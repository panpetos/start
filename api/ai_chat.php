<?php
/**
 * ai_chat.php — ИИ-чат (прокси к Yandex Foundation Models или OpenRouter).
 *
 * POST ?action=chat        {messages:[{role,content}], model?, search?}  → SSE-поток
 * GET  ?action=models                                           → список моделей
 * GET  ?action=config      (админ)                              → текущая настройка, ключ скрыт
 * POST ?action=save-config (админ) {provider, api_key, folder_id, models, search_enabled}
 * GET  ?action=search-status                                   → доступен ли поиск и почему нет
 * POST ?action=test        (админ) {model?}                     → живая проверка ключа
 * POST ?action=search-test (админ) {query?}                     → живая проверка поиска в интернете
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
require_once __DIR__ . '/ai_text.php';   // разбор страниц и приложенных файлов — общий с ai_file.php
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

    // Сначала генеративный поиск: он сам делает выжимку и отдаёт источники.
    $gen = aiGenSearch($query, $apiKey, $folderId);
    if (!isset($gen['error'])) { $gen['query'] = $query; $gen['via'] = 'gen'; return $gen; }

    // Не вышло — обычный поиск по вебу. Он доступен всем, у кого подключён Search API,
    // а генеративный местами открывают отдельно: без запасного пути ассистент оставался
    // без интернета там, где поиск на самом деле есть.
    $web = aiPlainSearch($query, $apiKey, $folderId);
    if (!isset($web['error'])) { $web['query'] = $query; $web['via'] = 'web'; return $web; }

    // Обе не сработали — показываем ту причину, что понятнее, и не прячем вторую.
    $web['gen_error'] = $gen['error'];
    if (empty($web['hint']) && !empty($gen['hint'])) $web['hint'] = $gen['hint'];
    return $web;
}

/** Один POST в Search API. Возвращает ['body'=>…] либо ['error'=>…, 'code'=>…, 'hint'=>…]. */
function aiSearchPost($url, $body, $apiKey, $timeout = 20) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Api-Key ' . $apiKey],
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return ['error' => 'не достучались до поиска: ' . $err, 'code' => 0];
    if ($code < 200 || $code >= 300) {
        $d = json_decode((string)$resp, true);
        $msg = $d['message'] ?? ($d['error_message'] ?? substr((string)$resp, 0, 200));
        return ['error' => 'сервис поиска отказал (' . $code . '): ' . $msg,
                'code' => $code, 'hint' => aiSearchHint($code, (string)$msg)];
    }
    return ['body' => (string)$resp];
}

/** Генеративный поиск: готовая выжимка со ссылками. */
function aiGenSearch($query, $apiKey, $folderId) {
    $body = json_encode([
        'messages' => [['content' => mb_substr($query, 0, 1000), 'role' => 'ROLE_USER']],
        'folderId' => $folderId,
        'site' => new stdClass(),
    ], JSON_UNESCAPED_UNICODE);
    $r = aiSearchPost('https://searchapi.api.cloud.yandex.net/v2/gen/search', $body, $apiKey, 20);
    if (isset($r['error'])) return $r;
    return aiParseSearchResponse($r['body']);
}

/**
 * Обычный поиск по вебу: тот самый запрос, что показывает Playground в AI Studio.
 * Ответ приходит как XML, упакованный в base64 внутри поля rawData.
 */
