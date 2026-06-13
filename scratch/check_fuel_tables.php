<?php
require_once __DIR__ . '/../public/db_connect.php';
echo "=== FUEL_BATCHES ===\n";
try {
    $stmt = $pdo->query('SHOW COLUMNS FROM fuel_batches');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo "Field: {$row['Field']} | Type: {$row['Type']}\n";
    }
} catch (Exception $e) { echo $e->getMessage() . "\n"; }

echo "=== FUEL_SUPPLIERS ===\n";
try {
    $stmt = $pdo->query('SHOW COLUMNS FROM fuel_suppliers');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo "Field: {$row['Field']} | Type: {$row['Type']}\n";
    }
} catch (Exception $e) { echo $e->getMessage() . "\n"; }
