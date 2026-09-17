<?php
/**
 * polls.php — опросы/голосования в чате и группах.
 *
 * Автор создаёт опрос (вопрос + варианты), в переписку уходит обычным сообщением
 * маркер [[poll:ID]] — его рисует post-format.js, а голоса подтягиваются отдельно.
 * Так работает и в личных чатах, и в группах: само сообщение шлёт клиент штатно.
 *
 * POST ?action=create {chat_key, question, options[], multi?}  → {id, marker}
 * GET  ?action=get&poll_id=ID                                   → опрос + голоса
 * POST ?action=vote {poll_id, option_idx}                       → переключить голос
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rate_limit.php';
if (!function_exists('getDB') && !function_exists('getDbConnection') && !function_exists('getPDO')) {
    require_once __DIR__ . '/db.php';
}
$pdo = function_exists('getDB') ? getDB()
     : (function_exists('getDbConnection') ? getDbConnection()
     : (function_exists('getPDO') ? getPDO() : null));
if (!$pdo) { http_response_code(500); echo json_encode(['error' => 'Нет подключения к БД']); exit; }

if (session_status() === PHP_SESSION_NONE) session_start();
$userId = $_SESSION['user_id'] ?? null;
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }

function pollOut($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS polls (
        id INT AUTO_INCREMENT PRIMARY KEY,
        creator_id VARCHAR(64) NOT NULL,
        chat_key VARCHAR(120) NULL,
        question VARCHAR(300) NOT NULL,
        options TEXT NOT NULL,
        multi TINYINT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS poll_votes (
        poll_id INT NOT NULL,
        user_id VARCHAR(64) NOT NULL,
        option_idx INT NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (poll_id, user_id, option_idx),
        INDEX idx_poll (poll_id)
    ) DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { pollOut(['ok' => false, 'error' => 'Не удалось подготовить таблицы'], 500); }

/** Полный снимок опроса для клиента: вопрос, варианты, счётчики, мои голоса. */
function pollSnapshot(PDO $pdo, $pollId, $userId) {
    $st = $pdo->prepare("SELECT id, creator_id, question, options, multi FROM polls WHERE id = ? LIMIT 1");
    $st->execute([$pollId]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) return null;
    $options = json_decode((string)$p['options'], true);
    if (!is_array($options)) $options = [];
    $counts = array_fill(0, count($options), 0);
    try {
        $c = $pdo->prepare("SELECT option_idx, COUNT(*) AS n FROM poll_votes WHERE poll_id = ? GROUP BY option_idx");
        $c->execute([$pollId]);
        foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $i = (int)$r['option_idx']; if ($i >= 0 && $i < count($counts)) $counts[$i] = (int)$r['n'];
        }
    } catch (Exception $e) {}
    $mine = [];
    try {
        $m = $pdo->prepare("SELECT option_idx FROM poll_votes WHERE poll_id = ? AND user_id = ?");
        $m->execute([$pollId, $userId]);
        $mine = array_map('intval', $m->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {}
    $voters = 0;
    try {
        $v = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM poll_votes WHERE poll_id = ?");
        $v->execute([$pollId]); $voters = (int)$v->fetchColumn();
    } catch (Exception $e) {}
    return ['ok' => true, 'id' => (int)$p['id'], 'question' => (string)$p['question'],
            'options' => $options, 'multi' => (int)$p['multi'], 'counts' => $counts,
            'my_votes' => $mine, 'voters' => $voters];
}

$action = $_GET['action'] ?? '';
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!psyRateLimit($pdo, 'poll_create:' . $userId, 20, 3600)) pollOut(['ok' => false, 'error' => 'Слишком часто'], 429);
    $question = mb_substr(trim((string)($body['question'] ?? '')), 0, 300);
    $multi = !empty($body['multi']) ? 1 : 0;
    $chatKey = mb_substr(trim((string)($body['chat_key'] ?? '')), 0, 120);
    $rawOpts = is_array($body['options'] ?? null) ? $body['options'] : [];
    $options = [];
    foreach ($rawOpts as $o) { $o = mb_substr(trim((string)$o), 0, 150); if ($o !== '') $options[] = $o; }
    $options = array_slice($options, 0, 10);
    if ($question === '') pollOut(['ok' => false, 'error' => 'Введите вопрос'], 400);
    if (count($options) < 2) pollOut(['ok' => false, 'error' => 'Нужно минимум 2 варианта'], 400);
    try {
        $st = $pdo->prepare("INSERT INTO polls (creator_id, chat_key, question, options, multi, created_at)
                             VALUES (?, ?, ?, ?, ?, NOW())");
        $st->execute([$userId, $chatKey !== '' ? $chatKey : null, $question, json_encode($options, JSON_UNESCAPED_UNICODE), $multi]);
        $id = (int)$pdo->lastInsertId();
        pollOut(['ok' => true, 'id' => $id, 'marker' => '[[poll:' . $id . ']]']);
    } catch (Exception $e) { pollOut(['ok' => false, 'error' => 'Не удалось создать опрос'], 500); }
}

if ($action === 'get') {
    $pollId = (int)($_GET['poll_id'] ?? 0);
    if ($pollId <= 0) pollOut(['ok' => false, 'error' => 'Некорректный опрос'], 400);
    $snap = pollSnapshot($pdo, $pollId, $userId);
    if (!$snap) pollOut(['ok' => false, 'error' => 'Опрос не найден'], 404);
    pollOut($snap);
}

if ($action === 'vote' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pollId = (int)($body['poll_id'] ?? 0);
    $idx = (int)($body['option_idx'] ?? -1);
    if ($pollId <= 0 || $idx < 0) pollOut(['ok' => false, 'error' => 'Некорректный голос'], 400);
    // Загружаем опрос — проверить границы и режим.
    $st = $pdo->prepare("SELECT options, multi FROM polls WHERE id = ? LIMIT 1");
    $st->execute([$pollId]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) pollOut(['ok' => false, 'error' => 'Опрос не найден'], 404);
    $options = json_decode((string)$p['options'], true);
    if (!is_array($options) || $idx >= count($options)) pollOut(['ok' => false, 'error' => 'Нет такого варианта'], 400);
    if (!psyRateLimit($pdo, 'poll_vote:' . $userId, 120, 60)) pollOut(['ok' => false, 'error' => 'Слишком часто'], 429);
    try {
        // Уже голосовал за этот вариант? Тогда снимаем голос (переключатель).
        $chk = $pdo->prepare("SELECT 1 FROM poll_votes WHERE poll_id = ? AND user_id = ? AND option_idx = ? LIMIT 1");
        $chk->execute([$pollId, $userId, $idx]);
        $has = (bool)$chk->fetchColumn();
        if ($has) {
            $pdo->prepare("DELETE FROM poll_votes WHERE poll_id = ? AND user_id = ? AND option_idx = ?")
                ->execute([$pollId, $userId, $idx]);
        } else {
            // Один вариант: снимаем прежние голоса этого пользователя.
            if ((int)$p['multi'] !== 1) {
                $pdo->prepare("DELETE FROM poll_votes WHERE poll_id = ? AND user_id = ?")->execute([$pollId, $userId]);
            }
            $pdo->prepare("INSERT IGNORE INTO poll_votes (poll_id, user_id, option_idx, created_at) VALUES (?, ?, ?, NOW())")
                ->execute([$pollId, $userId, $idx]);
        }
    } catch (Exception $e) { pollOut(['ok' => false, 'error' => 'Не удалось учесть голос'], 500); }
    pollOut(pollSnapshot($pdo, $pollId, $userId));
}

pollOut(['ok' => false, 'error' => 'Неизвестное действие'], 400);
