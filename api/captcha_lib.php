<?php
/**
 * captcha_lib.php — проверка Яндекс SmartCaptcha (антиспам на публичных формах).
 *
 * Секрет (server_key) — в api/captcha_config.php вне git. Если не задан, проверка
 * пропускается (сайт работает и без настроенной капчи). Если Яндекс недоступен —
 * НЕ блокируем пользователя (fail-open), чтобы не ронять формы из-за их сбоя.
 *
 * Только функции; подключается require_once из эндпоинтов (subscribe.php и др.).
 */

if (!function_exists('captchaCfg')) {
    function captchaCfg(): array {
        static $c = null;
        if ($c !== null) return $c;
        $f = __DIR__ . '/captcha_config.php';
        $c = file_exists($f) ? (include $f) : [];
        if (!is_array($c)) $c = [];
        return $c;
    }
    /** Публичный ключ (для виджета). '' если не настроен. */
    function captchaSiteKey(): string {
        $k = (string)(captchaCfg()['client_key'] ?? '');
        return (strpos($k, 'ВПИШИТЕ') === false) ? trim($k) : '';
    }
    /** Секретный ключ (для проверки). '' если не настроен. */
    function captchaSecret(): string {
        $k = (string)(captchaCfg()['server_key'] ?? '');
        return (strpos($k, 'ВПИШИТЕ') === false) ? trim($k) : '';
    }
    function captchaEnabled(): bool { return captchaSecret() !== ''; }

    /**
     * Проверить токен капчи. true — пройдено (или капча не настроена / сервис недоступен);
     * false — токен пустой или Яндекс явно ответил «failed».
     */
    function captchaVerify(?string $token, ?string $ip = null): bool {
        $secret = captchaSecret();
        if ($secret === '') return true;              // капча не настроена — не блокируем
        $token = trim((string)$token);
        if ($token === '') return false;              // настроена, но токена нет — это бот/ошибка
        $ip = $ip !== null ? $ip : ($_SERVER['REMOTE_ADDR'] ?? '');
        $url = 'https://smartcaptcha.yandexcloud.net/validate?secret=' . urlencode($secret)
             . '&token=' . urlencode($token) . '&ip=' . urlencode($ip);
        $raw = null;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_SSL_VERIFYPEER => true]);
            $raw = curl_exec($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            if ($errno) return true;                  // сервис недоступен — не ломаем форму
        } else {
            $ctx = stream_context_create(['http' => ['timeout' => 5]]);
            $raw = @file_get_contents($url, false, $ctx);
            if ($raw === false) return true;
        }
        $d = json_decode($raw, true);
        return is_array($d) && (($d['status'] ?? '') === 'ok');
    }
}
