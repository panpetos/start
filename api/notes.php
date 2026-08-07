<?php
/**
 * notes.php — приватные заметки специалиста по клиенту (видит только автор).
 *
 * GET  ?action=list&about=<user_id>   — заметки текущего пользователя об этом клиенте
 * GET  ?action=favorites              — избранные заметки текущего пользователя по всем клиентам
 * POST ?action=add   {about, text}    — добавить заметку
 * POST ?action=update {id, text?, is_favorite?}  — изменить текст и/или избранное своей заметки
 * POST ?action=delete {id}            — удалить свою заметку
 *
 * Заметки приватны: author_id = текущий пользователь из сессии. Клиент их не видит.
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

function ensureTable(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS psychologist_notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        author_id VARCHAR(64) NOT NULL,
        about_id VARCHAR(64) NOT NULL,
        text TEXT NOT NULL,
        is_favorite TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        INDEX idx_author_about (author_id, about_id)
    ) DEFAULT CHARSET=utf8mb4");
    try { $pdo->exec("ALTER TABLE psychologist_notes ADD COLUMN is_favorite TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}
}

$action = $_GET['action'] ?? '';
$body = json_decode(file_get_contents('php://input'), true) ?: [];

try { ensureTable($pdo); } catch (Exception $e) {}

if ($action === 'list') {
    $about = (string)($_GET['about'] ?? '');
    if ($about === '') { http_response_code(400); echo json_encode(['error' => 'about обязателен']); exit; }
    try {
        $st = $pdo->prepare("SELECT id, text, is_favorite, created_at FROM psychologist_notes WHERE author_id = ? AND about_id = ? ORDER BY created_at DESC LIMIT 500");
        $st->execute([$userId, $about]);
        echo json_encode(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { echo json_encode(['ok' => true, 'data' => []]); }
    exit;
}

if ($action === 'favorites') {
    try {
        $st = $pdo->prepare("SELECT n.id, n.about_id, n.text, n.is_favorite, n.created_at,
                u.first_name, u.last_name
            FROM psychologist_notes n
            LEFT JOIN users u ON u.id = n.about_id
            WHERE n.author_id = ? AND n.is_favorite = 1
            ORDER BY n.created_at DESC LIMIT 500");
        $st->execute([$userId]);
        echo json_encode(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { echo json_encode(['ok' => true, 'data' => []]); }
    exit;
}

if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $about = (string)($body['about'] ?? '');
    $text = trim((string)($body['text'] ?? ''));
    if ($about === '' || $text === '') { http_response_code(400); echo json_encode(['error' => 'about и text обязательны']); exit; }
    try {
        $st = $pdo->prepare("INSERT INTO psychologist_notes (author_id, about_id, text, created_at) VALUES (?, ?, ?, NOW())");
        $st->execute([$userId, $about, mb_substr($text, 0, 5000)]);
        echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось сохранить заметку']); }
    exit;
}

if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id обязателен']); exit; }
    $fields = []; $params = [];
    if (array_key_exists('text', $body)) {
        $text = trim((string)$body['text']);
        if ($text === '') { http_response_code(400); echo json_encode(['error' => 'Текст не может быть пустым']); exit; }
        $fields[] = 'text=?'; $params[] = mb_substr($text, 0, 5000);
    }
    if (array_key_exists('is_favorite', $body)) { $fields[] = 'is_favorite=?'; $params[] = !empty($body['is_favorite']) ? 1 : 0; }
    if (!$fields) { echo json_encode(['ok' => true]); exit; }
    $params[] = $id; $params[] = $userId;
    try {
        $st = $pdo->prepare("UPDATE psychologist_notes SET " . implode(', ', $fields) . " WHERE id = ? AND author_id = ?");
        $st->execute($params);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось обновить заметку']); }
    exit;
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id обязателен']); exit; }
    try {
        $st = $pdo->prepare("DELETE FROM psychologist_notes WHERE id = ? AND author_id = ?");
        $st->execute([$id, $userId]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось удалить']); }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Неизвестное действие']);
