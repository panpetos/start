<?php
/**
 * blog.php — блоги психологов: психолог сам пишет статью и публикует её сразу в общий блог
 * (/blog.html), админ модерирует (снять с публикации / вернуть / удалить).
 *
 * Текст статьи хранится НЕ как HTML, а как markdown-лайт разметка (см. js/post-format.js) —
 * тот же инвариант, что и у постов канала: на выходе всё экранируется и только потом
 * превращается в теги, поэтому чужой HTML/скрипт в статью не попадёт.
 *
 * Публично (без авторизации):
 *   GET  ?action=list&limit=&tag=            — опубликованные статьи (карточки для blog.html)
 *   GET  ?action=get&slug=X                  — одна статья (черновик виден только автору и админу)
 *   GET  ?action=tags                        — список тегов с количеством
 * Психолог (автор):
 *   GET  ?action=mine                        — все свои статьи с их статусами
 *   POST ?action=save   {id?, title, excerpt, body, cover_url, tag, status:'draft'|'published'}
 *   POST ?action=delete {id}
 * Админ:
 *   GET  ?action=admin-list                  — все статьи всех авторов
 *   POST ?action=admin-set-status {id, status:'published'|'hidden'|'draft', reason?}
 *   POST ?action=admin-delete {id}
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

function out($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$tablesError = null;
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS blog_articles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(200) NOT NULL,
        author_user_id VARCHAR(64) NOT NULL,
        psychologist_id VARCHAR(64) NULL,
        title VARCHAR(300) NOT NULL,
        excerpt VARCHAR(600) NULL,
        body MEDIUMTEXT NOT NULL,
        cover_url VARCHAR(500) NULL,
        tag VARCHAR(60) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'draft',
        moderation_note VARCHAR(500) NULL,
        views_count INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        published_at DATETIME NULL,
        UNIQUE KEY uniq_slug (slug),
        INDEX idx_author (author_user_id),
        INDEX idx_status (status)
    ) DEFAULT CHARSET=utf8mb4");
    psy_align_collation($pdo, ['blog_articles']);
} catch (Exception $e) { $tablesError = $e->getMessage(); }

function userRole(PDO $pdo, $userId) {
    if (!$userId) return null;
    try {
        $st = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        $st->execute([$userId]);
        return (string)$st->fetchColumn();
    } catch (Exception $e) { return null; }
}
function myPsychId(PDO $pdo, $userId) {
    if (!$userId) return null;
    try {
        $st = $pdo->prepare("SELECT id FROM psychologists WHERE user_id = ? LIMIT 1");
        $st->execute([$userId]);
        return $st->fetchColumn() ?: null;
    } catch (Exception $e) { return null; }
}

/** Транслитерация заголовка в slug для читаемого адреса статьи. */
function makeSlug($title) {
    $map = ['а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y',
        'к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f',
        'х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya'];
    $s = mb_strtolower(trim((string)$title));
    $s = strtr($s, $map);
    $s = preg_replace('/[^a-z0-9]+/u', '-', $s);
    $s = trim((string)$s, '-');
    if ($s === '') $s = 'article';
    return mb_substr($s, 0, 120);
}
function uniqueSlug(PDO $pdo, $title, $exceptId = 0) {
    $base = makeSlug($title);
    $slug = $base;
    for ($i = 2; $i < 200; $i++) {
        try {
            $st = $pdo->prepare("SELECT id FROM blog_articles WHERE slug = ? AND id <> ? LIMIT 1");
            $st->execute([$slug, (int)$exceptId]);
            if (!$st->fetchColumn()) return $slug;
        } catch (Exception $e) { return $slug; }
        $slug = $base . '-' . $i;
    }
    return $base . '-' . substr(md5((string)microtime(true)), 0, 6);
}

/** Автоматическая выжимка из текста, если автор не заполнил её сам. */
function autoExcerpt($body) {
    $plain = preg_replace('/[#*_>\-]+/u', ' ', (string)$body);
    $plain = preg_replace('/\s+/u', ' ', $plain);
    return mb_substr(trim((string)$plain), 0, 220);
}
/** Оценка времени чтения — как «7 мин» в существующих карточках блога. */
function readTime($body) {
    $words = preg_split('/\s+/u', trim(strip_tags((string)$body))) ?: [];
    $min = max(1, (int)ceil(count($words) / 170));
    return $min . ' мин';
}

$action = $_GET['action'] ?? '';
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];
$role = userRole($pdo, $userId);
$isAdmin = ($role === 'admin');

