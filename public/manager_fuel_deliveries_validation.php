<?php
// ============================================================
// Manager Fuel Deliveries Oversight – manager_fuel_deliveries_validation.php
// Purpose: View, audit, and validate staff-encoded fuel deliveries
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'fuel_deliveries_validation';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();

// Access control - Manager and Supervisor only
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

// ── Status Badges / Helper Functions ─────────────────────────
if (!function_exists('getStatusBadgeClass')) {
    function getStatusBadgeClass($status) {
        $s = strtolower(trim($status ?? ''));
        if (strpos($s, 'pending') !== false) return 'bg-amber';
        if (in_array($s, ['verified', 'approved', 'validated'])) return 'bg-green';
        if ($s === 'rejected') return 'bg-red';
        return 'bg-gray';
    }
}

if (!function_exists('getStatusLabel')) {
    function getStatusLabel($status) {
        $s = strtolower(trim($status ?? ''));
        if (strpos($s, 'pending') !== false) return 'Pending';
        if (in_array($s, ['verified', 'approved', 'validated'])) return 'Verified';
        if ($s === 'rejected') return 'Rejected';
        return ucfirst($status);
    }
}

// ── POST Actions (Single Delivery validation) ────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['export'])) {
    $action      = trim($_POST['action'] ?? '');
    $delivery_id = (int)($_POST['id'] ?? 0);

    if ($delivery_id <= 0) {
        $_SESSION['error'] = 'Invalid delivery ID.';
        header('Location: manager_fuel_deliveries_validation.php'); exit;
    }

    try {
        $pdo->beginTransaction();

        // Fetch delivery
        $stmt = $pdo->prepare("SELECT * FROM fuel_deliveries WHERE id = ? AND station_id = ? FOR UPDATE");
        $stmt->execute([$delivery_id, $station_id]);
        $delivery = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$delivery) {
            throw new Exception("Delivery record not found.");
        }

        // Guard: must still be pending validation
        $curr_status = strtolower(trim($delivery['status']));
        if (!in_array($curr_status, ['pending', 'pending validation', 'pending manager approval', 'pending manager validation'])) {
            throw new Exception("This delivery has already been processed (Status: " . ucfirst($delivery['status']) . ").");
        }

        if ($action === 'verify') {
            // Update status
            $up = $pdo->prepare("UPDATE fuel_deliveries SET status='Verified', verified_by=?, verified_at=NOW() WHERE id=? AND station_id=?");
            $up->execute([$me['id'], $delivery_id, $station_id]);

            // Add liters to fuel_inventory (current_level AND current_stock must both increase)
            // This is the ONLY place that increases inventory — on manager verification of delivery.
            $up_inv = $pdo->prepare("UPDATE fuel_inventory 
                                     SET current_level = COALESCE(current_level, 0) + ?,
                                         current_stock  = COALESCE(current_stock, 0) + ?,
                                         last_updated   = NOW()
                                     WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))");
            $up_inv->execute([$delivery['delivery_liters'], $delivery['delivery_liters'], $station_id, $delivery['fuel_type']]);

            // Safety check: ensure the fuel type was found in inventory
            if ($up_inv->rowCount() === 0) {
                // Fuel type name mismatch — delivery fuel type not in inventory table
                // Log for admin to fix the fuel type name mapping
                error_log("FUEL INVENTORY WARNING: No fuel_inventory row matched fuel_type='{$delivery['fuel_type']}' station_id={$station_id} for DEL-{$delivery_id}. Inventory NOT updated.");
                // Throw to alert the manager
                throw new Exception("Fuel type '{$delivery['fuel_type']}' was not found in this station's fuel inventory. Please contact the administrator to configure the tank first.");
            }

            // Write to audit log
            try {
                $pdo->prepare("INSERT INTO audit_logs(user_id, action_type, entity_type, entity_id, details, station_id, ip_address, created_at) VALUES(?, 'Verify', 'fuel_delivery', ?, ?, ?, ?, NOW())")
                    ->execute([$me['id'], $delivery_id, "Verified delivery DEL-{$delivery_id} (Invoice/DR: {$delivery['invoice_no']}, Liters: {$delivery['delivery_liters']} L)", $station_id, $_SERVER['REMOTE_ADDR']??'']);
            } catch(Exception $ae){}

            $_SESSION['success'] = "Delivery <strong>DEL-{$delivery_id}</strong> successfully verified and added to inventory.";

        } elseif ($action === 'reject') {
            $reason = trim($_POST['remarks'] ?? '');
            if (empty($reason)) {
                throw new Exception("Rejection remarks are required.");
            }

            // Update status & notes
            $new_notes = trim(($delivery['notes'] ?? '') . " | Rejected Reason: " . $reason);
            $up = $pdo->prepare("UPDATE fuel_deliveries SET status='Rejected', verified_by=?, verified_at=NOW(), notes=? WHERE id=? AND station_id=?");
            $up->execute([$me['id'], $new_notes, $delivery_id, $station_id]);

            // Write to audit log
            try {
                $pdo->prepare("INSERT INTO audit_logs(user_id, action_type, entity_type, entity_id, details, station_id, ip_address, created_at) VALUES(?, 'Reject', 'fuel_delivery', ?, ?, ?, ?, NOW())")
                    ->execute([$me['id'], $delivery_id, "Rejected delivery DEL-{$delivery_id} (Reason: {$reason})", $station_id, $_SERVER['REMOTE_ADDR']??'']);
            } catch(Exception $ae){}

            $_SESSION['success'] = "Delivery <strong>DEL-{$delivery_id}</strong> has been rejected.";
        }

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
    header('Location: manager_fuel_deliveries_validation.php'); exit;
}

