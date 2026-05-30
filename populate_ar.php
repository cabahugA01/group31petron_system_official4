<?php
require_once __DIR__ . '/public/db_connect.php';
header('Content-Type: text/plain');

try {
    // Clear out empty records if any
    $pdo->exec("DELETE FROM accounts_receivable");

    $records = [
        // ABC Transport Services
        [
            'station_id' => 1253,
            'customer_id' => 18,
            'transaction_id' => 'TXN-AR-001',
            'fuel_type' => 'Diesel Max',
            'amount' => 15750.00,
            'status' => 'pending',
            'due_date' => date('Y-m-d', strtotime('+15 days')),
            'created_at' => date('Y-m-d H:i:s', strtotime('-15 days'))
        ],
        [
            'station_id' => 1253,
            'customer_id' => 18,
            'transaction_id' => 'TXN-AR-002',
            'fuel_type' => 'XCS Plus',
            'amount' => 8420.50,
            'status' => 'overdue',
            'due_date' => date('Y-m-d', strtotime('-5 days')),
            'created_at' => date('Y-m-d H:i:s', strtotime('-35 days'))
        ],
        // Sandara / ulala
        [
            'station_id' => 1253,
            'customer_id' => 13,
            'transaction_id' => 'TXN-AR-003',
            'fuel_type' => 'Premium Blue',
            'amount' => 3200.00,
            'status' => 'paid',
            'due_date' => date('Y-m-d', strtotime('+5 days')),
            'created_at' => date('Y-m-d H:i:s', strtotime('-25 days'))
        ],
        // ABC Transport on station 226
        [
            'station_id' => 226,
            'customer_id' => 18,
            'transaction_id' => 'TXN-AR-226-01',
            'fuel_type' => 'Diesel Max',
            'amount' => 24500.00,
            'status' => 'pending',
            'due_date' => date('Y-m-d', strtotime('+20 days')),
            'created_at' => date('Y-m-d H:i:s', strtotime('-10 days'))
        ],
        // Test Customer 12
        [
            'station_id' => 1253,
            'customer_id' => 12,
            'transaction_id' => 'JO-AR-004',
            'fuel_type' => 'Merchandise/Service',
            'amount' => 4550.00,
            'status' => 'pending',
            'due_date' => date('Y-m-d', strtotime('+30 days')),
            'created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))
        ]
    ];

    $stmt = $pdo->prepare("
        INSERT INTO accounts_receivable (
            station_id, customer_id, transaction_id, fuel_type, amount, status, due_date, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($records as $r) {
        $stmt->execute([
            $r['station_id'],
            $r['customer_id'],
            $r['transaction_id'],
            $r['fuel_type'],
            $r['amount'],
            $r['status'],
            $r['due_date'],
            $r['created_at']
        ]);
    }
    
    // Also, let's update credit customer balance based on pending receivables to keep consistency!
    $pdo->exec("UPDATE customers SET balance = 24170.50 WHERE id = 18");
    $pdo->exec("UPDATE customers SET balance = 4550.00 WHERE id = 12");
    
    echo "Successfully populated accounts_receivable with 5 records and updated customer credit balances!\n";

} catch (Exception $e) {
    echo "Error populating: " . $e->getMessage() . "\n";
}
