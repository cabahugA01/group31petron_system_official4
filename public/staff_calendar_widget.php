<?php
/**
 * Staff Calendar Dashboard Widget
 * Include this in manager_dashboard.php and staff_dashboard.php
 * Usage: include __DIR__ . '/staff_calendar_widget.php';
 */
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($pdo)) {
    require_once __DIR__ . '/../public/db_connect.php';
}
$_wuid = $_SESSION['user']['id'] ?? 0;
$_wsid = $_SESSION['station_id'] ?? (function_exists('user_station_id') ? user_station_id() : 0);
$_today = date('Y-m-d');
$_3days = date('Y-m-d', strtotime('+3 days'));

$_w_today    = [];
$_w_upcoming = [];
$_w_counts   = ['pending'=>0,'approved'=>0,'completed'=>0];

try {
    // Today's events from calendar
    $s = $pdo->prepare("
        SELECT sce.*, et.type_name, et.type_key, et.icon_class,
               su.name AS staff_name, mu.name AS manager_name,
               COALESCE(scc.color_code,'#2563eb') AS staff_color
        FROM staff_calendar_events sce
        JOIN staff_event_types et ON sce.event_type_id = et.id
        JOIN users su ON sce.staff_encoder_id = su.id
        LEFT JOIN users mu ON sce.manager_assigned_id = mu.id
        LEFT JOIN staff_color_config scc ON sce.staff_encoder_id = scc.user_id AND scc.is_active=1
        WHERE sce.station_id=? AND sce.event_date=?
        ORDER BY sce.start_time
    ");
    $s->execute([$_wsid, $_today]);
    $_w_today = $s->fetchAll(PDO::FETCH_ASSOC);

    // Upcoming (next 3 days)
    $s = $pdo->prepare("
        SELECT sce.*, et.type_name, et.type_key, et.icon_class,
               su.name AS staff_name, mu.name AS manager_name,
               COALESCE(scc.color_code,'#2563eb') AS staff_color
        FROM staff_calendar_events sce
        JOIN staff_event_types et ON sce.event_type_id = et.id
        JOIN users su ON sce.staff_encoder_id = su.id
        LEFT JOIN users mu ON sce.manager_assigned_id = mu.id
        LEFT JOIN staff_color_config scc ON sce.staff_encoder_id = scc.user_id AND scc.is_active=1
        WHERE sce.station_id=? AND sce.event_date > ? AND sce.event_date <= ?
        ORDER BY sce.event_date, sce.start_time
        LIMIT 5
    ");
    $s->execute([$_wsid, $_today, $_3days]);
    $_w_upcoming = $s->fetchAll(PDO::FETCH_ASSOC);

    // Status counts (this week)
    $s = $pdo->prepare("
        SELECT status, COUNT(*) as cnt
        FROM staff_calendar_events
        WHERE station_id=? AND event_date BETWEEN DATE_SUB(CURDATE(),INTERVAL 7 DAY) AND CURDATE()
        GROUP BY status
    ");
    $s->execute([$_wsid]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $k = strtolower($r['status']);
        if (isset($_w_counts[$k])) $_w_counts[$k] = (int)$r['cnt'];
    }

    // Also count today's auto-synced job orders
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND DATE(created_at)=?");
        $s->execute([$_wsid, $_today]);
        $jo_count = (int)$s->fetchColumn();
    } catch (Exception $e) { $jo_count = 0; }

    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM deliveries WHERE station_id=? AND DATE(created_at)=?");
        $s->execute([$_wsid, $_today]);
        $del_count = (int)$s->fetchColumn();
    } catch (Exception $e) { $del_count = 0; }

} catch (Exception $e) {
    $jo_count = 0; $del_count = 0;
}

function _w_type_color(string $k): string {
    return match($k) { 'job_order'=>'#2563eb','merchandise_delivery'=>'#16a34a','fuel_calibration'=>'#d97706','staff_shift'=>'#7c3aed',default=>'#6b7280' };
}
?>
<style>
.wcal-card { background:#fff; border-radius:14px; border:1px solid #EAEAEA; padding:18px; box-shadow:0 2px 8px rgba(0,0,0,.05); margin-bottom:20px; }
.wcal-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; padding-bottom:12px; border-bottom:2px solid #f0f0f0; }
.wcal-title { font-size:15px; font-weight:800; color:#00264D; display:flex; align-items:center; gap:8px; margin:0; }
.wcal-view-all { font-size:12px; font-weight:700; color:#00264D; text-decoration:none; background:#f0f4ff; padding:4px 12px; border-radius:20px; }
.wcal-view-all:hover { background:#dbeafe; }
.wcal-section-label { font-size:11px; font-weight:800; color:#667085; text-transform:uppercase; letter-spacing:.5px; margin:12px 0 6px; }
.wcal-item { display:flex; gap:10px; padding:7px 0; border-bottom:1px solid #f8f8f8; }
.wcal-item:last-child { border-bottom:none; }
.wcal-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; margin-top:4px; }
.wcal-info { flex:1; min-width:0; }
.wcal-type { font-size:11px; font-weight:700; }
.wcal-staff { font-size:12px; font-weight:600; color:#101828; }
.wcal-mgr { font-size:11px; color:#667085; }
.wcal-desc { font-size:11px; color:#667085; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.wcal-badge { display:inline-block; padding:1px 7px; border-radius:20px; font-size:10px; font-weight:700; text-transform:uppercase; }
.wcal-badge.pending   { background:#FFF3CD; color:#856404; }
.wcal-badge.approved  { background:#D1FAE5; color:#065F46; }
.wcal-badge.completed { background:#DBEAFE; color:#1E40AF; }
.wcal-badge.cancelled { background:#FEE2E2; color:#991B1B; }
.wcal-stats { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; margin-top:10px; }
.wcal-stat { text-align:center; padding:8px; border-radius:10px; }
.wcal-stat-num { font-size:20px; font-weight:800; }
.wcal-stat-lbl { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; margin-top:2px; }
.wcal-synced-row { display:flex; gap:12px; margin-top:10px; }
.wcal-synced-chip { flex:1; display:flex; align-items:center; gap:6px; background:#f0f9ff; border:1px solid #bae6fd; border-radius:10px; padding:8px 10px; }
.wcal-synced-chip i { color:#0284c7; }
.wcal-synced-chip .num { font-size:16px; font-weight:800; color:#0284c7; }
.wcal-synced-chip .lbl { font-size:11px; color:#0369a1; font-weight:600; }
</style>

<div class="wcal-card">
  <div class="wcal-head">
    <p class="wcal-title"><i class="fas fa-calendar-alt"></i> Staff Calendar</p>
    <a href="staff_calendar.php" class="wcal-view-all"><i class="fas fa-external-link-alt"></i> Full View</a>
  </div>

  <!-- Auto-synced today counts -->
  <div class="wcal-synced-row">
    <div class="wcal-synced-chip">
      <i class="fas fa-wrench"></i>
      <div><div class="num"><?php echo $jo_count; ?></div><div class="lbl">Job Orders Today</div></div>
    </div>
    <div class="wcal-synced-chip">
      <i class="fas fa-box"></i>
      <div><div class="num"><?php echo $del_count; ?></div><div class="lbl">Deliveries Today</div></div>
    </div>
  </div>

  <!-- Today's Events -->
  <div class="wcal-section-label"><i class="fas fa-sun"></i> Today's Events</div>
  <?php if(empty($_w_today)): ?>
    <p style="font-size:12px;color:#9ca3af;padding:8px 0">No scheduled events today.</p>
  <?php else: ?>
    <?php foreach(array_slice($_w_today,0,4) as $ev):
      $tc = _w_type_color($ev['type_key']);
      $st = strtolower($ev['status']??'pending');
    ?>
    <div class="wcal-item">
      <div class="wcal-dot" style="background:<?php echo $tc; ?>"></div>
      <div class="wcal-info">
        <div class="wcal-type" style="color:<?php echo $tc; ?>"><?php echo htmlspecialchars($ev['type_name']); ?></div>
        <div class="wcal-staff"><i class="fas fa-user" style="font-size:9px;color:#2563eb"></i> <?php echo htmlspecialchars($ev['staff_name']); ?></div>
        <div class="wcal-mgr"><i class="fas fa-user-tie" style="font-size:9px;color:#dc2626"></i> <?php echo htmlspecialchars($ev['manager_name']??'--'); ?></div>
        <div class="wcal-desc"><?php echo htmlspecialchars($ev['work_description']); ?></div>
        <span class="wcal-badge <?php echo $st; ?>"><?php echo ucfirst($st); ?></span>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if(count($_w_today)>4): ?>
      <a href="staff_calendar.php" style="font-size:11px;color:#00264D;font-weight:700">+<?php echo count($_w_today)-4; ?> more &rarr;</a>
    <?php endif; ?>
  <?php endif; ?>

  <!-- Upcoming Tasks -->
  <?php if(!empty($_w_upcoming)): ?>
  <div class="wcal-section-label"><i class="fas fa-clock"></i> Upcoming (next 3 days)</div>
  <?php foreach($_w_upcoming as $ev):
    $tc = _w_type_color($ev['type_key']);
    $st = strtolower($ev['status']??'pending');
    $ev_date = date('D M j', strtotime($ev['event_date']));
  ?>
  <div class="wcal-item">
    <div class="wcal-dot" style="background:<?php echo $tc; ?>"></div>
    <div class="wcal-info">
      <div class="wcal-type" style="color:#667085;font-size:10px"><?php echo $ev_date; ?></div>
      <div class="wcal-type" style="color:<?php echo $tc; ?>"><?php echo htmlspecialchars($ev['type_name']); ?></div>
      <div class="wcal-staff"><?php echo htmlspecialchars($ev['staff_name']); ?> &rarr; <?php echo htmlspecialchars($ev['manager_name']??'--'); ?></div>
      <span class="wcal-badge <?php echo $st; ?>"><?php echo ucfirst($st); ?></span>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <!-- Quick Status Overview -->
  <div class="wcal-section-label"><i class="fas fa-chart-bar"></i> Quick Status (7 days)</div>
  <div class="wcal-stats">
    <div class="wcal-stat" style="background:#FFF3CD">
      <div class="wcal-stat-num" style="color:#856404"><?php echo $_w_counts['pending']; ?></div>
      <div class="wcal-stat-lbl" style="color:#856404">Pending</div>
    </div>
    <div class="wcal-stat" style="background:#D1FAE5">
      <div class="wcal-stat-num" style="color:#065F46"><?php echo $_w_counts['approved']; ?></div>
      <div class="wcal-stat-lbl" style="color:#065F46">Approved</div>
    </div>
    <div class="wcal-stat" style="background:#DBEAFE">
      <div class="wcal-stat-num" style="color:#1E40AF"><?php echo $_w_counts['completed']; ?></div>
      <div class="wcal-stat-lbl" style="color:#1E40AF">Completed</div>
    </div>
  </div>
</div>
