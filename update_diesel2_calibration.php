<?php
// Set calibration values for ALL 17 pumps with July 2, 2026 date
require_once __DIR__ . '/public/db_connect.php';

try {
    $pdo->beginTransaction();
    
    // Get all active stations
    $stmt = $pdo->query("SELECT id, name FROM stations WHERE status = 'Active'");
    $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Define calibration values for each pump
    $pump_calibrations = [
        'DIESEL 1 - 1' => 10.00,
        'DIESEL 1 - 2' => 10.00,
        'DIESEL 1 - 3' => 5.00,
        'DIESEL 1 - 4' => 5.00,
        'DIESEL 2 - 5' => 100.00,
        'DIESEL 2 - 6' => 100.00,
        'KEROSENE - 1' => 8.00,
        'TURBO DIESEL - 1' => 12.00,
        'TURBO DIESEL - 2' => 12.00,
        'XCS PLUS - 1' => 15.00,
        'XCS PLUS - 2' => 15.00,
        'XCS PLUS - 3' => 15.00,
        'XCS PLUS - 4' => 15.00,
        'XTRA UNL 1 - 1' => 20.00,
        'XTRA UNL 1 - 2' => 20.00,
        'XTRA UNL 2 - 3' => 20.00,
        'XTRA UNL 2 - 4' => 20.00,
    ];
    
    // Get Edgar Eslit user ID for calibration_updated_by
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = 'edgar' OR CONCAT(first_name, ' ', last_name) = 'Edgar Eslit' LIMIT 1");
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $user_id = $user ? $user['id'] : 1; // Default to user ID 1 if not found
    
    $updated_count = 0;
    
    foreach ($stations as $station) {
        $station_id = $station['id'];
        $station_name = $station['name'];
        
        echo "<strong>Station: {$station_name} (ID: {$station_id})</strong><br>";
        
        foreach ($pump_calibrations as $pump_name => $calibration) {
            $stmt = $pdo->prepare("
                UPDATE fuel_pumps 
                SET calibration_value = ?,
                    calibration_updated_at = '2026-07-02 08:00:00',
                    calibration_updated_by = ?,
                    calibration_notes = 'Initial calibration setup'
                WHERE station_id = ? 
                AND pump_number = ?
            ");
            $stmt->execute([$calibration, $user_id, $station_id, $pump_name]);
            
            if ($stmt->rowCount() > 0) {
                $updated_count++;
                echo "✅ {$pump_name}: {$calibration} L<br>";
            }
        }
        echo "<br>";
    }
    
    $pdo->commit();
    
    echo "<br><strong style='color:green;'>✅ SUCCESS!</strong> Updated {$updated_count} pump(s) with calibration values dated July 2, 2026<br>";
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "❌ Error: " . $e->getMessage();
}
?>
