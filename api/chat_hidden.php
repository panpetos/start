<?php
/**
 * chat_hidden.php — «удалить диалог» из списка чатов (фидбэк админа: «и добавить удаление диалога»).
 *
 * Настоящее удаление переписки невозможно и не нужно: сообщения принадлежат обоим участникам,
 * и стереть их у собеседника — не то, чего ждут от кнопки «удалить». Поэтому здесь то же,
 * что делает Telegram при «удалить чат у себя»: диалог скрывается лично у этого пользователя.
 *
 * Храним не флаг, а МОМЕНТ скрытия. Пока в переписке нет ничего нового, диалога в списке нет;
 * приходит новое сообщение — диалог возвращается сам, ничего не потеряется. Свои сообщения,
 * если их нужно стереть по-настоящему, удаляются точечно через message_edit.php?action=delete.
 *
 * Ключ чата (chat_key) — тот же, что у папок и закреплений:
 *   <user_id> | channel:<psy_id> | group:<id> | support:<thread> | __fav__
 *
 * GET  ?action=list                  — мои скрытые диалоги: [{chat_key, hidden_at}]
 * POST ?action=hide    {chat_key}    — скрыть (повторный вызов обновляет момент скрытия)
 * POST ?action=unhide  {chat_key}    — вернуть в список
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

$initError = '';
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_hidden (
        user_id VARCHAR(64) NOT NULL,
        chat_key VARCHAR(120) NOT NULL,
        hidden_at DATETIME NOT NULL,
        PRIMARY KEY (user_id, chat_key)
    ) DEFAULT CHARSET=utf8mb4");
    // Новые таблицы создаются в utf8mb4_0900_ai_ci, а users/psychologists — в utf8mb4_unicode_ci;
    // без выравнивания любой JOIN падает с ошибкой 1267 (см. schema_util.php).
    psy_align_collation($pdo, ['chat_hidden']);
} catch (Exception $e) {
    $initError = $e->getMessage();
}

$action = $_GET['action'] ?? '';
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

function chatKeyFrom($v) {
    $v = trim((string)$v);
    if ($v === '' || strlen($v) > 120) return '';
    return $v;
}

try {
    if ($action === 'list') {
        // Скрытие — удобство: если таблицы нет, список чатов должен работать как раньше.
        if ($initError) out(['ok' => true, 'data' => []]);
        $st = $pdo->prepare("SELECT chat_key, hidden_at FROM chat_hidden WHERE user_id = ? ORDER BY hidden_at DESC");
        $st->execute([$userId]);
        out(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'hide' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($initError) out(['ok' => false, 'error' => 'Удаление диалога недоступно: ' . $initError], 500);
        $chatKey = chatKeyFrom($body['chat_key'] ?? '');
        if ($chatKey === '') out(['ok' => false, 'error' => 'Некорректный чат'], 400);
        $st = $pdo->prepare("SELECT 1 FROM chat_hidden WHERE user_id = ? AND chat_key = ?");
        $st->execute([$userId, $chatKey]);
        if ($st->fetchColumn()) {
            $pdo->prepare("UPDATE chat_hidden SET hidden_at = NOW() WHERE user_id = ? AND chat_key = ?")
                ->execute([$userId, $chatKey]);
        } else {
            $pdo->prepare("INSERT INTO chat_hidden (user_id, chat_key, hidden_at) VALUES (?, ?, NOW())")
                ->execute([$userId, $chatKey]);
        }
        $now = $pdo->prepare("SELECT hidden_at FROM chat_hidden WHERE user_id = ? AND chat_key = ?");
        $now->execute([$userId, $chatKey]);
        out(['ok' => true, 'chat_key' => $chatKey, 'hidden_at' => $now->fetchColumn()]);
    }

    if ($action === 'unhide' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($initError) out(['ok' => false, 'error' => 'Удаление диалога недоступно: ' . $initError], 500);
        $chatKey = chatKeyFrom($body['chat_key'] ?? '');
        if ($chatKey === '') out(['ok' => false, 'error' => 'Некорректный чат'], 400);
        $pdo->prepare("DELETE FROM chat_hidden WHERE user_id = ? AND chat_key = ?")->execute([$userId, $chatKey]);
        out(['ok' => true, 'chat_key' => $chatKey]);
    }

    out(['ok' => false, 'error' => 'Неизвестное действие'], 400);
} catch (Exception $e) {
    out(['ok' => false, 'error' => $e->getMessage()], 500);
}
