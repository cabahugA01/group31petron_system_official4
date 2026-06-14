<?php
$page_id = 'inv_fuel';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? 'staff');
$station_id = user_station_id();

// ── Module gate ───────────────────────────────────────────────
if (!in_array($role, ['superadmin', 'developer']) && !is_module_enabled('inventory')) {
    render_module_disabled_page('Inventory');
}

if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
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
$del_lookup = [];
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
$msg = '';
try {
    foreach ($TANK_CONFIG_17 as $tc) {
        $ft_key   = strtolower(trim($tc['fuel_type']));
        $tank_key = strtolower(trim($tc['tank']));
        $inv      = $fi_lookup[$ft_key] ?? null;

        $capacity  = (float)$tc['capacity'];
        $cur_level = $inv ? (float)($inv['current_level'] ?? $inv['current_stock'] ?? 0) : 0;

        // Number of tanks for this fuel type
        $same_type_count = count(array_filter($TANK_CONFIG_17, fn($t) => strtolower($t['fuel_type']) === $ft_key));

        // Deliveries: per tank_assigned
        $purchases = $del_lookup[$tank_key] ?? 0;

        // Sales & Calibration: split equally
        $sales_total = $sales_lookup[$ft_key] ?? 0;
        $adj_total   = $adj_lookup[$ft_key] ?? 0;
        $sales       = $same_type_count > 0 ? round($sales_total / $same_type_count, 2) : 0;
        $calibration = $same_type_count > 0 ? round($adj_total / $same_type_count, 2) : 0;

        // Beginning Balance
        $beginning = $same_type_count > 0 ? round($cur_level / $same_type_count, 2) : 0;

        $total_available = $beginning + $purchases;
        $ending_system   = max(0, $total_available - $sales - $calibration);

        // Actual Dip = use ending_system as proxy
        $actual_dip = $ending_system;
        $variance   = $ending_system - $actual_dip;

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
} catch (Exception $e) {
    $msg = 'Error loading fuel inventory: ' . $e->getMessage();
}

$pending_fuel_sr = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM fuel_stock_requests WHERE staff_id=? AND status='Pending'");
    $s->execute([$me['id']]);
    $pending_fuel_sr = (int)$s->fetchColumn();
} catch (Exception $e) {}

// Build JS data array for Stock Request modal
$js_fuel = [];
foreach ($rows as $r) {
    $fl  = (float)$r['current_level'];
    $cap = (float)$r['capacity'];
    $pct = $cap > 0 ? ($fl / $cap) * 100 : 0;
    
    if      ($fl  <= 0)   { $st = 'OUT OF STOCK'; $sc = '#dc3545'; $st_cls = 'status-critical'; }
    elseif  ($pct <= 10)  { $st = 'CRITICAL';     $sc = '#dc3545'; $st_cls = 'status-critical'; }
    elseif  ($pct <= 25)  { $st = 'LOW';          $sc = '#fd7e14'; $st_cls = 'status-low'; }
    else                  { $st = 'AVAILABLE';    $sc = '#28a745'; $st_cls = 'status-ok'; }
    
    $js_fuel[] = [
        'name'         => $r['fuel_type'],
        'tanker_label' => $r['label'] ?? '',
        'tanker_num'   => $r['tanker_num'] ?? 0,
        'level'        => $fl,
        'capacity'     => $cap,
        'pct'          => round($pct, 1),
        'variance'     => $r['variance'],
        'status'       => $st,
        'statusCls'    => $st_cls,
        'color'        => $sc,
    ];
}

