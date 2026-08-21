<?php
/**
 * push.php — настоящие push-уведомления (Web Push + VAPID).
 *
 * Зачем: до этого уведомление показывалось только пока открыта страница — она
 * опрашивала сервер каждые несколько секунд. На телефоне это и давало «приходят
 * через раз»: браузер усыпляет таймеры в фоне, а на iPhone — почти сразу. Push
 * доставляет сообщение через службу браузера, поэтому оно приходит и при
 * закрытом приложении.
 *
 * GET  ?action=key                    → публичный ключ VAPID для подписки
 * POST ?action=subscribe {endpoint, keys:{p256dh, auth}}
 * POST ?action=unsubscribe {endpoint}
 * GET  ?action=pending                → что показать (спрашивает сервис-воркер)
 * GET  ?action=status                 → готовность (для админки и проверок)
 * POST ?action=test                   → прислать себе проверочное уведомление
 *
 * Как устроена отправка. Пуш уходит БЕЗ содержимого: сервис-воркер, получив
 * пустой пуш, сам спрашивает ?action=pending и рисует уведомление. Так сделано
 * намеренно — содержимое пуша пришлось бы шифровать (ECDH + HKDF + AES-GCM), а
 * это много кода на ровном месте и лишний риск ошибиться в криптографии. Плюс
 * текст сообщения не проходит через чужую службу доставки: для переписки с
 * психологом это важнее удобства.
 *
 * Ключи VAPID создаются сами при первом обращении и живут в таблице settings —
 * в репозиторий не попадают.
 */

