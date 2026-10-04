<?php
/**
 * ai_post.php — помощник для постов психолога в его канал.
 *
 * Две задачи:
 *   POST ?action=text  {mode:'generate'|'rewrite', prompt?, current?}
 *        → {ok, text}   — сгенерировать пост по запросу или переписать имеющийся.
 *   POST ?action=image {prompt}
 *        → {ok, url}     — сгенерировать картинку к посту (YandexART).
 *
 * ИИ «понимает», кто пишет: в системную подсказку кладём профиль психолога —
 * имя, специализацию, опыт, о себе. Так текст выходит по теме и от первого лица,
 * а не абстрактный.
 *
 * Ключи и провайдер берём из того же ai_chat_config.php, что и ассистент, — второй
 * копии настроек не заводим. В репозитории ключей нет (файл вне git).
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';
if (!function_exists('getDB') && !function_exists('getDbConnection') && !function_exists('getPDO')) {
    require_once __DIR__ . '/db.php';
}
$pdo = function_exists('getDB') ? getDB()
     : (function_exists('getDbConnection') ? getDbConnection()
     : (function_exists('getPDO') ? getPDO() : null));

function apOut($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if (!$pdo) apOut(['error' => 'Нет подключения к БД'], 500);
if (session_status() === PHP_SESSION_NONE) session_start();
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) apOut(['error' => 'Требуется авторизация'], 401);

// Постить в канал может психолог (у клиента канала нет) и админ.
$role = '';
try { $st = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1"); $st->execute([$userId]); $role = (string)$st->fetchColumn(); }
catch (Exception $e) {}
if ($role !== 'psychologist' && $role !== 'admin') apOut(['error' => 'Доступно психологам'], 403);

// ── Настройки ИИ (те же, что у ассистента) ───────────────────────────────────
$cfgFile = __DIR__ . '/ai_chat_config.php';
$cfg = file_exists($cfgFile) ? (require $cfgFile) : [];
if (!is_array($cfg)) $cfg = [];
$provider = ($cfg['provider'] ?? 'yandex') === 'openrouter' ? 'openrouter' : 'yandex';
$apiKey   = trim((string)($cfg['api_key'] ?? ''));
$folderId = trim((string)($cfg['folder_id'] ?? ''));
if ($apiKey === '') apOut(['error' => 'ИИ не настроен: в админке не задан ключ API (вкладка «ИИ-ассистент»).'], 503);
if ($provider === 'yandex' && $folderId === '') apOut(['error' => 'Для Yandex не задан folder_id в настройках ИИ.'], 503);

/** Имя текстовой модели для выбранного провайдера. */
function apTextModel($cfg, $provider, $folderId) {
    $models = $cfg['models'] ?? [];
    $first = (is_array($models) && $models) ? (string)array_key_first($models) : '';
    if ($provider === 'openrouter') return $first !== '' ? $first : 'deepseek/deepseek-chat-v3-0324:free';
    $m = $first !== '' ? $first : 'yandexgpt-lite/latest';
    if (strpos($m, 'gpt://') === 0 || strpos($m, 'ds://') === 0) return $m;
    return 'gpt://' . $folderId . '/' . $m;
}

/** Профиль психолога одной строкой — контекст для модели. */
function apPsyProfile(PDO $pdo, $userId) {
    $facts = [];
    try {
        $st = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1");
        $st->execute([$userId]);
        if ($u = $st->fetch(PDO::FETCH_ASSOC)) {
            $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
            if ($name !== '') $facts[] = 'Имя: ' . $name;
        }
    } catch (Exception $e) {}
    try {
        $st = $pdo->prepare("SELECT specialization, experience, education, description FROM psychologists WHERE user_id = ? LIMIT 1");
        $st->execute([$userId]);
        if ($p = $st->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($p['specialization'])) $facts[] = 'Специализация: ' . $p['specialization'];
            if (!empty($p['experience']))     $facts[] = 'Опыт: ' . $p['experience'];
            if (!empty($p['education']))      $facts[] = 'Образование: ' . $p['education'];
            if (!empty($p['description']))    $facts[] = 'О себе: ' . mb_substr((string)$p['description'], 0, 600);
        }
    } catch (Exception $e) {}
    return implode("\n", $facts);
}

$action = $_GET['action'] ?? '';
$body = json_decode(file_get_contents('php://input'), true) ?: [];

