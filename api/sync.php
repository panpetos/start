<?php
/**
 * sync.php — один запрос вместо пяти.
 *
 * ЗАЧЕМ. Открытая вкладка чата раньше опрашивала сервер пятью отдельными
 * запросами: сообщения, список диалогов, «кто онлайн», «я здесь» и звонки. Каждый
 * из них — это отдельное соединение, запуск PHP, чтение сессии и подключение к базе;
 * сама полезная работа занимает единицы миллисекунд, а вся эта обвязка — почти
 * двести. Пять запросов в пять секунд с каждой вкладки и складывались в нагрузку,
 * из-за которой сайт лёг.
 *
 * ЧТО ДЕЛАЕМ. Собираем всё за один заход. И главное: тяжёлые списки НЕ отдаём.
 * Вместо них считаем короткую «отметку версии» (сколько сообщений и когда было
 * последнее) — если она не изменилась, браузеру перерисовывать нечего и он не пойдёт
 * за списком вовсе. В спокойной переписке тяжёлых запросов не будет ни одного.
 *
 * POST /api/sync.php
 *   {
 *     peer:      "<id собеседника>" | "g:<id группы>" | "favorites" | null,
 *     peers:     ["id", ...],     // чьё присутствие показать
 *     typing_to: "<id>" | "",     // «я сейчас печатаю» — заодно и есть сигнал «я здесь»
 *     rtc_after: <int>,           // последний разобранный сигнал звонка
 *     rev:       { msg: "...", conv: "..." }   // что у браузера уже есть
 *   }
 * Ответ: presence, rtc, rev + changed (что реально изменилось).
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';
if (!function_exists('getDB') && !function_exists('getDbConnection') && !function_exists('getPDO')) {
    require_once __DIR__ . '/db.php';
}
$pdo = function_exists('getDB') ? getDB()
     : (function_exists('getDbConnection') ? getDbConnection()
     : (function_exists('getPDO') ? getPDO() : null));
if (!$pdo) { http_response_code(500); echo json_encode(['error' => 'Нет подключения к БД']); exit; }

if (session_status() === PHP_SESSION_NONE) session_start();
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Требуется авторизация']); exit; }

require_once __DIR__ . '/rtc_lib.php';

const SYNC_ONLINE_WINDOW = 70;   // столько секунд без сигнала человек ещё «в сети»
const SYNC_TYPING_TTL    = 7;    // столько живёт отметка «печатает»

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$now  = date('Y-m-d H:i:s');
$out  = ['ok' => true, 'now' => $now, 'rev' => [], 'changed' => []];

/**
 * Индексы под отметки версии.
 *
 * Отметка считается на КАЖДОМ опросе каждой вкладки, поэтому она обязана идти по
 * индексу. Без индексов по sender_id и receiver_id это полный перебор таблицы
 * сообщений — ровно та ошибка, из-за которой сайт уже ложился однажды. Сейчас
 * сообщений сотни и индексы добавляются мгновенно; на миллионе строк это была бы
 * долгая блокирующая операция, так что делаем сразу и один раз в сутки проверяем.
 */
function syncEnsureIndexes(PDO $pdo) {
    psy_schema_once('messages_rev_idx_v1', 86400, function () use ($pdo) {
        try {
            $have = [];
            foreach ($pdo->query("SHOW INDEX FROM messages")->fetchAll(PDO::FETCH_ASSOC) as $r)
                $have[strtolower($r['Key_name'])] = true;
            if (!isset($have['idx_sync_sender']))
                try { $pdo->exec("ALTER TABLE messages ADD INDEX idx_sync_sender (sender_id, created_at)"); } catch (Exception $e) {}
            if (!isset($have['idx_sync_receiver']))
                try { $pdo->exec("ALTER TABLE messages ADD INDEX idx_sync_receiver (receiver_id, created_at)"); } catch (Exception $e) {}
        } catch (Exception $e) { /* нет прав на ALTER — отметки просто будут дороже */ }
    });
}

