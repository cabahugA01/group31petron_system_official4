<?php
// ============================================================
// Manager Pump Master Oversight â€“ manager_fuel_pump_master.php
// Purpose: View, monitor, and manage station fuel pump assignments,
//          calibration values, status changes, and history validation.
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'fuel_pump_master';
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

// â”€â”€ Status Badge Styles / Helper Functions â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (!function_exists('getStatusBadgeClass')) {
    function getStatusBadgeClass($status) {
        $s = strtolower(trim($status ?? ''));
        if ($s === 'active') return 'bg-green';
        if ($s === 'inactive') return 'bg-red';
        if ($s === 'maintenance') return 'bg-amber';
        return 'bg-gray';
    }
}

if (!function_exists('getStatusLabel')) {
    function getStatusLabel($status) {
        $s = strtolower(trim($status ?? ''));
        if ($s === 'active') return 'Active';
        if ($s === 'inactive') return 'Inactive';
        if ($s === 'maintenance') return 'Maintenance';
        return ucfirst($status);
    }
}

// â”€â”€ POST Actions (Activate / Deactivate / Update Calibration) â”€â”€
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['export'])) {
    $action = trim($_POST['action'] ?? '');
    $pump_id = (int)($_POST['pump_id'] ?? 0);

    // 1. ACTIVATE PUMP
    if ($action === 'activate') {
        if ($pump_id <= 0) {
            $_SESSION['error'] = 'Invalid pump ID.';
            header('Location: manager_fuel_pump_master.php'); exit;
        }

        try {
            $pdo->beginTransaction();

            // Fetch pump
            $stmt = $pdo->prepare("SELECT * FROM fuel_pumps WHERE id = ? AND station_id = ? FOR UPDATE");
            $stmt->execute([$pump_id, $station_id]);
            $pump = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$pump) throw new Exception("Pump not found.");

            // Update status
            $up = $pdo->prepare("UPDATE fuel_pumps SET status='Active' WHERE id=? AND station_id=?");
            $up->execute([$pump_id, $station_id]);

            log_activity($pdo, $me['id'], 'Activate Pump', "Activated pump ID {$pump_id} (Number: {$pump['pump_number']})");
            $_SESSION['success'] = "Pump <strong>{$pump['pump_number']}</strong> has been activated.";
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = "Error: " . $e->getMessage();
        }
        header('Location: manager_fuel_pump_master.php'); exit;
    }

    // 2. DEACTIVATE PUMP
    elseif ($action === 'deactivate') {
        if ($pump_id <= 0) {
            $_SESSION['error'] = 'Invalid pump ID.';
            header('Location: manager_fuel_pump_master.php'); exit;
        }

        try {
            $pdo->beginTransaction();

            // Fetch pump
            $stmt = $pdo->prepare("SELECT * FROM fuel_pumps WHERE id = ? AND station_id = ? FOR UPDATE");
            $stmt->execute([$pump_id, $station_id]);
            $pump = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$pump) throw new Exception("Pump not found.");

            // Update status
            $up = $pdo->prepare("UPDATE fuel_pumps SET status='Inactive' WHERE id=? AND station_id=?");
            $up->execute([$pump_id, $station_id]);

            log_activity($pdo, $me['id'], 'Deactivate Pump', "Deactivated pump ID {$pump_id} (Number: {$pump['pump_number']})");
            $_SESSION['success'] = "Pump <strong>{$pump['pump_number']}</strong> has been deactivated.";
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = "Error: " . $e->getMessage();
        }
        header('Location: manager_fuel_pump_master.php'); exit;
    }

    // 3. UPDATE CALIBRATION VALUE
    elseif ($action === 'update_calibration') {
        $calibration_value = (float)($_POST['calibration_value'] ?? 0);
        $reason            = trim($_POST['reason'] ?? '');

        if ($pump_id <= 0) {
            $_SESSION['error'] = 'Invalid pump ID.';
            header('Location: manager_fuel_pump_master.php'); exit;
        }

        if (empty($reason)) {
            $_SESSION['error'] = 'Detailed calibration reason is required.';
            header('Location: manager_fuel_pump_master.php'); exit;
        }

        try {
            $pdo->beginTransaction();

            // Fetch pump & fuel type name
            $stmt = $pdo->prepare("
                SELECT fp.*, ft.name as fuel_type_name
                FROM fuel_pumps fp
                LEFT JOIN fuel_types ft ON fp.fuel_type_id = ft.id
                WHERE fp.id = ? AND fp.station_id = ? FOR UPDATE
            ");
            $stmt->execute([$pump_id, $station_id]);
            $pump = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$pump) throw new Exception("Pump record not found.");

            $previous_cal = (float)$pump['calibration_value'];
            $difference   = $calibration_value - $previous_cal;

            // Update fuel_pumps record
            $up = $pdo->prepare("
                UPDATE fuel_pumps 
                SET calibration_value = ?, 
                    calibration_updated_at = NOW(), 
                    calibration_updated_by = ?, 
                    calibration_notes = ? 
                WHERE id = ? AND station_id = ?
            ");
            $up->execute([$calibration_value, $me['id'], $reason, $pump_id, $station_id]);

            // Log to pump_calibration_history
            $ins_history = $pdo->prepare("
                INSERT INTO pump_calibration_history 
                    (station_id, fuel_type, previous_calibration, new_calibration, updated_by, updated_at, created_at, reason)
                VALUES (?, ?, ?, ?, ?, NOW(), NOW(), ?)
            ");
            $ins_history->execute([
                $station_id, 
                $pump['fuel_type_name'], 
                $previous_cal, 
                $calibration_value, 
                $me['id'], 
                "Pump " . $pump['pump_number'] . ": " . $reason
            ]);

            // CRITICAL: Insert into fuel_adjustments for Admin oversight transparency
            $adj_notes = "Calibration adjustment for Pump " . $pump['pump_number'] . " (" . $pump['fuel_type_name'] . "). Reason: " . $reason;
            $ins_adj = $pdo->prepare("
                INSERT INTO fuel_adjustments 
                    (station_id, adjustment_date, fuel_type, fuel_type_id, adjustment_type, liters, previous_value, new_value, reason, user_id, status, created_at)
                VALUES (?, CURDATE(), ?, ?, 'Calibration', ?, 0, 0, ?, ?, 'Approved', NOW())
            ");
            $ins_adj->execute([
                $station_id, 
                $pump['fuel_type_name'], 
                $pump['fuel_type_id'], 
                $difference, 
                $adj_notes, 
                $me['id']
            ]);

            // Sync with fuel_inventory latest_calibration value
            $up_inv = $pdo->prepare("
                UPDATE fuel_inventory 
                SET latest_calibration = ?, last_updated = NOW() 
                WHERE station_id = ? AND fuel_type_id = ?
            ");
            $up_inv->execute([$calibration_value, $station_id, $pump['fuel_type_id']]);

            log_activity($pdo, $me['id'], 'Update Calibration', "Updated pump {$pump['pump_number']} calibration to {$calibration_value} L. Change: {$difference} L.");
            $_SESSION['success'] = "Calibration for Pump <strong>{$pump['pump_number']}</strong> updated successfully.";
            $pdo->commit();

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = "Error: " . $e->getMessage();
        }
        header('Location: manager_fuel_pump_master.php'); exit;
    }
}

// â”€â”€ GET Filters â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$fuel_type_filter   = trim($_GET['fuel_type'] ?? 'all');
$pump_status_filter = trim($_GET['pump_status'] ?? 'all');
$search_pump        = trim($_GET['search_pump'] ?? '');
$export             = trim($_GET['export'] ?? '');

// Base SQL conditions
$where = ["fp.station_id = ?"];
$params = [$station_id];

// Fuel Type Filter
if ($fuel_type_filter !== 'all' && $fuel_type_filter !== '') {
    $where[] = "ft.name = ?";
    $params[] = $fuel_type_filter;
}

// Status Filter
if ($pump_status_filter !== 'all' && $pump_status_filter !== '') {
    $where[] = "LOWER(fp.status) = ?";
    $params[] = strtolower($pump_status_filter);
}

// Search Filter
if ($search_pump !== '') {
    $where[] = "fp.pump_number LIKE ?";
    $params[] = '%' . $search_pump . '%';
}

// â”€â”€ Fetch Pumps â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$pumps = [];
try {
    $sql = "SELECT fp.*, 
                   ft.name as fuel_type_name,
                   fi.current_stock as tank_stock,
                   fi.capacity as tank_capacity,
                   COALESCE(
                       NULLIF(CONCAT(TRIM(COALESCE(u.first_name, '')), ' ', TRIM(COALESCE(u.last_name, ''))), ' '),
                       u.username,
                       '—'
                   ) as updated_by_name
            FROM fuel_pumps fp
            LEFT JOIN fuel_types ft ON fp.fuel_type_id = ft.id
            LEFT JOIN fuel_inventory fi ON fp.fuel_type_id = fi.fuel_type_id AND fi.station_id = fp.station_id
            LEFT JOIN users u ON fp.calibration_updated_by = u.id
            WHERE " . implode(" AND ", $where) . "
            ORDER BY fp.pump_number ASC, fp.id ASC";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $pumps = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Fetch pumps error: " . $e->getMessage());
    $_SESSION['error'] = "Error loading pumps: " . $e->getMessage();
}

// â”€â”€ Compute Summary Card Metrics â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$total_pumps_count       = 0;
$active_pumps_count      = 0;
$inactive_pumps_count    = 0;
$calibrated_pumps_count  = 0;
$cal_updates_this_month = 0;

try {
    // 1. Total Pumps
    $sp = $pdo->prepare("SELECT COUNT(*) FROM fuel_pumps WHERE station_id = ?");
    $sp->execute([$station_id]);
    $total_pumps_count = (int)$sp->fetchColumn();

    // 2. Active Pumps
    $sa = $pdo->prepare("SELECT COUNT(*) FROM fuel_pumps WHERE station_id = ? AND LOWER(status) = 'active'");
    $sa->execute([$station_id]);
    $active_pumps_count = (int)$sa->fetchColumn();

    // 3. Inactive Pumps
    $si = $pdo->prepare("SELECT COUNT(*) FROM fuel_pumps WHERE station_id = ? AND LOWER(status) = 'inactive'");
    $si->execute([$station_id]);
    $inactive_pumps_count = (int)$si->fetchColumn();

    // 4. Pumps with Calibration (calibration_value is non-zero)
    $sc = $pdo->prepare("SELECT COUNT(*) FROM fuel_pumps WHERE station_id = ? AND calibration_value != 0.00 AND calibration_value IS NOT NULL");
    $sc->execute([$station_id]);
    $calibrated_pumps_count = (int)$sc->fetchColumn();

    // 5. Calibration updates this month
    $sum_m = $pdo->prepare("
        SELECT COUNT(*) 
        FROM pump_calibration_history 
        WHERE station_id = ? 
          AND MONTH(updated_at) = MONTH(CURRENT_DATE()) 
          AND YEAR(updated_at) = YEAR(CURRENT_DATE())
    ");
    $sum_m->execute([$station_id]);
    $cal_updates_this_month = (int)$sum_m->fetchColumn();
} catch (Exception $e) {
    error_log("Summary calculations error: " . $e->getMessage());
}

// â”€â”€ Fetch dynamic fuel types for filter â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$fuel_types = [];
try {
    $ft_stmt = $pdo->prepare("SELECT DISTINCT ft.name FROM fuel_inventory fi JOIN fuel_types ft ON fi.fuel_type_id = ft.id WHERE fi.station_id=? ORDER BY ft.name");
    $ft_stmt->execute([$station_id]);
    $fuel_types = $ft_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// â”€â”€ Fetch complete calibration history for JS modal viewer â”€â”€â”€â”€
$calibration_history_all = [];
try {
    $hist_stmt = $pdo->prepare("
        SELECT pch.*, 
               COALESCE(NULLIF(CONCAT(TRIM(u.first_name), ' ', TRIM(u.last_name)), ' '), u.username, 'System') as updater_name
        FROM pump_calibration_history pch
        LEFT JOIN users u ON pch.updated_by = u.id
        WHERE pch.station_id = ?
        ORDER BY pch.updated_at DESC
    ");
    $hist_stmt->execute([$station_id]);
    $calibration_history_all = $hist_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// â”€â”€ EXPORTS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (in_array($export, ['excel', 'pdf'])) {
    $headers = ['Pump ID', 'Pump Name', 'Fuel Type', 'Assigned Tank', 'Calibration Value', 'Status', 'Last Updated', 'Updated By'];
    $rows_fmt = [];
    foreach ($pumps as $p) {
        $rows_fmt[] = [
            'PUMP-' . $p['id'],
            $p['pump_number'],
            $p['fuel_type_name'] ?? '—',
            ($p['fuel_type_name'] ?? '') . ' Tank (Cap: ' . number_format($p['tank_capacity'] ?? 0, 0) . ' L)',
            number_format($p['calibration_value'], 3) . ' L',
            getStatusLabel($p['status']),
            $p['calibration_updated_at'] ? date('M d, Y H:i', strtotime($p['calibration_updated_at'])) : '—',
            $p['updated_by_name'] ?? '—'
        ];
    }
    $filename = 'pump_master_oversight_' . date('Y-m-d');

    if ($export === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="UTF-8"><style>table{border-collapse:collapse}th,td{border:1px solid #ddd;padding:7px}th{background:#002F6C;color:#fff;font-size:11px}</style></head><body>';
        echo '<h2>Pump Master Oversight Report</h2><p>Station: ' . $station_id . ' | Run Date: ' . date('M d, Y H:i') . '</p>';
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
        foreach ($pumps as $p) {
            $tbody .= '<tr>';
            $tbody .= '<td>PUMP-' . htmlspecialchars($p['id']) . '</td>';
            $tbody .= '<td>' . htmlspecialchars($p['pump_number']) . '</td>';
            $tbody .= '<td>' . htmlspecialchars($p['fuel_type_name'] ?? '—') . '</td>';
            $tbody .= '<td>' . htmlspecialchars($p['fuel_type_name'] ?? '') . ' Tank (' . number_format($p['tank_capacity'] ?? 0, 0) . ' L)</td>';
            $tbody .= '<td style="text-align:right;">' . number_format($p['calibration_value'], 3) . ' L</td>';
            $tbody .= '<td>' . getStatusLabel($p['status']) . '</td>';
            $tbody .= '<td>' . ($p['calibration_updated_at'] ? date('M d, Y', strtotime($p['calibration_updated_at'])) : '—') . '</td>';
            $tbody .= '<td>' . htmlspecialchars($p['updated_by_name'] ?? '—') . '</td>';
            $tbody .= '</tr>';
        }

        echo '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Pump Master Oversight Report</title>
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
                <div class="rpt-title">Pump Master Oversight Summary</div>
            </div>
            
            <table class="meta-table">
                <tr>
                    <td><strong>Station ID:</strong> ' . htmlspecialchars($station_id) . '</td>
                    <td style="text-align: right;"><strong>Run Date:</strong> ' . $generated . '</td>
                </tr>
                <tr>
                    <td><strong>Generated By:</strong> ' . htmlspecialchars($me['username']) . ' (' . htmlspecialchars($role) . ')</td>
                    <td style="text-align: right;"><strong>Total Pumps Loaded:</strong> ' . count($pumps) . '</td>
                </tr>
            </table>

            <table class="data-table">
                <thead>
                    <tr>
                        <th>Pump ID</th>
                        <th>Pump Name</th>
                        <th>Fuel Type</th>
                        <th>Assigned Tank</th>
                        <th style="text-align:right;">Calibration</th>
                        <th>Status</th>
                        <th>Last Updated</th>
                        <th>Updated By</th>
                    </tr>
                </thead>
                <tbody>
                    ' . $tbody . '
                </tbody>
            </table>

            <div class="footer">
                <span>System Generated Report â€¢ Confidential</span>
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
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
.afto-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
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

/* Card variants based on colors (No Emojis) */
.afto-card.blue::before   { background-color: #2563eb; }
.afto-card.blue .afto-card-icon { color: #2563eb; }
.afto-card.green::before  { background-color: #16a34a; }
.afto-card.green .afto-card-icon { color: #16a34a; }
.afto-card.red::before    { background-color: #dc2626; }
.afto-card.red .afto-card-icon { color: #dc2626; }
.afto-card.yellow::before { background-color: #d97706; }
.afto-card.yellow .afto-card-icon { color: #d97706; }
.afto-card.purple::before { background-color: #7c3aed; }
.afto-card.purple .afto-card-icon { color: #7c3aed; }

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
    border-collapse: collapse;
    font-size: 12px;
    text-align: left;
}
.afto-tbl thead tr {
    background: #002F70;
}
.afto-tbl thead th {
    padding: 10px 12px;
    font-weight: 700;
    color: #ffffff;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    font-size: 11px;
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
    padding: 10px 12px;
    color: #334155;
    vertical-align: middle;
}

/* Numeric alignment */
.align-right { text-align: right; font-family: monospace; }
.align-left { text-align: left; }
.bold-vol { font-weight: 700; color: #002F6C; }

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
    max-width: 550px;
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
    max-height: 400px;
    overflow-y: auto;
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
        <h1><i class="fas fa-gas-pump"></i> Pump Master Oversight</h1>
        <div class="sub">Monitor pump layouts, verify nozzle calibration values, and adjust operational status settings</div>
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
    <!-- Total Pumps Card -->
    <div class="afto-card blue">
        <div class="afto-card-info">
            <span class="afto-card-lbl">Total Pumps</span>
            <span class="afto-card-val"><?= number_format($total_pumps_count) ?></span>
        </div>
        <div class="afto-card-icon"><i class="fas fa-gas-pump"></i></div>
    </div>
    
    <!-- Active Pumps Card -->
    <div class="afto-card green">
        <div class="afto-card-info">
            <span class="afto-card-lbl">Active Pumps</span>
            <span class="afto-card-val"><?= number_format($active_pumps_count) ?></span>
        </div>
        <div class="afto-card-icon"><i class="fas fa-toggle-on"></i></div>
    </div>
    
    <!-- Inactive Pumps Card -->
    <div class="afto-card red">
        <div class="afto-card-info">
            <span class="afto-card-lbl">Inactive Pumps</span>
            <span class="afto-card-val"><?= number_format($inactive_pumps_count) ?></span>
        </div>
        <div class="afto-card-icon"><i class="fas fa-toggle-off"></i></div>
    </div>
    
    <!-- Calibrated Pumps Card -->
    <div class="afto-card yellow">
        <div class="afto-card-info">
            <span class="afto-card-lbl">Pumps with Calibration</span>
            <span class="afto-card-val"><?= number_format($calibrated_pumps_count) ?></span>
        </div>
        <div class="afto-card-icon"><i class="fas fa-balance-scale"></i></div>
    </div>
    
    <!-- Monthly Calibration Card -->
    <div class="afto-card purple">
        <div class="afto-card-info">
            <span class="afto-card-lbl">Cal. Updates This Month</span>
            <span class="afto-card-val"><?= number_format($cal_updates_this_month) ?></span>
        </div>
        <div class="afto-card-icon"><i class="fas fa-history"></i></div>
    </div>
</div>

<!-- == FILTERS == -->
<form method="get" class="afto-filter">
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
        <label>Pump Status</label>
        <select name="pump_status">
            <option value="all">All Status</option>
            <option value="active" <?= $pump_status_filter === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $pump_status_filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            <option value="maintenance" <?= $pump_status_filter === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
        </select>
    </div>
    
    <div class="afto-fg">
        <label>Search Pump</label>
        <input type="text" name="search_pump" value="<?= htmlspecialchars($search_pump) ?>" placeholder="Search pump number/name..." style="width: 220px;">
    </div>
    
    <div class="afto-actions">
        <button type="submit" class="ato-btn ato-btn-filter"><i class="fas fa-filter"></i> Filter</button>
        <a href="manager_fuel_pump_master.php" class="ato-btn ato-btn-reset"><i class="fas fa-sync-alt"></i> Reset</a>
        <button type="submit" name="export" value="excel" class="ato-btn ato-btn-export"><i class="fas fa-file-excel"></i> Export Excel</button>
        <button type="submit" name="export" value="pdf" class="ato-btn ato-btn-pdf" target="_blank"><i class="fas fa-file-pdf"></i> Export PDF</button>
    </div>
</form>

<!-- == DETAILS TABLE == -->
<div class="tbl-card">
    <div class="tbl-hd">
        <span class="tbl-title"><i class="fas fa-gas-pump"></i> Fuel Nozzles & Calibration Assignments</span>
        <span style="font-size: 11px; color: #64748b; font-weight: 600;">Showing <?= count($pumps) ?> record(s)</span>
    </div>
    
    <div style="overflow-x: auto;">
        <table class="afto-tbl">
            <thead>
                <tr>
                    <th>Pump ID</th>
                    <th>Pump Name</th>
                    <th>Fuel Type</th>
                    <th>Assigned Tank</th>
                    <th class="align-right">Calibration Value</th>
                    <th>Status</th>
                    <th>Last Updated</th>
                    <th>Updated By</th>
                    <th style="text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pumps)): ?>
                    <tr>
                        <td colspan="9">
                            <div class="empty-state">
                                <i class="fas fa-inbox"></i>
                                No pump layout records found matching the filter criteria.
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($pumps as $p): 
                        $cal = (float)$p['calibration_value'];
                        $cal_class = $cal == 0 ? 'var-zero' : ($cal > 0 ? 'var-pos' : 'var-neg');
                        $cal_str = ($cal >= 0 ? '+' : '') . number_format($cal, 3) . ' L';
                        
                        $tank_lbl = ($p['fuel_type_name'] ?? '—') . ' Tank (Cap: ' . number_format($p['tank_capacity'] ?? 0, 0) . ' L)';
                    ?>
                        <tr>
                            <td><strong style="color:#1e40af;">PUMP-<?= htmlspecialchars($p['id']) ?></strong></td>
                            <td style="font-weight: 600;"><?= htmlspecialchars($p['pump_number']) ?></td>
                            <td class="bold-vol"><?= htmlspecialchars($p['fuel_type_name'] ?? '—') ?></td>
                            <td><span style="font-size:11px; color:#64748b;"><?= htmlspecialchars($tank_lbl) ?></span></td>
                            <td class="align-right <?= $cal_class ?>" style="font-weight: bold; font-family: monospace;"><?= $cal_str ?></td>
                            <td>
                                <span class="badge-lbl <?= getStatusBadgeClass($p['status']) ?>">
                                    <?= getStatusLabel($p['status']) ?>
                                </span>
                            </td>
                            <td>
                                <?= $p['calibration_updated_at'] ? date('M d, Y H:i', strtotime($p['calibration_updated_at'])) : '—' ?>
                            </td>
                            <td><?= htmlspecialchars($p['updated_by_name'] ?? '—') ?></td>
                            <td style="text-align: center; white-space: nowrap;">
                                <div style="display: flex; gap: 4px; justify-content: center;">
                                    <!-- VIEW PUMP -->
                                    <button class="row-btn row-btn-details btn-view-pump"
                                        data-pump="<?= htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8') ?>"
                                        title="View Pump Details">
                                        <i class="fas fa-eye"></i> View
                                    </button>

                                    <!-- CALIBRATION -->
                                    <button class="row-btn row-btn-approve btn-calibrate"
                                        data-pump-id="<?= $p['id'] ?>"
                                        data-pump-name="<?= htmlspecialchars($p['pump_number'], ENT_QUOTES) ?>"
                                        data-cal-val="<?= $cal ?>"
                                        data-fuel-type="<?= htmlspecialchars($p['fuel_type_name'] ?? '', ENT_QUOTES) ?>"
                                        title="Update Calibration">
                                        <i class="fas fa-balance-scale"></i> Calibration
                                    </button>

                                    <!-- ACTIVATE / DEACTIVATE -->
                                    <?php if (strtolower($p['status']) !== 'active'): ?>
                                        <form method="post" style="display: inline;" class="pump-status-form">
                                            <input type="hidden" name="action" value="activate">
                                            <input type="hidden" name="pump_id" value="<?= $p['id'] ?>">
                                            <button type="button" class="row-btn row-btn-details btn-status-submit"
                                                data-confirm="Activate Pump <?= htmlspecialchars(addslashes($p['pump_number'])) ?>?"
                                                style="color:#16a34a !important; border-color:#bbf7d0 !important;"
                                                title="Activate Pump">
                                                <i class="fas fa-play"></i> Activate
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" style="display: inline;" class="pump-status-form">
                                            <input type="hidden" name="action" value="deactivate">
                                            <input type="hidden" name="pump_id" value="<?= $p['id'] ?>">
                                            <button type="button" class="row-btn row-btn-reject btn-status-submit"
                                                data-confirm="Deactivate Pump <?= htmlspecialchars(addslashes($p['pump_number'])) ?>?"
                                                title="Deactivate Pump">
                                                <i class="fas fa-stop"></i> Deactivate
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <!-- HISTORY -->
                                    <button class="row-btn row-btn-print btn-view-history"
                                        data-fuel-type="<?= htmlspecialchars($p['fuel_type_name'] ?? '', ENT_QUOTES) ?>"
                                        data-pump-name="<?= htmlspecialchars($p['pump_number'], ENT_QUOTES) ?>"
                                        title="View Calibration History">
                                        <i class="fas fa-history"></i> History
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

<!-- == MODAL: VIEW PUMP DETAILS == -->
<div id="viewPumpModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Pump Details</h3>
            <span class="close" onclick="closeModal('viewPumpModal')">&times;</span>
        </div>
        <div class="modal-body" id="pumpDetailsContent">
            <!-- Rendered dynamically -->
        </div>
        <div class="modal-footer">
            <button class="modal-btn modal-btn-secondary" onclick="closeModal('viewPumpModal')">Close</button>
        </div>
    </div>
</div>

<!-- == MODAL: UPDATE CALIBRATION == -->
<div id="calibrationModal" class="modal-overlay">
    <div class="modal-box">
        <form method="post" action="manager_fuel_pump_master.php">
            <input type="hidden" name="action" value="update_calibration">
            <input type="hidden" name="pump_id" id="cal_pump_id">
            
            <div class="modal-header">
                <h3>Update Nozzle Calibration</h3>
                <span class="close" onclick="closeModal('calibrationModal')">&times;</span>
            </div>
            <div class="modal-body">
                <div class="modal-form-row">
                    <label>Pump Number / Name</label>
                    <input type="text" id="cal_pump_name" readonly style="background: #f1f5f9; color: #475569;">
                </div>
                
                <div class="modal-form-row">
                    <label>Fuel Type / Tank</label>
                    <input type="text" id="cal_fuel_type" readonly style="background: #f1f5f9; color: #475569;">
                </div>

                <div class="modal-form-row">
                    <label>Previous Calibration Value (Liters)</label>
                    <input type="text" id="cal_prev_val" readonly style="background: #f1f5f9; color: #475569;">
                </div>
                
                <div class="modal-form-row">
                    <label>New Calibration Value (Liters) *</label>
                    <input type="number" step="0.001" name="calibration_value" required placeholder="e.g. 0.050 or -0.020">
                    <span style="font-size: 10px; color: #64748b; margin-top: 2px; display: block;">
                        Positive value represents over-dispensing correction (adds stock adjustments), negative represents under-dispensing.
                    </span>
                </div>
                
                <div class="modal-form-row">
                    <label>Calibration Correction Reason *</label>
                    <textarea name="reason" required placeholder="Describe the reason (e.g. periodic validation with 10L calibration bucket, nozzle replacement verification...)"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="modal-btn modal-btn-secondary" onclick="closeModal('calibrationModal')">Cancel</button>
                <button type="submit" class="modal-btn modal-btn-primary">Save Calibration</button>
            </div>
        </form>
    </div>
</div>

<!-- == MODAL: CALIBRATION HISTORY == -->
<div id="historyModal" class="modal-overlay">
    <div class="modal-box" style="max-width: 650px;">
        <div class="modal-header">
            <h3 id="historyModalTitle">Calibration History</h3>
            <span class="close" onclick="closeModal('historyModal')">&times;</span>
        </div>
        <div class="modal-body">
            <div style="overflow-x: auto;">
                <table class="afto-tbl" style="font-size: 11px;">
                    <thead>
                        <tr>
                            <th>Update Date</th>
                            <th class="align-right">Prev Value</th>
                            <th class="align-right">New Value</th>
                            <th class="align-right">Difference</th>
                            <th>Changed By</th>
                            <th>Reason / Explanation</th>
                        </tr>
                    </thead>
                    <tbody id="historyTableBody">
                        <!-- Loaded dynamically from JSON -->
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-footer">
            <button class="modal-btn modal-btn-secondary" onclick="closeModal('historyModal')">Close</button>
        </div>
    </div>
</div>

<script>
// â”€â”€ Calibration history pre-loaded from PHP â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const calibrationLogsAll = <?= json_encode($calibration_history_all) ?>;

// â”€â”€ Modal open/close â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}
document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) closeModal(overlay.id);
    });
});

// â”€â”€ VIEW PUMP â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
document.querySelectorAll('.btn-view-pump').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var p;
        try { p = JSON.parse(this.dataset.pump); }
        catch(e) { alert('Error reading pump data: ' + e.message); return; }

        var cal = parseFloat(p.calibration_value) || 0;
        var calStr = (cal >= 0 ? '+' : '') + cal.toFixed(3) + ' L';
        var capFmt = parseFloat(p.tank_capacity || 0).toLocaleString();

        var html = '<div class="detail-row"><span class="detail-lbl">Pump ID</span><span class="detail-val">PUMP-' + p.id + '</span></div>'
            + '<div class="detail-row"><span class="detail-lbl">Pump Name</span><span class="detail-val">' + (p.pump_number || '—') + '</span></div>'
            + '<div class="detail-row"><span class="detail-lbl">Fuel Type</span><span class="detail-val">' + (p.fuel_type_name || '—') + '</span></div>'
            + '<div class="detail-row"><span class="detail-lbl">Assigned Tank</span><span class="detail-val">' + (p.fuel_type_name || '') + ' Tank (Cap: ' + capFmt + ' L)</span></div>'
            + '<div class="detail-row"><span class="detail-lbl">Current Stock</span><span class="detail-val">' + parseFloat(p.tank_stock || 0).toFixed(2) + ' L</span></div>'
            + '<div class="detail-row"><span class="detail-lbl">Calibration Value</span><span class="detail-val" style="font-weight:bold;color:' + (cal >= 0 ? '#16a34a' : '#dc2626') + '">' + calStr + '</span></div>'
            + '<div class="detail-row"><span class="detail-lbl">Status</span><span class="detail-val" style="font-weight:bold;">' + (p.status || '—') + '</span></div>'
            + '<div class="detail-row"><span class="detail-lbl">Date Added</span><span class="detail-val">' + (p.created_at || '—') + '</span></div>';

        if (p.calibration_updated_at) {
            html += '<div style="margin-top:12px;border-top:1px solid #e2e8f0;padding-top:12px;">'
                + '<div class="detail-lbl" style="margin-bottom:4px;">Last Calibration Update</div>'
                + '<div class="detail-row"><span class="detail-lbl">Date Updated</span><span class="detail-val">' + p.calibration_updated_at + '</span></div>'
                + '<div class="detail-row"><span class="detail-lbl">Updated By</span><span class="detail-val">' + (p.updated_by_name || '—') + '</span></div>'
                + '<div class="detail-lbl" style="margin-top:8px;margin-bottom:4px;">Notes / Remarks</div>'
                + '<div style="font-size:11px;color:#475569;background:#f8fafc;border:1px solid #e2e8f0;padding:6px;border-radius:4px;font-style:italic;">'
                + (p.calibration_notes || 'No notes provided.') + '</div></div>';
        }

        document.getElementById('pumpDetailsContent').innerHTML = html;
        document.getElementById('viewPumpModal').classList.add('active');
    });
});

// â”€â”€ CALIBRATION â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
document.querySelectorAll('.btn-calibrate').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var id       = this.dataset.pumpId;
        var name     = this.dataset.pumpName;
        var prevVal  = parseFloat(this.dataset.calVal) || 0;
        var fuelType = this.dataset.fuelType;

        document.getElementById('cal_pump_id').value   = id;
        document.getElementById('cal_pump_name').value  = name;
        document.getElementById('cal_fuel_type').value  = fuelType;
        document.getElementById('cal_prev_val').value   = (prevVal >= 0 ? '+' : '') + prevVal.toFixed(3) + ' L';

        var form = document.getElementById('calibrationModal').querySelector('form');
        form.querySelector('input[name="calibration_value"]').value = '';
        form.querySelector('textarea[name="reason"]').value = '';

        document.getElementById('calibrationModal').classList.add('active');
    });
});

// â”€â”€ ACTIVATE / DEACTIVATE â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
document.querySelectorAll('.btn-status-submit').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var msg = this.dataset.confirm || 'Are you sure?';
        if (confirm(msg)) {
            this.closest('form').submit();
        }
    });
});

// â”€â”€ CALIBRATION HISTORY â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
document.querySelectorAll('.btn-view-history').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var fuelType = this.dataset.fuelType;
        var pumpName = this.dataset.pumpName;

        var logs = calibrationLogsAll.filter(function(l) {
            return l.fuel_type.toLowerCase().trim() === fuelType.toLowerCase().trim();
        });

        document.getElementById('historyModalTitle').textContent = 'Calibration Log â€“ Pump ' + pumpName + ' (' + fuelType + ')';

        var html = '';
        if (logs.length === 0) {
            html = '<tr><td colspan="6" style="text-align:center;padding:20px;color:#94a3b8;"><i class="fas fa-history"></i> No calibration history found for this pump.</td></tr>';
        } else {
            logs.forEach(function(l) {
                var prev = parseFloat(l.previous_calibration);
                var next = parseFloat(l.new_calibration);
                var diff = next - prev;
                var diffStr = (diff >= 0 ? '+' : '') + diff.toFixed(3) + ' L';
                var diffColor = diff > 0 ? '#16a34a' : (diff < 0 ? '#dc2626' : '#475569');
                var d = new Date(l.updated_at);
                var dateStr = d.toLocaleDateString(undefined, {month:'short', day:'numeric', year:'numeric'})
                            + ' ' + d.toLocaleTimeString(undefined, {hour:'2-digit', minute:'2-digit'});
                html += '<tr>'
                    + '<td>' + dateStr + '</td>'
                    + '<td class="align-right">' + prev.toFixed(3) + ' L</td>'
                    + '<td class="align-right">' + next.toFixed(3) + ' L</td>'
                    + '<td class="align-right" style="font-weight:bold;color:' + diffColor + '">' + diffStr + '</td>'
                    + '<td>' + (l.updater_name || '—') + '</td>'
                    + '<td>' + (l.reason || '—') + '</td>'
                    + '</tr>';
            });
        }

        document.getElementById('historyTableBody').innerHTML = html;
        document.getElementById('historyModal').classList.add('active');
    });
});


</script>

<?php
require_once __DIR__ . '/../partials/footer.php';
?>


