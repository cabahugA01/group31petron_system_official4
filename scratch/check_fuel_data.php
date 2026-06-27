<?php
require __DIR__ . '/../public/db_connect.php';

echo "=== STATIONS ===\n";
print_r($pdo->query("SELECT id, name FROM stations")->fetchAll(PDO::FETCH_ASSOC));

echo "=== FUEL TYPES ===\n";
print_r($pdo->query("SELECT * FROM fuel_types")->fetchAll(PDO::FETCH_ASSOC));

echo "=== FUEL INVENTORY ===\n";
print_r($pdo->query("SELECT * FROM fuel_inventory")->fetchAll(PDO::FETCH_ASSOC));
