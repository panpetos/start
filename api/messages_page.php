<?php
/**
 * messages_page.php — переписка порциями.
 *
 * ЗАЧЕМ. Раньше чат забирал ВСЕ сообщения переписки одним запросом и не умел
 * подгружать старые. Пока сообщений сотни, это незаметно; на нескольких тысячах в
 * одной переписке каждое открытие чата тянуло бы мегабайт и заметно тормозило —
 * причём на каждом опросе, а не только при открытии.
 *
 * Отправка сообщений осталась там же, где была (серверный messages.php, его в
 * репозитории нет). Здесь только ЧТЕНИЕ и только своей переписки — поэтому файл и
 * появился отдельно, ничего чужого он не трогает.
 *
 * GET ?with=<id собеседника>&limit=50[&before=<курсор>]
 *   → { ok, data: [сообщения от старых к новым], has_more, cursor }
 *
 * cursor — метка самого старого из отданных сообщений. Передайте её в before,
 * чтобы получить предыдущую порцию. Метка составная («время|id»), потому что
 * идентификатор сообщения в разных установках то числовой, то строковый, и
 * опираться только на него нельзя.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/config.php';
if (!function_exists('getDB') && !function_exists('getDbConnection') && !function_exists('getPDO')) {
    require_once __DIR__ . '/db.php';
}
$pdo = function_exists('getDB') ? getDB()
     : (function_exists('getDbConnection') ? getDbConnection()
     : (function_exists('getPDO') ? getPDO() : null));
if (!$pdo) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Нет подключения к БД']); exit; }

if (session_status() === PHP_SESSION_NONE) session_start();
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Требуется авторизация']); exit; }

function mpOut($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

/**
 * Список колонок таблицы сообщений — набор полей у разных установок разный.
 *
 * ВАЖНО, почему не «SHOW COLUMNS FROM messages LIKE ?» через prepare, как было
 * сначала: подстановка в SHOW проходит не везде, и на боевой базе такой запрос
 * падал. Ошибка глушилась catch-ем, функция отвечала «колонки нет» — и из выборки
 * молча пропадали attachment_url/type/name. Внешне это выглядело так, будто фото
 * и файлы перестали приходить: в переписке оставалась одна служебная подпись.
 * Теперь спрашиваем список целиком, без подстановок, и один раз на запрос.
 */
function mpColumns(PDO $pdo) {
    static $cols = null;
    if ($cols !== null) return $cols;
    $cols = [];
    try {
        foreach ($pdo->query("SHOW COLUMNS FROM messages")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $name = $r['Field'] ?? ($r['field'] ?? null);
            if ($name !== null) $cols[] = (string)$name;
        }
    } catch (Exception $e) { $cols = []; }
    return $cols;
}

function mpHasColumn(PDO $pdo, $col) {
    return in_array($col, mpColumns($pdo), true);
}

$peer = trim((string)($_GET['with'] ?? ''));
if ($peer === '') mpOut(['ok' => false, 'error' => 'Не указан собеседник'], 400);

// ── Почему переписка открылась пустой ────────────────────────────────────────
// Своё и только своё: считаем сообщения между мной и этим собеседником и смотрим,
// в каком виде там лежат идентификаторы. Именно из-за их вида переписка и может
// «пропасть»: если в базе id собеседника записан иначе, чем приходит из списка
// диалогов (иной регистр, пробел по краям), точное сравнение ничего не находит,
// хотя список диалогов ту же переписку показывает. Текстов сообщений не отдаём.
if (!empty($_GET['diag'])) {
    $peerRaw = trim((string)($_GET['with'] ?? ''));
    $d = ['спрошенный_id' => $peerRaw, 'мой_id' => (string)$userId];
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM messages
                              WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)");
        $st->execute([$userId, $peerRaw, $peerRaw, $userId]);
        $d['точное_совпадение'] = (int)$st->fetchColumn();

        $st = $pdo->prepare("SELECT COUNT(*) FROM messages
                              WHERE (TRIM(LOWER(sender_id)) = TRIM(LOWER(?)) AND TRIM(LOWER(receiver_id)) = TRIM(LOWER(?)))
                                 OR (TRIM(LOWER(sender_id)) = TRIM(LOWER(?)) AND TRIM(LOWER(receiver_id)) = TRIM(LOWER(?)))");
        $st->execute([$userId, $peerRaw, $peerRaw, $userId]);
        $d['без_учёта_регистра_и_пробелов'] = (int)$st->fetchColumn();

        $st = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE sender_id = ? OR receiver_id = ?");
        $st->execute([$peerRaw, $peerRaw]);
        $d['всего_у_собеседника'] = (int)$st->fetchColumn();

        $st = $pdo->prepare("SELECT SUM(attachment_url IS NOT NULL AND attachment_url <> '') AS с_вложением,
                                    SUM(content IS NULL OR content = '') AS без_текста
                               FROM messages
                              WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)");
        $st->execute([$userId, $peerRaw, $peerRaw, $userId]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $d['из_них_с_вложением'] = (int)($r['с_вложением'] ?? 0);
        $d['из_них_без_текста'] = (int)($r['без_текста'] ?? 0);
    } catch (Exception $e) { $d['ошибка'] = substr($e->getMessage(), 0, 200); }
    $d['колонки'] = mpColumns($pdo);
    mpOut(['ok' => true, 'диагностика' => $d]);
}

