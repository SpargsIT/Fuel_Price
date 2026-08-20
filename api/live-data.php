<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$root = dirname(__DIR__);
$basePath = $root . '/data/forecast.json';
$cachePath = $root . '/data/live.json';
$lockPath = $root . '/data/live.lock';
$ttlSeconds = 1800; // 30 minutes
$force = isset($_GET['force']) && $_GET['force'] === '1';

function json_response(array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function read_json_file(string $path): ?array {
    if (!is_file($path)) return null;
    $raw = @file_get_contents($path);
    if ($raw === false) return null;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function fetch_url(string $url, int $timeout = 15): array {
    $headers = [
        'User-Agent: Mozilla/5.0 (compatible; SpargsFuelGasUpdater/1.0; +https://www.spargs.com)',
        'Accept: text/html,application/xhtml+xml,application/json,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: en-ZA,en;q=0.9',
        'Cache-Control: no-cache',
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 7,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        return [
            'ok' => $body !== false && $status >= 200 && $status < 400,
            'status' => $status,
            'body' => is_string($body) ? $body : '',
            'error' => $error,
            'url' => $finalUrl ?: $url,
        ];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'ignore_errors' => true,
            'header' => implode("\r\n", $headers),
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) $status = (int)$m[1];
        }
    }
    return [
        'ok' => $body !== false && $status >= 200 && $status < 400,
        'status' => $status,
        'body' => is_string($body) ? $body : '',
        'error' => $body === false ? 'HTTP fetch failed' : '',
        'url' => $url,
    ];
}


/** Fetch independent public sources concurrently so one slow publisher does not
 * hold the whole dashboard hostage. The sequential fetch_url() path remains as
 * a compatibility fallback on hosts without curl_multi. */
function fetch_many(array $urls, int $timeout = 10): array {
    if (!function_exists('curl_multi_init')) {
        // Full live mode requires cURL. Do not make a non-cURL host wait through
        // many sequential network timeouts; fail fast and retain verified data.
        $out = [];
        foreach ($urls as $key => $url) {
            $out[$key] = ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'PHP cURL extension is not installed', 'url' => $url];
        }
        return $out;
    }

    $headers = [
        'User-Agent: Mozilla/5.0 (compatible; SpargsFuelGasUpdater/1.1; +https://www.spargs.com)',
        'Accept: text/html,application/xhtml+xml,application/json,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: en-ZA,en;q=0.9',
        'Cache-Control: no-cache',
    ];
    $mh = curl_multi_init();
    $handles = [];
    foreach ($urls as $key => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = ['handle' => $ch, 'url' => $url];
    }

    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($status !== CURLM_OK) break;
        if ($running > 0) {
            $selected = curl_multi_select($mh, 1.0);
            if ($selected === -1) usleep(100000);
        }
    } while ($running > 0);

    $out = [];
    foreach ($handles as $key => $item) {
        $ch = $item['handle'];
        $body = curl_multi_getcontent($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = curl_error($ch);
        $out[$key] = [
            'ok' => is_string($body) && $status >= 200 && $status < 400,
            'status' => $status,
            'body' => is_string($body) ? $body : '',
            'error' => $error,
            'url' => $finalUrl ?: $item['url'],
        ];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

function html_text(string $html): string {
    $html = preg_replace('~<script\b[^>]*>.*?</script>~is', ' ', $html) ?? $html;
    $html = preg_replace('~<style\b[^>]*>.*?</style>~is', ' ', $html) ?? $html;
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('~[\x{00A0}\s]+~u', ' ', $text) ?? $text;
    return trim($text);
}

function first_wednesday(int $year, int $month): DateTimeImmutable {
    $d = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), new DateTimeZone('Africa/Johannesburg'));
    while ($d->format('N') !== '3') $d = $d->modify('+1 day');
    return $d;
}

