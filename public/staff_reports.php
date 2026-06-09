<?php
/**
 * STAFF REPORTS & ADD-ONS MODULE
 * Professional implementation matching Manager Reports theme and styling.
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? 'staff');
$user_id    = (int)($me['id'] ?? 0);
$station_id = user_station_id();

if (!in_array($role, ['staff', 'cashier', 'pump_attendant', 'manager', 'admin', 'superadmin', 'developer'])) {
    header('Location: dashboard.php'); exit;
}

// Module gate
if (!in_array($role, ['superadmin','developer']) && !is_module_enabled('reports')) {
    render_module_disabled_page('Reports');
}

if (!$station_id) {
    die('Error: You are not assigned to a station.');
}

// Helper: check columns dynamically to prevent runtime query crashes
function has_col(PDO $pdo, string $table, string $col): bool {
    try {
        $r = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
        return $r && $r->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

// Get Station Name
$station_name = 'Station';
try {
    $s = $pdo->prepare("SELECT name FROM stations WHERE id=? LIMIT 1");
    $s->execute([$station_id]);
    $st = $s->fetch(PDO::FETCH_ASSOC);
    if ($st) $station_name = $st['name'];
} catch (Exception $e) {}

// ============================================================
// SECTION & DATE RANGE LOGIC
// ============================================================
$valid_sections = ['sales', 'job_orders', 'deliveries', 'meter', 'payments', 'customers', 'activity'];
$section = trim($_GET['section'] ?? 'sales');
if (!in_array($section, $valid_sections)) {
    // Check if legacy view parameter is used
    $view_param = trim($_GET['view'] ?? '');
    $legacy_map = [
        'daily_sales' => 'sales',
        'customer_linkage' => 'sales',
        'jo_tracker' => 'job_orders',
        'fuel_deliveries' => 'deliveries',
        'merch_deliveries' => 'deliveries',
        'inventory_movement' => 'deliveries',
        'meter_readings' => 'meter',
        'payment_status' => 'payments',
        'customer_reports' => 'customers',
        'personal_activity' => 'activity',
        'audit_trail' => 'activity',
    ];
    $section = $legacy_map[$view_param] ?? 'sales';
}

$page_id = match($section) {
    'job_orders' => 'report_jo_tracker',
    'deliveries' => 'report_deliveries',
    'meter'      => 'report_meter',
    'payments'   => 'report_payments',
    'customers'  => 'report_customers',
    'activity'   => 'report_activity',
    default      => 'report_daily_sales',
};

$range = strtolower(trim($_GET['range'] ?? 'month'));
if (!in_array($range, ['today', 'week', 'month', 'custom'])) $range = 'month';

$sub_tab = trim($_GET['sub_tab'] ?? $_GET['sub'] ?? '');
if (empty($sub_tab)) {
    if ($section === 'sales') $sub_tab = 'daily_summary';
    elseif ($section === 'job_orders') $sub_tab = 'jo_list';
    elseif ($section === 'deliveries') $sub_tab = 'fuel_deliveries';
    elseif ($section === 'meter') $sub_tab = 'readings';
    elseif ($section === 'payments') $sub_tab = 'status_breakdown';
    elseif ($section === 'customers') $sub_tab = 'customer_list';
    elseif ($section === 'activity') $sub_tab = 'staff_activity';
}

$today = date('Y-m-d');
switch ($range) {
    case 'week':
        $date_start = date('Y-m-d', strtotime('monday this week'));
        $date_end   = date('Y-m-d', strtotime('sunday this week'));
        break;
    case 'month':
        $date_start = date('Y-m-01');
        $date_end   = date('Y-m-t');
        break;
    case 'custom':
        $date_start = trim($_GET['start'] ?? $_GET['date_from'] ?? $today);
        $date_end   = trim($_GET['end'] ?? $_GET['date_to'] ?? $today);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_start)) $date_start = $today;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_end))   $date_end   = $today;
        if ($date_end < $date_start) $date_end = $date_start;
        break;
    default: // today
        $date_start = $today;
        $date_end   = $today;
        break;
}

$report_data = [];
$summary_cards = [];
$report_error = '';

// ============================================================
// DATA FETCHING CONDITIONALS
// ============================================================
try {
    if ($section === 'sales') {
        if ($sub_tab === 'daily_summary') {
            // Try to query sales table first
            $report_data = [];
            try {
                $tables = $pdo->query("SHOW TABLES LIKE 'sales'")->fetchAll();
                if (!empty($tables)) {
                    $stmt = $pdo->prepare("
                        SELECT DATE(s.sale_date) AS sale_date,
                               COUNT(*) AS transaction_count,
                               SUM(s.total) AS total_sales,
                               SUM(CASE WHEN s.payment_method IN ('Cash','cash') THEN s.total ELSE 0 END) AS cash_sales,
                               SUM(CASE WHEN s.payment_method IN ('Credit Card','Card','card') THEN s.total ELSE 0 END) AS card_sales,
                               SUM(CASE WHEN s.payment_method IN ('GCash','Maya','E-Wallet','ewallet') THEN s.total ELSE 0 END) AS ewallet_sales,
                               SUM(CASE WHEN s.payment_method IN ('Credit','Account Receivable','utang','Utang') THEN s.total ELSE 0 END) AS credit_sales
                        FROM sales s
                        WHERE s.station_id = ? AND s.user_id = ?
                          AND s.sale_date BETWEEN ? AND ?
                        GROUP BY DATE(s.sale_date)
                        ORDER BY sale_date DESC
                    ");
                    $stmt->execute([$station_id, $user_id, $date_start, $date_end]);
                    $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                }
            } catch (Exception $e) {
                $report_data = [];
            }

            // Fallback to merchandise_transactions
            if (empty($report_data)) {
                try {
                    $stmt = $pdo->prepare("
                        SELECT DATE(created_at) AS sale_date,
                               COUNT(*) AS transaction_count,
                               SUM(total_amount) AS total_sales,
                               SUM(CASE WHEN payment_method IN ('Cash','cash') THEN total_amount ELSE 0 END) AS cash_sales,
                               SUM(CASE WHEN payment_method IN ('Credit Card','Card','card') THEN total_amount ELSE 0 END) AS card_sales,
                               SUM(CASE WHEN payment_method IN ('E-Wallet','GCash','Maya','ewallet') THEN total_amount ELSE 0 END) AS ewallet_sales,
                               SUM(CASE WHEN payment_method IN ('Credit','Account Receivable','utang','Utang') THEN total_amount ELSE 0 END) AS credit_sales
                        FROM merchandise_transactions
                        WHERE station_id = ? AND staff_id = ? AND DATE(created_at) BETWEEN ? AND ?
                        GROUP BY DATE(created_at)
                        ORDER BY sale_date DESC
                    ");
                    $stmt->execute([$station_id, $user_id, $date_start, $date_end]);
                    $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (Exception $e) {
                    $report_data = [];
                }
            }

            $total = array_sum(array_column($report_data, 'total_sales'));
            $txn_count = array_sum(array_column($report_data, 'transaction_count'));
            $avg_daily = count($report_data) > 0 ? $total / count($report_data) : 0;

            $summary_cards = [
                ['label' => 'Total Sales', 'value' => '₱' . number_format($total, 2), 'icon' => 'fa-wallet', 'class' => 'stat-blue'],
                ['label' => 'Transactions', 'value' => number_format($txn_count), 'icon' => 'fa-file-invoice-dollar', 'class' => 'stat-red'],
                ['label' => 'Avg Daily Sales', 'value' => '₱' . number_format($avg_daily, 2), 'icon' => 'fa-chart-line', 'class' => 'stat-green'],
            ];
        } elseif ($sub_tab === 'customer_linkage') {
            // Check if sales table exists and query it first
            $report_data = [];
            try {
                $tables = $pdo->query("SHOW TABLES LIKE 'sales'")->fetchAll();
                if (!empty($tables)) {
                    $stmt = $pdo->prepare("
                        SELECT s.id AS sale_id,
                               COALESCE(s.customer, c.name, 'Walk-in') AS customer_name,
                               s.total AS total_amount,
                               s.payment_method,
                               s.sale_date AS created_at,
                               COALESCE(s.status, 'completed') AS status
                        FROM sales s
                        LEFT JOIN customers c ON s.customer_id = c.id
                        WHERE s.station_id = ? AND s.user_id = ?
                          AND s.sale_date BETWEEN ? AND ?
                        ORDER BY s.sale_date DESC
                    ");
                    $stmt->execute([$station_id, $user_id, $date_start, $date_end]);
                    $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                }
            } catch (Exception $e) {
                $report_data = [];
            }

            // Fallback to merchandise_transactions if no sales data
            if (empty($report_data)) {
                // Check if customer_id column exists in merchandise_transactions
                $has_customer_id = has_col($pdo, 'merchandise_transactions', 'customer_id');
                
                if ($has_customer_id) {
                    $stmt = $pdo->prepare("
                        SELECT COALESCE(NULLIF(mt.transaction_id,''),CONCAT('MT-',mt.id)) AS sale_id,
                               COALESCE(c.name, mt.customer_name, 'Walk-in') AS customer_name,
                               mt.total_amount,
                               mt.payment_method,
                               mt.created_at,
                               'completed' AS status
                        FROM merchandise_transactions mt
                        LEFT JOIN customers c ON mt.customer_id = c.id
                        WHERE mt.station_id = ? AND mt.staff_id = ? AND DATE(mt.created_at) BETWEEN ? AND ?
                        ORDER BY mt.created_at DESC
                    ");
                } else {
                    // Query without JOIN if customer_id doesn't exist
                    $stmt = $pdo->prepare("
                        SELECT COALESCE(NULLIF(mt.transaction_id,''),CONCAT('MT-',mt.id)) AS sale_id,
                               COALESCE(mt.customer_name, 'Walk-in') AS customer_name,
                               mt.total_amount,
                               mt.payment_method,
                               mt.created_at,
                               'completed' AS status
                        FROM merchandise_transactions mt
                        WHERE mt.station_id = ? AND mt.staff_id = ? AND DATE(mt.created_at) BETWEEN ? AND ?
                        ORDER BY mt.created_at DESC
                    ");
                }
                $stmt->execute([$station_id, $user_id, $date_start, $date_end]);
                $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }

            $total_linked = count(array_filter($report_data, fn($r) => $r['customer_name'] !== 'Walk-in'));
            $total_walkin = count($report_data) - $total_linked;

            $summary_cards = [
                ['label' => 'Linked Customers', 'value' => $total_linked, 'icon' => 'fa-user-check', 'class' => 'stat-blue'],
                ['label' => 'Walk-in Sales', 'value' => $total_walkin, 'icon' => 'fa-walking', 'class' => 'stat-orange'],
                ['label' => 'Total Linked Txns', 'value' => count($report_data), 'icon' => 'fa-database', 'class' => 'stat-green'],
            ];
        }
    }

    if ($section === 'job_orders') {
        $jo_enc = has_col($pdo, 'job_orders', 'created_by') ? 'created_by' : 'user_id';
        $jo_id_col = has_col($pdo,'job_orders','job_order_id') ? "COALESCE(NULLIF(jo.job_order_id,''),CONCAT('JO-',jo.id))" : "CONCAT('JO-',jo.id)";
        $cost_col = has_col($pdo,'job_orders','total_cost') ? 'COALESCE(jo.total_cost,jo.estimated_cost,0)' : 'COALESCE(jo.estimated_cost,0)';
        
        // Check if mechanics table exists and which columns are available
        $mechanic_col = "'—'";
        $mechanic_join = "";
        try {
            $tables = $pdo->query("SHOW TABLES LIKE 'mechanics'")->fetchAll();
            if (!empty($tables)) {
                if (has_col($pdo, 'mechanics', 'full_name')) {
                    $mechanic_col = "COALESCE(m.full_name, '—')";
                    $mechanic_join = "LEFT JOIN mechanics m ON jo.assigned_mechanic_id = m.id";
                } elseif (has_col($pdo, 'mechanics', 'name')) {
                    $mechanic_col = "COALESCE(m.name, '—')";
                    $mechanic_join = "LEFT JOIN mechanics m ON jo.assigned_mechanic_id = m.id";
                }
            }
        } catch (Exception $e) {
            $mechanic_col = "'—'";
            $mechanic_join = "";
        }

        if ($sub_tab === 'jo_list') {
            $stmt = $pdo->prepare("
                SELECT $jo_id_col AS job_order_id,
                       COALESCE(jo.customer_name,'Walk-in') AS customer_name,
                       COALESCE(jo.vehicle_plate,'—') AS vehicle_plate,
                       COALESCE(jo.service_type,'—') AS service_type,
                       COALESCE(jo.status,'Pending') AS status,
                       $cost_col AS total_cost,
                       jo.created_at,
                       $mechanic_col AS assigned_mechanic
                FROM job_orders jo
                $mechanic_join
                WHERE jo.station_id=? AND jo.$jo_enc=? AND DATE(jo.created_at) BETWEEN ? AND ?
                ORDER BY jo.created_at DESC
            ");
            $stmt->execute([$station_id, $user_id, $date_start, $date_end]);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $total_jo = count($report_data);
            $completed = count(array_filter($report_data, fn($r) => strtolower($r['status']) === 'completed'));
            $pending = count(array_filter($report_data, fn($r) => in_array(strtolower($r['status']), ['pending', 'pending validation', 'in progress'])));

            $summary_cards = [
                ['label' => 'Total Job Orders', 'value' => $total_jo, 'icon' => 'fa-wrench', 'class' => 'stat-blue'],
                ['label' => 'Completed Jobs', 'value' => $completed, 'icon' => 'fa-circle-check', 'class' => 'stat-green'],
                ['label' => 'Pending/Active', 'value' => $pending, 'icon' => 'fa-hourglass-half', 'class' => 'stat-orange'],
            ];
        } elseif ($sub_tab === 'staff_perf') {
            $stmt = $pdo->prepare("
                SELECT DATE(jo.created_at) AS work_date,
                       COUNT(*) AS jobs_created,
                       SUM(CASE WHEN jo.status = 'Completed' THEN 1 ELSE 0 END) AS jobs_completed,
                       SUM(CASE WHEN jo.status IN ('Approved','Validated','Complete') THEN 1 ELSE 0 END) AS jobs_approved,
                       SUM(CASE WHEN jo.status = 'Rejected' THEN 1 ELSE 0 END) AS jobs_rejected,
                       AVG(CASE WHEN jo.status = 'Completed' 
                           THEN TIMESTAMPDIFF(HOUR, jo.created_at, jo.updated_at) 
                           ELSE NULL END) AS avg_completion_hours
                FROM job_orders jo
                WHERE jo.station_id=? AND jo.$jo_enc=? AND DATE(jo.created_at) BETWEEN ? AND ?
                GROUP BY DATE(jo.created_at)
                ORDER BY work_date DESC
            ");
            $stmt->execute([$station_id, $user_id, $date_start, $date_end]);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $total_created = array_sum(array_column($report_data, 'jobs_created'));
            $total_completed = array_sum(array_column($report_data, 'jobs_completed'));
            $completion_rate = $total_created > 0 ? ($total_completed / $total_created * 100) : 0;

            $summary_cards = [
                ['label' => 'Jobs Encoded', 'value' => $total_created, 'icon' => 'fa-folder-plus', 'class' => 'stat-blue'],
                ['label' => 'Completed Status', 'value' => $total_completed, 'icon' => 'fa-check-double', 'class' => 'stat-green'],
                ['label' => 'Completion Rate', 'value' => number_format($completion_rate, 1) . '%', 'icon' => 'fa-chart-pie', 'class' => 'stat-purple'],
            ];
        }
    }

    if ($section === 'deliveries') {
        if ($sub_tab === 'fuel_deliveries') {
            // Check if fuel_deliveries table exists
            try {
                $tables = $pdo->query("SHOW TABLES LIKE 'fuel_deliveries'")->fetchAll();
                if (empty($tables)) {
                    $report_data = [];
                    $summary_cards = [
                        ['label' => 'Total Deliveries', 'value' => 0, 'icon' => 'fa-truck-field', 'class' => 'stat-blue'],
                        ['label' => 'Total Liters Received', 'value' => '0.00 L', 'icon' => 'fa-gas-pump', 'class' => 'stat-green'],
                    ];
                } else {
                    // Check if fuel_types and users tables exist
                    $fuel_type_join = "";
                    $fuel_type_col = "fd.fuel_type";
                    try {
                        $ft_tables = $pdo->query("SHOW TABLES LIKE 'fuel_types'")->fetchAll();
                        if (!empty($ft_tables)) {
                            $fuel_type_join = "LEFT JOIN fuel_types ft ON fd.fuel_type = ft.id";
                            $fuel_type_col = "COALESCE(ft.name, fd.fuel_type, 'Unknown')";
                        }
                    } catch (Exception $e) {
                        $fuel_type_join = "";
                        $fuel_type_col = "COALESCE(fd.fuel_type, 'Unknown')";
                    }
                    
                    $user_join = "";
                    $user_col = "'—'";
                    try {
                        $user_tables = $pdo->query("SHOW TABLES LIKE 'users'")->fetchAll();
                        if (!empty($user_tables)) {
                            $user_join = "LEFT JOIN users u ON fd.received_by = u.id";
                            $user_col = "COALESCE(u.name, '—')";
                        }
                    } catch (Exception $e) {
                        $user_join = "";
                        $user_col = "'—'";
                    }
                    
                    $stmt = $pdo->prepare("
                        SELECT CONCAT('FD-',fd.id) AS delivery_ref,
                               fd.supplier,
                               $fuel_type_col AS fuel_type,
                               fd.delivery_liters AS quantity,
                               fd.status,
                               fd.created_at AS delivery_date,
                               $user_col AS received_by
                        FROM fuel_deliveries fd
                        $fuel_type_join
                        $user_join
                        WHERE fd.station_id=? AND DATE(fd.created_at) BETWEEN ? AND ?
                        ORDER BY fd.created_at DESC
                    ");
                    $stmt->execute([$station_id, $date_start, $date_end]);
                    $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                    $total_deliveries = count($report_data);
                    $total_liters = array_sum(array_column($report_data, 'quantity'));

                    $summary_cards = [
                        ['label' => 'Total Deliveries', 'value' => $total_deliveries, 'icon' => 'fa-truck-field', 'class' => 'stat-blue'],
                        ['label' => 'Total Liters Received', 'value' => number_format($total_liters, 2) . ' L', 'icon' => 'fa-gas-pump', 'class' => 'stat-green'],
                    ];
                }
            } catch (Exception $e) {
                $report_data = [];
                $summary_cards = [
                    ['label' => 'Total Deliveries', 'value' => 0, 'icon' => 'fa-truck-field', 'class' => 'stat-blue'],
                    ['label' => 'Total Liters Received', 'value' => '0.00 L', 'icon' => 'fa-gas-pump', 'class' => 'stat-green'],
                ];
            }
        } elseif ($sub_tab === 'merch_deliveries') {
            // Check if deliveries_oversight table exists
            try {
                $tables = $pdo->query("SHOW TABLES LIKE 'deliveries_oversight'")->fetchAll();
                if (empty($tables)) {
                    $report_data = [];
                    $summary_cards = [
                        ['label' => 'Total Merchandise Deliveries', 'value' => 0, 'icon' => 'fa-boxes-packing', 'class' => 'stat-blue'],
                    ];
                } else {
                    $stmt = $pdo->prepare("
                        SELECT COALESCE(batch_id, delivery_ref, CONCAT('MD-',id)) AS delivery_ref,
                               supplier,
                               product,
                               quantity,
                               unit,
                               status,
                               created_at AS delivery_date,
                               COALESCE((SELECT name FROM users WHERE user_id = encoded_by), '—') AS encoded_by
                        FROM deliveries_oversight
                        WHERE station_id=? AND delivery_type='merchandise' 
                        AND DATE(created_at) BETWEEN ? AND ?
                        ORDER BY created_at DESC
                    ");
                    $stmt->execute([$station_id, $date_start, $date_end]);
                    $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                    $total_deliveries = count($report_data);
                    $summary_cards = [
                        ['label' => 'Total Merchandise Deliveries', 'value' => $total_deliveries, 'icon' => 'fa-boxes-packing', 'class' => 'stat-blue'],
                    ];
                }
            } catch (Exception $e) {
                $report_data = [];
                $summary_cards = [
                    ['label' => 'Total Merchandise Deliveries', 'value' => 0, 'icon' => 'fa-boxes-packing', 'class' => 'stat-blue'],
                ];
            }
        } elseif ($sub_tab === 'inventory_movement') {
            // Check if inventory_logs table exists
            try {
                $tables = $pdo->query("SHOW TABLES LIKE 'inventory_logs'")->fetchAll();
                if (empty($tables)) {
                    $report_data = [];
                    $summary_cards = [
                        ['label' => 'Total Movements', 'value' => 0, 'icon' => 'fa-right-left', 'class' => 'stat-blue'],
                        ['label' => 'Stock-In logs', 'value' => 0, 'icon' => 'fa-circle-arrow-up', 'class' => 'stat-green'],
                        ['label' => 'Stock-Out logs', 'value' => 0, 'icon' => 'fa-circle-arrow-down', 'class' => 'stat-red'],
                    ];
                } else {
                    // Check if inventory_products table exists
                    $product_join = "";
                    $product_col = "'Unknown'";
                    try {
                        $product_tables = $pdo->query("SHOW TABLES LIKE 'inventory_products'")->fetchAll();
                        if (!empty($product_tables)) {
                            $product_join = "LEFT JOIN inventory_products p ON il.product_id = p.id";
                            $product_col = "COALESCE(p.product_name, 'Unknown')";
                        }
                    } catch (Exception $e) {
                        $product_join = "";
                        $product_col = "'Unknown'";
                    }
                    
                    $stmt = $pdo->prepare("
                        SELECT il.action,
                               $product_col AS product_name,
                               il.quantity_change,
                               il.reference_type,
                               il.reference_id,
                               il.created_at
                        FROM inventory_logs il
                        $product_join
                        WHERE il.station_id=? AND DATE(il.created_at) BETWEEN ? AND ?
                        ORDER BY il.created_at DESC
                    ");
                    $stmt->execute([$station_id, $date_start, $date_end]);
                    $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                    $total_movements = count($report_data);
                    $stock_in = count(array_filter($report_data, fn($r) => strtolower($r['action']) === 'stock_in'));
                    $stock_out = count(array_filter($report_data, fn($r) => strtolower($r['action']) === 'stock_out'));

                    $summary_cards = [
                        ['label' => 'Total Movements', 'value' => $total_movements, 'icon' => 'fa-right-left', 'class' => 'stat-blue'],
                        ['label' => 'Stock-In logs', 'value' => $stock_in, 'icon' => 'fa-circle-arrow-up', 'class' => 'stat-green'],
                        ['label' => 'Stock-Out logs', 'value' => $stock_out, 'icon' => 'fa-circle-arrow-down', 'class' => 'stat-red'],
                    ];
                }
            } catch (Exception $e) {
                $report_data = [];
                $summary_cards = [
                    ['label' => 'Total Movements', 'value' => 0, 'icon' => 'fa-right-left', 'class' => 'stat-blue'],
                    ['label' => 'Stock-In logs', 'value' => 0, 'icon' => 'fa-circle-arrow-up', 'class' => 'stat-green'],
                    ['label' => 'Stock-Out logs', 'value' => 0, 'icon' => 'fa-circle-arrow-down', 'class' => 'stat-red'],
                ];
            }
        }
    }

    if ($section === 'meter') {
        if ($sub_tab === 'readings') {
            // Check if fuel_readings table exists
            try {
                $tables = $pdo->query("SHOW TABLES LIKE 'fuel_readings'")->fetchAll();
                if (empty($tables)) {
                    $report_data = [];
                    $summary_cards = [
                        ['label' => 'Total Readings', 'value' => 0, 'icon' => 'fa-gauge', 'class' => 'stat-blue'],
                        ['label' => 'Total Liters Sold', 'value' => '0.00 L', 'icon' => 'fa-gas-pump', 'class' => 'stat-green'],
                    ];
                } else {
                    // Check if fuel_pumps table exists
                    $pump_join = "";
                    $pump_col = "CONCAT('Pump ', r.pump_number)";
                    try {
                        $pump_tables = $pdo->query("SHOW TABLES LIKE 'fuel_pumps'")->fetchAll();
                        if (!empty($pump_tables)) {
                            $pump_join = "LEFT JOIN fuel_pumps p ON r.pump_number = p.id";
                            $pump_col = "COALESCE(p.pump_name, CONCAT('Pump ', r.pump_number))";
                        }
                    } catch (Exception $e) {
                        $pump_join = "";
                        $pump_col = "CONCAT('Pump ', r.pump_number)";
                    }
                    
                    $stmt = $pdo->prepare("
                        SELECT r.id AS reading_id,
                               $pump_col AS pump_name,
                               r.fuel_type,
                               COALESCE(r.shift_period, '—') AS shift,
                               r.previous_reading AS opening_reading,
                               r.present_reading AS closing_reading,
                               r.difference AS liters_sold,
                               r.status,
                               DATE(r.encoded_at) AS reading_date,
                               r.encoded_at
                        FROM fuel_readings r
                        $pump_join
                        WHERE r.station_id=? AND DATE(r.encoded_at) BETWEEN ? AND ?
                        ORDER BY r.encoded_at DESC
                    ");
                    $stmt->execute([$station_id, $date_start, $date_end]);
                    $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                    $total_readings = count($report_data);
                    $total_liters = array_sum(array_column($report_data, 'liters_sold'));

                    $summary_cards = [
                        ['label' => 'Total Readings', 'value' => $total_readings, 'icon' => 'fa-gauge', 'class' => 'stat-blue'],
                        ['label' => 'Total Liters Sold', 'value' => number_format($total_liters, 2) . ' L', 'icon' => 'fa-gas-pump', 'class' => 'stat-green'],
                    ];
                }
            } catch (Exception $e) {
                $report_data = [];
                $summary_cards = [
                    ['label' => 'Total Readings', 'value' => 0, 'icon' => 'fa-gauge', 'class' => 'stat-blue'],
                    ['label' => 'Total Liters Sold', 'value' => '0.00 L', 'icon' => 'fa-gas-pump', 'class' => 'stat-green'],
                ];
            }
        }
    }

    if ($section === 'payments') {
        if ($sub_tab === 'status_breakdown') {
            $jo_enc = has_col($pdo, 'job_orders', 'created_by') ? 'created_by' : 'user_id';
            $jo_id_col = has_col($pdo,'job_orders','job_order_id') ? "COALESCE(NULLIF(jo.job_order_id,''),CONCAT('JO-',jo.id))" : "CONCAT('JO-',jo.id)";
            $cost_col = has_col($pdo,'job_orders','total_cost') ? 'COALESCE(jo.total_cost,jo.estimated_cost,0)' : 'COALESCE(jo.estimated_cost,0)';
            $pay_status_col = has_col($pdo,'job_orders','payment_status') ? "COALESCE(jo.payment_status,'Unpaid')" : "'Unpaid'";

            $s1 = $pdo->prepare("
                SELECT 'Job Order' AS type,
                       $jo_id_col AS reference_id,
                       COALESCE(jo.customer_name,'Walk-in') AS customer_name,
                       $pay_status_col AS payment_status,
                       $cost_col AS total_amount,
                       jo.payment_method,
                       jo.created_at
                FROM job_orders jo
                WHERE jo.station_id=? AND jo.$jo_enc=? AND DATE(jo.created_at) BETWEEN ? AND ?
            ");
            $s1->execute([$station_id, $user_id, $date_start, $date_end]);
            $jo_rows = $s1->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $mt_pay_col = has_col($pdo,'merchandise_transactions','payment_status') ? "COALESCE(mt.payment_status,'Paid')" : "'Paid'";
            $s2 = $pdo->prepare("
                SELECT 'Merchandise' AS type,
                       COALESCE(NULLIF(mt.transaction_id,''),CONCAT('MT-',mt.id)) AS reference_id,
                       COALESCE(NULLIF(TRIM(mt.customer_name),''),'Walk-in') AS customer_name,
                       $mt_pay_col AS payment_status,
                       COALESCE(mt.total_amount,0) AS total_amount,
                       mt.payment_method,
                       mt.created_at
                FROM merchandise_transactions mt
                WHERE mt.station_id=? AND mt.staff_id=? AND DATE(mt.created_at) BETWEEN ? AND ?
            ");
            $s2->execute([$station_id, $user_id, $date_start, $date_end]);
            $mt_rows = $s2->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $report_data = array_merge($jo_rows, $mt_rows);
            usort($report_data, fn($a,$b) => strtotime($b['created_at']) - strtotime($a['created_at']));

            $unpaid = count(array_filter($report_data, fn($r) => strtolower($r['payment_status']) === 'unpaid'));
            $pending = count(array_filter($report_data, fn($r) => strtolower($r['payment_status']) === 'pending'));
            $paid = count(array_filter($report_data, fn($r) => strtolower($r['payment_status']) === 'paid'));

            $summary_cards = [
                ['label' => 'Unpaid transactions', 'value' => $unpaid, 'icon' => 'fa-circle-xmark', 'class' => 'stat-red'],
                ['label' => 'Pending Approvals', 'value' => $pending, 'icon' => 'fa-clock', 'class' => 'stat-orange'],
                ['label' => 'Paid transactions', 'value' => $paid, 'icon' => 'fa-circle-check', 'class' => 'stat-green'],
            ];
        }
    }

    if ($section === 'customers') {
        if ($sub_tab === 'customer_list') {
            $c_cols = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
            $sel_contact = in_array('contact_number', $c_cols) ? 'c.contact_number' : "'' AS contact_number";
            $sel_address  = in_array('address',        $c_cols) ? 'c.address'        : "'' AS address";
            $sel_status   = in_array('status',         $c_cols) ? 'c.status'         : "'active' AS status";
            $sel_credit   = in_array('credit_limit',   $c_cols) ? 'c.credit_limit'   : "0.00 AS credit_limit";
            $sel_balance  = in_array('balance',        $c_cols) ? 'c.balance'        : "0.00 AS balance";

            $stmt = $pdo->prepare("
                SELECT c.id,
                       c.name,
                       $sel_contact AS contact_number,
                       $sel_address AS address,
                       $sel_status AS status,
                       $sel_credit AS credit_limit,
                       $sel_balance AS balance,
                       c.created_at
                FROM customers c
                WHERE c.station_id = ?
                ORDER BY c.name ASC
            ");
            $stmt->execute([$station_id]);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $total_custs = count($report_data);
            $active_custs = count(array_filter($report_data, fn($r) => strtolower($r['status']) === 'active'));

            $summary_cards = [
                ['label' => 'Total Profiles', 'value' => $total_custs, 'icon' => 'fa-address-book', 'class' => 'stat-blue'],
                ['label' => 'Active Status', 'value' => $active_custs, 'icon' => 'fa-user-check', 'class' => 'stat-green'],
            ];
        } elseif ($sub_tab === 'customer_history') {
            // Check if customer_id column exists in merchandise_transactions
            $has_customer_id = has_col($pdo, 'merchandise_transactions', 'customer_id');
            
            if ($has_customer_id) {
                $stmt = $pdo->prepare("
                    SELECT COALESCE(NULLIF(mt.transaction_id,''),CONCAT('MT-',mt.id)) AS reference,
                           COALESCE(c.name, mt.customer_name, 'Walk-in') AS customer,
                           mt.total_amount,
                           mt.payment_method,
                           mt.created_at AS transaction_date,
                           COALESCE((SELECT name FROM users WHERE user_id =mt.staff_id),'—') AS encoded_by
                    FROM merchandise_transactions mt
                    LEFT JOIN customers c ON mt.customer_id = c.id
                    WHERE mt.station_id = ? AND mt.staff_id = ? AND DATE(mt.created_at) BETWEEN ? AND ?
                    ORDER BY mt.created_at DESC
                ");
            } else {
                // Query without JOIN if customer_id doesn't exist
                $stmt = $pdo->prepare("
                    SELECT COALESCE(NULLIF(mt.transaction_id,''),CONCAT('MT-',mt.id)) AS reference,
                           COALESCE(mt.customer_name, 'Walk-in') AS customer,
                           mt.total_amount,
                           mt.payment_method,
                           mt.created_at AS transaction_date,
                           COALESCE((SELECT name FROM users WHERE user_id =mt.staff_id),'—') AS encoded_by
                    FROM merchandise_transactions mt
                    WHERE mt.station_id = ? AND mt.staff_id = ? AND DATE(mt.created_at) BETWEEN ? AND ?
                    ORDER BY mt.created_at DESC
                ");
            }
            $stmt->execute([$station_id, $user_id, $date_start, $date_end]);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $total_txns = count($report_data);
            $total_amt = array_sum(array_column($report_data, 'total_amount'));

            $summary_cards = [
                ['label' => 'My Encoded Txns', 'value' => $total_txns, 'icon' => 'fa-cash-register', 'class' => 'stat-blue'],
                ['label' => 'Total Encoded Amount', 'value' => '₱' . number_format($total_amt, 2), 'icon' => 'fa-coins', 'class' => 'stat-green'],
            ];
        }
    }

    if ($section === 'activity') {
        if ($sub_tab === 'staff_activity') {
            $active_dates = [];
            try {
                $s = $pdo->prepare("SELECT DISTINCT DATE(created_at) AS d FROM activity_logs WHERE user_id=? AND DATE(created_at) BETWEEN ? AND ?");
                $s->execute([$user_id, $date_start, $date_end]);
                foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $d) $active_dates[$d] = true;
            } catch (Exception $e) {}

            $s = $pdo->prepare("SELECT DISTINCT DATE(created_at) AS d FROM merchandise_transactions WHERE station_id=? AND staff_id=? AND DATE(created_at) BETWEEN ? AND ?");
            $s->execute([$station_id, $user_id, $date_start, $date_end]);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $d) $active_dates[$d] = true;
            
            $jo_enc = has_col($pdo, 'job_orders', 'created_by') ? 'created_by' : 'user_id';
            $s = $pdo->prepare("SELECT DISTINCT DATE(created_at) AS d FROM job_orders WHERE station_id=? AND $jo_enc=? AND DATE(created_at) BETWEEN ? AND ?");
            $s->execute([$station_id, $user_id, $date_start, $date_end]);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $d) $active_dates[$d] = true;
            
            try {
                $s = $pdo->prepare("SELECT DISTINCT DATE(encoded_at) AS d FROM fuel_readings WHERE station_id=? AND encoded_by=? AND DATE(encoded_at) BETWEEN ? AND ?");
                $s->execute([$station_id, $user_id, $date_start, $date_end]);
                foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $d) $active_dates[$d] = true;
            } catch (Exception $e) {}

            krsort($active_dates);
            $report_data = [];
            foreach (array_keys($active_dates) as $d) {
                $act_count = 0;
                try {
                    $q0 = $pdo->prepare("SELECT COUNT(*) FROM activity_logs WHERE user_id=? AND DATE(created_at)=?");
                    $q0->execute([$user_id, $d]);
                    $act_count = (int)$q0->fetchColumn();
                } catch (Exception $e) {}

                $q1 = $pdo->prepare("SELECT COUNT(*) FROM merchandise_transactions WHERE station_id=? AND staff_id=? AND DATE(created_at)=?");
                $q1->execute([$station_id, $user_id, $d]);
                $merch_count = (int)$q1->fetchColumn();
                
                $q2 = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND $jo_enc=? AND DATE(created_at)=?");
                $q2->execute([$station_id, $user_id, $d]);
                $jo_count = (int)$q2->fetchColumn();
                
                $fuel_count = 0;
                try {
                    $q4 = $pdo->prepare("SELECT COUNT(*) FROM fuel_readings WHERE station_id=? AND encoded_by=? AND DATE(encoded_at)=?");
                    $q4->execute([$station_id, $user_id, $d]);
                    $fuel_count = (int)$q4->fetchColumn();
                } catch (Exception $e) {}
                
                $report_data[] = [
                    'date'               => $d,
                    'activity_logs'      => $act_count,
                    'merchandise_txns'   => $merch_count,
                    'job_orders'         => $jo_count,
                    'fuel_readings'      => $fuel_count,
                    'total_actions'      => $act_count + $merch_count + $jo_count + $fuel_count,
                ];
            }

            $total_days = count($report_data);
            $total_actions = array_sum(array_column($report_data, 'total_actions'));

            $summary_cards = [
                ['label' => 'Active Days', 'value' => $total_days, 'icon' => 'fa-calendar-days', 'class' => 'stat-blue'],
                ['label' => 'Total Actions', 'value' => $total_actions, 'icon' => 'fa-bolt', 'class' => 'stat-green'],
            ];
        } elseif ($sub_tab === 'audit_trail') {
            // Check if audit_logs table exists
            try {
                $tables = $pdo->query("SHOW TABLES LIKE 'audit_logs'")->fetchAll();
                if (empty($tables)) {
                    $report_data = [];
                    $summary_cards = [
                        ['label' => 'My Audit Logs', 'value' => 0, 'icon' => 'fa-fingerprint', 'class' => 'stat-blue'],
                    ];
                } else {
                    $stmt = $pdo->prepare("
                        SELECT action_type,
                               action_details,
                               entity_type,
                               entity_id,
                               status,
                               created_at
                        FROM audit_logs
                        WHERE user_id=? AND DATE(created_at) BETWEEN ? AND ?
                        ORDER BY created_at DESC
                    ");
                    $stmt->execute([$user_id, $date_start, $date_end]);
                    $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                    $total_logs = count($report_data);
                    $summary_cards = [
                        ['label' => 'My Audit Logs', 'value' => $total_logs, 'icon' => 'fa-fingerprint', 'class' => 'stat-blue'],
                    ];
                }
            } catch (Exception $e) {
                $report_data = [];
                $summary_cards = [
                    ['label' => 'My Audit Logs', 'value' => 0, 'icon' => 'fa-fingerprint', 'class' => 'stat-blue'],
                ];
            }
        }
    }
} catch (Exception $e) {
    $report_error = $e->getMessage();
}

// ============================================================
// EXPORT HANDLER
// ============================================================
if (isset($_GET['export']) && !empty($report_data)) {
    $format = trim($_GET['export']);
    if (in_array($format, ['excel', 'csv'])) {
        header("Content-Type: text/csv; charset=utf-8");
        $filename = "staff_report_{$section}_{$sub_tab}_" . date('Y-m-d') . ".csv";
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        $out = fopen('php://output', 'w');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM

        fputcsv($out, [strtoupper($section . ' - ' . $sub_tab . ' Report')]);
        fputcsv($out, ["Station: {$station_name}", "Staff: " . ($me['name'] ?? 'Staff'), "Period: {$date_start} to {$date_end}"]);
        fputcsv($out, []); // Blank line

        if (!empty($report_data)) {
            $headers = array_keys($report_data[0]);
            fputcsv($out, array_map(fn($h) => strtoupper(str_replace('_', ' ', $h)), $headers));
            foreach ($report_data as $row) {
                fputcsv($out, array_values($row));
            }
        }
        fclose($out);
        exit;
    }
}

require_once __DIR__ . '/../partials/header.php';
?>
<style>
/* ============================================================
   Reports — Styles (Matching Manager Design System)
   ============================================================ */
