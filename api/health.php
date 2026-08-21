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
$sd = @ini_get('session.save_path');
$r['session_path'] = $sd ?: '(по умолчанию)';
if ($sd && is_dir($sd)) {
    $cnt = @shell_exec('ls -U ' . escapeshellarg($sd) . ' 2>/dev/null | head -100000 | wc -l');
    $r['session_files'] = ($cnt !== null && trim((string)$cnt) !== '') ? (int)trim($cnt) : null;
}

$r['total_ms'] = round((microtime(true) - $t0) * 1000);
echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
