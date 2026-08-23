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

$action = $_GET['action'] ?? 'counts';

if (!unHasIsRead($pdo)) unOut(['ok' => true, 'supported' => false, 'data' => new stdClass(), 'total' => 0]);

if ($action === 'total') {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM messages
                              WHERE receiver_id = ? AND (is_read = 0 OR is_read IS NULL)");
        $st->execute([$userId]);
        unOut(['ok' => true, 'supported' => true, 'total' => (int)$st->fetchColumn()]);
    } catch (Exception $e) { unOut(['ok' => true, 'supported' => false, 'total' => 0]); }
}

// По собеседникам. Группируем в базе, а не в PHP: строк может быть много, а нам
// нужны только числа.
try {
    $st = $pdo->prepare("SELECT sender_id, COUNT(*) AS n FROM messages
                          WHERE receiver_id = ? AND (is_read = 0 OR is_read IS NULL)
                          GROUP BY sender_id");
    $st->execute([$userId]);
    $out = [];
    $total = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $id = (string)($r['sender_id'] ?? '');
        if ($id === '') continue;
        $n = (int)$r['n'];
        $out[$id] = $n;
        $total += $n;
    }
    unOut(['ok' => true, 'supported' => true, 'data' => $out ?: new stdClass(), 'total' => $total]);
} catch (Exception $e) {
    unOut(['ok' => true, 'supported' => false, 'data' => new stdClass(), 'total' => 0]);
}
