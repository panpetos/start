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
 * POST ?action=update-info {group_id, name?, description?, avatar_url?} — название/описание/аватарка (владелец)
 * POST ?action=delete-message {message_id}             — удалить своё сообщение (владелец — любое)
 * POST ?action=remove-member  {group_id, user_id}      — исключить участника (только владелец)
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

// Схему проверяем не чаще раза в час (см. psy_schema_once): раньше пять ALTER'ов
// и четыре CREATE TABLE выполнялись на каждый опрос группового чата, а он идёт
// раз в пять секунд с каждой открытой вкладки.
function ensureGroupTables(PDO $pdo) {
    psy_schema_once('group_chat_schema_v1', 3600, function () use ($pdo) { ensureGroupTablesNow($pdo); });
}

function ensureGroupTablesNow(PDO $pdo) {
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
    // Аватарка и описание группы (фидбэк админа: «нужно моч ставить аватарку на группу
    // или канал с описанием и там и там»). Добавляем через ALTER, чтобы не ломать
    // существующие группы; повторный запуск молча ничего не делает.
    // Время прочтения участником: last_read_message_id говорит «докуда прочитал»,
    // но не «когда» — а для окна «кто прочитал» нужно именно время.
    try { $pdo->exec("ALTER TABLE chat_group_members ADD COLUMN last_read_at DATETIME NULL"); } catch (Exception $e) {}
    // Код пригласительной ссылки. Отдельная колонка, а не id группы: id
    // предсказуем, по нему в группу зашёл бы любой перебором.
    try { $pdo->exec("ALTER TABLE chat_groups ADD COLUMN invite_code VARCHAR(32) NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE chat_groups ADD UNIQUE KEY uniq_invite (invite_code)"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE chat_groups ADD COLUMN avatar_url VARCHAR(500) NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE chat_groups ADD COLUMN description VARCHAR(500) NULL"); } catch (Exception $e) {}
    // Вложения в группах: без этих колонок в группу нельзя было отправить ни фото, ни файл,
    // ни голосовое, ни кружок — запись просто не сохранялась (фидбэк админа).
    try { $pdo->exec("ALTER TABLE chat_group_messages ADD COLUMN attachment_url VARCHAR(500) NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE chat_group_messages ADD COLUMN attachment_type VARCHAR(60) NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE chat_group_messages ADD COLUMN attachment_name VARCHAR(255) NULL"); } catch (Exception $e) {}
    // См. schema_util.php: без выравнивания сортировки JOIN на users в списке сообщений и
    // участников падает с ошибкой 1267, и группа выглядит пустой/неработающей.
    psy_align_collation($pdo, ['chat_groups', 'chat_group_members', 'chat_group_messages', 'group_message_edit_log']);
}
// Ошибку создания таблиц НЕ глушим: если таблиц нет, все действия ниже всё равно упадут,
// и раньше это выглядело как «сообщения просто не отправляются» без единого намёка на причину.
$groupTablesError = null;
try { ensureGroupTables($pdo); } catch (Exception $e) { $groupTablesError = $e->getMessage(); }

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
    if ($groupTablesError) out(['error' => 'Групповые чаты недоступны: не удалось подготовить таблицы в БД (' . $groupTablesError . ')'], 500);
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
        out(['error' => 'Не удалось создать группу: ' . $e->getMessage()], 500);
    }
    out(['ok' => true, 'group_id' => (int)$groupId]);
}

