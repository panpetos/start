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

/**
 * Данные поставщика-психолога для чека по агентской схеме (54-ФЗ): ИНН, имя, телефон.
 * ИНН лежит в psychologist_credentials (type='inn', в поле name) — туда его кладёт
 * регистрация; колонки psychologists.inn на проде нет. Если ИНН не найден, вернём
 * пустой inn — вызывающий код тогда НЕ добавит агентские поля, и оплата не сорвётся.
 */
function robokassaSupplier(PDO $pdo, $psychologistId): array {
    $out = ['inn' => '', 'name' => '', 'phone' => ''];
    $userId = null;
    try {
        $st = $pdo->prepare("SELECT user_id, first_name, last_name FROM psychologists p
                             LEFT JOIN users u ON u.id = p.user_id WHERE p.id = ? LIMIT 1");
        $st->execute([$psychologistId]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $userId = $r['user_id'] ?? null;
            $out['name'] = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        }
    } catch (Exception $e) {}
    try {
        $st = $pdo->prepare("SELECT name FROM psychologist_credentials
                              WHERE type = 'inn' AND (psychologist_id = ? OR user_id = ?)
                              ORDER BY id DESC LIMIT 1");
        $st->execute([$psychologistId, $userId ?: 0]);
        $out['inn'] = preg_replace('/\D+/', '', (string)$st->fetchColumn());
    } catch (Exception $e) {}
    if ($out['name'] === '') $out['name'] = 'Психолог';
    return $out;
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

// ── Диагностика (без раскрытия секретов — только да/нет) ────────────────────────
if ($action === 'diag') {
    $realExists = file_exists($cfgFile);
    $isSet = function ($v) { return ($v !== '' && $v !== null && strpos((string)$v, 'ВПИШИТЕ') === false); };
    jsonOut([
        'ok' => true,
        'config_file_present'  => $realExists,                    // существует ли api/robokassa_config.php
        'loaded_source'        => $realExists ? 'robokassa_config.php' : 'sample (реальный файл не найден!)',
        'config_is_array'      => is_array($cfg),                 // корректно ли файл возвращает массив
        'merchant_login'       => $login,                         // публичный идентификатор, не секрет
        'is_test'              => $isTest,
        'test_password1_set'   => $isSet($cfg['test']['password1'] ?? ''),
        'test_password2_set'   => $isSet($cfg['test']['password2'] ?? ''),
        'prod_password1_set'   => $isSet($cfg['prod']['password1'] ?? ''),
        'prod_password2_set'   => $isSet($cfg['prod']['password2'] ?? ''),
        'active_creds_used'    => $isTest ? 'test' : 'prod',
        'active_configured'    => robokassaConfigured($password1, $password2),
        'receipt_enabled'      => !empty($receiptCfg['enabled']),
        'receipt_sno'          => $receiptCfg['sno'] ?? 'usn_income',
        'receipt_tax'          => $receiptCfg['tax'] ?? 'none',
        'receipt_agent_enabled' => !empty($receiptCfg['agent_enabled']),
        'receipt_agent_type'   => $receiptCfg['agent_type'] ?? 'agent',
    ]);
}

// ── Запись конфига из админки (только admin) — чтобы не редактировать PHP руками ──
if ($action === 'save-config' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $uid = $_SESSION['user_id'] ?? null;
    $role = '';
    if ($uid) {
        try { $st = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1"); $st->execute([$uid]); $role = (string)$st->fetchColumn(); } catch (Exception $e) {}
    }
    if ($role !== 'admin') jsonOut(['error' => 'Только для администратора'], 403);

    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    // Текущие значения (если файл был корректным) — чтобы пустые поля не затирали пароли
    $cur = is_array($cfg) ? $cfg : [];
    $pick = function ($new, $old) { $new = trim((string)$new); return $new !== '' ? $new : (string)$old; };

    $arr = [
        'MerchantLogin' => $pick($b['merchant_login'] ?? '', $cur['MerchantLogin'] ?? 'psytalk.pro'),
        'IsTest' => (int)($b['is_test'] ?? ($cur['IsTest'] ?? 1)) ? 1 : 0,
        'test' => [
            'password1' => $pick($b['test_password1'] ?? '', $cur['test']['password1'] ?? ''),
            'password2' => $pick($b['test_password2'] ?? '', $cur['test']['password2'] ?? ''),
        ],
        'prod' => [
            'password1' => $pick($b['prod_password1'] ?? '', $cur['prod']['password1'] ?? ''),
            'password2' => $pick($b['prod_password2'] ?? '', $cur['prod']['password2'] ?? ''),
        ],
        'receipt' => [
            'enabled'        => !empty($b['receipt_enabled']),
            'sno'            => $b['receipt_sno'] ?? ($cur['receipt']['sno'] ?? 'usn_income'),
            'payment_method' => $cur['receipt']['payment_method'] ?? 'full_payment',
            'payment_object' => $cur['receipt']['payment_object'] ?? 'service',
            'tax'            => $b['receipt_tax'] ?? ($cur['receipt']['tax'] ?? 'none'),
            // Агентская схема (Robokassa Онлайн): признак агента и данные психолога в чеке.
            'agent_enabled'  => array_key_exists('receipt_agent_enabled', $b) ? !empty($b['receipt_agent_enabled']) : !empty($cur['receipt']['agent_enabled']),
            'agent_type'     => $b['receipt_agent_type'] ?? ($cur['receipt']['agent_type'] ?? 'agent'),
        ],
    ];

    $php = "<?php\n// Сгенерировано из админ-панели. Секретный файл — не в git.\nreturn " . var_export($arr, true) . ";\n";
    $ok = @file_put_contents($cfgFile, $php);
    if ($ok === false) {
        jsonOut(['error' => 'Не удалось записать файл robokassa_config.php. Проверьте права на папку /api (нужно разрешить запись веб-серверу) или удалите старый файл.', 'writable' => is_writable(__DIR__)], 500);
    }
    @chmod($cfgFile, 0640);
    jsonOut(['ok' => true, 'saved' => true, 'is_test' => $arr['IsTest'],
        'test_password1_set' => $arr['test']['password1'] !== '', 'test_password2_set' => $arr['test']['password2'] !== '',
        'prod_password1_set' => $arr['prod']['password1'] !== '', 'prod_password2_set' => $arr['prod']['password2'] !== '']);
}

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
        $item = [
            'name' => $description,
            'quantity' => 1,
            'sum' => (float)$outSum,
            'payment_method' => $receiptCfg['payment_method'] ?? 'full_payment',
            'payment_object' => $receiptCfg['payment_object'] ?? 'service',
            'tax' => $receiptCfg['tax'] ?? 'none',
        ];
        // Агентская схема (Robokassa Онлайн): в чеке — признак агента и данные
        // поставщика-психолога (принципала). Включается флагом agent_enabled в
        // настройках. Если у психолога не заполнен ИНН, агентские поля не добавляем —
        // иначе касса отклонит чек; оплата при этом всё равно проходит обычным чеком.
        if (!empty($receiptCfg['agent_enabled'])) {
            $sup = robokassaSupplier($pdo, $psychologistId);
            if ($sup['inn'] !== '') {
                $item['payment_agent_type'] = $receiptCfg['agent_type'] ?? 'agent';
                $item['agent_info'] = ['type' => $receiptCfg['agent_type'] ?? 'agent'];
                $item['supplier_info'] = [
                    'name' => mb_substr($sup['name'], 0, 239),
                    'inn'  => $sup['inn'],
                ];
                if ($sup['phone'] !== '') $item['supplier_info']['phones'] = [$sup['phone']];
            }
        }
        $receipt = [
            'sno' => $receiptCfg['sno'] ?? 'usn_income',
            'items' => [$item],
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
