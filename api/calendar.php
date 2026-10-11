<?php
/**
 * calendar.php — записи на приём календарём и подписка на них внешним календарём.
 *
 * ЗАЧЕМ. Записи были только списком в кабинете: чтобы понять, занят ли четверг,
 * приходилось читать даты глазами. И жили они отдельно от календаря, которым
 * человек пользуется каждый день, — про сессию забывали.
 *
 * ЧТО ДЕЛАЕТ
 *   GET ?action=list&from=&to=   — записи за период (для сетки месяца)
 *   GET ?action=token            — личная ссылка для подписки внешним календарём
 *   GET ?action=ics              — выгрузить свои записи файлом .ics (нужен вход)
 *   GET ?action=ics&token=XXX    — то же по личной ссылке, БЕЗ входа
 *
 * ПРО ССЫЛКУ БЕЗ ВХОДА. Иначе никак: Google Календарь и «Календарь» на iPhone
 * ходят за файлом сами, своим сервером, и сессии у них нет. Поэтому ссылка
 * содержит длинный случайный токен, знает его только владелец, и по нему видны
 * ровно его собственные записи. Токен можно сменить — старая ссылка сразу умрёт.
 *
 * ЧЕГО В ФАЙЛЕ НЕТ. Ни диагнозов, ни заметок, ни стоимости: календарь человек
 * может показать коллеге, открыв ноутбук. В событии только время, формат и имя
 * собеседника.
 */

require_once __DIR__ . '/config.php';
if (!function_exists('getDB') && !function_exists('getDbConnection') && !function_exists('getPDO')) {
    require_once __DIR__ . '/db.php';
}
$pdo = function_exists('getDB') ? getDB()
     : (function_exists('getDbConnection') ? getDbConnection()
     : (function_exists('getPDO') ? getPDO() : null));

function calJson($d, $c = 200) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code($c);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$pdo) calJson(['ok' => false, 'error' => 'Нет подключения к БД'], 500);

$action = $_GET['action'] ?? 'list';

function calEnsure(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS calendar_tokens (
        user_id VARCHAR(64) NOT NULL PRIMARY KEY,
        token VARCHAR(48) NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uniq_token (token)
    ) DEFAULT CHARSET=utf8mb4");
}

/** Колонки таблицы записей: набор полей у разных установок разный. */
function calColumns(PDO $pdo) {
    static $cols = null;
    if ($cols !== null) return $cols;
    $cols = [];
    try {
        foreach ($pdo->query("SHOW COLUMNS FROM appointments")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $n = $r['Field'] ?? ($r['field'] ?? null);
            if ($n !== null) $cols[] = (string)$n;
        }
    } catch (Exception $e) { $cols = []; }
    return $cols;
}
function calHas(PDO $pdo, $c) { return in_array($c, calColumns($pdo), true); }

/**
 * Записи человека за период. Роль определяем по факту: клиент видит свои записи,
 * психолог — свои. Кто он, спрашивать не нужно — достаточно посмотреть, где он
 * встречается в таблице.
 */
