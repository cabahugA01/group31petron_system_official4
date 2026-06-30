<?php
try {
    $pdo = new PDO('mysql:host=localhost;dbname=petron_pos_db_secure', 'root', '');
    
    echo "=== CHECKING ADMIN USERS ===\n";
    $stmt = $pdo->query("SELECT id, username, first_name, last_name, role, station_id FROM users WHERE role LIKE '%admin%'");
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $name = trim($row['first_name'] . ' ' . $row['last_name']);
        echo "- {$row['username']} ($name) | Role: {$row['role']} | Station: {$row['station_id']}\n";
    }
    
    echo "\n=== CHECKING STATIONS ===\n";
    $stmt = $pdo->query("SELECT id, name FROM stations");
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "- Station {$row['id']}: {$row['name']}\n";
    }
    
    echo "\n=== CHECKING STATION_INVENTORY FOR STATION 1 ===\n";
    $stmt = $pdo->query("SELECT COUNT(*) FROM station_inventory WHERE station_id = 1");
    echo "Total inventory records for station 1: " . $stmt->fetchColumn() . "\n";
    
} catch(Exception $e) {
    echo "Error: " . $e->getMessage();
}
