<?php
/**
 * profile_reminders.php — «готовность профиля психолога к работе».
 *
 * Считает, что у психолога ещё не заполнено (аватар, ИНН, график, описание,
 * цена, документы), показывает чек-лист самому психологу и шлёт напоминания
 * тем, у кого чего-то не хватает, чтобы администратору не приходилось теребить
 * вручную.
 *
 * Действия:
 *   GET  ?action=mine                 (психолог) — что осталось заполнить (чек-лист в ЛК)
 *   GET  ?action=scan                 (admin)    — список психологов с незаполненным
 *   POST ?action=remind {force?}      (admin|token) — разослать напоминания (раз в N дней)
 *
 * Напоминание — личным сообщением в чат от имени администратора (как welcome.php),
 * это же шлёт пуш. Антиспам: не чаще, чем раз в reminder_cooldown_days (по умолчанию 3).
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
@include_once __DIR__ . '/rtc_lib.php';      // rtcSendDm — напоминание сообщением + пуш
@include_once __DIR__ . '/settings_lib.php'; // psySetting — настройки платформы
$pdo = function_exists('getDB') ? getDB()
     : (function_exists('getDbConnection') ? getDbConnection()
     : (function_exists('getPDO') ? getPDO() : null));
if (!$pdo) { http_response_code(500); echo json_encode(['error' => 'Нет БД']); exit; }

function prOut($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

/** Есть ли колонка в таблице (чтобы не падать на «Unknown column» на проде). */
function prHasCol(PDO $pdo, string $table, string $col): bool {
    static $cache = [];
    $k = $table . '.' . $col;
    if (array_key_exists($k, $cache)) return $cache[$k];
    try { $cache[$k] = (bool)$pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($col))->fetch(); }
    catch (Exception $e) { $cache[$k] = false; }
    return $cache[$k];
}

/** Первый админ — от его имени шлём напоминание (как welcome.php). */
function prAdminId(PDO $pdo) {
    foreach (["SELECT id FROM users WHERE role = 'admin' ORDER BY created_at ASC, id ASC LIMIT 1",
              "SELECT id FROM users WHERE role = 'admin' LIMIT 1"] as $q) {
        try { $id = $pdo->query($q)->fetchColumn(); if ($id) return $id; } catch (Exception $e) {}
    }
    return null;
}

/** Непустое ли значение аватара пользователя (колонка определяется динамически). */
function prUserAvatar(PDO $pdo, $userId): bool {
    foreach (['avatar', 'photo_url', 'photo', 'image_url', 'avatar_url'] as $col) {
        if (prHasCol($pdo, 'users', $col)) {
            try {
                $st = $pdo->prepare("SELECT `$col` FROM users WHERE id = ? LIMIT 1");
                $st->execute([$userId]);
                $v = trim((string)$st->fetchColumn());
                return $v !== '';
            } catch (Exception $e) { return true; } // колонка есть, но запрос упал — не придираемся
        }
    }
    return true; // колонки аватара нет вовсе — не считаем это пробелом
}

/**
 * Чего не хватает психологу (массив человекочитаемых пунктов).
 * $psy — строка из psychologists (SELECT *). $userId — users.id.
 */
function prMissing(PDO $pdo, array $psy, $userId): array {
    $miss = [];
    // Аватар
    if (!prUserAvatar($pdo, $userId)) $miss[] = 'фото профиля (аватар)';
    // ИНН (в psychologist_credentials type='inn')
    $hasInn = false;
    try {
        $st = $pdo->prepare("SELECT 1 FROM psychologist_credentials WHERE type='inn' AND (psychologist_id = ? OR user_id = ?) LIMIT 1");
        $st->execute([$psy['id'] ?? 0, $userId]);
        $hasInn = (bool)$st->fetchColumn();
    } catch (Exception $e) { $hasInn = true; }
    if (!$hasInn) $miss[] = 'ИНН (самозанятость или ИП)';
    // График работы (psychologist_schedule.data непустой JSON)
    $hasSchedule = false;
    try {
        $st = $pdo->prepare("SELECT data FROM psychologist_schedule WHERE user_id = ? LIMIT 1");
        $st->execute([$userId]);
        $raw = (string)$st->fetchColumn();
        $arr = $raw !== '' ? json_decode($raw, true) : null;
        if (is_array($arr)) { foreach ($arr as $d) { if (is_array($d) && count($d)) { $hasSchedule = true; break; } } }
    } catch (Exception $e) { $hasSchedule = true; }
    if (!$hasSchedule) $miss[] = 'график работы (рабочие часы)';
    // Реквизиты для выплат (psy_payout_requisites с непустым enc_blob)
    $hasPayout = false;
    try {
        $st = $pdo->prepare("SELECT 1 FROM psy_payout_requisites WHERE psychologist_id = ? AND enc_blob IS NOT NULL AND enc_blob <> '' LIMIT 1");
        $st->execute([$psy['id'] ?? 0]);
        $hasPayout = (bool)$st->fetchColumn();
    } catch (Exception $e) { $hasPayout = true; } // таблицы/колонки может не быть — не придираемся
    if (!$hasPayout) $miss[] = 'реквизиты для выплат (куда перечислять деньги)';
    // Документы (диплом/сертификат)
    $hasDoc = false;
    try {
        $st = $pdo->prepare("SELECT 1 FROM psychologist_credentials WHERE type IN ('diploma','cert') AND (psychologist_id = ? OR user_id = ?) LIMIT 1");
        $st->execute([$psy['id'] ?? 0, $userId]);
        $hasDoc = (bool)$st->fetchColumn();
    } catch (Exception $e) { $hasDoc = true; }
    if (!$hasDoc) $miss[] = 'дипломы/сертификаты';
    // Описание «о себе»
    $descCol = null;
    foreach (['description', 'about', 'bio'] as $c) { if (array_key_exists($c, $psy)) { $descCol = $c; break; } }
    if ($descCol !== null && trim((string)$psy[$descCol]) === '') $miss[] = 'описание «о себе»';
    // Цена
    if (array_key_exists('price', $psy) && (float)$psy['price'] <= 0) $miss[] = 'стоимость сессии';
    return $miss;
}

