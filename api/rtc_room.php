<?php
/**
 * rtc_room.php — групповые звонки на своём сервере, без Телемоста.
 *
 * ЗАЧЕМ. Личный звонок вдвоём давно работает через rtc_calls.php. Для группы
 * вместо этого открывался Яндекс.Телемост: человек уходил на чужой сайт,
 * создавал встречу руками и присылал ссылку в чат. Для сервиса, где в группе
 * идёт терапия, это и неудобно, и неуместно — разговор о личном уходил в
 * стороннюю переписку по чужой ссылке.
 *
 * ПОЧЕМУ ОТДЕЛЬНЫЙ ФАЙЛ, А НЕ ПРАВКА rtc_calls.php. Звонок вдвоём работает
 * хорошо, и трогать его ради группы — верный способ сломать то, что не просили.
 * Здесь своя таблица и свой цикл, общее только одно: список ICE-серверов
 * (rtc_calls.php?action=ice), чтобы настройка TURN оставалась в одном месте.
 *
 * КАК УСТРОЕНО: полносвязная сетка (mesh). Каждый участник соединяется с каждым
 * напрямую, сервер только передаёт сигналы. Для терапевтической группы это
 * верный выбор: нет сервера-микшера (его на shared-хостинге и негде взять),
 *媒иа не проходит через нас — значит и не хранится у нас, что для разговора о
 * личном важнее экономии трафика. Плата за это — трафик у участника растёт
 * линейно, поэтому размер комнаты ограничен (ROOM_MAX).
 *
 * КТО КОМУ ЗВОНИТ. Инициатор соединения всегда тот, кто вошёл ПОЗЖЕ: он видит
 * список уже присутствующих и шлёт offer каждому. Так не бывает «стеклянного»
 * состояния, когда обе стороны одновременно шлют offer друг другу.
 *
 * ДЕЙСТВИЯ
 *   POST ?action=join   {group_id}                  → войти/создать комнату
 *   POST ?action=leave  {room_id}                   → выйти
 *   POST ?action=ping   {room_id}                   → «я ещё здесь»
 *   GET  ?action=state&room_id=X&after=N            → участники + новые сигналы
 *   POST ?action=signal {room_id, to, kind, payload}→ offer/answer/ice
 *   GET  ?action=active&group_id=X                  → идёт ли звонок в группе
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/config.php';
if (!function_exists('getDB') && !function_exists('getDbConnection') && !function_exists('getPDO')) {
    require_once __DIR__ . '/db.php';
}
if (!function_exists('psy_schema_once')) require_once __DIR__ . '/schema_util.php';

$pdo = function_exists('getDB') ? getDB()
     : (function_exists('getDbConnection') ? getDbConnection()
     : (function_exists('getPDO') ? getPDO() : null));
if (!$pdo) { http_response_code(500); echo json_encode(['error' => 'Нет подключения к БД']); exit; }

if (session_status() === PHP_SESSION_NONE) session_start();
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }

function rrOut($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

/** Сколько человек пускаем в комнату. Смотри комментарий про mesh наверху файла. */
const ROOM_MAX = 8;
/** Не подавал признаков жизни столько секунд — считаем, что вышел. */
const PEER_STALE = 25;
/** Сигналы старше этого удаляем: они уже никому не нужны. */
const SIGNAL_TTL = 300;

function rrEnsure(PDO $pdo) {
    psy_schema_once('rtc_room_schema_v1', 3600, function () use ($pdo) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS rtc_rooms (
            id INT AUTO_INCREMENT PRIMARY KEY,
            group_id INT NOT NULL,
            started_by VARCHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            ended_at DATETIME NULL,
            INDEX idx_group (group_id, ended_at)
        ) DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS rtc_room_peers (
            room_id INT NOT NULL,
            user_id VARCHAR(64) NOT NULL,
            joined_at DATETIME NOT NULL,
            last_seen DATETIME NOT NULL,
            PRIMARY KEY (room_id, user_id),
            INDEX idx_seen (room_id, last_seen)
        ) DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS rtc_room_signals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            room_id INT NOT NULL,
            from_id VARCHAR(64) NOT NULL,
            to_id VARCHAR(64) NOT NULL,
            kind VARCHAR(12) NOT NULL,
            payload MEDIUMTEXT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_inbox (room_id, to_id, id),
            INDEX idx_created (created_at)
        ) DEFAULT CHARSET=utf8mb4");
    });
}
try { rrEnsure($pdo); } catch (Exception $e) { rrOut(['error' => 'Не удалось подготовить таблицы'], 500); }

