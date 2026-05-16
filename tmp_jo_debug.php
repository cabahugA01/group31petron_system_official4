<?php
require 'public/db_connect.php';
session_start();

$user_id = $_SESSION['user_id'] ?? null;
echo "Session user_id: $user_id\n";

if ($user_id) {
    // Check user's station assignment
    $u = $pdo->prepare("SELECT id, name, username, role, station_id FROM users WHERE id = ?");
    $u->execute([$user_id]);
    $user = $u->fetch(PDO::FETCH_ASSOC);
    echo "User: "; print_r($user);

    // Check user_stations table
    try {
        $us = $pdo->prepare("SELECT * FROM user_stations WHERE user_id = ?");
        $us->execute([$user_id]);
        echo "user_stations: "; print_r($us->fetchAll(PDO::FETCH_ASSOC));
    } catch(Exception $e) { echo "user_stations table: " . $e->getMessage() . "\n"; }

    // Check station_staff table
    try {
        $ss = $pdo->prepare("SELECT * FROM station_staff WHERE user_id = ?");
        $ss->execute([$user_id]);
        echo "station_staff: "; print_r($ss->fetchAll(PDO::FETCH_ASSOC));
    } catch(Exception $e) { echo "station_staff table: " . $e->getMessage() . "\n"; }
}

// Show how staff_dashboard resolves station_id
echo "\n=== How staff_dashboard.php resolves station_id ===\n";
// Read the relevant section
$lines = file('public/staff_dashboard.php');
foreach ($lines as $i => $line) {
    if (stripos($line, 'station_id') !== false && $i < 100) {
        echo ($i+1) . ": " . trim($line) . "\n";
    }
}

echo "\n=== job_orders station_ids ===\n";
$r = $pdo->query("SELECT station_id, COUNT(*) as cnt FROM job_orders GROUP BY station_id");
print_r($r->fetchAll(PDO::FETCH_ASSOC));
