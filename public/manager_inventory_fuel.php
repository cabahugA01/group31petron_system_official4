<?php
$page_id = 'mgr_inv_fuel';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

// ── Module gate ───────────────────────────────────────────────
if (!in_array($role, ['superadmin', 'developer']) && !is_module_enabled('inventory')) {
    render_module_disabled_page('Inventory');
}

if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    header('Location: dashboard.php');
    exit;
}

// ── 17-Tanker Configuration ──────────────────────────────────────────
$TANK_CONFIG_17 = [
    ['fuel_type'=>'Diesel',       'label'=>'DIESEL 1 - 1',     'tank'=>'Underground Tank #1',  'tanker_num'=>1,  'capacity'=>50000],
    ['fuel_type'=>'Diesel',       'label'=>'DIESEL 1 - 2',     'tank'=>'Underground Tank #2',  'tanker_num'=>2,  'capacity'=>50000],
    ['fuel_type'=>'Diesel',       'label'=>'DIESEL 1 - 3',     'tank'=>'Underground Tank #3',  'tanker_num'=>3,  'capacity'=>50000],
    ['fuel_type'=>'Diesel',       'label'=>'DIESEL 1 - 4',     'tank'=>'Underground Tank #4',  'tanker_num'=>4,  'capacity'=>50000],
    ['fuel_type'=>'Diesel',       'label'=>'DIESEL 2 - 5',     'tank'=>'Underground Tank #5',  'tanker_num'=>5,  'capacity'=>50000],
    ['fuel_type'=>'Diesel',       'label'=>'DIESEL 2 - 6',     'tank'=>'Underground Tank #6',  'tanker_num'=>6,  'capacity'=>50000],
    ['fuel_type'=>'Kerosene',     'label'=>'KEROSENE - 1',     'tank'=>'Underground Tank #7',  'tanker_num'=>7,  'capacity'=>20000],
    ['fuel_type'=>'Turbo Diesel', 'label'=>'TURBO DIESEL - 1', 'tank'=>'Underground Tank #8',  'tanker_num'=>8,  'capacity'=>45000],
    ['fuel_type'=>'Turbo Diesel', 'label'=>'TURBO DIESEL - 2', 'tank'=>'Underground Tank #9',  'tanker_num'=>9,  'capacity'=>45000],
    ['fuel_type'=>'XCS Plus',     'label'=>'XCS PLUS - 1',     'tank'=>'Underground Tank #10', 'tanker_num'=>10, 'capacity'=>20000],
    ['fuel_type'=>'XCS Plus',     'label'=>'XCS PLUS - 2',     'tank'=>'Underground Tank #11', 'tanker_num'=>11, 'capacity'=>20000],
    ['fuel_type'=>'XCS Plus',     'label'=>'XCS PLUS - 3',     'tank'=>'Underground Tank #12', 'tanker_num'=>12, 'capacity'=>20000],
    ['fuel_type'=>'XCS Plus',     'label'=>'XCS PLUS - 4',     'tank'=>'Underground Tank #13', 'tanker_num'=>13, 'capacity'=>20000],
    ['fuel_type'=>'XTRA UNL',     'label'=>'XTRA UNL 1 - 1',  'tank'=>'Underground Tank #14', 'tanker_num'=>14, 'capacity'=>20000],
    ['fuel_type'=>'XTRA UNL',     'label'=>'XTRA UNL 1 - 2',  'tank'=>'Underground Tank #15', 'tanker_num'=>15, 'capacity'=>20000],
    ['fuel_type'=>'XTRA UNL',     'label'=>'XTRA UNL 2 - 3',  'tank'=>'Underground Tank #16', 'tanker_num'=>16, 'capacity'=>20000],
    ['fuel_type'=>'XTRA UNL',     'label'=>'XTRA UNL 2 - 4',  'tank'=>'Underground Tank #17', 'tanker_num'=>17, 'capacity'=>20000],
];

