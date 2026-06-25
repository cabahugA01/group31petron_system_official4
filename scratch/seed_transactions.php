<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== Seeding Fuel Transactions for Station 1253 ===\n";

try {
    // Let's clear test transactions first to keep database clean and fresh
    $pdo->exec("DELETE FROM fuel_transactions WHERE station_id = 1253");
    
    // Seed data
    $txs = [
        [
            'transaction_id' => 'TXN-1253-20260624-001',
            'station_id' => 1253,
            'pump_id' => 9,
            'fuel_type' => 'Diesel',
            'previous_reading' => 1000.00,
            'present_reading' => 1250.00,
            'calibration' => 2.00,
            'price_per_liter' => 58.50,
            'payment_method' => 'Cash',
            'shift_period' => 'Morning',
            'shift_name' => 'Morning Shift',
            'staff_id' => 2, // Judy
            'transaction_date' => '2026-06-24 07:30:00',
            'status' => 'Verified',
            'validated_by' => 3, // Edgar
            'validated_at' => '2026-06-24 13:00:00',
            'notes' => 'Validated and matches sales receipt.'
        ],
        [
            'transaction_id' => 'TXN-1253-20260624-002',
            'station_id' => 1253,
            'pump_id' => 10,
            'fuel_type' => 'Turbo Diesel',
            'previous_reading' => 850.00,
            'present_reading' => 1100.00,
            'calibration' => 0.00,
            'price_per_liter' => 64.20,
            'payment_method' => 'Card',
            'shift_period' => 'Afternoon',
            'shift_name' => 'Afternoon Shift',
            'staff_id' => 2,
            'transaction_date' => '2026-06-24 15:45:00',
            'status' => 'Pending Validation',
            'validated_by' => null,
            'validated_at' => null,
            'notes' => 'Awaiting manager approval'
        ],
        [
            'transaction_id' => 'TXN-1253-20260625-001',
            'station_id' => 1253,
            'pump_id' => 11,
            'fuel_type' => 'XCS Plus',
            'previous_reading' => 2100.00,
            'present_reading' => 2340.00,
            'calibration' => 5.00,
            'price_per_liter' => 68.10,
            'payment_method' => 'Cash',
            'shift_period' => 'Morning',
            'shift_name' => 'Morning Shift',
            'staff_id' => 2,
            'transaction_date' => '2026-06-25 09:15:00',
            'status' => 'Verified',
            'validated_by' => 3,
            'validated_at' => '2026-06-25 09:50:00',
            'notes' => 'Regular validation'
        ],
        [
            'transaction_id' => 'TXN-1253-20260625-002',
            'station_id' => 1253,
            'pump_id' => 12,
            'fuel_type' => 'XTRA UNL',
            'previous_reading' => 3400.00,
            'present_reading' => 3800.00,
            'calibration' => 10.00,
            'price_per_liter' => 61.90,
            'payment_method' => 'Cash',
            'shift_period' => 'Morning',
            'shift_name' => 'Morning Shift',
            'staff_id' => 2,
            'transaction_date' => '2026-06-25 08:00:00',
            'status' => 'Rejected',
            'validated_by' => 3,
            'validated_at' => '2026-06-25 09:30:00',
            'notes' => 'Calibration value misreported.',
            'reject_reason' => 'Incorrect calibration input.'
        ],
        [
            'transaction_id' => 'TXN-1253-20260625-003',
            'station_id' => 1253,
            'pump_id' => 13,
            'fuel_type' => 'Kerosene',
            'previous_reading' => 450.00,
            'present_reading' => 520.00,
            'calibration' => 1.00,
            'price_per_liter' => 72.40,
            'payment_method' => 'Cash',
            'shift_period' => 'Afternoon',
            'shift_name' => 'Afternoon Shift',
            'staff_id' => 2,
            'transaction_date' => '2026-06-25 14:00:00',
            'status' => 'Pending Validation',
            'validated_by' => null,
            'validated_at' => null,
            'notes' => 'End of day review requested.'
        ]
    ];

    $insert = $pdo->prepare("INSERT INTO fuel_transactions (
        transaction_id, station_id, pump_id, fuel_type, 
        previous_reading, present_reading, calibration, liters_sold, price_per_liter, total_amount,
        payment_method, shift_period, shift_name, staff_id, transaction_date, status, validated_by, validated_at, notes, reject_reason
    ) VALUES (
        :transaction_id, :station_id, :pump_id, :fuel_type,
        :previous_reading, :present_reading, :calibration, :liters_sold, :price_per_liter, :total_amount,
        :payment_method, :shift_period, :shift_name, :staff_id, :transaction_date, :status, :validated_by, :validated_at, :notes, :reject_reason
    )");

    foreach ($txs as $tx) {
        $liters_sold = $tx['present_reading'] - $tx['previous_reading'] - $tx['calibration'];
        $total_amount = $liters_sold * $tx['price_per_liter'];
        
        $params = [
            ':transaction_id' => $tx['transaction_id'],
            ':station_id' => $tx['station_id'],
            ':pump_id' => $tx['pump_id'],
            ':fuel_type' => $tx['fuel_type'],
            ':previous_reading' => $tx['previous_reading'],
            ':present_reading' => $tx['present_reading'],
            ':calibration' => $tx['calibration'],
            ':liters_sold' => $liters_sold,
            ':price_per_liter' => $tx['price_per_liter'],
            ':total_amount' => $total_amount,
            ':payment_method' => $tx['payment_method'],
            ':shift_period' => $tx['shift_period'],
            ':shift_name' => $tx['shift_name'],
            ':staff_id' => $tx['staff_id'],
            ':transaction_date' => $tx['transaction_date'],
            ':status' => $tx['status'],
            ':validated_by' => $tx['validated_by'],
            ':validated_at' => $tx['validated_at'],
            ':notes' => $tx['notes'],
            ':reject_reason' => $tx['reject_reason'] ?? null
        ];
        
        $insert->execute($params);
    }
    
    echo "Successfully seeded " . count($txs) . " transactions for station 1253!\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
