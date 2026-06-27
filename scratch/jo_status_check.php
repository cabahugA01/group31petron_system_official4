<?php
require_once __DIR__ . '/../public/db_connect.php';

try {
    $stmt = $pdo->query("SELECT status, COUNT(*) AS count FROM job_orders GROUP BY status");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "Status: {$row['status']} - Count: {$row['count']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
