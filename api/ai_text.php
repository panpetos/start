<?php
/**
 * ai_text.php — превращение файлов и страниц в простой текст.
 *
 * Общий кусок для ai_chat.php (читает найденные в интернете страницы) и
 * ai_file.php (читает то, что человек приложил к вопросу). Держим отдельно,
 * чтобы не было двух расходящихся копий одного разбора.
 *
 * Ничего не выводит и не требует сессии — только функции.
 */

/**
 * Превратить страницу в текст. Без этого от «поиска» оставались одни заголовки,
 * и ассистент честно не знал ответа — отсюда и «посмотрите на сайте».
 */
function aiHtmlToText($html, $maxChars = 2000) {
    $html = (string)$html;
    // Кодировка: половина рунета всё ещё в windows-1251, иначе на выходе каша
    $enc = '';
    if (preg_match('/charset=["\']?\s*([A-Za-z0-9_-]+)/i', substr($html, 0, 4000), $m)) $enc = strtoupper($m[1]);
    if ($enc !== '' && $enc !== 'UTF-8' && function_exists('mb_convert_encoding')) {
        $conv = @mb_convert_encoding($html, 'UTF-8', $enc);
        if (is_string($conv) && $conv !== '') $html = $conv;
    }
    $html = preg_replace('#<(script|style|noscript|svg|template|iframe)\b[^>]*>.*?</\1>#is', ' ', $html);
    $html = preg_replace('#<!--.*?-->#s', ' ', $html);
    $html = preg_replace('#<(br|/p|/div|/li|/tr|/h[1-6])\s*/?>#i', "\n", $html);
    $txt = html_entity_decode(strip_tags((string)$html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $txt = preg_replace('/[ \t\x{00A0}]+/u', ' ', $txt);
    $txt = preg_replace('/(\s*\n\s*)+/u', "\n", $txt);
    $txt = trim((string)$txt);
    if ($txt === '' || !mb_check_encoding($txt, 'UTF-8')) return '';
    return mb_substr($txt, 0, $maxChars);
}

/** Обычный текстовый файл: приводим к UTF-8, иначе кириллица из блокнота — каша. */
function aiPlainToText($raw, $maxChars) {
    $raw = (string)$raw;
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $conv = @mb_convert_encoding($raw, 'UTF-8', 'Windows-1251, KOI8-R, ISO-8859-5');
        if (is_string($conv) && $conv !== '') $raw = $conv;
    }
    $raw = preg_replace('/\r\n?/', "\n", $raw);
    $raw = preg_replace('/(\s*\n\s*){3,}/u', "\n\n", $raw);
    return mb_substr(trim($raw), 0, $maxChars);
}

/**
 * .docx — это zip с word/document.xml. Разбираем сами: ставить конвертеры на
 * шаред-хостинг нельзя, а документы люди присылают именно в этом виде.
 */
function aiDocxToText($path, $maxChars) {
    if (!class_exists('ZipArchive')) return ['error' => 'на сервере нет модуля для чтения .docx'];
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return ['error' => 'файл не открывается как .docx'];
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) return ['error' => 'внутри .docx нет текста документа'];
    // Абзацы и переводы строк — до того, как срежем теги, иначе всё слипнется
    $xml = preg_replace('#<w:p\b[^>]*/?>#', "\n", $xml);
    $xml = preg_replace('#</w:p>#', "\n", $xml);
    $xml = preg_replace('#<w:(br|tab)\b[^>]*/?>#', ' ', $xml);
    $txt = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    $txt = preg_replace('/[ \t]+/u', ' ', $txt);
    $txt = preg_replace('/(\s*\n\s*){2,}/u', "\n", trim($txt));
    if ($txt === '') return ['error' => 'в документе не нашлось текста'];
    return ['text' => mb_substr($txt, 0, $maxChars)];
}

/**
 * PDF без сторонних библиотек: вытаскиваем текстовые куски из потоков.
 * Работает для документов, набранных текстом; со сканами не выйдет — так и говорим,
 * а не отдаём мусор из символов.
 */
function aiPdfToText($raw, $maxChars) {
    $raw = (string)$raw;
    $out = '';
    // Потоки бывают сжатыми (FlateDecode) и нет — пробуем и так, и так
    if (preg_match_all('#stream\r?\n?(.*?)endstream#s', $raw, $m)) {
        foreach ($m[1] as $chunk) {
            $data = @gzuncompress($chunk);
            if ($data === false) $data = @gzinflate(substr($chunk, 2));
            if ($data === false) $data = $chunk;
            if (!is_string($data) || strpos($data, 'Tj') === false && strpos($data, 'TJ') === false) continue;
            // Текст в PDF лежит в круглых скобках: (Привет) Tj
            if (preg_match_all('#\(((?:\\\\.|[^\\\\()])*)\)#s', $data, $t)) {
                foreach ($t[1] as $piece) {
                    $piece = str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $piece);
                    $out .= $piece;
                }
                $out .= "\n";
            }
        }
    }
    $out = trim(preg_replace('/[ \t]+/u', ' ', $out));
    if ($out === '' || !mb_check_encoding($out, 'UTF-8')) {
        return ['error' => 'этот PDF не читается как текст (похоже на скан) — пришлите текстом или .docx'];
    }
    return ['text' => mb_substr($out, 0, $maxChars)];
}

/**
 * Достать текст из приложенного файла. Возвращает ['text'=>…] либо ['error'=>…]
 * человеческой фразой: молчаливое «ничего не произошло» хуже отказа.
 */
function aiFileToText($path, $name, $maxChars = 20000) {
    if (!is_file($path) || !is_readable($path)) return ['error' => 'файл не найден на сервере'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $size = @filesize($path);
    if ($size === false || $size === 0) return ['error' => 'файл пустой'];
    if ($size > 25 * 1024 * 1024) return ['error' => 'слишком большой файл для чтения (больше 25 МБ)'];

    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'bmp', 'svg'], true)) {
        return ['error' => 'картинки ассистент пока не читает — перескажите текст или пришлите файлом'];
    }
    if ($ext === 'docx') return aiDocxToText($path, $maxChars);
    if ($ext === 'pdf')  return aiPdfToText((string)@file_get_contents($path), $maxChars);
    if ($ext === 'doc')  return ['error' => 'старый .doc не читается — сохраните как .docx или .txt'];

    $raw = (string)@file_get_contents($path, false, null, 0, 6 * 1024 * 1024);
    if ($raw === '') return ['error' => 'не удалось прочитать файл'];
    if (in_array($ext, ['html', 'htm', 'xml'], true)) {
        $t = aiHtmlToText($raw, $maxChars);
        return $t === '' ? ['error' => 'в файле не нашлось текста'] : ['text' => $t];
    }
    if (in_array($ext, ['txt', 'md', 'csv', 'json', 'log', 'rtf', 'yml', 'yaml', 'ini', 'sql'], true)) {
        $t = aiPlainToText($raw, $maxChars);
        return $t === '' ? ['error' => 'в файле не нашлось текста'] : ['text' => $t];
    }
    // Незнакомое расширение: если внутри читаемый текст — берём, иначе честно отказываем
    if (mb_check_encoding($raw, 'UTF-8') && preg_match('/[\p{L}\p{N}]{3}/u', $raw)) {
        return ['text' => aiPlainToText($raw, $maxChars)];
    }
    return ['error' => 'такой файл ассистент прочитать не умеет'];
}
