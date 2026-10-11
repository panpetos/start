<?php
/**
 * chat_media.php — всё вложенное в переписку одним списком: медиа, файлы, ссылки, голосовые.
 *
 * ЗАЧЕМ. Найти присланный месяц назад документ можно было только одним способом —
 * прокручивая переписку руками. В карточке чата и группы для этого есть место, но
 * данных для него не было: сообщения приходят порциями по 50, и «все файлы диалога»
 * из них не собрать.
 *
 * ЧТО ОТДАЁТ
 *   GET ?peer=<id собеседника>&kind=media|files|links|voice[&limit=&offset=]
 *   GET ?group_id=<номер группы>&kind=…
 *   → { ok, data: [ {id, url, type, name, text, created_at, sender} ], has_more }
 *
 * Личные сообщения лежат в messages (sender_id/receiver_id), групповые — в
 * chat_group_messages. Отдаём только то, что человек и так видит: свою переписку
 * или группу, в которой он состоит.
 *
 * Набор колонок у таблицы messages в разных установках разный, поэтому список
 * спрашиваем целиком через SHOW COLUMNS без подстановок: prepare с подстановкой в
 * SHOW на этом хостинге падает (emulate_prepares=false), а заглушенная ошибка уже
 * однажды стоила нам молча пропавших вложений.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');

require_once __DIR__ . '/config.php';
if (!function_exists('getDB') && !function_exists('getDbConnection') && !function_exists('getPDO')) {
    require_once __DIR__ . '/db.php';
}
$pdo = function_exists('getDB') ? getDB()
     : (function_exists('getDbConnection') ? getDbConnection()
     : (function_exists('getPDO') ? getPDO() : null));
if (!$pdo) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Нет подключения к БД']); exit; }

if (session_status() === PHP_SESSION_NONE) session_start();
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Требуется авторизация']); exit; }

function cmOut($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

/** Колонки таблицы — один раз на запрос, без подстановок в SHOW. */
function cmColumns(PDO $pdo, $table) {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    $cols = [];
    try {
        foreach ($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $name = $r['Field'] ?? ($r['field'] ?? null);
            if ($name !== null) $cols[] = (string)$name;
        }
    } catch (Exception $e) { $cols = []; }
    $cache[$table] = $cols;
    return $cols;
}

/** Картинка или видео — по типу вложения, а у старых сообщений по расширению. */
function cmIsMedia($type, $url) {
    $t = strtolower((string)$type);
    if ($t === 'image' || $t === 'video' || strpos($t, 'image/') === 0 || strpos($t, 'video/') === 0) return true;
    return (bool)preg_match('~\.(jpe?g|png|gif|webp|heic|bmp|mp4|mov|m4v|webm|ogv|3gp|mkv)(\?|$)~i', (string)$url);
}

function cmIsVoice($type, $url, $text) {
    $t = strtolower((string)$type);
    if ($t === 'voice' || $t === 'video_note' || strpos($t, 'audio/') === 0) return true;
    if (preg_match('~\.(mp3|m4a|wav|ogg|opus)(\?|$)~i', (string)$url)) return true;
    // Кружок распознаём так же, как чат: подпись «Видеосообщение» рядом с видеофайлом.
    return preg_match('~\.(webm|mp4|mov)(\?|$)~i', (string)$url) && preg_match('~Видеосообщение~u', (string)$text);
}

/** Ссылки из текста сообщения. */
function cmLinks($text) {
    $out = [];
    if (preg_match_all('~https?://[^\s<>"\']+~u', (string)$text, $m)) {
        foreach ($m[0] as $u) $out[] = rtrim($u, '.,;:)]}');
    }
    return $out;
}

$kind = (string)($_GET['kind'] ?? 'media');
if (!in_array($kind, ['media', 'files', 'links', 'voice'], true)) $kind = 'media';
$limit = max(10, min(200, (int)($_GET['limit'] ?? 60)));
$offset = max(0, (int)($_GET['offset'] ?? 0));
$peer = trim((string)($_GET['peer'] ?? ''));
$groupId = (int)($_GET['group_id'] ?? 0);
$favorites = !empty($_GET['favorites']);

// Сколько строк переписки просматриваем. Ссылки и файлы ищем по тексту и вложениям,
// поэтому берём с запасом и отбираем уже в PHP: условия по LIKE на большой таблице
// этому хостингу дороже, чем прогнать пару тысяч строк здесь.
$SCAN = 3000;