// ── Fetch fuel_inventory (one row per fuel_type for this station) ─────
$fi_lookup = [];
try {
    $s = $pdo->prepare("SELECT fuel_type, current_level, current_stock, capacity, price_per_liter, latest_calibration, status, last_updated FROM fuel_inventory WHERE station_id = ?");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fi_lookup[strtolower(trim($row['fuel_type']))] = $row;
    }
} catch (Exception $e) {}

// ── Fetch today's deliveries per (fuel_type, tank_assigned) ─────────
$del_lookup = []; // key: tank label e.g. "Underground Tank #1"
try {
    $s = $pdo->prepare("SELECT tank_assigned, fuel_type, SUM(delivery_liters) AS total_del FROM fuel_deliveries WHERE station_id=? AND DATE(delivery_date)=CURDATE() AND status='Verified' GROUP BY tank_assigned, fuel_type");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $del_lookup[strtolower(trim($row['tank_assigned']))] = (float)$row['total_del'];
    }
} catch (Exception $e) {}

// ── Fetch today's sales per fuel_type ────────────────────────────────
$sales_lookup = [];
try {
    $s = $pdo->prepare("SELECT fuel_type, SUM(liters_sold) AS total_sales FROM fuel_transactions WHERE station_id=? AND DATE(transaction_date)=CURDATE() AND status='Verified' GROUP BY fuel_type");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sales_lookup[strtolower(trim($row['fuel_type']))] = (float)$row['total_sales'];
    }
} catch (Exception $e) {}

// ── Fetch today's calibration/adjustments per fuel_type ──────────────
$adj_lookup = [];
try {
    $s = $pdo->prepare("SELECT fi.fuel_type, COALESCE(SUM(fa.liters),0) AS total_adj FROM fuel_adjustments fa JOIN fuel_inventory fi ON fa.fuel_type_id=fi.fuel_type_id AND fi.station_id=fa.station_id WHERE fa.station_id=? AND DATE(fa.adjustment_date)=CURDATE() GROUP BY fi.fuel_type");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $adj_lookup[strtolower(trim($row['fuel_type']))] = (float)$row['total_adj'];
    }
} catch (Exception $e) {}

// ── Fetch latest price per fuel_type from fuel_pricing ──────────────
$price_lookup = [];
try {
    $s = $pdo->prepare("SELECT ft.name AS fuel_type, fp.price_per_liter FROM fuel_pricing fp JOIN fuel_types ft ON fp.fuel_type_id=ft.id WHERE fp.station_id=? AND fp.is_active=1 ORDER BY fp.effective_date DESC");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = strtolower(trim($row['fuel_type']));
        if (!isset($price_lookup[$key])) $price_lookup[$key] = (float)$row['price_per_liter'];
    }
} catch (Exception $e) {}

