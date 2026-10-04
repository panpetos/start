<?php
/**
 * quick_replies.php — личные «быстрые ответы» (шаблоны) для чата.
 *
 * ЗАЧЕМ. Психолог/админ раз за разом печатает одни и те же фразы («Здравствуйте!
 * Спасибо за обращение…», реквизиты, инструкции). Шаблон сохраняется один раз и
 * вставляется в поле ввода одним касанием.
 *
 * Самостоятельный файл, та же БД, сам создаёт таблицу. Шаблоны — личные (по user_id).
 *
 * GET  ?action=list                 → мои шаблоны
 * POST ?action=add    {text}        → добавить (вернёт созданный)
 * POST ?action=delete {id}          → удалить свой
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

function qrOut($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS quick_replies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(64) NOT NULL,
        text TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_user (user_id)
    ) DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { qrOut(['ok' => false, 'error' => 'Не удалось подготовить таблицу'], 500); }

$action = $_GET['action'] ?? '';
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

if ($action === 'list') {
    try {
        $st = $pdo->prepare("SELECT id, text FROM quick_replies WHERE user_id = ? ORDER BY id ASC LIMIT 200");
        $st->execute([$userId]);
        qrOut(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC) ?: []]);
    } catch (Exception $e) { qrOut(['ok' => true, 'data' => []]); }
}

if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $text = trim((string)($body['text'] ?? ''));
    if ($text === '') qrOut(['ok' => false, 'error' => 'Пустой шаблон'], 400);
    $text = mb_substr($text, 0, 4000);
    try {
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM quick_replies WHERE user_id = ?");
        $cnt->execute([$userId]);
        if ((int)$cnt->fetchColumn() >= 100) qrOut(['ok' => false, 'error' => 'Больше 100 шаблонов не поддерживается'], 400);
        $st = $pdo->prepare("INSERT INTO quick_replies (user_id, text, created_at) VALUES (?, ?, NOW())");
        $st->execute([$userId, $text]);
        qrOut(['ok' => true, 'item' => ['id' => (int)$pdo->lastInsertId(), 'text' => $text]]);
    } catch (Exception $e) { qrOut(['ok' => false, 'error' => 'Не удалось сохранить'], 500); }
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) qrOut(['ok' => false, 'error' => 'Некорректный id'], 400);
    try {
        $pdo->prepare("DELETE FROM quick_replies WHERE id = ? AND user_id = ?")->execute([$id, $userId]);
        qrOut(['ok' => true]);
    } catch (Exception $e) { qrOut(['ok' => false, 'error' => 'Не удалось удалить'], 500); }
}

qrOut(['ok' => false, 'error' => 'Неизвестное действие'], 400);
