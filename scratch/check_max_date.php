<?php
require_once __DIR__ . '/../public/db_connect.php';

try {
    $stmt = $pdo->prepare("SELECT MAX(DATE(transaction_date)) FROM fuel_transactions WHERE station_id = ?");
    $stmt->execute([1253]);
    $max_date = $stmt->fetchColumn();
    echo "Max fuel transaction date for station 1253: " . ($max_date ?: 'None') . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
