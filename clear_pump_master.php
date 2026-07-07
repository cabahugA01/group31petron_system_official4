<?php
require_once 'public/db_connect.php';

$station_id = 1253;

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');

// 1. Delete all pump records for this station from fuel_pumps
$stmt = $pdo->prepare("DELETE FROM fuel_pumps WHERE station_id = ?");
$stmt->execute([$station_id]);
echo "fuel_pumps deleted for station $station_id: " . $stmt->rowCount() . " rows\n";

// 2. Delete from pump_configuration for this station
$stmt = $pdo->prepare("DELETE FROM pump_configuration WHERE station_id = ?");
$stmt->execute([$station_id]);
echo "pump_configuration deleted for station $station_id: " . $stmt->rowCount() . " rows\n";

// 3. Delete from station_pump_assignment for this station
$stmt = $pdo->prepare("DELETE FROM station_pump_assignment WHERE station_id = ?");
$stmt->execute([$station_id]);
echo "station_pump_assignment deleted for station $station_id: " . $stmt->rowCount() . " rows\n";

// 4. Clear pump_calibration_history for this station (already empty but just in case)
try {
    $stmt = $pdo->prepare("DELETE FROM pump_calibration_history WHERE station_id = ?");
    $stmt->execute([$station_id]);
    echo "pump_calibration_history deleted for station $station_id: " . $stmt->rowCount() . " rows\n";
} catch(Exception $e) {
    echo "pump_calibration_history: " . $e->getMessage() . "\n";
}

// 5. Reset calibration values on fuel_inventory for this station
$stmt = $pdo->prepare("UPDATE fuel_inventory SET calibration_value = NULL, latest_calibration = 0 WHERE station_id = ?");
$stmt->execute([$station_id]);
echo "fuel_inventory calibration reset for station $station_id: " . $stmt->rowCount() . " rows\n";

$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

echo "\nDone! All pump master data cleared for station $station_id.\n";
echo "You can now re-enter pump assignments fresh from the Pump Master page.\n";

unlink(__FILE__);
