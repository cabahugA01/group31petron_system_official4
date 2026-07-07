<?php
require_once __DIR__ . '/public/db_connect.php';

echo "<h2>Debug Admin List Query</h2>";
echo "<pre>";

// Test 1: Check all users with any role containing "admin"
echo "=== Test 1: All users with 'admin' in role ===\n";
try {
    $stmt = $pdo->query("SELECT id, name, role, status, station_id FROM users WHERE LOWER(role) LIKE '%admin%'");
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Found " . count($results) . " users:\n";
    foreach ($results as $row) {
        printf("ID: %d | Name: %s | Role: %s | Status: %s | Station: %d\n", 
            $row['id'], 
            $row['name'], 
            $row['role'], 
            $row['status'], 
            $row['station_id'] ?? 0
        );
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== Test 2: Exact query from admin management page ===\n";
try {
    $stmt = $pdo->query(
        "SELECT u.id, u.name, u.first_name, u.last_name, u.email, u.phone_number, u.username, u.status, u.station_id, u.created_at, u.role,
                s.name AS station_name
         FROM users u
         LEFT JOIN stations s ON s.id = u.station_id
         WHERE (LOWER(u.role) LIKE '%admin%' AND LOWER(u.role) NOT LIKE '%super%' AND LOWER(u.role) NOT LIKE '%developer%')
           AND (u.is_deleted IS NULL OR u.is_deleted = 0)
         ORDER BY u.name"
    );
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Found " . count($results) . " admins:\n";
    foreach ($results as $row) {
        printf("ID: %d | Name: %s | Email: %s | Role: %s | Station: %s | Status: %s\n", 
            $row['id'], 
            $row['name'] ?? 'NULL', 
            $row['email'] ?? 'NULL', 
            $row['role'],
            $row['station_name'] ?? 'Unassigned',
            $row['status']
        );
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== Test 3: Check station1253 specifically ===\n";
try {
    $stmt = $pdo->query("SELECT id, name FROM stations WHERE id = 1253 OR name LIKE '%1253%'");
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($results) > 0) {
        echo "Station found:\n";
        foreach ($results as $row) {
            echo "Station ID: " . $row['id'] . " | Name: " . $row['name'] . "\n";
            
            // Find admins for this station
            $stmt2 = $pdo->prepare("SELECT id, name, role, email FROM users WHERE station_id = ?");
            $stmt2->execute([$row['id']]);
            $admins = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            echo "  Admins for this station: " . count($admins) . "\n";
            foreach ($admins as $admin) {
                echo "    - " . $admin['name'] . " (" . $admin['role'] . ") - " . ($admin['email'] ?? 'no email') . "\n";
            }
        }
    } else {
        echo "Station 1253 not found!\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "</pre>";
