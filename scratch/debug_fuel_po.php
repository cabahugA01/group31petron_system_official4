<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "<pre>\n";

// Check all fuel_purchase_orders
echo "=== ALL fuel_purchase_orders ===\n";
try {
    $rows = $pdo->query("SELECT id, po_number, station_id, fuel_type_id, volume, unit_price, total_amount, status, created_by, batch_id, notes, created_at FROM fuel_purchase_orders ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        print_r($r);
    }
    echo "Total: " . count($rows) . "\n";
} catch (Exception $e) { echo "Error: " . $e->getMessage() . "\n"; }

// Check all fuel_stock_requests
echo "\n=== ALL fuel_stock_requests ===\n";
try {
    $rows = $pdo->query("SELECT id, station_id, staff_id, fuel_type, status, approved_liters, manager_id FROM fuel_stock_requests ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        print_r($r);
    }
    echo "Total: " . count($rows) . "\n";
} catch (Exception $e) { echo "Error: " . $e->getMessage() . "\n"; }

// Check station IDs
echo "\n=== STATIONS ===\n";
try {
    $rows = $pdo->query("SELECT id, name FROM stations")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) { echo "Station #{$r['id']}: {$r['name']}\n"; }
} catch (Exception $e) { echo "Error: " . $e->getMessage() . "\n"; }

// Check admin user
echo "\n=== USERS (admin/manager) ===\n";
try {
    $rows = $pdo->query("SELECT id, name, role, station_id FROM users WHERE role IN ('admin','manager') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) { print_r($r); }
} catch (Exception $e) { echo "Error: " . $e->getMessage() . "\n"; }

echo "</pre>";
