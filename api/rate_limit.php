<?php
/**
 * rate_limit.php — простой антифлуд для эндпоинтов.
 *
 * ЗАЧЕМ. Ни на отправке сообщений/историй, ни на анонимной поддержке не было
 * никаких ограничений частоты: один бот мог забить базу и диск. Здесь — общий
 * лёгкий лимитер по «корзинам» (bucket = что-то + кто).
 *
 * ГДЕ ХРАНИМ СЧЁТ. Если доступен APCu — в памяти процесса (быстро, без нагрузки
 * на БД). Иначе — в таблице rate_limits (запасной путь). При любом сбое лимитер
 * пропускает запрос: он защита от флуда, а не повод ронять сервис живым людям.
 *
 * ИСПОЛЬЗОВАНИЕ:
 *   require_once __DIR__.'/rate_limit.php';
 *   if (!psyRateLimit($pdo, 'support_start:'.psyClientIp(), 5, 60)) { ... 429 ... }
 */

if (!function_exists('psyClientIp')) {
    /** IP клиента: за прокси reg.ru реальный адрес может быть в X-Forwarded-For. */
    function psyClientIp(): string {
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff !== '') { $p = trim(explode(',', $xff)[0]); if ($p !== '') return substr($p, 0, 45); }
        return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    }
}

if (!function_exists('psyRateLimit')) {
    /**
     * true — можно; false — превышен лимит ($max действий за $windowSec секунд).
     * $bucket — уникальный ключ действия+субъекта (например "story_create:<userId>").
     */
    function psyRateLimit($pdo, string $bucket, int $max, int $windowSec): bool {
        $bucket = substr($bucket, 0, 150);
        $now = time();

        // Быстрый путь — APCu (в памяти, без БД).
        if (function_exists('apcu_enabled') && @apcu_enabled()) {
            $k = 'psyrl:' . $bucket;
            $v = apcu_fetch($k);
            if (!is_array($v) || ($now - (int)($v[1] ?? 0)) >= $windowSec) {
                apcu_store($k, [1, $now], $windowSec);
                return true;
            }
            if ((int)$v[0] >= $max) return false;
            apcu_store($k, [(int)$v[0] + 1, (int)$v[1]], $windowSec);
            return true;
        }

        // Запасной путь — таблица. Гонки возможны, но для антифлуда некритично.
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
                bucket VARCHAR(160) NOT NULL PRIMARY KEY,
                cnt INT NOT NULL,
                window_start INT NOT NULL
            ) DEFAULT CHARSET=utf8mb4");
            $st = $pdo->prepare("SELECT cnt, window_start FROM rate_limits WHERE bucket = ? LIMIT 1");
            $st->execute([$bucket]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row || ($now - (int)$row['window_start']) >= $windowSec) {
                $pdo->prepare("INSERT INTO rate_limits (bucket, cnt, window_start) VALUES (?, 1, ?)
                               ON DUPLICATE KEY UPDATE cnt = 1, window_start = VALUES(window_start)")
                    ->execute([$bucket, $now]);
                return true;
            }
            if ((int)$row['cnt'] >= $max) return false;
            $pdo->prepare("UPDATE rate_limits SET cnt = cnt + 1 WHERE bucket = ?")->execute([$bucket]);
            return true;
        } catch (Exception $e) {
            return true;   // лимитер сломался — не мешаем людям
        }
    }
}
