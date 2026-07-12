<?php
require_once __DIR__ . '/../public/db_connect.php';
$q = $pdo->query("SELECT DISTINCT size FROM inventory_products");
while($r = $q->fetch(PDO::FETCH_ASSOC)) {
    echo "Size: " . json_encode($r['size']) . "\n";
}
$q2 = $pdo->query("SELECT DISTINCT unit FROM station_inventory");
while($r = $q2->fetch(PDO::FETCH_ASSOC)) {
    echo "Station Inventory Unit: " . json_encode($r['unit']) . "\n";
}
