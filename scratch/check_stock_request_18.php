<?php
require_once __DIR__ . '/../public/db_connect.php';

try {
    $stmt = $pdo->prepare("SELECT * FROM stock_requests WHERE id = 18");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "--- stock_requests row 18 ---\n";
    print_r($row);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
