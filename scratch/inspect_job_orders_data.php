<?php
require_once __DIR__ . '/../public/db_connect.php';

$cnt = $pdo->query("SELECT COUNT(*) FROM job_orders WHERE station_id = 1253")->fetchColumn();
echo "Job orders count for station 1253: $cnt\n";

$rows = $pdo->query("SELECT jo.*, m.full_name as mechanic_name, sc.name as category_name FROM job_orders jo LEFT JOIN mechanics m ON jo.assigned_mechanic_id = m.id LEFT JOIN service_categories sc ON jo.service_category_id = sc.id WHERE jo.station_id = 1253 LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);

$mechs = $pdo->query("SELECT * FROM mechanics WHERE station_id = 1253")->fetchAll(PDO::FETCH_ASSOC);
echo "\nMechanics for station 1253:\n";
print_r($mechs);

$cats = $pdo->query("SELECT * FROM service_categories")->fetchAll(PDO::FETCH_ASSOC);
echo "\nService categories:\n";
print_r($cats);