// Файл работает в двух режимах: как обычный эндпоинт и как библиотека — его
// подключает notify_dispatch.php ради push_send_to_user(). В режиме библиотеки
// нельзя ни отдавать заголовки, ни отвечать, иначе рассылка сломает свой же JSON.
$PUSH_LIB_ONLY = !empty($GLOBALS['__PUSH_LIB_ONLY']);
if (!$PUSH_LIB_ONLY) {
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/schema_util.php';
if (!function_exists('getDB') && !function_exists('getDbConnection') && !function_exists('getPDO')) {
    require_once __DIR__ . '/db.php';
}
$pdo = function_exists('getDB') ? getDB()
     : (function_exists('getDbConnection') ? getDbConnection()
     : (function_exists('getPDO') ? getPDO() : null));
// В режиме библиотеки нас подключили в середине чужого ответа — печатать свою
// ошибку туда нельзя, она испортит уже отданный JSON. Просто ничего не делаем.
if (!$pdo) {
    if ($PUSH_LIB_ONLY) return;
    http_response_code(500); echo json_encode(['error' => 'Нет подключения к БД']); exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();
$userId = $_SESSION['user_id'] ?? null;

function pushOut($d, $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

// ── base64url ────────────────────────────────────────────────────────────────
function b64u($bin) { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
function b64uDec($s) {
    $s = strtr($s, '-_', '+/');
    return base64_decode($s . str_repeat('=', (4 - strlen($s) % 4) % 4));
}

// ── Таблицы ──────────────────────────────────────────────────────────────────
$pushErr = '';
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS push_subs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(64) NOT NULL,
        endpoint VARCHAR(500) NOT NULL,
        p256dh VARCHAR(200) NULL,
        auth VARCHAR(100) NULL,
        ua VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        last_ok DATETIME NULL,
        fails INT NOT NULL DEFAULT 0,
        UNIQUE KEY uniq_endpoint (endpoint),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    psy_align_collation($pdo, ['push_subs']);
} catch (Exception $e) { $pushErr = $e->getMessage(); }

/**
 * Своё хранилище для ключей VAPID — отдельная таблица, а не общая `settings`.
 *
 * Сначала ключи лежали в `settings` с определением колонок «на угад» (key_name/value
 * либо setting_key/setting_value). Если угадать не удавалось, запись молча не
 * сохранялась — и пара ключей создавалась заново на каждый запрос. Для push это
 * не мелочь, а полная поломка: подписка привязана к ключу, с которым она сделана,
 * и служба доставки начинает отвечать 403 на всё. Своя таблица такого не допускает.
 */
function pushSet(PDO $pdo, $key, $val = null) {
    static $ready = null;
    if ($ready === null) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS push_config (
                key_name VARCHAR(64) NOT NULL PRIMARY KEY,
                value TEXT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $ready = true;
        } catch (Exception $e) { $ready = false; }
    }
    if (!$ready) return null;
    if ($val === null) {
        try {
            $st = $pdo->prepare("SELECT value FROM push_config WHERE key_name = ? LIMIT 1");
            $st->execute([$key]);
            $r = $st->fetchColumn();
            return ($r === false || $r === null || $r === '') ? null : (string)$r;
        } catch (Exception $e) { return null; }
    }
    try {
        $st = $pdo->prepare("SELECT 1 FROM push_config WHERE key_name = ? LIMIT 1");
        $st->execute([$key]);
        if ($st->fetchColumn()) {
            $pdo->prepare("UPDATE push_config SET value = ? WHERE key_name = ?")->execute([$val, $key]);
        } else {
            $pdo->prepare("INSERT INTO push_config (key_name, value) VALUES (?, ?)")->execute([$key, $val]);
        }
        // Проверяем, что запись действительно легла: без этого «ключи не сохраняются»
        // выглядело бы как «уведомления иногда не приходят».
        $st = $pdo->prepare("SELECT value FROM push_config WHERE key_name = ? LIMIT 1");
        $st->execute([$key]);
        if ((string)$st->fetchColumn() !== (string)$val) return null;
    } catch (Exception $e) { return null; }
    return $val;
}

/**
 * Пара ключей VAPID. Создаётся один раз сама — просить админа завести ключи
 * вручную значило бы, что уведомления не заработают, пока он этого не сделает.
 * Возвращает ['public' => base64url точки, 'private' => PEM] либо null.
 */
function vapidKeys(PDO $pdo) {
    $pub = pushSet($pdo, 'vapid_public');
    $priv = pushSet($pdo, 'vapid_private');
    if ($pub && $priv) return ['public' => $pub, 'private' => $priv];
    if (!function_exists('openssl_pkey_new')) return null;

    $res = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    if (!$res) return null;
    $details = openssl_pkey_get_details($res);
    if (empty($details['ec']['x']) || empty($details['ec']['y'])) return null;
    // Публичный ключ для VAPID — несжатая точка: 0x04 || X(32) || Y(32)
    $point = "\x04" . str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT)
                    . str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    $pem = '';
    if (!openssl_pkey_export($res, $pem)) return null;
    $pub = b64u($point);
    // Не сохранились — значит на следующем запросе появится другая пара, а все
    // подписки, сделанные с этой, станут недействительными. Лучше честно сказать
    // «не готово», чем раздавать ключ-однодневку.
    if (pushSet($pdo, 'vapid_public', $pub) === null) return null;
    if (pushSet($pdo, 'vapid_private', $pem) === null) return null;
    return ['public' => $pub, 'private' => $pem];
}

/** Подпись ES256: openssl отдаёт DER, а JWT ждёт R||S по 32 байта. */
function es256(string $data, string $pem) {
    $key = openssl_pkey_get_private($pem);
    if (!$key) return null;
    $der = '';
    if (!openssl_sign($data, $der, $key, OPENSSL_ALGO_SHA256)) return null;
    // DER: 30 len 02 lenR R 02 lenS S
    $off = 0;
    if (($der[$off++] ?? '') !== "\x30") return null;
    $l = ord($der[$off++]);
    if ($l > 0x80) $off += $l - 0x80;
    $read = function () use ($der, &$off) {
        if (($der[$off++] ?? '') !== "\x02") return null;
        $n = ord($der[$off++]);
        $v = substr($der, $off, $n);
        $off += $n;
        return ltrim($v, "\x00");
    };
    $r = $read(); $s = $read();
    if ($r === null || $s === null) return null;
    return str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
}

/** JWT для конкретной службы доставки: aud — её origin. */
function vapidJwt($endpoint, array $keys) {
    $u = parse_url($endpoint);
    if (empty($u['host'])) return null;
    $aud = ($u['scheme'] ?? 'https') . '://' . $u['host'];
    $head = b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $body = b64u(json_encode([
        'aud' => $aud,
        'exp' => time() + 12 * 3600,
        'sub' => 'mailto:support@psytalk.pro',
    ], JSON_UNESCAPED_SLASHES));
    $sig = es256($head . '.' . $body, $keys['private']);
    if ($sig === null) return null;
    return $head . '.' . $body . '.' . b64u($sig);
}

/**
 * Отправить пустой пуш по одной подписке.
 * Возвращает ['ok'=>bool, 'code'=>int, 'gone'=>bool] — gone означает «подписка
 * мертва, её надо удалить» (браузер снесён, разрешение отозвано).
 */
function pushSendOne(PDO $pdo, string $endpoint, array $keys) {
    $jwt = vapidJwt($endpoint, $keys);
    if (!$jwt) return ['ok' => false, 'code' => 0, 'gone' => false, 'error' => 'не удалось подписать запрос'];
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'code' => 0, 'gone' => false, 'error' => 'на сервере нет расширения curl'];
    }
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: vapid t=' . $jwt . ', k=' . $keys['public'],
            'TTL: 3600',
            'Urgency: high',
            'Content-Length: 0',
        ],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    // 404/410 — подписка больше не существует; 403 — ключи не те, что при подписке
    $gone = in_array($code, [404, 410], true);
    return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'gone' => $gone,
            'error' => $err ?: (($code >= 400) ? substr((string)$resp, 0, 200) : null)];
}