// ── Генерация/переписывание текста ───────────────────────────────────────────
if ($action === 'text' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode    = ($body['mode'] ?? 'generate') === 'rewrite' ? 'rewrite' : 'generate';
    $prompt  = trim((string)($body['prompt'] ?? ''));
    $current = trim((string)($body['current'] ?? ''));
    if ($mode === 'generate' && $prompt === '') apOut(['error' => 'Напишите, о чём пост'], 400);
    if ($mode === 'rewrite' && $current === '') apOut(['error' => 'Нет текста для переписывания'], 400);

    $profile = apPsyProfile($pdo, $userId);
    $sys = "Ты помогаешь психологу вести его личный канал на платформе психологической помощи. "
         . "Пиши по-русски, от первого лица, тёплым, профессиональным и бережным тоном, без канцелярита и без обесценивания. "
         . "Не давай медицинских диагнозов и не обещай гарантированного результата. "
         . "Пост должен быть готов к публикации: 1–4 коротких абзаца, при уместности — список через '- '. "
         . "Без хэштегов и без markdown-заголовков '#'. Верни ТОЛЬКО текст поста, без пояснений."
         . ($profile !== '' ? "\n\nАвтор канала:\n" . $profile : '');
    $user = $mode === 'rewrite'
        ? "Перепиши и улучши этот пост, сохранив смысл и факты:\n\n" . mb_substr($current, 0, 4000)
          . ($prompt !== '' ? "\n\nПожелание: " . $prompt : '')
        : "Напиши пост на тему: " . mb_substr($prompt, 0, 1000)
          . ($current !== '' ? "\n\nМожно опереться на черновик:\n" . mb_substr($current, 0, 2000) : '');

    $endpoint = $provider === 'openrouter'
        ? 'https://openrouter.ai/api/v1/chat/completions'
        : 'https://llm.api.cloud.yandex.net/v1/chat/completions';
    $headers = ['Content-Type: application/json'];
    if ($provider === 'openrouter') {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
        $headers[] = 'HTTP-Referer: https://psytalk.pro';
        $headers[] = 'X-Title: PsyTalk Post Helper';
    } else {
        $headers[] = 'Authorization: Api-Key ' . $apiKey;
    }
    $payload = json_encode([
        'model' => apTextModel($cfg, $provider, $folderId),
        'temperature' => 0.7,
        'max_tokens' => 900,
        'messages' => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user',   'content' => $user],
        ],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) apOut(['error' => 'ИИ недоступен: ' . $err], 502);
    $d = json_decode($raw, true);
    $text = '';
    if (is_array($d)) {
        $text = $d['choices'][0]['message']['content']
            ?? $d['result']['alternatives'][0]['message']['text']   // формат Yandex, если вернётся он
            ?? '';
    }
    $text = trim((string)$text);
    if ($text === '') {
        $msg = is_array($d) ? ($d['error']['message'] ?? $d['message'] ?? '') : '';
        apOut(['error' => 'ИИ не вернул текст' . ($msg ? ': ' . $msg : '. Попробуйте ещё раз.')], 502);
    }
    apOut(['ok' => true, 'text' => $text]);
}

// ── Генерация картинки (YandexART) ───────────────────────────────────────────
if ($action === 'image' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $prompt = trim((string)($body['prompt'] ?? ''));
    if ($prompt === '') apOut(['error' => 'Опишите картинку'], 400);
    if ($provider !== 'yandex') {
        apOut(['error' => 'Генерация картинок сейчас доступна на провайдере Yandex (YandexART). '
                        . 'Переключите провайдера в админке или добавьте картинку вручную кнопкой 🖼️.'], 503);
    }
    // Мягко направляем модель: не текст на картинке, спокойная иллюстрация к посту психолога.
    $full = $prompt . '. Спокойная, тёплая иллюстрация для поста психолога, без текста и букв, мягкие тона';
    $start = json_encode([
        'modelUri' => 'art://' . $folderId . '/yandex-art/latest',
        'generationOptions' => ['aspectRatio' => ['widthRatio' => '3', 'heightRatio' => '2']],
        'messages' => [['weight' => '1', 'text' => mb_substr($full, 0, 500)]],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://llm.api.cloud.yandex.net/foundationModels/v1/imageGenerationAsync');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $start,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Api-Key ' . $apiKey],
        CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $raw = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if ($raw === false) apOut(['error' => 'YandexART недоступен: ' . $err], 502);
    $d = json_decode($raw, true);
    $opId = is_array($d) ? ($d['id'] ?? '') : '';
    if ($opId === '') {
        $msg = is_array($d) ? ($d['error']['message'] ?? $d['message'] ?? '') : '';
        apOut(['error' => 'Не удалось запустить генерацию' . ($msg ? ': ' . $msg : '') . '. Проверьте, что в облаке включён YandexART.'], 502);
    }

    // Ждём результат: операция асинхронная. Опрашиваем до ~50 секунд.
    $b64 = '';
    for ($i = 0; $i < 25; $i++) {
        usleep(2000000);
        $c2 = curl_init('https://llm.api.cloud.yandex.net/operations/' . rawurlencode($opId));
        curl_setopt_array($c2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Api-Key ' . $apiKey],
            CURLOPT_TIMEOUT => 20,
        ]);
        $r2 = curl_exec($c2); curl_close($c2);
        $o = json_decode((string)$r2, true);
        if (!is_array($o)) continue;
        if (!empty($o['error'])) apOut(['error' => 'Генерация не удалась: ' . ($o['error']['message'] ?? 'ошибка YandexART')], 502);
        if (!empty($o['done'])) { $b64 = $o['response']['image'] ?? ''; break; }
    }
    if ($b64 === '') apOut(['error' => 'Картинка не готова — YandexART долго отвечает. Попробуйте ещё раз.'], 504);

    $bin = base64_decode($b64, true);
    if ($bin === false || strlen($bin) < 100) apOut(['error' => 'Пустой ответ генерации'], 502);
    $dir = __DIR__ . '/../uploads/ai_posts';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(16)) . '.jpg';
    if (@file_put_contents($dir . '/' . $name, $bin) === false) apOut(['error' => 'Не удалось сохранить картинку'], 500);
    @chmod($dir . '/' . $name, 0644);
    apOut(['ok' => true, 'url' => '/uploads/ai_posts/' . $name]);
}

apOut(['error' => 'Неизвестное действие'], 400);
