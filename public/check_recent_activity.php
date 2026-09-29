<?php
require_once __DIR__ . '/db_connect.php';
header('Content-Type: application/json');

$logs = $pdo->query("SELECT * FROM activity_logs ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
$recent_users = $pdo->query("SELECT * FROM users ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'recent_logs' => $logs,
    'recent_users' => $recent_users
], JSON_PRETTY_PRINT);
