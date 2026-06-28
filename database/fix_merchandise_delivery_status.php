<?php
/**
 * FIX: Update Merchandise Delivery Status
 * 
 * Problem: Staff-encoded deliveries were using 'Pending Verification' or 'Pending Validation'
 * but Manager page expects 'Pending Manager Approval'
 * 
 * This script updates all existing records to use the correct status
 */

require_once __DIR__ . '/../public/db_connect.php';

echo "═══════════════════════════════════════════════════════════\n";
echo "  FIX: Merchandise Delivery Status\n";
echo "═══════════════════════════════════════════════════════════\n\n";

try {
    $pdo->beginTransaction();
    
    // Check current status values
    echo "Step 1: Checking current status values...\n";
    $stmt = $pdo->query("
        SELECT status, COUNT(*) as count 
        FROM deliveries_oversight 
        WHERE delivery_type = 'merchandise'
        GROUP BY status
        ORDER BY count DESC
    ");
    $statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Current status breakdown:\n";
    foreach ($statuses as $s) {
        echo "  - {$s['status']}: {$s['count']} records\n";
    }
    echo "\n";
    
    // Update 'Pending Verification' to 'Pending Manager Approval'
    echo "Step 2: Updating 'Pending Verification' → 'Pending Manager Approval'...\n";
    $stmt = $pdo->prepare("
        UPDATE deliveries_oversight 
        SET status = 'Pending Manager Approval', updated_at = NOW()
        WHERE delivery_type = 'merchandise' 
        AND status = 'Pending Verification'
    ");
    $stmt->execute();
    $count1 = $stmt->rowCount();
    echo "  ✓ Updated {$count1} records\n\n";
    
    // Update 'Pending Validation' to 'Pending Manager Approval'
    echo "Step 3: Updating 'Pending Validation' → 'Pending Manager Approval'...\n";
    $stmt = $pdo->prepare("
        UPDATE deliveries_oversight 
        SET status = 'Pending Manager Approval', updated_at = NOW()
        WHERE delivery_type = 'merchandise' 
        AND status = 'Pending Validation'
    ");
    $stmt->execute();
    $count2 = $stmt->rowCount();
    echo "  ✓ Updated {$count2} records\n\n";
    
    $pdo->commit();
    
    // Check updated status values
    echo "Step 4: Verifying updated status values...\n";
    $stmt = $pdo->query("
        SELECT status, COUNT(*) as count 
        FROM deliveries_oversight 
        WHERE delivery_type = 'merchandise'
        GROUP BY status
        ORDER BY count DESC
    ");
    $statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Updated status breakdown:\n";
    foreach ($statuses as $s) {
        echo "  - {$s['status']}: {$s['count']} records\n";
    }
    echo "\n";
    
    // Check how many pending records manager should see
    echo "Step 5: Checking Manager validation queue...\n";
    $stmt = $pdo->query("
        SELECT COUNT(*) as count
        FROM deliveries_oversight
        WHERE delivery_type = 'merchandise'
        AND status IN ('Pending Manager Approval', 'Pending Manager Confirmation', 'Pending Validation', 'Pending Verification')
    ");
    $pending_count = $stmt->fetchColumn();
    echo "  ✓ Manager should see {$pending_count} pending merchandise deliveries\n\n";
    
    // Show sample records that should appear in Manager page
    echo "Step 6: Sample records for Manager validation:\n";
    $stmt = $pdo->query("
        SELECT id, delivery_ref, product, quantity, unit, supplier, delivery_date, status
        FROM deliveries_oversight
        WHERE delivery_type = 'merchandise'
        AND status IN ('Pending Manager Approval', 'Pending Manager Confirmation', 'Pending Validation', 'Pending Verification')
        ORDER BY delivery_date DESC
        LIMIT 5
    ");
    $samples = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($samples) > 0) {
        foreach ($samples as $s) {
            echo "  - ID {$s['id']}: {$s['delivery_ref']} | {$s['product']} | {$s['quantity']} {$s['unit']} | {$s['supplier']} | {$s['delivery_date']} | {$s['status']}\n";
        }
    } else {
        echo "  ℹ No pending deliveries found\n";
    }
    
    echo "\n";
    echo "═══════════════════════════════════════════════════════════\n";
    echo "  FIX COMPLETE\n";
    echo "═══════════════════════════════════════════════════════════\n";
    echo "Total records updated: " . ($count1 + $count2) . "\n";
    echo "Status: ✅ SUCCESS\n";
    echo "\n";
    echo "NEXT STEPS:\n";
    echo "1. Refresh Manager Merchandise Deliveries page\n";
    echo "2. Verify deliveries now appear in the table\n";
    echo "3. Test approve/reject functionality\n";
    echo "═══════════════════════════════════════════════════════════\n";
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    echo "Status: FAILED\n";
}