function aiPlainSearch($query, $apiKey, $folderId) {
    $body = json_encode([
        'query' => [
            'searchType'  => 'SEARCH_TYPE_RU',
            'queryText'   => mb_substr($query, 0, 400),
            'familyMode'  => 'FAMILY_MODE_MODERATE',
            'page'        => '0',
            'fixTypoMode' => 'FIX_TYPO_MODE_ON',
        ],
        'sortSpec'  => ['sortMode' => 'SORT_MODE_BY_RELEVANCE', 'sortOrder' => 'SORT_ORDER_DESC'],
        'groupSpec' => ['groupsOnPage' => '8', 'groupMode' => 'GROUP_MODE_DEEP', 'docsInGroup' => '1'],
        'maxPassages'    => '5',
        'l10n'           => 'LOCALIZATION_RU',
        'folderId'       => $folderId,
        'responseFormat' => 'FORMAT_XML',
    ], JSON_UNESCAPED_UNICODE);
    $r = aiSearchPost('https://searchapi.api.cloud.yandex.net/v2/web/search', $body, $apiKey, 20);
    if (isset($r['error'])) return $r;

    $d = json_decode($r['body'], true);
    $raw = $d['rawData'] ?? '';
    if ($raw === '') return ['error' => 'поиск вернул пустой ответ'];
    $xml = base64_decode($raw, true);
    if ($xml === false) return ['error' => 'не удалось разобрать ответ поиска'];
    return aiParseWebSearchXml($xml);
}

/**
 * Стоит ли вообще показывать эту ссылку. Выдача поисковиков и «картинки по запросу»
 * пользы не несут: человек и так пришёл из-за того, что не хочет листать поиск.
 */
function aiUsefulSource($url) {
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    $path = (string)parse_url($url, PHP_URL_PATH);
    if ($host === '') return false;
    if (preg_match('#^(www\.)?(yandex|google)\.[a-z.]+$#', $host) && preg_match('#^/(images/|video/)?search#', $path)) return false;
    if (strpos($host, 'webcache.') === 0) return false;
    return true;
}


/**
 * Скачать несколько найденных страниц разом и вернуть [url => текст].
 * Качаем параллельно и с короткими сроками: ждать ответа дольше нескольких секунд
 * человеку в чате нельзя, лучше ответить по тому, что успели прочитать.
 */
function aiFetchPages($urls, $maxChars = 2000, $timeout = 6) {
    $urls = array_values(array_unique(array_filter((array)$urls, function ($u) {
        return preg_match('#^https?://#i', (string)$u) && aiUsefulSource($u);
    })));
    if (!$urls) return [];

    $mh = curl_multi_init();
    $handles = [];
    foreach ($urls as $u) {
        $ch = curl_init($u);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; PsyTalkBot/1.0; +https://psytalk.pro)',
            CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: ru,en;q=0.8'],
            // Тяжёлую страницу обрываем: нам нужен текст сверху, а не весь файл
            CURLOPT_NOPROGRESS     => false,
            CURLOPT_PROGRESSFUNCTION => function ($c, $dt, $dn, $ut, $un) { return $dn > 1500000 ? 1 : 0; },
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$u] = $ch;
    }
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        if ($running > 0) curl_multi_select($mh, 0.2);
    } while ($running > 0);

    $out = [];
    foreach ($handles as $u => $ch) {
        $body = curl_multi_getcontent($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        if ($code < 200 || $code >= 300 || !is_string($body) || $body === '') continue;
        if ($type !== '' && stripos($type, 'html') === false && stripos($type, 'text') === false) continue;
        $txt = aiHtmlToText($body, $maxChars);
        if ($txt !== '') $out[$u] = $txt;
    }
    curl_multi_close($mh);
    return $out;
}

/**
 * Разобрать выдачу обычного поиска: заголовок, адрес и найденные отрывки по каждой
 * странице. Вынесено отдельно — разбор XML проверяем без обращения к платной услуге.
 */
