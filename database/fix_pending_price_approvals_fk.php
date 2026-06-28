<?php
/**
 * Fix Foreign Key Constraint Issue in pending_price_approvals
 * 
 * Problem: The foreign key fk_pending_price_approvals_product references inventory_products(id)
 * but product_id can point to different tables based on product_type:
 * - 'merchandise' -> inventory_products(id)
 * - 'service_type' -> job_order_service_types(id)
 * - 'fuel' or 'fuel_inventory' -> fuel_inventory(id)
 * 
 * Solution: Remove the foreign key constraint since product_id is polymorphic
 */

require_once __DIR__ . '/../public/db_connect.php';

try {
    echo "Starting foreign key constraint fix...\n";
    echo "Connected to database: petron_pos_db_secure\n\n";
    
    // Check if the constraint exists
    $checkSql = "
        SELECT CONSTRAINT_NAME 
        FROM information_schema.TABLE_CONSTRAINTS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'pending_price_approvals' 
        AND CONSTRAINT_NAME = 'fk_pending_price_approvals_product'
    ";
    
    $stmt = $pdo->query($checkSql);
    $constraintExists = $stmt->fetch();
    
    if ($constraintExists) {
        echo "Found constraint 'fk_pending_price_approvals_product'. Dropping it...\n";
        
        // Drop the foreign key constraint
        $pdo->exec("ALTER TABLE pending_price_approvals DROP FOREIGN KEY fk_pending_price_approvals_product");
        
        echo "✓ Successfully dropped foreign key constraint 'fk_pending_price_approvals_product'\n";
        echo "✓ The table now supports polymorphic product_id references\n";
        echo "\n";
        echo "Note: product_id can now reference:\n";
        echo "  - inventory_products.id (when product_type = 'merchandise')\n";
        echo "  - fuel_inventory.id (when product_type = 'fuel' or 'fuel_inventory')\n";
        echo "  - job_order_service_types.id (when product_type = 'service_type')\n";
    } else {
        echo "✓ Constraint 'fk_pending_price_approvals_product' does not exist. No action needed.\n";
    }
    
    // Verify the table structure
    echo "\nCurrent table structure:\n";
    $result = $pdo->query("SHOW CREATE TABLE pending_price_approvals");
    $row = $result->fetch(PDO::FETCH_ASSOC);
    echo $row['Create Table'] . "\n";
    
    // Also check for any remaining foreign key constraints on product_id
    echo "\n\nChecking for any other foreign key constraints on product_id:\n";
    $fkCheck = $pdo->query("
        SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME 
        FROM information_schema.KEY_COLUMN_USAGE 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'pending_price_approvals' 
        AND COLUMN_NAME = 'product_id'
        AND REFERENCED_TABLE_NAME IS NOT NULL
    ");
    $fks = $fkCheck->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($fks)) {
        echo "WARNING: Found remaining foreign key constraints:\n";
        foreach ($fks as $fk) {
            echo "  - {$fk['CONSTRAINT_NAME']} -> {$fk['REFERENCED_TABLE_NAME']}\n";
        }
    } else {
        echo "✓ No foreign key constraints on product_id. Clean!\n";
    }
    
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n✓ Fix completed successfully!\n";
?>
