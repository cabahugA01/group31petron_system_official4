<?php
require_once __DIR__ . '/db_connect.php';

header('Content-Type: application/json');

$logs = [];
try {
    $logs['activity_logs'] = $pdo->query("SELECT * FROM activity_logs ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
try {
    $logs['audit_trail'] = $pdo->query("SELECT * FROM audit_trail ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

echo json_encode($logs, JSON_PRETTY_PRINT);
