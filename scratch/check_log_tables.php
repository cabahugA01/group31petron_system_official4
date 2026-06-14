<?php
require_once __DIR__ . '/../public/db_connect.php';

$log_tables = [
    'activity_logs',
    'system_activity_logs',
    'audit_logs',
    'audit_trail',
    'audit_log'
];

foreach ($log_tables as $t) {
    echo "\n=== $t Columns ===\n";
    try {
        $stmt = $pdo->query("DESCRIBE `$t`");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "  - {$row['Field']} ({$row['Type']})\n";
        }
    } catch (Exception $e) {
        echo "  Table $t does not exist or error: " . $e->getMessage() . "\n";
    }
}
