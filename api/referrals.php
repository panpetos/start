<?php
/**
 * referrals.php — приглашения по личной ссылке.
 *
 * ЗАЧЕМ. Позвать знакомого можно было только словами: «зайди на psytalk.pro и
 * зарегистрируйся». Ни отследить, ни поблагодарить за это было нечем.
 *
 * КАК УСТРОЕНО. У каждого есть короткий код и ссылка вида psytalk.pro/i/КОД.
 * Переход по ней запоминается, а когда человек регистрируется — приглашение
 * засчитывается пригласившему.
 *
 * ПОЧЕМУ ЗАСЧИТЫВАЕТ БРАУЗЕР, А НЕ РЕГИСТРАЦИЯ. Регистрация живёт в серверном
 * auth.php, которого нет в репозитории и трогать его нельзя. Поэтому код
 * запоминается в браузере при переходе и отправляется сюда сразу после входа —
 * действие идемпотентное: второй раз то же приглашение не засчитается.
 *
 * ДЕЙСТВИЯ
 *   GET  ?action=me                 — мой код, ссылка и счётчики
 *   GET  ?action=list               — кого я пригласил
 *   GET  ?action=who&code=X         — чьё это приглашение (для страницы-посадки; без входа)
 *   POST ?action=touch  {code}      — по ссылке перешли (без входа)
 *   POST ?action=claim  {code}      — засчитать приглашение вошедшему
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/config.php';
if (!function_exists('getDB') && !function_exists('getDbConnection') && !function_exists('getPDO')) {
    require_once __DIR__ . '/db.php';
}
$pdo = function_exists('getDB') ? getDB()
     : (function_exists('getDbConnection') ? getDbConnection()
     : (function_exists('getPDO') ? getPDO() : null));
if (!$pdo) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Нет подключения к БД']); exit; }

if (session_status() === PHP_SESSION_NONE) session_start();
$userId = $_SESSION['user_id'] ?? null;

function refOut($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

function refEnsure(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS referral_codes (
        user_id VARCHAR(64) NOT NULL PRIMARY KEY,
        code VARCHAR(16) NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uniq_code (code)
    ) DEFAULT CHARSET=utf8mb4");
    // Одна строка на приглашённого: повторный claim ничего не добавит.
    $pdo->exec("CREATE TABLE IF NOT EXISTS referral_invites (
        id INT AUTO_INCREMENT PRIMARY KEY,
        inviter_id VARCHAR(64) NOT NULL,
        invited_id VARCHAR(64) NOT NULL,
        code VARCHAR(16) NOT NULL,
        joined_at DATETIME NOT NULL,
        UNIQUE KEY uniq_invited (invited_id),
        INDEX idx_inviter (inviter_id)
    ) DEFAULT CHARSET=utf8mb4");
    // Переходы считаем отдельно и обезличенно: кто именно кликнул — не наше дело,
    // а пригласившему полезно видеть, сколько людей вообще открыло ссылку.
    $pdo->exec("CREATE TABLE IF NOT EXISTS referral_clicks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(16) NOT NULL,
        day DATE NOT NULL,
        hits INT NOT NULL DEFAULT 0,
        UNIQUE KEY uniq_code_day (code, day)
    ) DEFAULT CHARSET=utf8mb4");
}

/**
 * Код без похожих друг на друга знаков: 0/O и 1/I/l в пересказанной вслух
 * ссылке путают людей, а ссылку часто диктуют голосом.
 */
function refMakeCode() {
    $abc = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < 7; $i++) $s .= $abc[random_int(0, strlen($abc) - 1)];
    return $s;
}

function refCodeFor(PDO $pdo, $userId) {
    $st = $pdo->prepare("SELECT code FROM referral_codes WHERE user_id = ? LIMIT 1");
    $st->execute([$userId]);
    $code = $st->fetchColumn();
    if ($code) return (string)$code;
    for ($try = 0; $try < 6; $try++) {
        $code = refMakeCode();
        try {
            $pdo->prepare("INSERT INTO referral_codes (user_id, code, created_at) VALUES (?, ?, NOW())")
                ->execute([$userId, $code]);
            return $code;
        } catch (Exception $e) {
            // Совпал с чужим — пробуем другой. Не совпал, а другая ошибка — выходим.
            $st->execute([$userId]);
            $have = $st->fetchColumn();
            if ($have) return (string)$have;
        }
    }
    return '';
}

function refUserName(PDO $pdo, $id) {
    try {
        $st = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return '';
        return trim(((string)($r['first_name'] ?? '')) . ' ' . ((string)($r['last_name'] ?? '')));
    } catch (Exception $e) { return ''; }
}

function refCleanCode($c) {
    $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$c));
    return mb_substr($c, 0, 16);
}

try { refEnsure($pdo); } catch (Exception $e) { refOut(['ok' => false, 'error' => 'Не удалось подготовить таблицы'], 500); }

$action = $_GET['action'] ?? '';
$body = json_decode(file_get_contents('php://input'), true) ?: [];