$limit = max(10, min(200, (int)($_GET['limit'] ?? 50)));
$before = trim((string)($_GET['before'] ?? ''));

// Отдаём только то, что человек и так видит: свою переписку с этим собеседником.
// Если список колонок получить не удалось — берём строку целиком (m.*). Лишние
// поля дороже по трафику, но это несравнимо лучше, чем потерять вложения.
$known = mpColumns($pdo);
if (!$known) {
    $sel = 'm.*';
} else {
    $cols = ['id', 'sender_id', 'receiver_id', 'content', 'created_at'];
    foreach (['attachment_url', 'attachment_type', 'attachment_name', 'edited_at',
              'is_read', 'read_at'] as $c) {
        if (mpHasColumn($pdo, $c)) $cols[] = $c;
    }
    $cols = array_values(array_intersect($cols, $known));
    // Обязательный минимум пропасть не должен: без id и created_at чат не соберёт ленту
    $sel = $cols ? implode(', ', array_map(fn($c) => 'm.' . $c, $cols)) : 'm.*';
}

/**
 * Обращения в поддержку, написанные из списка чатов.
 *
 * Когда человек пишет «Тех поддержке» у себя в чатах, сообщение уходит НЕ конкретному
 * администратору, а на служебного получателя 'support' — и ответы приходят от него же.
 * Список диалогов у админа такие строки показывает (с именем человека и превью), а
 * этот запрос искал строго переписку двух людей и не находил ничего: диалог
 * открывался пустым, хотя в списке рядом висело «📷 Фото».
 *
 * Поэтому администратору добавляем в выборку и переписку этого человека с 'support'.
 * Только администратору: иначе чужие обращения в поддержку смог бы прочитать любой.
 */
$isAdmin = false;
try {
    $st = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    $st->execute([$userId]);
    $isAdmin = ((string)$st->fetchColumn() === 'admin');
} catch (Exception $e) {}

$where = "((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))";
$args = [$userId, $peer, $peer, $userId];
if ($isAdmin && $peer !== 'support') {
    $where = "(" . $where . " OR (m.sender_id = ? AND m.receiver_id = 'support')"
                   . " OR (m.sender_id = 'support' AND m.receiver_id = ?))";
    array_push($args, $peer, $peer);
}

// Курсор: берём то, что старее указанной точки
if ($before !== '') {
    $parts = explode('|', $before, 2);
    $bTime = $parts[0] ?? '';
    $bId = $parts[1] ?? '';
    if ($bTime !== '') {
        $where .= " AND (m.created_at < ? OR (m.created_at = ? AND m.id < ?))";
        array_push($args, $bTime, $bTime, $bId);
    }
}

