<?php
require __DIR__ . '/../public/db_connect.php';

try {
    $stmt = $pdo->query("SELECT id, first_name, last_name, username, role, assigned_shift FROM users");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($users, JSON_PRETTY_PRINT) . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
