<?php
$page_id = 'admin_inventory_history';
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
$action_type = $_GET['action_type'] ?? '';

// Fetch Pipeline History (Purchase Orders lifecycle)
$po_history = [];
try {
    $stmt1 = $pdo->prepare("
        SELECT po.*, sr.created_at AS requested_at, sr.processed_at AS mgr_approved_at,
               u.name AS created_by_name, f.last_updated AS fuel_stocked_at,
               si.encoded_at AS merch_stocked_at
        FROM purchase_orders po
        LEFT JOIN stock_requests sr ON po.request_id = sr.id AND po.type = 'merch'
        LEFT JOIN users u ON po.created_by = u.id
        LEFT JOIN fuel_inventory f ON po.product_name = f.fuel_type AND po.type = 'fuel' AND f.station_id = po.station_id
        LEFT JOIN merchandise_stock_in si ON po.id = si.po_id AND po.type = 'merch' AND si.station_id = po.station_id
        WHERE po.station_id = ?
          AND DATE(po.created_at) BETWEEN ? AND ?
        ORDER BY po.created_at DESC
    ");
    $stmt1->execute([$station_id, $start_date, $end_date]);
    $po_history = $stmt1->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Fetch Raw Inventory Transactions (from inventory_logs)
$trans_logs = [];
try {
    $stmt2 = $pdo->prepare("
        SELECT il.*, ip.product_name, u.name AS performed_by_name
        FROM inventory_logs il
        LEFT JOIN inventory_products ip ON il.product_id = ip.id
        LEFT JOIN users u ON il.user_id = u.id
        WHERE il.station_id = ?
          AND DATE(il.created_at) BETWEEN ? AND ?
          AND (? = '' OR il.action = ?)
        ORDER BY il.created_at DESC
    ");
    $stmt2->execute([$station_id, $start_date, $end_date, $action_type, $action_type]);
    $trans_logs = $stmt2->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Export CSV handler
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $type = $_GET['export_type'] ?? 'pipeline';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inventory_'.$type.'_history_'.date('Ymd').'.csv"');
    $out = fopen('php://output', 'w');

    if ($type === 'pipeline') {
        fputcsv($out, ['PO Number', 'Type', 'Item/Product', 'Qty Ordered', 'Unit Price', 'Total Amount', 'Status', 'Requested Date', 'Manager Review Date', 'Created/Drafted Date', 'Finalized Date', 'Stocked-In Date']);
        foreach ($po_history as $r) {
            $stocked_in_date = $r['type'] === 'fuel' ? $r['fuel_stocked_at'] : $r['merch_stocked_at'];
            fputcsv($out, [
                $r['po_number'],
                ucfirst($r['type']),
                $r['product_name'],
                $r['quantity'],
                $r['unit_price'],
                $r['total_amount'],
                $r['status'],
                $r['requested_at'] ?? '—',
                $r['mgr_approved_at'] ?? '—',
                $r['created_at'],
                $r['admin_finalized'] ? ($r['updated_at'] ?? '—') : '—',
                $stocked_in_date ?? '—'
            ]);
        }
    } else {
        fputcsv($out, ['Date/Time', 'Product/Item', 'Action', 'Qty Before', 'Qty Change', 'Qty After', 'Performed By', 'Remarks/Notes']);
        foreach ($trans_logs as $r) {
            fputcsv($out, [
                $r['created_at'],
                $r['product_name'] ?: 'Fuel / Miscellaneous',
                ucfirst($r['action']),
                $r['quantity_before'],
                $r['quantity_change'],
                $r['quantity_after'],
                $r['performed_by_name'] ?? 'System',
                $r['notes']
            ]);
        }
    }
    fclose($out);
    exit;
}

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
.card-hd{padding:13px 18px;border-bottom:1px solid #e9ecef;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;}
.card-hd-title{font-size:14px;font-weight:700;color:var(--blue);display:flex;align-items:center;gap:8px;}
.card-body{padding:16px 18px;}
.filter-bar{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:16px;background:#f8fafc;padding:12px 14px;border-radius:8px;border:1px solid #e2e8f0;}
.filter-bar .fg{display:flex;flex-direction:column;gap:4px;}
.filter-bar label{font-size:11px;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:.4px;}
.filter-bar input,.filter-bar select{padding:7px 10px;border:1px solid #dee2e6;border-radius:6px;font-size:13px;}
.table-wrap{overflow-x:hidden;}
table.hist{width:100%;border-collapse:collapse;font-size:13px;table-layout:fixed;}
table.hist th{background:#002F6C;color:#fff;padding:10px 11px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;}
table.hist td{padding:9px 11px;border-bottom:1px solid #f1f5f9;vertical-align:middle;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
table.hist tbody tr:hover td{background:#f0f4ff;}
.badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;}
.badge-pending{background:#fff3cd;color:#856404;}
.badge-finalized{background:#dbeafe;color:#1e40af;}
.badge-completed{background:#dcfce7;color:#166534;}
.badge-cancelled{background:#fee2e2;color:#991b1b;}
</style>

<div class="page-head">
  <div>
    <h1 class="h1"><i class="fas fa-history"></i> Inventory History &amp; Auditing</h1>
    <div class="sub">Verify end-to-end inventory transactions, check variances, and audit reconciliation trails.</div>
  </div>
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-left:auto;">
    <?php
    $export_table_id       = 'adminHistPipelineTable';
    $export_filename       = 'admin_inventory_history_' . date('Ymd');
    $export_title          = 'Inventory History';
    $export_rows_select_id = 'adminHistRowsLimit';
    $export_default_rows   = 25;
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
        <th style="padding:10px;font-weight:700;color:#555;border-right:1px solid #dee2e6;">POs in Period</th>
        <th style="padding:10px;font-weight:700;color:#555;border-right:1px solid #dee2e6;">Fully Stocked In</th>
        <th style="padding:10px;font-weight:700;color:#555;">Stock Transactions</th>
      </tr>
    </thead>
    <tbody>
      <tr style="font-size:16px;font-weight:800;">
        <td style="padding:12px;color:#002F6C;border-right:1px solid #dee2e6;"><?= count($po_history) ?></td>
        <td style="padding:12px;color:#28a745;border-right:1px solid #dee2e6;"><?= count(array_filter($po_history, fn($p)=>strtolower($p['status'])==='stock-in complete' || $p['stock_in_done'])) ?></td>
        <td style="padding:12px;color:#fd7e14;"><?= count($trans_logs) ?></td>
      </tr>
    </tbody>
  </table>
</div>

<div class="main-tab-nav">
  <button class="tab-btn active" id="tabPipelineBtn" onclick="switchTab('pipeline')"><i class="fas fa-route"></i> Pipeline Lifecycle Log (<?= count($po_history) ?>)</button>
  <button class="tab-btn" id="tabTransBtn" onclick="switchTab('trans')"><i class="fas fa-list-alt"></i> Stock Transaction Log (<?= count($trans_logs) ?>)</button>
</div>

<!-- Pipeline Panel -->
<div class="card" id="panelPipeline">
  <div class="card-hd">
    <div class="card-hd-title"><i class="fas fa-route"></i> Purchase Order Pipeline Log</div>
    <div>
      <button class="btn ghost" style="font-size:12px;" onclick="exportCSV('pipeline')"><i class="fas fa-file-csv"></i> Export Pipeline CSV</button>
    </div>
  </div>
  <div class="card-body">
    <!-- Filter bar -->
    <form method="GET" class="filter-bar">
      <div class="fg">
        <label>Start Date</label>
        <input type="date" name="start" value="<?= htmlspecialchars($start_date) ?>">
      </div>
      <div class="fg">
        <label>End Date</label>
        <input type="date" name="end" value="<?= htmlspecialchars($end_date) ?>">
      </div>
      <div style="margin-top:auto;">
        <button type="submit" class="btn primary" style="padding:7px 15px;font-size:13px;"><i class="fas fa-filter"></i> Apply Dates</button>
      </div>
    </form>

    <div class="table-wrap">
      <table class="hist" id="adminHistPipelineTable">
        <colgroup>
          <col style="width:13%"><col style="width:7%"><col style="width:18%"><col style="width:8%"><col style="width:11%"><col style="width:11%"><col style="width:11%"><col style="width:11%"><col style="width:10%">
        </colgroup>
        <thead>
          <tr>
            <th>PO Number</th><th>Type</th><th>Product</th><th>Qty</th><th>Request Date</th><th>Mgr Review</th><th>Admin Final</th><th>Stocked In</th><th>Status</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($po_history)): ?>
          <tr><td colspan="9" style="text-align:center;padding:32px;color:var(--gray);">No PO lifecycle logs found for this period.</td></tr>
        <?php else: foreach ($po_history as $p):
          $st = strtolower($p['status']);
          $bc = ($st==='stock-in complete' || $p['stock_in_done'])?'badge-completed':(($r['admin_finalized']??0)?'badge-finalized':'badge-pending');
          $bl = ($st==='stock-in complete' || $p['stock_in_done'])?'Stocked-In':(($p['admin_finalized'])?'Finalized':'Pending');
          $stocked = $p['type']==='fuel'?$p['fuel_stocked_at']:$p['merch_stocked_at'];
        ?>
          <tr>
            <td style="color:var(--blue);font-weight:600;"><?= htmlspecialchars($p['po_number']) ?></td>
            <td><?= ucfirst($p['type']) ?></td>
            <td title="<?= htmlspecialchars($p['product_name']) ?>"><strong><?= htmlspecialchars($p['product_name']) ?></strong></td>
            <td><?= number_format((float)$p['quantity'],0) ?></td>
            <td><?= $p['requested_at'] ? date('M d, Y', strtotime($p['requested_at'])) : '—' ?></td>
            <td><?= $p['mgr_approved_at'] ? date('M d, Y', strtotime($p['mgr_approved_at'])) : '—' ?></td>
            <td><?= $p['admin_finalized'] ? date('M d, Y', strtotime($p['created_at'])) : '—' ?></td>
            <td><?= $stocked ? date('M d, Y', strtotime($stocked)) : '—' ?></td>
            <td><span class="badge <?= $bc ?>"><?= $bl ?></span></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div id="adminHistPipelinePagination" style="margin-top:10px;"></div>
  </div>
</div>

<!-- Transaction Panel -->
<div class="card" id="panelTrans" style="display:none;">
  <div class="card-hd">
    <div class="card-hd-title"><i class="fas fa-list-alt"></i> Raw Stock Transaction Log</div>
    <div>
      <button class="btn ghost" style="font-size:12px;" onclick="exportCSV('trans')"><i class="fas fa-file-csv"></i> Export Transactions CSV</button>
    </div>
  </div>
  <div class="card-body">
    <!-- Filter bar -->
    <form method="GET" class="filter-bar">
      <div class="fg">
        <label>Start Date</label>
        <input type="date" name="start" value="<?= htmlspecialchars($start_date) ?>">
      </div>
      <div class="fg">
        <label>End Date</label>
        <input type="date" name="end" value="<?= htmlspecialchars($end_date) ?>">
      </div>
      <div class="fg">
        <label>Action/Type</label>
        <select name="action_type">
          <option value="">All Actions</option>
          <option value="stock_in" <?= $action_type==='stock_in'?'selected':'' ?>>Stock-In</option>
          <option value="sales" <?= $action_type==='sales'?'selected':'' ?>>Sales / Reduction</option>
          <option value="adjustment" <?= $action_type==='adjustment'?'selected':'' ?>>Manual Adjustment</option>
        </select>
      </div>
      <div style="margin-top:auto;">
        <button type="submit" class="btn primary" style="padding:7px 15px;font-size:13px;"><i class="fas fa-filter"></i> Filter</button>
      </div>
    </form>

    <div class="table-wrap">
      <table class="hist" id="adminHistTransTable">
        <colgroup>
          <col style="width:13%"><col style="width:17%"><col style="width:10%"><col style="width:8%"><col style="width:8%"><col style="width:8%"><col style="width:13%"><col style="width:23%">
        </colgroup>
        <thead>
          <tr>
            <th>Date/Time</th><th>Product</th><th>Action</th><th>Before</th><th>Change</th><th>After</th><th>User</th><th>Notes</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($trans_logs)): ?>
          <tr><td colspan="8" style="text-align:center;padding:32px;color:var(--gray);">No raw stock transaction logs found for this period.</td></tr>
        <?php else: foreach ($trans_logs as $r): ?>
          <tr>
            <td><?= date('M d, Y H:i', strtotime($r['created_at'])) ?></td>
            <td title="<?= htmlspecialchars($r['product_name'] ?: '') ?>"><strong><?= htmlspecialchars($r['product_name'] ?: 'Fuel / Miscellaneous') ?></strong></td>
            <td><strong style="color:var(--blue);"><?= htmlspecialchars($r['action']) ?></strong></td>
            <td><?= number_format((float)$r['quantity_before'],0) ?></td>
            <td style="color:<?= $r['quantity_change']>0?'#28a745':'#dc3545' ?>"><?= $r['quantity_change']>0?'+':'' ?><?= number_format((float)$r['quantity_change'],0) ?></td>
            <td><?= number_format((float)$r['quantity_after'],0) ?></td>
            <td><?= htmlspecialchars($r['performed_by_name'] ?: 'System') ?></td>
            <td style="font-size:12px;color:var(--gray);" title="<?= htmlspecialchars($r['notes']) ?>"><?= htmlspecialchars($r['notes']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div id="adminHistTransPagination" style="margin-top:10px;"></div>
  </div>
</div>

<script>
function switchTab(t) {
    document.getElementById('panelPipeline').style.display = t==='pipeline'?'block':'none';
    document.getElementById('panelTrans').style.display = t==='trans'?'block':'none';
    document.getElementById('tabPipelineBtn').classList.toggle('active', t==='pipeline');
    document.getElementById('tabTransBtn').classList.toggle('active', t==='trans');
    if (t==='pipeline') setupTablePagination('adminHistPipelineTable','adminHistRowsLimit','adminHistPipelinePagination',25);
    if (t==='trans')    setupTablePagination('adminHistTransTable','adminHistRowsLimit','adminHistTransPagination',25);
}
function exportCSV(type) {
    var start = document.querySelector('input[name="start"]').value;
    var end = document.querySelector('input[name="end"]').value;
    var act = document.querySelector('select[name="action_type"]')?.value || '';
    window.open('admin_inventory_history.php?export=csv&export_type='+type+'&start='+start+'&end='+end+'&action_type='+act, '_blank');
}
document.addEventListener('DOMContentLoaded', function() {
    setupTablePagination('adminHistPipelineTable','adminHistRowsLimit','adminHistPipelinePagination',25);
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
