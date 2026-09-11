<?php
/**
 * notify_lib.php — движок отправки в Telegram / MAX (задача #40, продолжение
 * стадии 1 из notifications.php: та часть хранила только шаблоны и токены,
 * реальной отправки не было).
 *
 * Только функции, подключается через require_once из других API-файлов —
 * своего HTTP-роутинга нет. Конфиг токенов — notifications_config.php (вне git).
 *
 * Привязка админа к его чату в боте — таблица notify_bot_links (user_id,
 * channel, chat_id), заполняется через notifications.php (action=telegram-link
 * / max-link) после того, как админ находит свой chat_id в списке недавних
 * /start через action=telegram-updates / max-updates.
 */

function notify_channels_config() {
    $f = __DIR__ . '/notifications_config.php';
    $c = file_exists($f) ? (include $f) : [];
    if (!is_array($c)) return [];
    // Токены копипастятся вручную в UI — обрезаем случайные пробелы/переносы, иначе Telegram отвечает 401 Unauthorized.
    foreach (['telegram_token', 'max_token', 'telegram_proxy', 'max_proxy', 'telegram_api_base'] as $k) {
        if (isset($c[$k])) $c[$k] = trim((string)$c[$k]);
    }
    return $c;
}

function notify_ensure_links_table(PDO $pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS notify_bot_links (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(64) NOT NULL,
            channel VARCHAR(16) NOT NULL,
            chat_id VARCHAR(64) NOT NULL,
            label VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_channel (user_id, channel)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}
}

// ── Telegram Bot API (https://core.telegram.org/bots/api) ─────────────────────────
/**
 * $proxy — необязательный прокси для curl (например "socks5h://host:1080" или
 * "http://user:pass@host:3128") — на случай если у хостинга проблемы с прямым
 * доступом к api.telegram.org (задача #40: подозрение на блокировку/санкции).
 * $apiBase — необязательная замена хоста "https://api.telegram.org" (например
 * бесплатный Cloudflare Worker-реверс-прокси до Bot API) — для хостингов под
 * санкциями это проще в настройке, чем покупка/аренда SOCKS/HTTP-прокси-сервера.
 * Транспортные ошибки (не достучались до Telegram) и API-ошибки Telegram (плохой
 * токен и т.п.) возвращаются с явной меткой transport_error, чтобы не путать их —
 * раньше обе выглядели одинаково как {ok:false, error:"..."}.
 */
function notify_tg_request($token, $method, $params = [], $proxy = null, $apiBase = null) {
    $base = rtrim((string)($apiBase ?: 'https://api.telegram.org'), '/');
    $url = $base . '/bot' . $token . '/' . $method;
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($params),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => true,
    ];
    if ($proxy) $opts[CURLOPT_PROXY] = $proxy;
    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    curl_close($ch);
    if ($errno) {
        // Транспортный уровень не ответил вовсе — таймаут/DNS/SSL/сброс соединения.
        // Именно такая картина ожидается при сетевой блокировке (DPI/файрвол хостера), не при неверном токене.
        return ['ok' => false, 'transport_error' => true, 'error' => $err, 'curl_errno' => $errno, 'elapsed_s' => round($totalTime, 2)];
    }
    $d = json_decode($res, true);
    if (!is_array($d)) return ['ok' => false, 'transport_error' => false, 'error' => 'Пустой/некорректный ответ Telegram', 'http_code' => $httpCode, 'raw' => $res];
    if (empty($d['ok'])) $d['transport_error'] = false; // ответ дошёл, но Telegram вернул ошибку (например 401 — неверный токен)
    return $d;
}
function notify_tg_send($token, $chatId, $text, $proxy = null, $apiBase = null) {
    return notify_tg_request($token, 'sendMessage', ['chat_id' => $chatId, 'text' => $text], $proxy, $apiBase);
}
/** Последние входящие через long-polling getUpdates — чтобы найти chat_id тех, кто написал боту /start. */
function notify_tg_recent_chats($token, $proxy = null, $apiBase = null) {
    // getUpdates отвечает "409 Conflict: can't use getUpdates method while webhook is active", если на боте
    // когда-либо был установлен вебхук (даже случайно) — снимаем его перед каждым опросом, это безопасно и идемпотентно.
    notify_tg_request($token, 'deleteWebhook', ['drop_pending_updates' => false], $proxy, $apiBase);
    $r = notify_tg_request($token, 'getUpdates', ['limit' => 50, 'timeout' => 0], $proxy, $apiBase);
    if (empty($r['ok'])) return $r;
    $seen = [];
    foreach (($r['result'] ?? []) as $u) {
        $msg = $u['message'] ?? $u['edited_message'] ?? null;
        if (!$msg) continue;
        $chat = $msg['chat'] ?? null;
        if (!$chat) continue;
        $id = (string)$chat['id'];
        $name = trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? '')) ?: ($chat['username'] ?? $id);
        $seen[$id] = ['chat_id' => $id, 'name' => $name, 'text' => $msg['text'] ?? '', 'date' => $msg['date'] ?? 0];
    }
    return ['ok' => true, 'data' => array_values($seen)];
}