function parse_topauto_adjustment(string $html): ?array {
    $text = html_text($html);
    $needlePos = stripos($text, 'Indicative');
    if ($needlePos === false) return null;
    $window = substr($text, $needlePos, 600);

    $value = null;
    if (preg_match('~Indicative\s+[A-Za-z]+\s+\d{4}\s+adjustment\s*(?:R\s*)?([+-]?\s*\d+(?:[.,]\d+)?)~i', $window, $m)) {
        $value = (float)str_replace([' ', ','], ['', '.'], $m[1]);
    } elseif (preg_match('~adjustment\s*(?:R\s*)?([+-]?\s*\d+(?:[.,]\d+)?)~i', $window, $m)) {
        $value = (float)str_replace([' ', ','], ['', '.'], $m[1]);
    }
    if ($value === null) return null;

    if (stripos($window, 'Decrease') !== false && $value > 0) $value *= -1;
    if (stripos($window, 'Increase') !== false && $value < 0) $value *= -1;

    $reporting = '';
    if (preg_match('~Reporting period\s+(.{4,80}?)(?=\s+(?:[A-Z][a-z]+\s+\d{4}\s+price|Exchange-rate impact|Product-price impact))~i', $text, $m)) {
        $reporting = trim($m[1]);
    }
    return ['value' => round($value, 4), 'reportingPeriod' => $reporting];
}

function parse_json_body(array $response): ?array {
    if (!$response['ok']) return null;
    $decoded = json_decode($response['body'], true);
    return is_array($decoded) ? $decoded : null;
}

function source_health(string $name, bool $ok, string $message, string $url): array {
    return ['name' => $name, 'ok' => $ok, 'message' => $message, 'url' => $url];
}

function apply_forecast_value(array &$data, string $key, float $value, string $sourceDate, string $sourceLabel): void {
    $range = match ($key) {
        'p95', 'p93' => 0.48,
        'd500', 'd50' => 0.82,
        'paraffin' => 0.90,
        default => 0.60,
    };
    foreach ($data['forecast'] as &$row) {
        if (($row['key'] ?? '') !== $key) continue;
        $row['cef'] = $value;
        $row['bfpModel'] = $value;
        $row['allIn'] = $value;
        $row['bfpLow'] = round($value - $range, 4);
        $row['allLow'] = round($value - $range, 4);
        $row['bfpHigh'] = round($value + $range, 4);
        $row['allHigh'] = round($value + $range, 4);
        $row['topAuto'] = $value;
        $row['topAutoDate'] = $sourceDate;
        $direction = $value < 0 ? 'decrease' : ($value > 0 ? 'increase' : 'flat result');
        $row['reason'] = sprintf(
            'Automatic update from %s reports an indicative %s of R%.2f/l. This remains a live forecast and the final DMPR announcement overrides it.',
            $sourceLabel,
            $direction,
            abs($value)
        );
        break;
    }
    unset($row);
}


function date_rank_from_text(string $text): ?int {
    if (preg_match_all('~([0-9]{1,2}\s+[A-Za-z]+\s+20[0-9]{2})~', $text, $m) && !empty($m[1])) {
        $ts = strtotime(end($m[1]));
        return $ts !== false ? $ts : null;
    }
    $ts = strtotime($text);
    return $ts !== false ? $ts : null;
}

