<?php
/**
 * Database Migration: Unified Transactions System
 * Adds necessary columns for job_order, merchandise, and combined transaction types
 */

require_once __DIR__ . '/../../public/db_connect.php';

echo "Starting Unified Transactions Migration...\n\n";

try {
    // ========== STEP 1: Add transaction_type column ==========
    echo "Step 1: Adding transaction_type column...\n";
    try {
        $pdo->exec("
            ALTER TABLE merchandise_transactions 
            ADD COLUMN transaction_type ENUM('job_order', 'merchandise', 'combined') 
            DEFAULT 'merchandise'
            COMMENT 'Transaction type: job_order (service only), merchandise (products only), combined (both)'
        ");
        echo "✓ transaction_type column added\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "✓ transaction_type column already exists\n";
        } else {
            throw $e;
        }
    }
    
    // ========== STEP 2: Add job order fields ==========
    echo "\nStep 2: Adding job order fields...\n";
    
    $job_order_columns = [
        "job_order_service VARCHAR(255) DEFAULT NULL COMMENT 'Service type name'",
        "job_order_vehicle_plate VARCHAR(20) DEFAULT NULL COMMENT 'Vehicle plate number'",
        "job_order_vehicle_type VARCHAR(100) DEFAULT NULL COMMENT 'Vehicle type (Sedan, SUV, etc.)'",
        "job_order_vehicle_brand VARCHAR(100) DEFAULT NULL COMMENT 'Vehicle brand (Toyota, Honda, etc.)'",
        "job_order_vehicle_model VARCHAR(100) DEFAULT NULL COMMENT 'Vehicle model (Vios, Civic, etc.)'",
        "job_order_service_category VARCHAR(100) DEFAULT NULL COMMENT 'Service category (Maintenance, Repair, etc.)'",
        "job_order_mechanic_name VARCHAR(255) DEFAULT NULL COMMENT 'Assigned mechanic name'",
        "workflow_status ENUM('Pending','In Progress','Completed','Rejected') DEFAULT 'Pending' COMMENT 'Job order workflow status'"
    ];
    
    foreach ($job_order_columns as $column_def) {
        $column_name = explode(' ', $column_def)[0];
        try {
            $pdo->exec("ALTER TABLE merchandise_transactions ADD COLUMN $column_def");
            echo "✓ $column_name added\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') !== false) {
                echo "✓ $column_name already exists\n";
            } else {
                throw $e;
            }
        }
    }
    
    // ========== STEP 3: Add item_type column to items table ==========
    echo "\nStep 3: Adding item_type column to merchandise_transaction_items...\n";
    try {
        $pdo->exec("
            ALTER TABLE merchandise_transaction_items 
            ADD COLUMN item_type ENUM('merchandise', 'service') 
            DEFAULT 'merchandise'
            COMMENT 'Item type: merchandise or service'
        ");
        echo "✓ item_type column added\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "✓ item_type column already exists\n";
        } else {
            throw $e;
        }
    }
    
    // ========== STEP 4: Create indexes for better performance ==========
    echo "\nStep 4: Creating indexes...\n";
    
    $indexes = [
        "CREATE INDEX idx_transaction_type ON merchandise_transactions(transaction_type)",
        "CREATE INDEX idx_workflow_status ON merchandise_transactions(workflow_status)",
        "CREATE INDEX idx_item_type ON merchandise_transaction_items(item_type)",
        "CREATE INDEX idx_created_at ON merchandise_transactions(created_at)"
    ];
    
    foreach ($indexes as $index_sql) {
        try {
            $pdo->exec($index_sql);
            preg_match('/idx_([a-z_]+)/', $index_sql, $matches);
            $index_name = $matches[1] ?? 'unknown';
            echo "✓ Index idx_$index_name created\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate key') !== false) {
                preg_match('/idx_([a-z_]+)/', $index_sql, $matches);
                $index_name = $matches[1] ?? 'unknown';
                echo "✓ Index idx_$index_name already exists\n";
            } else {
                // Non-critical, continue
                echo "⚠ Index creation skipped: " . $e->getMessage() . "\n";
            }
        }
    }
    
    // ========== STEP 5: Migrate existing data ==========
    echo "\nStep 5: Migrating existing transaction data...\n";
    
    // Update transaction_type for existing records
    try {
        // Records with service info but no merchandise items = job_order
        $stmt = $pdo->query("
            UPDATE merchandise_transactions mt
            SET transaction_type = 'job_order'
            WHERE transaction_type = 'merchandise'
              AND job_order_service IS NOT NULL
              AND job_order_service != ''
              AND NOT EXISTS (
                  SELECT 1 FROM merchandise_transaction_items mti
                  WHERE mti.transaction_id = mt.id
                    AND COALESCE(mti.item_type, 'merchandise') = 'merchandise'
              )
        ");
        $job_order_count = $stmt->rowCount();
        echo "✓ Migrated $job_order_count records to job_order type\n";
        
        // Records with both service and merchandise = combined
        $stmt = $pdo->query("
            UPDATE merchandise_transactions mt
            SET transaction_type = 'combined'
            WHERE transaction_type = 'merchandise'
              AND job_order_service IS NOT NULL
              AND job_order_service != ''
              AND EXISTS (
                  SELECT 1 FROM merchandise_transaction_items mti
                  WHERE mti.transaction_id = mt.id
                    AND COALESCE(mti.item_type, 'merchandise') = 'merchandise'
              )
        ");
        $combined_count = $stmt->rowCount();
        echo "✓ Migrated $combined_count records to combined type\n";
        
        // Remaining records = merchandise only (already set by default)
        $stmt = $pdo->query("
            SELECT COUNT(*) FROM merchandise_transactions
            WHERE transaction_type = 'merchandise'
        ");
        $merchandise_count = $stmt->fetchColumn();
        echo "✓ $merchandise_count records remain as merchandise type\n";
        
    } catch (PDOException $e) {
        echo "⚠ Data migration warning: " . $e->getMessage() . "\n";
    }
    
    // ========== STEP 6: Verify migration ==========
    echo "\nStep 6: Verifying migration...\n";
    
    $stmt = $pdo->query("
        SELECT 
            transaction_type,
            COUNT(*) as count
        FROM merchandise_transactions
        GROUP BY transaction_type
    ");
    $counts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\nTransaction type distribution:\n";
    foreach ($counts as $row) {
        echo "  - " . ucfirst($row['transaction_type']) . ": " . $row['count'] . " records\n";
    }
    
    echo "\n✅ Migration completed successfully!\n";
    echo "\nNext steps:\n";
    echo "1. Access the unified transaction form at: public/staff_transactions_unified.php\n";
    echo "2. Test creating job_order, merchandise, and combined transactions\n";
    echo "3. Verify each tab shows the correct transaction types\n";
    
} catch (Exception $e) {
    echo "\n❌ Migration failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
