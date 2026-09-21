<?php
/**
 * status.php — свой статус («на сессии», «отвечу после 18:00») и автоответ.
 *
 * Статус виден собеседникам в шапке чата. Автоответ (если включён) уходит тому,
 * кто написал, — один раз в несколько часов, чтобы человек не ждал молча. Само
 * срабатывание автоответа висит на push.php?action=poke (его дёргает отправитель
 * сразу после сообщения) — ядро сообщений серверное, туда не влезть.
 *
 * Работает как библиотека (функции ниже) и как эндпоинт при прямом открытии:
 *   GET  ?action=get[&user_id=X]   — статус (свой — целиком; чужой — только текст)
 *   POST ?action=set {status_text, auto_reply, auto_reply_on}
 */

if (!function_exists('status_ensure_table')) {
    function status_ensure_table(PDO $pdo) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_status (
            user_id VARCHAR(64) NOT NULL PRIMARY KEY,
            status_text VARCHAR(120) NULL,
            auto_reply VARCHAR(500) NULL,
            auto_reply_on TINYINT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL
        ) DEFAULT CHARSET=utf8mb4");
        // Две свои фразы для кнопок быстрого ответа в уведомлении.
        try { $pdo->exec("ALTER TABLE user_status ADD COLUMN quick1 VARCHAR(60) NULL"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE user_status ADD COLUMN quick2 VARCHAR(60) NULL"); } catch (Exception $e) {}
    }
    /** Строка статуса для внутреннего использования (напр. автоответ в push poke). */
    function status_get_row(PDO $pdo, $id) {
        try {
            $st = $pdo->prepare("SELECT * FROM user_status WHERE user_id = ? LIMIT 1");
            $st->execute([$id]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) { return null; }
    }
    /** Пара быстрых ответов пользователя для пуша (с запасными значениями). */
    function status_quick_replies(PDO $pdo, $id) {
        $row = status_get_row($pdo, $id);
        $q1 = $row ? trim((string)($row['quick1'] ?? '')) : '';
        $q2 = $row ? trim((string)($row['quick2'] ?? '')) : '';
        if ($q1 === '') $q1 = '👍 Ок';
        if ($q2 === '') $q2 = 'Отвечу позже 🙏';
        return [$q1, $q2];
    }
}

// ── Режим эндпоинта (только при прямом открытии, не при include) ─────────────
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
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
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $stOut = function ($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };
    try { status_ensure_table($pdo); } catch (Exception $e) {}

    $action = $_GET['action'] ?? '';
    $body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

    if ($action === 'get') {
        $target = trim((string)($_GET['user_id'] ?? ''));
        $self = ($target === '' || $target === (string)$userId);
        if ($self && !$userId) $stOut(['ok' => false, 'error' => 'Требуется авторизация'], 401);
        $row = status_get_row($pdo, $self ? $userId : $target);
        if (!$row) $stOut(['ok' => true, 'status_text' => '', 'auto_reply' => '', 'auto_reply_on' => 0]);
        if ($self) {
            $stOut(['ok' => true, 'status_text' => (string)($row['status_text'] ?? ''),
                    'auto_reply' => (string)($row['auto_reply'] ?? ''), 'auto_reply_on' => (int)($row['auto_reply_on'] ?? 0),
                    'quick1' => (string)($row['quick1'] ?? ''), 'quick2' => (string)($row['quick2'] ?? '')]);
        }
        $stOut(['ok' => true, 'status_text' => (string)($row['status_text'] ?? '')]);   // чужим — только текст
    }

    if (!$userId) $stOut(['ok' => false, 'error' => 'Требуется авторизация'], 401);

    if ($action === 'set' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $statusText = mb_substr(trim((string)($body['status_text'] ?? '')), 0, 120);
        $autoReply = mb_substr(trim((string)($body['auto_reply'] ?? '')), 0, 500);
        $autoOn = !empty($body['auto_reply_on']) ? 1 : 0;
        $quick1 = mb_substr(trim((string)($body['quick1'] ?? '')), 0, 60);
        $quick2 = mb_substr(trim((string)($body['quick2'] ?? '')), 0, 60);
        try {
            $pdo->prepare("INSERT INTO user_status (user_id, status_text, auto_reply, auto_reply_on, quick1, quick2, updated_at)
                           VALUES (?, ?, ?, ?, ?, ?, NOW())
                           ON DUPLICATE KEY UPDATE status_text = VALUES(status_text), auto_reply = VALUES(auto_reply),
                                                   auto_reply_on = VALUES(auto_reply_on), quick1 = VALUES(quick1),
                                                   quick2 = VALUES(quick2), updated_at = NOW()")
                ->execute([$userId, $statusText !== '' ? $statusText : null, $autoReply !== '' ? $autoReply : null, $autoOn,
                           $quick1 !== '' ? $quick1 : null, $quick2 !== '' ? $quick2 : null]);
            $stOut(['ok' => true]);
        } catch (Exception $e) { $stOut(['ok' => false, 'error' => 'Не удалось сохранить'], 500); }
    }

    $stOut(['ok' => false, 'error' => 'Неизвестное действие'], 400);
}
