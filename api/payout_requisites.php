<?php
/**
 * payout_requisites.php — банковские реквизиты психолога для выплат вознаграждения.
 *
 * Зачем. Платформа работает по агентской схеме: деньги клиента принимает эквайринг
 * (Робочеки), а вознаграждение психологу платформа переводит ВРУЧНУЮ с расчётного
 * счёта (у Робокассы массовых выплат самозанятым нет). Чтобы было куда переводить,
 * психолог один раз указывает свои реквизиты, а администратор видит их при выплате.
 *
 * Безопасность. Реквизиты — персональные данные, поэтому:
 *   • хранятся ЗАШИФРОВАННЫМИ (AES-256-GCM), ключ — в api/payouts_config.php вне git;
 *   • психолог видит только маску (последние 4 цифры), не весь номер;
 *   • полный номер отдаётся ТОЛЬКО администратору и только по явному запросу;
 *   • запросы — PDO с prepared statements, вывод — json, без подстановки в SQL.
 *
 * Действия:
 *   GET  ?action=mine                              — свои реквизиты, маской (психолог)
 *   POST ?action=save {method, value, holder, bank}— сохранить свои (психолог)
 *   GET  ?action=get&psychologist_id=..            — полные реквизиты (ТОЛЬКО админ)
 *   POST ?action=delete                            — удалить свои (психолог)
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

function prOut($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

// ── Ключ шифрования (вне git) ───────────────────────────────────────────────────
function prEncKey(): string {
    $f = __DIR__ . '/payouts_config.php';
    $cfg = file_exists($f) ? (include $f) : [];
    $k = is_array($cfg) ? (string)($cfg['enc_key'] ?? '') : '';
    if ($k === '' || strpos($k, 'ВПИШИТЕ') !== false) return '';
    return $k;
}
function prEncrypt(string $plain, string $key): string {
    if (!function_exists('openssl_encrypt')) return '';
    $k = hash('sha256', $key, true);
    $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) return '';
    return base64_encode($iv . $tag . $ct);
}
function prDecrypt(string $blob, string $key): string {
    if (!function_exists('openssl_decrypt')) return '';
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 29) return '';
    $k = hash('sha256', $key, true);
    $iv = substr($raw, 0, 12); $tag = substr($raw, 12, 16); $ct = substr($raw, 28);
    $pt = openssl_decrypt($ct, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag);
    return $pt === false ? '' : $pt;
}

// ── Схема ───────────────────────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS psy_payout_requisites (
        psychologist_id VARCHAR(64) NOT NULL PRIMARY KEY,
        method VARCHAR(16) NOT NULL DEFAULT 'card',   -- card | account | sbp
        holder VARCHAR(255) NULL,                      -- ФИО получателя
        bank   VARCHAR(255) NULL,                      -- банк (для СБП/счёта)
        last4  VARCHAR(8) NULL,                         -- последние цифры (для показа маской)
        enc_blob TEXT NULL,                            -- зашифрованный полный номер
        updated_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

if (session_status() === PHP_SESSION_NONE) session_start();
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) prOut(['error' => 'Требуется авторизация'], 401);

$role = '';
try {
    $st = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    $st->execute([$userId]);
    $role = (string)$st->fetchColumn();
} catch (Exception $e) {}
$isAdmin = ($role === 'admin');

/** psychologists.id текущего пользователя (или '' если он не психолог). */
function prMyPsyId(PDO $pdo, $userId): string {
    try {
        $st = $pdo->prepare("SELECT id FROM psychologists WHERE user_id = ? LIMIT 1");
        $st->execute([$userId]);
        return (string)$st->fetchColumn();
    } catch (Exception $e) { return ''; }
}

$action = $_GET['action'] ?? '';
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

