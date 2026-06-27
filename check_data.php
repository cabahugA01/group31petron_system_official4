<?php
require_once __DIR__ . '/public/db_connect.php';

echo "--- STOCK REQUESTS ---\n";
$stmt = $pdo->query("SELECT id, staff_id, station_id, item_name, status, created_at FROM stock_requests");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n--- USERS ---\n";
$stmt = $pdo->query("SELECT id, name, role, station_id FROM users WHERE role IN ('staff','manager','admin')");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
