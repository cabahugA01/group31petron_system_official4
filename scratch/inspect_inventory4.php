<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== MERCHANDISE_ADJUSTMENTS ===\n";
$cols = $pdo->query("DESCRIBE merchandise_adjustments")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) echo "  {$c['Field']} ({$c['Type']})\n";
$sample = $pdo->query("SELECT * FROM merchandise_adjustments LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
echo "Sample: "; print_r($sample);

echo "\n=== ADJUSTMENT_TYPES ===\n";
$cols = $pdo->query("DESCRIBE adjustment_types")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) echo "  {$c['Field']} ({$c['Type']})\n";
$sample = $pdo->query("SELECT * FROM adjustment_types LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
echo "Sample: "; print_r($sample);

echo "\n=== MERCHANDISE_ADJUSTMENTS for station 1253 ===\n";
try {
    $rows = $pdo->query("SELECT * FROM merchandise_adjustments WHERE station_id=1253 LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    print_r($rows);
} catch(Exception $e) { echo $e->getMessage()."\n"; }

echo "\n=== INVENTORY (table) ===\n";
try {
    $cols = $pdo->query("DESCRIBE inventory")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) echo "  {$c['Field']} ({$c['Type']})\n";
    $sample = $pdo->query("SELECT * FROM inventory LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
    echo "Sample: "; print_r($sample);
} catch(Exception $e) { echo $e->getMessage()."\n"; }
