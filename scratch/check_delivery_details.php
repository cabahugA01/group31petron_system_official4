<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "--- fuel_deliveries columns ---\n";
try {
    $cols = $pdo->query("DESCRIBE fuel_deliveries")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) echo $c['Field'] . " (" . $c['Type'] . ")\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n--- merchandise_deliveries columns ---\n";
try {
    $cols = $pdo->query("DESCRIBE merchandise_deliveries")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) echo $c['Field'] . " (" . $c['Type'] . ")\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n--- Row counts ---\n";
echo "deliveries_oversight: " . $pdo->query("SELECT COUNT(*) FROM deliveries_oversight")->fetchColumn() . "\n";
echo "fuel_deliveries: " . $pdo->query("SELECT COUNT(*) FROM fuel_deliveries")->fetchColumn() . "\n";
echo "merchandise_deliveries: " . $pdo->query("SELECT COUNT(*) FROM merchandise_deliveries")->fetchColumn() . "\n";

echo "\n--- Last 2 fuel_deliveries rows ---\n";
print_r($pdo->query("SELECT * FROM fuel_deliveries ORDER BY id DESC LIMIT 2")->fetchAll(PDO::FETCH_ASSOC));

echo "\n--- Last 2 merchandise_deliveries rows ---\n";
print_r($pdo->query("SELECT * FROM merchandise_deliveries ORDER BY id DESC LIMIT 2")->fetchAll(PDO::FETCH_ASSOC));
