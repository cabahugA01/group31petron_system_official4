<?php
$page_id = 'staff_delivery_history';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
    header('Location: dashboard.php');
    exit;
}

/* ── Flash messages from redirect ── */
$flash_messages = [
    'received'     => '&#10003; Delivery received and submitted for Manager Approval. View it below.',
    'discrepancy'  => '&#9888; Variance detected! Delivery was flagged as Discrepancy. Please review below.',
    'manual_saved' => '&#10003; Manual delivery saved successfully. Status: Pending Manager Approval.',
    'resubmitted'  => '&#10003; Delivery resubmitted successfully and is now pending Manager Approval.',
];
$msg_key  = trim($_GET['msg'] ?? '');
$msg      = $flash_messages[$msg_key] ?? '';
$msg_type = trim($_GET['type'] ?? 'success');

/* ── Filters from GET ── */
$filter_status   = trim($_GET['status']   ?? '');
$filter_supplier = trim($_GET['supplier'] ?? '');
$filter_start    = trim($_GET['start']    ?? date('Y-m-d', strtotime('-30 days')));
$filter_end      = trim($_GET['end']      ?? date('Y-m-d'));

/* ── Fetch deliveries ── */
$deliveries = [];
$counts = [
    'Pending'  => 0,
    'Verified' => 0,
    'Rejected' => 0,
    'Total'    => 0,
];

