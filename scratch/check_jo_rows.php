<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== All records in job_orders table ===\n";
try {
    $stmt = $pdo->query("SELECT id, jo_number, customer_name, mechanic_name, created_at, status, total_cost, labor_fee, service_fee, parts_cost FROM job_orders LIMIT 10");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Count: " . count($rows) . "\n";
    print_r($rows);
} catch (Exception $e) { echo $e->getMessage(); }
