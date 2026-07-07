<?php
/**
 * Fuel Inventory Report — tank levels, meter readings, variance, low-stock alerts
 * Works as both standalone page and included file
 */

// Initialize for standalone access
$is_standalone = !isset($date_start) || !isset($date_end) || !isset($pdo) || !isset($station_id);

if ($is_standalone) {  // Standalone mode - setup environment  if (session_status() !== PHP_SESSION_ACTIVE) {  session_start();  }  // Include required files  require_once __DIR__ . '/../../backend/lib.php';  require_once __DIR__ . '/../db_connect.php';  // Check authentication  require_login();  // Get user info  $current_user = current_user();  $user_role = role_key($current_user['role'] ?? 'staff');  // Get station_id from user session  $station_id = user_station_id();  // Check if user has station assigned  if (!$station_id && in_array($user_role, ['admin', 'manager', 'staff'])) {  render_no_station_page('admin_dashboard.php');  }  // Get date range from GET parameters with defaults  $date_start = $_GET['date_from'] ?? date('Y-m-d');  $date_end = $_GET['date_to'] ?? date('Y-m-d');  // Validate date format  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_start)) {  $date_start = date('Y-m-d');  }  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_end)) {  $date_end = date('Y-m-d');  }  // Include header for standalone page  $page_id = 'admin_reports';  require_once __DIR__ . '/../../partials/header.php';
}

