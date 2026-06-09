<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== fuel_transactions.pump_id column ===\n";
$col = $pdo->query("SHOW COLUMNS FROM fuel_transactions WHERE Field='pump_id'")->fetch(PDO::FETCH_ASSOC);
print_r($col);

echo "\n=== notifications table columns ===\n";
try {
    $notif = $pdo->query("SHOW COLUMNS FROM notifications")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($notif as $c) {
        echo $c['Field'] . " (" . $c['Type'] . ")\n";
    }
} catch (Exception $e) {
    echo "notifications table: " . $e->getMessage() . "\n";
}

echo "\n=== user_stations table check ===\n";
try {
    $us = $pdo->query("SHOW COLUMNS FROM user_stations")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($us as $c) {
        echo $c['Field'] . " (" . $c['Type'] . ")\n";
    }
} catch (Exception $e) {
    echo "user_stations table: " . $e->getMessage() . "\n";
}
