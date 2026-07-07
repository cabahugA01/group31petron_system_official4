<?php
$page_id = 'fuel_management';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/classes/ShiftPeriodConfig.php';
require_login();

$me = current_user();
$station_id = user_station_id();
$role = role_key($me['role'] ?? '');

// Helper for preceding shift & date mapping
if (!function_exists('get_preceding_shift_and_date')) {  function get_preceding_shift_and_date($pdo, $shift_key, $date) {  $stmt = $pdo->query("SELECT shift_key FROM shift_periods WHERE is_active = 1 ORDER BY sort_order ASC");  $shifts = $stmt->fetchAll(PDO::FETCH_COLUMN);  if (empty($shifts)) {  return null;  }  $index = array_search(strtolower($shift_key), array_map('strtolower', $shifts));  if ($index === false) {  return null;  }  if ($index > 0) {  return [  'shift_key' => $shifts[$index - 1],  'date' => $date  ];  } else {  $prev_date = date('Y-m-d', strtotime($date . ' -1 day'));  return [  'shift_key' => $shifts[count($shifts) - 1],  'date' => $prev_date  ];  }  }
}

// AJAX handler to fetch previous reading based on pump and shift
if (isset($_GET['action']) && $_GET['action'] === 'get_previous_reading') {  header('Content-Type: application/json');  $pump_id = (int)($_GET['pump_id'] ?? 0);  $shift_period = trim($_GET['shift_period'] ?? '');  $date = trim($_GET['date'] ?? date('Y-m-d'));  if ($pump_id <= 0 || empty($shift_period)) {  echo json_encode(['success' => false, 'reading' => 0.00]);  exit;  }  $preceding = get_preceding_shift_and_date($pdo, $shift_period, $date);  $prev_reading = 0.00;  if ($preceding) {  $stmt = $pdo->prepare("  SELECT present_reading  FROM fuel_transactions  WHERE station_id = ?  AND pump_id = ?  AND LOWER(shift_period) = LOWER(?)  AND DATE(transaction_date) = ?  AND LOWER(status) IN ('verified', 'adjusted')  ORDER BY id DESC  LIMIT 1  ");  $stmt->execute([$station_id, $pump_id, $preceding['shift_key'], $preceding['date']]);  $val = $stmt->fetchColumn();  if ($val !== false) {  $prev_reading = (float)$val;  } else {  $stmt_fallback = $pdo->prepare("  SELECT present_reading  FROM fuel_transactions  WHERE station_id = ?  AND pump_id = ?  AND LOWER(status) IN ('verified', 'adjusted')  ORDER BY transaction_date DESC, id DESC  LIMIT 1  ");  $stmt_fallback->execute([$station_id, $pump_id]);  $val_fallback = $stmt_fallback->fetchColumn();  if ($val_fallback !== false) {  $prev_reading = (float)$val_fallback;  }  }  } else {  $stmt_fallback = $pdo->prepare("  SELECT present_reading  FROM fuel_transactions  WHERE station_id = ?  AND pump_id = ?  AND LOWER(status) IN ('verified', 'adjusted')  ORDER BY transaction_date DESC, id DESC  LIMIT 1  ");  $stmt_fallback->execute([$station_id, $pump_id]);  $val_fallback = $stmt_fallback->fetchColumn();  if ($val_fallback !== false) {  $prev_reading = (float)$val_fallback;  }  }  echo json_encode(['success' => true, 'reading' => $prev_reading]);  exit;
}

// ── Module gate ───────────────────────────────────────────────
if (!in_array($role, ['superadmin','developer']) && !is_module_enabled('fuel_management')) {  render_module_disabled_page('Fuel Management');
}

// Only staff and above can access fuel management
if (!in_array($role, ['staff', 'cashier', 'pump_attendant', 'manager', 'admin', 'superadmin'])) {  $_SESSION['error'] = 'Access denied.';  header('Location: dashboard.php');  exit;
}

$msg = '';
$msg_type = '';
if (isset($_SESSION['success'])) {  $msg = $_SESSION['success'];  $msg_type = 'success';  unset($_SESSION['success']); 
}
if (isset($_SESSION['error'])) {  $msg = $_SESSION['error'];  $msg_type = 'error';  unset($_SESSION['error']); 
}

function fm_table_exists(PDO $pdo, string $tableName): bool {  $stmt = $pdo->prepare('SHOW TABLES LIKE ?');  $stmt->execute([$tableName]);  return (bool)$stmt->fetchColumn();
}

