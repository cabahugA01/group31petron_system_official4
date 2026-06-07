-- ═══════════════════════════════════════════════════════════════
-- CLEAN USERS TABLE - COPY AND PASTE THIS IN PHPMYADMIN
-- Run in SQL tab, click GO
-- ═══════════════════════════════════════════════════════════════

-- DROP unnecessary columns
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

-- RENAME columns
ALTER TABLE `users` CHANGE COLUMN `phone` `phone_number` VARCHAR(20) NULL;
ALTER TABLE `users` CHANGE COLUMN `password` `password_hash` VARCHAR(255) NOT NULL;

-- UPDATE field types
ALTER TABLE `users` MODIFY COLUMN `first_name` VARCHAR(100) NOT NULL;
ALTER TABLE `users` MODIFY COLUMN `last_name` VARCHAR(100) NOT NULL;
ALTER TABLE `users` MODIFY COLUMN `username` VARCHAR(100) NOT NULL;
ALTER TABLE `users` MODIFY COLUMN `role` ENUM('SuperAdmin', 'Admin', 'Manager', 'Staff') NOT NULL DEFAULT 'Staff';
ALTER TABLE `users` MODIFY COLUMN `status` ENUM('Active', 'Locked', 'Disabled') NOT NULL DEFAULT 'Active';
ALTER TABLE `users` MODIFY COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- ═══════════════════════════════════════════════════════════════
-- DONE! You now have ONLY 12 fields as required!
-- ═══════════════════════════════════════════════════════════════
