-- ============================================================
-- FINALIZED TRANSACTION MODULE - DATABASE SCHEMA UPDATES
-- Ensures all required columns and tables exist
-- ============================================================

-- Add missing columns to merchandise_transactions
ALTER TABLE `merchandise_transactions` 
ADD COLUMN IF NOT EXISTS `customer_first_name` VARCHAR(100) DEFAULT NULL COMMENT 'Customer first name',
ADD COLUMN IF NOT EXISTS `customer_last_name` VARCHAR(100) DEFAULT NULL COMMENT 'Customer last name',
ADD COLUMN IF NOT EXISTS `contact_number` VARCHAR(50) DEFAULT NULL COMMENT 'Customer contact number',
ADD COLUMN IF NOT EXISTS `address` TEXT DEFAULT NULL COMMENT 'Customer address',
ADD COLUMN IF NOT EXISTS `vehicle_plate` VARCHAR(20) DEFAULT NULL COMMENT 'Vehicle plate number',
ADD COLUMN IF NOT EXISTS `vehicle_type` VARCHAR(50) DEFAULT NULL COMMENT 'Vehicle type',
ADD COLUMN IF NOT EXISTS `vehicle_brand` VARCHAR(100) DEFAULT NULL COMMENT 'Vehicle brand',
ADD COLUMN IF NOT EXISTS `vehicle_model` VARCHAR(100) DEFAULT NULL COMMENT 'Vehicle model',
ADD COLUMN IF NOT EXISTS `staff_remarks` TEXT DEFAULT NULL COMMENT 'Staff-entered notes',
ADD COLUMN IF NOT EXISTS `manager_notes` TEXT DEFAULT NULL COMMENT 'Manager validation notes',
ADD COLUMN IF NOT EXISTS `due_date` DATE DEFAULT NULL COMMENT 'Payment due date',
ADD COLUMN IF NOT EXISTS `inventory_deducted` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Inventory deduction flag';

-- Add missing columns to job_orders
ALTER TABLE `job_orders` 
ADD COLUMN IF NOT EXISTS `customer_first_name` VARCHAR(100) DEFAULT NULL COMMENT 'Customer first name',
ADD COLUMN IF NOT EXISTS `customer_last_name` VARCHAR(100) DEFAULT NULL COMMENT 'Customer last name',
ADD COLUMN IF NOT EXISTS `vehicle_brand` VARCHAR(100) DEFAULT NULL COMMENT 'Vehicle brand',
ADD COLUMN IF NOT EXISTS `vehicle_model` VARCHAR(100) DEFAULT NULL COMMENT 'Vehicle model',
ADD COLUMN IF NOT EXISTS `service_category` VARCHAR(100) DEFAULT NULL COMMENT 'Service category',
ADD COLUMN IF NOT EXISTS `assigned_technician` INT(11) DEFAULT NULL COMMENT 'Assigned technician ID',
ADD COLUMN IF NOT EXISTS `labor_cost` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Labor cost',
ADD COLUMN IF NOT EXISTS `due_date` DATE DEFAULT NULL COMMENT 'Payment due date',
ADD COLUMN IF NOT EXISTS `balance_due` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Outstanding balance',
ADD COLUMN IF NOT EXISTS `shift_period` VARCHAR(50) DEFAULT NULL COMMENT 'Shift period',
ADD COLUMN IF NOT EXISTS `shift_name` VARCHAR(100) DEFAULT NULL COMMENT 'Shift name',
ADD COLUMN IF NOT EXISTS `shift_id` INT(11) DEFAULT NULL COMMENT 'Shift session ID';

-- Create merchandise_transaction_items table if not exists
CREATE TABLE IF NOT EXISTS `merchandise_transaction_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `transaction_id` INT(11) NOT NULL COMMENT 'FK to merchandise_transactions.id',
  `product_id` INT(11) NOT NULL COMMENT 'FK to inventory_products.id',
  `sku` VARCHAR(200) DEFAULT NULL COMMENT 'Product SKU',
  `product_name` VARCHAR(255) NOT NULL COMMENT 'Product name',
  `category` VARCHAR(100) DEFAULT 'General' COMMENT 'Product category',
  `quantity` INT(11) NOT NULL COMMENT 'Quantity sold',
  `unit_price` DECIMAL(10,2) NOT NULL COMMENT 'Unit price',
  `line_total` DECIMAL(10,2) NOT NULL COMMENT 'Line total (qty * price)',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_transaction_id` (`transaction_id`),
  KEY `idx_product_id` (`product_id`),
  CONSTRAINT `fk_mti_transaction` FOREIGN KEY (`transaction_id`) 
    REFERENCES `merchandise_transactions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mti_product` FOREIGN KEY (`product_id`) 
    REFERENCES `inventory_products` (`id`) ON DELETE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Merchandise transaction line items';


