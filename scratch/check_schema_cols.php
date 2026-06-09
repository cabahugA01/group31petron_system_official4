<?php
require_once __DIR__ . '/../public/db_connect.php';

try {
    echo "=== fuel_transactions columns ===\n";
    $q = $pdo->query("DESCRIBE fuel_transactions");
    while($row = $q->fetch(PDO::FETCH_ASSOC)) {
        echo $row['Field'] . " - " . $row['Type'] . "\n";
    }
    
    echo "\n=== fuel_pumps columns ===\n";
    $q = $pdo->query("DESCRIBE fuel_pumps");
    while($row = $q->fetch(PDO::FETCH_ASSOC)) {
        echo $row['Field'] . " - " . $row['Type'] . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
