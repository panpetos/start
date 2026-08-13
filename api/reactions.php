<?php
/**
 * reactions.php — эмодзи-реакции на сообщения.
 *
 * POST ?action=toggle {message_id, chat_key, emoji}  — поставить или снять свою реакцию
 * GET  ?action=list&chat_key=<ключ>                  — реакции всей переписки разом
 *
 * chat_key — с кем переписка: id человека либо "group:<id>". Он нужен, чтобы отдавать
 * реакции всего диалога одним запросом, а не спрашивать про каждое сообщение.
 *
 * Отдельным файлом по той же причине, что и presence.php: личные сообщения пишет
 * серверный messages.php, которого нет в репозитории, и добавить туда колонку нельзя.
 */

header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');
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

/** Набор, который предлагаем в интерфейсе. Чужие эмодзи не принимаем — незачем. */
const ALLOWED_REACTIONS = ['👍', '❤️', '🔥', '😂', '😮', '😢', '🙏', '👏'];

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS message_reactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        message_id VARCHAR(64) NOT NULL,
        chat_key VARCHAR(140) NOT NULL,   -- для личной переписки это два id через |
        user_id VARCHAR(64) NOT NULL,
        emoji VARCHAR(16) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_one (message_id, user_id, emoji),
        INDEX idx_chat (chat_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    psy_align_collation($pdo, ['message_reactions']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Не удалось подготовить таблицу: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? '';
$body = json_decode(file_get_contents('php://input'), true) ?: [];

if ($action === 'toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $mid = trim((string)($body['message_id'] ?? ''));
    $key = trim((string)($body['chat_key'] ?? ''));
    $emoji = trim((string)($body['emoji'] ?? ''));
    if ($mid === '' || $key === '') { http_response_code(400); echo json_encode(['error' => 'Не указано сообщение']); exit; }
    if (!in_array($emoji, ALLOWED_REACTIONS, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Такую реакцию поставить нельзя'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        // Повторное нажатие снимает свою реакцию — как везде
        $st = $pdo->prepare("SELECT id FROM message_reactions WHERE message_id = ? AND user_id = ? AND emoji = ? LIMIT 1");
        $st->execute([$mid, $userId, $emoji]);
        $existing = $st->fetchColumn();
        if ($existing) {
            $pdo->prepare("DELETE FROM message_reactions WHERE id = ?")->execute([$existing]);
            $set = false;
        } else {
            $pdo->prepare("INSERT INTO message_reactions (message_id, chat_key, user_id, emoji) VALUES (?,?,?,?)")
                ->execute([$mid, $key, $userId, $emoji]);
            $set = true;
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => true, 'set' => $set], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'list') {
    $key = trim((string)($_GET['chat_key'] ?? ''));
    if ($key === '') { echo json_encode(['ok' => true, 'data' => new stdClass()]); exit; }
    $out = [];
    try {
        $st = $pdo->prepare("SELECT message_id, emoji, user_id FROM message_reactions WHERE chat_key = ?");
        $st->execute([$key]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $m = (string)$r['message_id'];
            $e = (string)$r['emoji'];
            if (!isset($out[$m])) $out[$m] = [];
            if (!isset($out[$m][$e])) $out[$m][$e] = ['count' => 0, 'mine' => false];
            $out[$m][$e]['count']++;
            if ((string)$r['user_id'] === (string)$userId) $out[$m][$e]['mine'] = true;
        }
    } catch (Exception $e) {}
    echo json_encode(['ok' => true, 'data' => $out ?: new stdClass(),
                      'allowed' => ALLOWED_REACTIONS], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Неизвестное действие']);
