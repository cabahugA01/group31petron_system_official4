<?php
$page_id = 'job_order_reports';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$station_id = user_station_id();
$role = role_key($me['role'] ?? '');

// Only managers and above can access reports
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Manager privileges required.';
    header('Location: dashboard.php');
    exit;
}

$msg = '';
if (isset($_SESSION['success'])) { 
    $msg = $_SESSION['success']; 
    unset($_SESSION['success']); 
}
if (isset($_SESSION['error'])) { 
    $msg = $_SESSION['error']; 
    unset($_SESSION['error']); 
}

// Handle export requests
if (isset($_GET['export'])) {
    $export_type = $_GET['export'];
    $date_range = $_GET['date_range'] ?? 'this_month';
    
    try {
        // Generate date filter
        $date_filter = '';
        switch ($date_range) {
            case 'today':
                $date_filter = "DATE(jo.created_at) = CURDATE()";
                break;
            case 'this_week':
                $date_filter = "YEARWEEK(jo.created_at) = YEARWEEK(CURDATE())";
                break;
            case 'this_month':
                $date_filter = "YEAR(jo.created_at) = YEAR(CURDATE()) AND MONTH(jo.created_at) = MONTH(CURDATE())";
                break;
            case 'last_month':
                $date_filter = "YEAR(jo.created_at) = YEAR(CURDATE() - INTERVAL 1 MONTH) AND MONTH(jo.created_at) = MONTH(CURDATE() - INTERVAL 1 MONTH)";
                break;
            case 'this_year':
                $date_filter = "YEAR(jo.created_at) = YEAR(CURDATE())";
                break;
        }
        
        // Get report data
        $stmt = $pdo->prepare("
            SELECT 
                jo.id,
                jo.job_order_number,
                jo.created_at,
                jo.completed_at,
                jo.status,
                jo.service_description,
                jo.vehicle_plate,
                jo.vehicle_type,
                c.name as customer_name,
                m.full_name as technician_name,
                ar.amount as receivable_amount,
                ar.status as receivable_status,
                ar.due_date,
                CASE 
                    WHEN jo.status = 'Completed' AND jo.completed_at IS NOT NULL 
                    THEN TIMESTAMPDIFF(MINUTE, jo.created_at, jo.completed_at)
                    ELSE NULL 
                END as duration_minutes
            FROM job_orders jo
            LEFT JOIN customers c ON jo.customer_id = c.id
            LEFT JOIN mechanics m ON jo.assigned_mechanic_id = m.id
            LEFT JOIN accounts_receivable ar ON jo.id = ar.job_order_id
            WHERE jo.station_id = ? AND ($date_filter)
            ORDER BY jo.created_at DESC
        ");
        $stmt->execute([$station_id]);
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Export to CSV
        if ($export_type === 'csv') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="job_orders_report_' . date('Y-m-d') . '.csv"');
            
            $output = fopen('php://output', 'w');
            
            // Header row
            fputcsv($output, [
                'Job Order ID',
                'Job Order Number',
                'Customer',
                'Vehicle',
                'Service',
                'Technician',
                'Status',
                'Credit Amount',
                'Receivable Status',
                'Due Date',
                'Created Date',
                'Completed Date',
                'Duration (Minutes)'
            ]);
            
            // Data rows
            foreach ($report_data as $row) {
                fputcsv($output, [
                    $row['id'],
                    $row['job_order_number'],
                    $row['customer_name'] ?? 'Walk-in',
                    $row['vehicle_plate'] ?? '',
                    $row['service_description'],
                    $row['technician_name'] ?? 'Unassigned',
                    $row['status'],
                    $row['receivable_amount'] ? '₱' . number_format($row['receivable_amount'], 2) : '',
                    $row['receivable_status'] ?? '',
                    $row['due_date'] ?? '',
                    $row['created_at'],
                    $row['completed_at'] ?? '',
                    $row['duration_minutes'] ?? ''
                ]);
            }
            
            fclose($output);
            exit;
        }
        
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error generating report: ' . $e->getMessage();
        header('Location: job_order_reports.php');
        exit;
    }
}

// Fetch report data
$report_data = [];
$summary_stats = [];
$receivables_summary = [];

