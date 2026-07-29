<?php
// ============================================================
// Manager Fuel Adjustments Oversight – manager_fuel_adjustments.php
// Purpose: Consolidated history of Fuel Transaction Adjustments
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
if (!in_array($role, ['manager', 'supervisor', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Manager access required.';
    header('Location: staff_dashboard.php'); 
    exit;
}

if ($station_id <= 0) {
    $_SESSION['error'] = 'No station assigned.';
    header('Location: manager_dashboard.php'); 
    exit;
}

// Active Tab
$active_tab = 'transactions';

// ── GET Filters ──────────────────────────────────────────────
$date_from          = trim($_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days')));
$date_to            = trim($_GET['date_to']   ?? date('Y-m-d'));
$fuel_type_filter   = trim($_GET['fuel_type'] ?? 'all');
$adjusted_by_filter = trim($_GET['adjusted_by'] ?? 'all');
$export             = trim($_GET['export'] ?? '');

$shift_filter       = trim($_GET['shift'] ?? 'all');
$staff_filter       = trim($_GET['staff'] ?? '');
$search_tx          = trim($_GET['search_tx'] ?? '');

// Base conditions for active tab (includes all fuel/meter/calibration adjustments for station)
$where = [
    "fa.station_id = ?",
    "(LOWER(COALESCE(fa.adjustment_type, '')) NOT LIKE '%delivery%' OR fa.adjustment_type IS NULL)"
];
$params = [$station_id];

// Date Filter
$where[] = "DATE(fa.adjustment_date) BETWEEN ? AND ?";
$params[] = $date_from;
$params[] = $date_to;

// Fuel Type Filter
if ($fuel_type_filter !== 'all' && $fuel_type_filter !== '') {
    $where[] = "fa.fuel_type = ?";
    $params[] = $fuel_type_filter;
}

// Adjusted By Filter
if ($adjusted_by_filter !== 'all' && $adjusted_by_filter !== '') {
    $where[] = "fa.user_id = ?";
    $params[] = (int)$adjusted_by_filter;
}

// JSON & Text filters for Transactions
if ($shift_filter !== 'all' && $shift_filter !== '') {
    $where[] = "fa.notes LIKE ?";
    $params[] = '%"shift":"%' . $shift_filter . '%"%';
}
if ($staff_filter !== '') {
    $where[] = "fa.notes LIKE ?";
    $params[] = '%' . $staff_filter . '%';
}
if ($search_tx !== '') {
    $where[] = "(fa.notes LIKE ? OR fa.id LIKE ?)";
    $like_val = '%' . $search_tx . '%';
    $params[] = $like_val;
    $params[] = $like_val;
}

// Fetch Adjustments
$adjustments = [];
try {
    $sql = "SELECT fa.*, 
                   COALESCE(
                       NULLIF(CONCAT(TRIM(COALESCE(u.first_name, '')), ' ', TRIM(COALESCE(u.last_name, ''))), ' '),
                       u.username,
                       'Unknown'
                   ) as manager_name
            FROM fuel_adjustments fa
            LEFT JOIN users u ON fa.user_id = u.id
            WHERE " . implode(" AND ", $where) . "
            ORDER BY fa.adjustment_date DESC, fa.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $adjustments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Fetch adjustments error: " . $e->getMessage());
}

// Compute Summary Metrics
$total_count = 0;
$today_count = 0;
$month_count = 0;
$last_adj_str = '—';

try {
    // 1. Total
    $sp = $pdo->prepare("SELECT COUNT(*) FROM fuel_adjustments WHERE station_id = ? AND (LOWER(COALESCE(adjustment_type, '')) NOT LIKE '%delivery%' OR adjustment_type IS NULL)");
    $sp->execute([$station_id]);
    $total_count = (int)$sp->fetchColumn();

    // 2. Today
    $sp2 = $pdo->prepare("SELECT COUNT(*) FROM fuel_adjustments WHERE station_id = ? AND (LOWER(COALESCE(adjustment_type, '')) NOT LIKE '%delivery%' OR adjustment_type IS NULL) AND DATE(adjustment_date) = CURDATE()");
    $sp2->execute([$station_id]);
    $today_count = (int)$sp2->fetchColumn();

    // 3. This Month
    $sp3 = $pdo->prepare("SELECT COUNT(*) FROM fuel_adjustments WHERE station_id = ? AND (LOWER(COALESCE(adjustment_type, '')) NOT LIKE '%delivery%' OR adjustment_type IS NULL) AND MONTH(adjustment_date) = MONTH(CURDATE()) AND YEAR(adjustment_date) = YEAR(CURDATE())");
    $sp3->execute([$station_id]);
    $month_count = (int)$sp3->fetchColumn();

    // 4. Last Adjustment
    $sp4 = $pdo->prepare("SELECT created_at FROM fuel_adjustments WHERE station_id = ? AND (LOWER(COALESCE(adjustment_type, '')) NOT LIKE '%delivery%' OR adjustment_type IS NULL) ORDER BY id DESC LIMIT 1");
    $sp4->execute([$station_id]);
    $last_adj = $sp4->fetchColumn();
    if ($last_adj) {
        $last_adj_str = date('M d, Y h:i A', strtotime($last_adj));
    }
} catch (Exception $e) {}

$managers = [];
try {
    $mgr_stmt = $pdo->prepare("
        SELECT DISTINCT u.id, 
               COALESCE(NULLIF(CONCAT(TRIM(u.first_name), ' ', TRIM(u.last_name)), ' '), u.username, 'Unknown') as name
        FROM users u 
        JOIN fuel_adjustments fa ON fa.user_id = u.id 
        WHERE fa.station_id = ?
        ORDER BY name
    ");
    $mgr_stmt->execute([$station_id]);
    $managers = $mgr_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Fuel Types List for filter
$fuel_types = [];
try {
    $ft_stmt = $pdo->prepare("SELECT DISTINCT fuel_type FROM fuel_inventory WHERE station_id=? AND fuel_type IS NOT NULL AND fuel_type!='' ORDER BY fuel_type");
    $ft_stmt->execute([$station_id]);
    $fuel_types = $ft_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}
if (empty($shifts)) {
    $shifts = ['First Shift', 'Second Shift', 'Third Shift'];
}

// Petron station fuel deliveries use Petron Corporation as the sole supplier.
$suppliers = ['Petron Corporation'];

// ── EXPORTS ──────────────────────────────────────────────────
if (in_array($export, ['excel', 'pdf'])) {
    $filename = ($active_tab === 'transactions' ? 'fuel_transaction_adjustments_' : 'fuel_delivery_adjustments_') . $date_from . '_to_' . $date_to;
    
    if ($active_tab === 'transactions') {
        $headers = ['Adjustment ID', 'Transaction No.', 'Fuel Line', 'Fuel Type', 'Shift', 'Staff', 'Previous Calibration', 'New Calibration', 'Difference', 'Reason', 'Adjusted By', 'Date & Time'];
        $rows_fmt = [];
        foreach ($adjustments as $adj) {
            $notes_data = json_decode($adj['notes'], true) ?: [];
            $prev_cal = $notes_data['prev_calibration'] ?? 0;
            $new_cal = $notes_data['new_calibration'] ?? 0;
            $diff = $new_cal - $prev_cal;
            
            $rows_fmt[] = [
                'ADJ-' . $adj['id'],
                $notes_data['transaction_id'] ?? '—',
                $notes_data['fuel_line'] ?? '—',
                $adj['fuel_type'],
                $notes_data['shift'] ?? '—',
                $notes_data['staff_name'] ?? '—',
                number_format($prev_cal, 2),
                number_format($new_cal, 2),
                ($diff >= 0 ? '+' : '') . number_format($diff, 2),
                $adj['reason'] ?? '—',
                $adj['manager_name'],
                date('M d, Y h:i A', strtotime($adj['created_at']))
            ];
        }
    } else {
        $headers = ['Adjustment ID', 'Delivery No.', 'Supplier', 'Fuel Type', 'Previous Quantity', 'New Quantity', 'Difference', 'Reason', 'Adjusted By', 'Date & Time'];
        $rows_fmt = [];
        foreach ($adjustments as $adj) {
            $notes_data = json_decode($adj['notes'], true) ?: [];
            $prev_lit = $notes_data['prev_liters'] ?? 0;
            $new_lit = $notes_data['new_liters'] ?? 0;
            $diff = $new_lit - $prev_lit;
            
            $rows_fmt[] = [
                'ADJ-' . $adj['id'],
                'DEL-' . ($notes_data['delivery_id'] ?? '—'),
                'Petron Corporation',
                $adj['fuel_type'],
                number_format($prev_lit, 2) . ' L',
                number_format($new_lit, 2) . ' L',
                ($diff >= 0 ? '+' : '') . number_format($diff, 2) . ' L',
                $adj['reason'] ?? '—',
                $adj['manager_name'],
                date('M d, Y h:i A', strtotime($adj['created_at']))
            ];
        }
    }

    if ($export === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="UTF-8"><style>table{border-collapse:collapse}th,td{border:1px solid #ddd;padding:7px}th{background:#002F6C;color:#fff;font-size:11px}</style></head><body>';
        echo '<h2>Fuel Transaction Adjustments Report</h2>';
        echo '<p>Period: ' . $date_from . ' to ' . $date_to . ' | Records: ' . count($rows_fmt) . '</p>';
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
        foreach ($rows_fmt as $r) {
            $tbody .= '<tr>';
            foreach ($r as $c) {
                $tbody .= '<td>' . htmlspecialchars($c) . '</td>';
            }
            $tbody .= '</tr>';
        }

        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Adjustments Report</title>
        <style>body{font-family:Arial,sans-serif;font-size:10px;padding:20px;color:#333;}
        .pbtn{margin-bottom:12px}@media print{.pbtn{display:none}}
        .hdr{border-bottom:3px solid #002F6C;margin-bottom:14px;padding-bottom:8px;display:flex;align-items:center;justify-content:between;}
        h1{color:#002F6C;font-size:16px;margin:0 0 4px;text-transform:uppercase;}
        table{width:100%;border-collapse:collapse;margin-top:10px;}
        th{background:#002F6C;color:#fff;padding:6px;font-size:8px;text-transform:uppercase;text-align:left;}
        td{padding:5px;border-bottom:1px solid #e2e8f0;font-size:8px;}
        tr:nth-child(even) td{background:#f8fafc}
        </style></head><body>';
        echo '<div class="pbtn"><button onclick="window.print()" style="background:#002F6C;color:#fff;border:none;padding:8px 18px;border-radius:5px;cursor:pointer;font-weight:bold;">Print</button>
        <a href="javascript:history.back()" style="margin-left:8px;background:#6c757d;color:#fff;border:none;padding:8px 18px;border-radius:5px;cursor:pointer;text-decoration:none;font-weight:bold;">← Back</a></div>';
        echo '<div class="hdr"><div><h1>Fuel Transaction Adjustments</h1><p style="margin:2px 0 0;color:#666;">Period: ' . htmlspecialchars($date_from) . ' — ' . htmlspecialchars($date_to) . ' | Station: ' . htmlspecialchars(user_station_name()) . '</p></div><div style="text-align:right;"><p style="margin:0;">Generated: ' . $generated . '</p></div></div>';
        echo '<table><thead><tr>';
        foreach ($headers as $h) echo '<th>' . htmlspecialchars($h) . '</th>';
        echo '</tr></thead>';
        echo '<tbody>' . ($tbody ?: '<tr><td colspan="' . count($headers) . '" style="text-align:center;padding:20px;color:#94a3b8">No records found.</td></tr>') . '</tbody></table>';
        echo '</body></html>'; exit;
    }
}

require_once __DIR__ . '/../partials/header.php'; require_once __DIR__ . '/../partials/flash_toast.php';
?>

<style>
/* Core layout styles */
* { box-sizing: border-box; }
.mfa-wrap { max-width: 100%; width: 100%; box-sizing: border-box; overflow-x: hidden !important; padding: 0 12px; }
.main-content { max-width: 100% !important; overflow-x: hidden !important; padding: 0 !important; }

/* Petron clean headers */
.int-head { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; margin-top: 0 !important; padding-top: 16px; padding-bottom: 16px; border-bottom: 2px solid #e9ecef; width: 100%; }
.int-head h1 { font-size: 22px !important; font-weight: 700 !important; color: #00264D !important; margin: 0 !important; text-transform: uppercase !important; display: flex; align-items: center; gap: 8px; line-height: 1.3; }
.int-head .sub { font-size: 13px; color: #64748b; margin-top: 4px; line-height: 1.4; }

/* Modal close button overrides to prevent global button background override */
.modal-header button {
    background: none !important;
    background-color: transparent !important;
    border: none !important;
    box-shadow: none !important;
}


/* Tabs system */
.tabs-navigation { display: flex; gap: 10px; border-bottom: 2px solid #e2e8f0; margin-bottom: 22px; width: 100%; }
.tab-btn { display: flex; align-items: center; gap: 8px; padding: 12px 20px; font-size: 13.5px; font-weight: 600; color: #64748b; text-decoration: none; border-bottom: 2px solid transparent; margin-bottom: -2px; transition: all 0.2s ease; }
.tab-btn:hover { color: #002F70; }
.tab-btn.active { color: #002F70; border-bottom-color: #002F70; font-weight: 700; }

/* Standard buttons */
.ato-btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 0 16px; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; border: 1px solid transparent; text-decoration: none; transition: all .15s; height: 36px; white-space: nowrap; background: white !important; }
.ato-btn-excel { color: #1d6f42 !important; border-color: #1d6f42 !important; }
.ato-btn-excel:hover { background: #1d6f42 !important; color: #fff !important; }
.ato-btn-pdf { color: #dc2626 !important; border-color: #dc2626 !important; }
.ato-btn-pdf:hover { background: #dc2626 !important; color: #fff !important; }
.ato-btn-print { color: #334155 !important; border-color: #64748b !important; }
.ato-btn-print:hover { background: #64748b !important; color: #fff !important; }
.ato-btn-back { color: #4b5563 !important; border-color: #cbd5e1 !important; }
.ato-btn-back:hover { background: #cbd5e1 !important; }
.ato-btn-filter { color: #002F70 !important; border-color: #002F70 !important; }
.ato-btn-filter:hover { background: #002F70 !important; color: #fff !important; }
.ato-btn-reset { color: #475569 !important; border-color: #cbd5e1 !important; }
.ato-btn-reset:hover { background: #f1f5f9 !important; }

/* Summary Cards */
.afto-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
.afto-card { background: #ffffff; border: 1px solid #cbd5e1; border-radius: 10px; padding: 16px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 1px 3px rgba(0,0,0,.05); }
.afto-card-info { display: flex; flex-direction: column; }
.afto-card-lbl { font-size: 10.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
.afto-card-val { font-size: 19px; font-weight: 700; color: #1e293b; }
.afto-card-icon { font-size: 22px; opacity: 0.85; }
.afto-card.blue .afto-card-icon { color: #0ea5e9; }
.afto-card.green .afto-card-icon { color: #10b981; }
.afto-card.yellow .afto-card-icon { color: #f59e0b; }
.afto-card.purple .afto-card-icon { color: #8b5cf6; }

/* Filters */
.afto-filter { display: flex; align-items: flex-end; gap: 10px; flex-wrap: wrap; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 16px; margin-bottom: 20px; }
.afto-fg { display: flex; flex-direction: column; gap: 3px; }
.afto-fg label { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .4px; }
.afto-fg input, .afto-fg select { height: 36px; padding: 0 10px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 13px; color: #1e293b; background: #fff; outline: none; }
.afto-fg input:focus, .afto-fg select:focus { border-color: #002F70; box-shadow: 0 0 0 3px rgba(0,47,112,.1); }

/* Table styles */
.afto-table-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 11px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.04); width: 100%; }
.afto-table-hd { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid #f1f5f9; }
.afto-table-title { font-size: 13px; font-weight: 700; color: #00264D; text-transform: uppercase; letter-spacing: .3px; margin: 0; }
.afto-tbl-wrap { width: 100%; overflow-x: auto; }
.afto-tbl { width: 100%; border-collapse: collapse; font-size: 11px; }
.afto-tbl thead tr { background: #002F70; }
.afto-tbl thead th { padding: 9px 10px; text-align: left; font-size: 10px; font-weight: 700; color: #fff; text-transform: uppercase; letter-spacing: .3px; white-space: nowrap; }
.afto-tbl tbody tr { border-bottom: 1px solid #f1f5f9; }
.afto-tbl tbody tr:hover td { background: #eff6ff; }
.afto-tbl tbody td { padding: 9px 10px; color: #334155; vertical-align: middle; background: #fff; }

.row-btn { display: inline-flex; align-items: center; justify-content: center; gap: 4px; padding: 0 8px; border-radius: 5px; font-size: 10px; font-weight: 700; border: 1px solid #002F70; cursor: pointer; height: 24px; background: white !important; color: #002F70 !important; text-decoration: none; text-transform: uppercase; }
.row-btn:hover { background: #002F70 !important; color: white !important; }

/* Modal */
.modal { display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(15,23,42,0.55); backdrop-filter: blur(4px); align-items: center; justify-content: center; }
.modal-content { background: #fff; border-radius: 12px; width: 90%; max-width: 550px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); overflow: hidden; animation: modalIn 0.2s ease; }
@keyframes modalIn { from { opacity: 0; transform: translateY(-12px); } to { opacity: 1; transform: none; } }
.modal-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
.modal-header h3 { margin: 0; font-size: 14px; color: #00264D; font-weight: 700; text-transform: uppercase; }
.modal-body { padding: 20px; }
.modal-footer { display: flex; gap: 8px; justify-content: flex-end; padding: 12px 20px; border-top: 1px solid #e2e8f0; background: #f8fafc; }

.details-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 20px; }
.details-item { border-bottom: 1px solid #f1f5f9; padding-bottom: 6px; }
.details-item.full-width { grid-column: span 2; }
.details-lbl { font-size: 10px; color: #64748b; text-transform: uppercase; font-weight: bold; }
.details-val { font-size: 12px; color: #1e293b; font-weight: 600; margin-top: 2px; }

/* Badges */
.badge-diff { font-weight: bold; }
.badge-diff.plus { color: #16a34a; }
.badge-diff.minus { color: #dc2626; }
.badge-diff.zero { color: #64748b; }
</style>

<div class="mfa-wrap">
    <!-- Page Header -->
    <div class="int-head">
        <div>
            <h1><i class="fas fa-sliders-h"></i> Fuel Transaction Adjustment History</h1>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="afto-cards">
        <div class="afto-card blue">
            <div class="afto-card-info">
                <span class="afto-card-lbl">Total Adjustments</span>
                <span class="afto-card-val"><?= number_format($total_count) ?></span>
            </div>
            <div class="afto-card-icon"><i class="fas fa-database"></i></div>
        </div>
        <div class="afto-card green">
            <div class="afto-card-info">
                <span class="afto-card-lbl">Today's Adjustments</span>
                <span class="afto-card-val"><?= number_format($today_count) ?></span>
            </div>
            <div class="afto-card-icon"><i class="fas fa-calendar-day"></i></div>
        </div>
        <div class="afto-card yellow">
            <div class="afto-card-info">
                <span class="afto-card-lbl">This Month's Adjustments</span>
                <span class="afto-card-val"><?= number_format($month_count) ?></span>
            </div>
            <div class="afto-card-icon"><i class="fas fa-calendar-alt"></i></div>
        </div>
        <div class="afto-card purple">
            <div class="afto-card-info">
                <span class="afto-card-lbl">Last Adjustment</span>
                <span class="afto-card-val" style="font-size:12.5px;"><?= $last_adj_str ?></span>
            </div>
            <div class="afto-card-icon"><i class="fas fa-clock"></i></div>
        </div>
    </div>

    <!-- Filters Form -->
    <form method="get" class="afto-filter">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
        
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
                <option value="all">All Fuel Types</option>
                <?php foreach ($fuel_types as $ft): ?>
                    <option value="<?= htmlspecialchars($ft) ?>" <?= $fuel_type_filter === $ft ? 'selected' : '' ?>><?= htmlspecialchars($ft) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if ($active_tab === 'transactions'): ?>
            <div class="afto-fg">
                <label>Shift</label>
                <select name="shift">
                    <option value="all">All Shifts</option>
                    <?php foreach ($shifts as $sh): ?>
                        <option value="<?= htmlspecialchars($sh) ?>" <?= $shift_filter === $sh ? 'selected' : '' ?>><?= htmlspecialchars($sh) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="afto-fg">
                <label>Staff</label>
                <input type="text" name="staff" value="<?= htmlspecialchars($staff_filter) ?>" placeholder="Staff name...">
            </div>
        <?php else: ?>
            <div class="afto-fg">
                <label>Supplier</label>
                <select name="supplier">
                    <option value="all">All Suppliers</option>
                    <?php foreach ($suppliers as $sup): ?>
                        <option value="<?= htmlspecialchars($sup) ?>" <?= $supplier_filter === $sup ? 'selected' : '' ?>><?= htmlspecialchars($sup) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <div class="afto-fg">
            <label>Adjusted By</label>
            <select name="adjusted_by">
                <option value="all">All Managers</option>
                <?php foreach ($managers as $m): ?>
                    <option value="<?= $m['id'] ?>" <?= $adjusted_by_filter == $m['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if ($active_tab === 'transactions'): ?>
            <div class="afto-fg">
                <label>Search Trans No.</label>
                <input type="text" name="search_tx" value="<?= htmlspecialchars($search_tx) ?>" placeholder="FUEL2026...">
            </div>
        <?php else: ?>
            <div class="afto-fg">
                <label>Search Delivery No.</label>
                <input type="text" name="search_del" value="<?= htmlspecialchars($search_del) ?>" placeholder="DEL-...">
            </div>
        <?php endif; ?>

        <div style="display: flex; gap: 8px;">
            <button type="submit" class="ato-btn ato-btn-filter"><i class="fas fa-search"></i> Filter</button>
            <a href="?tab=<?= $active_tab ?>" class="ato-btn ato-btn-reset"><i class="fas fa-rotate-left"></i> Reset</a>
        </div>
    </form>

    <!-- Table Card -->
    <div class="afto-table-card">
        <div class="afto-table-hd">
            <h3 class="afto-table-title">
                <i class="fas fa-table"></i> 
                <?= $active_tab === 'transactions' ? 'Fuel Transaction Adjustment Records' : 'Fuel Delivery Adjustment Records' ?>
            </h3>
        </div>
        <div class="afto-tbl-wrap">
            <table class="afto-tbl">
                <?php if ($active_tab === 'transactions'): ?>
                    <thead>
                        <tr>
                            <th>Adjustment ID</th>
                            <th>Transaction No.</th>
                            <th>Fuel Line</th>
                            <th>Fuel Type</th>
                            <th>Shift</th>
                            <th>Staff</th>
                            <th style="text-align:right;">Prev Calibration</th>
                            <th style="text-align:right;">New Calibration</th>
                            <th>Reason</th>
                            <th>Adjusted By</th>
                            <th>Date & Time</th>
                            <th style="text-align:center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($adjustments)): ?>
                            <tr><td colspan="12" style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-inbox" style="font-size:24px;display:block;margin-bottom:8px;"></i>No transaction adjustments found</td></tr>
                        <?php else: ?>
                            <?php foreach ($adjustments as $adj): 
                                $notes_data = json_decode($adj['notes'], true) ?: [];
                                $prev_cal = $notes_data['prev_calibration'] ?? 0;
                                $new_cal = $notes_data['new_calibration'] ?? 0;
                            ?>
                                <tr>
                                    <td><strong>ADJ-<?= $adj['id'] ?></strong></td>
                                    <td><?= htmlspecialchars($notes_data['transaction_id'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($notes_data['fuel_line'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($adj['fuel_type']) ?></td>
                                    <td><?= htmlspecialchars($notes_data['shift'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($notes_data['staff_name'] ?? '—') ?></td>
                                    <td style="text-align:right;"><?= number_format($prev_cal, 2) ?></td>
                                    <td style="text-align:right;"><?= number_format($new_cal, 2) ?></td>
                                    <td><?= htmlspecialchars($adj['reason'] ?: '—') ?></td>
                                    <td><?= htmlspecialchars($adj['manager_name']) ?></td>
                                    <td><?= date('M d, Y h:i A', strtotime($adj['created_at'])) ?></td>
                                    <td style="text-align:center;">
                                        <button class="row-btn" onclick="viewTxDetails(<?= htmlspecialchars(json_encode([
                                            'adj_id' => 'ADJ-' . $adj['id'],
                                            'transaction_id' => $notes_data['transaction_id'] ?? '—',
                                            'fuel_line' => $notes_data['fuel_line'] ?? '—',
                                            'fuel_type' => $adj['fuel_type'],
                                            'prev_beginning' => number_format($notes_data['prev_beginning'] ?? 0, 2),
                                            'prev_ending' => number_format($notes_data['prev_ending'] ?? 0, 2),
                                            'prev_calibration' => number_format($prev_cal, 2),
                                            'new_beginning' => number_format($notes_data['new_beginning'] ?? 0, 2),
                                            'new_ending' => number_format($notes_data['new_ending'] ?? 0, 2),
                                            'new_calibration' => number_format($new_cal, 2),
                                            'diff' => number_format($new_cal - $prev_cal, 2),
                                            'reason' => $adj['reason'] ?: '—',
                                            'staff_name' => $notes_data['staff_name'] ?? '—',
                                            'manager_name' => $adj['manager_name'],
                                            'date_time' => date('M d, Y h:i A', strtotime($adj['created_at']))
                                        ])) ?>)"><i class="fas fa-eye"></i> View</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                <?php else: ?>
                    <thead>
                        <tr>
                            <th>Adjustment ID</th>
                            <th>Delivery No.</th>
                            <th>Supplier</th>
                            <th>Fuel Type</th>
                            <th style="text-align:right;">Previous Quantity</th>
                            <th style="text-align:right;">New Quantity</th>
                            <th style="text-align:right;">Difference</th>
                            <th>Reason</th>
                            <th>Adjusted By</th>
                            <th>Date & Time</th>
                            <th style="text-align:center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($adjustments)): ?>
                            <tr><td colspan="11" style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-inbox" style="font-size:24px;display:block;margin-bottom:8px;"></i>No delivery adjustments found</td></tr>
                        <?php else: ?>
                            <?php foreach ($adjustments as $adj): 
                                $notes_data = json_decode($adj['notes'], true) ?: [];
                                $prev_lit = $notes_data['prev_liters'] ?? 0;
                                $new_lit = $notes_data['new_liters'] ?? 0;
                                $diff = $new_lit - $prev_lit;
                            ?>
                                <tr>
                                    <td><strong>ADJ-<?= $adj['id'] ?></strong></td>
                                    <td><strong>DEL-<?= htmlspecialchars($notes_data['delivery_id'] ?? '—') ?></strong></td>
                                    <td><?= htmlspecialchars('Petron Corporation') ?></td>
                                    <td><?= htmlspecialchars($adj['fuel_type']) ?></td>
                                    <td style="text-align:right;"><?= number_format($prev_lit, 2) ?> L</td>
                                    <td style="text-align:right;"><?= number_format($new_lit, 2) ?> L</td>
                                    <td style="text-align:right;" class="badge-diff <?= $diff >= 0 ? 'plus' : 'minus' ?>">
                                        <?= ($diff >= 0 ? '+' : '') . number_format($diff, 2) ?> L
                                    </td>
                                    <td><?= htmlspecialchars($adj['reason'] ?: '—') ?></td>
                                    <td><?= htmlspecialchars($adj['manager_name']) ?></td>
                                    <td><?= date('M d, Y h:i A', strtotime($adj['created_at'])) ?></td>
                                    <td style="text-align:center;">
                                        <button class="row-btn" onclick="viewDelDetails(<?= htmlspecialchars(json_encode([
                                            'adj_id' => 'ADJ-' . $adj['id'],
                                            'delivery_id' => 'DEL-' . ($notes_data['delivery_id'] ?? '—'),
                                            'supplier' => 'Petron Corporation',
                                            'fuel_type' => $adj['fuel_type'],
                                            'invoice_no' => $notes_data['invoice_no'] ?? '—',
                                            'prev_liters' => number_format($prev_lit, 2) . ' L',
                                            'new_liters' => number_format($new_lit, 2) . ' L',
                                            'diff' => ($diff >= 0 ? '+' : '') . number_format($diff, 2) . ' L',
                                            'reason' => $adj['reason'] ?: '—',
                                            'manager_name' => $adj['manager_name'],
                                            'date_time' => date('M d, Y h:i A', strtotime($adj['created_at']))
                                        ])) ?>)"><i class="fas fa-eye"></i> View</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<!-- Transaction Details Modal -->
<div id="txModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Transaction Adjustment Details</h3>
            <button onclick="closeModal('txModal')" style="border:none;background:none;font-size:20px;cursor:pointer;color:#64748b;">&times;</button>
        </div>
        <div class="modal-body">
            <div class="details-grid">
                <div class="details-item">
                    <div class="details-lbl">Transaction Number</div>
                    <div class="details-val" id="tx_val_txn_no"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Fuel Line</div>
                    <div class="details-val" id="tx_val_fuel_line"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Fuel Type</div>
                    <div class="details-val" id="tx_val_fuel_type"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Staff Name</div>
                    <div class="details-val" id="tx_val_staff"></div>
                </div>
                
                <div class="details-item">
                    <div class="details-lbl">Previous Beginning Reading</div>
                    <div class="details-val" id="tx_val_prev_beg"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">New Beginning Reading</div>
                    <div class="details-val" id="tx_val_new_beg"></div>
                </div>

                <div class="details-item">
                    <div class="details-lbl">Previous Ending Reading</div>
                    <div class="details-val" id="tx_val_prev_end"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">New Ending Reading</div>
                    <div class="details-val" id="tx_val_new_end"></div>
                </div>

                <div class="details-item">
                    <div class="details-lbl">Previous Calibration</div>
                    <div class="details-val" id="tx_val_prev_cal"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">New Calibration</div>
                    <div class="details-val" id="tx_val_new_cal"></div>
                </div>

                <div class="details-item">
                    <div class="details-lbl">Calibration Difference</div>
                    <div class="details-val" id="tx_val_diff" style="font-weight:700;"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Adjusted By</div>
                    <div class="details-val" id="tx_val_manager"></div>
                </div>

                <div class="details-item full-width">
                    <div class="details-lbl">Adjustment Reason</div>
                    <div class="details-val" id="tx_val_reason" style="white-space:pre-wrap;font-weight:normal;color:#475569;"></div>
                </div>
                <div class="details-item full-width">
                    <div class="details-lbl">Date & Time Adjusted</div>
                    <div class="details-val" id="tx_val_date"></div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="ato-btn ato-btn-back" onclick="closeModal('txModal')">Close</button>
        </div>
    </div>
</div>

<!-- Delivery Details Modal -->
<div id="delModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Delivery Adjustment Details</h3>
            <button onclick="closeModal('delModal')" style="border:none;background:none;font-size:20px;cursor:pointer;color:#64748b;">&times;</button>
        </div>
        <div class="modal-body">
            <div class="details-grid">
                <div class="details-item">
                    <div class="details-lbl">Delivery Number</div>
                    <div class="details-val" id="del_val_no"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Supplier</div>
                    <div class="details-val" id="del_val_supplier"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Fuel Type</div>
                    <div class="details-val" id="del_val_fuel"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Purchase Order / DR No.</div>
                    <div class="details-val" id="del_val_invoice"></div>
                </div>
                
                <div class="details-item">
                    <div class="details-lbl">Previous Quantity</div>
                    <div class="details-val" id="del_val_prev_qty"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">New Quantity</div>
                    <div class="details-val" id="del_val_new_qty"></div>
                </div>
                
                <div class="details-item">
                    <div class="details-lbl">Difference</div>
                    <div class="details-val" id="del_val_diff" style="font-weight:700;"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Adjusted By</div>
                    <div class="details-val" id="del_val_manager"></div>
                </div>
                
                <div class="details-item full-width">
                    <div class="details-lbl">Manager Remarks / Reason</div>
                    <div class="details-val" id="del_val_reason" style="white-space:pre-wrap;font-weight:normal;color:#475569;"></div>
                </div>
                <div class="details-item full-width">
                    <div class="details-lbl">Date & Time Adjusted</div>
                    <div class="details-val" id="del_val_date"></div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="ato-btn ato-btn-back" onclick="closeModal('delModal')">Close</button>
        </div>
    </div>
</div>

<script>
function viewTxDetails(d) {
    document.getElementById('tx_val_txn_no').textContent = d.transaction_id;
    document.getElementById('tx_val_fuel_line').textContent = d.fuel_line;
    document.getElementById('tx_val_fuel_type').textContent = d.fuel_type;
    document.getElementById('tx_val_staff').textContent = d.staff_name;
    document.getElementById('tx_val_prev_beg').textContent = d.prev_beginning;
    document.getElementById('tx_val_new_beg').textContent = d.new_beginning;
    document.getElementById('tx_val_prev_end').textContent = d.prev_ending;
    document.getElementById('tx_val_new_end').textContent = d.new_ending;
    document.getElementById('tx_val_prev_cal').textContent = d.prev_calibration;
    document.getElementById('tx_val_new_cal').textContent = d.new_calibration;
    
    var diffVal = parseFloat(d.diff);
    var diffEl = document.getElementById('tx_val_diff');
    diffEl.textContent = (diffVal >= 0 ? '+' : '') + d.diff;
    diffEl.className = 'details-val badge-diff ' + (diffVal > 0 ? 'plus' : (diffVal < 0 ? 'minus' : 'zero'));
    
    document.getElementById('tx_val_manager').textContent = d.manager_name;
    document.getElementById('tx_val_reason').textContent = d.reason;
    document.getElementById('tx_val_date').textContent = d.date_time;
    
    document.getElementById('txModal').style.display = 'flex';
}

function viewDelDetails(d) {
    document.getElementById('del_val_no').textContent = d.delivery_id;
    document.getElementById('del_val_supplier').textContent = d.supplier;
    document.getElementById('del_val_fuel').textContent = d.fuel_type;
    document.getElementById('del_val_invoice').textContent = d.invoice_no;
    document.getElementById('del_val_prev_qty').textContent = d.prev_liters;
    document.getElementById('del_val_new_qty').textContent = d.new_liters;
    
    var diffVal = parseFloat(d.diff.replace(/[^0-9.\-]/g,''));
    var diffEl = document.getElementById('del_val_diff');
    diffEl.textContent = d.diff;
    diffEl.className = 'details-val badge-diff ' + (diffVal > 0 ? 'plus' : (diffVal < 0 ? 'minus' : 'zero'));
    
    document.getElementById('del_val_manager').textContent = d.manager_name;
    document.getElementById('del_val_reason').textContent = d.reason;
    document.getElementById('del_val_date').textContent = d.date_time;
    
    document.getElementById('delModal').style.display = 'flex';
}

function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}

// Close modals when clicking outside content
window.onclick = function(event) {
    if (event.target.className === 'modal') {
        event.target.style.display = 'none';
    }
}
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