-- Create inventory_movement_log table if not exists
CREATE TABLE IF NOT EXISTS `inventory_movement_log` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `station_id` INT(11) NOT NULL COMMENT 'FK to stations.id',
  `product_id` INT(11) NOT NULL COMMENT 'FK to inventory_products.id',
  `movement_type` VARCHAR(50) NOT NULL COMMENT 'sale, purchase, adjustment, return',
  `quantity` INT(11) NOT NULL COMMENT 'Quantity (negative for deductions)',
  `reference_type` VARCHAR(50) DEFAULT NULL COMMENT 'transaction, delivery, adjustment',
  `reference_id` INT(11) DEFAULT NULL COMMENT 'Reference record ID',
  `performed_by` INT(11) NOT NULL COMMENT 'User who performed action',
  `notes` TEXT DEFAULT NULL COMMENT 'Additional notes',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_station_product` (`station_id`, `product_id`),
  KEY `idx_reference` (`reference_type`, `reference_id`),
  KEY `idx_performed_by` (`performed_by`),
  CONSTRAINT `fk_iml_station` FOREIGN KEY (`station_id`) 
    REFERENCES `stations` (`id`) ON DELETE NO ACTION,
  CONSTRAINT `fk_iml_product` FOREIGN KEY (`product_id`) 
    REFERENCES `inventory_products` (`id`) ON DELETE NO ACTION,
  CONSTRAINT `fk_iml_user` FOREIGN KEY (`performed_by`) 
    REFERENCES `users` (`id`) ON DELETE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Inventory movement tracking';

-- Create calendar_events table if not exists
CREATE TABLE IF NOT EXISTS `calendar_events` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `station_id` INT(11) NOT NULL COMMENT 'FK to stations.id',
  `event_type` VARCHAR(50) NOT NULL COMMENT 'transaction, job_order, shift, delivery',
  `event_title` VARCHAR(255) NOT NULL COMMENT 'Event title',
  `event_description` TEXT DEFAULT NULL COMMENT 'Event description',
  `event_date` DATE NOT NULL COMMENT 'Event date',
  `event_color` VARCHAR(20) DEFAULT 'blue' COMMENT 'Calendar color',
  `created_by` INT(11) NOT NULL COMMENT 'User who created event',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_station_date` (`station_id`, `event_date`),
  KEY `idx_event_type` (`event_type`),
  CONSTRAINT `fk_ce_station` FOREIGN KEY (`station_id`) 
    REFERENCES `stations` (`id`) ON DELETE NO ACTION,
  CONSTRAINT `fk_ce_user` FOREIGN KEY (`created_by`) 
    REFERENCES `users` (`id`) ON DELETE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Calendar events auto-logging';

-- Create variance_reports table if not exists
CREATE TABLE IF NOT EXISTS `variance_reports` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `station_id` INT(11) NOT NULL,
  `report_date` DATE NOT NULL,
  `variance_type` VARCHAR(50) NOT NULL COMMENT 'inventory, transaction, fuel',
  `entity_type` VARCHAR(50) NOT NULL COMMENT 'merchandise_transactions, fuel_transactions',
  `entity_id` INT(11) DEFAULT NULL,
  `expected_value` DECIMAL(12,2) NOT NULL,
  `actual_value` DECIMAL(12,2) NOT NULL,
  `variance_amount` DECIMAL(12,2) NOT NULL,
  `variance_percent` DECIMAL(5,2) NOT NULL,
  `status` ENUM('Open','Under Investigation','Resolved') DEFAULT 'Open',
  `flagged_by` INT(11) DEFAULT NULL,
  `resolved_by` INT(11) DEFAULT NULL,
  `resolution_notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_station_date` (`station_id`, `report_date`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Variance tracking for admin oversight';

-- Create compliance_notes table if not exists
CREATE TABLE IF NOT EXISTS `compliance_notes` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `station_id` INT(11) NOT NULL,
  `entity_type` VARCHAR(50) NOT NULL COMMENT 'transaction, inventory, staff',
  `entity_id` INT(11) NOT NULL,
  `note_type` VARCHAR(50) NOT NULL COMMENT 'compliance, regulatory, policy',
  `note_text` TEXT NOT NULL,
  `severity` ENUM('low','medium','high','critical') DEFAULT 'low',
  `created_by` INT(11) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_station_entity` (`station_id`, `entity_type`, `entity_id`),
  KEY `idx_severity` (`severity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Admin compliance notes';

-- Ensure audit_logs has all required columns
ALTER TABLE `audit_logs` 
ADD COLUMN IF NOT EXISTS `old_values` TEXT DEFAULT NULL COMMENT 'Before values (JSON)',
ADD COLUMN IF NOT EXISTS `new_values` TEXT DEFAULT NULL COMMENT 'After values (JSON)',
ADD COLUMN IF NOT EXISTS `ip_address` VARCHAR(45) DEFAULT NULL COMMENT 'IP address',
ADD COLUMN IF NOT EXISTS `user_agent` TEXT DEFAULT NULL COMMENT 'User agent string';

-- Add indexes for performance
CREATE INDEX IF NOT EXISTS `idx_mt_validation_status` ON `merchandise_transactions` (`validation_status`);
CREATE INDEX IF NOT EXISTS `idx_mt_shift` ON `merchandise_transactions` (`shift_period`, `shift_id`);
CREATE INDEX IF NOT EXISTS `idx_mt_transaction_date` ON `merchandise_transactions` (`transaction_date`);
CREATE INDEX IF NOT EXISTS `idx_jo_status` ON `job_orders` (`status`);
CREATE INDEX IF NOT EXISTS `idx_jo_shift` ON `job_orders` (`shift_period`, `shift_id`);
CREATE INDEX IF NOT EXISTS `idx_audit_entity` ON `audit_logs` (`entity_type`, `entity_id`);
CREATE INDEX IF NOT EXISTS `idx_audit_user_date` ON `audit_logs` (`user_id`, `created_at`);

-- ============================================================
-- DATABASE SCHEMA UPDATES COMPLETE
-- ============================================================
