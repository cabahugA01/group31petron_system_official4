<?php
require_once __DIR__ . '/../public/db_connect.php';

try {
    $stmt = $pdo->prepare("SELECT * FROM audit_logs WHERE action_details LIKE '%PR-20260710-0001%' OR entity_id = 17 OR action_details LIKE '%PO ID: 17%' OR action_details LIKE '%17%' ORDER BY id DESC LIMIT 50");
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "--- Audit Logs for PO 17 ---\n";
    print_r($logs);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
