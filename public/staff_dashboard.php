<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'dashboard';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/classes/ShiftPeriodConfig.php';
require_login();

$me         = current_user();
$user_id    = (int)($me['id'] ?? $me['user_id'] ?? ($_SESSION['user_id'] ?? 0));
$station_id = (int) user_station_id();
$role       = role_key($me['role'] ?? '');

$role_redirects = [
    'superadmin' => 'super_admin_dashboard.php',
    'admin'      => 'admin_dashboard.php',
    'manager'    => 'manager_dashboard.php',
    'developer'  => 'developer_panel.php',
];

if (isset($role_redirects[$role])) {
    header('Location: ' . $role_redirects[$role]);
    exit;
}

if ($role !== 'staff') {
    header('Location: index.php');
    exit;
}
if (!$station_id) {
    render_no_station_page('staff_dashboard.php');
}

$display_name  = htmlspecialchars($me['full_name'] ?? trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: ($me['name'] ?? 'Staff'));
$station_label = htmlspecialchars($me['station_name'] ?? 'Station #' . $station_id);

$shift_config = getShiftPeriodConfig($pdo, $station_id);
$shift_periods = $shift_config->getShiftPeriods();
foreach ($shift_periods as $idx => $period) {
    $shift_periods[$idx]['dashboard_number'] = $idx + 1;
}

if (empty($shift_periods)) {
    die('Error: No active shift periods configured.');
}

function dashboard_valid_date(?string $value): ?string {
    if (!$value || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }

    $dt = DateTime::createFromFormat('!Y-m-d', $value);
    return ($dt && $dt->format('Y-m-d') === $value) ? $value : null;
}

function dashboard_shift_aliases(array $shift, int $shift_number): array {
    $aliases = [
        $shift['shift_key'] ?? null,
        $shift['shift_name'] ?? null,
        'shift ' . $shift_number,
        'shift' . $shift_number,
        (string) $shift_number,
    ];

    if ($shift_number === 1) {
        $aliases = array_merge($aliases, ['first', 'first shift']);
    } elseif ($shift_number === 2) {
        $aliases = array_merge($aliases, ['second', 'second shift']);
    }

    $normalized = [];
    foreach ($aliases as $alias) {
        $alias = strtolower(trim((string) $alias));
        if ($alias !== '') {
            $normalized[$alias] = true;
        }
    }

    return array_keys($normalized);
}

function dashboard_shift_number_from_value(?string $value, array $shift_periods): ?int {
    $value = strtolower(trim((string) $value));
    if ($value === '') {
        return null;
    }

    foreach ($shift_periods as $shift) {
        $number = (int) ($shift['dashboard_number'] ?? 0);
        if ($number && in_array($value, dashboard_shift_aliases($shift, $number), true)) {
            return $number;
        }
    }

    if (preg_match('/\b(first|1)\b/', $value)) {
        return 1;
    }
    if (preg_match('/\b(second|2)\b/', $value)) {
        return 2;
    }

    return null;
}

function dashboard_shift_by_number(array $shift_periods, int $shift_number): ?array {
    foreach ($shift_periods as $shift) {
        if ((int) ($shift['dashboard_number'] ?? 0) === $shift_number) {
            return $shift;
        }
    }
    return null;
}

function dashboard_current_shift(array $shift_periods): ?array {
    $now = date('H:i:s');
    foreach ($shift_periods as $shift) {
        $start = $shift['start_time'] ?? '00:00:00';
        $end = $shift['end_time'] ?? '23:59:59';
        $in_range = ($start <= $end)
            ? ($now >= $start && $now <= $end)
            : ($now >= $start || $now <= $end);

        if ($in_range) {
            return $shift;
        }
    }

    return $shift_periods[0] ?? null;
}

function dashboard_shift_label(array $shift): string {
    $name = trim((string) ($shift['shift_name'] ?? 'Shift'));
    $name = preg_replace('/[\x{2013}\x{2014}]/u', '-', $name) ?? $name;
    if (preg_match('/^\s*(.+?)\s*:/', $name, $matches)) {
        $name = trim($matches[1]);
    }
    if ($name === '') {
        $name = 'Shift';
    }

    $start = isset($shift['start_time']) ? date('g:i A', strtotime($shift['start_time'])) : '';
    $end = isset($shift['end_time']) ? date('g:i A', strtotime($shift['end_time'])) : '';
    $time_label = ($start && $end) ? " ($start - $end)" : '';

    return $name . $time_label;
}

function dashboard_range_label(string $date_from, string $date_to): string {
    if ($date_from === $date_to) {
        return date('F j, Y', strtotime($date_from));
    }

    return date('F j, Y', strtotime($date_from)) . ' - ' . date('F j, Y', strtotime($date_to));
}

// User assigned shift
$user_assigned_shift = null;
try {
    $shift_check = $pdo->prepare("SELECT shift_assignment FROM users WHERE id = ? LIMIT 1");
    $shift_check->execute([$user_id]);
    $shift_assignment = $shift_check->fetchColumn();

    $user_assigned_shift = dashboard_shift_number_from_value($shift_assignment, $shift_periods);
} catch (Exception $e) {}

