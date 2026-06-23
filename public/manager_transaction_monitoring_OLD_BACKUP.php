<?php
/**
 * Manager Transaction Monitoring
 * Official staff transactions can only be adjusted, corrected, or voided.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'manager_transactions';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$station_id = (int) user_station_id();
$role = role_key($me['role'] ?? '');

if (!in_array($role, ['manager', 'supervisor', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Manager privileges required.';
    header('Location: dashboard.php');
    exit;
}

if (!$station_id) {
    die('Error: You are not assigned to a station.');
}

foreach ([
    "ALTER TABLE `merchandise_transactions` ADD COLUMN `manager_notes` TEXT DEFAULT NULL",
    "ALTER TABLE `merchandise_transactions` ADD COLUMN `inventory_deducted` TINYINT(1) NOT NULL DEFAULT 0",
] as $schema_fix) {
    try { $pdo->exec($schema_fix); } catch (Exception $e) {}
}
unset($schema_fix);

function mtm_log_audit(PDO $pdo, array $me, int $station_id, int $txn_id, string $action, array $old_values = [], array $new_values = []): void {
    try {
        $pdo->prepare("
            INSERT INTO audit_logs (
                user_id, log_type, action_type, action_details, entity_type, entity_id,
                old_values, new_values, station_id, ip_address, user_agent, status, created_at
            ) VALUES (?, 'TRANSACTION', ?, ?, 'merchandise_transactions', ?, ?, ?, ?, ?, ?, 'SUCCESS', NOW())
        ")->execute([
            $me['id'],
            $action,
            "Manager {$action} on transaction #{$txn_id}",
            $txn_id,
            json_encode($old_values),
            json_encode($new_values),
            $station_id,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    } catch (Exception $e) {
        error_log('Manager transaction audit warning: ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        $pdo->beginTransaction();

        if ($action === 'adjust') {
            $txn_id = (int)($_POST['transaction_id'] ?? 0);
            $new_quantity = (float)($_POST['quantity'] ?? 0);
            $new_rate = (float)($_POST['rate'] ?? 0);
            $new_payment_type = trim($_POST['payment_type'] ?? '');
            $new_payment_status = trim($_POST['payment_status'] ?? '');
            $correction_reason = trim($_POST['correction_reason'] ?? '');
            $remarks = trim($_POST['remarks'] ?? '');

            if ($txn_id <= 0) throw new Exception('Transaction ID is required.');
            if ($new_quantity <= 0) throw new Exception('Quantity must be greater than zero.');
            if ($new_rate < 0) throw new Exception('Rate cannot be negative.');
            if ($correction_reason === '') throw new Exception('Correction reason is required.');

            $orig_stmt = $pdo->prepare("SELECT * FROM merchandise_transactions WHERE id = ? AND station_id = ? FOR UPDATE");
            $orig_stmt->execute([$txn_id, $station_id]);
            $orig = $orig_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$orig) throw new Exception('Transaction not found.');
            if (strtolower((string)($orig['validation_status'] ?? '')) === 'voided') {
                throw new Exception('Voided transactions cannot be adjusted.');
            }

            $new_total = round($new_quantity * $new_rate, 2);

            $item_stmt = $pdo->prepare("
                SELECT id, product_id, quantity
                FROM merchandise_transaction_items
                WHERE transaction_id = ?
                  AND COALESCE(item_type, 'merchandise') <> 'service'
                  AND product_id IS NOT NULL
                ORDER BY id
                LIMIT 2
            ");
            $item_stmt->execute([$txn_id]);
            $items = $item_stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($items) === 1 && !empty($orig['inventory_deducted'])) {
                $item = $items[0];
                $delta = $new_quantity - (float)$item['quantity'];
                if ($delta > 0) {
                    $stock_stmt = $pdo->prepare("
                        SELECT stock_level
                        FROM station_inventory
                        WHERE station_id = ? AND product_id = ?
                        FOR UPDATE
                    ");
                    $stock_stmt->execute([$station_id, $item['product_id']]);
                    $stock = $stock_stmt->fetchColumn();
                    if ($stock === false || (float)$stock < $delta) {
                        throw new Exception('Insufficient stock for this adjustment.');
                    }
                    $pdo->prepare("
                        UPDATE station_inventory
                        SET stock_level = stock_level - ?, last_updated = NOW()
                        WHERE station_id = ? AND product_id = ?
                    ")->execute([$delta, $station_id, $item['product_id']]);
                } elseif ($delta < 0) {
                    $pdo->prepare("
                        UPDATE station_inventory
                        SET stock_level = stock_level + ?, last_updated = NOW()
                        WHERE station_id = ? AND product_id = ?
                    ")->execute([abs($delta), $station_id, $item['product_id']]);
                }

                try {
                    $pdo->prepare("
                        UPDATE merchandise_transaction_items
                        SET quantity = ?, unit_price = ?, subtotal = ?
                        WHERE id = ? AND transaction_id = ?
                    ")->execute([$new_quantity, $new_rate, $new_total, $item['id'], $txn_id]);
                } catch (Exception $e) {
                    error_log('Transaction item adjustment warning: ' . $e->getMessage());
                }
            }

            $manager_note = "Adjusted: {$correction_reason}";
            if ($remarks !== '') $manager_note .= " | {$remarks}";

            $pdo->prepare("
                UPDATE merchandise_transactions
                SET quantity = ?,
                    unit_price = ?,
                    total_amount = ?,
                    payment_method = ?,
                    payment_status = ?,
                    validation_status = 'Adjusted',
                    validated_by = ?,
                    validated_at = NOW(),
                    manager_notes = ?,
                    updated_at = NOW()
                WHERE id = ? AND station_id = ?
            ")->execute([
                $new_quantity,
                $new_rate,
                $new_total,
                $new_payment_type,
                $new_payment_status,
                $me['id'],
                $manager_note,
                $txn_id,
                $station_id
            ]);

            if (!empty($orig['credit_customer_id'])) {
                $credit_delta = $new_total - (float)($orig['total_amount'] ?? 0);
                if (abs($credit_delta) > 0.009) {
                    $pdo->prepare("
                        UPDATE customers
                        SET balance = GREATEST(balance + ?, 0)
                        WHERE id = ? AND station_id = ?
                    ")->execute([$credit_delta, $orig['credit_customer_id'], $station_id]);
                }
            }

            mtm_log_audit($pdo, $me, $station_id, $txn_id, 'Adjust', [
                'quantity' => $orig['quantity'] ?? null,
                'rate' => $orig['unit_price'] ?? null,
                'total' => $orig['total_amount'] ?? null,
                'payment_method' => $orig['payment_method'] ?? null,
                'payment_status' => $orig['payment_status'] ?? null,
            ], [
                'quantity' => $new_quantity,
                'rate' => $new_rate,
                'total' => $new_total,
                'payment_method' => $new_payment_type,
                'payment_status' => $new_payment_status,
                'reason' => $correction_reason,
            ]);

            $_SESSION['success'] = 'Transaction adjusted successfully.';
        } elseif ($action === 'correct') {
            $txn_id = (int)($_POST['transaction_id'] ?? 0);
            $correction_note = trim($_POST['correction_note'] ?? '');
            if ($txn_id <= 0) throw new Exception('Transaction ID is required.');
            if ($correction_note === '') throw new Exception('Correction note is required.');

            $stmt = $pdo->prepare("SELECT manager_notes FROM merchandise_transactions WHERE id = ? AND station_id = ? FOR UPDATE");
            $stmt->execute([$txn_id, $station_id]);
            $existing_note = $stmt->fetchColumn();
            if ($existing_note === false) throw new Exception('Transaction not found.');

            $new_note = trim(($existing_note ? $existing_note . "\n" : '') . "Corrected: {$correction_note}");
            $pdo->prepare("
                UPDATE merchandise_transactions
                SET manager_notes = ?,
                    validated_by = ?,
                    validated_at = NOW(),
                    updated_at = NOW()
                WHERE id = ? AND station_id = ?
            ")->execute([$new_note, $me['id'], $txn_id, $station_id]);

            mtm_log_audit($pdo, $me, $station_id, $txn_id, 'Correct', [
                'manager_notes' => $existing_note
            ], [
                'manager_notes' => $new_note
            ]);

            $_SESSION['success'] = 'Correction note added successfully.';
        } elseif ($action === 'void') {
            $txn_id = (int)($_POST['transaction_id'] ?? 0);
            $void_reason = trim($_POST['void_reason'] ?? '');
            if ($txn_id <= 0) throw new Exception('Transaction ID is required.');
            if ($void_reason === '') throw new Exception('Void reason is required.');

            $orig_stmt = $pdo->prepare("SELECT * FROM merchandise_transactions WHERE id = ? AND station_id = ? FOR UPDATE");
            $orig_stmt->execute([$txn_id, $station_id]);
            $orig = $orig_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$orig) throw new Exception('Transaction not found.');
            if (strtolower((string)($orig['validation_status'] ?? '')) === 'voided') {
                throw new Exception('Transaction is already voided.');
            }

            if (!empty($orig['inventory_deducted'])) {
                $items_stmt = $pdo->prepare("
                    SELECT product_id, quantity
                    FROM merchandise_transaction_items
                    WHERE transaction_id = ?
                      AND COALESCE(item_type, 'merchandise') <> 'service'
                      AND product_id IS NOT NULL
                ");
                $items_stmt->execute([$txn_id]);
                while ($item = $items_stmt->fetch(PDO::FETCH_ASSOC)) {
                    $pdo->prepare("
                        UPDATE station_inventory
                        SET stock_level = stock_level + ?, last_updated = NOW()
                        WHERE station_id = ? AND product_id = ?
                    ")->execute([$item['quantity'], $station_id, $item['product_id']]);
                }
            }

            if (!empty($orig['credit_customer_id']) && (float)($orig['total_amount'] ?? 0) > 0) {
                $pdo->prepare("
                    UPDATE customers
                    SET balance = GREATEST(balance - ?, 0)
                    WHERE id = ? AND station_id = ?
                ")->execute([(float)$orig['total_amount'], $orig['credit_customer_id'], $station_id]);
            }

            $pdo->prepare("
                UPDATE merchandise_transactions
                SET validation_status = 'Voided',
                    inventory_deducted = 0,
                    validated_by = ?,
                    validated_at = NOW(),
                    manager_notes = ?,
                    updated_at = NOW()
                WHERE id = ? AND station_id = ?
            ")->execute([$me['id'], "Voided: {$void_reason}", $txn_id, $station_id]);

            mtm_log_audit($pdo, $me, $station_id, $txn_id, 'Void', [
                'validation_status' => $orig['validation_status'] ?? null,
                'inventory_deducted' => $orig['inventory_deducted'] ?? null,
            ], [
                'validation_status' => 'Voided',
                'reason' => $void_reason,
            ]);

            $_SESSION['success'] = 'Transaction voided successfully.';
        }

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error'] = 'Error: ' . $e->getMessage();
    }

    header('Location: manager_transaction_monitoring.php');
    exit;
}

$filter_date_from = $_GET['date_from'] ?? date('Y-m-01');
$filter_date_to = $_GET['date_to'] ?? date('Y-m-d');
$filter_shift = $_GET['shift'] ?? '';
$filter_staff = $_GET['staff'] ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_type = $_GET['type'] ?? '';
$search = trim($_GET['search'] ?? '');

$where = ["mt.station_id = ?"];
$params = [$station_id];

if ($filter_date_from && $filter_date_to) {
    $where[] = "DATE(COALESCE(mt.transaction_date, mt.created_at)) BETWEEN ? AND ?";
    $params[] = $filter_date_from;
    $params[] = $filter_date_to;
}
if ($filter_shift) {
    $where[] = "mt.shift_period = ?";
    $params[] = $filter_shift;
}
if ($filter_staff) {
    $where[] = "mt.staff_id = ?";
    $params[] = $filter_staff;
}
if ($filter_status) {
    $where[] = "mt.validation_status = ?";
    $params[] = $filter_status;
}
if ($filter_type) {
    $where[] = "mt.transaction_type = ?";
    $params[] = $filter_type;
}
if ($search !== '') {
    $where[] = "(mt.transaction_id LIKE ? OR mt.customer_name LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$stmt = $pdo->prepare("
    SELECT
        mt.*,
        TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS staff_name,
        COUNT(mti.id) AS item_count
    FROM merchandise_transactions mt
    LEFT JOIN users u ON u.id = mt.staff_id
    LEFT JOIN merchandise_transaction_items mti ON mti.transaction_id = mt.id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY mt.id
    ORDER BY COALESCE(mt.transaction_date, mt.created_at) DESC, mt.id DESC
");
$stmt->execute($params);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$staff_list = [];
try {
    $stmt = $pdo->prepare("SELECT id, TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) AS name FROM users WHERE station_id = ? AND role IN ('staff','cashier','pump_attendant') ORDER BY name");
    $stmt->execute([$station_id]);
    $staff_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

require_once __DIR__ . '/../partials/header.php';
?>

<style>
/* == PAGE HEADER - matches SuperAdmin int-head standard == */
.int-head { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px; margin-top:-12px !important; }
.int-head h1 { font-size:22px !important; font-weight:700 !important; color:var(--petron-blue,#00264D) !important; margin:0 !important; text-transform:uppercase !important; display:flex; align-items:center; gap:8px; }
.int-head .sub { font-size:13px; color:#666; margin-top:4px; text-transform:none !important; }

/* == Outline Buttons == */
.ato-btn {
    display:inline-flex; align-items:center; justify-content:center; gap:6px;
    padding:0 16px; border-radius:7px; font-size:13px; font-weight:600;
    cursor:pointer; border:1px solid transparent; text-decoration:none; transition:all .15s;
    height:36px; white-space:nowrap; background:white !important;
}
.ato-btn-filter { color:#002F70 !important; border-color:#002F70 !important; }
.ato-btn-filter:hover { background:#002F70 !important; color:#fff !important; }
.ato-btn-back { color:#4b5563 !important; border-color:#6b7280 !important; }
.ato-btn-back:hover { background:#6b7280 !important; color:#fff !important; }

/* == Table Styles == */
.transaction-table { width:100%; border-collapse:collapse; font-size:11px; table-layout:fixed; }
.transaction-table thead tr { background:#002F70; }
.transaction-table th { padding:9px 10px; text-align:left; font-size:11px; font-weight:700; color:#fff; text-transform:uppercase; letter-spacing:.4px; border-bottom:2px solid #001a3d; vertical-align:middle; }
.transaction-table tbody tr { border-bottom:1px solid #f1f5f9; transition:background .1s; }
.transaction-table tbody tr:hover td { background:#eff6ff; }
.transaction-table tbody td { padding:9px 10px; color:#334155; vertical-align:middle; overflow:hidden; text-overflow:ellipsis; background:#fff; font-size:11px; }

/* Status Badges */
.status-badge { display:inline-block; padding:3px 10px; border-radius:4px; font-size:11px; font-weight:700; text-transform:uppercase; white-space:nowrap; }
.status-official, .status-completed, .status-approved { background:#dcfce7; color:#166534; }
.status-adjusted { background:#eff6ff; color:#1e40af; }
.status-voided { background:#f1f5f9; color:#475569; }
.status-pending { background:#fef9c3; color:#a16207; }

/* Row buttons */
.action-buttons { display:flex; gap:6px; flex-wrap:wrap; }
.action-buttons .btn {
    display:inline-flex; align-items:center; justify-content:center; gap:4px;
    padding:0 10px; border-radius:5px; font-size:11px; font-weight:600;
    cursor:pointer; border:1px solid transparent; text-decoration:none; transition:all .15s;
    height:28px; white-space:nowrap; background:white !important;
}
.btn-adjust { color:#3b82f6 !important; border-color:#3b82f6 !important; }
.btn-adjust:hover { background:#3b82f6 !important; color:#fff !important; }
.btn-correct { color:#6366f1 !important; border-color:#6366f1 !important; }
.btn-correct:hover { background:#6366f1 !important; color:#fff !important; }
.btn-void { color:#f97316 !important; border-color:#f97316 !important; }
.btn-void:hover { background:#f97316 !important; color:#fff !important; }

/* == Filter Bar Layout == */
.filters { display:flex; align-items:flex-end; gap:10px; flex-wrap:wrap; background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:12px 16px; margin-bottom:16px; }
.filters > div { display:flex; flex-direction:column; gap:3px; }
.filters label { font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.4px; }
.filters .input { height:36px; padding:0 10px; border:1px solid #cbd5e1; border-radius:7px; font-size:13px; color:#1e293b; background:#fff; outline:none; box-sizing:border-box; width:100%; min-width:140px; }
.filters .input:focus { border-color:#002F70; box-shadow:0 0 0 3px rgba(0,47,112,.1); }

/* == Modals == */
.modal { position:fixed; inset:0; display:none; align-items:center; justify-content:center; z-index:9999; }
.modal-overlay { position:absolute; inset:0; background:rgba(15,23,42,0.5); }
.modal-card { position:relative; background:#fff; border-radius:12px; max-width:600px; width:90%; max-height:90vh; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,.1); display:flex; flex-direction:column; }
.modal-head { display:flex; justify-content:space-between; align-items:center; padding:14px 18px; background:#002F70; color:#fff; }
.modal-title { font-weight:700; color:#fff; font-size:15px; }
.modal-head .icon-btn { background:none; border:none; color:#fff; font-size:20px; cursor:pointer; line-height:1; }
.modal-body { padding:20px; overflow-y:auto; }
.modal-body .form-label { font-size:11px; font-weight:600; color:#475569; text-transform:uppercase; letter-spacing:0.3px; display:block; margin-bottom:4px; }
.modal-body .input { width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; box-sizing:border-box; outline:none; }
.modal-body .input:focus { border-color:#002F70; box-shadow:0 0 0 3px rgba(0,47,112,.1); }
.modal-actions { display:flex; justify-content:flex-end; gap:8px; padding:12px 18px; background:#f8fafc; border-top:1px solid #e2e8f0; }

.modal-actions .btn {
    display:inline-flex; align-items:center; justify-content:center; gap:6px;
    padding:0 16px; border-radius:7px; font-size:13px; font-weight:600;
    cursor:pointer; border:1px solid transparent; text-decoration:none; transition:all .15s;
    height:36px; white-space:nowrap;
}
.modal-actions .btn.ghost { color:#4b5563 !important; border-color:#6b7280 !important; background:white !important; }
.modal-actions .btn.ghost:hover { background:#6b7280 !important; color:#fff !important; }
.modal-actions .btn.primary { background:#002F70 !important; color:#fff !important; }
.modal-actions .btn.primary:hover { background:#001a3d !important; }
.modal-actions .btn.danger { background:#dc2626 !important; color:#fff !important; }
.modal-actions .btn.danger:hover { background:#b91c1c !important; }

/* Custom clean card */
.card { background:#fff; border:1px solid #e2e8f0; border-radius:11px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.05); }
.card-head { display:flex; align-items:center; justify-content:space-between; padding:13px 16px; border-bottom:1px solid #e9ecef; flex-wrap:wrap; gap:8px; background:#f8fafc; }
.card-title { font-size:13px; font-weight:700; color:#00264D; display:flex; align-items:center; gap:7px; }
</style>

<div class="int-head">
    <div>
        <?php if ($filter_status === 'Voided'): ?>
            <h1><i class="fas fa-ban"></i> Voided Transactions</h1>
            <div class="sub">Monitor cancelled transactions and review associated void reasons and approvals.</div>
        <?php else: ?>
            <h1><i class="fas fa-sliders-h"></i> Transaction Adjustments</h1>
            <div class="sub">Review and manage transaction corrections, modifications, and adjustment records.</div>
        <?php endif; ?>
    </div>
</div>

<?php if (isset($_SESSION['success'])): ?>
<div class="alert success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
<div class="alert error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-head"><div class="card-title">Filter Transactions</div></div>
    <form method="GET" class="filters">
        <div>
            <label>Date From</label>
            <input type="date" name="date_from" class="input" value="<?php echo htmlspecialchars($filter_date_from); ?>">
        </div>
        <div>
            <label>Date To</label>
            <input type="date" name="date_to" class="input" value="<?php echo htmlspecialchars($filter_date_to); ?>">
        </div>
        <div>
            <label>Search</label>
            <input type="text" name="search" class="input" value="<?php echo htmlspecialchars($search); ?>" placeholder="Transaction ID or customer">
        </div>
        <div>
            <label>Shift</label>
            <select name="shift" class="input">
                <option value="">All Shifts</option>
                <option value="first" <?php echo $filter_shift === 'first' ? 'selected' : ''; ?>>Shift 1 (6AM-2PM)</option>
                <option value="second" <?php echo $filter_shift === 'second' ? 'selected' : ''; ?>>Shift 2 (2PM-12MN)</option>
            </select>
        </div>
        <div>
            <label>Staff</label>
            <select name="staff" class="input">
                <option value="">All Staff</option>
                <?php foreach ($staff_list as $staff): ?>
                <option value="<?php echo (int)$staff['id']; ?>" <?php echo $filter_staff == $staff['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($staff['name'] ?: 'Staff #' . $staff['id']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Status</label>
            <select name="status" class="input">
                <option value="">All Status</option>
                <?php foreach (['Official','Completed','Adjusted','Voided'] as $status_option): ?>
                <option value="<?php echo $status_option; ?>" <?php echo $filter_status === $status_option ? 'selected' : ''; ?>><?php echo $status_option; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Type</label>
            <select name="type" class="input">
                <option value="">All Types</option>
                <option value="merchandise" <?php echo $filter_type === 'merchandise' ? 'selected' : ''; ?>>Merchandise</option>
                <option value="job_order" <?php echo $filter_type === 'job_order' ? 'selected' : ''; ?>>Job Order</option>
                <option value="combined" <?php echo $filter_type === 'combined' ? 'selected' : ''; ?>>Job Order + Merchandise</option>
            </select>
        </div>
        <div>
            <button type="submit" class="ato-btn ato-btn-filter"><i class="fas fa-filter"></i> Apply</button>
        </div>
    </form>
</div>

<div class="card" style="margin-top:20px;">
    <div class="card-head"><div class="card-title">Transactions (<?php echo count($transactions); ?>)</div></div>
    <table class="transaction-table">
        <thead>
            <tr>
                <th>Transaction ID</th>
                <th>Customer</th>
                <th>Shift</th>
                <th>Staff</th>
                <th>Type</th>
                <th>Amount</th>
                <th>Payment</th>
                <th>Date</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$transactions): ?>
            <tr><td colspan="10" style="text-align:center;padding:40px;">No transactions found</td></tr>
            <?php else: ?>
            <?php foreach ($transactions as $txn): ?>
            <?php
                $status = $txn['validation_status'] ?: 'Official';
                $status_class = 'status-' . preg_replace('/[^a-z0-9_-]+/', '-', strtolower($status));
                $txn_date = $txn['transaction_date'] ?: ($txn['created_at'] ?? null);
            ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($txn['transaction_id'] ?? ('MT-' . $txn['id'])); ?></strong></td>
                <td><?php echo htmlspecialchars($txn['customer_name'] ?? 'Walk-in Customer'); ?></td>
                <td><?php echo htmlspecialchars($txn['shift_name'] ?? $txn['shift_period'] ?? '-'); ?></td>
                <td><?php echo htmlspecialchars(trim($txn['staff_name']) ?: 'Staff #' . ($txn['staff_id'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $txn['transaction_type'] ?? 'merchandise'))); ?></td>
                <td>PHP <?php echo number_format((float)($txn['total_amount'] ?? 0), 2); ?></td>
                <td><?php echo htmlspecialchars($txn['payment_method'] ?? '-'); ?></td>
                <td><?php echo $txn_date ? date('M d, Y h:i A', strtotime($txn_date)) : '-'; ?></td>
                <td><span class="status-badge <?php echo $status_class; ?>"><?php echo htmlspecialchars($status); ?></span></td>
                <td>
                    <?php if (strtolower($status) !== 'voided'): ?>
                    <div class="action-buttons">
                        <button class="btn btn-adjust" onclick="openAdjustModal(<?php echo (int)$txn['id']; ?>, <?php echo htmlspecialchars(json_encode($txn), ENT_QUOTES); ?>)">Adjust</button>
                        <button class="btn btn-correct" onclick="openCorrectModal(<?php echo (int)$txn['id']; ?>, '<?php echo htmlspecialchars($txn['transaction_id'] ?? ('MT-' . $txn['id']), ENT_QUOTES); ?>')">Correct</button>
                        <button class="btn btn-void" onclick="openVoidModal(<?php echo (int)$txn['id']; ?>, '<?php echo htmlspecialchars($txn['transaction_id'] ?? ('MT-' . $txn['id']), ENT_QUOTES); ?>')">Void</button>
                    </div>
                    <?php else: ?>
                    <span style="color:#64748b;">No actions</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="modal" id="adjustModal">
    <div class="modal-overlay" onclick="closeModal('adjustModal')"></div>
    <div class="modal-card">
        <div class="modal-head">
            <div class="modal-title">Adjust Transaction</div>
            <button class="icon-btn" onclick="closeModal('adjustModal')">x</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="adjust">
            <input type="hidden" name="transaction_id" id="adjust_txn_id">
            <div class="modal-body">
                <p style="margin-bottom:20px;">Transaction ID: <strong id="adjust_txn_display"></strong></p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;">
                    <div>
                        <label class="form-label">Quantity</label>
                        <input type="number" name="quantity" id="adjust_quantity" class="input" step="1" min="1" required>
                    </div>
                    <div>
                        <label class="form-label">Rate / Amount</label>
                        <input type="number" name="rate" id="adjust_rate" class="input" step="0.01" min="0" required>
                    </div>
                </div>
                <div style="margin-top:15px;">
                    <label class="form-label">Payment Type</label>
                    <select name="payment_type" id="adjust_payment_type" class="input">
                        <option value="Cash">Cash</option>
                        <option value="Card">Card</option>
                        <option value="E-Wallet">E-Wallet</option>
                        <option value="Credit">Credit</option>
                        <option value="Fleet Card">Fleet Card</option>
                        <option value="Petron E-Fuel">Petron E-Fuel</option>
                    </select>
                </div>
                <div style="margin-top:15px;">
                    <label class="form-label">Payment Status</label>
                    <select name="payment_status" id="adjust_payment_status" class="input">
                        <option value="Paid">Paid</option>
                        <option value="Partial Payment">Partial Payment</option>
                        <option value="Pending Payment">Pending Payment</option>
                        <option value="Credit Transaction">Credit Transaction</option>
                    </select>
                </div>
                <div style="margin-top:15px;">
                    <label class="form-label">Correction Reason <span style="color:red;">*</span></label>
                    <textarea name="correction_reason" class="input" rows="2" required></textarea>
                </div>
                <div style="margin-top:15px;">
                    <label class="form-label">Remarks</label>
                    <textarea name="remarks" class="input" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn ghost" onclick="closeModal('adjustModal')">Cancel</button>
                <button type="submit" class="btn primary">Save Adjustment</button>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="correctModal">
    <div class="modal-overlay" onclick="closeModal('correctModal')"></div>
    <div class="modal-card">
        <div class="modal-head">
            <div class="modal-title">Add Correction Note</div>
            <button class="icon-btn" onclick="closeModal('correctModal')">x</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="correct">
            <input type="hidden" name="transaction_id" id="correct_txn_id">
            <div class="modal-body">
                <p>Transaction ID: <strong id="correct_txn_display"></strong></p>
                <div style="margin-top:15px;">
                    <label class="form-label">Correction Note <span style="color:red;">*</span></label>
                    <textarea name="correction_note" class="input" rows="3" required></textarea>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn ghost" onclick="closeModal('correctModal')">Cancel</button>
                <button type="submit" class="btn primary">Save Correction Note</button>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="voidModal">
    <div class="modal-overlay" onclick="closeModal('voidModal')"></div>
    <div class="modal-card">
        <div class="modal-head">
            <div class="modal-title">Void Transaction</div>
            <button class="icon-btn" onclick="closeModal('voidModal')">x</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="void">
            <input type="hidden" name="transaction_id" id="void_txn_id">
            <div class="modal-body">
                <p>Transaction ID: <strong id="void_txn_display"></strong></p>
                <p style="color:#ef4444;margin-top:10px;">This will void the transaction and reverse inventory deductions when applicable.</p>
                <div style="margin-top:15px;">
                    <label class="form-label">Void Reason <span style="color:red;">*</span></label>
                    <textarea name="void_reason" class="input" rows="3" required></textarea>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn ghost" onclick="closeModal('voidModal')">Cancel</button>
                <button type="submit" class="btn danger">Void Transaction</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAdjustModal(id, txnData) {
    document.getElementById('adjust_txn_id').value = id;
    document.getElementById('adjust_txn_display').textContent = txnData.transaction_id || ('MT-' + id);
    document.getElementById('adjust_quantity').value = txnData.quantity || 1;
    document.getElementById('adjust_rate').value = txnData.unit_price || txnData.total_amount || 0;
    document.getElementById('adjust_payment_type').value = txnData.payment_method || 'Cash';
    document.getElementById('adjust_payment_status').value = txnData.payment_status || 'Paid';
    document.getElementById('adjustModal').style.display = 'flex';
}

function openCorrectModal(id, txnId) {
    document.getElementById('correct_txn_id').value = id;
    document.getElementById('correct_txn_display').textContent = txnId;
    document.getElementById('correctModal').style.display = 'flex';
}

function openVoidModal(id, txnId) {
    document.getElementById('void_txn_id').value = id;
    document.getElementById('void_txn_display').textContent = txnId;
    document.getElementById('voidModal').style.display = 'flex';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// ═══════════════════════════════════════════════════════════════════════════
// AUTO-REFRESH: Manager Transaction Monitoring (60-second polling)
// Automatically refreshes transaction list to reflect new staff transactions
// and manager corrections without requiring manual page reload.
// ═══════════════════════════════════════════════════════════════════════════
let refreshManagerMonitoringTimer = null;
let isManagerModalOpen = false;

function autoRefreshManagerMonitoring() {
    // Skip refresh if manager is working with a transaction in modal
    if (isManagerModalOpen) {
        return;
    }
    
    // Silently reload to get fresh transaction data
    const urlParams = new URLSearchParams(window.location.search);
    const currentSearch = urlParams.toString();
    const reloadUrl = currentSearch ? '?' + currentSearch : window.location.pathname;
    
    // Silent reload - preserves all filters and search params
    window.location.replace(reloadUrl + (currentSearch ? '&t=' : '?t=') + Date.now());
}

// Track modal state to pause auto-refresh during manager actions
const originalCloseModal = window.closeModal;
window.closeModal = function(id) {
    originalCloseModal(id);
    isManagerModalOpen = false;
};

const originalOpenAdjustModal = window.openAdjustModal;
window.openAdjustModal = function(id, txnData) {
    isManagerModalOpen = true;
    return originalOpenAdjustModal(id, txnData);
};

const originalOpenCorrectModal = window.openCorrectModal;
window.openCorrectModal = function(id, txnId) {
    isManagerModalOpen = true;
    return originalOpenCorrectModal(id, txnId);
};

const originalOpenVoidModal = window.openVoidModal;
window.openVoidModal = function(id, txnId) {
    isManagerModalOpen = true;
    return originalOpenVoidModal(id, txnId);
};

// Start auto-refresh timer (60 seconds - appropriate for manager monitoring)
window.refreshManagerMonitoringTimer = setInterval(autoRefreshManagerMonitoring, 60000);

console.log('✅ Auto-refresh enabled for Manager Transaction Monitoring (60s interval)');
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
