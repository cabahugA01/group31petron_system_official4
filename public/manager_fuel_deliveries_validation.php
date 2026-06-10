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

// ── POST Actions (Batch-level) ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['export'])) {
    $action   = trim($_POST['action']   ?? '');
    $batch_id = trim($_POST['batch_id'] ?? '');

    if (empty($batch_id)) {
        $_SESSION['error'] = 'No Batch ID provided.';
        header('Location: manager_fuel_deliveries_validation.php'); exit;
    }

    try {
        $pdo->beginTransaction();

        // Load all entries in this batch
        $es = $pdo->prepare("SELECT * FROM fuel_deliveries WHERE batch_id = ? AND station_id = ?");
        $es->execute([$batch_id, $station_id]);
        $batch_entries = $es->fetchAll(PDO::FETCH_ASSOC);

        if (empty($batch_entries)) {
            throw new Exception("Batch not found.");
        }

        // Guard: must still be pending (check first entry only)
        $first_status = strtolower($batch_entries[0]['status'] ?? '');
        if (!in_array($first_status, ['pending','pending validation','pending manager approval','pending manager validation'])) {
            throw new Exception("Batch {$batch_id} has already been processed.");
        }

        if ($action === 'approve_batch') {
            foreach ($batch_entries as $e) {
                $pdo->prepare("UPDATE fuel_deliveries SET status='Verified', verified_by=?, verified_at=NOW() WHERE id=? AND station_id=?")
                    ->execute([$me['id'], $e['id'], $station_id]);
                $pdo->prepare("UPDATE fuel_inventory SET current_level=COALESCE(current_level,0)+?, current_stock=COALESCE(current_stock,0)+?, last_updated=NOW() WHERE station_id=? AND LOWER(TRIM(fuel_type))=LOWER(TRIM(?))")
                    ->execute([$e['delivery_liters'], $e['delivery_liters'], $station_id, $e['fuel_type']]);
            }
            $total_l = array_sum(array_column($batch_entries, 'delivery_liters'));
            try { $pdo->prepare("INSERT INTO audit_logs(user_id,action_type,entity_type,entity_id,details,station_id,ip_address,created_at)VALUES(?,'Approve','fuel_delivery_batch',0,?,?,?,NOW())")
                ->execute([$me['id'],"Approved batch {$batch_id} — ".count($batch_entries)." tanks, {$total_l}L",$station_id,$_SERVER['REMOTE_ADDR']??'']); } catch(Exception $ae){}
            $_SESSION['success'] = "Batch <strong>{$batch_id}</strong> approved — ".count($batch_entries)." tanks verified, {$total_l} L added to inventory.";

        } elseif ($action === 'reject_batch') {
            $reason = trim($_POST['reason'] ?? '');
            if (empty($reason)) throw new Exception("Return reason is required.");
            foreach ($batch_entries as $e) {
                $pdo->prepare("UPDATE fuel_deliveries SET status='Rejected', verified_by=?, verified_at=NOW(), notes=CONCAT(IFNULL(notes,''),' | Manager Returned: ',?) WHERE id=? AND station_id=?")
                    ->execute([$me['id'], $reason, $e['id'], $station_id]);
            }
            try { $pdo->prepare("INSERT INTO audit_logs(user_id,action_type,entity_type,entity_id,details,station_id,ip_address,created_at)VALUES(?,'Reject','fuel_delivery_batch',0,?,?,?,NOW())")
                ->execute([$me['id'],"Returned batch {$batch_id} — Reason: {$reason}",$station_id,$_SERVER['REMOTE_ADDR']??'']); } catch(Exception $ae){}
            $_SESSION['success'] = "Batch <strong>{$batch_id}</strong> returned to staff for correction.";

        } elseif ($action === 'adjust_batch') {
            $adj_note    = trim($_POST['adj_note'] ?? '');
            $adj_liters  = $_POST['adj_liters'] ?? [];
            if (empty($adj_note)) throw new Exception("Adjustment note is required.");
            
            $log_entries = [];
            foreach ($batch_entries as $e) {
                $new_l = isset($adj_liters[$e['id']]) ? max(0, (float)$adj_liters[$e['id']]) : (float)$e['delivery_liters'];
                $orig  = (float)$e['delivery_liters'];
                $variance = $new_l - $orig;
                
                $var_str = ($variance >= 0 ? "+" : "") . number_format($variance, 2) . " L";
                $note_update = " | Adjusted {$orig} L -> {$new_l} L (Variance: {$var_str}). Note: {$adj_note}";
                
                $pdo->prepare("UPDATE fuel_deliveries SET status='Verified', delivery_liters=?, verified_by=?, verified_at=NOW(), notes=CONCAT(IFNULL(notes,''), ?) WHERE id=? AND station_id=?")
                    ->execute([$new_l, $note_update, $e['id'], $station_id]);
                
                if ($new_l > 0) {
                    $pdo->prepare("UPDATE fuel_inventory SET current_level=COALESCE(current_level,0)+?, current_stock=COALESCE(current_stock,0)+?, last_updated=NOW() WHERE station_id=? AND LOWER(TRIM(fuel_type))=LOWER(TRIM(?))")
                        ->execute([$new_l, $new_l, $station_id, $e['fuel_type']]);
                }
                
                $log_entries[] = "Tank: {$e['tank_assigned']} ({$e['fuel_type']}) Adjusted: {$orig} L -> {$new_l} L (Variance: {$var_str})";
            }
            
            $consolidated_logs = implode("; ", $log_entries) . " | Reason: {$adj_note}";
            try { 
                $pdo->prepare("INSERT INTO audit_logs(user_id,action_type,entity_type,entity_id,details,station_id,ip_address,created_at) VALUES(?,'Adjust','fuel_delivery_batch',0,?,?,?,NOW())")
                    ->execute([$me['id'], "Adjusted Batch {$batch_id} — " . $consolidated_logs, $station_id, $_SERVER['REMOTE_ADDR']??'']); 
            } catch(Exception $ae){}
            
            $_SESSION['success'] = "Batch <strong>{$batch_id}</strong> adjusted and approved.";
        }

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
    header('Location: manager_fuel_deliveries_validation.php'); exit;
}

