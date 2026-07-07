<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'dashboard';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$station_id = user_station_id();
$role       = role_key($me['role'] ?? '');

if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
    header('Location: dashboard.php'); exit;
}
if (!$station_id) {
    die('Error: You are not assigned to a station.');
}

$display_name  = htmlspecialchars($me['full_name'] ?? trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: ($me['name'] ?? 'Staff'));
$station_label = htmlspecialchars($me['station_name'] ?? 'Station #' . $station_id);

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

$flash_success = $_SESSION['success'] ?? null; unset($_SESSION['success']);
$flash_error   = $_SESSION['error']   ?? null; unset($_SESSION['error']);

// POST HANDLER: clock_in / clock_out
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'clock_in') {
        $check = $pdo->prepare("SELECT id FROM labor_sessions WHERE user_id = ? AND end_time IS NULL");
        $check->execute([$me['id']]);
        if ($check->fetch()) {
            $_SESSION['error'] = 'You are already clocked in.';
        } else {
            $shift = $current_shift ?: ['shift_key' => 'first', 'shift_name' => 'First Shift'];
            $pdo->prepare(
                "INSERT INTO labor_sessions (user_id, station_id, start_time, shift_period, shift_name)
                 VALUES (?, ?, NOW(), ?, ?)"
            )->execute([$me['id'], $station_id, $shift['shift_key'], $shift['shift_name']]);
            log_activity($pdo, $me['id'], 'Clock In', "Station {$station_id} - {$shift['shift_name']}");
            $_SESSION['success'] = "Clocked in successfully. Shift: {$shift['shift_name']}";
        }
    }
    if ($_POST['action'] === 'clock_out') {
        $stmt = $pdo->prepare(
            "UPDATE labor_sessions
             SET end_time = NOW(),
                 hours_worked = ROUND(TIMESTAMPDIFF(MINUTE, start_time, NOW()) / 60, 2)
             WHERE user_id = ? AND end_time IS NULL"
        );
        $stmt->execute([$me['id']]);
        if ($stmt->rowCount() > 0) {
            log_activity($pdo, $me['id'], 'Clock Out', 'Clocked out');
            $_SESSION['success'] = 'Clocked out successfully.';
        } else {
            $_SESSION['error'] = 'You are not clocked in.';
        }
    }
    header('Location: staff_dashboard.php'); exit;
}

