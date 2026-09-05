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
 * POST ?action=set-role          {user_id, role: client|psychologist|admin}
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
// rtcSendDm — уведомления в чат о переносе/переназначении записи
@include_once __DIR__ . '/rtc_lib.php';
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
                'phone' => $u['phone'] ?? ($u['phone_number'] ?? ''),
                'role'  => $u['role'] ?? '',
                'avatar' => $u['avatar'] ?? '',
            ];
        }
    } catch (Exception $e) {}
    return $map;
}

/** Определяет имена колонок ключ/значение в таблице settings (схема может отличаться). */
function settingsCols(PDO $pdo): array {
    foreach (['settings', 'site_settings', 'options', 'config'] as $table) {
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) { continue; }
        if (!$cols) continue;
        $lc = array_map('strtolower', $cols);
        $k = null; $v = null;
        foreach (['setting_key', 'key_name', 'key', 'name', 'k', 'option_name', 'param', 'param_name', '`key`'] as $c) {
            $i = array_search($c, $lc, true); if ($i !== false) { $k = $cols[$i]; break; }
        }
        foreach (['setting_value', 'value', 'val', 'v', 'option_value', 'data'] as $c) {
            $i = array_search($c, $lc, true); if ($i !== false) { $v = $cols[$i]; break; }
        }
        if ($k && $v) return [$table, $k, $v];
    }
    return [null, null, null];
}

/** Ключи настроек, которые разрешено читать/писать из админки. */
/**
 * Сохранить ИНН психолога. ИНН один на человека: если запись уже есть — обновляем,
 * иначе создаём. Пустая строка стирает ИНН. Молчит при любой ошибке — это побочная
 * запись, она не должна ронять сохранение профиля.
 */
