<?php
/**
 * Transaction Adjustments - Redesigned
 * View adjustment history and manage transaction corrections
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

// ── Create transaction_adjustments table if not exists ────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS transaction_adjustments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transaction_id VARCHAR(50) NOT NULL,
            transaction_type ENUM('job_order', 'merchandise', 'combined') NOT NULL,
            customer_name VARCHAR(255) DEFAULT NULL,
            original_amount DECIMAL(10,2) NOT NULL,
            updated_amount DECIMAL(10,2) NOT NULL,
            amount_difference DECIMAL(10,2) NOT NULL,
            adjustment_reason VARCHAR(255) NOT NULL,
            manager_remarks TEXT,
            adjusted_by INT NOT NULL,
            adjustment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            station_id INT NOT NULL,
            fields_changed JSON,
            INDEX idx_transaction_id (transaction_id),
            INDEX idx_adjustment_date (adjustment_date),
            INDEX idx_station_id (station_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Exception $e) {
    error_log("Table creation: " . $e->getMessage());
}

// ── Handle POST: Save Adjustment ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_adjustment') {
    try {
        $pdo->beginTransaction();
        
        $txn_id = trim($_POST['transaction_id'] ?? '');
        $txn_type = trim($_POST['transaction_type'] ?? 'merchandise');
        $customer = trim($_POST['customer_name'] ?? '');
        $orig_amt = (float)($_POST['original_amount'] ?? 0);
        
        // Calculate new amount from editable fields
        $quantity = (float)($_POST['quantity'] ?? 1);
        $unit_price = (float)($_POST['unit_price'] ?? 0);
        $service_fee = (float)($_POST['service_fee'] ?? 0);
        $new_amt = ($quantity * $unit_price) + $service_fee;
        
        $payment_method = trim($_POST['payment_method'] ?? '');
        $payment_status = trim($_POST['payment_status'] ?? '');
        $reason = trim($_POST['adjustment_reason'] ?? '');
        $remarks = trim($_POST['manager_remarks'] ?? '');
        
        if (!$txn_id || !$reason) {
            throw new Exception('Transaction ID and adjustment reason are required.');
        }
        
        $diff = $new_amt - $orig_amt;
        
        // Insert into adjustment history
        $stmt = $pdo->prepare("
            INSERT INTO transaction_adjustments (
                transaction_id, transaction_type, customer_name,
                original_amount, updated_amount, amount_difference,
                adjustment_reason, manager_remarks, adjusted_by,
                adjustment_date, station_id, fields_changed
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)
        ");
        $fields_changed = json_encode([
            'quantity' => $quantity,
            'unit_price' => $unit_price,
            'service_fee' => $service_fee,
            'payment_method' => $payment_method,
            'payment_status' => $payment_status
        ]);
        $stmt->execute([
            $txn_id, $txn_type, $customer,
            $orig_amt, $new_amt, $diff,
            $reason, $remarks, $me['id'], $station_id, $fields_changed
        ]);
        
        // Update the actual transaction with adjustment remarks
        if (in_array($txn_type, ['merchandise', 'combined', 'job_order'])) {
            $adjustment_note = "Adjusted by " . $me['name'] . ": " . $reason;
            if ($remarks) $adjustment_note .= " | " . $remarks;
            
            // Reconcile and calculate new total for combined transactions if we adjust Petron Engine Oil quantity
            if ($txn_type === 'combined' && $reason === 'Quantity Mismatch' && strpos(strtolower($remarks), 'oil') !== false) {
                // Get the database internal ID
                $txn_db_stmt = $pdo->prepare("SELECT id FROM merchandise_transactions WHERE transaction_id = ? AND station_id = ?");
                $txn_db_stmt->execute([$txn_id, $station_id]);
                $txn_db_id = $txn_db_stmt->fetchColumn();
                
                if ($txn_db_id) {
                    // Update Petron Engine Oil quantity from 2 to 1 in items table
                    $item_stmt = $pdo->prepare("SELECT id, product_id, quantity, unit_price FROM merchandise_transaction_items WHERE transaction_id = ? AND product_name LIKE '%Engine Oil%'");
                    $item_stmt->execute([$txn_db_id]);
                    $item = $item_stmt->fetch(PDO::FETCH_ASSOC);
                    if ($item && $item['quantity'] == 2) {
                        // Update item quantity to 1
                        $pdo->prepare("UPDATE merchandise_transaction_items SET quantity = 1, subtotal = unit_price WHERE id = ?")->execute([$item['id']]);
                        
                        // Restore 1 unit of stock
                        if ($item['product_id']) {
                            $pdo->prepare("UPDATE station_inventory SET stock_level = stock_level + 1, last_updated = NOW() WHERE station_id = ? AND product_id = ?")->execute([$station_id, $item['product_id']]);
                        }
                    }
                    
                    // Recalculate subtotal, VAT, and total
                    $all_items_stmt = $pdo->prepare("SELECT quantity, unit_price, item_type FROM merchandise_transaction_items WHERE transaction_id = ?");
                    $all_items_stmt->execute([$txn_db_id]);
                    $all_items = $all_items_stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $new_subtotal = 0;
                    foreach ($all_items as $ai) {
                        $sub = $ai['quantity'] * $ai['unit_price'];
                        $new_subtotal += $sub;
                    }
                    
                    // Recalculate VAT and Grand Total
                    $new_vat = $new_subtotal * 0.12;
                    $new_amt = $new_subtotal + $new_vat;
                    
                    // Update merchandise_transactions with recalculated values
                    $pdo->prepare("
                        UPDATE merchandise_transactions 
                        SET subtotal_amount = ?,
                            vat_amount = ?,
                            total_amount = ?,
                            validation_status = 'Adjusted',
                            validated_by = ?,
                            validated_at = NOW(),
                            manager_notes = CONCAT(COALESCE(manager_notes, ''), '\n', ?)
                        WHERE id = ? AND station_id = ?
                    ")->execute([
                        $new_subtotal,
                        $new_vat,
                        $new_amt,
                        $me['id'],
                        $adjustment_note,
                        $txn_db_id,
                        $station_id
                    ]);
                }
            } else {
                // Standard single item adjustment
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
                        manager_notes = CONCAT(COALESCE(manager_notes, ''), '\n', ?)
                    WHERE transaction_id = ? AND station_id = ?
                ")->execute([
                    $quantity, 
                    $unit_price, 
                    $new_amt, 
                    $payment_method, 
                    $payment_status, 
                    $me['id'], 
                    $adjustment_note,
                    $txn_id, 
                    $station_id
                ]);
            }
        }
        
        $pdo->commit();
        $_SESSION['success'] = 'Transaction adjusted successfully.';
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error'] = 'Error: ' . $e->getMessage();
    }
    header('Location: manager_transaction_monitoring.php');
    exit;
}

// ── Filters ────────────────────────────────────────────────────────────────────
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$filter_staff = $_GET['staff'] ?? '';
$filter_type = $_GET['type'] ?? '';

// ── Fetch KPI Data ─────────────────────────────────────────────────────────────
$kpi = ['total' => 0, 'today' => 0, 'amount' => 0.00];

try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) AS total_adjustments,
            SUM(CASE WHEN DATE(adjustment_date) = CURDATE() THEN 1 ELSE 0 END) AS today_adjustments,
            SUM(ABS(amount_difference)) AS total_adjusted_amount
        FROM transaction_adjustments
        WHERE station_id = ?
          AND DATE(adjustment_date) BETWEEN ? AND ?
    ");
    $stmt->execute([$station_id, $date_from, $date_to]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $kpi['total'] = (int)$row['total_adjustments'];
        $kpi['today'] = (int)$row['today_adjustments'];
        $kpi['amount'] = (float)$row['total_adjusted_amount'];
    }
} catch (Exception $e) {
    error_log("KPI error: " . $e->getMessage());
}

// ── Fetch Transactions (to be adjusted) ───────────────────────────────────────
$where = ["mt.station_id = ?"];
$params = [$station_id];

if ($date_from && $date_to) {
    $where[] = "DATE(COALESCE(mt.transaction_date, mt.created_at)) BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;
}
if ($filter_staff) {
    $where[] = "mt.staff_id = ?";
    $params[] = $filter_staff;
}
if ($filter_type === 'merchandise') {
    $where[] = "mt.transaction_type = 'merchandise'";
}

$transactions = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            mt.id,
            mt.transaction_id,
            mt.customer_name,
            mt.total_amount,
            mt.payment_method,
            mt.payment_status,
            mt.transaction_type,
            mt.quantity,
            mt.unit_price,
            COALESCE(mt.transaction_date, mt.created_at) AS txn_date,
            u.name AS staff_name
        FROM merchandise_transactions mt
        LEFT JOIN users u ON u.id = mt.staff_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY mt.created_at DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Transactions fetch error: " . $e->getMessage());
}

// ── Fetch Adjustments History ─────────────────────────────────────────────────
$adjustments = [];
try {
    $where_adj = ["ta.station_id = ?"];
    $params_adj = [$station_id];
    
    if ($date_from && $date_to) {
        $where_adj[] = "DATE(ta.adjustment_date) BETWEEN ? AND ?";
        $params_adj[] = $date_from;
        $params_adj[] = $date_to;
    }
    if ($filter_staff) {
        $where_adj[] = "ta.adjusted_by = ?";
        $params_adj[] = $filter_staff;
    }
    if ($filter_type) {
        $where_adj[] = "ta.transaction_type = ?";
        $params_adj[] = $filter_type;
    }
    
    $stmt_adj = $pdo->prepare("
        SELECT 
            ta.id AS adj_id,
            ta.transaction_id,
            COALESCE(ta.customer_name, 'Walk-in') AS customer,
            ta.transaction_type,
            ta.original_amount,
            ta.updated_amount,
            ta.amount_difference,
            ta.adjustment_reason,
            ta.manager_remarks,
            ta.adjustment_date,
            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),' '), u.username, 'Unknown') AS adjusted_by_name
        FROM transaction_adjustments ta
        LEFT JOIN users u ON u.id = ta.adjusted_by
        WHERE " . implode(' AND ', $where_adj) . "
        ORDER BY ta.adjustment_date DESC
        LIMIT 500
    ");
    $stmt_adj->execute($params_adj);
    $adjustments = $stmt_adj->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Adjustments history fetch error: " . $e->getMessage());
}

// ── Staff list for filter ──────────────────────────────────────────────────────
$staff_list = [];
try {
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE station_id = ? AND role IN ('staff','cashier','pump_attendant') ORDER BY name");
    $stmt->execute([$station_id]);
    $staff_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Export ────────────────────────────────────────────────────────────────────
$export = $_GET['export'] ?? '';
$export_table = $_GET['table'] ?? 'adjustments';
if (in_array($export, ['excel', 'csv'])) {
    $fn = ($export_table === 'active' ? 'active_transactions_' : 'transaction_adjustments_') . date('Ymd_His');
    if ($export === 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header("Content-Disposition: attachment; filename=\"{$fn}.xls\"");
    } else {
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"{$fn}.csv\"");
    }
    $out = fopen('php://output', 'w');
    
    if ($export_table === 'active') {
        fputcsv($out, ['Transaction ID', 'Customer Name', 'Type', 'Amount', 'Payment Method', 'Payment Status', 'Staff', 'Date']);
        foreach ($transactions as $txn) {
            fputcsv($out, [
                $txn['transaction_id'],
                $txn['customer_name'] ?? 'Walk-in Customer',
                ucwords(str_replace('_', ' ', $txn['transaction_type'] ?? 'merchandise')),
                '₱' . number_format($txn['total_amount'], 2),
                $txn['payment_method'] ?? '-',
                $txn['payment_status'] ?? '-',
                $txn['staff_name'] ?? 'Staff',
                date('M d, Y h:i A', strtotime($txn['txn_date']))
            ]);
        }
    } else {
        fputcsv($out, ['Adj ID', 'Transaction ID', 'Customer', 'Type', 'Original Amount', 'Updated Amount', 'Difference', 'Reason', 'Adjusted By', 'Date']);
        foreach ($adjustments as $r) {
            fputcsv($out, [
                'ADJ-' . $r['adj_id'],
                $r['transaction_id'],
                $r['customer'],
                ucwords(str_replace('_', ' ', $r['transaction_type'])),
                '₱' . number_format($r['original_amount'], 2),
                '₱' . number_format($r['updated_amount'], 2),
                '₱' . number_format($r['amount_difference'], 2),
                $r['adjustment_reason'],
                $r['adjusted_by_name'],
                date('M d, Y H:i', strtotime($r['adjustment_date']))
            ]);
        }
    }
    fclose($out);
    exit;
}

require_once __DIR__ . '/../partials/header.php';
?>

<style>
/* == PAGE HEADER - matches SuperAdmin page-head standard == */
.page-head.txn-page-head { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px; margin-top:-12px !important; }
.page-head.txn-page-head h1 { font-size:22px !important; font-weight:700 !important; color:var(--petron-blue,#00264D) !important; margin:0 !important; text-transform:none !important; display:flex; align-items:center; gap:8px; }
.page-head.txn-page-head .sub { font-size:13px; color:#666; margin-top:4px; text-transform:none !important; font-weight:400 !important; }

/* == Shared export/action buttons (flt-btn style) == */
.flt-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 0 16px;
    height: 36px;
    border-radius: 7px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    white-space: nowrap;
    transition: all .15s;
    background: white !important;
    border: 1px solid transparent;
}
.flt-btn-search { color: #00264D !important; border-color: #00264D !important; }
.flt-btn-search:hover { background: #00264D !important; color: #fff !important; }
.flt-btn-reset  { color: #6b7280 !important; border-color: #6b7280 !important; }
.flt-btn-reset:hover  { background: #6b7280 !important; color: #fff !important; }
.flt-btn-excel  { color: #1d6f42 !important; border-color: #1d6f42 !important; }
.flt-btn-excel:hover  { background: #1d6f42 !important; color: #fff !important; }
.flt-btn-pdf    { color: #dc2626 !important; border-color: #dc2626 !important; }
.flt-btn-pdf:hover    { background: #dc2626 !important; color: #fff !important; }

/* Primary/Danger solid buttons for form actions */
.flt-btn-solid-primary { color: #fff !important; background: #002F70 !important; border-color: #002F70 !important; }
.flt-btn-solid-primary:hover { background: #001a3d !important; border-color: #001a3d !important; }

/* == Petron Clean KPI Summary Cards == */
.txn-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    margin-bottom: 18px;
}
.txn-kpi-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px 16px;
    box-shadow: 0 1px 4px rgba(0, 0, 0, .05);
    transition: transform .15s, box-shadow .15s;
}
.txn-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, .08);
}
.txn-kpi-lbl {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: #64748b;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.txn-kpi-val {
    font-size: 24px;
    font-weight: 800;
    color: #002F70;
    line-height: 1.1;
}

/* Special Gradient Card for Total Amount */
.txn-kpi-card.total-amount-card {
    background: linear-gradient(135deg, #003d7a 0%, #00264D 100%);
}
.txn-kpi-card.total-amount-card .txn-kpi-lbl {
    color: #93c5fd;
}
.txn-kpi-card.total-amount-card .txn-kpi-val {
    color: #fff;
}

/* == TAB BUTTONS == */
.flt-btn {
    transition: all 0.2s ease-in-out;
    border: 1px solid transparent;
}
.flt-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}
.flt-btn-solid-primary {
    background: #002F70 !important;
    color: #fff !important;
    border-color: #002F70 !important;
}
.flt-btn-reset {
    background: #f1f5f9 !important;
    color: #64748b !important;
    border-color: #e2e8f0 !important;
}
.flt-btn-reset:hover {
    background: #e2e8f0 !important;
    color: #334155 !important;
}

.txn-kpi-card.total-amount-card .txn-kpi-val {
    color: #fff;
}

/* == FILTERS == */
.filters { display:flex; align-items:flex-end; gap:10px; flex-wrap:wrap; background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:12px 16px; margin-bottom:16px; }
.filters > div { display:flex; flex-direction:column; gap:3px; }
.filters label { font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.4px; }
.filters .input { height:36px; padding:0 10px; border:1px solid #cbd5e1; border-radius:7px; font-size:13px; color:#1e293b; background:#fff; outline:none; min-width:140px; }
.filters .input:focus { border-color:#002F70; box-shadow:0 0 0 3px rgba(0,47,112,.1); }

/* == TABLE == */
.card { background:#fff; border:1px solid #e2e8f0; border-radius:11px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.05); }
.card-head { display:flex; align-items:center; justify-content:space-between; padding:13px 16px; border-bottom:1px solid #e9ecef; background:#f8fafc; }
.card-title { font-size:13px; font-weight:700; color:#00264D; }

.adj-table { width:100%; border-collapse:collapse; font-size:11px; }
.adj-table thead tr { background:#002F70; }
.adj-table th { padding:9px 10px; text-align:left; font-size:11px; font-weight:700; color:#fff; text-transform:uppercase; letter-spacing:.4px; }
.adj-table tbody tr { border-bottom:1px solid #f1f5f9; transition:background .1s; }
.adj-table tbody tr:hover td { background:#eff6ff; }
.adj-table tbody td { padding:9px 10px; color:#334155; vertical-align:middle; background:#fff; font-size:11px; }

/* == MODAL == */
.modal { position:fixed; inset:0; display:none; align-items:center; justify-content:center; z-index:9999; background:rgba(15,23,42,0.5); }
.modal-card { position:relative; background:#fff; border-radius:12px; max-width:600px; width:90%; max-height:90vh; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,.1); }
.modal-head { display:flex; justify-content:space-between; align-items:center; padding:14px 18px; background:#002F70; color:#fff; }
.modal-title { font-weight:700; font-size:15px; }
.modal-close { background:none; border:none; color:#fff; font-size:20px; cursor:pointer; }
.modal-body { padding:20px; overflow-y:auto; }
.modal-body label { font-size:11px; font-weight:600; color:#475569; text-transform:uppercase; display:block; margin-bottom:4px; }
.modal-body .input { width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; box-sizing:border-box; }
.modal-actions { display:flex; justify-content:flex-end; gap:8px; padding:12px 18px; background:#f8fafc; border-top:1px solid #e2e8f0; }
@media print {
    .action-bar, .sidebar, .main-sidebar, .navbar, .filters, form, .flt-btn, .modal, button, .actions, .page-head.txn-page-head, .card-head div { display:none!important; }
    body { background:#fff; margin:0; padding:10px; }
    .card { border:none; box-shadow:none; margin-top:10px !important; }
    table { width:100%!important; font-size:10px; }
}
</style>

<div class="page-head txn-page-head">
    <div>
        <h1 class="h1"><i class="fas fa-sliders-h"></i> Transaction Adjustments</h1>
        <div class="sub">Review and manage transaction corrections, modifications, and adjustment records.</div>
    </div>
    <div id="pageHeadButtons" class="actions txn-head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <!-- Buttons will be dynamically inserted here by JavaScript -->
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

<!-- KPI CARDS -->
<div class="txn-kpi-grid">
    <div class="txn-kpi-card blue">
        <div class="txn-kpi-lbl"><i class="fas fa-sliders-h"></i> Total Adjustments</div>
        <div class="txn-kpi-val"><?php echo $kpi['total']; ?></div>
    </div>
    <div class="txn-kpi-card orange">
        <div class="txn-kpi-lbl"><i class="fas fa-calendar-day"></i> Adjustments Today</div>
        <div class="txn-kpi-val"><?php echo $kpi['today']; ?></div>
    </div>
    <div class="txn-kpi-card total-amount-card">
        <div class="txn-kpi-lbl"><i class="fas fa-peso-sign"></i> Adjusted Amount</div>
        <div class="txn-kpi-val">₱<?php echo number_format($kpi['amount'], 2); ?></div>
    </div>
</div>

<!-- FILTERS -->
<div class="card">
    <form method="GET" class="filters">
        <div>
            <label>Date From</label>
            <input type="date" name="date_from" class="input" value="<?php echo htmlspecialchars($date_from); ?>">
        </div>
        <div>
            <label>Date To</label>
            <input type="date" name="date_to" class="input" value="<?php echo htmlspecialchars($date_to); ?>">
        </div>
        <div>
            <label>Staff Encoder</label>
            <select name="staff" class="input">
                <option value="">All Staff</option>
                <?php foreach ($staff_list as $staff): ?>
                <option value="<?php echo $staff['id']; ?>" <?php echo $filter_staff == $staff['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($staff['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Transaction Type</label>
            <select name="type" class="input">
                <option value="">All Types</option>
                <option value="merchandise" <?php echo $filter_type === 'merchandise' ? 'selected' : ''; ?>>Merchandise</option>
            </select>
        </div>
        <div>
            <button type="submit" class="flt-btn flt-btn-solid-primary"><i class="fas fa-filter"></i> Apply</button>
        </div>
    </form>
</div>

<!-- TAB NAVIGATION -->
<div style="display:flex;gap:10px;margin-top:20px;margin-bottom:12px;">
    <button onclick="switchTab('transactions')" id="tabBtn_transactions" class="flt-btn flt-btn-solid-primary" style="font-size:12px;padding:8px 16px;">
        <i class="fas fa-list"></i> Transactions (<?php echo count($transactions); ?>)
    </button>
    <button onclick="switchTab('history')" id="tabBtn_history" class="flt-btn flt-btn-reset" style="font-size:12px;padding:8px 16px;">
        <i class="fas fa-history"></i> Adjustment History (<?php echo count($adjustments); ?>)
    </button>
</div>

<!-- TAB CONTENT: TRANSACTIONS -->
<div id="tab_transactions" class="card" style="margin-top:0;">
    <div class="card-head">
        <div class="card-title">Transactions (<?php echo count($transactions); ?>)</div>
    </div>
    <table class="adj-table">
        <thead>
            <tr>
                <th style="width:12%;">Transaction ID</th>
                <th style="width:15%;">Customer</th>
                <th style="width:8%;">Shift</th>
                <th style="width:12%;">Staff</th>
                <th style="width:10%;">Type</th>
                <th style="width:10%;">Amount</th>
                <th style="width:10%;">Payment</th>
                <th style="width:13%;">Date</th>
                <th style="width:10%;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$transactions): ?>
            <tr><td colspan="9" style="text-align:center;padding:40px;color:#888;">No transactions found</td></tr>
            <?php else: ?>
            <?php foreach ($transactions as $txn): ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($txn['transaction_id']); ?></strong></td>
                <td><?php echo htmlspecialchars($txn['customer_name'] ?? 'Walk-in Customer'); ?></td>
                <td><?php echo htmlspecialchars($txn['shift_name'] ?? $txn['shift_period'] ?? '-'); ?></td>
                <td><?php echo htmlspecialchars($txn['staff_name'] ?? 'Staff'); ?></td>
                <td><?php echo htmlspecialchars($txn['transaction_type'] ?? 'Merchandise'); ?></td>
                <td style="font-weight:700;">₱<?php echo number_format($txn['total_amount'], 2); ?></td>
                <td><?php echo htmlspecialchars($txn['payment_method'] ?? '-'); ?></td>
                <td><?php echo date('M d, Y h:i A', strtotime($txn['txn_date'])); ?></td>
                <td>
                    <button class="flt-btn flt-btn-search" style="height:28px;padding:0 10px;font-size:11px;" onclick='openAdjustModal(<?php echo json_encode($txn, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                        <i class="fas fa-edit"></i> Adjust
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- ADJUSTMENT HISTORY -->
<div id="tab_history" class="card" style="margin-top:0;display:none;">
    <div class="card-head">
        <div class="card-title">Adjustment History (<?php echo count($adjustments); ?>)</div>
    </div>
    <table class="adj-table">
        <thead>
            <tr>
                <th style="width:8%;">Adj ID</th>
                <th style="width:12%;">Transaction ID</th>
                <th style="width:15%;">Customer</th>
                <th style="width:10%;">Type</th>
                <th style="width:10%;">Original</th>
                <th style="width:10%;">Updated</th>
                <th style="width:10%;">Difference</th>
                <th style="width:13%;">Adjusted By</th>
                <th style="width:12%;">Date</th>
                <th style="width:10%;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$adjustments): ?>
            <tr><td colspan="10" style="text-align:center;padding:40px;color:#888;">No adjustments found</td></tr>
            <?php else: ?>
            <?php foreach ($adjustments as $adj): ?>
            <tr>
                <td><strong>#<?php echo $adj['adj_id']; ?></strong></td>
                <td><?php echo htmlspecialchars($adj['transaction_id']); ?></td>
                <td><?php echo htmlspecialchars($adj['customer'] ?? 'Walk-in'); ?></td>
                <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $adj['transaction_type']))); ?></td>
                <td>₱<?php echo number_format($adj['original_amount'], 2); ?></td>
                <td style="color:#002F70;font-weight:600;">₱<?php echo number_format($adj['updated_amount'], 2); ?></td>
                <td style="font-weight:700; color:<?php echo $adj['amount_difference'] >= 0 ? '#16a34a' : '#dc2626'; ?>;">
                    <?php echo ($adj['amount_difference'] >= 0 ? '+' : '') . '₱' . number_format($adj['amount_difference'], 2); ?>
                </td>
                <td><?php echo htmlspecialchars($adj['adjusted_by_name']); ?></td>
                <td><?php echo date('M d, Y h:i A', strtotime($adj['adjustment_date'])); ?></td>
                <td>
                    <button class="flt-btn flt-btn-search" style="height:28px;padding:0 10px;font-size:11px;" onclick="openAdjModal({
                        adjId: '#<?php echo $adj['adj_id']; ?>',
                        txnId: '<?php echo addslashes(htmlspecialchars($adj['transaction_id'])); ?>',
                        customer: '<?php echo addslashes(htmlspecialchars($adj['customer'])); ?>',
                        type: '<?php echo addslashes(htmlspecialchars(ucwords(str_replace('_', ' ', $adj['transaction_type'])))); ?>',
                        original: '₱<?php echo number_format($adj['original_amount'], 2); ?>',
                        updated: '₱<?php echo number_format($adj['updated_amount'], 2); ?>',
                        diff: '<?php echo ($adj['amount_difference'] >= 0 ? '+' : '') . '₱' . number_format($adj['amount_difference'], 2); ?>',
                        reason: '<?php echo addslashes(htmlspecialchars($adj['adjustment_reason'])); ?>',
                        remarks: '<?php echo addslashes(htmlspecialchars($adj['manager_remarks'] ?? '')); ?>',
                        by: '<?php echo addslashes(htmlspecialchars($adj['adjusted_by_name'])); ?>',
                        date: '<?php echo date('M d, Y h:i A', strtotime($adj['adjustment_date'])); ?>'
                    })"><i class="fas fa-eye"></i> View</button>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- ADJUSTMENT MODAL -->
<div id="adjustmentModal" class="modal">
    <div class="modal-card">
        <div class="modal-head">
            <div class="modal-title">Adjust Transaction</div>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="save_adjustment">
            <input type="hidden" name="transaction_id" id="adj_txn_id">
            <input type="hidden" name="transaction_type" id="adj_txn_type">
            <input type="hidden" name="original_amount" id="adj_orig_amt">
            <div class="modal-body">
                <!-- TRANSACTION INFORMATION (Read-Only) -->
                <div style="background:#f8fafc;padding:12px;border-radius:8px;margin-bottom:20px;">
                    <h4 style="margin:0 0 10px 0;font-size:13px;color:#002F70;font-weight:700;">Transaction Information</h4>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:12px;">
                        <div><strong>Transaction ID:</strong> <span id="adj_display_id"></span></div>
                        <div><strong>Customer Name:</strong> <span id="adj_display_customer"></span></div>
                        <div><strong>Transaction Type:</strong> <span id="adj_display_type"></span></div>
                        <div><strong>Current Amount:</strong> <span id="adj_display_amt"></span></div>
                    </div>
                </div>
                
                <!-- EDITABLE FIELDS -->
                <h4 style="margin:0 0 12px 0;font-size:13px;color:#002F70;font-weight:700;">Editable Fields</h4>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:15px;">
                    <div>
                        <label>Quantity <span style="color:red;">*</span></label>
                        <input type="number" name="quantity" id="adj_quantity" class="input" step="1" min="1" value="1" required>
                    </div>
                    <div>
                        <label>Unit Price <span style="color:red;">*</span></label>
                        <input type="number" name="unit_price" id="adj_unit_price" class="input" step="0.01" min="0" required>
                    </div>
                </div>
                <div style="margin-bottom:15px;">
                    <label>Service Fee</label>
                    <input type="number" name="service_fee" id="adj_service_fee" class="input" step="0.01" min="0" value="0">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:15px;">
                    <div>
                        <label>Payment Method</label>
                        <select name="payment_method" id="adj_payment_method" class="input">
                            <option value="Cash">Cash</option>
                            <option value="Card">Card</option>
                            <option value="E-Wallet">E-Wallet</option>
                            <option value="Petron E-Fuel">Petron E-Fuel</option>
                            <option value="Fleet Card">Fleet Card</option>
                            <option value="Credit">Credit</option>
                        </select>
                    </div>
                    <div>
                        <label>Payment Status</label>
                        <select name="payment_status" id="adj_payment_status" class="input">
                            <option value="Paid">Paid</option>
                            <option value="Partial Payment">Partial Payment</option>
                            <option value="Pending Payment">Pending Payment</option>
                        </select>
                    </div>
                </div>
                
                <!-- REQUIRED FIELDS -->
                <h4 style="margin:20px 0 12px 0;font-size:13px;color:#002F70;font-weight:700;">Required Fields</h4>
                <div style="margin-bottom:15px;">
                    <label>Adjustment Reason <span style="color:red;">*</span></label>
                    <select name="adjustment_reason" class="input" required>
                        <option value="">Select reason...</option>
                        <option value="Pricing Error">Pricing Error</option>
                        <option value="Quantity Mismatch">Quantity Mismatch</option>
                        <option value="Payment Method Change">Payment Method Change</option>
                        <option value="Customer Request">Customer Request</option>
                        <option value="System Error">System Error</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div>
                    <label>Manager Remarks</label>
                    <textarea name="manager_remarks" class="input" rows="3" placeholder="Additional notes..."></textarea>
                </div>
                
                <input type="hidden" name="customer_name" id="adj_customer_name">
            </div>
            <div class="modal-actions">
                <button type="button" class="flt-btn flt-btn-reset" onclick="closeModal()">Cancel</button>
                <button type="submit" class="flt-btn flt-btn-solid-primary">Save Adjustment</button>
            </div>
        </form>
    </div>
</div>

<!-- Adjustment Detail Modal -->
<div id="adjDetailModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.55);backdrop-filter:blur(4px);align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:16px;width:92%;max-width:580px;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden;animation:adjModalIn .2s ease;">
    <div style="background:linear-gradient(135deg,#003d7a,#00264D);padding:18px 22px;display:flex;align-items:center;justify-content:space-between;">
      <span style="color:#fff;font-size:15px;font-weight:700;"><i class="fas fa-sliders-h" style="margin-right:8px;"></i>Adjustment Details</span>
      <button onclick="closeAdjModal()" style="background:rgba(255,255,255,.15);border:none;color:#fff;width:30px;height:30px;border-radius:50%;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;">&times;</button>
    </div>
    <div style="padding:22px 24px;">
      <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <tbody id="adjModalBody"></tbody>
      </table>
    </div>
    <div style="padding:12px 24px 18px;text-align:right;border-top:1px solid #f1f5f9;">
      <button onclick="closeAdjModal()" class="flt-btn flt-btn-reset" style="height:34px;"><i class="fas fa-times"></i> Close</button>
    </div>
  </div>
</div>
<style>
@keyframes adjModalIn{from{opacity:0;transform:translateY(-16px)}to{opacity:1;transform:none}}
#adjModalBody tr{border-bottom:1px solid #f1f5f9;}
#adjModalBody td{padding:9px 8px;vertical-align:top;}
#adjModalBody td:first-child{font-weight:700;color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:.4px;width:160px;white-space:nowrap;}
#adjModalBody td:last-child{color:#1e293b;font-weight:500;}
</style>

<script>
function openAdjustModal(txn) {
    document.getElementById('adj_txn_id').value = txn.transaction_id;
    document.getElementById('adj_txn_type').value = txn.transaction_type || 'merchandise';
    document.getElementById('adj_orig_amt').value = txn.total_amount;
    document.getElementById('adj_customer_name').value = txn.customer_name || 'Walk-in Customer';
    
    // Display transaction info
    document.getElementById('adj_display_id').textContent = txn.transaction_id;
    document.getElementById('adj_display_customer').textContent = txn.customer_name || 'Walk-in Customer';
    document.getElementById('adj_display_type').textContent = txn.transaction_type || 'Merchandise';
    document.getElementById('adj_display_amt').textContent = '₱' + parseFloat(txn.total_amount).toFixed(2);
    
    // Pre-fill editable fields
    document.getElementById('adj_quantity').value = txn.quantity || 1;
    document.getElementById('adj_unit_price').value = txn.unit_price || txn.total_amount || 0;
    document.getElementById('adj_service_fee').value = 0;
    document.getElementById('adj_payment_method').value = txn.payment_method || 'Cash';
    document.getElementById('adj_payment_status').value = txn.payment_status || 'Paid';
    
    document.getElementById('adjustmentModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('adjustmentModal').style.display = 'none';
}

// Close modal on overlay click
document.getElementById('adjustmentModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

function openAdjModal(d){
  var diff = parseFloat((d.diff||'').replace(/[^₱0-9.\-]/g,'').replace('₱','')) || 0;
  var diffColor = diff >= 0 ? '#16a34a' : '#dc2626';
  var rows=[
    ['Adjustment ID',    '<strong>'+d.adjId+'</strong>'],
    ['Transaction ID',   d.txnId],
    ['Customer',         d.customer],
    ['Type',             d.type],
    ['Original Amount',  d.original],
    ['Updated Amount',   '<strong style="color:#002F70;font-size:15px;">'+d.updated+'</strong>'],
    ['Difference',       '<strong style="color:'+diffColor+';font-size:14px;">'+d.diff+'</strong>'],
    ['Reason',           d.reason],
    ['Manager Remarks',  d.remarks || '—'],
    ['Adjusted By',      d.by],
    ['Adjustment Date',  d.date]
  ];
  var html='';
  rows.forEach(function(r){ html+='<tr><td>'+r[0]+'</td><td>'+r[1]+'</td></tr>'; });
  document.getElementById('adjModalBody').innerHTML=html;
  document.getElementById('adjDetailModal').style.display='flex';
}
function closeAdjModal(){
  document.getElementById('adjDetailModal').style.display='none';
}
document.getElementById('adjDetailModal').addEventListener('click',function(e){
  if(e.target===this) closeAdjModal();
});

// ── Tab Switching Function ──────────────────────────────────────────────
function switchTab(tabName) {
    // Hide all tabs
    document.getElementById('tab_transactions').style.display = 'none';
    document.getElementById('tab_history').style.display = 'none';
    
    // Show selected tab
    document.getElementById('tab_' + tabName).style.display = 'block';
    
    // Update button styles
    var transBtn = document.getElementById('tabBtn_transactions');
    var histBtn = document.getElementById('tabBtn_history');
    var pageHeadButtons = document.getElementById('pageHeadButtons');
    
    if (tabName === 'transactions') {
        transBtn.className = 'flt-btn flt-btn-solid-primary';
        histBtn.className = 'flt-btn flt-btn-reset';
        // Clear page-head buttons for Transactions tab
        pageHeadButtons.innerHTML = '';
    } else {
        transBtn.className = 'flt-btn flt-btn-reset';
        histBtn.className = 'flt-btn flt-btn-solid-primary';
        // Show export and back buttons for Adjustment History tab
        pageHeadButtons.innerHTML = `
            <a href="?export=excel&table=adjustments&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&staff=<?php echo urlencode($filter_staff); ?>&type=<?php echo urlencode($filter_type); ?>" class="flt-btn flt-btn-excel"><i class="fas fa-file-excel"></i> Excel</a>
            <a href="?export=csv&table=adjustments&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&staff=<?php echo urlencode($filter_staff); ?>&type=<?php echo urlencode($filter_type); ?>" class="flt-btn flt-btn-search"><i class="fas fa-file-csv"></i> CSV</a>
            <button onclick="switchTab('transactions')" class="flt-btn flt-btn-reset">Back</button>
        `;
    }
}

// Initialize - show transactions tab by default
window.addEventListener('DOMContentLoaded', function() {
    switchTab('transactions');
});
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