try {
    // Берём на одну больше запрошенного: так сразу видно, есть ли что-то ещё дальше,
    // и не нужен отдельный COUNT по всей переписке.
    $sql = "SELECT $sel, u.first_name AS sender_first_name, u.last_name AS sender_last_name
              FROM messages m
              LEFT JOIN users u ON u.id = m.sender_id
             WHERE $where
             ORDER BY m.created_at DESC, m.id DESC
             LIMIT " . ($limit + 1);
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $hasMore = count($rows) > $limit;
    if ($hasMore) array_pop($rows);

    // Курсор — по самому старому из отданных
    $cursor = null;
    if ($rows) {
        $oldest = $rows[count($rows) - 1];
        $cursor = (string)$oldest['created_at'] . '|' . (string)$oldest['id'];
    }

    // Наружу отдаём в привычном порядке: от старых к новым, как рисует переписку чат
    $rows = array_reverse($rows);

    // ── Отметить прочитанным ──────────────────────────────────────────────────
    // Прежний путь чтения (messages.php?action=list) снимал непрочитанность попутно,
    // сам этого не объявляя. Когда переписку стал отдавать этот файл, отметка
    // пропала — счётчик у диалога горел даже после того, как всё прочитано.
    //
    // Писать на каждый опрос нельзя: чат обновляет переписку раз в несколько секунд,
    // и постоянный UPDATE — ровно та нагрузка, из-за которой сайт уже ложился.
    // Поэтому смотрим на строки, которые и так только что прочли: есть ли среди них
    // непрочитанное МНЕ. Нет — не трогаем базу вовсе. Есть — один UPDATE, и он
    // закрывает всю переписку, включая то, что не попало в текущую порцию.
    $markedRead = false;
    $flag = mpHasColumn($pdo, 'is_read') ? 'is_read'
          : (mpHasColumn($pdo, 'read_at') ? 'read_at' : null);
    if ($flag && $rows) {
        $needs = false;
        foreach ($rows as $row) {
            if ((string)($row['sender_id'] ?? '') !== (string)$peer) continue;
            if ((string)($row['receiver_id'] ?? '') !== (string)$userId) continue;
            $v = $row[$flag] ?? null;
            if ($flag === 'is_read' ? !(int)$v : ($v === null || $v === '')) { $needs = true; break; }
        }
        // Непрочитанное может быть СТАРШЕ загруженной порции — тогда в строках выше его
        // не видно, и счётчик горел даже после того, как человек открыл диалог и всё
        // прочитал (жалоба админа: «приходят старые, давно прочитанные»). На открытии
        // диалога (opened=1) спрашиваем базу отдельно. На опросе этого запроса нет:
        // чат перечитывает переписку раз в несколько секунд, и лишний запрос на каждый
        // тик — ровно та нагрузка, из-за которой сайт уже ложился.
        if (!$needs && !empty($_GET['opened'])) {
            try {
                $chk = $pdo->prepare($flag === 'is_read'
                    ? "SELECT 1 FROM messages WHERE sender_id = ? AND receiver_id = ?
                         AND (is_read = 0 OR is_read IS NULL) LIMIT 1"
                    : "SELECT 1 FROM messages WHERE sender_id = ? AND receiver_id = ?
                         AND read_at IS NULL LIMIT 1");
                $chk->execute([$peer, $userId]);
                $needs = (bool)$chk->fetchColumn();
            } catch (Exception $e) { /* не смогли проверить — ведём себя как раньше */ }
        }
        if ($needs) {
            try {
                $sqlUpd = $flag === 'is_read'
                    ? "UPDATE messages SET is_read = 1
                        WHERE sender_id = ? AND receiver_id = ? AND (is_read = 0 OR is_read IS NULL)"
                    : "UPDATE messages SET read_at = ?
                        WHERE sender_id = ? AND receiver_id = ? AND read_at IS NULL";
                $up = $pdo->prepare($sqlUpd);
                $up->execute($flag === 'is_read'
                    ? [$peer, $userId]
                    : [date('Y-m-d H:i:s'), $peer, $userId]);
                // Обращение, написанное «Тех поддержке», адресовано 'support', а не
                // администратору лично: без этой строки админ прочитал бы сообщение,
                // а счётчик непрочитанного у диалога продолжал бы гореть.
                if ($isAdmin && $peer !== 'support') {
                    $up->execute($flag === 'is_read'
                        ? [$peer, 'support']
                        : [date('Y-m-d H:i:s'), $peer, 'support']);
                }
                // Клиент рисует галочки по этим же полям — отдаём уже обновлённое состояние
                foreach ($rows as &$row) {
                    if ((string)($row['sender_id'] ?? '') !== (string)$peer) continue;
                    if ((string)($row['receiver_id'] ?? '') !== (string)$userId) continue;
                    $row[$flag] = $flag === 'is_read' ? 1 : date('Y-m-d H:i:s');
                }
                unset($row);
                $markedRead = true;   // счётчик у диалога устарел — чат обновит список
            } catch (Exception $e) { /* не отметилось — переписку всё равно отдаём */ }
        }
    }

    mpOut(['ok' => true, 'data' => $rows, 'has_more' => $hasMore,
           'cursor' => $cursor, 'marked_read' => $markedRead]);
} catch (Exception $e) {
    mpOut(['ok' => false, 'error' => 'Не удалось прочитать переписку'], 500);
}