function aiParseWebSearchXml($xml) {
    $prev = libxml_use_internal_errors(true);
    $doc = simplexml_load_string((string)$xml);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$doc) return ['error' => 'поиск ответил в непонятном виде'];

    // Яндекс подсвечивает совпадения тегами <hlword>, в тексте они не нужны
    $plain = function ($node) {
        // Узла может не быть вовсе (страница без заголовка) — тогда просто пусто
        if (!$node instanceof SimpleXMLElement) return '';
        return trim(preg_replace('/\s+/u', ' ', strip_tags((string)$node->asXML())));
    };

    $sources = []; $parts = [];
    foreach ($doc->xpath('//doc') ?: [] as $n => $doc1) {
        if ($n >= 8) break;
        $url = trim((string)$doc1->url);
        if ($url === '') continue;
        $title = $plain($doc1->title);
        $text = '';
        if (isset($doc1->passages->passage)) {
            foreach ($doc1->passages->passage as $ps) $text .= ' ' . $plain($ps);
        }
        if ($text === '' && isset($doc1->headline)) $text = $plain($doc1->headline);
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if (!aiUsefulSource($url)) continue;      // выдача поисковика вместо ответа не нужна
        $sources[$url] = ['title' => $title !== '' ? $title : $url, 'url' => $url];
        $parts[] = (count($parts) + 1) . '. ' . ($title !== '' ? $title : $url)
                 . ($text !== '' ? "\n" . mb_substr($text, 0, 600) : '');
    }
    if (!$parts) return ['error' => 'поиск ничего не нашёл'];
    return ['text' => implode("\n\n", $parts), 'sources' => array_values($sources)];
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
            if ($u === '' || !aiUsefulSource($u)) continue;
            $sources[$u] = ['title' => (string)($src['title'] ?? $u), 'url' => $u];
        }
    }
    $text = trim($text);
    if ($text === '' && !$sources) return ['error' => 'поиск ничего не вернул'];
    return ['text' => $text, 'sources' => array_values($sources)];
}

/**
 * Что именно делать, если поиск отказал. Коды у Search API говорящие, но человеку
 * от «403 permission denied» толку нет — переводим в шаги, которые он выполнит в консоли.
 */
function aiSearchHint($code, $msg = '') {
    if ($code === 403 || $code === 401) {
        return 'Похоже, услуга «Поиск в интернете» ещё не включена или у ключа нет прав. '
             . 'В консоли Yandex Cloud: 1) откройте каталог, «Search API» → «Подключить»; '
             . '2) сервисному аккаунту, чьим ключом мы ходим, выдайте роль search-api.webSearch.user '
             . '(и search-api.executor); 3) убедитесь, что в биллинге привязан платёжный аккаунт — '
             . 'услуга платная и без него сервис отвечает отказом.';
    }
    if ($code === 404) return 'Сервис не нашёл каталог. Проверьте folder_id — он должен быть тем же, где включён Search API.';
    if ($code === 429) return 'Слишком много запросов подряд — сервис попросил подождать. Попробуйте через минуту.';
    if ($code >= 500) return 'Это сбой на стороне Яндекса, не у нас. Стоит повторить позже.';
    return '';
}

/**
 * Похоже ли, что вопрос про «сейчас», а не про вечное. Модель знает мир только до
 * своей даты обучения, и в режиме «Авто» мы идём в интернет именно по таким вопросам,
 * а не по каждому — поиск платный и на «объясни, что такое тревога» он лишний.
 */
function aiNeedsFresh($text) {
    $t = mb_strtolower(trim((string)$text));
    if ($t === '') return false;
    if (preg_match('~https?://|\bwww\.~u', $t)) return true;
    if (preg_match('/\b20(2[4-9]|[3-9][0-9])\b/u', $t)) return true;   // год после обучения модели
    $marks = ['сегодня', 'сейчас', 'вчера', 'на этой неделе', 'в этом году', 'последн', 'свеж',
              'актуальн', 'новост', 'что нового', 'курс ', 'погод', 'сколько стоит', 'цена', 'цены',
              'стоимость', 'тариф', 'расписание', 'афиш', 'кто победил', 'когда выйдет', 'вышел ли',
              'обновлени', 'закон ', 'поправк', 'статистик', 'рейтинг', 'отзыв', 'кто такой', 'кто такая'];
    foreach ($marks as $m) if (mb_strpos($t, $m) !== false) return true;
    return false;
}

/**
 * Из чего складывать поисковый запрос. Брать буквально последнюю реплику мало:
 * «а в рублях?» сам по себе не значит ничего, и поиск вернул бы мусор. Поэтому
 * короткое уточнение склеиваем с предыдущим вопросом.
 */
