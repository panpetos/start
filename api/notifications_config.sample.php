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
    'email_method'   => '',   // 'resend' (рекомендуется) | 'smtp' | 'mail'
    // — Resend API (отправка) —
    'resend_key'     => '',    // API-ключ Resend (re_...)
    'resend_from'    => 'psytalk.pro <noreply@psytalk.pro>',
    'resend_replyto' => 'support@psytalk.pro', // ответы придут сюда (ящик на reg.ru)
    // — SMTP (альтернатива) —
    'smtp_host'      => '',    // например smtp.hosting.reg.ru
    'smtp_port'      => '465', // 465 (SSL) или 587 (TLS)
    'smtp_user'      => '',    // support@psytalk.pro
    'smtp_pass'      => '',    // пароль ящика
    'smtp_from'      => 'psytalk.pro <support@psytalk.pro>',
];
