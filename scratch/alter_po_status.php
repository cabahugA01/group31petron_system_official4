<?php
require_once __DIR__ . '/../public/db_connect.php';
try {
    echo "Altering fuel_purchase_orders status column...\n";
    $pdo->exec("ALTER TABLE fuel_purchase_orders MODIFY COLUMN status VARCHAR(100) NOT NULL DEFAULT 'pending'");
    echo "Altering purchase_orders status column...\n";
    $pdo->exec("ALTER TABLE purchase_orders MODIFY COLUMN status VARCHAR(100) NOT NULL DEFAULT 'Draft'");
    echo "Success!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
