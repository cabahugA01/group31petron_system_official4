<?php
require_once __DIR__ . '/../public/db_connect.php';
foreach(['stock_requests', 'inventory_products', 'fuel_stock_requests', 'fuel_inventory'] as $t) {
    echo "\n*** Table: $t ***\n";
    try {
        $q = $pdo->query("DESCRIBE $t");
        while($r = $q->fetch(PDO::FETCH_ASSOC)) {
            echo "  {$r['Field']} - {$r['Type']}\n";
        }
    } catch(Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
