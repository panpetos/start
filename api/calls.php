<?php
/**
 * calls.php — учёт реального времени консультации («Начать сессию» / «Завершить
 * сессию» у психолога в chat.html).
 *
 * ВОССТАНОВЛЕННЫЙ ФАЙЛ. Исходный жил только на сервере (как messages.php и auth.php)
 * и был случайно перезаписан: новый модуль звонков сначала назывался так же. Звонки
 * с тех пор переехали в api/rtc_calls.php, а здесь восстановлен прежний договор,
 * снятый с тех мест, что этим файлом пользуются:
 *   • chat.html: POST ?action=start {participant_id, room_name} -> {call_id}
 *                POST ?action=end   {call_id}
 *   • admin_ext.php ?action=calls читает таблицу calls целиком (SELECT *) и сам
 *     подбирает колонки: собеседников из caller_id|initiator_id|user_id|from_user_id
 *     и participant_id|callee_id|to_user_id|receiver_id, длительность из
 *     duration|duration_sec|seconds|length.
 *   • неизвестное действие -> {"error":"Invalid action"}, без входа -> "Not authenticated"
 *
 * Колонки подбираются на месте через SHOW COLUMNS: если таблица calls уже есть со
 * своей схемой, пишем только в те поля, что в ней действительно существуют, и ничего
 * не ломаем. Если таблицы нет — создаём с именами, которые понимает админка.
 *
 * Если у хостинга сохранилась резервная копия исходного файла, лучше вернуть её:
 * она точнее этой реконструкции.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';
if (!function_exists('getDB') && !function_exists('getDbConnection') && !function_exists('getPDO')) {
    require_once __DIR__ . '/db.php';
}
$pdo = function_exists('getDB') ? getDB()
     : (function_exists('getDbConnection') ? getDbConnection()
     : (function_exists('getPDO') ? getPDO() : null));
if (!$pdo) { http_response_code(500); echo json_encode(['error' => 'DB unavailable']); exit; }

if (session_status() === PHP_SESSION_NONE) session_start();
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Not authenticated']); exit; }

try {
    // Таблицы может не быть только на чистой установке — тогда заводим её сами,
    // именами, которые понимает читатель в админке.
    $pdo->exec("CREATE TABLE IF NOT EXISTS calls (
        id INT AUTO_INCREMENT PRIMARY KEY,
        caller_id VARCHAR(64) NULL,
        participant_id VARCHAR(64) NULL,
        room_name VARCHAR(190) NULL,
        status VARCHAR(20) NULL,
        duration INT NULL,
        created_at DATETIME NULL,
        ended_at DATETIME NULL,
        INDEX idx_caller (caller_id),
        INDEX idx_created (created_at)
    ) DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { /* уже есть — со своей схемой, ниже подстроимся */ }

/** Какие колонки реально есть у таблицы: схему мог задать исходный файл. */
function callsCols(PDO $pdo) {
    static $c = null;
    if ($c !== null) return $c;
    $c = [];
    try {
        foreach ($pdo->query("SHOW COLUMNS FROM `calls`")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $c[$r['Field']] = $r;
        }
    } catch (Exception $e) { $c = []; }
    return $c;
}

/** Первая существующая колонка из списка — имена в разных схемах разные. */
function firstCol(array $cols, array $names) {
    foreach ($names as $n) if (isset($cols[$n])) return $n;
    return null;
}

$action = $_GET['action'] ?? '';
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

try {
    if ($action === 'start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $cols = callsCols($pdo);
        $data = [];
        $c = firstCol($cols, ['caller_id', 'initiator_id', 'user_id', 'from_user_id']);
        if ($c) $data[$c] = $userId;
        $p = firstCol($cols, ['participant_id', 'callee_id', 'to_user_id', 'receiver_id']);
        if ($p) $data[$p] = (string)($body['participant_id'] ?? '');
        if (isset($cols['room_name'])) $data['room_name'] = substr((string)($body['room_name'] ?? ''), 0, 190);
        if (isset($cols['status'])) $data['status'] = 'started';
        $t = firstCol($cols, ['created_at', 'started_at', 'start_time']);
        if ($t) $data[$t] = date('Y-m-d H:i:s');
        if (!$data) { echo json_encode(['error' => 'calls table has no known columns']); exit; }
        $names = array_keys($data);
        $pdo->prepare("INSERT INTO `calls` (`" . implode('`, `', $names) . "`) VALUES ("
            . implode(', ', array_fill(0, count($names), '?')) . ")")
            ->execute(array_values($data));
        echo json_encode(['ok' => true, 'call_id' => (int)$pdo->lastInsertId()]);
        exit;
    }

    if ($action === 'end' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $callId = $body['call_id'] ?? '';
        // клиент при недоступном сервере подставляет свой local-... — такое просто игнорируем
        if (!is_numeric($callId)) { echo json_encode(['ok' => true, 'skipped' => 'local id']); exit; }
        $cols = callsCols($pdo);
        $sets = []; $args = [];
        $endCol = firstCol($cols, ['ended_at', 'end_time', 'finished_at']);
        if ($endCol) { $sets[] = "`$endCol` = ?"; $args[] = date('Y-m-d H:i:s'); }
        if (isset($cols['status'])) { $sets[] = "`status` = ?"; $args[] = 'ended'; }
        // длительность считаем от времени начала, чтобы не верить клиенту на слово
        $durCol = firstCol($cols, ['duration', 'duration_sec', 'seconds', 'length']);
        $startCol = firstCol($cols, ['created_at', 'started_at', 'start_time']);
        if ($durCol && $startCol) {
            $sets[] = "`$durCol` = GREATEST(0, TIMESTAMPDIFF(SECOND, `$startCol`, NOW()))";
        }
        if (!$sets) { echo json_encode(['ok' => true]); exit; }
        $args[] = (int)$callId;
        // чужую сессию завершить нельзя
        $own = firstCol($cols, ['caller_id', 'initiator_id', 'user_id', 'from_user_id']);
        $sql = "UPDATE `calls` SET " . implode(', ', $sets) . " WHERE id = ?";
        if ($own) { $sql .= " AND `$own` = ?"; $args[] = $userId; }
        $pdo->prepare($sql)->execute($args);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'history') {
        $cols = callsCols($pdo);
        $own = firstCol($cols, ['caller_id', 'initiator_id', 'user_id', 'from_user_id']);
        $p   = firstCol($cols, ['participant_id', 'callee_id', 'to_user_id', 'receiver_id']);
        $ord = firstCol($cols, ['created_at', 'started_at', 'start_time', 'id']) ?: 'id';
        $where = []; $args = [];
        if ($own) { $where[] = "`$own` = ?"; $args[] = $userId; }
        if ($p)   { $where[] = "`$p` = ?";   $args[] = $userId; }
        $sql = "SELECT * FROM `calls`"
             . ($where ? " WHERE " . implode(' OR ', $where) : '')
             . " ORDER BY `$ord` DESC LIMIT 200";
        $st = $pdo->prepare($sql);
        $st->execute($args);
        echo json_encode(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC) ?: []]);
        exit;
    }

    echo json_encode(['error' => 'Invalid action']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
