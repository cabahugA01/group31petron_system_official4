<?php
require_once __DIR__ . '/db_connect.php';

header('Content-Type: application/json');

$stations = $pdo->query("SELECT id, name, location, address, region FROM stations ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

$outliers = [];
foreach ($stations as $s) {
    $raw = $s['address'] ?: $s['name'];
    $parts = explode(',', $raw);
    $c = count($parts);
    if ($c <= 3 || $c >= 6) {
        $outliers[] = [
            'id' => $s['id'],
            'comma_count' => $c,
            'name' => $s['name'],
            'address' => $s['address'],
            'region' => $s['region']
        ];
    }
}

echo json_encode([
    'outlier_count' => count($outliers),
    'outliers' => $outliers
], JSON_PRETTY_PRINT);
