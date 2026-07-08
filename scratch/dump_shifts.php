<?php
require_once __DIR__ . '/../public/db_connect.php';

$output = [];

$stmt = $pdo->query("SELECT * FROM labor_sessions ORDER BY id DESC LIMIT 30");
$output['labor_sessions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt2 = $pdo->query("SELECT id, username, first_name, last_name, role FROM users");
$output['users'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);

file_put_contents(__DIR__ . '/shift_data.json', json_encode($output, JSON_PRETTY_PRINT));
echo "Saved to shift_data.json\n";
