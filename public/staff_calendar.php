<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'calendar';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$rk         = strtolower(trim($me['role'] ?? ''));
if (!in_array($rk, ['staff','manager','admin'])) { header('Location: dashboard.php'); exit; }
$station_id = user_station_id();
if (!$station_id) { die('Error: Not assigned to a station.'); }
$user_id    = $me['id'];

// ── Module gate ───────────────────────────────────────────────
$_role = role_key($me['role'] ?? '');
if (!in_array($_role, ['superadmin','developer']) && !is_module_enabled('calendar')) {
    render_module_disabled_page('Calendar');
}

// Ensure calendar tables exist
try {
    $schema_sql = @file_get_contents(__DIR__ . '/../database/staff_calendar_schema.sql');
    if ($schema_sql) {
        $schema_sql = preg_replace('/DELIMITER\s*\/\/.*?DELIMITER\s*;/s', '', $schema_sql);
        foreach (array_filter(array_map('trim', explode(';', $schema_sql))) as $sql) {
            try { $pdo->exec($sql); } catch (Exception $e) {}
        }
    }
} catch (Exception $e) {}

$msg = '';
if (isset($_SESSION['success'])) { $msg = $_SESSION['success']; unset($_SESSION['success']); }
if (isset($_SESSION['error']))   { $msg = $_SESSION['error'];   unset($_SESSION['error']); }

// Week navigation
$today       = new DateTime();
$week_offset = (int)($_GET['week_offset'] ?? 0);
$dow         = (int)$today->format('N');
$monday      = clone $today;
$monday->modify('-' . ($dow - 1) . ' days');
$monday->modify($week_offset . ' weeks');
$sunday      = clone $monday;
$sunday->modify('+6 days');
$week_label  = $monday->format('F j') . ' - ' . $sunday->format('j, Y');
$prev_offset = $week_offset - 1;
$next_offset = $week_offset + 1;
$today_str   = $today->format('Y-m-d');

$week_dates = [];
for ($i = 0; $i < 7; $i++) {
    $d = clone $monday;
    $d->modify("+$i days");
    $week_dates[] = $d->format('Y-m-d');
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $act = $_POST['action'];
    if ($act === 'create_event' && in_array($rk, ['manager','admin'])) {
        try {
            $pdo->prepare("INSERT INTO staff_calendar_events (station_id,event_type_id,staff_encoder_id,manager_assigned_id,event_date,start_time,end_time,work_description,status,notes) VALUES (?,?,?,?,?,?,?,?,'pending',?)")
                ->execute([$station_id,(int)$_POST['event_type_id'],(int)$_POST['staff_encoder_id'],!empty($_POST['manager_assigned_id'])?(int)$_POST['manager_assigned_id']:null,$_POST['event_date'],$_POST['start_time'],$_POST['end_time'],trim($_POST['work_description']),trim($_POST['notes']??'')]);
            if (function_exists('log_activity')) log_activity($pdo,$user_id,'Calendar Event Created','New event on '.$_POST['event_date']);
            $_SESSION['success'] = 'Event created successfully.';
        } catch (Exception $e) { $_SESSION['error'] = 'Error: '.$e->getMessage(); }
        header('Location: staff_calendar.php?week_offset='.$week_offset); exit;
    }
    if ($act === 'update_status') {
        $allowed = ['pending','approved','completed','cancelled'];
        $new_status = $_POST['status'] ?? '';
        $event_id   = (int)($_POST['event_id'] ?? 0);
        if ($event_id && in_array($new_status, $allowed)) {
            try {
                $pdo->prepare("UPDATE staff_calendar_events SET status=?,updated_at=NOW() WHERE id=? AND station_id=?")
                    ->execute([$new_status,$event_id,$station_id]);
                if (function_exists('log_activity')) log_activity($pdo,$user_id,'Calendar Status Updated',"Event #$event_id -> $new_status");
                $_SESSION['success'] = 'Status updated.';
            } catch (Exception $e) { $_SESSION['error'] = 'Error: '.$e->getMessage(); }
        }
        header('Location: staff_calendar.php?week_offset='.$week_offset); exit;
    }
    if ($act === 'delete_event' && in_array($rk, ['manager','admin'])) {
        $event_id = (int)($_POST['event_id'] ?? 0);
        if ($event_id) {
            try {
                $pdo->prepare("DELETE FROM staff_calendar_events WHERE id=? AND station_id=?")->execute([$event_id,$station_id]);
                $_SESSION['success'] = 'Event deleted.';
            } catch (Exception $e) { $_SESSION['error'] = 'Error: '.$e->getMessage(); }
        }
        header('Location: staff_calendar.php?week_offset='.$week_offset); exit;
    }
}

// Load data
$staff_list   = [];
$manager_list = [];
$event_types  = [];
$week_events  = [];