/** Участник группы? Групповой звонок — только для своих. */
function rrInGroup(PDO $pdo, $groupId, $userId) {
    try {
        $st = $pdo->prepare("SELECT 1 FROM chat_group_members WHERE group_id = ? AND user_id = ? LIMIT 1");
        $st->execute([$groupId, $userId]);
        return (bool)$st->fetchColumn();
    } catch (Exception $e) { return false; }
}

/** Живые участники комнаты (кто подавал признаки жизни недавно). */
function rrLivePeers(PDO $pdo, $roomId) {
    try {
        $st = $pdo->prepare("SELECT p.user_id, p.joined_at, u.first_name, u.last_name, u.avatar
                               FROM rtc_room_peers p
                               LEFT JOIN users u ON u.id = p.user_id
                              WHERE p.room_id = ? AND p.last_seen > (NOW() - INTERVAL " . PEER_STALE . " SECOND)
                              ORDER BY p.joined_at ASC");
        $st->execute([$roomId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            $out[] = ['user_id' => (string)$r['user_id'],
                      'name' => $name !== '' ? $name : 'Участник',
                      'avatar' => $r['avatar'] ?: null,
                      'joined_at' => $r['joined_at']];
        }
        return $out;
    } catch (Exception $e) { return []; }
}

/**
 * Уборка. Служебная работа — со своим try/catch и молча: если она не удалась,
 * человек всё равно должен попасть в звонок.
 */
function rrSweep(PDO $pdo, $roomId = 0) {
    try { $pdo->exec("DELETE FROM rtc_room_signals WHERE created_at < (NOW() - INTERVAL " . SIGNAL_TTL . " SECOND)"); } catch (Exception $e) {}
    try {
        $pdo->exec("DELETE FROM rtc_room_peers WHERE last_seen < (NOW() - INTERVAL 120 SECOND)");
    } catch (Exception $e) {}
    // Комната без живых участников — закончилась
    try {
        $pdo->exec("UPDATE rtc_rooms r SET r.ended_at = NOW()
                     WHERE r.ended_at IS NULL
                       AND r.created_at < (NOW() - INTERVAL 30 SECOND)
                       AND NOT EXISTS (SELECT 1 FROM rtc_room_peers p
                                        WHERE p.room_id = r.id
                                          AND p.last_seen > (NOW() - INTERVAL " . PEER_STALE . " SECOND))");
    } catch (Exception $e) {}
}

$action = $_GET['action'] ?? '';
$body = json_decode(file_get_contents('php://input'), true) ?: [];

if ($action === 'active') {
    $gid = (int)($_GET['group_id'] ?? 0);
    if (!$gid || !rrInGroup($pdo, $gid, $userId)) rrOut(['ok' => true, 'room' => null]);
    rrSweep($pdo);
    try {
        $st = $pdo->prepare("SELECT id FROM rtc_rooms WHERE group_id = ? AND ended_at IS NULL ORDER BY id DESC LIMIT 1");
        $st->execute([$gid]);
        $rid = (int)($st->fetchColumn() ?: 0);
        if (!$rid) rrOut(['ok' => true, 'room' => null]);
        $peers = rrLivePeers($pdo, $rid);
        if (!$peers) rrOut(['ok' => true, 'room' => null]);
        rrOut(['ok' => true, 'room' => ['id' => $rid, 'peers' => $peers, 'count' => count($peers)]]);
    } catch (Exception $e) { rrOut(['ok' => true, 'room' => null]); }
}

if ($action === 'join' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $gid = (int)($body['group_id'] ?? 0);
    if (!$gid) rrOut(['error' => 'Не передан номер группы'], 400);
    if (!rrInGroup($pdo, $gid, $userId)) rrOut(['error' => 'Вы не участник этой группы'], 403);
    rrSweep($pdo);
    try {
        $st = $pdo->prepare("SELECT id FROM rtc_rooms WHERE group_id = ? AND ended_at IS NULL ORDER BY id DESC LIMIT 1");
        $st->execute([$gid]);
        $rid = (int)($st->fetchColumn() ?: 0);
        if (!$rid) {
            $pdo->prepare("INSERT INTO rtc_rooms (group_id, started_by, created_at) VALUES (?, ?, NOW())")
                ->execute([$gid, $userId]);
            $rid = (int)$pdo->lastInsertId();
        }
        // Кто УЖЕ в комнате — им новичок и разошлёт offer'ы (см. шапку файла)
        $existing = array_values(array_filter(rrLivePeers($pdo, $rid),
                        fn($p) => (string)$p['user_id'] !== (string)$userId));
        if (count($existing) >= ROOM_MAX - 1) rrOut(['error' => 'В звонке уже максимум участников (' . ROOM_MAX . ')'], 409);

        $pdo->prepare("INSERT INTO rtc_room_peers (room_id, user_id, joined_at, last_seen)
                       VALUES (?, ?, NOW(), NOW())
                       ON DUPLICATE KEY UPDATE last_seen = NOW()")->execute([$rid, $userId]);
        // Номер последнего сигнала — чтобы новичок не вычитывал чужую старую переписку
        $last = 0;
        try { $last = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM rtc_room_signals")->fetchColumn(); } catch (Exception $e) {}
        rrOut(['ok' => true, 'room_id' => $rid, 'peers' => $existing, 'after' => $last, 'max' => ROOM_MAX]);
    } catch (Exception $e) { rrOut(['error' => 'Не удалось войти в звонок: ' . $e->getMessage()], 500); }
}

if ($action === 'ping' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rid = (int)($body['room_id'] ?? 0);
    if ($rid) {
        try { $pdo->prepare("UPDATE rtc_room_peers SET last_seen = NOW() WHERE room_id = ? AND user_id = ?")
                  ->execute([$rid, $userId]); } catch (Exception $e) {}
    }
    rrOut(['ok' => true]);
}

if ($action === 'leave' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rid = (int)($body['room_id'] ?? 0);
    if ($rid) {
        try { $pdo->prepare("DELETE FROM rtc_room_peers WHERE room_id = ? AND user_id = ?")->execute([$rid, $userId]); } catch (Exception $e) {}
        // Скажем остальным, чтобы убрали окошко сразу, не дожидаясь таймаута
        try {
            $st = $pdo->prepare("SELECT user_id FROM rtc_room_peers WHERE room_id = ? AND user_id <> ?");
            $st->execute([$rid, $userId]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $peer) {
                $pdo->prepare("INSERT INTO rtc_room_signals (room_id, from_id, to_id, kind, payload, created_at)
                               VALUES (?, ?, ?, 'bye', NULL, NOW())")->execute([$rid, $userId, $peer]);
            }
        } catch (Exception $e) {}
    }
    rrSweep($pdo, $rid);
    rrOut(['ok' => true]);
}

if ($action === 'signal' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rid = (int)($body['room_id'] ?? 0);
    $to  = trim((string)($body['to'] ?? ''));
    $kind = (string)($body['kind'] ?? '');
    if (!$rid || $to === '') rrOut(['error' => 'Нужны room_id и получатель'], 400);
    if (!in_array($kind, ['offer', 'answer', 'ice', 'bye'], true)) rrOut(['error' => 'Неизвестный сигнал'], 400);
    $payload = $body['payload'] ?? null;
    $json = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json !== null && strlen($json) > 200000) rrOut(['error' => 'Сигнал слишком большой'], 413);
    try {
        // Отправлять можно только тому, кто в этой же комнате
        $st = $pdo->prepare("SELECT 1 FROM rtc_room_peers WHERE room_id = ? AND user_id = ? LIMIT 1");
        $st->execute([$rid, $userId]);
        if (!$st->fetchColumn()) rrOut(['error' => 'Вы не в этом звонке'], 403);
        $pdo->prepare("INSERT INTO rtc_room_signals (room_id, from_id, to_id, kind, payload, created_at)
                       VALUES (?, ?, ?, ?, ?, NOW())")->execute([$rid, $userId, $to, $kind, $json]);
        rrOut(['ok' => true]);
    } catch (Exception $e) { rrOut(['error' => 'Сигнал не ушёл'], 500); }
}

if ($action === 'state') {
    $rid = (int)($_GET['room_id'] ?? 0);
    $after = (int)($_GET['after'] ?? 0);
    if (!$rid) rrOut(['error' => 'Не передан room_id'], 400);
    // Отметку «я здесь» ставим здесь же: опрос идёт постоянно, отдельный запрос
    // ради этого — лишний. Служебная запись, поэтому молча и в своём try.
    try { $pdo->prepare("UPDATE rtc_room_peers SET last_seen = NOW() WHERE room_id = ? AND user_id = ?")
              ->execute([$rid, $userId]); } catch (Exception $e) {}
    $peers = rrLivePeers($pdo, $rid);
    $signals = [];
    $maxId = $after;
    try {
        $st = $pdo->prepare("SELECT id, from_id, kind, payload FROM rtc_room_signals
                              WHERE room_id = ? AND to_id = ? AND id > ? ORDER BY id ASC LIMIT 100");
        $st->execute([$rid, $userId, $after]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $signals[] = ['id' => (int)$r['id'], 'from' => (string)$r['from_id'],
                          'kind' => $r['kind'],
                          'payload' => $r['payload'] !== null ? json_decode($r['payload'], true) : null];
            $maxId = max($maxId, (int)$r['id']);
        }
    } catch (Exception $e) {}
    rrOut(['ok' => true, 'peers' => $peers, 'signals' => $signals, 'after' => $maxId]);
}

rrOut(['error' => 'Неизвестное действие'], 400);
