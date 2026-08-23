<?php
/**
 * promo_booking.php — запись по промокоду, без оплаты.
 *
 * ЗАЧЕМ. Приём платежей ещё не настроен, а проверить весь путь — «клиент
 * записался → психолог увидел → созвонились» — нужно уже сейчас, на живом сайте
 * и настоящими аккаунтами. Общий тумблер «тестовая оплата» для этого не годится:
 * он делает бесплатным вообще всё и для всех. Промокод знает только тот, кому
 * его дали.
 *
 * ГДЕ ЗАДАЁТСЯ КОД. В админке, вкладка «Тарифы» → «Промокод бесплатной записи»
 * (настройка free_promo_code). Пустое значение = функция ВЫКЛЮЧЕНА целиком:
 * никакой код не подойдёт, в том числе пустой. Это важнее удобства — иначе
 * забытая пустая настройка открыла бы бесплатную запись всем.
 *
 * ДЕЙСТВИЯ
 *   POST ?action=check {code}                                  → подходит ли код
 *   POST ?action=book  {code, psychologistId, dateTime, format} → создать запись
 *
 * ПОЧЕМУ ПРОВЕРКА ЗДЕСЬ, А НЕ В БРАУЗЕРЕ. Иначе цену можно было бы обнулить
 * из консоли. Код сверяется на сервере при КАЖДОЙ записи, цена ставится нулевая
 * тоже здесь, а не приходит из запроса.
 *
 * ОГРАНИЧИТЕЛИ. Код может утечь, поэтому: не больше free_promo_limit записей на
 * человека (по умолчанию 5) и не больше 10 неудачных попыток подбора в сутки.
 * Каждая бесплатная запись пишется в promo_bookings — видно, кто и когда.
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
if (!$pdo) { http_response_code(500); echo json_encode(['error' => 'Нет подключения к БД']); exit; }

if (session_status() === PHP_SESSION_NONE) session_start();
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }

function pbOut($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

function pbSetting(PDO $pdo, $key, $default = '') {
    try {
        $st = $pdo->prepare("SELECT value FROM settings WHERE key_name = ? LIMIT 1");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return ($v === false || $v === null) ? $default : (string)$v;
    } catch (Exception $e) { return $default; }
}

function pbEnsure(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS promo_bookings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        appointment_id VARCHAR(64) NOT NULL,
        user_id VARCHAR(64) NOT NULL,
        code VARCHAR(64) NOT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_user (user_id),
        UNIQUE KEY uniq_appt (appointment_id)
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS promo_attempts (
        user_id VARCHAR(64) NOT NULL,
        day DATE NOT NULL,
        fails INT NOT NULL DEFAULT 0,
        PRIMARY KEY (user_id, day)
    ) DEFAULT CHARSET=utf8mb4");
}

function pbNorm($s) { return strtoupper(trim(preg_replace('/\s+/', '', (string)$s))); }

/** Сколько раз сегодня не угадали. Защита от перебора кода. */
function pbFails(PDO $pdo, $userId) {
    try {
        $st = $pdo->prepare("SELECT fails FROM promo_attempts WHERE user_id = ? AND day = CURDATE()");
        $st->execute([$userId]);
        return (int)$st->fetchColumn();
    } catch (Exception $e) { return 0; }
}
function pbCountFail(PDO $pdo, $userId) {
    try {
        $pdo->prepare("INSERT INTO promo_attempts (user_id, day, fails) VALUES (?, CURDATE(), 1)
                       ON DUPLICATE KEY UPDATE fails = fails + 1")->execute([$userId]);
    } catch (Exception $e) {}
}

/**
 * Подходит ли код. Возвращает [ok, причина].
 * Сравнение через hash_equals: обычное == по строкам подсказывает длину и
 * совпавший префикс временем ответа.
 */
function pbCheckCode(PDO $pdo, $userId, $code) {
    $set = pbNorm(pbSetting($pdo, 'free_promo_code', ''));
    if ($set === '') return [false, 'Бесплатная запись по промокоду сейчас отключена'];
    if (pbFails($pdo, $userId) >= 10) return [false, 'Слишком много попыток. Попробуйте завтра'];
    $given = pbNorm($code);
    if ($given === '' || !hash_equals($set, $given)) {
        pbCountFail($pdo, $userId);
        return [false, 'Промокод не подошёл'];
    }
    $limit = (int)pbSetting($pdo, 'free_promo_limit', '5');
    if ($limit > 0) {
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM promo_bookings WHERE user_id = ?");
            $st->execute([$userId]);
            if ((int)$st->fetchColumn() >= $limit) {
                return [false, 'По этому промокоду вы уже записались ' . $limit . ' раз(а)'];
            }
        } catch (Exception $e) {}
    }
    return [true, ''];
}

