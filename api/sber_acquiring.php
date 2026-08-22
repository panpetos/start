<?php
/**
 * sber_acquiring.php — приём оплат через интернет-эквайринг Сбербанка (задача №72).
 *
 * ЗАЧЕМ ЭТО НУЖНО. Психологи работают как самозанятые: деньги за консультацию —
 * их, платформа берёт комиссию. Банк называет такую схему «агентской»: в каждом
 * заказе указывается, что платформа выступает агентом (agentInfo.type = another), а
 * исполнитель услуги — конкретный самозанятый (supplierInfo). Из этих данных банк
 * формирует чек на нужное лицо, и платформе не приходится проводить чужую выручку
 * через себя.
 *
 * ЧТО ЗДЕСЬ ЕСТЬ УЖЕ СЕЙЧАС. Всё, кроме логина и пароля от банка: создание заказа,
 * возврат человека после оплаты, проверка состояния, отметка оплаты в наших
 * таблицах, журнал обращений. Как только банк пришлёт userName и password, их
 * достаточно вписать в api/sber_acquiring_config.php — код менять не придётся.
 *
 * ЧЕГО ЗДЕСЬ НЕТ И БЕЗ ЧЕГО НЕ ЗАПУСТИТЬСЯ:
 *   • userName и password от банка (обещаны, ещё не пришли);
 *   • зарегистрированная онлайн-касса — без неё банк откажет в формировании чека;
 *   • ИНН самозанятого у каждого психолога (для supplierInfo). В профиле поле ИНН
 *     уже есть, проверка через api/inn_check.php тоже — здесь оно просто читается.
 *
 * ДЕЙСТВИЯ:
 *   POST ?action=init   {appointment_id|amount, psychologist_id} — создать заказ,
 *                        вернуть {formUrl} — адрес платёжной страницы банка
 *   GET  ?action=status &order_id=...  — спросить банк о состоянии заказа
 *   GET  ?action=return &orderId=...   — сюда банк возвращает человека после оплаты
 *   GET  ?action=fail                  — сюда при отказе
 *   GET  ?action=selftest              — что настроено, а чего не хватает (без секретов)
 *
 * Документация банка: https://ecomtest.sberbank.ru/doc
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/config.php';
if (!function_exists('getDB') && !function_exists('getDbConnection') && !function_exists('getPDO')) {
    require_once __DIR__ . '/db.php';
}
$pdo = function_exists('getDB') ? getDB()
     : (function_exists('getDbConnection') ? getDbConnection()
     : (function_exists('getPDO') ? getPDO() : null));

function sberOut($d, $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

// ── Настройки (вне git) ────────────────────────────────────────────────────────
$cfgFile = __DIR__ . '/sber_acquiring_config.php';
$cfg = file_exists($cfgFile) ? (include $cfgFile) : (include __DIR__ . '/sber_acquiring_config.sample.php');
$isTest = (int)($cfg['is_test'] ?? 1);
$creds  = $isTest ? ($cfg['test'] ?? []) : ($cfg['prod'] ?? []);
$userName = (string)($creds['userName'] ?? '');
$password = (string)($creds['password'] ?? '');
$base     = rtrim((string)($creds['base'] ?? ''), '/');

/** Настроен ли эквайринг по-настоящему (а не образцом с заглушками). */
function sberReady($userName, $password) {
    return $userName !== '' && $password !== ''
        && strpos($userName, 'ВПИШИТЕ') === false && strpos($password, 'ВПИШИТЕ') === false;
}

/** Свои таблицы: заказы и журнал обращений к банку. */
function sberSchema(PDO $pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sber_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_number VARCHAR(64) NOT NULL,
            bank_order_id VARCHAR(64) NULL,
            client_id VARCHAR(64) NOT NULL,
            psychologist_id VARCHAR(64) NULL,
            appointment_id VARCHAR(64) NULL,
            amount_kop INT NOT NULL,
            commission_kop INT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'created',
            form_url VARCHAR(500) NULL,
            created_at DATETIME NOT NULL,
            paid_at DATETIME NULL,
            UNIQUE KEY uniq_order (order_number),
            INDEX idx_client (client_id, status),
            INDEX idx_bank (bank_order_id)
        ) DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS sber_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_number VARCHAR(64) NULL,
            method VARCHAR(40) NOT NULL,
            request MEDIUMTEXT NULL,
            response MEDIUMTEXT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_order (order_number)
        ) DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}
}

