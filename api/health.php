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
    foreach (['attachment_url', 'attachment_type', 'attachment_name', 'edited_at',
              'is_read', 'read_at', 'seen', 'status', 'deleted_at'] as $c) {
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

// 4c. ?promo=1 — почему промокод бесплатной записи «не срабатывает».
//     Здесь две стороны, которые обязаны сойтись: админка ПИШЕТ настройку через
//     admin_ext.php, а promo_booking.php её ЧИТАЕТ. Ровно тут и была поломка:
//     на этом хостинге колонки называются k/v, а чтение шло по key_name/value.
//     Обе стороны теперь определяют колонки одинаково — это видно ниже.
//     Значение наружу не отдаём: только куда пишем, куда читаем и пусто там или нет.
if (isset($_GET['promo']) && isset($pdo) && $pdo) {
    $p = [];

    // Так таблицу настроек ищет admin_ext.php (сторона записи)
    $writeTable = null; $writeKey = null; $writeVal = null;
    foreach (['settings', 'site_settings', 'options', 'config'] as $table) {
        try { $cols = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN); }
        catch (Exception $e) { continue; }
        if (!$cols) continue;
        $lc = array_map('strtolower', $cols);
        $k = null; $v = null;
        foreach (['setting_key', 'key_name', 'key', 'name', 'k', 'option_name', 'param', 'param_name'] as $c) {
            $i = array_search($c, $lc, true); if ($i !== false) { $k = $cols[$i]; break; }
        }
        foreach (['setting_value', 'value', 'val', 'v', 'option_value', 'data'] as $c) {
            $i = array_search($c, $lc, true); if ($i !== false) { $v = $cols[$i]; break; }
        }
        if ($k && $v) { $writeTable = $table; $writeKey = $k; $writeVal = $v; break; }
    }
    $p['admin_writes_to'] = $writeTable ? "$writeTable($writeKey, $writeVal)" : 'таблица настроек не найдена';
    // Сторона чтения — тот же resolver, которым теперь пользуется promo_booking.php
    require_once __DIR__ . '/settings_lib.php';
    list($rt, $rk, $rv) = psySettingsCols($pdo);
    $p['promo_reads_from'] = $rt ? "$rt($rk, $rv)" : 'таблица настроек не найдена';
    $p['same_place'] = ($writeTable === $rt && $writeKey === $rk && $writeVal === $rv);

    $code = psySetting($pdo, 'free_promo_code', '');
    $p['row_exists'] = ($code !== '');
    $p['value_empty'] = (trim($code) === '');
    $p['value_len'] = strlen(trim($code));
    $p['limit_value'] = psySetting($pdo, 'free_promo_limit', '(нет строки)');
    // Соседний ключ из той же формы «Тарифы» — если он читается, а промокод нет,
    // значит форма сохраняется, а терялся именно ключ промокода.
    $p['price_self_exists'] = (psySetting($pdo, 'price_self', '') !== '');

    if ($rt) {
        try { $p['settings_rows'] = (int)$pdo->query("SELECT COUNT(*) FROM `$rt`")->fetchColumn(); }
        catch (Exception $e) { $p['settings_rows'] = 'ошибка: ' . substr($e->getMessage(), 0, 80); }
    }
    try { $p['promo_bookings'] = (int)$pdo->query("SELECT COUNT(*) FROM promo_bookings")->fetchColumn(); }
    catch (Exception $e) { $p['promo_bookings'] = '(таблицы ещё нет)'; }

    $r['promo'] = $p;
}