$rows = [];
try {
    if ($favorites) {
        // «Избранное» — это заметки самого человека: чужого здесь быть не может,
        // выбираем строго по своему user_id.
        $st = $pdo->prepare("SELECT id, content, created_at, attachment_url, attachment_type, attachment_name
                               FROM favorite_messages WHERE user_id = ? ORDER BY id DESC LIMIT $SCAN");
        $st->execute([$userId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } elseif ($groupId > 0) {
        $st = $pdo->prepare("SELECT role FROM chat_group_members WHERE group_id = ? AND user_id = ? LIMIT 1");
        $st->execute([$groupId, $userId]);
        if (!$st->fetchColumn()) cmOut(['ok' => false, 'error' => 'Вы не участник этой группы'], 403);
        $st = $pdo->prepare("SELECT m.id, m.content, m.created_at, m.attachment_url, m.attachment_type,
                                    m.attachment_name, u.first_name, u.last_name
                               FROM chat_group_messages m LEFT JOIN users u ON u.id = m.sender_id
                              WHERE m.group_id = ? ORDER BY m.id DESC LIMIT $SCAN");
        $st->execute([$groupId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } elseif ($peer !== '') {
        $cols = cmColumns($pdo, 'messages');
        $has = function ($c) use ($cols) { return in_array($c, $cols, true); };
        if (!$has('attachment_url')) {
            // Вложений в этой установке нет вовсе — честно отдаём пустой список,
            // а не падаем: карточка чата должна открыться в любом случае.
            cmOut(['ok' => true, 'data' => [], 'has_more' => false]);
        }
        // Обращения в поддержку из списка чатов уходят служебному получателю 'support',
        // а не конкретному администратору. Админу показываем и их — иначе вложения
        // такой переписки в карточке не нашлись бы (см. пояснение в messages_page.php).
        $isAdmin = false;
        try {
            $q = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
            $q->execute([$userId]);
            $isAdmin = ((string)$q->fetchColumn() === 'admin');
        } catch (Exception $e) {}
        $where = "((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))";
        $args = [$userId, $peer, $peer, $userId];
        if ($isAdmin && $peer !== 'support') {
            $where = "(" . $where . " OR (m.sender_id = ? AND m.receiver_id = 'support')"
                           . " OR (m.sender_id = 'support' AND m.receiver_id = ?))";
            array_push($args, $peer, $peer);
        }
        $st = $pdo->prepare("SELECT m.id, m.content, m.created_at, m.attachment_url, m.attachment_type,
                                    m.attachment_name, u.first_name, u.last_name
                               FROM messages m LEFT JOIN users u ON u.id = m.sender_id
                              WHERE $where
                              ORDER BY m.created_at DESC, m.id DESC LIMIT $SCAN");
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        cmOut(['ok' => false, 'error' => 'Не указан чат'], 400);
    }
} catch (Exception $e) {
    cmOut(['ok' => true, 'data' => [], 'has_more' => false, 'note' => 'не удалось прочитать переписку']);
}

$items = [];
foreach ($rows as $r) {
    $url = (string)($r['attachment_url'] ?? '');
    $type = (string)($r['attachment_type'] ?? '');
    $text = (string)($r['content'] ?? '');
    $who = trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? ''));
    $base = [
        'id' => (string)($r['id'] ?? ''),
        'created_at' => (string)($r['created_at'] ?? ''),
        'sender' => $who,
    ];
    if ($kind === 'links') {
        foreach (cmLinks($text) as $u) {
            $items[] = $base + ['url' => $u, 'type' => 'link', 'name' => $u, 'text' => mb_substr($text, 0, 200)];
        }
        continue;
    }
    if ($url === '') continue;
    $isVoice = cmIsVoice($type, $url, $text);
    $isMedia = !$isVoice && cmIsMedia($type, $url);
    $ok = ($kind === 'voice' && $isVoice)
       || ($kind === 'media' && $isMedia)
       || ($kind === 'files' && !$isVoice && !$isMedia);
    if (!$ok) continue;
    $items[] = $base + [
        'url' => $url,
        'type' => $type !== '' ? $type : ($isMedia ? 'media' : 'file'),
        'name' => (string)($r['attachment_name'] ?? ''),
        'text' => mb_substr($text, 0, 200),
    ];
}

$total = count($items);
$page = array_slice($items, $offset, $limit);
cmOut(['ok' => true, 'data' => array_values($page), 'has_more' => ($offset + $limit) < $total, 'total' => $total]);
