<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== USER LIST ===\n";
try {
    $stmt = $pdo->query("SELECT id, username, role, station_id, status FROM users");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "- ID: {$r['id']}, User: {$r['username']}, Role: {$r['role']}, Station: {$r['station_id']}, Status: {$r['status']}\n";
    }
} catch (Exception $e) {
    echo "Query failed: " . $e->getMessage() . "\n";
}
