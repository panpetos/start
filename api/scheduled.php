<?php
/**
 * scheduled.php — отложенная отправка сообщений («отправить позже», как в Telegram).
 *
 * Работает и в личных переписках, и в группах, и в «Избранном».
 *
 * Почему доставка отдельным проходом, а не «сервер сам в нужную секунду»:
 * личные сообщения пишутся через api/messages.php, которого нет в репозитории (живёт
 * только на сервере), и cron на хостинге может быть не настроен. Поэтому берём тот же
 * приём, что и notify_dispatch.php: есть ?action=run (cron/админ) и ?action=tick —
 * «сам собой», когда кто-то открыл сайт, не чаще раза в TICK_MIN_SEC секунд на всю
 * платформу. Минута-другая задержки для отложенного сообщения некритична, зато оно
 * уходит без всякого cron.
 *
 * Колонки таблицы messages подбираем на месте через SHOW COLUMNS: схему этой таблицы
 * создаёт messages.php, которого мы не видим, и жёстко зашитый INSERT сломался бы на
 * первой же несовпавшей колонке.
 *
 * GET  ?action=list[&chat_key=X]        — мои запланированные (ожидающие отправки)
 * POST ?action=create {chat_key, content, send_at_ts}
 * POST ?action=cancel {id}
 * POST ?action=reschedule {id, send_at_ts}
 * GET/POST ?action=tick                 — доставить назревшее (троттлинг)
 * GET/POST ?action=run                  — то же без троттлинга (админ)
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

function out($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

const TICK_MIN_SEC = 20;      // не чаще раза в 20 секунд на всю платформу
const MAX_PENDING   = 100;    // столько отложенных на человека — с запасом
const MAX_AHEAD_DAYS = 366;   // дальше чем на год не планируем

$schemaError = '';
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS scheduled_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(64) NOT NULL,
        chat_key VARCHAR(160) NOT NULL,
        content TEXT NULL,
        send_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        sent_at DATETIME NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'pending',
        error VARCHAR(300) NULL,
        INDEX idx_due (status, send_at),
        INDEX idx_user (user_id, status)
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS scheduled_state (
        k VARCHAR(64) NOT NULL PRIMARY KEY,
        v VARCHAR(255) NULL
    ) DEFAULT CHARSET=utf8mb4");
    psy_align_collation($pdo, ['scheduled_messages', 'scheduled_state']);
} catch (Exception $e) {
    $schemaError = $e->getMessage();
}

function stateGet($pdo, $k) {
    try {
        $st = $pdo->prepare("SELECT v FROM scheduled_state WHERE k = ?");
        $st->execute([$k]);
        return $st->fetchColumn() ?: null;
    } catch (Exception $e) { return null; }
}
function stateSet($pdo, $k, $v) {
    try {
        $pdo->prepare("INSERT INTO scheduled_state (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")
            ->execute([$k, $v]);
    } catch (Exception $e) {}
}

/** Колонки таблицы: схему messages создаёт не этот файл, подбираем поля по факту. */
function tableCols($pdo, $t) {
    static $cache = [];
    if (array_key_exists($t, $cache)) return $cache[$t];
    try {
        $rows = $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) $out[$r['Field']] = $r;
        return $cache[$t] = $out;
    } catch (Exception $e) { return $cache[$t] = []; }
}