if ($action === 'list') {
    try {
        $st = $pdo->prepare("
            SELECT g.id, g.name, g.avatar_url, g.description, g.created_by, m.role, m.last_read_message_id,
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
    } catch (Exception $e) { out(['error' => 'Не удалось загрузить группы: ' . $e->getMessage()], 500); }
}

if ($action === 'messages') {
    $groupId = (int)($_GET['group_id'] ?? 0);
    if (!$groupId) out(['error' => 'Не передан номер группы'], 400);
    if (!isMember($pdo, $groupId, $userId)) out(['error' => 'Вы не участник этой группы (или группа удалена)'], 403);
    try {
        $st = $pdo->prepare("SELECT m.id, m.sender_id, m.content, m.created_at, m.edited_at,
                                     m.attachment_url, m.attachment_type, m.attachment_name,
                                     u.first_name, u.last_name, u.avatar
                              FROM chat_group_messages m LEFT JOIN users u ON u.id = m.sender_id
                              WHERE m.group_id = ? ORDER BY m.id ASC LIMIT 500");
        $st->execute([$groupId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $maxId = $rows ? (int)$rows[count($rows) - 1]['id'] : 0;
        if ($maxId > 0) {
            $pdo->prepare("UPDATE chat_group_members SET last_read_message_id = GREATEST(COALESCE(last_read_message_id, 0), ?), last_read_at = NOW() WHERE group_id = ? AND user_id = ?")
                ->execute([$maxId, $groupId, $userId]);
        }
        out(['ok' => true, 'data' => $rows]);
    } catch (Exception $e) { out(['ok' => true, 'data' => []]); }
}

if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($groupTablesError) out(['error' => 'Групповые чаты недоступны: не удалось подготовить таблицы в БД (' . $groupTablesError . ')'], 500);
    $groupId = (int)($body['group_id'] ?? 0);
    $content = trim((string)($body['content'] ?? ''));
    if (!$groupId) out(['error' => 'Не передан номер группы'], 400);
    if (!isMember($pdo, $groupId, $userId)) out(['error' => 'Вы не участник этой группы (или группа удалена)'], 403);
    // Вложение (фото, файл, голосовое, кружок). Ссылку принимаем только свою — либо
    // относительный путь на сайте, либо https, иначе в группу можно было бы подсунуть что угодно.
    $attUrl  = trim((string)($body['attachment_url'] ?? ''));
    if ($attUrl !== '' && !preg_match('~^(/[^\s]*|https://[^\s]+)$~', $attUrl)) {
        out(['error' => 'Некорректная ссылка на вложение'], 400);
    }
    $attType = mb_substr(trim((string)($body['attachment_type'] ?? '')), 0, 60);
    $attName = mb_substr(trim((string)($body['attachment_name'] ?? '')), 0, 255);
    if ($content === '' && $attUrl === '') out(['error' => 'Пустое сообщение'], 400);
    $content = mb_substr($content, 0, 5000);
    try {
        $st = $pdo->prepare("INSERT INTO chat_group_messages (group_id, sender_id, content, attachment_url, attachment_type, attachment_name, created_at)
                             VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $st->execute([$groupId, $userId, $content, $attUrl !== '' ? $attUrl : null,
                      $attType !== '' ? $attType : null, $attName !== '' ? $attName : null]);
        $mid = (int)$pdo->lastInsertId();
        // COALESCE: если last_read_message_id почему-то NULL, GREATEST(NULL, x) вернёт NULL,
        // а колонка NOT NULL — в strict-режиме MySQL это ошибка и всё сообщение бы «не отправилось».
        $pdo->prepare("UPDATE chat_group_members SET last_read_message_id = GREATEST(COALESCE(last_read_message_id, 0), ?), last_read_at = NOW() WHERE group_id = ? AND user_id = ?")
            ->execute([$mid, $groupId, $userId]);
        // Уведомления участникам — сразу, а не с рассылкой раз в несколько минут.
        // Отвечаем клиенту первым делом: обращений к службам доставки столько же,
        // сколько участников, и ждать их «отправить» не должно.
        http_response_code(200);
        echo json_encode(['ok' => true, 'id' => $mid], JSON_UNESCAPED_UNICODE);
        // push.php при подключении объявляет свои $pdo и $userId на верхнем уровне,
        // поэтому автора запоминаем заранее: иначе рассылка зависела бы от того,
        // что чужой файл положит в переменную с тем же именем.
        $senderId = $userId;
        $senderPdo = $pdo;
        try {
            $pf = __DIR__ . '/push.php';
            if (is_file($pf)) {
                $GLOBALS['__PUSH_LIB_ONLY'] = true;   // у push.php свой роутер — просим его молчать
                include_once $pf;
            }
            if (function_exists('push_flush_response')) push_flush_response();
            if (function_exists('push_notify_group')) push_notify_group($senderPdo, $groupId, $senderId);
        } catch (Exception $e) { /* сообщение уже отправлено — молчим */ }
        exit;
    } catch (Exception $e) { out(['error' => 'Не удалось отправить: ' . $e->getMessage()], 500); }
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
        out(['error' => 'Не удалось сохранить: ' . $e->getMessage()], 500);
    }
    out(['ok' => true]);
}

if ($action === 'delete-message' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $mid = (int)($body['message_id'] ?? 0);
    if (!$mid) out(['error' => 'message_id обязателен'], 400);
    try {
        $st = $pdo->prepare("SELECT sender_id, group_id FROM chat_group_messages WHERE id = ? LIMIT 1");
        $st->execute([$mid]);
        $msg = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { out(['error' => 'Ошибка: ' . $e->getMessage()], 500); }
    if (!$msg) out(['error' => 'Сообщение не найдено'], 404);
    // Своё сообщение удаляет автор; любое — владелец группы (модерация, как админ канала в Telegram).
    $isOwner = (isMember($pdo, (int)$msg['group_id'], $userId) === 'owner');
    if ((string)$msg['sender_id'] !== (string)$userId && !$isOwner) out(['error' => 'Удалить можно только своё сообщение'], 403);
    try {
        $pdo->prepare("DELETE FROM chat_group_messages WHERE id = ?")->execute([$mid]);
        out(['ok' => true]);
    } catch (Exception $e) { out(['error' => 'Не удалось удалить: ' . $e->getMessage()], 500); }
}

if ($action === 'remove-member' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $groupId = (int)($body['group_id'] ?? 0);
    $target = trim((string)($body['user_id'] ?? ''));
    if (!$groupId || $target === '') out(['error' => 'Некорректный запрос'], 400);
    if (isMember($pdo, $groupId, $userId) !== 'owner') out(['error' => 'Исключать участников может только владелец группы'], 403);
    if ($target === (string)$userId) out(['error' => 'Владелец не может исключить себя — используйте «Выйти из группы»'], 400);
    try {
        $pdo->prepare("DELETE FROM chat_group_members WHERE group_id = ? AND user_id = ? AND role <> 'owner'")->execute([$groupId, $target]);
        out(['ok' => true]);
    } catch (Exception $e) { out(['error' => 'Не удалось исключить: ' . $e->getMessage()], 500); }
}

/**
 * Кто из участников прочитал сообщение и когда.
 *
 * Считаем по last_read_message_id: прочитавшим считается тот, чья отметка дошла
 * до этого сообщения или дальше. Себя из списка убираем — свои сообщения человек
 * читал по определению.
 *
 * GET ?action=readers&group_id=X&message_id=Y
 *   → { ok, readers: [{user_id, name, at}], total }
 */
if ($action === 'readers') {
    $gid = (int)($_GET['group_id'] ?? 0);
    $mid = (int)($_GET['message_id'] ?? 0);
    if (!$gid || !$mid) { http_response_code(400); echo json_encode(['error' => 'Нужны group_id и message_id']); exit; }
    if (!isMember($pdo, $gid, $userId)) { http_response_code(403); echo json_encode(['error' => 'Вы не участник группы']); exit; }
    try {
        $st = $pdo->prepare("SELECT m.user_id, m.last_read_at,
                                    u.first_name, u.last_name
                               FROM chat_group_members m
                               LEFT JOIN users u ON u.id = m.user_id
                              WHERE m.group_id = ? AND m.user_id <> ?
                                AND COALESCE(m.last_read_message_id, 0) >= ?
                              ORDER BY m.last_read_at DESC");
        $st->execute([$gid, $userId, $mid]);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            $rows[] = ['user_id' => (string)$r['user_id'],
                       'name' => $name !== '' ? $name : 'Участник',
                       'at' => $r['last_read_at'] ?: null];
        }
        // Всего участников кроме меня — чтобы показать «3 из 5»
        $tot = $pdo->prepare("SELECT COUNT(*) FROM chat_group_members WHERE group_id = ? AND user_id <> ?");
        $tot->execute([$gid, $userId]);
        echo json_encode(['ok' => true, 'readers' => $rows, 'total' => (int)$tot->fetchColumn()], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        http_response_code(500); echo json_encode(['error' => 'Не удалось получить список: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/** Код приглашения: без похожих символов, чтобы диктовать голосом и не путать 0/O. */
function gcMakeInviteCode() {
    $abc = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < 10; $i++) $out .= $abc[random_int(0, strlen($abc) - 1)];
    return $out;
}

/**
 * Пригласительная ссылка группы: получить, создать или отозвать.
 *
 * Раньше добавить человека можно было только вручную из списка контактов —
 * позвать того, кого нет в контактах, было нечем.
 *
 * GET  ?action=invite-link&group_id=X            — текущая ссылка (владелец)
 * POST ?action=invite-link {group_id, reset:1}   — выпустить заново (старая перестаёт работать)
 */
if ($action === 'invite-link') {
    $groupId = (int)($_GET['group_id'] ?? ($body['group_id'] ?? 0));
    if (!$groupId) out(['error' => 'Не передан номер группы'], 400);
    if (isMember($pdo, $groupId, $userId) !== 'owner') out(['error' => 'Ссылку выдаёт только владелец группы'], 403);
    try {
        $st = $pdo->prepare("SELECT invite_code FROM chat_groups WHERE id = ? LIMIT 1");
        $st->execute([$groupId]);
        $code = (string)($st->fetchColumn() ?: '');
        $reset = ($_SERVER['REQUEST_METHOD'] === 'POST') && !empty($body['reset']);
        if ($code === '' || $reset) {
            // Пробуем несколько раз: код случайный, совпадение маловероятно, но
            // уникальный индекс всё равно должен быть соблюдён.
            for ($i = 0; $i < 5; $i++) {
                $try = gcMakeInviteCode();
                try {
                    $pdo->prepare("UPDATE chat_groups SET invite_code = ? WHERE id = ?")->execute([$try, $groupId]);
                    $code = $try; break;
                } catch (Exception $e) { /* столкновение — берём другой */ }
            }
            if ($code === '') out(['error' => 'Не удалось выпустить ссылку'], 500);
        }
        out(['ok' => true, 'code' => $code]);
    } catch (Exception $e) { out(['error' => 'Не удалось получить ссылку: ' . $e->getMessage()], 500); }
}

/**
 * Войти в группу по коду приглашения.
 * POST ?action=join {code}
 */
if ($action === 'join' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = strtoupper(trim((string)($body['code'] ?? '')));
    if ($code === '') out(['error' => 'Не передан код приглашения'], 400);
    try {
        $st = $pdo->prepare("SELECT id, name FROM chat_groups WHERE invite_code = ? LIMIT 1");
        $st->execute([$code]);
        $g = $st->fetch(PDO::FETCH_ASSOC);
        if (!$g) out(['error' => 'Ссылка недействительна — возможно, её отозвали'], 404);
        $gid = (int)$g['id'];
        if (isMember($pdo, $gid, $userId)) out(['ok' => true, 'group_id' => $gid, 'name' => $g['name'], 'already' => true]);
        $pdo->prepare("INSERT INTO chat_group_members (group_id, user_id, role, last_read_message_id, joined_at)
                       VALUES (?, ?, 'member', 0, NOW())")->execute([$gid, $userId]);
        out(['ok' => true, 'group_id' => $gid, 'name' => $g['name'], 'already' => false]);
    } catch (Exception $e) { out(['error' => 'Не удалось вступить: ' . $e->getMessage()], 500); }
}

if ($action === 'members') {
    $groupId = (int)($_GET['group_id'] ?? 0);
    if (!$groupId || !isMember($pdo, $groupId, $userId)) out(['error' => 'Группа не найдена или вы не участник'], 403);
    try {
        $st = $pdo->prepare("SELECT m.user_id, m.role, m.joined_at, u.first_name, u.last_name, u.avatar, u.role AS site_role
                              FROM chat_group_members m LEFT JOIN users u ON u.id = m.user_id
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
    } catch (Exception $e) { out(['error' => 'Не удалось добавить участников: ' . $e->getMessage()], 500); }
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

/**
 * Удалить группу целиком — только владелец. Выход (leave) убирает из группы одного
 * человека, а группа продолжает жить у остальных; здесь исчезает и сама группа
 * со всей перепиской (по просьбе админа: «добавь возможность удалить свою группу»).
 */
if ($action === 'delete-group' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($groupTablesError) out(['error' => 'Групповые чаты недоступны: ' . $groupTablesError], 500);
    $groupId = (int)($body['group_id'] ?? 0);
    if (!$groupId) out(['error' => 'group_id обязателен'], 400);
    try {
        $st = $pdo->prepare("SELECT created_by FROM chat_groups WHERE id = ? LIMIT 1");
        $st->execute([$groupId]);
        $owner = $st->fetchColumn();
        if (!$owner) out(['error' => 'Группа не найдена'], 404);
        if ((string)$owner !== (string)$userId) out(['error' => 'Удалить группу может только её владелец'], 403);
        $pdo->prepare("DELETE FROM chat_group_messages WHERE group_id = ?")->execute([$groupId]);
        $pdo->prepare("DELETE FROM chat_group_members WHERE group_id = ?")->execute([$groupId]);
        $pdo->prepare("DELETE FROM chat_groups WHERE id = ?")->execute([$groupId]);
        out(['ok' => true]);
    } catch (Exception $e) { out(['error' => 'Не удалось удалить группу: ' . $e->getMessage()], 500); }
}

/**
 * Название, описание и аватарка группы — одним запросом. Меняет только владелец.
 * Передавать можно любое подмножество полей; пустая строка в description/avatar_url
 * означает «убрать», отсутствие ключа — «не трогать».
 */
if ($action === 'update-info' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $groupId = (int)($body['group_id'] ?? 0);
    if (!$groupId) out(['error' => 'Не передан номер группы'], 400);
    if (isMember($pdo, $groupId, $userId) !== 'owner') out(['error' => 'Менять группу может только владелец'], 403);
    $sets = [];
    $vals = [];
    if (array_key_exists('name', $body)) {
        $name = trim((string)$body['name']);
        if ($name === '') out(['error' => 'Название не может быть пустым'], 400);
        $sets[] = 'name = ?';
        $vals[] = mb_substr($name, 0, 255);
    }
    if (array_key_exists('description', $body)) {
        $descr = trim((string)$body['description']);
        $sets[] = 'description = ?';
        $vals[] = $descr === '' ? null : mb_substr($descr, 0, 500);
    }
    if (array_key_exists('avatar_url', $body)) {
        $av = trim((string)$body['avatar_url']);
        // принимаем только свои пути и https — чтобы в аватарку нельзя было подсунуть чужой хост
        if ($av !== '' && !preg_match('~^(/[A-Za-z0-9._/-]+|https://[A-Za-z0-9.-]+/[A-Za-z0-9._/%-]*)$~', $av)) {
            out(['error' => 'Некорректная ссылка на аватарку'], 400);
        }
        $sets[] = 'avatar_url = ?';
        $vals[] = $av === '' ? null : mb_substr($av, 0, 500);
    }
    if (!$sets) out(['error' => 'Нечего менять'], 400);
    try {
        $vals[] = $groupId;
        $pdo->prepare("UPDATE chat_groups SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
        out(['ok' => true]);
    } catch (Exception $e) { out(['error' => 'Не удалось сохранить: ' . $e->getMessage()], 500); }
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
