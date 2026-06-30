<?php
require_once __DIR__ . '/../public/db_connect.php';

// Check users table columns
$cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
echo 'Columns: ' . implode(', ', $cols) . PHP_EOL . PHP_EOL;

// Check the users data
$stmt = $pdo->query('SELECT id, employee_id, first_name, last_name, username, email, role, status, assigned_shift, station_id FROM users ORDER BY id');
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
    echo json_encode($u) . PHP_EOL;
}

// Check stations table
echo PHP_EOL . 'Stations:' . PHP_EOL;
$stmt = $pdo->query('SELECT id, name, status FROM stations');
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
    echo json_encode($s) . PHP_EOL;
}
