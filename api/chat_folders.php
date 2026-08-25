<?php
/**
 * chat_folders.php — папки в списке чатов (фидбэк админа: «можно добавить папки все,
 * личное, каналы и какие может добавить человек»).
 *
 * Встроенные папки (Все / Личное / Каналы / Группы) считаются на клиенте по типу строки
 * и здесь не хранятся — в БД живут только пользовательские папки и их состав.
 * Папки личные: пользователь видит и меняет только свои.
 *
 * Ключ чата (chat_key) — то же значение, которым чат опознаётся в интерфейсе:
 *   <user_id>          — личный диалог
 *   channel:<psy_id>   — канал психолога
 *   group:<id>         — групповой чат
 *   __fav__            — «Избранное»
 *
 * GET  ?action=list                        — мои папки со списком ключей чатов
 * POST ?action=create      {name}          — создать папку
 * POST ?action=rename      {id, name}      — переименовать
 * POST ?action=delete      {id}            — удалить папку (состав удаляется вместе с ней)
 * POST ?action=toggle-item {id, chat_key}  — добавить/убрать чат из папки, вернёт in:true|false
 * POST ?action=set-items   {id, keys:[]}   — заменить состав папки целиком
 * POST ?action=ensure-defaults             — задача №81: разово (на человека) завести папку
 *      «Важное» с разделами ИИ-ассистент/Психологи/Лента/Блог/Тех поддержка и закрепить
 *      их же — чтобы новый (и уже существующий) человек не потерял эти разделы среди
 *      личных чатов. Идемпотентно — метка в default_seeded, повторный вызов молчит.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/schema_util.php';
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

function out($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$foldersError = '';
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_folders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(64) NOT NULL,
        name VARCHAR(60) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        INDEX idx_user (user_id)
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_folder_items (
        folder_id INT NOT NULL,
        chat_key VARCHAR(120) NOT NULL,
        PRIMARY KEY (folder_id, chat_key)
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_default_seeded (
        user_id VARCHAR(64) NOT NULL PRIMARY KEY,
        seeded_at DATETIME NOT NULL
    ) DEFAULT CHARSET=utf8mb4");
    // Та же таблица, что и в chat_pins.php (ensure-defaults закрепляет разделы сама,
    // а порядок подключения файлов не гарантирован — на всякий случай создаём и здесь).
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_pinned_chats (
        user_id VARCHAR(64) NOT NULL,
        chat_key VARCHAR(120) NOT NULL,
        pinned_at DATETIME NOT NULL,
        PRIMARY KEY (user_id, chat_key)
    ) DEFAULT CHARSET=utf8mb4");
    // Наши таблицы создаются в utf8mb4_0900_ai_ci, а users/psychologists живут в
    // utf8mb4_unicode_ci — без выравнивания любой JOIN падает с ошибкой 1267.
    psy_align_collation($pdo, ['chat_folders', 'chat_folder_items', 'chat_default_seeded', 'chat_pinned_chats']);
} catch (Exception $e) {
    $foldersError = $e->getMessage();
}

$action = $_GET['action'] ?? '';
$body = json_decode(file_get_contents('php://input'), true) ?: [];

/** Папка существует и принадлежит текущему пользователю. */
function ownFolder(PDO $pdo, $id, $userId) {
    $st = $pdo->prepare("SELECT * FROM chat_folders WHERE id = ? AND user_id = ?");
    $st->execute([(int)$id, $userId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function cleanName($n) {
    $n = trim(preg_replace('/\s+/u', ' ', (string)$n));
    if (function_exists('mb_substr')) $n = mb_substr($n, 0, 40, 'UTF-8');
    else $n = substr($n, 0, 40);
    return $n;
}

try {
    if ($action === 'list') {
        if ($foldersError) out(['ok' => false, 'error' => 'Папки недоступны: ' . $foldersError], 500);
        $st = $pdo->prepare("SELECT id, name, sort_order FROM chat_folders WHERE user_id = ? ORDER BY sort_order, id");
        $st->execute([$userId]);
        $folders = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($folders) {
            $ids = array_map(function ($f) { return (int)$f['id']; }, $folders);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $it = $pdo->prepare("SELECT folder_id, chat_key FROM chat_folder_items WHERE folder_id IN ($ph)");
            $it->execute($ids);
            $byFolder = [];
            foreach ($it->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $byFolder[(int)$r['folder_id']][] = $r['chat_key'];
            }
            foreach ($folders as &$f) {
                $f['id'] = (int)$f['id'];
                $f['keys'] = $byFolder[$f['id']] ?? [];
            }
            unset($f);
        }
        out(['ok' => true, 'data' => $folders]);
    }

    if ($action === 'create') {
        if ($foldersError) out(['ok' => false, 'error' => 'Папки недоступны: ' . $foldersError], 500);
        $name = cleanName($body['name'] ?? '');
        if ($name === '') out(['ok' => false, 'error' => 'Укажите название папки'], 400);
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM chat_folders WHERE user_id = ?");
        $cnt->execute([$userId]);
        if ((int)$cnt->fetchColumn() >= 20) out(['ok' => false, 'error' => 'Больше 20 папок не поддерживается'], 400);
        $ord = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM chat_folders WHERE user_id = ?");
        $ord->execute([$userId]);
        $st = $pdo->prepare("INSERT INTO chat_folders (user_id, name, sort_order, created_at) VALUES (?, ?, ?, NOW())");
        $st->execute([$userId, $name, (int)$ord->fetchColumn()]);
        out(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'name' => $name]);
    }

    if ($action === 'rename') {
        $f = ownFolder($pdo, $body['id'] ?? 0, $userId);
        if (!$f) out(['ok' => false, 'error' => 'Папка не найдена'], 404);
        $name = cleanName($body['name'] ?? '');
        if ($name === '') out(['ok' => false, 'error' => 'Укажите название папки'], 400);
        $st = $pdo->prepare("UPDATE chat_folders SET name = ? WHERE id = ?");
        $st->execute([$name, (int)$f['id']]);
        out(['ok' => true, 'name' => $name]);
    }

    if ($action === 'delete') {
        $f = ownFolder($pdo, $body['id'] ?? 0, $userId);
        if (!$f) out(['ok' => false, 'error' => 'Папка не найдена'], 404);
        $pdo->prepare("DELETE FROM chat_folder_items WHERE folder_id = ?")->execute([(int)$f['id']]);
        $pdo->prepare("DELETE FROM chat_folders WHERE id = ?")->execute([(int)$f['id']]);
        out(['ok' => true]);
    }

    if ($action === 'toggle-item') {
        $f = ownFolder($pdo, $body['id'] ?? 0, $userId);
        if (!$f) out(['ok' => false, 'error' => 'Папка не найдена'], 404);
        $key = trim((string)($body['chat_key'] ?? ''));
        if ($key === '' || strlen($key) > 120) out(['ok' => false, 'error' => 'Некорректный чат'], 400);
        $ex = $pdo->prepare("SELECT 1 FROM chat_folder_items WHERE folder_id = ? AND chat_key = ?");
        $ex->execute([(int)$f['id'], $key]);
        if ($ex->fetchColumn()) {
            $pdo->prepare("DELETE FROM chat_folder_items WHERE folder_id = ? AND chat_key = ?")
                ->execute([(int)$f['id'], $key]);
            out(['ok' => true, 'in' => false]);
        }
        $pdo->prepare("INSERT INTO chat_folder_items (folder_id, chat_key) VALUES (?, ?)")
            ->execute([(int)$f['id'], $key]);
        out(['ok' => true, 'in' => true]);
    }

    if ($action === 'set-items') {
        $f = ownFolder($pdo, $body['id'] ?? 0, $userId);
        if (!$f) out(['ok' => false, 'error' => 'Папка не найдена'], 404);
        $keys = is_array($body['keys'] ?? null) ? $body['keys'] : [];
        $pdo->prepare("DELETE FROM chat_folder_items WHERE folder_id = ?")->execute([(int)$f['id']]);
        $ins = $pdo->prepare("INSERT INTO chat_folder_items (folder_id, chat_key) VALUES (?, ?)");
        $seen = [];
        foreach ($keys as $k) {
            $k = trim((string)$k);
            if ($k === '' || strlen($k) > 120 || isset($seen[$k])) continue;
            $seen[$k] = true;
            $ins->execute([(int)$f['id'], $k]);
        }
        out(['ok' => true, 'count' => count($seen)]);
    }

    if ($action === 'ensure-defaults') {
        if ($foldersError) out(['ok' => true, 'done' => false, 'reason' => 'no-table']);
        $mark = $pdo->prepare("SELECT 1 FROM chat_default_seeded WHERE user_id = ?");
        $mark->execute([$userId]);
        if ($mark->fetchColumn()) out(['ok' => true, 'done' => false, 'reason' => 'already']);
        // Отмечаем сразу — двойной вызов (два тика загрузки чата) не должен завести две папки.
        $pdo->prepare("INSERT IGNORE INTO chat_default_seeded (user_id, seeded_at) VALUES (?, NOW())")
            ->execute([$userId]);

        $defaultKeys = ['__ai__', '__psy__', '__feed__', '__blog__', '__support__'];

        $st = $pdo->prepare("SELECT id FROM chat_folders WHERE user_id = ? AND name = ? LIMIT 1");
        $st->execute([$userId, 'Важное']);
        $folderId = (int)$st->fetchColumn();
        if (!$folderId) {
            $ord = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM chat_folders WHERE user_id = ?");
            $ord->execute([$userId]);
            $ins = $pdo->prepare("INSERT INTO chat_folders (user_id, name, sort_order, created_at) VALUES (?, 'Важное', ?, NOW())");
            $ins->execute([$userId, (int)$ord->fetchColumn()]);
            $folderId = (int)$pdo->lastInsertId();
        }
        $insItem = $pdo->prepare("INSERT IGNORE INTO chat_folder_items (folder_id, chat_key) VALUES (?, ?)");
        $insPin = $pdo->prepare("INSERT IGNORE INTO chat_pinned_chats (user_id, chat_key, pinned_at) VALUES (?, ?, NOW())");
        foreach ($defaultKeys as $k) {
            $insItem->execute([$folderId, $k]);
            try { $insPin->execute([$userId, $k]); } catch (Exception $e) {}
        }
        out(['ok' => true, 'done' => true, 'folder_id' => $folderId]);
    }

    out(['ok' => false, 'error' => 'Неизвестное действие'], 400);
} catch (Exception $e) {
    out(['ok' => false, 'error' => $e->getMessage()], 500);
}
