<?php
require_once __DIR__ . '/public/db_connect.php';
header('Content-Type: text/plain');

$tables = ['fuel_variance_reports', 'accounts_receivable', 'customers', 'deliveries_oversight', 'audit_logs'];
foreach ($tables as $t) {
    echo "=== TABLE: $t ===\n";
    try {
        $q = $pdo->query("DESCRIBE $t");
        while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
            echo "  {$r['Field']} - {$r['Type']}\n";
        }
    } catch (Exception $e) {
        echo "  Error or table does not exist: " . $e->getMessage() . "\n";
    }
}
