<?php
/**
 * thumb.php — уменьшенная копия картинки для быстрого показа в переписке.
 *
 * ЗАЧЕМ. В чат приходят снимки прямо с телефона: 3–6 МБ, 4000 пикселей по
 * длинной стороне. Браузер честно тянет каждый оригинал и рисует их по мере
 * загрузки — отсюда и «фото подгружаются медленно, то справа налево, то сверху
 * вниз». Показывать в ленте оригинал незачем: пузырь всё равно шириной
 * несколько сотен пикселей.
 *
 * Теперь в переписке стоит уменьшенная копия (по умолчанию 900px по длинной
 * стороне, JPEG/WebP), а оригинал остаётся нетронутым: он открывается при
 * увеличении и скачивается как есть. Так делают все мессенджеры.
 *
 * GET ?u=/uploads/messages/xxx.jpg[&w=900]
 *   → тело картинки с длинным кэшем; если уменьшить нечем — редирект на оригинал.
 *
 * БЕЗОПАСНОСТЬ. Открываем только то, что лежит в наших загрузках: путь из
 * запроса сам по себе не повод читать что угодно с диска (та же проверка, что
 * в ai_file.php — realpath внутрь uploads).
 *
 * НАГРУЗКА. Уменьшенная копия делается один раз и кладётся рядом, в
 * uploads/.thumbs. Дальше файл просто отдаётся, а браузеру говорится держать
 * его месяц — при повторных открытиях чата запрос вообще не доходит до сервера.
 */

$ALLOWED_W = [320, 640, 900, 1280];
$DEFAULT_W = 900;
$MAX_SRC_BYTES = 40 * 1024 * 1024;      // больше — не наша забота, отдадим оригинал

$u = (string)($_GET['u'] ?? '');
$w = (int)($_GET['w'] ?? $DEFAULT_W);
if (!in_array($w, $ALLOWED_W, true)) $w = $DEFAULT_W;

/** Отправить человека на оригинал: лучше медленно, чем никак. */
function thumbFallback($u) {
    $safe = '/' . ltrim(str_replace(["\r", "\n"], '', $u), '/');
    header('Cache-Control: public, max-age=600');
    header('Location: ' . $safe, true, 302);
    exit;
}

$path = parse_url($u, PHP_URL_PATH) ?: '';
if ($path === '' || strpos($path, '/uploads/') !== 0 || strpos($path, '..') !== false) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Ожидается путь внутри /uploads/';
    exit;
}

$root = realpath(__DIR__ . '/../uploads');
$src  = realpath(__DIR__ . '/..' . $path);
if ($root === false || $src === false || strpos($src, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($src)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Файл не найден';
    exit;
}

// Не картинка или её нечем уменьшить — отдаём как есть
$info = @getimagesize($src);
$type = $info ? (int)$info[2] : 0;
$okType = in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true);
if (!$info || !$okType || !function_exists('imagecreatetruecolor') || filesize($src) > $MAX_SRC_BYTES) {
    thumbFallback($path);
}
// Анимированный GIF уменьшать нельзя — потеряется анимация
if ($type === IMAGETYPE_GIF) thumbFallback($path);

// Картинка и так меньше запрошенного — уменьшать нечего
$sw = (int)$info[0]; $sh = (int)$info[1];
if ($sw <= 0 || $sh <= 0) thumbFallback($path);
if (max($sw, $sh) <= $w) thumbFallback($path);

$cacheDir = __DIR__ . '/../uploads/.thumbs';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
// Имя кэша учитывает и время правки файла: заменят картинку — копия обновится сама.
// Суффикс |o2 — версия логики: подняли его, когда научились учитывать EXIF-поворот,
// чтобы уже сделанные (перевёрнутые) копии пересоздались, а не отдавались из кэша.
$key = sha1($path . '|' . $w . '|' . (string)@filemtime($src) . '|o2');
$cache = $cacheDir . '/' . $key . '.jpg';

