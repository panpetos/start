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

/** Проверка контрольных цифр ИНН (10 или 12 знаков). */
function validInn(string $inn): bool {
    if (!preg_match('/^\d{10}$/', $inn) && !preg_match('/^\d{12}$/', $inn)) return false;
    $d = array_map('intval', str_split($inn));
    $csum = function (array $digits, array $coef) {
        $s = 0; foreach ($coef as $i => $c) $s += $c * $digits[$i];
        return ($s % 11) % 10;
    };
    if (strlen($inn) === 10) {
        $n10 = $csum($d, [2, 4, 10, 3, 5, 9, 4, 6, 8]);
        return $n10 === $d[9];
    }
    $n11 = $csum($d, [7, 2, 4, 10, 3, 5, 9, 4, 6, 8]);
    $n12 = $csum($d, [3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8]);
    return $n11 === $d[10] && $n12 === $d[11];
}

/** Запрос статуса самозанятого (НПД) в ФНС. Возвращает true/false/null (если сервис недоступен). */
function checkSelfEmployed(string $inn): ?bool {
    $payload = json_encode(['inn' => $inn, 'requestDate' => date('Y-m-d')]);
    $url = 'https://statusnpd.nalog.ru/api/v1/tracker/taxpayer_status';
    $raw = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'psytalk.pro-verify/1.0',
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) $raw = null;
        curl_close($ch);
    }
    if ($raw === null) {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => $payload,
            'timeout' => 8,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) return null;
    if (array_key_exists('status', $data)) return (bool)$data['status'];
    return null;
}

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
