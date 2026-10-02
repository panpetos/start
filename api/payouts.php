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
 *   POST ?action=npd-recheck {psychologist_id} — перепроверить статус НПД в ФНС (админ)
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
require_once __DIR__ . '/npd_lib.php';
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
psy_schema_once('psy_payouts_schema_v3', 3600, function () use ($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS psy_npd_checks (
        psychologist_id VARCHAR(64) NOT NULL PRIMARY KEY,
        inn VARCHAR(16) NULL,
        status VARCHAR(8) NOT NULL DEFAULT 'unknown',   -- yes | no | unknown | none
        checked_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
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
    // Запросы психологов на вывод накопленного баланса (раз в N дней, по умолчанию 14).
    $pdo->exec("CREATE TABLE IF NOT EXISTS psy_payout_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        psychologist_id VARCHAR(64) NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,       -- баланс на момент запроса
        status VARCHAR(16) NOT NULL DEFAULT 'pending', -- pending | paid | rejected
        note VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        resolved_at DATETIME NULL,
        INDEX idx_psy (psychologist_id, status),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
});

/** Минимальный интервал между запросами на вывод, дней (настройка payout_min_days, по умолчанию 14). */
function poMinDays(PDO $pdo) {
    $v = (int)psySetting($pdo, 'payout_min_days', '14');
    return $v < 0 ? 0 : $v;
}

/** Накопленный баланс психолога к выплате (сумма amount_to_psy по статусу accrued). */
function poAccruedBalance(PDO $pdo, string $pid): float {
    try {
        $st = $pdo->prepare("SELECT COALESCE(SUM(amount_to_psy),0) FROM psy_payouts WHERE psychologist_id = ? AND status = 'accrued'");
        $st->execute([$pid]);
        return round((float)$st->fetchColumn(), 2);
    } catch (Exception $e) { return 0.0; }
}

/** Текущий (pending) запрос на вывод, если есть. */
function poPendingRequest(PDO $pdo, string $pid) {
    try {
        $st = $pdo->prepare("SELECT * FROM psy_payout_requests WHERE psychologist_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
        $st->execute([$pid]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) { return null; }
}

/** Дата последнего запроса на вывод (любого статуса) — для контроля интервала. */
function poLastRequestAt(PDO $pdo, string $pid) {
    try {
        $st = $pdo->prepare("SELECT MAX(created_at) FROM psy_payout_requests WHERE psychologist_id = ?");
        $st->execute([$pid]);
        return $st->fetchColumn() ?: null;
    } catch (Exception $e) { return null; }
}

/**
 * Закрыть ожидающие заявки на вывод как оплаченные. Побочная работа: свой try/catch,
 * молчит при любой ошибке — выплата уже отмечена, человек не должен пострадать от сбоя тут.
 */
function poCloseRequests(PDO $pdo, array $pids, string $note): void {
    foreach (array_unique(array_filter($pids)) as $pid) {
        try {
            $st = $pdo->prepare("UPDATE psy_payout_requests SET status='paid', resolved_at=NOW(), note=?
                                  WHERE psychologist_id=? AND status='pending'");
            $st->execute([mb_substr($note, 0, 255), (string)$pid]);
        } catch (Exception $e) {}
    }
}

/** Комиссия платформы в процентах (настройка platform_commission). */
function poCommissionPct(PDO $pdo) {
    $v = (float)psySetting($pdo, 'platform_commission', '0');
    if ($v < 0) $v = 0;
    if ($v > 100) $v = 100;
    return $v;
}

/**
 * ИНН психолога. Лежит в psychologist_credentials (type='inn', сам ИНН в поле name) —
 * туда его кладёт регистрация; колонки psychologists.inn на проде нет. Ищем и по
 * psychologist_id, и по user_id: у старых записей заполнено только одно из двух.
 */
function poPsyInn(PDO $pdo, string $pid): string {
    $uid = 0;
    try {
        $st = $pdo->prepare("SELECT user_id FROM psychologists WHERE id = ? LIMIT 1");
        $st->execute([$pid]);
        $uid = $st->fetchColumn();
    } catch (Exception $e) {}
    try {
        $st = $pdo->prepare("SELECT name FROM psychologist_credentials
                              WHERE type = 'inn' AND (psychologist_id = ? OR user_id = ?)
                              ORDER BY id DESC LIMIT 1");
        $st->execute([$pid, $uid ?: 0]);
        return preg_replace('/\D+/', '', (string)$st->fetchColumn());
    } catch (Exception $e) {}
    return '';
}

/** Сохранённый статус НПД. Только чтение кэша, без похода в ФНС. */
function poNpdCached(PDO $pdo, string $pid): array {
    try {
        $st = $pdo->prepare("SELECT inn, status, checked_at FROM psy_npd_checks WHERE psychologist_id = ? LIMIT 1");
        $st->execute([$pid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) return ['инн' => (string)$r['inn'], 'статус' => (string)$r['status'], 'проверено' => $r['checked_at']];
    } catch (Exception $e) {}
    return ['инн' => '', 'статус' => 'unknown', 'проверено' => null];
}

/**
 * Проверить статус самозанятого в ФНС и запомнить.
 *
 * Платформа-агент обязана убедиться, что исполнитель на момент расчёта — плательщик
 * НПД: иначе чек уйдёт не с тем признаком, а человеку прилетит доход, который он не
 * сможет провести через «Мой налог». Ответ кэшируем на неделю: у ФНС нет обязательств
 * по нагрузке, а статус меняется редко.
 *
 * status: yes — подтверждён, no — ФНС статус не подтверждает, unknown — сервис не
 * ответил, none — ИНН вообще не указан.
 */
function poNpdCheck(PDO $pdo, string $pid, bool $force = false): array {
    $cached = poNpdCached($pdo, $pid);
    $inn = poPsyInn($pdo, $pid);
    if ($inn === '') {
        poNpdRemember($pdo, $pid, '', 'none');
        return ['инн' => '', 'статус' => 'none', 'проверено' => null];
    }
    // «unknown» не считаем свежим: сервис мог просто моргнуть, спрашиваем снова.
    if (!$force && $cached['инн'] === $inn && $cached['проверено']
        && $cached['статус'] !== 'unknown'
        && strtotime((string)$cached['проверено']) > time() - 7 * 86400) {
        return $cached;
    }
    if (!validInn($inn)) {
        poNpdRemember($pdo, $pid, $inn, 'no');
        return ['инн' => $inn, 'статус' => 'no', 'проверено' => date('Y-m-d H:i:s'), 'note' => 'ИНН не проходит проверку контрольной суммы'];
    }
    $res = checkSelfEmployed($inn);
    $status = $res === true ? 'yes' : ($res === false ? 'no' : 'unknown');
    poNpdRemember($pdo, $pid, $inn, $status);
    return ['инн' => $inn, 'статус' => $status, 'проверено' => date('Y-m-d H:i:s')];
}

/**
 * Запомнить результат проверки. Отдельная функция со своим try/catch: это побочная
 * работа, и если запись в кэш не удалась (нет таблицы, ALTER ещё не прошёл), человек
 * всё равно должен получить ответ проверки, а выплата — не застрять.
 */
function poNpdRemember(PDO $pdo, string $pid, string $inn, string $status): void {
    try {
        $st = $pdo->prepare("INSERT INTO psy_npd_checks (psychologist_id, inn, status, checked_at)
                             VALUES (?, ?, ?, NOW())
                             ON DUPLICATE KEY UPDATE inn = VALUES(inn), status = VALUES(status), checked_at = NOW()");
        $st->execute([$pid, $inn, $status]);
    } catch (Exception $e) {}
}

/** Человеческая подпись статуса — чтобы админка не расшифровывала коды сама. */
function poNpdLabel(string $status): string {
    switch ($status) {
        case 'yes':  return 'Самозанятый (НПД) подтверждён';
        case 'no':   return 'ФНС не подтверждает статус НПД';
        case 'none': return 'ИНН не указан';
        default:     return 'Статус не проверен (ФНС не ответила)';
    }
}

/** Есть ли колонка в таблице (для мягкой работы до ALTER). */
function poHasCol(PDO $pdo, string $table, string $col): bool {
    try { return (bool)$pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($col))->fetch(); }
    catch (Exception $e) { return false; }
}

/**
 * Сверка: создать начисления по ЗАВЕРШЁННЫМ и ПОДТВЕРЖДЁННЫМ оплаченным сессиям.
 *
 * Деньги психологу начисляются только когда сессия проведена (appointments.status
 * = 'completed') и подтверждена анти-фродом (verified=1) — нельзя получить выплату
 * без реально проведённой сессии. Пока колонки verified нет (до ALTER из sessions.php),
 * опираемся только на статус 'completed'.
 *
 * Комиссия берётся на момент сверки и сохраняется в самой строке — если позже
 * поменять процент в настройках, уже сделанные начисления не «поедут задним числом».
 */
function poSync(PDO $pdo) {
    $pct = poCommissionPct($pdo);
    $rows = [];
    // Начисляем только за проведённые сессии; verified=1 если колонка уже есть.
    $cond = "a.status = 'completed'";
    if (poHasCol($pdo, 'appointments', 'verified')) $cond .= " AND a.verified = 1";
    try {
        // Берём завершённые+подтверждённые оплаченные записи, для которых начисления ещё нет
        $st = $pdo->query("SELECT a.id AS appt, a.psychologist_id, a.price,
                                  COALESCE(SUM(p.amount), 0) AS paid
                             FROM appointments a
                             JOIN payments p ON p.appointment_id = a.id AND p.status = 'success'
                        LEFT JOIN psy_payouts po ON po.appointment_id = a.id
                            WHERE po.id IS NULL AND $cond
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
    $npd = poNpdCached($pdo, $pid);
    // Статус заявки на вывод + можно ли подать новую (интервал + наличие баланса).
    $pending = poPendingRequest($pdo, $pid);
    $minDays = poMinDays($pdo);
    $lastAt = poLastRequestAt($pdo, $pid);
    $tooSoon = false; $nextAt = null;
    if ($minDays > 0 && $lastAt) {
        $next = strtotime($lastAt) + $minDays * 86400;
        if ($next > time()) { $tooSoon = true; $nextAt = date('Y-m-d', $next); }
    }
    $canRequest = !$pending && !$tooSoon && round($acc, 2) > 0;
    poOut(['ok' => true, 'итого' => ['начислено' => round($acc + $paid, 2),
           'выплачено' => round($paid, 2), 'к_выплате' => round($acc, 2)],
           'налоговый_статус' => ['код' => $npd['статус'], 'текст' => poNpdLabel($npd['статус']),
                                  'проверено' => $npd['проверено']],
           'вывод' => [
               'баланс' => round($acc, 2),
               'заявка' => $pending ? ['сумма' => (float)$pending['amount'], 'создана' => $pending['created_at']] : null,
               'можно_запросить' => $canRequest,
               'мин_интервал_дней' => $minDays,
               'следующий_запрос_с' => $nextAt,
           ],
           'data' => $data]);
}

// ── Психолог: запросить вывод накопленного баланса ───────────────────────────
if ($action === 'request' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pid = '';
    try {
        $st = $pdo->prepare("SELECT id FROM psychologists WHERE user_id = ? LIMIT 1");
        $st->execute([$userId]);
        $pid = (string)$st->fetchColumn();
    } catch (Exception $e) {}
    if ($pid === '') poOut(['error' => 'Профиль психолога не найден'], 403);

    if (poPendingRequest($pdo, $pid)) poOut(['error' => 'Заявка на вывод уже подана и ожидает обработки.'], 409);

    $minDays = poMinDays($pdo);
    $lastAt = poLastRequestAt($pdo, $pid);
    if ($minDays > 0 && $lastAt) {
        $next = strtotime($lastAt) + $minDays * 86400;
        if ($next > time()) {
            poOut(['error' => 'Вывод можно запрашивать раз в ' . $minDays . ' дней. Следующий запрос — с ' . date('d.m.Y', $next) . '.'], 429);
        }
    }

    poSync($pdo); // свежие начисления перед расчётом баланса
    $bal = poAccruedBalance($pdo, $pid);
    if ($bal <= 0) poOut(['error' => 'Нет средств к выводу.'], 400);

    // Напомним психологу про реквизиты — без них выплату не отправить (но заявку примем).
    $reqNote = '';
    try {
        $st = $pdo->prepare("SELECT (enc_blob IS NOT NULL AND enc_blob <> '') FROM psy_payout_requisites WHERE psychologist_id = ? LIMIT 1");
        $st->execute([$pid]);
        if (!$st->fetchColumn()) $reqNote = 'Укажите реквизиты для выплаты в личном кабинете, иначе перевод не отправить.';
    } catch (Exception $e) {}

    try {
        $st = $pdo->prepare("INSERT INTO psy_payout_requests (psychologist_id, amount, status, created_at) VALUES (?, ?, 'pending', NOW())");
        $st->execute([$pid, $bal]);
    } catch (Exception $e) { poOut(['error' => 'Не удалось создать заявку'], 500); }
    poOut(['ok' => true, 'запрошено' => $bal, 'note' => $reqNote]);
}

// ── Дальше только администратор ──────────────────────────────────────────────
if (!$isAdmin) poOut(['error' => 'Доступ только для администратора'], 403);

// ── Админ: список заявок на вывод ─────────────────────────────────────────────
if ($action === 'requests') {
    $status = (string)($_GET['status'] ?? 'pending');
    $out = [];
    try {
        $sql = "SELECT * FROM psy_payout_requests" . ($status !== 'all' ? " WHERE status = ?" : "") . " ORDER BY id DESC LIMIT 300";
        $st = $pdo->prepare($sql);
        $st->execute($status !== 'all' ? [$status] : []);
        $reqs = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $reqs = []; }
    // Имена и актуальный баланс/НПД/реквизиты — чтобы админ решал по полной картине.
    $names = [];
    try {
        foreach ($pdo->query("SELECT p.id, u.first_name, u.last_name FROM psychologists p LEFT JOIN users u ON u.id = p.user_id") as $p) {
            $n = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
            $names[(string)$p['id']] = $n !== '' ? $n : 'Психолог';
        }
    } catch (Exception $e) {}
    foreach ($reqs as $r) {
        $pid = (string)$r['psychologist_id'];
        $hasReq = false;
        try {
            $st = $pdo->prepare("SELECT (enc_blob IS NOT NULL AND enc_blob <> '') FROM psy_payout_requisites WHERE psychologist_id = ? LIMIT 1");
            $st->execute([$pid]); $hasReq = (bool)$st->fetchColumn();
        } catch (Exception $e) {}
        $npd = poNpdCached($pdo, $pid);
        $out[] = [
            'id' => (int)$r['id'],
            'psychologist_id' => $pid,
            'имя' => $names[$pid] ?? 'Психолог',
            'сумма_заявки' => (float)$r['amount'],
            'баланс_сейчас' => poAccruedBalance($pdo, $pid),
            'статус' => $r['status'],
            'создана' => $r['created_at'],
            'обработана' => $r['resolved_at'],
            'реквизиты_есть' => $hasReq,
            'нпд' => $npd['статус'],
            'нпд_текст' => poNpdLabel($npd['статус']),
        ];
    }
    poOut(['ok' => true, 'data' => $out]);
}

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
            $pid = (string)$r['psychologist_id'];
            // Статус НПД — только из кэша: в сводке могут быть десятки психологов,
            // и ходить за каждым в ФНС значило бы вешать страницу на полминуты.
            // Живая проверка — по кнопке (npd-recheck) и перед самой выплатой.
            $npd = poNpdCached($pdo, $pid);
            $out[] = [
                'psychologist_id' => $pid,
                'имя' => $names[$pid] ?? 'Психолог',
                'к_выплате' => round((float)$r['to_pay'], 2),
                'выплачено' => round((float)$r['paid'], 2),
                'комиссия_платформы' => round((float)$r['commission'], 2),
                'сессий' => (int)$r['cnt'],
                'нпд' => $npd['статус'],
                'нпд_текст' => poNpdLabel($npd['статус']),
                'нпд_проверено' => $npd['проверено'],
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

if ($action === 'request-reject' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($body['id'] ?? 0);
    $note = mb_substr(trim((string)($body['note'] ?? '')), 0, 255);
    if ($id <= 0) poOut(['error' => 'Не указана заявка'], 400);
    try {
        $st = $pdo->prepare("UPDATE psy_payout_requests SET status='rejected', resolved_at=NOW(), note=? WHERE id=? AND status='pending'");
        $st->execute([$note, $id]);
        poOut(['ok' => true, 'отклонено' => $st->rowCount()]);
    } catch (Exception $e) { poOut(['error' => 'Не удалось отклонить'], 500); }
}

if ($action === 'npd-recheck' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $psy = (string)($body['psychologist_id'] ?? '');
    if ($psy === '') poOut(['error' => 'Не указан психолог'], 400);
    $r = poNpdCheck($pdo, $psy, true);
    poOut(['ok' => true, 'нпд' => $r['статус'], 'нпд_текст' => poNpdLabel($r['статус']),
           'инн' => $r['инн'], 'проверено' => $r['проверено'], 'note' => $r['note'] ?? null]);
}

if ($action === 'mark-paid' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ids = $body['ids'] ?? [];
    $psy = (string)($body['psychologist_id'] ?? '');
    $note = mb_substr(trim((string)($body['note'] ?? '')), 0, 255);
    $force = !empty($body['force']);

    // Отмечать можно двумя способами: всё разом одному психологу или отдельные
    // строки по id. Проверять налоговый статус надо в обоих — иначе шлюз обходится
    // сменой способа отметки. Для строк берём их психологов из самих строк.
    $toCheck = $psy !== '' ? [$psy] : [];
    if ($psy === '' && is_array($ids) && $ids) {
        try {
            $tmp = array_slice(array_map('intval', $ids), 0, 500);
            $in = implode(',', array_fill(0, count($tmp), '?'));
            $st = $pdo->prepare("SELECT DISTINCT psychologist_id FROM psy_payouts WHERE id IN ($in)");
            $st->execute($tmp);
            $toCheck = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (Exception $e) {}
    }

    // Перед выплатой — свежая проверка статуса НПД. Отказываем только когда ФНС
    // ответила «нет»: неотвеченный сервис не повод задерживать людям деньги, но
    // предупредить об этом надо. Отметка «всё равно выплатил» остаётся за админом.
    foreach ($toCheck as $checkPsy) {
        if ($force) break;
        $npd = poNpdCheck($pdo, $checkPsy);
        if ($npd['статус'] === 'no' || $npd['статус'] === 'none') {
            poOut(['error' => 'npd', 'нпд' => $npd['статус'], 'сообщение' => poNpdLabel($npd['статус'])
                   . '. Платформа работает по агентской схеме: выплачивать можно только плательщику НПД.'
                   . ' Попросите психолога указать корректный ИНН и подтвердить самозанятость,'
                   . ' либо отметьте выплату принудительно, если рассчитались по другому договору.',
                   'psychologist_id' => $checkPsy, 'можно_принудительно' => true], 409);
        }
        if ($npd['статус'] === 'unknown' && strpos($note, 'НПД не проверен') === false) {
            $note = mb_substr(trim($note . ' [статус НПД не проверен: ФНС не ответила]'), 0, 255);
        }
    }
    try {
        if ($psy !== '') {
            // Отметить всё, что причитается одному психологу — обычный случай:
            // перевели человеку сумму разом, а не по каждой сессии отдельно.
            $st = $pdo->prepare("UPDATE psy_payouts SET status = 'paid', paid_at = NOW(), note = ?
                                  WHERE psychologist_id = ? AND status = 'accrued'");
            $st->execute([$note, $psy]);
            $cnt = $st->rowCount();
            poCloseRequests($pdo, [$psy], $note);
            poOut(['ok' => true, 'отмечено' => $cnt]);
        }
        if (!is_array($ids) || !$ids) poOut(['error' => 'Нечего отмечать'], 400);
        $ids = array_slice(array_map('intval', $ids), 0, 500);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("UPDATE psy_payouts SET status = 'paid', paid_at = NOW(), note = ?
                              WHERE id IN ($in) AND status = 'accrued'");
        $st->execute(array_merge([$note], $ids));
        $cnt = $st->rowCount();
        poCloseRequests($pdo, $toCheck, $note);
        poOut(['ok' => true, 'отмечено' => $cnt]);
    } catch (Exception $e) { poOut(['error' => 'Не удалось отметить'], 500); }
}

poOut(['error' => 'Неизвестное действие'], 400);
