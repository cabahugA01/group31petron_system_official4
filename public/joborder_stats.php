<?php
$page_id = 'joborder_stats';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

// RBAC Enforcement: Admin or Super Admin can access
$me = current_user();
$role = role_key($me['role'] ?? '');
if(!in_array($role, ['admin','superadmin','manager'])){
    echo "<div style='padding:20px;color:red;'>Access Denied. Super Admin privileges required.</div>";
    exit;
}

// Auto-migration for older schemas that might be missing columns
try { $pdo->exec("ALTER TABLE job_orders ADD COLUMN user_id INT NULL AFTER station_id;"); } catch(PDOException $e) { /* ignore */ }
try { $pdo->exec("ALTER TABLE job_orders ADD COLUMN mechanic_id INT NULL AFTER service_type;"); } catch(PDOException $e) { /* ignore */ }

// FIX: Ensure job_orders table exists to prevent "Base table not found" error
// Note: Table structure matches the main SQL schema

// --- 1. FILTERS & EXPORT ---
$f_station = $_GET['station'] ?? '';
$f_start = $_GET['start'] ?? date('Y-m-01');
$f_end = $_GET['end'] ?? date('Y-m-d');
$f_status = $_GET['status'] ?? '';

// Export Handler
if(($_GET['export'] ?? '') === 'csv'){
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="job_orders_report.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Date', 'Station', 'Mechanic', 'Vehicle', 'Service', 'Status', 'Labor', 'Parts', 'Total']);
    
    $sql = "SELECT j.*, s.name as station_name, u.username as mechanic, sr.flat_rate as service_rate
            FROM job_orders j 
            LEFT JOIN stations s ON j.station_id = s.id 
            LEFT JOIN users u ON j.assigned_mechanic_id = u.id 
            LEFT JOIN service_categories sc ON j.service_category_id = sc.id
            LEFT JOIN service_rates sr ON sc.id = sr.service_category_id AND sr.station_id = j.station_id
            WHERE (j.created_at BETWEEN ? AND ?)";
    $params = [$f_start.' 00:00:00', $f_end.' 23:59:59'];
    if($f_station){ $sql .= " AND j.station_id = ?"; $params[] = $f_station; }
    if($f_status){ $sql .= " AND j.status = ?"; $params[] = $f_status; }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
        $total_cost = $row['service_rate'] ?: 0;
        fputcsv($out, [
            $row['id'], $row['created_at'], $row['station_name'], $row['mechanic'],
            $row['vehicle_plate'] . ' (' . $row['vehicle_type'] . ')',
            $row['service_description'], $row['status'],
            $total_cost, '0', $total_cost
        ]);
    }
    fclose($out);
    exit;
}

// --- 2. DATA FETCHING ---
// Fetch Stations for Filter
$stations = $pdo->query("SELECT id, name FROM stations")->fetchAll(PDO::FETCH_KEY_PAIR);

// Main Query
$sql = "SELECT j.*, s.name as station_name, u.username as mechanic, sc.name as service_category, sr.flat_rate as service_rate
        FROM job_orders j 
        LEFT JOIN stations s ON j.station_id = s.id 
        LEFT JOIN users u ON j.assigned_mechanic_id = u.id 
        LEFT JOIN service_categories sc ON j.service_category_id = sc.id
        LEFT JOIN service_rates sr ON sc.id = sr.service_category_id AND (sr.station_id = j.station_id OR sr.station_id IS NULL)
        WHERE (j.created_at BETWEEN ? AND ?)";
$params = [$f_start.' 00:00:00', $f_end.' 23:59:59'];

if($f_station){ $sql .= " AND j.station_id = ?"; $params[] = $f_station; }
if($f_status){ $sql .= " AND j.status = ?"; $params[] = $f_status; }

$sql .= " ORDER BY j.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- 3. AGGREGATION & METRICS ---
$metrics = ['total'=>0, 'labor'=>0, 'parts'=>0, 'pending'=>0, 'completed'=>0, 'verified'=>0];
$svcStats = [];

foreach($jobs as $j){
    $service_rate = $j['service_rate'] ?: 0;
    $metrics['total'] += $service_rate;
    
    if($j['status'] === 'Pending') $metrics['pending']++;
    if($j['status'] === 'Completed') $metrics['completed']++;
    if($j['status'] === 'Verified') $metrics['verified']++;
    
    $svc = $j['service_category'] ?: $j['service_description'] ?: 'General Service';
    if(!isset($svcStats[$svc])) $svcStats[$svc] = 0;
    $svcStats[$svc]++;
}
arsort($svcStats);
$topServices = array_slice($svcStats, 0, 5, true);

// Fetch Audit Logs for Job Orders
$auditSql = "SELECT l.*, u.username, s.name as station_name FROM activity_logs l 
             LEFT JOIN users u ON l.user_id = u.id 
             LEFT JOIN stations s ON u.station_id = s.id 
             WHERE l.action LIKE '%Job Order%' 
             ORDER BY l.created_at DESC LIMIT 10";
