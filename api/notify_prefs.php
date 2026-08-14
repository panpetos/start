<?php
/**
 * notify_prefs.php — куда присылать уведомления: личные настройки КАЖДОГО пользователя.
 *
 * До этого каналы (Telegram/MAX/почта) существовали только для администратора: токены
 * жили в админке, привязка чата делалась вручную по chat_id из свежих /start. Обычный
 * клиент или психолог выбрать способ доставки не мог вообще. Здесь тот же движок
 * (notify_lib.php) открыт всем — но с самообслуживанием: человек получает короткий код,
 * пишет его боту, сервер сам находит нужный чат и привязывает. Токены при этом никуда
 * не отдаются: наружу уходит только «настроен канал или нет».
 *
 * GET  ?action=get            — мои настройки + какие каналы вообще доступны на сервере
 * POST ?action=save           {email_enabled, email_to, telegram_enabled, max_enabled, push_enabled, events}
 * GET  ?action=link-code&channel=telegram|max   — код для привязки (живёт 15 минут)
 * POST ?action=link-check     {channel}         — найти свежее сообщение с кодом и привязать чат
 * POST ?action=unlink         {channel}
 * POST ?action=test           {channel}         — прислать себе проверочное уведомление
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/notify_lib.php';
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

function out($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

// Какие события человек может включать/выключать. Список общий: лишние ему просто не придут.
const NOTIFY_EVENTS = [
    'messages'     => 'Новые сообщения в чатах',
    'appointments' => 'Записи и напоминания о сессиях',
    'payments'     => 'Оплаты и счёта',
    'system'       => 'Важное от платформы',
];

$initError = '';
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS notify_prefs (
        user_id VARCHAR(64) NOT NULL PRIMARY KEY,
        email_enabled TINYINT NOT NULL DEFAULT 1,
        email_to VARCHAR(190) NULL,
        telegram_enabled TINYINT NOT NULL DEFAULT 0,
        max_enabled TINYINT NOT NULL DEFAULT 0,
        push_enabled TINYINT NOT NULL DEFAULT 0,
        events TEXT NULL,
        updated_at DATETIME NOT NULL
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS notify_link_codes (
        user_id VARCHAR(64) NOT NULL,
        channel VARCHAR(16) NOT NULL,
        code VARCHAR(16) NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (user_id, channel)
    ) DEFAULT CHARSET=utf8mb4");
    notify_ensure_links_table($pdo);
    psy_align_collation($pdo, ['notify_prefs', 'notify_link_codes', 'notify_bot_links']);
} catch (Exception $e) {
    $initError = $e->getMessage();
}

$action = $_GET['action'] ?? '';
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];
$cfg = notify_channels_config();

function myEmail($pdo, $userId) {
    try {
        $st = $pdo->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
        $st->execute([$userId]);
        return (string)$st->fetchColumn();
    } catch (Exception $e) { return ''; }
}

function emailConfigured($cfg) {
    $m = $cfg['email_method'] ?? '';
    if ($m === 'resend') return !empty($cfg['resend_key']);
    if ($m === 'smtp')   return !empty($cfg['smtp_host']);
    if ($m === 'mail')   return true;
    return false;
}

function loadPrefs($pdo, $userId, $fallbackEmail) {
    $row = null;
    try {
        $st = $pdo->prepare("SELECT * FROM notify_prefs WHERE user_id = ? LIMIT 1");
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
    $events = [];
    if ($row && $row['events']) $events = json_decode($row['events'], true) ?: [];
    // По умолчанию включено всё, кроме того, что человек снял сам
    $eventFlags = [];
    foreach (array_keys(NOTIFY_EVENTS) as $k) {
        $eventFlags[$k] = array_key_exists($k, $events) ? (bool)$events[$k] : true;
    }
    return [
        'email_enabled'    => $row ? (bool)$row['email_enabled'] : true,
        'email_to'         => $row && $row['email_to'] ? $row['email_to'] : $fallbackEmail,
        'telegram_enabled' => $row ? (bool)$row['telegram_enabled'] : false,
        'max_enabled'      => $row ? (bool)$row['max_enabled'] : false,
        'push_enabled'     => $row ? (bool)$row['push_enabled'] : false,
        'events'           => $eventFlags,
    ];
}

function myLinks($pdo, $userId) {
    $out = [];
    try {
        $st = $pdo->prepare("SELECT channel, chat_id, label FROM notify_bot_links WHERE user_id = ?");
        $st->execute([$userId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['channel']] = $r;
    } catch (Exception $e) {}
    return $out;
}

/**
 * Имя бота на платформе — нужно и чтобы подсказать человеку, куда писать код,
 * и чтобы собрать кликабельную ссылку на чат с ботом (задача #46: раньше имя
 * бота показывалось только для Telegram, и то не как ссылка, а как голый текст).
 * Telegram: t.me/<username>. MAX: max.ru/<username> — тот же принцип, официально
 * задокументирован в MAX Bot API (https://max.ru/<bot>?start=<payload>).
 */
