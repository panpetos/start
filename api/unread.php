<?php
/**
 * unread.php — счётчики непрочитанного, посчитанные ПО ТОМУ ЖЕ признаку,
 * по которому переписка отмечается прочитанной.
 *
 * ЗАЧЕМ. Счётчик у диалога не гас после прочтения. Отметку ставит
 * messages_page.php (messages.is_read = 1), а число для списка приходило из
 * серверного messages.php, который считает его по-своему. Два разных источника
 * рано или поздно расходятся — и разошлись: сообщение прочитано, а «1» висит.
 *
 * Здесь один источник на оба действия: и отметка, и счёт идут по messages.is_read.
 * Разойтись им теперь не с чем.
 *
 *   GET ?action=counts → { ok, data: { "<id собеседника>": 3, ... } }
 *   GET ?action=total  → { ok, total: 5 }
 *
 * Если колонки is_read в таблице нет, честно отвечаем supported:false — чат
 * тогда оставляет числа, пришедшие из messages.php, и ничего не ломается.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/config.php';
if (!function_exists('getDB') && !function_exists('getDbConnection') && !function_exists('getPDO')) {
    require_once __DIR__ . '/db.php';
}
$pdo = function_exists('getDB') ? getDB()
     : (function_exists('getDbConnection') ? getDbConnection()
     : (function_exists('getPDO') ? getPDO() : null));
if (!$pdo) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Нет подключения к БД']); exit; }

if (session_status() === PHP_SESSION_NONE) session_start();
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Требуется авторизация']); exit; }

function unOut($d) { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

/** Есть ли колонка. Список читаем целиком: подстановка в SHOW на боевой базе не проходит. */
function unHasIsRead(PDO $pdo) {
    static $has = null;
    if ($has !== null) return $has;
    $has = false;
    try {
        foreach ($pdo->query("SHOW COLUMNS FROM messages")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if ((string)($c['Field'] ?? '') === 'is_read') { $has = true; break; }
        }
    } catch (Exception $e) { $has = false; }
    return $has;
}

/**
 * Скрытые и архивные диалоги этого человека: ключ чата → [время, вид].
 *
 * ПОЧЕМУ ЭТО ЗДЕСЬ. Счётчик висел вечно (жалоба админа: «приходят старые сообщения,
 * я даже диалог удалил, а два висят»). Круг замыкался так: удалённый у себя диалог
 * пропадает из списка (chat_hidden) → открыть его больше нельзя → messages_page.php,
 * который единственный ставит is_read = 1, до него не доходит НИКОГДА → здесь он
 * считается непрочитанным до скончания века.
 *
 * JOIN сознательно не делаем: chat_hidden и messages живут в разных collation
 * (см. schema_util.php), и JOIN между ними падает с ошибкой 1267 — а падение тут
 * молча возвращает supported:false и гасит счётчики совсем.
 */
function unHiddenMap(PDO $pdo, $userId) {
    $map = [];
    try {
        $st = $pdo->prepare("SELECT chat_key, hidden_at, COALESCE(kind, 'deleted') AS kind
                               FROM chat_hidden WHERE user_id = ?");
        $st->execute([$userId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[(string)$r['chat_key']] = ['at' => (string)$r['hidden_at'], 'kind' => (string)$r['kind']];
        }
    } catch (Exception $e) { /* таблицы нет — считаем как раньше, по всем */ }
    return $map;
}

/**
 * Непрочитанное по собеседникам, с учётом удалённых и архивных диалогов.
 * Возвращает [ключ => число]. Один и тот же расчёт и для counts, и для total —
 * иначе шапка и список опять разойдутся, ради чего этот файл и заводился.
 */
function unCountsByPeer(PDO $pdo, $userId) {
    $st = $pdo->prepare("SELECT sender_id, COUNT(*) AS n FROM messages
                          WHERE receiver_id = ? AND (is_read = 0 OR is_read IS NULL)
                          GROUP BY sender_id");
    $st->execute([$userId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $hidden = unHiddenMap($pdo, $userId);
    // Считаем только то, что пришло ПОСЛЕ удаления: ровно такой диалог и возвращается
    // в список сам (см. isChatHidden в chat.html). Всё, что было до, человек выбросил.
    $fresh = $pdo->prepare("SELECT COUNT(*) FROM messages
                             WHERE receiver_id = ? AND sender_id = ?
                               AND (is_read = 0 OR is_read IS NULL) AND created_at > ?");
    $out = [];
    foreach ($rows as $r) {
        $id = (string)($r['sender_id'] ?? '');
        if ($id === '') continue;
        $n = (int)$r['n'];
        if (isset($hidden[$id])) {
            // Архив, в отличие от удаления, не возвращается сам даже на новое сообщение —
            // значит и счётчику там взяться неоткуда.
            if ($hidden[$id]['kind'] === 'archived') continue;
            try {
                $fresh->execute([$userId, $id, $hidden[$id]['at']]);
                $n = (int)$fresh->fetchColumn();
            } catch (Exception $e) { $n = 0; }
        }
        if ($n <= 0) continue;
        $out[$id] = $n;
    }
    return $out;
}

$action = $_GET['action'] ?? 'counts';

if (!unHasIsRead($pdo)) unOut(['ok' => true, 'supported' => false, 'data' => new stdClass(), 'total' => 0]);

try {
    $counts = unCountsByPeer($pdo, $userId);
    $total = array_sum($counts);
    if ($action === 'total') unOut(['ok' => true, 'supported' => true, 'total' => $total]);
    unOut(['ok' => true, 'supported' => true, 'data' => $counts ?: new stdClass(), 'total' => $total]);
} catch (Exception $e) {
    if ($action === 'total') unOut(['ok' => true, 'supported' => false, 'total' => 0]);
    unOut(['ok' => true, 'supported' => false, 'data' => new stdClass(), 'total' => 0]);
}