function parse_google_news_response(array $response, string $category, int $limit = 5): array {
    if (!$response['ok']) return ['ok' => false, 'url' => $response['url'] ?? '', 'items' => [], 'error' => $response['error'] ?: ('HTTP ' . ($response['status'] ?? 0))];
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($response['body'], 'SimpleXMLElement', LIBXML_NOCDATA);
    if (!$xml || !isset($xml->channel->item)) return ['ok' => false, 'url' => $response['url'] ?? '', 'items' => [], 'error' => 'Invalid RSS'];
    $items = [];
    foreach ($xml->channel->item as $item) {
        $title = trim((string)$item->title);
        $link = trim((string)$item->link);
        $dateRaw = trim((string)$item->pubDate);
        $description = html_text((string)$item->description);
        if ($title === '' || $link === '') continue;
        $items[] = [
            'title' => $title,
            'source' => 'Automatic web news',
            'date' => $dateRaw !== '' ? date('d M Y', strtotime($dateRaw)) : date('d M Y'),
            'category' => $category,
            'score' => 72,
            'direction' => 'neutral',
            'credibility' => 'Live news feed; open the source for full context',
            'url' => $link,
            'summary' => mb_substr($description !== '' ? $description : $title, 0, 360),
        ];
        if (count($items) >= $limit) break;
    }
    return ['ok' => count($items) > 0, 'url' => $response['url'] ?? '', 'items' => $items, 'error' => ''];
}

function parse_lpg_cp_from_rss(array $response): ?array {
    if (!$response['ok']) return null;
    $text = html_text($response['body']);
    $propane = null;
    $butane = null;
    $patternsPropane = [
        '~propane.{0,120}?(?:US\$|\$)?\s*([0-9]{3,4})(?:\s*(?:/|per)\s*(?:t|tonne|metric ton))?~i',
        '~(?:US\$|\$)\s*([0-9]{3,4}).{0,80}?propane~i',
    ];
    $patternsButane = [
        '~butane.{0,120}?(?:US\$|\$)?\s*([0-9]{3,4})(?:\s*(?:/|per)\s*(?:t|tonne|metric ton))?~i',
        '~(?:US\$|\$)\s*([0-9]{3,4}).{0,80}?butane~i',
    ];
    foreach ($patternsPropane as $pattern) if (preg_match($pattern, $text, $m)) { $propane = (float)$m[1]; break; }
    foreach ($patternsButane as $pattern) if (preg_match($pattern, $text, $m)) { $butane = (float)$m[1]; break; }
    if ($propane === null || $butane === null || $propane < 200 || $propane > 2000 || $butane < 200 || $butane > 2000) return null;
    return ['propane' => $propane, 'butane' => $butane];
}

function fetch_google_news(string $query, string $category, int $limit = 5): array {
    $url = 'https://news.google.com/rss/search?q=' . rawurlencode($query) . '&hl=en-ZA&gl=ZA&ceid=ZA:en';
    $response = fetch_url($url, 15);
    if (!$response['ok']) return ['ok' => false, 'url' => $url, 'items' => [], 'error' => $response['error'] ?: ('HTTP ' . $response['status'])];

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($response['body'], 'SimpleXMLElement', LIBXML_NOCDATA);
    if (!$xml || !isset($xml->channel->item)) return ['ok' => false, 'url' => $url, 'items' => [], 'error' => 'Invalid RSS'];

    $items = [];
    foreach ($xml->channel->item as $item) {
        $title = trim((string)$item->title);
        $link = trim((string)$item->link);
        $dateRaw = trim((string)$item->pubDate);
        $description = html_text((string)$item->description);
        if ($title === '' || $link === '') continue;
        $items[] = [
            'title' => $title,
            'source' => 'Automatic web news',
            'date' => $dateRaw !== '' ? date('d M Y', strtotime($dateRaw)) : date('d M Y'),
            'category' => $category,
            'score' => 72,
            'direction' => 'neutral',
            'credibility' => 'Live news feed; open the source for full context',
            'url' => $link,
            'summary' => mb_substr($description !== '' ? $description : $title, 0, 360),
        ];
        if (count($items) >= $limit) break;
    }
    return ['ok' => count($items) > 0, 'url' => $url, 'items' => $items, 'error' => ''];
}

$base = read_json_file($basePath);
if (!$base) json_response(['ok' => false, 'error' => 'The packaged forecast data file is missing or invalid.'], 500);

