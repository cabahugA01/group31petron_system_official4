<?php
require_once __DIR__ . '/../public/db_connect.php';

function show_cols($pdo, $tbl) {
    echo "--- Table: $tbl ---\n";
    try {
        $st = $pdo->query("DESCRIBE $tbl");
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            echo "  {$r['Field']} | {$r['Type']} | {$r['Null']} | {$r['Default']}\n";
        }
    } catch (Exception $e) {
        echo "  Table does not exist or error: " . $e->getMessage() . "\n";
    }
}

show_cols($pdo, 'inventory_logs');
show_cols($pdo, 'station_inventory');
show_cols($pdo, 'inventory_products');
show_cols($pdo, 'merchandise_stock_in');
