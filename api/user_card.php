<?php
/**
 * user_card.php — мини-карточка собеседника для чата (задача #29): клик по
 * аватару в chat.html открывает публичную карточку — у психолога это то же,
 * что видно на его профиле (специализация, цена, рейтинг, подписчики), у
 * клиента — минимум публичных данных (имя, аватар, с какого года на
 * платформе), без личных данных (email/телефон и т.п. не отдаются).
 *
 * GET ?action=get&user_id=X   — требует авторизации (любая роль)
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
if (!($_SESSION['user_id'] ?? null)) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }

function out($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$action = $_GET['action'] ?? '';
if ($action !== 'get') out(['error' => 'Неизвестное действие'], 400);

$targetId = trim((string)($_GET['user_id'] ?? ''));
if ($targetId === '') out(['error' => 'user_id обязателен'], 400);

try {
    $st = $pdo->prepare("SELECT id, first_name, last_name, avatar, role, created_at FROM users WHERE id = ? LIMIT 1");
    $st->execute([$targetId]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) { $u = null; }
if (!$u) out(['error' => 'Пользователь не найден'], 404);

$card = [
    'ok' => true,
    'user_id' => $u['id'],
    'first_name' => $u['first_name'],
    'last_name' => $u['last_name'],
    'avatar' => $u['avatar'],
    'role' => $u['role'],
    'member_since' => $u['created_at'],
];

if ($u['role'] === 'psychologist') {
    try {
        $st = $pdo->prepare("SELECT id, specialization, experience, education, description, price FROM psychologists WHERE user_id = ? LIMIT 1");
        $st->execute([$targetId]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $p = null; }
    if ($p) {
        $card['psychologist_id'] = $p['id'];
        $card['specialization'] = $p['specialization'];
        $card['experience'] = $p['experience'];
        $card['education'] = $p['education'];
        $card['description'] = $p['description'];
        $card['price'] = $p['price'];
        try {
            $st = $pdo->prepare("SELECT COUNT(*) AS cnt, ROUND(AVG(rating),1) AS avg_r FROM reviews_ext WHERE psychologist_id = ?");
            $st->execute([$p['id']]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            $card['reviews_count'] = (int)($r['cnt'] ?? 0);
            $card['rating'] = $r['avg_r'] !== null ? (float)$r['avg_r'] : null;
        } catch (Exception $e) { $card['reviews_count'] = 0; $card['rating'] = null; }
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM channel_subscriptions WHERE psychologist_id = ?");
            $st->execute([$p['id']]);
            $card['subscribers_count'] = (int)$st->fetchColumn();
        } catch (Exception $e) { $card['subscribers_count'] = 0; }
    }
}

out($card);
