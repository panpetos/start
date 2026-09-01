<?php
/**
 * payouts.php — начисления психологам и учёт выплат.
 *
 * Зачем это отдельно от эквайринга. Кто бы ни принимал деньги — Робокасса,
 * ЮKassa или Сбер, — платформе всё равно нужно знать: сколько заработал каждый
 * психолог, сколько удержано комиссии и что ему ещё не выплачено. Пока провайдер
 * не выбран окончательно, это единственное место, где такой учёт вообще есть,
 * и выплаты можно делать руками, ничего не теряя.
 *
 * Начисления не пишутся из платёжных обработчиков, а ДОСОЗДАЮТСЯ сверкой
 * (action=sync) по успешным оплатам. Так учёт не зависит от того, какой провайдер
 * принял платёж, и сам чинится, если обработчик что-то не записал.
 *
 * Действия:
 *   GET  ?action=summary            — сводка по психологам (админ)
 *   GET  ?action=list[&status=&psy=]— список начислений (админ)
 *   GET  ?action=mine               — свои начисления (психолог)
 *   POST ?action=sync               — досоздать начисления по оплаченным записям (админ)
 *   POST ?action=mark-paid {ids, note} — отметить выплаченными (админ)
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
require_once __DIR__ . '/settings_lib.php';
if (!function_exists('psy_schema_once')) require_once __DIR__ . '/schema_util.php';

$pdo = function_exists('getDB') ? getDB()
     : (function_exists('getDbConnection') ? getDbConnection()
     : (function_exists('getPDO') ? getPDO() : null));
if (!$pdo) { http_response_code(500); echo json_encode(['error' => 'Нет подключения к БД']); exit; }

function poOut($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if (session_status() === PHP_SESSION_NONE) session_start();
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) poOut(['error' => 'Требуется авторизация'], 401);

$role = '';
try {
    $st = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    $st->execute([$userId]);
    $role = (string)$st->fetchColumn();
} catch (Exception $e) {}
$isAdmin = ($role === 'admin');

// ── Схема ────────────────────────────────────────────────────────────────────
psy_schema_once('psy_payouts_schema_v1', 3600, function () use ($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS psy_payouts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        appointment_id VARCHAR(64) NOT NULL,
        psychologist_id VARCHAR(64) NOT NULL,
        amount_total DECIMAL(10,2) NOT NULL DEFAULT 0,
        commission_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
        commission_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        amount_to_psy DECIMAL(10,2) NOT NULL DEFAULT 0,
        status VARCHAR(16) NOT NULL DEFAULT 'accrued',   -- accrued | paid | canceled
        note VARCHAR(255) NULL,
        paid_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uniq_appt (appointment_id),
        INDEX idx_psy (psychologist_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
});

/** Комиссия платформы в процентах (настройка platform_commission). */
function poCommissionPct(PDO $pdo) {
    $v = (float)psySetting($pdo, 'platform_commission', '0');
    if ($v < 0) $v = 0;
    if ($v > 100) $v = 100;
    return $v;
}

/**
 * Сверка: создать начисления по успешно оплаченным записям, которых ещё нет.
 *
 * Комиссия берётся на момент сверки и сохраняется в самой строке — если позже
 * поменять процент в настройках, уже сделанные начисления не «поедут задним числом».
 */
