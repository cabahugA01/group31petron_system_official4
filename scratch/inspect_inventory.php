<?php
require_once __DIR__ . '/../public/db_connect.php';

$tables = [
    'products', 'merchandise_batches', 'deliveries_oversight',
    'inventory_logs', 'inventory_adjustments', 'product_categories',
    'station_inventory', 'fuel_inventory', 'users'
];

foreach ($tables as $t) {
    echo "\n=== TABLE: $t ===\n";
    try {
        $cols = $pdo->query("DESCRIBE `$t`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) echo "  {$c['Field']} ({$c['Type']})\n";
        $sample = $pdo->query("SELECT * FROM `$t` LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
        if ($sample) { echo "  SAMPLE: "; print_r($sample[0]); }
    } catch(Exception $e) { echo "  ERROR: {$e->getMessage()}\n"; }
}
