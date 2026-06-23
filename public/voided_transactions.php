<?php
/**
 * Voided Transactions
 * Manage cancelled or invalid transactions
 */
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'voided_transactions';
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

// ── Create voided_transactions table if not exists ────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS voided_transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transaction_id VARCHAR(50) NOT NULL,
            transaction_type ENUM('job_order', 'merchandise', 'combined') NOT NULL,
            customer_name VARCHAR(255) DEFAULT NULL,
            amount DECIMAL(10,2) NOT NULL,
            void_reason VARCHAR(255) NOT NULL,
            manager_remarks TEXT,
            voided_by INT NOT NULL,
            void_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            station_id INT NOT NULL,
            INDEX idx_transaction_id (transaction_id),
            INDEX idx_void_date (void_date),
            INDEX idx_station_id (station_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Exception $e) {
    error_log("Table creation: " . $e->getMessage());
}

// ── Handle POST: Void Transaction ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'void_transaction') {
    try {
        $pdo->beginTransaction();
        
        $txn_id = trim($_POST['transaction_id'] ?? '');
        $txn_type = trim($_POST['transaction_type'] ?? 'merchandise');
        $customer = trim($_POST['customer_name'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $void_reason = trim($_POST['void_reason'] ?? '');
        $remarks = trim($_POST['manager_remarks'] ?? '');
        
        if (!$txn_id || !$void_reason) {
            throw new Exception('Transaction ID and void reason are required.');
        }
        
        // Insert into voided_transactions table
        $stmt = $pdo->prepare("
            INSERT INTO voided_transactions (
                transaction_id, transaction_type, customer_name,
                amount, void_reason, manager_remarks,
                voided_by, void_date, station_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)
        ");
        $stmt->execute([
            $txn_id, $txn_type, $customer,
            $amount, $void_reason, $remarks,
            $me['id'], $station_id
        ]);
        
        // Update the original transaction
        if (in_array($txn_type, ['merchandise', 'combined', 'job_order'])) {
            $void_note = "Voided by " . $me['name'] . ": " . $void_reason;
            if ($remarks) $void_note .= " | " . $remarks;
            
            $pdo->prepare("
                UPDATE merchandise_transactions 
                SET validation_status = 'Voided',
                    validated_by = ?,
                    validated_at = NOW(),
                    manager_notes = CONCAT(COALESCE(manager_notes, ''), '\n', ?),
                    inventory_deducted = 0
                WHERE transaction_id = ? AND station_id = ?
            ")->execute([$me['id'], $void_note, $txn_id, $station_id]);
            
            // Restore inventory if it was deducted
            // First get the transaction internal ID
            $txn_internal = $pdo->prepare("SELECT id FROM merchandise_transactions WHERE transaction_id = ? AND station_id = ?");
            $txn_internal->execute([$txn_id, $station_id]);
            $txn_internal_id = $txn_internal->fetchColumn();
            
            if ($txn_internal_id) {
                $items_stmt = $pdo->prepare("
                    SELECT product_id, quantity
                    FROM merchandise_transaction_items
                    WHERE transaction_id = ?
                      AND product_id IS NOT NULL
                ");
                $items_stmt->execute([$txn_internal_id]);
                while ($item = $items_stmt->fetch(PDO::FETCH_ASSOC)) {
                    $pdo->prepare("
                        UPDATE station_inventory
                        SET stock_level = stock_level + ?, last_updated = NOW()
                        WHERE station_id = ? AND product_id = ?
                    ")->execute([$item['quantity'], $station_id, $item['product_id']]);
                }
            }
        }
        
        $pdo->commit();
        $_SESSION['success'] = 'Transaction voided successfully.';
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error'] = 'Error: ' . $e->getMessage();
    }
    header('Location: voided_transactions.php');
    exit;
}

// ── Filters ────────────────────────────────────────────────────────────────────
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$filter_staff = $_GET['staff'] ?? '';

// ── Fetch KPI Data ─────────────────────────────────────────────────────────────
$kpi = ['total' => 0, 'today' => 0, 'amount' => 0.00];
try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) AS total_voids,
            SUM(CASE WHEN DATE(void_date) = CURDATE() THEN 1 ELSE 0 END) AS today_voids,
            SUM(amount) AS total_voided_amount
        FROM voided_transactions
        WHERE station_id = ?
    ");
    $stmt->execute([$station_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $kpi['total'] = (int)$row['total_voids'];
        $kpi['today'] = (int)$row['today_voids'];
        $kpi['amount'] = (float)$row['total_voided_amount'];
    }
} catch (Exception $e) {
    error_log("KPI error: " . $e->getMessage());
}

// ── Fetch Voided Records ───────────────────────────────────────────────────────
$where = ["vt.station_id = ?"];
$params = [$station_id];

if ($date_from && $date_to) {
    $where[] = "DATE(vt.void_date) BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;
}
if ($filter_staff) {
    $where[] = "vt.voided_by = ?";
    $params[] = $filter_staff;
}

$voided = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            vt.*,
            u.name AS voided_by_name
        FROM voided_transactions vt
        LEFT JOIN users u ON u.id = vt.voided_by
        WHERE " . implode(' AND ', $where) . "
        ORDER BY vt.void_date DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    $voided = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Voided fetch error: " . $e->getMessage());
}

// ── Fetch active transactions for voiding ──────────────────────────────────────
$active_transactions = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            mt.id,
            mt.transaction_id,
            mt.customer_name,
            mt.total_amount,
            mt.transaction_type,
            COALESCE(mt.transaction_date, mt.created_at) AS txn_date,
            u.name AS staff_name
        FROM merchandise_transactions mt
        LEFT JOIN users u ON u.id = mt.staff_id
        WHERE mt.station_id = ?
          AND LOWER(COALESCE(mt.validation_status, '')) NOT IN ('voided')
        ORDER BY mt.created_at DESC
        LIMIT 100
    ");
    $stmt->execute([$station_id]);
    $active_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Staff list for filter ──────────────────────────────────────────────────────
$staff_list = [];
try {
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE station_id = ? AND role IN ('manager','supervisor','admin') ORDER BY name");
    $stmt->execute([$station_id]);
    $staff_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Export ────────────────────────────────────────────────────────────────────
$export = $_GET['export'] ?? '';
if (in_array($export, ['excel', 'csv'])) {
    $fn = 'voided_transactions_' . date('Ymd_His');
    if ($export === 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header("Content-Disposition: attachment; filename=\"{$fn}.xls\"");
    } else {
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"{$fn}.csv\"");
    }
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Void ID', 'Transaction ID', 'Customer Name', 'Transaction Type', 'Amount', 'Void Reason', 'Voided By', 'Void Date']);
    
    foreach ($voided as $v) {
        fputcsv($out, [
            'VOID-' . $v['id'],
            $v['transaction_id'],
            $v['customer_name'] ?? 'Walk-in Customer',
            ucwords(str_replace('_', ' ', $v['transaction_type'])),
            '₱' . number_format($v['amount'], 2),
            $v['void_reason'],
            $v['voided_by_name'] ?? 'Manager',
            date('M d, Y h:i A', strtotime($v['void_date']))
        ]);
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

/* Solid action buttons for forms and modals */
.flt-btn-solid-primary { color: #fff !important; background: #002F70 !important; border-color: #002F70 !important; }
.flt-btn-solid-primary:hover { background: #001a3d !important; border-color: #001a3d !important; }
.flt-btn-solid-danger { color: #fff !important; background: #dc2626 !important; border-color: #dc2626 !important; }
.flt-btn-solid-danger:hover { background: #b91c1c !important; border-color: #b91c1c !important; }

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
    background: linear-gradient(135deg, #002F70 0%, #003d8a 100%);
    border-left: none;
}
.txn-kpi-card.total-amount-card .txn-kpi-lbl {
    color: #93c5fd;
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

.void-table { width:100%; border-collapse:collapse; font-size:11px; }
.void-table thead tr { background:#002F70; }
.void-table th { padding:9px 10px; text-align:left; font-size:11px; font-weight:700; color:#fff; text-transform:uppercase; letter-spacing:.4px; }
.void-table tbody tr { border-bottom:1px solid #f1f5f9; transition:background .1s; }
.void-table tbody tr:hover td { background:#fef2f2; }
.void-table tbody td { padding:9px 10px; color:#334155; vertical-align:middle; background:#fff; font-size:11px; }

/* == MODAL == */
.modal { position:fixed; inset:0; display:none; align-items:center; justify-content:center; z-index:9999; background:rgba(15,23,42,0.5); }
.modal-card { position:relative; background:#fff; border-radius:12px; max-width:600px; width:90%; max-height:90vh; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,.1); }
.modal-head { display:flex; justify-content:space-between; align-items:center; padding:14px 18px; background:#dc2626; color:#fff; }
.modal-title { font-weight:700; font-size:15px; }
.modal-close { background:none; border:none; color:#fff; font-size:20px; cursor:pointer; }
.modal-body { padding:20px; overflow-y:auto; }
.modal-body label { font-size:11px; font-weight:600; color:#475569; text-transform:uppercase; display:block; margin-bottom:4px; }
.modal-body .input { width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; box-sizing:border-box; }
@media print {
    .action-bar, .sidebar, .main-sidebar, .navbar, .filters, form, .flt-btn, .modal, button, .actions, .page-head.txn-page-head, .card-head div { display:none!important; }
    body { background:#fff; margin:0; padding:10px; }
    .card { border:none; box-shadow:none; margin-top:10px !important; }
    table { width:100%!important; font-size:10px; }
}
</style>

<div class="page-head txn-page-head">
    <div>
        <h1 class="h1"><i class="fas fa-ban"></i> Voided Transactions</h1>
        <div class="sub">Review and monitor voided, cancelled, and reversed transactions.</div>
    </div>
    <div class="actions txn-head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <a href="<?= in_array($role, ['admin', 'superadmin']) ? 'admin_dashboard.php' : 'manager_dashboard.php'; ?>" class="flt-btn flt-btn-reset"><i class="fas fa-arrow-left"></i> Back</a>
        <a href="?export=excel&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&staff=<?php echo urlencode($filter_staff); ?>" class="flt-btn flt-btn-excel"><i class="fas fa-file-excel"></i> Excel</a>
        <a href="?export=csv&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&staff=<?php echo urlencode($filter_staff); ?>" class="flt-btn flt-btn-search"><i class="fas fa-file-csv"></i> CSV</a>
        <button class="flt-btn flt-btn-pdf" onclick="window.print()"><i class="fas fa-file-pdf"></i> PDF</button>
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
    <div class="txn-kpi-card">
        <div class="txn-kpi-lbl"><i class="fas fa-ban"></i> Total Voided Transactions</div>
        <div class="txn-kpi-val"><?php echo $kpi['total']; ?></div>
    </div>
    <div class="txn-kpi-card">
        <div class="txn-kpi-lbl"><i class="fas fa-calendar-day"></i> Voided Today</div>
        <div class="txn-kpi-val"><?php echo $kpi['today']; ?></div>
    </div>
    <div class="txn-kpi-card total-amount-card">
        <div class="txn-kpi-lbl"><i class="fas fa-peso-sign"></i> Total Voided Amount</div>
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
            <button type="submit" class="flt-btn flt-btn-solid-primary"><i class="fas fa-filter"></i> Apply</button>
        </div>
    </form>
</div>

<!-- VOIDED TRANSACTIONS TABLE -->
<div class="card" style="margin-top:20px;">
    <div class="card-head">
        <div class="card-title">Voided Transactions (<?php echo count($voided); ?>)</div>
    </div>
    <table class="void-table">
        <thead>
            <tr>
                <th style="width:8%;">Void ID</th>
                <th style="width:12%;">Transaction ID</th>
                <th style="width:15%;">Customer Name</th>
                <th style="width:10%;">Transaction Type</th>
                <th style="width:10%;">Amount</th>
                <th style="width:15%;">Void Reason</th>
                <th style="width:12%;">Voided By</th>
                <th style="width:13%;">Void Date</th>
                <th style="width:10%;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$voided): ?>
            <tr><td colspan="9" style="text-align:center;padding:40px;color:#888;">No voided transactions found</td></tr>
            <?php else: ?>
            <?php foreach ($voided as $v): ?>
            <tr>
                <td><strong>#<?php echo $v['id']; ?></strong></td>
                <td><?php echo htmlspecialchars($v['transaction_id']); ?></td>
                <td><?php echo htmlspecialchars($v['customer_name'] ?? 'Walk-in Customer'); ?></td>
                <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $v['transaction_type']))); ?></td>
                <td style="color:#dc2626;font-weight:600;">₱<?php echo number_format($v['amount'], 2); ?></td>
                <td><?php echo htmlspecialchars($v['void_reason']); ?></td>
                <td><?php echo htmlspecialchars($v['voided_by_name'] ?? 'Manager'); ?></td>
                <td><?php echo date('M d, Y h:i A', strtotime($v['void_date'])); ?></td>
                <td>
                    <button class="flt-btn flt-btn-search" style="height:28px;padding:0 10px;font-size:11px;" onclick="openVoidDetailModal({
                        voidId: '#<?php echo $v['id']; ?>',
                        txnId: '<?php echo addslashes(htmlspecialchars($v['transaction_id'])); ?>',
                        customer: '<?php echo addslashes(htmlspecialchars($v['customer_name'] ?? 'Walk-in Customer')); ?>',
                        type: '<?php echo addslashes(htmlspecialchars(ucwords(str_replace('_', ' ', $v['transaction_type'])))); ?>',
                        amount: '₱<?php echo number_format($v['amount'], 2); ?>',
                        reason: '<?php echo addslashes(htmlspecialchars($v['void_reason'])); ?>',
                        remarks: '<?php echo addslashes(htmlspecialchars($v['manager_remarks'] ?? '')); ?>',
                        by: '<?php echo addslashes(htmlspecialchars($v['voided_by_name'] ?? 'Manager')); ?>',
                        date: '<?php echo date('M d, Y h:i A', strtotime($v['void_date'])); ?>'
                    })"><i class="fas fa-eye"></i> View</button>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- ACTIVE TRANSACTIONS (For Voiding) -->
<div class="card" style="margin-top:20px;">
    <div class="card-head">
        <div class="card-title">Active Transactions (Can be voided)</div>
    </div>
    <table class="void-table">
        <thead>
            <tr>
                <th>Transaction ID</th>
                <th>Customer</th>
                <th>Type</th>
                <th>Amount</th>
                <th>Staff</th>
                <th>Date</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$active_transactions): ?>
            <tr><td colspan="7" style="text-align:center;padding:40px;color:#888;">No active transactions</td></tr>
            <?php else: ?>
            <?php foreach ($active_transactions as $txn): ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($txn['transaction_id']); ?></strong></td>
                <td><?php echo htmlspecialchars($txn['customer_name'] ?? 'Walk-in Customer'); ?></td>
                <td><?php echo htmlspecialchars($txn['transaction_type'] ?? 'Merchandise'); ?></td>
                <td style="font-weight:700;">₱<?php echo number_format($txn['total_amount'], 2); ?></td>
                <td><?php echo htmlspecialchars($txn['staff_name'] ?? 'Staff'); ?></td>
                <td><?php echo date('M d, Y', strtotime($txn['txn_date'])); ?></td>
                <td>
                    <button class="flt-btn flt-btn-pdf" style="height:28px;padding:0 10px;font-size:11px;" onclick='openVoidModal(<?php echo json_encode($txn, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                        <i class="fas fa-ban"></i> Void
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- VOID MODAL -->
<div id="voidModal" class="modal">
    <div class="modal-card">
        <div class="modal-head">
            <div class="modal-title">Void Transaction</div>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="void_transaction">
            <input type="hidden" name="transaction_id" id="void_txn_id">
            <input type="hidden" name="transaction_type" id="void_txn_type">
            <input type="hidden" name="amount" id="void_amount">
            <input type="hidden" name="customer_name" id="void_customer">
            <div class="modal-body">
                <!-- INFORMATION (Read-Only) -->
                <div style="background:#fef2f2;padding:12px;border-radius:8px;margin-bottom:20px;border:1px solid #fecaca;">
                    <h4 style="margin:0 0 10px 0;font-size:13px;color:#dc2626;font-weight:700;">⚠️ Warning: This action cannot be undone</h4>
                    <div style="font-size:12px;color:#991b1b;">Voiding this transaction will mark it as cancelled and restore any inventory that was deducted.</div>
                </div>
                
                <div style="background:#f8fafc;padding:12px;border-radius:8px;margin-bottom:20px;">
                    <h4 style="margin:0 0 10px 0;font-size:13px;color:#002F70;font-weight:700;">Transaction Information</h4>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:12px;">
                        <div><strong>Transaction ID:</strong> <span id="void_display_id"></span></div>
                        <div><strong>Customer Name:</strong> <span id="void_display_customer"></span></div>
                        <div><strong>Transaction Type:</strong> <span id="void_display_type"></span></div>
                        <div><strong>Amount:</strong> <span id="void_display_amt"></span></div>
                    </div>
                </div>
                
                <!-- REQUIRED FIELDS -->
                <h4 style="margin:0 0 12px 0;font-size:13px;color:#002F70;font-weight:700;">Required Fields</h4>
                <div style="margin-bottom:15px;">
                    <label>Void Reason <span style="color:red;">*</span></label>
                    <select name="void_reason" class="input" required>
                        <option value="">Select reason...</option>
                        <option value="Duplicate Transaction">Duplicate Transaction</option>
                        <option value="Wrong Customer">Wrong Customer</option>
                        <option value="Incorrect Amount">Incorrect Amount</option>
                        <option value="Payment Failed">Payment Failed</option>
                        <option value="Customer Request">Customer Request</option>
                        <option value="System Error">System Error</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div>
                    <label>Manager Remarks</label>
                    <textarea name="manager_remarks" class="input" rows="3" placeholder="Additional notes about why this transaction is being voided..."></textarea>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="flt-btn flt-btn-reset" onclick="closeModal()">Cancel</button>
                <button type="submit" class="flt-btn flt-btn-solid-danger">Confirm Void</button>
            </div>
        </form>
    </div>
</div>

<!-- Void Detail Modal -->
<div id="voidDetailModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.55);backdrop-filter:blur(4px);align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:16px;width:92%;max-width:580px;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden;animation:voidModalIn .2s ease;">
    <div style="background:linear-gradient(135deg,#dc2626,#b91c1c);padding:18px 22px;display:flex;align-items:center;justify-content:space-between;">
      <span style="color:#fff;font-size:15px;font-weight:700;"><i class="fas fa-ban" style="margin-right:8px;"></i>Voided Transaction Details</span>
      <button onclick="closeVoidDetailModal()" style="background:rgba(255,255,255,.15);border:none;color:#fff;width:30px;height:30px;border-radius:50%;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;">&times;</button>
    </div>
    <div style="padding:22px 24px;">
      <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <tbody id="voidDetailModalBody"></tbody>
      </table>
    </div>
    <div style="padding:12px 24px 18px;text-align:right;border-top:1px solid #f1f5f9;">
      <button onclick="closeVoidDetailModal()" class="flt-btn flt-btn-reset" style="height:34px;"><i class="fas fa-times"></i> Close</button>
    </div>
  </div>
</div>
<style>
@keyframes voidModalIn{from{opacity:0;transform:translateY(-16px)}to{opacity:1;transform:none}}
#voidDetailModalBody tr{border-bottom:1px solid #f1f5f9;}
#voidDetailModalBody td{padding:9px 8px;vertical-align:top;}
#voidDetailModalBody td:first-child{font-weight:700;color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:.4px;width:160px;white-space:nowrap;}
#voidDetailModalBody td:last-child{color:#1e293b;font-weight:500;}
</style>

<script>
function openVoidModal(txn) {
    document.getElementById('void_txn_id').value = txn.transaction_id;
    document.getElementById('void_txn_type').value = txn.transaction_type || 'merchandise';
    document.getElementById('void_amount').value = txn.total_amount;
    document.getElementById('void_customer').value = txn.customer_name || 'Walk-in Customer';
    
    // Display transaction info
    document.getElementById('void_display_id').textContent = txn.transaction_id;
    document.getElementById('void_display_customer').textContent = txn.customer_name || 'Walk-in Customer';
    document.getElementById('void_display_type').textContent = txn.transaction_type || 'Merchandise';
    document.getElementById('void_display_amt').textContent = '₱' + parseFloat(txn.total_amount).toFixed(2);
    
    document.getElementById('voidModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('voidModal').style.display = 'none';
}

// Close modal on overlay click
document.getElementById('voidModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

function openVoidDetailModal(d){
  var rows=[
    ['Void ID',          '<strong>'+d.voidId+'</strong>'],
    ['Transaction ID',   d.txnId],
    ['Customer',         d.customer],
    ['Type',             d.type],
    ['Amount',           '<strong style="color:#dc2626;font-size:15px;">'+d.amount+'</strong>'],
    ['Void Reason',      d.reason],
    ['Remarks / Notes',  d.remarks || '—'],
    ['Voided By',        d.by],
    ['Void Date',        d.date]
  ];
  var html='';
  rows.forEach(function(r){ html+='<tr><td>'+r[0]+'</td><td>'+r[1]+'</td></tr>'; });
  document.getElementById('voidDetailModalBody').innerHTML=html;
  document.getElementById('voidDetailModal').style.display='flex';
}
function closeVoidDetailModal(){
  document.getElementById('voidDetailModal').style.display='none';
}
document.getElementById('voidDetailModal').addEventListener('click',function(e){
  if(e.target===this) closeVoidDetailModal();
});
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
