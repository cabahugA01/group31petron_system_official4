<?php
require 'public/db_connect.php';

$tables = [
    'inventory_products',
    'merchandise_batches',
    'merchandise_deliveries',
    'fuel_batches',
    'fuel_deliveries',
    'deliveries_oversight',
    'purchase_orders',
    'fuel_purchase_orders',
    'fuel_types'
];

foreach ($tables as $table) {
    echo "=== TABLE: $table ===\n";
    try {
        $columns = $pdo->query("DESCRIBE `$table`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $c) {
            echo "  {$c['Field']} - {$c['Type']} - " . ($c['Null'] == 'YES' ? 'NULL' : 'NOT NULL') . " - {$c['Key']} - {$c['Default']}\n";
        }
    } catch (Exception $e) {
        echo "  Error: " . $e->getMessage() . "\n";
    }
    echo "\n";
}