$action = $_GET['action'] ?? '';

// ── Чек-лист для самого психолога (ЛК) ──────────────────────────────────────────
if ($action === 'mine') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) prOut(['error' => 'Требуется авторизация'], 401);
    try {
        $st = $pdo->prepare("SELECT * FROM psychologists WHERE user_id = ? LIMIT 1");
        $st->execute([$userId]);
        $psy = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $psy = null; }
    if (!$psy) prOut(['ok' => true, 'is_psychologist' => false, 'complete' => true, 'missing' => []]);
    $miss = prMissing($pdo, $psy, $userId);
    prOut(['ok' => true, 'is_psychologist' => true, 'complete' => count($miss) === 0, 'missing' => $miss]);
}

// ── Админ / токен ────────────────────────────────────────────────────────────────
function prIsAdmin(PDO $pdo): bool {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $uid = $_SESSION['user_id'] ?? null;
    if (!$uid) return false;
    try { $st = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1"); $st->execute([$uid]); return (string)$st->fetchColumn() === 'admin'; }
    catch (Exception $e) { return false; }
}
function prTokenOk(): bool {
    $t = $_GET['token'] ?? ($_POST['token'] ?? '');
    $f = __DIR__ . '/dev_tasks_config.php';
    if ($t === '' || !file_exists($f)) return false;
    $cfg = include $f;
    $real = is_array($cfg) ? ($cfg['token'] ?? '') : '';
    return $real !== '' && hash_equals((string)$real, (string)$t);
}

if ($action === 'scan' || $action === 'remind') {
    if (!prIsAdmin($pdo) && !prTokenOk()) prOut(['error' => 'Только для администратора'], 403);

    // Берём психологов (одобренных и ожидающих — всем полезно напоминание).
    try {
        $rows = $pdo->query("SELECT p.*, u.first_name, u.last_name, u.id AS uid
                             FROM psychologists p JOIN users u ON u.id = p.user_id")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $rows = []; }

    $report = [];
    foreach ($rows as $r) {
        $uid = $r['uid'] ?? $r['user_id'] ?? null;
        if (!$uid) continue;
        $miss = prMissing($pdo, $r, $uid);
        if ($miss) $report[] = ['user_id' => $uid, 'psychologist_id' => $r['id'] ?? null,
            'name' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')), 'missing' => $miss];
    }

    if ($action === 'scan') prOut(['ok' => true, 'incomplete' => count($report), 'items' => $report]);

    // remind: разослать, но не чаще чем раз в N дней на каждого.
    $cooldown = 3;
    if (function_exists('psySetting')) { $c = (int)psySetting($pdo, 'reminder_cooldown_days', 3); if ($c > 0) $cooldown = $c; }
    $force = false;
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!empty($body['force']) || !empty($_GET['force'])) $force = true;

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS psy_profile_reminders (
            user_id VARCHAR(64) NOT NULL PRIMARY KEY,
            last_sent_at DATETIME NULL,
            sent_count INT NOT NULL DEFAULT 0
        ) DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}

    $adminId = prAdminId($pdo);
    if (!$adminId) prOut(['ok' => true, 'sent' => 0, 'reason' => 'нет администратора-отправителя']);

    $sent = 0; $skipped = 0;
    foreach ($report as $it) {
        $uid = $it['user_id'];
        if ((string)$uid === (string)$adminId) continue;
        // Антиспам: пропустить, если недавно уже слали.
        if (!$force) {
            try {
                $st = $pdo->prepare("SELECT last_sent_at FROM psy_profile_reminders WHERE user_id = ? LIMIT 1");
                $st->execute([$uid]);
                $last = $st->fetchColumn();
                if ($last && (time() - strtotime($last)) < $cooldown * 86400) { $skipped++; continue; }
            } catch (Exception $e) {}
        }
        $list = '— ' . implode("\n— ", $it['missing']);
        $text = "👋 Напоминаем: чтобы ваш профиль был готов к работе и виден клиентам, осталось заполнить:\n"
              . $list . "\n\nЭто можно сделать в личном кабинете (разделы «Профиль» и «График»). "
              . "Спасибо! Если нужна помощь — ответьте на это сообщение.";
        $ok = false;
        try { if (function_exists('rtcSendDm')) { rtcSendDm($pdo, $adminId, $uid, $text, false); $ok = true; } }
        catch (\Throwable $e) {}
        if ($ok) {
            $sent++;
            try {
                $pdo->prepare("INSERT INTO psy_profile_reminders (user_id, last_sent_at, sent_count)
                               VALUES (?, NOW(), 1)
                               ON DUPLICATE KEY UPDATE last_sent_at=NOW(), sent_count=sent_count+1")->execute([$uid]);
            } catch (Exception $e) {}
        }
    }
    prOut(['ok' => true, 'sent' => $sent, 'skipped' => $skipped, 'incomplete' => count($report), 'cooldown_days' => $cooldown]);
}

prOut(['error' => 'Неизвестное действие'], 400);
