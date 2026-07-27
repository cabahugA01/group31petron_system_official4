<?php /* Transaction History section — included by staff_transactions_hub.php */ ?>
<style>
.th-kpi-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 12px;
  margin: 16px 0 0;
}
.th-kpi {
  background: #fff;
  border-radius: 10px;
  padding: 16px 18px;
  border: 1px solid #e2e8f0;
  box-shadow: 0 1px 6px rgba(0,0,0,.06);
}
.th-kpi-val {
  font-size: 24px;
  font-weight: 800;
  line-height: 1.2;
  margin-top: 4px;
}
.th-kpi-lbl {
  font-size: 11px;
  font-weight: 700;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: .4px;
  display: flex;
  align-items: center;
  gap: 6px;
}
.th-kpi-sub {
  font-size: 11px;
  color: #94a3b8;
  margin-top: 3px;
}
.th-filter-bar {
  background: #fff;
  border-radius: 10px;
  padding: 14px 16px;
  margin: 14px 0;
  box-shadow: 0 1px 6px rgba(0,0,0,.06);
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: flex-end;
}
.th-filter-bar label {
  font-size: 11px;
  font-weight: 700;
  color: #475569;
  display: block;
  margin-bottom: 4px;
}
.th-filter-bar input, .th-filter-bar select {
  height: 34px;
  padding: 0 10px;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  font-size: 12px;
  color: #1e293b;
  background: #fff;
}
.th-filter-grp {
  display: flex;
  flex-direction: column;
}
.th-badge {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 5px;
  font-size: 10px;
  font-weight: 700;
}
.hist-modal-bg {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.5);
  z-index: 9000;
  align-items: center;
  justify-content: center;
}
.hist-modal-bg.open {
  display: flex;
}
.hist-modal {
  background: #fff;
  border-radius: 14px;
  max-width: 720px;
  width: 96%;
  max-height: 90vh;
  overflow-y: auto;
  padding: 28px;
  position: relative;
}
.hist-modal h2 {
  font-size: 16px;
  font-weight: 800;
  color: #002F70;
  margin-bottom: 16px;
}
.hist-sec-title {
  font-size: 12px;
  font-weight: 800;
  color: #002F70;
  text-transform: uppercase;
  letter-spacing: .5px;
  border-bottom: 2px solid #e2e8f0;
  padding-bottom: 6px;
  margin: 14px 0 8px;
}
.hist-row {
  display: flex;
  justify-content: space-between;
  padding: 5px 0;
  border-bottom: 1px solid #f1f5f9;
  font-size: 13px;
}
.hist-row .k { color: #64748b; font-weight: 500; }
.hist-row .v { font-weight: 600; text-align: right; }
.hist-items-tbl {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
  margin-top: 6px;
}
#histTbl {
  font-size: 11px;
  width: 100%;
  table-layout: fixed;
}
#histTbl th {
  padding: 8px 6px;
  font-size: 10.5px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  background: #f8fafc;
  border-bottom: 2px solid #e2e8f0;
  color: #475569;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.3px;
}
#histTbl td {
  padding: 8px 6px;
  word-break: break-word;
  overflow-wrap: break-word;
  white-space: normal;
  font-size: 11px;
  vertical-align: middle;
  border-bottom: 1px solid #f1f5f9;
}
.hist-item-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  padding: 2px 7px;
  font-size: 10px;
  font-weight: 600;
  color: #374151;
  margin: 1px 2px 1px 0;
}
.hist-item-chip.svc {
  background: #fffbeb;
  border-color: #fde68a;
  color: #92400e;
}
.hist-action-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
  padding: 4px 10px;
  border-radius: 5px;
  font-size: 11px;
  font-weight: 600;
  cursor: pointer;
  border: 1.5px solid #003d7a !important;
  background: #ffffff !important;
  color: #003d7a !important;
  transition: all 0.15s;
  text-decoration: none;
  white-space: nowrap;
}
.btn-view-act {
  border-color: #003d7a !important;
  background: #ffffff !important;
  color: #003d7a !important;
}
.btn-view-act:hover {
  background: #f0f7ff !important;
  color: #00264D !important;
  border-color: #00264D !important;
}
.btn-print-act {
  border-color: #003d7a !important;
  background: #ffffff !important;
  color: #003d7a !important;
}
.btn-print-act:hover {
  background: #f0f7ff !important;
  color: #00264D !important;
  border-color: #00264D !important;
}
</style>

