<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== activity_logs Sample Rows ===\n";
try {
    $stmt = $pdo->query("SELECT * FROM activity_logs ORDER BY id DESC LIMIT 5");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    print_r($rows);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
