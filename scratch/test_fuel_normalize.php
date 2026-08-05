<?php
require_once __DIR__ . '/../public/db_connect.php';

$sql = "SELECT DISTINCT fuel_type FROM fuel_transactions WHERE station_id = 1253";
$raw_types = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);

echo "=== RAW FUEL TYPES IN fuel_transactions ===\n";
print_r($raw_types);

echo "\n=== MAPPED TO 5 CORE PETRON FUEL CATEGORIES ===\n";
function normalizeFuelType($raw) {
    $u = strtoupper(trim($raw));
    if (str_contains($u, 'TURBO DIESEL')) return 'Turbo Diesel';
    if (str_contains($u, 'DIESEL')) return 'Diesel';
    if (str_contains($u, 'XCS')) return 'XCS Plus';
    if (str_contains($u, 'XTRA') || str_contains($u, 'UNL')) return 'Xtra Advance';
    if (str_contains($u, 'KEROSENE')) return 'Kerosene';
    return preg_replace('/\\s*[0-9]+.*$/', '', $raw);
}

$mapped = array_unique(array_map('normalizeFuelType', $raw_types));
print_r(array_values($mapped));
