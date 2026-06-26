<?php
require_once __DIR__ . '/../public/db_connect.php';
$tables = ['suppliers', 'stations', 'purchase_orders', 'fuel_purchase_orders'];
foreach ($tables as $t) {
    echo "=== Table: $t ===\n";
    try {
        $q = $pdo->query("DESCRIBE $t");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            echo "  {$row['Field']} - {$row['Type']}\n";
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
