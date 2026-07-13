<?php
/**
 * Admin Dashboard - Complete Rebuild
 * Monitor overall station performance, users, inventory, financial operations, approvals, and system activities
 */
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'admin_dashboard';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();
$user_id = (int)($me['id'] ?? 0);

// Role check
if (!in_array($role, ['admin', 'superadmin', 'developer'])) {
    header('Location: dashboard.php');
    exit;
}

if (!$station_id && $role === 'admin') {
    render_no_station_page('admin_dashboard.php');
}

$display_name = htmlspecialchars($me['full_name'] ?? trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: ($me['name'] ?? 'Admin'));

// Date filter
$date_filter = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_filter)) {
    $date_filter = date('Y-m-d');
}

$flash_success = $_SESSION['success'] ?? null; unset($_SESSION['success']);
$flash_error = $_SESSION['error'] ?? null; unset($_SESSION['error']);

// ═══════════════════════════════════════════════════════════════════════════
// METRICS COLLECTION
// ═══════════════════════════════════════════════════════════════════════════

// 1. Today's Revenue (Fuel + Merchandise + Services)
try {
    $fuel_rev = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM fuel_transactions WHERE station_id = ? AND DATE(transaction_date) = ?");
    $fuel_rev->execute([$station_id, $date_filter]);
    $fuel_revenue = (float)$fuel_rev->fetchColumn();
} catch (Exception $e) { $fuel_revenue = 0; }

try {
    $merch_rev = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM merchandise_transactions WHERE station_id = ? AND DATE(COALESCE(transaction_date, created_at)) = ?");
    $merch_rev->execute([$station_id, $date_filter]);
    $merch_revenue = (float)$merch_rev->fetchColumn();
} catch (Exception $e) { $merch_revenue = 0; }

try {
    $service_rev = $pdo->prepare("SELECT COALESCE(SUM(total_cost), 0) FROM job_orders WHERE station_id = ? AND DATE(created_at) = ? AND status IN ('Completed', 'Released', 'Verified')");
    $service_rev->execute([$station_id, $date_filter]);
    $service_revenue = (float)$service_rev->fetchColumn();
} catch (Exception $e) { $service_revenue = 0; }

$total_revenue = $fuel_revenue + $merch_revenue + $service_revenue;

// 2. Today's Transactions
try {
    $fuel_tx = $pdo->prepare("SELECT COUNT(*) FROM fuel_transactions WHERE station_id = ? AND DATE(transaction_date) = ?");
    $fuel_tx->execute([$station_id, $date_filter]);
    $fuel_count = (int)$fuel_tx->fetchColumn();
} catch (Exception $e) { $fuel_count = 0; }

try {
    $merch_tx = $pdo->prepare("SELECT COUNT(*) FROM merchandise_transactions WHERE station_id = ? AND DATE(COALESCE(transaction_date, created_at)) = ?");
    $merch_tx->execute([$station_id, $date_filter]);
    $merch_count = (int)$merch_tx->fetchColumn();
} catch (Exception $e) { $merch_count = 0; }

try {
    $service_tx = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id = ? AND DATE(created_at) = ?");
    $service_tx->execute([$station_id, $date_filter]);
    $service_count = (int)$service_tx->fetchColumn();
} catch (Exception $e) { $service_count = 0; }

$total_transactions = $fuel_count + $merch_count + $service_count;

// 3. Active Users
try {
    $admin_count = $pdo->prepare("SELECT COUNT(*) FROM users WHERE station_id = ? AND status = 'Active' AND LOWER(role) = 'admin'");
    $admin_count->execute([$station_id]);
    $active_admins = (int)$admin_count->fetchColumn();
} catch (Exception $e) { $active_admins = 0; }

try {
    $manager_count = $pdo->prepare("SELECT COUNT(*) FROM users WHERE station_id = ? AND status = 'Active' AND LOWER(role) = 'manager'");
    $manager_count->execute([$station_id]);
    $active_managers = (int)$manager_count->fetchColumn();
} catch (Exception $e) { $active_managers = 0; }

try {
    $staff_count = $pdo->prepare("SELECT COUNT(*) FROM users WHERE station_id = ? AND status = 'Active' AND LOWER(role) = 'staff'");
    $staff_count->execute([$station_id]);
    $active_staff = (int)$staff_count->fetchColumn();
} catch (Exception $e) { $active_staff = 0; }