function botUsername($channel, $cfg) {
    if ($channel === 'telegram') {
        if (empty($cfg['telegram_token'])) return '';
        $me = notify_tg_request($cfg['telegram_token'], 'getMe', [], $cfg['telegram_proxy'] ?? null, $cfg['telegram_api_base'] ?? null);
        return !empty($me['ok']) ? ($me['result']['username'] ?? '') : '';
    }
    if (empty($cfg['max_token'])) return '';
    $me = notify_max_request($cfg['max_token'], '/me', 'GET', [], null, $cfg['max_proxy'] ?? null);
    return !empty($me['username']) ? $me['username'] : '';
}

/** Короткий код для привязки: его человек отправляет боту, чтобы мы узнали его чат. */
function issueCode($pdo, $userId, $channel) {
    $code = 'PSY-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    try {
        $st = $pdo->prepare("SELECT 1 FROM notify_link_codes WHERE user_id = ? AND channel = ?");
        $st->execute([$userId, $channel]);
        if ($st->fetchColumn()) {
            $pdo->prepare("UPDATE notify_link_codes SET code = ?, created_at = NOW() WHERE user_id = ? AND channel = ?")
                ->execute([$code, $userId, $channel]);
        } else {
            $pdo->prepare("INSERT INTO notify_link_codes (user_id, channel, code, created_at) VALUES (?, ?, ?, NOW())")
                ->execute([$userId, $channel, $code]);
        }
    } catch (Exception $e) { return null; }
    return $code;
}

