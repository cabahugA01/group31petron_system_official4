<?php
$page_id = 'admin_transactions_oversight';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();

if (!in_array($role, ['admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: admin_dashboard.php'); exit;
}

// ── Dynamic column detection ──────────────────────────────────────────────────
function ato_cols(PDO $pdo, string $table): array {
    try {
        $rows = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $r) $map[strtolower($r['Field'])] = true;
        return $map;
    } catch (Exception $e) { return []; }
}
function ato_has(array $map, string $col): bool { return isset($map[strtolower($col)]); }

$mt_cols = ato_cols($pdo, 'merchandise_transactions');
$ft_cols = ato_cols($pdo, 'fuel_transactions');
$jo_cols = ato_cols($pdo, 'job_orders');

// ── Payment status helper ─────────────────────────────────────────────────────
// Derives Paid / Partial / Unpaid from amount_paid vs total_amount columns if available.
// Falls back to payment_method presence as a proxy.
function ato_pay_status(array $row): string {
    $total = (float)($row['amount'] ?? 0);
    $paid  = isset($row['amount_paid']) ? (float)$row['amount_paid'] : null;
    if ($paid === null) {
        // proxy: if payment_method is set and not 'N/A' assume Paid
        $pm = strtolower(trim($row['payment_method'] ?? ''));
        return ($pm !== '' && $pm !== 'n/a') ? 'Paid' : 'Unpaid';
    }
    if ($paid <= 0)            return 'Unpaid';
    if ($paid < $total - 0.01) return 'Partial';
    return 'Paid';
}

// ── Filters ───────────────────────────────────────────────────────────────────
// Two tabs: "transactions" (Merchandise + Job Orders unified) and "fuel"
$active_tab = ($_GET['tab'] ?? 'transactions') === 'fuel' ? 'fuel' : 'transactions';
$start      = $_GET['start']  ?? date('Y-m-d', strtotime('-30 days'));
$end        = $_GET['end']    ?? date('Y-m-d');
$search     = trim($_GET['search'] ?? '');
$status_f   = trim($_GET['status'] ?? '');
$type_f     = trim($_GET['type']   ?? ''); // 'merchandise' | 'job_order' | ''

// ── Excel export header (early, before any output) ────────────────────────────
$is_export = isset($_GET['export']) && $_GET['export'] === 'excel';
if ($is_export) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="transactions_oversight_' . $active_tab . '_' . date('Y-m-d') . '.xls"');
    header('Pragma: no-cache');
}

// ── Fetch unified Merchandise + Job Orders ────────────────────────────────────
$rows         = [];
$total_amount = 0.0;

