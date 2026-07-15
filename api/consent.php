<?php
/**
 * consent.php — фиксация факта согласий (152-ФЗ) и журнал аудита.
 *
 * Доказательство получения согласия: user_id, тип согласия, версия документа,
 * дата-время, IP-адрес, user-agent. Плюс журнал аудита действий с данными и
 * механизм отзыва согласия (со снятием доступа к чату для спецкатегории).
 *
 * Самостоятельный файл, та же БД, сам создаёт таблицы consents и audit_log.
 *
 * POST ?action=record  {type, version}   — зафиксировать согласие (по сессии)
 * GET  ?action=status&type=...           — есть ли активное согласие данного типа
 * GET  ?action=mine                      — все согласия пользователя
 * POST ?action=revoke  {type}            — отозвать согласие
 *
 * Типы (type): general | health_special | cookie
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

if (session_status() === PHP_SESSION_NONE) session_start();
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS consents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(64) NOT NULL,
        consent_type VARCHAR(32) NOT NULL,
        doc_version VARCHAR(32) NOT NULL DEFAULT '1.0',
        status VARCHAR(16) NOT NULL DEFAULT 'active',
        ip VARCHAR(64) NULL,
        user_agent VARCHAR(512) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        revoked_at DATETIME NULL,
        INDEX idx_user (user_id),
        INDEX idx_user_type (user_id, consent_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(64) NULL,
        action VARCHAR(64) NOT NULL,
        meta TEXT NULL,
        ip VARCHAR(64) NULL,
        user_agent VARCHAR(512) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_action (action),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

/** Реальный IP с учётом прокси. */
function clientIp(): string {
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}
function ua(): string { return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500); }

/** Запись события в журнал аудита. */
function audit(PDO $pdo, $userId, string $action, $meta = null): void {
    try {
        $st = $pdo->prepare("INSERT INTO audit_log (user_id, action, meta, ip, user_agent) VALUES (?,?,?,?,?)");
        $st->execute([$userId, $action, is_string($meta) ? $meta : json_encode($meta, JSON_UNESCAPED_UNICODE), clientIp(), ua()]);
    } catch (Exception $e) {}
}

$ALLOWED_TYPES = ['general', 'health_special', 'cookie'];
$action = $_GET['action'] ?? '';
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

if ($action === 'record') {
    $type = trim((string)($body['type'] ?? ''));
    $version = trim((string)($body['version'] ?? '1.0'));
    if (!in_array($type, $ALLOWED_TYPES, true)) { http_response_code(400); echo json_encode(['error' => 'Неизвестный тип согласия']); exit; }
    try {
        // Снимаем прошлые «active» этого типа (перезапись актуальной версией), пишем новое.
        $pdo->prepare("UPDATE consents SET status='superseded' WHERE user_id=? AND consent_type=? AND status='active'")->execute([$userId, $type]);
        $st = $pdo->prepare("INSERT INTO consents (user_id, consent_type, doc_version, status, ip, user_agent) VALUES (?,?,?, 'active', ?, ?)");
        $st->execute([$userId, $type, $version, clientIp(), ua()]);
        $id = (int)$pdo->lastInsertId();
        audit($pdo, $userId, 'consent_given', ['type' => $type, 'version' => $version, 'consent_id' => $id]);
        echo json_encode(['ok' => true, 'id' => $id]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось зафиксировать согласие']); }
    exit;
}

if ($action === 'status') {
    $type = trim((string)($_GET['type'] ?? ''));
    if (!in_array($type, $ALLOWED_TYPES, true)) { echo json_encode(['ok' => true, 'granted' => false]); exit; }
    try {
        $st = $pdo->prepare("SELECT id, doc_version, created_at FROM consents WHERE user_id=? AND consent_type=? AND status='active' ORDER BY id DESC LIMIT 1");
        $st->execute([$userId, $type]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'granted' => (bool)$row, 'consent' => $row ?: null]);
    } catch (Exception $e) { echo json_encode(['ok' => true, 'granted' => false]); }
    exit;
}

if ($action === 'mine') {
    try {
        $st = $pdo->prepare("SELECT id, consent_type, doc_version, status, ip, created_at, revoked_at FROM consents WHERE user_id=? ORDER BY id DESC");
        $st->execute([$userId]);
        echo json_encode(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { echo json_encode(['ok' => true, 'data' => []]); }
    exit;
}

if ($action === 'revoke') {
    $type = trim((string)($body['type'] ?? ''));
    if (!in_array($type, $ALLOWED_TYPES, true)) { http_response_code(400); echo json_encode(['error' => 'Неизвестный тип согласия']); exit; }
    try {
        $pdo->prepare("UPDATE consents SET status='revoked', revoked_at=NOW() WHERE user_id=? AND consent_type=? AND status='active'")->execute([$userId, $type]);
        audit($pdo, $userId, 'consent_revoked', ['type' => $type]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось отозвать согласие']); }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Неизвестное действие']);
