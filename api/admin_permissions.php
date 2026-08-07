<?php
/**
 * admin_permissions.php — ограничение видимых разделов для дополнительных акков админа
 * (например техподдержка/бухгалтер — не всё нужно видеть).
 *
 * Модель: если для admin-пользователя нет строки в admin_permissions — у него полный доступ
 * (обратная совместимость с уже существующими админами). Явная строка со списком разделов
 * ограничивает видимость сайдбара до этого списка. Управлять чужими правами может только
 * админ с полным доступом («супер-админ») — это не даёт ограниченному админу выдать себе
 * больше прав.
 *
 * GET  ?action=my                       — мои разрешённые разделы (null = полный доступ)
 * GET  ?action=list                     — все админы + их права (только супер-админ)
 * POST ?action=set {user_id, sections}  — задать список разделов (пусто/[] = полный доступ) (только супер-админ)
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
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }

try {
    $st = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $st->execute([$userId]);
    if ($st->fetchColumn() !== 'admin') { http_response_code(403); echo json_encode(['error' => 'Доступно только администратору']); exit; }
} catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Ошибка проверки прав']); exit; }

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_permissions (
        user_id VARCHAR(64) PRIMARY KEY,
        sections TEXT NOT NULL,
        updated_at DATETIME NOT NULL
    ) DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

const ADMIN_SECTIONS = ['overview','users','psychologists','appointments','calls','replacements','supervision',
    'payments','tariffs','robokassa','yookassa','subscribers','referrals','activity','support','devtasks',
    'notifications','notes','adminperms'];

function getMySections(PDO $pdo, $uid) {
    try {
        $st = $pdo->prepare("SELECT sections FROM admin_permissions WHERE user_id = ?");
        $st->execute([$uid]);
        $row = $st->fetchColumn();
        if ($row === false) return null; // нет строки — полный доступ
        $arr = json_decode($row, true);
        return is_array($arr) ? array_values(array_intersect($arr, ADMIN_SECTIONS)) : null;
    } catch (Exception $e) { return null; }
}

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?: [];

if ($action === 'my') {
    echo json_encode(['ok' => true, 'sections' => getMySections($pdo, $userId), 'all_sections' => ADMIN_SECTIONS]);
    exit;
}

$mySections = getMySections($pdo, $userId);
$isSuperAdmin = ($mySections === null); // полный доступ = может управлять другими

if ($action === 'list') {
    if (!$isSuperAdmin) { http_response_code(403); echo json_encode(['error' => 'Управлять правами может только администратор с полным доступом']); exit; }
    try {
        $admins = $pdo->query("SELECT id, first_name, last_name, email FROM users WHERE role = 'admin' ORDER BY first_name")->fetchAll(PDO::FETCH_ASSOC);
        $perms = [];
        foreach ($pdo->query("SELECT user_id, sections FROM admin_permissions") as $r) { $perms[$r['user_id']] = json_decode($r['sections'], true) ?: []; }
        foreach ($admins as &$a) { $a['sections'] = array_key_exists($a['id'], $perms) ? $perms[$a['id']] : null; }
        echo json_encode(['ok' => true, 'data' => $admins, 'all_sections' => ADMIN_SECTIONS]);
    } catch (Exception $e) { echo json_encode(['ok' => true, 'data' => [], 'all_sections' => ADMIN_SECTIONS]); }
    exit;
}

if ($action === 'set' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isSuperAdmin) { http_response_code(403); echo json_encode(['error' => 'Управлять правами может только администратор с полным доступом']); exit; }
    $targetId = (string)($input['user_id'] ?? '');
    $sections = is_array($input['sections'] ?? null) ? array_values(array_intersect($input['sections'], ADMIN_SECTIONS)) : [];
    if ($targetId === '') { http_response_code(400); echo json_encode(['error' => 'user_id обязателен']); exit; }
    if ($targetId === $userId) { http_response_code(400); echo json_encode(['error' => 'Нельзя ограничить самого себя']); exit; }
    try {
        if (!$sections) {
            $pdo->prepare("DELETE FROM admin_permissions WHERE user_id = ?")->execute([$targetId]);
        } else {
            if (!in_array('overview', $sections, true)) $sections[] = 'overview'; // всегда доступен минимум обзор
            $st = $pdo->prepare("INSERT INTO admin_permissions (user_id, sections, updated_at) VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE sections = VALUES(sections), updated_at = NOW()");
            $st->execute([$targetId, json_encode(array_values($sections))]);
        }
        echo json_encode(['ok' => true]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось сохранить']); }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Неизвестное действие']);
