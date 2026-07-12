<?php
require_once __DIR__ . '/../public/db_connect.php';

try {
    $stmt = $pdo->prepare("SELECT * FROM activity_logs WHERE details LIKE '%PR-20260710-0001%' OR details LIKE '%17%' ORDER BY id DESC LIMIT 50");
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "--- Activity Logs for PO 17 ---\n";
    print_r($logs);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
