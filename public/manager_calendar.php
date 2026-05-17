<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'calendar';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$user_role = strtolower(trim($me['role'] ?? ''));
if (!in_array($user_role, ['manager', 'admin', 'superadmin'])) {
    header('Location: ../index.php');
    exit();
}
$station_id = user_station_id();
if (!$station_id) { die('Error: Not assigned to a station.'); }
$user_id = $me['id'];

// Get station info for display
$stmt = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
$stmt->execute([$station_id]);
$station = $stmt->fetch(PDO::FETCH_ASSOC);
$station_name = $station['name'] ?? 'Unknown Station';

// Auto-migrate schema for staff_shifts validations
try { $pdo->exec("ALTER TABLE staff_shifts ADD COLUMN validation_status VARCHAR(20) NOT NULL DEFAULT 'pending'"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE staff_shifts ADD COLUMN validated_by INT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE staff_shifts ADD COLUMN validation_notes TEXT NULL"); } catch(Exception $e) {}

// Get current date and week navigation
$today = new DateTime();
$week_offset = isset($_GET['week']) ? (int)$_GET['week'] : 0;
$current_week_start = clone $today;
$current_week_start->modify('Monday this week');
$current_week_start->modify($week_offset . ' weeks');
$current_week_end = clone $current_week_start;
$current_week_end->modify('Sunday this week');

$week_dates = [];
for ($i = 0; $i < 7; $i++) {
    $date = clone $current_week_start;
    $date->modify("+$i days");
    $week_dates[] = $date;
}

// Navigation
$prev_week = $week_offset - 1;
$next_week = $week_offset + 1;
$week_label = $current_week_start->format('F j') . ' – ' . $current_week_end->format('j, Y');

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'validate_job_order') {
        $job_order_id = (int)($_POST['job_order_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $notes = $_POST['notes'] ?? '';
        
        if ($job_order_id && in_array($status, ['Approved', 'Rejected', 'Adjusted'])) {
            try {
                $stmt = $pdo->prepare("UPDATE job_orders SET validation_status = ?, validated_by = ?, validated_at = NOW(), validation_notes = ? WHERE id = ? AND station_id = ?");
                $stmt->execute([$status, $user_id, $notes, $job_order_id, $station_id]);
                $_SESSION['success'] = "Job Order #$job_order_id has been $status";
            } catch (Exception $e) {
                $_SESSION['error'] = "Error updating job order: " . $e->getMessage();
            }
        }
        header("Location: manager_calendar.php?week=$week_offset");
        exit;
    }
    
    if ($action === 'update_delivery_status') {
        $delivery_id = (int)($_POST['delivery_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $notes = $_POST['notes'] ?? '';
        
        if ($delivery_id && in_array($status, ['Approved', 'Rejected', 'Adjusted'])) {
            try {
                $stmt = $pdo->prepare("UPDATE deliveries_oversight SET status = ?, admin_id = ?, admin_notes = ?, admin_action_at = NOW() WHERE id = ? AND station_id = ?");
                $stmt->execute([$status, $user_id, $notes, $delivery_id, $station_id]);
                $_SESSION['success'] = "Delivery #$delivery_id has been $status";
            } catch (Exception $e) {
                $_SESSION['error'] = "Error updating delivery: " . $e->getMessage();
            }
        }
        header("Location: manager_calendar.php?week=$week_offset");
        exit;
    }
    
    if ($action === 'validate_staff_shift') {
        $shift_id = (int)($_POST['shift_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $notes = $_POST['notes'] ?? '';
        
        if ($shift_id && in_array($status, ['Approved', 'Rejected', 'Adjusted'])) {
            try {
                $stmt = $pdo->prepare("UPDATE staff_shifts SET validation_status = ?, validated_by = ?, validation_notes = ? WHERE id = ? AND station_id = ?");
                $stmt->execute([strtolower($status), $user_id, $notes, $shift_id, $station_id]);
                $_SESSION['success'] = "Staff Shift #$shift_id has been $status";
            } catch (Exception $e) {
                $_SESSION['error'] = "Error updating shift: " . $e->getMessage();
            }
        }
        header("Location: manager_calendar.php?week=$week_offset");
        exit;
    }
    
    if ($action === 'validate_credit_transaction') {
        $transaction_id = (int)($_POST['transaction_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $notes = $_POST['notes'] ?? '';
        
        if ($transaction_id && in_array($status, ['Approved', 'Rejected'])) {
            try {
                $stmt = $pdo->prepare("UPDATE credit_transactions SET validation_status = ?, validated_by = ?, validated_at = NOW(), validation_notes = ? WHERE id = ? AND station_id = ?");
                $stmt->execute([$status, $user_id, $notes, $transaction_id, $station_id]);
                $_SESSION['success'] = "Credit Transaction #$transaction_id has been $status";
            } catch (Exception $e) {
                $_SESSION['error'] = "Error updating credit transaction: " . $e->getMessage();
            }
        }
        header("Location: manager_calendar.php?week=$week_offset");
        exit;
    }
}

// ── Load events for the week ──────────────────────────────────────────────────
$week_events    = [];  // [staff_id][date][] = event_array
$today_str      = $today->format('Y-m-d');
$week_start_str = $current_week_start->format('Y-m-d');
$week_end_str   = $current_week_end->format('Y-m-d');

// Auto-sync Job Orders
try {
    $stmt = $pdo->prepare("
        SELECT jo.id, jo.created_by, jo.customer_name, jo.service_type,
               jo.validation_status, jo.created_at,
               u.name AS staff_name,
               mu.name AS manager_assigned_name
        FROM job_orders jo
        JOIN users u ON jo.created_by = u.id AND u.role IN ('staff','cashier','pump_attendant')
        LEFT JOIN users mu ON jo.validated_by = mu.id
        WHERE jo.station_id = ? AND DATE(jo.created_at) BETWEEN ? AND ?
        ORDER BY jo.created_at DESC");
    $stmt->execute([$station_id, $week_start_str, $week_end_str]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ev_date = date('Y-m-d', strtotime($r['created_at']));
        $week_events[$r['created_by']][$ev_date][] = [
            'id'                => 'jo_'.$r['id'],
            'raw_id'            => $r['id'],
            'type_key'          => 'job_order',
            'type_name'         => 'Job Order',
            'icon_class'        => 'fas fa-wrench',
            'staff_encoder_id'  => $r['created_by'],
            'staff_name'        => $r['staff_name'],
            'manager_name'      => $r['manager_assigned_name'] ?? '—',
            'event_date'        => $ev_date,
            'start_time'        => '00:00',
            'end_time'          => '00:00',
            'work_description'  => ($r['service_type'] ?? 'Job Order').' — '.($r['customer_name'] ?? ''),
            'status'            => strtolower($r['validation_status'] ?? 'pending'),
            'validation_status' => $r['validation_status'] ?? 'pending',
            'customer_name'     => $r['customer_name'] ?? '',
            'staff_color'       => '#2563eb',
            'auto_synced'       => true,
        ];
    }
} catch (Exception $e) {}

// Auto-sync Credit/Utang Transactions
try {
    $stmt = $pdo->prepare("
        SELECT ct.id, ct.staff_id, ct.customer_name, ct.total_amount,
               ct.validation_status, ct.created_at,
               u.name AS staff_name
        FROM credit_transactions ct
        JOIN users u ON ct.staff_id = u.id AND u.role IN ('staff','cashier','pump_attendant')
        WHERE ct.station_id = ? AND DATE(ct.created_at) BETWEEN ? AND ?
        ORDER BY ct.created_at DESC");
    $stmt->execute([$station_id, $week_start_str, $week_end_str]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ev_date = date('Y-m-d', strtotime($r['created_at']));
        $week_events[$r['staff_id']][$ev_date][] = [
            'id'                => 'ct_'.$r['id'],
            'raw_id'            => $r['id'],
            'type_key'          => 'credit_txn',
            'type_name'         => 'Credit/Utang',
            'icon_class'        => 'fas fa-credit-card',
            'staff_encoder_id'  => $r['staff_id'],
            'staff_name'        => $r['staff_name'],
            'manager_name'      => '—',
            'event_date'        => $ev_date,
            'start_time'        => '00:00',
            'end_time'          => '00:00',
            'work_description'  => 'Credit — '.($r['customer_name'] ?? '').' ₱'.number_format((float)$r['total_amount'],2),
            'status'            => strtolower($r['validation_status'] ?? 'pending'),
            'validation_status' => $r['validation_status'] ?? 'pending',
            'customer_name'     => $r['customer_name'] ?? '',
            'staff_color'       => '#7c3aed',
            'auto_synced'       => true,
        ];
    }
} catch (Exception $e) {}

// Auto-sync Deliveries
try {
    $stmt = $pdo->prepare("
        SELECT d.id, d.encoded_by as created_by, d.status, d.supplier,
               DATE(d.delivery_date) AS event_date,
               u.name AS staff_name,
               mu.name AS manager_assigned_name
        FROM deliveries_oversight d
        JOIN users u ON d.encoded_by = u.id AND u.role IN ('staff','cashier','pump_attendant')
        LEFT JOIN users mu ON d.admin_id = mu.id
        WHERE d.station_id = ? AND DATE(d.delivery_date) BETWEEN ? AND ?
        ORDER BY d.delivery_date DESC");
    $stmt->execute([$station_id, $week_start_str, $week_end_str]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $week_events[$r['created_by']][$r['event_date']][] = [
            'id'                => 'del_'.$r['id'],
            'raw_id'            => $r['id'],
            'type_key'          => 'delivery',
            'type_name'         => 'Delivery',
            'icon_class'        => 'fas fa-truck',
            'staff_encoder_id'  => $r['created_by'],
            'staff_name'        => $r['staff_name'],
            'manager_name'      => $r['manager_assigned_name'] ?? '—',
            'event_date'        => $r['event_date'],
            'start_time'        => '00:00',
            'end_time'          => '00:00',
            'work_description'  => 'Delivery #'.$r['id'].' — '.($r['supplier'] ?? ''),
            'status'            => strtolower($r['status'] ?? 'pending'),
            'validation_status' => $r['status'] ?? 'pending',
            'customer_name'     => '',
            'staff_color'       => '#16a34a',
            'auto_synced'       => true,
        ];
    }
} catch (Exception $e) {}

// Auto-sync Staff Shifts
try {
    $stmt = $pdo->prepare("
        SELECT ss.id, ss.staff_id, ss.shift_date, ss.start_time, ss.end_time,
               u.name AS staff_name, ss.validation_status, ss.validated_by,
               mu.name AS manager_assigned_name
        FROM staff_shifts ss
        JOIN users u ON ss.staff_id = u.id
        LEFT JOIN users mu ON ss.validated_by = mu.id
        WHERE ss.station_id = ? AND ss.shift_date BETWEEN ? AND ?
        ORDER BY ss.shift_date, ss.start_time");
    $stmt->execute([$station_id, $week_start_str, $week_end_str]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $week_events[$r['staff_id']][$r['shift_date']][] = [
            'id'                => 'shift_'.$r['id'],
            'raw_id'            => $r['id'],
            'type_key'          => 'staff_shift',
            'type_name'         => 'Shift',
            'icon_class'        => 'fas fa-user-clock',
            'staff_encoder_id'  => $r['staff_id'],
            'staff_name'        => $r['staff_name'],
            'manager_name'      => $r['manager_assigned_name'] ?? '—',
            'event_date'        => $r['shift_date'],
            'start_time'        => $r['start_time'],
            'end_time'          => $r['end_time'],
            'work_description'  => date('g:i A', strtotime($r['start_time'])).' — '.date('g:i A', strtotime($r['end_time'])),
            'status'            => strtolower($r['validation_status'] ?? 'pending'),
            'validation_status' => $r['validation_status'] ?? 'pending',
            'customer_name'     => '',
            'staff_color'       => '#0891b2',
            'auto_synced'       => true,
        ];
    }
} catch (Exception $e) {}

// Conflict Monitoring: Check for overlapping shifts
foreach ($week_events as $staff_id => &$dates) {
    foreach ($dates as $date => &$evs) {
        $shifts = array_filter($evs, function($e) { return $e['type_key'] === 'staff_shift'; });
        if (count($shifts) > 1) {
            // Check for overlaps
            usort($shifts, function($a, $b) { return strcmp($a['start_time'], $b['start_time']); });
            for ($i = 0; $i < count($shifts) - 1; $i++) {
                if ($shifts[$i]['end_time'] > $shifts[$i+1]['start_time']) {
                    // Mark conflict
                    foreach ($evs as &$ev) {
                        if ($ev['id'] === $shifts[$i]['id'] || $ev['id'] === $shifts[$i+1]['id']) {
                            $ev['has_conflict'] = true;
                        }
                    }
                }
            }
        }
    }
}
unset($dates, $evs, $ev);

// ── Staff list (encoders only) ────────────────────────────────────────────────
$staff_list = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, role FROM users WHERE station_id = ? AND status = 'active' AND role IN ('staff','cashier','pump_attendant') ORDER BY name");
    $stmt->execute([$station_id]);
    $staff_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$color_palette = ['#1e3a8a','#dc2626','#16a34a','#7c3aed','#d97706','#0891b2','#f97316','#6366f1','#84cc16','#ec4899'];
$staff_colors  = [];
foreach ($staff_list as $idx => $s) {
    $staff_colors[$s['id']] = $color_palette[$idx % count($color_palette)];
}

// ── Derive sidebar data from week_events (single source of truth) ─────────────
$today_events    = [];
$upcoming_events = [];
$weekly_stats    = ['pending_validations'=>0,'approved'=>0,'rejected'=>0,'adjusted'=>0,'total_events'=>0];
$three_days_ahead = date('Y-m-d', strtotime('+3 days'));

foreach ($week_events as $uid => $dates) {
    foreach ($dates as $date => $evs) {
        foreach ($evs as $ev) {
            $weekly_stats['total_events']++;
            $vs = strtolower($ev['validation_status'] ?? $ev['status'] ?? 'pending');
            if (in_array($vs, ['pending','pending validation'])) $weekly_stats['pending_validations']++;
            elseif ($vs === 'approved')  $weekly_stats['approved']++;
            elseif ($vs === 'rejected')  $weekly_stats['rejected']++;
            elseif ($vs === 'adjusted')  $weekly_stats['adjusted']++;

            if ($date === $today_str)                                  $today_events[]    = $ev;
            elseif ($date > $today_str && $date <= $three_days_ahead) $upcoming_events[] = $ev;
        }
    }
}

// ── Outstanding credit balances ───────────────────────────────────────────────
$outstanding_credits = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.name, ct.total_amount, ct.due_date,
               DATEDIFF(CURDATE(), ct.due_date) AS days_overdue
        FROM customers c
        JOIN credit_transactions ct ON c.id = ct.customer_id
        WHERE c.station_id = ? AND ct.validation_status = 'Approved'
          AND ct.status = 'unpaid' AND ct.due_date < CURDATE()
        ORDER BY ct.due_date ASC");
    $stmt->execute([$station_id]);
    $outstanding_credits = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>

<?php
// Helper functions for calendar styling
function cal_type_color($type_key) {
    $colors = [
        'job_order' => '#2563eb',
        'credit_txn' => '#dc2626', 
        'delivery' => '#16a34a',
        'staff_shift' => '#7c3aed',
        'fuel_calibration' => '#f59e0b',
        'maintenance' => '#0891b2'
    ];
    return $colors[$type_key] ?? '#6b7280';
}

function cal_status_badge($status) {
    $badges = [
        'pending' => '<span class="cal-badge badge-pending">Pending</span>',
        'approved' => '<span class="cal-badge badge-approved">Approved</span>',
        'completed' => '<span class="cal-badge badge-completed">Completed</span>',
        'cancelled' => '<span class="cal-badge badge-cancelled">Cancelled</span>',
        'rejected' => '<span class="cal-badge badge-rejected">Rejected</span>',
        'adjusted' => '<span class="cal-badge badge-adjusted">Adjusted</span>'
    ];
    return $badges[$status] ?? $badges['pending'];
}

require_once '../partials/header.php';
?>

<style>
/* Staff Calendar Styling - Same as staff_calendar.php */
.sc-wrap { display:flex; gap:20px; padding:20px; max-width:100%; }
.sc-main { flex:1; min-width:0; }
.sc-sidebar { width:300px; flex-shrink:0; display:flex; flex-direction:column; gap:16px; }

/* Header */
.sc-header { display:flex; justify-content:space-between; align-items:center; padding:16px 20px; background:#fff; border:1px solid #EAEAEA; margin-bottom:18px; border-radius:14px; box-shadow:0 1px 4px rgba(0,0,0,.04); }
.sc-header-left h2 { margin:0; font-size:18px; font-weight:800; color:#101828; display:flex; align-items:center; }
.sc-header-left p { margin:3px 0 0; color:#667085; font-size:12px; }
.sc-nav { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.sc-nav-btn { background:#f8fafc; border:1px solid #EAEAEA; color:#344054; padding:7px 14px; border-radius:8px; font-size:13px; cursor:pointer; transition:.2s; text-decoration:none; display:inline-flex; align-items:center; gap:5px; }
.sc-nav-btn:hover { background:#f0f4ff; border-color:#c7d7f5; color:#00264D; }
.sc-week-label { font-weight:700; font-size:14px; min-width:160px; text-align:center; color:#101828; }
.sc-today-btn { background:#00264D; color:#fff; border:none; padding:7px 16px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; }
.sc-today-btn:hover { background:#003d7a; color:#fff; }

/* Grid */
.sc-grid-wrap { background:#e9eaec; border-radius:14px; border:1px solid #d8dadf; overflow-x:auto; box-shadow:0 2px 12px rgba(0,0,0,.06); }
.sc-grid { display:grid; grid-template-columns:180px repeat(7,minmax(100px,1fr)); min-width:900px; }
.sc-col-head-label { background:#eef0f3; padding:10px 12px; border-bottom:2px solid #d8dadf; border-right:1px solid #d8dadf; font-size:11px; font-weight:700; color:#667085; text-transform:uppercase; letter-spacing:.5px; display:flex; align-items:center; }
.sc-col-head { background:#eef0f3; padding:10px 8px; text-align:center; border-bottom:2px solid #d8dadf; border-right:1px solid #d8dadf; }
.sc-col-head:last-child { border-right:none; }
.sc-col-head.today-col { background:#eef4ff; }
.day-name { font-size:11px; font-weight:700; color:#667085; text-transform:uppercase; letter-spacing:.5px; }
.day-num { font-size:18px; font-weight:800; color:#101828; line-height:1.2; }
.day-num.today { background:#00264D; color:#fff; width:32px; height:32px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:15px; }

/* Person cells — kept for reference but replaced by section cells */
.sc-person-cell { padding:10px 12px; background:#eef0f3; border-bottom:1px solid #d8dadf; border-right:1px solid #d8dadf; display:flex; align-items:center; gap:8px; }
.sc-person-avatar { width:32px; height:32px; border-radius:10px; color:#fff; font-weight:800; font-size:13px; display:grid; place-items:center; flex-shrink:0; }
.sc-person-name { font-weight:700; font-size:13px; color:#101828; line-height:1.2; }
.sc-person-role { font-size:11px; color:#667085; }

/* Section label cells (replaces per-staff rows) */
.sc-section-cell { padding:12px 10px; background:#fff; border-bottom:1px solid #d8dadf; border-right:1px solid #d8dadf; display:flex; align-items:center; gap:10px; min-height:60px; }
.sc-section-icon { width:34px; height:34px; border-radius:10px; display:grid; place-items:center; font-size:14px; flex-shrink:0; }
.sc-section-name { font-weight:800; font-size:12px; line-height:1.2; text-transform:uppercase; letter-spacing:.4px; }
.sc-section-sub  { font-size:10px; color:#667085; margin-top:2px; }
.sc-event-staff  { font-size:10px; color:#374151; margin-bottom:2px; display:flex; align-items:center; }

/* Day cells */
.sc-day-cell { padding:6px; background:#f5f6f8; border-bottom:1px solid #d8dadf; border-right:1px solid #d8dadf; min-height:80px; vertical-align:top; }
.sc-day-cell:last-child { border-right:none; }
.sc-day-cell.today-col { background:#eef4ff; }
.sc-off-label { font-size:11px; color:#9ca3af; text-align:center; padding-top:20px; }

/* Events */
.sc-event { padding:8px; border-radius:8px; margin-bottom:6px; border-left:4px solid; cursor:pointer; transition:all 0.2s; font-size:11px; }
.sc-event:hover { transform:translateX(2px); box-shadow:0 2px 4px rgba(0,0,0,0.1); }
.sc-event-type { font-weight:600; margin-bottom:2px; display:flex; align-items:center; gap:4px; }
.sc-event-desc { color:#374151; line-height:1.3; margin-bottom:2px; }
.sc-event-time { color:#6b7280; font-size:10px; margin-bottom:2px; }
.sc-event-mgr { color:#dc2626; font-size:10px; margin-bottom:2px; }

/* Badges */
.cal-badge { font-size:9px; font-weight:700; padding:2px 6px; border-radius:12px; text-transform:uppercase; }
.badge-pending { background:#fef3c7; color:#92400e; }
.badge-approved { background:#d1fae5; color:#065f46; }
.badge-completed { background:#dbeafe; color:#1e40af; }
.badge-cancelled { background:#f3f4f6; color:#374151; }
.badge-rejected { background:#fee2e2; color:#991b1b; }
.badge-adjusted { background:#e0e7ff; color:#3730a3; }

/* Sidebar cards */
.sc-card { background:#f5f6f8; border-radius:14px; border:1px solid #e4e6ea; padding:16px; margin-bottom:0; }
.sc-card-title { font-weight:600; color:#111827; margin:0 0 12px; display:flex; align-items:center; gap:6px; font-size:14px; }
.sc-today-item { display:flex; gap:8px; margin-bottom:8px; padding-bottom:8px; border-bottom:1px solid #f3f4f6; }
.sc-today-item:last-child { border-bottom:none; margin-bottom:0; padding-bottom:0; }
.sc-today-dot { width:8px; height:8px; border-radius:50%; margin-top:6px; flex-shrink:0; }
.sc-today-info { flex:1; min-width:0; }
.sc-today-type { font-weight:600; font-size:12px; color:#111827; margin-bottom:2px; }
.sc-today-staff, .sc-today-mgr, .sc-today-desc { font-size:11px; color:#6b7280; margin-bottom:1px; line-height:1.3; }

/* Status rows */
.sc-status-row { display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; }
.sc-status-row:last-child { margin-bottom:0; }
.sc-status-label { font-size:12px; color:#374151; display:flex; align-items:center; gap:6px; }
.sc-status-count { font-weight:600; font-size:13px; color:#111827; }

/* Buttons */
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

/* Modal styling */
.sc-modal-overlay { position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); display:none; align-items:center; justify-content:center; z-index:9999; }
.sc-modal-overlay.open { display:flex; }
.sc-modal { background:#fff; border-radius:16px; width:min(520px,94vw); max-height:88vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,.2); }
.sc-modal-body { padding:20px 24px; }
.sc-detail-row { display:flex; gap:12px; padding:8px 0; border-bottom:1px solid #f0f0f0; align-items:flex-start; }
.sc-detail-row:last-child { border-bottom:none; }
.sc-detail-label { font-size:12px; font-weight:700; color:#667085; width:110px; flex-shrink:0; padding-top:1px; }
.sc-detail-val { font-size:13px; color:#101828; flex:1; }

/* Manager action buttons */
.sc-manager-actions { display:flex; gap:8px; margin-top:12px; flex-wrap:wrap; }
.sc-approve-btn { background:#16a34a; color:#fff; border:none; padding:6px 12px; border-radius:6px; font-size:11px; font-weight:600; cursor:pointer; }
.sc-reject-btn { background:#dc2626; color:#fff; border:none; padding:6px 12px; border-radius:6px; font-size:11px; font-weight:600; cursor:pointer; }
.sc-adjust-btn { background:#2563eb; color:#fff; border:none; padding:6px 12px; border-radius:6px; font-size:11px; font-weight:600; cursor:pointer; }

@media(max-width:900px){
  .sc-wrap { flex-direction:column; }
  .sc-sidebar { width:100%; flex:none; }
  .sc-grid { grid-template-columns:120px repeat(7,minmax(80px,1fr)); }
}

/* sc-wrap handles its own padding; grid scroll is handled by .sc-grid-wrap */
.sc-wrap { padding-bottom: 60px; }
</style>

<div class="sc-wrap" style="width: 100%; box-sizing: border-box;">
  <!-- ===== MAIN CALENDAR AREA ===== -->
  <div class="sc-main">

    <?php if(isset($_SESSION['success']) || isset($_SESSION['error'])): ?>
    <div class="sc-alert <?php echo isset($_SESSION['error']) ? 'error' : 'success'; ?>">
      <i class="fas fa-<?php echo isset($_SESSION['error']) ? 'exclamation-circle' : 'check-circle'; ?>"></i>
      <?php echo htmlspecialchars($_SESSION['success'] ?? $_SESSION['error'] ?? ''); ?>
      <?php unset($_SESSION['success'], $_SESSION['error']); ?>
    </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="sc-header">
      <div class="sc-header-left">
        <h2><i class="fas fa-calendar-alt" style="margin-right:8px"></i>Manager Calendar</h2>
        <p>Job Orders, Credit Transactions, Deliveries, Staff Shifts</p>
      </div>
      <div class="sc-nav">
        <a href="manager_calendar.php?week=<?php echo $prev_week; ?>" class="sc-nav-btn">
          <i class="fas fa-chevron-left"></i>
        </a>
        <span class="sc-week-label"><?php echo htmlspecialchars($week_label); ?></span>
        <a href="manager_calendar.php?week=<?php echo $next_week; ?>" class="sc-nav-btn">
          <i class="fas fa-chevron-right"></i>
        </a>
        <a href="manager_calendar.php?week=0" class="sc-today-btn">Today</a>
      </div>
    </div>

    <!-- Weekly Grid — 4 section rows: Job Orders, Credit/Utang, Deliveries, Staff Shifts -->
    <div class="sc-grid-wrap">
      <div class="sc-grid">

        <!-- Column headers -->
        <div class="sc-col-head-label">Category</div>
        <?php
        $day_names = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
        foreach($week_dates as $i => $date):
          $is_today = ($date->format('Y-m-d') === $today->format('Y-m-d'));
        ?>
        <div class="sc-col-head <?php echo $is_today?'today-col':''; ?>">
          <div class="day-name"><?php echo $day_names[$i]; ?></div>
          <div class="day-num <?php echo $is_today?'today':''; ?>"><?php echo $date->format('j'); ?></div>
        </div>
        <?php endforeach; ?>

        <?php
        // ── Build flat event lists per type_key per date ──────────────────────
        $by_type = ['job_order'=>[], 'credit_txn'=>[], 'delivery'=>[], 'staff_shift'=>[]];
        foreach ($week_events as $uid => $dates) {
            foreach ($dates as $date_key => $evs) {
                foreach ($evs as $ev) {
                    $tk = $ev['type_key'] ?? '';
                    if (isset($by_type[$tk])) {
                        $by_type[$tk][$date_key][] = $ev;
                    }
                }
            }
        }

        // Section definitions
        $sections = [
            [
                'key'   => 'job_order',
                'label' => 'Job Orders',
                'icon'  => 'fas fa-wrench',
                'color' => '#2563eb',
                'desc'  => 'Pending validation',
            ],
            [
                'key'   => 'credit_txn',
                'label' => 'Credit / Utang',
                'icon'  => 'fas fa-credit-card',
                'color' => '#7c3aed',
                'desc'  => 'Due dates & balances',
            ],
            [
                'key'   => 'delivery',
                'label' => 'Deliveries',
                'icon'  => 'fas fa-truck',
                'color' => '#16a34a',
                'desc'  => 'Incoming / outgoing',
            ],
            [
                'key'   => 'staff_shift',
                'label' => 'Staff Shifts',
                'icon'  => 'fas fa-user-clock',
                'color' => '#0891b2',
                'desc'  => 'Duty & attendance',
            ],
        ];

        foreach ($sections as $section):
            $tk = $section['key'];
            $sc = $section['color'];
        ?>

        <!-- Section label cell -->
        <div class="sc-section-cell" style="border-left:4px solid <?php echo $sc; ?>">
          <div class="sc-section-icon" style="background:<?php echo $sc; ?>18;color:<?php echo $sc; ?>">
            <i class="<?php echo $section['icon']; ?>"></i>
          </div>
          <div>
            <div class="sc-section-name" style="color:<?php echo $sc; ?>"><?php echo $section['label']; ?></div>
            <div class="sc-section-sub"><?php echo $section['desc']; ?></div>
          </div>
        </div>

        <!-- Day cells for this section -->
        <?php foreach($week_dates as $i => $date):
          $is_today = ($date->format('Y-m-d') === $today->format('Y-m-d'));
          $date_str  = $date->format('Y-m-d');
          $cell_events = $by_type[$tk][$date_str] ?? [];
          $cell_cls = 'sc-day-cell' . ($is_today ? ' today-col' : '');
        ?>
        <div class="<?php echo $cell_cls; ?>">
          <?php if (empty($cell_events)): ?>
            <div class="sc-off-label">—</div>
          <?php else: ?>
            <?php foreach ($cell_events as $ev):
              $tc      = $sc;
              $st      = strtolower($ev['status'] ?? 'pending');
              $bg      = $tc . '18';
              $synced  = !empty($ev['auto_synced']);
              $time_str = ($ev['start_time'] !== '00:00' || $ev['end_time'] !== '00:00')
                ? date('g:ia', strtotime($ev['start_time'])).' – '.date('g:ia', strtotime($ev['end_time']))
                : '';
              $staff_c  = $staff_colors[$ev['staff_encoder_id'] ?? 0] ?? '#6b7280';
              $ev_js    = htmlspecialchars(json_encode($ev));
            ?>
            <div class="sc-event" style="background:<?php echo $bg; ?>;border-left-color:<?php echo $tc; ?>"
                 onclick='openDetailModal(<?php echo $ev_js; ?>)'>
              <div class="sc-event-type" style="color:<?php echo $tc; ?>">
                <i class="<?php echo htmlspecialchars($ev['icon_class'] ?? $section['icon']); ?>"></i>
                <?php echo htmlspecialchars($ev['type_name']); ?>
                <?php if ($synced): ?><span class="sc-synced"><i class="fas fa-sync-alt"></i></span><?php endif; ?>
              </div>
              <div class="sc-event-desc"><?php echo htmlspecialchars($ev['work_description']); ?></div>
              <?php if ($time_str): ?><div class="sc-event-time"><?php echo $time_str; ?></div><?php endif; ?>
              <div class="sc-event-staff">
                <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:<?php echo $staff_c; ?>;margin-right:3px;"></span>
                <?php echo htmlspecialchars($ev['staff_name'] ?? '—'); ?>
              </div>
              <?php if (!empty($ev['manager_name']) && $ev['manager_name'] !== '—'): ?>
              <div class="sc-event-mgr">
                <i class="fas fa-user-tie" style="color:#dc2626;font-size:9px"></i>
                <?php echo htmlspecialchars($ev['manager_name']); ?>
              </div>
              <?php endif; ?>
              <?php if(!empty($ev['has_conflict'])): ?>
              <div style="color:#b91c1c;font-size:10px;font-weight:700;margin-top:4px;"><i class="fas fa-exclamation-triangle"></i> Conflict!</div>
              <?php endif; ?>
              <?php echo cal_status_badge($st); ?>
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
          $tc = cal_type_color($ev['type_key'] ?? 'job_order');
        ?>
        <div class="sc-today-item">
          <div class="sc-today-dot" style="background:<?php echo $tc; ?>"></div>
          <div class="sc-today-info">
            <div class="sc-today-type"><?php echo htmlspecialchars($ev['type_name'] ?? 'Event'); ?></div>
            <div class="sc-today-desc"><?php echo htmlspecialchars($ev['work_description'] ?? $ev['customer_name'] ?? ''); ?></div>
            <?php echo cal_status_badge(strtolower($ev['validation_status'] ?? $ev['status'] ?? 'pending')); ?>
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
        <span class="sc-status-count"><?php echo $weekly_stats['pending_validations']; ?></span>
      </div>
      <div class="sc-status-row">
        <span class="sc-status-label"><span style="width:10px;height:10px;border-radius:50%;background:#065F46;display:inline-block"></span> Approved</span>
        <span class="sc-status-count"><?php echo $weekly_stats['approved']; ?></span>
      </div>
      <div class="sc-status-row">
        <span class="sc-status-label"><span style="width:10px;height:10px;border-radius:50%;background:#1E40AF;display:inline-block"></span> Adjusted</span>
        <span class="sc-status-count"><?php echo $weekly_stats['adjusted']; ?></span>
      </div>
      <div class="sc-status-row">
        <span class="sc-status-label"><span style="width:10px;height:10px;border-radius:50%;background:#991B1B;display:inline-block"></span> Rejected</span>
        <span class="sc-status-count"><?php echo $weekly_stats['rejected']; ?></span>
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
          $tc = cal_type_color($ev['type_key'] ?? 'job_order');
        ?>
        <div class="sc-today-item">
          <div class="sc-today-dot" style="background:<?php echo $tc; ?>"></div>
          <div class="sc-today-info">
            <div class="sc-today-type"><?php echo htmlspecialchars($ev['type_name'] ?? 'Event'); ?></div>
            <div class="sc-today-desc"><?php echo htmlspecialchars(substr($ev['work_description'] ?? $ev['customer_name'] ?? '', 0, 40)); ?></div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if(count($upcoming_events)>5): ?>
          <p style="font-size:11px;color:#667085;text-align:center;margin-top:8px">+<?php echo count($upcoming_events)-5; ?> more</p>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <!-- Staff Color Code Legend -->
    <div class="sc-card">
      <p class="sc-card-title"><i class="fas fa-palette"></i> Staff Color Code</p>
      <div style="display:grid; gap:8px;">
        <?php foreach($staff_list as $staff): ?>
          <?php if(isset($staff_colors[$staff['id']])): ?>
          <div style="display:flex; align-items:center; gap:8px;">
            <div style="width:12px;height:12px;border-radius:50%;background:<?php echo htmlspecialchars($staff_colors[$staff['id']]); ?>"></div>
            <span style="font-size:12px;color:#374151;"><?php echo htmlspecialchars($staff['name']); ?></span>
          </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Outstanding Credit Alerts -->
    <?php if (!empty($outstanding_credits)): ?>
    <div class="sc-card">
      <p class="sc-card-title"><i class="fas fa-exclamation-triangle" style="color:#dc2626;"></i> Outstanding Credits</p>
      <?php foreach (array_slice($outstanding_credits, 0, 3) as $credit): ?>
      <div style="padding:8px;background:#fee2e2;border-radius:6px;margin-bottom:8px;">
        <div style="font-weight:600;font-size:12px;color:#991b1b;"><?php echo htmlspecialchars($credit['name']); ?></div>
        <div style="font-size:11px;color:#991b1b;">₱<?php echo number_format($credit['total_amount'], 2); ?> - <?php echo abs($credit['days_overdue']); ?> days overdue</div>
      </div>
      <?php endforeach; ?>
      <?php if (count($outstanding_credits) > 3): ?>
        <p style="font-size:11px;color:#667085;text-align:center;margin-top:8px">And <?php echo count($outstanding_credits) - 3; ?> more...</p>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  </div><!-- .sc-sidebar -->
</div><!-- .sc-wrap -->

<!-- ===== DETAIL MODAL ===== -->
<div class="sc-modal-overlay" id="detailModal">
  <div class="sc-modal" style="border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.2);">
    <div class="sc-modal-header" style="background:linear-gradient(135deg,#00264D,#003d7a);color:#fff;padding:20px 24px;border-radius:16px 16px 0 0;display:flex;align-items:center;justify-content:space-between;">
      <h3 class="sc-modal-title" id="modalTitle" style="margin:0;font-size:17px;font-weight:800;color:#fff;"></h3>
      <button class="sc-modal-close" onclick="closeDetailModal()" style="background:none;border:none;color:#fff;font-size:24px;cursor:pointer;line-height:1;">&times;</button>
    </div>
    <div class="sc-modal-body" id="modalBody" style="padding:20px 24px;">
    </div>
  </div>
</div>

<script>
// ── Modal ──────────────────────────────────────────────────────────────────────
function openDetailModal(ev) {
  const statusMap = {
    pending:'badge-pending', approved:'badge-approved', completed:'badge-completed',
    cancelled:'badge-cancelled', rejected:'badge-rejected', adjusted:'badge-adjusted'
  };
  const st  = (ev.status || 'pending').toLowerCase();
  const vs  = (ev.validation_status || ev.status || 'pending').toLowerCase();
  const badge = `<span class="cal-badge ${statusMap[st]||'badge-pending'}">${st.charAt(0).toUpperCase()+st.slice(1)}</span>`;

  // Determine event type and raw numeric ID
  let eventType = null;
  let eventId   = ev.raw_id || String(ev.id).replace(/^\D+/, '');

  if (String(ev.id).startsWith('jo_'))    eventType = 'job_order';
  else if (String(ev.id).startsWith('ct_'))  eventType = 'credit_transaction';
  else if (String(ev.id).startsWith('del_')) eventType = 'delivery';
  else if (String(ev.id).startsWith('shift_')) eventType = 'staff_shift';

  // Manager action buttons — only for pending validatable events
  let actionsHtml = '';
  const canValidate = eventType && ['pending','pending validation'].includes(vs);
  if (canValidate) {
    const adjustBtn = (eventType === 'job_order' || eventType === 'delivery' || eventType === 'staff_shift')
      ? `<button class="sc-adjust-btn" onclick="submitValidation('${eventType}',${eventId},'Adjusted')"><i class="fas fa-edit"></i> Adjust</button>`
      : '';
    actionsHtml = `
      <div style="margin-top:16px;padding-top:16px;border-top:1px solid #f0f0f0;">
        <p style="font-size:12px;font-weight:700;color:#344054;margin:0 0 10px;text-transform:uppercase;letter-spacing:.4px;">Manager Actions</p>
        <div class="sc-manager-actions">
          <button class="sc-approve-btn" onclick="submitValidation('${eventType}',${eventId},'Approved')"><i class="fas fa-check"></i> Approve</button>
          <button class="sc-reject-btn"  onclick="submitValidation('${eventType}',${eventId},'Rejected')"><i class="fas fa-times"></i> Reject</button>
          ${adjustBtn}
        </div>
      </div>`;
  }

  // Format date nicely
  const evDate = ev.event_date
    ? new Date(ev.event_date + 'T00:00:00').toLocaleDateString('en-US',{weekday:'short',year:'numeric',month:'short',day:'numeric'})
    : '—';

  // Type label with color dot
  const typeColors = {job_order:'#2563eb',credit_txn:'#7c3aed',delivery:'#16a34a',staff_shift:'#0891b2'};
  const tc = typeColors[ev.type_key] || '#6b7280';

  let html = `
    <div class="sc-detail-row"><span class="sc-detail-label">Date</span><span class="sc-detail-val">${evDate}</span></div>
    <div class="sc-detail-row">
      <span class="sc-detail-label">Type</span>
      <span class="sc-detail-val"><span style="display:inline-flex;align-items:center;gap:5px;"><span style="width:8px;height:8px;border-radius:50%;background:${tc};display:inline-block;"></span>${ev.type_name||'—'}</span></span>
    </div>
    <div class="sc-detail-row"><span class="sc-detail-label">Description</span><span class="sc-detail-val">${ev.work_description||'—'}</span></div>`;

  if (ev.has_conflict) {
    html += `<div class="sc-detail-row" style="background:#fee2e2;padding:8px;border-radius:6px;"><span class="sc-detail-label" style="color:#991b1b;"><i class="fas fa-exclamation-triangle"></i> Alert</span><span class="sc-detail-val" style="color:#991b1b;font-weight:600;">This shift conflicts with another assigned shift for this staff member!</span></div>`;
  }

  if (ev.staff_name) {
    html += `<div class="sc-detail-row"><span class="sc-detail-label">Staff</span><span class="sc-detail-val"><i class="fas fa-user" style="color:#2563eb;font-size:10px;margin-right:4px;"></i>${ev.staff_name}</span></div>`;
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
  html += actionsHtml;

  document.getElementById('modalTitle').innerHTML = `<i class="${ev.icon_class||'fas fa-calendar-check'}" style="margin-right:8px;"></i>${ev.type_name||'Event Details'}`;
  document.getElementById('modalBody').innerHTML = html;
  document.getElementById('detailModal').classList.add('open');
}

function closeDetailModal() {
  document.getElementById('detailModal').classList.remove('open');
}

// Close on overlay click
document.getElementById('detailModal').addEventListener('click', function(e) {
  if (e.target === this) closeDetailModal();
});

// ── Validation submit ──────────────────────────────────────────────────────────
function submitValidation(eventType, eventId, status) {
  const notes = prompt(`Notes for "${status}" (optional, press OK to continue):`);
  if (notes === null) return; // cancelled

  // Map eventType to POST action name and ID field name
  const actionMap = {
    job_order:            { action: 'validate_job_order',         idField: 'job_order_id' },
    credit_transaction:   { action: 'validate_credit_transaction', idField: 'transaction_id' },
    delivery:             { action: 'update_delivery_status',      idField: 'delivery_id' },
    staff_shift:          { action: 'validate_staff_shift',        idField: 'shift_id' },
  };
  const cfg = actionMap[eventType];
  if (!cfg) return;

  const form = document.createElement('form');
  form.method = 'POST';
  form.action = 'manager_calendar.php?week=<?php echo (int)$week_offset; ?>';

  [['action', cfg.action], [cfg.idField, eventId], ['status', status], ['notes', notes || '']].forEach(([n,v]) => {
    const inp = document.createElement('input');
    inp.type = 'hidden'; inp.name = n; inp.value = v;
    form.appendChild(inp);
  });

  document.body.appendChild(form);
  form.submit();
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>

