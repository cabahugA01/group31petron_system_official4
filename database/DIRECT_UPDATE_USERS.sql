-- ═══════════════════════════════════════════════════════════════
-- DIRECT UPDATE: Users Table Structure
-- Run this in phpMyAdmin SQL tab
-- Database: petron_pos_db_secure
-- ═══════════════════════════════════════════════════════════════

-- STEP 1: Add missing columns
-- ─────────────────────────────────────────────────────────────

-- Add status column
ALTER TABLE `users` 
ADD COLUMN `status` ENUM('Active', 'Locked', 'Disabled') NOT NULL DEFAULT 'Active' 
AFTER `role`;

-- Add created_at column
ALTER TABLE `users` 
ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP 
AFTER `status`;

-- Add updated_at column
ALTER TABLE `users` 
ADD COLUMN `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP 
AFTER `created_at`;


-- STEP 2: Rename columns
-- ─────────────────────────────────────────────────────────────

-- Rename 'phone' to 'phone_number'
ALTER TABLE `users` 
CHANGE COLUMN `phone` `phone_number` VARCHAR(20) NULL;

-- Rename 'password' to 'password_hash'
ALTER TABLE `users` 
CHANGE COLUMN `password` `password_hash` VARCHAR(255) NOT NULL;


-- STEP 3: Update data types
-- ─────────────────────────────────────────────────────────────

-- Ensure first_name is NOT NULL
ALTER TABLE `users` 
MODIFY COLUMN `first_name` VARCHAR(100) NOT NULL;

-- Ensure last_name is NOT NULL
ALTER TABLE `users` 
MODIFY COLUMN `last_name` VARCHAR(100) NOT NULL;

-- Update role to ENUM
ALTER TABLE `users` 
MODIFY COLUMN `role` ENUM('SuperAdmin', 'Admin', 'Manager', 'Staff') NOT NULL DEFAULT 'Staff';


-- STEP 4: Add indexes for performance
-- ─────────────────────────────────────────────────────────────

-- Index on email (if doesn't exist)
ALTER TABLE `users` 
ADD INDEX `idx_email` (`email`);

-- Index on phone_number
ALTER TABLE `users` 
ADD INDEX `idx_phone_number` (`phone_number`);

-- Index on status
ALTER TABLE `users` 
ADD INDEX `idx_status` (`status`);

-- Index on station_id
ALTER TABLE `users` 
ADD INDEX `idx_station_id` (`station_id`);


-- ═══════════════════════════════════════════════════════════════
-- DONE! Verify with: DESCRIBE users;
-- ═══════════════════════════════════════════════════════════════
