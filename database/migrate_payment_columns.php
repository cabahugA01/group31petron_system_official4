<?php
/**
 * migrate_payment_columns.php
 * Run once: adds missing payment/workflow columns to job_orders and merchandise_transactions.
 * Safe to run multiple times — uses ADD COLUMN IF NOT EXISTS (MariaDB/MySQL 10.3+).
 * For older MySQL, wraps each in a try/catch so duplicates are silently ignored.
 */
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

$results = [];

$migrations = [
    // ── job_orders ────────────────────────────────────────────────────────────
    "ALTER TABLE `job_orders` ADD COLUMN IF NOT EXISTS `balance_due` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `amount_paid`",

    // ── merchandise_transactions ──────────────────────────────────────────────
    "ALTER TABLE `merchandise_transactions` ADD COLUMN IF NOT EXISTS `payment_status`  VARCHAR(60)   NOT NULL DEFAULT 'Pending Payment' AFTER `payment_method`",
    "ALTER TABLE `merchandise_transactions` ADD COLUMN IF NOT EXISTS `amount_paid`     DECIMAL(12,2) NOT NULL DEFAULT 0.00              AFTER `payment_status`",
    "ALTER TABLE `merchandise_transactions` ADD COLUMN IF NOT EXISTS `balance_due`     DECIMAL(12,2) NOT NULL DEFAULT 0.00              AFTER `amount_paid`",
    "ALTER TABLE `merchandise_transactions` ADD COLUMN IF NOT EXISTS `workflow_status` VARCHAR(60)   DEFAULT NULL                       AFTER `balance_due`",

    // ── payment_audit_log (idempotent — CREATE TABLE IF NOT EXISTS) ───────────
    "CREATE TABLE IF NOT EXISTS `payment_audit_log` (
        `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `record_id`      INT UNSIGNED NOT NULL,
        `record_source`  VARCHAR(60)  NOT NULL DEFAULT 'job_orders',
        `staff_id`       INT UNSIGNED NOT NULL,
        `station_id`     INT UNSIGNED NOT NULL,
        `amount_paid`    DECIMAL(12,2) NOT NULL DEFAULT 0,
        `payment_method` VARCHAR(60)  NOT NULL DEFAULT 'Cash',
        `balance_due`    DECIMAL(12,2) NOT NULL DEFAULT 0,
        `payment_status` VARCHAR(60)  NOT NULL DEFAULT 'Pending Payment',
        `remarks`        TEXT,
        `logged_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_record` (`record_id`, `record_source`),
        INDEX `idx_staff`  (`staff_id`),
        INDEX `idx_logged` (`logged_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ── Back-fill balance_due for job_orders where it is still 0 but total_cost > amount_paid ──
    "UPDATE `job_orders`
     SET `balance_due` = GREATEST(0, COALESCE(`total_cost`, `estimated_cost`, 0) - COALESCE(`amount_paid`, 0))
     WHERE `balance_due` = 0
       AND COALESCE(`total_cost`, `estimated_cost`, 0) > COALESCE(`amount_paid`, 0)
       AND `payment_status` NOT IN ('Paid')",

    // ── Back-fill payment_status for merchandise_transactions from validation_status ──
    "UPDATE `merchandise_transactions`
     SET `payment_status` = CASE
         WHEN `payment_method` IN ('Credit','Account Receivable','utang','Utang') THEN 'Credit Transaction'
         WHEN COALESCE(`amount_tendered`, 0) >= `total_amount` THEN 'Paid'
         WHEN COALESCE(`amount_tendered`, 0) > 0 THEN 'Partial Payment'
         ELSE 'Pending Payment'
     END
     WHERE `payment_status` = 'Pending Payment'",

    // ── Back-fill balance_due for merchandise_transactions ────────────────────
    "UPDATE `merchandise_transactions`
     SET `balance_due` = CASE
         WHEN `payment_status` = 'Paid' THEN 0
         WHEN `payment_status` = 'Credit Transaction' THEN `total_amount`
         ELSE GREATEST(0, `total_amount` - COALESCE(`amount_paid`, `amount_tendered`, 0))
     END
     WHERE `balance_due` = 0",

    // ── Back-fill amount_paid for merchandise_transactions ────────────────────
    "UPDATE `merchandise_transactions`
     SET `amount_paid` = COALESCE(`amount_tendered`, 0)
     WHERE `amount_paid` = 0 AND COALESCE(`amount_tendered`, 0) > 0",
];

foreach ($migrations as $sql) {
    try {
        $pdo->exec($sql);
        $short = substr(trim(preg_replace('/\s+/', ' ', $sql)), 0, 80);
        $results[] = ['ok', $short];
    } catch (PDOException $e) {
        // Duplicate column = already applied — not an error
        if (strpos($e->getMessage(), 'Duplicate column') !== false ||
            strpos($e->getMessage(), 'already exists') !== false) {
            $short = substr(trim(preg_replace('/\s+/', ' ', $sql)), 0, 80);
            $results[] = ['skip', $short . ' (already applied)'];
        } else {
            $short = substr(trim(preg_replace('/\s+/', ' ', $sql)), 0, 80);
            $results[] = ['error', $short . ' → ' . $e->getMessage()];
        }
    }
}

// Output
header('Content-Type: text/plain; charset=utf-8');
echo "=== Payment Column Migration ===\n\n";
foreach ($results as [$status, $msg]) {
    $icon = $status === 'ok' ? '✓' : ($status === 'skip' ? '~' : '✗');
    echo "$icon [$status] $msg\n";
}
echo "\nDone.\n";
