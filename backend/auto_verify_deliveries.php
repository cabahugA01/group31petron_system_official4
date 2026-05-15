<?php
/**
 * Auto Verify Pending Fuel Deliveries
 * 
 * This script automatically verifies pending fuel deliveries that are older than 24 hours
 * This helps fix the "Volume In = 0" issue in reconciliation reports
 */

require_once 'lib.php';
require_once '../public/db_connect.php';

echo "<h2>Auto-Verifying Pending Fuel Deliveries</h2>";

// Get deliveries that are pending and older than 24 hours
$threshold_date = date('Y-m-d H:i:s', strtotime('-24 hours'));

try {
    $stmt = $pdo->prepare("
        SELECT fd.*, s.name as station_name
        FROM fuel_deliveries fd
        LEFT JOIN stations s ON fd.station_id = s.id
        WHERE fd.status IN ('Encoded', 'Pending Review') 
        AND fd.created_at < ?
        ORDER BY fd.delivery_date DESC, fd.created_at DESC
    ");
    $stmt->execute([$threshold_date]);
    $pending_deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $verified_count = 0;
    $skipped_count = 0;
    
    foreach ($pending_deliveries as $delivery) {
        // Auto-verify the delivery
        try {
            $pdo->beginTransaction();
            
            // Update delivery status to Verified
            $stmt = $pdo->prepare("
                UPDATE fuel_deliveries 
                SET status = 'Verified', 
                    verified_by = 1, -- System admin ID
                    verified_at = NOW(),
                    notes = CONCAT(COALESCE(notes, ''), '\n[AUTO-VERIFIED: ', ?, ']')
                WHERE id = ?
            ");
            
            $auto_note = "Auto-verified by system on " . date('Y-m-d H:i:s') . " (older than 24 hours)";
            $stmt->execute([$auto_note, $delivery['id']]);
            
            // Find fuel product and update inventory
            $stmt = $pdo->prepare("
                SELECT id FROM products 
                WHERE type_id = 1 
                AND (name LIKE ? OR sku LIKE ?)
                LIMIT 1
            ");
            $stmt->execute(["%{$delivery['fuel_type']}%", "%{$delivery['fuel_type']}%"]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($product) {
                // Update station inventory
                $stmt = $pdo->prepare("
                    UPDATE station_inventory 
                    SET stock_level = stock_level + ? 
                    WHERE station_id = ? AND product_id = ?
                ");
                $stmt->execute([$delivery['delivery_liters'], $delivery['station_id'], $product['id']]);
                
                // Log the inventory change
                log_fuel_inventory_action(
                    $pdo,
                    1, // System admin
                    'delivery_auto_verified',
                    'fuel_delivery',
                    $delivery['id'],
                    $delivery['station_id'],
                    $product['id'],
                    [
                        'fuel_type' => $delivery['fuel_type'],
                        'delivery_liters' => $delivery['delivery_liters'],
                        'auto_verified' => true,
                        'verification_time' => date('Y-m-d H:i:s')
                    ]
                );
            }
            
            $pdo->commit();
            
            $verified_count++;
            echo "<p style='color: green;'>✓ Auto-verified delivery #{$delivery['id']}: {$delivery['delivery_liters']}L of {$delivery['fuel_type']} at {$delivery['station_name']}</p>";
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $skipped_count++;
            echo "<p style='color: red;'>✗ Failed to verify delivery #{$delivery['id']}: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
    
    echo "<h3>Summary:</h3>";
    echo "<p style='color: green;'>✓ Auto-verified deliveries: {$verified_count}</p>";
    echo "<p style='color: blue;'>ℹ Failed to verify: {$skipped_count}</p>";
    
    if ($verified_count > 0) {
        echo "<p><strong>Note:</strong> These deliveries were automatically verified because they were pending for more than 24 hours.</p>";
        echo "<p><a href='../public/reconciliation.php'>View Updated Reconciliation Reports</a></p>";
    } else {
        echo "<p style='color: blue;'>ℹ No pending deliveries older than 24 hours found.</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
