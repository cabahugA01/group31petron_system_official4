<?php
require_once __DIR__ . '/../public/db_connect.php';
echo "=== fuel_deliveries structure ===\n";
foreach ($pdo->query("DESCRIBE fuel_deliveries")->fetchAll(PDO::FETCH_ASSOC) as $col) {
    echo "{$col['Field']} - {$col['Type']} - Null: {$col['Null']} - Key: {$col['Key']} - Default: {$col['Default']}\n";
}
echo "=== sample row / unique values of status ===\n";
try {
    $rows = $pdo->query("SELECT DISTINCT status FROM fuel_deliveries")->fetchAll(PDO::FETCH_ASSOC);
    print_r($rows);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
