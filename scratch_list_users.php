<?php
require_once __DIR__ . '/public/db_connect.php';
$stmt = $pdo->query("SELECT id, username, role, status, station_id FROM users LIMIT 20");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($users, JSON_PRETTY_PRINT);
