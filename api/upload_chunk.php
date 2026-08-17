<?php
/**
 * upload_chunk.php — загрузка больших файлов по частям.
 *
 * Зачем отдельный загрузчик. Обычный upload.php принимает файл одним запросом, а
 * хостинг такой запрос ограничивает: замеры на бою — PHP принимает не больше
 * 256 МБ (post_max_size), а nginx рубит запрос около гигабайта (413). То есть
 * «просто поднять цифру в проверке» гигабайтный файл не пропустит: его отрежет
 * не наш код, а сервер, и человек увидит невнятную ошибку.
 *
 * Поэтому файл режется в браузере на части по несколько мегабайт, каждая уходит
 * своим запросом и дописывается в конец временного файла. Заодно это даёт то,
 * без чего гигабайт бессмысленен: видимый прогресс и возможность продолжить с
 * той части, на которой оборвалась связь.
 *
 * POST ?action=chunk   (multipart) upload_id, index, total, name, chunk
 *      → {ok:true, received:N}                   пока части ещё идут
 *      → {ok:true, url:'/uploads/messages/...'}  когда пришла последняя
 * POST ?action=abort   {upload_id}   — бросить недогруженное
 * GET  ?action=status&upload_id=...  — сколько частей уже дошло (для продолжения)
 * GET  ?action=limits                — потолок и размер части для браузера
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$userId = $_SESSION['user_id'] ?? null;

/** Потолок одного файла. Больше гигабайта не принимаем осознанно: это уже не переписка. */
const UP_MAX_BYTES = 1073741824;          // 1 ГБ
/** Размер части. 8 МБ проходит везде и даёт заметный прогресс на длинной загрузке. */
const UP_CHUNK_BYTES = 8388608;
/** Недогруженное живёт сутки — потом это мусор, занимающий место на диске. */
const UP_TMP_TTL_SEC = 86400;

function upOut($d, $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$action = $_GET['action'] ?? '';

if ($action === 'limits') {
    upOut(['ok' => true, 'max_bytes' => UP_MAX_BYTES, 'chunk_bytes' => UP_CHUNK_BYTES,
           'max_mb' => (int)(UP_MAX_BYTES / 1048576)]);
}

if (!$userId) upOut(['ok' => false, 'error' => 'Требуется авторизация'], 401);

$tmpDir = __DIR__ . '/../uploads/tmp';
$outDir = __DIR__ . '/../uploads/messages';
foreach ([$tmpDir, $outDir] as $d) {
    if (!is_dir($d) && !@mkdir($d, 0755, true)) upOut(['ok' => false, 'error' => 'Нет папки для загрузок'], 500);
}

/**
 * Что можно класть на сайт. Список именно разрешённых, а не запрещённых:
 * забытое расширение при запрещающем списке — это чужой .php или .html в нашей
 * папке, то есть выполнение чужого кода на нашем домене.
 */
function upAllowedExt() {
    return [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'bmp', 'tiff', 'avif',
        'mp4', 'mov', 'm4v', 'webm', 'mkv', 'avi', '3gp', 'ogv',
        'mp3', 'm4a', 'aac', 'wav', 'ogg', 'oga', 'opus', 'flac', 'amr',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'rtf', 'txt', 'csv', 'md', 'odt', 'ods',
        'zip', 'rar', '7z', 'gz', 'tar',
    ];
}

/** Расширение из имени файла, приведённое к нашему виду. */
function upExt($name) {
    $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
    if ($ext === 'jpeg') $ext = 'jpg';
    return preg_replace('/[^a-z0-9]/', '', $ext);
}

/**
 * Путь к временному файлу этой загрузки.
 * В имя входит id того, кто грузит: иначе, зная чужой upload_id, можно было бы
 * дописать своё в чужой файл.
 */
function upTmpPath($tmpDir, $userId, $uploadId) {
    return $tmpDir . '/' . substr(sha1($userId . '|' . $uploadId), 0, 40) . '.part';
}

/** Подчистить брошенные куски: без этого недогруженное копится на диске молча. */
function upSweep($tmpDir) {
    $now = time();
    foreach ((array)@glob($tmpDir . '/*.part') as $f) {
        if (@filemtime($f) < $now - UP_TMP_TTL_SEC) @unlink($f);
    }
}

$body = ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST))
    ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];
$uploadId = (string)($_POST['upload_id'] ?? $body['upload_id'] ?? $_GET['upload_id'] ?? '');
if ($uploadId !== '' && !preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $uploadId)) {
    upOut(['ok' => false, 'error' => 'Некорректный номер загрузки'], 400);
}

