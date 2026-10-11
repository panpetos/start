<?php
/**
 * transcribe.php — расшифровка голосовых и кружков в текст через Yandex SpeechKit.
 *
 * POST ?action=chunk   (тело — сырой звук LPCM 16 бит, моно; заголовок X-Rate: 16000)
 *                      → {ok:true, text:"..."}
 * GET  ?action=get&url=<вложение>   → уже готовая расшифровка, если её кто-то сделал
 * POST ?action=get-many {urls:[]}   → то же пачкой, чтобы не слать запрос на каждую запись
 * POST ?action=save  {url, text}    → сохранить расшифровку для всех
 * GET  ?action=status  → готов ли сервис (ключ и каталог берём из настройки ИИ-ассистента)
 *
 * Расшифровка хранится на сервере и привязана к файлу записи: кто первым нажал —
 * тот и оплатил распознавание, остальные (и он сам в следующий раз) получают готовый
 * текст мгновенно и без обращения к сервису.
 *
 * Почему сырой звук, а не файл записи: SpeechKit принимает OggOpus, MP3 и LPCM, а наши
 * записи — webm/opus (Chrome) или mp4/aac (Safari). Перекодировать на сервере нечем
 * (ffmpeg на хостинге нет), зато браузер уже умеет декодировать запись — он же рисует
 * её волну. Поэтому звук приходит сюда готовым LPCM, и перекодирование не нужно вовсе.
 *
 * Ограничение сервиса на короткое распознавание — 1 МБ и 30 секунд за запрос, поэтому
 * длинные записи браузер режет на куски и склеивает результат.
 *
 * Ключ и каталог — те же, что у ИИ-ассистента (api/ai_chat_config.php вне git).
 */

header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Rate');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/schema_util.php';
if (!function_exists('getDB') && !function_exists('getDbConnection') && !function_exists('getPDO')) {
    require_once __DIR__ . '/db.php';
}
$pdo = function_exists('getDB') ? getDB()
     : (function_exists('getDbConnection') ? getDbConnection()
     : (function_exists('getPDO') ? getPDO() : null));
if (session_status() === PHP_SESSION_NONE) session_start();
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }

$cfgFile = __DIR__ . '/ai_chat_config.php';
$cfg = file_exists($cfgFile) ? (require $cfgFile) : [];
if (!is_array($cfg)) $cfg = [];
$apiKey   = trim((string)($cfg['api_key'] ?? ''));
$folderId = trim((string)($cfg['folder_id'] ?? ''));

/** Чего не хватает для расшифровки. Пустая строка — всё на месте. */
function sttMissing($apiKey, $folderId) {
    if ($apiKey === '') return 'Не задан ключ Yandex Cloud. Админка → «ИИ-ассистент».';
    if ($folderId === '') return 'Не задан идентификатор каталога Yandex Cloud. Админка → «ИИ-ассистент».';
    return '';
}

/** Ключ расшифровки — путь к файлу записи без домена и лишних параметров. */
function sttKey($url) {
    $u = trim((string)$url);
    $p = parse_url($u, PHP_URL_PATH);
    return substr($p ?: $u, 0, 255);
}

if ($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS transcripts (
            audio_key VARCHAR(255) PRIMARY KEY,
            text MEDIUMTEXT NOT NULL,
            lang VARCHAR(10) NOT NULL DEFAULT 'ru-RU',
            created_by VARCHAR(64) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        psy_align_collation($pdo, ['transcripts']);
    } catch (Exception $e) { /* без таблицы просто не будет общего кэша */ }
}

$action = $_GET['action'] ?? '';
// Тело разбираем только там, где это JSON: у chunk в теле сырой звук на сотни килобайт,
// и гонять его через json_decode незачем.
$body = [];
if ($action !== 'chunk') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) $body = [];
}