$auditLogs = $pdo->query($auditSql)->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../partials/header.php';
?>
<style>
    .status-badge { padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 0.85em; }
    .st-Pending { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
    .st-Completed { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    .st-Cancelled { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    .st-Verified { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
</style>

  <div class="page-head">
    <div>
      <h1 class="h1">Job Order Oversight</h1>
      <div class="sub">Nationwide service tracking, revenue analysis, and mechanic performance.</div>
    </div>
    <div style="display:flex; align-items:center; gap:10px;">
        <a href="joborder_stats.php?export=csv&station=<?php echo urlencode($f_station); ?>&start=<?php echo $f_start; ?>&end=<?php echo $f_end; ?>&status=<?php echo $f_status; ?>" class="btn ghost"><i class="fas fa-download"></i> Export Report</a>
    </div>
  </div>
  
  <!-- FILTERS -->
  <section class="card" style="padding:15px; margin-bottom:20px; background:#f8f9fa;">
    <form method="get" style="display:flex; gap:15px; align-items:end; flex-wrap:wrap;">
        <div>
            <label class="lbl" style="font-size:0.85em;">Station</label>
            <select name="station" class="inp" style="padding:5px;">
                <option value="">All Stations</option>
                <?php foreach($stations as $id => $name): ?>
                    <option value="<?php echo $id; ?>" <?php echo $f_station == $id ? 'selected' : ''; ?>><?php echo htmlspecialchars($name); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="lbl" style="font-size:0.85em;">Date Range</label>
            <div style="display:flex; gap:5px;">
                <input type="date" name="start" value="<?php echo $f_start; ?>" class="inp" style="padding:5px;">
                <span style="align-self:center;">to</span>
                <input type="date" name="end" value="<?php echo $f_end; ?>" class="inp" style="padding:5px;">
            </div>
        </div>
        <div>
            <label class="lbl" style="font-size:0.85em;">Status</label>
            <select name="status" class="inp" style="padding:5px;">
                <option value="">All Statuses</option>
                <option value="Pending" <?php echo $f_status == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="In Progress" <?php echo $f_status == 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                <option value="Completed" <?php echo $f_status == 'Completed' ? 'selected' : ''; ?>>Completed</option>
                <option value="Verified" <?php echo $f_status == 'Verified' ? 'selected' : ''; ?>>Verified</option>
                <option value="Cancelled" <?php echo $f_status == 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
            </select>
        </div>
        <button type="submit" class="btn primary" style="padding:6px 15px;">Filter</button>
    </form>
  </section>

  <!-- METRICS -->
  <section class="cards four">
    <div class="card metric">
        <div class="metric-label">Total Revenue</div>
        <div class="metric-value">₱<?php echo number_format($metrics['total'], 2); ?></div>
        <div class="metric-sub">₱<?php echo number_format($metrics['total'], 2); ?> Total Revenue</div>
    </div>
    <div class="card metric">
        <div class="metric-label">Services Performed</div>
        <div class="metric-value blue"><?php echo count($jobs); ?></div>
        <div class="metric-sub"><?php echo $metrics['completed']; ?> Completed | <?php echo $metrics['verified']; ?> Verified</div>
    </div>
    <div class="card metric">
        <div class="metric-label">Pending Verification</div>
        <div class="metric-value <?php echo $metrics['pending'] > 0 ? 'red' : 'green'; ?>"><?php echo $metrics['pending']; ?></div>
        <div class="metric-sub">Requires Admin Action</div>
    </div>
    <div class="card metric">
        <div class="metric-label">Top Service</div>
        <div class="metric-value" style="font-size:1.2em;"><?php echo key($topServices) ?? 'N/A'; ?></div>
        <div class="metric-sub"><?php echo current($topServices) ?? 0; ?> requests</div>
    </div>
  </section>

  <!-- JOB ORDER LIST -->
  <section class="card" style="margin-top:20px; padding:20px;">
    <h2 class="h2">Job Order Records</h2>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Station</th>
                    <th>Vehicle / Customer</th>
                    <th>Service</th>
                    <th>Mechanic</th>
                    <th>Cost Breakdown</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($jobs as $j): 
                    $stClass = 'st-' . str_replace(' ', '', $j['status']);
                ?>
                <tr>
                    <td><?php echo date('M d, Y', strtotime($j['created_at'])); ?><br><small><?php echo date('H:i', strtotime($j['created_at'])); ?></small></td>
                    <td><?php echo htmlspecialchars($j['station_name']); ?></td>
                    <td>
                        <b><?php echo htmlspecialchars($j['vehicle_plate']); ?></b><br>
                        <small><?php echo htmlspecialchars($j['vehicle_type']); ?></small>
                    </td>
                    <td><?php echo htmlspecialchars($j['service_category'] ?: $j['service_description']); ?></td>
                    <td><?php echo htmlspecialchars($j['mechanic'] ?? 'Unassigned'); ?></td>
                    <td>
                        <div>Rate: <b>₱<?php echo number_format($j['service_rate'] ?: 0, 2); ?></b></div>
                        <small style="color:#666;">Duration: <?php echo $j['estimated_duration'] ?: 60; ?> mins</small>
                    </td>
                    <td><span class="status-badge <?php echo $stClass; ?>"><?php echo htmlspecialchars($j['status']); ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($jobs)): ?><tr><td colspan="7">No job orders found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
  </section>

  <!-- AUDIT TRAIL -->
  <section class="card" style="margin-top:20px; padding:20px;">
    <h2 class="h2"><i class="fas fa-shield-alt"></i> Job Order Audit Trail</h2>
    <div class="sub">Recent modifications, overrides, or status changes.</div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Action</th>
                    <th>Details</th>
                    <th>Time</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($auditLogs as $log): ?>
                <tr>
                    <td><?php echo htmlspecialchars($log['username']); ?><br><small><?php echo htmlspecialchars($log['station_name']); ?></small></td>
                    <td><?php echo htmlspecialchars($log['action']); ?></td>
                    <td><?php echo htmlspecialchars($log['details']); ?></td>
                    <td><?php echo date('M d H:i', strtotime($log['created_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($auditLogs)): ?><tr><td colspan="4">No audit logs found for job orders.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
  </section>

<?php include __DIR__ . '/../partials/footer.php'; ?>