function adminSetPsychInn(PDO $pdo, $psychId, $userId, string $raw) {
    $inn = preg_replace('/\D+/', '', $raw);
    try {
        $st = $pdo->prepare("SELECT id FROM psychologist_credentials
                              WHERE type = 'inn' AND (psychologist_id = ? OR user_id = ?)
                              ORDER BY id DESC LIMIT 1");
        $st->execute([$psychId, $userId ?: 0]);
        $exist = $st->fetchColumn();
        if ($inn === '') {
            if ($exist) $pdo->prepare("DELETE FROM psychologist_credentials WHERE id = ?")->execute([$exist]);
            return;
        }
        if ($exist) {
            $pdo->prepare("UPDATE psychologist_credentials
                              SET name = ?, psychologist_id = COALESCE(psychologist_id, ?)
                            WHERE id = ?")->execute([$inn, $psychId, $exist]);
        } else {
            $pdo->prepare("INSERT INTO psychologist_credentials (user_id, psychologist_id, type, url, name, meta)
                           VALUES (?,?,'inn',NULL,?,NULL)")->execute([$userId, $psychId, $inn]);
        }
    } catch (Exception $e) {}
}

function allowedSettingKeys(): array {
    // Модель приёма оплаты и процент сервиса-сплита: без них новые поля тарификации
    // молча не сохранялись бы — список ключей белый, лишнее сюда не пройдёт.
    $keys = ['price_self', 'price_couple', 'price_teen', 'platform_commission', 'acquiring_fee', 'tax_rate',
             'payment_model', 'split_fee', 'split_fee_payer',
             // Промокод бесплатной записи (api/promo_booking.php). Без этих двух ключей
             // админка показывала «Сохранено», а значение молча не доходило до settings —
             // и промокод не срабатывал никогда.
             'free_promo_code', 'free_promo_limit', 'free_promo_fail_limit', 'free_promo_expires',
             // Эквайринг: чем принимаем оплату и тестовая сумма (см. sber_acquiring.php)
             'payment_provider', 'sber_test_amount',
             // Текст автоприветствия новому пользователю (api/welcome.php)
             'welcome_message'];
    foreach (['self', 'couple', 'teen'] as $t) {
        foreach (['enabled', 'price', 'title', 'duration', 'deadline'] as $f) {
            $keys[] = "promo_{$t}_{$f}";
        }
    }
    return $keys;
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
    // карта контактов по email (телефон/telegram/whatsapp/max из регистрации)
    $contacts = [];
    try {
        foreach ($pdo->query("SELECT * FROM psychologist_contacts LIMIT 5000")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $contacts[mb_strtolower((string)$c['email'])] = $c;
        }
    } catch (Exception $e) {}
    foreach ($rows as &$r) {
        $uid = (string)pick($r, ['user_id'], '');
        $r['name']  = $um[$uid]['name']  ?? '';
        $r['email'] = $um[$uid]['email'] ?? '';
        $c = $contacts[mb_strtolower((string)$r['email'])] ?? [];
        $r['phone']    = pick($r, ['phone', 'phone_number'], $c['phone'] ?? ($um[$uid]['phone'] ?? ''));
        $r['telegram'] = $c['telegram'] ?? '';
        $r['whatsapp'] = $c['whatsapp'] ?? '';
        $r['max_msg']  = $c['max_msg'] ?? '';
        if (empty($r['avatar']) && !empty($um[$uid]['avatar'])) $r['avatar'] = $um[$uid]['avatar'];
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

if ($action === 'subscribers') {
    $rows = safeRows($pdo, 'newsletter_subscribers', ['created_at', 'id'], 5000);
    echo json_encode(['ok' => true, 'data' => $rows]);
    exit;
}

if ($action === 'referrals') {
    $rows = safeRows($pdo, 'referral_sources', ['created_at', 'id'], 5000);
    // агрегируем по источнику
    $agg = [];
    foreach ($rows as $r) { $s = $r['source'] ?? '—'; $agg[$s] = ($agg[$s] ?? 0) + 1; }
    arsort($agg);
    echo json_encode(['ok' => true, 'data' => $rows, 'summary' => $agg]);
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
                'uid' => (string)($u['id'] ?? ''),   // для подстановки IP/устройства из журнала входов
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
                'uid' => $cid,   // клиент — для подстановки IP/устройства из журнала входов
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

if ($action === 'replacements') {
    $rows = safeRows($pdo, 'replacement_requests', ['created_at', 'id'], 2000);
    $um = usersMap($pdo); $pm = psychMap($pdo);
    foreach ($rows as &$r) {
        $cid = (string)pick($r, ['client_user_id', 'client_id'], '');
        $r['client_name']  = $um[$cid]['name']  ?? '';
        $r['client_email'] = $um[$cid]['email'] ?? '';
        $pid = (string)pick($r, ['current_psychologist_id'], '');
        // current_psychologist_id может быть как psychologists.id, так и user_id
        $puid = $pm[$pid] ?? $pid;
        $r['psychologist_name'] = $um[$puid]['name'] ?? ($pid ? ('ID ' . $pid) : '—');
    }
    echo json_encode(['ok' => true, 'data' => $rows]);
    exit;
}

if ($action === 'get-settings') {
    list($table, $kc, $vc) = settingsCols($pdo);
    $out = [];
    if ($table) {
        try {
            foreach ($pdo->query("SELECT `$kc` AS k, `$vc` AS v FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[$r['k']] = $r['v'];
            }
        } catch (Exception $e) {}
    }
    echo json_encode(['ok' => true, 'data' => $out, 'store' => $table]);
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

    if ($action === 'set-role') {
        $uid = $body['user_id'] ?? null;
        $role = (string)($body['role'] ?? '');
        if (!$uid || !in_array($role, ['client', 'psychologist', 'admin'], true)) {
            http_response_code(400); echo json_encode(['error' => 'Нужны user_id и корректная роль']); exit;
        }
        try {
            $st = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
            $st->execute([$role, $uid]);
            if ($role === 'psychologist') {
                // На случай если у пользователя ещё нет профиля психолога — создаём пустой заготовку
                $chk = $pdo->prepare("SELECT id FROM psychologists WHERE user_id = ? LIMIT 1");
                $chk->execute([$uid]);
                if (!$chk->fetchColumn()) {
                    try { $pdo->prepare("INSERT INTO psychologists (user_id, is_approved) VALUES (?, 0)")->execute([$uid]); } catch (Exception $e) {}
                }
            }
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            http_response_code(500); echo json_encode(['error' => 'Не удалось обновить роль (проверьте поле role)']);
        }
        exit;
    }

    if ($action === 'delete-user') {
        $email = trim((string)($body['email'] ?? ''));
        $uidReq = $body['user_id'] ?? null;
        if ($email === '' && !$uidReq) { http_response_code(400); echo json_encode(['error' => 'Нужен email или user_id']); exit; }
        // Найти пользователя
        try {
            if ($uidReq) { $st = $pdo->prepare("SELECT id, email, role FROM users WHERE id = ? LIMIT 1"); $st->execute([$uidReq]); }
            else { $st = $pdo->prepare("SELECT id, email, role FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1"); $st->execute([$email]); }
            $u = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Ошибка поиска пользователя']); exit; }
        if (!$u) { http_response_code(404); echo json_encode(['error' => 'Пользователь с таким email не найден']); exit; }
        if (($u['role'] ?? '') === 'admin') { http_response_code(403); echo json_encode(['error' => 'Нельзя удалить администратора']); exit; }
        if ((string)$u['id'] === (string)$userId) { http_response_code(403); echo json_encode(['error' => 'Нельзя удалить свой аккаунт']); exit; }
        $uid = $u['id']; $uemail = (string)$u['email'];

        $del = function ($sql, $params) use ($pdo) {
            try { $st = $pdo->prepare($sql); $st->execute($params); return $st->rowCount(); }
            catch (Exception $e) { return 0; }
        };
        // id психолога (если пользователь — психолог)
        $pids = [];
        try { $st = $pdo->prepare("SELECT id FROM psychologists WHERE user_id = ?"); $st->execute([$uid]); $pids = $st->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) {}
        // связанные записи и оплаты
        $apptIds = [];
        try { $st = $pdo->prepare("SELECT id FROM appointments WHERE client_id = ?"); $st->execute([$uid]); $apptIds = $st->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) {}
        foreach ($pids as $pid) {
            try { $st = $pdo->prepare("SELECT id FROM appointments WHERE psychologist_id = ?"); $st->execute([$pid]); $apptIds = array_merge($apptIds, $st->fetchAll(PDO::FETCH_COLUMN)); } catch (Exception $e) {}
        }
        $apptIds = array_values(array_unique($apptIds));
        foreach ($apptIds as $aid) { $del("DELETE FROM payments WHERE appointment_id = ?", [$aid]); }
        foreach ($apptIds as $aid) { $del("DELETE FROM appointments WHERE id = ?", [$aid]); }
        // контакты и профиль психолога
        $del("DELETE FROM psychologist_contacts WHERE LOWER(email) = LOWER(?)", [$uemail]);
        foreach ($pids as $pid) { $del("DELETE FROM psychologists WHERE id = ?", [$pid]); }
        // прочие возможные связи (best-effort: если таблицы/колонки нет — просто пропустится)
        // Сюда же — таблицы, заведённые позже: статус «в сети», отметки о прочтении,
        // реакции, коды входа по QR и расшифровки. Без них от удалённого аккаунта
        // оставались строки, привязанные к несуществующему пользователю.
        foreach (['consents' => 'user_id', 'notes' => 'user_id', 'reviews' => 'user_id', 'subscribers' => 'email',
                  'support_threads' => 'email', 'audit_log' => 'user_id', 'credentials' => 'user_id',
                  'user_presence' => 'user_id', 'chat_reads' => 'user_id', 'message_reactions' => 'user_id',
                  'qr_logins' => 'user_id', 'transcripts' => 'created_by'] as $tbl => $col) {
            $del("DELETE FROM `$tbl` WHERE `$col` = ?", [$col === 'email' ? $uemail : $uid]);
        }
        // сам пользователь
        try {
            $st = $pdo->prepare("DELETE FROM users WHERE id = ?"); $st->execute([$uid]);
            echo json_encode(['ok' => true, 'deleted_email' => $uemail, 'appointments' => count($apptIds), 'psychologist' => count($pids) > 0]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Не удалось удалить пользователя — остались связанные записи. Сообщите разработчику.']);
        }
        exit;
    }

    if ($action === 'update-appointment') {
        // Переназначить / перенести / отменить запись — то, что нужно техподдержке.
        // Раньше «замена психолога» была только очередью заявок (replacement_requests):
        // клиент просил, админ помечал статус, а сама запись оставалась у прежнего
        // специалиста и в прежнем времени. Здесь запись меняется по-настоящему.
        //
        // Меняем только то, что прислали: пустое поле — «не трогать».
        $aid = trim((string)($body['id'] ?? ''));
        if ($aid === '') { http_response_code(400); echo json_encode(['error' => 'Нужен id записи']); exit; }

        try {
            $st = $pdo->prepare("SELECT * FROM appointments WHERE id = ? LIMIT 1");
            $st->execute([$aid]);
            $appt = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $appt = null; }
        if (!$appt) { http_response_code(404); echo json_encode(['error' => 'Запись не найдена']); exit; }

        $oldPsyId = (string)($appt['psychologist_id'] ?? '');
        $oldWhen  = (string)($appt['date_time'] ?? '');
        $clientId = (string)($appt['client_id'] ?? '');

        $newPsyId = trim((string)($body['psychologist_id'] ?? ''));
        $newWhenIn = trim((string)($body['date_time'] ?? ''));
        $newFormat = trim((string)($body['format'] ?? ''));
        $newStatus = trim((string)($body['status'] ?? ''));

        // Новый психолог должен существовать
        if ($newPsyId !== '' && $newPsyId !== $oldPsyId) {
            try {
                $st = $pdo->prepare("SELECT id FROM psychologists WHERE id = ? LIMIT 1");
                $st->execute([$newPsyId]);
                if (!$st->fetchColumn()) { http_response_code(400); echo json_encode(['error' => 'Такого психолога нет']); exit; }
            } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось проверить психолога']); exit; }
        }

        // Время принимаем и с «T», и с пробелом — из datetime-local приходит с «T»
        $newWhen = '';
        if ($newWhenIn !== '') {
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $newWhenIn)
               ?: DateTime::createFromFormat('Y-m-d\TH:i:s', $newWhenIn)
               ?: DateTime::createFromFormat('Y-m-d\TH:i', $newWhenIn)
               ?: DateTime::createFromFormat('Y-m-d H:i', $newWhenIn);
            if (!$dt) { http_response_code(400); echo json_encode(['error' => 'Не понял дату и время']); exit; }
            $newWhen = $dt->format('Y-m-d H:i:s');
        }

        if ($newStatus !== '' && !in_array($newStatus, ['scheduled', 'completed', 'cancelled', 'pending_payment', 'no_show'], true)) {
            http_response_code(400); echo json_encode(['error' => 'Неизвестный статус']); exit;
        }
        if ($newFormat !== '' && !in_array($newFormat, ['video', 'audio', 'chat'], true)) {
            http_response_code(400); echo json_encode(['error' => 'Неизвестный формат']); exit;
        }

        // Занятость: у КОНЕЧНОГО психолога в КОНЕЧНОЕ время не должно быть другой записи.
        $checkPsy  = $newPsyId !== '' ? $newPsyId : $oldPsyId;
        $checkWhen = $newWhen  !== '' ? $newWhen  : $oldWhen;
        $finalStatus = $newStatus !== '' ? $newStatus : (string)($appt['status'] ?? '');
        if ($finalStatus !== 'cancelled' && $checkPsy !== '' && $checkWhen !== '') {
            try {
                $st = $pdo->prepare("SELECT id FROM appointments
                                      WHERE psychologist_id = ? AND date_time = ?
                                        AND status != 'cancelled' AND id != ? LIMIT 1");
                $st->execute([$checkPsy, $checkWhen, $aid]);
                if ($st->fetchColumn()) { http_response_code(409); echo json_encode(['error' => 'У этого психолога уже занято это время']); exit; }
            } catch (Exception $e) {}
        }

        // Собираем UPDATE только из присланных полей
        $set = []; $args = [];
        if ($newPsyId !== '' && $newPsyId !== $oldPsyId) { $set[] = 'psychologist_id = ?'; $args[] = $newPsyId; }
        if ($newWhen  !== '' && $newWhen  !== $oldWhen)  { $set[] = 'date_time = ?';       $args[] = $newWhen; }
        if ($newFormat !== '') { $set[] = 'format = ?'; $args[] = $newFormat; }
        if ($newStatus !== '') { $set[] = 'status = ?'; $args[] = $newStatus; }
        if (!$set) { echo json_encode(['ok' => true, 'changed' => false]); exit; }

        $args[] = $aid;
        try {
            $pdo->prepare("UPDATE appointments SET " . implode(', ', $set) . " WHERE id = ?")->execute($args);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Не удалось изменить запись: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ── Уведомления в чат ────────────────────────────────────────────────
        // Человек не должен узнавать о переносе, случайно заглянув в кабинет.
        $notified = [];
        if (!empty($body['notify'])) {
            $pm = psychMap($pdo);          // profile id => user_id
            $um = usersMap($pdo);
            $nameOf = function ($psyId) use ($pm, $um) {
                $uid = $pm[$psyId] ?? '';
                return $um[$uid]['name'] ?? 'специалист';
            };
            $whenHuman = function ($sqlDt) {
                if ($sqlDt === '') return '';
                $ts = strtotime($sqlDt);
                return $ts ? date('d.m.Y H:i', $ts) : $sqlDt;
            };
            $lines = [];
            if ($newPsyId !== '' && $newPsyId !== $oldPsyId) {
                $lines[] = 'специалист: ' . $nameOf($oldPsyId) . ' → ' . $nameOf($newPsyId);
            }
            if ($newWhen !== '' && $newWhen !== $oldWhen) {
                $lines[] = 'время: ' . $whenHuman($oldWhen) . ' → ' . $whenHuman($newWhen);
            }
            if ($newStatus === 'cancelled') $lines[] = 'запись отменена';
            elseif ($newStatus !== '') $lines[] = 'статус: ' . $newStatus;
            if ($newFormat !== '') $lines[] = 'формат: ' . $newFormat;

            if ($lines) {
                $text = "🛠 Изменение по вашей записи

• " . implode("
• ", $lines)
                      . "

Изменение внесла поддержка psytalk.pro. Если что-то не так — ответьте на это сообщение.";
                if (function_exists('rtcSendDm')) {
                    // Клиенту
                    if ($clientId !== '') { rtcSendDm($pdo, $userId, $clientId, $text, false); $notified[] = 'client'; }
                    // Прежнему специалисту — если его сняли с записи
                    if ($newPsyId !== '' && $newPsyId !== $oldPsyId) {
                        $oldUid = $pm[$oldPsyId] ?? '';
                        if ($oldUid !== '') { rtcSendDm($pdo, $userId, $oldUid, $text, false); $notified[] = 'old_psy'; }
                    }
                    // Конечному специалисту
                    $newUid = $pm[$checkPsy] ?? '';
                    if ($newUid !== '') { rtcSendDm($pdo, $userId, $newUid, $text, false); $notified[] = 'psy'; }
                }
            }
        }

        echo json_encode(['ok' => true, 'changed' => true, 'notified' => $notified], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete-appointment') {
        // Удалить одну запись со всем, что на неё ссылается.
        //
        // ПОЧЕМУ НЕ СПИСКОМ ИМЁН ТАБЛИЦ. Сначала здесь стояли три знакомых таблицы
        // (payments, promo_bookings, free_intro_bookings) — и удаление падало с
        // «Не удалось удалить запись»: на appointments ссылается кто-то ещё, а какой
        // именно внешний ключ мешает, угадывать бессмысленно. Теперь зависимые
        // таблицы спрашиваем у самой базы (information_schema) и чистим их все.
        // Что не нашлось по внешним ключам — добиваем по известным именам колонок.
        $aid = trim((string)($body['id'] ?? $body['appointment_id'] ?? ''));
        if ($aid === '') { http_response_code(400); echo json_encode(['error' => 'Нужен id записи']); exit; }
        try {
            $st = $pdo->prepare("SELECT id FROM appointments WHERE id = ? LIMIT 1");
            $st->execute([$aid]);
            if (!$st->fetchColumn()) { http_response_code(404); echo json_encode(['error' => 'Запись не найдена']); exit; }

            $cleared = [];
            $wipe = function ($table, $col) use ($pdo, $aid, &$cleared) {
                try {
                    $st = $pdo->prepare("DELETE FROM `$table` WHERE `$col` = ?");
                    $st->execute([$aid]);
                    if ($st->rowCount() > 0) $cleared[] = $table . ':' . $st->rowCount();
                    return true;
                } catch (Exception $e) { return false; }
            };

            // 1. Всё, что связано внешним ключом — узнаём у базы, а не гадаем.
            $seen = [];
            try {
                $fk = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME
                                     FROM information_schema.KEY_COLUMN_USAGE
                                    WHERE REFERENCED_TABLE_NAME = 'appointments'
                                      AND TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($fk as $row) {
                    $t = (string)($row['TABLE_NAME'] ?? ''); $c = (string)($row['COLUMN_NAME'] ?? '');
                    if ($t === '' || $c === '') continue;
                    $seen[$t . '.' . $c] = true;
                    $wipe($t, $c);
                }
            } catch (Exception $e) { /* нет прав на information_schema — идём дальше */ }

            // 2. Таблицы БЕЗ внешнего ключа, но со ссылкой по смыслу. Их тоже не
            //    перечисляем руками, а находим по имени колонки: так в список сам
            //    попал robokassa_invoices.appointment_id, которого в моём перечне
            //    не было — именно такие строки и оставались сиротами.
            $byCol = [];
            try {
                $rows = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
                                      WHERE TABLE_SCHEMA = DATABASE()
                                        AND COLUMN_NAME IN ('appointment_id','appt_id')")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $t = (string)($row['TABLE_NAME'] ?? ''); $c = (string)($row['COLUMN_NAME'] ?? '');
                    if ($t === '' || $c === '' || $t === 'appointments') continue;
                    $byCol[] = [$t, $c];
                }
            } catch (Exception $e) { /* нет доступа к information_schema — ниже запасной список */ }
            if (!$byCol) {
                // Запасной путь, если information_schema закрыта.
                foreach (['payments', 'promo_bookings', 'free_intro_bookings', 'reviews',
                          'robokassa_invoices', 'calls', 'session_orders'] as $t) {
                    $byCol[] = [$t, 'appointment_id'];
                }
            }
            foreach ($byCol as $pair) {
                if (isset($seen[$pair[0] . '.' . $pair[1]])) continue;
                $wipe($pair[0], $pair[1]);
            }

            $pdo->prepare("DELETE FROM appointments WHERE id = ?")->execute([$aid]);
            echo json_encode(['ok' => true, 'deleted' => $aid, 'cleared' => $cleared], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            // Текст ошибки отдаём как есть: это админский эндпоинт, и без настоящей
            // причины («такой-то внешний ключ») чинить нечего — видно только «не удалось».
            http_response_code(500);
            echo json_encode(['error' => 'Не удалось удалить запись: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($action === 'set-price') {
        $pid = $body['psychologist_id'] ?? null;
        $price = isset($body['price']) ? (float)$body['price'] : null;
        if (!$pid || $price === null || $price < 0) { http_response_code(400); echo json_encode(['error' => 'Нужны psychologist_id и цена']); exit; }
        try {
            $st = $pdo->prepare("UPDATE psychologists SET price = ? WHERE id = ?");
            $st->execute([$price, $pid]);
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            http_response_code(500); echo json_encode(['error' => 'Не удалось обновить цену']);
        }
        exit;
    }

    if ($action === 'save-settings') {
        $settings = isset($body['settings']) && is_array($body['settings']) ? $body['settings'] : $body;
        list($table, $kc, $vc) = settingsCols($pdo);
        if (!$table) { http_response_code(500); echo json_encode(['error' => 'Не найдена таблица настроек']); exit; }
        $allowed = allowedSettingKeys();
        $saved = 0; $errors = []; $skipped = [];
        foreach ($settings as $key => $val) {
            // Ключ не в белом списке — не сохраняем, но и не молчим: раньше такой
            // ключ исчезал бесследно, а админка рапортовала об успехе.
            if (!in_array($key, $allowed, true)) { $skipped[] = $key; continue; }
            try {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE `$kc` = ?");
                $chk->execute([$key]);
                if ((int)$chk->fetchColumn() > 0) {
                    $u = $pdo->prepare("UPDATE `$table` SET `$vc` = ? WHERE `$kc` = ?");
                    $u->execute([(string)$val, $key]);
                } else {
                    $ins = $pdo->prepare("INSERT INTO `$table` (`$kc`, `$vc`) VALUES (?, ?)");
                    $ins->execute([$key, (string)$val]);
                }
                $saved++;
            } catch (Exception $e) { $errors[] = $key; }
        }
        echo json_encode(['ok' => true, 'saved' => $saved, 'errors' => $errors, 'skipped' => $skipped]);
        exit;
    }

    if ($action === 'set-replacement-status') {
        $rid = $body['id'] ?? null;
        $status = (string)($body['status'] ?? '');
        if (!$rid || !in_array($status, ['new', 'in_progress', 'done'], true)) {
            http_response_code(400); echo json_encode(['error' => 'Нужны id и корректный статус']); exit;
        }
        try {
            $st = $pdo->prepare("UPDATE replacement_requests SET status = ? WHERE id = ?");
            $st->execute([$status, $rid]);
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            http_response_code(500); echo json_encode(['error' => 'Не удалось обновить статус']);
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

    if ($action === 'update-psychologist') {
        $pid = $body['psychologist_id'] ?? null;
        if (!$pid) { http_response_code(400); echo json_encode(['error' => 'psychologist_id обязателен']); exit; }

        $psyCols = ['specialization', 'experience', 'education', 'description', 'not_working_with', 'price', 'avatar'];
        $userCols = ['first_name', 'last_name', 'phone'];
        $contactCols = ['telegram', 'whatsapp', 'max_msg'];

        $uid = null;
        try {
            $st = $pdo->prepare("SELECT user_id FROM psychologists WHERE id = ? LIMIT 1");
            $st->execute([$pid]);
            $uid = $st->fetchColumn();
        } catch (Exception $e) {}
        if (!$uid) { http_response_code(404); echo json_encode(['error' => 'Психолог не найден']); exit; }

        $errors = [];
        $sets = []; $vals = [];
        foreach ($psyCols as $c) {
            if (array_key_exists($c, $body)) { $sets[] = "`$c` = ?"; $vals[] = $body[$c]; }
        }
        if ($sets) {
            $vals[] = $pid;
            try { $pdo->prepare("UPDATE psychologists SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals); }
            catch (Exception $e) { $errors[] = 'psychologists'; }
        }

        $uSets = []; $uVals = [];
        foreach ($userCols as $c) {
            if (array_key_exists($c, $body)) { $uSets[] = "`$c` = ?"; $uVals[] = $body[$c]; }
        }
        if ($uSets) {
            $uVals[] = $uid;
            try { $pdo->prepare("UPDATE users SET " . implode(', ', $uSets) . " WHERE id = ?")->execute($uVals); }
            catch (Exception $e) { $errors[] = 'users'; }
        }

        $hasContact = false;
        foreach ($contactCols as $c) { if (array_key_exists($c, $body)) { $hasContact = true; break; } }
        if ($hasContact) {
            $email = '';
            try { $st = $pdo->prepare("SELECT email FROM users WHERE id = ? LIMIT 1"); $st->execute([$uid]); $email = $st->fetchColumn() ?: ''; } catch (Exception $e) {}
            if ($email) {
                $cSets = []; $cVals = [];
                foreach ($contactCols as $c) {
                    if (array_key_exists($c, $body)) { $cSets[] = "`$c` = ?"; $cVals[] = $body[$c]; }
                }
                try {
                    $chk = $pdo->prepare("SELECT COUNT(*) FROM psychologist_contacts WHERE LOWER(email) = LOWER(?)");
                    $chk->execute([$email]);
                    if ((int)$chk->fetchColumn() > 0) {
                        $cVals[] = $email;
                        $pdo->prepare("UPDATE psychologist_contacts SET " . implode(', ', $cSets) . " WHERE LOWER(email) = LOWER(?)")->execute($cVals);
                    } else {
                        $ins = ['email' => $email];
                        foreach ($contactCols as $c) { if (array_key_exists($c, $body)) $ins[$c] = $body[$c]; }
                        $cols = array_keys($ins); $ph = array_fill(0, count($cols), '?');
                        $pdo->prepare("INSERT INTO psychologist_contacts (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $ph) . ")")->execute(array_values($ins));
                    }
                } catch (Exception $e) { $errors[] = 'contacts'; }
            }
        }

        // ИНН — не колонка psychologists, а запись в psychologist_credentials (type='inn'),
        // туда же его кладёт регистрация и редактирование профиля. Оттуда его берёт
        // эквайринг для supplierInfo в чеке. Своя функция со своим try/catch: если запись
        // не удастся, остальные поля психолога всё равно должны сохраниться.
        if (array_key_exists('inn', $body)) adminSetPsychInn($pdo, $pid, $uid, (string)$body['inn']);

        echo json_encode(['ok' => true, 'errors' => $errors]);
        exit;
    }

    /**
     * Документы, потерявшие хозяина. Таблица psychologist_credentials когда-то имела
     * колонки id-шников типа INT, а идентификаторы у нас строковые — MySQL сложил их в
     * 2147483647 и 0. Строки, записанные до расширения колонок, теперь не находятся ни
     * одним запросом: у психолога в карточке просто пусто. Здесь их видно, и админ может
     * привязать их к нужному психологу.
     *
     * Только дипломы и сертификаты: осиротевший ИНН не трогаем — приписать чужой ИНН
     * человеку хуже, чем оставить его висеть.
     */
    if ($action === 'orphan-credentials') {
        try {
            $st = $pdo->query("SELECT id, type, url, name, created_at
                                 FROM psychologist_credentials pc
                                WHERE pc.type IN ('diploma','cert')
                                  AND pc.url IS NOT NULL AND pc.url <> ''
                                  AND NOT EXISTS (SELECT 1 FROM psychologists p WHERE p.id = pc.psychologist_id)
                                  AND NOT EXISTS (SELECT 1 FROM psychologists p2 WHERE p2.user_id = pc.user_id)
                                ORDER BY pc.id ASC");
            echo json_encode(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) { echo json_encode(['ok' => true, 'data' => []]); }
        exit;
    }

    if ($action === 'attach-credentials') {
        $pid = $body['psychologist_id'] ?? null;
        $ids = $body['ids'] ?? [];
        if (!$pid) { http_response_code(400); echo json_encode(['error' => 'psychologist_id обязателен']); exit; }
        if (!is_array($ids) || !$ids) { http_response_code(400); echo json_encode(['error' => 'Не выбрано ни одного документа']); exit; }
        $uid = null;
        try { $st = $pdo->prepare("SELECT user_id FROM psychologists WHERE id = ? LIMIT 1"); $st->execute([$pid]); $uid = $st->fetchColumn(); } catch (Exception $e) {}
        if (!$uid) { http_response_code(404); echo json_encode(['error' => 'Психолог не найден']); exit; }
        $done = 0;
        try {
            $up = $pdo->prepare("UPDATE psychologist_credentials
                                    SET psychologist_id = ?, user_id = ?
                                  WHERE id = ? AND type IN ('diploma','cert')");
            foreach ($ids as $id) { $up->execute([$pid, $uid, (int)$id]); $done += $up->rowCount(); }
        } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось привязать документы']); exit; }
        echo json_encode(['ok' => true, 'привязано' => $done], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'psychologist-credentials') {
        $pid = $body['psychologist_id'] ?? ($_GET['psychologist_id'] ?? null);
        if (!$pid) { http_response_code(400); echo json_encode(['error' => 'psychologist_id обязателен']); exit; }
        $uid = null;
        try { $st = $pdo->prepare("SELECT user_id FROM psychologists WHERE id = ? LIMIT 1"); $st->execute([$pid]); $uid = $st->fetchColumn(); } catch (Exception $e) {}
        try {
            $st = $pdo->prepare("SELECT id, type, url, name, meta, created_at FROM psychologist_credentials WHERE psychologist_id = ? OR user_id = ? ORDER BY id ASC");
            $st->execute([$pid, $uid ?: 0]);
            echo json_encode(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Exception $e) { echo json_encode(['ok' => true, 'data' => []]); }
        exit;
    }
}

http_response_code(400);
echo json_encode(['error' => 'Неизвестное действие']);
