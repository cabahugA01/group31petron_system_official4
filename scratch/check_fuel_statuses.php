<?php
require_once __DIR__ . '/../public/db_connect.php';
try {
    echo "=== Fuel PO Statuses ===\n";
    $q = $pdo->query("SELECT DISTINCT status FROM fuel_purchase_orders");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo "  - " . ($row['status'] ?? 'NULL') . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
