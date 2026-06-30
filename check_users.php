<?php
require_once __DIR__ . '/public/db_connect.php';
try {
    $stmt = $pdo->query("SELECT id, username, email, role, status FROM users");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Total users: " . count($users) . "\n";
    print_r($users);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
