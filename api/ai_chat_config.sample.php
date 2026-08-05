<?php
/**
 * ОБРАЗЕЦ конфигурации ИИ-чата (OpenRouter).
 *
 * КАК ПОЛЬЗОВАТЬСЯ:
 *   1) Скопируйте этот файл рядом под именем  ai_chat_config.php
 *   2) Впишите ваш API-ключ OpenRouter.
 *   3) Файл ai_chat_config.php НЕ коммитится в git (см. .gitignore).
 */

return [
    'api_key' => 'sk-or-v1-ВПИШИТЕ_ВАШ_КЛЮЧ',

    'models' => [
        'deepseek/deepseek-chat-v3-0324:free' => 'DeepSeek V3',
        'qwen/qwen3-235b-a22b:free'           => 'Qwen 3 235B',
        'deepseek/deepseek-r1:free'            => 'DeepSeek R1 (думающий)',
        'google/gemini-2.5-flash-preview:free' => 'Gemini 2.5 Flash',
    ],
];
