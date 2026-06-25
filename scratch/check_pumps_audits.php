<?php
require_once __DIR__ . '/../public/db_connect.php';
echo "=== fuel_pumps at station 1253 ===\n";
$rows = $pdo->query("SELECT id, pump_number, fuel_type_id, status FROM fuel_pumps WHERE station_id=1253")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) print_r($r);

echo "\n=== audit_logs columns ===\n";
try {
    $cols = $pdo->query("DESCRIBE audit_logs")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) echo "  {$c['Field']}  {$c['Type']}\n";
} catch(Exception $e) { echo "  ".$e->getMessage()."\n"; }

echo "\n=== shifts table exists? ===\n";
try {
    $cols = $pdo->query("DESCRIBE shifts")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) echo "  {$c['Field']}\n";
} catch(Exception $e) { echo "  Not found\n"; }
