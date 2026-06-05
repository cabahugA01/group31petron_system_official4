-- ============================================
-- COMPREHENSIVE TEST DATA CLEANUP SCRIPT
-- Date: June 5, 2026
-- Purpose: Remove test/orphaned data after user deletions
-- ============================================

SET FOREIGN_KEY_CHECKS=0;

-- 1. DELETE MERCHANDISE TRANSACTIONS with deleted user names
DELETE FROM merchandise_transactions 
WHERE customer_name IN ('AMIE CABAHUG', 'yang c.', 'Airel', 'markvincentmanonan');

-- 2. DELETE TEST DELIVERY RECORDS (multiple Topias Freshener test entries)
DELETE FROM deliveries_oversight 
WHERE (product = 'Topias Freshener' AND batch_id = 'BATCH-20260518-001')
   OR (product = 'Topias Freshener Premium' AND batch_id LIKE 'POM-%');

-- 3. DELETE TEST FUEL TRANSACTIONS (0.00 or 0.01 liter test entries)
DELETE FROM fuel_transactions 
WHERE liters_sold <= 0.01 AND status = 'Pending Validation';

-- 4. DELETE TEST CUSTOMERS (test email addresses)
DELETE FROM customers 
WHERE email LIKE '%test%' OR name LIKE '%test%';

-- 5. CLEAN UP ORPHANED JOB ORDERS (if any reference deleted staff)
DELETE FROM job_orders 
WHERE user_id NOT IN (SELECT id FROM users);

-- 6. CLEAN UP ORPHANED MERCHANDISE TRANSACTION ITEMS
DELETE FROM merchandise_transaction_items 
WHERE transaction_id NOT IN (SELECT id FROM merchandise_transactions);

-- 7. CLEAN UP ORPHANED FUEL DELIVERIES
DELETE FROM fuel_deliveries 
WHERE encoded_by NOT IN (SELECT id FROM users) AND encoded_by IS NOT NULL;

-- 8. CLEAN UP LOGIN ATTEMPTS for deleted users
DELETE FROM login_attempts 
WHERE user_id NOT IN (SELECT id FROM users) AND user_id IS NOT NULL;

-- 9. CLEAN UP USER PREFERENCES for deleted users
DELETE FROM user_preferences 
WHERE user_id NOT IN (SELECT id FROM users);

-- 10. CLEAN UP NOTIFICATIONS for deleted users
DELETE FROM user_notifications 
WHERE user_id NOT IN (SELECT id FROM users);

SET FOREIGN_KEY_CHECKS=1;

-- ============================================
-- VERIFICATION QUERIES
-- ============================================

SELECT 'Remaining Users' AS check_type, COUNT(*) AS count FROM users;
SELECT 'Transactions with deleted users' AS check_type, COUNT(*) AS count 
FROM merchandise_transactions 
WHERE customer_name IN ('AMIE CABAHUG', 'yang c.', 'Airel');
SELECT 'Test deliveries remaining' AS check_type, COUNT(*) AS count 
FROM deliveries_oversight 
WHERE product = 'Topias Freshener' AND batch_id = 'BATCH-20260518-001';
SELECT 'Test fuel transactions remaining' AS check_type, COUNT(*) AS count 
FROM fuel_transactions 
WHERE liters_sold <= 0.01 AND status = 'Pending Validation';

-- End of script
