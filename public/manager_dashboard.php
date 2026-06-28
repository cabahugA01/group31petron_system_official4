<?php
/**
 * Manager Dashboard - Complete Rebuild
 * Comprehensive operational dashboard with real-time insights
 */
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'manager_dashboard';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();
$user_id = (int)($me['id'] ?? 0);

// Role check
if (!in_array($role, ['manager', 'admin', 'superadmin', 'developer'])) {
    header('Location: dashboard.php');
    exit;
}

if (!$station_id && $role === 'manager') {
    render_no_station_page('manager_dashboard.php');
}

$display_name = htmlspecialchars($me['full_name'] ?? trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: ($me['name'] ?? 'Manager'));
$station_name = htmlspecialchars($me['station_name'] ?? 'Station #' . $station_id);

// Date filter
$date_filter = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{1,2}$/', $date_filter)) {
    $date_filter = date('Y-m-d');
}

$flash_success = $_SESSION['success'] ?? null; unset($_SESSION['success']);
$flash_error = $_SESSION['error'] ?? null; unset($_SESSION['error']);

// ═══════════════════════════════════════════════════════════════════════════
// METRICS COLLECTION
// ═══════════════════════════════════════════════════════════════════════════

// 1. Today's Transactions Count
$fuel_tx = $pdo->prepare("SELECT COUNT(*) FROM fuel_transactions WHERE station_id = ? AND DATE(transaction_date) = ?");
$fuel_tx->execute([$station_id, $date_filter]);
$fuel_count = (int)$fuel_tx->fetchColumn();

$merch_tx = $pdo->prepare("SELECT COUNT(*) FROM merchandise_transactions WHERE station_id = ? AND DATE(COALESCE(transaction_date, created_at)) = ?");
$merch_tx->execute([$station_id, $date_filter]);
$merch_count = (int)$merch_tx->fetchColumn();

$service_tx = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id = ? AND DATE(created_at) = ?");
$service_tx->execute([$station_id, $date_filter]);
$service_count = (int)$service_tx->fetchColumn();

$total_transactions = $fuel_count + $merch_count + $service_count;

// 2. Today's Revenue
$fuel_rev = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM fuel_transactions WHERE station_id = ? AND DATE(transaction_date) = ?");
$fuel_rev->execute([$station_id, $date_filter]);
$fuel_revenue = (float)$fuel_rev->fetchColumn();

$merch_rev = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM merchandise_transactions WHERE station_id = ? AND DATE(COALESCE(transaction_date, created_at)) = ?");
$merch_rev->execute([$station_id, $date_filter]);
$merch_revenue = (float)$merch_rev->fetchColumn();

$service_rev = $pdo->prepare("SELECT COALESCE(SUM(total_cost), 0) FROM job_orders WHERE station_id = ? AND DATE(created_at) = ? AND status IN ('Completed', 'Released', 'Verified')");
$service_rev->execute([$station_id, $date_filter]);
$service_revenue = (float)$service_rev->fetchColumn();

$total_revenue = $fuel_revenue + $merch_revenue + $service_revenue;

// 3. Fuel Sold Today (Liters)
$fuel_liters = $pdo->prepare("SELECT COALESCE(SUM(liters_sold), 0) FROM fuel_transactions WHERE station_id = ? AND DATE(transaction_date) = ?");
$fuel_liters->execute([$station_id, $date_filter]);
$total_fuel_liters = (float)$fuel_liters->fetchColumn();

// 4. Pending Approvals
$stock_requests = $pdo->prepare("SELECT COUNT(*) FROM stock_requests WHERE station_id = ? AND status = 'Pending'");
$stock_requests->execute([$station_id]);
$pending_stock_requests = (int)$stock_requests->fetchColumn();

// Customer registration requests feature not yet implemented
$pending_customer_requests = 0;

