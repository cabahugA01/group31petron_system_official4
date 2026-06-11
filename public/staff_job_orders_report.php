<?php
/**
 * STAFF JOB ORDER REPORTS - DAILY REPORTS
 * Professional implementation with shift summaries, export capabilities
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? 'staff');
$user_id = (int)($me['id'] ?? 0);
$station_id = user_station_id();

// Access control
if (!in_array($role, ['staff', 'cashier', 'pump_attendant', 'manager', 'admin', 'superadmin', 'developer'])) {
    header('Location: dashboard.php'); 
    exit;
}

// Module gate
if (!in_array($role, ['superadmin', 'developer']) && !is_module_enabled('reports')) {
    render_module_disabled_page('Reports');
}

if (!$station_id) {
    die('Error: You are not assigned to a station.');
}

// Get Station Name
$station_name = 'Station';
$station_location = '';
try {
    $s = $pdo->prepare("SELECT name, location FROM stations WHERE id=? LIMIT 1");
    $s->execute([$station_id]);
    $st = $s->fetch(PDO::FETCH_ASSOC);
    if ($st) {
        $station_name = $st['name'];
        $station_location = $st['location'] ?? '';
    }
} catch (Exception $e) {}

// Date handling
$today = date('Y-m-d');
$report_date = trim($_GET['report_date'] ?? $today);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date)) {
    $report_date = $today;
}

// Get shift from URL or default
$shift_filter = trim($_GET['shift'] ?? 'all');

// Helper function to determine shift based on time
function get_shift_from_time($datetime) {
    $hour = (int)date('H', strtotime($datetime));
    if ($hour >= 6 && $hour < 14) return 'Shift 1';
    elseif ($hour >= 14 && $hour < 22) return 'Shift 2';
    else return 'Shift 3';
}

// Check if tables exist
function table_exists($pdo, $table_name) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table_name'");
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function column_exists($pdo, $table, $column) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

$has_job_orders = table_exists($pdo, 'job_orders');
$has_mechanics = table_exists($pdo, 'mechanics');
$has_service_categories = table_exists($pdo, 'service_categories');
$has_job_order_parts = table_exists($pdo, 'job_order_parts');
$has_products = table_exists($pdo, 'products');

// Initialize data arrays
$job_orders = [];
$shift_summaries = [];
$overall_summary = [
    'total_jobs' => 0,
    'total_amount' => 0,
    'completed_jobs' => 0,
    'pending_jobs' => 0,
    'unpaid_jobs' => 0,
    'cancelled_jobs' => 0,
    'total_cash' => 0,
    'total_credit' => 0,
];

if (!$has_job_orders) {
    $error_message = "Job Orders table not found in database.";
} else {
    try {
        // Build the query dynamically based on available tables/columns
        $sql = "SELECT 
                    jo.id,
                    COALESCE(jo.job_order_number, CONCAT('JO-', jo.id)) AS job_order_id,
                    COALESCE(c.name, jo.customer_name, 'Walk-in') AS customer_name,
                    COALESCE(c.id, jo.customer_id) AS customer_id,
                    ";
        
        if ($has_service_categories) {
            $sql .= "COALESCE(sc.name, jo.service_type, 'General Service') AS service_type, ";
        } else {
            $sql .= "COALESCE(jo.service_type, 'General Service') AS service_type, ";
        }
        
        $sql .= "jo.vehicle_plate,
                 jo.status,
                 jo.payment_status,
                 jo.payment_mode,
                 COALESCE(jo.total_cost, jo.estimated_labor_cost + jo.estimated_parts_cost, 0) AS total_amount,
                 COALESCE(jo.estimated_labor_cost, 0) AS labor_cost,
                 COALESCE(jo.actual_parts_cost, jo.estimated_parts_cost, 0) AS parts_cost,
                 jo.created_at,
                 jo.completed_at,
                 ";
        
        if ($has_mechanics) {
            $sql .= "COALESCE(m.full_name, m.name, 'Unassigned') AS mechanic_name, ";
        } else {
            $sql .= "'Unassigned' AS mechanic_name, ";
        }
        
        $sql .= "u.name AS staff_encoder,
                 jo.notes,
                 jo.discount_amount
            FROM job_orders jo
            LEFT JOIN customers c ON jo.customer_id = c.id
            ";
        
        if ($has_service_categories) {
            $sql .= "LEFT JOIN service_categories sc ON jo.service_category_id = sc.id ";
        }
        
        if ($has_mechanics) {
            $sql .= "LEFT JOIN mechanics m ON jo.assigned_mechanic_id = m.id ";
        }
        
        $sql .= "LEFT JOIN users u ON jo.created_by = u.user_id
            WHERE jo.station_id = ? 
            AND DATE(jo.created_at) = ?
            ORDER BY jo.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$station_id, $report_date]);
        $all_jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get parts/materials for each job order if table exists
        if ($has_job_order_parts && $has_products) {
            foreach ($all_jobs as &$job) {
                $parts_sql = "SELECT 
                                jop.quantity_used,
                                jop.unit_cost,
                                jop.total_cost,
                                COALESCE(p.name, 'Unknown Part') AS product_name
                            FROM job_order_parts jop
                            LEFT JOIN products p ON jop.product_id = p.id
                            WHERE jop.job_order_id = ?";
                $parts_stmt = $pdo->prepare($parts_sql);
                $parts_stmt->execute([$job['id']]);
                $job['parts_used'] = $parts_stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            unset($job);
        }
        
        // Filter by shift if needed
        if ($shift_filter !== 'all') {
            $job_orders = array_filter($all_jobs, function($job) use ($shift_filter) {
                $job_shift = get_shift_from_time($job['created_at']);
                return $job_shift === $shift_filter;
            });
        } else {
            $job_orders = $all_jobs;
        }
        
        // Calculate shift summaries
        $shifts = ['Shift 1', 'Shift 2', 'Shift 3'];
        foreach ($shifts as $shift) {
            $shift_jobs = array_filter($all_jobs, function($job) use ($shift) {
                return get_shift_from_time($job['created_at']) === $shift;
            });
            
            $shift_total = array_sum(array_column($shift_jobs, 'total_amount'));
            $shift_completed = count(array_filter($shift_jobs, fn($j) => strtolower($j['status']) === 'completed'));
            $shift_cash = array_sum(array_map(function($j) {
                return (strtolower($j['payment_mode'] ?? '') === 'cash') ? $j['total_amount'] : 0;
            }, $shift_jobs));
            
            $shift_summaries[$shift] = [
                'total_jobs' => count($shift_jobs),
                'total_amount' => $shift_total,
                'completed' => $shift_completed,
                'cash' => $shift_cash,
                'credit' => $shift_total - $shift_cash,
            ];
        }
        
        // Calculate overall summary
        $overall_summary['total_jobs'] = count($all_jobs);
        $overall_summary['total_amount'] = array_sum(array_column($all_jobs, 'total_amount'));
        $overall_summary['completed_jobs'] = count(array_filter($all_jobs, fn($j) => strtolower($j['status']) === 'completed'));
        $overall_summary['pending_jobs'] = count(array_filter($all_jobs, fn($j) => in_array(strtolower($j['status']), ['pending', 'in progress'])));
        $overall_summary['unpaid_jobs'] = count(array_filter($all_jobs, fn($j) => strtolower($j['payment_status'] ?? 'unpaid') === 'unpaid'));
        $overall_summary['cancelled_jobs'] = count(array_filter($all_jobs, fn($j) => in_array(strtolower($j['status']), ['cancelled', 'rejected'])));
        $overall_summary['total_cash'] = array_sum(array_map(function($j) {
            return (strtolower($j['payment_mode'] ?? '') === 'cash') ? $j['total_amount'] : 0;
        }, $all_jobs));
        $overall_summary['total_credit'] = $overall_summary['total_amount'] - $overall_summary['total_cash'];
        
    } catch (Exception $e) {
        $error_message = "Error fetching job orders: " . $e->getMessage();
    }
}

// Handle export requests
if (isset($_GET['export'])) {
    $export_format = $_GET['export'];
    
    if ($export_format === 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment;filename="job_orders_report_' . $report_date . '.xls"');
        
        echo "DAILY JOB ORDER REPORT\n";
        echo "Station: $station_name - $station_location\n";
        echo "Date: " . date('F d, Y', strtotime($report_date)) . "\n";
        echo "Generated: " . date('Y-m-d H:i:s') . "\n\n";
        
        echo "Job Order ID\tCustomer Name\tService Type\tParts/Materials\tQuantity\tUnit Price\tTotal Amount\tPayment Mode\tStatus\tStaff Encoder\tRemarks\n";
        
        foreach ($job_orders as $job) {
            $parts_list = '';
            if (isset($job['parts_used']) && !empty($job['parts_used'])) {
                $parts_names = array_column($job['parts_used'], 'product_name');
                $parts_list = implode(', ', $parts_names);
            }
            
            echo $job['job_order_id'] . "\t" .
                 $job['customer_name'] . "\t" .
                 $job['service_type'] . "\t" .
                 ($parts_list ?: '—') . "\t" .
                 "—\t" .
                 "—\t" .
                 number_format($job['total_amount'], 2) . "\t" .
                 ($job['payment_mode'] ?: '—') . "\t" .
                 $job['status'] . "\t" .
                 ($job['staff_encoder'] ?: '—') . "\t" .
                 ($job['notes'] ?: '—') . "\n";
        }
        
        echo "\n\nSHIFT SUMMARIES\n";
        foreach ($shift_summaries as $shift => $summary) {
            echo "$shift:\n";
            echo "Total Jobs: " . $summary['total_jobs'] . "\n";
            echo "Total Amount: ₱" . number_format($summary['total_amount'], 2) . "\n";
            echo "Completed: " . $summary['completed'] . "\n";
            echo "Cash: ₱" . number_format($summary['cash'], 2) . "\n";
            echo "Credit: ₱" . number_format($summary['credit'], 2) . "\n\n";
        }
        
        echo "\nOVERALL SUMMARY\n";
        echo "Total Jobs: " . $overall_summary['total_jobs'] . "\n";
        echo "Total Amount: ₱" . number_format($overall_summary['total_amount'], 2) . "\n";
        echo "Completed: " . $overall_summary['completed_jobs'] . "\n";
        echo "Pending/Active: " . $overall_summary['pending_jobs'] . "\n";
        echo "Unpaid: " . $overall_summary['unpaid_jobs'] . "\n";
        echo "Cancelled: " . $overall_summary['cancelled_jobs'] . "\n";
        
        exit;
    }
    
    if ($export_format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment;filename="job_orders_report_' . $report_date . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        fputcsv($output, ['DAILY JOB ORDER REPORT']);
        fputcsv($output, ['Station', $station_name . ' - ' . $station_location]);
        fputcsv($output, ['Date', date('F d, Y', strtotime($report_date))]);
        fputcsv($output, ['Generated', date('Y-m-d H:i:s')]);
        fputcsv($output, []);
        
        fputcsv($output, ['Job Order ID', 'Customer Name', 'Service Type', 'Total Amount', 'Payment Mode', 'Status', 'Staff Encoder', 'Remarks']);
        
        foreach ($job_orders as $job) {
            fputcsv($output, [
                $job['job_order_id'],
                $job['customer_name'],
                $job['service_type'],
                number_format($job['total_amount'], 2),
                $job['payment_mode'] ?: '—',
                $job['status'],
                $job['staff_encoder'] ?: '—',
                $job['notes'] ?: '—'
            ]);
        }
        
        fclose($output);
        exit;
    }
    
    if ($export_format === 'pdf') {
        // Simple HTML-based PDF output
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Job Orders Report - <?= htmlspecialchars($report_date) ?></title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                .header { text-align: center; margin-bottom: 30px; }
                .header h1 { margin: 10px 0; color: #333; }
                .info { margin-bottom: 20px; }
                table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 11px; }
                th, td { border: 1px solid #ddd; padding: 6px; text-align: left; }
                th { background-color: #f2f2f2; font-weight: bold; }
                .summary { margin-top: 30px; padding: 15px; background-color: #f9f9f9; }
                .summary h3 { margin-top: 0; }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>DAILY JOB ORDER REPORT</h1>
                <p><strong><?= htmlspecialchars($station_name . ' - ' . $station_location) ?></strong></p>
                <p>Date: <?= date('F d, Y', strtotime($report_date)) ?> | Generated: <?= date('Y-m-d H:i:s') ?></p>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>Job Order ID</th>
                        <th>Customer</th>
                        <th>Service Type</th>
                        <th>Total Amount</th>
                        <th>Payment Mode</th>
                        <th>Status</th>
                        <th>Staff</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($job_orders as $job): ?>
                    <tr>
                        <td><?= htmlspecialchars($job['job_order_id']) ?></td>
                        <td><?= htmlspecialchars($job['customer_name']) ?></td>
                        <td><?= htmlspecialchars($job['service_type']) ?></td>
                        <td>₱<?= number_format($job['total_amount'], 2) ?></td>
                        <td><?= htmlspecialchars($job['payment_mode'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($job['status']) ?></td>
                        <td><?= htmlspecialchars($job['staff_encoder'] ?: '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div class="summary">
                <h3>Shift Summaries</h3>
                <?php foreach ($shift_summaries as $shift => $summary): ?>
                <p><strong><?= $shift ?>:</strong> 
                   <?= $summary['total_jobs'] ?> jobs, 
                   ₱<?= number_format($summary['total_amount'], 2) ?> total,
                   <?= $summary['completed'] ?> completed</p>
                <?php endforeach; ?>
                
                <h3>Overall Summary</h3>
                <p>Total Jobs: <?= $overall_summary['total_jobs'] ?></p>
                <p>Total Amount: ₱<?= number_format($overall_summary['total_amount'], 2) ?></p>
                <p>Completed: <?= $overall_summary['completed_jobs'] ?></p>
                <p>Pending/Active: <?= $overall_summary['pending_jobs'] ?></p>
                <p>Unpaid: <?= $overall_summary['unpaid_jobs'] ?></p>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

$page_title = "Daily Job Order Reports";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - <?= htmlspecialchars($station_name) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 32px;
            margin-bottom: 10px;
            font-weight: 600;
        }
        
        .header p {
            font-size: 16px;
            opacity: 0.9;
        }
        
        .controls {
            padding: 20px 30px;
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .date-controls {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .date-controls input[type="date"],
        .date-controls select {
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .export-buttons {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5568d3;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .btn-info {
            background: #17a2b8;
            color: white;
        }
        
        .btn-info:hover {
            background: #138496;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            padding: 30px;
            background: #f8f9fa;
        }
        
        .summary-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            text-align: center;
            transition: transform 0.3s ease;
        }
        
        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
        }
        
        .summary-card .icon {
            font-size: 32px;
            margin-bottom: 10px;
        }
        
        .summary-card .label {
            font-size: 14px;
            color: #6c757d;
            margin-bottom: 5px;
        }
        
        .summary-card .value {
            font-size: 24px;
            font-weight: 700;
            color: #333;
        }
        
        .card-blue .icon { color: #667eea; }
        .card-green .icon { color: #28a745; }
        .card-orange .icon { color: #fd7e14; }
        .card-red .icon { color: #dc3545; }
        .card-purple .icon { color: #6f42c1; }
        .card-info .icon { color: #17a2b8; }
        
        .content {
            padding: 30px;
        }
        
        .section-title {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #333;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .table-container {
            overflow-x: auto;
            margin-bottom: 30px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
        }
        
        thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        td {
            padding: 12px 15px;
            border-bottom: 1px solid #dee2e6;
            font-size: 14px;
        }
        
        tbody tr:hover {
            background: #f8f9fa;
        }
        
        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-completed { background: #d4edda; color: #155724; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-in-progress { background: #d1ecf1; color: #0c5460; }
        .status-cancelled { background: #f8d7da; color: #721c24; }
        
        .payment-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .payment-cash { background: #d4edda; color: #155724; }
        .payment-credit { background: #d1ecf1; color: #0c5460; }
        .payment-suki { background: #fff3cd; color: #856404; }
        
        .shift-summary {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .shift-summary h3 {
            font-size: 18px;
            margin-bottom: 15px;
            color: #667eea;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .shift-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
        }
        
        .shift-stat {
            background: white;
            padding: 15px;
            border-radius: 6px;
            text-align: center;
        }
        
        .shift-stat .label {
            font-size: 12px;
            color: #6c757d;
            margin-bottom: 5px;
        }
        
        .shift-stat .value {
            font-size: 20px;
            font-weight: 700;
            color: #333;
        }
        
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        
        .no-data i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.3;
        }
        
        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 6px;
            margin: 20px 30px;
            border-left: 4px solid #dc3545;
        }
        
        @media print {
            body { background: white; padding: 0; }
            .controls, .export-buttons, .btn { display: none; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1><i class="fas fa-clipboard-list"></i> DAILY JOB ORDER REPORTS</h1>
            <p><?= htmlspecialchars($station_name) ?> <?= $station_location ? '- ' . htmlspecialchars($station_location) : '' ?></p>
            <p>Date: <?= date('F d, Y', strtotime($report_date)) ?> | Shift: <?= $shift_filter === 'all' ? 'All Shifts' : htmlspecialchars($shift_filter) ?></p>
        </div>
        
        <!-- Controls -->
        <div class="controls">
            <div class="date-controls">
                <label><strong>Report Date:</strong></label>
                <input type="date" id="report_date" value="<?= htmlspecialchars($report_date) ?>" max="<?= $today ?>">
                
                <label><strong>Shift:</strong></label>
                <select id="shift_filter">
                    <option value="all" <?= $shift_filter === 'all' ? 'selected' : '' ?>>All Shifts</option>
                    <option value="Shift 1" <?= $shift_filter === 'Shift 1' ? 'selected' : '' ?>>Shift 1 (6AM-2PM)</option>
                    <option value="Shift 2" <?= $shift_filter === 'Shift 2' ? 'selected' : '' ?>>Shift 2 (2PM-10PM)</option>
                    <option value="Shift 3" <?= $shift_filter === 'Shift 3' ? 'selected' : '' ?>>Shift 3 (10PM-6AM)</option>
                </select>
                
                <button class="btn btn-primary" onclick="applyFilters()">
                    <i class="fas fa-filter"></i> Apply
                </button>
            </div>
            
            <div class="export-buttons">
                <a href="?export=excel&report_date=<?= urlencode($report_date) ?>&shift=<?= urlencode($shift_filter) ?>" class="btn btn-success">
                    <i class="fas fa-file-excel"></i> Export Excel
                </a>
                <a href="?export=csv&report_date=<?= urlencode($report_date) ?>&shift=<?= urlencode($shift_filter) ?>" class="btn btn-info">
                    <i class="fas fa-file-csv"></i> Export CSV
                </a>
                <a href="?export=pdf&report_date=<?= urlencode($report_date) ?>&shift=<?= urlencode($shift_filter) ?>" class="btn btn-danger">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </a>
            </div>
        </div>
        
        <?php if (isset($error_message)): ?>
        <div class="error-message">
            <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error_message) ?>
        </div>
        <?php endif; ?>
        
        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card card-blue">
                <div class="icon"><i class="fas fa-clipboard-list"></i></div>
                <div class="label">Total Job Orders</div>
                <div class="value"><?= number_format($overall_summary['total_jobs']) ?></div>
            </div>
            
            <div class="summary-card card-green">
                <div class="icon"><i class="fas fa-check-circle"></i></div>
                <div class="label">Completed Jobs</div>
                <div class="value"><?= number_format($overall_summary['completed_jobs']) ?></div>
            </div>
            
            <div class="summary-card card-orange">
                <div class="icon"><i class="fas fa-hourglass-half"></i></div>
                <div class="label">Pending/Active</div>
                <div class="value"><?= number_format($overall_summary['pending_jobs']) ?></div>
            </div>
            
            <div class="summary-card card-red">
                <div class="icon"><i class="fas fa-money-bill-wave"></i></div>
                <div class="label">Unpaid Jobs</div>
                <div class="value"><?= number_format($overall_summary['unpaid_jobs']) ?></div>
            </div>
            
            <div class="summary-card card-purple">
                <div class="icon"><i class="fas fa-peso-sign"></i></div>
                <div class="label">Total Amount</div>
                <div class="value">₱<?= number_format($overall_summary['total_amount'], 2) ?></div>
            </div>
            
            <div class="summary-card card-info">
                <div class="icon"><i class="fas fa-ban"></i></div>
                <div class="label">Cancelled Jobs</div>
                <div class="value"><?= number_format($overall_summary['cancelled_jobs']) ?></div>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="content">
            <?php if (!isset($error_message) && count($job_orders) > 0): ?>
            
            <!-- Job Orders Table -->
            <div class="section-title">
                <i class="fas fa-table"></i> Job Orders List
            </div>
            
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Job Order ID</th>
                            <th>Customer Name</th>
                            <th>Service Type</th>
                            <th>Parts/Materials</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Total Amount</th>
                            <th>Payment Mode</th>
                            <th>Status</th>
                            <th>Staff Encoder</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($job_orders as $job): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($job['job_order_id']) ?></strong></td>
                            <td><?= htmlspecialchars($job['customer_name']) ?></td>
                            <td><?= htmlspecialchars($job['service_type']) ?></td>
                            <td>
                                <?php if (isset($job['parts_used']) && !empty($job['parts_used'])): ?>
                                    <?php foreach ($job['parts_used'] as $part): ?>
                                        <div><?= htmlspecialchars($part['product_name']) ?></div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span style="color: #6c757d;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (isset($job['parts_used']) && !empty($job['parts_used'])): ?>
                                    <?php foreach ($job['parts_used'] as $part): ?>
                                        <div><?= number_format($part['quantity_used'], 2) ?></div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span style="color: #6c757d;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (isset($job['parts_used']) && !empty($job['parts_used'])): ?>
                                    <?php foreach ($job['parts_used'] as $part): ?>
                                        <div>₱<?= number_format($part['unit_cost'], 2) ?></div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span style="color: #6c757d;">—</span>
                                <?php endif; ?>
                            </td>
                            <td><strong>₱<?= number_format($job['total_amount'], 2) ?></strong></td>
                            <td>
                                <?php 
                                $payment_mode = strtolower($job['payment_mode'] ?? '');
                                $payment_class = 'payment-cash';
                                if ($payment_mode === 'credit') $payment_class = 'payment-credit';
                                elseif ($payment_mode === 'suki') $payment_class = 'payment-suki';
                                ?>
                                <span class="payment-badge <?= $payment_class ?>">
                                    <?= htmlspecialchars($job['payment_mode'] ?: '—') ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                $status = strtolower($job['status']);
                                $status_class = 'status-pending';
                                if ($status === 'completed') $status_class = 'status-completed';
                                elseif (in_array($status, ['in progress', 'in-progress'])) $status_class = 'status-in-progress';
                                elseif (in_array($status, ['cancelled', 'rejected'])) $status_class = 'status-cancelled';
                                ?>
                                <span class="status-badge <?= $status_class ?>">
                                    <?= htmlspecialchars($job['status']) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($job['staff_encoder'] ?: '—') ?></td>
                            <td><?= htmlspecialchars($job['notes'] ?: '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Shift Summaries -->
            <div class="section-title">
                <i class="fas fa-chart-bar"></i> Shift Summaries
            </div>
            
            <?php foreach ($shift_summaries as $shift => $summary): ?>
            <div class="shift-summary">
                <h3><i class="fas fa-clock"></i> <?= htmlspecialchars($shift) ?></h3>
                <div class="shift-stats">
                    <div class="shift-stat">
                        <div class="label">Total Jobs</div>
                        <div class="value"><?= number_format($summary['total_jobs']) ?></div>
                    </div>
                    <div class="shift-stat">
                        <div class="label">Total Amount</div>
                        <div class="value">₱<?= number_format($summary['total_amount'], 2) ?></div>
                    </div>
                    <div class="shift-stat">
                        <div class="label">Completed</div>
                        <div class="value"><?= number_format($summary['completed']) ?></div>
                    </div>
                    <div class="shift-stat">
                        <div class="label">Cash</div>
                        <div class="value">₱<?= number_format($summary['cash'], 2) ?></div>
                    </div>
                    <div class="shift-stat">
                        <div class="label">Credit</div>
                        <div class="value">₱<?= number_format($summary['credit'], 2) ?></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            
            <!-- Overall Daily Summary -->
            <div class="section-title">
                <i class="fas fa-calculator"></i> Overall Daily Summary
            </div>
            
            <div class="shift-summary">
                <div class="shift-stats">
                    <div class="shift-stat">
                        <div class="label">Total Job Orders</div>
                        <div class="value"><?= number_format($overall_summary['total_jobs']) ?></div>
                    </div>
                    <div class="shift-stat">
                        <div class="label">Total Amount</div>
                        <div class="value">₱<?= number_format($overall_summary['total_amount'], 2) ?></div>
                    </div>
                    <div class="shift-stat">
                        <div class="label">Completed</div>
                        <div class="value"><?= number_format($overall_summary['completed_jobs']) ?></div>
                    </div>
                    <div class="shift-stat">
                        <div class="label">Pending/Active</div>
                        <div class="value"><?= number_format($overall_summary['pending_jobs']) ?></div>
                    </div>
                    <div class="shift-stat">
                        <div class="label">Total Cash</div>
                        <div class="value">₱<?= number_format($overall_summary['total_cash'], 2) ?></div>
                    </div>
                    <div class="shift-stat">
                        <div class="label">Total Credit</div>
                        <div class="value">₱<?= number_format($overall_summary['total_credit'], 2) ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Additional Summaries -->
            <div class="section-title">
                <i class="fas fa-list-check"></i> Additional Summaries
            </div>
            
            <div class="shift-summary">
                <h3><i class="fas fa-money-bill-wave"></i> Unpaid Job Orders</h3>
                <?php 
                $unpaid_jobs = array_filter($job_orders, fn($j) => strtolower($j['payment_status'] ?? 'unpaid') === 'unpaid');
                if (count($unpaid_jobs) > 0):
                ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Job Order ID</th>
                                <th>Customer</th>
                                <th>Service Type</th>
                                <th>Total Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($unpaid_jobs as $job): ?>
                            <tr>
                                <td><?= htmlspecialchars($job['job_order_id']) ?></td>
                                <td><?= htmlspecialchars($job['customer_name']) ?></td>
                                <td><?= htmlspecialchars($job['service_type']) ?></td>
                                <td>₱<?= number_format($job['total_amount'], 2) ?></td>
                                <td><span class="status-badge status-pending">Unpaid</span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p style="color: #6c757d; padding: 20px; text-align: center;">No unpaid job orders for this period.</p>
                <?php endif; ?>
            </div>
            
            <div class="shift-summary">
                <h3><i class="fas fa-check-double"></i> Completed Job Orders</h3>
                <?php 
                $completed_jobs = array_filter($job_orders, fn($j) => strtolower($j['status']) === 'completed');
                if (count($completed_jobs) > 0):
                ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Job Order ID</th>
                                <th>Customer</th>
                                <th>Service Type</th>
                                <th>Total Amount</th>
                                <th>Payment Mode</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($completed_jobs as $job): ?>
                            <tr>
                                <td><?= htmlspecialchars($job['job_order_id']) ?></td>
                                <td><?= htmlspecialchars($job['customer_name']) ?></td>
                                <td><?= htmlspecialchars($job['service_type']) ?></td>
                                <td>₱<?= number_format($job['total_amount'], 2) ?></td>
                                <td><?= htmlspecialchars($job['payment_mode'] ?: '—') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p style="color: #6c757d; padding: 20px; text-align: center;">No completed job orders for this period.</p>
                <?php endif; ?>
            </div>
            
            <div class="shift-summary">
                <h3><i class="fas fa-ban"></i> Cancelled Job Orders</h3>
                <?php 
                $cancelled_jobs = array_filter($job_orders, fn($j) => in_array(strtolower($j['status']), ['cancelled', 'rejected']));
                if (count($cancelled_jobs) > 0):
                ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Job Order ID</th>
                                <th>Customer</th>
                                <th>Service Type</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cancelled_jobs as $job): ?>
                            <tr>
                                <td><?= htmlspecialchars($job['job_order_id']) ?></td>
                                <td><?= htmlspecialchars($job['customer_name']) ?></td>
                                <td><?= htmlspecialchars($job['service_type']) ?></td>
                                <td><?= htmlspecialchars($job['notes'] ?: 'No reason provided') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p style="color: #6c757d; padding: 20px; text-align: center;">No cancelled job orders for this period.</p>
                <?php endif; ?>
            </div>
            
            <?php elseif (!isset($error_message)): ?>
            <div class="no-data">
                <i class="fas fa-inbox"></i>
                <h3>No Job Orders Found</h3>
                <p>There are no job orders for the selected date and shift.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function applyFilters() {
            const reportDate = document.getElementById('report_date').value;
            const shift = document.getElementById('shift_filter').value;
            window.location.href = `?report_date=${reportDate}&shift=${shift}`;
        }
        
        // Allow Enter key to apply filters
        document.getElementById('report_date').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                applyFilters();
            }
        });
    </script>
</body>
</html>