function aiSearchQuery($messages) {
    $users = [];
    for ($i = count($messages) - 1; $i >= 0 && count($users) < 2; $i--) {
        if (($messages[$i]['role'] ?? '') === 'user') {
            $c = trim((string)($messages[$i]['content'] ?? ''));
            if ($c !== '') $users[] = $c;
        }
    }
    if (!$users) return '';
    $q = $users[0];
    if (isset($users[1]) && mb_strlen($q) < 40) $q = $users[1] . ' — ' . $q;
    return mb_substr($q, 0, 400);
}

/**
 * Кто такой ассистент. Без этого модель отвечала «в вакууме»: не знала ни сегодняшнего
 * числа, ни того, что она на платформе психологической помощи, и легко выдумывала.
 */
function aiSystemPrompt($searchOn) {
    $tz = @date_default_timezone_get();
    @date_default_timezone_set('Europe/Moscow');
    $today = date('d.m.Y, H:i') . ' по Москве';
    @date_default_timezone_set($tz ?: 'UTC');
    $p = "Ты — ассистент платформы психологической помощи psytalk.pro. Сейчас {$today}.\n"
       . "Отвечай по-русски, по делу и без воды. Если просят текст (письмо клиенту, пост, "
       . "упражнение, план сессии) — сразу давай готовый текст, а не рассуждения о нём.\n"
       . "Ты не ставишь диагнозов и не заменяешь супервизию: в сложных случаях предлагай "
       . "обратиться к живому специалисту, а при признаках угрозы жизни — к экстренной помощи.\n"
       . "Не выдумывай фактов, ссылок и цитат. Если чего-то не знаешь — так и скажи.\n"
       . "Никогда не отвечай «посмотрите на сайте» и не отсылай к поисковикам и погодным "
       . "сервисам: человек пришёл за ответом, а не за списком ссылок.";
    $p .= $searchOn
        ? "\nК этому ответу приложены свежие сведения из интернета — отвечай по ним. "
        . "Называй конкретику: числа, даты, суммы, градусы — прямо в первой фразе. "
        . "Если в материалах нужного нет, честно скажи одной фразой, чего именно не нашлось, "
        . "и добавь то, что всё же известно. Спорные и важные числа подкрепляй номером источника.\n"
        . "Числа бери из одного источника, не смешивая страницы: на странице рядом лежат и "
        . "«сейчас», и прогноз на дни — если непонятно, к какому времени относится значение, "
        . "так и скажи и назови, что это прогноз, а не текущее наблюдение."
        : "\nСейчас у тебя нет доступа к интернету: твои знания ограничены датой обучения. "
        . "Если вопрос про свежее (новости, цены, законы, расписания), честно предупреди об этом "
        . "и посоветуй включить «Интернет» переключателем над полем ввода.";
    return $p;
}

/**
 * Что из переписки отдаём модели. Целиком не нужно: длинный диалог упирается в лимит
 * контекста, и вместо ответа приходит отказ или невпопад. Свои системные сообщения
 * выбрасываем — на запрос ставится ровно одно, собранное здесь.
 */
function aiTrimHistory($messages, $keep = 24) {
    $out = [];
    foreach ((array)$messages as $m) {
        $role = $m['role'] ?? '';
        if ($role !== 'user' && $role !== 'assistant') continue;
        $c = (string)($m['content'] ?? '');
        if (trim($c) === '') continue;
        $out[] = ['role' => $role, 'content' => mb_substr($c, 0, 8000)];
    }
    return array_slice($out, -$keep);
}

/** Почему поиск недоступен — одной фразой для того, кто это читает в чате. */
function aiSearchReason($searchEnabled, $provider, $apiKey, $folderId) {
    if ($provider !== 'yandex') return 'Поиск в интернете есть только у Yandex Cloud — сейчас выбран другой сервис.';
    if ($apiKey === '' || $folderId === '') return 'Ассистент ещё не настроен: нет ключа или каталога Yandex Cloud.';
    if (!$searchEnabled) return 'Поиск в интернете выключен в настройках платформы (админка → «ИИ-ассистент»).';
    return '';
}

$action = $_GET['action'] ?? '';