if (!$force && is_file($cachePath) && (time() - (int)filemtime($cachePath)) < $ttlSeconds) {
    $cached = read_json_file($cachePath);
    if ($cached && isset($cached['data']) && (($cached['mode'] ?? '') === 'automatic-web-update')) {
        $cached['cache'] = 'fresh';
        json_response($cached);
    }
}

$lock = @fopen($lockPath, 'c+');
if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
    $cached = read_json_file($cachePath);
    if ($cached && isset($cached['data'])) {
        $cached['cache'] = 'refresh-in-progress';
        json_response($cached);
    }
}

$data = $base;
$health = [];
$now = new DateTimeImmutable('now', new DateTimeZone('Africa/Johannesburg'));
$data['snapshotDate'] = $now->format(DateTimeInterface::ATOM);
$data['modelVersion'] = '2026.08-auto-live';

// Fetch all independent public sources concurrently. This normally completes in
// under the slowest single-source timeout instead of adding every timeout together.
$periodStart = first_wednesday((int)$now->format('Y'), (int)$now->format('n'));
$period = $periodStart->format('Y-m-d');
$slugs = [
    'p93' => 'petrol_93',
    'p95' => 'petrol_95',
    'd500' => 'diesel_005',
    'd50' => 'diesel_0005',
    'paraffin' => 'illuminating_paraffin',
];
$urls = [
    'fx_primary' => 'https://open.er-api.com/v6/latest/USD',
    'fx_fallback' => 'https://api.frankfurter.app/latest?from=USD&to=ZAR',
    'brent' => 'https://query1.finance.yahoo.com/v8/finance/chart/BZ%3DF?range=5d&interval=1d',
    'dmpr' => 'https://www.dmpr.gov.za/Services/Petroleum-Resources/Fuel-Prices',
    'cef_archive' => 'https://cefgroup.co.za/2026-4/',
    'news_fuel' => 'https://news.google.com/rss/search?q=' . rawurlencode('South Africa CEF fuel price petrol diesel forecast when:7d') . '&hl=en-ZA&gl=ZA&ceid=ZA:en',
    'news_lpg' => 'https://news.google.com/rss/search?q=' . rawurlencode('South Africa LPG price Saudi propane butane freight when:30d') . '&hl=en-ZA&gl=ZA&ceid=ZA:en',
    'lpg_cp' => 'https://news.google.com/rss/search?q=' . rawurlencode('Reuters Saudi Aramco LPG contract price propane butane ' . $now->format('F Y')) . '&hl=en-ZA&gl=ZA&ceid=ZA:en',
];
foreach ($slugs as $key => $slug) {
    $urls['topauto_' . $key] = 'https://topauto.co.za/fuel-price-predictor/?fuel=' . rawurlencode($slug) . '&period=' . rawurlencode($period);
}
$responses = fetch_many($urls, 10);

// 1) USD/ZAR: two independent no-key public endpoints; first valid result wins.
$fx = null;
$fxUrl = $urls['fx_primary'];
$fxJson = parse_json_body($responses['fx_primary']);
if ($fxJson && isset($fxJson['rates']['ZAR'])) $fx = (float)$fxJson['rates']['ZAR'];
if ($fx === null) {
    $fxUrl = $urls['fx_fallback'];
    $fxJson = parse_json_body($responses['fx_fallback']);
    if ($fxJson && isset($fxJson['rates']['ZAR'])) $fx = (float)$fxJson['rates']['ZAR'];
}
if ($fx !== null && $fx > 5 && $fx < 40) {
    $data['market']['usdZar'] = round($fx, 4);
    $data['market']['usdZarDate'] = $now->format('d M Y H:i') . ' SAST auto';
    $data['lpg']['modelUsdZar'] = round($fx, 4);
    $health[] = source_health('USD/ZAR live rate', true, 'Updated to R' . number_format($fx, 4), $fxUrl);
} else {
    $health[] = source_health('USD/ZAR live rate', false, 'Using verified packaged fallback', $fxUrl);
}

