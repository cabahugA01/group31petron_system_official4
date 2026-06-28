<?php
/**
 * ============================================================
 * RUN DELETE CUSTOMERS MODULE SCRIPT
 * Petron Station Management System
 * Date: June 28, 2026
 * ============================================================
 * 
 * WARNING: This script will PERMANENTLY delete all customer data
 * including all customer records, transactions, and related data.
 * 
 * This action CANNOT be undone!
 * 
 * Usage:
 * 1. Make a full database backup before running this script
 * 2. Access this file via browser: http://localhost/group31petron_system_official4/database/run_delete_customers_module.php
 * 3. Or run via command line: php run_delete_customers_module.php
 */

require_once __DIR__ . '/../public/db_connect.php';

// Security check - require confirmation
$confirmed = isset($_GET['confirm']) && $_GET['confirm'] === 'YES_DELETE_ALL_CUSTOMER_DATA';

if (!$confirmed) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Delete Customers Module - Confirmation Required</title>
        <style>
            body { font-family: Arial, sans-serif; padding: 40px; background: #f5f5f5; }
            .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            h1 { color: #dc3545; }
            .warning { background: #fff3cd; border: 2px solid #ffc107; padding: 20px; border-radius: 5px; margin: 20px 0; }
            .danger { background: #f8d7da; border: 2px solid #dc3545; padding: 20px; border-radius: 5px; margin: 20px 0; }
            ul { margin: 15px 0; padding-left: 25px; }
            li { margin: 8px 0; }
            .btn { display: inline-block; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-weight: bold; margin: 10px 5px; }
            .btn-danger { background: #dc3545; color: white; }
            .btn-secondary { background: #6c757d; color: white; }
            .btn:hover { opacity: 0.9; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>⚠️ Delete Customers Module - Confirmation Required</h1>
            
            <div class="danger">
                <h2>⚠️ PERMANENT DATA DELETION WARNING ⚠️</h2>
                <p><strong>This action will PERMANENTLY delete:</strong></p>
                <ul>
                    <li><strong>All customer records</strong> from the database</li>
                    <li><strong>All customer transactions</strong> and payment history</li>
                    <li><strong>All customer credit transactions</strong> and balances</li>
                    <li><strong>All customer documents</strong> and access logs</li>
                    <li><strong>All customer update requests</strong></li>
                    <li><strong>All customer permissions</strong> and role assignments</li>
                    <li><strong>Customer module configuration</strong> from all stations</li>
                </ul>
                <p style="font-size: 18px; color: #dc3545; font-weight: bold;">THIS ACTION CANNOT BE UNDONE!</p>
            </div>

            <div class="warning">
                <h3>Before proceeding, ensure you have:</h3>
                <ul>
                    <li>✅ Created a <strong>full database backup</strong></li>
                    <li>✅ Verified the backup is complete and restorable</li>
                    <li>✅ Notified all system users about this change</li>
                    <li>✅ Documented any customer data that needs to be preserved</li>
                </ul>
            </div>

            <h3>What will happen:</h3>
            <ul>
                <li>All customer-related database tables will be dropped</li>
                <li>All customer permissions will be removed from all roles</li>
                <li>Customer module will be removed from station configurations</li>
                <li>Customer menu items will no longer appear (files already deleted)</li>
            </ul>

            <h3>What will NOT be affected:</h3>
            <ul>
                <li>Other transactions (fuel, merchandise, job orders)</li>
                <li>User accounts and permissions</li>
                <li>Station configurations (except customer module)</li>
                <li>Inventory, products, and pricing data</li>
            </ul>

            <div style="margin-top: 30px; padding-top: 20px; border-top: 2px solid #ddd;">
                <p><strong>If you understand the consequences and wish to proceed:</strong></p>
                <a href="?confirm=YES_DELETE_ALL_CUSTOMER_DATA" class="btn btn-danger" onclick="return confirm('Are you ABSOLUTELY SURE? This will permanently delete ALL customer data!');">
                    🗑️ YES, DELETE ALL CUSTOMER DATA
                </a>
                <a href="javascript:history.back()" class="btn btn-secondary">
                    ← Cancel and Go Back
                </a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// User confirmed - proceed with deletion
echo "<!DOCTYPE html>
<html>
<head>
    <title>Deleting Customers Module</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 40px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .step { margin: 15px 0; padding: 10px; border-left: 4px solid #007bff; }
        .success { border-left-color: #28a745; background: #d4edda; }
        .error { border-left-color: #dc3545; background: #f8d7da; }
        .info { border-left-color: #17a2b8; background: #d1ecf1; }
    </style>
</head>
<body>
<div class='container'>
<h1>🗑️ Deleting Customers Module</h1>";

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Step 1: Disable foreign key checks
    echo "<div class='step info'>Disabling foreign key checks...</div>";
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    
    // Step 2: Drop customer tables
    echo "<div class='step info'>Dropping customer tables...</div>";
    
    $tables = [
        'customer_update_requests',
        'customer_documents_access_log',
        'customer_transactions',
        'customer_credit_transactions',
        'customers'
    ];
    
    foreach ($tables as $table) {
        try {
            $pdo->exec("DROP TABLE IF EXISTS `$table`");
            echo "<div class='step success'>✓ Dropped table: $table</div>";
        } catch (Exception $e) {
            echo "<div class='step error'>✗ Error dropping table $table: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
    
    // Step 3: Remove customer permissions
    echo "<div class='step info'>Removing customer permissions...</div>";
    
    // Get permission IDs first
    $stmt = $pdo->query("SELECT id FROM permissions WHERE module = 'customers'");
    $permission_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (!empty($permission_ids)) {
        $ids = implode(',', $permission_ids);
        $deleted = $pdo->exec("DELETE FROM role_permissions WHERE permission_id IN ($ids)");
        echo "<div class='step success'>✓ Removed $deleted role permission assignments</div>";
    }
    
    $deleted = $pdo->exec("DELETE FROM permissions WHERE module = 'customers'");
    echo "<div class='step success'>✓ Deleted $deleted customer permissions</div>";
    
    // Step 4: Remove customer module from station_modules
    echo "<div class='step info'>Removing customer module from station configurations...</div>";
    $deleted = $pdo->exec("DELETE FROM station_modules WHERE module_key = 'customers'");
    echo "<div class='step success'>✓ Removed customer module from $deleted stations</div>";
    
    // Step 5: Clean up audit logs (optional)
    echo "<div class='step info'>Cleaning up audit logs...</div>";
    $deleted = $pdo->exec("DELETE FROM audit_log WHERE table_name = 'customers'");
    echo "<div class='step success'>✓ Removed $deleted customer audit log entries</div>";
    
    // Step 6: Re-enable foreign key checks
    echo "<div class='step info'>Re-enabling foreign key checks...</div>";
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    
    // Final verification
    echo "<div class='step info'>Verifying deletion...</div>";
    
    // Check if tables exist
    $check = $pdo->query("SHOW TABLES LIKE 'customers'")->rowCount();
    if ($check === 0) {
        echo "<div class='step success'>✓ Verified: customers table no longer exists</div>";
    } else {
        echo "<div class='step error'>⚠ Warning: customers table still exists</div>";
    }
    
    // Check module
    $check = $pdo->query("SELECT COUNT(*) FROM station_modules WHERE module_key = 'customers'")->fetchColumn();
    echo "<div class='step success'>✓ Verified: $check station_modules entries with 'customers' (should be 0)</div>";
    
    // Check permissions
    $check = $pdo->query("SELECT COUNT(*) FROM permissions WHERE module = 'customers'")->fetchColumn();
    echo "<div class='step success'>✓ Verified: $check customer permissions remaining (should be 0)</div>";
    
    echo "<div class='step success' style='margin-top: 30px; font-size: 18px; font-weight: bold;'>
        ✅ CUSTOMERS MODULE DELETION COMPLETE!
    </div>";
    
    echo "<div class='step info' style='margin-top: 20px;'>
        <h3>Next Steps:</h3>
        <ul>
            <li>Clear browser cache and refresh the application</li>
            <li>Verify that customer menu items no longer appear</li>
            <li>Test that other modules (transactions, inventory, etc.) still work correctly</li>
            <li>You can now implement your new customer management system</li>
        </ul>
    </div>";
    
} catch (Exception $e) {
    echo "<div class='step error'>
        <h3>❌ FATAL ERROR</h3>
        <p>" . htmlspecialchars($e->getMessage()) . "</p>
        <p>The database may be in an inconsistent state. Please restore from backup.</p>
    </div>";
}

echo "</div></body></html>";
?>
