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
    $tx_id  = (int)($_POST['transaction_id'] ?? 0);
    
    try {
        $pdo->beginTransaction();
        
        if ($action === 'approve' && $tx_id > 0) {
            $notes = trim($_POST['approve_notes'] ?? '');
            
            // Approve transaction
            $stmt = $pdo->prepare("UPDATE fuel_transactions 
                                   SET status = 'Verified', 
                                       validated_by = ?, 
                                       validated_at = NOW(),
                                       reject_reason = ? 
                                   WHERE id = ? AND station_id = ? AND LOWER(status) LIKE '%pending%'");
            $stmt->execute([$me['id'], $notes !== '' ? $notes : null, $tx_id, $station_id]);
            
            if ($stmt->rowCount() > 0) {
                // Log audit trail
                try {
                    $details = "Approved.";
                    if ($notes !== '') {
                        $details .= " Notes: " . $notes;
                    }
                    $pdo->prepare("INSERT INTO audit_logs (user_id, action_type, entity_type, entity_id, details, station_id, ip_address, created_at) 
                                   VALUES (?, 'Approve', 'fuel_transaction', ?, ?, ?, ?, NOW())")
                        ->execute([$me['id'], $tx_id, $details, $station_id, $_SERVER['REMOTE_ADDR'] ?? '']);
                } catch (Exception $ae) {}
                
                $_SESSION['success'] = "Transaction #{$tx_id} approved successfully.";
            } else {
                $_SESSION['error'] = "Transaction not found or already processed.";
            }
        }
        
        elseif ($action === 'reject' && $tx_id > 0) {
            $reason = trim($_POST['reason'] ?? '');
            
            // Reject transaction (reason is optional)
            $stmt = $pdo->prepare("UPDATE fuel_transactions 
                                   SET status = 'Rejected', 
                                       validated_by = ?, 
                                       validated_at = NOW(),
                                       reject_reason = ? 
                                   WHERE id = ? AND station_id = ? AND LOWER(status) LIKE '%pending%'");
            $stmt->execute([$me['id'], $reason !== '' ? $reason : null, $tx_id, $station_id]);
            
            if ($stmt->rowCount() > 0) {
                // Log audit trail
                try {
                    $details = "Rejected.";
                    if ($reason !== '') {
                        $details .= " Reason: " . $reason;
                    }
                    $pdo->prepare("INSERT INTO audit_logs (user_id, action_type, entity_type, entity_id, details, station_id, ip_address, created_at) 
                                   VALUES (?, 'Reject', 'fuel_transaction', ?, ?, ?, ?, NOW())")
                        ->execute([$me['id'], $tx_id, $details, $station_id, $_SERVER['REMOTE_ADDR'] ?? '']);
                } catch (Exception $ae) {}
                
                $_SESSION['success'] = "Transaction #{$tx_id} rejected and returned to staff.";
            } else {
                $_SESSION['error'] = "Transaction not found or already processed.";
            }
        }
        
        elseif ($action === 'adjust' && $tx_id > 0) {
            $new_liters = (float)($_POST['adj_liters'] ?? 0);
            $new_amount = (float)($_POST['adj_amount'] ?? 0);
            $adj_note   = trim($_POST['adj_note'] ?? '');
            
            if ($new_liters <= 0 || $new_amount <= 0) {
                throw new Exception("Adjusted values must be greater than zero.");
            }
            
            // Adjust transaction (note is optional)
            $stmt = $pdo->prepare("UPDATE fuel_transactions 
                                   SET status = 'Verified', 
                                       liters_sold = ?,
                                       total_amount = ?,
                                       validated_by = ?, 
                                       validated_at = NOW(),
                                       reject_reason = ?
                                   WHERE id = ? AND station_id = ? AND LOWER(status) LIKE '%pending%'");
            $stmt->execute([$new_liters, $new_amount, $me['id'], $adj_note !== '' ? $adj_note : null, $tx_id, $station_id]);
            
            if ($stmt->rowCount() > 0) {
                // Log audit trail
                try {
                    $details = "Adjusted to {$new_liters}L / ₱{$new_amount}.";
                    if ($adj_note !== '') {
                        $details .= " Note: {$adj_note}";
                    }
                    $pdo->prepare("INSERT INTO audit_logs (user_id, action_type, entity_type, entity_id, details, station_id, ip_address, created_at) 
                                   VALUES (?, 'Adjust', 'fuel_transaction', ?, ?, ?, ?, NOW())")
                        ->execute([$me['id'], $tx_id, $details, $station_id, $_SERVER['REMOTE_ADDR'] ?? '']);
                } catch (Exception $ae) {}
                
                $_SESSION['success'] = "Transaction #{$tx_id} adjusted successfully.";
            } else {
                $_SESSION['error'] = "Transaction not found or already processed.";
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
$pending_count = 0;

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM fuel_transactions 
                           WHERE station_id = ? 
                           AND LOWER(status) IN ('approved', 'adjusted', 'verified')
                           AND DATE(transaction_date) BETWEEN ? AND ?");
    $stmt->execute([$station_id, $date_from, $date_to]);
    $validated_count = (int)$stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM fuel_transactions 
                           WHERE station_id = ? 
                           AND LOWER(status) LIKE '%pending%'
                           AND DATE(transaction_date) BETWEEN ? AND ?");
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

// ── Fetch Transactions ────────────────────────────────────
$transactions = [];
$total_records = 0;
try {
    // Get total count
    $count_sql = "SELECT COUNT(*) 
                  FROM fuel_transactions ft
                  WHERE ft.station_id = ?
                  AND LOWER(ft.status) LIKE '%pending%'
                  AND DATE(ft.transaction_date) BETWEEN ? AND ?";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute([$station_id, $date_from, $date_to]);
    $total_records = (int)$stmt->fetchColumn();
    
    // Get paginated results - Use only first_name/last_name (compatible schema)
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
            ORDER BY ft.transaction_date DESC, ft.created_at DESC
            LIMIT ? OFFSET ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(1, $station_id, PDO::PARAM_INT);
    $stmt->bindValue(2, $date_from, PDO::PARAM_STR);
    $stmt->bindValue(3, $date_to, PDO::PARAM_STR);
    $stmt->bindValue(4, $rows_per_page, PDO::PARAM_INT);
    $stmt->bindValue(5, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Fetch transactions error: " . $e->getMessage());
    $_SESSION['error'] = "Error loading transactions: " . $e->getMessage();
}

$total_pages = ceil($total_records / $rows_per_page);

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

/* Manager Fuel Transaction Validation Styles */
.mftv-wrap { 
    padding: 0; 
    max-width: 100%;
    width: 100%;
    overflow-x: visible;
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
.sum-ico {
    width: 52px; height: 52px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 24px; flex-shrink: 0;
}
.summary-card.sc-blue .sum-ico   { background: #dbeafe; color: #1e40af; }
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

/* Table – NO horizontal scroll, all columns fit on screen */
.table-card {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
    padding: 14px; box-shadow: 0 1px 3px rgba(0,0,0,.05);
    width: 100%; overflow: hidden; box-sizing: border-box;
}
.table-wrap { 
    width: 100%; overflow-x: hidden;
    box-sizing: border-box; display: block;
}
.data-table {
    width: 100%; border-collapse: collapse;
    table-layout: fixed;
    box-sizing: border-box;
}
/* Column widths — tuned so all 13 cols fit at ~1050px+ content width */
.data-table th:nth-child(1),  .data-table td:nth-child(1)  { width:7%;  } /* Date        */
.data-table th:nth-child(2),  .data-table td:nth-child(2)  { width:6%;  } /* Shift       */
.data-table th:nth-child(3),  .data-table td:nth-child(3)  { width:9%;  } /* Pump/Fuel   */
.data-table th:nth-child(4),  .data-table td:nth-child(4)  { width:6%;  } /* Beginning   */
.data-table th:nth-child(5),  .data-table td:nth-child(5)  { width:6%;  } /* Ending      */
.data-table th:nth-child(6),  .data-table td:nth-child(6)  { width:5%;  } /* Calibration */
.data-table th:nth-child(7),  .data-table td:nth-child(7)  { width:6%;  } /* Volume      */
.data-table th:nth-child(8),  .data-table td:nth-child(8)  { width:6%;  } /* Price/L     */
.data-table th:nth-child(9),  .data-table td:nth-child(9)  { width:7%;  } /* Amount      */
.data-table th:nth-child(10), .data-table td:nth-child(10) { width:8%;  } /* Encoded By  */
.data-table th:nth-child(11), .data-table td:nth-child(11) { width:11%; } /* Status      */
.data-table th:nth-child(12), .data-table td:nth-child(12) { width:6%;  } /* Notes       */
.data-table th:nth-child(13), .data-table td:nth-child(13) { width:13%; } /* ACTION      */
.data-table thead th {
    background: #002F70; padding: 8px 6px; text-align: left;
    font-size: 10px; font-weight: 700; color: #fff;
    text-transform: uppercase; border-bottom: 2px solid #002F70;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    line-height: 1.2;
}
.data-table tbody tr { border-bottom: 1px solid #f1f5f9; }
.data-table tbody tr:hover { background: #e3f2fd; }
.data-table tbody td {
    padding: 7px 6px;
    color: #334155;
    vertical-align: middle;
    font-size: 11px;
    line-height: 1.3;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.align-right { text-align: right !important; }

/* Status badge – compact */
.status-badge {
    display: inline-block;
    font-size: 9px; font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .4px;
    padding: 3px 6px;
    border-radius: 4px;
    white-space: nowrap;
}

/* Compact action buttons – stacked vertically with words */
.action-btn {
    padding: 5px 8px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 700;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    transition: all .15s;
    width: 100%;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    box-sizing: border-box;
}
.btn-approve { background: #28a745; color: #fff; }
.btn-approve:hover { background: #218838; transform: scale(1.03); }
.btn-reject  { background: #dc2626; color: #fff; }
.btn-reject:hover  { background: #b91c1c; transform: scale(1.03); }
.btn-adjust  { background: #002F70; color: #fff; }
.btn-adjust:hover  { background: #001a42; transform: scale(1.03); }

.action-buttons-wrapper {
    display: flex;
    flex-direction: column;
    gap: 4px;
    width: 100%;
    align-items: stretch;
}
.data-table thead th:last-child  { text-align: center; }
.data-table tbody td:last-child   { padding: 6px 6px !important; overflow: visible; }

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
        <div class="summary-card sc-blue">
            <div class="sum-ico"><i class="fas fa-check-circle"></i></div>
            <div class="sum-meta">
                <h3>Validated Transactions</h3>
                <h2><?= number_format($validated_count) ?></h2>
                <span>Gi-approve ug gi-adjust</span>
            </div>
        </div>
        <div class="summary-card sc-amber">
            <div class="sum-ico"><i class="fas fa-clock"></i></div>
            <div class="sum-meta">
                <h3>Pending Transactions</h3>
                <h2><?= number_format($pending_count) ?></h2>
                <span>Naghulat sa validation</span>
            </div>
        </div>
    </div>

    <!-- Transactions Table -->
    <div class="table-card">
        <h3 style="margin:0 0 14px;font-size:14px;font-weight:700;color:#00264D;text-transform:uppercase;">
            <i class="fas fa-list"></i> Pending Pump Readings
        </h3>

        <?php if (empty($transactions)): ?>
        <div style="text-align:center;padding:40px;color:#94a3b8;">
            <i class="fas fa-inbox" style="font-size:48px;margin-bottom:12px;opacity:.5;"></i>
            <p style="margin:0;">Walay pending transactions nga nakit-an.</p>
        </div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <!-- v2.0 - Action header added -->
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Shift</th>
                        <th>Pump / Fuel Type</th>
                        <th class="align-right">Beginning</th>
                        <th class="align-right">Ending</th>
                        <th class="align-right">Calibration</th>
                        <th class="align-right">Volume (L)</th>
                        <th class="align-right">Price/L</th>
                        <th class="align-right">Amount</th>
                        <th>Encoded By</th>
                        <th>Status</th>
                        <th>Notes</th>
                        <th style="text-align: center;">ACTION</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $tx): 
                        $pump_fuel = htmlspecialchars($tx['fuel_type'] ?? 'N/A');
                        if (!empty($tx['pump_number'])) {
                            $pump_fuel .= ' - ' . htmlspecialchars($tx['pump_number']);
                        }
                    ?>
                    <tr>
                        <td><?= date('M d, Y', strtotime($tx['transaction_date'])) ?></td>
                        <td><?= htmlspecialchars(formatShift($tx['shift_period'] ?? '', $tx['shift_name'] ?? '')) ?></td>
                        <td style="font-weight: 700; color: #0f172a;"><?= $pump_fuel ?></td>
                        <td class="align-right"><?= number_format($tx['previous_reading'] ?? 0, 2) ?></td>
                        <td class="align-right" style="font-weight: 600; color: #1e293b;"><?= number_format($tx['present_reading'] ?? 0, 2) ?></td>
                        <td class="align-right"><?= number_format($tx['calibration'] ?? 0, 3) ?></td>
                        <td class="align-right" style="font-weight: 700; color: #1e293b;"><?= number_format($tx['liters_sold'] ?? 0, 2) ?> L</td>
                        <td class="align-right">₱<?= number_format($tx['price_per_liter'] ?? 0, 2) ?></td>
                        <td class="align-right" style="font-weight: 800; color: #0f172a;">₱<?= number_format($tx['total_amount'] ?? 0, 2) ?></td>
                        <td style="font-weight: 500;"><?= htmlspecialchars($tx['staff_name'] ?? 'N/A') ?></td>
                        <td><?= getStatusBadge($tx['status']) ?></td>
                        <td style="max-width: 160px;">
                            <?php
                            $staffNotes = trim($tx['notes'] ?? '');
                            $mgrNotes = trim($tx['reject_reason'] ?? '');
                            $fullTooltip = '';
                            if ($staffNotes !== '') {
                                $fullTooltip .= 'Staff: ' . $staffNotes;
                            }
                            if ($mgrNotes !== '') {
                                if ($fullTooltip !== '') $fullTooltip .= "\n";
                                $fullTooltip .= 'Manager: ' . $mgrNotes;
                            }
                            if ($staffNotes !== '' && $mgrNotes !== '') {
                                echo '<div style="line-height:1.2; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="' . htmlspecialchars($fullTooltip) . '"><strong>S:</strong> ' . htmlspecialchars($staffNotes) . '<br><strong>M:</strong> ' . htmlspecialchars($mgrNotes) . '</div>';
                            } elseif ($staffNotes !== '') {
                                echo '<div style="line-height:1.2; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="' . htmlspecialchars($fullTooltip) . '">' . htmlspecialchars($staffNotes) . '</div>';
                            } elseif ($mgrNotes !== '') {
                                echo '<div style="line-height:1.2; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#002F70;" title="' . htmlspecialchars($fullTooltip) . '"><strong>M:</strong> ' . htmlspecialchars($mgrNotes) . '</div>';
                            } else {
                                echo '—';
                            }
                            ?>
                        </td>
                        <td>
                            <div class="action-buttons-wrapper">
                                <button class="action-btn btn-approve" onclick="approveTransaction(<?= $tx['id'] ?>)" title="Approve">
                                    <i class="fas fa-check"></i> Approve
                                </button>
                                <button class="action-btn btn-reject" onclick="rejectTransaction(<?= $tx['id'] ?>)" title="Reject">
                                    <i class="fas fa-times"></i> Reject
                                </button>
                                <button class="action-btn btn-adjust" onclick="adjustTransaction(<?= $tx['id'] ?>, <?= $tx['liters_sold'] ?? 0 ?>, <?= $tx['total_amount'] ?? 0 ?>)" title="Adjust">
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

<!-- Approve Modal -->
<div id="approveModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Approve Transaction</h3>
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
                        style="background:#6c757d;color:#fff;padding:8px 16px;border-radius:6px;border:none;cursor:pointer;">
                    Cancel
                </button>
                <button type="submit"
                        style="background:#28a745;color:#fff;padding:8px 16px;border-radius:6px;border:none;cursor:pointer;">
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
            <h3>Reject Transaction</h3>
            <span class="modal-close" onclick="closeModal('rejectModal')">&times;</span>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="transaction_id" id="reject_tx_id">
            <div class="form-group">
                <label>Rejection Reason <span style="font-weight: normal; color: #64748b;">(Optional)</span></label>
                <textarea name="reason" placeholder="Explain why this transaction is being rejected..."></textarea>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" onclick="closeModal('rejectModal')"
                        style="background:#6c757d;color:#fff;padding:8px 16px;border-radius:6px;border:none;cursor:pointer;">
                    Cancel
                </button>
                <button type="submit"
                        style="background:#dc2626;color:#fff;padding:8px 16px;border-radius:6px;border:none;cursor:pointer;">
                    <i class="fas fa-times"></i> Reject
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Adjust Modal -->
<div id="adjustModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Adjust Transaction</h3>
            <span class="modal-close" onclick="closeModal('adjustModal')">&times;</span>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="adjust">
            <input type="hidden" name="transaction_id" id="adjust_tx_id">
            <div class="form-group">
                <label>Adjusted Liters <span style="color:#dc2626;">*</span></label>
                <input type="number" name="adj_liters" id="adj_liters" step="0.01" min="0" required>
            </div>
            <div class="form-group">
                <label>Adjusted Amount <span style="color:#dc2626;">*</span></label>
                <input type="number" name="adj_amount" id="adj_amount" step="0.01" min="0" required>
            </div>
            <div class="form-group">
                <label>Adjustment Note <span style="font-weight: normal; color: #64748b;">(Optional)</span></label>
                <textarea name="adj_note" placeholder="Explain the reason for adjustment..."></textarea>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" onclick="closeModal('adjustModal')"
                        style="background:#6c757d;color:#fff;padding:8px 16px;border-radius:6px;border:none;cursor:pointer;">
                    Cancel
                </button>
                <button type="submit"
                        style="background:#0891b2;color:#fff;padding:8px 16px;border-radius:6px;border:none;cursor:pointer;">
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

function approveTransaction(txId) {
    document.getElementById('approve_tx_id').value = txId;
    document.getElementById('approveModal').style.display = 'block';
}

function rejectTransaction(txId) {
    document.getElementById('reject_tx_id').value = txId;
    document.getElementById('rejectModal').style.display = 'block';
}

function adjustTransaction(txId, liters, amount) {
    document.getElementById('adjust_tx_id').value = txId;
    document.getElementById('adj_liters').value = liters;
    document.getElementById('adj_amount').value = amount;
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
