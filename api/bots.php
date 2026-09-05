<?php
/**
 * bots.php — платформа ботов (база). Позволяет создать бота, получить ТОКЕН и
 * подключать по нему внешние системы: приём заявок со стороннего сайта, интеграции
 * (OpenClaude и т.п.). Самостоятельный файл, та же БД, свои таблицы.
 *
 * ── Управление (нужна сессия администратора) ──
 *   GET  ?action=list                     — список ботов (с токенами, только админ)
 *   POST ?action=create   {name, scopes}  — создать бота, вернуть токен
 *   POST ?action=regenerate {id}          — сменить токен
 *   POST ?action=toggle   {id, active}    — вкл/выкл
 *   POST ?action=delete   {id}            — удалить
 *   GET  ?action=leads[&bot_id=]          — заявки, пришедшие через ботов
 *
 * ── API бота (авторизация ТОКЕНОМ, не сессией) ──
 *   Токен в заголовке `Authorization: Bearer <token>`, либо ?token=, либо в теле.
 *   POST/GET ?action=me                   — проверить токен, вернуть имя и права
 *   POST     ?action=lead {name, contact, message, source, meta}
 *                                         — создать заявку (напр. с внешнего сайта)
 *
 * Токены — секреты: в git не попадают, живут только в БД, показываются в админке.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';
if (!function_exists('getDB') && !function_exists('getDbConnection') && !function_exists('getPDO')) {
    require_once __DIR__ . '/db.php';
}
$pdo = function_exists('getDB') ? getDB()
     : (function_exists('getDbConnection') ? getDbConnection()
     : (function_exists('getPDO') ? getPDO() : null));
if (!$pdo) { http_response_code(500); echo json_encode(['error' => 'Нет подключения к БД']); exit; }

function bout($d, $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function bclientIp(): string {
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) { $ip = trim(explode(',', $_SERVER[$k])[0]); if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip; }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS bots (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        token CHAR(52) NOT NULL,
        scopes VARCHAR(255) NOT NULL DEFAULT 'lead',
        owner_user_id VARCHAR(64) NULL,
        is_active TINYINT NOT NULL DEFAULT 1,
        calls_count INT NOT NULL DEFAULT 0,
        last_used_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_token (token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_leads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bot_id INT NOT NULL,
        name VARCHAR(200) NULL,
        contact VARCHAR(200) NULL,
        message TEXT NULL,
        source VARCHAR(200) NULL,
        meta TEXT NULL,
        ip VARCHAR(64) NULL,
        handled TINYINT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_bot (bot_id),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

$ALL_SCOPES = ['lead', 'message', 'ai', 'post'];   // реализованы: lead, me; остальные — задел
$action = $_GET['action'] ?? '';
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

function newToken(): string {
    try { $raw = bin2hex(random_bytes(24)); }
    catch (Exception $e) { $raw = bin2hex(openssl_random_pseudo_bytes(24)); }
    return 'psb_' . $raw;   // psb_ + 48 hex = 52 символа
}

// ─────────────────────────────────────────────────────────────────────────────
//  API БОТА (авторизация токеном) — сюда стучатся внешние системы
// ─────────────────────────────────────────────────────────────────────────────
$TOKEN_ACTIONS = ['me', 'lead', 'ask'];
if (in_array($action, $TOKEN_ACTIONS, true)) {
    // Токен: заголовок Authorization: Bearer …, либо ?token=, либо тело
    $token = '';
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/Bearer\s+(\S+)/i', $hdr, $m)) $token = $m[1];
    if ($token === '') $token = (string)($_GET['token'] ?? ($body['token'] ?? ''));
    $token = trim($token);
    if ($token === '') bout(['error' => 'Нужен токен бота'], 401);

    $bot = null;
    try { $st = $pdo->prepare("SELECT * FROM bots WHERE token = ? LIMIT 1"); $st->execute([$token]); $bot = $st->fetch(PDO::FETCH_ASSOC); }
    catch (Exception $e) {}
    if (!$bot) bout(['error' => 'Неизвестный токен'], 401);
    if ((int)$bot['is_active'] !== 1) bout(['error' => 'Бот отключён'], 403);

    // Отметка использования — побочная запись, глушим ошибки, данные не задерживаем
    botTouch($pdo, (int)$bot['id']);

    $scopes = array_filter(array_map('trim', explode(',', (string)$bot['scopes'])));
    $has = function ($s) use ($scopes) { return in_array($s, $scopes, true); };

    if ($action === 'me') {
        bout(['ok' => true, 'bot' => ['name' => $bot['name'], 'scopes' => array_values($scopes)]]);
    }

    if ($action === 'ask') {
        // ИИ-ответ (OpenClaude): внешняя система шлёт {prompt}, получает {answer}.
        // Ключи/провайдер берём из того же ai_chat_config.php, что и ассистент.
        if (!$has('ai')) bout(['error' => 'У бота нет права на ИИ (scope ai)'], 403);
        $prompt = trim((string)($body['prompt'] ?? ($body['message'] ?? '')));
        if ($prompt === '') bout(['error' => 'Пустой запрос: нужен prompt'], 400);
        $system = mb_substr(trim((string)($body['system'] ?? 'Ты — помощник сервиса psytalk.pro. Отвечай кратко и по делу.')), 0, 2000);

        $cfgFile = __DIR__ . '/ai_chat_config.php';
        $cfg = file_exists($cfgFile) ? (require $cfgFile) : [];
        if (!is_array($cfg)) $cfg = [];
        $provider = ($cfg['provider'] ?? 'yandex') === 'openrouter' ? 'openrouter' : 'yandex';
        $apiKey   = trim((string)($cfg['api_key'] ?? ''));
        $folderId = trim((string)($cfg['folder_id'] ?? ''));
        if ($apiKey === '') bout(['error' => 'ИИ не настроен на сервере (нет ключа)'], 503);
        if ($provider === 'yandex' && $folderId === '') bout(['error' => 'Для Yandex не задан folder_id'], 503);

        $models = $cfg['models'] ?? [];
        $first = (is_array($models) && $models) ? (string)array_key_first($models) : '';
        if ($provider === 'openrouter') { $model = $first !== '' ? $first : 'deepseek/deepseek-chat-v3-0324:free'; }
        else { $m = $first !== '' ? $first : 'yandexgpt-lite/latest'; $model = (strpos($m, 'gpt://') === 0 || strpos($m, 'ds://') === 0) ? $m : ('gpt://' . $folderId . '/' . $m); }

        $endpoint = $provider === 'openrouter'
            ? 'https://openrouter.ai/api/v1/chat/completions'
            : 'https://llm.api.cloud.yandex.net/v1/chat/completions';
        $headers = ['Content-Type: application/json'];
        if ($provider === 'openrouter') { $headers[] = 'Authorization: Bearer ' . $apiKey; $headers[] = 'HTTP-Referer: https://psytalk.pro'; $headers[] = 'X-Title: PsyTalk Bot'; }
        else { $headers[] = 'Authorization: Api-Key ' . $apiKey; }
        $payload = json_encode([
            'model' => $model, 'temperature' => 0.6, 'max_tokens' => 900,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => mb_substr($prompt, 0, 4000)],
            ],
        ], JSON_UNESCAPED_UNICODE);
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 10]);
        $raw = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
        if ($raw === false) bout(['error' => 'ИИ недоступен: ' . $err], 502);
        $dd = json_decode($raw, true);
        $answer = is_array($dd) ? ($dd['choices'][0]['message']['content'] ?? $dd['result']['alternatives'][0]['message']['text'] ?? '') : '';
        $answer = trim((string)$answer);
        if ($answer === '') { $mm = is_array($dd) ? ($dd['error']['message'] ?? $dd['message'] ?? '') : ''; bout(['error' => 'ИИ не вернул ответ' . ($mm ? ': ' . $mm : '')], 502); }
        bout(['ok' => true, 'answer' => $answer]);
    }

    if ($action === 'lead') {
        if (!$has('lead')) bout(['error' => 'У бота нет права на заявки (scope lead)'], 403);
        $name    = mb_substr(trim((string)($body['name'] ?? '')), 0, 200);
        $contact = mb_substr(trim((string)($body['contact'] ?? '')), 0, 200);
        $message = mb_substr(trim((string)($body['message'] ?? '')), 0, 4000);
        $source  = mb_substr(trim((string)($body['source'] ?? '')), 0, 200);
        $meta    = isset($body['meta']) ? mb_substr(json_encode($body['meta'], JSON_UNESCAPED_UNICODE), 0, 4000) : null;
        if ($name === '' && $contact === '' && $message === '') bout(['error' => 'Пустая заявка: нужно хотя бы имя, контакт или сообщение'], 400);
        try {
            $st = $pdo->prepare("INSERT INTO bot_leads (bot_id, name, contact, message, source, meta, ip) VALUES (?,?,?,?,?,?,?)");
            $st->execute([(int)$bot['id'], $name, $contact, $message, $source, $meta, bclientIp()]);
            bout(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
        } catch (Exception $e) { bout(['error' => 'Не удалось сохранить заявку'], 500); }
    }

    bout(['error' => 'Неизвестное действие бота'], 400);
}

/** Побочная отметка «бот использован». Своя обёртка, молчит при любой ошибке. */
function botTouch(PDO $pdo, int $botId): void {
    try { $pdo->prepare("UPDATE bots SET calls_count = calls_count + 1, last_used_at = NOW() WHERE id = ?")->execute([$botId]); }
    catch (Exception $e) {}
}

