<?php
/**
 * replace.php — заявка клиента на замену психолога («не подошёл»).
 *
 * Бесплатная замена: клиент оставляет заявку (с причиной по желанию), мы помогаем
 * подобрать другого специалиста. Заявки видит администратор в админ-панели.
 *
 * Самостоятельный файл, та же БД, сам создаёт таблицу replacement_requests.
 *
 * POST ?action=create {current_psychologist_id?, reason?}  — клиент
 * GET  ?action=my                                          — заявки клиента
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
    $pdo->exec("CREATE TABLE IF NOT EXISTS replacement_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_user_id INT NOT NULL,
        current_psychologist_id INT NULL,
        reason TEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'new',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_client (client_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

$action = $_GET['action'] ?? '';

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $psyId = $body['current_psychologist_id'] ?? null;
    $reason = trim((string)($body['reason'] ?? ''));
    try {
        $st = $pdo->prepare("INSERT INTO replacement_requests (client_user_id, current_psychologist_id, reason, status)
                             VALUES (?, ?, ?, 'new')");
        $st->execute([$userId, $psyId ?: null, $reason]);
        echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    } catch (Exception $e) {
        http_response_code(500); echo json_encode(['error' => 'Не удалось отправить заявку']);
    }
    exit;
}

if ($action === 'my') {
    try {
        $st = $pdo->prepare("SELECT * FROM replacement_requests WHERE client_user_id = ? ORDER BY id DESC");
        $st->execute([$userId]);
        echo json_encode(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        echo json_encode(['ok' => true, 'data' => []]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Неизвестное действие']);
