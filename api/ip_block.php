<?php
/**
 * ip_block.php — управление чёрным списком IP (только администратор).
 * Заводит таблицу ip_blocklist и даёт CRUD для админки.
 *
 * GET  ?action=list                 — список заблокированных IP
 * POST ?action=add   {ip, reason}   — заблокировать IP
 * POST ?action=remove {ip}          — снять блокировку
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

function ibout($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if (session_status() === PHP_SESSION_NONE) session_start();
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) ibout(['error' => 'Требуется авторизация'], 401);
$isAdmin = false;
try { $st = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1"); $st->execute([$userId]); $isAdmin = ((string)$st->fetchColumn() === 'admin'); }
catch (Exception $e) {}
if (!$isAdmin) ibout(['error' => 'Доступ только для администратора'], 403);

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ip_blocklist (
        ip VARCHAR(64) NOT NULL PRIMARY KEY,
        reason VARCHAR(255) NULL,
        added_by VARCHAR(64) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

$action = $_GET['action'] ?? '';
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

if ($action === 'list') {
    try { ibout(['ok' => true, 'data' => $pdo->query("SELECT ip, reason, created_at FROM ip_blocklist ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC)]); }
    catch (Exception $e) { ibout(['ok' => true, 'data' => []]); }
}

if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = trim((string)($body['ip'] ?? ''));
    if (!filter_var($ip, FILTER_VALIDATE_IP)) ibout(['error' => 'Некорректный IP-адрес'], 400);
    // Не дать заблокировать свой же текущий IP — иначе админ сам себя закроет
    $myIp = '';
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) { $c = trim(explode(',', $_SERVER[$k])[0]); if (filter_var($c, FILTER_VALIDATE_IP)) { $myIp = $c; break; } }
    }
    if ($ip === $myIp) ibout(['error' => 'Это ваш текущий IP — блокировать его нельзя, иначе потеряете доступ.'], 400);
    $reason = mb_substr(trim((string)($body['reason'] ?? '')), 0, 255);
    try {
        $pdo->prepare("INSERT INTO ip_blocklist (ip, reason, added_by) VALUES (?,?,?)
                       ON DUPLICATE KEY UPDATE reason = VALUES(reason)")->execute([$ip, $reason, $userId]);
        ibout(['ok' => true]);
    } catch (Exception $e) { ibout(['error' => 'Не удалось заблокировать'], 500); }
}

if ($action === 'remove' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = trim((string)($body['ip'] ?? ''));
    if ($ip === '') ibout(['error' => 'Не указан IP'], 400);
    try { $pdo->prepare("DELETE FROM ip_blocklist WHERE ip = ?")->execute([$ip]); ibout(['ok' => true]); }
    catch (Exception $e) { ibout(['error' => 'Не удалось снять блокировку'], 500); }
}

ibout(['error' => 'Неизвестное действие'], 400);
