<?php
$page_id = 'mgr_cust_history';
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
$search      = trim($_GET['search'] ?? '');
$pay_status  = $_GET['pay_status'] ?? '';   // paid, unpaid, partial
$start       = $_GET['start'] ?? date('Y-m-d', strtotime('-90 days'));
$end         = $_GET['end']   ?? date('Y-m-d');

// ── Fetch customers with linked transactions ──────────────────────────────────
$customers = [];
try {
    $sql = "
        SELECT
            c.id,
            c.name AS customer_name,
            c.phone,
            c.email,
            COALESCE(c.credit_balance, 0) AS outstanding_balance,
            COALESCE(c.credit_limit, 0) AS credit_limit,
            COUNT(DISTINCT mt.id) AS merch_count,
            COUNT(DISTINCT jo.id) AS jo_count,
            COALESCE(SUM(DISTINCT mt.total_amount), 0) AS merch_total,
            COALESCE(SUM(DISTINCT jo.total_cost), 0) AS jo_total,
            MAX(GREATEST(
                COALESCE(mt.created_at, '2000-01-01'),
                COALESCE(jo.created_at, '2000-01-01')
            )) AS last_transaction
        FROM customers c
        LEFT JOIN merchandise_transactions mt
            ON mt.customer_name = c.name
            AND mt.station_id = c.station_id
            AND DATE(COALESCE(mt.transaction_date, mt.created_at)) BETWEEN ? AND ?
        LEFT JOIN job_orders jo
            ON jo.customer_name = c.name
            AND jo.station_id = c.station_id
            AND DATE(jo.created_at) BETWEEN ? AND ?
        WHERE c.station_id = ?
    ";
    $params = [$start, $end, $start, $end, $station_id];

    if ($search !== '') {
        $sql .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)";
        $params[] = '%'.$search.'%'; $params[] = '%'.$search.'%'; $params[] = '%'.$search.'%';
    }
    if ($pay_status === 'unpaid') {
        $sql .= " AND COALESCE(c.credit_balance, 0) > 0";
    } elseif ($pay_status === 'paid') {
        $sql .= " AND COALESCE(c.credit_balance, 0) <= 0";
    }

    $sql .= " GROUP BY c.id ORDER BY last_transaction DESC, c.name ASC LIMIT 300";
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback: fetch from transactions directly if customers table doesn't exist
    try {
        $sql2 = "
            SELECT
                COALESCE(NULLIF(TRIM(mt.customer_name),''), 'Walk-in') AS customer_name,
                NULL AS phone, NULL AS email,
                0 AS outstanding_balance, 0 AS credit_limit,
                COUNT(*) AS merch_count, 0 AS jo_count,
                SUM(mt.total_amount) AS merch_total, 0 AS jo_total,
                MAX(COALESCE(mt.transaction_date, mt.created_at)) AS last_transaction,
                NULL AS id
            FROM merchandise_transactions mt
            WHERE mt.station_id = ?
              AND DATE(COALESCE(mt.transaction_date, mt.created_at)) BETWEEN ? AND ?
              AND mt.customer_name IS NOT NULL AND TRIM(mt.customer_name) != ''
        ";
        $p2 = [$station_id, $start, $end];
        if ($search !== '') { $sql2 .= " AND mt.customer_name LIKE ?"; $p2[] = '%'.$search.'%'; }
        $sql2 .= " GROUP BY mt.customer_name ORDER BY last_transaction DESC LIMIT 300";
        $stmt2 = $pdo->prepare($sql2); $stmt2->execute($p2);
        $customers = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e2) {}
}

// ── Selected customer detail ──────────────────────────────────────────────────
$selected_customer = null;
$cust_merch_txns   = [];
$cust_jo_txns      = [];

