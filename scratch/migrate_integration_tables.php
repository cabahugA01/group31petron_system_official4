<?php
require_once __DIR__ . '/../public/db_connect.php';

try {
    echo "Starting migration of integration tables...\n";

    // 1. Alter integration_audit target_type to VARCHAR(100) to support all targets
    $pdo->exec("ALTER TABLE `integration_audit` MODIFY COLUMN `target_type` VARCHAR(100) NOT NULL");
    echo "Modified integration_audit.target_type to VARCHAR(100) successfully.\n";

    // 2. Alter sync_logs to add sync_job_id and sync_status
    // Let's check if sync_job_id exists
    $cols = $pdo->query("DESCRIBE `sync_logs`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('sync_job_id', $cols)) {
        $pdo->exec("ALTER TABLE `sync_logs` ADD COLUMN `sync_job_id` INT NULL AFTER `id`");
        echo "Added sync_job_id column to sync_logs.\n";
    }
    
    if (!in_array('sync_status', $cols)) {
        $pdo->exec("ALTER TABLE `sync_logs` ADD COLUMN `sync_status` ENUM('success','failed','pending') NOT NULL DEFAULT 'pending' AFTER `sync_job_id`");
        echo "Added sync_status column to sync_logs.\n";
    }

    echo "Migration complete!\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