// ── Настройка из админки: ключи в репозиторий не попадают ──────────────────────
if ($action === 'config' || $action === 'save-config' || $action === 'test' || $action === 'search-test') {
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

    if ($action === 'search-test') {
        // Отдельная кнопка: связь с моделью может работать, а Search API — нет,
        // это разные услуги. Раньше это выяснялось только в живом чате.
        if ($provider !== 'yandex') { echo json_encode(['ok'=>false, 'error'=>'Поиск в интернете есть только у Yandex Cloud.'], JSON_UNESCAPED_UNICODE); exit; }
        $miss = aiMissing($provider, $apiKey, $folderId);
        if ($miss !== '') { echo json_encode(['ok'=>false, 'error'=>$miss], JSON_UNESCAPED_UNICODE); exit; }
        $q = trim((string)($body['query'] ?? '')) ?: 'какие сегодня новости в России';
        $found = aiWebSearch($q, $apiKey, $folderId);
        if (isset($found['error'])) {
            // Показываем обе причины: генеративный поиск и обычный включаются по-разному,
            // и по одной строке не понять, что именно не дали.
            echo json_encode(['ok'=>false, 'query'=>$q, 'error'=>$found['error'],
                              'gen_error'=>$found['gen_error'] ?? '',
                              'hint'=>$found['hint'] ?? aiSearchHint((int)($found['code'] ?? 0))], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode([
            'ok'      => true,
            'query'   => $q,
            'via'     => $found['via'] ?? '',
            'answer'  => mb_substr((string)($found['text'] ?? ''), 0, 400),
            'sources' => array_slice($found['sources'] ?? [], 0, 5),
            'note'    => $searchEnabled ? '' : 'Поиск работает, но галочка «Разрешить поиск в интернете» пока снята — в чате его не будет.',
        ], JSON_UNESCAPED_UNICODE);
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
    $reason = aiSearchReason($searchEnabled, $provider, $apiKey, $folderId);
    // Раньше отдавали одно «доступно/нет», и человек не понимал, почему переключателя
    // нет. Теперь причина приходит вместе с ответом — её и показываем в чате.
    echo json_encode([
        'ok'        => true,
        'available' => $reason === '',
        'reason'    => $reason,
        'enabled'   => $searchEnabled,
        'provider'  => $provider,
        'is_admin'  => $isAdmin,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'chat') {
    $input = json_decode(file_get_contents('php://input'), true);
    $messages = $input['messages'] ?? [];
    $model = $input['model'] ?? array_key_first($models);
    // Режим поиска: 'off' | 'auto' | 'on'. Старые страницы шлют true/false — понимаем и их.
    $rawSearch = $input['search'] ?? 'off';
    if ($rawSearch === true) $rawSearch = 'on';
    if ($rawSearch === false || $rawSearch === null) $rawSearch = 'off';
    $searchMode = in_array($rawSearch, ['on', 'auto', 'off'], true) ? $rawSearch : 'off';
    // Страница сообщает, что умеет показывать ход поиска и источники отдельным блоком.
    // Без этого признака шлём то же самое обычным текстом, чтобы ничего не потерялось.
    $richUi = !empty($input['ui']);

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

    /** Служебное событие для страницы: ход поиска, источники. Старая страница их не увидит. */
    $note = function ($data) use ($richUi) {
        if (!$richUi) return;
        echo "data: " . json_encode(['psy' => $data], JSON_UNESCAPED_UNICODE) . "\n\n";
        if (ob_get_level()) ob_flush();
        flush();
    };

    // Поиск в интернете: сначала ищем, потом отдаём находки модели как справку.
    // Модель сама по себе знает мир только до своей даты обучения, а спрашивают
    // у неё и про сегодняшнее. Если поиск не сработал — говорим об этом прямо
    // и всё равно отвечаем, а не оставляем человека без ответа.
    $sourcesTail = '';
    $searchRef = '';          // справка из интернета, если поиск сработал
    $searchReason = aiSearchReason($searchEnabled, $provider, $apiKey, $folderId);
    $query = $searchMode === 'off' ? '' : aiSearchQuery($messages);

    // В режиме «Авто» идём в интернет только за тем, что меняется со временем:
    // услуга платная, и на «объясни, что такое тревога» поиск не нужен.
    $doSearch = $searchMode === 'on'
        || ($searchMode === 'auto' && $query !== '' && aiNeedsFresh($query));

    if ($doSearch && $searchReason !== '') {
        // Просили искать, а нельзя — не молчим и не делаем вид, что искали.
        $note(['stage' => 'search_off', 'reason' => $searchReason]);
        if (!$richUi) $emit('_' . $searchReason . ' Отвечаю по своим знаниям._' . "\n\n");
        $doSearch = false;
    }

    if ($doSearch) {
        $note(['stage' => 'search', 'query' => $query]);
        $found = aiWebSearch($query, $apiKey, $folderId);
        if (isset($found['error'])) {
            $hint = $found['hint'] ?? '';
            $note(['stage' => 'search_fail', 'error' => $found['error'], 'hint' => $hint,
                   'is_admin' => $isAdmin]);
            if (!$richUi) $emit('_Поиск в интернете не сработал: ' . $found['error'] . '. Отвечаю без него._' . "\n\n");
        } else {
            $srcs = array_slice($found['sources'] ?? [], 0, 6);

            // Заголовков и обрывков из выдачи не хватает, чтобы ответить по существу:
            // именно из-за этого ассистент отвечал «посмотрите на сайте». Открываем
            // первые страницы и читаем их сами — как сделал бы человек.
            $pages = [];
            if ($srcs) {
                $note(['stage' => 'reading', 'count' => min(3, count($srcs))]);
                $pages = aiFetchPages(array_slice(array_column($srcs, 'url'), 0, 3), 3000);
            }

            $ref = ($found['via'] ?? '') === 'web'
                 ? "Ниже — что нашлось в интернете по вопросу человека: отрывки из выдачи и текст "
                 . "самих страниц. Ответь по существу и сразу с конкретикой, не пересказывая названия сайтов. "
                 : "Ниже — свежие сведения из интернета по вопросу человека. "
                 . "Опирайся на них, если они относятся к делу, не выдумывай того, чего в них нет, "
                 . "и прямо скажи, если находки не отвечают на вопрос.\n\n" . (string)($found['text'] ?? '');

            $n = 0;
            foreach ($pages as $url => $text) {
                $n++;
                $ref .= "\n\n--- Со страницы " . $url . " ---\n" . $text;
            }
            if ($n) $note(['stage' => 'read_done', 'count' => $n]);
            // Админу отдаём сами вычитанные куски: когда ответ оказывается ерундой,
            // спорить о причинах бессмысленно — надо видеть, что было на входе.
            if ($n && $isAdmin) {
                $dump = [];
                foreach ($pages as $url => $text) {
                    $dump[] = ['url' => $url, 'text' => mb_substr($text, 0, 1200)];
                }
                $note(['stage' => 'read_dump', 'pages' => $dump]);
            }
            if ($srcs) {
                $ref .= "\n\nСписок источников (ссылайся на них номерами):\n";
                foreach ($srcs as $n => $src) {
                    $ref .= ($n + 1) . '. ' . $src['title'] . ' — ' . $src['url'] . "\n";
                }
                if (!$richUi) {
                    $sourcesTail = "\n\n**Источники**\n";
                    foreach ($srcs as $n => $src) {
                        $sourcesTail .= ($n + 1) . '. ' . $src['title'] . ' — ' . $src['url'] . "\n";
                    }
                }
            }
            $note(['stage' => 'search_done', 'query' => $query, 'sources' => $srcs]);
            $searchRef = $ref;
        }
    }

    // Один системный промпт на запрос: два подряд Яндекс принимает неохотно, а справку
    // из поиска всё равно логично держать вместе с описанием роли.
    $sys = aiSystemPrompt($searchRef !== '');
    if ($searchRef !== '') $sys .= "\n\n" . $searchRef;
    $tail = aiTrimHistory($messages);
    array_unshift($tail, ['role' => 'system', 'content' => $sys]);

    $payload = json_encode([
        'model'    => aiModelName($provider, $model, $folderId),
        'messages' => $tail,
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
