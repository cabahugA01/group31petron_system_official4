<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../backend/lib.php';
require_login();

/* Transaction History section — included by staff_transactions_hub.php */ ?>
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
  overflow: hidden;
  position: relative;
  display: flex;
  flex-direction: column;
}
.hist-modal-header {
  padding: 20px 28px 16px;
  border-bottom: 2px solid #e2e8f0;
  flex-shrink: 0;
}
.hist-modal-header h2 {
  font-size: 16px;
  font-weight: 800;
  color: #002F70;
  margin: 0;
}
.hist-modal-body {
  flex: 1;
  overflow-y: auto;
  padding: 20px 28px;
}
.hist-modal-foot {
  padding: 14px 28px 20px;
  border-top: 1px solid #e2e8f0;
  background: #f8fafc;
  flex-shrink: 0;
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
  font-size: 11.5px;
  width: 100% !important;
  min-width: 100% !important;
  max-width: 100% !important;
  table-layout: fixed !important;
  border-collapse: collapse !important;
}
#histTbl th {
  padding: 10px 8px !important;
  font-size: 11px !important;
  white-space: nowrap !important;
  overflow: hidden !important;
  text-overflow: ellipsis !important;
  background: #002F70 !important;
  border-bottom: 2px solid #001f4d !important;
  color: #ffffff !important;
  font-weight: 700 !important;
  text-transform: uppercase !important;
  letter-spacing: 0.3px !important;
  box-sizing: border-box !important;
}
#histTbl td {
  padding: 8px 8px !important;
  word-break: break-word !important;
  overflow-wrap: break-word !important;
  font-size: 11.5px !important;
  vertical-align: middle !important;
  border-bottom: 1px solid #f1f5f9 !important;
  overflow: hidden !important;
  box-sizing: border-box !important;
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
  padding: 4px 8px;
  border-radius: 5px;
  font-size: 10.5px;
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
  <div class="txn-card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
    <div style="display:flex;align-items:center;gap:8px;">
      <i class="fas fa-list-alt" style="color:#002F70"></i>
      <h3 style="margin:0;">All Transactions</h3>
    </div>
    <span style="background:#f1f5f9;color:#475569;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;border:1px solid #cbd5e1;display:inline-flex;align-items:center;gap:5px;">
      <i class="fas fa-eye" style="color:#002F70;"></i> Read-only • Click any row to view details
    </span>
  </div>
  <div class="txn-card-body" style="padding:0">
<?php if (empty($recent_merch)): ?>
    <div style="text-align:center;padding:56px 20px;color:#64748b;">
      <i class="fas fa-receipt" style="font-size:42px;display:block;margin-bottom:12px;color:#cbd5e1;"></i>
      <div style="font-size:16px;font-weight:700;color:#1e293b;margin-bottom:4px;"><i class="fas fa-file-alt"></i> No transactions found.</div>
      <div style="font-size:13px;color:#64748b;">Try changing the date range or filter settings.</div>
    </div>