// ── Публичное чтение ──────────────────────────────────────────────────────────────
if ($action === 'list') {
    if ($tablesError) out(['ok' => true, 'data' => []]);
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
    $tag = trim((string)($_GET['tag'] ?? ''));
    try {
        $sql = "SELECT a.id, a.slug, a.title, a.excerpt, a.cover_url, a.tag, a.views_count, a.published_at, a.created_at,
                       a.author_user_id, a.psychologist_id, u.first_name, u.last_name, u.avatar
                FROM blog_articles a
                LEFT JOIN users u ON u.id = a.author_user_id
                WHERE a.status = 'published'" . ($tag !== '' ? " AND a.tag = ?" : "") . "
                ORDER BY COALESCE(a.published_at, a.created_at) DESC, a.id DESC LIMIT $limit";
        $st = $pdo->prepare($sql);
        $st->execute($tag !== '' ? [$tag] : []);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        out(['ok' => true, 'data' => $rows]);
    } catch (Exception $e) { out(['ok' => true, 'data' => []]); }
}

if ($action === 'tags') {
    if ($tablesError) out(['ok' => true, 'data' => []]);
    try {
        $st = $pdo->query("SELECT tag, COUNT(*) AS cnt FROM blog_articles WHERE status='published' AND tag IS NOT NULL AND tag <> '' GROUP BY tag ORDER BY cnt DESC");
        out(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { out(['ok' => true, 'data' => []]); }
}

if ($action === 'get') {
    $slug = trim((string)($_GET['slug'] ?? ''));
    $id = (int)($_GET['id'] ?? 0);
    if ($slug === '' && !$id) out(['error' => 'Нужен slug или id'], 400);
    try {
        $st = $pdo->prepare("SELECT a.*, u.first_name, u.last_name, u.avatar
                              FROM blog_articles a LEFT JOIN users u ON u.id = a.author_user_id
                              WHERE " . ($slug !== '' ? "a.slug = ?" : "a.id = ?") . " LIMIT 1");
        $st->execute([$slug !== '' ? $slug : $id]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $a = null; }
    if (!$a) out(['error' => 'Статья не найдена'], 404);
    $mine = ($userId && (string)$a['author_user_id'] === (string)$userId);
    if ($a['status'] !== 'published' && !$mine && !$isAdmin) out(['error' => 'Статья не опубликована'], 404);
    if ($a['status'] === 'published') {
        try { $pdo->prepare("UPDATE blog_articles SET views_count = views_count + 1 WHERE id = ?")->execute([$a['id']]); } catch (Exception $e) {}
    }
    $a['read_time'] = readTime($a['body']);
    $a['can_edit'] = $mine || $isAdmin;
    out(['ok' => true, 'data' => $a]);
}

// ── Автор (психолог) ──────────────────────────────────────────────────────────────
if ($action === 'mine') {
    if (!$userId) out(['error' => 'Требуется авторизация'], 401);
    if ($tablesError) out(['error' => 'Блог недоступен: не удалось подготовить таблицу (' . $tablesError . ')'], 500);
    try {
        $st = $pdo->prepare("SELECT id, slug, title, excerpt, cover_url, tag, status, moderation_note, views_count, created_at, updated_at, published_at
                              FROM blog_articles WHERE author_user_id = ? ORDER BY updated_at DESC, id DESC LIMIT 300");
        $st->execute([$userId]);
        out(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { out(['error' => 'Не удалось загрузить статьи: ' . $e->getMessage()], 500); }
}

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$userId) out(['error' => 'Требуется авторизация'], 401);
    if ($tablesError) out(['error' => 'Блог недоступен: не удалось подготовить таблицу (' . $tablesError . ')'], 500);
    if ($role !== 'psychologist' && !$isAdmin) out(['error' => 'Писать статьи в блог могут психологи'], 403);

    $id = (int)($body['id'] ?? 0);
    $title = trim((string)($body['title'] ?? ''));
    $text = trim((string)($body['body'] ?? ''));
    if ($title === '') out(['error' => 'Введите заголовок статьи'], 400);
    if ($text === '') out(['error' => 'Текст статьи не может быть пустым'], 400);
    $status = (string)($body['status'] ?? 'draft');
    if (!in_array($status, ['draft', 'published'], true)) $status = 'draft';
    $excerpt = trim((string)($body['excerpt'] ?? ''));
    if ($excerpt === '') $excerpt = autoExcerpt($text);
    $cover = trim((string)($body['cover_url'] ?? ''));
    $tag = trim((string)($body['tag'] ?? ''));

    try {
        if ($id) {
            $st = $pdo->prepare("SELECT author_user_id, status, slug FROM blog_articles WHERE id = ? LIMIT 1");
            $st->execute([$id]);
            $cur = $st->fetch(PDO::FETCH_ASSOC);
            if (!$cur) out(['error' => 'Статья не найдена'], 404);
            if ((string)$cur['author_user_id'] !== (string)$userId && !$isAdmin) out(['error' => 'Это не ваша статья'], 403);
            // Снятую админом с публикации статью автор не может вернуть сам — только доработать как черновик.
            if ($cur['status'] === 'hidden' && !$isAdmin) $status = 'hidden';
            $slug = $cur['slug'];
            if ($title !== '' && $status !== 'hidden') $slug = uniqueSlug($pdo, $title, $id);
            $pdo->prepare("UPDATE blog_articles SET slug=?, title=?, excerpt=?, body=?, cover_url=?, tag=?, status=?,
                            updated_at=NOW(), published_at = CASE WHEN ?='published' AND published_at IS NULL THEN NOW() ELSE published_at END
                            WHERE id=?")
                ->execute([$slug, mb_substr($title, 0, 300), mb_substr($excerpt, 0, 600), $text,
                           $cover !== '' ? $cover : null, $tag !== '' ? mb_substr($tag, 0, 60) : null, $status, $status, $id]);
            out(['ok' => true, 'id' => $id, 'slug' => $slug, 'status' => $status]);
        }
        $slug = uniqueSlug($pdo, $title);
        $pdo->prepare("INSERT INTO blog_articles (slug, author_user_id, psychologist_id, title, excerpt, body, cover_url, tag, status, created_at, updated_at, published_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), " . ($status === 'published' ? 'NOW()' : 'NULL') . ")")
            ->execute([$slug, $userId, myPsychId($pdo, $userId), mb_substr($title, 0, 300), mb_substr($excerpt, 0, 600), $text,
                       $cover !== '' ? $cover : null, $tag !== '' ? mb_substr($tag, 0, 60) : null, $status]);
        out(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'slug' => $slug, 'status' => $status]);
    } catch (Exception $e) { out(['error' => 'Не удалось сохранить: ' . $e->getMessage()], 500); }
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$userId) out(['error' => 'Требуется авторизация'], 401);
    $id = (int)($body['id'] ?? 0);
    if (!$id) out(['error' => 'id обязателен'], 400);
    try {
        $st = $pdo->prepare("SELECT author_user_id FROM blog_articles WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $owner = $st->fetchColumn();
        if ($owner === false) out(['error' => 'Статья не найдена'], 404);
        if ((string)$owner !== (string)$userId && !$isAdmin) out(['error' => 'Это не ваша статья'], 403);
        $pdo->prepare("DELETE FROM blog_articles WHERE id = ?")->execute([$id]);
        out(['ok' => true]);
    } catch (Exception $e) { out(['error' => 'Не удалось удалить: ' . $e->getMessage()], 500); }
}

// ── Админ-модерация ───────────────────────────────────────────────────────────────
if ($action === 'admin-list') {
    if (!$isAdmin) out(['error' => 'Доступно только администратору'], 403);
    if ($tablesError) out(['ok' => true, 'data' => []]);
    try {
        $st = $pdo->query("SELECT a.id, a.slug, a.title, a.excerpt, a.tag, a.status, a.moderation_note, a.views_count,
                    a.created_at, a.updated_at, a.published_at, a.author_user_id, u.first_name, u.last_name, u.role AS author_role
                FROM blog_articles a LEFT JOIN users u ON u.id = a.author_user_id
                ORDER BY a.updated_at DESC, a.id DESC LIMIT 500");
        out(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { out(['ok' => true, 'data' => []]); }
}

if ($action === 'admin-set-status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) out(['error' => 'Доступно только администратору'], 403);
    $id = (int)($body['id'] ?? 0);
    $status = (string)($body['status'] ?? '');
    if (!$id || !in_array($status, ['published', 'hidden', 'draft'], true)) out(['error' => 'Некорректный запрос'], 400);
    $note = trim((string)($body['reason'] ?? ''));
    try {
        $pdo->prepare("UPDATE blog_articles SET status = ?, moderation_note = ?, updated_at = NOW(),
                        published_at = CASE WHEN ?='published' AND published_at IS NULL THEN NOW() ELSE published_at END
                        WHERE id = ?")
            ->execute([$status, $note !== '' ? mb_substr($note, 0, 500) : null, $status, $id]);
        out(['ok' => true]);
    } catch (Exception $e) { out(['error' => 'Не удалось изменить статус: ' . $e->getMessage()], 500); }
}

if ($action === 'admin-delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) out(['error' => 'Доступно только администратору'], 403);
    $id = (int)($body['id'] ?? 0);
    if (!$id) out(['error' => 'id обязателен'], 400);
    try {
        $pdo->prepare("DELETE FROM blog_articles WHERE id = ?")->execute([$id]);
        out(['ok' => true]);
    } catch (Exception $e) { out(['error' => 'Не удалось удалить: ' . $e->getMessage()], 500); }
}

out(['error' => 'Неизвестное действие'], 400);