try {
    /* Bootstrap table */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS deliveries_oversight (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            delivery_type   ENUM('fuel','merchandise') NOT NULL DEFAULT 'merchandise',
            delivery_ref    VARCHAR(100) NOT NULL DEFAULT '',
            supplier        VARCHAR(200) NOT NULL DEFAULT '',
            product         VARCHAR(200) NOT NULL DEFAULT '',
            quantity        DECIMAL(12,3) NOT NULL DEFAULT 0,
            unit            VARCHAR(30)  NOT NULL DEFAULT 'pcs',
            delivery_date   DATE         NOT NULL,
            dr_number       VARCHAR(100) DEFAULT NULL,
            encoded_by      INT          DEFAULT NULL,
            station_id      INT          NOT NULL,
            status          VARCHAR(60)  NOT NULL DEFAULT 'Pending Manager Approval',
            manager_id      INT          DEFAULT NULL,
            manager_action_at DATETIME   DEFAULT NULL,
            manager_notes   TEXT         DEFAULT NULL,
            admin_id        INT          DEFAULT NULL,
            admin_action_at DATETIME     DEFAULT NULL,
            admin_notes     TEXT         DEFAULT NULL,
            remarks         TEXT         DEFAULT NULL,
            created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_station (station_id),
            INDEX idx_status  (status),
            INDEX idx_date    (delivery_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN remarks TEXT DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN dr_number VARCHAR(100) DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN manager_id INT DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN manager_action_at DATETIME DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN manager_notes TEXT DEFAULT NULL"); } catch (Exception $e) {}

    $where  = "WHERE do2.station_id = ? AND do2.delivery_type = 'merchandise' AND do2.status != 'Expected Delivery' AND do2.delivery_date BETWEEN ? AND ?";
    $params = [$station_id, $filter_start, $filter_end];

    /* Allow all staff in the station to see the delivery history for that station */
    // if ($role === 'staff') {
    //     $where   .= " AND do2.encoded_by = ?";
    //     $params[] = $me['id'];
    // }

    /* Map filter value to DB values */
    if ($filter_status !== '') {
        if ($filter_status === 'Pending Manager Approval') {
            $where .= " AND do2.status IN ('Pending Manager Approval','Pending Manager Confirmation','Pending Validation','Pending Verification','Pending Admin Oversight')";
        } elseif ($filter_status === 'Confirmed') {
            $where .= " AND do2.status IN ('Confirmed','Approved','Adjusted','Validated','Verified','Ready for Stock-In','Stock-In Complete','Partial Delivery','Damaged Items')";
        } elseif ($filter_status === 'Discrepancy') {
            $where .= " AND do2.status IN ('Discrepancy','Pending Resolution','Awaiting Replacement','Returned to Supplier','Rejected','Rejected Delivery','Returned','Returned to Staff','Flagged')";
        } elseif ($filter_status === 'Closed') {
            $where .= " AND do2.status = 'Closed'";
        }
    }
    if ($filter_supplier !== '') {
        $where   .= " AND do2.supplier LIKE ?";
        $params[] = '%' . $filter_supplier . '%';
    }

    $stmt = $pdo->prepare("
        SELECT do2.*, 
               COALESCE(NULLIF(TRIM(u_enc.username), ''), 'Unknown') AS encoded_by_name, 
               COALESCE(NULLIF(TRIM(u_act.username), ''), 'Unknown') AS action_by_name
        FROM deliveries_oversight do2
        LEFT JOIN users u_enc ON do2.encoded_by  = u_enc.id
        LEFT JOIN users u_act ON do2.manager_id  = u_act.id
        {$where}
        ORDER BY
            FIELD(do2.status,
                'Discrepancy',
                'Pending Manager Approval',
                'Pending Manager Confirmation',
                'Pending Validation',
                'Pending Verification',
                'Pending Admin Oversight',
                'Confirmed',
                'Approved',
                'Validated',
                'Verified',
                'Ready for Stock-In',
                'Stock-In Complete',
                'Partial Delivery',
                'Damaged Items',
                'Closed'
            ),
            do2.delivery_date DESC
    ");
    $stmt->execute($params);
    $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($deliveries as $r) {
        $s = $r['status'];
        $counts['Total']++;
        if (in_array($s, ['Pending Manager Approval', 'Pending Manager Confirmation', 'Pending Validation', 'Pending Verification', 'Pending Admin Oversight'])) {
            $counts['Pending']++;
        } elseif (in_array($s, ['Confirmed', 'Approved', 'Validated', 'Verified', 'Ready for Stock-In', 'Adjusted', 'Stock-In Complete', 'Partial Delivery', 'Damaged Items', 'Closed'])) {
            $counts['Verified']++;
        } elseif (in_array($s, ['Discrepancy', 'Pending Resolution', 'Awaiting Replacement', 'Returned to Supplier', 'Rejected', 'Rejected Delivery', 'Returned', 'Returned to Staff', 'Flagged'])) {
            $counts['Rejected']++;
        }
    }

} catch (Exception $e) {
    $msg      = 'Error loading deliveries: ' . $e->getMessage();
    $msg_type = 'error';
}

include __DIR__ . '/../partials/header.php';
?>
<style>
.del-card { background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.06); border:1px solid #e9ecef; margin-bottom:24px; }
.del-card-head { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid #e9ecef; flex-wrap:wrap; gap:8px; }
.del-card-title { font-size:1rem; font-weight:700; color:#002F70; display:flex; align-items:center; gap:8px; }
.del-card-body  { padding:20px; }

/* Status badges */
.badge-pending  { background:#fff3cd; color:#856404; border:1px solid #ffc107; border-radius:20px; padding:3px 10px; font-size:11px; font-weight:600; white-space:nowrap; }
.badge-approved { background:#d4edda; color:#155724; border:1px solid #28a745; border-radius:20px; padding:3px 10px; font-size:11px; font-weight:600; white-space:nowrap; }
.badge-rejected { background:#f8d7da; color:#721c24; border:1px solid #dc3545; border-radius:20px; padding:3px 10px; font-size:11px; font-weight:600; white-space:nowrap; }
.badge-closed   { background:#e2e3e5; color:#383d41; border:1px solid #6c757d; border-radius:20px; padding:3px 10px; font-size:11px; font-weight:600; white-space:nowrap; }

/* Summary cards */
.summary-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:24px; }
@media(max-width:700px){ .summary-grid { grid-template-columns:1fr 1fr; } }
.summary-card {
    background:#fff; border-radius:12px; padding:18px 20px;
    box-shadow:0 2px 8px rgba(0,0,0,.06); border:1px solid #e9ecef;
    display:flex; align-items:center; gap:16px;
    transition:transform .15s,box-shadow .15s;
}
.summary-card:hover { transform:translateY(-2px); box-shadow:0 4px 14px rgba(0,0,0,.09); }
.sc-icon { width:44px; height:44px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }
.sc-text .sc-num   { font-size:1.9rem; font-weight:800; line-height:1; }
.sc-text .sc-label { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.4px; margin-top:3px; }
.sc-pending  .sc-icon { background:#fff3cd; color:#856404; }
.sc-pending  .sc-num  { color:#856404; }
.sc-pending  .sc-label { color:#856404; }
.sc-verified .sc-icon { background:#d4edda; color:#155724; }
.sc-verified .sc-num  { color:#155724; }
.sc-verified .sc-label { color:#155724; }
.sc-rejected .sc-icon { background:#f8d7da; color:#721c24; }
.sc-rejected .sc-num  { color:#721c24; }
.sc-rejected .sc-label { color:#721c24; }
.sc-total    .sc-icon { background:#e8f0fe; color:#002F70; }
.sc-total    .sc-num  { color:#002F70; }
.sc-total    .sc-label { color:#002F70; }

/* Filter bar */
.filter-bar { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:16px; }
.filter-bar .form-group { display:flex; flex-direction:column; gap:4px; }
.filter-bar label { font-size:12px; font-weight:600; color:#495057; }
.filter-bar input, .filter-bar select { border:1px solid #ced4da; border-radius:6px; padding:7px 10px; font-size:13px; }
.filter-bar input:focus, .filter-bar select:focus { border-color:#002F70; outline:0; box-shadow:0 0 0 .15rem rgba(0,47,112,.15); }

/* Table */
.del-table { width:100%; border-collapse:collapse; font-size:13px; }
.del-table thead th { background:#002F70 !important; color:#fff !important; font-weight:600; padding:14px 16px; text-align:left; text-transform:uppercase; letter-spacing:0.3px; border:none !important; font-size:11px; }
.del-table td { padding:10px 12px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
.del-table tr:hover td { background:#f8f9fa; }
.del-table tr.row-rejected td { background:#fff8f8; }
.del-table tr.row-rejected:hover td { background:#ffeaea; }
.del-table tr:last-child td { border-bottom:none; }

/* Buttons */
.btn-sm-view     { display:inline-flex; align-items:center; justify-content:center; gap:5px; padding:5px 10px; border-radius:4px; font-size:11px; font-weight:600; cursor:pointer; text-decoration:none;
                   background:#ffffff !important; border:1px solid #002F6C !important; color:#002F6C !important; transition:all .2s; }
.btn-sm-view:hover { background:#002F6C !important; color:#ffffff !important; }

.btn-sm-print    { display:inline-flex; align-items:center; justify-content:center; gap:5px; padding:5px 10px; border-radius:4px; font-size:11px; font-weight:600; cursor:pointer; text-decoration:none;
                   background:#ffffff !important; border:1px solid #16a34a !important; color:#16a34a !important; transition:all .2s; }
.btn-sm-print:hover { background:#16a34a !important; color:#ffffff !important; }

.btn-sm-resubmit { display:inline-flex; align-items:center; justify-content:center; gap:5px; padding:5px 10px; border-radius:4px; font-size:11px; font-weight:600; cursor:pointer; text-decoration:none;
                   background:#ffffff !important; border:1px solid #fd7e14 !important; color:#fd7e14 !important; transition:all .2s; }
.btn-sm-resubmit:hover { background:#fd7e14 !important; color:#ffffff !important; }

/* Alerts */
.alert-success-del { background:#d4edda; color:#155724; border:1px solid #c3e6cb; border-radius:8px; padding:12px 16px; margin-bottom:20px; display:flex; align-items:flex-start; gap:10px; }
.alert-error-del   { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; border-radius:8px; padding:12px 16px; margin-bottom:20px; display:flex; align-items:flex-start; gap:10px; }

/* Rejection note inline in table */
.rejection-note { background:#fff3cd; border:1px solid #ffc107; border-radius:5px; padding:3px 8px; font-size:11px; color:#856404; margin-top:4px; display:flex; align-items:flex-start; gap:5px; line-height:1.4; }

/* Modal */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:9000; align-items:center; justify-content:center; }
.modal-overlay.show { display:flex; }
.modal-box { background:#fff; border-radius:12px; padding:28px; max-width:540px; width:90%; box-shadow:0 8px 32px rgba(0,0,0,.2); max-height:90vh; overflow-y:auto; }
.modal-title { font-size:1.1rem; font-weight:700; color:#002F70; margin-bottom:16px; display:flex; align-items:center; gap:8px; }
.modal-actions { display:flex; gap:10px; margin-top:20px; justify-content:flex-end; flex-wrap:wrap; }
.btn-cancel-del     { display:inline-flex; align-items:center; gap:5px; padding:8px 16px; border-radius:4px; font-size:12px; font-weight:600; cursor:pointer; background:#ffffff !important; border:1px solid #475569 !important; color:#475569 !important; transition:all .2s; }
.btn-cancel-del:hover { background:#475569 !important; color:#ffffff !important; }

.btn-resubmit-modal { display:inline-flex; align-items:center; gap:7px; padding:8px 18px; border-radius:4px; font-size:12px; font-weight:600; cursor:pointer; text-decoration:none;
                      background:#ffffff !important; border:1px solid #fd7e14 !important; color:#fd7e14 !important; transition:all .2s; }
.btn-resubmit-modal:hover { background:#fd7e14 !important; color:#ffffff !important; }

/* Detail table in modal */
.detail-table { width:100%; border-collapse:collapse; font-size:13px; }
.detail-table tr td:first-child { color:#6c757d; width:140px; padding:7px 0; vertical-align:top; font-weight:500; }
.detail-table tr td:last-child  { padding:7px 0; color:#212529; }
.detail-table tr + tr td { border-top:1px solid #f0f0f0; }

/* Rejection banner in modal */
.rejection-banner { background:#f8d7da; border:1px solid #f5c6cb; border-radius:8px; padding:12px 14px; margin-bottom:16px; display:flex; align-items:flex-start; gap:10px; font-size:13px; color:#721c24; }

@media (max-width:640px) {
    .summary-grid { grid-template-columns:1fr 1fr; }
    .del-table { font-size:12px; }
}

/* Protect txn-btn from global header button color override */
.txn-btn {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 6px !important;
    padding: 7px 14px !important;
    border-radius: 4px !important;
    font-size: 11px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    border: 1px solid transparent !important;
    transition: all .2s ease-in-out !important;
    text-decoration: none !important;
    white-space: nowrap !important;
    box-sizing: border-box !important;
    background-color: #ffffff !important;
    background: #ffffff !important;
}
.txn-btn.primary   { color:#00264D !important; background-color:#ffffff !important; background:#ffffff !important; border:1px solid #00264D !important; }
.txn-btn.primary:hover   { background-color:#00264D !important; background:#00264D !important; color:#ffffff !important; }
.txn-btn.secondary { color:#475569 !important; background-color:#ffffff !important; background:#ffffff !important; border:1px solid #475569 !important; }
.txn-btn.secondary:hover { background-color:#475569 !important; background:#475569 !important; color:#ffffff !important; }
.txn-btn.success   { color:#16a34a !important; background-color:#ffffff !important; background:#ffffff !important; border:1px solid #16a34a !important; }
.txn-btn.success:hover   { background-color:#16a34a !important; background:#16a34a !important; color:#ffffff !important; }
.txn-btn.warning   { color:#b45309 !important; background-color:#ffffff !important; background:#ffffff !important; border:1px solid #b45309 !important; }
.txn-btn.warning:hover   { background-color:#b45309 !important; background:#b45309 !important; color:#ffffff !important; }
.txn-btn.danger    { color:#dc2626 !important; background-color:#ffffff !important; background:#ffffff !important; border:1px solid #dc2626 !important; }
.txn-btn.danger:hover    { background-color:#dc2626 !important; background:#dc2626 !important; color:#ffffff !important; }
/* Header standardization */
.int-head { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px; margin-top:0px !important; }
.int-head h1 { font-size:22px !important; font-weight:700 !important; color:#002F70 !important; margin:0 !important; text-transform:uppercase !important; display:flex; align-items:center; gap:8px; }
.int-head .sub { font-size:13px; color:#64748b; margin-top:4px; }
</style>

<div class="stock-page">
<div class="int-head">
    <div>
        <h1><i class="fas fa-history"></i> Merchandise Delivery History</h1>
        <div class="sub">All merchandise deliveries for this station &mdash; Pending, Approved, Discrepancy, and Closed records.</div>
    </div>
    <div style="display:flex;gap:8px;align-items:center;"></div>
</div>

<?php if ($msg): ?>
<div class="alert-<?php echo $msg_type === 'success' ? 'success' : 'error'; ?>-del">
    <i class="fas fa-<?php echo $msg_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
    <div><?php echo $msg; ?></div>
</div>
<?php endif; ?>

<!-- ══ Summary Cards ══ -->
<div class="summary-grid">
    <div class="summary-card sc-pending">
        <div class="sc-icon"><i class="fas fa-clock"></i></div>
        <div class="sc-text">
            <div class="sc-num"><?php echo $counts['Pending']; ?></div>
            <div class="sc-label">Pending</div>
        </div>
    </div>
    <div class="summary-card sc-verified">
        <div class="sc-icon"><i class="fas fa-check-circle"></i></div>
        <div class="sc-text">
            <div class="sc-num"><?php echo $counts['Verified']; ?></div>
            <div class="sc-label">Verified</div>
        </div>
    </div>
    <div class="summary-card sc-rejected">
        <div class="sc-icon"><i class="fas fa-times-circle"></i></div>
        <div class="sc-text">
            <div class="sc-num"><?php echo $counts['Rejected']; ?></div>
            <div class="sc-label">Rejected</div>
        </div>
    </div>
    <div class="summary-card sc-total">
        <div class="sc-icon"><i class="fas fa-layer-group"></i></div>
        <div class="sc-text">
            <div class="sc-num"><?php echo $counts['Total']; ?></div>
            <div class="sc-label">Total</div>
        </div>
    </div>
</div>

<!-- Filters + Table -->
<div class="del-card">
    <div class="del-card-head">
        <div class="del-card-title"><i class="fas fa-boxes"></i> Merchandise Delivery Records</div>
        <span style="font-size:12px;color:#6c757d;"><?php echo count($deliveries); ?> record(s) &mdash; <span style="color:#002F70;font-weight:600;">Merchandise Only</span></span>
    </div>
    <div class="del-card-body">

        <form method="GET">
            <div class="filter-bar">
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="">All Statuses</option>
                        <option value="Pending Manager Approval" <?php echo $filter_status === 'Pending Manager Approval' ? 'selected' : ''; ?>>Pending Approval</option>
                        <option value="Confirmed"   <?php echo $filter_status === 'Confirmed'   ? 'selected' : ''; ?>>Approved</option>
                        <option value="Discrepancy" <?php echo $filter_status === 'Discrepancy' ? 'selected' : ''; ?>>Discrepancy / Rejected</option>
                        <option value="Closed"      <?php echo $filter_status === 'Closed'      ? 'selected' : ''; ?>>Closed</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Supplier</label>
                    <input type="text" name="supplier" placeholder="Search supplier..." value="<?php echo htmlspecialchars($filter_supplier); ?>">
                </div>
                <div class="form-group">
                    <label>From</label>
                    <input type="date" name="start" value="<?php echo htmlspecialchars($filter_start); ?>">
                </div>
                <div class="form-group">
                    <label>To</label>
                    <input type="date" name="end" value="<?php echo htmlspecialchars($filter_end); ?>">
                </div>
                <div class="form-group" style="justify-content:flex-end;">
                    <button type="submit" class="txn-btn primary">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                </div>
                <div class="form-group" style="justify-content:flex-end;">
                    <a href="staff_delivery_history.php" class="txn-btn secondary">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </div>
            </div>
        </form>

        <?php if (empty($deliveries)): ?>
        <div style="text-align:center;padding:48px 20px;color:#6c757d;">
            <i class="fas fa-truck" style="font-size:3rem;margin-bottom:12px;opacity:.3;display:block;"></i>
            <p style="font-size:15px;margin:0;">No delivery records found.</p>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="del-table">
                <thead>
                    <tr>
                        <th>Delivery ID</th>
                        <th>Date</th>
                        <th>DR No.</th>
                        <th>Supplier</th>
                        <th>Item</th>
                        <th>Qty</th>
                        <th>Status</th>
                        <th style="text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($deliveries as $d):
                    $status      = $d['status'];
                    $is_pending  = in_array($status, ['Pending Manager Approval', 'Pending Manager Confirmation', 'Pending Validation', 'Pending Verification', 'Pending Admin Oversight']);
                    $is_discrepancy = in_array($status, ['Discrepancy', 'Pending Resolution', 'Awaiting Replacement', 'Returned to Supplier', 'Rejected', 'Rejected Delivery', 'Returned', 'Returned to Staff', 'Flagged']);
                    $is_approved = in_array($status, ['Confirmed', 'Approved', 'Validated', 'Verified', 'Ready for Stock-In', 'Adjusted', 'Stock-In Complete', 'Partial Delivery', 'Damaged Items']);
                    $is_closed   = ($status === 'Closed');

                    if ($is_pending) {
                        $badge_class = 'badge-pending';
                        $badge_label = ($status === 'Pending Manager Confirmation') ? 'Pending Confirmation' : (($status === 'Pending Validation' || $status === 'Pending Verification' || $status === 'Pending Admin Oversight') ? 'Pending Verification' : 'Pending Approval');
                    } elseif ($status === 'Pending Resolution') {
                        $badge_class = 'badge-rejected';
                        $badge_label = 'Pending Resolution';
                    } elseif ($status === 'Awaiting Replacement') {
                        $badge_class = 'badge-pending';
                        $badge_label = 'Awaiting Replacement';
                    } elseif ($status === 'Returned to Supplier') {
                        $badge_class = 'badge-closed';
                        $badge_label = 'Returned to Supplier';
                    } elseif ($status === 'Returned' || $status === 'Returned to Staff') {
                        $badge_class = 'badge-rejected';
                        $badge_label = 'Returned to Staff';
                    } elseif ($status === 'Adjusted') {
                        $badge_class = 'badge-approved';
                        $badge_label = 'Adjusted';
                    } elseif ($status === 'Stock-In Complete') {
                        $badge_class = 'badge-approved';
                        $badge_label = 'Stock-In Complete';
                    } elseif ($status === 'Partial Delivery') {
                        $badge_class = 'badge-approved';
                        $badge_label = 'Partial Delivery';
                    } elseif ($status === 'Damaged Items') {
                        $badge_class = 'badge-rejected';
                        $badge_label = 'Damaged Items';
                    } elseif ($status === 'Rejected Delivery' || $status === 'Rejected') {
                        $badge_class = 'badge-rejected';
                        $badge_label = 'Rejected';
                    } elseif ($is_approved) {
                        $badge_class = 'badge-approved';
                        $badge_label = 'Approved';
                    } else {
                        $badge_class = 'badge-closed';
                        $badge_label = 'Closed';
                    }

                    $row_class = $is_discrepancy ? 'row-rejected' : '';
                ?>
                    <tr class="<?php echo $row_class; ?>">
                        <td>
                            <strong style="font-family:monospace;font-size:12px;color:#002F70;"><?php echo htmlspecialchars($d['delivery_ref']); ?></strong>
                            <div style="font-size:10px;color:#adb5bd;margin-top:2px;font-family:monospace;"><?php echo htmlspecialchars($d['batch_id'] ?? ''); ?></div>
                        </td>
                        <td style="white-space:nowrap;"><?php echo date('M j, Y', strtotime($d['delivery_date'])); ?></td>
                        <td style="font-size:12px;color:#6c757d;"><?php echo $d['dr_number'] ? htmlspecialchars($d['dr_number']) : '<span style="color:#dee2e6;">—</span>'; ?></td>
                        <td><?php echo htmlspecialchars($d['supplier']); ?></td>
                        <td>
                            <div style="font-weight:600;font-size:13px;"><?php echo htmlspecialchars($d['product']); ?></div>
                            <div style="font-size:11px;color:#6c757d;"><?php echo htmlspecialchars($d['category'] ?? ''); ?></div>
                        </td>
                        <td style="white-space:nowrap;"><?php echo number_format((float)$d['quantity'], 2); ?> <span style="color:#6c757d;font-size:11px;"><?php echo htmlspecialchars($d['unit']); ?></span></td>
                        <td>
                            <span class="<?php echo $badge_class; ?>"><?php echo $badge_label; ?></span>
                            <?php if ($is_discrepancy && !empty($d['admin_notes'])): ?>
                            <div class="rejection-note">
                                <i class="fas fa-exclamation-circle" style="margin-top:1px;flex-shrink:0;"></i>
                                <span><?php echo htmlspecialchars(mb_strimwidth($d['admin_notes'], 0, 55, '…')); ?></span>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                            <button class="btn-sm-view" onclick="viewDelivery(<?php echo (int)$d['id']; ?>)" title="View details">
                                <i class="fas fa-eye"></i> View
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- View Modal -->
<div class="modal-overlay" id="viewModal">
    <div class="modal-box">
        <div class="modal-title"><i class="fas fa-file-invoice"></i> Delivery Details</div>
        <div id="viewModalContent"></div>
        <div class="modal-actions" id="viewModalActions">
            <button class="btn-cancel-del" onclick="closeModal('viewModal')">Close</button>
        </div>
    </div>
</div>

<script>
const deliveryData = <?php echo json_encode(array_column($deliveries, null, 'id'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

const STATUS_LABELS = {
    'Pending Manager Approval':     'Pending Approval',
    'Pending Manager Confirmation': 'Pending Confirmation',
    'Pending Validation':           'Pending Verification',
    'Pending Verification':         'Pending Verification',
    'Pending Admin Oversight':      'Pending Oversight',
    'Confirmed':   'Approved',
    'Approved':    'Approved',
    'Validated':   'Approved',
    'Verified':    'Approved',
    'Ready for Stock-In': 'Ready for Stock-In',
    'Adjusted':    'Adjusted',
    'Stock-In Complete': 'Stock-In Complete',
    'Partial Delivery': 'Partial Delivery',
    'Damaged Items': 'Damaged Items',
    'Rejected Delivery': 'Rejected',
    'Returned':    'Returned to Staff',
    'Returned to Staff': 'Returned to Staff',
    'Discrepancy': 'Rejected',
    'Pending Resolution':   'Pending Resolution',
    'Awaiting Replacement': 'Awaiting Replacement',
    'Returned to Supplier': 'Returned to Supplier',
    'Closed':      'Closed',
};
const STATUS_COLORS = {
    'Pending Manager Approval':     '#856404',
    'Pending Manager Confirmation': '#856404',
    'Pending Validation':           '#856404',
    'Pending Verification':         '#856404',
    'Pending Admin Oversight':      '#856404',
    'Confirmed':   '#155724',
    'Approved':    '#155724',
    'Validated':   '#155724',
    'Verified':    '#155724',
    'Ready for Stock-In': '#155724',
    'Adjusted':    '#0c5460',
    'Stock-In Complete': '#155724',
    'Partial Delivery': '#155724',
    'Damaged Items': '#721c24',
    'Rejected Delivery': '#721c24',
    'Returned':    '#721c24',
    'Returned to Staff': '#721c24',
    'Discrepancy': '#721c24',
    'Pending Resolution':   '#7d4e00',
    'Awaiting Replacement': '#004085',
    'Returned to Supplier': '#383d41',
    'Closed':      '#383d41',
};

function viewDelivery(id) {
    const d = deliveryData[id];
    if (!d) return;

    const label      = STATUS_LABELS[d.status] || d.status;
    const color      = STATUS_COLORS[d.status] || '#333';
    const isRejected = (d.status === 'Discrepancy' || d.status === 'Rejected');
    const isDiscrepancy = ['Discrepancy','Pending Resolution','Awaiting Replacement','Returned to Supplier','Rejected','Rejected Delivery','Returned','Returned to Staff','Flagged'].includes(d.status);

    let html = '';

    if (isDiscrepancy) {
        const discMsg = {
            'Discrepancy':          'This delivery was Rejected by the Manager.',
            'Pending Resolution':   'Discrepancy flagged — Pending Resolution. Manager is deciding the action.',
            'Awaiting Replacement': 'Awaiting replacement delivery from supplier.',
            'Returned to Supplier': 'Items returned to supplier.',
        }[d.status] || 'Discrepancy flagged.';
        html += '<div class="rejection-banner">'
              + '<i class="fas fa-exclamation-triangle" style="margin-top:2px;flex-shrink:0;font-size:16px;"></i>'
              + '<div><strong>' + escHtml(discMsg) + '</strong>'
              + (d.admin_notes ? '<br><span style="margin-top:4px;display:block;">Manager note: ' + escHtml(d.admin_notes) + '</span>' : '')
              + (d.status === 'Discrepancy' ? '<br><span style="font-size:12px;">Please correct the details and resubmit.</span>' : '')
              + '</div></div>';
    }

    html += '<table class="detail-table">'
          + '<tr><td>Delivery ID</td><td><strong style="font-family:monospace;">' + escHtml(d.delivery_ref) + '</strong></td></tr>'
          + '<tr><td>Batch ID</td><td><strong style="font-family:monospace;color:#002F70;">' + escHtml(d.batch_id || '—') + '</strong></td></tr>'
          + '<tr><td>Date Received</td><td>' + escHtml(d.delivery_date) + '</td></tr>'
          + '<tr><td>DR / Invoice No.</td><td>' + (d.dr_number ? escHtml(d.dr_number) : '—') + '</td></tr>'
          + '<tr><td>Supplier</td><td>' + escHtml(d.supplier) + '</td></tr>'
          + '<tr><td>Item / Product</td><td>' + escHtml(d.product) + '</td></tr>'
          + '<tr><td>Category</td><td>' + (d.category ? escHtml(d.category) : '—') + '</td></tr>'
          + '<tr><td>Quantity</td><td>' + parseFloat(d.quantity).toFixed(2) + ' ' + escHtml(d.unit) + '</td></tr>'
          + '<tr><td>Unit Cost</td><td>' + (d.unit_cost ? '₱' + parseFloat(d.unit_cost).toFixed(2) : '—') + '</td></tr>'
          + '<tr><td>Expiry Date</td><td>' + (d.expiry_date ? escHtml(d.expiry_date) : '—') + '</td></tr>'
          + '<tr><td>Status</td><td><strong style="color:' + color + ';">' + escHtml(label) + '</strong></td></tr>'
          + '<tr><td>Received By</td><td>' + (d.received_by_name ? escHtml(d.received_by_name) : (d.encoded_by_name ? escHtml(d.encoded_by_name) : '—')) + '</td></tr>'
          + '<tr><td>Remarks</td><td>' + (d.remarks ? escHtml(d.remarks) : '—') + '</td></tr>'
          + '<tr><td>Recorded At</td><td>' + escHtml(d.created_at) + '</td></tr>'
          + '</table>';

    document.getElementById('viewModalContent').innerHTML = html;

    const actions = document.getElementById('viewModalActions');
    actions.innerHTML = '';
    if (isRejected) {
        actions.innerHTML += '<a href="staff_record_delivery.php?edit=' + id + '" class="btn-resubmit-modal">'
                           + '<i class="fas fa-redo"></i> Edit &amp; Resubmit</a>';
    }
    actions.innerHTML += '<button class="btn-sm-print" onclick="printDelivery(' + id + ')" style="padding:8px 16px;"><i class="fas fa-print"></i> Print</button>';
    actions.innerHTML += '<button class="btn-cancel-del" onclick="closeModal(\'viewModal\')">Close</button>';

    document.getElementById('viewModal').classList.add('show');
}

function escHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function printDelivery(id) {
    const d = deliveryData[id];
    if (!d) return;
    const label = STATUS_LABELS[d.status] || d.status;
    const win = window.open('', '_blank', 'width=750,height=900');
    win.document.write(`
<!DOCTYPE html><html><head><title>Delivery Record — ${escHtml(d.delivery_ref)}</title>
<style>
  body { font-family: Arial, sans-serif; font-size: 13px; color: #212529; padding: 32px; }
  h2 { color: #002F70; margin: 0 0 4px; font-size: 18px; }
  .sub { color: #6c757d; font-size: 12px; margin-bottom: 20px; }
  .logo-bar { display:flex; align-items:center; gap:12px; margin-bottom:20px; border-bottom:2px solid #002F70; padding-bottom:14px; }
  .logo-bar h1 { margin:0; font-size:16px; color:#002F70; }
  .logo-bar small { display:block; font-size:11px; color:#6c757d; }
  table { width:100%; border-collapse:collapse; margin-top:12px; }
  td { padding:8px 10px; border-bottom:1px solid #e9ecef; }
  td:first-child { color:#6c757d; width:160px; font-weight:500; }
  .badge { display:inline-block; padding:3px 10px; border-radius:12px; font-size:12px; font-weight:700; background:#fff3cd; color:#856404; }
  .footer { margin-top:28px; padding-top:12px; border-top:1px solid #dee2e6; font-size:11px; color:#adb5bd; text-align:center; }
  @media print { body { padding:16px; } }
</style>
</head><body>
<div class="logo-bar">
  <div>
    <h1>Petron Station Management System</h1>
    <small>Merchandise Delivery Record</small>
  </div>
</div>
<h2>${escHtml(d.delivery_ref)}</h2>
<div class="sub">Batch: ${escHtml(d.batch_id || '—')} &nbsp;&bull;&nbsp; Printed: ${new Date().toLocaleString()}</div>
<table>
  <tr><td>Date Received</td><td>${escHtml(d.delivery_date)}</td></tr>
  <tr><td>DR / Invoice No.</td><td>${d.dr_number ? escHtml(d.dr_number) : '—'}</td></tr>
  <tr><td>Supplier</td><td>${escHtml(d.supplier)}</td></tr>
  <tr><td>Item / Product</td><td>${escHtml(d.product)}</td></tr>
  <tr><td>Category</td><td>${d.category ? escHtml(d.category) : '—'}</td></tr>
  <tr><td>Quantity Delivered</td><td>${parseFloat(d.quantity).toFixed(2)} ${escHtml(d.unit)}</td></tr>
  <tr><td>Unit Cost</td><td>${d.unit_cost ? '₱' + parseFloat(d.unit_cost).toFixed(2) : '—'}</td></tr>
  <tr><td>Expiry Date</td><td>${d.expiry_date ? escHtml(d.expiry_date) : 'N/A'}</td></tr>
  <tr><td>Status</td><td><span class="badge">${escHtml(label)}</span></td></tr>
  <tr><td>Received By</td><td>${d.received_by_name ? escHtml(d.received_by_name) : (d.encoded_by_name ? escHtml(d.encoded_by_name) : '—')}</td></tr>
  <tr><td>Remarks</td><td>${d.remarks ? escHtml(d.remarks) : '—'}</td></tr>
  <tr><td>Recorded At</td><td>${escHtml(d.created_at)}</td></tr>
</table>
<div class="footer">Generated by Petron Station Management System &mdash; Staff Record</div>
</body></html>`);
    win.document.close();
    win.focus();
    setTimeout(() => win.print(), 600);
}

function closeModal(id) {
    document.getElementById(id).classList.remove('show');
}

document.querySelectorAll('.modal-overlay').forEach(function(el) {
    el.addEventListener('click', function(e) {
        if (e.target === this) closeModal(this.id);
    });
});
</script>

</div>
<?php include __DIR__ . '/../partials/footer.php'; ?>
