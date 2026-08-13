<?php
/**
 * presence.php — «онлайн», «печатает…» и галочки доставки/прочтения.
 *
 * POST ?action=ping     {typing_to?}            — я здесь; и, возможно, печатаю кому-то
 * GET  ?action=state&peers=a,b,c                — состояние собеседников + их отметки прочтения
 * POST ?action=read     {peer_id}               — я дочитал переписку до текущего момента
 *
 * Почему отдельным файлом: личные сообщения пишет серверный messages.php, которого нет
 * в репозитории и куда нельзя добавить ни колонок, ни хуков. Поэтому статусы держим
 * рядом, в своих таблицах, и сопоставляем по времени:
 *   доставлено — собеседник был в сети позже, чем отправлено сообщение;
 *   прочитано  — собеседник открывал эту переписку позже, чем отправлено сообщение.
 * Это честные признаки, не требующие правок в чужом коде.
 */

header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/schema_util.php';
if (!function_exists('getDB') && !function_exists('getDbConnection') && !function_exists('getPDO')) {
    require_once __DIR__ . '/db.php';
}
$pdo = function_exists('getDB') ? getDB()
     : (function_exists('getDbConnection') ? getDbConnection()
     : (function_exists('getPDO') ? getPDO() : null));
if (!$pdo) { http_response_code(500); echo json_encode(['error' => 'Нет подключения к БД']); exit; }

if (session_status() === PHP_SESSION_NONE) session_start();
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }

