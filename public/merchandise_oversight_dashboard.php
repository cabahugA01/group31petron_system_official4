<?php
$page_id = 'merchandise_oversight_dashboard';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$station_id = user_station_id();
$role = role_key($me['role'] ?? '');

// Only managers and above can access this dashboard
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

// Get date range from GET parameters or default to last 7 days
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// Fetch merchandise transaction statistics
$stats = [
    'total_transactions' => 0,
    'pending_transactions' => 0,
    'approved_transactions' => 0,
    'rejected_transactions' => 0,
    'total_amount' => 0,
    'pending_amount' => 0,
    'approved_amount' => 0,
    'rejected_amount' => 0,
    'daily_avg' => 0,
    'staff_performance' => []
];

try {
    // Overall transaction statistics
    // For Admin: 'Pending' means awaiting Manager validation (informational only — Admin doesn't act on these)
    // For Manager: 'Pending' means awaiting their validation action
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_transactions,
            COUNT(CASE WHEN validation_status = 'Pending' THEN 1 END) as pending_transactions,
            COUNT(CASE WHEN validation_status = 'Approved' THEN 1 END) as approved_transactions,
            COUNT(CASE WHEN validation_status = 'Rejected' THEN 1 END) as rejected_transactions,
            COALESCE(SUM(total_amount), 0) as total_amount,
            COALESCE(SUM(CASE WHEN validation_status = 'Pending' THEN total_amount ELSE 0 END), 0) as pending_amount,
            COALESCE(SUM(CASE WHEN validation_status = 'Approved' THEN total_amount ELSE 0 END), 0) as approved_amount,
            COALESCE(SUM(CASE WHEN validation_status = 'Rejected' THEN total_amount ELSE 0 END), 0) as rejected_amount
        FROM merchandise_transactions
        WHERE station_id = ? 
          AND DATE(created_at) BETWEEN ? AND ?
    ");
    $stmt->execute([$station_id, $date_from, $date_to]);
    $transaction_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stats = array_merge($stats, $transaction_stats);
    
    // Calculate daily average
    $days = max(1, (strtotime($date_to) - strtotime($date_from)) / (60 * 60 * 24) + 1);
    $stats['daily_avg'] = $stats['total_transactions'] / $days;
    
    // Staff performance statistics
    $stmt = $pdo->prepare("
        SELECT 
            u.name as staff_name,
            u.id as staff_id,
            COUNT(mt.id) as transaction_count,
            COALESCE(SUM(mt.total_amount), 0) as total_amount,
            COUNT(CASE WHEN mt.validation_status = 'Approved' THEN 1 END) as approved_count,
            COUNT(CASE WHEN mt.validation_status = 'Rejected' THEN 1 END) as rejected_count,
            ROUND(
                (COUNT(CASE WHEN mt.validation_status = 'Approved' THEN 1 END) / NULLIF(COUNT(mt.id), 0)) * 100, 
                2
            ) as approval_rate
        FROM merchandise_transactions mt
        LEFT JOIN users u ON mt.staff_id = u.id
        WHERE mt.station_id = ? 
          AND DATE(mt.created_at) BETWEEN ? AND ?
        GROUP BY mt.staff_id, u.name, u.id
        ORDER BY transaction_count DESC
    ");
    $stmt->execute([$station_id, $date_from, $date_to]);
    $stats['staff_performance'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Error fetching merchandise oversight stats: " . $e->getMessage());
}

// Fetch daily transaction data for charts
$daily_data = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            DATE(created_at) as transaction_date,
            COUNT(*) as transaction_count,
            COALESCE(SUM(total_amount), 0) as total_amount,
            COUNT(CASE WHEN validation_status = 'Pending' THEN 1 END) as pending_count,
            COUNT(CASE WHEN validation_status = 'Approved' THEN 1 END) as approved_count,
            COUNT(CASE WHEN validation_status = 'Rejected' THEN 1 END) as rejected_count,
            COALESCE(SUM(CASE WHEN validation_status = 'Pending' THEN total_amount ELSE 0 END), 0) as pending_amount,
            COALESCE(SUM(CASE WHEN validation_status = 'Approved' THEN total_amount ELSE 0 END), 0) as approved_amount,
            COALESCE(SUM(CASE WHEN validation_status = 'Rejected' THEN total_amount ELSE 0 END), 0) as rejected_amount
        FROM merchandise_transactions
        WHERE station_id = ? 
          AND DATE(created_at) BETWEEN ? AND ?
        GROUP BY DATE(created_at)
        ORDER BY transaction_date ASC
    ");
    $stmt->execute([$station_id, $date_from, $date_to]);
    $daily_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching daily data: " . $e->getMessage());
}

// Fetch category-wise sales data
$category_data = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            ip.category,
            COUNT(DISTINCT mt.id) as transaction_count,
            COALESCE(SUM(mti.quantity), 0) as total_quantity,
            COALESCE(SUM(mti.subtotal), 0) as total_amount,
            COUNT(DISTINCT mti.product_id) as unique_products
        FROM merchandise_transactions mt
        LEFT JOIN merchandise_transaction_items mti ON mt.id = mti.transaction_id
        LEFT JOIN inventory_products ip ON mti.product_id = ip.id
        WHERE mt.station_id = ? 
          AND DATE(mt.created_at) BETWEEN ? AND ?
          AND mt.validation_status = 'Approved'
        GROUP BY ip.category
        ORDER BY total_amount DESC
    ");
    $stmt->execute([$station_id, $date_from, $date_to]);
    $category_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching category data: " . $e->getMessage());
}