/**
 * Обращение к банку. Логин и пароль уходят в каждом запросе — так требует банк.
 * Пишем в журнал и запрос, и ответ, но БЕЗ пароля: разбирать спорные платежи
 * потом придётся, а хранить пароль в базе незачем.
 */
function sberCall(PDO $pdo, $base, $userName, $password, $method, array $params, $orderNumber = null) {
    $params['userName'] = $userName;
    $params['password'] = $password;
    $url = $base . '/' . $method . '.do';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    $safe = $params; unset($safe['password']);
    try {
        $pdo->prepare("INSERT INTO sber_log (order_number, method, request, response, created_at)
                       VALUES (?, ?, ?, ?, NOW())")
            ->execute([$orderNumber, $method, json_encode($safe, JSON_UNESCAPED_UNICODE),
                       $err ? ('ошибка связи: ' . $err) : substr((string)$raw, 0, 60000)]);
    } catch (Exception $e) {}
    if ($err) return ['errorCode' => 'net', 'errorMessage' => 'Банк недоступен: ' . $err];
    $d = json_decode((string)$raw, true);
    return is_array($d) ? $d : ['errorCode' => 'parse', 'errorMessage' => 'Непонятный ответ банка'];
}

/** Данные исполнителя для чека: банк требует ИНН и название самозанятого. */
function sberSupplier(PDO $pdo, $psychologistId) {
    $out = ['name' => '', 'inn' => '', 'phones' => []];
    if (!$psychologistId) return $out;
    try {
        $st = $pdo->prepare("SELECT u.first_name, u.last_name, u.phone, p.inn
                               FROM psychologists p JOIN users u ON u.id = p.user_id
                              WHERE p.id = ? LIMIT 1");
        $st->execute([$psychologistId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $out['name'] = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            $out['inn'] = preg_replace('/\D+/', '', (string)($r['inn'] ?? ''));
            $ph = preg_replace('/\D+/', '', (string)($r['phone'] ?? ''));
            if ($ph !== '') $out['phones'] = [$ph];
        }
    } catch (Exception $e) {}
    return $out;
}

if (session_status() === PHP_SESSION_NONE) session_start();
$userId = $_SESSION['user_id'] ?? null;
$action = $_GET['action'] ?? '';
$body = json_decode(file_get_contents('php://input'), true) ?: [];

// ── Что настроено, а чего не хватает. Секретов не показывает. ─────────────────
if ($action === 'selftest') {
    $sup = sberSupplier($pdo, (string)($_GET['psychologist_id'] ?? ''));
    sberOut([
        'ok' => true,
        'режим' => $isTest ? 'тестовый контур банка' : 'боевой контур банка',
        'адрес_банка' => $base,
        'логин_и_пароль_вписаны' => sberReady($userName, $password),
        'агент_настроен' => !empty($cfg['agent']['inn']) && strpos((string)$cfg['agent']['inn'], 'ВПИШИТЕ') === false,
        'исполнитель_из_профиля' => ['имя' => $sup['name'], 'инн_заполнен' => $sup['inn'] !== ''],
        'чего_не_хватает' => array_values(array_filter([
            sberReady($userName, $password) ? null : 'логин и пароль от банка',
            (!empty($cfg['agent']['inn']) && strpos((string)$cfg['agent']['inn'], 'ВПИШИТЕ') === false) ? null : 'ИНН платформы как агента',
            'подтверждение, что онлайн-касса зарегистрирована',
        ])),
    ]);
}

if (!$pdo) sberOut(['ok' => false, 'error' => 'Нет подключения к БД'], 500);
sberSchema($pdo);

if (!sberReady($userName, $password)) {
    sberOut(['ok' => false, 'error' => 'Эквайринг ещё не настроен: банк не прислал логин и пароль'], 503);
}

if ($action === 'init' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$userId) sberOut(['ok' => false, 'error' => 'Требуется авторизация'], 401);
    $amount = (int)round(((float)($body['amount'] ?? 0)) * 100);          // банк считает в копейках
    if ($amount < 100) sberOut(['ok' => false, 'error' => 'Некорректная сумма'], 400);
    $psychId = (string)($body['psychologist_id'] ?? '');
    $appointment = (string)($body['appointment_id'] ?? '');
    $orderNumber = 'psy-' . date('Ymd') . '-' . bin2hex(random_bytes(5));

    // Комиссия платформы: остальное принадлежит исполнителю
    $commissionPct = 0;
    try {
        $st = $pdo->query("SELECT value FROM settings WHERE key_name = 'platform_commission'");
        $commissionPct = (float)($st ? $st->fetchColumn() : 0);
    } catch (Exception $e) {}
    $commission = (int)round($amount * $commissionPct / 100);

    $sup = sberSupplier($pdo, $psychId);
    if ($sup['inn'] === '') sberOut(['ok' => false, 'error' => 'У специалиста не заполнен ИНН — чек выписать не на кого'], 400);

    $params = [
        'orderNumber' => $orderNumber,
        'amount' => $amount,
        'returnUrl' => (string)($cfg['return_url'] ?? ''),
        'failUrl' => (string)($cfg['fail_url'] ?? ''),
        'description' => 'Консультация психолога',
        // Чек: платформа — агент, услугу оказывает самозанятый
        'orderBundle' => json_encode([
            'cartItems' => ['items' => [[
                'positionId' => 1,
                'name' => 'Консультация психолога',
                'quantity' => ['value' => 1, 'measure' => 'шт'],
                'itemAmount' => $amount,
                'itemCode' => 'consultation',
                'tax' => ['taxType' => (int)($cfg['vat'] ?? 0)],
                'agentInterface' => ['agentInfo' => ['type' => (string)($cfg['agent']['type'] ?? 'another')]],
                'supplierInfo' => [
                    'name' => $sup['name'],
                    'inn' => $sup['inn'],
                    'phones' => $sup['phones'],
                ],
            ]]],
        ], JSON_UNESCAPED_UNICODE),
    ];

    $res = sberCall($pdo, $base, $userName, $password, 'register', $params, $orderNumber);
    if (!empty($res['errorCode']) && (string)$res['errorCode'] !== '0') {
        sberOut(['ok' => false, 'error' => (string)($res['errorMessage'] ?? 'Банк отказал в создании заказа')], 502);
    }
    try {
        $pdo->prepare("INSERT INTO sber_orders (order_number, bank_order_id, client_id, psychologist_id,
                                                appointment_id, amount_kop, commission_kop, status, form_url, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, 'created', ?, NOW())")
            ->execute([$orderNumber, (string)($res['orderId'] ?? ''), $userId, $psychId ?: null,
                       $appointment ?: null, $amount, $commission, (string)($res['formUrl'] ?? '')]);
    } catch (Exception $e) {}
    sberOut(['ok' => true, 'order_number' => $orderNumber, 'formUrl' => (string)($res['formUrl'] ?? '')]);
}

if ($action === 'status') {
    if (!$userId) sberOut(['ok' => false, 'error' => 'Требуется авторизация'], 401);
    $orderNumber = (string)($_GET['order_number'] ?? '');
    if ($orderNumber === '') sberOut(['ok' => false, 'error' => 'Не указан заказ'], 400);
    $st = $pdo->prepare("SELECT * FROM sber_orders WHERE order_number = ? AND client_id = ? LIMIT 1");
    $st->execute([$orderNumber, $userId]);
    $order = $st->fetch(PDO::FETCH_ASSOC);
    if (!$order) sberOut(['ok' => false, 'error' => 'Заказ не найден'], 404);

    $res = sberCall($pdo, $base, $userName, $password, 'getOrderStatusExtended',
                    ['orderId' => $order['bank_order_id']], $orderNumber);
    // 2 — «полная авторизация суммы заказа», то есть деньги списаны
    $paid = ((int)($res['orderStatus'] ?? -1)) === 2;
    if ($paid && $order['status'] !== 'paid') {
        try {
            $pdo->prepare("UPDATE sber_orders SET status = 'paid', paid_at = NOW() WHERE id = ?")
                ->execute([$order['id']]);
        } catch (Exception $e) {}
    }
    sberOut(['ok' => true, 'paid' => $paid, 'bank_status' => $res['orderStatus'] ?? null]);
}

if ($action === 'return' || $action === 'fail') {
    // Человека возвращаем на понятную страницу, а не в JSON
    header('Content-Type: text/html; charset=utf-8');
    $to = $action === 'return' ? '/payment-success.html' : '/payment-info.html';
    header('Location: ' . $to);
    exit;
}

sberOut(['ok' => false, 'error' => 'Неизвестное действие'], 400);
