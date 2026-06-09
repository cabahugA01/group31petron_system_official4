<?php
/**
 * Manager Dashboard - Rebuilt with Correct Data Fetching Flow
 * 
 * Summary Cards: Total Sales, Fuel Stock, Merchandise Inventory, Pending Deliveries, Active Staff
 * Graphs: Transactions, Fuel Management, Deliveries, Inventory, Customers, Staff Performance
 * 
 * Data Sources:
 * - Sales: transactions table (validated only)
 * - Fuel: fuel_inventory, fuel_deliveries, meter_readings
 * - Merchandise: station_inventory, stock_movements
 * - Deliveries: deliveries_oversight, purchase_orders
 * - Customers: customers, orders, returns
 * - Staff: staff_activity_logs, validation_logs
 */

if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'manager_dashboard';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$station_id = (int)user_station_id();
$role = role_key($me['role'] ?? '');

// Access control
if (!in_array($role, ['manager', 'supervisor'])) {
    $_SESSION['error'] = 'Access denied. Manager privileges required.';
    header('Location: dashboard.php'); exit;
}

if (!$station_id) {
    die('Error: You are not assigned to a station.');
}

$display_name = htmlspecialchars($me['full_name'] ?? trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: ($me['name'] ?? 'Manager'));
$flash_success = $_SESSION['success'] ?? null; unset($_SESSION['success']);
$flash_error = $_SESSION['error'] ?? null; unset($_SESSION['error']);

