<?php
require_once __DIR__ . '/../public/db_connect.php';
$rows = $pdo->query("SELECT id, username, role, station_id FROM users")->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);
