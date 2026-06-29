<?php
/**
 * credentials.php — документы психолога (дипломы, сертификаты) и данные о статусе (ИНН).
 *
 * Дипломы/сертификаты загружаются психологом (при регистрации или в профиле) и
 * показываются в его карточке. ИНН/статус самозанятости хранится для проверки
 * (виден самому психологу и администратору, клиентам — нет).
 *
 * Самостоятельный файл, та же БД, сам создаёт таблицу psychologist_credentials.
 *
 * POST ?action=add     {type:'diploma'|'cert'|'inn', url?, name?, meta?}  — психолог (по сессии)
 * GET  ?action=mine                                                       — мои документы
 * GET  ?action=list&psychologist_id=...                                   — публично: дипломы/сертификаты карточки
 * POST ?action=delete  {id}                                               — удалить свой документ
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
    $pdo->exec("CREATE TABLE IF NOT EXISTS psychologist_credentials (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        psychologist_id INT NULL,
        type VARCHAR(20) NOT NULL DEFAULT 'diploma',
        url TEXT NULL,
        name VARCHAR(255) NULL,
        meta TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_psy (psychologist_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

function psychIdForUser(PDO $pdo, $userId) {
    try { $st = $pdo->prepare("SELECT id FROM psychologists WHERE user_id = ? LIMIT 1"); $st->execute([$userId]); $v = $st->fetchColumn(); return $v !== false ? $v : null; }
    catch (Exception $e) { return null; }
}
function userIdForPsych(PDO $pdo, $psyId) {
    try { $st = $pdo->prepare("SELECT user_id FROM psychologists WHERE id = ? LIMIT 1"); $st->execute([$psyId]); $v = $st->fetchColumn(); return $v !== false ? $v : null; }
    catch (Exception $e) { return null; }
}
function roleOf(PDO $pdo, $userId) {
    try { $st = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1"); $st->execute([$userId]); return $st->fetchColumn() ?: ''; }
    catch (Exception $e) { return ''; }
}

$action = $_GET['action'] ?? '';
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

// ── Публичный список дипломов/сертификатов для карточки ──────────────────────────
if ($action === 'list') {
    $psyId = $_GET['psychologist_id'] ?? null;
    if (!$psyId) { echo json_encode(['ok' => true, 'data' => []]); exit; }
    $uid = userIdForPsych($pdo, $psyId);
    try {
        $st = $pdo->prepare("SELECT id, type, url, name, created_at FROM psychologist_credentials
                             WHERE (psychologist_id = ? OR (user_id = ? AND ? IS NOT NULL))
                               AND type IN ('diploma','cert') AND url IS NOT NULL AND url <> ''
                             ORDER BY id ASC");
        $st->execute([$psyId, $uid, $uid]);
        echo json_encode(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { echo json_encode(['ok' => true, 'data' => []]); }
    exit;
}

// Дальше — только для авторизованных
if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }

if ($action === 'add') {
    $type = trim((string)($body['type'] ?? 'diploma'));
    if (!in_array($type, ['diploma', 'cert', 'inn'], true)) $type = 'diploma';
    $url = trim((string)($body['url'] ?? ''));
    $name = trim((string)($body['name'] ?? ''));
    $meta = isset($body['meta']) ? (is_string($body['meta']) ? $body['meta'] : json_encode($body['meta'], JSON_UNESCAPED_UNICODE)) : null;
    if ($type !== 'inn' && $url === '') { http_response_code(400); echo json_encode(['error' => 'Нужен файл документа']); exit; }
    $psyId = psychIdForUser($pdo, $userId);
    // ИНН — один на психолога: обновляем существующий
    if ($type === 'inn') {
        try {
            $chk = $pdo->prepare("SELECT id FROM psychologist_credentials WHERE user_id = ? AND type = 'inn' LIMIT 1");
            $chk->execute([$userId]);
            $exist = $chk->fetchColumn();
            if ($exist) {
                $pdo->prepare("UPDATE psychologist_credentials SET name = ?, meta = ?, psychologist_id = COALESCE(psychologist_id, ?) WHERE id = ?")->execute([$name, $meta, $psyId, $exist]);
                echo json_encode(['ok' => true, 'id' => (int)$exist]); exit;
            }
        } catch (Exception $e) {}
    }
    try {
        $st = $pdo->prepare("INSERT INTO psychologist_credentials (user_id, psychologist_id, type, url, name, meta) VALUES (?,?,?,?,?,?)");
        $st->execute([$userId, $psyId, $type, $url ?: null, $name, $meta]);
        echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось сохранить документ']); }
    exit;
}

if ($action === 'mine') {
    try {
        $st = $pdo->prepare("SELECT id, type, url, name, meta, created_at FROM psychologist_credentials WHERE user_id = ? ORDER BY id ASC");
        $st->execute([$userId]);
        echo json_encode(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { echo json_encode(['ok' => true, 'data' => []]); }
    exit;
}

if ($action === 'delete') {
    $id = $body['id'] ?? null;
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id обязателен']); exit; }
    $isAdmin = roleOf($pdo, $userId) === 'admin';
    try {
        if ($isAdmin) $pdo->prepare("DELETE FROM psychologist_credentials WHERE id = ?")->execute([$id]);
        else $pdo->prepare("DELETE FROM psychologist_credentials WHERE id = ? AND user_id = ?")->execute([$id, $userId]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось удалить']); }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Неизвестное действие']);
