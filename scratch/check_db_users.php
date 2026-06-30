<?php
require_once __DIR__ . '/../public/db_connect.php';

try {
    $stmt = $pdo->query("SELECT id, employee_id, first_name, last_name, username, email, role, status FROM users");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($users, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