<?php else: ?>
    <div class="vt-table-wrapper" style="width:100% !important;max-width:100% !important;overflow-x:hidden !important;box-sizing:border-box !important;border-radius:10px 10px 0 0;background:#fff;">
    <table class="txn-table" id="histTbl" style="width:100% !important;table-layout:fixed !important;border-collapse:collapse !important;">
      <colgroup>
        <col style="width:12%;"><!-- TXN ID -->
        <col style="width:15%;"><!-- CUSTOMER & VEHICLE -->
        <col style="width:11%;"><!-- TYPE -->
        <col style="width:19%;"><!-- PRODUCTS & SERVICES -->
        <col style="width:10%;"><!-- FEES -->
        <col style="width:14%;"><!-- TOTAL & PAYMENT -->
        <col style="width:9%;"><!-- STATUS -->
        <col style="width:10%;"><!-- DATE & TIME -->
      </colgroup>
      <thead><tr>
        <th style="white-space:nowrap;">TXN ID</th>
        <th style="white-space:nowrap;">CUSTOMER & VEHICLE</th>
        <th style="white-space:nowrap;">TYPE</th>
        <th style="white-space:nowrap;">PRODUCTS & SERVICES</th>
        <th style="white-space:nowrap;">FEES</th>
        <th style="white-space:nowrap;">TOTAL & PAYMENT</th>
        <th style="white-space:nowrap;text-align:center;">STATUS</th>
        <th style="white-space:nowrap;">DATE & TIME</th>
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
            $tc='#002F70'; $tb='#e0f2fe'; $tborder='#93c5fd'; $tl='Job Order + Merch'; 
          } elseif ($ht_type==='job_order') { 
            $tc='#991b1b'; $tb='#fee2e2'; $tborder='#fca5a5'; $tl='Job Order'; 
          } else { 
            $tc='#166534'; $tb='#dcfce7'; $tborder='#86efac'; $tl='Merchandise'; 
          }

          $ht_date = '';
          if (!empty($ht['transaction_date'])) {
              try { $ht_date = (new DateTime($ht['transaction_date']))->format('M d, Y • h:i A'); } catch(Exception $e){}
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

          // Validation Status Badge (Completed / Released / Adjusted / Voided)
          $val_status = strtolower(trim($ht['validation_status'] ?? 'official'));
          $wf_status  = strtolower(trim($ht['workflow_status'] ?? ''));
          if (in_array($val_status, ['released']) || in_array($wf_status, ['released'])) {
              $v_badge_html = '<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:999px;font-size:10.5px;font-weight:700;color:#166534;background:#dcfce7;border:1px solid #86efac;white-space:nowrap;"><i class="fas fa-check" style="font-size:8px;"></i> Released</span>';
          } elseif (in_array($val_status, ['completed', 'approved', 'official', 'verified']) || in_array($wf_status, ['completed'])) {
              $v_badge_html = '<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:999px;font-size:10.5px;font-weight:700;color:#166534;background:#dcfce7;border:1px solid #86efac;white-space:nowrap;"><i class="fas fa-check-circle" style="font-size:8px;"></i> Completed</span>';
          } elseif ($val_status === 'adjusted') {
              $v_badge_html = '<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:999px;font-size:10.5px;font-weight:700;color:#92400e;background:#fef3c7;border:1px solid #fcd34d;white-space:nowrap;"><i class="fas fa-sliders-h" style="font-size:8px;"></i> Adjusted</span>';
          } elseif (in_array($val_status, ['voided', 'cancelled', 'canceled', 'void'])) {
              $v_badge_html = '<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:999px;font-size:10.5px;font-weight:700;color:#991b1b;background:#fee2e2;border:1px solid #fca5a5;white-space:nowrap;"><i class="fas fa-ban" style="font-size:8px;"></i> Voided</span>';
          } else {
              $v_badge_html = '<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:999px;font-size:10.5px;font-weight:700;color:#166534;background:#dcfce7;border:1px solid #86efac;white-space:nowrap;"><i class="fas fa-check-circle" style="font-size:8px;"></i> Completed</span>';
          }

          // Service Fee & Labor Fee resolution
          $sf_val = (float)($ht['service_fee'] ?? 0);
          $lf_val = (float)($ht['labor_fee'] ?? 0);
          if ($sf_val == 0 && !empty($row_items)) {
              foreach ($row_items as $ri) {
                  $itype = strtolower($ri['item_type'] ?? '');
                  $icat  = strtolower($ri['category'] ?? '');
                  $iname = strtolower($ri['product_name'] ?? '');
                  if (($itype === 'service' || strpos($icat, 'service') !== false) && strpos($icat, 'labor') === false && strpos($iname, 'labor') === false) {
                      $sf_val += (float)($ri['subtotal'] ?? 0);
                  }
              }
              if ($sf_val == 0 && ($ht_type === 'job_order' || !empty($ht['job_order_service']))) {
                  $sf_val = (float)($ht['total_amount'] ?? 0);
              }
          }
          if ($lf_val == 0 && !empty($row_items)) {
              foreach ($row_items as $ri) {
                  $icat  = strtolower($ri['category'] ?? '');
                  $iname = strtolower($ri['product_name'] ?? '');
                  if ($icat === 'labor' || strpos($iname, 'labor') !== false) {
                      $lf_val += (float)($ri['subtotal'] ?? 0);
                  }
              }
          }
      ?>
      <tr class="hist-row-main" onclick="openHistModal(<?= $ht_id ?>)" style="cursor:pointer;" title="Click to view transaction details">
        <!-- 1. Txn ID -->
        <td style="padding:8px 8px;max-width:0;overflow:hidden;box-sizing:border-box;vertical-align:middle;">
          <div style="font-weight:700;font-size:11px;color:#002F70;font-family:monospace;word-break:break-all;overflow-wrap:anywhere;line-height:1.2;" title="<?= $ht_tid ?>"><?= $ht_tid ?></div>
        </td>

        <!-- 2. Customer & Vehicle -->
        <td style="padding:8px 8px;max-width:0;overflow:hidden;box-sizing:border-box;vertical-align:middle;">
          <div style="font-weight:700;font-size:12px;color:#0f172a;line-height:1.25;word-break:break-word;overflow-wrap:break-word;white-space:normal;"><?= $ht_cname ?></div>
          <?php if (!empty($ht['vehicle_plate']) && $ht['vehicle_plate'] !== '—' && $ht['vehicle_plate'] !== 'N/A'): ?>
            <div style="display:inline-flex;align-items:center;gap:3px;background:#f1f5f9;border:1px solid #cbd5e1;color:#1e293b;padding:1px 5px;border-radius:4px;font-size:10px;font-weight:700;margin-top:2px;">
              <i class="fas fa-car" style="color:#2563eb;font-size:9.5px;"></i> <?= htmlspecialchars($ht['vehicle_plate']) ?>
            </div>
          <?php endif; ?>
        </td>

        <!-- 3. Type -->
        <td style="padding:8px 8px;max-width:0;overflow:hidden;box-sizing:border-box;vertical-align:middle;">
          <span style="background:<?= $tb ?>;color:<?= $tc ?>;border:1px solid <?= $tborder ?>;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700;white-space:nowrap;display:inline-block;"><?= $tl ?></span>
        </td>

        <!-- 4. Products & Services (with Qty & Unit) -->
        <td style="padding:8px 8px;max-width:0;overflow:hidden;box-sizing:border-box;vertical-align:middle;">
          <?php
          $merch_items = array_filter($row_items, fn($ri) => ($ri['item_type'] ?? 'merchandise') !== 'service');
          $svc_items   = array_filter($row_items, fn($ri) => ($ri['item_type'] ?? '') === 'service');
          $has_any = false;
          
          if (!empty($merch_items)) {
              $has_any = true;
              foreach ($merch_items as $ri) {
                  $qv = (float)($ri['quantity'] ?? 1);
                  $q_num = ($qv == (int)$qv) ? (int)$qv : number_format($qv, 2);
                  $u_lbl = $resolveUnitLabel($ri['product_name'] ?? '', $ri['size_variant'] ?? '', $qv, $ri['item_type'] ?? 'merchandise');
                  ?>
                  <div style="font-weight:700;font-size:11.5px;color:#1e293b;word-break:break-word;overflow-wrap:break-word;line-height:1.25;">
                    <?= htmlspecialchars($ri['product_name']) ?>
                    <?php if (!empty($ri['size_variant'])): ?>
                    <small style="color:#64748b;">[<?= htmlspecialchars($ri['size_variant']) ?>]</small>
                    <?php endif; ?>
                  </div>
                  <div style="font-size:10px;color:#64748b;margin-bottom:3px;">Qty: <?= $q_num ?> <?= $u_lbl ?></div>
                  <?php
              }
          }
          
          $svc_name = '';
          if (!empty($svc_items)) {
              $svc_name = implode(', ', array_map(fn($s) => $s['product_name'], $svc_items));
          } elseif (!empty($ht['job_order_service'])) {
              $svc_name = $ht['job_order_service'];
          }
          
          if ($svc_name) {
              $has_any = true;
              if (!empty($merch_items)) echo '<div style="border-top:1px dashed #e2e8f0;margin:3px 0;"></div>';
              echo '<div style="font-weight:700;color:#1e40af;font-size:11.5px;word-break:break-word;overflow-wrap:break-word;"><i class="fas fa-wrench" style="color:#2563eb;font-size:10px;margin-right:3px;"></i>' . htmlspecialchars($svc_name) . '</div>';
          }
          
          if (!$has_any) {
              echo '<span style="color:#94a3b8;">—</span>';
          }
          ?>
        </td>

        <!-- 5. Fees -->
        <td style="padding:8px 8px;max-width:0;overflow:hidden;box-sizing:border-box;vertical-align:middle;font-size:11px;">
          <?php
          if ($sf_val > 0) {
              echo '<div style="color:#334155;white-space:nowrap;">Svc: <strong style="color:#2563eb;font-weight:700;">₱' . number_format($sf_val, 2) . '</strong></div>';
          }
          if ($lf_val > 0) {
              echo '<div style="color:#334155;margin-top:2px;white-space:nowrap;">Labor: <strong style="color:#16a34a;font-weight:700;">₱' . number_format($lf_val, 2) . '</strong></div>';
          }
          if ($sf_val <= 0 && $lf_val <= 0) {
              echo '<span style="color:#94a3b8;">—</span>';
          }
          ?>
        </td>

        <!-- 6. Total & Payment -->
        <td style="padding:8px 8px;max-width:0;overflow:hidden;box-sizing:border-box;vertical-align:middle;">
          <div style="font-weight:800;font-size:13px;color:#002F70;white-space:nowrap;line-height:1.2;">₱<?= number_format((float)$ht['total_amount'], 2) ?></div>
          <div style="display:flex;align-items:center;gap:4px;margin-top:3px;flex-wrap:wrap;">
            <span style="color:#1e293b;font-weight:700;font-size:10.5px;"><?= htmlspecialchars($ht['payment_method'] ?? 'Cash') ?></span>
            <span style="background:<?= $ht_ps==='paid'?'#dcfce7':'#fee2e2' ?>;color:<?= $ht_ps==='paid'?'#15803d':'#b91c1c' ?>;font-weight:800;font-size:9.5px;padding:1px 4px;border-radius:3px;border:1px solid <?= $ht_ps==='paid'?'#bbf7d0':'#fecaca' ?>;">
              <?= strtoupper(htmlspecialchars($ht['payment_status'] ?? 'PAID')) ?>
            </span>
          </div>
        </td>

        <!-- 7. Status -->
        <td style="padding:8px 4px;max-width:0;overflow:hidden;box-sizing:border-box;vertical-align:middle;text-align:center;">
          <?= $v_badge_html ?>
        </td>

        <!-- 8. Date & Time -->
        <td style="padding:8px 8px;max-width:0;overflow:hidden;box-sizing:border-box;vertical-align:middle;">
          <div style="font-size:11px;color:#334155;font-weight:600;white-space:nowrap;"><?= $ht_date ?: '—' ?></div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <!-- Pagination Footer -->
    <div id="histPaginationFooter" style="display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-top:1px solid #e2e8f0; background:#ffffff; border-radius:0 0 12px 12px; font-size:13px; color:#475569; flex-wrap:wrap; gap:12px;">
      <div style="display:flex; align-items:center;">
        <span id="histShowingEntriesText" style="font-size:13px; color:#64748b; font-weight:600;">Showing <?= empty($recent_merch) ? '0' : '1–'.min(10, count($recent_merch)) ?> of <?= count($recent_merch) ?> entries</span>
      </div>
      <div style="display:flex; align-items:center; gap:16px;">
        <div style="display:flex; align-items:center; gap:8px;">
          <label style="margin:0; font-weight:600; color:#64748b; font-size:13px;">Rows per page:</label>
          <select id="histPerPage" onchange="histChangePerPage()" style="padding:4px 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; background:transparent !important; color:#334155; outline:none; cursor:pointer;">
            <option value="10" selected>10</option>
            <option value="20">20</option>
            <option value="50">50</option>
            <option value="100">100</option>
          </select>
        </div>
        <div style="display:flex; align-items:center; gap:6px;">
          <button id="histPrevBtn" onclick="histGoPage(histState.page - 1)" 
                  style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:not-allowed; color:#cbd5e1; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                  onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
            <i class="fas fa-chevron-left"></i>
          </button>
          <span id="histPageLabel" style="color:#334155; font-size:13px; font-weight:600; padding:0 4px;">Page 1 of <?= max(1, ceil(count($recent_merch) / 10)) ?></span>
          <button id="histNextBtn" onclick="histGoPage(histState.page + 1)" 
                  style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:<?= count($recent_merch) > 10 ? 'pointer' : 'not-allowed' ?>; color:<?= count($recent_merch) > 10 ? '#475569' : '#cbd5e1' ?>; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                  onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
            <i class="fas fa-chevron-right"></i>
          </button>
        </div>
      </div>
    </div>
<?php endif; ?>
  </div>
</div>

<!-- View Details Modal -->
<div class="hist-modal-bg" id="histModalBg" onclick="if(event.target===this)closeHistModal()">
  <div class="hist-modal" id="histModal">
    <!-- Header -->
    <div class="hist-modal-header">
      <button onclick="closeHistModal()" style="position:absolute;top:14px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;color:#64748b">&times;</button>
      <h2><i class="fas fa-receipt" style="color:#002F70;margin-right:8px"></i>Transaction Details</h2>
    </div>
    <!-- Body -->
    <div class="hist-modal-body">
      <div id="histModalContent" style="color:#475569;font-size:13px">Loading...</div>
    </div>
    <!-- Footer -->
    <div class="hist-modal-foot" id="histModalFooter" style="display:flex;justify-content:flex-end;gap:10px;">
      <button id="modalPrintBtn" class="txn-btn primary" style="min-width:0;height:32px;padding:0 14px;font-size:12px;border-radius:6px;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-print"></i> Print Receipt</button>
      <button onclick="closeHistModal()" class="txn-btn secondary" style="min-width:0;height:32px;padding:0 14px;font-size:12px;border-radius:6px;">Close</button>
    </div>
  </div>
</div>


<script>
(function(){
var histState = { page: 1, per_page: 10 };

function histRender() {
  var rows = Array.from(document.querySelectorAll('#histTbody tr.hist-row-main'));
  var pp = histState.per_page || 10;
  var tot = rows.length;
  var tp = Math.max(1, Math.ceil(tot / pp));
  if (histState.page > tp) histState.page = tp;
  if (histState.page < 1) histState.page = 1;
  var p = histState.page;

  var start = (p - 1) * pp;
  var end   = p * pp;

  rows.forEach(function(r, i) {
    r.style.display = (i >= start && i < end) ? '' : 'none';
  });

  // Update entries counter
  var showingStart = tot === 0 ? 0 : start + 1;
  var showingEnd   = Math.min(end, tot);
  var entriesLbl   = document.getElementById('histShowingEntriesText');
  if (entriesLbl) {
    entriesLbl.textContent = 'Showing ' + (tot === 0 ? '0' : showingStart + '–' + showingEnd) + ' of ' + tot + ' entries';
  }

  var lbl = document.getElementById('histPageLabel');
  if (lbl) lbl.textContent = 'Page ' + p + ' of ' + tp;

  var prev = document.getElementById('histPrevBtn');
  var next = document.getElementById('histNextBtn');
  if (prev) {
    prev.disabled = (p <= 1);
    prev.style.cursor = prev.disabled ? 'not-allowed' : 'pointer';
    prev.style.color = prev.disabled ? '#cbd5e1' : '#475569';
  }
  if (next) {
    next.disabled = (p >= tp);
    next.style.cursor = next.disabled ? 'not-allowed' : 'pointer';
    next.style.color = next.disabled ? '#cbd5e1' : '#475569';
  }
}

window.histState = histState;
window.histGoPage = function(p) {
  var rows = document.querySelectorAll('#histTbody tr.hist-row-main');
  var tp = Math.max(1, Math.ceil(rows.length / (histState.per_page || 10)));
  if (p < 1 || p > tp) return;
  histState.page = p;
  histRender();
};
window.histChangePerPage = function() {
  var s = document.getElementById('histPerPage');
  if (s) histState.per_page = parseInt(s.value, 10);
  histState.page = 1;
  histRender();
};

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