// ============================================================
// AJAX ENDPOINT: Fetch Dashboard Data
// ============================================================
if (isset($_GET['fetch']) && $_GET['fetch'] === 'dashboard_data') {
    header('Content-Type: application/json');
    try {
        $data = fetchDashboardData($pdo, $station_id);
        echo json_encode(['success' => true, 'data' => $data]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}


// ============================================================
// FUNCTION: Fetch All Dashboard Data
// ============================================================
function fetchDashboardData($pdo, $station_id) {
    $data = [];
    
    // ─── SUMMARY CARDS ──────────────────────────────────────────────────────────
    
    // 1. Total Sales (₱) → validated transactions only
    $fuel_stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM fuel_transactions WHERE station_id = ? AND status = 'verified' AND DATE(created_at) = CURDATE()");
    $fuel_stmt->execute([$station_id]);
    $fuel_sales = (float)$fuel_stmt->fetchColumn();
    
    $merch_stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM merchandise_transactions WHERE station_id = ? AND validation_status IN ('Approved', 'Adjusted') AND DATE(created_at) = CURDATE()");
    $merch_stmt->execute([$station_id]);
    $merch_sales = (float)$merch_stmt->fetchColumn();
    
    $data['total_sales'] = $fuel_sales + $merch_sales;
    $data['fuel_sales'] = $fuel_sales;
    $data['merch_sales'] = $merch_sales;
    
    // 2. Fuel Stock (Liters) → fuel_inventory after validation
    $fuel_stock_stmt = $pdo->prepare("SELECT 
        COALESCE(ft.name, fi.fuel_type) AS fuel_type,
        COALESCE(fi.current_level, fi.current_stock, 0) AS current_stock,
        COALESCE(fi.capacity, 10000) AS capacity
    FROM fuel_inventory fi
    LEFT JOIN fuel_types ft ON fi.fuel_type_id = ft.id
    WHERE fi.station_id = ?
    ORDER BY fuel_type");
    $fuel_stock_stmt->execute([$station_id]);
    $data['fuel_stock'] = $fuel_stock_stmt->fetchAll(PDO::FETCH_ASSOC);
    $data['total_fuel_liters'] = array_sum(array_column($data['fuel_stock'], 'current_stock'));
    
    // 3. Merchandise Inventory → station_inventory (validated stock-in/out)
    $merch_inv_stmt = $pdo->prepare("SELECT COUNT(*) AS total_items, COALESCE(SUM(stock_level), 0) AS total_stock FROM station_inventory WHERE station_id = ? AND status = 'Active'");
    $merch_inv_stmt->execute([$station_id]);
    $merch_inv = $merch_inv_stmt->fetch(PDO::FETCH_ASSOC);
    $data['merch_inventory_items'] = (int)$merch_inv['total_items'];
    $data['merch_inventory_stock'] = (int)$merch_inv['total_stock'];

    
    // 4. Pending Deliveries → deliveries_oversight (awaiting validation)
    $pend_del_stmt = $pdo->prepare("SELECT COUNT(*) FROM deliveries_oversight WHERE station_id = ? AND status = 'Pending Manager Approval'");
    $pend_del_stmt->execute([$station_id]);
    $data['pending_deliveries'] = (int)$pend_del_stmt->fetchColumn();
    
    // 5. Active Staff Tasks → labor_sessions fallbacks
    $active_staff_stmt = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM labor_sessions WHERE station_id = ? AND end_time IS NULL");
    $active_staff_stmt->execute([$station_id]);
    $data['active_staff'] = (int)$active_staff_stmt->fetchColumn();
    
    // ─── TRANSACTIONS GRAPHS ────────────────────────────────────────────────────
    
    // Bar Chart: Daily sales totals (cash vs card vs e-wallet) - Last 7 days
    $payment_trend_stmt = $pdo->prepare("SELECT 
        DATE(created_at) AS date,
        COALESCE(SUM(CASE WHEN payment_method IN ('Cash', 'cash') THEN total_amount ELSE 0 END), 0) AS cash,
        COALESCE(SUM(CASE WHEN payment_method IN ('Card', 'Credit Card', 'Debit Card', 'card') THEN total_amount ELSE 0 END), 0) AS card,
        COALESCE(SUM(CASE WHEN payment_method IN ('E-Wallet', 'GCash', 'Maya', 'ewallet') THEN total_amount ELSE 0 END), 0) AS ewallet
    FROM (
        SELECT created_at, payment_method, total_amount FROM fuel_transactions WHERE station_id = ? AND status = 'verified' AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        UNION ALL
        SELECT created_at, payment_method, total_amount FROM merchandise_transactions WHERE station_id = ? AND validation_status IN ('Approved', 'Adjusted') AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ) AS combined
    GROUP BY DATE(created_at)
    ORDER BY DATE(created_at)");
    $payment_trend_stmt->execute([$station_id, $station_id]);
    $data['payment_trend'] = $payment_trend_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Pie Chart: Payment method distribution (today)
    $payment_dist_stmt = $pdo->prepare("SELECT 
        payment_method,
        COALESCE(SUM(total_amount), 0) AS total
    FROM (
        SELECT payment_method, total_amount FROM fuel_transactions WHERE station_id = ? AND status = 'verified' AND DATE(created_at) = CURDATE()
        UNION ALL
        SELECT payment_method, total_amount FROM merchandise_transactions WHERE station_id = ? AND validation_status IN ('Approved', 'Adjusted') AND DATE(created_at) = CURDATE()
    ) AS combined
    GROUP BY payment_method
    ORDER BY total DESC");
    $payment_dist_stmt->execute([$station_id, $station_id]);
    $data['payment_distribution'] = $payment_dist_stmt->fetchAll(PDO::FETCH_ASSOC);

    
    // Line Chart: Revenue trend (weekly/monthly) - Last 30 days
    $revenue_trend_stmt = $pdo->prepare("SELECT 
        DATE(created_at) AS date,
        COALESCE(SUM(total_amount), 0) AS revenue
    FROM (
        SELECT created_at, total_amount FROM fuel_transactions WHERE station_id = ? AND status = 'verified' AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        UNION ALL
        SELECT created_at, total_amount FROM merchandise_transactions WHERE station_id = ? AND validation_status IN ('Approved', 'Adjusted') AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ) AS combined
    GROUP BY DATE(created_at)
    ORDER BY DATE(created_at)");
    $revenue_trend_stmt->execute([$station_id, $station_id]);
    $data['revenue_trend'] = $revenue_trend_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ─── FUEL MANAGEMENT GRAPHS ─────────────────────────────────────────────────
    
    // Gauge Chart: Current tank stock levels (already fetched above in fuel_stock)
    $data['tank_levels'] = $data['fuel_stock'];
    
    // Bar Chart: Liters sold per fuel type (today)
    $fuel_sold_stmt = $pdo->prepare("SELECT 
        fuel_type,
        COALESCE(SUM(liters_sold), 0) AS liters_sold
    FROM fuel_transactions
    WHERE station_id = ? AND status = 'verified' AND DATE(created_at) = CURDATE()
    GROUP BY fuel_type
    ORDER BY liters_sold DESC");
    $fuel_sold_stmt->execute([$station_id]);
    $data['fuel_sold_by_type'] = $fuel_sold_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Line Chart: Variance between expected vs actual pump readings (last 7 days)
    $fuel_variance_stmt = $pdo->prepare("SELECT 
        DATE(created_at) AS date,
        fuel_type,
        ROUND(SUM(present_reading - previous_reading), 2) AS expected,
        ROUND(SUM(liters_sold), 2) AS actual,
        ROUND(SUM(ABS((present_reading - previous_reading) - liters_sold)), 2) AS variance
    FROM fuel_transactions
    WHERE station_id = ? AND status = 'verified' AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY DATE(created_at), fuel_type
    ORDER BY DATE(created_at), fuel_type");
    $fuel_variance_stmt->execute([$station_id]);
    $data['fuel_variance_trend'] = $fuel_variance_stmt->fetchAll(PDO::FETCH_ASSOC);

    
    // ─── MERCHANDISE DELIVERIES GRAPHS ──────────────────────────────────────────
    
    // Pie Chart: Delivery status breakdown (Full, Partial, Damaged, Rejected)
    $del_status_stmt = $pdo->prepare("SELECT 
        CASE 
            WHEN status IN ('Validated', 'Confirmed', 'Approved', 'Stock-In Complete') THEN 'Full'
            WHEN status = 'Partial Delivery' THEN 'Partial'
            WHEN status IN ('Damaged Items', 'Flagged') THEN 'Damaged'
            WHEN status IN ('Rejected', 'Rejected Delivery', 'Discrepancy') THEN 'Rejected'
            WHEN status LIKE '%Pending%' THEN 'Pending'
            ELSE 'Other'
        END AS delivery_status,
        COUNT(*) AS count
    FROM deliveries_oversight
    WHERE station_id = ? AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY delivery_status
    ORDER BY count DESC");
    $del_status_stmt->execute([$station_id]);
    $data['delivery_status_breakdown'] = $del_status_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Stacked Bar Chart: PO vs actual quantities (last 10 deliveries)
    // NOTE: This query requires expected_quantity, actual_quantity, and damaged_quantity columns
    // If these columns don't exist, they need to be added via the admin panel
    try {
        $po_vs_actual_stmt = $pdo->prepare("SELECT 
            COALESCE(delivery_ref, CONCAT('DEL-', id)) AS delivery_ref,
            COALESCE(expected_quantity, quantity, 0) AS expected_quantity,
            COALESCE(actual_quantity, quantity, 0) AS actual_quantity,
            COALESCE(damaged_quantity, 0) AS damaged_quantity
        FROM deliveries_oversight
        WHERE station_id = ? AND status IN ('Validated', 'Partial Delivery', 'Damaged Items', 'Stock-In Complete')
        ORDER BY created_at DESC
        LIMIT 10");
        $po_vs_actual_stmt->execute([$station_id]);
        $data['po_vs_actual'] = $po_vs_actual_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Fallback if columns don't exist - use basic quantity data
        $po_vs_actual_stmt = $pdo->prepare("SELECT 
            COALESCE(delivery_ref, CONCAT('DEL-', id)) AS delivery_ref,
            COALESCE(quantity, 0) AS expected_quantity,
            COALESCE(quantity, 0) AS actual_quantity,
            0 AS damaged_quantity
        FROM deliveries_oversight
        WHERE station_id = ? AND status IN ('Validated', 'Partial Delivery', 'Damaged Items', 'Stock-In Complete')
        ORDER BY created_at DESC
        LIMIT 10");
        $po_vs_actual_stmt->execute([$station_id]);
        $data['po_vs_actual'] = $po_vs_actual_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Trend Line: Supplier performance (on-time vs delayed) - Last 30 days
    // NOTE: This query attempts to join purchase_orders with deliveries_oversight
    // If the schema doesn't support this analysis, it will return empty data
    try {
        $supplier_perf_stmt = $pdo->prepare("
            SELECT 
                COALESCE(po.supplier_name, do.supplier, 'Unknown Supplier') AS supplier,
                COUNT(*) AS total_deliveries,
                SUM(CASE WHEN COALESCE(do.delivery_date, DATE(do.created_at)) <= COALESCE(po.expected_delivery_date, po.expected_delivery, DATE_ADD(DATE(do.created_at), INTERVAL 7 DAY)) THEN 1 ELSE 0 END) AS on_time,
                SUM(CASE WHEN COALESCE(do.delivery_date, DATE(do.created_at)) > COALESCE(po.expected_delivery_date, po.expected_delivery, DATE_ADD(DATE(do.created_at), INTERVAL 7 DAY)) THEN 1 ELSE 0 END) AS `delayed`
            FROM deliveries_oversight do
            LEFT JOIN purchase_orders po ON po.po_number = do.source_ref AND po.station_id = do.station_id
            WHERE do.station_id = ? AND DATE(COALESCE(do.delivery_date, do.created_at)) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY supplier
            HAVING total_deliveries > 0
            ORDER BY total_deliveries DESC
            LIMIT 10
        ");
        $supplier_perf_stmt->execute([$station_id]);
        $data['supplier_performance'] = $supplier_perf_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Fallback: Simple supplier count from deliveries_oversight only
        try {
            $supplier_perf_fallback = $pdo->prepare("
                SELECT 
                    COALESCE(supplier, 'Unknown') AS supplier,
                    COUNT(*) AS total_deliveries,
                    COUNT(*) AS on_time,
                    0 AS delayed
                FROM deliveries_oversight
                WHERE station_id = ? AND DATE(COALESCE(delivery_date, created_at)) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                GROUP BY supplier
                ORDER BY total_deliveries DESC
                LIMIT 10
            ");
            $supplier_perf_fallback->execute([$station_id]);
            $data['supplier_performance'] = $supplier_perf_fallback->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e2) {
            // Ultimate fallback: empty array
            $data['supplier_performance'] = [];
        }
    }
    
    // ─── INVENTORY GRAPHS ───────────────────────────────────────────────────────
    
    // Bar Chart: Stock-in vs stock-out per item (top 10 items)
    $stock_movement_stmt = $pdo->prepare("SELECT 
        ip.product_name,
        COALESCE(SUM(CASE WHEN il.quantity_change > 0 THEN il.quantity_change ELSE 0 END), 0) AS stock_in,
        COALESCE(SUM(CASE WHEN il.quantity_change < 0 THEN ABS(il.quantity_change) ELSE 0 END), 0) AS stock_out
    FROM inventory_logs il
    JOIN inventory_products ip ON il.product_id = ip.id
    WHERE il.station_id = ? AND DATE(il.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY ip.product_name
    ORDER BY (COALESCE(SUM(CASE WHEN il.quantity_change > 0 THEN il.quantity_change ELSE 0 END), 0) + 
              COALESCE(SUM(CASE WHEN il.quantity_change < 0 THEN ABS(il.quantity_change) ELSE 0 END), 0)) DESC
    LIMIT 10");
    $stock_movement_stmt->execute([$station_id]);
    $data['stock_movement'] = $stock_movement_stmt->fetchAll(PDO::FETCH_ASSOC);

    
    // Line Chart: Inventory trends over time (last 30 days)
    $inv_trend_stmt = $pdo->prepare("SELECT 
        DATE(created_at) AS date,
        COALESCE(SUM(CASE WHEN quantity_change > 0 THEN quantity_change ELSE 0 END), 0) AS stock_in,
        COALESCE(SUM(CASE WHEN quantity_change < 0 THEN ABS(quantity_change) ELSE 0 END), 0) AS stock_out
    FROM inventory_logs
    WHERE station_id = ? AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY DATE(created_at)
    ORDER BY DATE(created_at)");
    $inv_trend_stmt->execute([$station_id]);
    $data['inventory_trend'] = $inv_trend_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Alerts: Low stock flagged
    $low_stock_stmt = $pdo->prepare("SELECT 
        ip.product_name,
        si.stock_level,
        si.reorder_level,
        'Merchandise' AS item_type
    FROM station_inventory si
    JOIN inventory_products ip ON si.product_id = ip.id
    WHERE si.station_id = ? AND si.status = 'Active' AND si.stock_level <= si.reorder_level
    UNION
    SELECT 
        COALESCE(ft.name, fi.fuel_type) AS product_name,
        COALESCE(fi.current_level, fi.current_stock, 0) AS stock_level,
        2000 AS reorder_level,
        'Fuel' AS item_type
    FROM fuel_inventory fi
    LEFT JOIN fuel_types ft ON fi.fuel_type_id = ft.id
    WHERE fi.station_id = ? AND COALESCE(fi.current_level, fi.current_stock, 0) <= 2000
    ORDER BY stock_level ASC");
    $low_stock_stmt->execute([$station_id, $station_id]);
    $data['low_stock_alerts'] = $low_stock_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ─── CUSTOMER GRAPHS ────────────────────────────────────────────────────────
    
    // Pie Chart: Purchase distribution (fuel vs merchandise) - Last 30 days
    $purchase_dist_stmt = $pdo->prepare("SELECT 
        'Fuel' AS category,
        COALESCE(SUM(total_amount), 0) AS total
    FROM fuel_transactions
    WHERE station_id = ? AND status = 'verified' AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    UNION ALL
    SELECT 
        'Merchandise' AS category,
        COALESCE(SUM(total_amount), 0) AS total
    FROM merchandise_transactions
    WHERE station_id = ? AND validation_status IN ('Approved', 'Adjusted') AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    $purchase_dist_stmt->execute([$station_id, $station_id]);
    $data['purchase_distribution'] = $purchase_dist_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Bar Chart: Top customers by purchase volume (top 10)
    $top_customers_stmt = $pdo->prepare("SELECT 
        c.name AS customer_name,
        COALESCE(SUM(mt.total_amount), 0) AS total_purchases
    FROM customers c
    LEFT JOIN merchandise_transactions mt ON c.id = mt.credit_customer_id AND mt.station_id = ? AND mt.validation_status IN ('Approved', 'Adjusted') AND DATE(mt.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    WHERE c.station_id = ?
    GROUP BY c.id, c.name
    HAVING total_purchases > 0
    ORDER BY total_purchases DESC
    LIMIT 10");
    $top_customers_stmt->execute([$station_id, $station_id]);
    $data['top_customers'] = $top_customers_stmt->fetchAll(PDO::FETCH_ASSOC);

    
    // Line Chart: Complaints/returns trend (last 30 days) from notifications
    $complaints_stmt = $pdo->prepare("
        SELECT DATE(n.created_at) AS date, COUNT(*) AS count
        FROM notifications n
        JOIN users u ON n.user_id = u.id
        WHERE u.station_id = ? AND n.title = 'Customer Issue' AND DATE(n.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY DATE(n.created_at)
        ORDER BY DATE(n.created_at)
    ");
    $complaints_stmt->execute([$station_id]);
    $data['complaints_trend'] = $complaints_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ─── STAFF PERFORMANCE SNAPSHOT ─────────────────────────────────────────────
    
    // Bar Chart: Encoding accuracy per staff (based on validation actions log)
    $staff_accuracy_stmt = $pdo->prepare("
        SELECT 
            staff_name,
            COUNT(*) AS total_validations,
            SUM(CASE WHEN action = 'Approved' THEN 1 ELSE 0 END) AS accurate_count,
            ROUND((SUM(CASE WHEN action = 'Approved' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) AS accuracy
        FROM validation_actions_log
        WHERE station_id = ? AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY staff_id, staff_name
        ORDER BY accuracy DESC
        LIMIT 10
    ");
    $staff_accuracy_stmt->execute([$station_id]);
    $data['staff_accuracy'] = $staff_accuracy_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Line Chart: Task completion rate (last 7 days)
    $task_completion_stmt = $pdo->prepare("
        SELECT 
            DATE(st.created_at) AS date,
            COUNT(*) AS total_tasks,
            SUM(CASE WHEN st.status = 'completed' THEN 1 ELSE 0 END) AS completed_tasks,
            ROUND((SUM(CASE WHEN st.status = 'completed' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) AS completion_rate
        FROM staff_tasks st
        JOIN users u ON st.user_id = u.id
        WHERE u.station_id = ? AND DATE(st.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY DATE(st.created_at)
        ORDER BY DATE(st.created_at)
    ");
    $task_completion_stmt->execute([$station_id]);
    $data['task_completion'] = $task_completion_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Alerts: Validation errors flagged (Adjusted or Rejected)
    $validation_errors_stmt = $pdo->prepare("
        SELECT 
            staff_name,
            action AS action_type,
            remarks AS action_details,
            created_at
        FROM validation_actions_log
        WHERE station_id = ? AND action IN ('Adjusted', 'Rejected') AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $validation_errors_stmt->execute([$station_id]);
    $data['validation_errors'] = $validation_errors_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return $data;
}

// ============================================================
// INITIAL PHP DATA LOAD
// ============================================================
$dashboard_data = fetchDashboardData($pdo, $station_id);

include __DIR__ . '/../partials/header.php';
?>


<style>
/* ── Local aliases map to the Petron system CSS variables from partials/header.php ── */
:root {
    --blue:     var(--petron-blue,    #00264D);  /* Primary brand blue */
    --red:      var(--petron-red,     #CC0000);  /* Accent brand red   */
    --green:    var(--petron-green,   #28A745);  /* Success green      */
    --orange:                                    #FD7E14;
    --yellow:   var(--petron-yellow,  #FFC107);  /* Warning yellow     */
    --gray:     var(--petron-muted,   #666666);  /* Muted gray         */
    --light-bg: var(--bg-main,        #F8F9FA);  /* Page background    */
}

.dashboard-grid {
    display: grid;
    gap: 20px;
    margin-bottom: 24px;
}

/* Summary Cards */
.summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    margin-bottom: 24px;
}

.summary-card {
    background: var(--bg-card, #fff);
    border-radius: 8px;
    padding: 14px 16px;
    box-shadow: 0 2px 6px rgba(0,0,0,.08);
    border-left: 3px solid var(--gray);
    transition: transform .2s, box-shadow .2s;
}

.summary-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,.12);
}

.summary-card-icon {
    font-size: 1.8rem;
    margin-bottom: 8px;
    opacity: .85;
}

.summary-card-value {
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 3px;
    line-height: 1.2;
}

.summary-card-label {
    font-size: 11px;
    text-transform: uppercase;
    color: var(--gray);
    font-weight: 600;
    letter-spacing: .3px;
}

.summary-card-sub {
    font-size: 10px;
    color: var(--gray);
    margin-top: 6px;
}

/* Color variants */
.summary-card.blue { border-left-color: var(--blue); }
.summary-card.blue .summary-card-icon { color: var(--blue); }
.summary-card.blue .summary-card-value { color: var(--blue); }

.summary-card.green { border-left-color: var(--green); }
.summary-card.green .summary-card-icon { color: var(--green); }
.summary-card.green .summary-card-value { color: var(--green); }

.summary-card.orange { border-left-color: var(--orange); }
.summary-card.orange .summary-card-icon { color: var(--orange); }
.summary-card.orange .summary-card-value { color: var(--orange); }

.summary-card.red { border-left-color: var(--red); }
.summary-card.red .summary-card-icon { color: var(--red); }
.summary-card.red .summary-card-value { color: var(--red); }

.summary-card.yellow { border-left-color: var(--yellow); }
.summary-card.yellow .summary-card-icon { color: var(--yellow); }
.summary-card.yellow .summary-card-value { color: var(--yellow); }

/* Graph Cards */
.graph-card {
    background: var(--bg-card, #fff);
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,.08);
    border: 1px solid var(--border-color, #e9ecef);
}

.graph-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    padding-bottom: 12px;
    border-bottom: 2px solid var(--border-color, #e9ecef);
}

.graph-card-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--blue);
    display: flex;
    align-items: center;
    gap: 10px;
}

.graph-card-title i {
    font-size: 1.3rem;
}

.graph-container {
    min-height: 300px;
    position: relative;
}

.graph-container canvas {
    max-height: 400px;
}


/* Alerts */
.alert-card {
    background: rgba(255, 193, 7, 0.12);
    border-left: 4px solid var(--yellow);
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 16px;
    box-shadow: 0 2px 6px rgba(0,0,0,.06);
}

.alert-card.danger {
    background: rgba(204, 0, 0, 0.08);
    border-left-color: var(--red);
}

.alert-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid rgba(0,0,0,.1);
}

.alert-card-title {
    font-weight: 700;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
    color: #721c24;
}

.btn-toggle-alerts {
    background: transparent;
    border: 1px solid #721c24;
    padding: 4px 12px;
    border-radius: 4px;
    font-size: 12px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    color: #721c24;
    transition: all .2s;
}

.btn-toggle-alerts:hover {
    background: #721c24;
    color: #fff;
}

.alert-table-container {
    max-height: 400px;
    overflow-y: auto;
    border-radius: 4px;
    border: 1px solid rgba(0,0,0,.1);
}

.alert-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    background: #fff;
}

.alert-table thead {
    position: sticky;
    top: 0;
    background: #721c24;
    color: #fff;
    z-index: 10;
}

.alert-table thead th {
    padding: 8px 12px;
    text-align: left;
    font-weight: 600;
    font-size: 12px;
    border-bottom: 2px solid #fff;
}

.alert-table tbody tr {
    border-bottom: 1px solid #f1f1f1;
    transition: background .2s;
}

.alert-table tbody tr:hover {
    background: #fff9e6;
}

.alert-table tbody td {
    padding: 8px 12px;
}

.badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.badge-fuel {
    background: #d4edda;
    color: #155724;
}

.badge-merchandise {
    background: #d1ecf1;
    color: #0c5460;
}

.status-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 4px;
    font-weight: 700;
    font-size: 12px;
}

.status-badge.critical {
    background: #dc3545;
    color: #fff;
}

.status-badge.low {
    background: #fd7e14;
    color: #fff;
}

.status-badge.warning {
    background: #ffc107;
    color: #333;
}

.alert-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.alert-list li {
    padding: 6px 0;
    border-bottom: 1px solid rgba(0,0,0,.05);
    font-size: 13px;
}

.alert-list li:last-child {
    border-bottom: none;
}

/* Loading */
.loading-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(255,255,255,.9);
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 12px;
    z-index: 10;
}

.spinner {
    border: 3px solid #f3f3f3;
    border-top: 3px solid var(--blue);
    border-radius: 50%;
    width: 40px;
    height: 40px;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Responsive */
@media (max-width: 768px) {
    .summary-cards {
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    }
    
    .graph-container {
        min-height: 250px;
    }
}
</style>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-gauge-high"></i> Manager Dashboard</h1>
        <div class="sub">Welcome back, <?= $display_name ?> — Real-time operational insights and performance metrics</div>
    </div>
</div>

<?php if ($flash_success): ?>
<div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div>
<?php endif; ?>

<?php if ($flash_error): ?>
<div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>


<!-- Summary Cards -->
<div class="summary-cards">
    <div class="summary-card blue">
        <div class="summary-card-icon"><i class="fas fa-peso-sign"></i></div>
        <div class="summary-card-value">₱<?= number_format($dashboard_data['total_sales'], 2) ?></div>
        <div class="summary-card-label">Total Sales (Today)</div>
        <div class="summary-card-sub">Fuel: ₱<?= number_format($dashboard_data['fuel_sales'], 2) ?> | Merch: ₱<?= number_format($dashboard_data['merch_sales'], 2) ?></div>
    </div>
    
    <div class="summary-card green">
        <div class="summary-card-icon"><i class="fas fa-gas-pump"></i></div>
        <div class="summary-card-value"><?= number_format($dashboard_data['total_fuel_liters']) ?> L</div>
        <div class="summary-card-label">Fuel Stock</div>
        <div class="summary-card-sub"><?= count($dashboard_data['fuel_stock']) ?> fuel types</div>
    </div>
    
    <div class="summary-card orange">
        <div class="summary-card-icon"><i class="fas fa-boxes"></i></div>
        <div class="summary-card-value"><?= number_format($dashboard_data['merch_inventory_stock']) ?></div>
        <div class="summary-card-label">Merchandise Inventory</div>
        <div class="summary-card-sub"><?= $dashboard_data['merch_inventory_items'] ?> active items</div>
    </div>
    
    <div class="summary-card yellow">
        <div class="summary-card-icon"><i class="fas fa-truck"></i></div>
        <div class="summary-card-value"><?= $dashboard_data['pending_deliveries'] ?></div>
        <div class="summary-card-label">Pending Deliveries</div>
        <div class="summary-card-sub">Awaiting validation</div>
    </div>
    
    <div class="summary-card red">
        <div class="summary-card-icon"><i class="fas fa-users"></i></div>
        <div class="summary-card-value"><?= $dashboard_data['active_staff'] ?></div>
        <div class="summary-card-label">Active Staff</div>
        <div class="summary-card-sub">Currently clocked in</div>
    </div>
</div>

<!-- Low Stock Alerts -->
<?php if (!empty($dashboard_data['low_stock_alerts'])): ?>
<div class="alert-card danger">
    <div class="alert-card-header">
        <div class="alert-card-title">
            <i class="fas fa-exclamation-triangle"></i>
            Low Stock Alerts (<?= count($dashboard_data['low_stock_alerts']) ?> items)
        </div>
        <button type="button" class="btn-toggle-alerts" onclick="toggleAlerts()">
            <span id="alertToggleText">Show All</span>
            <i class="fas fa-chevron-down" id="alertToggleIcon"></i>
        </button>
    </div>
    
    <!-- Critical Items (First 5) -->
    <div class="alert-table-container" id="alertsCritical">
        <table class="alert-table">
            <thead>
                <tr>
                    <th>Product Name</th>
                    <th>Type</th>
                    <th>Current</th>
                    <th>Reorder Level</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($dashboard_data['low_stock_alerts'], 0, 5) as $item): 
                    $reorder_level = max(1, $item['reorder_level']); // Prevent division by zero
                    $percentage = ($item['stock_level'] / $reorder_level) * 100;
                    $status_class = $percentage <= 25 ? 'critical' : ($percentage <= 50 ? 'low' : 'warning');
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($item['product_name']) ?></strong></td>
                    <td><span class="badge badge-<?= strtolower($item['item_type']) ?>"><?= htmlspecialchars($item['item_type']) ?></span></td>
                    <td><?= number_format($item['stock_level']) ?></td>
                    <td><?= number_format($item['reorder_level']) ?></td>
                    <td><span class="status-badge <?= $status_class ?>"><?= round($percentage) ?>%</span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- All Items (Hidden by default) -->
    <?php if (count($dashboard_data['low_stock_alerts']) > 5): ?>
    <div class="alert-table-container" id="alertsAll" style="display: none;">
        <table class="alert-table">
            <thead>
                <tr>
                    <th>Product Name</th>
                    <th>Type</th>
                    <th>Current</th>
                    <th>Reorder Level</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dashboard_data['low_stock_alerts'] as $item): 
                    $reorder_level = max(1, $item['reorder_level']); // Prevent division by zero
                    $percentage = ($item['stock_level'] / $reorder_level) * 100;
                    $status_class = $percentage <= 25 ? 'critical' : ($percentage <= 50 ? 'low' : 'warning');
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($item['product_name']) ?></strong></td>
                    <td><span class="badge badge-<?= strtolower($item['item_type']) ?>"><?= htmlspecialchars($item['item_type']) ?></span></td>
                    <td><?= number_format($item['stock_level']) ?></td>
                    <td><?= number_format($item['reorder_level']) ?></td>
                    <td><span class="status-badge <?= $status_class ?>"><?= round($percentage) ?>%</span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>


<!-- Validation Errors Flagged -->
<?php if (!empty($dashboard_data['validation_errors'])): ?>
<div class="alert-card danger" style="background: #fdf2f2; border-left: 4px solid var(--red); margin-bottom: 24px;">
    <div class="alert-card-header">
        <div class="alert-card-title" style="color: #9b1c1c;">
            <i class="fas fa-exclamation-circle"></i>
            Validation Discrepancies & Flagged Errors (Last 7 Days)
        </div>
    </div>
    <div class="alert-table-container">
        <table class="alert-table" style="font-size: 12px;">
            <thead>
                <tr style="background: #9b1c1c; color: white;">
                    <th>Staff Name</th>
                    <th>Action</th>
                    <th>Details</th>
                    <th>Time</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dashboard_data['validation_errors'] as $err): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($err['staff_name']) ?></strong></td>
                    <td><span class="badge" style="background: #fde8e8; color: #9b1c1c;"><?= htmlspecialchars($err['action_type']) ?></span></td>
                    <td><?= htmlspecialchars($err['action_details']) ?></td>
                    <td><?= date('M d, Y h:i A', strtotime($err['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>


<!-- Graphs Section -->
<div class="dashboard-grid" style="grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));">
    
    <!-- TRANSACTIONS: Payment Method Distribution (Pie Chart) -->
    <div class="graph-card">
        <div class="graph-card-header">
            <div class="graph-card-title">
                <i class="fas fa-chart-pie"></i>
                Payment Method Distribution (Today)
            </div>
        </div>
        <div class="graph-container">
            <canvas id="paymentDistChart"></canvas>
        </div>
    </div>
    
    <!-- TRANSACTIONS: Daily Sales Trend (Bar Chart) -->
    <div class="graph-card">
        <div class="graph-card-header">
            <div class="graph-card-title">
                <i class="fas fa-chart-bar"></i>
                Daily Sales by Payment Method (Last 7 Days)
            </div>
        </div>
        <div class="graph-container">
            <canvas id="paymentTrendChart"></canvas>
        </div>
    </div>
    
    <!-- TRANSACTIONS: Revenue Trend (Line Chart) -->
    <div class="graph-card" style="grid-column: 1 / -1;">
        <div class="graph-card-header">
            <div class="graph-card-title">
                <i class="fas fa-chart-line"></i>
                Revenue Trend (Last 30 Days)
            </div>
        </div>
        <div class="graph-container">
            <canvas id="revenueTrendChart"></canvas>
        </div>
    </div>
    
    <!-- FUEL: Tank Levels (Gauge / Bar Chart) -->
    <div class="graph-card">
        <div class="graph-card-header">
            <div class="graph-card-title">
                <i class="fas fa-gas-pump"></i>
                Current Tank Stock Levels
            </div>
        </div>
        <div class="graph-container">
            <canvas id="tankLevelsChart"></canvas>
        </div>
    </div>
    
    <!-- FUEL: Liters Sold by Type (Bar Chart) -->
    <div class="graph-card">
        <div class="graph-card-header">
            <div class="graph-card-title">
                <i class="fas fa-gas-pump"></i>
                Fuel Sold by Type (Today)
            </div>
        </div>
        <div class="graph-container">
            <canvas id="fuelSoldChart"></canvas>
        </div>
    </div>

    <!-- FUEL: Variance Trend (Line Chart) -->
    <div class="graph-card" style="grid-column: 1 / -1;">
        <div class="graph-card-header">
            <div class="graph-card-title">
                <i class="fas fa-balance-scale"></i>
                Expected vs Actual Pump Variance (Last 7 Days)
            </div>
        </div>
        <div class="graph-container">
            <canvas id="fuelVarianceChart"></canvas>
        </div>
    </div>
    
    <!-- DELIVERIES: Status Breakdown (Pie Chart) -->
    <div class="graph-card">
        <div class="graph-card-header">
            <div class="graph-card-title">
                <i class="fas fa-truck-loading"></i>
                Delivery Status Breakdown
            </div>
        </div>
        <div class="graph-container">
            <canvas id="deliveryStatusChart"></canvas>
        </div>
    </div>
    
    <!-- DELIVERIES: PO vs Actual (Stacked Bar Chart) -->
    <div class="graph-card">
        <div class="graph-card-header">
            <div class="graph-card-title">
                <i class="fas fa-file-invoice"></i>
                PO vs Actual Quantities (Last 10 Deliveries)
            </div>
        </div>
        <div class="graph-container">
            <canvas id="poVsActualChart"></canvas>
        </div>
    </div>

    <!-- DELIVERIES: Supplier Performance (Line Chart) -->
    <div class="graph-card" style="grid-column: 1 / -1;">
        <div class="graph-card-header">
            <div class="graph-card-title">
                <i class="fas fa-truck"></i>
                Supplier Performance On-Time vs Delayed Delivery
            </div>
        </div>
        <div class="graph-container">
            <canvas id="supplierPerformanceChart"></canvas>
        </div>
    </div>
    
    <!-- INVENTORY: Stock Movement (Bar Chart) -->
    <div class="graph-card">
        <div class="graph-card-header">
            <div class="graph-card-title">
                <i class="fas fa-warehouse"></i>
                Stock Movement (Top 10 Items)
            </div>
        </div>
        <div class="graph-container">
            <canvas id="stockMovementChart"></canvas>
        </div>
    </div>

    <!-- INVENTORY: Inventory Trend (Line Chart) -->
    <div class="graph-card">
        <div class="graph-card-header">
            <div class="graph-card-title">
                <i class="fas fa-chart-line"></i>
                Inventory Stock-In / Stock-Out Trends (Last 30 Days)
            </div>
        </div>
        <div class="graph-container">
            <canvas id="inventoryTrendChart"></canvas>
        </div>
    </div>
    
    <!-- CUSTOMERS: Purchase Distribution (Pie Chart) -->
    <div class="graph-card">
        <div class="graph-card-header">
            <div class="graph-card-title">
                <i class="fas fa-users"></i>
                Customer Purchase Distribution
            </div>
        </div>
        <div class="graph-container">
            <canvas id="purchaseDistChart"></canvas>
        </div>
    </div>

    <!-- CUSTOMERS: Top Customers by Volume (Bar Chart) -->
    <div class="graph-card">
        <div class="graph-card-header">
            <div class="graph-card-title">
                <i class="fas fa-trophy"></i>
                Top 10 Customers by Purchase Volume
            </div>
        </div>
        <div class="graph-container">
            <canvas id="topCustomersChart"></canvas>
        </div>
    </div>

    <!-- CUSTOMERS: Complaints/Returns Trend (Line Chart) -->
    <div class="graph-card" style="grid-column: 1 / -1;">
        <div class="graph-card-header">
            <div class="graph-card-title">
                <i class="fas fa-comment-slash"></i>
                Customer Complaints / Issues Trend (Last 30 Days)
            </div>
        </div>
        <div class="graph-container">
            <canvas id="complaintsTrendChart"></canvas>
        </div>
    </div>
    
    <!-- STAFF: Encoding Accuracy (Bar Chart) -->
    <div class="graph-card">
        <div class="graph-card-header">
            <div class="graph-card-title">
                <i class="fas fa-user-check"></i>
                Staff Encoding Accuracy (Last 7 Days)
            </div>
        </div>
        <div class="graph-container">
            <canvas id="staffAccuracyChart"></canvas>
        </div>
    </div>

    <!-- STAFF: Task Completion Rate (Line Chart) -->
    <div class="graph-card">
        <div class="graph-card-header">
            <div class="graph-card-title">
                <i class="fas fa-tasks"></i>
                Staff Task Completion Rate (Last 7 Days)
            </div>
        </div>
        <div class="graph-container">
            <canvas id="taskCompletionChart"></canvas>
        </div>
    </div>
    
</div>

<!-- Include Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>


<script>
// Dashboard data from PHP
const dashboardData = <?= json_encode($dashboard_data) ?>;

// ── Chart.js global defaults — aligned with Petron System CSS variables ─────
Chart.defaults.font.family = "'Inter', -apple-system, BlinkMacSystemFont, sans-serif";
Chart.defaults.font.size = 12;

// Detect active theme and apply matching label / grid colors
const _isDark   = document.body.classList.contains('dark-theme');
const _labelClr = _isDark ? '#94a3b8' : '#666666'; // --text-secondary
const _gridClr  = _isDark ? 'rgba(255,255,255,0.07)' : 'rgba(0,0,0,0.06)';
Chart.defaults.color                    = _labelClr;
Chart.defaults.scale.grid.color         = _gridClr;
Chart.defaults.scale.grid.borderColor   = _gridClr;

// ── Petron System Color Palette ───────────────────────────────────────────────
// All values mirror the :root CSS variables in partials/header.php
const colors = {
    blue:      '#00264D',  // --petron-blue   (primary brand)
    red:       '#CC0000',  // --petron-red    (accent brand)
    green:     '#28A745',  // --petron-green  (success)
    yellow:    '#FFC107',  // --petron-yellow (warning)
    danger:    '#DC3545',  // --petron-danger (error)
    info:      '#17A2B8',  // --petron-info
    orange:    '#FD7E14',  // orange accent
    purple:    '#6F42C1',  // purple accent
    pink:      '#E83E8C',  // pink accent
    gray:      '#666666',  // --petron-muted
};

// ─── TRANSACTIONS: Payment Distribution (Pie Chart) ─────────────────────────
const paymentDistData = dashboardData.payment_distribution;
const paymentLabels = paymentDistData.map(d => d.payment_method);
const paymentValues = paymentDistData.map(d => parseFloat(d.total));

new Chart(document.getElementById('paymentDistChart'), {
    type: 'pie',
    data: {
        labels: paymentLabels,
        datasets: [{
            data: paymentValues,
            backgroundColor: [colors.blue, colors.green, colors.orange, colors.purple, colors.info, colors.pink],
            borderWidth: 2,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { position: 'bottom' },
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


// ─── TRANSACTIONS: Daily Sales Trend (Bar Chart) ────────────────────────────
const paymentTrendData = dashboardData.payment_trend;
const trendDates = paymentTrendData.map(d => new Date(d.date).toLocaleDateString('en-US', {month: 'short', day: 'numeric'}));
const cashData = paymentTrendData.map(d => parseFloat(d.cash));
const cardData = paymentTrendData.map(d => parseFloat(d.card));
const ewalletData = paymentTrendData.map(d => parseFloat(d.ewallet));

new Chart(document.getElementById('paymentTrendChart'), {
    type: 'bar',
    data: {
        labels: trendDates,
        datasets: [
            { label: 'Cash', data: cashData, backgroundColor: colors.green, borderWidth: 0 },
            { label: 'Card', data: cardData, backgroundColor: colors.blue, borderWidth: 0 },
            { label: 'E-Wallet', data: ewalletData, backgroundColor: colors.orange, borderWidth: 0 }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { position: 'top' },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.dataset.label + ': ₱' + context.parsed.y.toLocaleString('en-PH', {minimumFractionDigits: 2});
                    }
                }
            }
        },
        scales: {
            x: { stacked: false },
            y: { stacked: false, beginAtZero: true, ticks: { callback: v => '₱' + v.toLocaleString() } }
        }
    }
});

// ─── TRANSACTIONS: Revenue Trend (Line Chart) ───────────────────────────────
const revenueTrendData = dashboardData.revenue_trend;
const revenueDates = revenueTrendData.map(d => new Date(d.date).toLocaleDateString('en-US', {month: 'short', day: 'numeric'}));
const revenueValues = revenueTrendData.map(d => parseFloat(d.revenue));

new Chart(document.getElementById('revenueTrendChart'), {
    type: 'line',
    data: {
        labels: revenueDates,
        datasets: [{
            label: 'Total Revenue',
            data: revenueValues,
            borderColor: colors.blue,
            backgroundColor: colors.blue + '20',
            borderWidth: 3,
            fill: true,
            tension: 0.4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return 'Revenue: ₱' + context.parsed.y.toLocaleString('en-PH', {minimumFractionDigits: 2});
                    }
                }
            }
        },
        scales: {
            y: { beginAtZero: true, ticks: { callback: v => '₱' + v.toLocaleString() } }
        }
    }
});


// ─── FUEL: Tank Levels (Bar Chart) ──────────────────────────────────────────
const tankData = dashboardData.tank_levels;
const tankLabels = tankData.map(d => d.fuel_type);
const tankLevels = tankData.map(d => parseFloat(d.current_stock));
const tankCapacities = tankData.map(d => parseFloat(d.capacity));

new Chart(document.getElementById('tankLevelsChart'), {
    type: 'bar',
    data: {
        labels: tankLabels,
        datasets: [
            { label: 'Current Stock', data: tankLevels, backgroundColor: colors.blue, borderWidth: 0 },
            { label: 'Capacity', data: tankCapacities, backgroundColor: colors.green + '40', borderWidth: 1, borderColor: colors.green }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { position: 'top' },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.dataset.label + ': ' + context.parsed.y.toLocaleString() + ' L';
                    }
                }
            }
        },
        scales: {
            y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString() + ' L' } }
        }
    }
});

// ─── FUEL: Liters Sold by Type (Bar Chart) ──────────────────────────────────
const fuelSoldData = dashboardData.fuel_sold_by_type;
const fuelTypes = fuelSoldData.map(d => d.fuel_type);
const litersSold = fuelSoldData.map(d => parseFloat(d.liters_sold));

new Chart(document.getElementById('fuelSoldChart'), {
    type: 'bar',
    data: {
        labels: fuelTypes,
        datasets: [{
            label: 'Liters Sold',
            data: litersSold,
            backgroundColor: colors.orange,
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return 'Sold: ' + context.parsed.y.toLocaleString() + ' L';
                    }
                }
            }
        },
        scales: {
            y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString() + ' L' } }
        }
    }
});


// ─── DELIVERIES: Status Breakdown (Pie Chart) ───────────────────────────────
const deliveryStatusData = dashboardData.delivery_status_breakdown;
const deliveryLabels = deliveryStatusData.map(d => d.delivery_status);
const deliveryCounts = deliveryStatusData.map(d => parseInt(d.count));

new Chart(document.getElementById('deliveryStatusChart'), {
    type: 'pie',
    data: {
        labels: deliveryLabels,
        datasets: [{
            data: deliveryCounts,
            backgroundColor: [colors.green, colors.yellow, colors.orange, colors.red, colors.purple],
            borderWidth: 2,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { position: 'bottom' }
        }
    }
});

// ─── DELIVERIES: PO vs Actual (Bar Chart) ───────────────────────────────────
const poVsActualData = dashboardData.po_vs_actual;
const deliveryRefs = poVsActualData.map(d => d.delivery_ref);
const expectedQty = poVsActualData.map(d => parseFloat(d.expected_quantity));
const actualQty = poVsActualData.map(d => parseFloat(d.actual_quantity));
const damagedQty = poVsActualData.map(d => parseFloat(d.damaged_quantity || 0));

new Chart(document.getElementById('poVsActualChart'), {
    type: 'bar',
    data: {
        labels: deliveryRefs,
        datasets: [
            { label: 'Expected', data: expectedQty, backgroundColor: colors.blue, borderWidth: 0 },
            { label: 'Actual', data: actualQty, backgroundColor: colors.green, borderWidth: 0 },
            { label: 'Damaged', data: damagedQty, backgroundColor: colors.red, borderWidth: 0 }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { position: 'top' }
        },
        scales: {
            y: { beginAtZero: true }
        }
    }
});

// ─── INVENTORY: Stock Movement (Bar Chart) ──────────────────────────────────
const stockMovementData = dashboardData.stock_movement;
const productNames = stockMovementData.map(d => d.product_name);
const stockIn = stockMovementData.map(d => parseFloat(d.stock_in));
const stockOut = stockMovementData.map(d => parseFloat(d.stock_out));

new Chart(document.getElementById('stockMovementChart'), {
    type: 'bar',
    data: {
        labels: productNames,
        datasets: [
            { label: 'Stock-In', data: stockIn, backgroundColor: colors.green, borderWidth: 0 },
            { label: 'Stock-Out', data: stockOut, backgroundColor: colors.red, borderWidth: 0 }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        indexAxis: 'y',
        plugins: {
            legend: { position: 'top' }
        },
        scales: {
            x: { beginAtZero: true }
        }
    }
});


// ─── CUSTOMERS: Purchase Distribution (Pie Chart) ───────────────────────────
const purchaseDistData = dashboardData.purchase_distribution;
const purchaseLabels = purchaseDistData.map(d => d.category);
const purchaseValues = purchaseDistData.map(d => parseFloat(d.total));

new Chart(document.getElementById('purchaseDistChart'), {
    type: 'pie',
    data: {
        labels: purchaseLabels,
        datasets: [{
            data: purchaseValues,
            backgroundColor: [colors.blue, colors.orange],
            borderWidth: 2,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { position: 'bottom' },
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

// ─── STAFF: Encoding Accuracy (Bar Chart) ───────────────────────────────────
const staffAccuracyData = dashboardData.staff_accuracy;
const staffNames = staffAccuracyData.map(d => d.staff_name);
const accuracyRates = staffAccuracyData.map(d => parseFloat(d.accuracy));

new Chart(document.getElementById('staffAccuracyChart'), {
    type: 'bar',
    data: {
        labels: staffNames,
        datasets: [{
            label: 'Accuracy Rate (%)',
            data: accuracyRates,
            backgroundColor: accuracyRates.map(a => a >= 95 ? colors.green : a >= 85 ? colors.yellow : colors.danger),
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return 'Accuracy: ' + context.parsed.y.toFixed(2) + '%';
                    }
                }
            }
        },
        scales: {
            y: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } }
        }
    }
});


// ─── FUEL: Variance Trend (Line Chart) ──────────────────────────────────────
const varianceData = dashboardData.fuel_variance_trend || [];
const varianceDates = [...new Set(varianceData.map(d => new Date(d.date).toLocaleDateString('en-US', {month: 'short', day: 'numeric'})))];
const fuelTypesList = [...new Set(varianceData.map(d => d.fuel_type))];

const varianceDatasets = fuelTypesList.map((type, idx) => {
    const typeColors = [colors.blue, colors.orange, colors.green, colors.purple, colors.pink];
    const dataPoints = varianceDates.map(dateStr => {
        const entry = varianceData.find(d => new Date(d.date).toLocaleDateString('en-US', {month: 'short', day: 'numeric'}) === dateStr && d.fuel_type === type);
        return entry ? parseFloat(entry.variance) : 0;
    });
    return {
        label: type + ' Variance',
        data: dataPoints,
        borderColor: typeColors[idx % typeColors.length],
        backgroundColor: 'transparent',
        borderWidth: 2,
        tension: 0.3
    };
});

new Chart(document.getElementById('fuelVarianceChart'), {
    type: 'line',
    data: {
        labels: varianceDates,
        datasets: varianceDatasets
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { position: 'bottom' },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.dataset.label + ': ' + context.parsed.y.toLocaleString() + ' L';
                    }
                }
            }
        },
        scales: {
            y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString() + ' L' } }
        }
    }
});


// ─── DELIVERIES: Supplier Performance (Bar Chart) ────────────────────────────
const supplierData = dashboardData.supplier_performance || [];
const suppliers = supplierData.map(d => d.supplier);
const onTimeData = supplierData.map(d => parseInt(d.on_time));
const delayedData = supplierData.map(d => parseInt(d.delayed));

new Chart(document.getElementById('supplierPerformanceChart'), {
    type: 'bar',
    data: {
        labels: suppliers,
        datasets: [
            { label: 'On-Time', data: onTimeData, backgroundColor: colors.green },
            { label: 'Delayed', data: delayedData, backgroundColor: colors.red }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { position: 'top' }
        },
        scales: {
            x: { stacked: true },
            y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } }
        }
    }
});


// ─── INVENTORY: Inventory Trend (Line Chart) ─────────────────────────────────
const invTrendData = dashboardData.inventory_trend || [];
const invDates = invTrendData.map(d => new Date(d.date).toLocaleDateString('en-US', {month: 'short', day: 'numeric'}));
const invStockIn = invTrendData.map(d => parseFloat(d.stock_in));
const invStockOut = invTrendData.map(d => parseFloat(d.stock_out));

new Chart(document.getElementById('inventoryTrendChart'), {
    type: 'line',
    data: {
        labels: invDates,
        datasets: [
            { label: 'Stock-In', data: invStockIn, borderColor: colors.green, backgroundColor: colors.green + '10', fill: true, tension: 0.3 },
            { label: 'Stock-Out', data: invStockOut, borderColor: colors.red, backgroundColor: colors.red + '10', fill: true, tension: 0.3 }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { position: 'top' }
        },
        scales: {
            y: { beginAtZero: true }
        }
    }
});


// ─── CUSTOMERS: Top Customers (Bar Chart) ────────────────────────────────────
const topCustData = dashboardData.top_customers || [];
const custNames = topCustData.map(d => d.customer_name);
const custPurchases = topCustData.map(d => parseFloat(d.total_purchases));

new Chart(document.getElementById('topCustomersChart'), {
    type: 'bar',
    data: {
        labels: custNames,
        datasets: [{
            label: 'Total Purchases',
            data: custPurchases,
            backgroundColor: colors.blue,
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        indexAxis: 'y',
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return 'Purchases: ₱' + context.parsed.x.toLocaleString('en-PH', {minimumFractionDigits: 2});
                    }
                }
            }
        },
        scales: {
            x: { beginAtZero: true, ticks: { callback: v => '₱' + v.toLocaleString() } }
        }
    }
});


// ─── CUSTOMERS: Complaints/Returns Trend (Line Chart) ────────────────────────
const complaintsData = dashboardData.complaints_trend || [];
const complaintsDates = complaintsData.map(d => new Date(d.date).toLocaleDateString('en-US', {month: 'short', day: 'numeric'}));
const complaintsCounts = complaintsData.map(d => parseInt(d.count));

new Chart(document.getElementById('complaintsTrendChart'), {
    type: 'line',
    data: {
        labels: complaintsDates,
        datasets: [{
            label: 'Issues Reported',
            data: complaintsCounts,
            borderColor: colors.red,
            backgroundColor: colors.red + '10',
            borderWidth: 2,
            fill: true,
            tension: 0.3
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { display: false }
        },
        scales: {
            y: { beginAtZero: true, ticks: { precision: 0 } }
        }
    }
});


// ─── STAFF: Task Completion Rate (Line Chart) ───────────────────────────────
const taskCompData = dashboardData.task_completion || [];
const taskDates = taskCompData.map(d => new Date(d.date).toLocaleDateString('en-US', {month: 'short', day: 'numeric'}));
const taskCompletionRates = taskCompData.map(d => parseFloat(d.completion_rate));

new Chart(document.getElementById('taskCompletionChart'), {
    type: 'line',
    data: {
        labels: taskDates,
        datasets: [{
            label: 'Completion Rate (%)',
            data: taskCompletionRates,
            borderColor: colors.green,
            backgroundColor: colors.green + '10',
            borderWidth: 2,
            fill: true,
            tension: 0.3
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return 'Completion Rate: ' + context.parsed.y.toFixed(1) + '%';
                    }
                }
            }
        },
        scales: {
            y: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } }
        }
    }
});

// ─── Auto-refresh every 5 minutes ────────────────────────────────────────────
setInterval(function() {
    location.reload();
}, 300000); // 5 minutes

// ─── Toggle Low Stock Alerts ─────────────────────────────────────────────────
function toggleAlerts() {
    const criticalDiv = document.getElementById('alertsCritical');
    const allDiv = document.getElementById('alertsAll');
    const toggleText = document.getElementById('alertToggleText');
    const toggleIcon = document.getElementById('alertToggleIcon');
    
    if (allDiv.style.display === 'none') {
        // Show all items
        criticalDiv.style.display = 'none';
        allDiv.style.display = 'block';
        toggleText.textContent = 'Show Less';
        toggleIcon.classList.remove('fa-chevron-down');
        toggleIcon.classList.add('fa-chevron-up');
    } else {
        // Show only critical (first 5)
        criticalDiv.style.display = 'block';
        allDiv.style.display = 'none';
        toggleText.textContent = 'Show All';
        toggleIcon.classList.remove('fa-chevron-up');
        toggleIcon.classList.add('fa-chevron-down');
    }
}

</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