include __DIR__ . '/../partials/header.php';
?>
<style>
.inv-card { background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.06); border:1px solid #e9ecef; margin-bottom:20px; }
.inv-card-head { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid #e9ecef; flex-wrap:wrap; gap:8px; }
.inv-card-title { font-size:1rem; font-weight:700; color:#002F70; display:flex; align-items:center; gap:8px; }
.inv-card-body  { padding:20px; }

/* ── No-Scroll Fixed-Layout Table ── */
body, html { overflow-x: hidden !important; }

.table-wrap {
    width: 100%;
    max-width: 100%;
    overflow: hidden;   /* no scroll at all */
    padding: 0;
}
.fuel-table {
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
    table-layout: fixed !important;
    border-collapse: collapse;
    border-spacing: 0;
    font-size: 13px;
}
.fuel-table thead tr { background: #002F6C; }
.fuel-table thead th {
    padding: 10px 6px; 
    text-align: center; 
    font-size: 11px; 
    font-weight: 700;
    color: #fff; 
    text-transform: uppercase; 
    letter-spacing: .3px;
    white-space: normal; 
    word-wrap: break-word; 
    overflow-wrap: break-word;
    line-height: 1.35; 
    vertical-align: middle;
}
.fuel-table tbody tr { border-bottom: 1px solid #f1f5f9; transition: background .1s; }
.fuel-table tbody tr:hover { background: #eff6ff; }
.fuel-table tbody td {
    padding: 9px 6px; 
    color: #1e293b; 
    vertical-align: middle;
    text-align: center; 
    overflow: hidden; 
    text-overflow: ellipsis;
    white-space: nowrap; 
    line-height: 1.4; 
    font-size: 13px;
}
.fuel-table tbody td.bold { font-weight: 700; color: #002F70; }
.status-pill {
    display: inline-block; 
    padding: 3px 10px; 
    border-radius: 20px;
    font-size: 11px; 
    font-weight: 700; 
    white-space: nowrap;
}
.var-zero { color: #6c757d; }
.var-pos  { color: #28a745; font-weight: 700; }
.var-neg  { color: #dc3545; font-weight: 700; }

/* ══ Modal Elements ══ */
.sr-modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:10000; display:flex; align-items:center; justify-content:center; opacity:0; pointer-events:none; transition:opacity .2s ease-in-out; }
.sr-modal-overlay.open { opacity:1; pointer-events:auto; }
.sr-modal-box { background:#fff; border-radius:12px; width:100%; max-width:540px; box-shadow:0 10px 25px rgba(0,0,0,.2); display:flex; flex-direction:column; max-height:90vh; overflow:hidden; }
.sr-modal-head { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid #e2e8f0; }
.sr-modal-title { font-size:16px; font-weight:700; color:#002F70; }
.sr-modal-close { background:none; border:none; font-size:24px; color:#64748b; cursor:pointer; }
.sr-info-box { background:#eff6ff; border-left:4px solid #002F70; padding:12px 16px; margin:16px; border-radius:0 8px 8px 0; font-size:13px; color:#1e293b; line-height:1.5; }
.fsr-select-bar { display:flex; align-items:center; padding:10px 20px; background:#f8fafc; border-bottom:1px solid #e2e8f0; font-size:13px; font-weight:600; }
#fsrCheckList { overflow-y:auto; flex:1; padding:8px 16px; }
.fsr-cb-row { display:flex; align-items:flex-start; gap:12px; padding:10px 12px; border-radius:8px; border:1px solid #e2e8f0; margin-bottom:8px; cursor:pointer; transition:all .15s ease; }
.fsr-cb-row:hover { background:#f8fafc; border-color:#cbd5e1; }
.fsr-cb-row.checked { background:#f0fdf4; border-color:#86efac; }
.fsr-cb-row input[type="checkbox"] { margin-top:3px; transform:scale(1.1); }
.fsr-item-info { flex:1; }
.fsr-item-name { font-weight:700; font-size:14px; color:#1e293b; }
.fsr-item-meta { font-size:12px; color:#64748b; margin-top:3px; display:flex; align-items:center; flex-wrap:wrap; gap:4px; }
.sr-modal-footer { display:flex; align-items:center; justify-content:flex-end; gap:10px; padding:16px 20px; border-top:1px solid #e2e8f0; background:#f8fafc; }
</style>

<div class="mif-head" style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
    <div>
        <h1 style="margin:0 0 4px;font-size:22px;font-weight:700;color:#00264D;">Fuel Inventory</h1>
        <div style="font-size:13px;color:#6b7280;text-transform:uppercase;letter-spacing:.3px;">17-Tanker Overview &middot; Today: <?= date('F d, Y') ?></div>
    </div>
</div>

<?php if ($msg): ?>
<div style="background:#f8d7da;color:#721c24;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>




<div class="inv-card">
    <div class="inv-card-head">
        <div class="inv-card-title">17-Tanker Fuel Inventory Grid</div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <?php
            $export_table_id       = 'fuelTable';
            $export_filename       = 'fuel_inventory_' . date('Ymd');
            $export_title          = 'Fuel Inventory';
            $export_rows_select_id = 'fuelRowsLimit';
            $export_default_rows   = 20;
            require __DIR__ . '/../partials/export_buttons.php';
            ?>
            <button onclick="openFuelSrModal()"
                    style="background:#002F70;color:#fff;border:none;display:inline-flex;align-items:center;gap:7px;height:36px;padding:8px 18px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;">
                Stock Request
            </button>
        </div>
    </div>
    <div class="inv-card-body">
        <div class="table-wrap">
            <table class="fuel-table" id="fuelTable">
                <colgroup>
                    <col style="width:6%">
                    <col style="width:13%">
                    <col style="width:17%">
                    <col style="width:12%">
                    <col style="width:18%">
                    <col style="width:12%">
                    <col style="width:10%">
                    <col style="width:12%">
                </colgroup>
                <thead>
                    <tr>
                        <th>Tanker No.</th>
                        <th>Fuel Type</th>
                        <th>Tanker Ref.</th>
                        <th>Capacity (L)</th>
                        <th>Current Level</th>
                        <th>Status</th>
                        <th>Variance (L)</th>
                        <th>Timestamp</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="8" style="text-align:center;padding:32px;color:#6c757d;font-size:14px;">
                            No fuel inventory data available.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $r):
                        $var = $r['variance'];
                        $var_cls = abs($var) < 0.01 ? 'var-zero' : ($var >= 0 ? 'var-pos' : 'var-neg');
                        $var_str = abs($var) < 0.01 ? '0.00' : (($var > 0 ? '+' : '') . number_format($var, 2));
                        $ts_str  = $r['timestamp'] ? date('M d, Y g:i A', strtotime($r['timestamp'])) : '—';
                        $fill    = min(100, round($r['fill_pct'], 0));
                        $fl      = $r['current_level'];
                    ?>
                    <tr>
                        <td style="font-weight:700;color:#002F70;"><?= $r['tanker_num'] ?></td>
                        <td style="font-weight:700;"><?= htmlspecialchars($r['fuel_type']) ?></td>
                        <td style="font-weight:600;color:#002F70;"><?= htmlspecialchars($r['label']) ?></td>
                        <td><?= number_format($r['capacity'], 0) ?></td>
                        <td><?= number_format($fl, 0) ?> L &middot; <?= $fill ?>%</td>
                        <td>
                            <span class="status-pill" style="background:<?= $r['status_color'] ?>18;color:<?= $r['status_color'] ?>;border:1px solid <?= $r['status_color'] ?>40;">
                                <?= htmlspecialchars($r['status']) ?>
                            </span>
                        </td>
                        <td><span class="<?= $var_cls ?>"><?= $var_str ?></span></td>
                        <td style="color:#64748b;"><?= $ts_str ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div id="fuelPagination"></div>
    </div>
</div>

<!-- ══ FUEL STOCK REQUEST MODAL ══ -->
<div class="sr-modal-overlay" id="fuelSrModal">
    <div class="sr-modal-box">
        <div class="sr-modal-head">
            <div class="sr-modal-title">
                Fuel Stock Request
            </div>
            <button class="sr-modal-close" id="fuelSrClose">&times;</button>
        </div>

        <div class="sr-info-box">
            <strong>Select the fuel types you want to request, then click Submit.</strong><br>
            &bull; Manager will review and set the approved liters<br>
            &bull; Fuel inventory is NOT updated until Manager processes the delivery
        </div>

        <!-- Select-all bar -->
        <div class="fsr-select-bar">
            <input type="checkbox" id="fsrSelectAll">
            <label for="fsrSelectAll" style="cursor:pointer;margin:0;margin-left:8px;">Select All</label>
            <span id="fsrSelectedCount" style="margin-left:auto;color:#002F70;"></span>
        </div>

        <!-- Fuel list with checkboxes -->
        <div id="fsrCheckList"></div>

        <div id="fsrError" style="display:none;background:#fee2e2;color:#dc3545;padding:10px 14px;border-radius:6px;margin-bottom:12px;font-size:13px;"></div>

        <div class="sr-modal-footer">
            <button type="button" id="fsrCancelBtn"
                    style="padding:9px 22px;background:#6c757d;color:#fff;border:none;border-radius:6px;cursor:pointer;">
                Cancel
            </button>
            <button type="button" id="fsrSubmitBtn"
                    style="padding:9px 22px;background:#002F70;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600;">
                Submit Request
            </button>
        </div>
    </div>
</div>

<!-- ── Success popup ── -->
<div id="fsrSuccessOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:10998;"></div>
<div id="fsrSuccessPopup" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:10999;background:#fff;padding:28px;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.25);text-align:center;min-width:300px;">
    <div style="width:56px;height:56px;background:linear-gradient(135deg,#28a745,#20c997);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
        <span style="color:#fff;font-size:32px;font-weight:700;">✓</span>
    </div>
    <h3 style="margin:0 0 8px;color:#28a745;">Request Submitted!</h3>
    <p style="margin:0 0 18px;color:#333;font-size:14px;line-height:1.5;" id="fsrSuccessMsg">
        Your fuel stock request is now <strong>Pending</strong> Manager review.
    </p>
    <button onclick="closeFsrSuccess()" style="background:#002F70;color:#fff;border:none;padding:9px 26px;border-radius:6px;cursor:pointer;font-weight:600;">OK</button>
</div>

<script>
var allFuelData = <?php echo json_encode($js_fuel); ?>;

// ── Open modal ────────────────────────────────────────────────────────────────
function openFuelSrModal() {
    renderFsrCheckList();
    syncFsrSelectAll();
    document.getElementById('fsrError').style.display = 'none';
    document.getElementById('fsrSubmitBtn').disabled  = false;
    document.getElementById('fsrSubmitBtn').innerHTML = 'Submit Request';
    document.getElementById('fuelSrModal').classList.add('open');
}

function renderFsrCheckList() {
    // Only show fuels that need replenishment — exclude AVAILABLE status
    var needsRestock = allFuelData.filter(function(it) {
        var s = (it.status || '').toUpperCase();
        return s === 'CRITICAL' || s === 'LOW' || s === 'LOW STOCK' || s === 'OUT OF STOCK';
    });

    if (needsRestock.length === 0) {
        document.getElementById('fsrCheckList').innerHTML =
            '<div style="text-align:center;padding:28px 16px;color:#6c757d;">' +
            '<strong>All fuel tanks are at sufficient levels.</strong><br>' +
            '<small>Stock requests are only needed for Critical, Low, or Out-of-Stock fuels.</small></div>';
        document.getElementById('fsrSubmitBtn').disabled = true;
        return;
    }

    document.getElementById('fsrSubmitBtn').disabled = false;

    var html = needsRestock.map(function(it) {
        var idx = allFuelData.indexOf(it);
        var bar = '<div style="background:#e9ecef;border-radius:3px;height:6px;width:70px;display:inline-block;vertical-align:middle;margin:0 5px;">' +
                  '<div style="width:' + Math.min(100, it.pct) + '%;height:100%;background:' + it.color + ';border-radius:3px;"></div></div>';
        var badge = '<span style="background:' + it.color + '20;color:' + it.color + ';border:1px solid ' + it.color + '40;border-radius:20px;padding:1px 7px;font-size:10px;font-weight:700;">' + esc(it.status) + '</span>';
        var displayName = it.tanker_label ? it.tanker_label : it.name;
        return '<label class="fsr-cb-row ' + it.statusCls + '" data-idx="' + idx + '" style="display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:8px;border:1px solid #e2e8f0;margin-bottom:8px;cursor:pointer;">' +
            '<input type="checkbox" class="fsr-cb fsr-item-cb" data-idx="' + idx + '">' +
            '<div class="fsr-item-info">' +
                '<div class="fsr-item-name">' + esc(displayName) + '</div>' +
                '<div class="fsr-item-meta">' +
                    it.level.toLocaleString('en-PH',{minimumFractionDigits:2}) + ' L / ' +
                    it.capacity.toLocaleString('en-PH',{minimumFractionDigits:2}) + ' L ' +
                    bar + it.pct + '% &bull; ' + badge +
                '</div>' +
            '</div>' +
        '</label>';
    }).join('');
    document.getElementById('fsrCheckList').innerHTML = html;

    // Highlight row when checkbox changes
    document.querySelectorAll('.fsr-item-cb').forEach(function(cb) {
        cb.addEventListener('change', function() {
            var row = this.closest('.fsr-cb-row');
            if (row) {
                row.classList.toggle('checked', this.checked);
            }
            syncFsrSelectAll();
        });
    });
}

function syncFsrSelectAll() {
    var all     = document.querySelectorAll('.fsr-item-cb');
    var checked = document.querySelectorAll('.fsr-item-cb:checked');
    var sa = document.getElementById('fsrSelectAll');
    if (sa) {
        sa.indeterminate = checked.length > 0 && checked.length < all.length;
        sa.checked       = all.length > 0 && checked.length === all.length;
    }
    var countLabel = document.getElementById('fsrSelectedCount');
    if (countLabel) {
        countLabel.textContent = checked.length > 0 ? checked.length + ' selected' : '';
    }
}

var selectAllEl = document.getElementById('fsrSelectAll');
if (selectAllEl) {
    selectAllEl.addEventListener('change', function() {
        var c = this.checked;
        document.querySelectorAll('.fsr-item-cb').forEach(function(cb) { cb.checked = c; });
        document.querySelectorAll('.fsr-cb-row').forEach(function(row) { row.classList.toggle('checked', c); });
        syncFsrSelectAll();
    });
}

// ── Close ─────────────────────────────────────────────────────────────────────
function closeFuelSrModal() { document.getElementById('fuelSrModal').classList.remove('open'); }
document.getElementById('fuelSrClose').addEventListener('click', closeFuelSrModal);
document.getElementById('fsrCancelBtn').addEventListener('click', closeFuelSrModal);
document.getElementById('fuelSrModal').addEventListener('click', function(e) { if (e.target === this) closeFuelSrModal(); });
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeFuelSrModal(); });

// ── Submit ────────────────────────────────────────────────────────────────────
document.getElementById('fsrSubmitBtn').addEventListener('click', function() {
    var checked = document.querySelectorAll('.fsr-item-cb:checked');
    if (checked.length === 0) {
        var el = document.getElementById('fsrError');
        el.textContent = 'Please select at least one fuel type.';
        el.style.display = 'block';
        return;
    }

    var btn = this;
    btn.disabled = true;
    btn.innerHTML = 'Submitting...';
    document.getElementById('fsrError').style.display = 'none';

    var queue = [];
    checked.forEach(function(cb) { queue.push(allFuelData[parseInt(cb.dataset.idx)]); });

    var results = { ok: 0, fail: 0, errors: [] };

    function submitNext() {
        if (queue.length === 0) {
            closeFuelSrModal();
            var msg = results.ok + ' fuel request' + (results.ok !== 1 ? 's' : '') + ' submitted successfully.';
            if (results.fail > 0) msg += ' ' + results.fail + ' failed: ' + results.errors.join('; ');
            document.getElementById('fsrSuccessMsg').innerHTML = msg;
            document.getElementById('fsrSuccessPopup').style.display  = 'block';
            document.getElementById('fsrSuccessOverlay').style.display = 'block';
            setTimeout(closeFsrSuccess, 6000);
            return;
        }
        var it = queue.shift();
        fetch('../backend/api/fuel_stock_request.php?action=create', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                fuel_type:        it.name,
                current_level:    it.level,
                capacity:         it.capacity,
                stock_status:     it.status,
                requested_liters: 0,
                remarks:          ''
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) results.ok++;
            else { results.fail++; results.errors.push(it.name + ': ' + (res.message || 'error')); }
            submitNext();
        })
        .catch(function() {
            results.fail++;
            results.errors.push(it.name + ': network error');
            submitNext();
        });
    }
    submitNext();
});

function closeFsrSuccess() {
    document.getElementById('fsrSuccessPopup').style.display  = 'none';
    document.getElementById('fsrSuccessOverlay').style.display = 'none';
    location.reload();
}
function esc(str) { var d = document.createElement('div'); d.appendChild(document.createTextNode(str)); return d.innerHTML; }

document.addEventListener('DOMContentLoaded', function() {
    ['fuelSrModal','fsrSuccessOverlay','fsrSuccessPopup'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el && el.parentNode !== document.body) document.body.appendChild(el);
    });
    if (typeof setupTablePagination === 'function') {
        setupTablePagination('fuelTable', 'fuelRowsLimit', 'fuelPagination', 20);
    }
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
