<?php
/**
 * Образец настроек звонков. Рабочий файл — api/rtc_calls_config.php (он в .gitignore,
 * потому что содержит пароль от TURN).
 *
 * Нужен только TURN — ретранслятор для тех пар, где прямое соединение не встаёт
 * (оба за «строгим» NAT: мобильные операторы, корпоративные сети). Без него звонки
 * работают, но у части людей будут срываться на «не удалось соединиться».
 *
 * TURN нельзя поднять на shared-хостинге: нужен сервер с открытыми UDP-портами.
 * Варианты: свой coturn на самом дешёвом VPS либо готовый платный сервис.
 *
 * Пример для coturn со статическим паролем:
 *   turnserver -a -u psytalk:ПАРОЛЬ -r psytalk.pro --no-tls --no-dtls
 */
return [
    'turn_urls' => [
        // 'turn:turn.psytalk.pro:3478?transport=udp',
        // 'turn:turn.psytalk.pro:3478?transport=tcp',
    ],
    'turn_username'   => '',
    'turn_credential' => '',
];