try {
    if ($action === 'get') {
        $email = myEmail($pdo, $userId);
        $links = myLinks($pdo, $userId);
        // Имя бота нужно только пока канал ещё не привязан (ссылка «Открыть бота») —
        // уже привязанным не дёргаем лишний раз внешний API при каждой загрузке страницы.
        out([
            'ok' => true,
            'prefs' => loadPrefs($pdo, $userId, $email),
            'links' => $links,
            'account_email' => $email,
            'available' => [
                'email'    => emailConfigured($cfg),
                'email_via' => $cfg['email_method'] ?? '',
                'telegram' => !empty($cfg['telegram_token']),
                'max'      => !empty($cfg['max_token']),
            ],
            'telegram_bot' => empty($links['telegram']) ? botUsername('telegram', $cfg) : '',
            'max_bot'      => empty($links['max']) ? botUsername('max', $cfg) : '',
            'events' => NOTIFY_EVENTS,
            'init_error' => $initError ?: null,
        ]);
    }

    // Что реально уходило именно этому человеку и почему не дошло. Без этого при
    // «включил каналы, а письма нет» остаётся только гадать.
    if ($action === 'my-log') {
        $rows = [];
        try {
            $st = $pdo->prepare("SELECT channel, kind, ok, note, sent_at FROM notify_sent_log
                                 WHERE user_id = ? ORDER BY id DESC LIMIT 20");
            $st->execute([$userId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { /* таблицы может не быть, если проход ещё не запускался */ }
        // last_run сдвигается только когда всё ушло без ошибок, а last_tick — на каждой проверке.
        // Показываем именно проверку: иначе при неудачных отправках кажется, будто рассылка не работает вовсе.
        $lastRun = $lastCheck = null;
        try {
            $st = $pdo->prepare("SELECT key_name, value FROM notify_dispatch_state
                                 WHERE key_name IN ('last_run', 'last_tick')");
            $st->execute();
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if ($r['key_name'] === 'last_run') $lastRun = $r['value'] ?: null;
                else $lastCheck = $r['value'] ?: null;
            }
        } catch (Exception $e) {}
        out(['ok' => true, 'data' => $rows, 'last_run' => $lastRun, 'last_check' => $lastCheck]);
    }

    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($initError) out(['ok' => false, 'error' => 'Настройки недоступны: ' . $initError], 500);
        $emailTo = trim((string)($body['email_to'] ?? ''));
        if ($emailTo !== '' && !filter_var($emailTo, FILTER_VALIDATE_EMAIL)) {
            out(['ok' => false, 'error' => 'Проверьте адрес почты'], 400);
        }
        $events = [];
        $inEvents = is_array($body['events'] ?? null) ? $body['events'] : [];
        foreach (array_keys(NOTIFY_EVENTS) as $k) $events[$k] = !empty($inEvents[$k]);
        $vals = [
            !empty($body['email_enabled']) ? 1 : 0,
            $emailTo !== '' ? $emailTo : null,
            !empty($body['telegram_enabled']) ? 1 : 0,
            !empty($body['max_enabled']) ? 1 : 0,
            !empty($body['push_enabled']) ? 1 : 0,
            json_encode($events, JSON_UNESCAPED_UNICODE),
        ];
        $st = $pdo->prepare("SELECT 1 FROM notify_prefs WHERE user_id = ?");
        $st->execute([$userId]);
        if ($st->fetchColumn()) {
            $pdo->prepare("UPDATE notify_prefs SET email_enabled=?, email_to=?, telegram_enabled=?, max_enabled=?, push_enabled=?, events=?, updated_at=NOW() WHERE user_id=?")
                ->execute(array_merge($vals, [$userId]));
        } else {
            $pdo->prepare("INSERT INTO notify_prefs (user_id, email_enabled, email_to, telegram_enabled, max_enabled, push_enabled, events, updated_at) VALUES (?,?,?,?,?,?,?,NOW())")
                ->execute(array_merge([$userId], $vals));
        }
        out(['ok' => true, 'prefs' => loadPrefs($pdo, $userId, myEmail($pdo, $userId))]);
    }

    if ($action === 'link-code') {
        $channel = ($_GET['channel'] ?? '') === 'max' ? 'max' : 'telegram';
        if ($channel === 'telegram' && empty($cfg['telegram_token'])) out(['ok' => false, 'error' => 'Telegram-бот пока не подключён на платформе'], 400);
        if ($channel === 'max' && empty($cfg['max_token'])) out(['ok' => false, 'error' => 'MAX-бот пока не подключён на платформе'], 400);
        $code = issueCode($pdo, $userId, $channel);
        if (!$code) out(['ok' => false, 'error' => 'Не удалось создать код'], 500);
        // Имя бота — чтобы собрать кликабельную ссылку прямо в чат с ботом (задача #46),
        // с кодом в параметре ?start= — бот получит его как обычное сообщение и сам себя привяжет.
        $bot = botUsername($channel, $cfg);
        out(['ok' => true, 'code' => $code, 'bot' => $bot, 'ttl_min' => 15]);
    }

    if ($action === 'link-check' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $channel = ($body['channel'] ?? '') === 'max' ? 'max' : 'telegram';
        $st = $pdo->prepare("SELECT code, created_at FROM notify_link_codes WHERE user_id = ? AND channel = ? LIMIT 1");
        $st->execute([$userId, $channel]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) out(['ok' => false, 'error' => 'Сначала получите код'], 400);
        if (strtotime($row['created_at']) < time() - 15 * 60) out(['ok' => false, 'error' => 'Код устарел — получите новый'], 400);
        $code = $row['code'];

        $recent = $channel === 'telegram'
            ? notify_tg_recent_chats($cfg['telegram_token'] ?? '', $cfg['telegram_proxy'] ?? null, $cfg['telegram_api_base'] ?? null)
            : notify_max_recent_chats($cfg['max_token'] ?? '', $cfg['max_proxy'] ?? null);
        if (empty($recent['ok'])) {
            out(['ok' => false, 'error' => 'Не удалось получить сообщения бота: ' . ($recent['description'] ?? $recent['error'] ?? 'неизвестно')], 502);
        }
        $found = null;
        foreach ($recent['data'] as $chat) {
            if (stripos((string)$chat['text'], $code) !== false) { $found = $chat; break; }
        }
        if (!$found) out(['ok' => false, 'error' => 'Сообщение с кодом не найдено. Отправьте боту ' . $code . ' и нажмите «Проверить» ещё раз.'], 404);

        $ins = $pdo->prepare("SELECT 1 FROM notify_bot_links WHERE user_id = ? AND channel = ?");
        $ins->execute([$userId, $channel]);
        if ($ins->fetchColumn()) {
            $pdo->prepare("UPDATE notify_bot_links SET chat_id = ?, label = ? WHERE user_id = ? AND channel = ?")
                ->execute([$found['chat_id'], $found['name'] ?? null, $userId, $channel]);
        } else {
            $pdo->prepare("INSERT INTO notify_bot_links (user_id, channel, chat_id, label) VALUES (?,?,?,?)")
                ->execute([$userId, $channel, $found['chat_id'], $found['name'] ?? null]);
        }
        // привязали — сразу включаем канал, иначе человек ждёт уведомлений, а галочка выключена
        $col = $channel === 'telegram' ? 'telegram_enabled' : 'max_enabled';
        $has = $pdo->prepare("SELECT 1 FROM notify_prefs WHERE user_id = ?");
        $has->execute([$userId]);
        if ($has->fetchColumn()) {
            $pdo->prepare("UPDATE notify_prefs SET $col = 1, updated_at = NOW() WHERE user_id = ?")->execute([$userId]);
        } else {
            $pdo->prepare("INSERT INTO notify_prefs (user_id, email_enabled, $col, updated_at) VALUES (?, 1, 1, NOW())")->execute([$userId]);
        }
        $pdo->prepare("DELETE FROM notify_link_codes WHERE user_id = ? AND channel = ?")->execute([$userId, $channel]);
        out(['ok' => true, 'chat_id' => $found['chat_id'], 'label' => $found['name'] ?? '']);
    }

    if ($action === 'unlink' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $channel = ($body['channel'] ?? '') === 'max' ? 'max' : 'telegram';
        $pdo->prepare("DELETE FROM notify_bot_links WHERE user_id = ? AND channel = ?")->execute([$userId, $channel]);
        $col = $channel === 'telegram' ? 'telegram_enabled' : 'max_enabled';
        try { $pdo->prepare("UPDATE notify_prefs SET $col = 0, updated_at = NOW() WHERE user_id = ?")->execute([$userId]); } catch (Exception $e) {}
        out(['ok' => true]);
    }

    // Проверочная отправка: без неё человек не знает, дойдёт ли до него уведомление
    if ($action === 'test' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $channel = (string)($body['channel'] ?? '');
        $prefs = loadPrefs($pdo, $userId, myEmail($pdo, $userId));
        $text = 'Проверка уведомлений psytalk.pro — если вы это читаете, канал работает.';
        if ($channel === 'email') {
            if (!emailConfigured($cfg)) out(['ok' => false, 'error' => 'Отправка почты на платформе не настроена'], 400);
            $to = trim((string)($body['email_to'] ?? '')) ?: $prefs['email_to'];
            $r = notify_send_email($cfg, $to, 'Проверка уведомлений psytalk.pro', $text);
            if (empty($r['ok'])) out(['ok' => false, 'error' => $r['error'] ?? 'не удалось отправить'], 502);
            out(['ok' => true, 'via' => $r['via'] ?? 'email', 'note' => $r['note'] ?? null, 'to' => $to]);
        }
        if ($channel === 'telegram' || $channel === 'max') {
            $links = myLinks($pdo, $userId);
            if (empty($links[$channel])) out(['ok' => false, 'error' => 'Сначала привяжите бота'], 400);
            $chatId = $links[$channel]['chat_id'];
            $r = $channel === 'telegram'
                ? notify_tg_send($cfg['telegram_token'] ?? '', $chatId, $text, $cfg['telegram_proxy'] ?? null, $cfg['telegram_api_base'] ?? null)
                : notify_max_send($cfg['max_token'] ?? '', $chatId, $text, $cfg['max_proxy'] ?? null);
            if (empty($r['ok'])) out(['ok' => false, 'error' => $r['description'] ?? $r['error'] ?? 'не удалось отправить'], 502);
            out(['ok' => true, 'via' => $channel]);
        }
        out(['ok' => false, 'error' => 'Неизвестный канал'], 400);
    }

    out(['ok' => false, 'error' => 'Неизвестное действие'], 400);
} catch (Exception $e) {
    out(['ok' => false, 'error' => $e->getMessage()], 500);
}