// ── MAX Bot API (https://dev.max.ru) — платформа-api2.max.ru, токен в заголовке Authorization, версия ?v= ──
define('NOTIFY_MAX_BASE', 'https://platform-api2.max.ru');
define('NOTIFY_MAX_VERSION', '1.2.5');
function notify_max_request($token, $path, $httpMethod, $query = [], $body = null, $proxy = null) {
    $url = NOTIFY_MAX_BASE . $path . '?' . http_build_query(array_merge(['v' => NOTIFY_MAX_VERSION], $query));
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: ' . $token, 'Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CUSTOMREQUEST => $httpMethod,
        CURLOPT_SSL_VERIFYPEER => true,
    ];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    if ($proxy) $opts[CURLOPT_PROXY] = $proxy;
    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($errno) return ['ok' => false, 'transport_error' => true, 'error' => $err, 'curl_errno' => $errno];
    $d = json_decode($res, true);
    return is_array($d) ? $d : ['ok' => false, 'transport_error' => false, 'error' => 'Пустой/некорректный ответ MAX', 'raw' => $res];
}
function notify_max_send($token, $userId, $text, $proxy = null) {
    $r = notify_max_request($token, '/messages', 'POST', ['user_id' => $userId], ['text' => $text], $proxy);
    // MAX возвращает {"code":"...","message":"..."} при ошибке, иначе объект отправленного сообщения
    if (isset($r['code'])) return ['ok' => false, 'error' => $r['message'] ?? $r['code']];
    if (array_key_exists('ok', $r)) return $r;
    return array_merge(['ok' => true], $r);
}
function notify_max_recent_chats($token, $proxy = null) {
    $r = notify_max_request($token, '/updates', 'GET', ['limit' => 50, 'timeout' => 0], null, $proxy);
    if (isset($r['code'])) return ['ok' => false, 'error' => $r['message'] ?? $r['code']];
    $seen = [];
    foreach (($r['updates'] ?? $r['result'] ?? []) as $u) {
        $msg = $u['message'] ?? null;
        if (!$msg) continue;
        $sender = $msg['sender'] ?? [];
        $id = (string)($sender['user_id'] ?? '');
        if ($id === '') continue;
        $name = trim(($sender['first_name'] ?? '') . ' ' . ($sender['last_name'] ?? '')) ?: ($sender['username'] ?? $id);
        $seen[$id] = ['chat_id' => $id, 'name' => $name, 'text' => ($msg['body']['text'] ?? ''), 'date' => $msg['timestamp'] ?? 0];
    }
    return ['ok' => true, 'data' => array_values($seen)];
}

// ── Почта ─────────────────────────────────────────────────────────────────────────
/**
 * Отправить письмо тем способом, который настроен в админке: Resend (HTTP API),
 * либо обычная mail(). SMTP без библиотеки поднимать не стали — если выбран smtp,
 * письмо уйдёт через mail(), а причина возвращается в ответе, чтобы это было видно,
 * а не выглядело как «отправлено».
 */
function notify_send_email($cfg, $to, $subject, $text) {
    $to = trim((string)$to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Некорректный адрес получателя'];
    }
    $method = $cfg['email_method'] ?? '';
    if ($method === 'resend' && !empty($cfg['resend_key'])) {
        $from = trim((string)($cfg['resend_from'] ?? '')) ?: 'psytalk.pro <noreply@psytalk.pro>';
        $payload = [
            'from' => $from,
            'to' => [$to],
            'subject' => (string)$subject,
            'text' => (string)$text,
        ];
        $reply = trim((string)($cfg['resend_replyto'] ?? ''));
        if ($reply !== '') $payload['reply_to'] = $reply;
        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cfg['resend_key'], 'Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $res = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno) return ['ok' => false, 'transport_error' => true, 'error' => 'Не удалось связаться с Resend: ' . $err];
        $d = json_decode($res, true);
        if ($code >= 200 && $code < 300) return ['ok' => true, 'id' => $d['id'] ?? null, 'via' => 'resend'];
        $msg = is_array($d) ? ($d['message'] ?? $d['error'] ?? $res) : $res;
        return ['ok' => false, 'error' => 'Resend отказал (' . $code . '): ' . (is_string($msg) ? $msg : json_encode($msg)), 'via' => 'resend'];
    }
    // mail() и заодно запасной путь для smtp
    $from = trim((string)($cfg['smtp_from'] ?? $cfg['resend_from'] ?? '')) ?: 'noreply@psytalk.pro';
    $headers = "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nFrom: " . $from . "\r\n";
    $sent = @mail($to, '=?UTF-8?B?' . base64_encode((string)$subject) . '?=', (string)$text, $headers);
    if (!$sent) return ['ok' => false, 'error' => 'Не удалось отправить письмо через mail()', 'via' => 'mail'];
    return ['ok' => true, 'via' => 'mail', 'note' => $method === 'smtp' ? 'SMTP не поддерживается, письмо ушло через mail()' : null];
}

