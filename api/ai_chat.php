<?php
/**
 * ai_chat.php — ИИ-чат (прокси к Yandex Foundation Models или OpenRouter).
 *
 * POST ?action=chat        {messages:[{role,content}], model?}  → SSE-поток
 * GET  ?action=models                                           → список моделей
 * GET  ?action=config      (админ)                              → текущая настройка, ключ скрыт
 * POST ?action=save-config (админ) {provider, api_key, folder_id, models}
 * POST ?action=test        (админ) {model?}                     → живая проверка ключа
 *
 * Ключи в репозитории не хранятся: админка записывает их в ai_chat_config.php,
 * который лежит вне git (см. .gitignore).
 *
 * Доступ к чату: все авторизованные — у каждого своя переписка с ассистентом.
 */

header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';
if (!function_exists('getDB') && !function_exists('getDbConnection') && !function_exists('getPDO')) {
    require_once __DIR__ . '/db.php';
}
$pdo = function_exists('getDB') ? getDB()
     : (function_exists('getDbConnection') ? getDbConnection()
     : (function_exists('getPDO') ? getPDO() : null));
if (!$pdo) { header('Content-Type: application/json'); http_response_code(500); echo json_encode(['error'=>'DB']); exit; }

if (session_status() === PHP_SESSION_NONE) session_start();
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) { header('Content-Type: application/json'); http_response_code(401); echo json_encode(['error'=>'Требуется авторизация']); exit; }

$me = null;
try { $st = $pdo->prepare("SELECT id, role FROM users WHERE id = ? LIMIT 1"); $st->execute([$userId]); $me = $st->fetch(PDO::FETCH_ASSOC); } catch (Exception $e) {}
$role = $me['role'] ?? '';
$isAdmin = ($role === 'admin');

$cfgFile = __DIR__ . '/ai_chat_config.php';
$cfg = file_exists($cfgFile) ? (require $cfgFile) : [];
if (!is_array($cfg)) $cfg = [];
$provider = ($cfg['provider'] ?? 'yandex') === 'openrouter' ? 'openrouter' : 'yandex';
$apiKey   = trim((string)($cfg['api_key'] ?? ''));
$folderId = trim((string)($cfg['folder_id'] ?? ''));

/**
 * Модели Яндекса по умолчанию. Каталог у Яндекса пополняется, поэтому список можно
 * дополнить своими идентификаторами прямо из админки — здесь только то, что заведомо есть.
 */
const YANDEX_DEFAULT_MODELS = [
    'yandexgpt/latest'      => 'YandexGPT Pro — основная',
    'yandexgpt/rc'          => 'YandexGPT Pro (release candidate)',
    'yandexgpt-32k/latest'  => 'YandexGPT Pro 32k — длинный контекст',
    'yandexgpt-lite/latest' => 'YandexGPT Lite — быстрая и дешёвая',
    'yandexgpt-lite/rc'     => 'YandexGPT Lite (release candidate)',
    'llama/latest'          => 'Llama 70B',
    'llama-lite/latest'     => 'Llama 8B',
];
const OPENROUTER_DEFAULT_MODELS = [
    'deepseek/deepseek-chat-v3-0324:free' => 'DeepSeek V3',
    'qwen/qwen3-235b-a22b:free'           => 'Qwen 3 235B',
    'deepseek/deepseek-r1:free'           => 'DeepSeek R1 (думающий)',
];

$models = $cfg['models'] ?? [];
if (!is_array($models) || !$models) {
    $models = $provider === 'openrouter' ? OPENROUTER_DEFAULT_MODELS : YANDEX_DEFAULT_MODELS;
}

/** Чего не хватает, чтобы ассистент заработал. Пустая строка — всё на месте. */
function aiMissing($provider, $apiKey, $folderId) {
    if ($apiKey === '') return 'Не задан ключ API. Админка → «ИИ-ассистент».';
    if ($provider === 'yandex' && $folderId === '')
        return 'Не задан идентификатор каталога Yandex Cloud (folder_id, вида b1g...). Одного ключа мало: имя модели передаётся как gpt://<каталог>/<модель>.';
    return '';
}

/** Куда и с какой авторизацией идти. У Яндекса есть эндпоинт, совместимый с OpenAI. */
function aiEndpoint($provider) {
    return $provider === 'openrouter'
        ? 'https://openrouter.ai/api/v1/chat/completions'
        : 'https://llm.api.cloud.yandex.net/v1/chat/completions';
}
function aiHeaders($provider, $apiKey) {
    $h = ['Content-Type: application/json'];
    if ($provider === 'openrouter') {
        $h[] = 'Authorization: Bearer ' . $apiKey;
        $h[] = 'HTTP-Referer: https://psytalk.pro';
        $h[] = 'X-Title: PsyTalk AI Chat';
    } else {
        $h[] = 'Authorization: Api-Key ' . $apiKey;   // ключ сервисного аккаунта Yandex Cloud
    }
    return $h;
}
/** Яндекс ждёт полное имя модели: gpt://<каталог>/<модель>. */
function aiModelName($provider, $model, $folderId) {
    if ($provider !== 'yandex') return $model;
    if (strpos($model, 'gpt://') === 0 || strpos($model, 'ds://') === 0) return $model;
    return 'gpt://' . $folderId . '/' . $model;
}

$action = $_GET['action'] ?? '';

