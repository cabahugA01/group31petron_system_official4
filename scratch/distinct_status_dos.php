<?php
require_once __DIR__ . '/../public/db_connect.php';
echo "--- deliveries_oversight status ---\n";
print_r($pdo->query("SELECT DISTINCT status FROM deliveries_oversight")->fetchAll(PDO::FETCH_COLUMN));
