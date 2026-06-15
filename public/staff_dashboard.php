<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'dashboard';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$station_id = user_station_id();
$role       = role_key($me['role'] ?? '');

if (!in_array($role, ['staff', 'cashier', 'pump_attendant', 'manager', 'admin', 'superadmin'])) {
    header('Location: dashboard.php'); exit;
}
if (!$station_id) {
    die('Error: You are not assigned to a station.');
}

$display_name  = htmlspecialchars($me['full_name'] ?? trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: ($me['name'] ?? 'Staff'));
$station_label = htmlspecialchars($me['station_name'] ?? 'Station #' . $station_id);

// ═══════════════════════════════════════════════════════════════
// SHIFT ASSIGNMENT LOGIC - Account Segregation
// ═══════════════════════════════════════════════════════════════
// Determine user's assigned shift from their shift_assignment or last clock-in
$user_assigned_shift = null;
$can_view_consolidation = false;

// Check if user has shift_assignment in users table
try {
    $shift_check = $pdo->prepare("SELECT shift_assignment FROM users WHERE id = ? LIMIT 1");
    $shift_check->execute([$me['id']]);
    $shift_assignment = $shift_check->fetchColumn();
    
    if ($shift_assignment && in_array(strtolower($shift_assignment), ['shift 1', 'first shift', 'shift1', '1'])) {
        $user_assigned_shift = 1;
    } elseif ($shift_assignment && in_array(strtolower($shift_assignment), ['shift 2', 'second shift', 'shift2', '2'])) {
        $user_assigned_shift = 2;
    }
} catch (Exception $e) {}

