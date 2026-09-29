<?php
require_once __DIR__ . '/db_connect.php';
header('Content-Type: application/json');

$st = $pdo->query("SELECT id, name, location, address FROM stations WHERE name LIKE '%kauswagan%' OR name LIKE '%highway%' LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($st, JSON_PRETTY_PRINT);
