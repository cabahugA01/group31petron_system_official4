<?php
/**
 * Setup Customers Table
 * Creates the customers table with all required columns
 */

require_once __DIR__ . '/../public/db_connect.php';

echo "<h2>Setting up customers table...</h2>";

try {
    $pdo->beginTransaction();
    
    // Drop existing table if exists
    echo "<p>Dropping existing customers table (if exists)...</p>";
    $pdo->exec("DROP TABLE IF EXISTS customers");
    
    // Create customers table
    echo "<p>Creating customers table...</p>";
    $pdo->exec("
        CREATE TABLE `customers` (
            `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `customer_id` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Auto-generated customer ID (CUS-STATION-YYYYMM-###)',
            `station_id` INT(11) UNSIGNED NOT NULL,
            `first_name` VARCHAR(100) NOT NULL,
            `middle_name` VARCHAR(100) DEFAULT NULL,
            `last_name` VARCHAR(100) NOT NULL,
            `contact_number` VARCHAR(20) NOT NULL,
            `address` TEXT NOT NULL,
            `customer_type` ENUM('walk-in', 'regular', 'fleet') NOT NULL DEFAULT 'walk-in',
            `gov_id_type` VARCHAR(100) DEFAULT NULL COMMENT 'Government ID type',
            `gov_id_image` VARCHAR(255) DEFAULT NULL COMMENT 'Filename of uploaded ID',
            `cr_document` VARCHAR(255) DEFAULT NULL COMMENT 'Filename of Certificate of Registration',
            `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            `registered_by` INT(11) UNSIGNED NOT NULL COMMENT 'Staff who registered',
            `registered_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_by` INT(11) UNSIGNED DEFAULT NULL,
            `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            `notes` TEXT DEFAULT NULL,
            KEY `idx_customer_id` (`customer_id`),
            KEY `idx_station_id` (`station_id`),
            KEY `idx_customer_type` (`customer_type`),
            KEY `idx_status` (`status`),
            KEY `idx_contact` (`contact_number`),
            KEY `idx_registered_at` (`registered_at`),
            KEY `idx_name` (`first_name`, `last_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
        COMMENT='Customer management for fuel, merchandise, and services'
    ");
    
    echo "<p style='color:green;'>✓ Customers table created successfully!</p>";
    
    // Insert sample customers for testing
    echo "<p>Inserting sample customers...</p>";
    
    $sampleCustomers = [
        ['CUS-1253-202406-001', 1253, 'Juan', 'Santos', 'Dela Cruz', '0917-123-4567', 'Cagayan de Oro City', 'walk-in'],
        ['CUS-1253-202406-002', 1253, 'Maria', 'Angeles', 'Reyes', '0918-234-5678', 'Carmen, Misamis Oriental', 'regular'],
        ['CUS-1253-202406-003', 1253, 'Pedro', 'Jose', 'Garcia', '0919-345-6789', 'Vamenta Blvd, CDO', 'fleet'],
    ];
    
    $stmt = $pdo->prepare("
        INSERT INTO customers (
            customer_id, station_id, first_name, middle_name, last_name,
            contact_number, address, customer_type, status, registered_by, registered_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', 1, NOW())
    ");
    
    foreach ($sampleCustomers as $customer) {
        $stmt->execute($customer);
    }
    
    echo "<p style='color:green;'>✓ Inserted " . count($sampleCustomers) . " sample customers!</p>";
    
    // Verify table structure
    echo "<h3>Table Structure:</h3>";
    $columns = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_ASSOC);
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse;'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    foreach ($columns as $col) {
        echo "<tr>";
        echo "<td>{$col['Field']}</td>";
        echo "<td>{$col['Type']}</td>";
        echo "<td>{$col['Null']}</td>";
        echo "<td>{$col['Key']}</td>";
        echo "<td>{$col['Default']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Count customers
    $count = $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
    echo "<p><strong>Total customers:</strong> $count</p>";
    
    $pdo->commit();
    
    echo "<h2 style='color:green;'>✅ SUCCESS! Customers table is ready.</h2>";
    echo "<p><a href='../public/staff_customer_list.php' style='padding:10px 20px;background:#002F70;color:#fff;text-decoration:none;border-radius:5px;'>Go to Customer Module</a></p>";
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "<h2 style='color:red;'>❌ ERROR!</h2>";
    echo "<p style='color:red;'>" . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>
