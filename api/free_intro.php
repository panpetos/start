<?php
/**
 * free_intro.php — бесплатная вводная сессия 20 минут у любого психолога.
 *
 * Каждый клиент может один раз записаться к КАЖДОМУ психологу на бесплатную
 * 20-минутную вводную сессию — чтобы решить, подходит ли специалист, не платя
 * сразу полную цену. Право на неё определяется тем, было ли у клиента раньше
 * хоть одно бронирование (платное или уже бесплатное) с этим же психологом —
 * если было, вводная уже не положена.
 *
 * ДЕЙСТВИЯ
 *   GET  ?action=eligible&psychologist_id=ID   → положена ли вводная сессия
 *   POST ?action=book {psychologistId, dateTime, format} → создать запись
 *
 * ПОЧЕМУ ПРОВЕРКА И ЦЕНА — ЗДЕСЬ, А НЕ В БРАУЗЕРЕ. Иначе цену можно было бы
 * обнулить из консоли или записаться на вводную повторно. Право пересчитывается
 * на сервере при каждой попытке записи, цена нулевая ставится тоже здесь.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/settings_lib.php';
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

function fiOut($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

const FI_DURATION = 20;

function fiEnsure(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS free_intro_bookings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        appointment_id VARCHAR(64) NOT NULL,
        user_id VARCHAR(64) NOT NULL,
        psychologist_id VARCHAR(64) NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uniq_appt (appointment_id),
        INDEX idx_user_psy (user_id, psychologist_id)
    ) DEFAULT CHARSET=utf8mb4");
}

/** Была ли у клиента хоть одна не отменённая запись к этому психологу раньше. */
function fiHasPriorBooking(PDO $pdo, $userId, $psyId) {
    try {
        $st = $pdo->prepare("SELECT id FROM appointments
                              WHERE client_id = ? AND psychologist_id = ? AND status != 'cancelled' LIMIT 1");
        $st->execute([$userId, $psyId]);
        return (bool)$st->fetch();
    } catch (Exception $e) { return true; /* при ошибке — не даём воспользоваться повторно */ }
}

function fiEligible(PDO $pdo, $userId, $psyId) {
    if (!$psyId) return false;
    return !fiHasPriorBooking($pdo, $userId, $psyId);
}

function fiUuid() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
}

try { fiEnsure($pdo); } catch (Exception $e) { fiOut(['error' => 'Не удалось подготовить таблицы'], 500); }

$action = $_GET['action'] ?? '';
$body = json_decode(file_get_contents('php://input'), true) ?: [];

if ($action === 'eligible') {
    $psyId = $_GET['psychologist_id'] ?? '';
    fiOut(['ok' => true, 'eligible' => fiEligible($pdo, $userId, $psyId), 'duration' => FI_DURATION]);
}

if ($action === 'book') {
    $psyId = $body['psychologistId'] ?? null;
    $dateTime = (string)($body['dateTime'] ?? '');
    $format = (string)($body['format'] ?? 'video');
    if (!in_array($format, ['video', 'audio', 'chat'], true)) $format = 'video';
    if (!$psyId || $dateTime === '') fiOut(['error' => 'Не указан психолог или время'], 400);

    if (!fiEligible($pdo, $userId, $psyId)) {
        fiOut(['error' => 'Вводная сессия у этого специалиста уже использована'], 403);
    }

    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $dateTime)
       ?: DateTime::createFromFormat('Y-m-d\TH:i:s', $dateTime)
       ?: DateTime::createFromFormat('Y-m-d\TH:i', $dateTime);
    if (!$dt) fiOut(['error' => 'Не понял дату и время'], 400);
    $sql = $dt->format('Y-m-d H:i:s');
    if ($dt->getTimestamp() < time() - 3600) fiOut(['error' => 'Это время уже прошло'], 400);

    try {
        $st = $pdo->prepare("SELECT id FROM psychologists WHERE id = ? AND is_approved = 1 LIMIT 1");
        $st->execute([$psyId]);
        if (!$st->fetch()) fiOut(['error' => 'Психолог не найден'], 404);

        $st = $pdo->prepare("SELECT id FROM appointments
                              WHERE psychologist_id = ? AND date_time = ? AND status != 'cancelled' LIMIT 1");
        $st->execute([$psyId, $sql]);
        if ($st->fetch()) fiOut(['error' => 'Это время уже занято'], 409);
    } catch (Exception $e) { fiOut(['error' => 'Не удалось проверить время'], 500); }

    $apptId = fiUuid();
    $pdo->beginTransaction();
    try {
        // Цена и длительность — здесь, не из запроса: иначе полную сессию можно
        // было бы оформить как «вводную» бесплатно из консоли браузера.
        $pdo->prepare("INSERT INTO appointments (id, client_id, psychologist_id, date_time, duration, format, status, price)
                       VALUES (?, ?, ?, ?, ?, ?, 'scheduled', 0)")
            ->execute([$apptId, $userId, $psyId, $sql, FI_DURATION, $format]);
        $pdo->prepare("INSERT INTO payments (id, appointment_id, amount, status, paid_at)
                       VALUES (?, ?, 0, 'success', NOW())")
            ->execute([fiUuid(), $apptId]);
        $pdo->prepare("INSERT INTO free_intro_bookings (appointment_id, user_id, psychologist_id, created_at)
                       VALUES (?, ?, ?, NOW())")
            ->execute([$apptId, $userId, $psyId]);
        $pdo->commit();
    } catch (Exception $e) {
        try { $pdo->rollBack(); } catch (Exception $e2) {}
        fiOut(['error' => 'Не удалось создать запись'], 500);
    }

    fiOut(['ok' => true, 'appointment_id' => $apptId, 'free_intro' => true, 'duration' => FI_DURATION]);
}

fiOut(['error' => 'Неизвестное действие'], 400);
