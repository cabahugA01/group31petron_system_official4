<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== fuel_transactions counts per station ===\n";
$rows = $pdo->query("SELECT station_id, COUNT(*) as cnt FROM fuel_transactions GROUP BY station_id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) print_r($r);

echo "=== fuel_deliveries counts per station ===\n";
$rows = $pdo->query("SELECT station_id, COUNT(*) as cnt FROM fuel_deliveries GROUP BY station_id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) print_r($r);
