<?php
/**
 * admin_notes.php — личные записки/TODO администратора и психолога.
 *
 * GET  ?action=list                         — все записки текущего пользователя
 * POST ?action=add    {title, body, due_date?}         — создать
 * POST ?action=update {id, title?, body?, is_done?, due_date?} — обновить (due_date:'' очищает срок)
 * POST ?action=delete {id}                  — удалить
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
if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }

try {
    $st = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $st->execute([$userId]);
    $myRole = (string)$st->fetchColumn();
    if (!in_array($myRole, ['admin', 'psychologist'], true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Доступно только администратору и психологу']);
        exit;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка проверки прав']);
    exit;
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(64) NOT NULL,
        title VARCHAR(500) DEFAULT '',
        body TEXT,
        is_done TINYINT(1) DEFAULT 0,
        due_date DATE NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_user (user_id)
    ) DEFAULT CHARSET=utf8mb4");
    try { $pdo->exec("ALTER TABLE admin_notes ADD COLUMN due_date DATE NULL"); } catch (Exception $e) {}
} catch (Exception $e) {}

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?: [];

if ($action === 'list') {
    try {
        $st = $pdo->prepare("SELECT *, (due_date IS NOT NULL AND due_date < CURDATE() AND is_done = 0) AS is_overdue FROM admin_notes WHERE user_id = ? ORDER BY is_done ASC, (due_date IS NULL), due_date ASC, created_at DESC LIMIT 500");
        $st->execute([$userId]);
        echo json_encode(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { echo json_encode(['ok' => true, 'data' => []]); }
    exit;
}

if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim((string)($input['title'] ?? ''));
    $body  = trim((string)($input['body'] ?? ''));
    $dueDate = trim((string)($input['due_date'] ?? ''));
    if ($dueDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) $dueDate = '';
    if ($title === '' && $body === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Введите заголовок или текст']);
        exit;
    }
    try {
        $st = $pdo->prepare("INSERT INTO admin_notes (user_id, title, body, is_done, due_date, created_at, updated_at) VALUES (?, ?, ?, 0, ?, NOW(), NOW())");
        $st->execute([$userId, mb_substr($title, 0, 500), mb_substr($body, 0, 10000), $dueDate !== '' ? $dueDate : null]);
        echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Не удалось сохранить']);
    }
    exit;
}

if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($input['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id обязателен']); exit; }
    $sets = []; $params = [];
    if (array_key_exists('title', $input))   { $sets[] = 'title = ?';   $params[] = mb_substr(trim((string)$input['title']), 0, 500); }
    if (array_key_exists('body', $input))    { $sets[] = 'body = ?';    $params[] = mb_substr(trim((string)$input['body']), 0, 10000); }
    if (array_key_exists('is_done', $input)) { $sets[] = 'is_done = ?'; $params[] = $input['is_done'] ? 1 : 0; }
    if (array_key_exists('due_date', $input)) {
        $dd = trim((string)$input['due_date']);
        $sets[] = 'due_date = ?';
        $params[] = ($dd !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dd)) ? $dd : null;
    }
    if (empty($sets)) { echo json_encode(['ok' => true]); exit; }
    $sets[] = 'updated_at = NOW()';
    $params[] = $id;
    $params[] = $userId;
    try {
        $st = $pdo->prepare("UPDATE admin_notes SET " . implode(', ', $sets) . " WHERE id = ? AND user_id = ?");
        $st->execute($params);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Не удалось обновить']);
    }
    exit;
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($input['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id обязателен']); exit; }
    try {
        $st = $pdo->prepare("DELETE FROM admin_notes WHERE id = ? AND user_id = ?");
        $st->execute([$id, $userId]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Не удалось удалить']);
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Неизвестное действие']);