/**
 * Разослать пуш всем устройствам человека. Возвращает [отправлено, удалено, ошибки].
 * Вызывается из notify_dispatch.php наравне с почтой и Telegram.
 */
function push_send_to_user(PDO $pdo, $userId) {
    $keys = vapidKeys($pdo);
    if (!$keys) return ['sent' => 0, 'removed' => 0, 'errors' => ['нет ключей VAPID']];
    try {
        $st = $pdo->prepare("SELECT id, endpoint FROM push_subs WHERE user_id = ? LIMIT 20");
        $st->execute([$userId]);
        $subs = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return ['sent' => 0, 'removed' => 0, 'errors' => [$e->getMessage()]]; }

    $sent = 0; $removed = 0; $errors = []; $details = [];
    foreach ($subs as $s) {
        $r = pushSendOne($pdo, (string)$s['endpoint'], $keys);
        if ($r['ok']) {
            $sent++;
            // Подробности нужны для разбора «отправили, а не пришло»: по коду и
            // службе доставки видно, приняли ли сообщение и кто именно.
            $details[] = ['host' => parse_url((string)$s['endpoint'], PHP_URL_HOST), 'code' => $r['code'], 'ok' => true];
            try { $pdo->prepare("UPDATE push_subs SET last_ok = NOW(), fails = 0 WHERE id = ?")->execute([$s['id']]); } catch (Exception $e) {}
            continue;
        }
        if ($r['gone']) {
            $removed++;
            $details[] = ['host' => parse_url((string)$s['endpoint'], PHP_URL_HOST), 'code' => $r['code'], 'ok' => false, 'gone' => true];
            try { $pdo->prepare("DELETE FROM push_subs WHERE id = ?")->execute([$s['id']]); } catch (Exception $e) {}
            continue;
        }
        $errors[] = 'HTTP ' . $r['code'] . ($r['error'] ? ': ' . $r['error'] : '');
        $details[] = ['host' => parse_url((string)$s['endpoint'], PHP_URL_HOST), 'code' => $r['code'], 'ok' => false];
        // Пять неудач подряд — подписка, скорее всего, безнадёжна, чтобы не долбить вечно
        try {
            $pdo->prepare("UPDATE push_subs SET fails = fails + 1 WHERE id = ?")->execute([$s['id']]);
            $pdo->prepare("DELETE FROM push_subs WHERE id = ? AND fails >= 5")->execute([$s['id']]);
        } catch (Exception $e) {}
    }
    return ['sent' => $sent, 'removed' => $removed, 'errors' => $errors, 'details' => $details];
}