function uuidv4() {
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

/** Собрать INSERT только из тех колонок, которые в таблице действительно есть. */
function insertRow($pdo, $table, $data) {
    $cols = tableCols($pdo, $table);
    if (!$cols) throw new Exception("нет таблицы $table");
    $use = [];
    foreach ($data as $k => $v) if (isset($cols[$k])) $use[$k] = $v;
    // Первичный ключ строкой и без auto_increment — значит идентификатор наш (UUID)
    if (isset($cols['id']) && !isset($use['id'])) {
        $extra = strtolower($cols['id']['Extra'] ?? '');
        $type  = strtolower($cols['id']['Type'] ?? '');
        if (strpos($extra, 'auto_increment') === false &&
            (strpos($type, 'char') !== false || strpos($type, 'text') !== false)) {
            $use['id'] = uuidv4();
        }
    }
    if (!$use) throw new Exception("не совпала ни одна колонка $table");
    $names = array_keys($use);
    $sql = "INSERT INTO `$table` (`" . implode('`, `', $names) . "`) VALUES ("
         . implode(', ', array_fill(0, count($names), '?')) . ")";
    $pdo->prepare($sql)->execute(array_values($use));
    return $use['id'] ?? $pdo->lastInsertId();
}

/**
 * Доставить одно отложенное сообщение туда, куда оно адресовано.
 * chat_key: __fav__ | group:<id> | <user_id>
 */
function deliverOne($pdo, $row) {
    $chatKey = (string)$row['chat_key'];
    $userId  = (string)$row['user_id'];
    $content = (string)$row['content'];
    $now     = date('Y-m-d H:i:s');

    if ($chatKey === '__fav__') {
        insertRow($pdo, 'favorite_messages',
            ['user_id' => $userId, 'content' => $content, 'created_at' => $now]);
        return true;
    }
    if (strpos($chatKey, 'group:') === 0) {
        $gid = (int)substr($chatKey, 6);
        if ($gid <= 0) throw new Exception('плохой номер группы');
        // всё ещё ли человек в группе — за время ожидания его могли исключить
        $st = $pdo->prepare("SELECT 1 FROM chat_group_members WHERE group_id = ? AND user_id = ?");
        $st->execute([$gid, $userId]);
        if (!$st->fetchColumn()) throw new Exception('вы больше не участник группы');
        insertRow($pdo, 'chat_group_messages',
            ['group_id' => $gid, 'sender_id' => $userId, 'content' => $content, 'created_at' => $now]);
        return true;
    }
    if (strpos($chatKey, 'channel:') === 0 || strpos($chatKey, 'support:') === 0) {
        throw new Exception('в этот раздел отложенная отправка пока не поддерживается');
    }
    // личная переписка: chat_key — это id собеседника
    insertRow($pdo, 'messages', [
        'sender_id'   => $userId,
        'receiver_id' => $chatKey,
        'content'     => $content,
        'created_at'  => $now,
        'is_read'     => 0,
    ]);
    return true;
}

/** Разослать всё, чему подошёл срок. Возвращает счётчики. */
function runDue($pdo, $limit = 50) {
    $sent = 0; $failed = 0;
    $st = $pdo->prepare("SELECT * FROM scheduled_messages
                         WHERE status = 'pending' AND send_at <= NOW()
                         ORDER BY send_at ASC LIMIT " . (int)$limit);
    $st->execute();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        // помечаем ДО отправки: если что-то упадёт, письмо не уйдёт дважды
        $lock = $pdo->prepare("UPDATE scheduled_messages SET status = 'sending' WHERE id = ? AND status = 'pending'");
        $lock->execute([$row['id']]);
        if (!$lock->rowCount()) continue;      // кто-то уже взял эту строку
        try {
            deliverOne($pdo, $row);
            $pdo->prepare("UPDATE scheduled_messages SET status = 'sent', sent_at = NOW(), error = NULL WHERE id = ?")
                ->execute([$row['id']]);
            $sent++;
        } catch (Exception $e) {
            $pdo->prepare("UPDATE scheduled_messages SET status = 'failed', error = ? WHERE id = ?")
                ->execute([mb_substr($e->getMessage(), 0, 300), $row['id']]);
            $failed++;
        }
    }
    return ['sent' => $sent, 'failed' => $failed];
}

$action = $_GET['action'] ?? '';
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

try {
    if ($action === 'tick' || $action === 'run') {
        if (!$userId) out(['ok' => false, 'error' => 'Требуется авторизация'], 401);
        if ($schemaError) out(['ok' => false, 'error' => 'Отложенная отправка недоступна: ' . $schemaError], 500);
        if ($action === 'tick') {
            $last = stateGet($pdo, 'last_tick');
            if ($last && strtotime($last) > time() - TICK_MIN_SEC) out(['ok' => true, 'skipped' => 'рано']);
        }
        stateSet($pdo, 'last_tick', date('Y-m-d H:i:s'));   // метку ставим ДО прохода
        out(['ok' => true] + runDue($pdo));
    }

    if (!$userId) out(['ok' => false, 'error' => 'Требуется авторизация'], 401);
    if ($schemaError) out(['ok' => false, 'error' => 'Отложенная отправка недоступна: ' . $schemaError], 500);

    if ($action === 'list') {
        runDue($pdo, 20);          // заодно разошлём назревшее — список сразу честный
        $chatKey = (string)($_GET['chat_key'] ?? '');
        $sql = "SELECT id, chat_key, content, send_at, status, error FROM scheduled_messages
                WHERE user_id = ? AND status IN ('pending','sending','failed')";
        $args = [$userId];
        if ($chatKey !== '') { $sql .= " AND chat_key = ?"; $args[] = $chatKey; }
        $sql .= " ORDER BY send_at ASC LIMIT 200";
        $st = $pdo->prepare($sql);
        $st->execute($args);
        out(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'now' => date('Y-m-d H:i:s')]);
    }

    if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $chatKey = trim((string)($body['chat_key'] ?? ''));
        $content = trim((string)($body['content'] ?? ''));
        $ts      = (int)($body['send_at_ts'] ?? 0);
        if ($chatKey === '' || strlen($chatKey) > 160) out(['ok' => false, 'error' => 'Не указан чат'], 400);
        if ($content === '') out(['ok' => false, 'error' => 'Пустое сообщение'], 400);
        if (mb_strlen($content) > 20000) out(['ok' => false, 'error' => 'Слишком длинное сообщение'], 400);
        if ($ts <= 0) out(['ok' => false, 'error' => 'Не указано время'], 400);
        if ($ts < time() + 20) out(['ok' => false, 'error' => 'Выберите время хотя бы на минуту вперёд'], 400);
        if ($ts > time() + MAX_AHEAD_DAYS * 86400) out(['ok' => false, 'error' => 'Дальше чем на год запланировать нельзя'], 400);
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM scheduled_messages WHERE user_id = ? AND status = 'pending'");
        $cnt->execute([$userId]);
        if ((int)$cnt->fetchColumn() >= MAX_PENDING)
            out(['ok' => false, 'error' => 'Больше ' . MAX_PENDING . ' отложенных сообщений одновременно не поддерживается'], 400);
        $pdo->prepare("INSERT INTO scheduled_messages (user_id, chat_key, content, send_at, created_at, status)
                       VALUES (?, ?, ?, FROM_UNIXTIME(?), NOW(), 'pending')")
            ->execute([$userId, $chatKey, $content, $ts]);
        out(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'send_at' => date('Y-m-d H:i:s', $ts)]);
    }

    if ($action === 'cancel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($body['id'] ?? 0);
        if ($id <= 0) out(['ok' => false, 'error' => 'Не указано сообщение'], 400);
        // 'sending' не трогаем: оно прямо сейчас уходит
        $st = $pdo->prepare("DELETE FROM scheduled_messages WHERE id = ? AND user_id = ? AND status IN ('pending','failed')");
        $st->execute([$id, $userId]);
        if (!$st->rowCount()) out(['ok' => false, 'error' => 'Это сообщение уже отправлено'], 400);
        out(['ok' => true]);
    }

    if ($action === 'reschedule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($body['id'] ?? 0);
        $ts = (int)($body['send_at_ts'] ?? 0);
        if ($id <= 0 || $ts <= 0) out(['ok' => false, 'error' => 'Некорректный запрос'], 400);
        if ($ts < time() + 20) out(['ok' => false, 'error' => 'Выберите время хотя бы на минуту вперёд'], 400);
        if ($ts > time() + MAX_AHEAD_DAYS * 86400) out(['ok' => false, 'error' => 'Дальше чем на год запланировать нельзя'], 400);
        $st = $pdo->prepare("UPDATE scheduled_messages SET send_at = FROM_UNIXTIME(?), status = 'pending', error = NULL
                             WHERE id = ? AND user_id = ? AND status IN ('pending','failed')");
        $st->execute([$ts, $id, $userId]);
        if (!$st->rowCount()) out(['ok' => false, 'error' => 'Это сообщение уже отправлено'], 400);
        out(['ok' => true, 'send_at' => date('Y-m-d H:i:s', $ts)]);
    }

    out(['ok' => false, 'error' => 'Неизвестное действие'], 400);
} catch (Exception $e) {
    out(['ok' => false, 'error' => $e->getMessage()], 500);
}