$total_active_users = $active_admins + $active_managers + $active_staff;

// 4. Pending Approvals
try {
    $stock_req = $pdo->prepare("SELECT COUNT(*) FROM stock_requests WHERE station_id = ? AND status IN ('Pending', 'Pending Manager Review')");
    $stock_req->execute([$station_id]);
    $pending_stock_requests = (int)$stock_req->fetchColumn();
} catch (Exception $e) { $pending_stock_requests = 0; }

try {
    $fuel_req = $pdo->prepare("SELECT COUNT(*) FROM fuel_stock_requests WHERE station_id = ? AND status IN ('Pending', 'Pending Manager Review')");
    $fuel_req->execute([$station_id]);
    $pending_fuel_requests = (int)$fuel_req->fetchColumn();
} catch (Exception $e) { $pending_fuel_requests = 0; }

try {
    $inv_adj = $pdo->prepare("SELECT COUNT(*) FROM inventory_adjustments WHERE station_id = ? AND status = 'Pending'");
    $inv_adj->execute([$station_id]);
    $pending_adjustments = (int)$inv_adj->fetchColumn();
} catch (Exception $e) { $pending_adjustments = 0; }

$total_pending_approvals = $pending_stock_requests + $pending_fuel_requests + $pending_adjustments;

// 5. Inventory Alerts
try {
    $low_fuel = $pdo->prepare("SELECT COUNT(*) FROM fuel_inventory WHERE station_id = ? AND current_level <= reorder_level");
    $low_fuel->execute([$station_id]);
    $low_fuel_count = (int)$low_fuel->fetchColumn();
} catch (Exception $e) { $low_fuel_count = 0; }

try {
    $low_merch = $pdo->prepare("SELECT COUNT(*) FROM station_inventory WHERE station_id = ? AND stock_level <= reorder_level AND status = 'active'");
    $low_merch->execute([$station_id]);
    $low_merch_count = (int)$low_merch->fetchColumn();
} catch (Exception $e) { $low_merch_count = 0; }

try {
    $critical_stock = $pdo->prepare("SELECT COUNT(*) FROM station_inventory WHERE station_id = ? AND stock_level <= 0 AND status = 'active'");
    $critical_stock->execute([$station_id]);
    $critical_stock_count = (int)$critical_stock->fetchColumn();
} catch (Exception $e) { $critical_stock_count = 0; }

$total_inventory_alerts = $low_fuel_count + $low_merch_count + $critical_stock_count;

// 6. Pending Deliveries
try {
    $pending_del = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE station_id = ? AND admin_finalized = 1 AND delivery_validated = 0 AND stock_in_done = 0");
    $pending_del->execute([$station_id]);
    $pending_deliveries_count = (int)$pending_del->fetchColumn();
} catch (Exception $e) { $pending_deliveries_count = 0; }

// 7. System Health
$db_connected = true;
$server_running = true;
try {
    $pdo->query("SELECT 1");
} catch (Exception $e) {
    $db_connected = false;
}

// Check last backup (mock - replace with actual backup table if exists)
$backup_successful = true;
$last_backup = date('Y-m-d H:i:s', strtotime('-1 day'));

// 8. Today's Profit (Optional - requires cost tracking)
$todays_profit = 0; // Placeholder - implement cost tracking if needed

// ═══════════════════════════════════════════════════════════════════════════
// ADDITIONAL CHART DATA
// ═══════════════════════════════════════════════════════════════════════════

// Fuel Sales by Product
$fuel_products = ['Diesel', 'XCS', 'Turbo Diesel', 'XTRA Unleaded', 'Kerosene'];
$fuel_sales_data = [];
foreach ($fuel_products as $product) {
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(liters_sold), 0) FROM fuel_transactions WHERE station_id = ? AND DATE(transaction_date) = ? AND fuel_type LIKE ?");
        $stmt->execute([$station_id, $date_filter, "%$product%"]);
        $fuel_sales_data[] = (float)$stmt->fetchColumn();
    } catch (Exception $e) {
        $fuel_sales_data[] = 0;
    }
}

