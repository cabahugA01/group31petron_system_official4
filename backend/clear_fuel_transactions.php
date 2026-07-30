<?php
/**
 * Clear All Fuel Transactions and Related Report Data
 * Resets fuel transactions, meter readings, audit logs, and reports cache for fresh data entry.
 */

require_once __DIR__ . '/../public/db_connect.php';

header('Content-Type: text/plain; charset=utf-8');
echo "Starting fuel transaction & reports deletion...\n\n";

$tables_to_clear = [
    'fuel_transactions',
    'fuel_transaction_audit',
    'fuel_daily_readings',
    'fuel_readings',
    'fuel_sales_summary',
    'fuel_variance_reports',
    'fuel_reconciliation',
    'fuel_audit_trail',
    'fuel_adjustments',
    'shift_reports',
    'reports_cache'
];

try {
    $pdo->beginTransaction();

    foreach ($tables_to_clear as $table) {
        try {
            $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$table]);
            if ($stmt->fetchColumn()) {
                $count = (int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
                $pdo->exec("DELETE FROM `$table`");
                echo "✓ Cleared `$table` ($count records deleted)\n";
            } else {
                echo "  (Skipped `$table`: table does not exist)\n";
            }
        } catch (Exception $te) {
            echo "  (Error clearing `$table`: " . $te->getMessage() . ")\n";
        }
    }

    $pdo->commit();
    echo "\n✓ All transaction deletes committed successfully!\n";

    // Reset auto increment outside transaction
    foreach ($tables_to_clear as $table) {
        try {
            $pdo->exec("ALTER TABLE `$table` AUTO_INCREMENT = 1");
        } catch (Exception $ae) {}
    }
    echo "✓ Reset ID auto-increment counters.\n\n";

    echo "═══════════════════════════════════════\n";
    echo "SUCCESS! Fuel transactions and report data have been cleared.\n";
    echo "The system is clean and ready for fresh fuel transaction input.\n";
    echo "═══════════════════════════════════════\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "\n❌ ERROR: Deletion failed! " . $e->getMessage() . "\n";
}
?>
