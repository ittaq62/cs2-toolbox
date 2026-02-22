<?php
header('Content-Type: application/json; charset=utf-8');

$credFile = __DIR__ . '/credentials.json';
if (!file_exists($credFile)) {
    http_response_code(500);
    echo json_encode(['error' => 'credentials.json not found']);
    exit;
}
$creds = json_decode(file_get_contents($credFile), true);

function getAccessToken(array $creds): ?string {
    $now = time();
    $header = base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claims = base64url_encode(json_encode([
        'iss'   => $creds['client_email'],
        'scope' => 'https://www.googleapis.com/auth/spreadsheets.readonly https://www.googleapis.com/auth/drive.readonly',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));
    $toSign = "$header.$claims";
    $signature = '';
    $key = openssl_pkey_get_private($creds['private_key']);
    if (!$key) return null;
    openssl_sign($toSign, $signature, $key, OPENSSL_ALGO_SHA256);
    $jwt = "$toSign." . base64url_encode($signature);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    return $data['access_token'] ?? null;
}

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

$tokenFile = sys_get_temp_dir() . '/gsheet_token.json';
$token = null;
if (file_exists($tokenFile)) {
    $cached = json_decode(file_get_contents($tokenFile), true);
    if (($cached['exp'] ?? 0) > time()) $token = $cached['token'];
}
if (!$token) {
    $token = getAccessToken($creds);
    if (!$token) {
        http_response_code(500);
        echo json_encode(['error' => 'auth_failed']);
        exit;
    }
    file_put_contents($tokenFile, json_encode(['token' => $token, 'exp' => time() + 3000]));
}

function gapi(string $url, string $token): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token"],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp, true);
}

function isNumericCell(string $val): bool {
    $val = trim($val);
    if ($val === '') return false;
    return is_numeric(str_replace(',', '.', $val));
}

function bgToHex(?array $bg): ?string {
    if (!$bg) return null;
    $r = (int)(($bg['red']   ?? 0) * 255);
    $g = (int)(($bg['green'] ?? 0) * 255);
    $b = (int)(($bg['blue']  ?? 0) * 255);
    // Skip white, near-white, black, near-black (default backgrounds)
    if ($r > 235 && $g > 235 && $b > 235) return null;
    if ($r < 20  && $g < 20  && $b < 20)  return null;
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

// ========================
// MODE 1: Lister les spreadsheets
// ========================
if (!isset($_GET['id'])) {
    $url = 'https://www.googleapis.com/drive/v3/files?'
         . http_build_query([
             'q'        => "mimeType='application/vnd.google-apps.spreadsheet'",
             'fields'   => 'files(id,name,modifiedTime)',
             'orderBy'  => 'name',
             'pageSize' => 100,
         ]);
    $data = gapi($url, $token);
    echo json_encode($data['files'] ?? [], JSON_UNESCAPED_UNICODE);
    exit;
}

// ========================
// MODE 2: Lire un spreadsheet (avec couleurs)
// ========================
$sheetId = $_GET['id'];

// Utiliser spreadsheets.get pour récupérer valeurs + couleurs de fond
$url = "https://sheets.googleapis.com/v4/spreadsheets/$sheetId?"
     . http_build_query([
         'ranges' => 'A1:Z200',
         'fields' => 'sheets.data.rowData.values(effectiveValue,effectiveFormat.backgroundColor)',
     ]);

$data = gapi($url, $token);

// Extraire les lignes et couleurs
$rows = [];
$rowColors = []; // couleur de fond de la première cellule (colonne A)

$rowData = $data['sheets'][0]['data'][0]['rowData'] ?? [];
foreach ($rowData as $ri => $rd) {
    $row = [];
    $color = null;
    $cells = $rd['values'] ?? [];
    foreach ($cells as $ci => $cell) {
        // Valeur
        $val = '';
        if (isset($cell['effectiveValue']['stringValue'])) {
            $val = $cell['effectiveValue']['stringValue'];
        } elseif (isset($cell['effectiveValue']['numberValue'])) {
            $val = (string)$cell['effectiveValue']['numberValue'];
        } elseif (isset($cell['effectiveValue']['boolValue'])) {
            $val = $cell['effectiveValue']['boolValue'] ? 'TRUE' : 'FALSE';
        }
        $row[] = $val;

        // Couleur de la première cellule
        if ($ci === 0) {
            $color = bgToHex($cell['effectiveFormat']['backgroundColor'] ?? null);
        }
    }
    $rows[] = $row;
    $rowColors[] = $color;
}

if (empty($rows)) {
    echo json_encode(['error' => 'empty_sheet']);
    exit;
}

$result = [
    'id'       => $sheetId,
    'items'    => [],
    'total'    => 0,
    'rarities' => [],
];

// Detect column layout from first data row
$nameColCount = 1;
for ($i = 1; $i < count($rows); $i++) {
    $cell0 = strtolower(trim($rows[$i][0] ?? ''));
    if ($cell0 === '' || $cell0 === 'total') continue;
    $nameColCount = 0;
    for ($c = 0; $c < count($rows[$i]); $c++) {
        if (isNumericCell($rows[$i][$c] ?? '')) break;
        $nameColCount++;
    }
    if ($nameColCount < 1) $nameColCount = 1;
    break;
}

$dropCol = $nameColCount;
$pctCol  = $nameColCount + 1;

$inRarities = false;
foreach ($rows as $i => $row) {
    if ($i === 0) continue;
    $firstCell = strtolower(trim($row[0] ?? ''));
    if ($firstCell === '') continue;

    if ($firstCell === 'total' && !$inRarities) {
        $totalVal = 0;
        if (isset($row[$dropCol]) && isNumericCell($row[$dropCol])) {
            $totalVal = (int)$row[$dropCol];
        } elseif (isset($row[1]) && isNumericCell($row[1])) {
            $totalVal = (int)$row[1];
        }
        $result['total'] = $totalVal;
        $inRarities = true;
        continue;
    }

    if ($inRarities) {
        if ($firstCell === 'total') break;
        $rarityName  = trim($row[0] ?? '');
        $rarityValue = 0;
        if (isset($row[1]) && isNumericCell($row[1])) {
            $rarityValue = floatval(str_replace(',', '.', $row[1]));
        }
        if ($rarityName !== '') {
            // Couleur de la rareté = couleur de fond de cette ligne
            $result['rarities'][] = [
                'name'    => $rarityName,
                'percent' => round($rarityValue, 2),
                'color'   => $rowColors[$i],
            ];
        }
        continue;
    }

    // Items
    $drops = 0;
    if (isset($row[$dropCol]) && isNumericCell($row[$dropCol])) {
        $drops = (int)$row[$dropCol];
    }
    $pct = 0;
    if (isset($row[$pctCol]) && isNumericCell($row[$pctCol])) {
        $pct = round(floatval(str_replace(',', '.', $row[$pctCol])), 2);
    }

    $nameParts = [];
    for ($c = 0; $c < $nameColCount; $c++) {
        $part = trim($row[$c] ?? '');
        if ($part !== '') $nameParts[] = $part;
    }
    $name = implode(' | ', $nameParts);
    if ($name === '') continue;

    $result['items'][] = [
        'name'  => $name,
        'drops' => $drops,
        'pct'   => $pct,
        'color' => $rowColors[$i],
    ];
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
