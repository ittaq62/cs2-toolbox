<?php
// accounts.php
header('Content-Type: application/json; charset=utf-8');

$jsonFile = __DIR__ . '/accounts.json';

$defaults = [
    ['steamId' => '76561199055485964', 'label' => 'Dublatic'],
    ['steamId' => '76561199652580615', 'label' => 'Livies'],
    ['steamId' => '76561199065020540', 'label' => 'Henebus'],
];

if (!file_exists($jsonFile)) {
    file_put_contents($jsonFile, json_encode($defaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    echo file_get_contents($jsonFile);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_json']);
        exit;
    }

    $clean = [];
    foreach ($input as $item) {
        $id    = trim($item['steamId'] ?? '');
        $label = trim($item['label']   ?? '');
        if ($id === '' || $label === '') continue;
        $clean[] = ['steamId' => $id, 'label' => $label];
    }

    file_put_contents($jsonFile, json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo json_encode($clean, JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method_not_allowed']);
