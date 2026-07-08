<?php
require_once __DIR__ . '/../public/db_connect.php';

try {
    echo "=== DB CHECK: users station_id ===\n";
    $stmt = $pdo->query("SELECT id, username, first_name, last_name, role, station_id FROM users LIMIT 15");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    print_r($rows);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
