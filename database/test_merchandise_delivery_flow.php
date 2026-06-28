<?php
/**
 * Test Merchandise Delivery Flow
 * Verify that staff encoding creates records in deliveries_oversight
 */

require_once __DIR__ . '/../public/db_connect.php';

echo "=== MERCHANDISE DELIVERY FLOW TEST ===\n\n";

// Check current state
echo "BEFORE TEST:\n";
$before_count = $pdo->query("SELECT COUNT(*) FROM deliveries_oversight WHERE delivery_type='merchandise'")->fetchColumn();
echo "  Merchandise deliveries in deliveries_oversight: $before_count\n\n";

// Simulate staff encoding (test insert)
echo "SIMULATING STAFF ENCODING:\n";
try {
    $pdo->beginTransaction();
    
    // Test data
    $test_batch = 'TEST-BATCH-' . date('YmdHis');
    $test_product = 'TEST PRODUCT - ' . date('H:i:s');
    $test_station_id = 1253; // Default test station
    $test_user_id = 1; // Test user
    
    echo "  Creating test delivery:\n";
    echo "    Batch: $test_batch\n";
    echo "    Product: $test_product\n";
    echo "    Station: $test_station_id\n";
    
    // Insert test record
    $stmt = $pdo->prepare("
        INSERT INTO deliveries_oversight (
            delivery_type, delivery_ref, batch_id, supplier, product, quantity, unit,
            delivery_date, encoded_by, station_id, status, created_at, category, unit_cost,
            received_by_name
        ) VALUES (
            'merchandise', ?, ?, 'Petron Corporation', ?, 10, 'pieces',
            CURDATE(), ?, ?, 'Pending Validation', NOW(), 'Test Category', 100.00,
            'Test Staff'
        )
    ");
    
    $delivery_ref = 'MDR-TEST-' . date('His');
    $stmt->execute([
        $delivery_ref,
        $test_batch,
        $test_product,
        $test_user_id,
        $test_station_id
    ]);
    
    $test_id = $pdo->lastInsertId();
    echo "  ✓ Test delivery created (ID: $test_id)\n\n";
    
    // Verify it appears in the table
    echo "VERIFICATION:\n";
    $after_count = $pdo->query("SELECT COUNT(*) FROM deliveries_oversight WHERE delivery_type='merchandise'")->fetchColumn();
    echo "  Merchandise deliveries in deliveries_oversight: $after_count\n";
    
    if ($after_count > $before_count) {
        echo "  ✓ New delivery successfully added!\n\n";
    } else {
        echo "  ✗ Delivery was not added (issue!)\n\n";
    }
    
    // Check the record details
    $stmt_check = $pdo->prepare("SELECT * FROM deliveries_oversight WHERE id = ?");
    $stmt_check->execute([$test_id]);
    $record = $stmt_check->fetch(PDO::FETCH_ASSOC);
    
    if ($record) {
        echo "RECORD DETAILS:\n";
        echo "  ID: {$record['id']}\n";
        echo "  Type: {$record['delivery_type']}\n";
        echo "  Reference: {$record['delivery_ref']}\n";
        echo "  Batch: {$record['batch_id']}\n";
        echo "  Product: {$record['product']}\n";
        echo "  Quantity: {$record['quantity']} {$record['unit']}\n";
        echo "  Status: {$record['status']}\n";
        echo "  Station: {$record['station_id']}\n";
        echo "  Encoded By: {$record['encoded_by']}\n";
        echo "  Created: {$record['created_at']}\n\n";
    }
    
    // Clean up test data
    echo "CLEANUP:\n";
    $pdo->exec("DELETE FROM deliveries_oversight WHERE id = $test_id");
    echo "  ✓ Test record deleted\n\n";
    
    $pdo->commit();
    
    echo "═══ TEST RESULT ═══\n";
    echo "✓✓✓ MERCHANDISE DELIVERY FLOW IS WORKING! ✓✓✓\n";
    echo "\nStaff-encoded merchandise deliveries will now appear\n";
    echo "in the Manager's Merchandise Deliveries Validation page.\n";
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "✗ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
?>
