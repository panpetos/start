<?php
/**
 * yookassa.php — интеграция с ЮKassa (YooKassa).
 *
 * Действия:
 *   GET  ?action=diag            — диагностика конфигурации (админ)
 *   POST ?action=save-config     — сохранить ключи (админ)
 *   POST ?action=create-payment  — создать платёж
 *   GET  ?action=payment-status&payment_id=...  — статус платежа
 *   POST ?action=webhook         — обработка уведомлений от YooKassa
 *
 * API YooKassa v3: https://api.yookassa.ru/v3
 * Аутентификация: HTTP Basic Auth (shop_id:secret_key)
 * Idempotence-Key обязателен для POST-запросов
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';

$cfgFile = __DIR__ . '/yookassa_config.php';
$cfg = file_exists($cfgFile) ? (include $cfgFile) : [];
if (!is_array($cfg)) $cfg = [];

if (session_status() === PHP_SESSION_NONE) session_start();
$uid = $_SESSION['user_id'] ?? null;
$isAdmin = false;
if ($uid) {
    if (!function_exists('getDB') && !function_exists('getDbConnection') && !function_exists('getPDO')) {
        require_once __DIR__ . '/db.php';
    }
    $pdo = function_exists('getDB') ? getDB()
         : (function_exists('getDbConnection') ? getDbConnection()
         : (function_exists('getPDO') ? getPDO() : null));
    if ($pdo) {
        try { $st = $pdo->prepare("SELECT role FROM users WHERE id=? LIMIT 1"); $st->execute([$uid]); $isAdmin = ((string)$st->fetchColumn() === 'admin'); } catch (Exception $e) {}
    }
}

$action = $_GET['action'] ?? '';
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

function out($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$YOOKASSA_API = 'https://api.yookassa.ru/v3';

function yooRequest($method, $endpoint, $data = null) {
    global $cfg, $YOOKASSA_API;
    $shopId = (string)($cfg['shop_id'] ?? '');
    $secretKey = (string)($cfg['secret_key'] ?? '');
    if (!$shopId || !$secretKey) return ['error' => 'YooKassa не настроена'];

    $ch = curl_init($YOOKASSA_API . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $shopId . ':' . $secretKey,
        CURLOPT_TIMEOUT => 30,
    ]);
    $headers = ['Content-Type: application/json'];
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
        $headers[] = 'Idempotence-Key: ' . bin2hex(random_bytes(16));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $result = json_decode($resp, true) ?: [];
    $result['_http_code'] = $code;
    return $result;
}

// ── Диагностика ──────────────────────────────────────────────────────────────
if ($action === 'diag') {
    if (!$isAdmin) out(['error' => 'Только для администратора'], 403);
    out([
        'ok' => true,
        'config_file_present' => file_exists($cfgFile),
        'config_is_array' => is_array($cfg) && !empty($cfg),
        'shop_id' => (string)($cfg['shop_id'] ?? ''),
        'secret_key_set' => !empty($cfg['secret_key']),
        'is_test' => (bool)($cfg['is_test'] ?? true),
        'return_url' => (string)($cfg['return_url'] ?? ''),
        'active_configured' => !empty($cfg['shop_id']) && !empty($cfg['secret_key']),
    ]);
}

// ── Сохранение конфигурации ──────────────────────────────────────────────────
if ($action === 'save-config') {
    if (!$isAdmin) out(['error' => 'Только для администратора'], 403);
    $newCfg = $cfg;
    if (!empty($body['shop_id'])) $newCfg['shop_id'] = trim((string)$body['shop_id']);
    if (!empty($body['secret_key'])) $newCfg['secret_key'] = trim((string)$body['secret_key']);
    if (isset($body['is_test'])) $newCfg['is_test'] = (bool)$body['is_test'];
    if (!empty($body['return_url'])) $newCfg['return_url'] = trim((string)$body['return_url']);
    $php = "<?php\n// YooKassa config — НЕ в git.\nreturn " . var_export($newCfg, true) . ";\n";
    if (@file_put_contents($cfgFile, $php) === false) out(['error' => 'Не удалось записать yookassa_config.php'], 500);
    @chmod($cfgFile, 0640);
    out(['ok' => true]);
}

// ── Создание платежа ─────────────────────────────────────────────────────────
if ($action === 'create-payment') {
    $amount = (string)($body['amount'] ?? '');
    $description = (string)($body['description'] ?? 'Оплата на psytalk.pro');
    $returnUrl = (string)($cfg['return_url'] ?? 'https://psytalk.pro/payment-success.html');
    $metadata = $body['metadata'] ?? [];

    if (!$amount || floatval($amount) <= 0) out(['error' => 'Некорректная сумма'], 400);

    $paymentData = [
        'amount' => ['value' => number_format(floatval($amount), 2, '.', ''), 'currency' => 'RUB'],
        'capture' => true,
        'confirmation' => ['type' => 'redirect', 'return_url' => $returnUrl],
        'description' => mb_substr($description, 0, 128),
    ];
    if (!empty($metadata)) $paymentData['metadata'] = $metadata;

    $result = yooRequest('POST', '/payments', $paymentData);
    if (isset($result['id'])) {
        out(['ok' => true, 'payment_id' => $result['id'], 'confirmation_url' => $result['confirmation']['confirmation_url'] ?? '', 'status' => $result['status'] ?? '']);
    } else {
        out(['error' => $result['description'] ?? $result['error'] ?? 'Ошибка создания платежа', 'details' => $result], $result['_http_code'] ?? 500);
    }
}

// ── Статус платежа ───────────────────────────────────────────────────────────
if ($action === 'payment-status') {
    $paymentId = (string)($_GET['payment_id'] ?? '');
    if (!$paymentId) out(['error' => 'Нужен payment_id'], 400);
    $result = yooRequest('GET', '/payments/' . urlencode($paymentId));
    if (isset($result['id'])) {
        out(['ok' => true, 'payment' => $result]);
    } else {
        out(['error' => $result['description'] ?? 'Платёж не найден'], $result['_http_code'] ?? 404);
    }
}

// ── Webhook от YooKassa ──────────────────────────────────────────────────────
if ($action === 'webhook') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!$data || !isset($data['event'])) { http_response_code(200); exit; }

    // TODO: Проверка IP-адреса из whitelist (185.71.76.0/27, 185.71.77.0/27, 77.75.153.0/25)
    // TODO: Обработка событий payment.succeeded, payment.canceled, refund.succeeded
    // TODO: Обновление статуса в таблице payments

    http_response_code(200);
    echo json_encode(['ok' => true]);
    exit;
}

out(['error' => 'Неизвестное действие'], 400);