// 2) Brent is context; finished-product markers and CEF recoveries remain primary.
$brentJson = parse_json_body($responses['brent']);
$brent = null;
if ($brentJson && isset($brentJson['chart']['result'][0]['indicators']['quote'][0]['close'])) {
    foreach (array_reverse($brentJson['chart']['result'][0]['indicators']['quote'][0]['close']) as $close) {
        if (is_numeric($close)) { $brent = (float)$close; break; }
    }
}
if ($brent !== null && $brent > 10 && $brent < 250) {
    $data['market']['brent'] = round($brent, 2);
    $data['market']['brentDate'] = $now->format('d M Y H:i') . ' SAST auto context';
    $health[] = source_health('Brent market context', true, '$' . number_format($brent, 2) . '/bbl', $urls['brent']);
} else {
    $health[] = source_health('Brent market context', false, 'Using verified packaged fallback', $urls['brent']);
}

// 3) Secondary predictor pages. Never overwrite a newer official packaged CEF anchor
// with an older secondary snapshot.
$forecastUpdated = 0;
$forecastSkippedStale = 0;
$latestReporting = '';
$officialRank = date_rank_from_text((string)($data['officialCefDate'] ?? '')) ?? 0;
foreach ($slugs as $key => $slug) {
    $response = $responses['topauto_' . $key];
    $parsed = $response['ok'] ? parse_topauto_adjustment($response['body']) : null;
    if (!$parsed || !is_numeric($parsed['value']) || abs((float)$parsed['value']) >= 15) continue;
    $sourceDate = $parsed['reportingPeriod'] !== '' ? $parsed['reportingPeriod'] : '';
    $sourceRank = $sourceDate !== '' ? (date_rank_from_text($sourceDate) ?? 0) : 0;
    if ($sourceRank === 0 || $sourceRank < $officialRank) { $forecastSkippedStale++; continue; }
    apply_forecast_value($data, $key, (float)$parsed['value'], $sourceDate, 'TopAuto/CEF predictor');
    $latestReporting = $sourceDate;
    $forecastUpdated++;
}
if ($forecastUpdated > 0) {
    $data['officialCefDate'] = $latestReporting !== '' ? $latestReporting : $data['officialCefDate'];
    // Keep the final chart label aligned with the newest accepted CEF period.
    if ($latestReporting !== '' && isset($data['projectionPath']['dates']) && is_array($data['projectionPath']['dates'])) {
        if (preg_match_all('~(\d{1,2})\s+([A-Za-z]+)\s+(20\d{2})~', $latestReporting, $dateMatches, PREG_SET_ORDER)) {
            $lastMatch = $dateMatches[count($dateMatches) - 1];
            $periodEnd = DateTimeImmutable::createFromFormat('!j F Y', $lastMatch[1] . ' ' . $lastMatch[2] . ' ' . $lastMatch[3]);
            if ($periodEnd instanceof DateTimeImmutable) {
                $lastDateIndex = count($data['projectionPath']['dates']) - 1;
                if ($lastDateIndex >= 0) $data['projectionPath']['dates'][$lastDateIndex] = $periodEnd->format('j M');
            }
        }
    }
    $health[] = source_health('CEF predictor by grade', $forecastUpdated >= 4, $forecastUpdated . '/5 grades refreshed; official-date guard active', 'https://topauto.co.za/fuel-price-predictor/');
} else {
    $msg = $forecastSkippedStale > 0 ? 'Secondary values were older than the official packaged CEF anchor' : 'No valid new grade values found';
    $health[] = source_health('CEF predictor by grade', false, $msg . '; verified fallback retained', 'https://topauto.co.za/fuel-price-predictor/');
}

