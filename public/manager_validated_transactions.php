<?php
/**
 * MANAGER VALIDATED TRANSACTIONS
 * 
 * Shows ONLY transactions with validation_status = 'Approved'
 * Manager can view approved transactions and their details
 * Uses NEW tables: merchandise_transactions, job_orders
 * Design: Petron Blue (#002F70)
 */
$page_id = 'validated_transactions_manager';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/transaction_schema_fix.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();

// Only Manager/Admin can access
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Manager role required.';
    header('Location: staff_dashboard.php'); exit;
}

// ── Dynamic column detection ──────────────────────────────────────────────────
function vt_cols(PDO $pdo, string $table): array {
    try {
        $rows = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $r) $map[strtolower($r['Field'])] = true;
        return $map;
    } catch (Exception $e) { return []; }
}
function vt_has(array $map, string $col): bool { return isset($map[strtolower($col)]); }

$mt_cols = vt_cols($pdo, 'merchandise_transactions');
$jo_cols = vt_cols($pdo, 'job_orders');

// ── Payment status helper ─────────────────────────────────────────────────────
function vt_pay_status(array $row): string {
    $total = (float)($row['amount'] ?? 0);
    $paid  = isset($row['amount_paid']) ? (float)$row['amount_paid'] : null;
    if ($paid === null) {
        $pm = strtolower(trim($row['payment_method'] ?? ''));
        return ($pm !== '' && $pm !== 'n/a') ? 'Paid' : 'Unpaid';
    }
    if ($paid <= 0)            return 'Unpaid';
    if ($paid < $total - 0.01) return 'Partial';
    return 'Paid';
}

// ── Filters ───────────────────────────────────────────────────────────────────
$search = trim($_GET['search'] ?? '');
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// ── Fetch APPROVED Merchandise + Job Orders ───────────────────────────────────
$rows = [];
$total_amount = 0.0;

// Merchandise APPROVED transactions
$mt_status_col = vt_has($mt_cols, 'validation_status') ? 'mt.validation_status' : "'Approved'";
$mt_staff_col  = vt_has($mt_cols, 'staff_id') ? "COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown')" : "'Unknown'";
$mt_date_col   = "CASE WHEN mt.transaction_date > '2000-01-01' THEN mt.transaction_date ELSE mt.created_at END";
$mt_paid_col   = vt_has($mt_cols, 'amount_paid') ? 'mt.amount_paid' : 'NULL';
$mt_vby_col    = vt_has($mt_cols, 'validated_by') ? "COALESCE(NULLIF(CONCAT(v.first_name,' ',v.last_name),' '), v.username, 'N/A')" : "'N/A'";

$mt_where = "WHERE mt.station_id = ? AND LOWER(TRIM(COALESCE(mt.validation_status,''))) = 'approved'";
$mt_params = [$station_id];
if ($search !== '') {
    $mt_where .= " AND (mt.customer_name LIKE ? OR mt.transaction_id LIKE ?)";
    $mt_params[] = "%$search%"; $mt_params[] = "%$search%";
}
if ($date_from !== '') {
    $mt_where .= " AND {$mt_date_col} >= ?";
    $mt_params[] = $date_from;
}
if ($date_to !== '') {
    $mt_where .= " AND {$mt_date_col} <= ?";
    $mt_params[] = $date_to;
}

$mt_rows = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            mt.id AS row_id,
            mt.transaction_id AS txn_id,
            COALESCE(NULLIF(TRIM(mt.customer_name),''),'Walk-in') AS customer,
            'Merchandise' AS entry_type,
            COALESCE(mt.item_sku, 'N/A') AS items_service,
            mt.total_amount AS amount,
            {$mt_paid_col} AS amount_paid,
            COALESCE(mt.payment_method,'Cash') AS payment_method,
            {$mt_date_col} AS txn_date,
            COALESCE({$mt_status_col},'Approved') AS validation_status,
            COALESCE({$mt_staff_col},'Unknown') AS staff_name,
            COALESCE({$mt_vby_col},'N/A') AS validated_by,
            'merchandise_transactions' AS _source
        FROM merchandise_transactions mt
        LEFT JOIN users u ON u.id = mt.staff_id
        LEFT JOIN users v ON v.id = mt.validated_by
        {$mt_where}
        ORDER BY txn_date DESC
        LIMIT 500
    ");
    $stmt->execute($mt_params);
    $mt_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $mt_rows = []; }

