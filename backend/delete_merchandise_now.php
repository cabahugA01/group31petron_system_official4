<?php
/**
 * IMMEDIATE Merchandise Transaction Deletion
 * No confirmation required - executes immediately
 */

require_once __DIR__ . '/../public/db_connect.php';

echo "Starting merchandise history deletion...\n\n";

try {
    $pdo->beginTransaction();
    
    // Count records before deletion
    $count_transactions = $pdo->query("SELECT COUNT(*) FROM merchandise_transactions")->fetchColumn();
    $count_items = 0;
    
    // Check if items table exists
    try {
        $count_items = $pdo->query("SELECT COUNT(*) FROM merchandise_transaction_items")->fetchColumn();
    } catch (Exception $e) {
        echo "Note: merchandise_transaction_items table not found (may not exist yet)\n";
    }
    
    echo "Found:\n";
    echo "  - $count_transactions merchandise transactions\n";
    echo "  - $count_items transaction line items\n\n";
    
    // Delete all merchandise transaction items first (foreign key constraint)
    try {
        $pdo->exec("DELETE FROM merchandise_transaction_items");
        echo "✓ Deleted all transaction items\n";
    } catch (Exception $e) {
        echo "  (Skipped transaction items - table may not exist)\n";
    }
    
    // Delete all merchandise transactions
    $pdo->exec("DELETE FROM merchandise_transactions");
    echo "✓ Deleted all merchandise transactions\n";
    
    $pdo->commit();
    echo "✓ Changes committed to database\n";
    
    // Reset auto-increment counters (outside transaction)
    $pdo->exec("ALTER TABLE merchandise_transactions AUTO_INCREMENT = 1");
    echo "✓ Reset transaction ID counter\n";
    
    try {
        $pdo->exec("ALTER TABLE merchandise_transaction_items AUTO_INCREMENT = 1");
        echo "✓ Reset items ID counter\n";
    } catch (Exception $e) {
        // Ignore if table doesn't exist
    }
    
    echo "\n✅ SUCCESS!\n";
    echo "═══════════════════════════════════════\n";
    echo "All merchandise history deleted.\n";
    echo "Database is clean and ready for new data.\n";
    echo "═══════════════════════════════════════\n";
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    echo "\n❌ ERROR!\n";
    echo "═══════════════════════════════════════\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "No changes were made (rolled back).\n";
    echo "═══════════════════════════════════════\n";
}
?>
