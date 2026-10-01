<?php
require_once __DIR__ . '/db_connect.php';

// Simulate loading fuel products exactly as admin_set_prices.php does for station 1253
$target_sid = 1253;

$s = $pdo->prepare("SELECT id, fuel_type, ugt_no, current_level, current_stock, capacity, price_per_liter, latest_calibration, status, last_updated, reorder_level, critical_level, fuel_type_id FROM fuel_inventory WHERE station_id = ?");
$s->execute([$target_sid]);
$fi_raw = $s->fetchAll(PDO::FETCH_ASSOC);

// Preload pumps
$p_stmt = $pdo->prepare("SELECT id, tank_id, ugt_no, fuel_type_id, pump_number, pump_name, status FROM fuel_pumps WHERE station_id = ?");
$p_stmt->execute([$target_sid]);
$all_pumps = $p_stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<pre>\n";
echo "=== RAW FUEL INVENTORY (Station 1253) ===\n";
foreach ($fi_raw as $row) {
    echo "ID: {$row['id']} | Fuel Type: {$row['fuel_type']} | UGT: {$row['ugt_no']} | fuel_type_id: {$row['fuel_type_id']}\n";
}

echo "\n=== ALL PUMPS (Station 1253) ===\n";
foreach ($all_pumps as $p) {
    echo "ID: {$p['id']} | pump_number: '{$p['pump_number']}' | tank_id: '{$p['tank_id']}' | ugt_no: '{$p['ugt_no']}' | fuel_type_id: {$p['fuel_type_id']}\n";
}

echo "</pre>";
