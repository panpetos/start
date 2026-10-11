<?php
/**
 * welcome.php — автоматическое приветствие новому пользователю в чате.
 *
 * ЗАЧЕМ. В notifications.php давно лежит шаблон client.welcome, но его никто и
 * никогда не отправлял: по всему коду на этот ключ нет ни одной ссылки. Из-за
 * этого новый человек заходил в чат, а там пусто — и у админа в диалоге с ним
 * тоже пусто, хотя приветствие предполагалось.
 *
 * Регистрация живёт в auth.php, которого в репозитории нет, поэтому цепляемся
 * не к регистрации, а к первому открытию чата: клиент зовёт ?action=ensure,
 * и если человек ещё ни с кем не переписывался — пишем ему приветствие от имени
 * администратора. Диалог появляется сразу у обоих.
 *
 * ОСТОРОЖНО, ПОЧЕМУ ИМЕННО «НИ ОДНОГО СООБЩЕНИЯ». Иначе приветствие свалилось бы
 * всем действующим пользователям разом. Условие «за всё время нет ни одного
 * сообщения ни в одну сторону» отсекает всех, кто уже пользуется чатом.
 *
 * POST ?action=ensure → { ok, sent: true|false, reason }
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/settings_lib.php';
if (!function_exists('getDB') && !function_exists('getDbConnection') && !function_exists('getPDO')) {
    require_once __DIR__ . '/db.php';
}
require_once __DIR__ . '/rtc_lib.php';   // rtcSendDm: уже умеет и auto_increment, и is_read

$pdo = function_exists('getDB') ? getDB()
     : (function_exists('getDbConnection') ? getDbConnection()
     : (function_exists('getPDO') ? getPDO() : null));
if (!$pdo) { http_response_code(500); echo json_encode(['error' => 'Нет подключения к БД']); exit; }

if (session_status() === PHP_SESSION_NONE) session_start();
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Требуется авторизация']); exit; }

function wcOut($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

const WC_DEFAULT =
    "Здравствуйте! 👋\n\n" .
    "Добро пожаловать на psytalk.pro. Здесь можно подобрать психолога и записаться на сессию — " .
    "прямо в этом чате, в разделе «Психологи».\n\n" .
    "Если что-то непонятно или нужна помощь с выбором — просто напишите в ответ, мы на связи.";

/** Таблица-отметка: кому приветствие уже отправляли. Чтобы не слать дважды. */
function wcEnsureTable(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS welcome_sent (
        user_id VARCHAR(64) NOT NULL PRIMARY KEY,
        sent_at DATETIME NOT NULL
    ) DEFAULT CHARSET=utf8mb4");
}

/** id администратора, от чьего имени пишем. Берём самого раннего — он же владелец. */
function wcAdminId(PDO $pdo) {
    try {
        $st = $pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY created_at ASC, id ASC LIMIT 1");
        $id = $st ? $st->fetchColumn() : false;
        return $id !== false ? (string)$id : null;
    } catch (Exception $e) {
        // created_at может отсутствовать — тогда просто любого администратора
        try {
            $st = $pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
            $id = $st ? $st->fetchColumn() : false;
            return $id !== false ? (string)$id : null;
        } catch (Exception $e2) { return null; }
    }
}

/** Переписывался ли человек хоть раз — в любую сторону. */
function wcHasAnyMessage(PDO $pdo, $userId) {
    try {
        $st = $pdo->prepare("SELECT 1 FROM messages WHERE sender_id = ? OR receiver_id = ? LIMIT 1");
        $st->execute([$userId, $userId]);
        return (bool)$st->fetchColumn();
    } catch (Exception $e) {
        return true;   // не смогли проверить — молчим, лишнее письмо хуже отсутствующего
    }
}

$action = $_GET['action'] ?? '';

if ($action === 'ensure') {
    try { wcEnsureTable($pdo); } catch (Exception $e) { wcOut(['ok' => true, 'sent' => false, 'reason' => 'no-table']); }

    // Администратору приветствие от самого себя не нужно
    try {
        $st = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        $st->execute([$userId]);
        if ((string)$st->fetchColumn() === 'admin') wcOut(['ok' => true, 'sent' => false, 'reason' => 'admin']);
    } catch (Exception $e) {}

    try {
        $st = $pdo->prepare("SELECT 1 FROM welcome_sent WHERE user_id = ? LIMIT 1");
        $st->execute([$userId]);
        if ($st->fetchColumn()) wcOut(['ok' => true, 'sent' => false, 'reason' => 'already']);
    } catch (Exception $e) {}

    if (wcHasAnyMessage($pdo, $userId)) {
        // Уже переписывается — не новичок. Отметим, чтобы больше не проверять.
        try { $pdo->prepare("INSERT IGNORE INTO welcome_sent (user_id, sent_at) VALUES (?, NOW())")->execute([$userId]); }
        catch (Exception $e) {}
        wcOut(['ok' => true, 'sent' => false, 'reason' => 'has-messages']);
    }

    $adminId = wcAdminId($pdo);
    if (!$adminId || (string)$adminId === (string)$userId) {
        wcOut(['ok' => true, 'sent' => false, 'reason' => 'no-admin']);
    }

    // Текст можно поменять в админке (настройка welcome_message)
    $text = trim(psySetting($pdo, 'welcome_message', ''));
    if ($text === '') $text = WC_DEFAULT;

    // Непрочитанным: человек должен увидеть значок нового сообщения
    rtcSendDm($pdo, $adminId, $userId, $text, false);

    try { $pdo->prepare("INSERT IGNORE INTO welcome_sent (user_id, sent_at) VALUES (?, NOW())")->execute([$userId]); }
    catch (Exception $e) {}

    wcOut(['ok' => true, 'sent' => true]);
}

wcOut(['error' => 'Неизвестное действие'], 400);
