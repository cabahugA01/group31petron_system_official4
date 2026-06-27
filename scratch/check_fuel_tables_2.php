<?php
require __DIR__ . '/../public/db_connect.php';

echo "=== FUEL PRICING ===\n";
print_r($pdo->query("SELECT * FROM fuel_pricing")->fetchAll(PDO::FETCH_ASSOC));

echo "=== FUEL PUMPS ===\n";
print_r($pdo->query("SELECT * FROM fuel_pumps")->fetchAll(PDO::FETCH_ASSOC));