function poSync(PDO $pdo) {
    $pct = poCommissionPct($pdo);
    $rows = [];
    try {
        // Берём оплаченные записи, для которых начисления ещё нет
        $st = $pdo->query("SELECT a.id AS appt, a.psychologist_id, a.price,
                                  COALESCE(SUM(p.amount), 0) AS paid
                             FROM appointments a
                             JOIN payments p ON p.appointment_id = a.id AND p.status = 'success'
                        LEFT JOIN psy_payouts po ON po.appointment_id = a.id
                            WHERE po.id IS NULL
                         GROUP BY a.id, a.psychologist_id, a.price
                            LIMIT 500");
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return ['ok' => false, 'error' => 'Не удалось прочитать оплаты: ' . $e->getMessage()];
    }
    $added = 0;
    $ins = $pdo->prepare("INSERT IGNORE INTO psy_payouts
        (appointment_id, psychologist_id, amount_total, commission_pct, commission_amount, amount_to_psy, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 'accrued', NOW())");
    foreach ($rows as $r) {
        // Платим с фактически оплаченного, а не с цены в записи: если оплата была
        // частичной или по акции, психолог должен получить долю от реальных денег.
        $total = (float)($r['paid'] > 0 ? $r['paid'] : $r['price']);
        if ($total <= 0) continue;
        $comm = round($total * $pct / 100, 2);
        $toPsy = round($total - $comm, 2);
        try { $ins->execute([$r['appt'], (string)$r['psychologist_id'], $total, $pct, $comm, $toPsy]); $added++; }
        catch (Exception $e) {}
    }
    return ['ok' => true, 'добавлено' => $added, 'комиссия_%' => $pct];
}

$action = $_GET['action'] ?? '';
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

// ── Психолог: свои начисления ────────────────────────────────────────────────
if ($action === 'mine') {
    $pid = '';
    try {
        $st = $pdo->prepare("SELECT id FROM psychologists WHERE user_id = ? LIMIT 1");
        $st->execute([$userId]);
        $pid = (string)$st->fetchColumn();
    } catch (Exception $e) {}
    if ($pid === '') poOut(['ok' => true, 'итого' => ['начислено' => 0, 'выплачено' => 0, 'к_выплате' => 0], 'data' => []]);
    poSync($pdo);   // чтобы человек видел свежие начисления, а не вчерашние
    $data = []; $acc = 0; $paid = 0;
    try {
        $st = $pdo->prepare("SELECT po.*, a.date_time
                               FROM psy_payouts po
                          LEFT JOIN appointments a ON a.id = po.appointment_id
                              WHERE po.psychologist_id = ? AND po.status <> 'canceled'
                           ORDER BY po.id DESC LIMIT 300");
        $st->execute([$pid]);
        $data = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($data as $r) {
            if ($r['status'] === 'paid') $paid += (float)$r['amount_to_psy'];
            else $acc += (float)$r['amount_to_psy'];
        }
    } catch (Exception $e) {}
    poOut(['ok' => true, 'итого' => ['начислено' => round($acc + $paid, 2),
           'выплачено' => round($paid, 2), 'к_выплате' => round($acc, 2)], 'data' => $data]);
}

// ── Дальше только администратор ──────────────────────────────────────────────
if (!$isAdmin) poOut(['error' => 'Доступ только для администратора'], 403);

if ($action === 'sync') {
    poOut(poSync($pdo));
}

if ($action === 'summary') {
    poSync($pdo);
    $out = [];
    try {
        $st = $pdo->query("SELECT po.psychologist_id,
                                  SUM(CASE WHEN po.status = 'accrued' THEN po.amount_to_psy ELSE 0 END) AS to_pay,
                                  SUM(CASE WHEN po.status = 'paid' THEN po.amount_to_psy ELSE 0 END) AS paid,
                                  SUM(CASE WHEN po.status <> 'canceled' THEN po.commission_amount ELSE 0 END) AS commission,
                                  COUNT(*) AS cnt
                             FROM psy_payouts po
                            WHERE po.status <> 'canceled'
                         GROUP BY po.psychologist_id");
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        // Имена психологов: отдельным запросом, чтобы сводка не падала из-за схемы
        $names = [];
        try {
            foreach ($pdo->query("SELECT p.id, u.first_name, u.last_name
                                    FROM psychologists p LEFT JOIN users u ON u.id = p.user_id") as $p) {
                $n = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
                $names[(string)$p['id']] = $n !== '' ? $n : 'Психолог';
            }
        } catch (Exception $e) {}
        foreach ($rows as $r) {
            $out[] = [
                'psychologist_id' => (string)$r['psychologist_id'],
                'имя' => $names[(string)$r['psychologist_id']] ?? 'Психолог',
                'к_выплате' => round((float)$r['to_pay'], 2),
                'выплачено' => round((float)$r['paid'], 2),
                'комиссия_платформы' => round((float)$r['commission'], 2),
                'сессий' => (int)$r['cnt'],
            ];
        }
        usort($out, fn($a, $b) => $b['к_выплате'] <=> $a['к_выплате']);
    } catch (Exception $e) {}
    poOut(['ok' => true, 'комиссия_%' => poCommissionPct($pdo), 'data' => $out]);
}

if ($action === 'list') {
    $status = (string)($_GET['status'] ?? '');
    $psy = (string)($_GET['psy'] ?? '');
    $where = []; $args = [];
    if ($status !== '') { $where[] = 'po.status = ?'; $args[] = $status; }
    if ($psy !== '')    { $where[] = 'po.psychologist_id = ?'; $args[] = $psy; }
    $sql = "SELECT po.*, a.date_time FROM psy_payouts po
       LEFT JOIN appointments a ON a.id = po.appointment_id"
       . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
       . " ORDER BY po.id DESC LIMIT 500";
    try {
        $st = $pdo->prepare($sql); $st->execute($args);
        poOut(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { poOut(['ok' => true, 'data' => []]); }
}

if ($action === 'mark-paid' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ids = $body['ids'] ?? [];
    $psy = (string)($body['psychologist_id'] ?? '');
    $note = mb_substr(trim((string)($body['note'] ?? '')), 0, 255);
    try {
        if ($psy !== '') {
            // Отметить всё, что причитается одному психологу — обычный случай:
            // перевели человеку сумму разом, а не по каждой сессии отдельно.
            $st = $pdo->prepare("UPDATE psy_payouts SET status = 'paid', paid_at = NOW(), note = ?
                                  WHERE psychologist_id = ? AND status = 'accrued'");
            $st->execute([$note, $psy]);
            poOut(['ok' => true, 'отмечено' => $st->rowCount()]);
        }
        if (!is_array($ids) || !$ids) poOut(['error' => 'Нечего отмечать'], 400);
        $ids = array_slice(array_map('intval', $ids), 0, 500);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("UPDATE psy_payouts SET status = 'paid', paid_at = NOW(), note = ?
                              WHERE id IN ($in) AND status = 'accrued'");
        $st->execute(array_merge([$note], $ids));
        poOut(['ok' => true, 'отмечено' => $st->rowCount()]);
    } catch (Exception $e) { poOut(['error' => 'Не удалось отметить'], 500); }
}

poOut(['error' => 'Неизвестное действие'], 400);
