<?php
/**
 * post.php — отдельная страница поста психолога, оптимизированная под SEO.
 *
 * Зачем отдельной страницей, а не попапом: пост должен хорошо читаться в интернете
 * и попадать в поиск. Для этого нужны серверные мета-теги (title, description,
 * Open Graph, canonical, JSON-LD) — их поисковик и мессенджеры читают из HTML, а
 * попап и клиентский рендер этого не дают. Тело поста тоже рисуем на сервере.
 *
 * URL: /post.php?id=<id поста>.  Красивый путь /post/<id> тоже ведёт сюда — правило
 * есть в .user.ini/.htaccess при наличии; canonical в любом случае указывает на
 * /post.php?id=, чтобы не было дублей в индексе.
 */

require_once __DIR__ . '/api/config.php';
if (!function_exists('getDB') && !function_exists('getDbConnection') && !function_exists('getPDO')) {
    require_once __DIR__ . '/api/db.php';
}
$pdo = function_exists('getDB') ? getDB()
     : (function_exists('getDbConnection') ? getDbConnection()
     : (function_exists('getPDO') ? getPDO() : null));

$SITE = 'https://psytalk.pro';
$postId = (int)($_GET['id'] ?? 0);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
function attr($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** Голый текст из markdown-lite — для title/description и schema. */
function plainText($md, $limit = 0) {
    $t = (string)$md;
    $t = preg_replace('/\[([^\]]+)\]\([^)]+\)/u', '$1', $t);   // [текст](ссылка) -> текст
    $t = preg_replace('/[*_~`#>-]+/u', ' ', $t);              // маркеры разметки
    $t = preg_replace('/\s+/u', ' ', $t);
    $t = trim($t);
    if ($limit > 0 && mb_strlen($t) > $limit) $t = mb_substr($t, 0, $limit - 1) . '…';
    return $t;
}

/**
 * Компактный рендер markdown-lite в HTML (серверный аналог formatPostText).
 * Экранируем ВХОД целиком, затем расставляем теги — HTML автора не исполняется.
 */
function renderPost($md) {
    $lines = explode("\n", str_replace("\r", '', (string)$md));
    $html = '';
    $list = null;
    $closeList = function () use (&$html, &$list) { if ($list) { $html .= "</$list>"; $list = null; } };
    $inline = function ($s) {
        $s = htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // ссылки [текст](url) и голые http(s)
        $s = preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^\s)]+|\/[^\s)]*)\)/u',
            fn($m) => '<a href="' . attr($m[2]) . '" rel="noopener">' . $m[1] . '</a>', $s);
        $s = preg_replace('/(?<!["\'>=])(https?:\/\/[^\s<]+)/u', '<a href="$1" rel="noopener">$1</a>', $s);
        $s = preg_replace('/\*\*\s*(.+?)\s*\*\*/u', '<b>$1</b>', $s);
        $s = preg_replace('/~~\s*(.+?)\s*~~/u', '<s>$1</s>', $s);
        $s = preg_replace('/__\s*(.+?)\s*__/u', '<u>$1</u>', $s);
        $s = preg_replace('/(?<!\w)\*(?!\s)(.+?)(?<!\s)\*(?!\w)/u', '<i>$1</i>', $s);
        $s = preg_replace('/(?<!\w)_(?!\s)(.+?)(?<!\s)_(?!\w)/u', '<i>$1</i>', $s);
        return $s;
    };
    foreach ($lines as $line) {
        if (preg_match('/^\s*(#{2,3})\s+(.+)$/u', $line, $m)) {
            $closeList(); $tag = strlen($m[1]) === 2 ? 'h2' : 'h3';
            $html .= "<$tag>" . $inline($m[2]) . "</$tag>";
        } elseif (preg_match('/^\s*>\s?(.*)$/u', $line, $m)) {
            $closeList(); $html .= '<blockquote>' . $inline($m[1]) . '</blockquote>';
        } elseif (preg_match('/^\s*[-•]\s+(.+)$/u', $line, $m)) {
            if ($list !== 'ul') { $closeList(); $html .= '<ul>'; $list = 'ul'; }
            $html .= '<li>' . $inline($m[1]) . '</li>';
        } elseif (preg_match('/^\s*\d+[.)]\s+(.+)$/u', $line, $m)) {
            if ($list !== 'ol') { $closeList(); $html .= '<ol>'; $list = 'ol'; }
            $html .= '<li>' . $inline($m[1]) . '</li>';
        } elseif (trim($line) === '') {
            $closeList(); $html .= '';
        } else {
            $closeList(); $html .= '<p>' . $inline($line) . '</p>';
        }
    }
    $closeList();
    return $html;
}

