<?php
/**
 * favorites.php — личное «Избранное» пользователя (заметки себе, файлы,
 * пересланные сообщения). Видит только сам пользователь. Используется как
 * псевдо-диалог в chat.html (задача #28).
 *
 * GET  ?action=list                    — все записи текущего пользователя (старые -> новые)
 * POST ?action=add    {content?, attachment_url?, attachment_type?, attachment_name?, source_label?}
 * POST ?action=edit   {id, content}    — изменить текст своей записи (задача #45)
 * POST ?action=delete {id}             — удалить свою запись
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

function ensureFavoritesTable(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS favorite_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(64) NOT NULL,
        content TEXT NULL,
        attachment_url VARCHAR(500) NULL,
        attachment_type VARCHAR(20) NULL,
        attachment_name VARCHAR(255) NULL,
        source_label VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_user (user_id)
    ) DEFAULT CHARSET=utf8mb4");
    // Метка записи: смайл + название категории (как «теги» в Telegram). Добавляем
    // отдельно, а не в CREATE TABLE: у тех, у кого таблица уже есть, CREATE ничего
    // не сделает — новые колонки так и не появились бы.
    $have = [];
    try {
        foreach ($pdo->query("SHOW COLUMNS FROM favorite_messages")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $have[] = (string)($r['Field'] ?? '');
        }
    } catch (Exception $e) { return; }
    if ($have && !in_array('tag_name', $have, true)) {
        try { $pdo->exec("ALTER TABLE favorite_messages ADD COLUMN tag_emoji VARCHAR(16) NULL, ADD COLUMN tag_name VARCHAR(40) NULL"); }
        catch (Exception $e) { /* нет прав на ALTER — метки просто не будут работать */ }
    }
}

/** Есть ли в таблице колонки меток: без них старые установки должны продолжать работать. */
function favHasTags(PDO $pdo) {
    static $has = null;
    if ($has !== null) return $has;
    $has = false;
    try {
        foreach ($pdo->query("SHOW COLUMNS FROM favorite_messages")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ((string)($r['Field'] ?? '') === 'tag_name') { $has = true; break; }
        }
    } catch (Exception $e) { $has = false; }
    return $has;
}

/** Метка: смайл до 2 символов и короткое название. Пустое название = метки нет. */
function favTagFrom($body) {
    $emoji = trim((string)($body['tag_emoji'] ?? ''));
    $name  = trim((string)($body['tag_name'] ?? ''));
    if ($name === '') return [null, null];
    return [mb_substr($emoji, 0, 2) ?: null, mb_substr($name, 0, 40)];
}

$action = $_GET['action'] ?? '';
$body = json_decode(file_get_contents('php://input'), true) ?: [];

try { ensureFavoritesTable($pdo); } catch (Exception $e) {}

if ($action === 'list') {
    try {
        $tagCols = favHasTags($pdo) ? ', tag_emoji, tag_name' : '';
        $st = $pdo->prepare("SELECT id, content, attachment_url, attachment_type, attachment_name, source_label, created_at$tagCols
                              FROM favorite_messages WHERE user_id = ? ORDER BY created_at ASC, id ASC LIMIT 1000");
        $st->execute([$userId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        // Список категорий собираем здесь же: отдельный запрос ради этого не нужен,
        // а чату нужен готовый набор для полосы фильтров.
        $tags = [];
        foreach ($rows as $r) {
            $n = trim((string)($r['tag_name'] ?? ''));
            if ($n === '') continue;
            if (!isset($tags[$n])) $tags[$n] = ['name' => $n, 'emoji' => (string)($r['tag_emoji'] ?? ''), 'count' => 0];
            $tags[$n]['count']++;
        }
        usort($tags, function ($a, $b) { return $b['count'] <=> $a['count'] ?: strcmp($a['name'], $b['name']); });
        echo json_encode(['ok' => true, 'data' => $rows, 'tags' => array_values($tags)], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) { echo json_encode(['ok' => true, 'data' => [], 'tags' => []]); }
    exit;
}

/** Поставить или снять метку у записи. */
if ($action === 'tag' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id обязателен']); exit; }
    if (!favHasTags($pdo)) { http_response_code(400); echo json_encode(['error' => 'Метки недоступны в этой установке']); exit; }
    list($emoji, $name) = favTagFrom($body);
    try {
        $chk = $pdo->prepare("SELECT id FROM favorite_messages WHERE id = ? AND user_id = ? LIMIT 1");
        $chk->execute([$id, $userId]);
        if (!$chk->fetchColumn()) { http_response_code(404); echo json_encode(['error' => 'Запись не найдена']); exit; }
        $pdo->prepare("UPDATE favorite_messages SET tag_emoji = ?, tag_name = ? WHERE id = ? AND user_id = ?")
            ->execute([$emoji, $name, $id, $userId]);
        echo json_encode(['ok' => true, 'tag_emoji' => $emoji, 'tag_name' => $name], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось сохранить метку']); }
    exit;
}

if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $content = isset($body['content']) ? trim((string)$body['content']) : '';
    $attachmentUrl = isset($body['attachment_url']) ? (string)$body['attachment_url'] : null;
    $attachmentType = isset($body['attachment_type']) ? (string)$body['attachment_type'] : null;
    $attachmentName = isset($body['attachment_name']) ? (string)$body['attachment_name'] : null;
    $sourceLabel = isset($body['source_label']) ? (string)$body['source_label'] : null;

    if ($content === '' && !$attachmentUrl) { http_response_code(400); echo json_encode(['error' => 'Пустая запись']); exit; }

    try {
        if (favHasTags($pdo)) {
            list($emoji, $name) = favTagFrom($body);
            $st = $pdo->prepare("INSERT INTO favorite_messages (user_id, content, attachment_url, attachment_type, attachment_name, source_label, tag_emoji, tag_name, created_at)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $st->execute([$userId, $content !== '' ? $content : null, $attachmentUrl, $attachmentType, $attachmentName, $sourceLabel, $emoji, $name]);
        } else {
            $st = $pdo->prepare("INSERT INTO favorite_messages (user_id, content, attachment_url, attachment_type, attachment_name, source_label, created_at)
                                  VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $st->execute([$userId, $content !== '' ? $content : null, $attachmentUrl, $attachmentType, $attachmentName, $sourceLabel]);
        }
        echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось сохранить']); }
    exit;
}

if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($body['id'] ?? 0);
    $content = trim((string)($body['content'] ?? ''));
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id обязателен']); exit; }
    if ($content === '') { http_response_code(400); echo json_encode(['error' => 'Текст не может быть пустым']); exit; }
    $content = mb_substr($content, 0, 5000);
    try {
        // Проверяем владение отдельным SELECT: UPDATE с WHERE id=?+user_id=? при неизменном
        // content вернул бы rowCount()=0 (0 affected rows), и это выглядело бы как «не найдена».
        $chk = $pdo->prepare("SELECT id FROM favorite_messages WHERE id = ? AND user_id = ? LIMIT 1");
        $chk->execute([$id, $userId]);
        if (!$chk->fetchColumn()) { http_response_code(404); echo json_encode(['error' => 'Запись не найдена']); exit; }
        $pdo->prepare("UPDATE favorite_messages SET content = ? WHERE id = ? AND user_id = ?")
            ->execute([$content, $id, $userId]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось сохранить']); }
    exit;
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id обязателен']); exit; }
    try {
        $st = $pdo->prepare("DELETE FROM favorite_messages WHERE id = ? AND user_id = ?");
        $st->execute([$id, $userId]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось удалить']); }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Неизвестное действие']);
