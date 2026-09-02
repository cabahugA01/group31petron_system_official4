<?php
$pdo = new PDO("mysql:host=localhost;dbname=petron_pos_db_secure;charset=utf8mb4", "root", "");
$stmt = $pdo->query("SELECT id, transaction_id, customer_name, job_order_service, total_amount, payment_status, validation_status, workflow_status, created_at FROM merchandise_transactions WHERE transaction_type IN ('job_order','combined') ORDER BY id DESC LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
