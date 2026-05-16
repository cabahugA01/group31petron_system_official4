<?php
$page_id = 'mgr_audit_trail';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$station_id = user_station_id();
$role       = role_key($me['role'] ?? '');

if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Manager access required.';
    header('Location: dashboard.php'); exit;
}

// ── Filters ───────────────────────────────────────────────────────────────────
$start      = $_GET['start']      ?? date('Y-m-d', strtotime('-30 days'));
$end        = $_GET['end']        ?? date('Y-m-d');
$action_f   = trim($_GET['action_f'] ?? '');
$txn_search = trim($_GET['txn_search'] ?? '');

// ── Export CSV ────────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit_trail_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Audit ID', 'Transaction/JO ID', 'Manager ID', 'Manager Name', 'Action', 'Details', 'Timestamp']);

    $sql = "SELECT at.id, at.transaction_id, at.manager_id, u.name AS manager_name,
                   at.action_type, COALESCE(at.new_value, at.notes, at.reason, '') AS details,
                   at.created_at
            FROM audit_trail at
            LEFT JOIN users u ON at.manager_id = u.id
            WHERE at.station_id = ?
              AND DATE(at.created_at) BETWEEN ? AND ?";
    $params = [$station_id, $start, $end];
    if ($action_f !== '') { $sql .= " AND LOWER(at.action_type) LIKE ?"; $params[] = '%' . strtolower($action_f) . '%'; }
    if ($txn_search !== '') { $sql .= " AND at.transaction_id LIKE ?"; $params[] = '%' . $txn_search . '%'; }
    $sql .= " ORDER BY at.created_at DESC";

    try {
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, [$row['id'], $row['transaction_id'], $row['manager_id'], $row['manager_name'], $row['action_type'], $row['details'], $row['created_at']]);
        }
    } catch (Exception $e) {}
    fclose($out); exit;
}

// ── Fetch audit trail ─────────────────────────────────────────────────────────
$audit_logs = [];
$total_logs = 0;
try {
    $sql = "SELECT at.id, at.transaction_id, at.manager_id, u.name AS manager_name,
                   at.action_type, COALESCE(at.new_value, at.notes, at.reason, '') AS details,
                   at.created_at
            FROM audit_trail at
            LEFT JOIN users u ON at.manager_id = u.id
            WHERE at.station_id = ?
              AND DATE(at.created_at) BETWEEN ? AND ?";
    $params = [$station_id, $start, $end];
    if ($action_f !== '') { $sql .= " AND LOWER(at.action_type) LIKE ?"; $params[] = '%' . strtolower($action_f) . '%'; }
    if ($txn_search !== '') { $sql .= " AND at.transaction_id LIKE ?"; $params[] = '%' . $txn_search . '%'; }
    $sql .= " ORDER BY at.created_at DESC LIMIT 500";
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    $audit_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_logs = count($audit_logs);
} catch (Exception $e) {
    $_SESSION['error'] = 'Could not load audit trail: ' . $e->getMessage();
}

// ── Action type counts ────────────────────────────────────────────────────────
$action_counts = [];
foreach ($audit_logs as $log) {
    $a = strtolower($log['action_type'] ?? 'other');
    $action_counts[$a] = ($action_counts[$a] ?? 0) + 1;
}

// ── Customer Transparency data (moved from Customer Module) ──────────────────
$trans_bal_col = 'balance'; // default
try {
    $trans_avail = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
    $trans_bal_col = in_array('credit_balance', $trans_avail) ? 'credit_balance'
                   : (in_array('balance', $trans_avail) ? 'balance' : '0');
} catch (Exception $e) {}