// ─────────────────────────────────────────────────────────────────────────────
//  УПРАВЛЕНИЕ (по сессии). Как BotFather: любой авторизованный пользователь заводит
//  СВОИХ ботов и управляет только ими. Администратор видит и правит всех.
// ─────────────────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) bout(['error' => 'Требуется авторизация'], 401);
$isAdmin = false;
try { $st = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1"); $st->execute([$userId]); $isAdmin = ((string)$st->fetchColumn() === 'admin'); }
catch (Exception $e) {}

function normScopes($val, array $all): string {
    $list = is_array($val) ? $val : explode(',', (string)$val);
    $list = array_values(array_unique(array_filter(array_map('trim', $list), function ($s) use ($all) { return in_array($s, $all, true); })));
    if (!$list) $list = ['lead'];
    return implode(',', $list);
}
/** Бот принадлежит пользователю? (админу — любой). Иначе — доступа нет. */
function botOwned(PDO $pdo, int $id, $userId, bool $isAdmin): bool {
    if ($isAdmin) return true;
    try { $st = $pdo->prepare("SELECT owner_user_id FROM bots WHERE id = ? LIMIT 1"); $st->execute([$id]); return ((string)$st->fetchColumn() === (string)$userId); }
    catch (Exception $e) { return false; }
}

if ($action === 'list') {
    try {
        if ($isAdmin) { $st = $pdo->query("SELECT id, name, token, scopes, is_active, calls_count, last_used_at, created_at, owner_user_id FROM bots ORDER BY id DESC"); }
        else { $st = $pdo->prepare("SELECT id, name, token, scopes, is_active, calls_count, last_used_at, created_at, owner_user_id FROM bots WHERE owner_user_id = ? ORDER BY id DESC"); $st->execute([$userId]); }
        bout(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC), 'is_admin' => $isAdmin]);
    } catch (Exception $e) { bout(['ok' => true, 'data' => []]); }
}

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = mb_substr(trim((string)($body['name'] ?? '')), 0, 120);
    if ($name === '') bout(['error' => 'Укажите название бота'], 400);
    $scopes = normScopes($body['scopes'] ?? 'lead', $ALL_SCOPES);
    try {
        $token = newToken();
        $st = $pdo->prepare("INSERT INTO bots (name, token, scopes, owner_user_id) VALUES (?,?,?,?)");
        $st->execute([$name, $token, $scopes, $userId]);
        bout(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'token' => $token]);
    } catch (Exception $e) { bout(['error' => 'Не удалось создать бота'], 500); }
}

