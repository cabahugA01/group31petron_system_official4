<?php
require_once __DIR__ . '/public/db_connect.php';
echo "=== DESCRIBE fuel_purchase_orders ===\n";
try {
    $cols = $pdo->query("DESCRIBE fuel_purchase_orders")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "{$c['Field']} - {$c['Type']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
