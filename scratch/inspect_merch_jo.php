<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== MERCHANDISE_TRANSACTIONS ===\n";
try {
    $cols = $pdo->query("DESCRIBE merchandise_transactions")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) echo "  {$c['Field']} ({$c['Type']})\n";
    $sample = $pdo->query("SELECT * FROM merchandise_transactions LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
    echo "Sample: "; print_r($sample);
} catch(Exception $e) { echo $e->getMessage()."\n"; }

echo "\n=== MERCHANDISE_TRANSACTION_ITEMS ===\n";
try {
    $cols = $pdo->query("DESCRIBE merchandise_transaction_items")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) echo "  {$c['Field']} ({$c['Type']})\n";
    $sample = $pdo->query("SELECT * FROM merchandise_transaction_items LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
    echo "Sample: "; print_r($sample);
} catch(Exception $e) { echo $e->getMessage()."\n"; }

echo "\n=== JOB_ORDERS ===\n";
try {
    $cols = $pdo->query("DESCRIBE job_orders")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) echo "  {$c['Field']} ({$c['Type']})\n";
    $sample = $pdo->query("SELECT * FROM job_orders LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
    echo "Sample: "; print_r($sample);
} catch(Exception $e) { echo $e->getMessage()."\n"; }

echo "\n=== PRODUCTS / CATEGORIES ===\n";
try {
    $cols = $pdo->query("DESCRIBE products")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) echo "  {$c['Field']} ({$c['Type']})\n";
} catch(Exception $e) { echo $e->getMessage()."\n"; }

echo "\n=== MECHANICS ===\n";
try {
    $cols = $pdo->query("DESCRIBE mechanics")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) echo "  {$c['Field']} ({$c['Type']})\n";
} catch(Exception $e) { echo $e->getMessage()."\n"; }