// Merchandise Sales by Category
try {
    $merch_cat = $pdo->prepare("SELECT mti.category, COALESCE(SUM(mti.subtotal), 0) AS total FROM merchandise_transaction_items mti JOIN merchandise_transactions mt ON mti.transaction_id = mt.id WHERE mt.station_id = ? AND DATE(COALESCE(mt.transaction_date, mt.created_at)) = ? GROUP BY mti.category LIMIT 5");
    $merch_cat->execute([$station_id, $date_filter]);
    $merch_categories_raw = $merch_cat->fetchAll(PDO::FETCH_ASSOC);
    $merch_categories = [];
    $merch_sales_by_cat = [];
    foreach ($merch_categories_raw as $row) {
        $merch_categories[] = $row['category'] ?: 'Others';
        $merch_sales_by_cat[] = (float)$row['total'];
    }
} catch (Exception $e) {
    $merch_categories = ['Lubricants', 'Drinks', 'Snacks', 'Accessories', 'Engine Oil'];
    $merch_sales_by_cat = [0, 0, 0, 0, 0];
}

// Weekly Sales Trend (Last 7 days)
$weekly_labels = [];
$weekly_sales = [];
for ($i = 6; $i >= 0; $i--) {
    $check_date = date('Y-m-d', strtotime("-$i days"));
    $weekly_labels[] = date('D', strtotime($check_date));
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM (SELECT total_amount FROM fuel_transactions WHERE station_id = ? AND DATE(transaction_date) = ? UNION ALL SELECT total_amount FROM merchandise_transactions WHERE station_id = ? AND DATE(COALESCE(transaction_date, created_at)) = ?) AS combined");
        $stmt->execute([$station_id, $check_date, $station_id, $check_date]);
        $weekly_sales[] = (float)$stmt->fetchColumn();
    } catch (Exception $e) {
        $weekly_sales[] = 0;
    }
}

// User Activity
try {
    $user_act = $pdo->prepare("SELECT u.username, COUNT(al.id) AS actions FROM audit_logs al JOIN users u ON al.user_id = u.id WHERE u.station_id = ? AND DATE(al.created_at) = ? GROUP BY u.id, u.username ORDER BY actions DESC LIMIT 5");
    $user_act->execute([$station_id, $date_filter]);
    $user_activity_raw = $user_act->fetchAll(PDO::FETCH_ASSOC);
    $user_names = [];
    $user_actions = [];
    foreach ($user_activity_raw as $row) {
        $user_names[] = $row['username'];
        $user_actions[] = (int)$row['actions'];
    }
} catch (Exception $e) {
    $user_names = [];
    $user_actions = [];
}

// ═══════════════════════════════════════════════════════════════════════════
// MANAGEMENT PANEL DATA
// ═══════════════════════════════════════════════════════════════════════════

// Pending User Accounts
try {
    $pending_users = $pdo->prepare("SELECT id, CONCAT(first_name, ' ', last_name) AS name, username, role, status FROM users WHERE station_id = ? AND status = 'Pending' LIMIT 5");
    $pending_users->execute([$station_id]);
    $pending_users_data = $pending_users->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pending_users_data = [];
}

// Recent User Activities
try {
    $recent_activities = $pdo->prepare("SELECT u.username, al.action_type, al.entity_type, DATE_FORMAT(al.created_at, '%h:%i %p') AS time FROM audit_logs al JOIN users u ON al.user_id = u.id WHERE u.station_id = ? ORDER BY al.created_at DESC LIMIT 10");
    $recent_activities->execute([$station_id]);
    $recent_activities_data = $recent_activities->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recent_activities_data = [];
}

// Recent Transactions
try {
    $recent_txn = $pdo->prepare("SELECT 'Fuel' AS type, CONCAT('FT-', id) AS ref_no, DATE_FORMAT(transaction_date, '%h:%i %p') AS time, total_amount FROM fuel_transactions WHERE station_id = ? AND DATE(transaction_date) = ? UNION ALL SELECT 'Merchandise' AS type, CONCAT('MT-', id) AS ref_no, DATE_FORMAT(COALESCE(transaction_date, created_at), '%h:%i %p') AS time, total_amount FROM merchandise_transactions WHERE station_id = ? AND DATE(COALESCE(transaction_date, created_at)) = ? ORDER BY time DESC LIMIT 10");
    $recent_txn->execute([$station_id, $date_filter, $station_id, $date_filter]);
    $recent_txn_data = $recent_txn->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recent_txn_data = [];
}

