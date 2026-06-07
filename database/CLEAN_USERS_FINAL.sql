-- ═══════════════════════════════════════════════════════════════
-- CLEAN USERS TABLE - REMOVE UNNECESSARY FIELDS
-- This will keep ONLY the 12 required fields
-- ═══════════════════════════════════════════════════════════════

-- STEP 1: DROP all unnecessary columns
-- ─────────────────────────────────────────────────────────────

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

-- STEP 2: RENAME columns to standard names
-- ─────────────────────────────────────────────────────────────

-- Rename 'phone' to 'phone_number'
ALTER TABLE `users` 
CHANGE COLUMN `phone` `phone_number` VARCHAR(20) NULL;

-- Rename 'password' to 'password_hash'
ALTER TABLE `users` 
CHANGE COLUMN `password` `password_hash` VARCHAR(255) NOT NULL;

-- STEP 3: UPDATE field types to match requirements
-- ─────────────────────────────────────────────────────────────

-- id - Primary Key
ALTER TABLE `users` 
MODIFY COLUMN `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY;

-- first_name - NOT NULL
ALTER TABLE `users` 
MODIFY COLUMN `first_name` VARCHAR(100) NOT NULL;

-- last_name - NOT NULL
ALTER TABLE `users` 
MODIFY COLUMN `last_name` VARCHAR(100) NOT NULL;

-- station_id - Foreign Key (nullable)
ALTER TABLE `users` 
MODIFY COLUMN `station_id` INT(11) NULL;

-- email - Unique, optional
ALTER TABLE `users` 
MODIFY COLUMN `email` VARCHAR(255) NULL;

-- username - Unique, NOT NULL
ALTER TABLE `users` 
MODIFY COLUMN `username` VARCHAR(100) NOT NULL;

-- phone_number - Unique, optional (already renamed above)

-- password_hash - NOT NULL (already renamed above)

-- role - ENUM
ALTER TABLE `users` 
MODIFY COLUMN `role` ENUM('SuperAdmin', 'Admin', 'Manager', 'Staff') NOT NULL DEFAULT 'Staff';

-- status - ENUM
ALTER TABLE `users` 
MODIFY COLUMN `status` ENUM('Active', 'Locked', 'Disabled') NOT NULL DEFAULT 'Active';

-- created_at - Timestamp
ALTER TABLE `users` 
MODIFY COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- updated_at - Timestamp on update
ALTER TABLE `users` 
MODIFY COLUMN `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;

-- STEP 4: ADD unique constraints
-- ─────────────────────────────────────────────────────────────

-- Remove old indexes
ALTER TABLE `users` DROP INDEX IF EXISTS `uk_username`;
ALTER TABLE `users` DROP INDEX IF EXISTS `idx_email`;
ALTER TABLE `users` DROP INDEX IF EXISTS `idx_phone`;

-- Add unique constraints
ALTER TABLE `users` ADD UNIQUE INDEX `uk_username` (`username`);
ALTER TABLE `users` ADD UNIQUE INDEX `uk_email` (`email`);
ALTER TABLE `users` ADD UNIQUE INDEX `uk_phone_number` (`phone_number`);

-- STEP 5: ADD foreign key constraint
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
-- FINAL STRUCTURE (ONLY 12 FIELDS):
-- ═══════════════════════════════════════════════════════════════
-- 1.  id              INT(11) PRIMARY KEY AUTO_INCREMENT
-- 2.  first_name      VARCHAR(100) NOT NULL
-- 3.  last_name       VARCHAR(100) NOT NULL
-- 4.  station_id      INT(11) NULL (FK → stations.id)
-- 5.  email           VARCHAR(255) NULL UNIQUE
-- 6.  username        VARCHAR(100) NOT NULL UNIQUE
-- 7.  phone_number    VARCHAR(20) NULL UNIQUE
-- 8.  password_hash   VARCHAR(255) NOT NULL
-- 9.  role            ENUM('SuperAdmin','Admin','Manager','Staff')
-- 10. status          ENUM('Active','Locked','Disabled')
-- 11. created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
-- 12. updated_at      TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
-- ═══════════════════════════════════════════════════════════════

-- Verify final structure:
DESCRIBE `users`;
