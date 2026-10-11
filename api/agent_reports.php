<?php
/**
 * agent_reports.php — отчёт агента за период (доказательная база для налоговой ИП).
 *
 * Зачем. Платформа работает по агентскому договору-оферте: по ст. 251 НК РФ доходом
 * агента признаётся ТОЛЬКО агентское вознаграждение (комиссия), а деньги, собранные
 * для принципалов-психологов, — транзит и в доход агента не входят. Чтобы это можно
 * было доказать ФНС, нужен документ — отчёт агента: за период видно, сколько собрано
 * с клиентов, сколько удержано комиссии (облагается у агента) и сколько перечислено
 * принципалам. Данные берутся из уже существующего учёта (psy_payouts), поэтому отчёт
 * не создаёт параллельной «правды», а просто сводит и оформляет имеющееся.
 *
 * Самостоятельный файл, только для администратора. Все запросы PDO с prepared.
 *
 * Действия (GET):
 *   action=report&from=YYYY-MM-DD&to=YYYY-MM-DD   — JSON: свод + разбивка по психологам
 *   action=csv&from=..&to=..                       — выгрузка реестра (CSV для Excel)
 */

require_once __DIR__ . '/config.php';
if (!function_exists('getDB') && !function_exists('getDbConnection') && !function_exists('getPDO')) {
    require_once __DIR__ . '/db.php';
}
require_once __DIR__ . '/settings_lib.php';
$pdo = function_exists('getDB') ? getDB()
     : (function_exists('getDbConnection') ? getDbConnection()
     : (function_exists('getPDO') ? getPDO() : null));

