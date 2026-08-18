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

        $res = aiFileToText($path, $name, min(20000, $left));
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