// 4d. ?appt=1 — почему не удаляется запись из админки.
//     Удаление падало с «Не удалось удалить запись», а какая именно связь мешает —
//     снаружи не видно. Здесь перечислены таблицы, ссылающиеся на appointments
//     внешним ключом, и таблицы с колонкой appointment_id без ключа. Данных не
//     отдаём — только имена таблиц и колонок.
if (isset($_GET['appt']) && isset($pdo) && $pdo) {
    $a = ['fk' => [], 'by_column' => []];
    try {
        $rows = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME
                               FROM information_schema.KEY_COLUMN_USAGE
                              WHERE REFERENCED_TABLE_NAME = 'appointments'
                                AND TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $a['fk'][] = ($r['TABLE_NAME'] ?? '?') . '.' . ($r['COLUMN_NAME'] ?? '?')
                       . ' [' . ($r['CONSTRAINT_NAME'] ?? '') . ']';
        }
    } catch (Exception $e) { $a['fk_error'] = substr($e->getMessage(), 0, 160); }
    try {
        $rows = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
                              WHERE TABLE_SCHEMA = DATABASE()
                                AND COLUMN_NAME IN ('appointment_id','appt_id')")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) $a['by_column'][] = ($r['TABLE_NAME'] ?? '?') . '.' . ($r['COLUMN_NAME'] ?? '?');
    } catch (Exception $e) { $a['col_error'] = substr($e->getMessage(), 0, 160); }
    try {
        // Разбивка по статусам: в админке часть строк показывалась как «—», то есть
        // статус пустой. Видно, какой путь записи его не проставляет.
        foreach ($pdo->query("SELECT COALESCE(NULLIF(status,''),'(пусто)') AS s, COUNT(*) AS n
                                FROM appointments GROUP BY s")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $a['by_status'][(string)$row['s']] = (int)$row['n'];
        }
    } catch (Exception $e) { $a['status_error'] = substr($e->getMessage(), 0, 160); }
    try {
        // Уборка неоплаченных броней опирается на created_at — надо видеть, есть ли он.
        $cols = [];
        foreach ($pdo->query("SHOW COLUMNS FROM appointments") as $c) $cols[] = (string)($c['Field'] ?? '');
        $a['колонки'] = $cols;
        $a['appointments_rows'] = (int)$pdo->query("SELECT COUNT(*) FROM appointments")->fetchColumn();
        $a['engine'] = (string)$pdo->query("SELECT ENGINE FROM information_schema.TABLES
                                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments'")->fetchColumn();
    } catch (Exception $e) {}
    $r['appointments'] = $a;
}

// 4b. ?creds=1 — что реально лежит в psychologist_credentials. У психолога пропали
//     сертификаты из карточки: карточка берёт их запросом с условиями по type и url,
//     а его catch молчит при ошибке. Здесь видно, есть ли строки вообще, у скольких
//     заполнен url и совпадает ли привязка (psychologist_id / user_id). Ссылок и
//     самих ИНН наружу не отдаём — одни счётчики.
if (isset($_GET['creds']) && isset($pdo) && $pdo) {
    $c = [];
    try {
        foreach ($pdo->query("SELECT type,
                                     COUNT(*) AS n,
                                     SUM(CASE WHEN url IS NOT NULL AND url <> '' THEN 1 ELSE 0 END) AS with_url,
                                     SUM(CASE WHEN psychologist_id IS NULL OR psychologist_id = '' THEN 1 ELSE 0 END) AS no_psy_id
                                FROM psychologist_credentials GROUP BY type")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $c['по_типам'][(string)$row['type']] = [
                'всего' => (int)$row['n'],
                'с_файлом' => (int)$row['with_url'],
                'без_привязки_к_психологу' => (int)$row['no_psy_id'],
            ];
        }
        $c['всего_строк'] = (int)$pdo->query("SELECT COUNT(*) FROM psychologist_credentials")->fetchColumn();
    } catch (Exception $e) { $c['ошибка'] = substr($e->getMessage(), 0, 200); }
    // Совпадает ли psychologist_id в документах с id из таблицы психологов: если
    // регистрация записала туда user_id, публичный запрос карточки ничего не найдёт.
    try {
        $c['привязка_бьётся_с_psychologists'] = (int)$pdo->query(
            "SELECT COUNT(*) FROM psychologist_credentials pc
               JOIN psychologists p ON p.id = pc.psychologist_id")->fetchColumn();
        $c['привязка_через_user_id'] = (int)$pdo->query(
            "SELECT COUNT(*) FROM psychologist_credentials pc
               JOIN psychologists p ON p.user_id = pc.user_id")->fetchColumn();
    } catch (Exception $e) { $c['ошибка_привязки'] = substr($e->getMessage(), 0, 200); }
    // Построчно: к кому привязан документ и существует ли этот психолог/пользователь.
    // Идентификаторы и так отдаёт публичный поиск психологов, ничего нового не раскрываем.
    try {
        $rows = $pdo->query("SELECT pc.id, pc.type,
                                    pc.psychologist_id, pc.user_id,
                                    (pc.url IS NOT NULL AND pc.url <> '') AS has_url,
                                    (SELECT COUNT(*) FROM psychologists p WHERE p.id = pc.psychologist_id) AS psy_ok,
                                    (SELECT COUNT(*) FROM users u WHERE u.id = pc.user_id) AS user_ok,
                                    (SELECT COUNT(*) FROM psychologists p2 WHERE p2.user_id = pc.user_id) AS psy_by_user
                               FROM psychologist_credentials pc ORDER BY pc.id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $c['строки'][] = [
                'id' => (int)$row['id'],
                'тип' => (string)$row['type'],
                'файл' => ((int)$row['has_url'] === 1),
                'psychologist_id' => (string)$row['psychologist_id'],
                'такой_психолог_есть' => ((int)$row['psy_ok'] > 0),
                'user_id' => (string)$row['user_id'],
                'такой_пользователь_есть' => ((int)$row['user_ok'] > 0),
                'психолог_по_user_id' => ((int)$row['psy_by_user'] > 0),
            ];
        }
    } catch (Exception $e) { $c['ошибка_строк'] = substr($e->getMessage(), 0, 200); }
    $r['credentials'] = $c;
}

// 4c. ?support=1 — обращения в поддержку. Админ сообщил, что не видит сообщений от
//     людей. Надо отделить «сообщения не приходят» от «приходят, но не показываются»:
//     здесь видно число обращений, число сообщений, когда пришло последнее и сколько
//     обращений привязано к зарегистрированным пользователям. Текстов не отдаём.
if (isset($_GET['support']) && isset($pdo) && $pdo) {
    $sp = [];
    try {
        $sp['обращений'] = (int)$pdo->query("SELECT COUNT(*) FROM support_threads")->fetchColumn();
        $sp['из_них_у_зарегистрированных'] = (int)$pdo->query("SELECT COUNT(*) FROM support_threads WHERE user_id IS NOT NULL AND user_id <> ''")->fetchColumn();
        $sp['сообщений_всего'] = (int)$pdo->query("SELECT COUNT(*) FROM support_messages")->fetchColumn();
        foreach ($pdo->query("SELECT sender, COUNT(*) n, MAX(created_at) last_at
                                FROM support_messages GROUP BY sender")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sp['по_отправителю'][(string)$row['sender']] = [
                'сообщений' => (int)$row['n'],
                'последнее' => (string)$row['last_at'],
            ];
        }
        // Обращения, где последнее слово за человеком и админ его ещё не открывал.
        $sp['ждут_ответа'] = (int)$pdo->query(
            "SELECT COUNT(*) FROM support_threads t
              WHERE EXISTS (SELECT 1 FROM support_messages m
                             WHERE m.thread_id = t.id AND m.sender = 'user'
                               AND (t.admin_read_at IS NULL OR m.created_at > t.admin_read_at))")->fetchColumn();
    } catch (Exception $e) { $sp['ошибка'] = substr($e->getMessage(), 0, 200); }
    $r['support'] = $sp;
}

// 4d. ?dm=1 — личные сообщения в целом. У админа диалог показывает превью
//     последнего сообщения, а сама переписка открывается пустой. Надо отделить
//     «сообщения не дошли до базы» от «дошли, но не читаются». Только счётчики и
//     время последнего — ни текстов, ни имён.
if (isset($_GET['dm']) && isset($pdo) && $pdo) {
    $dm = [];
    try {
        $dm['сообщений_всего'] = (int)$pdo->query("SELECT COUNT(*) FROM messages")->fetchColumn();
        $dm['последнее'] = (string)$pdo->query("SELECT MAX(created_at) FROM messages")->fetchColumn();
        $dm['с_вложением'] = (int)$pdo->query("SELECT COUNT(*) FROM messages
                                                WHERE attachment_url IS NOT NULL AND attachment_url <> ''")->fetchColumn();
        $dm['последнее_с_вложением'] = (string)$pdo->query("SELECT MAX(created_at) FROM messages
                                                             WHERE attachment_url IS NOT NULL AND attachment_url <> ''")->fetchColumn();
        // Сообщения, адресованные не человеку, а «support»: так пишут те, кто
        // выбрал «Тех поддержку» в своём списке чатов. Их читает админ, и если их
        // запрос ищет строго переписку двух людей, такие строки в неё не попадают.
        $dm['адресовано_support'] = (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE receiver_id = 'support'")->fetchColumn();
        $dm['отправлено_от_support'] = (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE sender_id = 'support'")->fetchColumn();
        $dm['последнее_к_support'] = (string)$pdo->query("SELECT MAX(created_at) FROM messages WHERE receiver_id = 'support'")->fetchColumn();

        // Сообщения за последние двое суток — по дням, чтобы видеть, идут ли они вообще.
        foreach ($pdo->query("SELECT DATE(created_at) d, COUNT(*) n FROM messages
                               WHERE created_at > DATE_SUB(NOW(), INTERVAL 3 DAY)
                               GROUP BY d ORDER BY d")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $dm['по_дням'][(string)$row['d']] = (int)$row['n'];
        }
    } catch (Exception $e) { $dm['ошибка'] = substr($e->getMessage(), 0, 200); }
    $r['messages'] = $dm;
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
