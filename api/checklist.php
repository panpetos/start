<?php
/**
 * checklist.php — переключение галочек в списках задач прямо в сообщении.
 *
 * ЗАЧЕМ. Галочку в обычном списке «- [ ] » может ставить только автор (через
 * правку сообщения). Но список задач часто нужен общий: чтобы в группе или в
 * личной переписке отметить пункт мог любой участник, а отметку видели все.
 *
 * КАК. Такой «общий» список автор оформляет блоком ```задачи-всем … ```. Клиент
 * шлёт сюда номер пункта и желаемое состояние, а сервер сам решает, вправе ли
 * человек его менять:
 *   • пункт внутри блока ```задачи-всем — может любой участник разговора;
 *   • обычный строчный «- [ ] »        — только автор сообщения (личный по умолчанию).
 * Состояние хранится прямо в тексте сообщения, поэтому его видят все.
 *
 * POST ?action=toggle {kind:'msg'|'group'|'fav', message_id, index, checked}
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

function clOut($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$isAdmin = false;
try {
    $st = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    $st->execute([$userId]);
    $isAdmin = ((string)$st->fetchColumn() === 'admin');
} catch (Exception $e) {}

/**
 * Найти index-й пункт-чеклист в тексте и понять, «общий» он или нет.
 * Возвращает [номер строки, флаг «внутри блока задачи-всем», разбор строки] или null.
 */
function clLocate(array $lines, $index) {
    $inAll = false;
    $idx = -1;
    foreach ($lines as $li => $ln) {
        if ($inAll) {
            if (preg_match('/^\s*```\s*$/u', $ln)) { $inAll = false; }
            // строки внутри блока тоже считаем пунктами (ниже)
        } elseif (preg_match('/^\s*```\s*(?:задачи|чеклист|todo)[-\s]*всем\s*$/ui', $ln)) {
            $inAll = true;
            continue;
        }
        if (preg_match('/^(\s*[-*]\s+\[)([ xXvV\x{2713}])(\].*)$/u', $ln, $m)) {
            $idx++;
            if ($idx === (int)$index) return [$li, $inAll, $m];
        }
    }
    return null;
}

$action = $_GET['action'] ?? '';
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

if ($action !== 'toggle' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    clOut(['error' => 'Неизвестное действие'], 400);
}

$kind = (string)($body['kind'] ?? 'msg');
$messageId = trim((string)($body['message_id'] ?? ''));
$index = (int)($body['index'] ?? -1);
$checked = !empty($body['checked']);
if ($messageId === '' || $index < 0) clOut(['error' => 'Некорректный запрос'], 400);

// ── Достаём сообщение и понимаем, кто его участники ──────────────────────────
$content = null; $isAuthor = false; $isParticipant = false;
try {
    if ($kind === 'group') {
        $st = $pdo->prepare("SELECT group_id, sender_id, content FROM chat_group_messages WHERE id = ? LIMIT 1");
        $st->execute([$messageId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) clOut(['error' => 'Сообщение не найдено'], 404);
        $content = (string)$row['content'];
        $isAuthor = (string)$row['sender_id'] === (string)$userId;
        $mem = $pdo->prepare("SELECT 1 FROM chat_group_members WHERE group_id = ? AND user_id = ? LIMIT 1");
        $mem->execute([$row['group_id'], $userId]);
        $isParticipant = (bool)$mem->fetchColumn();
    } elseif ($kind === 'fav') {
        $st = $pdo->prepare("SELECT user_id, content FROM favorite_messages WHERE id = ? LIMIT 1");
        $st->execute([$messageId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) clOut(['error' => 'Запись не найдена'], 404);
        $content = (string)$row['content'];
        $isAuthor = (string)$row['user_id'] === (string)$userId;
        $isParticipant = $isAuthor;   // «Избранное» — личное пространство
    } else { // msg — личная переписка
        $st = $pdo->prepare("SELECT sender_id, receiver_id, content FROM messages WHERE id = ? LIMIT 1");
        $st->execute([$messageId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) clOut(['error' => 'Сообщение не найдено'], 404);
        $content = (string)$row['content'];
        $isAuthor = (string)$row['sender_id'] === (string)$userId
                 || ($isAdmin && (string)$row['sender_id'] === 'support');
        $isParticipant = $isAuthor
                 || (string)($row['receiver_id'] ?? '') === (string)$userId
                 || $isAdmin;
    }
} catch (Exception $e) {
    clOut(['error' => 'Не удалось прочитать сообщение'], 500);
}

// ── Находим нужный пункт и проверяем право его менять ────────────────────────
$lines = explode("\n", $content);
$loc = clLocate($lines, $index);
if (!$loc) clOut(['error' => 'Пункт не найден'], 404);
list($lineNo, $scopeAll, $m) = $loc;

if ($scopeAll) {
    // Общий список: отметить может любой участник разговора.
    if (!$isParticipant) clOut(['error' => 'Нет доступа к этому разговору'], 403);
} else {
    // Личный пункт: только автор.
    if (!$isAuthor) clOut(['error' => 'Отмечать этот пункт может только автор'], 403);
}

// ── Ставим/снимаем галочку и сохраняем ───────────────────────────────────────
// «Отмечено» — любой непробельный символ (x/v/✓); снятое — пробел.
$curChecked = ($m[2] !== ' ');
if ($curChecked === $checked) clOut(['ok' => true, 'unchanged' => true]);
$lines[$lineNo] = $m[1] . ($checked ? 'x' : ' ') . $m[3];
$newContent = implode("\n", $lines);

try {
    if ($kind === 'group') {
        $pdo->prepare("UPDATE chat_group_messages SET content = ? WHERE id = ?")->execute([$newContent, $messageId]);
    } elseif ($kind === 'fav') {
        $pdo->prepare("UPDATE favorite_messages SET content = ? WHERE id = ? AND user_id = ?")
            ->execute([$newContent, $messageId, $userId]);
    } else {
        $pdo->prepare("UPDATE messages SET content = ? WHERE id = ?")->execute([$newContent, $messageId]);
    }
} catch (Exception $e) {
    clOut(['error' => 'Не удалось сохранить отметку'], 500);
}

clOut(['ok' => true, 'checked' => $checked]);
