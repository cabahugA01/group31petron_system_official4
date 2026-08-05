<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== fuel_transactions payment_method values ===\n";
try {
    $stmt = $pdo->query("SELECT payment_method, COUNT(*) as cnt, SUM(total_amount) as total FROM fuel_transactions GROUP BY payment_method");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) { echo $e->getMessage(); }

echo "=== merchandise_transactions payment_method values ===\n";
try {
    $stmt = $pdo->query("SELECT payment_method, COUNT(*) as cnt, SUM(total_amount) as total FROM merchandise_transactions GROUP BY payment_method");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) { echo $e->getMessage(); }
