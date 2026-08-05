<?php
/**
 * ai_chat.php — ИИ-чат (прокси к OpenRouter).
 *
 * POST ?action=chat   {messages:[{role,content}], model?}  → SSE-поток
 * GET  ?action=models                                      → список моделей
 *
 * Доступ: психологи и админы.
 */

header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';
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
if (!in_array($role, ['psychologist','admin'])) {
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(['error'=>'Доступ только для психологов и админов']);
    exit;
}

$cfgFile = __DIR__ . '/ai_chat_config.php';
if (!file_exists($cfgFile)) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error'=>'Конфигурация ИИ-чата не найдена. Создайте api/ai_chat_config.php из образца.']);
    exit;
}
$cfg = require $cfgFile;
$apiKey = $cfg['api_key'] ?? '';
$models = $cfg['models'] ?? [];

$action = $_GET['action'] ?? '';

if ($action === 'models') {
    header('Content-Type: application/json; charset=utf-8');
    $list = [];
    foreach ($models as $id => $name) {
        $list[] = ['id' => $id, 'name' => $name];
    }
    echo json_encode(['ok' => true, 'models' => $list]);
    exit;
}

if ($action === 'chat') {
    $input = json_decode(file_get_contents('php://input'), true);
    $messages = $input['messages'] ?? [];
    $model = $input['model'] ?? array_key_first($models);

    if (!$messages || !is_array($messages)) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Нет сообщений']);
        exit;
    }

    if (!array_key_exists($model, $models)) {
        $model = array_key_first($models);
    }

    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    $payload = json_encode([
        'model'    => $model,
        'messages' => $messages,
        'stream'   => true,
        'max_tokens' => 4096,
    ]);

    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'HTTP-Referer: https://psytalk.pro',
            'X-Title: PsyTalk AI Chat',
        ],
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
    }
    exit;
}

header('Content-Type: application/json');
echo json_encode(['error' => 'Неизвестное действие']);
