<?php
require_once __DIR__ . '/../public/db_connect.php';
$tables = ['stock_requests', 'merchandise_stock_in', 'transaction_items', 'users'];
foreach ($tables as $t) {
    echo "=== $t ===\n";
    $r = $pdo->query("DESCRIBE $t");
    foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $c) {
        echo $c['Field'] . ' | ' . $c['Type'] . "\n";
    }
    echo "\n";
}