/**
 * «Отметка версии» набора строк: сколько их и когда была последняя правка.
 * Считается по индексам и не тащит наружу ни одного сообщения. Если отметка
 * совпала с той, что уже есть у браузера, — значит, ничего не изменилось.
 */
function syncRev(PDO $pdo, $sql, array $args) {
    try {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        $r = $st->fetch(PDO::FETCH_NUM);
        return ((int)($r[0] ?? 0)) . '|' . (string)($r[1] ?? '') . '|' . (string)($r[2] ?? '');
    } catch (Exception $e) { return null; }
}

/**
 * Есть ли такая колонка — схемы у разных установок немного разные.
 *
 * Раньше здесь был prepare("SHOW COLUMNS FROM `t` LIKE ?"). На боевой базе
 * подготовка идёт на стороне сервера (emulate_prepares выключен), а MySQL не
 * принимает подстановку в SHOW: запрос падал с ошибкой синтаксиса, catch её глушил,
 * и функция ВСЕГДА отвечала «колонки нет». Здесь из-за этого в открытой переписке
 * не отслеживалось время правки — исправленный текст не обновлялся у собеседника.
 * Тот же приём стоил дороже в messages_page.php: оттуда молча пропадали вложения.
 * Спрашиваем список колонок целиком, без подстановок, и запоминаем на запрос.
 */
function syncHasColumn(PDO $pdo, $table, $col) {
    static $memo = [];
    if (!isset($memo[$table])) {
        $memo[$table] = [];
        try {
            foreach ($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $name = $r['Field'] ?? ($r['field'] ?? null);
                if ($name !== null) $memo[$table][] = (string)$name;
            }
        } catch (Exception $e) { $memo[$table] = []; }
    }
    return in_array($col, $memo[$table], true);
}

// ── 1. «Я здесь» и «я печатаю» ────────────────────────────────────────────────
// Это единственная запись за весь запрос. Раньше она шла отдельным обращением к
// presence.php раз в 30 секунд.
$typingTo = trim((string)($body['typing_to'] ?? ''));
try {
    $st = $pdo->prepare("INSERT INTO user_presence (user_id, last_seen, typing_to, typing_at)
                         VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen),
                                                typing_to = VALUES(typing_to),
                                                typing_at = VALUES(typing_at)");
    $st->execute([$userId, $now, $typingTo !== '' ? $typingTo : null, $typingTo !== '' ? $now : null]);
} catch (Exception $e) { /* таблицу заведёт presence.php — без нас никто не пострадает */ }

// ── 2. Кто в сети и кто печатает ──────────────────────────────────────────────
$peers = array_values(array_filter(array_map('trim', (array)($body['peers'] ?? [])), function ($p) { return $p !== ''; }));
$peers = array_slice(array_unique($peers), 0, 100);
$presence = [];
if ($peers) {
    $in = implode(',', array_fill(0, count($peers), '?'));
    try {
        $st = $pdo->prepare("SELECT user_id, last_seen, typing_to, typing_at, hide_last_seen
                             FROM user_presence WHERE user_id IN ($in)");
        $st->execute($peers);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $seen = strtotime((string)$r['last_seen']);
            $typing = $r['typing_to'] !== null && $r['typing_at']
                   && strtotime((string)$r['typing_at']) > time() - SYNC_TYPING_TTL;
            $hidden = !empty($r['hide_last_seen']);
            $presence[(string)$r['user_id']] = [
                'online'     => $hidden ? false : ($seen > time() - SYNC_ONLINE_WINDOW),
                'last_seen'  => $hidden ? null : $r['last_seen'],
                'hidden'     => $hidden,
                'typing_to'  => $typing ? (string)$r['typing_to'] : null,
            ];
        }
    } catch (Exception $e) {}
    try {
        $st = $pdo->prepare("SELECT user_id, last_read_at FROM chat_reads WHERE peer_id = ? AND user_id IN ($in)");
        $st->execute(array_merge([$userId], $peers));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $u = (string)$r['user_id'];
            if (!isset($presence[$u])) $presence[$u] = ['online' => false, 'last_seen' => null, 'typing_to' => null];
            $presence[$u]['read_until'] = $r['last_read_at'];
        }
    } catch (Exception $e) {}
}
$out['presence'] = $presence;

