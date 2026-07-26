<?php
/**
 * robokassa.php — приём платежей через Робокассу (обычная схема, без сплита).
 *
 * Действия:
 *   POST ?action=init    (нужна авторизация) — создаёт запись+инвойс, возвращает {paymentUrl}
 *   GET/POST ?action=result  (вызывает Робокасса, ResultURL) — проверка подписи Password#2,
 *                              отметка оплаты; ответ «OK{InvId}»
 *   GET ?action=success  (SuccessURL, браузер клиента) — редирект на страницу успеха
 *   GET ?action=fail     (FailURL) — редирект назад
 *
 * Ключи хранятся ВНЕ git — в api/robokassa_config.php (см. robokassa_config.sample.php).
 * Переключение тест/боевой — флагом IsTest в конфиге.
 */

require_once __DIR__ . '/config.php';
if (!function_exists('getDB') && !function_exists('getDbConnection') && !function_exists('getPDO')) {
    require_once __DIR__ . '/db.php';
}
$pdo = function_exists('getDB') ? getDB()
     : (function_exists('getDbConnection') ? getDbConnection()
     : (function_exists('getPDO') ? getPDO() : null));

$action = $_GET['action'] ?? '';

// ── Конфиг (вне git) ───────────────────────────────────────────────────────────
$cfgFile = __DIR__ . '/robokassa_config.php';
$cfg = file_exists($cfgFile) ? (include $cfgFile) : (include __DIR__ . '/robokassa_config.sample.php');
$login     = $cfg['MerchantLogin'] ?? 'psytalk.pro';
$isTest    = (int)($cfg['IsTest'] ?? 1);
$creds     = $isTest ? ($cfg['test'] ?? []) : ($cfg['prod'] ?? []);
$password1 = (string)($creds['password1'] ?? '');
$password2 = (string)($creds['password2'] ?? '');
$receiptCfg = $cfg['receipt'] ?? ['enabled' => false];