// Keep the projected path aligned to the latest headline values.
if (isset($data['projectionPath']['series'])) {
    foreach ($data['forecast'] as $row) {
        $key = $row['key'];
        if (!isset($data['projectionPath']['series'][$key]) || !is_array($data['projectionPath']['series'][$key])) continue;
        $last = count($data['projectionPath']['series'][$key]) - 1;
        if ($last >= 0) $data['projectionPath']['series'][$key][$last] = $row['allIn'];
    }
}

// 4) Official publication health. Exact forecast values stay anchored to the newest
// dated official CEF report already verified in the package unless a newer valid
// secondary daily value is obtained.
$dmprResponse = $responses['dmpr'];
if ($dmprResponse['ok']) {
    $dmprText = html_text($dmprResponse['body']);
    $published = '';
    if (preg_match('~Fuel Prices Effective from\s+([0-9]{1,2}\s+[A-Za-z]+\s+20[0-9]{2})~i', $dmprText, $m)) $published = $m[1];
    $health[] = source_health('DMPR official fuel publications', true, $published !== '' ? ('Latest monthly page: ' . $published) : 'Official page reachable', $urls['dmpr']);
} else {
    $health[] = source_health('DMPR official fuel publications', false, 'Official page not reachable during this refresh', $urls['dmpr']);
}
$cefResponse = $responses['cef_archive'];
$cefArchiveMessage = 'Archive unavailable; packaged official CEF anchor retained';
$cefArchiveOk = false;
if ($cefResponse['ok']) {
    $cefArchiveOk = true;
    $cefArchiveText = html_text($cefResponse['body']);
    $latestArchiveDate = '';
    if (preg_match_all('~([0-9]{1,2})[-/]([0-9]{1,2})[-/](20[0-9]{2})~', $cefArchiveText, $cefDates, PREG_SET_ORDER)) {
        $bestRank = 0;
        foreach ($cefDates as $cd) {
            $candidate = sprintf('%04d-%02d-%02d', (int)$cd[3], (int)$cd[2], (int)$cd[1]);
            $rank = strtotime($candidate);
            if ($rank !== false && $rank > $bestRank) { $bestRank = $rank; $latestArchiveDate = $candidate; }
        }
    }
    if ($latestArchiveDate !== '') {
        $anchorRank = strtotime((string)($data['officialCefDate'] ?? '')) ?: 0;
        $archiveRank = strtotime($latestArchiveDate) ?: 0;
        if ($archiveRank > $anchorRank && $forecastUpdated === 0) {
            $cefArchiveOk = false;
            $cefArchiveMessage = 'Newer CEF daily report exists (' . $latestArchiveDate . ') but headline values were not parsed; do not treat fallback as current';
        } else {
            $cefArchiveMessage = 'Latest archive date ' . $latestArchiveDate . '; headline anchor ' . ($data['officialCefDate'] ?? 'unknown');
        }
    } else {
        $cefArchiveMessage = 'Daily report archive reachable; latest date could not be parsed';
    }
}
$health[] = source_health('CEF daily report archive', $cefArchiveOk, $cefArchiveMessage, $urls['cef_archive']);

// 5) Live Fuel and LPG news feeds. Curated direct-source items stay as fallback.
$fuelNews = parse_google_news_response($responses['news_fuel'], 'Fuel news', 5);
$lpgNews = parse_google_news_response($responses['news_lpg'], 'Gas / LPG news', 5);
$liveNews = array_merge($fuelNews['items'], $lpgNews['items']);
if (count($liveNews) > 0) {
    $curated = array_values(array_filter($data['news'] ?? [], static fn($n) => ($n['source'] ?? '') !== 'Automatic web news'));
    $data['news'] = array_slice(array_merge($liveNews, $curated), 0, 28);
}
$health[] = source_health('Fuel news feed', $fuelNews['ok'], $fuelNews['ok'] ? count($fuelNews['items']) . ' live stories' : 'Curated fallback active', $fuelNews['url']);
$health[] = source_health('Gas/LPG news feed', $lpgNews['ok'], $lpgNews['ok'] ? count($lpgNews['items']) . ' live stories' : 'Curated fallback active', $lpgNews['url']);

