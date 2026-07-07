<?php
/**  * Staff Inventory History  * Read-only view of inventory movements and fuel transactions  */
$page_id = 'inv_history';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();  $me  = current_user();
$role  = role_key($me['role'] ?? 'staff');
$station_id = user_station_id();  // ── Module gate ───────────────────────────────────────────────
if (!in_array($role, ['superadmin', 'developer']) && !is_module_enabled('inventory')) {  render_module_disabled_page('Inventory');
}  if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])) {  header('Location: dashboard.php');  exit;
}  // ═══════════════════════════════════════════════════════════════════════════
// MERCHANDISE HISTORY - Fetch real inventory movements from merchandise_stock_in
// ═══════════════════════════════════════════════════════════════════════════
$merch_movements = [];
$merch_stats = ['total' => 0, 'deliveries' => 0, 'releases' => 0];  try {  // Fetch from actual merchandise_stock_in table (production data)  $stmt = $pdo->prepare("  SELECT  msi.encoded_at as date,  msi.batch_ref as reference_no,  msi.product_name as product,  'Delivery' as movement_type,  msi.qty_received as quantity,  COALESCE(u.username, 'System') as user  FROM merchandise_stock_in msi  LEFT JOIN users u ON msi.encoded_by = u.id  WHERE msi.station_id = ?  ORDER BY msi.encoded_at DESC  LIMIT 500  ");  $stmt->execute([$station_id]);  $merch_movements = $stmt->fetchAll(PDO::FETCH_ASSOC);  // Calculate stats from actual data  $merch_stats['total'] = count($merch_movements);  foreach ($merch_movements as $m) {  if ($m['movement_type'] === 'Delivery') $merch_stats['deliveries']++;  else $merch_stats['releases']++;  }
} catch (Exception $e) {  error_log("Merchandise history error: " . $e->getMessage());
}  // ═══════════════════════════════════════════════════════════════════════════
// FUEL HISTORY - Fetch real fuel transactions from fuel_transactions table
// ═══════════════════════════════════════════════════════════════════════════
$fuel_transactions = [];
$fuel_stats = ['total' => 0, 'today' => 0, 'total_liters' => 0];  try {  // Fetch from actual fuel_transactions table (production data)  // Use helper function to determine shift based on transaction time  $shift_case = get_shift_sql_case('ft.transaction_date');  $stmt = $pdo->prepare("  SELECT  ft.transaction_date as date,  $shift_case as shift,  ft.fuel_type as tank,  ft.previous_reading as beginning,  ft.present_reading as ending,  ft.calibration as calibration,  ft.liters_sold as dispensed,  COALESCE(u.username, 'Unknown') as staff  FROM fuel_transactions ft  LEFT JOIN users u ON ft.staff_id = u.id  WHERE ft.station_id = ?  ORDER BY ft.transaction_date DESC  LIMIT 500  ");  $stmt->execute([$station_id]);  $fuel_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);  // Calculate stats from actual data  $fuel_stats['total'] = count($fuel_transactions);  $today = date('Y-m-d');  foreach ($fuel_transactions as $f) {  $date = date('Y-m-d', strtotime($f['date']));  if ($date === $today) $fuel_stats['today']++;  $fuel_stats['total_liters'] += (float)($f['dispensed'] ?? 0);  }
} catch (Exception $e) {  error_log("Fuel history error: " . $e->getMessage());
}  include __DIR__ . '/../partials/header.php';
?>  <style>
/* Summary Cards */
.summary-grid {  display: grid;  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));  gap: 16px;  margin-bottom: 24px;
}
.summary-card {  background: #fff;  border: 1px solid #e2e8f0;  border-radius: 10px;  padding: 18px 20px;  text-align: center;  box-shadow: 0 2px 4px rgba(0,0,0,.04);
}
.summary-card .sc-num {  font-size: 32px;  font-weight: 700;  line-height: 1;  color: #002F6C;
}
.summary-card .sc-lbl {  font-size: 12px;  color: #64748b;  margin-top: 6px;  font-weight: 500;  text-transform: uppercase;  letter-spacing: .3px;
}
.summary-card.sc-green .sc-num { color: #16a34a; }
.summary-card.sc-blue .sc-num { color: #0284c7; }
.summary-card.sc-orange .sc-num { color: #ea580c; }  /* Tabs */
.hist-tabs {  display: flex;  border-bottom: 2px solid #e9ecef;  margin-bottom: 20px;  gap: 4px;
}
.hist-tab {  padding: 12px 24px;  font-size: 14px;  font-weight: 600;  color: #6c757d;  cursor: pointer;  border-bottom: 3px solid transparent;  margin-bottom: -2px;  transition: all .15s;  display: flex;  align-items: center;  gap: 8px;  user-select: none;  background: transparent;  border-radius: 8px 8px 0 0;
}
.hist-tab:hover {  color: #002F70;  background: #f8fbff;
}
.hist-tab.active {  color: #002F70;  border-bottom-color: #002F70;  background: #f8fbff;
}
.hist-tab-panel {  display: none;
}
.hist-tab-panel.active {  display: block;
}  /* Card */
.hist-card {  background: #fff;  border-radius: 12px;  box-shadow: 0 2px 8px rgba(0,0,0,.06);  border: 1px solid #e9ecef;  overflow: hidden;
}
.hist-card-head {  padding: 18px 24px;  border-bottom: 1px solid #e9ecef;  background: #f8f9fa;
}
.hist-card-title {  font-size: 16px;  font-weight: 700;  color: #002F70;  display: flex;  align-items: center;  gap: 10px;
}
.hist-card-body {  padding: 0;
}  /* Table */
.hist-table {  width: 100%;  border-collapse: collapse;  font-size: 13px;
}
.hist-table thead {  background: #002F70;  color: #fff;
}
.hist-table th {  padding: 12px 16px;  text-align: left;  font-size: 11px;  font-weight: 700;  text-transform: uppercase;  letter-spacing: .5px;  white-space: nowrap;
}
.hist-table td {  padding: 12px 16px;  border-bottom: 1px solid #f1f5f9;  vertical-align: middle;
}
.hist-table tbody tr:hover {  background: #f8fbff;
}
.hist-table tbody tr:last-child td {  border-bottom: none;
}  /* Empty state */
.empty-state {  text-align: center;  padding: 48px 24px;  color: #94a3b8;
}
.empty-state i {  font-size: 48px;  margin-bottom: 12px;  opacity: .3;  display: block;
}
</style>  <div class="page-head">  <div>  <h1 class="h1"><i class="fas fa-history"></i> Inventory History</h1>  <div class="sub">Track inventory movements, stock-in records, and deliveries.</div>  </div>
</div>  <!-- Tabs -->
<div class="hist-tabs">  <div class="hist-tab active" onclick="switchTab('merchandise')" id="tab-merchandise">  <i class="fas fa-box"></i> Merchandise History  </div>  <div class="hist-tab" onclick="switchTab('fuel')" id="tab-fuel">  <i class="fas fa-gas-pump"></i> Fuel History  </div>
</div>  <!-- ══════════════════════════════════════════════════════════════════════════  MERCHANDISE HISTORY TAB  ══════════════════════════════════════════════════════════════════════════ -->
<div class="hist-tab-panel active" id="panel-merchandise">  <!-- Summary Cards -->  <div class="summary-grid">  <div class="summary-card">  <div class="sc-num"><?php echo number_format($merch_stats['total']); ?></div>  <div class="sc-lbl">Total Movements</div>  </div>  <div class="summary-card sc-green">  <div class="sc-num"><?php echo number_format($merch_stats['deliveries']); ?></div>  <div class="sc-lbl">Deliveries</div>  </div>  <div class="summary-card sc-orange">  <div class="sc-num"><?php echo number_format($merch_stats['releases']); ?></div>  <div class="sc-lbl">Releases</div>  </div>  </div>  <!-- Table -->  <div class="hist-card">  <div class="hist-card-body">  <table class="hist-table">  <thead>  <tr>  <th>Date</th>  <th>Reference No.</th>  <th>Product</th>  <th>Movement</th>  <th>Qty</th>  <th>User</th>  </tr>  </thead>  <tbody>  <?php if (empty($merch_movements)): ?>  <tr>  <td colspan="6">  <div class="empty-state">  <i class="fas fa-inbox"></i>  <p style="margin:0;font-size:14px;">No merchandise inventory movements yet.</p>  </div>  </td>  </tr>  <?php else: ?>  <?php foreach ($merch_movements as $m): ?>  <tr>  <td style="white-space:nowrap;">  <?php echo date('M d, Y g:i A', strtotime($m['date'])); ?>  </td>  <td>  <code style="font-size:11px;background:#f1f5f9;padding:2px 6px;border-radius:4px;">  <?php echo htmlspecialchars($m['reference_no'] ?? '—'); ?>  </code>  </td>  <td><strong><?php echo htmlspecialchars($m['product']); ?></strong></td>  <td>  <span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;padding:3px 8px;border-radius:12px;background:#dcfce7;color:#166534;">  <i class="fas fa-arrow-down"></i> <?php echo htmlspecialchars($m['movement_type']); ?>  </span>  </td>  <td style="font-weight:700;color:#16a34a;">  +<?php echo number_format((float)$m['quantity'], 0); ?>  </td>  <td><?php echo htmlspecialchars($m['user'] ?? '—'); ?></td>  </tr>  <?php endforeach; ?>  <?php endif; ?>  </tbody>  </table>  </div>  </div>
</div>  <!-- ══════════════════════════════════════════════════════════════════════════  FUEL HISTORY TAB  ══════════════════════════════════════════════════════════════════════════ -->
<div class="hist-tab-panel" id="panel-fuel">  <!-- Summary Cards -->  <div class="summary-grid">  <div class="summary-card">  <div class="sc-num"><?php echo number_format($fuel_stats['total']); ?></div>  <div class="sc-lbl">Total Fuel Transactions</div>  </div>  <div class="summary-card sc-blue">  <div class="sc-num"><?php echo number_format($fuel_stats['today']); ?></div>  <div class="sc-lbl">Today's Transactions</div>  </div>  <div class="summary-card sc-green">  <div class="sc-num"><?php echo number_format($fuel_stats['total_liters'], 2); ?></div>  <div class="sc-lbl">Total Liters Dispensed</div>  </div>  </div>  <!-- Table -->  <div class="hist-card">  <div class="hist-card-body">  <table class="hist-table">  <thead>  <tr>  <th>Date</th>  <th>Shift</th>  <th>Tank</th>  <th>Beginning</th>  <th>Ending</th>  <th>Calibration</th>  <th>Dispensed (L)</th>  <th>Staff</th>  </tr>  </thead>  <tbody>  <?php if (empty($fuel_transactions)): ?>  <tr>  <td colspan="8">  <div class="empty-state">  <i class="fas fa-inbox"></i>  <p style="margin:0;font-size:14px;">No fuel transactions yet.</p>  </div>  </td>  </tr>  <?php else: ?>  <?php foreach ($fuel_transactions as $f): ?>  <tr>  <td style="white-space:nowrap;">  <?php echo date('M d, Y', strtotime($f['date'])); ?>  </td>  <td>  <span style="font-size:11px;font-weight:600;padding:3px 8px;border-radius:12px;background:#fff3cd;color:#856404;">  <?php echo htmlspecialchars($f['shift']); ?>  </span>  </td>  <td><strong><?php echo htmlspecialchars($f['tank']); ?></strong></td>  <td><?php echo number_format((float)$f['beginning'], 2); ?></td>  <td><?php echo number_format((float)$f['ending'], 2); ?></td>  <td><?php echo number_format((float)$f['calibration'], 2); ?></td>  <td style="font-weight:700;color:#0284c7;">  <?php echo number_format((float)$f['dispensed'], 2); ?> L  </td>  <td><?php echo htmlspecialchars($f['staff'] ?? '—'); ?></td>  </tr>  <?php endforeach; ?>  <?php endif; ?>  </tbody>  </table>  </div>  </div>
</div>  <script>
function switchTab(tab) {  document.querySelectorAll('.hist-tab').forEach(el => el.classList.remove('active'));  document.querySelectorAll('.hist-tab-panel').forEach(el => el.classList.remove('active'));  document.getElementById('tab-' + tab).classList.add('active');  document.getElementById('panel-' + tab).classList.add('active');  history.replaceState(null, '', '#tab-' + tab);
}  document.addEventListener('DOMContentLoaded', function() {  const hash = window.location.hash;  if (hash === '#tab-fuel') {  switchTab('fuel');  }
});
</script>  <?php include __DIR__ . '/../partials/footer.php'; ?>
