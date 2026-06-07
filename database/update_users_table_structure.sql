-- ═══════════════════════════════════════════════════════════════
-- UPDATE USERS TABLE TO MATCH STANDARD STRUCTURE
-- Date: June 5, 2026
-- ═══════════════════════════════════════════════════════════════
-- 
-- Required Structure:
-- - user_id (was: id)
-- - first_name
-- - last_name
-- - station_id
-- - email
-- - username
-- - phone_number (was: phone)
-- - password_hash (was: password)
-- - role
-- - status
-- - created_at
-- - updated_at
--
-- ═══════════════════════════════════════════════════════════════

-- Step 1: Add missing columns if they don't exist
-- ─────────────────────────────────────────────────────────────

-- Add status column if missing
ALTER TABLE `users` 
ADD COLUMN IF NOT EXISTS `status` ENUM('Active', 'Locked', 'Disabled') NOT NULL DEFAULT 'Active' 
AFTER `role`;

-- Add created_at if missing
ALTER TABLE `users` 
ADD COLUMN IF NOT EXISTS `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP 
AFTER `status`;

-- Add updated_at if missing
ALTER TABLE `users` 
ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP 
AFTER `created_at`;

-- Ensure station_id exists (it should already exist)
ALTER TABLE `users` 
ADD COLUMN IF NOT EXISTS `station_id` INT(11) NULL 
AFTER `last_name`;

-- Ensure email exists
ALTER TABLE `users` 
ADD COLUMN IF NOT EXISTS `email` VARCHAR(255) NULL UNIQUE 
AFTER `station_id`;

-- Ensure phone exists (we'll rename it later)
ALTER TABLE `users` 
ADD COLUMN IF NOT EXISTS `phone` VARCHAR(20) NULL UNIQUE 
AFTER `username`;


-- Step 2: Rename columns to match standard naming
-- ─────────────────────────────────────────────────────────────

-- Rename 'id' to 'user_id'
-- Note: MySQL doesn't support "IF EXISTS" for CHANGE COLUMN
-- We'll handle this in the PHP script

-- Check if 'id' column exists and rename it
-- This will be done via PHP script for safety


-- Rename 'phone' to 'phone_number'
ALTER TABLE `users` 
CHANGE COLUMN `phone` `phone_number` VARCHAR(20) NULL UNIQUE;

-- Rename 'password' to 'password_hash'
ALTER TABLE `users` 
CHANGE COLUMN `password` `password_hash` VARCHAR(255) NOT NULL;


-- Step 3: Update data types and constraints
-- ─────────────────────────────────────────────────────────────

-- Ensure first_name and last_name are NOT NULL
ALTER TABLE `users` 
MODIFY COLUMN `first_name` VARCHAR(100) NOT NULL;

ALTER TABLE `users` 
MODIFY COLUMN `last_name` VARCHAR(100) NOT NULL;

-- Ensure username is unique
ALTER TABLE `users` 
MODIFY COLUMN `username` VARCHAR(100) NOT NULL UNIQUE;

-- Ensure email is unique (if exists)
ALTER TABLE `users` 
MODIFY COLUMN `email` VARCHAR(255) NULL UNIQUE;

-- Ensure phone_number is unique
ALTER TABLE `users` 
MODIFY COLUMN `phone_number` VARCHAR(20) NULL UNIQUE;

-- Ensure role is ENUM
ALTER TABLE `users` 
MODIFY COLUMN `role` ENUM('SuperAdmin', 'Admin', 'Manager', 'Staff') NOT NULL DEFAULT 'Staff';


-- Step 4: Add indexes for performance
-- ─────────────────────────────────────────────────────────────

-- Index on email for login lookup
ALTER TABLE `users` 
ADD INDEX IF NOT EXISTS `idx_email` (`email`);

-- Index on phone_number for login lookup
ALTER TABLE `users` 
ADD INDEX IF NOT EXISTS `idx_phone_number` (`phone_number`);

-- Index on status for filtering
ALTER TABLE `users` 
ADD INDEX IF NOT EXISTS `idx_status` (`status`);

-- Index on station_id for filtering
ALTER TABLE `users` 
ADD INDEX IF NOT EXISTS `idx_station_id` (`station_id`);


-- Step 5: Add foreign key constraints
-- ─────────────────────────────────────────────────────────────

-- Foreign key to stations table (if not exists)
ALTER TABLE `users` 
ADD CONSTRAINT IF NOT EXISTS `fk_users_station_id` 
FOREIGN KEY (`station_id`) REFERENCES `stations`(`id`) 
ON DELETE SET NULL 
ON UPDATE CASCADE;


-- ═══════════════════════════════════════════════════════════════
-- VERIFICATION QUERIES
-- ═══════════════════════════════════════════════════════════════

-- After running this script, verify with:
-- DESCRIBE users;
-- SHOW CREATE TABLE users;

-- Expected structure:
-- user_id          INT(11) AUTO_INCREMENT PRIMARY KEY
-- first_name       VARCHAR(100) NOT NULL
-- last_name        VARCHAR(100) NOT NULL
-- station_id       INT(11) NULL
-- email            VARCHAR(255) NULL UNIQUE
-- username         VARCHAR(100) NOT NULL UNIQUE
-- phone_number     VARCHAR(20) NULL UNIQUE
-- password_hash    VARCHAR(255) NOT NULL
-- role             ENUM('SuperAdmin','Admin','Manager','Staff') NOT NULL
-- status           ENUM('Active','Locked','Disabled') NOT NULL
-- created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
-- updated_at       TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP

-- ═══════════════════════════════════════════════════════════════
