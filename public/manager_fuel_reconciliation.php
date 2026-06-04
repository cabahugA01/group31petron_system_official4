<?php
// ============================================================
// Manager Fuel Reconciliation – manager_fuel_reconciliation.php
// Purpose: Compare pump sales with tank levels and resolve variances
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'fuel_reconciliation';
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

// ── Date Filter ───────────────────────────────────────────
$date_from = trim($_GET['date_from'] ?? date('Y-m-d', strtotime('-6 months'))); // Default to 6 months ago
$date_to   = trim($_GET['date_to']   ?? date('Y-m-d'));  // Default to today

// ── POST Actions ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['export'])) {
    $action = trim($_POST['action'] ?? '');
    $variance_id = (int)($_POST['variance_id'] ?? 0);
    
    try {
        $pdo->beginTransaction();
        
        if ($action === 'resolve' && $variance_id > 0) {
            $resolution = trim($_POST['resolution'] ?? '');
            
            if (empty($resolution)) {
                throw new Exception("Resolution notes are required.");
            }
            
            // Update variance status
            $stmt = $pdo->prepare("UPDATE fuel_variance_reports 
                                   SET status = 'Resolved', 
                                       resolved_by = ?, 
                                       updated_at = NOW(),
                                       resolution_notes = ? 
                                   WHERE id = ? AND station_id = ?");
            $stmt->execute([$me['id'], $resolution, $variance_id, $station_id]);
            
            if ($stmt->rowCount() > 0) {
                // Log audit trail
                try {
                    $pdo->prepare("INSERT INTO audit_logs (user_id, action_type, entity_type, entity_id, details, station_id, ip_address, created_at) 
                                   VALUES (?, 'Resolve', 'fuel_variance', ?, ?, ?, ?, NOW())")
                        ->execute([
                            $me['id'], 
                            $variance_id, 
                            "Resolved variance. Resolution: {$resolution}", 
                            $station_id, 
                            $_SERVER['REMOTE_ADDR'] ?? ''
                        ]);
                } catch (Exception $ae) {}
                
                $_SESSION['success'] = "Variance #{$variance_id} resolved successfully.";
            } else {
                $_SESSION['error'] = "Variance not found or already resolved.";
            }
        }
        
        elseif ($action === 'investigate' && $variance_id > 0) {
            $notes = trim($_POST['notes'] ?? '');
            
            // Update variance to under investigation
            $stmt = $pdo->prepare("UPDATE fuel_variance_reports 
                                   SET status = 'Under Investigation', 
                                       updated_at = NOW(),
                                       resolution_notes = CONCAT(IFNULL(resolution_notes, ''), ' | Investigation: ', ?) 
                                   WHERE id = ? AND station_id = ?");
            $stmt->execute([$notes, $variance_id, $station_id]);
            
            if ($stmt->rowCount() > 0) {
                $_SESSION['success'] = "Variance #{$variance_id} marked as under investigation.";
            }
        }
        
        $pdo->commit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
    
    header('Location: manager_fuel_reconciliation.php');
    exit;
}

// ── Summary Cards ──────────────────────────────────────────
$reconciliations_completed = 0;
$variances_detected = 0;
$open_variances = 0;

try {
    // Count reconciliations completed (resolved variances)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM fuel_variance_reports 
                           WHERE station_id = ? 
                           AND LOWER(status) = 'resolved'
                           AND DATE(report_date) BETWEEN ? AND ?");
    $stmt->execute([$station_id, $date_from, $date_to]);
    $reconciliations_completed = (int)$stmt->fetchColumn();
    
    // Count total variances detected
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM fuel_variance_reports 
                           WHERE station_id = ? 
                           AND DATE(report_date) BETWEEN ? AND ?");
    $stmt->execute([$station_id, $date_from, $date_to]);
    $variances_detected = (int)$stmt->fetchColumn();
    
    // Count open variances
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM fuel_variance_reports 
                           WHERE station_id = ? 
                           AND LOWER(status) IN ('open', 'under investigation')
                           AND DATE(report_date) BETWEEN ? AND ?");
    $stmt->execute([$station_id, $date_from, $date_to]);
    $open_variances = (int)$stmt->fetchColumn();
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

// ── Fetch Variance Reports ────────────────────────────────────
$variances = [];
$total_records = 0;
try {
    // Get total count
    $count_sql = "SELECT COUNT(*) 
                  FROM fuel_variance_reports fvr
                  WHERE fvr.station_id = ?
                  AND DATE(fvr.report_date) BETWEEN ? AND ?";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute([$station_id, $date_from, $date_to]);
    $total_records = (int)$stmt->fetchColumn();
    
    // Get paginated results
    $sql = "SELECT fvr.*, 
                   u.name as resolved_by_name
            FROM fuel_variance_reports fvr
            LEFT JOIN users u ON fvr.resolved_by = u.id
            WHERE fvr.station_id = ?
            AND DATE(fvr.report_date) BETWEEN ? AND ?
            ORDER BY 
                CASE LOWER(TRIM(fvr.status))
                    WHEN 'open' THEN 1
                    WHEN 'under investigation' THEN 2
                    WHEN 'resolved' THEN 3
                    ELSE 4
                END ASC,
                fvr.report_date DESC
            LIMIT ? OFFSET ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(1, $station_id, PDO::PARAM_INT);
    $stmt->bindValue(2, $date_from, PDO::PARAM_STR);
    $stmt->bindValue(3, $date_to, PDO::PARAM_STR);
    $stmt->bindValue(4, $rows_per_page, PDO::PARAM_INT);
    $stmt->bindValue(5, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $variances = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Fetch variances error: " . $e->getMessage());
    $_SESSION['error'] = "Error loading variances: " . $e->getMessage();
}

$total_pages = ceil($total_records / $rows_per_page);

// ── Handle Export ──────────────────────────────────────────
$export = $_GET['export'] ?? '';
if (in_array($export, ['excel', 'csv', 'pdf'])) {
    try {
        $stmt = $pdo->prepare("SELECT fvr.*, 
                                      u.name as resolved_by_name
                               FROM fuel_variance_reports fvr
                               LEFT JOIN users u ON fvr.resolved_by = u.id
                               WHERE fvr.station_id = ?
                               AND DATE(fvr.report_date) BETWEEN ? AND ?
                               ORDER BY fvr.report_date DESC");
        $stmt->execute([$station_id, $date_from, $date_to]);
        $export_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($export === 'csv' || $export === 'excel') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="fuel_reconciliation_' . date('Y-m-d') . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Report ID', 'Date', 'Fuel Type', 'Expected (L)', 'Actual (L)', 'Variance (L)', 'Variance (%)', 'Status', 'Resolved By', 'Notes']);
            foreach ($export_data as $v) {
                fputcsv($out, [
                    $v['id'],
                    $v['report_date'],
                    $v['fuel_type'],
                    number_format($v['expected_stock'], 2),
                    number_format($v['actual_stock'], 2),
                    number_format($v['variance_liters'], 2),
                    number_format($v['variance_percent'], 2) . '%',
                    $v['status'],
                    $v['resolved_by_name'] ?? 'Pending',
                    $v['resolution_notes'] ?? ''
                ]);
            }
            fclose($out);
            exit;
        } elseif ($export === 'pdf') {
            header('Content-Type: text/html');
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Fuel Reconciliation Report</title>';
            echo '<style>body{font-family:Arial,sans-serif;margin:20px}h1{color:#002F70}table{border-collapse:collapse;width:100%;margin-top:20px}th,td{border:1px solid #ddd;padding:8px;text-align:left;font-size:11px}th{background:#002F70;color:#fff}tr:nth-child(even){background:#f9f9f9}.header-info{margin:15px 0;font-size:13px}.high{color:#dc3545;font-weight:700}.ok{color:#28a745}</style>';
            echo '</head><body>';
            echo '<h1>Fuel Reconciliation Report</h1>';
            echo '<div class="header-info"><strong>Station ID:</strong> ' . $station_id . ' | <strong>Period:</strong> ' . $date_from . ' to ' . $date_to . ' | <strong>Generated:</strong> ' . date('Y-m-d H:i:s') . '</div>';
            echo '<table><thead><tr><th>ID</th><th>Date</th><th>Fuel</th><th>Expected</th><th>Actual</th><th>Variance</th><th>%</th><th>Status</th><th>Resolved By</th></tr></thead><tbody>';
            foreach ($export_data as $v) {
                $cls = abs($v['variance_percent']) > 5 ? 'high' : 'ok';
                echo '<tr>';
                echo '<td>VAR-' . $v['id'] . '</td>';
                echo '<td>' . htmlspecialchars($v['report_date']) . '</td>';
                echo '<td>' . htmlspecialchars($v['fuel_type']) . '</td>';
                echo '<td>' . number_format($v['expected_stock'], 2) . ' L</td>';
                echo '<td>' . number_format($v['actual_stock'], 2) . ' L</td>';
                echo '<td class="' . $cls . '">' . number_format($v['variance_liters'], 2) . ' L</td>';
                echo '<td class="' . $cls . '">' . number_format($v['variance_percent'], 2) . '%</td>';
                echo '<td>' . htmlspecialchars($v['status']) . '</td>';
                echo '<td>' . htmlspecialchars($v['resolved_by_name'] ?? 'Pending') . '</td>';
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

/* Manager Fuel Reconciliation Styles */
.mfr-wrap { 
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
.summary-card.sc-red    { border-left: 4px solid #dc2626; }
.sum-ico {
    width: 52px; height: 52px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 24px; flex-shrink: 0;
}
.summary-card.sc-green .sum-ico  { background: #dcfce7; color: #16a34a; }
.summary-card.sc-amber .sum-ico  { background: #fef3c7; color: #d97706; }
.summary-card.sc-red .sum-ico    { background: #fee2e2; color: #dc2626; }
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
/* Column widths - adjusted to prevent cutoff */
.data-table th:nth-child(1), .data-table td:nth-child(1) { width: 4%; } /* ID */
.data-table th:nth-child(2), .data-table td:nth-child(2) { width: 8%; } /* Date */
.data-table th:nth-child(3), .data-table td:nth-child(3) { width: 8%; } /* Fuel Type */
.data-table th:nth-child(4), .data-table td:nth-child(4) { width: 8%; } /* Expected */
.data-table th:nth-child(5), .data-table td:nth-child(5) { width: 8%; } /* Actual */
.data-table th:nth-child(6), .data-table td:nth-child(6) { width: 8%; } /* Variance L */
.data-table th:nth-child(7), .data-table td:nth-child(7) { width: 8%; } /* Variance % */
.data-table th:nth-child(8), .data-table td:nth-child(8) { width: 9%; } /* Status */
.data-table th:nth-child(9), .data-table td:nth-child(9) { width: 11%; } /* Resolved By */
.data-table th:nth-child(10), .data-table td:nth-child(10) { width: 28%; } /* Actions - increased */
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
.badge-red { color: #dc2626; }
.badge-amber { color: #d97706; }
.badge-green { color: #16a34a; }

/* Variance display */
.var-high { color: #dc2626; font-weight: 700; }
.var-ok { color: #16a34a; font-weight: 700; }

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
.btn-resolve { background: #28a745; color: #fff; }
.btn-resolve:hover { background: #218838; }
.btn-investigate { background: #002F70; color: #fff; }
.btn-investigate:hover { background: #001a42; }

/* Actions cell layout */
.data-table tbody td:last-child {
    display: flex;
    flex-direction: column;
    gap: 3px;
    position: relative;
}

/* Modal */
.modal {
    display: none; position: fixed; z-index: 9999; left: 0; top: 0;
    width: 100%; height: 100%; background: rgba(0,0,0,.5);
    overflow-y: auto;
}
.modal-content {
    background: #fff; margin: 10% auto; padding: 24px;
    border-radius: 12px; width: 90%; max-width: 600px;
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

<div class="mfr-wrap">
    <!-- Page Header -->
    <div class="page-head">
        <div>
            <h1>Fuel Reconciliation</h1>
            <div class="sub">Pag-compare sa pump sales vs tank levels ug pag-resolve sa variances.</div>
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
                <h3>Reconciliations Completed</h3>
                <h2><?= number_format($reconciliations_completed) ?></h2>
                <span>Gi-resolve nga variances</span>
            </div>
        </div>
        <div class="summary-card sc-amber">
            <div class="sum-ico"><i class="fas fa-chart-line"></i></div>
            <div class="sum-meta">
                <h3>Variances Detected</h3>
                <h2><?= number_format($variances_detected) ?></h2>
                <span>Total nga naka-detect</span>
            </div>
        </div>
        <div class="summary-card sc-red">
            <div class="sum-ico"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="sum-meta">
                <h3>Open Variances</h3>
                <h2><?= number_format($open_variances) ?></h2>
                <span>Naghulat sa resolution</span>
            </div>
        </div>
    </div>

    <!-- Variance Reports Table -->
    <div class="table-card">
        <h3 style="margin:0 0 14px;font-size:14px;font-weight:700;color:#00264D;text-transform:uppercase;">
            <i class="fas fa-exclamation-triangle"></i> Variance Reports
        </h3>

        <?php if (empty($variances)): ?>
        <div style="text-align:center;padding:40px;color:#94a3b8;">
            <i class="fas fa-check-circle" style="font-size:48px;margin-bottom:12px;opacity:.5;color:#16a34a;"></i>
            <p style="margin:0;">Walay variances nga nakit-an. All fuel reconciliation balanced!</p>
        </div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Date</th>
                        <th>Fuel Type</th>
                        <th>Expected (L)</th>
                        <th>Actual (L)</th>
                        <th>Variance (L)</th>
                        <th>Variance (%)</th>
                        <th>Status</th>
                        <th>Resolved By</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($variances as $v): 
                        $var_pct = abs($v['variance_percent']);
                        $var_class = $var_pct > 5 ? 'var-high' : 'var-ok';
                        $status_lower = strtolower($v['status']);
                    ?>
                    <tr>
                        <td>VAR-<?= htmlspecialchars($v['id']) ?></td>
                        <td><?= date('M d, Y', strtotime($v['report_date'])) ?></td>
                        <td><?= htmlspecialchars($v['fuel_type']) ?></td>
                        <td><?= number_format($v['expected_stock'], 2) ?>L</td>
                        <td><?= number_format($v['actual_stock'], 2) ?>L</td>
                        <td class="<?= $var_class ?>"><?= number_format($v['variance_liters'], 2) ?>L</td>
                        <td class="<?= $var_class ?>"><?= number_format($v['variance_percent'], 2) ?>%</td>
                        <td>
                            <?php if ($status_lower === 'open'): ?>
                                <span class="badge badge-red">OPEN</span>
                            <?php elseif ($status_lower === 'under investigation'): ?>
                                <span class="badge badge-amber">INVESTIGATING</span>
                            <?php else: ?>
                                <span class="badge badge-green">RESOLVED</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($v['resolved_by_name'] ?? 'Pending') ?></td>
                        <td>
                            <?php if ($status_lower === 'open'): ?>
                                <button class="action-btn btn-resolve" onclick="resolveVariance(<?= $v['id'] ?>)">
                                    <i class="fas fa-check"></i> Resolve
                                </button>
                                <button class="action-btn btn-investigate" onclick="investigateVariance(<?= $v['id'] ?>)">
                                    <i class="fas fa-search"></i> Investigate
                                </button>
                            <?php elseif ($status_lower === 'under investigation'): ?>
                                <button class="action-btn btn-resolve" onclick="resolveVariance(<?= $v['id'] ?>)">
                                    <i class="fas fa-check"></i> Resolve
                                </button>
                            <?php else: ?>
                                <span style="color:#94a3b8;font-size:11px;">Resolved</span>
                            <?php endif; ?>
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

<!-- Resolve Modal -->
<div id="resolveModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Resolve Variance</h3>
            <span class="modal-close" onclick="closeModal('resolveModal')">&times;</span>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="resolve">
            <input type="hidden" name="variance_id" id="resolve_variance_id">
            <div class="form-group">
                <label>Resolution Notes <span style="color:#dc2626;">*</span></label>
                <textarea name="resolution" required placeholder="Explain how this variance was resolved and what corrective actions were taken..."></textarea>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" onclick="closeModal('resolveModal')"
                        style="background:#6c757d;color:#fff;padding:8px 16px;border-radius:6px;border:none;cursor:pointer;">
                    Cancel
                </button>
                <button type="submit"
                        style="background:#16a34a;color:#fff;padding:8px 16px;border-radius:6px;border:none;cursor:pointer;">
                    <i class="fas fa-check"></i> Resolve
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Investigate Modal -->
<div id="investigateModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Mark as Under Investigation</h3>
            <span class="modal-close" onclick="closeModal('investigateModal')">&times;</span>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="investigate">
            <input type="hidden" name="variance_id" id="investigate_variance_id">
            <div class="form-group">
                <label>Investigation Notes <span style="color:#dc2626;">*</span></label>
                <textarea name="notes" required placeholder="Describe what investigation steps are being taken..."></textarea>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" onclick="closeModal('investigateModal')"
                        style="background:#6c757d;color:#fff;padding:8px 16px;border-radius:6px;border:none;cursor:pointer;">
                    Cancel
                </button>
                <button type="submit"
                        style="background:#0891b2;color:#fff;padding:8px 16px;border-radius:6px;border:none;cursor:pointer;">
                    <i class="fas fa-search"></i> Mark Investigating
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

function resolveVariance(varId) {
    document.getElementById('resolve_variance_id').value = varId;
    document.getElementById('resolveModal').style.display = 'block';
}

function investigateVariance(varId) {
    document.getElementById('investigate_variance_id').value = varId;
    document.getElementById('investigateModal').style.display = 'block';
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
