<?php
/**
 * notify_dispatch_config.sample.php — шаблон секрета прогона рассылки уведомлений.
 *
 * Реальный файл api/notify_dispatch_config.php в git НЕ хранится (см. .gitignore)
 * и обычно создаётся из админки. Если создаёте вручную (например, после переезда
 * на ВДС) — скопируйте этот файл в notify_dispatch_config.php и впишите токен.
 *
 * Токеном защищён вызов api/notify_dispatch.php (его дёргает cron/Routine, чтобы
 * разослать накопившиеся уведомления). Передаётся в ?token= или заголовке
 * X-Notify-Token. Сами ключи почты (Resend/SMTP) лежат в notifications_config.php.
 */
return [
    'token' => 'CHANGE_ME_длинный_случайный_токен_минимум_16_символов',
];
