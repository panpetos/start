<?php
/**
 * inn_check.php — проверка ИНН: контрольная сумма + статус самозанятого (НПД).
 *
 * Проверяет корректность ИНН (10 знаков — ИП/юрлицо, 12 — физлицо/самозанятый) по
 * контрольным цифрам и обращается к официальному сервису ФНС для проверки статуса
 * налогоплательщика НПД (самозанятость): statusnpd.nalog.ru.
 *
 * POST ?action=check  {inn}
 *   → {ok, valid, length, kind, self_employed: true|false|null, message}
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// validInn() и checkSelfEmployed() живут в npd_lib.php: та же проверка нужна
// перед каждой выплатой (api/payouts.php), а две копии одной логики рано или
// поздно разъезжаются.
require_once __DIR__ . '/npd_lib.php';

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$inn = preg_replace('/\D+/', '', (string)($body['inn'] ?? ''));

if ($inn === '') { http_response_code(400); echo json_encode(['error' => 'Введите ИНН']); exit; }

$valid = validInn($inn);
$len = strlen($inn);
$kind = $len === 10 ? 'Юрлицо / ИП' : ($len === 12 ? 'Физлицо / ИП / самозанятый' : '—');

if (!$valid) {
    echo json_encode(['ok' => true, 'valid' => false, 'length' => $len, 'kind' => $kind,
        'self_employed' => null, 'message' => 'ИНН некорректен (не сходится контрольная сумма)']);
    exit;
}

$selfEmployed = checkSelfEmployed($inn);
$message = $selfEmployed === true ? 'ИНН корректен. Подтверждён статус самозанятого (НПД).'
    : ($selfEmployed === false ? 'ИНН корректен. Статус самозанятого (НПД) не найден — возможно, ИП или физлицо.'
    : 'ИНН корректен. Проверку статуса в ФНС выполнить не удалось — проверьте позже.');

echo json_encode(['ok' => true, 'valid' => true, 'length' => $len, 'kind' => $kind,
    'self_employed' => $selfEmployed, 'message' => $message]);
