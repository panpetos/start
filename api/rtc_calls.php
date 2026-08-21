<?php
/**
 * rtc_calls.php — свои аудио- и видеозвонки прямо на сайте, без Яндекс.Телемоста.
 *
 * КАК ЭТО РАБОТАЕТ И ПОЧЕМУ ТАК
 * Звук и видео идут НАПРЯМУЮ между браузерами (WebRTC, peer-to-peer) и через наш
 * сервер не проходят вообще. Хостинг переносит только «знакомство» собеседников:
 * несколько небольших JSON-строк с описанием соединения (SDP) и сетевыми
 * кандидатами (ICE). Для shared-хостинга это копейки — ни одного байта медиа.
 *
 * Шифрование включено всегда и его нельзя отключить: WebRTC обязывает шифровать
 * поток (DTLS-SRTP). Ключи вырабатываются самими браузерами, серверу они не
 * известны, поэтому прослушать разговор со стороны сайта нельзя.
 *
 * ОГРАНИЧЕНИЕ, О КОТОРОМ НУЖНО ЗНАТЬ
 * Если ОБА собеседника сидят за «строгим» NAT (symmetric NAT — часто у мобильных
 * операторов и в корпоративных сетях), прямое соединение не устанавливается: нужен
 * ретранслятор TURN. TURN — это отдельный сервер с открытыми UDP-портами, на
 * shared-хостинге его не поднять. Поэтому:
 *   • по умолчанию используем только STUN (бесплатно, ничего поднимать не нужно) —
 *     этого хватает большинству пар;
 *   • если у платформы появится TURN, его адрес и логин кладутся в
 *     api/rtc_calls_config.php (см. rtc_calls_config.sample.php) — код менять не придётся;
 *   • когда соединиться не удалось, клиент честно говорит об этом и предлагает
 *     Телемост как запасной путь.
 *
 * GET  ?action=ice                        — какие STUN/TURN отдавать браузеру
 * GET  ?action=poll[&after=N]             — входящие звонки и новые сигналы
 * POST ?action=start   {to, kind}         — позвонить (kind: audio|video)
 * POST ?action=signal  {call_id, kind, payload}  — offer/answer/ice
 * POST ?action=accept  {call_id}
 * POST ?action=decline {call_id}
 * POST ?action=end     {call_id, reason}
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';
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
if (!$userId) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Требуется авторизация']); exit; }

function out($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

const RING_TIMEOUT   = 45;     // столько звоним, потом «не ответил»
const MAX_PER_HOUR   = 40;     // защита от спама звонками
const SIGNAL_MAX     = 200000; // потолок на один сигнал (SDP бывает крупным)

/**
 * ВАЖНО ПРО НАГРУЗКУ. Раньше на КАЖДЫЙ запрос выполнялись два CREATE TABLE IF NOT
 * EXISTS и выравнивание кодировок (а это обращения к information_schema). При опросе
 * звонков раз в несколько секунд с каждой открытой вкладки это давало постоянный
 * поток служебных запросов и блокировок — база не справлялась, и сайт отдавал 504.
 *
 * Теперь схема создаётся ЛЕНИВО: обычный запрос её не касается вовсе, а если таблицы
 * действительно нет (первый запуск), ловим ошибку «нет такой таблицы» и создаём.
 */
function rtcEnsureSchema($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS rtc_calls (
            id INT AUTO_INCREMENT PRIMARY KEY,
            from_id VARCHAR(64) NOT NULL,
            to_id VARCHAR(64) NOT NULL,
            kind VARCHAR(10) NOT NULL DEFAULT 'video',
            status VARCHAR(16) NOT NULL DEFAULT 'ringing',
            end_reason VARCHAR(40) NULL,
            created_at DATETIME NOT NULL,
            answered_at DATETIME NULL,
            ended_at DATETIME NULL,
            INDEX idx_to (to_id, status),
            INDEX idx_from (from_id, status),
            INDEX idx_created (created_at)
        ) DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS rtc_call_signals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            call_id INT NOT NULL,
            sender_id VARCHAR(64) NOT NULL,
            kind VARCHAR(12) NOT NULL,
            payload MEDIUMTEXT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_call (call_id, id),
            INDEX idx_created (created_at)
        ) DEFAULT CHARSET=utf8mb4");
        // Уборка старых сигналов шла по created_at без индекса — полный перебор
        // таблицы. Добавляем индекс существующим установкам.
        try { $pdo->exec("ALTER TABLE rtc_call_signals ADD INDEX idx_created (created_at)"); } catch (Exception $e) {}
        psy_align_collation($pdo, ['rtc_calls', 'rtc_call_signals']);
    } catch (Exception $e) {}
}

