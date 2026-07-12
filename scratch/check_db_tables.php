<?php
require_once __DIR__ . '/../public/db_connect.php';

try {
    echo "--- List of tables ---\n";
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    print_r($tables);
    
    // Check structure of purchase_orders
    echo "\n--- Structure of purchase_orders ---\n";
    $cols = $pdo->query("DESCRIBE purchase_orders")->fetchAll(PDO::FETCH_ASSOC);
    print_r($cols);
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
