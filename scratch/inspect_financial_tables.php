<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== Tables in database related to financial / payments / credit / AR ===\n";
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    if (preg_match('/pay|credit|ar|receiv|collect|invoice|transac|customer|fuel|merch/i', $t)) {
        echo " - $t\n";
    }
}

echo "\n=== Inspecting credit/payment tables ===\n";
$target_tables = ['payments', 'customer_payments', 'credit_payments', 'ar_invoices', 'fleet_cards', 'customers'];
foreach ($target_tables as $tt) {
    if (in_array($tt, $tables)) {
        echo "\n--- TABLE: $tt ---\n";
        $cols = $pdo->query("DESCRIBE `$tt`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) echo "   {$c['Field']} ({$c['Type']})\n";
        $cnt = $pdo->query("SELECT COUNT(*) FROM `$tt`")->fetchColumn();
        echo "   Row count: $cnt\n";
    }
}
