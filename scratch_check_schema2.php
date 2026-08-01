<?php
require_once __DIR__ . '/public/db_connect.php';

$tables = ['fuel_price_log', 'fuel_pricing', 'fuel_config', 'fuel_audit_trail', 'fuel_management_config', 'pending_price_approvals', 'master_data_requests'];

foreach ($tables as $tbl) {
    echo "=== $tbl ===\n";
    try {
        $stmt = $pdo->query("DESCRIBE $tbl");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "  {$row['Field']} ({$row['Type']})\n";
        }
    } catch (Exception $e) {
        echo "  Table error: " . $e->getMessage() . "\n";
    }
}
