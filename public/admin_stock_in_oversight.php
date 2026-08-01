<?php
$page_id = 'admin_stock_in';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();
$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();
if (!in_array($role, ['admin','superadmin'])) { header('Location: dashboard.php'); exit; }
if ($station_id <= 0 && $role === 'admin') { render_no_station_page('admin_dashboard.php'); }

// Filters
$start_date = $_GET['start'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date   = $_GET['end']   ?? date('Y-m-d');
$active_tab = $_GET['tab']   ?? 'merch';

// Fetch Merchandise Stock-In records
$merch_in = [];
try {
    $stmt1 = $pdo->prepare("
        SELECT msi.*, 
               CONCAT(u.first_name, ' ', u.last_name) AS encoded_by_name,
               CONCAT(u_val.first_name, ' ', u_val.last_name) AS validated_by_name,
               po.status AS po_status,
               po.supplier_name,
               ip.max_stock AS max_capacity
        FROM merchandise_stock_in msi
        LEFT JOIN users u ON msi.encoded_by = u.id
        LEFT JOIN purchase_orders po ON msi.po_id = po.id
        LEFT JOIN users u_val ON po.admin_id = u_val.id
        LEFT JOIN inventory_products ip ON msi.product_id = ip.id
        WHERE msi.station_id = ? AND DATE(msi.encoded_at) BETWEEN ? AND ?
        ORDER BY msi.encoded_at DESC
    ");
    $stmt1->execute([$station_id, $start_date, $end_date]);
    $merch_in = $stmt1->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Fetch Fuel Stock-In records (sourced from fuel_deliveries table)
$fuel_in = [];
try {
    $stmt2 = $pdo->prepare("
        SELECT fd.*, 
               CONCAT(u_enc.first_name, ' ', u_enc.last_name) AS encoded_by_name,
               CONCAT(u_val.first_name, ' ', u_val.last_name) AS validated_by_name,
               fi.capacity AS tank_capacity,
               fi.current_level AS tank_current_level,
               fi.price_per_liter AS unit_price,
               fi.latest_calibration AS calibration_adjustment
        FROM fuel_deliveries fd
        LEFT JOIN users u_enc ON fd.received_by = u_enc.id
        LEFT JOIN users u_val ON fd.verified_by = u_val.id
        LEFT JOIN fuel_inventory fi ON fd.fuel_type = fi.fuel_type AND fd.station_id = fi.station_id
        WHERE fd.station_id = ? AND DATE(fd.created_at) BETWEEN ? AND ?
        ORDER BY fd.created_at DESC
    ");
    $stmt2->execute([$station_id, $start_date, $end_date]);
    $fuel_in_raw = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    // Compute running balance after delivery for Fuel per fuel type
    $fuel_balance = [];
    $fuel_in_ordered = array_reverse($fuel_in_raw); // oldest first to compute running balance
    foreach ($fuel_in_ordered as &$r) {
        $ft = $r['fuel_type'];
        if (!isset($fuel_balance[$ft])) {
            // Start from the current level in database as base if not set
            $fuel_balance[$ft] = (float)($r['tank_current_level'] ?? 0);
        }
        // Add quantity received
        $fuel_balance[$ft] += (float)$r['delivery_liters'];
        // Subtract calibration adjustments if any
        if (isset($r['calibration_adjustment'])) {
            $fuel_balance[$ft] -= (float)$r['calibration_adjustment'];
        }
        $r['balance_after'] = $fuel_balance[$ft];
    }
    unset($r);
    $fuel_in = array_reverse($fuel_in_ordered); // Back to newest first
} catch (Exception $e) {}

// Counts for KPIs
$merch_disc = count(array_filter($merch_in, fn($r) => $r['qty_variance'] != 0 || $r['condition_flag'] !== 'Good'));
$fuel_disc = count(array_filter($fuel_in, fn($r) => $r['qty_variance'] != 0 || $r['condition_flag'] !== 'Good'));

include __DIR__ . '/../partials/header.php';
?>
<style>
:root{--blue:#002F70;--red:#dc3545;--orange:#fd7e14;--green:#28a745;--gray:#6c757d;}
/* == PAGE HEADER - matches SuperAdmin int-head standard == */
.int-head { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; margin-top: 0 !important; padding-top: 16px; padding-bottom: 16px; border-bottom: 2px solid #e9ecef; }
.int-head h1{font-size:22px!important;font-weight:700!important;color:var(--petron-blue,#002F70)!important;margin:0!important;text-transform:uppercase!important;display:flex;align-items:center;gap:8px}
.int-head .sub{font-size:13px;color:#666;margin-top:4px;text-transform:none!important}
/* == Outline Buttons - SuperAdmin standard == */
.ato-btn{display:inline-flex;align-items:center;gap:6px;padding:0 16px;height:36px;border:1px solid transparent;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;white-space:nowrap;background:white!important;transition:all .15s}
.ato-btn-refresh{color:#002F70!important;border-color:#002F70!important}.ato-btn-refresh:hover{background:#002F70!important;color:#fff!important}
/* == KPI Cards == */
.kpi-grid{display:flex;gap:14px;margin-bottom:20px;flex-wrap:wrap;}
.kpi-card{flex:1;background:#fff;border-radius:10px;padding:16px 18px;border:1px solid #e2e8f0;box-shadow:0 1px 3px rgba(0,0,0,.06);}
.kpi-card .kv{font-size:24px;font-weight:800;line-height:1;} .kpi-card .kl{font-size:12px;color:var(--gray);margin-top:4px;}
/* == Tabs == */
.main-tab-nav{display:flex;border-bottom:2px solid #e2e8f0;margin-bottom:20px;}
.tab-btn{padding:10px 22px;background:none;border:none;border-bottom:3px solid transparent;font-size:14px;font-weight:600;color:var(--gray);cursor:pointer;margin-bottom:-2px;transition:all .15s;display:inline-flex;align-items:center;gap:6px;}
.tab-btn.active{color:var(--blue);border-bottom-color:var(--blue);}
.tab-btn:hover{color:var(--blue);}
/* == Card == */
.card{background:#fff;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.07);margin-bottom:20px;overflow:hidden;}
.card-hd{padding:13px 18px;border-bottom:1px solid #e9ecef;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;}
.card-hd-title{font-size:14px;font-weight:700;color:var(--blue);display:flex;align-items:center;gap:8px;}
.filter-bar{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:16px;background:#fff;padding:12px 14px;border-radius:10px;border:1px solid #e2e8f0;}
.filter-bar .fg{display:flex;flex-direction:column;gap:4px;}
.filter-bar label{font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;}
.filter-bar input,.filter-bar select{height:36px;padding:0 10px;border:1px solid #cbd5e1;border-radius:7px;font-size:13px;color:#1e293b;background:#fff;outline:none;box-sizing:border-box;}
.filter-bar input:focus,.filter-bar select:focus{border-color:#002F70;box-shadow:0 0 0 3px rgba(0,47,112,.1)}
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;}
/* == Table - SuperAdmin ato-table standard == */
table.stockin{width:100%;border-collapse:collapse;font-size:11px;}
table.stockin th{background:#002F70;color:#fff;padding:9px 10px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;border-bottom:2px solid #001a3d;}
table.stockin td{padding:9px 10px;border-bottom:1px solid #f1f5f9;vertical-align:middle;white-space:nowrap;background:#fff;font-size:11px;}
table.stockin tbody tr:hover td{background:#eff6ff;}
/* == Badges == */
.badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;}
.badge-good{background:#dcfce7;color:#166534;}
.badge-damaged{background:#fee2e2;color:#991b1b;}
.badge-short{background:#fef9c3;color:#92400e;}
.badge-excess{background:#e0f2fe;color:#0369a1;}
.badge-pending{background:#fef9c3;color:#854d0e;}
.badge-approved{background:#e0f2fe;color:#0369a1;}
.badge-completed{background:#dcfce7;color:#166534;}
.text-danger{color:var(--red);font-weight:700;}
.text-success{color:var(--green);font-weight:700;}
.text-warning{color:var(--orange);font-weight:700;}
.row-issue td{background:#fff5f5;}
.txn-id{font-family:monospace;font-size:11px;color:var(--blue);font-weight:600;}
.num{text-align:right;}
.cap-ok{color:var(--green);font-weight:700;}
.cap-warn{color:var(--red);font-weight:700;}
</style>

<div class="int-head">
  <div>
    <h1><i class="fas fa-dolly-flatbed"></i> Stock-In Oversight &amp; History</h1>
    <div class="sub">Cross-check actual deliveries committed to inventory, track capacity, and review validation status.</div>
  </div>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <button onclick="location.reload()" class="ato-btn ato-btn-refresh"><i class="fas fa-sync-alt"></i> Refresh</button>
  </div>
</div>

<div class="kpi-grid">
  <div class="kpi-card"><div class="kv" style="color:var(--blue)"><?= count($merch_in)+count($fuel_in) ?></div><div class="kl">Total Deliveries</div></div>
  <div class="kpi-card"><div class="kv" style="color:var(--red)"><?= $merch_disc+$fuel_disc ?></div><div class="kl">Flagged Discrepancies</div></div>
  <div class="kpi-card"><div class="kv" style="color:var(--green)"><?= count($merch_in) ?></div><div class="kl">Merchandise Batches</div></div>
  <div class="kpi-card"><div class="kv" style="color:#6366f1"><?= count($fuel_in) ?></div><div class="kl">Fuel Deliveries</div></div>
</div>

<div class="main-tab-nav">
  <button class="tab-btn <?= $active_tab==='merch'?'active':'' ?>" onclick="switchTab('merch')"><i class="fas fa-boxes"></i> Merchandise Stock-In History (<?= count($merch_in) ?>)</button>
  <button class="tab-btn <?= $active_tab==='fuel'?'active':'' ?>" onclick="switchTab('fuel')"><i class="fas fa-gas-pump"></i> Fuel Stock-In History (<?= count($fuel_in) ?>)</button>
</div>

<!-- ══ MERCHANDISE PANEL ══ -->
<div class="card" id="panelMerch" <?= $active_tab!=='merch'?'style="display:none;"':'' ?>>
  <div class="card-hd">
    <div class="card-hd-title"><i class="fas fa-boxes"></i> Merchandise Stock-In Log</div>
  </div>
  <div class="card-body">
    <!-- Filter bar -->
    <form method="GET" class="filter-bar">
      <input type="hidden" name="tab" value="merch">
      <div class="fg"><label>Start Date</label><input type="date" name="start" value="<?= htmlspecialchars($start_date) ?>"></div>
      <div class="fg"><label>End Date</label><input type="date" name="end" value="<?= htmlspecialchars($end_date) ?>"></div>
      <div style="margin-top:auto;"><button type="submit" class="ato-btn ato-btn-refresh"><i class="fas fa-filter"></i> Apply Dates</button></div>
    </form>

    <div class="table-wrap">
      <table class="stockin" id="merchStockTable">
        <thead>
          <tr>
            <th>Transaction ID</th>
            <th>Date &amp; Time</th>
            <th>Product Name / SKU</th>
            <th class="num">Qty Received</th>
            <th class="num">Damaged/Exp.</th>
            <th class="num">Balance After</th>
            <th>Capacity Check</th>
            <th>Supplier</th>
            <th class="num">Unit Price</th>
            <th class="num">Total Value</th>
            <th>Encoded By</th>
            <th>Validated By</th>
            <th>Status</th>
            <th>Remarks</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($merch_in)): ?>
          <tr><td colspan="14" style="text-align:center;padding:32px;color:var(--gray);">No merchandise stock-in records found.</td></tr>
        <?php else: foreach ($merch_in as $r):
          $cond = strtolower($r['condition_flag']);
          $badge_cond = $cond==='good'?'badge-good':($cond==='damaged'?'badge-damaged':($cond==='short'?'badge-short':'badge-excess'));
          
          // Damaged/Expired count
          $damaged_count = ($cond === 'damaged') ? abs($r['qty_variance']) : 0;
          
          // Capacity Check
          $max_cap = (int)($r['max_capacity'] ?? 0);
          $bal_after = (int)$r['balance_after'];
          $cap_status = "OK";
          $cap_class = "cap-ok";
          if ($max_cap > 0 && $bal_after > $max_cap) {
              $cap_status = "Overlimit (Max: $max_cap)";
              $cap_class = "cap-warn";
          } elseif ($max_cap > 0) {
              $cap_status = "$bal_after / $max_cap";
          }
          
          // Validation Status Map
          $po_st = strtolower($r['po_status'] ?? '');
          $status_label = "Completed";
          $status_cls = "badge-completed";
          if ($po_st === 'pending') { $status_label = "Pending"; $status_cls = "badge-pending"; }
          elseif ($po_st === 'approved') { $status_label = "Approved"; $status_cls = "badge-approved"; }
        ?>
          <tr class="<?= $cond!=='good'?'row-issue':'' ?>">
            <td><span class="txn-id">MSI-<?= htmlspecialchars($r['id']) ?></span></td>
            <td><?= date('M d, Y H:i', strtotime($r['encoded_at'])) ?></td>
            <td>
              <strong><?= htmlspecialchars($r['product_name']) ?></strong>
              <?php if(!empty($r['sku'])): ?><br><span style="font-family:monospace;font-size:10px;background:#e8f4fd;color:#002F70;padding:1px 5px;border-radius:3px;"><?= htmlspecialchars($r['sku']) ?></span><?php endif; ?>
            </td>
            <td class="num" style="font-weight:700;"><?= (int)$r['qty_received'] ?></td>
            <td class="num text-danger"><?= $damaged_count > 0 ? $damaged_count : '—' ?></td>
            <td class="num" style="font-weight:700;color:var(--blue);"><?= $bal_after ?></td>
            <td><span class="<?= $cap_class ?>"><?= $cap_status ?></span></td>
            <td><?= htmlspecialchars($r['supplier_name'] ?? 'Petron Corp') ?></td>
            <td class="num">&#8369;<?= number_format((float)$r['unit_price'], 2) ?></td>
            <td class="num" style="font-weight:700;">&#8369;<?= number_format((float)$r['total_value'], 2) ?></td>
            <td><?= htmlspecialchars($r['encoded_by_name'] ?? 'Staff') ?></td>
            <td><?= htmlspecialchars($r['validated_by_name'] ?? '—') ?></td>
            <td><span class="badge <?= $status_cls ?>"><?= $status_label ?></span></td>
            <td>
              <span class="badge <?= $badge_cond ?>"><?= htmlspecialchars($r['condition_flag']) ?></span>
              <?php if(!empty($r['remarks'])): ?><span style="display:block;font-size:11px;color:var(--gray);margin-top:2px;"><?= htmlspecialchars($r['remarks']) ?></span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div id="merchPagination" style="margin-top:10px;"></div>
  </div>
</div>

<!-- ══ FUEL PANEL ══ -->
<div class="card" id="panelFuel" <?= $active_tab!=='fuel'?'style="display:none;"':'' ?>>
  <div class="card-hd">
    <div class="card-hd-title"><i class="fas fa-gas-pump"></i> Fuel Stock-In Log</div>
  </div>
  <div class="card-body">
    <!-- Filter bar -->
    <form method="GET" class="filter-bar">
      <input type="hidden" name="tab" value="fuel">
      <div class="fg"><label>Start Date</label><input type="date" name="start" value="<?= htmlspecialchars($start_date) ?>"></div>
      <div class="fg"><label>End Date</label><input type="date" name="end" value="<?= htmlspecialchars($end_date) ?>"></div>
      <div style="margin-top:auto;"><button type="submit" class="ato-btn ato-btn-refresh"><i class="fas fa-filter"></i> Apply Dates</button></div>
    </form>

    <div class="table-wrap">
      <table class="stockin" id="fuelStockTable">
        <thead>
          <tr>
            <th>Transaction ID</th>
            <th>Date &amp; Time</th>
            <th>Fuel Type</th>
            <th class="num">Liters Received</th>
            <th class="num">Calibration Adj.</th>
            <th class="num">Balance After</th>
            <th>Capacity Check</th>
            <th>Supplier</th>
            <th class="num">Unit Price</th>
            <th class="num">Total Value</th>
            <th>Encoded By</th>
            <th>Validated By</th>
            <th>Status</th>
            <th>Remarks</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($fuel_in)): ?>
          <tr><td colspan="14" style="text-align:center;padding:32px;color:var(--gray);">No fuel stock-in records found.</td></tr>
        <?php else: foreach ($fuel_in as $r):
          $cond = strtolower($r['condition_flag'] ?? 'good');
          $badge_cond = $cond==='good'?'badge-good':($cond==='damaged'?'badge-damaged':($cond==='short'?'badge-short':'badge-excess'));
          
          // Calibration Adjustments
          $cal_adj = (float)($r['calibration_adjustment'] ?? 0);
          
          // Capacity Check
          $max_cap = (float)($r['tank_capacity'] ?? 0);
          $bal_after = (float)($r['balance_after'] ?? 0);
          $cap_status = "OK";
          $cap_class = "cap-ok";
          if ($max_cap > 0 && $bal_after > $max_cap) {
              $cap_status = "Overlimit (Max: ".number_format($max_cap,0)."L)";
              $cap_class = "cap-warn";
          } elseif ($max_cap > 0) {
              $cap_status = number_format($bal_after,0)." / ".number_format($max_cap,0)." L";
          }
          
          // Status Map
          $f_status = strtolower($r['status'] ?? '');
          $status_label = "Completed";
          $status_cls = "badge-completed";
          if ($f_status === 'pending') { $status_label = "Pending"; $status_cls = "badge-pending"; }
          elseif ($f_status === 'approved' || $f_status === 'verified') { $status_label = "Approved"; $status_cls = "badge-approved"; }
        ?>
          <tr class="<?= $cond!=='good'?'row-issue':'' ?>">
            <td><span class="txn-id">FSI-<?= htmlspecialchars($r['id']) ?></span></td>
            <td><?= date('M d, Y H:i', strtotime($r['created_at'])) ?></td>
            <td><strong><?= htmlspecialchars($r['fuel_type']) ?></strong></td>
            <td class="num" style="font-weight:700;"><?= number_format((float)$r['delivery_liters'], 2) ?> L</td>
            <td class="num text-danger"><?= $cal_adj > 0 ? '-'.number_format($cal_adj, 2).' L' : '—' ?></td>
            <td class="num" style="font-weight:700;color:var(--blue);"><?= number_format($bal_after, 2) ?> L</td>
            <td><span class="<?= $cap_class ?>"><?= $cap_status ?></span></td>
            <td><?= htmlspecialchars($r['supplier'] ?? 'Petron Corp') ?></td>
            <td class="num">&#8369;<?= number_format((float)($r['unit_price'] ?? 0), 2) ?></td>
            <td class="num" style="font-weight:700;">&#8369;<?= number_format((float)($r['total_value'] ?? 0), 2) ?></td>
            <td><?= htmlspecialchars($r['encoded_by_name'] ?? 'Staff') ?></td>
            <td><?= htmlspecialchars($r['validated_by_name'] ?? '—') ?></td>
            <td><span class="badge <?= $status_cls ?>"><?= $status_label ?></span></td>
            <td>
              <span class="badge <?= $badge_cond ?>"><?= htmlspecialchars($r['condition_flag'] ?? 'Good') ?></span>
              <?php if(!empty($r['notes'])): ?><span style="display:block;font-size:11px;color:var(--gray);margin-top:2px;"><?= htmlspecialchars($r['notes']) ?></span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div id="fuelPagination" style="margin-top:10px;"></div>
  </div>
</div>

<script>
var activeTab = '<?= $active_tab ?>';
function switchTab(t) {
    activeTab = t;
    document.getElementById('panelMerch').style.display = t==='merch'?'block':'none';
    document.getElementById('panelFuel').style.display  = t==='fuel'?'block':'none';
    document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.remove('active'); });
    document.querySelectorAll('.tab-btn')[t==='merch'?0:1].classList.add('active');
    
    if (t==='merch') setupTablePagination('merchStockTable','adminHistRowsLimit','merchPagination',25);
    if (t==='fuel')  setupTablePagination('fuelStockTable','adminHistRowsLimit','fuelPagination',25);
}
document.addEventListener('DOMContentLoaded', function() {
    if (activeTab==='merch') setupTablePagination('merchStockTable','adminHistRowsLimit','merchPagination',25);
    if (activeTab==='fuel')  setupTablePagination('fuelStockTable','adminHistRowsLimit','fuelPagination',25);
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
