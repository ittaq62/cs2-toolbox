<?php
/**
 * get_prices.php — Steam Market price fetcher
 * 
 * GET ?names=Item1,Item2,...  → fetch prices
 * GET ?debug=1               → diagnostic
 * GET ?clear_cache=1         → purge cache
 * 
 * Steam rate limit: ~20 req/min. Delay: 3s between fetches.
 * Cache: 1h success, 3min failure.
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

set_time_limit(300); // 5 min max pour grosses listes

$cacheDir = sys_get_temp_dir() . '/steam_prices_v3';
if (!is_dir($cacheDir)) mkdir($cacheDir, 0777, true);

$CACHE_TTL     = 3600;   // 1h succès
$NEG_CACHE_TTL = 180;    // 3min échec

// === DEBUG ===
if (isset($_GET['debug'])) {
    $files = glob("$cacheDir/*.json");
    $items = [];
    $ok = 0; $fail = 0;
    foreach ($files as $f) {
        $d = json_decode(file_get_contents($f), true);
        if ($d['price'] !== null) $ok++; else $fail++;
        $d['_age_s'] = time() - ($d['_ts'] ?? 0);
        $items[] = $d;
    }
    echo json_encode([
        'cache_dir' => $cacheDir, 'total' => count($files),
        'with_price' => $ok, 'failed' => $fail,
        'php' => phpversion(), 'curl' => function_exists('curl_init'),
        'items' => $items,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// === CLEAR CACHE ===
if (isset($_GET['clear_cache'])) {
    $files = glob("$cacheDir/*.json");
    foreach ($files as $f) unlink($f);
    echo json_encode(['cleared' => count($files)]);
    exit;
}

// === FETCH PRICES ===
$namesParam = $_GET['names'] ?? '';
if ($namesParam === '') { echo json_encode(['error' => 'missing names']); exit; }

$names = array_filter(array_map('trim', explode(',', $namesParam)));
if (empty($names)) { echo json_encode(['error' => 'empty']); exit; }

function steamFetch(string $name): array {
    $url = 'https://steamcommunity.com/market/priceoverview/?'
        . http_build_query(['appid' => 730, 'currency' => 3, 'market_hash_name' => $name]);
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) return ['price' => null, 'error' => "curl: $err"];
    if ($code === 429)  return ['price' => null, 'error' => 'rate_limited'];
    if ($code !== 200)  return ['price' => null, 'error' => "http_$code"];

    $data = json_decode($raw, true);
    if (!is_array($data) || !($data['success'] ?? false))
        return ['price' => null, 'error' => 'api_fail'];

    $str = $data['lowest_price'] ?? $data['median_price'] ?? null;
    if (!$str) return ['price' => null, 'error' => 'no_price'];

    $clean = str_replace(',', '.', preg_replace('/[^0-9,.]/', '', $str));
    return ['price' => round(floatval($clean), 2), 'currency' => '€'];
}

$results   = [];
$toFetch   = [];
$DELAY_US  = 3000000;  // 3 secondes — respecte le rate limit Steam

// Phase 1 : vérifier le cache
foreach ($names as $name) {
    $file = "$cacheDir/" . md5($name) . ".json";
    if (file_exists($file)) {
        $c = json_decode(file_get_contents($file), true);
        if (is_array($c)) {
            $age = time() - ($c['_ts'] ?? 0);
            $ttl = ($c['price'] !== null) ? $CACHE_TTL : $NEG_CACHE_TTL;
            if ($age < $ttl) {
                $results[$name] = ['price' => $c['price'], 'currency' => '€', 'cached' => true];
                continue;
            }
        }
    }
    $toFetch[] = $name;
}

// Phase 2 : fetch les manquants (avec retry sur rate_limit)
$retries = [];
foreach ($toFetch as $i => $name) {
    if ($i > 0) usleep($DELAY_US);
    
    $r = steamFetch($name);
    
    // Si rate limited, on met de côté pour retry
    if (($r['error'] ?? '') === 'rate_limited') {
        $retries[] = $name;
        continue;
    }
    
    // Cache
    $c = ['price' => $r['price'], 'currency' => '€', '_ts' => time(), '_name' => $name];
    if (isset($r['error'])) $c['error'] = $r['error'];
    @file_put_contents("$cacheDir/" . md5($name) . ".json", json_encode($c));
    
    $results[$name] = ['price' => $r['price'], 'currency' => '€', 'cached' => false];
    if (isset($r['error'])) $results[$name]['error'] = $r['error'];
}

// Phase 3 : retry les rate_limited après 10s
if (!empty($retries)) {
    sleep(10);
    foreach ($retries as $i => $name) {
        if ($i > 0) usleep($DELAY_US);
        $r = steamFetch($name);
        $c = ['price' => $r['price'], 'currency' => '€', '_ts' => time(), '_name' => $name];
        if (isset($r['error'])) $c['error'] = $r['error'];
        @file_put_contents("$cacheDir/" . md5($name) . ".json", json_encode($c));
        $results[$name] = ['price' => $r['price'], 'currency' => '€', 'cached' => false];
        if (isset($r['error'])) $results[$name]['error'] = $r['error'];
    }
}

echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
