<?php
// ============================================================
// Manager Fuel Adjustments Oversight – manager_fuel_adjustments.php
// Purpose: Rebuilt to support summary cards, filters, adjustments table,
//          Approve/Reject actions, View Details, Print Slip, and PDF/Excel exports.
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'fuel_adjustments';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();

// Access control - Manager only
if (!in_array($role, ['manager', 'supervisor', 'admin'])) {
    $_SESSION['error'] = 'Access denied. Manager access required.';
    header('Location: staff_dashboard.php'); 
    exit;
}

if ($station_id <= 0) {
    $_SESSION['error'] = 'No station assigned.';
    header('Location: manager_dashboard.php'); 
    exit;
}

// ── Status Badge Styles / Helper Functions ───────────────────
if (!function_exists('getStatusBadgeClass')) {
    function getStatusBadgeClass($status) {
        $s = strtolower(trim($status ?? ''));
        if ($s === 'pending') return 'bg-amber';
        if ($s === 'approved' || $s === 'validated' || $s === 'cleared') return 'bg-green';
        if ($s === 'rejected') return 'bg-red';
        return 'bg-gray';
    }
}

if (!function_exists('getStatusLabel')) {
    function getStatusLabel($status) {
        $s = strtolower(trim($status ?? ''));
        if ($s === 'pending') return 'Pending';
        if ($s === 'approved' || $s === 'cleared') return 'Approved';
        if ($s === 'rejected') return 'Rejected';
        return ucfirst($status);
    }
}

