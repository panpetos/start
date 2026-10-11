<?php
/**
 * complaints.php — жалоба на собеседника с временным доступом администратора к переписке.
 *
 * Зачем именно так. Читать чужие переписки «просто потому что админ» нельзя — это личные
 * данные, и такой доступ никто не ожидает. Но и оставлять человека один на один со спамером
 * или неадекватным собеседником тоже неправильно. Поэтому доступ появляется только когда
 * человек сам его дал: подавая жалобу, он ставит галочку «разрешаю посмотреть переписку»,
 * и админ видит её ограниченное время (по умолчанию 72 часа). Каждый факт открытия
 * записывается в журнал, и обе стороны могут увидеть, что переписку открывали.
 *
 * POST ?action=create   {against_id, reason, allow_access, [message_id]}  — подать жалобу
 * GET  ?action=my                                   — мои жалобы
 * GET  ?action=list&status=open|all                 — все жалобы (админ)
 * GET  ?action=thread&id=N                          — переписка по жалобе (админ, пока доступ жив)
 * POST ?action=resolve  {id, status, admin_comment}  — закрыть жалобу (админ)
 * POST ?action=revoke   {id}                         — забрать доступ досрочно (автор жалобы)
 * GET  ?action=access-log&id=N                       — кто и когда открывал переписку
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

const COMPLAINT_ACCESS_HOURS = 72;   // столько живёт разрешение на просмотр переписки

$initError = '';
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS complaints (
        id INT AUTO_INCREMENT PRIMARY KEY,
        author_id VARCHAR(64) NOT NULL,
        against_id VARCHAR(64) NOT NULL,
        reason TEXT NOT NULL,
        message_id VARCHAR(64) NULL,
        allow_access TINYINT NOT NULL DEFAULT 0,
        access_until DATETIME NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        admin_comment TEXT NULL,
        created_at DATETIME NOT NULL,
        resolved_at DATETIME NULL,
        INDEX idx_author (author_id),
        INDEX idx_against (against_id),
        INDEX idx_status (status)
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS complaint_access_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        complaint_id INT NOT NULL,
        admin_id VARCHAR(64) NOT NULL,
        opened_at DATETIME NOT NULL,
        INDEX idx_complaint (complaint_id)
    ) DEFAULT CHARSET=utf8mb4");
    psy_align_collation($pdo, ['complaints', 'complaint_access_log']);
} catch (Exception $e) {
    $initError = $e->getMessage();
}

$action = $_GET['action'] ?? '';
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

function isAdmin($pdo, $userId) {
    try {
        $st = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        $st->execute([$userId]);
        return $st->fetchColumn() === 'admin';
    } catch (Exception $e) { return false; }
}

function userBrief($pdo, $id) {
    try {
        $st = $pdo->prepare("SELECT id, first_name, last_name, email, role FROM users WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: ['id' => $id];
    } catch (Exception $e) { return ['id' => $id]; }
}

try {
    if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($initError) out(['ok' => false, 'error' => 'Жалобы недоступны: ' . $initError], 500);
        $against = trim((string)($body['against_id'] ?? ''));
        $reason = trim((string)($body['reason'] ?? ''));
        $allow = !empty($body['allow_access']) ? 1 : 0;
        $messageId = trim((string)($body['message_id'] ?? ''));
        if ($against === '' || $against === (string)$userId) out(['ok' => false, 'error' => 'Некорректный получатель жалобы'], 400);
        if (mb_strlen($reason) < 5) out(['ok' => false, 'error' => 'Опишите, что произошло (хотя бы пару фраз)'], 400);
        $reason = mb_substr($reason, 0, 3000);

        // одна открытая жалоба на человека за раз — иначе очередь захламляется дублями
        $dup = $pdo->prepare("SELECT id FROM complaints WHERE author_id = ? AND against_id = ? AND status = 'open' LIMIT 1");
        $dup->execute([$userId, $against]);
        if ($dup->fetchColumn()) out(['ok' => false, 'error' => 'Ваша жалоба на этого человека уже рассматривается'], 400);

        $until = $allow ? date('Y-m-d H:i:s', time() + COMPLAINT_ACCESS_HOURS * 3600) : null;
        $st = $pdo->prepare("INSERT INTO complaints (author_id, against_id, reason, message_id, allow_access, access_until, status, created_at)
                             VALUES (?, ?, ?, ?, ?, ?, 'open', NOW())");
        $st->execute([$userId, $against, $reason, $messageId !== '' ? $messageId : null, $allow, $until]);
        out(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'access_until' => $until, 'access_hours' => COMPLAINT_ACCESS_HOURS]);
    }

    if ($action === 'my') {
        if ($initError) out(['ok' => true, 'data' => []]);
        $st = $pdo->prepare("SELECT c.*, u.first_name, u.last_name
                             FROM complaints c LEFT JOIN users u ON u.id = c.against_id
                             WHERE c.author_id = ? ORDER BY c.id DESC LIMIT 100");
        $st->execute([$userId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        // и сколько раз переписку открывали — человек вправе это знать
        foreach ($rows as &$r) {
            $l = $pdo->prepare("SELECT COUNT(*) FROM complaint_access_log WHERE complaint_id = ?");
            $l->execute([$r['id']]);
            $r['opened_times'] = (int)$l->fetchColumn();
        }
        out(['ok' => true, 'data' => $rows]);
    }

    // Жалобы на меня: обе стороны должны видеть, что переписку открывали
    if ($action === 'about-me') {
        if ($initError) out(['ok' => true, 'data' => []]);
        $st = $pdo->prepare("SELECT id, status, allow_access, access_until, created_at FROM complaints
                             WHERE against_id = ? ORDER BY id DESC LIMIT 50");
        $st->execute([$userId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $l = $pdo->prepare("SELECT COUNT(*) FROM complaint_access_log WHERE complaint_id = ?");
            $l->execute([$r['id']]);
            $r['opened_times'] = (int)$l->fetchColumn();
        }
        out(['ok' => true, 'data' => $rows]);
    }

    if ($action === 'revoke' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) out(['ok' => false, 'error' => 'id обязателен'], 400);
        $st = $pdo->prepare("SELECT author_id FROM complaints WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $author = $st->fetchColumn();
        if (!$author) out(['ok' => false, 'error' => 'Жалоба не найдена'], 404);
        if ((string)$author !== (string)$userId) out(['ok' => false, 'error' => 'Отозвать доступ может только автор жалобы'], 403);
        $pdo->prepare("UPDATE complaints SET allow_access = 0, access_until = NULL WHERE id = ?")->execute([$id]);
        out(['ok' => true]);
    }

    // ── дальше только администратор ──
    if (!isAdmin($pdo, $userId)) out(['ok' => false, 'error' => 'Доступно только администратору'], 403);

    if ($action === 'list') {
        if ($initError) out(['ok' => true, 'data' => []]);
        $status = (string)($_GET['status'] ?? 'open');
        $sql = "SELECT c.*, a.first_name AS author_first, a.last_name AS author_last, a.email AS author_email,
                       b.first_name AS against_first, b.last_name AS against_last, b.email AS against_email, b.role AS against_role
                FROM complaints c
                LEFT JOIN users a ON a.id = c.author_id
                LEFT JOIN users b ON b.id = c.against_id";
        $params = [];
        if ($status !== 'all') { $sql .= " WHERE c.status = ?"; $params[] = $status; }
        $sql .= " ORDER BY c.id DESC LIMIT 200";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['access_alive'] = ($r['allow_access'] && $r['access_until'] && strtotime($r['access_until']) > time()) ? 1 : 0;
        }
        out(['ok' => true, 'data' => $rows]);
    }

    if ($action === 'thread') {
        if ($initError) out(['ok' => false, 'error' => 'Жалобы недоступны: ' . $initError], 500);
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) out(['ok' => false, 'error' => 'id обязателен'], 400);
        $st = $pdo->prepare("SELECT * FROM complaints WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) out(['ok' => false, 'error' => 'Жалоба не найдена'], 404);
        if (!$c['allow_access']) out(['ok' => false, 'error' => 'Автор жалобы не дал доступ к переписке'], 403);
        if (!$c['access_until'] || strtotime($c['access_until']) <= time()) {
            out(['ok' => false, 'error' => 'Срок доступа к переписке истёк'], 403);
        }
        // Факт открытия фиксируем всегда, до выдачи содержимого
        $pdo->prepare("INSERT INTO complaint_access_log (complaint_id, admin_id, opened_at) VALUES (?, ?, NOW())")
            ->execute([$id, $userId]);
        $m = $pdo->prepare("SELECT id, sender_id, receiver_id, content, attachment_url, attachment_type, attachment_name, created_at
                            FROM messages
                            WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
                            ORDER BY created_at ASC LIMIT 500");
        $m->execute([$c['author_id'], $c['against_id'], $c['against_id'], $c['author_id']]);
        out([
            'ok' => true,
            'complaint' => $c,
            'author' => userBrief($pdo, $c['author_id']),
            'against' => userBrief($pdo, $c['against_id']),
            'access_until' => $c['access_until'],
            'data' => $m->fetchAll(PDO::FETCH_ASSOC),
        ]);
    }

    if ($action === 'access-log') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) out(['ok' => false, 'error' => 'id обязателен'], 400);
        $st = $pdo->prepare("SELECT l.*, u.first_name, u.last_name FROM complaint_access_log l
                             LEFT JOIN users u ON u.id = l.admin_id
                             WHERE l.complaint_id = ? ORDER BY l.id DESC LIMIT 200");
        $st->execute([$id]);
        out(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'resolve' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($body['id'] ?? 0);
        $status = (string)($body['status'] ?? 'closed');
        if (!$id) out(['ok' => false, 'error' => 'id обязателен'], 400);
        if (!in_array($status, ['open', 'closed', 'rejected'], true)) out(['ok' => false, 'error' => 'Неизвестный статус'], 400);
        $comment = mb_substr(trim((string)($body['admin_comment'] ?? '')), 0, 2000);
        // Закрыли жалобу — доступ к переписке сразу гаснет, дольше он не нужен
        if ($status === 'open') {
            $pdo->prepare("UPDATE complaints SET status = ?, admin_comment = ?, resolved_at = NULL WHERE id = ?")
                ->execute([$status, $comment !== '' ? $comment : null, $id]);
        } else {
            $pdo->prepare("UPDATE complaints SET status = ?, admin_comment = ?, resolved_at = NOW(), allow_access = 0, access_until = NULL WHERE id = ?")
                ->execute([$status, $comment !== '' ? $comment : null, $id]);
        }
        out(['ok' => true]);
    }

    out(['ok' => false, 'error' => 'Неизвестное действие'], 400);
} catch (Exception $e) {
    out(['ok' => false, 'error' => $e->getMessage()], 500);
}
