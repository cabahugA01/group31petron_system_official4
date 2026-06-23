<?php
$page_id = 'admin_inventory_history';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();
$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();

// ── Module gate ───────────────────────────────────────────────
if (!in_array($role, ['superadmin', 'developer']) && !is_module_enabled('inventory')) {
    render_module_disabled_page('Inventory');
}

if (!in_array($role, ['admin','superadmin'])) { header('Location: dashboard.php'); exit; }

$start_date = $_GET['start'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date   = $_GET['end']   ?? date('Y-m-d');
$active_tab = $_GET['tab']   ?? 'fuel';
$fuel_filter = trim($_GET['fuel_type'] ?? '');

// ── FUEL HISTORY: UNION of deliveries (IN), transactions (OUT), adjustments (±) ──
$fuel_rows = [];
try {
    $sql = "
        SELECT
            CONCAT('DEL-', fd.id) AS txn_id,
            fd.created_at AS txn_date,
            fd.fuel_type,
            fd.delivery_liters AS liters_in,
            0 AS liters_out,
            fd.delivery_liters AS qty_change,
            'Delivery (Stock-In)' AS txn_type,
            0 AS unit_price,
            0 AS total_value,
            fd.tank_assigned AS notes
        FROM fuel_deliveries fd
        WHERE fd.station_id = ? AND DATE(fd.created_at) BETWEEN ? AND ?
          AND fd.status IN ('Verified','Approved')
          AND (? = '' OR fd.fuel_type LIKE ?)

        UNION ALL

        SELECT
            ft.transaction_id AS txn_id,
            ft.transaction_date AS txn_date,
            ft.fuel_type,
            0 AS liters_in,
            ft.liters_sold AS liters_out,
            -ft.liters_sold AS qty_change,
            'Sales (Out)' AS txn_type,
            ft.price_per_liter AS unit_price,
            ft.total_amount AS total_value,
            CONCAT('Pump #', IFNULL(ft.pump_id,'—')) AS notes
        FROM fuel_transactions ft
        WHERE ft.station_id = ? AND DATE(ft.transaction_date) BETWEEN ? AND ?
          AND ft.status IN ('Approved','approved','Completed')
          AND (? = '' OR ft.fuel_type LIKE ?)

        UNION ALL

        SELECT
            CONCAT('ADJ-', fa.id) AS txn_id,
            fa.created_at AS txn_date,
            fa.fuel_type,
            CASE WHEN fa.liters > 0 THEN fa.liters ELSE 0 END AS liters_in,
            CASE WHEN fa.liters < 0 THEN ABS(fa.liters) ELSE 0 END AS liters_out,
            fa.liters AS qty_change,
            CONCAT('Adjustment (', fa.adjustment_type, ')') AS txn_type,
            0 AS unit_price,
            0 AS total_value,
            fa.reason AS notes
        FROM fuel_adjustments fa
        WHERE fa.station_id = ? AND DATE(fa.created_at) BETWEEN ? AND ?
          AND fa.status = 'Approved'
          AND (? = '' OR fa.fuel_type LIKE ?)

        ORDER BY txn_date DESC
    ";
    $like = "%$fuel_filter%";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $station_id, $start_date, $end_date, $fuel_filter, $like,
        $station_id, $start_date, $end_date, $fuel_filter, $like,
        $station_id, $start_date, $end_date, $fuel_filter, $like,
    ]);
    $fuel_rows_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Compute running balance per fuel type
    $fuel_balance = [];
    $fuel_rows_ordered = array_reverse($fuel_rows_raw); // oldest first
    foreach ($fuel_rows_ordered as &$r) {
        $ft = $r['fuel_type'];
        if (!isset($fuel_balance[$ft])) $fuel_balance[$ft] = 0;
        $fuel_balance[$ft] += (float)$r['qty_change'];
        $r['balance_after'] = $fuel_balance[$ft];
        // Compute total value for deliveries using current fuel price
        if ($r['liters_in'] > 0 && $r['unit_price'] == 0) {
            try {
                $ps = $pdo->prepare("SELECT price_per_liter FROM fuel_inventory WHERE station_id=? AND fuel_type=? LIMIT 1");
                $ps->execute([$station_id, $ft]);
                $pr = $ps->fetchColumn();
                $r['unit_price'] = $pr ?: 0;
                $r['total_value'] = (float)$r['liters_in'] * (float)$r['unit_price'];
            } catch(Exception $e) {}
        }
    }
    unset($r);
    $fuel_rows = array_reverse($fuel_rows_ordered); // back to newest first
} catch (Exception $e) {}

