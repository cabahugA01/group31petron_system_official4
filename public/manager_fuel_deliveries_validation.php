<?php
// ============================================================
// Manager Fuel Deliveries Validation – manager_fuel_deliveries_validation.php
// Purpose: Validate staff-encoded fuel delivery receipts
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'fuel_deliveries_validation';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();

// Access control - Manager only
if (!in_array($role, ['manager', 'supervisor'])) {
    $_SESSION['error'] = 'Access denied. Manager access required.';
    header('Location: staff_dashboard.php'); 
    exit;
}

if ($station_id <= 0) {
    $_SESSION['error'] = 'No station assigned.';
    header('Location: manager_dashboard.php'); 
    exit;
}

// ── POST Actions ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['export'])) {
    $action = trim($_POST['action'] ?? '');
    $del_id = (int)($_POST['delivery_id'] ?? 0);
    
    try {
        $pdo->beginTransaction();
        
        if ($action === 'approve' && $del_id > 0) {
            // Get delivery details
            $stmt = $pdo->prepare("SELECT * FROM fuel_deliveries WHERE id = ? AND station_id = ?");
            $stmt->execute([$del_id, $station_id]);
            $delivery = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$delivery) {
                throw new Exception("Delivery not found.");
            }
            
            // Check if already processed
            if (!in_array(strtolower($delivery['status']), ['pending', 'pending validation', 'pending manager approval'])) {
                throw new Exception("This delivery has already been processed.");
            }
            
            // Update delivery status
            $stmt = $pdo->prepare("UPDATE fuel_deliveries 
                                   SET status = 'Verified', 
                                       verified_by = ?, 
                                       verified_at = NOW() 
                                   WHERE id = ? AND station_id = ?");
            $stmt->execute([$me['id'], $del_id, $station_id]);
            
            // Update inventory - add liters to tank
            $stmt = $pdo->prepare("UPDATE fuel_inventory 
                                   SET current_level = COALESCE(current_level, 0) + ?,
                                       current_stock = COALESCE(current_stock, 0) + ?,
                                       last_updated = NOW()
                                   WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))");
            $stmt->execute([
                $delivery['delivery_liters'],
                $delivery['delivery_liters'],
                $station_id,
                $delivery['fuel_type']
            ]);
            
            // Log audit trail
            try {
                $pdo->prepare("INSERT INTO audit_logs (user_id, action_type, entity_type, entity_id, details, station_id, ip_address, created_at) 
                               VALUES (?, 'Approve', 'fuel_delivery', ?, ?, ?, ?, NOW())")
                    ->execute([
                        $me['id'], 
                        $del_id, 
                        "Approved delivery of {$delivery['delivery_liters']}L {$delivery['fuel_type']}", 
                        $station_id, 
                        $_SERVER['REMOTE_ADDR'] ?? ''
                    ]);
            } catch (Exception $ae) {}
            
            $_SESSION['success'] = "Delivery #DEL-{$del_id} approved successfully. Tank inventory updated.";
        }
        
        elseif ($action === 'reject' && $del_id > 0) {
            $reason = trim($_POST['reason'] ?? '');
            
            if (empty($reason)) {
                throw new Exception("Return reason is required.");
            }
            
            // Update delivery status
            $stmt = $pdo->prepare("UPDATE fuel_deliveries 
                                   SET status = 'Rejected', 
                                       verified_by = ?, 
                                       verified_at = NOW(),
                                       notes = CONCAT(IFNULL(notes, ''), ' | Manager Returned: ', ?) 
                                   WHERE id = ? AND station_id = ?");
            $stmt->execute([$me['id'], $reason, $del_id, $station_id]);
            
            if ($stmt->rowCount() > 0) {
                // Log audit trail
                try {
                    $pdo->prepare("INSERT INTO audit_logs (user_id, action_type, entity_type, entity_id, details, station_id, ip_address, created_at) 
                                   VALUES (?, 'Reject', 'fuel_delivery', ?, ?, ?, ?, NOW())")
                        ->execute([$me['id'], $del_id, "Reason: {$reason}", $station_id, $_SERVER['REMOTE_ADDR'] ?? '']);
                } catch (Exception $ae) {}
                
                $_SESSION['success'] = "Delivery #DEL-{$del_id} returned to staff for correction.";
            } else {
                $_SESSION['error'] = "Delivery not found or already processed.";
            }
        }
        
        elseif ($action === 'adjust' && $del_id > 0) {
            $new_liters = (float)($_POST['adj_liters'] ?? 0);
            $adj_note   = trim($_POST['adj_note'] ?? '');
            
            if ($new_liters <= 0) {
                throw new Exception("Adjusted liters must be greater than zero.");
            }
            
            if (empty($adj_note)) {
                throw new Exception("Adjustment note is required.");
            }
            
            // Get delivery details
            $stmt = $pdo->prepare("SELECT * FROM fuel_deliveries WHERE id = ? AND station_id = ?");
            $stmt->execute([$del_id, $station_id]);
            $delivery = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$delivery) {
                throw new Exception("Delivery not found.");
            }
            
            $original_liters = $delivery['delivery_liters'];
            
            // Update delivery with adjusted amount
            $stmt = $pdo->prepare("UPDATE fuel_deliveries 
                                   SET status = 'Verified',
                                       delivery_liters = ?,
                                       verified_by = ?, 
                                       verified_at = NOW(),
                                       notes = CONCAT(IFNULL(notes, ''), ' | Adjusted from ', ?, 'L to ', ?, 'L: ', ?) 
                                   WHERE id = ? AND station_id = ?");
            $stmt->execute([$new_liters, $me['id'], $original_liters, $new_liters, $adj_note, $del_id, $station_id]);
            
            // Update inventory with adjusted amount
            $stmt = $pdo->prepare("UPDATE fuel_inventory 
                                   SET current_level = COALESCE(current_level, 0) + ?,
                                       current_stock = COALESCE(current_stock, 0) + ?,
                                       last_updated = NOW()
                                   WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))");
            $stmt->execute([$new_liters, $new_liters, $station_id, $delivery['fuel_type']]);
            
            // Log audit trail
            try {
                $pdo->prepare("INSERT INTO audit_logs (user_id, action_type, entity_type, entity_id, details, station_id, ip_address, created_at) 
                               VALUES (?, 'Adjust', 'fuel_delivery', ?, ?, ?, ?, NOW())")
                    ->execute([
                        $me['id'], 
                        $del_id, 
                        "Adjusted from {$original_liters}L to {$new_liters}L. Note: {$adj_note}", 
                        $station_id, 
                        $_SERVER['REMOTE_ADDR'] ?? ''
                    ]);
            } catch (Exception $ae) {}
            
            $_SESSION['success'] = "Delivery #DEL-{$del_id} adjusted to {$new_liters}L and approved.";
        }
        
        $pdo->commit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
    
    header('Location: manager_fuel_deliveries_validation.php');
    exit;
}

// ── Date Filter ───────────────────────────────────────────
$date_from = trim($_GET['date_from'] ?? date('Y-m-d', strtotime('-6 months'))); // Default to 6 months ago
$date_to   = trim($_GET['date_to']   ?? date('Y-m-d'));  // Default to today

// ── Summary Cards ──────────────────────────────────────────
$validated_count = 0;
$pending_count = 0;

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM fuel_deliveries 
                           WHERE station_id = ? 
                           AND LOWER(status) IN ('verified', 'approved')
                           AND DATE(delivery_date) BETWEEN ? AND ?");
    $stmt->execute([$station_id, $date_from, $date_to]);
    $validated_count = (int)$stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM fuel_deliveries 
                           WHERE station_id = ? 
                           AND LOWER(status) IN ('pending', 'pending validation', 'pending manager approval')
                           AND DATE(delivery_date) BETWEEN ? AND ?");
    $stmt->execute([$station_id, $date_from, $date_to]);
    $pending_count = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    error_log("Summary error: " . $e->getMessage());
}

// ── Pagination ─────────────────────────────────────────────
$rows_per_page = (int)($_GET['rows_per_page'] ?? 10);
if (!in_array($rows_per_page, [10, 25, 50, 100])) {
    $rows_per_page = 10;
}
$current_page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($current_page - 1) * $rows_per_page;

// ── Fetch Deliveries ────────────────────────────────────
$deliveries = [];
$total_records = 0;
try {
    // Get total count
    $count_sql = "SELECT COUNT(*) 
                  FROM fuel_deliveries fd
                  WHERE fd.station_id = ?
                  AND LOWER(fd.status) IN ('pending', 'pending validation', 'pending manager approval', 'discrepancy')
                  AND DATE(fd.delivery_date) BETWEEN ? AND ?";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute([$station_id, $date_from, $date_to]);
    $total_records = (int)$stmt->fetchColumn();
    
    // Get paginated results - Use only first_name/last_name columns
    $sql = "SELECT fd.*, 
                   COALESCE(
                       NULLIF(CONCAT(TRIM(COALESCE(staff.first_name, '')), ' ', TRIM(COALESCE(staff.last_name, ''))), ' '),
                       staff.username,
                       'Unknown'
                   ) as staff_name,
                   COALESCE(
                       NULLIF(CONCAT(TRIM(COALESCE(validator.first_name, '')), ' ', TRIM(COALESCE(validator.last_name, ''))), ' '),
                       validator.username,
                       'Unknown'
                   ) as validator_name,
                   COALESCE(fi.current_level, fi.current_stock, 0) as current_tank_level
            FROM fuel_deliveries fd
            LEFT JOIN users staff ON fd.received_by = staff.id
            LEFT JOIN users validator ON fd.verified_by = validator.id
            LEFT JOIN fuel_inventory fi ON fi.station_id = fd.station_id 
                AND LOWER(TRIM(fi.fuel_type)) = LOWER(TRIM(fd.fuel_type))
            WHERE fd.station_id = ?
            AND LOWER(fd.status) IN ('pending', 'pending validation', 'pending manager approval', 'discrepancy')
            AND DATE(fd.delivery_date) BETWEEN ? AND ?
            ORDER BY fd.delivery_date DESC, fd.created_at DESC
            LIMIT ? OFFSET ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(1, $station_id, PDO::PARAM_INT);
    $stmt->bindValue(2, $date_from, PDO::PARAM_STR);
    $stmt->bindValue(3, $date_to, PDO::PARAM_STR);
    $stmt->bindValue(4, $rows_per_page, PDO::PARAM_INT);
    $stmt->bindValue(5, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Fetch deliveries error: " . $e->getMessage());
    $_SESSION['error'] = "Error loading deliveries: " . $e->getMessage();
}

$total_pages = ceil($total_records / $rows_per_page);

// ── Handle Export ──────────────────────────────────────────
$export = $_GET['export'] ?? '';
if (in_array($export, ['excel', 'csv', 'pdf'])) {
    try {
        $stmt = $pdo->prepare("SELECT fd.*, 
                                      COALESCE(
                                          NULLIF(CONCAT(TRIM(COALESCE(staff.first_name, '')), ' ', TRIM(COALESCE(staff.last_name, ''))), ' '),
                                          staff.username,
                                          'Unknown'
                                      ) as staff_name,
                                      COALESCE(
                                          NULLIF(CONCAT(TRIM(COALESCE(validator.first_name, '')), ' ', TRIM(COALESCE(validator.last_name, ''))), ' '),
                                          validator.username,
                                          'Unknown'
                                      ) as validator_name
                               FROM fuel_deliveries fd
                               LEFT JOIN users staff ON fd.received_by = staff.id
                               LEFT JOIN users validator ON fd.verified_by = validator.id
                               WHERE fd.station_id = ?
                               AND DATE(fd.delivery_date) BETWEEN ? AND ?
                               ORDER BY fd.delivery_date DESC");
        $stmt->execute([$station_id, $date_from, $date_to]);
        $export_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($export === 'csv' || $export === 'excel') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="fuel_deliveries_' . date('Y-m-d') . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Delivery ID', 'Date', 'Fuel Type', 'Supplier', 'Invoice No', 'Liters', 'Tanker No', 'Status', 'Recorded By', 'Verified By']);
            foreach ($export_data as $d) {
                fputcsv($out, [
                    'DEL-' . $d['id'],
                    $d['delivery_date'],
                    $d['fuel_type'],
                    $d['supplier'],
                    $d['invoice_no'],
                    number_format($d['delivery_liters'], 2),
                    $d['tanker_number'] ?? '-',
                    $d['status'],
                    $d['staff_name'] ?? '-',
                    $d['validator_name'] ?? 'Pending'
                ]);
            }
            fclose($out);
            exit;
        } elseif ($export === 'pdf') {
            header('Content-Type: text/html');
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Fuel Deliveries Report</title>';
            echo '<style>body{font-family:Arial,sans-serif;margin:20px}h1{color:#002F70}table{border-collapse:collapse;width:100%;margin-top:20px}th,td{border:1px solid #ddd;padding:8px;text-align:left;font-size:11px}th{background:#002F70;color:#fff}tr:nth-child(even){background:#f9f9f9}.header-info{margin:15px 0;font-size:13px}</style>';
            echo '</head><body>';
            echo '<h1>Fuel Deliveries Validation Report</h1>';
            echo '<div class="header-info"><strong>Station ID:</strong> ' . $station_id . ' | <strong>Period:</strong> ' . $date_from . ' to ' . $date_to . ' | <strong>Generated:</strong> ' . date('Y-m-d H:i:s') . '</div>';
            echo '<table><thead><tr><th>ID</th><th>Date</th><th>Fuel</th><th>Supplier</th><th>Invoice</th><th>Liters</th><th>Status</th><th>Verified By</th></tr></thead><tbody>';
            foreach ($export_data as $d) {
                echo '<tr>';
                echo '<td>DEL-' . $d['id'] . '</td>';
                echo '<td>' . htmlspecialchars($d['delivery_date']) . '</td>';
                echo '<td>' . htmlspecialchars($d['fuel_type']) . '</td>';
                echo '<td>' . htmlspecialchars($d['supplier']) . '</td>';
                echo '<td>' . htmlspecialchars($d['invoice_no']) . '</td>';
                echo '<td>' . number_format($d['delivery_liters'], 2) . ' L</td>';
                echo '<td>' . htmlspecialchars($d['status']) . '</td>';
                echo '<td>' . htmlspecialchars($d['validator_name'] ?? 'Pending') . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></body></html>';
            exit;
        }
    } catch (Exception $e) {
        $_SESSION['error'] = 'Export error: ' . $e->getMessage();
    }
}

require_once __DIR__ . '/../partials/header.php';
?>

<style>
/* Global Fix: NO Horizontal Scroll - ABSOLUTE */
* {
    box-sizing: border-box;
}
html, body {
    max-width: 100%;
    width: 100%;
    overflow-x: hidden !important;
    position: relative;
}

/* Manager Fuel Deliveries Validation Styles */
.mfdv-wrap { 
    padding: 0; 
    max-width: 100%;
    width: 100%;
    overflow-x: hidden !important;
    box-sizing: border-box;
}

.page-head {
    display: flex; align-items: flex-start; justify-content: space-between;
    gap: 12px; margin-bottom: 18px; flex-wrap: wrap;
    max-width: 100%;
}
.page-head h1 {
    margin: 0 0 8px; font-size: 24px !important; font-weight: 700;
    color: #00264D; text-transform: uppercase; letter-spacing: 0.5px;
}
.page-head .sub {
    font-size: 14px; color: #666666; font-weight: 500;
    text-transform: uppercase; letter-spacing: 0.3px;
}

/* Summary Cards */
.summary-row {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 14px; margin-bottom: 18px;
    max-width: 100%;
}
.summary-card {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
    padding: 18px; display: flex; align-items: center; gap: 14px;
    box-shadow: 0 1px 3px rgba(0,0,0,.05);
    min-width: 0;
}
.summary-card.sc-green  { border-left: 4px solid #16a34a; }
.summary-card.sc-amber  { border-left: 4px solid #d97706; }
.sum-ico {
    width: 52px; height: 52px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 24px; flex-shrink: 0;
}
.summary-card.sc-green .sum-ico  { background: #dcfce7; color: #16a34a; }
.summary-card.sc-amber .sum-ico  { background: #fef3c7; color: #d97706; }
.sum-meta { 
    min-width: 0;
    overflow: hidden;
}
.sum-meta h3 { 
    margin: 0; font-size: 11px; color: #64748b; 
    text-transform: uppercase; font-weight: 700; 
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.sum-meta h2 { 
    margin: 4px 0 2px; font-size: 28px; font-weight: 900; 
    color: #00264D; line-height: 1; 
}
.sum-meta span { 
    font-size: 12px; color: #94a3b8; 
    display: block;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Filter Bar */
.filter-bar {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
    padding: 12px 16px; margin-bottom: 18px; display: flex; align-items: flex-end; gap: 10px; flex-wrap: wrap;
    max-width: 100%;
}
.filter-group { display: flex; flex-direction: column; gap: 4px; min-width: 150px; }
.filter-group label { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; }
.filter-group input[type=date] { padding: 6px 10px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 13px; width: 100%; }

/* Table */
.table-card {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
    padding: 18px; box-shadow: 0 1px 3px rgba(0,0,0,.05);
    max-width: 100%;
    overflow: hidden;
    box-sizing: border-box;
}
.table-wrap { 
    width: 100%;
    max-width: 100%;
    overflow-x: hidden;
    box-sizing: border-box;
    display: block;
}
.data-table {
    width: 100%; 
    border-collapse: collapse; 
    font-size: 13px;
    table-layout: fixed;
    box-sizing: border-box;
    display: table;
}
.data-table thead th {
    background: #002F70; padding: 10px 6px; text-align: left;
    font-size: 11px; font-weight: 700; color: #fff;
    text-transform: uppercase; border-bottom: 2px solid #002F70;
    white-space: normal;
    word-wrap: break-word;
    overflow: hidden;
    line-height: 1.3;
}
/* Column widths - optimized for full visibility */
.data-table th:nth-child(1), .data-table td:nth-child(1) { width: 4%; } /* ID */
.data-table th:nth-child(2), .data-table td:nth-child(2) { width: 8%; } /* Date */
.data-table th:nth-child(3), .data-table td:nth-child(3) { width: 8%; } /* Fuel Type */
.data-table th:nth-child(4), .data-table td:nth-child(4) { width: 10%; } /* Supplier */
.data-table th:nth-child(5), .data-table td:nth-child(5) { width: 8%; } /* Invoice # */
.data-table th:nth-child(6), .data-table td:nth-child(6) { width: 7%; } /* Liters */
.data-table th:nth-child(7), .data-table td:nth-child(7) { width: 7%; } /* Tanker # */
.data-table th:nth-child(8), .data-table td:nth-child(8) { width: 11%; } /* Recorded By */
.data-table th:nth-child(9), .data-table td:nth-child(9) { width: 8%; } /* Status */
.data-table th:nth-child(10), .data-table td:nth-child(10) { width: 29%; } /* Actions - increased */
.data-table tbody tr { border-bottom: 1px solid #f1f5f9; }
.data-table tbody tr:hover { background: #e3f2fd; }
.data-table tbody td { 
    padding: 10px 6px; 
    color: #334155; 
    vertical-align: middle;
    word-wrap: break-word;
    overflow: hidden;
    font-size: 13px;
    line-height: 1.4;
}

/* Badges - Plain text, no background */
.badge {
    display: inline-block;
    font-size: 12px; font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.badge-amber { color: #d97706; }

/* Action Buttons */
.action-btn {
    padding: 7px 10px; border-radius: 5px; font-size: 12px; font-weight: 600;
    border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 5px;
    transition: all .15s;
    margin: 3px 0;
    white-space: nowrap;
    line-height: 1.3;
    width: 100%;
    justify-content: center;
}
.action-btn i { font-size: 11px; }
.btn-approve { background: #28a745; color: #fff; }
.btn-approve:hover { background: #218838; }
.btn-reject { background: #dc2626; color: #fff; }
.btn-reject:hover { background: #b91c1c; }
.btn-adjust { background: #002F70; color: #fff; }
.btn-adjust:hover { background: #001a42; }

/* Actions cell layout */
.data-table tbody td:last-child {
    padding: 8px 6px !important;
    vertical-align: middle;
}

.action-buttons-wrapper {
    display: flex;
    flex-direction: column;
    gap: 3px;
    width: 100%;
}

/* ACTION header alignment */
.data-table thead th:last-child {
    text-align: center;
    vertical-align: middle;
}

/* Modal */
.modal {
    display: none; position: fixed; z-index: 9999; left: 0; top: 0;
    width: 100%; height: 100%; background: rgba(0,0,0,.5);
    overflow-y: auto;
}
.modal-content {
    background: #fff; margin: 10% auto; padding: 24px;
    border-radius: 12px; width: 90%; max-width: 500px;
}
.modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.modal-header h3 { margin: 0; font-size: 18px; color: #00264D; }
.modal-close { cursor: pointer; font-size: 24px; color: #94a3b8; }
.modal-close:hover { color: #dc2626; }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px; }
.form-group input, .form-group textarea {
    width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0;
    border-radius: 6px; font-size: 13px;
    box-sizing: border-box;
}
.form-group textarea { min-height: 80px; resize: vertical; }

/* Responsive fixes */
@media (max-width: 768px) {
    .page-head { flex-direction: column; }
    .actions { width: 100%; justify-content: flex-start !important; }
    .summary-row { grid-template-columns: 1fr; }
    .filter-bar { flex-direction: column; align-items: stretch; }
    .filter-group { min-width: 100%; }
}
</style>

<div class="mfdv-wrap">
    <!-- Page Header -->
    <div class="page-head">
        <div>
            <h1>Fuel Deliveries Validation</h1>
            <div class="sub">CHECK AND CONFIRM FUEL DELIVERIES AGAINST PURCHASE ORDERS, ENSURING BATCH IDs AND QUANTITIES MATCH.</div>
        </div>
        <div class="actions" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <!-- Excel -->
            <button type="button"
                    onclick="window.location.href='?date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&export=excel'"
                    style="background:#1d6f42;color:#fff;height:36px;padding:8px 14px;border-radius:8px;border:none;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
                <i class="fas fa-file-excel"></i> Excel
            </button>
            <!-- CSV -->
            <button type="button"
                    onclick="window.location.href='?date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&export=csv'"
                    style="background:#003d7a;color:#fff;height:36px;padding:8px 14px;border-radius:8px;border:none;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
                <i class="fas fa-file-csv"></i> CSV
            </button>
            <!-- PDF -->
            <button type="button"
                    onclick="window.open('?date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&export=pdf','_blank')"
                    style="background:#dc2626;color:#fff;height:36px;padding:8px 14px;border-radius:8px;border:none;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
                <i class="fas fa-file-pdf"></i> PDF
            </button>
            <!-- Back -->
            <a href="manager_dashboard.php"
               style="background:#6c757d;color:#fff;text-decoration:none;height:36px;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:600;display:inline-flex;align-items:center;gap:6px;">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>
    </div>

    <!-- Filter Bar -->
    <form method="get" class="filter-bar">
        <div class="filter-group">
            <label>Date From</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
        </div>
        <div class="filter-group">
            <label>Date To</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
        </div>
        <button type="submit"
                style="background:#00264D;color:#fff;height:36px;padding:8px 14px;border-radius:8px;border:none;font-size:13px;font-weight:600;cursor:pointer;">
            <i class="fas fa-filter"></i> Apply Filter
        </button>
    </form>

    <!-- Summary Cards -->
    <div class="summary-row">
        <div class="summary-card sc-green">
            <div class="sum-ico"><i class="fas fa-check-circle"></i></div>
            <div class="sum-meta">
                <h3>Validated Deliveries</h3>
                <h2><?= number_format($validated_count) ?></h2>
                <span>Gi-approve nga deliveries</span>
            </div>
        </div>
        <div class="summary-card sc-amber">
            <div class="sum-ico"><i class="fas fa-clock"></i></div>
            <div class="sum-meta">
                <h3>Pending Deliveries</h3>
                <h2><?= number_format($pending_count) ?></h2>
                <span>Naghulat sa validation</span>
            </div>
        </div>
    </div>

    <!-- Deliveries Table -->
    <div class="table-card">
        <h3 style="margin:0 0 14px;font-size:14px;font-weight:700;color:#00264D;text-transform:uppercase;">
            <i class="fas fa-truck"></i> Pending Delivery Receipts
        </h3>

        <?php if (empty($deliveries)): ?>
        <div style="text-align:center;padding:40px;color:#94a3b8;">
            <i class="fas fa-inbox" style="font-size:48px;margin-bottom:12px;opacity:.5;"></i>
            <p style="margin:0;">Walay pending deliveries nga nakit-an.</p>
        </div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Date</th>
                        <th>Fuel Type</th>
                        <th>Supplier</th>
                        <th>Invoice #</th>
                        <th>Liters</th>
                        <th>Tanker #</th>
                        <th>Recorded By</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($deliveries as $d): ?>
                    <tr>
                        <td>DEL-<?= htmlspecialchars($d['id']) ?></td>
                        <td><?= date('M d, Y', strtotime($d['delivery_date'])) ?></td>
                        <td><?= htmlspecialchars($d['fuel_type']) ?></td>
                        <td><?= htmlspecialchars($d['supplier']) ?></td>
                        <td><?= htmlspecialchars($d['invoice_no']) ?></td>
                        <td><?= number_format($d['delivery_liters'], 2) ?>L</td>
                        <td><?= htmlspecialchars($d['tanker_number'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($d['staff_name'] ?? 'N/A') ?></td>
                        <td><span class="badge badge-amber">PENDING</span></td>
                        <td>
                            <div class="action-buttons-wrapper">
                                <button class="action-btn btn-approve" onclick="approveDelivery(<?= $d['id'] ?>)">
                                    <i class="fas fa-check"></i> Approve
                                </button>
                                <button class="action-btn btn-reject" onclick="rejectDelivery(<?= $d['id'] ?>)">
                                    <i class="fas fa-times"></i> Return
                                </button>
                                <button class="action-btn btn-adjust" onclick="adjustDelivery(<?= $d['id'] ?>, <?= $d['delivery_liters'] ?>)">
                                    <i class="fas fa-edit"></i> Adjust
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <?php if ($total_records > 0): ?>
        <!-- Pagination Controls -->
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:16px;padding-top:16px;border-top:1px solid #e2e8f0;flex-wrap:wrap;gap:12px;">
            <div style="display:flex;align-items:center;gap:8px;">
                <label style="font-size:13px;color:#64748b;font-weight:600;">Rows per page:</label>
                <select id="rowsPerPage" onchange="changeRowsPerPage(this.value)"
                        style="padding:6px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;cursor:pointer;">
                    <option value="10" <?= $rows_per_page == 10 ? 'selected' : '' ?>>10</option>
                    <option value="25" <?= $rows_per_page == 25 ? 'selected' : '' ?>>25</option>
                    <option value="50" <?= $rows_per_page == 50 ? 'selected' : '' ?>>50</option>
                    <option value="100" <?= $rows_per_page == 100 ? 'selected' : '' ?>>100</option>
                </select>
                <span style="font-size:13px;color:#64748b;">
                    Showing <?= number_format($offset + 1) ?> to <?= number_format(min($offset + $rows_per_page, $total_records)) ?> of <?= number_format($total_records) ?> entries
                </span>
            </div>
            
            <?php if ($total_pages > 1): ?>
            <div style="display:flex;gap:4px;">
                <?php if ($current_page > 1): ?>
                <a href="?page=1&rows_per_page=<?= $rows_per_page ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>"
                   style="padding:6px 10px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;color:#00264D;text-decoration:none;display:inline-flex;align-items:center;">
                    <i class="fas fa-angle-double-left"></i>
                </a>
                <a href="?page=<?= $current_page - 1 ?>&rows_per_page=<?= $rows_per_page ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>"
                   style="padding:6px 10px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;color:#00264D;text-decoration:none;display:inline-flex;align-items:center;">
                    <i class="fas fa-angle-left"></i>
                </a>
                <?php endif; ?>
                
                <span style="padding:6px 12px;background:#002F70;color:#fff;border-radius:6px;font-size:13px;font-weight:600;">
                    <?= $current_page ?> / <?= $total_pages ?>
                </span>
                
                <?php if ($current_page < $total_pages): ?>
                <a href="?page=<?= $current_page + 1 ?>&rows_per_page=<?= $rows_per_page ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>"
                   style="padding:6px 10px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;color:#00264D;text-decoration:none;display:inline-flex;align-items:center;">
                    <i class="fas fa-angle-right"></i>
                </a>
                <a href="?page=<?= $total_pages ?>&rows_per_page=<?= $rows_per_page ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>"
                   style="padding:6px 10px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;color:#00264D;text-decoration:none;display:inline-flex;align-items:center;">
                    <i class="fas fa-angle-double-right"></i>
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Return Delivery to Staff</h3>
            <span class="modal-close" onclick="closeModal('rejectModal')">&times;</span>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="delivery_id" id="reject_del_id">
            <div class="form-group">
                <label>Return Reason <span style="color:#dc2626;">*</span></label>
                <textarea name="reason" required placeholder="Explain why this delivery is being returned..."></textarea>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" onclick="closeModal('rejectModal')"
                        style="background:#6c757d;color:#fff;padding:8px 16px;border-radius:6px;border:none;cursor:pointer;">
                    Cancel
                </button>
                <button type="submit"
                        style="background:#dc2626;color:#fff;padding:8px 16px;border-radius:6px;border:none;cursor:pointer;">
                    <i class="fas fa-times"></i> Return
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Adjust Modal -->
<div id="adjustModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Adjust Delivery Amount</h3>
            <span class="modal-close" onclick="closeModal('adjustModal')">&times;</span>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="adjust">
            <input type="hidden" name="delivery_id" id="adjust_del_id">
            <div class="form-group">
                <label>Adjusted Liters <span style="color:#dc2626;">*</span></label>
                <input type="number" name="adj_liters" id="adj_liters" step="0.01" min="0" required>
            </div>
            <div class="form-group">
                <label>Adjustment Note <span style="color:#dc2626;">*</span></label>
                <textarea name="adj_note" required placeholder="Explain the reason for adjustment..."></textarea>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" onclick="closeModal('adjustModal')"
                        style="background:#6c757d;color:#fff;padding:8px 16px;border-radius:6px;border:none;cursor:pointer;">
                    Cancel
                </button>
                <button type="submit"
                        style="background:#002F70;color:#fff;padding:8px 16px;border-radius:6px;border:none;cursor:pointer;">
                    <i class="fas fa-edit"></i> Adjust
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function changeRowsPerPage(value) {
    const url = new URL(window.location.href);
    url.searchParams.set('rows_per_page', value);
    url.searchParams.set('page', '1'); // Reset to first page
    window.location.href = url.toString();
}

function approveDelivery(delId) {
    if (confirm('Approve this delivery and update tank inventory?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="delivery_id" value="${delId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function rejectDelivery(delId) {
    document.getElementById('reject_del_id').value = delId;
    document.getElementById('rejectModal').style.display = 'block';
}

function adjustDelivery(delId, liters) {
    document.getElementById('adjust_del_id').value = delId;
    document.getElementById('adj_liters').value = liters;
    document.getElementById('adjustModal').style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.className === 'modal') {
        event.target.style.display = 'none';
    }
}
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>lor:#fff;padding:8px 16px;border-radius:6px;border:none;cursor:pointer;">
                    <i class="fas fa-edit"></i> Adjust & Approve
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function approveDelivery(delId) {
    if (confirm('Approve this delivery? This will add the fuel to tank inventory.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="delivery_id" value="${delId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function rejectDelivery(delId) {
    document.getElementById('reject_del_id').value = delId;
    document.getElementById('rejectModal').style.display = 'block';
}

function adjustDelivery(delId, liters) {
    document.getElementById('adjust_del_id').value = delId;
    document.getElementById('adj_liters').value = liters;
    document.getElementById('adjustModal').style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.className === 'modal') {
        event.target.style.display = 'none';
    }
}
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
