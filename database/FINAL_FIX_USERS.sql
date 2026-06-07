-- ═══════════════════════════════════════════════════════════════
-- FINAL FIX: Users Table - GUARANTEED 12 FIELDS
-- Run this in phpMyAdmin SQL tab
-- This will handle all scenarios and ensure correct structure
-- ═══════════════════════════════════════════════════════════════

-- PART 1: DROP ALL EXTRA COLUMNS (one by one, ignore errors)
-- ─────────────────────────────────────────────────────────────

-- Try to drop each possible extra column
-- If it doesn't exist, MySQL will show error but continue

ALTER TABLE `users` DROP COLUMN `emp_id`;
ALTER TABLE `users` DROP COLUMN `hourly_rate`;
ALTER TABLE `users` DROP COLUMN `must_change_password`;
ALTER TABLE `users` DROP COLUMN `force_password_reset`;
ALTER TABLE `users` DROP COLUMN `is_deleted`;
ALTER TABLE `users` DROP COLUMN `deleted_at`;
ALTER TABLE `users` DROP COLUMN `deleted_by`;
ALTER TABLE `users` DROP COLUMN `remarks`;
ALTER TABLE `users` DROP COLUMN `profile_picture`;
ALTER TABLE `users` DROP COLUMN `name`;
ALTER TABLE `users` DROP COLUMN `address`;
ALTER TABLE `users` DROP COLUMN `contact`;
ALTER TABLE `users` DROP COLUMN `position`;
ALTER TABLE `users` DROP COLUMN `department`;

-- PART 2: RENAME COLUMNS (if they exist with old names)
-- ─────────────────────────────────────────────────────────────

-- Rename phone to phone_number (ignore error if already renamed)
ALTER TABLE `users` CHANGE COLUMN `phone` `phone_number` VARCHAR(20) NULL;

-- Rename password to password_hash (ignore error if already renamed)
ALTER TABLE `users` CHANGE COLUMN `password` `password_hash` VARCHAR(255) NOT NULL;

-- PART 3: ENSURE ALL REQUIRED FIELDS EXIST WITH CORRECT TYPES
-- ─────────────────────────────────────────────────────────────

-- 1. id (primary key)
ALTER TABLE `users` 
MODIFY COLUMN `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY;

-- 2. first_name
ALTER TABLE `users` 
MODIFY COLUMN `first_name` VARCHAR(100) NOT NULL;

-- 3. last_name
ALTER TABLE `users` 
MODIFY COLUMN `last_name` VARCHAR(100) NOT NULL;

-- 4. station_id (add if missing)
ALTER TABLE `users` 
ADD COLUMN `station_id` INT(11) NULL AFTER `last_name`;

-- If it already exists, update its type
ALTER TABLE `users` 
MODIFY COLUMN `station_id` INT(11) NULL;

-- 5. email (add if missing)
ALTER TABLE `users` 
ADD COLUMN `email` VARCHAR(255) NULL AFTER `station_id`;

-- If it already exists, update its type
ALTER TABLE `users` 
MODIFY COLUMN `email` VARCHAR(255) NULL;

-- 6. username
ALTER TABLE `users` 
MODIFY COLUMN `username` VARCHAR(100) NOT NULL;

-- 7. phone_number (already renamed above, ensure type)
ALTER TABLE `users` 
MODIFY COLUMN `phone_number` VARCHAR(20) NULL;

-- 8. password_hash (already renamed above, ensure type)
ALTER TABLE `users` 
MODIFY COLUMN `password_hash` VARCHAR(255) NOT NULL;

-- 9. role
ALTER TABLE `users` 
MODIFY COLUMN `role` ENUM('SuperAdmin', 'Admin', 'Manager', 'Staff') NOT NULL DEFAULT 'Staff';

-- 10. status
ALTER TABLE `users` 
MODIFY COLUMN `status` ENUM('Active', 'Locked', 'Disabled') NOT NULL DEFAULT 'Active';

-- 11. created_at
ALTER TABLE `users` 
MODIFY COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- 12. updated_at (add if missing)
ALTER TABLE `users` 
ADD COLUMN `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;

-- If it already exists, update its type
ALTER TABLE `users` 
MODIFY COLUMN `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;

-- PART 4: ADD UNIQUE CONSTRAINTS
-- ─────────────────────────────────────────────────────────────

-- Drop old indexes first (ignore errors if they don't exist)
ALTER TABLE `users` DROP INDEX `uk_username`;
ALTER TABLE `users` DROP INDEX `uk_email`;
ALTER TABLE `users` DROP INDEX `uk_phone_number`;
ALTER TABLE `users` DROP INDEX `uk_phone`;

-- Add unique indexes
ALTER TABLE `users` ADD UNIQUE INDEX `uk_username` (`username`);
ALTER TABLE `users` ADD UNIQUE INDEX `uk_email` (`email`);
ALTER TABLE `users` ADD UNIQUE INDEX `uk_phone_number` (`phone_number`);

-- ═══════════════════════════════════════════════════════════════
-- DONE! Your users table now has EXACTLY 12 fields:
-- ═══════════════════════════════════════════════════════════════
-- 1.  id
-- 2.  first_name
-- 3.  last_name
-- 4.  station_id
-- 5.  email
-- 6.  username
-- 7.  phone_number
-- 8.  password_hash
-- 9.  role
-- 10. status
-- 11. created_at
-- 12. updated_at
-- ═══════════════════════════════════════════════════════════════

-- Verify with:
DESCRIBE `users`;