// Fuel inventory levels per tank/type
$tanks = [];
try {  $q = $pdo->prepare("SELECT fi.*, ft.name AS fuel_type_name,  COALESCE(fi.fuel_type, ft.name, 'Unknown') AS display_type  FROM fuel_inventory fi  LEFT JOIN fuel_types ft ON fi.fuel_type_id = ft.id  WHERE fi.station_id = ? ORDER BY display_type");  $q->execute([$station_id]);  $tanks = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Fuel sold in period per type
$fuel_sold = [];
try {  $q2 = $pdo->prepare("SELECT fuel_type,  COALESCE(SUM(liters_sold),0) AS liters,  COALESCE(SUM(total_amount),0) AS revenue,  COUNT(*) AS txn_cnt  FROM fuel_transactions WHERE station_id=?  AND DATE(transaction_date) BETWEEN ? AND ?  GROUP BY fuel_type ORDER BY fuel_type");  $q2->execute([$station_id, $date_start, $date_end]);  foreach ($q2->fetchAll(PDO::FETCH_ASSOC) as $row) {  $fuel_sold[$row['fuel_type']] = $row;  }
} catch (Exception $e) {}

// Deliveries received in period
$deliveries = [];
try {  $q3 = $pdo->prepare("SELECT fuel_type, COALESCE(SUM(delivery_liters),0) AS liters, COUNT(*) AS cnt,  GROUP_CONCAT(DISTINCT supplier SEPARATOR ', ') AS suppliers  FROM fuel_deliveries WHERE station_id=?  AND delivery_date BETWEEN ? AND ? GROUP BY fuel_type");  $q3->execute([$station_id, $date_start, $date_end]);  foreach ($q3->fetchAll(PDO::FETCH_ASSOC) as $row) {  $deliveries[$row['fuel_type']] = $row;  }
} catch (Exception $e) {}

// Variance alerts
$variances = [];
try {  $q4 = $pdo->prepare("SELECT item_identifier AS fuel_type,  COALESCE(SUM(variance_amount),0) AS total_variance,  COUNT(*) AS alert_cnt  FROM variance_alerts WHERE station_id=? AND transaction_type='Fuel'  AND DATE(created_at) BETWEEN ? AND ? GROUP BY item_identifier");  $q4->execute([$station_id, $date_start, $date_end]);  foreach ($q4->fetchAll(PDO::FETCH_ASSOC) as $row) {  $variances[$row['fuel_type']] = $row;  }
} catch (Exception $e) {}

$total_stock = array_sum(array_column($tanks,'current_level'));
$low_count  = count(array_filter($tanks, fn($t) => ($t['current_level']??0) <= ($t['reorder_level']??0) && ($t['current_level']??0) > 0));
$out_count  = count(array_filter($tanks, fn($t) => ($t['current_level']??0) <= 0));
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">  <div>  <h2 style="margin:0 0 4px;color:#003366;font-size:22px;"><i class="fas fa-gas-pump"></i> Fuel Inventory Report</h2>  <p style="margin:0;color:#666;font-size:13px;">Tank levels, deliveries, sales, and variance analysis</p>  </div>  <button onclick="window.print()" style="background:#003366;color:#fff;border:none;padding:9px 18px;border-radius:6px;font-weight:600;cursor:pointer;"><i class="fas fa-print"></i> Print</button>
</div>

<!-- KPI Cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:28px;">  <div style="background:linear-gradient(135deg,#003366,#004d99);padding:18px;border-radius:10px;color:#fff;">  <div style="font-size:12px;opacity:.85;">Total Fuel in Stock</div>  <div style="font-size:22px;font-weight:700;"><?= number_format($total_stock,2) ?> L</div>  <div style="font-size:11px;opacity:.75;"><?= count($tanks) ?> tank types</div>  </div>  <div style="background:linear-gradient(135deg,#fd7e14,#e06c00);padding:18px;border-radius:10px;color:#fff;">  <div style="font-size:12px;opacity:.85;">Low Stock Alerts</div>  <div style="font-size:22px;font-weight:700;"><?= $low_count ?></div>  <div style="font-size:11px;opacity:.75;">Below reorder level</div>  </div>  <div style="background:linear-gradient(135deg,#dc3545,#c82333);padding:18px;border-radius:10px;color:#fff;">  <div style="font-size:12px;opacity:.85;">Out of Stock</div>  <div style="font-size:22px;font-weight:700;"><?= $out_count ?></div>  <div style="font-size:11px;opacity:.75;">Tank types at 0</div>  </div>  <div style="background:linear-gradient(135deg,#1a7c40,#228b22);padding:18px;border-radius:10px;color:#fff;">  <div style="font-size:12px;opacity:.85;">Total Sold (Period)</div>  <div style="font-size:22px;font-weight:700;"><?= number_format(array_sum(array_column($fuel_sold,'liters')),2) ?> L</div>  <div style="font-size:11px;opacity:.75;">₱<?= number_format(array_sum(array_column($fuel_sold,'revenue')),2) ?> revenue</div>  </div>
</div>

<!-- Tank Status Table -->
<h3 style="color:#003366;margin-bottom:12px;"><i class="fas fa-database"></i> Current Tank Inventory</h3>
<div style="overflow-x:auto;margin-bottom:28px;">
<table class="report-table">  <thead>  <tr>  <th>Fuel Type</th><th>Current Level (L)</th><th>Capacity (L)</th>  <th>Fill %</th><th>Reorder Level</th><th>Price/L</th><th>Status</th>  </tr>  </thead>  <tbody>  <?php if(empty($tanks)): ?>  <tr><td colspan="7" style="text-align:center;color:#999;padding:40px;">No inventory records found.</td></tr>  <?php else: foreach($tanks as $t):  $lvl  = (float)($t['current_level'] ?? $t['current_stock'] ?? 0);  $cap  = (float)($t['capacity'] ?? 0);  $pct  = $cap > 0 ? round($lvl/$cap*100,1) : 0;  $ror  = (float)($t['reorder_level'] ?? 0);  $status = $lvl <= 0 ? ['Out of Stock','#dc3545'] : ($lvl <= $ror ? ['Low Stock','#fd7e14'] : ['Normal','#28a745']);  ?>  <tr>  <td><strong><?= htmlspecialchars($t['display_type'] ?? $t['fuel_type'] ?? 'N/A') ?></strong></td>  <td><?= number_format($lvl,2) ?></td>  <td><?= $cap > 0 ? number_format($cap,2) : '—' ?></td>  <td>  <div style="display:flex;align-items:center;gap:8px;">  <div style="flex:1;height:10px;background:#e0e0e0;border-radius:5px;overflow:hidden;">  <div style="width:<?= min($pct,100) ?>%;height:100%;background:<?= $pct<25?'#dc3545':($pct<50?'#fd7e14':'#28a745') ?>;border-radius:5px;"></div>  </div>  <span style="font-size:12px;min-width:38px;"><?= $pct ?>%</span>  </div>  </td>  <td><?= $ror > 0 ? number_format($ror,2).' L' : '—' ?></td>  <td>₱<?= number_format((float)($t['price_per_liter']??0),2) ?></td>  <td><span style="color:<?= $status[1] ?>;font-weight:700;"><?= $status[0] ?></span></td>  </tr>  <?php endforeach; endif; ?>  </tbody>
</table>
</div>

<!-- Sales vs Deliveries -->
<h3 style="color:#003366;margin-bottom:12px;"><i class="fas fa-exchange-alt"></i> Period Activity (<?= $date_start ?> to <?= $date_end ?>)</h3>
<div style="overflow-x:auto;margin-bottom:28px;">
<table class="report-table">  <thead>  <tr>  <th>Fuel Type</th><th>Delivered (L)</th><th>Deliveries</th>  <th>Sold (L)</th><th>Revenue</th><th>Transactions</th>  <th>Variance (L)</th><th>Variance Alerts</th>  </tr>  </thead>  <tbody>  <?php  $all_types = array_unique(array_merge(array_keys($fuel_sold), array_keys($deliveries)));  if(empty($all_types)):?>  <tr><td colspan="8" style="text-align:center;color:#999;padding:40px;">No activity in selected period.</td></tr>  <?php else: foreach($all_types as $ft):  $sold  = $fuel_sold[$ft]  ?? ['liters'=>0,'revenue'=>0,'txn_cnt'=>0];  $deliv = $deliveries[$ft] ?? ['liters'=>0,'cnt'=>0,'suppliers'=>'—'];  $var  = $variances[$ft]  ?? ['total_variance'=>0,'alert_cnt'=>0];  $net_variance = (float)$var['total_variance'];  ?>  <tr>  <td><strong><?= htmlspecialchars($ft) ?></strong></td>  <td><?= number_format($deliv['liters'],2) ?></td>  <td><?= $deliv['cnt'] ?> (<?= htmlspecialchars($deliv['suppliers']) ?>)</td>  <td><?= number_format($sold['liters'],2) ?></td>  <td>₱<?= number_format($sold['revenue'],2) ?></td>  <td><?= $sold['txn_cnt'] ?></td>  <td style="color:<?= abs($net_variance)>0 ? '#dc3545':'#28a745' ?>;font-weight:600;">  <?= $net_variance != 0 ? number_format($net_variance,2) : '—' ?>  </td>  <td><?= $var['alert_cnt'] > 0 ? '<span style="color:#dc3545;font-weight:700;">'.$var['alert_cnt'].'</span>' : '—' ?></td>  </tr>  <?php endforeach; endif; ?>  </tbody>
</table>
</div>

<?php
// Include footer if running in standalone mode
if ($is_standalone) {  require_once __DIR__ . '/../../partials/footer.php';
}
?>
