<?php
/**
 * support.php — закреплённый чат поддержки на сайте + входящие для админа.
 *
 * Посетитель пишет из виджета: указывает имя, email и роль (клиент / психолог /
 * компания / хочу стать психологом / другое). Если он залогинен — контакты и роль
 * подставляются из аккаунта автоматически. Админ видит все обращения, роль и контакты
 * и отвечает прямо из админ-панели.
 *
 * Самостоятельный файл, та же БД, сам создаёт таблицы.
 *
 * Публичные (по токену):
 *   POST ?action=start  {name, email, role, message, token?}  → создаёт обращение, вернёт token
 *   POST ?action=send   {token, message}
 *   GET  ?action=poll&token=...                                 → переписка посетителя
 * Админские (роль admin):
 *   GET  ?action=threads
 *   GET  ?action=messages&thread_id=...
 *   POST ?action=reply  {thread_id, message}
 *   POST ?action=close  {thread_id}
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
if (!$pdo) { http_response_code(500); echo json_encode(['error' => 'Нет подключения к БД']); exit; }

if (session_status() === PHP_SESSION_NONE) session_start();
$userId = $_SESSION['user_id'] ?? null;

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_threads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        token VARCHAR(64) NOT NULL,
        user_id INT NULL,
        name VARCHAR(255) NULL,
        email VARCHAR(255) NULL,
        role VARCHAR(40) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        admin_read_at DATETIME NULL,
        INDEX idx_token (token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        thread_id INT NOT NULL,
        sender VARCHAR(10) NOT NULL,
        body TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_thread (thread_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

/** Профиль текущего пользователя (для авто-подстановки контактов). */
function currentUserRow(PDO $pdo, $userId) {
    if (!$userId) return null;
    try {
        $st = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $st->execute([$userId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) { return null; }
}
function isAdmin(PDO $pdo, $userId): bool {
    $u = currentUserRow($pdo, $userId);
    return $u && ($u['role'] ?? '') === 'admin';
}
function threadByToken(PDO $pdo, $token) {
    if (!$token) return null;
    try {
        $st = $pdo->prepare("SELECT * FROM support_threads WHERE token = ? LIMIT 1");
        $st->execute([$token]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) { return null; }
}

$action = $_GET['action'] ?? '';
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

// ── Публичные действия (виджет) ────────────────────────────────────────────────
if ($action === 'start') {
    $token = trim((string)($body['token'] ?? ''));
    $message = trim((string)($body['message'] ?? ''));
    if ($message === '') { http_response_code(400); echo json_encode(['error' => 'Введите сообщение']); exit; }

    $me = currentUserRow($pdo, $userId);
    if ($me) {
        $name = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: ($me['email'] ?? 'Пользователь');
        $email = $me['email'] ?? '';
        $role = $me['role'] ?? 'client';
    } else {
        $name = trim((string)($body['name'] ?? '')) ?: 'Гость';
        $email = trim((string)($body['email'] ?? ''));
        $role = trim((string)($body['role'] ?? 'Другое'));
    }

    $thread = threadByToken($pdo, $token);
    if (!$thread) {
        $token = bin2hex(random_bytes(16));
        try {
            $st = $pdo->prepare("INSERT INTO support_threads (token, user_id, name, email, role) VALUES (?, ?, ?, ?, ?)");
            $st->execute([$token, $userId ?: null, $name, $email, $role]);
            $threadId = (int)$pdo->lastInsertId();
        } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось создать обращение']); exit; }
    } else {
        $threadId = (int)$thread['id'];
    }

    try {
        $st = $pdo->prepare("INSERT INTO support_messages (thread_id, sender, body) VALUES (?, 'user', ?)");
        $st->execute([$threadId, $message]);
        $pdo->prepare("UPDATE support_threads SET last_at = NOW(), status = 'open' WHERE id = ?")->execute([$threadId]);
    } catch (Exception $e) {}

    echo json_encode(['ok' => true, 'token' => $token]);
    exit;
}

if ($action === 'send') {
    $token = trim((string)($body['token'] ?? ''));
    $message = trim((string)($body['message'] ?? ''));
    $thread = threadByToken($pdo, $token);
    if (!$thread) { http_response_code(404); echo json_encode(['error' => 'Сессия чата не найдена']); exit; }
    if ($message === '') { http_response_code(400); echo json_encode(['error' => 'Пустое сообщение']); exit; }
    try {
        $st = $pdo->prepare("INSERT INTO support_messages (thread_id, sender, body) VALUES (?, 'user', ?)");
        $st->execute([(int)$thread['id'], $message]);
        $pdo->prepare("UPDATE support_threads SET last_at = NOW(), status = 'open' WHERE id = ?")->execute([(int)$thread['id']]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось отправить']); exit; }
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'poll') {
    $token = trim((string)($_GET['token'] ?? ''));
    $thread = threadByToken($pdo, $token);
    if (!$thread) { echo json_encode(['ok' => true, 'data' => []]); exit; }
    try {
        $st = $pdo->prepare("SELECT id, sender, body, created_at FROM support_messages WHERE thread_id = ? ORDER BY id ASC");
        $st->execute([(int)$thread['id']]);
        echo json_encode(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { echo json_encode(['ok' => true, 'data' => []]); }
    exit;
}

// ── Админские действия ──────────────────────────────────────────────────────────
if (!isAdmin($pdo, $userId)) { http_response_code(403); echo json_encode(['error' => 'Доступ только для администратора']); exit; }

if ($action === 'threads') {
    try {
        $rows = $pdo->query("SELECT * FROM support_threads ORDER BY last_at DESC LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $rows = []; }
    foreach ($rows as &$t) {
        try {
            $st = $pdo->prepare("SELECT body, sender, created_at FROM support_messages WHERE thread_id = ? ORDER BY id DESC LIMIT 1");
            $st->execute([(int)$t['id']]);
            $last = $st->fetch(PDO::FETCH_ASSOC);
            $t['last_message'] = $last['body'] ?? '';
            $t['last_sender'] = $last['sender'] ?? '';
        } catch (Exception $e) { $t['last_message'] = ''; }
        // непрочитанные: сообщения посетителя после admin_read_at
        try {
            $q = $pdo->prepare("SELECT COUNT(*) FROM support_messages WHERE thread_id = ? AND sender = 'user' AND (? IS NULL OR created_at > ?)");
            $q->execute([(int)$t['id'], $t['admin_read_at'], $t['admin_read_at']]);
            $t['unread'] = (int)$q->fetchColumn();
        } catch (Exception $e) { $t['unread'] = 0; }
        $t['registered'] = !empty($t['user_id']);
    }
    echo json_encode(['ok' => true, 'data' => $rows]);
    exit;
}

if ($action === 'messages') {
    $tid = $_GET['thread_id'] ?? null;
    if (!$tid) { http_response_code(400); echo json_encode(['error' => 'thread_id обязателен']); exit; }
    try {
        $info = $pdo->prepare("SELECT * FROM support_threads WHERE id = ? LIMIT 1");
        $info->execute([$tid]);
        $thread = $info->fetch(PDO::FETCH_ASSOC);
        $st = $pdo->prepare("SELECT id, sender, body, created_at FROM support_messages WHERE thread_id = ? ORDER BY id ASC");
        $st->execute([$tid]);
        $msgs = $st->fetchAll(PDO::FETCH_ASSOC);
        $pdo->prepare("UPDATE support_threads SET admin_read_at = NOW() WHERE id = ?")->execute([$tid]);
    } catch (Exception $e) { $thread = null; $msgs = []; }
    echo json_encode(['ok' => true, 'thread' => $thread, 'data' => $msgs]);
    exit;
}

if ($action === 'reply') {
    $tid = $body['thread_id'] ?? null;
    $message = trim((string)($body['message'] ?? ''));
    if (!$tid || $message === '') { http_response_code(400); echo json_encode(['error' => 'Нужны тред и сообщение']); exit; }
    try {
        $pdo->prepare("INSERT INTO support_messages (thread_id, sender, body) VALUES (?, 'admin', ?)")->execute([$tid, $message]);
        $pdo->prepare("UPDATE support_threads SET last_at = NOW(), status = 'open', admin_read_at = NOW() WHERE id = ?")->execute([$tid]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось ответить']); exit; }
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'close') {
    $tid = $body['thread_id'] ?? null;
    if (!$tid) { http_response_code(400); echo json_encode(['error' => 'thread_id обязателен']); exit; }
    try { $pdo->prepare("UPDATE support_threads SET status = 'closed' WHERE id = ?")->execute([$tid]); } catch (Exception $e) {}
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Неизвестное действие']);
