<?php
/**
 * Fix Duplicate PO Number Constraint
 * This script removes/modifies the unique constraint on po_number if it exists
 */

require_once __DIR__ . '/public/db_connect.php';

try {
    echo "Checking purchase_orders table constraints...\n";
    
    // Check if unique key exists
    $stmt = $pdo->query("SHOW INDEX FROM purchase_orders WHERE Key_name = 'uk_po_number'");
    $has_constraint = $stmt->fetch();
    
    if ($has_constraint) {
        echo "Found unique constraint 'uk_po_number'. Dropping it...\n";
        $pdo->exec("ALTER TABLE purchase_orders DROP INDEX uk_po_number");
        echo "✅ Unique constraint dropped successfully.\n";
    } else {
        echo "✅ No unique constraint found on po_number. Table is OK.\n";
    }
    
    // Add regular index instead (for performance, but allows duplicates)
    try {
        $pdo->exec("ALTER TABLE purchase_orders ADD INDEX idx_po_number (po_number)");
        echo "✅ Added regular index on po_number for query performance.\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
            echo "ℹ Index already exists.\n";
        } else {
            throw $e;
        }
    }
    
    echo "\n";
    echo "Checking fuel_purchase_orders table...\n";
    
    // Check fuel PO table too
    $stmt2 = $pdo->query("SHOW INDEX FROM fuel_purchase_orders WHERE Key_name = 'uk_po_number'");
    $has_constraint2 = $stmt2->fetch();
    
    if ($has_constraint2) {
        echo "Found unique constraint 'uk_po_number' on fuel table. Dropping it...\n";
        $pdo->exec("ALTER TABLE fuel_purchase_orders DROP INDEX uk_po_number");
        echo "✅ Unique constraint dropped successfully.\n";
    } else {
        echo "✅ No unique constraint found on po_number. Table is OK.\n";
    }
    
    try {
        $pdo->exec("ALTER TABLE fuel_purchase_orders ADD INDEX idx_po_number (po_number)");
        echo "✅ Added regular index on po_number for query performance.\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
            echo "ℹ Index already exists.\n";
        } else {
            throw $e;
        }
    }
    
    echo "\n✅ ALL DONE! Duplicate PO number issue is fixed.\n";
    echo "You can now finalize PO batches without errors.\n";
    
} catch (PDOException $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
