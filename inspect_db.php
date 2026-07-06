<?php
require_once 'public/db_connect.php';
$stmt = $pdo->query("SHOW TABLES");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "=== ALL TABLES ===\n";
print_r($tables);

echo "\n=== VEHICLE TYPES SAMPLE ===\n";
try {
    $stmt = $pdo->query("SELECT * FROM vehicle_types LIMIT 5");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch(Exception $e) { echo $e->getMessage() . "\n"; }
