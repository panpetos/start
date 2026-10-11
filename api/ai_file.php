<?php
/**
 * ai_file.php — прочитать файлы, приложенные к вопросу ассистенту.
 *
 * POST ?action=read {urls:["/uploads/messages/xxx.docx", ...]} → [{name, url, chars, text|error}]
 *
 * Сам файл уже лежит на сервере: его кладёт обычная загрузка чата (upload.php или
 * upload_chunk.php), поэтому здесь ничего не принимаем в теле, кроме ссылок — так
 * работает и дозагрузка кусками для больших файлов, и общий предпросмотр вложений.
 */

header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ai_text.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }

/**
 * Распознать текст на картинке через Yandex Vision OCR — тем же ключом, что и модели.
 * Услугу в облаке включают отдельно, поэтому при отказе говорим человеку, что именно
 * не так, а не отмалчиваемся. Возвращает ['text'=>…] либо ['error'=>…].
 */
function aiOcrImage($path, $name, $maxChars = 20000) {
    $cfgFile = __DIR__ . '/ai_chat_config.php';
    $cfg = file_exists($cfgFile) ? (require $cfgFile) : [];
    if (!is_array($cfg)) $cfg = [];
    $key = trim((string)($cfg['api_key'] ?? ''));
    $folder = trim((string)($cfg['folder_id'] ?? ''));
    if ($key === '' || $folder === '') {
        return ['error' => 'картинки читаются через Yandex Vision, а ключ облака ещё не настроен'];
    }
    $size = @filesize($path);
    if ($size > 10 * 1024 * 1024) return ['error' => 'картинка слишком большая для распознавания (больше 10 МБ)'];

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $mime = $ext === 'png' ? 'PNG' : ($ext === 'pdf' ? 'PDF' : 'JPEG');
    $body = json_encode([
        'mimeType'      => $mime,
        'languageCodes' => ['ru', 'en'],
        'model'         => 'page',
        'content'       => base64_encode((string)@file_get_contents($path)),
    ]);

    $ch = curl_init('https://ocr.api.cloud.yandex.net/ocr/v1/recognizeText');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Api-Key ' . $key,
            'x-folder-id: ' . $folder,
            'x-data-logging-enabled: false',
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return ['error' => 'не достучались до распознавания: ' . $err];
    $d = json_decode((string)$resp, true);
    if ($code < 200 || $code >= 300) {
        $msg = $d['message'] ?? substr((string)$resp, 0, 160);
        if ($code === 403 || $code === 401) {
            return ['error' => 'распознавание картинок не включено в Yandex Cloud (Vision OCR) — '
                             . 'подключите сервис и выдайте ключу роль ai.vision.user'];
        }
        return ['error' => 'распознавание отказало (' . $code . '): ' . $msg];
    }

    $text = $d['result']['textAnnotation']['fullText'] ?? ($d['textAnnotation']['fullText'] ?? '');
    $text = trim(preg_replace('/[ \t]+/u', ' ', (string)$text));
    if ($text === '') return ['error' => 'на картинке не нашлось текста'];
    return ['text' => mb_substr($text, 0, $maxChars)];
}

$action = $_GET['action'] ?? 'read';
if ($action !== 'read') { echo json_encode(['error' => 'Неизвестное действие']); exit; }

try {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $urls = array_slice((array)($body['urls'] ?? []), 0, 5);   // больше пяти за раз незачем
    if (!$urls) { echo json_encode(['ok' => true, 'files' => []]); exit; }

    // Читаем только то, что лежит в наших загрузках: путь из запроса — не повод
    // открывать что угодно на диске.
    $root = realpath(__DIR__ . '/../uploads');
    $left = 40000;                                            // общий предел на все файлы
    $files = [];

    foreach ($urls as $u) {
        $u = (string)$u;
        $name = basename(parse_url($u, PHP_URL_PATH) ?: '');
        $row = ['url' => $u, 'name' => $name, 'chars' => 0];

        if ($root === false || strpos($u, '/uploads/') !== 0 || strpos($u, '..') !== false) {
            $row['error'] = 'файл не из загрузок чата';
            $files[] = $row;
            continue;
        }
        $path = realpath(__DIR__ . '/..' . $u);
        if ($path === false || strpos($path, $root . DIRECTORY_SEPARATOR) !== 0) {
            $row['error'] = 'файл не найден';
            $files[] = $row;
            continue;
        }
        if ($left <= 0) {
            $row['error'] = 'не влезло: слишком много текста в остальных файлах';
            $files[] = $row;
            continue;
        }

        $isImage = in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)),
                            ['jpg', 'jpeg', 'png', 'webp', 'bmp'], true);
        // Картинку сначала пробуем прочитать распознаванием — скриншоты присылают чаще,
        // чем документы, и «я не умею» на них выглядит беспомощно.
        $res = $isImage ? aiOcrImage($path, $name, min(20000, $left))
                        : aiFileToText($path, $name, min(20000, $left));
        if ($isImage && isset($res['error'])) $row['kind'] = 'image';
        if (isset($res['error'])) {
            $row['error'] = $res['error'];
        } else {
            $row['text'] = $res['text'];
            $row['chars'] = mb_strlen($res['text']);
            $left -= $row['chars'];
        }
        $files[] = $row;
    }

    echo json_encode(['ok' => true, 'files' => $files], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Не удалось прочитать файлы: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
