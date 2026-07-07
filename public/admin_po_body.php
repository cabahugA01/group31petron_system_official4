<?php
// ─── Body HTML: Summary cards + Tabbed Fuel & Merchandise PO Tables ─────────

// Split $display_pos into fuel and merch
$merch_pos = array_values(array_filter($display_pos, fn($p) => $p['po_type'] === 'merch'));
$fuel_pos  = array_values(array_filter($display_pos, fn($p) => $p['po_type'] === 'fuel'));

$active_tab = $_GET['tab'] ?? 'merch'; // default to merch tab
?>
<?php if ($flash_ok): ?><div class="flash-ok"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($flash_ok) ?></div><?php endif; ?>
<?php if ($flash_err): ?><div class="flash-err"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($flash_err) ?></div><?php endif; ?>

<!-- PAGE HEADER -->
<div class="po-int-head">  <div>  <h1><i class="fas fa-file-invoice"></i> Purchase Orders Oversight</h1>  <div class="sub">Monitor, review and manage all purchase orders for this station.</div>  </div>
</div>

<!-- SUMMARY CARDS -->
<div class="po-sum-grid">  <div class="po-sum-card blue">  <div><div class="po-sum-label">Total POs</div><div class="po-sum-val"><?= $cnt_total ?></div></div>  <div class="po-sum-icon" style="background:#e8f0fb;color:#002F70;"><i class="fas fa-file-invoice"></i></div>  </div>  <div class="po-sum-card orange">  <div><div class="po-sum-label">Pending</div><div class="po-sum-val" style="color:#fd7e14;"><?= $cnt_pending ?></div></div>  <div class="po-sum-icon" style="background:#fff3cd;color:#fd7e14;"><i class="fas fa-hourglass-half"></i></div>  </div>  <div class="po-sum-card green">  <div><div class="po-sum-label">Approved</div><div class="po-sum-val" style="color:#28a745;"><?= $cnt_approved ?></div></div>  <div class="po-sum-icon" style="background:#d4edda;color:#28a745;"><i class="fas fa-check-circle"></i></div>  </div>  <div class="po-sum-card teal">  <div><div class="po-sum-label">Delivered</div><div class="po-sum-val" style="color:#17a2b8;"><?= $cnt_delivered ?></div></div>  <div class="po-sum-icon" style="background:#d1ecf1;color:#17a2b8;"><i class="fas fa-truck"></i></div>  </div>  <div class="po-sum-card red">  <div><div class="po-sum-label">Cancelled</div><div class="po-sum-val" style="color:#dc3545;"><?= $cnt_cancelled ?></div></div>  <div class="po-sum-icon" style="background:#f8d7da;color:#dc3545;"><i class="fas fa-times-circle"></i></div>  </div>
</div>

<!-- FILTER BAR -->
<form method="GET" class="po-filter-bar" id="poFilterForm">  <input type="hidden" name="tab" id="filterTabInput" value="<?= htmlspecialchars($active_tab) ?>">  <input type="date" name="filter_date" value="<?= htmlspecialchars($filter_date) ?>" title="Filter by Date">  <select name="filter_status" onchange="this.form.submit()">  <option value="">All Statuses</option>  <option value="pending"  <?= $filter_status==='pending'  ?'selected':'' ?>>Pending</option>  <option value="approved"  <?= $filter_status==='approved'  ?'selected':'' ?>>Approved</option>  <option value="delivered" <?= $filter_status==='delivered' ?'selected':'' ?>>Delivered</option>  <option value="cancelled" <?= $filter_status==='cancelled' ?'selected':'' ?>>Cancelled</option>  </select>  <input type="text" name="search" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" placeholder="Search PO No. / Item..." style="width:180px;">  <button type="submit" class="po-ctrl-btn po-btn-exp" style="padding:7px 14px;"><i class="fas fa-search"></i> Search</button>  <?php if ($filter_date || $filter_status || ($filter_search !== '')): ?>  <a href="admin_purchase_orders.php?tab=<?= htmlspecialchars($active_tab) ?>" class="po-ctrl-btn po-btn-back"><i class="fas fa-times"></i> Clear</a>  <?php endif; ?>
</form>

<!-- ── TAB NAVIGATION ─────────────────────────────────────────────────────── -->
<div class="po-tabs-nav">  <button class="po-tab-btn <?= $active_tab === 'merch' ? 'active-merch' : '' ?>"  id="tabBtnMerch"  onclick="switchTab('merch')">  <i class="fas fa-boxes"></i>  Merchandise  <span class="po-tab-badge"><?= count($merch_pos) ?></span>  </button>  <button class="po-tab-btn <?= $active_tab === 'fuel' ? 'active-fuel' : '' ?>"  id="tabBtnFuel"  onclick="switchTab('fuel')">  <i class="fas fa-gas-pump"></i>  Fuel  <span class="po-tab-badge"><?= count($fuel_pos) ?></span>  </button>
</div>

