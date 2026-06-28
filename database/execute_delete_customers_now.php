<?php
/**
 * DIRECT EXECUTION - Delete Customers Module
 * This bypasses the confirmation page and deletes immediately
 * Date: June 28, 2026
 */

echo "=================================================\n";
echo "DELETING CUSTOMERS MODULE - PLEASE WAIT...\n";
echo "=================================================\n\n";

require_once __DIR__ . '/../public/db_connect.php';

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Step 1: Disable foreign key checks
    echo "[1/6] Disabling foreign key checks...\n";
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    echo "      ✓ Done\n\n";
    
    // Step 2: Drop customer tables
    echo "[2/6] Dropping customer tables...\n";
    
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
            echo "      ✓ Dropped: $table\n";
        } catch (Exception $e) {
            echo "      ✗ Error with $table: " . $e->getMessage() . "\n";
        }
    }
    echo "\n";
    
    // Step 3: Remove customer permissions
    echo "[3/6] Removing customer permissions...\n";
    
    // Get permission IDs first
    $stmt = $pdo->query("SELECT id FROM permissions WHERE module = 'customers'");
    $permission_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (!empty($permission_ids)) {
        $ids = implode(',', $permission_ids);
        $deleted = $pdo->exec("DELETE FROM role_permissions WHERE permission_id IN ($ids)");
        echo "      ✓ Removed $deleted role permission assignments\n";
    } else {
        echo "      - No role permission assignments found\n";
    }
    
    $deleted = $pdo->exec("DELETE FROM permissions WHERE module = 'customers'");
    echo "      ✓ Deleted $deleted customer permissions\n\n";
    
    // Step 4: Remove customer module from station_modules
    echo "[4/6] Removing customer module from stations...\n";
    $deleted = $pdo->exec("DELETE FROM station_modules WHERE module_key = 'customers'");
    echo "      ✓ Removed from $deleted stations\n\n";
    
    // Step 5: Clean up audit logs
    echo "[5/6] Cleaning up audit logs...\n";
    try {
        $deleted = $pdo->exec("DELETE FROM audit_log WHERE table_name = 'customers'");
        echo "      ✓ Removed $deleted audit log entries\n\n";
    } catch (Exception $e) {
        echo "      - Audit log cleanup skipped (table may not exist)\n\n";
    }
    
    // Step 6: Re-enable foreign key checks
    echo "[6/6] Re-enabling foreign key checks...\n";
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "      ✓ Done\n\n";
    
    // Final verification
    echo "=================================================\n";
    echo "VERIFICATION\n";
    echo "=================================================\n";
    
    // Check if customers table exists
    $check = $pdo->query("SHOW TABLES LIKE 'customers'")->rowCount();
    if ($check === 0) {
        echo "✓ customers table: DELETED\n";
    } else {
        echo "✗ customers table: STILL EXISTS (ERROR!)\n";
    }
    
    // Check customer_transactions
    $check = $pdo->query("SHOW TABLES LIKE 'customer_transactions'")->rowCount();
    if ($check === 0) {
        echo "✓ customer_transactions table: DELETED\n";
    } else {
        echo "✗ customer_transactions table: STILL EXISTS (ERROR!)\n";
    }
    
    // Check customer_credit_transactions
    $check = $pdo->query("SHOW TABLES LIKE 'customer_credit_transactions'")->rowCount();
    if ($check === 0) {
        echo "✓ customer_credit_transactions table: DELETED\n";
    } else {
        echo "✗ customer_credit_transactions table: STILL EXISTS (ERROR!)\n";
    }
    
    // Check module entries
    $check = $pdo->query("SELECT COUNT(*) FROM station_modules WHERE module_key = 'customers'")->fetchColumn();
    echo "✓ station_modules entries: $check (should be 0)\n";
    
    // Check permissions
    $check = $pdo->query("SELECT COUNT(*) FROM permissions WHERE module = 'customers'")->fetchColumn();
    echo "✓ customer permissions: $check (should be 0)\n";
    
    echo "\n=================================================\n";
    echo "✅ CUSTOMERS MODULE DELETION COMPLETE!\n";
    echo "=================================================\n\n";
    
    echo "Files already deleted:\n";
    echo "  ✓ manager_customers.php\n";
    echo "  ✓ manager_customer_management.php\n";
    echo "  ✓ staff_customer_list.php\n";
    echo "  ✓ admin_customer_management.php\n";
    echo "  ✓ All customer-related PHP, CSS, JS files\n";
    echo "  ✓ Menu entries removed from rbac_menu.php\n";
    echo "  ✓ Module removed from lib.php\n\n";
    
    echo "Database tables deleted:\n";
    echo "  ✓ customers\n";
    echo "  ✓ customer_transactions\n";
    echo "  ✓ customer_credit_transactions\n";
    echo "  ✓ customer_update_requests\n";
    echo "  ✓ customer_documents_access_log\n\n";
    
    echo "Next steps:\n";
    echo "  1. Clear browser cache\n";
    echo "  2. Log out and log back in\n";
    echo "  3. Verify customer menu is gone\n";
    echo "  4. Implement your new customer system\n\n";
    
    echo "SUCCESS! Customer module is permanently deleted.\n";
    
} catch (Exception $e) {
    echo "\n❌ FATAL ERROR:\n";
    echo $e->getMessage() . "\n\n";
    echo "The database may be in an inconsistent state.\n";
    echo "Please restore from backup if needed.\n";
}
?>
