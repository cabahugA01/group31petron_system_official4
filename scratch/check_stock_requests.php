<?php
require __DIR__ . '/../public/db_connect.php';
$tables = ['stock_requests', 'merchandise_stock_requests', 'inventory_stock_requests', 'stock_request_items'];
foreach ($tables as $t) {
    try {
        $s = $pdo->query("SHOW COLUMNS FROM `$t`");
        echo "=== $t ===\n";
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            echo "  " . $r['Field'] . " (" . $r['Type'] . ")\n";
        }
    } catch (Exception $e) {
        echo "--- $t: NOT FOUND\n";
    }
}
// Also check what table the existing manager_inventory_merchandise uses
echo "\n--- Checking existing stock request PHP ---\n";
$content = file_get_contents(__DIR__ . '/../public/manager_inventory_merchandise.php');
preg_match_all('/stock_request[s]?\w*/', $content, $m);
echo implode(', ', array_unique($m[0])) . "\n";
