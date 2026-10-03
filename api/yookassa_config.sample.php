<?php
// YooKassa (ЮKassa) — конфигурация.
// Скопируйте в yookassa_config.php и заполните реальными данными.
// НЕ коммитить реальные ключи в git!
return [
    'shop_id'    => '',          // ID магазина из ЛК YooKassa
    'secret_key' => '',          // Секретный ключ из ЛК YooKassa
    'is_test'    => true,        // true = тестовый режим, false = боевой
    'return_url' => 'https://psytalk.pro/payment-success.html',
    'webhook_secret' => '',      // Секрет для проверки уведомлений (если настроен)
    // Whitelist IP для вебхуков: 185.71.76.0/27, 185.71.77.0/27, 77.75.153.0/25
];