if (!$user_assigned_shift) {
    try {
        $recent_shift = $pdo->prepare("
            SELECT shift_name 
            FROM labor_sessions 
            WHERE user_id = ? 
            ORDER BY start_time DESC 
            LIMIT 1
        ");
        $recent_shift->execute([$user_id]);
        $last_shift_name = $recent_shift->fetchColumn();

        $user_assigned_shift = dashboard_shift_number_from_value($last_shift_name, $shift_periods);
    } catch (Exception $e) {}
}

if (!$user_assigned_shift) {
    $user_assigned_shift = (int)($shift_periods[0]['dashboard_number'] ?? 1);
}

$clocked_in = false;
$clock_in_time = null;
$clock_in_shift = null;
try {
    $ci = $pdo->prepare("SELECT start_time, shift_name FROM labor_sessions WHERE user_id = ? AND end_time IS NULL");
    $ci->execute([$user_id]);
    $clock = $ci->fetch(PDO::FETCH_ASSOC);
    if ($clock) {
        $clocked_in = true;
        $clock_in_time = $clock['start_time'];
        $clock_in_shift = $clock['shift_name'];
    }
} catch (Exception $e) {}

$flash_success = $_SESSION['success'] ?? null; unset($_SESSION['success']);
$flash_error   = $_SESSION['error']   ?? null; unset($_SESSION['error']);

// Date range filters
$legacy_date = dashboard_valid_date($_GET['date'] ?? null);
$date_from = dashboard_valid_date($_GET['date_from'] ?? null) ?? $legacy_date ?? date('Y-m-d');
$date_to = dashboard_valid_date($_GET['date_to'] ?? null) ?? $legacy_date ?? $date_from;

if ($date_from > $date_to) {
    [$date_from, $date_to] = [$date_to, $date_from];
}

$date_range_label = dashboard_range_label($date_from, $date_to);

// ─────────────────────────────────────────────────────────────────────────────
// METRICS QUERY BLOCK
// ─────────────────────────────────────────────────────────────────────────────

// 1. Today's Transactions
$fuel_count = 0; $merch_count = 0; $jo_count = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM fuel_transactions WHERE station_id = ? AND DATE(transaction_date) BETWEEN ? AND ?");
    $stmt->execute([$station_id, $date_from, $date_to]);
    $fuel_count = (int)$stmt->fetchColumn();
} catch (Exception $e) {}
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM merchandise_transactions WHERE station_id = ? AND DATE(COALESCE(NULLIF(transaction_date, '0000-00-00 00:00:00'), created_at)) BETWEEN ? AND ?");
    $stmt->execute([$station_id, $date_from, $date_to]);
    $merch_count = (int)$stmt->fetchColumn();
} catch (Exception $e) {}
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id = ? AND DATE(created_at) BETWEEN ? AND ?");
    $stmt->execute([$station_id, $date_from, $date_to]);
    $jo_count = (int)$stmt->fetchColumn();
} catch (Exception $e) {}
$todays_transactions = $fuel_count + $merch_count + $jo_count;

// 2. Today's Sales
$fuel_sales = 0.0; $merch_sales = 0.0; $service_sales = 0.0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM fuel_transactions WHERE station_id=? AND DATE(transaction_date) BETWEEN ? AND ?");
    $stmt->execute([$station_id, $date_from, $date_to]);
    $fuel_sales = (float)$stmt->fetchColumn();
} catch (Exception $e) {}
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM merchandise_transactions WHERE station_id=? AND DATE(COALESCE(NULLIF(transaction_date,'0000-00-00 00:00:00'),created_at)) BETWEEN ? AND ?");
    $stmt->execute([$station_id, $date_from, $date_to]);
    $merch_sales = (float)$stmt->fetchColumn();
} catch (Exception $e) {}
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_cost),0) FROM job_orders WHERE station_id=? AND DATE(created_at) BETWEEN ? AND ? AND status IN ('Completed','Verified','finalized')");
    $stmt->execute([$station_id, $date_from, $date_to]);
    $service_sales = (float)$stmt->fetchColumn();
} catch (Exception $e) {}
$todays_sales = $fuel_sales + $merch_sales + $service_sales;

// 3. Fuel Sold Today
$fuel_sold_liters = 0.0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(liters_sold),0) FROM fuel_transactions WHERE station_id=? AND DATE(transaction_date) BETWEEN ? AND ?");
    $stmt->execute([$station_id, $date_from, $date_to]);
    $fuel_sold_liters = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

// 4. Service Queue
$service_queue_count = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND DATE(created_at) BETWEEN ? AND ? AND status IN ('Pending','Reviewed','In Progress','Awaiting Parts')");
    $stmt->execute([$station_id, $date_from, $date_to]);
    $service_queue_count = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// 5. Fuel Stock Alerts
$fuel_stock_alerts_count = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM fuel_inventory WHERE station_id=? AND current_level <= reorder_level");
    $stmt->execute([$station_id]);
    $fuel_stock_alerts_count = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// 6. Merchandise Stock Alerts
$merch_stock_alerts_count = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM station_inventory WHERE station_id=? AND stock_level <= reorder_level AND status='active'");
    $stmt->execute([$station_id]);
    $merch_stock_alerts_count = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// 7. Pending Stock Requests
$pending_fuel_requests_count = 0; $pending_merch_requests_count = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM fuel_stock_requests WHERE staff_id=? AND status='Pending'");
    $stmt->execute([$user_id]);
    $pending_fuel_requests_count = (int)$stmt->fetchColumn();
} catch (Exception $e) {}
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_requests WHERE staff_id=? AND status='Pending'");
    $stmt->execute([$user_id]);
    $pending_merch_requests_count = (int)$stmt->fetchColumn();
} catch (Exception $e) {}
$pending_stock_requests = $pending_fuel_requests_count + $pending_merch_requests_count;

// 8. Current Shift
$current_shift_info = dashboard_current_shift($shift_periods);
$current_shift_label = $current_shift_info ? dashboard_shift_label($current_shift_info) : 'No Active Shift';

// ─────────────────────────────────────────────────────────────────────────────
// CHARTS QUERY BLOCK
// ─────────────────────────────────────────────────────────────────────────────

