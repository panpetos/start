<?php
/**
 * channels.php — простые публичные «каналы» психологов (лента постов) + подписки
 * клиентов. MVP задачи #29 (Telegram-подобные каналы).
 *
 * GET  ?action=posts&psychologist_id=X        — публичная лента постов психолога (без авторизации)
 * GET  ?action=info&psychologist_id=X         — {subscribers_count, is_subscribed}
 * GET  ?action=my-subscriptions                — каналы, на которые подписан текущий пользователь
 * POST ?action=post   {text?, image_url?}      — добавить пост в СВОЙ канал (только психолог)
 * POST ?action=delete-post {id}                — удалить свой пост
 * POST ?action=subscribe   {psychologist_id}   — подписаться (авторизованный пользователь)
 * POST ?action=unsubscribe {psychologist_id}   — отписаться
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

function ensureChannelTables(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS channel_posts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        psychologist_id VARCHAR(64) NOT NULL,
        text TEXT NULL,
        image_url VARCHAR(500) NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_psych (psychologist_id)
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS channel_subscriptions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id VARCHAR(64) NOT NULL,
        psychologist_id VARCHAR(64) NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uniq_sub (client_id, psychologist_id)
    ) DEFAULT CHARSET=utf8mb4");
}

// Вернуть psychologists.id текущего пользователя (по сессии), либо null
function myPsychologistId(PDO $pdo, $userId) {
    if (!$userId) return null;
    try {
        $st = $pdo->prepare("SELECT id FROM psychologists WHERE user_id = ? LIMIT 1");
        $st->execute([$userId]);
        $id = $st->fetchColumn();
        return $id ?: null;
    } catch (Exception $e) { return null; }
}

$action = $_GET['action'] ?? '';
$body = json_decode(file_get_contents('php://input'), true) ?: [];

try { ensureChannelTables($pdo); } catch (Exception $e) {}

if ($action === 'posts') {
    $psychId = (string)($_GET['psychologist_id'] ?? '');
    if ($psychId === '') { http_response_code(400); echo json_encode(['error' => 'psychologist_id обязателен']); exit; }
    try {
        $st = $pdo->prepare("SELECT id, text, image_url, created_at FROM channel_posts WHERE psychologist_id = ? ORDER BY created_at DESC, id DESC LIMIT 200");
        $st->execute([$psychId]);
        echo json_encode(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { echo json_encode(['ok' => true, 'data' => []]); }
    exit;
}

if ($action === 'info') {
    $psychId = (string)($_GET['psychologist_id'] ?? '');
    if ($psychId === '') { http_response_code(400); echo json_encode(['error' => 'psychologist_id обязателен']); exit; }
    $subscribersCount = 0;
    $isSubscribed = false;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM channel_subscriptions WHERE psychologist_id = ?");
        $st->execute([$psychId]);
        $subscribersCount = (int)$st->fetchColumn();
    } catch (Exception $e) {}
    if ($userId) {
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM channel_subscriptions WHERE psychologist_id = ? AND client_id = ?");
            $st->execute([$psychId, $userId]);
            $isSubscribed = (int)$st->fetchColumn() > 0;
        } catch (Exception $e) {}
    }
    echo json_encode(['ok' => true, 'subscribers_count' => $subscribersCount, 'is_subscribed' => $isSubscribed]);
    exit;
}

if ($action === 'my-subscriptions') {
    if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }
    try {
        $st = $pdo->prepare("
            SELECT p.id AS psychologist_id, u.first_name, u.last_name, u.avatar,
                   (SELECT text FROM channel_posts cp WHERE cp.psychologist_id = p.id ORDER BY cp.created_at DESC, cp.id DESC LIMIT 1) AS last_post_text,
                   (SELECT created_at FROM channel_posts cp WHERE cp.psychologist_id = p.id ORDER BY cp.created_at DESC, cp.id DESC LIMIT 1) AS last_post_at
            FROM channel_subscriptions s
            JOIN psychologists p ON p.id = s.psychologist_id
            JOIN users u ON u.id = p.user_id
            WHERE s.client_id = ?
            ORDER BY s.created_at DESC
        ");
        $st->execute([$userId]);
        echo json_encode(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { echo json_encode(['ok' => true, 'data' => []]); }
    exit;
}

if ($action === 'post' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }
    $psychId = myPsychologistId($pdo, $userId);
    if (!$psychId) { http_response_code(403); echo json_encode(['error' => 'Доступно только психологам с созданным профилем']); exit; }
    $text = isset($body['text']) ? trim((string)$body['text']) : '';
    $imageUrl = isset($body['image_url']) ? (string)$body['image_url'] : null;
    if ($text === '' && !$imageUrl) { http_response_code(400); echo json_encode(['error' => 'Пустой пост']); exit; }
    try {
        $st = $pdo->prepare("INSERT INTO channel_posts (psychologist_id, text, image_url, created_at) VALUES (?, ?, ?, NOW())");
        $st->execute([$psychId, $text !== '' ? $text : null, $imageUrl]);
        echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось опубликовать']); }
    exit;
}

if ($action === 'delete-post' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }
    $psychId = myPsychologistId($pdo, $userId);
    $id = (int)($body['id'] ?? 0);
    if (!$psychId || !$id) { http_response_code(400); echo json_encode(['error' => 'Некорректный запрос']); exit; }
    try {
        $st = $pdo->prepare("DELETE FROM channel_posts WHERE id = ? AND psychologist_id = ?");
        $st->execute([$id, $psychId]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось удалить']); }
    exit;
}

if ($action === 'subscribe' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }
    $psychId = (string)($body['psychologist_id'] ?? '');
    if ($psychId === '') { http_response_code(400); echo json_encode(['error' => 'psychologist_id обязателен']); exit; }
    try {
        $st = $pdo->prepare("INSERT IGNORE INTO channel_subscriptions (client_id, psychologist_id, created_at) VALUES (?, ?, NOW())");
        $st->execute([$userId, $psychId]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось подписаться']); }
    exit;
}

if ($action === 'unsubscribe' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }
    $psychId = (string)($body['psychologist_id'] ?? '');
    if ($psychId === '') { http_response_code(400); echo json_encode(['error' => 'psychologist_id обязателен']); exit; }
    try {
        $st = $pdo->prepare("DELETE FROM channel_subscriptions WHERE client_id = ? AND psychologist_id = ?");
        $st->execute([$userId, $psychId]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось отписаться']); }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Неизвестное действие']);