// ── MERCH HISTORY: UNION of stock_in (IN) and transaction_items (OUT) ──
$merch_rows = [];
try {
    $sql2 = "
        SELECT
            CONCAT('SI-', msi.id) AS txn_id,
            msi.encoded_at AS txn_date,
            msi.product_name,
            msi.sku,
            msi.qty_received AS qty_in,
            0 AS qty_out,
            msi.qty_received AS qty_change,
            'Stock-In (Delivery)' AS txn_type,
            msi.unit_cost AS unit_price,
            msi.total_cost AS total_value,
            msi.stock_before,
            msi.stock_after AS balance_after,
            msi.condition_flag AS notes
        FROM merchandise_stock_in msi
        WHERE msi.station_id = ? AND DATE(msi.encoded_at) BETWEEN ? AND ?

        UNION ALL

        SELECT
            CONCAT('SALE-', mt.id, '-', mti.id) AS txn_id,
            mt.transaction_date AS txn_date,
            mti.product_name,
            '' AS sku,
            0 AS qty_in,
            mti.quantity AS qty_out,
            -mti.quantity AS qty_change,
            CONCAT('Sales (', mti.category, ')') AS txn_type,
            mti.unit_price,
            mti.subtotal AS total_value,
            0 AS stock_before,
            0 AS balance_after,
            mt.transaction_id AS notes
        FROM merchandise_transaction_items mti
        JOIN merchandise_transactions mt ON mti.transaction_id = mt.id
        WHERE mt.station_id = ? AND DATE(mt.transaction_date) BETWEEN ? AND ?
          AND mt.validation_status IN ('Official','Completed','Approved','Adjusted')
          AND mti.item_type = 'merchandise'

        ORDER BY txn_date DESC
    ";
    $stmt2 = $pdo->prepare($sql2);
    $stmt2->execute([$station_id, $start_date, $end_date, $station_id, $start_date, $end_date]);
    $merch_rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $etype = $_GET['export_type'] ?? 'fuel';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inventory_'.$etype.'_history_'.date('Ymd').'.csv"');
    $out = fopen('php://output', 'w');
    if ($etype === 'fuel') {
        fputcsv($out, ['Transaction ID','Date & Time','Fuel Type','Type','Liters In','Liters Out','Balance After','Unit Price','Total Value','Notes']);
        foreach ($fuel_rows as $r) {
            fputcsv($out, [$r['txn_id'],$r['txn_date'],$r['fuel_type'],$r['txn_type'],$r['liters_in'],$r['liters_out'],$r['balance_after']??'—',$r['unit_price'],$r['total_value'],$r['notes']]);
        }
    } else {
        fputcsv($out, ['Transaction ID','Date & Time','Product','SKU','Type','Qty In','Qty Out','Balance After','Unit Price','Total Value','Notes']);
        foreach ($merch_rows as $r) {
            fputcsv($out, [$r['txn_id'],$r['txn_date'],$r['product_name'],$r['sku'],$r['txn_type'],$r['qty_in'],$r['qty_out'],$r['balance_after']??'—',$r['unit_price'],$r['total_value'],$r['notes']]);
        }
    }
    fclose($out);
    exit;
}

