<?php
require_once __DIR__ . '/db_connect.php';
header('Content-Type: application/json');

// Find all users and stations
$users = $pdo->query("
    SELECT u.id, u.username, u.first_name, u.last_name, u.email, u.role, u.station_id, s.name as station_name
    FROM users u
    LEFT JOIN stations s ON s.id = u.station_id
    ORDER BY u.id
")->fetchAll(PDO::FETCH_ASSOC);

// Find fuel_inventory count by station
$inv_by_station = $pdo->query("
    SELECT fi.station_id, s.name as station_name, COUNT(*) as fuel_tank_count,
           GROUP_CONCAT(fi.fuel_type SEPARATOR ', ') as fuels
    FROM fuel_inventory fi
    LEFT JOIN stations s ON s.id = fi.station_id
    GROUP BY fi.station_id
")->fetchAll(PDO::FETCH_ASSOC);

// Check pending_price_approvals
$approvals = $pdo->query("
    SELECT ppa.*, s.name as station_name
    FROM pending_price_approvals ppa
    LEFT JOIN stations s ON s.id = ppa.station_id
")->fetchAll(PDO::FETCH_ASSOC);

// Check all notifications
$notifs = $pdo->query("
    SELECT n.*, u.username, u.first_name, u.last_name, u.role, u.station_id, s.name as user_station_name
    FROM notifications n
    LEFT JOIN users u ON u.id = n.user_id
    LEFT JOIN stations s ON s.id = u.station_id
    ORDER BY n.id DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'users' => $users,
    'fuel_inventory_by_station' => $inv_by_station,
    'pending_price_approvals' => $approvals,
    'recent_notifications' => $notifs
], JSON_PRETTY_PRINT);
