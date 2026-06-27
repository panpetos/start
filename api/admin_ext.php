<?php
/**
 * admin_ext.php — расширенные данные для админ-панели (только чтение + 2 безопасных действия).
 *
 * Самостоятельный файл: не трогает существующий бэкенд, подключается к той же БД,
 * что и остальные эндпоинты (config.php + db.php). Все запросы защищены try/catch,
 * чтобы расхождения в схеме приводили к пустой секции, а не к 500.
 *
 * GET  ?action=overview      — сводка (пользователи, записи, оплаты, звонки)
 * GET  ?action=users         — список пользователей
 * GET  ?action=appointments  — все записи
 * GET  ?action=payments      — все оплаты
 * GET  ?action=calls         — звонки/сессии (реальное время консультаций)
 * GET  ?action=activity      — единый журнал активности (аудит на основе данных)
 * POST ?action=set-frozen        {user_id, frozen:0|1}
 * POST ?action=set-approved      {psychologist_id, approved:0|1}
 *
 * Доступ только для роли admin.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';
// На этом хостинге config.php уже определяет функции БД. db.php подключаем
// только если ни одной из них нет (иначе фатал «Cannot redeclare»).
if (!function_exists('getDB') && !function_exists('getDbConnection') && !function_exists('getPDO')) {
    require_once __DIR__ . '/db.php';
}
$pdo = function_exists('getDB') ? getDB()
     : (function_exists('getDbConnection') ? getDbConnection()
     : (function_exists('getPDO') ? getPDO() : null));
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['error' => 'Нет подключения к БД']);
    exit;
}
if (session_status() === PHP_SESSION_NONE) session_start();

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['error' => 'Требуется авторизация']);
    exit;
}

// ── Проверка роли admin ───────────────────────────────────────────────────────
$me = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $me = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

if (!$me || ($me['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Доступ только для администратора']);
    exit;
}

// ── Утилиты ────────────────────────────────────────────────────────────────────

/** Безопасный SELECT: пробует ORDER BY список колонок-кандидатов, иначе без сортировки. */
function safeRows(PDO $pdo, string $table, array $orderCandidates = [], int $limit = 1000): array {
    foreach ($orderCandidates as $col) {
        try {
            $st = $pdo->query("SELECT * FROM `$table` ORDER BY `$col` DESC LIMIT $limit");
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { /* пробуем следующую колонку */ }
    }
    try {
        $st = $pdo->query("SELECT * FROM `$table` LIMIT $limit");
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

function safeCount(PDO $pdo, string $sql, array $args = []): int {
    try { $st = $pdo->prepare($sql); $st->execute($args); return (int)$st->fetchColumn(); }
    catch (Exception $e) { return 0; }
}

/** Первое существующее поле из списка кандидатов. */
function pick(array $row, array $keys, $default = null) {
    foreach ($keys as $k) { if (isset($row[$k]) && $row[$k] !== '') return $row[$k]; }
    return $default;
}

/** Карта пользователей id => {name, email, role}. */
function usersMap(PDO $pdo): array {
    $map = [];
    try {
        $st = $pdo->query("SELECT * FROM users LIMIT 5000");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
            $map[(string)$u['id']] = [
                'name'  => $name !== '' ? $name : ($u['email'] ?? 'Пользователь'),
                'email' => $u['email'] ?? '',
                'role'  => $u['role'] ?? '',
            ];
        }
    } catch (Exception $e) {}
    return $map;
}

/** Карта психологов: psychologists.id => user_id (для связи записи -> пользователь). */
function psychMap(PDO $pdo): array {
    $map = [];
    try {
        $st = $pdo->query("SELECT id, user_id FROM psychologists LIMIT 5000");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) $map[(string)$p['id']] = (string)$p['user_id'];
    } catch (Exception $e) {}
    return $map;
}

$action = $_GET['action'] ?? '';

// ── Чтение ───────────────────────────────────────────────────────────────────

if ($action === 'overview') {
    $now = date('Y-m-d H:i:s');
    $monthStart = date('Y-m-01 00:00:00');

    $out = [
        'users_total'        => safeCount($pdo, "SELECT COUNT(*) FROM users"),
        'clients'            => safeCount($pdo, "SELECT COUNT(*) FROM users WHERE role = 'client'"),
        'psychologists'      => safeCount($pdo, "SELECT COUNT(*) FROM users WHERE role = 'psychologist'"),
        'admins'             => safeCount($pdo, "SELECT COUNT(*) FROM users WHERE role = 'admin'"),
        'psy_pending'        => safeCount($pdo, "SELECT COUNT(*) FROM psychologists WHERE is_approved = 0"),
        'appointments_total' => safeCount($pdo, "SELECT COUNT(*) FROM appointments"),
        'appt_scheduled'     => safeCount($pdo, "SELECT COUNT(*) FROM appointments WHERE status = 'scheduled'"),
        'appt_completed'     => safeCount($pdo, "SELECT COUNT(*) FROM appointments WHERE status = 'completed'"),
        'appt_cancelled'     => safeCount($pdo, "SELECT COUNT(*) FROM appointments WHERE status = 'cancelled'"),
        'payments_total'     => safeCount($pdo, "SELECT COUNT(*) FROM payments WHERE status = 'success'"),
        'calls_total'        => safeCount($pdo, "SELECT COUNT(*) FROM calls"),
    ];

    // Выручка
    try {
        $out['revenue_total'] = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='success'")->fetchColumn();
    } catch (Exception $e) { $out['revenue_total'] = 0; }
    try {
        $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='success' AND paid_at >= ?");
        $st->execute([$monthStart]);
        $out['revenue_month'] = (float)$st->fetchColumn();
    } catch (Exception $e) { $out['revenue_month'] = 0; }

    // Среднее время звонка (сек) — пробуем разные названия колонки длительности
    $out['avg_call_sec'] = 0;
    foreach (['duration', 'duration_sec', 'seconds', 'length'] as $c) {
        try {
            $v = $pdo->query("SELECT AVG(`$c`) FROM calls WHERE `$c` > 0")->fetchColumn();
            if ($v !== false && $v !== null) { $out['avg_call_sec'] = round((float)$v); break; }
        } catch (Exception $e) {}
    }

    echo json_encode(['ok' => true, 'data' => $out]);
    exit;
}

if ($action === 'users') {
    $rows = safeRows($pdo, 'users', ['created_at', 'id'], 2000);
    // не отдаём хэши паролей
    foreach ($rows as &$r) { unset($r['password'], $r['password_hash'], $r['pass_hash']); }
    echo json_encode(['ok' => true, 'data' => $rows]);
    exit;
}

if ($action === 'psychologists') {
    $rows = safeRows($pdo, 'psychologists', ['created_at', 'id'], 2000);
    $um = usersMap($pdo);
    foreach ($rows as &$r) {
        $uid = (string)pick($r, ['user_id'], '');
        $r['name']  = $um[$uid]['name']  ?? '';
        $r['email'] = $um[$uid]['email'] ?? '';
    }
    echo json_encode(['ok' => true, 'data' => $rows]);
    exit;
}

if ($action === 'appointments') {
    $rows = safeRows($pdo, 'appointments', ['date_time', 'created_at', 'id'], 2000);
    $um = usersMap($pdo); $pm = psychMap($pdo);
    foreach ($rows as &$r) {
        $clientId = (string)pick($r, ['client_id', 'user_id'], '');
        $r['client_name'] = $um[$clientId]['name'] ?? '';
        $pid = (string)pick($r, ['psychologist_id'], '');
        $puid = $pm[$pid] ?? $pid;
        $r['psychologist_name'] = $um[$puid]['name'] ?? '';
    }
    echo json_encode(['ok' => true, 'data' => $rows]);
    exit;
}

if ($action === 'payments') {
    $rows = safeRows($pdo, 'payments', ['paid_at', 'created_at', 'id'], 2000);
    // обогащаем именем клиента через appointment_id -> appointments.client_id -> users
    $apptClient = [];
    try {
        foreach ($pdo->query("SELECT id, client_id, psychologist_id FROM appointments LIMIT 5000")->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $apptClient[(string)$a['id']] = $a;
        }
    } catch (Exception $e) {}
    $um = usersMap($pdo); $pm = psychMap($pdo);
    foreach ($rows as &$r) {
        $aid = (string)pick($r, ['appointment_id'], '');
        if (isset($apptClient[$aid])) {
            $cid = (string)($apptClient[$aid]['client_id'] ?? '');
            $r['client_name'] = $um[$cid]['name'] ?? '';
            $pid = (string)($apptClient[$aid]['psychologist_id'] ?? '');
            $puid = $pm[$pid] ?? $pid;
            $r['psychologist_name'] = $um[$puid]['name'] ?? '';
        }
    }
    echo json_encode(['ok' => true, 'data' => $rows]);
    exit;
}

if ($action === 'calls') {
    $rows = safeRows($pdo, 'calls', ['created_at', 'started_at', 'start_time', 'id'], 2000);
    $um = usersMap($pdo);
    foreach ($rows as &$r) {
        $a = (string)pick($r, ['caller_id', 'initiator_id', 'user_id', 'from_user_id'], '');
        $b = (string)pick($r, ['participant_id', 'callee_id', 'to_user_id', 'receiver_id'], '');
        $r['from_name'] = $um[$a]['name'] ?? '';
        $r['to_name']   = $um[$b]['name'] ?? '';
    }
    echo json_encode(['ok' => true, 'data' => $rows]);
    exit;
}

if ($action === 'activity') {
    // Единый журнал из реальных данных (регистрации, записи, оплаты, звонки).
    $um = usersMap($pdo); $pm = psychMap($pdo);
    $events = [];

    // Регистрации
    try {
        foreach ($pdo->query("SELECT * FROM users ORDER BY created_at DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: ($u['email'] ?? '');
            $events[] = [
                'ts' => pick($u, ['created_at'], ''),
                'type' => 'register',
                'actor' => $name,
                'actor_role' => $u['role'] ?? '',
                'summary' => 'Регистрация: ' . $name . ' (' . ($u['role'] ?? '') . ')',
            ];
        }
    } catch (Exception $e) {}

    // Записи
    try {
        foreach ($pdo->query("SELECT * FROM appointments ORDER BY created_at DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $cid = (string)pick($a, ['client_id', 'user_id'], '');
            $pid = (string)pick($a, ['psychologist_id'], '');
            $puid = $pm[$pid] ?? $pid;
            $cn = $um[$cid]['name'] ?? 'клиент';
            $pn = $um[$puid]['name'] ?? 'психолог';
            $events[] = [
                'ts' => pick($a, ['created_at', 'date_time'], ''),
                'type' => 'appointment',
                'actor' => $cn,
                'summary' => "Запись: $cn → $pn (" . pick($a, ['status'], '') . ')',
            ];
        }
    } catch (Exception $e) {}

    // Оплаты
    try {
        foreach ($pdo->query("SELECT * FROM payments ORDER BY paid_at DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $events[] = [
                'ts' => pick($p, ['paid_at', 'created_at'], ''),
                'type' => 'payment',
                'actor' => '',
                'summary' => 'Оплата: ' . number_format((float)pick($p, ['amount'], 0), 0, '.', ' ') . ' ₽ (' . pick($p, ['status'], '') . ')',
            ];
        }
    } catch (Exception $e) {}

    // Звонки
    try {
        foreach ($pdo->query("SELECT * FROM calls ORDER BY created_at DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $dur = (int)pick($c, ['duration', 'duration_sec', 'seconds', 'length'], 0);
            $durStr = $dur > 0 ? (floor($dur / 60) . ':' . str_pad($dur % 60, 2, '0', STR_PAD_LEFT)) : '';
            $events[] = [
                'ts' => pick($c, ['created_at', 'started_at', 'start_time'], ''),
                'type' => 'call',
                'actor' => '',
                'summary' => 'Звонок/сессия' . ($durStr ? ", длительность $durStr" : ''),
            ];
        }
    } catch (Exception $e) {}

    // Сортировка по времени убыв.
    usort($events, function ($a, $b) { return strcmp((string)$b['ts'], (string)$a['ts']); });
    $events = array_slice($events, 0, 500);

    echo json_encode(['ok' => true, 'data' => $events]);
    exit;
}

// ── Действия (безопасные одиночные UPDATE) ────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];

    if ($action === 'set-frozen') {
        $uid = $body['user_id'] ?? null;
        $frozen = !empty($body['frozen']) ? 1 : 0;
        if (!$uid) { http_response_code(400); echo json_encode(['error' => 'user_id обязателен']); exit; }
        try {
            $st = $pdo->prepare("UPDATE users SET is_frozen = ? WHERE id = ?");
            $st->execute([$frozen, $uid]);
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            http_response_code(500); echo json_encode(['error' => 'Не удалось обновить (поле is_frozen?)']);
        }
        exit;
    }

    if ($action === 'set-approved') {
        $pid = $body['psychologist_id'] ?? null;
        $approved = !empty($body['approved']) ? 1 : 0;
        if (!$pid) { http_response_code(400); echo json_encode(['error' => 'psychologist_id обязателен']); exit; }
        try {
            $st = $pdo->prepare("UPDATE psychologists SET is_approved = ? WHERE id = ?");
            $st->execute([$approved, $pid]);
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            http_response_code(500); echo json_encode(['error' => 'Не удалось обновить (поле is_approved?)']);
        }
        exit;
    }
}

http_response_code(400);
echo json_encode(['error' => 'Неизвестное действие']);
