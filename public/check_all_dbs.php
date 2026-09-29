<?php
require_once __DIR__ . '/db_connect.php';
header('Content-Type: application/json');

$dbs = $pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);

$users_across_dbs = [];
foreach ($dbs as $db) {
    if (in_array($db, ['information_schema', 'mysql', 'performance_schema', 'sys', 'phpmyadmin'])) continue;
    try {
        $u = $pdo->query("SELECT id, username, first_name, last_name, email, role, station_id FROM `{$db}`.users")->fetchAll(PDO::FETCH_ASSOC);
        $users_across_dbs[$db] = $u;
    } catch (Exception $e) {
        $users_across_dbs[$db] = ['error' => $e->getMessage()];
    }
}

echo json_encode([
    'all_databases' => $dbs,
    'users_across_dbs' => $users_across_dbs
], JSON_PRETTY_PRINT);
