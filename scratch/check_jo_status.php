<?php
$pdo = new PDO("mysql:host=localhost;dbname=petron_pos_db_secure;charset=utf8mb4", "root", "");
$cols = $pdo->query("SHOW COLUMNS FROM job_orders")->fetchAll(PDO::FETCH_COLUMN);
echo "Columns in job_orders:\n";
print_r($cols);

$row = $pdo->query("SELECT * FROM job_orders ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
echo "\nRecent job_orders:\n";
print_r($row);