// ── Build 17-row dataset ─────────────────────────────────────────────
$rows = [];
foreach ($TANK_CONFIG_17 as $tc) {
    $ft_key   = strtolower(trim($tc['fuel_type']));
    $tank_key = strtolower(trim($tc['tank']));
    $inv      = $fi_lookup[$ft_key] ?? null;

    $capacity  = (float)$tc['capacity'];
    $cur_level = $inv ? (float)($inv['current_level'] ?? $inv['current_stock'] ?? 0) : 0;

    // Number of tanks for this fuel type (for splitting current_level equally across them)
    $same_type_count = count(array_filter($TANK_CONFIG_17, fn($t) => strtolower($t['fuel_type']) === $ft_key));

    // Deliveries: per tank_assigned
    $purchases = $del_lookup[$tank_key] ?? 0;

    // Sales & Calibration: split equally across same-fuel tanks (approximation)
    $sales_total = $sales_lookup[$ft_key] ?? 0;
    $adj_total   = $adj_lookup[$ft_key] ?? 0;
    $sales       = $same_type_count > 0 ? round($sales_total / $same_type_count, 2) : 0;
    $calibration = $same_type_count > 0 ? round($adj_total / $same_type_count, 2) : 0;

    // Beginning Balance = current_level ÷ number_of_tanks (each tank gets equal share)
    $beginning = $same_type_count > 0 ? round($cur_level / $same_type_count, 2) : 0;

    $total_available = $beginning + $purchases;
    $ending_system   = max(0, $total_available - $sales - $calibration);

    // Actual Dip = use ending_system as proxy (no physical dip table exists yet)
    $actual_dip = $ending_system;
    $variance   = $ending_system - $actual_dip; // 0 until physical dip data exists

    // Current Level per tank
    $current_level_tank = $ending_system;

    // Status
    $fill_pct = $capacity > 0 ? ($current_level_tank / $capacity) * 100 : 0;
    if      ($current_level_tank <= 0)  { $status = 'Out of Stock'; $sc = '#dc3545'; }
    elseif  ($fill_pct <= 10)           { $status = 'Critical';     $sc = '#dc3545'; }
    elseif  ($fill_pct <= 25)           { $status = 'Low';          $sc = '#fd7e14'; }
    else                                { $status = 'Available';    $sc = '#28a745'; }

    // Price
    $price = $price_lookup[$ft_key] ?? ($inv ? (float)($inv['price_per_liter'] ?? 0) : 0);

    // Revenue
    $revenue = round($sales * $price, 2);

    // Timestamp
    $timestamp = $inv['last_updated'] ?? null;

    $rows[] = [
        'fuel_type'       => $tc['fuel_type'],
        'label'           => $tc['label'],
        'tank'            => $tc['tank'],
        'tanker_num'      => $tc['tanker_num'],
        'capacity'        => $capacity,
        'beginning'       => $beginning,
        'purchases'       => $purchases,
        'total_available' => $total_available,
        'sales'           => $sales,
        'calibration'     => $calibration,
        'ending_system'   => $ending_system,
        'actual_dip'      => $actual_dip,
        'variance'        => $variance,
        'current_level'   => $current_level_tank,
        'status'          => $status,
        'status_color'    => $sc,
        'fill_pct'        => $fill_pct,
        'price'           => $price,
        'revenue'         => $revenue,
        'timestamp'       => $timestamp,
    ];
}

