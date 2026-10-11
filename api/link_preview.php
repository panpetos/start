<?php
/**
 * link_preview.php — карточка ссылки: заголовок, описание и картинка.
 *
 * ЗАЧЕМ. В переписке ссылка выглядит как голый адрес: непонятно, куда ведёт, и её
 * страшно открывать. Мессенджеры показывают короткую карточку — то же делаем и мы.
 *
 * БЕЗОПАСНОСТЬ — главное здесь. Сервер по просьбе браузера идёт по чужому адресу,
 * а значит им можно попытаться заглянуть ВНУТРЬ нашей сети (так называемый SSRF).
 * Поэтому:
 *   • только http и https, никаких file://, gopher:// и прочего;
 *   • адрес разрешаем в IP заранее и отказываем, если он внутренний
 *     (127.х, 10.х, 192.168.х, 172.16–31.х, ::1 и им подобные);
 *   • переадресации не следуем автоматически — каждый шаг проверяем сами,
 *     иначе внешний адрес мог бы перевести нас на внутренний;
 *   • качаем не больше 256 КБ и не дольше 6 секунд;
 *   • ответ кэшируем на неделю, чтобы не ходить наружу на каждый показ переписки.
 *
 * GET ?url=<адрес> → { ok, title, description, image, site, url }
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=86400');

require_once __DIR__ . '/config.php';
if (!function_exists('getDB') && !function_exists('getDbConnection') && !function_exists('getPDO')) {
    require_once __DIR__ . '/db.php';
}
$pdo = function_exists('getDB') ? getDB()
     : (function_exists('getDbConnection') ? getDbConnection()
     : (function_exists('getPDO') ? getPDO() : null));

if (session_status() === PHP_SESSION_NONE) session_start();
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Требуется авторизация']); exit; }

function lpOut($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

/** Внутренний ли это адрес — туда ходить нельзя. */
function lpPrivateIp($ip) {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return true;
    // Отсекаем частные и служебные диапазоны штатной проверкой PHP
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return true;
    return false;
}

/** Безопасно ли идти по этому адресу. */
function lpSafeUrl($url) {
    $p = parse_url($url);
    if (!$p || empty($p['scheme']) || empty($p['host'])) return false;
    if (!in_array(strtolower($p['scheme']), ['http', 'https'], true)) return false;
    if (!empty($p['port']) && !in_array((int)$p['port'], [80, 443], true)) return false;
    $host = $p['host'];
    // Имя может указывать на внутренний адрес — разрешаем и проверяем каждый ответ
    $ips = @gethostbynamel($host);
    if (!$ips) {
        $rec = @dns_get_record($host, DNS_AAAA);
        $ips = $rec ? array_column($rec, 'ipv6') : [];
    }
    if (!$ips) return false;
    foreach ($ips as $ip) { if (lpPrivateIp($ip)) return false; }
    return true;
}

/** Скачать начало страницы, проверяя каждый шаг переадресации. */
function lpFetch($url, $hops = 3) {
    for ($i = 0; $i < $hops; $i++) {
        if (!lpSafeUrl($url)) return [null, null];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,     // переадресацию разбираем сами
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HEADER => true,
            CURLOPT_USERAGENT => 'psytalk.pro link preview',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
            // Обрываем закачку после 256 КБ: карточке хватает первых килобайт
            CURLOPT_BUFFERSIZE => 16384,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function ($ch, $dlTotal, $dlNow) {
                return $dlNow > 262144 ? 1 : 0;
            },
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hdrLen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        if ($raw === false && $code === 0) return [null, null];
        $head = substr((string)$raw, 0, $hdrLen);
        $body = substr((string)$raw, $hdrLen);
        if ($code >= 300 && $code < 400 && preg_match('/^Location:\s*(.+)$/mi', $head, $m)) {
            $next = trim($m[1]);
            // Относительная переадресация — достраиваем до полного адреса
            if (!preg_match('~^https?://~i', $next)) {
                $p = parse_url($url);
                $next = $p['scheme'] . '://' . $p['host'] . (strpos($next, '/') === 0 ? '' : '/') . $next;
            }
            $url = $next;
            continue;
        }
        if ($code < 200 || $code >= 300) return [null, null];
        return [$body, $url];
    }
    return [null, null];
}

