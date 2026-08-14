<?php
/**
 * ai_chat.php — ИИ-чат (прокси к Yandex Foundation Models или OpenRouter).
 *
 * POST ?action=chat        {messages:[{role,content}], model?, search?}  → SSE-поток
 * GET  ?action=models                                           → список моделей
 * GET  ?action=config      (админ)                              → текущая настройка, ключ скрыт
 * POST ?action=save-config (админ) {provider, api_key, folder_id, models, search_enabled}
 * GET  ?action=search-status                                   → доступен ли поиск в интернете
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
// Поиск в интернете — отдельная услуга Yandex Search API, её включают в облаке
// и оплачивают отдельно от моделей. Пока админ её не разрешил, переключателя у людей нет.
$searchEnabled = !empty($cfg['search_enabled']);

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

/**
 * Поискать в интернете через Yandex Search API (генеративный ответ со ссылками).
 * Возвращает ['text' => краткая выжимка, 'sources' => [['title','url'], ...]]
 * либо ['error' => 'человеческая причина'].
 *
 * Ключ и каталог те же, что у моделей, но услугу нужно отдельно включить в облаке:
 * без этого сервис отвечает 403, и мы честно говорим об этом, а не молчим.
 */
function aiWebSearch($query, $apiKey, $folderId) {
    $query = trim((string)$query);
    if ($query === '') return ['error' => 'Пустой запрос'];
    if ($apiKey === '' || $folderId === '') return ['error' => 'Не настроены ключ или каталог Yandex Cloud'];

    $body = json_encode([
        'messages' => [['content' => mb_substr($query, 0, 1000), 'role' => 'ROLE_USER']],
        'folderId' => $folderId,
        'site' => new stdClass(),
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://searchapi.api.cloud.yandex.net/v2/gen/search');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Api-Key ' . $apiKey],
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return ['error' => 'не достучались до поиска: ' . $err];
    $d = json_decode((string)$resp, true);
    if ($code < 200 || $code >= 300) {
        $msg = $d['message'] ?? ($d['error_message'] ?? substr((string)$resp, 0, 200));
        if ($code === 403) $msg = 'нет доступа к Search API — его нужно включить в Yandex Cloud (' . $msg . ')';
        return ['error' => 'сервис поиска отказал (' . $code . '): ' . $msg];
    }

    return aiParseSearchResponse($resp);
}

/**
 * Разобрать ответ Search API. Вынесено отдельно, потому что сервис отдаёт результат
 * то одним объектом, то потоком построчных JSON-кусков, и склейку нужно проверять
 * без обращения к платной услуге.
 */
function aiParseSearchResponse($resp) {
    $text = ''; $sources = [];
    $chunks = [];
    foreach (preg_split('/\r?\n/', (string)$resp) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $j = json_decode($line, true);
        if (is_array($j)) $chunks[] = $j;
    }
    if (!$chunks) {
        $whole = json_decode((string)$resp, true);
        if (is_array($whole)) $chunks[] = $whole;
    }
    foreach ($chunks as $j) {
        $node = $j['result'] ?? $j;
        if (isset($node['message']['content'])) $text .= (string)$node['message']['content'];
        foreach ((array)($node['sources'] ?? []) as $src) {
            $u = (string)($src['url'] ?? '');
            if ($u === '') continue;
            $sources[$u] = ['title' => (string)($src['title'] ?? $u), 'url' => $u];
        }
    }
    $text = trim($text);
    if ($text === '' && !$sources) return ['error' => 'поиск ничего не вернул'];
    return ['text' => $text, 'sources' => array_values($sources)];
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
            'search_enabled' => $searchEnabled,
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
        $ns = !empty($body['search_enabled']);
        $out = "<?php\n// Создан админкой psytalk.pro. НЕ коммитить: файл в .gitignore.\nreturn "
             . var_export(['provider'=>$np, 'api_key'=>$nk, 'folder_id'=>$nf, 'models'=>$nm,
                           'search_enabled'=>$ns], true) . ";\n";
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

if ($action === 'search-status') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'available' => $searchEnabled && $provider === 'yandex'
                                                   && $apiKey !== '' && $folderId !== '']);
    exit;
}

if ($action === 'chat') {
    $input = json_decode(file_get_contents('php://input'), true);
    $messages = $input['messages'] ?? [];
    $model = $input['model'] ?? array_key_first($models);
    $wantSearch = !empty($input['search']);

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

    /** Отправить строку в поток как обычный кусочек ответа модели. */
    $emit = function ($text) {
        echo "data: " . json_encode(['choices' => [['delta' => ['content' => $text]]]], JSON_UNESCAPED_UNICODE) . "\n\n";
        if (ob_get_level()) ob_flush();
        flush();
    };

    // Поиск в интернете: сначала ищем, потом отдаём находки модели как справку.
    // Модель сама по себе знает мир только до своей даты обучения, а спрашивают
    // у неё и про сегодняшнее. Если поиск не сработал — говорим об этом прямо
    // и всё равно отвечаем, а не оставляем человека без ответа.
    $sourcesTail = '';
    if ($wantSearch && $searchEnabled && $provider === 'yandex') {
        $lastUser = '';
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') { $lastUser = (string)($messages[$i]['content'] ?? ''); break; }
        }
        $found = aiWebSearch($lastUser, $apiKey, $folderId);
        if (isset($found['error'])) {
            $emit("_Поиск в интернете не сработал: " . $found['error'] . ". Отвечаю без него._\n\n");
        } else {
            $ref = "Ниже — свежие сведения из интернета по запросу человека. Опирайся на них, "
                 . "если они относятся к делу, и не выдумывай того, чего в них нет.\n\n" . $found['text'];
            if ($found['sources']) {
                $ref .= "\n\nИсточники:\n";
                foreach (array_slice($found['sources'], 0, 6) as $n => $src) {
                    $ref .= ($n + 1) . '. ' . $src['title'] . ' — ' . $src['url'] . "\n";
                }
                $sourcesTail = "\n\n**Источники**\n";
                foreach (array_slice($found['sources'], 0, 6) as $n => $src) {
                    $sourcesTail .= ($n + 1) . '. ' . $src['title'] . ' — ' . $src['url'] . "\n";
                }
            }
            array_unshift($messages, ['role' => 'system', 'content' => $ref]);
        }
    } elseif ($wantSearch && !$searchEnabled) {
        $emit("_Поиск в интернете выключен в настройках платформы. Отвечаю по своим знаниям._\n\n");
    }

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
    } elseif ($sourcesTail !== '') {
        $emit($sourcesTail);      // куда смотреть, если ответ важно перепроверить
    }
    exit;
}

header('Content-Type: application/json');
echo json_encode(['error' => 'Неизвестное действие']);