include __DIR__ . '/../partials/header.php';
?>
<style>
/* ── Page Header ── */
.mif-head { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:20px; flex-wrap:wrap; }
.mif-head h1 { margin:0 0 4px; font-size:22px; font-weight:700; color:#00264D; }
.mif-sub { font-size:13px; color:#6b7280; text-transform:uppercase; letter-spacing:.3px; }
.mif-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }

/* ── Summary Cards ── */
.mif-stats { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:20px; }
.mif-stat  { background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:14px 18px; min-width:140px; flex:1; box-shadow:0 1px 3px rgba(0,0,0,.04); }
.mif-stat .n { font-size:1.6rem; font-weight:800; color:#002F70; line-height:1.1; }
.mif-stat .l { font-size:12px; font-weight:700; color:#6c757d; text-transform:uppercase; letter-spacing:.4px; margin-top:3px; }
.mif-stat.ok   .n { color:#28a745; }
.mif-stat.warn .n { color:#fd7e14; }
.mif-stat.crit .n { color:#dc3545; }

/* ── Card ── */
.mif-card { background:#fff; border:1px solid #e2e8f0; border-radius:11px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.05); margin-bottom:20px; }
.mif-card-hd { display:flex; align-items:center; justify-content:space-between; padding:13px 16px; border-bottom:1px solid #f1f5f9; flex-wrap:wrap; gap:8px; }
.mif-card-title { font-size:14px; font-weight:700; color:#00264D; text-transform:uppercase; letter-spacing:.3px; margin:0; }

/* ── Table: NO horizontal scroll, fixed layout, centered ── */
.mif-tbl-wrap { width:100%; overflow:hidden; }
.mif-tbl { width:100%; table-layout:fixed; border-collapse:collapse; font-size:13px; }
.mif-tbl thead tr { background:#002F70; }
.mif-tbl thead th {
    padding:10px 6px; text-align:center; font-size:11px; font-weight:700;
    color:#fff; text-transform:uppercase; letter-spacing:.3px;
    white-space:normal; word-wrap:break-word; overflow-wrap:break-word;
    line-height:1.35; vertical-align:middle;
}
.mif-tbl tbody tr { border-bottom:1px solid #f1f5f9; transition:background .1s; }
.mif-tbl tbody tr:hover { background:#eff6ff; }
.mif-tbl tbody td {
    padding:9px 6px; color:#1e293b; vertical-align:middle;
    text-align:center; overflow:hidden; text-overflow:ellipsis;
    white-space:nowrap; line-height:1.4; font-size:13px;
}
.mif-tbl tbody td.bold { font-weight:700; color:#002F70; }
.status-pill {
    display:inline-block; padding:3px 10px; border-radius:20px;
    font-size:11px; font-weight:700; white-space:nowrap;
}
.var-zero { color:#6c757d; }
.var-pos  { color:#28a745; font-weight:700; }
.var-neg  { color:#dc3545; font-weight:700; }
</style>

<?php
// ── Summary counts ──────────────────────────────────────────────────
$cnt_available  = count(array_filter($rows, fn($r) => $r['status'] === 'Available'));
$cnt_low        = count(array_filter($rows, fn($r) => $r['status'] === 'Low'));
$cnt_critical   = count(array_filter($rows, fn($r) => in_array($r['status'], ['Critical','Out of Stock'])));
$total_revenue  = array_sum(array_column($rows, 'revenue'));
$total_sales_l  = array_sum(array_column($rows, 'sales'));
?>

<!-- ══ Page Header ══ -->
<div class="mif-head">
    <div>
        <h1>Fuel Inventory</h1>
        <div class="mif-sub">17-Tanker Overview &middot; Today: <?= date('F d, Y') ?></div>
    </div>
    <div class="mif-actions">
        <?php
        $export_table_id       = 'mgrFuelTable';
        $export_filename       = 'fuel_inventory_' . date('Ymd');
        $export_title          = 'Fuel Inventory';
        $export_rows_select_id = 'mgrFuelRowsLimit';
        $export_default_rows   = 20;
        $export_back_url       = 'manager_dashboard.php';
        require __DIR__ . '/../partials/export_buttons.php';
        ?>
    </div>
</div>

<!-- ══ Summary Stats ══ -->
<div class="mif-stats">
    <div class="mif-stat">
        <div class="n">17</div>
        <div class="l">Total Tankers</div>
    </div>
    <div class="mif-stat ok">
        <div class="n"><?= $cnt_available ?></div>
        <div class="l">Available</div>
    </div>
    <div class="mif-stat warn">
        <div class="n"><?= $cnt_low ?></div>
        <div class="l">Low Level</div>
    </div>
    <div class="mif-stat crit">
        <div class="n"><?= $cnt_critical ?></div>
        <div class="l">Critical / Empty</div>
    </div>
    <div class="mif-stat">
        <div class="n"><?= number_format($total_sales_l, 0) ?> L</div>
        <div class="l">Today's Sales</div>
    </div>
    <div class="mif-stat ok">
        <div class="n">₱<?= number_format($total_revenue, 0) ?></div>
        <div class="l">Today's Revenue</div>
    </div>
</div>

<!-- ══ Inventory Table ══ -->
<div class="mif-card">

    <div class="mif-tbl-wrap">
        <table class="mif-tbl" id="mgrFuelTable">
            <colgroup>
                <col style="width:3%">
                <col style="width:7%">
                <col style="width:8%">
                <col style="width:5%">
                <col style="width:6%">
                <col style="width:6%">
                <col style="width:6%">
                <col style="width:5%">
                <col style="width:5%">
                <col style="width:6%">
                <col style="width:6%">
                <col style="width:5%">
                <col style="width:7%">
                <col style="width:6%">
                <col style="width:5%">
                <col style="width:6%">
                <col style="width:7%">
            </colgroup>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Fuel Type</th>
                    <th>Tanker Ref.</th>
                    <th class="r">Capacity</th>
                    <th class="r">Beg. Balance</th>
                    <th class="r">Purchases</th>
                    <th class="r">Total Avail.</th>
                    <th class="r">Sales (L)</th>
                    <th class="r">Calibration</th>
                    <th class="r">Ending (Sys)</th>
                    <th class="r">Actual Dip</th>
                    <th class="r">Variance</th>
                    <th class="r">Current Level</th>
                    <th>Status</th>
                    <th class="r">Price/L</th>
                    <th class="r">Revenue</th>
                    <th>Timestamp</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr>
                    <td colspan="17" style="text-align:center;padding:32px;color:#6c757d;font-size:14px;">
                        No fuel inventory data available.
                    </td>
                </tr>
            <?php else: ?>
            <?php foreach ($rows as $r):
                $var = $r['variance'];
                $var_cls = abs($var) < 0.01 ? 'var-zero' : ($var >= 0 ? 'var-pos' : 'var-neg');
                $var_str = abs($var) < 0.01 ? '0.00' : (($var > 0 ? '+' : '') . number_format($var, 2));
                $ts_str  = $r['timestamp'] ? date('M d, Y h:i A', strtotime($r['timestamp'])) : '—';
                $fill    = min(100, round($r['fill_pct'], 0));
            ?>
                <tr>
                    <td style="font-weight:700;color:#002F70;"><?= $r['tanker_num'] ?></td>
                    <td style="font-weight:700;"><?= htmlspecialchars($r['fuel_type']) ?></td>
                    <td style="font-weight:600;color:#002F70;"><?= htmlspecialchars($r['label']) ?></td>
                    <td><?= number_format($r['capacity'], 0) ?></td>
                    <td><?= number_format($r['beginning'], 2) ?></td>
                    <td style="color:<?= $r['purchases'] > 0 ? '#16a34a' : '#1e293b' ?>;font-weight:<?= $r['purchases'] > 0 ? '700' : '400' ?>;"><?= number_format($r['purchases'], 2) ?></td>
                    <td class="bold"><?= number_format($r['total_available'], 2) ?></td>
                    <td><?= number_format($r['sales'], 2) ?></td>
                    <td><?= number_format($r['calibration'], 2) ?></td>
                    <td class="bold"><?= number_format($r['ending_system'], 2) ?></td>
                    <td style="font-weight:700;"><?= number_format($r['actual_dip'], 2) ?></td>
                    <td><span class="<?= $var_cls ?>"><?= $var_str ?></span></td>
                    <td><?= number_format($r['current_level'], 0) ?> L &middot; <?= $fill ?>%</td>
                    <td>
                        <span class="status-pill" style="background:<?= $r['status_color'] ?>18;color:<?= $r['status_color'] ?>;border:1px solid <?= $r['status_color'] ?>40;">
                            <?= htmlspecialchars($r['status']) ?>
                        </span>
                    </td>
                    <td>&#8369;<?= number_format($r['price'], 2) ?></td>
                    <td class="bold">&#8369;<?= number_format($r['revenue'], 2) ?></td>
                    <td style="color:#64748b;"><?= $ts_str ?></td>
                </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="mgrFuelPagination"></div>
<p style="font-size:13px;color:#6c757d;margin-top:4px;">
    For detailed fuel operations (deliveries, adjustments, reconciliation), go to
    <a href="manager_fuel_management_complete.php" style="color:#002F70;font-weight:600;">Fuel Management</a>.
</p>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof setupTablePagination === 'function') {
        setupTablePagination('mgrFuelTable', 'mgrFuelRowsLimit', 'mgrFuelPagination', 20);
    }
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
