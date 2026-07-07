<?php
// Master migration script - runs all missing migrations that were applied after the original SQL dump
require_once __DIR__ . '/public/db_connect.php';

$errors = [];
$success = [];

function run_sql($pdo, $sql, $label, &$success, &$errors) {  try {  $pdo->exec($sql);  $success[] = $label;  } catch (Exception $e) {  // Ignore "already exists" errors  $msg = $e->getMessage();  if (strpos($msg, 'already exists') !== false || strpos($msg, 'Duplicate') !== false) {  $success[] = "$label (already exists)";  } else {  $errors[] = "$label: " . $msg;  }  }
}

// ═══ pending_price_approvals table ═══════════════════════════════════════════
run_sql($pdo, "CREATE TABLE IF NOT EXISTS pending_price_approvals (  id INT AUTO_INCREMENT PRIMARY KEY,  product_id INT DEFAULT NULL,  product_type ENUM('fuel','merchandise','service') NOT NULL,  product_name VARCHAR(255) NOT NULL,  field_name VARCHAR(100) NOT NULL,  old_value DECIMAL(12,2) DEFAULT NULL,  new_value DECIMAL(12,2) NOT NULL,  requested_by INT NOT NULL,  station_id INT NOT NULL,  status ENUM('pending','approved','rejected') DEFAULT 'pending',  reviewed_by INT DEFAULT NULL,  reviewed_at DATETIME DEFAULT NULL,  reviewer_notes TEXT DEFAULT NULL,  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,  fuel_type_id INT DEFAULT NULL,  service_type_id INT DEFAULT NULL,  INDEX idx_ppa_status (status),  INDEX idx_ppa_station (station_id),  INDEX idx_ppa_requested_by (requested_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"pending_price_approvals table", $success, $errors);

// ═══ pump_calibration_history table ══════════════════════════════════════════
run_sql($pdo, "CREATE TABLE IF NOT EXISTS pump_calibration_history (  id INT AUTO_INCREMENT PRIMARY KEY,  pump_id INT NOT NULL,  station_id INT NOT NULL,  calibration_value DECIMAL(10,4) NOT NULL,  previous_value DECIMAL(10,4) DEFAULT NULL,  reason TEXT DEFAULT NULL,  performed_by INT DEFAULT NULL,  performed_at DATETIME DEFAULT CURRENT_TIMESTAMP,  INDEX idx_pch_pump (pump_id),  INDEX idx_pch_station (station_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"pump_calibration_history table", $success, $errors);

// ═══ service_fees_base table ══════════════════════════════════════════════════
run_sql($pdo, "CREATE TABLE IF NOT EXISTS service_fees_base (  id INT AUTO_INCREMENT PRIMARY KEY,  service_name VARCHAR(255) NOT NULL,  base_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,  category VARCHAR(100) DEFAULT NULL,  station_id INT DEFAULT NULL,  is_active TINYINT(1) DEFAULT 1,  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,  INDEX idx_sfb_station (station_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"service_fees_base table", $success, $errors);

// ═══ voided_transactions table ═══════════════════════════════════════════════
run_sql($pdo, "CREATE TABLE IF NOT EXISTS voided_transactions (  id INT AUTO_INCREMENT PRIMARY KEY,  transaction_id VARCHAR(50) NOT NULL,  transaction_type ENUM('job_order','merchandise','combined') NOT NULL,  customer_name VARCHAR(255) DEFAULT NULL,  amount DECIMAL(10,2) NOT NULL,  void_reason VARCHAR(255) NOT NULL,  manager_remarks TEXT DEFAULT NULL,  voided_by INT NOT NULL,  void_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,  station_id INT NOT NULL,  fields_changed JSON DEFAULT NULL,  merchandise_txn_id INT DEFAULT NULL,  job_order_no VARCHAR(100) DEFAULT NULL,  vehicle_plate VARCHAR(50) DEFAULT NULL,  payment_method VARCHAR(50) DEFAULT NULL,  voided_by_name VARCHAR(255) DEFAULT NULL,  INDEX idx_vt_date (void_date),  INDEX idx_vt_station (station_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"voided_transactions table", $success, $errors);

// ═══ transaction_adjustments table ═══════════════════════════════════════════
run_sql($pdo, "CREATE TABLE IF NOT EXISTS transaction_adjustments (  id INT AUTO_INCREMENT PRIMARY KEY,  transaction_id VARCHAR(50) NOT NULL,  transaction_type ENUM('job_order','merchandise','combined') NOT NULL DEFAULT 'merchandise',  customer_name VARCHAR(255) DEFAULT NULL,  original_amount DECIMAL(10,2) NOT NULL,  updated_amount DECIMAL(10,2) NOT NULL,  amount_difference DECIMAL(10,2) NOT NULL,  adjustment_reason VARCHAR(255) NOT NULL,  manager_remarks TEXT DEFAULT NULL,  adjusted_by INT NOT NULL,  adjustment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,  station_id INT NOT NULL,  fields_changed JSON DEFAULT NULL,  INDEX idx_adj_txn (transaction_id),  INDEX idx_adj_date (adjustment_date),  INDEX idx_adj_station (station_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"transaction_adjustments table", $success, $errors);

// ═══ ui_config table ══════════════════════════════════════════════════════════
run_sql($pdo, "CREATE TABLE IF NOT EXISTS ui_config (  id INT AUTO_INCREMENT PRIMARY KEY,  config_key VARCHAR(100) NOT NULL UNIQUE,  config_value TEXT DEFAULT NULL,  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"ui_config table", $success, $errors);

// ═══ audit_trail table (with source_table column) ════════════════════════════
run_sql($pdo, "CREATE TABLE IF NOT EXISTS audit_trail (  id INT AUTO_INCREMENT PRIMARY KEY,  transaction_id VARCHAR(255) NOT NULL,  manager_id INT NOT NULL,  action_type VARCHAR(60) NOT NULL,  new_value TEXT DEFAULT NULL,  old_value TEXT DEFAULT NULL,  station_id INT NOT NULL DEFAULT 0,  entity_type VARCHAR(60) DEFAULT NULL,  source_table VARCHAR(100) DEFAULT NULL,  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,  INDEX idx_txn (transaction_id),  INDEX idx_mgr (manager_id),  INDEX idx_ts (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"audit_trail table", $success, $errors);

// ═══ Add missing columns ══════════════════════════════════════════════════════
$alter_stmts = [  // users  ["ALTER TABLE users ADD COLUMN IF NOT EXISTS employee_id VARCHAR(20) DEFAULT NULL COMMENT 'Employee ID'", "users.employee_id"],  ["ALTER TABLE users ADD COLUMN IF NOT EXISTS assigned_shift ENUM('Shift 1','Shift 2','Shift 3','All Shifts') DEFAULT NULL", "users.assigned_shift"],  ["ALTER TABLE users ADD COLUMN IF NOT EXISTS shift_start_time TIME DEFAULT NULL", "users.shift_start_time"],  ["ALTER TABLE users ADD COLUMN IF NOT EXISTS shift_end_time TIME DEFAULT NULL", "users.shift_end_time"],  // merchandise_transactions  ["ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS payment_status VARCHAR(60) DEFAULT 'Unpaid'", "merchandise_transactions.payment_status"],  ["ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS workflow_status VARCHAR(60) DEFAULT 'Pending'", "merchandise_transactions.workflow_status"],  ["ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS manager_notes TEXT DEFAULT NULL", "merchandise_transactions.manager_notes"],  ["ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS manager_remarks TEXT DEFAULT NULL", "merchandise_transactions.manager_remarks"],  ["ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS inventory_deducted TINYINT(1) DEFAULT 1", "merchandise_transactions.inventory_deducted"],  ["ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS void_reason TEXT DEFAULT NULL", "merchandise_transactions.void_reason"],  ["ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS adjustment_reason TEXT DEFAULT NULL", "merchandise_transactions.adjustment_reason"],  // inventory_products  ["ALTER TABLE inventory_products ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'active'", "inventory_products.status"],  ["ALTER TABLE inventory_products ADD COLUMN IF NOT EXISTS min_stock INT NOT NULL DEFAULT 0", "inventory_products.min_stock"],  ["ALTER TABLE inventory_products ADD COLUMN IF NOT EXISTS max_stock INT NOT NULL DEFAULT 0", "inventory_products.max_stock"],  ["ALTER TABLE inventory_products ADD COLUMN IF NOT EXISTS station_id INT NOT NULL DEFAULT 1", "inventory_products.station_id"],  ["ALTER TABLE inventory_products ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP", "inventory_products.updated_at"],  // fuel_transactions  ["ALTER TABLE fuel_transactions ADD COLUMN IF NOT EXISTS manager_id INT DEFAULT NULL", "fuel_transactions.manager_id"],  ["ALTER TABLE fuel_transactions ADD COLUMN IF NOT EXISTS reject_reason TEXT DEFAULT NULL", "fuel_transactions.reject_reason"],  // audit_trail  ["ALTER TABLE audit_trail ADD COLUMN IF NOT EXISTS source_table VARCHAR(100) DEFAULT NULL", "audit_trail.source_table"],  // job_orders  ["ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS validated_by INT DEFAULT NULL", "job_orders.validated_by"],  ["ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS validated_at DATETIME DEFAULT NULL", "job_orders.validated_at"],  ["ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS adjustment_reason TEXT DEFAULT NULL", "job_orders.adjustment_reason"],  // customers  ["ALTER TABLE customers ADD COLUMN IF NOT EXISTS gov_id_image VARCHAR(500) DEFAULT NULL", "customers.gov_id_image"],  ["ALTER TABLE customers ADD COLUMN IF NOT EXISTS cr_document VARCHAR(500) DEFAULT NULL", "customers.cr_document"],  ["ALTER TABLE customers ADD COLUMN IF NOT EXISTS verification_status VARCHAR(50) DEFAULT 'pending'", "customers.verification_status"],  ["ALTER TABLE customers ADD COLUMN IF NOT EXISTS verified_by INT DEFAULT NULL", "customers.verified_by"],  ["ALTER TABLE customers ADD COLUMN IF NOT EXISTS verified_at DATETIME DEFAULT NULL", "customers.verified_at"],
];

foreach ($alter_stmts as [$sql, $label]) {  run_sql($pdo, $sql, $label, $success, $errors);
}

// ═══ master_data_requests table from sql folder ═══════════════════════════════
run_sql($pdo, "CREATE TABLE IF NOT EXISTS master_data_requests (  id INT AUTO_INCREMENT PRIMARY KEY,  request_type VARCHAR(100) NOT NULL,  data_payload JSON NOT NULL,  station_id INT NOT NULL,  requested_by INT NOT NULL,  status ENUM('pending','approved','rejected','applied') DEFAULT 'pending',  reviewed_by INT DEFAULT NULL,  review_notes TEXT DEFAULT NULL,  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,  reviewed_at DATETIME DEFAULT NULL,  INDEX idx_mdr_station (station_id),  INDEX idx_mdr_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"master_data_requests table", $success, $errors);

echo "=== MIGRATION RESULTS ===\n";
echo "Success (" . count($success) . "):\n";
foreach ($success as $s) echo "  $s\n";
if ($errors) {  echo "\nErrors (" . count($errors) . "):\n";  foreach ($errors as $e) echo "  $e\n";
} else {  echo "\nAll migrations completed successfully!\n";
}
