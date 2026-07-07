<?php
require_once __DIR__ . '/db_connect.php';
$tbls = ['products', 'fuel_types', 'inventory_products', 'merchandise_batches'];
foreach ($tbls as $t) {
    echo "=== Table: $t ===\n";
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) {
            echo "  - {$c['Field']} ({$c['Type']})\n";
        }
    } catch (Exception $e) {
        echo "  [Error: " . $e->getMessage() . "]\n";
    }
}
