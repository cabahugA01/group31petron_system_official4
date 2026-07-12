<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

$tables = [
    'stock_requests',
    'fuel_stock_requests',
    'purchase_orders',
    'fuel_purchase_orders',
    'deliveries_oversight',
    'merchandise_stock_in',
    'fuel_deliveries',
    'inventory_logs',
    'fuel_adjustments'
];

foreach ($tables as $t) {
    echo "=== Table: $t ===\n";
    try {
        $stmt = $pdo->query("DESCRIBE $t");
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "  " . $r['Field'] . " - " . $r['Type'] . "\n";
        }
    } catch (Exception $e) {
        echo "  Error: " . $e->getMessage() . "\n";
    }
}
