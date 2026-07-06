<?php
if (session_status() === PHP_SESSION_NONE) session_start(); // Fix: Ensure session is active
$page_id = 'home';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();  $me = current_user();
// Normalize Role to ensure consistency
$raw_role = strtolower($me['role'] ?? 'staff');
$role = 'staff';
if ($raw_role === 'super admin' || $raw_role === 'superadmin') $role = 'superadmin';
elseif ($raw_role === 'admin' || $raw_role === 'manager') $role = 'admin';  include __DIR__ . '/../partials/header.php';  // Fetch data for KPIs
$todayISO = date('Y-m-d');
$stationId = user_station_id();  // Alerts
$alerts_count = 0;
$alerts_html = '';  // Low inventory
try {  $stmt = $pdo->prepare("SELECT COUNT(*) FROM station_inventory WHERE station_id = ? AND stock_level <= 20");  $stmt->execute([$stationId]);  $low_stock = $stmt->fetchColumn();  if($low_stock > 0){  $alerts_count += $low_stock;  $alerts_html .= "<div style='color:red; font-size:0.9em;'><i class='fas fa-exclamation-triangle'></i> Low stock items: $low_stock</div>";  }
} catch(Exception $e){}  // Fuel variance
$fuel_readings = read_json('fuel_readings.json', []);
foreach($fuel_readings as $fr) {  if(($fr['station_id']??'') == $stationId && ($fr['computed_liters'] ?? 0) < 0) {  $alerts_count++;  $alerts_html .= "<div style='color:red; font-size:0.9em;'><i class='fas fa-exclamation-triangle'></i> Fuel variance detected</div>";  break;  }
}
?>
<div class="page-head">  <div>  <h1 class="h1">Welcome, <?php echo htmlspecialchars($me['name'] ?? 'Admin'); ?></h1>  <div class="sub">KPIs, Alerts & Quick Actions</div>  </div>
</div>  <?php if($alerts_count > 0): ?>
<div class="card" style="padding:15px; margin-bottom:20px; border-left: 4px solid orange;">  <h3 class="h3"><i class="fas fa-exclamation-triangle'></i> Action Required</h3>  <?php echo $alerts_html; ?>
</div>
<?php endif; ?>  <section class="cards four">  <div class="card metric">  <div class="metric-label">Today's Sales</div>  <div class="metric-value" id="mTodaySales">₱0</div>  <div class="metric-sub green">↗ +0% vs yesterday</div>  <div class="metric-ico green"><i class="fas fa-money-bill-wave"></i></div>  </div>  <div class="card metric">  <div class="metric-label">Fuel Stock</div>  <div class="metric-value" id="mFuelStock">0 L</div>  <div class="metric-ico blue"><i class="fas fa-gas-pump"></i></div>  </div>  <div class="card metric">  <div class="metric-label">Inventory Items</div>  <div class="metric-value" id="mInvItems">0</div>  <div class="metric-ico purple"><i class="fas fa-box"></i></div>  </div>  <div class="card metric">  <div class="metric-label">Transactions Today</div>  <div class="metric-value" id="mTransactions">0</div>  <div class="metric-ico amber"><i class="fas fa-receipt"></i></div>  </div>
</section>  <section class="card" style="margin-bottom: 20px; border-left: 4px solid var(--petron-blue);">  <div class="card-head">  <div class="card-title"><i class="fas fa-rocket"></i> Quick Actions</div>  </div>  <div style="padding: 20px; display: flex; gap: 10px; flex-wrap: wrap;">  <a href="fuel_management.php" class="btn ghost">Fuel Management</a>  <a href="inventory.php" class="btn ghost">Inventory</a>  <a href="pos.php" class="btn ghost"><i class="fas fa-shopping-cart"></i> POS Transactions</a>  <a href="reports.php" class="btn ghost"><i class="fas fa-file-alt"></i> Reports</a>  <a href="settings.php" class="btn ghost"><i class="fas fa-cogs"></i> Station Settings</a>  </div>
</section>  <style>  .hover-card:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.1); transition: all 0.2s; }
</style>
<style>
/* ===== HOME KPI CARDS ===== */
.kpi-wrap{  max-width: 520px; /* left column width */
}
.kpi-card{  border: 1px solid #e5e7eb;  border-radius: 14px;  padding: 14px 16px;  margin-bottom: 12px;  background: #fff;  display: flex;  align-items: center;  justify-content: space-between;  gap: 12px;
}
.kpi-left .kpi-title{  font-size: 12px;  letter-spacing: .5px;  text-transform: uppercase;  color: #6b7280;  margin: 0;
}
.kpi-left .kpi-value{  font-size: 28px;  font-weight: 800;  margin: 2px 0 0;  line-height: 1.1;
}
.kpi-left .kpi-sub{  font-size: 12px;  color: #6b7280;  margin: 4px 0 0;
}
.kpi-ico{  width: 42px;  height: 42px;  border-radius: 12px;  display:flex;  align-items:center;  justify-content:center;  background: #f3f4f6;  flex: 0 0 42px;
}
.kpi-ico i{  font-size: 18px;  color: #111827;
}  /* ===== QUICK ACTIONS ===== */
.quick-card{  border: 1px solid #e5e7eb;  border-radius: 14px;  padding: 14px 16px;  background: #fff;
}
.quick-title{  display:flex;  align-items:center;  gap: 8px;  font-weight: 800;  margin-bottom: 10px;
}
.quick-actions{  display:flex;  flex-wrap: wrap;  gap: 10px;
}
.quick-actions a{  display:inline-flex;  align-items:center;  gap: 8px;  padding: 10px 12px;  border: 1px solid #e5e7eb;  border-radius: 12px;  text-decoration: none;  color: #111827;  font-size: 13px;  background: #fff;
}
.quick-actions a:hover{  background:#f9fafb;
}  /* responsive */
@media (max-width: 992px){  .kpi-wrap{ max-width: 100%; }
}
</style>  <?php include __DIR__ . '/../partials/footer.php'; ?>