function robokassaConfigured($p1, $p2): bool {
    return $p1 !== '' && $p2 !== '' && strpos($p1, 'ВПИШИТЕ') === false && strpos($p2, 'ВПИШИТЕ') === false;
}
function jsonOut($data, int $code = 200) { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function genUUID(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
}

// ── Схема: таблица инвойсов + задел под сплит (Shop ID психолога) ───────────────
function ensureSchema(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS robokassa_invoices (
            id INT AUTO_INCREMENT PRIMARY KEY,           -- это InvId для Робокассы
            appointment_id VARCHAR(64) NULL,
            client_user_id VARCHAR(64) NULL,
            psychologist_id VARCHAR(64) NULL,
            out_sum DECIMAL(10,2) NOT NULL,
            description VARCHAR(255) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending | paid | failed
            is_test TINYINT NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            paid_at DATETIME NULL,
            INDEX idx_appt (appointment_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}
    // Задел под сплит: поле Robokassa Shop ID у психолога (пока пустое, не используется)
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM psychologists LIKE 'robokassa_shop_id'")->fetchAll();
        if (!$cols) {
            $pdo->exec("ALTER TABLE psychologists ADD COLUMN robokassa_shop_id VARCHAR(64) NULL");
        }
    } catch (Exception $e) {}
}

if (!$pdo) {
    if ($action === 'result') { header('Content-Type: text/plain'); echo 'DB error'; exit; }
    jsonOut(['error' => 'Нет подключения к БД'], 500);
}
ensureSchema($pdo);

$base = 'https://auth.robokassa.ru/Merchant/Index.aspx';

// ─────────────────────────────────────────────────────────────────────────────
// INIT — создать заказ и вернуть ссылку на оплату
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'init' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) jsonOut(['error' => 'Требуется авторизация'], 401);

    if (!robokassaConfigured($password1, $password2)) {
        jsonOut(['error' => 'Приём платежей ещё не настроен. Заполните api/robokassa_config.php на сервере.'], 503);
    }

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $psychologistId = $data['psychologistId'] ?? null;
    $dateTime = $data['dateTime'] ?? null;
    $format = in_array(($data['format'] ?? 'video'), ['video', 'audio', 'chat'], true) ? $data['format'] : 'video';
    $price = (float)($data['price'] ?? 0);

    if (!$psychologistId || !$dateTime) jsonOut(['error' => 'Не указан психолог или дата'], 400);

    // Проверяем психолога и берём цену из БД (не доверяем цене с клиента)
    try {
        $st = $pdo->prepare("SELECT id, price FROM psychologists WHERE id = ? AND is_approved = 1 LIMIT 1");
        $st->execute([$psychologistId]);
        $psy = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $psy = null; }
    if (!$psy) jsonOut(['error' => 'Психолог не найден'], 404);
    if ($price <= 0) $price = (float)$psy['price'];
    if ($price <= 0) jsonOut(['error' => 'Некорректная сумма'], 400);

    // Слот не должен быть занят
    try {
        $st = $pdo->prepare("SELECT id FROM appointments WHERE psychologist_id = ? AND date_time = ? AND status != 'cancelled' LIMIT 1");
        $st->execute([$psychologistId, $dateTime]);
        if ($st->fetch()) jsonOut(['error' => 'Это время уже занято'], 409);
    } catch (Exception $e) {}

    // Создаём запись (ожидает оплаты) и инвойс
    $appointmentId = genUUID();
    try {
        $st = $pdo->prepare("INSERT INTO appointments (id, client_id, psychologist_id, date_time, duration, format, status, price)
                             VALUES (?, ?, ?, ?, 50, ?, 'pending_payment', ?)");
        $st->execute([$appointmentId, $userId, $psychologistId, $dateTime, $format, $price]);
    } catch (Exception $e) {
        jsonOut(['error' => 'Не удалось создать запись'], 500);
    }

    $outSum = number_format($price, 2, '.', '');
    $description = 'Консультация психолога psytalk.pro';
    try {
        $st = $pdo->prepare("INSERT INTO robokassa_invoices (appointment_id, client_user_id, psychologist_id, out_sum, description, status, is_test)
                             VALUES (?, ?, ?, ?, ?, 'pending', ?)");
        $st->execute([$appointmentId, $userId, $psychologistId, $outSum, $description, $isTest]);
        $invId = (int)$pdo->lastInsertId();
    } catch (Exception $e) {
        jsonOut(['error' => 'Не удалось создать платёж'], 500);
    }

    // Receipt (фискализация) — опционально
    $params = [
        'MerchantLogin' => $login,
        'OutSum' => $outSum,
        'InvId' => $invId,
        'Description' => $description,
        'Culture' => 'ru',
    ];
    if (!empty($receiptCfg['enabled'])) {
        $receipt = [
            'sno' => $receiptCfg['sno'] ?? 'usn_income',
            'items' => [[
                'name' => $description,
                'quantity' => 1,
                'sum' => (float)$outSum,
                'payment_method' => $receiptCfg['payment_method'] ?? 'full_payment',
                'payment_object' => $receiptCfg['payment_object'] ?? 'service',
                'tax' => $receiptCfg['tax'] ?? 'none',
            ]],
        ];
        $receiptEnc = urlencode(json_encode($receipt, JSON_UNESCAPED_UNICODE));
        $sig = md5("$login:$outSum:$invId:$receiptEnc:$password1");
        $params['Receipt'] = $receiptEnc;
    } else {
        $sig = md5("$login:$outSum:$invId:$password1");
    }
    $params['SignatureValue'] = $sig;
    if ($isTest) $params['IsTest'] = 1;

    // Собираем URL (Receipt уже url-encoded — не кодируем повторно)
    $qs = [];
    foreach ($params as $k => $v) {
        $qs[] = ($k === 'Receipt') ? "$k=$v" : "$k=" . rawurlencode((string)$v);
    }
    $paymentUrl = $base . '?' . implode('&', $qs);

    jsonOut(['ok' => true, 'paymentUrl' => $paymentUrl, 'invId' => $invId, 'isTest' => $isTest]);
}

// ─────────────────────────────────────────────────────────────────────────────
// RESULT — уведомление от Робокассы (ResultURL). Проверка подписью Password#2.
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'result') {
    header('Content-Type: text/plain; charset=utf-8');
    $outSum = $_REQUEST['OutSum'] ?? $_REQUEST['OutSumm'] ?? '';
    $invId  = $_REQUEST['InvId'] ?? '';
    $sigIn  = $_REQUEST['SignatureValue'] ?? '';
    if ($outSum === '' || $invId === '' || $sigIn === '') { echo 'bad request'; exit; }

    $calc = strtoupper(md5("$outSum:$invId:$password2"));
    if ($calc !== strtoupper($sigIn)) { echo 'bad sign'; exit; }

    try {
        $st = $pdo->prepare("SELECT * FROM robokassa_invoices WHERE id = ? LIMIT 1");
        $st->execute([$invId]);
        $inv = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $inv = null; }
    if (!$inv) { echo 'bad invid'; exit; }

    // Сумма должна совпасть
    if (number_format((float)$inv['out_sum'], 2, '.', '') !== number_format((float)$outSum, 2, '.', '')) {
        echo 'bad sum'; exit;
    }

    if ($inv['status'] !== 'paid') {
        try {
            $pdo->prepare("UPDATE robokassa_invoices SET status='paid', paid_at=NOW() WHERE id=?")->execute([$invId]);
            if (!empty($inv['appointment_id'])) {
                $pdo->prepare("UPDATE appointments SET status='scheduled' WHERE id=?")->execute([$inv['appointment_id']]);
                // Запись в payments (как в остальной системе) — success
                $pid = genUUID();
                try {
                    $pdo->prepare("INSERT INTO payments (id, appointment_id, amount, status, paid_at) VALUES (?, ?, ?, 'success', NOW())")
                        ->execute([$pid, $inv['appointment_id'], $inv['out_sum']]);
                } catch (Exception $e) {}
            }
        } catch (Exception $e) { echo 'db error'; exit; }
    }

    echo 'OK' . $invId;
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// SUCCESS / FAIL — редиректы браузера клиента
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'success') {
    $invId = $_REQUEST['InvId'] ?? '';
    $apptId = '';
    try {
        $st = $pdo->prepare("SELECT appointment_id FROM robokassa_invoices WHERE id = ? LIMIT 1");
        $st->execute([$invId]);
        $apptId = (string)($st->fetchColumn() ?: '');
    } catch (Exception $e) {}
    header('Location: /payment-success.html' . ($apptId ? ('?appointment_id=' . urlencode($apptId)) : ''));
    exit;
}

if ($action === 'fail') {
    $invId = $_REQUEST['InvId'] ?? '';
    try {
        if ($invId !== '') $pdo->prepare("UPDATE robokassa_invoices SET status='failed' WHERE id=? AND status='pending'")->execute([$invId]);
    } catch (Exception $e) {}
    header('Location: /client-dashboard.html?payment=fail');
    exit;
}

jsonOut(['error' => 'Invalid action or method'], 400);
