<?php
/**
 * sessions.php — жизненный цикл оплаченной сессии.
 *
 * Поток: оплачено (scheduled) → психолог ПРИНЯЛ (confirmed) → НАЧАЛ (in_progress)
 *        → ЗАВЕРШИЛ (completed). Отказ/просрочка принятия → cancelled + очередь возврата.
 *
 * Зачем отдельно: приём оплаты (robokassa.php) только помечает запись scheduled;
 * принятие заказа, начало/конец и завершение раньше нигде не фиксировались, а
 * вознаграждение психологу должно начисляться именно по ЗАВЕРШЁННОЙ сессии
 * (payouts.php accrue'ит только appointments.status='completed').
 *
 * Авто-подстраховка (sessionsAutoTick, дёргается на list — реального cron на shared нет):
 *   • scheduled, время начала прошло (+грейс) и психолог не принял → cancelled + возврат;
 *   • confirmed/in_progress, плановый конец прошёл (+грейс) → completed (психолог забыл кнопки).
 * Это ПОБОЧНАЯ работа: своя функция со своим try/catch, молчит при любой ошибке и
 * никогда не ломает основной ответ (правило из CLAUDE.md).
 *
 * Действия:
 *   GET  ?action=list                       — сессии психолога (нужные к действию + недавние)
 *   POST ?action=accept  {appointment_id}   — принять заказ (scheduled→confirmed)
 *   POST ?action=decline {appointment_id,reason?} — отказаться (scheduled→cancelled + возврат)
 *   POST ?action=start   {appointment_id}   — начать (confirmed→in_progress)
 *   POST ?action=end     {appointment_id}   — завершить (in_progress/confirmed→completed)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';
if (!function_exists('getDB') && !function_exists('getDbConnection') && !function_exists('getPDO')) {
    require_once __DIR__ . '/db.php';
}
require_once __DIR__ . '/settings_lib.php';
if (!function_exists('psy_schema_once')) require_once __DIR__ . '/schema_util.php';
@include_once __DIR__ . '/rtc_lib.php'; // rtcSendDm — уведомления участникам в чат (шлёт пуш)

$pdo = function_exists('getDB') ? getDB()
     : (function_exists('getDbConnection') ? getDbConnection()
     : (function_exists('getPDO') ? getPDO() : null));
if (!$pdo) { http_response_code(500); echo json_encode(['error' => 'Нет подключения к БД']); exit; }

function seOut($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

// ── Схема: колонки жизненного цикла в appointments + очередь возвратов ───────────
psy_schema_once('psy_sessions_schema_v2', 3600, function () use ($pdo) {
    foreach (['accepted_at DATETIME NULL', 'started_at DATETIME NULL', 'ended_at DATETIME NULL',
              'verified TINYINT NOT NULL DEFAULT 0', 'verify_note VARCHAR(255) NULL'] as $def) {
        $col = strtok($def, ' ');
        try {
            $has = $pdo->query("SHOW COLUMNS FROM appointments LIKE " . $pdo->quote($col))->fetch();
            if (!$has) $pdo->exec("ALTER TABLE appointments ADD COLUMN $def");
        } catch (Exception $e) {}
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS psy_refunds (
            id INT AUTO_INCREMENT PRIMARY KEY,
            appointment_id VARCHAR(64) NOT NULL,
            client_id VARCHAR(64) NULL,
            psychologist_id VARCHAR(64) NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            reason VARCHAR(255) NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'pending',   -- pending | done | rejected
            note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            resolved_at DATETIME NULL,
            UNIQUE KEY uniq_appt (appointment_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}
    // Журнал событий сессии — для сверки перед выплатой (кто/что/когда).
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS psy_session_log (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            appointment_id VARCHAR(64) NOT NULL,
            event VARCHAR(32) NOT NULL,          -- accepted|started|ended|auto_completed|declined|auto_cancelled|verified|rejected
            actor VARCHAR(64) NULL,               -- user_id или 'system'
            meta VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_appt (appointment_id),
            INDEX idx_event (event)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}
});

/** Есть ли колонка в appointments (кэш в статике). */
function seHasCol(PDO $pdo, string $col): bool {
    static $cache = [];
    if (isset($cache[$col])) return $cache[$col];
    try { $cache[$col] = (bool)$pdo->query("SHOW COLUMNS FROM appointments LIKE " . $pdo->quote($col))->fetch(); }
    catch (Exception $e) { $cache[$col] = false; }
    return $cache[$col];
}

