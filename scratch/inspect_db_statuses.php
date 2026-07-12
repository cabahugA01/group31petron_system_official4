<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

echo "=== purchase_orders status values ===\n";
try {
    $stmt = $pdo->query("SELECT DISTINCT status FROM purchase_orders");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "- " . $r['status'] . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "=== fuel_purchase_orders status values ===\n";
try {
    $stmt = $pdo->query("SELECT DISTINCT status FROM fuel_purchase_orders");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "- " . $r['status'] . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "=== deliveries_oversight status values ===\n";
try {
    $stmt = $pdo->query("SELECT DISTINCT status FROM deliveries_oversight");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "- " . $r['status'] . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
