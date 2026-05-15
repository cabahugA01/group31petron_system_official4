<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'admin_calendar';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$user_role = strtolower(trim($me['role'] ?? ''));
if (!in_array($user_role, ['admin', 'superadmin'])) {
    header('Location: ../index.php');
    exit();
}
$station_id = user_station_id();
if ((int)$station_id <= 0 && in_array($user_role, ['admin'])) {
    render_no_station_page('admin_dashboard.php');
}
$user_id = $me['id'];

// ── Handle POST early (before any output) ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'flag_event') {
    $ev_type = $_POST['event_type'] ?? '';
    $ev_id   = (int)($_POST['event_id'] ?? 0);
    $reason  = trim($_POST['reason'] ?? '');
    $wo      = (int)($_GET['week'] ?? 0);
    if ($ev_id && $reason) {
        try {
            if (function_exists('log_activity')) {
                log_activity($pdo, $user_id, 'Admin Flagged Event', "Type: $ev_type | ID: $ev_id | Reason: $reason");
            }
            $_SESSION['success'] = "Event #$ev_id flagged successfully.";
        } catch (Exception $e) {
            $_SESSION['error'] = 'Flag failed: ' . $e->getMessage();
        }
    }
    header("Location: admin_calendar.php?week=$wo");
    exit;
}

// Station info
try {
    $stmt = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
    $stmt->execute([$station_id]);
    $station = $stmt->fetch(PDO::FETCH_ASSOC);
    $station_name = $station['name'] ?? 'Unknown Station';
} catch (Exception $e) { $station_name = 'Station'; }

// Week navigation
$today       = new DateTime();
$week_offset = isset($_GET['week']) ? (int)$_GET['week'] : 0;
$week_start  = clone $today;
$week_start->modify('Monday this week');
$week_start->modify($week_offset . ' weeks');
$week_end = clone $week_start;
$week_end->modify('+6 days');

$week_dates = [];
for ($i = 0; $i < 7; $i++) {
    $d = clone $week_start;
    $d->modify("+$i days");
    $week_dates[] = $d;
}

$prev_week  = $week_offset - 1;
$next_week  = $week_offset + 1;
$week_label = $week_start->format('F j') . ' – ' . $week_end->format('j, Y');
$today_str  = $today->format('Y-m-d');
$ws_str     = $week_start->format('Y-m-d');
$we_str     = $week_end->format('Y-m-d');

// ── Filter params ─────────────────────────────────────────────────────────────
$filter_status = trim($_GET['filter_status'] ?? '');
$filter_type   = trim($_GET['filter_type']   ?? '');

// ── Load events ───────────────────────────────────────────────────────────────
$week_events = []; // [type_key][date][] = event