// Job Orders APPROVED
$jo_status_col = vt_has($jo_cols, 'validation_status') ? 'jo.validation_status' : 'jo.status';
$jo_staff_col  = vt_has($jo_cols, 'created_by') ? 'COALESCE(jo.created_by, jo.user_id)' : 'jo.user_id';
$jo_pay_col    = vt_has($jo_cols, 'payment_method') ? 'COALESCE(jo.payment_method,\'N/A\')' : "'N/A'";
$jo_cost_col   = vt_has($jo_cols, 'total_cost') ? 'COALESCE(jo.total_cost,0)' : 'COALESCE(jo.estimated_cost,0)';
$jo_paid_col   = vt_has($jo_cols, 'amount_paid') ? 'jo.amount_paid' : 'NULL';
$jo_vby_col    = vt_has($jo_cols, 'validated_by') ? "COALESCE(NULLIF(CONCAT(v.first_name,' ',v.last_name),' '), v.username, 'N/A')" : "'N/A'";

$jo_where = "WHERE jo.station_id = ? AND LOWER(TRIM(COALESCE({$jo_status_col},''))) = 'approved'";
$jo_params = [$station_id];
if ($search !== '') {
    $jo_where .= " AND (jo.customer_name LIKE ? OR jo.service_type LIKE ? OR jo.vehicle_plate LIKE ?)";
    $jo_params[] = "%$search%"; $jo_params[] = "%$search%"; $jo_params[] = "%$search%";
}
if ($date_from !== '') {
    $jo_where .= " AND jo.created_at >= ?";
    $jo_params[] = $date_from;
}
if ($date_to !== '') {
    $jo_where .= " AND jo.created_at <= ?";
    $jo_params[] = $date_to;
}

$jo_rows = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            jo.id AS row_id,
            CONCAT('JO-', jo.id) AS txn_id,
            COALESCE(NULLIF(TRIM(jo.customer_name),''),'Walk-in') AS customer,
            'Job Order' AS entry_type,
            CONCAT(COALESCE(jo.service_type,'Service'), 
                   CASE WHEN jo.vehicle_plate IS NOT NULL AND jo.vehicle_plate != '' 
                        THEN CONCAT(' | ', jo.vehicle_plate) ELSE '' END) AS items_service,
            {$jo_cost_col} AS amount,
            {$jo_paid_col} AS amount_paid,
            {$jo_pay_col} AS payment_method,
            jo.created_at AS txn_date,
            COALESCE(NULLIF(TRIM({$jo_status_col}),''),'Approved') AS validation_status,
            COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown') AS staff_name,
            COALESCE({$jo_vby_col},'N/A') AS validated_by,
            'job_orders' AS _source
        FROM job_orders jo
        LEFT JOIN users u ON u.id = COALESCE(jo.created_by, jo.user_id)
        LEFT JOIN users v ON v.id = jo.validated_by
        {$jo_where}
        ORDER BY jo.created_at DESC
        LIMIT 500
    ");
    $stmt->execute($jo_params);
    $jo_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $jo_rows = []; }

