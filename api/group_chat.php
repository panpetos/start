<?php
/**
 * group_chat.php — групповые чаты (задача из фидбэка админа: «создать возможность
 * создать общий чат любому человеку как в telegram»). Отдельная подсистема от
 * server-only messages.php — свои таблицы, свой протокол, не трогает 1:1 переписку.
 * Создать группу и добавлять в неё участников может любой авторизованный пользователь
 * любой роли (клиент/психолог/админ).
 *
 * GET  ?action=list                                  — мои группы (последнее сообщение, непрочитанные)
 * GET  ?action=messages&group_id=X                    — сообщения группы (отмечает как прочитанные)
 * POST ?action=create   {name, member_ids:[]}          — создать группу, автор становится owner
 * POST ?action=send     {group_id, content}            — отправить сообщение
 * POST ?action=edit-message {message_id, content}      — редактировать своё сообщение (лог правок)
 * GET  ?action=members&group_id=X                      — участники группы
 * POST ?action=add-members {group_id, member_ids:[]}   — добавить участников (любой участник группы)
 * POST ?action=leave    {group_id}                     — выйти из группы
 * POST ?action=rename   {group_id, name}                — переименовать (только владелец)
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

function out($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

function ensureGroupTables(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_groups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        created_by VARCHAR(64) NOT NULL,
        created_at DATETIME NOT NULL
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_group_members (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_id INT NOT NULL,
        user_id VARCHAR(64) NOT NULL,
        role VARCHAR(20) NOT NULL DEFAULT 'member',
        last_read_message_id INT NOT NULL DEFAULT 0,
        joined_at DATETIME NOT NULL,
        UNIQUE KEY uniq_member (group_id, user_id),
        INDEX idx_user (user_id)
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_group_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_id INT NOT NULL,
        sender_id VARCHAR(64) NOT NULL,
        content TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        edited_at DATETIME NULL,
        INDEX idx_group (group_id)
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS group_message_edit_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        message_id INT NOT NULL,
        editor_id VARCHAR(64) NOT NULL,
        old_content TEXT NULL,
        new_content TEXT NULL,
        edited_at DATETIME NOT NULL,
        INDEX idx_message (message_id)
    ) DEFAULT CHARSET=utf8mb4");
}
try { ensureGroupTables($pdo); } catch (Exception $e) {}

function isMember(PDO $pdo, $groupId, $userId) {
    try {
        $st = $pdo->prepare("SELECT role FROM chat_group_members WHERE group_id = ? AND user_id = ? LIMIT 1");
        $st->execute([$groupId, $userId]);
        return $st->fetchColumn();
    } catch (Exception $e) { return false; }
}

$action = $_GET['action'] ?? '';
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') out(['error' => 'Введите название группы'], 400);
    $name = mb_substr($name, 0, 255);
    $memberIds = array_values(array_unique(array_filter(array_map('strval', (array)($body['member_ids'] ?? [])), fn($v) => $v !== '' && $v !== (string)$userId)));
    $memberIds = array_slice($memberIds, 0, 200);
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare("INSERT INTO chat_groups (name, created_by, created_at) VALUES (?, ?, NOW())");
        $st->execute([$name, $userId]);
        $groupId = $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO chat_group_members (group_id, user_id, role, joined_at) VALUES (?, ?, 'owner', NOW())")->execute([$groupId, $userId]);
        if ($memberIds) {
            $ins = $pdo->prepare("INSERT IGNORE INTO chat_group_members (group_id, user_id, role, joined_at) VALUES (?, ?, 'member', NOW())");
            foreach ($memberIds as $mid) $ins->execute([$groupId, $mid]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        out(['error' => 'Не удалось создать группу'], 500);
    }
    out(['ok' => true, 'group_id' => (int)$groupId]);
}

if ($action === 'list') {
    try {
        $st = $pdo->prepare("
            SELECT g.id, g.name, g.created_by, m.role, m.last_read_message_id,
                   (SELECT COUNT(*) FROM chat_group_members WHERE group_id = g.id) AS members_count,
                   (SELECT content FROM chat_group_messages WHERE group_id = g.id ORDER BY id DESC LIMIT 1) AS last_message,
                   (SELECT created_at FROM chat_group_messages WHERE group_id = g.id ORDER BY id DESC LIMIT 1) AS last_message_at,
                   (SELECT MAX(id) FROM chat_group_messages WHERE group_id = g.id) AS last_message_id,
                   (SELECT COUNT(*) FROM chat_group_messages WHERE group_id = g.id AND id > m.last_read_message_id) AS unread_count
            FROM chat_group_members m
            JOIN chat_groups g ON g.id = m.group_id
            WHERE m.user_id = ?
            ORDER BY (last_message_at IS NULL), last_message_at DESC
        ");
        $st->execute([$userId]);
        out(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { out(['ok' => true, 'data' => []]); }
}

if ($action === 'messages') {
    $groupId = (int)($_GET['group_id'] ?? 0);
    if (!$groupId || !isMember($pdo, $groupId, $userId)) out(['error' => 'Группа не найдена или вы не участник'], 403);
    try {
        $st = $pdo->prepare("SELECT m.id, m.sender_id, m.content, m.created_at, m.edited_at, u.first_name, u.last_name, u.avatar
                              FROM chat_group_messages m JOIN users u ON u.id = m.sender_id
                              WHERE m.group_id = ? ORDER BY m.id ASC LIMIT 500");
        $st->execute([$groupId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $maxId = $rows ? (int)$rows[count($rows) - 1]['id'] : 0;
        if ($maxId > 0) {
            $pdo->prepare("UPDATE chat_group_members SET last_read_message_id = GREATEST(last_read_message_id, ?) WHERE group_id = ? AND user_id = ?")
                ->execute([$maxId, $groupId, $userId]);
        }
        out(['ok' => true, 'data' => $rows]);
    } catch (Exception $e) { out(['ok' => true, 'data' => []]); }
}

if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $groupId = (int)($body['group_id'] ?? 0);
    $content = trim((string)($body['content'] ?? ''));
    if (!$groupId || !isMember($pdo, $groupId, $userId)) out(['error' => 'Группа не найдена или вы не участник'], 403);
    if ($content === '') out(['error' => 'Пустое сообщение'], 400);
    $content = mb_substr($content, 0, 5000);
    try {
        $st = $pdo->prepare("INSERT INTO chat_group_messages (group_id, sender_id, content, created_at) VALUES (?, ?, ?, NOW())");
        $st->execute([$groupId, $userId, $content]);
        $mid = $pdo->lastInsertId();
        $pdo->prepare("UPDATE chat_group_members SET last_read_message_id = GREATEST(last_read_message_id, ?) WHERE group_id = ? AND user_id = ?")
            ->execute([$mid, $groupId, $userId]);
        out(['ok' => true, 'id' => (int)$mid]);
    } catch (Exception $e) { out(['error' => 'Не удалось отправить'], 500); }
}

if ($action === 'edit-message' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $mid = (int)($body['message_id'] ?? 0);
    $content = trim((string)($body['content'] ?? ''));
    if (!$mid) out(['error' => 'message_id обязателен'], 400);
    if ($content === '') out(['error' => 'Текст не может быть пустым'], 400);
    $content = mb_substr($content, 0, 5000);
    try {
        $st = $pdo->prepare("SELECT sender_id, content FROM chat_group_messages WHERE id = ? LIMIT 1");
        $st->execute([$mid]);
        $msg = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { out(['error' => 'Ошибка'], 500); }
    if (!$msg) out(['error' => 'Сообщение не найдено'], 404);
    if ((string)$msg['sender_id'] !== (string)$userId) out(['error' => 'Редактировать можно только свои сообщения'], 403);
    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE chat_group_messages SET content = ?, edited_at = NOW() WHERE id = ?")->execute([$content, $mid]);
        $pdo->prepare("INSERT INTO group_message_edit_log (message_id, editor_id, old_content, new_content, edited_at) VALUES (?, ?, ?, ?, NOW())")
            ->execute([$mid, $userId, $msg['content'], $content]);
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        out(['error' => 'Не удалось сохранить'], 500);
    }
    out(['ok' => true]);
}

if ($action === 'members') {
    $groupId = (int)($_GET['group_id'] ?? 0);
    if (!$groupId || !isMember($pdo, $groupId, $userId)) out(['error' => 'Группа не найдена или вы не участник'], 403);
    try {
        $st = $pdo->prepare("SELECT m.user_id, m.role, m.joined_at, u.first_name, u.last_name, u.avatar, u.role AS site_role
                              FROM chat_group_members m JOIN users u ON u.id = m.user_id
                              WHERE m.group_id = ? ORDER BY (m.role='owner') DESC, u.first_name");
        $st->execute([$groupId]);
        out(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { out(['ok' => true, 'data' => []]); }
}

if ($action === 'add-members' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $groupId = (int)($body['group_id'] ?? 0);
    if (!$groupId || !isMember($pdo, $groupId, $userId)) out(['error' => 'Группа не найдена или вы не участник'], 403);
    $memberIds = array_values(array_unique(array_filter(array_map('strval', (array)($body['member_ids'] ?? [])), fn($v) => $v !== '')));
    if (!$memberIds) out(['error' => 'Не указаны участники'], 400);
    try {
        $ins = $pdo->prepare("INSERT IGNORE INTO chat_group_members (group_id, user_id, role, joined_at) VALUES (?, ?, 'member', NOW())");
        foreach (array_slice($memberIds, 0, 200) as $mid) $ins->execute([$groupId, $mid]);
        out(['ok' => true]);
    } catch (Exception $e) { out(['error' => 'Не удалось добавить участников'], 500); }
}

if ($action === 'leave' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $groupId = (int)($body['group_id'] ?? 0);
    if (!$groupId) out(['error' => 'group_id обязателен'], 400);
    try {
        $pdo->prepare("DELETE FROM chat_group_members WHERE group_id = ? AND user_id = ?")->execute([$groupId, $userId]);
        // Если участников не осталось — группа сама больше нигде не отображается, отдельно чистить не обязательно.
        out(['ok' => true]);
    } catch (Exception $e) { out(['error' => 'Не удалось выйти из группы'], 500); }
}

if ($action === 'rename' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $groupId = (int)($body['group_id'] ?? 0);
    $name = trim((string)($body['name'] ?? ''));
    if (!$groupId || $name === '') out(['error' => 'Некорректный запрос'], 400);
    if (isMember($pdo, $groupId, $userId) !== 'owner') out(['error' => 'Переименовать группу может только владелец'], 403);
    try {
        $pdo->prepare("UPDATE chat_groups SET name = ? WHERE id = ?")->execute([mb_substr($name, 0, 255), $groupId]);
        out(['ok' => true]);
    } catch (Exception $e) { out(['error' => 'Не удалось переименовать'], 500); }
}

out(['error' => 'Неизвестное действие'], 400);
