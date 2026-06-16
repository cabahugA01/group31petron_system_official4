<?php
$page_id = 'admin_inventory_fuel';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();
$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();

// ── Module gate ───────────────────────────────────────────────
if (!in_array($role, ['superadmin', 'developer']) && !is_module_enabled('inventory')) {
    render_module_disabled_page('Inventory');
}

if (!in_array($role, ['admin','superadmin'])) { header('Location: dashboard.php'); exit; }
if ($station_id <= 0 && $role === 'admin') { render_no_station_page('admin_dashboard.php'); }

// Handle edit liters POST
$flash_ok = $_SESSION['ok'] ?? null; unset($_SESSION['ok']);
$flash_err = $_SESSION['err'] ?? null; unset($_SESSION['err']);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_level') {
    $fid  = (int)($_POST['fuel_id'] ?? 0);
    $newL = (float)($_POST['new_level'] ?? 0);
    $note = trim($_POST['note'] ?? '');
    if ($fid > 0 && $newL >= 0) {
        try {
            $stmt = $pdo->prepare("SELECT current_level,capacity FROM fuel_inventory WHERE id=? AND station_id=?");
            $stmt->execute([$fid, $station_id]);
            $fi = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$fi) throw new Exception('Fuel record not found.');
            if ($newL > (float)$fi['capacity']) throw new Exception('New level exceeds tank capacity.');
            $pdo->prepare("UPDATE fuel_inventory SET current_level=?, last_updated=NOW() WHERE id=? AND station_id=?")
                ->execute([$newL, $fid, $station_id]);
            if (function_exists('log_activity'))
                log_activity($pdo, $me['id'], 'Admin Edit Fuel Level',
                    "Fuel ID $fid: {$fi['current_level']}L → {$newL}L. Note: $note");
            $_SESSION['ok'] = 'Fuel level updated successfully.';
        } catch (Exception $e) { $_SESSION['err'] = $e->getMessage(); }
    }
    header('Location: admin_inventory_fuel.php'); exit;
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

// ── DB lookups ──────────────────────────────────────────────────────
$fi_lookup = [];
try {
    $s = $pdo->prepare("SELECT id, fuel_type, current_level, current_stock, capacity, price_per_liter, latest_calibration, status, last_updated FROM fuel_inventory WHERE station_id=?");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row)
        $fi_lookup[strtolower(trim($row['fuel_type']))] = $row;
} catch (Exception $e) {}

$del_lookup = [];
try {
    $s = $pdo->prepare("SELECT tank_assigned, SUM(delivery_liters) AS tot FROM fuel_deliveries WHERE station_id=? AND DATE(delivery_date)=CURDATE() AND status='Verified' GROUP BY tank_assigned");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row)
        $del_lookup[strtolower(trim($row['tank_assigned']))] = (float)$row['tot'];
} catch (Exception $e) {}

$sales_lookup = [];
try {
    $s = $pdo->prepare("SELECT fuel_type, SUM(liters_sold) AS tot FROM fuel_transactions WHERE station_id=? AND DATE(transaction_date)=CURDATE() AND status='Verified' GROUP BY fuel_type");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row)
        $sales_lookup[strtolower(trim($row['fuel_type']))] = (float)$row['tot'];
} catch (Exception $e) {}

$adj_lookup = [];
try {
    $s = $pdo->prepare("SELECT fi.fuel_type, COALESCE(SUM(fa.liters),0) AS tot FROM fuel_adjustments fa JOIN fuel_inventory fi ON fa.fuel_type_id=fi.fuel_type_id AND fi.station_id=fa.station_id WHERE fa.station_id=? AND DATE(fa.adjustment_date)=CURDATE() GROUP BY fi.fuel_type");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row)
        $adj_lookup[strtolower(trim($row['fuel_type']))] = (float)$row['tot'];
} catch (Exception $e) {}

$price_lookup = [];
try {
    $s = $pdo->prepare("SELECT ft.name AS fuel_type, fp.price_per_liter FROM fuel_pricing fp JOIN fuel_types ft ON fp.fuel_type_id=ft.id WHERE fp.station_id=? AND fp.is_active=1 ORDER BY fp.effective_date DESC");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $k = strtolower(trim($row['fuel_type']));
        if (!isset($price_lookup[$k])) $price_lookup[$k] = (float)$row['price_per_liter'];
    }
} catch (Exception $e) {}

