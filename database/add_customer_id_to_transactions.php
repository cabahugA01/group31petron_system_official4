<?php
/**
 * Add customer_id column to transaction tables
 * This enables customer transaction history integration
 */

require_once __DIR__ . '/../public/db_connect.php';

echo "<h1>Add customer_id to Transaction Tables</h1>";
echo "<p>This script will add customer_id column to merchandise_transactions and job_orders tables if it doesn't exist.</p>";

$results = [];

try {
    // ========== MERCHANDISE_TRANSACTIONS TABLE ==========
    echo "<h2>1. Merchandise Transactions Table</h2>";
    
    // Check if table exists
    try {
        $pdo->query("SELECT 1 FROM merchandise_transactions LIMIT 1");
        echo "<p>✅ Table exists</p>";
        
        // Check if customer_id column exists
        $columns = $pdo->query("SHOW COLUMNS FROM merchandise_transactions LIKE 'customer_id'")->fetchAll();
        
        if (empty($columns)) {
            echo "<p>⚠️ customer_id column does NOT exist. Adding...</p>";
            
            // Add customer_id column
            $pdo->exec("
                ALTER TABLE merchandise_transactions
                ADD COLUMN customer_id INT(11) UNSIGNED NULL AFTER station_id,
                ADD INDEX idx_customer_id (customer_id)
            ");
            
            echo "<p style='color:green;'><strong>✅ customer_id column added successfully!</strong></p>";
            $results['merchandise'] = 'added';
        } else {
            echo "<p style='color:blue;'><strong>ℹ️ customer_id column already exists</strong></p>";
            $results['merchandise'] = 'exists';
        }
        
        // Show column info
        $colInfo = $pdo->query("SHOW COLUMNS FROM merchandise_transactions LIKE 'customer_id'")->fetch(PDO::FETCH_ASSOC);
        echo "<p><strong>Column Details:</strong></p>";
        echo "<pre>" . print_r($colInfo, true) . "</pre>";
        
    } catch (Exception $e) {
        echo "<p style='color:orange;'>⚠️ Table does not exist: " . $e->getMessage() . "</p>";
        $results['merchandise'] = 'table_not_found';
    }
    
    echo "<hr>";
    
    // ========== JOB_ORDERS TABLE ==========
    echo "<h2>2. Job Orders Table</h2>";
    
    // Check if table exists
    try {
        $pdo->query("SELECT 1 FROM job_orders LIMIT 1");
        echo "<p>✅ Table exists</p>";
        
        // Check if customer_id column exists
        $columns = $pdo->query("SHOW COLUMNS FROM job_orders LIKE 'customer_id'")->fetchAll();
        
        if (empty($columns)) {
            echo "<p>⚠️ customer_id column does NOT exist. Adding...</p>";
            
            // Add customer_id column
            $pdo->exec("
                ALTER TABLE job_orders
                ADD COLUMN customer_id INT(11) UNSIGNED NULL AFTER station_id,
                ADD INDEX idx_customer_id (customer_id)
            ");
            
            echo "<p style='color:green;'><strong>✅ customer_id column added successfully!</strong></p>";
            $results['job_orders'] = 'added';
        } else {
            echo "<p style='color:blue;'><strong>ℹ️ customer_id column already exists</strong></p>";
            $results['job_orders'] = 'exists';
        }
        
        // Show column info
        $colInfo = $pdo->query("SHOW COLUMNS FROM job_orders LIKE 'customer_id'")->fetch(PDO::FETCH_ASSOC);
        echo "<p><strong>Column Details:</strong></p>";
        echo "<pre>" . print_r($colInfo, true) . "</pre>";
        
    } catch (Exception $e) {
        echo "<p style='color:orange;'>⚠️ Table does not exist: " . $e->getMessage() . "</p>";
        $results['job_orders'] = 'table_not_found';
    }
    
    echo "<hr>";
    
    // ========== FUEL_TRANSACTIONS TABLE (for completeness) ==========
    echo "<h2>3. Fuel Transactions Table</h2>";
    
    try {
        $pdo->query("SELECT 1 FROM fuel_transactions LIMIT 1");
        echo "<p>✅ Table exists</p>";
        
        // Check if customer_id column exists
        $columns = $pdo->query("SHOW COLUMNS FROM fuel_transactions LIKE 'customer_id'")->fetchAll();
        
        if (empty($columns)) {
            echo "<p>⚠️ customer_id column does NOT exist. Adding...</p>";
            
            // Add customer_id column
            $pdo->exec("
                ALTER TABLE fuel_transactions
                ADD COLUMN customer_id INT(11) UNSIGNED NULL AFTER station_id,
                ADD INDEX idx_customer_id (customer_id)
            ");
            
            echo "<p style='color:green;'><strong>✅ customer_id column added successfully!</strong></p>";
            $results['fuel'] = 'added';
        } else {
            echo "<p style='color:blue;'><strong>ℹ️ customer_id column already exists</strong></p>";
            $results['fuel'] = 'exists';
        }
        
        // Show column info
        $colInfo = $pdo->query("SHOW COLUMNS FROM fuel_transactions LIKE 'customer_id'")->fetch(PDO::FETCH_ASSOC);
        echo "<p><strong>Column Details:</strong></p>";
        echo "<pre>" . print_r($colInfo, true) . "</pre>";
        
    } catch (Exception $e) {
        echo "<p style='color:orange;'>⚠️ Table does not exist: " . $e->getMessage() . "</p>";
        $results['fuel'] = 'table_not_found';
    }
    
    echo "<hr>";
    
    // ========== SUMMARY ==========
    echo "<h2>✅ Summary</h2>";
    echo "<table border='1' cellpadding='10' style='border-collapse:collapse;'>";
    echo "<tr style='background:#002F70;color:white;'><th>Table</th><th>Status</th><th>Action Required</th></tr>";
    
    foreach ($results as $table => $status) {
        $statusText = '';
        $action = '';
        $color = '';
        
        switch ($status) {
            case 'added':
                $statusText = '✅ Column Added';
                $action = 'None - Ready to use!';
                $color = '#d1fae5';
                break;
            case 'exists':
                $statusText = 'ℹ️ Already Exists';
                $action = 'None - Already integrated';
                $color = '#dbeafe';
                break;
            case 'table_not_found':
                $statusText = '⚠️ Table Not Found';
                $action = 'Create the table first';
                $color = '#fef3c7';
                break;
        }
        
        echo "<tr style='background:$color;'>";
        echo "<td><strong>$table</strong></td>";
        echo "<td>$statusText</td>";
        echo "<td>$action</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    
    echo "<hr>";
    echo "<h2>🎉 Process Complete!</h2>";
    echo "<p><strong>What's next?</strong></p>";
    echo "<ol>";
    echo "<li>When creating new merchandise transactions, include customer_id in the INSERT query</li>";
    echo "<li>When creating new job orders, include customer_id in the INSERT query</li>";
    echo "<li>Customer transaction history will now work automatically once customer_id is populated</li>";
    echo "</ol>";
    
    echo "<p style='margin-top:30px;'>";
    echo "<a href='../public/staff_customer_list.php' style='display:inline-block;padding:12px 24px;background:#16a34a;color:white;text-decoration:none;border-radius:8px;font-weight:bold;'>→ Go to Customer Module</a> ";
    echo "<a href='../check_customers_table.php' style='display:inline-block;padding:12px 24px;background:#002F70;color:white;text-decoration:none;border-radius:8px;font-weight:bold;margin-left:10px;'>→ Check Database Status</a>";
    echo "</p>";
    
} catch (Exception $e) {
    echo "<div style='background:#fee2e2;border:2px solid #dc2626;border-radius:8px;padding:20px;margin:20px 0;'>";
    echo "<h2 style='color:#dc2626;margin:0 0 10px;'>❌ Error Occurred</h2>";
    echo "<p style='color:#991b1b;'><strong>Error:</strong> " . $e->getMessage() . "</p>";
    echo "<pre style='background:#fef2f2;padding:10px;border-radius:4px;overflow-x:auto;'>" . $e->getTraceAsString() . "</pre>";
    echo "</div>";
}
?>

<style>
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    max-width: 1200px;
    margin: 40px auto;
    padding: 20px;
    background: #f8f9fa;
}
h1 {
    color: #002F70;
    border-bottom: 3px solid #002F70;
    padding-bottom: 10px;
}
h2 {
    color: #002F70;
    margin-top: 30px;
}
pre {
    background: #f3f4f6;
    padding: 15px;
    border-radius: 8px;
    border-left: 4px solid #002F70;
    overflow-x: auto;
}
table {
    width: 100%;
    margin: 20px 0;
}
table th, table td {
    text-align: left;
    padding: 12px;
}
hr {
    border: none;
    border-top: 2px solid #e5e7eb;
    margin: 30px 0;
}
</style>
