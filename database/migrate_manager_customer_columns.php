<?php
/**
 * Migration: Add Manager-level Customer Columns
 * Adds outstanding_balance, verification columns, and fleet details.
 */

require_once __DIR__ . '/../public/db_connect.php';

echo "<h1>Migrating Customers Table...</h1>";

try {  $columnsToAdd = [  'company_name' => "VARCHAR(255) NULL DEFAULT NULL AFTER customer_type",  'company_address' => "TEXT NULL DEFAULT NULL AFTER company_name",  'company_contact_person' => "VARCHAR(255) NULL DEFAULT NULL AFTER company_address",  'company_contact_number' => "VARCHAR(20) NULL DEFAULT NULL AFTER company_contact_person",  'verification_status' => "ENUM('pending', 'verified', 'rejected') NOT NULL DEFAULT 'pending' AFTER company_contact_number",  'verified_by' => "INT(11) UNSIGNED NULL DEFAULT NULL AFTER verification_status",  'verified_at' => "DATETIME NULL DEFAULT NULL AFTER verified_by",  'verification_remarks' => "TEXT NULL DEFAULT NULL AFTER verified_at",  'outstanding_balance' => "DECIMAL(15, 2) NOT NULL DEFAULT 0.00 AFTER verification_remarks",  'credit_limit' => "DECIMAL(15, 2) NOT NULL DEFAULT 0.00 AFTER outstanding_balance"  ];  foreach ($columnsToAdd as $colName => $colDef) {  $stmt = $pdo->query("SHOW COLUMNS FROM customers LIKE '$colName'");  if ($stmt->rowCount() === 0) {  echo "<p>Adding column <strong>$colName</strong>...</p>";  $pdo->exec("ALTER TABLE customers ADD COLUMN `$colName` $colDef");  echo "<p style='color:green;'>Column <strong>$colName</strong> added.</p>";  } else {  echo "<p style='color:blue;'>ℹ️ Column <strong>$colName</strong> already exists.</p>";  }  }  // Add indexes for optimization  $indexes = [  'idx_verification_status' => 'verification_status',  'idx_outstanding_balance' => 'outstanding_balance'  ];  foreach ($indexes as $idxName => $col) {  try {  $pdo->exec("ALTER TABLE customers ADD INDEX `$idxName` (`$col`)");  echo "<p style='color:green;'>Index <strong>$idxName</strong> added.</p>";  } catch (Exception $e) {  echo "<p style='color:blue;'>ℹ️ Index <strong>$idxName</strong> may already exist or error: " . $e->getMessage() . "</p>";  }  }  echo "<h2 style='color:green;'> Migration complete!</h2>";

} catch (Exception $e) {  echo "<div style='color:red; background:#fee2e2; padding:20px; border:1px solid #dc2626;'>";  echo "<h3>Error:</h3>";  echo "<p>" . $e->getMessage() . "</p>";  echo "</div>";
}
?>
