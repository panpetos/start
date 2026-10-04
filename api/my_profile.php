<?php
/**
 * my_profile.php — профиль ТЕКУЩЕГО психолога по сессии (вне зависимости от модерации).
 *
 * Публичный поиск psychologists.php?action=search отдаёт только одобренных,
 * поэтому психолог «на модерации» не мог увидеть свои же данные в редакторе.
 * Этот эндпоинт читает запись по user_id из сессии.
 *
 * GET ?action=get — вернуть профиль психолога текущего пользователя (+контакты)
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

// Профиль психолога по user_id
$profile = null;
try {
    $st = $pdo->prepare("SELECT * FROM psychologists WHERE user_id = ? LIMIT 1");
    $st->execute([$userId]);
    $profile = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Exception $e) {}

if (!$profile) { echo json_encode(['ok' => true, 'profile' => null]); exit; }

// email пользователя (для подмешивания контактов)
$email = null;
try {
    $st = $pdo->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
    $st->execute([$userId]);
    $email = $st->fetchColumn();
} catch (Exception $e) {}

// контакты из psychologist_contacts (если заполнял при регистрации)
if ($email) {
    try {
        $st = $pdo->prepare("SELECT * FROM psychologist_contacts WHERE email = ? LIMIT 1");
        $st->execute([$email]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if ($c) {
            $profile['contact_phone']    = $c['phone'] ?? '';
            $profile['contact_telegram'] = $c['telegram'] ?? '';
            $profile['contact_whatsapp'] = $c['whatsapp'] ?? '';
            $profile['contact_max']      = $c['max_msg'] ?? '';
        }
    } catch (Exception $e) {}
}

echo json_encode(['ok' => true, 'profile' => $profile]);
