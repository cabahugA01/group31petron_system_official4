<?php
require_once __DIR__ . '/../public/db_connect.php';
try {
    $q = $pdo->query("SELECT * FROM inventory_products WHERE LOWER(category) = 'fuel'");
    while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
        print_r($row);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
