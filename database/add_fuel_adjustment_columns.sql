-- Add missing columns to fuel_adjustments table
-- Run this SQL script to fix the "Undefined array key" warnings

ALTER TABLE `fuel_adjustments` 
ADD COLUMN `previous_value` DECIMAL(10,2) DEFAULT 0 AFTER `liters`,
ADD COLUMN `new_value` DECIMAL(10,2) DEFAULT 0 AFTER `previous_value`;

-- Update existing records to set previous_value and new_value based on liters
-- Note: This is a best-effort update for existing records
-- New value = previous_value + liters, so previous_value = new_value - liters
-- We'll set previous_value to 0 and new_value to liters for existing records
UPDATE `fuel_adjustments` 
SET `previous_value` = 0, 
    `new_value` = ABS(`liters`)
WHERE `previous_value` IS NULL OR `new_value` IS NULL;
