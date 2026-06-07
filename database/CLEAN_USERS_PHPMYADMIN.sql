-- ═══════════════════════════════════════════════════════════════
-- CLEAN USERS TABLE - SAFE FOR PHPMYADMIN
-- Copy and paste the ENTIRE script in phpMyAdmin SQL tab
-- Click "Go" once
-- ═══════════════════════════════════════════════════════════════

-- Rename columns
ALTER TABLE `users` CHANGE COLUMN `phone` `phone_number` VARCHAR(20) NULL;
ALTER TABLE `users` CHANGE COLUMN `password` `password_hash` VARCHAR(255) NOT NULL;

-- Update field types
ALTER TABLE `users` MODIFY COLUMN `first_name` VARCHAR(100) NOT NULL;
ALTER TABLE `users` MODIFY COLUMN `last_name` VARCHAR(100) NOT NULL;
ALTER TABLE `users` MODIFY COLUMN `username` VARCHAR(100) NOT NULL;
ALTER TABLE `users` MODIFY COLUMN `role` ENUM('SuperAdmin', 'Admin', 'Manager', 'Staff') NOT NULL DEFAULT 'Staff';
ALTER TABLE `users` MODIFY COLUMN `status` ENUM('Active', 'Locked', 'Disabled') NOT NULL DEFAULT 'Active';
ALTER TABLE `users` MODIFY COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- ═══════════════════════════════════════════════════════════════
-- DONE! Final structure matches requirements.
-- ═══════════════════════════════════════════════════════════════
