<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== fuel_purchase_orders ===\n";
try {
    $cols = $pdo->query("DESCRIBE fuel_purchase_orders")->fetchAll(PDO::FETCH_ASSOC);
    foreach($cols as $c) echo "  {$c['Field']} | {$c['Type']} | {$c['Null']} | {$c['Default']}\n";
    echo "Sample rows:\n";
    $rows = $pdo->query("SELECT * FROM fuel_purchase_orders LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $r) { echo "  "; print_r($r); }
} catch(Exception $e){ echo "  ERROR: ".$e->getMessage()."\n"; }

echo "\n=== deliveries_oversight (fuel) ===\n";
try {
    $cols = $pdo->query("DESCRIBE deliveries_oversight")->fetchAll(PDO::FETCH_ASSOC);
    foreach($cols as $c) echo "  {$c['Field']} | {$c['Type']} | {$c['Null']} | {$c['Default']}\n";
    echo "Sample rows (fuel):\n";
    $rows = $pdo->query("SELECT * FROM deliveries_oversight WHERE delivery_type='fuel' LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $r) { echo "  "; print_r($r); }
} catch(Exception $e){ echo "  ERROR: ".$e->getMessage()."\n"; }

echo "\n=== fuel_deliveries ===\n";
try {
    $cols = $pdo->query("DESCRIBE fuel_deliveries")->fetchAll(PDO::FETCH_ASSOC);
    foreach($cols as $c) echo "  {$c['Field']} | {$c['Type']} | {$c['Null']} | {$c['Default']}\n";
} catch(Exception $e){ echo "  ERROR: ".$e->getMessage()."\n"; }
