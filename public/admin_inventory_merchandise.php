<?php
$page_id = 'admin_inventory_merch';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();
$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();
if (!in_array($role, ['admin','superadmin'])) { header('Location: dashboard.php'); exit; }
if ($station_id <= 0 && $role === 'admin') { render_no_station_page('admin_dashboard.php'); }

$products = [];
$stats = ['total'=>0,'in_stock'=>0,'low_stock'=>0,'out_of_stock'=>0,'total_value'=>0];
try {
    $stmt = $pdo->prepare("
        SELECT id, product_name, sku, category, size, unit_cost, unit_price,
               COALESCE(stock_quantity, stock, 0) AS qty,
               COALESCE(reorder_point, min_stock, 10) AS reorder_point,
               supplier, updated_at
        FROM inventory_products
        WHERE category != 'Fuel' AND LOWER(COALESCE(status,'active')) != 'inactive'
        ORDER BY category, product_name
    ");
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($products as &$p) {
        $qty = (int)$p['qty']; $ro = (int)$p['reorder_point'];
        $stats['total']++;
        $stats['total_value'] += $qty * (float)($p['unit_cost'] ?? 0);
        if ($qty <= 0)      { $p['_st']='out'; $stats['out_of_stock']++; }
        elseif ($qty<=$ro)  { $p['_st']='low'; $stats['low_stock']++; }
        else                { $p['_st']='ok';  $stats['in_stock']++; }
    }
    unset($p);
} catch (Exception $e) {}

$cats = array_unique(array_column($products,'category')); sort($cats);
include __DIR__ . '/../partials/header.php';
?>
<style>
:root{--blue:#002F6C;--red:#dc3545;--orange:#fd7e14;--green:#28a745;--gray:#6c757d;}
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:14px;margin-bottom:20px;}
.kpi-card{background:#fff;border-radius:10px;padding:16px 18px;border:1px solid #e2e8f0;box-shadow:0 1px 3px rgba(0,0,0,.06);}
.kpi-card .kv{font-size:26px;font-weight:800;line-height:1;}
.kpi-card .kl{font-size:12px;color:var(--gray);margin-top:4px;}
.kpi-total .kv{color:var(--blue);} .kpi-ok .kv{color:var(--green);} .kpi-low .kv{color:var(--orange);} .kpi-out .kv{color:var(--red);} .kpi-val .kv{color:#6d28d9;font-size:18px;}
.alert-banner{background:#fff3cd;border:1px solid #ffc107;border-left:4px solid #fd7e14;border-radius:8px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:10px;font-size:13px;color:#6d4c00;}
.toolbar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:14px;}
.toolbar input,.toolbar select{padding:7px 10px;border:1px solid #dee2e6;border-radius:6px;font-size:13px;}
.toolbar input:focus,.toolbar select:focus{border-color:var(--blue);outline:none;}
.card{background:#fff;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.07);margin-bottom:20px;overflow:hidden;}
.card-hd{padding:13px 18px;border-bottom:1px solid #e9ecef;display:flex;align-items:center;justify-content:space-between;}
.card-hd-title{font-size:14px;font-weight:700;color:var(--blue);display:flex;align-items:center;gap:8px;}
.card-body{padding:16px 18px;}
.table-wrap{overflow-x:hidden;}
table.inv{width:100%;border-collapse:collapse;font-size:13px;table-layout:fixed;}
table.inv th{background:#002F6C;color:#fff;padding:10px 11px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;}
table.inv td{padding:9px 11px;border-bottom:1px solid #f1f5f9;vertical-align:middle;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
table.inv tbody tr:hover td{background:#f0f4ff;}
.badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;}
.badge-ok{background:#dcfce7;color:#166534;} .badge-low{background:#fef9c3;color:#854d0e;} .badge-out{background:#fee2e2;color:#991b1b;}
.row-low td{background:#fffbeb;} .row-out td{background:#fff5f5;}
</style>

<div class="page-head">
  <div>
    <h1 class="h1"><i class="fas fa-boxes"></i> Merchandise Inventory Oversight</h1>
    <div class="sub">Read-only monitoring of merchandise stock levels and alerts.</div>
  </div>
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-left:auto;">
    <?php
    $export_table_id       = 'invT';
    $export_filename       = 'admin_merch_inventory_' . date('Ymd');
    $export_title          = 'Merchandise Inventory Oversight';
    $export_rows_select_id = 'adminMerchRowsLimit';
    $export_default_rows   = 50;
    $export_back_url       = 'admin_dashboard.php';
    require __DIR__ . '/../partials/export_buttons.php';
    ?>
  </div>
</div>

<?php require_once __DIR__ . '/../partials/admin_inventory_summary.php'; ?>

<!-- Flat KPI Summary Table -->
<div style="background:#fff;border:1px solid #dee2e6;border-radius:8px;margin-bottom:20px;overflow:hidden;">
  <table style="width:100%;border-collapse:collapse;font-size:13px;text-align:center;">
    <thead>
      <tr style="background:#f8f9fa;border-bottom:1px solid #dee2e6;">
        <th style="padding:10px;font-weight:700;color:#555;border-right:1px solid #dee2e6;">Total Products</th>
        <th style="padding:10px;font-weight:700;color:#555;border-right:1px solid #dee2e6;">In Stock</th>
        <th style="padding:10px;font-weight:700;color:#555;border-right:1px solid #dee2e6;">Low Stock</th>
        <th style="padding:10px;font-weight:700;color:#555;border-right:1px solid #dee2e6;">Out of Stock</th>
        <th style="padding:10px;font-weight:700;color:#555;">Total Inventory Value</th>
      </tr>
    </thead>
    <tbody>
      <tr style="font-size:16px;font-weight:800;">
        <td style="padding:12px;color:#002F6C;border-right:1px solid #dee2e6;"><?= $stats['total'] ?></td>
        <td style="padding:12px;color:#28a745;border-right:1px solid #dee2e6;"><?= $stats['in_stock'] ?></td>
        <td style="padding:12px;color:#fd7e14;border-right:1px solid #dee2e6;"><?= $stats['low_stock'] ?></td>
        <td style="padding:12px;color:#dc3545;border-right:1px solid #dee2e6;"><?= $stats['out_of_stock'] ?></td>
        <td style="padding:12px;color:#6d28d9;">&#8369;<?= number_format($stats['total_value'],0) ?></td>
      </tr>
    </tbody>
  </table>
</div>

<?php if ($stats['low_stock'] + $stats['out_of_stock'] > 0): ?>
<div class="alert-banner">
  <i class="fas fa-exclamation-triangle" style="color:#fd7e14;font-size:18px;"></i>
  <strong><?= $stats['low_stock']+$stats['out_of_stock'] ?> product(s) need attention</strong> &mdash;
  <?= $stats['out_of_stock'] ?> Out of Stock &nbsp;|&nbsp; <?= $stats['low_stock'] ?> Low Stock.
  Monitor via <a href="admin_stock_requests_monitor.php" style="color:#6d4c00;font-weight:700;">Stock Request Monitoring</a>.
</div>
<?php endif; ?>

<div class="card">
  <div class="card-hd">
    <div class="card-hd-title"><i class="fas fa-list"></i> Product Records <span id="vcnt" style="font-size:12px;color:var(--gray);font-weight:400;margin-left:6px;"></span></div>
  </div>
  <div class="card-body">
    <div class="toolbar">
      <input type="text" id="sq" placeholder="&#128269; Search name, SKU..." oninput="ft()">
      <select id="cf" onchange="ft()">
        <option value="">All Categories</option>
        <?php foreach ($cats as $c): ?><option><?= htmlspecialchars($c) ?></option><?php endforeach; ?>
      </select>
      <select id="sf" onchange="ft()">
        <option value="">All Statuses</option>
        <option value="ok">In Stock</option>
        <option value="low">Low Stock</option>
        <option value="out">Out of Stock</option>
      </select>
    </div>
    <div class="table-wrap">
      <table class="inv" id="invT">
        <colgroup>
          <col style="width:20%"><col style="width:9%"><col style="width:10%"><col style="width:7%">
          <col style="width:10%"><col style="width:10%"><col style="width:9%"><col style="width:8%">
          <col style="width:10%"><col style="width:7%">
        </colgroup>
        <thead>
          <tr>
            <th>Product</th><th>SKU</th><th>Category</th><th>Size</th>
            <th>Unit Cost</th><th>Selling Price</th><th>Qty</th><th>Reorder Pt.</th>
            <th>Status</th><th>Updated</th>
          </tr>
        </thead>
        <tbody id="invB">
        <?php if (empty($products)): ?>
          <tr><td colspan="10" style="text-align:center;padding:32px;color:var(--gray);">No merchandise products found.</td></tr>
        <?php else: foreach ($products as $p):
          $st=$p['_st'];
          $rc=$st==='out'?'row-out':($st==='low'?'row-low':'');
          $bc=$st==='out'?'badge-out':($st==='low'?'badge-low':'badge-ok');
          $bl=$st==='out'?'Out of Stock':($st==='low'?'Low Stock':'In Stock');
        ?>
          <tr class="inv-row <?= $rc ?>" data-n="<?= strtolower(htmlspecialchars($p['product_name'])) ?>" data-s="<?= strtolower(htmlspecialchars($p['sku']??'')) ?>" data-c="<?= htmlspecialchars($p['category']) ?>" data-x="<?= $st ?>">
            <td title="<?= htmlspecialchars($p['product_name']) ?>"><strong><?= htmlspecialchars($p['product_name']) ?></strong></td>
            <td style="color:var(--gray);"><?= htmlspecialchars($p['sku']??'—') ?></td>
            <td><?= htmlspecialchars($p['category']) ?></td>
            <td style="color:var(--gray);"><?= htmlspecialchars($p['size']??'—') ?></td>
            <td>&#8369;<?= number_format((float)$p['unit_cost'],2) ?></td>
            <td><strong>&#8369;<?= number_format((float)$p['unit_price'],2) ?></strong></td>
            <td><strong style="color:<?= $st==='out'?'#dc3545':($st==='low'?'#fd7e14':'#28a745') ?>"><?= number_format((int)$p['qty']) ?></strong></td>
            <td><?= (int)$p['reorder_point'] ?></td>
            <td><span class="badge <?= $bc ?>"><?= $bl ?></span></td>
            <td style="font-size:12px;color:var(--gray);"><?= $p['updated_at'] ? date('M d',strtotime($p['updated_at'])) : '—' ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div id="adminMerchInvPagination" style="margin-top:10px;"></div>
  </div>
</div>

<script>
function ft(){
    var q=document.getElementById('sq').value.toLowerCase();
    var c=document.getElementById('cf').value;
    var s=document.getElementById('sf').value;
    var rows=document.querySelectorAll('#invB .inv-row'),v=0;
    rows.forEach(function(r){
        var show=(!q||r.dataset.n.includes(q)||r.dataset.s.includes(q))
              &&(!c||r.dataset.c===c)&&(!s||r.dataset.x===s);
        r.style.display=show?'':'none';if(show)v++;
    });
    document.getElementById('vcnt').textContent='('+v+' shown)';
}
function exportCSV(){
    var rows=document.querySelectorAll('#invB .inv-row'),csv='Product,SKU,Category,Size,UnitCost,SellingPrice,Qty,ReorderPt,Status\n';
    rows.forEach(function(r){
        if(r.style.display==='none')return;
        var c=r.querySelectorAll('td'),l=[];
        [0,1,2,3,4,5,6,7,8].forEach(function(i){l.push('"'+(c[i]?c[i].textContent.trim().replace(/,/g,''):'')+'"');});
        csv+=l.join(',')+'\n';
    });
    var a=document.createElement('a');
    a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv'}));
    a.download='merch_inventory_<?= date('Ymd') ?>.csv';a.click();
}
document.addEventListener('DOMContentLoaded',function(){
    document.getElementById('vcnt').textContent='(<?= count($products) ?> shown)';
    setupTablePagination('invT','adminMerchRowsLimit','adminMerchInvPagination',50);
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
