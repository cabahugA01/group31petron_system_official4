<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "Cleaning existing deliveries for Station 1253...\n";
$pdo->exec("DELETE FROM fuel_deliveries WHERE station_id = 1253");

$deliveries = [
    [
        'batch_id' => 'BAT-20260620-01',
        'station_id' => 1253,
        'delivery_date' => '2026-06-20',
        'fuel_type' => 'XCS',
        'supplier' => 'Petron Corporation Depot',
        'invoice_no' => 'DR-992811',
        'delivery_liters' => 8000.00,
        'tank_assigned' => 'Tank 1 - XCS',
        'tanker_number' => 'TKR-5521',
        'received_by' => 2, // Judy
        'verified_by' => 3, // Edgar
        'verified_at' => '2026-06-20 14:30:00',
        'notes' => 'Received full tank. No leakage observed.',
        'status' => 'Verified'
    ],
    [
        'batch_id' => 'BAT-20260621-02',
        'station_id' => 1253,
        'delivery_date' => '2026-06-21',
        'fuel_type' => 'Diesel Max',
        'supplier' => 'Petron Corporation Depot',
        'invoice_no' => 'DR-992812',
        'delivery_liters' => 10000.00,
        'tank_assigned' => 'Tank 2 - Diesel',
        'tanker_number' => 'TKR-5522',
        'received_by' => 2, // Judy
        'verified_by' => 3, // Edgar
        'verified_at' => '2026-06-21 16:15:00',
        'notes' => 'Volume matched delivery receipt perfectly.',
        'status' => 'Verified'
    ],
    [
        'batch_id' => 'BAT-20260622-03',
        'station_id' => 1253,
        'delivery_date' => '2026-06-22',
        'fuel_type' => 'Prima',
        'supplier' => 'Subic Petroleum Corp',
        'invoice_no' => 'DR-442819',
        'delivery_liters' => 5000.00,
        'tank_assigned' => 'Tank 3 - Prima',
        'tanker_number' => 'PET-1002',
        'received_by' => 2, // Judy
        'verified_by' => null,
        'verified_at' => null,
        'notes' => 'Awaiting validation. Driver seal intact.',
        'status' => 'Pending'
    ],
    [
        'batch_id' => 'BAT-20260623-04',
        'station_id' => 1253,
        'delivery_date' => '2026-06-23',
        'fuel_type' => 'XCS',
        'supplier' => 'Petron Corporation Depot',
        'invoice_no' => 'DR-992813',
        'delivery_liters' => 8000.00,
        'tank_assigned' => 'Tank 1 - XCS',
        'tanker_number' => 'TKR-5523',
        'received_by' => 2, // Judy
        'verified_by' => 4, // Kathrine
        'verified_at' => '2026-06-23 11:00:00',
        'notes' => 'Verified by Kathrine. Seals checked and verified.',
        'status' => 'Verified'
    ],
    [
        'batch_id' => 'BAT-20260624-05',
        'station_id' => 1253,
        'delivery_date' => '2026-06-24',
        'fuel_type' => 'Diesel Max',
        'supplier' => 'Petron Corporation Depot',
        'invoice_no' => 'DR-992814',
        'delivery_liters' => 12000.00,
        'tank_assigned' => 'Tank 2 - Diesel',
        'tanker_number' => 'TKR-5524',
        'received_by' => 2, // Judy
        'verified_by' => 3, // Edgar
        'verified_at' => '2026-06-24 15:45:00',
        'notes' => 'Rejected due to water contamination flag in sample.',
        'status' => 'Rejected'
    ],
    [
        'batch_id' => 'BAT-20260625-06',
        'station_id' => 1253,
        'delivery_date' => '2026-06-25',
        'fuel_type' => 'Prima',
        'supplier' => 'Petron Corporation Depot',
        'invoice_no' => 'DR-992815',
        'delivery_liters' => 6000.00,
        'tank_assigned' => 'Tank 3 - Prima',
        'tanker_number' => 'TKR-5525',
        'received_by' => 2, // Judy
        'verified_by' => null,
        'verified_at' => null,
        'notes' => 'Awaiting manager approval. Download completed.',
        'status' => 'Pending'
    ],
    [
        'batch_id' => 'BAT-20260625-07',
        'station_id' => 1253,
        'delivery_date' => '2026-06-25',
        'fuel_type' => 'XCS',
        'supplier' => 'Subic Petroleum Corp',
        'invoice_no' => 'DR-442820',
        'delivery_liters' => 7000.00,
        'tank_assigned' => 'Tank 1 - XCS',
        'tanker_number' => 'PET-1003',
        'received_by' => 2, // Judy
        'verified_by' => 3, // Edgar
        'verified_at' => '2026-06-25 09:30:00',
        'notes' => 'Verification failed. Discrepancy of over 500L.',
        'status' => 'Rejected'
    ],
    [
        'batch_id' => 'BAT-20260625-08',
        'station_id' => 1253,
        'delivery_date' => '2026-06-25',
        'fuel_type' => 'Diesel Max',
        'supplier' => 'Petron Corporation Depot',
        'invoice_no' => 'DR-992816',
        'delivery_liters' => 8000.00,
        'tank_assigned' => 'Tank 2 - Diesel',
        'tanker_number' => 'TKR-5526',
        'received_by' => 2, // Judy
        'verified_by' => 3, // Edgar
        'verified_at' => '2026-06-25 10:15:00',
        'notes' => 'Verified and accepted.',
        'status' => 'Verified'
    ],
];

foreach ($deliveries as $d) {
    $stmt = $pdo->prepare("INSERT INTO fuel_deliveries 
        (batch_id, station_id, delivery_date, fuel_type, supplier, invoice_no, delivery_liters, tank_assigned, tanker_number, received_by, verified_by, verified_at, notes, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $d['batch_id'],
        $d['station_id'],
        $d['delivery_date'],
        $d['fuel_type'],
        $d['supplier'],
        $d['invoice_no'],
        $d['delivery_liters'],
        $d['tank_assigned'],
        $d['tanker_number'],
        $d['received_by'],
        $d['verified_by'],
        $d['verified_at'],
        $d['notes'],
        $d['status']
    ]);
}

echo "Successfully seeded " . count($deliveries) . " deliveries for Station 1253.\n";
