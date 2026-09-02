<?php
$pdo = new PDO("mysql:host=localhost;dbname=petron_pos_db_secure;charset=utf8mb4", "root", "");
$cols = $pdo->query("SHOW COLUMNS FROM merchandise_transactions")->fetchAll(PDO::FETCH_COLUMN);
echo "Columns in merchandise_transactions:\n";
print_r($cols);

$row = $pdo->query("SELECT id, transaction_id, transaction_type, customer_name, job_order_service, status, validation_status, workflow_status FROM merchandise_transactions ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
echo "\nRecent records:\n";
print_r($row);