if ($action === 'get') {
    $key = sttKey($_GET['url'] ?? '');
    if ($key === '' || !$pdo) { echo json_encode(['ok' => true, 'text' => null]); exit; }
    try {
        $st = $pdo->prepare("SELECT text FROM transcripts WHERE audio_key = ? LIMIT 1");
        $st->execute([$key]);
        $t = $st->fetchColumn();
        echo json_encode(['ok' => true, 'text' => $t === false ? null : (string)$t], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) { echo json_encode(['ok' => true, 'text' => null]); }
    exit;
}

if ($action === 'get-many' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Пачкой: в переписке бывает несколько десятков записей, и спрашивать про каждую
    // отдельным запросом — лишняя нагрузка на открытие чата.
    $urls = (array)($body['urls'] ?? []);
    $keys = [];
    foreach (array_slice($urls, 0, 200) as $u) { $k = sttKey($u); if ($k !== '') $keys[$k] = true; }
    $out = [];
    if ($keys && $pdo) {
        $list = array_keys($keys);
        $in = implode(',', array_fill(0, count($list), '?'));
        try {
            $st = $pdo->prepare("SELECT audio_key, text FROM transcripts WHERE audio_key IN ($in)");
            $st->execute($list);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(string)$r['audio_key']] = (string)$r['text'];
        } catch (Exception $e) {}
    }
    echo json_encode(['ok' => true, 'data' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $key = sttKey($body['url'] ?? '');
    $text = trim((string)($body['text'] ?? ''));
    if ($key === '' || $text === '') { http_response_code(400); echo json_encode(['error' => 'Нечего сохранять']); exit; }
    if (mb_strlen($text) > 20000) $text = mb_substr($text, 0, 20000);
    if (!$pdo) { echo json_encode(['ok' => true, 'stored' => false]); exit; }
    try {
        $st = $pdo->prepare("INSERT INTO transcripts (audio_key, text, created_by) VALUES (?,?,?)
                             ON DUPLICATE KEY UPDATE text = VALUES(text)");
        $st->execute([$key, $text, $userId]);
    } catch (Exception $e) {
        try {
            $pdo->prepare("REPLACE INTO transcripts (audio_key, text, created_by) VALUES (?,?,?)")
                ->execute([$key, $text, $userId]);
        } catch (Exception $e2) { echo json_encode(['ok' => true, 'stored' => false]); exit; }
    }
    echo json_encode(['ok' => true, 'stored' => true]);
    exit;
}

if ($action === 'status') {
    $miss = sttMissing($apiKey, $folderId);
    echo json_encode(['ok' => $miss === '', 'missing' => $miss], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'chunk' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $miss = sttMissing($apiKey, $folderId);
    if ($miss !== '') { http_response_code(400); echo json_encode(['error' => $miss], JSON_UNESCAPED_UNICODE); exit; }

    $audio = file_get_contents('php://input');
    if ($audio === false || strlen($audio) < 1000) {
        http_response_code(400); echo json_encode(['error' => 'Пустой фрагмент звука']); exit;
    }
    if (strlen($audio) > 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['error' => 'Фрагмент больше мегабайта — сервис такие не принимает'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $rate = (int)($_SERVER['HTTP_X_RATE'] ?? 16000);
    if (!in_array($rate, [8000, 16000, 48000], true)) $rate = 16000;
    $lang = preg_match('/^[a-z]{2}-[A-Z]{2}$/', (string)($_GET['lang'] ?? '')) ? $_GET['lang'] : 'ru-RU';

    $url = 'https://stt.api.cloud.yandex.net/speech/v1/stt:recognize'
         . '?folderId=' . urlencode($folderId)
         . '&lang=' . urlencode($lang)
         . '&format=lpcm&sampleRateHertz=' . $rate;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $audio,
        CURLOPT_HTTPHEADER => [
            'Authorization: Api-Key ' . $apiKey,
            'Content-Type: application/octet-stream',
            'Transfer-Encoding:',            // иначе curl шлёт chunked, а сервис его не любит
        ],
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) { http_response_code(502); echo json_encode(['error' => 'Не достучались до сервиса: ' . $err], JSON_UNESCAPED_UNICODE); exit; }
    $d = json_decode((string)$resp, true);
    if ($code < 200 || $code >= 300) {
        $msg = $d['error_message'] ?? ($d['message'] ?? substr((string)$resp, 0, 300));
        http_response_code(502);
        echo json_encode(['error' => 'Сервис отказал (' . $code . '): ' . $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => true, 'text' => (string)($d['result'] ?? '')], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Неизвестное действие']);
