-- ============================================
-- Users Table Migration to Proper Schema
-- ============================================

-- Step 1: Rename old table as backup
ALTER TABLE `users` RENAME TO `users_backup_old`;

-- Step 2: Create new properly structured users table
CREATE TABLE `users` (
  `user_id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `station_id` INT(11) DEFAULT NULL,
  `email` VARCHAR(255) DEFAULT NULL UNIQUE,
  `username` VARCHAR(100) DEFAULT NULL UNIQUE,
  `phone_number` VARCHAR(15) DEFAULT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('SuperAdmin', 'Admin', 'Manager', 'Staff') NOT NULL DEFAULT 'Staff',
  `status` ENUM('Active', 'Locked', 'Disabled') NOT NULL DEFAULT 'Active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_deleted` TINYINT(1) DEFAULT 0,
  INDEX `idx_email` (`email`),
  INDEX `idx_username` (`username`),
  INDEX `idx_phone` (`phone_number`),
  INDEX `idx_station` (`station_id`),
  INDEX `idx_role` (`role`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step 3: Migrate data from old table to new table
INSERT INTO `users` (
    `user_id`,
    `first_name`,
    `last_name`,
    `station_id`,
    `email`,
    `username`,
    `phone_number`,
    `password_hash`,
    `role`,
    `status`,
    `created_at`,
    `updated_at`,
    `is_deleted`
)
SELECT 
    `id` AS `user_id`,
    COALESCE(`first_name`, 'Unknown') AS `first_name`,
    COALESCE(`last_name`, 'User') AS `last_name`,
    `station_id`,
    `email`,
    `username`,
    `phone` AS `phone_number`,
    `password` AS `password_hash`,
    CASE 
        WHEN LOWER(`role`) = 'superadmin' THEN 'SuperAdmin'
        WHEN LOWER(`role`) = 'admin' THEN 'Admin'
        WHEN LOWER(`role`) = 'manager' THEN 'Manager'
        WHEN LOWER(`role`) = 'staff' THEN 'Staff'
        ELSE 'Staff'
    END AS `role`,
    CASE 
        WHEN LOWER(`status`) = 'active' THEN 'Active'
        WHEN LOWER(`status`) = 'inactive' THEN 'Disabled'
        WHEN LOWER(`status`) = 'locked' THEN 'Locked'
        ELSE 'Active'
    END AS `status`,
    COALESCE(`created_at`, CURRENT_TIMESTAMP) AS `created_at`,
    COALESCE(`updated_at`, CURRENT_TIMESTAMP) AS `updated_at`,
    COALESCE(`is_deleted`, 0) AS `is_deleted`
FROM `users_backup_old`;

-- Step 4: Verify migration
SELECT 
    'Migration Summary' AS info,
    (SELECT COUNT(*) FROM users_backup_old) AS old_count,
    (SELECT COUNT(*) FROM users) AS new_count;

-- Show migrated users
SELECT user_id, first_name, last_name, email, username, phone_number, role, status 
FROM users 
ORDER BY user_id;
