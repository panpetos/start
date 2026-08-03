<?php
/**
 * dev_tasks.php — очередь задач на доработку сайта («таск-доска для Клода»).
 *
 * Идея: администратор в админке пишет задачи (большой текст) → Клод по расписанию
 * (Routine в облаке) забирает новые/«на доработку», выполняет, деплоит и возвращает
 * статус со своим комментарием. Администратор потом ставит «Закрыто» или «На доработку».
 *
 * Доступ:
 *   - Администратор (по сессии, role=admin) — полный доступ из админки.
 *   - Автоматизация (Клод) — по секретному токену ?token=... (хранится вне git,
 *     в api/dev_tasks_config.php). Токен даёт право читать задачи, обновлять их
 *     статус/комментарий Клода и писать heartbeat.
 *
 * Действия:
 *   GET  ?action=list[&status=pending|all]           — список задач
 *   GET  ?action=status                              — сводка + последний/тек. запуск
 *   POST ?action=add            {title, body}         (админ)
 *   POST ?action=set-status     {id, status, admin_comment}  (админ: done|rework|new)
 *   POST ?action=claude-update  {id, status, claude_comment} (токен/админ)
 *   POST ?action=heartbeat      {state, note, processed}     (токен/админ)
 *   POST ?action=save-token     {token}                      (админ) — записать секрет
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

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS dev_tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NULL,
        body MEDIUMTEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'new',      -- new | in_progress | done | rework
        author VARCHAR(20) NOT NULL DEFAULT 'admin',    -- admin | claude
        claude_comment MEDIUMTEXT NULL,
        admin_comment MEDIUMTEXT NULL,
        attachments TEXT NULL,                          -- JSON-массив ссылок на картинки к задаче (от админа)
        admin_attachments TEXT NULL,                    -- JSON-массив ссылок на картинки к доработке (от админа)
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Для уже существующей таблицы — добавить колонки, если их нет
    try { $pdo->exec("ALTER TABLE dev_tasks ADD COLUMN attachments TEXT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE dev_tasks ADD COLUMN admin_attachments TEXT NULL"); } catch (Exception $e) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS dev_task_runs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        state VARCHAR(20) NOT NULL DEFAULT 'idle',      -- running | idle | limited | error
        note VARCHAR(500) NULL,
        processed INT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// ── Аутентификация ──────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
$uid = $_SESSION['user_id'] ?? null;
$isAdmin = false;
if ($uid) {
    try { $st = $pdo->prepare("SELECT role FROM users WHERE id=? LIMIT 1"); $st->execute([$uid]); $isAdmin = ((string)$st->fetchColumn() === 'admin'); } catch (Exception $e) {}
}
$cfgFile = __DIR__ . '/dev_tasks_config.php';
$cfg = file_exists($cfgFile) ? (include $cfgFile) : [];
$secretToken = is_array($cfg) ? (string)($cfg['token'] ?? '') : '';
$providedToken = $_GET['token'] ?? ($_SERVER['HTTP_X_DEV_TOKEN'] ?? '');
$validToken = $secretToken !== '' && hash_equals($secretToken, (string)$providedToken);

$action = $_GET['action'] ?? '';
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

function out($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

// ── Чтение ────────────────────────────────────────────────────────────────────
if ($action === 'list') {
    if (!$isAdmin && !$validToken) out(['error' => 'Требуется доступ'], 403);
    $status = $_GET['status'] ?? 'all';
    try {
        if ($status === 'pending') {
            $st = $pdo->query("SELECT * FROM dev_tasks WHERE status IN ('new','rework') ORDER BY id ASC");
        } else {
            $st = $pdo->query("SELECT * FROM dev_tasks ORDER BY (status='new' OR status='rework') DESC, id DESC LIMIT 500");
        }
        out(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { out(['ok' => true, 'data' => []]); }
}

if ($action === 'status') {
    if (!$isAdmin && !$validToken) out(['error' => 'Требуется доступ'], 403);
    $counts = ['new' => 0, 'in_progress' => 0, 'done' => 0, 'rework' => 0];
    try { foreach ($pdo->query("SELECT status, COUNT(*) c FROM dev_tasks GROUP BY status") as $r) $counts[$r['status']] = (int)$r['c']; } catch (Exception $e) {}
    $lastRun = null;
    try { $lastRun = $pdo->query("SELECT * FROM dev_task_runs ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Exception $e) {}
    out(['ok' => true, 'counts' => $counts, 'last_run' => $lastRun, 'token_configured' => $secretToken !== '']);
}

// ── Действия админа ──────────────────────────────────────────────────────────────
if ($action === 'add') {
    if (!$isAdmin) out(['error' => 'Только для администратора'], 403);
    $title = trim((string)($body['title'] ?? ''));
    $text = trim((string)($body['body'] ?? ''));
    if ($text === '') out(['error' => 'Введите текст задачи'], 400);
    $atts = $body['attachments'] ?? [];
    $attJson = (is_array($atts) && $atts) ? json_encode(array_values($atts), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
    try {
        $st = $pdo->prepare("INSERT INTO dev_tasks (title, body, attachments, status, author) VALUES (?, ?, ?, 'new', 'admin')");
        $st->execute([$title, $text, $attJson]);
        out(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    } catch (Exception $e) { out(['error' => 'Не удалось сохранить'], 500); }
}

// ── Загрузка картинки к задаче (multipart, только админ) ──────────────────────────
if ($action === 'upload') {
    if (!$isAdmin) out(['error' => 'Только для администратора'], 403);
    if (empty($_FILES['file']) || !is_array($_FILES['file'])) out(['error' => 'Файл не передан'], 400);
    $f = $_FILES['file'];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) out(['error' => 'Ошибка загрузки файла'], 400);
    if (($f['size'] ?? 0) > 20 * 1024 * 1024) out(['error' => 'Файл больше 20 МБ'], 400);
    // Разрешаем изображения и документы. Загрузка только у админа → проверяем по расширению.
    $allowExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'txt', 'rtf'];
    $ext = strtolower(pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
    if ($ext === 'jpeg') $ext = 'jpg';
    if (!in_array($ext, $allowExt, true)) out(['error' => 'Разрешены: изображения, PDF, DOC/DOCX, TXT, RTF'], 400);
    $dir = __DIR__ . '/../uploads/dev_tasks';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) out(['error' => 'Не удалось сохранить файл (проверьте права на /uploads)'], 500);
    @chmod($dir . '/' . $name, 0644);
    out(['ok' => true, 'url' => '/uploads/dev_tasks/' . $name]);
}

if ($action === 'update') {
    if (!$isAdmin) out(['error' => 'Только для администратора'], 403);
    $id = $body['id'] ?? null;
    if (!$id) out(['error' => 'Нужен id'], 400);
    $fields = []; $params = [];
    if (array_key_exists('title', $body)) { $fields[] = 'title=?'; $params[] = trim((string)$body['title']); }
    if (array_key_exists('body', $body)) { $fields[] = 'body=?'; $params[] = trim((string)$body['body']); }
    if (!$fields) out(['ok' => true]);
    $fields[] = 'updated_at=NOW()';
    $params[] = $id;
    try {
        $st = $pdo->prepare("UPDATE dev_tasks SET " . implode(', ', $fields) . " WHERE id=?");
        $st->execute($params);
        out(['ok' => true]);
    } catch (Exception $e) { out(['error' => 'Не удалось обновить'], 500); }
}

if ($action === 'set-status') {
    if (!$isAdmin) out(['error' => 'Только для администратора'], 403);
    $id = $body['id'] ?? null;
    $status = (string)($body['status'] ?? '');
    if (!$id || !in_array($status, ['new', 'in_progress', 'done', 'rework', 'closed'], true)) out(['error' => 'Нужны id и корректный статус'], 400);
    // Строим UPDATE динамически: admin_comment/admin_attachments трогаем только если переданы
    $fields = ['status=?']; $params = [$status];
    if (array_key_exists('admin_comment', $body)) { $fields[] = 'admin_comment=?'; $params[] = trim((string)$body['admin_comment']); }
    $atts = $body['admin_attachments'] ?? null;
    if (is_array($atts) && $atts) { $fields[] = 'admin_attachments=?'; $params[] = json_encode(array_values($atts), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
    $fields[] = 'updated_at=NOW()';
    $params[] = $id;
    try {
        $st = $pdo->prepare("UPDATE dev_tasks SET " . implode(', ', $fields) . " WHERE id=?");
        $st->execute($params);
        out(['ok' => true]);
    } catch (Exception $e) { out(['error' => 'Не удалось обновить'], 500); }
}

if ($action === 'save-token') {
    if (!$isAdmin) out(['error' => 'Только для администратора'], 403);
    $token = trim((string)($body['token'] ?? ''));
    if (strlen($token) < 16) out(['error' => 'Токен слишком короткий (мин. 16 символов)'], 400);
    $php = "<?php\n// Секрет автоматизации задач — НЕ в git.\nreturn " . var_export(['token' => $token], true) . ";\n";
    if (@file_put_contents($cfgFile, $php) === false) out(['error' => 'Не удалось записать dev_tasks_config.php (проверьте права на /api)'], 500);
    @chmod($cfgFile, 0640);
    out(['ok' => true]);
}

// ── Действия автоматизации (Клод) ────────────────────────────────────────────────
if ($action === 'claude-update') {
    if (!$validToken && !$isAdmin) out(['error' => 'Требуется токен'], 403);
    $id = $body['id'] ?? null;
    $status = (string)($body['status'] ?? '');
    if (!$id || !in_array($status, ['in_progress', 'done', 'rework'], true)) out(['error' => 'Нужны id и статус'], 400);
    try {
        $st = $pdo->prepare("UPDATE dev_tasks SET status=?, claude_comment=?, author='claude', updated_at=NOW() WHERE id=?");
        $st->execute([$status, trim((string)($body['claude_comment'] ?? '')), $id]);
        out(['ok' => true]);
    } catch (Exception $e) { out(['error' => 'Не удалось обновить'], 500); }
}

if ($action === 'heartbeat') {
    if (!$validToken && !$isAdmin) out(['error' => 'Требуется токен'], 403);
    $state = in_array(($body['state'] ?? 'idle'), ['running', 'idle', 'limited', 'error'], true) ? $body['state'] : 'idle';
    try {
        $st = $pdo->prepare("INSERT INTO dev_task_runs (state, note, processed) VALUES (?, ?, ?)");
        $st->execute([$state, mb_substr(trim((string)($body['note'] ?? '')), 0, 500), (int)($body['processed'] ?? 0)]);
        out(['ok' => true]);
    } catch (Exception $e) { out(['error' => 'Не удалось записать'], 500); }
}

// ── «Запустить сейчас»: админ ставит запрос, runner его забирает ──────────────────
if ($action === 'request-run') {
    if (!$isAdmin) out(['error' => 'Только для администратора'], 403);
    try {
        $pdo->prepare("INSERT INTO dev_task_runs (state, note, processed) VALUES ('requested', ?, 0)")
            ->execute([mb_substr(trim((string)($body['note'] ?? 'ручной запуск из админки')), 0, 500)]);
        out(['ok' => true]);
    } catch (Exception $e) { out(['error' => 'Не удалось поставить запрос'], 500); }
}

if ($action === 'claim-run') {
    if (!$validToken && !$isAdmin) out(['error' => 'Требуется токен'], 403);
    // Есть ли необработанный запрос на запуск?
    try {
        $last = $pdo->query("SELECT * FROM dev_task_runs ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($last && $last['state'] === 'requested') {
            $pdo->prepare("UPDATE dev_task_runs SET state='running', note=CONCAT('claimed: ', COALESCE(note,'')) WHERE id=?")->execute([$last['id']]);
            out(['ok' => true, 'run' => true]);
        }
        out(['ok' => true, 'run' => false]);
    } catch (Exception $e) { out(['ok' => true, 'run' => false]); }
}

out(['error' => 'Неизвестное действие'], 400);
