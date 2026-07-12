<?php
require_once __DIR__ . '/../public/db_connect.php';

try {
    $stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id = 17");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "--- purchase_orders row 17 ---\n";
    print_r($row);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