// ── Чьё приглашение: нужно странице-посадке, её открывают ещё не войдя ────────
if ($action === 'who') {
    $code = refCleanCode($_GET['code'] ?? '');
    if ($code === '') refOut(['ok' => true, 'found' => false]);
    try {
        $st = $pdo->prepare("SELECT user_id FROM referral_codes WHERE code = ? LIMIT 1");
        $st->execute([$code]);
        $owner = $st->fetchColumn();
        if (!$owner) refOut(['ok' => true, 'found' => false]);
        // Наружу отдаём только имя: чужой id и почта посторонним ни к чему.
        refOut(['ok' => true, 'found' => true, 'name' => refUserName($pdo, $owner) ?: 'Ваш знакомый']);
    } catch (Exception $e) { refOut(['ok' => true, 'found' => false]); }
}

// ── Переход по ссылке ────────────────────────────────────────────────────────
if ($action === 'touch') {
    $code = refCleanCode($body['code'] ?? '');
    if ($code === '') refOut(['ok' => true]);
    try {
        $pdo->prepare("INSERT INTO referral_clicks (code, day, hits) VALUES (?, CURDATE(), 1)
                       ON DUPLICATE KEY UPDATE hits = hits + 1")->execute([$code]);
    } catch (Exception $e) { /* счётчик — не повод ронять переход */ }
    refOut(['ok' => true]);
}

if (!$userId) refOut(['ok' => false, 'error' => 'Требуется авторизация'], 401);

// ── Засчитать приглашение ────────────────────────────────────────────────────
if ($action === 'claim') {
    $code = refCleanCode($body['code'] ?? '');
    if ($code === '') refOut(['ok' => true, 'claimed' => false, 'why' => 'нет кода']);
    try {
        $st = $pdo->prepare("SELECT user_id FROM referral_codes WHERE code = ? LIMIT 1");
        $st->execute([$code]);
        $inviter = $st->fetchColumn();
        if (!$inviter) refOut(['ok' => true, 'claimed' => false, 'why' => 'код не найден']);
        if ((string)$inviter === (string)$userId) refOut(['ok' => true, 'claimed' => false, 'why' => 'своя ссылка']);
        // Уже приглашён — второй раз не засчитываем (UNIQUE это и гарантирует,
        // но проверяем заранее, чтобы вернуть честный ответ без исключения).
        $chk = $pdo->prepare("SELECT inviter_id FROM referral_invites WHERE invited_id = ? LIMIT 1");
        $chk->execute([$userId]);
        if ($chk->fetchColumn()) refOut(['ok' => true, 'claimed' => false, 'why' => 'уже засчитано']);
        $pdo->prepare("INSERT INTO referral_invites (inviter_id, invited_id, code, joined_at) VALUES (?, ?, ?, NOW())")
            ->execute([$inviter, $userId, $code]);
        refOut(['ok' => true, 'claimed' => true, 'inviter' => refUserName($pdo, $inviter)]);
    } catch (Exception $e) {
        refOut(['ok' => true, 'claimed' => false, 'why' => 'не удалось засчитать']);
    }
}

// ── Моя ссылка и счётчики ────────────────────────────────────────────────────
if ($action === 'me') {
    $code = refCodeFor($pdo, $userId);
    if ($code === '') refOut(['ok' => false, 'error' => 'Не удалось создать код'], 500);
    $joined = 0; $clicks = 0; $invitedBy = '';
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM referral_invites WHERE inviter_id = ?");
        $st->execute([$userId]); $joined = (int)$st->fetchColumn();
        $st = $pdo->prepare("SELECT COALESCE(SUM(hits),0) FROM referral_clicks WHERE code = ?");
        $st->execute([$code]); $clicks = (int)$st->fetchColumn();
        $st = $pdo->prepare("SELECT inviter_id FROM referral_invites WHERE invited_id = ? LIMIT 1");
        $st->execute([$userId]);
        $by = $st->fetchColumn();
        if ($by) $invitedBy = refUserName($pdo, $by);
    } catch (Exception $e) {}
    $host = ($_SERVER['HTTP_HOST'] ?? 'psytalk.pro');
    refOut(['ok' => true, 'code' => $code,
            'link' => 'https://' . $host . '/i/' . $code,
            'clicks' => $clicks, 'joined' => $joined, 'invited_by' => $invitedBy]);
}

// ── Кого я пригласил ─────────────────────────────────────────────────────────
if ($action === 'list') {
    try {
        $st = $pdo->prepare("SELECT r.invited_id, r.joined_at, u.first_name, u.last_name
                               FROM referral_invites r
                               LEFT JOIN users u ON u.id = r.invited_id
                              WHERE r.inviter_id = ?
                              ORDER BY r.joined_at DESC LIMIT 200");
        $st->execute([$userId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $name = trim(((string)($r['first_name'] ?? '')) . ' ' . ((string)($r['last_name'] ?? '')));
            $out[] = ['name' => $name ?: 'Участник', 'joined_at' => $r['joined_at']];
        }
        refOut(['ok' => true, 'data' => $out]);
    } catch (Exception $e) { refOut(['ok' => true, 'data' => []]); }
}

refOut(['ok' => false, 'error' => 'Неизвестное действие'], 400);