// Fallback: Determine shift from most recent labor_session
if (!$user_assigned_shift) {
    try {
        $recent_shift = $pdo->prepare("
            SELECT shift_name 
            FROM labor_sessions 
            WHERE user_id = ? 
            ORDER BY start_time DESC 
            LIMIT 1
        ");
        $recent_shift->execute([$me['id']]);
        $last_shift_name = $recent_shift->fetchColumn();
        
        if ($last_shift_name && stripos($last_shift_name, 'first') !== false) {
            $user_assigned_shift = 1;
        } elseif ($last_shift_name && stripos($last_shift_name, 'second') !== false) {
            $user_assigned_shift = 2;
        }
    } catch (Exception $e) {}
}

// Default to Shift 1 if no assignment found
if (!$user_assigned_shift) {
    $user_assigned_shift = 1;
}

// Only Admin and Manager can view Daily Consolidation
if (in_array($role, ['admin', 'manager', 'superadmin'])) {
    $can_view_consolidation = true;
}

// Get current shift info
$current_shift = null;
try {
    $sp = $pdo->prepare("
        SELECT shift_key, shift_name, start_time, end_time
        FROM shift_periods
        WHERE is_active = 1 
        AND start_time <= TIME(NOW()) 
        AND end_time >= TIME(NOW())
        ORDER BY sort_order ASC LIMIT 1
    ");
    $sp->execute();
    $current_shift = $sp->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Check if user is clocked in
$clocked_in = false;
$clock_in_time = null;
$clock_in_shift = null;
try {
    $ci = $pdo->prepare("SELECT start_time, shift_name FROM labor_sessions WHERE user_id = ? AND end_time IS NULL");
    $ci->execute([$me['id']]);
    $clock = $ci->fetch(PDO::FETCH_ASSOC);
    if ($clock) {
        $clocked_in = true;
        $clock_in_time = $clock['start_time'];
        $clock_in_shift = $clock['shift_name'];
    }
} catch (Exception $e) {}

// ═══════════════════════════════════════════════════════════════
// AUTOMATIC CLOCK IN ON LOGIN
// ═══════════════════════════════════════════════════════════════
// Auto clock in if not already clocked in
if (!$clocked_in) {
    try {
        $shift = $current_shift ?: ['shift_key' => 'first', 'shift_name' => 'First Shift'];
        $pdo->prepare(
            "INSERT INTO labor_sessions (user_id, station_id, start_time, shift_period, shift_name)
             VALUES (?, ?, NOW(), ?, ?)"
        )->execute([$me['id'], $station_id, $shift['shift_key'], $shift['shift_name']]);
        log_activity($pdo, $me['id'], 'Auto Clock In', "Station {$station_id} - {$shift['shift_name']}");
        
        // Refresh clock in status
        $clocked_in = true;
        $clock_in_time = date('Y-m-d H:i:s');
        $clock_in_shift = $shift['shift_name'];
    } catch (Exception $e) {}
}

$flash_success = $_SESSION['success'] ?? null; unset($_SESSION['success']);
$flash_error   = $_SESSION['error']   ?? null; unset($_SESSION['error']);

// Determine the selected date for dashboard data (with fallback to latest transaction date)
$selected_date = $_GET['date'] ?? null;
if ($selected_date) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) {
        $selected_date = null;
    }
}

if (!$selected_date) {
    try {
        $latest_dates = [];
        
        $q1 = $pdo->prepare("SELECT MAX(DATE(transaction_date)) FROM fuel_transactions WHERE station_id = ?");
        $q1->execute([$station_id]);
        $d1 = $q1->fetchColumn();
        
        $q2 = $pdo->prepare("SELECT MAX(DATE(created_at)) FROM merchandise_transactions WHERE station_id = ?");
        $q2->execute([$station_id]);
        $d2 = $q2->fetchColumn();
        
        $q3 = $pdo->prepare("SELECT MAX(DATE(created_at)) FROM job_orders WHERE station_id = ?");
        $q3->execute([$station_id]);
        $d3 = $q3->fetchColumn();
        
        // Prioritize fuel/merch sales dates so cards are populated by default on first load
        if ($d1) {
            $selected_date = $d1;
        } elseif ($d2) {
            $selected_date = $d2;
        } elseif ($d3) {
            $selected_date = $d3;
        }
    } catch (Exception $e) {}
}

if (!$selected_date) {
    $selected_date = date('Y-m-d');
}

// Helper function to get shift data
function getShiftData($pdo, $station_id, $shift_number, $date = null) {
    if (!$date) {
        $date = date('Y-m-d');
    }
    
    // Define shift time ranges
    $shift_times = [
        1 => ['start' => '06:00:00', 'end' => '14:00:00'],
        2 => ['start' => '14:00:00', 'end' => '22:00:00']
    ];
    
    $times = $shift_times[$shift_number];
    $start = "$date {$times['start']}";
    $end = "$date {$times['end']}";
    $shift_keys = ($shift_number == 1) ? ['first', 'First Shift'] : ['second', 'Second Shift'];
    $today_date = $date;
    
    // Fuel Sales
    $fuel_query = $pdo->prepare("
        SELECT fuel_type,
               COALESCE(SUM(liters_sold), 0) AS liters,
               COALESCE(SUM(total_amount), 0) AS revenue,
               COALESCE(AVG(price_per_liter), 0) AS avg_price
        FROM fuel_transactions
        WHERE station_id = ? 
        AND (
            (transaction_date BETWEEN ? AND ?)
            OR (DATE(transaction_date) = ? AND (shift_period IN (?, ?) OR shift_id = ?))
        )
        AND liters_sold > 0
        GROUP BY fuel_type
        ORDER BY revenue DESC
    ");
    $fuel_query->execute([$station_id, $start, $end, $today_date, $shift_keys[0], $shift_keys[1], $shift_number]);
    $fuel_data = $fuel_query->fetchAll(PDO::FETCH_ASSOC);
    
    // Merchandise Sales (basic totals)
    $merch_query = $pdo->prepare("
        SELECT COALESCE(SUM(total_amount), 0) AS total,
               COUNT(*) AS count
        FROM merchandise_transactions
        WHERE station_id = ?
        AND (
            (created_at BETWEEN ? AND ?)
            OR (DATE(created_at) = ? AND (shift_period IN (?, ?) OR shift_id = ?))
        )
    ");
    $merch_query->execute([$station_id, $start, $end, $today_date, $shift_keys[0], $shift_keys[1], $shift_number]);
    $merch_data = $merch_query->fetch(PDO::FETCH_ASSOC);
    
    // Service Income from completed job orders
    $service_query = $pdo->prepare("
        SELECT COALESCE(SUM(total_cost), 0) AS total_service_income,
               COUNT(*) AS completed_jobs
        FROM job_orders
        WHERE station_id = ?
        AND (
            (created_at BETWEEN ? AND ?)
            OR (DATE(created_at) = ? AND (shift_id = ?))
        )
        AND status = 'Completed'
    ");
    $service_query->execute([$station_id, $start, $end, $today_date, $shift_number]);
    $service_data = $service_query->fetch(PDO::FETCH_ASSOC);
    
    // Payments Summary Aggregator across all streams: Fuel, Merch, Job Orders
    $payments_summary = [
        'cash' => 0.0,
        'card' => 0.0,
        'ewallet' => 0.0,
        'efuel' => 0.0,
        'fleet' => 0.0,
        'total' => 0.0
    ];
    
    // 1. Fuel transaction payments
    $fuel_pay_q = $pdo->prepare("
        SELECT COALESCE(payment_method, 'Cash') AS method, COALESCE(SUM(total_amount), 0) AS total
        FROM fuel_transactions
        WHERE station_id = ?
          AND (
              (transaction_date BETWEEN ? AND ?)
              OR (DATE(transaction_date) = ? AND (shift_period IN (?, ?) OR shift_id = ?))
          )
        GROUP BY payment_method
    ");
    $fuel_pay_q->execute([$station_id, $start, $end, $today_date, $shift_keys[0], $shift_keys[1], $shift_number]);
    $fuel_pays = $fuel_pay_q->fetchAll(PDO::FETCH_ASSOC);

    // 2. Merchandise transaction payments
    $merch_pay_q = $pdo->prepare("
        SELECT COALESCE(payment_method, 'Cash') AS method, COALESCE(SUM(total_amount), 0) AS total
        FROM merchandise_transactions
        WHERE station_id = ?
          AND (
              (created_at BETWEEN ? AND ?)
              OR (DATE(created_at) = ? AND (shift_period IN (?, ?) OR shift_id = ?))
          )
        GROUP BY payment_method
    ");
    $merch_pay_q->execute([$station_id, $start, $end, $today_date, $shift_keys[0], $shift_keys[1], $shift_number]);
    $merch_pays = $merch_pay_q->fetchAll(PDO::FETCH_ASSOC);

    // 3. Job order payments
    $jo_pay_q = $pdo->prepare("
        SELECT COALESCE(payment_method, 'Cash') AS method, COALESCE(SUM(total_cost), 0) AS total
        FROM job_orders
        WHERE station_id = ?
          AND status = 'Completed'
          AND (
              (created_at BETWEEN ? AND ?)
              OR (DATE(created_at) = ? AND (shift_id = ?))
          )
        GROUP BY payment_method
    ");
    $jo_pay_q->execute([$station_id, $start, $end, $today_date, $shift_number]);
    $jo_pays = $jo_pay_q->fetchAll(PDO::FETCH_ASSOC);

    // Helper to map method name to canonical keys
    $map_method = function($m) {
        $m = strtolower(trim($m));
        if (in_array($m, ['cash'])) return 'cash';
        if (in_array($m, ['card', 'credit card', 'debit card'])) return 'card';
        if (in_array($m, ['e-wallet', 'ewallet', 'gcash', 'maya', 'e-wallet'])) return 'ewallet';
        if (in_array($m, ['e-fuel card', 'fuel card', 'efuel'])) return 'efuel';
        if (in_array($m, ['fleet card', 'fleet'])) return 'fleet';
        return 'cash'; // Default fallback
    };

    foreach ($fuel_pays as $p) {
        $payments_summary[$map_method($p['method'])] += (float)$p['total'];
    }
    foreach ($merch_pays as $p) {
        $payments_summary[$map_method($p['method'])] += (float)$p['total'];
    }
    foreach ($jo_pays as $p) {
        $payments_summary[$map_method($p['method'])] += (float)$p['total'];
    }
    $payments_summary['total'] = array_sum(array_slice($payments_summary, 0, 5));
    
    // Fuel Tank Levels
    $fuel_levels_query = $pdo->prepare("
        SELECT COALESCE(ft.name, fi.fuel_type) AS fuel_type_name,
               COALESCE(fi.current_level, fi.current_stock, 0) AS current_stock,
               COALESCE(fi.capacity, 0) AS capacity,
               CASE
                   WHEN COALESCE(fi.current_level, fi.current_stock, 0) <= 0 THEN 'Out of Stock'
                   WHEN COALESCE(fi.capacity, 0) > 0
                        AND (COALESCE(fi.current_level, fi.current_stock, 0) / COALESCE(fi.capacity, 0)) * 100 <= 10 THEN 'Critical'
                   WHEN COALESCE(fi.capacity, 0) > 0
                        AND (COALESCE(fi.current_level, fi.current_stock, 0) / COALESCE(fi.capacity, 0)) * 100 <= 25 THEN 'Low Stock'
                   ELSE 'Normal'
               END AS stock_status
        FROM fuel_inventory fi
        LEFT JOIN fuel_types ft ON fi.fuel_type_id = ft.id
        WHERE fi.station_id = ?
        ORDER BY current_stock ASC
    ");
    $fuel_levels_query->execute([$station_id]);
    $fuel_levels = $fuel_levels_query->fetchAll(PDO::FETCH_ASSOC);
    
    // Merchandise Low Stock
    $merch_low_stock_query = $pdo->prepare("
        SELECT COALESCE(ip.product_name, CONCAT('Product #', si.product_id)) AS product_name,
               si.stock_level AS current_stock,
               COALESCE(si.reorder_level, 10) AS threshold,
               COALESCE(ip.category, 'Merchandise') AS category
        FROM station_inventory si
        LEFT JOIN inventory_products ip ON ip.id = si.product_id
        WHERE si.station_id = ? AND si.status = 'Active'
          AND si.stock_level <= COALESCE(si.reorder_level, 10)
        ORDER BY si.stock_level ASC
        LIMIT 10
    ");
    $merch_low_stock_query->execute([$station_id]);
    $merch_low_stock = $merch_low_stock_query->fetchAll(PDO::FETCH_ASSOC);
    
    // Fallback to inventory table if station_inventory returns empty
    if (empty($merch_low_stock)) {
        try {
            $merch_low_stock_query2 = $pdo->prepare("
                SELECT COALESCE(ip.product_name, CONCAT('Product #', i.product_id)) AS product_name,
                       i.stock_level AS current_stock,
                       COALESCE(i.reorder_level, 10) AS threshold,
                       COALESCE(ip.category, 'Merchandise') AS category
                FROM inventory i
                LEFT JOIN inventory_products ip ON ip.id = i.product_id
                WHERE i.station_id = ?
                  AND i.stock_level <= COALESCE(i.reorder_level, 10)
                ORDER BY i.stock_level ASC
                LIMIT 10
            ");
            $merch_low_stock_query2->execute([$station_id]);
            $merch_low_stock = $merch_low_stock_query2->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $merch_low_stock = [];
        }
    }
    
    // Activity Log for this shift - STAFF ONLY (exclude admin/manager)
    $activity_query = $pdo->prepare("
        SELECT al.action_type, al.action_details, al.created_at, u.username, u.role
        FROM audit_logs al 
        LEFT JOIN users u ON al.user_id = u.id
        WHERE u.station_id = ?
        AND al.created_at BETWEEN ? AND ?
        AND u.role IN ('staff', 'cashier', 'pump_attendant')
        ORDER BY al.created_at DESC 
        LIMIT 15
    ");
    $activity_query->execute([$station_id, $start, $end]);
    $activity_log = $activity_query->fetchAll(PDO::FETCH_ASSOC);
    
    // Shift Tracker - Staff clocked in during this shift
    $shift_tracker_query = $pdo->prepare("
        SELECT COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.username, 'Unknown') AS full_name, 
               ls.start_time, 
               ls.end_time,
               TIMESTAMPDIFF(MINUTE, ls.start_time, COALESCE(ls.end_time, NOW())) AS duration_min,
               CASE WHEN ls.end_time IS NULL THEN 'Active' ELSE 'Completed' END AS status
        FROM labor_sessions ls 
        LEFT JOIN users u ON ls.user_id = u.id
        WHERE ls.station_id = ?
        AND ls.start_time BETWEEN ? AND ?
        ORDER BY ls.start_time DESC
        LIMIT 10
    ");
    $shift_tracker_query->execute([$station_id, $start, $end]);
    $shift_tracker = $shift_tracker_query->fetchAll(PDO::FETCH_ASSOC);

    // Merchandise Sales by Category (real data from merchandise_transaction_items)
    $merch_cat_query = $pdo->prepare("
        SELECT
            COALESCE(NULLIF(mti.category, ''), 'Others') AS category,
            COALESCE(SUM(mti.subtotal), 0) AS total
        FROM merchandise_transaction_items mti
        INNER JOIN merchandise_transactions mt ON mti.transaction_id = mt.id
        WHERE mt.station_id = ?
          AND (
              (mt.created_at BETWEEN ? AND ?)
              OR (DATE(mt.created_at) = ? AND (mt.shift_period IN (?, ?) OR mt.shift_id = ?))
          )
        GROUP BY category
        ORDER BY total DESC
        LIMIT 10
    ");
    $merch_cat_query->execute([$station_id, $start, $end, $today_date, $shift_keys[0], $shift_keys[1], $shift_number]);
    $merch_categories = $merch_cat_query->fetchAll(PDO::FETCH_ASSOC);

    // Job Orders Hourly Trend (real data - count per hour in this shift window)
    $jo_hourly_query = $pdo->prepare("
        SELECT
            HOUR(created_at) AS hr,
            COUNT(*) AS count
        FROM job_orders
        WHERE station_id = ?
          AND (
              (created_at BETWEEN ? AND ?)
              OR (DATE(created_at) = ? AND (shift_id = ?))
          )
        GROUP BY HOUR(created_at)
        ORDER BY hr ASC
    ");
    $jo_hourly_query->execute([$station_id, $start, $end, $today_date, $shift_number]);
    $jo_hourly_rows = $jo_hourly_query->fetchAll(PDO::FETCH_ASSOC);
    // Build a full slot map for each hour in the shift window
    $start_hr = (int)substr($times['start'], 0, 2);
    $end_hr   = (int)substr($times['end'],   0, 2);
    $jo_hourly_map = [];
    for ($h = $start_hr; $h < $end_hr; $h++) {
        $jo_hourly_map[$h] = 0;
    }
    foreach ($jo_hourly_rows as $r) {
        $h = (int)$r['hr'];
        if (array_key_exists($h, $jo_hourly_map)) {
            $jo_hourly_map[$h] = (int)$r['count'];
        }
    }
    $jo_hourly_labels = array_map(function($h) {
        $suffix = $h < 12 ? 'AM' : 'PM';
        $disp   = $h > 12 ? $h - 12 : ($h == 0 ? 12 : $h);
        return "{$disp} {$suffix}";
    }, array_keys($jo_hourly_map));
    $jo_hourly_data = array_values($jo_hourly_map);

    // Fuel Variance Alerts (real data per fuel type for this shift)
    $variance_query = $pdo->prepare("
        SELECT
            va.item_identifier AS fuel_type,
            COALESCE(SUM(ABS(va.variance_amount)), 0) AS variance
        FROM variance_alerts va
        WHERE va.station_id = ?
          AND va.transaction_type = 'Fuel'
          AND va.created_at BETWEEN ? AND ?
        GROUP BY va.item_identifier
        ORDER BY variance DESC
        LIMIT 8
    ");
    $variance_query->execute([$station_id, $start, $end]);
    $variance_data = $variance_query->fetchAll(PDO::FETCH_ASSOC);

    // Job orders count by status for this shift
    $jo_stats = [
        'Pending' => 0,
        'In Progress' => 0,
        'Completed' => 0,
        'Cancelled' => 0
    ];
    if ($shift_number == 1) {
        $jo_q = $pdo->prepare("
            SELECT status, COUNT(*) as count
            FROM job_orders
            WHERE station_id = ?
            AND (
                (created_at BETWEEN ? AND ?)
                OR (DATE(created_at) = ? AND shift_id = 1)
            )
            GROUP BY status
        ");
        $jo_q->execute([$station_id, $start, $end, $today_date]);
    } else {
        $jo_q = $pdo->prepare("
            SELECT status, COUNT(*) as count
            FROM job_orders
            WHERE station_id = ?
            AND (
                (created_at BETWEEN ? AND ?)
                OR (DATE(created_at) = ? AND shift_id = 2)
                OR (status IN ('Pending', 'In Progress', 'Awaiting Parts') AND created_at < ? AND DATE(created_at) = ?)
            )
            GROUP BY status
        ");
        $jo_q->execute([$station_id, $start, $end, $today_date, $start, $today_date]);
    }
    foreach ($jo_q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $status_mapped = $row['status'];
        if ($status_mapped == 'Reviewed') $status_mapped = 'Pending';
        if ($status_mapped == 'Awaiting Parts') $status_mapped = 'In Progress';
        if ($status_mapped == 'Verified' || $status_mapped == 'finalized') $status_mapped = 'Completed';
        if ($status_mapped == 'Rejected') $status_mapped = 'Cancelled';
        
        if (array_key_exists($status_mapped, $jo_stats)) {
            $jo_stats[$status_mapped] += $row['count'];
        }
    }

    // Shift-specific Calendar Tasks: Job Orders + Fuel Deliveries (today + next 3 days)
    $calendar_tasks = [];
    try {
        $jo_task_q = $pdo->prepare("
            SELECT 'Job Order' AS task_type,
                   COALESCE(job_order_number, CONCAT('JO-', id)) AS reference,
                   status,
                   created_at AS task_date,
                   COALESCE(customer_name, 'Walk-in') AS customer,
                   id AS task_id
            FROM job_orders
            WHERE station_id = ?
              AND (
                  (created_at BETWEEN ? AND ?)
                  OR (DATE(created_at) BETWEEN DATE_ADD(?, INTERVAL 1 DAY) AND DATE_ADD(?, INTERVAL 3 DAY) AND (shift_id = ?))
              )
            ORDER BY created_at DESC
            LIMIT 15
        ");
        $jo_task_q->execute([$station_id, $start, $end, $today_date, $today_date, $shift_number]);
        $calendar_tasks = $jo_task_q->fetchAll(PDO::FETCH_ASSOC);

        $fd_task_q = $pdo->prepare("
            SELECT 'Fuel Delivery' AS task_type,
                   COALESCE(batch_id, CONCAT('FD-', id)) AS reference,
                   status,
                   COALESCE(delivery_date, CURDATE()) AS task_date,
                   COALESCE(supplier, 'Supplier TBD') AS customer,
                   id AS task_id
            FROM fuel_deliveries
            WHERE station_id = ?
              AND delivery_date BETWEEN ? AND DATE_ADD(?, INTERVAL 3 DAY)
            ORDER BY delivery_date ASC
            LIMIT 10
        ");
        $fd_task_q->execute([$station_id, $today_date, $today_date]);
        $fd_tasks = $fd_task_q->fetchAll(PDO::FETCH_ASSOC);
        $calendar_tasks = array_merge($calendar_tasks, $fd_tasks);

        usort($calendar_tasks, fn($a, $b) => strtotime($a['task_date']) - strtotime($b['task_date']));
    } catch (Exception $e) {}

    // New customers added during this shift window
    $new_customers_count = 0;
    $credit_customers_list = [];
    try {
        $nc_q = $pdo->prepare("
            SELECT COUNT(*) FROM customers
            WHERE station_id = ?
              AND (
                  (created_at BETWEEN ? AND ?)
                  OR (DATE(created_at) = ? AND shift_id = ?)
              )
        ");
        $nc_q->execute([$station_id, $start, $end, $today_date, $shift_number]);
        $new_customers_count = (int)$nc_q->fetchColumn();

        $cc_q = $pdo->prepare("
            SELECT name, type, COALESCE(current_balance, balance, 0) AS balance,
                   credit_limit, status
            FROM customers
            WHERE station_id = ? AND type = 'credit'
              AND COALESCE(current_balance, balance, 0) > 0
            ORDER BY balance DESC
            LIMIT 10
        ");
        $cc_q->execute([$station_id]);
        $credit_customers_list = $cc_q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    return [
        'fuel'             => $fuel_data,
        'merch'            => $merch_data,
        'service'          => $service_data,
        'payments_summary' => $payments_summary,
        'fuel_levels'      => $fuel_levels,
        'merch_low_stock'  => $merch_low_stock,
        'activity_log'     => $activity_log,
        'shift_tracker'    => $shift_tracker,
        'merch_categories' => $merch_categories,
        'jo_hourly_labels' => $jo_hourly_labels,
        'jo_hourly_data'   => $jo_hourly_data,
        'variance_data'    => $variance_data,
        'jo_stats'         => $jo_stats,
        'calendar_tasks'   => $calendar_tasks,
        'new_customers'    => $new_customers_count,
        'credit_customers' => $credit_customers_list,
        'shift_number'     => $shift_number,
        'shift_label'      => $shift_number == 1 ? '6AM - 2PM' : '2PM - 10PM'
    ];
}

// Get data for shifts based on user access
// Staff can only see their assigned shift
// Admin/Manager can see all shifts
if (in_array($role, ['admin', 'manager', 'superadmin'])) {
    // Admin/Manager: Load both shifts
    $shift1_data = getShiftData($pdo, $station_id, 1, $selected_date);
    $shift2_data = getShiftData($pdo, $station_id, 2, $selected_date);
} else {
    // Staff: Load only their assigned shift
    if ($user_assigned_shift == 1) {
        $shift1_data = getShiftData($pdo, $station_id, 1, $selected_date);
        $shift2_data = null; // No access to Shift 2
    } else {
        $shift1_data = null; // No access to Shift 1
        $shift2_data = getShiftData($pdo, $station_id, 2, $selected_date);
    }
}

// NOTE: jo_stats, new_customers, credit_customers, calendar_tasks are all
// fetched per-shift inside getShiftData() and returned as part of $shift1_data / $shift2_data.

// Include system header with sidebar
include __DIR__ . '/../partials/header.php';
?>
<!-- Load Chart.js at the top to prevent ReferenceErrors -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<!-- Main Content Area -->
<div class="main-content">
    <style>
        /* Reset potential conflicts */
        html, body {
            margin: 0;
            padding: 0;
            overflow-x: hidden;
        }
        
        .main-content {
            margin-left: 0;
            padding: 0;
            min-height: auto;
            width: 100%;
            position: relative;
            background: transparent;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                width: 100%;
            }
        }
        
        /* Dashboard Specific Styles */
        
        .dashboard-container {
            max-width: 100%;
            margin: 0 auto;
            position: relative;
            padding-top: 0; /* Removed large top space */
        }
        
        .header-section {
            background: white;
            padding: 20px 30px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            z-index: 1;
        }
        
        .header-left h1 {
            color: #003366;
            font-size: 28px;
            margin-bottom: 5px;
        }
        
        .header-left p {
            color: #666666;
            font-size: 14px;
        }
        
        .header-right {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        .clock-status {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .clock-status.clocked-in {
            background: #d4edda;
            color: #155724;
        }
        
        .clock-status.clocked-out {
            background: #f8d7da;
            color: #721c24;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background: #003366;
            color: white;
        }
        
        .btn-primary:hover {
            background: #002244;
            transform: translateY(-2px);
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c82333;
            transform: translateY(-2px);
        }
        
        .tabs-container {
            background: white;
            padding: 0;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .tabs {
            display: flex;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .tab {
            flex: 1;
            padding: 15px 20px;
            background: #f7f7f7;
            border: none;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            color: #666666;
        }
        
        .tab.active {
            background: white;
            color: #003366;
            border-bottom: 3px solid #003366;
        }
        
        .tab:hover:not(.active) {
            background: #e8e8e8;
        }
        
        .tab-content {
            display: none;
            padding: 20px;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .widget-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .widget-card h3 {
            color: #003366;
            margin-bottom: 15px;
            font-size: 18px;
        }
        
        .stat-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            transition: transform 0.25s, box-shadow 0.25s;
            min-height: 120px;
            position: relative;
        }
        
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 15px rgba(0,0,0,0.1);
        }
        
        .stat-card .details {
            flex: 1;
        }
        
        .stat-card .details .label {
            font-size: 13px;
            color: #64748b;
            margin: 0 0 6px 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        
        .stat-card .value {
            font-size: 24px;
            font-weight: 700;
            margin: 0;
            color: #1e293b;
        }
        
        .stat-card small {
            font-size: 12px;
            color: #64748b;
            display: block;
            margin: 4px 0 0 0;
        }
        
        .stat-card .icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: rgba(0,38,77,0.1);
            color: #00264D;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 0.5px;
            opacity: 0.85;
            flex-shrink: 0;
            margin-left: 16px;
        }
        
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .chart-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .chart-card h3 {
            color: #003366;
            margin-bottom: 15px;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .chart-card h3 i {
            color: #003366;
        }
        
        .calendar-widget {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .calendar-header h3 {
            color: #003366;
            font-size: 20px;
        }
        
        .task-list {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .task-item {
            padding: 15px;
            border: 1px solid #e2e8f0;
            background: #f7f7f7;
            margin-bottom: 10px;
            border-radius: 5px;
            transition: all 0.3s;
        }
        
        .task-item:hover {
            background: #e8e8e8;
            transform: translateX(5px);
        }
        
        .task-item.today {
            background: #d4edda;
        }
        
        .task-item .task-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }
        
        .task-item .task-title {
            font-weight: 600;
            color: #003366;
        }
        
        .task-item .task-date {
            font-size: 12px;
            color: #666666;
        }
        
        .task-item .task-status {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-top: 5px;
        }
        
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .quick-action-btn {
            padding: 15px;
            background: #003366;
            color: white;
            text-align: center;
            border-radius: 10px;
            text-decoration: none;
            transition: all 0.3s;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .quick-action-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.15);
            background: #002244;
        }
        
        .quick-action-btn i {
            display: none;
        }
        
        .consolidation-panel {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-top: 30px;
        }
        
        .consolidation-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .consolidation-header h2 {
            color: #003366;
            font-size: 32px;
            margin-bottom: 10px;
        }
        
        .consolidation-header p {
            color: #666666;
            font-size: 16px;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }
        
        @media (max-width: 768px) {
            .header-section {
                flex-direction: column;
                gap: 15px;
            }
            
            .charts-grid {
                grid-template-columns: 1fr;
            }
            
            .tabs {
                flex-direction: column;
            }
        }
    </style>

    <div class="dashboard-container">
        <!-- Page Title with Welcome -->
        <div style="margin-bottom: 20px;">
            <h1 style="margin: 0 0 5px 0; font-size: 28px; font-weight: 700; color: #003366;">
                Staff Dashboard
            </h1>
            <p style="margin: 0 0 8px 0; font-size: 20px; color: #666666;">
                Welcome, <?= htmlspecialchars($me['first_name'] ?? $display_name) ?>!
            </p>
            
            <!-- Clock Status - Small text below welcome (Read-only display) -->
            <?php if ($clocked_in): ?>
                <div style="font-size: 8px; color: #155724; display: flex; align-items: center; gap: 8px;">
                    <span style="padding: 2px 6px; background: #d4edda; border-radius: 4px; border-left: 2px solid #28a745; font-weight: 600;">
                        Clocked In - <?= $clock_in_shift ?> • Since <?= date('h:i A', strtotime($clock_in_time)) ?>
                    </span>
                </div>
            <?php else: ?>
                <div style="font-size: 8px; color: #721c24;">
                    <span style="padding: 2px 6px; background: #f8d7da; border-radius: 4px; border-left: 2px solid #dc3545; font-weight: 600;">
                        Not Clocked In
                    </span>
                </div>
            <?php endif; ?>
        </div>

        <!-- Date Selector / Status Info -->
        <div style="margin-bottom: 25px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                <span style="font-weight: 700; color: #003366; font-size: 14px; text-transform: uppercase; letter-spacing: 0.5px;">Viewing Data For:</span>
                <span style="background: #003366; color: white; padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 700; box-shadow: 0 2px 4px rgba(0,51,102,0.2);">
                    <?= date('F j, Y', strtotime($selected_date)) ?>
                </span>
                <?php if ($selected_date !== date('Y-m-d')): ?>
                <?php endif; ?>
            </div>
            <form method="GET" style="display: flex; align-items: center; gap: 8px; margin: 0; flex-wrap: wrap;">
                <input type="date" name="date" value="<?= htmlspecialchars($selected_date) ?>" style="padding: 7px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; color: #334155; outline: none; background: white; font-weight: 500;">
                <button type="submit" style="background: #003366; color: white; border: none; padding: 7px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.2s; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    Filter Date
                </button>
                <?php if (isset($_GET['date'])): ?>
                    <a href="staff_dashboard.php" style="background: #64748b; color: white; text-decoration: none; padding: 7px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; transition: all 0.2s; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        Reset
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Flash Messages -->
        <?php if ($flash_success): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($flash_success) ?>
            </div>
        <?php endif; ?>
        
        <?php if ($flash_error): ?>
            <div class="alert alert-error">
                <?= htmlspecialchars($flash_error) ?>
            </div>
        <?php endif; ?>
        
        <!-- Tabs (Hidden for staff, only shown for admin/manager) -->
        <?php if ($can_view_consolidation): ?>
        <div class="tabs-container">
            <div class="tabs">
                <?php if ($user_assigned_shift == 1 || $can_view_consolidation): ?>
                    <button class="tab <?= ($user_assigned_shift == 1 && !$can_view_consolidation) ? 'active' : '' ?>" data-tab="shift1">
                        Shift 1 (6AM - 2PM)
                    </button>
                <?php endif; ?>
                
                <?php if ($user_assigned_shift == 2 || $can_view_consolidation): ?>
                    <button class="tab <?= ($user_assigned_shift == 2 && !$can_view_consolidation) ? 'active' : '' ?>" data-tab="shift2">
                        Shift 2 (2PM - 10PM)
                    </button>
                <?php endif; ?>
                
                <?php if ($can_view_consolidation): ?>
                    <button class="tab active" data-tab="consolidation">
                        Daily Consolidation
                    </button>
                <?php endif; ?>
            </div>
        <?php endif; ?>
            
            <!-- SHIFT 1 CONTENT -->
            <?php if ($shift1_data): ?>
            <div class="tab-content <?= ($user_assigned_shift == 1 && !$can_view_consolidation) ? 'active' : '' ?>" id="shift1">
                
                <!-- Shift Tracker -->
                <div class="widget-card" style="margin-bottom: 20px;">
                    <h3 style="color: #003366;">Shift Tracker - Staff Clock In/Out</h3>
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: #f7f7f7;">
                                    <th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">Staff Name</th>
                                    <th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">Clock In</th>
                                    <th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">Clock Out</th>
                                    <th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">Duration</th>
                                    <th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($shift1_data['shift_tracker'])): ?>
                                    <?php foreach ($shift1_data['shift_tracker'] as $tracker): ?>
                                        <tr>
                                            <td style="padding: 10px; border-bottom: 1px solid #eee;"><?= htmlspecialchars($tracker['full_name']) ?></td>
                                            <td style="padding: 10px; border-bottom: 1px solid #eee;"><?= date('g:i A', strtotime($tracker['start_time'])) ?></td>
                                            <td style="padding: 10px; border-bottom: 1px solid #eee;"><?= $tracker['end_time'] ? date('g:i A', strtotime($tracker['end_time'])) : '-' ?></td>
                                            <td style="padding: 10px; border-bottom: 1px solid #eee;"><?= floor($tracker['duration_min'] / 60) ?>h <?= $tracker['duration_min'] % 60 ?>m</td>
                                            <td style="padding: 10px; border-bottom: 1px solid #eee;">
                                                <span style="padding: 3px 10px; border-radius: 12px; font-size: 11px; background: <?= $tracker['status'] == 'Active' ? '#d4edda' : '#e8e8e8' ?>; color: <?= $tracker['status'] == 'Active' ? '#155724' : '#666666' ?>;">
                                                    <?= $tracker['status'] ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" style="padding: 20px; text-align: center; color: #666666;">No staff clocked in for this shift</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Summary Cards -->
                <div class="cards-grid">
                    <div class="stat-card">
                        <div class="details">
                            <div class="label">Fuel Sales</div>
                            <div class="value">₱<?= number_format(array_sum(array_column($shift1_data['fuel'], 'revenue')), 2) ?></div>
                            <small><?= number_format(array_sum(array_column($shift1_data['fuel'], 'liters')), 2) ?> L sold</small>
                        </div>
                        <div class="icon"><i class="fas fa-gas-pump"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="details">
                            <div class="label">Merchandise Sales</div>
                            <div class="value">₱<?= number_format($shift1_data['merch']['total'], 2) ?></div>
                            <small><?= $shift1_data['merch']['count'] ?> transactions</small>
                        </div>
                        <div class="icon"><i class="fas fa-shopping-cart"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="details">
                            <div class="label">Service Income</div>
                            <div class="value">₱<?= number_format($shift1_data['service']['total_service_income'], 2) ?></div>
                            <small><?= $shift1_data['service']['completed_jobs'] ?> completed jobs</small>
                        </div>
                        <div class="icon"><i class="fas fa-wrench"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="details">
                            <div class="label">Total Payments Collected</div>
                            <div class="value">₱<?= number_format($shift1_data['payments_summary']['total'], 2) ?></div>
                            <small>Fuel + Merch + Service</small>
                        </div>
                        <div class="icon"><i class="fas fa-money-bill-wave"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="details">
                            <div class="label">Job Orders</div>
                            <div class="value"><?= array_sum($shift1_data['jo_stats']) ?></div>
                            <small><?= $shift1_data['jo_stats']['Pending'] ?? 0 ?> pending &bull; <?= $shift1_data['jo_stats']['In Progress'] ?? 0 ?> in progress &bull; <?= $shift1_data['jo_stats']['Completed'] ?? 0 ?> done</small>
                        </div>
                        <div class="icon"><i class="fas fa-clipboard-list"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="details">
                            <div class="label">New Customers</div>
                            <div class="value"><?= $shift1_data['new_customers'] ?></div>
                            <small>Added this shift</small>
                        </div>
                        <div class="icon"><i class="fas fa-users"></i></div>
                    </div>
                </div>

                <!-- Fuel Monitoring -->
                <div class="widget-card" style="margin:20px 0;">
                    <h3 style="color:#003366;">Fuel Tank Levels &amp; Monitoring</h3>
                    <?php if (empty($shift1_data['fuel_levels'])): ?>
                        <p style="color:#666;padding:20px;text-align:center;">No fuel inventory data available.</p>
                    <?php else: ?>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;margin-top:15px;">
                        <?php foreach ($shift1_data['fuel_levels'] as $fl):
                            $pct = $fl['capacity'] > 0 ? min(100, ($fl['current_stock'] / $fl['capacity']) * 100) : 0;
                            $c   = ($fl['stock_status']==='Critical'||$fl['stock_status']==='Out of Stock') ? '#dc3545' : ($fl['stock_status']==='Low Stock' ? '#fd7e14' : '#28a745');
                        ?>
                        <div style="background:#f7f7f7;padding:15px;border-radius:8px;border-left:4px solid <?= $c ?>;">
                            <div style="font-weight:700;color:#003366;margin-bottom:4px;"><?= htmlspecialchars($fl['fuel_type_name']) ?></div>
                            <div style="font-size:22px;font-weight:700;color:#003366;"><?= number_format($fl['current_stock'],2) ?> L</div>
                            <div style="font-size:11px;color:#666;">Capacity: <?= number_format($fl['capacity'],2) ?> L</div>
                            <span style="padding:2px 8px;border-radius:10px;font-size:10px;font-weight:600;background:<?= $c ?>22;color:<?= $c ?>;display:inline-block;margin-top:4px;"><?= $fl['stock_status'] ?></span>
                            <?php if ($fl['capacity']>0): ?>
                            <div style="background:#e0e0e0;height:6px;border-radius:3px;margin-top:8px;overflow:hidden;">
                                <div style="background:<?= $c ?>;height:100%;width:<?= round($pct) ?>%;"></div>
                            </div>
                            <div style="font-size:10px;color:#999;margin-top:2px;"><?= round($pct) ?>% full</div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Fuel Tank Utilization Chart -->
                <?php if (!empty($shift1_data['fuel_levels'])): ?>
                <div class="chart-card" style="margin:20px 0;">
                    <h3><i class="fas fa-gas-pump" style="color:#003366;"></i> Fuel Tank Utilization</h3>
                    <canvas id="shift1TankChart" height="120"></canvas>
                </div>
                <?php endif; ?>

                <!-- Merchandise Inventory — Low Stock Alerts -->
                <div class="widget-card" style="margin:20px 0;">
                    <h3 style="color:#003366;">Merchandise Inventory — Low Stock Alerts</h3>
                    <?php if (!empty($shift1_data['merch_low_stock'])): ?>
                    <div style="overflow-x:auto;margin-top:15px;">
                        <table style="width:100%;border-collapse:collapse;">
                            <thead>
                                <tr style="background:#f7f7f7;">
                                    <th style="padding:10px;text-align:left;border-bottom:2px solid #ddd;">Product</th>
                                    <th style="padding:10px;text-align:left;border-bottom:2px solid #ddd;">Current</th>
                                    <th style="padding:10px;text-align:left;border-bottom:2px solid #ddd;">Reorder</th>
                                    <th style="padding:10px;text-align:left;border-bottom:2px solid #ddd;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach($shift1_data['merch_low_stock'] as $item):
                                $pct2=max(0,round(($item['current_stock']??0)/max(1,$item['reorder_level']??1)*100));
                                $sc=$pct2<=25?'#dc3545':($pct2<=50?'#fd7e14':'#ffc107');
                            ?>
                            <tr>
                                <td style="padding:10px;border-bottom:1px solid #eee;"><?=htmlspecialchars($item['product_name'])?></td>
                                <td style="padding:10px;border-bottom:1px solid #eee;"><?=number_format($item['current_stock']??0)?></td>
                                <td style="padding:10px;border-bottom:1px solid #eee;"><?=number_format($item['reorder_level']??0)?></td>
                                <td style="padding:10px;border-bottom:1px solid #eee;"><span style="background:<?=$sc?>22;color:<?=$sc?>;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;"><?=$pct2?>%</span></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- Low Stock Bar Chart -->
                    <div style="margin-top:16px;"><canvas id="shift1MerchStockChart" height="100"></canvas></div>
                    <?php else: ?>
                        <p style="text-align: center; color: #666666; padding: 20px;"><i class="fas fa-check-circle" style="color:#28a745;"></i> All merchandise items are adequately stocked</p>
                    <?php endif; ?>
                </div>
                
                <!-- Charts Grid -->
                <div class="charts-grid">
                    <div class="chart-card">
                        <h3>Fuel Sales by Type (Liters)</h3>
                        <canvas id="shift1FuelChart"></canvas>
                    </div>
                    <div class="chart-card">
                        <h3>Job Orders Trend (Hourly)</h3>
                        <canvas id="shift1JobOrderChart"></canvas>
                    </div>
                    <div class="chart-card">
                        <h3>Merchandise Sales by Category</h3>
                        <canvas id="shift1MerchCategoryChart"></canvas>
                    </div>
                    <div class="chart-card">
                        <h3>Payment Methods Breakdown</h3>
                        <canvas id="shift1PaymentChart"></canvas>
                    </div>
                </div>

                <!-- Job Orders Status Breakdown -->
                <div class="widget-card" style="margin:20px 0;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
                        <h3 style="color:#003366;margin:0;">Job Orders — Shift 1</h3>
                        <a href="staff_transactions_hub.php?section=merchandise&active_tab=tracker" style="font-size:13px;color:#003366;text-decoration:none;font-weight:600;">View All &rarr;</a>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;">
                        <?php
                        $jo_display = [
                            'Pending'    => ['#fd7e14','#fff3e0'],
                            'In Progress'=> ['#003366','#e8f0ff'],
                            'Completed'  => ['#28a745','#d4edda'],
                            'Cancelled'  => ['#dc3545','#fde8ea'],
                        ];
                        foreach ($jo_display as $label => [$fg,$bg]):
                            $cnt = $shift1_data['jo_stats'][$label] ?? 0;
                        ?>
                        <div style="background:<?= $bg ?>;border-radius:10px;padding:15px;text-align:center;border-top:3px solid <?= $fg ?>;">
                            <div style="font-size:28px;font-weight:700;color:<?= $fg ?>;"><?= $cnt ?></div>
                            <div style="font-size:12px;color:#555;margin-top:4px;font-weight:600;"><?= $label ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Payments Summary -->
                <div class="widget-card" style="margin:20px 0;">
                    <h3 style="color:#003366;margin-bottom:15px;">Payments Summary — Shift 1</h3>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px;">
                        <?php
                        $pay_modes = [
                            'Cash'         => ['#28a745', $shift1_data['payments_summary']['cash']],
                            'Card'         => ['#003366', $shift1_data['payments_summary']['card']],
                            'E-Wallet'     => ['#17a2b8', $shift1_data['payments_summary']['ewallet']],
                            'E-Fuel Card'  => ['#fd7e14', $shift1_data['payments_summary']['efuel']],
                            'Fleet Card'   => ['#6c757d', $shift1_data['payments_summary']['fleet']],
                        ];
                        foreach ($pay_modes as $mode => [$col, $amt]):
                        ?>
                        <div style="background:#f7f7f7;border-radius:10px;padding:14px;border-left:4px solid <?= $col ?>;">
                            <div style="font-size:11px;color:#666;font-weight:600;"><?= $mode ?></div>
                            <div style="font-size:20px;font-weight:700;color:<?= $col ?>;margin-top:4px;">₱<?= number_format($amt,2) ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Customers -->
                <div class="widget-card" style="margin:20px 0;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
                        <h3 style="color:#003366;margin:0;">Customers</h3>
                        <a href="staff_customers_report.php" style="font-size:13px;color:#003366;text-decoration:none;font-weight:600;">View All &rarr;</a>
                    </div>
                    <div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:15px;">
                        <div style="background:#d4edda;border-radius:10px;padding:15px 25px;text-align:center;flex:1;min-width:120px;">
                            <div style="font-size:28px;font-weight:700;color:#28a745;"><?= $shift1_data['new_customers'] ?></div>
                            <div style="font-size:12px;color:#155724;font-weight:600;">New This Shift</div>
                        </div>
                        <div style="background:#fff3cd;border-radius:10px;padding:15px 25px;text-align:center;flex:1;min-width:120px;">
                            <div style="font-size:28px;font-weight:700;color:#856404;"><?= count($shift1_data['credit_customers']) ?></div>
                            <div style="font-size:12px;color:#856404;font-weight:600;">With Balance</div>
                        </div>
                    </div>
                    <?php if (!empty($shift1_data['credit_customers'])): ?>
                    <table style="width:100%;border-collapse:collapse;font-size:13px;">
                        <thead>
                            <tr style="background:#f7f7f7;">
                                <th style="padding:8px 10px;text-align:left;border-bottom:2px solid #ddd;">Customer</th>
                                <th style="padding:8px 10px;text-align:right;border-bottom:2px solid #ddd;">Balance</th>
                                <th style="padding:8px 10px;text-align:right;border-bottom:2px solid #ddd;">Credit Limit</th>
                                <th style="padding:8px 10px;text-align:center;border-bottom:2px solid #ddd;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($shift1_data['credit_customers'] as $cc): ?>
                            <tr>
                                <td style="padding:8px 10px;border-bottom:1px solid #eee;"><?= htmlspecialchars($cc['name']) ?></td>
                                <td style="padding:8px 10px;border-bottom:1px solid #eee;text-align:right;color:#dc3545;font-weight:600;">₱<?= number_format($cc['balance'],2) ?></td>
                                <td style="padding:8px 10px;border-bottom:1px solid #eee;text-align:right;">₱<?= number_format($cc['credit_limit'],2) ?></td>
                                <td style="padding:8px 10px;border-bottom:1px solid #eee;text-align:center;">
                                    <span style="padding:2px 8px;border-radius:10px;font-size:10px;font-weight:600;background:<?= $cc['status']==='active'?'#d4edda':'#f8d7da' ?>;color:<?= $cc['status']==='active'?'#155724':'#721c24' ?>;"><?= ucfirst($cc['status']) ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                        <p style="color:#666;text-align:center;padding:10px;">No outstanding credit balances.</p>
                    <?php endif; ?>
                </div>

                <!-- Activity Log -->
                <div class="widget-card" style="margin:20px 0;">
                    <h3 style="color:#003366;margin-bottom:15px;">Activity Log — Shift 1</h3>
                    <div style="max-height:350px;overflow-y:auto;">
                        <?php if (!empty($shift1_data['activity_log'])): ?>
                            <?php foreach ($shift1_data['activity_log'] as $log): ?>
                            <div style="padding:10px 12px;border-left:3px solid #003366;background:#f7f7f7;margin-bottom:8px;border-radius:5px;">
                                <div style="display:flex;justify-content:space-between;margin-bottom:3px;">
                                    <span style="font-weight:600;color:#003366;font-size:13px;"><?= htmlspecialchars($log['action_type']) ?></span>
                                    <span style="font-size:11px;color:#999;"><?= date('g:i A', strtotime($log['created_at'])) ?></span>
                                </div>
                                <div style="font-size:12px;color:#555;"><?= htmlspecialchars($log['action_details']) ?></div>
                                <div style="font-size:11px;color:#aaa;margin-top:2px;">by <?= htmlspecialchars($log['username']) ?></div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="text-align:center;color:#666;padding:20px;">No activity recorded for this shift.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Calendar Widget — Today's Tasks + Upcoming 3 Days -->
                <div class="calendar-widget">
                    <div class="calendar-header">
                        <h3>Tasks &amp; Upcoming Schedule</h3>
                        <span style="color:#666;font-size:14px;"><?= date('F j, Y', strtotime($selected_date)) ?></span>
                    </div>
                    <div class="task-list">
                        <?php if (empty($shift1_data['calendar_tasks'])): ?>
                            <p style="text-align:center;color:#666;padding:20px;">No tasks or deliveries in the next 3 days.</p>
                        <?php else: ?>
                            <?php foreach ($shift1_data['calendar_tasks'] as $task):
                                $isToday = date('Y-m-d', strtotime($task['task_date'])) === date('Y-m-d');
                                $typeBg  = $task['task_type'] === 'Fuel Delivery' ? '#fff3e0' : '#e8f0ff';
                                $typeFg  = $task['task_type'] === 'Fuel Delivery' ? '#fd7e14' : '#003366';
                            ?>
                            <div class="task-item <?= $isToday ? 'today' : '' ?>">
                                <div class="task-header">
                                    <span class="task-title"><?= htmlspecialchars($task['reference']) ?></span>
                                    <span class="task-date"><?= date('M j, g:i A', strtotime($task['task_date'])) ?></span>
                                </div>
                                <div style="font-size:12px;color:#555;margin-top:2px;"><?= htmlspecialchars($task['customer']) ?></div>
                                <div style="margin-top:5px;display:flex;gap:8px;flex-wrap:wrap;">
                                    <span style="padding:2px 8px;border-radius:10px;font-size:10px;font-weight:600;background:<?= $typeBg ?>;color:<?= $typeFg ?>;"><?= $task['task_type'] ?></span>
                                    <span class="task-status" style="background:#e8e8e8;color:#003366;"><?= htmlspecialchars($task['status']) ?></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <!-- Quick Actions -->
                <div style="background:#f7f7f7;padding:20px;border-radius:10px;margin-top:20px;">
                    <h3 style="color:#003366;margin-bottom:15px;">Quick Actions</h3>
                    <div class="quick-actions">
                        <a href="staff_transactions_hub.php?section=merchandise" class="quick-action-btn"><div>POS / Merchandise</div></a>
                        <a href="staff_transactions_hub.php?section=merchandise" class="quick-action-btn"><div>Credit Sale</div></a>
                        <a href="staff_transactions_hub.php?section=merchandise&active_tab=tracker" class="quick-action-btn"><div>Job Orders</div></a>
                        <a href="staff_transactions_hub.php?section=fuel" class="quick-action-btn"><div>Fuel Transactions</div></a>
                        <a href="staff_record_delivery.php" class="quick-action-btn"><div>Receive Items</div></a>
                        <a href="my_shift.php" class="quick-action-btn"><div>My Shift</div></a>
                    </div>
                </div>
            </div>
            <?php endif; // End Shift 1 Content ?>

            
            <!-- SHIFT 2 CONTENT -->
            <?php if ($shift2_data): ?>
            <div class="tab-content <?= ($user_assigned_shift == 2 && !$can_view_consolidation) ? 'active' : '' ?>" id="shift2">
                
                <!-- Shift Tracker -->
                <div class="widget-card" style="margin-bottom: 20px;">
                    <h3 style="color: #003366;">Shift Tracker - Staff Clock In/Out</h3>
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: #f7f7f7;">
                                    <th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">Staff Name</th>
                                    <th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">Clock In</th>
                                    <th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">Clock Out</th>
                                    <th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">Duration</th>
                                    <th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($shift2_data['shift_tracker'])): ?>
                                    <?php foreach ($shift2_data['shift_tracker'] as $tracker): ?>
                                        <tr>
                                            <td style="padding: 10px; border-bottom: 1px solid #eee;"><?= htmlspecialchars($tracker['full_name']) ?></td>
                                            <td style="padding: 10px; border-bottom: 1px solid #eee;"><?= date('g:i A', strtotime($tracker['start_time'])) ?></td>
                                            <td style="padding: 10px; border-bottom: 1px solid #eee;"><?= $tracker['end_time'] ? date('g:i A', strtotime($tracker['end_time'])) : '-' ?></td>
                                            <td style="padding: 10px; border-bottom: 1px solid #eee;"><?= floor($tracker['duration_min'] / 60) ?>h <?= $tracker['duration_min'] % 60 ?>m</td>
                                            <td style="padding: 10px; border-bottom: 1px solid #eee;">
                                                <span style="padding: 3px 10px; border-radius: 12px; font-size: 11px; background: <?= $tracker['status'] == 'Active' ? '#d4edda' : '#e8e8e8' ?>; color: <?= $tracker['status'] == 'Active' ? '#155724' : '#666666' ?>;">
                                                    <?= $tracker['status'] ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" style="padding: 20px; text-align: center; color: #666666;">No staff clocked in for this shift</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Summary Cards -->
                <div class="cards-grid">
                    <div class="stat-card">
                        <div class="details">
                            <div class="label">Fuel Sales</div>
                            <div class="value">₱<?= number_format(array_sum(array_column($shift2_data['fuel'], 'revenue')), 2) ?></div>
                            <small><?= number_format(array_sum(array_column($shift2_data['fuel'], 'liters')), 2) ?> L sold</small>
                        </div>
                        <div class="icon"><i class="fas fa-gas-pump"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="details">
                            <div class="label">Merchandise Sales</div>
                            <div class="value">₱<?= number_format($shift2_data['merch']['total'], 2) ?></div>
                            <small><?= $shift2_data['merch']['count'] ?> transactions</small>
                        </div>
                        <div class="icon"><i class="fas fa-shopping-cart"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="details">
                            <div class="label">Service Income</div>
                            <div class="value">₱<?= number_format($shift2_data['service']['total_service_income'], 2) ?></div>
                            <small><?= $shift2_data['service']['completed_jobs'] ?> completed jobs</small>
                        </div>
                        <div class="icon"><i class="fas fa-wrench"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="details">
                            <div class="label">Total Payments Collected</div>
                            <div class="value">₱<?= number_format($shift2_data['payments_summary']['total'], 2) ?></div>
                            <small>Fuel + Merch + Service</small>
                        </div>
                        <div class="icon"><i class="fas fa-money-bill-wave"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="details">
                            <div class="label">Job Orders</div>
                            <div class="value"><?= array_sum($shift2_data['jo_stats']) ?></div>
                            <small><?= $shift2_data['jo_stats']['Pending'] ?? 0 ?> pending &bull; <?= $shift2_data['jo_stats']['In Progress'] ?? 0 ?> in progress &bull; <?= $shift2_data['jo_stats']['Completed'] ?? 0 ?> done</small>
                        </div>
                        <div class="icon"><i class="fas fa-clipboard-list"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="details">
                            <div class="label">New Customers</div>
                            <div class="value"><?= $shift2_data['new_customers'] ?></div>
                            <small>Added this shift</small>
                        </div>
                        <div class="icon"><i class="fas fa-users"></i></div>
                    </div>
                </div>

                <!-- Fuel Monitoring with Variance -->
                <div class="widget-card" style="margin:20px 0;">
                    <h3 style="color:#003366;">Fuel Tank Levels &amp; Variance Alerts</h3>
                    <?php if (empty($shift2_data['fuel_levels'])): ?>
                        <p style="color:#666;padding:20px;text-align:center;">No fuel inventory data available.</p>
                    <?php else: ?>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;margin-top:15px;">
                        <?php foreach ($shift2_data['fuel_levels'] as $fl):
                            $pct = $fl['capacity'] > 0 ? min(100, ($fl['current_stock'] / $fl['capacity']) * 100) : 0;
                            $c   = ($fl['stock_status']==='Critical'||$fl['stock_status']==='Out of Stock') ? '#dc3545' : ($fl['stock_status']==='Low Stock' ? '#fd7e14' : '#28a745');
                        ?>
                        <div style="background:#f7f7f7;padding:15px;border-radius:8px;border-left:4px solid <?= $c ?>;">
                            <div style="font-weight:700;color:#003366;margin-bottom:4px;"><?= htmlspecialchars($fl['fuel_type_name']) ?></div>
                            <div style="font-size:22px;font-weight:700;color:#003366;"><?= number_format($fl['current_stock'],2) ?> L</div>
                            <div style="font-size:11px;color:#666;">Capacity: <?= number_format($fl['capacity'],2) ?> L</div>
                            <span style="padding:2px 8px;border-radius:10px;font-size:10px;font-weight:600;background:<?= $c ?>22;color:<?= $c ?>;display:inline-block;margin-top:4px;"><?= $fl['stock_status'] ?></span>
                            <?php if ($fl['capacity']>0): ?>
                            <div style="background:#e0e0e0;height:6px;border-radius:3px;margin-top:8px;overflow:hidden;">
                                <div style="background:<?= $c ?>;height:100%;width:<?= round($pct) ?>%;"></div>
                            </div>
                            <div style="font-size:10px;color:#999;margin-top:2px;"><?= round($pct) ?>% full</div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Fuel Tank Utilization Chart Shift 2 -->
                <?php if (!empty($shift2_data['fuel_levels'])): ?>
                <div class="chart-card" style="margin:20px 0;">
                    <h3><i class="fas fa-gas-pump" style="color:#003366;"></i> Fuel Tank Utilization</h3>
                    <canvas id="shift2TankChart" height="120"></canvas>
                </div>
                <?php endif; ?>

                <!-- Merchandise Inventory — Low Stock Alerts -->
                <div class="widget-card" style="margin:20px 0;">
                    <h3 style="color:#003366;">Merchandise Inventory — Low Stock Alerts</h3>
                    <?php if (!empty($shift2_data['merch_low_stock'])): ?>
                    <div style="overflow-x:auto;margin-top:15px;">
                        <table style="width:100%;border-collapse:collapse;">
                            <thead>
                                <tr style="background:#f7f7f7;">
                                    <th style="padding:10px;text-align:left;border-bottom:2px solid #ddd;">Product</th>
                                    <th style="padding:10px;text-align:left;border-bottom:2px solid #ddd;">Category</th>
                                    <th style="padding:10px;text-align:center;border-bottom:2px solid #ddd;">Stock</th>
                                    <th style="padding:10px;text-align:center;border-bottom:2px solid #ddd;">Reorder Level</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($shift2_data['merch_low_stock'] as $item): ?>
                                <tr>
                                    <td style="padding:10px;border-bottom:1px solid #eee;"><?= htmlspecialchars($item['product_name']) ?></td>
                                    <td style="padding:10px;border-bottom:1px solid #eee;"><?= htmlspecialchars($item['category']) ?></td>
                                    <td style="padding:10px;border-bottom:1px solid #eee;text-align:center;"><span style="color:<?= $item['current_stock']<=0?'#dc3545':'#fd7e14' ?>;font-weight:700;"><?= $item['current_stock'] ?></span></td>
                                    <td style="padding:10px;border-bottom:1px solid #eee;text-align:center;"><?= $item['threshold'] ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- Low Stock Bar Chart -->
                    <div style="margin-top:16px;"><canvas id="shift2MerchStockChart" height="100"></canvas></div>
                    <?php else: ?>
                        <p style="text-align:center;color:#28a745;padding:20px;font-weight:600;">All merchandise items are adequately stocked.</p>
                    <?php endif; ?>
                </div>

                <!-- Charts Grid -->
                <div class="charts-grid">
                    <div class="chart-card">
                        <h3>Fuel Sales by Type (Liters)</h3>
                        <canvas id="shift2FuelChart"></canvas>
                    </div>
                    <div class="chart-card">
                        <h3>Job Orders Trend (Hourly)</h3>
                        <canvas id="shift2JobOrderChart"></canvas>
                    </div>
                    <div class="chart-card">
                        <h3>Merchandise Sales by Category</h3>
                        <canvas id="shift2MerchCategoryChart"></canvas>
                    </div>
                    <div class="chart-card">
                        <h3>Payment Methods Breakdown</h3>
                        <canvas id="shift2PaymentChart"></canvas>
                    </div>
                    <div class="chart-card">
                        <h3>Variance Alerts</h3>
                        <canvas id="shift2VarianceChart"></canvas>
                    </div>
                </div>

                <!-- Job Orders Status Breakdown -->
                <div class="widget-card" style="margin:20px 0;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
                        <h3 style="color:#003366;margin:0;">Job Orders — Shift 2</h3>
                        <a href="staff_transactions_hub.php?section=merchandise&active_tab=tracker" style="font-size:13px;color:#003366;text-decoration:none;font-weight:600;">View All &rarr;</a>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;">
                        <?php
                        $jo_display2 = [
                            'Pending'    => ['#fd7e14','#fff3e0'],
                            'In Progress'=> ['#003366','#e8f0ff'],
                            'Completed'  => ['#28a745','#d4edda'],
                            'Cancelled'  => ['#dc3545','#fde8ea'],
                        ];
                        foreach ($jo_display2 as $label => [$fg,$bg]):
                            $cnt = $shift2_data['jo_stats'][$label] ?? 0;
                        ?>
                        <div style="background:<?= $bg ?>;border-radius:10px;padding:15px;text-align:center;border-top:3px solid <?= $fg ?>;">
                            <div style="font-size:28px;font-weight:700;color:<?= $fg ?>;"><?= $cnt ?></div>
                            <div style="font-size:12px;color:#555;margin-top:4px;font-weight:600;"><?= $label ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Payments Summary -->
                <div class="widget-card" style="margin:20px 0;">
                    <h3 style="color:#003366;margin-bottom:15px;">Payments Summary — Shift 2</h3>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px;">
                        <?php
                        $pay_modes2 = [
                            'Cash'         => ['#28a745', $shift2_data['payments_summary']['cash']],
                            'Card'         => ['#003366', $shift2_data['payments_summary']['card']],
                            'E-Wallet'     => ['#17a2b8', $shift2_data['payments_summary']['ewallet']],
                            'E-Fuel Card'  => ['#fd7e14', $shift2_data['payments_summary']['efuel']],
                            'Fleet Card'   => ['#6c757d', $shift2_data['payments_summary']['fleet']],
                        ];
                        foreach ($pay_modes2 as $mode => [$col, $amt]):
                        ?>
                        <div style="background:#f7f7f7;border-radius:10px;padding:14px;border-left:4px solid <?= $col ?>;">
                            <div style="font-size:11px;color:#666;font-weight:600;"><?= $mode ?></div>
                            <div style="font-size:20px;font-weight:700;color:<?= $col ?>;margin-top:4px;">₱<?= number_format($amt,2) ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Customers -->
                <div class="widget-card" style="margin:20px 0;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
                        <h3 style="color:#003366;margin:0;">Customers</h3>
                        <a href="staff_customers_report.php" style="font-size:13px;color:#003366;text-decoration:none;font-weight:600;">View All &rarr;</a>
                    </div>
                    <div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:15px;">
                        <div style="background:#d4edda;border-radius:10px;padding:15px 25px;text-align:center;flex:1;min-width:120px;">
                            <div style="font-size:28px;font-weight:700;color:#28a745;"><?= $shift2_data['new_customers'] ?></div>
                            <div style="font-size:12px;color:#155724;font-weight:600;">New This Shift</div>
                        </div>
                        <div style="background:#fff3cd;border-radius:10px;padding:15px 25px;text-align:center;flex:1;min-width:120px;">
                            <div style="font-size:28px;font-weight:700;color:#856404;"><?= count($shift2_data['credit_customers']) ?></div>
                            <div style="font-size:12px;color:#856404;font-weight:600;">With Balance</div>
                        </div>
                    </div>
                    <?php if (!empty($shift2_data['credit_customers'])): ?>
                    <table style="width:100%;border-collapse:collapse;font-size:13px;">
                        <thead>
                            <tr style="background:#f7f7f7;">
                                <th style="padding:8px 10px;text-align:left;border-bottom:2px solid #ddd;">Customer</th>
                                <th style="padding:8px 10px;text-align:right;border-bottom:2px solid #ddd;">Balance</th>
                                <th style="padding:8px 10px;text-align:right;border-bottom:2px solid #ddd;">Credit Limit</th>
                                <th style="padding:8px 10px;text-align:center;border-bottom:2px solid #ddd;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($shift2_data['credit_customers'] as $cc): ?>
                            <tr>
                                <td style="padding:8px 10px;border-bottom:1px solid #eee;"><?= htmlspecialchars($cc['name']) ?></td>
                                <td style="padding:8px 10px;border-bottom:1px solid #eee;text-align:right;color:#dc3545;font-weight:600;">₱<?= number_format($cc['balance'],2) ?></td>
                                <td style="padding:8px 10px;border-bottom:1px solid #eee;text-align:right;">₱<?= number_format($cc['credit_limit'],2) ?></td>
                                <td style="padding:8px 10px;border-bottom:1px solid #eee;text-align:center;">
                                    <span style="padding:2px 8px;border-radius:10px;font-size:10px;font-weight:600;background:<?= $cc['status']==='active'?'#d4edda':'#f8d7da' ?>;color:<?= $cc['status']==='active'?'#155724':'#721c24' ?>;"><?= ucfirst($cc['status']) ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                        <p style="color:#666;text-align:center;padding:10px;">No outstanding credit balances.</p>
                    <?php endif; ?>
                </div>

                <!-- Activity Log -->
                <div class="widget-card" style="margin:20px 0;">
                    <h3 style="color:#003366;margin-bottom:15px;">Activity Log — Shift 2</h3>
                    <div style="max-height:350px;overflow-y:auto;">
                        <?php if (!empty($shift2_data['activity_log'])): ?>
                            <?php foreach ($shift2_data['activity_log'] as $log): ?>
                            <div style="padding:10px 12px;border-left:3px solid #dc3545;background:#f7f7f7;margin-bottom:8px;border-radius:5px;">
                                <div style="display:flex;justify-content:space-between;margin-bottom:3px;">
                                    <span style="font-weight:600;color:#003366;font-size:13px;"><?= htmlspecialchars($log['action_type']) ?></span>
                                    <span style="font-size:11px;color:#999;"><?= date('g:i A', strtotime($log['created_at'])) ?></span>
                                </div>
                                <div style="font-size:12px;color:#555;"><?= htmlspecialchars($log['action_details']) ?></div>
                                <div style="font-size:11px;color:#aaa;margin-top:2px;">by <?= htmlspecialchars($log['username']) ?></div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="text-align:center;color:#666;padding:20px;">No activity recorded for this shift.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Calendar Widget — Today's Tasks + Upcoming 3 Days -->
                <div class="calendar-widget">
                    <div class="calendar-header">
                        <h3>Tasks &amp; Upcoming Schedule</h3>
                        <span style="color:#666;font-size:14px;"><?= date('F j, Y', strtotime($selected_date)) ?></span>
                    </div>
                    <div class="task-list">
                        <?php if (empty($shift2_data['calendar_tasks'])): ?>
                            <p style="text-align:center;color:#666;padding:20px;">No tasks or deliveries in the next 3 days.</p>
                        <?php else: ?>
                            <?php foreach ($shift2_data['calendar_tasks'] as $task):
                                $isToday = date('Y-m-d', strtotime($task['task_date'])) === date('Y-m-d');
                                $typeBg  = $task['task_type'] === 'Fuel Delivery' ? '#fff3e0' : '#e8f0ff';
                                $typeFg  = $task['task_type'] === 'Fuel Delivery' ? '#fd7e14' : '#003366';
                            ?>
                            <div class="task-item <?= $isToday ? 'today' : '' ?>">
                                <div class="task-header">
                                    <span class="task-title"><?= htmlspecialchars($task['reference']) ?></span>
                                    <span class="task-date"><?= date('M j, g:i A', strtotime($task['task_date'])) ?></span>
                                </div>
                                <div style="font-size:12px;color:#555;margin-top:2px;"><?= htmlspecialchars($task['customer']) ?></div>
                                <div style="margin-top:5px;display:flex;gap:8px;flex-wrap:wrap;">
                                    <span style="padding:2px 8px;border-radius:10px;font-size:10px;font-weight:600;background:<?= $typeBg ?>;color:<?= $typeFg ?>;"><?= $task['task_type'] ?></span>
                                    <span class="task-status" style="background:#e8e8e8;color:#003366;"><?= htmlspecialchars($task['status']) ?></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div style="background:#f7f7f7;padding:20px;border-radius:10px;margin-top:20px;">
                    <h3 style="color:#003366;margin-bottom:15px;">Quick Actions</h3>
                    <div class="quick-actions">
                        <a href="staff_transactions_hub.php?section=merchandise" class="quick-action-btn"><div>POS / Merchandise</div></a>
                        <a href="staff_transactions_hub.php?section=merchandise" class="quick-action-btn"><div>Credit Sale</div></a>
                        <a href="joborder.php" class="quick-action-btn"><div>Job Orders</div></a>
                        <a href="staff_transactions_hub.php?section=fuel" class="quick-action-btn"><div>Fuel Transactions</div></a>
                        <a href="staff_record_delivery.php" class="quick-action-btn"><div>Receive Items</div></a>
                        <a href="my_shift.php" class="quick-action-btn"><div>My Shift</div></a>
                    </div>
                </div>
            </div>
            <?php endif; // End Shift 2 Content ?>
            
            <!-- DAILY CONSOLIDATION CONTENT -->
            <?php if ($can_view_consolidation && $shift1_data && $shift2_data): ?>
            <div class="tab-content <?= $can_view_consolidation ? 'active' : '' ?>" id="consolidation">
                <div class="consolidation-header">
                    <h2>Daily Consolidation Report</h2>
                    <p><?= date('F j, Y', strtotime($selected_date)) ?> - Combined Shift 1 + Shift 2</p>
                </div>
                
                <!-- Overall Summary Cards -->
                <div class="cards-grid">
                    <div class="stat-card">
                        <div class="details">
                            <div class="label">Total Revenue</div>
                            <div class="value">₱<?php 
                                $total_fuel = array_sum(array_column($shift1_data['fuel'], 'revenue')) + array_sum(array_column($shift2_data['fuel'], 'revenue'));
                                $total_merch = $shift1_data['merch']['total'] + $shift2_data['merch']['total'];
                                $total_service = $shift1_data['service']['total_service_income'] + $shift2_data['service']['total_service_income'];
                                echo number_format($total_fuel + $total_merch + $total_service, 2);
                            ?></div>
                            <small>All Sources</small>
                        </div>
                        <div class="icon"><i class="fas fa-chart-line"></i></div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="details">
                            <div class="label">Fuel Sales (Both Shifts)</div>
                            <div class="value">₱<?= number_format($total_fuel, 2) ?></div>
                            <small><?= number_format(array_sum(array_column($shift1_data['fuel'], 'liters')) + array_sum(array_column($shift2_data['fuel'], 'liters')), 2) ?> L</small>
                        </div>
                        <div class="icon"><i class="fas fa-gas-pump"></i></div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="details">
                            <div class="label">Merchandise Sales (Both Shifts)</div>
                            <div class="value">₱<?= number_format($total_merch, 2) ?></div>
                            <small><?= $shift1_data['merch']['count'] + $shift2_data['merch']['count'] ?> transactions</small>
                        </div>
                        <div class="icon"><i class="fas fa-shopping-cart"></i></div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="details">
                            <div class="label">Service Income (Both Shifts)</div>
                            <div class="value">₱<?= number_format($total_service, 2) ?></div>
                            <small><?= $shift1_data['service']['completed_jobs'] + $shift2_data['service']['completed_jobs'] ?> completed jobs</small>
                        </div>
                        <div class="icon"><i class="fas fa-wrench"></i></div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="details">
                            <div class="label">Total Job Orders</div>
                            <div class="value"><?= array_sum($shift1_data['jo_stats']) + array_sum($shift2_data['jo_stats']) ?></div>
                            <small><?= ($shift1_data['jo_stats']['Completed'] ?? 0) + ($shift2_data['jo_stats']['Completed'] ?? 0) ?> completed</small>
                        </div>
                        <div class="icon"><i class="fas fa-clipboard-list"></i></div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="details">
                            <div class="label">New Customers</div>
                            <div class="value"><?= $shift1_data['new_customers'] + $shift2_data['new_customers'] ?></div>
                            <small>Both Shifts Today</small>
                        </div>
                        <div class="icon"><i class="fas fa-users"></i></div>
                    </div>
                </div>
                
                <!-- Combined Charts -->
                <div class="charts-grid">
                    <div class="chart-card">
                        <h3>Fuel Sales - Daily Comparison</h3>
                        <canvas id="consolidationFuelChart"></canvas>
                    </div>
                    
                    <div class="chart-card">
                        <h3>Merchandise Category Distribution</h3>
                        <canvas id="consolidationMerchChart"></canvas>
                    </div>
                    
                    <div class="chart-card">
                        <h3>Job Orders - Daily Summary</h3>
                        <canvas id="consolidationJobOrderChart"></canvas>
                    </div>
                    
                    <div class="chart-card">
                        <h3>Payment Methods - Daily Total</h3>
                        <canvas id="consolidationPaymentChart"></canvas>
                    </div>
                </div>
                
                <!-- Consolidated Calendar -->
                <div class="calendar-widget">
                    <div class="calendar-header">
                        <h3>Complete Daily Schedule</h3>
                        <span style="color: #666666; font-size: 14px;">All Tasks & Deliveries</span>
                    </div>
                    <div class="task-list">
                        <?php
                        $all_tasks = array_merge(
                            $shift1_data['calendar_tasks'] ?? [],
                            $shift2_data['calendar_tasks'] ?? []
                        );
                        usort($all_tasks, fn($a,$b) => strtotime($a['task_date']) - strtotime($b['task_date']));
                        ?>
                        <?php if (empty($all_tasks)): ?>
                            <p style="text-align: center; color: #666666; padding: 20px;">
                                No scheduled tasks
                            </p>
                        <?php else: ?>
                            <?php foreach ($all_tasks as $task): ?>
                                <div class="task-item <?= date('Y-m-d', strtotime($task['task_date'])) === date('Y-m-d') ? 'today' : '' ?>">
                                    <div class="task-header">
                                        <span class="task-title">
                                            <?= htmlspecialchars($task['reference']) ?>
                                        </span>
                                        <span class="task-date"><?= date('M j, g:i A', strtotime($task['task_date'])) ?></span>
                                    </div>
                                    <div style="font-size: 14px; color: #666666; margin-top: 5px;">
                                        Customer: <?= htmlspecialchars($task['customer']) ?>
                                    </div>
                                    <span class="task-status" style="background: #d4edda; color: #155724;">
                                        <?= htmlspecialchars($task['status']) ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Audit Trail Export -->
                <div style="background: #f7f7f7; padding: 20px; border-radius: 10px; margin-top: 20px; text-align: center;">
                    <h3 style="color: #003366; margin-bottom: 15px;">
                        Export Options
                    </h3>
                    <div style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap;">
                        <a href="export_daily_report.php?date=<?= date('Y-m-d') ?>" class="btn btn-primary">
                            Export Daily Report
                        </a>
                        <a href="export_audit_trail.php?date=<?= date('Y-m-d') ?>" class="btn btn-primary">
                            Export Audit Trail
                        </a>
                        <a href="export_consolidation.php?date=<?= date('Y-m-d') ?>" class="btn btn-primary">
                            Export to Excel
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; // End Daily Consolidation Content ?>
        <?php if ($can_view_consolidation): ?>
        </div><!-- /tabs-container -->
        <?php endif; ?>
    </div><!-- /dashboard-container -->

    <script>
        // Tab switching functionality
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', () => {
                // Remove active class from all tabs and contents
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                
                // Add active class to clicked tab and corresponding content
                tab.classList.add('active');
                const tabId = tab.getAttribute('data-tab');
                document.getElementById(tabId).classList.add('active');
            });
        });
        
        <?php if ($shift1_data): ?>
        // Shift 1 - Fuel Sales Chart
        const shift1FuelCtx = document.getElementById('shift1FuelChart')?.getContext('2d');
        if (shift1FuelCtx) {
            new Chart(shift1FuelCtx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode(array_column($shift1_data['fuel'], 'fuel_type')) ?>,
                    datasets: [{
                        label: 'Liters Sold',
                        data: <?= json_encode(array_column($shift1_data['fuel'], 'liters')) ?>,
                        backgroundColor: 'rgba(0, 51, 102, 0.8)',
                        borderColor: 'rgba(0, 51, 102, 1)',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return value.toLocaleString() + ' L';
                                }
                            }
                        }
                    }
                }
            });
        }
        
        // Shift 1 - Payment Methods Chart
        const shift1PaymentCtx = document.getElementById('shift1PaymentChart')?.getContext('2d');
        if (shift1PaymentCtx) {
            new Chart(shift1PaymentCtx, {
                type: 'pie',
                data: {
                    labels: ['Cash', 'Card', 'E-Wallet', 'E-Fuel Card', 'Fleet Card'],
                    datasets: [{
                        data: [
                            <?= floatval($shift1_data['payments_summary']['cash']) ?>,
                            <?= floatval($shift1_data['payments_summary']['card']) ?>,
                            <?= floatval($shift1_data['payments_summary']['ewallet']) ?>,
                            <?= floatval($shift1_data['payments_summary']['efuel']) ?>,
                            <?= floatval($shift1_data['payments_summary']['fleet']) ?>
                        ],
                        backgroundColor: [
                            '#28a745',
                            '#003366',
                            '#6c757d',
                            '#dc3545',
                            '#999999'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true
                }
            });
        }
        
        // Shift 1 - Job Orders Hourly Trend Chart (LIVE DB DATA)
        const shift1JobOrderCtx = document.getElementById('shift1JobOrderChart')?.getContext('2d');
        if (shift1JobOrderCtx) {
            new Chart(shift1JobOrderCtx, {
                type: 'line',
                data: {
                    labels: <?= json_encode($shift1_data['jo_hourly_labels']) ?>,
                    datasets: [{
                        label: 'Job Orders Created',
                        data: <?= json_encode($shift1_data['jo_hourly_data']) ?>,
                        borderColor: '#003366',
                        backgroundColor: 'rgba(0,51,102,0.1)',
                        tension: 0.4,
                        fill: true,
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
                }
            });
        }
        
        // Shift 1 - Merchandise Sales by Category Chart (LIVE DB DATA)
        const shift1MerchCategoryCtx = document.getElementById('shift1MerchCategoryChart')?.getContext('2d');
        if (shift1MerchCategoryCtx) {
            const s1MerchLabels = <?= json_encode(array_column($shift1_data['merch_categories'], 'category')) ?>;
            const s1MerchData   = <?= json_encode(array_map('floatval', array_column($shift1_data['merch_categories'], 'total'))) ?>;
            const s1Colors = ['#003366','#28a745','#dc3545','#6c757d','#f0a500','#0099cc','#9b59b6','#e67e22','#1abc9c','#95a5a6'];
            new Chart(shift1MerchCategoryCtx, {
                type: 'pie',
                data: {
                    labels: s1MerchLabels.length ? s1MerchLabels : ['No Sales Yet'],
                    datasets: [{
                        data: s1MerchData.length ? s1MerchData : [1],
                        backgroundColor: s1Colors.slice(0, Math.max(s1MerchLabels.length, 1))
                    }]
                },
                options: { responsive: true, maintainAspectRatio: true }
            });
        }
        <?php endif; // End Shift 1 Charts ?>
        
        // Shift 1 - Fuel Tank Utilization Chart
        <?php if ($shift1_data && !empty($shift1_data['fuel_levels'])): ?>
        const s1TankCtx = document.getElementById('shift1TankChart')?.getContext('2d');
        if (s1TankCtx) {
            const s1TankLabels = <?= json_encode(array_column($shift1_data['fuel_levels'], 'fuel_type_name')) ?>;
            const s1TankCurrent = <?= json_encode(array_map('floatval', array_column($shift1_data['fuel_levels'], 'current_stock'))) ?>;
            const s1TankCapacity = <?= json_encode(array_map('floatval', array_column($shift1_data['fuel_levels'], 'capacity'))) ?>;
            const s1TankColors = s1TankCurrent.map((v,i) => {
                const pct = s1TankCapacity[i] > 0 ? (v/s1TankCapacity[i])*100 : 0;
                return pct < 25 ? '#dc3545' : pct < 50 ? '#fd7e14' : '#28a745';
            });
            new Chart(s1TankCtx, {
                type: 'bar',
                data: {
                    labels: s1TankLabels,
                    datasets: [
                        { label: 'Current (L)', data: s1TankCurrent, backgroundColor: s1TankColors, borderRadius: 4 },
                        { label: 'Capacity (L)', data: s1TankCapacity, backgroundColor: 'rgba(0,38,77,0.08)', borderRadius: 4 }
                    ]
                },
                options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } } }, scales: { y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString() + ' L' } } } }
            });
        }
        <?php endif; ?>

        // Shift 1 - Merchandise Low Stock Chart
        <?php if ($shift1_data && !empty($shift1_data['merch_low_stock'])): ?>
        const s1MerchStockCtx = document.getElementById('shift1MerchStockChart')?.getContext('2d');
        if (s1MerchStockCtx) {
            const s1MsLabels = <?= json_encode(array_column($shift1_data['merch_low_stock'], 'product_name')) ?>;
            const s1MsCurrent = <?= json_encode(array_map('floatval', array_column($shift1_data['merch_low_stock'], 'current_stock'))) ?>;
            const s1MsReorder = <?= json_encode(array_map('floatval', array_column($shift1_data['merch_low_stock'], 'reorder_level'))) ?>;
            new Chart(s1MerchStockCtx, {
                type: 'bar',
                indexAxis: 'y',
                data: {
                    labels: s1MsLabels,
                    datasets: [
                        { label: 'Current Stock', data: s1MsCurrent, backgroundColor: '#dc354588', borderRadius: 4 },
                        { label: 'Reorder Level', data: s1MsReorder, backgroundColor: '#fd7e1488', borderRadius: 4 }
                    ]
                },
                options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } } }, scales: { x: { beginAtZero: true } } }
            });
        }
        <?php endif; ?>
        
        <?php if ($shift2_data): ?>
        // Shift 2 - Fuel Sales Chart
        const shift2FuelCtx = document.getElementById('shift2FuelChart')?.getContext('2d');
        if (shift2FuelCtx) {
            new Chart(shift2FuelCtx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode(array_column($shift2_data['fuel'], 'fuel_type')) ?>,
                    datasets: [{
                        label: 'Liters Sold',
                        data: <?= json_encode(array_column($shift2_data['fuel'], 'liters')) ?>,
                        backgroundColor: 'rgba(220, 53, 69, 0.8)',
                        borderColor: 'rgba(220, 53, 69, 1)',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return value.toLocaleString() + ' L';
                                }
                            }
                        }
                    }
                }
            });
        }
        
        // Shift 2 - Payment Methods Chart
        const shift2PaymentCtx = document.getElementById('shift2PaymentChart')?.getContext('2d');
        if (shift2PaymentCtx) {
            new Chart(shift2PaymentCtx, {
                type: 'pie',
                data: {
                    labels: ['Cash', 'Card', 'E-Wallet', 'E-Fuel Card', 'Fleet Card'],
                    datasets: [{
                        data: [
                            <?= floatval($shift2_data['payments_summary']['cash']) ?>,
                            <?= floatval($shift2_data['payments_summary']['card']) ?>,
                            <?= floatval($shift2_data['payments_summary']['ewallet']) ?>,
                            <?= floatval($shift2_data['payments_summary']['efuel']) ?>,
                            <?= floatval($shift2_data['payments_summary']['fleet']) ?>
                        ],
                        backgroundColor: [
                            '#28a745',
                            '#003366',
                            '#6c757d',
                            '#dc3545',
                            '#999999'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true
                }
            });
        }
        
        // Shift 2 - Job Orders Hourly Trend Chart (LIVE DB DATA)
        const shift2JobOrderCtx = document.getElementById('shift2JobOrderChart')?.getContext('2d');
        if (shift2JobOrderCtx) {
            new Chart(shift2JobOrderCtx, {
                type: 'line',
                data: {
                    labels: <?= json_encode($shift2_data['jo_hourly_labels']) ?>,
                    datasets: [{
                        label: 'Job Orders Created',
                        data: <?= json_encode($shift2_data['jo_hourly_data']) ?>,
                        borderColor: '#dc3545',
                        backgroundColor: 'rgba(220,53,69,0.1)',
                        tension: 0.4,
                        fill: true,
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
                }
            });
        }

        // Shift 2 - Merchandise Sales by Category Chart (LIVE DB DATA)
        const shift2MerchCategoryCtx = document.getElementById('shift2MerchCategoryChart')?.getContext('2d');
        if (shift2MerchCategoryCtx) {
            const s2MerchLabels = <?= json_encode(array_column($shift2_data['merch_categories'], 'category')) ?>;
            const s2MerchData   = <?= json_encode(array_map('floatval', array_column($shift2_data['merch_categories'], 'total'))) ?>;
            const s2Colors = ['#dc3545','#003366','#28a745','#6c757d','#f0a500','#0099cc','#9b59b6','#e67e22','#1abc9c','#95a5a6'];
            new Chart(shift2MerchCategoryCtx, {
                type: 'pie',
                data: {
                    labels: s2MerchLabels.length ? s2MerchLabels : ['No Sales Yet'],
                    datasets: [{
                        data: s2MerchData.length ? s2MerchData : [1],
                        backgroundColor: s2Colors.slice(0, Math.max(s2MerchLabels.length, 1))
                    }]
                },
                options: { responsive: true, maintainAspectRatio: true }
            });
        }
        
        // Shift 2 - Variance Alerts Chart (LIVE DB DATA)
        const shift2VarianceCtx = document.getElementById('shift2VarianceChart')?.getContext('2d');
        if (shift2VarianceCtx) {
            const s2VarLabels = <?= json_encode(array_column($shift2_data['variance_data'], 'fuel_type')) ?>;
            const s2VarData   = <?= json_encode(array_map('floatval', array_column($shift2_data['variance_data'], 'variance'))) ?>;
            new Chart(shift2VarianceCtx, {
                type: 'bar',
                data: {
                    labels: s2VarLabels.length ? s2VarLabels : ['No Variances'],
                    datasets: [{
                        label: 'Variance (Liters)',
                        data: s2VarData.length ? s2VarData : [0],
                        backgroundColor: s2VarLabels.map((_, i) => i === 0 ? '#dc3545' : '#28a745')
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    scales: { y: { beginAtZero: true } }
                }
            });
        }
        <?php endif; // End Shift 2 Charts ?>
        
        // Shift 2 - Fuel Tank Utilization Chart
        <?php if ($shift2_data && !empty($shift2_data['fuel_levels'])): ?>
        const s2TankCtx = document.getElementById('shift2TankChart')?.getContext('2d');
        if (s2TankCtx) {
            const s2TankLabels = <?= json_encode(array_column($shift2_data['fuel_levels'], 'fuel_type_name')) ?>;
            const s2TankCurrent = <?= json_encode(array_map('floatval', array_column($shift2_data['fuel_levels'], 'current_stock'))) ?>;
            const s2TankCapacity = <?= json_encode(array_map('floatval', array_column($shift2_data['fuel_levels'], 'capacity'))) ?>;
            const s2TankColors = s2TankCurrent.map((v,i) => {
                const pct = s2TankCapacity[i] > 0 ? (v/s2TankCapacity[i])*100 : 0;
                return pct < 25 ? '#dc3545' : pct < 50 ? '#fd7e14' : '#28a745';
            });
            new Chart(s2TankCtx, {
                type: 'bar',
                data: {
                    labels: s2TankLabels,
                    datasets: [
                        { label: 'Current (L)', data: s2TankCurrent, backgroundColor: s2TankColors, borderRadius: 4 },
                        { label: 'Capacity (L)', data: s2TankCapacity, backgroundColor: 'rgba(0,38,77,0.08)', borderRadius: 4 }
                    ]
                },
                options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } } }, scales: { y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString() + ' L' } } } }
            });
        }
        <?php endif; ?>

        // Shift 2 - Merchandise Low Stock Chart
        <?php if ($shift2_data && !empty($shift2_data['merch_low_stock'])): ?>
        const s2MerchStockCtx = document.getElementById('shift2MerchStockChart')?.getContext('2d');
        if (s2MerchStockCtx) {
            const s2MsLabels = <?= json_encode(array_column($shift2_data['merch_low_stock'], 'product_name')) ?>;
            const s2MsCurrent = <?= json_encode(array_map('floatval', array_column($shift2_data['merch_low_stock'], 'current_stock'))) ?>;
            const s2MsReorder = <?= json_encode(array_map(function($i){ return isset($i['reorder_level']) ? (float)$i['reorder_level'] : (float)($i['threshold']??0); }, $shift2_data['merch_low_stock'])) ?>;
            new Chart(s2MerchStockCtx, {
                type: 'bar',
                indexAxis: 'y',
                data: {
                    labels: s2MsLabels,
                    datasets: [
                        { label: 'Current Stock', data: s2MsCurrent, backgroundColor: '#dc354588', borderRadius: 4 },
                        { label: 'Reorder Level', data: s2MsReorder, backgroundColor: '#fd7e1488', borderRadius: 4 }
                    ]
                },
                options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } } }, scales: { x: { beginAtZero: true } } }
            });
        }
        <?php endif; ?>
        
        <?php if ($can_view_consolidation && $shift1_data && $shift2_data): ?>
        // Consolidation - Fuel Sales Comparison Chart
        const consolidationFuelCtx = document.getElementById('consolidationFuelChart')?.getContext('2d');
        if (consolidationFuelCtx) {
        new Chart(consolidationFuelCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_unique(array_merge(array_column($shift1_data['fuel'], 'fuel_type'), array_column($shift2_data['fuel'], 'fuel_type')))) ?>,
                datasets: [
                    {
                        label: 'Shift 1 (6AM-2PM)',
                        data: <?= json_encode(array_column($shift1_data['fuel'], 'liters')) ?>,
                        backgroundColor: 'rgba(0, 51, 102, 0.8)'
                    },
                    {
                        label: 'Shift 2 (2PM-10PM)',
                        data: <?= json_encode(array_column($shift2_data['fuel'], 'liters')) ?>,
                        backgroundColor: 'rgba(220, 53, 69, 0.8)'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return value.toLocaleString() + ' L';
                            }
                        }
                    }
                }
            }
        });
        
        // Consolidation - Merchandise Category Chart (LIVE DB DATA - both shifts combined)
        const consolidationMerchCtx = document.getElementById('consolidationMerchChart')?.getContext('2d');
        if (consolidationMerchCtx) {
            <?php
            // Merge merch_categories from both shifts
            $combined_cats = [];
            foreach (array_merge($shift1_data['merch_categories'], $shift2_data['merch_categories']) as $row) {
                $cat = $row['category'];
                $combined_cats[$cat] = ($combined_cats[$cat] ?? 0) + (float)$row['total'];
            }
            arsort($combined_cats);
            ?>
            const consolidMerchLabels = <?= json_encode(array_keys($combined_cats)) ?>;
            const consolidMerchData   = <?= json_encode(array_values($combined_cats)) ?>;
            const consolidColors = ['#003366','#28a745','#dc3545','#6c757d','#f0a500','#0099cc','#9b59b6','#e67e22','#1abc9c','#95a5a6'];
            new Chart(consolidationMerchCtx, {
                type: 'pie',
                data: {
                    labels: consolidMerchLabels.length ? consolidMerchLabels : ['No Sales Yet'],
                    datasets: [{
                        data: consolidMerchData.length ? consolidMerchData : [1],
                        backgroundColor: consolidColors.slice(0, Math.max(consolidMerchLabels.length, 1))
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: { legend: { position: 'bottom' } }
                }
            });
        }
        
        // Consolidation - Job Orders Summary Chart
        const consolidationJobOrderCtx = document.getElementById('consolidationJobOrderChart')?.getContext('2d');
        if (consolidationJobOrderCtx) {
            new Chart(consolidationJobOrderCtx, {
                type: 'line',
                data: {
                    labels: ['6 AM', '8 AM', '10 AM', '12 PM', '2 PM', '4 PM', '6 PM', '8 PM', '10 PM'],
                    datasets: [{
                        label: 'Cumulative Job Orders',
                        data: [0, 2, 6, 9, 14, 20, 24, 26, 26],
                        borderColor: '#003366',
                        backgroundColor: 'rgba(0, 51, 102, 0.1)',
                        tension: 0.4,
                        fill: true,
                        borderWidth: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        }
        
        // Consolidation - Payment Methods Total Chart
        const consolidationPaymentCtx = document.getElementById('consolidationPaymentChart')?.getContext('2d');
        if (consolidationPaymentCtx) {
            new Chart(consolidationPaymentCtx, {
            type: 'bar',
            data: {
                labels: ['Cash', 'Card', 'E-Wallet', 'E-Fuel Card', 'Fleet Card'],
                datasets: [
                    {
                        label: 'Shift 1',
                        data: [
                            <?= floatval($shift1_data['payments_summary']['cash']) ?>,
                            <?= floatval($shift1_data['payments_summary']['card']) ?>,
                            <?= floatval($shift1_data['payments_summary']['ewallet']) ?>,
                            <?= floatval($shift1_data['payments_summary']['efuel']) ?>,
                            <?= floatval($shift1_data['payments_summary']['fleet']) ?>
                        ],
                        backgroundColor: 'rgba(0, 51, 102, 0.8)'
                    },
                    {
                        label: 'Shift 2',
                        data: [
                            <?= floatval($shift2_data['payments_summary']['cash']) ?>,
                            <?= floatval($shift2_data['payments_summary']['card']) ?>,
                            <?= floatval($shift2_data['payments_summary']['ewallet']) ?>,
                            <?= floatval($shift2_data['payments_summary']['efuel']) ?>,
                            <?= floatval($shift2_data['payments_summary']['fleet']) ?>
                        ],
                        backgroundColor: 'rgba(220, 53, 69, 0.8)'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₱' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
        }
        <?php endif; // End Consolidation Charts ?>
        
        // Auto-refresh every 30 seconds
        setInterval(() => {
            location.reload();
        }, 30000);
    </script>
</div><!-- /main-content -->

<?php include __DIR__ . '/../partials/footer.php'; ?>
