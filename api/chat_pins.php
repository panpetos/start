<?php
/**
 * chat_pins.php — закрепление сообщений в переписке и закрепление самих диалогов
 * в списке чатов (фидбэк админа: «закрепление сообщений нужно сделать в чате»,
 * «и закреплять диалоги»).
 *
 * Два разных вида закрепления, поэтому две таблицы:
 *  • chat_pinned_messages — закреплённое сообщение в конкретном разговоре. Видно обоим
 *    участникам (как в мессенджерах), поэтому ключом идёт сам разговор, а не пользователь.
 *  • chat_pinned_chats    — «поднять диалог наверх списка». Это личная настройка каждого,
 *    поэтому ключ — пользователь + чат.
 *
 * Ключ чата (chat_key) — тот же, что и у папок:
 *   <user_id> | channel:<psy_id> | group:<id> | __fav__
 * Ключ разговора для закреплённых сообщений — нормализованная пара участников
 * (или group:<id> / channel:<psy_id>), чтобы обе стороны видели одно закрепление.
 *
 * GET  ?action=state&chat_key=X            — закреплённое сообщение этого чата + закреплён ли сам чат
 * GET  ?action=pinned-chats                 — мои закреплённые чаты (список ключей)
 * POST ?action=pin-message   {chat_key, message_id, preview}
 * POST ?action=unpin-message {chat_key}
 * POST ?action=pin-chat      {chat_key}     — закрепить/снять (toggle), вернёт pinned:true|false
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

$pinsError = '';
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_pinned_messages (
        conversation_key VARCHAR(160) NOT NULL,
        message_id VARCHAR(64) NOT NULL,
        preview VARCHAR(300) NULL,
        pinned_by VARCHAR(64) NOT NULL,
        pinned_at DATETIME NOT NULL,
        PRIMARY KEY (conversation_key, message_id)
    ) DEFAULT CHARSET=utf8mb4");
    // Миграция уже созданной таблицы: раньше первичным ключом был ОДИН conversation_key,
    // из-за чего закрепление было ровно одно на разговор и новое затирало прежнее.
    // Теперь ключ составной — закреплений может быть много, как в Telegram.
    $pkCols = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_pinned_messages'
          AND INDEX_NAME = 'PRIMARY'")->fetchColumn();
    if ($pkCols === 1) {
        $pdo->exec("ALTER TABLE chat_pinned_messages
                    DROP PRIMARY KEY, ADD PRIMARY KEY (conversation_key, message_id)");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_pinned_chats (
        user_id VARCHAR(64) NOT NULL,
        chat_key VARCHAR(120) NOT NULL,
        pinned_at DATETIME NOT NULL,
        PRIMARY KEY (user_id, chat_key)
    ) DEFAULT CHARSET=utf8mb4");
    // Наши таблицы создаются в utf8mb4_0900_ai_ci, а users/psychologists — в utf8mb4_unicode_ci;
    // без выравнивания любой JOIN падает с ошибкой 1267 (см. schema_util.php).
    psy_align_collation($pdo, ['chat_pinned_messages', 'chat_pinned_chats']);
} catch (Exception $e) {
    $pinsError = $e->getMessage();
}

$action = $_GET['action'] ?? '';
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

/**
 * Ключ разговора: для группы/канала/избранного — сам chat_key, для личной переписки —
 * пара id, отсортированная по алфавиту, чтобы у обоих участников получался один ключ.
 */
function conversationKey($chatKey, $userId) {
    $chatKey = (string)$chatKey;
    if ($chatKey === '' ) return '';
    if (strpos($chatKey, 'group:') === 0 || strpos($chatKey, 'channel:') === 0 || $chatKey === '__fav__') {
        return $chatKey;
    }
    if (strpos($chatKey, 'support:') === 0) return $chatKey;
    $pair = [(string)$userId, $chatKey];
    sort($pair, SORT_STRING);
    return 'dm:' . $pair[0] . ':' . $pair[1];
}

function cutText($v, $n) {
    $v = trim(preg_replace('/\s+/u', ' ', (string)$v));
    if ($v === '') return null;
    return function_exists('mb_substr') ? mb_substr($v, 0, $n, 'UTF-8') : substr($v, 0, $n);
}

