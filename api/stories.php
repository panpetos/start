<?php
/**
 * stories.php — «Истории»: короткая публикация (фото), видна 24 часа, как в Telegram/MAX
 * (просьба админа: «добавь истории возможность выкладывать на своих контактов»).
 *
 * MVP-охват (решено самостоятельно — админ не ответил на уточняющие вопросы):
 *  • только фото (видео — следующим шагом, если понадобится);
 *  • живёт 24 часа, потом просто перестаёт попадать в выборку (expires_at в WHERE);
 *  • видна «контактам» — тем, с кем есть переписка (сообщение в любую сторону в messages),
 *    это единственное естественное понятие «контакта» в проекте на сегодня;
 *  • в интерфейсе — кружки над списком чатов, как просили.
 *
 * GET  ?action=feed            — истории моих контактов (сгруппированы по автору) + мои
 * GET  ?action=mine            — только мои активные истории
 * POST ?action=create  {media_url, media_type, caption}
 * POST ?action=view    {story_id}
 * POST ?action=delete  {story_id}
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

$storiesError = '';
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS stories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(64) NOT NULL,
        media_url VARCHAR(500) NOT NULL,
        media_type VARCHAR(20) NOT NULL DEFAULT 'image',
        caption VARCHAR(300) NULL,
        created_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        INDEX idx_user (user_id),
        INDEX idx_expires (expires_at)
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS story_views (
        story_id INT NOT NULL,
        viewer_id VARCHAR(64) NOT NULL,
        viewed_at DATETIME NOT NULL,
        PRIMARY KEY (story_id, viewer_id)
    ) DEFAULT CHARSET=utf8mb4");
    psy_align_collation($pdo, ['stories', 'story_views']);
} catch (Exception $e) {
    $storiesError = $e->getMessage();
}

$action = $_GET['action'] ?? '';
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

/** Контакты — те, с кем была переписка (сообщение в любую сторону). */
function contactIds(PDO $pdo, $userId) {
    $st = $pdo->prepare("SELECT DISTINCT CASE WHEN sender_id = ? THEN receiver_id ELSE sender_id END AS uid
                          FROM messages WHERE (sender_id = ? OR receiver_id = ?) AND receiver_id IS NOT NULL");
    $st->execute([$userId, $userId, $userId]);
    return array_values(array_filter($st->fetchAll(PDO::FETCH_COLUMN), fn($v) => $v !== null && $v !== ''));
}

function userLabel(PDO $pdo, $ids) {
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, first_name, last_name, avatar FROM users WHERE id IN ($in)");
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) { $out[$u['id']] = $u; }
    return $out;
}

try {
    if ($action === 'feed') {
        if ($storiesError) out(['ok' => true, 'data' => [], 'mine' => []]);
        $ids = contactIds($pdo, $userId);
        $ids[] = $userId;
        $ids = array_values(array_unique($ids));
        if (!$ids) out(['ok' => true, 'data' => [], 'mine' => []]);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT s.*, (SELECT COUNT(*) FROM story_views v WHERE v.story_id = s.id AND v.viewer_id = ?) AS seen
                              FROM stories s
                              WHERE s.user_id IN ($in) AND s.expires_at > NOW()
                              ORDER BY s.user_id, s.created_at ASC");
        $st->execute(array_merge([$userId], $ids));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $labels = userLabel($pdo, $ids);

        $byUser = [];
        foreach ($rows as $r) {
            $uid = $r['user_id'];
            if (!isset($byUser[$uid])) {
                $u = $labels[$uid] ?? null;
                $byUser[$uid] = [
                    'user_id' => $uid,
                    'name' => $u ? trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) : 'Пользователь',
                    'avatar' => $u['avatar'] ?? null,
                    'is_mine' => ((string)$uid === (string)$userId),
                    'has_unseen' => false,
                    'stories' => [],
                ];
            }
            $seen = (bool)$r['seen'];
            if (!$seen && (string)$uid !== (string)$userId) $byUser[$uid]['has_unseen'] = true;
            $byUser[$uid]['stories'][] = [
                'id' => (int)$r['id'], 'media_url' => $r['media_url'], 'media_type' => $r['media_type'],
                'caption' => $r['caption'], 'created_at' => $r['created_at'], 'seen' => $seen,
            ];
        }
        // Мои — отдельно первыми, остальные контакты — у кого есть непросмотренные, вперёд остальных.
        $mine = $byUser[$userId] ?? null;
        unset($byUser[$userId]);
        $others = array_values($byUser);
        usort($others, fn($a, $b) => ($b['has_unseen'] <=> $a['has_unseen']));
        out(['ok' => true, 'mine' => $mine ? [$mine] : [], 'data' => $others]);
    }

    if ($action === 'mine') {
        if ($storiesError) out(['ok' => true, 'data' => []]);
        $st = $pdo->prepare("SELECT * FROM stories WHERE user_id = ? AND expires_at > NOW() ORDER BY created_at ASC");
        $st->execute([$userId]);
        out(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($storiesError) out(['ok' => false, 'error' => 'Истории недоступны: ' . $storiesError], 500);
        $mediaUrl = trim((string)($body['media_url'] ?? ''));
        $mediaType = trim((string)($body['media_type'] ?? 'image'));
        $caption = trim((string)($body['caption'] ?? ''));
        if ($mediaUrl === '') out(['ok' => false, 'error' => 'Не передано фото'], 400);
        if (mb_strlen($caption) > 300) $caption = mb_substr($caption, 0, 300, 'UTF-8');
        // Не больше 20 активных историй на человека — не подсчитывать бесконечно растущий список.
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM stories WHERE user_id = ? AND expires_at > NOW()");
        $cnt->execute([$userId]);
        if ((int)$cnt->fetchColumn() >= 20) out(['ok' => false, 'error' => 'Слишком много активных историй — подождите, пока старые истекут'], 400);
        $pdo->prepare("INSERT INTO stories (user_id, media_url, media_type, caption, created_at, expires_at)
                        VALUES (?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR))")
            ->execute([$userId, $mediaUrl, $mediaType ?: 'image', $caption ?: null]);
        out(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    }

    if ($action === 'view' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $storyId = (int)($body['story_id'] ?? 0);
        if (!$storyId) out(['ok' => false, 'error' => 'Не передана история'], 400);
        $pdo->prepare("INSERT INTO story_views (story_id, viewer_id, viewed_at) VALUES (?, ?, NOW())
                        ON DUPLICATE KEY UPDATE viewed_at = NOW()")
            ->execute([$storyId, $userId]);
        out(['ok' => true]);
    }

    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $storyId = (int)($body['story_id'] ?? 0);
        if (!$storyId) out(['ok' => false, 'error' => 'Не передана история'], 400);
        $st = $pdo->prepare("DELETE FROM stories WHERE id = ? AND user_id = ?");
        $st->execute([$storyId, $userId]);
        out(['ok' => true, 'deleted' => $st->rowCount() > 0]);
    }

    out(['ok' => false, 'error' => 'Неизвестное действие'], 400);
} catch (Exception $e) {
    out(['ok' => false, 'error' => $e->getMessage()], 500);
}