/** Вытащить из разметки заголовок, описание и картинку. */
function lpParse($html, $baseUrl) {
    $out = ['title' => '', 'description' => '', 'image' => ''];
    if (!$html) return $out;
    // Приводим к UTF-8, если страница в другой кодировке
    if (preg_match('/charset=["\']?([A-Za-z0-9_\-]+)/i', substr($html, 0, 4000), $m)) {
        $cs = strtoupper($m[1]);
        if ($cs !== 'UTF-8' && function_exists('mb_convert_encoding')) {
            $html = @mb_convert_encoding($html, 'UTF-8', $cs) ?: $html;
        }
    }
    $meta = function ($names) use ($html) {
        foreach ($names as $n) {
            $re = '~<meta[^>]+(?:property|name)=["\']' . preg_quote($n, '~') . '["\'][^>]*content=["\']([^"\']*)["\']~i';
            if (preg_match($re, $html, $m)) return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            $re2 = '~<meta[^>]+content=["\']([^"\']*)["\'][^>]*(?:property|name)=["\']' . preg_quote($n, '~') . '["\']~i';
            if (preg_match($re2, $html, $m)) return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }
        return '';
    };
    $out['title'] = $meta(['og:title', 'twitter:title']);
    if ($out['title'] === '' && preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m)) {
        $out['title'] = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES, 'UTF-8');
    }
    $out['description'] = $meta(['og:description', 'twitter:description', 'description']);
    $img = $meta(['og:image', 'twitter:image']);
    if ($img !== '') {
        if (!preg_match('~^https?://~i', $img)) {
            $p = parse_url($baseUrl);
            $img = $p['scheme'] . '://' . $p['host'] . (strpos($img, '/') === 0 ? '' : '/') . $img;
        }
        // Картинку тоже отдаём, только если её адрес безопасен
        if (lpSafeUrl($img)) $out['image'] = $img;
    }
    foreach ($out as $k => $v) $out[$k] = mb_substr(trim((string)$v), 0, $k === 'description' ? 300 : 200);
    return $out;
}

$url = trim((string)($_GET['url'] ?? ''));
if ($url === '' || !preg_match('~^https?://~i', $url)) lpOut(['ok' => false, 'error' => 'Нужен обычный адрес http или https'], 400);
if (mb_strlen($url) > 900) lpOut(['ok' => false, 'error' => 'Слишком длинный адрес'], 400);

$key = hash('sha256', $url);

// Кэш: наружу ходим не чаще раза в неделю на один адрес
if ($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS link_previews (
            url_hash CHAR(64) PRIMARY KEY,
            url VARCHAR(900) NOT NULL,
            title VARCHAR(255) NULL,
            description VARCHAR(400) NULL,
            image VARCHAR(900) NULL,
            fetched_at DATETIME NOT NULL,
            INDEX idx_fetched (fetched_at)
        ) DEFAULT CHARSET=utf8mb4");
        $st = $pdo->prepare("SELECT title, description, image FROM link_previews
                              WHERE url_hash = ? AND fetched_at > (NOW() - INTERVAL 7 DAY) LIMIT 1");
        $st->execute([$key]);
        $hit = $st->fetch(PDO::FETCH_ASSOC);
        if ($hit) {
            lpOut(['ok' => true, 'cached' => true, 'url' => $url, 'site' => parse_url($url, PHP_URL_HOST)] + $hit);
        }
    } catch (Exception $e) {}
}

list($body, $finalUrl) = lpFetch($url);
$data = lpParse($body, $finalUrl ?: $url);

// Пустую карточку не показываем, но запоминаем — чтобы не ходить туда снова
if ($pdo) {
    try {
        $pdo->prepare("INSERT INTO link_previews (url_hash, url, title, description, image, fetched_at)
                       VALUES (?, ?, ?, ?, ?, NOW())
                       ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description),
                                               image = VALUES(image), fetched_at = VALUES(fetched_at)")
            ->execute([$key, $url, $data['title'], $data['description'], $data['image']]);
    } catch (Exception $e) {}
}

if ($data['title'] === '' && $data['description'] === '' && $data['image'] === '') {
    lpOut(['ok' => false, 'error' => 'Нечего показать']);
}
lpOut(['ok' => true, 'url' => $url, 'site' => parse_url($url, PHP_URL_HOST)] + $data);