try {
    if ($action === 'state') {
        if ($pinsError) out(['ok' => false, 'error' => 'Закрепления недоступны: ' . $pinsError], 500);
        $chatKey = (string)($_GET['chat_key'] ?? '');
        if ($chatKey === '') out(['ok' => false, 'error' => 'Не передан чат'], 400);
        $ck = conversationKey($chatKey, $userId);
        // По возрастанию времени: в плашке листаем закреплённые так же, как они идут
        // в переписке — от давнего к свежему.
        $st = $pdo->prepare("SELECT message_id, preview, pinned_by, pinned_at FROM chat_pinned_messages
                             WHERE conversation_key = ? ORDER BY pinned_at ASC, message_id ASC");
        $st->execute([$ck]);
        $list = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $c = $pdo->prepare("SELECT 1 FROM chat_pinned_chats WHERE user_id = ? AND chat_key = ?");
        $c->execute([$userId, $chatKey]);
        out(['ok' => true,
             'pinned_messages' => $list,
             // старое поле оставляем: им пользуются вкладки, открытые до обновления
             'pinned_message' => $list ? $list[count($list) - 1] : null,
             'chat_pinned' => (bool)$c->fetchColumn()]);
    }

    if ($action === 'pinned-chats') {
        if ($pinsError) out(['ok' => true, 'data' => []]);   // закрепления — удобство, список чатов работает и без них
        $st = $pdo->prepare("SELECT chat_key FROM chat_pinned_chats WHERE user_id = ? ORDER BY pinned_at DESC");
        $st->execute([$userId]);
        out(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_COLUMN)]);
    }

    if ($action === 'pin-message' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($pinsError) out(['ok' => false, 'error' => 'Закрепления недоступны: ' . $pinsError], 500);
        $chatKey = (string)($body['chat_key'] ?? '');
        $messageId = trim((string)($body['message_id'] ?? ''));
        if ($chatKey === '' || $messageId === '') out(['ok' => false, 'error' => 'Некорректный запрос'], 400);
        $ck = conversationKey($chatKey, $userId);
        $preview = cutText($body['preview'] ?? '', 300);
        // Закреплений может быть много: новое ДОБАВЛЯЕТСЯ, а не затирает прежнее.
        // Повторное закрепление того же сообщения только обновляет подпись и время.
        $st = $pdo->prepare("SELECT 1 FROM chat_pinned_messages WHERE conversation_key = ? AND message_id = ?");
        $st->execute([$ck, $messageId]);
        if (!$st->fetchColumn()) {
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM chat_pinned_messages WHERE conversation_key = ?");
            $cnt->execute([$ck]);
            if ((int)$cnt->fetchColumn() >= 50) {
                out(['ok' => false, 'error' => 'Больше 50 закреплённых сообщений в одном чате не поддерживается'], 400);
            }
        }
        $pdo->prepare("INSERT INTO chat_pinned_messages (conversation_key, message_id, preview, pinned_by, pinned_at)
                       VALUES (?, ?, ?, ?, NOW())
                       ON DUPLICATE KEY UPDATE preview = VALUES(preview), pinned_by = VALUES(pinned_by), pinned_at = NOW()")
            ->execute([$ck, $messageId, $preview, $userId]);
        $all = $pdo->prepare("SELECT message_id, preview, pinned_by, pinned_at FROM chat_pinned_messages
                              WHERE conversation_key = ? ORDER BY pinned_at ASC, message_id ASC");
        $all->execute([$ck]);
        out(['ok' => true, 'message_id' => $messageId, 'preview' => $preview,
             'pinned_messages' => $all->fetchAll(PDO::FETCH_ASSOC) ?: []]);
    }

    if ($action === 'unpin-message' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $chatKey = (string)($body['chat_key'] ?? '');
        if ($chatKey === '') out(['ok' => false, 'error' => 'Не передан чат'], 400);
        $ck = conversationKey($chatKey, $userId);
        // С message_id — снимаем одно закрепление, без него — все сразу («Открепить все»).
        $messageId = trim((string)($body['message_id'] ?? ''));
        if ($messageId !== '') {
            $pdo->prepare("DELETE FROM chat_pinned_messages WHERE conversation_key = ? AND message_id = ?")
                ->execute([$ck, $messageId]);
        } else {
            $pdo->prepare("DELETE FROM chat_pinned_messages WHERE conversation_key = ?")->execute([$ck]);
        }
        $all = $pdo->prepare("SELECT message_id, preview, pinned_by, pinned_at FROM chat_pinned_messages
                              WHERE conversation_key = ? ORDER BY pinned_at ASC, message_id ASC");
        $all->execute([$ck]);
        out(['ok' => true, 'pinned_messages' => $all->fetchAll(PDO::FETCH_ASSOC) ?: []]);
    }

    if ($action === 'pin-chat' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($pinsError) out(['ok' => false, 'error' => 'Закрепления недоступны: ' . $pinsError], 500);
        $chatKey = trim((string)($body['chat_key'] ?? ''));
        if ($chatKey === '' || strlen($chatKey) > 120) out(['ok' => false, 'error' => 'Некорректный чат'], 400);
        $st = $pdo->prepare("SELECT 1 FROM chat_pinned_chats WHERE user_id = ? AND chat_key = ?");
        $st->execute([$userId, $chatKey]);
        if ($st->fetchColumn()) {
            $pdo->prepare("DELETE FROM chat_pinned_chats WHERE user_id = ? AND chat_key = ?")->execute([$userId, $chatKey]);
            out(['ok' => true, 'pinned' => false]);
        }
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM chat_pinned_chats WHERE user_id = ?");
        $cnt->execute([$userId]);
        if ((int)$cnt->fetchColumn() >= 30) out(['ok' => false, 'error' => 'Больше 30 закреплённых чатов не поддерживается'], 400);
        $pdo->prepare("INSERT INTO chat_pinned_chats (user_id, chat_key, pinned_at) VALUES (?, ?, NOW())")
            ->execute([$userId, $chatKey]);
        out(['ok' => true, 'pinned' => true]);
    }

    out(['ok' => false, 'error' => 'Неизвестное действие'], 400);
} catch (Exception $e) {
    out(['ok' => false, 'error' => $e->getMessage()], 500);
}
