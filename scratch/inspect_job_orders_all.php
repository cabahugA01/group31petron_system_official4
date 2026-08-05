<?php
require_once __DIR__ . '/../public/db_connect.php';

$rows = $pdo->query("SELECT jo.id, jo.job_order_number, jo.station_id, jo.status, jo.created_at, jo.customer_name, jo.vehicle_plate, jo.payment_status, jo.payment_method, jo.actual_labor_cost, jo.actual_parts_cost, jo.total_cost FROM job_orders jo LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);
