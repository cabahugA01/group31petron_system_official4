<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

function inspectTable($pdo, $tableName) {
    echo "=== Table: $tableName ===\n";
    try {
        $stmt = $pdo->query("DESCRIBE $tableName");
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "  " . $r['Field'] . " - " . $r['Type'] . " (" . ($r['Null'] == 'YES' ? 'null' : 'not null') . ")\n";
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

inspectTable($pdo, 'deliveries_oversight');
inspectTable($pdo, 'purchase_orders');
inspectTable($pdo, 'purchase_order_items');
inspectTable($pdo, 'fuel_purchase_orders');
