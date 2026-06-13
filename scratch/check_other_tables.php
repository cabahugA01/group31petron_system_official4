<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== PURCHASE_ORDERS COLUMNS ===\n";
try {
    $stmt = $pdo->query('SHOW COLUMNS FROM purchase_orders');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo "Field: {$row['Field']} | Type: {$row['Type']}\n";
    }
} catch (Exception $e) { echo $e->getMessage() . "\n"; }

echo "\n=== SUPPLIERS COLUMNS ===\n";
try {
    $stmt = $pdo->query('SHOW COLUMNS FROM suppliers');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo "Field: {$row['Field']} | Type: {$row['Type']}\n";
    }
} catch (Exception $e) { echo $e->getMessage() . "\n"; }

echo "\n=== MERCHANDISE_DELIVERIES COLUMNS ===\n";
try {
    $stmt = $pdo->query('SHOW COLUMNS FROM merchandise_deliveries');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo "Field: {$row['Field']} | Type: {$row['Type']}\n";
    }
} catch (Exception $e) { echo $e->getMessage() . "\n"; }
