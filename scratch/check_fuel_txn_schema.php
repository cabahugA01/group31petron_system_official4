<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== fuel_transactions columns ===\n";
$cols = $pdo->query("DESCRIBE fuel_transactions")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) echo "  {$c['Field']}  {$c['Type']}  {$c['Null']}  default={$c['Default']}\n";

echo "\n=== fuel_transactions sample rows (station 1) ===\n";
$rows = $pdo->query("SELECT * FROM fuel_transactions WHERE station_id=1 LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) print_r($r);

echo "\n=== fuel_pumps columns ===\n";
try {
    $cols = $pdo->query("DESCRIBE fuel_pumps")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) echo "  {$c['Field']}  {$c['Type']}\n";
} catch(Exception $e) { echo "  Not found: ".$e->getMessage()."\n"; }

echo "\n=== fuel_transactions station counts ===\n";
$rows = $pdo->query("SELECT station_id, COUNT(*) as cnt FROM fuel_transactions GROUP BY station_id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) print_r($r);