// ── GET Filters ──────────────────────────────────────────────
$date_from        = trim($_GET['date_from'] ?? date('Y-m-d', strtotime('-6 months')));
$date_to          = trim($_GET['date_to']   ?? date('Y-m-d'));
$fuel_type_filter = trim($_GET['fuel_type'] ?? '');
$status_filter    = trim($_GET['status_filter'] ?? 'all');
$dr_number_filter = trim($_GET['dr_number'] ?? '');
$search_query     = trim($_GET['search_query'] ?? '');
$export           = trim($_GET['export'] ?? '');

// Base SQL conditions
$where = ["fd.station_id = ?"];
$params = [$station_id];

// Date Filter
$where[] = "DATE(fd.delivery_date) BETWEEN ? AND ?";
$params[] = $date_from;
$params[] = $date_to;

// Fuel Type Filter
if ($fuel_type_filter !== '') {
    $where[] = "fd.fuel_type = ?";
    $params[] = $fuel_type_filter;
}

// DR Number Filter
if ($dr_number_filter !== '') {
    $where[] = "fd.invoice_no LIKE ?";
    $params[] = '%' . $dr_number_filter . '%';
}

// Search Query
if ($search_query !== '') {
    $where[] = "(LOWER(fd.batch_id) LIKE ? OR LOWER(fd.supplier) LIKE ? OR LOWER(fd.tanker_number) LIKE ? OR LOWER(staff.username) LIKE ? OR LOWER(staff.first_name) LIKE ? OR LOWER(staff.last_name) LIKE ? OR LOWER(fd.notes) LIKE ?)";
    $like_val = '%' . strtolower($search_query) . '%';
    $params = array_merge($params, [$like_val, $like_val, $like_val, $like_val, $like_val, $like_val, $like_val]);
}

// Copy filters for summary counts (before applying status filter)
$sc_where = $where;
$sc_params = $params;

// Apply Status Filter to main list
if ($status_filter !== 'all') {
    if ($status_filter === 'pending') {
        $where[] = "LOWER(fd.status) IN ('pending', 'pending validation', 'pending manager validation')";
    } elseif ($status_filter === 'verified') {
        $where[] = "LOWER(fd.status) IN ('verified', 'approved', 'validated')";
    } elseif ($status_filter === 'rejected') {
        $where[] = "LOWER(fd.status) = 'rejected'";
    }
}

// ── Fetch Deliveries ─────────────────────────────────────────
$deliveries = [];
try {
    $sql = "SELECT fd.*, 
                   COALESCE(
                       NULLIF(CONCAT(TRIM(COALESCE(staff.first_name, '')), ' ', TRIM(COALESCE(staff.last_name, ''))), ' '),
                       staff.username,
                       'Unknown'
                   ) as staff_name,
                   COALESCE(
                       NULLIF(CONCAT(TRIM(COALESCE(validator.first_name, '')), ' ', TRIM(COALESCE(validator.last_name, ''))), ' '),
                       validator.username,
                       '—'
                   ) as validator_name
            FROM fuel_deliveries fd
            LEFT JOIN users staff ON fd.received_by = staff.id
            LEFT JOIN users validator ON fd.verified_by = validator.id
            WHERE " . implode(" AND ", $where) . "
            ORDER BY fd.delivery_date DESC, fd.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Fetch deliveries error: " . $e->getMessage());
    $_SESSION['error'] = "Error loading deliveries: " . $e->getMessage();
}

// ── Compute Summary Card Metrics ─────────────────────────────
$pending_count = 0;
$verified_count = 0;
$rejected_count = 0;
$total_liters_delivered = 0.0;
$total_records = 0;

try {
    // 1. Pending (overall pending awaiting manager attention)
    $sp = $pdo->prepare("SELECT COUNT(*) FROM fuel_deliveries WHERE station_id = ? AND LOWER(status) IN ('pending', 'pending validation', 'pending manager validation')");
    $sp->execute([$station_id]);
    $pending_count = (int)$sp->fetchColumn();

    // 2. Verified (matching current filters)
    $sv_where = array_merge($sc_where, ["LOWER(fd.status) IN ('verified', 'approved', 'validated')"]);
    $sv = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(fd.delivery_liters), 0) FROM fuel_deliveries fd LEFT JOIN users staff ON fd.received_by = staff.id WHERE " . implode(" AND ", $sv_where));
    $sv->execute($sc_params);
    $sv_row = $sv->fetch(PDO::FETCH_NUM);
    $verified_count = (int)$sv_row[0];
    $total_liters_delivered = (float)$sv_row[1];

    // 3. Rejected (matching current filters)
    $sr_where = array_merge($sc_where, ["LOWER(fd.status) = 'rejected'"]);
    $sr = $pdo->prepare("SELECT COUNT(*) FROM fuel_deliveries fd LEFT JOIN users staff ON fd.received_by = staff.id WHERE " . implode(" AND ", $sr_where));
    $sr->execute($sc_params);
    $rejected_count = (int)$sr->fetchColumn();

    // 4. Total Delivery Records (in current filter list)
    $total_records = count($deliveries);
} catch (Exception $e) {
    error_log("Summary calculations error: " . $e->getMessage());
}

