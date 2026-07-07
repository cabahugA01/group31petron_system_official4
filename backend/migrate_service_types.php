<?php
/**
 * Migration Script: Set all existing service types to ACTIVE
 * Run this once to update all existing service types
 */
require_once __DIR__ . '/../public/db_connect.php';

try {
    // Update all service types to active status
    $stmt = $pdo->prepare("UPDATE job_order_service_types SET status = 'active', active = 1");
    $stmt->execute();
    $count = $stmt->rowCount();
    
    echo "✅ Successfully updated $count service type(s) to ACTIVE status.\n";
    echo "All services are now active with Deactivate buttons.\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