if ($action === 'status') {
    if ($uploadId === '') upOut(['ok' => false, 'error' => 'Не передан номер загрузки'], 400);
    $p = upTmpPath($tmpDir, $userId, $uploadId);
    $size = is_file($p) ? (int)filesize($p) : 0;
    // Сколько ЦЕЛЫХ частей уже лежит — с этой и продолжаем
    upOut(['ok' => true, 'bytes' => $size, 'received' => intdiv($size, UP_CHUNK_BYTES),
           'chunk_bytes' => UP_CHUNK_BYTES]);
}

if ($action === 'abort' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($uploadId !== '') @unlink(upTmpPath($tmpDir, $userId, $uploadId));
    upOut(['ok' => true]);
}

if ($action === 'chunk' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($uploadId === '') upOut(['ok' => false, 'error' => 'Не передан номер загрузки'], 400);
    $index = (int)($_POST['index'] ?? -1);
    $total = (int)($_POST['total'] ?? 0);
    $name  = (string)($_POST['name'] ?? '');
    if ($index < 0 || $total < 1 || $index >= $total) upOut(['ok' => false, 'error' => 'Неверный номер части'], 400);

    $ext = upExt($name);
    if (!in_array($ext, upAllowedExt(), true)) {
        upOut(['ok' => false, 'error' => 'Такой тип файла загружать нельзя'], 400);
    }
    // Считаем потолок ДО приёма частей: незачем принимать 900 МБ, чтобы отказать в конце
    if ((float)$total * UP_CHUNK_BYTES > UP_MAX_BYTES + UP_CHUNK_BYTES) {
        upOut(['ok' => false, 'error' => 'Файл больше ' . (int)(UP_MAX_BYTES / 1048576) . ' МБ'], 400);
    }

    if (empty($_FILES['chunk']) || ($_FILES['chunk']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        upOut(['ok' => false, 'error' => 'Часть файла не дошла'], 400);
    }

    $path = upTmpPath($tmpDir, $userId, $uploadId);
    if ($index === 0) { @unlink($path); upSweep($tmpDir); }

    $have = is_file($path) ? (int)filesize($path) : 0;
    $expect = $index * UP_CHUNK_BYTES;
    if ($have < $expect) upOut(['ok' => false, 'error' => 'Потерялась предыдущая часть', 'received' => intdiv($have, UP_CHUNK_BYTES)], 409);
    // Часть пришла повторно (браузер повторил запрос) — молча подтверждаем, не дописывая дважды
    if ($have > $expect) upOut(['ok' => true, 'received' => intdiv($have, UP_CHUNK_BYTES), 'duplicate' => true]);

    $in = @fopen($_FILES['chunk']['tmp_name'], 'rb');
    $out = @fopen($path, 'ab');
    if (!$in || !$out) {
        if ($in) fclose($in);
        if ($out) fclose($out);
        upOut(['ok' => false, 'error' => 'Не удалось сохранить часть'], 500);
    }
    // Под замком: два запроса подряд от одного человека иначе пишут внахлёст
    $written = 0;
    if (flock($out, LOCK_EX)) {
        while (!feof($in)) {
            $buf = fread($in, 1048576);
            if ($buf === false) break;
            $w = fwrite($out, $buf);
            if ($w === false) break;
            $written += $w;
        }
        fflush($out);
        flock($out, LOCK_UN);
    }
    fclose($in);
    fclose($out);
    @unlink($_FILES['chunk']['tmp_name']);

    $size = (int)filesize($path);
    if ($size > UP_MAX_BYTES) {
        @unlink($path);
        upOut(['ok' => false, 'error' => 'Файл больше ' . (int)(UP_MAX_BYTES / 1048576) . ' МБ'], 400);
    }

    if ($index < $total - 1) upOut(['ok' => true, 'received' => $index + 1, 'bytes' => $size]);

    // Последняя часть — собираем файл под своим именем. Имя даём мы: чужое имя
    // из браузера — это путь наружу папки и расширение по своему усмотрению.
    $final = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!@rename($path, $outDir . '/' . $final)) {
        @unlink($path);
        upOut(['ok' => false, 'error' => 'Не удалось сохранить файл'], 500);
    }
    @chmod($outDir . '/' . $final, 0644);
    upOut(['ok' => true, 'url' => '/uploads/messages/' . $final, 'bytes' => $size,
           'name' => mb_substr(preg_replace('/[\r\n]/', '', $name), 0, 255)]);
}

upOut(['ok' => false, 'error' => 'Неизвестное действие'], 400);