// 1. Job Orders
try {
    $stmt = $pdo->prepare("
        SELECT jo.id, jo.created_by, jo.customer_name, jo.service_type,
               jo.validation_status, jo.created_at,
               u.name AS staff_name,
               mu.name AS manager_name
        FROM job_orders jo
        JOIN users u ON jo.created_by = u.id
        LEFT JOIN users mu ON jo.validated_by = mu.id
        WHERE jo.station_id = ? AND DATE(jo.created_at) BETWEEN ? AND ?
        ORDER BY jo.created_at DESC");
    $stmt->execute([$station_id, $ws_str, $we_str]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ev_date = date('Y-m-d', strtotime($r['created_at']));
        $week_events['job_order'][$ev_date][] = [
            'id'               => 'jo_'.$r['id'],
            'raw_id'           => $r['id'],
            'type_key'         => 'job_order',
            'type_name'        => 'Job Order',
            'icon_class'       => 'fas fa-wrench',
            'staff_name'       => $r['staff_name'] ?? '—',
            'manager_name'     => $r['manager_name'] ?? '—',
            'event_date'       => $ev_date,
            'start_time'       => '00:00',
            'end_time'         => '00:00',
            'work_description' => ($r['service_type'] ?? 'Job Order').' — '.($r['customer_name'] ?? ''),
            'status'           => strtolower($r['validation_status'] ?? 'pending'),
            'customer_name'    => $r['customer_name'] ?? '',
            'auto_synced'      => true,
        ];
    }
} catch (Exception $e) {}

// 2. Deliveries (deliveries_oversight table)
try {
    $stmt = $pdo->prepare("
        SELECT d.id, d.encoded_by, d.status, d.supplier_name,
               d.delivery_type, d.delivery_date,
               u.name AS staff_name,
               mu.name AS manager_name
        FROM deliveries_oversight d
        LEFT JOIN users u  ON u.id  = d.encoded_by
        LEFT JOIN users mu ON mu.id = d.admin_id
        WHERE d.station_id = ? AND DATE(COALESCE(d.delivery_date, d.created_at)) BETWEEN ? AND ?
        ORDER BY d.created_at DESC");
    $stmt->execute([$station_id, $ws_str, $we_str]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ev_date = $r['delivery_date'] ?? date('Y-m-d', strtotime($r['created_at'] ?? 'now'));
        $week_events['delivery'][$ev_date][] = [
            'id'               => 'del_'.$r['id'],
            'raw_id'           => $r['id'],
            'type_key'         => 'delivery',
            'type_name'        => 'Delivery',
            'icon_class'       => 'fas fa-truck',
            'staff_name'       => $r['staff_name'] ?? '—',
            'manager_name'     => $r['manager_name'] ?? '—',
            'event_date'       => $ev_date,
            'start_time'       => '00:00',
            'end_time'         => '00:00',
            'work_description' => 'Delivery #'.$r['id'].' — '.($r['supplier_name'] ?? '').($r['delivery_type'] ? ' ('.$r['delivery_type'].')' : ''),
            'status'           => strtolower($r['status'] ?? 'pending'),
            'customer_name'    => '',
            'auto_synced'      => true,
        ];
    }
} catch (Exception $e) {}

// 3. Purchase Orders
try {
    $stmt = $pdo->prepare("
        SELECT po.id, po.created_by, po.status, po.product_name,
               po.quantity, po.total_amount, po.created_at,
               u.name AS staff_name
        FROM purchase_orders po
        LEFT JOIN users u ON u.id = po.created_by
        WHERE po.station_id = ? AND DATE(po.created_at) BETWEEN ? AND ?
        ORDER BY po.created_at DESC");
    $stmt->execute([$station_id, $ws_str, $we_str]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ev_date = date('Y-m-d', strtotime($r['created_at']));
        $week_events['purchase_order'][$ev_date][] = [
            'id'               => 'po_'.$r['id'],
            'raw_id'           => $r['id'],
            'type_key'         => 'purchase_order',
            'type_name'        => 'Purchase Order',
            'icon_class'       => 'fas fa-file-invoice-dollar',
            'staff_name'       => $r['staff_name'] ?? '—',
            'manager_name'     => '—',
            'event_date'       => $ev_date,
            'start_time'       => '00:00',
            'end_time'         => '00:00',
            'work_description' => 'PO #'.$r['id'].' — '.($r['product_name'] ?? '').' (qty: '.($r['quantity'] ?? 0).')',
            'status'           => strtolower($r['status'] ?? 'pending'),
            'customer_name'    => '',
            'auto_synced'      => true,
        ];
    }
} catch (Exception $e) {}

// 4. Fuel Calibration (fuel_calibration_logs)
try {
    $stmt = $pdo->prepare("
        SELECT fc.id, fc.performed_by, fc.calibration_date,
               fc.pump_number, fc.status, fc.notes,
               u.name AS staff_name,
               mu.name AS manager_name
        FROM fuel_calibration_logs fc
        LEFT JOIN users u  ON u.id  = fc.performed_by
        LEFT JOIN users mu ON mu.id = fc.approved_by
        WHERE fc.station_id = ? AND fc.calibration_date BETWEEN ? AND ?
        ORDER BY fc.calibration_date DESC");
    $stmt->execute([$station_id, $ws_str, $we_str]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $week_events['fuel_calibration'][$r['calibration_date']][] = [
            'id'               => 'fc_'.$r['id'],
            'raw_id'           => $r['id'],
            'type_key'         => 'fuel_calibration',
            'type_name'        => 'Fuel Calibration',
            'icon_class'       => 'fas fa-tachometer-alt',
            'staff_name'       => $r['staff_name'] ?? '—',
            'manager_name'     => $r['manager_name'] ?? '—',
            'event_date'       => $r['calibration_date'],
            'start_time'       => '00:00',
            'end_time'         => '00:00',
            'work_description' => 'Pump #'.($r['pump_number'] ?? '?').' — '.($r['notes'] ?? 'Calibration'),
            'status'           => strtolower($r['status'] ?? 'pending'),
            'customer_name'    => '',
            'auto_synced'      => true,
        ];
    }
} catch (Exception $e) {}

// 5. Staff Shifts
try {
    $stmt = $pdo->prepare("
        SELECT ss.id, ss.staff_id, ss.shift_date, ss.start_time, ss.end_time,
               u.name AS staff_name
        FROM staff_shifts ss
        JOIN users u ON ss.staff_id = u.id
        WHERE ss.station_id = ? AND ss.shift_date BETWEEN ? AND ?
        ORDER BY ss.shift_date, ss.start_time");
    $stmt->execute([$station_id, $ws_str, $we_str]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $week_events['staff_shift'][$r['shift_date']][] = [
            'id'               => 'shift_'.$r['id'],
            'raw_id'           => $r['id'],
            'type_key'         => 'staff_shift',
            'type_name'        => 'Staff Shift',
            'icon_class'       => 'fas fa-user-clock',
            'staff_name'       => $r['staff_name'] ?? '—',
            'manager_name'     => '—',
            'event_date'       => $r['shift_date'],
            'start_time'       => $r['start_time'],
            'end_time'         => $r['end_time'],
            'work_description' => date('g:i A', strtotime($r['start_time'])).' – '.date('g:i A', strtotime($r['end_time'])),
            'status'           => 'completed',
            'customer_name'    => '',
            'auto_synced'      => true,
        ];
    }
} catch (Exception $e) {}

// ── Summary widget counts ─────────────────────────────────────────────────────
function safe_count(PDO $pdo, string $sql, array $p = []): int {
    try { $s = $pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); }
    catch (Exception $e) { return 0; }
}

$upcoming_deliveries = safe_count($pdo,
    "SELECT COUNT(*) FROM deliveries_oversight WHERE station_id=? AND DATE(COALESCE(delivery_date,created_at)) >= CURDATE() AND status NOT IN ('Confirmed','Rejected','Discrepancy')",
    [$station_id]);

$scheduled_calibrations = safe_count($pdo,
    "SELECT COUNT(*) FROM fuel_calibration_logs WHERE station_id=? AND calibration_date >= CURDATE() AND status NOT IN ('completed','approved')",
    [$station_id]);

$pending_job_orders = safe_count($pdo,
    "SELECT COUNT(*) FROM job_orders WHERE station_id=? AND (validation_status IS NULL OR validation_status='Pending')",
    [$station_id]);

$active_shifts_today = safe_count($pdo,
    "SELECT COUNT(*) FROM staff_shifts WHERE station_id=? AND shift_date=CURDATE()",
    [$station_id]);

// ── Sidebar: today + upcoming events ─────────────────────────────────────────
$today_events    = [];
$upcoming_events = [];
$weekly_stats    = ['pending'=>0,'approved'=>0,'completed'=>0,'rejected'=>0,'total'=>0];
$three_days      = date('Y-m-d', strtotime('+3 days'));

foreach ($week_events as $type_key => $dates) {
    foreach ($dates as $date => $evs) {
        foreach ($evs as $ev) {
            $weekly_stats['total']++;
            $st = strtolower($ev['status'] ?? 'pending');
            if (in_array($st, ['pending','pending validation','pending manager approval'])) $weekly_stats['pending']++;
            elseif (in_array($st, ['approved','confirmed','validated'])) $weekly_stats['approved']++;
            elseif (in_array($st, ['completed','done'])) $weekly_stats['completed']++;
            elseif (in_array($st, ['rejected','discrepancy'])) $weekly_stats['rejected']++;

            if ($date === $today_str) $today_events[] = $ev;
            elseif ($date > $today_str && $date <= $three_days) $upcoming_events[] = $ev;
        }
    }
}

// ── Helper functions ──────────────────────────────────────────────────────────
function adm_cal_type_color(string $type_key): string {
    return [
        'job_order'       => '#2563eb',
        'delivery'        => '#16a34a',
        'purchase_order'  => '#d97706',
        'fuel_calibration'=> '#f59e0b',
        'staff_shift'     => '#0891b2',
    ][$type_key] ?? '#6b7280';
}

function adm_cal_status_badge(string $status): string {
    $map = [
        'pending'                    => ['badge-pending',   'Pending'],
        'pending validation'         => ['badge-pending',   'Pending'],
        'pending manager approval'   => ['badge-pending',   'Pending'],
        'approved'                   => ['badge-approved',  'Approved'],
        'confirmed'                  => ['badge-approved',  'Confirmed'],
        'validated'                  => ['badge-approved',  'Validated'],
        'completed'                  => ['badge-completed', 'Completed'],
        'done'                       => ['badge-completed', 'Done'],
        'rejected'                   => ['badge-rejected',  'Rejected'],
        'discrepancy'                => ['badge-rejected',  'Discrepancy'],
        'cancelled'                  => ['badge-cancelled', 'Cancelled'],
    ];
    [$cls, $lbl] = $map[$status] ?? ['badge-pending', ucfirst($status)];
    return '<span class="cal-badge '.$cls.'">'.$lbl.'</span>';
}

require_once '../partials/header.php';
?>

<style>
.sc-wrap{display:flex;gap:20px;padding:20px;max-width:100%;overflow-x:hidden;}
.sc-main{flex:1;min-width:0;}
.sc-sidebar{width:300px;flex-shrink:0;display:flex;flex-direction:column;gap:16px;}
.sc-header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:#fff;border:1px solid #EAEAEA;margin-bottom:18px;border-radius:14px;box-shadow:0 1px 4px rgba(0,0,0,.04);flex-wrap:wrap;gap:10px;}
.sc-header-left h2{margin:0;font-size:18px;font-weight:800;color:#101828;display:flex;align-items:center;}
.sc-header-left p{margin:3px 0 0;color:#667085;font-size:12px;}
.sc-nav{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.sc-nav-btn{background:#f8fafc;border:1px solid #EAEAEA;color:#344054;padding:7px 14px;border-radius:8px;font-size:13px;cursor:pointer;transition:.2s;text-decoration:none;display:inline-flex;align-items:center;gap:5px;}
.sc-nav-btn:hover{background:#f0f4ff;border-color:#c7d7f5;color:#00264D;}
.sc-week-label{font-weight:700;font-size:14px;min-width:160px;text-align:center;color:#101828;}
.sc-today-btn{background:#00264D;color:#fff;border:none;padding:7px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;}
.sc-today-btn:hover{background:#003d7a;color:#fff;}
.sc-filter-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px;background:#fff;border:1px solid #EAEAEA;border-radius:10px;padding:10px 14px;}
.sc-filter-bar select{padding:6px 10px;border:1px solid #dee2e6;border-radius:6px;font-size:12px;color:#344054;}
.sc-filter-bar label{font-size:11px;font-weight:700;color:#667085;text-transform:uppercase;letter-spacing:.4px;}
.sc-filter-bar .fg{display:flex;flex-direction:column;gap:3px;}
.sc-filter-btn{background:#00264D;color:#fff;border:none;padding:6px 14px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;}
.sc-filter-clear{background:#f8fafc;color:#344054;border:1px solid #dee2e6;padding:6px 12px;border-radius:6px;font-size:12px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;}
.sc-grid-wrap{background:#e9eaec;border-radius:14px;border:1px solid #d8dadf;overflow-x:auto;box-shadow:0 2px 12px rgba(0,0,0,.06);}
.sc-grid{display:grid;grid-template-columns:180px repeat(7,minmax(100px,1fr));min-width:900px;}
.sc-col-head-label{background:#eef0f3;padding:10px 12px;border-bottom:2px solid #d8dadf;border-right:1px solid #d8dadf;font-size:11px;font-weight:700;color:#667085;text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center;}
.sc-col-head{background:#eef0f3;padding:10px 8px;text-align:center;border-bottom:2px solid #d8dadf;border-right:1px solid #d8dadf;}
.sc-col-head:last-child{border-right:none;}
.sc-col-head.today-col{background:#eef4ff;}
.day-name{font-size:11px;font-weight:700;color:#667085;text-transform:uppercase;letter-spacing:.5px;}
.day-num{font-size:18px;font-weight:800;color:#101828;line-height:1.2;}
.day-num.today{background:#00264D;color:#fff;width:32px;height:32px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:15px;}
.sc-section-cell{padding:12px 10px;background:#fff;border-bottom:1px solid #d8dadf;border-right:1px solid #d8dadf;display:flex;align-items:center;gap:10px;min-height:60px;}
.sc-section-icon{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;font-size:14px;flex-shrink:0;}
.sc-section-name{font-weight:800;font-size:12px;line-height:1.2;text-transform:uppercase;letter-spacing:.4px;}
.sc-section-sub{font-size:10px;color:#667085;margin-top:2px;}
.sc-day-cell{padding:6px;background:#f5f6f8;border-bottom:1px solid #d8dadf;border-right:1px solid #d8dadf;min-height:80px;vertical-align:top;}
.sc-day-cell:last-child{border-right:none;}
.sc-day-cell.today-col{background:#eef4ff;}
.sc-off-label{font-size:11px;color:#9ca3af;text-align:center;padding-top:20px;}
.sc-event{padding:8px;border-radius:8px;margin-bottom:6px;border-left:4px solid;cursor:pointer;transition:all .2s;font-size:11px;}
.sc-event:hover{transform:translateX(2px);box-shadow:0 2px 4px rgba(0,0,0,.1);}
.sc-event-type{font-weight:600;margin-bottom:2px;display:flex;align-items:center;gap:4px;}
.sc-event-desc{color:#374151;line-height:1.3;margin-bottom:2px;}
.sc-event-time{color:#6b7280;font-size:10px;margin-bottom:2px;}
.sc-event-staff{font-size:10px;color:#374151;margin-bottom:2px;display:flex;align-items:center;}
.sc-event-mgr{color:#dc2626;font-size:10px;margin-bottom:2px;}
.cal-badge{font-size:9px;font-weight:700;padding:2px 6px;border-radius:12px;text-transform:uppercase;}
.badge-pending{background:#fef3c7;color:#92400e;}
.badge-approved{background:#d1fae5;color:#065f46;}
.badge-completed{background:#dbeafe;color:#1e40af;}
.badge-cancelled{background:#f3f4f6;color:#374151;}
.badge-rejected{background:#fee2e2;color:#991b1b;}
.sc-synced{display:inline-flex;align-items:center;gap:3px;background:#D1FAE5;color:#065F46;font-size:9px;font-weight:700;padding:1px 5px;border-radius:4px;text-transform:uppercase;}
.sc-card{background:#f5f6f8;border-radius:14px;border:1px solid #e4e6ea;padding:16px;}
.sc-card-title{font-weight:600;color:#111827;margin:0 0 12px;display:flex;align-items:center;gap:6px;font-size:14px;}
.sc-today-item{display:flex;gap:8px;margin-bottom:8px;padding-bottom:8px;border-bottom:1px solid #f3f4f6;}
.sc-today-item:last-child{border-bottom:none;margin-bottom:0;padding-bottom:0;}
.sc-today-dot{width:8px;height:8px;border-radius:50%;margin-top:6px;flex-shrink:0;}
.sc-today-info{flex:1;min-width:0;}
.sc-today-type{font-weight:600;font-size:12px;color:#111827;margin-bottom:2px;}
.sc-today-desc{font-size:11px;color:#6b7280;margin-bottom:1px;line-height:1.3;}
.sc-status-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;}
.sc-status-row:last-child{margin-bottom:0;}
.sc-status-label{font-size:12px;color:#374151;display:flex;align-items:center;gap:6px;}
.sc-status-count{font-weight:600;font-size:13px;color:#111827;}
.sc-widget-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:0;}
.sc-widget{background:#fff;border-radius:10px;border:1px solid #e4e6ea;padding:12px;text-align:center;}
.sc-widget-num{font-size:24px;font-weight:800;line-height:1;}
.sc-widget-lbl{font-size:10px;font-weight:700;color:#667085;text-transform:uppercase;letter-spacing:.4px;margin-top:4px;}
.sc-modal-overlay{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);display:none;align-items:center;justify-content:center;z-index:9999;}
.sc-modal-overlay.open{display:flex;}
.sc-modal{background:#fff;border-radius:16px;width:min(520px,94vw);max-height:88vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);}
.sc-modal-body{padding:20px 24px;}
.sc-detail-row{display:flex;gap:12px;padding:8px 0;border-bottom:1px solid #f0f0f0;align-items:flex-start;}
.sc-detail-row:last-child{border-bottom:none;}
.sc-detail-label{font-size:12px;font-weight:700;color:#667085;width:120px;flex-shrink:0;padding-top:1px;}
.sc-detail-val{font-size:13px;color:#101828;flex:1;}
.sc-flag-btn{background:#CC0000;color:#fff;border:none;padding:6px 12px;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;}
.sc-flag-btn:hover{background:#a00000;}
.sc-alert{padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:13px;font-weight:600;}
.sc-alert.success{background:#D1FAE5;color:#065F46;border:1px solid #A7F3D0;}
.sc-alert.error{background:#FEE2E2;color:#991B1B;border:1px solid #FECACA;}
@media(max-width:900px){
  .sc-wrap{flex-direction:column;}
  .sc-sidebar{width:100%;flex:none;}
  .sc-grid{grid-template-columns:120px repeat(7,minmax(80px,1fr));}
  .sc-widget-grid{grid-template-columns:1fr 1fr;}
}
</style>

<div class="sc-wrap">
  <!-- ===== MAIN CALENDAR ===== -->
  <div class="sc-main">

    <?php if (isset($_SESSION['success']) || isset($_SESSION['error'])): ?>
    <div class="sc-alert <?php echo isset($_SESSION['error']) ? 'error' : 'success'; ?>">
      <i class="fas fa-<?php echo isset($_SESSION['error']) ? 'exclamation-circle' : 'check-circle'; ?>"></i>
      <?php echo htmlspecialchars($_SESSION['success'] ?? $_SESSION['error'] ?? ''); ?>
      <?php unset($_SESSION['success'], $_SESSION['error']); ?>
    </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="sc-header">
      <div class="sc-header-left">
        <h2><i class="fas fa-calendar-check" style="margin-right:8px;color:#00264D;"></i>Admin Calendar Oversight</h2>
        <p>Deliveries, Job Orders, Fuel Calibration, Purchase Orders, Staff Shifts</p>
      </div>
      <div class="sc-nav">
        <a href="admin_calendar.php?week=<?php echo $prev_week; ?>&filter_status=<?php echo urlencode($filter_status); ?>&filter_type=<?php echo urlencode($filter_type); ?>" class="sc-nav-btn">
          <i class="fas fa-chevron-left"></i>
        </a>
        <span class="sc-week-label"><?php echo htmlspecialchars($week_label); ?></span>
        <a href="admin_calendar.php?week=<?php echo $next_week; ?>&filter_status=<?php echo urlencode($filter_status); ?>&filter_type=<?php echo urlencode($filter_type); ?>" class="sc-nav-btn">
          <i class="fas fa-chevron-right"></i>
        </a>
        <a href="admin_calendar.php?week=0" class="sc-today-btn">Today</a>
        <a href="admin_calendar.php?week=<?php echo $week_offset; ?>&export=csv&filter_status=<?php echo urlencode($filter_status); ?>&filter_type=<?php echo urlencode($filter_type); ?>" class="sc-nav-btn" title="Export CSV">
          <i class="fas fa-download"></i> Export
        </a>
      </div>
    </div>

    <!-- Filter Bar -->
    <form method="GET" action="admin_calendar.php" class="sc-filter-bar">
      <input type="hidden" name="week" value="<?php echo $week_offset; ?>">
      <div class="fg">
        <label>Status</label>
        <select name="filter_status">
          <option value="">All Status</option>
          <option value="pending" <?php echo $filter_status==='pending'?'selected':''; ?>>Pending</option>
          <option value="approved" <?php echo $filter_status==='approved'?'selected':''; ?>>Approved</option>
          <option value="completed" <?php echo $filter_status==='completed'?'selected':''; ?>>Completed</option>
          <option value="rejected" <?php echo $filter_status==='rejected'?'selected':''; ?>>Rejected</option>
        </select>
      </div>
      <div class="fg">
        <label>Event Type</label>
        <select name="filter_type">
          <option value="">All Types</option>
          <option value="job_order" <?php echo $filter_type==='job_order'?'selected':''; ?>>Job Orders</option>
          <option value="delivery" <?php echo $filter_type==='delivery'?'selected':''; ?>>Deliveries</option>
          <option value="purchase_order" <?php echo $filter_type==='purchase_order'?'selected':''; ?>>Purchase Orders</option>
          <option value="fuel_calibration" <?php echo $filter_type==='fuel_calibration'?'selected':''; ?>>Fuel Calibration</option>
          <option value="staff_shift" <?php echo $filter_type==='staff_shift'?'selected':''; ?>>Staff Shifts</option>
        </select>
      </div>
      <div class="fg" style="justify-content:flex-end;">
        <label>&nbsp;</label>
        <div style="display:flex;gap:6px;">
          <button type="submit" class="sc-filter-btn"><i class="fas fa-filter"></i> Filter</button>
          <a href="admin_calendar.php?week=<?php echo $week_offset; ?>" class="sc-filter-clear"><i class="fas fa-times"></i> Clear</a>
        </div>
      </div>
    </form>

    <!-- Weekly Grid -->
    <div class="sc-grid-wrap">
      <div class="sc-grid">

        <!-- Column headers -->
        <div class="sc-col-head-label">Category</div>
        <?php
        $day_names = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
        foreach ($week_dates as $i => $date):
          $is_today = ($date->format('Y-m-d') === $today_str);
        ?>
        <div class="sc-col-head <?php echo $is_today ? 'today-col' : ''; ?>">
          <div class="day-name"><?php echo $day_names[$i]; ?></div>
          <div class="day-num <?php echo $is_today ? 'today' : ''; ?>"><?php echo $date->format('j'); ?></div>
        </div>
        <?php endforeach; ?>

        <?php
        // Section definitions — 5 rows
        $sections = [
            ['key'=>'job_order',        'label'=>'Job Orders',       'icon'=>'fas fa-wrench',               'color'=>'#2563eb', 'desc'=>'Staff-encoded orders'],
            ['key'=>'delivery',         'label'=>'Deliveries',       'icon'=>'fas fa-truck',                'color'=>'#16a34a', 'desc'=>'Incoming deliveries'],
            ['key'=>'purchase_order',   'label'=>'Purchase Orders',  'icon'=>'fas fa-file-invoice-dollar',  'color'=>'#d97706', 'desc'=>'PO tracking'],
            ['key'=>'fuel_calibration', 'label'=>'Fuel Calibration', 'icon'=>'fas fa-tachometer-alt',       'color'=>'#f59e0b', 'desc'=>'Pump calibration'],
            ['key'=>'staff_shift',      'label'=>'Staff Shifts',     'icon'=>'fas fa-user-clock',           'color'=>'#0891b2', 'desc'=>'Active duty schedules'],
        ];

        foreach ($sections as $section):
          $tk = $section['key'];
          $sc = $section['color'];

          // Apply type filter
          if ($filter_type && $filter_type !== $tk) {
              continue;
          }
        ?>

        <!-- Section label -->
        <div class="sc-section-cell" style="border-left:4px solid <?php echo $sc; ?>">
          <div class="sc-section-icon" style="background:<?php echo $sc; ?>18;color:<?php echo $sc; ?>">
            <i class="<?php echo $section['icon']; ?>"></i>
          </div>
          <div>
            <div class="sc-section-name" style="color:<?php echo $sc; ?>"><?php echo $section['label']; ?></div>
            <div class="sc-section-sub"><?php echo $section['desc']; ?></div>
          </div>
        </div>

        <!-- Day cells -->
        <?php foreach ($week_dates as $date):
          $is_today  = ($date->format('Y-m-d') === $today_str);
          $date_str  = $date->format('Y-m-d');
          $all_evs   = $week_events[$tk][$date_str] ?? [];

          // Apply status filter
          if ($filter_status) {
              $all_evs = array_filter($all_evs, function($ev) use ($filter_status) {
                  $st = strtolower($ev['status'] ?? '');
                  if ($filter_status === 'pending')   return in_array($st, ['pending','pending validation','pending manager approval']);
                  if ($filter_status === 'approved')  return in_array($st, ['approved','confirmed','validated']);
                  if ($filter_status === 'completed') return in_array($st, ['completed','done']);
                  if ($filter_status === 'rejected')  return in_array($st, ['rejected','discrepancy']);
                  return true;
              });
          }
        ?>
        <div class="sc-day-cell <?php echo $is_today ? 'today-col' : ''; ?>">
          <?php if (empty($all_evs)): ?>
            <div class="sc-off-label">—</div>
          <?php else: ?>
            <?php foreach ($all_evs as $ev):
              $st     = strtolower($ev['status'] ?? 'pending');
              $bg     = $sc . '18';
              $ev_js  = htmlspecialchars(json_encode($ev), ENT_QUOTES);
              $time_str = (!empty($ev['start_time']) && $ev['start_time'] !== '00:00')
                ? date('g:ia', strtotime($ev['start_time'])).' – '.date('g:ia', strtotime($ev['end_time']))
                : '';
            ?>
            <div class="sc-event" style="background:<?php echo $bg; ?>;border-left-color:<?php echo $sc; ?>"
                 onclick='openDetailModal(<?php echo $ev_js; ?>)'>
              <div class="sc-event-type" style="color:<?php echo $sc; ?>">
                <i class="<?php echo htmlspecialchars($ev['icon_class']); ?>"></i>
                <?php echo htmlspecialchars($ev['type_name']); ?>
                <span class="sc-synced"><i class="fas fa-sync-alt"></i></span>
              </div>
              <div class="sc-event-desc"><?php echo htmlspecialchars(mb_strimwidth($ev['work_description'], 0, 45, '…')); ?></div>
              <?php if ($time_str): ?><div class="sc-event-time"><?php echo $time_str; ?></div><?php endif; ?>
              <div class="sc-event-staff">
                <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:<?php echo $sc; ?>;margin-right:3px;"></span>
                <?php echo htmlspecialchars($ev['staff_name'] ?? '—'); ?>
              </div>
              <?php if (!empty($ev['manager_name']) && $ev['manager_name'] !== '—'): ?>
              <div class="sc-event-mgr">
                <i class="fas fa-user-tie" style="font-size:9px;"></i>
                <?php echo htmlspecialchars($ev['manager_name']); ?>
              </div>
              <?php endif; ?>
              <?php echo adm_cal_status_badge($st); ?>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <?php endforeach; // week_dates ?>

        <?php endforeach; // sections ?>

      </div><!-- .sc-grid -->
    </div><!-- .sc-grid-wrap -->

  </div><!-- .sc-main -->

  <!-- ===== RIGHT SIDEBAR ===== -->
  <div class="sc-sidebar">

    <!-- Summary Widgets -->
    <div class="sc-card">
      <p class="sc-card-title"><i class="fas fa-chart-bar" style="color:#00264D;"></i> Summary Dashboard</p>
      <div class="sc-widget-grid">
        <div class="sc-widget">
          <div class="sc-widget-num" style="color:#16a34a;"><?php echo $upcoming_deliveries; ?></div>
          <div class="sc-widget-lbl">Upcoming Deliveries</div>
        </div>
        <div class="sc-widget">
          <div class="sc-widget-num" style="color:#f59e0b;"><?php echo $scheduled_calibrations; ?></div>
          <div class="sc-widget-lbl">Scheduled Calibrations</div>
        </div>
        <div class="sc-widget">
          <div class="sc-widget-num" style="color:#2563eb;"><?php echo $pending_job_orders; ?></div>
          <div class="sc-widget-lbl">Pending Job Orders</div>
        </div>
        <div class="sc-widget">
          <div class="sc-widget-num" style="color:#0891b2;"><?php echo $active_shifts_today; ?></div>
          <div class="sc-widget-lbl">Shifts Active Today</div>
        </div>
      </div>
    </div>

    <!-- Today's Events -->
    <div class="sc-card">
      <p class="sc-card-title"><i class="fas fa-sun" style="color:#d97706;"></i> Today's Events
        <span style="margin-left:auto;background:#00264D;color:#fff;font-size:11px;padding:2px 8px;border-radius:20px;font-weight:700;">
          <?php echo count($today_events); ?>
        </span>
      </p>
      <?php if (empty($today_events)): ?>
        <p style="font-size:12px;color:#9ca3af;text-align:center;padding:12px 0;">No events today</p>
      <?php else: ?>
        <?php foreach (array_slice($today_events, 0, 6) as $ev):
          $tc = adm_cal_type_color($ev['type_key'] ?? 'job_order');
        ?>
        <div class="sc-today-item">
          <div class="sc-today-dot" style="background:<?php echo $tc; ?>;"></div>
          <div class="sc-today-info">
            <div class="sc-today-type"><?php echo htmlspecialchars($ev['type_name'] ?? 'Event'); ?></div>
            <div class="sc-today-desc"><?php echo htmlspecialchars(mb_strimwidth($ev['work_description'] ?? '', 0, 50, '…')); ?></div>
            <div class="sc-today-desc"><i class="fas fa-user" style="font-size:9px;"></i> <?php echo htmlspecialchars($ev['staff_name'] ?? '—'); ?></div>
            <?php echo adm_cal_status_badge(strtolower($ev['status'] ?? 'pending')); ?>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (count($today_events) > 6): ?>
          <p style="font-size:11px;color:#667085;text-align:center;margin-top:8px;">+<?php echo count($today_events) - 6; ?> more</p>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <!-- This Week Status -->
    <div class="sc-card">
      <p class="sc-card-title"><i class="fas fa-chart-pie" style="color:#6366f1;"></i> This Week Status</p>
      <div class="sc-status-row">
        <span class="sc-status-label"><span style="width:10px;height:10px;border-radius:50%;background:#92400e;display:inline-block;"></span> Pending</span>
        <span class="sc-status-count"><?php echo $weekly_stats['pending']; ?></span>
      </div>
      <div class="sc-status-row">
        <span class="sc-status-label"><span style="width:10px;height:10px;border-radius:50%;background:#065f46;display:inline-block;"></span> Approved</span>
        <span class="sc-status-count"><?php echo $weekly_stats['approved']; ?></span>
      </div>
      <div class="sc-status-row">
        <span class="sc-status-label"><span style="width:10px;height:10px;border-radius:50%;background:#1e40af;display:inline-block;"></span> Completed</span>
        <span class="sc-status-count"><?php echo $weekly_stats['completed']; ?></span>
      </div>
      <div class="sc-status-row">
        <span class="sc-status-label"><span style="width:10px;height:10px;border-radius:50%;background:#991b1b;display:inline-block;"></span> Rejected</span>
        <span class="sc-status-count"><?php echo $weekly_stats['rejected']; ?></span>
      </div>
      <div class="sc-status-row" style="border-top:1px solid #e4e6ea;padding-top:8px;margin-top:4px;">
        <span class="sc-status-label" style="font-weight:700;">Total Events</span>
        <span class="sc-status-count"><?php echo $weekly_stats['total']; ?></span>
      </div>
    </div>

    <!-- Upcoming (next 3 days) -->
    <div class="sc-card">
      <p class="sc-card-title"><i class="fas fa-clock" style="color:#d97706;"></i> Upcoming (3 days)
        <span style="margin-left:auto;background:#d97706;color:#fff;font-size:11px;padding:2px 8px;border-radius:20px;font-weight:700;">
          <?php echo count($upcoming_events); ?>
        </span>
      </p>
      <?php if (empty($upcoming_events)): ?>
        <p style="font-size:12px;color:#9ca3af;text-align:center;padding:12px 0;">No upcoming events</p>
      <?php else: ?>
        <?php foreach (array_slice($upcoming_events, 0, 5) as $ev):
          $tc = adm_cal_type_color($ev['type_key'] ?? 'job_order');
        ?>
        <div class="sc-today-item">
          <div class="sc-today-dot" style="background:<?php echo $tc; ?>;"></div>
          <div class="sc-today-info">
            <div class="sc-today-type"><?php echo htmlspecialchars($ev['type_name'] ?? 'Event'); ?></div>
            <div class="sc-today-desc"><?php echo htmlspecialchars(mb_strimwidth($ev['work_description'] ?? '', 0, 45, '…')); ?></div>
            <div class="sc-today-desc" style="color:#374151;">
              <?php echo date('M j', strtotime($ev['event_date'])); ?>
              &bull; <i class="fas fa-user" style="font-size:9px;"></i> <?php echo htmlspecialchars($ev['staff_name'] ?? '—'); ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (count($upcoming_events) > 5): ?>
          <p style="font-size:11px;color:#667085;text-align:center;margin-top:8px;">+<?php echo count($upcoming_events) - 5; ?> more</p>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <!-- Event Type Legend -->
    <div class="sc-card">
      <p class="sc-card-title"><i class="fas fa-palette" style="color:#6b7280;"></i> Event Types</p>
      <?php
      $legend = [
          ['color'=>'#2563eb','label'=>'Job Orders'],
          ['color'=>'#16a34a','label'=>'Deliveries'],
          ['color'=>'#d97706','label'=>'Purchase Orders'],
          ['color'=>'#f59e0b','label'=>'Fuel Calibration'],
          ['color'=>'#0891b2','label'=>'Staff Shifts'],
      ];
      foreach ($legend as $l): ?>
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
        <div style="width:12px;height:12px;border-radius:50%;background:<?php echo $l['color']; ?>;flex-shrink:0;"></div>
        <span style="font-size:12px;color:#374151;"><?php echo $l['label']; ?></span>
      </div>
      <?php endforeach; ?>
    </div>

  </div><!-- .sc-sidebar -->
</div><!-- .sc-wrap -->


<!-- ===== DETAIL MODAL ===== -->
<div class="sc-modal-overlay" id="detailModal">
  <div class="sc-modal">
    <div style="background:linear-gradient(135deg,#00264D,#003d7a);color:#fff;padding:20px 24px;border-radius:16px 16px 0 0;display:flex;align-items:center;justify-content:space-between;">
      <h3 id="modalTitle" style="margin:0;font-size:17px;font-weight:800;color:#fff;"></h3>
      <button onclick="closeDetailModal()" style="background:none;border:none;color:#fff;font-size:24px;cursor:pointer;line-height:1;">&times;</button>
    </div>
    <div class="sc-modal-body" id="modalBody"></div>
  </div>
</div>

<script>
function openDetailModal(ev) {
  const statusMap = {
    pending:'badge-pending', approved:'badge-approved', confirmed:'badge-approved',
    validated:'badge-approved', completed:'badge-completed', done:'badge-completed',
    rejected:'badge-rejected', discrepancy:'badge-rejected', cancelled:'badge-cancelled'
  };
  const st    = (ev.status || 'pending').toLowerCase();
  const badge = `<span class="cal-badge ${statusMap[st]||'badge-pending'}">${st.charAt(0).toUpperCase()+st.slice(1)}</span>`;

  const typeColors = {
    job_order:'#2563eb', delivery:'#16a34a', purchase_order:'#d97706',
    fuel_calibration:'#f59e0b', staff_shift:'#0891b2'
  };
  const tc = typeColors[ev.type_key] || '#6b7280';

  const evDate = ev.event_date
    ? new Date(ev.event_date + 'T00:00:00').toLocaleDateString('en-US',{weekday:'short',year:'numeric',month:'short',day:'numeric'})
    : '—';

  let html = `
    <div class="sc-detail-row"><span class="sc-detail-label">Date</span><span class="sc-detail-val">${evDate}</span></div>
    <div class="sc-detail-row">
      <span class="sc-detail-label">Event Type</span>
      <span class="sc-detail-val"><span style="display:inline-flex;align-items:center;gap:5px;"><span style="width:8px;height:8px;border-radius:50%;background:${tc};display:inline-block;"></span>${ev.type_name||'—'}</span></span>
    </div>
    <div class="sc-detail-row"><span class="sc-detail-label">Description</span><span class="sc-detail-val">${ev.work_description||'—'}</span></div>`;

  if (ev.staff_name && ev.staff_name !== '—') {
    html += `<div class="sc-detail-row"><span class="sc-detail-label">Encoded By</span><span class="sc-detail-val"><i class="fas fa-user" style="color:#2563eb;font-size:10px;margin-right:4px;"></i>${ev.staff_name}</span></div>`;
  }
  if (ev.manager_name && ev.manager_name !== '—') {
    html += `<div class="sc-detail-row"><span class="sc-detail-label">Validated By</span><span class="sc-detail-val"><i class="fas fa-user-tie" style="color:#dc2626;font-size:10px;margin-right:4px;"></i>${ev.manager_name}</span></div>`;
  }
  if (ev.customer_name) {
    html += `<div class="sc-detail-row"><span class="sc-detail-label">Customer</span><span class="sc-detail-val">${ev.customer_name}</span></div>`;
  }
  if (ev.start_time && ev.start_time !== '00:00') {
    html += `<div class="sc-detail-row"><span class="sc-detail-label">Time</span><span class="sc-detail-val">${ev.start_time} – ${ev.end_time}</span></div>`;
  }
  html += `<div class="sc-detail-row"><span class="sc-detail-label">Status</span><span class="sc-detail-val">${badge}</span></div>`;

  // Admin flag action
  html += `
    <div style="margin-top:16px;padding-top:16px;border-top:1px solid #f0f0f0;">
      <p style="font-size:12px;font-weight:700;color:#344054;margin:0 0 10px;text-transform:uppercase;letter-spacing:.4px;">Admin Actions</p>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <button class="sc-flag-btn" onclick="flagEvent('${ev.type_key}',${ev.raw_id})">
          <i class="fas fa-flag"></i> Flag Discrepancy
        </button>
      </div>
    </div>`;

  document.getElementById('modalTitle').innerHTML =
    `<i class="${ev.icon_class||'fas fa-calendar-check'}" style="margin-right:8px;"></i>${ev.type_name||'Event Details'}`;
  document.getElementById('modalBody').innerHTML = html;
  document.getElementById('detailModal').classList.add('open');
}

function closeDetailModal() {
  document.getElementById('detailModal').classList.remove('open');
}

document.getElementById('detailModal').addEventListener('click', function(e) {
  if (e.target === this) closeDetailModal();
});

function flagEvent(type, id) {
  const reason = prompt('Enter reason for flagging this event (required):');
  if (!reason || !reason.trim()) return;

  const form = document.createElement('form');
  form.method = 'POST';
  form.action = 'admin_calendar.php?week=<?php echo (int)$week_offset; ?>';
  [['action','flag_event'],['event_type',type],['event_id',id],['reason',reason]].forEach(([n,v]) => {
    const inp = document.createElement('input');
    inp.type='hidden'; inp.name=n; inp.value=v;
    form.appendChild(inp);
  });
  document.body.appendChild(form);
  form.submit();
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>