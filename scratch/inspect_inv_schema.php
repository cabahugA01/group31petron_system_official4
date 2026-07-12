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

inspectTable($pdo, 'inventory_products');
inspectTable($pdo, 'station_inventory');
inspectTable($pdo, 'fuel_inventory');
