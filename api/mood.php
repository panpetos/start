<?php
/**
 * mood.php — чек-ин настроения (для платформы психологической помощи).
 *
 * ЗАЧЕМ. Человек отмечает настроение (шкала 1–5), а психолог видит динамику —
 * это то, чего нет у обычных мессенджеров и что реально помогает в терапии.
 *
 * Настроение — личное: пишет его сам человек про себя. Смотреть чужую динамику
 * можно, только если между вами есть переписка (то есть это ваш психолог/клиент),
 * либо вы админ. Одна запись в день: повторная отметка обновляет сегодняшнюю.
 *
 * POST ?action=log     {score:1..5, note?}     → отметить своё настроение на сегодня
 * GET  ?action=history &user_id=? &days=30      → динамика (своя или собеседника)
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

function moodOut($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS mood_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(64) NOT NULL,
        day DATE NOT NULL,
        score TINYINT NOT NULL,
        note VARCHAR(300) NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uniq_user_day (user_id, day),
        INDEX idx_user (user_id)
    ) DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { moodOut(['ok' => false, 'error' => 'Не удалось подготовить таблицу'], 500); }

$isAdmin = false;
try {
    $st = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    $st->execute([$userId]);
    $isAdmin = ((string)$st->fetchColumn() === 'admin');
} catch (Exception $e) {}

/** Есть ли переписка между двумя людьми — чтобы чужую динамику видел только «свой». */
function moodHasConversation(PDO $pdo, $a, $b) {
    try {
        $st = $pdo->prepare("SELECT 1 FROM messages
            WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?) LIMIT 1");
        $st->execute([$a, $b, $b, $a]);
        return (bool)$st->fetchColumn();
    } catch (Exception $e) { return false; }
}

$action = $_GET['action'] ?? '';
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

if ($action === 'log' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $score = (int)($body['score'] ?? 0);
    if ($score < 1 || $score > 5) moodOut(['ok' => false, 'error' => 'Оценка 1..5'], 400);
    $note = trim((string)($body['note'] ?? ''));
    if ($note !== '') $note = mb_substr($note, 0, 300);
    try {
        $pdo->prepare("INSERT INTO mood_log (user_id, day, score, note, created_at)
                       VALUES (?, CURDATE(), ?, ?, NOW())
                       ON DUPLICATE KEY UPDATE score = VALUES(score), note = VALUES(note), created_at = NOW()")
            ->execute([$userId, $score, $note !== '' ? $note : null]);
        moodOut(['ok' => true]);
    } catch (Exception $e) { moodOut(['ok' => false, 'error' => 'Не удалось сохранить'], 500); }
}

if ($action === 'history') {
    $target = trim((string)($_GET['user_id'] ?? '')) ?: (string)$userId;
    $days = max(7, min(180, (int)($_GET['days'] ?? 30)));
    // Доступ: свои — всегда; чужие — админу или если есть переписка (ваш клиент/психолог).
    if ((string)$target !== (string)$userId && !$isAdmin && !moodHasConversation($pdo, $userId, $target)) {
        moodOut(['ok' => false, 'error' => 'Нет доступа к динамике этого человека'], 403);
    }
    try {
        $st = $pdo->prepare("SELECT day, score, note FROM mood_log
                             WHERE user_id = ? AND day >= (CURDATE() - INTERVAL ? DAY)
                             ORDER BY day ASC");
        $st->execute([$target, $days]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $avg = null;
        if ($rows) { $s = 0; foreach ($rows as $r) $s += (int)$r['score']; $avg = round($s / count($rows), 1); }
        moodOut(['ok' => true, 'data' => $rows, 'avg' => $avg, 'is_self' => ((string)$target === (string)$userId)]);
    } catch (Exception $e) { moodOut(['ok' => true, 'data' => [], 'avg' => null]); }
}

moodOut(['ok' => false, 'error' => 'Неизвестное действие'], 400);