/**
 * Развернуть картинку по EXIF-ориентации (снимки с телефона часто «лежат на боку»:
 * в файле пиксели повёрнуты, а правильный поворот записан в EXIF). GD при
 * imagecreatefromjpeg EXIF не применяет — поэтому уменьшенная копия выходила
 * повёрнутой, хотя оригинал браузер показывал верно. Возвращаем, возможно, новый
 * ресурс (imagerotate создаёт новый), поэтому результат обязательно присваивать.
 */
function thumbApplyExif($img, $src, $type) {
    if ($type !== IMAGETYPE_JPEG || !function_exists('exif_read_data') || !function_exists('imagerotate')) return $img;
    $ori = 0;
    try { $ex = @exif_read_data($src); if ($ex && !empty($ex['Orientation'])) $ori = (int)$ex['Orientation']; }
    catch (Throwable $e) { $ori = 0; }
    if ($ori <= 1) return $img;
    // imagerotate в GD крутит ПРОТИВ часовой; углы подобраны под стандарт EXIF 1..8.
    switch ($ori) {
        case 2: if (function_exists('imageflip')) imageflip($img, IMG_FLIP_HORIZONTAL); break;
        case 3: $img = imagerotate($img, 180, 0); break;
        case 4: if (function_exists('imageflip')) imageflip($img, IMG_FLIP_VERTICAL); break;
        case 5: $img = imagerotate($img, -90, 0); if (function_exists('imageflip')) imageflip($img, IMG_FLIP_HORIZONTAL); break;
        case 6: $img = imagerotate($img, -90, 0); break;
        case 7: $img = imagerotate($img, 90, 0); if (function_exists('imageflip')) imageflip($img, IMG_FLIP_HORIZONTAL); break;
        case 8: $img = imagerotate($img, 90, 0); break;
    }
    return $img;
}

/** Отдать готовый файл с длинным кэшем и поддержкой 304. */
function thumbSend($file) {
    $etag = '"' . sha1_file($file) . '"';
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=2592000, immutable');   // месяц
    header('ETag: ' . $etag);
    if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

if (is_file($cache) && filesize($cache) > 0) thumbSend($cache);

// ── Делаем копию ────────────────────────────────────────────────────────────
$srcImg = null;
try {
    if     ($type === IMAGETYPE_JPEG) $srcImg = @imagecreatefromjpeg($src);
    elseif ($type === IMAGETYPE_PNG)  $srcImg = @imagecreatefrompng($src);
    elseif ($type === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) $srcImg = @imagecreatefromwebp($src);
} catch (Throwable $e) { $srcImg = null; }
if (!$srcImg) thumbFallback($path);

// Учитываем EXIF-поворот ДО уменьшения. После поворота на 90/270° стороны
// меняются местами — берём размеры уже из повёрнутого изображения.
$srcImg = thumbApplyExif($srcImg, $src, $type);
$sw = imagesx($srcImg); $sh = imagesy($srcImg);

$scale = $w / max($sw, $sh);
$dw = max(1, (int)round($sw * $scale));
$dh = max(1, (int)round($sh * $scale));

$dst = @imagecreatetruecolor($dw, $dh);
if (!$dst) { imagedestroy($srcImg); thumbFallback($path); }
// Прозрачность у PNG/WebP заливаем белым: копия отдаётся JPEG-ом, а он без альфы —
// иначе прозрачные места стали бы чёрными.
$white = imagecolorallocate($dst, 255, 255, 255);
imagefilledrectangle($dst, 0, 0, $dw, $dh, $white);
imagecopyresampled($dst, $srcImg, 0, 0, 0, 0, $dw, $dh, $sw, $sh);
imagedestroy($srcImg);

// Пишем через временный файл: иначе при двух одновременных запросах кто-то
// прочитает половину копии и увидит битую картинку.
$tmp = $cache . '.' . getmypid() . '.tmp';
$ok = @imagejpeg($dst, $tmp, 82);
imagedestroy($dst);
if (!$ok || !is_file($tmp)) { @unlink($tmp); thumbFallback($path); }
@rename($tmp, $cache);

thumbSend(is_file($cache) ? $cache : $tmp);
