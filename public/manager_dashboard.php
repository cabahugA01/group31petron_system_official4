<?php
/**
 * Manager Dashboard
 *
 * Station command center for operations, approvals, inventory, pricing,
 * transactions, service queue, quick actions, and calendar.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$page_id = 'manager_dashboard';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();
$user_id = (int) ($me['id'] ?? ($_SESSION['user_id'] ?? 0));

if (!in_array($role, ['manager', 'admin', 'superadmin', 'developer'], true)) {
    header('Location: dashboard.php');
    exit;
}

if (!$station_id && $role === 'manager') {
    render_no_station_page('manager_dashboard.php');
}

$date_filter = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_filter)) {
    $date_filter = date('Y-m-d');
}

function mgr_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function mgr_money($value): string
{
    return '&#8369;' . number_format((float) $value, 2);
}

function mgr_qty($value, int $decimals = 2): string
{
    return number_format((float) $value, $decimals);
}

function mgr_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
        );
        $stmt->execute([$table]);
        $cache[$table] = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $cache[$table] = false;
    }

    return $cache[$table];
}

function mgr_column_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
        );
        $stmt->execute([$table, $column]);
        $cache[$key] = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

function mgr_value(PDO $pdo, string $sql, array $params = [], $default = 0)
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? $default : $value;
    } catch (Throwable $e) {
        error_log('[manager_dashboard] value query failed: ' . $e->getMessage() . ' | ' . $sql);
        return $default;
    }
}

function mgr_rows(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[manager_dashboard] rows query failed: ' . $e->getMessage() . ' | ' . $sql);
        return [];
    }
}

function mgr_station_clause(int $station_id, string $alias = ''): string
{
    if (!$station_id) {
        return '1=1';
    }
    $prefix = $alias !== '' ? $alias . '.' : '';
    return $prefix . 'station_id = ?';
}

function mgr_station_params(int $station_id): array
{
    return $station_id ? [$station_id] : [];
}

function mgr_badge_class($status): string
{
    $s = strtolower(trim((string) $status));
    if ($s === '' || str_contains($s, 'pending') || str_contains($s, 'await')) {
        return 'warning';
    }
    if (str_contains($s, 'approved') || str_contains($s, 'verified') || str_contains($s, 'received') || str_contains($s, 'normal') || str_contains($s, 'confirmed')) {
        return 'success';
    }
    if (str_contains($s, 'reject') || str_contains($s, 'cancel') || str_contains($s, 'critical') || str_contains($s, 'out')) {
        return 'danger';
    }
    if (str_contains($s, 'progress') || str_contains($s, 'review') || str_contains($s, 'ready') || str_contains($s, 'adjust')) {
        return 'info';
    }
    if (str_contains($s, 'low') || str_contains($s, 'short') || str_contains($s, 'damaged')) {
        return 'warning';
    }
    return 'neutral';
}

function mgr_status_label($status): string
{
    $status = trim((string) $status);
    return $status !== '' ? $status : 'Pending';
}

$display_name = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''));
if ($display_name === '') {
    $display_name = $me['full_name'] ?? $me['name'] ?? $me['username'] ?? 'Manager';
}

$station_label = $me['station_name'] ?? '';
if ($station_label === '' && $station_id) {
    $station_label = (string) mgr_value($pdo, 'SELECT name FROM stations WHERE id = ?', [$station_id], 'Station #' . $station_id);
}
if ($station_label === '') {
    $station_label = 'All Stations';
}

$station_sql = mgr_station_clause($station_id);
$station_params = mgr_station_params($station_id);

// Summary cards.
$fuel_count = mgr_table_exists($pdo, 'fuel_transactions')
    ? (int) mgr_value($pdo, "SELECT COUNT(*) FROM fuel_transactions WHERE {$station_sql} AND DATE(transaction_date) = ?", array_merge($station_params, [$date_filter]))
    : 0;

$merch_count = mgr_table_exists($pdo, 'merchandise_transactions')
    ? (int) mgr_value($pdo, "SELECT COUNT(*) FROM merchandise_transactions WHERE {$station_sql} AND DATE(COALESCE(transaction_date, created_at)) = ?", array_merge($station_params, [$date_filter]))
    : 0;

$service_count = mgr_table_exists($pdo, 'job_orders')
    ? (int) mgr_value($pdo, "SELECT COUNT(*) FROM job_orders WHERE {$station_sql} AND DATE(created_at) = ?", array_merge($station_params, [$date_filter]))
    : 0;

$total_transactions = $fuel_count + $merch_count + $service_count;

$fuel_revenue = mgr_table_exists($pdo, 'fuel_transactions')
    ? (float) mgr_value($pdo, "SELECT COALESCE(SUM(total_amount), 0) FROM fuel_transactions WHERE {$station_sql} AND DATE(transaction_date) = ?", array_merge($station_params, [$date_filter]))
    : 0.0;

$merch_revenue = mgr_table_exists($pdo, 'merchandise_transactions')
    ? (float) mgr_value($pdo, "SELECT COALESCE(SUM(total_amount), 0) FROM merchandise_transactions WHERE {$station_sql} AND DATE(COALESCE(transaction_date, created_at)) = ?", array_merge($station_params, [$date_filter]))
    : 0.0;

$service_revenue = mgr_table_exists($pdo, 'job_orders')
    ? (float) mgr_value(
        $pdo,
        "SELECT COALESCE(SUM(COALESCE(total_cost, estimated_cost, actual_labor_cost + actual_parts_cost, 0)), 0)
         FROM job_orders
         WHERE {$station_sql}
           AND DATE(created_at) = ?
           AND LOWER(COALESCE(status, '')) IN ('completed', 'verified', 'finalized', 'released')",
        array_merge($station_params, [$date_filter])
    )
    : 0.0;

$total_revenue = $fuel_revenue + $merch_revenue + $service_revenue;

$total_fuel_liters = mgr_table_exists($pdo, 'fuel_transactions')
    ? (float) mgr_value($pdo, "SELECT COALESCE(SUM(liters_sold), 0) FROM fuel_transactions WHERE {$station_sql} AND DATE(transaction_date) = ?", array_merge($station_params, [$date_filter]))
    : 0.0;

$pending_merch_stock = mgr_table_exists($pdo, 'stock_requests')
    ? (int) mgr_value($pdo, "SELECT COUNT(*) FROM stock_requests WHERE {$station_sql} AND LOWER(status) = 'pending'", $station_params)
    : 0;

$pending_fuel_stock = mgr_table_exists($pdo, 'fuel_stock_requests')
    ? (int) mgr_value($pdo, "SELECT COUNT(*) FROM fuel_stock_requests WHERE {$station_sql} AND LOWER(status) = 'pending'", $station_params)
    : 0;

$pending_customer_requests = mgr_table_exists($pdo, 'customers')
    ? (int) mgr_value(
        $pdo,
        "SELECT COUNT(*) FROM customers
         WHERE {$station_sql}
           AND LOWER(COALESCE(status, 'active')) <> 'inactive'
           AND (
                LOWER(COALESCE(verification_status, '')) = 'pending'
                OR LOWER(COALESCE(mgr_status, '')) = 'pending'
           )",
        $station_params
    )
    : 0;

$pending_price_requests = mgr_table_exists($pdo, 'pending_price_approvals')
    ? (int) mgr_value($pdo, "SELECT COUNT(*) FROM pending_price_approvals WHERE {$station_sql} AND LOWER(status) = 'pending'", $station_params)
    : 0;

$total_pending_approvals = $pending_merch_stock + $pending_fuel_stock + $pending_customer_requests + $pending_price_requests;

$low_fuel_count = 0;
$out_fuel_count = 0;
if (mgr_table_exists($pdo, 'fuel_inventory')) {
    $fuel_inv_rows = mgr_rows($pdo, "SELECT current_level, current_stock, capacity FROM fuel_inventory WHERE {$station_sql}", $station_params);
    foreach ($fuel_inv_rows as $fi_row) {
        $capacity = (float)($fi_row['capacity'] ?? 0);
        $level = min(max(0, (float)($fi_row['current_level'] ?? $fi_row['current_stock'] ?? 0)), $capacity);
        if ($capacity == 14000)    { $critical_lvl = 5000; $low_lvl = 7000; }
        elseif ($capacity == 7000) { $critical_lvl = 1000; $low_lvl = 2000; }
        else                       { $critical_lvl = $capacity * 0.10; $low_lvl = $capacity * 0.20; }
        if ($level <= 0) {
            $out_fuel_count++;
        } elseif ($level <= $critical_lvl || $level <= $low_lvl) {
            $low_fuel_count++;
        }
    }
}

$low_merch_count = mgr_table_exists($pdo, 'station_inventory')
    ? (int) mgr_value($pdo, "SELECT COUNT(*) FROM station_inventory WHERE {$station_sql} AND status = 'active' AND COALESCE(stock_level, 0) <= COALESCE(reorder_level, 0)", $station_params)
    : 0;

$out_merch_count = mgr_table_exists($pdo, 'station_inventory')
    ? (int) mgr_value($pdo, "SELECT COUNT(*) FROM station_inventory WHERE {$station_sql} AND status = 'active' AND COALESCE(stock_level, 0) <= 0", $station_params)
    : 0;

$out_stock_count = $out_fuel_count + $out_merch_count;
$total_inventory_alerts = $low_fuel_count + $low_merch_count + $out_stock_count;

$pending_po_deliveries = mgr_table_exists($pdo, 'purchase_orders')
    ? (int) mgr_value(
        $pdo,
        "SELECT COUNT(*) FROM purchase_orders
         WHERE {$station_sql}
           AND admin_finalized = 1
           AND delivery_validated = 0
           AND stock_in_done = 0",
        $station_params
    )
    : 0;

$pending_encoded_deliveries = mgr_table_exists($pdo, 'deliveries_oversight')
    ? (int) mgr_value(
        $pdo,
        "SELECT COUNT(*) FROM deliveries_oversight
         WHERE {$station_sql}
           AND LOWER(status) IN ('pending manager approval', 'pending validation', 'pending verification', 'pending manager confirmation', 'approved - ready for stock-in', 'adjusted - ready for stock-in')",
        $station_params
    )
    : 0;

$pending_deliveries_count = $pending_po_deliveries + $pending_encoded_deliveries;

$active_services_count = mgr_table_exists($pdo, 'job_orders')
    ? (int) mgr_value(
        $pdo,
        "SELECT COUNT(*) FROM job_orders
         WHERE {$station_sql}
           AND LOWER(COALESCE(status, 'pending')) NOT IN ('cancelled', 'rejected', 'finalized')",
        $station_params
    )
    : 0;

$staff_shift_counts = ['Shift 1' => 0, 'Shift 2' => 0];
if (mgr_table_exists($pdo, 'labor_sessions')) {
    $staff_rows = mgr_rows(
        $pdo,
        "SELECT COALESCE(NULLIF(shift_name, ''), NULLIF(shift_period, ''), 'Unassigned') AS shift_label,
                COUNT(DISTINCT user_id) AS staff_count
         FROM labor_sessions
         WHERE {$station_sql}
           AND DATE(start_time) = ?
           AND end_time IS NULL
         GROUP BY shift_label",
        array_merge($station_params, [$date_filter])
    );
    foreach ($staff_rows as $row) {
        $label = (string) ($row['shift_label'] ?? 'Unassigned');
        $count = (int) ($row['staff_count'] ?? 0);
        if (stripos($label, '1') !== false || stripos($label, 'morning') !== false) {
            $staff_shift_counts['Shift 1'] += $count;
        } elseif (stripos($label, '2') !== false || stripos($label, 'afternoon') !== false) {
            $staff_shift_counts['Shift 2'] += $count;
        } else {
            $staff_shift_counts[$label] = ($staff_shift_counts[$label] ?? 0) + $count;
        }
    }
}
$active_staff_total = array_sum($staff_shift_counts);

$service_status_counts = ['Pending' => 0, 'In Progress' => 0, 'Ready' => 0, 'Released' => 0];
if (mgr_table_exists($pdo, 'job_orders')) {
    $service_status_counts['Pending'] = (int) mgr_value($pdo, "SELECT COUNT(*) FROM job_orders WHERE {$station_sql} AND LOWER(status) IN ('pending', 'reviewed')", $station_params);
    $service_status_counts['In Progress'] = (int) mgr_value($pdo, "SELECT COUNT(*) FROM job_orders WHERE {$station_sql} AND LOWER(status) IN ('in progress', 'awaiting parts')", $station_params);
    $service_status_counts['Ready'] = (int) mgr_value($pdo, "SELECT COUNT(*) FROM job_orders WHERE {$station_sql} AND LOWER(status) IN ('completed', 'verified')", $station_params);
    $service_status_counts['Released'] = (int) mgr_value($pdo, "SELECT COUNT(*) FROM job_orders WHERE {$station_sql} AND LOWER(status) IN ('released', 'finalized')", $station_params);
}

// Chart data.
$hour_labels = [];
$hourly_sales = [];
for ($h = 6; $h <= 23; $h++) {
    $hour_labels[] = date('ga', strtotime(sprintf('%02d:00:00', $h)));
    $start = $date_filter . ' ' . sprintf('%02d:00:00', $h);
    $end = $date_filter . ' ' . sprintf('%02d:59:59', $h);

    $fuel_hour = mgr_table_exists($pdo, 'fuel_transactions')
        ? (float) mgr_value($pdo, "SELECT COALESCE(SUM(total_amount), 0) FROM fuel_transactions WHERE {$station_sql} AND transaction_date BETWEEN ? AND ?", array_merge($station_params, [$start, $end]))
        : 0.0;
    $merch_hour = mgr_table_exists($pdo, 'merchandise_transactions')
        ? (float) mgr_value($pdo, "SELECT COALESCE(SUM(total_amount), 0) FROM merchandise_transactions WHERE {$station_sql} AND COALESCE(transaction_date, created_at) BETWEEN ? AND ?", array_merge($station_params, [$start, $end]))
        : 0.0;
    $service_hour = mgr_table_exists($pdo, 'job_orders')
        ? (float) mgr_value(
            $pdo,
            "SELECT COALESCE(SUM(COALESCE(total_cost, estimated_cost, actual_labor_cost + actual_parts_cost, 0)), 0)
             FROM job_orders
             WHERE {$station_sql}
               AND created_at BETWEEN ? AND ?
               AND LOWER(COALESCE(status, '')) IN ('completed', 'verified', 'finalized', 'released')",
            array_merge($station_params, [$start, $end])
        )
        : 0.0;

    $hourly_sales[] = $fuel_hour + $merch_hour + $service_hour;
}

$fuel_product_rules = [
    'Diesel' => "(LOWER(fuel_type) LIKE '%diesel%' AND LOWER(fuel_type) NOT LIKE '%turbo%')",
    'XCS' => "LOWER(fuel_type) LIKE '%xcs%'",
    'Turbo Diesel' => "(LOWER(fuel_type) LIKE '%turbo%' OR LOWER(fuel_type) LIKE '%turbo diesel%')",
    'XTRA' => "(LOWER(fuel_type) LIKE '%xtra%' OR LOWER(fuel_type) LIKE '%unleaded%')",
    'Kerosene' => "LOWER(fuel_type) LIKE '%kerosene%'",
];
$fuel_products = array_keys($fuel_product_rules);
$fuel_sales_data = [];
foreach ($fuel_product_rules as $rule) {
    $fuel_sales_data[] = mgr_table_exists($pdo, 'fuel_transactions')
        ? (float) mgr_value($pdo, "SELECT COALESCE(SUM(liters_sold), 0) FROM fuel_transactions WHERE {$station_sql} AND DATE(transaction_date) = ? AND {$rule}", array_merge($station_params, [$date_filter]))
        : 0.0;
}

$merch_category_rules = [
    'Lubricants' => ["%lubricant%", "%lube%", "%grease%", "%oil%"],
    'Drinks' => ["%drink%", "%beverage%", "%water%", "%juice%", "%cola%"],
    'Snacks' => ["%snack%", "%biscuit%", "%cracker%", "%chips%", "%candy%"],
    'Accessories' => ["%accessor%", "%air freshener%", "%car%", "%tire%", "%patch%"],
    'Engine Oil' => ["%engine oil%", "%mo30%", "%mo40%", "%motor oil%"],
];
$merch_categories = array_keys($merch_category_rules);
$merch_sales_data = [];
foreach ($merch_category_rules as $patterns) {
    if (!mgr_table_exists($pdo, 'merchandise_transaction_items') || !mgr_table_exists($pdo, 'merchandise_transactions')) {
        $merch_sales_data[] = 0.0;
        continue;
    }

    $like_sql = [];
    $like_params = [];
    foreach ($patterns as $pattern) {
        $like_sql[] = 'LOWER(mti.category) LIKE ?';
        $like_sql[] = 'LOWER(mti.product_name) LIKE ?';
        $like_params[] = $pattern;
        $like_params[] = $pattern;
    }

    $merch_sales_data[] = (float) mgr_value(
        $pdo,
        "SELECT COALESCE(SUM(mti.subtotal), 0)
         FROM merchandise_transaction_items mti
         INNER JOIN merchandise_transactions mt ON mt.id = mti.transaction_id
         WHERE " . mgr_station_clause($station_id, 'mt') . "
           AND DATE(COALESCE(mt.transaction_date, mt.created_at)) = ?
           AND (" . implode(' OR ', $like_sql) . ')',
        array_merge($station_params, [$date_filter], $like_params)
    );
}

$selected_ts = strtotime($date_filter);
$week_start = date('Y-m-d', strtotime('monday this week', $selected_ts));
$weekly_labels = [];
$weekly_revenue = [];
for ($i = 0; $i < 7; $i++) {
    $day = date('Y-m-d', strtotime($week_start . " +{$i} days"));
    $weekly_labels[] = date('D', strtotime($day));

    $fuel_day = mgr_table_exists($pdo, 'fuel_transactions')
        ? (float) mgr_value($pdo, "SELECT COALESCE(SUM(total_amount), 0) FROM fuel_transactions WHERE {$station_sql} AND DATE(transaction_date) = ?", array_merge($station_params, [$day]))
        : 0.0;
    $merch_day = mgr_table_exists($pdo, 'merchandise_transactions')
        ? (float) mgr_value($pdo, "SELECT COALESCE(SUM(total_amount), 0) FROM merchandise_transactions WHERE {$station_sql} AND DATE(COALESCE(transaction_date, created_at)) = ?", array_merge($station_params, [$day]))
        : 0.0;
    $service_day = mgr_table_exists($pdo, 'job_orders')
        ? (float) mgr_value(
            $pdo,
            "SELECT COALESCE(SUM(COALESCE(total_cost, estimated_cost, actual_labor_cost + actual_parts_cost, 0)), 0)
             FROM job_orders
             WHERE {$station_sql}
               AND DATE(created_at) = ?
               AND LOWER(COALESCE(status, '')) IN ('completed', 'verified', 'finalized', 'released')",
            array_merge($station_params, [$day])
        )
        : 0.0;
    $weekly_revenue[] = $fuel_day + $merch_day + $service_day;
}

$inventory_labels = [];
$inventory_current = [];
$inventory_remaining = [];
$inventory_colors = [];
if (mgr_table_exists($pdo, 'fuel_inventory')) {
    $fuel_inventory_rows = mgr_rows(
        $pdo,
        "SELECT fuel_type AS label,
                COALESCE(current_level, current_stock, 0) AS current_qty,
                COALESCE(NULLIF(capacity, 0), COALESCE(current_level, current_stock, 0)) AS capacity_qty,
                COALESCE(reorder_level, 0) AS reorder_level,
                COALESCE(critical_level, 0) AS critical_level
         FROM fuel_inventory
         WHERE {$station_sql}
         ORDER BY fuel_type
         LIMIT 8",
        $station_params
    );
    foreach ($fuel_inventory_rows as $row) {
        $capacity = (float) $row['capacity_qty'];
        $current = min(max(0, (float) $row['current_qty']), $capacity);
        if ($capacity == 14000) {
            $critical_lvl = 5000; $low_lvl = 7000;
        } elseif ($capacity == 7000) {
            $critical_lvl = 1000; $low_lvl = 2000;
        } else {
            $critical_lvl = $capacity * 0.10; $low_lvl = $capacity * 0.20;
        }
        $inventory_labels[] = 'Fuel: ' . ($row['label'] ?: 'Tank');
        $inventory_current[] = $current;
        $inventory_remaining[] = max($capacity - $current, 0);
        $inventory_colors[] = $current <= $critical_lvl ? '#dc2626' : ($current <= $low_lvl ? '#f97316' : '#22c55e');
    }
}

if (mgr_table_exists($pdo, 'station_inventory')) {
    $merch_inventory_rows = mgr_rows(
        $pdo,
        "SELECT COALESCE(pc.name, ip.category, p.name, 'Merchandise') AS label,
                SUM(COALESCE(si.stock_level, 0)) AS current_qty,
                SUM(CASE
                    WHEN COALESCE(si.capacity, 0) > 0 THEN si.capacity
                    WHEN COALESCE(si.reorder_level, 0) > 0 THEN si.reorder_level * 2
                    ELSE COALESCE(si.stock_level, 0)
                END) AS capacity_qty,
                SUM(COALESCE(si.reorder_level, 0)) AS reorder_qty
         FROM station_inventory si
         LEFT JOIN products p ON p.id = si.product_id
         LEFT JOIN product_categories pc ON pc.id = p.category_id
         LEFT JOIN inventory_products ip ON ip.id = si.product_id
         WHERE " . mgr_station_clause($station_id, 'si') . "
           AND si.status = 'active'
         GROUP BY label
         ORDER BY current_qty ASC
         LIMIT 6",
        $station_params
    );
    foreach ($merch_inventory_rows as $row) {
        $current = (float) $row['current_qty'];
        $capacity = max((float) $row['capacity_qty'], $current);
        $reorder = (float) $row['reorder_qty'];
        $inventory_labels[] = 'Merch: ' . ($row['label'] ?: 'Products');
        $inventory_current[] = $current;
        $inventory_remaining[] = max($capacity - $current, 0);
        $inventory_colors[] = $current <= 0 ? '#dc2626' : ($current <= $reorder ? '#f59e0b' : '#22c55e');
    }
}

// Manager panels.
$stock_request_rows = [];
if (mgr_table_exists($pdo, 'stock_requests')) {
    $rows = mgr_rows(
        $pdo,
        "SELECT sr.id,
                CONCAT('SR-', LPAD(sr.id, 4, '0')) AS request_no,
                'Merchandise' AS request_type,
                sr.item_name AS item_name,
                sr.requested_quantity AS requested_qty,
                sr.status,
                sr.created_at,
                COALESCE(NULLIF(CONCAT_WS(' ', u.first_name, u.last_name), ''), u.username, 'Staff') AS requested_by,
                'manager_stock_request_review.php' AS action_url
         FROM stock_requests sr
         LEFT JOIN users u ON u.id = sr.staff_id
         WHERE " . mgr_station_clause($station_id, 'sr') . "
           AND LOWER(sr.status) = 'pending'
         ORDER BY sr.created_at DESC
         LIMIT 8",
        $station_params
    );
    $stock_request_rows = array_merge($stock_request_rows, $rows);
}
if (mgr_table_exists($pdo, 'fuel_stock_requests')) {
    $rows = mgr_rows(
        $pdo,
        "SELECT fsr.id,
                CONCAT('FSR-', LPAD(fsr.id, 4, '0')) AS request_no,
                'Fuel' AS request_type,
                fsr.fuel_type AS item_name,
                fsr.requested_liters AS requested_qty,
                fsr.status,
                fsr.created_at,
                COALESCE(NULLIF(CONCAT_WS(' ', u.first_name, u.last_name), ''), u.username, 'Staff') AS requested_by,
                'manager_stock_request_review.php?subtab=fuel' AS action_url
         FROM fuel_stock_requests fsr
         LEFT JOIN users u ON u.id = fsr.staff_id
         WHERE " . mgr_station_clause($station_id, 'fsr') . "
           AND LOWER(fsr.status) = 'pending'
         ORDER BY fsr.created_at DESC
         LIMIT 8",
        $station_params
    );
    $stock_request_rows = array_merge($stock_request_rows, $rows);
}
usort($stock_request_rows, fn($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
$stock_request_rows = array_slice($stock_request_rows, 0, 8);

$customer_request_rows = mgr_table_exists($pdo, 'customers')
    ? mgr_rows(
        $pdo,
        "SELECT id,
                name AS customer_name,
                COALESCE(NULLIF(contact_number, ''), NULLIF(phone, ''), NULLIF(email, ''), 'No contact') AS contact,
                COALESCE(NULLIF(contact_person, ''), 'Customer Form') AS requested_by,
                COALESCE(verification_status, mgr_status, 'pending') AS status,
                created_at
         FROM customers
         WHERE {$station_sql}
           AND LOWER(COALESCE(status, 'active')) <> 'inactive'
           AND (
                LOWER(COALESCE(verification_status, '')) = 'pending'
                OR LOWER(COALESCE(mgr_status, '')) = 'pending'
           )
         ORDER BY created_at DESC
         LIMIT 8",
        $station_params
    )
    : [];

$delivery_rows = [];
if (mgr_table_exists($pdo, 'purchase_orders')) {
    $delivery_rows = array_merge($delivery_rows, mgr_rows(
        $pdo,
        "SELECT po.id,
                po.po_number AS delivery_no,
                COALESCE(s.name, 'Supplier pending') AS supplier,
                po.status,
                COALESCE(po.expected_delivery_date, po.admin_finalized_at, po.created_at) AS event_date,
                'Purchase Order' AS delivery_type,
                'manager_delivery_validation.php' AS receive_url,
                'manager_delivery_validation.php' AS view_url,
                'staff_stock_in.php' AS stock_url
         FROM purchase_orders po
         LEFT JOIN suppliers s ON s.id = po.supplier_id
         WHERE " . mgr_station_clause($station_id, 'po') . "
           AND po.admin_finalized = 1
           AND po.delivery_validated = 0
           AND po.stock_in_done = 0
         ORDER BY COALESCE(po.expected_delivery_date, po.admin_finalized_at, po.created_at) ASC
         LIMIT 8",
        $station_params
    ));
}
if (mgr_table_exists($pdo, 'deliveries_oversight')) {
    $delivery_rows = array_merge($delivery_rows, mgr_rows(
        $pdo,
        "SELECT id,
                delivery_ref AS delivery_no,
                supplier,
                status,
                delivery_date AS event_date,
                delivery_type,
                'manager_merchandise_deliveries.php' AS receive_url,
                'manager_merchandise_deliveries.php' AS view_url,
                'staff_stock_in.php' AS stock_url
         FROM deliveries_oversight
         WHERE {$station_sql}
           AND LOWER(status) IN ('pending manager approval', 'pending validation', 'pending verification', 'pending manager confirmation', 'approved - ready for stock-in', 'adjusted - ready for stock-in')
         ORDER BY delivery_date ASC, created_at DESC
         LIMIT 8",
        $station_params
    ));
}
usort($delivery_rows, fn($a, $b) => strcmp((string) ($a['event_date'] ?? ''), (string) ($b['event_date'] ?? '')));
$delivery_rows = array_slice($delivery_rows, 0, 8);

$price_rows = mgr_table_exists($pdo, 'pending_price_approvals')
    ? mgr_rows(
        $pdo,
        "SELECT p.id,
                p.product_type,
                COALESCE(NULLIF(p.product_name, ''), fi.fuel_type, ip.product_name, pr.name, CONCAT('Product #', p.product_id)) AS product_name,
                COALESCE(NULLIF(p.old_price, 0), NULLIF(p.old_value, 0), p.old_cost, 0) AS old_price,
                COALESCE(NULLIF(p.new_price, 0), NULLIF(p.new_value, 0), p.new_cost, 0) AS new_price,
                p.status,
                p.created_at,
                p.reviewed_at,
                COALESCE(NULLIF(CONCAT_WS(' ', u.first_name, u.last_name), ''), u.username, 'Manager') AS updated_by
         FROM pending_price_approvals p
         LEFT JOIN users u ON u.id = COALESCE(p.manager_id, p.requested_by, p.reviewed_by)
         LEFT JOIN fuel_inventory fi ON fi.id = p.product_id AND p.product_type LIKE '%fuel%'
         LEFT JOIN inventory_products ip ON ip.id = p.product_id AND p.product_type = 'merchandise'
         LEFT JOIN products pr ON pr.id = p.product_id
         WHERE " . mgr_station_clause($station_id, 'p') . "
         ORDER BY COALESCE(p.reviewed_at, p.created_at) DESC
         LIMIT 10",
        $station_params
    )
    : [];

$recent_transactions = [];
if (mgr_table_exists($pdo, 'fuel_transactions')) {
    $recent_transactions = array_merge($recent_transactions, mgr_rows(
        $pdo,
        "SELECT transaction_date AS txn_time,
                'Fuel' AS txn_type,
                transaction_id AS reference_no,
                fuel_type AS detail,
                'Walk-in' AS customer_name,
                total_amount AS amount,
                status
         FROM fuel_transactions
         WHERE {$station_sql}
         ORDER BY transaction_date DESC
         LIMIT 10",
        $station_params
    ));
}
if (mgr_table_exists($pdo, 'merchandise_transactions')) {
    $recent_transactions = array_merge($recent_transactions, mgr_rows(
        $pdo,
        "SELECT COALESCE(transaction_date, created_at) AS txn_time,
                'Merchandise' AS txn_type,
                transaction_id AS reference_no,
                item_sku AS detail,
                COALESCE(NULLIF(customer_name, ''), 'Walk-in') AS customer_name,
                total_amount AS amount,
                validation_status AS status
         FROM merchandise_transactions
         WHERE {$station_sql}
         ORDER BY COALESCE(transaction_date, created_at) DESC
         LIMIT 10",
        $station_params
    ));
}
if (mgr_table_exists($pdo, 'job_orders')) {
    $recent_transactions = array_merge($recent_transactions, mgr_rows(
        $pdo,
        "SELECT created_at AS txn_time,
                'Service' AS txn_type,
                COALESCE(job_order_number, job_order_id, CONCAT('JO-', id)) AS reference_no,
                service_type AS detail,
                COALESCE(NULLIF(customer_name, ''), 'Walk-in') AS customer_name,
                COALESCE(total_cost, estimated_cost, actual_labor_cost + actual_parts_cost, 0) AS amount,
                status
         FROM job_orders
         WHERE {$station_sql}
         ORDER BY created_at DESC
         LIMIT 10",
        $station_params
    ));
}
usort($recent_transactions, fn($a, $b) => strcmp((string) ($b['txn_time'] ?? ''), (string) ($a['txn_time'] ?? '')));
$recent_transactions = array_slice($recent_transactions, 0, 10);

$low_inventory_rows = [];
if (mgr_table_exists($pdo, 'fuel_inventory')) {
    $fuel_all = mgr_rows($pdo, "SELECT fuel_type, current_level, current_stock, capacity FROM fuel_inventory WHERE {$station_sql}", $station_params);
    foreach ($fuel_all as $fi_row) {
        $capacity = (float)($fi_row['capacity'] ?? 0);
        $level = min(max(0, (float)($fi_row['current_level'] ?? $fi_row['current_stock'] ?? 0)), $capacity);
        if ($capacity == 14000)    { $critical_lvl = 5000; $low_lvl = 7000; }
        elseif ($capacity == 7000) { $critical_lvl = 1000; $low_lvl = 2000; }
        else                       { $critical_lvl = $capacity * 0.10; $low_lvl = $capacity * 0.20; }
        if ($level <= 0) {
            $status = 'Out of Stock';
        } elseif ($level <= $critical_lvl) {
            $status = 'Critical';
        } elseif ($level <= $low_lvl) {
            $status = 'Low';
        } else {
            $status = 'Normal';
        }
        if ($status !== 'Normal') {
            $low_inventory_rows[] = [
                'product_name' => $fi_row['fuel_type'],
                'item_type' => 'Fuel',
                'current_qty' => $level,
                'reorder_qty' => $low_lvl,
                'critical_qty' => $critical_lvl,
                'status' => $status
            ];
        }
    }
}
if (mgr_table_exists($pdo, 'station_inventory')) {
    $low_inventory_rows = array_merge($low_inventory_rows, mgr_rows(
        $pdo,
        "SELECT COALESCE(p.name, ip.product_name, CONCAT('Product #', si.product_id)) AS product_name,
                'Merchandise' AS item_type,
                COALESCE(si.stock_level, 0) AS current_qty,
                COALESCE(si.reorder_level, 0) AS reorder_qty,
                0 AS critical_qty,
                CASE
                    WHEN COALESCE(si.stock_level, 0) <= 0 THEN 'Out of Stock'
                    WHEN COALESCE(si.stock_level, 0) <= COALESCE(si.reorder_level, 0) THEN 'Low'
                    ELSE 'Normal'
                END AS status
         FROM station_inventory si
         LEFT JOIN products p ON p.id = si.product_id
         LEFT JOIN inventory_products ip ON ip.id = si.product_id
         WHERE " . mgr_station_clause($station_id, 'si') . "
           AND si.status = 'active'
           AND COALESCE(si.stock_level, 0) <= COALESCE(si.reorder_level, 0)
         ORDER BY current_qty ASC, product_name ASC
         LIMIT 10",
        $station_params
    ));
}
usort($low_inventory_rows, function ($a, $b) {
    $rank = ['Out of Stock' => 0, 'Critical' => 1, 'Low' => 2, 'Normal' => 3];
    return ($rank[$a['status'] ?? 'Normal'] ?? 3) <=> ($rank[$b['status'] ?? 'Normal'] ?? 3);
});
$low_inventory_rows = array_slice($low_inventory_rows, 0, 12);

$service_queue_rows = mgr_table_exists($pdo, 'job_orders')
    ? mgr_rows(
        $pdo,
        "SELECT id,
                COALESCE(job_order_number, job_order_id, CONCAT('JO-', id)) AS service_no,
                COALESCE(NULLIF(customer_name, ''), 'Walk-in') AS customer_name,
                service_type,
                status,
                created_at
         FROM job_orders
         WHERE {$station_sql}
           AND LOWER(COALESCE(status, 'pending')) NOT IN ('cancelled', 'rejected', 'finalized')
         ORDER BY
           CASE LOWER(status)
             WHEN 'pending' THEN 1
             WHEN 'reviewed' THEN 2
             WHEN 'in progress' THEN 3
             WHEN 'awaiting parts' THEN 4
             WHEN 'completed' THEN 5
             WHEN 'verified' THEN 6
             ELSE 9
           END,
           created_at DESC
         LIMIT 10",
        $station_params
    )
    : [];

$calendar_rows = [];
$today = date('Y-m-d');
if (mgr_table_exists($pdo, 'purchase_orders')) {
    $calendar_rows = array_merge($calendar_rows, mgr_rows(
        $pdo,
        "SELECT COALESCE(expected_delivery_date, DATE(admin_finalized_at), DATE(created_at)) AS event_date,
                'Upcoming Delivery' AS event_type,
                CONCAT(COALESCE(po_number, 'PO'), ' - ', COALESCE(product_name, 'Merchandise')) AS title,
                status
         FROM purchase_orders
         WHERE {$station_sql}
           AND stock_in_done = 0
           AND COALESCE(expected_delivery_date, DATE(admin_finalized_at), DATE(created_at)) >= ?
         ORDER BY event_date ASC
         LIMIT 8",
        array_merge($station_params, [$today])
    ));
}
if (mgr_table_exists($pdo, 'deliveries_oversight')) {
    $calendar_rows = array_merge($calendar_rows, mgr_rows(
        $pdo,
        "SELECT delivery_date AS event_date,
                'Upcoming Delivery' AS event_type,
                CONCAT(delivery_ref, ' - ', product) AS title,
                status
         FROM deliveries_oversight
         WHERE {$station_sql}
           AND delivery_date >= ?
           AND LOWER(status) NOT IN ('rejected', 'returned', 'cancelled')
         ORDER BY delivery_date ASC
         LIMIT 8",
        array_merge($station_params, [$today])
    ));
}
if (mgr_table_exists($pdo, 'calendar_events')) {
    $calendar_rows = array_merge($calendar_rows, mgr_rows(
        $pdo,
        "SELECT event_date,
                REPLACE(event_type, '_', ' ') AS event_type,
                work_description AS title,
                status
         FROM calendar_events
         WHERE {$station_sql}
           AND event_date >= ?
         ORDER BY event_date ASC, event_time ASC
         LIMIT 8",
        array_merge($station_params, [$today])
    ));
}
if (mgr_table_exists($pdo, 'staff_calendar_events')) {
    $calendar_rows = array_merge($calendar_rows, mgr_rows(
        $pdo,
        "SELECT event_date,
                'Staff Meeting / Schedule' AS event_type,
                work_description AS title,
                status
         FROM staff_calendar_events
         WHERE {$station_sql}
           AND event_date >= ?
         ORDER BY event_date ASC, start_time ASC
         LIMIT 8",
        array_merge($station_params, [$today])
    ));
}
usort($calendar_rows, fn($a, $b) => strcmp((string) ($a['event_date'] ?? ''), (string) ($b['event_date'] ?? '')));
$calendar_rows = array_slice($calendar_rows, 0, 10);

$chart_empty = [
    'revenue' => $total_revenue <= 0,
    'hourly' => array_sum($hourly_sales) <= 0,
    'fuel' => array_sum($fuel_sales_data) <= 0,
    'merch' => array_sum($merch_sales_data) <= 0,
    'weekly' => array_sum($weekly_revenue) <= 0,
    'inventory' => array_sum($inventory_current) <= 0,
];

$pending_fuel_transactions = mgr_table_exists($pdo, 'fuel_transactions')
    ? (int) mgr_value(
        $pdo,
        "SELECT COUNT(*)
         FROM fuel_transactions
         WHERE {$station_sql}
           AND LOWER(TRIM(COALESCE(status, ''))) IN ('pending', 'pending validation', 'pendingvalidation', 'awaiting validation')",
        $station_params
    )
    : 0;

$pending_merch_transactions = mgr_table_exists($pdo, 'merchandise_transactions')
    ? (int) mgr_value(
        $pdo,
        "SELECT COUNT(*)
         FROM merchandise_transactions
         WHERE {$station_sql}
           AND LOWER(TRIM(COALESCE(validation_status, ''))) IN ('', 'pending', 'pending validation', 'pendingvalidation')",
        $station_params
    )
    : 0;

$service_pending_parts = ["LOWER(TRIM(COALESCE(status, ''))) IN ('pending', 'pending validation', 'reviewed')"];
if (mgr_column_exists($pdo, 'job_orders', 'validation_status')) {
    $service_pending_parts[] = "LOWER(TRIM(COALESCE(validation_status, ''))) IN ('pending', 'pending validation')";
}
$pending_service_validation = mgr_table_exists($pdo, 'job_orders')
    ? (int) mgr_value(
        $pdo,
        "SELECT COUNT(*)
         FROM job_orders
         WHERE {$station_sql}
           AND (" . implode(' OR ', $service_pending_parts) . ')',
        $station_params
    )
    : 0;

$stock_approval_count = $pending_merch_stock + $pending_fuel_stock;
$delivery_action_url = $pending_encoded_deliveries > 0
    ? 'manager_merchandise_deliveries.php?tab=manage'
    : 'manager_delivery_validation.php';

$quick_actions = [
    [
        'label' => 'Fuel Transaction Validation',
        'href' => 'manager_fuel_transaction_validation.php?status_filter=pending',
        'icon' => 'fas fa-gas-pump',
        'class' => 'mgr-btn-blue',
        'badge' => $pending_fuel_transactions,
        'meta' => 'Pending pump readings',
    ],
    [
        'label' => 'Merchandise Transaction Review',
        'href' => 'transactions_pending.php',
        'icon' => 'fas fa-basket-shopping',
        'class' => 'mgr-btn-green',
        'badge' => $pending_merch_transactions,
        'meta' => 'Pending validation queue',
    ],
    [
        'label' => 'Service Transaction Review',
        'href' => 'manager_job_orders.php?status=Pending%20Validation',
        'icon' => 'fas fa-screwdriver-wrench',
        'class' => 'mgr-btn-amber',
        'badge' => $pending_service_validation,
        'meta' => 'Pending job order approvals',
    ],
    [
        'label' => 'Review Stock Requests',
        'href' => 'manager_stock_request_review.php',
        'icon' => 'fas fa-list-check',
        'class' => 'mgr-btn-blue',
        'badge' => $stock_approval_count,
        'meta' => 'Fuel and merchandise requests',
    ],
    [
        'label' => 'Receive Deliveries',
        'href' => $delivery_action_url,
        'icon' => 'fas fa-truck-fast',
        'class' => 'mgr-btn-green',
        'badge' => $pending_deliveries_count,
        'meta' => 'PO ' . number_format($pending_po_deliveries) . ' | Encoded ' . number_format($pending_encoded_deliveries),
    ],
    [
        'label' => 'Pricing Management',
        'href' => 'manager_set_prices.php',
        'icon' => 'fas fa-tags',
        'class' => 'mgr-btn-amber',
        'badge' => $pending_price_requests,
        'meta' => 'Pending price approvals',
    ],
    [
        'label' => 'Inventory Management',
        'href' => 'manager_inventory_merchandise.php',
        'icon' => 'fas fa-warehouse',
        'class' => 'mgr-btn-blue',
        'badge' => $total_inventory_alerts,
        'meta' => 'Low and out-of-stock alerts',
    ],
    [
        'label' => 'Reports',
        'href' => 'manager_reports.php',
        'icon' => 'fas fa-chart-column',
        'class' => 'mgr-btn-gray',
        'badge' => null,
        'meta' => 'Operations, finance, compliance',
    ],
];

include __DIR__ . '/../partials/header.php';
?>

<script src="../assets/vendor/chart.js/chart.umd.min.js"></script>

<style>
    .mgr-dashboard {
        padding: 0 0 72px;
        background: #f6f8fb;
        min-height: calc(100vh - 110px);
        color: #0f172a;
    }

    .mgr-card,
    .mgr-panel {
        background: #ffffff;
        border: 1px solid #dbe3ee;
        border-radius: 8px;
        box-shadow: 0 8px 22px rgba(15, 23, 42, 0.05);
    }

    .mgr-page-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 18px;
        margin-bottom: 22px;
        padding: 0 0 18px 0;
        border-bottom: 2px solid #e2e9f3;
    }

    .mgr-title-block {
        flex: 1 1 520px;
        min-width: 0;
    }

    .mgr-title-block h1 {
        margin: 0;
        color: #002f70;
        font-size: 24px;
        font-weight: 800;
        line-height: 1.2;
    }

    .mgr-title-block p {
        margin: 8px 0 0;
        max-width: 760px;
        color: #56657a;
        font-size: 13px;
        font-weight: 600;
    }

    .mgr-filter-form {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        margin-left: auto;
    }

    .mgr-filter-form input[type="date"] {
        min-width: 160px;
        height: 40px;
        border: 1px solid #c7d2e2;
        border-radius: 8px;
        padding: 0 12px;
        font-size: 13px;
        color: #0f172a;
        background: #ffffff;
    }

    .mgr-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        min-height: 34px;
        padding: 8px 12px;
        border-radius: 7px;
        border: 1px solid transparent;
        font-size: 12px;
        font-weight: 800;
        text-decoration: none;
        cursor: pointer;
        white-space: nowrap;
        transition: transform .15s ease, box-shadow .15s ease, background .15s ease;
    }

    .mgr-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 8px 18px rgba(15, 23, 42, 0.12);
        text-decoration: none;
    }

    /* ── Action button variants: outline style (no fill, border + colored text) ── */
    .mgr-btn-blue  { background: transparent; color: #002f70; border-color: #002f70; }
    .mgr-btn-blue:hover { background: #002f70; color: #ffffff; }

    /* ── Filter button: solid filled style to match staff ── */
    .mgr-filter-form .mgr-btn-blue {
        background: #002F70 !important;
        color: #ffffff !important;
        border: none !important;
    }
    .mgr-filter-form .mgr-btn-blue:hover {
        background: #001f4d !important;
    }

    .mgr-btn-gray  { background: transparent; color: #475569; border-color: #94a3b8; }
    .mgr-btn-gray:hover { background: #f1f5f9; color: #1e293b; }

    .mgr-btn-green { background: transparent; color: #128143; border-color: #128143; }
    .mgr-btn-green:hover { background: #128143; color: #ffffff; }

    .mgr-btn-red   { background: transparent; color: #c81e2d; border-color: #c81e2d; }
    .mgr-btn-red:hover { background: #c81e2d; color: #ffffff; }

    .mgr-btn-amber { background: transparent; color: #b45309; border-color: #b45309; }
    .mgr-btn-amber:hover { background: #b45309; color: #ffffff; }

    .mgr-summary-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 16px;
        margin-bottom: 22px;
    }

    .mgr-card {
        min-height: 126px;
        display: flex;
        justify-content: space-between;
        gap: 14px;
        padding: 18px;
        overflow: hidden;
    }

    .mgr-card-label {
        color: #56657a;
        font-size: 11px;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 0;
        line-height: 1.25;
    }

    .mgr-card-value {
        margin-top: 8px;
        color: #071225;
        font-size: 25px;
        line-height: 1.15;
        font-weight: 900;
    }

    .mgr-card-sub {
        margin-top: 8px;
        color: #6b7a90;
        font-size: 11px;
        font-weight: 700;
        line-height: 1.45;
    }

    .mgr-icon {
        width: 44px;
        height: 44px;
        flex: 0 0 44px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        font-size: 18px;
        background: #eef4ff;
        color: #002f70;
    }

    .mgr-charts-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 18px;
        margin-bottom: 22px;
    }

    .mgr-panel {
        padding: 20px;
        margin-bottom: 18px;
    }

    .mgr-panel-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        padding-bottom: 14px;
        border-bottom: 1px solid #edf2f7;
        margin-bottom: 16px;
    }

    .mgr-panel-title {
        display: flex;
        align-items: center;
        gap: 10px;
        color: #002f70;
        font-size: 17px;
        font-weight: 900;
        line-height: 1.25;
    }

    .mgr-panel-sub {
        margin-top: 4px;
        color: #6b7a90;
        font-size: 12px;
        font-weight: 600;
    }

    .mgr-chart-body {
        position: relative;
        height: 292px;
        min-height: 292px;
    }

    .mgr-chart-empty {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 2;
        color: #718096;
        font-size: 13px;
        font-weight: 800;
        text-align: center;
        background: rgba(255, 255, 255, 0.82);
        pointer-events: none;
    }

    .mgr-table-wrap {
        width: 100%;
        overflow-x: auto;
    }

    .mgr-table {
        width: 100%;
        min-width: 720px;
        border-collapse: collapse;
        font-size: 12px;
    }

    .mgr-table th {
        background: #f4f7fb;
        color: #4b5d74;
        text-transform: uppercase;
        letter-spacing: 0;
        font-size: 11px;
        font-weight: 900;
        text-align: left;
        padding: 12px 14px;
        border-bottom: 1px solid #dbe3ee;
    }

    .mgr-table td {
        padding: 13px 14px;
        border-bottom: 1px solid #edf2f7;
        color: #26364c;
        vertical-align: middle;
    }

    .mgr-table tr:last-child td {
        border-bottom: 0;
    }

    .mgr-table code {
        color: #002f70;
        font-weight: 900;
        font-size: 11px;
    }

    .mgr-actions-cell {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        align-items: center;
        gap: 6px;
        max-width: 320px;
    }

    .mgr-actions-cell .mgr-btn {
        width: 100%;
        min-height: 32px;
        padding: 7px 8px;
        white-space: normal;
        line-height: 1.15;
    }

    .mgr-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 24px;
        padding: 4px 9px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 900;
        white-space: nowrap;
    }

    .mgr-badge.success { background: #e4f8ec; color: #0c6b35; }
    .mgr-badge.warning { background: #fff6d8; color: #8a5800; }
    .mgr-badge.danger { background: #ffe5e8; color: #9f1623; }
    .mgr-badge.info { background: #e5efff; color: #17458f; }
    .mgr-badge.neutral { background: #edf2f7; color: #526174; }

    .mgr-empty {
        padding: 28px 16px;
        text-align: center;
        color: #718096;
        font-size: 13px;
        font-weight: 700;
        background: #fbfdff;
        border: 1px dashed #d7e0ea;
        border-radius: 8px;
    }

    .mgr-empty i {
        display: block;
        margin-bottom: 9px;
        font-size: 24px;
        color: #9aa9ba;
    }

    .mgr-split {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 18px;
        margin-bottom: 18px;
    }

    .mgr-mini-list {
        display: grid;
        gap: 10px;
    }

    .mgr-mini-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 12px;
        background: #f8fbff;
        border: 1px solid #e2e9f3;
        border-radius: 8px;
    }

    .mgr-mini-title {
        color: #17243a;
        font-size: 13px;
        font-weight: 900;
    }

    .mgr-mini-meta {
        margin-top: 3px;
        color: #6b7a90;
        font-size: 11px;
        font-weight: 700;
    }

    /* ── Quick Actions Grid — matches staff dashboard style ── */
    .mgr-quick-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
    }

    .mgr-quick-grid .mgr-btn {
        position: relative;
        width: 100%;
        min-height: 90px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 9px;
        padding: 16px 12px;
        text-align: center;
        white-space: normal;
        /* Staff-style: white bg, light border, no color fill */
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        color: #334155;
        font-weight: 700;
        font-size: 12px;
        box-shadow: 0 4px 6px -1px rgba(0,0,0,0.04);
        transition: all 0.25s ease;
        text-decoration: none;
        cursor: pointer;
    }

    /* Override color variants to all use white bg */
    .mgr-quick-grid .mgr-btn-blue,
    .mgr-quick-grid .mgr-btn-green,
    .mgr-quick-grid .mgr-btn-red,
    .mgr-quick-grid .mgr-btn-amber,
    .mgr-quick-grid .mgr-btn-gray {
        background: #ffffff;
        color: #334155;
        border-color: #e2e8f0;
    }

    .mgr-quick-grid .mgr-btn:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
        border-color: #002f70;
        color: #002f70;
        background: #ffffff;
    }

    .mgr-quick-main {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 6px;
        min-width: 0;
        width: 100%;
        padding-right: 0;
    }

    .mgr-quick-main i {
        font-size: 20px;
        color: #002f70;
        transition: transform 0.25s ease;
        flex: unset;
        width: unset;
        margin-top: 0;
    }

    .mgr-quick-grid .mgr-btn:hover .mgr-quick-main i {
        transform: scale(1.15);
    }

    .mgr-quick-label {
        min-width: 0;
        line-height: 1.3;
        font-size: 12px;
        font-weight: 700;
        color: inherit;
    }

    .mgr-action-count {
        position: absolute;
        top: 8px;
        right: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 22px;
        height: 22px;
        padding: 0 6px;
        border-radius: 999px;
        background: #002f70;
        color: #ffffff;
        font-size: 10px;
        font-weight: 900;
        line-height: 1;
    }

    /* Neutralise count badge override from colored variants */
    .mgr-btn-gray .mgr-action-count {
        background: #002f70;
        color: #ffffff;
    }

    .mgr-action-meta {
        display: block;
        font-size: 10px;
        font-weight: 600;
        line-height: 1.35;
        color: #64748b;
        opacity: 1;
    }

    .mgr-calendar-list {
        display: grid;
        gap: 10px;
    }

    .mgr-calendar-item {
        display: grid;
        grid-template-columns: 82px 1fr auto;
        gap: 12px;
        align-items: center;
        padding: 12px;
        background: #f8fbff;
        border: 1px solid #e2e9f3;
        border-radius: 8px;
    }

    .mgr-date-chip {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        min-height: 54px;
        border-radius: 8px;
        background: #002f70;
        color: #ffffff;
        font-weight: 900;
    }

    .mgr-date-chip span:first-child {
        font-size: 18px;
        line-height: 1;
    }

    .mgr-date-chip span:last-child {
        margin-top: 3px;
        font-size: 11px;
        text-transform: uppercase;
    }

    .mgr-service-summary {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px;
        margin-bottom: 14px;
    }

    .mgr-service-pill {
        padding: 12px;
        background: #f8fbff;
        border: 1px solid #e2e9f3;
        border-radius: 8px;
        text-align: center;
    }

    .mgr-service-pill strong {
        display: block;
        color: #002f70;
        font-size: 20px;
        font-weight: 900;
    }

    .mgr-service-pill span {
        color: #5b6d82;
        font-size: 11px;
        font-weight: 800;
        text-transform: uppercase;
    }

    @media (max-width: 1280px) {
        .mgr-summary-grid,
        .mgr-quick-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 980px) {
        .mgr-page-header,
        .mgr-split,
        .mgr-charts-grid {
            grid-template-columns: 1fr;
        }

        .mgr-page-header {
            align-items: stretch;
            flex-direction: column;
        }

        .mgr-filter-form {
            width: 100%;
        }

        .mgr-filter-form input[type="date"],
        .mgr-filter-form .mgr-btn {
            flex: 1 1 160px;
        }
    }

    @media (max-width: 720px) {
        .mgr-dashboard {
            padding: 16px 14px 72px;
        }

        .mgr-summary-grid,
        .mgr-quick-grid,
        .mgr-service-summary {
            grid-template-columns: 1fr;
        }

        .mgr-calendar-item {
            grid-template-columns: 1fr;
            align-items: stretch;
        }

        .mgr-date-chip {
            width: 82px;
        }
    }
</style>

<section class="mgr-dashboard">
    <div class="mgr-page-header">
        <div class="mgr-title-block">
            <h1>Welcome, <?= mgr_h($display_name) ?>!</h1>
            <div style="display:flex; align-items:center; gap:8px; margin-top:4px; margin-bottom:8px;">
                <i class="fas fa-tachometer-alt" style="color:#64748b; font-size:14px;"></i>
                <span style="color:#64748b; font-size:13px; font-weight:600;">Manager Dashboard</span>
            </div>
            <p>Monitor station operations, approve requests, manage inventory, pricing, and track daily business performance.</p>
        </div>
        <form method="get" class="mgr-filter-form">
            <input type="date" name="date" value="<?= mgr_h($date_filter) ?>" required>
            <button class="mgr-btn mgr-btn-blue" type="submit"><i class="fas fa-filter"></i> Filter</button>
        </form>
    </div>

    <div class="mgr-summary-grid">
        <div class="mgr-card" data-tone="blue">
            <div>
                <div class="mgr-card-label">Today's Transactions</div>
                <div class="mgr-card-value"><?= number_format($total_transactions) ?></div>
                <div class="mgr-card-sub">Fuel <?= number_format($fuel_count) ?> | Merchandise <?= number_format($merch_count) ?> | Service <?= number_format($service_count) ?></div>
            </div>
            <div class="mgr-icon"><i class="fas fa-right-left"></i></div>
        </div>

        <div class="mgr-card" data-tone="green">
            <div>
                <div class="mgr-card-label">Today's Revenue</div>
                <div class="mgr-card-value"><?= mgr_money($total_revenue) ?></div>
                <div class="mgr-card-sub">Current day sales from fuel, merchandise, and services</div>
            </div>
            <div class="mgr-icon" style="background:#e7f7ee;color:#128143;"><i class="fas fa-peso-sign"></i></div>
        </div>

        <div class="mgr-card" data-tone="amber">
            <div>
                <div class="mgr-card-label">Fuel Sold Today</div>
                <div class="mgr-card-value"><?= mgr_qty($total_fuel_liters) ?> L</div>
                <div class="mgr-card-sub">Total liters sold on selected date</div>
            </div>
            <div class="mgr-icon" style="background:#fff2dc;color:#c56b00;"><i class="fas fa-gas-pump"></i></div>
        </div>

        <div class="mgr-card" data-tone="red">
            <div>
                <div class="mgr-card-label">Pending Approvals</div>
                <div class="mgr-card-value"><?= number_format($total_pending_approvals) ?></div>
                <div class="mgr-card-sub">Stock <?= number_format($pending_merch_stock + $pending_fuel_stock) ?> | Customers <?= number_format($pending_customer_requests) ?> | Prices <?= number_format($pending_price_requests) ?></div>
            </div>
            <div class="mgr-icon" style="background:#ffe8eb;color:#c81e2d;"><i class="fas fa-clipboard-check"></i></div>
        </div>

        <div class="mgr-card" data-tone="violet">
            <div>
                <div class="mgr-card-label">Inventory Alerts</div>
                <div class="mgr-card-value"><?= number_format($total_inventory_alerts) ?></div>
                <div class="mgr-card-sub">Low fuel <?= number_format($low_fuel_count) ?> | Low merch <?= number_format($low_merch_count) ?> | Out <?= number_format($out_stock_count) ?></div>
            </div>
            <div class="mgr-icon" style="background:#f0eaff;color:#6d3bd1;"><i class="fas fa-triangle-exclamation"></i></div>
        </div>

        <div class="mgr-card" data-tone="cyan">
            <div>
                <div class="mgr-card-label">Pending Deliveries</div>
                <div class="mgr-card-value"><?= number_format($pending_deliveries_count) ?></div>
                <div class="mgr-card-sub">Purchase orders and encoded deliveries awaiting action</div>
            </div>
            <div class="mgr-icon" style="background:#e4f8fb;color:#087990;"><i class="fas fa-truck"></i></div>
        </div>

        <div class="mgr-card" data-tone="amber">
            <div>
                <div class="mgr-card-label">Active Services</div>
                <div class="mgr-card-value"><?= number_format($active_services_count) ?></div>
                <div class="mgr-card-sub">Pending <?= number_format($service_status_counts['Pending']) ?> | In progress <?= number_format($service_status_counts['In Progress']) ?> | Ready <?= number_format($service_status_counts['Ready']) ?></div>
            </div>
            <div class="mgr-icon" style="background:#fff8db;color:#b98300;"><i class="fas fa-screwdriver-wrench"></i></div>
        </div>

        <div class="mgr-card" data-tone="blue">
            <div>
                <div class="mgr-card-label">Active Staff</div>
                <div class="mgr-card-value"><?= number_format($active_staff_total) ?></div>
                <div class="mgr-card-sub">
                    <?php foreach ($staff_shift_counts as $shift => $count): ?>
                        <?= mgr_h($shift) ?>: <?= number_format($count) ?> Staff<?= $shift !== array_key_last($staff_shift_counts) ? ' | ' : '' ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="mgr-icon"><i class="fas fa-users"></i></div>
        </div>
    </div>

    <div class="mgr-charts-grid">
        <div class="mgr-panel">
            <div class="mgr-panel-header">
                <div>
                    <div class="mgr-panel-title"><i class="fas fa-chart-pie"></i> Revenue Breakdown</div>
                    <div class="mgr-panel-sub">Fuel, merchandise, and service revenue split</div>
                </div>
            </div>
            <div class="mgr-chart-body">
                <?php if ($chart_empty['revenue']): ?><div class="mgr-chart-empty">No revenue for the selected date</div><?php endif; ?>
                <canvas id="revenueBreakdownChart"></canvas>
            </div>
        </div>

        <div class="mgr-panel">
            <div class="mgr-panel-header">
                <div>
                    <div class="mgr-panel-title"><i class="fas fa-chart-line"></i> Hourly Sales Trend</div>
                    <div class="mgr-panel-sub">Revenue by hour for the selected date</div>
                </div>
            </div>
            <div class="mgr-chart-body">
                <?php if ($chart_empty['hourly']): ?><div class="mgr-chart-empty">No hourly sales recorded yet</div><?php endif; ?>
                <canvas id="hourlySalesChart"></canvas>
            </div>
        </div>

        <div class="mgr-panel">
            <div class="mgr-panel-header">
                <div>
                    <div class="mgr-panel-title"><i class="fas fa-gas-pump"></i> Fuel Sales by Product</div>
                    <div class="mgr-panel-sub">Liters sold by fuel type</div>
                </div>
            </div>
            <div class="mgr-chart-body">
                <?php if ($chart_empty['fuel']): ?><div class="mgr-chart-empty">No fuel sales for the selected date</div><?php endif; ?>
                <canvas id="fuelSalesChart"></canvas>
            </div>
        </div>

        <div class="mgr-panel">
            <div class="mgr-panel-header">
                <div>
                    <div class="mgr-panel-title"><i class="fas fa-basket-shopping"></i> Merchandise Sales by Category</div>
                    <div class="mgr-panel-sub">Sales amount grouped by category</div>
                </div>
            </div>
            <div class="mgr-chart-body">
                <?php if ($chart_empty['merch']): ?><div class="mgr-chart-empty">No merchandise sales for the selected date</div><?php endif; ?>
                <canvas id="merchSalesChart"></canvas>
            </div>
        </div>

        <div class="mgr-panel">
            <div class="mgr-panel-header">
                <div>
                    <div class="mgr-panel-title"><i class="fas fa-calendar-week"></i> Weekly Revenue Trend</div>
                    <div class="mgr-panel-sub">Monday to Sunday revenue for the selected week</div>
                </div>
            </div>
            <div class="mgr-chart-body">
                <?php if ($chart_empty['weekly']): ?><div class="mgr-chart-empty">No revenue in the selected week</div><?php endif; ?>
                <canvas id="weeklyRevenueChart"></canvas>
            </div>
        </div>

        <div class="mgr-panel">
            <div class="mgr-panel-header">
                <div>
                    <div class="mgr-panel-title"><i class="fas fa-boxes-stacked"></i> Inventory Status</div>
                    <div class="mgr-panel-sub">Fuel tanks and merchandise current stock</div>
                </div>
            </div>
            <div class="mgr-chart-body">
                <?php if ($chart_empty['inventory']): ?><div class="mgr-chart-empty">No inventory stock data available</div><?php endif; ?>
                <canvas id="inventoryStatusChart"></canvas>
            </div>
        </div>
    </div>

    <div class="mgr-panel">
        <div class="mgr-panel-header">
            <div>
                <div class="mgr-panel-title"><i class="fas fa-file-signature"></i> Pending Stock Requests</div>
                <div class="mgr-panel-sub">Fuel and merchandise requests waiting for manager review</div>
            </div>
            <a href="manager_stock_request_review.php" class="mgr-btn mgr-btn-blue"><i class="fas fa-list-check"></i> Review All</a>
        </div>
        <?php if ($stock_request_rows): ?>
            <div class="mgr-table-wrap">
                <table class="mgr-table">
                    <thead>
                        <tr>
                            <th>Request No</th>
                            <th>Type</th>
                            <th>Requested By</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($stock_request_rows as $row): ?>
                        <tr>
                            <td><code><?= mgr_h($row['request_no']) ?></code><div class="mgr-panel-sub"><?= mgr_h($row['item_name']) ?> - <?= mgr_qty($row['requested_qty'], $row['request_type'] === 'Fuel' ? 2 : 0) ?><?= $row['request_type'] === 'Fuel' ? ' L' : '' ?></div></td>
                            <td><?= mgr_h($row['request_type']) ?></td>
                            <td><?= mgr_h($row['requested_by']) ?></td>
                            <td><span class="mgr-badge <?= mgr_badge_class($row['status']) ?>"><?= mgr_h(mgr_status_label($row['status'])) ?></span></td>
                            <td>
                                <div class="mgr-actions-cell">
                                    <a class="mgr-btn mgr-btn-gray" href="<?= mgr_h($row['action_url']) ?>"><i class="fas fa-eye"></i> Review</a>
                                    <a class="mgr-btn mgr-btn-green" href="<?= mgr_h($row['action_url']) ?>"><i class="fas fa-check"></i> Approve</a>
                                    <a class="mgr-btn mgr-btn-red" href="<?= mgr_h($row['action_url']) ?>"><i class="fas fa-xmark"></i> Reject</a>
                                    <a class="mgr-btn mgr-btn-amber" href="<?= mgr_h($row['action_url']) ?>"><i class="fas fa-file-invoice"></i> Generate PO</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="mgr-empty"><i class="fas fa-circle-check"></i>No pending stock requests.</div>
        <?php endif; ?>
    </div>

    <div class="mgr-panel">
        <div class="mgr-panel-header">
            <div>
                <div class="mgr-panel-title"><i class="fas fa-user-plus"></i> Pending Customer Registration</div>
                <div class="mgr-panel-sub">Customer accounts still waiting for registration review</div>
            </div>
            <a href="manager_customers.php" class="mgr-btn mgr-btn-blue"><i class="fas fa-users-gear"></i> Manage Customers</a>
        </div>
        <?php if ($customer_request_rows): ?>
            <div class="mgr-table-wrap">
                <table class="mgr-table">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Contact</th>
                            <th>Requested By</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($customer_request_rows as $row): ?>
                        <tr>
                            <td><strong><?= mgr_h($row['customer_name']) ?></strong><div class="mgr-panel-sub"><?= mgr_h(date('M d, Y', strtotime($row['created_at'] ?? 'now'))) ?></div></td>
                            <td><?= mgr_h($row['contact']) ?></td>
                            <td><?= mgr_h($row['requested_by']) ?></td>
                            <td>
                                <div class="mgr-actions-cell">
                                    <a class="mgr-btn mgr-btn-green" href="manager_customers.php"><i class="fas fa-check"></i> Approve</a>
                                    <a class="mgr-btn mgr-btn-red" href="manager_customers.php"><i class="fas fa-xmark"></i> Reject</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="mgr-empty"><i class="fas fa-user-check"></i>No pending customer registrations.</div>
        <?php endif; ?>
    </div>

    <div class="mgr-panel">
        <div class="mgr-panel-header">
            <div>
                <div class="mgr-panel-title"><i class="fas fa-truck-ramp-box"></i> Pending Deliveries</div>
                <div class="mgr-panel-sub">Deliveries ready for receiving, viewing, or stock-in workflow</div>
            </div>
            <a href="manager_delivery_validation.php" class="mgr-btn mgr-btn-blue"><i class="fas fa-truck-fast"></i> Receive Deliveries</a>
        </div>
        <?php if ($delivery_rows): ?>
            <div class="mgr-table-wrap">
                <table class="mgr-table">
                    <thead>
                        <tr>
                            <th>Delivery No</th>
                            <th>Supplier</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($delivery_rows as $row): ?>
                        <tr>
                            <td><code><?= mgr_h($row['delivery_no']) ?></code><div class="mgr-panel-sub"><?= mgr_h(ucwords((string) $row['delivery_type'])) ?></div></td>
                            <td><?= mgr_h($row['supplier']) ?></td>
                            <td><span class="mgr-badge <?= mgr_badge_class($row['status']) ?>"><?= mgr_h(mgr_status_label($row['status'])) ?></span></td>
                            <td>
                                <div class="mgr-actions-cell">
                                    <a class="mgr-btn mgr-btn-green" href="<?= mgr_h($row['receive_url']) ?>"><i class="fas fa-clipboard-check"></i> Receive Delivery</a>
                                    <a class="mgr-btn mgr-btn-gray" href="<?= mgr_h($row['view_url']) ?>"><i class="fas fa-eye"></i> View</a>
                                    <a class="mgr-btn mgr-btn-amber" href="<?= mgr_h($row['stock_url']) ?>"><i class="fas fa-dolly"></i> Stock-In</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="mgr-empty"><i class="fas fa-truck"></i>No pending deliveries.</div>
        <?php endif; ?>
    </div>

    <div class="mgr-split">
        <div class="mgr-panel">
            <div class="mgr-panel-header">
                <div>
                    <div class="mgr-panel-title"><i class="fas fa-tags"></i> Price Update Summary</div>
                    <div class="mgr-panel-sub">Recent fuel, merchandise, and service price changes</div>
                </div>
                <a href="manager_set_prices.php" class="mgr-btn mgr-btn-blue"><i class="fas fa-sliders"></i> Manage Pricing</a>
            </div>
            <?php if ($price_rows): ?>
                <div class="mgr-mini-list">
                    <?php foreach ($price_rows as $row): ?>
                        <div class="mgr-mini-item">
                            <div>
                                <div class="mgr-mini-title"><?= mgr_h($row['product_name']) ?></div>
                                <div class="mgr-mini-meta"><?= mgr_h(ucwords(str_replace('_', ' ', (string) $row['product_type']))) ?> | <?= mgr_h($row['updated_by']) ?> | <?= mgr_h(date('M d, Y', strtotime($row['reviewed_at'] ?: $row['created_at'] ?: 'now'))) ?></div>
                            </div>
                            <div style="text-align:right;">
                                <div class="mgr-mini-title"><?= mgr_money($row['old_price']) ?> &rarr; <?= mgr_money($row['new_price']) ?></div>
                                <span class="mgr-badge <?= mgr_badge_class($row['status']) ?>"><?= mgr_h(mgr_status_label($row['status'])) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="mgr-empty"><i class="fas fa-tags"></i>No recent price updates.</div>
            <?php endif; ?>
        </div>

        <div class="mgr-panel">
            <div class="mgr-panel-header">
                <div>
                    <div class="mgr-panel-title"><i class="fas fa-receipt"></i> Recent Transactions</div>
                    <div class="mgr-panel-sub">Latest 10 fuel, merchandise, and service records</div>
                </div>
                <a href="manager_transaction_monitoring.php" class="mgr-btn mgr-btn-blue"><i class="fas fa-magnifying-glass-chart"></i> View All</a>
            </div>
            <?php if ($recent_transactions): ?>
                <div class="mgr-table-wrap">
                    <table class="mgr-table">
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
                        <?php foreach ($recent_transactions as $row): ?>
                            <tr>
                                <td><?= mgr_h(date('M d, h:i A', strtotime($row['txn_time'] ?? 'now'))) ?><div class="mgr-panel-sub"><?= mgr_h($row['reference_no']) ?></div></td>
                                <td><?= mgr_h($row['txn_type']) ?><div class="mgr-panel-sub"><?= mgr_h($row['detail']) ?></div></td>
                                <td><?= mgr_h($row['customer_name']) ?></td>
                                <td><strong><?= mgr_money($row['amount']) ?></strong></td>
                                <td><span class="mgr-badge <?= mgr_badge_class($row['status']) ?>"><?= mgr_h(mgr_status_label($row['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="mgr-empty"><i class="fas fa-receipt"></i>No transactions recorded yet.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="mgr-split">
        <div class="mgr-panel">
            <div class="mgr-panel-header">
                <div>
                    <div class="mgr-panel-title"><i class="fas fa-warehouse"></i> Low Inventory</div>
                    <div class="mgr-panel-sub">Fuel and merchandise items below reorder levels</div>
                </div>
                <a href="manager_inventory_fuel.php" class="mgr-btn mgr-btn-blue"><i class="fas fa-boxes-stacked"></i> Inventory</a>
            </div>
            <?php if ($low_inventory_rows): ?>
                <div class="mgr-table-wrap">
                    <table class="mgr-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Type</th>
                                <th>Current</th>
                                <th>Reorder Level</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($low_inventory_rows as $row): ?>
                            <tr>
                                <td><?= mgr_h($row['product_name']) ?></td>
                                <td><?= mgr_h($row['item_type']) ?></td>
                                <td><?= mgr_qty($row['current_qty']) ?></td>
                                <td><?= mgr_qty($row['reorder_qty']) ?></td>
                                <td><span class="mgr-badge <?= mgr_badge_class($row['status']) ?>"><?= mgr_h($row['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="mgr-empty"><i class="fas fa-circle-check"></i>Inventory levels are normal.</div>
            <?php endif; ?>
        </div>

        <div class="mgr-panel">
            <div class="mgr-panel-header">
                <div>
                    <div class="mgr-panel-title"><i class="fas fa-screwdriver-wrench"></i> Service Queue</div>
                    <div class="mgr-panel-sub">Pending, in progress, ready, and released job orders</div>
                </div>
                <a href="manager_job_orders.php" class="mgr-btn mgr-btn-blue"><i class="fas fa-clipboard-list"></i> Job Orders</a>
            </div>
            <div class="mgr-service-summary">
                <?php foreach ($service_status_counts as $label => $count): ?>
                    <div class="mgr-service-pill">
                        <strong><?= number_format($count) ?></strong>
                        <span><?= mgr_h($label) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($service_queue_rows): ?>
                <div class="mgr-table-wrap">
                    <table class="mgr-table">
                        <thead>
                            <tr>
                                <th>Service No</th>
                                <th>Customer</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($service_queue_rows as $row): ?>
                            <tr>
                                <td><code><?= mgr_h($row['service_no']) ?></code><div class="mgr-panel-sub"><?= mgr_h($row['service_type']) ?></div></td>
                                <td><?= mgr_h($row['customer_name']) ?></td>
                                <td><span class="mgr-badge <?= mgr_badge_class($row['status']) ?>"><?= mgr_h(mgr_status_label($row['status'])) ?></span></td>
                                <td><a class="mgr-btn mgr-btn-gray" href="manager_job_orders.php"><i class="fas fa-eye"></i> View</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="mgr-empty"><i class="fas fa-circle-check"></i>No active services in queue.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="mgr-split">
        <div class="mgr-panel">
            <div class="mgr-panel-header">
                <div>
                    <div class="mgr-panel-title"><i class="fas fa-bolt"></i> Quick Actions</div>
                    <div class="mgr-panel-sub">Common manager workflows</div>
                </div>
            </div>
            <div class="mgr-quick-grid">
                <?php foreach ($quick_actions as $action): ?>
                    <a class="mgr-btn <?= mgr_h($action['class']) ?>" href="<?= mgr_h($action['href']) ?>">
                        <span class="mgr-quick-main">
                            <i class="<?= mgr_h($action['icon']) ?>"></i>
                            <span class="mgr-quick-label"><?= mgr_h($action['label']) ?></span>
                        </span>
                        <?php if ($action['badge'] !== null): ?>
                            <span class="mgr-action-count"><?= number_format((int) $action['badge']) ?></span>
                        <?php endif; ?>
                        <span class="mgr-action-meta"><?= mgr_h($action['meta']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="mgr-panel">
            <div class="mgr-panel-header">
                <div>
                    <div class="mgr-panel-title"><i class="fas fa-calendar-days"></i> Manager Calendar</div>
                    <div class="mgr-panel-sub">Upcoming deliveries, inventory counts, staff meetings, and scheduled maintenance</div>
                </div>
                <a href="manager_calendar.php" class="mgr-btn mgr-btn-blue"><i class="fas fa-calendar-plus"></i> Calendar</a>
            </div>
            <?php if ($calendar_rows): ?>
                <div class="mgr-calendar-list">
                    <?php foreach ($calendar_rows as $row): ?>
                        <?php $event_ts = strtotime($row['event_date'] ?? 'now'); ?>
                        <div class="mgr-calendar-item">
                            <div class="mgr-date-chip">
                                <span><?= mgr_h(date('d', $event_ts)) ?></span>
                                <span><?= mgr_h(date('M', $event_ts)) ?></span>
                            </div>
                            <div>
                                <div class="mgr-mini-title"><?= mgr_h($row['title']) ?></div>
                                <div class="mgr-mini-meta"><?= mgr_h(ucwords(str_replace('_', ' ', (string) $row['event_type']))) ?></div>
                            </div>
                            <span class="mgr-badge <?= mgr_badge_class($row['status']) ?>"><?= mgr_h(mgr_status_label($row['status'])) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="mgr-empty"><i class="fas fa-calendar-check"></i>No upcoming calendar entries.</div>
            <?php endif; ?>
        </div>
    </div>
</section>

<script>
    (function () {
        const chartReady = typeof Chart !== 'undefined';
        if (!chartReady) {
            document.querySelectorAll('.mgr-chart-body').forEach(function (body) {
                if (!body.querySelector('.mgr-chart-empty')) {
                    const empty = document.createElement('div');
                    empty.className = 'mgr-chart-empty';
                    empty.textContent = 'Chart library unavailable';
                    body.appendChild(empty);
                }
            });
            return;
        }

        Chart.defaults.font.family = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif';
        Chart.defaults.color = '#56657a';

        const money = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
        const number = new Intl.NumberFormat('en-PH', { maximumFractionDigits: 2 });

        function makeChart(id, config) {
            const canvas = document.getElementById(id);
            if (!canvas) return;
            new Chart(canvas, config);
        }

        makeChart('revenueBreakdownChart', {
            type: 'doughnut',
            data: {
                labels: ['Fuel', 'Merchandise', 'Services'],
                datasets: [{
                    data: <?= json_encode([$fuel_revenue, $merch_revenue, $service_revenue], JSON_NUMERIC_CHECK) ?>,
                    backgroundColor: ['#dc2626', '#16a34a', '#3b82f6'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 14, font: { size: 12, weight: '700' } } },
                    tooltip: { callbacks: { label: ctx => ctx.label + ': ' + money.format(ctx.parsed || 0) } }
                }
            }
        });

        makeChart('hourlySalesChart', {
            type: 'line',
            data: {
                labels: <?= json_encode($hour_labels) ?>,
                datasets: [{
                    label: 'Revenue',
                    data: <?= json_encode($hourly_sales, JSON_NUMERIC_CHECK) ?>,
                    borderColor: '#002f70',
                    backgroundColor: 'rgba(0, 47, 112, 0.12)',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 3,
                    pointHoverRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: ctx => money.format(ctx.parsed.y || 0) } }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: value => money.format(value) } }
                }
            }
        });

        makeChart('fuelSalesChart', {
            type: 'bar',
            data: {
                labels: <?= json_encode($fuel_products) ?>,
                datasets: [{
                    label: 'Liters Sold',
                    data: <?= json_encode($fuel_sales_data, JSON_NUMERIC_CHECK) ?>,
                    backgroundColor: '#e46f00',
                    borderRadius: 6,
                    maxBarThickness: 46
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: ctx => number.format(ctx.parsed.y || 0) + ' L' } }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: value => number.format(value) + ' L' } }
                }
            }
        });

        makeChart('merchSalesChart', {
            type: 'bar',
            data: {
                labels: <?= json_encode($merch_categories) ?>,
                datasets: [{
                    label: 'Sales Amount',
                    data: <?= json_encode($merch_sales_data, JSON_NUMERIC_CHECK) ?>,
                    backgroundColor: '#128143',
                    borderRadius: 6,
                    maxBarThickness: 46
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: ctx => money.format(ctx.parsed.y || 0) } }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: value => money.format(value) } }
                }
            }
        });

        makeChart('weeklyRevenueChart', {
            type: 'line',
            data: {
                labels: <?= json_encode($weekly_labels) ?>,
                datasets: [{
                    label: 'Revenue',
                    data: <?= json_encode($weekly_revenue, JSON_NUMERIC_CHECK) ?>,
                    borderColor: '#6d3bd1',
                    backgroundColor: 'rgba(109, 59, 209, 0.12)',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: ctx => money.format(ctx.parsed.y || 0) } }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: value => money.format(value) } }
                }
            }
        });

        makeChart('inventoryStatusChart', {
            type: 'bar',
            data: {
                labels: <?= json_encode($inventory_labels) ?>,
                datasets: [
                    {
                        label: 'Current Stock',
                        data: <?= json_encode($inventory_current, JSON_NUMERIC_CHECK) ?>,
                        backgroundColor: <?= json_encode($inventory_colors) ?>,
                        borderRadius: 6
                    },
                    {
                        label: 'Remaining Capacity',
                        data: <?= json_encode($inventory_remaining, JSON_NUMERIC_CHECK) ?>,
                        backgroundColor: '#d7e0ea',
                        borderRadius: 6
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 14, font: { size: 12, weight: '700' } } },
                    tooltip: {
                        callbacks: {
                            label: ctx => ctx.dataset.label + ': ' + number.format(ctx.parsed.y || 0)
                        }
                    }
                },
                scales: {
                    x: { stacked: true, ticks: { maxRotation: 35, minRotation: 0 } },
                    y: { stacked: true, beginAtZero: true, ticks: { callback: value => number.format(value) } }
                }
            }
        });
    })();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