function fm_table_columns(PDO $pdo, string $tableName): array {  if (!fm_table_exists($pdo, $tableName)) {  return [];  }  $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $tableName) . '`');  return array_map(static function (array $row): string {  return $row['Field'];  }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function fm_has_column(PDO $pdo, string $tableName, string $columnName): bool {  return in_array($columnName, fm_table_columns($pdo, $tableName), true);
}

function fm_ensure_support_tables(PDO $pdo): void {  $pdo->exec("CREATE TABLE IF NOT EXISTS fuel_readings (  id INT AUTO_INCREMENT PRIMARY KEY,  pump_number VARCHAR(20) NOT NULL,  fuel_type VARCHAR(100) NOT NULL,  present_reading DECIMAL(10,2) NOT NULL,  previous_reading DECIMAL(10,2) NOT NULL DEFAULT 0.00,  difference DECIMAL(10,2) NOT NULL DEFAULT 0.00,  shift_period VARCHAR(20) DEFAULT NULL,  status VARCHAR(50) DEFAULT 'Pending Manager Validation',  station_id INT NOT NULL,  encoded_by INT DEFAULT NULL,  encoded_at DATETIME NOT NULL,  INDEX idx_fuel_readings_station_time (station_id, encoded_at),  INDEX idx_fuel_readings_type (station_id, fuel_type),  INDEX idx_fuel_readings_status (status)  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");  // Add status & calibration columns to existing tables if they don't exist  $pdo->exec("ALTER TABLE fuel_readings  ADD COLUMN IF NOT EXISTS status VARCHAR(50) DEFAULT 'Pending Manager Validation',  ADD COLUMN IF NOT EXISTS calibration DECIMAL(10,2) NOT NULL DEFAULT 0.00");  $pdo->exec("ALTER TABLE fuel_readings  ADD INDEX IF NOT EXISTS idx_fuel_readings_status (status)");  $pdo->exec("CREATE TABLE IF NOT EXISTS calibration_logs (  id INT AUTO_INCREMENT PRIMARY KEY,  pump_number VARCHAR(20) NOT NULL,  fuel_type VARCHAR(100) NOT NULL,  calibration_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,  shift_period VARCHAR(20) DEFAULT NULL,  station_id INT NOT NULL,  encoded_by INT DEFAULT NULL,  encoded_at DATETIME NOT NULL,  INDEX idx_calibration_logs_station_time (station_id, encoded_at),  INDEX idx_calibration_logs_type (station_id, fuel_type)  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");  $pdo->exec("CREATE TABLE IF NOT EXISTS fuel_audit_trail (  id INT AUTO_INCREMENT PRIMARY KEY,  reading_id INT DEFAULT NULL,  calibration_id INT DEFAULT NULL,  action VARCHAR(50) NOT NULL,  before_value DECIMAL(10,2) DEFAULT NULL,  after_value DECIMAL(10,2) DEFAULT NULL,  stock_before DECIMAL(10,2) DEFAULT NULL,  stock_after DECIMAL(10,2) DEFAULT NULL,  performed_by INT DEFAULT NULL,  performed_at DATETIME NOT NULL,  notes TEXT DEFAULT NULL,  INDEX idx_fuel_audit_station_time (performed_by, performed_at)  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");  $pdo->exec("CREATE TABLE IF NOT EXISTS low_stock_alerts (  id INT AUTO_INCREMENT PRIMARY KEY,  station_id INT NOT NULL,  fuel_type VARCHAR(100) NOT NULL,  current_stock DECIMAL(10,2) NOT NULL DEFAULT 0.00,  threshold DECIMAL(10,2) NOT NULL DEFAULT 0.00,  alert_level VARCHAR(50) NOT NULL DEFAULT 'Warning',  created_by INT DEFAULT NULL,  created_at DATETIME NOT NULL,  INDEX idx_low_stock_station_time (station_id, created_at),  INDEX idx_low_stock_type (station_id, fuel_type)  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function fm_resolve_pump_for_fuel_type(PDO $pdo, int $stationId, string $fuelType): ?array {  if (fm_table_exists($pdo, 'fuel_pumps') && fm_table_exists($pdo, 'fuel_types') && fm_has_column($pdo, 'fuel_pumps', 'fuel_type_id')) {  $stmt = $pdo->prepare("  SELECT fp.pump_number, COALESCE(ft.name, ?) AS fuel_type  FROM fuel_pumps fp  LEFT JOIN fuel_types ft  ON ft.id = fp.fuel_type_id  WHERE fp.station_id = ?  AND LOWER(TRIM(COALESCE(ft.name, ?))) = LOWER(TRIM(?))  ORDER BY fp.pump_number ASC  LIMIT 1  ");  $stmt->execute([$fuelType, $stationId, $fuelType, $fuelType]);  $pump = $stmt->fetch(PDO::FETCH_ASSOC);  if ($pump) {  return $pump;  }  }  if (fm_table_exists($pdo, 'fuel_pumps')) {  $stmt = $pdo->prepare("  SELECT pump_number  FROM fuel_pumps  WHERE station_id = ?  ORDER BY pump_number ASC  LIMIT 1  ");  $stmt->execute([$stationId]);  $pump = $stmt->fetch(PDO::FETCH_ASSOC);  if ($pump) {  $pump['fuel_type'] = $fuelType;  return $pump;  }  }  return null;
}

fm_ensure_support_tables($pdo);

// Initialize shift period configuration
$shift_config = getShiftPeriodConfig($pdo, $station_id);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {  if (isset($_POST['action'])) {  switch ($_POST['action']) {  case 'encode_fuel_reading':  $pump_id = (int)($_POST['pump_id'] ?? 0);  $present_reading = (float)($_POST['present_reading'] ?? 0);  $shift_period = $_POST['shift_period'] ?? '';  try {  if ($pump_id <= 0) {  $_SESSION['error'] = 'Invalid pump selected.';  header('Location: fuel_readings_encoding.php');  exit;  }  if (!$shift_config->isValidShiftKey($shift_period)) {  $_SESSION['error'] = 'Invalid shift period selected.';  header('Location: fuel_readings_encoding.php');  exit;  }  // Fetch the selected pump and its fuel type  $stmt = $pdo->prepare("  SELECT fp.*, ft.name AS fuel_type_name  FROM fuel_pumps fp  LEFT JOIN fuel_types ft ON fp.fuel_type_id = ft.id  WHERE fp.id = ? AND fp.station_id = ?  ");  $stmt->execute([$pump_id, $station_id]);  $pump = $stmt->fetch(PDO::FETCH_ASSOC);  if (!$pump) {  $_SESSION['error'] = 'Selected pump not found.';  header('Location: fuel_readings_encoding.php');  exit;  }  $pump_number = $pump['pump_number'];  $fuel_type = $pump['fuel_type_name'];  $calibration_value = (float)($pump['calibration_value'] ?? 0);  // Get last VERIFIED/ADJUSTED transaction reading for this pump  $stmt = $pdo->prepare("  SELECT present_reading  FROM fuel_transactions  WHERE station_id = ?  AND pump_id = ?  AND LOWER(status) IN ('verified','adjusted')  ORDER BY transaction_date DESC, id DESC  LIMIT 1  ");  $stmt->execute([$station_id, $pump_id]);  $last_reading = $stmt->fetch(PDO::FETCH_ASSOC);  // Fallback: try fuel_readings table  if (!$last_reading) {  $stmt = $pdo->prepare("  SELECT present_reading  FROM fuel_readings  WHERE station_id = ?  AND pump_number = ?  AND LOWER(status) IN ('verified','adjusted','approved','manager approved')  ORDER BY encoded_at DESC, id DESC  LIMIT 1  ");  $stmt->execute([$station_id, $pump_number]);  $last_reading = $stmt->fetch(PDO::FETCH_ASSOC);  }  // If still no approved reading found, start from 0  $previous_reading = $last_reading['present_reading'] ?? 0;  // Validation  if ($present_reading < $previous_reading) {  $_SESSION['error'] = 'Present reading cannot be less than previous reading (' . number_format($previous_reading, 2) . 'L).';  header('Location: fuel_readings_encoding.php');  exit;  }  // Compute net liters sold (present - previous - calibration)  $difference = max(0, $present_reading - $previous_reading - $calibration_value);  // Get current stock for projected low-stock warning (display only, no deduction)  $stmt = $pdo->prepare("SELECT current_level AS current_stock FROM fuel_inventory WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))");  $stmt->execute([$station_id, $fuel_type]);  $stock_row = $stmt->fetch(PDO::FETCH_ASSOC);  $stock_before_amount = (float)($stock_row['current_stock'] ?? 0);  // Soft check: warn if projected balance would be negative (staff info only)  $projected_stock = $stock_before_amount - $difference;  if ($projected_stock < 0) {  $_SESSION['error'] = 'Warning: Projected liters sold (' . number_format($difference, 2) . 'L) exceeds current inventory (' . number_format($stock_before_amount, 2) . 'L). Please recheck your reading and resubmit. The actual deduction will be verified by the manager.';  header('Location: fuel_readings_encoding.php');  exit;  }  // Create fuel reading record (pending manager validation)  $stmt = $pdo->prepare("  INSERT INTO fuel_readings (  pump_number, fuel_type, present_reading, previous_reading,  difference, calibration, shift_period, status, station_id, encoded_by, encoded_at  ) VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending Manager Validation', ?, ?, NOW())  ");  $stmt->execute([  $pump_number, $fuel_type, $present_reading, $previous_reading,  $difference, $calibration_value, $shift_period, $station_id, $me['id']  ]);  $reading_id = $pdo->lastInsertId();  // Insert into fuel_transactions so manager approval queue picks it up  $txn_id = 'FUEL' . date('Y') . str_pad($station_id, 3, '0', STR_PAD_LEFT)  . str_pad($reading_id, 6, '0', STR_PAD_LEFT);  $price_per_liter = 0.0;  try {  $priceStmt = $pdo->prepare("SELECT price_per_liter FROM fuel_inventory WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?)) LIMIT 1");  $priceStmt->execute([$station_id, $fuel_type]);  $price_per_liter = (float)($priceStmt->fetchColumn() ?? 0);  } catch (Exception $e) {}  $total_amount = round($difference * $price_per_liter, 2);  try {  // Ensure required columns exist  $ftCols = array_column($pdo->query("SHOW COLUMNS FROM fuel_transactions")->fetchAll(PDO::FETCH_ASSOC), 'Field');  foreach (['shift_period','shift_name','shift_id','notes','status','validated_by','validated_at','reject_reason','pump_id'] as $rc) {  if (!in_array($rc, $ftCols)) {  $def = ($rc === 'status') ? "VARCHAR(50) NULL DEFAULT 'Pending Validation'" : "TEXT NULL";  if ($rc === 'pump_id') {  $def = "INT NULL";  }  $pdo->exec("ALTER TABLE fuel_transactions ADD COLUMN `$rc` $def");  }  }  $pdo->prepare("  INSERT INTO fuel_transactions  (transaction_id, station_id, pump_id, fuel_type,  present_reading, previous_reading, calibration, staff_calibration,  liters_sold, price_per_liter, total_amount,  staff_id, transaction_date,  shift_period, shift_name, shift_id, notes, status)  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Encoded via Reading Form','Pending Validation')  ")->execute([  $txn_id, $station_id, $pump_id, $fuel_type,  $present_reading, $previous_reading, $calibration_value, $calibration_value,  $difference, $price_per_liter, $total_amount,  $me['id'], date('Y-m-d H:i:s'),  $shift_period, '', null,  ]);  } catch (Exception $e) {  error_log('fuel_readings_encoding: could not insert fuel_transactions: ' . $e->getMessage());  }  // ── Check for projected low stock alert (informational only) ──────────────  $threshold = 500.0; // safe default  $critical_threshold = 100.0;  try {  $thr_stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('low_stock_threshold','critical_stock_threshold')");  if ($thr_stmt) {  foreach ($thr_stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {  if ($k === 'low_stock_threshold'  && (float)$v > 0) $threshold  = (float)$v;  if ($k === 'critical_stock_threshold' && (float)$v > 0) $critical_threshold = (float)$v;  }  }  } catch (Exception $e) {}  if (fm_has_column($pdo, 'fuel_inventory', 'reorder_threshold')) {  try {  $thr2 = $pdo->prepare("SELECT reorder_threshold FROM fuel_inventory WHERE station_id = ? AND fuel_type = ? AND reorder_threshold > 0 LIMIT 1");  $thr2->execute([$station_id, $fuel_type]);  $rt = $thr2->fetchColumn();  if ($rt !== false && (float)$rt > 0) $threshold = (float)$rt;  } catch (Exception $e) {}  }  // Record projected alert (using projected_stock, not actual deduction)  $low_stock_alert = false;  if ($projected_stock < $threshold) {  $low_stock_alert = true;  try {  $alert_level = $projected_stock < $critical_threshold ? 'Critical' : 'Warning';  $pdo->prepare("  INSERT INTO low_stock_alerts (  station_id, fuel_type, current_stock, threshold, alert_level,  created_by, created_at  ) VALUES (?, ?, ?, ?, ?, ?, NOW())  ")->execute([$station_id, $fuel_type, $projected_stock, $threshold, $alert_level, $me['id']]);  } catch (Exception $e) {}  }  // ── Audit trail: record stock_before; stock_after deferred to manager approval ──  try {  $notes_audit = "Pump #{$pump_number} | {$fuel_type} | Prev: {$previous_reading}L → Present: {$present_reading}L | Diff: " . number_format($difference, 2) . "L | Shift: {$shift_period} | PENDING MANAGER APPROVAL — no deduction yet.";  if ($low_stock_alert) {  $notes_audit .= ' [PROJECTED LOW STOCK: ' . number_format($projected_stock, 2) . 'L after approval]';  }  $pdo->prepare("  INSERT INTO fuel_audit_trail (  reading_id, action, before_value, after_value, stock_before, stock_after,  performed_by, performed_at, notes  ) VALUES (?, 'FUEL_READING_ENCODED', ?, ?, ?, NULL, ?, NOW(), ?)  ")->execute([  $reading_id, $previous_reading, $present_reading,  $stock_before_amount, $me['id'], $notes_audit  ]);  } catch (Exception $e) {}  $_SESSION['success'] = 'Fuel reading encoded and submitted for manager approval. Liters computed: ' . number_format($difference, 2) . 'L. Inventory will be deducted after manager approves.'  . ($low_stock_alert ? ' Projected low stock after approval!' : '');  // ── Audit log ──  try {  $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';  $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';  $detail = "Fuel reading encoded | Pump #{$pump_number} | {$fuel_type} | Prev: " . number_format($previous_reading,2) . "L → Present: " . number_format($present_reading,2) . "L | Diff: " . number_format($difference,2) . "L | Shift: {$shift_period}" . ($low_stock_alert ? " | LOW STOCK" : '');  $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at) VALUES (?, 'transaction', 'Create', ?, 'fuel_readings', ?, 'Success', ?, ?, NOW())")  ->execute([$me['id'], $detail, $reading_id, $ip, $ua]);  } catch (Exception $e) {}  header('Location: fuel_readings_encoding.php');  exit;  } catch (Exception $e) {  $_SESSION['error'] = 'Error encoding fuel reading: ' . $e->getMessage();  header('Location: fuel_readings_encoding.php');  exit;  }  break;  }  }
}

// Fetch data for forms
$fuel_readings = [];
$fuel_inventory = [];
$low_stock_alerts = [];
$fuel_options = [];

try {  $fuelInventoryColumns = fm_table_columns($pdo, 'fuel_inventory');  $reorderThresholdExpr = in_array('reorder_threshold', $fuelInventoryColumns, true)  ? 'COALESCE(fi.reorder_threshold, 0)'  : '0';  $lastUpdatedExpr = in_array('last_updated', $fuelInventoryColumns, true)  ? 'COALESCE(fi.last_updated, NOW())'  : 'NOW()';  // Get all pumps for this station with their respective last readings and calibration values  $stmt = $pdo->prepare("  SELECT  fp.id,  fp.pump_number,  fp.calibration_value,  ft.name AS fuel_type,  COALESCE(fi.current_level, 0) AS current_stock,  -- Get last reading for this specific pump  COALESCE(  (SELECT present_reading  FROM fuel_transactions  WHERE station_id = fp.station_id  AND pump_id = fp.id  AND LOWER(status) IN ('verified','adjusted')  ORDER BY transaction_date DESC, id DESC LIMIT 1),  (SELECT present_reading  FROM fuel_readings  WHERE station_id = fp.station_id  AND pump_number = fp.pump_number  AND LOWER(status) IN ('verified','adjusted','approved','manager approved')  ORDER BY encoded_at DESC, id DESC LIMIT 1),  0  ) AS previous_reading  FROM fuel_pumps fp  LEFT JOIN fuel_types ft ON fp.fuel_type_id = ft.id  LEFT JOIN fuel_inventory fi ON fi.fuel_type_id = fp.fuel_type_id AND fi.station_id = fp.station_id  WHERE fp.station_id = ?  ORDER BY fp.pump_number ASC  ");  $stmt->execute([$station_id]);  $fuel_options = $stmt->fetchAll(PDO::FETCH_ASSOC);  // Get fuel inventory  $stmt = $pdo->prepare("SELECT fi.*, COALESCE(fi.current_level, 0) AS current_stock, " . $reorderThresholdExpr . " AS reorder_threshold FROM fuel_inventory fi WHERE station_id = ? ORDER BY fuel_type");  $stmt->execute([$station_id]);  $fuel_inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);  // Get low stock alerts directly from live fuel inventory  $stmt = $pdo->prepare("  SELECT  fi.fuel_type,  COALESCE(fi.current_level, 0) AS current_stock,  " . $reorderThresholdExpr . " AS reorder_threshold,  CASE  WHEN COALESCE(fi.current_level, 0) < 100 THEN 'Critical'  ELSE 'Warning'  END AS alert_level,  " . $lastUpdatedExpr . " AS created_at  FROM fuel_inventory fi  WHERE fi.station_id = ?  AND " . $reorderThresholdExpr . " > 0  AND COALESCE(fi.current_level, 0) <= " . $reorderThresholdExpr . "  ORDER BY (COALESCE(fi.current_level, 0) / NULLIF(" . $reorderThresholdExpr . ", 0)) ASC, fi.fuel_type ASC  ");  $stmt->execute([$station_id]);  $low_stock_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);  // Get recent fuel readings based on role  $name_expr = "COALESCE(NULLIF(CONCAT(TRIM(COALESCE(u.first_name,'')), ' ', TRIM(COALESCE(u.last_name,''))), ' '), u.username, 'Unknown')";  if (in_array($role, ['staff', 'cashier', 'pump_attendant'])) {  // Staff can only see their own readings  $stmt = $pdo->prepare("  SELECT fr.*, {$name_expr} as encoded_by_name  FROM fuel_readings fr  LEFT JOIN users u ON fr.encoded_by = u.id  WHERE fr.station_id = ? AND fr.encoded_by = ?  ORDER BY fr.encoded_at DESC  LIMIT 50  ");  $stmt->execute([$station_id, $me['id']]);  } else {  // Managers and above can see all readings  $stmt = $pdo->prepare("  SELECT fr.*, {$name_expr} as encoded_by_name  FROM fuel_readings fr  LEFT JOIN users u ON fr.encoded_by = u.id  WHERE fr.station_id = ?  ORDER BY fr.encoded_at DESC  LIMIT 100  ");  $stmt->execute([$station_id]);  }  $fuel_readings = $stmt->fetchAll(PDO::FETCH_ASSOC);  } catch (Exception $e) {  error_log("Error fetching fuel data: " . $e->getMessage());
}

include __DIR__ . '/../partials/header.php';
?>

<style>
.fuel-management-container {  max-width: 1400px;  margin: 0 auto;  padding: 20px;
}  .fuel-card {  background: #fff;  border-radius: 12px;  box-shadow: 0 2px 8px rgba(0,0,0,0.08);  border: 1px solid #e9ecef;  padding: 30px;  margin-bottom: 20px;
}

.reading-form {  padding: 0;  color: inherit;
}

.form-row {  display: grid;  grid-template-columns: 1fr 1fr;  gap: 20px;  margin-bottom: 20px;
}

.form-group {  margin-bottom: 20px;
}

.form-label {  display: block;  margin-bottom: 8px;  font-weight: 600;  color: #333;
}  .form-input, .form-select {  width: 100%;  padding: 12px;  border: 1px solid #ddd;  border-radius: 6px;  font-size: 14px;
}  .auto-pulled {  background: #f8f9fa;  border-color: #28a745;  color: #28a745;
}

.computed {  background: #e3f2fd;  border-color: #2196f3;  color: #1976d2;
}

.calculation-display {  background: #f8f9fa;  padding: 20px;  border-radius: 8px;  margin: 20px 0;
}

.calc-row {  display: flex;  justify-content: space-between;  margin-bottom: 10px;  padding: 8px 0;
}

.calc-row.total {  border-top: 2px solid #333;  padding-top: 15px;  font-weight: bold;  font-size: 18px;
}

.btn-primary {  background: #003d7a;  color: white;  border: none;  padding: 12px 24px;  border-radius: 6px;  cursor: pointer;  font-weight: 600;  transition: background 0.3s ease;
}

.btn-primary:hover {  background: #002855;
}

.btn-secondary {  background: #6c757d;  color: white;  border: none;  padding: 8px 16px;  border-radius: 4px;  cursor: pointer;  font-size: 12px;
}

.alert {  padding: 15px;  border-radius: 8px;  margin-bottom: 20px;  border: 1px solid transparent;
}

.alert-success {  background: #d4edda;  border-color: #c3e6cb;  color: #155724;
}

.alert-error {  background: #f8d7da;  border-color: #f5c6cb;  color: #721c24;
}

.alert-warning {  background: #fff3cd;  border: 1px solid #ffeaa7;  color: #856404;  padding: 15px;  border-radius: 8px;  margin-bottom: 20px;
}

.alert-danger {  background: #f8d7da;  border: 1px solid #f5c6cb;  color: #721c24;  padding: 15px;  border-radius: 8px;  margin-bottom: 20px;
}

.stock-grid {  display: grid;  grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));  gap: 20px;  margin-bottom: 30px;
}

.stock-card {  padding: 20px;  border-radius: 12px;  border-left: 4px solid #003d7a;  background: #f8f9fa;
}

.stock-card.low-stock {  border-left-color: #ffc107;  background: #fff3cd;
}

.stock-card.critical-stock {  border-left-color: #dc3545;  background: #f8d7da;
}

.fuel-table {  width: 100%;  border-collapse: collapse;  margin-top: 20px;
}

.fuel-table th,
.fuel-table td {  padding: 12px;  text-align: left;  border-bottom: 1px solid #e9ecef;
}

.fuel-table th {  background: #f8f9fa;  font-weight: 600;  color: #333;
}

.fuel-table tr:hover {  background: #f8f9fa;
}

@media (max-width: 768px) {  .form-row {  grid-template-columns: 1fr;  }  .fuel-tabs {  flex-direction: column;  }  .stock-grid {  grid-template-columns: 1fr;  }
}
</style>

<div class="fuel-management-container">  <div class="page-head">  <div>  <h1 class="h1">Fuel Management</h1>  <div class="sub">Encode readings, track inventory, and monitor stock levels with audit-ready transparency</div>  </div>  </div>  <?php
// Toast bridge: convert $msg/$msg_type to SESSION for flash_toast
if (!empty($msg)) {  if ($msg_type === 'success') $_SESSION['success'] = $msg;  else $_SESSION['error'] = $msg;  $msg = ''; $msg_type = '';
}
require __DIR__ . '/../partials/flash_toast.php';
?>  <!-- Low Stock Alerts -->  <?php if (!empty($low_stock_alerts)): ?>  <div class="alert-warning">  <h4><i class="fas fa-exclamation-triangle"></i> Low Stock Alerts</h4>  <?php foreach ($low_stock_alerts as $alert): ?>  <p><strong><?php echo htmlspecialchars($alert['fuel_type']); ?>:</strong>  Current: <?php echo number_format($alert['current_stock'], 2); ?>L,  Threshold: <?php echo number_format($alert['reorder_threshold'], 2); ?>L,  Level: <span style="color: <?php echo $alert['alert_level'] === 'Critical' ? '#dc3545' : '#856404'; ?>;">  <?php echo $alert['alert_level']; ?>  </span>  </p>  <?php endforeach; ?>  </div>  <?php endif; ?>  <!-- Fuel Reading Tracker -->  <div class="fuel-card">  <h2 style="margin-bottom: 20px;">Fuel Reading Tracker</h2>  <form method="post" action="fuel_readings_encoding.php">  <input type="hidden" name="action" value="encode_fuel_reading">  <div class="form-row">  <div class="form-group">  <label class="form-label">Select Pump</label>  <select name="pump_id" id="pump_id" class="form-select" onchange="updatePreviousReading()" required>  <option value="">Select pump</option>  <?php foreach ($fuel_options as $fuel): ?>  <option  value="<?php echo htmlspecialchars($fuel['id']); ?>"  data-pump-number="<?php echo htmlspecialchars($fuel['pump_number']); ?>"  data-fuel-type="<?php echo htmlspecialchars($fuel['fuel_type']); ?>"  data-previous-reading="<?php echo number_format((float)($fuel['previous_reading'] ?? 0), 2, '.', ''); ?>"  data-calibration="<?php echo number_format((float)($fuel['calibration_value'] ?? 0), 2, '.', ''); ?>"  data-current-stock="<?php echo number_format((float)($fuel['current_stock'] ?? 0), 2, '.', ''); ?>">  <?php echo htmlspecialchars($fuel['pump_number']); ?> (<?php echo htmlspecialchars($fuel['fuel_type']); ?>)  </option>  <?php endforeach; ?>  </select>  <input type="hidden" name="pump_number" id="resolved_pump_number" value="">  <div id="pump_selection_meta" style="font-size: 11.5px; color: #475569; margin-top: 5px; font-weight: 500;">Select a pump to load meta information.</div>  </div>  <div class="form-group">  <label class="form-label">Present Reading</label>  <input type="number" name="present_reading" id="present_reading"  class="form-input" step="0.01" min="0" required onchange="computeDifference()" oninput="computeDifference()">  </div>  </div>  <div class="form-row">  <div class="form-group">  <label class="form-label">Previous Reading</label>  <input type="number" id="previous_reading"  class="form-input auto-pulled" step="0.01" readonly value="0.00">  </div>  <div class="form-group">  <label class="form-label">Gross Difference (L)</label>  <input type="number" id="computed_difference"  class="form-input computed" step="0.01" readonly value="0.00">  </div>  </div>  <div class="form-row">  <div class="form-group">  <label class="form-label">Calibration Value (L)</label>  <input type="number" id="calibration_value"  class="form-input auto-pulled" step="0.01" readonly value="0.00">  </div>  <div class="form-group">  <label class="form-label">Net Liters Sold</label>  <input type="number" id="computed_net_liters"  class="form-input computed" step="0.01" readonly value="0.00">  </div>  </div>  <div class="form-row">  <div class="form-group">  <label class="form-label">Shift Period</label>  <select name="shift_period" id="shift_period" class="form-select" onchange="updatePreviousReading()" required>  <option value="">Select shift period</option>  <?php echo $shift_config->generateShiftSelectOptions(); ?>  </select>  </div>  <div class="form-group">  <label class="form-label">Staff Name</label>  <?php  $display_name = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''));  if ($display_name === '') $display_name = $me['username'] ?? 'Unknown';  ?>  <input type="text" class="form-input"  value="<?= htmlspecialchars($display_name) ?>" readonly>  </div>  </div>  <div style="display: flex; gap: 15px; margin-top: 20px; justify-content: flex-end;">  <button type="submit" class="btn-primary">  Encode Reading  </button>  <button type="reset" class="btn-secondary" onclick="resetReadingForm()">  Reset  </button>  </div>  </form>  </div>
</div>

