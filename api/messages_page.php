<?php
/**
 * messages_page.php — переписка порциями.
 *
 * ЗАЧЕМ. Раньше чат забирал ВСЕ сообщения переписки одним запросом и не умел
 * подгружать старые. Пока сообщений сотни, это незаметно; на нескольких тысячах в
 * одной переписке каждое открытие чата тянуло бы мегабайт и заметно тормозило —
 * причём на каждом опросе, а не только при открытии.
 *
 * Отправка сообщений осталась там же, где была (серверный messages.php, его в
 * репозитории нет). Здесь только ЧТЕНИЕ и только своей переписки — поэтому файл и
 * появился отдельно, ничего чужого он не трогает.
 *
 * GET ?with=<id собеседника>&limit=50[&before=<курсор>]
 *   → { ok, data: [сообщения от старых к новым], has_more, cursor }
 *
 * cursor — метка самого старого из отданных сообщений. Передайте её в before,
 * чтобы получить предыдущую порцию. Метка составная («время|id»), потому что
 * идентификатор сообщения в разных установках то числовой, то строковый, и
 * опираться только на него нельзя.
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

function mpOut($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

/** Есть ли колонка — набор полей в таблице сообщений у разных установок разный. */
function mpHasColumn(PDO $pdo, $col) {
    static $memo = [];
    if (isset($memo[$col])) return $memo[$col];
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM messages LIKE ?");
        $st->execute([$col]);
        return $memo[$col] = (bool)$st->fetch();
    } catch (Exception $e) { return $memo[$col] = false; }
}

$peer = trim((string)($_GET['with'] ?? ''));
if ($peer === '') mpOut(['ok' => false, 'error' => 'Не указан собеседник'], 400);
$limit = max(10, min(200, (int)($_GET['limit'] ?? 50)));
$before = trim((string)($_GET['before'] ?? ''));

// Отдаём только то, что человек и так видит: свою переписку с этим собеседником
$cols = ['id', 'sender_id', 'receiver_id', 'content', 'created_at'];
foreach (['attachment_url', 'attachment_type', 'attachment_name', 'edited_at'] as $c) {
    if (mpHasColumn($pdo, $c)) $cols[] = $c;
}
$sel = implode(', ', array_map(fn($c) => 'm.' . $c, $cols));

$where = "((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))";
$args = [$userId, $peer, $peer, $userId];

// Курсор: берём то, что старее указанной точки
if ($before !== '') {
    $parts = explode('|', $before, 2);
    $bTime = $parts[0] ?? '';
    $bId = $parts[1] ?? '';
    if ($bTime !== '') {
        $where .= " AND (m.created_at < ? OR (m.created_at = ? AND m.id < ?))";
        array_push($args, $bTime, $bTime, $bId);
    }
}

try {
    // Берём на одну больше запрошенного: так сразу видно, есть ли что-то ещё дальше,
    // и не нужен отдельный COUNT по всей переписке.
    $sql = "SELECT $sel, u.first_name AS sender_first_name, u.last_name AS sender_last_name
              FROM messages m
              LEFT JOIN users u ON u.id = m.sender_id
             WHERE $where
             ORDER BY m.created_at DESC, m.id DESC
             LIMIT " . ($limit + 1);
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $hasMore = count($rows) > $limit;
    if ($hasMore) array_pop($rows);

    // Курсор — по самому старому из отданных
    $cursor = null;
    if ($rows) {
        $oldest = $rows[count($rows) - 1];
        $cursor = (string)$oldest['created_at'] . '|' . (string)$oldest['id'];
    }

    // Наружу отдаём в привычном порядке: от старых к новым, как рисует переписку чат
    $rows = array_reverse($rows);
    mpOut(['ok' => true, 'data' => $rows, 'has_more' => $hasMore, 'cursor' => $cursor]);
} catch (Exception $e) {
    mpOut(['ok' => false, 'error' => 'Не удалось прочитать переписку'], 500);
}
