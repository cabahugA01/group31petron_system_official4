<?php
require_once __DIR__ . '/../public/db_connect.php';

try {
    $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    echo "users columns:\n" . implode(", ", $cols) . "\n\n";
    $users = $pdo->query("SELECT id, username, email, role, first_name, last_name FROM users")->fetchAll(PDO::FETCH_ASSOC);
    echo "Users list:\n";
    print_r($users);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