function calFetch(PDO $pdo, $userId, $from, $to) {
    $hasDur = calHas($pdo, 'duration');
    $sel = "a.id, a.date_time, a.status, a.client_id, a.psychologist_id"
         . (calHas($pdo, 'format') ? ", a.format" : ", NULL AS format")
         . ($hasDur ? ", a.duration" : ", NULL AS duration");
    $sql = "SELECT $sel,
                   cu.first_name AS client_first_name, cu.last_name AS client_last_name,
                   pu.first_name AS psy_first_name,   pu.last_name AS psy_last_name,
                   p.user_id AS psy_user_id
              FROM appointments a
              LEFT JOIN users cu ON cu.id = a.client_id
              LEFT JOIN psychologists p ON p.id = a.psychologist_id
              LEFT JOIN users pu ON pu.id = p.user_id
             WHERE (a.client_id = ? OR p.user_id = ?)
               AND a.date_time >= ? AND a.date_time < ?
             ORDER BY a.date_time ASC
             LIMIT 500";
    $st = $pdo->prepare($sql);
    $st->execute([$userId, $userId, $from, $to]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach ($rows as $r) {
        $iAmClient = ((string)$r['client_id'] === (string)$userId);
        $withName = $iAmClient
            ? trim(((string)$r['psy_first_name']) . ' ' . ((string)$r['psy_last_name']))
            : trim(((string)$r['client_first_name']) . ' ' . ((string)$r['client_last_name']));
        $out[] = [
            'id'        => (string)$r['id'],
            'date_time' => (string)$r['date_time'],
            'duration'  => (int)($r['duration'] ?: 50),
            'format'    => (string)($r['format'] ?? ''),
            'status'    => (string)($r['status'] ?? 'scheduled'),
            'role'      => $iAmClient ? 'client' : 'psychologist',
            'with'      => $withName ?: ($iAmClient ? 'Психолог' : 'Клиент'),
            'peer_id'   => $iAmClient ? (string)($r['psy_user_id'] ?? '') : (string)$r['client_id'],
            'psychologist_id' => (string)($r['psychologist_id'] ?? ''),
        ];
    }
    return $out;
}

// ── Файл .ics ────────────────────────────────────────────────────────────────

/** Экранирование по RFC 5545: запятые, точки с запятой и переносы строк. */
function icsEsc($s) {
    return str_replace(["\\", "\n", "\r", ',', ';'], ['\\\\', '\\n', '', '\\,', '\;'], (string)$s);
}

/**
 * Строки .ics длиннее 75 октетов положено сворачивать. Иначе часть календарей
 * (в первую очередь Outlook) обрезает описание на середине слова.
 */
function icsFold($line) {
    $out = '';
    $cur = '';
    foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        if (strlen($cur) + strlen($ch) > 72) { $out .= $cur . "\r\n "; $cur = ''; }
        $cur .= $ch;
    }
    return $out . $cur;
}

function icsTime($sqlDateTime) {
    // Время в базе московское. Отдаём в UTC с пометкой Z: так его правильно
    // покажет календарь в любом часовом поясе, и не нужно тащить VTIMEZONE.
    try {
        $dt = new DateTime($sqlDateTime, new DateTimeZone('Europe/Moscow'));
        $dt->setTimezone(new DateTimeZone('UTC'));
        return $dt->format('Ymd\THis\Z');
    } catch (Exception $e) { return gmdate('Ymd\THis\Z'); }
}

function calFormatWord($f) {
    $map = ['video' => 'Видео', 'audio' => 'Аудио', 'phone' => 'Аудио', 'chat' => 'Чат'];
    return $map[strtolower((string)$f)] ?? '';
}

function calIcs(array $items, $host) {
    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//psytalk.pro//Sessions//RU',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'X-WR-CALNAME:psytalk.pro — консультации',
        // Подсказка внешнему календарю, как часто заглядывать: чаще незачем,
        // записи не меняются поминутно.
        'X-PUBLISHED-TTL:PT2H',
        'REFRESH-INTERVAL;VALUE=DURATION:PT2H',
    ];
    foreach ($items as $it) {
        if (($it['status'] ?? '') === 'cancelled') continue;
        $fmt = calFormatWord($it['format']);
        $title = 'Консультация' . ($it['with'] ? ' · ' . $it['with'] : '');
        $start = icsTime($it['date_time']);
        try {
            $end = new DateTime($it['date_time'], new DateTimeZone('Europe/Moscow'));
            $end->modify('+' . max(15, (int)$it['duration']) . ' minutes');
            $end->setTimezone(new DateTimeZone('UTC'));
            $end = $end->format('Ymd\THis\Z');
        } catch (Exception $e) { $end = $start; }
        $desc = ($fmt ? 'Формат: ' . $fmt . '. ' : '') . 'Подключение — на psytalk.pro';
        $lines[] = 'BEGIN:VEVENT';
        $lines[] = 'UID:' . icsEsc($it['id']) . '@psytalk.pro';
        $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
        $lines[] = 'DTSTART:' . $start;
        $lines[] = 'DTEND:' . $end;
        $lines[] = icsFold('SUMMARY:' . icsEsc($title));
        $lines[] = icsFold('DESCRIPTION:' . icsEsc($desc));
        $lines[] = icsFold('URL:https://' . $host . '/chat.html');
        $lines[] = 'STATUS:CONFIRMED';
        // Напоминание за час: без него смысл переноса в календарь наполовину теряется
        $lines[] = 'BEGIN:VALARM';
        $lines[] = 'TRIGGER:-PT1H';
        $lines[] = 'ACTION:DISPLAY';
        $lines[] = icsFold('DESCRIPTION:' . icsEsc('Через час — ' . $title));
        $lines[] = 'END:VALARM';
        $lines[] = 'END:VEVENT';
    }
    $lines[] = 'END:VCALENDAR';
    return implode("\r\n", $lines) . "\r\n";
}