<div class="txn-section-header">
  <div class="txn-section-title">
    <div>
      <h1><i class="fas fa-history" style="color:#002F70;margin-right:8px;font-size:20px"></i>Transaction History</h1>
    </div>
  </div>
  <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;">
    <button type="button" onclick="window.location.href='staff_transactions_hub.php?section=merchandise&active_tab=merchandise'" class="txn-btn secondary" title="Back to Merchandise/Service Transaction">
      <i class="fas fa-arrow-left"></i> <span>Back</span>
    </button>
  </div>
</div>

<!-- KPI Cards -->
<div class="th-kpi-grid">
  <div class="th-kpi">
    <div class="th-kpi-lbl"><i class="fas fa-file-alt" style="color:#002F70;"></i> Total Transactions</div>
    <div class="th-kpi-val" style="color:#002F70"><?= number_format($hist_kpi_total) ?></div>
    <div class="th-kpi-sub">This period</div>
  </div>
  <div class="th-kpi">
    <div class="th-kpi-lbl"><i class="fas fa-shopping-cart" style="color:#15803d;"></i> Merchandise</div>
    <div class="th-kpi-val" style="color:#15803d"><?= number_format($hist_kpi_merch) ?></div>
    <div class="th-kpi-sub">Merchandise Only</div>
  </div>
  <div class="th-kpi">
    <div class="th-kpi-lbl"><i class="fas fa-wrench" style="color:#b45309;"></i> Job Orders</div>
    <div class="th-kpi-val" style="color:#b45309"><?= number_format($hist_kpi_jo) ?></div>
    <div class="th-kpi-sub">JO + Combined</div>
  </div>
  <div class="th-kpi">
    <div class="th-kpi-lbl"><i class="fas fa-coins" style="color:#7c3aed;"></i> Total Sales</div>
    <div class="th-kpi-val" style="color:#7c3aed">&#8369;<?= number_format($hist_kpi_sales, 2) ?></div>
    <div class="th-kpi-sub">Gross encoded</div>
  </div>
  <div class="th-kpi">
    <div class="th-kpi-lbl"><i class="fas fa-credit-card" style="color:#16a34a;"></i> Paid</div>
    <div class="th-kpi-val" style="color:#16a34a"><?= number_format($hist_kpi_paid ?? 0) ?></div>
    <div class="th-kpi-sub">Fully paid</div>
  </div>
  <div class="th-kpi">
    <div class="th-kpi-lbl"><i class="fas fa-hourglass-half" style="color:#ea580c;"></i> Unpaid / Partial</div>
    <div class="th-kpi-val" style="color:#ea580c"><?= number_format($hist_kpi_unpaid ?? 0) ?></div>
    <div class="th-kpi-sub">Pending / Credit</div>
  </div>
</div>

<!-- Filters -->
<form method="get" action="" class="th-filter-bar">
  <input type="hidden" name="section" value="history">
  <div class="th-filter-grp">
    <label>From Date</label>
    <input type="date" name="date_from" value="<?= htmlspecialchars($hist_filter_date_from) ?>">
  </div>
  <div class="th-filter-grp">
    <label>To Date</label>
    <input type="date" name="date_to" value="<?= htmlspecialchars($hist_filter_date_to) ?>">
  </div>
  <div class="th-filter-grp">
    <label>Transaction Type</label>
    <select name="txn_type">
      <option value="">All Types</option>
      <option value="merchandise" <?= $hist_filter_type==='merchandise'?'selected':'' ?>>Merchandise Only</option>
      <option value="job_order" <?= $hist_filter_type==='job_order'?'selected':'' ?>>Job Order Only</option>
      <option value="combined" <?= $hist_filter_type==='combined'?'selected':'' ?>>Job Order + Merchandise</option>
    </select>
  </div>
  <div class="th-filter-grp">
    <label>Customer</label>
    <select name="cust_type">
      <option value="">All</option>
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
    <label>Status</label>
    <select name="vstatus">
      <option value="">All Statuses</option>
      <option value="Completed" <?= $hist_filter_vstatus==='Completed'?'selected':'' ?>>Completed</option>
      <option value="Adjusted" <?= $hist_filter_vstatus==='Adjusted'?'selected':'' ?>>Adjusted</option>
      <option value="Voided" <?= $hist_filter_vstatus==='Voided'?'selected':'' ?>>Voided</option>
    </select>
  </div>
  <div class="th-filter-grp">
    <label>Shift</label>
    <select name="shift">
      <option value="">All Shifts</option>
      <option value="first" <?= $hist_filter_shift==='first'?'selected':'' ?>>Shift 1</option>
      <option value="second" <?= $hist_filter_shift==='second'?'selected':'' ?>>Shift 2</option>
    </select>
  </div>
  <div class="th-filter-grp" style="flex:1;min-width:220px;">
    <label>Search</label>
    <input type="text" name="hsearch" placeholder="Search Transaction ID, Customer, OR No., Plate No." value="<?= htmlspecialchars($hist_search) ?>" style="width:100%;">
  </div>
  <button type="submit" class="txn-btn primary" style="min-width:0; height:34px; padding:0 16px; font-size:12px; border-radius:6px"><i class="fas fa-search"></i> Filter</button>
  <a href="?section=history" class="txn-btn secondary" style="min-width:0; height:34px; padding:0 14px; font-size:12px; border-radius:6px; display:inline-flex; align-items:center; gap:6px"><i class="fas fa-times"></i> Reset</a>
