<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "--- deliveries_oversight columns ---\n";
$r = $pdo->query("DESCRIBE deliveries_oversight")->fetchAll(PDO::FETCH_ASSOC);
foreach ($r as $c) echo $c['Field'] . " (" . $c['Type'] . ")\n";

echo "\n--- fuel_adjustments sample insert test ---\n";
// Check what the real insert would look like
$r = $pdo->query("DESCRIBE fuel_adjustments")->fetchAll(PDO::FETCH_ASSOC);
foreach ($r as $c) echo $c['Field'] . " (" . $c['Type'] . ")\n";

echo "\n--- purchase_order_items columns ---\n";
try {
    $r = $pdo->query("DESCRIBE purchase_order_items")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($r as $c) echo $c['Field'] . " (" . $c['Type'] . ")\n";
} catch (Exception $e) { echo "ERR: " . $e->getMessage() . "\n"; }

echo "\n--- merchandise_batches columns ---\n";
try {
    $r = $pdo->query("DESCRIBE merchandise_batches")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($r as $c) echo $c['Field'] . " (" . $c['Type'] . ")\n";
} catch (Exception $e) { echo "ERR: " . $e->getMessage() . "\n"; }

echo "\n--- purchase_orders columns ---\n";
$r = $pdo->query("DESCRIBE purchase_orders")->fetchAll(PDO::FETCH_ASSOC);
foreach ($r as $c) echo $c['Field'] . " (" . $c['Type'] . ")\n";
