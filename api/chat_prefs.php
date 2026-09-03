<?php
/**
 * chat_prefs.php — личные настройки чата: обои переписки и ширина списка чатов
 * (фидбэк админа: «шторку чатов можно на край навести и мышкой двигать», «давай добавим
 * возможность делать обои на чатах»).
 *
 * Хранить в localStorage было бы проще, но тогда настройки теряются при смене устройства
 * или очистке браузера, поэтому держим на сервере. localStorage остаётся кэшем, чтобы
 * страница открывалась уже с выбранными обоями, не дожидаясь ответа сервера.
 *
 * Ключи только из белого списка — это личные настройки интерфейса, а не свалка для любых данных.
 *
 * GET  ?action=get                     — все мои настройки объектом {ключ: значение}
 * POST ?action=set   {key, value}      — записать одну настройку
 * POST ?action=set-many {prefs:{...}}  — записать несколько сразу
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/schema_util.php';
if (!function_exists('getDB') && !function_exists('getDbConnection') && !function_exists('getPDO')) {
    require_once __DIR__ . '/db.php';
}
$pdo = function_exists('getDB') ? getDB()
     : (function_exists('getDbConnection') ? getDbConnection()
     : (function_exists('getPDO') ? getPDO() : null));
if (!$pdo) { http_response_code(500); echo json_encode(['error' => 'Нет подключения к БД']); exit; }

if (session_status() === PHP_SESSION_NONE) session_start();
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }

function out($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

/** Разрешённые настройки и проверка значения. Всё остальное отклоняем. */
const PREF_RULES = [
    'wallpaper'     => '/^[a-z0-9_-]{1,40}$/',   // имя набора обоев (или photo — тогда см. wall_url)
    'sidebar_width' => '/^[0-9]{2,4}$/',          // ширина списка чатов в пикселях
    // Своё фото на фон. Принимаем только путь внутри сайта: чужая ссылка на фон —
    // это и утечка (каждый показ чата стучится на чужой сервер), и картинка,
    // которую в любой момент подменят.
    'wall_url'      => '#^(?:/uploads/[A-Za-z0-9._/-]{1,100})?$#',
    // Затемнение поверх фото: без него текст сообщений сливается со снимком
    'wall_dim'      => '/^(?:[0-9]|[1-8][0-9]|90)$/',
    // Цвет узора-обоев. Пусто = «Авто» (цвет по теме). Иначе — rgba/hex из готового
    // набора; принимаем только безопасный набор символов, чтобы значение нельзя было
    // превратить в кусок CSS.
    'doodle_color'  => '~^(?:rgba?\([0-9., ]{1,24}\)|#[0-9a-fA-F]{3,8})?$~',
    // Свои цвета пузырей
    'own_color'     => '/^(?:#[0-9a-fA-F]{6})?$/',
    'own_color2'    => '/^(?:#[0-9a-fA-F]{6})?$/',
    'theirs_color'  => '/^(?:#[0-9a-fA-F]{6})?$/',
];

$prefsError = '';
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_prefs (
        user_id VARCHAR(64) NOT NULL,
        pref_key VARCHAR(40) NOT NULL,
        pref_value VARCHAR(120) NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (user_id, pref_key)
    ) DEFAULT CHARSET=utf8mb4");
    // См. schema_util.php: без выравнивания сортировки JOIN на users падает с ошибкой 1267.
    psy_align_collation($pdo, ['chat_prefs']);
} catch (Exception $e) {
    $prefsError = $e->getMessage();
}

$action = $_GET['action'] ?? '';
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

function savePref(PDO $pdo, $userId, $key, $value) {
    if (!isset(PREF_RULES[$key])) return 'Неизвестная настройка: ' . $key;
    $value = (string)$value;
    if (!preg_match(PREF_RULES[$key], $value)) return 'Некорректное значение настройки ' . $key;
    if ($key === 'sidebar_width') {
        $w = (int)$value;
        if ($w < 200 || $w > 640) return 'Ширина списка должна быть от 200 до 640 пикселей';
        $value = (string)$w;
    }
    $st = $pdo->prepare("SELECT 1 FROM chat_prefs WHERE user_id = ? AND pref_key = ?");
    $st->execute([$userId, $key]);
    if ($st->fetchColumn()) {
        $pdo->prepare("UPDATE chat_prefs SET pref_value = ?, updated_at = NOW() WHERE user_id = ? AND pref_key = ?")
            ->execute([$value, $userId, $key]);
    } else {
        $pdo->prepare("INSERT INTO chat_prefs (user_id, pref_key, pref_value, updated_at) VALUES (?, ?, ?, NOW())")
            ->execute([$userId, $key, $value]);
    }
    return null;
}

try {
    if ($action === 'get') {
        if ($prefsError) out(['ok' => true, 'data' => []]);   // настройки — удобство, чат работает и без них
        $st = $pdo->prepare("SELECT pref_key, pref_value FROM chat_prefs WHERE user_id = ?");
        $st->execute([$userId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['pref_key']] = $r['pref_value'];
        out(['ok' => true, 'data' => $out]);
    }

    if ($action === 'set' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($prefsError) out(['ok' => false, 'error' => 'Настройки недоступны: ' . $prefsError], 500);
        $err = savePref($pdo, $userId, (string)($body['key'] ?? ''), $body['value'] ?? '');
        if ($err) out(['ok' => false, 'error' => $err], 400);
        out(['ok' => true]);
    }

    if ($action === 'set-many' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($prefsError) out(['ok' => false, 'error' => 'Настройки недоступны: ' . $prefsError], 500);
        $prefs = is_array($body['prefs'] ?? null) ? $body['prefs'] : [];
        $errors = [];
        foreach ($prefs as $k => $v) {
            $err = savePref($pdo, $userId, (string)$k, $v);
            if ($err) $errors[] = $err;
        }
        if ($errors) out(['ok' => false, 'error' => implode('; ', $errors)], 400);
        out(['ok' => true, 'count' => count($prefs)]);
    }

    out(['ok' => false, 'error' => 'Неизвестное действие'], 400);
} catch (Exception $e) {
    out(['ok' => false, 'error' => $e->getMessage()], 500);
}