// 6) Monthly Saudi LPG CP discovery. If a valid new propane/butane pair is found,
// the weighted 60/40 benchmark and transparent scenario are recalculated.
$cpParsed = parse_lpg_cp_from_rss($responses['lpg_cp']);
if ($cpParsed) {
    $propane = $cpParsed['propane'];
    $butane = $cpParsed['butane'];
    $weighted = round(0.6 * $propane + 0.4 * $butane, 2);
    $previousWeighted = (float)($data['lpg']['previousCp']['weighted'] ?? $weighted);
    $data['lpg']['currentCp'] = ['month' => $now->format('F Y') . ' auto', 'propane' => $propane, 'butane' => $butane, 'weighted' => $weighted];
    $data['lpg']['cpDeltaUsdPerTonne'] = round($weighted - $previousWeighted, 2);
    $health[] = source_health('Saudi LPG CP discovery', true, 'Propane $' . number_format($propane, 0) . '/t · butane $' . number_format($butane, 0) . '/t', $urls['lpg_cp']);
} else {
    $health[] = source_health('Saudi LPG CP discovery', false, 'No safely parseable new CP pair; verified monthly anchor retained', $urls['lpg_cp']);
}

// Recalculate the LPG raw benchmark conversion whenever FX changes. The CP itself remains source-labelled.
$cpDelta = (float)($data['lpg']['cpDeltaUsdPerTonne'] ?? 0);
$modelFx = (float)($data['lpg']['modelUsdZar'] ?? $data['market']['usdZar'] ?? 0);
if ($cpDelta !== 0.0 && $modelFx > 0) {
    $data['lpg']['rawBenchmarkChangeRandKg'] = round(($cpDelta * $modelFx) / 1000, 2);
    $data['lpg']['summary'] = sprintf(
        'Saudi CP remains the direct LPG anchor. The current weighted CP move is USD %.0f/t, equal to about R%.2f/kg at USD/ZAR %.4f before freight, insurance, storage, financing, margins, VAT and official rounding. The displayed LPG retail forecast remains a model, not a CEF daily value.',
        $cpDelta,
        abs((float)$data['lpg']['rawBenchmarkChangeRandKg']),
        $modelFx
    );
}
// Rebuild the transparent LPG scenario from the weighted CP move and live FX.
$raw = (float)($data['lpg']['rawBenchmarkChangeRandKg'] ?? 0);
$central = round($raw - 0.12, 2); // transparent packaged landed/local central adjustment
$best = round($central - 0.60, 2);
$worst = round($central + 0.80, 2);
$currentLpg = $data['lpg']['current'] ?? ['coastal' => 0, 'inland' => 0, 'saldanha' => 0];
foreach (['best' => $best, 'central' => $central, 'worst' => $worst] as $case => $change) {
    $data['lpg']['forecast'][$case]['change'] = $change;
    foreach (['coastal', 'inland', 'saldanha'] as $zone) {
        $data['lpg']['forecast'][$case][$zone] = round((float)$currentLpg[$zone] + $change, 2);
    }
}
$health[] = source_health('Saudi LPG CP model', true, '60/40 CP benchmark and FX conversion recalculated; final official price still overrides the model', $urls['lpg_cp']);

$payload = [
    'ok' => true,
    'generatedAt' => $now->format(DateTimeInterface::ATOM),
    'mode' => 'automatic-web-update',
    'cacheSeconds' => $ttlSeconds,
    'data' => $data,
    'health' => $health,
];

$tmp = $cachePath . '.tmp';
$encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
if ($encoded !== false) {
    @file_put_contents($tmp, $encoded, LOCK_EX);
    @rename($tmp, $cachePath);
}

if ($lock) {
    flock($lock, LOCK_UN);
    fclose($lock);
}

json_response($payload);
