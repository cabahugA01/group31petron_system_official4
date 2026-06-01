<?php
require_once __DIR__ . '/public/db_connect.php';

$station_id = 1253;
$user_id = 21;

// fuel_transactions
try {
    $r = $pdo->query('DESCRIBE fuel_transactions');
    echo "fuel_transactions columns:\n";
    foreach($r->fetchAll(PDO::FETCH_ASSOC) as $col) echo "  " . $col['Field'] . " (" . $col['Type'] . ")\n";
    $cnt = $pdo->query('SELECT COUNT(*) FROM fuel_transactions')->fetchColumn();
    echo "Total rows: $cnt\n";
    $sample = $pdo->query("SELECT * FROM fuel_transactions WHERE station_id=$station_id LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
    foreach($sample as $row) echo json_encode($row) . "\n";
} catch(Exception $e) { echo "fuel_transactions: " . $e->getMessage() . "\n"; }

echo "\n";

// fuel_sales
try {
    $r = $pdo->query('DESCRIBE fuel_sales');
    echo "fuel_sales columns:\n";
    foreach($r->fetchAll(PDO::FETCH_ASSOC) as $col) echo "  " . $col['Field'] . " (" . $col['Type'] . ")\n";
    $cnt = $pdo->query('SELECT COUNT(*) FROM fuel_sales')->fetchColumn();
    echo "Total rows: $cnt\n";
    $sample = $pdo->query("SELECT * FROM fuel_sales LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
    foreach($sample as $row) echo json_encode($row) . "\n";
} catch(Exception $e) { echo "fuel_sales: " . $e->getMessage() . "\n"; }

echo "\n";

// transactions (generic)
try {
    $r = $pdo->query('DESCRIBE transactions');
    echo "transactions columns:\n";
    foreach($r->fetchAll(PDO::FETCH_ASSOC) as $col) echo "  " . $col['Field'] . " (" . $col['Type'] . ")\n";
    $cnt = $pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
    echo "Total rows: $cnt\n";
    $sample = $pdo->query("SELECT * FROM transactions WHERE station_id=$station_id LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
    foreach($sample as $row) echo json_encode($row) . "\n";
} catch(Exception $e) { echo "transactions: " . $e->getMessage() . "\n"; }

echo "\n";

// fuel_readings - already know this exists
$cnt = $pdo->query("SELECT COUNT(*) FROM fuel_readings WHERE station_id=$station_id AND encoded_by=$user_id")->fetchColumn();
echo "fuel_readings for user $user_id: $cnt\n";

// Check all tables with 'fuel'
$tables = $pdo->query("SHOW TABLES LIKE '%fuel%'")->fetchAll(PDO::FETCH_COLUMN);
echo "\nAll fuel tables: " . implode(', ', $tables) . "\n";

// Check all tables with 'transaction'
$tables2 = $pdo->query("SHOW TABLES LIKE '%transaction%'")->fetchAll(PDO::FETCH_COLUMN);
echo "All transaction tables: " . implode(', ', $tables2) . "\n";
