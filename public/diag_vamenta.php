<?php
require_once __DIR__ . '/db_connect.php';

echo "<pre>\n";
// 1. Stations
echo "=== STATIONS ===\n";
$st = $pdo->query("SELECT id, name, location, address FROM stations")->fetchAll(PDO::FETCH_ASSOC);
print_r($st);

// Find Vamenta station ID
$vamenta_id = null;
foreach ($st as $s) {
    if (stripos($s['name'], 'vamenta') !== false || stripos($s['location'], 'vamenta') !== false || stripos($s['address'], 'vamenta') !== false) {
        $vamenta_id = $s['id'];
        break;
    }
}
if (!$vamenta_id && !empty($st)) {
    $vamenta_id = $st[0]['id'];
}
echo "VAMENTA ID: " . var_export($vamenta_id, true) . "\n\n";

// 2. Fuel Types
echo "=== FUEL TYPES ===\n";
try {
    $ft = $pdo->query("SELECT * FROM fuel_types")->fetchAll(PDO::FETCH_ASSOC);
    print_r($ft);
} catch (Exception $e) { echo "No fuel_types table: " . $e->getMessage() . "\n"; }

// 3. Fuel Inventory for Vamenta
echo "=== FUEL INVENTORY (Vamenta station_id=$vamenta_id) ===\n";
try {
    $fi = $pdo->prepare("SELECT * FROM fuel_inventory WHERE station_id = ?");
    $fi->execute([$vamenta_id]);
    print_r($fi->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) { echo $e->getMessage() . "\n"; }

// 4. Fuel Pumps for Vamenta
echo "=== FUEL PUMPS (Vamenta station_id=$vamenta_id) ===\n";
try {
    $fp = $pdo->prepare("SELECT * FROM fuel_pumps WHERE station_id = ? ORDER BY id ASC");
    $fp->execute([$vamenta_id]);
    $pumps = $fp->fetchAll(PDO::FETCH_ASSOC);
    print_r($pumps);
} catch (Exception $e) { echo $e->getMessage() . "\n"; }

// 5. Nozzles for Vamenta
echo "=== NOZZLES (Vamenta station_id=$vamenta_id) ===\n";
try {
    $nz = $pdo->prepare("SELECT * FROM nozzles WHERE station_id = ? ORDER BY id ASC");
    $nz->execute([$vamenta_id]);
    print_r($nz->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) { echo $e->getMessage() . "\n"; }

echo "</pre>";
