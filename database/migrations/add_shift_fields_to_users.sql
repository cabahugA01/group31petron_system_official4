-- Migration: Add Shift Assignment Fields to Users Table
-- Purpose: Support shift-based staff management without creating separate roles
-- Date: 2026-06-27

-- Add shift-related columns to users table
ALTER TABLE `users`
ADD COLUMN `assigned_shift` enum('Shift 1','Shift 2','Shift 3','All Shifts') DEFAULT NULL COMMENT 'Assigned shift for staff members' AFTER `role`,
ADD COLUMN `shift_start_time` time DEFAULT NULL COMMENT 'Shift start time (e.g., 06:00:00)' AFTER `assigned_shift`,
ADD COLUMN `shift_end_time` time DEFAULT NULL COMMENT 'Shift end time (e.g., 14:00:00)' AFTER `shift_start_time`;

-- Add index for shift-based queries
ALTER TABLE `users`
ADD INDEX `idx_assigned_shift` (`assigned_shift`);

-- Update existing Manager accounts to have 'All Shifts' access
UPDATE `users` 
SET `assigned_shift` = 'All Shifts' 
WHERE `role` = 'manager';

-- Update existing Admin accounts to have 'All Shifts' access
UPDATE `users` 
SET `assigned_shift` = 'All Shifts' 
WHERE `role` = 'admin';

-- Leave staff accounts with NULL shift assignment (to be assigned by Admin)

-- Add comment to table
ALTER TABLE `users` COMMENT = 'User accounts with role-based access control and shift assignment for staff';
