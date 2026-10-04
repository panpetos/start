<?php
/**
 * schedule.php — недельное расписание доступности психолога.
 *
 * POST ?action=save  {slots:{mon:[...],tue:[...],...}}  — психолог сохраняет свои окна (по сессии)
 * GET  ?action=get&psychologist_id=<id>                 — расписание психолога (для страницы записи, публично)
 * GET  ?action=mine                                     — расписание текущего психолога (для редактора)
 *
 * Хранится недельный шаблон по user_id. Таблица создаётся автоматически.
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
    $pdo->exec("CREATE TABLE IF NOT EXISTS psychologist_schedule (
        user_id VARCHAR(64) NOT NULL PRIMARY KEY,
        data TEXT NOT NULL,
        updated_at DATETIME NOT NULL
    ) DEFAULT CHARSET=utf8mb4");
}
try { ensureTable($pdo); } catch (Exception $e) {}

if (session_status() === PHP_SESSION_NONE) session_start();
$userId = $_SESSION['user_id'] ?? null;

$action = $_GET['action'] ?? '';

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $slots = $body['slots'] ?? null;
    if (!is_array($slots)) { http_response_code(400); echo json_encode(['error' => 'Нет данных']); exit; }
    try {
        $json = json_encode($slots, JSON_UNESCAPED_UNICODE);
        $st = $pdo->prepare("INSERT INTO psychologist_schedule (user_id, data, updated_at) VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = NOW()");
        $st->execute([$userId, $json]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось сохранить']); }
    exit;
}

if ($action === 'mine') {
    if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }
    echo json_encode(['ok' => true, 'slots' => readSlots($pdo, $userId)]);
    exit;
}

if ($action === 'get') {
    $pid = (string)($_GET['psychologist_id'] ?? '');
    if ($pid === '') { http_response_code(400); echo json_encode(['error' => 'psychologist_id обязателен']); exit; }
    // psychologists.id -> user_id
    $uid = null;
    try {
        $st = $pdo->prepare("SELECT user_id FROM psychologists WHERE id = ? LIMIT 1");
        $st->execute([$pid]);
        $uid = $st->fetchColumn();
    } catch (Exception $e) {}
    if (!$uid) { echo json_encode(['ok' => true, 'slots' => null]); exit; }
    echo json_encode(['ok' => true, 'slots' => readSlots($pdo, $uid)]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Неизвестное действие']);

function readSlots(PDO $pdo, $uid) {
    try {
        $st = $pdo->prepare("SELECT data FROM psychologist_schedule WHERE user_id = ? LIMIT 1");
        $st->execute([$uid]);
        $d = $st->fetchColumn();
        return $d ? json_decode($d, true) : null;
    } catch (Exception $e) { return null; }
}
