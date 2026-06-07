<?php
/**
 * Bootstrap Script: Create fuel_adjustments table
 * Run this file once to fix the "Table 'secure_fuel_adjustments' doesn't exist" error
 */

require_once __DIR__ . '/public/db_connect.php';

try {
    // Create fuel_adjustments table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `fuel_adjustments` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `station_id` INT(11) NOT NULL,
            `fuel_type_id` INT(11) DEFAULT NULL,
            `fuel_type` VARCHAR(100) DEFAULT NULL COMMENT 'Fuel type name (fallback if fuel_type_id is NULL)',
            `adjustment_type` VARCHAR(100) NOT NULL COMMENT 'delivery, verified_sale, rejected_reading, adjusted_reading, daily_log_approved, daily_log_rejected, price_update, etc.',
            `liters` DECIMAL(12,3) NOT NULL DEFAULT 0.000 COMMENT 'Liters adjusted (positive=add, negative=subtract)',
            `reason` VARCHAR(255) DEFAULT NULL COMMENT 'Audit trail reason/notes',
            `user_id` INT(11) DEFAULT NULL COMMENT 'Manager/Admin who made the adjustment',
            `adjustment_date` DATE NOT NULL COMMENT 'Date of adjustment',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_station` (`station_id`),
            INDEX `idx_fuel_type` (`fuel_type_id`),
            INDEX `idx_adjustment_date` (`adjustment_date`),
            INDEX `idx_user` (`user_id`),
            INDEX `idx_adjustment_type` (`adjustment_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Fuel adjustment audit trail for Manager actions'
    ");
    
    echo "✅ SUCCESS: fuel_adjustments table created successfully!\n";
    echo "You can now use the Fuel Management module without errors.\n";
    
} catch (PDOException $e) {
    echo "❌ ERROR creating fuel_adjustments table:\n";
    echo $e->getMessage() . "\n";
    exit(1);
}
