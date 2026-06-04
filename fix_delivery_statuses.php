<?php
/**
 * Delivery Status Standardization Script
 * 
 * This script standardizes all fuel delivery status values to match the system's expectations:
 * - All pending variants → 'Pending'
 * - All approved/verified variants → 'Verified'  
 * - All rejected variants → 'Rejected'
 */

require_once __DIR__ . '/public/db_connect.php';

echo "=== Fuel Deliveries Status Standardization ===\n\n";

try {
    // First, let's see what we're working with
    echo "Current status values in database:\n";
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM fuel_deliveries GROUP BY status ORDER BY status");
    $current_statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($current_statuses as $row) {
        echo "  - '{$row['status']}': {$row['count']} records\n";
    }
    echo "\n";

    $pdo->beginTransaction();

    // Standardize all pending variants to 'Pending'
    $pending_variants = [
        'pending validation',
        'Pending Validation',
        'pending review',
        'Pending Review',
        'pending manager approval',
        'Pending Manager Approval',
        'discrepancy',
        'Discrepancy',
        'pending',
        'PENDING'
    ];

    foreach ($pending_variants as $variant) {
        $stmt = $pdo->prepare("UPDATE fuel_deliveries SET status = 'Pending' WHERE status = ?");
        $stmt->execute([$variant]);
        $affected = $stmt->rowCount();
        if ($affected > 0) {
            echo "✓ Updated $affected records from '$variant' → 'Pending'\n";
        }
    }

    // Standardize all approved/verified variants to 'Verified'
    $verified_variants = [
        'verified',
        'approved',
        'Approved',
        'VERIFIED',
        'APPROVED',
        'Awaiting Stock-In',
        'awaiting stock-in'
    ];

    foreach ($verified_variants as $variant) {
        $stmt = $pdo->prepare("UPDATE fuel_deliveries SET status = 'Verified' WHERE status = ?");
        $stmt->execute([$variant]);
        $affected = $stmt->rowCount();
        if ($affected > 0) {
            echo "✓ Updated $affected records from '$variant' → 'Verified'\n";
        }
    }

    // Standardize all rejected variants to 'Rejected'
    $rejected_variants = [
        'rejected',
        'REJECTED',
        'returned',
        'Returned'
    ];

    foreach ($rejected_variants as $variant) {
        $stmt = $pdo->prepare("UPDATE fuel_deliveries SET status = 'Rejected' WHERE status = ?");
        $stmt->execute([$variant]);
        $affected = $stmt->rowCount();
        if ($affected > 0) {
            echo "✓ Updated $affected records from '$variant' → 'Rejected'\n";
        }
    }

    $pdo->commit();

    echo "\n✅ Status standardization complete!\n\n";

    // Show the final state
    echo "Final status values:\n";
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM fuel_deliveries GROUP BY status ORDER BY status");
    $final_statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($final_statuses as $row) {
        echo "  - '{$row['status']}': {$row['count']} records\n";
    }

    echo "\n🎉 All delivery statuses are now aligned!\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