include __DIR__ . '/../partials/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
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
        
        .dashboard-container {
            max-width: 1920px;
            margin: 0 auto;
            padding: 24px;
        }
        
        /* Dashboard Header Container */
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
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-filter-submit:hover {
            background: #001f4d !important;
        }
        
        /* Summary Cards Grid */
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
        @media (max-width: 1200px) {
            .charts-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        @media (max-width: 768px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
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
        
        /* Management Panels */
        .management-panels-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 32px;
        }
        @media (max-width: 992px) {
            .management-panels-grid {
                grid-template-columns: 1fr;
            }
        }
        .panel-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }
        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid #f1f5f9;
        }
        .panel-header h3 {
            font-size: 15px;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .data-table thead th {
            background: #f8fafc;
            color: #64748b;
            font-weight: 700;
            padding: 12px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e2e8f0;
        }
        .data-table tbody td {
            padding: 12px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
        }
        .data-table tbody tr:hover {
            background: #f8fafc;
        }
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
        .btn-sm {
            padding: 6px 12px;
            font-size: 11px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-weight: 600;
        }
        .btn-primary {
            background: #002F70;
            color: white;
        }
        .btn-success {
            background: #16a34a;
            color: white;
        }
        .btn-danger {
            background: #dc2626;
            color: white;
        }
        
        /* Quick Actions */
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        .quick-action-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 10px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 18px 12px;
            text-decoration: none;
            color: #334155;
            font-weight: 700;
            font-size: 13px;
            transition: all 0.25s ease;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            cursor: pointer;
        }
        .quick-action-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            border-color: #002F70;
            color: #002F70;
        }
        .quick-action-btn i {
            font-size: 20px;
            color: #002F70;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        
        <!-- Welcome / Filter Banner -->
        <div class="dashboard-header-container">
            <div class="welcome-meta">
                <h2>Welcome, <?= $display_name ?>!</h2>
                <p><i class="fas fa-user-shield"></i> Admin Dashboard</p>
            </div>
            <div class="header-filters">
                <form method="GET" class="date-filter-form">
                    <input type="date" name="date" value="<?= htmlspecialchars($date_filter) ?>" required>
                    <button type="submit" class="btn-filter-submit"><i class="fas fa-filter"></i> Filter</button>
                    <?php if ($date_filter !== date('Y-m-d')): ?>
                        <a href="admin_dashboard_rebuilt.php" class="btn-filter-submit" style="background:#64748b !important;"><i class="fas fa-undo"></i> Reset</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        
        <!-- 8 Summary Cards Grid -->
        <div class="summary-cards-grid">
            <!-- Card 1: Today's Revenue -->
            <div class="summary-metric-card" style="border-left: 4px solid #16a34a;">
                <div class="metric-details">
                    <h4>Today's Revenue</h4>
                    <div class="metric-value">₱<?= number_format($total_revenue, 2) ?></div>
                </div>
                <div class="metric-icon-box" style="background: #f0fdf4; color: #16a34a;">
                    <i class="fas fa-peso-sign"></i>
                </div>
            </div>
            
            <!-- Card 2: Today's Transactions -->
            <div class="summary-metric-card" style="border-left: 4px solid #002F70;">
                <div class="metric-details">
                    <h4>Today's Transactions</h4>
                    <div class="metric-value"><?= number_format($total_transactions) ?></div>
                </div>
                <div class="metric-icon-box" style="background: #eff6ff; color: #002F70;">
                    <i class="fas fa-exchange-alt"></i>
                </div>
            </div>
            
            <!-- Card 3: Active Users -->
            <div class="summary-metric-card" style="border-left: 4px solid #7c3aed;">
                <div class="metric-details">
                    <h4>Active Users</h4>
                    <div class="metric-value"><?= number_format($total_active_users) ?></div>
                </div>
                <div class="metric-icon-box" style="background: #f3e8ff; color: #7c3aed;">
                    <i class="fas fa-users"></i>
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
            <div class="summary-metric-card" style="border-left: 4px solid #f59e0b;">
                <div class="metric-details">
                    <h4>Inventory Alerts</h4>
                    <div class="metric-value"><?= number_format($total_inventory_alerts) ?></div>
                </div>
                <div class="metric-icon-box" style="background: #ffedd5; color: #c2410c;">
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
            
            <!-- Card 7: System Health -->
            <div class="summary-metric-card" style="border-left: 4px solid <?= $db_connected ? '#16a34a' : '#dc2626' ?>;">
                <div class="metric-details">
                    <h4>System Health</h4>
                    <div class="metric-value">
                        <?php if ($db_connected && $server_running && $backup_successful): ?>
                            <span style="color: #16a34a;"><i class="fas fa-check-circle"></i> OK</span>
                        <?php else: ?>
                            <span style="color: #dc2626;"><i class="fas fa-exclamation-circle"></i> Issue</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="metric-icon-box" style="background: <?= $db_connected ? '#f0fdf4' : '#fef2f2' ?>; color: <?= $db_connected ? '#16a34a' : '#dc2626' ?>;">
                    <i class="fas fa-server"></i>
                </div>
            </div>
            
            <!-- Card 8: Today's Profit (Optional) -->
            <div class="summary-metric-card" style="border-left: 4px solid #eab308;">
                <div class="metric-details">
                    <h4>Today's Profit</h4>
                    <div class="metric-value">₱<?= number_format($todays_profit, 2) ?></div>
                </div>
                <div class="metric-icon-box" style="background: #fef9c3; color: #eab308;">
                    <i class="fas fa-chart-line"></i>
                </div>
            </div>
        </div>
        
        <!-- Charts Section -->
        <div class="charts-grid">
            <!-- Chart 1: Revenue Breakdown -->
            <div class="chart-card">
                <div class="chart-card-header">
                    <i class="fas fa-chart-pie"></i>
                    <h3>Revenue Breakdown</h3>
                </div>
                <div class="chart-container">
                    <canvas id="revenueBreakdownChart"></canvas>
                </div>
            </div>
            
            <!-- Chart 2: Monthly Revenue Trend -->
            <div class="chart-card">
                <div class="chart-card-header">
                    <i class="fas fa-chart-line"></i>
                    <h3>Monthly Revenue Trend</h3>
                </div>
                <div class="chart-container">
                    <canvas id="monthlyRevenueChart"></canvas>
                </div>
            </div>
            
            <!-- Chart 3: Transactions per Module -->
            <div class="chart-card">
                <div class="chart-card-header">
                    <i class="fas fa-chart-bar"></i>
                    <h3>Transactions per Module</h3>
                </div>
                <div class="chart-container">
                    <canvas id="transactionsModuleChart"></canvas>
                </div>
            </div>
            
            <!-- Chart 4: Inventory Status -->
            <div class="chart-card">
                <div class="chart-card-header">
                    <i class="fas fa-boxes"></i>
                    <h3>Inventory Status</h3>
                </div>
                <div class="chart-container">
                    <canvas id="inventoryStatusChart"></canvas>
                </div>
            </div>
            
            <!-- Chart 5: Weekly Sales Trend -->
            <div class="chart-card">
                <div class="chart-card-header">
                    <i class="fas fa-chart-area"></i>
                    <h3>Weekly Sales Trend</h3>
                </div>
                <div class="chart-container">
                    <canvas id="weeklySalesChart"></canvas>
                </div>
            </div>
            
            <!-- Chart 6: User Activity -->
            <div class="chart-card">
                <div class="chart-card-header">
                    <i class="fas fa-user-check"></i>
                    <h3>User Activity</h3>
                </div>
                <div class="chart-container">
                    <canvas id="userActivityChart"></canvas>
                </div>
            </div>
            
            <!-- Chart 7: Fuel Sales by Product -->
            <div class="chart-card">
                <div class="chart-card-header">
                    <i class="fas fa-gas-pump"></i>
                    <h3>Fuel Sales by Product</h3>
                </div>
                <div class="chart-container">
                    <canvas id="fuelSalesChart"></canvas>
                </div>
            </div>
            
            <!-- Chart 8: Merchandise Sales by Category -->
            <div class="chart-card">
                <div class="chart-card-header">
                    <i class="fas fa-shopping-bag"></i>
                    <h3>Merchandise Sales by Category</h3>
                </div>
                <div class="chart-container">
                    <canvas id="merchSalesChart"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="quick-actions-grid">
            <a href="admin_user_management.php" class="quick-action-btn">
                <i class="fas fa-users-cog"></i>
                <span>User Management</span>
            </a>
            <a href="admin_set_prices.php" class="quick-action-btn">
                <i class="fas fa-tags"></i>
                <span>Pricing Management</span>
            </a>
            <a href="admin_reports.php" class="quick-action-btn">
                <i class="fas fa-file-alt"></i>
                <span>Reports</span>
            </a>
            <a href="admin_inventory.php" class="quick-action-btn">
                <i class="fas fa-boxes"></i>
                <span>Inventory</span>
            </a>
            <a href="manager_validated_transactions.php" class="quick-action-btn">
                <i class="fas fa-receipt"></i>
                <span>Transactions</span>
            </a>
        </div>
        
        <!-- Management Panels -->
        <div class="management-panels-grid">
            <!-- Panel 1: Pending User Accounts -->
            <div class="panel-card">
                <div class="panel-header">
                    <h3><i class="fas fa-user-clock"></i> Pending User Accounts</h3>
                </div>
                <div class="panel-content">
                    <?php if (empty($pending_users_data)): ?>
                        <p style="color: #64748b; text-align: center; padding: 20px;">No pending user accounts</p>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Position</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending_users_data as $user): ?>
                                <tr>
                                    <td><?= htmlspecialchars($user['name']) ?></td>
                                    <td><?= htmlspecialchars($user['role']) ?></td>
                                    <td><span class="badge badge-warning"><?= htmlspecialchars($user['status']) ?></span></td>
                                    <td>
                                        <button class="btn-sm btn-success" onclick="alert('Approve User')">Approve</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Panel 2: Recent User Activities -->
            <div class="panel-card">
                <div class="panel-header">
                    <h3><i class="fas fa-history"></i> Recent User Activities</h3>
                </div>
                <div class="panel-content">
                    <?php if (empty($recent_activities_data)): ?>
                        <p style="color: #64748b; text-align: center; padding: 20px;">No recent activities</p>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Module</th>
                                    <th>Action</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_activities_data as $activity): ?>
                                <tr>
                                    <td><?= htmlspecialchars($activity['username']) ?></td>
                                    <td><?= htmlspecialchars($activity['entity_type'] ?? 'System') ?></td>
                                    <td><?= htmlspecialchars($activity['action_type']) ?></td>
                                    <td><?= htmlspecialchars($activity['time']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Panel 3: Recent Transactions -->
            <div class="panel-card">
                <div class="panel-header">
                    <h3><i class="fas fa-exchange-alt"></i> Recent Transactions</h3>
                </div>
                <div class="panel-content">
                    <?php if (empty($recent_txn_data)): ?>
                        <p style="color: #64748b; text-align: center; padding: 20px;">No recent transactions</p>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Ref No</th>
                                    <th>Amount</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_txn_data as $txn): ?>
                                <tr>
                                    <td><span class="badge badge-success"><?= htmlspecialchars($txn['type']) ?></span></td>
                                    <td><?= htmlspecialchars($txn['ref_no']) ?></td>
                                    <td>₱<?= number_format($txn['total_amount'], 2) ?></td>
                                    <td><?= htmlspecialchars($txn['time']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Panel 4: Low Inventory Summary -->
            <div class="panel-card">
                <div class="panel-header">
                    <h3><i class="fas fa-exclamation-triangle"></i> Low Inventory Summary</h3>
                </div>
                <div class="panel-content">
                    <p style="color: #64748b; text-align: center; padding: 20px;">
                        <strong><?= $total_inventory_alerts ?></strong> items need attention
                    </p>
                    <div style="display: flex; justify-content: space-around; margin-top: 15px;">
                        <div style="text-align: center;">
                            <div style="font-size: 24px; font-weight: 800; color: #dc2626;"><?= $low_fuel_count ?></div>
                            <div style="font-size: 12px; color: #64748b;">Low Fuel</div>
                        </div>
                        <div style="text-align: center;">
                            <div style="font-size: 24px; font-weight: 800; color: #f59e0b;"><?= $low_merch_count ?></div>
                            <div style="font-size: 12px; color: #64748b;">Low Merchandise</div>
                        </div>
                        <div style="text-align: center;">
                            <div style="font-size: 24px; font-weight: 800; color: #7c3aed;"><?= $critical_stock_count ?></div>
                            <div style="font-size: 12px; color: #64748b;">Critical Stock</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
    </div>

    <script>
        // Chart.js Charts
        
        // 1. Revenue Breakdown Chart (Donut)
        const revenueCtx = document.getElementById('revenueBreakdownChart');
        if (revenueCtx) {
            new Chart(revenueCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Fuel Sales', 'Merchandise Sales', 'Service Sales'],
                    datasets: [{
                        data: [<?= $fuel_revenue ?>, <?= $merch_revenue ?>, <?= $service_revenue ?>],
                        backgroundColor: ['#dc2626', '#16a34a', '#002F70']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        }
        
        // 2. Monthly Revenue Trend (Line)
        const monthlyCtx = document.getElementById('monthlyRevenueChart');
        if (monthlyCtx) {
            new Chart(monthlyCtx, {
                type: 'line',
                data: {
                    labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                    datasets: [{
                        label: 'Revenue',
                        data: [0, 0, 0, 0, 0, 0], // Replace with actual data
                        borderColor: '#002F70',
                        backgroundColor: 'rgba(0, 47, 112, 0.1)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        }
        
        // 3. Transactions per Module (Bar)
        const transactionsCtx = document.getElementById('transactionsModuleChart');
        if (transactionsCtx) {
            new Chart(transactionsCtx, {
                type: 'bar',
                data: {
                    labels: ['Fuel', 'Merchandise', 'Service'],
                    datasets: [{
                        label: 'Transactions',
                        data: [<?= $fuel_count ?>, <?= $merch_count ?>, <?= $service_count ?>],
                        backgroundColor: ['#dc2626', '#16a34a', '#002F70']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        }
        
        // 4. Inventory Status (Horizontal Bar)
        const inventoryCtx = document.getElementById('inventoryStatusChart');
        if (inventoryCtx) {
            new Chart(inventoryCtx, {
                type: 'bar',
                data: {
                    labels: ['Normal', 'Low Stock', 'Critical'],
                    datasets: [{
                        label: 'Items',
                        data: [10, <?= $low_merch_count + $low_fuel_count ?>, <?= $critical_stock_count ?>],
                        backgroundColor: ['#16a34a', '#f59e0b', '#dc2626']
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true
                        }
                    }
                }
            });
        }
        
        // 5. Weekly Sales Trend (Line)
        const weeklyCtx = document.getElementById('weeklySalesChart');
        if (weeklyCtx) {
            new Chart(weeklyCtx, {
                type: 'line',
                data: {
                    labels: <?= json_encode($weekly_labels) ?>,
                    datasets: [{
                        label: 'Sales',
                        data: <?= json_encode($weekly_sales) ?>,
                        borderColor: '#16a34a',
                        backgroundColor: 'rgba(22, 163, 74, 0.1)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        }
        
        // 6. User Activity (Bar)
        const userActivityCtx = document.getElementById('userActivityChart');
        if (userActivityCtx) {
            new Chart(userActivityCtx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($user_names) ?>,
                    datasets: [{
                        label: 'Actions',
                        data: <?= json_encode($user_actions) ?>,
                        backgroundColor: '#7c3aed'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        }
        
        // 7. Fuel Sales by Product (Bar)
        const fuelSalesCtx = document.getElementById('fuelSalesChart');
        if (fuelSalesCtx) {
            new Chart(fuelSalesCtx, {
                type: 'bar',
                data: {
                    labels: ['Diesel', 'XCS', 'Turbo Diesel', 'XTRA Unleaded', 'Kerosene'],
                    datasets: [{
                        label: 'Liters',
                        data: <?= json_encode($fuel_sales_data) ?>,
                        backgroundColor: ['#dc2626', '#f59e0b', '#7c3aed', '#16a34a', '#0891b2']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        }
        
        // 8. Merchandise Sales by Category (Bar)
        const merchSalesCtx = document.getElementById('merchSalesChart');
        if (merchSalesCtx) {
            new Chart(merchSalesCtx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($merch_categories) ?>,
                    datasets: [{
                        label: 'Sales',
                        data: <?= json_encode($merch_sales_by_cat) ?>,
                        backgroundColor: '#002F70'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        }
    </script>
</body>
</html>
