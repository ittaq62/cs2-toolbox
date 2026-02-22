<?php
// get_inventory.php
// Répond un JSON: [ { "name": "...", "amount": 3, "type": "case"|"souvenir"|"sticker" }, ... ]

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

function categorize(string $name): ?string {
    $lower = mb_strtolower($name);

    // Capsules de stickers / autographes (contient "Capsule")
    if (str_contains($lower, 'capsule')) {
        return 'sticker';
    }

    // Souvenir Packages uniquement (pas les skins souvenir)
    if (str_contains($lower, 'souvenir package')) {
        return 'souvenir';
    }

    // Caisses classiques uniquement ("Case" ou "Caisse")
    if (preg_match('/\b(case|caisse)\b/', $lower)) {
        return 'case';
    }

    return null; // graffitis, charms, pins, skins, stickers individuels → ignorés
}

$counts = [];

if (!empty($data['assets']) && is_array($data['assets'])) {
    foreach ($data['assets'] as $a) {
        $ck = $a['classid']   ?? null;
        $ik = $a['instanceid']?? '0';
        if (!$ck) continue;

        $desc = $descIndex[$ck . '_' . $ik] ?? null;
        if (!$desc) continue;

        $name = $desc['market_hash_name'] ?? ($desc['name'] ?? null);
        if (!$name) continue;

        $type = categorize($name);
        if ($type === null) continue;

        $amt = isset($a['amount']) ? (int)$a['amount'] : 1;
        $amt = max(1, $amt);

        if (!isset($counts[$name])) {
            $counts[$name] = ['amount' => 0, 'type' => $type];
        }
        $counts[$name]['amount'] += $amt;
    }
}

$out = [];
foreach ($counts as $n => $info) {
    $out[] = [
        'name'   => $n,
        'amount' => $info['amount'],
        'type'   => $info['type'],
    ];
}

usort($out, fn($x, $y) => $y['amount'] <=> $x['amount']);

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