// ── PRINT FRIENDLY VIEW ──────────────────────────────────────
if (isset($_GET['print_id'])) {
    $print_id = (int)$_GET['print_id'];
    $stmt = $pdo->prepare("
        SELECT fa.*,
               COALESCE(NULLIF(CONCAT(TRIM(u.first_name), ' ', TRIM(u.last_name)), ' '), u.username, '—') as requested_by_name,
               COALESCE(NULLIF(CONCAT(TRIM(app.first_name), ' ', TRIM(app.last_name)), ' '), app.username, '—') as approved_by_name
        FROM fuel_adjustments fa
        LEFT JOIN users u ON fa.user_id = u.id
        LEFT JOIN users app ON fa.approved_by = app.id
        WHERE fa.id = ? AND fa.station_id = ?
    ");
    $stmt->execute([$print_id, $station_id]);
    $adj = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$adj) {
        die('Adjustment record not found or access denied.');
    }
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Fuel Adjustment Receipt #<?= htmlspecialchars($adj['id']) ?></title>
        <style>
            body { font-family: 'Courier New', Courier, monospace; font-size: 13px; color: #000; padding: 20px; line-height: 1.5; }
            .receipt-wrap { max-width: 380px; margin: 0 auto; border: 1px dashed #aaa; padding: 15px; }
            .center { text-align: center; }
            .logo { font-weight: bold; font-size: 18px; color: #002F70; margin-bottom: 2px; }
            .title { font-weight: bold; border-bottom: 1px dashed #000; padding-bottom: 8px; margin-bottom: 12px; font-size: 14px; }
            .row { display: flex; justify-content: space-between; margin-bottom: 5px; }
            .label { font-weight: bold; }
            .value { text-align: right; }
            .section { border-top: 1px dashed #000; margin-top: 10px; padding-top: 10px; }
            .notes-box { font-style: italic; font-size: 11px; margin-top: 8px; border: 1px dotted #ccc; padding: 6px; background: #fafafa; }
            @media print {
                body { padding: 0; }
                .receipt-wrap { border: none; }
            }
        </style>
    </head>
    <body onload="window.print();">
        <div class="receipt-wrap">
            <div class="center">
                <div class="logo">PETRON CORPORATION</div>
                <div>Station ID: <?= htmlspecialchars($station_id) ?></div>
                <div class="title">FUEL ADJUSTMENT SLIP</div>
            </div>
            
            <div class="row"><span class="label">Adjustment ID:</span><span class="value">ADJ-<?= htmlspecialchars($adj['id']) ?></span></div>
            <div class="row"><span class="label">Date:</span><span class="value"><?= date('M d, Y', strtotime($adj['adjustment_date'])) ?></span></div>
            <div class="row"><span class="label">Adjustment Type:</span><span class="value"><?= htmlspecialchars($adj['adjustment_type']) ?></span></div>
            <div class="row"><span class="label">Fuel Type / Tank:</span><span class="value"><?= htmlspecialchars($adj['fuel_type']) ?></span></div>
            
            <div class="section">
                <div class="row"><span class="label">Previous Level:</span><span class="value"><?= number_format($adj['previous_value'], 2) ?> L</span></div>
                <div class="row"><span class="label">New Level:</span><span class="value"><?= number_format($adj['new_value'], 2) ?> L</span></div>
                <div class="row" style="font-size: 14px; font-weight: bold;"><span class="label">Difference:</span><span class="value"><?= ($adj['liters'] >= 0 ? '+' : '') . number_format($adj['liters'], 2) ?> L</span></div>
            </div>

            <div class="section">
                <div><span class="label">Reason / Explanation:</span></div>
                <div class="notes-box"><?= htmlspecialchars($adj['reason'] ?: 'No explanation provided.') ?></div>
            </div>

            <div class="section">
                <div class="row"><span class="label">Requested By:</span><span class="value"><?= htmlspecialchars($adj['requested_by_name']) ?></span></div>
                <div class="row"><span class="label">Status:</span><span class="value"><?= htmlspecialchars(getStatusLabel($adj['status'])) ?></span></div>
                <?php if ($adj['status'] !== 'Pending'): ?>
                    <div class="row"><span class="label">Processed By:</span><span class="value"><?= htmlspecialchars($adj['approved_by_name']) ?></span></div>
                    <div class="row"><span class="label">Process Date:</span><span class="value"><?= date('M d, Y H:i', strtotime($adj['approved_at'])) ?></span></div>
                <?php endif; ?>
                <?php if (!empty($adj['notes'])): ?>
                    <div style="margin-top: 5px;"><span class="label">Remarks:</span></div>
                    <div class="notes-box"><?= htmlspecialchars($adj['notes']) ?></div>
                <?php endif; ?>
            </div>

            <div class="center section" style="font-size: 10px; color: #666; margin-top: 20px;">
                Petron Fuel Management System<br>
                Official Audit Copy
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ── POST Actions (Approve / Reject / Create New) ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['export'])) {
    $action = trim($_POST['action'] ?? '');

    // 1. APPROVE ADJUSTMENT
    if ($action === 'approve') {
        $adj_id = (int)($_POST['id'] ?? 0);
        if ($adj_id <= 0) {
            $_SESSION['error'] = 'Invalid adjustment ID.';
            header('Location: manager_fuel_adjustments.php'); exit;
        }

        try {
            $pdo->beginTransaction();

            // Fetch adjustment
            $stmt = $pdo->prepare("SELECT * FROM fuel_adjustments WHERE id = ? AND station_id = ? FOR UPDATE");
            $stmt->execute([$adj_id, $station_id]);
            $adj = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$adj) {
                throw new Exception("Adjustment record not found.");
            }

            if (strtolower($adj['status']) !== 'pending') {
                throw new Exception("Adjustment has already been processed (Status: " . htmlspecialchars($adj['status']) . ").");
            }

            // Update Status to Approved
            $up = $pdo->prepare("UPDATE fuel_adjustments SET status='Approved', approved_by=?, approved_at=NOW() WHERE id=? AND station_id=?");
            $up->execute([$me['id'], $adj_id, $station_id]);

            // Deduct or add liters to fuel_inventory matching the fuel_type_id
            $up_inv = $pdo->prepare("
                UPDATE fuel_inventory 
                SET current_level = COALESCE(current_level, 0) + ?,
                    current_stock = COALESCE(current_stock, 0) + ?,
                    last_updated = NOW()
                WHERE station_id = ? AND fuel_type_id = ?
            ");
            $up_inv->execute([$adj['liters'], $adj['liters'], $station_id, $adj['fuel_type_id']]);

            // Add activity log
            log_activity($pdo, $me['id'], 'Approve Adjustment', "Approved adjustment ADJ-{$adj_id} (Fuel: {$adj['fuel_type']}, Liters: {$adj['liters']})");

            $_SESSION['success'] = "Adjustment <strong>ADJ-{$adj_id}</strong> was approved. Inventory levels have been updated.";
            $pdo->commit();

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = "Error approving adjustment: " . $e->getMessage();
        }
        header('Location: manager_fuel_adjustments.php'); exit;
    }

    // 2. REJECT ADJUSTMENT
    elseif ($action === 'reject') {
        $adj_id = (int)($_POST['id'] ?? 0);
        $remarks = trim($_POST['remarks'] ?? '');

        if ($adj_id <= 0) {
            $_SESSION['error'] = 'Invalid adjustment ID.';
            header('Location: manager_fuel_adjustments.php'); exit;
        }

        if (empty($remarks)) {
            $_SESSION['error'] = 'Rejection remarks/reason is required.';
            header('Location: manager_fuel_adjustments.php'); exit;
        }

        try {
            $pdo->beginTransaction();

            // Fetch adjustment
            $stmt = $pdo->prepare("SELECT * FROM fuel_adjustments WHERE id = ? AND station_id = ? FOR UPDATE");
            $stmt->execute([$adj_id, $station_id]);
            $adj = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$adj) {
                throw new Exception("Adjustment record not found.");
            }

            if (strtolower($adj['status']) !== 'pending') {
                throw new Exception("Adjustment has already been processed.");
            }

            // Update status to Rejected and store remarks in notes
            $up = $pdo->prepare("UPDATE fuel_adjustments SET status='Rejected', approved_by=?, approved_at=NOW(), notes=? WHERE id=? AND station_id=?");
            $up->execute([$me['id'], $remarks, $adj_id, $station_id]);

            // Add activity log
            log_activity($pdo, $me['id'], 'Reject Adjustment', "Rejected adjustment ADJ-{$adj_id} (Reason: {$remarks})");

            $_SESSION['success'] = "Adjustment <strong>ADJ-{$adj_id}</strong> has been rejected.";
            $pdo->commit();

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = "Error rejecting adjustment: " . $e->getMessage();
        }
        header('Location: manager_fuel_adjustments.php'); exit;
    }

    // 3. CREATE NEW ADJUSTMENT REQUEST
    elseif ($action === 'create_adjustment') {
        $fuel_type_id    = (int)($_POST['fuel_type_id'] ?? 0);
        $adjustment_type = trim($_POST['adjustment_type'] ?? '');
        $new_value       = (float)($_POST['new_value'] ?? 0);
        $reason          = trim($_POST['reason'] ?? '');

        try {
            if ($fuel_type_id <= 0) throw new Exception("Please select a valid fuel type / tank.");
            if (empty($adjustment_type)) throw new Exception("Please select an adjustment type.");
            if (empty($reason)) throw new Exception("Please provide a detailed reason.");
            if ($new_value < 0) throw new Exception("New value level cannot be negative.");

            // Fetch current inventory details
            $stmt = $pdo->prepare("SELECT COALESCE(current_stock, 0) as current_stock, fuel_type FROM fuel_inventory WHERE fuel_type_id = ? AND station_id = ?");
            $stmt->execute([$fuel_type_id, $station_id]);
            $inv = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$inv) {
                throw new Exception("Fuel inventory record not found for selected fuel type.");
            }

            $previous_value = (float)$inv['current_stock'];
            $difference     = $new_value - $previous_value;

            if ($difference === 0.0) {
                throw new Exception("New value is equal to previous value. No adjustment required.");
            }

            // Insert adjustment into database as Pending
            $ins = $pdo->prepare("
                INSERT INTO fuel_adjustments 
                    (station_id, adjustment_date, fuel_type, fuel_type_id, adjustment_type, liters, previous_value, new_value, reason, user_id, status, created_at)
                VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())
            ");
            $ins->execute([
                $station_id, $inv['fuel_type'], $fuel_type_id, $adjustment_type, 
                $difference, $previous_value, $new_value, $reason, $me['id']
            ]);
            $new_id = $pdo->lastInsertId();

            // Log activity
            log_activity($pdo, $me['id'], 'Request Adjustment', "Requested adjustment ADJ-{$new_id} for {$inv['fuel_type']}: {$difference} L.");

            $_SESSION['success'] = "Adjustment request <strong>ADJ-{$new_id}</strong> created successfully and is now pending approval.";

        } catch (Exception $e) {
            $_SESSION['error'] = "Error requesting adjustment: " . $e->getMessage();
        }
        header('Location: manager_fuel_adjustments.php'); exit;
    }
}

// ── GET Filters ──────────────────────────────────────────────
$date_from          = trim($_GET['date_from'] ?? date('Y-m-d', strtotime('-6 months')));
$date_to            = trim($_GET['date_to']   ?? date('Y-m-d'));
$adj_type_filter    = trim($_GET['adjustment_type'] ?? 'all');
$fuel_type_filter   = trim($_GET['fuel_type'] ?? 'all');
$status_filter      = trim($_GET['status_filter'] ?? 'all');
$search_query       = trim($_GET['search_query'] ?? '');
$export             = trim($_GET['export'] ?? '');

// Base SQL conditions
$where = ["fa.station_id = ?"];
$params = [$station_id];

// Date Filter
$where[] = "DATE(fa.adjustment_date) BETWEEN ? AND ?";
$params[] = $date_from;
$params[] = $date_to;

// Adjustment Type Filter
if ($adj_type_filter !== 'all' && $adj_type_filter !== '') {
    $where[] = "fa.adjustment_type = ?";
    $params[] = $adj_type_filter;
}

// Fuel Type Filter
if ($fuel_type_filter !== 'all' && $fuel_type_filter !== '') {
    $where[] = "fa.fuel_type = ?";
    $params[] = $fuel_type_filter;
}

// Search Query
if ($search_query !== '') {
    $where[] = "(fa.reason LIKE ? OR fa.notes LIKE ? OR u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
    $like_val = '%' . $search_query . '%';
    $params = array_merge($params, [$like_val, $like_val, $like_val, $like_val, $like_val]);
}

// Copy filters for summary calculations (before applying status filter)
$sc_where = $where;
$sc_params = $params;

// Apply Status Filter to main list
if ($status_filter !== 'all' && $status_filter !== '') {
    $where[] = "LOWER(fa.status) = ?";
    $params[] = strtolower($status_filter);
}

// ── Fetch Adjustments ─────────────────────────────────────────
$adjustments = [];
try {
    $sql = "SELECT fa.*, 
                   COALESCE(
                       NULLIF(CONCAT(TRIM(COALESCE(u.first_name, '')), ' ', TRIM(COALESCE(u.last_name, ''))), ' '),
                       u.username,
                       'Unknown'
                   ) as requested_by_name,
                   COALESCE(
                       NULLIF(CONCAT(TRIM(COALESCE(app.first_name, '')), ' ', TRIM(COALESCE(app.last_name, ''))), ' '),
                       app.username,
                       '—'
                   ) as validator_name
            FROM fuel_adjustments fa
            LEFT JOIN users u ON fa.user_id = u.id
            LEFT JOIN users app ON fa.approved_by = app.id
            WHERE " . implode(" AND ", $where) . "
            ORDER BY fa.adjustment_date DESC, fa.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $adjustments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Fetch adjustments error: " . $e->getMessage());
    $_SESSION['error'] = "Error loading adjustments: " . $e->getMessage();
}

// ── Compute Summary Card Metrics ─────────────────────────────
$pending_count = 0;
$approved_count = 0;
$rejected_count = 0;
$total_adjustments = 0;

try {
    // 1. Pending (overall station count)
    $sp = $pdo->prepare("SELECT COUNT(*) FROM fuel_adjustments WHERE station_id = ? AND LOWER(status) = 'pending'");
    $sp->execute([$station_id]);
    $pending_count = (int)$sp->fetchColumn();

    // 2. Approved (matching current filters)
    $sa_where = array_merge($sc_where, ["LOWER(fa.status) = 'approved'"]);
    $sa = $pdo->prepare("SELECT COUNT(*) FROM fuel_adjustments fa LEFT JOIN users u ON fa.user_id = u.id WHERE " . implode(" AND ", $sa_where));
    $sa->execute($sc_params);
    $approved_count = (int)$sa->fetchColumn();

    // 3. Rejected (matching current filters)
    $sr_where = array_merge($sc_where, ["LOWER(fa.status) = 'rejected'"]);
    $sr = $pdo->prepare("SELECT COUNT(*) FROM fuel_adjustments fa LEFT JOIN users u ON fa.user_id = u.id WHERE " . implode(" AND ", $sr_where));
    $sr->execute($sc_params);
    $rejected_count = (int)$sr->fetchColumn();

    // 4. Total Adjustments (records count matching filters)
    $total_adjustments = count($adjustments);
} catch (Exception $e) {
    error_log("Summary calculations error: " . $e->getMessage());
}

// ── Fetch dynamic fuel types and adjustment types ───────────
$fuel_types = [];
$adjustment_types = [];
try {
    $ft_stmt = $pdo->prepare("SELECT DISTINCT fuel_type FROM fuel_inventory WHERE station_id=? AND fuel_type IS NOT NULL AND fuel_type!='' ORDER BY fuel_type");
    $ft_stmt->execute([$station_id]);
    $fuel_types = $ft_stmt->fetchAll(PDO::FETCH_COLUMN);

    $at_stmt = $pdo->query("SELECT DISTINCT adjustment_type FROM fuel_adjustments WHERE adjustment_type IS NOT NULL AND adjustment_type!='' ORDER BY adjustment_type");
    $adjustment_types = $at_stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Add default types if DB table has none
    if (empty($adjustment_types)) {
        $adjustment_types = ['Calibration', 'Theft/Loss', 'Spillage', 'Transfer', 'Other', 'Delivery'];
    }
} catch (Exception $e) {}

// ── EXPORTS ──────────────────────────────────────────────────
if (in_array($export, ['excel', 'pdf'])) {
    $headers = ['Adjustment ID', 'Date', 'Adjustment Type', 'Fuel Type', 'Tank/Pump', 'Previous Value', 'New Value', 'Difference', 'Reason', 'Requested By', 'Status', 'Approval Date'];
    $rows_fmt = [];
    foreach ($adjustments as $adj) {
        $rows_fmt[] = [
            'ADJ-' . $adj['id'],
            date('M d, Y', strtotime($adj['adjustment_date'])),
            $adj['adjustment_type'],
            $adj['fuel_type'],
            $adj['fuel_type'], // Tank/Pump description
            number_format($adj['previous_value'], 2),
            number_format($adj['new_value'], 2),
            ($adj['liters'] >= 0 ? '+' : '') . number_format($adj['liters'], 2),
            $adj['reason'] ?? '—',
            $adj['requested_by_name'] ?? '—',
            getStatusLabel($adj['status'] ?? ''),
            $adj['approved_at'] ? date('M d, Y H:i', strtotime($adj['approved_at'])) : '—'
        ];
    }
    $filename = 'fuel_adjustments_oversight_' . $date_from . '_to_' . $date_to;

    if ($export === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="UTF-8"><style>table{border-collapse:collapse}th,td{border:1px solid #ddd;padding:7px}th{background:#002F6C;color:#fff;font-size:11px}</style></head><body>';
        echo '<h2>Fuel Adjustments Oversight Report</h2><p>Period: ' . $date_from . ' to ' . $date_to . ' | Records: ' . count($rows_fmt) . '</p>';
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
        foreach ($adjustments as $adj) {
            $diff = ($adj['liters'] >= 0 ? '+' : '') . number_format($adj['liters'], 2);
            $tbody .= '<tr>';
            $tbody .= '<td>ADJ-' . htmlspecialchars($adj['id']) . '</td>';
            $tbody .= '<td>' . date('M d, Y', strtotime($adj['adjustment_date'])) . '</td>';
            $tbody .= '<td>' . htmlspecialchars($adj['adjustment_type']) . '</td>';
            $tbody .= '<td>' . htmlspecialchars($adj['fuel_type']) . '</td>';
            $tbody .= '<td style="text-align:right;">' . number_format($adj['previous_value'], 2) . '</td>';
            $tbody .= '<td style="text-align:right;">' . number_format($adj['new_value'], 2) . '</td>';
            $tbody .= '<td style="text-align:right; font-weight:bold;">' . $diff . '</td>';
            $tbody .= '<td>' . htmlspecialchars(substr($adj['reason'] ?? '', 0, 40)) . (strlen($adj['reason'] ?? '') > 40 ? '...' : '') . '</td>';
            $tbody .= '<td>' . htmlspecialchars($adj['requested_by_name'] ?? '—') . '</td>';
            $tbody .= '<td>' . getStatusLabel($adj['status'] ?? '') . '</td>';
            $tbody .= '<td>' . ($adj['approved_at'] ? date('M d, Y', strtotime($adj['approved_at'])) : '—') . '</td>';
            $tbody .= '</tr>';
        }

        echo '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Fuel Adjustments Oversight Report</title>
            <style>
                body { font-family: Arial, sans-serif; font-size: 10px; color: #333; line-height: 1.4; padding: 20px; }
                .header { border-bottom: 2px solid #002f6c; padding-bottom: 10px; margin-bottom: 15px; }
                .logo-text { font-size: 18px; font-weight: bold; color: #002f6c; text-transform: uppercase; }
                .rpt-title { font-size: 13px; font-weight: bold; margin-top: 5px; color: #555; }
                .meta-table { width: 100%; margin-bottom: 15px; font-size: 10px; }
                .data-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
                .data-table th, .data-table td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; }
                .data-table th { background-color: #002f6c; color: white; font-weight: bold; text-transform: uppercase; font-size: 9px; }
                .data-table tr:nth-child(even) { background-color: #f9f9f9; }
                .footer { margin-top: 30px; border-top: 1px solid #ddd; padding-top: 8px; font-size: 8px; color: #777; display: flex; justify-content: space-between; }
            </style>
        </head>
        <body onload="window.print()">
            <div class="header">
                <div class="logo-text">Petron Corporation</div>
                <div class="rpt-title">Fuel Adjustments Oversight Summary</div>
            </div>
            
            <table class="meta-table">
                <tr>
                    <td><strong>Station ID:</strong> ' . htmlspecialchars($station_id) . '</td>
                    <td style="text-align: right;"><strong>Date Range:</strong> ' . htmlspecialchars($date_from) . ' to ' . htmlspecialchars($date_to) . '</td>
                </tr>
                <tr>
                    <td><strong>Generated By:</strong> ' . htmlspecialchars($me['username']) . ' (' . htmlspecialchars($role) . ')</td>
                    <td style="text-align: right;"><strong>Run Date:</strong> ' . $generated . '</td>
                </tr>
            </table>

            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Fuel Type</th>
                        <th style="text-align:right;">Prev Value</th>
                        <th style="text-align:right;">New Value</th>
                        <th style="text-align:right;">Diff (L)</th>
                        <th>Reason</th>
                        <th>Requested By</th>
                        <th>Status</th>
                        <th>Approval Date</th>
                    </tr>
                </thead>
                <tbody>
                    ' . $tbody . '
                </tbody>
            </table>

            <div class="footer">
                <span>System Generated Report • Confidential</span>
                <span>Page 1 of 1</span>
            </div>
        </body>
        </html>';
        exit;
    }
}

// ── Fetch station inventory for New Adjustment form ─────────
$station_inventory = [];
try {
    $inv_stmt = $pdo->prepare("SELECT fuel_type_id, fuel_type, current_stock FROM fuel_inventory WHERE station_id = ? ORDER BY fuel_type");
    $inv_stmt->execute([$station_id]);
    $station_inventory = $inv_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

require_once __DIR__ . '/../partials/header.php';
?>

<style>
/* == GLOBAL OVERFLOW CONTROL == */
* { box-sizing: border-box; }
html, body { max-width: 100vw !important; width: 100%; overflow-x: hidden !important; position: relative; }
.main-content { max-width: 100% !important; overflow-x: hidden !important; }

/* == PAGE HEADER - Petron standard == */
.int-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 20px;
    margin-top: -12px !important;
}
.int-head h1 {
    font-size: 22px !important;
    font-weight: 700 !important;
    color: var(--petron-blue, #00264D) !important;
    margin: 0 !important;
    text-transform: uppercase !important;
    display: flex;
    align-items: center;
    gap: 8px;
}
.int-head .sub {
    font-size: 13px;
    color: #666;
    margin-top: 4px;
    text-transform: none !important;
}

/* == SUMMARY CARDS == */
.afto-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.afto-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    position: relative;
    overflow: hidden;
}
.afto-card-info {
    display: flex;
    flex-direction: column;
}
.afto-card-lbl {
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
}
.afto-card-val {
    font-size: 20px;
    font-weight: 700;
    color: #1e293b;
}
.afto-card-icon {
    font-size: 24px;
    opacity: 0.8;
}

/* Card icon colors (No colored borders) */
.afto-card.blue .afto-card-icon { color: #2563eb; }
.afto-card.green .afto-card-icon { color: #16a34a; }
.afto-card.red .afto-card-icon { color: #dc2626; }
.afto-card.yellow .afto-card-icon { color: #d97706; }

/* == FILTER BAR == */
.afto-filter {
    display: flex;
    align-items: flex-end;
    gap: 12px;
    flex-wrap: wrap;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 16px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
}
.afto-fg {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.afto-fg label {
    font-size: 11px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}
.afto-fg input, .afto-fg select {
    height: 36px;
    padding: 0 12px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 13px;
    color: #1e293b;
    background: #ffffff;
    outline: none;
    box-sizing: border-box;
}
.afto-fg input:focus, .afto-fg select:focus {
    border-color: var(--petron-blue, #00264D);
    box-shadow: 0 0 0 3px rgba(0, 38, 77, 0.1);
}
.afto-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

/* Buttons styling - White background with Petron Blue outline */
.ato-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 0 16px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid transparent;
    text-decoration: none;
    transition: all 0.15s ease-in-out;
    height: 36px;
    white-space: nowrap;
    background: #ffffff !important;
}
.ato-btn-filter { color: #002F70 !important; border-color: #002F70 !important; }
.ato-btn-filter:hover { background: #002F70 !important; color: #ffffff !important; }
.ato-btn-export { color: #16a34a !important; border-color: #16a34a !important; }
.ato-btn-export:hover { background: #16a34a !important; color: #ffffff !important; }
.ato-btn-pdf { color: #dc2626 !important; border-color: #dc2626 !important; }
.ato-btn-pdf:hover { background: #dc2626 !important; color: #ffffff !important; }
.ato-btn-reset { color: #4b5563 !important; border-color: #9ca3af !important; }
.ato-btn-reset:hover { background: #6b7280 !important; color: #ffffff !important; }
.ato-btn-add { color: #0f172a !important; border-color: #334155 !important; }
.ato-btn-add:hover { background: #334155 !important; color: #ffffff !important; }

/* == TABLE CARD == */
.tbl-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
    margin-bottom: 24px;
}
.tbl-hd {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 16px;
    border-bottom: 1px solid #e9ecef;
    flex-wrap: wrap;
    gap: 8px;
    background: #f8fafc;
}
.tbl-title {
    font-size: 14px;
    font-weight: 700;
    color: #00264D;
    display: flex;
    align-items: center;
    gap: 8px;
}
.afto-tbl {
    width: 100%;
    max-width: 100%;
    border-collapse: collapse;
    font-size: 10px;
    text-align: left;
    table-layout: fixed;
}
.afto-tbl thead tr {
    background: #002F70;
}
.afto-tbl thead th {
    padding: 8px 6px;
    font-weight: 700;
    color: #ffffff;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    font-size: 10px;
    border-bottom: 2px solid #001a3d;
}
.afto-tbl tbody tr {
    border-bottom: 1px solid #f1f5f9;
    transition: background 0.1s ease;
}
.afto-tbl tbody tr:hover td {
    background: #f8fafc;
}
.afto-tbl tbody td {
    padding: 8px 6px;
    color: #334155;
    vertical-align: middle;
    font-size: 9px;
    word-wrap: break-word;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Numeric alignment */
.align-right { text-align: right; font-family: monospace; }
.align-left { text-align: left; }
.bold-vol { font-weight: 700; color: #002F6C; }

/* Variance formatting */
.var-pos { color: #16a34a; font-weight: 700; font-family: monospace; }
.var-neg { color: #dc2626; font-weight: 700; font-family: monospace; }
.var-zero { color: #64748b; font-weight: 600; font-family: monospace; }

/* Status Badges */
.badge-lbl {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    text-align: center;
    white-space: nowrap;
}
.bg-amber { background-color: #fef3c7; color: #b45309; }
.bg-green { background-color: #dcfce7; color: #15803d; }
.bg-red   { background-color: #fee2e2; color: #b91c1c; }
.bg-gray  { background-color: #f1f5f9; color: #475569; }

/* Row Actions */
.row-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    height: 26px;
    padding: 0 10px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid transparent;
    text-decoration: none;
    transition: all 0.1s ease;
    background: #ffffff !important;
}
.row-btn-details { color: #1e40af !important; border-color: #bfdbfe !important; }
.row-btn-details:hover { background: #eff6ff !important; border-color: #1e40af !important; }
.row-btn-approve { color: #15803d !important; border-color: #bbf7d0 !important; }
.row-btn-approve:hover { background: #f0fdf4 !important; border-color: #15803d !important; }
.row-btn-reject  { color: #b91c1c !important; border-color: #fecaca !important; }
.row-btn-reject:hover { background: #fef2f2 !important; border-color: #b91c1c !important; }
.row-btn-print   { color: #4b5563 !important; border-color: #e5e7eb !important; }
.row-btn-print:hover { background: #f9fafb !important; border-color: #4b5563 !important; }

/* Empty state */
.empty-state {
    padding: 40px 16px;
    text-align: center;
    color: #94a3b8;
}
.empty-state i {
    font-size: 32px;
    margin-bottom: 8px;
    display: block;
    opacity: 0.5;
}

/* Modals */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.4);
    z-index: 999;
    align-items: center;
    justify-content: center;
}
.modal-overlay.active {
    display: flex;
}
.modal-box {
    background: #ffffff;
    border-radius: 8px;
    width: 100%;
    max-width: 500px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    overflow: hidden;
    animation: modalShow 0.15s ease-out;
}
@keyframes modalShow {
    from { transform: scale(0.95); opacity: 0; }
    to { transform: scale(1); opacity: 1; }
}
.modal-header {
    background: #002F70;
    color: #ffffff;
    padding: 14px 16px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.modal-header h3 {
    margin: 0;
    font-size: 14px;
    text-transform: uppercase;
}
.modal-header .close {
    cursor: pointer;
    font-size: 18px;
    font-weight: bold;
}
.modal-body {
    padding: 16px;
}
.modal-footer {
    padding: 12px 16px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    background: #f8fafc;
}
.modal-form-row {
    margin-bottom: 12px;
}
.modal-form-row label {
    display: block;
    font-size: 11px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    margin-bottom: 4px;
}
.modal-form-row input, .modal-form-row select, .modal-form-row textarea {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid #cbd5e1;
    border-radius: 4px;
    font-size: 13px;
    box-sizing: border-box;
}
.modal-form-row textarea {
    height: 60px;
    resize: none;
}
.modal-btn {
    padding: 8px 16px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid transparent;
}
.modal-btn-primary { background: #002F70; color: #ffffff; }
.modal-btn-primary:hover { background: #001f4d; }
.modal-btn-secondary { background: #e2e8f0; color: #475569; border-color: #cbd5e1; }
.modal-btn-secondary:hover { background: #cbd5e1; }

.detail-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #f1f5f9;
}
.detail-row:last-child {
    border-bottom: none;
}
.detail-lbl {
    font-weight: 700;
    color: #64748b;
    font-size: 11px;
    text-transform: uppercase;
}
.detail-val {
    color: #1e293b;
    font-size: 12px;
}
</style>

<!-- == TOP INT-HEAD HEADER == -->
<div class="int-head">
    <div>
        <h1><i class="fas fa-sliders-h"></i> Adjustments Oversight</h1>
        <div class="sub">View, audit, approve or reject fuel stock level and meter reading adjustments</div>
    </div>
    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
        <a href="?export=excel&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>" class="ato-btn ato-btn-export">
            <i class="fas fa-file-excel"></i> Export Excel
        </a>
        <button class="ato-btn ato-btn-pdf" onclick="exportPDF()">
            <i class="fas fa-file-pdf"></i> Export PDF
        </button>
    </div>
</div>

<!-- == ALERTS == -->
<?php if (!empty($_SESSION['success'])): ?>
    <div style="padding: 12px 16px; background: #dcfce7; border: 1px solid #bbf7d0; color: #15803d; border-radius: 8px; margin-bottom: 16px; font-weight: 600;">
        <i class="fas fa-check-circle"></i> <?= $_SESSION['success']; unset($_SESSION['success']); ?>
    </div>
<?php endif; ?>
<?php if (!empty($_SESSION['error'])): ?>
    <div style="padding: 12px 16px; background: #fee2e2; border: 1px solid #fecaca; color: #b91c1c; border-radius: 8px; margin-bottom: 16px; font-weight: 600;">
        <i class="fas fa-exclamation-circle"></i> <?= $_SESSION['error']; unset($_SESSION['error']); ?>
    </div>
<?php endif; ?>

<!-- == SUMMARY CARDS == -->
<div class="afto-cards">
    <!-- Pending Card -->
    <div class="afto-card blue">
        <div class="afto-card-info">
            <span class="afto-card-lbl">Pending Adjustments</span>
            <span class="afto-card-val"><?= number_format($pending_count) ?></span>
        </div>
        <div class="afto-card-icon"><i class="fas fa-clock"></i></div>
    </div>
    
    <!-- Approved Card -->
    <div class="afto-card green">
        <div class="afto-card-info">
            <span class="afto-card-lbl">Approved Adjustments</span>
            <span class="afto-card-val"><?= number_format($approved_count) ?></span>
        </div>
        <div class="afto-card-icon"><i class="fas fa-check-circle"></i></div>
    </div>
    
    <!-- Rejected Card -->
    <div class="afto-card red">
        <div class="afto-card-info">
            <span class="afto-card-lbl">Rejected Adjustments</span>
            <span class="afto-card-val"><?= number_format($rejected_count) ?></span>
        </div>
        <div class="afto-card-icon"><i class="fas fa-times-circle"></i></div>
    </div>
    
    <!-- Total Card -->
    <div class="afto-card yellow">
        <div class="afto-card-info">
            <span class="afto-card-lbl">Total Adjustments</span>
            <span class="afto-card-val"><?= number_format($total_adjustments) ?></span>
        </div>
        <div class="afto-card-icon"><i class="fas fa-list"></i></div>
    </div>
</div>

<!-- == FILTERS == -->
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
        <label>Adjustment Type</label>
        <select name="adjustment_type">
            <option value="all">All Types</option>
            <?php foreach ($adjustment_types as $type): ?>
                <option value="<?= htmlspecialchars($type) ?>" <?= $adj_type_filter === $type ? 'selected' : '' ?>>
                    <?= htmlspecialchars($type) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <div class="afto-fg">
        <label>Fuel Type</label>
        <select name="fuel_type">
            <option value="all">All Fuels</option>
            <?php foreach ($fuel_types as $ft): ?>
                <option value="<?= htmlspecialchars($ft) ?>" <?= $fuel_type_filter === $ft ? 'selected' : '' ?>>
                    <?= htmlspecialchars($ft) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <div class="afto-fg">
        <label>Status</label>
        <select name="status_filter">
            <option value="all">All Status</option>
            <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
            <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Approved</option>
            <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
        </select>
    </div>
    
    <div class="afto-fg">
        <label>Search</label>
        <input type="text" name="search_query" value="<?= htmlspecialchars($search_query) ?>" placeholder="Search reason, requested by..." style="width: 180px;">
    </div>
    
    <div class="afto-fg" style="flex-direction: row; gap: 6px;">
        <button type="submit" class="ato-btn ato-btn-filter"><i class="fas fa-filter"></i> Filter</button>
        <a href="manager_fuel_adjustments.php" class="ato-btn ato-btn-reset"><i class="fas fa-sync-alt"></i> Reset</a>
    </div>
</form>

<!-- == DETAILS TABLE == -->
<div class="tbl-card">
    <div class="tbl-hd">
        <span class="tbl-title"><i class="fas fa-sliders-h"></i> Fuel Adjustments Registry</span>
        <span style="font-size: 11px; color: #64748b; font-weight: 600;">Showing <?= count($adjustments) ?> record(s)</span>
    </div>
    
    <div style="overflow-x: hidden !important; max-width: 100%;">
        <table class="afto-tbl">
            <thead>
                <tr>
                    <th style="width: 7%;">Adj ID</th>
                    <th style="width: 8%;">Date</th>
                    <th style="width: 9%;">Type</th>
                    <th style="width: 7%;">Fuel</th>
                    <th style="width: 8%;">Tank/Pump</th>
                    <th class="align-right" style="width: 8%;">Prev Value</th>
                    <th class="align-right" style="width: 8%;">New Value</th>
                    <th class="align-right" style="width: 8%;">Diff (L)</th>
                    <th style="width: 12%;">Reason</th>
                    <th style="width: 8%;">By</th>
                    <th style="width: 7%;">Status</th>
                    <th style="width: 10%;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($adjustments)): ?>
                    <tr>
                        <td colspan="12">
                            <div class="empty-state">
                                <i class="fas fa-inbox"></i>
                                No adjustment records found matching the filter criteria.
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($adjustments as $adj): 
                        $diff = (float)$adj['liters'];
                        $diff_class = $diff == 0 ? 'var-zero' : ($diff > 0 ? 'var-pos' : 'var-neg');
                        $diff_str = ($diff >= 0 ? '+' : '') . number_format($diff, 2) . ' L';
                        
                        // Deriving Tank description
                        $tank_pump = $adj['fuel_type'];
                        if (strpos(strtolower($adj['reason']), 'pump') !== false) {
                            $tank_pump .= ' (Pump)';
                        } else {
                            $tank_pump .= ' (Tank)';
                        }
                    ?>
                        <tr>
                            <td style="font-size: 9px;"><strong style="color:#1e40af;">ADJ-<?= htmlspecialchars($adj['id']) ?></strong></td>
                            <td style="font-size: 9px;"><?= date('M d, Y', strtotime($adj['adjustment_date'])) ?></td>
                            <td style="font-size: 9px;"><span style="font-weight:600; color:#475569;"><?= htmlspecialchars(substr($adj['adjustment_type'], 0, 12)) ?></span></td>
                            <td class="bold-vol" style="font-size: 9px;"><?= htmlspecialchars(substr($adj['fuel_type'], 0, 8)) ?></td>
                            <td style="font-size: 9px;"><span style="color:#64748b;"><?= htmlspecialchars(substr($tank_pump, 0, 10)) ?></span></td>
                            <td class="align-right" style="font-size: 9px;"><?= number_format($adj['previous_value'], 2) ?></td>
                            <td class="align-right" style="font-size: 9px;"><?= number_format($adj['new_value'], 2) ?></td>
                            <td class="align-right <?= $diff_class ?>" style="font-size: 9px;"><?= $diff_str ?></td>
                            <td title="<?= htmlspecialchars($adj['reason']) ?>" style="font-size: 9px;">
                                <?= htmlspecialchars(substr($adj['reason'], 0, 20)) ?><?= strlen($adj['reason']) > 20 ? '...' : '' ?>
                            </td>
                            <td style="font-size: 9px;"><?= htmlspecialchars(substr($adj['requested_by_name'], 0, 10)) ?></td>
                            <td>
                                <span class="badge-lbl <?= getStatusBadgeClass($adj['status']) ?>">
                                    <?= getStatusLabel($adj['status']) ?>
                                </span>
                            </td>
                            <td style="text-align: center; white-space: nowrap;">
                                <div style="display: flex; flex-direction: column; gap: 2px;">
                                    <button class="row-btn row-btn-details" onclick='viewDetails(<?= json_encode($adj) ?>)' title="View Details" style="width: 100%; font-size: 9px;">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    
                                    <?php if (strtolower($adj['status']) === 'pending'): ?>
                                        <form method="post" style="display: block; width: 100%;" onsubmit="return confirm('Are you sure you want to approve this adjustment request? This will apply the difference to inventory stock.');">
                                            <input type="hidden" name="action" value="approve">
                                            <input type="hidden" name="id" value="<?= $adj['id'] ?>">
                                            <button type="submit" class="row-btn row-btn-approve" title="Approve Adjustment" style="width: 100%; font-size: 9px;">
                                                <i class="fas fa-check"></i> Approve
                                            </button>
                                        </form>
                                        
                                        <button class="row-btn row-btn-reject" onclick="rejectAdjustment(<?= $adj['id'] ?>)" title="Reject Adjustment" style="width: 100%; font-size: 9px;">
                                            <i class="fas fa-times"></i> Reject
                                        </button>
                                    <?php endif; ?>
                                    
                                    <button class="row-btn row-btn-print" onclick="printAdjustment(<?= $adj['id'] ?>)" title="Print Slip" style="width: 100%; font-size: 9px;">
                                        <i class="fas fa-print"></i> Print
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- == MODAL: VIEW DETAILS == -->
<div id="viewModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Adjustment Details</h3>
            <span class="close" onclick="closeModal('viewModal')">&times;</span>
        </div>
        <div class="modal-body" id="modalDetailsContent">
            <!-- Rendered dynamically -->
        </div>
        <div class="modal-footer">
            <button class="modal-btn modal-btn-secondary" onclick="closeModal('viewModal')">Close</button>
        </div>
    </div>
</div>

<!-- == MODAL: REJECT ADJUSTMENT == -->
<div id="rejectModal" class="modal-overlay">
    <div class="modal-box">
        <form method="post" action="manager_fuel_adjustments.php">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="id" id="reject_adj_id">
            
            <div class="modal-header">
                <h3>Reject Adjustment Request</h3>
                <span class="close" onclick="closeModal('rejectModal')">&times;</span>
            </div>
            <div class="modal-body">
                <div class="modal-form-row">
                    <label>Rejection Reason / Remarks *</label>
                    <textarea name="remarks" required placeholder="Explain why this adjustment is being rejected..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="modal-btn modal-btn-secondary" onclick="closeModal('rejectModal')">Cancel</button>
                <button type="submit" class="modal-btn modal-btn-primary" style="background: #dc2626;">Confirm Reject</button>
            </div>
        </form>
    </div>
</div>

<!-- == MODAL: NEW ADJUSTMENT REQUEST == -->
<div id="newAdjustmentModal" class="modal-overlay">
    <div class="modal-box">
        <form method="post" action="manager_fuel_adjustments.php">
            <input type="hidden" name="action" value="create_adjustment">
            
            <div class="modal-header">
                <h3>Request Fuel Stock Adjustment</h3>
                <span class="close" onclick="closeModal('newAdjustmentModal')">&times;</span>
            </div>
            <div class="modal-body">
                <div class="modal-form-row">
                    <label>Select Fuel Type / Tank *</label>
                    <select name="fuel_type_id" id="adj_fuel_select" onchange="updatePreviousValue()" required>
                        <option value="">-- Select --</option>
                        <?php foreach ($station_inventory as $inv): ?>
                            <option value="<?= $inv['fuel_type_id'] ?>" data-stock="<?= htmlspecialchars($inv['current_stock']) ?>">
                                <?= htmlspecialchars($inv['fuel_type']) ?> (Current: <?= number_format($inv['current_stock'], 2) ?> L)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="modal-form-row">
                    <label>Adjustment Type *</label>
                    <select name="adjustment_type" required>
                        <option value="Calibration">Calibration</option>
                        <option value="Theft/Loss">Theft / Loss</option>
                        <option value="Spillage">Spillage</option>
                        <option value="Transfer">Transfer</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                
                <div class="modal-form-row">
                    <label>Previous Value (Liters)</label>
                    <input type="text" id="adj_prev_value" readonly style="background: #f1f5f9; color: #475569;">
                </div>
                
                <div class="modal-form-row">
                    <label>New Value (Liters) *</label>
                    <input type="number" step="0.01" name="new_value" id="adj_new_value" oninput="calculateDiff()" required>
                </div>
                
                <div class="modal-form-row">
                    <label>Difference / Change (Liters)</label>
                    <input type="text" id="adj_diff_display" readonly style="background: #f1f5f9; font-weight: bold;">
                </div>
                
                <div class="modal-form-row">
                    <label>Detailed Explanation / Reason *</label>
                    <textarea name="reason" required placeholder="Explain why this adjustment is necessary (e.g. weekly dipstick validation discrepancy, calibration adjustment...)"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="modal-btn modal-btn-secondary" onclick="closeModal('newAdjustmentModal')">Cancel</button>
                <button type="submit" class="modal-btn modal-btn-primary">Submit Request</button>
            </div>
        </form>
    </div>
</div>

<!-- == HIDDEN PRINT IFRAME == -->
<iframe id="print_frame" style="display:none;"></iframe>

<script>
function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

function viewDetails(adj) {
    let diff = parseFloat(adj.liters);
    let diffStr = (diff >= 0 ? '+' : '') + diff.toLocaleString(undefined, {minimumFractionDigits: 2}) + ' L';
    
    let html = `
        <div class="detail-row"><span class="detail-lbl">Adjustment ID</span><span class="detail-val">ADJ-\${adj.id}</span></div>
        <div class="detail-row"><span class="detail-lbl">Date</span><span class="detail-val">\${adj.adjustment_date}</span></div>
        <div class="detail-row"><span class="detail-lbl">Type</span><span class="detail-val">\${adj.adjustment_type}</span></div>
        <div class="detail-row"><span class="detail-lbl">Fuel Type</span><span class="detail-val">\${adj.fuel_type}</span></div>
        <div class="detail-row"><span class="detail-lbl">Previous Value</span><span class="detail-val">\${parseFloat(adj.previous_value).toLocaleString(undefined, {minimumFractionDigits: 2})} L</span></div>
        <div class="detail-row"><span class="detail-lbl">New Value</span><span class="detail-val">\${parseFloat(adj.new_value).toLocaleString(undefined, {minimumFractionDigits: 2})} L</span></div>
        <div class="detail-row"><span class="detail-lbl">Difference</span><span class="detail-val" style="font-weight:bold; color:\${diff >= 0 ? '#16a34a' : '#dc2626'}">\${diffStr}</span></div>
        <div class="detail-row"><span class="detail-lbl">Requested By</span><span class="detail-val">\${adj.requested_by_name}</span></div>
        <div class="detail-row"><span class="detail-lbl">Status</span><span class="detail-val" style="font-weight:bold;">\${adj.status}</span></div>
        \${adj.approved_at ? `<div class="detail-row"><span class="detail-lbl">Approval Date</span><span class="detail-val">\${adj.approved_at}</span></div>` : ''}
        \${adj.validator_name ? `<div class="detail-row"><span class="detail-lbl">Processed By</span><span class="detail-val">\${adj.validator_name}</span></div>` : ''}
        
        <div style="margin-top: 12px; border-top: 1px solid #e2e8f0; padding-top: 12px;">
            <div class="detail-lbl" style="margin-bottom:4px;">Reason / Explanation</div>
            <div style="font-size:12px; color:#475569; background:#f8fafc; border:1px solid #e2e8f0; padding:8px; border-radius:4px; font-style:italic;">
                \${adj.reason || 'None provided.'}
            </div>
        </div>
        \${adj.notes ? `
        <div style="margin-top: 10px;">
            <div class="detail-lbl" style="margin-bottom:4px;">Manager Remarks / Notes</div>
            <div style="font-size:12px; color:#b91c1c; background:#fef2f2; border:1px solid #fecaca; padding:8px; border-radius:4px;">
                \${adj.notes}
            </div>
        </div>` : ''}
    `;
    
    document.getElementById('modalDetailsContent').innerHTML = html;
    document.getElementById('viewModal').classList.add('active');
}

function rejectAdjustment(id) {
    document.getElementById('reject_adj_id').value = id;
    document.getElementById('rejectModal').classList.add('active');
}

function openNewAdjustmentModal() {
    document.getElementById('adj_fuel_select').value = '';
    document.getElementById('adj_prev_value').value = '';
    document.getElementById('adj_new_value').value = '';
    document.getElementById('adj_diff_display').value = '';
    document.getElementById('newAdjustmentModal').classList.add('active');
}

function updatePreviousValue() {
    let select = document.getElementById('adj_fuel_select');
    let option = select.options[select.selectedIndex];
    if (option && option.value) {
        let stock = parseFloat(option.getAttribute('data-stock'));
        document.getElementById('adj_prev_value').value = stock.toFixed(2);
    } else {
        document.getElementById('adj_prev_value').value = '';
    }
    calculateDiff();
}

function calculateDiff() {
    let prev = parseFloat(document.getElementById('adj_prev_value').value);
    let newVal = parseFloat(document.getElementById('adj_new_value').value);
    let diffInput = document.getElementById('adj_diff_display');
    
    if (!isNaN(prev) && !isNaN(newVal)) {
        let diff = newVal - prev;
        diffInput.value = (diff >= 0 ? '+' : '') + diff.toFixed(2) + ' L';
        if (diff > 0) {
            diffInput.style.color = '#16a34a';
        } else if (diff < 0) {
            diffInput.style.color = '#dc2626';
        } else {
            diffInput.style.color = '#64748b';
        }
    } else {
        diffInput.value = '';
    }
}

function printAdjustment(id) {
    let frame = document.getElementById('print_frame');
    frame.src = 'manager_fuel_adjustments.php?print_id=' + id;
}
</script>

<?php
require_once __DIR__ . '/../partials/footer.php';
?>