if (isset($_GET['cid'])) {
    $cid = trim($_GET['cid']);
    // Fetch linked merchandise transactions
    try {
        $stmt = $pdo->prepare("
            SELECT mt.id, mt.transaction_id AS txn_ref,
                   COALESCE(NULLIF(TRIM(mt.customer_name),''),'Walk-in') AS customer,
                   mt.total_amount AS total,
                   COALESCE(mt.payment_method,'Cash') AS payment_method,
                   COALESCE(mt.validation_status,'Pending') AS status,
                   COALESCE(mt.transaction_date, mt.created_at) AS created_at,
                   u.name AS staff_name
            FROM merchandise_transactions mt
            LEFT JOIN users u ON mt.staff_id = u.id
            WHERE mt.station_id = ? AND mt.customer_name = ?
            ORDER BY created_at DESC LIMIT 100
        ");
        $stmt->execute([$station_id, $cid]);
        $cust_merch_txns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $selected_customer = $cid;
    } catch (Exception $e) {}

    // Fetch linked job orders
    try {
        $stmt = $pdo->prepare("
            SELECT jo.id, CONCAT('JO-', jo.id) AS txn_ref,
                   COALESCE(NULLIF(TRIM(jo.customer_name),''),'Walk-in') AS customer,
                   COALESCE(jo.total_cost, 0) AS total,
                   COALESCE(jo.payment_method,'N/A') AS payment_method,
                   COALESCE(jo.validation_status, jo.status, 'Pending') AS status,
                   jo.vehicle_plate, jo.service_type,
                   jo.created_at,
                   u.name AS staff_name
            FROM job_orders jo
            LEFT JOIN users u ON jo.user_id = u.id
            WHERE jo.station_id = ? AND jo.customer_name = ?
            ORDER BY jo.created_at DESC LIMIT 100
        ");
        $stmt->execute([$station_id, $cid]);
        $cust_jo_txns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-clock-rotate-left" style="color:#002F6C;"></i> Customer History</h1>
        <div class="sub">Read-only view — linked transactions per customer, payment status, and outstanding balances</div>
    </div>
</div>

<?php if (isset($_SESSION['error'])): ?>
<div class="ch-alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
<?php endif; ?>

<!-- Filter Card -->
<div class="ch-filter-card">
    <div class="ch-filter-header">
        <span class="ch-filter-title"><i class="fas fa-filter"></i> Filter Customers</span>
    </div>
    <form method="get" class="ch-filter-row">
        <div class="ch-flt-group">
            <label class="ch-flt-lbl"><i class="fas fa-calendar-alt"></i> Date Range</label>
            <div class="ch-date-wrap">
                <input type="date" name="start" value="<?php echo htmlspecialchars($start); ?>" class="ch-inp" max="<?php echo date('Y-m-d'); ?>">
                <span class="ch-date-sep">to</span>
                <input type="date" name="end" value="<?php echo htmlspecialchars($end); ?>" class="ch-inp" max="<?php echo date('Y-m-d'); ?>">
            </div>
        </div>
        <div class="ch-flt-group">
            <label class="ch-flt-lbl"><i class="fas fa-user"></i> Customer Search</label>
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" class="ch-inp" placeholder="Name, phone, email…">
        </div>
        <div class="ch-flt-group">
            <label class="ch-flt-lbl"><i class="fas fa-circle-dot"></i> Balance Status</label>
            <select name="pay_status" class="ch-inp ch-select">
                <option value="">All</option>
                <option value="paid"   <?php echo $pay_status==='paid'?'selected':''; ?>>✅ Paid / No Balance</option>
                <option value="unpaid" <?php echo $pay_status==='unpaid'?'selected':''; ?>>⚠️ Has Outstanding Balance</option>
            </select>
        </div>
        <div class="ch-flt-group ch-flt-btns">
            <label class="ch-flt-lbl">&nbsp;</label>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="ch-btn ch-btn-search"><i class="fas fa-search"></i> Search</button>
                <a href="manager_customer_history.php" class="ch-btn ch-btn-reset"><i class="fas fa-rotate-left"></i> Reset</a>
            </div>
        </div>
    </form>
</div>

<div class="ch-layout">
    <!-- Customer List -->
    <div class="ch-list-panel">
        <div class="ch-panel-header">
            <span><i class="fas fa-users"></i> Customers (<?php echo count($customers); ?>)</span>
        </div>
        <div class="ch-list-scroll">
            <?php foreach ($customers as $c): ?>
            <?php
                $balance = (float)($c['outstanding_balance'] ?? 0);
                $isSelected = ($selected_customer === $c['customer_name']);
                $balanceClass = $balance > 0 ? 'ch-balance-warn' : 'ch-balance-ok';
            ?>
            <a href="?start=<?php echo urlencode($start); ?>&end=<?php echo urlencode($end); ?>&search=<?php echo urlencode($search); ?>&pay_status=<?php echo urlencode($pay_status); ?>&cid=<?php echo urlencode($c['customer_name']); ?>"
               class="ch-cust-item <?php echo $isSelected ? 'ch-cust-active' : ''; ?>">
                <div class="ch-cust-name"><?php echo htmlspecialchars($c['customer_name']); ?></div>
                <div class="ch-cust-meta">
                    <?php if ($c['phone']): ?><span><?php echo htmlspecialchars($c['phone']); ?></span><?php endif; ?>
                    <span><?php echo (int)$c['merch_count']; ?> merch · <?php echo (int)$c['jo_count']; ?> JO</span>
                </div>
                <?php if ($balance > 0): ?>
                <div class="ch-balance-badge ch-balance-warn">⚠️ ₱<?php echo number_format($balance, 2); ?> outstanding</div>
                <?php else: ?>
                <div class="ch-balance-badge ch-balance-ok">✅ No balance</div>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
            <?php if (empty($customers)): ?>
            <div style="padding:32px;text-align:center;color:#aaa;font-size:13px;">
                <i class="fas fa-users" style="font-size:28px;display:block;margin-bottom:8px;opacity:.3;"></i>
                No customers found.
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Transaction Detail Panel -->
    <div class="ch-detail-panel">
        <?php if ($selected_customer): ?>
        <div class="ch-panel-header">
            <span><i class="fas fa-history"></i> Transaction History — <?php echo htmlspecialchars($selected_customer); ?></span>
        </div>

        <!-- Merchandise Transactions -->
        <?php if (!empty($cust_merch_txns)): ?>
        <div class="ch-section-title"><i class="fas fa-shopping-cart" style="color:#0d6efd;"></i> Merchandise Transactions (<?php echo count($cust_merch_txns); ?>)</div>
        <div class="ch-table-wrap">
            <table class="ch-table">
                <thead>
                    <tr>
                        <th>Transaction ID</th>
                        <th>Total</th>
                        <th>Payment</th>
                        <th>Status</th>
                        <th>Staff</th>
                        <th>Date/Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cust_merch_txns as $mt): ?>
                    <?php
                        $mst = strtolower($mt['status'] ?? '');
                        if (str_contains($mst,'approved')||str_contains($mst,'verified')) { $mc='#28a745';$ml='Approved'; }
                        elseif (str_contains($mst,'pending')) { $mc='#e6a817';$ml='Pending'; }
                        elseif (str_contains($mst,'rejected')||str_contains($mst,'returned')) { $mc='#dc3545';$ml='Rejected'; }
                        else { $mc='#6c757d';$ml=ucfirst($mt['status']); }
                    ?>
                    <tr>
                        <td style="font-weight:600;font-size:11px;">#<?php echo htmlspecialchars($mt['txn_ref'] ?? $mt['id']); ?></td>
                        <td style="font-weight:700;color:#002F6C;">₱<?php echo number_format($mt['total'],2); ?></td>
                        <td><?php echo htmlspecialchars($mt['payment_method']); ?></td>
                        <td><span style="background:<?php echo $mc; ?>;color:<?php echo $mc==='#e6a817'?'#212529':'#fff'; ?>;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;"><?php echo $ml; ?></span></td>
                        <td style="font-size:11px;"><?php echo htmlspecialchars($mt['staff_name']); ?></td>
                        <td style="font-size:11px;white-space:nowrap;"><?php echo date('M d, Y H:i', strtotime($mt['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Job Orders -->
        <?php if (!empty($cust_jo_txns)): ?>
        <div class="ch-section-title" style="margin-top:18px;"><i class="fas fa-wrench" style="color:#fd7e14;"></i> Job Orders (<?php echo count($cust_jo_txns); ?>)</div>
        <div class="ch-table-wrap">
            <table class="ch-table">
                <thead>
                    <tr>
                        <th>JO ID</th>
                        <th>Service</th>
                        <th>Vehicle</th>
                        <th>Total</th>
                        <th>Payment</th>
                        <th>Status</th>
                        <th>Date/Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cust_jo_txns as $jo): ?>
                    <?php
                        $jst = strtolower($jo['status'] ?? '');
                        if (str_contains($jst,'approved')||str_contains($jst,'completed')) { $jc='#28a745';$jl='Approved'; }
                        elseif (str_contains($jst,'pending')) { $jc='#e6a817';$jl='Pending'; }
                        elseif (str_contains($jst,'rejected')||str_contains($jst,'cancelled')) { $jc='#dc3545';$jl='Rejected'; }
                        elseif (str_contains($jst,'progress')) { $jc='#0d6efd';$jl='In Progress'; }
                        else { $jc='#6c757d';$jl=ucfirst($jo['status']); }
                    ?>
                    <tr>
                        <td style="font-weight:600;font-size:11px;">#<?php echo htmlspecialchars($jo['txn_ref']); ?></td>
                        <td style="font-size:11px;"><?php echo htmlspecialchars($jo['service_type'] ?? '—'); ?></td>
                        <td style="font-size:11px;"><?php echo htmlspecialchars($jo['vehicle_plate'] ?? '—'); ?></td>
                        <td style="font-weight:700;color:#002F6C;">₱<?php echo number_format($jo['total'],2); ?></td>
                        <td><?php echo htmlspecialchars($jo['payment_method']); ?></td>
                        <td><span style="background:<?php echo $jc; ?>;color:<?php echo $jc==='#e6a817'?'#212529':'#fff'; ?>;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;"><?php echo $jl; ?></span></td>
                        <td style="font-size:11px;white-space:nowrap;"><?php echo date('M d, Y H:i', strtotime($jo['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if (empty($cust_merch_txns) && empty($cust_jo_txns)): ?>
        <div style="padding:48px;text-align:center;color:#aaa;">
            <i class="fas fa-inbox" style="font-size:36px;display:block;margin-bottom:12px;opacity:.3;"></i>
            No transactions found for this customer in the selected date range.
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="ch-panel-header"><span><i class="fas fa-hand-pointer"></i> Select a customer to view history</span></div>
        <div style="padding:60px;text-align:center;color:#aaa;">
            <i class="fas fa-clock-rotate-left" style="font-size:48px;display:block;margin-bottom:16px;opacity:.15;"></i>
            <div style="font-size:14px;">Click a customer from the list to view their linked transactions, payment status, and outstanding balances.</div>
            <div style="font-size:12px;margin-top:8px;color:#ccc;">This is a read-only view. No edits can be made here.</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.ch-alert-error { background:#f8d7da;color:#721c24;padding:12px 16px;border-radius:8px;margin-bottom:16px; }

/* Filter */
.ch-filter-card { background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px 20px;margin-bottom:18px;box-shadow:0 1px 6px rgba(0,0,0,.05); }
.ch-filter-header { display:flex;justify-content:space-between;align-items:center;margin-bottom:14px; }
.ch-filter-title { font-size:13px;font-weight:700;color:#002F6C;text-transform:uppercase;letter-spacing:.5px; }
.ch-filter-row { display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap; }
.ch-flt-group { display:flex;flex-direction:column;gap:5px;min-width:130px; }
.ch-flt-lbl { font-size:11px;font-weight:700;color:#6c757d;text-transform:uppercase;letter-spacing:.5px; }
.ch-inp { height:36px;padding:0 10px;border:1px solid #ced4da;border-radius:7px;font-size:13px;color:#333;background:#fff;outline:none;width:100%;box-sizing:border-box; }
.ch-inp:focus { border-color:#002F6C;box-shadow:0 0 0 3px rgba(0,47,108,.1); }
.ch-select { cursor:pointer; }
.ch-date-wrap { display:flex;align-items:center;gap:6px; }
.ch-date-wrap .ch-inp { width:140px; }
.ch-date-sep { font-size:12px;color:#999; }
.ch-btn { display:inline-flex;align-items:center;gap:6px;padding:0 16px;height:36px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;white-space:nowrap;transition:all .2s; }
.ch-btn-search { background:#002F6C;color:#fff; }
.ch-btn-reset  { background:#6c757d;color:#fff; }
.ch-btn:hover  { filter:brightness(.88); }

/* Layout */
.ch-layout { display:flex;gap:16px;align-items:flex-start; }
.ch-list-panel { width:280px;flex-shrink:0;background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;box-shadow:0 1px 6px rgba(0,0,0,.05); }
.ch-detail-panel { flex:1;background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;box-shadow:0 1px 6px rgba(0,0,0,.05); }
.ch-panel-header { padding:12px 16px;background:#f8f9fa;border-bottom:1px solid #e2e8f0;font-size:13px;font-weight:700;color:#002F6C; }
.ch-list-scroll { max-height:600px;overflow-y:auto; }

/* Customer list items */
.ch-cust-item { display:block;padding:12px 14px;border-bottom:1px solid #f0f0f0;text-decoration:none;color:#333;transition:background .15s; }
.ch-cust-item:hover { background:#f8fbff; }
.ch-cust-active { background:#e8f0fe !important;border-left:3px solid #002F6C; }
.ch-cust-name { font-weight:700;font-size:13px;color:#212529; }
.ch-cust-meta { font-size:11px;color:#888;margin-top:2px;display:flex;gap:8px;flex-wrap:wrap; }
.ch-balance-badge { font-size:10px;font-weight:700;margin-top:4px;padding:2px 6px;border-radius:6px;display:inline-block; }
.ch-balance-warn { background:#fff3cd;color:#856404; }
.ch-balance-ok   { background:#d1fae5;color:#065f46; }

/* Section title */
.ch-section-title { padding:10px 16px;font-size:12px;font-weight:700;color:#495057;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid #f0f0f0;background:#fafbfc; }

/* Table */
.ch-table-wrap { overflow-x:auto; }
.ch-table { width:100%;border-collapse:collapse;font-size:12px;min-width:500px; }
.ch-table thead th { background:#f8f9fa;color:#495057;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:8px 10px;border-bottom:2px solid #dee2e6;white-space:nowrap; }
.ch-table tbody td { padding:7px 10px;border-bottom:1px solid #f0f0f0;vertical-align:middle; }
.ch-table tbody tr:hover td { background:#f8fbff; }

@media (max-width: 768px) {
    .ch-layout { flex-direction:column; }
    .ch-list-panel { width:100%; }
}
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>
