<?php
require_once __DIR__ . '/../public/db_connect.php';
$rows = $pdo->query("SELECT id, username, role, first_name, last_name FROM users WHERE station_id = 1253")->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);