// ── Настройка из админки: ключи в репозиторий не попадают ──────────────────────
if ($action === 'config' || $action === 'save-config' || $action === 'test') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$isAdmin) { http_response_code(403); echo json_encode(['error'=>'Только для администратора']); exit; }

    if ($action === 'config') {
        echo json_encode([
            'ok' => true,
            'provider' => $provider,
            'folder_id' => $folderId,
            'key_set' => $apiKey !== '',
            'key_hint' => $apiKey === '' ? '' : (substr($apiKey, 0, 4) . '…' . substr($apiKey, -4)),
            'models' => $models,
            'defaults' => ['yandex' => YANDEX_DEFAULT_MODELS, 'openrouter' => OPENROUTER_DEFAULT_MODELS],
            'missing' => aiMissing($provider, $apiKey, $folderId),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true) ?: [];

    if ($action === 'save-config') {
        $np = ($body['provider'] ?? 'yandex') === 'openrouter' ? 'openrouter' : 'yandex';
        // Пустой ключ означает «оставить прежний» — чтобы не стирать его случайным сохранением
        $nk = trim((string)($body['api_key'] ?? ''));
        if ($nk === '') $nk = $apiKey;
        $nf = trim((string)($body['folder_id'] ?? ''));
        $nm = [];
        foreach ((array)($body['models'] ?? []) as $id => $name) {
            $id = trim((string)$id); $name = trim((string)$name);
            if ($id !== '') $nm[$id] = ($name !== '' ? $name : $id);
        }
        $out = "<?php\n// Создан админкой psytalk.pro. НЕ коммитить: файл в .gitignore.\nreturn "
             . var_export(['provider'=>$np, 'api_key'=>$nk, 'folder_id'=>$nf, 'models'=>$nm], true) . ";\n";
        if (@file_put_contents($cfgFile, $out) === false) {
            http_response_code(500);
            echo json_encode(['error'=>'Не удалось записать api/ai_chat_config.php — проверьте права на папку api/']);
            exit;
        }
        @chmod($cfgFile, 0640);
        echo json_encode(['ok'=>true, 'missing'=>aiMissing($np, $nk, $nf)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'test') {
        $miss = aiMissing($provider, $apiKey, $folderId);
        if ($miss !== '') { echo json_encode(['ok'=>false, 'error'=>$miss], JSON_UNESCAPED_UNICODE); exit; }
        $model = trim((string)($body['model'] ?? '')) ?: array_key_first($models);
        $ch = curl_init(aiEndpoint($provider));
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => aiHeaders($provider, $apiKey),
            CURLOPT_POSTFIELDS => json_encode([
                'model' => aiModelName($provider, $model, $folderId),
                'messages' => [['role'=>'user','content'=>'Ответь одним словом: работает?']],
                'max_tokens' => 32,
            ], JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($err) { echo json_encode(['ok'=>false, 'error'=>'Не достучались до сервиса: ' . $err], JSON_UNESCAPED_UNICODE); exit; }
        $d = json_decode((string)$resp, true);
        if ($code < 200 || $code >= 300) {
            $msg = $d['error']['message'] ?? $d['message'] ?? substr((string)$resp, 0, 300);
            // Яндекс в отказе сам называет каталог, которому принадлежит ключ. Незачем
            // заставлять человека искать folder_id в консоли — подставим найденный.
            $suggest = '';
            if (preg_match("/service account folder ID '([a-z0-9]+)'/i", (string)$msg, $m2)) $suggest = $m2[1];
            echo json_encode([
                'ok' => false,
                'error' => 'Сервис отказал (' . $code . '): ' . $msg,
                'model' => $model,
                'suggest_folder' => $suggest,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $text = $d['choices'][0]['message']['content'] ?? '';
        echo json_encode(['ok'=>true, 'model'=>$model, 'answer'=>mb_substr((string)$text, 0, 200)], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if ($action === 'models') {
    header('Content-Type: application/json; charset=utf-8');
    $list = [];
    foreach ($models as $id => $name) {
        $list[] = ['id' => $id, 'name' => $name];
    }
    // Список отдаём всегда: пусть в чате видно, что моделей много, даже если ключ
    // ещё не вписан — тогда рядом придёт понятная причина, почему не отвечает.
    echo json_encode(['ok' => true, 'models' => $list, 'provider' => $provider,
                      'missing' => aiMissing($provider, $apiKey, $folderId)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'chat') {
    $input = json_decode(file_get_contents('php://input'), true);
    $messages = $input['messages'] ?? [];
    $model = $input['model'] ?? array_key_first($models);

    if (!$messages || !is_array($messages)) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Нет сообщений']);
        exit;
    }

    if (!array_key_exists($model, $models)) {
        $model = array_key_first($models);
    }

    // Не настроено — говорим об этом человеческим языком в самом потоке,
    // иначе бот просто молчал и было непонятно, сломался он или думает.
    $miss = aiMissing($provider, $apiKey, $folderId);
    if ($miss !== '') {
        header('Content-Type: text/event-stream; charset=utf-8');
        echo "data: " . json_encode(['error' => $miss], JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
        exit;
    }

    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    $payload = json_encode([
        'model'    => aiModelName($provider, $model, $folderId),
        'messages' => $messages,
        'stream'   => true,
        'max_tokens' => 4096,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init(aiEndpoint($provider));
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => aiHeaders($provider, $apiKey),
        CURLOPT_WRITEFUNCTION  => function($ch, $data) {
            echo $data;
            if (ob_get_level()) ob_flush();
            flush();
            return strlen($data);
        },
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        echo "data: " . json_encode(['error' => $err]) . "\n\n";
        flush();
    }
    exit;
}

header('Content-Type: application/json');
echo json_encode(['error' => 'Неизвестное действие']);