// Merge and sort
$rows = array_merge($mt_rows, $jo_rows);
usort($rows, fn($a, $b) => strtotime($b['txn_date']) - strtotime($a['txn_date']));
foreach ($rows as $r) $total_amount += (float)($r['amount'] ?? 0);

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:18px;">
    <div style="flex:1;">
        <h1 class="h1" style="margin:0 0 4px 0;">Validated Transactions</h1>
        <div class="sub">Approved merchandise and job orders with updated balances.</div>
    </div>
    
    <!-- Export & Back Buttons (Header Right) -->
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <button type="button" onclick="exportTable('excel')" title="Export to Excel"
                style="background:#1d6f42;color:#fff;height:36px;padding:8px 14px;border-radius:8px;border:none;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
            <i class="fas fa-file-excel"></i> Excel
        </button>
        <button type="button" onclick="exportTable('csv')" title="Export to CSV"
                style="background:#003d7a;color:#fff;height:36px;padding:8px 14px;border-radius:8px;border:none;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
            <i class="fas fa-file-csv"></i> CSV
        </button>
        <button type="button" onclick="exportTable('pdf')" title="Export to PDF"
                style="background:#dc2626;color:#fff;height:36px;padding:8px 14px;border-radius:8px;border:none;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
            <i class="fas fa-file-pdf"></i> PDF
        </button>
        <a href="<?= in_array($role, ['admin', 'superadmin']) ? 'admin_dashboard.php' : 'manager_dashboard.php'; ?>"
           style="background:#6c757d;color:#fff;text-decoration:none;height:36px;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:600;display:inline-flex;align-items:center;gap:6px;">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>

</div>

<?php if (isset($_SESSION['success'])): ?>
<div style="background:#d4edda;color:#155724;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
</div>
<?php endif; ?>
<?php if (isset($_SESSION['error'])): ?>
<div style="background:#f8d7da;color:#721c24;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
</div>
<?php endif; ?>

<!-- Filter Bar -->
<div class="vt-filter-card">
    <form method="get" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
        <div class="vt-flt-grp">
            <label class="vt-lbl"><i class="fas fa-search"></i> Search</label>
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                   class="vt-inp" placeholder="Transaction ID, customer..." style="width:250px;">
        </div>
        <div class="vt-flt-grp">
            <label class="vt-lbl"><i class="fas fa-calendar"></i> From</label>
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="vt-inp">
        </div>
        <div class="vt-flt-grp">
            <label class="vt-lbl"><i class="fas fa-calendar"></i> To</label>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="vt-inp">
        </div>
        <div style="align-self:flex-end;display:flex !important;flex-direction:row !important;gap:8px;">
            <button type="submit" class="vt-btn vt-btn-search"><i class="fas fa-search"></i> Search</button>
            <a href="?" class="vt-btn vt-btn-reset"><i class="fas fa-rotate-left"></i> Reset</a>
        </div>
    </form>
</div>

<!-- Summary -->
<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px 16px;margin-bottom:16px;display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
    <div style="font-size:14px;color:#64748b;">
        <strong style="color:#1e293b;"><?php echo count($rows); ?></strong> validated transaction(s)
    </div>
    <div style="font-size:14px;color:#64748b;">
        Total Amount: <strong style="color:#002F70;">₱<?php echo number_format($total_amount, 2); ?></strong>
    </div>
</div>