// Chart 1: Hourly Transactions (Line Chart)
$hourly_tx_query = $pdo->prepare("
    SELECT hr, COUNT(*) AS count FROM (
        SELECT HOUR(transaction_date) AS hr FROM fuel_transactions 
        WHERE station_id = ? AND DATE(transaction_date) = CURDATE()
        UNION ALL
        SELECT HOUR(COALESCE(NULLIF(transaction_date, '0000-00-00 00:00:00'), created_at)) AS hr FROM merchandise_transactions 
        WHERE station_id = ? AND DATE(COALESCE(NULLIF(transaction_date, '0000-00-00 00:00:00'), created_at)) = CURDATE()
        UNION ALL
        SELECT HOUR(created_at) AS hr FROM job_orders 
        WHERE station_id = ? AND DATE(created_at) = CURDATE()
    ) AS combined_txns
    GROUP BY hr
    ORDER BY hr
");
$hourly_tx_query->execute([$station_id, $station_id, $station_id]);
$hourly_tx_raw = $hourly_tx_query->fetchAll(PDO::FETCH_ASSOC);

$hourly_map = [];
for ($i = 0; $i < 24; $i++) {
    $hr = ($i + 6) % 24; // Start at 6 AM
    $hourly_map[$hr] = 0;
}
foreach ($hourly_tx_raw as $row) {
    $h = (int)$row['hr'];
    if (isset($hourly_map[$h])) {
        $hourly_map[$h] = (int)$row['count'];
    }
}
$hourly_chart_labels = [];
$hourly_chart_data = [];
foreach ($hourly_map as $h => $count) {
    $suffix = $h < 12 ? 'AM' : 'PM';
    $disp = $h % 12 === 0 ? 12 : $h % 12;
    $hourly_chart_labels[] = "{$disp}{$suffix}";
    $hourly_chart_data[] = $count;
}

// Chart 2: Fuel Sales by Product (Bar Chart)
$fuel_sales_query = $pdo->prepare("
    SELECT fuel_type, COALESCE(SUM(liters_sold), 0) AS total_liters
    FROM fuel_transactions
    WHERE station_id = ? AND DATE(transaction_date) BETWEEN ? AND ?
    GROUP BY fuel_type
");
$fuel_sales_query->execute([$station_id, $date_from, $date_to]);
$raw_fuel_sales = $fuel_sales_query->fetchAll(PDO::FETCH_ASSOC);

$fuel_chart_map = [];
try {
    $fuel_types_query = $pdo->prepare("
        SELECT DISTINCT fuel_type
        FROM fuel_inventory
        WHERE station_id = ?
          AND COALESCE(fuel_type, '') <> ''
        ORDER BY fuel_type
    ");
    $fuel_types_query->execute([$station_id]);
    foreach ($fuel_types_query->fetchAll(PDO::FETCH_COLUMN) as $fuel_type) {
        $fuel_chart_map[$fuel_type] = 0.0;
    }
} catch (Exception $e) {
    $fuel_chart_map = [];
}
foreach ($raw_fuel_sales as $row) {
    $fuel_type = trim((string)($row['fuel_type'] ?? ''));
    if ($fuel_type === '') {
        continue;
    }
    if (!array_key_exists($fuel_type, $fuel_chart_map)) {
        $fuel_chart_map[$fuel_type] = 0.0;
    }
    $fuel_chart_map[$fuel_type] += (float)$row['total_liters'];
}
$fuel_chart_labels = array_keys($fuel_chart_map);
$fuel_chart_data = array_values($fuel_chart_map);

// Chart 3: Merchandise Sales by Category (Bar Chart) — DB-driven, real categories
$merch_sales_query = $pdo->prepare("
    SELECT
        COALESCE(NULLIF(TRIM(mti.category), ''), 'Others') AS category,
        COALESCE(SUM(mti.subtotal), 0) AS total_sales
    FROM merchandise_transaction_items mti
    INNER JOIN merchandise_transactions mt ON mti.transaction_id = mt.id
    WHERE mt.station_id = ?
      AND DATE(COALESCE(NULLIF(mt.transaction_date, '0000-00-00 00:00:00'), mt.created_at)) BETWEEN ? AND ?
    GROUP BY category
    ORDER BY total_sales DESC
    LIMIT 6
");
$merch_sales_query->execute([$station_id, $date_from, $date_to]);
$raw_merch_sales = $merch_sales_query->fetchAll(PDO::FETCH_ASSOC);

// Use real categories from DB — no hardcoded mapping
$merch_chart_labels = [];
$merch_chart_data = [];
if (!empty($raw_merch_sales)) {
    foreach ($raw_merch_sales as $row) {
        // Shorten long category names for chart readability
        $label = $row['category'];
        if (strlen($label) > 22) {
            $label = substr($label, 0, 20) . '...';
        }
        $merch_chart_labels[] = $label;
        $merch_chart_data[] = round((float)$row['total_sales'], 2);
    }
} else {
    // Fallback — query top categories from inventory_products for this station
    try {
        $fallback = $pdo->prepare("
            SELECT ip.category, 0 AS total_sales
            FROM inventory_products ip
            WHERE ip.station_id = ? AND ip.status = 'active'
            GROUP BY ip.category
            ORDER BY COUNT(*) DESC
            LIMIT 6
        ");
        $fallback->execute([$station_id]);
        foreach ($fallback->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $label = $row['category'];
            if (strlen($label) > 22) $label = substr($label, 0, 20) . '...';
            $merch_chart_labels[] = $label;
            $merch_chart_data[] = 0;
        }
    } catch (Exception $e) {}
}

// Chart 4: Service Status Distribution (Doughnut Chart)
$service_status_query = $pdo->prepare("
    SELECT status, COUNT(*) AS count
    FROM job_orders
    WHERE station_id = ? AND DATE(created_at) BETWEEN ? AND ?
    GROUP BY status
");
$service_status_query->execute([$station_id, $date_from, $date_to]);
$raw_statuses = $service_status_query->fetchAll(PDO::FETCH_ASSOC);

$canonical_statuses = [
    'Pending' => 0,
    'In Progress' => 0,
    'Completed' => 0,
    'Released' => 0
];
foreach ($raw_statuses as $row) {
    $st = strtolower(trim($row['status']));
    if (in_array($st, ['pending', 'reviewed'])) {
        $canonical_statuses['Pending'] += (int)$row['count'];
    } elseif (in_array($st, ['in progress', 'awaiting parts'])) {
        $canonical_statuses['In Progress'] += (int)$row['count'];
    } elseif (in_array($st, ['completed', 'verified'])) {
        $canonical_statuses['Completed'] += (int)$row['count'];
    } elseif (in_array($st, ['finalized', 'released'])) {
        $canonical_statuses['Released'] += (int)$row['count'];
    }
}
$status_chart_labels = array_keys($canonical_statuses);
$status_chart_data = array_values($canonical_statuses);

// Chart 5: Weekly Transaction Trend (Line Chart)
$monday = date('Y-m-d', strtotime('monday this week', strtotime($date_from)));
$sunday = date('Y-m-d', strtotime('sunday this week', strtotime($date_from)));
$weekly_trend_query = $pdo->prepare("
    SELECT DAYNAME(dt) AS day_name, COUNT(*) AS count FROM (
        SELECT DATE(transaction_date) AS dt FROM fuel_transactions 
        WHERE station_id = ? AND DATE(transaction_date) BETWEEN ? AND ?
        UNION ALL
        SELECT DATE(COALESCE(NULLIF(transaction_date, '0000-00-00 00:00:00'), created_at)) AS dt FROM merchandise_transactions 
        WHERE station_id = ? AND DATE(COALESCE(NULLIF(transaction_date, '0000-00-00 00:00:00'), created_at)) BETWEEN ? AND ?
        UNION ALL
        SELECT DATE(created_at) AS dt FROM job_orders 
        WHERE station_id = ? AND DATE(created_at) BETWEEN ? AND ?
    ) AS all_txns
    GROUP BY day_name
");
$weekly_trend_query->execute([
    $station_id, $monday, $sunday,
    $station_id, $monday, $sunday,
    $station_id, $monday, $sunday
]);
$raw_weekly = $weekly_trend_query->fetchAll(PDO::FETCH_ASSOC);

$weekly_days = [
    'Monday' => 0,
    'Tuesday' => 0,
    'Wednesday' => 0,
    'Thursday' => 0,
    'Friday' => 0,
    'Saturday' => 0,
    'Sunday' => 0
];
foreach ($raw_weekly as $row) {
    $day = $row['day_name'];
    if (isset($weekly_days[$day])) {
        $weekly_days[$day] = (int)$row['count'];
    }
}
$weekly_chart_labels = array_keys($weekly_days);
$weekly_chart_data = array_values($weekly_days);

// Chart 6: Fuel Tank Levels (Progress Bars)
$tank_levels_query = $pdo->prepare("
    SELECT fuel_type, SUM(current_level) AS current_stock, SUM(capacity) AS total_capacity
    FROM fuel_inventory
    WHERE station_id = ?
    GROUP BY fuel_type
");
$tank_levels_query->execute([$station_id]);
$tank_levels = $tank_levels_query->fetchAll(PDO::FETCH_ASSOC);

foreach ($tank_levels as &$tl) {
    $current = (float)$tl['current_stock'];
    $capacity = (float)$tl['total_capacity'];
    $tl['pct'] = $capacity > 0 ? round(($current / $capacity) * 100, 1) : 0;
}
unset($tl);

// ─────────────────────────────────────────────────────────────────────────────
// TABLES QUERY BLOCK
// ─────────────────────────────────────────────────────────────────────────────

// Table 1: Recent Transactions
$recent_transactions_query = $pdo->prepare("
    SELECT time, type, customer, amount, status FROM (
        SELECT transaction_date AS time, 'Fuel' AS type, 'Walk-in' AS customer, total_amount AS amount, status 
        FROM fuel_transactions 
        WHERE station_id = ?
        UNION ALL
        SELECT DATE(COALESCE(NULLIF(transaction_date, '0000-00-00 00:00:00'), created_at)) AS time, 'Merchandise' AS type, customer_name AS customer, total_amount AS amount, validation_status AS status 
        FROM merchandise_transactions 
        WHERE station_id = ?
        UNION ALL
        SELECT created_at AS time, 'Service' AS type, customer_name AS customer, total_cost AS amount, status 
        FROM job_orders 
        WHERE station_id = ?
    ) AS unioned
    ORDER BY time DESC
    LIMIT 10
");
$recent_transactions_query->execute([$station_id, $station_id, $station_id]);
$recent_transactions = $recent_transactions_query->fetchAll(PDO::FETCH_ASSOC);

// Table 2: Active Service Queue
$active_services_query = $pdo->prepare("
    SELECT job_order_number AS service_no, customer_name AS customer, 
           CONCAT(vehicle_plate, ' (', vehicle_type, ')') AS vehicle, 
           service_type AS service, status 
    FROM job_orders 
    WHERE station_id = ? AND DATE(created_at) = CURDATE() AND status IN ('Pending', 'Reviewed', 'In Progress', 'Awaiting Parts')
    ORDER BY created_at DESC
");
$active_services_query->execute([$station_id]);
$active_services = $active_services_query->fetchAll(PDO::FETCH_ASSOC);

// Table 3: Fuel Stock Alerts
$fuel_stock_alerts_query = $pdo->prepare("
    SELECT fuel_type, current_level AS current_stock, reorder_level,
           CASE 
               WHEN current_level <= critical_level THEN 'Critical'
               ELSE 'Low'
           END AS status
    FROM fuel_inventory 
    WHERE station_id = ? AND current_level <= reorder_level
    ORDER BY current_level ASC
");
$fuel_stock_alerts_query->execute([$station_id]);
$fuel_stock_alerts = $fuel_stock_alerts_query->fetchAll(PDO::FETCH_ASSOC);

// Table 4: Merchandise Low Stock
$merch_low_stock_table_query = $pdo->prepare("
    SELECT COALESCE(ip.product_name, CONCAT('Product #', si.product_id)) AS product,
           si.stock_level AS current_qty,
           si.reorder_level,
           CASE 
               WHEN si.stock_level = 0 THEN 'Out of Stock'
               ELSE 'Low Stock'
           END AS status
    FROM station_inventory si
    LEFT JOIN inventory_products ip ON ip.id = si.product_id
    WHERE si.station_id = ? AND LOWER(si.status) = 'active'
      AND si.stock_level <= COALESCE(si.reorder_level, 10)
    ORDER BY si.stock_level ASC
");
$merch_low_stock_table_query->execute([$station_id]);
$merch_low_stock_table = $merch_low_stock_table_query->fetchAll(PDO::FETCH_ASSOC);

if (empty($merch_low_stock_table)) {
    try {
        $merch_low_stock_table_query2 = $pdo->prepare("
            SELECT COALESCE(ip.product_name, CONCAT('Product #', i.product_id)) AS product,
                   i.stock_level AS current_qty,
                   i.reorder_level,
                   CASE 
                       WHEN i.stock_level = 0 THEN 'Out of Stock'
                       ELSE 'Low Stock'
                   END AS status
            FROM inventory i
            LEFT JOIN inventory_products ip ON ip.id = i.product_id
            WHERE i.station_id = ? AND LOWER(i.status) = 'active'
              AND i.stock_level <= COALESCE(i.reorder_level, 10)
            ORDER BY i.stock_level ASC
        ");
        $merch_low_stock_table_query2->execute([$station_id]);
        $merch_low_stock_table = $merch_low_stock_table_query2->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// Table 5: Pending Stock Requests
$pending_requests_table_query = $pdo->prepare("
    SELECT CONCAT('SR-', id) AS request_no, item_category AS type, ? AS requested_by, status 
    FROM stock_requests 
    WHERE staff_id = ? AND status = 'Pending'
    UNION ALL
    SELECT CONCAT('FSR-', id) AS request_no, 'Fuel' AS type, ? AS requested_by, status 
    FROM fuel_stock_requests 
    WHERE staff_id = ? AND status = 'Pending'
    ORDER BY request_no DESC
");
$pending_requests_table_query->execute([$display_name, $user_id, $display_name, $user_id]);
$pending_requests_table = $pending_requests_table_query->fetchAll(PDO::FETCH_ASSOC);

// Include standard header layout
include __DIR__ . '/../partials/header.php';
?>
<!-- Include local Chart.js from vendor folder -->
<script src="../assets/vendor/chart.js/chart.umd.min.js"></script>

<style>
    /* Rebuilt Premium Petron CSS styling */
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

    /* Quick Actions Row */
    .quick-actions-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 15px;
        margin-bottom: 25px;
    }
    .quick-action-button {
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
    .quick-action-button:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        border-color: #002F70;
        color: #002F70;
    }
    .quick-action-button i {
        font-size: 20px;
        color: #002F70;
        transition: transform 0.25s ease;
    }
    .quick-action-button:hover i {
        transform: scale(1.15);
    }

    /* 8 Summary Cards Grid */
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

    /* Charts Section */
    .charts-grid-layout {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
        margin-bottom: 30px;
    }
    @media (max-width: 992px) {
        .charts-grid-layout {
            grid-template-columns: 1fr;
        }
    }
    .chart-panel-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    }
    .chart-panel-card h3 {
        font-size: 15px;
        font-weight: 800;
        color: #002F70;
        margin: 0 0 20px 0;
        display: flex;
        align-items: center;
        gap: 8px;
        border-bottom: 1px solid #f1f5f9;
        padding-bottom: 12px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .chart-container-inner {
        position: relative;
        width: 100%;
        height: 250px;
    }

    /* Fuel Progress Bars Styling */
    .tank-progress-item {
        margin-bottom: 18px;
    }
    .tank-progress-item:last-child {
        margin-bottom: 0;
    }
    .tank-meta-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 6px;
    }
    .tank-meta-row span {
        font-size: 13px;
        font-weight: 700;
        color: #1e293b;
    }
    .tank-meta-row .volume-text {
        font-size: 12px;
        color: #64748b;
        font-weight: 500;
    }
    .progress-bar-outer {
        width: 100%;
        height: 10px;
        background: #e2e8f0;
        border-radius: 6px;
        overflow: hidden;
    }
    .progress-bar-inner {
        height: 100%;
        border-radius: 6px;
        transition: width 0.4s ease;
    }

    /* Tabbed Tables Widget — matches manager dashboard exactly */
    .tables-panel-card {
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
        border-top: none;
        border-left: none;
        border-right: none;
        background: none;
        flex-shrink: 0;
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
    .action-tab-badge.orange {
        background: #ffedd5;
        color: #ea580c;
    }
    .action-tab-badge.blue {
        background: #dbeafe;
        color: #2563eb;
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

    /* Standardized Petron Tables */
    .standard-petron-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
        text-align: left;
        table-layout: fixed; /* Fixed layout for consistent column widths */
    }
    .standard-petron-table th {
        background: #002F70;
        color: #ffffff;
        font-weight: 700;
        padding: 12px 16px;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        white-space: nowrap; /* Prevent header text wrapping */
    }
    .standard-petron-table td {
        padding: 12px 16px;
        border-bottom: 1px solid #f1f5f9;
        color: #334155;
        overflow: hidden; /* Hide overflow text */
        text-overflow: ellipsis; /* Show ellipsis for long text */
    }
    .standard-petron-table tbody tr:hover {
        background: #f8fafc;
    }
    /* Specific column widths for proper alignment */
    .standard-petron-table th:nth-child(1),
    .standard-petron-table td:nth-child(1) {
        width: 20%; /* Time column */
    }
    .standard-petron-table th:nth-child(2),
    .standard-petron-table td:nth-child(2) {
        width: 15%; /* Type column */
    }
    .standard-petron-table th:nth-child(3),
    .standard-petron-table td:nth-child(3) {
        width: 25%; /* Customer/Vehicle column */
    }
    .standard-petron-table th:nth-child(4),
    .standard-petron-table td:nth-child(4) {
        width: 20%; /* Amount/Service column */
    }
    .standard-petron-table th:nth-child(5),
    .standard-petron-table td:nth-child(5) {
        width: 20%; /* Status column */
    }
    .status-badge-pill {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
        text-transform: capitalize;
    }
    .badge-completed, .badge-released, .badge-verified, .badge-available {
        background: #dcfce7;
        color: #15803d;
    }
    .badge-pending, .badge-low, .badge-inprogress, .badge-reviewed {
        background: #fef9c3;
        color: #a16207;
    }
    .badge-critical, .badge-critical-stock, .badge-voided, .badge-outofstock {
        background: #fee2e2;
        color: #b91c1c;
    }
    .badge-info-pill {
        background: #e0f2fe;
        color: #0369a1;
    }
</style>

<!-- Welcome / Filter Banner -->
<div class="dashboard-header-container">
    <div class="welcome-meta">
        <h2>Welcome, <?= $display_name ?>!</h2>
        <p><i class="fas fa-tachometer-alt"></i> Staff Dashboard</p>
    </div>
    <div class="header-filters">
        <form method="GET" class="date-filter-form">
            <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" required>
            <span style="color:#64748b; font-weight:600; font-size:13px;">to</span>
            <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" required>
            <button type="submit" class="btn-filter-submit"><i class="fas fa-filter"></i> Filter</button>
            <?php if ($date_from !== date('Y-m-d') || $date_to !== date('Y-m-d')): ?>
                <a href="staff_dashboard.php" class="btn-filter-submit" style="background:#64748b !important; text-decoration:none; display:inline-flex; align-items:center; height:33px; line-height:33px; padding:0 12px; box-sizing:border-box;"><i class="fas fa-undo"></i> Reset</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Quick Actions -->
<div class="quick-actions-row">
    <a href="staff_transactions_hub.php?section=fuel" class="quick-action-button">
        <i class="fas fa-gas-pump"></i>
        <span>New Fuel Transaction</span>
    </a>
    <a href="staff_transactions_hub.php?section=merchandise" class="quick-action-button">
        <i class="fas fa-shopping-cart"></i>
        <span>New Merchandise</span>
    </a>
    <a href="staff_transactions_hub.php?section=merchandise&active_tab=merchandise" class="quick-action-button">
        <i class="fas fa-tools"></i>
        <span>New Service Transaction</span>
    </a>
    <a href="staff_stock_requests.php" class="quick-action-button">
        <i class="fas fa-plus-circle"></i>
        <span>Create Stock Request</span>
    </a>
    <a href="staff_transactions_hub.php?section=history" class="quick-action-button">
        <i class="fas fa-history"></i>
        <span>Transaction History</span>
    </a>
</div>

<!-- 8 Summary Cards Grid -->
<div class="summary-cards-grid">
    <!-- Today's Transactions -->
    <div class="summary-metric-card" style="border-left: 4px solid #002F70;">
        <div class="metric-details">
            <h4>Today's Transactions</h4>
            <div class="metric-value"><?= number_format($todays_transactions) ?></div>
        </div>
        <div class="metric-icon-box" style="background: #eff6ff; color: #002F70;">
            <i class="fas fa-exchange-alt"></i>
        </div>
    </div>
    <!-- Today's Sales -->
    <div class="summary-metric-card" style="border-left: 4px solid #16a34a;">
        <div class="metric-details">
            <h4>Today's Sales</h4>
            <div class="metric-value">&#8369;<?= number_format($todays_sales, 2) ?></div>
        </div>
        <div class="metric-icon-box" style="background: #f0fdf4; color: #16a34a;">
            <i class="fas fa-money-bill-wave"></i>
        </div>
    </div>
    <!-- Fuel Sold Today (Liters) -->
    <div class="summary-metric-card" style="border-left: 4px solid #dc2626;">
        <div class="metric-details">
            <h4>Fuel Sold Today</h4>
            <div class="metric-value"><?= number_format($fuel_sold_liters, 2) ?> L</div>
        </div>
        <div class="metric-icon-box" style="background: #fef2f2; color: #dc2626;">
            <i class="fas fa-gas-pump"></i>
        </div>
    </div>
    <!-- Service Queue -->
    <div class="summary-metric-card" style="border-left: 4px solid #eab308;">
        <div class="metric-details">
            <h4>Service Queue</h4>
            <div class="metric-value"><?= number_format($service_queue_count) ?></div>
        </div>
        <div class="metric-icon-box" style="background: #fef9c3; color: #eab308;">
            <i class="fas fa-wrench"></i>
        </div>
    </div>
    <!-- Fuel Stock Alerts -->
    <div class="summary-metric-card" style="border-left: 4px solid #b91c1c;">
        <div class="metric-details">
            <h4>Fuel Stock Alerts</h4>
            <div class="metric-value"><?= number_format($fuel_stock_alerts_count) ?></div>
        </div>
        <div class="metric-icon-box" style="background: #fee2e2; color: #b91c1c;">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
    </div>
    <!-- Merchandise Stock Alerts -->
    <div class="summary-metric-card" style="border-left: 4px solid #ea580c;">
        <div class="metric-details">
            <h4>Merch Stock Alerts</h4>
            <div class="metric-value"><?= number_format($merch_stock_alerts_count) ?></div>
        </div>
        <div class="metric-icon-box" style="background: #ffedd5; color: #ea580c;">
            <i class="fas fa-box-open"></i>
        </div>
    </div>
    <!-- Pending Stock Requests -->
    <div class="summary-metric-card" style="border-left: 4px solid #0891b2;">
        <div class="metric-details">
            <h4>Pending Requests</h4>
            <div class="metric-value"><?= number_format($pending_stock_requests) ?></div>
        </div>
        <div class="metric-icon-box" style="background: #ecfeff; color: #0891b2;">
            <i class="fas fa-file-import"></i>
        </div>
    </div>
    <!-- Current Shift -->
    <div class="summary-metric-card" style="border-left: 4px solid #64748b;">
        <div class="metric-details">
            <h4>Current Shift</h4>
            <div class="metric-value" style="font-size: 14px; font-weight: 700; margin-top: 10px;"><?= htmlspecialchars($current_shift_info['shift_name'] ?? 'Active Shift') ?></div>
        </div>
        <div class="metric-icon-box" style="background: #f1f5f9; color: #64748b;">
            <i class="fas fa-clock"></i>
        </div>
    </div>
</div>

<!-- Charts & Visualizations Grid -->
<div class="charts-grid-layout">
    <!-- Hourly Transactions -->
    <div class="chart-panel-card">
        <h3><i class="fas fa-chart-line"></i> Hourly Transactions</h3>
        <div class="chart-container-inner">
            <canvas id="hourlyTransactionsChart"></canvas>
        </div>
    </div>
    
    <!-- Fuel Sales by Product -->
    <div class="chart-panel-card">
        <h3><i class="fas fa-gas-pump"></i> Fuel Sales by Product (Liters)</h3>
        <div class="chart-container-inner">
            <canvas id="fuelSalesChart"></canvas>
        </div>
    </div>

    <!-- Merchandise Sales by Category -->
    <div class="chart-panel-card">
        <h3><i class="fas fa-shopping-basket"></i> Merchandise Sales by Category</h3>
        <div class="chart-container-inner">
            <canvas id="merchSalesChart"></canvas>
        </div>
    </div>

    <!-- Service Status Distribution -->
    <div class="chart-panel-card">
        <h3><i class="fas fa-chart-pie"></i> Service Status Distribution</h3>
        <div class="chart-container-inner">
            <canvas id="serviceStatusChart"></canvas>
        </div>
    </div>

    <!-- Weekly Transaction Trend -->
    <div class="chart-panel-card">
        <h3><i class="fas fa-calendar-week"></i> Weekly Transaction Trend</h3>
        <div class="chart-container-inner">
            <canvas id="weeklyTrendChart"></canvas>
        </div>
    </div>

    <!-- Fuel Tank Levels Progress Bars -->
    <div class="chart-panel-card">
        <h3><i class="fas fa-tachometer-alt"></i> Fuel Tank Levels</h3>
        <div style="padding: 10px 0;">
            <?php if (empty($tank_levels)): ?>
                <div style="text-align:center; padding: 40px 0; color:#64748b;">
                    <i class="fas fa-gas-pump" style="font-size:32px; margin-bottom:10px; display:block;"></i>
                    No tank level readings available.
                </div>
            <?php else: ?>
                <?php foreach ($tank_levels as $tl):
                    $pct = $tl['pct'];
                    $color = '#22c55e'; // Green
                    if ($pct <= 10) {
                        $color = '#ef4444'; // Red
                    } elseif ($pct <= 25) {
                        $color = '#f97316'; // Orange
                    }
                ?>
                    <div class="tank-progress-item">
                        <div class="tank-meta-row">
                            <span><?= htmlspecialchars($tl['fuel_type']) ?></span>
                            <span class="volume-text"><?= number_format($tl['current_stock'], 2) ?> / <?= number_format($tl['total_capacity'], 2) ?> L (<?= $pct ?>%)</span>
                        </div>
                        <div class="progress-bar-outer">
                            <div class="progress-bar-inner" style="width: <?= $pct ?>%; background: <?= $color ?>;"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Operational Tables Tabbed Container -->
<div class="tables-panel-card">
    <div class="action-tabs">
        <button class="action-tab active" onclick="switchTab(event, 'recent-transactions-pane')">
            <i class="fas fa-history"></i>
            <span>Recent Transactions</span>
        </button>
        <button class="action-tab" onclick="switchTab(event, 'active-service-pane')">
            <i class="fas fa-tools"></i>
            <span>Active Service Queue</span>
            <?php if (count($active_services) > 0): ?>
            <span class="action-tab-badge blue"><?= count($active_services) ?></span>
            <?php endif; ?>
        </button>
        <button class="action-tab" onclick="switchTab(event, 'fuel-alerts-pane')">
            <i class="fas fa-exclamation-triangle"></i>
            <span>Fuel Stock Alerts</span>
            <?php if ($fuel_stock_alerts_count > 0): ?>
                <span class="action-tab-badge"><?= $fuel_stock_alerts_count ?></span>
            <?php endif; ?>
        </button>
        <button class="action-tab" onclick="switchTab(event, 'merch-alerts-pane')">
            <i class="fas fa-box-open"></i>
            <span>Merchandise Low Stock</span>
            <?php if ($merch_stock_alerts_count > 0): ?>
                <span class="action-tab-badge orange"><?= $merch_stock_alerts_count ?></span>
            <?php endif; ?>
        </button>
        <button class="action-tab" onclick="switchTab(event, 'pending-requests-pane')">
            <i class="fas fa-file-import"></i>
            <span>Pending Stock Requests</span>
            <?php if ($pending_stock_requests > 0): ?>
                <span class="action-tab-badge blue"><?= $pending_stock_requests ?></span>
            <?php endif; ?>
        </button>
    </div>
    <div class="action-panel-content">
        <!-- 1. Recent Transactions -->
        <div id="recent-transactions-pane" class="action-panel-pane active">
            <table class="standard-petron-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Type</th>
                        <th>Customer</th>
                        <th>Amount</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_transactions)): ?>
                        <tr>
                            <td colspan="5" style="text-align:center; color:#64748b;">No recent transactions found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recent_transactions as $rt): ?>
                            <tr>
                                <td><?= date('M d, Y h:i A', strtotime($rt['time'])) ?></td>
                                <td><span class="status-badge-pill badge-info-pill"><?= htmlspecialchars($rt['type']) ?></span></td>
                                <td><?= htmlspecialchars($rt['customer'] ?: 'Walk-in') ?></td>
                                <td class="font-bold">&#8369;<?= number_format($rt['amount'], 2) ?></td>
                                <td>
                                    <?php 
                                        $st = strtolower($rt['status'] ?? '');
                                        $badge_class = 'badge-pending';
                                        if (in_array($st, ['completed', 'released', 'verified', 'approved'])) {
                                            $badge_class = 'badge-completed';
                                        } elseif (in_array($st, ['voided', 'rejected', 'failed'])) {
                                            $badge_class = 'badge-critical';
                                        }
                                    ?>
                                    <span class="status-badge-pill <?= $badge_class ?>"><?= htmlspecialchars($rt['status'] ?: 'Pending') ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- 2. Active Service Queue -->
        <div id="active-service-pane" class="action-panel-pane">
            <table class="standard-petron-table">
                <thead>
                    <tr>
                        <th>Service No.</th>
                        <th>Customer</th>
                        <th>Vehicle</th>
                        <th>Service</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($active_services)): ?>
                        <tr>
                            <td colspan="5" style="text-align:center; color:#64748b;">No active services currently in queue.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($active_services as $as): ?>
                            <tr>
                                <td class="font-bold"><?= htmlspecialchars($as['service_no']) ?></td>
                                <td><?= htmlspecialchars($as['customer'] ?: 'Walk-in') ?></td>
                                <td><?= htmlspecialchars($as['vehicle']) ?></td>
                                <td><?= htmlspecialchars($as['service']) ?></td>
                                <td>
                                    <?php
                                        $st = strtolower($as['status'] ?? '');
                                        $badge_class = 'badge-pending';
                                        if ($st === 'in progress') {
                                            $badge_class = 'badge-inprogress';
                                        } elseif ($st === 'completed') {
                                            $badge_class = 'badge-completed';
                                        }
                                    ?>
                                    <span class="status-badge-pill <?= $badge_class ?>"><?= htmlspecialchars($as['status']) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- 3. Fuel Stock Alerts -->
        <div id="fuel-alerts-pane" class="action-panel-pane">
            <table class="standard-petron-table">
                <thead>
                    <tr>
                        <th>Fuel Type</th>
                        <th>Current Stock</th>
                        <th>Reorder Level</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($fuel_stock_alerts)): ?>
                        <tr>
                            <td colspan="4" style="text-align:center; color:#64748b;">All fuel tanks are at safe operating levels.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($fuel_stock_alerts as $fa): ?>
                            <tr>
                                <td class="font-bold"><?= htmlspecialchars($fa['fuel_type']) ?></td>
                                <td><?= number_format($fa['current_stock'], 2) ?> L</td>
                                <td><?= number_format($fa['reorder_level'], 2) ?> L</td>
                                <td>
                                    <?php
                                        $st = strtolower($fa['status'] ?? '');
                                        $badge_class = 'badge-critical';
                                        if ($st === 'low') {
                                            $badge_class = 'badge-low';
                                        }
                                    ?>
                                    <span class="status-badge-pill <?= $badge_class ?>"><?= htmlspecialchars($fa['status']) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- 4. Merchandise Low Stock -->
        <div id="merch-alerts-pane" class="action-panel-pane">
            <table class="standard-petron-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Current Qty</th>
                        <th>Reorder Level</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($merch_low_stock_table)): ?>
                        <tr>
                            <td colspan="4" style="text-align:center; color:#64748b;">All merchandise items have healthy stock levels.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($merch_low_stock_table as $ml): ?>
                            <tr>
                                <td class="font-bold"><?= htmlspecialchars($ml['product']) ?></td>
                                <td><?= number_format($ml['current_qty']) ?></td>
                                <td><?= number_format($ml['reorder_level']) ?></td>
                                <td>
                                    <?php
                                        $st = strtolower($ml['status'] ?? '');
                                        $badge_class = 'badge-critical';
                                        if ($st === 'low stock') {
                                            $badge_class = 'badge-low';
                                        }
                                    ?>
                                    <span class="status-badge-pill <?= $badge_class ?>"><?= htmlspecialchars($ml['status']) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- 5. Pending Stock Requests -->
        <div id="pending-requests-pane" class="action-panel-pane">
            <table class="standard-petron-table">
                <thead>
                    <tr>
                        <th>Request No.</th>
                        <th>Type</th>
                        <th>Requested By</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pending_requests_table)): ?>
                        <tr>
                            <td colspan="4" style="text-align:center; color:#64748b;">You have no pending stock requests.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($pending_requests_table as $pr): ?>
                            <tr>
                                <td class="font-bold"><?= htmlspecialchars($pr['request_no']) ?></td>
                                <td><span class="status-badge-pill badge-info-pill"><?= htmlspecialchars($pr['type']) ?></span></td>
                                <td><?= htmlspecialchars($pr['requested_by']) ?></td>
                                <td>
                                    <span class="status-badge-pill badge-pending"><?= htmlspecialchars($pr['status']) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Chart Script Logic -->
<script>
    // Tab switching function
    function switchTab(evt, paneId) {
        const tabs = document.getElementsByClassName('action-tab');
        for (let i = 0; i < tabs.length; i++) {
            tabs[i].classList.remove('active');
        }
        const panes = document.getElementsByClassName('action-panel-pane');
        for (let i = 0; i < panes.length; i++) {
            panes[i].classList.remove('active');
        }
        evt.currentTarget.classList.add('active');
        document.getElementById(paneId).classList.add('active');
    }

    // Chart.js global theme configurations
    Chart.defaults.font.family = "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif";
    Chart.defaults.font.size = 12;
    Chart.defaults.color = '#475569';

    // Chart 1: Hourly Transactions Line Chart
    const hourlyCtx = document.getElementById('hourlyTransactionsChart').getContext('2d');
    new Chart(hourlyCtx, {
        type: 'line',
        data: {
            labels: <?= json_encode($hourly_chart_labels) ?>,
            datasets: [{
                label: 'Transactions count',
                data: <?= json_encode($hourly_chart_data) ?>,
                borderColor: '#002F70',
                backgroundColor: 'rgba(0, 47, 112, 0.1)',
                tension: 0.3,
                fill: true,
                borderWidth: 2,
                pointBackgroundColor: '#002F70'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { stepSize: 1 }
                }
            }
        }
    });

    // Chart 2: Fuel Sales by Product Bar Chart
    const fuelCtx = document.getElementById('fuelSalesChart').getContext('2d');
    new Chart(fuelCtx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($fuel_chart_labels) ?>,
            datasets: [{
                label: 'Liters Sold',
                data: <?= json_encode($fuel_chart_data) ?>,
                backgroundColor: '#3b82f6',
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });

    // Chart 3: Merchandise Sales by Category Bar Chart
    const merchLabels = <?= json_encode($merch_chart_labels) ?>;
    const merchData   = <?= json_encode($merch_chart_data) ?>;
    const merchCtx = document.getElementById('merchSalesChart').getContext('2d');
    const hasAnySales = merchData.some(v => v > 0);
    if (!hasAnySales) {
        const el = document.getElementById('merchSalesChart').parentElement;
        el.innerHTML = '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;color:#94a3b8;"><i class="fas fa-shopping-basket" style="font-size:32px;margin-bottom:10px;"></i><span style="font-size:13px;font-weight:600;">No merchandise sales for this period</span></div>';
    } else {
        const barColors = ['#16a34a','#2563eb','#ea580c','#8b5cf6','#0891b2','#eab308'];
        new Chart(merchCtx, {
            type: 'bar',
            data: {
                labels: merchLabels,
                datasets: [{
                    label: 'Sales (₱)',
                    data: merchData,
                    backgroundColor: merchLabels.map((_,i) => barColors[i % barColors.length]),
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true } }
            }
        });
    }

    // Chart 4: Service Status Distribution Doughnut Chart
    const svcData   = <?= json_encode($status_chart_data) ?>;
    const svcLabels = <?= json_encode($status_chart_labels) ?>;
    const serviceCtx = document.getElementById('serviceStatusChart').getContext('2d');
    if (svcData.every(v => v === 0)) {
        const el2 = document.getElementById('serviceStatusChart').parentElement;
        el2.innerHTML = '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;color:#94a3b8;"><i class="fas fa-tools" style="font-size:32px;margin-bottom:10px;"></i><span style="font-size:13px;font-weight:600;">No service orders for this period</span></div>';
    } else {
        new Chart(serviceCtx, {
            type: 'doughnut',
            data: {
                labels: svcLabels,
                datasets: [{
                    data: svcData,
                    backgroundColor: ['#eab308','#3b82f6','#16a34a','#64748b'],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'right', labels: { boxWidth: 12 } } }
            }
        });
    }

    // Chart 5: Weekly Transaction Trend Line Chart
    const weeklyCtx = document.getElementById('weeklyTrendChart').getContext('2d');
    new Chart(weeklyCtx, {
        type: 'line',
        data: {
            labels: <?= json_encode($weekly_chart_labels) ?>,
            datasets: [{
                label: 'Transactions count',
                data: <?= json_encode($weekly_chart_data) ?>,
                borderColor: '#16a34a',
                backgroundColor: 'rgba(22, 163, 74, 0.05)',
                tension: 0.3,
                fill: true,
                borderWidth: 2,
                pointBackgroundColor: '#16a34a'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { stepSize: 1 }
                }
            }
        }
    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
