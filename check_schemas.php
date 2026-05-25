<?php
require_once __DIR__ . '/public/db_connect.php';

echo "=== Columns in fuel_purchase_orders ===\n";
$q = $pdo->query("DESCRIBE fuel_purchase_orders");
while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
    echo "  {$r['Field']} - {$r['Type']}\n";
}

echo "\n=== Columns in purchase_orders ===\n";
$q = $pdo->query("DESCRIBE purchase_orders");
while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
    echo "  {$r['Field']} - {$r['Type']}\n";
}

echo "\n=== Columns in deliveries_oversight ===\n";
$q = $pdo->query("DESCRIBE deliveries_oversight");
while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
    echo "  {$r['Field']} - {$r['Type']}\n";
}
