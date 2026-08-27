<?php
/**
 * ai_threads.php — переписка с ИИ-ассистентом, общая для всех устройств человека.
 *
 * ЗАЧЕМ. Диалоги с ассистентом лежали только в localStorage браузера. Спросил с
 * телефона — на компьютере этого разговора нет, и наоборот: одна и та же учётная
 * запись, а истории две разные. Здесь та же история хранится на сервере, и любое
 * устройство видит её целиком.
 *
 * ЧТО ХРАНИМ. Весь список диалогов одного человека одним куском JSON: он и так
 * собирается целиком в браузере, и отдельные таблицы под сообщения ничего бы не
 * дали — выборок по одному сообщению здесь не бывает. Слияние устройств делает
 * браузер: у каждого диалога есть время последнего изменения, побеждает свежий.
 *
 * GET  ?action=get             → {ok, payload, updated_at}
 * POST ?action=save {payload}  → {ok}
 *
 * Своё только своё: и чтение, и запись идут по user_id из сессии, чужой диалог
 * не отдаётся и не перезаписывается.
 */

header('Content-Type: application/json; charset=utf-8');
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
if (!$pdo) { http_response_code(500); echo json_encode(['error' => 'Нет подключения к БД']); exit; }

if (session_status() === PHP_SESSION_NONE) session_start();
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_threads (
        user_id VARCHAR(64) NOT NULL,
        payload MEDIUMTEXT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

$action = $_GET['action'] ?? '';
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

if ($action === 'get') {
    try {
        $st = $pdo->prepare("SELECT payload, updated_at FROM ai_threads WHERE user_id = ? LIMIT 1");
        $st->execute([$userId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        echo json_encode([
            'ok' => true,
            'payload' => $r ? (string)$r['payload'] : '',
            'updated_at' => $r ? (string)$r['updated_at'] : '',
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        // Пустая история лучше ошибки: браузер просто останется на своей местной копии.
        echo json_encode(['ok' => true, 'payload' => '', 'updated_at' => '']);
    }
    exit;
}

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = (string)($body['payload'] ?? '');
    // Потолок на всякий случай: браузер и так режет список до двадцати диалогов,
    // но полагаться на это одному серверу нельзя.
    if (strlen($payload) > 4 * 1024 * 1024) {
        http_response_code(413);
        echo json_encode(['error' => 'Слишком большая история']);
        exit;
    }
    // Кладём только валидный JSON: битая строка сломала бы чтение на другом устройстве.
    if ($payload !== '' && json_decode($payload, true) === null) {
        http_response_code(400);
        echo json_encode(['error' => 'История не разобралась']);
        exit;
    }
    try {
        $pdo->prepare("INSERT INTO ai_threads (user_id, payload, updated_at) VALUES (?, ?, NOW())
                       ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_at = NOW()")
            ->execute([$userId, $payload]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Не удалось сохранить историю']);
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Неизвестное действие']);
