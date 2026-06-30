<?php
/**
 * Create Fuel Pumps for Station 1
 * Creates 17 pumps distributed across the 5 fuel types
 */

require_once __DIR__ . '/../public/db_connect.php';

try {
    $pdo->beginTransaction();
    
    $station_id = 1;
    
    echo "Creating fuel pumps for Station 1...\n\n";
    
    // Get fuel types for Station 1
    $stmt = $pdo->prepare("
        SELECT id as fuel_inventory_id, fuel_type_id, fuel_type
        FROM fuel_inventory
        WHERE station_id = ?
        ORDER BY fuel_type
    ");
    $stmt->execute([$station_id]);
    $fuel_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($fuel_types) . " fuel types:\n";
    foreach ($fuel_types as $ft) {
        echo "- {$ft['fuel_type']} (Type ID: {$ft['fuel_type_id']})\n";
    }
    echo "\n";
    
    // Distribution of 17 pumps across 5 fuel types:
    // Diesel: 7 pumps (most common)
    // Turbo Diesel: 3 pumps
    // XCS: 3 pumps  
    // Xtra Advance: 3 pumps
    // Kerosene: 1 pump
    // Total: 17 pumps
    
    $pump_distribution = [
        'Diesel' => 7,
        'Turbo Diesel' => 3,
        'XCS' => 3,
        'Xtra Advance' => 3,
        'Kerosene' => 1
    ];
    
    $insert_stmt = $pdo->prepare("
        INSERT INTO fuel_pumps 
        (station_id, pump_number, fuel_type_id, capacity, status, created_at)
        VALUES (?, ?, ?, 0.00, 'Active', NOW())
    ");
    
    $pump_counter = 1;
    $total_created = 0;
    
    foreach ($fuel_types as $ft) {
        $fuel_type = $ft['fuel_type'];
        $fuel_type_id = $ft['fuel_type_id'];
        $num_pumps = $pump_distribution[$fuel_type] ?? 1;
        
        echo "Creating $num_pumps pump(s) for $fuel_type:\n";
        
        for ($i = 0; $i < $num_pumps; $i++) {
            $pump_number = (string)$pump_counter;
            $insert_stmt->execute([$station_id, $pump_number, $fuel_type_id]);
            echo "  - Pump #$pump_number ($fuel_type)\n";
            $pump_counter++;
            $total_created++;
        }
        echo "\n";
    }
    
    $pdo->commit();
    
    echo "SUCCESS! Created $total_created fuel pumps for Station 1\n\n";
    
    // Verify
    $verify_stmt = $pdo->prepare("
        SELECT fp.id, fp.pump_number, fp.fuel_type_id, ft.fuel_type
        FROM fuel_pumps fp
        LEFT JOIN fuel_inventory ft ON ft.fuel_type_id = fp.fuel_type_id AND ft.station_id = fp.station_id
        WHERE fp.station_id = ?
        ORDER BY CAST(fp.pump_number AS UNSIGNED)
    ");
    $verify_stmt->execute([$station_id]);
    $pumps = $verify_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Verification - Station 1 now has " . count($pumps) . " pumps:\n";
    foreach ($pumps as $pump) {
        echo "  - Pump #{$pump['pump_number']}: {$pump['fuel_type']}\n";
    }
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
