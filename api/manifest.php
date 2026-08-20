<?php
/**
 * manifest.php — манифест приложения (PWA) с ЯВНЫМ типом содержимого.
 *
 * Почему не статический .webmanifest: reg.ru отдаёт такой файл вообще без заголовка
 * Content-Type, да ещё с X-Content-Type-Options: nosniff. Браузер отказывается считать
 * ответ манифестом — установка приложения не предлагается, а на iPhone не подхватываются
 * имя и иконка для экрана «Домой». Здесь заголовок наш, и это работает на любом хостинге.
 *
 * Содержимое живёт прямо здесь и больше нигде: пара «php + отдельный json» уже разошлась
 * бы при первой правке (в запасном варианте, например, потерялись быстрые действия).
 */
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$icon = fn($size, $purpose) => [
    'src' => '/assets/icon-' . ($purpose === 'maskable' ? 'maskable-' : '') . $size . '.png',
    'sizes' => $size . 'x' . $size,
    'type' => 'image/png',
    'purpose' => $purpose,
];

echo json_encode([
    'name' => 'psytalk.pro — психологи для удалёнки',
    'short_name' => 'psytalk',
    'description' => 'Записи к психологу, чаты, голосовые и видеосообщения, каналы и лента — весь сервис в приложении.',
    'lang' => 'ru-RU',
    'start_url' => '/client-dashboard.html?src=app',
    'scope' => '/',
    'display' => 'standalone',
    'orientation' => 'portrait-primary',
    'background_color' => '#ECFDF5',
    'theme_color' => '#047857',
    'icons' => [$icon(192, 'any'), $icon(512, 'any'), $icon(512, 'maskable')],
    // Долгое нажатие на иконку приложения: сервис — это не только переписка,
    // поэтому здесь и записи, и подбор специалиста, и лента.
    'shortcuts' => [
        ['name' => 'Чаты', 'short_name' => 'Чаты', 'url' => '/chat.html?src=app',
         'icons' => [['src' => '/assets/icon-192.png', 'sizes' => '192x192']]],
        ['name' => 'Мои записи', 'short_name' => 'Записи', 'url' => '/client-dashboard.html?src=app'],
        ['name' => 'Найти психолога', 'short_name' => 'Поиск', 'url' => '/search.html?src=app'],
        ['name' => 'Лента', 'short_name' => 'Лента', 'url' => '/feed.html?src=app'],
    ],
    'categories' => ['health', 'medical', 'social'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