$price_requests = $pdo->prepare("SELECT COUNT(*) FROM pending_price_approvals WHERE station_id = ? AND status = 'Pending'");
$price_requests->execute([$station_id]);
$pending_price_requests = (int)$price_requests->fetchColumn();

$total_pending_approvals = $pending_stock_requests + $pending_customer_requests + $pending_price_requests;

// 5. Inventory Alerts
$low_fuel = $pdo->prepare("SELECT COUNT(*) FROM fuel_inventory WHERE station_id = ? AND current_level <= reorder_level");
$low_fuel->execute([$station_id]);
$low_fuel_count = (int)$low_fuel->fetchColumn();

$low_merch = $pdo->prepare("SELECT COUNT(*) FROM station_inventory WHERE station_id = ? AND stock_level <= reorder_level AND status = 'active'");
$low_merch->execute([$station_id]);
$low_merch_count = (int)$low_merch->fetchColumn();

$out_stock = $pdo->prepare("SELECT COUNT(*) FROM station_inventory WHERE station_id = ? AND stock_level <= 0 AND status = 'active'");
$out_stock->execute([$station_id]);
$out_stock_count = (int)$out_stock->fetchColumn();

$total_inventory_alerts = $low_fuel_count + $low_merch_count + $out_stock_count;

// 6. Pending Deliveries
$pending_deliveries = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE station_id = ? AND admin_finalized = 1 AND delivery_validated = 0 AND stock_in_done = 0");
$pending_deliveries->execute([$station_id]);
$pending_deliveries_count = (int)$pending_deliveries->fetchColumn();

// 7. Active Services (Job Orders)
$active_services = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id = ? AND status IN ('Pending', 'In Progress', 'Reviewed')");
$active_services->execute([$station_id]);
$active_services_count = (int)$active_services->fetchColumn();

// 8. Active Staff by Shift
$staff_shift1 = $pdo->prepare("SELECT COUNT(*) FROM labor_sessions WHERE station_id = ? AND shift_name LIKE '%Shift 1%' AND end_time IS NULL");
$staff_shift1->execute([$station_id]);
$staff_shift1_count = (int)$staff_shift1->fetchColumn();

$staff_shift2 = $pdo->prepare("SELECT COUNT(*) FROM labor_sessions WHERE station_id = ? AND shift_name LIKE '%Shift 2%' AND end_time IS NULL");
$staff_shift2->execute([$station_id]);
$staff_shift2_count = (int)$staff_shift2->fetchColumn();

// ═══════════════════════════════════════════════════════════════════════════
// CHART DATA COLLECTION
// ═══════════════════════════════════════════════════════════════════════════

// Chart 1: Hourly Sales Trend
$hourly_sales = [];
for ($h = 6; $h <= 23; $h++) {
    $hour_start = sprintf('%02d:00:00', $h);
    $hour_end = sprintf('%02d:59:59', $h);
    
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_amount), 0) FROM (
            SELECT total_amount FROM fuel_transactions 
            WHERE station_id = ? AND DATE(transaction_date) = ? AND TIME(transaction_date) BETWEEN ? AND ?
            UNION ALL
            SELECT total_amount FROM merchandise_transactions 
            WHERE station_id = ? AND DATE(COALESCE(transaction_date, created_at)) = ? AND TIME(COALESCE(transaction_date, created_at)) BETWEEN ? AND ?
        ) AS combined
    ");
    $stmt->execute([$station_id, $date_filter, $hour_start, $hour_end, $station_id, $date_filter, $hour_start, $hour_end]);
    $hourly_sales[] = (float)$stmt->fetchColumn();
}

// Chart 2: Fuel Sales by Product
$fuel_products = [];
try {
    $stmt = $pdo->prepare("SELECT DISTINCT fuel_type FROM fuel_inventory WHERE station_id = ? AND COALESCE(fuel_type, '') <> '' ORDER BY fuel_type");
    $stmt->execute([$station_id]);
    $fuel_products = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'fuel_type');
} catch (Exception $e) {
    $fuel_products = [];
}
$fuel_sales_data = [];
foreach ($fuel_products as $product) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(liters_sold), 0) FROM fuel_transactions WHERE station_id = ? AND DATE(transaction_date) = ? AND fuel_type LIKE ?");
    $stmt->execute([$station_id, $date_filter, "%$product%"]);
    $fuel_sales_data[] = (float)$stmt->fetchColumn();
}

