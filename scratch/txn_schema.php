<?php
require_once __DIR__ . '/../public/db_connect.php';

// List transaction/adjustment/void/audit related tables
$all = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$relevant = array_filter($all, function($t) {
    return preg_match('/transaction|adjust|void|audit|job_order|merchandise/i', $t);
});
echo "=== RELEVANT TABLES ===\n";
foreach ($relevant as $t) echo "  $t\n";

// Describe merchandise_transactions
echo "\n=== merchandise_transactions ===\n";
try {
    $cols = $pdo->query('DESCRIBE merchandise_transactions')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) echo "  {$c['Field']}  {$c['Type']}  {$c['Null']}  {$c['Default']}\n";
} catch (Exception $e) { echo "  ERROR: ".$e->getMessage()."\n"; }

// Describe merchandise_transaction_items
echo "\n=== merchandise_transaction_items ===\n";
try {
    $cols = $pdo->query('DESCRIBE merchandise_transaction_items')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) echo "  {$c['Field']}  {$c['Type']}  {$c['Null']}  {$c['Default']}\n";
} catch (Exception $e) { echo "  ERROR: ".$e->getMessage()."\n"; }

// Check if transaction_adjustments table exists
echo "\n=== transaction_adjustments (if exists) ===\n";
try {
    $cols = $pdo->query('DESCRIBE transaction_adjustments')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) echo "  {$c['Field']}  {$c['Type']}  {$c['Null']}  {$c['Default']}\n";
} catch (Exception $e) { echo "  NOT FOUND: ".$e->getMessage()."\n"; }

// Check audit_logs
echo "\n=== audit_logs ===\n";
try {
    $cols = $pdo->query('DESCRIBE audit_logs')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) echo "  {$c['Field']}  {$c['Type']}\n";
} catch (Exception $e) { echo "  ERROR: ".$e->getMessage()."\n"; }

// Check station_inventory columns
echo "\n=== station_inventory ===\n";
try {
    $cols = $pdo->query('DESCRIBE station_inventory')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) echo "  {$c['Field']}  {$c['Type']}\n";
} catch (Exception $e) { echo "  ERROR: ".$e->getMessage()."\n"; }