/** Сколько секунд без сигнала считаем человека всё ещё онлайн. */
const ONLINE_WINDOW_SEC = 70;
/** Сколько живёт отметка «печатает» — иначе она застревает, если вкладку закрыли. */
const TYPING_TTL_SEC = 7;

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_presence (
        user_id VARCHAR(64) PRIMARY KEY,
        last_seen DATETIME NOT NULL,
        typing_to VARCHAR(64) NULL,
        typing_at DATETIME NULL,
        hide_last_seen TINYINT(1) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Колонку могли не застать те, у кого таблица уже создана прошлой версией
    try { $pdo->exec("ALTER TABLE user_presence ADD COLUMN hide_last_seen TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_reads (
        user_id VARCHAR(64) NOT NULL,
        peer_id VARCHAR(64) NOT NULL,
        last_read_at DATETIME NOT NULL,
        PRIMARY KEY (user_id, peer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    psy_align_collation($pdo, ['user_presence', 'chat_reads']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Не удалось подготовить таблицы: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Грубая оценка «когда заходил» для тех, кто скрыл точное время. */
function presenceBucket($seen) {
    if (!$seen) return 'давно';
    $ago = time() - $seen;
    if ($ago < 3600) return 'недавно';
    if ($ago < 86400) return 'сегодня';
    if ($ago < 7 * 86400) return 'на этой неделе';
    return 'давно';
}

$action = $_GET['action'] ?? '';
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$now = date('Y-m-d H:i:s');

// ── Настройка приватности: скрыть от других, когда я в сети ──
if ($action === 'privacy') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $hide = !empty($body['hide_last_seen']) ? 1 : 0;
        try {
            $st = $pdo->prepare("INSERT INTO user_presence (user_id, last_seen, hide_last_seen)
                                 VALUES (?, ?, ?)
                                 ON DUPLICATE KEY UPDATE hide_last_seen = VALUES(hide_last_seen)");
            $st->execute([$userId, $now, $hide]);
        } catch (Exception $e) {
            try {
                $pdo->prepare("UPDATE user_presence SET hide_last_seen = ? WHERE user_id = ?")->execute([$hide, $userId]);
            } catch (Exception $e2) { http_response_code(500); echo json_encode(['error'=>$e2->getMessage()]); exit; }
        }
        echo json_encode(['ok' => true, 'hide_last_seen' => (bool)$hide]);
        exit;
    }
    $hide = 0;
    try {
        $st = $pdo->prepare("SELECT hide_last_seen FROM user_presence WHERE user_id = ? LIMIT 1");
        $st->execute([$userId]);
        $hide = (int)$st->fetchColumn();
    } catch (Exception $e) {}
    echo json_encode(['ok' => true, 'hide_last_seen' => (bool)$hide]);
    exit;
}

if ($action === 'ping') {
    // typing_to — с кем сейчас идёт набор: id человека или 'g:<id>' для группы.
    $typingTo = trim((string)($body['typing_to'] ?? ''));
    if ($typingTo === '') { $typingTo = null; $typingAt = null; } else { $typingAt = $now; }
    try {
        $st = $pdo->prepare("INSERT INTO user_presence (user_id, last_seen, typing_to, typing_at)
                             VALUES (?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen),
                                                    typing_to = VALUES(typing_to),
                                                    typing_at = VALUES(typing_at)");
        $st->execute([$userId, $now, $typingTo, $typingAt]);   // hide_last_seen не трогаем — он живёт своей жизнью
    } catch (Exception $e) {
        // sqlite на стенде не знает ON DUPLICATE KEY — там подойдёт REPLACE
        try {
            $st = $pdo->prepare("REPLACE INTO user_presence (user_id, last_seen, typing_to, typing_at) VALUES (?,?,?,?)");
            $st->execute([$userId, $now, $typingTo, $typingAt]);
        } catch (Exception $e2) {
            http_response_code(500); echo json_encode(['error' => $e2->getMessage()]); exit;
        }
    }
    echo json_encode(['ok' => true, 'now' => $now]);
    exit;
}

if ($action === 'read') {
    $peer = trim((string)($body['peer_id'] ?? ''));
    if ($peer === '') { http_response_code(400); echo json_encode(['error' => 'Не указан собеседник']); exit; }
    try {
        $st = $pdo->prepare("INSERT INTO chat_reads (user_id, peer_id, last_read_at) VALUES (?,?,?)
                             ON DUPLICATE KEY UPDATE last_read_at = VALUES(last_read_at)");
        $st->execute([$userId, $peer, $now]);
    } catch (Exception $e) {
        try {
            $st = $pdo->prepare("REPLACE INTO chat_reads (user_id, peer_id, last_read_at) VALUES (?,?,?)");
            $st->execute([$userId, $peer, $now]);
        } catch (Exception $e2) { http_response_code(500); echo json_encode(['error' => $e2->getMessage()]); exit; }
    }
    echo json_encode(['ok' => true, 'at' => $now]);
    exit;
}

if ($action === 'state') {
    $raw = (string)($_GET['peers'] ?? '');
    $peers = array_values(array_filter(array_map('trim', explode(',', $raw)), function ($p) { return $p !== ''; }));
    $peers = array_slice(array_unique($peers), 0, 100);   // предохранитель на длинный список
    $out = [];
    if ($peers) {
        $in = implode(',', array_fill(0, count($peers), '?'));
        try {
            $st = $pdo->prepare("SELECT user_id, last_seen, typing_to, typing_at, hide_last_seen
                                 FROM user_presence WHERE user_id IN ($in)");
            $st->execute($peers);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $seen = strtotime((string)$r['last_seen']);
                $typing = $r['typing_to'] !== null && $r['typing_at']
                       && strtotime((string)$r['typing_at']) > time() - TYPING_TTL_SEC;
                $hidden = !empty($r['hide_last_seen']);
                $out[(string)$r['user_id']] = [
                    // Если человек скрыл своё присутствие, точного времени не отдаём вообще:
                    // прятать его на стороне браузера бессмысленно — данные всё равно уедут.
                    'online' => $hidden ? false : ($seen > time() - ONLINE_WINDOW_SEC),
                    'last_seen' => $hidden ? null : $r['last_seen'],
                    'hidden' => $hidden,
                    'seen_bucket' => $hidden ? presenceBucket($seen) : null,
                    // печатает именно мне (или в группу, которую я сейчас смотрю)
                    'typing_to' => $typing ? (string)$r['typing_to'] : null,
                ];
            }
        } catch (Exception $e) {}
        // До какого момента собеседник прочитал МОЮ переписку с ним — отсюда вторая галочка
        try {
            $st = $pdo->prepare("SELECT user_id, last_read_at FROM chat_reads WHERE peer_id = ? AND user_id IN ($in)");
            $st->execute(array_merge([$userId], $peers));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $u = (string)$r['user_id'];
                if (!isset($out[$u])) $out[$u] = ['online' => false, 'last_seen' => null, 'typing_to' => null];
                $out[$u]['read_until'] = $r['last_read_at'];
            }
        } catch (Exception $e) {}
    }
    echo json_encode([
        'ok' => true,
        'now' => $now,
        'data' => $out,
        'online_window' => ONLINE_WINDOW_SEC,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Неизвестное действие']);
