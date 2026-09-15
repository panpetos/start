<?php
/**
 * homework.php — домашние задания между людьми в чате (психолог → клиент).
 *
 * ЗАЧЕМ. После сессии психолог даёт задание («вести дневник эмоций», упражнение,
 * чек-лист). Раньше это терялось в переписке. Теперь — отдельным списком со
 * сроком и статусом «выполнено», который видят оба.
 *
 * Доступ — только участникам (кто дал и кому дали). Отметить «выполнено» может
 * тот, кому дали (и автор). При создании в переписку падает строка-напоминание.
 *
 * GET  ?action=list  &with=<peerId>                 → задания между мной и этим человеком
 * POST ?action=add   {to_id, title, details?, due?} → выдать задание
 * POST ?action=done  {id, done:0|1}                 → отметить выполнение
 * POST ?action=delete{id}                           → удалить (автор)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rtc_lib.php';   // rtcSendDm() — строка-напоминание в чат
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

function hwOut($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS homework (
        id INT AUTO_INCREMENT PRIMARY KEY,
        from_id VARCHAR(64) NOT NULL,
        to_id VARCHAR(64) NOT NULL,
        title VARCHAR(300) NOT NULL,
        details TEXT NULL,
        due_date DATE NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'open',
        created_at DATETIME NOT NULL,
        done_at DATETIME NULL,
        INDEX idx_pair (from_id, to_id),
        INDEX idx_to (to_id)
    ) DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { hwOut(['ok' => false, 'error' => 'Не удалось подготовить таблицу'], 500); }

$action = $_GET['action'] ?? '';
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

function hwRow(PDO $pdo, $id) {
    $st = $pdo->prepare("SELECT * FROM homework WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($action === 'list') {
    $peer = trim((string)($_GET['with'] ?? ''));
    if ($peer === '') hwOut(['ok' => true, 'data' => []]);
    try {
        $st = $pdo->prepare("SELECT id, from_id, to_id, title, details, due_date, status, created_at, done_at
                             FROM homework
                             WHERE (from_id = ? AND to_id = ?) OR (from_id = ? AND to_id = ?)
                             ORDER BY (status = 'done') ASC, COALESCE(due_date, '9999-12-31') ASC, id DESC
                             LIMIT 200");
        $st->execute([$userId, $peer, $peer, $userId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) { $r['mine'] = ((string)$r['from_id'] === (string)$userId); }
        unset($r);
        hwOut(['ok' => true, 'data' => $rows]);
    } catch (Exception $e) { hwOut(['ok' => true, 'data' => []]); }
}

if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $to = trim((string)($body['to_id'] ?? ''));
    $title = trim((string)($body['title'] ?? ''));
    $details = trim((string)($body['details'] ?? ''));
    $due = trim((string)($body['due'] ?? ''));
    if ($to === '' || $to === (string)$userId) hwOut(['ok' => false, 'error' => 'Некорректный получатель'], 400);
    if ($title === '') hwOut(['ok' => false, 'error' => 'Введите название задания'], 400);
    $title = mb_substr($title, 0, 300);
    $details = $details !== '' ? mb_substr($details, 0, 4000) : null;
    // Дату принимаем только в виде YYYY-MM-DD, иначе — без срока.
    if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) $due = '';
    try {
        $st = $pdo->prepare("INSERT INTO homework (from_id, to_id, title, details, due_date, status, created_at)
                             VALUES (?, ?, ?, ?, ?, 'open', NOW())");
        $st->execute([$userId, $to, $title, $details, $due !== '' ? $due : null]);
        $id = (int)$pdo->lastInsertId();
    } catch (Exception $e) { hwOut(['ok' => false, 'error' => 'Не удалось создать задание'], 500); }
    // Строка-напоминание в переписку — побочное действие, тихо и после сохранения.
    if (function_exists('rtcSendDm')) {
        $line = '📌 Домашнее задание: ' . $title . ($due !== '' ? ' (до ' . $due . ')' : '');
        rtcSendDm($pdo, $userId, $to, $line);
    }
    hwOut(['ok' => true, 'id' => $id]);
}

if ($action === 'done' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($body['id'] ?? 0);
    $done = !empty($body['done']);
    $row = hwRow($pdo, $id);
    if (!$row) hwOut(['ok' => false, 'error' => 'Задание не найдено'], 404);
    // Отмечать может тот, кому дали, и автор.
    if ((string)$row['to_id'] !== (string)$userId && (string)$row['from_id'] !== (string)$userId) {
        hwOut(['ok' => false, 'error' => 'Нет доступа'], 403);
    }
    try {
        if ($done) $pdo->prepare("UPDATE homework SET status = 'done', done_at = NOW() WHERE id = ?")->execute([$id]);
        else       $pdo->prepare("UPDATE homework SET status = 'open', done_at = NULL WHERE id = ?")->execute([$id]);
        hwOut(['ok' => true]);
    } catch (Exception $e) { hwOut(['ok' => false, 'error' => 'Не удалось обновить'], 500); }
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($body['id'] ?? 0);
    $row = hwRow($pdo, $id);
    if (!$row) hwOut(['ok' => true]);
    if ((string)$row['from_id'] !== (string)$userId) hwOut(['ok' => false, 'error' => 'Удалить может только автор'], 403);
    try { $pdo->prepare("DELETE FROM homework WHERE id = ?")->execute([$id]); hwOut(['ok' => true]); }
    catch (Exception $e) { hwOut(['ok' => false, 'error' => 'Не удалось удалить'], 500); }
}

hwOut(['ok' => false, 'error' => 'Неизвестное действие'], 400);
