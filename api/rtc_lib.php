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
            logged TINYINT NOT NULL DEFAULT 0,
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
        // Отметка «о звонке уже написали в переписку» — у тех, чьи таблицы созданы
        // прошлой версией, этой колонки ещё нет.
        try { $pdo->exec("ALTER TABLE rtc_calls ADD COLUMN logged TINYINT NOT NULL DEFAULT 0"); } catch (Exception $e) {}
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
        // Просроченные «звонит…» помечаем пропущенными и про каждый оставляем запись
        // в переписке — поэтому берём их списком, а не одним общим UPDATE.
        $st = $pdo->prepare("SELECT id FROM rtc_calls
                              WHERE status = 'ringing' AND created_at < (NOW() - INTERVAL ? SECOND) LIMIT 50");
        $st->execute([RING_TIMEOUT]);
        $lost = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if ($lost) {
            $in = implode(',', array_fill(0, count($lost), '?'));
            $pdo->prepare("UPDATE rtc_calls SET status = 'ended', end_reason = 'missed', ended_at = NOW()
                           WHERE id IN ($in)")->execute($lost);
            foreach ($lost as $id) rtcLogCall($pdo, $id);
        }
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
    // Просроченный вызов есть — убираемся сразу, не дожидаясь ограничителя: иначе
    // запись о пропущенном появилась бы в переписке с опозданием до минуты.
    if ($stale || mt_rand(1, 50) === 1) expireStale($pdo, $stale);

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


/**
 * Запись о звонке в переписке.
 *
 * Раньше звонок не оставлял следа: не дозвонился — и непонятно, звонили тебе или нет;
 * поговорили — и в переписке пусто. Теперь каждый завершённый звонок оставляет строку
 * в той же переписке, как в телефоне и в мессенджерах.
 *
 * Пишем ровно один раз на звонок: завершить его могут обе стороны разом, поэтому
 * запись «занимается» одним UPDATE с проверкой отметки — кто успел, тот и пишет.
 *
 * Текст начинается с «☎️ » — по этому признаку страница рисует его отдельной плашкой
 * посередине, а не обычным пузырём. Если разбирать некому (список диалогов, письмо,
 * push), текст и так читается по-человечески.
 */
function rtcLogCall(PDO $pdo, $callId) {
    $callId = (int)$callId;
    if (!$callId) return;
    try {
        $claim = $pdo->prepare("UPDATE rtc_calls SET logged = 1 WHERE id = ? AND (logged = 0 OR logged IS NULL)");
        try { $claim->execute([$callId]); }
        catch (PDOException $e) { rtcEnsureSchema($pdo); $claim->execute([$callId]); }
        if (!$claim->rowCount()) return;          // про этот звонок уже написали

        $call = loadCall($pdo, $callId);
        if (!$call) return;
        $reason = (string)($call['end_reason'] ?? '');
        if ($reason === 'replaced') return;       // технический перезапуск, человеку не событие

        $answered = !empty($call['answered_at']);
        if ($answered) {
            $sec = strtotime((string)($call['ended_at'] ?: 'now')) - strtotime((string)$call['answered_at']);
            $text = '☎️ Звонок · ' . rtcDurWords($sec);
        } else {
            switch ($reason) {
                case 'declined': $text = '☎️ Звонок отклонён'; break;
                case 'missed':   $text = '☎️ Пропущенный звонок'; break;
                case 'hangup':   $text = '☎️ Отменённый звонок'; break;
                case 'fallback': $text = '☎️ Звонок не состоялся — перешли в Телемост'; break;
                default:         $text = '☎️ Звонок не состоялся';
            }
        }
        // Разговор состоялся — оба были на связи, «непрочитанным» это делать незачем.
        rtcSendDm($pdo, $call['from_id'], $call['to_id'], $text, $answered);
    } catch (Exception $e) { /* переписка без записи — не повод ронять звонок */ }
}

/** «45 с», «3 мин 01 с», «1 ч 02 мин» — как показывают длительность в телефоне. */
function rtcDurWords($sec) {
    $sec = max(0, (int)$sec);
    if ($sec < 60) return $sec . ' с';
    $m = intdiv($sec, 60); $s = $sec % 60;
    if ($m < 60) return $m . ' мин ' . str_pad((string)$s, 2, '0', STR_PAD_LEFT) . ' с';
    $h = intdiv($m, 60); $m = $m % 60;
    return $h . ' ч ' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . ' мин';
}

/**
 * Написать в личную переписку. Таблицу messages мы не заводим — она серверная, и в
 * разных установках ключ то автоинкрементный, то строковый: спрашиваем у базы, а не
 * гадаем (тот же приём, что в api/stories.php).
 */
/**
 * Запись о звонке в переписку.
 *
 * $read — считать ли её сразу прочитанной. Для СОСТОЯВШЕГОСЯ разговора это
 * правильно: оба только что были на связи, и счётчик «+1 новое» сразу после
 * того, как положили трубку, только сбивает с толку. Пропущенный, отклонённый и
 * отменённый звонок, наоборот, остаются непрочитанными — это и есть новость.
 */
function rtcSendDm(PDO $pdo, $from, $to, $content, $read = false) {
    static $auto = null;
    static $hasRead = null;
    if ($auto === null) {
        $auto = false;
        try {
            $r = $pdo->query("SHOW COLUMNS FROM messages LIKE 'id'");
            $c = $r ? $r->fetch(PDO::FETCH_ASSOC) : null;
            $auto = $c && stripos((string)($c['Extra'] ?? ''), 'auto_increment') !== false;
        } catch (Exception $e) {}
    }
    if ($hasRead === null) {
        // Список колонок целиком, без подстановки: prepare("SHOW ... LIKE ?") на
        // боевой базе падает с ошибкой синтаксиса (подстановка в SHOW не проходит).
        $hasRead = false;
        try {
            foreach ($pdo->query("SHOW COLUMNS FROM messages")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                if ((string)($c['Field'] ?? '') === 'is_read') { $hasRead = true; break; }
            }
        } catch (Exception $e) {}
    }
    $now = date('Y-m-d H:i:s');
    $rd = $hasRead ? ', is_read' : '';
    $rv = $hasRead ? ', ?' : '';
    try {
        if ($auto) {
            $args = [$from, $to, $content, $now];
            if ($hasRead) $args[] = $read ? 1 : 0;
            $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, content, created_at$rd) VALUES (?, ?, ?, ?$rv)")
                ->execute($args);
        } else {
            $args = [bin2hex(random_bytes(16)), $from, $to, $content, $now];
            if ($hasRead) $args[] = $read ? 1 : 0;
            $pdo->prepare("INSERT INTO messages (id, sender_id, receiver_id, content, created_at$rd) VALUES (?, ?, ?, ?, ?$rv)")
                ->execute($args);
        }
    } catch (Exception $e) {}
}
