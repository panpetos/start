<?php
/**
 * notify_dispatch.php — собственно доставка уведомлений в выбранные людьми каналы.
 *
 * До этого выбор каналов (notify_prefs.php) был, а уведомления по нему не уходили:
 * человек мог настроить почту или Telegram — и ничего не получить, кроме проверочного
 * сообщения. Здесь замыкается цепочка.
 *
 * Почему разбором занимается отдельный проход, а не «отправка в момент события»:
 * личные сообщения пишутся через api/messages.php, которого нет в репозитории (живёт
 * только на сервере), поставить туда хук нельзя. Поэтому обходим таблицу сообщений
 * периодически — так уведомление приходит через несколько минут, что для почты и
 * мессенджера нормально, и попутно не дёргаем человека на каждую реплику в активном
 * диалоге: сообщения новее «тихой паузы» (по умолчанию 5 минут) не берём вовсе.
 *
 * «Прочитано» не по флагу (в разных схемах его может не быть), а по факту: если человек
 * ответил в этот диалог позже — значит он сообщение видел, уведомлять не за чем.
 *
 * GET/POST ?action=run&token=...   — прогон (cron по токену либо админ по сессии)
 * GET  ?action=status              — когда был прогон и что отправлено (админ/токен)
 * POST ?action=save-token {token}  — записать секрет (админ)
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

function out($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if (session_status() === PHP_SESSION_NONE) session_start();
$uid = $_SESSION['user_id'] ?? null;
$isAdmin = false;
if ($uid) {
    try {
        $st = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        $st->execute([$uid]);
        $isAdmin = ((string)$st->fetchColumn() === 'admin');
    } catch (Exception $e) {}
}
$cfgFile = __DIR__ . '/notify_dispatch_config.php';
$cfg = file_exists($cfgFile) ? (include $cfgFile) : [];
$secretToken = is_array($cfg) ? (string)($cfg['token'] ?? '') : '';
$provided = $_GET['token'] ?? ($_SERVER['HTTP_X_NOTIFY_TOKEN'] ?? '');
$validToken = $secretToken !== '' && hash_equals($secretToken, (string)$provided);

$action = $_GET['action'] ?? '';
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

const NOTIFY_QUIET_MIN = 5;      // сообщения свежее этого не трогаем — человек, скорее всего, в диалоге
const NOTIFY_LOOKBACK_H = 48;    // насколько назад смотрим при самом первом прогоне
const NOTIFY_MAX_USERS = 200;    // предохранитель на один прогон
const NOTIFY_MAX_RETRIES = 5;    // столько раз пробуем одно и то же уведомление
const TICK_MIN_MIN = 4;          // не чаще раза в столько минут запускаем проход «сам собой»

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS notify_dispatch_state (
        key_name VARCHAR(64) NOT NULL PRIMARY KEY,
        value TEXT NULL,
        updated_at DATETIME NOT NULL
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS notify_sent_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(64) NOT NULL,
        channel VARCHAR(16) NOT NULL,
        kind VARCHAR(32) NOT NULL,
        ref VARCHAR(190) NULL,
        ok TINYINT NOT NULL DEFAULT 1,
        note VARCHAR(500) NULL,
        sent_at DATETIME NOT NULL,
        INDEX idx_user (user_id),
        INDEX idx_ref (ref)
    ) DEFAULT CHARSET=utf8mb4");
    psy_align_collation($pdo, ['notify_dispatch_state', 'notify_sent_log']);
} catch (Exception $e) {
    out(['ok' => false, 'error' => 'Не удалось подготовить таблицы: ' . $e->getMessage()], 500);
}

function stateGet(PDO $pdo, $key, $def = null) {
    try {
        $st = $pdo->prepare("SELECT value FROM notify_dispatch_state WHERE key_name = ? LIMIT 1");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? $def : $v;
    } catch (Exception $e) { return $def; }
}
function stateSet(PDO $pdo, $key, $value) {
    try {
        $st = $pdo->prepare("SELECT 1 FROM notify_dispatch_state WHERE key_name = ?");
        $st->execute([$key]);
        if ($st->fetchColumn()) {
            $pdo->prepare("UPDATE notify_dispatch_state SET value = ?, updated_at = NOW() WHERE key_name = ?")->execute([$value, $key]);
        } else {
            $pdo->prepare("INSERT INTO notify_dispatch_state (key_name, value, updated_at) VALUES (?, ?, NOW())")->execute([$key, $value]);
        }
    } catch (Exception $e) {}
}

/** Настройки доставки одного человека. Нет строки — значит по умолчанию только почта. */
function prefsOf(PDO $pdo, $userId) {
    $row = null;
    try {
        $st = $pdo->prepare("SELECT * FROM notify_prefs WHERE user_id = ? LIMIT 1");
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
    $events = ($row && $row['events']) ? (json_decode($row['events'], true) ?: []) : [];
    return [
        'email' => $row ? (bool)$row['email_enabled'] : true,
        'email_to' => $row ? ($row['email_to'] ?: null) : null,
        'telegram' => $row ? (bool)$row['telegram_enabled'] : false,
        'max' => $row ? (bool)$row['max_enabled'] : false,
        'wants' => function ($k) use ($events) { return array_key_exists($k, $events) ? (bool)$events[$k] : true; },
    ];
}

function linksOf(PDO $pdo, $userId) {
    $out = [];
    try {
        $st = $pdo->prepare("SELECT channel, chat_id FROM notify_bot_links WHERE user_id = ?");
        $st->execute([$userId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['channel']] = $r['chat_id'];
    } catch (Exception $e) {}
    return $out;
}

function alreadySent(PDO $pdo, $userId, $ref) {
    try {
        $st = $pdo->prepare("SELECT 1 FROM notify_sent_log WHERE user_id = ? AND ref = ? AND ok = 1 LIMIT 1");
        $st->execute([$userId, $ref]);
        return (bool)$st->fetchColumn();
    } catch (Exception $e) { return false; }
}
/** Сколько раз уже не получилось: чтобы навсегда сломанный канал не ретраился вечно. */
function failCount(PDO $pdo, $userId, $ref) {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM notify_sent_log WHERE user_id = ? AND ref = ? AND ok = 0");
        $st->execute([$userId, $ref]);
        return (int)$st->fetchColumn();
    } catch (Exception $e) { return 0; }
}
function logSent(PDO $pdo, $userId, $channel, $kind, $ref, $ok, $note = null) {
    try {
        $pdo->prepare("INSERT INTO notify_sent_log (user_id, channel, kind, ref, ok, note, sent_at) VALUES (?,?,?,?,?,?,NOW())")
            ->execute([$userId, $channel, $kind, $ref, $ok ? 1 : 0, $note ? mb_substr($note, 0, 500) : null]);
    } catch (Exception $e) {}
}

function userName(PDO $pdo, $id) {
    try {
        $st = $pdo->prepare("SELECT first_name, last_name, email FROM users WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $n = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
        return $n !== '' ? $n : ($u['email'] ?? 'собеседник');
    } catch (Exception $e) { return 'собеседник'; }
}

if ($action === 'status') {
    if (!$isAdmin && !$validToken) out(['ok' => false, 'error' => 'Требуется доступ'], 403);
    $recent = [];
    try {
        $st = $pdo->query("SELECT user_id, channel, kind, ok, note, sent_at FROM notify_sent_log ORDER BY id DESC LIMIT 30");
        $recent = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
    out([
        'ok' => true,
        'last_run' => stateGet($pdo, 'last_run'),
        'last_result' => json_decode((string)stateGet($pdo, 'last_result', '{}'), true),
        'token_configured' => $secretToken !== '',
        'quiet_minutes' => NOTIFY_QUIET_MIN,
        'recent' => $recent,
    ]);
}

if ($action === 'save-token' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) out(['ok' => false, 'error' => 'Только администратор'], 403);
    $token = trim((string)($body['token'] ?? ''));
    if (strlen($token) < 16) out(['ok' => false, 'error' => 'Токен слишком короткий (минимум 16 символов)'], 400);
    $php = "<?php\n// Секрет прогона уведомлений — НЕ в git.\nreturn " . var_export(['token' => $token], true) . ";\n";
    if (@file_put_contents($cfgFile, $php) === false) {
        out(['ok' => false, 'error' => 'Не удалось записать notify_dispatch_config.php (проверьте права на /api)'], 500);
    }
    @chmod($cfgFile, 0640);
    out(['ok' => true]);
}

/**
 * Один проход рассылки. Вынесен в функцию, потому что запускается двумя путями:
 * вручную/по cron (action=run) и «сам собой», когда кто-то открыл сайт (action=tick).
 */
function dispatchRun(PDO $pdo, $dry) {
    $chCfg = notify_channels_config();
    // Push — четвёртый канал наравне с почтой и Telegram. Подключаем здесь, а не
    // сверху файла: push.php сам разбирает запросы, и на других действиях он не нужен.
    if (!function_exists('push_send_to_user')) {
        $pf = __DIR__ . '/push.php';
        if (is_file($pf)) {
            // Файл при подключении не должен ничего отвечать: у него есть свой роутер,
            // поэтому просим его молчать заранее известным «действием».
            $GLOBALS['__PUSH_LIB_ONLY'] = true;
            // include внутри функции выполняется в ЕЁ области видимости, а push.php
            // объявляет на верхнем уровне свои $pdo и $userId — без этого наш $pdo
            // молча подменился бы чужим. Возвращаем свой обратно.
            $keepPdo = $pdo;
            include_once $pf;
            $pdo = $keepPdo;
        }
    }

    $since = stateGet($pdo, 'last_run');
    if (!$since) $since = date('Y-m-d H:i:s', time() - NOTIFY_LOOKBACK_H * 3600);
    $cutoff = date('Y-m-d H:i:s', time() - NOTIFY_QUIET_MIN * 60);

    // Кому что пришло: группируем по получателю и собеседнику. Ответ получателя позже
    // сообщения означает, что он его прочитал — такие пары не берём.
    $rows = [];
    try {
        $sql = "SELECT m.receiver_id, m.sender_id, COUNT(*) AS cnt, MAX(m.created_at) AS last_at
                FROM messages m
                WHERE m.created_at > ? AND m.created_at <= ?
                  AND m.receiver_id IS NOT NULL AND m.receiver_id <> m.sender_id
                  AND NOT EXISTS (
                      SELECT 1 FROM messages r
                      WHERE r.sender_id = m.receiver_id AND r.receiver_id = m.sender_id
                        AND r.created_at > m.created_at
                  )
                GROUP BY m.receiver_id, m.sender_id";
        $st = $pdo->prepare($sql);
        $st->execute([$since, $cutoff]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        out(['ok' => false, 'error' => 'Не удалось прочитать сообщения: ' . $e->getMessage()], 500);
    }

    // Складываем по получателю: одно уведомление на человека, а не на каждый диалог
    $byUser = [];
    foreach ($rows as $r) {
        $uidTo = (string)$r['receiver_id'];
        if (!isset($byUser[$uidTo])) $byUser[$uidTo] = ['total' => 0, 'from' => [], 'last_at' => ''];
        $byUser[$uidTo]['total'] += (int)$r['cnt'];
        $byUser[$uidTo]['from'][] = ['id' => $r['sender_id'], 'cnt' => (int)$r['cnt'], 'at' => $r['last_at']];
        if ($r['last_at'] > $byUser[$uidTo]['last_at']) $byUser[$uidTo]['last_at'] = $r['last_at'];
    }

    $result = ['candidates' => count($byUser), 'sent' => 0, 'skipped' => 0, 'failed' => 0, 'gave_up' => 0, 'details' => []];
    $done = 0;
    foreach ($byUser as $to => $info) {
        if ($done++ >= NOTIFY_MAX_USERS) break;
        $prefs = prefsOf($pdo, $to);
        if (!$prefs['wants']('messages')) { $result['skipped']++; continue; }
        // ref привязан к последнему сообщению — повторно про то же не напишем
        $ref = 'msg:' . $to . ':' . $info['last_at'];
        if (alreadySent($pdo, $to, $ref)) { $result['skipped']++; continue; }
        // Попытки исчерпаны — сдаёмся, но это должно быть видно, а не выглядеть как «всё чисто»
        if (failCount($pdo, $to, $ref) >= NOTIFY_MAX_RETRIES) { $result['gave_up']++; continue; }

        $names = [];
        foreach (array_slice($info['from'], 0, 3) as $f) $names[] = userName($pdo, $f['id']);
        $more = count($info['from']) - count($names);
        $who = implode(', ', $names) . ($more > 0 ? " и ещё $more" : '');
        $subject = 'Новые сообщения на psytalk.pro';
        $text = "У вас {$info['total']} новых сообщений в чатах psytalk.pro.\n"
              . "От: {$who}.\n\n"
              . "Открыть чаты: https://psytalk.pro/chat.html";

        $links = linksOf($pdo, $to);
        $anySent = false;
        $tries = [];

        if ($prefs['email']) {
            $addr = $prefs['email_to'] ?: null;
            if (!$addr) {
                try {
                    $st = $pdo->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
                    $st->execute([$to]);
                    $addr = (string)$st->fetchColumn();
                } catch (Exception $e) {}
            }
            if ($addr) {
                if ($dry) { $tries[] = ['email', true, 'dry-run']; }
                else {
                    $r = notify_send_email($chCfg, $addr, $subject, $text);
                    $tries[] = ['email', !empty($r['ok']), $r['error'] ?? ($r['via'] ?? null)];
                }
            }
        }
        if ($prefs['telegram'] && !empty($links['telegram']) && !empty($chCfg['telegram_token'])) {
            if ($dry) { $tries[] = ['telegram', true, 'dry-run']; }
            else {
                $r = notify_tg_send($chCfg['telegram_token'], $links['telegram'], $text,
                                    $chCfg['telegram_proxy'] ?? null, $chCfg['telegram_api_base'] ?? null);
                $tries[] = ['telegram', !empty($r['ok']), $r['description'] ?? $r['error'] ?? null];
            }
        }
        // Push — канал по факту подписки: человек нажал «разрешить» и подключил
        // устройство, это и есть согласие. Отдельной галочки не спрашиваем, чтобы
        // «разрешил, а не приходит» не стало новой загадкой; выключается отключением
        // устройства в настройках уведомлений.
        if (function_exists('push_send_to_user') && function_exists('push_device_count')
            && push_device_count($pdo, $to) > 0) {
            // Пуш мог уже уйти сразу после сообщения (push.php, action=poke и группы).
            // Тогда второй раз не шлём: это то же уведомление повторно, а если человек
            // к этому времени всё прочитал — и вовсе уведомление ни о чём.
            $lastPush = function_exists('push_last_ok') ? push_last_ok($pdo, $to) : null;
            if ($lastPush && $lastPush >= $info['last_at']) { $tries[] = ['push', true, 'уже доставлено сразу']; }
            elseif ($dry) { $tries[] = ['push', true, 'dry-run']; }
            else {
                $pr = push_send_to_user($pdo, $to);
                if ($pr['sent'] > 0) $tries[] = ['push', true, $pr['sent'] . ' устр.'];
                else $tries[] = ['push', false, $pr['errors'] ? implode('; ', array_slice($pr['errors'], 0, 2)) : 'служба доставки не приняла'];
            }
        }
        if ($prefs['max'] && !empty($links['max']) && !empty($chCfg['max_token'])) {
            if ($dry) { $tries[] = ['max', true, 'dry-run']; }
            else {
                $r = notify_max_send($chCfg['max_token'], $links['max'], $text, $chCfg['max_proxy'] ?? null);
                $tries[] = ['max', !empty($r['ok']), $r['error'] ?? null];
            }
        }

        if (!$tries) { $result['skipped']++; continue; }
        foreach ($tries as [$ch, $okFlag, $note]) {
            if (!$dry) logSent($pdo, $to, $ch, 'messages', $ref, $okFlag, $note);
            if ($okFlag) $anySent = true; else $result['failed']++;
        }
        if ($anySent) $result['sent']++;
        $result['details'][] = ['user' => $to, 'count' => $info['total'],
                                'channels' => array_map(fn($t) => $t[0] . ($t[1] ? '' : '!'), $tries)];
    }

    if (!$dry) {
        // Окно сдвигаем только если всё отправилось: иначе при сбое (Resend/сеть легли)
        // уведомление потерялось бы навсегда. Повторную отправку тем, кому уже дошло,
        // не сделаем — от этого защищает ref в журнале.
        if ($result['failed'] === 0) {
            stateSet($pdo, 'last_run', $cutoff);
        } else {
            $result['retry_from'] = $since;
        }
        stateSet($pdo, 'last_result', json_encode($result, JSON_UNESCAPED_UNICODE));
    }
    return ['ok' => true, 'dry' => $dry, 'since' => $since, 'cutoff' => $cutoff, 'result' => $result];
}

if ($action === 'run') {
    if (!$isAdmin && !$validToken) out(['ok' => false, 'error' => 'Требуется доступ'], 403);
    $dry = !empty($_GET['dry']) || !empty($body['dry']);
    out(dispatchRun($pdo, $dry));
}

/**
 * «Сам собой»: любой вошедший пользователь может дёрнуть проход, но не чаще, чем раз
 * в TICK_MIN_MIN минут на всю платформу. Так уведомления работают и без настроенного cron —
 * без этого человек включал каналы, а в боты и почту ничего не приходило, потому что
 * запускать проход было некому (фидбэк админа: «приходят только на пуши»).
 */
if ($action === 'tick') {
    if (!$uid) out(['ok' => false, 'error' => 'Требуется авторизация'], 401);
    $lastTick = stateGet($pdo, 'last_tick');
    if ($lastTick && strtotime($lastTick) > time() - TICK_MIN_MIN * 60) {
        out(['ok' => true, 'skipped' => 'рано', 'next_after' => date('Y-m-d H:i:s', strtotime($lastTick) + TICK_MIN_MIN * 60)]);
    }
    stateSet($pdo, 'last_tick', date('Y-m-d H:i:s'));   // ставим метку ДО прогона, чтобы не запустить два разом
    $r = dispatchRun($pdo, false);
    out(['ok' => true, 'ticked' => true, 'result' => $r['result'] ?? null]);
}

out(['ok' => false, 'error' => 'Неизвестное действие'], 400);