</form>

<!-- Table -->
<div class="txn-card" style="margin-top:6px">
  <div class="txn-card-header">
    <i class="fas fa-list-alt" style="color:#002F70"></i>
    <h3>All Transactions</h3>
  </div>
  <div class="txn-card-body" style="padding:0">
<?php if (empty($recent_merch)): ?>
    <div style="text-align:center;padding:56px 20px;color:#64748b;">
      <i class="fas fa-receipt" style="font-size:42px;display:block;margin-bottom:12px;color:#cbd5e1;"></i>
      <div style="font-size:16px;font-weight:700;color:#1e293b;margin-bottom:4px;">📄 No transactions found.</div>
      <div style="font-size:13px;color:#64748b;">Try changing the date range or filter settings.</div>
    </div>
<?php else: ?>
    <div>
    <table class="txn-table" id="histTbl" style="width:100%;table-layout:fixed;">
      <thead><tr>
        <th style="width:13%">Transaction ID</th>
        <th style="width:12%">Customer</th>
        <th style="width:12%">Type</th>
        <th style="width:14%">Product</th>
        <th style="width:10%">Service Type</th>
        <th style="width:5%;text-align:center">Qty</th>
        <th style="width:6%;text-align:center">Unit</th>
        <th style="width:9%;text-align:right">Amount</th>
        <th style="width:9%">Payment</th>
        <th style="width:8%">Status</th>
        <th style="width:10%">Date</th>
        <th style="width:10%;text-align:center">Actions</th>
      </tr></thead>
      <tbody id="histTbody">
      <?php
      // Helper to resolve unit labels
      $resolveUnitLabel = function(string $name, string $variant, float $qty, string $item_type): string {
          if ($item_type === 'service') return 'Svc';
          $n_lower = strtolower($name . ' ' . $variant);
          if (strpos($n_lower, 'refrigerant') !== false || strpos($n_lower, 'r134a') !== false || strpos($n_lower, 'can') !== false) {
              return $qty > 1 ? 'Cans' : 'Can';
          }
          if (strpos($n_lower, 'bottle') !== false || strpos($n_lower, 'coolant') !== false || strpos($n_lower, 'fluid') !== false || strpos($n_lower, 'cleaner') !== false || strpos($n_lower, 'oil') !== false || strpos($n_lower, 'brake') !== false) {
              return $qty > 1 ? 'Bottles' : 'Bottle';
          }
          if (strpos($n_lower, 'liter') !== false || strpos($n_lower, 'litre') !== false || preg_match('/\b\d+(\.\d+)?\s*l\b/i', $n_lower)) {
              return $qty > 1 ? 'Liters' : 'Liter';
          }
          if (strpos($n_lower, 'set') !== false) return 'Set';
          if (strpos($n_lower, 'box') !== false) return $qty > 1 ? 'Boxes' : 'Box';
          return $qty > 1 ? 'Pcs' : 'Pc';
      };

      // Pre-fetch items
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
          $ht_ps    = strtolower(trim($ht['payment_status'] ?? 'pending'));
          if ($ht_ps === 'paid') { $psc='#16a34a'; }
          elseif (in_array($ht_ps,['partially paid','partial payment'])) { $psc='#d97706'; }
          elseif (in_array($ht_ps,['credit account','credit transaction','credit'])) { $psc='#7c3aed'; }
          else { $psc='#ea580c'; }

          if ($ht_type==='combined') { 
            $tc='#7c3aed'; $tb='#f3e8ff'; $tborder='#d8b4fe'; $tl='Job Order + Merchandise'; 
          } elseif ($ht_type==='job_order') { 
            $tc='#b45309'; $tb='#fffbeb'; $tborder='#fde68a'; $tl='Job Order Only'; 
          } else { 
            $tc='#15803d'; $tb='#f0fdf4'; $tborder='#bbf7d0'; $tl='Merchandise Only'; 
          }

          $ht_date = '';
          if (!empty($ht['transaction_date'])) {
              try { $ht_date = (new DateTime($ht['transaction_date']))->format('M j, Y g:i A'); } catch(Exception $e){}
          }
          $ht_tid   = htmlspecialchars($ht['transaction_id'] ?? ('#'.$ht_id));
          $ht_cname = htmlspecialchars($ht['customer_name'] ?? 'Walk-in Customer');
          $row_items = $ht_items_map[$ht_id] ?? [];

          // Fallback if no structured item table rows
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

          // Format Qty and Unit columns
          $qty_str_arr = [];
          $unit_str_arr = [];
          if (!empty($row_items)) {
              foreach ($row_items as $ri) {
                  $qv = (float)($ri['quantity'] ?? 1);
                  $q_num = ($qv == (int)$qv) ? (int)$qv : number_format($qv, 2);
                  $u_lbl = $resolveUnitLabel($ri['product_name'] ?? '', $ri['size_variant'] ?? '', $qv, $ri['item_type'] ?? 'merchandise');
                  $qty_str_arr[] = $q_num;
                  $unit_str_arr[] = $u_lbl;
              }
              if (count($row_items) === 1) {
                  $qty_col_val  = $qty_str_arr[0];
                  $unit_col_val = $unit_str_arr[0];
              } else {
                  $qty_col_val  = implode(', ', $qty_str_arr);
                  $unit_col_val = implode(', ', array_unique($unit_str_arr));
              }
          } else {
              $qty_col_val  = '1';
              $unit_col_val = ($ht_type === 'job_order') ? 'Svc' : 'Pc';
          }

          // Validation Status Badge (Completed / Adjusted / Voided)
          $val_status = strtolower(trim($ht['validation_status'] ?? 'official'));
          if (in_array($val_status, ['completed', 'approved', 'official', 'verified'])) {
              $v_badge_html = '<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:12px;font-size:10.5px;font-weight:700;color:#166534;background:#d1fae5;border:1px solid #86efac;"><i class="fas fa-circle" style="font-size:7px;"></i> Completed</span>';
          } elseif ($val_status === 'adjusted') {
              $v_badge_html = '<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:12px;font-size:10.5px;font-weight:700;color:#854d0e;background:#fef08a;border:1px solid #fde047;"><i class="fas fa-circle" style="font-size:7px;"></i> Adjusted</span>';
          } elseif (in_array($val_status, ['voided', 'cancelled', 'canceled'])) {
              $v_badge_html = '<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:12px;font-size:10.5px;font-weight:700;color:#991b1b;background:#fee2e2;border:1px solid #fca5a5;"><i class="fas fa-circle" style="font-size:7px;"></i> Voided</span>';
          } else {
              $v_badge_html = '<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:12px;font-size:10.5px;font-weight:700;color:#166534;background:#d1fae5;border:1px solid #86efac;"><i class="fas fa-circle" style="font-size:7px;"></i> Completed</span>';
          }
      ?>
      <tr class="hist-row-main">
        <td style="font-size:11px;font-weight:600;color:#334155;font-family:monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:0;" title="<?= $ht_tid ?>"><?= $ht_tid ?></td>
        <td style="font-weight:600;font-size:11.5px"><?= $ht_cname ?></td>
        <td><span style="background:<?= $tb ?>;color:<?= $tc ?>;border:1px solid <?= $tborder ?>;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:700"><?= $tl ?></span></td>
        <td>
          <?php
          $merch_items = array_filter($row_items, fn($ri) => ($ri['item_type'] ?? 'merchandise') !== 'service');
          if (empty($merch_items)): ?>
            <span style="color:#94a3b8;font-size:11px">—</span>
          <?php else: ?>
            <?php foreach ($merch_items as $ri): ?>
              <span class="hist-item-chip">
                <i class="fas fa-box" style="font-size:9px"></i>
                <?= htmlspecialchars($ri['product_name']) ?>
                <?php if (!empty($ri['size_variant'])): ?>
                <small style="color:#64748b;">[<?= htmlspecialchars($ri['size_variant']) ?>]</small>
                <?php endif; ?>
              </span>
            <?php endforeach; ?>
          <?php endif; ?>
        </td>
        <td style="font-size:11px;color:#475569;">
          <?php
          $svc_items = array_filter($row_items, fn($ri) => ($ri['item_type'] ?? '') === 'service');
          $svc_name  = '';
          if (!empty($svc_items)) {
              $svc_name = implode(', ', array_map(fn($s) => $s['product_name'], $svc_items));
          } elseif (!empty($ht['job_order_service'])) {
              $svc_name = $ht['job_order_service'];
          }
          echo $svc_name ? '<span class="hist-item-chip svc"><i class="fas fa-wrench" style="font-size:9px"></i> ' . htmlspecialchars($svc_name) . '</span>' : '<span style="color:#94a3b8;">—</span>';
          ?>
        </td>
        <td style="text-align:center;font-weight:700;color:#334155"><?= $qty_col_val ?></td>
        <td style="text-align:center;font-weight:600;color:#64748b;font-size:10.5px"><?= $unit_col_val ?></td>
        <td style="text-align:right;font-weight:700;color:#002F70">&#8369;<?= number_format((float)$ht['total_amount'],2) ?></td>
        <td style="font-size:11px">
          <div><?= htmlspecialchars($ht['payment_method'] ?? 'Cash') ?></div>
          <span style="color:<?= $psc ?>;font-size:10px;font-weight:700"><?= htmlspecialchars(ucwords($ht['payment_status'] ?? 'Paid')) ?></span>
        </td>
        <td><?= $v_badge_html ?></td>
        <td style="font-size:10px;color:#64748b;"><?= $ht_date ?: '—' ?></td>
        <td style="text-align:center;">
          <div style="display:flex;flex-direction:column;gap:4px;align-items:stretch;">
            <button onclick="openHistModal(<?= $ht_id ?>)" class="hist-action-btn btn-view-act" title="View Transaction Details" style="width:100%;">
              <i class="fas fa-eye"></i> View
            </button>
            <button onclick="window.open('receipt.php?id=<?= urlencode($ht_tid) ?>&type=<?= urlencode($ht_type) ?>', '_blank')" class="hist-action-btn btn-print-act" title="Print Receipt" style="width:100%;">
              <i class="fas fa-print"></i> Print
            </button>
          </div>
        </td>
      </tr>
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
    <div id="histModalFooter" style="margin-top:20px;display:flex;justify-content:flex-end;gap:10px;border-top:1px solid #e2e8f0;padding-top:14px;">
      <button id="modalPrintBtn" class="txn-btn primary" style="min-width:0;height:32px;padding:0 14px;font-size:12px;border-radius:6px;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-print"></i> Print Receipt</button>
      <button onclick="closeHistModal()" class="txn-btn secondary" style="min-width:0;height:32px;padding:0 14px;font-size:12px;border-radius:6px;">Close</button>
    </div>
  </div>
