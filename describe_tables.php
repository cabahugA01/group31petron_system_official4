<?php
require_once __DIR__ . '/public/db_connect.php';

echo "=== fuel_transactions ===\n";
$stmt = $pdo->query("DESCRIBE fuel_transactions");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {  echo "{$row['Field']} - {$row['Type']}\n";
}

echo "\n=== fuel_inventory ===\n";
$stmt = $pdo->query("DESCRIBE fuel_inventory");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {  echo "{$row['Field']} - {$row['Type']}\n";
}

echo "\n=== fuel_pumps ===\n";
$stmt = $pdo->query("DESCRIBE fuel_pumps");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {  echo "{$row['Field']} - {$row['Type']}\n";
}
