<?php
/**
 * supervision.php — супервизия для психологов.
 *
 * Групповая: опытный психолог (супервизор) ведёт группу младших психологов.
 *   Длительность 90 или 120 минут, цена за участника — не выше 1500 ₽.
 *   Супервизор зарабатывает свою долю, платформа удерживает комиссию.
 * Индивидуальная: 1:1 супервизора и психолога, цена «по запросу»
 *   (психолог оставляет запрос → супервизор называет цену → психолог принимает).
 *
 * Самостоятельный файл, та же БД, сам создаёт таблицы. Доступ — психологам и админам.
 *
 * Групповая:
 *   POST ?action=create-group  {title, description, datetime, duration_min, price, capacity}
 *   GET  ?action=list-groups
 *   POST ?action=join-group    {session_id}
 *   GET  ?action=my
 * Индивидуальная (по запросу):
 *   POST ?action=request       {supervisor_user_id?, topic}
 *   GET  ?action=requests                 — супервизору (адресованные ему) / админу (все)
 *   POST ?action=price-request {request_id, price}     — супервизор называет цену
 *   POST ?action=respond-request {request_id, accept}  — психолог принимает/отклоняет
 * Админ:
 *   GET  ?action=admin-overview
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

$me = null;
try { $st = $pdo->prepare("SELECT id, role, first_name, last_name FROM users WHERE id = ? LIMIT 1"); $st->execute([$userId]); $me = $st->fetch(PDO::FETCH_ASSOC); } catch (Exception $e) {}
$role = $me['role'] ?? '';
$isPsy = ($role === 'psychologist');
$isAdmin = ($role === 'admin');

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS supervision_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        supervisor_user_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT NULL,
        datetime DATETIME NULL,
        duration_min INT NOT NULL DEFAULT 90,
        price DECIMAL(10,2) NOT NULL DEFAULT 0,
        capacity INT NOT NULL DEFAULT 6,
        commission DECIMAL(5,2) NOT NULL DEFAULT 40,
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sup (supervisor_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS supervision_signups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        psychologist_user_id INT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sess (session_id),
        INDEX idx_psy (psychologist_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS supervision_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        requester_user_id INT NOT NULL,
        supervisor_user_id INT NULL,
        topic TEXT NULL,
        price DECIMAL(10,2) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'new',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_req (requester_user_id),
        INDEX idx_supreq (supervisor_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

function platformCommissionPct(PDO $pdo): float {
    foreach (['settings', 'site_settings', 'options'] as $table) {
        try { $cols = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) { continue; }
        if (!$cols) continue;
        $lc = array_map('strtolower', $cols); $kc = null; $vc = null;
        foreach (['setting_key','key','name','k'] as $c){ $i=array_search($c,$lc,true); if($i!==false){$kc=$cols[$i];break;} }
        foreach (['setting_value','value','val','v'] as $c){ $i=array_search($c,$lc,true); if($i!==false){$vc=$cols[$i];break;} }
        if (!$kc || !$vc) continue;
        try { $st=$pdo->prepare("SELECT `$vc` FROM `$table` WHERE `$kc`='platform_commission' LIMIT 1"); $st->execute(); $v=$st->fetchColumn();
            if ($v!==false && $v!==null && $v!=='') return max(0,min(100,(float)$v)); } catch (Exception $e) {}
    }
    return 40.0;
}
function nameMap(PDO $pdo): array {
    $m = [];
    try { foreach ($pdo->query("SELECT id, first_name, last_name FROM users")->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $nm = trim(($u['first_name']??'').' '.($u['last_name']??'')); $m[(string)$u['id']] = $nm!==''?$nm:'Психолог';
    } } catch (Exception $e) {}
    return $m;
}

$action = $_GET['action'] ?? '';
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

// ── Групповая ───────────────────────────────────────────────────────────────────
if ($action === 'create-group') {
    if (!$isPsy && !$isAdmin) { http_response_code(403); echo json_encode(['error' => 'Создавать группы может только психолог-супервизор']); exit; }
    $title = trim((string)($body['title'] ?? ''));
    $desc = trim((string)($body['description'] ?? ''));
    $dt = trim((string)($body['datetime'] ?? ''));
    $dur = (int)($body['duration_min'] ?? 90);
    $price = (float)($body['price'] ?? 0);
    $cap = (int)($body['capacity'] ?? 6);
    if ($title === '' || $price <= 0) { http_response_code(400); echo json_encode(['error' => 'Нужны название и цена']); exit; }
    if ($price > 1500) { http_response_code(400); echo json_encode(['error' => 'Цена групповой супервизии не выше 1500 ₽ за участника']); exit; }
    if (!in_array($dur, [90, 120], true)) $dur = 90;
    if ($cap < 2) $cap = 2; if ($cap > 30) $cap = 30;
    $comm = platformCommissionPct($pdo);
    try {
        $st = $pdo->prepare("INSERT INTO supervision_sessions (supervisor_user_id, title, description, datetime, duration_min, price, capacity, commission) VALUES (?,?,?,?,?,?,?,?)");
        $st->execute([$userId, $title, $desc, $dt !== '' ? str_replace('T',' ',$dt) : null, $dur, $price, $cap, $comm]);
        echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось создать группу']); }
    exit;
}

if ($action === 'list-groups') {
    if (!$isPsy && !$isAdmin) { http_response_code(403); echo json_encode(['error' => 'Доступно психологам']); exit; }
    try { $rows = $pdo->query("SELECT * FROM supervision_sessions WHERE status='open' ORDER BY (datetime IS NULL), datetime ASC, id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC); }
    catch (Exception $e) { $rows = []; }
    $names = nameMap($pdo);
    foreach ($rows as &$r) {
        $taken = 0;
        try { $q=$pdo->prepare("SELECT COUNT(*) FROM supervision_signups WHERE session_id=?"); $q->execute([$r['id']]); $taken=(int)$q->fetchColumn(); } catch (Exception $e) {}
        $r['taken'] = $taken;
        $r['seats_left'] = max(0, (int)$r['capacity'] - $taken);
        $r['supervisor_name'] = $names[(string)$r['supervisor_user_id']] ?? 'Супервизор';
        $r['is_mine'] = ((string)$r['supervisor_user_id'] === (string)$userId);
        $joined = false;
        try { $q=$pdo->prepare("SELECT COUNT(*) FROM supervision_signups WHERE session_id=? AND psychologist_user_id=?"); $q->execute([$r['id'],$userId]); $joined=(int)$q->fetchColumn()>0; } catch (Exception $e) {}
        $r['joined'] = $joined;
    }
    echo json_encode(['ok' => true, 'data' => $rows]);
    exit;
}

if ($action === 'join-group') {
    if (!$isPsy && !$isAdmin) { http_response_code(403); echo json_encode(['error' => 'Записаться может психолог']); exit; }
    $sid = $body['session_id'] ?? null;
    if (!$sid) { http_response_code(400); echo json_encode(['error' => 'session_id обязателен']); exit; }
    try { $st=$pdo->prepare("SELECT * FROM supervision_sessions WHERE id=? LIMIT 1"); $st->execute([$sid]); $s=$st->fetch(PDO::FETCH_ASSOC); } catch (Exception $e) { $s=null; }
    if (!$s) { http_response_code(404); echo json_encode(['error' => 'Группа не найдена']); exit; }
    if ((string)$s['supervisor_user_id'] === (string)$userId) { http_response_code(400); echo json_encode(['error' => 'Это ваша группа']); exit; }
    try { $q=$pdo->prepare("SELECT COUNT(*) FROM supervision_signups WHERE session_id=? AND psychologist_user_id=?"); $q->execute([$sid,$userId]); if((int)$q->fetchColumn()>0){ echo json_encode(['ok'=>true,'note'=>'Вы уже записаны']); exit; } } catch (Exception $e) {}
    try { $q=$pdo->prepare("SELECT COUNT(*) FROM supervision_signups WHERE session_id=?"); $q->execute([$sid]); if((int)$q->fetchColumn() >= (int)$s['capacity']){ http_response_code(400); echo json_encode(['error'=>'Мест больше нет']); exit; } } catch (Exception $e) {}
    try { $pdo->prepare("INSERT INTO supervision_signups (session_id, psychologist_user_id) VALUES (?,?)")->execute([$sid,$userId]); }
    catch (Exception $e) { http_response_code(500); echo json_encode(['error'=>'Не удалось записаться']); exit; }
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'my') {
    $names = nameMap($pdo);
    $created = []; $joined = [];
    try { $st=$pdo->prepare("SELECT * FROM supervision_sessions WHERE supervisor_user_id=? ORDER BY id DESC"); $st->execute([$userId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r){ $q=$pdo->prepare("SELECT COUNT(*) FROM supervision_signups WHERE session_id=?"); $q->execute([$r['id']]); $r['taken']=(int)$q->fetchColumn(); $created[]=$r; } } catch (Exception $e) {}
    try { $st=$pdo->prepare("SELECT s.* FROM supervision_sessions s JOIN supervision_signups g ON g.session_id=s.id WHERE g.psychologist_user_id=? ORDER BY s.id DESC"); $st->execute([$userId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r){ $r['supervisor_name']=$names[(string)$r['supervisor_user_id']]??'Супервизор'; $joined[]=$r; } } catch (Exception $e) {}
    echo json_encode(['ok' => true, 'created' => $created, 'joined' => $joined]);
    exit;
}

// ── Индивидуальная (по запросу) ──────────────────────────────────────────────────
if ($action === 'request') {
    if (!$isPsy && !$isAdmin) { http_response_code(403); echo json_encode(['error' => 'Запрос может оставить психолог']); exit; }
    $topic = trim((string)($body['topic'] ?? ''));
    $sup = $body['supervisor_user_id'] ?? null;
    try { $pdo->prepare("INSERT INTO supervision_requests (requester_user_id, supervisor_user_id, topic, status) VALUES (?,?,?,'new')")->execute([$userId, $sup ?: null, $topic]);
        echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]); }
    catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось отправить запрос']); }
    exit;
}

if ($action === 'requests') {
    $names = nameMap($pdo);
    try {
        if ($isAdmin) { $st = $pdo->query("SELECT * FROM supervision_requests ORDER BY id DESC LIMIT 1000"); $rows = $st->fetchAll(PDO::FETCH_ASSOC); }
        else { $st = $pdo->prepare("SELECT * FROM supervision_requests WHERE supervisor_user_id=? OR requester_user_id=? ORDER BY id DESC"); $st->execute([$userId,$userId]); $rows = $st->fetchAll(PDO::FETCH_ASSOC); }
    } catch (Exception $e) { $rows = []; }
    foreach ($rows as &$r) {
        $r['requester_name'] = $names[(string)$r['requester_user_id']] ?? 'Психолог';
        $r['supervisor_name'] = $r['supervisor_user_id'] ? ($names[(string)$r['supervisor_user_id']] ?? 'Супервизор') : '';
        $r['is_requester'] = ((string)$r['requester_user_id'] === (string)$userId);
    }
    echo json_encode(['ok' => true, 'data' => $rows]);
    exit;
}

if ($action === 'price-request') {
    $rid = $body['request_id'] ?? null;
    $price = (float)($body['price'] ?? 0);
    if (!$rid || $price <= 0) { http_response_code(400); echo json_encode(['error' => 'Нужны запрос и цена']); exit; }
    try { $st=$pdo->prepare("SELECT * FROM supervision_requests WHERE id=? LIMIT 1"); $st->execute([$rid]); $req=$st->fetch(PDO::FETCH_ASSOC); } catch (Exception $e) { $req=null; }
    if (!$req) { http_response_code(404); echo json_encode(['error' => 'Запрос не найден']); exit; }
    if (!$isAdmin && !$isPsy) { http_response_code(403); echo json_encode(['error' => 'Цену называет супервизор']); exit; }
    // закрепляем супервизора за запросом (если ещё не задан)
    try { $pdo->prepare("UPDATE supervision_requests SET price=?, status='priced', supervisor_user_id=COALESCE(supervisor_user_id, ?) WHERE id=?")->execute([$price, $userId, $rid]);
        echo json_encode(['ok' => true]); }
    catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось назначить цену']); }
    exit;
}

if ($action === 'respond-request') {
    $rid = $body['request_id'] ?? null;
    $accept = !empty($body['accept']);
    if (!$rid) { http_response_code(400); echo json_encode(['error' => 'request_id обязателен']); exit; }
    try { $st=$pdo->prepare("SELECT * FROM supervision_requests WHERE id=? LIMIT 1"); $st->execute([$rid]); $req=$st->fetch(PDO::FETCH_ASSOC); } catch (Exception $e) { $req=null; }
    if (!$req) { http_response_code(404); echo json_encode(['error' => 'Запрос не найден']); exit; }
    if ((string)$req['requester_user_id'] !== (string)$userId) { http_response_code(403); echo json_encode(['error' => 'Это не ваш запрос']); exit; }
    try { $pdo->prepare("UPDATE supervision_requests SET status=? WHERE id=?")->execute([$accept ? 'accepted' : 'declined', $rid]);
        echo json_encode(['ok' => true]); }
    catch (Exception $e) { http_response_code(500); echo json_encode(['error' => 'Не удалось обновить запрос']); }
    exit;
}

if ($action === 'admin-overview') {
    if (!$isAdmin) { http_response_code(403); echo json_encode(['error' => 'Только для администратора']); exit; }
    $names = nameMap($pdo);
    $sessions = []; $requests = [];
    try { foreach ($pdo->query("SELECT * FROM supervision_sessions ORDER BY id DESC LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC) as $r){
        $q=$pdo->prepare("SELECT COUNT(*) FROM supervision_signups WHERE session_id=?"); $q->execute([$r['id']]); $r['taken']=(int)$q->fetchColumn();
        $r['supervisor_name']=$names[(string)$r['supervisor_user_id']]??'Супервизор'; $sessions[]=$r; } } catch (Exception $e) {}
    try { foreach ($pdo->query("SELECT * FROM supervision_requests ORDER BY id DESC LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC) as $r){
        $r['requester_name']=$names[(string)$r['requester_user_id']]??'Психолог'; $r['supervisor_name']=$r['supervisor_user_id']?($names[(string)$r['supervisor_user_id']]??'Супервизор'):''; $requests[]=$r; } } catch (Exception $e) {}
    echo json_encode(['ok' => true, 'sessions' => $sessions, 'requests' => $requests]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Неизвестное действие']);
