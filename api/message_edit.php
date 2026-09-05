<?php
/**
 * message_edit.php — редактирование своих сообщений в чате + журнал правок для админа (задачи #29, #42).
 *
 * api/messages.php — server-only файл (не в этом репозитории, см. CLAUDE.md), поэтому здесь отдельный
 * эндпоинт: он не переопределяет messages.php, а работает с той же таблицей messages напрямую через ту
 * же function_exists-резолвер связку config.php/db.php, как и остальные API-файлы этого репо.
 *
 * POST ?action=edit          {message_id, content}   — автор сообщения меняет текст (сохраняется в лог)
 * POST ?action=delete        {message_id}             — автор удаляет своё сообщение (текст остаётся в логе)
 * GET  ?action=edited-map    &ids=1,2,3               — для каких из этих id есть правки (участнику диалога)
 * GET  ?action=edited-map    &with=<id собеседника>     — то же сразу за всю переписку, без списка id
 * GET  ?action=recent-edits  &limit=200                — журнал правок для админа (кто/когда/с чего на что)
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

$isAdmin = false;
try {
    $st = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    $st->execute([$userId]);
    $isAdmin = ((string)$st->fetchColumn() === 'admin');
} catch (Exception $e) {}

function ensureEditLogTable(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS message_edit_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        message_id VARCHAR(64) NOT NULL,
        editor_id VARCHAR(64) NOT NULL,
        old_content TEXT NULL,
        new_content TEXT NULL,
        edited_at DATETIME NOT NULL,
        INDEX idx_message (message_id),
        INDEX idx_edited_at (edited_at)
    ) DEFAULT CHARSET=utf8mb4");
}
// Подготовку схемы делаем не чаще раза в час: этот файл дёргается при каждом открытии
// переписки и на каждом опросе, а заведомо падающий ALTER на главной таблице сообщений
// всё равно заставляет MySQL брать блокировку метаданных.
psy_schema_once('message_edit_schema_v1', 3600, function () use ($pdo) {
    try { ensureEditLogTable($pdo); psy_align_collation($pdo, ['message_edit_log']); } catch (Exception $e) {}
    // Метка "сообщение отредактировано" в самой таблице messages — на будущее (messages.php её сегодня не
    // отдаёт в ответах, т.к. этот файл нам недоступен, поэтому UI полагается на action=edited-map ниже).
    try { $pdo->exec("ALTER TABLE messages ADD COLUMN edited_at DATETIME NULL"); } catch (Exception $e) {}
});

$action = $_GET['action'] ?? '';
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $messageId = trim((string)($body['message_id'] ?? ''));
    $newContent = trim((string)($body['content'] ?? ''));
    if ($messageId === '') out(['error' => 'message_id обязателен'], 400);
    if ($newContent === '') out(['error' => 'Текст не может быть пустым'], 400);
    $newContent = mb_substr($newContent, 0, 5000);

    try {
        $st = $pdo->prepare("SELECT sender_id, content FROM messages WHERE id = ? LIMIT 1");
        $st->execute([$messageId]);
        $msg = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { out(['error' => 'Не удалось найти сообщение'], 500); }
    if (!$msg) out(['error' => 'Сообщение не найдено'], 404);
    // Админ, переписывающийся от имени поддержки (as_support), шлёт сообщения с sender_id='support' —
    // на клиенте (chat.html, isAdminMode) это его собственное сообщение, поэтому и тут считаем своим.
    $isOwn = (string)$msg['sender_id'] === (string)$userId || ($isAdmin && (string)$msg['sender_id'] === 'support');
    if (!$isOwn) out(['error' => 'Редактировать можно только свои сообщения'], 403);

    $oldContent = (string)$msg['content'];
    if ($oldContent === $newContent) out(['ok' => true, 'unchanged' => true]);

    try {
        $pdo->beginTransaction();
        $upd = $pdo->prepare("UPDATE messages SET content = ?, edited_at = NOW() WHERE id = ?");
        $upd->execute([$newContent, $messageId]);
        $log = $pdo->prepare("INSERT INTO message_edit_log (message_id, editor_id, old_content, new_content, edited_at) VALUES (?, ?, ?, ?, NOW())");
        $log->execute([$messageId, $userId, $oldContent, $newContent]);
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        out(['error' => 'Не удалось сохранить изменения'], 500);
    }
    out(['ok' => true]);
}

/**
 * Удаление своего сообщения в личной переписке. В группах и «Избранном» удаление уже было,
 * а в 1:1 его не существовало вовсе — сообщение можно было только отредактировать.
 *
 * Удаляем строку целиком: messages.php — server-only файл, отфильтровать «удалённые» в его
 * выборке нельзя, поэтому мягкое удаление привело бы к тому, что сообщение снова появлялось
 * бы после обновления. Текст перед удалением уходит в message_edit_log (new_content = NULL),
 * так что для админского журнала ничего не теряется.
 */
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $messageId = trim((string)($body['message_id'] ?? ''));
    if ($messageId === '') out(['error' => 'message_id обязателен'], 400);

    try {
        $st = $pdo->prepare("SELECT sender_id, content, attachment_url FROM messages WHERE id = ? LIMIT 1");
        $st->execute([$messageId]);
        $msg = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // в старых схемах может не быть attachment_url — пробуем без него
        try {
            $st = $pdo->prepare("SELECT sender_id, content FROM messages WHERE id = ? LIMIT 1");
            $st->execute([$messageId]);
            $msg = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e2) { out(['error' => 'Не удалось найти сообщение: ' . $e2->getMessage()], 500); }
    }
    if (!$msg) out(['error' => 'Сообщение не найдено'], 404);

    $isOwn = (string)$msg['sender_id'] === (string)$userId || ($isAdmin && (string)$msg['sender_id'] === 'support');
    // Админ может убрать любое сообщение (модерация), остальные — только свои.
    if (!$isOwn && !$isAdmin) out(['error' => 'Удалить можно только свои сообщения'], 403);

    $kept = (string)$msg['content'];
    if (!empty($msg['attachment_url'])) $kept .= ' [вложение: ' . $msg['attachment_url'] . ']';

    try {
        $pdo->beginTransaction();
        $log = $pdo->prepare("INSERT INTO message_edit_log (message_id, editor_id, old_content, new_content, edited_at) VALUES (?, ?, ?, NULL, NOW())");
        $log->execute([$messageId, $userId, $kept]);
        $pdo->prepare("DELETE FROM messages WHERE id = ?")->execute([$messageId]);
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        out(['error' => 'Не удалось удалить: ' . $e->getMessage()], 500);
    }
    out(['ok' => true]);
}

