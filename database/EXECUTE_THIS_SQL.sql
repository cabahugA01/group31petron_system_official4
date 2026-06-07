-- ═══════════════════════════════════════════════════════════════
-- EXECUTE THIS IN PHPMYADMIN
-- Copy entire content and paste in SQL tab, then click GO
-- ═══════════════════════════════════════════════════════════════

-- Rename phone to phone_number
ALTER TABLE `users` 
CHANGE COLUMN `phone` `phone_number` VARCHAR(20) NULL;

-- Rename password to password_hash  
ALTER TABLE `users` 
CHANGE COLUMN `password` `password_hash` VARCHAR(255) NOT NULL;

-- Update role to proper ENUM
ALTER TABLE `users` 
MODIFY COLUMN `role` ENUM('SuperAdmin', 'Admin', 'Manager', 'Staff') NOT NULL DEFAULT 'Staff';

-- Update status to proper ENUM
ALTER TABLE `users` 
MODIFY COLUMN `status` ENUM('Active', 'Locked', 'Disabled') NOT NULL DEFAULT 'Active';

-- Ensure first_name is NOT NULL
ALTER TABLE `users` 
MODIFY COLUMN `first_name` VARCHAR(100) NOT NULL;

-- Ensure last_name is NOT NULL
ALTER TABLE `users` 
MODIFY COLUMN `last_name` VARCHAR(100) NOT NULL;

-- Ensure username is NOT NULL and UNIQUE
ALTER TABLE `users` 
MODIFY COLUMN `username` VARCHAR(100) NOT NULL UNIQUE;

-- ═══════════════════════════════════════════════════════════════
-- DONE! Your users table now has the correct structure:
-- 
-- ✓ id (primary key)
-- ✓ first_name
-- ✓ last_name  
-- ✓ station_id
-- ✓ email
-- ✓ username
-- ✓ phone_number (renamed from phone)
-- ✓ password_hash (renamed from password)
-- ✓ role (ENUM)
-- ✓ status (ENUM)
-- ✓ created_at
-- ✓ updated_at
-- ═══════════════════════════════════════════════════════════════
