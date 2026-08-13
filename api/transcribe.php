<?php
/**
 * transcribe.php — расшифровка голосовых и кружков в текст через Yandex SpeechKit.
 *
 * POST ?action=chunk   (тело — сырой звук LPCM 16 бит, моно; заголовок X-Rate: 16000)
 *                      → {ok:true, text:"..."}
 * GET  ?action=status  → готов ли сервис (ключ и каталог берём из настройки ИИ-ассистента)
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

$action = $_GET['action'] ?? '';

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
