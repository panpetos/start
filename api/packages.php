<?php
/**
 * packages.php — абонементы (пакеты сессий) клиента к конкретному психологу.
 *
 * Модель: клиент выбирает психолога → покупает пакет (1 / 5 / 10 сессий) со скидкой.
 * В ЛК показывается «осталось X из N». Каждая запись по абонементу списывает одну
 * сессию и создаёт запись в appointments (без повторной оплаты).
 *
 * Самостоятельный файл, та же БД, сам создаёт таблицу session_packages.
 *
 * POST ?action=create  {psychologist_id, sessions}        — клиент покупает абонемент
 * GET  ?action=list                                       — абонементы клиента
 * POST ?action=book    {package_id, datetime, format}     — записаться по абонементу
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

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS session_packages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_user_id VARCHAR(64) NOT NULL,
        psychologist_id VARCHAR(64) NOT NULL,
        psychologist_user_id VARCHAR(64) NULL,
        total_sessions INT NOT NULL,
        used_sessions INT NOT NULL DEFAULT 0,
        base_price DECIMAL(10,2) NOT NULL DEFAULT 0,
        per_session_price DECIMAL(10,2) NOT NULL DEFAULT 0,
        total_price DECIMAL(10,2) NOT NULL DEFAULT 0,
        discount_pct INT NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NULL,
        INDEX idx_client (client_user_id),
        INDEX idx_pair (client_user_id, psychologist_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    psy_widen_id_columns($pdo, 'session_packages', ['client_user_id', 'psychologist_id', 'psychologist_user_id']);
    psy_align_collation($pdo, ['session_packages']);
} catch (Exception $e) {}

/** Скидка пакета по количеству сессий (в процентах). */
function packageDiscount(int $sessions): int {
    if ($sessions >= 10) return 20;
    if ($sessions >= 5)  return 10;
    return 0;
}
/** Срок действия абонемента по количеству сессий. */
function packageValidity(int $sessions): string {
    if ($sessions >= 10) return '+6 months';
    if ($sessions >= 5)  return '+3 months';
    return '+1 month';
}

/** Список колонок таблицы (для защитной вставки appointments). */
function tableColumns(PDO $pdo, string $table): array {
    try { return $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN); }
    catch (Exception $e) { return []; }
}

$action = $_GET['action'] ?? '';

// ── Покупка абонемента ─────────────────────────────────────────────────────────
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $psyId = $body['psychologist_id'] ?? null;
    $sessions = (int)($body['sessions'] ?? 0);
    if (!$psyId || $sessions < 1) { http_response_code(400); echo json_encode(['error' => 'Нужны психолог и количество сессий']); exit; }

    // Цена и user_id психолога
    $base = 0; $psyUserId = null;
    try {
        $st = $pdo->prepare("SELECT * FROM psychologists WHERE id = ? LIMIT 1");
        $st->execute([$psyId]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if ($p) { $base = (float)($p['price'] ?? 0); $psyUserId = $p['user_id'] ?? null; }
    } catch (Exception $e) {}
    if ($base <= 0) { http_response_code(400); echo json_encode(['error' => 'У психолога не задана цена — абонемент недоступен']); exit; }

    $disc = packageDiscount($sessions);
    $per = round($base * (100 - $disc) / 100, 2);
    $total = round($per * $sessions, 2);
    $expires = date('Y-m-d H:i:s', strtotime(packageValidity($sessions)));

    try {
        $st = $pdo->prepare("INSERT INTO session_packages
            (client_user_id, psychologist_id, psychologist_user_id, total_sessions, used_sessions,
             base_price, per_session_price, total_price, discount_pct, status, expires_at)
            VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, 'active', ?)");
        $st->execute([$userId, $psyId, $psyUserId, $sessions, $base, $per, $total, $disc, $expires]);
        $id = (int)$pdo->lastInsertId();
    } catch (Exception $e) {
        http_response_code(500); echo json_encode(['error' => 'Не удалось оформить абонемент']); exit;
    }
    echo json_encode(['ok' => true, 'id' => $id, 'total_price' => $total, 'per_session_price' => $per, 'sessions' => $sessions, 'discount_pct' => $disc]);
    exit;
}

