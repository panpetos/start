<?php
/**
 * qr_login.php — вход на компьютере подтверждением с телефона.
 *
 * GET  ?action=create              (без входа) — компьютер заводит запрос на вход
 *                                  → {ok, code, url, expires_in}
 * GET  ?action=poll&code=…         (без входа) — компьютер ждёт подтверждения
 *                                  → {ok, status: pending|approved|expired}
 * GET  ?action=info&code=…         (нужен вход) — телефон смотрит, что подтверждает
 * POST ?action=approve {code}      (нужен вход) — телефон подтверждает вход
 * POST ?action=reject  {code}      (нужен вход) — отклонить, если это не он
 *
 * Как это безопасно: код одноразовый, живёт QR_TTL_SEC и подтверждается только с
 * устройства, где человек уже вошёл. Компьютер узнаёт об успехе, опрашивая poll,
 * и получает сессию ровно того пользователя, который подтвердил.
 */

header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');
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

/** Сколько живёт код. Короткий срок — чтобы подсмотренный QR быстро протухал. */
const QR_TTL_SEC = 180;

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS qr_logins (
        code VARCHAR(64) PRIMARY KEY,
        user_id VARCHAR(64) NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL,
        approved_at DATETIME NULL,
        device VARCHAR(255) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    psy_align_collation($pdo, ['qr_logins']);
    // подчищаем протухшие, чтобы таблица не росла
    $pdo->prepare("DELETE FROM qr_logins WHERE created_at < ?")
        ->execute([date('Y-m-d H:i:s', time() - 3600)]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Не удалось подготовить таблицу: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? '';
$body = json_decode(file_get_contents('php://input'), true) ?: [];

/** Запись по коду, если он ещё не протух. */
function qrRow(PDO $pdo, $code) {
    if ($code === '' || !preg_match('/^[A-Za-z0-9]{8,64}$/', $code)) return null;
    try {
        $st = $pdo->prepare("SELECT * FROM qr_logins WHERE code = ? LIMIT 1");
        $st->execute([$code]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return null; }
    if (!$r) return null;
    if (strtotime((string)$r['created_at']) < time() - QR_TTL_SEC) { $r['status'] = 'expired'; }
    return $r;
}

if ($action === 'create') {
    $code = bin2hex(random_bytes(16));
    $device = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200);
    try {
        $pdo->prepare("INSERT INTO qr_logins (code, status, created_at, device) VALUES (?, 'pending', ?, ?)")
            ->execute([$code, date('Y-m-d H:i:s'), $device]);
    } catch (Exception $e) {
        http_response_code(500); echo json_encode(['error' => 'Не удалось создать код']); exit;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'psytalk.pro';
    echo json_encode([
        'ok' => true,
        'code' => $code,
        'url' => $scheme . '://' . $host . '/qr.html?code=' . $code,
        'expires_in' => QR_TTL_SEC,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'poll') {
    $r = qrRow($pdo, (string)($_GET['code'] ?? ''));
    if (!$r) { echo json_encode(['ok' => true, 'status' => 'expired']); exit; }
    if ($r['status'] === 'approved' && !empty($r['user_id'])) {
        // Вход происходит здесь: подтвердивший человек становится хозяином этой сессии.
        // Код сразу гасим — он одноразовый.
        $_SESSION['user_id'] = $r['user_id'];
        try { $pdo->prepare("DELETE FROM qr_logins WHERE code = ?")->execute([$r['code']]); } catch (Exception $e) {}
        echo json_encode(['ok' => true, 'status' => 'approved'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => true, 'status' => $r['status']], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Дальше только для того, кто уже вошёл на телефоне ──
if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }

if ($action === 'info') {
    $r = qrRow($pdo, (string)($_GET['code'] ?? ''));
    if (!$r || $r['status'] === 'expired') {
        echo json_encode(['ok' => false, 'error' => 'Код устарел — обновите страницу на компьютере'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'status' => $r['status'],
        'device' => $r['device'],
        'created_at' => $r['created_at'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($action === 'approve' || $action === 'reject') && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = (string)($body['code'] ?? '');
    $r = qrRow($pdo, $code);
    if (!$r || $r['status'] === 'expired') {
        echo json_encode(['ok' => false, 'error' => 'Код устарел — обновите страницу на компьютере'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'reject') {
        try { $pdo->prepare("DELETE FROM qr_logins WHERE code = ?")->execute([$code]); } catch (Exception $e) {}
        echo json_encode(['ok' => true, 'status' => 'rejected']);
        exit;
    }
    try {
        $pdo->prepare("UPDATE qr_logins SET status = 'approved', user_id = ?, approved_at = ? WHERE code = ?")
            ->execute([$userId, date('Y-m-d H:i:s'), $code]);
    } catch (Exception $e) {
        http_response_code(500); echo json_encode(['error' => 'Не удалось подтвердить']); exit;
    }
    echo json_encode(['ok' => true, 'status' => 'approved']);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Неизвестное действие']);
