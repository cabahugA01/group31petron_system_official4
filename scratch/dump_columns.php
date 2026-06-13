<?php
require_once __DIR__ . '/../public/db_connect.php';

$tables = [
    'fuel_transactions',
    'merchandise_transactions',
    'job_orders',
    'deliveries_oversight',
    'fuel_inventory',
    'station_inventory',
    'labor_sessions'
];

foreach ($tables as $t) {
    echo "=== $t ===\n";
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$t`");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            echo "  Field: {$row['Field']} | Type: {$row['Type']}\n";
        }
    } catch (Exception $e) {
        echo "  Error: " . $e->getMessage() . "\n";
    }
}