/** Подставить {{переменные}} в шаблон уведомления (notification_templates). */
function notify_render(PDO $pdo, $code, $vars) {
    $title = ''; $body = '';
    try {
        $st = $pdo->prepare("SELECT title, body FROM notification_templates WHERE code = ? LIMIT 1");
        $st->execute([$code]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) { $title = $row['title'] ?? ''; $body = $row['body'] ?? ''; }
    } catch (Exception $e) {}
    $rep = function ($s) use ($vars) {
        foreach ($vars as $k => $v) $s = str_replace('{{' . $k . '}}', (string)$v, $s);
        return $s;
    };
    return ['title' => $rep($title), 'body' => $rep($body)];
}

/** Отправить уведомление всем админам, привязавшим Telegram/MAX (см. notify_bot_links). */
function notify_admins(PDO $pdo, $code, $vars) {
    $cfg = notify_channels_config();
    if (empty($cfg['telegram_token']) && empty($cfg['max_token'])) return;
    $rendered = notify_render($pdo, $code, $vars);
    if (trim($rendered['body']) === '') return;
    notify_ensure_links_table($pdo);
    try {
        $admins = $pdo->query("SELECT id FROM users WHERE role='admin'")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) { $admins = []; }
    if (!$admins) return;
    $in = implode(',', array_fill(0, count($admins), '?'));
    try {
        $st = $pdo->prepare("SELECT user_id, channel, chat_id FROM notify_bot_links WHERE user_id IN ($in)");
        $st->execute($admins);
        $links = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $links = []; }
    $text = trim($rendered['title'] . "\n" . $rendered['body']);
    foreach ($links as $l) {
        try {
            if ($l['channel'] === 'telegram' && !empty($cfg['telegram_token'])) {
                notify_tg_send($cfg['telegram_token'], $l['chat_id'], $text, $cfg['telegram_proxy'] ?? null, $cfg['telegram_api_base'] ?? null);
            } elseif ($l['channel'] === 'max' && !empty($cfg['max_token'])) {
                notify_max_send($cfg['max_token'], $l['chat_id'], $text, $cfg['max_proxy'] ?? null);
            }
        } catch (Exception $e) {}
    }
}

/**
 * Диагностика соединения с ботом (задача #40) — отдельно от getUpdates/sendMessage,
 * чтобы явно показать администратору, ЧТО именно не работает: сеть не пускает хостинг
 * до api.telegram.org / platform-api2.max.ru (transport_error — похоже на блокировку
 * провайдером/файрволом хостера), или соединение проходит, но токен бота неверный
 * (обычная 401 Unauthorized), или всё в порядке.
 */
function notify_tg_diag($token, $proxy = null, $apiBase = null) {
    $r = notify_tg_request($token, 'getMe', [], $proxy, $apiBase);
    return notify_diag_verdict($r);
}
function notify_max_diag($token, $proxy = null) {
    $r = notify_max_request($token, '/me', 'GET', [], null, $proxy);
    return notify_diag_verdict($r);
}
function notify_diag_verdict($r) {
    if (!empty($r['ok'])) {
        return ['verdict' => 'ok', 'message' => 'Соединение и токен в порядке — бот ответил.', 'raw' => $r];
    }
    if (!empty($r['transport_error'])) {
        return [
            'verdict' => 'transport',
            'message' => 'Сервер не смог подключиться (cURL: ' . ($r['error'] ?? '?') . '). '
                . 'Это похоже на сетевую блокировку/файрвол на стороне хостинга, а не на проблему с токеном — '
                . 'токен вообще не успел проверяться. Если это стабильно повторяется, нужен обход блокировки: '
                . 'либо прокси (поле "Прокси" рядом с токеном) до Telegram/MAX из региона без блокировки, '
                . 'либо (только для Telegram, проще и бесплатно) свой Cloudflare Worker-реверс-прокси в поле '
                . '"Адрес API Telegram" — см. подсказку под этим полем в разделе «Каналы доставки».',
            'raw' => $r,
        ];
    }
    return [
        'verdict' => 'api_error',
        'message' => 'Сервер достучался до бота, но тот ответил ошибкой: ' . ($r['description'] ?? $r['error'] ?? 'неизвестно')
            . '. Сеть здесь ни при чём — скорее всего неверный/отозванный токен, проверьте его в @BotFather (Telegram) или в кабинете MAX.',
        'raw' => $r,
    ];
}
