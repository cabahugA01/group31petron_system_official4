<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== FUEL ADJUSTMENTS ===\n";
print_r($pdo->query("SELECT * FROM fuel_adjustments")->fetchAll(PDO::FETCH_ASSOC));

echo "\n=== FUEL DELIVERIES ===\n";
print_r($pdo->query("SELECT * FROM fuel_deliveries")->fetchAll(PDO::FETCH_ASSOC));
?>
