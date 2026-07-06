<?php
/**
 * Admin Dashboard Data Integrity Audit
 * Tests every major query used in admin_dashboard.php
 */
require_once __DIR__ . '/../public/db_connect.php';

// Simulate admin session for station_id
$station_id = 1253; // admin station

function check(PDO $pdo, string $label, string $sql, array $params = []): void {  try {  $stmt = $pdo->prepare($sql);  $stmt->execute($params);  $result = $stmt->fetchColumn();  echo "[OK]  $label => " . var_export($result, true) . "\n";  } catch (Exception $e) {  echo "[FAIL] $label => " . $e->getMessage() . "\n";  }
}

function checkRows(PDO $pdo, string $label, string $sql, array $params = []): void {  try {  $stmt = $pdo->prepare($sql);  $stmt->execute($params);  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);  echo "[OK]  $label => " . count($rows) . " rows\n";  if ($rows) {  echo "  cols: " . implode(', ', array_keys($rows[0])) . "\n";  }  } catch (Exception $e) {  echo "[FAIL] $label => " . $e->getMessage() . "\n";  }
}

$date_filter = date('Y-m-d');
$s = $station_id;

echo "=== SUMMARY CARD QUERIES ===\n";

// Revenue & Transactions
check($pdo, 'fuel_count', "SELECT COUNT(*) FROM fuel_transactions WHERE station_id=? AND DATE(transaction_date)=?", [$s, $date_filter]);
check($pdo, 'merch_count', "SELECT COUNT(*) FROM merchandise_transactions WHERE station_id=? AND DATE(COALESCE(transaction_date, created_at))=?", [$s, $date_filter]);
check($pdo, 'service_count', "SELECT COUNT(*) FROM job_orders WHERE station_id=? AND DATE(created_at)=?", [$s, $date_filter]);
check($pdo, 'fuel_revenue', "SELECT COALESCE(SUM(total_amount),0) FROM fuel_transactions WHERE station_id=? AND DATE(transaction_date)=?", [$s, $date_filter]);
check($pdo, 'merch_revenue', "SELECT COALESCE(SUM(total_amount),0) FROM merchandise_transactions WHERE station_id=? AND DATE(COALESCE(transaction_date, created_at))=?", [$s, $date_filter]);
check($pdo, 'service_revenue', "SELECT COALESCE(SUM(COALESCE(total_cost, estimated_cost, 0)),0) FROM job_orders WHERE station_id=? AND DATE(created_at)=? AND LOWER(COALESCE(status,'')) IN ('completed','verified','finalized','released')", [$s, $date_filter]);

// Users
check($pdo, 'active_admins', "SELECT COUNT(*) FROM users WHERE station_id=? AND status='Active' AND LOWER(role)='admin'", [$s]);
check($pdo, 'active_managers', "SELECT COUNT(*) FROM users WHERE station_id=? AND status='Active' AND LOWER(role)='manager'", [$s]);
check($pdo, 'active_staff', "SELECT COUNT(*) FROM users WHERE station_id=? AND status='Active' AND LOWER(role)='staff'", [$s]);
check($pdo, 'pending_user_accounts', "SELECT COUNT(*) FROM users WHERE station_id=? AND LOWER(COALESCE(status,''))='pending'", [$s]);

// Customers
check($pdo, 'pending_customer_requests', "SELECT COUNT(*) FROM customers WHERE station_id=? AND LOWER(COALESCE(status,'active'))<>'inactive' AND (LOWER(COALESCE(verification_status,''))='pending' OR LOWER(COALESCE(mgr_status,''))='pending')", [$s]);

