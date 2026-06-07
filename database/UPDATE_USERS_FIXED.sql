-- ═══════════════════════════════════════════════════════════════
-- FIXED UPDATE: Users Table Structure
-- Run ONE statement at a time in phpMyAdmin
-- ═══════════════════════════════════════════════════════════════

-- STEP 1: Rename 'phone' to 'phone_number' (if exists)
ALTER TABLE `users` 
CHANGE COLUMN `phone` `phone_number` VARCHAR(20) NULL;

-- STEP 2: Rename 'password' to 'password_hash' (if exists)
ALTER TABLE `users` 
CHANGE COLUMN `password` `password_hash` VARCHAR(255) NOT NULL;

-- STEP 3: Update role to ENUM (if needed)
ALTER TABLE `users` 
MODIFY COLUMN `role` ENUM('SuperAdmin', 'Admin', 'Manager', 'Staff') NOT NULL DEFAULT 'Staff';

-- STEP 4: Update status to ENUM (if already exists)
ALTER TABLE `users` 
MODIFY COLUMN `status` ENUM('Active', 'Locked', 'Disabled') NOT NULL DEFAULT 'Active';

-- STEP 5: Add created_at if missing (skip if error)
ALTER TABLE `users` 
ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- STEP 6: Add updated_at if missing (skip if error)
ALTER TABLE `users` 
ADD COLUMN `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;

-- STEP 7: Add indexes (skip if already exist)
ALTER TABLE `users` ADD INDEX `idx_email` (`email`);
ALTER TABLE `users` ADD INDEX `idx_phone_number` (`phone_number`);
ALTER TABLE `users` ADD INDEX `idx_status` (`status`);
ALTER TABLE `users` ADD INDEX `idx_station_id` (`station_id`);

-- ═══════════════════════════════════════════════════════════════
-- DONE! Check structure with: DESCRIBE users;
-- ═══════════════════════════════════════════════════════════════
