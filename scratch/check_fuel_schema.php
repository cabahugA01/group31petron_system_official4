<?php
require_once __DIR__ . '/../public/db_connect.php';
echo "=== fuel_transactions ===\n";
foreach($pdo->query('DESCRIBE fuel_transactions')->fetchAll(PDO::FETCH_ASSOC) as $c) echo $c['Field'].' ('.$c['Type'].")\n";
echo "\n=== fuel_inventory ===\n";
foreach($pdo->query('DESCRIBE fuel_inventory')->fetchAll(PDO::FETCH_ASSOC) as $c) echo $c['Field'].' ('.$c['Type'].")\n";
