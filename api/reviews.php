<?php
/**
 * reviews.php — отзывы о психологах.
 *
 * GET  ?action=list&psychologist_id=<id>     — отзывы + средний рейтинг (публично)
 * POST ?action=add  {psychologist_id, rating, text}  — оставить отзыв (нужна авторизация)
 *
 * Таблица создаётся автоматически. Один пользователь — один отзыв на психолога (перезапись).
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

function ensureTable(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS reviews_ext (
        id INT AUTO_INCREMENT PRIMARY KEY,
        psychologist_id VARCHAR(64) NOT NULL,
        author_id VARCHAR(64) NOT NULL,
        author_name VARCHAR(255) NULL,
        rating TINYINT NOT NULL,
        text TEXT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uniq_author_psy (psychologist_id, author_id),
        INDEX idx_psy (psychologist_id)
    ) DEFAULT CHARSET=utf8mb4");
}
try { ensureTable($pdo); } catch (Exception $e) {}

if (session_status() === PHP_SESSION_NONE) session_start();
$userId = $_SESSION['user_id'] ?? null;

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    $pid = (string)($_GET['psychologist_id'] ?? '');
    if ($pid === '') { http_response_code(400); echo json_encode(['error' => 'psychologist_id обязателен']); exit; }
    try {
        $st = $pdo->prepare("SELECT id, author_name, rating, text, created_at FROM reviews_ext WHERE psychologist_id = ? ORDER BY created_at DESC LIMIT 200");
        $st->execute([$pid]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $cnt = count($rows);
        $avg = 0;
        if ($cnt) { foreach ($rows as $r) $avg += (int)$r['rating']; $avg = round($avg / $cnt, 1); }
        echo json_encode(['ok' => true, 'data' => $rows, 'avg' => $avg, 'count' => $cnt]);
    } catch (Exception $e) { echo json_encode(['ok' => true, 'data' => [], 'avg' => 0, 'count' => 0]); }
    exit;
}

if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Войдите, чтобы оставить отзыв']); exit; }
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $pid = (string)($body['psychologist_id'] ?? '');
    $rating = (int)($body['rating'] ?? 0);
    $text = trim((string)($body['text'] ?? ''));
    if ($pid === '' || $rating < 1 || $rating > 5) { http_response_code(400); echo json_encode(['error' => 'Укажите оценку от 1 до 5']); exit; }

    // имя автора
    $name = 'Клиент';
    try {
        $st = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1");
        $st->execute([$userId]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if ($u) { $name = trim(($u['first_name'] ?? '') . ' ' . mb_substr(($u['last_name'] ?? ''), 0, 1)); if ($name === '') $name = 'Клиент'; }
    } catch (Exception $e) {}

    try {
        $st = $pdo->prepare("INSERT INTO reviews_ext (psychologist_id, author_id, author_name, rating, text, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE rating = VALUES(rating), text = VALUES(text), created_at = NOW()");
        $st->execute([$pid, $userId, mb_substr($name, 0, 255), $rating, mb_substr($text, 0, 3000)]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось сохранить отзыв']); }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Неизвестное действие']);
