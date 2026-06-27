<?php
require __DIR__ . '/../public/db_connect.php';

echo "=== TABLES IN DB ===\n";
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
print_r($tables);

echo "\n=== COLUMNS IN voided_transactions ===\n";
try {
    $cols = $pdo->query("DESCRIBE voided_transactions")->fetchAll(PDO::FETCH_ASSOC);
    foreach($cols as $c) echo "{$c['Field']} | {$c['Type']} | {$c['Null']}\n";
} catch(Exception $e) { echo $e->getMessage() . "\n"; }

echo "\n=== SAMPLE voided_transactions DATA ===\n";
try {
    $rows = $pdo->query("SELECT * FROM voided_transactions LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    print_r($rows);
} catch(Exception $e) { echo $e->getMessage() . "\n"; }

echo "\n=== COLUMNS IN merchandise_transactions ===\n";
try {
    $cols = $pdo->query("DESCRIBE merchandise_transactions")->fetchAll(PDO::FETCH_ASSOC);
    foreach($cols as $c) echo "{$c['Field']} | {$c['Type']}\n";
} catch(Exception $e) { echo $e->getMessage() . "\n"; }

echo "\n=== SAMPLE merchandise_transactions DATA ===\n";
try {
    $rows = $pdo->query("SELECT * FROM merchandise_transactions LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
    print_r($rows);
} catch(Exception $e) { echo $e->getMessage() . "\n"; }
