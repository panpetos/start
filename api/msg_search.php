<?php
/**
 * msg_search.php — поиск по СОДЕРЖИМОМУ переписок.
 *
 * GET ?action=search&q=слово[&limit=40] → {ok, data:[{kind, key, name, snippet, message_id, created_at}]}
 *
 * Поиск по именам собеседников живёт в самой странице (список чатов уже загружен),
 * а вот по тексту сообщений искать можно только на сервере: переписка целиком в
 * браузер не приезжает. Отдельный файл, потому что messages.php серверный и его
 * в репозитории нет.
 *
 * Отдаём только то, что человеку и так видно: свои личные переписки и группы,
 * в которых он состоит.
 */

header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
if (!function_exists('getDB') && !function_exists('getDbConnection') && !function_exists('getPDO')) {
    require_once __DIR__ . '/db.php';
}
$pdo = function_exists('getDB') ? getDB()
     : (function_exists('getDbConnection') ? getDbConnection()
     : (function_exists('getPDO') ? getPDO() : null));
if (!$pdo) { http_response_code(500); echo json_encode(['error' => 'DB']); exit; }

if (session_status() === PHP_SESSION_NONE) session_start();
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }

$action = $_GET['action'] ?? 'search';
if ($action !== 'search') { echo json_encode(['error' => 'Неизвестное действие']); exit; }

$q = trim((string)($_GET['q'] ?? ''));
$limit = (int)($_GET['limit'] ?? 40);
if ($limit < 1 || $limit > 100) $limit = 40;
// Один-два символа находят пол-базы и ничего не проясняют
if (mb_strlen($q) < 2) { echo json_encode(['ok' => true, 'data' => [], 'short' => true]); exit; }

/** Кусочек сообщения вокруг найденного слова — чтобы в списке было видно, за что зацепилось. */
function msSnippet($text, $q, $around = 60) {
    $text = trim(preg_replace('/\s+/u', ' ', (string)$text));
    $pos = mb_stripos($text, $q);
    if ($pos === false) return mb_substr($text, 0, $around * 2);
    $from = max(0, $pos - $around);
    $out = mb_substr($text, $from, $around * 2 + mb_strlen($q));
    if ($from > 0) $out = '…' . $out;
    if ($from + mb_strlen($out) < mb_strlen($text)) $out .= '…';
    return $out;
}

try {
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $rows = [];

    // ── Личные переписки ──
    $sql = "SELECT m.id, m.sender_id, m.receiver_id, m.content, m.created_at,
                   u.first_name, u.last_name, u.avatar
            FROM messages m
            LEFT JOIN users u ON u.id = CASE WHEN m.sender_id = :me THEN m.receiver_id ELSE m.sender_id END
            WHERE (m.sender_id = :me2 OR m.receiver_id = :me3)
              AND m.content LIKE :like
            ORDER BY m.created_at DESC
            LIMIT :lim";
    $st = $pdo->prepare($sql);
    $st->bindValue(':me', $userId);
    $st->bindValue(':me2', $userId);
    $st->bindValue(':me3', $userId);
    $st->bindValue(':like', $like);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $peer = ($r['sender_id'] === $userId) ? $r['receiver_id'] : $r['sender_id'];
        if (!$peer) continue;                       // системные записи без собеседника
        $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        $rows[] = [
            'kind'       => 'dm',
            'key'        => (string)$peer,
            'name'       => $name !== '' ? $name : 'Собеседник',
            'avatar'     => $r['avatar'] ?? null,
            'snippet'    => msSnippet($r['content'], $q),
            'message_id' => (string)$r['id'],
            'mine'       => $r['sender_id'] === $userId,
            'created_at' => $r['created_at'],
        ];
    }

    // ── Группы и каналы, где человек состоит ──
    try {
        $sql = "SELECT gm.id, gm.group_id, gm.sender_id, gm.content, gm.created_at, g.name
                FROM chat_group_messages gm
                JOIN chat_group_members mem ON mem.group_id = gm.group_id AND mem.user_id = :me
                JOIN chat_groups g ON g.id = gm.group_id
                WHERE gm.content LIKE :like
                ORDER BY gm.created_at DESC
                LIMIT :lim";
        $st = $pdo->prepare($sql);
        $st->bindValue(':me', $userId);
        $st->bindValue(':like', $like);
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [
                'kind'       => 'group',
                'key'        => (string)$r['group_id'],
                'name'       => $r['name'] ?: 'Группа',
                'avatar'     => null,
                'snippet'    => msSnippet($r['content'], $q),
                'message_id' => (string)$r['id'],
                'mine'       => $r['sender_id'] === $userId,
                'created_at' => $r['created_at'],
            ];
        }
    } catch (Exception $e) {
        // Групп может не быть вовсе — это не повод ронять поиск по личным
    }

    // Свежее — выше, независимо от того, личное это или группа
    usort($rows, function ($a, $b) { return strcmp((string)$b['created_at'], (string)$a['created_at']); });
    $rows = array_slice($rows, 0, $limit);

    echo json_encode(['ok' => true, 'data' => $rows, 'q' => $q], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Поиск не сработал: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
