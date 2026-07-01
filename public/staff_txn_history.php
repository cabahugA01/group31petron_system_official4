<?php /* Transaction History section — included by staff_transactions_hub.php */ ?>
<style>
.th-kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin:18px 0 0}
.th-kpi{background:#fff;border-radius:10px;padding:18px 20px;border:1px solid #e2e8f0;box-shadow:0 1px 6px rgba(0,0,0,.07)}
.th-kpi-val{font-size:26px;font-weight:800;line-height:1.2}
.th-kpi-lbl{font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px}
.th-kpi-sub{font-size:11px;color:#94a3b8;margin-top:3px}
.th-filter-bar{background:#fff;border-radius:10px;padding:14px 16px;margin:14px 0;box-shadow:0 1px 6px rgba(0,0,0,.06);display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end}
.th-filter-bar label{font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:3px}
.th-filter-bar input,.th-filter-bar select{height:32px;padding:0 8px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;color:#1e293b;background:#fff}
.th-filter-bar input[type=text]{min-width:180px}
.th-filter-grp{display:flex;flex-direction:column}
.th-flt-btn{height:32px;padding:0 14px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;border:none;display:inline-flex;align-items:center;gap:5px}
.th-flt-search{background:#002F70;color:#fff}
.th-flt-reset{background:#f1f5f9;color:#475569;border:1px solid #cbd5e1}
.th-badge{display:inline-block;padding:2px 8px;border-radius:5px;font-size:10px;font-weight:700}
.th-export-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:6px}
.hist-modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center}
.hist-modal-bg.open{display:flex}
.hist-modal{background:#fff;border-radius:14px;max-width:720px;width:96%;max-height:90vh;overflow-y:auto;padding:28px;position:relative}
.hist-modal h2{font-size:16px;font-weight:800;color:#002F70;margin-bottom:16px}
.hist-sec-title{font-size:12px;font-weight:800;color:#002F70;text-transform:uppercase;letter-spacing:.5px;border-bottom:2px solid #e2e8f0;padding-bottom:6px;margin:14px 0 8px}
.hist-row{display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid #f1f5f9;font-size:13px}
.hist-row .k{color:#64748b;font-weight:500}.hist-row .v{font-weight:600;text-align:right}
.hist-items-tbl{width:100%;border-collapse:collapse;font-size:12px;margin-top:6px}
.hist-items-tbl th{background:#f8fafc;padding:7px 10px;text-align:left;font-weight:700;font-size:11px;border-bottom:2px solid #e2e8f0}
.hist-items-tbl td{padding:7px 10px;border-bottom:1px solid #f1f5f9}
/* Inline items column */
.hist-item-chip{display:inline-flex;align-items:center;gap:4px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:4px;padding:2px 7px;font-size:10px;font-weight:600;color:#374151;margin:1px 2px 1px 0;white-space:nowrap}
.hist-item-chip.svc{background:#fffbeb;border-color:#fde68a;color:#92400e}
.hist-item-chip .chip-qty{background:#002F70;color:#fff;border-radius:3px;padding:0 4px;font-size:9px;margin-left:3px}
.hist-expand-row td{background:#f8fafc;border-top:none;padding:0}
.hist-expand-inner{padding:12px 16px;border-top:2px solid #e2e8f0}
.hist-expand-tbl{width:100%;border-collapse:collapse;font-size:11px}
.hist-expand-tbl th{padding:5px 10px;text-align:left;font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid #e2e8f0}
.hist-expand-tbl td{padding:6px 10px;border-bottom:1px solid #f1f5f9}
.hist-expand-tbl tr:last-child td{border-bottom:none}
.hist-row-main{cursor:pointer}
.hist-row-main:hover td{background:#f8faff !important}
</style>

<div class="txn-section-header">
  <div class="txn-section-title">
    <div>
      <h1><i class="fas fa-history" style="color:#002F70;margin-right:8px;font-size:20px"></i>Transaction History</h1>
      <p>Your encoded transactions — <?= htmlspecialchars(date('F Y')) ?></p>
    </div>
  </div>
  <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;">
    <button type="button" onclick="window.location.href='staff_transactions_hub.php?section=merchandise&active_tab=merchandise'" class="txn-btn secondary" title="Back to Merchandise/Service Transaction">
      <i class="fas fa-arrow-left"></i> <span>Back</span>
    </button>
    <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;">
      <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'excel'])) ?>" class="txn-btn success" style="text-decoration:none; display:inline-flex; align-items:center; gap:5px;"><i class="fas fa-file-excel"></i> Excel</a>
      <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'pdf'])) ?>" target="_blank" class="txn-btn danger" style="text-decoration:none; display:inline-flex; align-items:center; gap:5px;"><i class="fas fa-file-pdf"></i> PDF</a>
      <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="txn-btn primary" style="text-decoration:none; display:inline-flex; align-items:center; gap:5px;"><i class="fas fa-file-csv"></i> CSV</a>
    </div>
  </div>
</div>

<!-- KPI Cards -->
<div class="th-kpi-grid">
  <div class="th-kpi">
    <div class="th-kpi-lbl"><i class="fas fa-receipt"></i> Total Transactions</div>
    <div class="th-kpi-val" style="color:#002F70"><?= number_format($hist_kpi_total) ?></div>
    <div class="th-kpi-sub">This period</div>
  </div>
  <div class="th-kpi">
    <div class="th-kpi-lbl"><i class="fas fa-tools"></i> Job Orders</div>
    <div class="th-kpi-val" style="color:#b45309"><?= number_format($hist_kpi_jo) ?></div>
    <div class="th-kpi-sub">JO + Combined</div>
  </div>
  <div class="th-kpi">
    <div class="th-kpi-lbl"><i class="fas fa-box"></i> Merchandise</div>
    <div class="th-kpi-val" style="color:#15803d"><?= number_format($hist_kpi_merch) ?></div>
    <div class="th-kpi-sub">Products only</div>
  </div>
  <div class="th-kpi">
    <div class="th-kpi-lbl"><i class="fas fa-peso-sign"></i> Total Sales</div>
    <div class="th-kpi-val" style="color:#7c3aed">&#8369;<?= number_format($hist_kpi_sales, 2) ?></div>
    <div class="th-kpi-sub">Gross encoded</div>
  </div>
</div>

<!-- Filters -->
<?php
$_hbase = ['section'=>'history'];
$_hq    = function(array $extra=[]) use ($_hbase, $hist_filter_date_from, $hist_filter_date_to, $hist_filter_type, $hist_filter_ctype, $hist_filter_pay, $hist_filter_pstatus, $hist_search) {
    $p = $_hbase + ['date_from'=>$hist_filter_date_from,'date_to'=>$hist_filter_date_to,'txn_type'=>$hist_filter_type,'cust_type'=>$hist_filter_ctype,'payment'=>$hist_filter_pay,'pstatus'=>$hist_filter_pstatus,'hsearch'=>$hist_search];
    return http_build_query(array_filter(array_merge($p, $extra)));
};
?>
<form method="get" action="" class="th-filter-bar">
  <input type="hidden" name="section" value="history">
  <div class="th-filter-grp">
    <label>From</label>
    <input type="date" name="date_from" value="<?= htmlspecialchars($hist_filter_date_from) ?>">
  </div>
  <div class="th-filter-grp">
    <label>To</label>
    <input type="date" name="date_to" value="<?= htmlspecialchars($hist_filter_date_to) ?>">
  </div>
  <div class="th-filter-grp">
    <label>Type</label>
    <select name="txn_type">
      <option value="">All Types</option>
      <option value="merchandise" <?= $hist_filter_type==='merchandise'?'selected':'' ?>>Merchandise</option>
      <option value="job_order" <?= $hist_filter_type==='job_order'?'selected':'' ?>>Job Order</option>
      <option value="combined" <?= $hist_filter_type==='combined'?'selected':'' ?>>Combined</option>
    </select>
  </div>
  <div class="th-filter-grp">
    <label>Customer</label>
    <select name="cust_type">
      <option value="">All</option>
      <option value="walkin" <?= $hist_filter_ctype==='walkin'?'selected':'' ?>>Walk-in</option>
      <option value="registered" <?= $hist_filter_ctype==='registered'?'selected':'' ?>>Registered</option>
    </select>
  </div>
  <div class="th-filter-grp">
    <label>Payment Method</label>
    <select name="payment">
      <option value="">All Methods</option>
      <?php foreach(['Cash','Credit Card','Debit Card','GCash','Maya','Petron Fleet Card','Credit Account'] as $_pm): ?>
      <option value="<?= $_pm ?>" <?= $hist_filter_pay===$_pm?'selected':'' ?>><?= $_pm ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="th-filter-grp">
    <label>Payment Status</label>
    <select name="pstatus">
      <option value="">All Statuses</option>
      <option value="paid" <?= strtolower($hist_filter_pstatus)==='paid'?'selected':'' ?>>Paid</option>
      <option value="pending" <?= strtolower($hist_filter_pstatus)==='pending'?'selected':'' ?>>Pending</option>
      <option value="partially paid" <?= strtolower($hist_filter_pstatus)==='partially paid'?'selected':'' ?>>Partially Paid</option>
    </select>
  </div>
  <div class="th-filter-grp">
    <label>Search</label>
    <input type="text" name="hsearch" placeholder="Txn ID / Customer / Plate" value="<?= htmlspecialchars($hist_search) ?>" style="width:200px">
  </div>
  <button type="submit" class="txn-btn primary" style="min-width:0; height:32px; padding:0 14px; font-size:12px; border-radius:6px"><i class="fas fa-search"></i> Filter</button>
  <a href="?section=history" class="txn-btn secondary" style="min-width:0; height:32px; padding:0 14px; font-size:12px; border-radius:6px; display:inline-flex; align-items:center; gap:6px"><i class="fas fa-times"></i> Reset</a>
</form>

<!-- Table -->
<div class="txn-card" style="margin-top:6px">
  <div class="txn-card-header">
    <i class="fas fa-table" style="color:#002F70"></i>
    <h3>All Transactions <span style="font-size:12px;font-weight:400;color:#64748b">(<?= number_format($hist_kpi_total) ?> records)</span></h3>
  </div>
  <div class="txn-card-body" style="padding:0">
<?php if (empty($recent_merch)): ?>
    <div style="text-align:center;padding:48px;color:#94a3b8">
      <i class="fas fa-receipt" style="font-size:36px;display:block;margin-bottom:12px"></i>
      No transactions found for the selected period.
    </div>
<?php else: ?>
    <div style="overflow-x:auto">
    <table class="txn-table" id="histTbl" style="width:100%;min-width:1000px">
      <thead><tr>
        <th style="width:140px">Transaction ID</th>
        <th style="width:75px">Cust. Type</th>
        <th style="width:120px">Customer Name</th>
        <th style="width:90px">Txn Type</th>
        <th>Items Sold</th>
        <th style="width:100px;text-align:right">Total Amount</th>
        <th style="width:100px">Payment Method</th>
        <th style="width:90px">Pay. Status</th>
        <th style="width:90px">Txn Status</th>
        <th style="width:125px">Date & Time</th>
        <th style="width:110px" class="no-export">Actions</th>
      </tr></thead>
      <tbody id="histTbody">
      <?php
      // Pre-fetch all items for displayed transactions in ONE query
      $ht_ids = array_column($recent_merch, 'id');
      $ht_items_map = [];
      if (!empty($ht_ids)) {
          try {
              $in_pl = implode(',', array_map('intval', $ht_ids));
              $itm_stmt = $pdo->query("
                  SELECT transaction_id, product_name, quantity, unit_price, subtotal,
                         COALESCE(item_type,'merchandise') AS item_type,
                         COALESCE(category,'') AS category,
                         COALESCE(size_variant,'') AS size_variant
                  FROM merchandise_transaction_items
                  WHERE transaction_id IN ($in_pl)
                  ORDER BY transaction_id, id ASC
              ");
              foreach ($itm_stmt->fetchAll(PDO::FETCH_ASSOC) as $itm_row) {
                  $ht_items_map[(int)$itm_row['transaction_id']][] = $itm_row;
              }
          } catch (Exception $e) { $ht_items_map = []; }
      }
      foreach ($recent_merch as $ht):
          $ht_id    = (int)$ht['id'];
          $ht_type  = $ht['transaction_type'] ?? 'merchandise';
          $ht_ctype = ((int)($ht['credit_customer_id'] ?? 0) > 0) ? 'Registered' : 'Walk-in';
          $ht_ps    = strtolower(trim($ht['payment_status'] ?? 'pending'));
          if ($ht_ps === 'paid') { $psc='#16a34a'; }
          elseif (in_array($ht_ps,['partially paid','partial payment'])) { $psc='#d97706'; }
          elseif (in_array($ht_ps,['credit account','credit transaction','credit'])) { $psc='#7c3aed'; }
          else { $psc='#ea580c'; }
          if ($ht_type==='combined') { $tc='#7c3aed'; $tb='#f3e8ff'; $tl='Combined'; }
          elseif ($ht_type==='job_order') { $tc='#b45309'; $tb='#fffbeb'; $tl='Job Order'; }
          else { $tc='#15803d'; $tb='#f0fdf4'; $tl='Merchandise'; }
          $ht_date = '';
          if (!empty($ht['transaction_date'])) {
              try { $ht_date = (new DateTime($ht['transaction_date']))->format('M j, Y g:i A'); } catch(Exception $e){}
          }
          $ht_tid   = htmlspecialchars($ht['transaction_id'] ?? ('#'.$ht_id));
          $ht_cname = htmlspecialchars($ht['customer_name'] ?? 'Walk-in Customer');
          $row_items = $ht_items_map[$ht_id] ?? [];
          // Fallback: if no items table rows exist, build from legacy sku column
          if (empty($row_items) && !empty($ht['item_sku'])) {
              $row_items = [[
                  'item_type'    => ($ht_type === 'job_order') ? 'service' : 'merchandise',
                  'product_name' => $ht['item_sku'],
                  'quantity'     => $ht['quantity'] ?? 1,
                  'unit_price'   => $ht['unit_price'] ?? 0,
                  'subtotal'     => $ht['total_amount'] ?? 0,
                  'category'     => '',
                  'size_variant' => '',
              ]];
          }
          $expand_id = 'hte_'.$ht_id;
      ?>
      <tr class="hist-row-main" onclick="toggleHistExpand('<?= $expand_id ?>')">
        <td style="font-size:11px;font-weight:700;color:#002F70;font-family:monospace"><?= $ht_tid ?></td>
        <td><span style="font-size:11px;font-weight:600;color:<?= $ht_ctype==='Registered'?'#002F70':'#64748b' ?>"><?= $ht_ctype ?></span></td>
        <td style="font-weight:600;font-size:12px"><?= $ht_cname ?></td>
        <td><span style="background:<?= $tb ?>;color:<?= $tc ?>;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:700"><?= $tl ?></span></td>
        <td>
          <?php if (empty($row_items)): ?>
            <span style="color:#94a3b8;font-size:11px">—</span>
          <?php else: ?>
            <?php foreach ($row_items as $ri): ?>
              <?php $is_svc = ($ri['item_type'] === 'service'); ?>
              <span class="hist-item-chip<?= $is_svc ? ' svc' : '' ?>">
                <?php if ($is_svc): ?><i class="fas fa-wrench" style="font-size:9px"></i><?php else: ?><i class="fas fa-box" style="font-size:9px"></i><?php endif; ?>
                <?= htmlspecialchars($ri['product_name']) ?>
                <?php if (!$is_svc && (float)$ri['quantity'] > 0): ?>
                  <span class="chip-qty">x<?= (int)$ri['quantity'] ?></span>
                <?php endif; ?>
              </span>
            <?php endforeach; ?>
            <i class="fas fa-chevron-down" style="font-size:9px;color:#94a3b8;margin-left:4px" id="<?= $expand_id ?>_icon"></i>
          <?php endif; ?>
        </td>
        <td style="text-align:right;font-weight:700;color:#002F70">&#8369;<?= number_format((float)$ht['total_amount'],2) ?></td>
        <td style="font-size:12px"><?= htmlspecialchars($ht['payment_method'] ?? '—') ?></td>
        <td><span style="color:<?= $psc ?>;font-size:11px;font-weight:700"><?= htmlspecialchars($ht['payment_status'] ?? 'Pending') ?></span></td>
        <?php
        $val_status = strtolower(trim($ht['validation_status'] ?? 'official'));
        if (in_array($val_status, ['completed', 'approved', 'official', 'verified'])) {
            $v_badge_color = '#16a34a'; $v_badge_bg = '#f0fdf4'; $v_badge_border = '#bbf7d0'; $v_badge_label = 'Completed';
        } elseif ($val_status === 'adjusted') {
            $v_badge_color = '#d97706'; $v_badge_bg = '#fffbeb'; $v_badge_border = '#fde68a'; $v_badge_label = 'Adjusted';
        } elseif ($val_status === 'voided') {
            $v_badge_color = '#dc2626'; $v_badge_bg = '#fef2f2'; $v_badge_border = '#fecaca'; $v_badge_label = 'Voided';
        } else {
            $v_badge_color = '#16a34a'; $v_badge_bg = '#f0fdf4'; $v_badge_border = '#bbf7d0'; $v_badge_label = 'Completed';
        }
        ?>
        <td>
          <span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;color:<?= $v_badge_color ?>;background:<?= $v_badge_bg ?>;border:1px solid <?= $v_badge_border ?>;white-space:nowrap">
            <?= $v_badge_label ?>
          </span>
        </td>
        <td style="font-size:11px;color:#64748b;white-space:nowrap"><?= $ht_date ?: '—' ?></td>
        <td class="no-export" onclick="event.stopPropagation()">
          <button onclick="openHistModal(<?= $ht_id ?>)" class="txn-btn primary" style="min-width:0;height:28px;padding:0 10px;font-size:11px;border-radius:5px" title="View Full Details">
            <i class="fas fa-eye"></i> View
          </button>
        </td>
      </tr>
      <?php if (!empty($row_items)): ?>
      <tr class="hist-expand-row" id="<?= $expand_id ?>" style="display:none">
        <td colspan="11" class="no-export">
          <div class="hist-expand-inner">
            <?php
            $svc_items   = array_filter($row_items, fn($i) => $i['item_type'] === 'service');
            $merch_items = array_filter($row_items, fn($i) => $i['item_type'] !== 'service');
            ?>
            <?php if (!empty($svc_items)): ?>
            <div style="font-size:11px;font-weight:800;color:#b45309;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px">
              <i class="fas fa-tools"></i> Services / Job Order
            </div>
            <table class="hist-expand-tbl" style="margin-bottom:10px">
              <thead><tr>
                <th>Service</th>
                <th>Category</th>
                <th style="text-align:right">Fee</th>
              </tr></thead>
              <tbody>
              <?php foreach ($svc_items as $si): ?>
              <tr>
                <td style="font-weight:600"><?= htmlspecialchars($si['product_name']) ?></td>
                <td style="color:#64748b"><?= htmlspecialchars($si['category'] ?: '—') ?></td>
                <td style="text-align:right;font-weight:700;color:#002F70">&#8369;<?= number_format((float)$si['subtotal'],2) ?></td>
              </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
            <?php endif; ?>
            <?php if (!empty($merch_items)): ?>
            <div style="font-size:11px;font-weight:800;color:#15803d;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px">
              <i class="fas fa-box"></i> Merchandise Products
            </div>
            <table class="hist-expand-tbl">
              <thead><tr>
                <th>Product</th>
                <th>Size/Variant</th>
                <th style="text-align:center">Qty</th>
                <th style="text-align:right">Unit Price</th>
                <th style="text-align:right">Subtotal</th>
              </tr></thead>
              <tbody>
              <?php foreach ($merch_items as $mi): ?>
              <tr>
                <td style="font-weight:600"><?= htmlspecialchars($mi['product_name']) ?></td>
                <td style="color:#64748b;font-size:11px"><?= htmlspecialchars($mi['size_variant'] ?: '—') ?></td>
                <td style="text-align:center;font-weight:700"><?= (int)$mi['quantity'] ?></td>
                <td style="text-align:right;color:#475569">&#8369;<?= number_format((float)$mi['unit_price'],2) ?></td>
                <td style="text-align:right;font-weight:700;color:#002F70">&#8369;<?= number_format((float)$mi['subtotal'],2) ?></td>
              </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <!-- Pagination -->
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 16px;border-top:1px solid #e2e8f0;flex-wrap:wrap">
      <div style="display:flex;align-items:center;gap:7px">
        <label style="font-size:12px;white-space:nowrap">Rows per page:</label>
        <select id="histPerPage" onchange="histChangePerPage()" class="pag-select">
          <option value="10" selected>10</option><option value="20">20</option>
          <option value="30">30</option><option value="50">50</option>
        </select>
      </div>
      <div style="display:flex;align-items:center;gap:8px">
        <button id="histPrevBtn" onclick="histGoPage(histState.page-1)" class="pag-btn"><i class="fas fa-chevron-left"></i></button>
        <span id="histPageLabel" style="font-size:13px;color:#495057;white-space:nowrap">Page 1 of 1</span>
        <button id="histNextBtn" onclick="histGoPage(histState.page+1)" class="pag-btn"><i class="fas fa-chevron-right"></i></button>
      </div>
    </div>
<?php endif; ?>
  </div>
</div>

<!-- View Details Modal -->
<div class="hist-modal-bg" id="histModalBg" onclick="if(event.target===this)closeHistModal()">
  <div class="hist-modal" id="histModal">
    <button onclick="closeHistModal()" style="position:absolute;top:14px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;color:#64748b">&times;</button>
    <h2><i class="fas fa-receipt" style="color:#002F70;margin-right:8px"></i>Transaction Details</h2>
    <div id="histModalContent" style="color:#475569;font-size:13px">Loading...</div>
  </div>
</div>

<script>
// Expand/collapse inline item rows
window.toggleHistExpand = function(id) {
  var row = document.getElementById(id);
  var icon = document.getElementById(id + '_icon');
  if (!row) return;
  var open = row.style.display !== 'none';
  row.style.display = open ? 'none' : '';
  if (icon) icon.style.transform = open ? '' : 'rotate(180deg)';
};

(function(){
// Pagination — only count main rows (not expand rows)
var histState={page:1,per_page:10};
function histRender(){
  // Collapse all expand rows first
  document.querySelectorAll('.hist-expand-row').forEach(function(r){r.style.display='none';});
  document.querySelectorAll('[id$="_icon"]').forEach(function(ic){ic.style.transform='';});
  var rows=Array.from(document.querySelectorAll('#histTbody tr.hist-row-main'));
  var pp=histState.per_page,p=histState.page,tot=rows.length;
  var tp=Math.max(1,Math.ceil(tot/pp));
  if(p>tp)histState.page=p=tp;
  // Hide all main rows and their paired expand rows
  rows.forEach(function(r){
    r.style.display='none';
    var next=r.nextElementSibling;
    if(next&&next.classList.contains('hist-expand-row'))next.style.display='none';
  });
  // Show current page main rows only
  rows.slice((p-1)*pp,p*pp).forEach(function(r){r.style.display='';});
  var lbl=document.getElementById('histPageLabel');
  if(lbl)lbl.textContent='Page '+p+' of '+tp;
  var prev=document.getElementById('histPrevBtn'),next=document.getElementById('histNextBtn');
  if(prev){prev.disabled=(p<=1);prev.style.opacity=(p<=1)?'0.4':'1';}
  if(next){next.disabled=(p>=tp);next.style.opacity=(p>=tp)?'0.4':'1';}
}
window.histState=histState;
window.histGoPage=function(p){var rows=document.querySelectorAll('#histTbody tr.hist-row-main');var tp=Math.max(1,Math.ceil(rows.length/histState.per_page));if(p<1||p>tp)return;histState.page=p;histRender();};
window.histChangePerPage=function(){var s=document.getElementById('histPerPage');if(s)histState.per_page=parseInt(s.value);histState.page=1;histRender();};
histRender();
})();

function openHistModal(mtId){
  document.getElementById('histModalBg').classList.add('open');
  document.getElementById('histModalContent').innerHTML='<div style="text-align:center;padding:32px"><i class="fas fa-spinner fa-spin" style="font-size:24px;color:#002F70"></i></div>';
  fetch('../backend/api/merchandise_transactions.php?action=get_transaction_details&mt_id='+mtId)
    .then(function(r){return r.json();})
    .then(function(d){
      if(!d.success){document.getElementById('histModalContent').innerHTML='<p style="color:#dc2626">'+d.error+'</p>';return;}
      var t=d.transaction,items=d.items||[];
      var ps=t.payment_status||'—',pm=t.payment_method||'—';
      var txnType=t.transaction_type||'merchandise';
      var isJO=(txnType==='job_order'||txnType==='combined');
      var isMerch=(txnType==='merchandise'||txnType==='combined');
      var svcItems=items.filter(function(i){return i.item_type==='service';});
      var merItems=items.filter(function(i){return i.item_type!=='service';});
      var html='';
      // Transaction Info
      html+='<div class="hist-sec-title"><i class="fas fa-info-circle"></i> Transaction Information</div>';
      html+=row('Transaction ID','<strong style="color:#002F70;font-family:monospace">'+(t.transaction_id||'—')+'</strong>');
      html+=row('Type','<span style="font-weight:700;text-transform:capitalize">'+(txnType.replace('_',' '))+'</span>');
      html+=row('Customer Type',((t.credit_customer_id&&t.credit_customer_id>0)?'Registered':'Walk-in'));
      html+=row('Customer Name',t.customer_name||'Walk-in Customer');
      if(t.contact_number)html+=row('Contact',t.contact_number);
      // Vehicle
      if(isJO){
        html+='<div class="hist-sec-title"><i class="fas fa-car"></i> Vehicle Information</div>';
        if(t.vehicle_type)html+=row('Vehicle Type',t.vehicle_type);
        if(t.vehicle_plate)html+=row('Plate Number','<strong>'+t.vehicle_plate+'</strong>');
        if(t.mechanic_name)html+=row('Assigned Mechanic',t.mechanic_name);
      }
      // Services
      if(isJO&&svcItems.length){
        html+='<div class="hist-sec-title"><i class="fas fa-tools"></i> Services</div>';
        html+='<table class="hist-items-tbl"><thead><tr><th>Service</th><th>Category</th><th style="text-align:right">Fee</th></tr></thead><tbody>';
        svcItems.forEach(function(s){
          html+='<tr><td>'+esc(s.product_name)+'</td><td>'+esc(s.category||'—')+'</td><td style="text-align:right;font-weight:700">&#8369;'+fmt(s.subtotal)+'</td></tr>';
        });
        html+='</tbody></table>';
      }
      // Merchandise
      if(isMerch&&merItems.length){
        html+='<div class="hist-sec-title"><i class="fas fa-box"></i> Merchandise Items</div>';
        html+='<table class="hist-items-tbl"><thead><tr><th>Product</th><th style="text-align:right">Qty</th><th style="text-align:right">Unit Price</th><th style="text-align:right">Subtotal</th></tr></thead><tbody>';
        merItems.forEach(function(m){
          html+='<tr><td>'+esc(m.product_name)+(m.size_variant?'<br><small style="color:#94a3b8">'+esc(m.size_variant)+'</small>':'')+'</td><td style="text-align:right">'+m.quantity+'</td><td style="text-align:right">&#8369;'+fmt(m.unit_price)+'</td><td style="text-align:right;font-weight:700">&#8369;'+fmt(m.subtotal)+'</td></tr>';
        });
        html+='</tbody></table>';
      }
      // Payment
      html+='<div class="hist-sec-title"><i class="fas fa-credit-card"></i> Payment Information</div>';
      html+=row('Payment Method',pm);
      html+=row('Payment Status','<strong>'+ps+'</strong>');
      if(t.amount_paid>0)html+=row('Amount Paid','&#8369;'+fmt(t.amount_paid));
      if(pm==='Cash'&&t.change_amount>0)html+=row('Change','&#8369;'+fmt(t.change_amount));
      if(t.balance_due>0)html+=row('Balance Due','<span style="color:#dc2626;font-weight:700">&#8369;'+fmt(t.balance_due)+'</span>');
      if(t.subtotal_amount)html+=row('Subtotal','&#8369;'+fmt(t.subtotal_amount));
      if(t.vat_amount>0)html+=row('VAT (12%)','&#8369;'+fmt(t.vat_amount));
      html+=row('Total Amount','<strong style="font-size:15px;color:#002F70">&#8369;'+fmt(t.total_amount)+'</strong>');
      // System Info
      html+='<div class="hist-sec-title"><i class="fas fa-user-clock"></i> System Information</div>';
      var dt=t.transaction_date||t.created_at||'—';
      try{if(dt&&dt!=='—')dt=new Date(dt).toLocaleString('en-US',{dateStyle:'medium',timeStyle:'short'});}catch(e){}
      html+=row('Date & Time',dt);
      html+=row('Staff Encoder',t.encoder_name||'—');
      document.getElementById('histModalContent').innerHTML=html;
    })
    .catch(function(){document.getElementById('histModalContent').innerHTML='<p style="color:#dc2626">Failed to load transaction details.</p>';});
}
function closeHistModal(){document.getElementById('histModalBg').classList.remove('open');}
function row(k,v){return '<div class="hist-row"><span class="k">'+k+'</span><span class="v">'+v+'</span></div>';}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;');}
function fmt(n){return parseFloat(n||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});}
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeHistModal();});
</script>