$post = null; $author = null; $related = [];
if ($pdo && $postId > 0) {
    try {
        $st = $pdo->prepare("SELECT cp.id, cp.psychologist_id, cp.text, cp.image_url, cp.created_at,
                                    COALESCE(u.first_name, u2.first_name) AS first_name,
                                    COALESCE(u.last_name,  u2.last_name)  AS last_name,
                                    COALESCE(u.avatar,     u2.avatar)     AS avatar,
                                    p.specialization
                             FROM channel_posts cp
                        LEFT JOIN psychologists p ON p.id = cp.psychologist_id
                        LEFT JOIN users u ON u.id = p.user_id
                        LEFT JOIN users u2 ON u2.id = cp.psychologist_id
                            WHERE cp.id = ? LIMIT 1");
        $st->execute([$postId]);
        $post = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) { $post = null; }
    if ($post) {
        // Скрытый админом пост не отдаём в индекс.
        try {
            if (in_array('is_hidden', array_map(fn($c) => $c['Field'] ?? '', $pdo->query("SHOW COLUMNS FROM channel_posts")->fetchAll(PDO::FETCH_ASSOC)), true)) {
                $chk = $pdo->prepare("SELECT is_hidden FROM channel_posts WHERE id = ?");
                $chk->execute([$postId]);
                if ((int)$chk->fetchColumn() === 1) $post = null;
            }
        } catch (Exception $e) {}
    }
    if ($post) {
        try {
            $rs = $pdo->prepare("SELECT id, text, image_url, created_at FROM channel_posts
                                  WHERE psychologist_id = ? AND id <> ?
                                  ORDER BY created_at DESC, id DESC LIMIT 4");
            $rs->execute([$post['psychologist_id'], $postId]);
            $related = $rs->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) { $related = []; }
    }
}

if (!$post) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="robots" content="noindex">'
       . '<title>Пост не найден — psytalk.pro</title><link rel="stylesheet" href="/css/styles.css"></head>'
       . '<body style="font-family:Inter,sans-serif;padding:4rem 1rem;text-align:center;">'
       . '<h1>Пост не найден</h1><p>Возможно, его удалили.</p>'
       . '<p><a href="/feed.html" style="color:#047857;font-weight:600;">← В ленту</a></p></body></html>';
    exit;
}

$authorName = trim(($post['first_name'] ?? '') . ' ' . ($post['last_name'] ?? ''));
if ($authorName === '') $authorName = 'Психолог';
$psyId    = (string)$post['psychologist_id'];
$plain    = plainText($post['text']);
$title    = ($plain !== '' ? plainText($post['text'], 65) : ('Пост — ' . $authorName)) . ' — psytalk.pro';
$descr    = $plain !== '' ? plainText($post['text'], 160) : ('Пост психолога ' . $authorName . ' на psytalk.pro');
$canon    = $SITE . '/post.php?id=' . $postId;
$imageAbs = '';
if (!empty($post['image_url'])) {
    $imageAbs = (strpos($post['image_url'], 'http') === 0) ? $post['image_url'] : ($SITE . $post['image_url']);
}
$ogImage  = $imageAbs ?: ($SITE . '/assets/quiz-hero.jpg');
$pubIso   = !empty($post['created_at']) ? date('c', strtotime($post['created_at'])) : date('c');
$tags     = array_values(array_filter(array_map('trim', preg_split('/[,;]+/', (string)($post['specialization'] ?? '')))));

$ld = [
    '@context' => 'https://schema.org', '@type' => 'Article',
    'headline' => plainText($post['text'], 110) ?: ('Пост ' . $authorName),
    'articleBody' => $plain,
    'datePublished' => $pubIso,
    'author' => ['@type' => 'Person', 'name' => $authorName],
    'publisher' => ['@type' => 'Organization', 'name' => 'psytalk.pro',
                    'logo' => ['@type' => 'ImageObject', 'url' => $SITE . '/assets/icon-512.png']],
    'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canon],
    'url' => $canon,
];
if ($imageAbs) $ld['image'] = $imageAbs;
if ($tags) $ld['keywords'] = implode(', ', $tags);

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($title) ?></title>
<meta name="description" content="<?= attr($descr) ?>">
<link rel="canonical" href="<?= attr($canon) ?>">
<meta property="og:type" content="article">
<meta property="og:site_name" content="psytalk.pro">
<meta property="og:title" content="<?= attr($title) ?>">
<meta property="og:description" content="<?= attr($descr) ?>">
<meta property="og:url" content="<?= attr($canon) ?>">
<meta property="og:image" content="<?= attr($ogImage) ?>">
<meta property="article:published_time" content="<?= attr($pubIso) ?>">
<?php foreach ($tags as $tg): ?><meta property="article:tag" content="<?= attr($tg) ?>">
<?php endforeach; ?>
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= attr($title) ?>">
<meta name="twitter:description" content="<?= attr($descr) ?>">
<meta name="twitter:image" content="<?= attr($ogImage) ?>">
<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="/css/styles.css?v=20260823d">
<script src="/js/netguard.js?v=20260821c"></script>
<script src="/js/layout.js?v=20260823d"></script>
<script src="/js/lightbox.js?v=7"></script>
<style>
    *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
    :root { --pg-bg:#FAFAFA; --pg-text:#1A1A1A; --pg-sec:#6B7280; --pg-card:#fff; --pg-border:#EFF7F3; --pg-accent:#047857; }
    body.dark { --pg-bg:#191919; --pg-text:#E6E6E6; --pg-sec:#A0A0A0; --pg-card:#252525; --pg-border:#333; --pg-accent:#6EE7B7; }
    body { font-family:'Inter',-apple-system,sans-serif; background:var(--pg-bg); color:var(--pg-text); padding-top:84px; min-height:100vh; }
    .pg-wrap { max-width:720px; margin:0 auto; padding:1.5rem 1.25rem 4rem; }
    .pg-back { display:inline-block; color:var(--pg-sec); text-decoration:none; font-size:0.88rem; margin-bottom:1rem; }
    .pg-back:hover { color:var(--pg-accent); }
    .pg-card { background:var(--pg-card); border:1px solid var(--pg-border); border-radius:1.1rem; padding:1.5rem 1.6rem; box-shadow:0 2px 14px rgba(0,0,0,0.05); }
    .pg-head { display:flex; align-items:center; gap:0.8rem; margin-bottom:0.4rem; }
    .pg-ava { width:52px; height:52px; border-radius:50%; overflow:hidden; flex-shrink:0; background:linear-gradient(135deg,#047857,#065F46); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:1.3rem; }
    .pg-ava img { width:100%; height:100%; object-fit:cover; }
    .pg-author { font-weight:700; font-size:1.05rem; }
    .pg-author a { color:inherit; text-decoration:none; }
    .pg-meta { color:var(--pg-sec); font-size:0.82rem; }
    .pg-body { font-size:1.08rem; line-height:1.7; margin-top:1rem; word-wrap:break-word; }
    .pg-body p { margin:0.7rem 0; }
    .pg-body h2 { font-size:1.3rem; margin:1.1rem 0 0.5rem; }
    .pg-body h3 { font-size:1.12rem; margin:1rem 0 0.4rem; }
    .pg-body ul, .pg-body ol { margin:0.6rem 0 0.6rem 1.4rem; }
    .pg-body li { margin:0.25rem 0; }
    .pg-body blockquote { margin:0.8rem 0; padding:0.5rem 1rem; border-left:3px solid var(--pg-accent); color:var(--pg-sec); }
    .pg-body a { color:var(--pg-accent); }
    .pg-img { width:100%; border-radius:0.9rem; margin-top:1.1rem; cursor:zoom-in; }
    .pg-tags { display:flex; flex-wrap:wrap; gap:0.4rem; margin-top:1.2rem; }
    .pg-tag { background:rgba(4,120,87,0.1); color:var(--pg-accent); padding:0.25rem 0.7rem; border-radius:1rem; font-size:0.8rem; font-weight:600; }
    .pg-cta { display:flex; flex-wrap:wrap; gap:0.6rem; margin-top:1.5rem; }
    .pg-btn { display:inline-flex; align-items:center; gap:0.4rem; padding:0.7rem 1.3rem; border-radius:0.7rem; font-weight:700; font-size:0.95rem; text-decoration:none; cursor:pointer; border:none; font-family:inherit; }
    .pg-btn-primary { background:#047857; color:#fff; }
    .pg-btn-ghost { background:transparent; border:1.5px solid var(--pg-border); color:var(--pg-text); }
    .pg-rel { margin-top:2.5rem; }
    .pg-rel h2 { font-size:1.2rem; margin-bottom:0.9rem; }
    .pg-rel-item { display:flex; gap:0.8rem; padding:0.8rem; border:1px solid var(--pg-border); border-radius:0.8rem; margin-bottom:0.6rem; text-decoration:none; color:inherit; background:var(--pg-card); }
    .pg-rel-item:hover { border-color:var(--pg-accent); }
    .pg-rel-thumb { width:70px; height:70px; border-radius:0.6rem; object-fit:cover; flex-shrink:0; background:#eee; }
    .pg-rel-text { font-size:0.9rem; line-height:1.45; color:var(--pg-text); display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden; }
    @media (max-width:600px){ .pg-card{ padding:1.1rem 1.1rem; } .pg-body{ font-size:1.02rem; } }
</style>
</head>
<body>
<script>psyWriteHeader && psyWriteHeader();</script>
<main class="pg-wrap">
    <a class="pg-back" href="/feed.html">← Все посты в ленте</a>
    <article class="pg-card">
        <div class="pg-head">
            <a class="pg-ava" href="/psychologist-profile.html?id=<?= attr($psyId) ?>" aria-label="<?= attr($authorName) ?>">
                <?php if (!empty($post['avatar'])): ?><img src="<?= attr($post['avatar']) ?>" alt="<?= attr($authorName) ?>"><?php else: ?><?= h(mb_substr($authorName, 0, 1)) ?><?php endif; ?>
            </a>
            <div>
                <div class="pg-author"><a href="/psychologist-profile.html?id=<?= attr($psyId) ?>"><?= h($authorName) ?></a></div>
                <div class="pg-meta"><?php if (!empty($post['specialization'])): ?><?= h($post['specialization']) ?> · <?php endif; ?><?= h(date('d.m.Y', strtotime($post['created_at']))) ?></div>
            </div>
        </div>

        <div class="pg-body"><?= renderPost($post['text']) ?></div>
        <?php if ($imageAbs): ?><img class="pg-img" src="<?= attr($post['image_url']) ?>" alt="<?= attr(plainText($post['text'], 80)) ?>" data-zoom><?php endif; ?>

        <?php if ($tags): ?>
        <div class="pg-tags"><?php foreach ($tags as $tg): ?><span class="pg-tag">#<?= h($tg) ?></span><?php endforeach; ?></div>
        <?php endif; ?>

        <div class="pg-cta">
            <a class="pg-btn pg-btn-primary" href="/book.html?id=<?= attr($psyId) ?>">📅 Записаться к психологу</a>
            <a class="pg-btn pg-btn-ghost" href="/chat.html?open=channel:<?= attr(rawurlencode($psyId)) ?>">🔔 Подписаться на канал</a>
        </div>
    </article>

    <?php if ($related): ?>
    <section class="pg-rel">
        <h2>Ещё посты автора</h2>
        <?php foreach ($related as $r): ?>
        <a class="pg-rel-item" href="/post.php?id=<?= (int)$r['id'] ?>">
            <?php if (!empty($r['image_url'])): ?><img class="pg-rel-thumb" src="<?= attr($r['image_url']) ?>" alt="" loading="lazy"><?php endif; ?>
            <div class="pg-rel-text"><?= h(plainText($r['text'], 160)) ?></div>
        </a>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>
</main>
<script>window.psyWriteFooter && psyWriteFooter();</script>
</body>
</html>
