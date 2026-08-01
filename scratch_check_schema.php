<?php
require_once __DIR__ . '/public/db_connect.php';

$tables = ['fuel_inventory', 'pending_price_approvals', 'fuel_price_history', 'inventory_logs', 'activity_logs', 'fuel_config_history'];

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
