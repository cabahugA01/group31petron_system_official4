<?php
// ============================================================
// Manager Fuel Transaction Validation – manager_fuel_transaction_validation.php
// Purpose: Validate staff-encoded pump readings
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'fuel_transactions_validation';
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
    
    try {
        $pdo->beginTransaction();
        
        if ($action === 'approve') {
            $ids_str = trim($_POST['transaction_id'] ?? '');
            $ids = array_filter(array_map('intval', explode(',', $ids_str)));
            $notes = trim($_POST['approve_notes'] ?? '');
            
            if (empty($ids)) {
                throw new Exception("No transactions specified for approval.");
            }
            
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "UPDATE fuel_transactions 
                    SET status = 'Verified', 
                        validated_by = ?, 
                        validated_at = NOW(),
                        reject_reason = ? 
                    WHERE id IN ($placeholders) AND station_id = ? AND LOWER(status) LIKE '%pending%'";
            
            $stmt = $pdo->prepare($sql);
            $params = array_merge([$me['id'], $notes !== '' ? $notes : null], $ids, [$station_id]);
            $stmt->execute($params);
            
            if ($stmt->rowCount() > 0) {
                foreach ($ids as $id) {
                    try {
                        $details = "Approved shift group.";
                        if ($notes !== '') {
                            $details .= " Notes: " . $notes;
                        }
                        $pdo->prepare("INSERT INTO audit_logs (user_id, action_type, entity_type, entity_id, details, station_id, ip_address, created_at) 
                                       VALUES (?, 'Approve', 'fuel_transaction', ?, ?, ?, ?, NOW())")
                            ->execute([$me['id'], $id, $details, $station_id, $_SERVER['REMOTE_ADDR'] ?? '']);
                    } catch (Exception $ae) {}
                }
                $_SESSION['success'] = "Selected transactions approved successfully.";
            } else {
                $_SESSION['error'] = "No pending transactions were approved.";
            }
        }
        
        elseif ($action === 'reject') {
            $ids_str = trim($_POST['transaction_id'] ?? '');
            $ids = array_filter(array_map('intval', explode(',', $ids_str)));
            $reason = trim($_POST['reason'] ?? '');
            
            if (empty($ids)) {
                throw new Exception("No transactions specified for rejection.");
            }
            if (empty($reason)) {
                throw new Exception("Rejection reason is required.");
            }
            
            // Roll back inventory deduction for rejected transactions
            foreach ($ids as $id) {
                $stmt_get = $pdo->prepare("SELECT liters_sold, fuel_type FROM fuel_transactions WHERE id = ? AND station_id = ? AND LOWER(status) LIKE '%pending%'");
                $stmt_get->execute([$id, $station_id]);
                $tx = $stmt_get->fetch(PDO::FETCH_ASSOC);
                if ($tx) {
                    $l_sold = (float)$tx['liters_sold'];
                    $f_type = $tx['fuel_type'];
                    $pdo->prepare("UPDATE fuel_inventory 
                                   SET current_level = COALESCE(current_level,0) + ?, 
                                       last_updated = NOW() 
                                   WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))")
                        ->execute([$l_sold, $station_id, $f_type]);
                }
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "UPDATE fuel_transactions 
                    SET status = 'Rejected', 
                        validated_by = ?, 
                        validated_at = NOW(),
                        reject_reason = ? 
                    WHERE id IN ($placeholders) AND station_id = ? AND LOWER(status) LIKE '%pending%'";
            
            $stmt = $pdo->prepare($sql);
            $params = array_merge([$me['id'], $reason], $ids, [$station_id]);
            $stmt->execute($params);
            
            if ($stmt->rowCount() > 0) {
                foreach ($ids as $id) {
                    try {
                        $details = "Rejected shift group. Reason: " . $reason;
                        $pdo->prepare("INSERT INTO audit_logs (user_id, action_type, entity_type, entity_id, details, station_id, ip_address, created_at) 
                                       VALUES (?, 'Reject', 'fuel_transaction', ?, ?, ?, ?, NOW())")
                            ->execute([$me['id'], $id, $details, $station_id, $_SERVER['REMOTE_ADDR'] ?? '']);
                    } catch (Exception $ae) {}
                }
                $_SESSION['success'] = "Selected transactions rejected and returned to staff.";
            } else {
                $_SESSION['error'] = "No pending transactions were rejected.";
            }
        }
        
        elseif ($action === 'adjust_group') {
            $adjustments = $_POST['adjustments'] ?? [];
            $adj_note = trim($_POST['adj_note'] ?? '');
            
            if (empty($adjustments)) {
                throw new Exception("No adjustments specified.");
            }
            if (empty($adj_note)) {
                throw new Exception("Adjustment reason/note is required.");
            }
            
            $updated_count = 0;
            foreach ($adjustments as $tx_id => $data) {
                $tx_id = (int)$tx_id;
                $new_prev = (float)($data['prev_reading'] ?? 0);
                $new_pres = (float)($data['pres_reading'] ?? 0);
                $new_cal = (float)($data['calibration'] ?? 0);
                $new_liters = (float)($data['liters'] ?? 0);
                $new_amount = (float)($data['amount'] ?? 0);
                
                if ($tx_id <= 0 || $new_liters < 0 || $new_amount < 0 || $new_prev < 0 || $new_pres < 0 || $new_cal < 0) {
                    continue;
                }
                
                $stmt = $pdo->prepare("SELECT liters_sold, total_amount, previous_reading, present_reading, calibration, fuel_type FROM fuel_transactions WHERE id = ? AND station_id = ? AND LOWER(status) LIKE '%pending%'");
                $stmt->execute([$tx_id, $station_id]);
                $curr = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$curr) continue;
                
                $old_liters = (float)$curr['liters_sold'];
                $old_amount = (float)$curr['total_amount'];
                $old_prev = (float)$curr['previous_reading'];
                $old_pres = (float)$curr['present_reading'];
                $old_cal = (float)$curr['calibration'];
                $f_type = $curr['fuel_type'];
                
                $is_adjusted = (abs($old_liters - $new_liters) > 0.001) 
                    || (abs($old_amount - $new_amount) > 0.001)
                    || (abs($old_prev - $new_prev) > 0.001)
                    || (abs($old_pres - $new_pres) > 0.001)
                    || (abs($old_cal - $new_cal) > 0.001);
                
                $up_stmt = $pdo->prepare("UPDATE fuel_transactions 
                                          SET status = 'Verified', 
                                              previous_reading = ?,
                                              present_reading = ?,
                                              calibration = ?,
                                              liters_sold = ?,
                                              total_amount = ?,
                                              validated_by = ?, 
                                              validated_at = NOW(),
                                              reject_reason = ?
                                          WHERE id = ? AND station_id = ? AND LOWER(status) LIKE '%pending%'");
                $up_stmt->execute([$new_prev, $new_pres, $new_cal, $new_liters, $new_amount, $me['id'], $adj_note, $tx_id, $station_id]);
                
                if ($up_stmt->rowCount() > 0) {
                    $updated_count++;
                    
                    // Adjust fuel inventory stock levels based on difference: old_liters - new_liters
                    $diff_liters = $old_liters - $new_liters;
                    $pdo->prepare("UPDATE fuel_inventory 
                                   SET current_level = COALESCE(current_level,0) + ?, 
                                       last_updated = NOW() 
                                   WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))")
                        ->execute([$diff_liters, $station_id, $f_type]);
                    
                    try {
                        $log_action = $is_adjusted ? 'Adjust' : 'Approve';
                        $details = $is_adjusted 
                            ? "Adjusted Shift Reading for {$f_type} — Beginning: {$old_prev} -> {$new_prev}, Ending: {$old_pres} -> {$new_pres}, Calib: {$old_cal} -> {$new_cal}, Liters: {$old_liters} -> {$new_liters} L (Variance: " . ($new_liters - $old_liters) . " L), Amount: ₱{$old_amount} -> ₱{$new_amount}."
                            : "Approved shift reading.";
                        $details .= " Note: " . $adj_note;
                        
                        $pdo->prepare("INSERT INTO audit_logs (user_id, action_type, entity_type, entity_id, details, station_id, ip_address, created_at) 
                                       VALUES (?, ?, 'fuel_transaction', ?, ?, ?, ?, NOW())")
                            ->execute([$me['id'], $log_action, $tx_id, $details, $station_id, $_SERVER['REMOTE_ADDR'] ?? '']);
                    } catch (Exception $ae) {}
                }
            }
            
            if ($updated_count > 0) {
                $_SESSION['success'] = "Shift adjustments saved successfully.";
            } else {
                $_SESSION['error'] = "No adjustments were saved.";
            }
        }
        
        $pdo->commit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
    
    header('Location: manager_fuel_transaction_validation.php');
    exit;
}

// ── Date Filter ───────────────────────────────────────────
$date_from = trim($_GET['date_from'] ?? date('Y-m-d', strtotime('-6 months'))); // Default to 6 months ago
$date_to   = trim($_GET['date_to']   ?? date('Y-m-d'));  // Default to today

// ── Summary Cards ──────────────────────────────────────────
$validated_count = 0;
$pending_count   = 0;
$rejected_count  = 0;
$total_liters    = 0.0;
$total_amount    = 0.0;

try {
    $stmt = $pdo->prepare("SELECT
        SUM(CASE WHEN LOWER(status) IN ('approved','adjusted','verified') THEN 1 ELSE 0 END) as validated,
        SUM(CASE WHEN LOWER(status) LIKE '%pending%' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN LOWER(status) = 'rejected' THEN 1 ELSE 0 END) as rejected,
        COALESCE(SUM(CASE WHEN LOWER(status) IN ('approved','adjusted','verified') THEN liters_sold ELSE 0 END),0) as liters,
        COALESCE(SUM(CASE WHEN LOWER(status) IN ('approved','adjusted','verified') THEN total_amount ELSE 0 END),0) as amount
        FROM fuel_transactions
        WHERE station_id = ? AND DATE(transaction_date) BETWEEN ? AND ?");
    $stmt->execute([$station_id, $date_from, $date_to]);
    $sc = $stmt->fetch(PDO::FETCH_ASSOC);
    $validated_count = (int)($sc['validated'] ?? 0);
    $pending_count   = (int)($sc['pending']   ?? 0);
    $rejected_count  = (int)($sc['rejected']  ?? 0);
    $total_liters    = (float)($sc['liters']  ?? 0);
    $total_amount    = (float)($sc['amount']  ?? 0);
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

// Helper functions for formatting
function formatShift($shiftPeriod, $shiftName) {
    if ($shiftName) return $shiftName;
    if (!$shiftPeriod) return '—';
    $sl = strtolower($shiftPeriod);
    if (str_contains($sl, 'first') || $sl === 'shift_1' || $sl === '1') return 'First Shift';
    if (str_contains($sl, 'second') || $sl === 'shift_2' || $sl === '2') return 'Second Shift';
    return $shiftPeriod;
}

function getStatusBadge($status) {
    $s = strtolower(trim($status ?? ''));
    if ($s === 'pending validation' || $s === 'pending') {
        $color = '#d97706';
        $label = 'Pending Manager Validation';
    } elseif ($s === 'approved' || $s === 'verified' || $s === 'validated') {
        $color = '#16a34a';
        $label = 'Validated';
    } elseif ($s === 'adjusted') {
        $color = '#2563eb';
        $label = 'Adjusted';
    } elseif ($s === 'rejected') {
        $color = '#dc2626';
        $label = 'Rejected';
    } else {
        $color = '#64748b';
        $label = $status;
    }
    return '<span style="background:'.$color.'15; color:'.$color.'; border:1px solid '.$color.'30; font-weight:700; font-size:11px; padding:4px 8px; border-radius:6px; text-transform:uppercase; letter-spacing:.5px; white-space:nowrap;">'.$label.'</span>';
}

// ── Fetch and Group Transactions ─────────────────────────
$grouped = [];
$total_records = 0;
$total_pages = 0;
$transactions_grouped = [];

try {
    // Fetch all pending transactions for grouping
    $sql = "SELECT ft.*, 
                   fp.pump_number,
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
            FROM fuel_transactions ft
            LEFT JOIN fuel_pumps fp ON ft.pump_id = fp.id
            LEFT JOIN users staff ON ft.staff_id = staff.id
            LEFT JOIN users validator ON ft.validated_by = validator.id
            WHERE ft.station_id = ?
            AND LOWER(ft.status) LIKE '%pending%'
            AND DATE(ft.transaction_date) BETWEEN ? AND ?
            ORDER BY ft.transaction_date DESC, ft.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$station_id, $date_from, $date_to]);
    $all_txs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group in PHP by Date + Shift
    foreach ($all_txs as $tx) {
        $date_key = date('Y-m-d', strtotime($tx['transaction_date']));
        $shift_label = formatShift($tx['shift_period'] ?? '', $tx['shift_name'] ?? '');
        $group_key = $date_key . '_' . $shift_label;
        
        if (!isset($grouped[$group_key])) {
            $grouped[$group_key] = [
                'date' => $date_key,
                'shift' => $shift_label,
                'shift_period' => $tx['shift_period'] ?? '',
                'shift_name' => $tx['shift_name'] ?? '',
                'items' => [],
                'ids' => [],
                'staff_names' => []
            ];
        }
        $grouped[$group_key]['items'][] = $tx;
        $grouped[$group_key]['ids'][] = (int)$tx['id'];
        
        $sname = $tx['staff_name'] ?? 'Unknown';
        if (!in_array($sname, $grouped[$group_key]['staff_names'])) {
            $grouped[$group_key]['staff_names'][] = $sname;
        }
    }
    
    // Perform pagination on the groups
    $total_records = count($grouped);
    $total_pages = (int)ceil($total_records / $rows_per_page);
    
    $keys = array_keys($grouped);
    $page_keys = array_slice($keys, $offset, $rows_per_page);
    
    foreach ($page_keys as $k) {
        $transactions_grouped[$k] = $grouped[$k];
        $transactions_grouped[$k]['encoded_by'] = implode(', ', $grouped[$k]['staff_names']);
    }
} catch (Exception $e) {
    error_log("Fetch transactions error: " . $e->getMessage());
    $_SESSION['error'] = "Error loading transactions: " . $e->getMessage();
}

require_once __DIR__ . '/../partials/header.php';
?>

<style>
/* Global Fix: NO Horizontal Scroll */
* {
    box-sizing: border-box;
}
html, body {
    max-width: 100%;
    width: 100%;
    overflow-x: hidden !important;
    position: relative;
}

/* Manager Fuel Transaction Validation Styles */
.mftv-wrap { 
    padding: 0; 
    max-width: 100%;
    width: 100%;
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
.summary-card.sc-blue   { border-left: 4px solid #1e40af; }
.summary-card.sc-amber  { border-left: 4px solid #d97706; }
.summary-card.sc-green  { border-left: 4px solid #16a34a; }
.summary-card.sc-red    { border-left: 4px solid #dc2626; }
.sum-ico {
    width: 52px; height: 52px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 24px; flex-shrink: 0;
}
.summary-card.sc-blue .sum-ico   { background: #dbeafe; color: #1e40af; }
.summary-card.sc-amber .sum-ico  { background: #fef3c7; color: #d97706; }
.summary-card.sc-green .sum-ico  { background: #dcfce7; color: #16a34a; }
.summary-card.sc-red   .sum-ico  { background: #fee2e2; color: #dc2626; }
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

/* Table – NO horizontal scroll */
.table-card {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
    padding: 18px; box-shadow: 0 1px 3px rgba(0,0,0,.05);
    width: 100%; overflow: hidden; box-sizing: border-box;
}
.table-wrap { 
    width: 100%; overflow-x: auto;
    box-sizing: border-box; display: block;
}
.data-table {
    width: 100%; border-collapse: collapse;
    box-sizing: border-box;
}
.data-table thead th {
    background: #002F70; padding: 8px 10px; text-align: left;
    font-size: 11px; font-weight: 700; color: #fff;
    text-transform: uppercase; border-bottom: 2px solid #002F70;
    line-height: 1.2;
}
.data-table tbody tr { border-bottom: 1px solid #f1f5f9; }
.data-table tbody tr:hover { background: #e3f2fd; }
.data-table tbody td {
    padding: 8px 10px;
    color: #334155;
    vertical-align: middle;
    font-size: 12px;
    line-height: 1.3;
}
.align-right { text-align: right !important; }

/* Compact action buttons — unified outline style matching staff Transaction module */
.action-btn {
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 700;
    border: 1px solid transparent;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: all .15s;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    box-sizing: border-box;
    background: white !important;
}
.btn-approve { color: #16a34a !important; border-color: #16a34a !important; }
.btn-approve:hover { background: #16a34a !important; color: #fff !important; }
.btn-reject  { color: #dc2626 !important; border-color: #dc2626 !important; }
.btn-reject:hover  { background: #dc2626 !important; color: #fff !important; }
.btn-adjust  { color: #002F70 !important; border-color: #002F70 !important; }
.btn-adjust:hover  { background: #002F70 !important; color: #fff !important; }

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

<div class="mftv-wrap">
    <!-- Page Header -->
    <div class="page-head">
        <div>
            <h1>Fuel Transaction Validation</h1>
            <div class="sub">REVIEW AND VALIDATE FUEL TRANSACTIONS ENCODED BY STAFF FOR ACCURACY AND COMPLIANCE.</div>
        </div>
        <div class="actions" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <!-- Excel -->
            <button type="button"
                    onclick="mftvExport('excel')"
                    style="background:white;color:#1d6f42;height:36px;padding:8px 14px;border-radius:8px;border:1px solid #1d6f42;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s;"
                    onmouseover="this.style.background='#1d6f42';this.style.color='#fff'"
                    onmouseout="this.style.background='white';this.style.color='#1d6f42'">
                <i class="fas fa-file-excel"></i> Excel
            </button>
            <!-- CSV -->
            <button type="button"
                    onclick="mftvExport('csv')"
                    style="background:white;color:#003d7a;height:36px;padding:8px 14px;border-radius:8px;border:1px solid #003d7a;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s;"
                    onmouseover="this.style.background='#003d7a';this.style.color='#fff'"
                    onmouseout="this.style.background='white';this.style.color='#003d7a'">
                <i class="fas fa-file-csv"></i> CSV
            </button>
            <!-- PDF -->
            <button type="button"
                    onclick="mftvExport('pdf')"
                    style="background:white;color:#dc2626;height:36px;padding:8px 14px;border-radius:8px;border:1px solid #dc2626;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s;"
                    onmouseover="this.style.background='#dc2626';this.style.color='#fff'"
                    onmouseout="this.style.background='white';this.style.color='#dc2626'">
                <i class="fas fa-file-pdf"></i> PDF
            </button>
            <!-- Back -->
            <a href="manager_dashboard.php"
               style="background:white;color:#4b5563;text-decoration:none;height:36px;padding:8px 14px;border-radius:8px;border:1px solid #6b7280;font-size:13px;font-weight:600;display:inline-flex;align-items:center;gap:6px;transition:all .15s;"
               onmouseover="this.style.background='#6b7280';this.style.color='#fff'"
               onmouseout="this.style.background='white';this.style.color='#4b5563'">
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
                style="background:white;color:#16a34a;padding:8px 16px;border-radius:6px;border:1px solid #16a34a;cursor:pointer;font-size:13px;font-weight:600;transition:all .15s;"
                onmouseover="this.style.background='#16a34a';this.style.color='#fff'"
                onmouseout="this.style.background='white';this.style.color='#16a34a'">
            <i class="fas fa-filter"></i> Apply Filter
        </button>
    </form>

    <!-- Summary Cards -->
    <div class="summary-row" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
        <div class="summary-card sc-blue">
            <div class="sum-ico"><i class="fas fa-check-circle"></i></div>
            <div class="sum-meta">
                <h3>Validated</h3>
                <h2><?= number_format($validated_count) ?></h2>
                <span>Gi-approve ug gi-adjust</span>
            </div>
        </div>
        <div class="summary-card sc-amber">
            <div class="sum-ico"><i class="fas fa-clock"></i></div>
            <div class="sum-meta">
                <h3>Pending</h3>
                <h2><?= number_format($pending_count) ?></h2>
                <span>Naghulat sa validation</span>
            </div>
        </div>
        <div class="summary-card sc-green">
            <div class="sum-ico"><i class="fas fa-tint"></i></div>
            <div class="sum-meta">
                <h3>Total Liters (Validated)</h3>
                <h2><?= number_format($total_liters, 2) ?></h2>
                <span>Validated liters sold</span>
            </div>
        </div>
        <div class="summary-card sc-red">
            <div class="sum-ico"><i class="fas fa-times-circle"></i></div>
            <div class="sum-meta">
                <h3>Rejected</h3>
                <h2><?= number_format($rejected_count) ?></h2>
                <span>Gi-reject nga entries</span>
            </div>
        </div>
    </div>


    <!-- Transactions Table -->
    <div class="table-card">
        <h3 style="margin:0 0 18px;font-size:14px;font-weight:700;color:#00264D;text-transform:uppercase;">
            <i class="fas fa-list"></i> Pending Pump Readings
        </h3>

        <?php if (empty($transactions_grouped)): ?>
        <div style="text-align:center;padding:40px;color:#94a3b8;">
            <i class="fas fa-inbox" style="font-size:48px;margin-bottom:12px;opacity:.5;"></i>
            <p style="margin:0;">Walay pending transactions nga nakit-an.</p>
        </div>
        <?php else: ?>
        
        <?php foreach ($transactions_grouped as $group_key => $group): ?>
        <div class="shift-group-card" style="margin-bottom: 24px; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.05);">
            <div class="shift-group-header" style="background: #f8fafc; padding: 12px 16px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                <div style="font-weight: 700; color: #002F70; font-size: 14px;">
                    <i class="fas fa-calendar-alt"></i> <?= date('M d, Y', strtotime($group['date'])) ?> 
                    &nbsp;|&nbsp; 
                    <i class="fas fa-clock"></i> <?= htmlspecialchars($group['shift']) ?>
                    &nbsp;|&nbsp;
                    <span style="font-weight: 500; color: #64748b; font-size: 13px;">Encoded By: <?= htmlspecialchars($group['encoded_by']) ?></span>
                </div>
                <div style="display: flex; gap: 6px;">
                    <button class="action-btn btn-approve" style="width: auto; padding: 6px 14px; font-size: 12px;" onclick="approveGroup('<?= implode(',', $group['ids']) ?>')">
                        <i class="fas fa-check"></i> Approve Shift
                    </button>
                    <button class="action-btn btn-reject" style="width: auto; padding: 6px 14px; font-size: 12px;" onclick="rejectGroup('<?= implode(',', $group['ids']) ?>')">
                        <i class="fas fa-times"></i> Reject Shift
                    </button>
                    <button class="action-btn btn-adjust" style="width: auto; padding: 6px 14px; font-size: 12px;" onclick="adjustGroup('<?= $group_key ?>')">
                        <i class="fas fa-edit"></i> Adjust Shift
                    </button>
                </div>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width: 25%;">Pump / Fuel Type</th>
                            <th class="align-right" style="width: 12%;">Beginning</th>
                            <th class="align-right" style="width: 12%;">Ending</th>
                            <th class="align-right" style="width: 10%;">Calibration</th>
                            <th class="align-right" style="width: 12%;">Volume (L)</th>
                            <th class="align-right" style="width: 10%;">Price/L</th>
                            <th class="align-right" style="width: 12%;">Amount</th>
                            <th style="width: 20%;">Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($group['items'] as $tx): 
                            $pump_fuel = htmlspecialchars($tx['fuel_type'] ?? 'N/A');
                            if (!empty($tx['pump_number'])) {
                                $pump_fuel .= ' - ' . htmlspecialchars($tx['pump_number']);
                            }
                        ?>
                        <tr>
                            <td style="font-weight: 700; color: #0f172a;"><?= $pump_fuel ?></td>
                            <td class="align-right"><?= number_format($tx['previous_reading'] ?? 0, 2) ?></td>
                            <td class="align-right" style="font-weight: 600; color: #1e293b;"><?= number_format($tx['present_reading'] ?? 0, 2) ?></td>
                            <td class="align-right"><?= number_format($tx['calibration'] ?? 0, 3) ?></td>
                            <td class="align-right" style="font-weight: 700; color: #1e293b;"><?= number_format($tx['liters_sold'] ?? 0, 2) ?> L</td>
                            <td class="align-right">₱<?= number_format($tx['price_per_liter'] ?? 0, 2) ?></td>
                            <td class="align-right" style="font-weight: 800; color: #0f172a;">₱<?= number_format($tx['total_amount'] ?? 0, 2) ?></td>
                            <td>
                                <?php
                                $staffNotes = trim($tx['notes'] ?? '');
                                if ($staffNotes !== '') {
                                    echo htmlspecialchars($staffNotes);
                                } else {
                                    echo '—';
                                }
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endforeach; ?>
        
        <?php if ($total_records > 0): ?>
        <!-- Pagination Controls -->
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:16px;padding-top:16px;border-top:1px solid #e2e8f0;flex-wrap:wrap;gap:12px;">
            <div style="display:flex;align-items:center;gap:8px;">
                <label style="font-size:13px;color:#64748b;font-weight:600;">Shifts per page:</label>
                <select id="rowsPerPage" onchange="changeRowsPerPage(this.value)"
                        style="padding:6px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;cursor:pointer;">
                    <option value="10" <?= $rows_per_page == 10 ? 'selected' : '' ?>>10</option>
                    <option value="25" <?= $rows_per_page == 25 ? 'selected' : '' ?>>25</option>
                    <option value="50" <?= $rows_per_page == 50 ? 'selected' : '' ?>>50</option>
                    <option value="100" <?= $rows_per_page == 100 ? 'selected' : '' ?>>100</option>
                </select>
                <span style="font-size:13px;color:#64748b;">
                    Showing <?= number_format($offset + 1) ?> to <?= number_format(min($offset + $rows_per_page, $total_records)) ?> of <?= number_format($total_records) ?> shifts
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

<!-- Approve Modal -->
<div id="approveModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Approve Shift Transactions</h3>
            <span class="modal-close" onclick="closeModal('approveModal')">&times;</span>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="transaction_id" id="approve_tx_id">
            <div class="form-group">
                <label>Validation Notes <span style="font-weight: normal; color: #64748b;">(Optional)</span></label>
                <textarea name="approve_notes" placeholder="Enter optional validation notes or comments..."></textarea>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" onclick="closeModal('approveModal')"
                        style="background:white;color:#4b5563;padding:8px 16px;border-radius:6px;border:1px solid #6b7280;cursor:pointer;font-size:13px;font-weight:600;transition:all .15s;"
                        onmouseover="this.style.background='#6b7280';this.style.color='#fff'"
                        onmouseout="this.style.background='white';this.style.color='#4b5563'">
                    Cancel
                </button>
                <button type="submit"
                        style="background:white;color:#16a34a;padding:8px 16px;border-radius:6px;border:1px solid #16a34a;cursor:pointer;font-size:13px;font-weight:600;transition:all .15s;"
                        onmouseover="this.style.background='#16a34a';this.style.color='#fff'"
                        onmouseout="this.style.background='white';this.style.color='#16a34a'">
                    <i class="fas fa-check"></i> Approve
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Reject Shift Transactions</h3>
            <span class="modal-close" onclick="closeModal('rejectModal')">&times;</span>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="transaction_id" id="reject_tx_id">
            <div class="form-group">
                <label>Rejection Reason <span style="font-weight: normal; color: #64748b;">(Optional)</span></label>
                <textarea name="reason" placeholder="Explain why this shift is being rejected..."></textarea>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" onclick="closeModal('rejectModal')"
                        style="background:white;color:#4b5563;padding:8px 16px;border-radius:6px;border:1px solid #6b7280;cursor:pointer;font-size:13px;font-weight:600;transition:all .15s;"
                        onmouseover="this.style.background='#6b7280';this.style.color='#fff'"
                        onmouseout="this.style.background='white';this.style.color='#4b5563'">
                    Cancel
                </button>
                <button type="submit"
                        style="background:white;color:#dc2626;padding:8px 16px;border-radius:6px;border:1px solid #dc2626;cursor:pointer;font-size:13px;font-weight:600;transition:all .15s;"
                        onmouseover="this.style.background='#dc2626';this.style.color='#fff'"
                        onmouseout="this.style.background='white';this.style.color='#dc2626'">
                    <i class="fas fa-times"></i> Reject
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Adjust Modal -->
<div id="adjustModal" class="modal">
    <div class="modal-content" style="max-width: 600px; width: 95%;">
        <div class="modal-header">
            <h3>Adjust Shift Readings</h3>
            <span class="modal-close" onclick="closeModal('adjustModal')">&times;</span>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="adjust_group">
            
            <div id="adjust_items_container" style="max-height: 350px; overflow-y: auto; margin-bottom: 16px; padding-right: 8px;">
                <!-- Dynamically populated in JavaScript -->
            </div>
            
            <div class="form-group">
                <label>Adjustment Note / Remarks <span style="font-weight: normal; color: #64748b;">(Optional)</span></label>
                <textarea name="adj_note" placeholder="Explain the reason for adjustment..."></textarea>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" onclick="closeModal('adjustModal')"
                        style="background:white;color:#4b5563;padding:8px 16px;border-radius:6px;border:1px solid #6b7280;cursor:pointer;font-size:13px;font-weight:600;transition:all .15s;"
                        onmouseover="this.style.background='#6b7280';this.style.color='#fff'"
                        onmouseout="this.style.background='white';this.style.color='#4b5563'">
                    Cancel
                </button>
                <button type="submit"
                        style="background:white;color:#002F70;padding:8px 16px;border-radius:6px;border:1px solid #002F70;cursor:pointer;font-size:13px;font-weight:600;transition:all .15s;"
                        onmouseover="this.style.background='#002F70';this.style.color='#fff'"
                        onmouseout="this.style.background='white';this.style.color='#002F70'">
                    <i class="fas fa-edit"></i> Save Adjustments
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const groupedData = <?= json_encode($grouped) ?>;

function changeRowsPerPage(value) {
    const url = new URL(window.location.href);
    url.searchParams.set('rows_per_page', value);
    url.searchParams.set('page', '1'); // Reset to first page
    window.location.href = url.toString();
}

function approveGroup(ids) {
    document.getElementById('approve_tx_id').value = ids;
    document.getElementById('approveModal').style.display = 'block';
}

function rejectGroup(ids) {
    document.getElementById('reject_tx_id').value = ids;
    document.getElementById('rejectModal').style.display = 'block';
}

function recalculateTx(txId, price) {
    const prevInput = document.getElementById(`tx_prev_${txId}`);
    const presInput = document.getElementById(`tx_pres_${txId}`);
    const calInput = document.getElementById(`tx_cal_${txId}`);
    const litersInput = document.getElementById(`tx_liters_${txId}`);
    const amountInput = document.getElementById(`tx_amount_${txId}`);
    
    const prev = parseFloat(prevInput.value) || 0;
    const pres = parseFloat(presInput.value) || 0;
    const cal = parseFloat(calInput.value) || 0;
    
    let liters = pres - prev - cal;
    if (liters < 0) liters = 0;
    liters = Math.round(liters * 100) / 100;
    
    let amount = liters * price;
    amount = Math.round(amount * 100) / 100;
    
    litersInput.value = liters.toFixed(2);
    amountInput.value = amount.toFixed(2);
}

function handleLitersChange(txId, price) {
    const litersInput = document.getElementById(`tx_liters_${txId}`);
    const amountInput = document.getElementById(`tx_amount_${txId}`);
    
    const liters = parseFloat(litersInput.value) || 0;
    let amount = liters * price;
    amount = Math.round(amount * 100) / 100;
    
    amountInput.value = amount.toFixed(2);
}

function adjustGroup(groupKey) {
    window.location.href = 'manager_fuel_adjustments.php?sub_tab=adj-transactions#adjustments';
}

function escapeHtml(text) {
    if (!text) return '';
    return text
        .toString()
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
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

function mftvExport(format) {
    // Grab all tables with data
    const tables = Array.from(document.querySelectorAll('.data-table')).filter(
        t => t.querySelector('tbody tr')
    );

    if (!tables.length) { alert('No pending pump readings found to export.'); return; }

    const dateFrom = document.querySelector('input[name="date_from"]')?.value || '';
    const dateTo   = document.querySelector('input[name="date_to"]')?.value || '';
    const filename = `Fuel_Validation_${dateFrom || 'All'}_to_${dateTo || 'All'}`;

    if (format === 'excel') {
        if (typeof XLSX === 'undefined') {
            alert('Export library not loaded. Please try again.');
            return;
        }
        const wb = XLSX.utils.book_new();
        const usedNames = {};

        tables.forEach((tbl, i) => {
            // Get heading from shift group card
            const card = tbl.closest('.shift-group-card');
            let sheetName = card?.querySelector('.shift-group-header')?.innerText?.split('|')[0]?.trim() || `Sheet ${i + 1}`;
            // Clean sheet name
            sheetName = sheetName.replace(/[:\\\/?*\[\]]/g, '').substring(0, 31).trim() || `Sheet${i+1}`;
            if (usedNames[sheetName]) {
                usedNames[sheetName]++;
                sheetName = (sheetName.substring(0, 28) + ' ' + usedNames[sheetName]).substring(0,31);
            } else {
                usedNames[sheetName] = 1;
            }

            const aoa = [];
            // Headers
            tbl.querySelectorAll('thead tr').forEach(tr => {
                aoa.push([...tr.querySelectorAll('th')].map(th => th.innerText.trim()));
            });
            // Body
            tbl.querySelectorAll('tbody tr').forEach(tr => {
                aoa.push([...tr.querySelectorAll('td')].map(td => td.innerText.trim()));
            });

            const ws = XLSX.utils.aoa_to_sheet(aoa);
            if (aoa.length && aoa[0]) {
                ws['!cols'] = aoa[0].map((_, ci) => ({
                    wch: Math.min(45, Math.max(10, ...aoa.map(row => String(row[ci] ?? '').length)))
                }));
            }
            XLSX.utils.book_append_sheet(wb, ws, sheetName);
        });

        XLSX.writeFile(wb, filename + '.xlsx');
    } else if (format === 'csv') {
        let csv = '';
        tables.forEach((tbl, i) => {
            const card = tbl.closest('.shift-group-card');
            const heading = card?.querySelector('.shift-group-header')?.innerText?.replace(/\n/g, ' ') || '';
            if (heading) csv += '"' + heading.replace(/"/g, '""') + '"\n';
            else if (i > 0) csv += '\n';

            tbl.querySelectorAll('thead tr').forEach(tr => {
                csv += [...tr.querySelectorAll('th')].map(th => '"' + th.innerText.trim().replace(/"/g, '""') + '"').join(',') + '\n';
            });
            tbl.querySelectorAll('tbody tr').forEach(tr => {
                csv += [...tr.querySelectorAll('td')].map(td => '"' + td.innerText.trim().replace(/"/g, '""') + '"').join(',') + '\n';
            });
            csv += '\n';
        });
        const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href     = url;
        a.download = filename + '.csv';
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { document.body.removeChild(a); URL.revokeObjectURL(url); }, 200);
    } else if (format === 'pdf') {
        const logo_url  = '../assets/img/Petron%20Logo.png';
        const generated = new Date().toLocaleString();
        
        let contentHtml = '';
        tables.forEach(tbl => {
            const card = tbl.closest('.shift-group-card');
            const heading = card?.querySelector('.shift-group-header')?.innerHTML || '';
            
            // Clean action buttons from the printable heading
            let cleanHeading = heading.replace(/<div style="display:\s*flex;[^>]*>[\s\S]*?<\/div>/i, '');
            cleanHeading = cleanHeading.replace(/<div style="display:\s*flex;[^>]*>[\s\S]*?<\/div>/gi, '');
            
            contentHtml += `
            <div class="print-section">
                <div class="print-section-header">${cleanHeading}</div>
                <table>
                    ${tbl.innerHTML}
                </table>
            </div>`;
        });
        
        let iframe = document.getElementById('print-iframe');
        if (!iframe) {
            iframe = document.createElement('iframe');
            iframe.id = 'print-iframe';
            iframe.style.position = 'fixed';
            iframe.style.right = '0';
            iframe.style.bottom = '0';
            iframe.style.width = '0';
            iframe.style.height = '0';
            iframe.style.border = '0';
            document.body.appendChild(iframe);
        }

        const doc = iframe.contentDocument || iframe.contentWindow.document;
        doc.open();
        doc.write(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>Fuel Transaction Validation Report</title>
        <style>
            @page{size:legal landscape;margin:.3in .4in;}
            *{-webkit-print-color-adjust:exact !important;print-color-adjust:exact !important;box-sizing:border-box;}
            body{font-family:Arial,sans-serif;font-size:11px;color:#000;background:white;margin:0;padding:20px;}
            .header-container{display:flex;align-items:center;gap:15px;border-bottom:2px solid #002F70;padding-bottom:12px;margin-bottom:20px;}
            .header-container img{height:45px;}
            .header-title h1{font-size:16px;margin:0;color:#002F70;text-transform:uppercase;}
            .header-title p{font-size:10px;margin:3px 0 0;color:#666;}
            .meta-info{margin-left:auto;text-align:right;font-size:10px;color:#444;}
            .print-section{margin-bottom:25px; page-break-inside:avoid;}
            .print-section-header{font-size:12px;font-weight:700;background:#f2f2f2;padding:8px 10px;border:1px solid #ddd;border-bottom:none;color:#002F70;}
            table{width:100%;border-collapse:collapse;font-size:9.5px;}
            thead tr{background:#002F70;color:#fff;}
            thead th{padding:6px 5px;text-align:left;font-weight:700;font-size:9px;text-transform:uppercase;color:#fff;}
            tbody tr{border-bottom:1px solid #ddd;}
            tbody td{padding:5px;color:#333;}
            .align-right{text-align:right;}
            .align-center{text-align:center;}
        </style></head><body>
            <div class="header-container">
                <img src="${logo_url}" alt="Petron">
                <div class="header-title">
                    <h1>Petron Station Management System</h1>
                    <p>Fuel Transaction Validation Report</p>
                </div>
                <div class="meta-info">
                    Date Range: ${dateFrom || 'All'} to ${dateTo || 'All'}<br>
                    Generated: ${generated}
                </div>
            </div>
            ${contentHtml}
        </body></html>`);
        doc.close();

        setTimeout(() => {
            iframe.contentWindow.focus();
            iframe.contentWindow.print();
        }, 250);
    }
}
</script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>

