<?php
require_once __DIR__ . '/../public/db_connect.php';

$ddls = [
    "ALTER TABLE inventory_logs ADD COLUMN IF NOT EXISTS movement_type VARCHAR(10) DEFAULT 'OUT' AFTER action",
    "ALTER TABLE inventory_logs ADD COLUMN IF NOT EXISTS reason VARCHAR(255) DEFAULT NULL AFTER movement_type",
    "ALTER TABLE inventory_logs ADD COLUMN IF NOT EXISTS reference_no VARCHAR(100) DEFAULT NULL AFTER reference_id",
    "ALTER TABLE inventory_logs ADD INDEX IF NOT EXISTS idx_inv_log_station (station_id)",
    "ALTER TABLE inventory_logs ADD INDEX IF NOT EXISTS idx_inv_log_prod (product_id)",
    "ALTER TABLE inventory_logs ADD INDEX IF NOT EXISTS idx_inv_log_ref (reference_no)"
];

foreach ($ddls as $ddl) {
    try {
        $pdo->exec($ddl);
        echo "[+] Executed: $ddl\n";
    } catch (Exception $e) {
        echo "[-] Notice: " . $e->getMessage() . "\n";
    }
}
