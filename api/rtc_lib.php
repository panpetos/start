<?php
/**
 * rtc_lib.php — общая часть звонков: схема, уборка и сама выборка «что нового».
 *
 * Вынесено из rtc_calls.php, потому что тем же кодом пользуется api/sync.php: он
 * собирает состояние звонков вместе с остальным в ОДИН запрос, чтобы вкладка не
 * долбила сервер пятью запросами подряд. Держать выборку в двух местах нельзя —
 * разъедется.
 */

if (!function_exists('psy_align_collation')) require_once __DIR__ . '/schema_util.php';

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

/**
 * Что нового по звонкам для этого человека.
 *
 * САМЫЙ ЧАСТЫЙ ЗАПРОС НА САЙТЕ: его делает каждая открытая вкладка, постоянно.
 * Поэтому в спокойном состоянии (звонка нет) он стоит РОВНО ОДИН запрос к базе.
 * Раньше их было четыре плюс три записи на уборку — база не выдерживала.
 *
 * Всё, что нужно клиенту, забираем одной выборкой: входящий вызов, мой текущий
 * звонок и только что завершившийся. Каждая часть UNION идёт по своему индексу
 * (to_id+status, from_id+status) и берёт максимум одну строку.
 */
function rtcPollFor($pdo, $userId, $after = 0) {
    $after = (int)$after;
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

    $incoming = null; $active = null; $recentEnd = null; $stale = false;
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

    return ['incoming' => $incoming, 'active' => $active,
            'signals' => $signals, 'recent_end' => $recentEnd];
}
