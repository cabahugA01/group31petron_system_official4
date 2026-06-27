-- Migration: Add Employee ID to Users Table
-- Purpose: Add unique employee ID with role-based prefixes (ADM, MGR, STF)
-- Date: 2026-06-27

-- Add employee_id column
ALTER TABLE `users`
ADD COLUMN `employee_id` varchar(20) DEFAULT NULL COMMENT 'Employee ID with role prefix (ADM-001, MGR-001, STF-001)' AFTER `id`,
ADD UNIQUE KEY `uk_employee_id` (`employee_id`);

-- Generate employee IDs for existing users
-- Admin accounts
SET @adm_counter = 0;
UPDATE `users` 
SET `employee_id` = CONCAT('ADM-', LPAD((@adm_counter := @adm_counter + 1), 3, '0'))
WHERE `role` = 'admin'
ORDER BY `id`;

-- Manager accounts
SET @mgr_counter = 0;
UPDATE `users` 
SET `employee_id` = CONCAT('MGR-', LPAD((@mgr_counter := @mgr_counter + 1), 3, '0'))
WHERE `role` = 'manager'
ORDER BY `id`;

-- Staff accounts
SET @stf_counter = 0;
UPDATE `users` 
SET `employee_id` = CONCAT('STF-', LPAD((@stf_counter := @stf_counter + 1), 3, '0'))
WHERE `role` = 'staff'
ORDER BY `id`;

-- SuperAdmin accounts (optional - they don't typically need employee IDs)
SET @sa_counter = 0;
UPDATE `users` 
SET `employee_id` = CONCAT('SA-', LPAD((@sa_counter := @sa_counter + 1), 3, '0'))
WHERE `role` = 'superadmin'
ORDER BY `id`;
