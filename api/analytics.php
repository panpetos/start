<?php
/**
 * analytics.php — лёгкая собственная аналитика (задача #39): посетители,
 * просмотры страниц, целевые действия — без сторонних сервисов и без
 * персональных данных (анонимный id устройства в cookie, IP не хранится).
 *
 * POST ?action=track  {path, referrer}   — публичный, вызывается с каждой страницы
 * GET  ?action=summary&days=7            — только админ: сводка для панели
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

function out($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS analytics_events (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        visitor_id VARCHAR(40) NOT NULL,
        path VARCHAR(500) NOT NULL,
        referrer VARCHAR(500) NULL,
        role VARCHAR(20) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created (created_at),
        INDEX idx_visitor (visitor_id),
        INDEX idx_path (path(191))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

$action = $_GET['action'] ?? '';
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

if ($action === 'track') {
    // Анонимный идентификатор устройства — свой cookie, никак не связан с учёткой пользователя.
    $vid = $_COOKIE['psy_vid'] ?? '';
    if (!preg_match('/^[a-f0-9]{32}$/', (string)$vid)) {
        $vid = bin2hex(random_bytes(16));
        setcookie('psy_vid', $vid, [
            'expires' => time() + 60 * 60 * 24 * 365,
            'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }
    $path = mb_substr(trim((string)($body['path'] ?? '/')), 0, 490);
    $ref = mb_substr(trim((string)($body['referrer'] ?? '')), 0, 490);
    $role = null;
    if (session_status() === PHP_SESSION_NONE) session_start();
    $uid = $_SESSION['user_id'] ?? null;
    if ($uid) {
        try { $st = $pdo->prepare("SELECT role FROM users WHERE id=? LIMIT 1"); $st->execute([$uid]); $role = $st->fetchColumn() ?: null; } catch (Exception $e) {}
    }
    try {
        $pdo->prepare("INSERT INTO analytics_events (visitor_id, path, referrer, role) VALUES (?, ?, ?, ?)")
            ->execute([$vid, $path, $ref ?: null, $role]);
    } catch (Exception $e) {}
    out(['ok' => true]);
}

if ($action === 'summary') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $uid = $_SESSION['user_id'] ?? null;
    $isAdmin = false;
    if ($uid) {
        try { $st = $pdo->prepare("SELECT role FROM users WHERE id=? LIMIT 1"); $st->execute([$uid]); $isAdmin = ((string)$st->fetchColumn() === 'admin'); } catch (Exception $e) {}
    }
    if (!$isAdmin) out(['error' => 'Доступ только для администратора'], 403);

    $days = max(1, min(90, (int)($_GET['days'] ?? 7)));

    $totals = ['pageviews' => 0, 'visitors' => 0];
    try {
        $st = $pdo->prepare("SELECT COUNT(*) pv, COUNT(DISTINCT visitor_id) uv FROM analytics_events WHERE created_at >= (NOW() - INTERVAL ? DAY)");
        $st->execute([$days]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) { $totals['pageviews'] = (int)$r['pv']; $totals['visitors'] = (int)$r['uv']; }
    } catch (Exception $e) {}

    $byDay = [];
    try {
        $st = $pdo->prepare("SELECT DATE(created_at) d, COUNT(*) pv, COUNT(DISTINCT visitor_id) uv
            FROM analytics_events WHERE created_at >= (NOW() - INTERVAL ? DAY) GROUP BY DATE(created_at) ORDER BY d ASC");
        $st->execute([$days]);
        $byDay = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    $topPages = [];
    try {
        $st = $pdo->prepare("SELECT path, COUNT(*) c FROM analytics_events WHERE created_at >= (NOW() - INTERVAL ? DAY) GROUP BY path ORDER BY c DESC LIMIT 15");
        $st->execute([$days]);
        $topPages = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    $topReferrers = [];
    try {
        $st = $pdo->prepare("SELECT referrer, COUNT(*) c FROM analytics_events WHERE referrer IS NOT NULL AND referrer <> '' AND created_at >= (NOW() - INTERVAL ? DAY) GROUP BY referrer ORDER BY c DESC LIMIT 10");
        $st->execute([$days]);
        $topReferrers = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    // Целевые действия — берём из реальных таблиц (не из трекера, там уже есть точные данные).
    $goals = [];
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='client' AND created_at >= (NOW() - INTERVAL ? DAY)");
        $st->execute([$days]); $goals['Регистрации клиентов'] = (int)$st->fetchColumn();
    } catch (Exception $e) { $goals['Регистрации клиентов'] = 0; }
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='psychologist' AND created_at >= (NOW() - INTERVAL ? DAY)");
        $st->execute([$days]); $goals['Регистрации психологов'] = (int)$st->fetchColumn();
    } catch (Exception $e) { $goals['Регистрации психологов'] = 0; }
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE created_at >= (NOW() - INTERVAL ? DAY)");
        $st->execute([$days]); $goals['Новые записи'] = (int)$st->fetchColumn();
    } catch (Exception $e) { $goals['Новые записи'] = 0; }
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE status='success' AND paid_at >= (NOW() - INTERVAL ? DAY)");
        $st->execute([$days]); $goals['Успешные оплаты'] = (int)$st->fetchColumn();
    } catch (Exception $e) { $goals['Успешные оплаты'] = 0; }

    out(['ok' => true, 'days' => $days, 'totals' => $totals, 'by_day' => $byDay, 'top_pages' => $topPages, 'top_referrers' => $topReferrers, 'goals' => $goals]);
}

out(['error' => 'Неизвестное действие'], 400);
