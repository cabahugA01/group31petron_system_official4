<?php
require_once __DIR__ . '/../public/db_connect.php';

$tables = ['users', 'audit_logs', 'audit_trail'];
foreach ($tables as $table) {
    echo "--- Collations for table {$table} ---\n";
    $s = $pdo->query("SHOW FULL COLUMNS FROM {$table}");
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $col) {
        if ($col['Collation']) {
            echo "  {$col['Field']}: {$col['Collation']}\n";
        }
    }
}
