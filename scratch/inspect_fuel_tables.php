<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== FUEL-RELATED TABLES ===\n";
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$fuel_tables = array_filter($tables, fn($t) => stripos($t,'fuel') !== false || stripos($t,'ugt') !== false || stripos($t,'meter') !== false || stripos($t,'reading') !== false || stripos($t,'shift') !== false || stripos($t,'pump') !== false || stripos($t,'calibrat') !== false);
foreach ($fuel_tables as $t) {
    echo "\n--- TABLE: $t ---\n";
    $cols = $pdo->query("DESCRIBE `$t`")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "  {$c['Field']} ({$c['Type']})\n";
    }
    $count = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    echo "  [ROWS: $count]\n";
}

echo "\n=== SAMPLE fuel_inventory ===\n";
try {
    $rows = $pdo->query("SELECT * FROM fuel_inventory LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) print_r($r);
} catch(Exception $e) { echo $e->getMessage()."\n"; }

echo "\n=== SAMPLE fuel_transactions ===\n";
try {
    $rows = $pdo->query("SELECT * FROM fuel_transactions LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) print_r($r);
} catch(Exception $e) { echo $e->getMessage()."\n"; }

echo "\n=== SAMPLE fuel_pumps ===\n";
try {
    $rows = $pdo->query("SELECT * FROM fuel_pumps LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) print_r($r);
} catch(Exception $e) { echo $e->getMessage()."\n"; }
