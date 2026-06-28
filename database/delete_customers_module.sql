-- ============================================================
-- DELETE CUSTOMERS MODULE - PERMANENT REMOVAL
-- Petron Station Management System
-- Date: June 28, 2026
-- WARNING: This script will permanently delete all customer data
-- ============================================================

-- Disable foreign key checks temporarily
SET FOREIGN_KEY_CHECKS = 0;

-- Drop all customer-related tables
DROP TABLE IF EXISTS `customer_update_requests`;
DROP TABLE IF EXISTS `customer_documents_access_log`;
DROP TABLE IF EXISTS `customer_transactions`;
DROP TABLE IF EXISTS `customer_credit_transactions`;
DROP TABLE IF EXISTS `customers`;

-- Remove customer permissions
DELETE FROM `role_permissions` WHERE `permission_id` IN (
    SELECT `id` FROM `permissions` WHERE `module` = 'customers'
);

DELETE FROM `permissions` WHERE `module` = 'customers';

-- Remove customer module from station_modules
DELETE FROM `station_modules` WHERE `module_key` = 'customers';

-- Remove customer-related audit logs (optional - comment out if you want to keep audit history)
DELETE FROM `audit_log` WHERE `table_name` = 'customers';

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- Verification queries (run these to confirm deletion)
-- SELECT COUNT(*) FROM customers; -- Should error if table doesn't exist
-- SELECT COUNT(*) FROM station_modules WHERE module_key = 'customers'; -- Should return 0
-- SELECT COUNT(*) FROM permissions WHERE module = 'customers'; -- Should return 0

