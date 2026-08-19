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
 * GET  ?action=contacts        — кому можно показать историю (для выбора аудитории)
 * GET  ?action=viewers&story_id= — кто посмотрел мою историю
 * POST ?action=create  {media_url, media_type, caption, audience, allowed[]}
 * POST ?action=view    {story_id}
 * POST ?action=delete  {story_id}
 *
 * Аудитория (audience):
 *   contacts    — все, с кем есть переписка (по умолчанию)
 *   subscribers — подписчики моего канала (для психологов)
 *   selected    — только выбранные люди, список в story_audience
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
    // Аудитория появилась позже — на существующей таблице добавляем колонку отдельно.
    try { $pdo->exec("ALTER TABLE stories ADD COLUMN audience VARCHAR(20) NOT NULL DEFAULT 'contacts'"); }
    catch (Exception $e) { /* колонка уже есть */ }
    $pdo->exec("CREATE TABLE IF NOT EXISTS story_audience (
        story_id INT NOT NULL,
        user_id VARCHAR(64) NOT NULL,
        PRIMARY KEY (story_id, user_id)
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS story_views (
        story_id INT NOT NULL,
        viewer_id VARCHAR(64) NOT NULL,
        viewed_at DATETIME NOT NULL,
        PRIMARY KEY (story_id, viewer_id)
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS story_likes (
        story_id INT NOT NULL,
        user_id VARCHAR(64) NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (story_id, user_id)
    ) DEFAULT CHARSET=utf8mb4");
    psy_align_collation($pdo, ['stories', 'story_views', 'story_audience', 'story_likes']);
} catch (Exception $e) {
    $storiesError = $e->getMessage();
}

$action = $_GET['action'] ?? '';
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

/**
 * Написать человеку в личку от чужого имени (комментарий к истории уходит автору
 * в ЛС, как в Instagram). Таблицу messages мы не заводим — она серверная, и в разных
 * установках ключ то автоинкрементный, то строковый: спрашиваем у базы, а не гадаем.
 */
function storyIdIsAuto(PDO $pdo) {
    try {
        $st = $pdo->query("SHOW COLUMNS FROM messages LIKE 'id'");
        $r = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
        if ($r && isset($r['Extra'])) return stripos((string)$r['Extra'], 'auto_increment') !== false;
    } catch (Exception $e) {}
    return false;
}

function storySendDm(PDO $pdo, $from, $to, $content, $attUrl = null) {
    $now = date('Y-m-d H:i:s');
    $type = $attUrl ? 'image' : null;
    try {
        if (storyIdIsAuto($pdo)) {
            $st = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, content, created_at, attachment_url, attachment_type)
                                 VALUES (?, ?, ?, ?, ?, ?)");
            $st->execute([$from, $to, $content, $now, $attUrl, $type]);
        } else {
            $id = bin2hex(random_bytes(16));
            $st = $pdo->prepare("INSERT INTO messages (id, sender_id, receiver_id, content, created_at, attachment_url, attachment_type)
                                 VALUES (?, ?, ?, ?, ?, ?, ?)");
            $st->execute([$id, $from, $to, $content, $now, $attUrl, $type]);
        }
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/** Сколько лайков у историй и какие из них мои — одним запросом на список. */
function storyLikeMap(PDO $pdo, array $storyIds, $userId) {
    $out = [];
    if (!$storyIds) return $out;
    $in = implode(',', array_fill(0, count($storyIds), '?'));
    try {
        $st = $pdo->prepare("SELECT story_id, COUNT(*) AS cnt,
                                    SUM(CASE WHEN user_id = ? THEN 1 ELSE 0 END) AS mine
                             FROM story_likes WHERE story_id IN ($in) GROUP BY story_id");
        $st->execute(array_merge([$userId], $storyIds));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['story_id']] = ['count' => (int)$r['cnt'], 'mine' => (int)$r['mine'] > 0];
        }
    } catch (Exception $e) {}
    return $out;
}

/** Контакты — те, с кем была переписка (сообщение в любую сторону). */
function contactIds(PDO $pdo, $userId) {
    $st = $pdo->prepare("SELECT DISTINCT CASE WHEN sender_id = ? THEN receiver_id ELSE sender_id END AS uid
                          FROM messages WHERE (sender_id = ? OR receiver_id = ?) AND receiver_id IS NOT NULL");
    $st->execute([$userId, $userId, $userId]);
    return array_values(array_filter($st->fetchAll(PDO::FETCH_COLUMN), fn($v) => $v !== null && $v !== ''));
}

/** Подписчики моего канала — если я психолог и канал у меня есть. */
function subscriberIds(PDO $pdo, $userId) {
    try {
        $st = $pdo->prepare("SELECT id FROM psychologists WHERE user_id = ? LIMIT 1");
        $st->execute([$userId]);
        $pid = $st->fetchColumn();
        if (!$pid) return [];
        $st = $pdo->prepare("SELECT user_id FROM channel_subscriptions WHERE psychologist_id = ?");
        $st->execute([$pid]);
        return array_values(array_filter($st->fetchAll(PDO::FETCH_COLUMN)));
    } catch (Exception $e) { return []; }
}

/** Вижу ли я эту историю. Автор видит свою всегда. */
function maySeeStory(PDO $pdo, $story, $userId) {
    $author = (string)$story['user_id'];
    if ($author === (string)$userId) return true;
    $aud = (string)($story['audience'] ?? 'contacts');
    if ($aud === 'selected') {
        try {
            $st = $pdo->prepare("SELECT 1 FROM story_audience WHERE story_id = ? AND user_id = ? LIMIT 1");
            $st->execute([(int)$story['id'], $userId]);
            return (bool)$st->fetchColumn();
        } catch (Exception $e) { return false; }
    }
    if ($aud === 'subscribers') return in_array((string)$userId, array_map('strval', subscriberIds($pdo, $author)), true);
    return true;    // contacts — выборку по контактам делает сам запрос
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
        // Аудитория проверяется здесь, а не в SQL: правил три, и в запросе они
        // превратились бы в нечитаемое условие с подзапросами на каждую строку.
        $rows = array_values(array_filter($st->fetchAll(PDO::FETCH_ASSOC),
                                          fn($r) => maySeeStory($pdo, $r, $userId)));
        $labels = userLabel($pdo, $ids);
        $likes = storyLikeMap($pdo, array_map(fn($r) => (int)$r['id'], $rows), $userId);

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
            $views = null;
            if ((string)$uid === (string)$userId) {
                try {
                    $vc = $pdo->prepare("SELECT COUNT(*) FROM story_views WHERE story_id = ?");
                    $vc->execute([(int)$r['id']]);
                    $views = (int)$vc->fetchColumn();
                } catch (Exception $e) { $views = 0; }
            }
            $byUser[$uid]['stories'][] = [
                'id' => (int)$r['id'], 'media_url' => $r['media_url'], 'media_type' => $r['media_type'],
                'caption' => $r['caption'], 'created_at' => $r['created_at'], 'seen' => $seen,
                'audience' => $r['audience'] ?? 'contacts', 'views_count' => $views,
                'likes_count' => $likes[(int)$r['id']]['count'] ?? 0,
                'liked' => $likes[(int)$r['id']]['mine'] ?? false,
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
        $aud = (string)($body['audience'] ?? 'contacts');
        if (!in_array($aud, ['contacts', 'subscribers', 'selected'], true)) $aud = 'contacts';
        $allowed = array_values(array_filter(array_map('strval', (array)($body['allowed'] ?? []))));
        // «Выбранные» без списка показали бы историю никому — честнее вернуть ошибку,
        // чем молча опубликовать то, чего никто не увидит.
        if ($aud === 'selected' && !$allowed) out(['ok' => false, 'error' => 'Выберите, кому показать историю'], 400);
        $pdo->prepare("INSERT INTO stories (user_id, media_url, media_type, caption, audience, created_at, expires_at)
                        VALUES (?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR))")
            ->execute([$userId, $mediaUrl, $mediaType ?: 'image', $caption ?: null, $aud]);
        $sid = (int)$pdo->lastInsertId();
        if ($aud === 'selected') {
            $ins = $pdo->prepare("INSERT INTO story_audience (story_id, user_id) VALUES (?, ?)");
            foreach (array_slice(array_unique($allowed), 0, 200) as $uid) {
                try { $ins->execute([$sid, $uid]); } catch (Exception $e) {}
            }
        }
        out(['ok' => true, 'id' => $sid, 'audience' => $aud]);
    }

    if ($action === 'view' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $storyId = (int)($body['story_id'] ?? 0);
        if (!$storyId) out(['ok' => false, 'error' => 'Не передана история'], 400);
        $pdo->prepare("INSERT INTO story_views (story_id, viewer_id, viewed_at) VALUES (?, ?, NOW())
                        ON DUPLICATE KEY UPDATE viewed_at = NOW()")
            ->execute([$storyId, $userId]);
        out(['ok' => true]);
    }

    if ($action === 'viewers') {
        // Кто посмотрел — только автору своей истории. Иначе можно было бы подсмотреть,
        // кто читает чужие публикации.
        $storyId = (int)($_GET['story_id'] ?? 0);
        if (!$storyId) out(['ok' => false, 'error' => 'Не передана история'], 400);
        $st = $pdo->prepare("SELECT user_id FROM stories WHERE id = ? LIMIT 1");
        $st->execute([$storyId]);
        $owner = $st->fetchColumn();
        if (!$owner) out(['ok' => false, 'error' => 'История не найдена'], 404);
        if ((string)$owner !== (string)$userId) out(['ok' => false, 'error' => 'Просмотры видит только автор'], 403);
        $st = $pdo->prepare("SELECT v.viewer_id, v.viewed_at, u.first_name, u.last_name, u.avatar
                              FROM story_views v LEFT JOIN users u ON u.id = v.viewer_id
                              WHERE v.story_id = ? ORDER BY v.viewed_at DESC LIMIT 300");
        $st->execute([$storyId]);
        $viewRows = $st->fetchAll(PDO::FETCH_ASSOC);

        // Кто поставил лайк. Лайкнуть может и тот, кого нет в просмотрах (теоретически —
        // если запись о просмотре не дошла), поэтому таких добавляем в список отдельно.
        $liked = [];
        try {
            $lq = $pdo->prepare("SELECT l.user_id, l.created_at, u.first_name, u.last_name, u.avatar
                                  FROM story_likes l LEFT JOIN users u ON u.id = l.user_id
                                  WHERE l.story_id = ? ORDER BY l.created_at DESC LIMIT 300");
            $lq->execute([$storyId]);
            foreach ($lq->fetchAll(PDO::FETCH_ASSOC) as $l) $liked[(string)$l['user_id']] = $l;
        } catch (Exception $e) {}

        $rows = array_map(fn($r) => [
            'user_id' => $r['viewer_id'],
            'name' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: 'Пользователь',
            'avatar' => $r['avatar'] ?? null,
            'viewed_at' => $r['viewed_at'],
            'liked' => isset($liked[(string)$r['viewer_id']]),
        ], $viewRows);

        $seenIds = array_map(fn($r) => (string)$r['viewer_id'], $viewRows);
        foreach ($liked as $uid => $l) {
            if (in_array((string)$uid, $seenIds, true)) continue;
            $rows[] = [
                'user_id' => $uid,
                'name' => trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? '')) ?: 'Пользователь',
                'avatar' => $l['avatar'] ?? null,
                'viewed_at' => $l['created_at'],
                'liked' => true,
            ];
        }
        out(['ok' => true, 'data' => $rows, 'count' => count($rows), 'likes' => count($liked)]);
    }

    if ($action === 'like' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $storyId = (int)($body['story_id'] ?? 0);
        if (!$storyId) out(['ok' => false, 'error' => 'Не передана история'], 400);
        $st = $pdo->prepare("SELECT * FROM stories WHERE id = ? AND expires_at > NOW() LIMIT 1");
        $st->execute([$storyId]);
        $story = $st->fetch(PDO::FETCH_ASSOC);
        if (!$story) out(['ok' => false, 'error' => 'История не найдена или уже истекла'], 404);
        // Лайкнуть можно только то, что тебе и так показано
        if (!maySeeStory($pdo, $story, $userId)) out(['ok' => false, 'error' => 'Эта история вам не показывается'], 403);

        $on = !empty($body['on']);
        if ($on) {
            try {
                $q = $pdo->prepare("INSERT INTO story_likes (story_id, user_id, created_at) VALUES (?, ?, ?)");
                $q->execute([$storyId, $userId, date('Y-m-d H:i:s')]);
                // Автору — весточка в личку, но не самому себе и не на повторный лайк
                if ((string)$story['user_id'] !== (string)$userId) {
                    storySendDm($pdo, $userId, $story['user_id'],
                                '❤️ Понравилась ваша история', $story['media_url']);
                }
            } catch (Exception $e) { /* уже лайкнуто — это не ошибка */ }
        } else {
            $q = $pdo->prepare("DELETE FROM story_likes WHERE story_id = ? AND user_id = ?");
            $q->execute([$storyId, $userId]);
        }
        $c = $pdo->prepare("SELECT COUNT(*) FROM story_likes WHERE story_id = ?");
        $c->execute([$storyId]);
        out(['ok' => true, 'liked' => $on, 'count' => (int)$c->fetchColumn()]);
    }

    if ($action === 'comment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Комментарий к чужой истории уходит автору в личку с пометкой, к какой именно
        // истории он относится (как в Instagram и Telegram) — отдельной ленты комментариев
        // под историями нет намеренно: у нас всё общение живёт в переписках.
        $storyId = (int)($body['story_id'] ?? 0);
        $text = trim((string)($body['text'] ?? ''));
        if (!$storyId) out(['ok' => false, 'error' => 'Не передана история'], 400);
        if ($text === '') out(['ok' => false, 'error' => 'Пустой комментарий'], 400);
        if (mb_strlen($text) > 1000) $text = mb_substr($text, 0, 1000);

        $st = $pdo->prepare("SELECT * FROM stories WHERE id = ? AND expires_at > NOW() LIMIT 1");
        $st->execute([$storyId]);
        $story = $st->fetch(PDO::FETCH_ASSOC);
        if (!$story) out(['ok' => false, 'error' => 'История не найдена или уже истекла'], 404);
        if (!maySeeStory($pdo, $story, $userId)) out(['ok' => false, 'error' => 'Эта история вам не показывается'], 403);
        if ((string)$story['user_id'] === (string)$userId) {
            out(['ok' => false, 'error' => 'Это ваша история — комментировать её себе незачем'], 400);
        }

        $okSent = storySendDm($pdo, $userId, $story['user_id'],
                              '💬 Комментарий к вашей истории: ' . $text, $story['media_url']);
        if (!$okSent) out(['ok' => false, 'error' => 'Не удалось отправить сообщение автору'], 500);
        out(['ok' => true, 'sent_to' => (string)$story['user_id']]);
    }

    if ($action === 'contacts') {
        // Кому можно показать: контакты по переписке плюс подписчики канала.
        $ids = array_values(array_unique(array_merge(contactIds($pdo, $userId), subscriberIds($pdo, $userId))));
        $ids = array_values(array_filter($ids, fn($v) => (string)$v !== (string)$userId));
        $labels = userLabel($pdo, $ids);
        $subs = array_map('strval', subscriberIds($pdo, $userId));
        $rows = [];
        foreach ($ids as $uid) {
            $u = $labels[$uid] ?? null;
            $rows[] = [
                'user_id' => $uid,
                'name' => $u ? (trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: 'Пользователь') : 'Пользователь',
                'avatar' => $u['avatar'] ?? null,
                'is_subscriber' => in_array((string)$uid, $subs, true),
            ];
        }
        usort($rows, fn($a, $b) => strcmp($a['name'], $b['name']));
        out(['ok' => true, 'data' => $rows, 'has_channel' => !empty($subs)]);
    }

    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $storyId = (int)($body['story_id'] ?? 0);
        if (!$storyId) out(['ok' => false, 'error' => 'Не передана история'], 400);
        $st = $pdo->prepare("DELETE FROM stories WHERE id = ? AND user_id = ?");
        $st->execute([$storyId, $userId]);
        $gone = $st->rowCount() > 0;
        if ($gone) {   // без этого от удалённой истории оставались просмотры и список аудитории
            foreach (['story_views', 'story_audience'] as $t) {
                try { $pdo->prepare("DELETE FROM `$t` WHERE story_id = ?")->execute([$storyId]); } catch (Exception $e) {}
            }
        }
        out(['ok' => true, 'deleted' => $gone]);
    }

    out(['ok' => false, 'error' => 'Неизвестное действие'], 400);
} catch (Exception $e) {
    out(['ok' => false, 'error' => $e->getMessage()], 500);
}
