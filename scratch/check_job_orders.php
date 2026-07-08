<?php
require_once __DIR__ . '/../public/db_connect.php';

try {
    echo "=== DB CHECK: job_orders ===\n";
    $stmt = $pdo->query("SELECT id, COALESCE(job_order_number, '') as job_order_number, customer_name, status, validation_status, created_at, station_id FROM job_orders LIMIT 10");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        echo "No job orders found.\n";
    } else {
        print_r($rows);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

try {
    echo "=== DB CHECK: service_transactions ===\n";
    $stmt = $pdo->query("SELECT * FROM service_transactions LIMIT 10");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        echo "No service transactions found.\n";
    } else {
        print_r($rows);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