/** Нет таблицы? Создаём один раз и повторяем запрос. */
function rtcTry($pdo, callable $fn) {
    try {
        return $fn();
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), '42S02') === false &&
            stripos($e->getMessage(), "doesn't exist") === false) throw $e;
        rtcEnsureSchema($pdo);
        return $fn();
    }
}

/**
 * Незакрытые «звонит…» дольше RING_TIMEOUT — это неотвеченный вызов.
 *
 * Раньше эти ТРИ ЗАПИСИ выполнялись на каждом опросе, то есть несколько раз в
 * секунду при нескольких открытых вкладках, — именно это и положило базу. Теперь
 * уборка идёт не чаще раза в минуту на всю платформу: перед ней стоит дешёвая
 * проверка по одной строке, а сами удаления вынесены в отдельный редкий проход.
 */
function expireStale($pdo, $force = false) {
    try {
        if (!$force) {
            $st = $pdo->query("SELECT v FROM scheduled_state WHERE k = 'rtc_sweep'");
            $last = $st ? $st->fetchColumn() : null;
            if ($last && strtotime($last) > time() - 60) return;   // недавно уже убирались
            $pdo->prepare("INSERT INTO scheduled_state (k, v) VALUES ('rtc_sweep', ?)
                           ON DUPLICATE KEY UPDATE v = VALUES(v)")
                ->execute([date('Y-m-d H:i:s')]);
        }
        $pdo->prepare("UPDATE rtc_calls SET status = 'ended', end_reason = 'missed', ended_at = NOW()
                       WHERE status = 'ringing' AND created_at < (NOW() - INTERVAL ? SECOND)")
            ->execute([RING_TIMEOUT]);
        $pdo->exec("DELETE FROM rtc_call_signals WHERE created_at < (NOW() - INTERVAL 1 DAY)");
        $pdo->exec("DELETE FROM rtc_calls WHERE created_at < (NOW() - INTERVAL 7 DAY)");
    } catch (Exception $e) { /* нет таблицы состояния — уборка подождёт */ }
}

function loadCall($pdo, $id) {
    $st = $pdo->prepare("SELECT * FROM rtc_calls WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Участвует ли человек в этом звонке — чужие сигналы читать и писать нельзя. */
function isParty($call, $userId) {
    return $call && ((string)$call['from_id'] === (string)$userId || (string)$call['to_id'] === (string)$userId);
}

$action = $_GET['action'] ?? '';
// Схему проверяем только у редких действий. Опрос (самый частый запрос) её не
// касается: если таблицы вдруг нет, rtcTry поймает ошибку и создаст её один раз.
if ($action !== 'poll' && $action !== 'ice') rtcEnsureSchema($pdo);
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

try {
    if ($action === 'ice') {
        // Свой TURN, если он у платформы появится, кладётся в calls_config.php.
        // Без него остаётся STUN — его достаточно, пока хотя бы один из собеседников
        // не за «строгим» NAT.
        $servers = [['urls' => ['stun:stun.l.google.com:19302', 'stun:stun1.l.google.com:19302']]];
        $cfg = __DIR__ . '/rtc_calls_config.php';
        if (file_exists($cfg)) {
            $turn = include $cfg;
            if (is_array($turn) && !empty($turn['turn_urls'])) {
                $one = ['urls' => $turn['turn_urls']];
                if (!empty($turn['turn_username'])) $one['username'] = $turn['turn_username'];
                if (!empty($turn['turn_credential'])) $one['credential'] = $turn['turn_credential'];
                $servers[] = $one;
            }
        }
        out(['ok' => true, 'ice_servers' => $servers, 'has_turn' => count($servers) > 1]);
    }

    if ($action === 'poll') {
        // САМЫЙ ЧАСТЫЙ ЗАПРОС НА САЙТЕ: его делает каждая открытая вкладка, постоянно.
        // Поэтому в спокойном состоянии (звонка нет) он стоит РОВНО ОДИН запрос к базе.
        // Раньше их было четыре плюс три записи на уборку — база не выдерживала.
        //
        // Всё, что нужно клиенту, забираем одной выборкой: входящий вызов, мой текущий
        // звонок и только что завершившийся. Каждая часть UNION идёт по своему индексу
        // (to_id+status, from_id+status) и берёт максимум одну строку.
        $after = (int)($_GET['after'] ?? 0);
        $cols = "c.*, u.first_name, u.last_name, u.avatar";
        $join = "rtc_calls c LEFT JOIN users u ON u.id = c.from_id";
        $sql = "(SELECT $cols, 'in' AS slot FROM $join
                  WHERE c.to_id = ? AND c.status IN ('ringing','accepted') ORDER BY c.id DESC LIMIT 1)
                UNION ALL
                (SELECT $cols, 'out' AS slot FROM $join
                  WHERE c.from_id = ? AND c.status IN ('ringing','accepted') ORDER BY c.id DESC LIMIT 1)
                UNION ALL
                (SELECT $cols, 'end' AS slot FROM $join
                  WHERE c.to_id = ? AND c.status = 'ended'
                    AND c.ended_at > (NOW() - INTERVAL 30 SECOND) ORDER BY c.id DESC LIMIT 1)
                UNION ALL
                (SELECT $cols, 'end' AS slot FROM $join
                  WHERE c.from_id = ? AND c.status = 'ended'
                    AND c.ended_at > (NOW() - INTERVAL 30 SECOND) ORDER BY c.id DESC LIMIT 1)";
        $rows = rtcTry($pdo, function () use ($pdo, $sql, $userId) {
            $st = $pdo->prepare($sql);
            $st->execute([$userId, $userId, $userId, $userId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        });

        $incoming = null; $active = null; $recentEnd = null;
        $stale = false;
        foreach ($rows as $r) {
            $slot = $r['slot']; unset($r['slot']);
            if ($slot === 'end') {
                if (!$recentEnd || (int)$r['id'] > (int)$recentEnd['id'])
                    $recentEnd = ['id' => (int)$r['id'], 'status' => $r['status'], 'end_reason' => $r['end_reason']];
                continue;
            }
            // «звонит…» дольше таймаута — на самом деле уже пропущенный
            if ($r['status'] === 'ringing' && strtotime($r['created_at']) < time() - RING_TIMEOUT) {
                // отдаём как завершённый сразу, не дожидаясь уборки, — иначе окно
                // звонка у собеседника осталось бы висеть до следующего прохода
                $stale = true;
                if (!$recentEnd || (int)$r['id'] > (int)$recentEnd['id'])
                    $recentEnd = ['id' => (int)$r['id'], 'status' => 'ended', 'end_reason' => 'missed'];
                continue;
            }
            if ($slot === 'in' && $r['status'] === 'ringing') $incoming = $r;
            if (!$active || (int)$r['id'] > (int)$active['id']) $active = $r;
        }

        // Уборку запускаем, только когда она реально нужна (висит просроченный вызов)
        // либо изредка «за компанию» — чтобы старые записи не копились. Внутри стоит
        // ещё и общий на всю платформу ограничитель «не чаще раза в минуту».
        if ($stale || mt_rand(1, 50) === 1) expireStale($pdo);

        $signals = [];
        if ($active) {
            // свои же сигналы обратно не отдаём
            $signals = rtcTry($pdo, function () use ($pdo, $active, $after, $userId) {
                $sg = $pdo->prepare("SELECT id, kind, payload, sender_id FROM rtc_call_signals
                                     WHERE call_id = ? AND id > ? AND sender_id <> ?
                                     ORDER BY id ASC LIMIT 100");
                $sg->execute([$active['id'], $after, $userId]);
                return $sg->fetchAll(PDO::FETCH_ASSOC) ?: [];
            });
        }

        out(['ok' => true, 'incoming' => $incoming, 'active' => $active,
             'signals' => $signals, 'recent_end' => $recentEnd]);
    }

    if ($action === 'start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $to = trim((string)($body['to'] ?? ''));
        $kind = ($body['kind'] ?? 'video') === 'audio' ? 'audio' : 'video';
        if ($to === '') out(['ok' => false, 'error' => 'Не указан собеседник'], 400);
        if ((string)$to === (string)$userId) out(['ok' => false, 'error' => 'Нельзя позвонить самому себе'], 400);
        $ex = $pdo->prepare("SELECT 1 FROM users WHERE id = ?");
        $ex->execute([$to]);
        if (!$ex->fetchColumn()) out(['ok' => false, 'error' => 'Такого собеседника нет'], 404);

        expireStale($pdo);
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM rtc_calls WHERE from_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)");
        $cnt->execute([$userId]);
        if ((int)$cnt->fetchColumn() >= MAX_PER_HOUR)
            out(['ok' => false, 'error' => 'Слишком много звонков за час — попробуйте позже'], 429);

        // старые свои незакрытые вызовы закрываем, чтобы не было двух сразу
        $pdo->prepare("UPDATE rtc_calls SET status = 'ended', end_reason = 'replaced', ended_at = NOW()
                       WHERE from_id = ? AND status IN ('ringing','accepted')")->execute([$userId]);

        $pdo->prepare("INSERT INTO rtc_calls (from_id, to_id, kind, status, created_at)
                       VALUES (?, ?, ?, 'ringing', NOW())")->execute([$userId, $to, $kind]);
        out(['ok' => true, 'call_id' => (int)$pdo->lastInsertId(), 'kind' => $kind]);
    }

    if ($action === 'signal' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $callId = (int)($body['call_id'] ?? 0);
        $kind = (string)($body['kind'] ?? '');
        if (!in_array($kind, ['offer', 'answer', 'ice'], true)) out(['ok' => false, 'error' => 'Неизвестный сигнал'], 400);
        $payload = (string)($body['payload'] ?? '');
        if (strlen($payload) > SIGNAL_MAX) out(['ok' => false, 'error' => 'Слишком большой сигнал'], 400);
        $call = loadCall($pdo, $callId);
        if (!isParty($call, $userId)) out(['ok' => false, 'error' => 'Звонок недоступен'], 403);
        if ($call['status'] === 'ended') out(['ok' => false, 'error' => 'Звонок уже завершён'], 409);
        $pdo->prepare("INSERT INTO rtc_call_signals (call_id, sender_id, kind, payload, created_at)
                       VALUES (?, ?, ?, ?, NOW())")->execute([$callId, $userId, $kind, $payload]);
        out(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    }

    if ($action === 'accept' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $call = loadCall($pdo, (int)($body['call_id'] ?? 0));
        if (!$call || (string)$call['to_id'] !== (string)$userId) out(['ok' => false, 'error' => 'Звонок недоступен'], 403);
        if ($call['status'] !== 'ringing') out(['ok' => false, 'error' => 'Звонок уже не активен'], 409);
        $pdo->prepare("UPDATE rtc_calls SET status = 'accepted', answered_at = NOW() WHERE id = ?")->execute([$call['id']]);
        out(['ok' => true]);
    }

    if ($action === 'decline' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $call = loadCall($pdo, (int)($body['call_id'] ?? 0));
        if (!isParty($call, $userId)) out(['ok' => false, 'error' => 'Звонок недоступен'], 403);
        $pdo->prepare("UPDATE rtc_calls SET status = 'ended', end_reason = 'declined', ended_at = NOW()
                       WHERE id = ? AND status <> 'ended'")->execute([$call['id']]);
        out(['ok' => true]);
    }

    if ($action === 'end' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $call = loadCall($pdo, (int)($body['call_id'] ?? 0));
        if (!isParty($call, $userId)) out(['ok' => false, 'error' => 'Звонок недоступен'], 403);
        $reason = substr((string)($body['reason'] ?? 'hangup'), 0, 40);
        $pdo->prepare("UPDATE rtc_calls SET status = 'ended', end_reason = ?, ended_at = NOW()
                       WHERE id = ? AND status <> 'ended'")->execute([$reason, $call['id']]);
        out(['ok' => true]);
    }

    out(['ok' => false, 'error' => 'Неизвестное действие'], 400);
} catch (Exception $e) {
    out(['ok' => false, 'error' => $e->getMessage()], 500);
}
