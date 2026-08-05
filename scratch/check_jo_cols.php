<?php
require_once __DIR__ . '/../public/db_connect.php';

$stmt = $pdo->query("SELECT * FROM job_orders LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
