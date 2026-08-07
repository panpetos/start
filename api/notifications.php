<?php
/**
 * notifications.php — шаблоны сообщений и подключение каналов уведомлений.
 *
 * Этап 1 (этот файл): хранение и редактирование ШАБЛОНОВ всех уведомлений
 * (админ может править тексты) + сохранение настроек каналов (Telegram/MAX/Email)
 * в конфиг ВНЕ git. Сама рассылка (движок) — отдельный следующий этап.
 *
 * Доступ: только администратор (по сессии, role=admin).
 *
 * Действия:
 *   GET  ?action=templates        — все шаблоны (с досевом дефолтов) + метаданные
 *   GET  ?action=placeholders     — справочник динамических переменных
 *   GET  ?action=channels-status  — какие каналы настроены (без секретов)
 *   POST ?action=template-save    {code, title, body, enabled}
 *   POST ?action=template-reset   {code}                 — вернуть текст по умолчанию
 *   POST ?action=channels-save    {telegram_token, max_token, telegram_proxy, max_proxy, email_method, smtp_*}
 *
 * Задача #40 — тестовая отправка в уже подключённые боты (движок — notify_lib.php):
 *   GET  ?action=telegram-updates / max-updates   — недавние /start от бота (chat_id для привязки)
 *   POST ?action=telegram-link / max-link  {chat_id, label}   — привязать чат к текущему админу
 *   POST ?action=telegram-test / max-test  {chat_id?, text?}  — тестовое сообщение (по умолчанию — в свой привязанный чат)
 *   GET  ?action=my-links          — какие каналы привязаны у текущего админа
 *   POST ?action=unlink   {channel}
 *
 * Задача #40 (доработка) — диагностика связи с ботом, отдельно от «Найти чаты»/«Тест отправки»,
 * чтобы отличить сетевую блокировку хостинга от неверного токена (админ сообщил, что Telegram
 * не работает, предположительно из-за санкций/блокировки на сервере в РФ):
 *   GET  ?action=telegram-diag / max-diag   — { verdict: 'ok'|'transport'|'api_error', message }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/notify_lib.php';
if (!function_exists('getDB') && !function_exists('getDbConnection') && !function_exists('getPDO')) {
    require_once __DIR__ . '/db.php';
}
$pdo = function_exists('getDB') ? getDB()
     : (function_exists('getDbConnection') ? getDbConnection()
     : (function_exists('getPDO') ? getPDO() : null));
if (!$pdo) { http_response_code(500); echo json_encode(['error' => 'Нет подключения к БД']); exit; }

if (session_status() === PHP_SESSION_NONE) session_start();
$uid = $_SESSION['user_id'] ?? null;
$isAdmin = false;
if ($uid) {
    try { $st = $pdo->prepare("SELECT role FROM users WHERE id=? LIMIT 1"); $st->execute([$uid]); $isAdmin = ((string)$st->fetchColumn() === 'admin'); } catch (Exception $e) {}
}

function out($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
if (!$isAdmin) out(['error' => 'Доступ только для администратора'], 403);

$action = $_GET['action'] ?? '';
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

// ── Справочник динамических переменных ────────────────────────────────────────────
function placeholders_catalog() {
    return [
        ['{{user_name}}',       'Имя получателя (клиента или психолога)'],
        ['{{client_name}}',     'Имя клиента'],
        ['{{psy_name}}',        'Имя психолога'],
        ['{{date}}',            'Дата сессии (05.08.2026)'],
        ['{{time}}',            'Время сессии (14:00)'],
        ['{{datetime}}',        'Дата и время сессии'],
        ['{{duration}}',        'Длительность сессии (мин)'],
        ['{{format}}',          'Формат (видео / аудио / чат)'],
        ['{{price}}',           'Стоимость сессии, ₽'],
        ['{{amount}}',          'Сумма платежа, ₽'],
        ['{{join_link}}',       'Ссылка на вход в сессию'],
        ['{{booking_id}}',      'Номер записи'],
        ['{{review_link}}',     'Ссылка, чтобы оставить отзыв'],
        ['{{reset_link}}',      'Ссылка для сброса пароля'],
        ['{{reject_reason}}',   'Причина отклонения (модерация)'],
        ['{{sessions_left}}',   'Остаток сеансов в пакете'],
        ['{{earnings}}',        'Заработок за период, ₽'],
        ['{{period}}',          'Период (неделя/месяц)'],
        ['{{support_reply}}',   'Текст ответа поддержки'],
        ['{{message_preview}}', 'Превью нового сообщения'],
        ['{{site_name}}',       'psytalk.pro'],
        ['{{site_url}}',        'https://psytalk.pro'],
    ];
}

// ── Дефолтные шаблоны (код => группа, тип, заголовок, текст) ───────────────────────
function default_templates() {
    return [
        // ——— КЛИЕНТ ———
        'client.welcome'        => ['client','info',        'Добро пожаловать на {{site_name}}', 'Здравствуйте, {{client_name}}! Вы зарегистрировались на {{site_name}}. Теперь вы можете подобрать психолога и записаться на сессию.'],
        'client.password_reset' => ['client','transactional','Сброс пароля',                    'Вы запросили сброс пароля. Перейдите по ссылке, чтобы задать новый: {{reset_link}}. Если это были не вы — просто проигнорируйте письмо.'],
        'booking.created'       => ['client','transactional','Запись создана — ожидает оплаты', '{{client_name}}, ваша запись к {{psy_name}} на {{datetime}} создана. Осталось оплатить, чтобы подтвердить сессию.'],
        'payment.success'       => ['client','transactional','Оплата прошла — сессия подтверждена','{{client_name}}, оплата {{amount}} ₽ получена. Сессия с {{psy_name}} подтверждена на {{datetime}}. Ссылка на вход придёт перед началом.'],
        'payment.failed'        => ['client','transactional','Оплата не прошла',                '{{client_name}}, оплата по записи к {{psy_name}} на {{datetime}} не прошла. Попробуйте ещё раз, чтобы подтвердить сессию.'],
        'booking.reminder_24h'  => ['client','reminder',     'Напоминание: сессия завтра',      '{{client_name}}, напоминаем: завтра в {{time}} у вас сессия с {{psy_name}}. Формат: {{format}}.'],
        'booking.reminder_2h'   => ['client','reminder',     'Сессия через 2 часа',             '{{client_name}}, через 2 часа ({{time}}) начнётся сессия с {{psy_name}}.'],
        'booking.reminder_15m'  => ['client','reminder',     'Сессия через 15 минут',           '{{client_name}}, сессия с {{psy_name}} начнётся через 15 минут. Войти: {{join_link}}'],
        'booking.rescheduled'   => ['client','transactional','Сессия перенесена',               '{{client_name}}, ваша сессия с {{psy_name}} перенесена на {{datetime}}.'],
        'booking.cancelled'     => ['client','transactional','Сессия отменена',                 '{{client_name}}, сессия с {{psy_name}} на {{datetime}} отменена. Если оплата была — возврат придёт отдельным уведомлением.'],
        'booking.replacement'   => ['client','transactional','Замена психолога',                '{{client_name}}, по вашей сессии на {{datetime}} назначен другой специалист — {{psy_name}}. Все детали сохранены.'],
        'chat.new_message'      => ['client','info',         'Новое сообщение от психолога',    '{{client_name}}, у вас новое сообщение от {{psy_name}}: «{{message_preview}}»'],
        'session.finished'      => ['client','info',         'Сессия завершена',                '{{client_name}}, спасибо за сессию с {{psy_name}}. Хорошего дня!'],
        'review.request'        => ['client','info',         'Оставьте отзыв о сессии',         '{{client_name}}, как прошла сессия с {{psy_name}}? Поделитесь впечатлением: {{review_link}}'],
        'refund.processed'      => ['client','transactional','Возврат оформлен',                '{{client_name}}, возврат {{amount}} ₽ по вашей записи оформлен. Деньги вернутся на карту в течение нескольких дней.'],
        'package.low'           => ['client','info',         'В пакете остался 1 сеанс',        '{{client_name}}, в вашем пакете остался {{sessions_left}} сеанс. Можно продлить в личном кабинете.'],
        'package.expired'       => ['client','info',         'Пакет сеансов закончился',        '{{client_name}}, ваш пакет сеансов закончился. Оформить новый можно в личном кабинете.'],

        // ——— ПСИХОЛОГ ———
        'psy.registration_received' => ['psy','info',        'Заявка принята — на модерации',   '{{psy_name}}, ваша анкета отправлена на модерацию. Мы сообщим о решении.'],
        'psy.approved'          => ['psy','transactional',   'Анкета одобрена',                 '{{psy_name}}, ваша анкета одобрена! Профиль опубликован, клиенты уже могут записываться.'],
        'psy.rejected'          => ['psy','transactional',   'Анкета отклонена',                '{{psy_name}}, анкета пока отклонена. Причина: {{reject_reason}}. Исправьте и отправьте снова.'],
        'psy.booking_new'       => ['psy','transactional',   'Новая запись',                    '{{psy_name}}, к вам записался клиент {{client_name}} на {{datetime}}. Формат: {{format}}.'],
        'psy.payment_confirmed' => ['psy','info',            'Оплата клиента подтверждена',     '{{psy_name}}, клиент {{client_name}} оплатил сессию на {{datetime}}.'],
        'psy.booking_reminder_2h'=> ['psy','reminder',       'Сессия через 2 часа',             '{{psy_name}}, через 2 часа ({{time}}) сессия с {{client_name}}.'],
        'psy.booking_reminder_15m'=>['psy','reminder',       'Сессия через 15 минут',           '{{psy_name}}, сессия с {{client_name}} через 15 минут. Войти: {{join_link}}'],
        'psy.booking_cancelled' => ['psy','transactional',   'Клиент отменил/перенёс',          '{{psy_name}}, клиент {{client_name}} отменил или перенёс сессию на {{datetime}}.'],
        'psy.replacement_request'=>['psy','transactional',   'Просьба о замене',                '{{psy_name}}, требуется подмена по сессии {{datetime}}. Сможете провести? Подтвердите в кабинете.'],
        'psy.chat_new_message'  => ['psy','info',            'Новое сообщение от клиента',      '{{psy_name}}, новое сообщение от {{client_name}}: «{{message_preview}}»'],
        'psy.review_new'        => ['psy','info',            'Новый отзыв',                     '{{psy_name}}, клиент оставил вам новый отзыв. Посмотреть можно в кабинете.'],
        'psy.earnings_summary'  => ['psy','info',            'Сводка по заработку',             '{{psy_name}}, за {{period}} ваш заработок составил {{earnings}} ₽ (после комиссии платформы).'],
        'psy.payout'            => ['psy','transactional',   'Выплата начислена',               '{{psy_name}}, вам начислена выплата {{amount}} ₽.'],
        'psy.schedule_empty'    => ['psy','reminder',        'Пустое расписание',               '{{psy_name}}, у вас не осталось свободных слотов в расписании. Добавьте время, чтобы принимать записи.'],
        'psy.supervision_reminder'=>['psy','reminder',       'Напоминание о супервизии',        '{{psy_name}}, напоминаем о супервизии {{datetime}}.'],
        'psy.support_reply'     => ['psy','info',            'Ответ поддержки',                 '{{psy_name}}, поддержка ответила: «{{support_reply}}»'],

        // ——— АДМИН ———
        'admin.psy_moderation'  => ['admin','info',          'Новая анкета на модерацию',       'Новая анкета психолога {{psy_name}} ожидает модерации.'],
        'admin.support_new'     => ['admin','info',          'Новое обращение в поддержку',     'Новое обращение в поддержку от {{user_name}}: «{{message_preview}}»'],
        'admin.payment_issue'   => ['admin','info',          'Проблема с оплатой',              'Обнаружена проблема с оплатой по записи {{booking_id}} ({{amount}} ₽).'],
        'admin.replacement_needed'=>['admin','info',         'Нужна замена психолога',          'По сессии {{datetime}} требуется замена психолога — некому провести.'],
        'admin.daily_digest'    => ['admin','info',          'Сводка за день',                  'Итоги за день на {{site_name}}: новые записи, оплаты и регистрации — в админке.'],
    ];
}

// Гарантируем наличие таблицы и досеваем недостающие дефолты
function ensure_templates(PDO $pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS notification_templates (
            code VARCHAR(64) PRIMARY KEY,
            title VARCHAR(255) NULL,
            body TEXT NULL,
            enabled TINYINT NOT NULL DEFAULT 1,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) { return; }
    $existing = [];
    try { $existing = $pdo->query("SELECT code FROM notification_templates")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) {}
    $existing = array_flip($existing);
    $ins = $pdo->prepare("INSERT INTO notification_templates (code, title, body, enabled) VALUES (?, ?, ?, 1)");
    foreach (default_templates() as $code => $d) {
        if (!isset($existing[$code])) {
            try { $ins->execute([$code, $d[2], $d[3]]); } catch (Exception $e) {}
        }
    }
}

// ── Конфиг каналов (секреты вне git) ──────────────────────────────────────────────
$cfgFile = __DIR__ . '/notifications_config.php';
function load_channels($cfgFile) {
    $c = file_exists($cfgFile) ? (include $cfgFile) : [];
    return is_array($c) ? $c : [];
}

// ── Действия ──────────────────────────────────────────────────────────────────────
if ($action === 'placeholders') {
    out(['ok' => true, 'data' => placeholders_catalog()]);
}

if ($action === 'templates') {
    ensure_templates($pdo);
    $defs = default_templates();
    $rows = [];
    try { $rows = $pdo->query("SELECT * FROM notification_templates")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}
    $byCode = [];
    foreach ($rows as $r) $byCode[$r['code']] = $r;
    $groupLabels = ['client' => 'Клиент', 'psy' => 'Психолог', 'admin' => 'Админ'];
    $typeLabels  = ['transactional' => 'обязательное', 'reminder' => 'напоминание', 'info' => 'инфо'];
    $out = [];
    foreach ($defs as $code => $d) {
        $row = $byCode[$code] ?? ['title' => $d[2], 'body' => $d[3], 'enabled' => 1];
        $out[] = [
            'code' => $code,
            'group' => $d[0], 'group_label' => $groupLabels[$d[0]] ?? $d[0],
            'type' => $d[1], 'type_label' => $typeLabels[$d[1]] ?? $d[1],
            'title' => $row['title'], 'body' => $row['body'],
            'enabled' => (int)$row['enabled'],
            'is_mandatory' => $d[1] === 'transactional',
        ];
    }
    out(['ok' => true, 'data' => $out, 'placeholders' => placeholders_catalog()]);
}

if ($action === 'template-save') {
    ensure_templates($pdo);
    $code = trim((string)($body['code'] ?? ''));
    if ($code === '' || !isset(default_templates()[$code])) out(['error' => 'Неизвестный код шаблона'], 400);
    $title = trim((string)($body['title'] ?? ''));
    $text = (string)($body['body'] ?? '');
    if (trim($text) === '') out(['error' => 'Текст сообщения не может быть пустым'], 400);
    // Обязательные уведомления нельзя выключать
    $mandatory = default_templates()[$code][1] === 'transactional';
    $enabled = $mandatory ? 1 : (!empty($body['enabled']) ? 1 : 0);
    try {
        $st = $pdo->prepare("INSERT INTO notification_templates (code, title, body, enabled, updated_at) VALUES (?, ?, ?, ?, NOW())
                             ON DUPLICATE KEY UPDATE title=VALUES(title), body=VALUES(body), enabled=VALUES(enabled), updated_at=NOW()");
        $st->execute([$code, $title, $text, $enabled]);
        out(['ok' => true]);
    } catch (Exception $e) { out(['error' => 'Не удалось сохранить'], 500); }
}

if ($action === 'template-reset') {
    $code = trim((string)($body['code'] ?? ''));
    $defs = default_templates();
    if (!isset($defs[$code])) out(['error' => 'Неизвестный код шаблона'], 400);
    try {
        $st = $pdo->prepare("INSERT INTO notification_templates (code, title, body, enabled, updated_at) VALUES (?, ?, ?, 1, NOW())
                             ON DUPLICATE KEY UPDATE title=VALUES(title), body=VALUES(body), enabled=1, updated_at=NOW()");
        $st->execute([$code, $defs[$code][2], $defs[$code][3]]);
        out(['ok' => true, 'title' => $defs[$code][2], 'body' => $defs[$code][3]]);
    } catch (Exception $e) { out(['error' => 'Не удалось сбросить'], 500); }
}

if ($action === 'channels-status') {
    $c = load_channels($cfgFile);
    out(['ok' => true, 'data' => [
        'telegram_configured' => !empty($c['telegram_token']),
        'max_configured'      => !empty($c['max_token']),
        'telegram_proxy'      => $c['telegram_proxy'] ?? '',
        'max_proxy'           => $c['max_proxy'] ?? '',
        'email_method'        => $c['email_method'] ?? '',
        'email_configured'    => (function ($c) {
            $m = $c['email_method'] ?? '';
            if ($m === 'smtp')   return !empty($c['smtp_host']) && !empty($c['smtp_pass']);
            if ($m === 'resend') return !empty($c['resend_key']);
            if ($m === 'mail')   return true;
            return false;
        })($c),
        'smtp_host'           => $c['smtp_host'] ?? '',
        'smtp_port'           => $c['smtp_port'] ?? '',
        'smtp_user'           => $c['smtp_user'] ?? '',
        'smtp_from'           => $c['smtp_from'] ?? '',
        'resend_configured'   => !empty($c['resend_key']),
        'resend_from'         => $c['resend_from'] ?? '',
        'resend_replyto'      => $c['resend_replyto'] ?? '',
    ]]);
}

if ($action === 'channels-save') {
    $c = load_channels($cfgFile);
    // Секретные поля перезаписываем только если пришло непустое значение (иначе сохраняем прежнее)
    foreach (['telegram_token', 'max_token', 'smtp_pass', 'resend_key'] as $secret) {
        $v = trim((string)($body[$secret] ?? ''));
        if ($v !== '') $c[$secret] = $v;
    }
    // Несекретные поля — перезаписываем как пришли
    foreach (['email_method', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_from', 'resend_from', 'resend_replyto', 'telegram_proxy', 'max_proxy'] as $field) {
        if (array_key_exists($field, $body)) $c[$field] = trim((string)$body[$field]);
    }
    $php = "<?php\n// Конфиг каналов уведомлений — НЕ в git. Заполняется из админки.\nreturn " . var_export($c, true) . ";\n";
    if (@file_put_contents($cfgFile, $php) === false) out(['error' => 'Не удалось записать notifications_config.php (проверьте права на /api)'], 500);
    @chmod($cfgFile, 0640);
    out(['ok' => true]);
}

// ── Задача #40: тестовая отправка в уже подключённые боты ─────────────────────────
if ($action === 'telegram-updates' || $action === 'max-updates') {
    $ch = load_channels($cfgFile);
    $token = $action === 'telegram-updates' ? ($ch['telegram_token'] ?? '') : ($ch['max_token'] ?? '');
    $proxy = $action === 'telegram-updates' ? ($ch['telegram_proxy'] ?? null) : ($ch['max_proxy'] ?? null);
    if (!$token) out(['error' => 'Токен бота не настроен — сначала сохраните его в разделе «Каналы доставки»'], 400);
    $r = $action === 'telegram-updates' ? notify_tg_recent_chats($token, $proxy) : notify_max_recent_chats($token, $proxy);
    if (empty($r['ok'])) {
        $hint = !empty($r['transport_error']) ? ' (похоже на сетевую блокировку — см. «🔍 Диагностика соединения»)' : '';
        out(['error' => ($r['description'] ?? $r['error'] ?? 'Не удалось получить обновления от бота') . $hint], 502);
    }
    out(['ok' => true, 'data' => $r['data'] ?? []]);
}

if ($action === 'telegram-diag' || $action === 'max-diag') {
    $ch = load_channels($cfgFile);
    $token = $action === 'telegram-diag' ? ($ch['telegram_token'] ?? '') : ($ch['max_token'] ?? '');
    $proxy = $action === 'telegram-diag' ? ($ch['telegram_proxy'] ?? null) : ($ch['max_proxy'] ?? null);
    if (!$token) out(['error' => 'Токен бота не настроен — сначала сохраните его в разделе «Каналы доставки»'], 400);
    $d = $action === 'telegram-diag' ? notify_tg_diag($token, $proxy) : notify_max_diag($token, $proxy);
    out(['ok' => true, 'verdict' => $d['verdict'], 'message' => $d['message']]);
}

if ($action === 'telegram-link' || $action === 'max-link') {
    notify_ensure_links_table($pdo);
    $chatId = trim((string)($body['chat_id'] ?? ''));
    $label = trim((string)($body['label'] ?? ''));
    if ($chatId === '') out(['error' => 'chat_id обязателен'], 400);
    $channel = $action === 'telegram-link' ? 'telegram' : 'max';
    try {
        $pdo->prepare("INSERT INTO notify_bot_links (user_id, channel, chat_id, label) VALUES (?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE chat_id = VALUES(chat_id), label = VALUES(label)")
            ->execute([$uid, $channel, $chatId, $label ?: null]);
        out(['ok' => true]);
    } catch (Exception $e) { out(['error' => 'Не удалось привязать чат'], 500); }
}

if ($action === 'my-links') {
    notify_ensure_links_table($pdo);
    try {
        $st = $pdo->prepare("SELECT channel, chat_id, label, created_at FROM notify_bot_links WHERE user_id = ?");
        $st->execute([$uid]);
        out(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { out(['ok' => true, 'data' => []]); }
}

if ($action === 'unlink') {
    notify_ensure_links_table($pdo);
    $channel = trim((string)($body['channel'] ?? ''));
    if (!in_array($channel, ['telegram', 'max'], true)) out(['error' => 'Неизвестный канал'], 400);
    try { $pdo->prepare("DELETE FROM notify_bot_links WHERE user_id = ? AND channel = ?")->execute([$uid, $channel]); out(['ok' => true]); }
    catch (Exception $e) { out(['error' => 'Не удалось отвязать'], 500); }
}

if ($action === 'telegram-test' || $action === 'max-test') {
    $channel = $action === 'telegram-test' ? 'telegram' : 'max';
    $ch = load_channels($cfgFile);
    $token = $channel === 'telegram' ? ($ch['telegram_token'] ?? '') : ($ch['max_token'] ?? '');
    $proxy = $channel === 'telegram' ? ($ch['telegram_proxy'] ?? null) : ($ch['max_proxy'] ?? null);
    if (!$token) out(['error' => 'Токен бота не настроен — сначала сохраните его в разделе «Каналы доставки»'], 400);
    notify_ensure_links_table($pdo);
    $chatId = trim((string)($body['chat_id'] ?? ''));
    if ($chatId === '') {
        try {
            $st = $pdo->prepare("SELECT chat_id FROM notify_bot_links WHERE user_id = ? AND channel = ? LIMIT 1");
            $st->execute([$uid, $channel]);
            $chatId = (string)$st->fetchColumn();
        } catch (Exception $e) {}
    }
    if ($chatId === '') out(['error' => 'Нет привязанного чата — сначала найдите свой чат через «Найти мои чаты» и привяжите его'], 400);
    $text = trim((string)($body['text'] ?? '')) ?: 'Тестовое сообщение с psytalk.pro ✅ Если вы это читаете — бот настроен верно.';
    $r = $channel === 'telegram' ? notify_tg_send($token, $chatId, $text, $proxy) : notify_max_send($token, $chatId, $text, $proxy);
    if (empty($r['ok'])) {
        $hint = !empty($r['transport_error']) ? ' (похоже на сетевую блокировку — см. «🔍 Диагностика соединения»)' : '';
        out(['error' => ($r['description'] ?? $r['error'] ?? 'Бот вернул ошибку') . $hint], 502);
    }
    out(['ok' => true]);
}

out(['error' => 'Неизвестное действие'], 400);
