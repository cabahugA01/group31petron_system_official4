<?php
/**
 * Create Reconciliation Records Script
 * 
 * This script creates fuel reconciliation records from daily delivery and sales data
 * It should be run daily (cron job) to create records for investigation
 */

require_once 'lib.php';
require_once '../public/db_connect.php';

echo "<h2>Creating Fuel Reconciliation Records</h2>";

$yesterday = date('Y-m-d', strtotime('-1 day'));
$stations = [];

// Get all active stations
try {
    $stmt = $pdo->query("SELECT id, name FROM stations WHERE status = 'active'");
    $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    echo "<p style='color: red;'>Error fetching stations: " . htmlspecialchars($e->getMessage()) . "</p>";
    exit;
}

$created_count = 0;
$skipped_count = 0;

foreach ($stations as $station) {
    // Get all fuel types for this station
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT ft.name as fuel_type
            FROM fuel_types ft
            JOIN fuel_pumps fp ON ft.id = fp.fuel_type_id
            WHERE fp.station_id = ?
            ORDER BY ft.name
        ");
        $stmt->execute([$station['id']]);
        $fuel_types = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        echo "<p style='color: red;'>Error fetching fuel types for station {$station['name']}: " . htmlspecialchars($e->getMessage()) . "</p>";
        continue;
    }
    
    foreach ($fuel_types as $fuel_type) {
        // Check if reconciliation record already exists
        $stmt = $pdo->prepare("
            SELECT id FROM fuel_reconciliation 
            WHERE station_id = ? AND fuel_type = ? AND reconciliation_date = ?
        ");
        $stmt->execute([$station['id'], $fuel_type, $yesterday]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            $skipped_count++;
            continue;
        }
        
        // Calculate volume in (deliveries)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(delivery_liters), 0) as total_inflow
            FROM fuel_deliveries 
            WHERE station_id = ? AND fuel_type = ? AND delivery_date = ? 
            AND status IN ('Verified', 'Finalized')
        ");
        $stmt->execute([$station['id'], $fuel_type, $yesterday]);
        $volume_in = $stmt->fetch()['total_inflow'] ?? 0;
        
        // Calculate volume out (sales)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(dr.sales_liters), 0) as total_outflow
            FROM fuel_daily_readings dr 
            JOIN fuel_pumps fp ON dr.pump_id = fp.id 
            JOIN fuel_types ft ON fp.fuel_type_id = ft.id 
            WHERE dr.station_id = ? AND ft.name = ? AND dr.reading_date = ? 
            AND (dr.status = 'Verified' OR dr.status IS NULL OR dr.status = '')
        ");
        $stmt->execute([$station['id'], $fuel_type, $yesterday]);
        $volume_out = $stmt->fetch()['total_outflow'] ?? 0;
        
        // Calculate variance
        $variance = $volume_in - $volume_out;
        $variance_percent = $volume_in > 0 ? ($variance / $volume_in) * 100 : 0;
        
        // Determine status
        $status = 'Variance Alert';
        if (abs($variance_percent) <= 5) {
            $status = 'OK';
        }
        
        // Create reconciliation record
        try {
            $stmt = $pdo->prepare("
                INSERT INTO fuel_reconciliation (
                    station_id, reconciliation_date, fuel_type, 
                    opening_stock, deliveries, sales, variance, variance_percent,
                    status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $station['id'],
                $yesterday,
                $fuel_type,
                0, // opening_stock (calculated differently if needed)
                $volume_in,
                $volume_out,
                $variance,
                $variance_percent,
                $status
            ]);
            
            $created_count++;
            
            echo "<p style='color: green;'>✓ Created reconciliation record for {$station['name']} - {$fuel_type}: " .
                 "In: {$volume_in}L, Out: {$volume_out}L, Variance: {$variance}L ({$variance_percent}%)</p>";
            
        } catch (Exception $e) {
            echo "<p style='color: red;'>Error creating record for {$station['name']} - {$fuel_type}: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
}

echo "<h3>Summary:</h3>";
echo "<p style='color: green;'>✓ Records created: {$created_count}</p>";
echo "<p style='color: blue;'>ℹ Records skipped (already exist): {$skipped_count}</p>";

if ($created_count > 0) {
    echo "<p><a href='../public/reconciliation.php'>View Reconciliation Reports</a></p>";
}
?>
