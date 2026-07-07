<?php
$page_id = 'admin_stock_requests';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();
$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();
if (!in_array($role, ['admin','superadmin'])) { header('Location: dashboard.php'); exit; }
if ($station_id <= 0 && $role === 'admin') { render_no_station_page('admin_dashboard.php'); }

// Fetch stock requests
$merch_reqs = [];
$fuel_reqs = [];
try {  // Merchandise requests  $stmt1 = $pdo->prepare("  SELECT sr.*, COALESCE(u.name, 'Unknown Staff') AS staff_name, m.name AS manager_name, po.po_number, po.status AS po_status  FROM stock_requests sr  LEFT JOIN users u ON sr.staff_id = u.id  LEFT JOIN users m ON sr.manager_id = m.id  LEFT JOIN purchase_orders po ON po.request_id = sr.id AND po.type = 'merch'  WHERE sr.station_id = ?  ORDER BY sr.created_at DESC  ");  $stmt1->execute([$station_id]);  $merch_reqs = $stmt1->fetchAll(PDO::FETCH_ASSOC);  // Fuel requests  $stmt2 = $pdo->prepare("  SELECT fsr.*, COALESCE(u.name, 'Unknown Staff') AS staff_name, m.name AS manager_name  FROM fuel_stock_requests fsr  LEFT JOIN users u ON fsr.staff_id = u.id  LEFT JOIN users m ON fsr.manager_id = m.id  WHERE fsr.station_id = ?  ORDER BY fsr.created_at DESC  ");  $stmt2->execute([$station_id]);  $fuel_reqs = $stmt2->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$tot_pending = count(array_filter($merch_reqs, fn($r) => strtolower($r['status'])==='pending')) + count(array_filter($fuel_reqs, fn($r) => strtolower($r['status'])==='pending'));

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
table.reqs{width:100%;border-collapse:collapse;font-size:13px;table-layout:fixed;}
table.reqs th{background:#002F70;color:#fff;padding:10px 11px;text-align:center;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;}
table.reqs td{padding:9px 11px;border-bottom:1px solid #f1f5f9;vertical-align:middle;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-align:center;}
table.reqs tbody tr:hover td{background:#eff6ff;}
.badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;}
.badge-pending{background:#fff3cd;color:#856404;}
.badge-forwarded{background:#dbeafe;color:#1e40af;}
.badge-approved{background:#dcfce7;color:#166534;}
.badge-rejected{background:#fee2e2;color:#991b1b;}
.badge-other{background:#e2e8f0;color:#334155;}
</style>

<div class="page-head">  <div>  <h1 class="h1"><i class="fas fa-eye"></i> Stock Request Monitoring</h1>  <div class="sub">Monitor staff requests, manager validation, and generated purchase orders. Read-only control dashboard.</div>  </div>  <div class="header-actions">  <button onclick="location.reload()" class="btn ghost"><i class="fas fa-sync-alt"></i> Refresh</button>  </div>
</div>

<div class="kpi-grid">  <div class="kpi-card"><div class="kv" style="color:var(--blue)"><?= count($merch_reqs)+count($fuel_reqs) ?></div><div class="kl">Total Requests</div></div>  <div class="kpi-card"><div class="kv" style="color:var(--orange)"><?= $tot_pending ?></div><div class="kl">Pending Processing</div></div>  <div class="kpi-card"><div class="kv" style="color:var(--green)"><?= count(array_filter($merch_reqs, fn($r)=>in_array($r['status'],['Approved','Forwarded to Admin']))) + count(array_filter($fuel_reqs, fn($r)=>$r['status']==='Approved')) ?></div><div class="kl">Validated / Approved</div></div>
</div>

<div class="main-tab-nav">  <button class="tab-btn active" id="tabMerchBtn" onclick="switchTab('merch')"><i class="fas fa-boxes"></i> Merchandise Requests (<?= count($merch_reqs) ?>)</button>  <button class="tab-btn" id="tabFuelBtn" onclick="switchTab('fuel')"><i class="fas fa-gas-pump"></i> Fuel Requests (<?= count($fuel_reqs) ?>)</button>
</div>

<!-- Merchandise Panel -->
<div class="card" id="panelMerch">  <div class="card-hd">  <div class="card-hd-title"><i class="fas fa-boxes"></i> Merchandise Request Pipeline <span id="vcntMerch" style="font-size:12px;color:var(--gray);font-weight:400;margin-left:6px;"></span></div>  </div>  <div class="card-body">  <div class="toolbar">  <input type="text" id="sqMerch" placeholder="&#128269; Search product, SKU..." oninput="filterMerch()">  <select id="sfMerch" onchange="filterMerch()">  <option value="">All Statuses</option>  <option value="pending">Pending Manager</option>  <option value="forwarded to admin">Forwarded to Admin</option>  <option value="approved">Approved</option>  <option value="rejected">Rejected</option>  </select>  </div>  <div class="table-wrap">  <table class="reqs">  <colgroup>  <col style="width:7%"><col style="width:13%"><col style="width:13%"><col style="width:20%">  <col style="width:9%"><col style="width:9%"><col style="width:13%"><col style="width:16%">  </colgroup>  <thead>  <tr>  <th>ID</th><th>Date</th><th>Staff</th><th>Product</th><th>Req Qty</th><th>App Qty</th><th>Status</th><th>PO/Notes</th>  </tr>  </thead>  <tbody id="merchB">  <?php if (empty($merch_reqs)): ?>  <tr><td colspan="8" style="text-align:center;padding:32px;color:var(--gray);">No merchandise requests found.</td></tr>  <?php else: foreach ($merch_reqs as $r):  $st = strtolower($r['status']);  $bc = $st==='pending'?'badge-pending':($st==='forwarded to admin'?'badge-forwarded':($st==='approved'?'badge-approved':'badge-rejected'));  ?>  <tr class="merch-row" data-p="<?= strtolower(htmlspecialchars($r['item_name'])) ?>" data-s="<?= strtolower(htmlspecialchars($r['item_sku']??'')) ?>" data-x="<?= $st ?>">  <td style="color:var(--gray);">#<?= $r['id'] ?></td>  <td><?= date('M d, Y H:i', strtotime($r['created_at'])) ?></td>  <td><?= htmlspecialchars($r['staff_name']) ?></td>  <td title="<?= htmlspecialchars($r['item_name']) ?>"><strong><?= htmlspecialchars($r['item_name']) ?></strong></td>  <td><?= (int)$r['requested_quantity'] ?></td>  <td><?= $r['approved_quantity']!==null ? (int)$r['approved_quantity'] : '—' ?></td>  <td><span class="badge <?= $bc ?>"><?= htmlspecialchars($r['status']) ?></span></td>  <td>  <?php if ($r['po_number']): ?>  <strong style="color:var(--blue);"><?= htmlspecialchars($r['po_number']) ?></strong>  <?php elseif ($r['manager_notes']): ?>  <span style="color:var(--gray);font-style:italic;" title="<?= htmlspecialchars($r['manager_notes']) ?>"><?= htmlspecialchars($r['manager_notes']) ?></span>  <?php else: ?>  <span style="color:var(--gray);">&mdash;</span>  <?php endif; ?>  </td>  </tr>  <?php endforeach; endif; ?>  </tbody>  </table>  </div>  </div>
</div>

<!-- Fuel Panel -->
<div class="card" id="panelFuel" style="display:none;">  <div class="card-hd">  <div class="card-hd-title"><i class="fas fa-gas-pump"></i> Fuel Request Pipeline <span id="vcntFuel" style="font-size:12px;color:var(--gray);font-weight:400;margin-left:6px;"></span></div>  </div>  <div class="card-body">  <div class="toolbar">  <input type="text" id="sqFuel" placeholder="&#128269; Search fuel type..." oninput="filterFuel()">  <select id="sfFuel" onchange="filterFuel()">  <option value="">All Statuses</option>  <option value="pending">Pending</option>  <option value="approved">Approved</option>  <option value="rejected">Rejected</option>  </select>  </div>  <div class="table-wrap">  <table class="reqs">  <colgroup>  <col style="width:7%"><col style="width:13%"><col style="width:13%"><col style="width:20%">  <col style="width:9%"><col style="width:9%"><col style="width:13%"><col style="width:16%">  </colgroup>  <thead>  <tr>  <th>ID</th><th>Date</th><th>Staff</th><th>Fuel Type</th><th>Req Liters</th><th>App Liters</th><th>Status</th><th>Notes</th>  </tr>  </thead>  <tbody id="fuelB">  <?php if (empty($fuel_reqs)): ?>  <tr><td colspan="8" style="text-align:center;padding:32px;color:var(--gray);">No fuel requests found.</td></tr>  <?php else: foreach ($fuel_reqs as $r):  $st = strtolower($r['status']);  $bc = $st==='pending'?'badge-pending':($st==='approved'?'badge-approved':'badge-rejected');  ?>  <tr class="fuel-row" data-f="<?= strtolower(htmlspecialchars($r['fuel_type'])) ?>" data-x="<?= $st ?>">  <td style="color:var(--gray);">#<?= $r['id'] ?></td>  <td><?= date('M d, Y H:i', strtotime($r['created_at'])) ?></td>  <td><?= htmlspecialchars($r['staff_name']) ?></td>  <td><strong><?= htmlspecialchars($r['fuel_type']) ?></strong></td>  <td><?= number_format((float)$r['requested_liters'],2) ?></td>  <td><?= $r['approved_liters']!==null ? number_format((float)$r['approved_liters'],2) : '—' ?></td>  <td><span class="badge <?= $bc ?>"><?= htmlspecialchars($r['status']) ?></span></td>  <td>  <span style="color:var(--gray);font-style:italic;" title="<?= htmlspecialchars($r['manager_notes'] ?: $r['remarks'] ?: '') ?>">  <?= htmlspecialchars($r['manager_notes'] ?: $r['remarks'] ?: '—') ?>  </span>  </td>  </tr>  <?php endforeach; endif; ?>  </tbody>  </table>  </div>  </div>
</div>

<script>
function switchTab(t) {  document.getElementById('panelMerch').style.display = t==='merch'?'block':'none';  document.getElementById('panelFuel').style.display = t==='fuel'?'block':'none';  document.getElementById('tabMerchBtn').classList.toggle('active', t==='merch');  document.getElementById('tabFuelBtn').classList.toggle('active', t==='fuel');
}
function filterMerch() {  var q = document.getElementById('sqMerch').value.toLowerCase();  var s = document.getElementById('sfMerch').value;  var rows = document.querySelectorAll('#merchB .merch-row'), v=0;  rows.forEach(function(r) {  var show = (!q || r.dataset.p.includes(q) || r.dataset.s.includes(q)) && (!s || r.dataset.x === s);  r.style.display = show ? '' : 'none'; if(show) v++;  });  document.getElementById('vcntMerch').textContent = '('+v+' shown)';
}
function filterFuel() {  var q = document.getElementById('sqFuel').value.toLowerCase();  var s = document.getElementById('sfFuel').value;  var rows = document.querySelectorAll('#fuelB .fuel-row'), v=0;  rows.forEach(function(r) {  var show = (!q || r.dataset.f.includes(q)) && (!s || r.dataset.x === s);  r.style.display = show ? '' : 'none'; if(show) v++;  });  document.getElementById('vcntFuel').textContent = '('+v+' shown)';
}
document.addEventListener('DOMContentLoaded', function() {  filterMerch();  filterFuel();
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
