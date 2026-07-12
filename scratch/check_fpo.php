<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "--- fuel_purchase_orders columns ---\n";
try {
    $cols = $pdo->query("DESCRIBE fuel_purchase_orders")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) echo $c['Field'] . " (" . $c['Type'] . ")\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n--- fuel_purchase_orders rows ---\n";
try {
    $rows = $pdo->query("SELECT * FROM fuel_purchase_orders LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    print_r($rows);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