try {
    $s = $pdo->prepare("SELECT id,name FROM users WHERE station_id=? AND role IN ('staff','cashier','pump_attendant') AND status='active' ORDER BY name");
    $s->execute([$station_id]);
    $staff_list = $s->fetchAll(PDO::FETCH_ASSOC);

    $s = $pdo->prepare("SELECT id,name FROM users WHERE station_id=? AND role IN ('manager','admin') AND status='active' ORDER BY name");
    $s->execute([$station_id]);
    $manager_list = $s->fetchAll(PDO::FETCH_ASSOC);

    $s = $pdo->query("SELECT id,type_key,type_name,icon_class FROM staff_event_types WHERE is_active=1 ORDER BY sort_order");
    $event_types = $s ? $s->fetchAll(PDO::FETCH_ASSOC) : [];

    $stmt = $pdo->prepare("SELECT sce.*,et.type_name,et.type_key,et.icon_class,su.name AS staff_encoder_name,mu.name AS manager_assigned_name,COALESCE(scc.color_code,'#2563eb') AS staff_color,COALESCE(mcc.color_code,'#dc2626') AS manager_color FROM staff_calendar_events sce JOIN staff_event_types et ON sce.event_type_id=et.id JOIN users su ON sce.staff_encoder_id=su.id LEFT JOIN users mu ON sce.manager_assigned_id=mu.id LEFT JOIN staff_color_config scc ON sce.staff_encoder_id=scc.user_id AND scc.is_active=1 LEFT JOIN manager_color_config mcc ON sce.manager_assigned_id=mcc.user_id AND mcc.is_active=1 WHERE sce.station_id=? AND sce.event_date BETWEEN ? AND ? ORDER BY sce.event_date,sce.start_time");
    $stmt->execute([$station_id,$week_dates[0],$week_dates[6]]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $week_events[$row['staff_encoder_id']][$row['event_date']][] = $row;
    }

    // Auto-sync Job Orders
    try {
        $jo = $pdo->prepare("SELECT jo.id,jo.job_order_id AS jo_ref,jo.created_by,jo.service_type,jo.validation_status AS status,jo.validated_by,u.name AS staff_name,mu.name AS manager_name,DATE(jo.created_at) AS event_date FROM job_orders jo JOIN users u ON jo.created_by=u.id LEFT JOIN users mu ON jo.validated_by=mu.id WHERE jo.station_id=? AND DATE(jo.created_at) BETWEEN ? AND ?");
        $jo->execute([$station_id,$week_dates[0],$week_dates[6]]);
        foreach ($jo->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $week_events[$r['created_by']][$r['event_date']][] = ['id'=>'jo_'.$r['id'],'type_key'=>'job_order','type_name'=>'Job Order','icon_class'=>'fas fa-wrench','staff_encoder_id'=>$r['created_by'],'staff_encoder_name'=>$r['staff_name'],'manager_assigned_id'=>$r['validated_by'],'manager_assigned_name'=>$r['manager_name']??'--','event_date'=>$r['event_date'],'start_time'=>'00:00','end_time'=>'00:00','work_description'=>$r['service_type']??'Encode Job Order','status'=>strtolower($r['status']??'pending'),'staff_color'=>'#2563eb','manager_color'=>'#dc2626','auto_synced'=>true,'ref'=>$r['jo_ref']];
        }
    } catch (Exception $e) {}

    // Auto-sync Deliveries
    try {
        $dl = $pdo->prepare("SELECT d.id,d.created_by,d.validated_by,DATE(d.created_at) AS event_date,u.name AS staff_name,mu.name AS manager_name,d.status FROM deliveries d JOIN users u ON d.created_by=u.id LEFT JOIN users mu ON d.validated_by=mu.id WHERE d.station_id=? AND DATE(d.created_at) BETWEEN ? AND ?");
        $dl->execute([$station_id,$week_dates[0],$week_dates[6]]);
        foreach ($dl->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $week_events[$r['created_by']][$r['event_date']][] = ['id'=>'del_'.$r['id'],'type_key'=>'merchandise_delivery','type_name'=>'Merchandise Delivery','icon_class'=>'fas fa-box','staff_encoder_id'=>$r['created_by'],'staff_encoder_name'=>$r['staff_name'],'manager_assigned_id'=>$r['validated_by'],'manager_assigned_name'=>$r['manager_name']??'--','event_date'=>$r['event_date'],'start_time'=>'00:00','end_time'=>'00:00','work_description'=>'Encode Merchandise Delivery','status'=>strtolower($r['status']??'pending'),'staff_color'=>'#16a34a','manager_color'=>'#dc2626','auto_synced'=>true];
        }
    } catch (Exception $e) {}

    // Auto-sync Fuel Calibration
    try {
        $fc = $pdo->prepare("SELECT fc.id,fc.created_by,fc.validated_by,DATE(fc.created_at) AS event_date,u.name AS staff_name,mu.name AS manager_name,fc.status FROM fuel_calibration_log fc JOIN users u ON fc.created_by=u.id LEFT JOIN users mu ON fc.validated_by=mu.id WHERE fc.station_id=? AND DATE(fc.created_at) BETWEEN ? AND ?");
        $fc->execute([$station_id,$week_dates[0],$week_dates[6]]);
        foreach ($fc->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $week_events[$r['created_by']][$r['event_date']][] = ['id'=>'cal_'.$r['id'],'type_key'=>'fuel_calibration','type_name'=>'Fuel Calibration','icon_class'=>'fas fa-gas-pump','staff_encoder_id'=>$r['created_by'],'staff_encoder_name'=>$r['staff_name'],'manager_assigned_id'=>$r['validated_by'],'manager_assigned_name'=>$r['manager_name']??'--','event_date'=>$r['event_date'],'start_time'=>'00:00','end_time'=>'00:00','work_description'=>'Encode Fuel Calibration','status'=>strtolower($r['status']??'pending'),'staff_color'=>'#d97706','manager_color'=>'#dc2626','auto_synced'=>true];
        }
    } catch (Exception $e) {}

} catch (Exception $e) {}