if ($action === 'edited-map') {
    // Режим «по собеседнику»: отдаём правки сразу за всю переписку.
    //
    // Зачем: раньше карту правок можно было спросить только по списку id, а список
    // id получается лишь ПОСЛЕ загрузки сообщений — то есть ещё один запрос строго
    // после предыдущего. При открытии чата такие цепочки и складывались в те самые
    // секунды ожидания. С этим режимом запрос уходит одновременно с сообщениями.
    $with = trim((string)($_GET['with'] ?? ''));
    if ($with !== '') {
        $result = [];
        try {
            $st = $pdo->prepare("SELECT l.message_id, MAX(l.edited_at) AS last_edited
                                   FROM message_edit_log l
                                   JOIN messages m ON m.id = l.message_id
                                  WHERE (m.sender_id = ? AND m.receiver_id = ?)
                                     OR (m.sender_id = ? AND m.receiver_id = ?)
                                  GROUP BY l.message_id");
            $st->execute([$userId, $with, $with, $userId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $result[$r['message_id']] = $r['last_edited'];
        } catch (Exception $e) {}
        out(['ok' => true, 'data' => $result]);
    }

    $idsParam = trim((string)($_GET['ids'] ?? ''));
    if ($idsParam === '') out(['ok' => true, 'data' => []]);
    $ids = array_values(array_unique(array_filter(array_map('trim', explode(',', $idsParam)), fn($v) => $v !== '')));
    $ids = array_slice($ids, 0, 200);
    if (!$ids) out(['ok' => true, 'data' => []]);

    // Отдаём статус "отредактировано" только по сообщениям, где текущий пользователь — участник диалога
    // (отправитель или получатель), либо он админ — чтобы не давать зондировать чужие message_id.
    $result = [];
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT id, sender_id, receiver_id FROM messages WHERE id IN ($in)");
        $st->execute($ids);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $allowedIds = [];
        foreach ($rows as $r) {
            if ($isAdmin || (string)$r['sender_id'] === (string)$userId || (string)($r['receiver_id'] ?? '') === (string)$userId) {
                $allowedIds[] = $r['id'];
            }
        }
        if ($allowedIds) {
            $in2 = implode(',', array_fill(0, count($allowedIds), '?'));
            $st2 = $pdo->prepare("SELECT message_id, MAX(edited_at) AS last_edited FROM message_edit_log WHERE message_id IN ($in2) GROUP BY message_id");
            $st2->execute($allowedIds);
            foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) $result[$r['message_id']] = $r['last_edited'];
        }
    } catch (Exception $e) {}
    out(['ok' => true, 'data' => $result]);
}

if ($action === 'recent-edits') {
    if (!$isAdmin) out(['error' => 'Доступно только администратору'], 403);
    $limit = max(1, min(500, (int)($_GET['limit'] ?? 200)));
    try {
        $st = $pdo->prepare("SELECT l.id, l.message_id, l.editor_id, l.old_content, l.new_content, l.edited_at,
                    u.first_name AS editor_first_name, u.last_name AS editor_last_name, u.role AS editor_role
                FROM message_edit_log l
                LEFT JOIN users u ON u.id = l.editor_id
                ORDER BY l.edited_at DESC LIMIT $limit");
        $st->execute();
        out(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { out(['ok' => true, 'data' => []]); }
}

out(['error' => 'Неизвестное действие'], 400);