// ── 3. Звонки ─────────────────────────────────────────────────────────────────
try { $out['rtc'] = rtcPollFor($pdo, $userId, $body['rtc_after'] ?? 0); }
catch (Exception $e) { $out['rtc'] = ['incoming' => null, 'active' => null, 'signals' => [], 'recent_end' => null]; }

// ── 4. Изменилось ли что-то в переписке и в списке диалогов ───────────────────
$peer = trim((string)($body['peer'] ?? ''));
$revIn = (array)($body['rev'] ?? []);

syncEnsureIndexes($pdo);

// Список диалогов: любое моё сообщение в любую сторону. Условие «sender ИЛИ receiver»
// в одном запросе не даёт MySQL воспользоваться индексом — считаем двумя отдельными
// выборками, каждая по своему индексу, и складываем.
$out['rev']['conv'] = syncRev($pdo,
    "SELECT (SELECT COUNT(*) FROM messages WHERE sender_id = ?)
          + (SELECT COUNT(*) FROM messages WHERE receiver_id = ?),
            GREATEST(COALESCE((SELECT MAX(created_at) FROM messages WHERE sender_id = ?), '0'),
                     COALESCE((SELECT MAX(created_at) FROM messages WHERE receiver_id = ?), '0')),
            NULL",
    [$userId, $userId, $userId, $userId]);

if ($peer !== '' && strpos($peer, 'g:') === 0) {
    $gid = substr($peer, 2);
    $out['rev']['msg'] = syncRev($pdo,
        "SELECT COUNT(*), MAX(created_at), NULL FROM chat_group_messages WHERE group_id = ?", [$gid]);
} elseif ($peer === 'favorites') {
    $out['rev']['msg'] = syncRev($pdo,
        "SELECT COUNT(*), MAX(created_at), NULL FROM favorite_messages WHERE user_id = ?", [$userId]);
} elseif ($peer !== '') {
    // Та же причина: две выборки по индексам вместо одного условия с ИЛИ.
    // Правку сообщения счётчиком не поймать, поэтому в ОТКРЫТОЙ переписке берём ещё и
    // время последней правки — строк там немного, а без этого исправленный текст
    // не обновился бы у собеседника.
    $ed = syncHasColumn($pdo, 'messages', 'edited_at') ? 'edited_at'
        : (syncHasColumn($pdo, 'messages', 'updated_at') ? 'updated_at' : null);
    $edSel = $ed
        ? "GREATEST(COALESCE((SELECT MAX($ed) FROM messages WHERE sender_id = ? AND receiver_id = ?), '0'),
                    COALESCE((SELECT MAX($ed) FROM messages WHERE sender_id = ? AND receiver_id = ?), '0'))"
        : 'NULL';
    $args = [$userId, $peer, $peer, $userId, $userId, $peer, $peer, $userId];
    if ($ed) array_push($args, $userId, $peer, $peer, $userId);
    $out['rev']['msg'] = syncRev($pdo,
        "SELECT (SELECT COUNT(*) FROM messages WHERE sender_id = ? AND receiver_id = ?)
              + (SELECT COUNT(*) FROM messages WHERE sender_id = ? AND receiver_id = ?),
                GREATEST(COALESCE((SELECT MAX(created_at) FROM messages WHERE sender_id = ? AND receiver_id = ?), '0'),
                         COALESCE((SELECT MAX(created_at) FROM messages WHERE sender_id = ? AND receiver_id = ?), '0')),
                $edSel",
        $args);
}

foreach (['conv', 'msg'] as $k) {
    if (!isset($out['rev'][$k])) continue;
    // null означает «посчитать не вышло» — тогда честно говорим «изменилось»,
    // чтобы браузер сходил за списком сам и ничего не пропустил
    $out['changed'][$k] = ($out['rev'][$k] === null) || ($out['rev'][$k] !== (string)($revIn[$k] ?? ''));
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