<!-- ── TAB PANES ─────────────────────────────────────────────────────────── -->
<?php
function renderPoTable($pos, $table_id, $PENDING_ST, $APPROVED_ST, $DELIVERED_ST, $CANCELLED_ST, $is_fuel = false) {  $empty_label = $is_fuel ? 'fuel' : 'merchandise';
?>
<div class="po-table-wrap">  <table class="po-table" id="<?= $table_id ?>">  <thead>  <tr>  <th style="text-align:left;padding-left:18px;">PO No.</th>  <th><?= $is_fuel ? 'Fuel Type' : 'Product' ?></th>  <th>Supplier</th>  <th>Date Created</th>  <th>Quantity</th>  <th>Total Amount</th>  <th>Created By</th>  <th>Status</th>  <th style="width:160px;">Actions</th>  </tr>  </thead>  <tbody>  <?php if (empty($pos)): ?>  <tr class="no-paginate">  <td colspan="9" style="text-align:center;padding:48px;color:#94a3b8;">  <i class="fas <?= $is_fuel ? 'fa-gas-pump' : 'fa-boxes' ?>" style="font-size:2.5rem;display:block;margin-bottom:12px;opacity:.2;"></i>  No <?= $empty_label ?> purchase orders found.  </td>  </tr>  <?php else: ?>  <?php foreach ($pos as $po):  $st  = strtolower(trim($po['status'] ?? ''));  $isp = in_array($st, $PENDING_ST);  $isa = in_array($st, $APPROVED_ST);  $isd = $po['stock_in_done'] || in_array($st, $DELIVERED_ST);  $isc = in_array($st, $CANCELLED_ST);  if ($isd)  { $badgeCls='po-badge-delivered'; $badgeLbl='<i class="fas fa-truck"></i> Delivered'; }  elseif ($isc)  { $badgeCls='po-badge-cancelled'; $badgeLbl='<i class="fas fa-times-circle"></i> Cancelled'; }  elseif ($isa)  { $badgeCls='po-badge-approved';  $badgeLbl='<i class="fas fa-check-circle"></i> Approved'; }  elseif ($isp)  { $badgeCls='po-badge-pending';  $badgeLbl='<i class="fas fa-hourglass-half"></i> Pending Admin Approval'; }  else  { $badgeCls='po-badge-pending';  $badgeLbl=htmlspecialchars($po['status']); }  $ptype  = $po['po_type'];  $batch_id  = $po['batch_id'] ?? '';  $group_date = $po['group_date'] ?? ($po['date_created'] ? date('Y-m-d', strtotime($po['date_created'])) : '');  $date_fmt  = $po['date_created'] ? date('M d, Y h:i A', strtotime($po['date_created'])) : '—';  if (!empty($batch_id)) {  $print_url = "print_po_new.php?batch_id=" . urlencode($batch_id) . "&type=" . urlencode($ptype) . "&print=1";  } else {  $print_url = "print_po_new.php?date=" . urlencode($group_date) . "&type=" . urlencode($ptype) . "&print=1";  }  $json_po = htmlspecialchars(json_encode([  'po_no'  => $po['po_no'],  'batch_id'  => $batch_id,  'group_date'  => $group_date,  'supplier'  => $po['supplier'],  'category'  => $po['category'],  'date_created'=> $date_fmt,  'total_items' => $po['total_items'],  'total_amount'=> $po['total_amount'],  'created_by'  => trim($po['created_by'] ?? ''),  'status'  => $po['status'],  'notes'  => $po['notes'] ?? '',  'po_type'  => $ptype,  'detail'  => $po['detail'] ?? '',  ]), ENT_QUOTES, 'UTF-8');  ?>  <tr class="po-row">  <td style="text-align:left;padding-left:18px;">  <code style="font-weight:800;font-size:12px;color:#002F70;"><?= htmlspecialchars($po['po_no']) ?></code>  </td>  <td style="font-size:12px;font-weight:600;"><?= htmlspecialchars($po['detail']) ?></td>  <td style="font-size:12px;"><?= htmlspecialchars($po['supplier']) ?></td>  <td style="font-size:12px;white-space:nowrap;"><?= $date_fmt ?></td>  <td style="font-weight:700;"><?= number_format((float)($po['quantity'] ?? 1), 2) ?> <?= $ptype === 'fuel' ? 'L' : 'pcs' ?></td>  <td style="font-weight:700;">&#8369;<?= number_format((float)$po['total_amount'], 2) ?></td>  <td style="font-size:12px;"><?= htmlspecialchars(trim($po['created_by']) ?: '—') ?></td>  <td><span class="po-badge <?= $badgeCls ?>"><?= $badgeLbl ?></span></td>  <td>  <div style="display:flex;flex-direction:column;gap:5px;">  <a href="javascript:void(0)"  onclick="printPurchaseOrder('<?= htmlspecialchars($print_url, ENT_QUOTES) ?>')"  class="po-ctrl-btn po-btn-exp"  style="font-size:11px;padding:6px 10px;justify-content:center;text-decoration:none;display:flex;"  title="Print this PO">  <i class="fas fa-print"></i>&nbsp;Print PO  </a>  <?php if ($isp): ?>  <a href="javascript:void(0)"  class="po-ctrl-btn po-btn-fin"  style="font-size:11px;padding:6px 10px;justify-content:center;text-decoration:none;display:flex;"  onclick='openFinalizeSingle(<?= json_encode($group_date) ?>,<?= json_encode($ptype) ?>,<?= $json_po ?>)'  title="Finalize this PO">  <i class="fas fa-check"></i>&nbsp;Finalize  </a>  <a href="javascript:void(0)"  class="po-ctrl-btn po-btn-rej"  style="font-size:11px;padding:6px 10px;justify-content:center;text-decoration:none;display:flex;"  onclick="openReject('<?= $ptype ?>','<?= $group_date ?>')"  title="Reject this PO">  <i class="fas fa-times"></i>&nbsp;Reject  </a>  <?php endif; ?>  </div>  </td>  </tr>  <?php endforeach; ?>  <?php endif; ?>  </tbody>  </table>
</div>
<?php
} // end renderPoTable()
?>

