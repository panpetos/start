<?php
/**
 * subscribe.php — публичные записи маркетинговых данных.
 *
 * POST ?action=newsletter  {email}                — подписка на рассылку (из блога)
 * POST ?action=referral    {source, email?, role?} — «как вы нас нашли» (из регистрации)
 *
 * Таблицы создаются автоматически (CREATE TABLE IF NOT EXISTS). Без авторизации.
 * Список для админа отдаёт admin_ext.php (?action=subscribers / ?action=referrals).
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

$action = $_GET['action'] ?? '';
$body = json_decode(file_get_contents('php://input'), true) ?: [];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'Только POST']); exit;
}

if ($action === 'newsletter') {
    $email = trim((string)($body['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400); echo json_encode(['error' => 'Некорректный email']); exit;
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS newsletter_subscribers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL UNIQUE,
            created_at DATETIME NOT NULL
        ) DEFAULT CHARSET=utf8mb4");
        $st = $pdo->prepare("INSERT IGNORE INTO newsletter_subscribers (email, created_at) VALUES (?, NOW())");
        $st->execute([$email]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        http_response_code(500); echo json_encode(['error' => 'Не удалось сохранить подписку']);
    }
    exit;
}

if ($action === 'referral') {
    $source = trim((string)($body['source'] ?? ''));
    $email  = trim((string)($body['email'] ?? ''));
    $role   = trim((string)($body['role'] ?? ''));
    if ($source === '') { http_response_code(400); echo json_encode(['error' => 'source обязателен']); exit; }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS referral_sources (
            id INT AUTO_INCREMENT PRIMARY KEY,
            source VARCHAR(100) NOT NULL,
            email VARCHAR(255) NULL,
            role VARCHAR(50) NULL,
            created_at DATETIME NOT NULL
        ) DEFAULT CHARSET=utf8mb4");
        $st = $pdo->prepare("INSERT INTO referral_sources (source, email, role, created_at) VALUES (?, ?, ?, NOW())");
        $st->execute([mb_substr($source, 0, 100), ($email ?: null), ($role ?: null)]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        http_response_code(500); echo json_encode(['error' => 'Не удалось сохранить']);
    }
    exit;
}

if ($action === 'psy_contact') {
    $email = trim((string)($body['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400); echo json_encode(['error' => 'Некорректный email']); exit;
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS psychologist_contacts (
            email VARCHAR(255) NOT NULL PRIMARY KEY,
            phone VARCHAR(100) NULL,
            telegram VARCHAR(255) NULL,
            whatsapp VARCHAR(100) NULL,
            max_msg VARCHAR(255) NULL,
            created_at DATETIME NOT NULL
        ) DEFAULT CHARSET=utf8mb4");
        $st = $pdo->prepare("INSERT INTO psychologist_contacts (email, phone, telegram, whatsapp, max_msg, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE phone=VALUES(phone), telegram=VALUES(telegram), whatsapp=VALUES(whatsapp), max_msg=VALUES(max_msg)");
        $st->execute([
            $email,
            mb_substr(trim((string)($body['phone'] ?? '')), 0, 100) ?: null,
            mb_substr(trim((string)($body['telegram'] ?? '')), 0, 255) ?: null,
            mb_substr(trim((string)($body['whatsapp'] ?? '')), 0, 100) ?: null,
            mb_substr(trim((string)($body['max'] ?? '')), 0, 255) ?: null,
        ]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        http_response_code(500); echo json_encode(['error' => 'Не удалось сохранить контакты']);
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Неизвестное действие']);
