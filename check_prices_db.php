<?php
require_once __DIR__ . '/backend/lib.php';
require_once __DIR__ . '/public/db_connect.php';

$sid = 1253; // target station
echo "=== FUEL INVENTORY ===\n";
$stmt = $pdo->query("SELECT id, fuel_type, price_per_liter, fuel_type_id FROM fuel_inventory");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "FI ID: {$r['id']} | Type: {$r['fuel_type']} (ft_id: {$r['fuel_type_id']}) | Price: {$r['price_per_liter']}\n";
}

echo "\n=== FUEL PRICING ===\n";
$stmt = $pdo->query("SELECT fp.*, ft.name as ft_name FROM fuel_pricing fp JOIN fuel_types ft ON fp.fuel_type_id = ft.id");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "FP ID: {$r['id']} | Type: {$r['ft_name']} (ft_id: {$r['fuel_type_id']}) | Price: {$r['price_per_liter']} | active: {$r['is_active']}\n";
}
