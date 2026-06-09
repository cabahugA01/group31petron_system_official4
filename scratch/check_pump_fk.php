<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== fuel_pumps table structure ===\n";
$cols = $pdo->query("SHOW COLUMNS FROM fuel_pumps")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) echo $c['Field'] . " (" . $c['Type'] . ") " . $c['Null'] . "\n";

echo "\n=== fuel_pumps rows ===\n";
$rows = $pdo->query("SELECT * FROM fuel_pumps LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) print_r($r);

echo "\n=== FK constraint on fuel_transactions.pump_id ===\n";
$fk = $pdo->query("
    SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'fuel_transactions'
      AND REFERENCED_TABLE_NAME IS NOT NULL
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($fk as $f) print_r($f);
