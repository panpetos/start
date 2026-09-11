<?php
/**
 * server_stats.php — базовая самодиагностика хостинга для админки (диск, память, нагрузка).
 * Данные собираются средствами самого PHP (без shell_exec — на шаред-хостинге он обычно запрещён),
 * поэтому это ориентировочные показатели, а не полноценный мониторинг сервера.
 *
 * GET ?action=overview   — админ
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

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
if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }
try {
    $st = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $st->execute([$userId]);
    if ($st->fetchColumn() !== 'admin') { http_response_code(403); echo json_encode(['error' => 'Доступно только администратору']); exit; }
} catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Ошибка проверки прав']); exit; }

$action = $_GET['action'] ?? '';

if ($action === 'overview') {
    $out = ['ok' => true];

    // Диск (раздел, на котором лежит сайт)
    $dir = __DIR__;
    $free = @disk_free_space($dir);
    $total = @disk_total_space($dir);
    if ($free !== false && $total !== false && $total > 0) {
        $used = $total - $free;
        $out['disk'] = [
            'free_bytes' => (float)$free,
            'total_bytes' => (float)$total,
            'used_bytes' => (float)$used,
            'used_percent' => round($used / $total * 100, 1),
        ];
    } else {
        $out['disk'] = null;
    }

    // Средняя нагрузка (Linux; на части шаред-хостингов недоступно)
    if (function_exists('sys_getloadavg')) {
        $la = @sys_getloadavg();
        $out['load_avg'] = is_array($la) ? $la : null;
    } else {
        $out['load_avg'] = null;
    }

    // Память текущего PHP-процесса (не всего сервера — на шаред-хостинге системная память недоступна)
    $memLimit = ini_get('memory_limit');
    $out['php'] = [
        'version' => PHP_VERSION,
        'memory_limit' => $memLimit,
        'memory_usage_bytes' => (float)memory_get_usage(true),
        'memory_peak_bytes' => (float)memory_get_peak_usage(true),
    ];

    // Размер БД (сумма таблиц текущей схемы) — косвенный индикатор роста данных
    try {
        $st = $pdo->query("SELECT ROUND(SUM(data_length + index_length), 0) AS bytes, COUNT(*) AS tables FROM information_schema.TABLES WHERE table_schema = DATABASE()");
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $out['database'] = $row ? ['size_bytes' => (float)($row['bytes'] ?? 0), 'tables' => (int)($row['tables'] ?? 0)] : null;
    } catch (Exception $e) { $out['database'] = null; }

    // Размер /uploads — часто основной источник роста диска
    $uploadsDir = __DIR__ . '/../uploads';
    $uploadsBytes = 0; $uploadsFiles = 0;
    if (is_dir($uploadsDir)) {
        try {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadsDir, FilesystemIterator::SKIP_DOTS));
            $count = 0;
            foreach ($it as $f) {
                if ($f->isFile()) { $uploadsBytes += $f->getSize(); $uploadsFiles++; }
                if (++$count > 200000) break; // защита от чрезмерно долгого обхода
            }
        } catch (Exception $e) {}
    }
    $out['uploads'] = ['size_bytes' => (float)$uploadsBytes, 'files' => $uploadsFiles];

    echo json_encode($out);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Неизвестное действие']);
