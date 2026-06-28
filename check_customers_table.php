<?php
/**
 * Check if customers table exists and its structure
 */

require_once __DIR__ . '/public/db_connect.php';

echo "<h2>Checking Customers Table Status</h2>";

try {
    // Check if table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'customers'");
    $tableExists = $stmt->rowCount() > 0;
    
    if ($tableExists) {
        echo "<p style='color:green;'><strong>✅ Customers table EXISTS</strong></p>";
        
        // Show table structure
        echo "<h3>Table Structure:</h3>";
        $columns = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_ASSOC);
        echo "<table border='1' cellpadding='8' style='border-collapse:collapse; font-family:monospace;'>";
        echo "<tr style='background:#002F70;color:white;'><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
        foreach ($columns as $col) {
            echo "<tr>";
            echo "<td><strong>{$col['Field']}</strong></td>";
            echo "<td>{$col['Type']}</td>";
            echo "<td>{$col['Null']}</td>";
            echo "<td>{$col['Key']}</td>";
            echo "<td>{$col['Default']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Count customers
        $count = $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
        echo "<p><strong>Total customers in table:</strong> $count</p>";
        
        // Check transaction tables for customer_id column
        echo "<h3>Transaction Tables Check:</h3>";
        $tables = ['fuel_transactions', 'merchandise_transactions', 'job_orders'];
        
        foreach ($tables as $table) {
            try {
                $stmt = $pdo->query("SHOW COLUMNS FROM $table LIKE 'customer_id'");
                if ($stmt->rowCount() > 0) {
                    echo "<p style='color:green;'>✅ <strong>$table</strong> has customer_id column</p>";
                } else {
                    echo "<p style='color:orange;'>⚠️ <strong>$table</strong> does NOT have customer_id column</p>";
                }
            } catch (Exception $e) {
                echo "<p style='color:red;'>❌ <strong>$table</strong> does not exist or error: {$e->getMessage()}</p>";
            }
        }
        
        echo "<hr>";
        echo "<h3>✅ All checks complete!</h3>";
        echo "<p><a href='public/staff_customer_list.php' style='display:inline-block;padding:12px 24px;background:#16a34a;color:white;text-decoration:none;border-radius:8px;font-weight:bold;'>→ Go to Customer Module</a></p>";
        
    } else {
        echo "<p style='color:red;'><strong>❌ Customers table DOES NOT exist</strong></p>";
        echo "<p>You need to run the setup script first:</p>";
        echo "<p><a href='database/setup_customers_table.php' style='display:inline-block;padding:12px 24px;background:#dc2626;color:white;text-decoration:none;border-radius:8px;font-weight:bold;'>→ Run Setup Script</a></p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color:red;'><strong>Error:</strong> " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>
