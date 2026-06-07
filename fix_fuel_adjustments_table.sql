-- Fix for missing fuel_adjustments table error
-- Creates the fuel_adjustments table if it doesn't exist

CREATE TABLE IF NOT EXISTS `fuel_adjustments` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `station_id` INT(11) NOT NULL,
    `fuel_type_id` INT(11) DEFAULT NULL,
    `fuel_type` VARCHAR(100) DEFAULT NULL,
    `adjustment_type` VARCHAR(100) NOT NULL,
    `liters` DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    `reason` VARCHAR(255) DEFAULT NULL,
    `user_id` INT(11) DEFAULT NULL,
    `adjustment_date` DATE NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_station` (`station_id`),
    INDEX `idx_fuel_type` (`fuel_type_id`),
    INDEX `idx_adjustment_date` (`adjustment_date`),
    INDEX `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Note: Run this SQL in your database to create the missing table
-- Or use the PHP bootstrap script below
