<?php
require_once __DIR__ . '/../public/db_connect.php';

header('Content-Type: application/json');

$dbs = $pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);

echo json_encode($dbs, JSON_PRETTY_PRINT);
