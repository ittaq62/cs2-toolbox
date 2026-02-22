<?php
// get_inventory.php
// Répond un JSON: [ { "name": "...", "market_hash_name": "...", "amount": 3,
//                     "type": "case"|"souvenir"|"sticker", "needs_key": true/false,
//                     "image": "https://..." }, ... ]

header('Content-Type: application/json; charset=utf-8');

$defaultSteamId = '76561199055485964';
$steamId = isset($_GET['steamid']) && $_GET['steamid'] !== '' ? $_GET['steamid'] : $defaultSteamId;

$appId     = '730';
$contextId = '2';
$lang      = 'french';
$count     = 2000;

$url = "https://steamcommunity.com/inventory/$steamId/$appId/$contextId?l=$lang&count=$count";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
]);
$raw  = curl_exec($ch);
$err  = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($raw === false || $code >= 400) {
    http_response_code(502);
    echo json_encode(['error' => 'steam_fetch_failed', 'details' => ($err ?: "HTTP $code")], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(500);
    echo json_encode(['error' => 'invalid_json_from_steam'], JSON_UNESCAPED_UNICODE);
    exit;
}

$descIndex = [];
if (!empty($data['descriptions']) && is_array($data['descriptions'])) {
    foreach ($data['descriptions'] as $d) {
        $ck = $d['classid']   ?? null;
        $ik = $d['instanceid']?? '0';
        if ($ck) {
            $descIndex[$ck . '_' . $ik] = $d;
        }
    }
}

/**
 * Catégorise un item CS2 par son market_hash_name (anglais).
 */
function categorize(string $marketName): ?string {
    $lower = mb_strtolower($marketName);

    // Capsules de stickers / autographes
    if (str_contains($lower, 'capsule')) {
        return 'sticker';
    }

    // Souvenir Packages uniquement
    if (str_contains($lower, 'souvenir package')) {
        return 'souvenir';
    }

    // Caisses classiques ("Case")
    if (preg_match('/\b(case)\b/', $lower)) {
        return 'case';
    }

    return null;
}

$counts = [];

if (!empty($data['assets']) && is_array($data['assets'])) {
    foreach ($data['assets'] as $a) {
        $ck = $a['classid']   ?? null;
        $ik = $a['instanceid']?? '0';
        if (!$ck) continue;

        $desc = $descIndex[$ck . '_' . $ik] ?? null;
        if (!$desc) continue;

        $marketName = $desc['market_hash_name'] ?? null;
        $displayName = $desc['name'] ?? $marketName;
        if (!$marketName) continue;

        $type = categorize($marketName);
        if ($type === null) continue;

        $amt = isset($a['amount']) ? (int)$a['amount'] : 1;
        $amt = max(1, $amt);

        $icon = $desc['icon_url'] ?? null;
        $imageUrl = $icon ? "https://community.akamai.steamstatic.com/economy/image/$icon" : null;

        if (!isset($counts[$marketName])) {
            $counts[$marketName] = [
                'display_name'     => $displayName,
                'market_hash_name' => $marketName,
                'amount'           => 0,
                'type'             => $type,
                'needs_key'        => ($type === 'case'),
                'image'            => $imageUrl,
            ];
        }
        $counts[$marketName]['amount'] += $amt;
    }
}

$out = [];
foreach ($counts as $info) {
    $out[] = [
        'name'             => $info['display_name'],
        'market_hash_name' => $info['market_hash_name'],
        'amount'           => $info['amount'],
        'type'             => $info['type'],
        'needs_key'        => $info['needs_key'],
        'image'            => $info['image'],
    ];
}

usort($out, fn($x, $y) => $y['amount'] <=> $x['amount']);

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
