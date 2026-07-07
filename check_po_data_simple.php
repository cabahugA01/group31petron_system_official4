<?php
require_once __DIR__ . '/backend/lib.php';
require_once __DIR__ . '/public/db_connect.php';
require_login();  $station_id = user_station_id();  echo "<pre>";
echo "=== PURCHASE ORDERS DATA CHECK ===\n\n";
echo "Station ID: $station_id\n\n";  // Check merchandise POs
echo "1. MERCHANDISE PURCHASE ORDERS:\n";
$stmt = $pdo->prepare("SELECT id, po_number, product_name, quantity, status, created_at FROM purchase_orders WHERE station_id = ? AND type='merch' ORDER BY created_at DESC LIMIT 15");
$stmt->execute([$station_id]);
$merch_pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Found " . count($merch_pos) . " merchandise POs\n";
foreach ($merch_pos as $po) {  echo "  ID: {$po['id']} | PO: {$po['po_number']} | {$po['product_name']} | Qty: {$po['quantity']} | Status: {$po['status']} | Date: {$po['created_at']}\n";
}  echo "\n2. FUEL PURCHASE ORDERS:\n";
$stmt = $pdo->prepare("SELECT fpo.id, fpo.po_number, ft.name as fuel_name, fpo.volume, fpo.status, fpo.created_at FROM fuel_purchase_orders fpo LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id WHERE fpo.station_id = ? ORDER BY fpo.created_at DESC LIMIT 15");
$stmt->execute([$station_id]);
$fuel_pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Found " . count($fuel_pos) . " fuel POs\n";
foreach ($fuel_pos as $po) {  echo "  ID: {$po['id']} | PO: {$po['po_number']} | {$po['fuel_name']} | Volume: {$po['volume']} | Status: {$po['status']} | Date: {$po['created_at']}\n";
}  echo "\n=== SUMMARY ===\n";
echo "Total Merchandise POs: " . count($merch_pos) . "\n";
echo "Total Fuel POs: " . count($fuel_pos) . "\n";  if (count($merch_pos) >= 12 && count($fuel_pos) >= 4) {  echo "\n DATA LOOKS CORRECT! Admin should see 12 merchandise and 4 fuel POs.\n";
} else {  echo "\nEXPECTED: 12 merchandise + 4 fuel POs\n";  echo "FOUND: " . count($merch_pos) . " merchandise + " . count($fuel_pos) . " fuel POs\n";
}  echo "</pre>";