try { calEnsure($pdo); } catch (Exception $e) { /* без токенов подписка не заработает, остальное — да */ }

// ── Подписка по ссылке: сессии нет, узнаём человека по токену ────────────────
if ($action === 'ics' && !empty($_GET['token'])) {
    $token = preg_replace('/[^A-Za-z0-9]/', '', (string)$_GET['token']);
    if (strlen($token) < 24) { http_response_code(404); exit; }
    try {
        $st = $pdo->prepare("SELECT user_id FROM calendar_tokens WHERE token = ? LIMIT 1");
        $st->execute([$token]);
        $uid = $st->fetchColumn();
    } catch (Exception $e) { $uid = null; }
    if (!$uid) { http_response_code(404); exit; }
    // Отдаём год назад и год вперёд: календари не любят бесконечные ленты
    $items = calFetch($pdo, $uid, date('Y-m-d', strtotime('-1 year')), date('Y-m-d', strtotime('+1 year')));
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="psytalk.ics"');
    header('Cache-Control: public, max-age=1800');
    echo calIcs($items, $_SERVER['HTTP_HOST'] ?? 'psytalk.pro');
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) calJson(['ok' => false, 'error' => 'Требуется авторизация'], 401);

if ($action === 'list') {
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-01');
    $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d', strtotime('+2 months'));
    try { calJson(['ok' => true, 'data' => calFetch($pdo, $userId, $from, $to)]); }
    catch (Exception $e) { calJson(['ok' => true, 'data' => []]); }
}

if ($action === 'token' || $action === 'reset-token') {
    try {
        if ($action === 'reset-token') {
            $pdo->prepare("DELETE FROM calendar_tokens WHERE user_id = ?")->execute([$userId]);
        }
        $st = $pdo->prepare("SELECT token FROM calendar_tokens WHERE user_id = ? LIMIT 1");
        $st->execute([$userId]);
        $token = $st->fetchColumn();
        if (!$token) {
            $token = bin2hex(random_bytes(16));
            $pdo->prepare("INSERT INTO calendar_tokens (user_id, token, created_at) VALUES (?, ?, NOW())")
                ->execute([$userId, $token]);
        }
        $host = $_SERVER['HTTP_HOST'] ?? 'psytalk.pro';
        $url = 'https://' . $host . '/api/calendar.php?action=ics&token=' . $token;
        calJson(['ok' => true, 'token' => $token, 'url' => $url,
                 'webcal' => 'webcal://' . $host . '/api/calendar.php?action=ics&token=' . $token]);
    } catch (Exception $e) { calJson(['ok' => false, 'error' => 'Не удалось выдать ссылку'], 500); }
}

if ($action === 'ics') {
    $items = calFetch($pdo, $userId, date('Y-m-d', strtotime('-1 year')), date('Y-m-d', strtotime('+1 year')));
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="psytalk.ics"');
    echo calIcs($items, $_SERVER['HTTP_HOST'] ?? 'psytalk.pro');
    exit;
}

calJson(['ok' => false, 'error' => 'Неизвестное действие'], 400);
