<?php
require_once __DIR__ . '/../public/db_connect.php';

try {
    echo "=== DATABASE TABLES ===\n";
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    print_r($tables);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
