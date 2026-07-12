<?php
require_once __DIR__ . '/../public/db_connect.php';
echo "--- purchase_orders status ---\n";
print_r($pdo->query("SELECT DISTINCT status FROM purchase_orders")->fetchAll(PDO::FETCH_COLUMN));
echo "\n--- fuel_purchase_orders status ---\n";
print_r($pdo->query("SELECT DISTINCT status FROM fuel_purchase_orders")->fetchAll(PDO::FETCH_COLUMN));
