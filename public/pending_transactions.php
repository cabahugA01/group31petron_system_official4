<?php
/**
 * MANAGER PENDING TRANSACTIONS - NEW DESIGN
 * 
 * Shows ONLY transactions with validation_status = 'Pending' or 'Pending Validation'
 * Manager can: Approve, Reject, Adjust
 * Uses NEW tables: merchandise_transactions, job_orders
 * Design: Petron Blue (#002F70)
 */
$page_id = 'pending_transactions_manager';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/transaction_schema_fix.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();

// Only Manager can access
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Manager role required.';
    header('Location: staff_dashboard.php'); exit;
}

// ── Dynamic column detection ──────────────────────────────────────────────────
function pt_cols(PDO $pdo, string $table): array {
    try {
        $rows = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $r) $map[strtolower($r['Field'])] = true;
        return $map;
    } catch (Exception $e) { return []; }
}
function pt_has(array $map, string $col): bool { return isset($map[strtolower($col)]); }

$mt_cols = pt_cols($pdo, 'merchandise_transactions');
$jo_cols = pt_cols($pdo, 'job_orders');

// ── Payment status helper ─────────────────────────────────────────────────────
function pt_pay_status(array $row): string {
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

// ── POST: Manager actions (Approve, Reject) ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';

    $insert_audit = function(int $txn_id, string $action_type, ?string $new_val = null) use ($pdo, $me, $station_id) {
        try {
            $pdo->prepare("INSERT INTO audit_trail (transaction_id, manager_id, action_type, new_value, station_id) VALUES (?, ?, ?, ?, ?)")
                ->execute([$txn_id, $me['id'], $action_type, $new_val, $station_id]);
        } catch (Exception $ae) {}
    };

    // ── Approve Merchandise Transaction ──────────────────────────────────────
    if ($post_action === 'approve_transaction') {
        $row_id = (int)($_POST['transaction_id'] ?? 0);
        try {
            $pdo->beginTransaction();
            
            $set_parts = ["validation_status = 'Approved'"];
            $set_vals  = [];
            if (pt_has($mt_cols, 'validated_by')) { $set_parts[] = "validated_by = ?"; $set_vals[] = $me['id']; }
            if (pt_has($mt_cols, 'validated_at')) { $set_parts[] = "validated_at = NOW()"; }
            if (pt_has($mt_cols, 'updated_at'))   { $set_parts[] = "updated_at = NOW()"; }
            $stmt = $pdo->prepare("UPDATE merchandise_transactions SET " . implode(', ', $set_parts) . " WHERE id = ? AND station_id = ?");
            $stmt->execute(array_merge($set_vals, [$row_id, $station_id]));
            
            if ($stmt->rowCount() > 0) {
                $insert_audit($row_id, 'Approve');
                log_activity($pdo, $me['id'], 'Approve Transaction', "Merchandise transaction #{$row_id} approved by {$me['name']}");
                $pdo->commit();
                $_SESSION['success'] = 'Transaction approved successfully.';
            } else {
                $pdo->rollBack();
                $_SESSION['error'] = 'Transaction not found.';
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = 'Error: ' . $e->getMessage();
        }
        header('Location: pending_transactions.php?t=' . time()); exit;
    }

    // ── Reject Merchandise Transaction ───────────────────────────────────────
    if ($post_action === 'reject_transaction') {
        $row_id = (int)($_POST['transaction_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        try {
            $pdo->beginTransaction();
            
            $set_parts = ["validation_status = 'Rejected'"];
            $set_vals  = [];
            if (pt_has($mt_cols, 'validated_by')) { $set_parts[] = "validated_by = ?"; $set_vals[] = $me['id']; }
            if (pt_has($mt_cols, 'validated_at')) { $set_parts[] = "validated_at = NOW()"; }
            if (pt_has($mt_cols, 'rejection_reason')) { $set_parts[] = "rejection_reason = ?"; $set_vals[] = $reason; }
            elseif (pt_has($mt_cols, 'remarks')) { $set_parts[] = "remarks = ?"; $set_vals[] = 'REJECTED: ' . $reason; }
            if (pt_has($mt_cols, 'updated_at')) { $set_parts[] = "updated_at = NOW()"; }
            $stmt = $pdo->prepare("UPDATE merchandise_transactions SET " . implode(', ', $set_parts) . " WHERE id = ? AND station_id = ?");
            $stmt->execute(array_merge($set_vals, [$row_id, $station_id]));
            
            if ($stmt->rowCount() > 0) {
                $insert_audit($row_id, 'Reject', $reason);
                log_activity($pdo, $me['id'], 'Reject Transaction', "Merchandise #{$row_id} rejected. Reason: {$reason}");
                $pdo->commit();
                $_SESSION['success'] = 'Transaction rejected.';
            } else {
                $pdo->rollBack();
                $_SESSION['error'] = 'Transaction not found.';
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = 'Error: ' . $e->getMessage();
        }
        header('Location: pending_transactions.php?t=' . time()); exit;
    }

    // ── Approve Job Order ─────────────────────────────────────────────────────
    if ($post_action === 'approve_job_order') {
        $jo_id = (int)($_POST['jo_id'] ?? 0);
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE job_orders SET validation_status='Approved', status='Pending', validated_by=?, validated_at=NOW() WHERE id=? AND station_id=?")
                ->execute([$me['id'], $jo_id, $station_id]);
            $insert_audit($jo_id, 'Approve', "JO Approved.");
            log_activity($pdo, $me['id'], 'JO_APPROVED', "Job Order #{$jo_id} approved by {$me['name']}.");
            $pdo->commit();
            $_SESSION['success'] = "Job Order #{$jo_id} approved.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = 'Error: ' . $e->getMessage();
        }
        header('Location: pending_transactions.php?t=' . time()); exit;
    }

    // ── Reject Job Order ──────────────────────────────────────────────────────
    if ($post_action === 'reject_job_order') {
        $jo_id  = (int)($_POST['jo_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE job_orders SET validation_status='Rejected', status='Cancelled', validated_by=?, validated_at=NOW() WHERE id=? AND station_id=?")
                ->execute([$me['id'], $jo_id, $station_id]);
            $insert_audit($jo_id, 'Reject', "JO Rejected. Reason: {$reason}");
            log_activity($pdo, $me['id'], 'JO_REJECTED', "Job Order #{$jo_id} rejected. Reason: {$reason}");
            $pdo->commit();
            $_SESSION['success'] = "Job Order #{$jo_id} rejected.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = 'Error: ' . $e->getMessage();
        }
        header('Location: pending_transactions.php?t=' . time()); exit;
    }

    // ── Adjust Merchandise Transaction ───────────────────────────────────────
    if ($post_action === 'adjust_transaction') {
        $row_id = (int)($_POST['transaction_id'] ?? 0);
        $adj_type = trim($_POST['adjustment_type'] ?? '');
        $new_val = trim($_POST['new_value'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        try {
            $set_parts = ["validation_status = 'Adjusted'"];
            $set_vals  = [];
            if (pt_has($mt_cols, 'validated_by')) { $set_parts[] = "validated_by = ?"; $set_vals[] = $me['id']; }
            if (pt_has($mt_cols, 'validated_at')) { $set_parts[] = "validated_at = NOW()"; }
            if (pt_has($mt_cols, 'adjustment_reason')) { $set_parts[] = "adjustment_reason = ?"; $set_vals[] = "[$adj_type] $reason"; }
            elseif (pt_has($mt_cols, 'remarks')) { $set_parts[] = "remarks = ?"; $set_vals[] = "ADJUSTED [$adj_type]: $reason"; }
            if (pt_has($mt_cols, 'updated_at')) { $set_parts[] = "updated_at = NOW()"; }
            
            // Apply adjustment based on type
            if ($adj_type === 'quantity' && pt_has($mt_cols, 'quantity')) {
                $set_parts[] = "quantity = ?";
                $set_vals[] = (float)$new_val;
            } elseif ($adj_type === 'price' && pt_has($mt_cols, 'total_amount')) {
                $set_parts[] = "total_amount = ?";
                $set_vals[] = (float)$new_val;
            } elseif ($adj_type === 'service_fee' && pt_has($mt_cols, 'service_fee')) {
                $set_parts[] = "service_fee = ?";
                $set_vals[] = (float)$new_val;
            }
            
            $stmt = $pdo->prepare("UPDATE merchandise_transactions SET " . implode(', ', $set_parts) . " WHERE id = ? AND station_id = ?");
            $stmt->execute(array_merge($set_vals, [$row_id, $station_id]));
            if ($stmt->rowCount() > 0) {
                $insert_audit($row_id, 'Adjust', "[$adj_type] New value: $new_val. Reason: $reason");
                log_activity($pdo, $me['id'], 'Adjust Transaction', "Merchandise #{$row_id} adjusted. Type: $adj_type, Value: $new_val");
                $_SESSION['success'] = 'Transaction adjusted successfully.';
            } else {
                $_SESSION['error'] = 'Transaction not found.';
            }
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error: ' . $e->getMessage();
        }
        header('Location: pending_transactions.php'); exit;
    }

    // ── Adjust Job Order ──────────────────────────────────────────────────────
    if ($post_action === 'adjust_job_order') {
        $jo_id = (int)($_POST['jo_id'] ?? 0);
        $adj_type = trim($_POST['adjustment_type'] ?? '');
        $new_val = trim($_POST['new_value'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        try {
            $pdo->beginTransaction();
            $set_parts = ["validation_status='Adjusted'", "validated_by=?", "validated_at=NOW()"];
            $set_vals  = [$me['id']];
            
            // Apply adjustment based on type
            if ($adj_type === 'price' && pt_has($jo_cols, 'total_cost')) {
                $set_parts[] = "total_cost = ?";
                $set_vals[] = (float)$new_val;
            } elseif ($adj_type === 'service_fee' && pt_has($jo_cols, 'service_fee')) {
                $set_parts[] = "service_fee = ?";
                $set_vals[] = (float)$new_val;
            }
            
            $pdo->prepare("UPDATE job_orders SET " . implode(', ', $set_parts) . " WHERE id=? AND station_id=?")
                ->execute(array_merge($set_vals, [$jo_id, $station_id]));
            $insert_audit($jo_id, 'Adjust', "[$adj_type] New value: $new_val. Reason: $reason");
            log_activity($pdo, $me['id'], 'JO_ADJUSTED', "Job Order #{$jo_id} adjusted. Type: $adj_type, Value: $new_val");
            $pdo->commit();
            $_SESSION['success'] = "Job Order #{$jo_id} adjusted.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = 'Error: ' . $e->getMessage();
        }
        header('Location: pending_transactions.php'); exit;
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$search = trim($_GET['search'] ?? '');

// ── Fetch PENDING Merchandise + Job Orders ────────────────────────────────────
$rows = [];
$total_amount = 0.0;

// Merchandise PENDING transactions
$mt_status_col = pt_has($mt_cols, 'validation_status') ? 'mt.validation_status' : "'Pending'";
$mt_staff_col  = pt_has($mt_cols, 'staff_id') ? 'u.name' : "'Unknown'";
$mt_date_col   = "CASE WHEN mt.transaction_date > '2000-01-01' THEN mt.transaction_date ELSE mt.created_at END";
$mt_paid_col   = pt_has($mt_cols, 'amount_paid') ? 'mt.amount_paid' : 'NULL';

$mt_where = "WHERE mt.station_id = ? AND LOWER(TRIM(COALESCE(mt.validation_status,''))) = 'pending'";
$mt_params = [$station_id];
if ($search !== '') {
    $mt_where .= " AND (mt.customer_name LIKE ? OR mt.transaction_id LIKE ?)";
    $mt_params[] = "%$search%"; $mt_params[] = "%$search%";
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
            COALESCE({$mt_status_col},'Pending') AS validation_status,
            COALESCE({$mt_staff_col},'Unknown') AS staff_name,
            'merchandise_transactions' AS _source
        FROM merchandise_transactions mt
        LEFT JOIN users u ON u.id = mt.staff_id
        {$mt_where}
        ORDER BY txn_date DESC
        LIMIT 100
    ");
    $stmt->execute($mt_params);
    $mt_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $mt_rows = []; }

// Job Orders PENDING VALIDATION
$jo_status_col = pt_has($jo_cols, 'validation_status') ? 'jo.validation_status' : 'jo.status';
$jo_staff_col  = pt_has($jo_cols, 'created_by') ? 'COALESCE(jo.created_by, jo.user_id)' : 'jo.user_id';
$jo_pay_col    = pt_has($jo_cols, 'payment_method') ? 'COALESCE(jo.payment_method,\'N/A\')' : "'N/A'";
$jo_cost_col   = pt_has($jo_cols, 'total_cost') ? 'COALESCE(jo.total_cost,0)' : 'COALESCE(jo.estimated_cost,0)';
$jo_paid_col   = pt_has($jo_cols, 'amount_paid') ? 'jo.amount_paid' : 'NULL';

$jo_where = "WHERE jo.station_id = ? AND LOWER(TRIM(COALESCE({$jo_status_col},''))) = 'pending validation'";
$jo_params = [$station_id];
if ($search !== '') {
    $jo_where .= " AND (jo.customer_name LIKE ? OR jo.service_type LIKE ? OR jo.vehicle_plate LIKE ?)";
    $jo_params[] = "%$search%"; $jo_params[] = "%$search%"; $jo_params[] = "%$search%";
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
            COALESCE(NULLIF(TRIM({$jo_status_col}),''),'Pending') AS validation_status,
            COALESCE(u.name,'Unknown') AS staff_name,
            'job_orders' AS _source
        FROM job_orders jo
        LEFT JOIN users u ON u.id = {$jo_staff_col}
        {$jo_where}
        ORDER BY jo.created_at DESC
        LIMIT 100
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
    <div>
        <h1 class="h1" style="margin:0 0 4px 0;">Pending Transactions</h1>
        <div class="sub">Review staff-encoded records awaiting validation.</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <button type="button" onclick="exportPending('excel')" title="Export to Excel"
                style="background:#1d6f42;color:#fff;height:38px;padding:9px 20px;border-radius:8px;border:none;font-size:14px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
            <i class="fas fa-file-excel"></i> Excel
        </button>
        <button type="button" onclick="exportPending('csv')" title="Export to CSV"
                style="background:#003d7a;color:#fff;height:38px;padding:9px 20px;border-radius:8px;border:none;font-size:14px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
            <i class="fas fa-file-csv"></i> CSV
        </button>
        <button type="button" onclick="exportPending('pdf')" title="Export to PDF"
                style="background:#dc2626;color:#fff;height:38px;padding:9px 20px;border-radius:8px;border:none;font-size:14px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
            <i class="fas fa-file-pdf"></i> PDF
        </button>
        <a href="<?= in_array($role, ['admin', 'superadmin']) ? 'admin_dashboard.php' : 'manager_dashboard.php'; ?>"
           style="background:#6c757d;color:#fff;text-decoration:none;height:38px;padding:9px 20px;border-radius:8px;font-size:14px;font-weight:600;display:inline-flex;align-items:center;gap:6px;">
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
<?php if (isset($_SESSION['info'])): ?>
<div style="background:#d1ecf1;color:#0c5460;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($_SESSION['info']); unset($_SESSION['info']); ?>
</div>
<?php endif; ?>

<!-- Filter Bar -->
<div class="pt-filter-card">
    <form method="get" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
        <div class="pt-flt-grp">
            <label class="pt-lbl"><i class="fas fa-search"></i> Search</label>
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                   class="pt-inp" placeholder="Transaction ID, customer..." style="width:300px;">
        </div>
        <div style="align-self:flex-end;display:flex !important;flex-direction:row !important;gap:8px;">
            <button type="submit" class="pt-btn pt-btn-search"><i class="fas fa-search"></i> Search</button>
            <a href="?" class="pt-btn pt-btn-reset"><i class="fas fa-rotate-left"></i> Reset</a>
        </div>
    </form>
</div>

<!-- Table -->
<div class="card" style="padding:0;overflow-x:auto;">
    <table class="pt-table" style="table-layout:auto;width:100%;">
        <colgroup>
            <col style="width:9%;"><!-- Transaction ID -->
            <col style="width:10%;"><!-- Customer -->
            <col style="width:8%;"><!-- Type -->
            <col style="width:14%;"><!-- Items / Service -->
            <col style="width:8%;"><!-- Amount -->
            <col style="width:9%;"><!-- Payment Method -->
            <col style="width:9%;"><!-- Payment Status -->
            <col style="width:11%;"><!-- Date / Time -->
            <col style="width:10%;"><!-- Staff -->
            <col style="width:12%;"><!-- Actions -->
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
                <th style="font-size:13px;">Date / Time</th>
                <th style="font-size:13px;">Staff</th>
                <th style="text-align:center;font-size:13px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($rows) > 0): ?>
                <?php foreach ($rows as $r): ?>
                <?php $pay_st = pt_pay_status($r); ?>
                <tr>
                    <td style="font-weight:600;font-size:13px;font-family:monospace;white-space:nowrap;">
                        <?php echo htmlspecialchars($r['txn_id']); ?>
                    </td>
                    <td style="font-size:13px;" title="<?php echo htmlspecialchars($r['customer']); ?>"><?php echo htmlspecialchars($r['customer']); ?></td>
                    <td>
                        <span class="pt-badge pt-badge-type">
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
                        <span class="pt-badge pt-badge-<?php echo strtolower(str_replace(' ', '-', $pay_st)); ?>">
                            <?php echo $pay_st; ?>
                        </span>
                    </td>
                    <td style="white-space:nowrap;font-size:13px;color:#64748b;">
                        <?php echo date('M d, Y H:i', strtotime($r['txn_date'])); ?>
                    </td>
                    <td style="font-size:13px;color:#64748b;"><?php echo htmlspecialchars($r['staff_name']); ?></td>
                    <td style="text-align:center;padding:12px 8px;">
                        <div style="display:flex;flex-direction:column;gap:6px;align-items:center;">
                            <?php if ($r['_source'] === 'merchandise_transactions'): ?>
                                <button class="pt-btn-action-full pt-btn-approve" onclick="approveTransaction(<?php echo $r['row_id']; ?>)">
                                    <i class="fas fa-check-circle"></i> Approve
                                </button>
                                <button class="pt-btn-action-full pt-btn-reject" onclick="rejectTransaction(<?php echo $r['row_id']; ?>)">
                                    <i class="fas fa-times-circle"></i> Reject
                                </button>
                                <button class="pt-btn-action-full pt-btn-adjust" onclick="adjustTransaction(<?php echo $r['row_id']; ?>)">
                                    <i class="fas fa-edit"></i> Adjust
                                </button>
                                <button class="pt-btn-action-full pt-btn-view" onclick="viewTransaction(<?php echo $r['row_id']; ?>)">
                                    <i class="fas fa-eye"></i> View
                                </button>
                            <?php else: ?>
                                <button class="pt-btn-action-full pt-btn-approve" onclick="approveJobOrder(<?php echo $r['row_id']; ?>)">
                                    <i class="fas fa-check-circle"></i> Approve
                                </button>
                                <button class="pt-btn-action-full pt-btn-reject" onclick="rejectJobOrder(<?php echo $r['row_id']; ?>)">
                                    <i class="fas fa-times-circle"></i> Reject
                                </button>
                                <button class="pt-btn-action-full pt-btn-adjust" onclick="adjustJobOrder(<?php echo $r['row_id']; ?>)">
                                    <i class="fas fa-edit"></i> Adjust
                                </button>
                                <button class="pt-btn-action-full pt-btn-view" onclick="viewJobOrder(<?php echo $r['row_id']; ?>)">
                                    <i class="fas fa-eye"></i> View
                                </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="10" style="text-align:center;padding:60px 20px;color:#94a3b8;">
                        <i class="fas fa-check-circle" style="font-size:48px;display:block;margin-bottom:12px;opacity:0.3;"></i>
                        <div style="font-size:16px;font-weight:600;color:#64748b;margin-bottom:4px;">No Pending Transactions</div>
                        <div style="font-size:13px;">All transactions have been validated.</div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Pagination Controls -->
<?php if (count($rows) > 0): ?>
<div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;background:#fff;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 12px 12px;flex-wrap:wrap;gap:12px;">
    <div style="display:flex;align-items:center;gap:8px;">
        <label style="font-size:12px;color:#64748b;font-weight:600;">Rows per page:</label>
        <select id="rowsPerPage" style="padding:6px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;cursor:pointer;">
            <option value="10" selected>10</option>
            <option value="20">20</option>
            <option value="30">30</option>
            <option value="40">40</option>
            <option value="50">50</option>
        </select>
    </div>
    <div style="display:flex;align-items:center;gap:10px;">
        <span id="pageInfo" style="font-size:12px;color:#64748b;font-weight:600;">Page 1 of 1</span>
        <div style="display:flex;gap:4px;">
            <button id="prevPage" class="pt-page-btn" style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:6px;background:#fff;color:#64748b;font-size:12px;cursor:pointer;transition:all .15s;" disabled>
                <i class="fas fa-chevron-left"></i> Prev
            </button>
            <button id="nextPage" class="pt-page-btn" style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:6px;background:#fff;color:#64748b;font-size:12px;cursor:pointer;transition:all .15s;">
                Next <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Reject Modal -->
<div class="pt-modal-overlay" id="rejectModal">
    <div class="pt-modal">
        <h3><i class="fas fa-times-circle" style="color:#dc2626;margin-right:8px;"></i>Reject Transaction</h3>
        <form method="POST" id="rejectForm">
            <input type="hidden" name="action" id="reject_action" value="reject_transaction">
            <input type="hidden" name="transaction_id" id="reject_txn_id" value="">
            <input type="hidden" name="jo_id" id="reject_jo_id" value="">
            <label>Reason for rejection <span style="color:#dc2626;">*</span></label>
            <textarea name="reason" id="reject_reason" placeholder="Explain why this transaction is being rejected..." required style="min-height:80px;"></textarea>
            <div class="pt-modal-btns">
                <button type="button" class="pt-modal-cancel" onclick="closeRejectModal()">Cancel</button>
                <button type="submit" class="pt-modal-submit" style="background:#dc2626;">Reject Transaction</button>
            </div>
        </form>
    </div>
</div>

<!-- Adjust Modal -->
<div class="pt-modal-overlay" id="adjustModal">
    <div class="pt-modal" style="max-width:600px;">
        <h3><i class="fas fa-edit" style="color:#f59e0b;margin-right:8px;"></i>Adjust Transaction</h3>
        <form method="POST" id="adjustForm">
            <input type="hidden" name="action" id="adjust_action" value="adjust_transaction">
            <input type="hidden" name="transaction_id" id="adjust_txn_id" value="">
            <input type="hidden" name="jo_id" id="adjust_jo_id" value="">
            
            <label>Adjustment Type <span style="color:#dc2626;">*</span></label>
            <select name="adjustment_type" id="adjust_type" class="pt-modal-input" required>
                <option value="">Select adjustment type...</option>
                <option value="quantity">Quantity Adjustment</option>
                <option value="price">Price Adjustment</option>
                <option value="service_fee">Service Fee Adjustment</option>
                <option value="other">Other</option>
            </select>
            
            <label style="margin-top:12px;">New Value</label>
            <input type="text" name="new_value" id="adjust_value" class="pt-modal-input" placeholder="Enter new value..." required>
            
            <label style="margin-top:12px;">Reason for adjustment <span style="color:#dc2626;">*</span></label>
            <textarea name="reason" id="adjust_reason" placeholder="Explain why this adjustment is needed..." required style="min-height:60px;"></textarea>
            
            <div class="pt-modal-btns">
                <button type="button" class="pt-modal-cancel" onclick="closeAdjustModal()">Cancel</button>
                <button type="submit" class="pt-modal-submit" style="background:#f59e0b;">Apply Adjustment</button>
            </div>
        </form>
    </div>
</div>

<!-- View Modal -->
<div class="pt-modal-overlay" id="viewModal">
    <div class="pt-modal" style="max-width:800px;">
        <h3><i class="fas fa-eye" style="color:#3b82f6;margin-right:8px;"></i>Transaction Details</h3>
        <div id="viewContent" style="max-height:500px;overflow-y:auto;">
            <!-- Content loaded dynamically -->
        </div>
        <div class="pt-modal-btns">
            <button type="button" class="pt-modal-cancel" onclick="closeViewModal()">Close</button>
        </div>
    </div>
</div>

<style>
/* Filter Card */
.pt-filter-card { 
    background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px 18px;margin-bottom:14px;box-shadow:0 1px 4px rgba(0,0,0,.05); 
}
.pt-flt-grp { display:flex;flex-direction:column;gap:4px; }
.pt-lbl { font-size:14px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px; }
.pt-inp { 
    height:40px;padding:0 12px;border:1px solid #cbd5e1;border-radius:7px;font-size:14px;color:#1e293b;background:#fff;outline:none;box-sizing:border-box; 
}
.pt-inp:focus { border-color:#002F70;box-shadow:0 0 0 3px rgba(0,47,112,.1); }
.pt-btn { 
    display:inline-flex;align-items:center;gap:6px;padding:0 18px;height:40px;border:none;border-radius:7px;font-size:14px;font-weight:600;cursor:pointer;text-decoration:none;white-space:nowrap;transition:filter .15s;:inline-flex;align-items:center;gap:6px;padding:0 16px;height:36px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;white-space:nowrap;transition:filter .15s; 
}
.pt-btn:hover { filter:brightness(.88); }
.pt-btn-search { background:#002F70;color:#fff; }
.pt-btn-reset  { background:#64748b;color:#fff; }

/* Table */
.pt-table { width:100%;border-collapse:collapse;font-size:13px; }
.pt-table thead th { 
    background:#002F70;color:#fff;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:12px 10px;border-bottom:2px solid #001a3d;text-align:left;
}
.pt-table tbody td { padding:10px;border-bottom:1px solid #f1f5f9;vertical-align:middle;background:#fff; }
.pt-table tbody tr:hover td { background:#eff6ff; }

/* Badges */
.pt-badge { 
    display:inline-block;padding:3px 10px;border-radius:4px;font-size:11px;font-weight:600;white-space:nowrap;background:#f8fafc;color:#64748b;border:1px solid #e2e8f0;
}
.pt-badge-type { background:#f1f5f9;color:#475569;border-color:#cbd5e1; }
.pt-badge-paid { background:#f0fdf4;color:#166534;border-color:#bbf7d0; }
.pt-badge-partial { background:#fef3c7;color:#92400e;border-color:#fde047; }
.pt-badge-unpaid { background:#fef2f2;color:#991b1b;border-color:#fecaca; }

/* Action Buttons - MATCHING REFERENCE DESIGN */
.pt-btn-action-full { 
    color:#fff;
    width:auto;
    min-width:100px;
    height:36px;
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
    padding:0 12px;
}
.pt-btn-action-full:hover { 
    transform:translateY(-2px);
    box-shadow:0 4px 12px rgba(0,0,0,.2);
    filter:brightness(1.05);
}
.pt-btn-action-full:active {
    transform:translateY(0);
}
.pt-btn-approve { background:#28a745; } /* Bright Green */
.pt-btn-reject { background:#dc3545; }  /* Bright Red */
.pt-btn-adjust { background:#6c757d; }  /* Gray */
.pt-btn-view { background:#003d82; }    /* Navy Blue */

/* Modal */
.pt-modal-overlay { display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center; }
.pt-modal-overlay.active { display:flex; }
.pt-modal { background:#fff;border-radius:12px;padding:28px 28px 22px;width:100%;max-width:480px;box-shadow:0 8px 40px rgba(0,0,0,.18); }
.pt-modal h3 { font-size:16px;font-weight:700;color:#1e293b;margin:0 0 18px 0;display:flex;align-items:center; }
.pt-modal label { display:block;font-size:12px;font-weight:600;color:#475569;margin:0 0 4px 0; }
.pt-modal textarea { width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:7px;font-size:13px;box-sizing:border-box;resize:vertical; }
.pt-modal-input { width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:7px;font-size:13px;box-sizing:border-box; }
.pt-modal-input:focus, .pt-modal textarea:focus { border-color:#002F70;outline:none;box-shadow:0 0 0 3px rgba(0,47,112,.1); }
.pt-modal-btns { display:flex;gap:8px;justify-content:flex-end;margin-top:16px; }
.pt-modal-cancel { padding:8px 16px;background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer; }
.pt-modal-cancel:hover { background:#e2e8f0; }
.pt-modal-submit { padding:8px 18px;color:#fff;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer; }
.pt-modal-submit:hover { filter:brightness(.9); }
</style>

<script>
function approveTransaction(id) {
    if (confirm('Approve this transaction?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="approve_transaction"><input type="hidden" name="transaction_id" value="' + id + '">';
        document.body.appendChild(form);
        form.submit();
    }
}

function approveJobOrder(id) {
    if (confirm('Approve this job order?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="approve_job_order"><input type="hidden" name="jo_id" value="' + id + '">';
        document.body.appendChild(form);
        form.submit();
    }
}

function rejectTransaction(id) {
    document.getElementById('reject_action').value = 'reject_transaction';
    document.getElementById('reject_txn_id').value = id;
    document.getElementById('reject_jo_id').value = '';
    document.getElementById('reject_reason').value = '';
    document.getElementById('rejectModal').classList.add('active');
}

function rejectJobOrder(id) {
    document.getElementById('reject_action').value = 'reject_job_order';
    document.getElementById('reject_jo_id').value = id;
    document.getElementById('reject_txn_id').value = '';
    document.getElementById('reject_reason').value = '';
    document.getElementById('rejectModal').classList.add('active');
}

function closeRejectModal() {
    document.getElementById('rejectModal').classList.remove('active');
}

function adjustTransaction(id) {
    document.getElementById('adjust_action').value = 'adjust_transaction';
    document.getElementById('adjust_txn_id').value = id;
    document.getElementById('adjust_jo_id').value = '';
    document.getElementById('adjust_type').value = '';
    document.getElementById('adjust_value').value = '';
    document.getElementById('adjust_reason').value = '';
    document.getElementById('adjustModal').classList.add('active');
}

function adjustJobOrder(id) {
    document.getElementById('adjust_action').value = 'adjust_job_order';
    document.getElementById('adjust_jo_id').value = id;
    document.getElementById('adjust_txn_id').value = '';
    document.getElementById('adjust_type').value = '';
    document.getElementById('adjust_value').value = '';
    document.getElementById('adjust_reason').value = '';
    document.getElementById('adjustModal').classList.add('active');
}

function closeAdjustModal() {
    document.getElementById('adjustModal').classList.remove('active');
}

function viewTransaction(id) {
    document.getElementById('viewContent').innerHTML = '<div style="text-align:center;padding:40px;"><i class="fas fa-spinner fa-spin" style="font-size:24px;color:#3b82f6;"></i><div style="margin-top:12px;color:#64748b;">Loading transaction details...</div></div>';
    document.getElementById('viewModal').classList.add('active');
    
    // Fetch transaction details via AJAX
    fetch('../backend/get_transaction_details.php?id=' + id + '&type=merchandise_transactions')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let html = '<div style="font-size:13px;line-height:1.6;">';
                html += '<div style="display:grid;grid-template-columns:140px 1fr;gap:10px 16px;margin-bottom:16px;">';
                html += '<div style="font-weight:600;color:#64748b;">Transaction ID:</div><div style="color:#1e293b;font-family:monospace;">' + data.transaction_id + '</div>';
                html += '<div style="font-weight:600;color:#64748b;">Customer:</div><div style="color:#1e293b;">' + data.customer_name + '</div>';
                html += '<div style="font-weight:600;color:#64748b;">Item SKU:</div><div style="color:#1e293b;">' + data.item_sku + '</div>';
                html += '<div style="font-weight:600;color:#64748b;">Quantity:</div><div style="color:#1e293b;">' + data.quantity + '</div>';
                html += '<div style="font-weight:600;color:#64748b;">Unit Price:</div><div style="color:#1e293b;">₱' + data.unit_price + '</div>';
                html += '<div style="font-weight:600;color:#64748b;">Total Amount:</div><div style="color:#002F70;font-weight:700;font-size:16px;">₱' + data.total_amount + '</div>';
                html += '<div style="font-weight:600;color:#64748b;">Payment Method:</div><div style="color:#1e293b;">' + data.payment_method + '</div>';
                if (data.amount_tendered !== 'N/A') {
                    html += '<div style="font-weight:600;color:#64748b;">Amount Tendered:</div><div style="color:#1e293b;">₱' + data.amount_tendered + '</div>';
                    html += '<div style="font-weight:600;color:#64748b;">Change:</div><div style="color:#1e293b;">₱' + data.change_amount + '</div>';
                }
                html += '<div style="font-weight:600;color:#64748b;">Transaction Date:</div><div style="color:#1e293b;">' + data.transaction_date + '</div>';
                html += '<div style="font-weight:600;color:#64748b;">Staff:</div><div style="color:#1e293b;">' + data.staff_name + '</div>';
                html += '<div style="font-weight:600;color:#64748b;">Status:</div><div style="color:#1e293b;"><span style="background:#fef3c7;color:#92400e;padding:4px 10px;border-radius:4px;font-size:11px;font-weight:600;">PENDING</span></div>';
                if (data.shift !== 'N/A') {
                    html += '<div style="font-weight:600;color:#64748b;">Shift:</div><div style="color:#1e293b;">' + data.shift + '</div>';
                }
                html += '</div>';
                if (data.remarks !== 'N/A') {
                    html += '<div style="border-top:1px solid #e2e8f0;padding-top:12px;"><div style="font-weight:600;color:#64748b;margin-bottom:4px;">Remarks:</div><div style="color:#475569;">' + data.remarks + '</div></div>';
                }
                html += '</div>';
                document.getElementById('viewContent').innerHTML = html;
            } else {
                document.getElementById('viewContent').innerHTML = '<div style="text-align:center;padding:40px;color:#dc2626;"><i class="fas fa-exclamation-circle" style="font-size:24px;"></i><div style="margin-top:12px;">' + (data.error || 'Failed to load transaction details.') + '</div></div>';
            }
        })
        .catch(error => {
            console.error('Error loading transaction:', error);
            document.getElementById('viewContent').innerHTML = '<div style="text-align:center;padding:40px;color:#dc2626;"><i class="fas fa-exclamation-circle" style="font-size:24px;"></i><div style="margin-top:12px;">Error loading details. Please try again.</div></div>';
        });
}

function viewJobOrder(id) {
    document.getElementById('viewContent').innerHTML = '<div style="text-align:center;padding:40px;"><i class="fas fa-spinner fa-spin" style="font-size:24px;color:#3b82f6;"></i><div style="margin-top:12px;color:#64748b;">Loading job order details...</div></div>';
    document.getElementById('viewModal').classList.add('active');
    
    // Fetch job order details via AJAX
    fetch('../backend/get_transaction_details.php?id=' + id + '&type=job_orders')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let html = '<div style="font-size:13px;line-height:1.6;">';
                html += '<div style="display:grid;grid-template-columns:140px 1fr;gap:10px 16px;margin-bottom:16px;">';
                html += '<div style="font-weight:600;color:#64748b;">Job Order #:</div><div style="color:#1e293b;font-family:monospace;">' + data.transaction_id + '</div>';
                html += '<div style="font-weight:600;color:#64748b;">Customer:</div><div style="color:#1e293b;">' + data.customer_name + '</div>';
                html += '<div style="font-weight:600;color:#64748b;">Vehicle Plate:</div><div style="color:#1e293b;">' + data.vehicle_plate + '</div>';
                html += '<div style="font-weight:600;color:#64748b;">Vehicle Type:</div><div style="color:#1e293b;">' + data.vehicle_type + '</div>';
                html += '<div style="font-weight:600;color:#64748b;">Service Type:</div><div style="color:#1e293b;">' + data.service_type + '</div>';
                html += '<div style="font-weight:600;color:#64748b;">Description:</div><div style="color:#1e293b;">' + data.service_description + '</div>';
                html += '<div style="font-weight:600;color:#64748b;">Required Parts:</div><div style="color:#1e293b;font-size:12px;">' + data.required_parts + '</div>';
                html += '<div style="font-weight:600;color:#64748b;">Mechanic:</div><div style="color:#1e293b;">' + data.mechanic_name + '</div>';
                html += '<div style="font-weight:600;color:#64748b;">Estimated Cost:</div><div style="color:#1e293b;">₱' + data.estimated_cost + '</div>';
                html += '<div style="font-weight:600;color:#64748b;">Total Amount:</div><div style="color:#002F70;font-weight:700;font-size:16px;">₱' + data.total_amount + '</div>';
                html += '<div style="font-weight:600;color:#64748b;">Payment Method:</div><div style="color:#1e293b;">' + data.payment_method + '</div>';
                html += '<div style="font-weight:600;color:#64748b;">Payment Status:</div><div style="color:#1e293b;">' + data.payment_status + '</div>';
                html += '<div style="font-weight:600;color:#64748b;">Date:</div><div style="color:#1e293b;">' + data.transaction_date + '</div>';
                html += '<div style="font-weight:600;color:#64748b;">Staff:</div><div style="color:#1e293b;">' + data.staff_name + '</div>';
                html += '<div style="font-weight:600;color:#64748b;">Validation Status:</div><div style="color:#1e293b;"><span style="background:#fef3c7;color:#92400e;padding:4px 10px;border-radius:4px;font-size:11px;font-weight:600;">PENDING</span></div>';
                html += '</div>';
                if (data.additional_notes !== 'N/A') {
                    html += '<div style="border-top:1px solid #e2e8f0;padding-top:12px;"><div style="font-weight:600;color:#64748b;margin-bottom:4px;">Notes:</div><div style="color:#475569;">' + data.additional_notes + '</div></div>';
                }
                html += '</div>';
                document.getElementById('viewContent').innerHTML = html;
            } else {
                document.getElementById('viewContent').innerHTML = '<div style="text-align:center;padding:40px;color:#dc2626;"><i class="fas fa-exclamation-circle" style="font-size:24px;"></i><div style="margin-top:12px;">' + (data.error || 'Failed to load job order details.') + '</div></div>';
            }
        })
        .catch(error => {
            console.error('Error loading job order:', error);
            document.getElementById('viewContent').innerHTML = '<div style="text-align:center;padding:40px;color:#dc2626;"><i class="fas fa-exclamation-circle" style="font-size:24px;"></i><div style="margin-top:12px;">Error loading details. Please try again.</div></div>';
        });
}

function closeViewModal() {
    document.getElementById('viewModal').classList.remove('active');
}

// Close modals on overlay click
document.getElementById('rejectModal').addEventListener('click', function(e) {
    if (e.target === this) closeRejectModal();
});
document.getElementById('adjustModal').addEventListener('click', function(e) {
    if (e.target === this) closeAdjustModal();
});
document.getElementById('viewModal').addEventListener('click', function(e) {
    if (e.target === this) closeViewModal();
});

// ══════════════════════════════════════════════════════════════════════════════
// AUTO-REFRESH: Pending Transactions (30-second polling for near real-time updates)
// No manual refresh button needed - system automatically updates to reflect new
// staff encodings and manager actions.
// ══════════════════════════════════════════════════════════════════════════════
let refreshPendingTimer = null;
let isModalOpen = false;

function autoRefreshPendingTransactions() {
    // Skip refresh if user is interacting with modal
    if (isModalOpen) {
        return;
    }
    
    // Silently reload the page to get fresh data
    // Preserves current search filters via URL params
    const urlParams = new URLSearchParams(window.location.search);
    const currentSearch = urlParams.toString();
    const reloadUrl = currentSearch ? '?' + currentSearch : window.location.pathname;
    
    // Silent reload - no page flash
    window.location.replace(reloadUrl + (currentSearch ? '&t=' : '?t=') + Date.now());
}

// Track modal state
function updateModalState() {
    const rejectModal = document.getElementById('rejectModal');
    const adjustModal = document.getElementById('adjustModal');
    const viewModal = document.getElementById('viewModal');
    
    isModalOpen = rejectModal.classList.contains('active') || 
                  adjustModal.classList.contains('active') || 
                  viewModal.classList.contains('active');
}

// Update modal state whenever modals open/close
const originalCloseRejectModal = window.closeRejectModal;
window.closeRejectModal = function() {
    originalCloseRejectModal();
    updateModalState();
};

const originalCloseAdjustModal = window.closeAdjustModal;
window.closeAdjustModal = function() {
    originalCloseAdjustModal();
    updateModalState();
};

const originalCloseViewModal = window.closeViewModal;
window.closeViewModal = function() {
    originalCloseViewModal();
    updateModalState();
};

const originalApproveTransaction = window.approveTransaction;
window.approveTransaction = function(id) {
    updateModalState();
    return originalApproveTransaction(id);
};

const originalRejectTransaction = window.rejectTransaction;
window.rejectTransaction = function(id) {
    updateModalState();
    return originalRejectTransaction(id);
};

const originalAdjustTransaction = window.adjustTransaction;
window.adjustTransaction = function(id) {
    updateModalState();
    return originalAdjustTransaction(id);
};

function exportPending(format) {
    const formatNames = {
        'excel': 'Excel (.xls)',
        'csv': 'CSV (.csv)',
        'pdf': 'PDF (Print/Save)'
    };
    const urlParams = new URLSearchParams(window.location.search);
    const search = urlParams.get('search') || '';
    let exportUrl = '../backend/export_pending_transactions.php?format=' + format;
    if (search) exportUrl += '&search=' + encodeURIComponent(search);
    
    if (confirm('Export pending transactions to ' + formatNames[format] + '?\n\nThis will download all pending transactions matching your current filters.')) {
        window.location.href = exportUrl;
    }
}

// Start auto-refresh timer (30 seconds)
refreshPendingTimer = setInterval(autoRefreshPendingTransactions, 30000);

console.log('✅ Auto-refresh enabled for Pending Transactions (30s interval)');

// ══════════════════════════════════════════════════════════════════════════════
// PAGINATION FUNCTIONALITY
// ══════════════════════════════════════════════════════════════════════════════
(function() {
    const table = document.querySelector('.pt-table tbody');
    if (!table) return;
    
    const allRows = Array.from(table.querySelectorAll('tr'));
    // Skip if only "no records" row
    if (allRows.length === 1 && allRows[0].querySelector('td[colspan]')) return;
    
    let currentPage = 1;
    let rowsPerPage = 10;
    
    const rowsSelect = document.getElementById('rowsPerPage');
    const pageInfo = document.getElementById('pageInfo');
    const prevBtn = document.getElementById('prevPage');
    const nextBtn = document.getElementById('nextPage');
    
    if (!rowsSelect || !pageInfo || !prevBtn || !nextBtn) return;
    
    function updateTable() {
        const totalPages = Math.ceil(allRows.length / rowsPerPage);
        const start = (currentPage - 1) * rowsPerPage;
        const end = start + rowsPerPage;
        
        // Hide all rows first
        allRows.forEach(row => row.style.display = 'none');
        
        // Show only current page rows
        allRows.slice(start, end).forEach(row => row.style.display = '');
        
        // Update page info
        pageInfo.textContent = `Page ${currentPage} of ${totalPages}`;
        
        // Update button states
        prevBtn.disabled = currentPage === 1;
        nextBtn.disabled = currentPage === totalPages;
        
        // Update button styles
        prevBtn.style.opacity = prevBtn.disabled ? '0.5' : '1';
        prevBtn.style.cursor = prevBtn.disabled ? 'not-allowed' : 'pointer';
        nextBtn.style.opacity = nextBtn.disabled ? '0.5' : '1';
        nextBtn.style.cursor = nextBtn.disabled ? 'not-allowed' : 'pointer';
    }
    
    rowsSelect.addEventListener('change', function() {
        rowsPerPage = parseInt(this.value);
        currentPage = 1;
        updateTable();
    });
    
    prevBtn.addEventListener('click', function() {
        if (currentPage > 1) {
            currentPage--;
            updateTable();
            // Scroll to top of table
            document.querySelector('.card').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
    
    nextBtn.addEventListener('click', function() {
        const totalPages = Math.ceil(allRows.length / rowsPerPage);
        if (currentPage < totalPages) {
            currentPage++;
            updateTable();
            // Scroll to top of table
            document.querySelector('.card').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
    
    // Add hover effects
    document.querySelectorAll('.pt-page-btn').forEach(btn => {
        btn.addEventListener('mouseenter', function() {
            if (!this.disabled) {
                this.style.background = '#f1f5f9';
                this.style.borderColor = '#cbd5e1';
            }
        });
        btn.addEventListener('mouseleave', function() {
            this.style.background = '#fff';
            this.style.borderColor = '#e2e8f0';
        });
    });
    
    // Initialize
    updateTable();
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
