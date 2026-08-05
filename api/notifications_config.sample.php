<?php
/**
 * ШАБЛОН конфига каналов уведомлений.
 * Реальный файл — api/notifications_config.php — НЕ в git (см. .gitignore).
 * Заполняется из админки (вкладка «🔔 Уведомления» → «Каналы доставки»),
 * секреты в переписку не передаются.
 */
return [
    'telegram_token' => '',   // токен бота Telegram (BotFather)
    'max_token'      => '',   // токен бота MAX
    'email_method'   => '',   // 'smtp' (рекомендуется) или 'mail'
    'smtp_host'      => '',    // например mail.hosting.reg.ru
    'smtp_port'      => '465', // 465 (SSL) или 587 (TLS)
    'smtp_user'      => '',    // noreply@psytalk.pro
    'smtp_pass'      => '',    // пароль ящика
    'smtp_from'      => 'psytalk.pro <noreply@psytalk.pro>',
];
