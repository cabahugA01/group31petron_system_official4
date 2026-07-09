<?php
require_once 'public/db_connect.php';
// Check station_inventory columns
$cols = $pdo->query("DESCRIBE station_inventory")->fetchAll(PDO::FETCH_ASSOC);
echo "--- station_inventory columns ---\n";
foreach ($cols as $c) echo $c['Field'] . " - " . $c['Type'] . "\n";
