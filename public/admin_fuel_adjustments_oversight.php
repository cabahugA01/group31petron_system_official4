<?php
// ============================================================
// Admin Fuel Adjustments Oversight
// Fetch Source: fuel_adjustments (manager-requested → admin-reviewed/overridden)
// ============================================================
$page_id = 'admin_fuel_adjustments_oversight';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();

if (!in_array($role, ['admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: admin_dashboard.php'); exit;
}

// ── Handle AJAX Actions ──────────────────────────────────────
if (isset($_GET['ajax_action'])) {
    $action = $_GET['ajax_action'];
    $adj_id = (int)($_GET['id'] ?? 0);

    if ($action === 'get_details') {
        try {
            $stmt = $pdo->prepare("SELECT fa.*, 
                COALESCE(NULLIF(CONCAT(TRIM(COALESCE(req.first_name,'')), ' ', TRIM(COALESCE(req.last_name,''))), ' '), req.username, 'Unknown') AS requested_by_name,
                COALESCE(NULLIF(CONCAT(TRIM(COALESCE(app.first_name,'')), ' ', TRIM(COALESCE(app.last_name,''))), ' '), app.username, '—') AS approved_by_name,
                s.name AS station_name
                FROM fuel_adjustments fa
                LEFT JOIN users req ON fa.user_id = req.id
                LEFT JOIN users app ON fa.approved_by = app.id
                LEFT JOIN stations s ON fa.station_id = s.id
                WHERE fa.id = ?");
            $stmt->execute([$adj_id]);
            $adj = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($adj) {
                json_response(['success' => true, 'data' => $adj]);
            } else {
                json_response(['success' => false, 'message' => 'Adjustment record not found.']);
            }
        } catch (Exception $e) {
            json_response(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    if ($action === 'get_audit') {
        try {
            $stmt = $pdo->prepare("SELECT al.*, 
                COALESCE(NULLIF(CONCAT(TRIM(COALESCE(u.first_name,'')), ' ', TRIM(COALESCE(u.last_name,''))), ' '), u.username, 'System') AS username
                FROM audit_logs al
                LEFT JOIN users u ON al.user_id = u.id
                WHERE al.entity_type = 'fuel_adjustments' AND al.entity_id = ?
                ORDER BY al.created_at DESC");
            $stmt->execute([$adj_id]);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            json_response(['success' => true, 'data' => $logs]);
        } catch (Exception $e) {
            json_response(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}

// ── Handle Post Actions (Approve/Reject Overrides) ──────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $adj_id = (int)($_POST['id'] ?? 0);

    if ($adj_id > 0) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM fuel_adjustments WHERE id = ? FOR UPDATE");
            $stmt->execute([$adj_id]);
            $adj = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$adj) {
                throw new Exception("Adjustment record not found.");
            }

            if ($action === 'approve_override') {
                if (strtolower($adj['status']) !== 'pending') {
                    throw new Exception("Adjustment has already been resolved.");
                }

                // Update Status to Approved
                $up = $pdo->prepare("UPDATE fuel_adjustments SET status='Approved', approved_by=?, approved_at=NOW() WHERE id=?");
                $up->execute([$me['id'], $adj_id]);

                // Update inventory level: add the liters value (which can be negative for loss/calibration reductions)
                $up_inv = $pdo->prepare("
                    UPDATE fuel_inventory 
                    SET current_level = COALESCE(current_level, 0) + ?,
                        current_stock = COALESCE(current_stock, 0) + ?,
                        last_updated = NOW()
                    WHERE station_id = ? AND fuel_type = ?
                ");
                $up_inv->execute([$adj['liters'], $adj['liters'], $adj['station_id'], $adj['fuel_type']]);

                write_audit_log(
                    $pdo,
                    'Approved Override',
                    "Approved override for adjustment ID: {$adj['id']} (Liters: {$adj['liters']}, Type: {$adj['adjustment_type']})",
                    'fuel_adjustments',
                    $adj_id,
                    'system',
                    'Success'
                );

                $_SESSION['success'] = "Adjustment ID {$adj['id']} approved successfully. Inventory levels updated.";
            } 
            
            elseif ($action === 'reject_override') {
                if (strtolower($adj['status']) !== 'pending') {
                    throw new Exception("Adjustment has already been resolved.");
                }

                $reason = trim($_POST['rejection_reason'] ?? '');
                if (empty($reason)) {
                    throw new Exception("Rejection remarks are required.");
                }

                // Update Status to Rejected
                $notes = trim(($adj['notes'] ?? '') . "\n[Admin Rejection] " . $reason);
                $up = $pdo->prepare("UPDATE fuel_adjustments SET status='Rejected', approved_by=?, approved_at=NOW(), notes=? WHERE id=?");
                $up->execute([$me['id'], $notes, $adj_id]);

                write_audit_log(
                    $pdo,
                    'Rejected Override',
                    "Rejected override for adjustment ID: {$adj['id']}. Reason: {$reason}",
                    'fuel_adjustments',
                    $adj_id,
                    'system',
                    'Success'
                );

                $_SESSION['success'] = "Adjustment ID {$adj['id']} rejected successfully.";
            }

            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['error'] = "Error: " . $e->getMessage();
        }
    }
    header("Location: admin_fuel_adjustments_oversight.php?" . http_build_query($_GET));
    exit;
}

// ── Station Filter ──────────────────────────────────────────
$filter_station = isset($_GET['station']) ? (int)$_GET['station'] : $station_id;
if ($role === 'superadmin' && !isset($_GET['station'])) {
    $filter_station = 0; // Default to all stations for superadmin
}

// ── Filters ──────────────────────────────────────────────────
$date_from        = trim($_GET['date_from'] ?? date('Y-m-d', strtotime('-90 days')));
$date_to          = trim($_GET['date_to']   ?? date('Y-m-d'));
$adj_type_filter  = trim($_GET['adjustment_type'] ?? '');
$fuel_type_filter = trim($_GET['fuel_type'] ?? '');
$status_filter    = trim($_GET['status_filter'] ?? '');
$export           = trim($_GET['export'] ?? '');

// ── Single Adjustment Print Mode ────────────────────────────
if (isset($_GET['single_id']) && $export === 'pdf') {
    $single_id = (int)$_GET['single_id'];
    try {
        $stmt = $pdo->prepare("SELECT fa.*, 
            COALESCE(NULLIF(CONCAT(TRIM(COALESCE(req.first_name,'')), ' ', TRIM(COALESCE(req.last_name,''))), ' '), req.username, 'Unknown') AS requested_by_name,
            COALESCE(NULLIF(CONCAT(TRIM(COALESCE(app.first_name,'')), ' ', TRIM(COALESCE(app.last_name,''))), ' '), app.username, '—') AS approved_by_name,
            s.name AS station_name
            FROM fuel_adjustments fa
            LEFT JOIN users req ON fa.user_id = req.id
            LEFT JOIN users app ON fa.approved_by = app.id
            LEFT JOIN stations s ON fa.station_id = s.id
            WHERE fa.id = ?");
        $stmt->execute([$single_id]);
        $adj = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($adj) {
            $status_color = (strtolower($adj['status']) === 'approved') ? '#16a34a' : ((strtolower($adj['status']) === 'rejected') ? '#dc2626' : '#d97706');
            ?>
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Adjustment Slip - #<?= $adj['id'] ?></title>
                <style>
                    body { font-family: "Courier New", Courier, monospace; font-size: 12px; line-height: 1.4; padding: 20px; color: #000; background: #fff; }
                    .ticket { max-width: 320px; margin: 0 auto; padding: 10px; border: 1px dashed #000; }
                    .header { text-align: center; border-bottom: 1px dashed #000; padding-bottom: 8px; margin-bottom: 8px; }
                    .header h2 { margin: 0; font-size: 16px; font-weight: bold; }
                    .header p { margin: 2px 0 0; font-size: 11px; }
                    .row { display: flex; justify-content: space-between; margin: 3px 0; }
                    .row label { font-weight: bold; }
                    .row span { text-align: right; }
                    .total { border-top: 1px dashed #000; border-bottom: 1px dashed #000; padding: 4px 0; margin: 6px 0; font-weight: bold; font-size: 13px; }
                    .footer { text-align: center; margin-top: 12px; font-size: 10px; border-top: 1px dashed #000; padding-top: 6px; }
                    .footer p { margin: 2px 0; }
                    @media print { .no-print { display: none; } }
                </style>
            </head>
            <body>
                <div class="no-print" style="margin-bottom: 15px; text-align: center;">
                    <button onclick="window.print()" style="padding: 6px 12px; font-size: 12px; cursor: pointer;">Print Ticket</button>
                    <button onclick="window.close()" style="padding: 6px 12px; font-size: 12px; cursor: pointer; margin-left: 5px;">Close</button>
                </div>
                <div class="ticket">
                    <div class="header">
                        <h2>PETRON</h2>
                        <p><?= htmlspecialchars($adj['station_name'] ?? 'Petron Station') ?></p>
                        <p>Fuel Adjustment Slip</p>
                    </div>
                    <div class="row"><label>Adjustment ID:</label><span>#<?= $adj['id'] ?></span></div>
                    <div class="row"><label>Date:</label><span><?= date('M d, Y', strtotime($adj['adjustment_date'])) ?></span></div>
                    <div class="row"><label>Type:</label><span><?= htmlspecialchars($adj['adjustment_type']) ?></span></div>
                    <div class="row"><label>Fuel Type:</label><span><?= htmlspecialchars($adj['fuel_type']) ?></span></div>
                    <div class="row"><label>Previous Value:</label><span><?= number_format($adj['previous_value'], 2) ?> L</span></div>
                    <div class="row"><label>New Value:</label><span><?= number_format($adj['new_value'], 2) ?> L</span></div>
                    <div class="total"><label>Difference:</label><span><?= ($adj['liters'] >= 0 ? '+' : '') . number_format($adj['liters'], 2) ?> L</span></div>
                    <div class="row"><label>Requested By:</label><span><?= htmlspecialchars($adj['requested_by_name']) ?></span></div>
                    <div class="row"><label>Approved By:</label><span><?= htmlspecialchars($adj['approved_by_name']) ?></span></div>
                    <div class="row"><label>Status:</label><span style="color:<?= $status_color ?>; font-weight:bold;"><?= ucfirst(htmlspecialchars($adj['status'])) ?></span></div>
                    <div class="row"><label>Resolved At:</label><span><?= ($adj['approved_at'] ? date('M d, Y h:i A', strtotime($adj['approved_at'])) : '—') ?></span></div>
                    <div class="row" style="flex-direction:column; align-items:flex-start;"><label>Remarks/Reason:</label><span style="text-align:left; font-weight: normal; margin-top: 3px; color:#555;"><?= htmlspecialchars($adj['reason'] ?? '—') ?></span></div>
                    <?php if (!empty($adj['notes'])): ?>
                        <div class="row" style="flex-direction:column; align-items:flex-start; margin-top: 5px;"><label>Resolution Notes:</label><span style="text-align:left; font-weight: normal; font-style: italic; color:#b91c1c;"><?= htmlspecialchars($adj['notes']) ?></span></div>
                    <?php endif; ?>
                    <div class="footer">
                        <p>Petron Fuel Operations</p>
                        <p>Generated: <?= date('Y-m-d H:i:s') ?></p>
                    </div>
                </div>
            </body>
            </html>
            <?php
            exit;
        }
    } catch (Exception $e) {
        echo "Error loading details.";
        exit;
    }
}

// ── Get Station Name ──────────────────────────────────────
$station_name = 'All Stations';
if ($filter_station > 0) {
    try {
        $sn = $pdo->prepare("SELECT name FROM stations WHERE id=? LIMIT 1");
        $sn->execute([$filter_station]);
        $station_name = $sn->fetchColumn() ?: 'Station';
    } catch (Exception $e) {}
}

// ── Summary & Fetch Query Construction ────────────────────────
$where  = ["DATE(fa.adjustment_date) BETWEEN ? AND ?"];
$params = [$date_from, $date_to];

if ($filter_station > 0) {
    $where[] = "fa.station_id = ?";
    $params[] = $filter_station;
}
if ($adj_type_filter !== '') {
    $where[] = "fa.adjustment_type = ?";
    $params[] = $adj_type_filter;
}
if ($fuel_type_filter !== '') {
    $where[] = "fa.fuel_type = ?";
    $params[] = $fuel_type_filter;
}
if ($status_filter !== '') {
    $where[] = "LOWER(fa.status) = ?";
    $params[] = strtolower($status_filter);
}

// ── Summary Counts ───────────────────────────────────────────
$total_adjustments = 0; $pending_adjustments = 0; $approved_adjustments = 0; $rejected_adjustments = 0;
try {
    $sc_sql = "SELECT
        COUNT(*) as total,
        SUM(CASE WHEN LOWER(fa.status)='pending' OR fa.status = '' OR fa.status IS NULL THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN LOWER(fa.status)='approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN LOWER(fa.status)='rejected' THEN 1 ELSE 0 END) as rejected
        FROM fuel_adjustments fa
        WHERE " . implode(' AND ', $where);
    
    $sc = $pdo->prepare($sc_sql);
    $sc->execute($params);
    $sc_row = $sc->fetch(PDO::FETCH_ASSOC);
    $total_adjustments    = (int)($sc_row['total'] ?? 0);
    $pending_adjustments  = (int)($sc_row['pending'] ?? 0);
    $approved_adjustments = (int)($sc_row['approved'] ?? 0);
    $rejected_adjustments = (int)($sc_row['rejected'] ?? 0);
} catch (Exception $e) {}

// ── Fetch Adjustments ────────────────────────────────────────
$adjustments = [];
try {
    $stmt = $pdo->prepare("SELECT fa.*,
        COALESCE(NULLIF(CONCAT(TRIM(COALESCE(req.first_name,'')), ' ', TRIM(COALESCE(req.last_name,''))), ' '), req.username, 'Unknown') AS requested_by_name,
        COALESCE(NULLIF(CONCAT(TRIM(COALESCE(app.first_name,'')), ' ', TRIM(COALESCE(app.last_name,''))), ' '), app.username, '—') AS approved_by_name,
        s.name AS station_name
        FROM fuel_adjustments fa
        LEFT JOIN users req ON fa.user_id = req.id
        LEFT JOIN users app ON fa.approved_by = app.id
        LEFT JOIN stations s ON fa.station_id = s.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY fa.adjustment_date DESC, fa.id DESC LIMIT 500");
    $stmt->execute($params);
    $adjustments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Dynamic filter dropdown choices ──────────────────────────
$fuel_types = [];
$adjustment_types = [];
try {
    if ($filter_station > 0) {
        $ft_stmt = $pdo->prepare("SELECT DISTINCT fuel_type FROM fuel_adjustments WHERE station_id=? AND fuel_type IS NOT NULL AND fuel_type != '' ORDER BY fuel_type");
        $ft_stmt->execute([$filter_station]);
    } else {
        $ft_stmt = $pdo->query("SELECT DISTINCT fuel_type FROM fuel_adjustments WHERE fuel_type IS NOT NULL AND fuel_type != '' ORDER BY fuel_type");
    }
    $fuel_types = $ft_stmt->fetchAll(PDO::FETCH_COLUMN);

    $at_stmt = $pdo->query("SELECT DISTINCT adjustment_type FROM fuel_adjustments WHERE adjustment_type IS NOT NULL AND adjustment_type != '' ORDER BY adjustment_type");
    $adjustment_types = $at_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// ── Get All Stations (for filter) ─────────────────────────
$stations = [];
if ($role === 'superadmin') {
    try {
        $stmt = $pdo->query("SELECT id, name FROM stations WHERE status = 'Active' ORDER BY name");
        $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// ── EXPORT ───────────────────────────────────────────────────
if (in_array($export, ['excel','pdf'])) {
    $headers = ['Adjustment ID','Date','Adjustment Type','Fuel Type','Tank/Pump','Previous Value','New Value','Difference (L)','Reason','Requested By','Approved By','Status','Approval Date'];
    $rows_fmt = [];
    foreach($adjustments as $adj) {
        $diff = (float)$adj['liters'];
        $diff_str = ($diff >= 0 ? '+' : '') . number_format($diff, 2);

        // Derive tank/pump label
        $tank_pump = $adj['fuel_type'];
        if (strpos(strtolower($adj['reason']), 'pump') !== false) {
            $tank_pump .= ' (Pump)';
        } else {
            $tank_pump .= ' (Tank)';
        }

        $rows_fmt[] = [
            'ADJ-'.$adj['id'],
            date('M d, Y', strtotime($adj['adjustment_date'])),
            $adj['adjustment_type'],
            $adj['fuel_type'],
            $tank_pump,
            number_format($adj['previous_value'], 2),
            number_format($adj['new_value'], 2),
            $diff_str,
            $adj['reason'] ?? '—',
            $adj['requested_by_name'] ?? '—',
            $adj['approved_by_name'] ?? '—',
            ucfirst($adj['status']),
            $adj['approved_at'] ? date('M d, Y H:i', strtotime($adj['approved_at'])) : '—'
        ];
    }
    
    $filename = 'fuel_adjustments_oversight_' . $date_from . '_to_' . $date_to;

    if ($export === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="UTF-8"><style>table{border-collapse:collapse}th,td{border:1px solid #cbd5e1;padding:6px;font-size:12px;}th{background:#00264D;color:#fff;font-weight:bold;text-transform:uppercase;}</style></head><body>';
        echo '<h3>Fuel Adjustments Oversight Report</h3>';
        echo '<p><strong>Station:</strong> ' . htmlspecialchars($station_name) . ' | <strong>Period:</strong> ' . $date_from . ' to ' . $date_to . '</p>';
        echo '<table><thead><tr>';
        foreach ($headers as $h) echo '<th>' . htmlspecialchars($h) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows_fmt as $r) {
            echo '<tr>';
            foreach ($r as $c) echo '<td>' . htmlspecialchars($c) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></body></html>';
        exit;
    }

    if ($export === 'pdf') {
        header('Content-Type: text/html; charset=UTF-8');
        $generated = date('M d, Y H:i');
        
        $tbody = '';
        foreach ($rows_fmt as $r) {
            $tbody .= '<tr>';
            foreach ($r as $c) {
                $tbody .= '<td>' . htmlspecialchars($c) . '</td>';
            }
            $tbody .= '</tr>';
        }

        echo '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Fuel Adjustments Oversight Report</title>
            <style>
                body { font-family: Arial, sans-serif; font-size: 9px; color: #333; line-height: 1.4; padding: 20px; }
                .header { border-bottom: 2px solid #002f6c; padding-bottom: 10px; margin-bottom: 15px; }
                .logo-text { font-size: 16px; font-weight: bold; color: #002f6c; text-transform: uppercase; }
                .rpt-title { font-size: 12px; font-weight: bold; margin-top: 5px; color: #555; }
                .meta-table { width: 100%; margin-bottom: 15px; font-size: 9px; }
                .data-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
                .data-table th, .data-table td { border: 1px solid #ddd; padding: 5px 6px; text-align: left; }
                .data-table th { background-color: #002f6c; color: white; font-weight: bold; text-transform: uppercase; font-size: 8px; }
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
                    <td><strong>Station:</strong> ' . htmlspecialchars($station_name) . ' (ID: ' . htmlspecialchars($filter_station ?: 'All') . ')</td>
                    <td style="text-align: right;"><strong>Date Range:</strong> ' . htmlspecialchars($date_from) . ' to ' . htmlspecialchars($date_to) . '</td>
                </tr>
                <tr>
                    <td><strong>Generated By:</strong> ' . htmlspecialchars($me['username']) . ' (' . htmlspecialchars($role) . ')</td>
                    <td style="text-align: right;"><strong>Run Date:</strong> ' . $generated . '</td>
                </tr>
            </table>

            <table class="data-table">
                <thead>
                    <tr>';
                    foreach ($headers as $h) {
                        echo '<th>' . htmlspecialchars($h) . '</th>';
                    }
                    echo '</tr>
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

require_once __DIR__ . '/../partials/header.php';
?>

<style>
/* == PAGE HEADER - Petron unified standard == */
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
.ato-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.ato-card {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    padding: 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    position: relative;
    overflow: hidden;
}
.ato-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
}
.ato-card-info {
    display: flex;
    flex-direction: column;
}
.ato-card-lbl {
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
}
.ato-card-val {
    font-size: 20px;
    font-weight: 700;
    color: #1e293b;
}
.ato-card-icon {
    font-size: 24px;
    opacity: 0.8;
}

.ato-card.blue::before { background-color: #2563eb; }
.ato-card.blue .ato-card-icon { color: #2563eb; }
.ato-card.yellow::before { background-color: #d97706; }
.ato-card.yellow .ato-card-icon { color: #d97706; }
.ato-card.green::before { background-color: #16a34a; }
.ato-card.green .ato-card-icon { color: #16a34a; }
.ato-card.red::before { background-color: #dc2626; }
.ato-card.red .ato-card-icon { color: #dc2626; }

/* == FILTER BAR == */
.ato-filter {
    display: flex;
    align-items: flex-end;
    gap: 12px;
    flex-wrap: wrap;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    padding: 16px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.02);
}
.ato-fg {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.ato-fg label {
    font-size: 11px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}
.ato-fg input, .ato-fg select {
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
.ato-fg input:focus, .ato-fg select:focus {
    border-color: var(--petron-blue, #00264D);
    box-shadow: 0 0 0 3px rgba(0, 38, 77, 0.1);
}

/* Button styles - White outline style matching staff Transaction module */
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
.ato-btn-excel { color: #16a34a !important; border-color: #16a34a !important; }
.ato-btn-excel:hover { background: #16a34a !important; color: #ffffff !important; }
.ato-btn-pdf { color: #dc2626 !important; border-color: #dc2626 !important; }
.ato-btn-pdf:hover { background: #dc2626 !important; color: #ffffff !important; }
.ato-btn-back { color: #4b5563 !important; border-color: #9ca3af !important; }
.ato-btn-back:hover { background: #6b7280 !important; color: #ffffff !important; }

/* == TABLE CARD == */
.tbl-card {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 11px;
    overflow: hidden;
    box-shadow: 0 1px 4px rgba(0,0,0,0.05);
    margin-bottom: 24px;
}
.tbl-hd {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 13px 16px;
    border-bottom: 1px solid #e9ecef;
    flex-wrap: wrap;
    gap: 8px;
    background: #f8fafc;
}
.tbl-title {
    font-size: 13px;
    font-weight: 700;
    color: #00264D;
    display: flex;
    align-items: center;
    gap: 8px;
}
.ato-tbl {
    width: 100%;
    table-layout: fixed;
    border-collapse: collapse;
    font-size: 11px;
}
.ato-tbl thead tr {
    background: #002F70;
}
.ato-tbl thead th {
    padding: 10px 8px;
    font-weight: 700;
    color: #ffffff;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    font-size: 10.5px;
    border-bottom: 2px solid #001a3d;
    vertical-align: middle;
}
.ato-tbl tbody tr {
    border-bottom: 1px solid #f1f5f9;
    transition: background 0.1s;
}
.ato-tbl tbody tr:hover td {
    background: #eff6ff;
}
.ato-tbl tbody td {
    padding: 10px 8px;
    color: #334155;
    vertical-align: middle;
    overflow: hidden;
    text-overflow: ellipsis;
    background: #ffffff;
}

/* Action button styles stacked vertically with explicit text labels */
.action-box {
    display: flex;
    flex-direction: column;
    gap: 4px;
    width: 100%;
}
.action-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    height: 25px;
    padding: 0 8px;
    border-radius: 4px;
    font-size: 10.5px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid transparent;
    text-decoration: none;
    transition: all 0.1s ease;
    background: #ffffff !important;
    width: 100%;
    box-sizing: border-box;
    white-space: nowrap;
}
.action-btn-details { color: #1e40af !important; border-color: #bfdbfe !important; }
.action-btn-details:hover { background: #eff6ff !important; border-color: #1e40af !important; }
.action-btn-approve { color: #15803d !important; border-color: #bbf7d0 !important; }
.action-btn-approve:hover { background: #f0fdf4 !important; border-color: #15803d !important; }
.action-btn-reject { color: #b91c1c !important; border-color: #fecaca !important; }
.action-btn-reject:hover { background: #fef2f2 !important; border-color: #b91c1c !important; }
.action-btn-audit { color: #000000 !important; border-color: #d1d5db !important; }
.action-btn-audit:hover { background: #f3f4f6 !important; border-color: #374151 !important; }
.action-btn-print { color: #475569 !important; border-color: #cbd5e1 !important; }
.action-btn-print:hover { background: #f8fafc !important; border-color: #475569 !important; }

/* Status Labels */
.sb {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 9.5px;
    font-weight: 700;
    text-transform: uppercase;
    white-space: nowrap;
    text-align: center;
}
.sb-pending { background-color: #fef3c7; color: #b45309; }
.sb-approved { background-color: #dcfce7; color: #15803d; }
.sb-rejected { background-color: #fee2e2; color: #b91c1c; }

.var-pos { color: #16a34a; font-weight: 700; font-family: monospace; }
.var-neg { color: #dc2626; font-weight: 700; font-family: monospace; }
.var-zero { color: #64748b; font-weight: 600; font-family: monospace; }

/* Modal overlays & animations */
@keyframes modalFadeIn {
    from { opacity: 0; transform: translateY(-20px); }
    to { opacity: 1; transform: translateY(0); }
}
.ato-modal {
    display: none;
    position: fixed;
    z-index: 9999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    overflow: auto;
    background-color: rgba(0,0,0,0.4);
    align-items: center;
    justify-content: center;
}
.ato-modal-content {
    background-color: #fff;
    margin: auto;
    padding: 20px;
    border: 1px solid #cbd5e1;
    border-radius: 12px;
    width: 90%;
    max-width: 600px;
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
    animation: modalFadeIn 0.2s ease-out;
}
.details-item {
    display: flex;
    flex-direction: column;
    gap: 2px;
    border-bottom: 1px solid #f1f5f9;
    padding-bottom: 8px;
}
.details-item label {
    font-size: 10px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}
.details-item span {
    font-size: 12px;
    font-weight: 600;
    color: #1e293b;
    word-break: break-word;
}
.audit-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 10px;
}
.audit-meta {
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 4px;
    font-size: 10px;
    color: #64748b;
    margin-top: 6px;
    border-top: 1px dashed #e2e8f0;
    padding-top: 4px;
}
.ref-badge {
    font-family: monospace;
    font-size: 11px;
    font-weight: 700;
    background: #eff6ff;
    color: #1e40af;
    padding: 2px 7px;
    border-radius: 5px;
    border: 1px solid #dbeafe;
    white-space: nowrap;
}
.ato-empty { text-align:center; padding:50px 20px; color:#94a3b8; }
.ato-empty i { font-size:44px; display:block; margin-bottom:14px; opacity:.4; }
</style>

<div class="int-head">
    <div>
        <h1><i class="fas fa-sliders-h"></i> Fuel Adjustments Oversight</h1>
        <div class="sub">Admin review and approval override of manager-validated fuel inventory corrections for <strong><?= htmlspecialchars($station_name) ?></strong></div>
    </div>
    <a href="admin_dashboard.php" class="ato-btn ato-btn-back"><i class="fas fa-arrow-left"></i> Back</a>
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
<div class="ato-cards">
    <div class="ato-card blue">
        <div class="ato-card-info">
            <span class="ato-card-lbl">Total Adjustments</span>
            <span class="ato-card-val"><?= number_format($total_adjustments) ?></span>
        </div>
        <div class="ato-card-icon"><i class="fas fa-sliders-h"></i></div>
    </div>
    <div class="ato-card yellow">
        <div class="ato-card-info">
            <span class="ato-card-lbl">Pending Adjustments</span>
            <span class="ato-card-val"><?= number_format($pending_adjustments) ?></span>
        </div>
        <div class="ato-card-icon"><i class="fas fa-clock"></i></div>
    </div>
    <div class="ato-card green">
        <div class="ato-card-info">
            <span class="ato-card-lbl">Approved Adjustments</span>
            <span class="ato-card-val"><?= number_format($approved_adjustments) ?></span>
        </div>
        <div class="ato-card-icon"><i class="fas fa-check-circle"></i></div>
    </div>
    <div class="ato-card red">
        <div class="ato-card-info">
            <span class="ato-card-lbl">Rejected Adjustments</span>
            <span class="ato-card-val"><?= number_format($rejected_adjustments) ?></span>
        </div>
        <div class="ato-card-icon"><i class="fas fa-times-circle"></i></div>
    </div>
</div>

<!-- == FILTER BAR == -->
<form method="get" class="ato-filter">
    <?php if ($role === 'superadmin'): ?>
        <div class="ato-fg">
            <label>Station</label>
            <select name="station">
                <option value="0">All Stations</option>
                <?php foreach ($stations as $st): ?>
                    <option value="<?= $st['id'] ?>" <?= $filter_station === (int)$st['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($st['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>

    <div class="ato-fg">
        <label>Date From</label>
        <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
    </div>
    
    <div class="ato-fg">
        <label>Date To</label>
        <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
    </div>

    <div class="ato-fg">
        <label>Adjustment Type</label>
        <select name="adjustment_type">
            <option value="">All Types</option>
            <?php foreach ($adjustment_types as $type): ?>
                <option value="<?= htmlspecialchars($type) ?>" <?= $adj_type_filter === $type ? 'selected' : '' ?>>
                    <?= htmlspecialchars($type) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="ato-fg">
        <label>Fuel Type</label>
        <select name="fuel_type">
            <option value="">All Fuels</option>
            <?php foreach ($fuel_types as $ft): ?>
                <option value="<?= htmlspecialchars($ft) ?>" <?= $fuel_type_filter === $ft ? 'selected' : '' ?>>
                    <?= htmlspecialchars($ft) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="ato-fg">
        <label>Status</label>
        <select name="status_filter">
            <option value="">All Status</option>
            <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
            <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Approved</option>
            <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
        </select>
    </div>

    <div style="display:flex; gap:8px; margin-left:auto;">
        <button type="submit" class="ato-btn ato-btn-filter"><i class="fas fa-filter"></i> Apply Filters</button>
        <a href="admin_fuel_adjustments_oversight.php" class="ato-btn ato-btn-back"><i class="fas fa-times"></i> Reset</a>
        <button type="submit" name="export" value="excel" class="ato-btn ato-btn-excel"><i class="fas fa-file-excel"></i> Export Excel</button>
        <button type="submit" name="export" value="pdf" class="ato-btn ato-btn-pdf" target="_blank"><i class="fas fa-file-pdf"></i> Export PDF</button>
    </div>
</form>

<!-- == DETAILS TABLE == -->
<div class="tbl-card">
    <div class="tbl-hd">
        <span class="tbl-title"><i class="fas fa-list"></i> Adjustments Overview</span>
        <span style="font-size:11px;color:#64748b;font-weight:600;">Showing <?= count($adjustments) ?> record(s)</span>
    </div>

    <div style="overflow:hidden;">
        <table class="ato-tbl">
            <colgroup>
                <col style="width:6%">
                <col style="width:7%">
                <col style="width:8%">
                <col style="width:8%">
                <col style="width:8%">
                <col style="width:7%">
                <col style="width:7%">
                <col style="width:7%">
                <col style="width:12%">
                <col style="width:8%">
                <col style="width:8%">
                <col style="width:6%">
                <col style="width:7%">
                <col style="width:11%">
            </colgroup>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Date</th>
                    <th>Adjustment Type</th>
                    <th>Fuel Type</th>
                    <th>Tank/Pump</th>
                    <th style="text-align:right;">Prev Value</th>
                    <th style="text-align:right;">New Value</th>
                    <th style="text-align:right;">Diff (L)</th>
                    <th>Reason</th>
                    <th>Requested By</th>
                    <th>Approved By</th>
                    <th>Status</th>
                    <th>Approval Date</th>
                    <th style="text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($adjustments)): ?>
                    <tr>
                        <td colspan="14">
                            <div class="ato-empty">
                                <i class="fas fa-inbox"></i>
                                <div style="font-size:14px;font-weight:700;color:#64748b;">No adjustment records found.</div>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($adjustments as $adj):
                        $diff = (float)$adj['liters'];
                        $diff_str = ($diff >= 0 ? '+' : '') . number_format($diff, 2) . ' L';
                        $diff_class = $diff == 0 ? 'var-zero' : ($diff > 0 ? 'var-pos' : 'var-neg');

                        // Derive tank/pump label
                        $tank_pump = $adj['fuel_type'];
                        if (strpos(strtolower($adj['reason']), 'pump') !== false) {
                            $tank_pump .= ' (Pump)';
                        } else {
                            $tank_pump .= ' (Tank)';
                        }
                    ?>
                        <tr>
                            <td><span class="ref-badge">#<?= htmlspecialchars($adj['id']) ?></span></td>
                            <td><?= date('M d, Y', strtotime($adj['adjustment_date'])) ?></td>
                            <td><span style="font-weight:600; color:#475569;"><?= htmlspecialchars($adj['adjustment_type']) ?></span></td>
                            <td style="font-weight:700; color:#00264D;"><?= htmlspecialchars($adj['fuel_type']) ?></td>
                            <td><span style="font-size:10px; color:#64748b;"><?= htmlspecialchars($tank_pump) ?></span></td>
                            <td style="text-align:right; font-family:monospace;"><?= number_format($adj['previous_value'], 2) ?></td>
                            <td style="text-align:right; font-family:monospace;"><?= number_format($adj['new_value'], 2) ?></td>
                            <td style="text-align:right;" class="<?= $diff_class ?>"><?= $diff_str ?></td>
                            <td title="<?= htmlspecialchars($adj['reason']) ?>"><?= htmlspecialchars(substr($adj['reason'], 0, 40)) ?><?= strlen($adj['reason']) > 40 ? '...' : '' ?></td>
                            <td><?= htmlspecialchars($adj['requested_by_name']) ?></td>
                            <td><?= htmlspecialchars($adj['approved_by_name']) ?></td>
                            <td><span class="sb sb-<?= strtolower($adj['status']) ?>"><?= ucfirst(htmlspecialchars($adj['status'])) ?></span></td>
                            <td><?= ($adj['approved_at'] ? date('M d, Y', strtotime($adj['approved_at'])) : '—') ?></td>
                            <td style="text-align:center;">
                                <div class="action-box">
                                    <button class="action-btn action-btn-details" onclick="viewAdjDetails(<?= $adj['id'] ?>)">
                                        <i class="fas fa-info-circle"></i> Details
                                    </button>

                                    <?php if (strtolower($adj['status']) === 'pending'): ?>
                                        <button class="action-btn action-btn-approve" onclick="approveOverride(<?= $adj['id'] ?>, '<?= htmlspecialchars($adj['fuel_type'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($diff_str, ENT_QUOTES, 'UTF-8') ?>')">
                                            <i class="fas fa-check"></i> Approve
                                        </button>
                                        <button class="action-btn action-btn-reject" onclick="rejectOverride(<?= $adj['id'] ?>)">
                                            <i class="fas fa-times"></i> Reject
                                        </button>
                                    <?php endif; ?>

                                    <button class="action-btn action-btn-audit" onclick="viewAdjAudit(<?= $adj['id'] ?>)">
                                        <i class="fas fa-history"></i> History
                                    </button>
                                    <button class="action-btn action-btn-print" onclick="printSingleAdj(<?= $adj['id'] ?>)">
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

<script>
// Modal helpers
function openModal(id) {
    const m = document.getElementById(id);
    if (m) {
        m.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}
function closeModal(id) {
    const m = document.getElementById(id);
    if (m) {
        m.style.display = 'none';
        if (!document.querySelector('.ato-modal[style*="display: flex"]')) {
            document.body.style.overflow = '';
        }
    }
}
window.addEventListener('click', function(e) {
    if (e.target.classList.contains('ato-modal')) {
        closeModal(e.target.id);
    }
});

// View Details
function viewAdjDetails(id) {
    fetch(`?ajax_action=get_details&id=${id}`)
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                const data = res.data;
                const notes = data.notes ? data.notes.replace(/\n/g, '<br>') : '—';
                const diff = parseFloat(data.liters);
                const diffStr = (diff >= 0 ? '+' : '') + diff.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + ' L';
                const diffColor = diff >= 0 ? '#16a34a' : '#dc2626';

                const gridHtml = `
                    <div class="details-item"><label>Adjustment ID</label><span>#${data.id}</span></div>
                    <div class="details-item"><label>Adjustment Date</label><span>${data.adjustment_date}</span></div>
                    <div class="details-item"><label>Adjustment Type</label><span>${data.adjustment_type}</span></div>
                    <div class="details-item"><label>Fuel Type</label><span>${data.fuel_type}</span></div>
                    <div class="details-item"><label>Previous Value</label><span>${parseFloat(data.previous_value).toLocaleString(undefined, {minimumFractionDigits:2})} L</span></div>
                    <div class="details-item"><label>New Value</label><span>${parseFloat(data.new_value).toLocaleString(undefined, {minimumFractionDigits:2})} L</span></div>
                    <div class="details-item"><label>Difference</label><span style="color:${diffColor}; font-weight:700;">${diffStr}</span></div>
                    <div class="details-item"><label>Requested By</label><span>${data.requested_by_name}</span></div>
                    <div class="details-item"><label>Approved By</label><span>${data.approved_by_name}</span></div>
                    <div class="details-item"><label>Status</label><span>${data.status}</span></div>
                    <div class="details-item"><label>Approval Date</label><span>${data.approved_at || '—'}</span></div>
                    <div class="details-item" style="grid-column: span 2;"><label>Adjustment Reason</label><span>${data.reason || '—'}</span></div>
                    <div class="details-item" style="grid-column: span 2;"><label>Resolution Notes</label><span>${notes}</span></div>
                `;
                document.getElementById('detailsGrid').innerHTML = gridHtml;
                openModal('detailsModal');
            } else {
                alert(res.message || 'Error loading details.');
            }
        })
        .catch(err => alert('Network error.'));
}

// View History / Audit Logs
function viewAdjAudit(id) {
    fetch(`?ajax_action=get_audit&id=${id}`)
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                const logs = res.data;
                if (logs.length === 0) {
                    document.getElementById('auditList').innerHTML = '<div style="text-align:center;color:#64748b;padding:20px;">No audit trail history records found.</div>';
                } else {
                    const listHtml = logs.map(log => `
                        <div class="audit-card">
                            <div style="font-weight:700;color:#00264D;font-size:12px;">${log.action_type}</div>
                            <div style="margin-top:4px;color:#334155;font-size:11px;">${log.action_details}</div>
                            <div class="audit-meta">
                                <span><i class="fas fa-user"></i> ${log.username}</span>
                                <span><i class="fas fa-clock"></i> ${new Date(log.created_at).toLocaleString()}</span>
                            </div>
                        </div>
                    `).join('');
                    document.getElementById('auditList').innerHTML = listHtml;
                }
                openModal('auditModal');
            } else {
                alert(res.message || 'Error loading audit logs.');
            }
        })
        .catch(err => alert('Network error.'));
}

// Single Print Slip Action
function printSingleAdj(id) {
    const printUrl = `?export=pdf&single_id=${id}`;
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
    iframe.src = printUrl;
    iframe.onload = function() {
        setTimeout(function() {
            iframe.contentWindow.focus();
            iframe.contentWindow.print();
        }, 300);
    };
}

// Approve Override Action
function approveOverride(id, fuel, diff) {
    if (confirm(`Are you sure you want to approve this override request?\nAdjustment ID: #${id}\nFuel Type: ${fuel}\nDifference: ${diff}\nThis will adjust inventory stock immediately.`)) {
        document.getElementById('overrideFormAction').value = 'approve_override';
        document.getElementById('overrideFormId').value = id;
        document.getElementById('overrideForm').submit();
    }
}

// Reject Override Action
function rejectOverride(id) {
    document.getElementById('rejectFormId').value = id;
    document.getElementById('rejectionReasonText').value = '';
    openModal('rejectOverrideModal');
}
</script>

<!-- Details Modal -->
<div id="detailsModal" class="ato-modal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(0,0,0,0.4); align-items:center; justify-content:center;">
    <div class="ato-modal-content">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:12px; margin-bottom:16px;">
            <h3 style="margin:0; font-size:16px; font-weight:700; color:#00264D; text-transform:uppercase;"><i class="fas fa-info-circle"></i> Adjustment Details</h3>
            <button onclick="closeModal('detailsModal')" style="background:none; border:none; font-size:20px; cursor:pointer; color:#94a3b8; transition:color 0.15s;">&times;</button>
        </div>
        <div id="detailsGrid" style="display:grid; grid-template-columns:1fr 1fr; gap:12px; font-size:13px;">
            <!-- Dynamically populated -->
        </div>
        <div style="margin-top:20px; text-align:right; border-top:1px solid #e2e8f0; padding-top:12px;">
            <button onclick="closeModal('detailsModal')" class="ato-btn ato-btn-back" style="height:32px; padding:0 12px; font-size:12px; font-weight:600;">Close</button>
        </div>
    </div>
</div>

<!-- Audit Modal -->
<div id="auditModal" class="ato-modal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(0,0,0,0.4); align-items:center; justify-content:center;">
    <div class="ato-modal-content">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:12px; margin-bottom:16px;">
            <h3 style="margin:0; font-size:16px; font-weight:700; color:#00264D; text-transform:uppercase;"><i class="fas fa-history"></i> Audit Trail History</h3>
            <button onclick="closeModal('auditModal')" style="background:none; border:none; font-size:20px; cursor:pointer; color:#94a3b8; transition:color 0.15s;">&times;</button>
        </div>
        <div id="auditList" style="max-height:300px; overflow-y:auto; display:flex; flex-direction:column; gap:10px; padding-right:5px;">
            <!-- Dynamically populated -->
        </div>
        <div style="margin-top:20px; text-align:right; border-top:1px solid #e2e8f0; padding-top:12px;">
            <button onclick="closeModal('auditModal')" class="ato-btn ato-btn-back" style="height:32px; padding:0 12px; font-size:12px; font-weight:600;">Close</button>
        </div>
    </div>
</div>

<!-- Reject Override Modal -->
<div id="rejectOverrideModal" class="ato-modal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(0,0,0,0.4); align-items:center; justify-content:center;">
    <div class="ato-modal-content" style="max-width:500px;">
        <form method="post" action="">
            <input type="hidden" name="action" value="reject_override">
            <input type="hidden" name="id" id="rejectFormId">
            
            <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:12px; margin-bottom:16px;">
                <h3 style="margin:0; font-size:16px; font-weight:700; color:#dc2626; text-transform:uppercase;"><i class="fas fa-times-circle"></i> Reject Override</h3>
                <button type="button" onclick="closeModal('rejectOverrideModal')" style="background:none; border:none; font-size:20px; cursor:pointer; color:#94a3b8; transition:color 0.15s;">&times;</button>
            </div>
            
            <div style="font-size:13px; margin-bottom:12px;">
                <label style="font-weight:700; color:#475569; display:block; margin-bottom:6px;">Rejection Remarks *</label>
                <textarea name="rejection_reason" id="rejectionReasonText" required placeholder="Explain why this adjustment request is being rejected..." style="width:100%; height:80px; padding:8px; border:1px solid #cbd5e1; border-radius:6px; resize:none; font-size:13px; font-family:inherit; box-sizing:border-box;"></textarea>
            </div>
            
            <div style="margin-top:20px; text-align:right; border-top:1px solid #e2e8f0; padding-top:12px; display:flex; gap:8px; justify-content:flex-end;">
                <button type="button" onclick="closeModal('rejectOverrideModal')" class="ato-btn ato-btn-back" style="height:32px; padding:0 12px; font-size:12px;">Cancel</button>
                <button type="submit" class="ato-btn" style="height:32px; padding:0 12px; font-size:12px; font-weight:600; color:#fff !important; background:#dc2626 !important; border-color:#dc2626 !important;">Confirm Rejection</button>
            </div>
        </form>
    </div>
</div>

<!-- Override Hidden Form -->
<form id="overrideForm" method="post" style="display:none;">
    <input type="hidden" name="action" id="overrideFormAction">
    <input type="hidden" name="id" id="overrideFormId">
</form>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
