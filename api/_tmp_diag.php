<?php
// Временный диагностический скрипт — удалить после использования.
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
if (!function_exists('getDB') && !function_exists('getDbConnection') && !function_exists('getPDO')) {
    require_once __DIR__ . '/db.php';
}
$pdo = function_exists('getDB') ? getDB()
     : (function_exists('getDbConnection') ? getDbConnection()
     : (function_exists('getPDO') ? getPDO() : null));
if (!$pdo) { http_response_code(500); echo json_encode(['error' => 'no db']); exit; }

$cfg = @include __DIR__ . '/dev_tasks_config.php';
$secretToken = is_array($cfg) ? (string)($cfg['token'] ?? '') : '';
$token = $_GET['token'] ?? '';
if (!$secretToken || !hash_equals($secretToken, (string)$token)) { http_response_code(403); echo json_encode(['error' => 'forbidden']); exit; }

$out = [];
try {
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $out['all_tables'] = $tables;
    foreach ($tables as $t) {
        if (stripos($t, 'messag') !== false) {
            $out['columns'][$t] = $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
            $st = $pdo->query("SELECT * FROM `$t` ORDER BY id DESC LIMIT 8");
            $out['sample'][$t] = $st->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (Exception $e) {
    $out['error'] = $e->getMessage();
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
