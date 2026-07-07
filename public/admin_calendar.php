<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'calendar';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$rk = role_key($me['role'] ?? '');
if (!in_array($rk, ['admin','superadmin'])) { header('Location: dashboard.php'); exit; }
$station_id = user_station_id();
$user_id = $me['id'];
$is_admin = true;

// Admin can filter by station or view all
$filter_station = isset($_GET['station']) ? (int)$_GET['station'] : 0;

// Handle AJAX requests
if (isset($_POST['action']) && $_POST['action'] === 'save_event') {
    header('Content-Type: application/json');
    
    try {
        $event_id = $_POST['event_id'] ?? '';
        $event_date = $_POST['event_date'] ?? '';
        $event_type = $_POST['event_type'] ?? '';
        $work_description = $_POST['work_description'] ?? '';
        $start_time = $_POST['start_time'] ?? null;
        $end_time = $_POST['end_time'] ?? null;
        $status = $_POST['status'] ?? 'pending';
        
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
        if ($start_time && $end_time && $status !== 'cancelled') {
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
        $type_stmt = $pdo->prepare("SELECT id FROM staff_event_types WHERE type_key = ? LIMIT 1");
        $type_stmt->execute([$event_type]);
        $event_type_row = $type_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$event_type_row) {
            $insert_type = $pdo->prepare("INSERT INTO staff_event_types (type_key, type_name, icon_class) VALUES (?, ?, ?)");
            $insert_type->execute([$event_type, ucwords(str_replace('_', ' ', $event_type)), 'fas fa-calendar']);
            $event_type_id = $pdo->lastInsertId();
        } else {
            $event_type_id = $event_type_row['id'];
        }
        
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
            // Update existing event (admin can edit any event)
            $update_stmt = $pdo->prepare("UPDATE staff_calendar_events SET 
                event_date = ?, event_type_id = ?, work_description = ?, 
                start_time = ?, end_time = ?, status = ?, metadata = ?
                WHERE id = ?");
            $update_stmt->execute([
                $event_date, $event_type_id, $work_description, 
                $start_time, $end_time, $status, $metadata_json,
                $event_id
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

if (isset($_GET['action']) && $_GET['action'] === 'get_event') {
    header('Content-Type: application/json');
    
    try {
        $event_id = $_GET['event_id'] ?? '';
        
        // Admin can view all events
        $stmt = $pdo->prepare("SELECT sce.*, et.type_key, u.name as staff_name
            FROM staff_calendar_events sce
            JOIN staff_event_types et ON sce.event_type_id = et.id
            JOIN users u ON sce.staff_encoder_id = u.id
            WHERE sce.id = ?");
        $stmt->execute([$event_id]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($event) {
            // Decode metadata JSON
            if (!empty($event['metadata'])) {
                $metadata = json_decode($event['metadata'], true);
                $event = array_merge($event, $metadata ?: []);
            }
            
            // Admin can always edit
            $event['can_edit'] = true;
            
            echo json_encode(['success' => true, 'event' => $event]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Event not found']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'get_details') {
    header('Content-Type: application/json');
    try {
        $event_id = $_GET['event_id'] ?? '';
        $event_type = $_GET['event_type'] ?? '';
        
        $details = [];
        $audit = [];
        $numeric_id = preg_replace('/[^0-9]/', '', $event_id);
        
        if ($event_type === 'staff_shift' || strpos($event_id, 'shift_') !== false) {
            $stmt = $pdo->prepare("SELECT ss.*, CONCAT(u.first_name, ' ', u.last_name) AS staff_name, s.name as station_name
                FROM staff_schedules ss
                JOIN users u ON ss.user_id = u.id
                LEFT JOIN stations s ON u.station_id = s.id
                WHERE ss.id = ?");
            $stmt->execute([$numeric_id]);
            $details = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($details) {
                $details['title'] = 'Staff Shift Assignment';
                $details['description'] = 'Shift: ' . ($details['shift_name'] ?? 'Regular') . ' on ' . $details['scheduled_date'];
                $details['status'] = $details['status'] ?? 'Active';
                $details['date'] = $details['scheduled_date'];
                
                $audit_stmt = $pdo->prepare("SELECT action, details, created_at FROM activity_logs 
                    WHERE user_id = ? AND (action LIKE '%shift%' OR action LIKE '%schedule%') 
                    ORDER BY created_at DESC LIMIT 5");
                $audit_stmt->execute([$details['user_id']]);
                $audit = $audit_stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } elseif ($event_type === 'merchandise_delivery' || strpos($event_id, 'del_') !== false || $event_type === 'validation_delivery' || $event_type === 'fuel_delivery') {
            $stmt = $pdo->prepare("SELECT d.*, s.name as station_name 
                FROM deliveries_oversight d
                LEFT JOIN stations s ON d.station_id = s.id
                WHERE d.id = ?");
            $stmt->execute([$numeric_id]);
            $details = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($details) {
                $details['title'] = 'Delivery Oversight';
                $details['description'] = ($details['supplier'] ?? 'Petron Supplier') . ' - ' . ($details['product_name'] ?? 'Fuel/Merchandise') . ' (' . ($details['quantity'] ?? 0) . ' units)';
                $details['status'] = $details['status'] ?? 'Pending';
                $details['date'] = $details['delivery_date'] ?? date('Y-m-d');
                
                $audit_stmt = $pdo->prepare("SELECT action, details, created_at FROM activity_logs 
                    WHERE details LIKE ? OR action LIKE '%delivery%' 
                    ORDER BY created_at DESC LIMIT 5");
                $audit_stmt->execute(['%' . $numeric_id . '%']);
                $audit = $audit_stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } elseif ($event_type === 'job_order' || strpos($event_id, 'jo_') !== false) {
            $stmt = $pdo->prepare("SELECT jo.*, CONCAT(u.first_name, ' ', u.last_name) AS staff_name, s.name as station_name
                FROM job_orders jo
                LEFT JOIN users u ON jo.assigned_mechanic_id = u.id
                LEFT JOIN stations s ON jo.station_id = s.id
                WHERE jo.id = ?");
            $stmt->execute([$numeric_id]);
            $details = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($details) {
                $details['title'] = 'Job Order Oversight';
                $details['description'] = 'Service: ' . ($details['service_type'] ?? 'Repair') . ' for Customer: ' . ($details['customer_name'] ?? 'Walk-in');
                $details['status'] = $details['status'] ?? 'Pending';
                $details['date'] = date('Y-m-d', strtotime($details['created_at']));
                
                $audit_stmt = $pdo->prepare("SELECT action, details, created_at FROM activity_logs 
                    WHERE details LIKE ? OR action LIKE '%job_order%' 
                    ORDER BY created_at DESC LIMIT 5");
                $audit_stmt->execute(['%' . $numeric_id . '%']);
                $audit = $audit_stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } elseif ($event_type === 'compliance_deadline' || strpos($event_id, 'compliance_') !== false || strpos($event_id, 'overdue_report_') !== false) {
            $stmt = $pdo->prepare("SELECT c.*, s.name as station_name 
                FROM admin_compliance_deadlines c
                LEFT JOIN stations s ON c.station_id = s.id
                WHERE c.id = ?");
            $stmt->execute([$numeric_id]);
            $details = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($details) {
                $details['title'] = 'Compliance / Report Deadline';
                $details['description'] = $details['title'] . ' (' . ($details['deadline_type'] ?? 'Monthly') . ')';
                $details['status'] = $details['status'] ?? 'Pending';
                $details['date'] = $details['deadline_date'];
                
                $audit_stmt = $pdo->prepare("SELECT action, details, created_at FROM activity_logs 
                    WHERE action LIKE '%compliance%' OR action LIKE '%deadline%' 
                    ORDER BY created_at DESC LIMIT 5");
                $audit_stmt->execute();
                $audit = $audit_stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } elseif ($event_type === 'financial_event' || strpos($event_id, 'high_value_') !== false) {
            $stmt = $pdo->prepare("SELECT t.*, CONCAT(u.first_name, ' ', u.last_name) AS staff_name, s.name as station_name
                FROM merchandise_transactions t
                LEFT JOIN users u ON t.staff_id = u.id
                LEFT JOIN stations s ON t.station_id = s.id
                WHERE t.id = ?");
            $stmt->execute([$numeric_id]);
            $details = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($details) {
                $details['title'] = 'High-Value / Financial Transaction';
                $details['description'] = 'Customer: ' . ($details['customer_name'] ?? 'N/A') . ' - Amount: ₱' . number_format($details['total_amount'], 2) . ' (' . ($details['payment_method'] ?? 'Cash') . ')';
                $details['status'] = $details['status'] ?? 'Completed';
                $details['date'] = $details['transaction_date'] ?? date('Y-m-d');
                
                $audit_stmt = $pdo->prepare("SELECT action, details, created_at FROM activity_logs 
                    WHERE details LIKE ? OR action LIKE '%transaction%' 
                    ORDER BY created_at DESC LIMIT 5");
                $audit_stmt->execute(['%' . $numeric_id . '%']);
                $audit = $audit_stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } else {
            $stmt = $pdo->prepare("SELECT sce.*, et.type_name, CONCAT(u.first_name, ' ', u.last_name) AS staff_name, s.name as station_name
                FROM staff_calendar_events sce
                LEFT JOIN staff_event_types et ON sce.event_type_id = et.id
                LEFT JOIN users u ON sce.staff_encoder_id = u.id
                LEFT JOIN stations s ON sce.station_id = s.id
                WHERE sce.id = ?");
            $stmt->execute([$numeric_id]);
            $details = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($details) {
                $details['title'] = $details['type_name'] ?? 'Calendar Event';
                $details['description'] = $details['work_description'];
                $details['status'] = $details['status'] ?? 'Pending';
                $details['date'] = $details['event_date'];
                
                $audit_stmt = $pdo->prepare("SELECT action, details, created_at FROM activity_logs 
                    WHERE action LIKE '%calendar%' OR action LIKE '%event%' 
                    ORDER BY created_at DESC LIMIT 5");
                $audit_stmt->execute();
                $audit = $audit_stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        
        echo json_encode([
            'success' => true,
            'details' => $details ?: [
                'title' => 'System Alert / Notification',
                'description' => 'Automatic oversight entry generated by system monitor.',
                'status' => 'active',
                'date' => date('Y-m-d')
            ],
            'audit' => $audit
        ]);
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
    'compliance_deadlines' => 0,
    'overdue_reports' => 0,
    'critical_stock' => 0,
    'high_value_transactions' => 0,
    'stations_overview' => []
];

try {
    $today_date = date('Y-m-d');
    $week_start = date('Y-m-d', strtotime('monday this week'));
    $week_end = date('Y-m-d', strtotime('sunday this week'));
    $upcoming_end = date('Y-m-d', strtotime('+3 days'));
    
    // Build WHERE clause based on station filter
    $station_where = $filter_station > 0 ? "WHERE station_id = ?" : "";
    $station_params = $filter_station > 0 ? [$filter_station] : [];
    
    // Today's events count (ALL STATIONS or filtered)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM staff_calendar_events $station_where " . 
        ($filter_station > 0 ? "AND" : "WHERE") . " event_date = ?");
    $stmt->execute(array_merge($station_params, [$today_date]));
    $summary_stats['today_events'] = $stmt->fetchColumn();
    
    // Today's shifts (ALL STATIONS or filtered)
    if ($filter_station > 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM staff_schedules ss 
            JOIN users u ON ss.user_id = u.id 
            WHERE ss.scheduled_date = ? AND u.station_id = ?");
        $stmt->execute([$today_date, $filter_station]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM staff_schedules WHERE scheduled_date = ?");
        $stmt->execute([$today_date]);
    }
    $summary_stats['today_shifts'] = $stmt->fetchColumn();
    
    // Today's deliveries (ALL STATIONS or filtered)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM deliveries_oversight $station_where " . 
        ($filter_station > 0 ? "AND" : "WHERE") . " DATE(delivery_date) = ?");
    $stmt->execute(array_merge($station_params, [$today_date]));
    $summary_stats['today_deliveries'] = $stmt->fetchColumn();
    
    // Today's job orders (ALL STATIONS or filtered)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM job_orders $station_where " . 
        ($filter_station > 0 ? "AND" : "WHERE") . " DATE(created_at) = ?");
    $stmt->execute(array_merge($station_params, [$today_date]));
    $summary_stats['today_job_orders'] = $stmt->fetchColumn();
    
    // Week status counts (ALL STATIONS or filtered)
    $stmt = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM staff_calendar_events 
        $station_where " . ($filter_station > 0 ? "AND" : "WHERE") . " event_date BETWEEN ? AND ? 
        GROUP BY status");
    $stmt->execute(array_merge($station_params, [$week_start, $week_end]));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $status = strtolower($row['status']);
        if ($status === 'pending') $summary_stats['week_pending'] = $row['cnt'];
        if ($status === 'in_progress') $summary_stats['week_in_progress'] = $row['cnt'];
        if ($status === 'completed') $summary_stats['week_completed'] = $row['cnt'];
    }
    
    // Upcoming events (ALL STATIONS or filtered)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM staff_calendar_events 
        $station_where " . ($filter_station > 0 ? "AND" : "WHERE") . " event_date BETWEEN ? AND ?");
    $stmt->execute(array_merge($station_params, [$today_date, $upcoming_end]));
    $summary_stats['upcoming_count'] = $stmt->fetchColumn();
    
    // ADMIN SPECIFIC: Pending validations across all stations
    try {
        $validation_where = $filter_station > 0 ? "WHERE station_id = ?" : "";
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM merchandise_transactions $validation_where " . 
            ($filter_station > 0 ? "AND" : "WHERE") . " validation_status = 'Pending'");
        $stmt->execute($station_params);
        $summary_stats['pending_validations'] += $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM deliveries_oversight $station_where " . 
            ($filter_station > 0 ? "AND" : "WHERE") . " (status = 'pending' OR validated_by IS NULL)");
        $stmt->execute($station_params);
        $summary_stats['pending_validations'] += $stmt->fetchColumn();
    } catch (Exception $e) {}
    
    // ADMIN SPECIFIC: Compliance deadlines (upcoming within 7 days)
    try {
        $compliance_where = $filter_station > 0 ? "WHERE (station_id = ? OR station_id IS NULL)" : "";
        $compliance_params = $filter_station > 0 ? [$filter_station] : [];
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_compliance_deadlines $compliance_where " . 
            ($filter_station > 0 ? "AND" : "WHERE") . " deadline_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND status = 'pending'");
        $stmt->execute($compliance_params);
        $summary_stats['compliance_deadlines'] = $stmt->fetchColumn();
    } catch (Exception $e) {}
    
    // ADMIN SPECIFIC: Overdue reports count
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM merchandise_transactions $station_where " . 
            ($filter_station > 0 ? "AND" : "WHERE") . " validation_status = 'Pending' AND DATE(transaction_date) < DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
        $stmt->execute($station_params);
        $summary_stats['overdue_reports'] = $stmt->fetchColumn();
    } catch (Exception $e) {}
    
    // ADMIN SPECIFIC: Critical stock alerts
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory_products ip " . 
            ($filter_station > 0 ? "WHERE ip.station_id = ? AND" : "WHERE") . " ip.current_stock <= (ip.minimum_stock * 0.5) AND ip.status = 'Active'");
        $stmt->execute($station_params);
        $summary_stats['critical_stock'] = $stmt->fetchColumn();
    } catch (Exception $e) {}
    
    // ADMIN SPECIFIC: High-value transactions today
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM merchandise_transactions $station_where " . 
            ($filter_station > 0 ? "AND" : "WHERE") . " DATE(transaction_date) = ? AND total_amount >= 50000");
        $stmt->execute(array_merge($station_params, [$today_date]));
        $summary_stats['high_value_transactions'] = $stmt->fetchColumn();
    } catch (Exception $e) {}
    
    // ADMIN SPECIFIC: Stations overview (activity per station)
    if ($filter_station == 0) {
        try {
            $stmt = $pdo->prepare("SELECT s.id, s.name, 
                COUNT(DISTINCT sce.id) as events_today,
                COUNT(DISTINCT ss.id) as shifts_today
                FROM stations s
                LEFT JOIN staff_calendar_events sce ON s.id = sce.station_id AND sce.event_date = ?
                LEFT JOIN staff_schedules ss ON s.id IN (SELECT station_id FROM users WHERE user_id = ss.user_id) AND ss.scheduled_date = ?
                WHERE s.status = 'Active'
                GROUP BY s.id, s.name
                ORDER BY events_today DESC, shifts_today DESC
                LIMIT 10");
            $stmt->execute([$today_date, $today_date]);
            $summary_stats['stations_overview'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
    }
    
    // Check for schedule conflicts (ALL STATIONS or filtered)
    $conflict_where = $filter_station > 0 ? "WHERE e1.station_id = ? AND e2.station_id = ?" : "";
    $conflict_params = $filter_station > 0 ? [$filter_station, $filter_station] : [];
    
    $stmt = $pdo->prepare("SELECT e1.event_date, e1.start_time, e1.end_time, e1.work_description,
            e2.start_time as conflict_start, e2.end_time as conflict_end, e2.work_description as conflict_desc,
            u1.name as staff1_name, u2.name as staff2_name, st.name as station_name
        FROM staff_calendar_events e1
        JOIN staff_calendar_events e2 ON e1.event_date = e2.event_date AND e1.id < e2.id
        JOIN users u1 ON e1.staff_encoder_id = u1.id
        JOIN users u2 ON e2.staff_encoder_id = u2.id
        LEFT JOIN stations st ON e1.station_id = st.id
        $conflict_where
        " . ($filter_station > 0 ? "AND" : "WHERE") . " e1.start_time IS NOT NULL AND e2.start_time IS NOT NULL
        AND (
            (e1.start_time < e2.end_time AND e1.end_time > e2.start_time)
            OR (e2.start_time < e1.end_time AND e2.end_time > e1.start_time)
        )
        AND e1.status != 'cancelled' AND e2.status != 'cancelled'
        AND e1.staff_encoder_id = e2.staff_encoder_id
        LIMIT 20");
    $stmt->execute($conflict_params);
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
    // Load staff list with assigned colors (ALL STATIONS or filtered)
    if ($filter_station > 0) {
        $staff_stmt = $pdo->prepare("SELECT id, CONCAT(first_name, ' ', last_name) AS name FROM users WHERE station_id = ? AND role IN ('staff','cashier','pump_attendant','manager','supervisor') AND status = 'Active' ORDER BY first_name, last_name");
        $staff_stmt->execute([$filter_station]);
    } else {
        $staff_stmt = $pdo->prepare("SELECT id, CONCAT(first_name, ' ', last_name) AS name FROM users WHERE role IN ('staff','cashier','pump_attendant','manager','supervisor') AND status = 'Active' ORDER BY first_name, last_name");
        $staff_stmt->execute();
    }
    $all_staff = $staff_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($all_staff as $idx => $staff) {
        $color = $staff_colors[$idx % count($staff_colors)];
        $staff_list[$staff['id']] = [
            'name' => $staff['name'],
            'color' => $color
        ];
    }

    // Load calendar events (ALL STATIONS or filtered)
    if ($filter_station > 0) {
        $stmt = $pdo->prepare("SELECT sce.*, et.type_name, et.type_key, et.icon_class, su.name AS staff_name, sce.staff_encoder_id
            FROM staff_calendar_events sce
            JOIN staff_event_types et ON sce.event_type_id = et.id
            JOIN users su ON sce.staff_encoder_id = su.id
            WHERE sce.station_id = ? AND sce.event_date BETWEEN ? AND ?
            ORDER BY sce.event_date, sce.start_time");
        $stmt->execute([$filter_station, $view_start, $view_end]);
    } else {
        $stmt = $pdo->prepare("SELECT sce.*, et.type_name, et.type_key, et.icon_class, su.name AS staff_name, sce.staff_encoder_id
            FROM staff_calendar_events sce
            JOIN staff_event_types et ON sce.event_type_id = et.id
            JOIN users su ON sce.staff_encoder_id = su.id
            WHERE sce.event_date BETWEEN ? AND ?
            ORDER BY sce.event_date, sce.start_time");
        $stmt->execute([$view_start, $view_end]);
    }
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['color'] = $staff_list[$row['staff_encoder_id']]['color'] ?? '#757575';
        $month_events[$row['event_date']][] = $row;
    }

    // Auto-sync staff schedules/shifts (ALL STATIONS or filtered)
    try {
        if ($filter_station > 0) {
            $sh = $pdo->prepare("SELECT ss.id, ss.user_id, ss.shift, ss.scheduled_date, ss.status, u.name AS staff_name, s.start_time, s.end_time
                FROM staff_schedules ss
                JOIN users u ON ss.user_id = u.id
                LEFT JOIN shifts s ON ss.shift = s.name
                WHERE u.station_id = ? AND ss.scheduled_date BETWEEN ? AND ?");
            $sh->execute([$filter_station, $view_start, $view_end]);
        } else {
            $sh = $pdo->prepare("SELECT ss.id, ss.user_id, ss.shift, ss.scheduled_date, ss.status, u.name AS staff_name, s.start_time, s.end_time
                FROM staff_schedules ss
                JOIN users u ON ss.user_id = u.id
                LEFT JOIN shifts s ON ss.shift = s.name
                WHERE ss.scheduled_date BETWEEN ? AND ?");
            $sh->execute([$view_start, $view_end]);
        }
        foreach ($sh->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $month_events[$r['scheduled_date']][] = [
                'id' => 'shift_'.$r['id'],
                'type_name' => 'Shift',
                'type_key' => 'staff_shift',
                'icon_class' => 'fas fa-clock',
                'staff_name' => $r['staff_name'],
                'staff_encoder_id' => $r['user_id'],
                'work_description' => $r['staff_name'] . ' - ' . $r['shift'] . ' Shift',
                'status' => strtolower($r['status'] ?? 'pending'),
                'color' => $staff_list[$r['user_id']]['color'] ?? '#757575',
                'start_time' => $r['start_time'] ?? '00:00',
                'end_time' => $r['end_time'] ?? '00:00',
                'auto_synced' => true
            ];
        }
    } catch (Exception $e) {}

    // Auto-sync deliveries (ALL STATIONS or filtered)
    if ($filter_station > 0) {
        $dl = $pdo->prepare("SELECT d.id, d.encoded_by, DATE(d.delivery_date) AS event_date, u.name AS staff_name, d.status, d.supplier, d.product
            FROM deliveries_oversight d
            JOIN users u ON d.encoded_by = u.id
            WHERE d.station_id = ? AND DATE(d.delivery_date) BETWEEN ? AND ?");
        $dl->execute([$filter_station, $view_start, $view_end]);
    } else {
        $dl = $pdo->prepare("SELECT d.id, d.encoded_by, DATE(d.delivery_date) AS event_date, u.name AS staff_name, d.status, d.supplier, d.product
            FROM deliveries_oversight d
            JOIN users u ON d.encoded_by = u.id
            WHERE DATE(d.delivery_date) BETWEEN ? AND ?");
        $dl->execute([$view_start, $view_end]);
    }
    foreach ($dl->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $month_events[$r['event_date']][] = [
            'id' => 'del_'.$r['id'],
            'type_name' => 'Delivery',
            'type_key' => 'merchandise_delivery',
            'icon_class' => 'fas fa-box',
            'staff_name' => $r['staff_name'],
            'staff_encoder_id' => $r['encoded_by'],
            'work_description' => $r['supplier'] . ' - ' . $r['product'],
            'status' => strtolower($r['status'] ?? 'pending'),
            'color' => $staff_list[$r['encoded_by']]['color'] ?? '#757575',
            'auto_synced' => true
        ];
    }

    // Auto-sync job orders (ALL STATIONS or filtered)
    if ($filter_station > 0) {
        $jo = $pdo->prepare("SELECT jo.id, jo.created_by, DATE(jo.created_at) AS event_date, jo.service_type, jo.status, u.name AS staff_name, jo.customer_name
            FROM job_orders jo
            JOIN users u ON jo.created_by = u.id
            WHERE jo.station_id = ? AND DATE(jo.created_at) BETWEEN ? AND ?");
        $jo->execute([$filter_station, $view_start, $view_end]);
    } else {
        $jo = $pdo->prepare("SELECT jo.id, jo.created_by, DATE(jo.created_at) AS event_date, jo.service_type, jo.status, u.name AS staff_name, jo.customer_name
            FROM job_orders jo
            JOIN users u ON jo.created_by = u.id
            WHERE DATE(jo.created_at) BETWEEN ? AND ?");
        $jo->execute([$view_start, $view_end]);
    }
    foreach ($jo->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $month_events[$r['event_date']][] = [
            'id' => 'jo_'.$r['id'],
            'type_name' => 'Job Order',
            'type_key' => 'job_order',
            'icon_class' => 'fas fa-wrench',
            'staff_name' => $r['staff_name'],
            'staff_encoder_id' => $r['created_by'],
            'work_description' => $r['service_type'] . ' - ' . $r['customer_name'],
            'status' => strtolower($r['status'] ?? 'pending'),
            'color' => $staff_list[$r['created_by']]['color'] ?? '#757575',
            'auto_synced' => true
        ];
    }
    
    // ADMIN CALENDAR ENHANCEMENTS: Compliance Deadlines & Oversight
    
    // Auto-sync compliance deadlines (reports, audits, contracts)
    try {
        $compliance_query = "SELECT c.id, c.deadline_date, c.deadline_type, c.title, c.station_id, 
            c.status, s.name as station_name
            FROM admin_compliance_deadlines c
            LEFT JOIN stations s ON c.station_id = s.id
            WHERE c.deadline_date BETWEEN ? AND ?";
        
        $compliance_params = [$view_start, $view_end];
        if ($filter_station > 0) {
            $compliance_query .= " AND (c.station_id = ? OR c.station_id IS NULL)";
            $compliance_params[] = $filter_station;
        }
        $compliance_query .= " ORDER BY c.deadline_date";
        
        $compliance = $pdo->prepare($compliance_query);
        $compliance->execute($compliance_params);
        
        foreach ($compliance->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $days_until = (strtotime($r['deadline_date']) - strtotime($today_str)) / 86400;
            $is_overdue = $days_until < 0;
            $is_urgent = $days_until <= 3 && $days_until >= 0;
            
            $month_events[$r['deadline_date']][] = [
                'id' => 'compliance_'.$r['id'],
                'type_name' => ucfirst($r['deadline_type']),
                'type_key' => 'compliance_deadline',
                'icon_class' => 'fas fa-clipboard-list',
                'staff_name' => 'Admin Task',
                'staff_encoder_id' => $user_id,
                'work_description' => ($is_overdue ? '🔴 OVERDUE: ' : ($is_urgent ? '⚠ URGENT: ' : '📋 ')) . 
                    $r['title'] . ($r['station_name'] ? ' (' . $r['station_name'] . ')' : ' (All Stations)'),
                'status' => strtolower($r['status'] ?? 'pending'),
                'color' => $is_overdue ? '#d93025' : ($is_urgent ? '#ea8600' : '#7986cb'),
                'auto_synced' => true,
                'priority' => $is_overdue ? 'urgent' : ($is_urgent ? 'high' : 'normal')
            ];
        }
    } catch (Exception $e) {
        // Create compliance deadlines table if not exists
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS admin_compliance_deadlines (
                id INT AUTO_INCREMENT PRIMARY KEY,
                station_id INT NULL,
                deadline_type ENUM('report','audit','contract','license','inspection','other') DEFAULT 'report',
                title VARCHAR(255) NOT NULL,
                description TEXT,
                deadline_date DATE NOT NULL,
                status ENUM('pending','submitted','approved','overdue','cancelled') DEFAULT 'pending',
                assigned_to INT,
                created_by INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                completed_at TIMESTAMP NULL,
                INDEX idx_deadline (deadline_date, status),
                INDEX idx_station (station_id)
            )");
        } catch (Exception $e2) {}
    }
    
    // Auto-sync ALL pending validations across stations (admin oversight)
    try {
        $validation_query = "SELECT t.id, t.transaction_date, t.customer_name, t.total_amount, 
            t.station_id, s.name as station_name, u.name as staff_name
            FROM merchandise_transactions t
            JOIN stations s ON t.station_id = s.id
            JOIN users u ON t.staff_id = u.id
            WHERE t.validation_status = 'Pending' AND DATE(t.transaction_date) BETWEEN ? AND ?";
        
        $validation_params = [$view_start, $view_end];
        if ($filter_station > 0) {
            $validation_query .= " AND t.station_id = ?";
            $validation_params[] = $filter_station;
        }
        $validation_query .= " ORDER BY t.transaction_date DESC LIMIT 50";
        
        $pending_validations = $pdo->prepare($validation_query);
        $pending_validations->execute($validation_params);
        
        foreach ($pending_validations->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $month_events[$r['transaction_date']][] = [
                'id' => 'admin_validation_'.$r['id'],
                'type_name' => 'Oversight: Validation',
                'type_key' => 'admin_validation',
                'icon_class' => 'fas fa-eye',
                'staff_name' => $r['staff_name'] . ' @ ' . $r['station_name'],
                'staff_encoder_id' => $user_id,
                'work_description' => '👁 Monitor: ' . $r['customer_name'] . ' - ₱' . number_format($r['total_amount'], 2) . ' [' . $r['station_name'] . ']',
                'status' => 'pending',
                'color' => '#ea8600',
                'auto_synced' => true
            ];
        }
    } catch (Exception $e) {}
    
    // Auto-sync overdue reports from all stations
    try {
        $overdue_reports_query = "SELECT DATE(CURDATE()) as event_date, station_id, s.name as station_name,
            COUNT(*) as overdue_count
            FROM merchandise_transactions t
            JOIN stations s ON t.station_id = s.id
            WHERE t.validation_status = 'Pending' AND DATE(t.transaction_date) < DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        
        if ($filter_station > 0) {
            $overdue_reports_query .= " AND t.station_id = ?";
            $overdue_reports = $pdo->prepare($overdue_reports_query . " GROUP BY t.station_id, s.name");
            $overdue_reports->execute([$filter_station]);
        } else {
            $overdue_reports = $pdo->prepare($overdue_reports_query . " GROUP BY t.station_id, s.name");
            $overdue_reports->execute();
        }
        
        foreach ($overdue_reports->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['overdue_count'] > 0) {
                $month_events[$today_str][] = [
                    'id' => 'overdue_report_'.$r['station_id'],
                    'type_name' => 'Overdue Reports',
                    'type_key' => 'overdue_alert',
                    'icon_class' => 'fas fa-exclamation-circle',
                    'staff_name' => 'Admin Alert',
                    'staff_encoder_id' => $user_id,
                    'work_description' => '🚨 ' . $r['station_name'] . ' has ' . $r['overdue_count'] . ' overdue validation(s) (>7 days)',
                    'status' => 'urgent',
                    'color' => '#d93025',
                    'auto_synced' => true,
                    'priority' => 'urgent'
                ];
            }
        }
    } catch (Exception $e) {}
    
    // Auto-sync system-wide inventory alerts
    try {
        $critical_stock_query = "SELECT ip.id, ip.product_name, ip.current_stock, ip.minimum_stock, 
            ip.station_id, s.name as station_name
            FROM inventory_products ip
            JOIN stations s ON ip.station_id = s.id
            WHERE ip.current_stock <= (ip.minimum_stock * 0.5) AND ip.status = 'Active'";
        
        if ($filter_station > 0) {
            $critical_stock_query .= " AND ip.station_id = ?";
            $critical_stock = $pdo->prepare($critical_stock_query . " LIMIT 20");
            $critical_stock->execute([$filter_station]);
        } else {
            $critical_stock = $pdo->prepare($critical_stock_query . " LIMIT 20");
            $critical_stock->execute();
        }
        
        foreach ($critical_stock->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $month_events[$today_str][] = [
                'id' => 'critical_stock_'.$r['id'],
                'type_name' => 'Critical Stock Alert',
                'type_key' => 'stock_alert',
                'icon_class' => 'fas fa-box-open',
                'staff_name' => 'System Alert',
                'staff_encoder_id' => $user_id,
                'work_description' => '🔴 CRITICAL: ' . $r['product_name'] . ' @ ' . $r['station_name'] . ' (' . $r['current_stock'] . ' units remaining)',
                'status' => 'urgent',
                'color' => '#d93025',
                'auto_synced' => true,
                'priority' => 'urgent'
            ];
        }
    } catch (Exception $e) {}
    
    // Auto-sync operational & financial events integration
    try {
        // High-value transactions for admin oversight
        $high_value_query = "SELECT t.id, t.transaction_date, t.customer_name, t.total_amount,
            t.payment_method, s.name as station_name, u.name as staff_name
            FROM merchandise_transactions t
            JOIN stations s ON t.station_id = s.id
            JOIN users u ON t.staff_id = u.id
            WHERE t.total_amount >= 50000 AND DATE(t.transaction_date) BETWEEN ? AND ?";
        
        $high_value_params = [$view_start, $view_end];
        if ($filter_station > 0) {
            $high_value_query .= " AND t.station_id = ?";
            $high_value_params[] = $filter_station;
        }
        $high_value_query .= " ORDER BY t.total_amount DESC LIMIT 30";
        
        $high_value_tx = $pdo->prepare($high_value_query);
        $high_value_tx->execute($high_value_params);
        
        foreach ($high_value_tx->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $month_events[$r['transaction_date']][] = [
                'id' => 'high_value_'.$r['id'],
                'type_name' => 'High-Value Transaction',
                'type_key' => 'financial_event',
                'icon_class' => 'fas fa-coins',
                'staff_name' => $r['staff_name'] . ' @ ' . $r['station_name'],
                'staff_encoder_id' => $user_id,
                'work_description' => '💰 ' . $r['customer_name'] . ' - ₱' . number_format($r['total_amount'], 2) . ' (' . $r['payment_method'] . ') [' . $r['station_name'] . ']',
                'status' => 'completed',
                'color' => '#188038',
                'auto_synced' => true
            ];
        }
    } catch (Exception $e) {}
} catch (Exception $e) {}

if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="petron_calendar_report_'.date('Ymd').'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Event Type', 'Staff Assigned', 'Description', 'Status']);
    foreach ($month_events as $date => $evts) {
        foreach ($evts as $evt) {
            fputcsv($out, [
                $date,
                $evt['type_name'] ?? '',
                $evt['staff_name'] ?? '',
                $evt['work_description'] ?? '',
                $evt['status'] ?? 'pending'
            ]);
        }
    }
    fclose($out);
    exit;
}

include __DIR__ . '/../partials/header.php';
?>

<style>
/* Google Calendar Style */
.cal-layout { font-family: 'Google Sans', 'Roboto', Arial, sans-serif; background: #fff; display: flex; height: calc(100vh - 60px); }
.cal-layout * { font-family: 'Google Sans', 'Roboto', Arial, sans-serif; box-sizing: border-box; }

/* Sidebar */
.cal-sidebar { width: 256px; border-right: 1px solid #dadce0; padding: 8px 0; overflow-y: auto; flex-shrink: 0; }
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
.cal-calendar-checkbox.checked::before { content: '✓'; }

/* Main content */
.cal-main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
.cal-header { padding: 8px 16px; border-bottom: 1px solid #dadce0; display: flex; align-items: center; justify-content: space-between; }
.cal-header-left { display: flex; align-items: center; gap: 16px; }
.cal-menu-btn { background: none; border: none; padding: 12px; border-radius: 50%; cursor: pointer; color: #5f6368; font-size: 20px; }
.cal-menu-btn:hover { background: #f1f3f4; }
.cal-month-title { font-size: 22px; font-weight: 400; color: #3c4043; }
.cal-header-right { display: flex; align-items: center; gap: 8px; }
.cal-view-btn { background: none; border: 1px solid #dadce0; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-size: 14px; color: #3c4043; display: flex; align-items: center; gap: 6px; position: relative; }
.cal-view-btn:hover { background: #f1f3f4; }

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
.cal-content { flex: 1; overflow: auto; }
.cal-grid-container { min-width: 100%; }
.cal-weekdays { display: grid; grid-template-columns: repeat(7, 1fr); border-bottom: 1px solid #dadce0; position: sticky; top: 0; background: #fff; z-index: 2; }
.cal-weekday { padding: 8px; text-align: center; font-size: 11px; font-weight: 500; color: #70757a; }
.cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); grid-auto-rows: 120px; }
.cal-day { border: 1px solid #dadce0; border-top: none; border-left: none; padding: 2px; position: relative; background: #fff; overflow: hidden; }
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
        <!-- Summary Panels -->
        <div style="padding: 0 12px 20px; border-bottom: 1px solid #dadce0;">
            <!-- Today's Events -->
            <div style="background: #e8f0fe; border-radius: 8px; padding: 12px; margin-bottom: 12px;">
                <div style="font-size: 12px; color: #1a73e8; font-weight: 600; margin-bottom: 8px;">TODAY'S EVENTS</div>
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

            <!-- This Week Status -->
            <div style="background: #f1f3f4; border-radius: 8px; padding: 12px; margin-bottom: 12px;">
                <div style="font-size: 12px; color: #5f6368; font-weight: 600; margin-bottom: 8px;">THIS WEEK STATUS</div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                    <span style="font-size: 11px; color: #5f6368;">Pending</span>
                    <span style="font-size: 11px; font-weight: 600; color: #f9ab00;"><?= $summary_stats['week_pending'] ?></span>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                    <span style="font-size: 11px; color: #5f6368;">In Progress</span>
                    <span style="font-size: 11px; font-weight: 600; color: #1a73e8;"><?= $summary_stats['week_in_progress'] ?></span>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="font-size: 11px; color: #5f6368;">Completed</span>
                    <span style="font-size: 11px; font-weight: 600; color: #188038;"><?= $summary_stats['week_completed'] ?></span>
                </div>
            </div>

            <!-- Upcoming (3 days) -->
            <div style="background: #fef7e0; border-radius: 8px; padding: 12px;">
                <div style="font-size: 12px; color: #ea8600; font-weight: 600; margin-bottom: 4px;">UPCOMING (3 DAYS)</div>
                <div style="font-size: 24px; font-weight: 600; color: #ea8600;"><?= $summary_stats['upcoming_count'] ?></div>
                <div style="font-size: 10px; color: #5f6368;">events scheduled</div>
            </div>

            <?php if (count($summary_stats['conflicts']) > 0): ?>
            <!-- Conflicts Warning -->
            <div style="background: #fce8e6; border-radius: 8px; padding: 12px; margin-top: 12px;">
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                    <i class="fas fa-exclamation-triangle" style="color: #d93025;"></i>
                    <span style="font-size: 12px; color: #d93025; font-weight: 600;">SCHEDULE CONFLICTS</span>
                </div>
                <div style="font-size: 11px; color: #5f6368;"><?= count($summary_stats['conflicts']) ?> overlapping event(s) detected. Click to review.</div>
                <button onclick="showConflicts()" style="margin-top: 8px; padding: 6px 12px; background: #d93025; color: #fff; border: none; border-radius: 4px; font-size: 11px; cursor: pointer; width: 100%;">
                    Review Conflicts
                </button>
            </div>
            <?php endif; ?>
        </div>

        <!-- Staff color legend -->
        <div class="cal-calendars">
            <div class="cal-calendars-title">Staff</div>
            <?php foreach($staff_list as $staff_id => $staff): ?>
            <div class="cal-calendar-item" onclick="toggleStaff(<?= $staff_id ?>)">
                <div class="cal-calendar-checkbox checked" style="background: <?= htmlspecialchars($staff['color']) ?>; border-color: <?= htmlspecialchars($staff['color']) ?>;"></div>
                <div><?= htmlspecialchars($staff['name']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Event Categories -->
        <div class="cal-calendars" style="border-top: 1px solid #dadce0; margin-top: 16px; padding-top: 8px;">
            <div class="cal-calendars-title">Event Categories</div>
            
            <div class="cal-calendar-item" onclick="toggleCategory('staff_shift')">
                <div class="cal-calendar-checkbox checked" id="cb_cat_staff_shift" style="background: #1a73e8; border-color: #1a73e8;"></div>
                <div>Shifts</div>
            </div>
            <div class="cal-calendar-item" onclick="toggleCategory('job_order')">
                <div class="cal-calendar-checkbox checked" id="cb_cat_job_order" style="background: #1a73e8; border-color: #1a73e8;"></div>
                <div>Job Orders</div>
            </div>
            <div class="cal-calendar-item" onclick="toggleCategory('merchandise_delivery')">
                <div class="cal-calendar-checkbox checked" id="cb_cat_merchandise_delivery" style="background: #1a73e8; border-color: #1a73e8;"></div>
                <div>Deliveries</div>
            </div>
            <div class="cal-calendar-item" onclick="toggleCategory('compliance_deadline')">
                <div class="cal-calendar-checkbox checked" id="cb_cat_compliance_deadline" style="background: #1a73e8; border-color: #1a73e8;"></div>
                <div>Compliance</div>
            </div>
            <div class="cal-calendar-item" onclick="toggleCategory('validation_task')">
                <div class="cal-calendar-checkbox checked" id="cb_cat_validation_task" style="background: #1a73e8; border-color: #1a73e8;"></div>
                <div>Val. Tasks</div>
            </div>
            <div class="cal-calendar-item" onclick="toggleCategory('critical_stock')">
                <div class="cal-calendar-checkbox checked" id="cb_cat_critical_stock" style="background: #1a73e8; border-color: #1a73e8;"></div>
                <div>Critical Stock</div>
            </div>
        </div>

        <!-- Event Statuses -->
        <div class="cal-calendars" style="border-top: 1px solid #dadce0; margin-top: 16px; padding-top: 8px; margin-bottom: 20px;">
            <div class="cal-calendars-title">Status Filters</div>
            
            <div class="cal-calendar-item" onclick="toggleStatus('pending')">
                <div class="cal-calendar-checkbox checked" id="cb_stat_pending" style="background: #ea8600; border-color: #ea8600;"></div>
                <div>Pending / Flagged</div>
            </div>
            <div class="cal-calendar-item" onclick="toggleStatus('approved')">
                <div class="cal-calendar-checkbox checked" id="cb_stat_approved" style="background: #188038; border-color: #188038;"></div>
                <div>Approved / Verified</div>
            </div>
            <div class="cal-calendar-item" onclick="toggleStatus('completed')">
                <div class="cal-calendar-checkbox checked" id="cb_stat_completed" style="background: #1a73e8; border-color: #1a73e8;"></div>
                <div>Completed</div>
            </div>
            <div class="cal-calendar-item" onclick="toggleStatus('rejected')">
                <div class="cal-calendar-checkbox checked" id="cb_stat_rejected" style="background: #d93025; border-color: #d93025;"></div>
                <div>Rejected / Cancelled</div>
            </div>
        </div>
    </div>

    <!-- Main calendar -->
    <div class="cal-main">
        <!-- Header -->
        <div class="cal-header">
            <div class="cal-header-left">
                <h1 class="cal-month-title"><?= htmlspecialchars($view_title) ?></h1>
            </div>
            <div class="cal-header-right">
                <a href="admin_calendar.php?view=<?= $current_view ?>&month_offset=<?= $prev_offset ?><?= $filter_station > 0 ? '&station='.$filter_station : '' ?>" class="cal-icon-btn" title="Previous">
                    <i class="fas fa-chevron-left"></i>
                </a>
                <a href="admin_calendar.php?view=<?= $current_view ?>&month_offset=0<?= $filter_station > 0 ? '&station='.$filter_station : '' ?>" class="cal-view-btn">Today</a>
                <a href="admin_calendar.php?action=export_csv&view=<?= $current_view ?>&month_offset=<?= $month_offset ?><?= $filter_station > 0 ? '&station='.$filter_station : '' ?>" class="cal-view-btn" style="background: #188038; color: #fff; border-color: #188038;" title="Export CSV Report">
                    <i class="fas fa-file-excel"></i> Export CSV
                </a>
                <a href="admin_calendar.php?view=<?= $current_view ?>&month_offset=<?= $next_offset ?><?= $filter_station > 0 ? '&station='.$filter_station : '' ?>" class="cal-icon-btn" title="Next">
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
                    <div class="<?= $day_class ?>">
                        <div class="cal-day-num" onclick="clickDay('<?= $date ?>')"><?= $day_num ?></div>
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
                                 style="background: <?= $event_color ?>22; border-left-color: <?= $event_color ?>;" 
                                 title="<?= htmlspecialchars($event['staff_name'] ?? '') ?> - <?= htmlspecialchars($event['work_description'] ?? $event['type_name']) ?>"
                                 onclick="clickEvent('<?= htmlspecialchars($event_id) ?>', '<?= htmlspecialchars($event_type) ?>')">
                                <?php if ($time_str): ?>
                                <span class="cal-event-time"><?= $time_str ?></span>
                                <?php endif; ?>
                                <span class="cal-event-text">
                                    <?= htmlspecialchars($event['work_description'] ?? $event['type_name']) ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                            <?php if (count($day_events) > $display_limit): ?>
                            <div class="cal-more" onclick="clickDay('<?= $date ?>')">+<?= count($day_events) - $display_limit ?> more</div>
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
                <div class="cal-grid" style="grid-template-columns: repeat(7, 1fr); grid-auto-rows: 150px;">
                    <?php
                    $week_date = new DateTime($view_start);
                    for ($i = 0; $i < 7; $i++):
                        $date = $week_date->format('Y-m-d');
                        $is_today = ($date === $today_str);
                        $day_events = $month_events[$date] ?? [];
                    ?>
                    <div class="cal-day <?= $is_today ? 'today' : '' ?>">
                        <div class="cal-day-num"><?= $week_date->format('j') ?></div>
                        <div class="cal-events">
                            <?php foreach($day_events as $event):
                                $event_color = $event['color'] ?? '#757575';
                                $time_str = !empty($event['start_time']) && $event['start_time'] != '00:00:00' ? date('g:ia', strtotime($event['start_time'])) . ' ' : '';
                            ?>
                            <div class="cal-event" 
                                 data-staff="<?= htmlspecialchars($event['staff_encoder_id'] ?? '') ?>"
                                 data-type="<?= htmlspecialchars($event['type_key'] ?? '') ?>"
                                 data-status="<?= htmlspecialchars(strtolower($event['status'] ?? 'pending')) ?>"
                                 style="background: <?= $event_color ?>22; border-left-color: <?= $event_color ?>;" 
                                 onclick="clickEvent('<?= htmlspecialchars($event['id'] ?? '') ?>', '<?= htmlspecialchars($event['type_key'] ?? '') ?>')">
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
                        <div class="cal-event" 
                             data-staff="<?= htmlspecialchars($event['staff_encoder_id'] ?? '') ?>"
                             data-type="<?= htmlspecialchars($event['type_key'] ?? '') ?>"
                             data-status="<?= htmlspecialchars(strtolower($event['status'] ?? 'pending')) ?>"
                             style="border-left: 4px solid <?= $event_color ?>; background: <?= $event_color ?>11; padding: 16px; margin-bottom: 12px; border-radius: 4px; cursor: pointer; display: block;"
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
    <?php if ($filter_station > 0): ?>
    currentUrl.searchParams.set('station', '<?= $filter_station ?>');
    <?php endif; ?>
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

// Click on event
function clickEvent(eventId, eventType) {
    const match = eventId.toString().match(/\d+$/);
    const numericId = match ? match[0] : eventId;
    
    // Fetch details
    fetch('admin_calendar.php?action=get_details&event_id=' + eventId + '&event_type=' + eventType)
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                const det = res.details;
                const audit = res.audit;
                
                // Show modal
                document.getElementById('detailsTitle').innerText = det.title || 'Event Details';
                
                let detailsHTML = `
                    <div style="margin-bottom: 12px;"><strong>Event Type:</strong> <span style="background: #e8f0fe; color: #1a73e8; padding: 2px 8px; border-radius: 12px; font-size: 12px; font-weight: 600;">${eventType.toUpperCase()}</span></div>
                    <div style="margin-bottom: 8px;"><strong>Date:</strong> ${det.date || det.event_date || 'N/A'}</div>
                    <div style="margin-bottom: 8px;"><strong>Station:</strong> ${det.station_name || 'All Stations'}</div>
                    <div style="margin-bottom: 8px;"><strong>Assigned / Encoder:</strong> ${det.staff_name || 'System Auto-Generated'}</div>
                    <div style="margin-bottom: 8px;"><strong>Status:</strong> <span class="sla-badge" style="background: ${getStatusBg(det.status)}; color: ${getStatusColor(det.status)}; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700;">${(det.status || 'Active').toUpperCase()}</span></div>
                    <div style="margin-top: 16px; padding: 12px; background: #f1f3f4; border-radius: 6px; font-style: italic;">
                        "${det.description || det.work_description || 'No description provided.'}"
                    </div>
                `;
                
                // Add link buttons based on type
                let actionsHTML = '';
                if (eventType === 'merchandise_delivery' || eventId.toString().startsWith('del_')) {
                    actionsHTML = `
                        <a href="../public/staff_deliveries_module.php?delivery_id=${numericId}" class="cal-view-btn" style="background: #1a73e8; color: #fff; border-color: #1a73e8; text-decoration: none; padding: 10px 20px; border-radius: 4px; display: inline-block;">
                            <i class="fas fa-eye"></i> Go to Deliveries Module
                        </a>
                    `;
                } else if (eventType === 'job_order' || eventId.toString().startsWith('jo_')) {
                    actionsHTML = `
                        <a href="../public/staff_job_orders.php?job_id=${numericId}" class="cal-view-btn" style="background: #1a73e8; color: #fff; border-color: #1a73e8; text-decoration: none; padding: 10px 20px; border-radius: 4px; display: inline-block;">
                            <i class="fas fa-eye"></i> Go to Job Orders Module
                        </a>
                    `;
                } else if (!eventId.toString().startsWith('shift_') && !eventId.toString().startsWith('high_value_') && !eventId.toString().startsWith('compliance_')) {
                    // Manual events can be edited
                    actionsHTML = `
                        <button type="button" onclick="editManualEvent('${eventId}')" style="padding: 10px 24px; border: none; background: #1a73e8; color: #fff; border-radius: 4px; font-size: 14px; cursor: pointer; font-weight: 500;">
                            Edit Event
                        </button>
                    `;
                }
                
                document.getElementById('detailsContent').innerHTML = detailsHTML;
                
                // Add actions button if any
                const btnContainer = document.getElementById('detailsActionsContainer');
                if (btnContainer) {
                    btnContainer.innerHTML = actionsHTML;
                }
                
                // Render audit trail
                let auditHTML = '';
                if (audit && audit.length > 0) {
                    audit.forEach(log => {
                        auditHTML += `
                            <div style="border-bottom: 1px solid #eaeaea; padding: 6px 0;">
                                <span style="color: #1a73e8;">[${log.created_at}]</span> 
                                <strong>${log.action}:</strong> ${log.details}
                            </div>
                        `;
                    });
                } else {
                    auditHTML = 'No recent compliance audit trail logs found for this context.';
                }
                document.getElementById('detailsAuditTrail').innerHTML = auditHTML;
                
                // Show modal
                document.getElementById('detailsModal').style.display = 'flex';
            } else {
                alert('Error fetching event details: ' + res.message);
            }
        })
        .catch(err => {
            console.error(err);
            alert('Failed to load event details.');
        });
}

function getStatusBg(status) {
    status = (status || '').toLowerCase();
    if (status === 'approved' || status === 'verified' || status === 'completed' || status === 'active') return 'rgba(24, 128, 56, 0.15)';
    if (status === 'pending') return 'rgba(234, 134, 0, 0.15)';
    if (status === 'rejected' || status === 'cancelled') return 'rgba(217, 48, 37, 0.15)';
    return 'rgba(95, 99, 104, 0.15)';
}

function getStatusColor(status) {
    status = (status || '').toLowerCase();
    if (status === 'approved' || status === 'verified' || status === 'completed' || status === 'active') return '#188038';
    if (status === 'pending') return '#b06000';
    if (status === 'rejected' || status === 'cancelled') return '#c5221f';
    return '#5f6368';
}

function closeDetailsModal() {
    document.getElementById('detailsModal').style.display = 'none';
}

function editManualEvent(eventId) {
    closeDetailsModal();
    fetch('staff_calendar.php?action=get_event&event_id=' + eventId)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showEventModal(null, data.event);
            } else {
                alert('Event not found');
            }
        });
}

// Click on day
function clickDay(date) {
    if (confirm('Create event on ' + date + '?')) {
        showEventModal(date);
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
            
            fetch('staff_calendar.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('✓ Event saved successfully!');
                    location.reload();
                } else if (data.conflict) {
                    // Show conflict warning
                    if (confirm('⚠ ' + data.message + '\n\nDo you want to save anyway? (Not recommended)')) {
                        formData.append('force_save', '1');
                        // Retry with force flag
                        fetch('staff_calendar.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(r => r.json())
                        .then(data2 => {
                            if (data2.success) {
                                alert('✓ Event saved with conflict warning!');
                                location.reload();
                            } else {
                                alert('✗ Error: ' + (data2.message || 'Failed to save event'));
                            }
                        });
                    } else {
                        submitBtn.textContent = originalText;
                        submitBtn.disabled = false;
                    }
                } else {
                    alert('✗ Error: ' + (data.message || 'Failed to save event'));
                    submitBtn.textContent = originalText;
                    submitBtn.disabled = false;
                }
            })
            .catch(e => {
                alert('✗ Error saving event');
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

// Toggle staff, category, and status filters
const activeStaff = {};
document.querySelectorAll('.cal-calendar-item[onclick^="toggleStaff"]').forEach(item => {
    const match = item.getAttribute('onclick').match(/\d+/);
    if (match) {
        activeStaff[match[0]] = true;
    }
});

const activeCategories = {
    staff_shift: true,
    job_order: true,
    merchandise_delivery: true,
    validation_delivery: true,
    compliance_deadline: true,
    validation_task: true,
    critical_stock: true,
    fuel_delivery: true,
    fuel_calibration: true,
    overdue_report: true,
    financial_event: true,
    payment_reminder: true,
    restock_reminder: true
};

const activeStatuses = {
    pending: true,
    approved: true,
    completed: true,
    rejected: true,
    verified: true,
    cancelled: true
};

function toggleStaff(staffId) {
    const item = event.currentTarget;
    const checkbox = item.querySelector('.cal-calendar-checkbox');
    checkbox.classList.toggle('checked');
    const isChecked = checkbox.classList.contains('checked');
    
    if (isChecked) {
        checkbox.style.background = checkbox.style.borderColor;
    } else {
        checkbox.style.background = 'transparent';
    }
    
    activeStaff[staffId] = isChecked;
    applyCalendarFilters();
}

function toggleCategory(cat) {
    const checkbox = document.getElementById('cb_cat_' + cat);
    checkbox.classList.toggle('checked');
    const isChecked = checkbox.classList.contains('checked');
    
    if (isChecked) {
        checkbox.style.background = '#1a73e8';
    } else {
        checkbox.style.background = 'transparent';
    }
    
    activeCategories[cat] = isChecked;
    if (cat === 'merchandise_delivery') {
        activeCategories['validation_delivery'] = isChecked;
        activeCategories['fuel_delivery'] = isChecked;
    }
    if (cat === 'validation_task') {
        activeCategories['financial_event'] = isChecked;
    }
    if (cat === 'critical_stock') {
        activeCategories['restock_reminder'] = isChecked;
        activeCategories['payment_reminder'] = isChecked;
    }
    applyCalendarFilters();
}

function toggleStatus(stat) {
    const checkbox = document.getElementById('cb_stat_' + stat);
    checkbox.classList.toggle('checked');
    const isChecked = checkbox.classList.contains('checked');
    
    const colors = { pending: '#ea8600', approved: '#188038', completed: '#1a73e8', rejected: '#d93025' };
    if (isChecked) {
        checkbox.style.background = colors[stat];
    } else {
        checkbox.style.background = 'transparent';
    }
    
    activeStatuses[stat] = isChecked;
    if (stat === 'approved') {
        activeStatuses['verified'] = isChecked;
    }
    if (stat === 'rejected') {
        activeStatuses['cancelled'] = isChecked;
    }
    applyCalendarFilters();
}

function applyCalendarFilters() {
    const events = document.querySelectorAll('.cal-event');
    events.forEach(evt => {
        const staff = evt.getAttribute('data-staff');
        const type = evt.getAttribute('data-type');
        const status = evt.getAttribute('data-status');
        
        let show = true;
        
        if (staff && activeStaff[staff] === false) {
            show = false;
        }
        if (type && activeCategories[type] === false) {
            show = false;
        }
        if (status && activeStatuses[status] === false) {
            show = false;
        }
        
        if (show) {
            evt.style.setProperty('display', 'flex', 'important');
        } else {
            evt.style.setProperty('display', 'none', 'important');
        }
    });
}

// Mini calendar navigation
function navigateMiniMonth(offset) {
    // For now, just navigate main calendar
    const currentOffset = <?= $month_offset ?>;
    window.location.href = 'staff_calendar.php?month_offset=' + (currentOffset + offset);
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

<!-- Read-Only Details Modal -->
<div id="detailsModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1001; align-items: center; justify-content: center;">
    <div style="background: #fff; border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,0.2); width: 90%; max-width: 550px; max-height: 90vh; overflow-y: auto;">
        <div style="padding: 24px; border-bottom: 1px solid #dadce0; display: flex; justify-content: space-between; align-items: center;">
            <h2 id="detailsTitle" style="margin: 0; font-size: 20px; color: #1a73e8; font-weight: 600;">Event Details</h2>
            <button onclick="closeDetailsModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #5f6368;">&times;</button>
        </div>
        <div style="padding: 24px;">
            <div id="detailsContent" style="font-size: 14px; color: #3c4043; line-height: 1.6;">
                <!-- Filled dynamically by JavaScript -->
            </div>
            
            <div style="margin-top: 24px; border-top: 1px solid #dadce0; padding-top: 16px;">
                <h4 style="margin: 0 0 12px; color: #3c4043; font-size: 14px; font-weight: 600;">COMPLIANCE AUDIT SNAPHOT</h4>
                <div id="detailsAuditTrail" style="background: #f8f9fa; padding: 12px; border-radius: 6px; font-family: monospace; font-size: 11px; max-height: 150px; overflow-y: auto; color: #5f6368;">
                    No recent audit trail logs found for this context.
                </div>
            </div>

            <div style="display: flex; gap: 12px; justify-content: flex-end; padding-top: 20px; margin-top: 20px; border-top: 1px solid #dadce0;">
                <div id="detailsActionsContainer" style="display: inline-flex; gap: 12px;"></div>
                <button type="button" onclick="closeDetailsModal()" style="padding: 10px 24px; border: 1px solid #dadce0; background: #fff; color: #3c4043; border-radius: 4px; font-size: 14px; cursor: pointer; font-weight: 500;">
                    Close
                </button>
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
                    <option value="in_progress">In Progress</option>
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

<?php include __DIR__ . '/../partials/footer.php'; ?>