if ($action === 'regenerate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) bout(['error' => 'Не указан бот'], 400);
    if (!botOwned($pdo, $id, $userId, $isAdmin)) bout(['error' => 'Это не ваш бот'], 403);
    try {
        $token = newToken();
        $pdo->prepare("UPDATE bots SET token = ? WHERE id = ?")->execute([$token, $id]);
        bout(['ok' => true, 'token' => $token]);
    } catch (Exception $e) { bout(['error' => 'Не удалось сменить токен'], 500); }
}

if ($action === 'toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($body['id'] ?? 0);
    $active = !empty($body['active']) ? 1 : 0;
    if (!$id) bout(['error' => 'Не указан бот'], 400);
    if (!botOwned($pdo, $id, $userId, $isAdmin)) bout(['error' => 'Это не ваш бот'], 403);
    try { $pdo->prepare("UPDATE bots SET is_active = ? WHERE id = ?")->execute([$active, $id]); bout(['ok' => true]); }
    catch (Exception $e) { bout(['error' => 'Не удалось изменить'], 500); }
}

if ($action === 'scopes' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) bout(['error' => 'Не указан бот'], 400);
    if (!botOwned($pdo, $id, $userId, $isAdmin)) bout(['error' => 'Это не ваш бот'], 403);
    $scopes = normScopes($body['scopes'] ?? 'lead', $ALL_SCOPES);
    try { $pdo->prepare("UPDATE bots SET scopes = ? WHERE id = ?")->execute([$scopes, $id]); bout(['ok' => true, 'scopes' => $scopes]); }
    catch (Exception $e) { bout(['error' => 'Не удалось изменить права'], 500); }
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) bout(['error' => 'Не указан бот'], 400);
    if (!botOwned($pdo, $id, $userId, $isAdmin)) bout(['error' => 'Это не ваш бот'], 403);
    try { $pdo->prepare("DELETE FROM bots WHERE id = ?")->execute([$id]); bout(['ok' => true]); }
    catch (Exception $e) { bout(['error' => 'Не удалось удалить'], 500); }
}

if ($action === 'leads') {
    $botId = (int)($_GET['bot_id'] ?? 0);
    try {
        // Не-админ видит заявки только своих ботов
        if ($botId) {
            if (!botOwned($pdo, $botId, $userId, $isAdmin)) bout(['error' => 'Это не ваш бот'], 403);
            $st = $pdo->prepare("SELECT l.*, b.name AS bot_name FROM bot_leads l LEFT JOIN bots b ON b.id=l.bot_id WHERE l.bot_id = ? ORDER BY l.id DESC LIMIT 500"); $st->execute([$botId]);
        } else if ($isAdmin) {
            $st = $pdo->query("SELECT l.*, b.name AS bot_name FROM bot_leads l LEFT JOIN bots b ON b.id=l.bot_id ORDER BY l.id DESC LIMIT 500");
        } else {
            $st = $pdo->prepare("SELECT l.*, b.name AS bot_name FROM bot_leads l JOIN bots b ON b.id=l.bot_id WHERE b.owner_user_id = ? ORDER BY l.id DESC LIMIT 500"); $st->execute([$userId]);
        }
        bout(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { bout(['ok' => true, 'data' => []]); }
}

bout(['error' => 'Неизвестное действие'], 400);
