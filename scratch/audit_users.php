<?php
require_once __DIR__ . '/../public/db_connect.php';

// Show users only
echo "=== USERS ===" . PHP_EOL;
$stmt = $pdo->query('SELECT id, employee_id, first_name, last_name, username, email, role, status, assigned_shift, station_id FROM users ORDER BY id');
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
    echo json_encode($u) . PHP_EOL;
}

// Show only users' station
echo PHP_EOL . "=== STATION for users ===" . PHP_EOL;
$stmt = $pdo->query('SELECT u.id, u.first_name, u.last_name, u.role, s.id as station_id, s.name as station_name FROM users u LEFT JOIN stations s ON u.station_id = s.id ORDER BY u.id');
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
    echo json_encode($u) . PHP_EOL;
}

echo PHP_EOL . "=== COLUMNS IN users ===" . PHP_EOL;
$cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo $c['Field'] . ' (' . $c['Type'] . ')' . PHP_EOL;
}
