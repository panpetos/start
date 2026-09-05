<?php
/**
 * ip_guard.php — общий «сторож» доступа по IP. Подключается в начале любого
 * эндпоинта: если IP клиента в чёрном списке (таблица ip_blocklist) — отдаёт 403.
 *
 * Безопасно по умолчанию: если таблицы нет или запрос упал — НЕ блокирует (сайт
 * работает как обычно). Таблицу заводит и наполняет админка через api/ip_block.php.
 *
 * Использование в эндпоинте (после того, как получен $pdo):
 *   require_once __DIR__ . '/ip_guard.php';
 *   if (function_exists('psyIpGuard')) psyIpGuard($pdo);
 */

if (!function_exists('psyClientIp')) {
    function psyClientIp(): string {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(',', $_SERVER[$k])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }
}

if (!function_exists('psyIpBlocked')) {
    function psyIpBlocked(PDO $pdo): bool {
        static $cached = null;
        if ($cached !== null) return $cached;
        $cached = false;
        try {
            $ip = psyClientIp();
            if ($ip === '') return $cached;
            $st = $pdo->prepare("SELECT 1 FROM ip_blocklist WHERE ip = ? LIMIT 1");
            $st->execute([$ip]);
            $cached = (bool)$st->fetchColumn();
        } catch (Exception $e) { $cached = false; }  // нет таблицы/ошибка — не мешаем работе
        return $cached;
    }
}

if (!function_exists('psyIpGuard')) {
    function psyIpGuard(PDO $pdo): void {
        if (psyIpBlocked($pdo)) {
            http_response_code(403);
            if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Доступ с вашего IP ограничен администратором.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}
