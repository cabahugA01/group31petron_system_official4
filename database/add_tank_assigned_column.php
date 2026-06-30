<?php
/**
 * Add tank_assigned column to fuel_deliveries table
 */
require_once __DIR__ . '/../public/db_connect.php';

try {
    echo "Adding tank_assigned column to fuel_deliveries table...\n";
    
    // Check if column already exists
    $check = $pdo->query("SHOW COLUMNS FROM fuel_deliveries LIKE 'tank_assigned'")->fetch();
    
    if ($check) {
        echo "✓ Column 'tank_assigned' already exists.\n";
    } else {
        // Add the column
        $pdo->exec("ALTER TABLE fuel_deliveries ADD COLUMN tank_assigned VARCHAR(100) NULL AFTER delivery_liters");
        echo "✓ Column 'tank_assigned' added successfully!\n";
    }
    
    // Verify
    $verify = $pdo->query("SHOW COLUMNS FROM fuel_deliveries LIKE 'tank_assigned'")->fetch();
    if ($verify) {
        echo "✓ Column verified in table.\n";
        echo "  Type: {$verify['Type']}\n";
        echo "  Null: {$verify['Null']}\n";
        echo "  Default: " . ($verify['Default'] ?? 'NULL') . "\n";
    }
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nDone!\n";
