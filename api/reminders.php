<?php
/**
 * reminders.php — «напомнить мне об этом сообщении».
 *
 * Человек выбирает сообщение и время — в срок ему приходит пуш со ссылкой на чат.
 * Доставка — тем же приёмом, что у отложенных сообщений (scheduled.php): есть
 * ?action=tick (его дёргают клиенты) и подхват в часто опрашиваемом stories.php,
 * так что серверный cron не обязателен. Когда напоминание «созрело» — помечаем
 * ready и шлём пустой пуш; сервис-воркер спросит push.php?action=pending, а тот
 * (через reminders_pending_for) покажет напоминание.
 *
 * Работает и как библиотека (функции ниже), и как эндпоинт при прямом открытии:
 *   GET  ?action=list                                  — мои активные напоминания
 *   POST ?action=create {peer, message_id?, preview, remind_at_ts}
 *   POST ?action=delete {id}
 *   GET/POST ?action=tick                              — разослать назревшие
 */

if (!function_exists('reminders_ensure_table')) {
    function reminders_ensure_table(PDO $pdo) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS reminders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(64) NOT NULL,
            peer_key VARCHAR(120) NULL,
            message_id VARCHAR(64) NULL,
            preview VARCHAR(300) NULL,
            remind_at DATETIME NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL,
            INDEX idx_due (status, remind_at),
            INDEX idx_user (user_id, status)
        ) DEFAULT CHARSET=utf8mb4");
    }

    /**
     * Разослать назревшие напоминания (pending с прошедшим сроком): помечаем ready
     * и будим владельца пустым пушем. Идемпотентно — то же второй раз не отправит.
     */
    function reminders_tick(PDO $pdo) {
        try {
            $due = $pdo->query("SELECT id, user_id FROM reminders
                                WHERE status = 'pending' AND remind_at <= NOW()
                                ORDER BY remind_at ASC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { return 0; }   // таблицы ещё нет — напоминаний тоже
        if (!$due) return 0;
        if (!function_exists('push_send_to_user') && @file_exists(__DIR__ . '/push.php')) {
            $GLOBALS['__PUSH_LIB_ONLY'] = true;
            try { require_once __DIR__ . '/push.php'; } catch (\Throwable $e) {}
        }
        $mark = $pdo->prepare("UPDATE reminders SET status = 'ready' WHERE id = ? AND status = 'pending'");
        $n = 0;
        foreach ($due as $r) {
            try { $mark->execute([$r['id']]); if ($mark->rowCount() < 1) continue; } catch (Exception $e) { continue; }
            $n++;
            if (function_exists('push_send_to_user')) { try { push_send_to_user($pdo, $r['user_id']); } catch (\Throwable $e) {} }
        }
        return $n;
    }

    /**
     * Что показать этому человеку в пуше: самое раннее «созревшее» напоминание.
     * Помечаем shown, чтобы не показать дважды. Возвращает [title, body, url] или null.
     */
    function reminders_pending_for(PDO $pdo, $userId) {
        if (!$userId) return null;
        try {
            $st = $pdo->prepare("SELECT id, peer_key, preview FROM reminders
                                 WHERE user_id = ? AND status = 'ready' ORDER BY remind_at ASC LIMIT 1");
            $st->execute([$userId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            $upd = $pdo->prepare("UPDATE reminders SET status = 'shown' WHERE id = ? AND status = 'ready'");
            $upd->execute([$r['id']]);
            if ($upd->rowCount() < 1) return null;   // кто-то уже показал
            $bodyTxt = trim((string)$r['preview']);
            if ($bodyTxt === '') $bodyTxt = 'Вы просили напомнить об этом сообщении';
            $url = !empty($r['peer_key']) ? '/chat.html?open=' . rawurlencode((string)$r['peer_key']) : '/chat.html';
            return ['title' => '⏰ Напоминание', 'body' => $bodyTxt, 'url' => $url];
        } catch (Exception $e) { return null; }
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

    $remOut = function ($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };
    try { reminders_ensure_table($pdo); } catch (Exception $e) {}

    $action = $_GET['action'] ?? '';
    $body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

    // tick — без авторизации (рассылку двигает любой заход, как у scheduled.php).
    if ($action === 'tick') { $remOut(['ok' => true, 'sent' => reminders_tick($pdo)]); }

    if (!$userId) $remOut(['ok' => false, 'error' => 'Требуется авторизация'], 401);

    if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $peer = mb_substr(trim((string)($body['peer'] ?? '')), 0, 120);
        $messageId = mb_substr(trim((string)($body['message_id'] ?? '')), 0, 64);
        $preview = trim((string)($body['preview'] ?? ''));
        $preview = $preview !== '' ? mb_substr($preview, 0, 300) : null;
        $ts = (int)($body['remind_at_ts'] ?? 0);
        if ($ts < time() + 30) $remOut(['ok' => false, 'error' => 'Выберите время в будущем'], 400);
        if ($ts > time() + 400 * 86400) $remOut(['ok' => false, 'error' => 'Слишком далеко'], 400);
        try {
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM reminders WHERE user_id = ? AND status IN ('pending','ready')");
            $cnt->execute([$userId]);
            if ((int)$cnt->fetchColumn() >= 200) $remOut(['ok' => false, 'error' => 'Слишком много активных напоминаний'], 400);
        } catch (Exception $e) {}
        try {
            $st = $pdo->prepare("INSERT INTO reminders (user_id, peer_key, message_id, preview, remind_at, status, created_at)
                                 VALUES (?, ?, ?, ?, FROM_UNIXTIME(?), 'pending', NOW())");
            $st->execute([$userId, $peer !== '' ? $peer : null, $messageId !== '' ? $messageId : null, $preview, $ts]);
            $remOut(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
        } catch (Exception $e) { $remOut(['ok' => false, 'error' => 'Не удалось сохранить напоминание'], 500); }
    }

    if ($action === 'list') {
        try {
            $st = $pdo->prepare("SELECT id, peer_key, preview, UNIX_TIMESTAMP(remind_at) AS remind_ts, status
                                 FROM reminders WHERE user_id = ? AND status IN ('pending','ready')
                                 ORDER BY remind_at ASC LIMIT 200");
            $st->execute([$userId]);
            $remOut(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC) ?: []]);
        } catch (Exception $e) { $remOut(['ok' => true, 'data' => []]); }
    }

    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($body['id'] ?? 0);
        if ($id <= 0) $remOut(['ok' => false, 'error' => 'Некорректный id'], 400);
        try { $pdo->prepare("DELETE FROM reminders WHERE id = ? AND user_id = ?")->execute([$id, $userId]); $remOut(['ok' => true]); }
        catch (Exception $e) { $remOut(['ok' => false, 'error' => 'Не удалось удалить'], 500); }
    }

    $remOut(['ok' => false, 'error' => 'Неизвестное действие'], 400);
}
