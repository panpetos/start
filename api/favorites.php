<?php
/**
 * favorites.php — личное «Избранное» пользователя (заметки себе, файлы,
 * пересланные сообщения). Видит только сам пользователь. Используется как
 * псевдо-диалог в chat.html (задача #28).
 *
 * GET  ?action=list                    — все записи текущего пользователя (старые -> новые)
 * POST ?action=add    {content?, attachment_url?, attachment_type?, attachment_name?, source_label?}
 * POST ?action=edit   {id, content}    — изменить текст своей записи (задача #45)
 * POST ?action=delete {id}             — удалить свою запись
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

function ensureFavoritesTable(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS favorite_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(64) NOT NULL,
        content TEXT NULL,
        attachment_url VARCHAR(500) NULL,
        attachment_type VARCHAR(20) NULL,
        attachment_name VARCHAR(255) NULL,
        source_label VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_user (user_id)
    ) DEFAULT CHARSET=utf8mb4");
}

$action = $_GET['action'] ?? '';
$body = json_decode(file_get_contents('php://input'), true) ?: [];

try { ensureFavoritesTable($pdo); } catch (Exception $e) {}

if ($action === 'list') {
    try {
        $st = $pdo->prepare("SELECT id, content, attachment_url, attachment_type, attachment_name, source_label, created_at
                              FROM favorite_messages WHERE user_id = ? ORDER BY created_at ASC, id ASC LIMIT 1000");
        $st->execute([$userId]);
        echo json_encode(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { echo json_encode(['ok' => true, 'data' => []]); }
    exit;
}

if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $content = isset($body['content']) ? trim((string)$body['content']) : '';
    $attachmentUrl = isset($body['attachment_url']) ? (string)$body['attachment_url'] : null;
    $attachmentType = isset($body['attachment_type']) ? (string)$body['attachment_type'] : null;
    $attachmentName = isset($body['attachment_name']) ? (string)$body['attachment_name'] : null;
    $sourceLabel = isset($body['source_label']) ? (string)$body['source_label'] : null;

    if ($content === '' && !$attachmentUrl) { http_response_code(400); echo json_encode(['error' => 'Пустая запись']); exit; }

    try {
        $st = $pdo->prepare("INSERT INTO favorite_messages (user_id, content, attachment_url, attachment_type, attachment_name, source_label, created_at)
                              VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $st->execute([$userId, $content !== '' ? $content : null, $attachmentUrl, $attachmentType, $attachmentName, $sourceLabel]);
        echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось сохранить']); }
    exit;
}

if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($body['id'] ?? 0);
    $content = trim((string)($body['content'] ?? ''));
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id обязателен']); exit; }
    if ($content === '') { http_response_code(400); echo json_encode(['error' => 'Текст не может быть пустым']); exit; }
    $content = mb_substr($content, 0, 5000);
    try {
        // Проверяем владение отдельным SELECT: UPDATE с WHERE id=?+user_id=? при неизменном
        // content вернул бы rowCount()=0 (0 affected rows), и это выглядело бы как «не найдена».
        $chk = $pdo->prepare("SELECT id FROM favorite_messages WHERE id = ? AND user_id = ? LIMIT 1");
        $chk->execute([$id, $userId]);
        if (!$chk->fetchColumn()) { http_response_code(404); echo json_encode(['error' => 'Запись не найдена']); exit; }
        $pdo->prepare("UPDATE favorite_messages SET content = ? WHERE id = ? AND user_id = ?")
            ->execute([$content, $id, $userId]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось сохранить']); }
    exit;
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id обязателен']); exit; }
    try {
        $st = $pdo->prepare("DELETE FROM favorite_messages WHERE id = ? AND user_id = ?");
        $st->execute([$id, $userId]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось удалить']); }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Неизвестное действие']);
