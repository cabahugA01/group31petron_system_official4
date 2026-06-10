<?php
// Insert sample fuel delivery data for testing
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$station_id = user_station_id();

echo "<h1>Insert Sample Fuel Deliveries</h1>";
echo "<p><strong>Station ID:</strong> {$station_id}</p>";
echo "<p><strong>User ID:</strong> {$me['id']}</p>";
echo "<hr>";

// Sample deliveries
$samples = [
    [
        'batch_id' => 'BATCH-20260610-001',
        'fuel_type' => 'Diesel',
        'tank' => 'Underground Tank #1',
        'liters' => 5000.00,
        'invoice' => 'INV-2026-001',
        'tanker' => 'TRK-001',
    ],
    [
        'batch_id' => 'BATCH-20260610-001',
        'fuel_type' => 'Diesel',
        'tank' => 'Underground Tank #2',
        'liters' => 5000.00,
        'invoice' => 'INV-2026-001',
        'tanker' => 'TRK-001',
    ],
    [
        'batch_id' => 'BATCH-20260610-002',
        'fuel_type' => 'XCS Plus',
        'tank' => 'Underground Tank #10',
        'liters' => 8000.00,
        'invoice' => 'INV-2026-002',
        'tanker' => 'TRK-002',
    ],
    [
        'batch_id' => 'BATCH-20260609-001',
        'fuel_type' => 'XTRA UNL',
        'tank' => 'Underground Tank #14',
        'liters' => 6000.00,
        'invoice' => 'INV-2026-003',
        'tanker' => 'TRK-003',
    ],
];

echo "<h2>Inserting Sample Data...</h2>";

try {
    $inserted = 0;
    foreach ($samples as $s) {
        $stmt = $pdo->prepare("
            INSERT INTO fuel_deliveries
                (batch_id, station_id, delivery_date, fuel_type, supplier, invoice_no,
                 delivery_liters, tank_assigned, tanker_number, received_by, notes, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending Manager Validation', NOW())
        ");
        
        $stmt->execute([
            $s['batch_id'],
            $station_id,
            date('Y-m-d'), // today
            $s['fuel_type'],
            'Petron Corporation',
            $s['invoice'],
            $s['liters'],
            $s['tank'],
            $s['tanker'],
            $me['id'],
            'Sample delivery for testing'
        ]);
        
        $inserted++;
        
        echo "<p style='color:green;'>✅ Inserted: {$s['fuel_type']} - {$s['liters']} L - {$s['tank']} - Batch: {$s['batch_id']}</p>";
    }
    
    echo "<hr>";
    echo "<h3 style='color:green;'>✅ SUCCESS! Inserted {$inserted} sample fuel delivery records</h3>";
    
    // Show what was inserted
    echo "<h2>Inserted Records:</h2>";
    $stmt = $pdo->prepare("SELECT * FROM fuel_deliveries WHERE station_id = ? ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$station_id]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' cellpadding='8' style='border-collapse:collapse;'>";
    echo "<tr style='background:#f0f0f0;'>";
    echo "<th>ID</th><th>Batch ID</th><th>Fuel Type</th><th>Invoice</th><th>Liters</th><th>Tank</th><th>Tanker</th><th>Status</th><th>Created</th>";
    echo "</tr>";
    
    foreach ($records as $r) {
        $highlight = (stripos($r['fuel_type'], 'diesel') !== false) ? "background:#ffffcc;" : "";
        echo "<tr style='{$highlight}'>";
        echo "<td>{$r['id']}</td>";
        echo "<td style='font-family:monospace;'><strong>{$r['batch_id']}</strong></td>";
        echo "<td><strong>{$r['fuel_type']}</strong></td>";
        echo "<td>{$r['invoice_no']}</td>";
        echo "<td><strong>" . number_format($r['delivery_liters'], 2) . " L</strong></td>";
        echo "<td>{$r['tank_assigned']}</td>";
        echo "<td>{$r['tanker_number']}</td>";
        echo "<td><strong>{$r['status']}</strong></td>";
        echo "<td>{$r['created_at']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
} catch (Exception $e) {
    echo "<p style='color:red;'>❌ Error: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<h2>Next Steps:</h2>";
echo "<ol>";
echo "<li>Go to <a href='staff_fuel_delivery_status.php'><strong>Fuel Deliveries History</strong></a> page</li>";
echo "<li>Refresh the page</li>";
echo "<li>You should now see the sample fuel delivery records</li>";
echo "</ol>";

echo "<hr>";
echo "<p><a href='staff_fuel_delivery_status.php' style='display:inline-block; background:#002F70; color:#fff; padding:12px 24px; border-radius:8px; text-decoration:none; font-weight:600;'>→ Go to Fuel Deliveries History</a></p>";
?>
