<?php
require_once __DIR__ . '/../public/db_connect.php';
$tables = ['inventory_products', 'stock_movements', 'stock_in_records', 'stock_requests', 'inventory_history'];
foreach ($tables as $t) {
    try {
        echo "=== $t ===\n";
        foreach ($pdo->query("DESCRIBE $t")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            echo "  {$r['Field']} - {$r['Type']}\n";
        }
        $cnt = $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
        echo "  [Rows: $cnt]\n\n";
    } catch (Exception $e) { echo "  ERROR: {$e->getMessage()}\n\n"; }
}