<script>

function updatePreviousReading() {  const pumpSelect = document.getElementById('pump_id');  const shiftSelect = document.getElementById('shift_period');  const selectedOption = pumpSelect.options[pumpSelect.selectedIndex];  const pumpMeta = document.getElementById('pump_selection_meta');  const resolvedPumpInput = document.getElementById('resolved_pump_number');  if (!selectedOption || !selectedOption.value) {  document.getElementById('previous_reading').value = '0.00';  document.getElementById('calibration_value').value = '0.00';  if (resolvedPumpInput) {  resolvedPumpInput.value = '';  }  if (pumpMeta) {  pumpMeta.textContent = 'Select a pump to auto-load the last reading and current stock.';  }  computeDifference();  return;  }  const pumpId = selectedOption.value;  const shiftPeriod = shiftSelect ? shiftSelect.value : '';  const calibration = parseFloat(selectedOption.dataset.calibration || '0');  const currentStock = parseFloat(selectedOption.dataset.currentStock || '0');  const fuelType = selectedOption.dataset.fuelType || 'Unknown';  const pumpNumber = selectedOption.dataset.pumpNumber || '';  document.getElementById('calibration_value').value = calibration.toFixed(2);  if (resolvedPumpInput) {  resolvedPumpInput.value = pumpNumber;  }  // Default reading from data-attribute first  let previousReading = parseFloat(selectedOption.dataset.previousReading || '0');  document.getElementById('previous_reading').value = previousReading.toFixed(2);  if (pumpMeta) {  pumpMeta.textContent = `${fuelType} | Linked pump: ${pumpNumber || '-'} | Previous reading: ${previousReading.toFixed(2)} L | Calibration: ${calibration.toFixed(2)} L | Current stock: ${currentStock.toFixed(2)} L`;  }  // If both pump and shift are selected, fetch dynamically via AJAX  if (pumpId && shiftPeriod) {  fetch(`fuel_readings_encoding.php?action=get_previous_reading&pump_id=${pumpId}&shift_period=${shiftPeriod}`)  .then(res => res.json())  .then(data => {  if (data.success) {  const dynamicReading = parseFloat(data.reading);  document.getElementById('previous_reading').value = dynamicReading.toFixed(2);  if (pumpMeta) {  pumpMeta.textContent = `${fuelType} | Linked pump: ${pumpNumber || '-'} | Dynamic previous reading (${shiftPeriod}): ${dynamicReading.toFixed(2)} L | Calibration: ${calibration.toFixed(2)} L | Current stock: ${currentStock.toFixed(2)} L`;  }  computeDifference();  }  })  .catch(err => {  console.error("Error fetching previous reading:", err);  });  }  computeDifference();
}

function computeDifference() {  const present = parseFloat(document.getElementById('present_reading').value) || 0;  const previous = parseFloat(document.getElementById('previous_reading').value) || 0;  const calibration = parseFloat(document.getElementById('calibration_value').value) || 0;  const difference = present - previous;  const netLiters = Math.max(0, difference - calibration);  document.getElementById('computed_difference').value = difference.toFixed(2);  document.getElementById('computed_net_liters').value = netLiters.toFixed(2);
}

function resetReadingForm() {  if (confirm('Reset all form fields?')) {  document.querySelector('form').reset();  document.getElementById('previous_reading').value = '0.00';  document.getElementById('calibration_value').value = '0.00';  document.getElementById('computed_difference').value = '0.00';  document.getElementById('computed_net_liters').value = '0.00';  }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {  computeDifference();  updatePreviousReading();
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