:root {
    --petron-blue: #00264D;
    --petron-red:  #CC0000;
    --success:     #22c55e;
    --warning:     #002F70;
    --info:        #3b82f6;
    --purple:      #8b5cf6;
}

/* Page head */
.page-head { margin-bottom: 24px; }
.page-head .h1 { font-size: 26px; font-weight: 800; color: var(--petron-blue); margin: 0 0 4px; letter-spacing: -.3px; }
.page-head .sub { font-size: 13px; color: #667085; }

/* Sub-tab navigation */
.rpt-sub-tabs {
    display: flex;
    gap: 8px;
    border-bottom: 2px solid #EAEAEA;
    padding-bottom: 0px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}
.sub-tab-btn {
    padding: 10px 18px;
    font-size: 13px;
    font-weight: 700;
    color: #64748b;
    text-decoration: none;
    border-bottom: 3px solid transparent;
    transition: all 0.15s ease;
    margin-bottom: -2px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.sub-tab-btn:hover {
    color: var(--petron-blue);
    background: #f8fafc;
}
.sub-tab-btn.active {
    color: var(--petron-blue);
    border-bottom-color: var(--petron-red);
}

/* Date range filter bar */
.rpt-filter-bar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; background: #fff; border: 1px solid #EAEAEA; border-radius: 10px; padding: 12px 16px; margin-bottom: 20px; box-shadow: 0 1px 4px rgba(0,0,0,.04); }
.rpt-filter-bar label { font-size: 12px; font-weight: 600; color: #667085; text-transform: uppercase; letter-spacing: .4px; }
.range-btn { padding: 6px 14px; border-radius: 6px; border: 1px solid #EAEAEA; background: #f8fafc; font-size: 12px; font-weight: 600; color: #374151; cursor: pointer; text-decoration: none; transition: .15s; }
.range-btn:hover { background: #e8f0f8; border-color: var(--petron-blue); color: var(--petron-blue); }
.range-btn.active { background: var(--petron-blue); color: #fff; border-color: var(--petron-blue); }
.rpt-filter-bar input[type="date"] { padding: 6px 10px; border: 1px solid #EAEAEA; border-radius: 6px; font-size: 12px; color: #374151; background: #f8fafc; }
.rpt-filter-bar .btn-apply { padding: 6px 16px; background: var(--petron-red); color: #fff; border: none; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer; transition: .15s; }
.rpt-filter-bar .btn-apply:hover { background: #a80000; }
.rpt-filter-bar .btn-export { display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border-radius:7px; font-size:12px; font-weight:700; text-decoration:none; cursor:pointer; transition:.15s; border:none; }
.rpt-filter-bar .btn-export:hover { opacity:.88; }
.rpt-filter-bar .export-buttons { display: flex; gap: 8px; margin-left: auto; }
#custom-range-inputs { display: flex; align-items: center; gap: 8px; }

/* Card-level export action bar */
.card-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.btn-act { display:inline-flex; align-items:center; gap:6px; padding:7px 16px; border-radius:7px; font-size:12px; font-weight:700; text-decoration:none; cursor:pointer; transition:.15s; border:none; white-space:nowrap; }
.btn-act:hover { opacity:.85; transform:translateY(-1px); }
.btn-act-excel  { background:#1e7e34; color:#fff; }
.btn-act-csv    { background:#1a3a6b; color:#fff; }
.btn-act-pdf    { background:#cc0000; color:#fff; }
.btn-act-back   { background:#6c757d; color:#fff; }

/* Stat cards */
.stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 20px; }
.stat-card { background: #fff; border-radius: 12px; border: 1px solid #EAEAEA; padding: 16px 18px; display: flex; align-items: center; gap: 14px; box-shadow: 0 1px 4px rgba(0,0,0,.04); border-left: 4px solid #EAEAEA; }
.stat-icon { width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
.stat-body .stat-num  { font-size: 22px; font-weight: 800; color: #101828; line-height: 1.1; }
.stat-body .stat-label{ font-size: 11px; font-weight: 600; color: #667085; text-transform: uppercase; letter-spacing: .4px; margin-top: 2px; }
.stat-blue   { border-left-color: var(--petron-blue); } .stat-blue   .stat-icon { background: #e8f0f8; color: var(--petron-blue); }
.stat-red    { border-left-color: var(--petron-red);  } .stat-red    .stat-icon { background: #fee2e2; color: var(--petron-red); }
.stat-green  { border-left-color: #22c55e; }            .stat-green  .stat-icon { background: #dcfce7; color: #16a34a; }
.stat-orange { border-left-color: #002F70; }            .stat-orange .stat-icon { background: #e8f0fb; color: #002F70; }
.stat-purple { border-left-color: #8b5cf6; }            .stat-purple .stat-icon { background: #ede9fe; color: #7c3aed; }
.stat-teal   { border-left-color: #14b8a6; }            .stat-teal   .stat-icon { background: #ccfbf1; color: #0d9488; }

/* Section card */
.rpt-card { background: #fff; border-radius: 14px; border: 1px solid #EAEAEA; padding: 20px; box-shadow: 0 1px 4px rgba(0,0,0,.04); margin-bottom: 20px; }
.rpt-card-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.rpt-card-head h3 { font-size: 15px; font-weight: 700; color: var(--petron-blue); margin: 0; display: flex; align-items: center; gap: 8px; }
.rpt-card-head .badge-count { background: var(--petron-blue); color: #fff; font-size: 10px; font-weight: 800; padding: 2px 7px; border-radius: 20px; }

/* Tables - Standardized Design */
.mgr-table { width: 100%; border-collapse: collapse; font-size: 13px; background: #fff; }
.mgr-table thead tr { background: #002F70 !important; border: none; }
.mgr-table th { 
    background: #002F70 !important; 
    color: #fff !important; 
    text-align: left; 
    padding: 14px 12px !important; 
    font-size: 11px; 
    font-weight: 600; 
    text-transform: uppercase; 
    letter-spacing: .3px; 
    white-space: nowrap; 
    border: none !important;
}
.mgr-table th:last-child { text-align: center !important; }
.mgr-table td { 
    padding: 12px !important; 
    border-bottom: 1px solid #e9ecef !important; 
    color: #212529; 
    vertical-align: middle; 
    font-size: 13px;
}
.mgr-table td:last-child { text-align: center !important; }
.mgr-table tbody tr:hover td { background: #e3f2fd !important; }
.mgr-table tbody tr { transition: background 0.2s ease; }
.mgr-table tbody tr:last-child td { border-bottom: 1px solid #e9ecef !important; }
.table-scroll { overflow-x: auto; width: 100%; -webkit-overflow-scrolling: touch; }

/* Badges - Plain Text Only (No Backgrounds) */
.badge { 
    display: inline-block; 
    padding: 0 !important; 
    margin: 0 !important;
    background: transparent !important; 
    border: none !important;
    font-size: 12px; 
    font-weight: 600; 
    text-transform: uppercase; 
    letter-spacing: .3px; 
}
.badge-pending   { color: #4338ca !important; background: transparent !important; }
.badge-approved  { color: #0d7d3e !important; background: transparent !important; }
.badge-inprog    { color: #1976d2 !important; background: transparent !important; }
.badge-completed { color: #0d7d3e !important; background: transparent !important; }
.badge-rejected  { color: #c62828 !important; background: transparent !important; }
.badge-cancelled { color: #616161 !important; background: transparent !important; }
.badge-default   { color: #616161 !important; background: transparent !important; }
.badge-hold      { color: #b45309 !important; background: transparent !important; }
.badge-validated { color: #0d7d3e !important; background: transparent !important; }
.badge-ok        { color: #0d7d3e !important; background: transparent !important; }

/* Empty state */
.empty-state { text-align: center; padding: 48px 20px; color: #9ca3af; }
.empty-state i { font-size: 40px; margin-bottom: 12px; display: block; opacity: .4; }
.empty-state p { font-size: 14px; margin: 0; }

@media(max-width: 768px) {
    .stat-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>

<div class="page-content">
    <div class="page-head">
        <h1 class="h1"><i class="fa-solid fa-chart-bar" style="color:var(--petron-red);margin-right:8px;"></i>STAFF REPORTS</h1>
        <div class="sub">Station: <?= htmlspecialchars($station_name) ?> | Role: <?= htmlspecialchars(ucfirst($role)) ?></div>
    </div>

    <!-- DATE RANGE FILTER BAR -->
    <form method="GET" action="staff_reports.php" class="rpt-filter-bar" id="filter-form">
        <input type="hidden" name="section" value="<?= htmlspecialchars($section) ?>">
        <?php if (!empty($sub_tab)): ?>
        <input type="hidden" name="sub_tab" value="<?= htmlspecialchars($sub_tab) ?>">
        <?php endif; ?>
        <label>Period:</label>
        <?php foreach (['today' => 'Today', 'week' => 'This Week', 'month' => 'This Month', 'custom' => 'Custom'] as $r => $label): ?>
        <a href="staff_reports.php?<?= http_build_query(['section' => $section, 'range' => $r, 'start' => $date_start, 'end' => $date_end, 'sub_tab' => $sub_tab]) ?>"
           class="range-btn<?= $range === $r ? ' active' : '' ?>"
           onclick="if('<?= $r ?>'==='custom'){document.getElementById('custom-range-inputs').style.display='flex';return false;}else{document.getElementById('custom-range-inputs').style.display='none';}">
            <?= $label ?>
        </a>
        <?php endforeach; ?>
        <div id="custom-range-inputs" style="display:<?= $range === 'custom' ? 'flex' : 'none' ?>;">
            <input type="hidden" name="range" value="custom" id="range-hidden">
            <input type="date" name="start" value="<?= htmlspecialchars($date_start) ?>" max="<?= $today ?>">
            <span style="color:#9ca3af;font-size:12px;">to</span>
            <input type="date" name="end"   value="<?= htmlspecialchars($date_end) ?>"   max="<?= $today ?>">
            <button type="submit" class="btn-apply"><i class="fa-solid fa-filter"></i> Apply</button>
        </div>
    </form>

    <!-- SUB-TABS NAVIGATION -->
    <?php
    $sub_tabs_def = [
        'sales' => [
            'daily_summary' => ['label' => 'Daily Sales Summary', 'icon' => 'fa-cash-register'],
            'customer_linkage' => ['label' => 'Customer Linkage', 'icon' => 'fa-link']
        ],
        'job_orders' => [
            'jo_list' => ['label' => 'Job Orders Tracker', 'icon' => 'fa-wrench'],
            'staff_perf' => ['label' => 'Performance Report', 'icon' => 'fa-gauge-high']
        ],
        'deliveries' => [
            'fuel_deliveries' => ['label' => 'Fuel Deliveries', 'icon' => 'fa-truck-field'],
            'merch_deliveries' => ['label' => 'Merchandise Deliveries', 'icon' => 'fa-boxes-stacked'],
            'inventory_movement' => ['label' => 'Inventory Movement', 'icon' => 'fa-right-left']
        ],
        'meter' => [
            'readings' => ['label' => 'Meter Readings Log', 'icon' => 'fa-gauge']
        ],
        'payments' => [
            'status_breakdown' => ['label' => 'Payment Status Breakdown', 'icon' => 'fa-credit-card']
        ],
        'customers' => [
            'customer_list' => ['label' => 'Customer Profiles', 'icon' => 'fa-users'],
            'customer_history' => ['label' => 'Staff-Encoded History', 'icon' => 'fa-history']
        ],
        'activity' => [
            'staff_activity' => ['label' => 'My Activity Log', 'icon' => 'fa-user-clock'],
            'audit_trail' => ['label' => 'My Audit Trail', 'icon' => 'fa-list-check']
        ]
    ];
    ?>

    <?php if (isset($sub_tabs_def[$section])): ?>
        <div class="rpt-sub-tabs">
            <?php foreach ($sub_tabs_def[$section] as $sub_key => $sub_info): ?>
                <?php
                $sub_url = 'staff_reports.php?' . http_build_query([
                    'section' => $section,
                    'range' => $range,
                    'start' => $date_start,
                    'end' => $date_end,
                    'sub_tab' => $sub_key
                ]);
                ?>
                <a href="<?= $sub_url ?>" class="sub-tab-btn<?= $sub_tab === $sub_key ? ' active' : '' ?>">
                    <i class="fa-solid <?= $sub_info['icon'] ?>"></i> <?= htmlspecialchars($sub_info['label']) ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php
    $_exp_url = 'staff_reports.php?' . http_build_query([
        'section' => $section, 'range' => $range,
        'start'   => $date_start, 'end'  => $date_end, 'sub_tab' => $sub_tab, 'export' => 'excel'
    ]);
    $_csv_url = 'staff_reports.php?' . http_build_query([
        'section' => $section, 'range' => $range,
        'start'   => $date_start, 'end'  => $date_end, 'sub_tab' => $sub_tab, 'export' => 'csv'
    ]);
    $card_btns = '<div class="card-actions">
        <a href="'.$_exp_url.'" class="btn-act btn-act-excel" title="Export Excel"><i class="fa-solid fa-file-excel"></i> Excel</a>
        <a href="'.$_csv_url.'" class="btn-act btn-act-csv"   title="Export CSV"><i class="fa-solid fa-file-csv"></i> CSV</a>
    </div>';
    ?>

    <?php if ($report_error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($report_error) ?></div>
    <?php endif; ?>

    <!-- STATS CARDS -->
    <?php if (!empty($summary_cards)): ?>
        <div class="stat-grid">
            <?php foreach ($summary_cards as $card): ?>
                <div class="stat-card <?= $card['class'] ?? 'stat-blue' ?>">
                    <div class="stat-icon"><i class="fa-solid <?= $card['icon'] ?>"></i></div>
                    <div class="stat-body">
                        <div class="stat-num"><?= htmlspecialchars($card['value']) ?></div>
                        <div class="stat-label"><?= htmlspecialchars($card['label']) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- DATA CARD -->
    <div class="rpt-card">
        <div class="rpt-card-head">
            <h3>
                <i class="fa-solid <?= $sub_tabs_def[$section][$sub_tab]['icon'] ?? 'fa-file-invoice' ?>"></i> 
                <?= htmlspecialchars($sub_tabs_def[$section][$sub_tab]['label'] ?? 'Report Data') ?>
                <span class="badge-count"><?= count($report_data) ?></span>
            </h3>
            <?= $card_btns ?>
        </div>

        <?php if (empty($report_data)): ?>
            <div class="empty-state">
                <i class="fa-solid fa-circle-info"></i>
                <p>No records found for this period.</p>
            </div>
        <?php else: ?>
            <div class="table-scroll">
                <table class="mgr-table" id="report-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <?php foreach (array_keys($report_data[0]) as $h): ?>
                                <th><?= htmlspecialchars(str_replace('_', ' ', $h)) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data as $i => $row): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <?php foreach ($row as $col => $val): ?>
                                    <td>
                                        <?php
                                        if (strtolower($col) === 'status' || strtolower($col) === 'payment_status') {
                                            $s = strtolower((string)$val);
                                            $badge_class = 'badge-default';
                                            if (in_array($s, ['approved', 'validated', 'completed', 'paid', 'active'])) $badge_class = 'badge-approved';
                                            elseif (in_array($s, ['pending', 'pending validation', 'in progress'])) $badge_class = 'badge-pending';
                                            elseif (in_array($s, ['rejected', 'unpaid', 'cancelled'])) $badge_class = 'badge-rejected';
                                            echo '<span class="badge ' . $badge_class . '">' . htmlspecialchars(ucfirst($val)) . '</span>';
                                        } elseif (str_contains(strtolower($col), 'amount') || str_contains(strtolower($col), 'sales') || str_contains(strtolower($col), 'cost') || str_contains(strtolower($col), 'balance') || str_contains(strtolower($col), 'limit')) {
                                            echo '₱' . number_format((float)$val, 2);
                                        } elseif (str_contains(strtolower($col), 'quantity') || str_contains(strtolower($col), 'liters')) {
                                            echo number_format((float)$val, 2) . (str_contains(strtolower($col), 'liters') ? ' L' : '');
                                        } else {
                                            echo htmlspecialchars((string)$val);
                                        }
                                        ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const table = document.getElementById('report-table');
    if (!table) return;
    const tbody = table.querySelector('tbody');
    if (!tbody) return;
    const rows = Array.from(tbody.querySelectorAll('tr'));
    if (rows.length === 0) return;

    let currentPage = 1;
    let rowsPerPage = 10;
    let totalRows = rows.length;
    let totalPages = Math.ceil(totalRows / rowsPerPage);

    const wrapper = document.createElement('div');
    wrapper.className = 'pagination-wrapper client-side-pagination';
    wrapper.style.cssText = 'display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; background: #fff; border: 1px solid #EAEAEA; border-radius: 12px; margin-top: 12px; margin-bottom: 40px; box-shadow: 0 2px 5px rgba(0,0,0,0.02); flex-wrap: wrap; gap: 10px;';
    
    if (!document.getElementById('client-pagination-style')) {
        const style = document.createElement('style');
        style.id = 'client-pagination-style';
        style.innerHTML = `
            .rows-per-page { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #6b7280; }
            .rows-per-page select { padding: 6px; border: 1px solid #e5e7eb; border-radius: 4px; outline: none; cursor: pointer; }
            .page-info { font-size: 13px; color: #6b7280; }
            .pagination-controls { display: flex; align-items: center; gap: 10px; }
            .btn-page { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; background: #fff; border: 1px solid #e5e7eb; border-radius: 6px; color: #374151; text-decoration: none; transition: 0.2s; cursor: pointer; }
            .btn-page:hover:not(.disabled) { background: #f3f4f6; }
            .btn-page.disabled { opacity: 0.5; cursor: not-allowed; pointer-events: none; }
            .current-page { font-size: 13px; font-weight: 500; color: #111827; }
        `;
        document.head.appendChild(style);
    }

    function renderTable() {
        tbody.innerHTML = '';
        const start = (currentPage - 1) * rowsPerPage;
        const end = start + rowsPerPage;
        const paginatedRows = rows.slice(start, end);
        
        paginatedRows.forEach(row => tbody.appendChild(row));
        updateControls();
    }

    function updateControls() {
        totalPages = Math.ceil(totalRows / rowsPerPage);
        const start = (currentPage - 1) * rowsPerPage + 1;
        const end = Math.min(currentPage * rowsPerPage, totalRows);
        
        wrapper.innerHTML = `
            <div class="rows-per-page">
                <label>Rows per page:</label>
                <select class="rpp-select">
                    <option value="10" ${rowsPerPage === 10 ? 'selected' : ''}>10</option>
                    <option value="25" ${rowsPerPage === 25 ? 'selected' : ''}>25</option>
                    <option value="50" ${rowsPerPage === 50 ? 'selected' : ''}>50</option>
                    <option value="100" ${rowsPerPage === 100 ? 'selected' : ''}>100</option>
                    <option value="${totalRows}" ${rowsPerPage === totalRows ? 'selected' : ''}>All</option>
                </select>
            </div>
            <div class="page-info">
                Showing ${totalRows === 0 ? 0 : start} to ${end} of ${totalRows} entries
            </div>
            <div class="pagination-controls">
                <button type="button" class="btn-page prev-btn ${currentPage === 1 ? 'disabled' : ''}"><i class="fa-solid fa-chevron-left"></i></button>
                <span class="current-page">Page ${currentPage} of ${Math.max(1, totalPages)}</span>
                <button type="button" class="btn-page next-btn ${currentPage === totalPages || totalPages === 0 ? 'disabled' : ''}"><i class="fa-solid fa-chevron-right"></i></button>
            </div>
        `;

        wrapper.querySelector('.rpp-select').addEventListener('change', function(e) {
            rowsPerPage = parseInt(e.target.value);
            currentPage = 1;
            renderTable();
        });

        wrapper.querySelector('.prev-btn').addEventListener('click', function(e) {
            e.preventDefault();
            if (currentPage > 1) {
                currentPage--;
                renderTable();
            }
        });

        wrapper.querySelector('.next-btn').addEventListener('click', function(e) {
            e.preventDefault();
            if (currentPage < totalPages) {
                currentPage++;
                renderTable();
            }
        });
    }

    table.parentNode.insertBefore(wrapper, table.nextSibling);
    renderTable();
});
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>