// Fetch payment method breakdown
$payment_data = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            payment_method,
            COUNT(*) as transaction_count,
            COALESCE(SUM(total_amount), 0) as total_amount,
            COUNT(CASE WHEN validation_status = 'Approved' THEN 1 END) as approved_count,
            COALESCE(SUM(CASE WHEN validation_status = 'Approved' THEN total_amount ELSE 0 END), 0) as approved_amount
        FROM merchandise_transactions
        WHERE station_id = ? 
          AND DATE(created_at) BETWEEN ? AND ?
        GROUP BY payment_method
        ORDER BY total_amount DESC
    ");
    $stmt->execute([$station_id, $date_from, $date_to]);
    $payment_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching payment data: " . $e->getMessage());
}

include __DIR__ . '/../partials/header.php';
?>

<style>
.oversight-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.dashboard-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding: 20px;
    background: linear-gradient(135deg, #003d7a, #0056b3);
    color: white;
    border-radius: 12px;
}

.header-title h1 {
    margin: 0;
    font-size: 28px;
    font-weight: 700;
}

.header-title p {
    margin: 5px 0 0 0;
    opacity: 0.9;
}

.date-filter {
    display: flex;
    gap: 10px;
    align-items: center;
}

.date-filter input {
    padding: 8px 12px;
    border: 1px solid rgba(255,255,255,0.3);
    border-radius: 6px;
    background: rgba(255,255,255,0.1);
    color: white;
    font-size: 14px;
}

.date-filter input::placeholder {
    color: rgba(255,255,255,0.7);
}

.date-filter button {
    padding: 8px 16px;
    background: rgba(255,255,255,0.2);
    border: 1px solid rgba(255,255,255,0.3);
    border-radius: 6px;
    color: white;
    cursor: pointer;
    transition: all 0.3s ease;
}

.date-filter button:hover {
    background: rgba(255,255,255,0.3);
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border: 1px solid #e9ecef;
    transition: transform 0.3s ease;
}

.stat-card:hover {
    transform: translateY(-4px);
}

.stat-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.stat-title {
    font-size: 14px;
    font-weight: 600;
    color: #666;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
}