try {
    // Default date range
    $date_range = $_GET['date_range'] ?? 'this_month';
    $date_filter = '';
    switch ($date_range) {
        case 'today':
            $date_filter = "DATE(jo.created_at) = CURDATE()";
            break;
        case 'this_week':
            $date_filter = "YEARWEEK(jo.created_at) = YEARWEEK(CURDATE())";
            break;
        case 'this_month':
            $date_filter = "YEAR(jo.created_at) = YEAR(CURDATE()) AND MONTH(jo.created_at) = MONTH(CURDATE())";
            break;
        case 'last_month':
            $date_filter = "YEAR(jo.created_at) = YEAR(CURDATE() - INTERVAL 1 MONTH) AND MONTH(jo.created_at) = MONTH(CURDATE() - INTERVAL 1 MONTH)";
            break;
        case 'this_year':
            $date_filter = "YEAR(jo.created_at) = YEAR(CURDATE())";
            break;
    }
    
    // Get detailed report data
    $stmt = $pdo->prepare("
        SELECT 
            jo.id,
            jo.job_order_number,
            jo.created_at,
            jo.completed_at,
            jo.status,
            jo.service_description,
            jo.vehicle_plate,
            jo.vehicle_type,
            c.name as customer_name,
            m.full_name as technician_name,
            ar.amount as receivable_amount,
            ar.status as receivable_status,
            ar.due_date,
            CASE 
                WHEN jo.status = 'Completed' AND jo.completed_at IS NOT NULL 
                THEN TIMESTAMPDIFF(MINUTE, jo.created_at, jo.completed_at)
                ELSE NULL 
            END as duration_minutes
        FROM job_orders jo
        LEFT JOIN customers c ON jo.customer_id = c.id
        LEFT JOIN mechanics m ON jo.assigned_mechanic_id = m.id
        LEFT JOIN accounts_receivable ar ON jo.id = ar.job_order_id
        WHERE jo.station_id = ? AND ($date_filter)
        ORDER BY jo.created_at DESC
    ");
    $stmt->execute([$station_id]);
    $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate summary statistics
    $total_jobs = count($report_data);
    $completed_jobs = count(array_filter($report_data, fn($j) => $j['status'] === 'Completed'));
    $pending_jobs = count(array_filter($report_data, fn($j) => $j['status'] === 'Pending'));
    $in_progress_jobs = count(array_filter($report_data, fn($j) => $j['status'] === 'In Progress'));
    $credit_jobs = count(array_filter($report_data, fn($j) => !empty($j['receivable_amount'])));
    $total_receivables = array_sum(array_column(array_filter($report_data, fn($j) => !empty($j['receivable_amount'])), 'receivable_amount'));
    
    $summary_stats = [
        'total_jobs' => $total_jobs,
        'completed_jobs' => $completed_jobs,
        'pending_jobs' => $pending_jobs,
        'in_progress_jobs' => $in_progress_jobs,
        'credit_jobs' => $credit_jobs,
        'completion_rate' => $total_jobs > 0 ? round(($completed_jobs / $total_jobs) * 100, 1) : 0,
        'total_receivables' => $total_receivables,
        'avg_duration' => $completed_jobs > 0 ? round(array_sum(array_column(array_filter($report_data, fn($j) => $j['duration_minutes'] !== null), 'duration_minutes')) / $completed_jobs, 0) : 0
    ];
    
    // Receivables summary by status
    $receivables_by_status = [];
    foreach ($report_data as $job) {
        if (!empty($job['receivable_amount'])) {
            $status = $job['receivable_status'] ?? 'Unknown';
            if (!isset($receivables_by_status[$status])) {
                $receivables_by_status[$status] = ['count' => 0, 'amount' => 0];
            }
            $receivables_by_status[$status]['count']++;
            $receivables_by_status[$status]['amount'] += $job['receivable_amount'];
        }
    }
    
    $receivables_summary = $receivables_by_status;
    
} catch (Exception $e) {
    error_log("Error fetching job order report data: " . $e->getMessage());
}

include __DIR__ . '/../partials/header.php';
?>

<style>
.job-order-reports {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.reports-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
}

.date-filter {
    display: flex;
    gap: 10px;
    align-items: center;
}

.date-filter select {
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
}

.export-btn {
    background: #28a745;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.export-btn:hover {
    background: #1e7e34;
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.summary-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border: 1px solid #e9ecef;
    padding: 24px;
    text-align: center;
}

.summary-value {
    font-size: 2rem;
    font-weight: 700;
    color: #003d7a;
    margin-bottom: 8px;
}

.summary-label {
    font-size: 0.9rem;
    color: #666;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.receivables-section {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border: 1px solid #e9ecef;
    padding: 24px;
    margin-bottom: 30px;
}

.receivables-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.receivable-card {
    text-align: center;
    padding: 20px;
    border-radius: 8px;
    border: 1px solid #e9ecef;
}

.receivable-amount {
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 8px;
}

.receivable-count {
    font-size: 0.9rem;
    color: #666;
}

.status-pending { background: #fff3cd; color: #856404; }
.status-paid { background: #d4edda; color: #155724; }
.status-overdue { background: #f8d7da; color: #721c24; }
.status-unknown { background: #e2e3e5; color: #383d41; }

.report-table {
    width: 100%;
    border-collapse: collapse;
    background: #fff;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.report-table th,
.report-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #e9ecef;
}

.report-table th {
    background: #f8f9fa;
    font-weight: 600;
    color: #333;
}

.report-table tr:hover {
    background: #f8f9fa;
}

.status-badge {
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.credit-amount {
    font-weight: 600;
    color: #003d7a;
}

@media (max-width: 768px) {
    .reports-header {
        flex-direction: column;
        gap: 20px;
        align-items: flex-start;
    }
    
    .date-filter {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .summary-grid {
        grid-template-columns: 1fr;
    }
    
    .receivables-grid {
        grid-template-columns: 1fr;
    }
    
    .report-table {
        font-size: 12px;
    }
    
    .report-table th,
    .report-table td {
        padding: 8px;
    }
}
</style>

<div class="job-order-reports">
    <div class="page-head">
        <div>
            <h1 class="h1">Job Order Reports & Receivables</h1>
            <div class="sub">Job status tracking and receivables management</div>
        </div>
    </div>

<?php if($msg): ?>
<div class="alert <?php echo strpos($msg, 'Error') !== false ? 'alert-error' : 'alert-success'; ?>">
    <?php echo $msg; ?>
</div>
<?php endif; ?>

    <!-- Reports Header with Filters -->
    <div class="reports-header">
        <div class="date-filter">
            <label for="date_range">Date Range:</label>
            <select id="date_range" onchange="changeDateRange()">
                <option value="today" <?php echo $date_range === 'today' ? 'selected' : ''; ?>>Today</option>
                <option value="this_week" <?php echo $date_range === 'this_week' ? 'selected' : ''; ?>>This Week</option>
                <option value="this_month" <?php echo $date_range === 'this_month' ? 'selected' : ''; ?>>This Month</option>
                <option value="last_month" <?php echo $date_range === 'last_month' ? 'selected' : ''; ?>>Last Month</option>
                <option value="this_year" <?php echo $date_range === 'this_year' ? 'selected' : ''; ?>>This Year</option>
            </select>
        </div>
        
        <div>
            <a href="?export=csv&date_range=<?php echo $date_range; ?>" class="export-btn">
                <i class="fas fa-download"></i> Export CSV
            </a>
        </div>
    </div>

    <!-- Summary Statistics -->
    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-value"><?php echo $summary_stats['total_jobs']; ?></div>
            <div class="summary-label">Total Job Orders</div>
        </div>
        <div class="summary-card">
            <div class="summary-value"><?php echo $summary_stats['completed_jobs']; ?></div>
            <div class="summary-label">Completed</div>
        </div>
        <div class="summary-card">
            <div class="summary-value"><?php echo $summary_stats['completion_rate']; ?>%</div>
            <div class="summary-label">Completion Rate</div>
        </div>
        <div class="summary-card">
            <div class="summary-value"><?php echo $summary_stats['credit_jobs']; ?></div>
            <div class="summary-label">Credit Jobs</div>
        </div>
        <div class="summary-card">
            <div class="summary-value">₱<?php echo number_format($summary_stats['total_receivables'], 2); ?></div>
            <div class="summary-label">Total Receivables</div>
        </div>
        <div class="summary-card">
            <div class="summary-value"><?php echo $summary_stats['avg_duration']; ?> min</div>
            <div class="summary-label">Avg Duration</div>
        </div>
    </div>

    <!-- Receivables Summary -->
    <div class="receivables-section">
        <h3>Receivables by Status</h3>
        <div class="receivables-grid">
            <?php foreach ($receivables_summary as $status => $data): ?>
                <div class="receivable-card status-<?php echo strtolower($status); ?>">
                    <div class="receivable-amount">₱<?php echo number_format($data['amount'], 2); ?></div>
                    <div class="receivable-count"><?php echo $data['count']; ?> <?php echo ucfirst($status); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Detailed Report Table -->
    <div class="report-table-container">
        <table class="report-table">
            <thead>
                <tr>
                    <th>Job ID</th>
                    <th>Customer</th>
                    <th>Vehicle</th>
                    <th>Service</th>
                    <th>Technician</th>
                    <th>Status</th>
                    <th>Credit Amount</th>
                    <th>Receivable Status</th>
                    <th>Due Date</th>
                    <th>Duration</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report_data as $job): ?>
                    <tr>
                        <td><strong>#<?php echo $job['id']; ?></strong></td>
                        <td><?php echo htmlspecialchars($job['customer_name'] ?? 'Walk-in'); ?></td>
                        <td><?php echo htmlspecialchars($job['vehicle_plate'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars(substr($job['service_description'] ?? '', 0, 30)); ?></td>
                        <td><?php echo htmlspecialchars($job['technician_name'] ?? 'Unassigned'); ?></td>
                        <td>
                            <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $job['status'])); ?>">
                                <?php echo htmlspecialchars($job['status']); ?>
                            </span>
                        </td>
                        <td>
                            <?php if (!empty($job['receivable_amount'])): ?>
                                <span class="credit-amount">₱<?php echo number_format($job['receivable_amount'], 2); ?></span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($job['receivable_status'])): ?>
                                <span class="status-badge status-<?php echo strtolower($job['receivable_status']); ?>">
                                    <?php echo htmlspecialchars($job['receivable_status']); ?>
                                </span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($job['due_date'])): ?>
                                <?php echo date('M j, Y', strtotime($job['due_date'])); ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($job['duration_minutes'] !== null): ?>
                                <?php echo $job['duration_minutes']; ?> min
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td><?php echo date('M j, H:i', strtotime($job['created_at'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function changeDateRange() {
    const dateRange = document.getElementById('date_range').value;
    window.location.href = `job_order_reports.php?date_range=${dateRange}`;
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
