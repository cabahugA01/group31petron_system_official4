<?php
require_once __DIR__ . '/public/db_connect.php';
header('Content-Type: text/plain');

try {
    $q = $pdo->query("SELECT id, name, type, balance FROM customers LIMIT 10");
    while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
        echo "Customer ID: {$r['id']} - Name: {$r['name']} - Type: {$r['type']} - Balance: {$r['balance']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
