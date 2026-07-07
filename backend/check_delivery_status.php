<?php
/**
 * Debug script to check fuel delivery statuses
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

try {
    echo "Checking fuel_deliveries table...\n";
    echo str_repeat("=", 80) . "\n";
    
    $stmt = $pdo->query("
        SELECT id, batch_id, fuel_type, delivery_liters, status, 
               LENGTH(status) as status_length,
               HEX(status) as status_hex,
               received_by, verified_by, created_at
        FROM fuel_deliveries 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($deliveries)) {
        echo "No deliveries found.\n";
    } else {
        foreach ($deliveries as $d) {
            echo "\nID: {$d['id']}\n";
            echo "Batch: {$d['batch_id']}\n";
            echo "Fuel: {$d['fuel_type']}\n";
            echo "Liters: {$d['delivery_liters']}\n";
            echo "Status: '{$d['status']}' (length: {$d['status_length']}, hex: {$d['status_hex']})\n";
            echo "Received by: {$d['received_by']}\n";
            echo "Verified by: {$d['verified_by']}\n";
            echo "Created: {$d['created_at']}\n";
            echo str_repeat("-", 40) . "\n";
        }
    }
    
    echo "\n" . str_repeat("=", 80) . "\n";
    echo "Total deliveries: " . count($deliveries) . "\n";
    
    // Count by status
    $counts = $pdo->query("
        SELECT status, COUNT(*) as count 
        FROM fuel_deliveries 
        GROUP BY status
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\nStatus Breakdown:\n";
    echo str_repeat("-", 40) . "\n";
    foreach ($counts as $c) {
        echo "'{$c['status']}': {$c['count']}\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
