<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

try {
    $q = $pdo->query("SELECT id, username, name, role, email FROM users");
    echo "Users:\n";
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $u) {
        echo " - ID: {$u['id']} | Username: {$u['username']} | Name: {$u['name']} | Role: {$u['role']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
