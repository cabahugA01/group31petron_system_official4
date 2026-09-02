<?php
$pdo = new PDO("mysql:host=localhost;dbname=petron_pos_db_secure;charset=utf8mb4", "root", "");
$row = $pdo->query("SELECT id, transaction_id, station_id, staff_id, workflow_status FROM merchandise_transactions WHERE id=1")->fetch(PDO::FETCH_ASSOC);
print_r($row);

$stations = $pdo->query("SELECT id, name FROM stations")->fetchAll(PDO::FETCH_ASSOC);
echo "Stations:\n";
print_r($stations);

$users = $pdo->query("SELECT id, username, role, station_id FROM users WHERE username='staff_user' OR id=1 OR role='staff'")->fetchAll(PDO::FETCH_ASSOC);
echo "Users:\n";
print_r($users);
