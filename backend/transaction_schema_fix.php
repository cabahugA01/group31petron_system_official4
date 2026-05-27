<?php
/**
 * transaction_schema_fix.php
 * Ensures all columns and tables required by the transaction module exist.
 * Safe to call on every request — uses IF NOT EXISTS / ADD COLUMN IF NOT EXISTS.
 * Include AFTER db_connect.php.
 */
if (!isset($pdo)) return;

$fixes = [
    // ── merchandise_transactions columns ─────────────────────────────────────
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS validation_status  VARCHAR(60)  DEFAULT 'Pending'",
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS workflow_status     VARCHAR(60)  DEFAULT 'Pending'",
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS validated_by        INT          DEFAULT NULL",
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS validated_at        DATETIME     DEFAULT NULL",
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS rejection_reason    TEXT         DEFAULT NULL",
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS remarks             TEXT         DEFAULT NULL",
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS amount_paid         DECIMAL(12,2) DEFAULT 0",
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS balance_due         DECIMAL(12,2) DEFAULT NULL",
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS amount_tendered     DECIMAL(12,2) DEFAULT NULL",
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS payment_status      VARCHAR(60)  DEFAULT 'Unpaid'",
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS transaction_type    VARCHAR(60)  DEFAULT 'merchandise'",
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS job_order_service   VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS job_order_vehicle_plate VARCHAR(60) DEFAULT NULL",
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS job_order_mechanic_name VARCHAR(120) DEFAULT NULL",
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS updated_at          DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP",

    // ── fuel_transactions columns ─────────────────────────────────────────────
    "ALTER TABLE fuel_transactions ADD COLUMN IF NOT EXISTS status        VARCHAR(60)  DEFAULT 'Pending'",
    "ALTER TABLE fuel_transactions ADD COLUMN IF NOT EXISTS validated_by  INT          DEFAULT NULL",
    "ALTER TABLE fuel_transactions ADD COLUMN IF NOT EXISTS validated_at  DATETIME     DEFAULT NULL",
    "ALTER TABLE fuel_transactions ADD COLUMN IF NOT EXISTS reject_reason TEXT         DEFAULT NULL",
    "ALTER TABLE fuel_transactions ADD COLUMN IF NOT EXISTS manager_id    INT          DEFAULT NULL",

    // ── job_orders columns ────────────────────────────────────────────────────
    "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS validation_status VARCHAR(60)  DEFAULT 'Pending Validation'",
    "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS validated_by      INT          DEFAULT NULL",
    "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS validated_at      DATETIME     DEFAULT NULL",
    "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS rejection_reason  TEXT         DEFAULT NULL",
    "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS amount_paid       DECIMAL(12,2) DEFAULT 0",
    "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS balance_due       DECIMAL(12,2) DEFAULT NULL",
    "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS payment_status    VARCHAR(60)  DEFAULT 'Unpaid'",
    "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS updated_at        DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP",
];

foreach ($fixes as $sql) {
    try { $pdo->exec($sql); } catch (Exception $e) { /* column may already exist */ }
}

// ── audit_trail table ─────────────────────────────────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS audit_trail (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            transaction_id VARCHAR(255) NOT NULL,
            manager_id     INT          NOT NULL,
            action_type    VARCHAR(60)  NOT NULL,
            new_value      TEXT         DEFAULT NULL,
            old_value      TEXT         DEFAULT NULL,
            station_id     INT          NOT NULL DEFAULT 0,
            entity_type    VARCHAR(60)  DEFAULT NULL,
            created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_txn  (transaction_id),
            INDEX idx_mgr  (manager_id),
            INDEX idx_ts   (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {}

// ── payment_audit_log table ───────────────────────────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payment_audit_log (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            record_id      INT          NOT NULL,
            record_source  VARCHAR(80)  NOT NULL DEFAULT 'merchandise_transactions',
            staff_id       INT          DEFAULT NULL,
            station_id     INT          NOT NULL DEFAULT 0,
            amount_paid    DECIMAL(12,2) DEFAULT 0,
            payment_method VARCHAR(60)  DEFAULT NULL,
            balance_due    DECIMAL(12,2) DEFAULT 0,
            payment_status VARCHAR(60)  DEFAULT NULL,
            remarks        TEXT         DEFAULT NULL,
            logged_at      DATETIME     DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_rec  (record_id, record_source),
            INDEX idx_sta  (station_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {}
