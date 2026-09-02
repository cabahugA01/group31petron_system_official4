<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'calendar';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/calendar_module_helpers.php';
require_login();
calendar_ensure_schema($pdo);

$me = current_user();
$rk = role_key($me['role'] ?? '');
if (!in_array($rk, ['staff','manager','admin','superadmin'])) { header('Location: dashboard.php'); exit; }
$station_id = user_station_id();
if (!$station_id) { die('Error: Not assigned to a station.'); }
$user_id = $me['id'];

// Handle AJAX requests
if (isset($_POST['action']) && $_POST['action'] === 'save_event') {
    header('Content-Type: application/json');
    
    try {
        $event_id = $_POST['event_id'] ?? '';
        $event_date = calendar_normalize_date($_POST['event_date'] ?? '');
        $event_type = calendar_normalize_event_type($_POST['event_type'] ?? '');
        $work_description = calendar_clean_text($_POST['work_description'] ?? '');
        $start_time = calendar_normalize_time($_POST['start_time'] ?? '');
        $end_time = calendar_normalize_time($_POST['end_time'] ?? '', $start_time);
        $status = calendar_normalize_status($_POST['status'] ?? 'pending');

        if ($event_date === '' || $event_type === '' || $work_description === '') {
            echo json_encode(['success' => false, 'message' => 'Please complete the date, type, and description.']);
            exit;
        }

        if ($start_time !== '00:00:00' && $end_time !== '00:00:00' && $end_time < $start_time) {
            echo json_encode(['success' => false, 'message' => 'End time must be later than start time.']);
            exit;
        }
        
        // Collect all dynamic fields into metadata JSON
        $metadata = [];
        
        // Event type specific fields
        switch($event_type) {
            case 'staff_shift':
                $metadata['shift_type'] = $_POST['shift_type'] ?? '';
                $metadata['shift_status'] = $_POST['shift_status'] ?? '';
                break;
                
            case 'job_order':
                $metadata['service_type'] = $_POST['service_type'] ?? '';
                $metadata['customer_name'] = $_POST['customer_name'] ?? '';
                $metadata['job_status'] = $_POST['job_status'] ?? '';
                break;
                
            case 'fuel_delivery':
            case 'merchandise_delivery':
                $metadata['supplier'] = $_POST['supplier'] ?? '';
                $metadata['product'] = $_POST['product'] ?? '';
                $metadata['expected_qty'] = $_POST['expected_qty'] ?? 0;
                $metadata['actual_qty'] = $_POST['actual_qty'] ?? 0;
                $metadata['variance_qty'] = floatval($_POST['actual_qty'] ?? 0) - floatval($_POST['expected_qty'] ?? 0);
                break;
                
            case 'fuel_calibration':
            case 'meter_reading':
                $metadata['pump_number'] = $_POST['pump_number'] ?? '';
                $metadata['expected_reading'] = $_POST['expected_reading'] ?? 0;
                $metadata['actual_reading'] = $_POST['actual_reading'] ?? 0;
                $expected = floatval($_POST['expected_reading'] ?? 0);
                $actual = floatval($_POST['actual_reading'] ?? 0);
                $variance = $actual - $expected;
                $metadata['variance'] = $variance;
                $metadata['variance_percent'] = $expected > 0 ? ($variance / $expected) * 100 : 0;
                break;
                
            case 'customer_transaction':
            case 'payment_collection':
                $metadata['customer_id'] = $_POST['customer_id'] ?? '';
                $metadata['amount'] = $_POST['amount'] ?? 0;
                $metadata['payment_status'] = $_POST['payment_status'] ?? 'unpaid';
                break;
        }
        
        $metadata_json = json_encode($metadata);
        
        // Check for schedule conflicts before saving
        if (calendar_has_time_range($start_time, $end_time) && $status !== 'cancelled' && empty($_POST['force_save'])) {
            $conflict_check = $pdo->prepare("SELECT COUNT(*) FROM staff_calendar_events 
                WHERE staff_encoder_id = ? 
                AND event_date = ? 
                AND start_time IS NOT NULL 
                AND end_time IS NOT NULL
                AND status != 'cancelled'
                AND id != ?
                AND (
                    (start_time < ? AND end_time > ?)
                    OR (start_time < ? AND end_time > ?)
                    OR (start_time >= ? AND end_time <= ?)
                )");
            $conflict_check->execute([
                $user_id, 
                $event_date, 
                $event_id ?: 0,
                $end_time, $start_time,  // Check if new event overlaps existing
                $end_time, $start_time,  // Check if new event overlaps existing
                $start_time, $end_time   // Check if new event contains existing
            ]);
            
            $conflict_count = $conflict_check->fetchColumn();
            if ($conflict_count > 0) {
                echo json_encode([
                    'success' => false, 
                    'message' => 'Schedule conflict detected! You have overlapping events on this date.',
                    'conflict' => true
                ]);
                exit;
            }
        }
        
        // Get or create event_type_id
        $event_type_id = calendar_event_type_id($pdo, $event_type);
        
        // Check if metadata column exists, if not add it
        try {
            $pdo->query("SELECT metadata FROM staff_calendar_events LIMIT 1");
        } catch (Exception $e) {
            // Add metadata column if it doesn't exist
            try {
                $pdo->exec("ALTER TABLE staff_calendar_events ADD COLUMN metadata TEXT NULL");
            } catch (Exception $e2) {}
        }
        
        if ($event_id) {
            // Update existing event
            $update_stmt = $pdo->prepare("UPDATE staff_calendar_events SET 
                event_date = ?, event_type_id = ?, work_description = ?, 
                start_time = ?, end_time = ?, status = ?, metadata = ?
                WHERE id = ? AND staff_encoder_id = ?");
            $update_stmt->execute([
                $event_date, $event_type_id, $work_description, 
                $start_time, $end_time, $status, $metadata_json,
                $event_id, $user_id
            ]);
            
            echo json_encode(['success' => true, 'message' => 'Event updated successfully']);
        } else {
            // Create new event
            $insert_stmt = $pdo->prepare("INSERT INTO staff_calendar_events 
                (station_id, staff_encoder_id, event_type_id, event_date, work_description, 
                start_time, end_time, status, metadata, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $insert_stmt->execute([
                $station_id, $user_id, $event_type_id, $event_date, $work_description, 
                $start_time, $end_time, $status, $metadata_json
            ]);
            
            echo json_encode(['success' => true, 'message' => 'Event created successfully']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'validate_event') {
    header('Content-Type: application/json');
    $evt_type = $_POST['evt_type'] ?? '';
    $numeric_id = $_POST['numeric_id'] ?? '';
    $sub_action = $_POST['sub_action'] ?? '';
    
    try {
        // Handle reschedule for all types
        if ($sub_action === 'reschedule') {
            $new_date = $_POST['new_date'] ?? '';
            if (!$new_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_date)) {
                echo json_encode(['success' => false, 'message' => 'Invalid date provided.']);
                exit;
            }
            if ($evt_type === 'job_order') {
                $st = $pdo->prepare("UPDATE job_orders SET created_at = CONCAT(?, ' ', TIME(created_at)) WHERE id = ?");
                $st->execute([$new_date, $numeric_id]);
                echo json_encode(['success' => true, 'message' => 'Job Order rescheduled to ' . $new_date]);
            } elseif ($evt_type === 'staff_shift') {
                $st = $pdo->prepare("UPDATE staff_schedules SET scheduled_date = ? WHERE id = ?");
                $st->execute([$new_date, $numeric_id]);
                echo json_encode(['success' => true, 'message' => 'Shift rescheduled to ' . $new_date]);
            } elseif (in_array($evt_type, ['merchandise_delivery', 'fuel_delivery', 'delivery'])) {
                $st = $pdo->prepare("UPDATE deliveries_oversight SET delivery_date = ? WHERE id = ?");
                $st->execute([$new_date, $numeric_id]);
                echo json_encode(['success' => true, 'message' => 'Delivery rescheduled to ' . $new_date]);
            } else {
                $st = $pdo->prepare("UPDATE staff_calendar_events SET event_date = ? WHERE id = ?");
                $st->execute([$new_date, $numeric_id]);
                echo json_encode(['success' => true, 'message' => 'Event rescheduled to ' . $new_date]);
            }
            exit;
        }

        if ($evt_type === 'job_order') {
            if ($sub_action === 'approve') {
                $st = $pdo->prepare("UPDATE job_orders SET status = 'Verified', validated_by = ?, validated_at = NOW() WHERE id = ?");
                $st->execute([$user_id, $numeric_id]);
                echo json_encode(['success' => true, 'message' => 'Job Order approved/verified successfully']);
            } elseif ($sub_action === 'reject') {
                $reason = $_POST['reason'] ?? '';
                $st = $pdo->prepare("UPDATE job_orders SET status = 'Rejected', validated_by = ?, validated_at = NOW(), rejection_reason = ? WHERE id = ?");
                $st->execute([$user_id, $reason, $numeric_id]);
                echo json_encode(['success' => true, 'message' => 'Job Order rejected successfully']);
            } elseif ($sub_action === 'return') {
                $reason = $_POST['reason'] ?? '';
                $st = $pdo->prepare("UPDATE job_orders SET status = 'Pending', validation_status = 'returned', rejection_reason = ? WHERE id = ?");
                $st->execute([$reason, $numeric_id]);
                echo json_encode(['success' => true, 'message' => 'Job Order returned for review']);
            } elseif ($sub_action === 'reassign') {
                $new_staff = $_POST['new_staff_id'] ?? '';
                $st = $pdo->prepare("UPDATE job_orders SET assigned_mechanic_id = ? WHERE id = ?");
                $st->execute([$new_staff, $numeric_id]);
                echo json_encode(['success' => true, 'message' => 'Job Order mechanic re-assigned successfully']);
            }
            exit;
        } elseif ($evt_type === 'delivery') {
            if ($sub_action === 'approve') {
                $st = $pdo->prepare("UPDATE deliveries_oversight SET status = 'Approved', manager_id = ?, manager_action_at = NOW() WHERE id = ?");
                $st->execute([$user_id, $numeric_id]);
                echo json_encode(['success' => true, 'message' => 'Delivery approved successfully']);
            } elseif ($sub_action === 'reject') {
                $reason = $_POST['reason'] ?? '';
                $st = $pdo->prepare("UPDATE deliveries_oversight SET status = 'Rejected', manager_id = ?, manager_action_at = NOW(), manager_notes = ? WHERE id = ?");
                $st->execute([$user_id, $reason, $numeric_id]);
                echo json_encode(['success' => true, 'message' => 'Delivery rejected successfully']);
            }
            exit;
        } elseif ($evt_type === 'fuel_calibration') {
            if ($sub_action === 'validate') {
                // If it's a manual calibration event
                $st = $pdo->prepare("UPDATE staff_calendar_events SET status = 'approved', manager_assigned_id = ?, updated_at = NOW() WHERE id = ?");
                $st->execute([$user_id, $numeric_id]);
                echo json_encode(['success' => true, 'message' => 'Calibration validated successfully']);
            }
            exit;
        } elseif ($evt_type === 'staff_shift') {
            if ($sub_action === 'reassign') {
                $new_staff = $_POST['new_staff_id'] ?? '';
                $st = $pdo->prepare("UPDATE staff_schedules SET user_id = ? WHERE id = ?");
                $st->execute([$new_staff, $numeric_id]);
                echo json_encode(['success' => true, 'message' => 'Shift staff re-assigned successfully']);
            }
            exit;
        }
        echo json_encode(['success' => false, 'message' => 'Unknown event type or action']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'get_event') {
    header('Content-Type: application/json');
    
    try {
        $event_id = $_GET['event_id'] ?? '';
        
        $stmt = $pdo->prepare("SELECT sce.*, et.type_key 
            FROM staff_calendar_events sce
            JOIN staff_event_types et ON sce.event_type_id = et.id
            WHERE sce.id = ? AND sce.staff_encoder_id = ?");
        $stmt->execute([$event_id, $user_id]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($event) {
            // Decode metadata JSON
            if (!empty($event['metadata'])) {
                $metadata = json_decode($event['metadata'], true);
                $event = array_merge($event, $metadata ?: []);
            }
            
            echo json_encode(['success' => true, 'event' => $event]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Event not found']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Get summary stats for panels
$summary_stats = [
    'today_events' => 0,
    'today_shifts' => 0,
    'today_deliveries' => 0,
    'today_job_orders' => 0,
    'week_pending' => 0,
    'week_in_progress' => 0,
    'week_completed' => 0,
    'upcoming_count' => 0,
    'conflicts' => [],
    'pending_validations' => 0,
    'overdue_payments' => 0,
    'low_stock_items' => 0,
    'staff_workload' => [],
    'tomorrow_deliveries' => [],
    'pending_job_orders' => [],
    'upcoming_pms' => [],
    'today_deliveries_list' => [],
    'all_mechanics' => []
];

try {
    $today_date = date('Y-m-d');
    $tomorrow_date = date('Y-m-d', strtotime('+1 day'));
    $week_start = date('Y-m-d', strtotime('monday this week'));
    $week_end = date('Y-m-d', strtotime('sunday this week'));
    $upcoming_end = date('Y-m-d', strtotime('+3 days'));
    
    // Today's events count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM staff_calendar_events WHERE station_id = ? AND event_date = ?");
    $stmt->execute([$station_id, $today_date]);
    $summary_stats['today_events'] = (int)$stmt->fetchColumn();
    
    // Today's shifts
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM staff_schedules ss 
        JOIN users u ON ss.user_id = u.id 
        WHERE ss.scheduled_date = ? AND u.station_id = ?");
    $stmt->execute([$today_date, $station_id]);
    $summary_stats['today_shifts'] = (int)$stmt->fetchColumn();
    
    // Today's deliveries count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM deliveries_oversight WHERE station_id = ? AND DATE(delivery_date) = ?");
    $stmt->execute([$station_id, $today_date]);
    $summary_stats['today_deliveries'] = (int)$stmt->fetchColumn();
    
    // Today's job orders count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id = ? AND DATE(created_at) = ?");
    $stmt->execute([$station_id, $today_date]);
    $summary_stats['today_job_orders'] = (int)$stmt->fetchColumn();
    
    // Today's deliveries list
    try {
        $stmt = $pdo->prepare("SELECT d.id, d.supplier, d.product, d.status, DATE(d.delivery_date) AS del_date, u.name AS staff_name
            FROM deliveries_oversight d JOIN users u ON d.encoded_by = u.id
            WHERE d.station_id = ? AND DATE(d.delivery_date) = ? ORDER BY d.id DESC LIMIT 5");
        $stmt->execute([$station_id, $today_date]);
        $summary_stats['today_deliveries_list'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    // Tomorrow's deliveries list
    try {
        $stmt = $pdo->prepare("SELECT d.id, d.supplier, d.product, d.status, DATE(d.delivery_date) AS del_date, u.name AS staff_name
            FROM deliveries_oversight d JOIN users u ON d.encoded_by = u.id
            WHERE d.station_id = ? AND DATE(d.delivery_date) = ? ORDER BY d.id DESC LIMIT 5");
        $stmt->execute([$station_id, $tomorrow_date]);
        $summary_stats['tomorrow_deliveries'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Also check fuel deliveries for tomorrow
        $stmt2 = $pdo->prepare("SELECT id, fuel_type AS product, supplier, delivery_date, status
            FROM fuel_deliveries WHERE station_id = ? AND DATE(delivery_date) = ? LIMIT 3");
        $stmt2->execute([$station_id, $tomorrow_date]);
        foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $fd) {
            $fd['supplier'] = $fd['supplier'] ?? 'Petron';
            $summary_stats['tomorrow_deliveries'][] = $fd;
        }
    } catch (Exception $e) {}
    
    // Pending Job Orders list
    try {
        $stmt = $pdo->prepare("SELECT jo.id, jo.service_type, jo.customer_name, jo.status, 
            jo.plate_number, u.name AS staff_name, DATE(jo.created_at) AS jo_date
            FROM job_orders jo JOIN users u ON jo.created_by = u.id
            WHERE jo.station_id = ? AND jo.status IN ('Pending','Reviewed','In Progress')
            ORDER BY jo.created_at DESC LIMIT 5");
        $stmt->execute([$station_id]);
        $summary_stats['pending_job_orders'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
    
    // Upcoming PMS (Preventive Maintenance) - from job_orders with service_type LIKE PMS
    try {
        $stmt = $pdo->prepare("SELECT jo.id, jo.service_type, jo.customer_name, jo.plate_number, 
            jo.status, DATE(jo.created_at) AS jo_date, u.name AS staff_name
            FROM job_orders jo JOIN users u ON jo.created_by = u.id
            WHERE jo.station_id = ? AND (LOWER(jo.service_type) LIKE '%pms%' OR LOWER(jo.service_type) LIKE '%preventive%' OR LOWER(jo.service_type) LIKE '%maintenance%')
            AND DATE(jo.created_at) >= ?
            ORDER BY jo.created_at ASC LIMIT 5");
        $stmt->execute([$station_id, $today_date]);
        $summary_stats['upcoming_pms'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
    
    // All mechanics for assign/filter
    try {
        $stmt = $pdo->prepare("SELECT id, name FROM users WHERE station_id = ? AND role IN ('staff','cashier','pump_attendant','mechanic') AND status = 'Active' ORDER BY name");
        $stmt->execute([$station_id]);
        $summary_stats['all_mechanics'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
    
    // Week status counts (combining all sources)
    $stmt = $pdo->prepare("SELECT status FROM staff_calendar_events WHERE station_id = ? AND event_date BETWEEN ? AND ?");
    $stmt->execute([$station_id, $week_start, $week_end]);
    $all_statuses = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $stmt2 = $pdo->prepare("SELECT status FROM deliveries_oversight WHERE station_id = ? AND DATE(delivery_date) BETWEEN ? AND ?");
    $stmt2->execute([$station_id, $week_start, $week_end]);
    $all_statuses = array_merge($all_statuses, $stmt2->fetchAll(PDO::FETCH_COLUMN));
    
    $stmt3 = $pdo->prepare("SELECT status FROM job_orders WHERE station_id = ? AND DATE(created_at) BETWEEN ? AND ?");
    $stmt3->execute([$station_id, $week_start, $week_end]);
    $all_statuses = array_merge($all_statuses, $stmt3->fetchAll(PDO::FETCH_COLUMN));
    
    foreach ($all_statuses as $st_val) {
        $st = strtolower(trim($st_val ?? ''));
        if (in_array($st, ['pending', 'pending validation', 'draft', 'reviewed', 'in progress'])) {
            $summary_stats['week_pending']++;
        } elseif (in_array($st, ['approved', 'verified', 'processing'])) {
            $summary_stats['week_in_progress']++;
        } elseif (in_array($st, ['completed', 'complete', 'stock-in complete', 'delivered'])) {
            $summary_stats['week_completed']++;
        }
    }
    
    // Upcoming events (next 3 days across all sources)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM staff_calendar_events WHERE station_id = ? AND event_date BETWEEN ? AND ?");
    $stmt->execute([$station_id, $today_date, $upcoming_end]);
    $u1 = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM deliveries_oversight WHERE station_id = ? AND DATE(delivery_date) BETWEEN ? AND ?");
    $stmt->execute([$station_id, $today_date, $upcoming_end]);
    $u2 = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id = ? AND DATE(created_at) BETWEEN ? AND ?");
    $stmt->execute([$station_id, $today_date, $upcoming_end]);
    $u3 = (int)$stmt->fetchColumn();
    $summary_stats['upcoming_count'] = $u1 + $u2 + $u3;
    
    // MANAGER SPECIFIC: Pending validations count
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM merchandise_transactions WHERE station_id = ? AND validation_status = 'Pending'");
        $stmt->execute([$station_id]);
        $summary_stats['pending_validations'] += (int)$stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM deliveries_oversight WHERE station_id = ? AND (LOWER(status) LIKE '%pending%' OR manager_id IS NULL)");
        $stmt->execute([$station_id]);
        $summary_stats['pending_validations'] += (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id = ? AND (status = 'Pending' OR status = 'Reviewed')");
        $stmt->execute([$station_id]);
        $summary_stats['pending_validations'] += (int)$stmt->fetchColumn();
    } catch (Exception $e) {}
    
    // MANAGER SPECIFIC: Overdue payments count
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM merchandise_transactions
            WHERE station_id = ? AND COALESCE(balance_due, 0) > 0 AND due_date < CURDATE()
            AND LOWER(COALESCE(payment_status, '')) <> 'paid'");
        $stmt->execute([$station_id]);
        $summary_stats['overdue_payments'] = (int)$stmt->fetchColumn();
    } catch (Exception $e) {}
    
    // MANAGER SPECIFIC: Low stock items
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM inventory_products ip
            LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
            WHERE LOWER(COALESCE(ip.category,'')) NOT IN ('fuel', 'fuel products')
              AND ip.status = 'Active'
              AND COALESCE(si.stock_level, ip.stock, 0) <= COALESCE(si.reorder_level, ip.min_stock, 10)
        ");
        $stmt->execute([$station_id]);
        $summary_stats['low_stock_items'] = (int)$stmt->fetchColumn();
    } catch (Exception $e) {}
    
    // MANAGER SPECIFIC: Staff workload today (events per staff)
    try {
        $stmt = $pdo->prepare("SELECT u.id, u.name, COUNT(sce.id) as event_count
            FROM users u
            LEFT JOIN staff_calendar_events sce ON u.id = sce.staff_encoder_id AND sce.event_date = ?
            WHERE u.station_id = ? AND u.role IN ('staff','cashier','pump_attendant') AND u.status = 'Active'
            GROUP BY u.id, u.name
            ORDER BY event_count DESC");
        $stmt->execute([$today_date, $station_id]);
        $summary_stats['staff_workload'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
    
    // Check for schedule conflicts
    $stmt = $pdo->prepare("SELECT e1.event_date, e1.start_time, e1.end_time, e1.work_description,
            e2.start_time as conflict_start, e2.end_time as conflict_end, e2.work_description as conflict_desc,
            u.name as staff_name
        FROM staff_calendar_events e1
        JOIN staff_calendar_events e2 ON e1.event_date = e2.event_date AND e1.id < e2.id
        JOIN users u ON e1.staff_encoder_id = u.id
        WHERE e1.station_id = ? AND e2.station_id = ?
        AND e1.start_time IS NOT NULL AND e2.start_time IS NOT NULL
        AND (
            (e1.start_time < e2.end_time AND e1.end_time > e2.start_time)
            OR (e2.start_time < e1.end_time AND e2.end_time > e1.start_time)
        )
        AND e1.status != 'cancelled' AND e2.status != 'cancelled'
        AND e1.staff_encoder_id = e2.staff_encoder_id
        LIMIT 10");
    $stmt->execute([$station_id, $station_id]);
    $summary_stats['conflicts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Month navigation
$today = new DateTime();
$month_offset = (int)($_GET['month_offset'] ?? 0);
$current_view = $_GET['view'] ?? 'month'; // day, week, month, year

$current_month = clone $today;
$current_month->modify($month_offset . ' months');
$current_month->modify('first day of this month');

$month_name = $current_month->format('F Y');
$prev_offset = $month_offset - 1;
$next_offset = $month_offset + 1;
$today_str = $today->format('Y-m-d');
$current_month_str = $current_month->format('Y-m');

// Get all days in month
$first_day = clone $current_month;
$last_day = clone $current_month;
$last_day->modify('last day of this month');

// Get calendar grid (include previous/next month days to fill weeks)
$calendar_start = clone $first_day;
$start_weekday = (int)$calendar_start->format('N'); // 1=Monday, 7=Sunday
if ($start_weekday > 1) {
    $calendar_start->modify('-' . ($start_weekday - 1) . ' days');
}

$calendar_end = clone $last_day;
$end_weekday = (int)$calendar_end->format('N');
if ($end_weekday < 7) {
    $calendar_end->modify('+' . (7 - $end_weekday) . ' days');
}

// Build array of all dates to display
$calendar_dates = [];
$current_date = clone $calendar_start;
while ($current_date <= $calendar_end) {
    $calendar_dates[] = $current_date->format('Y-m-d');
    $current_date->modify('+1 day');
}

// Prepare date ranges based on view
switch($current_view) {
    case 'day':
        $view_start = $today_str;
        $view_end = $today_str;
        $view_title = $today->format('l, F j, Y');
        break;
    case 'week':
        $week_start = clone $today;
        $week_start->modify('sunday this week');
        $week_end = clone $week_start;
        $week_end->modify('+6 days');
        $view_start = $week_start->format('Y-m-d');
        $view_end = $week_end->format('Y-m-d');
        $view_title = $week_start->format('M j') . ' - ' . $week_end->format('M j, Y');
        break;
    case 'year':
        $year_start = clone $today;
        $year_start->modify('first day of January ' . $today->format('Y'));
        $year_end = clone $year_start;
        $year_end->modify('last day of December ' . $today->format('Y'));
        $view_start = $year_start->format('Y-m-d');
        $view_end = $year_end->format('Y-m-d');
        $view_title = $today->format('Y');
        break;
    default: // month
        $view_start = $calendar_dates[0];
        $view_end = end($calendar_dates);
        $view_title = $month_name;
}

// Load events for the entire calendar range
$month_events = [];
$staff_list = [];
$staff_colors = ['#039be5', '#7986cb', '#33b679', '#8e24aa', '#e67c73', '#f6bf26', '#f4511e', '#0b8043', '#d50000'];

try {
    // Load staff list with assigned colors
    $staff_stmt = $pdo->prepare("SELECT id, CONCAT(first_name, ' ', last_name) AS name FROM users WHERE (station_id = ? OR station_id = 0 OR station_id IS NULL) AND status = 'Active' ORDER BY first_name, last_name");
    $staff_stmt->execute([$station_id]);
    $all_staff = $staff_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($all_staff as $idx => $staff) {
        $color = $staff_colors[$idx % count($staff_colors)];
        $staff_list[$staff['id']] = [
            'name' => $staff['name'],
            'color' => $color
        ];
    }
} catch (Exception $e) {}

    // ── COMPLETE DEDUPLICATED MANAGER OPERATIONAL & APPROVAL CALENDAR ENGINE ──
    
    // Master Target URL Resolver for Direct Module Navigation on Event Click
    $resolve_target_url = function($type_key, $id, $role = 'manager') {
        $id_num = preg_replace('/[^0-9]/', '', (string)$id);
        switch($type_key) {
            case 'job_order':
                if ($role === 'admin') return 'admin_all_transactions.php?search=JO-' . $id_num;
                if ($role === 'manager') return 'manager_transactions_hub.php?tab=job_orders';
                return 'staff_transactions_hub.php?tab=job_orders';
            case 'merchandise_delivery':
            case 'fuel_delivery':
                if ($role === 'admin') return 'admin_fuel_transactions_oversight.php';
                if ($role === 'manager') return 'deliveries_oversight.php';
                return 'staff_transactions_hub.php?tab=deliveries';
            case 'validation_task':
            case 'validation_delivery':
            case 'manager_approval':
            case 'master_data_approval':
            case 'admin_approval':
                if ($role === 'admin') return 'admin_user_management.php';
                return 'manager_fuel_transaction_validation.php';
            case 'fuel_calibration':
                if ($role === 'admin') return 'admin_calibration_review.php';
                if ($role === 'manager') return 'manager_calibration_review.php';
                return 'staff_fuel_adjustments.php';
            case 'stock_request':
            case 'restock_reminder':
            case 'stock_alert':
                if ($role === 'admin') return 'admin_inventory_management.php';
                return 'inventory_management.php';
            case 'report_schedule':
                if ($role === 'admin') return 'admin_reports.php';
                if ($role === 'manager') return 'manager_reports.php';
                return 'staff_reports.php';
            default:
                return '#';
        }
    };

$seen_events = []; // Prevents duplicate events per date

    $add_mgr_unique_event = function($date, $event) use (&$month_events, &$seen_events, $resolve_target_url) {
        if (!$date || !isset($event['id'])) return;
        $key = (string)$event['id'];
        if (isset($seen_events[$date][$key])) return;
        $seen_events[$date][$key] = true;
        if (!isset($event['target_url'])) {
            $event['target_url'] = $resolve_target_url($event['type_key'] ?? '', $event['id'] ?? '', 'manager');
        }
        $month_events[$date][] = $event;
    };

    // 1. Staff Calendar Events (All station events & manager activities)
    try {
        $stmt = $pdo->prepare("
            SELECT sce.*, et.type_name, et.type_key, et.icon_class, su.name AS staff_name,
                   sce.staff_encoder_id, m.name AS manager_name
            FROM staff_calendar_events sce
            JOIN staff_event_types et ON sce.event_type_id = et.id
            JOIN users su ON sce.staff_encoder_id = su.id
            LEFT JOIN users m ON sce.manager_assigned_id = m.id
            WHERE sce.station_id = ? AND sce.event_date BETWEEN ? AND ?
            ORDER BY sce.event_date, sce.start_time
        ");
        $stmt->execute([$station_id, $view_start, $view_end]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['color'] = $staff_list[$row['staff_encoder_id']]['color'] ?? '#039be5';
            $add_mgr_unique_event($row['event_date'], $row);
        }
    } catch (Exception $e) {}

    // 2. Staff Schedules / Shifts & Turnover (Shift 1 & Shift 2)
    try {
        $sh = $pdo->prepare("
            SELECT ss.id, ss.user_id, ss.shift, ss.scheduled_date, ss.status, u.name AS staff_name,
                   s.start_time, s.end_time
            FROM staff_schedules ss
            JOIN users u ON ss.user_id = u.id
            LEFT JOIN shifts s ON ss.shift = s.name
            WHERE (u.station_id = ? OR u.station_id IS NULL OR u.station_id = 0)
              AND ss.scheduled_date BETWEEN ? AND ?
        ");
        $sh->execute([$station_id, $view_start, $view_end]);
        foreach ($sh->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $shift_txt = strtolower($r['shift'] ?? '');
            // Distinct Color Coding: Shift 1 = Royal Blue (#2563eb), Shift 2 = Deep Purple (#9333ea)
            $shift_color = (strpos($shift_txt, '1') !== false || strpos($shift_txt, 'morn') !== false) ? '#2563eb' : ((strpos($shift_txt, '2') !== false || strpos($shift_txt, 'after') !== false || strpos($shift_txt, 'night') !== false) ? '#9333ea' : '#0284c7');

            $add_mgr_unique_event($r['scheduled_date'], [
                'id' => 'shift_'.$r['id'],
                'type_name' => 'Shift Schedule (' . $r['shift'] . ')',
                'type_key' => 'staff_shift',
                'icon_class' => 'fas fa-clock',
                'staff_name' => $r['staff_name'],
                'staff_encoder_id' => $r['user_id'],
                'work_description' => $r['staff_name'] . ' — ' . $r['shift'] . ' Shift (' . ($r['start_time'] ?? '06:00') . ' - ' . ($r['end_time'] ?? '14:00') . ')',
                'status' => strtolower($r['status'] ?? 'active'),
                'color' => $shift_color,
                'auto_synced' => true
            ]);
        }
    } catch (Exception $e) {}

    // 3. Job Orders (Scheduled, Active, Completion & Due Dates)
    try {
        $jo = $pdo->prepare("
            SELECT jo.id, jo.created_by, DATE(jo.created_at) AS event_date, jo.due_date,
                   jo.service_type, jo.status, u.name AS staff_name, jo.customer_name,
                   m.name AS manager_name
            FROM job_orders jo
            JOIN users u ON jo.created_by = u.id
            LEFT JOIN users m ON jo.validated_by = m.id
            WHERE jo.station_id = ? AND (DATE(jo.created_at) BETWEEN ? AND ? OR jo.due_date BETWEEN ? AND ?)
        ");
        $jo->execute([$station_id, $view_start, $view_end, $view_start, $view_end]);
        foreach ($jo->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $edate = $r['due_date'] ?: $r['event_date'];
            $st_label = strtolower($r['status'] ?? 'pending');
            $color = ($st_label === 'completed' || $st_label === 'verified') ? '#10b981' : (($st_label === 'in progress' || $st_label === 'in_progress') ? '#3b82f6' : '#f59e0b');
            $add_mgr_unique_event($edate, [
                'id' => 'jo_'.$r['id'],
                'type_name' => 'Job Order',
                'type_key' => 'job_order',
                'icon_class' => 'fas fa-wrench',
                'staff_name' => $r['staff_name'],
                'staff_encoder_id' => $r['created_by'],
                'work_description' => 'JO #' . $r['id'] . ' (' . ucfirst($st_label) . '): ' . $r['service_type'] . ' - ' . ($r['customer_name'] ?: 'Customer'),
                'status' => $st_label,
                'color' => $color,
                'manager_name' => $r['manager_name'] ?? 'Manager Oversight',
                'auto_synced' => true
            ]);
        }
    } catch (Exception $e) {}

    // 4. Deliveries Oversight & Expected Deliveries
    try {
        $dl = $pdo->prepare("
            SELECT d.id, d.encoded_by, DATE(d.delivery_date) AS event_date, u.name AS staff_name,
                   d.status, d.supplier, d.product, m.name AS manager_name
            FROM deliveries_oversight d
            JOIN users u ON d.encoded_by = u.id
            LEFT JOIN users m ON d.manager_id = m.id
            WHERE d.station_id = ? AND DATE(d.delivery_date) BETWEEN ? AND ?
        ");
        $dl->execute([$station_id, $view_start, $view_end]);
        foreach ($dl->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $add_mgr_unique_event($r['event_date'], [
                'id' => 'del_'.$r['id'],
                'type_name' => 'Merchandise Delivery',
                'type_key' => 'merchandise_delivery',
                'icon_class' => 'fas fa-box',
                'staff_name' => $r['staff_name'],
                'staff_encoder_id' => $r['encoded_by'],
                'work_description' => 'Delivery: ' . $r['supplier'] . ' - ' . $r['product'],
                'status' => strtolower($r['status'] ?? 'pending'),
                'color' => '#06b6d4',
                'manager_name' => $r['manager_name'] ?? 'Pending Validation',
                'auto_synced' => true
            ]);
        }
    } catch (Exception $e) {}

    // 5. Fuel Deliveries
    try {
        $fd = $pdo->prepare("
            SELECT id, DATE(created_at) AS event_date, fuel_type, liters, status, supplier
            FROM fuel_deliveries
            WHERE station_id = ? AND DATE(created_at) BETWEEN ? AND ?
        ");
        $fd->execute([$station_id, $view_start, $view_end]);
        foreach ($fd->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $add_mgr_unique_event($r['event_date'], [
                'id' => 'fuel_del_'.$r['id'],
                'type_name' => 'Fuel Delivery',
                'type_key' => 'fuel_delivery',
                'icon_class' => 'fas fa-gas-pump',
                'staff_name' => $r['supplier'] ?: 'Fuel Supplier',
                'staff_encoder_id' => $user_id,
                'work_description' => 'Fuel Delivery: ' . ($r['fuel_type'] ?: 'Fuel') . ' (' . number_format((float)($r['liters'] ?? 0), 2) . ' L)',
                'status' => strtolower($r['status'] ?? 'pending'),
                'color' => '#ef4444',
                'auto_synced' => true
            ]);
        }
    } catch (Exception $e) {}

    // 6. Fuel Calibration & Meter Reading Schedule
    try {
        $fc = $pdo->prepare("
            SELECT id, DATE(calibration_date) AS event_date, fuel_type, status, technician_name
            FROM fuel_calibration_records
            WHERE station_id = ? AND DATE(calibration_date) BETWEEN ? AND ?
        ");
        $fc->execute([$station_id, $view_start, $view_end]);
        foreach ($fc->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $add_mgr_unique_event($r['event_date'], [
                'id' => 'calib_'.$r['id'],
                'type_name' => 'Fuel Reading & Calibration',
                'type_key' => 'fuel_calibration',
                'icon_class' => 'fas fa-tachometer-alt',
                'staff_name' => $r['technician_name'] ?: 'Technician',
                'staff_encoder_id' => $user_id,
                'work_description' => 'Meter Reading / Calibration: ' . ($r['fuel_type'] ?: 'Pump'),
                'status' => strtolower($r['status'] ?? 'pending'),
                'color' => '#64748b',
                'auto_synced' => true
            ]);
        }
    } catch (Exception $e) {}

    // 7. MANAGER APPROVAL TASKS: Pending Void & Adjustment Requests
    try {
        $reqs = $pdo->prepare("
            SELECT tr.id, DATE(tr.created_at) AS event_date, tr.request_type, tr.transaction_id,
                   tr.status, u.name AS staff_name, tr.record_source
            FROM transaction_requests tr
            JOIN users u ON tr.staff_id = u.id
            WHERE tr.station_id = ? AND tr.status = 'Pending'
              AND DATE(tr.created_at) BETWEEN ? AND ?
        ");
        $reqs->execute([$station_id, $view_start, $view_end]);
        foreach ($reqs->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $req_type = ucfirst($r['request_type']);
            $add_mgr_unique_event($r['event_date'], [
                'id' => 'approval_req_'.$r['id'],
                'type_name' => 'Pending ' . $req_type . ' Request',
                'type_key' => 'manager_approval',
                'icon_class' => 'fas fa-exclamation-circle',
                'staff_name' => $r['staff_name'],
                'staff_encoder_id' => $user_id,
                'work_description' => '[APPROVAL REQUIRED] Pending ' . $req_type . ': Txn #' . $r['transaction_id'] . ' (by ' . $r['staff_name'] . ')',
                'status' => 'pending',
                'color' => '#dc2626',
                'priority' => 'urgent',
                'auto_synced' => true
            ]);
        }
    } catch (Exception $e) {}

    // 8. MANAGER APPROVAL TASKS: Pending Master Data Requests
    try {
        $md = $pdo->prepare("
            SELECT m.id, DATE(m.created_at) AS event_date, m.request_type, m.entity_type,
                   u.name AS staff_name
            FROM master_data_requests m
            JOIN users u ON m.requested_by = u.id
            WHERE m.station_id = ? AND m.status = 'Pending'
              AND DATE(m.created_at) BETWEEN ? AND ?
        ");
        $md->execute([$station_id, $view_start, $view_end]);
        foreach ($md->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $add_mgr_unique_event($r['event_date'], [
                'id' => 'md_req_'.$r['id'],
                'type_name' => 'Master Data Request',
                'type_key' => 'master_data_approval',
                'icon_class' => 'fas fa-database',
                'staff_name' => $r['staff_name'],
                'staff_encoder_id' => $user_id,
                'work_description' => '[APPROVAL REQUIRED] Master Data: ' . ucfirst($r['request_type']) . ' ' . $r['entity_type'] . ' (by ' . $r['staff_name'] . ')',
                'status' => 'pending',
                'color' => '#d97706',
                'priority' => 'high',
                'auto_synced' => true
            ]);
        }
    } catch (Exception $e) {}

    // 9. Stock Requests (Pending Stock Request Approvals)
    try {
        $sr = $pdo->prepare("
            SELECT id, DATE(created_at) AS event_date, product_name, quantity, status
            FROM stock_requests
            WHERE station_id = ? AND DATE(created_at) BETWEEN ? AND ?
        ");
        $sr->execute([$station_id, $view_start, $view_end]);
        foreach ($sr->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $st_label = strtolower($r['status'] ?? 'pending');
            $add_mgr_unique_event($r['event_date'], [
                'id' => 'stock_req_'.$r['id'],
                'type_name' => 'Stock Request',
                'type_key' => 'stock_request',
                'icon_class' => 'fas fa-boxes',
                'staff_name' => 'Stock Request',
                'staff_encoder_id' => $user_id,
                'work_description' => ($st_label === 'pending' ? '[APPROVAL REQUIRED] ' : '') . 'Stock Request: ' . ($r['product_name'] ?: 'Item') . ' (Qty: ' . $r['quantity'] . ')',
                'status' => $st_label,
                'color' => $st_label === 'pending' ? '#d97706' : '#8b5cf6',
                'auto_synced' => true
            ]);
        }
    } catch (Exception $e) {}

    // 10. Low Inventory Restock Reminders
    try {
        $low_stock = $pdo->prepare("
            SELECT ip.id, ip.product_name, 
                   COALESCE(si.stock_level, ip.stock, 0) AS current_stock,
                   COALESCE(si.reorder_level, ip.min_stock, 10) AS minimum_stock,
                   COALESCE(si.unit, ip.size, 'pcs') AS unit
            FROM inventory_products ip
            LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
            WHERE LOWER(COALESCE(ip.category,'')) NOT IN ('fuel', 'fuel products')
              AND ip.status = 'Active'
              AND COALESCE(si.stock_level, ip.stock, 0) <= COALESCE(si.reorder_level, ip.min_stock, 10)
            LIMIT 10
        ");
        $low_stock->execute([$station_id]);
        foreach ($low_stock->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $add_mgr_unique_event($today_str, [
                'id' => 'restock_'.$r['id'],
                'type_name' => 'Low Stock Reminder',
                'type_key' => 'restock_reminder',
                'icon_class' => 'fas fa-exclamation-triangle',
                'staff_name' => 'System Alert',
                'staff_encoder_id' => $user_id,
                'work_description' => 'Low Stock: ' . $r['product_name'] . ' (' . (int)$r['current_stock'] . ' ' . $r['unit'] . ' left)',
                'status' => 'pending',
                'color' => '#ef4444',
                'auto_synced' => true
            ]);
        }
    } catch (Exception $e) {}

    // 11. Reports & Reconciliation Submission Deadlines
    // Adds daily closing & sales reconciliation reminders to today's and end of month dates
    $add_mgr_unique_event($today_str, [
        'id' => 'report_fuel_sales_closing',
        'type_name' => 'Report Deadline',
        'type_key' => 'report_schedule',
        'icon_class' => 'fas fa-file-invoice-dollar',
        'staff_name' => 'Manager Schedule',
        'staff_encoder_id' => $user_id,
        'work_description' => 'Daily Fuel Sales & Shift Reconciliation Submission',
        'status' => 'pending',
        'color' => '#10b981',
        'auto_synced' => true
    ]);

include __DIR__ . '/../partials/header.php';
?>

<style>
/* Google Calendar Style */
.cal-layout { font-family: 'Google Sans', 'Roboto', Arial, sans-serif; background: #fff; display: flex; width: 100%; max-width: 100%; height: auto; min-height: calc(100vh - 150px); overflow: hidden; margin-bottom: 60px !important; box-sizing: border-box; }
.cal-layout *:not(i):not([class*="fa-"]) { font-family: 'Google Sans', 'Roboto', Arial, sans-serif; box-sizing: border-box; }

/* Font Awesome Icon Override */
i.fas, i.far, i.fab, i.fa, [class*="fa-"] {
    font-family: "Font Awesome 6 Free", "Font Awesome 5 Free", "FontAwesome" !important;
    font-style: normal !important;
    font-weight: 900 !important;
    display: inline-block !important;
}

/* Sidebar */
.cal-sidebar { width: 256px; min-width: 256px; max-width: 256px; border-right: 1px solid #dadce0; padding: 8px 0; overflow-y: visible; flex-shrink: 0; height: auto; box-sizing: border-box; }
.cal-create-btn { margin: 20px 12px 32px; background: #fff; border: none; box-shadow: 0 1px 2px 0 rgba(60,64,67,.3), 0 1px 3px 1px rgba(60,64,67,.15); border-radius: 24px; padding: 0 24px 0 12px; height: 56px; display: flex; align-items: center; gap: 16px; cursor: pointer; font-size: 14px; color: #3c4043; font-weight: 500; transition: box-shadow .2s; }
.cal-create-btn:hover { box-shadow: 0 1px 3px 0 rgba(60,64,67,.3), 0 4px 8px 3px rgba(60,64,67,.15); }
.cal-create-btn i { width: 36px; height: 36px; background: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 20px; color: #1a73e8; }

.cal-mini-month { padding: 0 12px 20px; }
.cal-mini-header { display: flex; align-items: center; justify-content: space-between; padding: 8px 4px; }
.cal-mini-title { font-size: 14px; font-weight: 500; color: #3c4043; }
.cal-mini-nav { background: none; border: none; padding: 8px; border-radius: 50%; cursor: pointer; color: #5f6368; }
.cal-mini-nav:hover { background: #f1f3f4; }
.cal-mini-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 1px; }
.cal-mini-day { height: 32px; display: flex; align-items: center; justify-content: center; font-size: 12px; color: #3c4043; border-radius: 50%; cursor: pointer; }
.cal-mini-day.today { background: #1a73e8; color: #fff; font-weight: 600; }
.cal-mini-day:hover:not(.today) { background: #f1f3f4; }
.cal-mini-weekday { height: 24px; display: flex; align-items: center; justify-content: center; font-size: 10px; color: #70757a; font-weight: 500; }

.cal-calendars { padding: 0 12px; }
.cal-calendars-title { font-size: 14px; font-weight: 500; color: #3c4043; padding: 12px 8px 8px; }
.cal-calendar-item { display: flex; align-items: center; gap: 12px; padding: 8px; border-radius: 8px; cursor: pointer; font-size: 14px; color: #3c4043; }
.cal-calendar-item:hover { background: #f1f3f4; }
.cal-calendar-checkbox { width: 20px; height: 20px; border-radius: 3px; border: 2px solid; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 12px; }
.cal-calendar-checkbox.checked::before { content: '\2713'; }

/* Main content */
.cal-main { flex: 1 1 auto; display: flex; flex-direction: column; overflow: hidden; width: calc(100% - 256px); min-width: 0; max-width: calc(100% - 256px); box-sizing: border-box; }
.cal-header { padding: 8px 16px; border-bottom: 1px solid #dadce0; display: flex; align-items: center; justify-content: space-between; width: 100%; box-sizing: border-box; }
.cal-header-left { display: flex; align-items: center; gap: 16px; min-width: 0; }
.cal-menu-btn { background: none; border: none; padding: 12px; border-radius: 50%; cursor: pointer; color: #5f6368; font-size: 20px; }
.cal-menu-btn:hover { background: #f1f3f4; }
.cal-month-title { font-size: 22px; font-weight: 400; color: #3c4043; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cal-header-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.cal-view-btn { background: #fff !important; border: 1px solid #dadce0; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-size: 14px; color: #3c4043 !important; display: flex; align-items: center; gap: 6px; position: relative; text-decoration: none; }
.cal-view-btn:hover { background: #f1f3f4 !important; }

/* View dropdown */
.cal-view-dropdown { position: absolute; top: 100%; right: 0; margin-top: 4px; background: #fff; border: 1px solid #dadce0; border-radius: 8px; box-shadow: 0 2px 6px rgba(0,0,0,.2); z-index: 100; display: none; }
.cal-view-dropdown.show { display: block; }
.cal-view-option { padding: 12px 16px; cursor: pointer; font-size: 14px; color: #3c4043; display: flex; align-items: center; justify-content: space-between; }
.cal-view-option:hover { background: #f1f3f4; }
.cal-view-option.active { background: #e8f0fe; }
.cal-view-option .shortcut { font-size: 12px; color: #5f6368; }
.cal-icon-btn { background: none; border: none; padding: 12px; border-radius: 50%; cursor: pointer; color: #5f6368; font-size: 18px; }
.cal-icon-btn:hover { background: #f1f3f4; }

/* Calendar grid */
.cal-content { flex: 1 1 auto; height: auto; overflow: visible; width: 100%; max-width: 100%; box-sizing: border-box; }
.cal-grid-container { width: 100%; max-width: 100%; height: auto; box-sizing: border-box; }
.cal-weekdays { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); border-bottom: 1px solid #dadce0; position: sticky; top: 0; background: #fff; z-index: 2; width: 100%; box-sizing: border-box; }
.cal-weekday { padding: 8px 4px; text-align: center; font-size: 11px; font-weight: 500; color: #70757a; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cal-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); grid-auto-rows: minmax(120px, auto); width: 100%; box-sizing: border-box; }
.cal-day { border: 1px solid #dadce0; border-top: none; border-left: none; padding: 2px; position: relative; background: #fff; overflow: hidden; min-width: 0; }
.cal-day:nth-child(7n) { border-right: none; }
.cal-day:hover { background: #f8f9fa; }
.cal-day.other-month { background: #fafafa; }
.cal-day.today { background: #e8f0fe; }

.cal-day-num { height: 28px; width: 28px; display: flex; align-items: center; justify-content: center; font-size: 12px; color: #3c4043; margin: 2px; }
.cal-day.today .cal-day-num { background: #1a73e8; color: #fff; border-radius: 50%; font-weight: 600; }
.cal-day.other-month .cal-day-num { color: #9aa0a6; }

.cal-events { padding: 0 4px; }
.cal-event { margin-bottom: 2px; padding: 2px 6px; border-radius: 3px; font-size: 11px; cursor: pointer; display: flex; align-items: center; gap: 4px; border-left: 3px solid; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #3c4043; }
.cal-event:hover { filter: brightness(.95); }
.cal-event-time { font-weight: 500; }
.cal-event-text { flex: 1; overflow: hidden; text-overflow: ellipsis; }
.cal-more { font-size: 11px; color: #5f6368; padding: 2px 6px; cursor: pointer; font-weight: 500; }
.cal-more:hover { background: #f1f3f4; border-radius: 3px; }

@media(max-width: 900px) {
    .cal-sidebar { display: none; }
    .cal-grid { grid-auto-rows: 80px; }
}
</style>

<div class="cal-layout">
    <!-- Sidebar -->
    <div class="cal-sidebar">
        <!-- Search Sidebar Panel -->
        <div style="padding: 12px; border-bottom: 1px solid #dadce0;">
            <div style="font-size: 12px; font-weight: 600; color: #3c4043; margin-bottom: 8px;"><i class="fas fa-search"></i> SEARCH CALENDAR</div>
            <input type="text" id="calendarSearchInput" placeholder="Customer, Plate, JO#, Mechanic..." 
                   onkeyup="filterCalendarEvents()" 
                   style="width: 100%; padding: 6px 10px; font-size: 12px; border: 1px solid #dadce0; border-radius: 4px; outline: none; margin-bottom: 8px;">
            <div style="font-size: 10px; color: #70757a;">Search by: Customer | Plate | JO# | Mechanic</div>
        </div>

        <!-- Filters Sidebar Panel -->
        <div style="padding: 12px; border-bottom: 1px solid #dadce0;">
            <div style="font-size: 12px; font-weight: 600; color: #3c4043; margin-bottom: 8px;"><i class="fas fa-filter"></i> FILTERS</div>
            <div style="display: grid; gap: 6px;">
                <select id="filterStatus" onchange="filterCalendarEvents()" style="width: 100%; padding: 5px; font-size: 11px; border: 1px solid #dadce0; border-radius: 4px;">
                    <option value="">All Statuses</option>
                    <option value="pending">Pending / Unvalidated</option>
                    <option value="approved">Approved / In Progress</option>
                    <option value="completed">Completed / Verified</option>
                </select>
                
                <select id="filterMechanic" onchange="filterCalendarEvents()" style="width: 100%; padding: 5px; font-size: 11px; border: 1px solid #dadce0; border-radius: 4px;">
                    <option value="">All Staff / Mechanics</option>
                    <?php foreach ($summary_stats['all_mechanics'] as $mech): ?>
                    <option value="<?= htmlspecialchars($mech['id']) ?>"><?= htmlspecialchars($mech['name']) ?></option>
                    <?php endforeach; ?>
                </select>

                <select id="filterEventType" onchange="filterCalendarEvents()" style="width: 100%; padding: 5px; font-size: 11px; border: 1px solid #dadce0; border-radius: 4px;">
                    <option value="">All Event Types</option>
                    <option value="job_order"><i class="fas fa-circle text-success"></i> Job Orders</option>
                    <option value="customer_appointment"><i class="fas fa-circle text-primary"></i> Customer Appointments</option>
                    <option value="pms"><i class="fas fa-circle" style="color:#f97316;"></i> Preventive Maintenance (PMS)</option>
                    <option value="staff_shift"><i class="fas fa-circle" style="color:#8b5cf6;"></i> Staff Shifts</option>
                    <option value="merchandise_delivery"><i class="fas fa-circle text-warning"></i> Merchandise Deliveries</option>
                    <option value="fuel_delivery"><i class="fas fa-circle" style="color:#b45309;"></i> Fuel Deliveries</option>
                </select>

                <input type="date" id="filterDate" onchange="filterCalendarEvents()" style="width: 100%; padding: 5px; font-size: 11px; border: 1px solid #dadce0; border-radius: 4px;">
            </div>
        </div>

        <!-- Events Color Legend (Clickable to Filter) -->
        <div style="padding: 12px; border-bottom: 1px solid #dadce0;">
            <div style="font-size: 12px; font-weight: 600; color: #3c4043; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center;">
                <span><i class="fas fa-palette"></i> EVENT TYPES</span>
                <span onclick="filterByLegendType('')" style="font-size: 10px; color: #1a73e8; cursor: pointer; font-weight: 500;" title="Clear filter">Show All</span>
            </div>
            <div style="display: flex; flex-direction: column; gap: 5px; font-size: 11px; color: #3c4043;">
                <div onclick="filterByLegendType('job_order')" style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 3px 6px; border-radius: 4px; transition: background 0.15s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'"><span style="color: #33b679; font-size: 14px;"><i class="fas fa-circle text-success"></i></span> <span style="font-weight: 500;">Job Orders</span></div>
                <div onclick="filterByLegendType('customer_appointment')" style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 3px 6px; border-radius: 4px; transition: background 0.15s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'"><span style="color: #039be5; font-size: 14px;"><i class="fas fa-circle text-primary"></i></span> <span style="font-weight: 500;">Customer Appointments</span></div>
                <div onclick="filterByLegendType('pms')" style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 3px 6px; border-radius: 4px; transition: background 0.15s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'"><span style="color: #f6bf26; font-size: 14px;"><i class="fas fa-circle" style="color:#f97316;"></i></span> <span style="font-weight: 500;">Preventive Maintenance</span></div>
                <div onclick="filterByLegendType('staff_shift')" style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 3px 6px; border-radius: 4px; transition: background 0.15s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'"><span style="color: #8e24aa; font-size: 14px;"><i class="fas fa-circle" style="color:#8b5cf6;"></i></span> <span style="font-weight: 500;">Staff Shifts</span></div>
                <div onclick="filterByLegendType('merchandise_delivery')" style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 3px 6px; border-radius: 4px; transition: background 0.15s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'"><span style="color: #e67c73; font-size: 14px;"><i class="fas fa-circle text-warning"></i></span> <span style="font-weight: 500;">Merchandise Deliveries</span></div>
                <div onclick="filterByLegendType('fuel_delivery')" style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 3px 6px; border-radius: 4px;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'"><span style="color: #795548; font-size: 14px;"><i class="fas fa-circle" style="color:#b45309;"></i></span> <span style="font-weight: 500;">Fuel Deliveries</span></div>
            </div>
        </div>

        <!-- Summary Panels -->
        <div style="padding: 0 12px 20px; border-bottom: 1px solid #dadce0;">
            <!-- Manager Validation Tasks -->
            <div style="background: #fef7e0; border-radius: 8px; padding: 12px; margin-bottom: 12px;">
                <div style="font-size: 12px; color: #ea8600; font-weight: 600; margin-bottom: 8px;"><i class="fas fa-exclamation-triangle"></i> VALIDATION TASKS</div>
                <div style="font-size: 24px; font-weight: 600; color: #ea8600;"><?= $summary_stats['pending_validations'] ?></div>
                <div style="font-size: 10px; color: #5f6368;">awaiting your review</div>
                <?php if ($summary_stats['pending_validations'] > 0): ?>
                <a href="../public/manager_fuel_transaction_validation.php" style="display: block; margin-top: 8px; padding: 6px 12px; background: #ea8600; color: #fff; border-radius: 4px; font-size: 11px; text-align: center; text-decoration: none;">
                    Review Now
                </a>
                <?php endif; ?>
            </div>

            <!-- Pending Job Orders Quick List -->
            <div style="background: #e6f4ea; border-radius: 8px; padding: 12px; margin-bottom: 12px;">
                <div style="font-size: 12px; color: #137333; font-weight: 600; margin-bottom: 6px;"><i class="fas fa-wrench"></i> PENDING JOB ORDERS (<?= count($summary_stats['pending_job_orders']) ?>)</div>
                <?php if (empty($summary_stats['pending_job_orders'])): ?>
                    <div style="font-size: 11px; color: #5f6368;">No pending job orders</div>
                <?php else: ?>
                    <div style="max-height: 120px; overflow-y: auto;">
                        <?php foreach($summary_stats['pending_job_orders'] as $pjo): ?>
                        <div style="font-size: 11px; border-bottom: 1px solid #ceead6; padding: 4px 0;">
                            <div style="font-weight: 600; color: #137333;"><?= htmlspecialchars($pjo['service_type']) ?> - JO#<?= $pjo['id'] ?></div>
                            <div style="color: #5f6368; font-size: 10px;"><?= htmlspecialchars($pjo['customer_name']) ?> (<?= htmlspecialchars($pjo['plate_number'] ?? 'N/A') ?>)</div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Tomorrow's Deliveries Quick List -->
            <div style="background: #fef7e0; border-radius: 8px; padding: 12px; margin-bottom: 12px;">
                <div style="font-size: 12px; color: #b06000; font-weight: 600; margin-bottom: 6px;"><i class="fas fa-truck"></i> TOMORROW'S DELIVERIES (<?= count($summary_stats['tomorrow_deliveries']) ?>)</div>
                <?php if (empty($summary_stats['tomorrow_deliveries'])): ?>
                    <div style="font-size: 11px; color: #5f6368;">No deliveries tomorrow</div>
                <?php else: ?>
                    <div style="max-height: 120px; overflow-y: auto;">
                        <?php foreach($summary_stats['tomorrow_deliveries'] as $tdel): ?>
                        <div style="font-size: 11px; border-bottom: 1px solid #feefc3; padding: 4px 0;">
                            <div style="font-weight: 600; color: #b06000;"><?= htmlspecialchars($tdel['supplier'] ?? 'Supplier') ?></div>
                            <div style="color: #5f6368; font-size: 10px;"><?= htmlspecialchars($tdel['product']) ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Upcoming PMS Panel -->
            <?php if (!empty($summary_stats['upcoming_pms'])): ?>
            <div style="background: #e8f0fe; border-radius: 8px; padding: 12px; margin-bottom: 12px;">
                <div style="font-size: 12px; color: #1a73e8; font-weight: 600; margin-bottom: 6px;"><i class="fas fa-car-side"></i> UPCOMING PMS (<?= count($summary_stats['upcoming_pms']) ?>)</div>
                <div style="max-height: 120px; overflow-y: auto;">
                    <?php foreach($summary_stats['upcoming_pms'] as $pms): ?>
                    <div style="font-size: 11px; border-bottom: 1px solid #d2e3fc; padding: 4px 0;">
                        <div style="font-weight: 600; color: #1a73e8;"><?= htmlspecialchars($pms['customer_name']) ?> (<?= htmlspecialchars($pms['plate_number'] ?? 'N/A') ?>)</div>
                        <div style="color: #5f6368; font-size: 10px;"><?= htmlspecialchars($pms['service_type']) ?> - <?= $pms['jo_date'] ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Manager Action Items -->
            <div style="background: #fce8e6; border-radius: 8px; padding: 12px; margin-bottom: 12px;">
                <div style="font-size: 12px; color: #d93025; font-weight: 600; margin-bottom: 8px;"><i class="fas fa-circle"></i> ACTION REQUIRED</div>
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px;">
                    <div style="text-align: center;">
                        <div style="font-size: 18px; font-weight: 600; color: #d93025;"><?= $summary_stats['overdue_payments'] ?></div>
                        <div style="font-size: 9px; color: #5f6368;">Overdue Payments</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 18px; font-weight: 600; color: #d93025;"><?= $summary_stats['low_stock_items'] ?></div>
                        <div style="font-size: 9px; color: #5f6368;">Low Stock Items</div>
                    </div>
                </div>
            </div>
            
            <!-- Today's Station Events -->
            <div style="background: #e8f0fe; border-radius: 8px; padding: 12px; margin-bottom: 12px;">
                <div style="font-size: 12px; color: #1a73e8; font-weight: 600; margin-bottom: 8px;">TODAY'S STATION EVENTS</div>
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px;">
                    <div style="text-align: center;">
                        <div style="font-size: 20px; font-weight: 600; color: #1a73e8;"><?= $summary_stats['today_shifts'] ?></div>
                        <div style="font-size: 10px; color: #5f6368;">Shifts</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 20px; font-weight: 600; color: #1a73e8;"><?= $summary_stats['today_job_orders'] ?></div>
                        <div style="font-size: 10px; color: #5f6368;">Job Orders</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 20px; font-weight: 600; color: #1a73e8;"><?= $summary_stats['today_deliveries'] ?></div>
                        <div style="font-size: 10px; color: #5f6368;">Deliveries</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 20px; font-weight: 600; color: #1a73e8;"><?= $summary_stats['today_events'] ?></div>
                        <div style="font-size: 10px; color: #5f6368;">Other</div>
                    </div>
                </div>
            </div>

            <!-- Staff Workload Today -->
            <?php if (!empty($summary_stats['staff_workload'])): ?>
            <div style="background: #f1f3f4; border-radius: 8px; padding: 12px; margin-bottom: 12px;">
                <div style="font-size: 12px; color: #5f6368; font-weight: 600; margin-bottom: 8px;"><i class="fas fa-users"></i> STAFF WORKLOAD (TODAY)</div>
                <div style="max-height: 150px; overflow-y: auto;">
                    <?php foreach(array_slice($summary_stats['staff_workload'], 0, 5) as $staff_work): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 4px 0; border-bottom: 1px solid #e0e0e0;">
                        <div style="font-size: 11px; color: #3c4043; flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            <?= htmlspecialchars($staff_work['name']) ?>
                        </div>
                        <div style="font-size: 11px; font-weight: 600; color: <?= $staff_work['event_count'] > 3 ? '#d93025' : ($staff_work['event_count'] > 1 ? '#ea8600' : '#188038') ?>; margin-left: 8px;">
                            <?= $staff_work['event_count'] ?> tasks
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- This Week Status -->
            <div style="background: #e8f5e9; border-radius: 8px; padding: 12px; margin-bottom: 12px;">
                <div style="font-size: 12px; color: #188038; font-weight: 600; margin-bottom: 8px;">THIS WEEK STATUS</div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                    <span style="font-size: 11px; color: #5f6368;">Pending</span>
                    <span style="font-size: 11px; font-weight: 600; color: #f9ab00;"><?= $summary_stats['week_pending'] ?></span>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                    <span style="font-size: 11px; color: #5f6368;">Approved</span>
                    <span style="font-size: 11px; font-weight: 600; color: #1a73e8;"><?= $summary_stats['week_in_progress'] ?></span>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="font-size: 11px; color: #5f6368;">Completed</span>
                    <span style="font-size: 11px; font-weight: 600; color: #188038;"><?= $summary_stats['week_completed'] ?></span>
                </div>
            </div>

            <?php if (count($summary_stats['conflicts']) > 0): ?>
            <!-- Conflicts Warning -->
            <div style="background: #fce8e6; border-radius: 8px; padding: 12px; margin-bottom: 12px;">
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                    <i class="fas fa-exclamation-triangle" style="color: #d93025;"></i>
                    <span style="font-size: 12px; color: #d93025; font-weight: 600;">SCHEDULE CONFLICTS</span>
                </div>
                <div style="font-size: 11px; color: #5f6368;"><?= count($summary_stats['conflicts']) ?> overlapping event(s) detected for staff.</div>
                <button onclick="showConflicts()" style="margin-top: 8px; padding: 6px 12px; background: #d93025; color: #fff; border: none; border-radius: 4px; font-size: 11px; cursor: pointer; width: 100%;">
                    Review Conflicts
                </button>
            </div>
            <?php endif; ?>
        <!-- Staff Shift Overview Legend -->
        <div class="cal-calendars" style="padding: 12px; border-top: 1px solid #dadce0;">
            <div class="cal-calendars-title" style="font-size: 12px; font-weight: 600; color: #3c4043; margin-bottom: 8px;">
                <i class="fas fa-users"></i> STATION SHIFT COLOR CODES
            </div>
            <div style="display: flex; flex-direction: column; gap: 6px; font-size: 11px; color: #3c4043;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <div style="width: 12px; height: 12px; background: #2563eb; border-radius: 3px;"></div>
                    <span style="font-weight: 600;">Shift 1 Staff</span> <span style="font-size: 10px; color: #64748b;">(06:00 - 14:00)</span>
                </div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <div style="width: 12px; height: 12px; background: #9333ea; border-radius: 3px;"></div>
                    <span style="font-weight: 600;">Shift 2 Staff</span> <span style="font-size: 10px; color: #64748b;">(14:00 - 22:00)</span>
                </div>
            </div>
        </div>    </div>
    </div>

    <!-- Main calendar -->
    <div class="cal-main">
        <!-- Header -->
        <div class="cal-header">
            <div class="cal-header-left">
                <h1 class="cal-month-title"><?= htmlspecialchars($view_title) ?></h1>
            </div>
            <div class="cal-header-right">
                <a href="manager_calendar.php?view=<?= $current_view ?>&month_offset=<?= $prev_offset ?>" class="cal-icon-btn" title="Previous">
                    <i class="fas fa-chevron-left"></i>
                </a>
                <a href="manager_calendar.php?view=<?= $current_view ?>&month_offset=0" class="cal-view-btn">Today</a>
                <a href="manager_calendar.php?view=<?= $current_view ?>&month_offset=<?= $next_offset ?>" class="cal-icon-btn" title="Next">
                    <i class="fas fa-chevron-right"></i>
                </a>
                <div style="position: relative;">
                    <button class="cal-view-btn" onclick="toggleViewDropdown(event)">
                        <?= ucfirst($current_view) ?> <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="cal-view-dropdown" id="viewDropdown">
                        <div class="cal-view-option <?= $current_view === 'day' ? 'active' : '' ?>" onclick="selectView('day')">
                            <span>Day</span>
                            <span class="shortcut">D</span>
                        </div>
                        <div class="cal-view-option <?= $current_view === 'week' ? 'active' : '' ?>" onclick="selectView('week')">
                            <span>Week</span>
                            <span class="shortcut">W</span>
                        </div>
                        <div class="cal-view-option <?= $current_view === 'month' ? 'active' : '' ?>" onclick="selectView('month')">
                            <span>Month</span>
                            <span class="shortcut">M</span>
                        </div>
                        <div class="cal-view-option <?= $current_view === 'year' ? 'active' : '' ?>" onclick="selectView('year')">
                            <span>Year</span>
                            <span class="shortcut">Y</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Calendar content -->
        <div class="cal-content">
            <div class="cal-grid-container">
                <?php if ($current_view === 'month'): ?>
                <!-- Month View -->
                <!-- Weekdays -->
                <div class="cal-weekdays">
                    <div class="cal-weekday">SUN</div>
                    <div class="cal-weekday">MON</div>
                    <div class="cal-weekday">TUE</div>
                    <div class="cal-weekday">WED</div>
                    <div class="cal-weekday">THU</div>
                    <div class="cal-weekday">FRI</div>
                    <div class="cal-weekday">SAT</div>
                </div>

                <!-- Calendar Grid -->
                <div class="cal-grid">
                    <?php 
                    // Adjust calendar_dates to start with Sunday
                    $calendar_start_adjusted = clone $first_day;
                    $start_weekday = (int)$calendar_start_adjusted->format('w'); // 0=Sunday
                    if ($start_weekday > 0) {
                        $calendar_start_adjusted->modify('-' . $start_weekday . ' days');
                    }
                    
                    $calendar_end_adjusted = clone $last_day;
                    $end_weekday = (int)$calendar_end_adjusted->format('w');
                    if ($end_weekday < 6) {
                        $calendar_end_adjusted->modify('+' . (6 - $end_weekday) . ' days');
                    }
                    
                    $calendar_dates_adjusted = [];
                    $current_date = clone $calendar_start_adjusted;
                    while ($current_date <= $calendar_end_adjusted) {
                        $calendar_dates_adjusted[] = $current_date->format('Y-m-d');
                        $current_date->modify('+1 day');
                    }
                    
                    foreach($calendar_dates_adjusted as $date):
                        $day_num = date('j', strtotime($date));
                        $is_today = ($date === $today_str);
                        $is_other_month = (substr($date, 0, 7) !== $current_month_str);
                        $day_events = $month_events[$date] ?? [];
                        
                        $day_class = 'cal-day';
                        if ($is_today) $day_class .= ' today';
                        if ($is_other_month) $day_class .= ' other-month';
                    ?>
                    <div class="<?= $day_class ?>" data-date="<?= $date ?>" onclick="clickDay('<?= $date ?>')" style="cursor: pointer;">
                        <div class="cal-day-num"><?= $day_num ?></div>
                        <div class="cal-events">
                            <?php 
                            $display_limit = 4;
                            $displayed = 0;
                            foreach($day_events as $event):
                                if ($displayed >= $display_limit) break;
                                $event_color = $event['color'] ?? '#757575';
                                $status = strtolower($event['status'] ?? 'pending');
                                $time_str = '';
                                if (!empty($event['start_time']) && $event['start_time'] != '00:00:00') {
                                    $time_str = date('g:ia', strtotime($event['start_time'])) . ' ';
                                }
                                $event_id = $event['id'] ?? '';
                                $event_type = $event['type_key'] ?? '';
                                $staff_id = $event['staff_encoder_id'] ?? '';
                                $displayed++;
                            ?>
                            <div class="cal-event" 
                                 data-staff="<?= $staff_id ?>"
                                 data-type="<?= htmlspecialchars($event_type) ?>"
                                 data-status="<?= htmlspecialchars($status) ?>"
                                 data-date="<?= $date ?>"
                                 style="background: <?= $event_color ?>22; border-left-color: <?= $event_color ?>;" 
                                 title="<?= htmlspecialchars($event['staff_name'] ?? '') ?> - <?= htmlspecialchars($event['work_description'] ?? $event['type_name']) ?>"
                                 onclick="event.stopPropagation(); clickEvent('<?= htmlspecialchars($event_id) ?>', '<?= htmlspecialchars($event_type) ?>', '<?= htmlspecialchars($event['target_url'] ?? '#') ?>')">
                                <?php if ($time_str): ?>
                                <span class="cal-event-time"><?= $time_str ?></span>
                                <?php endif; ?>
                                <span class="cal-event-text">
                                    <?= htmlspecialchars($event['work_description'] ?? $event['type_name']) ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                            <?php if (count($day_events) > $display_limit): ?>
                            <div class="cal-more" onclick="event.stopPropagation(); clickDay('<?= $date ?>')">+<?= count($day_events) - $display_limit ?> more</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <?php elseif ($current_view === 'week'): ?>
                <!-- Week View -->
                <div class="cal-weekdays">
                    <?php
                    $week_start_date = new DateTime($view_start);
                    for ($i = 0; $i < 7; $i++):
                        $day_label = $week_start_date->format('D j');
                    ?>
                    <div class="cal-weekday"><?= $day_label ?></div>
                    <?php
                        $week_start_date->modify('+1 day');
                    endfor;
                    ?>
                </div>
                <div class="cal-grid" style="grid-template-columns: repeat(7, minmax(0, 1fr)); grid-auto-rows: 150px;">
                    <?php
                    $week_date = new DateTime($view_start);
                    for ($i = 0; $i < 7; $i++):
                        $date = $week_date->format('Y-m-d');
                        $is_today = ($date === $today_str);
                        $day_events = $month_events[$date] ?? [];
                    ?>
                    <div class="cal-day <?= $is_today ? 'today' : '' ?>" data-date="<?= $date ?>" onclick="clickDay('<?= $date ?>')" style="cursor: pointer;">
                        <div class="cal-day-num"><?= $week_date->format('j') ?></div>
                        <div class="cal-events">
                            <?php foreach($day_events as $event):
                                $event_color = $event['color'] ?? '#757575';
                                $status = strtolower($event['status'] ?? 'pending');
                                $time_str = !empty($event['start_time']) && $event['start_time'] != '00:00:00' ? date('g:ia', strtotime($event['start_time'])) . ' ' : '';
                                $event_id = $event['id'] ?? '';
                                $event_type = $event['type_key'] ?? '';
                                $staff_id = $event['staff_encoder_id'] ?? '';
                            ?>
                            <div class="cal-event" 
                                 data-staff="<?= $staff_id ?>"
                                 data-type="<?= htmlspecialchars($event_type) ?>"
                                 data-status="<?= htmlspecialchars($status) ?>"
                                 data-date="<?= $date ?>"
                                 style="background: <?= $event_color ?>22; border-left-color: <?= $event_color ?>;" 
                                 onclick="event.stopPropagation(); clickEvent('<?= htmlspecialchars($event_id) ?>', '<?= htmlspecialchars($event_type) ?>', '<?= htmlspecialchars($event['target_url'] ?? '#') ?>')">
                                <?php if ($time_str): ?><span class="cal-event-time"><?= $time_str ?></span><?php endif; ?>
                                <span class="cal-event-text"><?= htmlspecialchars($event['work_description'] ?? $event['type_name']) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php
                        $week_date->modify('+1 day');
                    endfor;
                    ?>
                </div>
                
                <?php elseif ($current_view === 'day'): ?>
                <!-- Day View -->
                <div style="padding: 20px;">
                    <h2 style="margin-bottom: 20px; color: #3c4043;"><?= $today->format('l, F j, Y') ?></h2>
                    <?php 
                    $day_events = $month_events[$today_str] ?? [];
                    if (empty($day_events)):
                    ?>
                    <div style="text-align: center; padding: 40px; color: #5f6368;">
                        <i class="fas fa-calendar-day" style="font-size: 48px; margin-bottom: 16px;"></i>
                        <div>No events scheduled for today</div>
                    </div>
                    <?php else: ?>
                    <div style="max-width: 800px;">
                        <?php foreach($day_events as $event):
                            $event_color = $event['color'] ?? '#757575';
                            $time_range = '';
                            if (!empty($event['start_time']) && $event['start_time'] != '00:00:00') {
                                $time_range = date('g:i A', strtotime($event['start_time']));
                                if (!empty($event['end_time']) && $event['end_time'] != '00:00:00') {
                                    $time_range .= ' - ' . date('g:i A', strtotime($event['end_time']));
                                }
                            }
                        ?>
                        <div style="border-left: 4px solid <?= $event_color ?>; background: <?= $event_color ?>11; padding: 16px; margin-bottom: 12px; border-radius: 4px; cursor: pointer;"
                             onclick="clickEvent('<?= htmlspecialchars($event['id'] ?? '') ?>', '<?= htmlspecialchars($event['type_key'] ?? '') ?>')">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                <div style="font-weight: 600; color: #3c4043;"><?= htmlspecialchars($event['work_description'] ?? $event['type_name']) ?></div>
                                <div style="color: #5f6368; font-size: 12px;"><?= $time_range ?></div>
                            </div>
                            <div style="color: #5f6368; font-size: 14px;">
                                <i class="fas fa-user"></i> <?= htmlspecialchars($event['staff_name'] ?? 'Unknown') ?> 
                                <span style="margin-left: 16px;"><i class="fas fa-tag"></i> <?= htmlspecialchars($event['type_name']) ?></span>
                                <span style="margin-left: 16px;"><i class="fas fa-circle" style="font-size: 8px;"></i> <?= ucfirst($event['status'] ?? 'pending') ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php elseif ($current_view === 'year'): ?>
                <!-- Year View -->
                <div style="padding: 20px; display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                    <?php
                    for ($m = 1; $m <= 12; $m++):
                        $month_date = new DateTime($today->format('Y') . '-' . str_pad($m, 2, '0', STR_PAD_LEFT) . '-01');
                        $month_name_short = $month_date->format('F');
                        $days_in_month = $month_date->format('t');
                        $first_day_of_week = (int)$month_date->format('w');
                    ?>
                    <div style="border: 1px solid #dadce0; border-radius: 8px; padding: 12px; background: #fff;">
                        <div style="font-weight: 600; margin-bottom: 8px; color: #3c4043; text-align: center;"><?= $month_name_short ?></div>
                        <div style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; font-size: 10px;">
                            <?php foreach(['S','M','T','W','T','F','S'] as $d): ?>
                            <div style="text-align: center; color: #5f6368; font-weight: 500;"><?= $d ?></div>
                            <?php endforeach; ?>
                            <?php for ($i = 0; $i < $first_day_of_week; $i++): ?>
                            <div></div>
                            <?php endfor; ?>
                            <?php for ($d = 1; $d <= $days_in_month; $d++):
                                $date_str = $month_date->format('Y-m') . '-' . str_pad($d, 2, '0', STR_PAD_LEFT);
                                $has_events = isset($month_events[$date_str]) && count($month_events[$date_str]) > 0;
                                $is_today_date = ($date_str === $today_str);
                            ?>
                            <div style="text-align: center; padding: 4px; <?= $is_today_date ? 'background: #1a73e8; color: #fff; border-radius: 50%;' : ($has_events ? 'font-weight: 600; color: #1a73e8;' : 'color: #3c4043;') ?>">
                                <?= $d ?>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Toggle view dropdown
function toggleViewDropdown(event) {
    event.stopPropagation();
    const dropdown = document.getElementById('viewDropdown');
    dropdown.classList.toggle('show');
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('viewDropdown');
    if (dropdown && !event.target.closest('.cal-view-btn')) {
        dropdown.classList.remove('show');
    }
});

// View selection
function selectView(view) {
    const currentUrl = new URL(window.location.href);
    currentUrl.searchParams.set('view', view);
    currentUrl.searchParams.set('month_offset', '<?= $month_offset ?>');
    window.location.href = currentUrl.toString();
}

// Keyboard shortcuts
document.addEventListener('keydown', function(event) {
    if (event.target.tagName === 'INPUT' || event.target.tagName === 'TEXTAREA') return;
    
    switch(event.key.toLowerCase()) {
        case 'd':
            selectView('day');
            break;
        case 'w':
            selectView('week');
            break;
        case 'm':
            selectView('month');
            break;
        case 'y':
            selectView('year');
            break;
    }
});

// Create event button
function createEvent() {
    const today = new Date().toISOString().split('T')[0];
    showEventModal(today);
}

// Show event modal
function showEventModal(date = null, eventData = null) {
    const modal = document.getElementById('eventModal');
    const form = document.getElementById('eventForm');
    const title = document.getElementById('modalTitle');
    
    if (eventData) {
        // Edit existing event
        title.textContent = 'Edit Event';
        document.getElementById('eventId').value = eventData.id || '';
        document.getElementById('eventDate').value = eventData.event_date || date;
        document.getElementById('eventType').value = eventData.type_key || '';
        document.getElementById('eventDescription').value = eventData.work_description || '';
        document.getElementById('eventStartTime').value = eventData.start_time || '';
        document.getElementById('eventEndTime').value = eventData.end_time || '';
        document.getElementById('eventStatus').value = eventData.status || 'pending';
        
        // Trigger dynamic fields population
        handleEventTypeChange();
        
        // Populate dynamic fields based on event type
        setTimeout(() => {
            const dynamicFields = document.getElementById('dynamicFields');
            
            // Populate all dynamic field values
            if (eventData.shift_type) {
                const shiftType = dynamicFields.querySelector('[name="shift_type"]');
                if (shiftType) shiftType.value = eventData.shift_type;
            }
            if (eventData.shift_status) {
                const shiftStatus = dynamicFields.querySelector('[name="shift_status"]');
                if (shiftStatus) shiftStatus.value = eventData.shift_status;
            }
            if (eventData.service_type) {
                const serviceType = dynamicFields.querySelector('[name="service_type"]');
                if (serviceType) serviceType.value = eventData.service_type;
            }
            if (eventData.customer_name) {
                const customerName = dynamicFields.querySelector('[name="customer_name"]');
                if (customerName) customerName.value = eventData.customer_name;
            }
            if (eventData.job_status) {
                const jobStatus = dynamicFields.querySelector('[name="job_status"]');
                if (jobStatus) jobStatus.value = eventData.job_status;
            }
            if (eventData.supplier) {
                const supplier = dynamicFields.querySelector('[name="supplier"]');
                if (supplier) supplier.value = eventData.supplier;
            }
            if (eventData.product) {
                const product = dynamicFields.querySelector('[name="product"]');
                if (product) product.value = eventData.product;
            }
            if (eventData.expected_qty) {
                const expectedQty = dynamicFields.querySelector('[name="expected_qty"]');
                if (expectedQty) expectedQty.value = eventData.expected_qty;
            }
            if (eventData.actual_qty) {
                const actualQty = dynamicFields.querySelector('[name="actual_qty"]');
                if (actualQty) actualQty.value = eventData.actual_qty;
            }
            if (eventData.pump_number) {
                const pumpNumber = dynamicFields.querySelector('[name="pump_number"]');
                if (pumpNumber) pumpNumber.value = eventData.pump_number;
            }
            if (eventData.expected_reading) {
                const expectedReading = dynamicFields.querySelector('[name="expected_reading"]');
                if (expectedReading) expectedReading.value = eventData.expected_reading;
            }
            if (eventData.actual_reading) {
                const actualReading = dynamicFields.querySelector('[name="actual_reading"]');
                if (actualReading) actualReading.value = eventData.actual_reading;
            }
            if (eventData.variance !== undefined) {
                const variance = dynamicFields.querySelector('[name="variance"]');
                if (variance) {
                    const pct = eventData.variance_percent || 0;
                    variance.value = `${eventData.variance.toFixed(2)} L (${pct.toFixed(2)}%)`;
                }
            }
            if (eventData.customer_id) {
                const customerId = dynamicFields.querySelector('[name="customer_id"]');
                if (customerId) customerId.value = eventData.customer_id;
            }
            if (eventData.amount) {
                const amount = dynamicFields.querySelector('[name="amount"]');
                if (amount) amount.value = eventData.amount;
            }
            if (eventData.payment_status) {
                const paymentStatus = dynamicFields.querySelector('[name="payment_status"]');
                if (paymentStatus) paymentStatus.value = eventData.payment_status;
            }
        }, 100);
    } else {
        // Create new event
        title.textContent = 'Create Event';
        form.reset();
        document.getElementById('eventDate').value = date || new Date().toISOString().split('T')[0];
        document.getElementById('dynamicFields').innerHTML = '';
    }
    
    modal.style.display = 'flex';
}

// Close modal
function closeModal() {
    document.getElementById('eventModal').style.display = 'none';
}

// Global events dictionary for lookup
const allCalendarEvents = <?= json_encode($month_events) ?>;
const activeStaffList = <?= json_encode($staff_list) ?>;

// Click on event — Always opens comprehensive Details & Action Modal
function clickEvent(eventId, eventType, targetUrl) {
    const match = eventId ? eventId.toString().match(/\d+$/) : null;
    const numericId = match ? match[0] : eventId;
    
    // Find the event in allCalendarEvents
    let foundEvent = null;
    for (const date in allCalendarEvents) {
        const evts = allCalendarEvents[date];
        const matchEvt = evts.find(e => e.id.toString() === eventId.toString());
        if (matchEvt) {
            foundEvent = JSON.parse(JSON.stringify(matchEvt));
            break;
        }
    }

    if (!foundEvent) {
        // Fallback for manual events
        fetch('manager_calendar.php?action=get_event&event_id=' + eventId)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showEventModal(null, data.event);
                } else {
                    alert('Event not found');
                }
            })
            .catch(e => alert('Error loading event'));
        return;
    }

    if (targetUrl && targetUrl !== '#') {
        foundEvent.target_url = targetUrl;
    }

    // Show manager details & validation modal
    showManagerDetailsModal(foundEvent);
}

function showManagerDetailsModal(evt) {
    const modal = document.getElementById('detailsModal');
    const title = document.getElementById('detailsTitle');
    const body = document.getElementById('detailsBody');
    const actions = document.getElementById('detailsActions');
    
    let typeName = evt.type_name || 'Event Details';
    title.textContent = 'Manage ' + typeName;
    
    let status = evt.status || 'pending';
    let badgeColor = '#ea8600'; // Orange
    if (status === 'completed' || status === 'approved' || status === 'verified') badgeColor = '#188038'; // Green
    if (status === 'cancelled' || status === 'rejected') badgeColor = '#d93025'; // Red
    
    let html = `
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <span style="font-weight:600; font-size:12px; text-transform:uppercase; color:#70757a;">Event Date</span>
            <span style="font-weight:500;">${evt.event_date || evt.scheduled_date}</span>
        </div>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <span style="font-weight:600; font-size:12px; text-transform:uppercase; color:#70757a;">Status</span>
            <span style="background:${badgeColor}22; color:${badgeColor}; padding:4px 8px; border-radius:4px; font-weight:600; font-size:11px; text-transform:uppercase;">${status}</span>
        </div>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <span style="font-weight:600; font-size:12px; text-transform:uppercase; color:#70757a;">Staff / Encoder</span>
            <span style="font-weight:500;">${evt.staff_name || 'System Auto-schedule'}</span>
        </div>
        <div style="margin-bottom:16px; border-top:1px solid #dadce0; padding-top:16px;">
            <span style="font-weight:600; font-size:12px; text-transform:uppercase; color:#70757a; display:block; margin-bottom:4px;">Description</span>
            <div style="background:#f1f3f4; padding:12px; border-radius:4px; font-size:13px;">${evt.work_description || 'No description provided.'}</div>
        </div>
    `;

    // Type specific info & adjustments
    const numericId = evt.id.toString().match(/\d+$/) ? evt.id.toString().match(/\d+$/)[0] : evt.id;
    
    if (evt.type_key === 'merchandise_delivery' || evt.type_key === 'fuel_delivery') {
        html += `
            <div style="margin-top:16px; padding-top:16px; border-top:1px solid #dadce0;">
                <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                    <span style="font-weight:600; font-size:12px; color:#70757a;">Supplier:</span>
                    <span>${evt.supplier || 'N/A'}</span>
                </div>
                <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                    <span style="font-weight:600; font-size:12px; color:#70757a;">Product:</span>
                    <span>${evt.product || 'N/A'}</span>
                </div>
            </div>
        `;
    } else if (evt.type_key === 'job_order') {
        html += `
            <div style="margin-top:16px; padding-top:16px; border-top:1px solid #dadce0;">
                <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                    <span style="font-weight:600; font-size:12px; color:#70757a;">Customer:</span>
                    <span>${evt.customer_name || 'N/A'}</span>
                </div>
                <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                    <span style="font-weight:600; font-size:12px; color:#70757a;">Service Type:</span>
                    <span>${evt.service_type || 'N/A'}</span>
                </div>
                <div style="margin-top:12px; padding-top:12px; border-top:1px dashed #dadce0;">
                    <label style="font-weight:600; font-size:12px; color:#70757a; display:block; margin-bottom:6px;">Re-assign Mechanic / Staff</label>
                    <select id="reassignSelect" style="width:100%; padding:8px; border:1px solid #dadce0; border-radius:4px; font-size:13px;">
                        <option value="">Select new staff...</option>
                        ${Object.keys(activeStaffList).map(id => `<option value="${id}" ${evt.staff_encoder_id == id ? 'selected' : ''}>${activeStaffList[id].name}</option>`).join('')}
                    </select>
                    <button onclick="submitManagerReassign('job_order', '${numericId}')" style="margin-top:8px; padding:6px 12px; border:none; background:#1a73e8; color:#fff; border-radius:4px; font-size:11px; cursor:pointer;">Apply Re-assignment</button>
                </div>
            </div>
        `;
    } else if (evt.type_key === 'staff_shift') {
        html += `
            <div style="margin-top:16px; padding-top:16px; border-top:1px solid #dadce0;">
                <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                    <span style="font-weight:600; font-size:12px; color:#70757a;">Shift Hours:</span>
                    <span>${evt.start_time || '00:00'} - ${evt.end_time || '00:00'}</span>
                </div>
                <div style="margin-top:12px; padding-top:12px; border-top:1px dashed #dadce0;">
                    <label style="font-weight:600; font-size:12px; color:#70757a; display:block; margin-bottom:6px;">Adjust Schedule / Re-assign Staff</label>
                    <select id="reassignSelect" style="width:100%; padding:8px; border:1px solid #dadce0; border-radius:4px; font-size:13px;">
                        <option value="">Select new staff...</option>
                        ${Object.keys(activeStaffList).map(id => `<option value="${id}" ${evt.staff_encoder_id == id ? 'selected' : ''}>${activeStaffList[id].name}</option>`).join('')}
                    </select>
                    <button onclick="submitManagerReassign('staff_shift', '${numericId}')" style="margin-top:8px; padding:6px 12px; border:none; background:#1a73e8; color:#fff; border-radius:4px; font-size:11px; cursor:pointer;">Apply Re-assignment</button>
                </div>
            </div>
        `;
    } else if (evt.type_key === 'fuel_calibration') {
        html += `
            <div style="margin-top:16px; padding-top:16px; border-top:1px solid #dadce0;">
                <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                    <span style="font-weight:600; font-size:12px; color:#70757a;">Pump / Tank:</span>
                    <span>${evt.pump_number || 'N/A'}</span>
                </div>
            </div>
        `;
    }

    body.innerHTML = html;

    // ─── ACTION BUTTONS ─────────────────────────────────────────────
    // Always: <i class="fas fa-eye"></i> View  <i class="fas fa-pencil-alt"></i> Reschedule  <i class="fas fa-user"></i> Assign/Reassign
    const isAutoSynced = evt.auto_synced || false;
    const hasNumericId = numericId && !isNaN(numericId);

    // Build the reschedule date picker inline
    const reschedulePicker = `
        <div id="reschedulePanel" style="display:none; margin-top:12px; padding:12px; background:#f8f9fa; border-radius:8px; border:1px solid #dadce0;">
            <label style="font-size:12px; font-weight:600; color:#3c4043; display:block; margin-bottom:6px;"><i class="fas fa-pencil-alt"></i> New Date:</label>
            <input type="date" id="rescheduleDate" value="${evt.event_date || evt.del_date || ''}" style="width:100%; padding:8px; border:1px solid #dadce0; border-radius:4px; font-size:13px; margin-bottom:8px;">
            <button onclick="submitReschedule('${numericId}', '${evt.type_key || ''}')" style="width:100%; padding:8px; border:none; background:#1a73e8; color:#fff; border-radius:4px; font-size:13px; cursor:pointer; font-weight:500;">
                Confirm Reschedule
            </button>
        </div>
    `;

    // Build assign/reassign staff picker
    const reassignPanel = `
        <div id="reassignPanel" style="display:none; margin-top:12px; padding:12px; background:#f8f9fa; border-radius:8px; border:1px solid #dadce0;">
            <label style="font-size:12px; font-weight:600; color:#3c4043; display:block; margin-bottom:6px;"><i class="fas fa-user"></i> Select Mechanic / Staff:</label>
            <select id="reassignSelectNew" style="width:100%; padding:8px; border:1px solid #dadce0; border-radius:4px; font-size:13px; margin-bottom:8px;">
                <option value="">-- Select --</option>
                ${Object.keys(activeStaffList).map(id => `<option value="${id}" ${evt.staff_encoder_id == id ? 'selected' : ''}>${activeStaffList[id].name}</option>`).join('')}
            </select>
            <button onclick="submitReassignNew('${numericId}', '${evt.type_key || ''}')" style="width:100%; padding:8px; border:none; background:#1a73e8; color:#fff; border-radius:4px; font-size:13px; cursor:pointer; font-weight:500;">
                Confirm Assignment
            </button>
        </div>
    `;

    // Insert panels into body
    body.innerHTML += reschedulePicker + reassignPanel;

    let actionButtons = `
        <button type="button" onclick="closeDetailsModal()" style="padding:10px 16px; border:1px solid #dadce0; background:#fff; color:#3c4043; border-radius:4px; font-size:13px; cursor:pointer; font-weight:500;">
            <i class="fas fa-times"></i> Close
        </button>
    `;

    // <i class="fas fa-eye"></i> View — link to source record
    let viewUrl = '#';
    if (evt.type_key === 'job_order') viewUrl = `../public/manager_validated_transactions.php?type=job_order&search=${numericId}`;
    else if (evt.type_key === 'merchandise_delivery') viewUrl = `../public/manager_deliveries.php?id=${numericId}`;
    else if (evt.type_key === 'fuel_delivery') viewUrl = `../public/manager_fuel_delivery.php?id=${numericId}`;
    else if (evt.type_key === 'staff_shift') viewUrl = `../public/manager_staff_schedule.php`;

    if (viewUrl !== '#') {
        actionButtons += `
            <a href="${viewUrl}" target="_blank" style="padding:10px 16px; border:1px solid #1a73e8; background:#fff; color:#1a73e8; border-radius:4px; font-size:13px; cursor:pointer; font-weight:500; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                <i class="fas fa-eye"></i> View
            </a>
        `;
    }

    // <i class="fas fa-pencil-alt"></i> Reschedule — toggle panel
    if (!isAutoSynced || evt.type_key === 'staff_shift' || evt.type_key === 'job_order') {
        actionButtons += `
            <button type="button" onclick="document.getElementById('reschedulePanel').style.display = document.getElementById('reschedulePanel').style.display === 'none' ? 'block' : 'none';" style="padding:10px 16px; border:1px solid #ea8600; background:#fff; color:#ea8600; border-radius:4px; font-size:13px; cursor:pointer; font-weight:500;">
                <i class="fas fa-pencil-alt"></i> Reschedule
            </button>
        `;
    }

    // <i class="fas fa-user"></i> Assign/Reassign Mechanic
    if (evt.type_key === 'job_order' || evt.type_key === 'staff_shift' || evt.type_key === 'merchandise_delivery') {
        actionButtons += `
            <button type="button" onclick="document.getElementById('reassignPanel').style.display = document.getElementById('reassignPanel').style.display === 'none' ? 'block' : 'none';" style="padding:10px 16px; border:1px solid #8e24aa; background:#fff; color:#8e24aa; border-radius:4px; font-size:13px; cursor:pointer; font-weight:500;">
                <i class="fas fa-user"></i> Assign/Reassign
            </button>
        `;
    }

    // Validation decisions
    if (evt.type_key === 'job_order') {
        actionButtons += `
            <button type="button" onclick="submitManagerAction('job_order', '${numericId}', 'approve')" style="padding:10px 16px; border:none; background:#188038; color:#fff; border-radius:4px; font-size:13px; cursor:pointer; font-weight:500;">
                <i class="fas fa-check"></i> Approve
            </button>
            <button type="button" onclick="submitManagerAction('job_order', '${numericId}', 'reject')" style="padding:10px 16px; border:none; background:#d93025; color:#fff; border-radius:4px; font-size:13px; cursor:pointer; font-weight:500;">
                <i class="fas fa-times"></i> Reject
            </button>
        `;
    } else if (evt.type_key === 'merchandise_delivery' || evt.type_key === 'fuel_delivery') {
        actionButtons += `
            <button type="button" onclick="submitManagerAction('delivery', '${numericId}', 'approve')" style="padding:10px 16px; border:none; background:#188038; color:#fff; border-radius:4px; font-size:13px; cursor:pointer; font-weight:500;">
                <i class="fas fa-check"></i> Approve
            </button>
            <button type="button" onclick="submitManagerAction('delivery', '${numericId}', 'reject')" style="padding:10px 16px; border:none; background:#d93025; color:#fff; border-radius:4px; font-size:13px; cursor:pointer; font-weight:500;">
                <i class="fas fa-times"></i> Reject
            </button>
        `;
    } else if (evt.type_key === 'fuel_calibration') {
        actionButtons += `
            <button type="button" onclick="submitManagerAction('fuel_calibration', '${numericId}', 'validate')" style="padding:10px 16px; border:none; background:#188038; color:#fff; border-radius:4px; font-size:13px; cursor:pointer; font-weight:500;">
                <i class="fas fa-check"></i> Validate
            </button>
        `;
    }

    actions.innerHTML = actionButtons;
    modal.style.display = 'flex';
}

function submitManagerAction(evtType, numericId, subAction) {
    let reason = '';
    if (subAction === 'reject' || subAction === 'return') {
        reason = prompt("Please provide notes/reason for this action:");
        if (reason === null) return; // cancelled
        if (!reason.trim()) {
            alert("A reason is required to reject/return.");
            return;
        }
    }
    
    const formData = new FormData();
    formData.append('action', 'validate_event');
    formData.append('evt_type', evtType);
    formData.append('numeric_id', numericId);
    formData.append('sub_action', subAction);
    formData.append('reason', reason);
    
    fetch('manager_calendar.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('SUCCESS: ' + data.message);
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(e => alert('Network error processing request'));
}

function submitManagerReassign(evtType, numericId) {
    const select = document.getElementById('reassignSelect');
    const newStaffId = select.value;
    if (!newStaffId) {
        alert("Please select a staff member to re-assign.");
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'validate_event');
    formData.append('evt_type', evtType);
    formData.append('numeric_id', numericId);
    formData.append('sub_action', 'reassign');
    formData.append('new_staff_id', newStaffId);
    
    fetch('manager_calendar.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('SUCCESS: ' + data.message);
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(e => alert('Network error re-assigning staff'));
}

// <i class="fas fa-pencil-alt"></i> Reschedule handler
function submitReschedule(numericId, evtType) {
    const newDate = document.getElementById('rescheduleDate')?.value;
    if (!newDate) { alert('Please pick a new date.'); return; }
    const formData = new FormData();
    formData.append('action', 'validate_event');
    formData.append('evt_type', evtType);
    formData.append('numeric_id', numericId);
    formData.append('sub_action', 'reschedule');
    formData.append('new_date', newDate);
    fetch('manager_calendar.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) { alert('<i class="fas fa-check"></i> Rescheduled successfully!'); location.reload(); }
            else { alert('Error: ' + (data.message || 'Could not reschedule.')); }
        })
        .catch(() => alert('Network error rescheduling'));
}

// <i class="fas fa-user"></i> Reassign (new panel version)
function submitReassignNew(numericId, evtType) {
    const select = document.getElementById('reassignSelectNew');
    const newStaffId = select?.value;
    if (!newStaffId) { alert('Please select a staff member.'); return; }
    const formData = new FormData();
    formData.append('action', 'validate_event');
    formData.append('evt_type', evtType === 'staff_shift' ? 'staff_shift' : 'job_order');
    formData.append('numeric_id', numericId);
    formData.append('sub_action', 'reassign');
    formData.append('new_staff_id', newStaffId);
    fetch('manager_calendar.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) { alert('<i class="fas fa-check"></i> ' + data.message); location.reload(); }
            else { alert('Error: ' + (data.message || 'Could not reassign.')); }
        })
        .catch(() => alert('Network error reassigning'));
}

function closeDetailsModal() {
    document.getElementById('detailsModal').style.display = 'none';
}

// Click on day — Shows Day Overview modal with all events or directly opens Create Event modal
function clickDay(date) {
    const dayEvts = (allCalendarEvents && allCalendarEvents[date]) ? allCalendarEvents[date] : [];
    if (dayEvts.length > 0) {
        showDayOverviewModal(date, dayEvts);
    } else {
        showEventModal(date);
    }
}

function showDayOverviewModal(date, events) {
    const modal = document.getElementById('dayOverviewModal');
    if (!modal) {
        showEventModal(date);
        return;
    }
    const dObj = new Date(date + 'T00:00:00');
    const dateFormatted = dObj.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
    
    document.getElementById('dayOverviewTitle').textContent = dateFormatted;
    document.getElementById('dayOverviewSubtitle').textContent = events.length + ' scheduled event(s) and operational task(s)';
    
    const listEl = document.getElementById('dayOverviewList');
    listEl.innerHTML = events.map(evt => {
        const color = evt.color || '#0284c7';
        const st = (evt.status || 'pending').toUpperCase();
        let badgeBg = '#fef3c7', badgeColor = '#b45309';
        if (st === 'COMPLETED' || st === 'VERIFIED' || st === 'APPROVED') { badgeBg = '#dcfce7'; badgeColor = '#15803d'; }
        else if (st === 'CANCELLED' || st === 'REJECTED') { badgeBg = '#fee2e2'; badgeColor = '#b91c1c'; }
        
        let timeStr = '';
        if (evt.start_time && evt.start_time !== '00:00:00') {
            timeStr = evt.start_time.substring(0, 5) + (evt.end_time && evt.end_time !== '00:00:00' ? ' - ' + evt.end_time.substring(0, 5) : '');
        }

        return `
            <div style="background:#fff; border:1px solid #e2e8f0; border-left:4px solid ${color}; border-radius:8px; padding:12px 14px; box-shadow:0 1px 3px rgba(0,0,0,0.04); display:flex; justify-content:space-between; align-items:center; gap:12px;">
                <div style="flex:1; min-width:0;">
                    <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                        <span style="font-weight:700; font-size:13px; color:#0f172a;">${evt.work_description || evt.type_name}</span>
                        <span style="background:${badgeBg}; color:${badgeColor}; font-size:10px; font-weight:700; padding:2px 7px; border-radius:4px;">${st}</span>
                    </div>
                    <div style="font-size:11.5px; color:#64748b; display:flex; gap:14px; flex-wrap:wrap;">
                        ${timeStr ? `<span><i class="far fa-clock" style="color:#0284c7;"></i> ${timeStr}</span>` : ''}
                        <span><i class="far fa-user" style="color:#64748b;"></i> ${evt.staff_name || 'Staff'}</span>
                        <span><i class="fas fa-tag" style="color:#64748b;"></i> ${evt.type_name || 'Event'}</span>
                    </div>
                </div>
                <button type="button" onclick="closeDayOverviewModal(); clickEvent('${evt.id}', '${evt.type_key || ''}', '${evt.target_url || '#'}');" style="padding:7px 12px; background:#002F70; color:#fff; border:none; border-radius:6px; font-size:11.5px; font-weight:600; cursor:pointer; white-space:nowrap; display:flex; align-items:center; gap:5px;">
                    <i class="fas fa-cog"></i> Manage
                </button>
            </div>
        `;
    }).join('');

    document.getElementById('dayOverviewAddBtn').onclick = function() {
        closeDayOverviewModal();
        showEventModal(date);
    };

    modal.style.display = 'flex';
}

function closeDayOverviewModal() {
    const modal = document.getElementById('dayOverviewModal');
    if (modal) modal.style.display = 'none';
}

function filterByLegendType(typeKey) {
    const sel = document.getElementById('filterEventType');
    if (sel) {
        sel.value = typeKey;
        filterCalendarEvents();
    }
}

// Submit event form
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('eventForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(form);
            formData.append('action', 'save_event');
            
            // Show loading state
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn.textContent;
            submitBtn.textContent = 'Saving...';
            submitBtn.disabled = true;
            
            fetch('manager_calendar.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('SUCCESS: Event saved successfully!');
                    location.reload();
                } else if (data.conflict) {
                    // Show conflict warning
                    if (confirm('WARNING: ' + data.message + '\n\nDo you want to save anyway? (Not recommended)')) {
                        formData.append('force_save', '1');
                        // Retry with force flag
                        fetch('manager_calendar.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(r => r.json())
                        .then(data2 => {
                            if (data2.success) {
                                alert('SUCCESS: Event saved with conflict warning!');
                                location.reload();
                            } else {
                                alert('ERROR: ' + (data2.message || 'Failed to save event'));
                            }
                        });
                    } else {
                        submitBtn.textContent = originalText;
                        submitBtn.disabled = false;
                    }
                } else {
                    alert('ERROR: ' + (data.message || 'Failed to save event'));
                    submitBtn.textContent = originalText;
                    submitBtn.disabled = false;
                }
            })
            .catch(e => {
                alert('<i class="fas fa-times"></i> Error saving event');
                console.error(e);
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
            });
        });
    }
    
    // Close modal on outside click
    const modal = document.getElementById('eventModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeModal();
            }
        });
    }
});

// Toggle staff visibility
function toggleStaff(staffId) {
    // This will hide/show events for specific staff
    const checkbox = event.target.closest('.cal-calendar-item').querySelector('.cal-calendar-checkbox');
    checkbox.classList.toggle('checked');
    
    // Hide/show events by data-staff attribute
    const events = document.querySelectorAll(`[data-staff="${staffId}"]`);
    events.forEach(evt => {
        evt.style.display = checkbox.classList.contains('checked') ? 'flex' : 'none';
    });
}

// Client-side Live Search & Multi-Filter for Calendar Events
function filterCalendarEvents() {
    const searchVal = (document.getElementById('calendarSearchInput')?.value || '').toLowerCase().trim();
    const statusVal = (document.getElementById('filterStatus')?.value || '').toLowerCase().trim();
    const mechanicVal = (document.getElementById('filterMechanic')?.value || '').trim();
    const typeVal = (document.getElementById('filterEventType')?.value || '').toLowerCase().trim();
    const dateVal = (document.getElementById('filterDate')?.value || '').trim();

    const allEvents = document.querySelectorAll('.cal-event');
    allEvents.forEach(evt => {
        const text = (evt.innerText || evt.getAttribute('title') || '').toLowerCase();
        const staff = evt.getAttribute('data-staff') || '';
        const evtType = (evt.getAttribute('data-type') || '').toLowerCase();
        const evtStatus = (evt.getAttribute('data-status') || '').toLowerCase();
        const evtDate = evt.getAttribute('data-date') || '';
        
        let match = true;

        if (searchVal && !text.includes(searchVal)) {
            match = false;
        }

        if (mechanicVal && staff !== mechanicVal) {
            match = false;
        }

        if (statusVal) {
            if (statusVal === 'pending' && !['pending', 'unvalidated', 'reviewed', 'in progress', 'in_progress'].includes(evtStatus) && !text.includes('pending')) match = false;
            else if (statusVal === 'approved' && !['approved', 'in progress', 'in_progress', 'processing'].includes(evtStatus) && !text.includes('approved')) match = false;
            else if (statusVal === 'completed' && !['completed', 'verified', 'complete', 'delivered'].includes(evtStatus) && !text.includes('completed') && !text.includes('verified')) match = false;
        }

        if (typeVal) {
            if (typeVal === 'job_order' && evtType !== 'job_order' && !text.includes('job order') && !text.includes('jo#')) match = false;
            else if (typeVal === 'pms' && !text.includes('pms') && !text.includes('preventive') && !text.includes('maintenance')) match = false;
            else if (typeVal === 'staff_shift' && evtType !== 'staff_shift' && !text.includes('shift')) match = false;
            else if (typeVal === 'merchandise_delivery' && evtType !== 'merchandise_delivery' && !text.includes('delivery')) match = false;
            else if (typeVal === 'fuel_delivery' && evtType !== 'fuel_delivery' && !text.includes('fuel delivery')) match = false;
            else if (typeVal === 'customer_appointment' && evtType !== 'customer_appointment' && !text.includes('appointment')) match = false;
        }

        if (dateVal && evtDate && evtDate !== dateVal) {
            match = false;
        }

        evt.style.display = match ? 'flex' : 'none';
    });

    // Highlight date cell if dateVal selected
    document.querySelectorAll('.cal-day').forEach(d => {
        const dDate = d.getAttribute('data-date') || '';
        if (dateVal && dDate === dateVal) {
            d.style.boxShadow = 'inset 0 0 0 2.5px #002F70';
            d.style.background = '#f0f9ff';
        } else {
            d.style.boxShadow = '';
            d.style.background = '';
        }
    });
}

// Mini calendar navigation
function navigateMiniMonth(offset) {
    // For now, just navigate main calendar
    const currentOffset = <?= $month_offset ?>;
    window.location.href = 'manager_calendar.php?month_offset=' + (currentOffset + offset);
}

// Show conflicts modal
function showConflicts() {
    const conflicts = <?= json_encode($summary_stats['conflicts']) ?>;
    let html = '<div style="max-height: 400px; overflow-y: auto;">';
    
    conflicts.forEach((conflict, idx) => {
        html += `
            <div style="padding: 12px; border: 1px solid #fce8e6; background: #fff; border-radius: 4px; margin-bottom: 12px;">
                <div style="font-weight: 600; color: #d93025; margin-bottom: 8px;">
                    <i class="fas fa-exclamation-triangle"></i> Conflict ${idx + 1}
                </div>
                <div style="font-size: 13px; color: #3c4043; margin-bottom: 4px;">
                    <strong>Date:</strong> ${conflict.event_date}
                </div>
                <div style="font-size: 12px; color: #5f6368; margin-bottom: 8px;">
                    <div><strong>Event 1:</strong> ${conflict.work_description} (${conflict.start_time} - ${conflict.end_time})</div>
                    <div><strong>Event 2:</strong> ${conflict.conflict_desc} (${conflict.conflict_start} - ${conflict.conflict_end})</div>
                </div>
            </div>
        `;
    });
    
    html += '</div>';
    
    const modal = document.getElementById('eventModal');
    const title = document.getElementById('modalTitle');
    const form = document.getElementById('eventForm');
    
    title.textContent = 'Schedule Conflicts Detected';
    form.innerHTML = html + `
        <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #dadce0;">
            <button type="button" onclick="closeModal()" style="padding: 10px 24px; border: 1px solid #dadce0; background: #fff; color: #3c4043; border-radius: 4px; font-size: 14px; cursor: pointer; font-weight: 500; width: 100%;">
                Close
            </button>
        </div>
    `;
    
    modal.style.display = 'flex';
}

// Handle event type change to show/hide dynamic fields
function handleEventTypeChange() {
    const eventType = document.getElementById('eventType').value;
    const dynamicFields = document.getElementById('dynamicFields');
    
    let fieldsHTML = '';
    
    switch(eventType) {
        case 'staff_shift':
            fieldsHTML = `
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Shift Type</label>
                    <select name="shift_type" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                        <option value="Morning">Morning Shift</option>
                        <option value="Afternoon">Afternoon Shift</option>
                        <option value="Night">Night Shift</option>
                        <option value="Graveyard">Graveyard Shift</option>
                    </select>
                </div>
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Shift Status</label>
                    <select name="shift_status" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            `;
            break;
            
        case 'job_order':
            fieldsHTML = `
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Service Type</label>
                    <input type="text" name="service_type" placeholder="e.g., Oil Change, Tire Replacement" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                </div>
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Customer Name</label>
                    <input type="text" name="customer_name" placeholder="Customer name" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                </div>
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Job Order Status</label>
                    <select name="job_status" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                        <option value="pending">Pending</option>
                        <option value="in_progress">In Progress</option>
                        <option value="completed">Completed</option>
                    </select>
                </div>
            `;
            break;
            
        case 'fuel_delivery':
        case 'merchandise_delivery':
            fieldsHTML = `
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Supplier</label>
                    <input type="text" name="supplier" placeholder="Supplier name" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                </div>
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Product/Item</label>
                    <input type="text" name="product" placeholder="Product or item name" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                    <div>
                        <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Expected Quantity</label>
                        <input type="number" step="0.01" name="expected_qty" placeholder="0.00" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                    </div>
                    <div>
                        <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Actual Quantity</label>
                        <input type="number" step="0.01" name="actual_qty" placeholder="0.00" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                    </div>
                </div>
            `;
            break;
            
        case 'fuel_calibration':
        case 'meter_reading':
            fieldsHTML = `
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Pump/Tank Number</label>
                    <input type="text" name="pump_number" placeholder="Pump or tank identifier" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                    <div>
                        <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Expected Reading</label>
                        <input type="number" step="0.01" name="expected_reading" placeholder="0.00" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                    </div>
                    <div>
                        <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Actual Reading</label>
                        <input type="number" step="0.01" name="actual_reading" placeholder="0.00" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                    </div>
                </div>
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Variance</label>
                    <input type="text" name="variance" placeholder="Auto-calculated" readonly style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px; background: #f1f3f4;">
                </div>
            `;
            break;
            
        case 'customer_transaction':
        case 'payment_collection':
            fieldsHTML = `
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Customer ID</label>
                    <input type="text" name="customer_id" placeholder="Customer ID" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                </div>
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Amount</label>
                    <input type="number" step="0.01" name="amount" placeholder="0.00" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                </div>
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Payment Status</label>
                    <select name="payment_status" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                        <option value="unpaid">Unpaid</option>
                        <option value="downpayment">Downpayment</option>
                        <option value="paid">Paid in Full</option>
                    </select>
                </div>
            `;
            break;
    }
    
    dynamicFields.innerHTML = fieldsHTML;
    
    // Add variance calculation listeners for fuel calibration/meter reading
    if (eventType === 'fuel_calibration' || eventType === 'meter_reading') {
        const expected = dynamicFields.querySelector('[name="expected_reading"]');
        const actual = dynamicFields.querySelector('[name="actual_reading"]');
        const variance = dynamicFields.querySelector('[name="variance"]');
        
        function calculateVariance() {
            const exp = parseFloat(expected.value) || 0;
            const act = parseFloat(actual.value) || 0;
            const diff = act - exp;
            const pct = exp > 0 ? ((diff / exp) * 100).toFixed(2) : 0;
            variance.value = `${diff.toFixed(2)} L (${pct}%)`;
        }
        
        expected.addEventListener('input', calculateVariance);
        actual.addEventListener('input', calculateVariance);
    }
}
</script>

<!-- Day Overview Modal -->
<div id="dayOverviewModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: #fff; border-radius: 10px; box-shadow: 0 8px 24px rgba(0,0,0,0.2); width: 92%; max-width: 540px; max-height: 85vh; display: flex; flex-direction: column; overflow: hidden;">
        <div style="padding: 18px 22px; border-bottom: 1px solid #dadce0; display: flex; justify-content: space-between; align-items: center; background: #f8fafc;">
            <div>
                <h2 id="dayOverviewTitle" style="margin: 0; font-size: 18px; color: #002F70; font-weight: 700;">Day Schedule</h2>
                <div id="dayOverviewSubtitle" style="font-size: 12px; color: #64748b; margin-top: 2px;"></div>
            </div>
            <button onclick="closeDayOverviewModal()" style="background: none; border: none; font-size: 24px; color: #64748b; cursor: pointer; line-height: 1;">&times;</button>
        </div>
        <div id="dayOverviewList" style="padding: 20px 22px; overflow-y: auto; flex: 1; display: flex; flex-direction: column; gap: 10px;">
            <!-- Filled dynamically -->
        </div>
        <div style="padding: 14px 22px; border-top: 1px solid #dadce0; display: flex; justify-content: space-between; align-items: center; background: #f8fafc;">
            <button type="button" id="dayOverviewAddBtn" style="padding: 9px 16px; border: none; background: #002F70; color: #fff; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                <i class="fas fa-plus"></i> Add Event on this Day
            </button>
            <button type="button" onclick="closeDayOverviewModal()" style="padding: 9px 16px; border: 1px solid #cbd5e1; background: #fff; color: #334155; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer;">
                Close
            </button>
        </div>
    </div>
</div>

<!-- Details Modal -->
<div id="detailsModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: #fff; border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,0.2); width: 90%; max-width: 500px; max-height: 90vh; overflow-y: auto;">
        <div style="padding: 24px; border-bottom: 1px solid #dadce0; display: flex; justify-content: space-between; align-items: center;">
            <h2 id="detailsTitle" style="margin: 0; font-size: 20px; color: #3c4043; font-weight: 500;">Event Details</h2>
            <button onclick="closeDetailsModal()" style="background: none; border: none; font-size: 24px; color: #5f6368; cursor: pointer; line-height: 1;">&times;</button>
        </div>
        <div style="padding: 24px;">
            <div id="detailsBody" style="font-size: 14px; color: #3c4043; line-height: 1.6;">
                <!-- Filled dynamically via JS -->
            </div>
            <div id="detailsActions" style="display: flex; gap: 12px; justify-content: flex-end; padding-top: 16px; border-top: 1px solid #dadce0; margin-top: 20px;">
                <!-- Filled dynamically via JS -->
            </div>
        </div>
    </div>
</div>

<!-- Event Modal -->
<div id="eventModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: #fff; border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,0.2); width: 90%; max-width: 500px; max-height: 90vh; overflow-y: auto;">
        <div style="padding: 24px; border-bottom: 1px solid #dadce0;">
            <h2 id="modalTitle" style="margin: 0; font-size: 22px; color: #3c4043; font-weight: 400;">Create Event</h2>
        </div>
        
        <form id="eventForm" style="padding: 24px;">
            <input type="hidden" id="eventId" name="event_id">
            
            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Date</label>
                <input type="date" id="eventDate" name="event_date" required style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
            </div>
            
            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Event Type</label>
                <select id="eventType" name="event_type" required onchange="handleEventTypeChange()" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                    <option value="">Select type...</option>
                    <optgroup label="Work Assignments">
                        <option value="staff_shift">Staff Shift</option>
                        <option value="job_order">Job Order</option>
                        <option value="fuel_calibration">Fuel Calibration</option>
                        <option value="meter_reading">Meter Reading</option>
                    </optgroup>
                    <optgroup label="Deliveries">
                        <option value="fuel_delivery">Fuel Delivery</option>
                        <option value="merchandise_delivery">Merchandise Delivery</option>
                    </optgroup>
                    <optgroup label="Customer & Payments">
                        <option value="customer_transaction">Customer Transaction</option>
                        <option value="payment_collection">Payment Collection</option>
                    </optgroup>
                    <optgroup label="Other">
                        <option value="maintenance">Maintenance</option>
                        <option value="meeting">Meeting</option>
                        <option value="training">Training</option>
                        <option value="other">Other</option>
                    </optgroup>
                </select>
            </div>
            
            <!-- Dynamic fields based on event type -->
            <div id="dynamicFields"></div>
            
            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Description</label>
                <textarea id="eventDescription" name="work_description" required rows="3" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px; resize: vertical;"></textarea>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                <div>
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Start Time</label>
                    <input type="time" id="eventStartTime" name="start_time" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">End Time</label>
                    <input type="time" id="eventEndTime" name="end_time" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                </div>
            </div>
            
            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Status</label>
                <select id="eventStatus" name="status" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                    <option value="pending">Pending</option>
                    <option value="approved">Approved</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>
            
            <div style="display: flex; gap: 12px; justify-content: flex-end; padding-top: 16px; border-top: 1px solid #dadce0;">
                <button type="button" onclick="closeModal()" style="padding: 10px 24px; border: 1px solid #dadce0; background: #fff; color: #3c4043; border-radius: 4px; font-size: 14px; cursor: pointer; font-weight: 500;">
                    Cancel
                </button>
                <button type="submit" style="padding: 10px 24px; border: none; background: #1a73e8; color: #fff; border-radius: 4px; font-size: 14px; cursor: pointer; font-weight: 500;">
                    Save
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Auto-Refresh for Calendar Module (Shift 1 & Shift 2 Real-Time Sync) -->
<script>
(function() {
    const REFRESH_INTERVAL_MS = 15000; // 15 seconds auto refresh // 2 seconds auto refresh

    function isUserEditingOrModalOpen() {
        // 1. Check if any modal is currently visible
        const modals = document.querySelectorAll('#eventModal, #detailsModal, [id*="modal"], [id*="Modal"]');
        for (const modal of modals) {
            if (modal && modal.offsetParent !== null && window.getComputedStyle(modal).display !== 'none') {
                return true;
            }
        }
        // 2. Check if an input/textarea/select is focused
        const active = document.activeElement;
        if (active && ['INPUT', 'TEXTAREA', 'SELECT'].includes(active.tagName)) {
            return true;
        }
        return false;
    }

    function checkAndAutoRefresh() {
        if (!isUserEditingOrModalOpen()) {
            console.log('[Calendar Sync] Auto-refreshing calendar data for Shift 1 & Shift 2 sync...');
            window.location.reload();
        } else {
            console.log('[Calendar Sync] Refresh paused while user is editing or modal is open.');
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        setInterval(checkAndAutoRefresh, REFRESH_INTERVAL_MS);
    });
})();
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>

