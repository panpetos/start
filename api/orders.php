<?php
/**
 * orders.php — индивидуальные счёта/ордера психолога клиенту (гибкая цена и формат).
 *
 * Психолог сам выставляет цену и формат (видео / аудио / текстом — 15 минут, день,
 * в течение недели и т.п.). Клиент видит ТОЛЬКО итоговую цену и принимает/отклоняет.
 * Платформа удерживает комиссию (по умолчанию 40%) — клиенту проценты не показываются.
 *
 * Самостоятельный файл: подключается к той же БД, что и остальные эндпоинты,
 * сам создаёт таблицу session_orders, все запросы в try/catch.
 *
 * POST ?action=create   {client_user_id, title, amount, duration?, note?}  — психолог
 * POST ?action=respond  {order_id, accept:0|1}                              — клиент
 * GET  ?action=list&with=<user_id>                                          — обе стороны
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/schema_util.php';
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

// Текущий пользователь и роль
$me = null;
try {
    $st = $pdo->prepare("SELECT id, role FROM users WHERE id = ? LIMIT 1");
    $st->execute([$userId]);
    $me = $st->fetch(PDO::FETCH_ASSOC);
    psy_widen_id_columns($pdo, 'session_orders', ['psychologist_user_id', 'client_user_id']);
    psy_align_collation($pdo, ['session_orders']);
} catch (Exception $e) {}
$myRole = $me['role'] ?? '';

// Создание таблицы (idempotent)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS session_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        psychologist_user_id VARCHAR(64) NOT NULL,
        client_user_id VARCHAR(64) NOT NULL,
        title VARCHAR(255) NOT NULL,
        note TEXT NULL,
        duration VARCHAR(64) NULL,
        amount DECIMAL(10,2) NOT NULL,
        commission DECIMAL(5,2) NOT NULL DEFAULT 40,
        platform_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
        psychologist_payout DECIMAL(10,2) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        responded_at DATETIME NULL,
        INDEX idx_pair (psychologist_user_id, client_user_id),
        INDEX idx_client (client_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

/** Комиссия платформы из настроек (в процентах), по умолчанию 40. */
function platformCommission(PDO $pdo): float {
    foreach (['settings', 'site_settings', 'options', 'config'] as $table) {
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) { continue; }
        if (!$cols) continue;
        $lc = array_map('strtolower', $cols);
        $kc = null; $vc = null;
        foreach (['setting_key', 'key_name', 'key', 'name', 'k', 'option_name'] as $c) { $i = array_search($c, $lc, true); if ($i !== false) { $kc = $cols[$i]; break; } }
        foreach (['setting_value', 'value', 'val', 'v', 'option_value'] as $c) { $i = array_search($c, $lc, true); if ($i !== false) { $vc = $cols[$i]; break; } }
        if (!$kc || !$vc) continue;
        try {
            $st = $pdo->prepare("SELECT `$vc` FROM `$table` WHERE `$kc` = 'platform_commission' LIMIT 1");
            $st->execute();
            $v = $st->fetchColumn();
            if ($v !== false && $v !== null && $v !== '') return max(0, min(100, (float)$v));
        } catch (Exception $e) {}
    }
    return 40.0;
}

$action = $_GET['action'] ?? '';

// ── Список ордеров диалога ─────────────────────────────────────────────────────
if ($action === 'list') {
    $other = $_GET['with'] ?? null;
    if (!$other) { echo json_encode(['ok' => true, 'data' => []]); exit; }
    try {
        $st = $pdo->prepare("SELECT * FROM session_orders
            WHERE (psychologist_user_id = ? AND client_user_id = ?)
               OR (psychologist_user_id = ? AND client_user_id = ?)
            ORDER BY id ASC");
        $st->execute([$userId, $other, $other, $userId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $rows = []; }

    // Клиенту не отдаём проценты/раскладку — только итог.
    $isPsy = ($myRole === 'psychologist' || $myRole === 'admin');
    foreach ($rows as &$r) {
        $r['is_mine'] = ((string)$r['psychologist_user_id'] === (string)$userId);
        if (!$isPsy) {
            unset($r['commission'], $r['platform_fee'], $r['psychologist_payout']);
        }
    }
    echo json_encode(['ok' => true, 'data' => $rows]);
    exit;
}

// ── Создание ордера (психолог) ─────────────────────────────────────────────────
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($myRole !== 'psychologist' && $myRole !== 'admin') {
        http_response_code(403); echo json_encode(['error' => 'Счёт может выставить только психолог']); exit;
    }
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $client = $body['client_user_id'] ?? null;
    $title = trim((string)($body['title'] ?? ''));
    $amount = isset($body['amount']) ? (float)$body['amount'] : 0;
    $duration = trim((string)($body['duration'] ?? ''));
    $note = trim((string)($body['note'] ?? ''));
    if (!$client || $title === '' || $amount <= 0) {
        http_response_code(400); echo json_encode(['error' => 'Нужны клиент, название и сумма больше 0']); exit;
    }
    $commission = platformCommission($pdo);
    $fee = round($amount * $commission / 100, 2);
    $payout = round($amount - $fee, 2);
    try {
        $st = $pdo->prepare("INSERT INTO session_orders
            (psychologist_user_id, client_user_id, title, note, duration, amount, commission, platform_fee, psychologist_payout, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
        $st->execute([$userId, $client, $title, $note, $duration, $amount, $commission, $fee, $payout]);
        $id = (int)$pdo->lastInsertId();
    } catch (Exception $e) {
        http_response_code(500); echo json_encode(['error' => 'Не удалось создать счёт']); exit;
    }
    echo json_encode(['ok' => true, 'id' => $id, 'payout' => $payout]);
    exit;
}

// ── Ответ клиента (принять/отклонить) ──────────────────────────────────────────
if ($action === 'respond' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $orderId = $body['order_id'] ?? null;
    $accept = !empty($body['accept']);
    if (!$orderId) { http_response_code(400); echo json_encode(['error' => 'order_id обязателен']); exit; }
    try {
        $st = $pdo->prepare("SELECT * FROM session_orders WHERE id = ? LIMIT 1");
        $st->execute([$orderId]);
        $order = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $order = null; }
    if (!$order) { http_response_code(404); echo json_encode(['error' => 'Счёт не найден']); exit; }
    // Отвечать может только клиент этого счёта
    if ((string)$order['client_user_id'] !== (string)$userId) {
        http_response_code(403); echo json_encode(['error' => 'Это не ваш счёт']); exit;
    }
    if ($order['status'] !== 'pending') {
        echo json_encode(['ok' => true, 'status' => $order['status'], 'note' => 'Уже обработан']); exit;
    }
    $status = $accept ? 'accepted' : 'declined';
    try {
        $u = $pdo->prepare("UPDATE session_orders SET status = ?, responded_at = NOW() WHERE id = ?");
        $u->execute([$status, $orderId]);
    } catch (Exception $e) {
        http_response_code(500); echo json_encode(['error' => 'Не удалось обновить счёт']); exit;
    }
    echo json_encode(['ok' => true, 'status' => $status]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Неизвестное действие']);
