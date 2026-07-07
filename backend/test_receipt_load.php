<?php
// Simulate receipt loading to test if data is retrieved properly
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../public/db_connect.php';

$id = 'MERCH2026125350963';
$type = 'merchandise';

echo "Testing Receipt Data Load for: $id\n";
echo str_repeat("=", 70) . "\n\n";

try {
    // Test the exact query used in receipt.php
    $stmt = $pdo->prepare("
        SELECT mt.*,
               COALESCE(u.username, 'Staff') AS staff_name,
               COALESCE(s.name, 'Petron Station') AS station_name,
               COALESCE(s.location, '') AS station_location,
               COALESCE(s.address, 'Vamenta Blvd., Carmen, CDO') AS station_address,
               COALESCE(s.vat_tin, '236-002-207-0000') AS station_vat_tin
        FROM merchandise_transactions mt
        LEFT JOIN users u ON mt.staff_id = u.id
        LEFT JOIN stations s ON mt.station_id = s.id
        WHERE mt.transaction_id = ?
           OR mt.transaction_id LIKE ?
           OR mt.id = ?
        LIMIT 1
    ");
    
    $numeric_id = is_numeric($id) ? (int)$id : 0;
    $stmt->execute([$id, $id.'%', $numeric_id]);
    $txn = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($txn) {
        echo "✓ TRANSACTION FOUND\n";
        echo "  Transaction ID: {$txn['transaction_id']}\n";
        echo "  Customer: {$txn['customer_name']}\n";
        echo "  Staff Name (from query): {$txn['staff_name']}\n";
        echo "  Transaction Type: {$txn['transaction_type']}\n";
        echo "\n";
        
        // Test items query
        $stmt2 = $pdo->prepare("
            SELECT product_name, category, size_variant, quantity, unit_price, subtotal,
                   COALESCE(item_type, 'merchandise') AS item_type
            FROM merchandise_transaction_items
            WHERE transaction_id = ?
        ");
        $stmt2->execute([$txn['id']]);
        $items = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        
        echo "✓ ITEMS FOUND: " . count($items) . "\n";
        foreach ($items as $item) {
            echo "  - {$item['product_name']} (Type: {$item['item_type']}, Qty: {$item['quantity']}, ₱{$item['subtotal']})\n";
        }
        echo "\n";
        
        // Test job order data
        $has_job_order = !empty($txn['job_order_service']) || !empty($txn['job_order_vehicle_plate']);
        echo "✓ JOB ORDER DATA: " . ($has_job_order ? "YES" : "NO") . "\n";
        if ($has_job_order) {
            echo "  Service: " . ($txn['job_order_service'] ?? 'NULL') . "\n";
            echo "  Vehicle: " . ($txn['job_order_vehicle_plate'] ?? 'NULL') . "\n";
            echo "  Mechanic: " . ($txn['job_order_mechanic_name'] ?? 'NULL') . "\n";
        }
        echo "\n";
        
        echo "✅ ALL DATA RETRIEVED SUCCESSFULLY!\n";
        echo "\nReceipt should display:\n";
        echo "  - Staff: {$txn['staff_name']}\n";
        echo "  - Items: " . count($items) . " items\n";
        echo "  - Job Order: " . ($has_job_order ? "YES" : "NO") . "\n";
        
    } else {
        echo "✗ TRANSACTION NOT FOUND\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
?>
