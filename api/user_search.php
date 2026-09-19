<?php
/**
 * user_search.php — поиск пользователей по всей системе для старта нового чата.
 * Исключает администраторов и самого себя. Доступно любому авторизованному пользователю.
 *
 * GET ?action=search&q=<строка>   — до 30 результатов (клиенты и психологи)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, OPTIONS');
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

$action = $_GET['action'] ?? '';

if ($action === 'search') {
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) { echo json_encode(['ok' => true, 'data' => []]); exit; }
    try {
        $like = '%' . $q . '%';
        $st = $pdo->prepare("SELECT id, role, first_name, last_name, email, phone, avatar FROM users
            WHERE role <> 'admin' AND id <> ?
            AND (first_name LIKE ? OR last_name LIKE ? OR CONCAT(first_name, ' ', last_name) LIKE ? OR email LIKE ? OR phone LIKE ?)
            ORDER BY first_name LIMIT 30");
        $st->execute([$userId, $like, $like, $like, $like, $like]);
        echo json_encode(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        echo json_encode(['ok' => true, 'data' => []]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Неизвестное действие']);
