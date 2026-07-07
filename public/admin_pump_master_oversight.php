<?php
// ============================================================
// Admin Pump Master Oversight – admin_pump_master_oversight.php
// Purpose: Central monitoring and maintenance of all pumps.
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'admin_pump_master_oversight';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me  = current_user();
$role  = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();

// Access control - Admin or SuperAdmin only
if (!in_array($role, ['admin', 'superadmin'])) {  $_SESSION['error'] = 'Access denied. Administrator access required.';  header('Location: dashboard.php');  exit;
}

// ── Station Scoping ──────────────────────────────────────────
$filter_station = isset($_GET['station']) ? (int)$_GET['station'] : $station_id;
if ($role === 'superadmin' && !isset($_GET['station'])) {  $filter_station = 0; // Default to all stations for superadmin
}

$station_condition = "";
$station_params = [];
if ($filter_station > 0) {  $station_condition = "station_id = ?";  $station_params[] = $filter_station;
} else {  $station_condition = "1=1";
}

// ── AJAX Action: Get Pump Calibration History ────────────────
if (isset($_GET['ajax_action'])) {  $action = $_GET['ajax_action'];  header('Content-Type: application/json');  if ($action === 'get_history') {  $pump_id = (int)$_GET['pump_id'];  try {  // Retrieve pump to get station_id, pump_number and fuel_type name  $stmt = $pdo->prepare("  SELECT fp.*, ft.name as fuel_type_name  FROM fuel_pumps fp  LEFT JOIN fuel_types ft ON fp.fuel_type_id = ft.id  WHERE fp.id = ?  ");  $stmt->execute([$pump_id]);  $pump = $stmt->fetch(PDO::FETCH_ASSOC);  if ($pump) {  $h_stmt = $pdo->prepare("  SELECT  pch.id,  pch.previous_value AS previous_calibration,  pch.calibration_value AS new_calibration,  pch.performed_at AS updated_at,  pch.reason,  COALESCE(NULLIF(CONCAT(TRIM(u.first_name), ' ', TRIM(u.last_name)), ' '), u.username, 'System') as updater_name  FROM pump_calibration_history pch  LEFT JOIN users u ON pch.performed_by = u.id  WHERE pch.pump_id = ?  ORDER BY pch.performed_at DESC  LIMIT 100  ");  $h_stmt->execute([$pump_id]);  echo json_encode($h_stmt->fetchAll(PDO::FETCH_ASSOC));  } else {  echo json_encode([]);  }  } catch (Exception $e) {  echo json_encode(['error' => $e->getMessage()]);  }  exit;  }
}

// ── POST Actions (CRUD + Calibration) ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {  $action = trim($_POST['action'] ?? '');  // 1. ADD PUMP  if ($action === 'add_pump') {  $target_station = ($role === 'superadmin') ? (int)$_POST['station_id'] : $station_id;  $pump_number  = trim($_POST['pump_number'] ?? '');  $fuel_type_id  = (int)($_POST['fuel_type_id'] ?? 0);  $capacity  = (float)($_POST['capacity'] ?? 0);  $status  = trim($_POST['status'] ?? 'Active');  if ($target_station <= 0) {  $_SESSION['error'] = 'Please select a valid station.';  } elseif (empty($pump_number)) {  $_SESSION['error'] = 'Pump name/number is required.';  } elseif ($fuel_type_id <= 0) {  $_SESSION['error'] = 'Please select a fuel type.';  } else {  try {  $pdo->beginTransaction();  // Check for duplicate pump number at target station  $chk = $pdo->prepare("SELECT id FROM fuel_pumps WHERE station_id = ? AND LOWER(pump_number) = LOWER(?)");  $chk->execute([$target_station, $pump_number]);  if ($chk->fetch()) {  throw new Exception("A pump with that name/number already exists at this station.");  }  $stmt = $pdo->prepare("  INSERT INTO fuel_pumps (station_id, pump_number, fuel_type_id, capacity, status, calibration_value, created_at)  VALUES (?, ?, ?, ?, ?, 0.00, NOW())  ");  $stmt->execute([$target_station, $pump_number, $fuel_type_id, $capacity, $status]);  $new_pump_id = $pdo->lastInsertId();  log_activity($pdo, $me['id'], 'Add Pump', "Added new pump {$pump_number} (ID: {$new_pump_id}) at Station ID {$target_station}");  write_audit_log($pdo, 'Add Pump', "Created pump {$pump_number} at station {$target_station}", 'fuel_pumps', $new_pump_id, 'system', 'Success');  $pdo->commit();  $_SESSION['success'] = "Pump <strong>" . htmlspecialchars($pump_number) . "</strong> has been created successfully.";  } catch (Exception $e) {  if ($pdo->inTransaction()) $pdo->rollBack();  $_SESSION['error'] = "Error adding pump: " . $e->getMessage();  }  }  header("Location: admin_pump_master_oversight.php?" . http_build_query($_GET));  exit;  }  // 2. EDIT PUMP  elseif ($action === 'edit_pump') {  $pump_id  = (int)($_POST['pump_id'] ?? 0);  $target_station = ($role === 'superadmin') ? (int)$_POST['station_id'] : $station_id;  $pump_number  = trim($_POST['pump_number'] ?? '');  $fuel_type_id  = (int)($_POST['fuel_type_id'] ?? 0);  $capacity  = (float)($_POST['capacity'] ?? 0);  $status  = trim($_POST['status'] ?? 'Active');  if ($pump_id <= 0) {  $_SESSION['error'] = 'Invalid pump ID.';  } elseif ($target_station <= 0) {  $_SESSION['error'] = 'Please select a valid station.';  } elseif (empty($pump_number)) {  $_SESSION['error'] = 'Pump name/number is required.';  } elseif ($fuel_type_id <= 0) {  $_SESSION['error'] = 'Please select a fuel type.';  } else {  try {  $pdo->beginTransaction();  // Verify pump exists  $chk_exist = $pdo->prepare("SELECT * FROM fuel_pumps WHERE id = ?");  $chk_exist->execute([$pump_id]);  $existing = $chk_exist->fetch(PDO::FETCH_ASSOC);  if (!$existing) {  throw new Exception("Pump not found.");  }  // Check for duplicate pump name/number at target station  $chk_dup = $pdo->prepare("SELECT id FROM fuel_pumps WHERE station_id = ? AND LOWER(pump_number) = LOWER(?) AND id != ?");  $chk_dup->execute([$target_station, $pump_number, $pump_id]);  if ($chk_dup->fetch()) {  throw new Exception("Another pump with that name/number already exists at this station.");  }  $stmt = $pdo->prepare("  UPDATE fuel_pumps  SET station_id = ?, pump_number = ?, fuel_type_id = ?, capacity = ?, status = ?  WHERE id = ?  ");  $stmt->execute([$target_station, $pump_number, $fuel_type_id, $capacity, $status, $pump_id]);  log_activity($pdo, $me['id'], 'Edit Pump', "Updated pump ID {$pump_id} details (Number: {$pump_number})");  write_audit_log($pdo, 'Edit Pump', "Updated pump ID {$pump_id} details", 'fuel_pumps', $pump_id, 'system', 'Success');  $pdo->commit();  $_SESSION['success'] = "Pump <strong>" . htmlspecialchars($pump_number) . "</strong> updated successfully.";  } catch (Exception $e) {  if ($pdo->inTransaction()) $pdo->rollBack();  $_SESSION['error'] = "Error updating pump: " . $e->getMessage();  }  }  header("Location: admin_pump_master_oversight.php?" . http_build_query($_GET));  exit;  }  // 4. ACTIVATE / DEACTIVATE STATUS TOGGLE  elseif (in_array($action, ['activate', 'deactivate'])) {  $pump_id = (int)($_POST['pump_id'] ?? 0);  $new_status = ($action === 'activate') ? 'Active' : 'Inactive';  try {  $pdo->beginTransaction();  $stmt = $pdo->prepare("SELECT * FROM fuel_pumps WHERE id = ?");  $stmt->execute([$pump_id]);  $pump = $stmt->fetch(PDO::FETCH_ASSOC);  if (!$pump) {  throw new Exception("Pump not found.");  }  // Verify station access if not superadmin  if ($role !== 'superadmin' && (int)$pump['station_id'] !== $station_id) {  throw new Exception("Access denied to this station's pump.");  }  $up = $pdo->prepare("UPDATE fuel_pumps SET status = ? WHERE id = ?");  $up->execute([$new_status, $pump_id]);  log_activity($pdo, $me['id'], ucfirst($action) . ' Pump', "Set status of pump ID {$pump_id} ({$pump['pump_number']}) to {$new_status}");  write_audit_log($pdo, ucfirst($action) . ' Pump', "Set status of pump ID {$pump_id} to {$new_status}", 'fuel_pumps', $pump_id, 'system', 'Success');  $pdo->commit();  $_SESSION['success'] = "Pump <strong>" . htmlspecialchars($pump['pump_number']) . "</strong> is now " . strtolower($new_status) . ".";  } catch (Exception $e) {  if ($pdo->inTransaction()) $pdo->rollBack();  $_SESSION['error'] = "Error toggling pump status: " . $e->getMessage();  }  header("Location: admin_pump_master_oversight.php?" . http_build_query($_GET));  exit;  }  // 5. UPDATE CALIBRATION  elseif ($action === 'update_calibration') {  $pump_id  = (int)($_POST['pump_id'] ?? 0);  $calibration_value  = (float)($_POST['calibration_value'] ?? 0);  $reason  = trim($_POST['reason'] ?? '');  if ($pump_id <= 0) {  $_SESSION['error'] = 'Invalid pump ID.';  } elseif (empty($reason)) {  $_SESSION['error'] = 'Reason is required for calibration updates.';  } else {  try {  $pdo->beginTransaction();  // Fetch pump  $stmt = $pdo->prepare("  SELECT fp.*, ft.name as fuel_type_name  FROM fuel_pumps fp  LEFT JOIN fuel_types ft ON fp.fuel_type_id = ft.id  WHERE fp.id = ?  ");  $stmt->execute([$pump_id]);  $pump = $stmt->fetch(PDO::FETCH_ASSOC);  if (!$pump) {  throw new Exception("Pump not found.");  }  // Verify station access if not superadmin  $target_station = (int)$pump['station_id'];  if ($role !== 'superadmin' && $target_station !== $station_id) {  throw new Exception("Access denied to this station's pump.");  }  $previous_cal = (float)$pump['calibration_value'];  $difference  = $calibration_value - $previous_cal;  // Update fuel_pumps record  $up = $pdo->prepare("  UPDATE fuel_pumps  SET calibration_value = ?,  calibration_updated_at = NOW(),  calibration_updated_by = ?,  calibration_notes = ?  WHERE id = ?  ");  $up->execute([$calibration_value, $me['id'], $reason, $pump_id]);  // Log to pump_calibration_history  $ins_history = $pdo->prepare("  INSERT INTO pump_calibration_history  (pump_id, station_id, calibration_value, previous_value, reason, performed_by, performed_at)  VALUES (?, ?, ?, ?, ?, ?, NOW())  ");  $ins_history->execute([  $pump_id,  $target_station,  $calibration_value,  $previous_cal,  "Pump " . $pump['pump_number'] . ": " . $reason,  $me['id']  ]);  // Insert into fuel_adjustments  $adj_notes = "Calibration adjustment for Pump " . $pump['pump_number'] . " (" . $pump['fuel_type_name'] . "). Reason: " . $reason;  // Fetch current tank level (fuel inventory level) for the active pump's fuel type  $tank_level = 0.0;  try {  $invStmt = $pdo->prepare("SELECT COALESCE(current_stock, current_level, 0) FROM fuel_inventory WHERE station_id = ? AND fuel_type_id = ? LIMIT 1");  $invStmt->execute([$target_station, $pump['fuel_type_id']]);  $tank_level = (float)($invStmt->fetchColumn() ?: 0.0);  } catch (Exception $e) {}  $ins_adj = $pdo->prepare("  INSERT INTO fuel_adjustments  (station_id, adjustment_date, fuel_type, fuel_type_id, adjustment_type, liters, previous_value, new_value, reason, user_id, status, created_at)  VALUES (?, CURDATE(), ?, ?, 'Calibration', ?, ?, ?, ?, ?, 'Approved', NOW())  ");  $ins_adj->execute([  $target_station,  $pump['fuel_type_name'],  $pump['fuel_type_id'],  $difference,  $tank_level,  $tank_level,  $adj_notes,  $me['id']  ]);  // Sync with fuel_inventory latest_calibration value  $up_inv = $pdo->prepare("  UPDATE fuel_inventory  SET latest_calibration = ?, last_updated = NOW()  WHERE station_id = ? AND fuel_type_id = ?  ");  $up_inv->execute([$calibration_value, $target_station, $pump['fuel_type_id']]);  log_activity($pdo, $me['id'], 'Update Calibration', "Updated pump {$pump['pump_number']} calibration to {$calibration_value} L. Change: {$difference} L.");  write_audit_log($pdo, 'Update Calibration', "Updated pump {$pump['pump_number']} calibration to {$calibration_value} L", 'fuel_pumps', $pump_id, 'system', 'Success');  $pdo->commit();  $_SESSION['success'] = "Calibration for Pump <strong>" . htmlspecialchars($pump['pump_number']) . "</strong> updated successfully.";  } catch (Exception $e) {  if ($pdo->inTransaction()) $pdo->rollBack();  $_SESSION['error'] = "Error updating calibration: " . $e->getMessage();  }  }  header("Location: admin_pump_master_oversight.php?" . http_build_query($_GET));  exit;  }
}

// ── GET Filters ──────────────────────────────────────────────
$fuel_type_filter  = trim($_GET['fuel_type'] ?? '');
$pump_status_filter = trim($_GET['pump_status'] ?? '');
$assigned_tank_ft  = trim($_GET['assigned_tank'] ?? ''); // Assigned Tank filter (filters by fuel type name)
$export  = trim($_GET['export'] ?? '');

// Base SQL conditions
$where = [];
$params = [];

if ($filter_station > 0) {  $where[] = "fp.station_id = ?";  $params[] = $filter_station;
}

// Fuel Type Filter
if ($fuel_type_filter !== '') {  $where[] = "ft.name = ?";  $params[] = $fuel_type_filter;
}

// Assigned Tank Filter (filters by same fuel type name)
if ($assigned_tank_ft !== '') {  $where[] = "ft.name = ?";  $params[] = $assigned_tank_ft;
}

// Pump Status Filter
if ($pump_status_filter !== '') {  $where[] = "LOWER(fp.status) = ?";  $params[] = strtolower($pump_status_filter);
}

$where_sql = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";

// ── Fetch Pumps ──────────────────────────────────────────────
$pumps = [];
try {  $sql = "  SELECT fp.*,  ft.name as fuel_type_name,  fi.capacity as tank_capacity,  fi.current_stock as tank_stock,  s.name as station_name,  COALESCE(  NULLIF(CONCAT(TRIM(COALESCE(u.first_name, '')), ' ', TRIM(COALESCE(u.last_name, ''))), ' '),  u.username,  '—'  ) as updated_by_name  FROM fuel_pumps fp  LEFT JOIN fuel_types ft ON fp.fuel_type_id = ft.id  LEFT JOIN fuel_inventory fi ON fp.fuel_type_id = fi.fuel_type_id AND fi.station_id = fp.station_id  LEFT JOIN stations s ON fp.station_id = s.id  LEFT JOIN users u ON fp.calibration_updated_by = u.id  $where_sql  ORDER BY s.name ASC, fp.pump_number ASC, fp.id ASC  ";  $stmt = $pdo->prepare($sql);  $stmt->execute($params);  $pumps = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {  $_SESSION['error'] = "Error fetching pumps: " . $e->getMessage();
}

// ── Fetch summary metrics (scoped by station) ────────────────
$total_pumps = 0;
$active_pumps = 0;
$inactive_pumps = 0;
$req_calibration = 0;
$cal_updates_month = 0;

try {  // Total Pumps  $sp = $pdo->prepare("SELECT COUNT(*) FROM fuel_pumps WHERE $station_condition");  $sp->execute($station_params);  $total_pumps = (int)$sp->fetchColumn();  // Active  $sa = $pdo->prepare("SELECT COUNT(*) FROM fuel_pumps WHERE $station_condition AND status = 'Active'");  $sa->execute($station_params);  $active_pumps = (int)$sa->fetchColumn();  // Inactive  $si = $pdo->prepare("SELECT COUNT(*) FROM fuel_pumps WHERE $station_condition AND status = 'Inactive'");  $si->execute($station_params);  $inactive_pumps = (int)$si->fetchColumn();  // Pumps requiring calibration: calibration_value is NULL or 0.00 or calibration_updated_at is NULL  $sc = $pdo->prepare("SELECT COUNT(*) FROM fuel_pumps WHERE $station_condition AND (calibration_value IS NULL OR calibration_value = 0.00 OR calibration_updated_at IS NULL)");  $sc->execute($station_params);  $req_calibration = (int)$sc->fetchColumn();  // Monthly updates count in pump_calibration_history  $sm = $pdo->prepare("  SELECT COUNT(*)  FROM pump_calibration_history  WHERE $station_condition  AND MONTH(performed_at) = MONTH(CURRENT_DATE())  AND YEAR(performed_at) = YEAR(CURRENT_DATE())  ");  $sm->execute($station_params);  $cal_updates_month = (int)$sm->fetchColumn();
} catch (Exception $e) {}

// ── Fetch dynamic fuel types for dropdown ────────────────────
$fuel_types = [];
try {  $fuel_types = $pdo->query("SELECT id, name FROM fuel_types ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Fetch active stations ────────────────────────────────────
$stations = [];
try {  $stations = $pdo->query("SELECT id, name FROM stations WHERE status = 'Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Status classes and labels helper functions ───────────────
if (!function_exists('getStatusBadgeClass')) {  function getStatusBadgeClass($status) {  $s = strtolower(trim($status ?? ''));  if ($s === 'active') return 'bg-green';  if ($s === 'inactive') return 'bg-red';  if ($s === 'maintenance') return 'bg-amber';  return 'bg-gray';  }
}
if (!function_exists('getStatusLabel')) {  function getStatusLabel($status) {  $s = strtolower(trim($status ?? ''));  if ($s === 'active') return 'Active';  if ($s === 'inactive') return 'Inactive';  if ($s === 'maintenance') return 'Maintenance';  return ucfirst($status);  }
}

// ── EXPORTS ──────────────────────────────────────────────────
if (in_array($export, ['excel', 'pdf'])) {  $filename = 'pump_master_report_' . date('Ymd_His');  if ($export === 'excel') {  header('Content-Type: application/vnd.ms-excel; charset=utf-8');  header('Content-Disposition: attachment; filename="' . $filename . '.xls"');  ?>  <html xmlns:x="urn:schemas-microsoft-com:office:excel">  <head>  <meta charset="UTF-8">  <style>  table { border-collapse: collapse; }  th, td { border: 1px solid #cbd5e1; padding: 8px; font-size: 11px; }  th { background-color: #002F70; color: #ffffff; font-weight: bold; }  </style>  </head>  <body>  <h2>Pump Master Oversight Report</h2>  <p>Generated: <?= date('M d, Y H:i A') ?></p>  <table>  <thead>  <tr>  <th>Fuel Type</th>  <th>Assigned Tank</th>  <th>Calibration Value</th>  <th>Status</th>  <th>Last Updated</th>  <th>Updated By</th>  </tr>  </thead>  <tbody>  <?php foreach ($pumps as $p):  $tank_lbl = ($p['fuel_type_name'] ?? '—') . ' Tank (Cap: ' . number_format($p['tank_capacity'] ?? 0, 0) . ' L)';  $cal_val = (float)($p['calibration_value'] ?? 0);  $cal_str = ($cal_val >= 0 ? '+' : '') . number_format($cal_val, 3) . ' L';  ?>  <tr>  <td><?= htmlspecialchars($p['fuel_type_name'] ?? '—') ?></td>  <td><?= htmlspecialchars($tank_lbl) ?></td>  <td><?= $cal_str ?></td>  <td><?= htmlspecialchars($p['status']) ?></td>  <td><?= $p['calibration_updated_at'] ? date('M d, Y H:i', strtotime($p['calibration_updated_at'])) : '—' ?></td>  <td><?= htmlspecialchars($p['updated_by_name'] ?? '—') ?></td>  </tr>  <?php endforeach; ?>  </tbody>  </table>  </body>  </html>  <?php  exit;  }  if ($export === 'pdf') {  ?>  <!DOCTYPE html>  <html>  <head>  <meta charset="UTF-8">  <title>Pump Master Oversight Report</title>  <style>  body { font-family: Arial, sans-serif; font-size: 11px; color: #333; line-height: 1.4; padding: 20px; }  .header { border-bottom: 2px solid #002f6c; padding-bottom: 10px; margin-bottom: 15px; }  .logo-text { font-size: 18px; font-weight: bold; color: #002f6c; text-transform: uppercase; }  .rpt-title { font-size: 13px; font-weight: bold; margin-top: 5px; color: #555; }  .meta-table { width: 100%; margin-bottom: 15px; font-size: 10px; }  .data-table { width: 100%; border-collapse: collapse; margin-top: 10px; }  .data-table th, .data-table td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; }  .data-table th { background-color: #002f6c; color: white; font-weight: bold; text-transform: uppercase; font-size: 9px; }  .data-table tr:nth-child(even) { background-color: #f9f9f9; }  .footer { margin-top: 30px; border-top: 1px solid #ddd; padding-top: 8px; font-size: 8px; color: #777; display: flex; justify-content: space-between; }  @media print { .no-print { display: none; } }  </style>  </head>  <body onload="window.print()">  <div class="no-print" style="margin-bottom: 15px; text-align: center;">  <button onclick="window.print()" style="padding: 6px 12px; cursor: pointer; font-weight: bold;">Print PDF</button>  <button onclick="window.close()" style="padding: 6px 12px; cursor: pointer; margin-left: 5px;">Close Window</button>  </div>  <div class="header">  <div class="logo-text">Petron Corporation</div>  <div class="rpt-title">Pump Master Oversight Summary</div>  </div>  <table class="meta-table">  <tr>  <td><strong>Station:</strong> <?= ($filter_station > 0 && isset($pumps[0]['station_name'])) ? htmlspecialchars($pumps[0]['station_name']) : 'All Stations' ?></td>  <td style="text-align: right;"><strong>Run Date:</strong> <?= date('M d, Y H:i A') ?></td>  </tr>  <tr>  <td><strong>Generated By:</strong> <?= htmlspecialchars($me['username']) ?> (<?= htmlspecialchars($role) ?>)</td>  <td style="text-align: right;"><strong>Total Pumps Loaded:</strong> <?= count($pumps) ?></td>  </tr>  </table>  <table class="data-table">  <thead>  <tr>  <th>Fuel Type</th>  <th>Assigned Tank</th>  <th style="text-align:right;">Calibration</th>  <th>Status</th>  <th>Last Updated</th>  <th>Updated By</th>  </tr>  </thead>  <tbody>  <?php foreach ($pumps as $p):  $tank_lbl = ($p['fuel_type_name'] ?? '—') . ' Tank (Cap: ' . number_format($p['tank_capacity'] ?? 0, 0) . ' L)';  $cal_val = (float)($p['calibration_value'] ?? 0);  $cal_str = ($cal_val >= 0 ? '+' : '') . number_format($cal_val, 3) . ' L';  ?>  <tr>  <td><?= htmlspecialchars($p['fuel_type_name'] ?? '—') ?></td>  <td><?= htmlspecialchars($tank_lbl) ?></td>  <td style="text-align:right; font-family:monospace;"><?= $cal_str ?></td>  <td><?= htmlspecialchars($p['status']) ?></td>  <td><?= $p['calibration_updated_at'] ? date('M d, Y', strtotime($p['calibration_updated_at'])) : '—' ?></td>  <td><?= htmlspecialchars($p['updated_by_name'] ?? '—') ?></td>  </tr>  <?php endforeach; ?>  </tbody>  </table>  <div class="footer" style="width:100%; display:flex; justify-content:space-between;">  <span>System Generated Report  Confidential</span>  <span>Page 1 of 1</span>  </div>  </body>  </html>  <?php  exit;  }
}

include __DIR__ . '/../partials/header.php';
?>

<style>
/* == PAGE HEADER - Petron standard == */
.int-head {  display: flex;  align-items: flex-start;  justify-content: space-between;  flex-wrap: wrap;  gap: 12px;  margin-bottom: 20px;  margin-top: -12px !important;
}
.int-head h1 {  font-size: 22px !important;  font-weight: 700 !important;  color: var(--petron-blue, #00264D) !important;  margin: 0 !important;  text-transform: uppercase !important;  display: flex;  align-items: center;  gap: 8px;
}
.int-head .sub {  font-size: 13px;  color: #666;  margin-top: 4px;  text-transform: none !important;
}

/* == SUMMARY CARDS == */
.pmo-cards {  display: grid;  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));  gap: 16px;  margin-bottom: 24px;
}
.pmo-card {  background: #ffffff;  border: 1px solid #e2e8f0;  border-radius: 10px;  padding: 16px;  display: flex;  align-items: center;  justify-content: space-between;  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);  position: relative;  overflow: hidden;
}
.pmo-card-info {  display: flex;  flex-direction: column;
}
.pmo-card-lbl {  font-size: 11px;  font-weight: 700;  color: #64748b;  text-transform: uppercase;  letter-spacing: 0.5px;  margin-bottom: 4px;
}
.pmo-card-val {  font-size: 20px;  font-weight: 700;  color: #1e293b;
}
.pmo-card-icon {  font-size: 24px;  opacity: 0.8;
}

/* Card variants based on colors */
.pmo-card.blue .pmo-card-icon { color: #2563eb; }
.pmo-card.green .pmo-card-icon { color: #16a34a; }
.pmo-card.red .pmo-card-icon { color: #dc2626; }
.pmo-card.yellow .pmo-card-icon { color: #d97706; }
.pmo-card.purple .pmo-card-icon { color: #7c3aed; }

/* == FILTER BAR == */
.pmo-filter {  display: flex;  align-items: flex-end;  gap: 12px;  flex-wrap: wrap;  background: #ffffff;  border: 1px solid #e2e8f0;  border-radius: 10px;  padding: 16px;  margin-bottom: 20px;  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
}
.pmo-fg {  display: flex;  flex-direction: column;  gap: 4px;
}
.pmo-fg label {  font-size: 11px;  font-weight: 700;  color: #475569;  text-transform: uppercase;  letter-spacing: 0.4px;
}
.pmo-fg input, .pmo-fg select {  height: 36px;  padding: 0 12px;  border: 1px solid #cbd5e1;  border-radius: 6px;  font-size: 13px;  color: #1e293b;  background: #ffffff;  outline: none;  box-sizing: border-box;
}
.pmo-fg input:focus, .pmo-fg select:focus {  border-color: var(--petron-blue, #00264D);  box-shadow: 0 0 0 3px rgba(0, 38, 77, 0.1);
}
.pmo-actions {  display: flex;  align-items: center;  gap: 8px;  flex-wrap: wrap;
}

/* Buttons styling - White background with Petron Blue outline */
.pmo-btn {  display: inline-flex;  align-items: center;  gap: 6px;  padding: 0 16px;  border-radius: 6px;  font-size: 13px;  font-weight: 600;  cursor: pointer;  border: 1px solid transparent;  text-decoration: none;  transition: all 0.15s ease-in-out;  height: 36px;  white-space: nowrap;  background: #ffffff !important;
}
.pmo-btn-filter { color: #002F70 !important; border-color: #002F70 !important; }
.pmo-btn-filter:hover { background: #002F70 !important; color: #ffffff !important; }
.pmo-btn-add { color: #2563eb !important; border-color: #2563eb !important; }
.pmo-btn-add:hover { background: #2563eb !important; color: #ffffff !important; }
.pmo-btn-export { color: #16a34a !important; border-color: #16a34a !important; }
.pmo-btn-export:hover { background: #16a34a !important; color: #ffffff !important; }
.pmo-btn-pdf { color: #dc2626 !important; border-color: #dc2626 !important; }
.pmo-btn-pdf:hover { background: #dc2626 !important; color: #ffffff !important; }
.pmo-btn-reset { color: #4b5563 !important; border-color: #9ca3af !important; }
.pmo-btn-reset:hover { background: #6b7280 !important; color: #ffffff !important; }

/* == TABLE CARD == */
.tbl-card {  background: #ffffff;  border: 1px solid #e2e8f0;  border-radius: 10px;  overflow: hidden;  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);  margin-bottom: 24px;
}
.tbl-hd {  display: flex;  align-items: center;  justify-content: space-between;  padding: 14px 16px;  border-bottom: 1px solid #e9ecef;  flex-wrap: wrap;  gap: 8px;  background: #f8fafc;
}
.tbl-title {  font-size: 14px;  font-weight: 700;  color: #00264D;  display: flex;  align-items: center;  gap: 8px;
}
.pmo-tbl {  width: 100%;  border-collapse: collapse;  font-size: 12px;  text-align: left;
}
.pmo-tbl thead tr {  background: #002F70;
}
.pmo-tbl thead th {  padding: 10px 12px;  font-weight: 700;  color: #ffffff;  text-transform: uppercase;  letter-spacing: 0.4px;  font-size: 11px;  border-bottom: 2px solid #001a3d;
}
.pmo-tbl tbody tr {  border-bottom: 1px solid #f1f5f9;  transition: background 0.1s ease;
}
.pmo-tbl tbody tr:hover td {  background: #f8fafc;
}
.pmo-tbl tbody td {  padding: 10px 12px;  color: #334155;  vertical-align: middle;
}

/* Numeric alignment */
.align-right { text-align: right; font-family: monospace; }
.bold-vol { font-weight: 700; color: #002F6C; }
.var-pos { color: #16a34a !important; }
.var-neg { color: #dc2626 !important; }
.var-zero { color: #64748b !important; }

/* Status Badges */
.badge-lbl {  display: inline-block;  padding: 3px 8px;  border-radius: 12px;  font-size: 10px;  font-weight: 700;  text-transform: uppercase;  text-align: center;  white-space: nowrap;
}
.bg-amber { background-color: #fef3c7; color: #b45309; }
.bg-green { background-color: #dcfce7; color: #15803d; }
.bg-red  { background-color: #fee2e2; color: #b91c1c; }
.bg-gray  { background-color: #f1f5f9; color: #475569; }

/* Row Action Box and Buttons */
.action-box {  display: flex;  flex-direction: column;  gap: 4px;  align-items: stretch;
}
.action-btn {  display: inline-flex;  align-items: center;  gap: 6px;  height: 24px;  padding: 0 10px;  border-radius: 4px;  font-size: 11px;  font-weight: 600;  cursor: pointer;  border: 1px solid transparent;  text-decoration: none;  transition: all 0.15s ease-in-out;  background: #ffffff !important;  box-sizing: border-box;
}
.action-btn-edit { color: #1d4ed8 !important; border-color: #bfdbfe !important; }
.action-btn-edit:hover { background: #eff6ff !important; border-color: #1d4ed8 !important; }
.action-btn-calibrate { color: #16a34a !important; border-color: #bbf7d0 !important; }
.action-btn-calibrate:hover { background: #f0fdf4 !important; border-color: #16a34a !important; }
.action-btn-history { color: #4b5563 !important; border-color: #cbd5e1 !important; }
.action-btn-history:hover { background: #f8fafc !important; border-color: #4b5563 !important; }
.action-btn-status-act { color: #047857 !important; border-color: #a7f3d0 !important; }
.action-btn-status-act:hover { background: #ecfdf5 !important; border-color: #047857 !important; }
.action-btn-status-deact { color: #ea580c !important; border-color: #ffedd5 !important; }
.action-btn-status-deact:hover { background: #fff7ed !important; border-color: #ea580c !important; }
.action-btn-delete { color: #dc2626 !important; border-color: #fecaca !important; }
.action-btn-delete:hover { background: #fef2f2 !important; border-color: #dc2626 !important; }

/* Empty state */
.empty-state {  padding: 40px 16px;  text-align: center;  color: #94a3b8;
}
.empty-state i {  font-size: 32px;  margin-bottom: 8px;  display: block;  opacity: 0.5;
}

/* Modals Overlay */
.modal-overlay {  display: none;  position: fixed;  top: 0;  left: 0;  width: 100%;  height: 100%;  background: rgba(0, 0, 0, 0.4);  z-index: 999;  align-items: center;  justify-content: center;
}
.modal-overlay.active {  display: flex;
}
.modal-box {  background: #ffffff;  border-radius: 8px;  width: 100%;  max-width: 500px;  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);  overflow: hidden;  animation: modalShow 0.15s ease-out;
}
@keyframes modalShow {  from { transform: scale(0.95); opacity: 0; }  to { transform: scale(1); opacity: 1; }
}
.modal-header {  background: #002F70;  color: #ffffff;  padding: 14px 16px;  font-weight: 700;  display: flex;  align-items: center;  justify-content: space-between;
}
.modal-header h3 {  margin: 0;  font-size: 14px;  text-transform: uppercase;
}
.modal-header .close {  cursor: pointer;  font-size: 18px;  font-weight: bold;
}
.modal-body {  padding: 16px;  max-height: 450px;  overflow-y: auto;
}
.modal-footer {  padding: 12px 16px;  border-top: 1px solid #e2e8f0;  display: flex;  justify-content: flex-end;  gap: 8px;  background: #f8fafc;
}
.modal-form-row {  margin-bottom: 12px;
}
.modal-form-row label {  display: block;  font-size: 11px;  font-weight: 700;  color: #475569;  text-transform: uppercase;  margin-bottom: 4px;
}
.modal-form-row input, .modal-form-row select, .modal-form-row textarea {  width: 100%;  padding: 8px 10px;  border: 1px solid #cbd5e1;  border-radius: 4px;  font-size: 13px;  box-sizing: border-box;
}
.modal-form-row textarea {  height: 60px;  resize: none;
}
.modal-btn {  padding: 8px 16px;  border-radius: 4px;  font-size: 12px;  font-weight: 600;  cursor: pointer;  border: 1px solid transparent;
}
.modal-btn-primary { background: #002F70; color: #ffffff; }
.modal-btn-primary:hover { background: #001f4d; }
.modal-btn-secondary { background: #e2e8f0; color: #475569; border-color: #cbd5e1; }
.modal-btn-secondary:hover { background: #cbd5e1; }

.detail-row {  display: flex;  justify-content: space-between;  padding: 8px 0;  border-bottom: 1px solid #f1f5f9;
}
.detail-row:last-child {  border-bottom: none;
}
.detail-lbl {  font-weight: 700;  color: #64748b;  font-size: 11px;  text-transform: uppercase;
}
.detail-val {  color: #1e293b;  font-size: 12px;
}
</style>

<!-- == TOP HEADER == -->
<div class="int-head">  <div>  <h1><i class="fas fa-gas-pump"></i> Calibration Oversight</h1>  <div class="sub">Central monitoring, maintenance, calibration adjustments, and status controls for all fuel pumps</div>  </div>  <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; justify-content: flex-end;">  <form method="post" style="display: inline;">  <?php foreach ($_GET as $k => $v): if ($k !== 'export'): ?>  <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">  <?php endif; endforeach; ?>  <button type="submit" name="export" value="excel" class="pmo-btn pmo-btn-export"><i class="fas fa-file-excel"></i> Excel</button>  </form>  <form method="post" style="display: inline;">  <?php foreach ($_GET as $k => $v): if ($k !== 'export'): ?>  <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">  <?php endif; endforeach; ?>  <button type="submit" name="export" value="pdf" class="pmo-btn pmo-btn-pdf" target="_blank"><i class="fas fa-file-pdf"></i> PDF</button>  </form>  </div>
</div>

<!-- == ALERTS == -->
<?php require __DIR__ . '/../partials/flash_toast.php'; ?>

<!-- == SUMMARY CARDS == -->
<div class="pmo-cards">  <!-- Total Pumps Card -->  <div class="pmo-card blue">  <div class="pmo-card-info">  <span class="pmo-card-lbl">Total Pumps</span>  <span class="pmo-card-val"><?= number_format($total_pumps) ?></span>  </div>  <div class="pmo-card-icon"><i class="fas fa-gas-pump"></i></div>  </div>  <!-- Active Pumps Card -->  <div class="pmo-card green">  <div class="pmo-card-info">  <span class="pmo-card-lbl">Active Pumps</span>  <span class="pmo-card-val"><?= number_format($active_pumps) ?></span>  </div>  <div class="pmo-card-icon"><i class="fas fa-toggle-on"></i></div>  </div>  <!-- Inactive Pumps Card -->  <div class="pmo-card red">  <div class="pmo-card-info">  <span class="pmo-card-lbl">Inactive Pumps</span>  <span class="pmo-card-val"><?= number_format($inactive_pumps) ?></span>  </div>  <div class="pmo-card-icon"><i class="fas fa-toggle-off"></i></div>  </div>  <!-- Pumps Requiring Calibration Card -->  <div class="pmo-card yellow">  <div class="pmo-card-info">  <span class="pmo-card-lbl">Pumps Requiring Calibration</span>  <span class="pmo-card-val"><?= number_format($req_calibration) ?></span>  </div>  <div class="pmo-card-icon"><i class="fas fa-balance-scale"></i></div>  </div>  <!-- Calibration Updates This Month Card -->  <div class="pmo-card purple">  <div class="pmo-card-info">  <span class="pmo-card-lbl">Calibration Updates This Month</span>  <span class="pmo-card-val"><?= number_format($cal_updates_month) ?></span>  </div>  <div class="pmo-card-icon"><i class="fas fa-history"></i></div>  </div>
</div>

<!-- == FILTERS == -->
<form method="get" class="pmo-filter">  <!-- Station Dropdown (SuperAdmin only) -->  <?php if ($role === 'superadmin'): ?>  <div class="pmo-fg">  <label>Station</label>  <select name="station" onchange="this.form.submit()">  <option value="0">All Stations</option>  <?php foreach ($stations as $st): ?>  <option value="<?= $st['id'] ?>" <?= $filter_station === (int)$st['id'] ? 'selected' : '' ?>>  <?= htmlspecialchars($st['name']) ?>  </option>  <?php endforeach; ?>  </select>  </div>  <?php endif; ?>  <div class="pmo-fg">  <label>Fuel Type</label>  <select name="fuel_type" onchange="this.form.submit()">  <option value="">All Fuel Types</option>  <?php foreach ($fuel_types as $ft): ?>  <option value="<?= htmlspecialchars($ft['name']) ?>" <?= $fuel_type_filter === $ft['name'] ? 'selected' : '' ?>>  <?= htmlspecialchars($ft['name']) ?>  </option>  <?php endforeach; ?>  </select>  </div>  <div class="pmo-fg">  <label>Pump Status</label>  <select name="pump_status" onchange="this.form.submit()">  <option value="">All Statuses</option>  <option value="active" <?= $pump_status_filter === 'active' ? 'selected' : '' ?>>Active</option>  <option value="inactive" <?= $pump_status_filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>  <option value="maintenance" <?= $pump_status_filter === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>  </select>  </div>  <!-- Assigned Tank Dropdown -->  <div class="pmo-fg">  <label>Assigned Tank</label>  <select name="assigned_tank" onchange="this.form.submit()">  <option value="">All Tanks</option>  <?php foreach ($fuel_types as $ft): ?>  <option value="<?= htmlspecialchars($ft['name']) ?>" <?= $assigned_tank_ft === $ft['name'] ? 'selected' : '' ?>>  <?= htmlspecialchars($ft['name']) ?> Tank  </option>  <?php endforeach; ?>  </select>  </div>  <div class="pmo-actions">  <button type="submit" class="pmo-btn pmo-btn-filter"><i class="fas fa-filter"></i> Filter</button>  <a href="admin_pump_master_oversight.php" class="pmo-btn pmo-btn-reset"><i class="fas fa-sync-alt"></i> Reset</a>  </div>
</form>

<!-- == DETAILS TABLE == -->
<div class="tbl-card">  <div class="tbl-hd">  <span class="tbl-title"><i class="fas fa-list"></i> Fuel Pump Inventory</span>  <span style="font-size:11px;color:#64748b;font-weight:600;">Showing <?= count($pumps) ?> record(s)</span>  </div>  <div style="overflow:hidden;">  <table class="pmo-tbl">  <colgroup>  <col style="width:15%">  <col style="width:25%">  <col style="width:15%">  <col style="width:15%">  <col style="width:15%">  <col style="width:15%">  </colgroup>  <thead>  <tr>  <th>Fuel Type</th>  <th>Assigned Tank</th>  <th class="align-right">Calibration Value</th>  <th>Status</th>  <th>Last Updated</th>  <th>Updated By</th>  </tr>  </thead>  <tbody>  <?php if (empty($pumps)): ?>  <tr>  <td colspan="6">  <div class="empty-state">  <i class="fas fa-inbox"></i>  No pump records found matching the filter criteria.  </div>  </td>  </tr>  <?php else: ?>  <?php foreach ($pumps as $p):  $cal_val = (float)($p['calibration_value'] ?? 0);  $cal_class = $cal_val == 0 ? 'var-zero' : ($cal_val > 0 ? 'var-pos' : 'var-neg');  $cal_str = ($cal_val >= 0 ? '+' : '') . number_format($cal_val, 3) . ' L';  $tank_lbl = ($p['fuel_type_name'] ?? '—') . ' Tank (Cap: ' . number_format($p['tank_capacity'] ?? 0, 0) . ' L)';  ?>  <tr>  <td class="bold-vol"><?= htmlspecialchars($p['fuel_type_name'] ?? '—') ?></td>  <td><span style="font-size:11px; color:#64748b;"><?= htmlspecialchars($tank_lbl) ?></span></td>  <td class="align-right <?= $cal_class ?>" style="font-weight: bold; font-family: monospace;"><?= $cal_str ?></td>  <td>  <span class="badge-lbl <?= getStatusBadgeClass($p['status']) ?>">  <?= getStatusLabel($p['status']) ?>  </span>  </td>  <td><?= $p['calibration_updated_at'] ? date('M d, Y H:i', strtotime($p['calibration_updated_at'])) : '—' ?></td>  <td><?= htmlspecialchars($p['updated_by_name'] ?? '—') ?></td>  </tr>  <?php endforeach; ?>  <?php endif; ?>  </tbody>  </table>  </div>
</div>

<!-- == MODAL: ADD PUMP == -->
<div id="addPumpModal" class="modal-overlay">  <div class="modal-box">  <form method="post">  <input type="hidden" name="action" value="add_pump">  <div class="modal-header">  <h3>Add New Fuel Pump</h3>  <span class="close" onclick="closeModal('addPumpModal')">&times;</span>  </div>  <div class="modal-body">  <?php if ($role === 'superadmin'): ?>  <div class="modal-form-row">  <label>Station *</label>  <select name="station_id" required>  <option value="">Select Station</option>  <?php foreach ($stations as $st): ?>  <option value="<?= $st['id'] ?>"><?= htmlspecialchars($st['name']) ?></option>  <?php endforeach; ?>  </select>  </div>  <?php endif; ?>  <div class="modal-form-row">  <label>Pump Number / Name *</label>  <input type="text" name="pump_number" required placeholder="e.g. Pump 1">  </div>  <div class="modal-form-row">  <label>Fuel Type *</label>  <select name="fuel_type_id" required>  <option value="">Select Fuel Type</option>  <?php foreach ($fuel_types as $ft): ?>  <option value="<?= $ft['id'] ?>"><?= htmlspecialchars($ft['name']) ?></option>  <?php endforeach; ?>  </select>  </div>  <div class="modal-form-row">  <label>Capacity (Liters)</label>  <input type="number" step="0.01" name="capacity" value="0.00" required>  </div>  <div class="modal-form-row">  <label>Initial Status *</label>  <select name="status" required>  <option value="Active">Active</option>  <option value="Inactive">Inactive</option>  <option value="Maintenance">Maintenance</option>  </select>  </div>  </div>  <div class="modal-footer">  <button type="button" class="modal-btn modal-btn-secondary" onclick="closeModal('addPumpModal')">Cancel</button>  <button type="submit" class="modal-btn modal-btn-primary">Save Pump</button>  </div>  </form>  </div>
</div>

<!-- == MODAL: EDIT PUMP == -->
<div id="editPumpModal" class="modal-overlay">  <div class="modal-box">  <form method="post">  <input type="hidden" name="action" value="edit_pump">  <input type="hidden" name="pump_id" id="edit_pump_id">  <div class="modal-header">  <h3>Edit Fuel Pump Details</h3>  <span class="close" onclick="closeModal('editPumpModal')">&times;</span>  </div>  <div class="modal-body">  <?php if ($role === 'superadmin'): ?>  <div class="modal-form-row">  <label>Station *</label>  <select name="station_id" id="edit_station_id" required>  <option value="">Select Station</option>  <?php foreach ($stations as $st): ?>  <option value="<?= $st['id'] ?>"><?= htmlspecialchars($st['name']) ?></option>  <?php endforeach; ?>  </select>  </div>  <?php endif; ?>  <div class="modal-form-row">  <label>Pump Number / Name *</label>  <input type="text" name="pump_number" id="edit_pump_number" required>  </div>  <div class="modal-form-row">  <label>Fuel Type *</label>  <select name="fuel_type_id" id="edit_fuel_type_id" required>  <option value="">Select Fuel Type</option>  <?php foreach ($fuel_types as $ft): ?>  <option value="<?= $ft['id'] ?>"><?= htmlspecialchars($ft['name']) ?></option>  <?php endforeach; ?>  </select>  </div>  <div class="modal-form-row">  <label>Capacity (Liters)</label>  <input type="number" step="0.01" name="capacity" id="edit_capacity" required>  </div>  <div class="modal-form-row">  <label>Status *</label>  <select name="status" id="edit_status" required>  <option value="Active">Active</option>  <option value="Inactive">Inactive</option>  <option value="Maintenance">Maintenance</option>  </select>  </div>  </div>  <div class="modal-footer">  <button type="button" class="modal-btn modal-btn-secondary" onclick="closeModal('editPumpModal')">Cancel</button>  <button type="submit" class="modal-btn modal-btn-primary">Save Changes</button>  </div>  </form>  </div>
</div>

<!-- == MODAL: UPDATE CALIBRATION == -->
<div id="calibrationModal" class="modal-overlay">  <div class="modal-box">  <form method="post">  <input type="hidden" name="action" value="update_calibration">  <input type="hidden" name="pump_id" id="cal_pump_id">  <div class="modal-header">  <h3>Update Nozzle Calibration</h3>  <span class="close" onclick="closeModal('calibrationModal')">&times;</span>  </div>  <div class="modal-body">  <div class="modal-form-row">  <label>Pump Number / Name</label>  <input type="text" id="cal_pump_name" readonly style="background:#f1f5f9; color:#475569;">  </div>  <div class="modal-form-row">  <label>Fuel Type</label>  <input type="text" id="cal_fuel_type" readonly style="background:#f1f5f9; color:#475569;">  </div>  <div class="modal-form-row">  <label>Previous Calibration Value (Liters)</label>  <input type="text" id="cal_prev_val" readonly style="background:#f1f5f9; color:#475569;">  </div>  <div class="modal-form-row">  <label>New Calibration Value (Liters) *</label>  <input type="number" step="0.001" name="calibration_value" required placeholder="e.g. 0.050 or -0.020">  </div>  <div class="modal-form-row">  <label>Calibration Correction Reason *</label>  <textarea name="reason" required placeholder="Describe the reason (e.g. periodic validation, nozzle correction...)"></textarea>  </div>  </div>  <div class="modal-footer">  <button type="button" class="modal-btn modal-btn-secondary" onclick="closeModal('calibrationModal')">Cancel</button>  <button type="submit" class="modal-btn modal-btn-primary">Save Calibration</button>  </div>  </form>  </div>
</div>

<!-- == MODAL: CALIBRATION HISTORY == -->
<div id="historyModal" class="modal-overlay">  <div class="modal-box" style="max-width: 650px;">  <div class="modal-header">  <h3 id="historyModalTitle">Calibration History</h3>  <span class="close" onclick="closeModal('historyModal')">&times;</span>  </div>  <div class="modal-body">  <div style="overflow-x: auto;">  <table class="pmo-tbl" style="font-size: 11px;">  <thead>  <tr>  <th>Update Date</th>  <th class="align-right">Prev Value</th>  <th class="align-right">New Value</th>  <th class="align-right">Difference</th>  <th>Changed By</th>  <th>Reason / Explanation</th>  </tr>  </thead>  <tbody id="historyTableBody">  <!-- Loaded dynamically via AJAX -->  </tbody>  </table>  </div>  </div>  <div class="modal-footer">  <button class="modal-btn modal-btn-secondary" onclick="closeModal('historyModal')">Close</button>  </div>  </div>
</div>

<script>
function closeModal(id) {  document.getElementById(id).classList.remove('active');
}

// Close modals when clicking outside
document.querySelectorAll('.modal-overlay').forEach(function(overlay) {  overlay.addEventListener('click', function(e) {  if (e.target === overlay) closeModal(overlay.id);  });
});

function openAddPumpModal() {  document.getElementById('addPumpModal').classList.add('active');
}

function openEditPumpModal(p) {  document.getElementById('edit_pump_id').value = p.id;  document.getElementById('edit_pump_number').value = p.pump_number;  document.getElementById('edit_fuel_type_id').value = p.fuel_type_id;  document.getElementById('edit_capacity').value = p.capacity;  document.getElementById('edit_status').value = p.status;  var stationSel = document.getElementById('edit_station_id');  if (stationSel) {  stationSel.value = p.station_id;  }  document.getElementById('editPumpModal').classList.add('active');
}

function openCalibrationModal(id, name, fuelType, prevVal) {  document.getElementById('cal_pump_id').value = id;  document.getElementById('cal_pump_name').value = name;  document.getElementById('cal_fuel_type').value = fuelType;  document.getElementById('cal_prev_val').value = (prevVal >= 0 ? '+' : '') + prevVal.toFixed(3) + ' L';  var form = document.getElementById('calibrationModal').querySelector('form');  form.querySelector('input[name="calibration_value"]').value = '';  form.querySelector('textarea[name="reason"]').value = '';  document.getElementById('calibrationModal').classList.add('active');
}

function viewHistory(pumpId, pumpName) {  document.getElementById('historyModalTitle').textContent = 'Calibration Log – Pump ' + pumpName;  var tbody = document.getElementById('historyTableBody');  tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px;color:#64748b;"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>';  document.getElementById('historyModal').classList.add('active');  fetch('admin_pump_master_oversight.php?ajax_action=get_history&pump_id=' + pumpId)  .then(response => response.json())  .then(data => {  if (data.length === 0) {  tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px;color:#94a3b8;"><i class="fas fa-history"></i> No calibration history found for this pump.</td></tr>';  } else {  var html = '';  data.forEach(l => {  var prev = parseFloat(l.previous_calibration) || 0;  var next = parseFloat(l.new_calibration) || 0;  var diff = next - prev;  var diffStr = (diff >= 0 ? '+' : '') + diff.toFixed(3) + ' L';  var diffColor = diff > 0 ? '#16a34a' : (diff < 0 ? '#dc2626' : '#475569');  var dateVal = new Date(l.updated_at);  var dateStr = dateVal.toLocaleDateString(undefined, {month:'short', day:'numeric', year:'numeric'})  + ' ' + dateVal.toLocaleTimeString(undefined, {hour:'2-digit', minute:'2-digit'});  html += '<tr>'  + '<td>' + dateStr + '</td>'  + '<td class="align-right">' + prev.toFixed(3) + ' L</td>'  + '<td class="align-right">' + next.toFixed(3) + ' L</td>'  + '<td class="align-right" style="font-weight:bold;color:' + diffColor + '">' + diffStr + '</td>'  + '<td>' + (l.updater_name || '—') + '</td>'  + '<td>' + (l.reason || '—') + '</td>'  + '</tr>';  });  tbody.innerHTML = html;  }  })  .catch(err => {  tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px;color:#dc2626;"><i class="fas fa-exclamation-circle"></i> Error loading history.</td></tr>';  });
}
</script>

<?php
include __DIR__ . '/../partials/footer.php';
?>