// Purchase Orders
check($pdo, 'pending_purchase_orders', "SELECT COUNT(*) FROM purchase_orders WHERE station_id=? AND (LOWER(COALESCE(status,'')) IN ('pending','pending approval','pending admin validation') OR (admin_finalized=0 AND LOWER(COALESCE(status,'')) NOT IN ('draft','rejected','cancelled','received')))", [$s]);
check($pdo, 'pending_fuel_purchase_orders', "SELECT COUNT(*) FROM fuel_purchase_orders WHERE station_id=? AND LOWER(COALESCE(status,'')) IN ('pending','pending approval','pending admin validation')", [$s]);
check($pdo, 'pending_price_requests', "SELECT COUNT(*) FROM pending_price_approvals WHERE station_id=? AND LOWER(status)='pending'", [$s]);

// Inventory Alerts
check($pdo, 'fuel_total_count', "SELECT COUNT(*) FROM fuel_inventory WHERE station_id=?", [$s]);
check($pdo, 'fuel_critical_count', "SELECT COUNT(*) FROM fuel_inventory WHERE station_id=? AND COALESCE(current_level, current_stock, 0)<=COALESCE(critical_level,0)", [$s]);
check($pdo, 'fuel_low_count', "SELECT COUNT(*) FROM fuel_inventory WHERE station_id=? AND COALESCE(current_level,current_stock,0)>COALESCE(critical_level,0) AND COALESCE(current_level,current_stock,0)<=COALESCE(reorder_level,0)", [$s]);
check($pdo, 'merch_total_count', "SELECT COUNT(*) FROM station_inventory WHERE station_id=? AND status='active'", [$s]);
check($pdo, 'merch_critical_count', "SELECT COUNT(*) FROM station_inventory WHERE station_id=? AND status='active' AND COALESCE(stock_level,0)<=0", [$s]);
check($pdo, 'merch_low_count', "SELECT COUNT(*) FROM station_inventory WHERE station_id=? AND status='active' AND COALESCE(stock_level,0)>0 AND COALESCE(stock_level,0)<=COALESCE(reorder_level,0)", [$s]);

// Deliveries
check($pdo, 'pending_deliveries_oversight', "SELECT COUNT(*) FROM deliveries_oversight WHERE station_id=? AND LOWER(COALESCE(status,'')) IN ('pending manager approval','pending validation','pending verification','pending manager confirmation','pending admin oversight','discrepancy','flagged')", [$s]);
check($pdo, 'pending_purchase_orders_delivery', "SELECT COUNT(*) FROM purchase_orders WHERE station_id=? AND admin_finalized=1 AND delivery_validated=0 AND stock_in_done=0 AND LOWER(COALESCE(status,'')) NOT IN ('cancelled','rejected')", [$s]);
check($pdo, 'pending_fuel_po_delivery', "SELECT COUNT(*) FROM fuel_purchase_orders WHERE station_id=? AND LOWER(COALESCE(status,'')) IN ('approved','approved po','admin finalized','official','confirmed') AND delivery_date IS NULL", [$s]);

// System Health
check($pdo, 'latest_backup', "SELECT COALESCE(status,'none') FROM system_backups ORDER BY COALESCE(completed_at,created_at) DESC, id DESC LIMIT 1", []);
check($pdo, 'audit_today_logs', "SELECT COUNT(*) FROM audit_logs WHERE DATE(created_at)=?", [$date_filter]);
check($pdo, 'login_successes', "SELECT COUNT(*) FROM login_attempts WHERE DATE(attempt_time)=? AND LOWER(status)='success'", [$date_filter]);
check($pdo, 'login_failures', "SELECT COUNT(*) FROM login_attempts WHERE DATE(attempt_time)=? AND LOWER(status) IN ('failed','locked','blocked')", [$date_filter]);

echo "\n=== MANAGEMENT PANEL QUERIES ===\n";

// Pending Users
checkRows($pdo, 'pending_users_data', "SELECT id, employee_id, COALESCE(NULLIF(TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))),''),(username),(CONCAT('User #',id))) AS employee, role, status FROM users u WHERE station_id=? AND LOWER(COALESCE(status,''))='pending' ORDER BY created_at DESC LIMIT 8", [$s]);

