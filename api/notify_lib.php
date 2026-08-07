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
    return is_array($c) ? $c : [];
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
function notify_tg_request($token, $method, $params = []) {
    $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($params),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return ['ok' => false, 'error' => $err];
    $d = json_decode($res, true);
    return is_array($d) ? $d : ['ok' => false, 'error' => 'Пустой/некорректный ответ Telegram', 'raw' => $res];
}
function notify_tg_send($token, $chatId, $text) {
    return notify_tg_request($token, 'sendMessage', ['chat_id' => $chatId, 'text' => $text]);
}
/** Последние входящие через long-polling getUpdates — чтобы найти chat_id тех, кто написал боту /start. */
function notify_tg_recent_chats($token) {
    $r = notify_tg_request($token, 'getUpdates', ['limit' => 50, 'timeout' => 0]);
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
function notify_max_request($token, $path, $httpMethod, $query = [], $body = null) {
    $url = NOTIFY_MAX_BASE . $path . '?' . http_build_query(array_merge(['v' => NOTIFY_MAX_VERSION], $query));
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: ' . $token, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CUSTOMREQUEST => $httpMethod,
        CURLOPT_SSL_VERIFYPEER => true,
    ];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return ['ok' => false, 'error' => $err];
    $d = json_decode($res, true);
    return is_array($d) ? $d : ['ok' => false, 'error' => 'Пустой/некорректный ответ MAX', 'raw' => $res];
}
function notify_max_send($token, $userId, $text) {
    $r = notify_max_request($token, '/messages', 'POST', ['user_id' => $userId], ['text' => $text]);
    // MAX возвращает {"code":"...","message":"..."} при ошибке, иначе объект отправленного сообщения
    if (isset($r['code'])) return ['ok' => false, 'error' => $r['message'] ?? $r['code']];
    if (array_key_exists('ok', $r)) return $r;
    return array_merge(['ok' => true], $r);
}
function notify_max_recent_chats($token) {
    $r = notify_max_request($token, '/updates', 'GET', ['limit' => 50, 'timeout' => 0]);
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
                notify_tg_send($cfg['telegram_token'], $l['chat_id'], $text);
            } elseif ($l['channel'] === 'max' && !empty($cfg['max_token'])) {
                notify_max_send($cfg['max_token'], $l['chat_id'], $text);
            }
        } catch (Exception $e) {}
    }
}
