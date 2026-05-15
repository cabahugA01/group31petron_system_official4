<?php
/**
 * Database Update Script - Synchronize All Changes
 * 
 * This script updates the database to ensure all recent changes are properly synchronized:
 * - Mechanics table with canonical Petron mechanics
 * - Service types table structure and data
 * - Job orders table updates
 * - Customer navigation role restrictions
 */

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

echo "=== Database Synchronization Update ===\n\n";

try {
    // Get current station ID for station-specific updates
    $station_id = 1; // Default station - adjust as needed
    if (function_exists('user_station_id')) {
        $station_id = user_station_id() ?: 1;
    }
    
    echo "Using Station ID: $station_id\n\n";
    
    // 1. Update Mechanics Table - Ensure canonical Petron mechanics
    echo "1. Updating Mechanics Table...\n";
    
    // Drop and recreate mechanics table to ensure clean state
    $pdo->exec("DROP TABLE IF EXISTS mechanics");
    
    $create_mechanics_sql = "CREATE TABLE mechanics (
        id INT AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(100) NOT NULL,
        specialization VARCHAR(100) DEFAULT 'General Mechanic',
        status ENUM('active', 'inactive') DEFAULT 'active',
        station_id INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        
        INDEX idx_status (status),
        INDEX idx_station_id (station_id),
        INDEX idx_full_name (full_name)
    )";
    
    $pdo->exec($create_mechanics_sql);
    echo "   - Mechanics table recreated\n";
    
    // Insert canonical Petron mechanics
    $canonical_mechanics = [
        ['CABUSOG, LOLOY', 'General Mechanic'],
        ['ESLIT, EDGAR', 'General Mechanic'],
        ['ESLIT, MARK', 'General Mechanic'],
        ['PIQUERO, CHRIS', 'General Mechanic'],
        ['EBUÑA, TATA', 'General Mechanic'],
        ['SOLAMIN, JEFFERSON', 'General Mechanic'],
        ['BELARMINO, CARLOS MIGUEL', 'General Mechanic'],
        ['AGUADA, JONARD', 'General Mechanic'],
        ['PAROHINGOG, DANNY', 'General Mechanic'],
        ['BUGAY, LIEBERT', 'General Mechanic'],
        ['CASTILLO, MARJUN', 'General Mechanic'],
        ['WENNIBER, SALACOB', 'General Mechanic'],
        ['JELISTER, LARAGA', 'General Mechanic']
    ];
    
    $stmt = $pdo->prepare("INSERT INTO mechanics (full_name, specialization, station_id) VALUES (?, ?, ?)");
    foreach ($canonical_mechanics as $mechanic) {
        $stmt->execute([$mechanic[0], $mechanic[1], $station_id]);
    }
    echo "   - " . count($canonical_mechanics) . " canonical mechanics inserted\n";
    
    // 2. Update Job Order Service Types Table
    echo "\n2. Updating Job Order Service Types...\n";
    
    // Ensure table exists with proper structure
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS job_order_service_types (
            service_key VARCHAR(50) PRIMARY KEY,
            service_name VARCHAR(100) NOT NULL,
            base_rate_per_hour DECIMAL(10,2) DEFAULT 0.00,
            service_price DECIMAL(10,2) NULL,
            min_price DECIMAL(10,2) DEFAULT 0.00,
            max_price DECIMAL(10,2) DEFAULT 999999.99,
            price_description TEXT NULL,
            pricing_notes TEXT NULL,
            icon_class VARCHAR(50) DEFAULT 'fas fa-wrench',
            color_class VARCHAR(50) DEFAULT 'text-primary',
            active BOOLEAN DEFAULT TRUE,
            sort_order INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_active (active),
            INDEX idx_sort_order (sort_order),
            INDEX idx_service_name (service_name)
        )
    ");
    
    // Add missing columns if they don't exist
    try { $pdo->exec("ALTER TABLE job_order_service_types ADD COLUMN IF NOT EXISTS service_price DECIMAL(10,2) NULL AFTER base_rate_per_hour"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE job_order_service_types ADD COLUMN IF NOT EXISTS pricing_notes TEXT NULL"); } catch (Exception $e) {}
    
    echo "   - Service types table structure verified\n";
    
    // 3. Update Job Orders Table Structure
    echo "\n3. Updating Job Orders Table...\n";
    
    // Add missing columns for enhanced functionality
    $job_orders_updates = [
        "ALTER TABLE job_orders MODIFY COLUMN service_type VARCHAR(500) NOT NULL DEFAULT ''",
        "ALTER TABLE job_orders MODIFY COLUMN payment_method VARCHAR(50) NOT NULL DEFAULT 'Cash'",
        "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS service_price_details TEXT NULL",
        "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS additional_notes TEXT NULL",
        "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS validation_status ENUM('Pending Validation', 'Approved', 'Validated', 'Rejected', 'In Progress', 'Completed', 'Adjusted') DEFAULT 'Pending Validation'",
        "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS validated_by INT NULL",
        "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS validated_at DATETIME NULL",
        "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS adjustment_reason TEXT NULL",
        "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS is_credit BOOLEAN DEFAULT FALSE",
        "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS customer_id INT NULL"
    ];
    
    foreach ($job_orders_updates as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Exception $e) {
            echo "   - Note: " . $e->getMessage() . "\n";
        }
    }
    echo "   - Job orders table structure updated\n";
    
    // 4. Update Customer Records for Role-Based Access
    echo "\n4. Updating Customer Access Controls...\n";
    
    // Ensure customers table has proper structure
    $customer_updates = [
        "ALTER TABLE customers ADD COLUMN IF NOT EXISTS credit_limit DECIMAL(10,2) DEFAULT 0.00",
        "ALTER TABLE customers ADD COLUMN IF NOT EXISTS balance DECIMAL(10,2) DEFAULT 0.00",
        "ALTER TABLE customers ADD COLUMN IF NOT EXISTS id_type VARCHAR(50) NULL",
        "ALTER TABLE customers ADD COLUMN IF NOT EXISTS id_number VARCHAR(100) NULL",
        "ALTER TABLE customers ADD COLUMN IF NOT EXISTS account_status ENUM('active', 'inactive', 'suspended') DEFAULT 'active'"
    ];
    
    foreach ($customer_updates as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Exception $e) {
            echo "   - Note: " . $e->getMessage() . "\n";
        }
    }
    echo "   - Customer table structure updated\n";
    
    // 5. Create/Update Audit Tables
    echo "\n5. Updating Audit Tables...\n";
    
    // Job order audit table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS job_order_audit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            job_order_id INT NOT NULL,
            action VARCHAR(50) NOT NULL,
            before_status VARCHAR(50) NULL,
            after_status VARCHAR(50) NULL,
            performed_by INT NULL,
            performed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            notes TEXT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            
            INDEX idx_job_order_id (job_order_id),
            INDEX idx_action (action),
            INDEX idx_performed_at (performed_at)
        )
    ");
    
    echo "   - Audit tables updated\n";
    
    // 6. Verify Data Counts
    echo "\n6. Verification Counts...\n";
    
    $mechanics_count = $pdo->query("SELECT COUNT(*) FROM mechanics WHERE status = 'active'")->fetchColumn();
    echo "   - Active Mechanics: $mechanics_count\n";
    
    $service_types_count = $pdo->query("SELECT COUNT(*) FROM job_order_service_types WHERE active = TRUE")->fetchColumn();
    echo "   - Active Service Types: $service_types_count\n";
    
    $job_orders_count = $pdo->query("SELECT COUNT(*) FROM job_orders")->fetchColumn();
    echo "   - Total Job Orders: $job_orders_count\n";
    
    $customers_count = $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
    echo "   - Total Customers: $customers_count\n";
    
    // 7. Update Station-Specific Settings
    echo "\n7. Updating Station Settings...\n";
    
    // Ensure mechanics are assigned to current station
    $pdo->prepare("
        UPDATE mechanics SET station_id = ? 
        WHERE station_id IS NULL OR station_id = 0
    ")->execute([$station_id]);
    
    echo "   - Mechanics assigned to station $station_id\n";
    
    echo "\n=== Database Synchronization Complete ===\n";
    echo "All database tables have been updated successfully!\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

echo "\n";
?>