/**
 * Когда этому человеку последний раз успешно уходил пуш (или null).
 * Нужно рассылке: если пуш уже ушёл сразу после сообщения (см. action=poke),
 * второй раз через несколько минут он лишний — то же самое уведомление во
 * второй раз, а если человек уже всё прочитал, то и вовсе пустое.
 */
function push_last_ok(PDO $pdo, $userId) {
    try {
        $st = $pdo->prepare("SELECT MAX(last_ok) FROM push_subs WHERE user_id = ?");
        $st->execute([$userId]);
        $v = $st->fetchColumn();
        return $v ? (string)$v : null;
    } catch (Exception $e) { return null; }
}

/** Сколько устройств подключено. Ноль — значит push этому человеку не канал вовсе. */
function push_device_count(PDO $pdo, $userId) {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM push_subs WHERE user_id = ?");
        $st->execute([$userId]);
        return (int)$st->fetchColumn();
    } catch (Exception $e) { return 0; }
}

/**
 * Отдать ответ клиенту и продолжить работу в фоне.
 * Пуши по группе на 20 человек — это 20 обращений к службам доставки; ждать их
 * в момент отправки сообщения нельзя, иначе «отправить» начнёт подвисать.
 */
function push_flush_response() {
    @ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); return true; }
    while (ob_get_level() > 0) { @ob_end_flush(); }
    @flush();
    return false;
}

/** Пуш всем участникам группы, кроме автора сообщения. */
function push_notify_group(PDO $pdo, $groupId, $senderId, $limit = 30) {
    try {
        $st = $pdo->prepare("SELECT user_id FROM chat_group_members WHERE group_id = ? AND user_id <> ? LIMIT $limit");
        $st->execute([$groupId, $senderId]);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) { return 0; }
    $n = 0;
    foreach ($ids as $uid) {
        try { $r = push_send_to_user($pdo, $uid); $n += (int)$r['sent']; } catch (Exception $e) {}
    }
    return $n;
}

// ── Дальше — обработка запросов страницы ─────────────────────────────────────
if ($PUSH_LIB_ONLY) return;      // подключили ради функции отправки — на этом всё

$action = $_GET['action'] ?? '';
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

if ($action === 'key') {
    $keys = vapidKeys($pdo);
    if (!$keys) pushOut(['ok' => false, 'error' => 'На сервере недоступен OpenSSL с кривой P-256 — push невозможен'], 500);
    pushOut(['ok' => true, 'key' => $keys['public']]);
}

if ($action === 'status') {
    $keys = vapidKeys($pdo);
    $cnt = 0;
    if ($userId) {
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM push_subs WHERE user_id = ?");
            $st->execute([$userId]);
            $cnt = (int)$st->fetchColumn();
        } catch (Exception $e) {}
    }
    pushOut(['ok' => true, 'ready' => (bool)$keys, 'devices' => $cnt,
             'table_error' => $pushErr ?: null]);
}

if (!$userId) pushOut(['ok' => false, 'error' => 'Требуется авторизация'], 401);

if ($action === 'subscribe' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $endpoint = trim((string)($body['endpoint'] ?? ''));
    if ($endpoint === '' || !preg_match('~^https://~i', $endpoint)) {
        pushOut(['ok' => false, 'error' => 'Некорректная подписка'], 400);
    }
    $p256 = substr((string)(($body['keys']['p256dh'] ?? '')), 0, 190);
    $auth = substr((string)(($body['keys']['auth'] ?? '')), 0, 90);
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);
    try {
        // Один и тот же endpoint может прийти повторно (переустановка, смена аккаунта) —
        // тогда он должен принадлежать тому, кто подписался последним.
        $st = $pdo->prepare("SELECT id FROM push_subs WHERE endpoint = ? LIMIT 1");
        $st->execute([$endpoint]);
        $id = $st->fetchColumn();
        if ($id) {
            $pdo->prepare("UPDATE push_subs SET user_id = ?, p256dh = ?, auth = ?, ua = ?, fails = 0 WHERE id = ?")
                ->execute([$userId, $p256, $auth, $ua, $id]);
        } else {
            $pdo->prepare("INSERT INTO push_subs (user_id, endpoint, p256dh, auth, ua, created_at)
                            VALUES (?, ?, ?, ?, ?, NOW())")
                ->execute([$userId, $endpoint, $p256, $auth, $ua]);
        }
    } catch (Exception $e) { pushOut(['ok' => false, 'error' => $e->getMessage()], 500); }
    pushOut(['ok' => true]);
}

