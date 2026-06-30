<?php
/**
 * Create Sample Merchandise Deliveries for Station 1
 * Creates test delivery records so the delivery history page has data to display
 */

require_once __DIR__ . '/../public/db_connect.php';

try {
    $pdo->beginTransaction();
    
    $station_id = 1;
    $judy_id = 5; // Judy User (staff)
    $edgar_id = 6; // Edgar User (manager)
    
    echo "Creating sample merchandise deliveries for Station 1...\n\n";
    
    // Sample deliveries
    $deliveries = [
        [
            'delivery_ref' => 'DEL-' . date('Ymd') . '-001',
            'supplier' => 'ABC Trading Inc.',
            'product' => 'Motor Oil 10W-40 (1L)',
            'category' => 'Oils / Lubes / Grease',
            'quantity' => 50.00,
            'unit' => 'pcs',
            'delivery_date' => date('Y-m-d'),
            'dr_number' => 'DR-2026-' . rand(1000, 9999),
            'status' => 'Pending Manager Approval',
            'encoded_by' => $judy_id,
        ],
        [
            'delivery_ref' => 'DEL-' . date('Ymd') . '-002',
            'supplier' => 'XYZ Automotive Supplies',
            'product' => 'Brake Fluid DOT 3 (500ml)',
            'category' => 'Brake System',
            'quantity' => 30.00,
            'unit' => 'bottles',
            'delivery_date' => date('Y-m-d', strtotime('-1 day')),
            'dr_number' => 'DR-2026-' . rand(1000, 9999),
            'status' => 'Confirmed',
            'encoded_by' => $judy_id,
            'manager_id' => $edgar_id,
            'manager_action_at' => date('Y-m-d H:i:s', strtotime('-1 hour')),
            'manager_notes' => 'Verified and approved',
        ],
        [
            'delivery_ref' => 'DEL-' . date('Ymd', strtotime('-2 days')) . '-003',
            'supplier' => 'LMN Parts Distributor',
            'product' => 'Tire Sealant (350ml)',
            'category' => 'Tire',
            'quantity' => 25.00,
            'unit' => 'cans',
            'delivery_date' => date('Y-m-d', strtotime('-2 days')),
            'dr_number' => 'DR-2026-' . rand(1000, 9999),
            'status' => 'Discrepancy',
            'encoded_by' => $judy_id,
            'manager_id' => $edgar_id,
            'manager_action_at' => date('Y-m-d H:i:s', strtotime('-2 days +1 hour')),
            'manager_notes' => 'Quantity mismatch: Expected 30, received 25',
        ],
        [
            'delivery_ref' => 'DEL-' . date('Ymd', strtotime('-3 days')) . '-004',
            'supplier' => 'PQR Supplies Co.',
            'product' => 'Car Wax Premium (500ml)',
            'category' => 'Car Accessories',
            'quantity' => 40.00,
            'unit' => 'bottles',
            'delivery_date' => date('Y-m-d', strtotime('-3 days')),
            'dr_number' => 'DR-2026-' . rand(1000, 9999),
            'status' => 'Closed',
            'encoded_by' => $judy_id,
            'manager_id' => $edgar_id,
            'manager_action_at' => date('Y-m-d H:i:s', strtotime('-3 days +30 minutes')),
            'manager_notes' => 'Approved and inventory updated',
        ],
        [
            'delivery_ref' => 'DEL-' . date('Ymd') . '-005',
            'supplier' => 'RST Oil Products',
            'product' => 'Transmission Fluid ATF (1L)',
            'category' => 'Fluids',
            'quantity' => 35.00,
            'unit' => 'liters',
            'delivery_date' => date('Y-m-d'),
            'dr_number' => 'DR-2026-' . rand(1000, 9999),
            'status' => 'Pending Manager Approval',
            'encoded_by' => $judy_id,
        ],
    ];
    
    $insert_stmt = $pdo->prepare("
        INSERT INTO deliveries_oversight 
        (delivery_type, delivery_ref, supplier, product, category, quantity, unit, 
         delivery_date, dr_number, encoded_by, station_id, status, 
         manager_id, manager_action_at, manager_notes, created_at)
        VALUES 
        ('merchandise', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $count = 0;
    foreach ($deliveries as $delivery) {
        $insert_stmt->execute([
            $delivery['delivery_ref'],
            $delivery['supplier'],
            $delivery['product'],
            $delivery['category'] ?? null,
            $delivery['quantity'],
            $delivery['unit'],
            $delivery['delivery_date'],
            $delivery['dr_number'],
            $delivery['encoded_by'],
            $station_id,
            $delivery['status'],
            $delivery['manager_id'] ?? null,
            $delivery['manager_action_at'] ?? null,
            $delivery['manager_notes'] ?? null,
        ]);
        
        echo "✓ Created: {$delivery['delivery_ref']} - {$delivery['product']} ({$delivery['status']})\n";
        $count++;
    }
    
    $pdo->commit();
    
    echo "\nSUCCESS! Created $count sample deliveries for Station 1\n\n";
    
    // Verify
    $verify_stmt = $pdo->prepare("
        SELECT COUNT(*) FROM deliveries_oversight 
        WHERE station_id = ? AND delivery_type = 'merchandise'
    ");
    $verify_stmt->execute([$station_id]);
    $total = $verify_stmt->fetchColumn();
    
    echo "Verification: Station 1 now has $total merchandise delivery records\n";
    
    echo "\nStatus breakdown:\n";
    $status_stmt = $pdo->prepare("
        SELECT status, COUNT(*) as count
        FROM deliveries_oversight
        WHERE station_id = ? AND delivery_type = 'merchandise'
        GROUP BY status
    ");
    $status_stmt->execute([$station_id]);
    $statuses = $status_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($statuses as $row) {
        echo "  - {$row['status']}: {$row['count']}\n";
    }
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "ERROR: " . $e->getMessage() . "\n";
}
