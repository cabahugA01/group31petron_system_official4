<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== UNIQUE USER SHIFTS ===\n";
$stmt = $pdo->query("SELECT DISTINCT assigned_shift, shift_assignment FROM users");
while($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    var_dump($r);
}

echo "\n=== UNIQUE MERCHANDISE TRANSACTIONS SHIFTS ===\n";
$stmt2 = $pdo->query("SELECT DISTINCT shift_period, shift_name FROM merchandise_transactions");
while($r = $stmt2->fetch(PDO::FETCH_ASSOC)) {
    var_dump($r);
}

echo "\n=== UNIQUE JOB ORDERS SHIFTS ===\n";
$stmt3 = $pdo->query("SELECT DISTINCT shift_period, shift_name FROM job_orders");
while($r = $stmt3->fetch(PDO::FETCH_ASSOC)) {
    var_dump($r);
}