// Recent User Activities
checkRows($pdo, 'recent_user_activities', "SELECT COALESCE(COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),(u.username),(CONCAT('User #',u.id))),'System') AS user_name, COALESCE(al.entity_type,al.log_type,'System') AS module, COALESCE(al.action_type,'Activity') AS action, al.status, al.created_at FROM audit_logs al LEFT JOIN users u ON u.id=al.user_id WHERE (u.station_id=? OR al.user_id IS NULL) ORDER BY al.created_at DESC LIMIT 10", [$s]);

// Pending Inventory Adjustments (purchase_orders)
checkRows($pdo, 'pending_inventory_adjustments_merch', "SELECT po.id, po.po_number AS ref_no, COALESCE(po.product_name,'Merchandise Products') AS product, COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),(u.username),(CONCAT('User #',u.id)),'Manager') AS requested_by, po.status, po.created_at FROM purchase_orders po LEFT JOIN users u ON u.id=po.created_by WHERE po.station_id=? AND (LOWER(COALESCE(po.status,'')) IN ('pending','pending approval','pending admin validation') OR (po.admin_finalized=0 AND LOWER(COALESCE(po.status,'')) NOT IN ('draft','rejected','cancelled','received'))) ORDER BY po.created_at DESC LIMIT 8", [$s]);

// Pending Customers
checkRows($pdo, 'pending_customers', "SELECT c.id, c.name AS customer, COALESCE(NULLIF(c.contact_number,''),NULLIF(c.phone,''),NULLIF(c.email,''),'N/A') AS contact, c.created_at FROM customers c WHERE c.station_id=? AND LOWER(COALESCE(c.status,'active'))<>'inactive' AND (LOWER(COALESCE(c.verification_status,''))='pending' OR LOWER(COALESCE(c.mgr_status,''))='pending') ORDER BY c.created_at DESC LIMIT 8", [$s]);

// Recent Transactions
checkRows($pdo, 'recent_fuel_transactions', "SELECT 'Fuel' AS type, COALESCE(transaction_id,CONCAT('FT-',id)) AS ref_no, fuel_type AS details, total_amount, COALESCE(status,'Completed') AS status, transaction_date AS created_at FROM fuel_transactions WHERE station_id=? ORDER BY transaction_date DESC, id DESC LIMIT 10", [$s]);
checkRows($pdo, 'recent_merch_transactions', "SELECT 'Merchandise' AS type, COALESCE(transaction_id,CONCAT('MT-',id)) AS ref_no, COALESCE(customer_name,'Merchandise') AS details, total_amount, COALESCE(validation_status,workflow_status,'Completed') AS status, COALESCE(transaction_date,created_at) AS created_at FROM merchandise_transactions WHERE station_id=? ORDER BY COALESCE(transaction_date,created_at) DESC, id DESC LIMIT 10", [$s]);
checkRows($pdo, 'recent_job_orders', "SELECT 'Service' AS type, COALESCE(job_order_number,job_order_id,CONCAT('JO-',id)) AS ref_no, COALESCE(customer_name,service_type,'Service') AS details, COALESCE(total_cost,estimated_cost,0) AS total_amount, COALESCE(status,validation_status,'Pending') AS status, created_at FROM job_orders WHERE station_id=? ORDER BY created_at DESC, id DESC LIMIT 10", [$s]);