// ── Build 17 rows ──────────────────────────────────────────────────
$rows = [];
foreach ($TANK_CONFIG_17 as $tc) {
    $ft_key   = strtolower(trim($tc['fuel_type']));
    $tank_key = strtolower(trim($tc['tank']));
    $inv      = $fi_lookup[$ft_key] ?? null;
    $capacity = (float)$tc['capacity'];
    $cur_level= $inv ? (float)($inv['current_level'] ?? $inv['current_stock'] ?? 0) : 0;
    $same_n   = count(array_filter($TANK_CONFIG_17, fn($t) => strtolower($t['fuel_type']) === $ft_key));
    $purchases   = $del_lookup[$tank_key] ?? 0;
    $sales       = $same_n > 0 ? round(($sales_lookup[$ft_key] ?? 0) / $same_n, 2) : 0;
    $calibration = $same_n > 0 ? round(($adj_lookup[$ft_key]  ?? 0) / $same_n, 2) : 0;
    $beginning   = $same_n > 0 ? round($cur_level / $same_n, 2) : 0;
    $total_avail = $beginning + $purchases;
    $ending      = max(0, $total_avail - $sales - $calibration);
    $actual_dip  = $ending;
    $variance    = 0;
    $fill_pct    = $capacity > 0 ? ($ending / $capacity) * 100 : 0;
    if      ($ending <= 0)     { $status = 'Out of Stock'; $sc = '#dc3545'; }
    elseif  ($fill_pct <= 10)  { $status = 'Critical';     $sc = '#dc3545'; }
    elseif  ($fill_pct <= 25)  { $status = 'Low';          $sc = '#fd7e14'; }
    else                       { $status = 'Available';    $sc = '#28a745'; }
    $price   = $price_lookup[$ft_key] ?? ($inv ? (float)($inv['price_per_liter'] ?? 0) : 0);
    $revenue = round($sales * $price, 2);
    $rows[]  = [
        'fuel_id'        => $inv['id'] ?? 0,
        'fuel_type'      => $tc['fuel_type'],
        'label'          => $tc['label'],
        'tank'           => $tc['tank'],
        'tanker_num'     => $tc['tanker_num'],
        'capacity'       => $capacity,
        'beginning'      => $beginning,
        'purchases'      => $purchases,
        'total_available'=> $total_avail,
        'sales'          => $sales,
        'calibration'    => $calibration,
        'ending_system'  => $ending,
        'actual_dip'     => $actual_dip,
        'variance'       => $variance,
        'current_level'  => $ending,
        'status'         => $status,
        'status_color'   => $sc,
        'fill_pct'       => $fill_pct,
        'price'          => $price,
        'revenue'        => $revenue,
        'timestamp'      => $inv['last_updated'] ?? null,
    ];
}

$cnt_avail = count(array_filter($rows, fn($r) => $r['status'] === 'Available'));
$cnt_low   = count(array_filter($rows, fn($r) => $r['status'] === 'Low'));
$cnt_crit  = count(array_filter($rows, fn($r) => in_array($r['status'], ['Critical','Out of Stock'])));