<!-- MERCHANDISE TAB PANE -->
<div class="po-tab-pane <?= $active_tab === 'merch' ? 'active' : '' ?>" id="tabPaneMerch">
<?php renderPoTable($merch_pos, 'poTableMerch', $PENDING_ST, $APPROVED_ST, $DELIVERED_ST, $CANCELLED_ST, false); ?>
</div>

<!-- FUEL TAB PANE -->
<div class="po-tab-pane <?= $active_tab === 'fuel' ? 'active' : '' ?>" id="tabPaneFuel">
<?php renderPoTable($fuel_pos, 'poTableFuel', $PENDING_ST, $APPROVED_ST, $DELIVERED_ST, $CANCELLED_ST, true); ?>
</div>

<script>
function switchTab(tab) {  // Update pane visibility  document.getElementById('tabPaneMerch').classList.toggle('active', tab === 'merch');  document.getElementById('tabPaneFuel').classList.toggle('active', tab === 'fuel');  // Update button active classes  var btnMerch = document.getElementById('tabBtnMerch');  var btnFuel  = document.getElementById('tabBtnFuel');  btnMerch.className = 'po-tab-btn' + (tab === 'merch' ? ' active-merch' : '');  btnFuel.className  = 'po-tab-btn' + (tab === 'fuel'  ? ' active-fuel'  : '');  // Update hidden form input so filter form preserves tab on submit  var ti = document.getElementById('filterTabInput');  if (ti) ti.value = tab;  // Update URL without page reload  var url = new URL(window.location.href);  url.searchParams.set('tab', tab);  history.replaceState(null, '', url.toString());
}

function printPurchaseOrder(printUrl) {  // Create or reuse hidden iframe for seamless printing  let iframe = document.getElementById('printFramePO');  if (!iframe) {  iframe = document.createElement('iframe');  iframe.id = 'printFramePO';  iframe.style.position = 'absolute';  iframe.style.width = '0';  iframe.style.height = '0';  iframe.style.border = 'none';  iframe.style.visibility = 'hidden';  document.body.appendChild(iframe);  }  // Load print content and trigger print dialog  iframe.onload = function() {  try {  iframe.contentWindow.focus();  iframe.contentWindow.print();  } catch (e) {  // Fallback to opening in new window if iframe printing fails  window.open(printUrl, '_blank');  }  };  iframe.src = printUrl;
}

function exportCurrentTabTable() {  var tab = document.getElementById('filterTabInput').value;  var tableId = (tab === 'fuel') ? 'poTableFuel' : 'poTableMerch';  var label  = (tab === 'fuel') ? 'fuel_po.xls' : 'merch_po.xls';  if (typeof exportTableToExcel === 'function') exportTableToExcel(tableId, label);
}

function printCurrentTabTable() {  var tab = document.getElementById('filterTabInput').value;  var tableId = (tab === 'fuel') ? 'poTableFuel' : 'poTableMerch';  var label  = (tab === 'fuel') ? 'Fuel POs' : 'Merchandise POs';  if (typeof exportTableToPDF === 'function') exportTableToPDF(tableId, label);
}

// Auto-dismiss flash messages
setTimeout(function(){  document.querySelectorAll('.flash-ok,.flash-err').forEach(function(el){ el.style.display='none'; });
}, 5000);

// Run pagination for active table
if (typeof setupTablePagination === 'function') {  setupTablePagination('poTableMerch', null, 'poPaginationMerch', 15);  setupTablePagination('poTableFuel',  null, 'poPaginationFuel',  15);
}
</script>

<div id="poPaginationMerch" style="padding:6px 0;"></div>
<div id="poPaginationFuel"  style="padding:6px 0;"></div>
