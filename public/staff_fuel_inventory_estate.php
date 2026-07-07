<?php
// ============================================================
// 17-Tanker Fuel Inventory Estate View
// Shows: Beginning + Purchases = Total Available - Sales - Calibration = Ending Balance
// Compare with Actual Dip Reading → Variance & Status
// ============================================================
$page_id = 'fuel_inventory_estate';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();  $me  = current_user();
$role  = role_key($me['role'] ?? 'staff');
$station_id = user_station_id();  if (!in_array($role, ['staff', 'cashier', 'pump_attendant', 'manager', 'admin', 'superadmin'])) {  header('Location: dashboard.php');  exit;
}  // ── 17-Tanker Configuration ──
$TANK_CONFIG_17 = [  ['fuel_type'=>'Diesel',  'label'=>'DIESEL 1 - 1',  'tank'=>'Underground Tank #1',  'tanker_num'=>1,  'capacity'=>50000],  ['fuel_type'=>'Diesel',  'label'=>'DIESEL 1 - 2',  'tank'=>'Underground Tank #2',  'tanker_num'=>2,  'capacity'=>50000],  ['fuel_type'=>'Diesel',  'label'=>'DIESEL 1 - 3',  'tank'=>'Underground Tank #3',  'tanker_num'=>3,  'capacity'=>50000],  ['fuel_type'=>'Diesel',  'label'=>'DIESEL 1 - 4',  'tank'=>'Underground Tank #4',  'tanker_num'=>4,  'capacity'=>50000],  ['fuel_type'=>'Diesel',  'label'=>'DIESEL 2 - 5',  'tank'=>'Underground Tank #5',  'tanker_num'=>5,  'capacity'=>50000],  ['fuel_type'=>'Diesel',  'label'=>'DIESEL 2 - 6',  'tank'=>'Underground Tank #6',  'tanker_num'=>6,  'capacity'=>50000],  ['fuel_type'=>'Kerosene',  'label'=>'KEROSENE - 1',  'tank'=>'Underground Tank #7',  'tanker_num'=>1,  'capacity'=>20000],  ['fuel_type'=>'Turbo Diesel', 'label'=>'TURBO DIESEL - 1', 'tank'=>'Underground Tank #8',  'tanker_num'=>1,  'capacity'=>45000],  ['fuel_type'=>'Turbo Diesel', 'label'=>'TURBO DIESEL - 2', 'tank'=>'Underground Tank #9',  'tanker_num'=>2,  'capacity'=>45000],  ['fuel_type'=>'XCS Plus',  'label'=>'XCS PLUS - 1',  'tank'=>'Underground Tank #10', 'tanker_num'=>1,  'capacity'=>20000],  ['fuel_type'=>'XCS Plus',  'label'=>'XCS PLUS - 2',  'tank'=>'Underground Tank #11', 'tanker_num'=>2,  'capacity'=>20000],  ['fuel_type'=>'XCS Plus',  'label'=>'XCS PLUS - 3',  'tank'=>'Underground Tank #12', 'tanker_num'=>3,  'capacity'=>20000],  ['fuel_type'=>'XCS Plus',  'label'=>'XCS PLUS - 4',  'tank'=>'Underground Tank #13', 'tanker_num'=>4,  'capacity'=>20000],  ['fuel_type'=>'XTRA UNL',  'label'=>'XTRA UNL 1 - 1',  'tank'=>'Underground Tank #14', 'tanker_num'=>1,  'capacity'=>20000],  ['fuel_type'=>'XTRA UNL',  'label'=>'XTRA UNL 1 - 2',  'tank'=>'Underground Tank #15', 'tanker_num'=>2,  'capacity'=>20000],  ['fuel_type'=>'XTRA UNL',  'label'=>'XTRA UNL 2 - 3',  'tank'=>'Underground Tank #16', 'tanker_num'=>3,  'capacity'=>20000],  ['fuel_type'=>'XTRA UNL',  'label'=>'XTRA UNL 2 - 4',  'tank'=>'Underground Tank #17', 'tanker_num'=>4,  'capacity'=>20000],
];  // ── Centralized lookups (same logic as manager_inventory_fuel.php) ──  // fuel_inventory by fuel_type key
$fi_lookup = [];
try {  $s = $pdo->prepare("SELECT fuel_type, current_level, current_stock, capacity, price_per_liter, status, last_updated FROM fuel_inventory WHERE station_id = ?");  $s->execute([$station_id]);  foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {  $fi_lookup[strtolower(trim($row['fuel_type']))] = $row;  }
} catch (Exception $e) {}  // Today's deliveries per tank_assigned
$del_lookup = [];
try {  $s = $pdo->prepare("SELECT tank_assigned, SUM(delivery_liters) AS total_del FROM fuel_deliveries WHERE station_id=? AND DATE(delivery_date)=CURDATE() AND status='Verified' GROUP BY tank_assigned");  $s->execute([$station_id]);  foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {  $del_lookup[strtolower(trim($row['tank_assigned']))] = (float)$row['total_del'];  }
} catch (Exception $e) {}  // Today's sales per fuel_type
$sales_lookup = [];
try {  $s = $pdo->prepare("SELECT fuel_type, SUM(liters_sold) AS total_sales FROM fuel_transactions WHERE station_id=? AND DATE(transaction_date)=CURDATE() AND status='Verified' GROUP BY fuel_type");  $s->execute([$station_id]);  foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {  $sales_lookup[strtolower(trim($row['fuel_type']))] = (float)$row['total_sales'];  }
} catch (Exception $e) {}  // Today's calibration/adjustments per fuel_type
$adj_lookup = [];
try {  $s = $pdo->prepare("SELECT fi.fuel_type, COALESCE(SUM(fa.liters),0) AS total_adj FROM fuel_adjustments fa JOIN fuel_inventory fi ON fa.fuel_type_id=fi.fuel_type_id AND fi.station_id=fa.station_id WHERE fa.station_id=? AND DATE(fa.adjustment_date)=CURDATE() GROUP BY fi.fuel_type");  $s->execute([$station_id]);  foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {  $adj_lookup[strtolower(trim($row['fuel_type']))] = (float)$row['total_adj'];  }
} catch (Exception $e) {}  // ── Build 17-row dataset using same split logic as manager ──
$tanker_data = [];
foreach ($TANK_CONFIG_17 as $tank) {  $ft_key  = strtolower(trim($tank['fuel_type']));  // XTRA UNL split  if ($ft_key === 'xtra unl') {  if (strpos(strtolower($tank['label']), 'xtra unl 1') !== false) {  $ft_key = 'xtra unl 1';  } elseif (strpos(strtolower($tank['label']), 'xtra unl 2') !== false) {  $ft_key = 'xtra unl 2';  }  }  $tank_key = strtolower(trim($tank['tank']));  $inv  = $fi_lookup[$ft_key] ?? null;  $capacity  = isset($tank['capacity']) ? (float)$tank['capacity'] : 20000;  $cur_level = $inv ? (float)($inv['current_level'] ?? $inv['current_stock'] ?? 0) : 0;  // Count tanks in this sub-group  $same_type_count = count(array_filter($TANK_CONFIG_17, function($t) use ($ft_key) {  $k = strtolower(trim($t['fuel_type']));  if ($k === 'xtra unl') {  if (strpos(strtolower($t['label']), 'xtra unl 1') !== false) { $k = 'xtra unl 1'; }  elseif (strpos(strtolower($t['label']), 'xtra unl 2') !== false) { $k = 'xtra unl 2'; }  }  return $k === $ft_key;  }));  $purchases  = $del_lookup[$tank_key] ?? 0;  $sales_total = $sales_lookup[$ft_key] ?? 0;  $adj_total  = $adj_lookup[$ft_key]  ?? 0;  $sales  = $same_type_count > 0 ? round($sales_total / $same_type_count, 2) : 0;  $calibration = $same_type_count > 0 ? round($adj_total  / $same_type_count, 2) : 0;  $beginning  = $same_type_count > 0 ? round($cur_level / $same_type_count, 2) : 0;  $total_available = $beginning + $purchases;  $ending_balance  = max(0, $total_available - $sales - $calibration);  $actual_dip  = $ending_balance; // no separate dip reading  $variance  = 0;  // Status: same thresholds as manager_inventory_fuel.php  $fill_pct = $capacity > 0 ? ($ending_balance / $capacity) * 100 : 0;  if  ($ending_balance <= 0) { $status = 'Out of Stock'; $status_color = '#dc3545'; }  elseif  ($fill_pct <= 10)  { $status = 'Critical';  $status_color = '#dc3545'; }  elseif  ($fill_pct <= 25)  { $status = 'Low';  $status_color = '#fd7e14'; }  else  { $status = 'Available';  $status_color = '#28a745'; }  $tanker_data[] = [  'config'  => $tank,  'beginning'  => $beginning,  'purchases'  => $purchases,  'total_available' => $total_available,  'sales'  => $sales,  'calibration'  => $calibration,  'ending_balance'  => $ending_balance,  'actual_dip'  => $actual_dip,  'variance'  => $variance,  'fill_pct'  => round($fill_pct, 1),  'status'  => $status,  'status_color'  => $status_color,  'last_dip_date'  => $inv['last_updated'] ?? null,  ];
}  ?>
<!DOCTYPE html>
<html lang="en">
<head>  <meta charset="UTF-8">  <meta name="viewport" content="width=device-width, initial-scale=1.0">  <title>17-Tanker Fuel Inventory Estate - Petron Management System</title>  <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">  <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body style="background:#f8f9fa;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;margin:0;padding:0;">  <!-- Simple Top Bar -->
<div style="background:#002F70;color:#fff;padding:12px 24px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 2px 4px rgba(0,0,0,.1);">  <div style="display:flex;align-items:center;gap:12px;">  <img src="<?php echo '../' . get_system_logo_url(isset($station_id) ? (int)$station_id : (isset($user['station_id']) ? (int)$user['station_id'] : 0)); ?>" alt="Petron" style="height:32px;">  <div>  <div style="font-size:14px;font-weight:700;">Petron Station Management System</div>  <div style="font-size:11px;opacity:0.9;"><?= htmlspecialchars($me['station_name'] ?? 'Station') ?></div>  </div>  </div>  <div style="display:flex;align-items:center;gap:16px;">  <span style="font-size:13px;"><i class="fas fa-user-circle"></i> <?= htmlspecialchars($me['full_name'] ?? 'User') ?></span>  <a href="logout.php" style="color:#fff;text-decoration:none;font-size:13px;"><i class="fas fa-sign-out-alt"></i> Logout</a>  </div>
</div>  <!-- Main Content Area -->
<div style="max-width:1800px;margin:0 auto;padding:24px;">  <style>
.estate-head { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:20px; flex-wrap:wrap; }
.estate-head h1 { margin:0 0 4px; font-size:22px; font-weight:700; color:#00264D; display:flex; align-items:center; gap:9px; }
.estate-subtitle { font-size:13px; color:#6b7280; text-transform:uppercase; letter-spacing:.3px; }
.estate-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.estate-btn { display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; border:none; text-decoration:none; transition:all .13s; height:36px; white-space:nowrap; }
.estate-btn-back { background:#6c757d; color:#fff; } .estate-btn-back:hover { background:#545b62; color:#fff; }
/* Table */
.estate-card { background:#fff; border:1px solid #e2e8f0; border-radius:11px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.04); width:100%; margin-bottom:20px; }
.estate-card-hd { display:flex; align-items:center; justify-content:space-between; padding:14px 16px; border-bottom:1px solid #f1f5f9; flex-wrap:wrap; gap:8px; }
.estate-card-title { font-size:13px; font-weight:700; color:#00264D; text-transform:uppercase; letter-spacing:.3px; margin:0; }
.estate-tbl-wrap { width:100%; overflow:hidden; }
.estate-tbl { width:100%; table-layout:auto; border-collapse:collapse; font-size:11px; }
.estate-tbl thead tr { background:#002F70; }
.estate-tbl thead th { padding:10px 8px; text-align:left; font-size:10px; font-weight:700; color:#fff; text-transform:uppercase; letter-spacing:.3px; white-space:normal; line-height:1.3; vertical-align:top; }
.estate-tbl thead th.r { text-align:right; }
.estate-tbl tbody tr { border-bottom:1px solid #f1f5f9; transition:background .1s; }
.estate-tbl tbody tr:hover { background:#eff6ff; }
.estate-tbl tbody td { padding:8px; color:#334155; vertical-align:middle; white-space:nowrap; line-height:1.5; }
.estate-tbl tbody td.r { text-align:right; }
.status-badge { display:inline-block; padding:3px 8px; border-radius:4px; font-size:10px; font-weight:600; white-space:nowrap; }
.var-pos { color:#dc3545; font-weight:600; }
.var-neg { color:#28a745; font-weight:600; }
.var-zero { color:#6c757d; }
</style>  <div class="estate-head">  <div>  <h1><i class="fas fa-gas-pump"></i> 17-Tanker Fuel Inventory Estate</h1>  <div class="estate-subtitle">BEGINNING + PURCHASES - SALES - CALIBRATION = ENDING vs ACTUAL DIP</div>  </div>  <div class="estate-actions">  <a href="staff_inventory_fuel.php" class="estate-btn estate-btn-back"><i class="fas fa-arrow-left"></i> Back</a>  </div>
</div>  <div class="estate-card">  <div class="estate-card-hd">  <h3 class="estate-card-title"><i class="fas fa-table"></i> 17 Tanker Inventory Flow (Today: <?= date('M d, Y') ?>)</h3>  <span style="font-size:11px;color:#64748b;">Real-time inventory tracking with variance detection</span>  </div>  <div class="estate-tbl-wrap">  <table class="estate-tbl">  <thead>  <tr>  <th>Fuel Type</th>  <th>Tanker Reference</th>  <th class="r">Beginning</th>  <th class="r">Purchases</th>  <th class="r">Total Available</th>  <th class="r">Sales</th>  <th class="r">Calibration</th>  <th class="r">Ending Balance</th>  <th class="r">Actual Dip</th>  <th class="r">Variance</th>  <th>Status</th>  <th>Last Dip</th>  </tr>  </thead>  <tbody>
<?php if (empty($tanker_data)): ?>  <tr>  <td colspan="12">  <div class="empty-state">  <i class="fas fa-gas-pump"></i>  <p>No inventory data available</p>  </div>  </td>  </tr>
<?php else: ?>
<?php foreach ($tanker_data as $data): ?>  <tr>  <td><?= htmlspecialchars($data['config']['fuel_type']) ?></td>  <td style="font-weight:600;color:#002F70;"><?= htmlspecialchars($data['config']['label']) ?></td>  <td class="r"><?= number_format($data['beginning'], 2) ?> L</td>  <td class="r"><?= number_format($data['purchases'], 2) ?> L</td>  <td class="r" style="font-weight:600;color:#002F70;"><?= number_format($data['total_available'], 2) ?> L</td>  <td class="r"><?= number_format($data['sales'], 2) ?> L</td>  <td class="r"><?= number_format($data['calibration'], 2) ?> L</td>  <td class="r" style="font-weight:600;"><?= number_format($data['ending_balance'], 2) ?> L</td>  <td class="r" style="font-weight:700;color:#002F70;"><?= number_format($data['actual_dip'], 2) ?> L</td>  <td class="r">
<?php  $var = $data['variance'];  $absVar = abs($var);  if ($absVar < 0.01) {  echo '<span class="var-zero">0.0 L</span>';  } elseif ($var > 0) {  echo '<span class="var-neg">+' . number_format($var, 2) . ' L</span>';  } else {  echo '<span class="var-pos">' . number_format($var, 2) . ' L</span>';  }
?>  </td>  <td>  <span class="status-badge" style="background-color:<?= htmlspecialchars($data['status_color']) ?>15;color:<?= htmlspecialchars($data['status_color']) ?>;">  <?= htmlspecialchars($data['status']) ?>  </span>  </td>  <td style="font-size:10px;color:#64748b;">  <?= $data['last_dip_date'] ? date('M d, h:i A', strtotime($data['last_dip_date'])) : '—' ?>  </td>  </tr>
<?php endforeach; ?>
<?php endif; ?>  </tbody>  </table>  </div>
</div>  <script>
// Auto-refresh every 5 minutes
setInterval(function(){  location.reload();
}, 300000);  // Print functionality
function printTable(){  window.print();
}  // Export to CSV
function exportCSV(){  const table = document.querySelector('.estate-tbl');  let csv = [];  // Headers  const headers = [];  table.querySelectorAll('thead th').forEach(th => {  headers.push(th.textContent.trim());  });  csv.push(headers.join(','));  // Data rows  table.querySelectorAll('tbody tr').forEach(tr => {  const row = [];  tr.querySelectorAll('td').forEach(td => {  row.push('"' + td.textContent.trim().replace(/"/g, '""') + '"');  });  csv.push(row.join(','));  });  // Download  const blob = new Blob([csv.join('\n')], { type: 'text/csv' });  const url = window.URL.createObjectURL(blob);  const a = document.createElement('a');  a.href = url;  a.download = '17_Tanker_Inventory_' + new Date().toISOString().split('T')[0] + '.csv';  a.click();  window.URL.revokeObjectURL(url);
}
</script>  <style media="print">
.estate-head .estate-actions { display:none; }
.estate-btn { display:none; }
body { background:#fff; }
.estate-card { box-shadow:none; border:1px solid #000; }
</style>  </div><!-- End Main Content -->  <script>
// Auto-refresh every 5 minutes (300000ms)
setTimeout(function() {  location.reload();
}, 300000);  // Esc key handler
document.addEventListener('keydown', function(e) {  if (e.key === 'Escape') {  window.location.href = 'staff_inventory_fuel.php';  }
});
</script>  </body>
</html>