// Helper function to get shift data
function getShiftData($pdo, $station_id, $shift_number) {
    $date = date('Y-m-d');
    
    // Define shift time ranges
    $shift_times = [
        1 => ['start' => '06:00:00', 'end' => '14:00:00'],
        2 => ['start' => '14:00:00', 'end' => '22:00:00']
    ];
    
    $times = $shift_times[$shift_number];
    $start = "$date {$times['start']}";
    $end = "$date {$times['end']}";
    
    // Fuel Sales
    $fuel_query = $pdo->prepare("
        SELECT fuel_type,
               COALESCE(SUM(liters_sold), 0) AS liters,
               COALESCE(SUM(total_amount), 0) AS revenue,
               COALESCE(AVG(price_per_liter), 0) AS avg_price
        FROM fuel_transactions
        WHERE station_id = ? 
        AND transaction_date BETWEEN ? AND ?
        AND liters_sold > 0
        GROUP BY fuel_type
        ORDER BY revenue DESC
    ");
    $fuel_query->execute([$station_id, $start, $end]);
    $fuel_data = $fuel_query->fetchAll(PDO::FETCH_ASSOC);
    
    // Merchandise Sales
    $merch_query = $pdo->prepare("
        SELECT COALESCE(SUM(total_amount), 0) AS total,
               COUNT(*) AS count,
               COALESCE(SUM(CASE WHEN payment_method IN ('Cash','cash') THEN total_amount ELSE 0 END), 0) AS cash,
               COALESCE(SUM(CASE WHEN payment_method IN ('Credit Card','Card','card') THEN total_amount ELSE 0 END), 0) AS card,
               COALESCE(SUM(CASE WHEN payment_method IN ('E-Wallet','GCash','Maya') THEN total_amount ELSE 0 END), 0) AS ewallet,
               COALESCE(SUM(CASE WHEN payment_method IN ('E-Fuel Card','Fuel Card') THEN total_amount ELSE 0 END), 0) AS efuel,
               COALESCE(SUM(CASE WHEN payment_method IN ('Fleet Card','Fleet') THEN total_amount ELSE 0 END), 0) AS fleet
        FROM merchandise_transactions
        WHERE station_id = ?
        AND created_at BETWEEN ? AND ?
    ");
    $merch_query->execute([$station_id, $start, $end]);
    $merch_data = $merch_query->fetch(PDO::FETCH_ASSOC);
    
    // Service Income from completed job orders
    $service_query = $pdo->prepare("
        SELECT COALESCE(SUM(total_cost), 0) AS total_service_income,
               COUNT(*) AS completed_jobs
        FROM job_orders
        WHERE station_id = ?
        AND created_at BETWEEN ? AND ?
        AND status = 'Completed'
    ");
    $service_query->execute([$station_id, $start, $end]);
    $service_data = $service_query->fetch(PDO::FETCH_ASSOC);
    
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
    
    // Activity Log for this shift
    $activity_query = $pdo->prepare("
        SELECT al.action_type, al.action_details, al.created_at, u.username
        FROM audit_logs al 
        LEFT JOIN users u ON al.user_id = u.id
        WHERE u.station_id = ?
        AND al.created_at BETWEEN ? AND ?
        ORDER BY al.created_at DESC 
        LIMIT 15
    ");
    $activity_query->execute([$station_id, $start, $end]);
    $activity_log = $activity_query->fetchAll(PDO::FETCH_ASSOC);
    
    // Shift Tracker - Staff clocked in during this shift
    $shift_tracker_query = $pdo->prepare("
        SELECT COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.name, u.username, 'Unknown') AS full_name, 
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
    
    return [
        'fuel' => $fuel_data,
        'merch' => $merch_data,
        'service' => $service_data,
        'fuel_levels' => $fuel_levels,
        'merch_low_stock' => $merch_low_stock,
        'activity_log' => $activity_log,
        'shift_tracker' => $shift_tracker,
        'shift_number' => $shift_number,
        'shift_label' => $shift_number == 1 ? '6AM - 2PM' : '2PM - 10PM'
    ];
}

// Get data for both shifts
$shift1_data = getShiftData($pdo, $station_id, 1);
$shift2_data = getShiftData($pdo, $station_id, 2);

// Job Orders by status
$jo_stats = [];
try {
    $jo_query = $pdo->prepare("
        SELECT status, COUNT(*) as count
        FROM job_orders
        WHERE station_id = ?
        AND DATE(created_at) = CURDATE()
        GROUP BY status
    ");
    $jo_query->execute([$station_id]);
    foreach ($jo_query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $jo_stats[$row['status']] = $row['count'];
    }
} catch (Exception $e) {}

// Customers
$new_customers = 0;
try {
    $cust_query = $pdo->prepare("
        SELECT COUNT(*) as count
        FROM customers
        WHERE station_id = ?
        AND DATE(created_at) = CURDATE()
    ");
    $cust_query->execute([$station_id]);
    $new_customers = $cust_query->fetchColumn();
} catch (Exception $e) {}

// Calendar tasks
$calendar_tasks = [];
try {
    $task_query = $pdo->prepare("
        SELECT 'Job Order' as task_type, 
               COALESCE(job_order_number, CONCAT('JO-', id)) as reference,
               status,
               created_at as task_date,
               COALESCE(customer_name, 'Walk-in') as customer
        FROM job_orders
        WHERE station_id = ?
        AND DATE(created_at) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $task_query->execute([$station_id]);
    $calendar_tasks = $task_query->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard - Shift Management | Petron</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
    <script src="../assets/vendor/chart.js/chart.umd.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
            padding: 20px;
        }
        
        .dashboard-container {
            max-width: 1600px;
            margin: 0 auto;
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
            background: #003366;
            padding: 20px;
            border-radius: 10px;
            color: white;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-card.fuel {
            background: #dc3545;
        }
        
        .stat-card.merch {
            background: #28a745;
        }
        
        .stat-card.payments {
            background: #003366;
        }
        
        .stat-card.jobs {
            background: #6c757d;
        }
        
        .stat-card .icon {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 10px;
            opacity: 0.9;
            letter-spacing: 1px;
        }
        
        .stat-card .label {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 5px;
        }
        
        .stat-card .value {
            font-size: 28px;
            font-weight: 700;
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
            border-left: 4px solid #003366;
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
            border-left-color: #28a745;
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
</head>
<body>
    <div class="dashboard-container">
        <!-- Header -->
        <div class="header-section">
            <div class="header-left">
                <h1>Staff Dashboard</h1>
                <p><?= $display_name ?> - <?= $station_label ?></p>
            </div>
            <div class="header-right">
                <?php if ($clocked_in): ?>
                    <div class="clock-status clocked-in">
                        Clocked In - <?= $clock_in_shift ?>
                        <br><small>Since <?= date('h:i A', strtotime($clock_in_time)) ?></small>
                    </div>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="clock_out">
                        <button type="submit" class="btn btn-danger">
                            Clock Out
                        </button>
                    </form>
                <?php else: ?>
                    <div class="clock-status clocked-out">
                        Not Clocked In
                    </div>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="clock_in">
                        <button type="submit" class="btn btn-primary">
                            Clock In
                        </button>
                    </form>
                <?php endif; ?>
            </div>
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
        
        <!-- Tabs -->
        <div class="tabs-container">
            <div class="tabs">
                <button class="tab active" data-tab="shift1">
                    Shift 1 (6AM - 2PM)
                </button>
                <button class="tab" data-tab="shift2">
                    Shift 2 (2PM - 10PM)
                </button>
                <button class="tab" data-tab="consolidation">
                    Daily Consolidation
                </button>
            </div>
            
            <!-- SHIFT 1 CONTENT -->
            <div class="tab-content active" id="shift1">
                <h2 style="color: #003366; margin-bottom: 20px;">
                    Shift 1 Dashboard (6:00 AM - 2:00 PM)
                </h2>
                
                <!-- Shift Tracker -->
                <div class="widget-card" style="margin-bottom: 20px;">
                    <h3 style="color: #003366;">Shift Tracker - Staff Clock In/Out</h3>
                    <div style="overflow:hidden;">
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
                    <div class="stat-card fuel">
                        <div class="icon">FUEL</div>
                        <div class="label">Fuel Sales</div>
                        <div class="value">₱<?= number_format(array_sum(array_column($shift1_data['fuel'], 'revenue')), 2) ?></div>
                        <small><?= number_format(array_sum(array_column($shift1_data['fuel'], 'liters')), 2) ?> L</small>
                    </div>
                    
                    <div class="stat-card merch">
                        <div class="icon">MERCH</div>
                        <div class="label">Merchandise Sales</div>
                        <div class="value">₱<?= number_format($shift1_data['merch']['total'], 2) ?></div>
                        <small><?= $shift1_data['merch']['count'] ?> transactions</small>
                    </div>
                    
                    <div class="stat-card" style="background: #28a745;">
                        <div class="icon">SERVICE</div>
                        <div class="label">Service Income</div>
                        <div class="value">₱<?= number_format($shift1_data['service']['total_service_income'], 2) ?></div>
                        <small><?= $shift1_data['service']['completed_jobs'] ?> completed jobs</small>
                    </div>
                    
                    <div class="stat-card payments">
                        <div class="icon">PAY</div>
                        <div class="label">Total Payments</div>
                        <div class="value">₱<?= number_format(array_sum(array_column($shift1_data['fuel'], 'revenue')) + $shift1_data['merch']['total'] + $shift1_data['service']['total_service_income'], 2) ?></div>
                    </div>
                    
                    <div class="stat-card jobs">
                        <div class="icon">JOB</div>
                        <div class="label">Job Orders</div>
                        <div class="value"><?= array_sum($jo_stats) ?></div>
                        <small><?= $jo_stats['Completed'] ?? 0 ?> completed</small>
                    </div>
                    
                    <div class="stat-card" style="background: #6c757d;">
                        <div class="icon">CUST</div>
                        <div class="label">New Customers</div>
                        <div class="value"><?= $new_customers ?></div>
                        <small>Added Today</small>
                    </div>
                </div>
                
                <!-- Fuel Monitoring -->
                <div class="widget-card" style="margin: 20px 0;">
                    <h3 style="color: #003366;">Fuel Tank Levels & Monitoring</h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 15px;">
                        <?php foreach ($shift1_data['fuel_levels'] as $fl): ?>
                            <div style="background: #f7f7f7; padding: 15px; border-radius: 8px; border-left: 4px solid <?= $fl['stock_status'] == 'Critical' || $fl['stock_status'] == 'Out of Stock' ? '#dc3545' : ($fl['stock_status'] == 'Low Stock' ? '#6c757d' : '#28a745') ?>;">
                                <div style="font-weight: 600; color: #003366; margin-bottom: 5px;"><?= htmlspecialchars($fl['fuel_type_name']) ?></div>
                                <div style="font-size: 24px; font-weight: 700; color: #003366;"><?= number_format($fl['current_stock'], 2) ?> L</div>
                                <div style="font-size: 12px; color: #666666;">Capacity: <?= number_format($fl['capacity'], 2) ?> L</div>
                                <div style="margin-top: 5px;">
                                    <span style="padding: 3px 8px; border-radius: 12px; font-size: 10px; font-weight: 600; background: <?= $fl['stock_status'] == 'Critical' || $fl['stock_status'] == 'Out of Stock' ? '#f8d7da' : ($fl['stock_status'] == 'Low Stock' ? '#e8e8e8' : '#d4edda') ?>; color: <?= $fl['stock_status'] == 'Critical' || $fl['stock_status'] == 'Out of Stock' ? '#721c24' : ($fl['stock_status'] == 'Low Stock' ? '#666666' : '#155724') ?>;">
                                        <?= $fl['stock_status'] ?>
                                    </span>
                                </div>
                                <?php if ($fl['capacity'] > 0): ?>
                                    <div style="background: #e8e8e8; height: 6px; border-radius: 3px; margin-top: 8px; overflow: hidden;">
                                        <div style="background: <?= $fl['stock_status'] == 'Critical' || $fl['stock_status'] == 'Out of Stock' ? '#dc3545' : ($fl['stock_status'] == 'Low Stock' ? '#6c757d' : '#28a745') ?>; height: 100%; width: <?= min(100, ($fl['current_stock'] / $fl['capacity']) * 100) ?>%;"></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Merchandise Low Stock -->
                <div class="widget-card" style="margin: 20px 0;">
                    <h3 style="color: #003366;">Merchandise Low Stock Alerts</h3>
                    <?php if (!empty($shift1_data['merch_low_stock'])): ?>
                        <div style="overflow:hidden; margin-top: 15px;">
                            <table style="width: 100%; border-collapse: collapse;">
                                <thead>
                                    <tr style="background: #f7f7f7;">
                                        <th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">Product</th>
                                        <th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">Category</th>
                                        <th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">Current Stock</th>
                                        <th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">Reorder Level</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($shift1_data['merch_low_stock'] as $item): ?>
                                        <tr>
                                            <td style="padding: 10px; border-bottom: 1px solid #eee;"><?= htmlspecialchars($item['product_name']) ?></td>
                                            <td style="padding: 10px; border-bottom: 1px solid #eee;"><?= htmlspecialchars($item['category']) ?></td>
                                            <td style="padding: 10px; border-bottom: 1px solid #eee;">
                                                <span style="color: <?= $item['current_stock'] <= 0 ? '#dc3545' : '#666666' ?>; font-weight: 600;">
                                                    <?= $item['current_stock'] ?>
                                                </span>
                                            </td>
                                            <td style="padding: 10px; border-bottom: 1px solid #eee;"><?= $item['threshold'] ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p style="text-align: center; color: #666666; padding: 20px;">All merchandise items are adequately stocked</p>
                    <?php endif; ?>
                </div>
                
                <!-- Charts -->
                <div class="charts-grid">
                    <div class="chart-card">
                        <h3>Fuel Sales by Type (Liters)</h3>
                        <canvas id="shift1FuelChart"></canvas>
                    </div>
                    
                    <div class="chart-card">
                        <h3>Payment Methods Breakdown</h3>
                        <canvas id="shift1PaymentChart"></canvas>
                    </div>
                    
                    <div class="chart-card">
                        <h3>Job Orders Status</h3>
                        <canvas id="shift1JobOrderChart"></canvas>
                    </div>
                    
                    <div class="chart-card">
                        <h3>Merchandise Sales by Category</h3>
                        <canvas id="shift1MerchCategoryChart"></canvas>
                    </div>
                </div>
                
                <!-- Activity Log -->
                <div class="widget-card" style="margin: 20px 0;">
                    <h3 style="color: #003366;">Activity Log - Shift 1</h3>
                    <div style="max-height: 400px; overflow-y: auto; margin-top: 15px;">
                        <?php if (!empty($shift1_data['activity_log'])): ?>
                            <?php foreach ($shift1_data['activity_log'] as $log): ?>
                                <div style="padding: 12px; border-left: 3px solid #003366; background: #f7f7f7; margin-bottom: 10px; border-radius: 5px;">
                                    <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                        <span style="font-weight: 600; color: #003366;"><?= htmlspecialchars($log['action_type']) ?></span>
                                        <span style="font-size: 12px; color: #666666;"><?= date('g:i A', strtotime($log['created_at'])) ?></span>
                                    </div>
                                    <div style="font-size: 13px; color: #666666;"><?= htmlspecialchars($log['action_details']) ?></div>
                                    <div style="font-size: 11px; color: #999999; margin-top: 3px;">by <?= htmlspecialchars($log['username']) ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="text-align: center; color: #666666; padding: 20px;">No activity recorded for this shift</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Calendar Widget -->
                <div class="calendar-widget">
                    <div class="calendar-header">
                        <h3>Today's Tasks</h3>
                        <span style="color: #666666; font-size: 14px;"><?= date('F j, Y') ?></span>
                    </div>
                    <div class="task-list">
                        <?php if (empty($calendar_tasks)): ?>
                            <p style="text-align: center; color: #666666; padding: 20px;">
                                No tasks scheduled for today
                            </p>
                        <?php else: ?>
                            <?php foreach ($calendar_tasks as $task): ?>
                                <div class="task-item <?= date('Y-m-d', strtotime($task['task_date'])) === date('Y-m-d') ? 'today' : '' ?>">
                                    <div class="task-header">
                                        <span class="task-title">
                                            <?= htmlspecialchars($task['reference']) ?>
                                        </span>
                                        <span class="task-date"><?= date('M j, g:i A', strtotime($task['task_date'])) ?></span>
                                    </div>
                                    <div>Customer: <?= htmlspecialchars($task['customer']) ?></div>
                                    <span class="task-status" style="background: #e8e8e8; color: #003366;">
                                        <?= htmlspecialchars($task['status']) ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div style="background: #f7f7f7; padding: 20px; border-radius: 10px; margin-top: 20px;">
                    <h3 style="color: #003366; margin-bottom: 15px;">
                        Quick Actions
                    </h3>
                    <div class="quick-actions">
                        <a href="pos.php" class="quick-action-btn">
                            <div>POS</div>
                        </a>
                        <a href="credit_sale.php" class="quick-action-btn">
                            <div>Credit Sale</div>
                        </a>
                        <a href="job_orders.php" class="quick-action-btn">
                            <div>Job Orders</div>
                        </a>
                        <a href="fuel_transactions.php" class="quick-action-btn">
                            <div>Fuel Txn</div>
                        </a>
                        <a href="receive_items.php" class="quick-action-btn">
                            <div>Receive Items</div>
                        </a>
                        <a href="my_shift.php" class="quick-action-btn">
                            <div>My Shift</div>
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- SHIFT 2 CONTENT -->
            <div class="tab-content" id="shift2">
                <h2 style="color: #003366; margin-bottom: 20px;">
                    Shift 2 Dashboard (2:00 PM - 10:00 PM)
                </h2>
                
                <!-- Shift Tracker -->
                <div class="widget-card" style="margin-bottom: 20px;">
                    <h3 style="color: #003366;">Shift Tracker - Staff Clock In/Out</h3>
                    <div style="overflow:hidden;">
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
                    <div class="stat-card fuel">
                        <div class="icon">FUEL</div>
                        <div class="label">Fuel Sales</div>
                        <div class="value">₱<?= number_format(array_sum(array_column($shift2_data['fuel'], 'revenue')), 2) ?></div>
                        <small><?= number_format(array_sum(array_column($shift2_data['fuel'], 'liters')), 2) ?> L</small>
                    </div>
                    
                    <div class="stat-card merch">
                        <div class="icon">MERCH</div>
                        <div class="label">Merchandise Sales</div>
                        <div class="value">₱<?= number_format($shift2_data['merch']['total'], 2) ?></div>
                        <small><?= $shift2_data['merch']['count'] ?> transactions</small>
                    </div>
                    
                    <div class="stat-card" style="background: #28a745;">
                        <div class="icon">SERVICE</div>
                        <div class="label">Service Income</div>
                        <div class="value">₱<?= number_format($shift2_data['service']['total_service_income'], 2) ?></div>
                        <small><?= $shift2_data['service']['completed_jobs'] ?> completed jobs</small>
                    </div>
                    
                    <div class="stat-card payments">
                        <div class="icon">PAY</div>
                        <div class="label">Total Payments</div>
                        <div class="value">₱<?= number_format(array_sum(array_column($shift2_data['fuel'], 'revenue')) + $shift2_data['merch']['total'] + $shift2_data['service']['total_service_income'], 2) ?></div>
                    </div>
                    
                    <div class="stat-card jobs">
                        <div class="icon">JOB</div>
                        <div class="label">Job Orders</div>
                        <div class="value"><?= array_sum($jo_stats) ?></div>
                        <small><?= $jo_stats['In Progress'] ?? 0 ?> in progress</small>
                    </div>
                    
                    <div class="stat-card" style="background: #6c757d;">
                        <div class="icon">CUST</div>
                        <div class="label">Customers Served</div>
                        <div class="value"><?= $shift2_data['merch']['count'] ?></div>
                        <small>This Shift</small>
                    </div>
                </div>
                
                <!-- Fuel Monitoring with Variance -->
                <div class="widget-card" style="margin: 20px 0;">
                    <h3 style="color: #003366;">Fuel Tank Levels & Variance Alerts</h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 15px;">
                        <?php foreach ($shift2_data['fuel_levels'] as $fl): ?>
                            <div style="background: #f7f7f7; padding: 15px; border-radius: 8px; border-left: 4px solid <?= $fl['stock_status'] == 'Critical' || $fl['stock_status'] == 'Out of Stock' ? '#dc3545' : ($fl['stock_status'] == 'Low Stock' ? '#6c757d' : '#28a745') ?>;">
                                <div style="font-weight: 600; color: #003366; margin-bottom: 5px;"><?= htmlspecialchars($fl['fuel_type_name']) ?></div>
                                <div style="font-size: 24px; font-weight: 700; color: #003366;"><?= number_format($fl['current_stock'], 2) ?> L</div>
                                <div style="font-size: 12px; color: #666666;">Capacity: <?= number_format($fl['capacity'], 2) ?> L</div>
                                <div style="margin-top: 5px;">
                                    <span style="padding: 3px 8px; border-radius: 12px; font-size: 10px; font-weight: 600; background: <?= $fl['stock_status'] == 'Critical' || $fl['stock_status'] == 'Out of Stock' ? '#f8d7da' : ($fl['stock_status'] == 'Low Stock' ? '#e8e8e8' : '#d4edda') ?>; color: <?= $fl['stock_status'] == 'Critical' || $fl['stock_status'] == 'Out of Stock' ? '#721c24' : ($fl['stock_status'] == 'Low Stock' ? '#666666' : '#155724') ?>;">
                                        <?= $fl['stock_status'] ?>
                                    </span>
                                </div>
                                <?php if ($fl['capacity'] > 0): ?>
                                    <div style="background: #e8e8e8; height: 6px; border-radius: 3px; margin-top: 8px; overflow: hidden;">
                                        <div style="background: <?= $fl['stock_status'] == 'Critical' || $fl['stock_status'] == 'Out of Stock' ? '#dc3545' : ($fl['stock_status'] == 'Low Stock' ? '#6c757d' : '#28a745') ?>; height: 100%; width: <?= min(100, ($fl['current_stock'] / $fl['capacity']) * 100) ?>%;"></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Merchandise Low Stock -->
                <div class="widget-card" style="margin: 20px 0;">
                    <h3 style="color: #003366;">Merchandise Stock Updates</h3>
                    <?php if (!empty($shift2_data['merch_low_stock'])): ?>
                        <div style="overflow:hidden; margin-top: 15px;">
                            <table style="width: 100%; border-collapse: collapse;">
                                <thead>
                                    <tr style="background: #f7f7f7;">
                                        <th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">Product</th>
                                        <th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">Category</th>
                                        <th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">Current Stock</th>
                                        <th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">Reorder Level</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($shift2_data['merch_low_stock'] as $item): ?>
                                        <tr>
                                            <td style="padding: 10px; border-bottom: 1px solid #eee;"><?= htmlspecialchars($item['product_name']) ?></td>
                                            <td style="padding: 10px; border-bottom: 1px solid #eee;"><?= htmlspecialchars($item['category']) ?></td>
                                            <td style="padding: 10px; border-bottom: 1px solid #eee;">
                                                <span style="color: <?= $item['current_stock'] <= 0 ? '#dc3545' : '#666666' ?>; font-weight: 600;">
                                                    <?= $item['current_stock'] ?>
                                                </span>
                                            </td>
                                            <td style="padding: 10px; border-bottom: 1px solid #eee;"><?= $item['threshold'] ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p style="text-align: center; color: #666666; padding: 20px;">All merchandise items are adequately stocked</p>
                    <?php endif; ?>
                </div>
                
                <!-- Charts -->
                <div class="charts-grid">
                    <div class="chart-card">
                        <h3>Fuel Sales by Type (Liters)</h3>
                        <canvas id="shift2FuelChart"></canvas>
                    </div>
                    
                    <div class="chart-card">
                        <h3>Payment Methods Breakdown</h3>
                        <canvas id="shift2PaymentChart"></canvas>
                    </div>
                    
                    <div class="chart-card">
                        <h3>Job Orders Trend</h3>
                        <canvas id="shift2JobOrderChart"></canvas>
                    </div>
                    
                    <div class="chart-card">
                        <h3>Merchandise Sales by Category</h3>
                        <canvas id="shift2MerchCategoryChart"></canvas>
                    </div>
                </div>
                
                <!-- Activity Log -->
                <div class="widget-card" style="margin: 20px 0;">
                    <h3 style="color: #003366;">Activity Log - Shift 2</h3>
                    <div style="max-height: 400px; overflow-y: auto; margin-top: 15px;">
                        <?php if (!empty($shift2_data['activity_log'])): ?>
                            <?php foreach ($shift2_data['activity_log'] as $log): ?>
                                <div style="padding: 12px; border-left: 3px solid #dc3545; background: #f7f7f7; margin-bottom: 10px; border-radius: 5px;">
                                    <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                        <span style="font-weight: 600; color: #003366;"><?= htmlspecialchars($log['action_type']) ?></span>
                                        <span style="font-size: 12px; color: #666666;"><?= date('g:i A', strtotime($log['created_at'])) ?></span>
                                    </div>
                                    <div style="font-size: 13px; color: #666666;"><?= htmlspecialchars($log['action_details']) ?></div>
                                    <div style="font-size: 11px; color: #999999; margin-top: 3px;">by <?= htmlspecialchars($log['username']) ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="text-align: center; color: #666666; padding: 20px;">No activity recorded for this shift</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Calendar Widget -->
                <div class="calendar-widget">
                    <div class="calendar-header">
                        <h3>Upcoming Tasks (Next 3 Days)</h3>
                    </div>
                    <div class="task-list">
                        <?php if (empty($calendar_tasks)): ?>
                            <p style="text-align: center; color: #666666; padding: 20px;">
                                No upcoming tasks
                            </p>
                        <?php else: ?>
                            <?php foreach ($calendar_tasks as $task): ?>
                                <div class="task-item">
                                    <div class="task-header">
                                        <span class="task-title">
                                            <?= htmlspecialchars($task['reference']) ?>
                                        </span>
                                        <span class="task-date"><?= date('M j, g:i A', strtotime($task['task_date'])) ?></span>
                                    </div>
                                    <div>Customer: <?= htmlspecialchars($task['customer']) ?></div>
                                    <span class="task-status" style="background: #e8e8e8; color: #dc3545;">
                                        <?= htmlspecialchars($task['status']) ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div style="background: #f7f7f7; padding: 20px; border-radius: 10px; margin-top: 20px;">
                    <h3 style="color: #003366; margin-bottom: 15px;">
                        Quick Actions
                    </h3>
                    <div class="quick-actions">
                        <a href="pos.php" class="quick-action-btn">
                            <div>POS</div>
                        </a>
                        <a href="credit_sale.php" class="quick-action-btn">
                            <div>Credit Sale</div>
                        </a>
                        <a href="job_orders.php" class="quick-action-btn">
                            <div>Job Orders</div>
                        </a>
                        <a href="fuel_transactions.php" class="quick-action-btn">
                            <div>Fuel Txn</div>
                        </a>
                        <a href="receive_items.php" class="quick-action-btn">
                            <div>Receive Items</div>
                        </a>
                        <a href="my_shift.php" class="quick-action-btn">
                            <div>My Shift</div>
                        </a>
                    </div>
                </div>
            </div>           </div>
                    
                    <div class="chart-card">
                        <h3><i class="fas fa-chart-line"></i> Job Orders Trend</h3>
                        <canvas id="shift2JobOrderChart"></canvas>
                    </div>
                    
                    <div class="chart-card">
                        <h3><i class="fas fa-exclamation-triangle"></i> Variance Alerts</h3>
                        <canvas id="shift2VarianceChart"></canvas>
                    </div>
                </div>
                
                <!-- Calendar Widget -->
                <div class="calendar-widget">
                    <div class="calendar-header">
                        <h3><i class="fas fa-calendar-check"></i> Upcoming Tasks (Next 3 Days)</h3>
                    </div>
                    <div class="task-list">
                        <?php if (empty($calendar_tasks)): ?>
                            <p style="text-align: center; color: #718096; padding: 20px;">
                                No upcoming tasks
                            </p>
                        <?php else: ?>
                            <?php foreach ($calendar_tasks as $task): ?>
                                <div class="task-item">
                                    <div class="task-header">
                                        <span class="task-title">
                                            <i class="fas fa-wrench"></i> <?= htmlspecialchars($task['reference']) ?>
                                        </span>
                                        <span class="task-date"><?= date('M j, g:i A', strtotime($task['task_date'])) ?></span>
                                    </div>
                                    <div>Customer: <?= htmlspecialchars($task['customer']) ?></div>
                                    <span class="task-status" style="background: #feebc8; color: #7c2d12;">
                                        <?= htmlspecialchars($task['status']) ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div style="background: #f7fafc; padding: 20px; border-radius: 10px; margin-top: 20px;">
                    <h3 style="color: #2d3748; margin-bottom: 15px;">
                        <i class="fas fa-bolt"></i> Quick Actions
                    </h3>
                    <div class="quick-actions">
                        <a href="pos.php" class="quick-action-btn">
                            <i class="fas fa-cash-register"></i>
                            <div>POS</div>
                        </a>
                        <a href="credit_sale.php" class="quick-action-btn">
                            <i class="fas fa-credit-card"></i>
                            <div>Credit Sale</div>
                        </a>
                        <a href="job_orders.php" class="quick-action-btn">
                            <i class="fas fa-tools"></i>
                            <div>Job Orders</div>
                        </a>
                        <a href="fuel_transactions.php" class="quick-action-btn">
                            <i class="fas fa-gas-pump"></i>
                            <div>Fuel Txn</div>
                        </a>
                        <a href="receive_items.php" class="quick-action-btn">
                            <i class="fas fa-box"></i>
                            <div>Receive Items</div>
                        </a>
                        <a href="my_shift.php" class="quick-action-btn">
                            <i class="fas fa-clock"></i>
                            <div>My Shift</div>
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- DAILY CONSOLIDATION CONTENT -->
            <div class="tab-content" id="consolidation">
                <div class="consolidation-header">
                    <h2>Daily Consolidation Report</h2>
                    <p><?= date('F j, Y') ?> - Combined Shift 1 + Shift 2</p>
                </div>
                
                <!-- Overall Summary Cards -->
                <div class="cards-grid">
                    <div class="stat-card" style="background: #003366;">
                        <div class="icon">TOTAL</div>
                        <div class="label">Total Revenue</div>
                        <div class="value">₱<?php 
                            $total_fuel = array_sum(array_column($shift1_data['fuel'], 'revenue')) + array_sum(array_column($shift2_data['fuel'], 'revenue'));
                            $total_merch = $shift1_data['merch']['total'] + $shift2_data['merch']['total'];
                            $total_service = $shift1_data['service']['total_service_income'] + $shift2_data['service']['total_service_income'];
                            echo number_format($total_fuel + $total_merch + $total_service, 2);
                        ?></div>
                        <small>All Sources</small>
                    </div>
                    
                    <div class="stat-card fuel">
                        <div class="icon">FUEL</div>
                        <div class="label">Fuel Sales (Both Shifts)</div>
                        <div class="value">₱<?= number_format($total_fuel, 2) ?></div>
                        <small><?= number_format(array_sum(array_column($shift1_data['fuel'], 'liters')) + array_sum(array_column($shift2_data['fuel'], 'liters')), 2) ?> L</small>
                    </div>
                    
                    <div class="stat-card merch">
                        <div class="icon">MERCH</div>
                        <div class="label">Merchandise Sales (Both Shifts)</div>
                        <div class="value">₱<?= number_format($total_merch, 2) ?></div>
                        <small><?= $shift1_data['merch']['count'] + $shift2_data['merch']['count'] ?> transactions</small>
                    </div>
                    
                    <div class="stat-card" style="background: #28a745;">
                        <div class="icon">SERVICE</div>
                        <div class="label">Service Income (Both Shifts)</div>
                        <div class="value">₱<?= number_format($total_service, 2) ?></div>
                        <small><?= $shift1_data['service']['completed_jobs'] + $shift2_data['service']['completed_jobs'] ?> completed jobs</small>
                    </div>
                    
                    <div class="stat-card jobs">
                        <div class="icon">JOB</div>
                        <div class="label">Total Job Orders</div>
                        <div class="value"><?= array_sum($jo_stats) ?></div>
                        <small><?= $jo_stats['Completed'] ?? 0 ?> completed</small>
                    </div>
                    
                    <div class="stat-card" style="background: #6c757d;">
                        <div class="icon">CUST</div>
                        <div class="label">New Customers</div>
                        <div class="value"><?= $new_customers ?></div>
                        <small>Registered Today</small>
                    </div>
                </div>
                
                <!-- Combined Charts -->
                <div class="charts-grid">
                    <div class="chart-card">
                        <h3><i class="fas fa-chart-bar"></i> Fuel Sales - Daily Comparison</h3>
                        <canvas id="consolidationFuelChart"></canvas>
                    </div>
                    
                    <div class="chart-card">
                        <h3><i class="fas fa-chart-pie"></i> Merchandise Category Distribution</h3>
                        <canvas id="consolidationMerchChart"></canvas>
                    </div>
                    
                    <div class="chart-card">
                        <h3><i class="fas fa-chart-line"></i> Job Orders - Daily Summary</h3>
                        <canvas id="consolidationJobOrderChart"></canvas>
                    </div>
                    
                    <div class="chart-card">
                        <h3><i class="fas fa-money-bill-wave"></i> Payment Methods - Daily Total</h3>
                        <canvas id="consolidationPaymentChart"></canvas>
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
                        <?php if (empty($calendar_tasks)): ?>
                            <p style="text-align: center; color: #666666; padding: 20px;">
                                No scheduled tasks
                            </p>
                        <?php else: ?>
                            <?php foreach ($calendar_tasks as $task): ?>
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
        </div>
    </div>

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
        
        // Shift 1 - Fuel Sales Chart
        const shift1FuelCtx = document.getElementById('shift1FuelChart').getContext('2d');
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
        
        // Shift 1 - Payment Methods Chart
        const shift1PaymentCtx = document.getElementById('shift1PaymentChart').getContext('2d');
        new Chart(shift1PaymentCtx, {
            type: 'pie',
            data: {
                labels: ['Cash', 'Card', 'E-Wallet', 'E-Fuel Card', 'Fleet Card'],
                datasets: [{
                    data: [
                        <?= $shift1_data['merch']['cash'] ?>,
                        <?= $shift1_data['merch']['card'] ?>,
                        <?= $shift1_data['merch']['ewallet'] ?>,
                        <?= $shift1_data['merch']['efuel'] ?>,
                        <?= $shift1_data['merch']['fleet'] ?>
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
        
        // Shift 1 - Job Orders Chart
        const shift1JobOrderCtx = document.getElementById('shift1JobOrderChart').getContext('2d');
        new Chart(shift1JobOrderCtx, {
            type: 'doughnut',
            data: {
                labels: ['Pending', 'In Progress', 'Completed', 'Rejected'],
                datasets: [{
                    data: [
                        <?= $jo_stats['Pending Validation'] ?? 0 ?>,
                        <?= $jo_stats['In Progress'] ?? 0 ?>,
                        <?= $jo_stats['Completed'] ?? 0 ?>,
                        <?= $jo_stats['Rejected'] ?? 0 ?>
                    ],
                    backgroundColor: ['#6c757d', '#003366', '#28a745', '#dc3545']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true
            }
        });
        
        // Shift 1 - Inventory Status Chart
        const shift1InventoryCtx = document.getElementById('shift1InventoryChart').getContext('2d');
        new Chart(shift1InventoryCtx, {
            type: 'bar',
            data: {
                labels: ['In Stock', 'Low Stock', 'Out of Stock'],
                datasets: [{
                    label: 'Item Count',
                    data: [45, 12, 3],
                    backgroundColor: ['#28a745', '#6c757d', '#dc3545']
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
        
        // Shift 2 - Fuel Sales Chart
        const shift2FuelCtx = document.getElementById('shift2FuelChart').getContext('2d');
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
        
        // Shift 2 - Payment Methods Chart
        const shift2PaymentCtx = document.getElementById('shift2PaymentChart').getContext('2d');
        new Chart(shift2PaymentCtx, {
            type: 'pie',
            data: {
                labels: ['Cash', 'Card', 'E-Wallet', 'E-Fuel Card', 'Fleet Card'],
                datasets: [{
                    data: [
                        <?= $shift2_data['merch']['cash'] ?>,
                        <?= $shift2_data['merch']['card'] ?>,
                        <?= $shift2_data['merch']['ewallet'] ?>,
                        <?= $shift2_data['merch']['efuel'] ?>,
                        <?= $shift2_data['merch']['fleet'] ?>
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
        
        // Shift 2 - Job Orders Trend Chart
        const shift2JobOrderCtx = document.getElementById('shift2JobOrderChart').getContext('2d');
        new Chart(shift2JobOrderCtx, {
            type: 'line',
            data: {
                labels: ['8 AM', '10 AM', '12 PM', '2 PM', '4 PM', '6 PM', '8 PM'],
                datasets: [{
                    label: 'Job Orders Created',
                    data: [2, 4, 3, 5, 6, 4, 2],
                    borderColor: '#003366',
                    backgroundColor: 'rgba(0, 51, 102, 0.1)',
                    tension: 0.4,
                    fill: true
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
        
        // Shift 2 - Variance Alerts Chart
        const shift2VarianceCtx = document.getElementById('shift2VarianceChart').getContext('2d');
        new Chart(shift2VarianceCtx, {
            type: 'bar',
            data: {
                labels: ['Diesel', 'Premium 95', 'Premium 97', 'Kerosene'],
                datasets: [{
                    label: 'Variance (Liters)',
                    data: [1.2, 0.5, 0.8, 0.3],
                    backgroundColor: ['#dc3545', '#28a745', '#28a745', '#28a745']
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
        
        // Consolidation - Fuel Sales Comparison Chart
        const consolidationFuelCtx = document.getElementById('consolidationFuelChart').getContext('2d');
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
        
        // Consolidation - Merchandise Category Chart
        const consolidationMerchCtx = document.getElementById('consolidationMerchChart').getContext('2d');
        new Chart(consolidationMerchCtx, {
            type: 'pie',
            data: {
                labels: ['Food & Beverages', 'Automotive', 'Tobacco', 'Snacks', 'Others'],
                datasets: [{
                    data: [35, 25, 20, 15, 5],
                    backgroundColor: [
                        '#003366',
                        '#28a745',
                        '#dc3545',
                        '#6c757d',
                        '#999999'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
        
        // Consolidation - Job Orders Summary Chart
        const consolidationJobOrderCtx = document.getElementById('consolidationJobOrderChart').getContext('2d');
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
        
        // Consolidation - Payment Methods Total Chart
        const consolidationPaymentCtx = document.getElementById('consolidationPaymentChart').getContext('2d');
        new Chart(consolidationPaymentCtx, {
            type: 'bar',
            data: {
                labels: ['Cash', 'Card', 'E-Wallet', 'E-Fuel Card', 'Fleet Card'],
                datasets: [
                    {
                        label: 'Shift 1',
                        data: [
                            <?= $shift1_data['merch']['cash'] ?>,
                            <?= $shift1_data['merch']['card'] ?>,
                            <?= $shift1_data['merch']['ewallet'] ?>,
                            <?= $shift1_data['merch']['efuel'] ?>,
                            <?= $shift1_data['merch']['fleet'] ?>
                        ],
                        backgroundColor: 'rgba(0, 51, 102, 0.8)'
                    },
                    {
                        label: 'Shift 2',
                        data: [
                            <?= $shift2_data['merch']['cash'] ?>,
                            <?= $shift2_data['merch']['card'] ?>,
                            <?= $shift2_data['merch']['ewallet'] ?>,
                            <?= $shift2_data['merch']['efuel'] ?>,
                            <?= $shift2_data['merch']['fleet'] ?>
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
        
        // Auto-refresh every 30 seconds
        setInterval(() => {
            location.reload();
        }, 30000);
    </script>
</body>
</html>