<!-- Table -->
<div class="card" style="padding:0;overflow-x:auto;">
    <table class="vt-table" style="table-layout:auto;width:100%;">
        <colgroup>
            <col style="width:8%;"><!-- Transaction ID -->
            <col style="width:9%;"><!-- Customer -->
            <col style="width:7%;"><!-- Type -->
            <col style="width:14%;"><!-- Items / Service -->
            <col style="width:8%;"><!-- Amount -->
            <col style="width:8%;"><!-- Payment Method -->
            <col style="width:9%;"><!-- Payment Status -->
            <col style="width:11%;"><!-- Date / Time -->
            <col style="width:9%;"><!-- Staff -->
            <col style="width:9%;"><!-- Validated By -->
            <col style="width:8%;"><!-- Actions -->
        </colgroup>
        <thead>
            <tr>
                <th style="font-size:13px;">Txn ID</th>
                <th style="font-size:13px;">Customer</th>
                <th style="font-size:13px;">Type</th>
                <th style="font-size:13px;">Items / Service</th>
                <th style="text-align:right;font-size:13px;">Amount</th>
                <th style="font-size:13px;">Method</th>
                <th style="font-size:13px;">Status</th>
                <th style="font-size:13px;">Date</th>
                <th style="font-size:13px;">Staff</th>
                <th style="font-size:13px;">Validated</th>
                <th style="text-align:center;font-size:13px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($rows) > 0): ?>
                <?php foreach ($rows as $r): ?>
                <?php $pay_st = vt_pay_status($r); ?>
                <tr>
                    <td style="font-weight:600;font-size:13px;font-family:monospace;white-space:nowrap;">
                        <?php echo htmlspecialchars($r['txn_id']); ?>
                    </td>
                    <td style="font-size:13px;" title="<?php echo htmlspecialchars($r['customer']); ?>"><?php echo htmlspecialchars($r['customer']); ?></td>
                    <td>
                        <span class="vt-badge vt-badge-type">
                            <?php echo htmlspecialchars($r['entry_type']); ?>
                        </span>
                    </td>
                    <td style="font-size:13px;"
                        title="<?php echo htmlspecialchars($r['items_service']); ?>">
                        <?php echo htmlspecialchars($r['items_service']); ?>
                    </td>
                    <td style="font-weight:600;color:#002F70;text-align:right;white-space:nowrap;">
                        &#8369;<?php echo number_format((float)$r['amount'], 2); ?>
                    </td>
                    <td style="font-size:13px;"><?php echo htmlspecialchars($r['payment_method']); ?></td>
                    <td>
                        <span class="vt-badge vt-badge-<?php echo strtolower(str_replace(' ', '-', $pay_st)); ?>">
                            <?php echo $pay_st; ?>
                        </span>
                    </td>
                    <td style="white-space:nowrap;font-size:13px;color:#64748b;">
                        <?php echo date('M d, Y H:i', strtotime($r['txn_date'])); ?>
                    </td>
                    <td style="font-size:13px;color:#64748b;"><?php echo htmlspecialchars($r['staff_name']); ?></td>
                    <td style="font-size:13px;color:#64748b;"><?php echo htmlspecialchars($r['validated_by']); ?></td>
                    <td style="text-align:center;padding:8px 4px;">
                        <button class="vt-btn-action vt-btn-view" onclick="viewValidatedTransaction('<?php echo $r['_source']; ?>', <?php echo $r['row_id']; ?>)" title="View transaction details" style="padding:6px 10px;font-size:12px;">
                            <i class="fas fa-eye"></i> View
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="11" style="text-align:center;padding:60px 20px;color:#94a3b8;">
                        <i class="fas fa-inbox" style="font-size:48px;display:block;margin-bottom:12px;opacity:0.3;"></i>
                        <div style="font-size:16px;font-weight:600;color:#64748b;margin-bottom:4px;">No Validated Transactions</div>
                        <div style="font-size:13px;">No approved transactions found matching your filters.</div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- View Transaction Modal -->
<div class="vt-modal-overlay" id="viewTransactionModal">
    <div class="vt-modal" style="max-width:700px;">
        <div class="vt-modal-header">
            <h3><i class="fas fa-eye" style="color:#003d82;margin-right:8px;"></i>Transaction Details</h3>
            <button class="vt-modal-close" onclick="closeViewModal()">&times;</button>
        </div>
        <div id="viewTransactionContent" class="vt-modal-body">
            <div style="text-align:center;padding:40px;">
                <i class="fas fa-spinner fa-spin" style="font-size:32px;color:#003d82;"></i>
                <div style="margin-top:12px;color:#64748b;">Loading transaction details...</div>
            </div>
        </div>
        <div class="vt-modal-footer">
            <button type="button" class="vt-btn vt-btn-reset" onclick="closeViewModal()">Close</button>
        </div>
    </div>
</div>

