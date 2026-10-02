<?php
require_once __DIR__ . '/db_connect.php';
$u = $pdo->query("SELECT id, username, role, station_id FROM users")->fetchAll(PDO::FETCH_ASSOC);
echo "<pre>\n";
print_r($u);
echo "</pre>";
