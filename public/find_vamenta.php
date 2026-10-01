<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/db_connect.php';

echo "<pre>\n";
// Check stations
try {
    $q = $pdo->query("SELECT id, name, location, address FROM stations WHERE name LIKE '%Vamenta%' OR location LIKE '%Vamenta%' OR address LIKE '%Vamenta%'");
    echo "=== VAMENTA STATIONS ===\n";
    print_r($q->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) { echo "Station error: " . $e->getMessage() . "\n"; }

// Check users
try {
    $u = $pdo->query("SELECT id, username, role, station_id FROM users WHERE username IN ('yyang', 'judy', 'admin', 'manager')")->fetchAll(PDO::FETCH_ASSOC);
    echo "=== USERS ===\n";
    print_r($u);
} catch (Exception $e) { echo "User error: " . $e->getMessage() . "\n"; }

// Check columns of fuel_pumps
try {
    $c = $pdo->query("SHOW COLUMNS FROM fuel_pumps")->fetchAll(PDO::FETCH_ASSOC);
    echo "=== FUEL PUMPS COLUMNS ===\n";
    print_r(array_column($c, 'Field'));
} catch (Exception $e) { echo "fuel_pumps columns error: " . $e->getMessage() . "\n"; }

// Check columns of nozzles
try {
    $c = $pdo->query("SHOW COLUMNS FROM nozzles")->fetchAll(PDO::FETCH_ASSOC);
    echo "=== NOZZLES COLUMNS ===\n";
    print_r(array_column($c, 'Field'));
} catch (Exception $e) { echo "nozzles columns error: " . $e->getMessage() . "\n"; }

echo "</pre>";