// Low Inventory
checkRows($pdo, 'low_fuel_inventory', "SELECT 'Fuel' AS type, fuel_type AS product, COALESCE(current_level,current_stock,0) AS current_stock, COALESCE(reorder_level,0) AS reorder_level, CASE WHEN COALESCE(current_level,current_stock,0)<=COALESCE(critical_level,0) THEN 'Critical' WHEN COALESCE(current_level,current_stock,0)<=COALESCE(reorder_level,0) THEN 'Low' ELSE 'Normal' END AS status FROM fuel_inventory WHERE station_id=? AND COALESCE(current_level,current_stock,0)<=COALESCE(reorder_level,0) ORDER BY current_stock ASC LIMIT 8", [$s]);
checkRows($pdo, 'low_merch_inventory', "SELECT 'Merchandise' AS type, COALESCE(p.name,CONCAT('Product #',si.product_id)) AS product, COALESCE(si.stock_level,0) AS current_stock, COALESCE(si.reorder_level,0) AS reorder_level, CASE WHEN COALESCE(si.stock_level,0)<=0 THEN 'Critical' WHEN COALESCE(si.stock_level,0)<=COALESCE(si.reorder_level,0) THEN 'Low' ELSE 'Normal' END AS status FROM station_inventory si LEFT JOIN products p ON p.id=si.product_id WHERE si.station_id=? AND si.status='active' AND COALESCE(si.stock_level,0)<=COALESCE(si.reorder_level,0) ORDER BY si.stock_level ASC LIMIT 8", [$s]);

// Recent Deliveries
checkRows($pdo, 'recent_deliveries_oversight', "SELECT id, delivery_type AS type, COALESCE(NULLIF(delivery_ref,''),CONCAT('DO-',id)) AS ref_no, supplier, product, status, created_at FROM deliveries_oversight WHERE station_id=? ORDER BY created_at DESC LIMIT 10", [$s]);

echo "\n=== COLUMN EXISTENCE SPOT CHECKS ===\n";
$col_checks = [  ['fuel_transactions', 'transaction_id'],  ['fuel_transactions', 'fuel_type'],  ['fuel_transactions', 'total_amount'],  ['fuel_transactions', 'liters_sold'],  ['fuel_transactions', 'staff_id'],  ['merchandise_transactions', 'transaction_id'],  ['merchandise_transactions', 'validation_status'],  ['merchandise_transactions', 'workflow_status'],  ['merchandise_transactions', 'customer_name'],  ['merchandise_transactions', 'staff_id'],  ['job_orders', 'job_order_number'],  ['job_orders', 'job_order_id'],  ['job_orders', 'total_cost'],  ['job_orders', 'estimated_cost'],  ['job_orders', 'created_by'],  ['job_orders', 'user_id'],  ['customers', 'contact_number'],  ['customers', 'phone'],  ['customers', 'verification_status'],  ['customers', 'mgr_status'],  ['customers', 'mgr_reviewed_by'],  ['purchase_orders', 'admin_finalized'],  ['purchase_orders', 'delivery_validated'],  ['purchase_orders', 'stock_in_done'],  ['purchase_orders', 'product_name'],  ['purchase_orders', 'po_number'],  ['fuel_purchase_orders', 'po_number'],  ['fuel_purchase_orders', 'fuel_type_id'],  ['fuel_purchase_orders', 'volume'],  ['fuel_purchase_orders', 'delivery_date'],  ['fuel_inventory', 'fuel_type'],  ['fuel_inventory', 'current_level'],  ['fuel_inventory', 'current_stock'],  ['fuel_inventory', 'critical_level'],  ['fuel_inventory', 'reorder_level'],  ['station_inventory', 'stock_level'],  ['station_inventory', 'reorder_level'],  ['deliveries_oversight', 'delivery_ref'],  ['deliveries_oversight', 'delivery_type'],  ['deliveries_oversight', 'supplier'],  ['audit_logs', 'entity_type'],  ['audit_logs', 'log_type'],  ['audit_logs', 'action_type'],  ['audit_logs', 'error_message'],  ['login_attempts', 'attempt_time'],  ['login_attempts', 'status'],  ['users', 'first_name'],  ['users', 'last_name'],  ['users', 'employee_id'],
];

foreach ($col_checks as [$table, $col]) {  try {  $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");  $stmt->execute([$table, $col]);  $exists = (bool)$stmt->fetchColumn();  echo ($exists ? "[OK]  " : "[MISS]") . " $table.$col\n";  } catch (Exception $e) {  echo "[ERR]  $table.$col => " . $e->getMessage() . "\n";  }
}