if ($active_tab === 'transactions') {

    // ── Merchandise rows ──────────────────────────────────────────────────────
    $mt_status_col  = ato_has($mt_cols, 'validation_status') ? 'mt.validation_status' : "'Pending'";
    $mt_staff_col   = ato_has($mt_cols, 'staff_id')          ? 'u.name'               : "'Unknown'";
    $mt_date_col    = "CASE WHEN mt.transaction_date > '2000-01-01' THEN mt.transaction_date ELSE mt.created_at END";
    $mt_paid_col    = ato_has($mt_cols, 'amount_paid')        ? 'mt.amount_paid'       : 'NULL';

    $mt_where  = "WHERE mt.station_id = ? AND DATE({$mt_date_col}) BETWEEN ? AND ?";
    $mt_params = [$station_id, $start, $end];
    if ($search !== '') {
        $mt_where .= " AND (mt.customer_name LIKE ? OR mt.transaction_id LIKE ?)";
        $mt_params[] = "%$search%"; $mt_params[] = "%$search%";
    }
    if ($status_f !== '') {
        $mt_where .= " AND LOWER(TRIM(COALESCE(mt.validation_status,''))) = LOWER(?)";
        $mt_params[] = $status_f;
    }

    $mt_rows = [];
    if ($type_f === '' || $type_f === 'merchandise') {
        try {
            $stmt = $pdo->prepare("
                SELECT
                    mt.transaction_id                                       AS txn_id,
                    COALESCE(NULLIF(TRIM(mt.customer_name),''),'Walk-in')  AS customer,
                    'Merchandise'                                           AS entry_type,
                    COALESCE(
                        NULLIF((SELECT GROUP_CONCAT(i.product_name ORDER BY i.id SEPARATOR ', ')
                                FROM merchandise_transaction_items i WHERE i.transaction_id = mt.id),''),
                        mt.item_sku, 'N/A'
                    )                                                       AS items_service,
                    mt.total_amount                                         AS amount,
                    {$mt_paid_col}                                          AS amount_paid,
                    COALESCE(mt.payment_method,'Cash')                      AS payment_method,
                    {$mt_date_col}                                          AS txn_date,
                    COALESCE({$mt_status_col},'Pending')                    AS validation_status,
                    COALESCE({$mt_staff_col},'Unknown')                     AS staff_name
                FROM merchandise_transactions mt
                LEFT JOIN users u ON u.id = mt.staff_id
                {$mt_where}
                GROUP BY mt.id
                ORDER BY txn_date DESC
            ");
            $stmt->execute($mt_params);
            $mt_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $mt_rows = []; }
    }

    // ── Job Order rows ────────────────────────────────────────────────────────
    $jo_status_col   = ato_has($jo_cols, 'validation_status') ? 'jo.validation_status' : 'jo.status';
    $jo_staff_col    = ato_has($jo_cols, 'created_by')        ? 'COALESCE(jo.created_by, jo.user_id)' : 'jo.user_id';
    $jo_mechanic_col = ato_has($jo_cols, 'assigned_mechanic_id') ? 'COALESCE(m.name,\'\')' : "''";
    $jo_pay_col      = ato_has($jo_cols, 'payment_method')    ? 'COALESCE(jo.payment_method,\'N/A\')' : "'N/A'";
    $jo_cost_col     = ato_has($jo_cols, 'total_cost')        ? 'COALESCE(jo.total_cost,0)' : 'COALESCE(jo.estimated_cost,0)';
    $jo_paid_col     = ato_has($jo_cols, 'amount_paid')       ? 'jo.amount_paid' : 'NULL';
    $mechanic_join   = ato_has($jo_cols, 'assigned_mechanic_id') ? "LEFT JOIN users m ON m.id = jo.assigned_mechanic_id" : "";

    $jo_where  = "WHERE jo.station_id = ? AND DATE(jo.created_at) BETWEEN ? AND ?";
    $jo_params = [$station_id, $start, $end];
    if ($search !== '') {
        $jo_where .= " AND (jo.customer_name LIKE ? OR jo.service_type LIKE ? OR jo.vehicle_plate LIKE ?)";
        $jo_params[] = "%$search%"; $jo_params[] = "%$search%"; $jo_params[] = "%$search%";
    }
    if ($status_f !== '') {
        $jo_where .= " AND LOWER(TRIM(COALESCE({$jo_status_col},''))) = LOWER(?)";
        $jo_params[] = $status_f;
    }

    $jo_rows = [];
    if ($type_f === '' || $type_f === 'job_order') {
        try {
            $stmt = $pdo->prepare("
                SELECT
                    CONCAT('JO-', jo.id)                                        AS txn_id,
                    COALESCE(NULLIF(TRIM(jo.customer_name),''),'Walk-in')       AS customer,
                    'Job Order'                                                  AS entry_type,
                    CONCAT(
                        COALESCE(jo.service_type,'Service'),
                        CASE WHEN jo.vehicle_plate IS NOT NULL AND jo.vehicle_plate != ''
                             THEN CONCAT(' | ', jo.vehicle_plate) ELSE '' END,
                        CASE WHEN {$jo_mechanic_col} != ''
                             THEN CONCAT(' | Mech: ', {$jo_mechanic_col}) ELSE '' END
                    )                                                            AS items_service,
                    {$jo_cost_col}                                               AS amount,
                    {$jo_paid_col}                                               AS amount_paid,
                    {$jo_pay_col}                                                AS payment_method,
                    jo.created_at                                                AS txn_date,
                    COALESCE(NULLIF(TRIM({$jo_status_col}),''),'Pending')        AS validation_status,
                    COALESCE(u.name,'Unknown')                                   AS staff_name
                FROM job_orders jo
                LEFT JOIN users u ON u.id = {$jo_staff_col}
                {$mechanic_join}
                {$jo_where}
                ORDER BY jo.created_at DESC
                LIMIT 500
            ");
            $stmt->execute($jo_params);
            $jo_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $jo_rows = []; }
    }

    // Merge and sort by date desc
    $rows = array_merge($mt_rows, $jo_rows);
    usort($rows, fn($a, $b) => strtotime($b['txn_date']) - strtotime($a['txn_date']));

} else {
    // ── Fuel tab ──────────────────────────────────────────────────────────────
    $ft_status_col = ato_has($ft_cols, 'status')           ? 'ft.status'           : "'N/A'";
    $ft_staff_col  = ato_has($ft_cols, 'staff_id')         ? 'u.name'              : "'Unknown'";
    $ft_date_col   = ato_has($ft_cols, 'transaction_date') ? 'ft.transaction_date' : 'ft.created_at';
    $ft_paid_col   = ato_has($ft_cols, 'amount_paid')      ? 'ft.amount_paid'      : 'NULL';

    $ft_where  = "WHERE ft.station_id = ? AND DATE({$ft_date_col}) BETWEEN ? AND ?";
    $ft_params = [$station_id, $start, $end];
    if ($search !== '') {
        $ft_where .= " AND (ft.transaction_id LIKE ? OR ft.fuel_type LIKE ?)";
        $ft_params[] = "%$search%"; $ft_params[] = "%$search%";
    }
    if ($status_f !== '') {
        $ft_where .= " AND LOWER(TRIM(COALESCE(ft.status,''))) = LOWER(?)";
        $ft_params[] = $status_f;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                ft.transaction_id                                       AS txn_id,
                'Fuel Customer'                                         AS customer,
                'Fuel'                                                  AS entry_type,
                CONCAT(ft.fuel_type, ' ', COALESCE(ft.liters_sold,0), 'L') AS items_service,
                ft.total_amount                                         AS amount,
                {$ft_paid_col}                                          AS amount_paid,
                COALESCE(ft.payment_method,'Cash')                      AS payment_method,
                {$ft_date_col}                                          AS txn_date,
                COALESCE({$ft_status_col},'Pending')                    AS validation_status,
                COALESCE({$ft_staff_col},'Unknown')                     AS staff_name
            FROM fuel_transactions ft
            LEFT JOIN users u ON u.id = ft.staff_id
            {$ft_where}
            ORDER BY txn_date DESC
            LIMIT 500
        ");
        $stmt->execute($ft_params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $rows = []; }
}

foreach ($rows as $r) $total_amount += (float)($r['amount'] ?? 0);

// ── Status counts ─────────────────────────────────────────────────────────────
$status_counts = [];
$type_counts   = [];
foreach ($rows as $r) {
    $s = strtolower(trim($r['validation_status'] ?? 'pending'));
    $status_counts[$s] = ($status_counts[$s] ?? 0) + 1;
    $t = $r['entry_type'] ?? '';
    $type_counts[$t] = ($type_counts[$t] ?? 0) + 1;
}

// ── Excel export output ───────────────────────────────────────────────────────
if ($is_export) {
    echo '<table border="1"><thead><tr>
        <th>Transaction ID</th><th>Customer</th><th>Type</th><th>Items / Service</th>
        <th>Amount</th><th>Payment Method</th><th>Payment Status</th>
        <th>Validation Status</th><th>Date/Time</th><th>Staff</th>
    </tr></thead><tbody>';
    foreach ($rows as $r) {
        $pay_st = ato_pay_status($r);
        echo '<tr>';
        echo '<td>' . htmlspecialchars($r['txn_id'])           . '</td>';
        echo '<td>' . htmlspecialchars($r['customer'])         . '</td>';
        echo '<td>' . htmlspecialchars($r['entry_type'])       . '</td>';
        echo '<td>' . htmlspecialchars($r['items_service'])    . '</td>';
        echo '<td>&#8369;' . number_format((float)$r['amount'], 2) . '</td>';
        echo '<td>' . htmlspecialchars($r['payment_method'])   . '</td>';
        echo '<td>' . $pay_st                                  . '</td>';
        echo '<td>' . htmlspecialchars($r['validation_status']). '</td>';
        echo '<td>' . date('M d, Y H:i', strtotime($r['txn_date'])) . '</td>';
        echo '<td>' . htmlspecialchars($r['staff_name'])       . '</td>';
        echo '</tr>';
    }
    echo '<tr><td colspan="4"><strong>TOTAL</strong></td>';
    echo '<td><strong>&#8369;' . number_format($total_amount, 2) . '</strong></td>';
    echo '<td colspan="5">' . count($rows) . ' record(s)</td></tr>';
    echo '</tbody></table>';
    exit;
}

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-eye" style="color:#002F6C;margin-right:8px;"></i>Transactions Oversight</h1>
        <div class="sub">Read-only view — all Merchandise, Job Order, and Fuel transactions for monitoring and transparency</div>
    </div>
    <div class="actions" style="display:flex;gap:8px;align-items:center;">
        <a href="?tab=<?php echo $active_tab; ?>&start=<?php echo urlencode($start); ?>&end=<?php echo urlencode($end); ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_f); ?>&type=<?php echo urlencode($type_f); ?>&export=excel"
           style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#1d6f42;color:#fff;border-radius:7px;text-decoration:none;font-size:13px;font-weight:600;">
            <i class="fas fa-file-excel"></i> Export Excel
        </a>
        <button onclick="window.print()"
           style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#002F6C;color:#fff;border-radius:7px;border:none;font-size:13px;font-weight:600;cursor:pointer;">
            <i class="fas fa-print"></i> Print
        </button>
    </div>
</div>

<?php if (isset($_SESSION['error'])): ?>
<div style="background:#f8d7da;color:#721c24;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
</div>
<?php endif; ?>

<!-- ── Two-tab bar: Merchandise/Service Transactions | Fuel Transactions ──── -->
<div class="ato-tab-bar">
    <a href="?tab=transactions&start=<?php echo urlencode($start); ?>&end=<?php echo urlencode($end); ?>"
       class="ato-tab <?php echo $active_tab === 'transactions' ? 'ato-tab-active' : ''; ?>">
        <i class="fas fa-receipt"></i> Merchandise/Service Transactions
    </a>
    <a href="?tab=fuel&start=<?php echo urlencode($start); ?>&end=<?php echo urlencode($end); ?>"
       class="ato-tab <?php echo $active_tab === 'fuel' ? 'ato-tab-active' : ''; ?>">
        <i class="fas fa-gas-pump"></i> Fuel Transactions
    </a>
</div>

<!-- ── Filter Bar ──────────────────────────────────────────────────────────── -->
<div class="ato-filter-card">
    <form method="get" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
        <input type="hidden" name="tab" value="<?php echo htmlspecialchars($active_tab); ?>">
        <div class="ato-flt-grp">
            <label class="ato-lbl"><i class="fas fa-calendar-alt"></i> Date Range</label>
            <div style="display:flex;gap:6px;align-items:center;">
                <input type="date" name="start" value="<?php echo htmlspecialchars($start); ?>" class="ato-inp" max="<?php echo date('Y-m-d'); ?>">
                <span style="color:#999;font-size:12px;">to</span>
                <input type="date" name="end"   value="<?php echo htmlspecialchars($end);   ?>" class="ato-inp" max="<?php echo date('Y-m-d'); ?>">
            </div>
        </div>
        <div class="ato-flt-grp">
            <label class="ato-lbl"><i class="fas fa-search"></i> Search</label>
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                   class="ato-inp" placeholder="Transaction ID, customer..." style="width:200px;">
        </div>
        <?php if ($active_tab === 'transactions'): ?>
        <div class="ato-flt-grp">
            <label class="ato-lbl"><i class="fas fa-layer-group"></i> Type</label>
            <select name="type" class="ato-inp ato-select">
                <option value="">All Types</option>
                <option value="merchandise" <?php echo $type_f === 'merchandise' ? 'selected' : ''; ?>>Merchandise</option>
                <option value="job_order"   <?php echo $type_f === 'job_order'   ? 'selected' : ''; ?>>Job Order</option>
            </select>
        </div>
        <?php endif; ?>
        <div class="ato-flt-grp">
            <label class="ato-lbl"><i class="fas fa-circle-dot"></i> Validation Status</label>
            <select name="status" class="ato-inp ato-select">
                <option value="">All Statuses</option>
                <?php
                $status_opts = $active_tab === 'fuel'
                    ? ['Pending','Verified','Rejected']
                    : ['Pending','Approved','Rejected','Adjusted','In Progress','Completed'];
                foreach ($status_opts as $opt):
                ?>
                <option value="<?php echo strtolower($opt); ?>" <?php echo strtolower($status_f) === strtolower($opt) ? 'selected' : ''; ?>>
                    <?php echo $opt; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ato-flt-grp" style="align-self:flex-end;">
            <button type="submit" class="ato-btn ato-btn-search"><i class="fas fa-search"></i> Search</button>
            <a href="?tab=<?php echo htmlspecialchars($active_tab); ?>" class="ato-btn ato-btn-reset"><i class="fas fa-rotate-left"></i> Reset</a>
        </div>
    </form>
</div>





<!-- ── Unified Table ───────────────────────────────────────────────────────── -->
<div class="card" style="padding:0;">
    <div style="overflow-x:auto;">
        <table class="ato-table">
            <thead>
                <tr>
                    <th>Transaction ID</th>
                    <th>Customer</th>
                    <th>Type</th>
                    <th>Items / Service</th>
                    <th style="text-align:right;">Amount</th>
                    <th>Payment Method</th>
                    <th>Payment Status</th>
                    <th>Validation Status</th>
                    <th>Date / Time</th>
                    <th>Staff</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <?php
                    $vs   = strtolower(trim($r['validation_status'] ?? ''));
                    $vsc  = '#6c757d'; $vstxt = '#fff';
                    if (in_array($vs, ['approved','verified','completed','validated'])) { $vsc = '#28a745'; }
                    elseif (in_array($vs, ['pending','pending validation']))            { $vsc = '#e6a817'; $vstxt = '#212529'; }
                    elseif (in_array($vs, ['rejected','cancelled','returned']))         { $vsc = '#dc3545'; }
                    elseif ($vs === 'adjusted')                                         { $vsc = '#6f42c1'; }
                    elseif ($vs === 'in progress')                                      { $vsc = '#0d6efd'; }
                    $pay_st = ato_pay_status($r);
                    $pay_bg = $pay_st === 'Paid' ? '#28a745' : ($pay_st === 'Partial' ? '#fd7e14' : '#dc3545');
                    $et     = $r['entry_type'] ?? '';
                    $etbg   = $et === 'Fuel' ? '#fd7e14' : ($et === 'Job Order' ? '#6f42c1' : '#0d6efd');
                ?>
                <tr>
                    <td style="font-weight:700;font-size:11px;font-family:monospace;white-space:nowrap;">
                        <?php echo htmlspecialchars($r['txn_id']); ?>
                    </td>
                    <td><?php echo htmlspecialchars($r['customer']); ?></td>
                    <td>
                        <span style="background:<?php echo $etbg; ?>;color:#fff;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;white-space:nowrap;">
                            <?php echo htmlspecialchars($et); ?>
                        </span>
                    </td>
                    <td style="font-size:12px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                        title="<?php echo htmlspecialchars($r['items_service']); ?>">
                        <?php echo htmlspecialchars($r['items_service']); ?>
                    </td>
                    <td style="font-weight:700;color:#002F6C;text-align:right;white-space:nowrap;">
                        &#8369;<?php echo number_format((float)$r['amount'], 2); ?>
                    </td>
                    <td style="font-size:12px;"><?php echo htmlspecialchars($r['payment_method']); ?></td>
                    <td>
                        <span style="background:<?php echo $pay_bg; ?>;color:#fff;padding:2px 9px;border-radius:10px;font-size:10px;font-weight:700;white-space:nowrap;">
                            <?php echo $pay_st; ?>
                        </span>
                    </td>
                    <td>
                        <span style="background:<?php echo $vsc; ?>;color:<?php echo $vstxt; ?>;padding:2px 9px;border-radius:10px;font-size:10px;font-weight:700;white-space:nowrap;">
                            <?php echo htmlspecialchars(ucfirst($r['validation_status'])); ?>
                        </span>
                    </td>
                    <td style="white-space:nowrap;font-size:11px;">
                        <?php echo date('M d, Y H:i', strtotime($r['txn_date'])); ?>
                    </td>
                    <td style="font-size:11px;color:#555;"><?php echo htmlspecialchars($r['staff_name']); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($rows)): ?>
                <tr>
                    <td colspan="10" style="text-align:center;padding:48px;color:#888;">
                        <i class="fas fa-inbox" style="font-size:36px;display:block;margin-bottom:12px;opacity:.3;"></i>
                        No transactions found for the selected filters.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.ato-tab-bar { display:flex;gap:0;border-bottom:2px solid #dee2e6;margin-bottom:18px; }
.ato-tab { display:inline-flex;align-items:center;gap:7px;padding:10px 22px;font-size:13px;font-weight:600;color:#6c757d;text-decoration:none;border-bottom:3px solid transparent;margin-bottom:-2px;transition:color .15s,border-color .15s;white-space:nowrap; }
.ato-tab:hover { color:#002F6C; }
.ato-tab-active { color:#002F6C;border-bottom-color:#002F6C;background:#f8fbff;border-radius:6px 6px 0 0; }
.ato-tab-badge { background:#e8f0fe;color:#002F6C;padding:1px 8px;border-radius:10px;font-size:10px;font-weight:700;margin-left:4px; }
.ato-tab-active .ato-tab-badge { background:#002F6C;color:#fff; }
.ato-filter-card { background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px 18px;margin-bottom:14px;box-shadow:0 1px 4px rgba(0,0,0,.05); }
.ato-flt-grp { display:flex;flex-direction:column;gap:4px; }
.ato-lbl { font-size:11px;font-weight:700;color:#6c757d;text-transform:uppercase;letter-spacing:.4px; }
.ato-inp { height:36px;padding:0 10px;border:1px solid #ced4da;border-radius:7px;font-size:13px;color:#333;background:#fff;outline:none;box-sizing:border-box; }
.ato-inp:focus { border-color:#002F6C;box-shadow:0 0 0 3px rgba(0,47,108,.1); }
.ato-select { cursor:pointer;min-width:150px; }
.ato-btn { display:inline-flex;align-items:center;gap:6px;padding:0 16px;height:36px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;white-space:nowrap;transition:filter .15s; }
.ato-btn:hover { filter:brightness(.88); }
.ato-btn-search { background:#002F6C;color:#fff; }
.ato-btn-reset  { background:#6c757d;color:#fff; }
.ato-summary-bar { display:flex;gap:10px;flex-wrap:wrap;align-items:center;padding:10px 16px;background:#f8f9fa;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:14px;font-size:13px;color:#555; }
.ato-sum-pill { background:#e8f0fe;color:#002F6C;padding:2px 10px;border-radius:10px;font-size:12px; }
.ato-pill-type { background:#f3e8ff;color:#6f42c1; }
.ato-sum-sep { color:#dee2e6;font-size:16px; }
.ato-readonly-notice { display:flex;align-items:center;gap:10px;padding:10px 16px;background:#fff8e1;border:1px solid #ffe082;border-radius:8px;margin-bottom:14px;font-size:12px;color:#5d4037; }
.ato-table { width:100%;border-collapse:collapse;font-size:12px; }
.ato-table thead th { background:#f8f9fa;color:#495057;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:9px 10px;border-bottom:2px solid #dee2e6; }
.ato-table tbody td { padding:8px 10px;border-bottom:1px solid #f0f0f0;vertical-align:middle; }
.ato-table tbody tr:hover td { background:#f8fbff; }
@media print {
    .sidebar,.top-header,.page-head .actions,.ato-filter-card,.ato-tab-bar { display:none !important; }
    .main { margin:0 !important;padding:0 !important; }
    .ato-table { font-size:10px; }
}
</style>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const tables = document.querySelectorAll('table.ato-table');
        tables.forEach(table => {
            const container = table.closest('div');
            if (!container) return;
            if (container.nextElementSibling && container.nextElementSibling.classList.contains('pagination-wrapper')) return;

            const tbody = table.querySelector('tbody');
            if (!tbody) return;
            
            // Filter out the empty placeholder row if it exists
            let rows = Array.from(tbody.querySelectorAll('tr'));
            if (rows.length === 1 && rows[0].querySelector('.fa-inbox')) return;

            let currentPage = 1;
            let rowsPerPage = 10;
            let totalRows = rows.length;
            let totalPages = Math.ceil(totalRows / rowsPerPage);

            const wrapper = document.createElement('div');
            wrapper.className = 'pagination-wrapper client-side-pagination';
            wrapper.style.cssText = 'display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; background: #fff; border: 1px solid #EAEAEA; border-radius: 12px; margin-top: 12px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.02); flex-wrap: wrap; gap: 10px;';
            
            if (!document.getElementById('client-pagination-style')) {
                const style = document.createElement('style');
                style.id = 'client-pagination-style';
                style.innerHTML = `
                    .rows-per-page { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #6b7280; }
                    .rows-per-page select { padding: 6px; border: 1px solid #e5e7eb; border-radius: 4px; outline: none; cursor: pointer; }
                    .page-info { font-size: 13px; color: #6b7280; }
                    .pagination-controls { display: flex; align-items: center; gap: 10px; }
                    .btn-page { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; background: #fff; border: 1px solid #e5e7eb; border-radius: 6px; color: #374151; text-decoration: none; transition: 0.2s; cursor: pointer; }
                    .btn-page:hover:not(.disabled) { background: #f3f4f6; }
                    .btn-page.disabled { opacity: 0.5; cursor: not-allowed; pointer-events: none; }
                    .current-page { font-size: 13px; font-weight: 500; color: #111827; }
                `;
                document.head.appendChild(style);
            }

            function renderTable() {
                tbody.innerHTML = '';
                const start = (currentPage - 1) * rowsPerPage;
                const end = start + rowsPerPage;
                const paginatedRows = rows.slice(start, end);
                
                paginatedRows.forEach(row => tbody.appendChild(row));
                updateControls();
            }

            function updateControls() {
                totalPages = Math.ceil(totalRows / rowsPerPage);
                
                const start = (currentPage - 1) * rowsPerPage + 1;
                const end = Math.min(currentPage * rowsPerPage, totalRows);
                
                wrapper.innerHTML = `
                    <div class="rows-per-page">
                        <label>Rows per page:</label>
                        <select class="rpp-select">
                            <option value="10" ${rowsPerPage === 10 ? 'selected' : ''}>10</option>
                            <option value="25" ${rowsPerPage === 25 ? 'selected' : ''}>25</option>
                            <option value="50" ${rowsPerPage === 50 ? 'selected' : ''}>50</option>
                            <option value="100" ${rowsPerPage === 100 ? 'selected' : ''}>100</option>
                            <option value="${totalRows}" ${rowsPerPage === totalRows ? 'selected' : ''}>All</option>
                        </select>
                    </div>
                    <div class="page-info">
                        Showing ${totalRows === 0 ? 0 : start} to ${end} of ${totalRows} entries
                    </div>
                    <div class="pagination-controls">
                        <button type="button" class="btn-page prev-btn ${currentPage === 1 ? 'disabled' : ''}"><i class="fa-solid fa-chevron-left"></i></button>
                        <span class="current-page">Page ${currentPage} of ${Math.max(1, totalPages)}</span>
                        <button type="button" class="btn-page next-btn ${currentPage === totalPages || totalPages === 0 ? 'disabled' : ''}"><i class="fa-solid fa-chevron-right"></i></button>
                    </div>
                `;

                wrapper.querySelector('.rpp-select').addEventListener('change', function(e) {
                    rowsPerPage = parseInt(e.target.value);
                    currentPage = 1;
                    renderTable();
                });

                wrapper.querySelector('.prev-btn').addEventListener('click', function(e) {
                    e.preventDefault();
                    if (currentPage > 1) {
                        currentPage--;
                        renderTable();
                    }
                });

                wrapper.querySelector('.next-btn').addEventListener('click', function(e) {
                    e.preventDefault();
                    if (currentPage < totalPages) {
                        currentPage++;
                        renderTable();
                    }
                });
            }

            container.parentNode.insertBefore(wrapper, container.nextSibling);
            renderTable();
        });
    });
</script>
<div style="height: 80px;"></div>
<?php include __DIR__ . '/../partials/footer.php'; ?>