// Fuel types for filter
$fuel_types = [];
try {
    $fuel_types = $pdo->prepare("SELECT DISTINCT fuel_type FROM fuel_inventory WHERE station_id=? ORDER BY fuel_type");
    $fuel_types->execute([$station_id]);
    $fuel_types = $fuel_types->fetchColumn() ? $fuel_types->fetchAll(PDO::FETCH_COLUMN) : [];
} catch(Exception $e) {}
if (empty($fuel_types)) {
    try {
        $r = $pdo->query("SELECT DISTINCT fuel_type FROM fuel_types ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        $fuel_types = $r;
    } catch(Exception $e) {}
}

include __DIR__ . '/../partials/header.php';
?>
<style>
:root{--blue:#002F6C;--red:#dc3545;--green:#28a745;--gray:#6c757d;}
.tab-nav{display:flex;border-bottom:2px solid #e2e8f0;margin-bottom:22px;}
.tab-btn{padding:10px 24px;background:none;border:none;border-bottom:3px solid transparent;font-size:14px;font-weight:600;color:var(--gray);cursor:pointer;margin-bottom:-2px;transition:all .15s;display:inline-flex;align-items:center;gap:7px;}
.tab-btn.active{color:var(--blue);border-bottom-color:var(--blue);}
.tab-btn:hover{color:var(--blue);}
.card{background:#fff;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.07);margin-bottom:20px;overflow:hidden;}
.card-hd{padding:13px 18px;border-bottom:1px solid #e9ecef;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;}
.card-hd-title{font-size:14px;font-weight:700;color:var(--blue);display:flex;align-items:center;gap:8px;}
.filter-bar{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:16px;background:#f8fafc;padding:12px 14px;border-radius:8px;border:1px solid #e2e8f0;}
.filter-bar .fg{display:flex;flex-direction:column;gap:4px;}
.filter-bar label{font-size:11px;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:.4px;}
.filter-bar input,.filter-bar select{padding:7px 10px;border:1px solid #dee2e6;border-radius:6px;font-size:13px;}
.table-wrap{overflow:hidden;}
table.hist{width:100%;border-collapse:collapse;font-size:12.5px;}
table.hist th{background:#002F70;color:#fff;padding:10px 12px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;}
table.hist td{padding:9px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle;white-space:nowrap;}
table.hist tbody tr:hover td{background:#eff6ff;}
.badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:4px;font-size:11px;font-weight:700;}
.badge-in{background:#dcfce7;color:#166534;}
.badge-out{background:#fee2e2;color:#991b1b;}
.badge-adj{background:#fef9c3;color:#854d0e;}
.txn-id{font-family:monospace;font-size:11px;color:var(--blue);}
.num{text-align:right;}
.in-val{color:#166534;font-weight:700;}
.out-val{color:#dc3545;font-weight:700;}
.kpi-strip{display:flex;gap:14px;margin-bottom:20px;flex-wrap:wrap;}
.kpi-card{flex:1;background:#fff;border-radius:10px;padding:14px 18px;border:1px solid #e2e8f0;box-shadow:0 1px 3px rgba(0,0,0,.05);}
.kpi-card .kv{font-size:22px;font-weight:800;line-height:1;}.kpi-card .kl{font-size:11px;color:var(--gray);margin-top:4px;}
</style>

<div class="page-head">
  <div>
    <h1 class="h1"><i class="fas fa-history"></i> Inventory History</h1>
    <div class="sub">Full ledger of all fuel and merchandise inventory movements — deliveries, sales, and adjustments.</div>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-left:auto;">
    <button onclick="exportCSV()" class="btn ghost" style="font-size:13px;"><i class="fas fa-file-csv"></i> Export CSV</button>
  </div>
</div>

<?php require_once __DIR__ . '/../partials/admin_inventory_summary.php'; ?>

<div class="tab-nav">
  <button class="tab-btn <?= $active_tab==='fuel'?'active':'' ?>" onclick="switchTab('fuel')">
    <i class="fas fa-gas-pump"></i> Fuel Inventory History <span style="background:#002F70;color:#fff;border-radius:10px;padding:1px 8px;font-size:11px;"><?= count($fuel_rows) ?></span>
  </button>
  <button class="tab-btn <?= $active_tab==='merch'?'active':'' ?>" onclick="switchTab('merch')">
    <i class="fas fa-boxes"></i> Merchandise Inventory History <span style="background:#002F70;color:#fff;border-radius:10px;padding:1px 8px;font-size:11px;"><?= count($merch_rows) ?></span>
  </button>
</div>

<!-- ══ FUEL TAB ══ -->
<div id="panelFuel" <?= $active_tab!=='fuel'?'style="display:none;"':'' ?>>
<?php
  $total_liters_in  = array_sum(array_column($fuel_rows,'liters_in'));
  $total_liters_out = array_sum(array_column($fuel_rows,'liters_out'));
  $total_fuel_value = array_sum(array_column($fuel_rows,'total_value'));
?>
<div class="kpi-strip">
  <div class="kpi-card"><div class="kv in-val"><?= number_format($total_liters_in,2) ?>L</div><div class="kl">Total Liters In</div></div>
  <div class="kpi-card"><div class="kv out-val"><?= number_format($total_liters_out,2) ?>L</div><div class="kl">Total Liters Out</div></div>
  <div class="kpi-card"><div class="kv" style="color:var(--blue);"><?= count($fuel_rows) ?></div><div class="kl">Total Transactions</div></div>
  <div class="kpi-card"><div class="kv" style="color:#7c3aed;">&#8369;<?= number_format($total_fuel_value,2) ?></div><div class="kl">Total Value</div></div>
</div>
<div class="card">
  <div class="card-hd">
    <div class="card-hd-title"><i class="fas fa-gas-pump"></i> Fuel Inventory Transaction Ledger</div>
  </div>
  <div style="padding:14px 18px;">
    <form method="GET" class="filter-bar">
      <input type="hidden" name="tab" value="fuel">
      <div class="fg"><label>Start Date</label><input type="date" name="start" value="<?= htmlspecialchars($start_date) ?>"></div>
      <div class="fg"><label>End Date</label><input type="date" name="end" value="<?= htmlspecialchars($end_date) ?>"></div>
      <div class="fg">
        <label>Fuel Type</label>
        <select name="fuel_type">
          <option value="">All Fuel Types</option>
          <?php foreach ($fuel_types as $ft): ?>
          <option value="<?= htmlspecialchars($ft) ?>" <?= $fuel_filter===$ft?'selected':'' ?>><?= htmlspecialchars($ft) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="margin-top:auto;"><button type="submit" class="btn primary" style="padding:7px 15px;font-size:13px;"><i class="fas fa-filter"></i> Filter</button></div>
    </form>
    <div class="table-wrap">
      <table class="hist" id="fuelHistTable">
        <thead>
          <tr>
            <th>Transaction ID</th>
            <th>Date &amp; Time</th>
            <th>Fuel Type</th>
            <th>Type</th>
            <th class="num">Liters In</th>
            <th class="num">Liters Out</th>
            <th class="num">Balance After</th>
            <th class="num">Unit Price</th>
            <th class="num">Total Value</th>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($fuel_rows)): ?>
          <tr><td colspan="10" style="text-align:center;padding:40px;color:var(--gray);">No fuel transactions found for this period.</td></tr>
        <?php else: foreach ($fuel_rows as $r):
          $is_in  = $r['liters_in'] > 0;
          $is_out = $r['liters_out'] > 0;
          $badge_cls = $is_in ? 'badge-in' : ($is_out ? 'badge-out' : 'badge-adj');
        ?>
          <tr>
            <td><span class="txn-id"><?= htmlspecialchars($r['txn_id']) ?></span></td>
            <td><?= $r['txn_date'] ? date('M d, Y H:i', strtotime($r['txn_date'])) : '—' ?></td>
            <td><strong><?= htmlspecialchars($r['fuel_type']) ?></strong></td>
            <td><span class="badge <?= $badge_cls ?>"><?= htmlspecialchars($r['txn_type']) ?></span></td>
            <td class="num <?= $r['liters_in']>0?'in-val':'' ?>"><?= $r['liters_in']>0 ? number_format((float)$r['liters_in'],2).'L' : '<span style="color:#ccc">—</span>' ?></td>
            <td class="num <?= $r['liters_out']>0?'out-val':'' ?>"><?= $r['liters_out']>0 ? number_format((float)$r['liters_out'],2).'L' : '<span style="color:#ccc">—</span>' ?></td>
            <td class="num" style="font-weight:700;color:var(--blue);"><?= isset($r['balance_after']) ? number_format((float)$r['balance_after'],2).'L' : '<span style="color:#ccc">—</span>' ?></td>
            <td class="num"><?= $r['unit_price']>0 ? '&#8369;'.number_format((float)$r['unit_price'],2) : '<span style="color:#ccc">—</span>' ?></td>
            <td class="num" style="font-weight:700;"><?= $r['total_value']>0 ? '&#8369;'.number_format((float)$r['total_value'],2) : '<span style="color:#ccc">—</span>' ?></td>
            <td style="font-size:11px;color:var(--gray);max-width:160px;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($r['notes']??'') ?>"><?= htmlspecialchars($r['notes']??'—') ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div id="fuelHistPagination" style="margin-top:10px;"></div>
  </div>
</div>
</div>

<!-- ══ MERCH TAB ══ -->
<div id="panelMerch" <?= $active_tab!=='merch'?'style="display:none;"':'' ?>>
<?php
  $total_qty_in  = array_sum(array_column($merch_rows,'qty_in'));
  $total_qty_out = array_sum(array_column($merch_rows,'qty_out'));
  $total_merch_value = array_sum(array_column($merch_rows,'total_value'));
?>
<div class="kpi-strip">
  <div class="kpi-card"><div class="kv in-val"><?= number_format($total_qty_in,0) ?></div><div class="kl">Total Qty In</div></div>
  <div class="kpi-card"><div class="kv out-val"><?= number_format($total_qty_out,0) ?></div><div class="kl">Total Qty Out</div></div>
  <div class="kpi-card"><div class="kv" style="color:var(--blue);"><?= count($merch_rows) ?></div><div class="kl">Total Transactions</div></div>
  <div class="kpi-card"><div class="kv" style="color:#7c3aed;">&#8369;<?= number_format($total_merch_value,2) ?></div><div class="kl">Total Value</div></div>
</div>
<div class="card">
  <div class="card-hd">
    <div class="card-hd-title"><i class="fas fa-boxes"></i> Merchandise Inventory Transaction Ledger</div>
  </div>
  <div style="padding:14px 18px;">
    <form method="GET" class="filter-bar">
      <input type="hidden" name="tab" value="merch">
      <div class="fg"><label>Start Date</label><input type="date" name="start" value="<?= htmlspecialchars($start_date) ?>"></div>
      <div class="fg"><label>End Date</label><input type="date" name="end" value="<?= htmlspecialchars($end_date) ?>"></div>
      <div style="margin-top:auto;"><button type="submit" class="btn primary" style="padding:7px 15px;font-size:13px;"><i class="fas fa-filter"></i> Filter</button></div>
    </form>
    <div class="table-wrap">
      <table class="hist" id="merchHistTable">
        <thead>
          <tr>
            <th>Transaction ID</th>
            <th>Date &amp; Time</th>
            <th>Product / SKU</th>
            <th>Type</th>
            <th class="num">Qty In</th>
            <th class="num">Qty Out</th>
            <th class="num">Balance After</th>
            <th class="num">Unit Price</th>
            <th class="num">Total Value</th>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($merch_rows)): ?>
          <tr><td colspan="10" style="text-align:center;padding:40px;color:var(--gray);">No merchandise transactions found for this period.</td></tr>
        <?php else: foreach ($merch_rows as $r):
          $is_in  = $r['qty_in'] > 0;
          $is_out = $r['qty_out'] > 0;
          $badge_cls = $is_in ? 'badge-in' : ($is_out ? 'badge-out' : 'badge-adj');
        ?>
          <tr>
            <td><span class="txn-id"><?= htmlspecialchars($r['txn_id']) ?></span></td>
            <td><?= $r['txn_date'] ? date('M d, Y H:i', strtotime($r['txn_date'])) : '—' ?></td>
            <td>
              <strong><?= htmlspecialchars($r['product_name']) ?></strong>
              <?php if (!empty($r['sku'])): ?>
                <br><span style="font-family:monospace;font-size:10px;background:#e8f4fd;color:#002F70;padding:1px 5px;border-radius:3px;"><?= htmlspecialchars($r['sku']) ?></span>
              <?php endif; ?>
            </td>
            <td><span class="badge <?= $badge_cls ?>"><?= htmlspecialchars($r['txn_type']) ?></span></td>
            <td class="num <?= $r['qty_in']>0?'in-val':'' ?>"><?= $r['qty_in']>0 ? number_format((float)$r['qty_in'],0) : '<span style="color:#ccc">—</span>' ?></td>
            <td class="num <?= $r['qty_out']>0?'out-val':'' ?>"><?= $r['qty_out']>0 ? number_format((float)$r['qty_out'],0) : '<span style="color:#ccc">—</span>' ?></td>
            <td class="num" style="font-weight:700;color:var(--blue);"><?= (isset($r['balance_after']) && $r['balance_after'] != 0) ? number_format((float)$r['balance_after'],0) : '<span style="color:#ccc">—</span>' ?></td>
            <td class="num"><?= $r['unit_price']>0 ? '&#8369;'.number_format((float)$r['unit_price'],2) : '<span style="color:#ccc">—</span>' ?></td>
            <td class="num" style="font-weight:700;"><?= $r['total_value']>0 ? '&#8369;'.number_format((float)$r['total_value'],2) : '<span style="color:#ccc">—</span>' ?></td>
            <td style="font-size:11px;color:var(--gray);max-width:140px;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($r['notes']??'') ?>"><?= htmlspecialchars($r['notes']??'—') ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div id="merchHistPagination" style="margin-top:10px;"></div>
  </div>
</div>
</div>

<script>
var activeTab = '<?= $active_tab ?>';
function switchTab(t) {
    activeTab = t;
    document.getElementById('panelFuel').style.display   = t==='fuel'   ? 'block' : 'none';
    document.getElementById('panelMerch').style.display  = t==='merch'  ? 'block' : 'none';
    document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.remove('active'); });
    document.querySelectorAll('.tab-btn')[t==='fuel'?0:1].classList.add('active');
    if (t==='fuel')  setupTablePagination('fuelHistTable','adminHistRowsLimit','fuelHistPagination',25);
    if (t==='merch') setupTablePagination('merchHistTable','adminHistRowsLimit','merchHistPagination',25);
}
function exportCSV() {
    var start = document.querySelector('input[name="start"]').value;
    var end   = document.querySelector('input[name="end"]').value;
    var ft    = document.querySelector('select[name="fuel_type"]')?.value || '';
    window.open('admin_inventory_history.php?export=csv&export_type='+activeTab+'&tab='+activeTab+'&start='+start+'&end='+end+'&fuel_type='+ft, '_blank');
}
document.addEventListener('DOMContentLoaded', function() {
    if (activeTab==='fuel')  setupTablePagination('fuelHistTable','adminHistRowsLimit','fuelHistPagination',25);
    if (activeTab==='merch') setupTablePagination('merchHistTable','adminHistRowsLimit','merchHistPagination',25);
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
