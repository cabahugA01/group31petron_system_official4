<?php
require_once __DIR__ . '/../public/db_connect.php';

$tables = ['activity_logs', 'login_attempts', 'merchandise_transactions', 'inventory_logs', 'audit_logs', 'users'];

foreach ($tables as $tbl) {
    echo "=== Table: $tbl ===\n";
    try {
        $r = $pdo->query("DESCRIBE $tbl");
        foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $c) {
            echo "  " . $c['Field'] . " (" . $c['Type'] . ")\n";
        }
    } catch (Exception $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
    }
    echo "\n";
}