include __DIR__ . '/../partials/header.php';
?>
<style>
:root{--blue:#002F6C;--red:#dc3545;--gray:#6c757d;}
body,html{overflow-x:hidden!important;}
.flash-ok{background:#d4edda;color:#155724;border:1px solid #c3e6cb;border-radius:7px;padding:11px 15px;margin-bottom:14px;font-size:13px;}
.flash-err{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;border-radius:7px;padding:11px 15px;margin-bottom:14px;font-size:13px;}
/* KPI bar */
.aif-kpi{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;}
.aif-kpi-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px 18px;flex:1;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.aif-kpi-card .n{font-size:1.6rem;font-weight:800;color:#002F70;line-height:1.1;}
.aif-kpi-card .l{font-size:12px;font-weight:700;color:#6c757d;text-transform:uppercase;letter-spacing:.4px;margin-top:3px;}
.aif-kpi-card.ok   .n{color:#28a745;}
.aif-kpi-card.warn .n{color:#fd7e14;}
.aif-kpi-card.crit .n{color:#dc3545;}
/* Table */
.aif-wrap{width:100%;overflow:hidden;}
.aif-tbl{width:100%;table-layout:fixed;border-collapse:collapse;font-size:13px;}
.aif-tbl thead tr{background:#002F70;}
.aif-tbl thead th{padding:10px 6px;text-align:center;font-size:11px;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:.3px;white-space:normal;word-wrap:break-word;line-height:1.35;vertical-align:middle;}
.aif-tbl tbody tr{border-bottom:1px solid #f1f5f9;transition:background .1s;}
.aif-tbl tbody tr:hover{background:#eff6ff;}
.aif-tbl tbody td{padding:9px 6px;color:#1e293b;vertical-align:middle;text-align:center;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px;}
.aif-tbl tbody td.bold{font-weight:700;color:#002F70;}
.status-pill{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap;}
.var-zero{color:#6c757d;} .var-pos{color:#28a745;font-weight:700;} .var-neg{color:#dc3545;font-weight:700;}
.btn-edit{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;background:var(--blue);color:#fff;border:none;border-radius:5px;font-size:11px;font-weight:600;cursor:pointer;}
.btn-edit:hover{background:#001F4F;}
/* Edit Modal */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9000;align-items:center;justify-content:center;}
.modal-overlay.show{display:flex;}
.modal-box{background:#fff;border-radius:12px;padding:26px;width:460px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.2);}
.modal-box h3{margin:0 0 14px;font-size:15px;color:var(--blue);}
.form-group{margin-bottom:13px;}
.form-group label{display:block;font-size:12px;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;}
.form-group input,.form-group textarea{width:100%;padding:8px 10px;border:1px solid #dee2e6;border-radius:6px;font-size:13px;box-sizing:border-box;}
.form-group input:focus,.form-group textarea:focus{outline:none;border-color:var(--blue);}
.modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:14px;}
.info-note{background:#e8f4fd;border-left:3px solid var(--blue);padding:9px 13px;border-radius:5px;font-size:12px;color:#1e4080;margin-bottom:13px;}
</style>

<div class="page-head">
  <div>
    <h1 class="h1">Fuel Inventory Oversight</h1>
    <div class="sub">17-Tanker Overview &middot; Today: <?= date('F d, Y') ?></div>
  </div>
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-left:auto;">
    <?php
    $export_table_id       = 'adminFuelInvTable';
    $export_filename       = 'admin_fuel_inventory_' . date('Ymd');
    $export_title          = 'Fuel Inventory Oversight';
    $export_rows_select_id = 'adminFuelRowsLimit';
    $export_default_rows   = 20;
    $export_back_url       = 'admin_dashboard.php';
    require __DIR__ . '/../partials/export_buttons.php';
    ?>
  </div>
</div>

<?php if ($flash_ok): ?><div class="flash-ok"><?= htmlspecialchars($flash_ok) ?></div><?php endif; ?>
<?php if ($flash_err): ?><div class="flash-err"><?= htmlspecialchars($flash_err) ?></div><?php endif; ?>




<!-- KPI Cards -->
<div class="aif-kpi">
    <div class="aif-kpi-card"><div class="n">17</div><div class="l">Total Tankers</div></div>
    <div class="aif-kpi-card ok"><div class="n"><?= $cnt_avail ?></div><div class="l">Available</div></div>
    <div class="aif-kpi-card warn"><div class="n"><?= $cnt_low ?></div><div class="l">Low Level</div></div>
    <div class="aif-kpi-card crit"><div class="n"><?= $cnt_crit ?></div><div class="l">Critical / Empty</div></div>
    <div class="aif-kpi-card"><div class="n"><?= number_format(array_sum(array_column($rows,'sales')), 0) ?> L</div><div class="l">Today's Sales</div></div>
    <div class="aif-kpi-card ok"><div class="n">&#8369;<?= number_format(array_sum(array_column($rows,'revenue')), 0) ?></div><div class="l">Today's Revenue</div></div>
</div>

<!-- Table -->
<div style="background:#fff;border:1px solid #e2e8f0;border-radius:11px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.05);margin-bottom:20px;">
  <div class="aif-wrap">
    <table class="aif-tbl" id="adminFuelInvTable">
      <colgroup>
        <col style="width:3%"><col style="width:7%"><col style="width:8%">
        <col style="width:5%"><col style="width:5%"><col style="width:5%">
        <col style="width:5%"><col style="width:5%"><col style="width:5%">
        <col style="width:6%"><col style="width:5%"><col style="width:5%">
        <col style="width:6%"><col style="width:6%"><col style="width:5%">
        <col style="width:5%"><col style="width:7%"><col style="width:7%">
      </colgroup>
      <thead>
        <tr>
          <th>#</th><th>Fuel Type</th><th>Tanker Ref.</th>
          <th>Capacity</th><th>Beg. Balance</th><th>Purchases</th>
          <th>Total Avail.</th><th>Sales (L)</th><th>Calibration</th>
          <th>Ending (Sys)</th><th>Actual Dip</th><th>Variance</th>
          <th>Current Level</th><th>Status</th><th>Price/L</th>
          <th>Revenue</th><th>Timestamp</th><th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="18" style="text-align:center;padding:32px;color:#6c757d;">No fuel inventory data available.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r):
            $var     = $r['variance'];
            $var_cls = abs($var) < 0.01 ? 'var-zero' : ($var >= 0 ? 'var-pos' : 'var-neg');
            $var_str = abs($var) < 0.01 ? '0.00' : (($var > 0 ? '+' : '') . number_format($var, 2));
            $ts_str  = $r['timestamp'] ? date('M d, Y h:i A', strtotime($r['timestamp'])) : '—';
            $fill    = min(100, round($r['fill_pct'], 0));
        ?>
        <tr>
          <td class="bold"><?= $r['tanker_num'] ?></td>
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
          <td><span class="status-pill" style="background:<?= $r['status_color'] ?>18;color:<?= $r['status_color'] ?>;border:1px solid <?= $r['status_color'] ?>40;"><?= htmlspecialchars($r['status']) ?></span></td>
          <td>&#8369;<?= number_format($r['price'], 2) ?></td>
          <td class="bold">&#8369;<?= number_format($r['revenue'], 2) ?></td>
          <td style="color:#64748b;font-size:11px;"><?= $ts_str ?></td>
          <td>
            <?php if ($r['fuel_id'] > 0): ?>
            <button class="btn-edit" onclick="openEdit(<?= $r['fuel_id'] ?>, '<?= htmlspecialchars($r['label'], ENT_QUOTES) ?>', <?= $r['current_level'] ?>, <?= $r['capacity'] ?>)">Edit</button>
            <?php else: ?>
            <span style="color:#aaa;font-size:11px;">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div id="adminFuelInvPagination" style="padding:8px 16px;"></div>
</div>

<!-- Edit Modal -->
<div class="modal-overlay" id="editModal">
  <div class="modal-box">
    <h3>Edit Fuel Level — Discrepancy Correction</h3>
    <div class="info-note">Use this only to correct discrepancies. All changes are logged for audit.</div>
    <form method="POST">
      <input type="hidden" name="action" value="edit_level">
      <input type="hidden" name="fuel_id" id="editFuelId">
      <div id="editFuelInfo" style="background:#f8f9fa;border-radius:6px;padding:10px 12px;margin-bottom:12px;font-size:13px;"></div>
      <div class="form-group">
        <label>New Current Level (Liters)</label>
        <input type="number" name="new_level" id="editNewLevel" min="0" step="0.01" required>
        <div id="editCapNote" style="font-size:11px;color:var(--gray);margin-top:3px;"></div>
      </div>
      <div class="form-group">
        <label>Reason / Note <span style="color:var(--red);">*</span></label>
        <textarea name="note" rows="2" placeholder="e.g. Corrected due to encoding error..." required></textarea>
      </div>
      <div class="modal-actions">
        <button type="button" onclick="document.getElementById('editModal').classList.remove('show')" style="padding:8px 18px;background:#6c757d;color:#fff;border:none;border-radius:5px;cursor:pointer;">Cancel</button>
        <button type="submit" class="btn-edit">Save Correction</button>
      </div>
    </form>
  </div>
</div>

<script>
function openEdit(id, name, current, cap) {
    document.getElementById('editFuelId').value = id;
    document.getElementById('editNewLevel').value = current;
    document.getElementById('editNewLevel').max = cap;
    document.getElementById('editFuelInfo').innerHTML = '<strong>' + name + '</strong> &nbsp;|&nbsp; Current: <strong>' + Number(current).toLocaleString() + ' L</strong> &nbsp;|&nbsp; Capacity: ' + Number(cap).toLocaleString() + ' L';
    document.getElementById('editCapNote').textContent = 'Max allowed: ' + Number(cap).toLocaleString() + ' L';
    document.getElementById('editModal').classList.add('show');
}
document.getElementById('editModal').addEventListener('click', function(e){ if(e.target===this) this.classList.remove('show'); });
document.addEventListener('DOMContentLoaded', function() {
    if (typeof setupTablePagination === 'function')
        setupTablePagination('adminFuelInvTable','adminFuelRowsLimit','adminFuelInvPagination',20);
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