// ── Психолог: свои реквизиты маской ──────────────────────────────────────────────
if ($action === 'mine') {
    $pid = prMyPsyId($pdo, $userId);
    if ($pid === '') prOut(['ok' => true, 'set' => false, 'note' => 'Профиль психолога не найден']);
    try {
        $st = $pdo->prepare("SELECT method, holder, bank, last4, updated_at,
                                    (enc_blob IS NOT NULL AND enc_blob <> '') AS has_value
                               FROM psy_payout_requisites WHERE psychologist_id = ? LIMIT 1");
        $st->execute([$pid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $r = null; }
    if (!$r || !$r['has_value']) prOut(['ok' => true, 'set' => false]);
    prOut(['ok' => true, 'set' => true,
           'method' => $r['method'], 'holder' => $r['holder'], 'bank' => $r['bank'],
           'last4' => $r['last4'], 'masked' => $r['last4'] ? ('•••• ' . $r['last4']) : '••••',
           'updated_at' => $r['updated_at']]);
}

// ── Психолог: сохранить свои реквизиты ───────────────────────────────────────────
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pid = prMyPsyId($pdo, $userId);
    if ($pid === '' && !$isAdmin) prOut(['error' => 'Только для психолога'], 403);
    // Админ может сохранить за психолога, указав его id (удобно при заведении вручную).
    if ($pid === '' && $isAdmin) $pid = (string)($body['psychologist_id'] ?? '');
    if ($pid === '') prOut(['error' => 'Не указан психолог'], 400);

    $key = prEncKey();
    if ($key === '') prOut(['error' => 'Хранилище реквизитов не настроено: заполните api/payouts_config.php (enc_key) на сервере.'], 503);

    $method = in_array(($body['method'] ?? 'card'), ['card', 'account', 'sbp'], true) ? $body['method'] : 'card';
    $value  = preg_replace('/\s+/', '', (string)($body['value'] ?? ''));
    $holder = mb_substr(trim((string)($body['holder'] ?? '')), 0, 255);
    $bank   = mb_substr(trim((string)($body['bank'] ?? '')), 0, 255);

    // Простейшая валидация по типу (без привязки к конкретному банку).
    if ($method === 'card') {
        $digits = preg_replace('/\D+/', '', $value);
        if (strlen($digits) < 16 || strlen($digits) > 19) prOut(['error' => 'Номер карты должен содержать 16–19 цифр'], 400);
        $value = $digits;
    } elseif ($method === 'account') {
        $digits = preg_replace('/\D+/', '', $value);
        if (strlen($digits) !== 20) prOut(['error' => 'Расчётный счёт должен содержать 20 цифр'], 400);
        $value = $digits;
    } else { // sbp — телефон
        $digits = preg_replace('/\D+/', '', $value);
        if (strlen($digits) < 10 || strlen($digits) > 15) prOut(['error' => 'Укажите телефон, привязанный к СБП'], 400);
        $value = $digits;
    }

    $last4 = substr(preg_replace('/\D+/', '', $value), -4);
    $blob = prEncrypt($value, $key);
    if ($blob === '') prOut(['error' => 'Не удалось зашифровать реквизиты (проверьте расширение openssl на сервере).'], 500);

    try {
        $st = $pdo->prepare("INSERT INTO psy_payout_requisites (psychologist_id, method, holder, bank, last4, enc_blob, updated_at)
                             VALUES (?, ?, ?, ?, ?, ?, NOW())
                             ON DUPLICATE KEY UPDATE method=VALUES(method), holder=VALUES(holder),
                                                     bank=VALUES(bank), last4=VALUES(last4),
                                                     enc_blob=VALUES(enc_blob), updated_at=NOW()");
        $st->execute([$pid, $method, $holder, $bank, $last4, $blob]);
    } catch (Exception $e) { prOut(['error' => 'Не удалось сохранить реквизиты'], 500); }
    prOut(['ok' => true, 'saved' => true, 'masked' => '•••• ' . $last4]);
}

// ── Психолог: удалить свои реквизиты ──────────────────────────────────────────────
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pid = prMyPsyId($pdo, $userId);
    if ($pid === '') prOut(['error' => 'Только для психолога'], 403);
    try {
        $pdo->prepare("DELETE FROM psy_payout_requisites WHERE psychologist_id = ?")->execute([$pid]);
    } catch (Exception $e) {}
    prOut(['ok' => true, 'deleted' => true]);
}

// ── Админ: полные реквизиты для выплаты ───────────────────────────────────────────
if ($action === 'get') {
    if (!$isAdmin) prOut(['error' => 'Доступ только для администратора'], 403);
    $pid = (string)($_GET['psychologist_id'] ?? '');
    if ($pid === '') prOut(['error' => 'Не указан психолог'], 400);
    $key = prEncKey();
    try {
        $st = $pdo->prepare("SELECT method, holder, bank, last4, enc_blob, updated_at
                               FROM psy_payout_requisites WHERE psychologist_id = ? LIMIT 1");
        $st->execute([$pid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $r = null; }
    if (!$r) prOut(['ok' => true, 'set' => false]);
    $full = ($key !== '' && !empty($r['enc_blob'])) ? prDecrypt($r['enc_blob'], $key) : '';
    prOut(['ok' => true, 'set' => true, 'method' => $r['method'], 'holder' => $r['holder'],
           'bank' => $r['bank'], 'last4' => $r['last4'], 'value' => $full,
           'updated_at' => $r['updated_at'],
           'note' => $full === '' ? 'Не удалось расшифровать (проверьте enc_key в payouts_config.php)' : null]);
}

prOut(['error' => 'Неизвестное действие'], 400);
