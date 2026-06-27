<?php
require __DIR__ . '/../public/db_connect.php';

echo "=== Describe fuel_inventory ===\n";
print_r($pdo->query("DESCRIBE fuel_inventory")->fetchAll(PDO::FETCH_ASSOC));
