<?php
require_once __DIR__ . '/../public/db_connect.php';
echo "=== audit_logs structure ===\n";
foreach ($pdo->query("DESCRIBE audit_logs")->fetchAll(PDO::FETCH_ASSOC) as $col) {
    echo "{$col['Field']} - {$col['Type']} - Null: {$col['Null']} - Key: {$col['Key']} - Default: {$col['Default']}\n";
}
