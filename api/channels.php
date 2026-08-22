<?php
/**
 * channels.php — простые публичные «каналы» психологов (лента постов) + подписки
 * клиентов. MVP задачи #29 (Telegram-подобные каналы).
 *
 * GET  ?action=posts&psychologist_id=X        — публичная лента постов психолога (без авторизации)
 * GET  ?action=info&psychologist_id=X         — {subscribers_count, is_subscribed}
 * GET  ?action=my-subscriptions                — каналы, на которые подписан текущий пользователь
 * POST ?action=post   {text?, image_url?}      — добавить пост в СВОЙ канал (только психолог)
 * POST ?action=edit-post   {id, text?, image_url?} — отредактировать свой пост (доработка по фидбэку админа)
 * POST ?action=delete-post {id}                — удалить свой пост
 * POST ?action=subscribe   {psychologist_id}   — подписаться (авторизованный пользователь)
 * POST ?action=unsubscribe {psychologist_id}   — отписаться
 * POST ?action=save-settings {title?, description?, avatar_url?} — оформление СВОЕГО канала (психолог)
 *
 * Доработка по фидбэку админа (рефок задачи #29) — лайки/просмотры/шеринг/общая лента:
 * GET  ?action=feed&limit=20&before_id=X       — общая лента постов ВСЕХ психологов (для всех ролей)
 * POST ?action=like   {post_id}                — поставить/снять лайк (toggle, авторизованный пользователь)
 * POST ?action=view   {post_id}                — засчитать просмотр (раз на посетителя, можно анонимно)
 *
 * Доработка (рефок #2 задачи #29) — канал как раздел чата + комментарии к постам:
 * GET  ?action=comments&post_id=X               — комментарии к посту (публично)
 * POST ?action=comment       {post_id, text}     — добавить комментарий (авторизованный пользователь)
 * POST ?action=delete-comment {id}               — удалить свой комментарий ИЛИ автор канала удаляет любой
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

function ensureChannelTables(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS channel_posts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        psychologist_id VARCHAR(64) NOT NULL,
        text TEXT NULL,
        image_url VARCHAR(500) NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_psych (psychologist_id)
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS channel_subscriptions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id VARCHAR(64) NOT NULL,
        psychologist_id VARCHAR(64) NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uniq_sub (client_id, psychologist_id)
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS channel_post_likes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        post_id INT NOT NULL,
        user_id VARCHAR(64) NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uniq_like (post_id, user_id)
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS channel_post_views (
        id INT AUTO_INCREMENT PRIMARY KEY,
        post_id INT NOT NULL,
        viewer_key VARCHAR(64) NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uniq_view (post_id, viewer_key)
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS channel_post_comments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        post_id INT NOT NULL,
        user_id VARCHAR(64) NOT NULL,
        text TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_post (post_id)
    ) DEFAULT CHARSET=utf8mb4");
    // Оформление канала: своя аватарка, название и описание — отдельно от личного фото
    // психолога (фидбэк админа: «нужно моч ставить аватарку на группу или канал с описанием»).
    $pdo->exec("CREATE TABLE IF NOT EXISTS channel_settings (
        psychologist_id VARCHAR(64) NOT NULL PRIMARY KEY,
        title VARCHAR(255) NULL,
        description VARCHAR(500) NULL,
        avatar_url VARCHAR(500) NULL,
        updated_at DATETIME NOT NULL
    ) DEFAULT CHARSET=utf8mb4");
    // Модерация постов админом: скрытый пост не показывается ни в ленте, ни в канале,
    // но не удаляется безвозвратно (можно вернуть). Причина видна автору и админу.
    try { $pdo->exec("ALTER TABLE channel_posts ADD COLUMN is_hidden TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE channel_posts ADD COLUMN hidden_reason VARCHAR(500) NULL"); } catch (Exception $e) {}
    // Сортировка строковых ключей должна совпадать с users/psychologists, иначе JOIN'ы (лента,
    // подписки, комментарии) падают с ошибкой 1267 и выглядят как «данных нет».
    psy_align_collation($pdo, ['channel_posts', 'channel_subscriptions', 'channel_post_likes',
        'channel_post_views', 'channel_post_comments', 'channel_settings']);
}

/** Есть ли колонка в таблице (кэшируется на запрос). */
function hasColumn(PDO $pdo, $table, $column) {
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];
    $ok = false;
    try {
        $st = $pdo->query("SELECT * FROM $table LIMIT 0");
        for ($i = 0, $n = $st->columnCount(); $i < $n; $i++) {
            $meta = $st->getColumnMeta($i);
            if ($meta && isset($meta['name']) && strcasecmp($meta['name'], $column) === 0) { $ok = true; break; }
        }
    } catch (Exception $e) { $ok = false; }
    return $cache[$key] = $ok;
}

