<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== station_inventory ===\n";
try {
    $q = $pdo->query("DESCRIBE station_inventory");
    while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
        print_r($row);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "=== inventory_products ===\n";
try {
    $q = $pdo->query("DESCRIBE inventory_products");
    while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
        print_r($row);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
