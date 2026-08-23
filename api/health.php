<?php
/**
 * health.php — короткая сводка о самочувствии хостинга.
 *
 * Нужна была, когда сайт «висел»: снаружи видно только «долго», а долго может быть
 * и из-за базы, и из-за нехватки процессов PHP, и из-за загрузки самого сервера.
 * Здесь всё это видно сразу и без доступа к панели хостинга.
 *
 * Секретов не отдаёт: только время ответа базы, счётчики и среднюю загрузку.
 * Ходить сюда стоит вручную; постоянного опроса эта страница не предполагает.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$t0 = microtime(true);
$r = ['ok' => true, 'time' => date('c')];

// 1. Сам PHP: сколько занял старт скрипта и какова средняя загрузка машины
$r['load'] = function_exists('sys_getloadavg') ? array_map(fn($v) => round($v, 2), sys_getloadavg()) : null;
$r['php'] = PHP_VERSION;

// 2. База: сколько идёт подключение и простейший запрос
try {
    require_once __DIR__ . '/config.php';
    if (!function_exists('getDB') && !function_exists('getDbConnection') && !function_exists('getPDO')) {
        require_once __DIR__ . '/db.php';
    }
    $t1 = microtime(true);
    $pdo = function_exists('getDB') ? getDB()
         : (function_exists('getDbConnection') ? getDbConnection()
         : (function_exists('getPDO') ? getPDO() : null));
    $r['db_connect_ms'] = round((microtime(true) - $t1) * 1000);
    if ($pdo) {
        $t2 = microtime(true);
        $pdo->query('SELECT 1')->fetchColumn();
        $r['db_ping_ms'] = round((microtime(true) - $t2) * 1000);
        // Кто сейчас в базе и не висит ли долгий запрос
        try {
            $rows = $pdo->query("SHOW FULL PROCESSLIST")->fetchAll(PDO::FETCH_ASSOC);
            $r['db_threads'] = count($rows);
            $slow = [];
            foreach ($rows as $p) {
                if ((int)($p['Time'] ?? 0) < 3) continue;
                if (strtolower($p['Command'] ?? '') === 'sleep') continue;
                $slow[] = ['s' => (int)$p['Time'], 'state' => $p['State'] ?? '',
                           'q' => mb_substr(preg_replace('/\s+/', ' ', (string)($p['Info'] ?? '')), 0, 160)];
            }
            usort($slow, fn($a, $b) => $b['s'] <=> $a['s']);
            $r['db_slow'] = array_slice($slow, 0, 8);
        } catch (Exception $e) { $r['db_threads'] = 'нет прав на PROCESSLIST'; }
    } else {
        $r['db'] = 'нет подключения';
    }
} catch (Exception $e) { $r['db_error'] = $e->getMessage(); }

// 3. Процессы PHP. Важно различать две картины:
//    - много процессов на всей машине, но мало наших — мешают соседи по хостингу;
//    - много именно наших — значит, очередь создаёт наш же сайт, и лечится это у нас.
$r['php_procs'] = null;
$n = @shell_exec('ps -e -o comm= 2>/dev/null | grep -c php');
if ($n !== null && trim((string)$n) !== '') $r['php_procs'] = (int)trim($n);

$r['user'] = @get_current_user();
$mine = @shell_exec('ps -u "$(id -un)" -o comm= 2>/dev/null | grep -c php');
$r['php_procs_mine'] = ($mine !== null && trim((string)$mine) !== '') ? (int)trim($mine) : null;

// 4. Файлы сессий: их сотни тысяч копятся от поисковых роботов, и тогда каждый
//    session_start() начинает упираться в файловую систему.
// Живут ли настройки из .user.ini — по ним видно, помнится вход или нет
$r['session'] = [
    'cookie_lifetime' => (int)@ini_get('session.cookie_lifetime'),
    'gc_maxlifetime'  => (int)@ini_get('session.gc_maxlifetime'),
    'cookie_secure'   => (int)@ini_get('session.cookie_secure'),
    'cookie_httponly' => (int)@ini_get('session.cookie_httponly'),
    'cookie_samesite' => (string)@ini_get('session.cookie_samesite'),
];
// Читает ли PHP наш .user.ini — и если нет, то почему
$root = $_SERVER['DOCUMENT_ROOT'] ?? '';
$r['user_ini'] = [
    'filename'  => (string)@ini_get('user_ini.filename'),
    'cache_ttl' => (int)@ini_get('user_ini.cache_ttl'),
    'doc_root'  => $root,
    'script_dir'=> __DIR__,
    'file_here' => is_file(__DIR__ . '/.user.ini'),
    'file_root' => $root ? is_file(rtrim($root, '/') . '/.user.ini') : null,
    'file_up'   => is_file(dirname(__DIR__) . '/.user.ini'),
    // Как запущен PHP: .user.ini читает только FastCGI/FPM, модуль Apache — нет
    'sapi'      => php_sapi_name(),
    // Проверочное значение из того же файла. Если оно применилось, а настройки
    // сессии — нет, значит их перебивает код, а не игнорируется весь файл.
    'upload_max' => (string)@ini_get('upload_max_filesize'),
    'post_max'   => (string)@ini_get('post_max_size'),
];
$sd = @ini_get('session.save_path');
$r['session_path'] = $sd ?: '(по умолчанию)';
if ($sd && is_dir($sd)) {
    $cnt = @shell_exec('ls -U ' . escapeshellarg($sd) . ' 2>/dev/null | head -100000 | wc -l');
    $r['session_files'] = ($cnt !== null && trim((string)$cnt) !== '') ? (int)trim($cnt) : null;
}

// 4b. ?msgcols=1 — почему из переписки могли пропасть вложения.
//     messages_page.php выбирает поля по списку колонок. Если список не читается,
//     из выборки молча выпадают attachment_url/type/name, и вместо фото, голосовых
//     и кружков в чате остаётся одна служебная подпись. Здесь видно, читается ли
//     список вообще и есть ли нужные колонки. Названий колонок наружу не отдаём —
//     только да/нет и счётчики.
if (isset($_GET['msgcols']) && isset($pdo) && $pdo) {
    $m = [];
    $names = [];
    try {
        foreach ($pdo->query("SHOW COLUMNS FROM messages")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $names[] = (string)($row['Field'] ?? $row['field'] ?? '');
        }
        $m['show_columns'] = 'ok';
        $m['count'] = count($names);
    } catch (Exception $e) {
        $m['show_columns'] = 'ошибка: ' . substr($e->getMessage(), 0, 120);
        $m['count'] = 0;
    }
    foreach (['attachment_url', 'attachment_type', 'attachment_name', 'edited_at'] as $c) {
        $m['has_' . $c] = in_array($c, $names, true);
    }
    // Тот самый запрос, что стоял в messages_page.php: работает ли подстановка в SHOW
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM messages LIKE ?");
        $st->execute(['attachment_url']);
        $m['prepared_like'] = $st->fetch() ? 'находит' : 'НЕ находит';
    } catch (Exception $e) {
        $m['prepared_like'] = 'ошибка: ' . substr($e->getMessage(), 0, 120);
    }
    try { $m['emulate_prepares'] = (bool)$pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES); } catch (Exception $e) {}
    // Сколько сообщений с вложением есть на самом деле — отправка ли сломалась или чтение
    if (!empty($m['has_attachment_url'])) {
        try {
            $m['rows_with_attachment'] = (int)$pdo->query(
                "SELECT COUNT(*) FROM messages WHERE attachment_url IS NOT NULL AND attachment_url <> ''"
            )->fetchColumn();
        } catch (Exception $e) {}
    }
    $r['messages_cols'] = $m;
}

// 5. ?bench=1 — сколько стоят типовые запросы сайта. Нужно, чтобы прикинуть,
//    сколько людей хостинг вытянет, не устраивая проду настоящую нагрузку.
//    Всё только на чтение, данные наружу не отдаются — одни тайминги.
if (isset($_GET['bench']) && isset($pdo) && $pdo) {
    $bench = [];
    $timeit = function ($name, callable $fn) use (&$bench) {
        $t = microtime(true);
        try { $n = $fn(); } catch (Exception $e) { $n = 'ошибка: ' . substr($e->getMessage(), 0, 80); }
        $bench[$name] = ['ms' => round((microtime(true) - $t) * 1000, 1), 'rows' => $n];
    };
    $dummy = '00000000000000000000000000000000';

    // опрос звонков — самый частый запрос на сайте
    $timeit('опрос звонков', function () use ($pdo, $dummy) {
        $cols = "c.*, u.first_name, u.last_name, u.avatar";
        $join = "rtc_calls c LEFT JOIN users u ON u.id = c.from_id";
        $sql = "(SELECT $cols, 'in' AS slot FROM $join WHERE c.to_id = ? AND c.status IN ('ringing','accepted') ORDER BY c.id DESC LIMIT 1)
                UNION ALL (SELECT $cols, 'out' AS slot FROM $join WHERE c.from_id = ? AND c.status IN ('ringing','accepted') ORDER BY c.id DESC LIMIT 1)
                UNION ALL (SELECT $cols, 'end' AS slot FROM $join WHERE c.to_id = ? AND c.status = 'ended' AND c.ended_at > (NOW() - INTERVAL 30 SECOND) ORDER BY c.id DESC LIMIT 1)
                UNION ALL (SELECT $cols, 'end' AS slot FROM $join WHERE c.from_id = ? AND c.status = 'ended' AND c.ended_at > (NOW() - INTERVAL 30 SECOND) ORDER BY c.id DESC LIMIT 1)";
        $st = $pdo->prepare($sql); $st->execute([$dummy, $dummy, $dummy, $dummy]);
        return count($st->fetchAll());
    });

    // присутствие — «кто онлайн»
    $timeit('присутствие', function () use ($pdo) {
        $st = $pdo->query("SELECT user_id FROM user_presence WHERE last_seen > (NOW() - INTERVAL 70 SECOND)");
        return count($st->fetchAll());
    });

    // лента сообщений: 50 последних в самой свежей переписке
    $timeit('переписка (50 сообщений)', function () use ($pdo) {
        $st = $pdo->query("SELECT id FROM messages ORDER BY id DESC LIMIT 50");
        return count($st->fetchAll());
    });

    // сколько всего накопилось — от размера таблиц зависит, как быстро всё это работает
    foreach (['messages', 'users', 'rtc_call_signals', 'rtc_calls'] as $t) {
        $timeit('строк в ' . $t, function () use ($pdo, $t) {
            return (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        });
    }
    $r['bench'] = $bench;
}

$r['total_ms'] = round((microtime(true) - $t0) * 1000);
echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