if ($action === 'unsubscribe' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $endpoint = trim((string)($body['endpoint'] ?? ''));
    try { $pdo->prepare("DELETE FROM push_subs WHERE endpoint = ? AND user_id = ?")->execute([$endpoint, $userId]); }
    catch (Exception $e) {}
    pushOut(['ok' => true]);
}

if ($action === 'pending') {
    // Это спрашивает сервис-воркер, получив пустой пуш: что именно показать.
    // Звонок важнее сообщений: если человеку прямо сейчас звонят, показываем вызов,
    // а не «новое сообщение» — иначе о звонке он узнает только открыв сайт.
    try {
        $st = $pdo->prepare("SELECT c.kind, u.first_name, u.last_name
                             FROM rtc_calls c LEFT JOIN users u ON u.id = c.from_id
                             WHERE c.to_id = ? AND c.status = 'ringing'
                               AND c.created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)
                             ORDER BY c.id DESC LIMIT 1");
        $st->execute([$userId]);
        if ($call = $st->fetch(PDO::FETCH_ASSOC)) {
            $who = trim((($call['first_name'] ?? '') . ' ' . ($call['last_name'] ?? ''))) ?: 'Собеседник';
            pushOut(['ok' => true, 'count' => 0, 'call' => true,
                     'title' => $who,
                     'body' => ($call['kind'] === 'audio' ? 'Аудиозвонок' : 'Видеозвонок') . ' — нажмите, чтобы ответить',
                     'url' => '/chat.html']);
        }
    } catch (Exception $e) { /* таблицы звонков может ещё не быть */ }

    $cnt = 0; $names = [];
    try {
        $st = $pdo->prepare("SELECT m.sender_id, COUNT(*) AS c
                              FROM messages m
                              WHERE m.receiver_id = ? AND m.sender_id <> ?
                                AND NOT EXISTS (
                                    SELECT 1 FROM messages r
                                    WHERE r.sender_id = m.receiver_id AND r.receiver_id = m.sender_id
                                      AND r.created_at > m.created_at)
                                AND m.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                              GROUP BY m.sender_id ORDER BY MAX(m.created_at) DESC LIMIT 3");
        $st->execute([$userId, $userId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cnt += (int)$r['c'];
            try {
                $u = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1");
                $u->execute([$r['sender_id']]);
                $row = $u->fetch(PDO::FETCH_ASSOC);
                $nm = trim((($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));
                if ($nm !== '') $names[] = $nm;
            } catch (Exception $e) {}
        }
    } catch (Exception $e) {}

    // Группы и каналы считаем тоже: иначе пуш о сообщении в группе показывал
    // «Загляните в чаты» — человек не понимал, что вообще случилось.
    $grp = 0;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM chat_group_messages gm
                              JOIN chat_group_members me ON me.group_id = gm.group_id AND me.user_id = ?
                              WHERE gm.sender_id <> ? AND gm.id > COALESCE(me.last_read_message_id, 0)");
        $st->execute([$userId, $userId]);
        $grp = (int)$st->fetchColumn();
    } catch (Exception $e) {}

    $total = $cnt + $grp;
    if ($total <= 0) pushOut(['ok' => true, 'count' => 0, 'title' => 'psytalk.pro', 'body' => 'Загляните в чаты', 'url' => '/chat.html']);
    $who = implode(', ', $names);
    // Заголовок — имя автора, если сообщение личное и одно; в остальных случаях
    // общий, чтобы не врать «от Ивана», когда написали ещё и в группу.
    $title = ($cnt > 0 && $who !== '' && $grp === 0) ? $who : 'psytalk.pro';
    $body = $total === 1
        ? ($grp === 1 ? 'Новое сообщение в группе' : 'Новое сообщение в чатах')
        : "Новых сообщений: $total";
    pushOut(['ok' => true, 'count' => $total, 'title' => $title, 'body' => $body, 'url' => '/chat.html']);
}

/**
 * Толчок: «я только что написал этому человеку — пришли ему пуш сейчас».
 * Иначе уведомление ждало бы рассылки: она пропускает сообщения свежее пяти
 * минут (человек, скорее всего, в диалоге) и запускается не чаще раза в четыре
 * минуты. До девяти минут задержки в переписке — это и читалось как «приходят
 * через раз».
 *
 * Позвать может только тот, кто действительно только что написал получателю:
 * проверяем это по самой переписке, поэтому дёрнуть уведомление незнакомому
 * человеку не получится.
 */
/**
 * Толчок для звонка. Обычный poke требует свежего сообщения получателю, которого
 * при звонке нет, поэтому право на пуш проверяем по самому звонку: разбудить можно
 * только того, кому ты прямо сейчас звонишь.
 */
if ($action === 'poke-call' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $to = trim((string)($body['to'] ?? ''));
    if ($to === '' || $to === (string)$userId) pushOut(['ok' => false, 'error' => 'Некому'], 400);
    try {
        $st = $pdo->prepare("SELECT 1 FROM rtc_calls
                             WHERE from_id = ? AND to_id = ? AND status = 'ringing'
                               AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND) LIMIT 1");
        $st->execute([$userId, $to]);
        if (!$st->fetchColumn()) pushOut(['ok' => false, 'error' => 'Нет активного вызова этому человеку'], 403);
    } catch (Exception $e) { pushOut(['ok' => false, 'error' => 'Звонки недоступны'], 500); }
    $r = push_send_to_user($pdo, $to);
    pushOut(['ok' => true, 'sent' => $r['sent'], 'removed' => $r['removed']]);
}

if ($action === 'poke' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $to = trim((string)($body['to'] ?? ''));
    if ($to === '' || $to === (string)$userId) pushOut(['ok' => false, 'error' => 'Некому'], 400);
    $wrote = false;
    try {
        $st = $pdo->prepare("SELECT 1 FROM messages
                              WHERE sender_id = ? AND receiver_id = ?
                                AND created_at > DATE_SUB(NOW(), INTERVAL 3 MINUTE) LIMIT 1");
        $st->execute([$userId, $to]);
        $wrote = (bool)$st->fetchColumn();
    } catch (Exception $e) {}
    if (!$wrote) pushOut(['ok' => false, 'error' => 'Нет свежего сообщения этому получателю'], 403);

    // Не чаще раза в 20 секунд на пару: при быстрой переписке иначе полетит
    // по пушу на каждую реплику, а уведомление и так одно (одинаковый tag).
    $last = push_last_ok($pdo, $to);
    if ($last && strtotime($last) > time() - 20) pushOut(['ok' => true, 'skipped' => 'недавно уже отправляли']);

    $r = push_send_to_user($pdo, $to);
    pushOut(['ok' => true, 'sent' => $r['sent'], 'removed' => $r['removed']]);
}

if ($action === 'test' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $r = push_send_to_user($pdo, $userId);
    pushOut(['ok' => $r['sent'] > 0, 'sent' => $r['sent'], 'removed' => $r['removed'],
             'errors' => $r['errors'],
             // Куда и с каким кодом ушло — чтобы «отправили, а не пришло» можно
             // было разобрать, а не гадать про чужой телефон.
             'details' => $r['details'] ?? [],
             'hint' => $r['sent'] > 0 ? null : 'Подписки нет или служба доставки отказала — включите уведомления в чате']);
}

pushOut(['ok' => false, 'error' => 'Неизвестное действие'], 400);
