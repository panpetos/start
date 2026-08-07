<?php
/**
 * site_structure.php — редактируемая майнд-мап структуры сайта (задача #38): узлы
 * (названо не "sitemap.php" — на хостинге reg.ru этот путь перехватывается
 * их собственной инфраструктурой и не доходит до PHP, отдавая их 404/403).
 * (прямоугольники/кружки с подписью) и связи между ними, чтобы визуально
 * держать в голове, из чего состоит сайт, и обновлять схему по мере
 * изменений (например, смена платёжного провайдера). Одна общая схема на
 * весь проект, только для администраторов.
 *
 * GET  ?action=get                    — текущая схема {nodes, edges, updated_at}
 * POST ?action=save {nodes, edges}     — перезаписать схему целиком
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
$pdo = function_exists('getDB') ? getDB()
     : (function_exists('getDbConnection') ? getDbConnection()
     : (function_exists('getPDO') ? getPDO() : null));
if (!$pdo) { http_response_code(500); echo json_encode(['error' => 'Нет подключения к БД']); exit; }

if (session_status() === PHP_SESSION_NONE) session_start();
$uid = $_SESSION['user_id'] ?? null;
$isAdmin = false;
if ($uid) {
    try { $st = $pdo->prepare("SELECT role FROM users WHERE id=? LIMIT 1"); $st->execute([$uid]); $isAdmin = ((string)$st->fetchColumn() === 'admin'); } catch (Exception $e) {}
}

function out($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
if (!$isAdmin) out(['error' => 'Доступ только для администратора'], 403);

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sitemap_doc (
        id INT PRIMARY KEY,
        data LONGTEXT NOT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_by VARCHAR(64) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

$action = $_GET['action'] ?? '';
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

function defaultSitemap() {
    return [
        'nodes' => [
            ['id' => 'n1', 'x' => 60,  'y' => 60,  'label' => 'Сайт psytalk.pro', 'color' => '#7C3AED'],
            ['id' => 'n2', 'x' => 340, 'y' => 60,  'label' => 'Клиенты',          'color' => '#2563EB'],
            ['id' => 'n3', 'x' => 620, 'y' => 60,  'label' => 'Психологи',        'color' => '#059669'],
            ['id' => 'n4', 'x' => 340, 'y' => 220, 'label' => 'Оплаты (ЮKassa)',  'color' => '#D97706'],
            ['id' => 'n5', 'x' => 620, 'y' => 220, 'label' => 'Админка',          'color' => '#DB2777'],
        ],
        'edges' => [
            ['from' => 'n1', 'to' => 'n2'],
            ['from' => 'n1', 'to' => 'n3'],
            ['from' => 'n2', 'to' => 'n4'],
            ['from' => 'n3', 'to' => 'n4'],
            ['from' => 'n1', 'to' => 'n5'],
        ],
    ];
}

if ($action === 'get') {
    try {
        $st = $pdo->prepare("SELECT data, updated_at FROM sitemap_doc WHERE id = 1 LIMIT 1");
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $row = null; }
    if (!$row) {
        out(['ok' => true, 'data' => defaultSitemap(), 'updated_at' => null, 'is_default' => true]);
    }
    $data = json_decode($row['data'], true);
    if (!is_array($data)) $data = defaultSitemap();
    out(['ok' => true, 'data' => $data, 'updated_at' => $row['updated_at'], 'is_default' => false]);
}

if ($action === 'save') {
    $nodes = is_array($body['nodes'] ?? null) ? $body['nodes'] : [];
    $edges = is_array($body['edges'] ?? null) ? $body['edges'] : [];
    $data = json_encode(['nodes' => $nodes, 'edges' => $edges], JSON_UNESCAPED_UNICODE);
    try {
        $st = $pdo->prepare("INSERT INTO sitemap_doc (id, data, updated_at, updated_by) VALUES (1, ?, NOW(), ?)
                             ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = NOW(), updated_by = VALUES(updated_by)");
        $st->execute([$data, $uid]);
        out(['ok' => true]);
    } catch (Exception $e) { out(['error' => 'Не удалось сохранить схему'], 500); }
}

out(['error' => 'Неизвестное действие'], 400);