// Build row people
// Staff color assignment: 1st staff = dark blue, 2nd staff = red, rest cycle
$staff_colors = ['#1e3a8a', '#dc2626', '#16a34a', '#7c3aed', '#d97706'];
$row_people = [];
foreach ($staff_list as $idx => $s) {
    $color = $staff_colors[$idx] ?? '#6b7280';
    $row_people[$s['id']] = ['id'=>$s['id'],'name'=>$s['name'],'color'=>$color,'role_label'=>'Staff'];
}
// Managers excluded from grid rows — they appear only in event details as "Manager Assigned"

// Status counts
$status_counts = ['pending'=>0,'approved'=>0,'completed'=>0,'cancelled'=>0];
foreach ($week_events as $uid => $dates) {
    foreach ($dates as $date => $evs) {
        foreach ($evs as $ev) {
            $st = strtolower($ev['status']??'pending');
            if (isset($status_counts[$st])) $status_counts[$st]++;
        }
    }
}

// Today events
$today_events = [];
foreach ($week_events as $uid => $dates) {
    if (isset($dates[$today_str])) {
        foreach ($dates[$today_str] as $ev) $today_events[] = $ev;
    }
}

// Upcoming (next 3 days)
$upcoming_events = [];
for ($i = 1; $i <= 3; $i++) {
    $d = (new DateTime())->modify("+$i days")->format('Y-m-d');
    foreach ($week_events as $uid => $dates) {
        if (isset($dates[$d])) foreach ($dates[$d] as $ev) $upcoming_events[] = $ev;
    }
}

function cal_type_color(string $k): string {
    return match($k) { 'job_order'=>'#2563eb','merchandise_delivery'=>'#16a34a','fuel_calibration'=>'#d97706','staff_shift'=>'#7c3aed',default=>'#6b7280' };
}
function cal_status_badge(string $s): string {
    $map = ['approved'=>'badge-approved','completed'=>'badge-completed','cancelled'=>'badge-cancelled','pending'=>'badge-pending'];
    $cls = $map[$s] ?? 'badge-pending';
    return '<span class="cal-badge '.$cls.'">'.ucfirst($s).'</span>';
}

include __DIR__ . '/../partials/header.php';
?>

<style>
/* ===== STAFF CALENDAR ===== */
.sc-wrap { display:flex; gap:20px; padding:20px; max-width:100%; }
.sc-main { flex:1; min-width:0; }
.sc-sidebar { width:300px; flex-shrink:0; display:flex; flex-direction:column; gap:16px; }

