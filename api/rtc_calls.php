<?php
/**
 * rtc_calls.php — свои аудио- и видеозвонки прямо на сайте, без Яндекс.Телемоста.
 *
 * КАК ЭТО РАБОТАЕТ И ПОЧЕМУ ТАК
 * Звук и видео идут НАПРЯМУЮ между браузерами (WebRTC, peer-to-peer) и через наш
 * сервер не проходят вообще. Хостинг переносит только «знакомство» собеседников:
 * несколько небольших JSON-строк с описанием соединения (SDP) и сетевыми
 * кандидатами (ICE). Для shared-хостинга это копейки — ни одного байта медиа.
 *
 * Шифрование включено всегда и его нельзя отключить: WebRTC обязывает шифровать
 * поток (DTLS-SRTP). Ключи вырабатываются самими браузерами, серверу они не
 * известны, поэтому прослушать разговор со стороны сайта нельзя.
 *
 * ОГРАНИЧЕНИЕ, О КОТОРОМ НУЖНО ЗНАТЬ
 * Если ОБА собеседника сидят за «строгим» NAT (symmetric NAT — часто у мобильных
 * операторов и в корпоративных сетях), прямое соединение не устанавливается: нужен
 * ретранслятор TURN. TURN — это отдельный сервер с открытыми UDP-портами, на
 * shared-хостинге его не поднять. Поэтому:
 *   • по умолчанию используем только STUN (бесплатно, ничего поднимать не нужно) —
 *     этого хватает большинству пар;
 *   • если у платформы появится TURN, его адрес и логин кладутся в
 *     api/rtc_calls_config.php (см. rtc_calls_config.sample.php) — код менять не придётся;
 *   • когда соединиться не удалось, клиент честно говорит об этом и предлагает
 *     Телемост как запасной путь.
 *
 * GET  ?action=ice                        — какие STUN/TURN отдавать браузеру
 * GET  ?action=poll[&after=N]             — входящие звонки и новые сигналы
 * POST ?action=start   {to, kind}         — позвонить (kind: audio|video)
 * POST ?action=signal  {call_id, kind, payload}  — offer/answer/ice/game
 * POST ?action=accept  {call_id}
 * POST ?action=decline {call_id}
 * POST ?action=end     {call_id, reason}
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
if (!$userId) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Требуется авторизация']); exit; }

function out($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

require_once __DIR__ . '/rtc_lib.php';

$action = $_GET['action'] ?? '';
// Схему проверяем только у редких действий. Опрос (самый частый запрос) её не
// касается: если таблицы вдруг нет, rtcTry поймает ошибку и создаст её один раз.
if ($action !== 'poll' && $action !== 'ice') rtcEnsureSchema($pdo);
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

try {
    if ($action === 'ice') {
        // ── ПОЧЕМУ У ЧАСТИ ЛЮДЕЙ ЗВОНОК НЕ ВСТАЁТ БЕЗ VPN ───────────────────
        // STUN только подсказывает браузеру его внешний адрес. Этого хватает,
        // пока хотя бы один собеседник за «мягким» NAT. У мобильных операторов
        // и в офисных сетях NAT симметричный: внешний порт свой на каждого
        // адресата, поэтому предсказать его нельзя и прямое соединение не встаёт
        // в принципе. VPN иногда «чинит» это случайно — просто уводит человека
        // в сеть с другим NAT. Единственное настоящее решение — TURN:
        // ретранслятор, через который media идёт, когда напрямую нельзя.
        //
        // Порядок ниже осмысленный: сначала STUN (дёшево и быстро), затем свой
        // TURN платформы, затем общедоступный запасной. Браузер всё равно
        // предпочтёт прямой путь и уйдёт на ретранслятор, только если иначе никак.
        $servers = [['urls' => [
            'stun:stun.l.google.com:19302',
            'stun:stun1.l.google.com:19302',
            'stun:stun2.l.google.com:19302',
            'stun:stun.cloudflare.com:3478',
        ]]];

        $ownTurn = false;
        $cfg = __DIR__ . '/rtc_calls_config.php';
        if (file_exists($cfg)) {
            $turn = include $cfg;
            if (is_array($turn)) {
                if (!empty($turn['turn_urls'])) {
                    $one = ['urls' => $turn['turn_urls']];
                    if (!empty($turn['turn_username'])) $one['username'] = $turn['turn_username'];
                    if (!empty($turn['turn_credential'])) $one['credential'] = $turn['turn_credential'];
                    $servers[] = $one;
                    $ownTurn = true;
                }
            }
        }

        // ЗДЕСЬ БЫЛ ОБЩЕДОСТУПНЫЙ РЕТРАНСЛЯТОР — И ЕГО ПРИШЛОСЬ УБРАТЬ.
        // Сначала сюда был вписан бесплатный openrelay.metered.ca, чтобы звонки
        // начали проходить не дожидаясь своего сервера. Проверка показала, что имя
        // openrelay.metered.ca больше не резолвится (Google STUN и psytalk.pro с того
        // же резолвера находятся, то есть дело не в проверяющей стороне) — сервис
        // закрыт. Мёртвый TURN в списке хуже, чем никакого: браузер честно тратит
        // на него время при каждом дозвоне, а has_turn врал бы, что ретранслятор есть.
        //
        // Поэтому: своего TURN нет — так и говорим. Как только он появится в
        // rtc_calls_config.php, всё заработает без правок кода. Любой сторонний
        // сервис вписывается туда же, в turn_urls.
        out(['ok' => true, 'ice_servers' => $servers,
             'has_turn' => $ownTurn, 'own_turn' => $ownTurn, 'public_turn' => false]);
    }

    if ($action === 'poll') {
        // Вся выборка живёт в rtc_lib.php: этим же кодом пользуется api/sync.php,
        // который собирает состояние звонков вместе с остальным одним запросом.
        out(['ok' => true] + rtcPollFor($pdo, $userId, $_GET['after'] ?? 0));
    }

    if ($action === 'start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $to = trim((string)($body['to'] ?? ''));
        $kind = ($body['kind'] ?? 'video') === 'audio' ? 'audio' : 'video';
        if ($to === '') out(['ok' => false, 'error' => 'Не указан собеседник'], 400);
        if ((string)$to === (string)$userId) out(['ok' => false, 'error' => 'Нельзя позвонить самому себе'], 400);
        $ex = $pdo->prepare("SELECT 1 FROM users WHERE id = ?");
        $ex->execute([$to]);
        if (!$ex->fetchColumn()) out(['ok' => false, 'error' => 'Такого собеседника нет'], 404);

        expireStale($pdo);
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM rtc_calls WHERE from_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)");
        $cnt->execute([$userId]);
        if ((int)$cnt->fetchColumn() >= MAX_PER_HOUR)
            out(['ok' => false, 'error' => 'Слишком много звонков за час — попробуйте позже'], 429);

        // старые свои незакрытые вызовы закрываем, чтобы не было двух сразу
        $pdo->prepare("UPDATE rtc_calls SET status = 'ended', end_reason = 'replaced', ended_at = NOW()
                       WHERE from_id = ? AND status IN ('ringing','accepted')")->execute([$userId]);

        $pdo->prepare("INSERT INTO rtc_calls (from_id, to_id, kind, status, created_at)
                       VALUES (?, ?, ?, 'ringing', NOW())")->execute([$userId, $to, $kind]);
        out(['ok' => true, 'call_id' => (int)$pdo->lastInsertId(), 'kind' => $kind]);
    }

    if ($action === 'signal' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $callId = (int)($body['call_id'] ?? 0);
        $kind = (string)($body['kind'] ?? '');
        // 'game' — необязательные ходы игры в ожидании ответа (см. rtcGame* в chat.html),
        // летят тем же каналом сигналов, сервер их просто передаёт дальше не глядя внутрь.
        if (!in_array($kind, ['offer', 'answer', 'ice', 'game'], true)) out(['ok' => false, 'error' => 'Неизвестный сигнал'], 400);
        $payload = (string)($body['payload'] ?? '');
        if (strlen($payload) > SIGNAL_MAX) out(['ok' => false, 'error' => 'Слишком большой сигнал'], 400);
        $call = loadCall($pdo, $callId);
        if (!isParty($call, $userId)) out(['ok' => false, 'error' => 'Звонок недоступен'], 403);
        if ($call['status'] === 'ended') out(['ok' => false, 'error' => 'Звонок уже завершён'], 409);
        $pdo->prepare("INSERT INTO rtc_call_signals (call_id, sender_id, kind, payload, created_at)
                       VALUES (?, ?, ?, ?, NOW())")->execute([$callId, $userId, $kind, $payload]);
        out(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    }

    if ($action === 'accept' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $call = loadCall($pdo, (int)($body['call_id'] ?? 0));
        if (!$call || (string)$call['to_id'] !== (string)$userId) out(['ok' => false, 'error' => 'Звонок недоступен'], 403);
        if ($call['status'] !== 'ringing') out(['ok' => false, 'error' => 'Звонок уже не активен'], 409);
        $pdo->prepare("UPDATE rtc_calls SET status = 'accepted', answered_at = NOW() WHERE id = ?")->execute([$call['id']]);
        out(['ok' => true]);
    }

    if ($action === 'decline' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $call = loadCall($pdo, (int)($body['call_id'] ?? 0));
        if (!isParty($call, $userId)) out(['ok' => false, 'error' => 'Звонок недоступен'], 403);
        $pdo->prepare("UPDATE rtc_calls SET status = 'ended', end_reason = 'declined', ended_at = NOW()
                       WHERE id = ? AND status <> 'ended'")->execute([$call['id']]);
        rtcLogCall($pdo, $call['id']);   // след в переписке: «звонок отклонён»
        out(['ok' => true]);
    }

    if ($action === 'end' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $call = loadCall($pdo, (int)($body['call_id'] ?? 0));
        if (!isParty($call, $userId)) out(['ok' => false, 'error' => 'Звонок недоступен'], 403);
        $reason = substr((string)($body['reason'] ?? 'hangup'), 0, 40);
        $pdo->prepare("UPDATE rtc_calls SET status = 'ended', end_reason = ?, ended_at = NOW()
                       WHERE id = ? AND status <> 'ended'")->execute([$reason, $call['id']]);
        rtcLogCall($pdo, $call['id']);   // след в переписке: длительность либо причина
        out(['ok' => true]);
    }

    out(['ok' => false, 'error' => 'Неизвестное действие'], 400);
} catch (Exception $e) {
    out(['ok' => false, 'error' => $e->getMessage()], 500);
}