// Chart 3: Merchandise Sales by Category
$merch_categories = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT ip.category
        FROM station_inventory si
        INNER JOIN inventory_products ip ON ip.id = si.product_id
        WHERE si.station_id = ?
          AND LOWER(si.status) = 'active'
          AND LOWER(COALESCE(ip.status, 'active')) <> 'inactive'
          AND LOWER(COALESCE(ip.category, '')) <> 'fuel'
          AND COALESCE(ip.category, '') <> ''
        ORDER BY ip.category
        LIMIT 5
    ");
    $stmt->execute([$station_id]);
    $merch_categories = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'category');
} catch (Exception $e) {
    $merch_categories = [];
}
$merch_sales_data = [];
foreach ($merch_categories as $category) {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(mti.subtotal), 0) 
        FROM merchandise_transaction_items mti
        JOIN merchandise_transactions mt ON mti.transaction_id = mt.id
        WHERE mt.station_id = ? AND DATE(COALESCE(mt.transaction_date, mt.created_at)) = ? AND mti.category LIKE ?
    ");
    $stmt->execute([$station_id, $date_filter, "%$category%"]);
    $merch_sales_data[] = (float)$stmt->fetchColumn();
}

// Chart 4: Weekly Revenue Trend
$weekly_revenue = [];
$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
for ($i = 6; $i >= 0; $i--) {
    $check_date = date('Y-m-d', strtotime("-$i days"));
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_amount), 0) FROM (
            SELECT total_amount FROM fuel_transactions WHERE station_id = ? AND DATE(transaction_date) = ?
            UNION ALL
            SELECT total_amount FROM merchandise_transactions WHERE station_id = ? AND DATE(COALESCE(transaction_date, created_at)) = ?
        ) AS combined
    ");
    $stmt->execute([$station_id, $check_date, $station_id, $check_date]);
    $weekly_revenue[] = (float)$stmt->fetchColumn();
}

