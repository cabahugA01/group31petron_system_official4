<?php
require_once __DIR__ . '/../public/db_connect.php';
try {
    $stmt = $pdo->query("SELECT id, username, email, role, station_id, status FROM users");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
