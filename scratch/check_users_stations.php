<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== Users info ===\n";
$rows = $pdo->query("SELECT id, username, first_name, last_name, role, station_id FROM users WHERE first_name LIKE '%Judy%' OR first_name LIKE '%Edgar%' OR username LIKE '%staff%' OR username LIKE '%manager%' LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) print_r($r);