</div>

<script>
(function(){
var histState={page:1,per_page:10};
function histRender(){
  var rows=Array.from(document.querySelectorAll('#histTbody tr.hist-row-main'));
  var pp=histState.per_page,p=histState.page,tot=rows.length;
  var tp=Math.max(1,Math.ceil(tot/pp));
  if(p>tp)histState.page=p=tp;
  rows.forEach(function(r, i){
    r.style.display=(i >= (p-1)*pp && i < p*pp) ? '' : 'none';
  });
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
      
      // Update print button link
      var printBtn = document.getElementById('modalPrintBtn');
      if(printBtn) {
        printBtn.onclick = function() {
          window.open('receipt.php?id=' + encodeURIComponent(t.transaction_id || t.id) + '&type=' + encodeURIComponent(txnType), '_blank');
        };
      }

      var html='';
      // Transaction Info
      html+='<div class="hist-sec-title"><i class="fas fa-info-circle"></i> Transaction Information</div>';
      html+=row('Transaction ID','<strong style="color:#002F70;font-family:monospace">'+(t.transaction_id||'—')+'</strong>');
      var typeLabel = (txnType==='combined')?'Job Order + Merchandise':((txnType==='job_order')?'Job Order Only':'Merchandise Only');
      html+=row('Type','<span style="font-weight:700">'+typeLabel+'</span>');
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
