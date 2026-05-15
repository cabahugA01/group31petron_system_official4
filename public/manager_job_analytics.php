<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'manager_job_analytics';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();
$me = current_user();

$role = role_key($me['role'] ?? 'staff');
if (!in_array($role, ['manager','admin','superadmin'])) { header("Location: dashboard.php"); exit; }

// Get current user's station (managers see only their station)
$my_station_id = user_station_id($me['id']);
$is_manager = ($role === 'manager');

// Get filter parameters
$view = $_GET['view'] ?? 'service_breakdown';
$f_start = $_GET['start'] ?? date('Y-m-01');
$f_end = $_GET['end'] ?? date('Y-m-d');
$f_station = $_GET['station'] ?? ($is_manager ? $my_station_id : '');
$f_status = $_GET['status'] ?? '';

$views = [
  'service_breakdown' => 'Service Type Breakdown',
  'staff_performance' => 'Staff Performance on Jobs',
  'completion_time'   => 'Completion Time Reports'
];
$label = $views[$view] ?? 'Job Order Analytics';

// Build base query with filters
$base_where = "(j.created_at BETWEEN ? AND ?)";
$base_params = [$f_start.' 00:00:00', $f_end.' 23:59:59'];
if($f_station) { $base_where .= " AND j.station_id = ?"; $base_params[] = $f_station; }
if($f_status) { $base_where .= " AND j.status = ?"; $base_params[] = $f_status; }
if($is_manager && $my_station_id) { $base_where .= " AND j.station_id = ?"; $base_params[] = $my_station_id; }