function arOut($d, $c = 200) { http_response_code($c); header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if (!$pdo) arOut(['error' => 'Нет подключения к БД'], 500);

if (session_status() === PHP_SESSION_NONE) session_start();
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) arOut(['error' => 'Требуется авторизация'], 401);
$role = '';
try { $st = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1"); $st->execute([$userId]); $role = (string)$st->fetchColumn(); } catch (Exception $e) {}
if ($role !== 'admin') arOut(['error' => 'Доступ только для администратора'], 403);

$action = $_GET['action'] ?? 'report';

/** Нормализовать дату YYYY-MM-DD; пусто → null. */
function arDate($s) {
    $s = trim((string)$s);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
}

/**
 * Собрать отчёт за период. Для УСН доход признаётся по дате получения денег, поэтому
 * «собрано/комиссия» считаем по дате ОПЛАТЫ клиентом (payments.paid_at), а не по дате
 * создания начисления. Если платёжной даты нет (старые данные) — запасной вариант по
 * psy_payouts.created_at, чтобы запись не выпала из отчёта.
 */
function arBuild(PDO $pdo, $from, $to) {
    $fromDt = $from ? $from . ' 00:00:00' : '1970-01-01 00:00:00';
    $toDt   = $to   ? $to   . ' 23:59:59' : '2999-12-31 23:59:59';

    // Начисления с датой оплаты клиентом (для признания дохода).
    $rows = [];
    try {
        $st = $pdo->query("SELECT po.psychologist_id, po.amount_total, po.commission_amount,
                                  po.amount_to_psy, po.status, po.paid_at AS payout_paid_at, po.created_at,
                                  (SELECT MIN(p.paid_at) FROM payments p
                                    WHERE p.appointment_id = po.appointment_id AND p.status='success') AS client_paid_at
                             FROM psy_payouts po
                            WHERE po.status <> 'canceled'");
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return ['ok' => false, 'error' => 'Не удалось прочитать учёт: ' . $e->getMessage()]; }

    // Имена/ИНН связываем через psychologists → users; договор — через consents(agent_offer).
    $names = []; $userByPsy = [];
    try {
        foreach ($pdo->query("SELECT p.id, p.user_id, u.first_name, u.last_name FROM psychologists p LEFT JOIN users u ON u.id = p.user_id") as $p) {
            $pid = (string)$p['id'];
            $n = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
            $names[$pid] = $n !== '' ? $n : 'Психолог';
            $userByPsy[$pid] = (string)($p['user_id'] ?? '');
        }
    } catch (Exception $e) {}

    // Принятие агентского договора-оферты (последнее активное) по user_id.
    $consent = [];
    try {
        foreach ($pdo->query("SELECT user_id, doc_version, created_at, ip FROM consents
                               WHERE consent_type='agent_offer' AND status='active' ORDER BY id ASC") as $c) {
            $consent[(string)$c['user_id']] = ['version' => $c['doc_version'], 'date' => $c['created_at'], 'ip' => $c['ip']];
        }
    } catch (Exception $e) {}

    // ИНН — из psychologist_credentials (type='inn'); НПД — из кэша psy_npd_checks.
    $inn = []; $npd = [];
    try {
        foreach ($pdo->query("SELECT psychologist_id, user_id, name FROM psychologist_credentials WHERE type='inn' ORDER BY id ASC") as $cr) {
            $digits = preg_replace('/\D+/', '', (string)$cr['name']);
            if ($cr['psychologist_id'] !== null && $cr['psychologist_id'] !== '') $inn[(string)$cr['psychologist_id']] = $digits;
            // запасная привязка по user_id
            if (($cr['user_id'] ?? '') !== '') foreach ($userByPsy as $pid => $uid) if ($uid === (string)$cr['user_id'] && empty($inn[$pid])) $inn[$pid] = $digits;
        }
    } catch (Exception $e) {}
    try {
        foreach ($pdo->query("SELECT psychologist_id, status FROM psy_npd_checks") as $n) {
            $npd[(string)$n['psychologist_id']] = (string)$n['status'];
        }
    } catch (Exception $e) {}

    $agg = [];
    $ensure = function (&$agg, $pid) use ($names, $inn, $npd, $consent, $userByPsy) {
        if (isset($agg[$pid])) return;
        $uid = $userByPsy[$pid] ?? '';
        $agg[$pid] = [
            'psychologist_id' => $pid,
            'имя' => $names[$pid] ?? 'Психолог',
            'инн' => $inn[$pid] ?? '',
            'нпд' => $npd[$pid] ?? 'unknown',
            'договор' => $consent[$uid] ?? null,
            'собрано' => 0.0, 'комиссия' => 0.0, 'к_перечислению' => 0.0,
            'выплачено_в_период' => 0.0, 'сессий' => 0,
        ];
    };

    foreach ($rows as $r) {
        $pid = (string)$r['psychologist_id'];
        // Дата признания дохода.
        $incomeDate = $r['client_paid_at'] ?: $r['created_at'];
        if ($incomeDate >= $fromDt && $incomeDate <= $toDt) {
            $ensure($agg, $pid);
            $agg[$pid]['собрано']        += (float)$r['amount_total'];
            $agg[$pid]['комиссия']       += (float)$r['commission_amount'];
            $agg[$pid]['к_перечислению'] += (float)$r['amount_to_psy'];
            $agg[$pid]['сессий']         += 1;
        }
        // Факт выплаты принципалу в периоде (кассовый поток выплат).
        if ($r['status'] === 'paid' && $r['payout_paid_at'] && $r['payout_paid_at'] >= $fromDt && $r['payout_paid_at'] <= $toDt) {
            $ensure($agg, $pid);
            $agg[$pid]['выплачено_в_период'] += (float)$r['amount_to_psy'];
        }
    }

    $data = array_values($agg);
    foreach ($data as &$d) {
        foreach (['собрано', 'комиссия', 'к_перечислению', 'выплачено_в_период'] as $k) $d[$k] = round($d[$k], 2);
    }
    unset($d);
    usort($data, fn($a, $b) => $b['собрано'] <=> $a['собрано']);

    $tot = ['собрано' => 0.0, 'комиссия' => 0.0, 'к_перечислению' => 0.0, 'выплачено_в_период' => 0.0, 'сессий' => 0];
    foreach ($data as $d) foreach ($tot as $k => $_) $tot[$k] += $d[$k];
    foreach (['собрано', 'комиссия', 'к_перечислению', 'выплачено_в_период'] as $k) $tot[$k] = round($tot[$k], 2);

    return [
        'ok' => true,
        'период' => ['с' => $from, 'по' => $to],
        'комиссия_%' => psySetting($pdo, 'platform_commission', '0'),
        'итого' => $tot,
        'data' => $data,
        'пояснение' => 'Доход агента (облагается у ИП) = столбец «комиссия». «Собрано» и '
            . '«к перечислению принципалам» — транзитные средства, доходом агента по ст. 251 НК РФ не являются.',
    ];
}

if ($action === 'report') {
    arOut(arBuild($pdo, arDate($_GET['from'] ?? ''), arDate($_GET['to'] ?? '')));
}

if ($action === 'csv') {
    $from = arDate($_GET['from'] ?? '');
    $to   = arDate($_GET['to'] ?? '');
    $rep = arBuild($pdo, $from, $to);
    if (empty($rep['ok'])) arOut($rep, 500);

    $fname = 'otchet-agenta_' . ($from ?: 'start') . '_' . ($to ?: 'end') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    echo "\xEF\xBB\xBF"; // BOM — чтобы Excel не ломал кириллицу
    $out = fopen('php://output', 'w');
    $sep = ';'; // RU Excel разделитель
    $w = function ($cols) use ($out, $sep) { fputcsv($out, $cols, $sep); };

    $w(['Отчёт агента psytalk.pro']);
    $w(['Период', ($from ?: 'с начала'), ($to ?: 'по сейчас')]);
    $w(['Комиссия платформы, %', str_replace('.', ',', (string)($rep['комиссия_%'] ?? ''))]);
    $w([]);
    $w(['Психолог (принципал)', 'ИНН', 'Статус НПД', 'Договор принят', 'Версия договора',
        'Сессий', 'Собрано с клиентов', 'Комиссия агента (доход ИП)', 'К перечислению принципалу', 'Выплачено в периоде']);
    $money = fn($n) => str_replace('.', ',', number_format((float)$n, 2, '.', ''));
    $npdTxt = ['yes' => 'подтверждён', 'no' => 'не подтверждён', 'none' => 'ИНН не указан', 'unknown' => 'не проверен'];
    foreach ($rep['data'] as $r) {
        $dog = $r['договор'];
        $w([
            $r['имя'], $r['инн'], ($npdTxt[$r['нпд']] ?? $r['нпд']),
            $dog ? $dog['date'] : 'не зафиксировано', $dog ? $dog['version'] : '',
            $r['сессий'], $money($r['собрано']), $money($r['комиссия']),
            $money($r['к_перечислению']), $money($r['выплачено_в_период']),
        ]);
    }
    $t = $rep['итого'];
    $w([]);
    $w(['ИТОГО', '', '', '', '', $t['сессий'], $money($t['собрано']), $money($t['комиссия']), $money($t['к_перечислению']), $money($t['выплачено_в_период'])]);
    $w([]);
    $w(['Доход агента, облагаемый у ИП (комиссия):', $money($t['комиссия'])]);
    $w(['Транзитные средства принципалов (не доход агента, ст. 251 НК РФ):', $money($t['к_перечислению'])]);
    fclose($out);
    exit;
}

arOut(['error' => 'Неизвестное действие'], 400);
