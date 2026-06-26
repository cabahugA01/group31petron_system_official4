<?php
require_once __DIR__ . '/../public/db_connect.php';

try {
    $pdo->exec("ALTER TABLE station_inventory ADD COLUMN physical_count DECIMAL(12,2) DEFAULT NULL");
    echo "Added physical_count column successfully.\n";
} catch (Exception $e) {
    echo "physical_count column error: " . $e->getMessage() . "\n";
}

try {
    $pdo->exec("ALTER TABLE station_inventory ADD COLUMN variance DECIMAL(12,2) DEFAULT NULL");
    echo "Added variance column successfully.\n";
} catch (Exception $e) {
    echo "variance column error: " . $e->getMessage() . "\n";
}
