<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== TABLES ===\n";
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    echo "- $t\n";
}

$target_tables = ['fuel_deliveries', 'fuel_transactions', 'fuel_adjustments', 'fuel_pumps', 'fuel_inventory', 'tanks'];
foreach ($target_tables as $tbl) {
    if (in_array($tbl, $tables)) {
        echo "\n=== Columns for $tbl ===\n";
        $cols = $pdo->query("DESCRIBE $tbl")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) {
            echo "  {$c['Field']} ({$c['Type']}) - Null: {$c['Null']}, Key: {$c['Key']}\n";
        }
    }
}