/* Header bar */
.sc-header { background:#fff; border:1px solid #EAEAEA; border-radius:14px; padding:16px 20px; display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; box-shadow:0 1px 4px rgba(0,0,0,.04); }
.sc-header-left h2 { margin:0; font-size:18px; font-weight:800; color:#101828; }
.sc-header-left p  { margin:3px 0 0; font-size:12px; color:#667085; }
.sc-nav { display:flex; align-items:center; gap:8px; }
.sc-nav-btn { background:#f8fafc; border:1px solid #EAEAEA; color:#344054; padding:7px 14px; border-radius:8px; cursor:pointer; font-size:13px; transition:.2s; text-decoration:none; display:inline-flex; align-items:center; gap:5px; }
.sc-nav-btn:hover { background:#f0f4ff; border-color:#c7d7f5; color:#00264D; }
.sc-week-label { font-weight:700; font-size:14px; min-width:200px; text-align:center; color:#101828; }
.sc-today-btn { background:#00264D; border:none; color:#fff; padding:7px 16px; border-radius:8px; cursor:pointer; font-size:13px; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; }
.sc-today-btn:hover { background:#003d7a; color:#fff; }



/* Weekly grid */
.sc-grid-wrap { background:#e9eaec; border-radius:14px; border:1px solid #d8dadf; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,.06); }
.sc-grid { display:grid; grid-template-columns:180px repeat(7,1fr); }

/* Column headers */
.sc-col-head { background:#eef0f3; padding:10px 8px; text-align:center; border-bottom:2px solid #d8dadf; border-right:1px solid #d8dadf; }
.sc-col-head:last-child { border-right:none; }
.sc-col-head .day-name { font-size:11px; font-weight:700; color:#667085; text-transform:uppercase; letter-spacing:.5px; }
.sc-col-head .day-num  { font-size:18px; font-weight:800; color:#101828; line-height:1.2; }
.sc-col-head .day-num.today { background:#00264D; color:#fff; width:32px; height:32px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:15px; }
.sc-col-head-label { background:#eef0f3; padding:10px 12px; border-bottom:2px solid #d8dadf; border-right:1px solid #d8dadf; font-size:11px; font-weight:700; color:#667085; text-transform:uppercase; letter-spacing:.5px; display:flex; align-items:center; }

/* Person rows */
.sc-person-cell { padding:10px 12px; border-right:1px solid #d8dadf; border-bottom:1px solid #d8dadf; display:flex; align-items:center; gap:8px; background:#eef0f3; }
.sc-person-avatar { width:32px; height:32px; border-radius:10px; display:grid; place-items:center; color:#fff; font-weight:800; font-size:13px; flex-shrink:0; }
.sc-person-name { font-size:13px; font-weight:700; color:#101828; line-height:1.2; }
.sc-person-role { font-size:11px; color:#667085; }

/* Day cells */
.sc-day-cell { padding:6px; border-right:1px solid #d8dadf; border-bottom:1px solid #d8dadf; min-height:80px; vertical-align:top; background:#f5f6f8; }
.sc-day-cell:last-child { border-right:none; }
.sc-day-cell.today-col { background:#eef4ff; }
.sc-day-cell.off { background:#ededef; }

/* Event chips */
.sc-event { border-radius:7px; padding:5px 7px; margin-bottom:4px; cursor:pointer; transition:.15s; border-left:3px solid transparent; }
.sc-event:hover { filter:brightness(.95); transform:translateY(-1px); }
.sc-event-type { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; opacity:.85; }
.sc-event-desc { font-size:11px; font-weight:600; margin-top:1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:120px; }
.sc-event-time { font-size:10px; opacity:.75; margin-top:1px; }
.sc-event-mgr  { font-size:10px; opacity:.8; margin-top:2px; }
.sc-off-label  { font-size:11px; color:#9ca3af; text-align:center; padding-top:20px; }

/* Status badges */
.cal-badge { display:inline-block; padding:2px 8px; border-radius:20px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; }
.badge-pending   { background:#FFF3CD; color:#856404; }
.badge-approved  { background:#D1FAE5; color:#065F46; }
.badge-completed { background:#DBEAFE; color:#1E40AF; }
.badge-cancelled { background:#FEE2E2; color:#991B1B; }

/* Sidebar cards */
.sc-card { background:#f5f6f8; border-radius:14px; border:1px solid #e4e6ea; padding:16px; box-shadow:none; }
.sc-card-title { font-size:13px; font-weight:800; color:#101828; margin:0 0 12px; display:flex; align-items:center; gap:7px; }
.sc-card-title i { color:#00264D; }

/* Today events list */
.sc-today-item { display:flex; gap:10px; padding:8px 0; border-bottom:1px solid #f0f0f0; }
.sc-today-item:last-child { border-bottom:none; }
.sc-today-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; margin-top:4px; }
.sc-today-info { flex:1; min-width:0; }
.sc-today-type { font-size:11px; font-weight:700; color:#344054; }
.sc-today-staff { font-size:12px; font-weight:600; color:#101828; }
.sc-today-mgr   { font-size:11px; color:#667085; }
.sc-today-desc  { font-size:11px; color:#667085; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

/* Status overview */
.sc-status-row { display:flex; align-items:center; justify-content:space-between; padding:6px 0; border-bottom:1px solid #f0f0f0; }
.sc-status-row:last-child { border-bottom:none; }
.sc-status-label { font-size:12px; font-weight:600; color:#344054; display:flex; align-items:center; gap:6px; }
.sc-status-count { font-size:14px; font-weight:800; color:#101828; }

/* Upcoming */
.sc-upcoming-item { padding:8px 0; border-bottom:1px solid #f0f0f0; }
.sc-upcoming-item:last-child { border-bottom:none; }
.sc-upcoming-date { font-size:10px; font-weight:700; color:#667085; text-transform:uppercase; letter-spacing:.4px; }
.sc-upcoming-type { font-size:12px; font-weight:700; color:#101828; }
.sc-upcoming-staff { font-size:11px; color:#667085; }

/* Modal */
.sc-modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:1000; align-items:center; justify-content:center; }
.sc-modal-overlay.open { display:flex; }
.sc-modal { background:#fff; border-radius:16px; width:min(520px,94vw); max-height:88vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,.2); }
.sc-modal-head { background:linear-gradient(135deg,#00264D,#003d7a); color:#fff; padding:20px 24px; border-radius:16px 16px 0 0; display:flex; align-items:center; justify-content:space-between; }
.sc-modal-head h3 { margin:0; font-size:17px; font-weight:800; }
.sc-modal-close { background:none; border:none; color:#fff; font-size:22px; cursor:pointer; line-height:1; }
.sc-modal-body { padding:20px 24px; }
.sc-detail-row { display:flex; gap:12px; padding:8px 0; border-bottom:1px solid #f0f0f0; }
.sc-detail-row:last-child { border-bottom:none; }
.sc-detail-label { font-size:12px; font-weight:700; color:#667085; width:130px; flex-shrink:0; }
.sc-detail-val   { font-size:13px; color:#101828; flex:1; }

/* Add event form */
.sc-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.sc-form-group { display:flex; flex-direction:column; gap:5px; }
.sc-form-group.full { grid-column:1/-1; }
.sc-form-label { font-size:12px; font-weight:700; color:#344054; }
.sc-form-input, .sc-form-select { padding:9px 12px; border:1px solid #EAEAEA; border-radius:10px; font-size:13px; outline:none; width:100%; }
.sc-form-input:focus, .sc-form-select:focus { border-color:#00264D; box-shadow:0 0 0 3px rgba(0,38,77,.1); }
.sc-form-actions { display:flex; gap:10px; margin-top:16px; }
.sc-btn-primary { background:#00264D; color:#fff; border:none; padding:10px 20px; border-radius:10px; font-size:13px; font-weight:700; cursor:pointer; }
.sc-btn-primary:hover { background:#003d7a; }
.sc-btn-danger  { background:#CC0000; color:#fff; border:none; padding:8px 16px; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer; }
.sc-btn-ghost   { background:#f8fafc; color:#344054; border:1px solid #EAEAEA; padding:10px 16px; border-radius:10px; font-size:13px; cursor:pointer; }

/* Alert */
.sc-alert { padding:12px 16px; border-radius:10px; margin-bottom:16px; font-size:13px; font-weight:600; }
.sc-alert.success { background:#D1FAE5; color:#065F46; border:1px solid #A7F3D0; }
.sc-alert.error   { background:#FEE2E2; color:#991B1B; border:1px solid #FECACA; }

/* Sync badge */
.sc-synced { display:inline-flex; align-items:center; gap:3px; background:#D1FAE5; color:#065F46; font-size:9px; font-weight:700; padding:1px 5px; border-radius:4px; text-transform:uppercase; }

@media(max-width:900px){
  .sc-wrap { flex-direction:column; }
  .sc-sidebar { width:100%; }
  .sc-grid { grid-template-columns:120px repeat(7,1fr); }
}
</style>

<div class="sc-wrap">
  <!-- ===== MAIN CALENDAR AREA ===== -->
  <div class="sc-main">

    <?php if($msg): ?>
    <div class="sc-alert <?php echo strpos($msg,'Error')!==false?'error':'success'; ?>">
      <i class="fas fa-<?php echo strpos($msg,'Error')!==false?'exclamation-circle':'check-circle'; ?>"></i>
      <?php echo htmlspecialchars($msg); ?>
    </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="sc-header">
      <div class="sc-header-left">
        <h2><i class="fas fa-calendar-alt" style="margin-right:8px"></i>Staff Calendar</h2>
        <p>Weekly schedule — Job Orders, Deliveries, Fuel Calibration</p>
      </div>
      <div class="sc-nav">
        <a href="staff_calendar.php?week_offset=<?php echo $prev_offset; ?>" class="sc-nav-btn">
          <i class="fas fa-chevron-left"></i>
        </a>
        <span class="sc-week-label"><?php echo htmlspecialchars($week_label); ?></span>
        <a href="staff_calendar.php?week_offset=<?php echo $next_offset; ?>" class="sc-nav-btn">
          <i class="fas fa-chevron-right"></i>
        </a>
        <a href="staff_calendar.php?week_offset=0" class="sc-today-btn">Today</a>
        <?php if(in_array($rk,['manager','admin'])): ?>
        <button class="sc-nav-btn" onclick="openAddModal()" style="background:#00264D;color:#fff;border-color:#00264D">
          <i class="fas fa-plus"></i> Add Event
        </button>
        <?php endif; ?>
      </div>
    </div>


    <!-- Weekly Grid -->
    <div class="sc-grid-wrap">
      <div class="sc-grid">

        <!-- Header row: label + 7 day columns -->
        <div class="sc-col-head-label">Staff</div>
        <?php
        $day_names = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
        foreach($week_dates as $i => $wd):
          $is_today = ($wd === $today_str);
          $dn = $day_names[$i];
          $dd = date('j', strtotime($wd));
        ?>
        <div class="sc-col-head <?php echo $is_today?'today-col':''; ?>">
          <div class="day-name"><?php echo $dn; ?></div>
          <div class="day-num <?php echo $is_today?'today':''; ?>"><?php echo $dd; ?></div>
        </div>
        <?php endforeach; ?>

        <!-- Staff rows -->
        <?php foreach($row_people as $pkey => $person):
          $is_mgr = !empty($person['is_manager']);
          $initials = strtoupper(substr($person['name'],0,1));
          $uid = $person['id'];
        ?>
        <div class="sc-person-cell">
          <div class="sc-person-avatar" style="background:<?php echo htmlspecialchars($person['color']); ?>">
            <?php echo $initials; ?>
          </div>
          <div>
            <div class="sc-person-name"><?php echo htmlspecialchars($person['name']); ?></div>
            <div class="sc-person-role"><?php echo $person['role_label']; ?></div>
          </div>
        </div>

        <?php foreach($week_dates as $i => $wd):
          $is_today = ($wd === $today_str);
          // Collect events for this person+date
          $cell_events = [];
          // From direct staff events
          if(isset($week_events[$uid][$wd])) {
            $cell_events = array_merge($cell_events, $week_events[$uid][$wd]);
          }
          $cell_cls = 'sc-day-cell' . ($is_today?' today-col':'');
        ?>
        <div class="<?php echo $cell_cls; ?>">
          <?php if(empty($cell_events)): ?>
            <div class="sc-off-label">—</div>
          <?php else: ?>
            <?php foreach($cell_events as $ev):
              $tc = cal_type_color($ev['type_key']);
              $st = strtolower($ev['status']??'pending');
              $bg = $tc.'18'; // light tint
              $synced = !empty($ev['auto_synced']);
              $time_str = ($ev['start_time']!='00:00'||$ev['end_time']!='00:00')
                ? date('g:ia',strtotime($ev['start_time'])).' - '.date('g:ia',strtotime($ev['end_time']))
                : '';
              $ev_id_js = htmlspecialchars(json_encode($ev));
            ?>
            <div class="sc-event"
                 style="background:<?php echo $bg; ?>;border-left-color:<?php echo $tc; ?>"
                 onclick='openDetailModal(<?php echo $ev_id_js; ?>)'>
              <div class="sc-event-type" style="color:<?php echo $tc; ?>">
                <i class="<?php echo htmlspecialchars($ev['icon_class']??'fas fa-calendar'); ?>"></i>
                <?php echo htmlspecialchars($ev['type_name']); ?>
                <?php if($synced): ?><span class="sc-synced"><i class="fas fa-sync-alt"></i></span><?php endif; ?>
              </div>
              <div class="sc-event-desc"><?php echo htmlspecialchars($ev['work_description']); ?></div>
              <?php if($time_str): ?><div class="sc-event-time"><?php echo $time_str; ?></div><?php endif; ?>
              <div class="sc-event-mgr">
                <i class="fas fa-user-tie" style="color:#dc2626;font-size:9px"></i>
                <?php echo htmlspecialchars($ev['manager_assigned_name']??'--'); ?>
              </div>
              <?php echo cal_status_badge($st); ?>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php endforeach; ?>

      </div><!-- .sc-grid -->
    </div><!-- .sc-grid-wrap -->

  </div><!-- .sc-main -->

  <!-- ===== RIGHT SIDEBAR ===== -->
  <div class="sc-sidebar">

    <!-- Today's Events -->
    <div class="sc-card">
      <p class="sc-card-title"><i class="fas fa-sun"></i> Today's Events
        <span style="margin-left:auto;background:#00264D;color:#fff;font-size:11px;padding:2px 8px;border-radius:20px;font-weight:700">
          <?php echo count($today_events); ?>
        </span>
      </p>
      <?php if(empty($today_events)): ?>
        <p style="font-size:12px;color:#9ca3af;text-align:center;padding:12px 0">No events today</p>
      <?php else: ?>
        <?php foreach(array_slice($today_events,0,6) as $ev):
          $tc = cal_type_color($ev['type_key']);
        ?>
        <div class="sc-today-item">
          <div class="sc-today-dot" style="background:<?php echo $tc; ?>"></div>
          <div class="sc-today-info">
            <div class="sc-today-type"><?php echo htmlspecialchars($ev['type_name']); ?></div>
            <div class="sc-today-staff"><i class="fas fa-user" style="font-size:10px;color:#2563eb"></i> <?php echo htmlspecialchars($ev['staff_encoder_name']); ?></div>
            <div class="sc-today-mgr"><i class="fas fa-user-tie" style="font-size:10px;color:#dc2626"></i> <?php echo htmlspecialchars($ev['manager_assigned_name']??'--'); ?></div>
            <div class="sc-today-desc"><?php echo htmlspecialchars($ev['work_description']); ?></div>
            <?php echo cal_status_badge(strtolower($ev['status']??'pending')); ?>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if(count($today_events)>6): ?>
          <p style="font-size:11px;color:#667085;text-align:center;margin-top:8px">+<?php echo count($today_events)-6; ?> more</p>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <!-- Quick Status Overview -->
    <div class="sc-card">
      <p class="sc-card-title"><i class="fas fa-chart-pie"></i> This Week Status</p>
      <div class="sc-status-row">
        <span class="sc-status-label"><span style="width:10px;height:10px;border-radius:50%;background:#856404;display:inline-block"></span> Pending</span>
        <span class="sc-status-count"><?php echo $status_counts['pending']; ?></span>
      </div>
      <div class="sc-status-row">
        <span class="sc-status-label"><span style="width:10px;height:10px;border-radius:50%;background:#065F46;display:inline-block"></span> Approved</span>
        <span class="sc-status-count"><?php echo $status_counts['approved']; ?></span>
      </div>
      <div class="sc-status-row">
        <span class="sc-status-label"><span style="width:10px;height:10px;border-radius:50%;background:#1E40AF;display:inline-block"></span> Completed</span>
        <span class="sc-status-count"><?php echo $status_counts['completed']; ?></span>
      </div>
      <div class="sc-status-row">
        <span class="sc-status-label"><span style="width:10px;height:10px;border-radius:50%;background:#991B1B;display:inline-block"></span> Cancelled</span>
        <span class="sc-status-count"><?php echo $status_counts['cancelled']; ?></span>
      </div>
    </div>

    <!-- Upcoming Tasks (next 3 days) -->
    <div class="sc-card">
      <p class="sc-card-title"><i class="fas fa-clock"></i> Upcoming (3 days)
        <span style="margin-left:auto;background:#d97706;color:#fff;font-size:11px;padding:2px 8px;border-radius:20px;font-weight:700">
          <?php echo count($upcoming_events); ?>
        </span>
      </p>
      <?php if(empty($upcoming_events)): ?>
        <p style="font-size:12px;color:#9ca3af;text-align:center;padding:12px 0">No upcoming events</p>
      <?php else: ?>
        <?php foreach(array_slice($upcoming_events,0,5) as $ev):
          $tc = cal_type_color($ev['type_key']);
          $ev_date = date('D, M j', strtotime($ev['event_date']));
        ?>
        <div class="sc-upcoming-item">
          <div class="sc-upcoming-date"><?php echo $ev_date; ?></div>
          <div class="sc-upcoming-type" style="color:<?php echo $tc; ?>">
            <i class="<?php echo htmlspecialchars($ev['icon_class']??'fas fa-calendar'); ?>"></i>
            <?php echo htmlspecialchars($ev['type_name']); ?>
          </div>
          <div class="sc-upcoming-staff"><?php echo htmlspecialchars($ev['staff_encoder_name']); ?> &rarr; <?php echo htmlspecialchars($ev['manager_assigned_name']??'--'); ?></div>
          <?php echo cal_status_badge(strtolower($ev['status']??'pending')); ?>
        </div>
        <?php endforeach; ?>
        <?php if(count($upcoming_events)>5): ?>
          <p style="font-size:11px;color:#667085;text-align:center;margin-top:8px">+<?php echo count($upcoming_events)-5; ?> more</p>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <!-- Color Code Legend -->
    <div class="sc-card">
      <p class="sc-card-title"><i class="fas fa-palette"></i> Staff Color Code</p>
      <?php foreach($row_people as $pkey => $person):
        if (!empty($person['hide_legend'])) continue; // skip managers/admins
      ?>
      <div style="display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid #e4e6ea">
        <div style="width:28px;height:28px;border-radius:8px;background:<?php echo htmlspecialchars($person['color']); ?>;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:12px">
          <?php echo strtoupper(substr($person['name'],0,1)); ?>
        </div>
        <span style="font-size:13px;font-weight:700;color:#101828"><?php echo htmlspecialchars($person['name']); ?></span>
        <span style="font-size:11px;font-weight:600;color:#fff;background:<?php echo htmlspecialchars($person['color']); ?>;padding:2px 8px;border-radius:20px;margin-left:auto">Staff</span>
      </div>
      <?php endforeach; ?>
    </div>

  </div><!-- .sc-sidebar -->
</div><!-- .sc-wrap -->

<!-- ===== EVENT DETAIL MODAL ===== -->
<div class="sc-modal-overlay" id="detailModal">
  <div class="sc-modal">
    <div class="sc-modal-head">
      <h3 id="modalTitle"><i class="fas fa-calendar-check"></i> Event Details</h3>
      <button class="sc-modal-close" onclick="closeDetailModal()">&times;</button>
    </div>
    <div class="sc-modal-body" id="modalBody"></div>
  </div>
</div>

<!-- ===== ADD EVENT MODAL (Manager/Admin only) ===== -->
<?php if(in_array($rk,['manager','admin'])): ?>
<div class="sc-modal-overlay" id="addModal">
  <div class="sc-modal">
    <div class="sc-modal-head">
      <h3><i class="fas fa-calendar-plus"></i> Create Calendar Event</h3>
      <button class="sc-modal-close" onclick="closeAddModal()">&times;</button>
    </div>
    <div class="sc-modal-body">
      <form method="post" action="staff_calendar.php?week_offset=<?php echo $week_offset; ?>">
        <input type="hidden" name="action" value="create_event">
        <div class="sc-form-grid">
          <div class="sc-form-group">
            <label class="sc-form-label">Event Type *</label>
            <select name="event_type_id" class="sc-form-select" required>
              <option value="">Select type</option>
              <?php foreach($event_types as $et): ?>
              <option value="<?php echo $et['id']; ?>"><?php echo htmlspecialchars($et['type_name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="sc-form-group">
            <label class="sc-form-label">Event Date *</label>
            <input type="date" name="event_date" class="sc-form-input" value="<?php echo $today_str; ?>" required>
          </div>
          <div class="sc-form-group">
            <label class="sc-form-label">Staff Encoder *</label>
            <select name="staff_encoder_id" class="sc-form-select" required>
              <option value="">Select staff</option>
              <?php foreach($staff_list as $s): ?>
              <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="sc-form-group">
            <label class="sc-form-label">Manager Assigned</label>
            <select name="manager_assigned_id" class="sc-form-select">
              <option value="">Select manager</option>
              <?php foreach($manager_list as $m): ?>
              <option value="<?php echo $m['id']; ?>"><?php echo htmlspecialchars($m['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="sc-form-group">
            <label class="sc-form-label">Start Time</label>
            <input type="time" name="start_time" class="sc-form-input" value="08:00">
          </div>
          <div class="sc-form-group">
            <label class="sc-form-label">End Time</label>
            <input type="time" name="end_time" class="sc-form-input" value="17:00">
          </div>
          <div class="sc-form-group full">
            <label class="sc-form-label">Work Description *</label>
            <input type="text" name="work_description" class="sc-form-input" placeholder="e.g. Encode Job Order, Fuel Calibration Entry" required>
          </div>
          <div class="sc-form-group full">
            <label class="sc-form-label">Notes</label>
            <input type="text" name="notes" class="sc-form-input" placeholder="Optional notes">
          </div>
        </div>
        <div class="sc-form-actions">
          <button type="submit" class="sc-btn-primary"><i class="fas fa-save"></i> Create Event</button>
          <button type="button" class="sc-btn-ghost" onclick="closeAddModal()">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
// ── Detail Modal ──────────────────────────────────────────────────────────────
function openDetailModal(ev) {
  const statusMap = {pending:'badge-pending',approved:'badge-approved',completed:'badge-completed',cancelled:'badge-cancelled'};
  const st = (ev.status||'pending').toLowerCase();
  const badge = `<span class="cal-badge ${statusMap[st]||'badge-pending'}">${st.charAt(0).toUpperCase()+st.slice(1)}</span>`;
  const synced = ev.auto_synced ? '<span class="sc-synced"><i class="fas fa-sync-alt"></i> Auto-synced</span>' : '';
  const timeStr = (ev.start_time && ev.start_time!=='00:00')
    ? `${ev.start_time} – ${ev.end_time}` : 'All day';

  let statusActions = '';
  <?php if(in_array($rk,['manager','admin'])): ?>
  if (typeof ev.id === 'number') {
    statusActions = `
      <div style="margin-top:16px;padding-top:16px;border-top:1px solid #f0f0f0">
        <p style="font-size:12px;font-weight:700;color:#344054;margin:0 0 8px">Update Status</p>
        <form method="post" action="staff_calendar.php?week_offset=<?php echo $week_offset; ?>" style="display:flex;gap:8px;flex-wrap:wrap">
          <input type="hidden" name="action" value="update_status">
          <input type="hidden" name="event_id" value="${ev.id}">
          <select name="status" class="sc-form-select" style="flex:1;min-width:120px">
            <option value="pending" ${st==='pending'?'selected':''}>Pending</option>
            <option value="approved" ${st==='approved'?'selected':''}>Approved</option>
            <option value="completed" ${st==='completed'?'selected':''}>Completed</option>
            <option value="cancelled" ${st==='cancelled'?'selected':''}>Cancelled</option>
          </select>
          <button type="submit" class="sc-btn-primary" style="padding:8px 14px">Update</button>
        </form>
        <form method="post" action="staff_calendar.php?week_offset=<?php echo $week_offset; ?>" style="margin-top:8px" onsubmit="return confirm('Delete this event?')">
          <input type="hidden" name="action" value="delete_event">
          <input type="hidden" name="event_id" value="${ev.id}">
          <button type="submit" class="sc-btn-danger"><i class="fas fa-trash"></i> Delete Event</button>
        </form>
      </div>`;
  }
  <?php endif; ?>

  document.getElementById('modalTitle').innerHTML =
    `<i class="${ev.icon_class||'fas fa-calendar-check'}"></i> ${ev.type_name||'Event'} ${synced}`;

  document.getElementById('modalBody').innerHTML = `
    <div class="sc-detail-row"><span class="sc-detail-label">Date</span><span class="sc-detail-val">${ev.event_date}</span></div>
    <div class="sc-detail-row"><span class="sc-detail-label">Time</span><span class="sc-detail-val">${timeStr}</span></div>
    <div class="sc-detail-row"><span class="sc-detail-label">Event Type</span><span class="sc-detail-val">${ev.type_name}</span></div>
    <div class="sc-detail-row"><span class="sc-detail-label">Staff Encoder</span><span class="sc-detail-val"><span style="background:${ev.staff_color};color:#fff;padding:2px 10px;border-radius:20px;font-size:12px;font-weight:700">${ev.staff_encoder_name}</span></span></div>
    <div class="sc-detail-row"><span class="sc-detail-label">Manager Assigned</span><span class="sc-detail-val"><span style="background:${ev.manager_color};color:#fff;padding:2px 10px;border-radius:20px;font-size:12px;font-weight:700">${ev.manager_assigned_name||'--'}</span></span></div>
    <div class="sc-detail-row"><span class="sc-detail-label">Work Description</span><span class="sc-detail-val">${ev.work_description}</span></div>
    <div class="sc-detail-row"><span class="sc-detail-label">Status</span><span class="sc-detail-val">${badge}</span></div>
    ${ev.notes ? `<div class="sc-detail-row"><span class="sc-detail-label">Notes</span><span class="sc-detail-val">${ev.notes}</span></div>` : ''}
    ${ev.ref ? `<div class="sc-detail-row"><span class="sc-detail-label">Reference</span><span class="sc-detail-val">${ev.ref}</span></div>` : ''}
    ${statusActions}
  `;
  document.getElementById('detailModal').classList.add('open');
}
function closeDetailModal() {
  document.getElementById('detailModal').classList.remove('open');
}

// ── Add Modal ─────────────────────────────────────────────────────────────────
function openAddModal() {
  document.getElementById('addModal').classList.add('open');
}
function closeAddModal() {
  document.getElementById('addModal').classList.remove('open');
}

// Close on overlay click
document.querySelectorAll('.sc-modal-overlay').forEach(el => {
  el.addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('open');
  });
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
