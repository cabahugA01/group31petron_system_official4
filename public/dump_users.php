<?php
require_once __DIR__ . '/db_connect.php';
header('Content-Type: application/json');

$users = $pdo->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode([
    'total_users' => count($users),
    'users' => $users
], JSON_PRETTY_PRINT);