/** Текущий пользователь — администратор? */
function isAdminUser(PDO $pdo, $userId) {
    if (!$userId) return false;
    try {
        $st = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        $st->execute([$userId]);
        return ((string)$st->fetchColumn() === 'admin');
    } catch (Exception $e) { return false; }
}

/** Анонимный ключ просмотра для гостей — тот же приём, что и в analytics.php (без ПД). */
function viewerKey() {
    if (!empty($_SESSION['user_id'])) return 'u:' . $_SESSION['user_id'];
    $vid = $_COOKIE['psy_vid'] ?? '';
    if (preg_match('/^[a-f0-9]{32}$/', (string)$vid)) return 'v:' . $vid;
    return 'ip:' . substr(md5(($_SERVER['REMOTE_ADDR'] ?? '') . ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 24);
}

/** Долить лайки/просмотры/liked_by_me к массиву постов (по одному запросу на метрику). */
function attachPostStats(PDO $pdo, array $posts, $userId) {
    if (!$posts) return $posts;
    $ids = array_column($posts, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $likes = []; $views = []; $mine = []; $comments = [];
    try {
        $st = $pdo->prepare("SELECT post_id, COUNT(*) c FROM channel_post_likes WHERE post_id IN ($in) GROUP BY post_id");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $likes[$r['post_id']] = (int)$r['c'];
    } catch (Exception $e) {}
    try {
        $st = $pdo->prepare("SELECT post_id, COUNT(*) c FROM channel_post_views WHERE post_id IN ($in) GROUP BY post_id");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $views[$r['post_id']] = (int)$r['c'];
    } catch (Exception $e) {}
    try {
        $st = $pdo->prepare("SELECT post_id, COUNT(*) c FROM channel_post_comments WHERE post_id IN ($in) GROUP BY post_id");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $comments[$r['post_id']] = (int)$r['c'];
    } catch (Exception $e) {}
    if ($userId) {
        try {
            $st = $pdo->prepare("SELECT post_id FROM channel_post_likes WHERE post_id IN ($in) AND user_id = ?");
            $st->execute(array_merge($ids, [$userId]));
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pid) $mine[$pid] = true;
        } catch (Exception $e) {}
    }
    foreach ($posts as &$p) {
        $p['likes_count'] = $likes[$p['id']] ?? 0;
        $p['views_count'] = $views[$p['id']] ?? 0;
        $p['comments_count'] = $comments[$p['id']] ?? 0;
        $p['liked_by_me'] = isset($mine[$p['id']]);
    }
    return $posts;
}

/**
 * Чей это канал.
 *
 * Раньше канал мог быть только у специалиста: столбец так и называется —
 * psychologist_id. По задаче №75 канал доступен всем, поэтому у психолога ключом
 * по-прежнему служит его psychologists.id (чтобы ничего не сломалось у тех, у кого
 * канал уже есть), а у остальных — собственный id пользователя. Столбец строковый,
 * оба вида значений в нём помещаются, переделывать таблицы не пришлось.
 *
 * Имя автора в ленте достаём с запасом обоими путями — см. запрос в action=feed.
 */
function myChannelId(PDO $pdo, $userId) {
    if (!$userId) return null;
    $psych = myPsychologistId($pdo, $userId);
    return $psych ?: (string)$userId;
}

// Вернуть psychologists.id текущего пользователя (по сессии), либо null
function myPsychologistId(PDO $pdo, $userId) {
    if (!$userId) return null;
    try {
        $st = $pdo->prepare("SELECT id FROM psychologists WHERE user_id = ? LIMIT 1");
        $st->execute([$userId]);
        $id = $st->fetchColumn();
        return $id ?: null;
    } catch (Exception $e) { return null; }
}

$action = $_GET['action'] ?? '';
$body = json_decode(file_get_contents('php://input'), true) ?: [];

try { ensureChannelTables($pdo); } catch (Exception $e) {}

if ($action === 'posts') {
    $psychId = (string)($_GET['psychologist_id'] ?? '');
    if ($psychId === '') { http_response_code(400); echo json_encode(['error' => 'psychologist_id обязателен']); exit; }
    try {
        // Скрытые админом посты автор видит у себя (со статусом), остальные — нет.
        $isOwner = ($psychId !== '' && myChannelId($pdo, $userId) === $psychId);
        $showHidden = $isOwner || isAdminUser($pdo, $userId);
        $hasHidden = hasColumn($pdo, 'channel_posts', 'is_hidden');
        $hiddenCond = ($hasHidden && !$showHidden) ? ' AND (is_hidden IS NULL OR is_hidden = 0)' : '';
        $cols = $hasHidden ? 'id, text, image_url, created_at, is_hidden, hidden_reason' : 'id, text, image_url, created_at';
        $st = $pdo->prepare("SELECT $cols FROM channel_posts WHERE psychologist_id = ?$hiddenCond ORDER BY created_at DESC, id DESC LIMIT 200");
        $st->execute([$psychId]);
        $rows = attachPostStats($pdo, $st->fetchAll(PDO::FETCH_ASSOC), $userId);
        echo json_encode(['ok' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Не удалось загрузить посты канала: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'feed') {
    $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
    $beforeId = (int)($_GET['before_id'] ?? 0);
    try {
        // ВАЖНО: именно LEFT JOIN. С INNER JOIN пост молча исчезал из общей ленты, если у автора
        // почему-то не находилась строка в psychologists/users (например профиль пересоздан) —
        // при этом в канале (action=posts, без джойнов) он оставался виден. Отсюда и был баг
        // «в чатах канал есть, а в общей ленте его нет». Имя автора подставляем с запасом.
        $where = [];
        if (hasColumn($pdo, 'channel_posts', 'is_hidden')) $where[] = '(cp.is_hidden IS NULL OR cp.is_hidden = 0)';
        $params = [];
        if ($beforeId > 0) { $where[] = 'cp.id < ?'; $params[] = $beforeId; }
        // Второй LEFT JOIN — на случай, когда канал ведёт не специалист: тогда в
        // psychologist_id лежит id самого пользователя (см. myChannelId), и строки в
        // psychologists для него нет. Имя берём тем путём, который сработал.
        $sql = "SELECT cp.id, cp.psychologist_id, cp.text, cp.image_url, cp.created_at,
                       COALESCE(u.first_name, u2.first_name) AS first_name,
                       COALESCE(u.last_name,  u2.last_name)  AS last_name,
                       COALESCE(u.avatar,     u2.avatar)     AS avatar,
                       p.specialization
                FROM channel_posts cp
                LEFT JOIN psychologists p ON p.id = cp.psychologist_id
                LEFT JOIN users u ON u.id = p.user_id
                LEFT JOIN users u2 ON u2.id = cp.psychologist_id
                " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
                ORDER BY cp.created_at DESC, cp.id DESC LIMIT $limit";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $rows = attachPostStats($pdo, $rows, $userId);
        // Подписки текущего пользователя — чтобы в ленте сразу показать кнопку "Отписаться"
        $subscribed = [];
        if ($userId) {
            try {
                $st2 = $pdo->prepare("SELECT psychologist_id FROM channel_subscriptions WHERE client_id = ?");
                $st2->execute([$userId]);
                $subscribed = array_flip($st2->fetchAll(PDO::FETCH_COLUMN));
            } catch (Exception $e) {}
        }
        foreach ($rows as &$r) { $r['is_subscribed'] = isset($subscribed[$r['psychologist_id']]); }
        echo json_encode(['ok' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Не удалось загрузить ленту: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'like' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }
    $postId = (int)($body['post_id'] ?? 0);
    if (!$postId) { http_response_code(400); echo json_encode(['error' => 'post_id обязателен']); exit; }
    try {
        $st = $pdo->prepare("SELECT id FROM channel_post_likes WHERE post_id = ? AND user_id = ?");
        $st->execute([$postId, $userId]);
        $liked = $st->fetchColumn();
        if ($liked) {
            $pdo->prepare("DELETE FROM channel_post_likes WHERE post_id = ? AND user_id = ?")->execute([$postId, $userId]);
        } else {
            $pdo->prepare("INSERT IGNORE INTO channel_post_likes (post_id, user_id, created_at) VALUES (?, ?, NOW())")->execute([$postId, $userId]);
        }
        $count = (int)$pdo->query("SELECT COUNT(*) FROM channel_post_likes WHERE post_id = " . (int)$postId)->fetchColumn();
        echo json_encode(['ok' => true, 'liked' => !$liked, 'likes_count' => $count]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось поставить лайк']); }
    exit;
}

if ($action === 'view' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $postId = (int)($body['post_id'] ?? 0);
    if (!$postId) { http_response_code(400); echo json_encode(['error' => 'post_id обязателен']); exit; }
    try {
        $pdo->prepare("INSERT IGNORE INTO channel_post_views (post_id, viewer_key, created_at) VALUES (?, ?, NOW())")->execute([$postId, viewerKey()]);
        $count = (int)$pdo->query("SELECT COUNT(*) FROM channel_post_views WHERE post_id = " . (int)$postId)->fetchColumn();
        echo json_encode(['ok' => true, 'views_count' => $count]);
    } catch (Exception $e) { echo json_encode(['ok' => true, 'views_count' => 0]); }
    exit;
}

if ($action === 'info') {
    $psychId = (string)($_GET['psychologist_id'] ?? '');
    if ($psychId === '') { http_response_code(400); echo json_encode(['error' => 'psychologist_id обязателен']); exit; }
    $subscribersCount = 0;
    $isSubscribed = false;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM channel_subscriptions WHERE psychologist_id = ?");
        $st->execute([$psychId]);
        $subscribersCount = (int)$st->fetchColumn();
    } catch (Exception $e) {}
    if ($userId) {
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM channel_subscriptions WHERE psychologist_id = ? AND client_id = ?");
            $st->execute([$psychId, $userId]);
            $isSubscribed = (int)$st->fetchColumn() > 0;
        } catch (Exception $e) {}
    }
    $settings = channelSettings($pdo, $psychId);
    echo json_encode(['ok' => true, 'subscribers_count' => $subscribersCount, 'is_subscribed' => $isSubscribed,
        'title' => $settings['title'], 'description' => $settings['description'], 'avatar_url' => $settings['avatar_url']],
        JSON_UNESCAPED_UNICODE);
    exit;
}

/** Оформление канала психолога (пустые поля — значит используется имя/фото из профиля). */
function channelSettings(PDO $pdo, $psychId) {
    $empty = ['title' => null, 'description' => null, 'avatar_url' => null];
    try {
        $st = $pdo->prepare("SELECT title, description, avatar_url FROM channel_settings WHERE psychologist_id = ?");
        $st->execute([$psychId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? array_merge($empty, $r) : $empty;
    } catch (Exception $e) { return $empty; }
}

/**
 * Оформление СВОЕГО канала: название, описание, аватарка. Только психолог, и только свой канал.
 * Пустая строка означает «убрать» (вернуться к имени/фото из профиля).
 */
/**
 * Удалить свой канал: посты, подписки и оформление. Сам профиль психолога остаётся —
 * канал можно будет начать заново (по просьбе админа: «добавь возможность удалить свой канал»).
 */
if ($action === 'delete-channel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }
    $psychId = myChannelId($pdo, $userId);
    if (!$psychId) { http_response_code(403); echo json_encode(['error' => 'Доступно только психологам с созданным профилем']); exit; }
    try {
        // комментарии и лайки чистим по постам канала, чтобы не оставлять сирот
        $ids = $pdo->prepare("SELECT id FROM channel_posts WHERE psychologist_id = ?");
        $ids->execute([$psychId]);
        $postIds = $ids->fetchAll(PDO::FETCH_COLUMN);
        foreach (['channel_post_comments', 'channel_post_likes', 'channel_post_views'] as $tbl) {
            foreach ($postIds as $pid) {
                try { $pdo->prepare("DELETE FROM $tbl WHERE post_id = ?")->execute([$pid]); } catch (Exception $e) {}
            }
        }
        $pdo->prepare("DELETE FROM channel_posts WHERE psychologist_id = ?")->execute([$psychId]);
        try { $pdo->prepare("DELETE FROM channel_subscriptions WHERE psychologist_id = ?")->execute([$psychId]); } catch (Exception $e) {}
        try { $pdo->prepare("DELETE FROM channel_settings WHERE psychologist_id = ?")->execute([$psychId]); } catch (Exception $e) {}
        echo json_encode(['ok' => true, 'deleted_posts' => count($postIds)], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Не удалось удалить канал: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'save-settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }
    $psychId = myChannelId($pdo, $userId);
    if (!$psychId) { http_response_code(403); echo json_encode(['error' => 'Доступно только психологам с созданным профилем']); exit; }
    $title = isset($body['title']) ? trim((string)$body['title']) : null;
    $descr = isset($body['description']) ? trim((string)$body['description']) : null;
    $avatar = isset($body['avatar_url']) ? trim((string)$body['avatar_url']) : null;
    // аватарка — только свой путь или https, чтобы нельзя было подставить чужой хост
    if ($avatar !== null && $avatar !== '' &&
        !preg_match('~^(/[A-Za-z0-9._/-]+|https://[A-Za-z0-9.-]+/[A-Za-z0-9._/%-]*)$~', $avatar)) {
        http_response_code(400); echo json_encode(['error' => 'Некорректная ссылка на аватарку']); exit;
    }
    $cut = function ($v, $n) {
        if ($v === null) return null;
        if ($v === '') return null;
        return function_exists('mb_substr') ? mb_substr($v, 0, $n, 'UTF-8') : substr($v, 0, $n);
    };
    try {
        $cur = channelSettings($pdo, $psychId);
        $newTitle  = ($title  === null) ? $cur['title']       : $cut($title, 255);
        $newDescr  = ($descr  === null) ? $cur['description'] : $cut($descr, 500);
        $newAvatar = ($avatar === null) ? $cur['avatar_url']  : $cut($avatar, 500);
        $st = $pdo->prepare("SELECT 1 FROM channel_settings WHERE psychologist_id = ?");
        $st->execute([$psychId]);
        if ($st->fetchColumn()) {
            $pdo->prepare("UPDATE channel_settings SET title = ?, description = ?, avatar_url = ?, updated_at = NOW() WHERE psychologist_id = ?")
                ->execute([$newTitle, $newDescr, $newAvatar, $psychId]);
        } else {
            $pdo->prepare("INSERT INTO channel_settings (psychologist_id, title, description, avatar_url, updated_at) VALUES (?, ?, ?, ?, NOW())")
                ->execute([$psychId, $newTitle, $newDescr, $newAvatar]);
        }
        echo json_encode(['ok' => true, 'title' => $newTitle, 'description' => $newDescr, 'avatar_url' => $newAvatar], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        http_response_code(500); echo json_encode(['error' => 'Не удалось сохранить оформление канала: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'my-subscriptions') {
    if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }
    try {
        $st = $pdo->prepare("
            SELECT p.id AS psychologist_id, u.id AS user_id, u.first_name, u.last_name, u.avatar,
                   cs.title AS channel_title, cs.description AS channel_description, cs.avatar_url AS channel_avatar,
                   (SELECT text FROM channel_posts cp WHERE cp.psychologist_id = p.id ORDER BY cp.created_at DESC, cp.id DESC LIMIT 1) AS last_post_text,
                   (SELECT created_at FROM channel_posts cp WHERE cp.psychologist_id = p.id ORDER BY cp.created_at DESC, cp.id DESC LIMIT 1) AS last_post_at
            FROM channel_subscriptions s
            JOIN psychologists p ON p.id = s.psychologist_id
            JOIN users u ON u.id = p.user_id
            LEFT JOIN channel_settings cs ON cs.psychologist_id = p.id
            WHERE s.client_id = ?
            ORDER BY s.created_at DESC
        ");
        $st->execute([$userId]);
        echo json_encode(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        // Раньше ошибка глушилась и подписки просто «исчезали» без следа — на такой
        // молчаливый catch я уже наступал с лентой и группами. Отдаём причину.
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Не удалось загрузить подписки: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'post' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }
    $psychId = myChannelId($pdo, $userId);
    if (!$psychId) { http_response_code(403); echo json_encode(['error' => 'Доступно только психологам с созданным профилем']); exit; }
    $text = isset($body['text']) ? trim((string)$body['text']) : '';
    $imageUrl = isset($body['image_url']) ? (string)$body['image_url'] : null;
    if ($text === '' && !$imageUrl) { http_response_code(400); echo json_encode(['error' => 'Пустой пост']); exit; }
    try {
        $st = $pdo->prepare("INSERT INTO channel_posts (psychologist_id, text, image_url, created_at) VALUES (?, ?, ?, NOW())");
        $st->execute([$psychId, $text !== '' ? $text : null, $imageUrl]);
        echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось опубликовать']); }
    exit;
}

if ($action === 'edit-post' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }
    $psychId = myChannelId($pdo, $userId);
    $id = (int)($body['id'] ?? 0);
    if (!$psychId || !$id) { http_response_code(400); echo json_encode(['error' => 'Некорректный запрос']); exit; }
    $text = isset($body['text']) ? trim((string)$body['text']) : '';
    if ($text === '') { http_response_code(400); echo json_encode(['error' => 'Текст не может быть пустым']); exit; }
    try {
        // image_url меняем, только если поле явно прислали (иначе картинка поста остаётся прежней)
        if (array_key_exists('image_url', $body)) {
            $st = $pdo->prepare("UPDATE channel_posts SET text = ?, image_url = ? WHERE id = ? AND psychologist_id = ?");
            $st->execute([$text, ((string)$body['image_url']) ?: null, $id, $psychId]);
        } else {
            $st = $pdo->prepare("UPDATE channel_posts SET text = ? WHERE id = ? AND psychologist_id = ?");
            $st->execute([$text, $id, $psychId]);
        }
        echo json_encode(['ok' => true]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось сохранить']); }
    exit;
}

if ($action === 'delete-post' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }
    $psychId = myChannelId($pdo, $userId);
    $id = (int)($body['id'] ?? 0);
    if (!$psychId || !$id) { http_response_code(400); echo json_encode(['error' => 'Некорректный запрос']); exit; }
    try {
        $st = $pdo->prepare("DELETE FROM channel_posts WHERE id = ? AND psychologist_id = ?");
        $st->execute([$id, $psychId]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось удалить']); }
    exit;
}

if ($action === 'subscribe' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }
    $psychId = (string)($body['psychologist_id'] ?? '');
    if ($psychId === '') { http_response_code(400); echo json_encode(['error' => 'psychologist_id обязателен']); exit; }
    try {
        $st = $pdo->prepare("INSERT IGNORE INTO channel_subscriptions (client_id, psychologist_id, created_at) VALUES (?, ?, NOW())");
        $st->execute([$userId, $psychId]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось подписаться']); }
    exit;
}

if ($action === 'unsubscribe' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }
    $psychId = (string)($body['psychologist_id'] ?? '');
    if ($psychId === '') { http_response_code(400); echo json_encode(['error' => 'psychologist_id обязателен']); exit; }
    try {
        $st = $pdo->prepare("DELETE FROM channel_subscriptions WHERE client_id = ? AND psychologist_id = ?");
        $st->execute([$userId, $psychId]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось отписаться']); }
    exit;
}

// ── Модерация постов администратором ──────────────────────────────────────────────
if ($action === 'admin-posts') {
    if (!isAdminUser($pdo, $userId)) { http_response_code(403); echo json_encode(['error' => 'Доступно только администратору']); exit; }
    $limit = max(1, min(500, (int)($_GET['limit'] ?? 200)));
    try {
        $hasHidden = hasColumn($pdo, 'channel_posts', 'is_hidden');
        $modCols = $hasHidden ? 'cp.is_hidden, cp.hidden_reason,' : '';
        $st = $pdo->prepare("SELECT cp.id, cp.psychologist_id, cp.text, cp.image_url, cp.created_at, $modCols
                    u.first_name, u.last_name, u.id AS author_user_id
                FROM channel_posts cp
                LEFT JOIN psychologists p ON p.id = cp.psychologist_id
                LEFT JOIN users u ON u.id = p.user_id
                ORDER BY cp.created_at DESC, cp.id DESC LIMIT $limit");
        $st->execute();
        $rows = attachPostStats($pdo, $st->fetchAll(PDO::FETCH_ASSOC), $userId);
        echo json_encode(['ok' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) { echo json_encode(['ok' => true, 'data' => []]); }
    exit;
}

if ($action === 'admin-set-hidden' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isAdminUser($pdo, $userId)) { http_response_code(403); echo json_encode(['error' => 'Доступно только администратору']); exit; }
    $id = (int)($body['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id обязателен']); exit; }
    $hidden = !empty($body['hidden']) ? 1 : 0;
    $reason = trim((string)($body['reason'] ?? ''));
    try {
        $st = $pdo->prepare("UPDATE channel_posts SET is_hidden = ?, hidden_reason = ? WHERE id = ?");
        $st->execute([$hidden, $hidden ? ($reason !== '' ? mb_substr($reason, 0, 500) : 'Скрыт администратором') : null, $id]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось изменить статус: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE); }
    exit;
}

if ($action === 'admin-delete-post' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isAdminUser($pdo, $userId)) { http_response_code(403); echo json_encode(['error' => 'Доступно только администратору']); exit; }
    $id = (int)($body['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id обязателен']); exit; }
    try {
        $pdo->prepare("DELETE FROM channel_posts WHERE id = ?")->execute([$id]);
        // подчищаем связанные метрики/комментарии, чтобы не оставлять мусор
        foreach (['channel_post_likes', 'channel_post_views', 'channel_post_comments'] as $t) {
            try { $pdo->prepare("DELETE FROM $t WHERE post_id = ?")->execute([$id]); } catch (Exception $e) {}
        }
        echo json_encode(['ok' => true]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось удалить: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE); }
    exit;
}

if ($action === 'comments') {
    $postId = (int)($_GET['post_id'] ?? 0);
    if (!$postId) { http_response_code(400); echo json_encode(['error' => 'post_id обязателен']); exit; }
    try {
        $st = $pdo->prepare("SELECT c.id, c.user_id, c.text, c.created_at, u.first_name, u.last_name, u.avatar
                              FROM channel_post_comments c JOIN users u ON u.id = c.user_id
                              WHERE c.post_id = ? ORDER BY c.created_at ASC, c.id ASC LIMIT 500");
        $st->execute([$postId]);
        echo json_encode(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { echo json_encode(['ok' => true, 'data' => []]); }
    exit;
}

if ($action === 'comment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }
    $postId = (int)($body['post_id'] ?? 0);
    $text = trim((string)($body['text'] ?? ''));
    if (!$postId || $text === '') { http_response_code(400); echo json_encode(['error' => 'post_id и text обязательны']); exit; }
    if (mb_strlen($text) > 2000) $text = mb_substr($text, 0, 2000);
    try {
        $st = $pdo->prepare("INSERT INTO channel_post_comments (post_id, user_id, text, created_at) VALUES (?, ?, ?, NOW())");
        $st->execute([$postId, $userId, $text]);
        echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось отправить комментарий']); }
    exit;
}

if ($action === 'delete-comment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }
    $id = (int)($body['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id обязателен']); exit; }
    $myPsychId = myPsychologistId($pdo, $userId);
    try {
        if ($myPsychId) {
            // Автор канала может удалить любой комментарий под СВОИМ постом (модерация)
            $st = $pdo->prepare("DELETE c FROM channel_post_comments c JOIN channel_posts p ON p.id = c.post_id
                                  WHERE c.id = ? AND (c.user_id = ? OR p.psychologist_id = ?)");
            $st->execute([$id, $userId, $myPsychId]);
        } else {
            $st = $pdo->prepare("DELETE FROM channel_post_comments WHERE id = ? AND user_id = ?");
            $st->execute([$id, $userId]);
        }
        echo json_encode(['ok' => true]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось удалить']); }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Неизвестное действие']);
