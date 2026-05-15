<?php
$page_id = 'kpis';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();
include __DIR__ . '/../partials/header.php';

$me = current_user();
$role = role_key($me['role'] ?? 'staff');

// Manager, Admin, and Super Admin can view KPIs
if (!in_array($role, ['manager','admin','superadmin'])) {
    header('Location: dashboard.php');
    exit;
}

$isSuper = ($role === 'superadmin');

// Aggregation Logic
$sales = read_json('sales.json', []);
// Fetch Stations from DB for Dropdown
$stations = [];
try {
    $stmt = $pdo->query("SELECT * FROM stations ORDER BY name");
    $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {
    $stations = read_json('stations.json', []);
}
$fuel_readings = read_json('fuel_readings.json', []);

// Filter Logic
$f_station = $_GET['station'] ?? '';
if (!$isSuper) {
    $f_station = user_station_id();
    $station_count = 1; 
}

if ($f_station) {
    $sales = array_filter($sales, function($s) use ($f_station) { return ($s['station_id'] ?? '') == $f_station; });
    if($isSuper) $station_count = 1;
} else {
    $station_count = count($stations);
}

// 1. Total Sales (DB Preference)
try {
    $stmt = $pdo->query("SELECT SUM(total) FROM sales");
    $total_sales = $stmt->fetchColumn() ?: 0;
} catch (Exception $e) {
    $total_sales = array_reduce($sales, fn($carry, $item) => $carry + ($item['total'] ?? 0), 0);
}

// --- NEW KPI CALCULATIONS ---
$fuel_variance_alerts = 0;
$low_stock_alerts = 0;
$pending_approvals = 0;
$notifications = [];

// 1. Fuel Variance (Count negative computed liters & RBAC Filter)
foreach($fuel_readings as $fr) {
    // RBAC: Filter for non-superadmins
    if ($f_station && ($fr['station_id'] ?? '') != $f_station) continue;

    if(($fr['computed_liters'] ?? 0) < 0) {
        $fuel_variance_alerts++;
        $notifications[] = "<i class='fas fa-gas-pump'></i> Station " . ($fr['station_id']??'?') . ": Fuel variance detected";
    }
}

// 2. DB Metrics (Low Stock & Pending Jobs)
try {
    // Low Stock (Detailed)
    $sqlInv = "SELECT s.name, i.product_name FROM station_inventory i LEFT JOIN stations s ON i.station_id = s.id WHERE i.stock_level <= 20";
    if ($f_station) $sqlInv .= " AND i.station_id = " . intval($f_station);
    $stmt = $pdo->query($sqlInv);
    $lowStockItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $low_stock_alerts = count($lowStockItems);
    foreach($lowStockItems as $item) $notifications[] = "<i class='fas fa-box'></i> Station " . ($item['name']??'Unknown') . ": Low stock on " . $item['product_name'];
    
    // Pending Job Orders (Detailed)
    $sqlJobs = "SELECT s.name, j.id FROM job_orders j LEFT JOIN stations s ON j.station_id = s.id WHERE j.status = 'Pending'";
    if ($f_station) $sqlJobs .= " AND j.station_id = " . intval($f_station);
    $stmt = $pdo->query($sqlJobs);
    $pendingJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $pending_approvals = count($pendingJobs);
    foreach($pendingJobs as $item) $notifications[] = "<i class='fas fa-tools'></i> Station " . ($item['name']??'Unknown') . ": Job Order #" . $item['id'] . " pending";
} catch (Exception $e) { /* Tables might not exist */ }

// 2. Active Stations (DB)
try {
    $active_stations = $pdo->query("SELECT COUNT(*) FROM stations WHERE status='active'")->fetchColumn();
} catch (Exception $e) {
    $active_stations = $station_count;
}

$unreadCount = count($notifications);
?>
<style>
@media print {
  /* Hide navigation and interactive elements for printing */
  .sidebar, .top-header, .actions, .btn, .nav, .page-head .sub, .no-print { display: none !important; }
  .main { margin: 0; padding: 0; width: 100%; height: auto; overflow: visible; }
  .card { border: 1px solid #ddd; break-inside: avoid; box-shadow: none; margin-bottom: 20px; }
  body { background: white; font-size: 12pt; color: black; }
  h1 { font-size: 18pt; margin-bottom: 10px; color: black; }
}
</style>

  <div class="page-head">
    <div>
      <h1 class="h1"><?php echo $isSuper ? 'Super Admin Dashboard' : 'Station KPIs'; ?></h1>
      <div class="sub"><?php echo $isSuper ? 'Nationwide performance overview and key metrics.' : 'Performance metrics for your station.'; ?></div>
    </div>
    
    <div class="actions" style="display:flex; align-items:center; gap:10px;">
        <?php if($isSuper): ?>
        <form method="get" style="margin-right:10px;">
            <select name="station" onchange="this.form.submit()" class="inp" style="padding:6px; font-size:0.9em;">
                <option value="">All Stations</option>
                <?php foreach($stations as $s): ?>
                    <option value="<?php echo $s['id']; ?>" <?php echo $f_station == $s['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php endif; ?>

        <?php if($isSuper): ?>
        <!-- Redirect to Reports for PDF/Excel Exports -->
        <a href="reports.php" class="btn primary" title="Go to Reports for PDF/Excel Exports"><i class="fas fa-file-export"></i> Reports & Export</a>
        <!-- Direct Browser Print -->
        <button class="btn ghost" onclick="window.print()" title="Print this dashboard view"><i class="fas fa-print"></i> Print Summary</button>
        <?php endif; ?>
    </div>
  </div>
  
  <!-- PRIMARY KPIs -->
  <div class="metrics cards five" style="margin-bottom: 20px;">
    <div class="card metric"><div class="metric-label">Total Sales</div><div class="metric-value">₱<?php echo number_format($total_sales, 2); ?></div><div class="metric-sub"><?php echo $isSuper ? 'Nationwide' : 'This Station'; ?></div></div>
    <div class="card metric"><div class="metric-label">Active Stations</div><div class="metric-value"><?php echo $active_stations; ?></div><div class="metric-sub">Registered</div></div>
    
    <!-- NEW: Fuel Variance -->
    <div class="card metric">
        <div class="metric-label">
            Fuel Variance Alerts
            <?php if($fuel_variance_alerts > 0): ?>
                <span style="background:#E30613; color:white; padding:2px 6px; border-radius:10px; font-size:0.7em; vertical-align:middle; margin-left:5px;"><?php echo $fuel_variance_alerts; ?></span>
            <?php endif; ?>
        </div>
        <div class="metric-value <?php echo $fuel_variance_alerts > 0 ? 'red' : 'green'; ?>"><?php echo $fuel_variance_alerts; ?></div>
        <div class="metric-sub">Unresolved discrepancies</div>
    </div>
    
    <!-- NEW: Low Stock -->
    <div class="card metric">
        <div class="metric-label">
            Low Stock Alerts
            <?php if($low_stock_alerts > 0): ?>
                <span style="background:#ffc107; color:#333; padding:2px 6px; border-radius:10px; font-size:0.7em; vertical-align:middle; margin-left:5px;"><?php echo $low_stock_alerts; ?></span>
            <?php endif; ?>
        </div>
        <div class="metric-value <?php echo $low_stock_alerts > 0 ? 'orange' : 'green'; ?>"><?php echo $low_stock_alerts; ?></div>
        <div class="metric-sub">Items with critical levels</div>
    </div>

    <!-- Pending Shift Approvals -->
    <div class="card metric">
        <div class="metric-label">
            Pending Shift Approvals
            <?php if($pending_approvals > 0): ?>
                <span style="background:#007bff; color:white; padding:2px 6px; border-radius:10px; font-size:0.7em; vertical-align:middle; margin-left:5px;"><?php echo $pending_approvals; ?></span>
            <?php endif; ?>
        </div>
        <div class="metric-value <?php echo $pending_approvals > 0 ? 'blue' : 'green'; ?>"><?php echo $pending_approvals; ?></div>
        <div class="metric-sub">Reports flagged "pending"</div>
    </div>
  </div>

  <!-- SECONDARY KPIs & QUICK ACCESS -->
  <section class="grid-2">
    <!-- Quick Access Panel -->
    <div class="card no-print" style="padding: 15px;">
        <h3 class="h3" style="margin-bottom: 10px;"><i class="fas fa-compass"></i> Quick Access</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px;">
            <a href="oversight.php" class="btn ghost" style="text-align:left;"><i class="fas fa-eye"></i> Inventory Oversight</a>
            <a href="joborder_stats.php" class="btn ghost" style="text-align:left;"><i class="fas fa-tools"></i> Job Orders</a>
            <a href="/group31petron_system_official4/public/users.php" class="btn ghost" style="text-align:left;"><i class="fas fa-users"></i> User Management</a>
            <a href="reports.php" class="btn ghost" style="text-align:left;"><i class="fas fa-file-alt"></i> Full Reports</a>
            <a href="reports.php" class="btn ghost" style="text-align:left;"><i class="fas fa-file-export"></i> Export Data</a>
            <button onclick="window.print()" class="btn ghost" style="text-align:left; width: 100%;"><i class="fas fa-print"></i> Print Summary</button>
        </div>
    </div>
  </section>

<?php include __DIR__ . '/../partials/footer.php'; ?>