// ── Fetch dynamic fuel types for filter ──────────────────────
$fuel_types = [];
try {
    $ft_stmt = $pdo->prepare("SELECT DISTINCT fuel_type FROM fuel_deliveries WHERE station_id=? AND fuel_type IS NOT NULL AND fuel_type!='' ORDER BY fuel_type");
    $ft_stmt->execute([$station_id]);
    $fuel_types = $ft_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// ── EXPORTS ──────────────────────────────────────────────────
if (in_array($export, ['excel', 'pdf'])) {
    $headers = ['Delivery ID', 'Delivery Date', 'Batch ID', 'DR Number', 'Tanker Number', 'Fuel Type', 'Assigned Tank', 'Liters Delivered', 'Staff Receiver', 'Status', 'Verification Date', 'Remarks'];
    $rows_fmt = [];
    foreach ($deliveries as $d) {
        $rows_fmt[] = [
            'DEL-' . $d['id'],
            date('M d, Y', strtotime($d['delivery_date'])),
            $d['batch_id'] ?? '—',
            $d['invoice_no'] ?? '—',
            $d['tanker_number'] ?? '—',
            $d['fuel_type'],
            $d['tank_assigned'] ?? '—',
            number_format($d['delivery_liters'], 2),
            $d['staff_name'] ?? '—',
            getStatusLabel($d['status'] ?? ''),
            $d['verified_at'] ? date('M d, Y H:i', strtotime($d['verified_at'])) : '—',
            $d['notes'] ?? '—'
        ];
    }
    $filename = 'fuel_deliveries_oversight_' . $date_from . '_to_' . $date_to;

    if ($export === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="UTF-8"><style>table{border-collapse:collapse}th,td{border:1px solid #ddd;padding:7px}th{background:#002F6C;color:#fff;font-size:11px}</style></head><body>';
        echo '<h2>Fuel Deliveries Oversight Report</h2><p>Period: ' . $date_from . ' to ' . $date_to . ' | Records: ' . count($rows_fmt) . '</p>';
        echo '<table><thead><tr>';
        foreach ($headers as $h) echo '<th>' . htmlspecialchars($h) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows_fmt as $r) {
            echo '<tr>';
            foreach ($r as $c) echo '<td>' . htmlspecialchars($c) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></body></html>'; exit;
    }

    if ($export === 'pdf') {
        header('Content-Type: text/html; charset=UTF-8');
        $generated = date('M d, Y H:i');
        
        $tbody = '';
        foreach ($deliveries as $d) {
            $tbody .= '<tr>';
            $tbody .= '<td>DEL-' . htmlspecialchars($d['id']) . '</td>';
            $tbody .= '<td>' . date('M d, Y', strtotime($d['delivery_date'])) . '</td>';
            $tbody .= '<td>' . htmlspecialchars($d['batch_id'] ?? '—') . '</td>';
            $tbody .= '<td>' . htmlspecialchars($d['invoice_no'] ?? '—') . '</td>';
            $tbody .= '<td>' . htmlspecialchars($d['tanker_number'] ?? '—') . '</td>';
            $tbody .= '<td>' . htmlspecialchars($d['fuel_type']) . '</td>';
            $tbody .= '<td>' . htmlspecialchars($d['tank_assigned'] ?? '—') . '</td>';
            $tbody .= '<td style="text-align:right;">' . number_format($d['delivery_liters'], 2) . '</td>';
            $tbody .= '<td>' . htmlspecialchars($d['staff_name'] ?? '—') . '</td>';
            $tbody .= '<td>' . getStatusLabel($d['status'] ?? '') . '</td>';
            $tbody .= '<td>' . ($d['verified_at'] ? date('M d, Y', strtotime($d['verified_at'])) : '—') . '</td>';
            $tbody .= '<td>' . htmlspecialchars($d['notes'] ?? '—') . '</td>';
            $tbody .= '</tr>';
        }

        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Fuel Deliveries Oversight Report</title>
        <style>body{font-family:Arial,sans-serif;font-size:10px;padding:20px;color:#333;}
        .pbtn{margin-bottom:12px}@media print{.pbtn{display:none}}
        .hdr{border-bottom:3px solid #002F6C;margin-bottom:14px;padding-bottom:8px;display:flex;align-items:center;justify-content:between;}
        h1{color:#002F6C;font-size:16px;margin:0 0 4px;text-transform:uppercase;}
        table{width:100%;border-collapse:collapse;margin-top:10px;}
        th{background:#002F6C;color:#fff;padding:6px;font-size:8px;text-transform:uppercase;text-align:left;}
        td{padding:5px;border-bottom:1px solid #e2e8f0;font-size:8px;}
        tr:nth-child(even) td{background:#f8fafc}
        </style></head><body>';
        echo '<div class="pbtn"><button onclick="window.print()" style="background:#002F6C;color:#fff;border:none;padding:8px 18px;border-radius:5px;cursor:pointer;font-weight:bold;">🖨 Print / Save PDF</button>
        <a href="javascript:history.back()" style="margin-left:8px;background:#6c757d;color:#fff;border:none;padding:8px 18px;border-radius:5px;cursor:pointer;text-decoration:none;font-weight:bold;">← Back</a></div>';
        echo '<div class="hdr"><div><h1>Petron Fuel Deliveries Oversight</h1><p style="margin:2px 0 0;color:#666;">Period: ' . htmlspecialchars($date_from) . ' — ' . htmlspecialchars($date_to) . ' | Station: ' . htmlspecialchars(user_station_name()) . '</p></div><div style="text-align:right;"><p style="margin:0;">Generated: ' . $generated . '</p></div></div>';
        echo '<table><thead><tr><th>Del ID</th><th>Date</th><th>Batch ID</th><th>DR Number</th><th>Tanker No</th><th>Fuel Type</th><th>Tank</th><th>Liters</th><th>Staff</th><th>Status</th><th>Val Date</th><th>Remarks</th></tr></thead>';
        echo '<tbody>' . ($tbody ?: '<tr><td colspan="12" style="text-align:center;padding:20px;color:#94a3b8">No records found.</td></tr>') . '</tbody></table>';
        echo '</body></html>'; exit;
    }
}

require_once __DIR__ . '/../partials/header.php';
?>

<style>
/* Reset and core alignment */
* { box-sizing: border-box; }
html, body { max-width: 100%; width: 100%; overflow-x: hidden !important; position: relative; }
.mftv-wrap { max-width: 100%; width: 100%; box-sizing: border-box; }

/* Petron clean headers */
.int-head { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; margin-top: -12px !important; }
.int-head h1 { font-size: 22px !important; font-weight: 700 !important; color: #00264D !important; margin: 0 !important; text-transform: uppercase !important; display: flex; align-items: center; gap: 8px; }
.int-head .sub { font-size: 13px; color: #64748b; margin-top: 4px; }

/* Outline buttons */
.ato-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    padding: 0 16px; border-radius: 7px; font-size: 13px; font-weight: 600;
    cursor: pointer; border: 1px solid transparent; text-decoration: none; transition: all .15s;
    height: 36px; white-space: nowrap; background: white !important;
}
.ato-btn-excel  { color: #1d6f42 !important; border-color: #1d6f42 !important; }
.ato-btn-excel:hover  { background: #1d6f42 !important; color: #fff !important; }
.ato-btn-pdf    { color: #dc2626 !important; border-color: #dc2626 !important; }
.ato-btn-pdf:hover    { background: #dc2626 !important; color: #fff !important; }
.ato-btn-back   { color: #4b5563 !important; border-color: #6b7280 !important; }
.ato-btn-back:hover   { background: #6b7280 !important; color: #fff !important; }
.ato-btn-filter { color: #002F70 !important; border-color: #002F70 !important; }
.ato-btn-filter:hover { background: #002F70 !important; color: #fff !important; }
.ato-btn-reset  { color: #475569 !important; border-color: #cbd5e1 !important; }
.ato-btn-reset:hover  { background: #f1f5f9 !important; }

/* Summary Cards matching standard */
.afto-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 20px; }
.afto-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 11px; padding: 16px; display: flex; align-items: center; gap: 14px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
.afto-card.c-blue  { border-left: 4px solid #3b82f6; }
.afto-card.c-green { border-left: 4px solid #10b981; }
.afto-card.c-red   { border-left: 4px solid #ef4444; }
.afto-card.c-amber { border-left: 4px solid #f59e0b; }
.afto-card.c-purple{ border-left: 4px solid #8b5cf6; }

.afto-card-ico { width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 19px; flex-shrink: 0; }
.afto-card.c-blue .afto-card-ico   { background: #eff6ff; color: #3b82f6; }
.afto-card.c-green .afto-card-ico  { background: #f0fdf4; color: #10b981; }
.afto-card.c-red .afto-card-ico    { background: #fef2f2; color: #ef4444; }
.afto-card.c-amber .afto-card-ico { background: #fffbeb; color: #f59e0b; }
.afto-card.c-purple .afto-card-ico { background: #faf5ff; color: #8b5cf6; }

.afto-card-meta h3 { margin: 0; font-size: 10px; color: #64748b; text-transform: uppercase; font-weight: 700; letter-spacing: .5px; }
.afto-card-meta h2 { margin: 2px 0 0; font-size: 22px; font-weight: 700; color: #00264D; line-height: 1; }
.afto-card-meta span { font-size: 11px; color: #94a3b8; display: block; margin-top: 2px; }

/* Filter Bar */
.afto-filter { display: flex; align-items: flex-end; gap: 10px; flex-wrap: wrap; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 16px; margin-bottom: 20px; }
.afto-fg { display: flex; flex-direction: column; gap: 3px; }
.afto-fg label { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .4px; }
.afto-fg input, .afto-fg select { height: 36px; padding: 0 10px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 13px; color: #1e293b; background: #fff; outline: none; box-sizing: border-box; }
.afto-fg input:focus, .afto-fg select:focus { border-color: #002F70; box-shadow: 0 0 0 3px rgba(0,47,112,.1); }

/* Table Container & Layout */
.afto-table-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 11px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.04); width: 100%; }
.afto-table-hd { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid #f1f5f9; flex-wrap: wrap; gap: 8px; }
.afto-table-title { font-size: 13px; font-weight: 700; color: #00264D; text-transform: uppercase; letter-spacing: .3px; margin: 0; }
.afto-tbl-wrap { width: 100%; overflow-x: auto; }
.afto-tbl { width: 100%; border-collapse: collapse; font-size: 11px; }
.afto-tbl thead tr { background: #002F70; }
.afto-tbl thead th { padding: 9px 10px; text-align: left; font-size: 11px; font-weight: 700; color: #fff; text-transform: uppercase; letter-spacing: .4px; border-bottom: 2px solid #001a3d; vertical-align: middle; white-space: nowrap; }
.afto-tbl tbody tr { border-bottom: 1px solid #f1f5f9; transition: background .1s; }
.afto-tbl tbody tr:hover td { background: #eff6ff; }
.afto-tbl tbody td { padding: 9px 10px; color: #334155; vertical-align: middle; white-space: nowrap; background: #fff; font-size: 11px; }

/* Status Badges */
.afto-badge { display: inline-block; padding: 3px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; white-space: nowrap; text-transform: uppercase; }
.bg-amber  { background: #fffbeb; color: #b45309; border: 1px solid #fef3c7; }
.bg-green  { background: #f0fdf4; color: #166534; border: 1px solid #dcfce7; }
.bg-red    { background: #fef2f2; color: #991b1b; border: 1px solid #fee2e2; }
.bg-blue   { background: #eff6ff; color: #1e40af; border: 1px solid #dbeafe; }
.bg-gray   { background: #f8fafc; color: #475569; border: 1px solid #e2e8f0; }

/* Action buttons */
.row-btn {
    padding: 0 10px; border-radius: 5px; font-size: 11px; font-weight: 700; border: 1px solid transparent; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center; gap: 4px; transition: all .15s; text-transform: uppercase;
    height: 28px; background: white !important; text-decoration: none;
}
.row-btn-info    { color: #0284c7 !important; border-color: #0284c7 !important; }
.row-btn-info:hover    { background: #0284c7 !important; color: #fff !important; }
.row-btn-success { color: #16a34a !important; border-color: #16a34a !important; }
.row-btn-success:hover { background: #16a34a !important; color: #fff !important; }
.row-btn-danger  { color: #dc2626 !important; border-color: #dc2626 !important; }
.row-btn-danger:hover  { background: #dc2626 !important; color: #fff !important; }
.row-btn-print   { color: #4b5563 !important; border-color: #4b5563 !important; }
.row-btn-print:hover   { background: #4b5563 !important; color: #fff !important; }

/* Empty state */
.afto-empty { text-align: center; padding: 60px 20px; color: #94a3b8; }
.afto-empty i { font-size: 44px; display: block; margin-bottom: 14px; opacity: .4; }

/* Modal styles */
.modal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.5); overflow-y: auto; }
.modal-content { background: #fff; margin: 10% auto; padding: 24px; border-radius: 12px; width: 90%; max-width: 500px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); }
.modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid #f1f5f9; padding-bottom: 10px; }
.modal-header h3 { margin: 0; font-size: 15px; color: #00264D; font-weight: 700; text-transform: uppercase; }
.modal-close { cursor: pointer; font-size: 20px; color: #94a3b8; font-weight: bold; }
.modal-close:hover { color: #dc2626; }
.modal-body { margin-bottom: 18px; }
.modal-footer { display: flex; gap: 8px; justify-content: flex-end; }

/* Form field inside modal */
.form-group { margin-bottom: 14px; }
.form-group label { display: block; font-size: 11px; font-weight: 600; color: #475569; margin-bottom: 4px; text-transform: uppercase; }
.form-group textarea, .form-group input { width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; outline: none; background: #fff; }
.form-group textarea:focus, .form-group input:focus { border-color: #002F70; box-shadow: 0 0 0 3px rgba(0,47,112,.1); }

/* Details list inside view modal */
.details-list { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 20px; }
.details-item { border-bottom: 1px solid #f1f5f9; padding-bottom: 6px; }
.details-label { font-size: 10px; color: #64748b; text-transform: uppercase; font-weight: bold; }
.details-value { font-size: 12px; color: #1e293b; font-weight: 600; margin-top: 2px; }

/* Page buttons */
.page-btn { padding: 6px 12px; border: 1px solid #e2e8f0; border-radius: 6px; background: #fff; color: #64748b; font-size: 12px; cursor: pointer; transition: all .15s; }
.page-btn:hover:not(:disabled) { background: #f1f5f9; border-color: #cbd5e1; color: #1e293b; }
.page-btn:disabled { opacity: 0.5; cursor: not-allowed; }
</style>

<div class="mftv-wrap">
    <!-- Page Header -->
    <div class="int-head">
        <div>
            <h1><i class="fas fa-truck-loading"></i> Fuel Deliveries Oversight</h1>
            <div class="sub">Monitor, audit, and validate fuel deliveries to maintain accurate station inventories.</div>
        </div>
        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <button type="button" onclick="mftvExport('excel')" class="ato-btn ato-btn-excel"><i class="fas fa-file-excel"></i> Excel</button>
            <button type="button" onclick="mftvExport('pdf')" class="ato-btn ato-btn-pdf"><i class="fas fa-file-pdf"></i> PDF</button>
            <a href="manager_dashboard.php" class="ato-btn ato-btn-back"><i class="fas fa-arrow-left"></i> Back</a>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="afto-cards">
        <div class="afto-card c-blue">
            <div class="afto-card-ico"><i class="fas fa-clock"></i></div>
            <div class="afto-card-meta">
                <h3>Pending Deliveries</h3>
                <h2><?= number_format($pending_count) ?></h2>
                <span>Awaiting validation</span>
            </div>
        </div>
        <div class="afto-card c-green">
            <div class="afto-card-ico"><i class="fas fa-check-circle"></i></div>
            <div class="afto-card-meta">
                <h3>Verified Deliveries</h3>
                <h2><?= number_format($verified_count) ?></h2>
                <span>Approved and loaded</span>
            </div>
        </div>
        <div class="afto-card c-red">
            <div class="afto-card-ico"><i class="fas fa-times-circle"></i></div>
            <div class="afto-card-meta">
                <h3>Rejected Deliveries</h3>
                <h2><?= number_format($rejected_count) ?></h2>
                <span>Returned deliveries</span>
            </div>
        </div>
        <div class="afto-card c-amber">
            <div class="afto-card-ico"><i class="fas fa-tint"></i></div>
            <div class="afto-card-meta">
                <h3>Total Liters Delivered</h3>
                <h2><?= number_format($total_liters_delivered, 2) ?> L</h2>
                <span>Verified volume</span>
            </div>
        </div>
        <div class="afto-card c-purple">
            <div class="afto-card-ico"><i class="fas fa-layer-group"></i></div>
            <div class="afto-card-meta">
                <h3>Total Delivery Records</h3>
                <h2><?= number_format($total_records) ?></h2>
                <span>Matching filter logs</span>
            </div>
        </div>
    </div>

    <!-- Filter Bar -->
    <form method="get" class="afto-filter">
        <div class="afto-fg">
            <label>Date From</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
        </div>
        <div class="afto-fg">
            <label>Date To</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
        </div>
        <div class="afto-fg">
            <label>Fuel Type</label>
            <select name="fuel_type">
                <option value="">All Fuel Types</option>
                <?php foreach ($fuel_types as $ft): ?>
                    <option value="<?= htmlspecialchars($ft) ?>" <?= $fuel_type_filter === $ft ? 'selected' : '' ?>><?= htmlspecialchars($ft) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="afto-fg">
            <label>Status</label>
            <select name="status_filter">
                <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Awaiting Validation</option>
                <option value="verified" <?= $status_filter === 'verified' ? 'selected' : '' ?>>Validated</option>
                <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
            </select>
        </div>
        <div class="afto-fg">
            <label>DR Number</label>
            <input type="text" name="dr_number" value="<?= htmlspecialchars($dr_number_filter) ?>" placeholder="DR / Invoice No">
        </div>
        <div class="afto-fg">
            <label>Search</label>
            <input type="text" name="search_query" value="<?= htmlspecialchars($search_query) ?>" placeholder="Supplier, Tanker No, Staff...">
        </div>
        <div style="display: flex; gap: 8px;">
            <button type="submit" class="ato-btn ato-btn-filter"><i class="fas fa-filter"></i> Apply Filter</button>
            <a href="manager_fuel_deliveries_validation.php" class="ato-btn ato-btn-reset"><i class="fas fa-times"></i> Reset</a>
        </div>
    </form>

    <!-- Table Card -->
    <div class="afto-table-card">
        <div class="afto-table-hd">
            <h3 class="afto-table-title"><i class="fas fa-list"></i> Fuel Deliveries Log</h3>
            <span style="font-size: 11px; color: #64748b; font-weight: 600;"><?= number_format(count($deliveries)) ?> record(s) found</span>
        </div>
        <div class="afto-tbl-wrap">
            <table class="afto-tbl">
                <thead>
                    <tr>
                        <th>Delivery ID</th>
                        <th>Delivery Date</th>
                        <th>Batch ID</th>
                        <th>DR Number</th>
                        <th>Tanker Number</th>
                        <th>Fuel Type</th>
                        <th>Assigned Tank</th>
                        <th style="text-align: right;">Liters Delivered</th>
                        <th>Staff Receiver</th>
                        <th>Status</th>
                        <th>Verification Date</th>
                        <th>Remarks</th>
                        <th style="text-align: center; width: 150px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($deliveries)): ?>
                        <tr>
                            <td colspan="13">
                                <div class="afto-empty">
                                    <i class="fas fa-inbox"></i>
                                    <div style="font-size: 15px; font-weight: 700; color: #64748b; margin-bottom: 4px;">No records found</div>
                                    <div style="font-size: 13px;">No deliveries match the selected filters.</div>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($deliveries as $d):
                            $del_label = 'DEL-' . $d['id'];
                            $dr_display = !empty($d['invoice_no']) ? $d['invoice_no'] : '—';
                            $tanker_display = !empty($d['tanker_number']) ? $d['tanker_number'] : '—';
                            $batch_display = !empty($d['batch_id']) ? $d['batch_id'] : '—';
                            $tank_display = !empty($d['tank_assigned']) ? $d['tank_assigned'] : '—';
                        ?>
                            <tr id="del_row_<?= $d['id'] ?>">
                                <td style="font-weight: 600; color: #00264D;"><?= $del_label ?></td>
                                <td><?= date('M d, Y', strtotime($d['delivery_date'])) ?></td>
                                <td><?= htmlspecialchars($batch_display) ?></td>
                                <td><?= htmlspecialchars($dr_display) ?></td>
                                <td><?= htmlspecialchars($tanker_display) ?></td>
                                <td><?= htmlspecialchars($d['fuel_type']) ?></td>
                                <td><?= htmlspecialchars($tank_display) ?></td>
                                <td style="text-align: right; font-weight: 700; color: #1e293b;"><?= number_format($d['delivery_liters'], 2) ?> L</td>
                                <td><?= htmlspecialchars($d['staff_name'] ?? '—') ?></td>
                                <td><span class="afto-badge <?= getStatusBadgeClass($d['status'] ?? '') ?>"><?= getStatusLabel($d['status'] ?? '') ?></span></td>
                                <td><?= $d['verified_at'] ? date('M d, Y H:i', strtotime($d['verified_at'])) : '—' ?></td>
                                <td style="max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($d['notes'] ?? '—') ?>">
                                    <?= htmlspecialchars($d['notes'] ?? '—') ?>
                                </td>
                                <td style="text-align: center;">
                                    <div style="display: inline-flex; gap: 4px;">
                                        <button type="button" class="row-btn row-btn-info" onclick="viewDetails(<?= htmlspecialchars(json_encode($d)) ?>)" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if (in_array(strtolower(trim($d['status'] ?? '')), ['pending', 'pending validation', 'pending manager approval', 'pending manager validation'])): ?>
                                            <button type="button" class="row-btn row-btn-success" onclick="openValidate(<?= $d['id'] ?>, '<?= $del_label ?>')" title="Verify Delivery">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <button type="button" class="row-btn row-btn-danger" onclick="openReject(<?= $d['id'] ?>, '<?= $del_label ?>')" title="Reject Delivery">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        <?php endif; ?>
                                        <button type="button" class="row-btn row-btn-print" onclick="printSingleDelivery(<?= htmlspecialchars(json_encode($d)) ?>)" title="Print Delivery Receipt">
                                            <i class="fas fa-print"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

            <!-- Client-side Pagination -->
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-top: 1px solid #f1f5f9; flex-wrap: wrap; gap: 12px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <label style="font-size: 12px; color: #64748b; font-weight: 600;">Rows per page:</label>
                    <select id="rowsPerPage" style="padding: 6px 10px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 12px; cursor: pointer;">
                        <option value="10">10</option>
                        <option value="25" selected>25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span id="pageInfo" style="font-size: 12px; color: #64748b; font-weight: 600;">Page 1 of 1</span>
                    <div style="display: flex; gap: 4px;">
                        <button id="prevPage" class="page-btn" disabled><i class="fas fa-chevron-left"></i> Prev</button>
                        <button id="nextPage" class="page-btn">Next <i class="fas fa-chevron-right"></i></button>
                    </div>
                </div>
            </div>
    </div>
</div>

<!-- View Details Modal -->
<div id="viewModal" class="modal">
    <div class="modal-content" style="max-width: 550px;">
        <div class="modal-header">
            <h3>Delivery Details</h3>
            <span class="modal-close" onclick="closeModal('viewModal')">&times;</span>
        </div>
        <div class="modal-body">
            <div class="details-list">
                <div class="details-item">
                    <div class="details-label">Delivery ID</div>
                    <div class="details-value" id="det_id">—</div>
                </div>
                <div class="details-item">
                    <div class="details-label">Delivery Date</div>
                    <div class="details-value" id="det_date">—</div>
                </div>
                <div class="details-item">
                    <div class="details-label">Batch ID</div>
                    <div class="details-value" id="det_batch">—</div>
                </div>
                <div class="details-item">
                    <div class="details-label">DR Number</div>
                    <div class="details-value" id="det_dr">—</div>
                </div>
                <div class="details-item">
                    <div class="details-label">Tanker Number</div>
                    <div class="details-value" id="det_tanker">—</div>
                </div>
                <div class="details-item">
                    <div class="details-label">Fuel Type</div>
                    <div class="details-value" id="det_fuel">—</div>
                </div>
                <div class="details-item">
                    <div class="details-label">Assigned Tank</div>
                    <div class="details-value" id="det_tank">—</div>
                </div>
                <div class="details-item">
                    <div class="details-label">Liters Delivered</div>
                    <div class="details-value" id="det_liters" style="color: #002F70; font-weight: 700;">—</div>
                </div>
                <div class="details-item">
                    <div class="details-label">Staff Receiver</div>
                    <div class="details-value" id="det_staff">—</div>
                </div>
                <div class="details-item">
                    <div class="details-label">Status</div>
                    <div class="details-value" id="det_status">—</div>
                </div>
                <div class="details-item">
                    <div class="details-label">Verified By</div>
                    <div class="details-value" id="det_validator">—</div>
                </div>
                <div class="details-item">
                    <div class="details-label">Verification Date</div>
                    <div class="details-value" id="det_val_date">—</div>
                </div>
                <div class="details-item" style="grid-column: span 2;">
                    <div class="details-label">Supplier / Source</div>
                    <div class="details-value" id="det_supplier">—</div>
                </div>
                <div class="details-item" style="grid-column: span 2;">
                    <div class="details-label">Remarks / Audit Note</div>
                    <div class="details-value" id="det_remarks" style="white-space: pre-wrap; font-weight: normal; color: #475569;">—</div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="ato-btn ato-btn-back" onclick="closeModal('viewModal')">Close</button>
        </div>
    </div>
</div>

<!-- Verify Delivery Confirmation Modal -->
<div id="validateModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Verify Fuel Delivery</h3>
            <span class="modal-close" onclick="closeModal('validateModal')">&times;</span>
        </div>
        <form method="post" id="validateForm">
            <div class="modal-body">
                <input type="hidden" name="action" value="verify">
                <input type="hidden" name="id" id="val_id_field">
                <p id="val_prompt" style="font-size: 13px; color: #475569; margin: 0; font-weight: 500; line-height: 1.5;"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="ato-btn ato-btn-back" onclick="closeModal('validateModal')">Cancel</button>
                <button type="submit" class="ato-btn" style="background:#16a34a !important; color:#fff !important; border-color:#16a34a !important;"><i class="fas fa-check"></i> Confirm Verification</button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Delivery Modal -->
<div id="rejectModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Reject Delivery</h3>
            <span class="modal-close" onclick="closeModal('rejectModal')">&times;</span>
        </div>
        <form method="post" id="rejectForm">
            <div class="modal-body">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="id" id="rej_id_field">
                <p id="rej_prompt" style="font-size: 13px; color: #475569; margin: 0 0 14px; font-weight: 500; line-height: 1.5;"></p>
                <div class="form-group">
                    <label>Rejection Remarks <span style="color:#dc2626;">*</span></label>
                    <textarea name="remarks" rows="3" required placeholder="State the reason for rejecting this delivery (e.g., quantity mismatch, damaged seal)..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="ato-btn ato-btn-back" onclick="closeModal('rejectModal')">Cancel</button>
                <button type="submit" class="ato-btn" style="background:#dc2626 !important; color:#fff !important; border-color:#dc2626 !important;"><i class="fas fa-times"></i> Reject Delivery</button>
            </div>
        </form>
    </div>
</div>

<script>
// Details View Modal
function viewDetails(d) {
    const del_label = 'DEL-' + d.id;
    const dr_display = d.invoice_no ? d.invoice_no : '—';
    const tanker_display = d.tanker_number ? d.tanker_number : '—';
    const batch_display = d.batch_id ? d.batch_id : '—';
    const tank_display = d.tank_assigned ? d.tank_assigned : '—';
    
    document.getElementById('det_id').textContent = del_label;
    document.getElementById('det_date').textContent = d.delivery_date || '—';
    document.getElementById('det_batch').textContent = batch_display;
    document.getElementById('det_dr').textContent = dr_display;
    document.getElementById('det_tanker').textContent = tanker_display;
    document.getElementById('det_fuel').textContent = d.fuel_type || '—';
    document.getElementById('det_tank').textContent = tank_display;
    document.getElementById('det_liters').textContent = parseFloat(d.delivery_liters || 0).toLocaleString(undefined, {minimumFractionDigits: 2}) + ' L';
    document.getElementById('det_staff').textContent = d.staff_name || '—';
    document.getElementById('det_status').innerHTML = `<span class="afto-badge ${getStatusBadgeClass(d.status)}">${getStatusLabel(d.status)}</span>`;
    document.getElementById('det_validator').textContent = d.validator_name || '—';
    document.getElementById('det_val_date').textContent = d.verified_at || '—';
    document.getElementById('det_supplier').textContent = d.supplier || '—';
    document.getElementById('det_remarks').textContent = d.notes || '—';
    
    document.getElementById('viewModal').style.display = 'block';
}

function getStatusBadgeClass(status) {
    const s = String(status || '').toLowerCase().trim();
    if (s.includes('pending')) return 'bg-amber';
    if (s === 'verified' || s === 'approved' || s === 'validated') return 'bg-green';
    if (s === 'rejected') return 'bg-red';
    return 'bg-gray';
}

function getStatusLabel(status) {
    const s = String(status || '').toLowerCase().trim();
    if (s.includes('pending')) return 'Pending';
    if (s === 'verified' || s === 'approved' || s === 'validated') return 'Verified';
    if (s === 'rejected') return 'Rejected';
    return status;
}

// Validate / Reject Modals
function openValidate(id, label) {
    document.getElementById('val_id_field').value = id;
    document.getElementById('val_prompt').innerHTML = `Are you sure you want to verify and approve delivery <strong>${label}</strong>? This will add the liters to the station tank inventory.`;
    document.getElementById('validateModal').style.display = 'block';
}

function openReject(id, label) {
    document.getElementById('rej_id_field').value = id;
    document.getElementById('rej_prompt').innerHTML = `Are you sure you want to reject delivery <strong>${label}</strong>? This will return it to the staff for correction.`;
    document.getElementById('rejectModal').style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}

// Client-side pagination logic
(function() {
    const tableBody = document.querySelector('.afto-tbl tbody');
    if (!tableBody) return;

    const allRows = Array.from(tableBody.querySelectorAll('tr'));
    let currentPage = 1;
    let rowsPerPage = 25;

    const rowsSelect = document.getElementById('rowsPerPage');
    const pageInfo = document.getElementById('pageInfo');
    const prevBtn = document.getElementById('prevPage');
    const nextBtn = document.getElementById('nextPage');

    function updateTable() {
        const totalPages = Math.ceil(allRows.length / rowsPerPage) || 1;
        if (currentPage > totalPages) currentPage = totalPages;
        const start = (currentPage - 1) * rowsPerPage;
        const end = start + rowsPerPage;

        allRows.forEach(row => row.style.display = 'none');
        allRows.slice(start, end).forEach(row => row.style.display = '');

        pageInfo.textContent = `Page ${currentPage} of ${totalPages}`;
        prevBtn.disabled = currentPage === 1;
        nextBtn.disabled = currentPage === totalPages;
    }

    if (rowsSelect) {
        rowsSelect.addEventListener('change', function() {
            rowsPerPage = parseInt(this.value);
            currentPage = 1;
            updateTable();
        });
    }

    if (prevBtn) {
        prevBtn.addEventListener('click', function() {
            if (currentPage > 1) {
                currentPage--;
                updateTable();
                document.querySelector('.afto-table-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    }

    if (nextBtn) {
        nextBtn.addEventListener('click', function() {
            const totalPages = Math.ceil(allRows.length / rowsPerPage) || 1;
            if (currentPage < totalPages) {
                currentPage++;
                updateTable();
                document.querySelector('.afto-table-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    }

    updateTable();
})();

// Export Helper
function mftvExport(format) {
    const params = new URLSearchParams(window.location.search);
    params.set('export', format);
    window.location.href = '?' + params.toString();
}

// Single Delivery Print Helper
function printSingleDelivery(d) {
    const tanker_display = d.tanker_number ? d.tanker_number : '—';
    const batch_display = d.batch_id ? d.batch_id : '—';
    const tank_display = d.tank_assigned ? d.tank_assigned : '—';
    
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
    doc.write(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>Delivery Slip</title>
    <style>
        body{font-family:'Courier New',monospace;font-size:12px;padding:20px;color:#000;}
        .receipt-header{text-align:center;border-bottom:1px dashed #000;padding-bottom:10px;margin-bottom:10px;}
        .receipt-line{display:flex;justify-content:between;margin:4px 0;}
        .receipt-line span{display:inline-block;}
        .receipt-line span:first-child{font-weight:bold;}
        .total-row{font-size:14px;font-weight:bold;border-top:1px dashed #000;border-bottom:1px dashed #000;padding:6px 0;margin:8px 0;}
    </style></head><body>
        <div class="receipt-header">
            <h3>PETRON FUEL DELIVERY SLIP</h3>
            <p>${escapeHtml(user_station_name())}</p>
        </div>
        <div class="receipt-line"><span>Delivery ID:</span><span>DEL-${escapeHtml(d.id)}</span></div>
        <div class="receipt-line"><span>Date:</span><span>${escapeHtml(d.delivery_date)}</span></div>
        <div class="receipt-line"><span>Batch ID:</span><span>${escapeHtml(batch_display)}</span></div>
        <div class="receipt-line"><span>DR Number:</span><span>${escapeHtml(d.invoice_no)}</span></div>
        <div class="receipt-line"><span>Tanker No:</span><span>${escapeHtml(tanker_display)}</span></div>
        <div class="receipt-line"><span>Fuel Type:</span><span>${escapeHtml(d.fuel_type)}</span></div>
        <div class="receipt-line"><span>Assigned Tank:</span><span>${escapeHtml(tank_display)}</span></div>
        <div class="total-row"><span style="float:left;">DELIVERED:</span><span style="float:right;">${parseFloat(d.delivery_liters || 0).toFixed(2)} L</span><div style="clear:both;"></div></div>
        <div class="receipt-line"><span>Staff Receiver:</span><span>${escapeHtml(d.staff_name || '')}</span></div>
        <div class="receipt-line"><span>Status:</span><span>${escapeHtml(getStatusLabel(d.status))}</span></div>
        <div class="receipt-line"><span>Validator:</span><span>${escapeHtml(d.validator_name || '—')}</span></div>
        <div class="receipt-line"><span>Val Date:</span><span>${escapeHtml(d.verified_at || '—')}</span></div>
        <div class="receipt-line"><span>Remarks:</span><span>${escapeHtml(d.notes || '—')}</span></div>
        <div style="margin-top:15px;text-align:center;font-size:10px;border-top:1px dashed #000;padding-top:10px;">For internal record only.</div>
    </body></html>`);
    doc.close();

    setTimeout(() => {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
    }, 250);
}

function escapeHtml(text) {
    if (!text) return '';
    return text.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