include __DIR__ . '/../partials/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manager Dashboard - <?= $station_name ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8fafc;
            color: #1e293b;
            line-height: 1.6;
        }
        
        /* Dashboard Container */
        .dashboard-container {
            max-width: 1920px;
            margin: 0 auto;
            padding: 24px;
        }
        
        /* Dashboard Header Container - Match Staff Dashboard */
        .dashboard-header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
            background: #ffffff;
            padding: 20px 24px;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            border: 1px solid #e2e8f0;
        }
        .welcome-meta h2 {
            margin: 0;
            font-size: 24px;
            font-weight: 800;
            color: #002F70;
        }
        .welcome-meta p {
            margin: 5px 0 0;
            color: #64748b;
            font-size: 14px;
            font-weight: 500;
        }
        .header-filters {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .date-filter-form {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .date-filter-form input[type="date"] {
            padding: 8px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 13px;
            color: #334155;
            outline: none;
            background: #ffffff;
        }
        .btn-filter-submit {
            background: #002F70 !important;
            color: #ffffff !important;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-filter-submit:hover {
            background: #001f4d !important;
        }
        
        /* Summary Cards Grid - Match Staff Dashboard */
        .summary-cards-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        @media (max-width: 1024px) {
            .summary-cards-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        @media (max-width: 600px) {
            .summary-cards-grid {
                grid-template-columns: 1fr;
            }
        }
        .summary-metric-card {
            background: #ffffff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            border: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s ease;
        }
        .summary-metric-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 20px -2px rgba(0, 0, 0, 0.08);
        }
        .metric-details h4 {
            margin: 0;
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .metric-details .metric-value {
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
            margin: 6px 0 0;
            line-height: 1.2;
        }
        .metric-icon-box {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        
        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 32px;
        }
        
        .chart-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }
        
        .chart-card-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .chart-card-header h3 {
            font-size: 15px;
            font-weight: 700;
            color: #1e293b;
        }
        
        .chart-container {
            position: relative;
            height: 280px;
        }
        
        /* Action Panels */
        .action-panels-container {
            background: white;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            margin-bottom: 32px;
        }
        
        .action-tabs {
            display: flex;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            overflow-x: auto;
        }
        
        .action-tab {
            padding: 16px 24px;
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: all 0.2s;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .action-tab:hover {
            color: #002F70;
            background: rgba(0, 47, 112, 0.02);
        }
        
        .action-tab.active {
            color: #002F70;
            border-bottom-color: #002F70;
            background: white;
        }
        
        .action-tab-badge {
            background: #fee2e2;
            color: #dc2626;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 10px;
            min-width: 18px;
            text-align: center;
        }
        
        .action-panel-content {
            padding: 24px;
        }
        
        .action-panel-pane {
            display: none;
        }
        
        .action-panel-pane.active {
            display: block;
        }
        
        /* Tables */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        
        .data-table thead th {
            background: #f8fafc;
            color: #64748b;
            font-weight: 700;
            padding: 12px 16px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .data-table tbody td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
        }
        
        .data-table tbody tr:hover {
            background: #f8fafc;
        }
        
        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.2s;
            text-decoration: none;
        }
        
        .btn-primary {
            background: #002F70;
            color: white;
            border-color: #002F70;
        }
        
        .btn-primary:hover {
            background: #00264D;
        }
        
        .btn-success {
            background: #16a34a;
            color: white;
        }
        
        .btn-danger {
            background: #dc2626;
            color: white;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 11px;
        }
        
        /* Badges */
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
        }
        
        .badge-success {
            background: #dcfce7;
            color: #15803d;
        }
        
        .badge-warning {
            background: #fef9c3;
            color: #a16207;
        }
        
        .badge-danger {
            background: #fee2e2;
            color: #b91c1c;
        }
        
        .badge-info {
            background: #dbeafe;
            color: #1e40af;
        }
        
        /* Responsive */
        @media (max-width: 1400px) {
            .summary-cards-grid {
                grid-template-columns: repeat(4, 1fr);
            }
            .charts-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 1024px) {
            .summary-cards-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .charts-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .summary-cards-grid {
                grid-template-columns: 1fr;
            }
            .charts-grid {
                grid-template-columns: 1fr;
            }
            .dashboard-container {
                padding: 16px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        
        <!-- Welcome / Filter Banner -->
        <div class="dashboard-header-container">
            <div class="welcome-meta">
                <h2>Welcome, <?= $display_name ?>!</h2>
                <p><i class="fas fa-tachometer-alt"></i> Manager Dashboard</p>
            </div>
            <div class="header-filters">
                <form method="GET" class="date-filter-form">
                    <input type="date" name="date" value="<?= htmlspecialchars($date_filter) ?>" required>
                    <button type="submit" class="btn-filter-submit"><i class="fas fa-filter"></i> Filter</button>
                    <?php if ($date_filter !== date('Y-m-d')): ?>
                        <a href="manager_dashboard.php" class="btn-filter-submit" style="background:#64748b !important; text-decoration:none; display:inline-flex; align-items:center; height:33px; line-height:33px; padding:0 12px; box-sizing:border-box;"><i class="fas fa-undo"></i> Reset</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        
        <!-- 8 Summary Cards Grid -->
        <div class="summary-cards-grid">
            <!-- Card 1: Today's Transactions -->
            <div class="summary-metric-card" style="border-left: 4px solid #002F70;">
                <div class="metric-details">
                    <h4>Today's Transactions</h4>
                    <div class="metric-value"><?= number_format($total_transactions) ?></div>
                </div>
                <div class="metric-icon-box" style="background: #eff6ff; color: #002F70;">
                    <i class="fas fa-exchange-alt"></i>
                </div>
            </div>
            
            <!-- Card 2: Today's Revenue -->
            <div class="summary-metric-card" style="border-left: 4px solid #16a34a;">
                <div class="metric-details">
                    <h4>Today's Revenue</h4>
                    <div class="metric-value">₱<?= number_format($total_revenue, 2) ?></div>
                </div>
                <div class="metric-icon-box" style="background: #f0fdf4; color: #16a34a;">
                    <i class="fas fa-peso-sign"></i>
                </div>
            </div>
            
            <!-- Card 3: Fuel Sold Today -->
            <div class="summary-metric-card" style="border-left: 4px solid #f59e0b;">
                <div class="metric-details">
                    <h4>Fuel Sold Today</h4>
                    <div class="metric-value"><?= number_format($total_fuel_liters, 2) ?> L</div>
                </div>
                <div class="metric-icon-box" style="background: #ffedd5; color: #c2410c;">
                    <i class="fas fa-gas-pump"></i>
                </div>
            </div>
            
            <!-- Card 4: Pending Approvals -->
            <div class="summary-metric-card" style="border-left: 4px solid #dc2626;">
                <div class="metric-details">
                    <h4>Pending Approvals</h4>
                    <div class="metric-value"><?= number_format($total_pending_approvals) ?></div>
                </div>
                <div class="metric-icon-box" style="background: #fef2f2; color: #dc2626;">
                    <i class="fas fa-clipboard-check"></i>
                </div>
            </div>
            
            <!-- Card 5: Inventory Alerts -->
            <div class="summary-metric-card" style="border-left: 4px solid #7c3aed;">
                <div class="metric-details">
                    <h4>Inventory Alerts</h4>
                    <div class="metric-value"><?= number_format($total_inventory_alerts) ?></div>
                </div>
                <div class="metric-icon-box" style="background: #f3e8ff; color: #7c3aed;">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
            </div>
            
            <!-- Card 6: Pending Deliveries -->
            <div class="summary-metric-card" style="border-left: 4px solid #0891b2;">
                <div class="metric-details">
                    <h4>Pending Deliveries</h4>
                    <div class="metric-value"><?= number_format($pending_deliveries_count) ?></div>
                </div>
                <div class="metric-icon-box" style="background: #cffafe; color: #0e7490;">
                    <i class="fas fa-truck"></i>
                </div>
            </div>
            
            <!-- Card 7: Active Services -->
            <div class="summary-metric-card" style="border-left: 4px solid #eab308;">
                <div class="metric-details">
                    <h4>Active Services</h4>
                    <div class="metric-value"><?= number_format($active_services_count) ?></div>
                </div>
                <div class="metric-icon-box" style="background: #fef9c3; color: #eab308;">
                    <i class="fas fa-tools"></i>
                </div>
            </div>
            
            <!-- Card 8: Active Staff -->
            <div class="summary-metric-card" style="border-left: 4px solid #002F70;">
                <div class="metric-details">
                    <h4>Active Staff</h4>
                    <div class="metric-value"><?= $staff_shift1_count + $staff_shift2_count ?></div>
                </div>
                <div class="metric-icon-box" style="background: #eff6ff; color: #002F70;">
                    <i class="fas fa-users"></i>
                </div>
            </div>
        </div>
        
        <!-- Charts Grid -->
        <div class="charts-grid">
            <!-- Chart 1: Revenue Breakdown (Donut) -->
            <div class="chart-card">
                <div class="chart-card-header">
                    <i class="fas fa-chart-pie"></i>
                    <h3>Revenue Breakdown</h3>
                </div>
                <div class="chart-container">
                    <canvas id="revenueBreakdownChart"></canvas>
                </div>
            </div>
            
            <!-- Chart 2: Hourly Sales Trend (Line) -->
            <div class="chart-card">
                <div class="chart-card-header">
                    <i class="fas fa-chart-line"></i>
                    <h3>Hourly Sales Trend</h3>
                </div>
                <div class="chart-container">
                    <canvas id="hourlySalesChart"></canvas>
                </div>
            </div>
            
            <!-- Chart 3: Fuel Sales by Product (Bar) -->
            <div class="chart-card">
                <div class="chart-card-header">
                    <i class="fas fa-gas-pump"></i>
                    <h3>Fuel Sales by Product</h3>
                </div>
                <div class="chart-container">
                    <canvas id="fuelSalesChart"></canvas>
                </div>
            </div>
            
            <!-- Chart 4: Merchandise Sales by Category (Bar) -->
            <div class="chart-card">
                <div class="chart-card-header">
                    <i class="fas fa-shopping-basket"></i>
                    <h3>Merchandise Sales by Category</h3>
                </div>
                <div class="chart-container">
                    <canvas id="merchSalesChart"></canvas>
                </div>
            </div>
            
            <!-- Chart 5: Weekly Revenue Trend (Line) -->
            <div class="chart-card">
                <div class="chart-card-header">
                    <i class="fas fa-calendar-week"></i>
                    <h3>Weekly Revenue Trend</h3>
                </div>
                <div class="chart-container">
                    <canvas id="weeklyRevenueChart"></canvas>
                </div>
            </div>
            
            <!-- Chart 6: Inventory Status (Bar) -->
            <div class="chart-card">
                <div class="chart-card-header">
                    <i class="fas fa-boxes"></i>
                    <h3>Inventory Status</h3>
                </div>
                <div class="chart-container">
                    <canvas id="inventoryStatusChart"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Action Panels -->
        <div class="action-panels-container">
            <div class="action-tabs">
                <div class="action-tab active" onclick="switchPanel('stock-requests')">
                    <i class="fas fa-box"></i>
                    <span>Stock Requests</span>
                    <?php if ($pending_stock_requests > 0): ?>
                    <span class="action-tab-badge"><?= $pending_stock_requests ?></span>
                    <?php endif; ?>
                </div>
                <div class="action-tab" onclick="switchPanel('customer-reg')">
                    <i class="fas fa-user-plus"></i>
                    <span>Customer Registration</span>
                    <?php if ($pending_customer_requests > 0): ?>
                    <span class="action-tab-badge"><?= $pending_customer_requests ?></span>
                    <?php endif; ?>
                </div>
                <div class="action-tab" onclick="switchPanel('deliveries')">
                    <i class="fas fa-truck"></i>
                    <span>Deliveries</span>
                    <?php if ($pending_deliveries_count > 0): ?>
                    <span class="action-tab-badge"><?= $pending_deliveries_count ?></span>
                    <?php endif; ?>
                </div>
                <div class="action-tab" onclick="switchPanel('pricing')">
                    <i class="fas fa-tag"></i>
                    <span>Pricing</span>
                </div>
                <div class="action-tab" onclick="switchPanel('transactions')">
                    <i class="fas fa-receipt"></i>
                    <span>Recent Transactions</span>
                </div>
                <div class="action-tab" onclick="switchPanel('inventory')">
                    <i class="fas fa-warehouse"></i>
                    <span>Low Inventory</span>
                </div>
                <div class="action-tab" onclick="switchPanel('services')">
                    <i class="fas fa-tools"></i>
                    <span>Service Queue</span>
                </div>
            </div>
            
            <div class="action-panel-content">
                <!-- Stock Requests Panel -->
                <div id="panel-stock-requests" class="action-panel-pane active">
                    <h4 style="margin-bottom: 16px; color: #1e293b;">Pending Stock Requests</h4>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Request No</th>
                                <th>Type</th>
                                <th>Requested By</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 40px; color: #64748b;">
                                    <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 12px; display: block; opacity: 0.3;"></i>
                                    <?= $pending_stock_requests > 0 ? "Loading stock requests..." : "No pending stock requests" ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- Other panels... -->
                <div id="panel-customer-reg" class="action-panel-pane">
                    <h4 style="margin-bottom: 16px; color: #1e293b;">Pending Customer Registrations</h4>
                    <p style="text-align: center; padding: 40px; color: #64748b;">Customer registration panel coming soon...</p>
                </div>
                
                <div id="panel-deliveries" class="action-panel-pane">
                    <h4 style="margin-bottom: 16px; color: #1e293b;">Pending Deliveries</h4>
                    <p style="text-align: center; padding: 40px; color: #64748b;">Deliveries panel coming soon...</p>
                </div>
                
                <div id="panel-pricing" class="action-panel-pane">
                    <h4 style="margin-bottom: 16px; color: #1e293b;">Price Update Summary</h4>
                    <p style="text-align: center; padding: 40px; color: #64748b;">Pricing panel coming soon...</p>
                </div>
                
                <div id="panel-transactions" class="action-panel-pane">
                    <h4 style="margin-bottom: 16px; color: #1e293b;">Recent Transactions</h4>
                    <p style="text-align: center; padding: 40px; color: #64748b;">Transactions panel coming soon...</p>
                </div>
                
                <div id="panel-inventory" class="action-panel-pane">
                    <h4 style="margin-bottom: 16px; color: #1e293b;">Low Inventory Items</h4>
                    <p style="text-align: center; padding: 40px; color: #64748b;">Inventory panel coming soon...</p>
                </div>
                
                <div id="panel-services" class="action-panel-pane">
                    <h4 style="margin-bottom: 16px; color: #1e293b;">Service Queue</h4>
                    <p style="text-align: center; padding: 40px; color: #64748b;">Service queue panel coming soon...</p>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Panel Switching Function
        function switchPanel(panelId) {
            // Hide all panels
            document.querySelectorAll('.action-panel-pane').forEach(panel => {
                panel.classList.remove('active');
            });
            
            // Remove active from all tabs
            document.querySelectorAll('.action-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected panel
            document.getElementById('panel-' + panelId).classList.add('active');
            
            // Activate clicked tab
            event.target.closest('.action-tab').classList.add('active');
        }
        
        // Chart.js Configuration
        Chart.defaults.font.family = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
        Chart.defaults.color = '#64748b';
        
        // Chart 1: Revenue Breakdown (Donut)
        new Chart(document.getElementById('revenueBreakdownChart'), {
            type: 'doughnut',
            data: {
                labels: ['Fuel', 'Merchandise', 'Service'],
                datasets: [{
                    data: [<?= $fuel_revenue ?>, <?= $merch_revenue ?>, <?= $service_revenue ?>],
                    backgroundColor: ['#dc2626', '#16a34a', '#3b82f6'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            font: { size: 12, weight: '600' }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.label + ': ₱' + context.parsed.toLocaleString('en-PH', {minimumFractionDigits: 2});
                            }
                        }
                    }
                }
            }
        });
        
        // Chart 2: Hourly Sales Trend (Line)
        new Chart(document.getElementById('hourlySalesChart'), {
            type: 'line',
            data: {
                labels: ['6AM', '7AM', '8AM', '9AM', '10AM', '11AM', '12PM', '1PM', '2PM', '3PM', '4PM', '5PM', '6PM', '7PM', '8PM', '9PM', '10PM', '11PM'],
                datasets: [{
                    label: 'Revenue',
                    data: <?= json_encode($hourly_sales) ?>,
                    borderColor: '#002F70',
                    backgroundColor: 'rgba(0, 47, 112, 0.1)',
                    fill: true,
                    tension: 0.4,
                    pointRadius: 3,
                    pointHoverRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return '₱' + context.parsed.y.toLocaleString('en-PH', {minimumFractionDigits: 2});
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₱' + value.toLocaleString('en-PH');
                            }
                        }
                    }
                }
            }
        });

        
        // Chart 3: Fuel Sales by Product (Bar)
        new Chart(document.getElementById('fuelSalesChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($fuel_products) ?>,
                datasets: [{
                    label: 'Liters Sold',
                    data: <?= json_encode($fuel_sales_data) ?>,
                    backgroundColor: '#ea580c',
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.parsed.y.toLocaleString('en-PH', {minimumFractionDigits: 2}) + ' L';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return value.toLocaleString('en-PH') + ' L';
                            }
                        }
                    }
                }
            }
        });
        
        // Chart 4: Merchandise Sales by Category (Bar)
        new Chart(document.getElementById('merchSalesChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($merch_categories) ?>,
                datasets: [{
                    label: 'Sales Amount',
                    data: <?= json_encode($merch_sales_data) ?>,
                    backgroundColor: '#16a34a',
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return '₱' + context.parsed.y.toLocaleString('en-PH', {minimumFractionDigits: 2});
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₱' + value.toLocaleString('en-PH');
                            }
                        }
                    }
                }
            }
        });
        
        // Chart 5: Weekly Revenue Trend (Line)
        new Chart(document.getElementById('weeklyRevenueChart'), {
            type: 'line',
            data: {
                labels: <?= json_encode($days) ?>,
                datasets: [{
                    label: 'Revenue',
                    data: <?= json_encode($weekly_revenue) ?>,
                    borderColor: '#7c3aed',
                    backgroundColor: 'rgba(124, 58, 237, 0.1)',
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return '₱' + context.parsed.y.toLocaleString('en-PH', {minimumFractionDigits: 2});
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₱' + value.toLocaleString('en-PH');
                            }
                        }
                    }
                }
            }
        });
        
        // Chart 6: Inventory Status (Bar)
        <?php
        // Get inventory status data for Chart 6
        $inventory_labels = [];
        $inventory_current = [];
        $inventory_capacity = [];
        $inventory_colors = [];
        
        // Fuel inventory
        $fuel_inv = $pdo->prepare("SELECT fuel_type, current_level, capacity FROM fuel_inventory WHERE station_id = ? ORDER BY fuel_type");
        $fuel_inv->execute([$station_id]);
        while ($row = $fuel_inv->fetch(PDO::FETCH_ASSOC)) {
            $inventory_labels[] = $row['fuel_type'];
            $inventory_current[] = (float)$row['current_level'];
            $inventory_capacity[] = (float)$row['capacity'];
            
            $fill_percent = $row['capacity'] > 0 ? ($row['current_level'] / $row['capacity']) * 100 : 0;
            if ($fill_percent >= 50) {
                $inventory_colors[] = '#22c55e'; // Green - Normal
            } elseif ($fill_percent >= 25) {
                $inventory_colors[] = '#f59e0b'; // Orange - Low
            } else {
                $inventory_colors[] = '#dc2626'; // Red - Critical
            }
        }
        ?>
        new Chart(document.getElementById('inventoryStatusChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($inventory_labels) ?>,
                datasets: [{
                    label: 'Current Stock',
                    data: <?= json_encode($inventory_current) ?>,
                    backgroundColor: <?= json_encode($inventory_colors) ?>,
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const capacity = <?= json_encode($inventory_capacity) ?>[context.dataIndex];
                                const current = context.parsed.y;
                                const percent = ((current / capacity) * 100).toFixed(1);
                                return [
                                    'Current: ' + current.toLocaleString('en-PH', {minimumFractionDigits: 2}) + ' L',
                                    'Capacity: ' + capacity.toLocaleString('en-PH', {minimumFractionDigits: 2}) + ' L',
                                    'Fill: ' + percent + '%'
                                ];
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return value.toLocaleString('en-PH') + ' L';
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>
<?php include __DIR__ . '/../partials/footer.php'; ?>
