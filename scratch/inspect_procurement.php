<?php
require_once __DIR__ . '/../public/db_connect.php';

$tables = [
    'purchase_orders',
    'purchase_order_items',
    'deliveries_oversight',
    'merchandise_stock_in',
    'merchandise_batches',
    'suppliers',
    'fuel_suppliers',
    'product_categories',
];

foreach ($tables as $tbl) {
    echo "\n=== TABLE: $tbl ===\n";
    try {
        $stmt = $pdo->query("DESCRIBE `$tbl`");
        $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) {
            echo "  {$c['Field']}  [{$c['Type']}]  {$c['Key']}\n";
        }
        // Sample row
        $smp = $pdo->query("SELECT * FROM `$tbl` LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($smp) {
            echo "  SAMPLE: " . json_encode($smp) . "\n";
        } else {
            echo "  (no rows)\n";
        }
    } catch (Exception $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
    }
}
