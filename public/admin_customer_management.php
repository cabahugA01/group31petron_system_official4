<?php
/**
 * Admin Customer Management
 * Four-section module: Master List | Balances Oversight | Accounts Receivable | Customer History
 * Admin/SuperAdmin only — station-scoped oversight
 */

// ── Bootstrap ─────────────────────────────────────────────
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();

// Role gate
if (!in_array($role, ['admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Admin privileges required.';
    header('Location: dashboard.php');
    exit;
}

// ── Section routing ────────────────────────────────────────
$valid_sections = ['master', 'balances', 'receivable', 'history'];
$section = isset($_GET['section']) && in_array($_GET['section'], $valid_sections)
    ? $_GET['section'] : 'master';

// Page ID for sidebar sub-item highlighting
$page_id = match($section) {
    'balances'   => 'adm_cust_balances',
    'receivable' => 'adm_cust_ar',
    'history'    => 'adm_cust_history',
    default      => 'adm_cust_master',
};

// ── Ensure required customer columns exist ─────────────────
try {
    $cols = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
    $required_cols = [
        'contact_number' => "VARCHAR(50) NULL",
        'id_number'      => "VARCHAR(100) NULL",
        'credit_limit'   => "DECIMAL(12,2) DEFAULT 0.00",
        'current_balance'=> "DECIMAL(12,2) DEFAULT 0.00",
    ];
    foreach ($required_cols as $col => $def) {
        if (!in_array($col, $cols)) {
            $pdo->exec("ALTER TABLE customers ADD COLUMN $col $def");
        }
    }
} catch (Exception $e) { /* silent */ }

// ── POST: credit-limit adjustment ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'adjust_credit_limit') {
        $cid   = (int)($_POST['customer_id'] ?? 0);
        $limit = (float)($_POST['credit_limit'] ?? 0);
        $note  = trim($_POST['note'] ?? '');
        try {
            $stmt = $pdo->prepare("UPDATE customers SET credit_limit=? WHERE id=? AND station_id=?");
            $stmt->execute([$limit, $cid, $station_id]);
            log_activity('Admin Credit Limit Adjusted', "Customer #$cid → ₱" . number_format($limit, 2) . ($note ? " | $note" : ''));
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['action'] === 'toggle_status') {
        $cid    = (int)($_POST['customer_id'] ?? 0);
        $status = $_POST['status'] === 'active' ? 'active' : 'inactive';
        try {
            $stmt = $pdo->prepare("UPDATE customers SET status=? WHERE id=? AND station_id=?");
            $stmt->execute([$status, $cid, $station_id]);
            log_activity('Admin Customer Status Changed', "Customer #$cid → $status");
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

// ── Helpers ────────────────────────────────────────────────
function adm_cust_val(PDO $p, string $sql, array $args = [], $default = 0) {
    try { $s = $p->prepare($sql); $s->execute($args); return $s->fetchColumn() ?? $default; }
    catch (Exception $e) { return $default; }
}
function adm_cust_rows(PDO $p, string $sql, array $args = []): array {
    try { $s = $p->prepare($sql); $s->execute($args); return $s->fetchAll(PDO::FETCH_ASSOC) ?: []; }
    catch (Exception $e) { return []; }
}

// ── DATA: Master List ──────────────────────────────────────
$search  = trim($_GET['q'] ?? '');
$status_filter = $_GET['status'] ?? 'all';
$customers = [];
if ($section === 'master') {
    $where = "WHERE station_id = :sid";
    $params = [':sid' => $station_id];
    if ($search !== '') {
        $where .= " AND (name LIKE :q OR contact_number LIKE :q OR id_number LIKE :q OR email LIKE :q)";
        $params[':q'] = "%$search%";
    }
    if ($status_filter !== 'all') {
        $where .= " AND status = :status";
        $params[':status'] = $status_filter;
    }
    $customers = adm_cust_rows($pdo,
        "SELECT id, name, contact_number, id_number, email,
                COALESCE(current_balance, balance, 0) AS outstanding_balance,
                COALESCE(credit_limit, 0) AS credit_limit,
                status, created_at
         FROM customers $where
         ORDER BY name ASC", $params);
}

// ── DATA: Balances Oversight ───────────────────────────────
$balance_customers = [];
$overdue_count = 0;
if ($section === 'balances') {
    $balance_customers = adm_cust_rows($pdo,
        "SELECT id, name, contact_number,
                COALESCE(current_balance, balance, 0) AS outstanding_balance,
                COALESCE(credit_limit, 0) AS credit_limit,
                status,
                CASE
                    WHEN COALESCE(credit_limit,0) > 0
                     AND COALESCE(current_balance,balance,0) >= COALESCE(credit_limit,0)
                    THEN 'overdue'
                    WHEN COALESCE(current_balance,balance,0) > 0 THEN 'has_balance'
                    ELSE 'clear'
                END AS balance_flag
         FROM customers
         WHERE station_id = ?
         ORDER BY outstanding_balance DESC",
        [$station_id]);
    $overdue_count = count(array_filter($balance_customers, fn($c) => $c['balance_flag'] === 'overdue'));
}

// ── DATA: Accounts Receivable ──────────────────────────────
$ar_rows     = [];
$total_ar    = 0;
$collected   = 0;
if ($section === 'receivable') {
    // AR = customers with positive outstanding balance
    $ar_rows = adm_cust_rows($pdo,
        "SELECT c.id, c.name, c.contact_number,
                COALESCE(c.current_balance, c.balance, 0) AS outstanding_balance,
                COALESCE(c.credit_limit, 0) AS credit_limit,
                (SELECT COALESCE(SUM(amount),0)
                   FROM credit_payments cp
                  WHERE cp.customer_id = c.id) AS total_paid,
                c.status
         FROM customers c
         WHERE c.station_id = ?
           AND COALESCE(c.current_balance, c.balance, 0) > 0
         ORDER BY outstanding_balance DESC",
        [$station_id]);
    $total_ar  = array_sum(array_column($ar_rows, 'outstanding_balance'));
    $collected = adm_cust_val($pdo,
        "SELECT COALESCE(SUM(cp.amount),0)
         FROM credit_payments cp
         JOIN customers c ON c.id = cp.customer_id
         WHERE c.station_id = ?",
        [$station_id]);
}

// ── DATA: Customer History ─────────────────────────────────
$hist_customer_id = (int)($_GET['cid'] ?? 0);
$hist_customers   = [];
$hist_rows        = [];
if ($section === 'history') {
    $hist_customers = adm_cust_rows($pdo,
        "SELECT id, name FROM customers WHERE station_id=? ORDER BY name",
        [$station_id]);

    if ($hist_customer_id > 0) {
        // Merchandise transactions
        $hist_rows = adm_cust_rows($pdo,
            "SELECT 'Merchandise' AS type, id, COALESCE(transaction_date, created_at) AS txn_date,
                    total_amount, payment_method, validation_status AS status, NULL AS notes
             FROM merchandise_transactions
             WHERE station_id=? AND customer_id=?
             UNION ALL
             SELECT 'Job Order' AS type, id, COALESCE(created_at, updated_at) AS txn_date,
                    COALESCE(total_amount,0), payment_method, status, remarks AS notes
             FROM job_orders
             WHERE station_id=? AND customer_id=?
             UNION ALL
             SELECT 'Payment' AS type, id, created_at AS txn_date,
                    amount, 'Credit Payment' AS payment_method, 'Paid' AS status, notes
             FROM credit_payments
             WHERE customer_id=?
             ORDER BY txn_date DESC
             LIMIT 200",
            [$station_id, $hist_customer_id, $station_id, $hist_customer_id, $hist_customer_id]);
    }
}

// ── Flash messages ─────────────────────────────────────────
$flash_ok  = $_SESSION['flash_ok']  ?? '';
$flash_err = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_err']);

include __DIR__ . '/../partials/header.php';
?>
<style>
:root {
    --adm-blue:    #002F6C;
    --adm-red:     #CC0000;
    --adm-success: #28a745;
    --adm-warning: #ffc107;
    --adm-danger:  #dc3545;
    --adm-info:    #17a2b8;
    --adm-gray:    #f8f9fa;
    --adm-border:  #dee2e6;
}

/* ── Cards / KPI ─────────────────────────────────────────── */
.acm-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.acm-kpi {
    background: #fff;
    border-radius: 10px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
    border-left: 4px solid var(--adm-blue);
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.acm-kpi.danger   { border-left-color: var(--adm-danger); }
.acm-kpi.warning  { border-left-color: var(--adm-warning); }
.acm-kpi.success  { border-left-color: var(--adm-success); }
.acm-kpi.info     { border-left-color: var(--adm-info); }
.acm-kpi-value    { font-size: 26px; font-weight: 800; color: var(--adm-blue); }
.acm-kpi.danger  .acm-kpi-value  { color: var(--adm-danger); }
.acm-kpi.warning .acm-kpi-value  { color: #b8860b; }
.acm-kpi.success .acm-kpi-value  { color: var(--adm-success); }
.acm-kpi.info    .acm-kpi-value  { color: var(--adm-info); }
.acm-kpi-label    { font-size: 11px; font-weight: 600; color: #888; text-transform: uppercase; letter-spacing: .5px; }

/* ── Table ───────────────────────────────────────────────── */
.acm-card {
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
    overflow: hidden;
    margin-bottom: 24px;
}
.acm-card-head {
    padding: 16px 20px;
    border-bottom: 1px solid var(--adm-border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
.acm-card-head h2 {
    margin: 0;
    font-size: 15px;
    font-weight: 700;
    color: var(--adm-blue);
    text-transform: uppercase;
    letter-spacing: .4px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.acm-table-wrap { overflow-x: auto; }
.acm-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.acm-table th {
    background: var(--adm-blue);
    color: #fff;
    padding: 10px 14px;
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .4px;
    white-space: nowrap;
}
.acm-table td {
    padding: 10px 14px;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: middle;
}
.acm-table tr:hover td { background: #f8faff; }
.acm-table tr:last-child td { border-bottom: none; }

/* ── Status badges ───────────────────────────────────────── */
.badge-status {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .3px;
}
.badge-active   { background:#d4edda; color:#155724; }
.badge-inactive { background:#f8d7da; color:#721c24; }
.badge-overdue  { background:#f8d7da; color:#721c24; }
.badge-balance  { background:#fff3cd; color:#856404; }
.badge-clear    { background:#d4edda; color:#155724; }

/* ── Search / filter bar ─────────────────────────────────── */
.acm-toolbar {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
}
.acm-toolbar input[type=text],
.acm-toolbar select {
    padding: 7px 12px;
    border: 1px solid var(--adm-border);
    border-radius: 6px;
    font-size: 13px;
    color: #333;
    background: #fff;
    min-width: 180px;
}
.acm-toolbar input[type=text]:focus,
.acm-toolbar select:focus {
    outline: none;
    border-color: var(--adm-blue);
    box-shadow: 0 0 0 3px rgba(0,47,108,.08);
}
.btn-acm {
    padding: 7px 14px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all .2s;
}
.btn-acm-primary { background: var(--adm-blue); color: #fff; }
.btn-acm-primary:hover { background: #001f4d; }
.btn-acm-danger  { background: var(--adm-danger); color: #fff; }
.btn-acm-success { background: var(--adm-success); color: #fff; }
.btn-acm-sm { padding: 4px 10px; font-size: 12px; }
.btn-acm-outline {
    background: #fff;
    border: 1px solid var(--adm-blue);
    color: var(--adm-blue);
}
.btn-acm-outline:hover { background: var(--adm-blue); color: #fff; }

/* ── Modals ──────────────────────────────────────────────── */
.acm-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.5);
    z-index: 9000;
    align-items: center;
    justify-content: center;
}
.acm-modal-overlay.open { display: flex; }
.acm-modal {
    background: #fff;
    border-radius: 12px;
    padding: 28px;
    width: 420px;
    max-width: 96vw;
    box-shadow: 0 8px 40px rgba(0,0,0,.18);
}
.acm-modal h3 { margin: 0 0 18px; color: var(--adm-blue); font-size: 16px; }
.acm-modal label { font-size: 12px; font-weight: 600; color: #555; display: block; margin-bottom: 4px; }
.acm-modal input, .acm-modal textarea, .acm-modal select {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--adm-border);
    border-radius: 6px;
    font-size: 13px;
    margin-bottom: 14px;
    box-sizing: border-box;
}
.acm-modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 6px; }

/* ── Flash messages ──────────────────────────────────────── */
.acm-flash { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
.acm-flash-ok  { background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; border-left:4px solid #28a745; }
.acm-flash-err { background:#fee2e2; color:#7f1d1d; border:1px solid #fca5a5; border-left:4px solid #dc3545; }

/* ── Progress bar for credit utilisation ─────────────────── */
.credit-bar { height: 6px; border-radius: 3px; background: #e9ecef; margin-top: 4px; }
.credit-bar-fill { height: 100%; border-radius: 3px; background: var(--adm-success); transition: width .3s; }
.credit-bar-fill.warn  { background: var(--adm-warning); }
.credit-bar-fill.over  { background: var(--adm-danger); }

/* ── Responsive ──────────────────────────────────────────── */
@media (max-width: 768px) {
    .acm-tabs { padding: 0 8px; }
    .acm-tab  { padding: 10px 12px; font-size: 12px; }
    .acm-kpi-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>

<div style="max-width:1400px;margin:0 auto;padding:0 0 40px;">

    <!-- Page header -->
    <div class="page-head" style="margin-bottom:20px;">
        <h1 style="font-size:22px;font-weight:800;color:var(--adm-blue);margin:0 0 4px;">
            <i class="fas fa-users" style="margin-right:10px;"></i>Customer Management
        </h1>
        <p class="page-subtitle" style="margin:0;font-size:13px;color:#666;">
            Admin oversight — customer profiles, balances, receivables &amp; history
        </p>
    </div>

    <!-- Flash messages -->
    <?php if ($flash_ok): ?>
        <div class="acm-flash acm-flash-ok"><i class="fas fa-check-circle"></i><?php echo htmlspecialchars($flash_ok); ?></div>
    <?php endif; ?>
    <?php if ($flash_err): ?>
        <div class="acm-flash acm-flash-err"><i class="fas fa-exclamation-circle"></i><?php echo htmlspecialchars($flash_err); ?></div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════════════
         SECTION 1: CUSTOMER MASTER LIST
    ══════════════════════════════════════════════════════════ -->
    <?php if ($section === 'master'): ?>

    <?php
    $total_customers  = count($customers);
    $active_count     = count(array_filter($customers, fn($c) => strtolower($c['status']) === 'active'));
    $inactive_count   = $total_customers - $active_count;
    $with_balance     = count(array_filter($customers, fn($c) => (float)$c['outstanding_balance'] > 0));
    ?>

    <!-- KPI row -->
    <div class="acm-kpi-grid">
        <div class="acm-kpi">
            <div class="acm-kpi-value"><?php echo number_format($total_customers); ?></div>
            <div class="acm-kpi-label"><i class="fas fa-users"></i> Total Customers</div>
        </div>
        <div class="acm-kpi success">
            <div class="acm-kpi-value"><?php echo number_format($active_count); ?></div>
            <div class="acm-kpi-label"><i class="fas fa-user-check"></i> Active</div>
        </div>
        <div class="acm-kpi danger">
            <div class="acm-kpi-value"><?php echo number_format($inactive_count); ?></div>
            <div class="acm-kpi-label"><i class="fas fa-user-slash"></i> Inactive</div>
        </div>
        <div class="acm-kpi warning">
            <div class="acm-kpi-value"><?php echo number_format($with_balance); ?></div>
            <div class="acm-kpi-label"><i class="fas fa-exclamation-triangle"></i> With Balances</div>
        </div>
    </div>

    <!-- Search & filter -->
    <div class="acm-card">
        <div class="acm-card-head">
            <h2><i class="fas fa-list-ul"></i> Customer Master List</h2>
            <form method="get" action="" style="margin:0;">
                <input type="hidden" name="section" value="master">
                <div class="acm-toolbar">
                    <input type="text" name="q" placeholder="Search name / contact / ID…"
                           value="<?php echo htmlspecialchars($search); ?>">
                    <select name="status">
                        <option value="all"      <?php echo $status_filter === 'all'      ? 'selected' : ''; ?>>All Statuses</option>
                        <option value="active"   <?php echo $status_filter === 'active'   ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                    <button type="submit" class="btn-acm btn-acm-primary">
                        <i class="fas fa-search"></i> Filter
                    </button>
                    <?php if ($search || $status_filter !== 'all'): ?>
                        <a href="?section=master" class="btn-acm btn-acm-outline">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="acm-table-wrap">
            <?php if (empty($customers)): ?>
                <div style="padding:40px;text-align:center;color:#999;">
                    <i class="fas fa-users" style="font-size:40px;margin-bottom:12px;display:block;opacity:.3;"></i>
                    No customers found.
                </div>
            <?php else: ?>
            <table class="acm-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Customer Name</th>
                        <th>Contact</th>
                        <th>ID Number</th>
                        <th>Outstanding Balance</th>
                        <th>Credit Limit</th>
                        <th>Status</th>
                        <th>Registered</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($customers as $i => $c):
                    $bal   = (float)$c['outstanding_balance'];
                    $limit = (float)$c['credit_limit'];
                    $util  = ($limit > 0) ? min(100, round($bal / $limit * 100)) : 0;
                    $bar_class = $util >= 100 ? 'over' : ($util >= 80 ? 'warn' : '');
                ?>
                <tr>
                    <td style="color:#999;"><?php echo $i + 1; ?></td>
                    <td style="font-weight:600;color:#333;"><?php echo htmlspecialchars($c['name']); ?></td>
                    <td><?php echo htmlspecialchars($c['contact_number'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($c['id_number'] ?? '—'); ?></td>
                    <td style="font-weight:700;color:<?php echo $bal > 0 ? '#dc3545' : '#28a745'; ?>;">
                        ₱<?php echo number_format($bal, 2); ?>
                    </td>
                    <td>
                        <div>₱<?php echo number_format($limit, 2); ?></div>
                        <?php if ($limit > 0): ?>
                            <div class="credit-bar" title="<?php echo $util; ?>% utilised">
                                <div class="credit-bar-fill <?php echo $bar_class; ?>"
                                     style="width:<?php echo $util; ?>%;"></div>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge-status <?php echo strtolower($c['status']) === 'active' ? 'badge-active' : 'badge-inactive'; ?>">
                            <?php echo ucfirst(htmlspecialchars($c['status'] ?? 'unknown')); ?>
                        </span>
                    </td>
                    <td style="color:#666;font-size:12px;"><?php echo date('M d, Y', strtotime($c['created_at'])); ?></td>
                    <td>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;">
                            <button class="btn-acm btn-acm-outline btn-acm-sm"
                                    onclick="openCreditModal(<?php echo $c['id']; ?>, '<?php echo htmlspecialchars(addslashes($c['name'])); ?>', <?php echo $limit; ?>)"
                                    title="Adjust Credit Limit">
                                <i class="fas fa-sliders-h"></i>
                            </button>
                            <button class="btn-acm btn-acm-sm <?php echo strtolower($c['status']) === 'active' ? 'btn-acm-danger' : 'btn-acm-success'; ?>"
                                    onclick="toggleStatus(<?php echo $c['id']; ?>, '<?php echo strtolower($c['status']) === 'active' ? 'inactive' : 'active'; ?>')"
                                    title="<?php echo strtolower($c['status']) === 'active' ? 'Deactivate' : 'Reactivate'; ?>">
                                <i class="fas <?php echo strtolower($c['status']) === 'active' ? 'fa-user-slash' : 'fa-user-check'; ?>"></i>
                            </button>
                            <a href="?section=history&cid=<?php echo $c['id']; ?>"
                               class="btn-acm btn-acm-sm btn-acm-primary" title="View History">
                                <i class="fas fa-history"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; /* end master */ ?>


    <!-- ══════════════════════════════════════════════════════════
         SECTION 2: BALANCES OVERSIGHT
    ══════════════════════════════════════════════════════════ -->
    <?php if ($section === 'balances'): ?>

    <?php
    $total_bal    = array_sum(array_column($balance_customers, 'outstanding_balance'));
    $total_limit  = array_sum(array_column($balance_customers, 'credit_limit'));
    $clear_count  = count(array_filter($balance_customers, fn($c) => $c['balance_flag'] === 'clear'));
    ?>

    <div class="acm-kpi-grid">
        <div class="acm-kpi">
            <div class="acm-kpi-value">₱<?php echo number_format($total_bal, 2); ?></div>
            <div class="acm-kpi-label"><i class="fas fa-balance-scale"></i> Total Outstanding</div>
        </div>
        <div class="acm-kpi danger">
            <div class="acm-kpi-value"><?php echo number_format($overdue_count); ?></div>
            <div class="acm-kpi-label"><i class="fas fa-exclamation-circle"></i> Overdue / At Limit</div>
        </div>
        <div class="acm-kpi warning">
            <div class="acm-kpi-value"><?php echo number_format(count($balance_customers) - $overdue_count - $clear_count); ?></div>
            <div class="acm-kpi-label"><i class="fas fa-clock"></i> Has Balance</div>
        </div>
        <div class="acm-kpi success">
            <div class="acm-kpi-value"><?php echo number_format($clear_count); ?></div>
            <div class="acm-kpi-label"><i class="fas fa-check-circle"></i> Clear / No Balance</div>
        </div>
    </div>

    <div class="acm-card">
        <div class="acm-card-head">
            <h2><i class="fas fa-wallet"></i> Customer Balances Oversight</h2>
            <span style="font-size:12px;color:#666;">Sorted by highest outstanding balance</span>
        </div>
        <div class="acm-table-wrap">
            <?php if (empty($balance_customers)): ?>
                <div style="padding:40px;text-align:center;color:#999;">No customer balance data found.</div>
            <?php else: ?>
            <table class="acm-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Customer</th>
                        <th>Contact</th>
                        <th>Outstanding Balance</th>
                        <th>Credit Limit</th>
                        <th>Utilisation</th>
                        <th>Flag</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($balance_customers as $i => $c):
                    $bal   = (float)$c['outstanding_balance'];
                    $limit = (float)$c['credit_limit'];
                    $util  = ($limit > 0) ? min(100, round($bal / $limit * 100)) : 0;
                    $bar_class = $util >= 100 ? 'over' : ($util >= 80 ? 'warn' : '');
                    $flag_class = match($c['balance_flag']) {
                        'overdue'     => 'badge-overdue',
                        'has_balance' => 'badge-balance',
                        default       => 'badge-clear',
                    };
                    $flag_label = match($c['balance_flag']) {
                        'overdue'     => 'Overdue',
                        'has_balance' => 'Has Balance',
                        default       => 'Clear',
                    };
                ?>
                <tr>
                    <td style="color:#999;"><?php echo $i + 1; ?></td>
                    <td style="font-weight:600;"><?php echo htmlspecialchars($c['name']); ?></td>
                    <td style="color:#666;"><?php echo htmlspecialchars($c['contact_number'] ?? '—'); ?></td>
                    <td style="font-weight:700;color:<?php echo $bal > 0 ? '#dc3545' : '#28a745'; ?>;">
                        ₱<?php echo number_format($bal, 2); ?>
                    </td>
                    <td>₱<?php echo number_format($limit, 2); ?></td>
                    <td style="min-width:120px;">
                        <?php if ($limit > 0): ?>
                            <div style="font-size:11px;color:#666;margin-bottom:2px;"><?php echo $util; ?>%</div>
                            <div class="credit-bar">
                                <div class="credit-bar-fill <?php echo $bar_class; ?>" style="width:<?php echo $util; ?>%;"></div>
                            </div>
                        <?php else: ?>
                            <span style="color:#bbb;font-size:12px;">No limit set</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge-status <?php echo $flag_class; ?>"><?php echo $flag_label; ?></span></td>
                    <td><span class="badge-status <?php echo strtolower($c['status']) === 'active' ? 'badge-active' : 'badge-inactive'; ?>"><?php echo ucfirst($c['status']); ?></span></td>
                    <td>
                        <button class="btn-acm btn-acm-outline btn-acm-sm"
                                onclick="openCreditModal(<?php echo $c['id']; ?>, '<?php echo htmlspecialchars(addslashes($c['name'])); ?>', <?php echo $limit; ?>)">
                            <i class="fas fa-sliders-h"></i> Adjust Limit
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; /* end balances */ ?>


    <!-- ══════════════════════════════════════════════════════════
         SECTION 3: ACCOUNTS RECEIVABLE
    ══════════════════════════════════════════════════════════ -->
    <?php if ($section === 'receivable'): ?>

    <div class="acm-kpi-grid">
        <div class="acm-kpi danger">
            <div class="acm-kpi-value">₱<?php echo number_format($total_ar, 2); ?></div>
            <div class="acm-kpi-label"><i class="fas fa-file-invoice-dollar"></i> Total Receivables</div>
        </div>
        <div class="acm-kpi success">
            <div class="acm-kpi-value">₱<?php echo number_format($collected, 2); ?></div>
            <div class="acm-kpi-label"><i class="fas fa-hand-holding-usd"></i> Total Collected</div>
        </div>
        <div class="acm-kpi info">
            <div class="acm-kpi-value"><?php echo count($ar_rows); ?></div>
            <div class="acm-kpi-label"><i class="fas fa-users"></i> Customers with Balance</div>
        </div>
        <div class="acm-kpi warning">
            <div class="acm-kpi-value">
                <?php
                $net = $total_ar - $collected;
                echo '₱' . number_format(max(0, $net), 2);
                ?>
            </div>
            <div class="acm-kpi-label"><i class="fas fa-exclamation-triangle"></i> Net Uncollected</div>
        </div>
    </div>

    <div class="acm-card">
        <div class="acm-card-head">
            <h2><i class="fas fa-file-invoice-dollar"></i> Accounts Receivable</h2>
            <span style="font-size:12px;color:#666;">Customers with outstanding credit balances</span>
        </div>
        <div class="acm-table-wrap">
            <?php if (empty($ar_rows)): ?>
                <div style="padding:40px;text-align:center;color:#28a745;">
                    <i class="fas fa-check-circle" style="font-size:40px;margin-bottom:12px;display:block;"></i>
                    No outstanding accounts receivable. All balances are cleared.
                </div>
            <?php else: ?>
            <table class="acm-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Customer</th>
                        <th>Contact</th>
                        <th>Outstanding Balance</th>
                        <th>Credit Limit</th>
                        <th>Total Paid</th>
                        <th>% Collected</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($ar_rows as $i => $c):
                    $bal   = (float)$c['outstanding_balance'];
                    $paid  = (float)($c['total_paid'] ?? 0);
                    $limit = (float)$c['credit_limit'];
                    $total_exposure = $bal + $paid;
                    $pct   = ($total_exposure > 0) ? min(100, round($paid / $total_exposure * 100)) : 0;
                ?>
                <tr>
                    <td style="color:#999;"><?php echo $i + 1; ?></td>
                    <td style="font-weight:600;"><?php echo htmlspecialchars($c['name']); ?></td>
                    <td style="color:#666;"><?php echo htmlspecialchars($c['contact_number'] ?? '—'); ?></td>
                    <td style="font-weight:700;color:#dc3545;">₱<?php echo number_format($bal, 2); ?></td>
                    <td>₱<?php echo number_format($limit, 2); ?></td>
                    <td style="color:#28a745;font-weight:600;">₱<?php echo number_format($paid, 2); ?></td>
                    <td style="min-width:120px;">
                        <div style="font-size:11px;color:#666;margin-bottom:2px;"><?php echo $pct; ?>% collected</div>
                        <div class="credit-bar">
                            <div class="credit-bar-fill" style="width:<?php echo $pct; ?>%;background:#28a745;"></div>
                        </div>
                    </td>
                    <td><span class="badge-status <?php echo strtolower($c['status']) === 'active' ? 'badge-active' : 'badge-inactive'; ?>"><?php echo ucfirst($c['status']); ?></span></td>
                    <td>
                        <a href="?section=history&cid=<?php echo $c['id']; ?>"
                           class="btn-acm btn-acm-primary btn-acm-sm">
                            <i class="fas fa-history"></i> History
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#f8f9fa;font-weight:700;">
                        <td colspan="3" style="text-align:right;padding:10px 14px;font-size:13px;">TOTALS</td>
                        <td style="color:#dc3545;">₱<?php echo number_format($total_ar, 2); ?></td>
                        <td>—</td>
                        <td style="color:#28a745;">₱<?php echo number_format($collected, 2); ?></td>
                        <td colspan="3">—</td>
                    </tr>
                </tfoot>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; /* end receivable */ ?>


    <!-- ══════════════════════════════════════════════════════════
         SECTION 4: CUSTOMER HISTORY
    ══════════════════════════════════════════════════════════ -->
    <?php if ($section === 'history'): ?>

    <div class="acm-card">
        <div class="acm-card-head">
            <h2><i class="fas fa-history"></i> Customer History</h2>
            <form method="get" action="" style="margin:0;">
                <input type="hidden" name="section" value="history">
                <div class="acm-toolbar">
                    <select name="cid" onchange="this.form.submit()" style="min-width:220px;">
                        <option value="0">— Select a customer —</option>
                        <?php foreach ($hist_customers as $hc): ?>
                            <option value="<?php echo $hc['id']; ?>"
                                    <?php echo $hc['id'] == $hist_customer_id ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($hc['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($hist_customer_id > 0): ?>
                        <a href="?section=history" class="btn-acm btn-acm-outline btn-acm-sm">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <?php if ($hist_customer_id <= 0): ?>
            <div style="padding:40px;text-align:center;color:#999;">
                <i class="fas fa-user-clock" style="font-size:40px;margin-bottom:12px;display:block;opacity:.3;"></i>
                Select a customer above to view their full transaction history.
            </div>
        <?php elseif (empty($hist_rows)): ?>
            <div style="padding:40px;text-align:center;color:#999;">
                <i class="fas fa-inbox" style="font-size:40px;margin-bottom:12px;display:block;opacity:.3;"></i>
                No transaction records found for this customer.
            </div>
        <?php else: ?>
        <div class="acm-table-wrap">
            <table class="acm-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Transaction ID</th>
                        <th>Amount</th>
                        <th>Payment Method</th>
                        <th>Status</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($hist_rows as $i => $r):
                    $type_colors = [
                        'Merchandise' => '#17a2b8',
                        'Job Order'   => '#764ba2',
                        'Payment'     => '#28a745',
                    ];
                    $tc = $type_colors[$r['type']] ?? '#666';
                ?>
                <tr>
                    <td style="color:#999;"><?php echo $i + 1; ?></td>
                    <td style="font-size:12px;color:#666;white-space:nowrap;">
                        <?php echo $r['txn_date'] ? date('M d, Y H:i', strtotime($r['txn_date'])) : '—'; ?>
                    </td>
                    <td>
                        <span class="badge-status"
                              style="background:<?php echo $tc; ?>1a;color:<?php echo $tc; ?>;border:1px solid <?php echo $tc; ?>44;">
                            <?php echo htmlspecialchars($r['type']); ?>
                        </span>
                    </td>
                    <td style="font-family:monospace;font-size:12px;color:#555;">#<?php echo $r['id']; ?></td>
                    <td style="font-weight:700;color:#333;">₱<?php echo number_format((float)$r['total_amount'], 2); ?></td>
                    <td style="color:#555;"><?php echo htmlspecialchars($r['payment_method'] ?? '—'); ?></td>
                    <td>
                        <?php
                        $st = strtolower($r['status'] ?? '');
                        $st_class = match(true) {
                            in_array($st, ['completed','validated','paid','approved']) => 'badge-active',
                            in_array($st, ['cancelled','rejected','inactive'])         => 'badge-inactive',
                            default                                                    => 'badge-balance',
                        };
                        ?>
                        <span class="badge-status <?php echo $st_class; ?>">
                            <?php echo htmlspecialchars($r['status'] ?? 'N/A'); ?>
                        </span>
                    </td>
                    <td style="font-size:12px;color:#666;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                        title="<?php echo htmlspecialchars($r['notes'] ?? ''); ?>">
                        <?php echo htmlspecialchars($r['notes'] ?? '—'); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="padding:12px 20px;font-size:12px;color:#666;border-top:1px solid #f0f0f0;">
            Showing <?php echo count($hist_rows); ?> most recent records.
        </div>
        <?php endif; ?>
    </div>

    <?php endif; /* end history */ ?>

</div><!-- end wrapper -->


<!-- ── Credit Limit Modal ─────────────────────────────────── -->
<div class="acm-modal-overlay" id="creditModal">
    <div class="acm-modal">
        <h3><i class="fas fa-sliders-h" style="margin-right:8px;"></i>Adjust Credit Limit</h3>
        <div id="creditModalName" style="font-size:13px;color:#666;margin-bottom:16px;"></div>
        <input type="hidden" id="creditCustomerId">
        <label>New Credit Limit (₱)</label>
        <input type="number" id="creditLimitInput" min="0" step="0.01" placeholder="e.g. 5000.00">
        <label>Note / Reason (optional)</label>
        <textarea id="creditNoteInput" rows="2" placeholder="Reason for adjustment…" style="resize:vertical;"></textarea>
        <div class="acm-modal-actions">
            <button class="btn-acm btn-acm-outline" onclick="closeCreditModal()">Cancel</button>
            <button class="btn-acm btn-acm-primary" onclick="saveCreditLimit()">
                <i class="fas fa-save"></i> Save
            </button>
        </div>
    </div>
</div>


<script>
// ── Credit limit modal ────────────────────────────────────
function openCreditModal(id, name, currentLimit) {
    document.getElementById('creditCustomerId').value  = id;
    document.getElementById('creditModalName').textContent = 'Customer: ' + name;
    document.getElementById('creditLimitInput').value  = currentLimit;
    document.getElementById('creditNoteInput').value   = '';
    document.getElementById('creditModal').classList.add('open');
}
function closeCreditModal() {
    document.getElementById('creditModal').classList.remove('open');
}

function saveCreditLimit() {
    const id    = document.getElementById('creditCustomerId').value;
    const limit = document.getElementById('creditLimitInput').value;
    const note  = document.getElementById('creditNoteInput').value;

    const fd = new FormData();
    fd.append('action',      'adjust_credit_limit');
    fd.append('customer_id', id);
    fd.append('credit_limit', limit);
    fd.append('note',         note);

    fetch(window.location.pathname + '?section=<?php echo $section; ?>', {
        method: 'POST', body: fd
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            closeCreditModal();
            location.reload();
        } else {
            alert('Error: ' + (d.error || 'Could not update credit limit.'));
        }
    })
    .catch(() => alert('Network error. Please try again.'));
}

// ── Toggle customer status ────────────────────────────────
function toggleStatus(id, newStatus) {
    const label = newStatus === 'active' ? 'Reactivate' : 'Deactivate';
    if (!confirm(label + ' this customer?')) return;

    const fd = new FormData();
    fd.append('action',      'toggle_status');
    fd.append('customer_id', id);
    fd.append('status',      newStatus);

    fetch(window.location.pathname + '?section=master', {
        method: 'POST', body: fd
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) location.reload();
        else alert('Error: ' + (d.error || 'Could not update status.'));
    })
    .catch(() => alert('Network error. Please try again.'));
}

// Close modal when clicking outside
document.getElementById('creditModal').addEventListener('click', function(e) {
    if (e.target === this) closeCreditModal();
});
</script>
