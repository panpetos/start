<?php
/**
 * sos.php — кнопка «Мне плохо сейчас».
 *
 * Главное в окне SOS — телефоны доверия (они на клиенте, работают всегда). Здесь —
 * только необязательный сигнал живому человеку: отправить в переписку с психологом
 * (или, если чат не личный, дежурному админу) пометку «нужна помощь» и разбудить
 * его пушем. Это НЕ замена экстренным службам — просто быстрый способ позвать своего
 * специалиста.
 *
 * POST ?action=alert {peer?}   — послать сигнал (peer — ключ текущего чата)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rtc_lib.php';        // rtcSendDm()
require_once __DIR__ . '/rate_limit.php';
if (!function_exists('getDB') && !function_exists('getDbConnection') && !function_exists('getPDO')) {
    require_once __DIR__ . '/db.php';
}
$pdo = function_exists('getDB') ? getDB()
     : (function_exists('getDbConnection') ? getDbConnection()
     : (function_exists('getPDO') ? getPDO() : null));
if (!$pdo) { http_response_code(500); echo json_encode(['error' => 'Нет подключения к БД']); exit; }

if (session_status() === PHP_SESSION_NONE) session_start();
$userId = $_SESSION['user_id'] ?? null;
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }

function sosOut($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

function sosName(PDO $pdo, $id) {
    try {
        $st = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? trim((($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))) : '';
    } catch (Exception $e) { return ''; }
}
function sosPrimaryAdmin(PDO $pdo) {
    try {
        $st = $pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY created_at ASC, id ASC LIMIT 1");
        $v = $st->fetchColumn();
        return $v ? (string)$v : null;
    } catch (Exception $e) { return null; }
}

$action = $_GET['action'] ?? '';
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

if ($action === 'alert' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Не чаще 5 сигналов в час — на случай залипшей кнопки, но кризис не блокируем жёстко.
    if (!psyRateLimit($pdo, 'sos:' . $userId, 5, 3600)) {
        sosOut(['ok' => false, 'error' => 'Сигнал уже отправлен. Если срочно — позвоните по телефону доверия.'], 429);
    }
    $peer = trim((string)($body['peer'] ?? ''));
    // Личный чат (peer — обычный id пользователя) → шлём собеседнику; иначе дежурному админу.
    $target = null;
    $isPlainUser = $peer !== '' && strpos($peer, 'group:') !== 0 && strpos($peer, 'channel:') !== 0
        && strpos($peer, 'bot:') !== 0 && strpos($peer, 'support:') !== 0
        && $peer !== '__fav__' && $peer !== '__support__' && $peer !== '__ai__';
    if ($isPlainUser) {
        // Убедимся, что это реальный пользователь и не сам инициатор.
        if ((string)$peer !== (string)$userId && sosName($pdo, $peer) !== '') $target = $peer;
    }
    if (!$target) $target = sosPrimaryAdmin($pdo);
    if (!$target || (string)$target === (string)$userId) {
        sosOut(['ok' => true, 'delivered' => false]);   // некому — окно всё равно покажет телефоны
    }

    $me = sosName($pdo, $userId) ?: 'Клиент';
    $text = "🆘 СИГНАЛ SOS от «{$me}»: нужна помощь прямо сейчас (отправлено кнопкой «Мне плохо»). "
          . "Пожалуйста, откликнитесь как можно скорее.";
    if (function_exists('rtcSendDm')) { try { rtcSendDm($pdo, $userId, $target, $text); } catch (\Throwable $e) {} }

    // Разбудить получателя пушем.
    if (!function_exists('push_send_to_user') && @file_exists(__DIR__ . '/push.php')) {
        $GLOBALS['__PUSH_LIB_ONLY'] = true;
        try { require_once __DIR__ . '/push.php'; } catch (\Throwable $e) {}
    }
    if (function_exists('push_send_to_user')) { try { push_send_to_user($pdo, $target); } catch (\Throwable $e) {} }

    sosOut(['ok' => true, 'delivered' => true, 'to' => sosName($pdo, $target) ?: 'специалисту']);
}

sosOut(['ok' => false, 'error' => 'Неизвестное действие'], 400);