// ── Список абонементов клиента ──────────────────────────────────────────────────
if ($action === 'list') {
    try {
        $st = $pdo->prepare("SELECT * FROM session_packages WHERE client_user_id = ? ORDER BY id DESC");
        $st->execute([$userId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $rows = []; }

    // Имена и доступность психологов
    $psyInfo = [];
    try {
        foreach ($pdo->query("SELECT p.id, p.is_approved, u.first_name, u.last_name, u.is_frozen
            FROM psychologists p LEFT JOIN users u ON u.id = p.user_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $nm = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            $psyInfo[(string)$r['id']] = [
                'name' => $nm !== '' ? $nm : 'Психолог',
                'available' => !empty($r['first_name']) && empty($r['is_frozen']) && !empty($r['is_approved'])
            ];
        }
    } catch (Exception $e) {}

    foreach ($rows as &$r) {
        $r['remaining'] = max(0, (int)$r['total_sessions'] - (int)$r['used_sessions']);
        $info = $psyInfo[(string)$r['psychologist_id']] ?? null;
        $r['psychologist_name'] = $info ? $info['name'] : 'Психолог';
        $r['psychologist_unavailable'] = !$info || !$info['available'];
        $expired = !empty($r['expires_at']) && strtotime($r['expires_at']) < time();
        if ($r['remaining'] <= 0) $r['state'] = 'used';
        elseif ($expired) $r['state'] = 'expired';
        else $r['state'] = 'active';
    }
    echo json_encode(['ok' => true, 'data' => $rows]);
    exit;
}

// ── Запись по абонементу ────────────────────────────────────────────────────────
if ($action === 'book' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $pkgId = $body['package_id'] ?? null;
    $datetime = trim((string)($body['datetime'] ?? ''));
    $format = trim((string)($body['format'] ?? 'video'));
    if (!$pkgId || $datetime === '') { http_response_code(400); echo json_encode(['error' => 'Нужны абонемент и время']); exit; }

    try {
        $st = $pdo->prepare("SELECT * FROM session_packages WHERE id = ? LIMIT 1");
        $st->execute([$pkgId]);
        $pkg = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $pkg = null; }
    if (!$pkg) { http_response_code(404); echo json_encode(['error' => 'Абонемент не найден']); exit; }
    if ((string)$pkg['client_user_id'] !== (string)$userId) { http_response_code(403); echo json_encode(['error' => 'Это не ваш абонемент']); exit; }
    $remaining = (int)$pkg['total_sessions'] - (int)$pkg['used_sessions'];
    if ($remaining <= 0) { http_response_code(400); echo json_encode(['error' => 'В абонементе не осталось сессий']); exit; }
    if (!empty($pkg['expires_at']) && strtotime($pkg['expires_at']) < time()) { http_response_code(400); echo json_encode(['error' => 'Срок действия абонемента истёк']); exit; }

    // Защитная вставка appointment — подстраиваемся под реальную схему таблицы
    $cols = tableColumns($pdo, 'appointments');
    if (!$cols) { http_response_code(500); echo json_encode(['error' => 'Таблица записей недоступна']); exit; }
    $has = function ($c) use ($cols) { return in_array($c, $cols, true); };

    $fields = []; $marks = []; $vals = [];
    $put = function ($col, $val) use (&$fields, &$marks, &$vals) { $fields[] = "`$col`"; $marks[] = '?'; $vals[] = $val; };

    $clientCol = $has('client_id') ? 'client_id' : ($has('user_id') ? 'user_id' : null);
    if ($clientCol) $put($clientCol, $userId);
    if ($has('psychologist_id')) $put('psychologist_id', $pkg['psychologist_id']);

    $dtCol = null;
    foreach (['date_time', 'datetime', 'scheduled_at', 'appointment_time', 'start_time', 'date'] as $c) { if ($has($c)) { $dtCol = $c; break; } }
    if ($dtCol) $put($dtCol, str_replace('T', ' ', $datetime));
    if ($has('format')) $put('format', $format);
    if ($has('status')) $put('status', 'scheduled');
    if ($has('price')) $put('price', 0);
    if ($has('paid')) $put('paid', 1);
    if ($has('package_id')) $put('package_id', $pkgId);
    if ($has('created_at')) $put('created_at', date('Y-m-d H:i:s'));

    if (!$clientCol || !$dtCol) { http_response_code(500); echo json_encode(['error' => 'Не удалось сопоставить поля записи']); exit; }

    $apptId = null;
    try {
        $sql = "INSERT INTO appointments (" . implode(',', $fields) . ") VALUES (" . implode(',', $marks) . ")";
        $ins = $pdo->prepare($sql);
        $ins->execute($vals);
        $apptId = (int)$pdo->lastInsertId();
    } catch (Exception $e) {
        http_response_code(500); echo json_encode(['error' => 'Не удалось создать запись по абонементу']); exit;
    }

    // Списываем сессию
    try {
        $newUsed = (int)$pkg['used_sessions'] + 1;
        $newStatus = ($newUsed >= (int)$pkg['total_sessions']) ? 'used' : 'active';
        $u = $pdo->prepare("UPDATE session_packages SET used_sessions = ?, status = ? WHERE id = ?");
        $u->execute([$newUsed, $newStatus, $pkgId]);
    } catch (Exception $e) {}

    echo json_encode(['ok' => true, 'appointment_id' => $apptId, 'remaining' => $remaining - 1]);
    exit;
}

// ── Переназначение абонемента на другого психолога ──────────────────────────────
if ($action === 'reassign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $pkgId = $body['package_id'] ?? null;
    $newPsyId = $body['psychologist_id'] ?? null;
    if (!$pkgId || !$newPsyId) { http_response_code(400); echo json_encode(['error' => 'Нужны package_id и psychologist_id']); exit; }

    try {
        $st = $pdo->prepare("SELECT * FROM session_packages WHERE id = ? LIMIT 1");
        $st->execute([$pkgId]);
        $pkg = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $pkg = null; }
    if (!$pkg) { http_response_code(404); echo json_encode(['error' => 'Абонемент не найден']); exit; }
    if ((string)$pkg['client_user_id'] !== (string)$userId) { http_response_code(403); echo json_encode(['error' => 'Это не ваш абонемент']); exit; }
    $remaining = (int)$pkg['total_sessions'] - (int)$pkg['used_sessions'];
    if ($remaining <= 0) { http_response_code(400); echo json_encode(['error' => 'В абонементе не осталось сессий']); exit; }
    if (!empty($pkg['expires_at']) && strtotime($pkg['expires_at']) < time()) { http_response_code(400); echo json_encode(['error' => 'Срок абонемента истёк']); exit; }

    try {
        $st = $pdo->prepare("SELECT p.id, p.user_id, p.is_approved, u.is_frozen FROM psychologists p LEFT JOIN users u ON u.id = p.user_id WHERE p.id = ? LIMIT 1");
        $st->execute([$newPsyId]);
        $newPsy = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $newPsy = null; }
    if (!$newPsy || !$newPsy['is_approved'] || !empty($newPsy['is_frozen'])) {
        http_response_code(400); echo json_encode(['error' => 'Выбранный психолог недоступен']); exit;
    }

    try {
        $st = $pdo->prepare("UPDATE session_packages SET psychologist_id = ?, psychologist_user_id = ? WHERE id = ?");
        $st->execute([$newPsyId, $newPsy['user_id'], $pkgId]);
    } catch (Exception $e) {
        http_response_code(500); echo json_encode(['error' => 'Не удалось переназначить абонемент']); exit;
    }
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Неизвестное действие']);
