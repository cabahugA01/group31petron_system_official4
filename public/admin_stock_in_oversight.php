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

// Fetch Stock-In Records
$merch_in = [];
$fuel_in = [];
try {
    // Merchandise Stock-In
    $stmt1 = $pdo->prepare("
        SELECT msi.*, u.name AS encoded_by_name
        FROM merchandise_stock_in msi
        LEFT JOIN users u ON msi.encoded_by = u.id
        WHERE msi.station_id = ?
        ORDER BY msi.encoded_at DESC
    ");
    $stmt1->execute([$station_id]);
    $merch_in = $stmt1->fetchAll(PDO::FETCH_ASSOC);

    // Fuel Stock-In
    $stmt2 = $pdo->prepare("
        SELECT fsi.*, u.name AS encoded_by_name
        FROM fuel_stock_in fsi
        LEFT JOIN users u ON fsi.encoded_by = u.id
        WHERE fsi.station_id = ?
        ORDER BY fsi.encoded_at DESC
    ");
    $stmt2->execute([$station_id]);
    $fuel_in = $stmt2->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Count discrepancies
$merch_disc = count(array_filter($merch_in, fn($r) => $r['qty_variance'] != 0 || $r['condition_flag'] !== 'Good'));
$fuel_disc = count(array_filter($fuel_in, fn($r) => $r['qty_variance'] != 0 || $r['condition_flag'] !== 'Good'));

include __DIR__ . '/../partials/header.php';
?>
<style>
:root{--blue:#002F6C;--red:#dc3545;--orange:#fd7e14;--green:#28a745;--gray:#6c757d;}
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:20px;}
.kpi-card{background:#fff;border-radius:10px;padding:16px 18px;border:1px solid #e2e8f0;box-shadow:0 1px 3px rgba(0,0,0,.06);}
.kpi-card .kv{font-size:26px;font-weight:800;line-height:1;} .kpi-card .kl{font-size:12px;color:var(--gray);margin-top:4px;}
.main-tab-nav{display:flex;border-bottom:2px solid #e2e8f0;margin-bottom:20px;}
.tab-btn{padding:10px 22px;background:none;border:none;border-bottom:3px solid transparent;font-size:14px;font-weight:600;color:var(--gray);cursor:pointer;margin-bottom:-2px;transition:all .15s;}
.tab-btn.active{color:var(--blue);border-bottom-color:var(--blue);}
.tab-btn:hover{color:var(--blue);}
.card{background:#fff;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.07);margin-bottom:20px;overflow:hidden;}
.card-hd{padding:13px 18px;border-bottom:1px solid #e9ecef;display:flex;align-items:center;justify-content:space-between;}
.card-hd-title{font-size:14px;font-weight:700;color:var(--blue);display:flex;align-items:center;gap:8px;}
.card-body{padding:16px 18px;}
.toolbar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:14px;}
.toolbar input,.toolbar select{padding:7px 10px;border:1px solid #dee2e6;border-radius:6px;font-size:13px;}
.toolbar input:focus,.toolbar select:focus{border-color:var(--blue);outline:none;}
.table-wrap{overflow-x:hidden;}
table.stockin{width:100%;border-collapse:collapse;font-size:13px;table-layout:fixed;}
table.stockin th{background:#002F6C;color:#fff;padding:10px 11px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;}
table.stockin td{padding:9px 11px;border-bottom:1px solid #f1f5f9;vertical-align:middle;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
table.stockin tbody tr:hover td{background:#f0f4ff;}
.badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;}
.badge-good{background:#dcfce7;color:#166534;}
.badge-damaged{background:#fee2e2;color:#991b1b;}
.badge-short{background:#fef9c3;color:#92400e;}
.badge-excess{background:#e0f2fe;color:#0369a1;}
.text-danger{color:var(--red);font-weight:700;}
.text-success{color:var(--green);font-weight:700;}
.text-warning{color:var(--orange);font-weight:700;}
.row-issue td{background:#fff5f5;}
</style>

<div class="page-head">
  <div>
    <h1 class="h1"><i class="fas fa-dolly-flatbed"></i> Stock-In Oversight</h1>
    <div class="sub">Cross-check actual deliveries committed to inventory against approved Purchase Orders.</div>
  </div>
  <div class="header-actions">
    <button onclick="location.reload()" class="btn ghost"><i class="fas fa-sync-alt"></i> Refresh</button>
  </div>
</div>

<div class="kpi-grid">
  <div class="kpi-card"><div class="kv" style="color:var(--blue)"><?= count($merch_in)+count($fuel_in) ?></div><div class="kl">Total Deliveries</div></div>
  <div class="kpi-card"><div class="kv" style="color:var(--red)"><?= $merch_disc+$fuel_disc ?></div><div class="kl">Flagged Discrepancies</div></div>
  <div class="kpi-card"><div class="kv" style="color:var(--green)"><?= count($merch_in) ?></div><div class="kl">Merchandise Batches</div></div>
  <div class="kpi-card"><div class="kv" style="color:#6366f1"><?= count($fuel_in) ?></div><div class="kl">Fuel Batches</div></div>
</div>

<div class="main-tab-nav">
  <button class="tab-btn active" id="tabMerchBtn" onclick="switchTab('merch')"><i class="fas fa-boxes"></i> Merchandise Stock-In (<?= count($merch_in) ?>)</button>
  <button class="tab-btn" id="tabFuelBtn" onclick="switchTab('fuel')"><i class="fas fa-gas-pump"></i> Fuel Stock-In (<?= count($fuel_in) ?>)</button>
</div>

<!-- Merchandise Panel -->
<div class="card" id="panelMerch">
  <div class="card-hd">
    <div class="card-hd-title"><i class="fas fa-boxes"></i> Merchandise Actual Stock-In Log <span id="vcntMerch" style="font-size:12px;color:var(--gray);font-weight:400;margin-left:6px;"></span></div>
  </div>
  <div class="card-body">
    <div class="toolbar">
      <input type="text" id="sqMerch" placeholder="&#128269; Search product, PO..." oninput="filterMerch()">
      <select id="sfMerch" onchange="filterMerch()">
        <option value="">All Conditions</option>
        <option value="good">Good</option>
        <option value="damaged">Damaged</option>
        <option value="short">Short</option>
        <option value="excess">Excess</option>
      </select>
    </div>
    <div class="table-wrap">
      <table class="stockin">
        <colgroup>
          <col style="width:13%"><col style="width:13%"><col style="width:11%"><col style="width:18%">
          <col style="width:7%"><col style="width:7%"><col style="width:7%"><col style="width:9%">
          <col style="width:15%">
        </colgroup>
        <thead>
          <tr>
            <th>Batch Ref</th><th>Date</th><th>PO Number</th><th>Product</th><th>Ordered</th><th>Received</th><th>Variance</th><th>Condition</th><th>Encoded By</th>
          </tr>
        </thead>
        <tbody id="merchB">
        <?php if (empty($merch_in)): ?>
          <tr><td colspan="9" style="text-align:center;padding:32px;color:var(--gray);">No merchandise stock-in records found.</td></tr>
        <?php else: foreach ($merch_in as $r):
          $cond = strtolower($r['condition_flag']);
          $bc = $cond==='good'?'badge-good':($cond==='damaged'?'badge-damaged':($cond==='short'?'badge-short':'badge-excess'));
          $has_issue = ($r['qty_variance'] != 0 || $r['condition_flag'] !== 'Good');
        ?>
          <tr class="merch-row <?= $has_issue?'row-issue':'' ?>" data-p="<?= strtolower(htmlspecialchars($r['product_name'])) ?>" data-po="<?= strtolower(htmlspecialchars($r['po_number'])) ?>" data-c="<?= $cond ?>">
            <td style="font-family:monospace;font-size:11px;" title="<?= htmlspecialchars($r['batch_ref']) ?>"><?= htmlspecialchars($r['batch_ref']) ?></td>
            <td><?= date('M d, Y H:i', strtotime($r['encoded_at'])) ?></td>
            <td style="color:var(--blue);font-weight:600;"><?= htmlspecialchars($r['po_number']) ?></td>
            <td title="<?= htmlspecialchars($r['product_name']) ?>"><strong><?= htmlspecialchars($r['product_name']) ?></strong></td>
            <td><?= (int)$r['qty_ordered'] ?></td>
            <td><?= (int)$r['qty_received'] ?></td>
            <td class="<?= $r['qty_variance']<0?'text-danger':($r['qty_variance']>0?'text-success':'') ?>"><?= $r['qty_variance'] > 0 ? '+'.$r['qty_variance'] : $r['qty_variance'] ?></td>
            <td><span class="badge <?= $bc ?>"><?= htmlspecialchars($r['condition_flag']) ?></span></td>
            <td><?= htmlspecialchars($r['encoded_by_name']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Fuel Panel -->
<div class="card" id="panelFuel" style="display:none;">
  <div class="card-hd">
    <div class="card-hd-title"><i class="fas fa-gas-pump"></i> Fuel Actual Stock-In Log <span id="vcntFuel" style="font-size:12px;color:var(--gray);font-weight:400;margin-left:6px;"></span></div>
  </div>
  <div class="card-body">
    <div class="toolbar">
      <input type="text" id="sqFuel" placeholder="&#128269; Search fuel type, invoice..." oninput="filterFuel()">
      <select id="sfFuel" onchange="filterFuel()">
        <option value="">All Conditions</option>
        <option value="good">Good</option>
        <option value="damaged">Damaged</option>
        <option value="short">Short</option>
        <option value="excess">Excess</option>
      </select>
    </div>
    <div class="table-wrap">
      <table class="stockin">
        <colgroup>
          <col style="width:13%"><col style="width:13%"><col style="width:11%"><col style="width:18%">
          <col style="width:8%"><col style="width:8%"><col style="width:8%"><col style="width:9%">
          <col style="width:12%">
        </colgroup>
        <thead>
          <tr>
            <th>Batch Ref</th><th>Date</th><th>Invoice / DR</th><th>Fuel Type</th><th>Expected</th><th>Received</th><th>Variance</th><th>Condition</th><th>Encoded By</th>
          </tr>
        </thead>
        <tbody id="fuelB">
        <?php if (empty($fuel_in)): ?>
          <tr><td colspan="9" style="text-align:center;padding:32px;color:var(--gray);">No fuel stock-in records found.</td></tr>
        <?php else: foreach ($fuel_in as $r):
          $cond = strtolower($r['condition_flag']);
          $bc = $cond==='good'?'badge-good':($cond==='damaged'?'badge-damaged':($cond==='short'?'badge-short':'badge-excess'));
          $has_issue = ($r['qty_variance'] != 0 || $r['condition_flag'] !== 'Good');
        ?>
          <tr class="fuel-row <?= $has_issue?'row-issue':'' ?>" data-f="<?= strtolower(htmlspecialchars($r['fuel_type'])) ?>" data-i="<?= strtolower(htmlspecialchars($r['invoice_no'])) ?>" data-c="<?= $cond ?>">
            <td style="font-family:monospace;font-size:11px;" title="<?= htmlspecialchars($r['batch_ref']) ?>"><?= htmlspecialchars($r['batch_ref']) ?></td>
            <td><?= date('M d, Y H:i', strtotime($r['encoded_at'])) ?></td>
            <td style="color:var(--blue);font-weight:600;"><?= htmlspecialchars($r['invoice_no']) ?></td>
            <td><strong><?= htmlspecialchars($r['fuel_type']) ?></strong></td>
            <td><?= number_format((float)$r['qty_expected'],2) ?> L</td>
            <td><?= number_format((float)$r['qty_received'],2) ?> L</td>
            <td class="<?= $r['qty_variance']<0?'text-danger':($r['qty_variance']>0?'text-success':'') ?>"><?= $r['qty_variance'] > 0 ? '+'.number_format($r['qty_variance'],2) : number_format($r['qty_variance'],2) ?> L</td>
            <td><span class="badge <?= $bc ?>"><?= htmlspecialchars($r['condition_flag']) ?></span></td>
            <td><?= htmlspecialchars($r['encoded_by_name']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
function switchTab(t) {
    document.getElementById('panelMerch').style.display = t==='merch'?'block':'none';
    document.getElementById('panelFuel').style.display = t==='fuel'?'block':'none';
    document.getElementById('tabMerchBtn').classList.toggle('active', t==='merch');
    document.getElementById('tabFuelBtn').classList.toggle('active', t==='fuel');
}
function filterMerch() {
    var q = document.getElementById('sqMerch').value.toLowerCase();
    var c = document.getElementById('sfMerch').value;
    var rows = document.querySelectorAll('#merchB .merch-row'), v=0;
    rows.forEach(function(r) {
        var show = (!q || r.dataset.p.includes(q) || r.dataset.po.includes(q)) && (!c || r.dataset.c === c);
        r.style.display = show ? '' : 'none'; if(show) v++;
    });
    document.getElementById('vcntMerch').textContent = '('+v+' shown)';
}
function filterFuel() {
    var q = document.getElementById('sqFuel').value.toLowerCase();
    var c = document.getElementById('sfFuel').value;
    var rows = document.querySelectorAll('#fuelB .fuel-row'), v=0;
    rows.forEach(function(r) {
        var show = (!q || r.dataset.f.includes(q) || r.dataset.i.includes(q)) && (!c || r.dataset.c === c);
        r.style.display = show ? '' : 'none'; if(show) v++;
    });
    document.getElementById('vcntFuel').textContent = '('+v+' shown)';
}
document.addEventListener('DOMContentLoaded', function() {
    filterMerch();
    filterFuel();
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
