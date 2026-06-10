-- ============================================================================
-- CLEAR MERCHANDISE TRANSACTIONS & JOB ORDERS DATA
-- ============================================================================
-- Purpose: Delete all merchandise transaction and job order records
-- Date: June 10, 2026
-- Author: Kiro AI Assistant
-- 
-- IMPORTANT: This will permanently delete data. Make backup first!
-- ============================================================================

-- Disable foreign key checks temporarily
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- 1. MERCHANDISE TRANSACTIONS
-- ============================================================================

-- Delete all merchandise transaction items
TRUNCATE TABLE `merchandise_transaction_items`;

-- Delete all merchandise transactions
TRUNCATE TABLE `merchandise_transactions`;

-- Reset auto-increment for merchandise transactions
ALTER TABLE `merchandise_transactions` AUTO_INCREMENT = 1;
ALTER TABLE `merchandise_transaction_items` AUTO_INCREMENT = 1;

-- ============================================================================
-- 2. JOB ORDERS
-- ============================================================================

-- Delete all job order items
TRUNCATE TABLE `job_order_items`;

-- Delete all job order services
TRUNCATE TABLE `job_order_services`;

-- Delete all job order labor entries
DELETE FROM `labor_entries` WHERE `job_order_id` IS NOT NULL;

-- Delete all job orders
TRUNCATE TABLE `job_orders`;

-- Reset auto-increment for job orders
ALTER TABLE `job_orders` AUTO_INCREMENT = 1;
ALTER TABLE `job_order_items` AUTO_INCREMENT = 1;
ALTER TABLE `job_order_services` AUTO_INCREMENT = 1;

-- ============================================================================
-- 3. RELATED AUDIT LOGS (Optional - remove if you want to keep audit trail)
-- ============================================================================

-- Delete audit logs related to merchandise transactions
DELETE FROM `audit_logs` 
WHERE `entity_type` = 'merchandise_transaction' 
   OR `action_type` LIKE '%merchandise%'
   OR `action_type` LIKE '%transaction%';

-- Delete audit logs related to job orders
DELETE FROM `audit_logs` 
WHERE `entity_type` = 'job_order' 
   OR `action_type` LIKE '%job order%'
   OR `action_type` LIKE '%job_order%';

-- ============================================================================
-- 4. ACTIVITY LOGS (Optional - remove if you want to keep activity trail)
-- ============================================================================

-- Delete activity logs related to merchandise transactions
DELETE FROM `activity_logs` 
WHERE `action` LIKE '%merchandise%'
   OR `action` LIKE '%transaction%'
   OR `details` LIKE '%merchandise_transaction%';

-- Delete activity logs related to job orders
DELETE FROM `activity_logs` 
WHERE `action` LIKE '%job order%'
   OR `action` LIKE '%Job Order%'
   OR `details` LIKE '%job_order%';

-- ============================================================================
-- 5. NOTIFICATIONS (Optional - clean up related notifications)
-- ============================================================================

-- Delete notifications related to merchandise transactions
DELETE FROM `notifications` 
WHERE `type` = 'merchandise_transaction' 
   OR `message` LIKE '%merchandise transaction%'
   OR `redirect_url` LIKE '%merchandise%';

-- Delete notifications related to job orders
DELETE FROM `notifications` 
WHERE `type` = 'job_order' 
   OR `message` LIKE '%job order%'
   OR `redirect_url` LIKE '%job_order%';

-- ============================================================================
-- 6. CUSTOMER BALANCES (Optional - reset if transactions were on credit)
-- ============================================================================

-- WARNING: Only uncomment this if you want to reset customer balances
-- UPDATE `customers` SET `credit_balance` = 0.00 WHERE `credit_balance` > 0;

-- ============================================================================
-- Re-enable foreign key checks
-- ============================================================================
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- VERIFICATION QUERIES
-- ============================================================================

-- Check remaining records (should all return 0)
SELECT 'Merchandise Transactions' AS table_name, COUNT(*) AS record_count FROM `merchandise_transactions`
UNION ALL
SELECT 'Merchandise Transaction Items', COUNT(*) FROM `merchandise_transaction_items`
UNION ALL
SELECT 'Job Orders', COUNT(*) FROM `job_orders`
UNION ALL
SELECT 'Job Order Items', COUNT(*) FROM `job_order_items`
UNION ALL
SELECT 'Job Order Services', COUNT(*) FROM `job_order_services`;

-- ============================================================================
-- COMPLETION MESSAGE
-- ============================================================================
SELECT '✓ All merchandise transactions and job orders have been deleted!' AS status;