// Fetch all job orders for current filters
$sql = "SELECT j.*, s.name as station_name, u.username as mechanic, sc.name as service_category
        FROM job_orders j 
        LEFT JOIN stations s ON j.station_id = s.id 
        LEFT JOIN users u ON j.assigned_mechanic_id = u.id 
        LEFT JOIN service_categories sc ON j.service_category_id = sc.id
        WHERE $base_where
        ORDER BY j.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($base_params);
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch stations for filter dropdown
$stations = $pdo->query("SELECT id, name FROM stations ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);

// Calculate analytics based on view
$analytics = [];
if ($view === 'service_breakdown') {
  $svc_stats = [];
  foreach($jobs as $j) {
    $svc = $j['service_category'] ?: $j['service_description'] ?: 'General Service';
    if(!isset($svc_stats[$svc])) $svc_stats[$svc] = ['count' => 0, 'total_cost' => 0];
    $svc_stats[$svc]['count']++;
    $svc_stats[$svc]['total_cost'] += $j['total_cost'] ?: 0;
  }
  arsort($svc_stats);
  $analytics = $svc_stats;
} elseif ($view === 'staff_performance') {
  $staff_stats = [];
  foreach($jobs as $j) {
    $mechanic = $j['mechanic'] ?: 'Unassigned';
    if(!isset($staff_stats[$mechanic])) {
      $staff_stats[$mechanic] = [
        'user_id' => $j['assigned_mechanic_id'],
        'total_jobs' => 0,
        'completed' => 0,
        'verified' => 0,
        'pending' => 0,
        'avg_duration' => 0,
        'total_cost' => 0,
        'durations' => []
      ];
    }
    $staff_stats[$mechanic]['total_jobs']++;
    $staff_stats[$mechanic]['total_cost'] += $j['total_cost'] ?: 0;
    
    if($j['status'] === 'Completed') $staff_stats[$mechanic]['completed']++;
    if($j['status'] === 'Verified') $staff_stats[$mechanic]['verified']++;
    if($j['status'] === 'Pending') $staff_stats[$mechanic]['pending']++;
    
    if($j['actual_duration']) {
      $staff_stats[$mechanic]['durations'][] = $j['actual_duration'];
    }
  }
  
  // Calculate average durations
  foreach($staff_stats as &$stats) {
    if(!empty($stats['durations'])) {
      $stats['avg_duration'] = round(array_sum($stats['durations']) / count($stats['durations']));
    }
    unset($stats['durations']);
  }
  
  uasort($staff_stats, function($a, $b) {
    return $b['total_jobs'] - $a['total_jobs'];
  });
  $analytics = $staff_stats;
} elseif ($view === 'completion_time') {
  $time_stats = [];
  foreach($jobs as $j) {
    if($j['started_at'] && $j['completed_at']) {
      $start = new DateTime($j['started_at']);
      $end = new DateTime($j['completed_at']);
      $duration_mins = round(($end->getTimestamp() - $start->getTimestamp()) / 60);
      
      // Group by date
      $date = $j['created_at'] ? date('Y-m-d', strtotime($j['created_at'])) : 'Unknown';
      if(!isset($time_stats[$date])) {
        $time_stats[$date] = ['jobs' => 0, 'total_time' => 0, 'avg_time' => 0];
      }
      $time_stats[$date]['jobs']++;
      $time_stats[$date]['total_time'] += $duration_mins;
    }
  }
  
  // Calculate averages
  foreach($time_stats as &$stats) {
    $stats['avg_time'] = round($stats['total_time'] / $stats['jobs']);
  }
  
  ksort($time_stats);
  $analytics = $time_stats;
}

// Summary metrics
$metrics = [
  'total_jobs' => count($jobs),
  'completed' => count(array_filter($jobs, fn($j) => $j['status'] === 'Completed')),
  'verified' => count(array_filter($jobs, fn($j) => $j['status'] === 'Verified')),
  'pending' => count(array_filter($jobs, fn($j) => $j['status'] === 'Pending')),
  'total_revenue' => array_sum(array_column($jobs, 'total_cost'))
];

include __DIR__ . '/../partials/header.php';
?>

<style>
  .status-badge { padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 0.85em; }
  .st-Pending { background: #fff3cd; color: #856404; }
  .st-Completed { background: #d4edda; color: #155724; }
  .st-Verified { background: #d1ecf1; color: #0c5460; }
  .st-Cancelled { background: #f8d7da; color: #721c24; }
  .metric { padding: 20px; text-align: center; border-radius: 6px; background: #f8f9fa; }
  .metric-label { font-size: 0.9em; color: #666; text-transform: uppercase; margin-bottom: 8px; }
  .metric-value { font-size: 2em; font-weight: bold; color: #333; }
  .metric-value.red { color: #dc3545; }
  .metric-value.green { color: #28a745; }
  .metric-value.blue { color: #007bff; }
  .chart-container { position: relative; height: 300px; margin: 20px 0; }
</style>

<div class="page-head">
  <div>
    <h1 class="h1">Job Order Analytics</h1>
    <div class="sub">Performance metrics, service analytics, and staff insights across your stations.</div>
  </div>
  <div style="display: flex; gap: 10px;">
    <a href="joborder_stats.php" class="btn ghost"><i class="fas fa-arrow-right"></i> Full Report</a>
  </div>
</div>

<!-- FILTER SECTION -->
<section class="card" style="padding: 16px; margin-bottom: 20px; background: #f8f9fa;">
  <form method="get" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
    <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
    
    <div>
      <label class="lbl" style="font-size: 0.85em;">Date Range</label>
      <div style="display: flex; gap: 5px; align-items: center;">
        <input type="date" name="start" value="<?php echo $f_start; ?>" class="inp" style="padding: 5px;">
        <span>to</span>
        <input type="date" name="end" value="<?php echo $f_end; ?>" class="inp" style="padding: 5px;">
      </div>
    </div>
    
    <?php if (!$is_manager): ?>
    <div>
      <label class="lbl" style="font-size: 0.85em;">Station</label>
      <select name="station" class="inp" style="padding: 5px;">
        <option value="">All Stations</option>
        <?php foreach($stations as $id => $name): ?>
          <option value="<?php echo $id; ?>" <?php echo $f_station == $id ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($name); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    
    <div>
      <label class="lbl" style="font-size: 0.85em;">Status</label>
      <select name="status" class="inp" style="padding: 5px;">
        <option value="">All Statuses</option>
        <option value="Pending" <?php echo $f_status === 'Pending' ? 'selected' : ''; ?>>Pending</option>
        <option value="In Progress" <?php echo $f_status === 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
        <option value="Completed" <?php echo $f_status === 'Completed' ? 'selected' : ''; ?>>Completed</option>
        <option value="Verified" <?php echo $f_status === 'Verified' ? 'selected' : ''; ?>>Verified</option>
      </select>
    </div>
    
    <button type="submit" class="btn primary" style="padding: 6px 15px;">Apply Filters</button>
    <a href="manager_job_analytics.php?view=<?php echo htmlspecialchars($view); ?>" class="btn ghost">Reset</a>
  </form>
</section>

<!-- METRICS SUMMARY -->
<section class="cards four">
  <div class="metric">
    <div class="metric-label">Total Jobs</div>
    <div class="metric-value blue"><?php echo $metrics['total_jobs']; ?></div>
    <div style="font-size: 0.85em; color: #666; margin-top: 8px;">
      <?php echo $metrics['completed']; ?> Completed | <?php echo $metrics['verified']; ?> Verified
    </div>
  </div>
  <div class="metric">
    <div class="metric-label">Total Revenue</div>
    <div class="metric-value">₱<?php echo number_format($metrics['total_revenue'], 2); ?></div>
    <div style="font-size: 0.85em; color: #666; margin-top: 8px;">From <?php echo count($jobs); ?> job orders</div>
  </div>
  <div class="metric">
    <div class="metric-label">Completion Rate</div>
    <div class="metric-value green">
      <?php echo $metrics['total_jobs'] > 0 ? round(($metrics['completed'] + $metrics['verified']) / $metrics['total_jobs'] * 100) : 0; ?>%
    </div>
    <div style="font-size: 0.85em; color: #666; margin-top: 8px;"><?php echo $metrics['completed'] + $metrics['verified']; ?> of <?php echo $metrics['total_jobs']; ?> done</div>
  </div>
  <div class="metric">
    <div class="metric-label">Pending Approval</div>
    <div class="metric-value <?php echo $metrics['pending'] > 0 ? 'red' : 'green'; ?>">
      <?php echo $metrics['pending']; ?>
    </div>
    <div style="font-size: 0.85em; color: #666; margin-top: 8px;">Awaiting verification</div>
  </div>
</section>

<!-- TAB NAVIGATION -->
<section class="card" style="margin-top: 20px;">
  <div style="display: flex; gap: 0; border-bottom: 2px solid #e0e0e0;">
    <a href="manager_job_analytics.php?view=service_breakdown&start=<?php echo $f_start; ?>&end=<?php echo $f_end; ?><?php echo $f_station ? '&station='.$f_station : ''; ?><?php echo $f_status ? '&status='.$f_status : ''; ?>" 
       class="btn ghost" style="border-bottom: 3px solid <?php echo $view === 'service_breakdown' ? '#007bff' : 'transparent'; ?>; border-radius: 0; flex: 1; text-align: left; padding: 15px;">
      <i class="fas fa-chart-pie"></i> Service Breakdown
    </a>
    <a href="manager_job_analytics.php?view=staff_performance&start=<?php echo $f_start; ?>&end=<?php echo $f_end; ?><?php echo $f_station ? '&station='.$f_station : ''; ?><?php echo $f_status ? '&status='.$f_status : ''; ?>" 
       class="btn ghost" style="border-bottom: 3px solid <?php echo $view === 'staff_performance' ? '#007bff' : 'transparent'; ?>; border-radius: 0; flex: 1; text-align: left; padding: 15px;">
      <i class="fas fa-users"></i> Staff Performance
    </a>
    <a href="manager_job_analytics.php?view=completion_time&start=<?php echo $f_start; ?>&end=<?php echo $f_end; ?><?php echo $f_station ? '&station='.$f_station : ''; ?><?php echo $f_status ? '&status='.$f_status : ''; ?>" 
       class="btn ghost" style="border-bottom: 3px solid <?php echo $view === 'completion_time' ? '#007bff' : 'transparent'; ?>; border-radius: 0; flex: 1; text-align: left; padding: 15px;">
      <i class="fas fa-clock"></i> Completion Time
    </a>
  </div>

  <!-- SERVICE BREAKDOWN VIEW -->
  <?php if ($view === 'service_breakdown'): ?>
  <div style="padding: 20px;">
    <h2 class="h2" style="margin-bottom: 20px;">Services by Type</h2>
    
    <?php if (!empty($analytics)): ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Service Type</th>
            <th style="text-align: right;">Jobs</th>
            <th style="text-align: right;">Total Revenue</th>
            <th style="text-align: right;">Avg per Job</th>
            <th style="text-align: right;">% of Total</th>
          </tr>
        </thead>
        <tbody>
          <?php 
          $total_revenue = array_sum(array_column($analytics, 'total_cost'));
          foreach($analytics as $service => $data): 
            $pct = $total_revenue > 0 ? round(($data['total_cost'] / $total_revenue) * 100) : 0;
            $avg = $data['count'] > 0 ? $data['total_cost'] / $data['count'] : 0;
          ?>
          <tr>
            <td><strong><?php echo htmlspecialchars($service); ?></strong></td>
            <td style="text-align: right;"><?php echo $data['count']; ?></td>
            <td style="text-align: right;">₱<?php echo number_format($data['total_cost'], 2); ?></td>
            <td style="text-align: right;">₱<?php echo number_format($avg, 2); ?></td>
            <td style="text-align: right;">
              <span style="background: #e3f2fd; padding: 4px 8px; border-radius: 4px; font-weight: bold;">
                <?php echo $pct; ?>%
              </span>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div style="padding: 40px; text-align: center; color: #999;">
      <p><i class="fas fa-inbox" style="font-size: 2em; margin-bottom: 10px; display: block;"></i></p>
      <p>No job orders found for the selected filters.</p>
    </div>
    <?php endif; ?>
  </div>

  <!-- STAFF PERFORMANCE VIEW -->
  <?php elseif ($view === 'staff_performance'): ?>
  <div style="padding: 20px;">
    <h2 class="h2" style="margin-bottom: 20px;">Staff Performance Metrics</h2>
    
    <?php if (!empty($analytics)): ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Mechanic</th>
            <th style="text-align: right;">Total Jobs</th>
            <th style="text-align: right;">Completed</th>
            <th style="text-align: right;">Verified</th>
            <th style="text-align: right;">Pending</th>
            <th style="text-align: right;">Avg Duration</th>
            <th style="text-align: right;">Total Revenue</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($analytics as $mechanic => $stats): ?>
          <tr>
            <td><strong><?php echo htmlspecialchars($mechanic); ?></strong></td>
            <td style="text-align: right;"><?php echo $stats['total_jobs']; ?></td>
            <td style="text-align: right;">
              <span style="background: #d4edda; padding: 4px 8px; border-radius: 4px;">
                <?php echo $stats['completed']; ?>
              </span>
            </td>
            <td style="text-align: right;">
              <span style="background: #d1ecf1; padding: 4px 8px; border-radius: 4px;">
                <?php echo $stats['verified']; ?>
              </span>
            </td>
            <td style="text-align: right;">
              <span style="background: #fff3cd; padding: 4px 8px; border-radius: 4px;">
                <?php echo $stats['pending']; ?>
              </span>
            </td>
            <td style="text-align: right;"><?php echo $stats['avg_duration']; ?> mins</td>
            <td style="text-align: right; font-weight: bold;">₱<?php echo number_format($stats['total_cost'], 2); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div style="padding: 40px; text-align: center; color: #999;">
      <p><i class="fas fa-inbox" style="font-size: 2em; margin-bottom: 10px; display: block;"></i></p>
      <p>No job assignments found for the selected filters.</p>
    </div>
    <?php endif; ?>
  </div>

  <!-- COMPLETION TIME VIEW -->
  <?php elseif ($view === 'completion_time'): ?>
  <div style="padding: 20px;">
    <h2 class="h2" style="margin-bottom: 20px;">Completion Time Trends</h2>
    
    <?php if (!empty($analytics)): ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Date</th>
            <th style="text-align: right;">Jobs Completed</th>
            <th style="text-align: right;">Total Hours</th>
            <th style="text-align: right;">Avg Duration</th>
            <th style="text-align: right;">Trend</th>
          </tr>
        </thead>
        <tbody>
          <?php 
          $prev_avg = null;
          foreach($analytics as $date => $stats): 
            $hours = round($stats['total_time'] / 60, 2);
            $trend = '';
            if($prev_avg !== null) {
              if($stats['avg_time'] < $prev_avg) {
                $trend = '<i class="fas fa-arrow-down" style="color: green;"></i> Improving';
              } elseif($stats['avg_time'] > $prev_avg) {
                $trend = '<i class="fas fa-arrow-up" style="color: red;"></i> Slower';
              } else {
                $trend = '<i class="fas fa-minus" style="color: #999;"></i> Stable';
              }
            }
            $prev_avg = $stats['avg_time'];
          ?>
          <tr>
            <td><strong><?php echo date('M d, Y', strtotime($date)); ?></strong></td>
            <td style="text-align: right;"><?php echo $stats['jobs']; ?></td>
            <td style="text-align: right;"><?php echo $hours; ?> hrs</td>
            <td style="text-align: right;">
              <span style="background: #e3f2fd; padding: 4px 8px; border-radius: 4px;">
                <?php echo $stats['avg_time']; ?> mins
              </span>
            </td>
            <td style="text-align: right;"><?php echo $trend; ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div style="padding: 40px; text-align: center; color: #999;">
      <p><i class="fas fa-inbox" style="font-size: 2em; margin-bottom: 10px; display: block;"></i></p>
      <p>No completed jobs found for the selected filters.</p>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</section>

<?php include __DIR__ . '/../partials/footer.php'; ?>