<style>
/* Filter Card */
.vt-filter-card { 
    background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px 18px;margin-bottom:14px;box-shadow:0 1px 4px rgba(0,0,0,.05);
}
.vt-flt-grp { display:flex;flex-direction:column;gap:4px; }
.vt-lbl { font-size:14px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px; }
.vt-inp { 
    height:40px;padding:0 12px;border:1px solid #cbd5e1;border-radius:7px;font-size:14px;color:#1e293b;background:#fff;outline:none;box-sizing:border-box; 
}
.vt-inp:focus { border-color:#002F70;box-shadow:0 0 0 3px rgba(0,47,112,.1); }
.vt-btn { 
    display:inline-flex;align-items:center;gap:6px;padding:0 18px;height:40px;border:none;border-radius:7px;font-size:14px;font-weight:600;cursor:pointer;text-decoration:none;white-space:nowrap;transition:filter .15s; 
}
.vt-btn:hover { filter:brightness(.88); }
.vt-btn-search { background:#002F70;color:#fff; }
.vt-btn-reset  { background:#64748b;color:#fff; }

/* Page Head Layout */
.page-head { display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:20px; }

/* Table */
.vt-table { width:100%;border-collapse:collapse;font-size:14px; }
.vt-table thead th { 
    background:#002F70;color:#fff;font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:12px 10px;border-bottom:2px solid #001a3d;text-align:left;
}
.vt-table tbody td { padding:10px;border-bottom:1px solid #f1f5f9;vertical-align:middle;background:#fff;font-size:13px; }
.vt-table tbody tr:hover td { background:#eff6ff; }

/* Badges */
.vt-badge { 
    display:inline-block;padding:4px 12px;border-radius:4px;font-size:12px;font-weight:600;white-space:nowrap;background:#f8fafc;color:#64748b;border:1px solid #e2e8f0;
}
.vt-badge-type { background:#f1f5f9;color:#475569;border-color:#cbd5e1; }
.vt-badge-paid { background:#f0fdf4;color:#166534;border-color:#bbf7d0; }
.vt-badge-partial { background:#fef3c7;color:#92400e;border-color:#fde047; }
.vt-badge-unpaid { background:#fef2f2;color:#991b1b;border-color:#fecaca; }

/* Action Buttons - View & Export */
.vt-btn-action { 
    color:#fff;
    width:auto;
    min-width:90px;
    height:38px;
    border-radius:8px;
    border:none;
    cursor:pointer;
    font-size:13px;
    font-weight:600;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    transition:all .15s;
    padding:0 14px;
}
.vt-btn-action:hover { 
    transform:translateY(-2px);
    box-shadow:0 4px 12px rgba(0,0,0,.2);
    filter:brightness(1.05);
}
.vt-btn-action:active {
    transform:translateY(0);
}
.vt-btn-view { background:#003d82; }    /* Navy Blue */
.vt-btn-export { background:#28a745; }  /* Green */

/* View Modal */
.vt-modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:9999; align-items:center; justify-content:center; }
.vt-modal-overlay.active { display:flex; }
.vt-modal { background:#fff; border-radius:12px; width:100%; max-width:700px; box-shadow:0 8px 40px rgba(0,0,0,.2); max-height:90vh; display:flex; flex-direction:column; }
.vt-modal-header { display:flex; align-items:center; justify-content:space-between; padding:20px 24px; border-bottom:1px solid #e2e8f0; }
.vt-modal-header h3 { margin:0; font-size:18px; font-weight:700; color:#1e293b; display:flex; align-items:center; }
.vt-modal-close { background:none; border:none; font-size:28px; color:#64748b; cursor:pointer; padding:0; width:32px; height:32px; border-radius:6px; }
.vt-modal-close:hover { background:#f1f5f9; color:#1e293b; }
.vt-modal-body { padding:24px; overflow-y:auto; flex:1; }
.vt-modal-footer { padding:16px 24px; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:8px; }
.vt-detail-grid { display:grid; grid-template-columns:140px 1fr; gap:12px 20px; font-size:14px; }
.vt-detail-label { font-weight:600; color:#64748b; }
.vt-detail-value { color:#1e293b; }
.vt-detail-amount { color:#002F70; font-weight:700; font-size:16px; }
</style>

<script>
function viewValidatedTransaction(source, id) {
    // Open modal
    document.getElementById('viewTransactionModal').classList.add('active');
    document.getElementById('viewTransactionContent').innerHTML = '<div style="text-align:center;padding:40px;"><i class="fas fa-spinner fa-spin" style="font-size:32px;color:#003d82;"></i><div style="margin-top:12px;color:#64748b;">Loading...</div></div>';
    
    // Fetch transaction details
    fetch('../backend/get_transaction_details.php?type=' + source + '&id=' + id)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let html = '<div class="vt-detail-grid">';
                
                if (data.type === 'merchandise') {
                    // Merchandise transaction details
                    html += '<div class="vt-detail-label">Transaction ID:</div><div class="vt-detail-value" style="font-family:monospace;font-weight:600;">' + data.transaction_id + '</div>';
                    html += '<div class="vt-detail-label">Customer:</div><div class="vt-detail-value">' + data.customer_name + '</div>';
                    html += '<div class="vt-detail-label">Item SKU:</div><div class="vt-detail-value">' + data.item_sku + '</div>';
                    html += '<div class="vt-detail-label">Quantity:</div><div class="vt-detail-value">' + data.quantity + '</div>';
                    html += '<div class="vt-detail-label">Unit Price:</div><div class="vt-detail-value">₱' + data.unit_price + '</div>';
                    html += '<div class="vt-detail-label">Total Amount:</div><div class="vt-detail-amount">₱' + data.total_amount + '</div>';
                    html += '<div class="vt-detail-label">Payment Method:</div><div class="vt-detail-value">' + data.payment_method + '</div>';
                    if (data.amount_tendered !== 'N/A') {
                        html += '<div class="vt-detail-label">Amount Tendered:</div><div class="vt-detail-value">₱' + data.amount_tendered + '</div>';
                        html += '<div class="vt-detail-label">Change:</div><div class="vt-detail-value">₱' + data.change_amount + '</div>';
                    }
                    html += '<div class="vt-detail-label">Transaction Date:</div><div class="vt-detail-value">' + data.transaction_date + '</div>';
                    html += '<div class="vt-detail-label">Staff:</div><div class="vt-detail-value">' + data.staff_name + '</div>';
                    html += '<div class="vt-detail-label">Status:</div><div class="vt-detail-value"><span style="background:#f0fdf4;color:#166534;padding:4px 12px;border-radius:4px;font-size:12px;font-weight:600;">' + data.validation_status + '</span></div>';
                    html += '<div class="vt-detail-label">Validated By:</div><div class="vt-detail-value">' + data.validated_by + '</div>';
                    html += '<div class="vt-detail-label">Validated At:</div><div class="vt-detail-value">' + data.validated_at + '</div>';
                    if (data.shift !== 'N/A') {
                        html += '<div class="vt-detail-label">Shift:</div><div class="vt-detail-value">' + data.shift + '</div>';
                    }
                    if (data.remarks !== 'N/A') {
                        html += '<div class="vt-detail-label">Remarks:</div><div class="vt-detail-value">' + data.remarks + '</div>';
                    }
                } else if (data.type === 'job_order') {
                    // Job order details
                    html += '<div class="vt-detail-label">Job Order #:</div><div class="vt-detail-value" style="font-family:monospace;font-weight:600;">' + data.transaction_id + '</div>';
                    html += '<div class="vt-detail-label">Customer:</div><div class="vt-detail-value">' + data.customer_name + '</div>';
                    html += '<div class="vt-detail-label">Vehicle Plate:</div><div class="vt-detail-value">' + data.vehicle_plate + '</div>';
                    html += '<div class="vt-detail-label">Vehicle Type:</div><div class="vt-detail-value">' + data.vehicle_type + '</div>';
                    html += '<div class="vt-detail-label">Service Type:</div><div class="vt-detail-value">' + data.service_type + '</div>';
                    html += '<div class="vt-detail-label">Description:</div><div class="vt-detail-value">' + data.service_description + '</div>';
                    html += '<div class="vt-detail-label">Required Parts:</div><div class="vt-detail-value" style="font-size:12px;">' + data.required_parts + '</div>';
                    html += '<div class="vt-detail-label">Mechanic:</div><div class="vt-detail-value">' + data.mechanic_name + '</div>';
                    html += '<div class="vt-detail-label">Estimated Cost:</div><div class="vt-detail-value">₱' + data.estimated_cost + '</div>';
                    html += '<div class="vt-detail-label">Total Amount:</div><div class="vt-detail-amount">₱' + data.total_amount + '</div>';
                    html += '<div class="vt-detail-label">Amount Paid:</div><div class="vt-detail-value">₱' + data.amount_paid + '</div>';
                    html += '<div class="vt-detail-label">Change:</div><div class="vt-detail-value">₱' + data.change_amount + '</div>';
                    html += '<div class="vt-detail-label">Payment Method:</div><div class="vt-detail-value">' + data.payment_method + '</div>';
                    html += '<div class="vt-detail-label">Payment Status:</div><div class="vt-detail-value">' + data.payment_status + '</div>';
                    html += '<div class="vt-detail-label">Job Status:</div><div class="vt-detail-value">' + data.job_status + '</div>';
                    html += '<div class="vt-detail-label">Created Date:</div><div class="vt-detail-value">' + data.transaction_date + '</div>';
                    html += '<div class="vt-detail-label">Staff:</div><div class="vt-detail-value">' + data.staff_name + '</div>';
                    html += '<div class="vt-detail-label">Validation Status:</div><div class="vt-detail-value"><span style="background:#f0fdf4;color:#166534;padding:4px 12px;border-radius:4px;font-size:12px;font-weight:600;">' + data.validation_status + '</span></div>';
                    html += '<div class="vt-detail-label">Validated By:</div><div class="vt-detail-value">' + data.validated_by + '</div>';
                    html += '<div class="vt-detail-label">Validated At:</div><div class="vt-detail-value">' + data.validated_at + '</div>';
                    if (data.additional_notes !== 'N/A') {
                        html += '<div class="vt-detail-label">Notes:</div><div class="vt-detail-value">' + data.additional_notes + '</div>';
                    }
                }
                
                html += '</div>';
                document.getElementById('viewTransactionContent').innerHTML = html;
            } else {
                document.getElementById('viewTransactionContent').innerHTML = '<div style="text-align:center;padding:40px;color:#dc2626;"><i class="fas fa-exclamation-circle" style="font-size:32px;display:block;margin-bottom:12px;"></i>' + (data.error || 'Unable to load details') + '</div>';
            }
        })
        .catch(error => {
            console.error('Error loading transaction details:', error);
            document.getElementById('viewTransactionContent').innerHTML = '<div style="text-align:center;padding:40px;color:#f59e0b;"><i class="fas fa-exclamation-triangle" style="font-size:32px;display:block;margin-bottom:12px;"></i>Connection error. Please try again.</div>';
        });
}

function closeViewModal() {
    document.getElementById('viewTransactionModal').classList.remove('active');
}

function exportTable(format) {
    const formatNames = {
        'excel': 'Excel (.xls)',
        'csv': 'CSV (.csv)',
        'pdf': 'PDF (Print/Save)'
    };
    
    // Get current search filter if exists
    const urlParams = new URLSearchParams(window.location.search);
    const search = urlParams.get('search') || '';
    const dateFrom = urlParams.get('date_from') || '';
    const dateTo = urlParams.get('date_to') || '';
    
    // Build export URL with filters
    let exportUrl = '../backend/export_validated_transactions.php?format=' + format;
    if (search) exportUrl += '&search=' + encodeURIComponent(search);
    if (dateFrom) exportUrl += '&date_from=' + encodeURIComponent(dateFrom);
    if (dateTo) exportUrl += '&date_to=' + encodeURIComponent(dateTo);
    
    // Confirm and redirect
    if (confirm('Export validated transactions to ' + formatNames[format] + '?\n\nThis will download all validated transactions matching your current filters.')) {
        window.location.href = exportUrl;
    }
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
