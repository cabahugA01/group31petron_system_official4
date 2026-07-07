<?php
/**
 * Debug script to check user station assignments
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

try {
    echo "Checking user station assignments...\n";
    echo str_repeat("=", 80) . "\n";
    
    $stmt = $pdo->query("
        SELECT id, username, role, station_id
        FROM users 
        WHERE role IN ('staff', 'cashier', 'pump_attendant', 'manager')
        ORDER BY role, id
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($users as $u) {
        echo "\nID: {$u['id']}\n";
        echo "Username: {$u['username']}\n";
        echo "Role: {$u['role']}\n";
        echo "Station ID: {$u['station_id']}\n";
        echo str_repeat("-", 40) . "\n";
    }
    
    echo "\n" . str_repeat("=", 80) . "\n";
    
    // Check fuel_deliveries station_id values
    $delivery_stations = $pdo->query("
        SELECT DISTINCT station_id, COUNT(*) as count
        FROM fuel_deliveries
        GROUP BY station_id
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\nFuel Deliveries by Station:\n";
    echo str_repeat("-", 40) . "\n";
    foreach ($delivery_stations as $ds) {
        echo "Station ID {$ds['station_id']}: {$ds['count']} deliveries\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