$transparency_data = [];
try {
    $s = $pdo->prepare("SELECT c.id, c.name,
        COALESCE($trans_bal_col,0) AS balance,
        COALESCE(credit_limit,0) AS credit_limit,
        c.status, COALESCE(c.contact_number,'—') AS contact_number,
        COALESCE(c.id_type,'—') AS id_type,
        (SELECT COUNT(*) FROM merchandise_transactions mt WHERE mt.customer_id=c.id
         AND mt.payment_method IN ('Account Receivable','Credit','Utang','utang','credit')) AS utang_count,
        (SELECT COALESCE(SUM(total_amount),0) FROM merchandise_transactions mt WHERE mt.customer_id=c.id
         AND mt.payment_method IN ('Account Receivable','Credit','Utang','utang','credit')) AS total_utang
        FROM customers c WHERE c.station_id=? ORDER BY balance DESC");
    $s->execute([$station_id]);
    $transparency_data = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Customer History data (merged into Transparency tab) ──────────────────────
$hist_customers     = [];
$hist_selected_id   = isset($_GET['cust_id']) ? (int)$_GET['cust_id'] : 0;
$hist_filter_type   = $_GET['hist_type']   ?? '';
$hist_filter_status = $_GET['hist_status'] ?? '';
$hist_filter_date   = $_GET['hist_date']   ?? '';
$hist_records       = [];
$hist_customer_info = null;
try {
    $s = $pdo->prepare("SELECT id, name, balance, credit_limit, status FROM customers WHERE station_id=? ORDER BY name ASC");
    $s->execute([$station_id]);
    $hist_customers = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

if ($hist_selected_id) {
    foreach ($hist_customers as $hc) {
        if ($hc['id'] === $hist_selected_id) { $hist_customer_info = $hc; break; }
    }
    try {
        $jo_cols = $pdo->query("SHOW COLUMNS FROM job_orders")->fetchAll(PDO::FETCH_COLUMN);
        $mt_cols = $pdo->query("SHOW COLUMNS FROM merchandise_transactions")->fetchAll(PDO::FETCH_COLUMN);
        $has_jo_credit = in_array('credit_customer_id', $jo_cols);
        $has_mt_cid    = in_array('customer_id', $mt_cols);
        $has_mt_credit = in_array('credit_customer_id', $mt_cols);
        $jo_num_col    = in_array('job_order_number', $jo_cols) ? 'job_order_number'
                       : (in_array('job_order_id', $jo_cols) ? 'job_order_id' : 'NULL');
        $jo_svc_col    = in_array('service_type', $jo_cols) ? 'service_type'
                       : (in_array('service_description', $jo_cols) ? 'service_description' : "''");
        $jo_pay_stat   = in_array('payment_status', $jo_cols) ? 'payment_status' : "''";
        $jo_amt_paid   = in_array('amount_paid', $jo_cols) ? 'amount_paid' : '0';
        $jo_total      = in_array('actual_cost', $jo_cols) ? 'COALESCE(actual_cost, estimated_cost, 0)'
                       : (in_array('estimated_cost', $jo_cols) ? 'COALESCE(estimated_cost, 0)' : '0');
        $jo_plate      = in_array('vehicle_plate', $jo_cols) ? 'vehicle_plate' : "''";
        $jo_cust_cond  = $has_jo_credit ? '(jo.customer_id=? OR jo.credit_customer_id=?)' : 'jo.customer_id=?';
        $jo_params     = $has_jo_credit ? [$station_id, $hist_selected_id, $hist_selected_id] : [$station_id, $hist_selected_id];
        $mt_date_col   = in_array('transaction_date', $mt_cols) ? 'COALESCE(mt.transaction_date, mt.created_at)' : 'mt.created_at';
        $mt_cust_cond  = $has_mt_credit ? '(mt.customer_id=? OR mt.credit_customer_id=?)'
                       : ($has_mt_cid ? 'mt.customer_id=?' : '1=0');
        $mt_params     = $has_mt_credit ? [$station_id, $hist_selected_id, $hist_selected_id]
                       : ($has_mt_cid ? [$station_id, $hist_selected_id] : [$station_id]);
        $jo_date_f = $mt_date_f = '';
        if ($hist_filter_date) {
            $jo_date_f = " AND DATE(jo.created_at) = " . $pdo->quote($hist_filter_date);
            $mt_date_f = " AND DATE($mt_date_col) = " . $pdo->quote($hist_filter_date);
        }
        $jo_rows = [];
        if ($hist_filter_type === '' || $hist_filter_type === 'job_order') {
            $st2 = $pdo->prepare("SELECT 'job_order' AS record_type, jo.id,
                COALESCE($jo_num_col, CONCAT('JO-', jo.id)) AS ref_number,
                COALESCE($jo_svc_col, '—') AS service_label, '' AS merch_items_summary,
                $jo_total AS total_amount, $jo_amt_paid AS amount_paid,
                COALESCE($jo_pay_stat, 'Unpaid') AS payment_status,
                jo.payment_method, jo.status AS txn_status, jo.created_at AS txn_date,
                $jo_plate AS vehicle_plate
                FROM job_orders jo WHERE jo.station_id=? AND $jo_cust_cond $jo_date_f ORDER BY jo.created_at DESC");
            $st2->execute($jo_params);
            $jo_rows = $st2->fetchAll(PDO::FETCH_ASSOC);
        }
        $mt_rows = [];
        if (($hist_filter_type === '' || $hist_filter_type === 'merchandise') && ($has_mt_cid || $has_mt_credit)) {
            $st2 = $pdo->prepare("SELECT 'merchandise' AS record_type, mt.id,
                COALESCE(mt.transaction_id, CONCAT('MT-', mt.id)) AS ref_number,
                COALESCE(mt.job_order_service, '') AS service_label, '' AS merch_items_summary,
                COALESCE(mt.total_amount, 0) AS total_amount, COALESCE(mt.total_amount, 0) AS amount_paid,
                COALESCE(mt.status, 'Unpaid') AS payment_status, mt.payment_method,
                COALESCE(mt.validation_status, mt.status, 'Pending') AS txn_status,
                $mt_date_col AS txn_date, '' AS vehicle_plate
                FROM merchandise_transactions mt WHERE mt.station_id=? AND $mt_cust_cond $mt_date_f ORDER BY $mt_date_col DESC");
            $st2->execute($mt_params);
            $mt_rows = $st2->fetchAll(PDO::FETCH_ASSOC);
            foreach ($mt_rows as &$mtr) {
                try {
                    $is2 = $pdo->prepare("SELECT COALESCE(ip.product_name, mti.product_name, 'Item') AS pname, mti.quantity
                        FROM merchandise_transaction_items mti
                        LEFT JOIN inventory_products ip ON ip.id = mti.product_id
                        WHERE mti.transaction_id = ? LIMIT 5");
                    $is2->execute([$mtr['id']]);
                    $items2 = $is2->fetchAll(PDO::FETCH_ASSOC);
                    $mtr['merch_items_summary'] = implode(', ', array_map(fn($i) => $i['pname'] . ' ×' . $i['quantity'], $items2));
                } catch (Exception $e) {}
            }
            unset($mtr);
        }
        $hist_records = array_merge($jo_rows, $mt_rows);
        usort($hist_records, fn($a, $b) => strtotime($b['txn_date']) - strtotime($a['txn_date']));
        foreach ($hist_records as &$hr) {
            $ps = strtolower(trim($hr['payment_status'] ?? ''));
            $tot = (float)$hr['total_amount']; $paid = (float)$hr['amount_paid'];
            if ($ps === 'paid' || $ps === 'completed' || $ps === 'approved') $hr['payment_status'] = 'Paid';
            elseif ($ps === 'partial' || ($paid > 0 && $paid < $tot)) $hr['payment_status'] = 'Partial';
            else $hr['payment_status'] = 'Unpaid';
        }
        unset($hr);
        if ($hist_filter_status) {
            $hist_records = array_values(array_filter($hist_records, fn($r) => $r['payment_status'] === $hist_filter_status));
        }
    } catch (Exception $e) {}
}

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-shield-halved" style="color:#002F6C;"></i> Audit Trail</h1>
        <div class="sub">Auto-logged compliance record of all manager actions — Transaction ID / JO ID, Manager ID, Action, Timestamp</div>
    </div>
</div>

<!-- Tab Navigation -->
<div style="display:flex;gap:0;border-bottom:2px solid #dee2e6;margin-bottom:20px;">
    <button id="tab-general" onclick="switchAuditTab('general')"
            style="padding:10px 22px;border:none;background:none;font-size:13px;font-weight:700;color:#002F6C;border-bottom:3px solid #002F6C;cursor:pointer;margin-bottom:-2px;">
        <i class="fas fa-shield-halved"></i> General Audit Trail
    </button>
    <button id="tab-fuel-requests" onclick="switchAuditTab('fuel-requests')"
            style="padding:10px 22px;border:none;background:none;font-size:13px;font-weight:600;color:#6c757d;border-bottom:3px solid transparent;cursor:pointer;margin-bottom:-2px;">
        <i class="fas fa-gas-pump" style="color:#c0392b;"></i> Fuel Stock Requests
        <?php
        try {
            $fsr_pending = 0;
            $fsr_stmt = $pdo->prepare("SELECT COUNT(*) FROM fuel_stock_requests WHERE station_id = ? AND status = 'Pending'");
            $fsr_stmt->execute([$station_id]);
            $fsr_pending = (int)$fsr_stmt->fetchColumn();
        } catch (Exception $e) { $fsr_pending = 0; }
        if ($fsr_pending > 0): ?>
            <span style="background:#dc3545;color:#fff;border-radius:10px;padding:1px 7px;font-size:10px;margin-left:4px;"><?php echo $fsr_pending; ?></span>
        <?php endif; ?>
    </button>
    <button id="tab-merch-requests" onclick="switchAuditTab('merch-requests')"
            style="padding:10px 22px;border:none;background:none;font-size:13px;font-weight:600;color:#6c757d;border-bottom:3px solid transparent;cursor:pointer;margin-bottom:-2px;">
        <i class="fas fa-box" style="color:#002F70;"></i> Merchandise Stock Requests
        <?php
        try {
            $msr_pending = 0;
            $msr_stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_requests WHERE station_id = ? AND status = 'Pending'");
            $msr_stmt->execute([$station_id]);
            $msr_pending = (int)$msr_stmt->fetchColumn();
        } catch (Exception $e) { $msr_pending = 0; }
        if ($msr_pending > 0): ?>
            <span style="background:#fd7e14;color:#fff;border-radius:10px;padding:1px 7px;font-size:10px;margin-left:4px;"><?php echo $msr_pending; ?></span>
        <?php endif; ?>
    </button>
    <button id="tab-customer-transparency" onclick="switchAuditTab('customer-transparency')"
            style="padding:10px 22px;border:none;background:none;font-size:13px;font-weight:600;color:#6c757d;border-bottom:3px solid transparent;cursor:pointer;margin-bottom:-2px;">
        <i class="fas fa-eye" style="color:#6f42c1;"></i> Customer Transparency
    </button>
</div>

<!-- FUEL STOCK REQUESTS SECTION -->
<div id="section-fuel-requests" style="display:none;">
    <?php
    // Ensure tables exist
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS fuel_stock_requests (id INT AUTO_INCREMENT PRIMARY KEY, staff_id INT NOT NULL, station_id INT NOT NULL, fuel_type VARCHAR(100) NOT NULL, current_level DECIMAL(12,2) NOT NULL DEFAULT 0, capacity DECIMAL(12,2) NOT NULL DEFAULT 0, stock_status VARCHAR(30) NOT NULL DEFAULT 'LOW', requested_liters DECIMAL(12,2) NOT NULL, remarks TEXT, status ENUM('Pending','Approved','Rejected') DEFAULT 'Pending', approved_liters DECIMAL(12,2) NULL, manager_id INT NULL, manager_notes TEXT NULL, processed_at TIMESTAMP NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $ignored) {}

    // Load fuel stock requests for this station
    $fuel_requests = [];
    try {
        $fsr_all = $pdo->prepare("
            SELECT fsr.*, u.name AS staff_name, m.name AS manager_name
            FROM fuel_stock_requests fsr
            JOIN users u ON fsr.staff_id = u.id
            LEFT JOIN users m ON fsr.manager_id = m.id
            WHERE fsr.station_id = ?
            ORDER BY
                CASE fsr.status WHEN 'Pending' THEN 1 WHEN 'Approved' THEN 2 ELSE 3 END,
                fsr.created_at DESC
        ");
        $fsr_all->execute([$station_id]);
        $fuel_requests = $fsr_all->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $fuel_requests = []; }
    ?>

    <!-- Summary cards -->
    <div class="at-summary-row" style="margin-bottom:18px;">
        <div class="at-card at-card-total">
            <div class="at-card-num"><?php echo count($fuel_requests); ?></div>
            <div class="at-card-lbl">Total Requests</div>
        </div>
        <div class="at-card" style="border-left:4px solid #fd7e14;">
            <div class="at-card-num" style="color:#fd7e14;"><?php echo count(array_filter($fuel_requests, fn($r) => $r['status'] === 'Pending')); ?></div>
            <div class="at-card-lbl">Pending</div>
        </div>
        <div class="at-card at-card-approve">
            <div class="at-card-num"><?php echo count(array_filter($fuel_requests, fn($r) => $r['status'] === 'Approved')); ?></div>
            <div class="at-card-lbl">Approved</div>
        </div>
        <div class="at-card at-card-reject">
            <div class="at-card-num"><?php echo count(array_filter($fuel_requests, fn($r) => $r['status'] === 'Rejected')); ?></div>
            <div class="at-card-lbl">Rejected</div>
        </div>
    </div>

    <!-- Export button -->
    <div style="display:flex;justify-content:flex-end;margin-bottom:12px;">
        <a href="../backend/api/fuel_stock_request.php?action=export_csv"
           style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#1d6f42;color:#fff;border-radius:7px;font-size:13px;font-weight:600;text-decoration:none;">
            <i class="fas fa-file-csv"></i> Export CSV
        </a>
    </div>

    <div class="card" style="padding:0;">
        <div class="at-notice">
            <i class="fas fa-gas-pump" style="color:#c0392b;"></i>
            <strong>Fuel Stock Requests.</strong> Staff-submitted requests for fuel replenishment. Approve or reject from
            <a href="manager_fuel_stock_requests.php" style="color:#002F6C;font-weight:700;">Fuel Stock Requests page</a>.
            This view is read-only for audit purposes.
        </div>
        <div class="at-table-wrap">
            <table class="at-table">
                <thead>
                    <tr>
                        <th>Req #</th>
                        <th>Date Submitted</th>
                        <th>Staff</th>
                        <th>Fuel Type</th>
                        <th>Stock Status</th>
                        <th>Current Level</th>
                        <th>Requested (L)</th>
                        <th>Approved (L)</th>
                        <th>Status</th>
                        <th>Manager</th>
                        <th>Notes</th>
                        <th>Processed At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($fuel_requests as $req): ?>
                    <?php
                        $st = $req['status'] ?? 'Pending';
                        $stColor = $st === 'Approved' ? '#28a745' : ($st === 'Rejected' ? '#dc3545' : '#fd7e14');
                        $stBg    = $st === 'Approved' ? '#d4edda' : ($st === 'Rejected' ? '#f8d7da' : '#fff3cd');
                        $stockSt = $req['stock_status'] ?? 'LOW';
                        $stockColor = in_array($stockSt, ['OUT OF STOCK','CRITICAL']) ? '#dc3545' : '#fd7e14';
                    ?>
                    <tr>
                        <td style="font-family:monospace;font-size:11px;color:#888;">#<?php echo $req['id']; ?></td>
                        <td style="font-size:11px;white-space:nowrap;"><?php echo date('M d, Y H:i', strtotime($req['created_at'])); ?></td>
                        <td><?php echo htmlspecialchars($req['staff_name']); ?></td>
                        <td><strong><?php echo htmlspecialchars($req['fuel_type']); ?></strong></td>
                        <td><span style="color:<?php echo $stockColor; ?>;font-weight:700;font-size:11px;"><?php echo htmlspecialchars($stockSt); ?></span></td>
                        <td style="font-size:12px;"><?php echo number_format($req['current_level'], 2); ?> L</td>
                        <td style="font-weight:700;text-align:center;"><?php echo number_format($req['requested_liters'], 2); ?></td>
                        <td style="text-align:center;">
                            <?php if ($req['approved_liters'] !== null): ?>
                                <strong style="color:#28a745;"><?php echo number_format($req['approved_liters'], 2); ?></strong>
                            <?php else: ?>
                                <span style="color:#adb5bd;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span style="background:<?php echo $stBg; ?>;color:<?php echo $stColor; ?>;padding:2px 9px;border-radius:8px;font-size:11px;font-weight:700;">
                                <?php echo htmlspecialchars($st); ?>
                            </span>
                        </td>
                        <td style="font-size:12px;"><?php echo htmlspecialchars($req['manager_name'] ?? '—'); ?></td>
                        <td style="font-size:11px;color:#555;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                            title="<?php echo htmlspecialchars($req['manager_notes'] ?? ''); ?>">
                            <?php echo $req['manager_notes'] ? htmlspecialchars($req['manager_notes']) : '<span style="color:#adb5bd;">—</span>'; ?>
                        </td>
                        <td style="font-size:11px;white-space:nowrap;color:#555;">
                            <?php echo $req['processed_at'] ? date('M d, Y H:i', strtotime($req['processed_at'])) : '<span style="color:#adb5bd;">—</span>'; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($fuel_requests)): ?>
                    <tr>
                        <td colspan="12" style="text-align:center;padding:48px;color:#888;">
                            <i class="fas fa-gas-pump" style="font-size:36px;display:block;margin-bottom:12px;opacity:0.2;"></i>
                            No fuel stock requests found.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MERCHANDISE STOCK REQUESTS SECTION -->
<div id="section-merch-requests" style="display:none;">
    <?php
    $merch_requests = [];
    try {
        $msr_all = $pdo->prepare("
            SELECT sr.*, u.name AS staff_name, m.name AS manager_name
            FROM stock_requests sr
            JOIN users u ON sr.staff_id = u.id
            LEFT JOIN users m ON sr.manager_id = m.id
            WHERE sr.station_id = ?
            ORDER BY
                CASE sr.status WHEN 'Pending' THEN 1 WHEN 'Approved' THEN 2 ELSE 3 END,
                sr.created_at DESC
        ");
        $msr_all->execute([$station_id]);
        $merch_requests = $msr_all->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $merch_requests = []; }
    ?>

    <!-- Summary cards -->
    <div class="at-summary-row" style="margin-bottom:18px;">
        <div class="at-card at-card-total">
            <div class="at-card-num"><?php echo count($merch_requests); ?></div>
            <div class="at-card-lbl">Total Requests</div>
        </div>
        <div class="at-card" style="border-left:4px solid #fd7e14;">
            <div class="at-card-num" style="color:#fd7e14;"><?php echo count(array_filter($merch_requests, fn($r) => $r['status'] === 'Pending')); ?></div>
            <div class="at-card-lbl">Pending</div>
        </div>
        <div class="at-card at-card-approve">
            <div class="at-card-num"><?php echo count(array_filter($merch_requests, fn($r) => $r['status'] === 'Approved')); ?></div>
            <div class="at-card-lbl">Approved</div>
        </div>
        <div class="at-card at-card-reject">
            <div class="at-card-num"><?php echo count(array_filter($merch_requests, fn($r) => $r['status'] === 'Rejected')); ?></div>
            <div class="at-card-lbl">Rejected</div>
        </div>
    </div>

    <!-- Export button -->
    <div style="display:flex;justify-content:flex-end;margin-bottom:12px;">
        <a href="../backend/api/stock_request.php?action=export_csv"
           style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#1d6f42;color:#fff;border-radius:7px;font-size:13px;font-weight:600;text-decoration:none;">
            <i class="fas fa-file-csv"></i> Export CSV
        </a>
    </div>

    <div class="card" style="padding:0;">
        <div class="at-notice">
            <i class="fas fa-box" style="color:#002F70;"></i>
            <strong>Merchandise Stock Requests.</strong> Staff-submitted requests for merchandise replenishment.
            Approve or reject from <a href="manager_inventory_stock_requests.php" style="color:#002F6C;font-weight:700;">Staff Stock Requests page</a>.
            This view is read-only for audit purposes.
        </div>
        <div class="at-table-wrap">
            <table class="at-table">
                <thead>
                    <tr>
                        <th>Req #</th>
                        <th>Date Submitted</th>
                        <th>Staff</th>
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Category</th>
                        <th>Current Stock</th>
                        <th>Qty Requested</th>
                        <th>Qty Approved</th>
                        <th>Status</th>
                        <th>Manager</th>
                        <th>Notes</th>
                        <th>Processed At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($merch_requests as $req): ?>
                    <?php
                        $st = $req['status'] ?? 'Pending';
                        $stColor = $st === 'Approved' ? '#28a745' : ($st === 'Rejected' ? '#dc3545' : '#fd7e14');
                        $stBg    = $st === 'Approved' ? '#d4edda' : ($st === 'Rejected' ? '#f8d7da' : '#fff3cd');
                    ?>
                    <tr>
                        <td style="font-family:monospace;font-size:11px;color:#888;">#<?php echo $req['id']; ?></td>
                        <td style="font-size:11px;white-space:nowrap;"><?php echo date('M d, Y H:i', strtotime($req['created_at'])); ?></td>
                        <td><?php echo htmlspecialchars($req['staff_name']); ?></td>
                        <td><strong style="font-size:12px;"><?php echo htmlspecialchars($req['item_name']); ?></strong></td>
                        <td style="font-family:monospace;font-size:11px;color:#888;"><?php echo htmlspecialchars($req['item_sku'] ?? '—'); ?></td>
                        <td style="font-size:12px;"><?php echo htmlspecialchars($req['item_category'] ?? '—'); ?></td>
                        <td style="text-align:center;font-size:12px;"><?php echo (int)($req['current_stock'] ?? 0); ?></td>
                        <td style="font-weight:700;text-align:center;"><?php echo (int)$req['requested_quantity']; ?></td>
                        <td style="text-align:center;">
                            <?php if ($req['approved_quantity'] !== null): ?>
                                <strong style="color:#28a745;"><?php echo (int)$req['approved_quantity']; ?></strong>
                            <?php else: ?>
                                <span style="color:#adb5bd;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span style="background:<?php echo $stBg; ?>;color:<?php echo $stColor; ?>;padding:2px 9px;border-radius:8px;font-size:11px;font-weight:700;">
                                <?php echo htmlspecialchars($st); ?>
                            </span>
                        </td>
                        <td style="font-size:12px;"><?php echo htmlspecialchars($req['manager_name'] ?? '—'); ?></td>
                        <td style="font-size:11px;color:#555;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                            title="<?php echo htmlspecialchars($req['manager_notes'] ?? ''); ?>">
                            <?php echo $req['manager_notes'] ? htmlspecialchars($req['manager_notes']) : '<span style="color:#adb5bd;">—</span>'; ?>
                        </td>
                        <td style="font-size:11px;white-space:nowrap;color:#555;">
                            <?php echo $req['processed_at'] ? date('M d, Y H:i', strtotime($req['processed_at'])) : '<span style="color:#adb5bd;">—</span>'; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($merch_requests)): ?>
                    <tr>
                        <td colspan="13" style="text-align:center;padding:48px;color:#888;">
                            <i class="fas fa-box" style="font-size:36px;display:block;margin-bottom:12px;opacity:0.2;"></i>
                            No merchandise stock requests found.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- GENERAL AUDIT SECTION wrapper -->
<div id="section-general">

<?php if (isset($_SESSION['success'])): ?>
<div class="alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
<?php endif; ?>
<?php if (isset($_SESSION['error'])): ?>
<div class="alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
<?php endif; ?>

<!-- Summary Cards -->
<div class="at-summary-row">
    <div class="at-card at-card-total">
        <div class="at-card-num"><?php echo $total_logs; ?></div>
        <div class="at-card-lbl">Total Logs</div>
    </div>
    <div class="at-card at-card-approve">
        <div class="at-card-num"><?php echo ($action_counts['approve'] ?? 0) + ($action_counts['approved'] ?? 0); ?></div>
        <div class="at-card-lbl">Approvals</div>
    </div>
    <div class="at-card at-card-reject">
        <div class="at-card-num"><?php echo ($action_counts['reject'] ?? 0) + ($action_counts['rejected'] ?? 0) + ($action_counts['return'] ?? 0); ?></div>
        <div class="at-card-lbl">Rejections</div>
    </div>
    <div class="at-card at-card-adjust">
        <div class="at-card-num"><?php echo ($action_counts['adjust'] ?? 0) + ($action_counts['adjusted'] ?? 0); ?></div>
        <div class="at-card-lbl">Adjustments</div>
    </div>
</div>

<!-- Filter Card -->
<div class="at-filter-card">
    <div class="at-filter-header">
        <span class="at-filter-title"><i class="fas fa-filter"></i> Filter Audit Logs</span>
        <a href="?<?php echo http_build_query(array_merge($_GET, ['export'=>'csv'])); ?>" class="at-btn at-btn-export">
            <i class="fas fa-file-csv"></i> Export CSV
        </a>
    </div>
    <form method="get" class="at-filter-row">
        <div class="at-flt-group">
            <label class="at-flt-lbl"><i class="fas fa-calendar-alt"></i> Date Range</label>
            <div class="at-date-wrap">
                <input type="date" name="start" value="<?php echo htmlspecialchars($start); ?>" class="at-inp" max="<?php echo date('Y-m-d'); ?>">
                <span class="at-date-sep">to</span>
                <input type="date" name="end" value="<?php echo htmlspecialchars($end); ?>" class="at-inp" max="<?php echo date('Y-m-d'); ?>">
            </div>
        </div>
        <div class="at-flt-group">
            <label class="at-flt-lbl"><i class="fas fa-bolt"></i> Action Type</label>
            <select name="action_f" class="at-inp at-select">
                <option value="">All Actions</option>
                <option value="approve" <?php echo $action_f==='approve'?'selected':''; ?>>✅ Approve</option>
                <option value="reject"  <?php echo $action_f==='reject'?'selected':''; ?>>❌ Reject</option>
                <option value="adjust"  <?php echo $action_f==='adjust'?'selected':''; ?>>🔧 Adjust</option>
                <option value="return"  <?php echo $action_f==='return'?'selected':''; ?>>↩ Return</option>
            </select>
        </div>
        <div class="at-flt-group">
            <label class="at-flt-lbl"><i class="fas fa-hashtag"></i> Transaction / JO ID</label>
            <input type="text" name="txn_search" value="<?php echo htmlspecialchars($txn_search); ?>" class="at-inp" placeholder="Search ID…">
        </div>
        <div class="at-flt-group at-flt-btns">
            <label class="at-flt-lbl">&nbsp;</label>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="at-btn at-btn-search"><i class="fas fa-search"></i> Search</button>
                <a href="manager_audit_trail.php" class="at-btn at-btn-reset"><i class="fas fa-rotate-left"></i> Reset</a>
            </div>
        </div>
    </form>
</div>

<!-- Audit Table -->
<div class="card" style="padding:0;">
    <div class="at-notice">
        <i class="fas fa-lock" style="color:#002F6C;"></i>
        <strong>Read-only.</strong> All entries are auto-logged. No manual input is allowed. This record is used for compliance and defense.
    </div>
    <div class="at-table-wrap">
        <table class="at-table">
            <thead>
                <tr>
                    <th>Audit ID</th>
                    <th>Transaction / JO ID</th>
                    <th>Manager ID</th>
                    <th>Manager Name</th>
                    <th>Action</th>
                    <th>Details / Notes</th>
                    <th>Timestamp</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($audit_logs as $log): ?>
                <?php
                    $act = strtolower($log['action_type'] ?? '');
                    if (str_contains($act, 'approve')) { $ac = '#28a745'; $al = 'Approve'; $ai = 'fa-check-circle'; }
                    elseif (str_contains($act, 'reject') || str_contains($act, 'return')) { $ac = '#dc3545'; $al = 'Reject'; $ai = 'fa-times-circle'; }
                    elseif (str_contains($act, 'adjust')) { $ac = '#6f42c1'; $al = 'Adjust'; $ai = 'fa-sliders'; }
                    else { $ac = '#6c757d'; $al = htmlspecialchars($log['action_type']); $ai = 'fa-circle-dot'; }
                ?>
                <tr>
                    <td style="font-family:monospace;font-size:11px;color:#888;">#<?php echo $log['id']; ?></td>
                    <td style="font-weight:600;font-size:12px;"><?php echo htmlspecialchars($log['transaction_id'] ?? '—'); ?></td>
                    <td style="font-size:11px;color:#888;"><?php echo htmlspecialchars($log['manager_id'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($log['manager_name'] ?? 'System'); ?></td>
                    <td>
                        <span style="background:<?php echo $ac; ?>;color:#fff;padding:2px 9px;border-radius:8px;font-size:11px;font-weight:700;white-space:nowrap;">
                            <i class="fas <?php echo $ai; ?>"></i> <?php echo $al; ?>
                        </span>
                    </td>
                    <td style="font-size:11px;color:#555;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($log['details']); ?>">
                        <?php echo htmlspecialchars($log['details'] ?: '—'); ?>
                    </td>
                    <td style="font-size:11px;white-space:nowrap;color:#555;">
                        <?php echo date('M d, Y H:i:s', strtotime($log['created_at'])); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($audit_logs)): ?>
                <tr>
                    <td colspan="7" style="text-align:center;padding:48px;color:#888;">
                        <i class="fas fa-shield-halved" style="font-size:36px;display:block;margin-bottom:12px;opacity:0.2;"></i>
                        No audit logs found for the selected filters.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.alert-success { background:#d4edda;color:#155724;padding:12px 16px;border-radius:8px;margin-bottom:16px; }
.alert-error   { background:#f8d7da;color:#721c24;padding:12px 16px;border-radius:8px;margin-bottom:16px; }

/* Summary cards */
.at-summary-row { display:flex;gap:14px;flex-wrap:wrap;margin-bottom:18px; }
.at-card { flex:1;min-width:120px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px 18px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.05); }
.at-card-num { font-size:26px;font-weight:800;color:#002F6C; }
.at-card-lbl { font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.5px;margin-top:2px; }
.at-card-total   .at-card-num { color:#002F6C; }
.at-card-approve .at-card-num { color:#155724; }
.at-card-reject  .at-card-num { color:#721c24; }
.at-card-adjust  .at-card-num { color:#5a32a3; }

/* Filter card */
.at-filter-card { background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px 20px;margin-bottom:18px;box-shadow:0 1px 6px rgba(0,0,0,.05); }
.at-filter-header { display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px; }
.at-filter-title { font-size:13px;font-weight:700;color:#002F6C;text-transform:uppercase;letter-spacing:.5px; }
.at-filter-row { display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap; }
.at-flt-group { display:flex;flex-direction:column;gap:5px;min-width:130px; }
.at-flt-lbl { font-size:11px;font-weight:700;color:#6c757d;text-transform:uppercase;letter-spacing:.5px; }
.at-inp { height:36px;padding:0 10px;border:1px solid #ced4da;border-radius:7px;font-size:13px;color:#333;background:#fff;outline:none;width:100%;box-sizing:border-box; }
.at-inp:focus { border-color:#002F6C;box-shadow:0 0 0 3px rgba(0,47,108,.1); }
.at-select { cursor:pointer; }
.at-date-wrap { display:flex;align-items:center;gap:6px; }
.at-date-wrap .at-inp { width:140px; }
.at-date-sep { font-size:12px;color:#999; }
.at-btn { display:inline-flex;align-items:center;gap:6px;padding:0 16px;height:36px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;white-space:nowrap;transition:all .2s; }
.at-btn-search { background:#002F6C;color:#fff; }
.at-btn-reset  { background:#6c757d;color:#fff; }
.at-btn-export { background:#1d6f42;color:#fff; }
.at-btn:hover  { filter:brightness(.88); }

/* Notice bar */
.at-notice { display:flex;align-items:center;gap:10px;padding:10px 18px;background:#f0f4ff;border-bottom:1px solid #e2e8f0;font-size:12px;color:#444; }

/* Table */
.at-table-wrap { overflow-x:auto; }
.at-table { width:100%;border-collapse:collapse;font-size:12px;min-width:700px; }
.at-table thead th { background:#f8f9fa;color:#495057;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:9px 10px;border-bottom:2px solid #dee2e6;white-space:nowrap; }
.at-table tbody td { padding:8px 10px;border-bottom:1px solid #f0f0f0;vertical-align:middle; }
.at-table tbody tr:hover td { background:#f8fbff; }
</style>

</div><!-- end section-general -->

<script>
function switchAuditTab(tab) {
    var tabs = ['general', 'fuel-requests', 'merch-requests'];
    tabs.forEach(function(t) {
        var btn = document.getElementById('tab-' + t);
        var sec = document.getElementById('section-' + t);
        if (t === tab) {
            btn.style.color = '#002F6C';
            btn.style.borderBottomColor = '#002F6C';
            btn.style.fontWeight = '700';
            if (sec) sec.style.display = 'block';
        } else {
            btn.style.color = '#6c757d';
            btn.style.borderBottomColor = 'transparent';
            btn.style.fontWeight = '600';
            if (sec) sec.style.display = 'none';
        }
    });
}

// Check URL hash on load
document.addEventListener('DOMContentLoaded', function() {
    var hash = window.location.hash;
    if (hash === '#fuel-requests') {
        switchAuditTab('fuel-requests');
    } else if (hash === '#merch-requests') {
        switchAuditTab('merch-requests');
    } else if (hash === '#customer-transparency') {
        switchAuditTab('customer-transparency');
    } else {
        switchAuditTab('general');
    }
});
</script>

<!-- ===== SECTION: CUSTOMER TRANSPARENCY (moved from Customer Module) ===== -->
<div id="section-customer-transparency" style="display:none;">
<style>
.at-cust-card{background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid #e9ecef;margin-bottom:20px;}
.at-cust-head{padding:16px 20px;border-bottom:1px solid #e9ecef;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;}
.at-cust-title{font-size:15px;font-weight:700;color:#002F70;margin:0;display:flex;align-items:center;gap:8px;}
.at-cust-body{padding:20px;}
.at-cust-table{width:100%;border-collapse:collapse;font-size:13px;}
.at-cust-table th{background:#f8f9fa;padding:10px 12px;text-align:left;font-weight:700;color:#495057;border-bottom:2px solid #dee2e6;white-space:nowrap;}
.at-cust-table td{padding:10px 12px;border-bottom:1px solid #f0f0f0;vertical-align:middle;}
.at-cust-table tr:hover td{background:#fafbff;}
.at-ch-badge{display:inline-block;padding:3px 9px;border-radius:20px;font-size:10px;font-weight:700;white-space:nowrap;}
.at-ch-badge-jo{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;}
.at-ch-badge-merch{background:#f0fdf4;color:#15803d;border:1px solid #86efac;}
.at-ch-badge-paid{background:#d1fae5;color:#065f46;}
.at-ch-badge-unpaid{background:#fee2e2;color:#991b1b;}
.at-ch-badge-partial{background:#fef3c7;color:#92400e;}
.at-ch-badge-pending{background:#f1f5f9;color:#475569;}
.at-ch-badge-approved{background:#d1fae5;color:#065f46;}
.at-ch-badge-rejected{background:#fee2e2;color:#991b1b;}
.at-info-pill{background:#f0f4ff;border:1px solid #c7d7f9;border-radius:8px;padding:10px 16px;display:flex;flex-direction:column;gap:2px;min-width:130px;}
.at-info-pill .pill-label{font-size:10px;font-weight:700;color:#6c757d;text-transform:uppercase;letter-spacing:.4px;}
.at-info-pill .pill-value{font-size:15px;font-weight:800;color:#002F70;}
.at-info-pill.danger .pill-value{color:#dc3545;}
.at-info-pill.success .pill-value{color:#16a34a;}
.at-search{width:100%;padding:9px 12px;border:1px solid #dee2e6;border-radius:6px;font-size:13px;margin-bottom:14px;box-sizing:border-box;}
.at-search:focus{border-color:#002F70;outline:none;}
.at-empty{text-align:center;padding:40px;color:#9ca3af;}
.at-empty i{font-size:2rem;display:block;margin-bottom:8px;}
</style>

<!-- ── Part 1: Credit Overview Table ── -->
<div class="at-cust-card">
  <div class="at-cust-head">
    <h2 class="at-cust-title"><i class="fas fa-eye" style="color:#6f42c1;"></i> Customer Credit Transparency</h2>
    <span style="font-size:13px;color:#6c757d;"><?php echo count($transparency_data); ?> customers</span>
  </div>
  <div class="at-cust-body">
    <input class="at-search" id="transSearch" placeholder="&#128269; Search customers..." oninput="atFilterRows('transSearch','transTable')">
    <?php if (empty($transparency_data)): ?>
      <div class="at-empty"><i class="fas fa-check-circle" style="color:#28a745;"></i><strong>No customers found.</strong></div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="at-cust-table" id="transTable">
      <thead><tr>
        <th>Customer</th><th>ID Type</th><th>Contact</th>
        <th>Credit Limit</th><th>Remaining Balance</th>
        <th>Credit Txns</th><th>Total Credit Used</th><th>Status</th><th>Action</th>
      </tr></thead>
      <tbody>
      <?php foreach ($transparency_data as $c):
        $bal = (float)($c['balance'] ?? 0);
        $lim = (float)($c['credit_limit'] ?? 0);
        $rem = $lim - $bal;
        $st  = strtolower($c['status'] ?? 'active');
        $rem_color = $rem <= 0 ? '#dc3545' : ($rem < $lim * 0.2 ? '#e67e22' : '#28a745');
      ?>
      <tr data-search="<?php echo strtolower(htmlspecialchars($c['name'])); ?>">
        <td><strong><?php echo htmlspecialchars($c['name']); ?></strong></td>
        <td style="font-size:12px;color:#6c757d;"><?php echo htmlspecialchars($c['id_type'] ?? '—'); ?></td>
        <td style="font-size:12px;"><?php echo htmlspecialchars($c['contact_number']); ?></td>
        <td>&#8369;<?php echo number_format($lim, 2); ?></td>
        <td style="font-weight:700;color:<?php echo $rem_color; ?>;">&#8369;<?php echo number_format($rem, 2); ?></td>
        <td style="text-align:center;"><?php echo (int)$c['utang_count']; ?></td>
        <td style="font-weight:700;color:#dc3545;">&#8369;<?php echo number_format((float)$c['total_utang'], 2); ?></td>
        <td><span class="badge-<?php echo $st; ?>"><?php echo ucfirst($st); ?></span></td>
        <td>
          <a href="manager_audit_trail.php#customer-transparency&cust_id=<?php echo (int)$c['id']; ?>"
             onclick="document.getElementById('histCustSelect').value=<?php echo (int)$c['id']; ?>;loadCustHistory();return false;"
             style="padding:4px 10px;background:#6f42c1;color:#fff;border-radius:6px;font-size:11px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:4px;">
            <i class="fas fa-history"></i> History
          </a>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ── Part 2: Customer Transaction History ── -->
<div class="at-cust-card">
  <div class="at-cust-head">
    <h2 class="at-cust-title"><i class="fas fa-history" style="color:#6f42c1;"></i> Customer Transaction History</h2>
    <?php if ($hist_customer_info): ?>
    <span style="font-size:13px;color:#6c757d;"><?php echo count($hist_records); ?> record<?php echo count($hist_records) !== 1 ? 's' : ''; ?></span>
    <?php endif; ?>
  </div>
  <div class="at-cust-body">

    <!-- Filter form -->
    <form method="GET" action="manager_audit_trail.php" id="histFilterForm">
      <input type="hidden" name="_tab" value="customer-transparency">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:18px;">
        <div style="display:flex;flex-direction:column;gap:4px;flex:1;min-width:200px;">
          <label style="font-size:11px;font-weight:700;color:#6c757d;text-transform:uppercase;letter-spacing:.4px;">Select Customer</label>
          <select id="histCustSelect" name="cust_id" onchange="this.form.submit()" style="padding:8px 12px;border:1px solid #dee2e6;border-radius:6px;font-size:13px;min-width:220px;">
            <option value="">— Choose a customer —</option>
            <?php foreach ($hist_customers as $hc): ?>
            <option value="<?php echo (int)$hc['id']; ?>" <?php echo $hist_selected_id === (int)$hc['id'] ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($hc['name']); ?>
              <?php $hc_rem = (float)$hc['credit_limit'] - (float)$hc['balance']; ?>
              <?php if ($hc_rem < (float)$hc['credit_limit']): ?> · &#8369;<?php echo number_format($hc_rem, 2); ?> remaining<?php endif; ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if ($hist_selected_id): ?>
        <div style="display:flex;flex-direction:column;gap:4px;">
          <label style="font-size:11px;font-weight:700;color:#6c757d;text-transform:uppercase;letter-spacing:.4px;">Type</label>
          <select name="hist_type" style="padding:8px 12px;border:1px solid #dee2e6;border-radius:6px;font-size:13px;min-width:150px;">
            <option value="" <?php echo $hist_filter_type === '' ? 'selected' : ''; ?>>All Types</option>
            <option value="job_order"   <?php echo $hist_filter_type === 'job_order'   ? 'selected' : ''; ?>>Job Order Only</option>
            <option value="merchandise" <?php echo $hist_filter_type === 'merchandise' ? 'selected' : ''; ?>>Merchandise Only</option>
          </select>
        </div>
        <div style="display:flex;flex-direction:column;gap:4px;">
          <label style="font-size:11px;font-weight:700;color:#6c757d;text-transform:uppercase;letter-spacing:.4px;">Payment Status</label>
          <select name="hist_status" style="padding:8px 12px;border:1px solid #dee2e6;border-radius:6px;font-size:13px;min-width:150px;">
            <option value="" <?php echo $hist_filter_status === '' ? 'selected' : ''; ?>>All Statuses</option>
            <option value="Paid"    <?php echo $hist_filter_status === 'Paid'    ? 'selected' : ''; ?>>Paid</option>
            <option value="Unpaid"  <?php echo $hist_filter_status === 'Unpaid'  ? 'selected' : ''; ?>>Unpaid</option>
            <option value="Partial" <?php echo $hist_filter_status === 'Partial' ? 'selected' : ''; ?>>Partial</option>
          </select>
        </div>
        <div style="display:flex;flex-direction:column;gap:4px;">
          <label style="font-size:11px;font-weight:700;color:#6c757d;text-transform:uppercase;letter-spacing:.4px;">Date</label>
          <input type="date" name="hist_date" value="<?php echo htmlspecialchars($hist_filter_date); ?>"
                 style="padding:8px 12px;border:1px solid #dee2e6;border-radius:6px;font-size:13px;">
        </div>
        <div style="display:flex;gap:6px;align-items:flex-end;">
          <button type="submit" style="padding:9px 16px;background:#002F70;color:#fff;border:none;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;">
            <i class="fas fa-filter"></i> Filter
          </button>
          <a href="manager_audit_trail.php?cust_id=<?php echo $hist_selected_id; ?>&_tab=customer-transparency"
             style="padding:9px 14px;background:#f1f5f9;color:#475569;border:1px solid #dee2e6;border-radius:6px;font-size:13px;text-decoration:none;">
            <i class="fas fa-times"></i>
          </a>
        </div>
        <?php endif; ?>
      </div>
    </form>

    <?php if (!$hist_selected_id): ?>
    <div style="text-align:center;padding:48px 20px;color:#9ca3af;">
      <i class="fas fa-user-clock" style="font-size:2.5rem;display:block;margin-bottom:12px;color:#c7d7f9;"></i>
      <p style="font-size:14px;margin:0;">Select a customer above to view their full transaction history.</p>
    </div>
    <?php else: ?>

    <?php if ($hist_customer_info): ?>
    <?php
      $ci_bal  = (float)$hist_customer_info['balance'];
      $ci_lim  = (float)$hist_customer_info['credit_limit'];
      $ci_rem  = $ci_lim - $ci_bal;
      $ci_unpaid = count(array_filter($hist_records, fn($r) => $r['payment_status'] === 'Unpaid'));
    ?>
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:18px;">
      <div class="at-info-pill">
        <span class="pill-label">Customer</span>
        <span class="pill-value" style="font-size:13px;"><?php echo htmlspecialchars($hist_customer_info['name']); ?></span>
      </div>
      <div class="at-info-pill">
        <span class="pill-label">Credit Limit</span>
        <span class="pill-value">&#8369;<?php echo number_format($ci_lim, 2); ?></span>
      </div>
      <div class="at-info-pill <?php echo $ci_bal > 0 ? 'danger' : ''; ?>">
        <span class="pill-label">Amount Used</span>
        <span class="pill-value">&#8369;<?php echo number_format($ci_bal, 2); ?></span>
      </div>
      <div class="at-info-pill <?php echo $ci_rem <= 0 ? 'danger' : 'success'; ?>">
        <span class="pill-label">Remaining Balance</span>
        <span class="pill-value">&#8369;<?php echo number_format($ci_rem, 2); ?></span>
      </div>
      <div class="at-info-pill">
        <span class="pill-label">Total Transactions</span>
        <span class="pill-value"><?php echo count($hist_records); ?></span>
      </div>
      <?php if ($ci_unpaid > 0): ?>
      <div class="at-info-pill danger">
        <span class="pill-label">Unpaid</span>
        <span class="pill-value"><?php echo $ci_unpaid; ?></span>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($hist_records)): ?>
    <div class="at-empty">
      <i class="fas fa-receipt"></i>
      No records found<?php echo ($hist_filter_type || $hist_filter_status || $hist_filter_date) ? ' for the selected filters.' : ' for this customer.'; ?>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
      <table class="at-cust-table">
        <thead><tr>
          <th>#</th><th>Reference</th><th>Type</th><th>Service / Items</th>
          <th>Vehicle</th><th style="text-align:right;">Total</th>
          <th>Payment</th><th>Pay Status</th><th>Txn Status</th><th>Date</th>
        </tr></thead>
        <tbody>
        <?php foreach ($hist_records as $idx => $hr):
          $ps = $hr['payment_status'];
          $ps_cls = match($ps) { 'Paid' => 'at-ch-badge-paid', 'Partial' => 'at-ch-badge-partial', default => 'at-ch-badge-unpaid' };
          $ts = strtolower($hr['txn_status'] ?? '');
          $ts_cls = match(true) {
            str_contains($ts,'approved')||str_contains($ts,'completed')||str_contains($ts,'verified') => 'at-ch-badge-approved',
            str_contains($ts,'rejected')||str_contains($ts,'cancelled') => 'at-ch-badge-rejected',
            default => 'at-ch-badge-pending',
          };
          $is_jo = $hr['record_type'] === 'job_order';
          $svc_text = $is_jo
            ? htmlspecialchars($hr['service_label'] ?: '—')
            : ($hr['merch_items_summary'] ?: htmlspecialchars($hr['service_label'] ?: '—'));
        ?>
        <tr>
          <td style="color:#9ca3af;font-size:11px;"><?php echo count($hist_records) - $idx; ?></td>
          <td><span style="font-family:monospace;font-size:11px;font-weight:700;color:#002F70;"><?php echo htmlspecialchars($hr['ref_number']); ?></span></td>
          <td>
            <span class="at-ch-badge <?php echo $is_jo ? 'at-ch-badge-jo' : 'at-ch-badge-merch'; ?>">
              <i class="fas <?php echo $is_jo ? 'fa-tools' : 'fa-shopping-cart'; ?>" style="margin-right:3px;"></i>
              <?php echo $is_jo ? 'Job Order' : 'Merchandise'; ?>
            </span>
          </td>
          <td style="max-width:220px;font-size:12px;color:#374151;"><?php echo $svc_text; ?></td>
          <td style="font-size:12px;color:#6c757d;"><?php echo $hr['vehicle_plate'] ? htmlspecialchars($hr['vehicle_plate']) : '—'; ?></td>
          <td style="text-align:right;font-weight:700;color:#002F70;white-space:nowrap;">&#8369;<?php echo number_format((float)$hr['total_amount'], 2); ?></td>
          <td style="font-size:12px;color:#6c757d;"><?php echo htmlspecialchars($hr['payment_method'] ?: '—'); ?></td>
          <td>
            <span class="at-ch-badge <?php echo $ps_cls; ?>">
              <?php if ($ps==='Paid'): ?><i class="fas fa-check-circle" style="margin-right:3px;"></i>
              <?php elseif ($ps==='Partial'): ?><i class="fas fa-adjust" style="margin-right:3px;"></i>
              <?php else: ?><i class="fas fa-clock" style="margin-right:3px;"></i><?php endif; ?>
              <?php echo $ps; ?>
            </span>
          </td>
          <td><span class="at-ch-badge <?php echo $ts_cls; ?>"><?php echo htmlspecialchars(ucfirst($hr['txn_status'] ?? 'Pending')); ?></span></td>
          <td style="font-size:11px;color:#6c757d;white-space:nowrap;">
            <?php echo date('M j, Y', strtotime($hr['txn_date'])); ?><br>
            <span style="color:#9ca3af;"><?php echo date('h:i A', strtotime($hr['txn_date'])); ?></span>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="display:flex;justify-content:flex-end;padding:12px 4px 0;gap:24px;font-size:13px;border-top:1px solid #f0f0f0;margin-top:4px;">
      <span style="color:#6c757d;"><?php echo count($hist_records); ?> record<?php echo count($hist_records) !== 1 ? 's' : ''; ?></span>
      <span style="font-weight:700;color:#002F70;">Total: &#8369;<?php echo number_format(array_sum(array_column($hist_records, 'total_amount')), 2); ?></span>
    </div>
    <?php endif; ?>
    <?php endif; // end $hist_selected_id ?>
  </div>
</div>

</div><!-- /section-customer-transparency -->

<script>
function atFilterRows(inputId, tableId) {
    var q = document.getElementById(inputId).value.toLowerCase();
    document.querySelectorAll('#' + tableId + ' tbody tr[data-search]').forEach(function(row) {
        row.style.display = row.getAttribute('data-search').includes(q) ? '' : 'none';
    });
}
function loadCustHistory() {
    // Switch to transparency tab and submit the history form
    switchAuditTab('customer-transparency');
    setTimeout(function() { document.getElementById('histFilterForm').submit(); }, 100);
}
// Auto-open transparency tab if _tab param is set
(function() {
    var params = new URLSearchParams(window.location.search);
    if (params.get('_tab') === 'customer-transparency' || params.get('cust_id')) {
        document.addEventListener('DOMContentLoaded', function() {
            switchAuditTab('customer-transparency');
        });
    }
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