function pbUuid() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
}

try { pbEnsure($pdo); } catch (Exception $e) { pbOut(['error' => 'Не удалось подготовить таблицы'], 500); }

$action = $_GET['action'] ?? '';
$body = json_decode(file_get_contents('php://input'), true) ?: [];

if ($action === 'check') {
    list($ok, $why) = pbCheckCode($pdo, $userId, $body['code'] ?? '');
    pbOut(['ok' => true, 'valid' => $ok, 'error' => $ok ? '' : $why]);
}

if ($action === 'book') {
    list($ok, $why) = pbCheckCode($pdo, $userId, $body['code'] ?? '');
    if (!$ok) pbOut(['error' => $why], 403);

    $psyId = $body['psychologistId'] ?? null;
    $dateTime = (string)($body['dateTime'] ?? '');
    $format = (string)($body['format'] ?? 'video');
    if (!in_array($format, ['video', 'audio', 'chat'], true)) $format = 'video';
    if (!$psyId || $dateTime === '') pbOut(['error' => 'Не указан психолог или время'], 400);
    // Дата приходит от браузера — принимаем только понятный формат, а не что придётся
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $dateTime)
       ?: DateTime::createFromFormat('Y-m-d\TH:i:s', $dateTime)
       ?: DateTime::createFromFormat('Y-m-d\TH:i', $dateTime);
    if (!$dt) pbOut(['error' => 'Не понял дату и время'], 400);
    $sql = $dt->format('Y-m-d H:i:s');
    if ($dt->getTimestamp() < time() - 3600) pbOut(['error' => 'Это время уже прошло'], 400);

    try {
        $st = $pdo->prepare("SELECT id FROM psychologists WHERE id = ? AND is_approved = 1 LIMIT 1");
        $st->execute([$psyId]);
        if (!$st->fetch()) pbOut(['error' => 'Психолог не найден'], 404);

        $st = $pdo->prepare("SELECT id FROM appointments
                              WHERE psychologist_id = ? AND date_time = ? AND status != 'cancelled' LIMIT 1");
        $st->execute([$psyId, $sql]);
        if ($st->fetch()) pbOut(['error' => 'Это время уже занято'], 409);
    } catch (Exception $e) { pbOut(['error' => 'Не удалось проверить время'], 500); }

    $apptId = pbUuid();
    $pdo->beginTransaction();
    try {
        // Цена нулевая проставляется ЗДЕСЬ, а не берётся из запроса: иначе её
        // можно было бы подменить и записаться «бесплатно» без промокода.
        $pdo->prepare("INSERT INTO appointments (id, client_id, psychologist_id, date_time, duration, format, status, price)
                       VALUES (?, ?, ?, ?, 50, ?, 'scheduled', 0)")
            ->execute([$apptId, $userId, $psyId, $sql, $format]);
        $pdo->prepare("INSERT INTO payments (id, appointment_id, amount, status, paid_at)
                       VALUES (?, ?, 0, 'success', NOW())")
            ->execute([pbUuid(), $apptId]);
        $pdo->prepare("INSERT INTO promo_bookings (appointment_id, user_id, code, created_at)
                       VALUES (?, ?, ?, NOW())")
            ->execute([$apptId, $userId, pbNorm($body['code'] ?? '')]);
        $pdo->commit();
    } catch (Exception $e) {
        try { $pdo->rollBack(); } catch (Exception $e2) {}
        pbOut(['error' => 'Не удалось создать запись'], 500);
    }

    pbOut(['ok' => true, 'appointment_id' => $apptId, 'promo' => true]);
}

pbOut(['error' => 'Неизвестное действие'], 400);
