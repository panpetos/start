<?php
/**
 * npd_lib.php — проверка ИНН и статуса самозанятого (НПД) в ФНС.
 *
 * Вынесено из inn_check.php, потому что то же самое нужно перед выплатой:
 * платформа-агент обязана убедиться, что исполнитель на момент расчёта
 * действительно плательщик НПД. Держать две копии одной проверки — верный
 * способ однажды поправить только одну из них.
 *
 * Функции обёрнуты в function_exists: файл могут подключить дважды по разным
 * путям, и повторное объявление уронило бы запрос целиком.
 */

if (!function_exists('validInn')) {
    /** Проверка контрольных цифр ИНН (10 или 12 знаков). */
    function validInn(string $inn): bool {
        if (!preg_match('/^\d{10}$/', $inn) && !preg_match('/^\d{12}$/', $inn)) return false;
        $d = array_map('intval', str_split($inn));
        $csum = function (array $digits, array $coef) {
            $s = 0;
            foreach ($coef as $i => $c) $s += $c * $digits[$i];
            return ($s % 11) % 10;
        };
        if (count($d) === 10) {
            return $csum($d, [2, 4, 10, 3, 5, 9, 4, 6, 8]) === $d[9];
        }
        $n11 = $csum($d, [7, 2, 4, 10, 3, 5, 9, 4, 6, 8]);
        $n12 = $csum($d, [3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8]);
        return $n11 === $d[10] && $n12 === $d[11];
    }
}

if (!function_exists('checkSelfEmployed')) {
    /** Статус самозанятого (НПД) в ФНС. true / false / null (сервис недоступен). */
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
}
