-- ═══════════════════════════════════════════════════════════════════════════
-- CLEAR TEST TRANSACTION DATA
-- This script removes all merchandise and job order transaction data
-- Use this to clean test data before production or new testing cycle
-- ═══════════════════════════════════════════════════════════════════════════

-- WARNING: This will permanently delete transaction data!
-- Make sure to backup your database before running this script

SET FOREIGN_KEY_CHECKS = 0;

-- ──────────────────────────────────────────────────────────────────────────
-- 1. Clear Job Order Related Tables
-- ──────────────────────────────────────────────────────────────────────────

-- Clear job order items (services/items in each job order)
TRUNCATE TABLE job_order_items;

-- Clear main job orders table
TRUNCATE TABLE job_orders;

-- Clear job order activity/audit logs
TRUNCATE TABLE job_order_activity;

-- Clear job order adjustments (if exists)
-- TRUNCATE TABLE job_order_adjustments;

-- ──────────────────────────────────────────────────────────────────────────
-- 2. Clear Merchandise Transaction Tables
-- ──────────────────────────────────────────────────────────────────────────

-- Clear merchandise transaction items (line items)
TRUNCATE TABLE merchandise_transaction_items;

-- Clear main merchandise transactions table
TRUNCATE TABLE merchandise_transactions;

-- Clear merchandise history/activity logs (if exists)
-- TRUNCATE TABLE merchandise_transaction_history;

-- ──────────────────────────────────────────────────────────────────────────
-- 3. Clear Combined Transaction Tables (if exists)
-- ──────────────────────────────────────────────────────────────────────────

-- If you have a combined transactions table that links job orders + merchandise
-- TRUNCATE TABLE combined_transactions;

-- ──────────────────────────────────────────────────────────────────────────
-- 4. Clear Payment Records Related to Transactions
-- ──────────────────────────────────────────────────────────────────────────

-- Clear payments linked to job orders and merchandise
-- ONLY if you want to clear payment records too
-- DELETE FROM payments WHERE transaction_type IN ('job_order', 'merchandise', 'combined');

-- ──────────────────────────────────────────────────────────────────────────
-- 5. Reset Auto Increment Counters (Optional)
-- ──────────────────────────────────────────────────────────────────────────

-- Reset job order ID sequence to start from 1
ALTER TABLE job_orders AUTO_INCREMENT = 1;

-- Reset job order items sequence
ALTER TABLE job_order_items AUTO_INCREMENT = 1;

-- Reset merchandise transactions sequence
ALTER TABLE merchandise_transactions AUTO_INCREMENT = 1;

-- Reset merchandise transaction items sequence
ALTER TABLE merchandise_transaction_items AUTO_INCREMENT = 1;

SET FOREIGN_KEY_CHECKS = 1;

-- ═══════════════════════════════════════════════════════════════════════════
-- VERIFICATION QUERIES
-- Run these to confirm data has been cleared
-- ═══════════════════════════════════════════════════════════════════════════

-- Check job orders count
SELECT COUNT(*) as job_orders_count FROM job_orders;

-- Check merchandise transactions count
SELECT COUNT(*) as merchandise_count FROM merchandise_transactions;

-- Check job order items count
SELECT COUNT(*) as jo_items_count FROM job_order_items;

-- Check merchandise transaction items count
SELECT COUNT(*) as merch_items_count FROM merchandise_transaction_items;
