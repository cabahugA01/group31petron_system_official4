<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== PRODUCTS with unit LIKE '%pcs%' or '%set%' ===\n";
$rows = $pdo->query("SELECT id, sku, name, unit FROM products WHERE unit LIKE '%4%' OR unit LIKE '%set%' OR unit LIKE '%pc%'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo json_encode($r) . "\n";
}

echo "\n=== DISTINCT UNITS IN products ===\n";
$units = $pdo->query("SELECT DISTINCT unit FROM products")->fetchAll(PDO::FETCH_COLUMN);
foreach ($units as $u) {
    echo "- '$u'\n";
}

echo "\n=== DISTINCT UNITS IN station_inventory ===\n";
$si_units = $pdo->query("SELECT DISTINCT unit FROM station_inventory")->fetchAll(PDO::FETCH_COLUMN);
foreach ($si_units as $u) {
    echo "- '$u'\n";
}

echo "\n=== DISTINCT UNITS IN inventory_products ===\n";
try {
    $ip_units = $pdo->query("SELECT DISTINCT unit FROM inventory_products")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ip_units as $u) {
        echo "- '$u'\n";
    }
} catch (Exception $e) {}