// ── Date Filter ────────────────────────────────────────────
$date_from = trim($_GET['date_from'] ?? date('Y-m-d', strtotime('-6 months')));
$date_to   = trim($_GET['date_to']   ?? date('Y-m-d'));

$PENDING_STATUSES = "'pending','pending validation','pending manager approval','pending manager validation'";

// ── Summary Cards ───────────────────────────────────────────
$validated_count = 0;
$pending_count   = 0;

try {
    $sv = $pdo->prepare("SELECT COUNT(DISTINCT batch_id) FROM fuel_deliveries WHERE station_id=? AND LOWER(status) IN ('verified','approved') AND DATE(delivery_date) BETWEEN ? AND ?");
    $sv->execute([$station_id, $date_from, $date_to]);
    $validated_count = (int)$sv->fetchColumn();

    $sp = $pdo->prepare("SELECT COUNT(DISTINCT batch_id) FROM fuel_deliveries WHERE station_id=? AND LOWER(status) IN ({$PENDING_STATUSES}) AND DATE(delivery_date) BETWEEN ? AND ?");
    $sp->execute([$station_id, $date_from, $date_to]);
    $pending_count = (int)$sp->fetchColumn();
} catch (Exception $e) { error_log('Summary: '.$e->getMessage()); }

// ── Fetch Pending Batches (grouped) ────────────────────────
$pending_batches = [];
try {
    $bs = $pdo->prepare("
        SELECT fd.batch_id,
               MIN(fd.delivery_date) AS delivery_date,
               fd.supplier, fd.invoice_no, fd.tanker_number,
               COUNT(*) AS entry_count,
               SUM(fd.delivery_liters) AS total_liters,
               MIN(fd.created_at) AS created_at,
               COALESCE(NULLIF(CONCAT(TRIM(COALESCE(u.first_name,'')), ' ', TRIM(COALESCE(u.last_name,''))), ' '), u.username, 'Unknown') AS staff_name
        FROM fuel_deliveries fd
        LEFT JOIN users u ON fd.received_by = u.id
        WHERE fd.station_id = ?
          AND LOWER(fd.status) IN ({$PENDING_STATUSES})
          AND DATE(fd.delivery_date) BETWEEN ? AND ?
        GROUP BY fd.batch_id, fd.supplier, fd.invoice_no, fd.tanker_number, fd.received_by, u.first_name, u.last_name, u.username
        ORDER BY created_at DESC");
    $bs->execute([$station_id, $date_from, $date_to]);
    $pending_batches = $bs->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pending_batches as &$batch) {
        $es = $pdo->prepare("SELECT * FROM fuel_deliveries WHERE batch_id=? AND station_id=? ORDER BY id ASC");
        $es->execute([$batch['batch_id'], $station_id]);
        $batch['entries'] = $es->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($batch);
    $total_records = count($pending_batches);
} catch (Exception $e) {
    error_log('Fetch batches: '.$e->getMessage());
    $_SESSION['error'] = 'Error loading batches: '.$e->getMessage();
}

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

    <!-- ── Batch Cards Section ── -->
<style>
.batch-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;margin-bottom:20px;overflow:hidden;box-shadow:0 2px 6px rgba(0,0,0,.05);}
.batch-hd{background:#f8fafc;border-bottom:1px solid #e2e8f0;padding:14px 18px;display:flex;align-items:flex-start;gap:16px;flex-wrap:wrap;}
.batch-id-tag{font-family:monospace;font-size:13px;font-weight:800;color:#002F70;background:#e0f2fe;border:1px solid #bae6fd;padding:5px 12px;border-radius:7px;white-space:nowrap;display:flex;align-items:center;gap:7px;}
.batch-meta{display:flex;flex-wrap:wrap;gap:10px;flex:1;align-items:center;}
.batch-meta span{font-size:12px;color:#475569;display:inline-flex;align-items:center;gap:5px;font-weight:500;}
.batch-meta span i{color:#94a3b8;}
.batch-totals{display:flex;gap:14px;margin-left:auto;}
.btot-item{text-align:right;}
.btot-lbl{font-size:10px;color:#94a3b8;font-weight:700;text-transform:uppercase;display:block;}
.btot-val{font-size:18px;font-weight:900;color:#002F70;}
.entry-tbl{width:100%;border-collapse:collapse;font-size:13px;}
.entry-tbl thead th{background:#002F70;color:#fff;padding:9px 12px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;}
.entry-tbl tbody tr{border-bottom:1px solid #f1f5f9;transition:background .1s;}
.entry-tbl tbody tr:hover{background:#f8fafc;}
.entry-tbl td{padding:9px 12px;vertical-align:middle;}
.entry-tbl tfoot td{padding:9px 12px;border-top:2px solid #e2e8f0;background:#f8fafc;}
.fuel-tag{font-size:11px;font-weight:700;color:#002F70;}
.batch-actions{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-top:1px solid #e2e8f0;background:#fafafa;gap:12px;flex-wrap:wrap;}
.badge-pending{font-size:11px;font-weight:700;color:#d97706;background:#fef3c7;border:1px solid #fde68a;padding:5px 10px;border-radius:6px;display:inline-flex;align-items:center;gap:5px;}
.action-set{display:flex;gap:8px;}
.act-btn{padding:9px 18px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:7px;transition:all .15s;}
.act-approve{background:#002F70;color:#fff;} .act-approve:hover{background:#001a42;}
.act-adjust{background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;} .act-adjust:hover{background:#e2e8f0;}
.act-return{background:#fef2f2;color:#dc2626;border:1px solid #fca5a5;} .act-return:hover{background:#fee2e2;}
</style>

    <div class="table-card">
        <h3 style="margin:0 0 16px;font-size:14px;font-weight:700;color:#00264D;text-transform:uppercase;">
            <i class="fas fa-layer-group"></i> Pending Delivery Batches
            <span style="font-size:12px;font-weight:600;color:#64748b;margin-left:8px;text-transform:none;">
                (<?= $total_records ?> batch<?= $total_records != 1 ? 'es' : '' ?> awaiting validation)
            </span>
        </h3>

        <?php if (empty($pending_batches)): ?>
        <div style="text-align:center;padding:48px;color:#94a3b8;">
            <i class="fas fa-inbox" style="font-size:48px;margin-bottom:14px;opacity:.4;display:block;"></i>
            <div style="font-size:14px;font-weight:600;">Walay pending deliveries nga nakit-an.</div>
            <div style="font-size:12px;margin-top:6px;">No pending batch submissions from staff for this date range.</div>
        </div>
        <?php else: ?>

        <?php foreach ($pending_batches as $batch): ?>
        <div class="batch-card">
            <!-- Batch Header -->
            <div class="batch-hd">
                <div class="batch-id-tag">
                    <i class="fas fa-layer-group"></i>
                    <?= htmlspecialchars($batch['batch_id']) ?>
                </div>
                <div class="batch-meta">
                    <span><i class="fas fa-calendar-alt"></i> <?= date('M d, Y', strtotime($batch['delivery_date'])) ?></span>
                    <span><i class="fas fa-user"></i> <?= htmlspecialchars($batch['staff_name']) ?></span>
                    <span><i class="fas fa-building"></i> <?= htmlspecialchars($batch['supplier']) ?></span>
                    <span><i class="fas fa-file-invoice"></i> <?= htmlspecialchars($batch['invoice_no']) ?></span>
                    <?php if (!empty($batch['tanker_number'])): ?>
                    <span><i class="fas fa-truck"></i> <?= htmlspecialchars($batch['tanker_number']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="batch-totals">
                    <div class="btot-item">
                        <span class="btot-lbl">Total Liters</span>
                        <span class="btot-val"><?= number_format($batch['total_liters'], 2) ?> L</span>
                    </div>
                    <div class="btot-item">
                        <span class="btot-lbl">Tanks</span>
                        <span class="btot-val"><?= $batch['entry_count'] ?></span>
                    </div>
                </div>
            </div>

            <!-- Entries Table -->
            <div style="overflow-x:auto;">
                <table class="entry-tbl">
                    <thead>
                        <tr>
                            <th width="4%">#</th>
                            <th width="20%">Fuel Type</th>
                            <th>Tank / Remarks</th>
                            <th width="18%" style="text-align:right;">Liters Delivered</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($batch['entries'] as $idx => $e): ?>
                        <tr>
                            <td style="color:#94a3b8;font-size:11px;"><?= $idx + 1 ?></td>
                            <td><span class="fuel-tag"><?= htmlspecialchars($e['fuel_type']) ?></span></td>
                            <td style="font-size:12px;color:#64748b;"><?= htmlspecialchars($e['notes'] ?? '') ?></td>
                            <td style="text-align:right;font-weight:700;color:#002F70;"><?= number_format($e['delivery_liters'], 2) ?> L</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" style="text-align:right;font-size:12px;font-weight:700;color:#475569;">BATCH TOTAL</td>
                            <td style="text-align:right;font-weight:900;color:#002F70;font-size:15px;"><?= number_format($batch['total_liters'], 2) ?> L</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- One Action Row Per Batch -->
            <div class="batch-actions">
                <span class="badge-pending"><i class="fas fa-clock"></i> Pending Manager Validation</span>
                <div class="action-set">
                    <button type="button" class="act-btn act-approve"
                        onclick="openBatchAction('approve','<?= htmlspecialchars(addslashes($batch['batch_id'])) ?>','<?= htmlspecialchars(addslashes($batch['invoice_no'])) ?>',<?= (int)$batch['entry_count'] ?>,<?= number_format((float)$batch['total_liters'],2,'.','') ?>)">
                        <i class="fas fa-check-circle"></i> Approve
                    </button>
                    <button type="button" class="act-btn act-adjust"
                        onclick="openBatchAction('adjust','<?= htmlspecialchars(addslashes($batch['batch_id'])) ?>','<?= htmlspecialchars(addslashes($batch['invoice_no'])) ?>',<?= (int)$batch['entry_count'] ?>,<?= number_format((float)$batch['total_liters'],2,'.','') ?>)">
                        <i class="fas fa-edit"></i> Adjust
                    </button>
                    <button type="button" class="act-btn act-return"
                        onclick="openBatchAction('return','<?= htmlspecialchars(addslashes($batch['batch_id'])) ?>','<?= htmlspecialchars(addslashes($batch['invoice_no'])) ?>',<?= (int)$batch['entry_count'] ?>,<?= number_format((float)$batch['total_liters'],2,'.','') ?>)">
                        <i class="fas fa-undo"></i> Return
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php
// Build JSON for adjust modal (per-entry liters)
$batches_json = [];
foreach ($pending_batches as $b) {
    $batches_json[$b['batch_id']] = array_map(fn($e) => [
        'id'      => (int)$e['id'],
        'fuel'    => $e['fuel_type'],
        'liters'  => (float)$e['delivery_liters'],
    ], $b['entries']);
}
?>

<!-- ── Batch Action Modal ── -->
<div id="batchModal" class="modal">
    <div class="modal-content" style="max-width:540px;">
        <div class="modal-header" style="border-bottom:1px solid #e2e8f0;padding-bottom:12px;">
            <div>
                <h3 id="bm_title" style="margin:0;font-size:17px;color:#00264D;"></h3>
                <p id="bm_sub" style="margin:4px 0 0;font-size:12px;color:#64748b;"></p>
            </div>
            <span class="modal-close" onclick="closeModal('batchModal')">&times;</span>
        </div>
        <div id="bm_body" style="padding-top:16px;"></div>
    </div>
</div>

<script>
const BATCHES = <?= json_encode($batches_json) ?>;

function openBatchAction(action, batchId, invoiceNo, entryCount, totalLiters) {
    const modal  = document.getElementById('batchModal');
    const title  = document.getElementById('bm_title');
    const sub    = document.getElementById('bm_sub');
    const body   = document.getElementById('bm_body');
    sub.textContent = batchId + ' \u2022 Invoice: ' + invoiceNo + ' \u2022 ' + entryCount + ' tanks \u2022 ' + totalLiters + ' L';

    if (action === 'approve') {
        title.innerHTML = '<i class="fas fa-check-circle" style="color:#16a34a;margin-right:8px;"></i>Approve Batch';
        body.innerHTML = `
            <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:14px 16px;margin-bottom:18px;font-size:13px;color:#15803d;">
                <i class="fas fa-info-circle" style="margin-right:6px;"></i>
                Approving will verify all <strong>${entryCount}</strong> tank entries and add <strong>${totalLiters} L</strong> to fuel inventory.
            </div>
            <form method="post">
                <input type="hidden" name="action" value="approve_batch">
                <input type="hidden" name="batch_id" value="${batchId}">
                <div style="display:flex;gap:8px;justify-content:flex-end;">
                    <button type="button" onclick="closeModal('batchModal')" style="background:#f1f5f9;color:#475569;padding:9px 18px;border-radius:7px;border:1px solid #e2e8f0;font-size:13px;font-weight:600;cursor:pointer;">Cancel</button>
                    <button type="submit" style="background:#16a34a;color:#fff;padding:9px 24px;border-radius:7px;border:none;font-size:13px;font-weight:700;cursor:pointer;"><i class="fas fa-check"></i> Confirm Approve</button>
                </div>
            </form>`;

    } else if (action === 'adjust') {
        window.location.href = 'manager_fuel_adjustments.php?sub_tab=adj-deliveries#adjustments';
        return;

    } else if (action === 'return') {
        title.innerHTML = '<i class="fas fa-undo" style="color:#dc2626;margin-right:8px;"></i>Return Batch to Staff';
        body.innerHTML = `
            <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:14px 16px;margin-bottom:18px;font-size:13px;color:#b91c1c;">
                <i class="fas fa-exclamation-triangle" style="margin-right:6px;"></i>
                Returning will reject all <strong>${entryCount}</strong> entries. Inventory will NOT be updated. Staff will see the reason.
            </div>
            <form method="post">
                <input type="hidden" name="action" value="reject_batch">
                <input type="hidden" name="batch_id" value="${batchId}">
                <div style="margin-bottom:14px;">
                    <label style="display:block;font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;margin-bottom:4px;">Return Reason <span style="color:#dc2626;">*</span></label>
                    <textarea name="reason" required placeholder="Explain why this batch is being returned..."
                              style="width:100%;padding:8px 12px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;min-height:80px;resize:vertical;box-sizing:border-box;"></textarea>
                </div>
                <div style="display:flex;gap:8px;justify-content:flex-end;">
                    <button type="button" onclick="closeModal('batchModal')" style="background:#f1f5f9;color:#475569;padding:9px 18px;border-radius:7px;border:1px solid #e2e8f0;font-size:13px;font-weight:600;cursor:pointer;">Cancel</button>
                    <button type="submit" style="background:#dc2626;color:#fff;padding:9px 24px;border-radius:7px;border:none;font-size:13px;font-weight:700;cursor:pointer;"><i class="fas fa-undo"></i> Confirm Return</button>
                </div>
            </form>`;
    }
    modal.style.display = 'block';
}

function closeModal(id) { document.getElementById(id).style.display = 'none'; }
window.onclick = function(e) { if (e.target.className === 'modal') e.target.style.display = 'none'; }
function changeRowsPerPage(v) {
    const u = new URL(window.location.href);
    u.searchParams.set('rows_per_page', v);
    u.searchParams.set('page','1');
    window.location.href = u.toString();
}
</script>
<?php require_once __DIR__ . '/../partials/footer.php'; ?>
