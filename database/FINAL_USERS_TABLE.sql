-- ═══════════════════════════════════════════════════════════════
-- FINAL USERS TABLE STRUCTURE
-- This will clean up and standardize the users table
-- ═══════════════════════════════════════════════════════════════

-- STEP 1: Drop unnecessary columns
-- ─────────────────────────────────────────────────────────────
ALTER TABLE `users` DROP COLUMN IF EXISTS `emp_id`;
ALTER TABLE `users` DROP COLUMN IF EXISTS `hourly_rate`;
ALTER TABLE `users` DROP COLUMN IF EXISTS `is_deleted`;

-- STEP 2: Rename columns to standard names
-- ─────────────────────────────────────────────────────────────

-- Rename 'phone' to 'phone_number'
ALTER TABLE `users` 
CHANGE COLUMN `phone` `phone_number` VARCHAR(20) NULL;

-- Rename 'password' to 'password_hash'
ALTER TABLE `users` 
CHANGE COLUMN `password` `password_hash` VARCHAR(255) NOT NULL;

-- STEP 3: Ensure required columns exist and have correct types
-- ─────────────────────────────────────────────────────────────

-- id (primary key)
ALTER TABLE `users` 
MODIFY COLUMN `id` INT(11) NOT NULL AUTO_INCREMENT;

-- first_name
ALTER TABLE `users` 
MODIFY COLUMN `first_name` VARCHAR(100) NOT NULL;

-- last_name
ALTER TABLE `users` 
MODIFY COLUMN `last_name` VARCHAR(100) NOT NULL;

-- station_id (add if missing)
ALTER TABLE `users` 
ADD COLUMN IF NOT EXISTS `station_id` INT(11) NULL AFTER `last_name`;

-- email (add if missing)
ALTER TABLE `users` 
ADD COLUMN IF NOT EXISTS `email` VARCHAR(255) NULL AFTER `station_id`;

-- username
ALTER TABLE `users` 
MODIFY COLUMN `username` VARCHAR(100) NOT NULL UNIQUE;

-- phone_number (already renamed above)

-- password_hash (already renamed above)

-- role
ALTER TABLE `users` 
MODIFY COLUMN `role` ENUM('SuperAdmin', 'Admin', 'Manager', 'Staff') NOT NULL DEFAULT 'Staff';

-- status
ALTER TABLE `users` 
MODIFY COLUMN `status` ENUM('Active', 'Locked', 'Disabled') NOT NULL DEFAULT 'Active';

-- created_at
ALTER TABLE `users` 
MODIFY COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- updated_at (add if missing, modify if exists)
ALTER TABLE `users` 
ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;

-- STEP 4: Add indexes for performance
-- ─────────────────────────────────────────────────────────────

-- Drop old indexes if they exist
ALTER TABLE `users` DROP INDEX IF EXISTS `uk_username`;
ALTER TABLE `users` DROP INDEX IF EXISTS `idx_email`;
ALTER TABLE `users` DROP INDEX IF EXISTS `idx_phone_number`;
ALTER TABLE `users` DROP INDEX IF EXISTS `idx_status`;
ALTER TABLE `users` DROP INDEX IF EXISTS `idx_station_id`;

-- Add new indexes
ALTER TABLE `users` ADD UNIQUE INDEX `uk_username` (`username`);
ALTER TABLE `users` ADD UNIQUE INDEX `uk_email` (`email`);
ALTER TABLE `users` ADD UNIQUE INDEX `uk_phone_number` (`phone_number`);
ALTER TABLE `users` ADD INDEX `idx_status` (`status`);
ALTER TABLE `users` ADD INDEX `idx_station_id` (`station_id`);
ALTER TABLE `users` ADD INDEX `idx_role` (`role`);

-- STEP 5: Add foreign key constraint
-- ─────────────────────────────────────────────────────────────

-- Drop old foreign key if exists
ALTER TABLE `users` DROP FOREIGN KEY IF EXISTS `fk_users_station_id`;

-- Add foreign key
ALTER TABLE `users` 
ADD CONSTRAINT `fk_users_station_id` 
FOREIGN KEY (`station_id`) 
REFERENCES `stations`(`id`) 
ON DELETE SET NULL 
ON UPDATE CASCADE;

-- ═══════════════════════════════════════════════════════════════
-- FINAL STRUCTURE:
-- ═══════════════════════════════════════════════════════════════
-- id              INT(11) PRIMARY KEY AUTO_INCREMENT
-- first_name      VARCHAR(100) NOT NULL
-- last_name       VARCHAR(100) NOT NULL
-- station_id      INT(11) NULL (Foreign Key → stations.id)
-- email           VARCHAR(255) NULL UNIQUE
-- username        VARCHAR(100) NOT NULL UNIQUE
-- phone_number    VARCHAR(20) NULL UNIQUE
-- password_hash   VARCHAR(255) NOT NULL
-- role            ENUM('SuperAdmin','Admin','Manager','Staff') NOT NULL
-- status          ENUM('Active','Locked','Disabled') NOT NULL
-- created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
-- updated_at      TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP
-- ═══════════════════════════════════════════════════════════════

-- Verify structure:
DESCRIBE `users`;
