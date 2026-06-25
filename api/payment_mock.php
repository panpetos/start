<?php
/**
 * Mock payment endpoint for development/testing.
 * Enabled only when settings.mock_payment_enabled = 1 OR ?force=DEV_SECRET.
 *
 * action=init  — create appointment + mark payment success, return appointment_id
 * action=status — get payment/appointment status by appointment_id
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$pdo = getDB();

// ── Auth check ──────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['error' => 'Требуется авторизация']);
    exit;
}

// ── Mock mode guard ──────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT value FROM settings WHERE key_name = 'mock_payment_enabled' LIMIT 1");
$stmt->execute();
$mockEnabled = $stmt->fetchColumn();

if ($mockEnabled !== '1') {
    http_response_code(403);
    echo json_encode(['error' => 'Тестовая оплата отключена']);
    exit;
}

// ── Route ────────────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? '';

if ($action === 'init') {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    $psychologistId = $data['psychologistId'] ?? null;
    $dateTime       = $data['dateTime']       ?? null;
    $format         = $data['format']         ?? 'video';
    $price          = (float)($data['price']  ?? 0);

    if (!$psychologistId || !$dateTime) {
        http_response_code(400);
        echo json_encode(['error' => 'Не указан психолог или дата']);
        exit;
    }

    // Validate format
    $allowedFormats = ['video', 'audio', 'chat'];
    if (!in_array($format, $allowedFormats)) $format = 'video';

    // Check psychologist exists and get actual price
    $stmt = $pdo->prepare("SELECT id, price FROM psychologists WHERE id = ? AND is_approved = 1 LIMIT 1");
    $stmt->execute([$psychologistId]);
    $psy = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$psy) {
        http_response_code(404);
        echo json_encode(['error' => 'Психолог не найден']);
        exit;
    }
    if ($price <= 0) $price = (float)$psy['price'];

    // Check slot is not already taken
    $stmt = $pdo->prepare(
        "SELECT id FROM appointments
         WHERE psychologist_id = ? AND date_time = ? AND status != 'cancelled' LIMIT 1"
    );
    $stmt->execute([$psychologistId, $dateTime]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Это время уже занято']);
        exit;
    }

    $pdo->beginTransaction();
    try {
        // Create appointment
        $appointmentId = generateUUID();
        $stmt = $pdo->prepare(
            "INSERT INTO appointments (id, client_id, psychologist_id, date_time, duration, format, status, price)
             VALUES (?, ?, ?, ?, 50, ?, 'scheduled', ?)"
        );
        $stmt->execute([$appointmentId, $userId, $psychologistId, $dateTime, $format, $price]);

        // Create payment record — status success (mock)
        $paymentId = generateUUID();
        $stmt = $pdo->prepare(
            "INSERT INTO payments (id, appointment_id, amount, status, paid_at)
             VALUES (?, ?, ?, 'success', NOW())"
        );
        $stmt->execute([$paymentId, $appointmentId, $price]);

        $pdo->commit();

        echo json_encode([
            'ok'             => true,
            'appointment_id' => $appointmentId,
            'payment_id'     => $paymentId,
            'mock'           => true,
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('payment_mock init error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Ошибка создания записи']);
    }

} elseif ($action === 'status') {

    $appointmentId = $_GET['appointment_id'] ?? null;
    if (!$appointmentId) {
        http_response_code(400);
        echo json_encode(['error' => 'Не указан appointment_id']);
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT a.id, a.status, a.date_time, a.format, a.price,
                p.status AS payment_status, p.paid_at
         FROM appointments a
         LEFT JOIN payments p ON p.appointment_id = a.id
         WHERE a.id = ? AND a.client_id = ?
         LIMIT 1"
    );
    $stmt->execute([$appointmentId, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Запись не найдена']);
        exit;
    }

    echo json_encode(['ok' => true, 'data' => $row]);

} else {
    http_response_code(400);
    echo json_encode(['error' => 'Неизвестное действие']);
}

function generateUUID(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}