.icon-primary { background: linear-gradient(135deg, #003d7a, #0056b3); color: white; }
.icon-success { background: linear-gradient(135deg, #28a745, #20c997); color: white; }
.icon-warning { background: linear-gradient(135deg, #ffc107, #fd7e14); color: white; }
.icon-danger { background: linear-gradient(135deg, #dc3545, #e83e8c); color: white; }
.icon-info { background: linear-gradient(135deg, #17a2b8, #6f42c1); color: white; }

.stat-value {
    font-size: 32px;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 8px;
}

.stat-subtitle {
    font-size: 13px;
    color: #666;
}

.chart-container {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border: 1px solid #e9ecef;
    margin-bottom: 30px;
}

.chart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.chart-title {
    font-size: 18px;
    font-weight: 600;
    color: #2c3e50;
}

.chart-controls {
    display: flex;
    gap: 10px;
}

.chart-controls button {
    padding: 6px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    background: white;
    color: #666;
    cursor: pointer;
    font-size: 12px;
    transition: all 0.3s ease;
}

.chart-controls button:hover,
.chart-controls button.active {
    background: #003d7a;
    color: white;
    border-color: #003d7a;
}

.chart-body {
    height: 300px;
    position: relative;
}

.simple-chart {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: flex-end;
    gap: 2px;
    padding: 10px 0;
}

.chart-bar {
    flex: 1;
    background: linear-gradient(to top, #003d7a, #0056b3);
    border-radius: 4px 4px 0 0;
    position: relative;
    cursor: pointer;
    transition: all 0.3s ease;
}

.chart-bar:hover {
    opacity: 0.8;
}

.chart-bar.pending { background: linear-gradient(to top, #ffc107, #fd7e14); }
.chart-bar.approved { background: linear-gradient(to top, #28a745, #20c997); }
.chart-bar.rejected { background: linear-gradient(to top, #dc3545, #e83e8c); }

.chart-tooltip {
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(0,0,0,0.8);
    color: white;
    padding: 8px 12px;
    border-radius: 6px;
    font-size: 12px;
    white-space: nowrap;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.3s ease;
}

.chart-bar:hover .chart-tooltip {
    opacity: 1;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}

.data-table th,
.data-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #e9ecef;
}

.data-table th {
    background: #f8f9fa;
    font-weight: 600;
    color: #2c3e50;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.data-table tr:hover {
    background: #f8f9fa;
}

.badge {
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.badge-success { background: #d4edda; color: #155724; }
.badge-warning { background: #fff3cd; color: #856404; }
.badge-danger { background: #f8d7da; color: #721c24; }
.badge-info { background: #d1ecf1; color: #0c5460; }

.progress-bar {
    width: 100%;
    height: 8px;
    background: #e9ecef;
    border-radius: 4px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #28a745, #20c997);
    border-radius: 4px;
    transition: width 0.3s ease;
}

.two-column {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 30px;
}

@media (max-width: 1024px) {
    .two-column {
        grid-template-columns: 1fr;
    }
    
    .stats-grid {
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    }
}

@media (max-width: 768px) {
    .dashboard-header {
        flex-direction: column;
        gap: 20px;
        text-align: center;
    }
    
    .date-filter {
        flex-direction: column;
        width: 100%;
    }
    
    .date-filter input,
    .date-filter button {
        width: 100%;
    }
}
</style>

<div class="oversight-container">
    <!-- Dashboard Header -->
    <div class="dashboard-header">
        <div class="header-title">
            <h1><i class="fas fa-chart-line"></i> Merchandise Oversight Dashboard</h1>
            <p>Comprehensive analytics and variance monitoring for merchandise transactions</p>
        </div>
        <div class="date-filter">
            <input type="date" id="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
            <span>to</span>
            <input type="date" id="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
            <button onclick="updateDateRange()"><i class="fas fa-sync-alt"></i> Update</button>
        </div>
    </div>

    <?php if($msg): ?>
        <div class="alert alert-<?php echo strpos($msg, 'Error') !== false ? 'danger' : 'success'; ?>" style="margin-bottom: 20px;">
            <?php echo htmlspecialchars($msg); ?>
        </div>
    <?php endif; ?>

    <!-- Key Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-title">Total Transactions</div>
                <div class="stat-icon icon-primary">
                    <i class="fas fa-shopping-cart"></i>
                </div>
            </div>
            <div class="stat-value"><?php echo number_format($stats['total_transactions']); ?></div>
            <div class="stat-subtitle">Daily avg: <?php echo number_format($stats['daily_avg'], 1); ?></div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-title">Pending Validation</div>
                <div class="stat-icon icon-warning">
                    <i class="fas fa-clock"></i>
                </div>
            </div>
            <div class="stat-value"><?php echo number_format($stats['pending_transactions']); ?></div>
            <div class="stat-subtitle">¥<?php echo number_format($stats['pending_amount'], 2); ?></div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-title">Approved Transactions</div>
                <div class="stat-icon icon-success">
                    <i class="fas fa-check-circle"></i>
                </div>
            </div>
            <div class="stat-value"><?php echo number_format($stats['approved_transactions']); ?></div>
            <div class="stat-subtitle">¥<?php echo number_format($stats['approved_amount'], 2); ?></div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-title">Rejected Transactions</div>
                <div class="stat-icon icon-danger">
                    <i class="fas fa-times-circle"></i>
                </div>
            </div>
            <div class="stat-value"><?php echo number_format($stats['rejected_transactions']); ?></div>
            <div class="stat-subtitle">¥<?php echo number_format($stats['rejected_amount'], 2); ?></div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-title">Total Revenue</div>
                <div class="stat-icon icon-info">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
            </div>
            <div class="stat-value">¥<?php echo number_format($stats['total_amount'], 2); ?></div>
            <div class="stat-subtitle">From all transactions</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-title">Approval Rate</div>
                <div class="stat-icon icon-primary">
                    <i class="fas fa-percentage"></i>
                </div>
            </div>
            <div class="stat-value"><?php echo $stats['total_transactions'] > 0 ? number_format(($stats['approved_transactions'] / $stats['total_transactions']) * 100, 1) : '0.0'; ?>%</div>
            <div class="stat-subtitle">Success rate</div>
        </div>
    </div>

    <!-- Charts Section -->
    <div class="two-column">
        <!-- Daily Transaction Trend -->
        <div class="chart-container">
            <div class="chart-header">
                <div class="chart-title">
                    <i class="fas fa-chart-line"></i> Daily Transaction Trend
                </div>
                <div class="chart-controls">
                    <button class="active" onclick="switchChartView('transactions', this)">Transactions</button>
                    <button onclick="switchChartView('amount', this)">Amount</button>
                </div>
            </div>
            <div class="chart-body">
                <div id="dailyChart" class="simple-chart">
                    <?php if (!empty($daily_data)): ?>
                        <?php 
                        $max_value = max(array_column($daily_data, 'transaction_count'));
                        foreach ($daily_data as $day): 
                            $height = $max_value > 0 ? ($day['transaction_count'] / $max_value) * 100 : 0;
                        ?>
                        <div class="chart-bar" style="height: <?php echo $height; ?>%;" data-date="<?php echo $day['transaction_date']; ?>" data-count="<?php echo $day['transaction_count']; ?>" data-amount="<?php echo $day['total_amount']; ?>">
                            <div class="chart-tooltip">
                                <?php echo date('M j', strtotime($day['transaction_date'])); ?><br>
                                Transactions: <?php echo $day['transaction_count']; ?><br>
                                Amount: ¥<?php echo number_format($day['total_amount'], 2); ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 50px; color: #666;">
                            <i class="fas fa-chart-line" style="font-size: 48px; margin-bottom: 10px;"></i>
                            <p>No data available for selected period</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Category Performance -->
        <div class="chart-container">
            <div class="chart-header">
                <div class="chart-title">
                    <i class="fas fa-tags"></i> Category Performance
                </div>
            </div>
            <div class="chart-body">
                <?php if (!empty($category_data)): ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Transactions</th>
                                <th>Revenue</th>
                                <th>Products</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($category_data as $category): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($category['category']); ?></td>
                                <td><?php echo number_format($category['transaction_count']); ?></td>
                                <td>¥<?php echo number_format($category['total_amount'], 2); ?></td>
                                <td><?php echo number_format($category['unique_products']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div style="text-align: center; padding: 50px; color: #666;">
                        <i class="fas fa-tags" style="font-size: 48px; margin-bottom: 10px;"></i>
                        <p>No category data available</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Staff Performance Table -->
    <div class="chart-container">
        <div class="chart-header">
            <div class="chart-title">
                <i class="fas fa-users"></i> Staff Performance Analysis
            </div>
            <div class="chart-controls">
                <button onclick="exportStaffData()"><i class="fas fa-download"></i> Export</button>
            </div>
        </div>
        <div class="chart-body" style="height: auto;">
            <?php if (!empty($stats['staff_performance'])): ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Staff Name</th>
                            <th>Total Transactions</th>
                            <th>Total Revenue</th>
                            <th>Approved</th>
                            <th>Rejected</th>
                            <th>Approval Rate</th>
                            <th>Performance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats['staff_performance'] as $staff): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($staff['staff_name']); ?></td>
                            <td><?php echo number_format($staff['transaction_count']); ?></td>
                            <td>¥<?php echo number_format($staff['total_amount'], 2); ?></td>
                            <td><span class="badge badge-success"><?php echo number_format($staff['approved_count']); ?></span></td>
                            <td><span class="badge badge-danger"><?php echo number_format($staff['rejected_count']); ?></span></td>
                            <td><?php echo number_format($staff['approval_rate'], 1); ?>%</td>
                            <td>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?php echo $staff['approval_rate']; ?>%;"></div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div style="text-align: center; padding: 50px; color: #666;">
                    <i class="fas fa-users" style="font-size: 48px; margin-bottom: 10px;"></i>
                    <p>No staff performance data available</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Payment Method Analysis -->
    <div class="chart-container">
        <div class="chart-header">
            <div class="chart-title">
                <i class="fas fa-credit-card"></i> Payment Method Analysis
            </div>
        </div>
        <div class="chart-body">
            <?php if (!empty($payment_data)): ?>
                <div class="simple-chart" style="height: 200px;">
                    <?php 
                    $max_amount = max(array_column($payment_data, 'total_amount'));
                    foreach ($payment_data as $payment): 
                        $height = $max_amount > 0 ? ($payment['total_amount'] / $max_amount) * 100 : 0;
                    ?>
                    <div class="chart-bar" style="height: <?php echo $height; ?>%;" data-method="<?php echo $payment['payment_method']; ?>" data-amount="<?php echo $payment['total_amount']; ?>" data-count="<?php echo $payment['transaction_count']; ?>">
                        <div class="chart-tooltip">
                            <?php echo htmlspecialchars($payment['payment_method']); ?><br>
                            Transactions: <?php echo $payment['transaction_count']; ?><br>
                            Amount: ¥<?php echo number_format($payment['total_amount'], 2); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Payment Method</th>
                            <th>Transactions</th>
                            <th>Total Amount</th>
                            <th>Approved Amount</th>
                            <th>Success Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payment_data as $payment): 
                            $success_rate = $payment['transaction_count'] > 0 ? ($payment['approved_count'] / $payment['transaction_count']) * 100 : 0;
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($payment['payment_method']); ?></td>
                            <td><?php echo number_format($payment['transaction_count']); ?></td>
                            <td>¥<?php echo number_format($payment['total_amount'], 2); ?></td>
                            <td>¥<?php echo number_format($payment['approved_amount'], 2); ?></td>
                            <td>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?php echo $success_rate; ?>%;"></div>
                                </div>
                                <small><?php echo number_format($success_rate, 1); ?>%</small>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div style="text-align: center; padding: 50px; color: #666;">
                    <i class="fas fa-credit-card" style="font-size: 48px; margin-bottom: 10px;"></i>
                    <p>No payment method data available</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function updateDateRange() {
    const dateFrom = document.getElementById('date_from').value;
    const dateTo = document.getElementById('date_to').value;
    
    if (!dateFrom || !dateTo) {
        showNotification('Please select both date from and date to', 'warning');
        return;
    }
    
    if (new Date(dateFrom) > new Date(dateTo)) {
        showNotification('Date from cannot be later than date to', 'warning');
        return;
    }
    
    window.location.href = `?date_from=${dateFrom}&date_to=${dateTo}`;
}

function switchChartView(view, button) {
    // Update button states
    const buttons = button.parentElement.querySelectorAll('button');
    buttons.forEach(btn => btn.classList.remove('active'));
    button.classList.add('active');
    
    // Update chart data
    updateChartData(view);
}

function updateChartData(view) {
    const chart = document.getElementById('dailyChart');
    const bars = chart.querySelectorAll('.chart-bar');
    
    bars.forEach(bar => {
        const count = parseFloat(bar.dataset.count);
        const amount = parseFloat(bar.dataset.amount);
        
        if (view === 'amount') {
            // Use amount data for height calculation
            const maxAmount = Math.max(...Array.from(bars).map(b => parseFloat(b.dataset.amount)));
            const height = maxAmount > 0 ? (amount / maxAmount) * 100 : 0;
            bar.style.height = height + '%';
            
            // Update tooltip
            const tooltip = bar.querySelector('.chart-tooltip');
            const date = bar.dataset.date;
            tooltip.innerHTML = `
                ${new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}<br>
                Amount: ¥${amount.toFixed(2)}<br>
                Transactions: ${count}
            `;
        } else {
            // Use transaction count data for height calculation
            const maxCount = Math.max(...Array.from(bars).map(b => parseFloat(b.dataset.count)));
            const height = maxCount > 0 ? (count / maxCount) * 100 : 0;
            bar.style.height = height + '%';
            
            // Update tooltip
            const tooltip = bar.querySelector('.chart-tooltip');
            const date = bar.dataset.date;
            tooltip.innerHTML = `
                ${new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}<br>
                Transactions: ${count}<br>
                Amount: ¥${amount.toFixed(2)}
            `;
        }
    });
}

function exportStaffData() {
    const dateFrom = document.getElementById('date_from').value;
    const dateTo = document.getElementById('date_to').value;
    
    // Create CSV data
    let csv = 'Staff Name,Total Transactions,Total Revenue,Approved,Rejected,Approval Rate\n';
    
    <?php foreach ($stats['staff_performance'] as $staff): ?>
    csv += `<?php echo addslashes($staff['staff_name']); ?>,<?php echo $staff['transaction_count']; ?>,<?php echo $staff['total_amount']; ?>,<?php echo $staff['approved_count']; ?>,<?php echo $staff['rejected_count']; ?>,<?php echo $staff['approval_rate']; ?>%\n`;
    <?php endforeach; ?>
    
    // Create download link
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `staff_performance_${dateFrom}_to_${dateTo}.csv`;
    a.click();
    window.URL.revokeObjectURL(url);
    
    showNotification('Staff performance data exported successfully', 'success');
}

function showNotification(message, type) {
    const notification = document.createElement('div');
    notification.className = `alert alert-${type === 'error' ? 'danger' : type === 'warning' ? 'warning' : 'success'}`;
    notification.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
    notification.innerHTML = `
        <i class="fas fa-${type === 'error' ? 'exclamation-triangle' : type === 'warning' ? 'exclamation-triangle' : 'check-circle'}"></i>
        ${message}
    `;
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.remove();
    }, 5000);
}

// Auto-refresh every 5 minutes
setInterval(() => {
    const dateFrom = document.getElementById('date_from').value;
    const dateTo = document.getElementById('date_to').value;
    window.location.href = `?date_from=${dateFrom}&date_to=${dateTo}`;
}, 5 * 60 * 1000);
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