/** Плановая длительность записи в минутах (по умолчанию 50). */
function seDuration(array $a): int {
    $d = (int)($a['duration'] ?? 0);
    return $d > 0 ? $d : 50;
}

/** Комиссия платформы, % (настройка platform_commission). */
function seCommissionPct(PDO $pdo): float {
    $v = (float)psySetting($pdo, 'platform_commission', '0');
    if ($v < 0) $v = 0; if ($v > 100) $v = 100;
    return $v;
}
/** Сумма к получению психологом — всегда ЗА ВЫЧЕТОМ комиссии платформы. */
function seNetToPsy(float $gross, float $pct): float {
    return round($gross * (1 - $pct / 100), 2);
}

/**
 * Авто-подстраховка. Побочная работа — свой try/catch, молчит при любой ошибке.
 * Ставит в очередь возврата оплаченные, но не принятые вовремя записи, и
 * авто-завершает принятые сессии, у которых плановый конец давно прошёл.
 */
function sessionsAutoTick(PDO $pdo): void {
    $graceStart = (int)psySetting($pdo, 'session_cancel_grace_min', '20');   // мин после начала без принятия → отмена
    $graceEnd   = (int)psySetting($pdo, 'session_autocomplete_hours', '3');  // ч после планового конца → авто-завершение
    $holdMin    = (int)psySetting($pdo, 'payment_hold_min', '30');            // мин «висения» неоплаченной записи → отмена

    // 0) НЕОПЛАЧЕННЫЕ висяки (оплата не дошла / клиент бросил) → отмена, чтобы они не
    //    выглядели как бронь и не занимали слот. Это НЕ деньги: записи ещё не оплачены.
    //    По возрасту (created_at), если колонка есть; плюс любые прошедшие по времени.
    try {
        $hasCreated = seHasCol($pdo, 'created_at');
        if ($hasCreated) {
            $st = $pdo->prepare("UPDATE appointments SET status='cancelled'
                                  WHERE status IN ('pending_payment','pending')
                                    AND created_at < (NOW() - INTERVAL ? MINUTE)");
            $st->execute([$holdMin]);
        }
        // Прошедшие по времени неоплаченные — в любом случае отменяем.
        $pdo->prepare("UPDATE appointments SET status='cancelled'
                        WHERE status IN ('pending_payment','pending')
                          AND date_time < (NOW() - INTERVAL 15 MINUTE)")->execute();
    } catch (Exception $e) {}

    // 1) Не принятые вовремя (оплачено, но психолог не подтвердил) → отмена + возврат.
    try {
        $st = $pdo->prepare("SELECT id, client_id, psychologist_id, price
                               FROM appointments
                              WHERE status = 'scheduled'
                                AND date_time < (NOW() - INTERVAL ? MINUTE)
                              LIMIT 200");
        $st->execute([$graceStart]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            try {
                $pdo->prepare("UPDATE appointments SET status='cancelled' WHERE id=? AND status='scheduled'")->execute([$r['id']]);
                seQueueRefund($pdo, $r['id'], $r['client_id'] ?? null, $r['psychologist_id'] ?? null, (float)($r['price'] ?? 0), 'Психолог не принял заказ вовремя');
                seLog($pdo, $r['id'], 'auto_cancelled', 'system', 'Не принят вовремя, возврат в очередь');
                seNotify($pdo, sePsyUserId($pdo, $r['psychologist_id'] ?? ''), $r['client_id'] ?? '',
                         '❌ Запись отменена: специалист не подтвердил её вовремя. Оформляется возврат оплаты.');
            } catch (Exception $e) {}
        }
    } catch (Exception $e) {}

    // 2) Принятые/идущие, плановый конец прошёл → авто-завершение (психолог забыл кнопки).
    //    Проверяем реальность по звонку: авто-завершённая сессия сама НЕ подтверждается
    //    (verified=0), если звонок не подтвердил, — чтобы деньги не начислялись без сессии.
    try {
        $durExpr = seHasCol($pdo, 'duration') ? 'COALESCE(a.duration,50)' : '50';
        $st = $pdo->prepare("SELECT a.* FROM appointments a
                              WHERE a.status IN ('confirmed','in_progress')
                                AND (a.date_time + INTERVAL $durExpr MINUTE) < (NOW() - INTERVAL ? HOUR)
                              LIMIT 200");
        $st->execute([$graceEnd]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            try {
                $psyUser = sePsyUserId($pdo, $a['psychologist_id'] ?? '');
                $a['ended_at'] = $a['ended_at'] ?? date('Y-m-d H:i:s');
                list($verified, $vnote) = seVerifyCompletion($pdo, $a, $psyUser);
                $sets = "status='completed'";
                if (seHasCol($pdo, 'ended_at'))   $sets .= ", ended_at = COALESCE(ended_at, NOW())";
                if (seHasCol($pdo, 'verified'))   $sets .= ", verified = " . (int)$verified;
                if (seHasCol($pdo, 'verify_note')) $sets .= ", verify_note = " . $pdo->quote('Авто-завершение. ' . $vnote);
                $pdo->prepare("UPDATE appointments SET $sets WHERE id = ?")->execute([$a['id']]);
                seLog($pdo, $a['id'], 'auto_completed', 'system', ($verified ? 'подтверждено' : 'на проверку') . ': ' . $vnote);
            } catch (Exception $e) {}
        }
    } catch (Exception $e) {}
}

/** Поставить возврат в очередь (идемпотентно по appointment_id). Побочная работа. */
function seQueueRefund(PDO $pdo, $apptId, $clientId, $psyId, float $amount, string $reason): void {
    try {
        $st = $pdo->prepare("INSERT IGNORE INTO psy_refunds (appointment_id, client_id, psychologist_id, amount, reason, status, created_at)
                             VALUES (?, ?, ?, ?, ?, 'pending', NOW())");
        $st->execute([$apptId, $clientId, $psyId, $amount, mb_substr($reason, 0, 255)]);
    } catch (Exception $e) {}
}

/** Запись в журнал сессии. Побочная работа — свой try/catch, молчит при ошибке. */
function seLog(PDO $pdo, $apptId, string $event, $actor, string $meta = ''): void {
    try {
        $st = $pdo->prepare("INSERT INTO psy_session_log (appointment_id, event, actor, meta, created_at) VALUES (?, ?, ?, ?, NOW())");
        $st->execute([$apptId, $event, (string)($actor ?? 'system'), mb_substr($meta, 0, 255)]);
    } catch (Exception $e) {}
}

/** user_id психолога по его psychologists.id (для сверки со звонками, где id пользователей). */
function sePsyUserId(PDO $pdo, $psyId): string {
    try { $st = $pdo->prepare("SELECT user_id FROM psychologists WHERE id = ? LIMIT 1"); $st->execute([$psyId]); return (string)$st->fetchColumn(); }
    catch (Exception $e) { return ''; }
}

/** Человеческая дата/время записи: «3 окт в 14:00». */
function seDateRu($dt): string {
    $ts = strtotime((string)$dt);
    if (!$ts) return (string)$dt;
    $m = ['', 'янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
    return (int)date('j', $ts) . ' ' . ($m[(int)date('n', $ts)] ?? '') . ' в ' . date('H:i', $ts);
}

/**
 * Уведомление участнику — системным сообщением в чат (оно же триггерит пуш).
 * Побочная работа: свой try/catch и function_exists, молчит при любой ошибке и
 * никогда не ломает основной ответ.
 */
function seNotify(PDO $pdo, $from, $to, string $text): void {
    if (!function_exists('rtcSendDm')) return;
    $from = (string)$from; $to = (string)$to;
    if ($from === '' || $to === '' || $from === $to) return;
    try { rtcSendDm($pdo, $from, $to, $text, false); } catch (\Throwable $e) {}
}

/**
 * АНТИ-ФРОД: подтвердить, что сессия реально состоялась, прежде чем пускать её в
 * начисление. Нельзя «понажимать кнопки» и получить деньги без проведённой сессии.
 *
 * Критерий:
 *  • видео/аудио — должен быть РЕАЛЬНЫЙ отвеченный звонок (rtc_calls: answered_at и
 *    ended_at заполнены) достаточной длительности между психологом и клиентом в окне
 *    сессии. Нет звонка / слишком короткий → verified=0 (на проверку админу).
 *  • чат — звонка нет, поэтому критерий — минимальная длительность сессии (start→end).
 * Возвращает [verified(0|1), note].
 */
function seVerifyCompletion(PDO $pdo, array $appt, $psyUserId): array {
    $format = (string)($appt['format'] ?? 'video');
    $minMin = (int)psySetting($pdo, 'session_min_minutes', '10');
    $minCallSec = (int)psySetting($pdo, 'session_min_call_sec', '300');
    $startedAt = $appt['started_at'] ?? null;
    $endedAt = $appt['ended_at'] ?? date('Y-m-d H:i:s');

    $elapsedMin = null; $elapsedOk = false;
    if ($startedAt) {
        $sec = strtotime($endedAt) - strtotime($startedAt);
        $elapsedMin = (int)round($sec / 60);
        $elapsedOk = $sec >= $minMin * 60;
    }

    $callSec = 0;
    $clientId = $appt['client_id'] ?? null;
    if ($psyUserId && $clientId) {
        try {
            $winFrom = $startedAt ? date('Y-m-d H:i:s', strtotime($startedAt) - 3600)
                     : (!empty($appt['date_time']) ? date('Y-m-d H:i:s', strtotime($appt['date_time']) - 3600)
                     : date('Y-m-d H:i:s', time() - 86400));
            $st = $pdo->prepare("SELECT COALESCE(MAX(TIMESTAMPDIFF(SECOND, answered_at, ended_at)),0)
                                   FROM rtc_calls
                                  WHERE status='ended' AND answered_at IS NOT NULL AND ended_at IS NOT NULL
                                    AND ((from_id=? AND to_id=?) OR (from_id=? AND to_id=?))
                                    AND answered_at >= ?");
            $st->execute([$psyUserId, $clientId, $clientId, $psyUserId, $winFrom]);
            $callSec = (int)$st->fetchColumn();
        } catch (Exception $e) {}
    }
    $callOk = $callSec >= $minCallSec;

    if ($format === 'chat') {
        if ($elapsedOk) return [1, 'Чат-сессия, длительность ~' . $elapsedMin . ' мин'];
        return [0, 'Чат-сессия слишком короткая' . ($elapsedMin !== null ? ' (~' . $elapsedMin . ' мин)' : '') . ' — нужна проверка'];
    }
    // видео/аудио
    if ($callOk) return [1, 'Подтверждено звонком ~' . (int)round($callSec / 60) . ' мин'];
    if ($callSec > 0) return [0, 'Звонок слишком короткий (~' . (int)round($callSec / 60) . ' мин) — нужна проверка'];
    return [0, 'Реального звонка не найдено — нужна проверка перед выплатой'];
}

if (session_status() === PHP_SESSION_NONE) session_start();
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) seOut(['error' => 'Требуется авторизация'], 401);

$role = '';
try { $st = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1"); $st->execute([$userId]); $role = (string)$st->fetchColumn(); } catch (Exception $e) {}

/** psychologists.id текущего пользователя. */
function sePsyId(PDO $pdo, $userId): string {
    try { $st = $pdo->prepare("SELECT id FROM psychologists WHERE user_id = ? LIMIT 1"); $st->execute([$userId]); return (string)$st->fetchColumn(); }
    catch (Exception $e) { return ''; }
}

$action = $_GET['action'] ?? '';
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

/** Загрузить запись и проверить, что она принадлежит этому психологу. */
function seLoadOwn(PDO $pdo, string $pid, string $apptId) {
    try {
        $st = $pdo->prepare("SELECT * FROM appointments WHERE id = ? LIMIT 1");
        $st->execute([$apptId]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $a = null; }
    if (!$a) return [null, 'Запись не найдена'];
    if ((string)$a['psychologist_id'] !== $pid) return [null, 'Это не ваша запись'];
    return [$a, null];
}

// ── Служебный тик: авто-очистка висяков/просрочек (любой авторизованный) ──────
// Клиентский кабинет дёргает его при загрузке, чтобы неоплаченные записи не висели.
if ($action === 'tick') {
    sessionsAutoTick($pdo);
    seOut(['ok' => true]);
}

// ── Карта реально ОПЛАЧЕННЫХ записей текущего пользователя (как клиента) ───────
// «Оплачено» = есть строка в payments со статусом success (платные и бесплатные
// промо/intro её создают). Статус записи сам по себе недостаточно надёжен —
// бывали записи 'scheduled' без оплаты. Кабинет по этой карте решает: показать
// «Начать диалог» (оплачено) или «Оплатить» (нет).
if ($action === 'paid-map') {
    $ids = [];
    try {
        $st = $pdo->prepare("SELECT DISTINCT a.id FROM appointments a
                             JOIN payments p ON p.appointment_id = a.id AND p.status = 'success'
                             WHERE a.client_id = ?");
        $st->execute([$userId]);
        $ids = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {}
    seOut(['ok' => true, 'paid' => $ids]);
}

// ── Ближайшая действующая сессия с конкретным собеседником (для кнопок в чате) ──
// Психолог видит в чате с клиентом кнопки принять/начать/завершить по его записи.
if ($action === 'for-peer') {
    $pid = sePsyId($pdo, $userId);
    $peer = (string)($_GET['peer'] ?? $_POST['peer'] ?? ($body['peer'] ?? ''));
    if ($pid === '' || $peer === '') seOut(['ok' => true, 'session' => null]);
    sessionsAutoTick($pdo);
    $durSel = seHasCol($pdo, 'duration') ? 'a.duration' : '50 AS duration';
    try {
        // Берём ближайшую актуальную (не завершённую/отменённую) оплаченную запись
        // этого клиента у этого психолога.
        $st = $pdo->prepare("SELECT a.id, a.date_time, a.status, a.format, $durSel
                               FROM appointments a
                              WHERE a.psychologist_id = ? AND a.client_id = ?
                                AND a.status IN ('scheduled','confirmed','in_progress')
                                AND EXISTS (SELECT 1 FROM payments p WHERE p.appointment_id = a.id AND p.status = 'success')
                           ORDER BY (a.status='in_progress') DESC, (a.status='confirmed') DESC, a.date_time ASC
                              LIMIT 1");
        $st->execute([$pid, $peer]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $r = null; }
    if (!$r) seOut(['ok' => true, 'session' => null]);
    seOut(['ok' => true, 'session' => [
        'id' => $r['id'], 'status' => $r['status'], 'date_time' => $r['date_time'],
        'format' => $r['format'], 'duration' => (int)($r['duration'] ?: 50),
    ]]);
}

// ── Список сессий психолога ──────────────────────────────────────────────────
if ($action === 'list') {
    $pid = sePsyId($pdo, $userId);
    if ($pid === '') seOut(['ok' => true, 'data' => [], 'note' => 'Профиль психолога не найден']);
    sessionsAutoTick($pdo); // подчистить просроченные перед показом
    $durSel = seHasCol($pdo, 'duration') ? 'a.duration' : '50 AS duration';
    $accSel = seHasCol($pdo, 'accepted_at') ? 'a.accepted_at' : 'NULL AS accepted_at';
    $staSel = seHasCol($pdo, 'started_at') ? 'a.started_at' : 'NULL AS started_at';
    $endSel = seHasCol($pdo, 'ended_at') ? 'a.ended_at' : 'NULL AS ended_at';
    $data = [];
    try {
        $st = $pdo->prepare("SELECT a.id, a.client_id, a.date_time, a.status, a.format, a.price,
                                    $durSel, $accSel, $staSel, $endSel,
                                    u.first_name, u.last_name
                               FROM appointments a
                          LEFT JOIN users u ON u.id = a.client_id
                              WHERE a.psychologist_id = ?
                                AND a.status IN ('scheduled','confirmed','in_progress','completed','cancelled')
                                AND EXISTS (SELECT 1 FROM payments p WHERE p.appointment_id = a.id AND p.status = 'success')
                           ORDER BY a.date_time DESC
                              LIMIT 100");
        $st->execute([$pid]);
        $pct = seCommissionPct($pdo);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            // Психологу показываем ТОЛЬКО сумму к получению (за вычетом комиссии платформы).
            $data[] = [
                'id' => $r['id'],
                'client' => $name !== '' ? $name : 'Клиент',
                'date_time' => $r['date_time'],
                'duration' => (int)($r['duration'] ?: 50),
                'format' => $r['format'] ?? '',
                'amount_to_psy' => seNetToPsy((float)$r['price'], $pct),
                'status' => $r['status'],
                'accepted_at' => $r['accepted_at'] ?? null,
                'started_at' => $r['started_at'] ?? null,
                'ended_at' => $r['ended_at'] ?? null,
            ];
        }
    } catch (Exception $e) { seOut(['ok' => true, 'data' => [], 'note' => 'Не удалось загрузить сессии']); }
    seOut(['ok' => true, 'data' => $data]);
}

// ── Админские списки (без appointment_id) ────────────────────────────────────
if ($role === 'admin' && $action === 'review-list') {
    $verSel = seHasCol($pdo, 'verified') ? 'a.verified' : '1 AS verified';
    $vnSel  = seHasCol($pdo, 'verify_note') ? 'a.verify_note' : 'NULL AS verify_note';
    $endSel = seHasCol($pdo, 'ended_at') ? 'a.ended_at' : 'NULL AS ended_at';
    $staSel = seHasCol($pdo, 'started_at') ? 'a.started_at' : 'NULL AS started_at';
    $out = [];
    try {
        $st = $pdo->query("SELECT a.id, a.client_id, a.psychologist_id, a.date_time, a.format, a.price,
                                  $verSel, $vnSel, $endSel, $staSel,
                                  u.first_name AS cf, u.last_name AS cl,
                                  pu.first_name AS pf, pu.last_name AS pl
                             FROM appointments a
                        LEFT JOIN users u ON u.id = a.client_id
                        LEFT JOIN psychologists p ON p.id = a.psychologist_id
                        LEFT JOIN users pu ON pu.id = p.user_id
                            WHERE a.status='completed' AND " . (seHasCol($pdo, 'verified') ? 'a.verified = 0' : '0') . "
                         ORDER BY a.date_time DESC LIMIT 200");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id' => $r['id'],
                'клиент' => trim(($r['cf'] ?? '') . ' ' . ($r['cl'] ?? '')) ?: 'Клиент',
                'психолог' => trim(($r['pf'] ?? '') . ' ' . ($r['pl'] ?? '')) ?: 'Психолог',
                'psychologist_id' => $r['psychologist_id'],
                'date_time' => $r['date_time'], 'format' => $r['format'], 'price' => (float)$r['price'],
                'начато' => $r['started_at'] ?? null, 'завершено' => $r['ended_at'] ?? null,
                'проверка' => $r['verify_note'] ?? null,
            ];
        }
    } catch (Exception $e) {}
    seOut(['ok' => true, 'data' => $out]);
}

if ($role === 'admin' && $action === 'refunds') {
    $status = (string)($_GET['status'] ?? 'pending');
    $out = [];
    try {
        $sql = "SELECT r.*, u.first_name AS cf, u.last_name AS cl
                  FROM psy_refunds r LEFT JOIN users u ON u.id = r.client_id"
             . ($status !== 'all' ? " WHERE r.status = ?" : "")
             . " ORDER BY r.id DESC LIMIT 200";
        $st = $pdo->prepare($sql);
        $st->execute($status !== 'all' ? [$status] : []);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id' => (int)$r['id'],
                'appointment_id' => $r['appointment_id'],
                'клиент' => trim(($r['cf'] ?? '') . ' ' . ($r['cl'] ?? '')) ?: 'Клиент',
                'сумма' => (float)$r['amount'],
                'причина' => $r['reason'], 'статус' => $r['status'],
                'создано' => $r['created_at'], 'обработано' => $r['resolved_at'],
            ];
        }
    } catch (Exception $e) {}
    seOut(['ok' => true, 'data' => $out]);
}

if ($role === 'admin' && $action === 'refund-resolve' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($body['id'] ?? 0);
    $done = !empty($body['done']);
    $note = mb_substr(trim((string)($body['note'] ?? '')), 0, 255);
    if ($id <= 0) seOut(['error' => 'Не указан возврат'], 400);
    try {
        $st = $pdo->prepare("UPDATE psy_refunds SET status=?, note=?, resolved_at=NOW() WHERE id=? AND status='pending'");
        $st->execute([$done ? 'done' : 'rejected', $note, $id]);
        seOut(['ok' => true, 'отмечено' => $st->rowCount()]);
    } catch (Exception $e) { seOut(['error' => 'Не удалось обновить'], 500); }
}

// Все действия ниже — только психолог-владелец записи.
$pid = sePsyId($pdo, $userId);
if ($pid === '' && $role !== 'admin') seOut(['error' => 'Только для психолога'], 403);

$apptId = (string)($body['appointment_id'] ?? '');
if ($apptId === '') seOut(['error' => 'Не указана запись'], 400);
list($appt, $err) = seLoadOwn($pdo, $pid, $apptId);
if ($err && $role !== 'admin') seOut(['error' => $err], 403);
if (!$appt) {
    // админ может действовать по любой записи
    try { $st = $pdo->prepare("SELECT * FROM appointments WHERE id=? LIMIT 1"); $st->execute([$apptId]); $appt = $st->fetch(PDO::FETCH_ASSOC); } catch (Exception $e) {}
    if (!$appt) seOut(['error' => 'Запись не найдена'], 404);
}

$cur = (string)$appt['status'];

if ($action === 'accept' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($cur !== 'scheduled') seOut(['error' => 'Принять можно только оплаченную запись (сейчас: ' . $cur . ')'], 409);
    $set = seHasCol($pdo, 'accepted_at') ? ", accepted_at = NOW()" : "";
    try { $pdo->prepare("UPDATE appointments SET status='confirmed'$set WHERE id=? AND status='scheduled'")->execute([$apptId]); }
    catch (Exception $e) { seOut(['error' => 'Не удалось принять'], 500); }
    seLog($pdo, $apptId, 'accepted', $userId, '');
    seNotify($pdo, sePsyUserId($pdo, $appt['psychologist_id'] ?? ''), $appt['client_id'] ?? '',
             '✅ Специалист принял вашу запись на ' . seDateRu($appt['date_time'] ?? '') . '. До встречи!');
    seOut(['ok' => true, 'status' => 'confirmed']);
}

if ($action === 'decline' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!in_array($cur, ['scheduled', 'confirmed'], true)) seOut(['error' => 'Отказаться можно до начала сессии'], 409);
    $reason = mb_substr(trim((string)($body['reason'] ?? 'Психолог отказался от заказа')), 0, 255);
    try { $pdo->prepare("UPDATE appointments SET status='cancelled' WHERE id=?")->execute([$apptId]); }
    catch (Exception $e) { seOut(['error' => 'Не удалось отменить'], 500); }
    // Возврат клиенту — в очередь (деньги возвращаются вручную через Робокассу).
    seQueueRefund($pdo, $apptId, $appt['client_id'] ?? null, $appt['psychologist_id'] ?? null, (float)($appt['price'] ?? 0), $reason);
    seLog($pdo, $apptId, 'declined', $userId, $reason);
    seNotify($pdo, sePsyUserId($pdo, $appt['psychologist_id'] ?? ''), $appt['client_id'] ?? '',
             '❌ К сожалению, запись на ' . seDateRu($appt['date_time'] ?? '') . ' отменена специалистом. Оформляется возврат оплаты.');
    seOut(['ok' => true, 'status' => 'cancelled', 'refund' => 'queued']);
}

if ($action === 'start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!in_array($cur, ['confirmed', 'scheduled'], true)) seOut(['error' => 'Начать можно принятую запись (сейчас: ' . $cur . ')'], 409);
    $set = seHasCol($pdo, 'started_at') ? ", started_at = NOW()" : "";
    // Если психолог жмёт «начать» по ещё не принятой — считаем, что тем самым принял.
    try { $pdo->prepare("UPDATE appointments SET status='in_progress'$set WHERE id=?")->execute([$apptId]); }
    catch (Exception $e) { seOut(['error' => 'Не удалось начать'], 500); }
    seLog($pdo, $apptId, 'started', $userId, '');
    seOut(['ok' => true, 'status' => 'in_progress']);
}

if ($action === 'end' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!in_array($cur, ['in_progress', 'confirmed'], true)) seOut(['error' => 'Завершить можно идущую/принятую сессию (сейчас: ' . $cur . ')'], 409);
    // АНТИ-ФРОД: проверяем реальность сессии (звонок/длительность) перед начислением.
    $psyUser = sePsyUserId($pdo, $appt['psychologist_id'] ?? '');
    if (!$psyUser && $pid !== '') $psyUser = (string)$userId; // обычный случай: психолог завершает свою
    $appt['ended_at'] = date('Y-m-d H:i:s');
    list($verified, $vnote) = seVerifyCompletion($pdo, $appt, $psyUser);
    $sets = "status='completed'";
    if (seHasCol($pdo, 'ended_at'))    $sets .= ", ended_at = NOW()";
    if (seHasCol($pdo, 'verified'))    $sets .= ", verified = " . (int)$verified;
    $params = [];
    if (seHasCol($pdo, 'verify_note')) { $sets .= ", verify_note = ?"; $params[] = $vnote; }
    $params[] = $apptId;
    try { $pdo->prepare("UPDATE appointments SET $sets WHERE id=?")->execute($params); }
    catch (Exception $e) { seOut(['error' => 'Не удалось завершить'], 500); }
    seLog($pdo, $apptId, 'ended', $userId, ($verified ? 'подтверждено' : 'на проверку') . ': ' . $vnote);
    seNotify($pdo, sePsyUserId($pdo, $appt['psychologist_id'] ?? ''), $appt['client_id'] ?? '',
             'Сессия завершена. Спасибо, что были на связи 🙏');
    // Начисление создаст payouts.php по completed+verified. Неподтверждённые ждут проверки админом.
    seOut(['ok' => true, 'status' => 'completed', 'verified' => (int)$verified, 'verify_note' => $vnote,
           'note' => $verified ? 'Сессия завершена и подтверждена — пойдёт в баланс к выплате.'
                               : 'Сессия завершена, но не подтверждена автоматически (' . $vnote . '). Начисление — после проверки администратором.']);
}

// ── Журнал одной записи (психолог-владелец или админ) ─────────────────────────
if ($action === 'log') {
    $events = [];
    try {
        $st = $pdo->prepare("SELECT event, actor, meta, created_at FROM psy_session_log WHERE appointment_id=? ORDER BY id ASC");
        $st->execute([$apptId]);
        $events = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
    // Оплата — из payments (время получения денег).
    $paidAt = null; $paidAmount = null;
    try {
        $st = $pdo->prepare("SELECT paid_at, amount FROM payments WHERE appointment_id=? AND status IN ('success','paid') ORDER BY paid_at ASC LIMIT 1");
        $st->execute([$apptId]);
        if ($p = $st->fetch(PDO::FETCH_ASSOC)) { $paidAt = $p['paid_at']; $paidAmount = (float)$p['amount']; }
    } catch (Exception $e) {}
    $netToPsy = $paidAmount !== null ? seNetToPsy((float)$paidAmount, seCommissionPct($pdo)) : null;
    seOut(['ok' => true, 'запись' => [
        'id' => $appt['id'],
        'статус' => $appt['status'],
        // «сумма» — сколько оплатил клиент (видит админ); «сумма_психологу» — за вычетом комиссии.
        'оплата' => $paidAt ? ['время' => $paidAt, 'сумма' => $paidAmount, 'сумма_психологу' => $netToPsy] : null,
        'принято' => $appt['accepted_at'] ?? null,
        'начато' => $appt['started_at'] ?? null,
        'завершено' => $appt['ended_at'] ?? null,
        'подтверждено' => isset($appt['verified']) ? (int)$appt['verified'] : null,
        'проверка' => $appt['verify_note'] ?? null,
    ], 'события' => $events]);
}

if ($role === 'admin' && $action === 'verify' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $approve = !empty($body['approve']);
    $note = mb_substr(trim((string)($body['note'] ?? '')), 0, 255);
    if ($approve) {
        $sets = seHasCol($pdo, 'verified') ? "verified=1" : "status=status";
        if (seHasCol($pdo, 'verify_note')) $sets .= ", verify_note=" . $pdo->quote('Подтверждено админом. ' . $note);
        try { $pdo->prepare("UPDATE appointments SET $sets WHERE id=?")->execute([$apptId]); }
        catch (Exception $e) { seOut(['error' => 'Не удалось подтвердить'], 500); }
        seLog($pdo, $apptId, 'verified', $userId, $note);
        seOut(['ok' => true, 'verified' => 1]);
    }
    // отклонить: отменить сессию и поставить возврат
    try { $pdo->prepare("UPDATE appointments SET status='cancelled' WHERE id=?")->execute([$apptId]); }
    catch (Exception $e) { seOut(['error' => 'Не удалось отклонить'], 500); }
    seQueueRefund($pdo, $apptId, $appt['client_id'] ?? null, $appt['psychologist_id'] ?? null, (float)($appt['price'] ?? 0), 'Сессия не подтверждена: ' . $note);
    seLog($pdo, $apptId, 'rejected', $userId, $note);
    seOut(['ok' => true, 'verified' => 0, 'status' => 'cancelled']);
}

seOut(['error' => 'Неизвестное действие'], 400);

