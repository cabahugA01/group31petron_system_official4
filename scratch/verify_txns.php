<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== fuel_transactions for station 1253 ===\n";
$rows = $pdo->query(
    "SELECT ft.id, ft.transaction_id, ft.fuel_type, ft.pump_id, 
     ft.liters_sold, ft.total_amount, ft.status, ft.transaction_date,
     fp.pump_number
     FROM fuel_transactions ft
     LEFT JOIN fuel_pumps fp ON ft.pump_id = fp.id
     WHERE ft.station_id = 1253
     ORDER BY ft.transaction_date DESC"
)->fetchAll(PDO::FETCH_ASSOC);

echo "Count: " . count($rows) . "\n\n";
foreach ($rows as $r) {
    echo "  ID={$r['id']}  TXN={$r['transaction_id']}  Pump={$r['pump_number']}  Fuel={$r['fuel_type']}  Liters={$r['liters_sold']}  Amount={$r['total_amount']}  Status={$r['status']}  Date={$r['transaction_date']}\n";
}

echo "\n=== Checking query used by oversight page (date range last 90d) ===\n";
$date_from = date('Y-m-d', strtotime('-90 days'));
$date_to   = date('Y-m-d');
echo "Range: $date_from to $date_to\n";
$stmt = $pdo->prepare("SELECT COUNT(*) FROM fuel_transactions WHERE station_id=1253 AND DATE(transaction_date) BETWEEN ? AND ?");
$stmt->execute([$date_from, $date_to]);
echo "Records in range: " . $stmt->fetchColumn() . "\n";
