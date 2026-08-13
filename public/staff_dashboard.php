<?php
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Asia/Manila');
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

    if (!empty($shift_periods)) {
        $first_shift = $shift_periods[0];
        $first_start = $first_shift['start_time'] ?? '00:00:00';
        if ($now < $first_start) {
            $last_shift = end($shift_periods);
            reset($shift_periods);
            return $last_shift ?: $first_shift;
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
    $end_raw = (string) ($shift['end_time'] ?? '');
    $end = $end_raw !== '' ? date('g:i A', strtotime($end_raw)) : '';
    if ($end_raw >= '23:59:00') {
        $end = '12:00 AM';
    }
    $time_label = ($start && $end) ? " ($start - $end)" : '';

    return $name . $time_label;
}

function dashboard_range_label(string $date_from, string $date_to): string {
    if ($date_from === $date_to) {
        return date('F j, Y', strtotime($date_from));
    }

    return date('F j, Y', strtotime($date_from)) . ' - ' . date('F j, Y', strtotime($date_to));
}

function dashboard_log_query_error(string $context, Throwable $e): void {
    error_log('staff_dashboard ' . $context . ': ' . $e->getMessage());
}

function dashboard_fetch_column(PDO $pdo, string $sql, array $params = [], $default = 0) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : $value;
    } catch (Throwable $e) {
        dashboard_log_query_error('fetch_column', $e);
        return $default;
    }
}

function dashboard_fetch_all(PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        dashboard_log_query_error('fetch_all', $e);
        return [];
    }
}

function dashboard_table_columns(PDO $pdo, string $table): array {
    static $cache = [];
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return [];
    }
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
        $cache[$table] = array_fill_keys(array_map('strtolower', $cols ?: []), true);
    } catch (Throwable $e) {
        dashboard_log_query_error('columns:' . $table, $e);
        $cache[$table] = [];
    }

    return $cache[$table];
}

function dashboard_has_column(PDO $pdo, string $table, string $column): bool {
    $cols = dashboard_table_columns($pdo, $table);
    return isset($cols[strtolower($column)]);
}

function dashboard_column_sql(string $column, string $alias = ''): string {
    $quoted = '`' . str_replace('`', '``', $column) . '`';
    if ($alias !== '' && preg_match('/^[A-Za-z0-9_]+$/', $alias)) {
        return "`{$alias}`.{$quoted}";
    }
    return $quoted;
}

function dashboard_shift_datetime_where(string $datetime_expr, string $date_from, string $date_to, string $start_time, string $end_time): array {
    $date_sql = "DATE({$datetime_expr})";
    $time_sql = "TIME({$datetime_expr})";

    if ($start_time <= $end_time) {
        return [
            "({$date_sql} BETWEEN ? AND ? AND {$time_sql} >= ? AND {$time_sql} <= ?)",
            [$date_from, $date_to, $start_time, $end_time],
        ];
    }

    return [
        "(
            ({$date_sql} BETWEEN ? AND ? AND {$time_sql} >= ?)
            OR
            ({$date_sql} BETWEEN DATE_ADD(?, INTERVAL 1 DAY) AND DATE_ADD(?, INTERVAL 1 DAY) AND {$time_sql} <= ?)
        )",
        [$date_from, $date_to, $start_time, $date_from, $date_to, $end_time],
    ];
}

function dashboard_transaction_status_where(PDO $pdo, string $table, array $columns): string {
    $excluded = "'void', 'voided', 'rejected', 'cancelled', 'canceled'";
    $checks = [];

    foreach ($columns as $column) {
        if (dashboard_has_column($pdo, $table, $column)) {
            $col = dashboard_column_sql($column);
            $checks[] = "LOWER(TRIM(COALESCE({$col}, ''))) NOT IN ({$excluded})";
        }
    }

    return empty($checks) ? '' : ' AND ' . implode(' AND ', $checks);
}

function dashboard_shift_hour_map(string $start_time, string $end_time): array {
    $start_ts = strtotime('2000-01-01 ' . $start_time);
    $end_ts = strtotime('2000-01-01 ' . $end_time);

    if ($start_ts === false || $end_ts === false) {
        return array_fill_keys(range(0, 23), 0);
    }

    if ($end_ts < $start_ts) {
        $end_ts += 86400;
    }

    $hours = [];
    for ($ts = $start_ts; $ts <= $end_ts && count($hours) < 25; $ts += 3600) {
        $hours[(int) date('G', $ts)] = 0;
    }

    return $hours;
}

function dashboard_datetime_expression(PDO $pdo, string $table, string $alias = '', array $preferred = ['created_at', 'transaction_date']): string {
    $parts = [];
    foreach ($preferred as $column) {
        if (dashboard_has_column($pdo, $table, $column)) {
            $parts[] = "NULLIF(" . dashboard_column_sql($column, $alias) . ", '0000-00-00 00:00:00')";
        }
    }

    if (empty($parts)) {
        return 'NOW()';
    }

    return count($parts) === 1 ? $parts[0] : 'COALESCE(' . implode(', ', $parts) . ')';
}

function dashboard_service_status_label($workflow_status, $validation_status = null): string {
    $wf = strtolower(trim((string) $workflow_status));
    $val = strtolower(trim((string) $validation_status));

    $is = static fn(string $value, array $options): bool => in_array($value, $options, true);

    if ($is($wf, ['rejected', 'cancelled', 'canceled', 'voided']) || $is($val, ['rejected', 'cancelled', 'canceled', 'voided'])) {
        return 'Rejected';
    }
    if ($is($wf, ['finalized', 'released']) || $is($val, ['finalized', 'released'])) {
        return 'Released';
    }
    if ($is($wf, ['completed', 'verified']) || $is($val, ['completed', 'verified'])) {
        return 'Completed';
    }
    if ($is($wf, ['in progress', 'ongoing', 'awaiting parts', 'on hold']) || $is($val, ['in progress', 'ongoing', 'awaiting parts', 'on hold'])) {
        return 'In Progress';
    }
    if ($is($wf, ['approved', 'validated', 'adjusted', 'reviewed']) || $is($val, ['approved', 'validated', 'adjusted', 'reviewed'])) {
        return 'Approved';
    }

    return 'Pending Validation';
}

function dashboard_service_tracker_filters(PDO $pdo, string $alias = ''): array {
    $filters = [];

    if (dashboard_has_column($pdo, 'merchandise_transactions', 'transaction_type')) {
        $col = dashboard_column_sql('transaction_type', $alias);
        $filters[] = "LOWER(COALESCE({$col}, '')) IN ('job_order', 'combined', 'service')";
    }
    if (dashboard_has_column($pdo, 'merchandise_transactions', 'job_order_service')) {
        $col = dashboard_column_sql('job_order_service', $alias);
        $filters[] = "({$col} IS NOT NULL AND TRIM({$col}) <> '')";
    }
    if (dashboard_has_column($pdo, 'merchandise_transactions', 'job_order_id')) {
        $filters[] = dashboard_column_sql('job_order_id', $alias) . ' IS NOT NULL';
    }
    if (dashboard_has_column($pdo, 'merchandise_transactions', 'job_order_db_id')) {
        $filters[] = dashboard_column_sql('job_order_db_id', $alias) . ' IS NOT NULL';
    }

    return $filters;
}

function dashboard_service_tracker_where(PDO $pdo, string $alias = ''): string {
    $filters = dashboard_service_tracker_filters($pdo, $alias);
    return empty($filters) ? '0=1' : '(' . implode(' OR ', $filters) . ')';
}

function dashboard_signature_query(PDO $pdo, string $key, string $from_where_sql, array $params, array $columns): string {
    $signature_columns = array_map(
        static fn($column) => "COALESCE(CAST({$column} AS CHAR), '')",
        $columns
    );

    try {
        $sql = "
            SELECT
                COUNT(*) AS row_count,
                COALESCE(SUM(CAST(CRC32(CONCAT_WS('|', " . implode(', ', $signature_columns) . ")) AS UNSIGNED)), 0) AS row_hash
            FROM {$from_where_sql}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return $key . ':' . (string)($row['row_count'] ?? '0') . ':' . (string)($row['row_hash'] ?? '0');
    } catch (Exception $e) {
        return $key . ':unavailable';
    }
}

function dashboard_change_version(PDO $pdo, int $station_id, int $user_id, string $date_from, string $date_to, array $shift_periods): string {
    $week_end = $date_to;
    $week_start = date('Y-m-d', strtotime('-6 days', strtotime($week_end)));
    $current_shift = dashboard_current_shift($shift_periods);
    $fuel_dt = dashboard_datetime_expression($pdo, 'fuel_transactions', '', ['transaction_date', 'created_at']);
    $merch_dt = dashboard_datetime_expression($pdo, 'merchandise_transactions', '', ['created_at', 'transaction_date']);
    $job_dt = dashboard_datetime_expression($pdo, 'job_orders', '', ['created_at']);

    $pieces = [
        'range:' . $date_from . ':' . $date_to,
        'week:' . $week_start . ':' . $week_end,
        'shift:' . ($current_shift ? dashboard_shift_label($current_shift) : 'none'),
    ];

    $fuel_columns = ['id', 'transaction_id', 'fuel_type', 'liters_sold', 'total_amount', 'status', 'validated_at', 'created_at', 'transaction_date', 'shift_name'];
    $merch_columns = ['id', 'transaction_id', 'total_amount', 'validation_status', 'payment_status', 'workflow_status', 'updated_at', 'validated_at', 'created_at', 'transaction_date', 'transaction_type'];
    $job_columns = ['id', 'job_order_number', 'status', 'validation_status', 'payment_status', 'vehicle_plate', 'customer_name', 'service_type', 'total_cost', 'updated_at', 'completed_at', 'created_at'];

    $pieces[] = dashboard_signature_query(
        $pdo,
        'fuel-range',
        "fuel_transactions WHERE station_id = ? AND DATE({$fuel_dt}) BETWEEN ? AND ?",
        [$station_id, $date_from, $date_to],
        $fuel_columns
    );
    $pieces[] = dashboard_signature_query(
        $pdo,
        'fuel-week',
        "fuel_transactions WHERE station_id = ? AND DATE({$fuel_dt}) BETWEEN ? AND ?",
        [$station_id, $week_start, $week_end],
        $fuel_columns
    );
    $pieces[] = dashboard_signature_query(
        $pdo,
        'merch-range',
        "merchandise_transactions WHERE station_id = ? AND DATE({$merch_dt}) BETWEEN ? AND ?",
        [$station_id, $date_from, $date_to],
        $merch_columns
    );
    $pieces[] = dashboard_signature_query(
        $pdo,
        'merch-week',
        "merchandise_transactions WHERE station_id = ? AND DATE({$merch_dt}) BETWEEN ? AND ?",
        [$station_id, $week_start, $week_end],
        $merch_columns
    );
    $pieces[] = dashboard_signature_query(
        $pdo,
        'merch-items-range',
        "merchandise_transaction_items mti INNER JOIN merchandise_transactions mt ON mti.transaction_id = mt.id WHERE mt.station_id = ? AND DATE(" . dashboard_datetime_expression($pdo, 'merchandise_transactions', 'mt', ['created_at', 'transaction_date']) . ") BETWEEN ? AND ?",
        [$station_id, $date_from, $date_to],
        ['mti.id', 'mti.transaction_id', 'mti.product_name', 'mti.category', 'mti.quantity', 'mti.subtotal', 'mti.created_at']
    );
    $pieces[] = dashboard_signature_query(
        $pdo,
        'job-range',
        "job_orders WHERE station_id = ? AND DATE({$job_dt}) BETWEEN ? AND ?",
        [$station_id, $date_from, $date_to],
        $job_columns
    );
    $pieces[] = dashboard_signature_query(
        $pdo,
        'job-week',
        "job_orders WHERE station_id = ? AND DATE({$job_dt}) BETWEEN ? AND ?",
        [$station_id, $week_start, $week_end],
        $job_columns
    );
    $pieces[] = dashboard_signature_query(
        $pdo,
        'fuel-inventory',
        "fuel_inventory WHERE station_id = ?",
        [$station_id],
        ['id', 'fuel_type', 'current_level', 'capacity', 'reorder_level', 'critical_level', 'status', 'last_updated']
    );
    $pieces[] = dashboard_signature_query(
        $pdo,
        'station-inventory',
        "station_inventory WHERE station_id = ?",
        [$station_id],
        ['id', 'product_id', 'stock_level', 'reorder_level', 'capacity', 'unit', 'status', 'last_updated']
    );
    $pieces[] = dashboard_signature_query(
        $pdo,
        'stock-requests',
        "stock_requests WHERE staff_id = ?",
        [$user_id],
        ['id', 'item_id', 'item_name', 'item_category', 'current_stock', 'requested_quantity', 'remarks', 'status', 'approved_quantity', 'processed_at', 'created_at', 'updated_at']
    );
    $pieces[] = dashboard_signature_query(
        $pdo,
        'fuel-stock-requests',
        "fuel_stock_requests WHERE staff_id = ?",
        [$user_id],
        ['id', 'fuel_type', 'current_level', 'capacity', 'stock_status', 'requested_liters', 'remarks', 'status', 'approved_liters', 'processed_at', 'created_at', 'updated_at']
    );
    $pieces[] = dashboard_signature_query(
        $pdo,
        'labor-sessions',
        "labor_sessions WHERE user_id = ? AND station_id = ?",
        [$user_id, $station_id],
        ['id', 'start_time', 'end_time', 'hours_worked', 'created_at', 'shift_period', 'shift_name']
    );

    return hash('sha256', implode('|', $pieces));
}

// User assigned shift — reads directly from users.shift_assignment set by admin
$user_assigned_shift = null;
$shift_assignment    = null;
try {
    $shift_check = $pdo->prepare("SELECT shift_assignment FROM users WHERE id = ? LIMIT 1");
    $shift_check->execute([$user_id]);
    $shift_assignment = $shift_check->fetchColumn() ?: null;
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

// Resolve the exact shift_periods row that admin assigned to this staff
$admin_shift_info = dashboard_shift_by_number($shift_periods, $user_assigned_shift);
if (!$admin_shift_info) {
    $admin_shift_info = $shift_periods[0] ?? null;
}
// Build clean label: e.g. "First Shift" and time range for the card
$admin_assigned_shift_name  = '';
$admin_assigned_shift_times = '';
if ($admin_shift_info) {
    // Strip the time part from shift_name (everything before ":" or "–"/"-")
    $raw_name = trim((string)($admin_shift_info['shift_name'] ?? 'Shift'));
    $raw_name = preg_replace('/[\x{2013}\x{2014}]/u', '-', $raw_name) ?? $raw_name;
    if (preg_match('/^\s*(.+?)\s*[:\-]/', $raw_name, $m)) {
        $admin_assigned_shift_name = trim($m[1]);
    } else {
        $admin_assigned_shift_name = $raw_name;
    }
    $s_start = isset($admin_shift_info['start_time']) ? date('g:i A', strtotime($admin_shift_info['start_time'])) : '';
    $s_end_raw = (string)($admin_shift_info['end_time'] ?? '');
    $s_end = $s_end_raw !== '' ? date('g:i A', strtotime($s_end_raw)) : '';
    if ($s_end_raw >= '23:59:00') $s_end = '12:00 AM';
    if ($s_start && $s_end) {
        $admin_assigned_shift_times = $s_start . ' – ' . $s_end;
    }
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

$is_today = ($date_from === date('Y-m-d') && $date_to === date('Y-m-d'));
$date_range_label = dashboard_range_label($date_from, $date_to);
$dashboard_version = dashboard_change_version($pdo, $station_id, $user_id, $date_from, $date_to, $shift_periods);

if (isset($_GET['dashboard_ping'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode([
        'success' => true,
        'version' => $dashboard_version,
        'checked_at' => date('c'),
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
$fuel_dt_expr = dashboard_datetime_expression($pdo, 'fuel_transactions', '', ['transaction_date', 'created_at']);
$merch_dt_expr = dashboard_datetime_expression($pdo, 'merchandise_transactions', '', ['created_at', 'transaction_date']);
$job_dt_expr = dashboard_datetime_expression($pdo, 'job_orders', '', ['created_at']);
$merch_dt_expr_mt = dashboard_datetime_expression($pdo, 'merchandise_transactions', 'mt', ['created_at', 'transaction_date']);
$job_validation_expr = dashboard_has_column($pdo, 'job_orders', 'validation_status') ? 'validation_status' : 'NULL';
$mt_service_where = dashboard_service_tracker_where($pdo, 'mt');
$mt_workflow_expr = dashboard_has_column($pdo, 'merchandise_transactions', 'workflow_status') ? dashboard_column_sql('workflow_status', 'mt') : 'NULL';
$mt_validation_expr = dashboard_has_column($pdo, 'merchandise_transactions', 'validation_status') ? dashboard_column_sql('validation_status', 'mt') : 'NULL';
$mt_queue_status_expr = "LOWER(COALESCE(NULLIF(TRIM({$mt_workflow_expr}), ''), NULLIF(TRIM({$mt_validation_expr}), ''), 'pending'))";
$open_service_statuses_sql = "'pending','pending validation','reviewed','approved','validated','adjusted','in progress','ongoing','awaiting parts','on hold'";
$pending_validation_statuses_sql = "'pending','pending validation',''";
$job_queue_status_expr = "LOWER(COALESCE(NULLIF(TRIM(status), ''), 'pending'))";
$job_queue_validation_expr = "LOWER(COALESCE(NULLIF(TRIM({$job_validation_expr}), ''), 'pending validation'))";
$mt_queue_workflow_expr = "LOWER(COALESCE(NULLIF(TRIM({$mt_workflow_expr}), ''), ''))";
$mt_queue_validation_expr = "LOWER(COALESCE(NULLIF(TRIM({$mt_validation_expr}), ''), 'pending validation'))";
$job_active_queue_condition = "({$job_queue_status_expr} IN ({$open_service_statuses_sql}) OR ({$job_queue_status_expr} IN ('completed','verified') AND {$job_queue_validation_expr} IN ({$pending_validation_statuses_sql})))";
$mt_active_queue_condition = "({$mt_queue_status_expr} IN ({$open_service_statuses_sql}) OR ({$mt_queue_workflow_expr} IN ('completed','verified') AND {$mt_queue_validation_expr} IN ({$pending_validation_statuses_sql})))";

// METRICS QUERY BLOCK
// ─────────────────────────────────────────────────────────────────────────────

// 1. Today's Transactions
$fuel_count = 0; $merch_count = 0; $jo_count = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM fuel_transactions WHERE station_id = ? AND DATE({$fuel_dt_expr}) BETWEEN ? AND ? AND LOWER(COALESCE(status, '')) NOT IN ('voided','rejected')");
    $stmt->execute([$station_id, $date_from, $date_to]);
    $fuel_count = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM merchandise_transactions WHERE station_id = ? AND DATE({$merch_dt_expr}) BETWEEN ? AND ? AND LOWER(COALESCE(validation_status, '')) NOT IN ('voided','rejected')");
    $stmt->execute([$station_id, $date_from, $date_to]);
    $merch_count = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id = ? AND DATE({$job_dt_expr}) BETWEEN ? AND ? AND LOWER(COALESCE(status, '')) NOT IN ('voided','cancelled')");
    $stmt->execute([$station_id, $date_from, $date_to]);
    $jo_count = (int)$stmt->fetchColumn();
} catch (Exception $e) {}
$todays_transactions = $fuel_count + $merch_count + $jo_count;

// 2. Today's Sales Breakdown
$fuel_sales = 0.0; $merch_sales = 0.0; $service_sales = 0.0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM fuel_transactions WHERE station_id=? AND DATE({$fuel_dt_expr}) BETWEEN ? AND ? AND LOWER(COALESCE(status, '')) NOT IN ('voided','rejected')");
    $stmt->execute([$station_id, $date_from, $date_to]);
    $fuel_sales = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

// Pure Merchandise Sales (excluding Job Orders / Services)
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(mt.total_amount),0) FROM merchandise_transactions mt WHERE mt.station_id=? AND DATE({$merch_dt_expr_mt}) BETWEEN ? AND ? AND LOWER(COALESCE(mt.transaction_type,'merchandise')) NOT IN ('job_order','service','combined') AND NULLIF(TRIM(COALESCE(mt.job_order_service,'')),'') IS NULL AND LOWER(COALESCE(mt.validation_status,'')) NOT IN ('voided','rejected')");
    $stmt->execute([$station_id, $date_from, $date_to]);
    $merch_sales = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

// Native Job Orders Service Sales
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(total_cost, estimated_cost, 0)),0) FROM job_orders WHERE station_id=? AND DATE({$job_dt_expr}) BETWEEN ? AND ? AND LOWER(COALESCE(status,'')) NOT IN ('voided','cancelled')");
    $stmt->execute([$station_id, $date_from, $date_to]);
    $service_sales += (float)$stmt->fetchColumn();
} catch (Exception $e) {}

// Service / Job Order Sales recorded in Merchandise Transactions
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(mt.total_amount),0) FROM merchandise_transactions mt WHERE mt.station_id=? AND DATE({$merch_dt_expr_mt}) BETWEEN ? AND ? AND (LOWER(COALESCE(mt.transaction_type,'')) IN ('job_order','service','combined') OR NULLIF(TRIM(COALESCE(mt.job_order_service,'')),'') IS NOT NULL) AND LOWER(COALESCE(mt.validation_status,'')) NOT IN ('voided','rejected')");
    $stmt->execute([$station_id, $date_from, $date_to]);
    $service_sales += (float)$stmt->fetchColumn();
} catch (Exception $e) {}

$todays_sales = $fuel_sales + $merch_sales + $service_sales;

// 2b. Completed Job Orders Metrics
$completed_jo_today_count = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND DATE({$job_dt_expr}) BETWEEN ? AND ? AND LOWER(TRIM(COALESCE(status, ''))) IN ('completed','verified','released','finalized')");
    $stmt->execute([$station_id, $date_from, $date_to]);
    $completed_jo_today_count += (int)$stmt->fetchColumn();
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM merchandise_transactions mt WHERE mt.station_id=? AND DATE({$merch_dt_expr_mt}) BETWEEN ? AND ? AND (LOWER(COALESCE(mt.transaction_type,'')) IN ('job_order','service','combined') OR NULLIF(TRIM(COALESCE(mt.job_order_service,'')),'') IS NOT NULL) AND LOWER(COALESCE(mt.validation_status,'')) NOT IN ('voided','rejected')");
    $stmt->execute([$station_id, $date_from, $date_to]);
    $completed_jo_today_count += (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// Payment Methods Breakdown Today
$payment_methods_today_map = [
    'Cash'               => 0.0,
    'Credit Card'        => 0.0,
    'Debit Card'         => 0.0,
    'GCash'              => 0.0,
    'Maya'               => 0.0,
    'Petron Fleet Card'  => 0.0,
    'Credit Account'     => 0.0,
];

$normalize_pm_fn = function($raw) {
    $p = strtolower(trim((string)$raw));
    if ($p === '') return 'Cash';
    if (str_contains($p, 'gcash')) return 'GCash';
    if (str_contains($p, 'maya') || str_contains($p, 'paymaya')) return 'Maya';
    if (str_contains($p, 'fleet') || str_contains($p, 'petron fleet')) return 'Petron Fleet Card';
    if (str_contains($p, 'debit')) return 'Debit Card';
    if (str_contains($p, 'credit card') || str_contains($p, 'visa') || str_contains($p, 'mastercard')) return 'Credit Card';
    if (str_contains($p, 'credit') || str_contains($p, 'account') || str_contains($p, 'suki') || str_contains($p, 'utang') || str_contains($p, 'receivable') || str_contains($p, 'ar')) return 'Credit Account';
    if (str_contains($p, 'cash')) return 'Cash';
    return 'Cash';
};

try {
    $stmt = $pdo->prepare("SELECT COALESCE(payment_method, 'Cash') AS pm, total_amount FROM fuel_transactions WHERE station_id=? AND DATE({$fuel_dt_expr}) BETWEEN ? AND ? AND LOWER(COALESCE(status, '')) NOT IN ('voided','rejected')");
    $stmt->execute([$station_id, $date_from, $date_to]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cat = $normalize_pm_fn($r['pm']);
        $payment_methods_today_map[$cat] += (float)$r['total_amount'];
    }
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("SELECT COALESCE(payment_method, 'Cash') AS pm, total_amount FROM merchandise_transactions WHERE station_id=? AND DATE({$merch_dt_expr}) BETWEEN ? AND ? AND LOWER(COALESCE(validation_status, '')) NOT IN ('voided','rejected')");
    $stmt->execute([$station_id, $date_from, $date_to]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cat = $normalize_pm_fn($r['pm']);
        $payment_methods_today_map[$cat] += (float)$r['total_amount'];
    }
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("SELECT COALESCE(payment_method, 'Cash') AS pm, COALESCE(total_cost, estimated_cost, 0) AS total_cost FROM job_orders WHERE station_id=? AND DATE({$job_dt_expr}) BETWEEN ? AND ? AND LOWER(COALESCE(status, '')) NOT IN ('voided','cancelled')");
    $stmt->execute([$station_id, $date_from, $date_to]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cat = $normalize_pm_fn($r['pm']);
        $payment_methods_today_map[$cat] += (float)$r['total_cost'];
    }
} catch (Exception $e) {}

$pie_pm_labels = array_keys($payment_methods_today_map);
$pie_pm_data   = array_values($payment_methods_today_map);

$bar_merch_vs_service_labels = ["Merchandise Sales Today", "Service Sales Today"];
$bar_merch_vs_service_data   = [round($merch_sales, 2), round($service_sales, 2)];

// 3. Fuel Sold Today
$fuel_sold_liters = 0.0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(liters_sold),0) FROM fuel_transactions WHERE station_id=? AND DATE({$fuel_dt_expr}) BETWEEN ? AND ?");
    $stmt->execute([$station_id, $date_from, $date_to]);
    $fuel_sold_liters = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

// 4. Service Queue — all open jobs regardless of date (active queue, not date-filtered)
$service_queue_count = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM job_orders
        WHERE station_id = ?
          AND {$job_active_queue_condition}
    ");
    $stmt->execute([$station_id]);
    $service_queue_count = (int)$stmt->fetchColumn();
} catch (Exception $e) {}
if ($mt_service_where !== '0=1') {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM merchandise_transactions mt
            WHERE mt.station_id = ?
              AND {$mt_service_where}
              AND {$mt_active_queue_condition}
        ");
        $stmt->execute([$station_id]);
        $service_queue_count += (int)$stmt->fetchColumn();
    } catch (Exception $e) {}
}

// 5. Fuel Stock Alerts (dynamic: uses reorder_level / critical_level from fuel_inventory or 20% capacity)
$fuel_stock_alerts_count = 0;
try {
    $stmt = $pdo->prepare("SELECT current_level, capacity, COALESCE(reorder_level, 0) AS reorder_level FROM fuel_inventory WHERE station_id=?");
    $stmt->execute([$station_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fi_row) {
        $fi_cap   = (float)($fi_row['capacity'] ?? 0);
        $fi_level = min(max(0, (float)($fi_row['current_level'] ?? 0)), $fi_cap > 0 ? $fi_cap : PHP_FLOAT_MAX);
        $fi_reorder = (float)($fi_row['reorder_level'] ?? 0);
        if ($fi_reorder <= 0) $fi_reorder = $fi_cap > 0 ? $fi_cap * 0.20 : 0;
        if ($fi_level <= $fi_reorder) $fuel_stock_alerts_count++;
    }
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
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM fuel_stock_requests WHERE staff_id=? AND status IN ('Pending', 'Pending Manager Review')");
    $stmt->execute([$user_id]);
    $pending_fuel_requests_count = (int)$stmt->fetchColumn();
} catch (Exception $e) {}
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_requests WHERE staff_id=? AND status IN ('Pending', 'Pending Manager Review')");
    $stmt->execute([$user_id]);
    $pending_merch_requests_count = (int)$stmt->fetchColumn();
} catch (Exception $e) {}
$pending_stock_requests = $pending_fuel_requests_count + $pending_merch_requests_count;

// ── Live Sync JSON Endpoint: lightweight metric refresh without full page reload ──
if (isset($_GET['live_sync']) && $_GET['live_sync'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode([
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'metrics' => [
            'todays_transactions'    => number_format($todays_transactions),
            'todays_sales'           => '₱' . number_format($todays_sales, 2),
            'service_queue_count'    => number_format($service_queue_count),
            'completed_jo_today_count' => number_format($completed_jo_today_count),
            'merch_sales'            => '₱' . number_format($merch_sales, 2),
            'service_sales'          => '₱' . number_format($service_sales, 2),
            'fuel_sold_liters'       => number_format($fuel_sold_liters, 2) . ' L',
            'pending_stock_requests' => number_format($pending_stock_requests),
            'fuel_stock_alerts'      => number_format($fuel_stock_alerts_count),
            'merch_stock_alerts'     => number_format($merch_stock_alerts_count),
        ],
        'version' => $dashboard_version,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// 8. Current Shift
$current_shift_info = dashboard_current_shift($shift_periods);
$current_shift_label = $current_shift_info ? dashboard_shift_label($current_shift_info) : 'No Active Shift';

$current_shift_window_start = date('Y-m-d 00:00:00');
$current_shift_window_end = date('Y-m-d 23:59:59');
if ($current_shift_info) {
    $shift_start_time = $current_shift_info['start_time'] ?? '00:00:00';
    $shift_end_time = $current_shift_info['end_time'] ?? '23:59:59';
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $now_time = date('H:i:s');

    if ($shift_start_time <= $shift_end_time) {
        $current_shift_window_start = "{$today} {$shift_start_time}";
        $current_shift_window_end = "{$today} {$shift_end_time}";
    } elseif ($now_time <= $shift_end_time) {
        $current_shift_window_start = "{$yesterday} {$shift_start_time}";
        $current_shift_window_end = "{$today} {$shift_end_time}";
    } else {
        $current_shift_window_start = "{$today} {$shift_start_time}";
        $current_shift_window_end = "{$tomorrow} {$shift_end_time}";
    }
}

$hourly_shift_number = dashboard_shift_number_from_value($clock_in_shift, $shift_periods) ?? $user_assigned_shift;
$hourly_shift_info = dashboard_shift_by_number($shift_periods, (int) $hourly_shift_number);
if (!$hourly_shift_info) {
    $hourly_shift_info = $current_shift_info ?: ($shift_periods[0] ?? null);
}
$hourly_shift_label = $hourly_shift_info ? dashboard_shift_label($hourly_shift_info) : 'Assigned Shift';
$hourly_shift_start_time = $hourly_shift_info['start_time'] ?? '00:00:00';
$hourly_shift_end_time = $hourly_shift_info['end_time'] ?? '23:59:59';

// ─────────────────────────────────────────────────────────────────────────────
// CHARTS QUERY BLOCK
// ─────────────────────────────────────────────────────────────────────────────

// Chart 1: Hourly Transactions (Line Chart)
[$fuel_shift_where, $fuel_shift_params] = dashboard_shift_datetime_where($fuel_dt_expr, $date_from, $date_to, $hourly_shift_start_time, $hourly_shift_end_time);
[$merch_shift_where, $merch_shift_params] = dashboard_shift_datetime_where($merch_dt_expr, $date_from, $date_to, $hourly_shift_start_time, $hourly_shift_end_time);
[$job_shift_where, $job_shift_params] = dashboard_shift_datetime_where($job_dt_expr, $date_from, $date_to, $hourly_shift_start_time, $hourly_shift_end_time);

$fuel_hourly_status_where = dashboard_transaction_status_where($pdo, 'fuel_transactions', ['status']);
$merch_hourly_status_where = dashboard_transaction_status_where($pdo, 'merchandise_transactions', ['validation_status', 'workflow_status', 'payment_status']);
$job_hourly_status_where = dashboard_transaction_status_where($pdo, 'job_orders', ['status', 'validation_status', 'payment_status']);

$hourly_tx_raw = dashboard_fetch_all($pdo, "
    SELECT hr, COUNT(*) AS count FROM (
        SELECT HOUR({$fuel_dt_expr}) AS hr FROM fuel_transactions
        WHERE station_id = ?
          AND {$fuel_shift_where}
          {$fuel_hourly_status_where}
        UNION ALL
        SELECT HOUR({$merch_dt_expr}) AS hr FROM merchandise_transactions
        WHERE station_id = ?
          AND {$merch_shift_where}
          {$merch_hourly_status_where}
        UNION ALL
        SELECT HOUR({$job_dt_expr}) AS hr FROM job_orders
        WHERE station_id = ?
          AND {$job_shift_where}
          {$job_hourly_status_where}
    ) AS combined_txns
    GROUP BY hr
    ORDER BY hr
",
array_merge(
    [$station_id],
    $fuel_shift_params,
    [$station_id],
    $merch_shift_params,
    [$station_id],
    $job_shift_params
));

$hourly_map = dashboard_shift_hour_map($hourly_shift_start_time, $hourly_shift_end_time);
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

// Chart 2: Fuel Sales by Product (Liters) — Exactly 7 UGT Underground Tanks
$ugt_tank_chart = [
    'UGT #1 (DIESEL 1)'       => 0.0,
    'UGT #2 (DIESEL 2)'       => 0.0,
    'UGT #3 (TURBO DIESEL)'   => 0.0,
    'UGT #4 (XCS PLUS)'       => 0.0,
    'UGT #5 (XTRA ADVANCE 1)' => 0.0,
    'UGT #6 (XTRA ADVANCE 2)' => 0.0,
    'UGT #7 (KEROSENE)'       => 0.0,
];

try {
    $raw_fuel_sales = dashboard_fetch_all($pdo, "
        SELECT 
            COALESCE(NULLIF(fp.pump_number, ''), ft.fuel_type) AS pump_name,
            ft.fuel_type,
            COALESCE(ft.liters_sold, 0) AS total_liters
        FROM fuel_transactions ft
        LEFT JOIN fuel_pumps fp ON ft.pump_id = fp.id
        INNER JOIN (
            SELECT MAX(id) AS max_id
            FROM fuel_transactions
            WHERE station_id = ? AND (DATE(transaction_date) BETWEEN ? AND ? OR (transaction_date IS NULL AND DATE(created_at) BETWEEN ? AND ?))
              AND LOWER(COALESCE(status, '')) NOT IN ('voided','rejected','cancelled','canceled')
            GROUP BY COALESCE(pump_id, fuel_type), DATE(COALESCE(transaction_date, created_at))
        ) latest ON ft.id = latest.max_id
    ", [$station_id, $date_from, $date_to, $date_from, $date_to]);

    foreach ($raw_fuel_sales as $row) {
        $pName  = strtoupper(trim($row['pump_name'] ?: $row['fuel_type']));
        $ftype  = strtolower(trim($row['fuel_type']));
        $liters = (float)$row['total_liters'];

        if (strpos($pName, 'DIESEL 1') !== false) {
            $ugt_tank_chart['UGT #1 (DIESEL 1)'] += $liters;
        } elseif (strpos($pName, 'DIESEL 2') !== false) {
            $ugt_tank_chart['UGT #2 (DIESEL 2)'] += $liters;
        } elseif (strpos($pName, 'TURBO') !== false) {
            $ugt_tank_chart['UGT #3 (TURBO DIESEL)'] += $liters;
        } elseif (strpos($pName, 'XCS') !== false) {
            $ugt_tank_chart['UGT #4 (XCS PLUS)'] += $liters;
        } elseif (strpos($pName, 'XTRA UNL 1') !== false || strpos($pName, 'XTRA AD 1') !== false || strpos($pName, 'ADVANCE 1') !== false) {
            $ugt_tank_chart['UGT #5 (XTRA ADVANCE 1)'] += $liters;
        } elseif (strpos($pName, 'XTRA UNL 2') !== false || strpos($pName, 'XTRA AD 2') !== false || strpos($pName, 'ADVANCE 2') !== false) {
            $ugt_tank_chart['UGT #6 (XTRA ADVANCE 2)'] += $liters;
        } elseif (strpos($pName, 'KERO') !== false) {
            $ugt_tank_chart['UGT #7 (KEROSENE)'] += $liters;
        } else {
            if (strpos($ftype, 'turbo') !== false) {
                $ugt_tank_chart['UGT #3 (TURBO DIESEL)'] += $liters;
            } elseif (strpos($ftype, 'diesel') !== false) {
                $ugt_tank_chart['UGT #1 (DIESEL 1)'] += $liters;
            } elseif (strpos($ftype, 'xcs') !== false) {
                $ugt_tank_chart['UGT #4 (XCS PLUS)'] += $liters;
            } elseif (strpos($ftype, 'xtra') !== false || strpos($ftype, 'advance') !== false) {
                $ugt_tank_chart['UGT #5 (XTRA ADVANCE 1)'] += $liters;
            } elseif (strpos($ftype, 'kero') !== false) {
                $ugt_tank_chart['UGT #7 (KEROSENE)'] += $liters;
            }
        }
    }
} catch (Exception $e) {}

$fuel_chart_labels = array_keys($ugt_tank_chart);
$fuel_chart_data   = array_values($ugt_tank_chart);

// Chart 3: Merchandise Sales by Category (Bar Chart) — DB-driven, real categories
$merch_chart_labels = [];
$merch_chart_data = [];
$raw_merch_sales = dashboard_fetch_all($pdo, "
    SELECT
        COALESCE(NULLIF(TRIM(mti.category), ''), 'Others') AS category,
        COALESCE(SUM(mti.subtotal), 0) AS total_sales
    FROM merchandise_transaction_items mti
    INNER JOIN merchandise_transactions mt ON mti.transaction_id = mt.id
    WHERE mt.station_id = ?
      AND DATE({$merch_dt_expr_mt}) BETWEEN ? AND ?
    GROUP BY category
    ORDER BY total_sales DESC
    LIMIT 8
", [$station_id, $date_from, $date_to]);

// Use real categories from DB — no hardcoded mapping
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
// Mirrors the Job Order Tracker sources: native job_orders + service rows encoded in merchandise_transactions.
$canonical_statuses = [
    'Pending Validation' => 0,
    'Approved' => 0,
    'In Progress' => 0,
    'Completed' => 0,
    'Released' => 0,
    'Rejected' => 0,
];

$job_validation_expr = dashboard_has_column($pdo, 'job_orders', 'validation_status') ? 'validation_status' : 'NULL';
$raw_job_statuses = dashboard_fetch_all($pdo, "
    SELECT status AS workflow_status, {$job_validation_expr} AS validation_status
    FROM job_orders
    WHERE station_id = ? AND DATE({$job_dt_expr}) BETWEEN ? AND ?
", [$station_id, $date_from, $date_to]);

foreach ($raw_job_statuses as $row) {
    $label = dashboard_service_status_label($row['workflow_status'] ?? null, $row['validation_status'] ?? null);
    if (isset($canonical_statuses[$label])) {
        $canonical_statuses[$label]++;
    }
}

$mt_workflow_expr = dashboard_has_column($pdo, 'merchandise_transactions', 'workflow_status') ? 'mt.workflow_status' : 'NULL';
$mt_validation_expr = dashboard_has_column($pdo, 'merchandise_transactions', 'validation_status') ? 'mt.validation_status' : 'NULL';
$mt_service_filters = [];
if (dashboard_has_column($pdo, 'merchandise_transactions', 'transaction_type')) {
    $mt_service_filters[] = "LOWER(COALESCE(mt.transaction_type, '')) IN ('job_order', 'combined')";
}
if (dashboard_has_column($pdo, 'merchandise_transactions', 'job_order_service')) {
    $mt_service_filters[] = "(mt.job_order_service IS NOT NULL AND TRIM(mt.job_order_service) <> '')";
}
if (dashboard_has_column($pdo, 'merchandise_transactions', 'job_order_id')) {
    $mt_service_filters[] = 'mt.job_order_id IS NOT NULL';
}
if (dashboard_has_column($pdo, 'merchandise_transactions', 'job_order_db_id')) {
    $mt_service_filters[] = 'mt.job_order_db_id IS NOT NULL';
}

if (!empty($mt_service_filters)) {
    $raw_tracker_statuses = dashboard_fetch_all($pdo, "
        SELECT {$mt_workflow_expr} AS workflow_status, {$mt_validation_expr} AS validation_status
        FROM merchandise_transactions mt
        WHERE mt.station_id = ?
          AND DATE({$merch_dt_expr_mt}) BETWEEN ? AND ?
          AND (" . implode(' OR ', $mt_service_filters) . ")
    ", [$station_id, $date_from, $date_to]);

    foreach ($raw_tracker_statuses as $row) {
        $label = dashboard_service_status_label($row['workflow_status'] ?? null, $row['validation_status'] ?? null);
        if (isset($canonical_statuses[$label])) {
            $canonical_statuses[$label]++;
        }
    }
}

$status_chart_labels = array_keys(array_filter($canonical_statuses, static fn($count) => $count > 0));
$status_chart_data = array_values(array_filter($canonical_statuses, static fn($count) => $count > 0));

// Chart 5: Weekly Transaction Trend (Line Chart)
$week_end = $date_to;
if ($date_from !== $date_to && (strtotime($date_to) - strtotime($date_from)) > 0 && (strtotime($date_to) - strtotime($date_from)) <= 6 * 86400) {
    $week_start = $date_from;
} else {
    $week_start = date('Y-m-d', strtotime('-6 days', strtotime($week_end)));
}

$raw_weekly = dashboard_fetch_all($pdo, "
    SELECT DAYNAME(dt) AS day_name, COUNT(*) AS count FROM (
        SELECT DATE({$fuel_dt_expr}) AS dt FROM fuel_transactions
        WHERE station_id = ? AND DATE({$fuel_dt_expr}) BETWEEN ? AND ?
        UNION ALL
        SELECT DATE({$merch_dt_expr}) AS dt FROM merchandise_transactions
        WHERE station_id = ? AND DATE({$merch_dt_expr}) BETWEEN ? AND ?
        UNION ALL
        SELECT DATE({$job_dt_expr}) AS dt FROM job_orders
        WHERE station_id = ? AND DATE({$job_dt_expr}) BETWEEN ? AND ?
    ) AS all_txns
    GROUP BY day_name
",
[
    $station_id, $week_start, $week_end,
    $station_id, $week_start, $week_end,
    $station_id, $week_start, $week_end
]);

$weekly_days = [];
$curr_ts = strtotime($week_start);
$end_ts = strtotime($week_end);
while ($curr_ts <= $end_ts) {
    $d_name = date('l', $curr_ts);
    $weekly_days[$d_name] = 0;
    $curr_ts = strtotime('+1 day', $curr_ts);
}

foreach ($raw_weekly as $row) {
    $day = $row['day_name'];
    if (isset($weekly_days[$day])) {
        $weekly_days[$day] += (int)$row['count'];
    }
}
$weekly_chart_labels = array_keys($weekly_days);
$weekly_chart_data = array_values($weekly_days);

// Chart 6: Fuel Tank Levels — 7 physical underground storage tanks (UGTs)
// Computes current level based on Beginning Inventory + Deliveries - Sales - Calibrations.
$TANK_CONFIG = get_tank_config($station_id);

$fi_lookup = [];
try {
    $s = $pdo->prepare("SELECT fuel_type, current_level, current_stock, capacity, price_per_liter, latest_calibration, status, last_updated FROM fuel_inventory WHERE station_id = ?");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fi_lookup[strtolower(trim($row['fuel_type']))] = $row;
    }
} catch (Exception $e) {}

$del_lookup = [];
try {
    $s = $pdo->prepare("SELECT tank_assigned, fuel_type, SUM(delivery_liters) AS total_del FROM fuel_deliveries WHERE station_id=? AND DATE(delivery_date)=CURDATE() AND status='Verified' GROUP BY tank_assigned, fuel_type");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $del_lookup[strtolower(trim($row['tank_assigned']))] = (float)$row['total_del'];
    }
} catch (Exception $e) {}

$sales_lookup = [];
try {
    $s = $pdo->prepare("SELECT fuel_type, SUM(liters_sold) AS total_sales FROM fuel_transactions WHERE station_id=? AND DATE(transaction_date)=CURDATE() AND status='Verified' GROUP BY fuel_type");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sales_lookup[strtolower(trim($row['fuel_type']))] = (float)$row['total_sales'];
    }
} catch (Exception $e) {}

$adj_lookup = [];
try {
    $s = $pdo->prepare("SELECT fi.fuel_type, COALESCE(SUM(fa.liters),0) AS total_adj FROM fuel_adjustments fa JOIN fuel_inventory fi ON fa.fuel_type_id=fi.fuel_type_id AND fi.station_id=fa.station_id WHERE fa.station_id=? AND DATE(fa.adjustment_date)=CURDATE() GROUP BY fi.fuel_type");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $adj_lookup[strtolower(trim($row['fuel_type']))] = (float)$row['total_adj'];
    }
} catch (Exception $e) {}

$tank_levels = [];
foreach ($TANK_CONFIG as $tc) {
    $ft_key   = strtolower(trim($tc['fuel_type']));
    $tank_key = strtolower(trim($tc['tank']));
    $tank_num = (int)($tc['tanker_num'] ?? 0);

    // Smart lookup for split fuel types in fuel_inventory (e.g. Diesel 1, Diesel 2, Xtra UNL 1, Xtra UNL 2)
    $inv = null;
    if ($ft_key === 'diesel') {
        if ($tank_num === 1 && isset($fi_lookup['diesel 1'])) {
            $inv = $fi_lookup['diesel 1'];
        } elseif ($tank_num === 2 && isset($fi_lookup['diesel 2'])) {
            $inv = $fi_lookup['diesel 2'];
        } else {
            $inv = $fi_lookup['diesel'] ?? null;
        }
    } elseif ($ft_key === 'xtra unl' || $ft_key === 'xtr advance') {
        if ($tank_num === 4 && (isset($fi_lookup['xtra unl 1']) || isset($fi_lookup['xtr advance 1']))) {
            $inv = $fi_lookup['xtra unl 1'] ?? $fi_lookup['xtr advance 1'];
        } elseif ($tank_num === 6 && (isset($fi_lookup['xtra unl 2']) || isset($fi_lookup['xtr advance 2']))) {
            $inv = $fi_lookup['xtra unl 2'] ?? $fi_lookup['xtr advance 2'];
        } else {
            $inv = $fi_lookup['xtra unl'] ?? $fi_lookup['xtr advance'] ?? null;
        }
    } else {
        $inv = $fi_lookup[$ft_key] ?? null;
    }

    $capacity  = (float)$tc['capacity'];
    $cur_level = $inv ? (float)($inv['current_level'] ?? $inv['current_stock'] ?? 0) : 0;

    // Deliveries per tank_assigned (or tank label)
    $purchases = $del_lookup[$tank_key] ?? $del_lookup[strtolower($tc['label'])] ?? 0;

    // Number of tanks sharing this fuel type without dedicated split rows
    $same_type_count = count(array_filter($TANK_CONFIG, fn($t) => strcasecmp(trim($t['fuel_type']), $tc['fuel_type']) === 0));

    // If inv is directly matched to a specific split row (like Diesel 1), use exact level directly
    $is_split_match = ($ft_key === 'diesel' && ($tank_num === 1 && isset($fi_lookup['diesel 1']) || $tank_num === 2 && isset($fi_lookup['diesel 2'])))
                   || (($ft_key === 'xtra unl' || $ft_key === 'xtr advance') && ($tank_num === 4 && (isset($fi_lookup['xtra unl 1']) || isset($fi_lookup['xtr advance 1'])) || $tank_num === 6 && (isset($fi_lookup['xtra unl 2']) || isset($fi_lookup['xtr advance 2']))));

    if ($is_split_match) {
        $beginning = $cur_level;
        $sales = $sales_lookup[strtolower(trim($inv['fuel_type'] ?? ''))] ?? 0;
        $calibration_adj = $adj_lookup[strtolower(trim($inv['fuel_type'] ?? ''))] ?? 0;
    } else {
        $sales_total     = $sales_lookup[$ft_key] ?? 0;
        $adj_total       = $adj_lookup[$ft_key] ?? 0;
        $sales           = $same_type_count > 0 ? round($sales_total / $same_type_count, 2) : 0;
        $calibration_adj = $same_type_count > 0 ? round($adj_total   / $same_type_count, 2) : 0;
        $beginning       = $same_type_count > 0 ? round($cur_level / $same_type_count, 2) : 0;
    }

    $total_available = $beginning + $purchases;
    $ending_system   = $capacity > 0
        ? min(max(0, $total_available - $sales - $calibration_adj), $capacity)
        : max(0, $total_available - $sales - $calibration_adj);

    $current_level_tank = $ending_system;

    // Reorder & Critical levels matching user's exact specification:
    // 14,000L tank => Low (reorder) at <= 5,000L, Critical at <= 2,500L
    // 7,000L tank => Low (reorder) at <= 2,000L, Critical at <= 1,000L
    if ($capacity == 7000) {
        $low_lvl      = 2000.0;
        $critical_lvl = 1000.0;
    } else {
        $low_lvl      = 5000.0;
        $critical_lvl = 2500.0;
    }

    $fill_pct = $capacity > 0 ? round(($current_level_tank / $capacity) * 100, 2) : 0;
    if      ($current_level_tank <= 0)             { $status = 'Out of Stock'; $sc = '#dc3545'; }
    elseif  ($current_level_tank <= $critical_lvl) { $status = 'Critical';     $sc = '#dc3545'; }
    elseif  ($current_level_tank <= $low_lvl)      { $status = 'Low';          $sc = '#fd7e14'; }
    else                                           { $status = 'Normal';       $sc = '#28a745'; }

    $tank_levels[] = [
        'tank_label'    => $tc['label'],
        'tank_assign'   => $tc['tank'],
        'fuel_type'     => $tc['fuel_type'],
        'level'         => $current_level_tank,
        'capacity'      => $capacity,
        'status'        => $status,
        'status_color'  => $sc,
        'pct'           => min(100.0, round($fill_pct, 1)),
        'reorder_level' => $low_lvl,
        'last_updated'  => $inv['last_updated'] ?? null,
    ];
}



// ─────────────────────────────────────────────────────────────────────────────
// TABLES QUERY BLOCK
// ─────────────────────────────────────────────────────────────────────────────

// Table 1: Recent Transactions
$recent_transactions = dashboard_fetch_all($pdo, "
    SELECT time, type, customer, amount, status FROM (
        SELECT {$fuel_dt_expr} AS time, 'Fuel' AS type, 'Walk-in' AS customer, total_amount AS amount, status
        FROM fuel_transactions 
        WHERE station_id = ?
        UNION ALL
        SELECT
            {$merch_dt_expr_mt} AS time,
            CASE WHEN {$mt_service_where} THEN 'Service' ELSE 'Merchandise' END AS type,
            mt.customer_name AS customer,
            mt.total_amount AS amount,
            CASE
                WHEN {$mt_service_where}
                    THEN COALESCE(NULLIF(TRIM({$mt_workflow_expr}), ''), NULLIF(TRIM({$mt_validation_expr}), ''), 'Pending Validation')
                ELSE COALESCE(NULLIF(TRIM({$mt_validation_expr}), ''), 'Pending')
            END AS status
        FROM merchandise_transactions mt
        WHERE mt.station_id = ?
        UNION ALL
        SELECT {$job_dt_expr} AS time, 'Service' AS type, customer_name AS customer, total_cost AS amount, status
        FROM job_orders 
        WHERE station_id = ?
    ) AS unioned
    ORDER BY time DESC
    LIMIT 10
", [$station_id, $station_id, $station_id]);

// Table 2: Active Service Queue — all open jobs (no date restriction)
$active_services = dashboard_fetch_all($pdo, "
    SELECT
        COALESCE(job_order_number, job_order_id, CONCAT('JO-', id)) AS service_no,
        COALESCE(customer_name, 'Walk-in')                          AS customer,
        CONCAT(
            COALESCE(NULLIF(vehicle_plate,''), '—'),
            CASE WHEN vehicle_type != '' AND vehicle_type IS NOT NULL
                 THEN CONCAT(' (', vehicle_type, ')') ELSE '' END
        )                                                           AS vehicle,
        COALESCE(service_type, service_description, 'Service')      AS service,
        status,
        {$job_dt_expr}                                              AS created_at
    FROM job_orders
    WHERE station_id = ?
      AND status IN ('Pending','Reviewed','In Progress','Awaiting Parts')
    ORDER BY {$job_dt_expr} DESC
    LIMIT 25
", [$station_id]);

// Include Job Order Tracker service rows saved through merchandise_transactions.
$active_service_sql = "
    SELECT
        COALESCE(job_order_number, job_order_id, CONCAT('JO-', id)) AS service_no,
        COALESCE(customer_name, 'Walk-in')                          AS customer,
        CONCAT(
            COALESCE(NULLIF(vehicle_plate,''), '-'),
            CASE WHEN vehicle_type != '' AND vehicle_type IS NOT NULL
                 THEN CONCAT(' (', vehicle_type, ')') ELSE '' END
        )                                                           AS vehicle,
        COALESCE(service_type, service_description, 'Service')      AS service,
        status                                                      AS workflow_status,
        {$job_validation_expr}                                      AS validation_status,
        {$job_dt_expr}                                              AS created_at
    FROM job_orders
    WHERE station_id = ?
      AND {$job_active_queue_condition}
";
$active_service_params = [$station_id];

if ($mt_service_where !== '0=1') {
    $active_service_sql .= "
        UNION ALL
        SELECT
            COALESCE(NULLIF(CAST(mt.job_order_id AS CHAR), ''), NULLIF(mt.transaction_id, ''), CONCAT('JO-', mt.id)) AS service_no,
            COALESCE(mt.customer_name, 'Walk-in')                                                                    AS customer,
            CONCAT(
                COALESCE(NULLIF(mt.job_order_vehicle_plate,''), '-'),
                CASE WHEN mt.job_order_vehicle_type != '' AND mt.job_order_vehicle_type IS NOT NULL
                     THEN CONCAT(' (', mt.job_order_vehicle_type, ')') ELSE '' END
            )                                                                                                       AS vehicle,
            COALESCE(mt.job_order_service, 'Service')                                                               AS service,
            {$mt_workflow_expr}                                                                                     AS workflow_status,
            {$mt_validation_expr}                                                                                   AS validation_status,
            {$merch_dt_expr_mt}                                                                                     AS created_at
        FROM merchandise_transactions mt
        WHERE mt.station_id = ?
          AND {$mt_service_where}
          AND {$mt_active_queue_condition}
    ";
    $active_service_params[] = $station_id;
}

$active_services = dashboard_fetch_all($pdo, "
    SELECT *
    FROM ({$active_service_sql}) AS active_queue
    ORDER BY created_at DESC
    LIMIT 25
", $active_service_params);

foreach ($active_services as &$active_service) {
    $active_service['status'] = dashboard_service_status_label(
        $active_service['workflow_status'] ?? null,
        $active_service['validation_status'] ?? null
    );
    $wf = strtolower(trim((string)($active_service['workflow_status'] ?? '')));
    $val = strtolower(trim((string)($active_service['validation_status'] ?? '')));
    if (in_array($wf, ['completed', 'verified'], true) && in_array($val, ['', 'pending', 'pending validation'], true)) {
        $active_service['status'] = 'Completed / Pending Validation';
    }
}
unset($active_service);

// Table 3: Fuel Stock Alerts (dynamic: uses reorder_level / critical_level from DB)
$fuel_stock_alerts = [];
try {
    $fi_rows = $pdo->prepare("SELECT fuel_type, current_level, capacity, COALESCE(reorder_level, 0) AS reorder_level, COALESCE(critical_level, 0) AS critical_level FROM fuel_inventory WHERE station_id=?");
    $fi_rows->execute([$station_id]);
    foreach ($fi_rows->fetchAll(PDO::FETCH_ASSOC) as $fi_row) {
        $fi_cap    = (float)($fi_row['capacity'] ?? 0);
        $fi_level  = min(max(0, (float)($fi_row['current_level'] ?? 0)), $fi_cap > 0 ? $fi_cap : PHP_FLOAT_MAX);
        $fi_reorder = (float)($fi_row['reorder_level'] ?? 0);
        $fi_crit    = (float)($fi_row['critical_level'] ?? 0);
        // Fall back to capacity percentages if not configured
        if ($fi_reorder <= 0) $fi_reorder = $fi_cap > 0 ? round($fi_cap * 0.30, 2) : 0;
        if ($fi_crit   <= 0) $fi_crit    = $fi_cap > 0 ? round($fi_cap * 0.15, 2) : 0;
        if ($fi_level <= $fi_reorder) {
            if ($fi_level <= 0)              { $st = 'Out of Stock'; }
            elseif ($fi_level <= $fi_crit)   { $st = 'Critical'; }
            else                             { $st = 'Low'; }
            $fuel_stock_alerts[] = [
                'fuel_type'     => $fi_row['fuel_type'],
                'current_stock' => $fi_level,
                'reorder_level' => $fi_reorder,
                'status'        => $st,
            ];
        }
    }
    usort($fuel_stock_alerts, fn($a,$b) => $a['current_stock'] <=> $b['current_stock']);
} catch (Exception $e) {}

// Table 4: Merchandise Low Stock
$merch_low_stock_table = dashboard_fetch_all($pdo, "
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
    LIMIT 25
", [$station_id]);

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
$requester_name = html_entity_decode($display_name, ENT_QUOTES | ENT_HTML5);
$pending_requests_table = dashboard_fetch_all($pdo, "
    SELECT COALESCE(request_no, CONCAT('PR-', id)) AS request_no, item_category AS type, ? AS requested_by, status 
    FROM stock_requests 
    WHERE staff_id = ? AND status IN ('Pending', 'Pending Manager Review')
    UNION ALL
    SELECT COALESCE(request_no, CONCAT('PR-', id)) AS request_no, 'Fuel' AS type, ? AS requested_by, status 
    FROM fuel_stock_requests 
    WHERE staff_id = ? AND status IN ('Pending', 'Pending Manager Review')
    ORDER BY request_no DESC
", [$requester_name, $user_id, $requester_name, $user_id]);

// Include standard header layout
include __DIR__ . '/../partials/header.php';
?>
<!-- Include local Chart.js from vendor folder -->
<script src="../assets/vendor/chart.js/chart.umd.min.js"></script>

<style>
    /* Rebuilt Premium Petron CSS styling */
    body[data-page="staff_dashboard"] .main {
        padding: 20px 20px 60px 20px !important;
        background: #f6f8fb;
        box-sizing: border-box;
    }
    .staff-dashboard {
        width: 100%;
        max-width: none;
        margin: 0;
        padding: 0 !important;
        min-height: calc(100vh - 110px);
        background: #f6f8fb;
        color: #0f172a;
        box-sizing: border-box;
    }
    .staff-dashboard * {
        box-sizing: border-box;
    }
    @media (max-width: 991px) {
        body[data-page="staff_dashboard"] .main {
            padding: 20px 16px 60px 16px !important;
        }
    }
    .dashboard-header-container {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
        flex-wrap: wrap;
        gap: 15px;
    }
    .welcome-meta h1 {
        margin: 0;
        font-size: 24px;
        font-weight: 800;
        color: #002F70;
    }
    .welcome-meta p {
        margin: 8px 0 0;
        color: #56657a;
        font-size: 13px;
        font-weight: 600;
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
    .chart-heading-subtitle {
        margin-left: auto;
        color: #64748b;
        font-size: 12px;
        font-weight: 700;
        line-height: 1.35;
        letter-spacing: 0;
        text-align: right;
        text-transform: none;
    }
    @media (max-width: 600px) {
        .chart-panel-card h3 {
            flex-wrap: wrap;
            align-items: flex-start;
        }
        .chart-heading-subtitle {
            width: 100%;
            margin-left: 28px;
            text-align: left;
        }
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
    .action-panel-content {
        padding: 24px;
        display: grid;
        gap: 24px;
    }
    .action-panel-pane,
    .action-panel-pane.active {
        display: block;
    }
    .action-panel-pane {
        padding-bottom: 24px;
        border-bottom: 1px solid #e2e8f0;
    }
    .action-panel-pane:last-child {
        padding-bottom: 0;
        border-bottom: none;
    }
    .table-section-title,
    .quick-actions-title {
        margin: 0 0 14px;
        color: #002F70;
        font-size: 16px;
        font-weight: 800;
        display: flex;
        align-items: center;
        gap: 8px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
    .quick-actions-panel {
        margin-bottom: 32px;
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

<section class="staff-dashboard">
<!-- Welcome / Filter Banner -->
<div class="dashboard-header-container">
    <div class="welcome-meta">
        <h1>Welcome, <?= $display_name ?>!</h1>
        <div style="display:flex; align-items:center; gap:8px; margin-top:4px; margin-bottom:8px;">
            <i class="fas fa-user-circle" style="color:#64748b; font-size:14px;"></i>
            <span style="color:#64748b; font-size:13px; font-weight:600;">Staff Dashboard</span>
        </div>
    </div>
    <div class="header-filters">
        <form method="GET" class="date-filter-form">
            <span style="color:#64748b; font-weight:600; font-size:13px;">From</span>
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

<!-- Staff Dashboard Metrics Grid -->
<div class="summary-cards-grid">
    <!-- 1. Today's Transactions -->
    <div class="summary-metric-card">
        <div class="metric-details">
            <h4><?= $is_today ? "Today's Transactions" : "Transactions" ?></h4>
            <div class="metric-value" data-live-metric="todays_transactions"><?= number_format($todays_transactions) ?></div>
        </div>
        <div class="metric-icon-box" style="background: #eff6ff; color: #002F70;">
            <i class="fas fa-exchange-alt"></i>
        </div>
    </div>
    <!-- 2. Active Job Orders -->
    <div class="summary-metric-card">
        <div class="metric-details">
            <h4>Active Job Orders</h4>
            <div class="metric-value" data-live-metric="service_queue_count"><?= number_format($service_queue_count) ?></div>
        </div>
        <div class="metric-icon-box" style="background: #fef9c3; color: #d97706;">
            <i class="fas fa-tools"></i>
        </div>
    </div>
    <!-- 3. Completed Job Orders Today -->
    <div class="summary-metric-card">
        <div class="metric-details">
            <h4><?= $is_today ? "Completed Job Orders Today" : "Completed Job Orders" ?></h4>
            <div class="metric-value" data-live-metric="completed_jo_today_count"><?= number_format($completed_jo_today_count) ?></div>
        </div>
        <div class="metric-icon-box" style="background: #f0fdf4; color: #16a34a;">
            <i class="fas fa-check-circle"></i>
        </div>
    </div>
    <!-- 4. Merchandise Sales Today -->
    <div class="summary-metric-card">
        <div class="metric-details">
            <h4><?= $is_today ? "Merchandise Sales Today" : "Merchandise Sales" ?></h4>
            <div class="metric-value" data-live-metric="merch_sales">&#8369;<?= number_format($merch_sales, 2) ?></div>
        </div>
        <div class="metric-icon-box" style="background: #ffedd5; color: #ea580c;">
            <i class="fas fa-boxes"></i>
        </div>
    </div>
    <!-- 5. Service Sales Today -->
    <div class="summary-metric-card">
        <div class="metric-details">
            <h4><?= $is_today ? "Service Sales Today" : "Service Sales" ?></h4>
            <div class="metric-value" data-live-metric="service_sales">&#8369;<?= number_format($service_sales, 2) ?></div>
        </div>
        <div class="metric-icon-box" style="background: #ecfeff; color: #0891b2;">
            <i class="fas fa-wrench"></i>
        </div>
    </div>
    <!-- 6. Current Shift (admin-assigned) + live clock -->
    <div class="summary-metric-card">
        <div class="metric-details">
            <h4>Current Shift</h4>
            <div class="metric-value" style="font-size: 15px; font-weight: 700; margin-top: 6px; color: #0f172a;">
                <?= htmlspecialchars($admin_assigned_shift_name) ?>
            </div>
            <?php if ($admin_assigned_shift_times): ?>
            <div style="font-size: 11px; color: #64748b; font-weight: 600; margin-top: 2px;">
                <?= htmlspecialchars($admin_assigned_shift_times) ?>
            </div>
            <?php endif; ?>
            <div style="font-size: 13px; font-weight: 700; color: #0891b2; margin-top: 6px; font-variant-numeric: tabular-nums;" id="dashLiveClock">--:-- --</div>
        </div>
        <div class="metric-icon-box" style="background: #ecfeff; color: #0891b2;">
            <i class="fas fa-clock"></i>
        </div>
    </div>
    <!-- Fuel Sold Today (Liters) -->
    <div class="summary-metric-card">
        <div class="metric-details">
            <h4><?= $is_today ? "Fuel Sold Today (Liters)" : "Fuel Sold (Liters)" ?></h4>
            <div class="metric-value" data-live-metric="fuel_sold_liters"><?= number_format($fuel_sold_liters, 2) ?> L</div>
        </div>
        <div class="metric-icon-box" style="background: #fef2f2; color: #dc2626;">
            <i class="fas fa-gas-pump"></i>
        </div>
    </div>
    <!-- Pending Stock Requests -->
    <div class="summary-metric-card">
        <div class="metric-details">
            <h4>Pending Stock Requests</h4>
            <div class="metric-value" data-live-metric="pending_stock_requests"><?= number_format($pending_stock_requests) ?></div>
        </div>
        <div class="metric-icon-box" style="background: #f3e8ff; color: #7c3aed;">
            <i class="fas fa-file-import"></i>
        </div>
    </div>
</div>

<!-- Charts & Visualizations Grid -->
<div class="charts-grid-layout">
    <!-- Pie Chart: Payment Methods Today -->
    <div class="chart-panel-card">
        <h3><i class="fas fa-chart-pie"></i> Payment Methods Today</h3>
        <div class="chart-container-inner">
            <canvas id="paymentMethodsPieChart"></canvas>
        </div>
    </div>

    <!-- Bar Chart: Today's Merchandise vs Service Sales -->
    <div class="chart-panel-card">
        <h3><i class="fas fa-chart-bar"></i> Today's Merchandise vs Service Sales</h3>
        <div class="chart-container-inner">
            <canvas id="merchVsServiceBarChart"></canvas>
        </div>
    </div>
    <!-- Hourly Transactions -->
    <div class="chart-panel-card">
        <h3>
            <i class="fas fa-chart-line"></i> Hourly Transactions
            <span class="chart-heading-subtitle"><?= htmlspecialchars($hourly_shift_label) ?></span>
        </h3>
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

    <!-- Fuel Tank Levels Table (matching manager_set_prices.php layout) -->
    <div class="chart-panel-card" style="grid-column: 1 / -1;">
        <h3><i class="fas fa-tachometer-alt"></i> Fuel Tank Levels</h3>
        <?php if (empty($tank_levels)): ?>
            <div style="text-align:center; padding: 40px 0; color:#64748b;">
                <i class="fas fa-gas-pump" style="font-size:32px; margin-bottom:10px; display:block;"></i>
                No tank level readings available.
            </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead>
                    <tr style="background:#002F70;">
                        <th style="padding:11px 14px; color:#fff; font-weight:700; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; text-align:left; white-space:nowrap;">UGT No.</th>
                        <th style="padding:11px 14px; color:#fff; font-weight:700; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; text-align:left; white-space:nowrap;">Fuel Type</th>
                        <th style="padding:11px 14px; color:#fff; font-weight:700; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; text-align:left; white-space:nowrap;">Stock Level (L)</th>
                        <th style="padding:11px 14px; color:#fff; font-weight:700; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; text-align:left; white-space:nowrap;">Capacity (L)</th>
                        <th style="padding:11px 14px; color:#fff; font-weight:700; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; text-align:left; white-space:nowrap;">Critical Level (L)</th>
                        <th style="padding:11px 14px; color:#fff; font-weight:700; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; text-align:left; white-space:nowrap;">Status</th>
                        <th style="padding:11px 14px; color:#fff; font-weight:700; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; text-align:left; white-space:nowrap;">Last Updated</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tank_levels as $tl):
                        $tl_current   = (float)$tl['level'];
                        $tl_capacity  = (float)$tl['capacity'];
                        $tl_pct       = $tl['pct'];
                        $tl_status    = $tl['status'];
                        $tl_color     = $tl['status_color'];
                        $tl_reorder   = (float)$tl['reorder_level'];

                        // Critical level: derived from capacity (same logic as backend)
                        $tl_critical  = ($tl_capacity == 7000) ? 1000.0 : 2500.0;

                        if ($tl_status === 'Normal') {
                            $tl_badge_bg   = '#dcfce7'; $tl_badge_color = '#15803d';
                            $tl_bar_color  = '#16a34a';
                            $tl_badge_icon = '&#10003;';
                        } elseif ($tl_status === 'Low') {
                            $tl_badge_bg   = '#fef9c3'; $tl_badge_color = '#a16207';
                            $tl_bar_color  = '#eab308';
                            $tl_badge_icon = '&#9888;';
                        } elseif ($tl_status === 'Critical') {
                            $tl_badge_bg   = '#fee2e2'; $tl_badge_color = '#b91c1c';
                            $tl_bar_color  = '#ef4444';
                            $tl_badge_icon = '&#9888;';
                        } else {
                            $tl_badge_bg   = '#fee2e2'; $tl_badge_color = '#b91c1c';
                            $tl_bar_color  = '#dc2626';
                            $tl_badge_icon = '&#9888;';
                        }
                    ?>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:11px 14px; color:#334155;">
                            <strong style="font-family:monospace; color:#002F70; font-size:14px;"><?= htmlspecialchars($tl['tank_assign']) ?></strong>
                            <div style="font-size:11px; color:#64748b; margin-top:2px; font-weight:600;"><?= htmlspecialchars($tl['tank_label']) ?></div>
                        </td>
                        <td style="padding:11px 14px; color:#334155;">
                            <strong><?= htmlspecialchars($tl['fuel_type']) ?></strong>
                        </td>
                        <td style="padding:11px 14px; color:#334155;">
                            <?= number_format($tl_current, 2) ?>
                            <div style="margin-top:5px; height:4px; background:#e2e8f0; border-radius:2px; width:100px;">
                                <div style="height:4px; background:<?= $tl_bar_color ?>; border-radius:2px; width:<?= $tl_pct ?>%;"></div>
                            </div>
                        </td>
                        <td style="padding:11px 14px; color:#334155;"><?= number_format($tl_capacity, 2) ?></td>
                        <td style="padding:11px 14px; color:#334155;"><?= number_format($tl_critical, 2) ?></td>
                        <td style="padding:11px 14px;">
                            <span style="display:inline-block; padding:4px 10px; border-radius:20px; font-size:11px; font-weight:700; background:<?= $tl_badge_bg ?>; color:<?= $tl_badge_color ?>;">
                                <?= $tl_badge_icon ?> <?= htmlspecialchars($tl_status) ?>
                            </span>
                        </td>
                        <td style="padding:11px 14px; color:#64748b; font-size:12px;">
                            <?= isset($tl['last_updated']) && $tl['last_updated'] ? htmlspecialchars(date('M d, Y H:i', strtotime($tl['last_updated']))) : '&mdash;' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Operational Tables Tabbed Container -->
<div class="tables-panel-card">
    <div class="action-panel-content">
        <!-- 1. Recent Transactions -->
        <div id="recent-transactions-pane" class="action-panel-pane active">
            <h3 class="table-section-title"><i class="fas fa-history"></i> Recent Transactions</h3>
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
            <h3 class="table-section-title"><i class="fas fa-tools"></i> Active Service Queue</h3>
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
            <h3 class="table-section-title"><i class="fas fa-exclamation-triangle"></i> Fuel Stock Alerts</h3>
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
            <h3 class="table-section-title"><i class="fas fa-box-open"></i> Merchandise Low Stock</h3>
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
            <h3 class="table-section-title"><i class="fas fa-file-import"></i> Pending Stock Requests</h3>
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

<!-- Quick Actions -->
<div class="quick-actions-panel">
    <h3 class="quick-actions-title"><i class="fas fa-bolt"></i> Quick Actions</h3>
    <div class="quick-actions-row">
        <a href="staff_transactions_hub.php?section=fuel" class="quick-action-button">
            <i class="fas fa-gas-pump"></i>
            <span>New Fuel Transaction</span>
        </a>
        <a href="staff_transactions_hub.php?section=merchandise" class="quick-action-button">
            <i class="fas fa-shopping-cart"></i>
            <span>New Merchandise Transaction</span>
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
            <span>View Transaction History</span>
        </a>
    </div>
</div>

</section>

<!-- Chart Script Logic -->
<script>
    // ── Chart.js Global Defaults ───────────────────────────────────────
    Chart.defaults.font.family = "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif";
    Chart.defaults.font.size   = 12;
    Chart.defaults.color       = '#475569';

    // ── Utility: Generate a palette of N distinct colors ──────────────
    function generatePalette(n) {
        const base = [
            '#3b82f6','#ef4444','#eab308','#8b5cf6','#64748b',
            '#16a34a','#f97316','#06b6d4','#ec4899','#a855f7',
            '#0891b2','#d97706','#14b8a6','#6366f1','#84cc16'
        ];
        const result = [];
        for (let i = 0; i < n; i++) result.push(base[i % base.length]);
        return result;
    }

    // ── Utility: Integer-only Y-axis ticks ────────────────────────────
    const integerTicks = {
        beginAtZero: true,
        ticks: {
            stepSize: 1,
            callback: (v) => Number.isInteger(v) ? v : null
        }
    };

    // ── Utility: Show empty-state placeholder instead of canvas ───────
    function showEmptyState(canvasId, icon, message) {
        const wrapper = document.getElementById(canvasId).parentElement;
        wrapper.innerHTML =
            `<div style="display:flex;flex-direction:column;align-items:center;
                         justify-content:center;height:100%;color:#94a3b8;gap:10px;">
                <i class="fas fa-${icon}" style="font-size:32px;"></i>
                <span style="font-size:13px;font-weight:600;text-align:center;">${message}</span>
             </div>`;
    }

    // ── Pie Chart: Payment Methods Today ─────────────────────────────────────
    (function () {
        const labels = <?= json_encode($pie_pm_labels) ?>;
        const data   = <?= json_encode($pie_pm_data) ?>;
        const total  = data.reduce((a, b) => a + b, 0);

        if (total === 0) {
            showEmptyState('paymentMethodsPieChart', 'credit-card', 'No payment transactions recorded for today');
            return;
        }

        new Chart(document.getElementById('paymentMethodsPieChart').getContext('2d'), {
            type: 'pie',
            data: {
                labels,
                datasets: [{
                    data,
                    backgroundColor: [
                        '#16a34a', // Cash (Green)
                        '#2563eb', // Credit Card (Blue)
                        '#0284c7', // Debit Card (Sky)
                        '#059669', // GCash (Emerald)
                        '#7c3aed', // Maya (Purple)
                        '#ea580c', // Petron Fleet Card (Orange)
                        '#dc2626'  // Credit Account (Red)
                    ],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: { boxWidth: 12, padding: 12 }
                    },
                    tooltip: {
                        callbacks: {
                            label: ctx => ` ${ctx.label}: ₱${Number(ctx.parsed).toLocaleString('en-PH', {minimumFractionDigits: 2})}`
                        }
                    }
                }
            }
        });
    })();

    // ── Bar Chart: Today's Merchandise vs Service Sales ───────────────────────
    (function () {
        const labels = <?= json_encode($bar_merch_vs_service_labels) ?>;
        const data   = <?= json_encode($bar_merch_vs_service_data) ?>;
        const total  = data.reduce((a, b) => a + b, 0);

        if (total === 0) {
            showEmptyState('merchVsServiceBarChart', 'chart-bar', 'No merchandise or service sales recorded today');
            return;
        }

        new Chart(document.getElementById('merchVsServiceBarChart').getContext('2d'), {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Sales (₱)',
                    data,
                    backgroundColor: ['#ea580c', '#0891b2'],
                    borderRadius: 8,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: ctx => ` ₱${Number(ctx.parsed.y).toLocaleString('en-PH', {minimumFractionDigits: 2})}`
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: v => '₱' + Number(v).toLocaleString('en-PH', {minimumFractionDigits: 0})
                        }
                    }
                }
            }
        });
    })();

    // ── Chart 1: Hourly Transactions (Line) ───────────────────────────
    (function () {
        const labels = <?= json_encode($hourly_chart_labels) ?>;
        const data   = <?= json_encode($hourly_chart_data) ?>;
        const shiftLabel = <?= json_encode($hourly_shift_label) ?>;
        const total  = data.reduce((a, b) => a + b, 0);

        if (total === 0) {
            showEmptyState('hourlyTransactionsChart', 'chart-line',
                `No transactions recorded for ${shiftLabel}`);
            return;
        }

        new Chart(document.getElementById('hourlyTransactionsChart').getContext('2d'), {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    label: 'Transactions',
                    data,
                    borderColor: '#002F70',
                    backgroundColor: 'rgba(0,47,112,0.08)',
                    tension: 0.35,
                    fill: true,
                    borderWidth: 2.5,
                    pointBackgroundColor: '#002F70',
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
                            label: ctx => ` ${ctx.parsed.y} transaction${ctx.parsed.y !== 1 ? 's' : ''}`
                        }
                    }
                },
                scales: { y: integerTicks }
            }
        });
    })();

    // ── Chart 2: Fuel Sales by Product (Bar) ──────────────────────────
    (function () {
        const labels = <?= json_encode($fuel_chart_labels) ?>;
        const data   = <?= json_encode($fuel_chart_data) ?>;
        const total  = data.reduce((a, b) => a + b, 0);

        if (total === 0) {
            showEmptyState('fuelSalesChart', 'gas-pump',
                'No fuel transactions for the selected date range');
            return;
        }

        const maxVal = Math.max(...data);
        // Round up to a nice value for the Y axis
        const niceMax = maxVal < 10 ? Math.ceil(maxVal + 1)
                       : maxVal < 100 ? Math.ceil(maxVal / 10) * 10 + 10
                       : Math.ceil(maxVal / 100) * 100 + 100;

        new Chart(document.getElementById('fuelSalesChart').getContext('2d'), {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Liters Sold',
                    data,
                    backgroundColor: generatePalette(labels.length),
                    borderRadius: 6,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: ctx => ` ${Number(ctx.parsed.y).toLocaleString('en-PH', {minimumFractionDigits:2})} L`
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: {
                            maxRotation: 45,
                            minRotation: 0,
                            font: { size: 10 }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        max: niceMax,
                        ticks: {
                            callback: v => Number(v).toLocaleString('en-PH', {minimumFractionDigits:0}) + ' L'
                        }
                    }
                }
            }
        });
    })();

    // ── Chart 3: Merchandise Sales by Category (Bar) ──────────────────
    (function () {
        const labels = <?= json_encode($merch_chart_labels) ?>;
        const data   = <?= json_encode($merch_chart_data) ?>;
        const total  = data.reduce((a, b) => a + b, 0);

        if (total === 0) {
            showEmptyState('merchSalesChart', 'shopping-basket',
                'No merchandise sales for the selected date range');
            return;
        }

        new Chart(document.getElementById('merchSalesChart').getContext('2d'), {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Sales',
                    data,
                    backgroundColor: generatePalette(labels.length),
                    borderRadius: 6,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: ctx => ` ₱${Number(ctx.parsed.y).toLocaleString('en-PH',{minimumFractionDigits:2})}`
                        }
                    }
                },
                scales: {
                    x: { ticks: { maxRotation: 40, font: { size: 10 } } },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: v => '₱' + Number(v).toLocaleString('en-PH',{minimumFractionDigits:0})
                        }
                    }
                }
            }
        });
    })();

    // ── Chart 4: Service Status Distribution (Doughnut) ───────────────
    (function () {
        const labels = <?= json_encode($status_chart_labels) ?>;
        const data   = <?= json_encode($status_chart_data) ?>;
        const total  = data.reduce((a, b) => a + b, 0);
        const statusColors = {
            'Pending Validation': '#eab308',
            'Approved': '#16a34a',
            'In Progress': '#3b82f6',
            'Completed': '#22c55e',
            'Released': '#64748b',
            'Rejected': '#ef4444'
        };

        if (total === 0) {
            showEmptyState('serviceStatusChart', 'tools',
                'No service orders for the selected date range');
            return;
        }

        new Chart(document.getElementById('serviceStatusChart').getContext('2d'), {
            type: 'doughnut',
            data: {
                labels,
                datasets: [{
                    data,
                    backgroundColor: labels.map(label => statusColors[label] || '#94a3b8'),
                    borderWidth: 2,
                    borderColor: '#fff',
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: { boxWidth: 12, padding: 14 }
                    },
                    tooltip: {
                        callbacks: {
                            label: ctx => ` ${ctx.label}: ${ctx.parsed} order${ctx.parsed !== 1 ? 's' : ''}`
                        }
                    }
                }
            }
        });
    })();

    // ── Chart 5: Weekly Transaction Trend (Line) ──────────────────────
    (function () {
        const labels = <?= json_encode($weekly_chart_labels) ?>;
        const data   = <?= json_encode($weekly_chart_data) ?>;
        const total  = data.reduce((a, b) => a + b, 0);

        if (total === 0) {
            showEmptyState('weeklyTrendChart', 'calendar-week',
                'No transactions recorded this week');
            return;
        }

        new Chart(document.getElementById('weeklyTrendChart').getContext('2d'), {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    label: 'Transactions',
                    data,
                    borderColor: '#16a34a',
                    backgroundColor: 'rgba(22,163,74,0.06)',
                    tension: 0.35,
                    fill: true,
                    borderWidth: 2.5,
                    pointBackgroundColor: '#16a34a',
                    pointRadius: 5,
                    pointHoverRadius: 7
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: ctx => ` ${ctx.parsed.y} transaction${ctx.parsed.y !== 1 ? 's' : ''}`
                        }
                    }
                },
                scales: { y: integerTicks }
            }
        });
    })();

    const staffDashboardVersion = <?= json_encode($dashboard_version) ?>;
    let staffDashboardRefreshInFlight = false;
    let staffDashboardLastCheck = 0;

    async function checkStaffDashboardUpdates(force = false) {
        const now = Date.now();
        if (staffDashboardRefreshInFlight) return;
        if (!force && document.hidden) return;
        if (!force && now - staffDashboardLastCheck < 10000) return;

        staffDashboardRefreshInFlight = true;
        staffDashboardLastCheck = now;

        try {
            const url = new URL(window.location.href);
            url.searchParams.set('dashboard_ping', '1');
            url.searchParams.set('_', String(now));

            const response = await fetch(url.toString(), {
                headers: { 'Accept': 'application/json' },
                cache: 'no-store',
                credentials: 'same-origin'
            });
            if (!response.ok) return;

            const payload = await response.json();
            if (payload.success && payload.version && payload.version !== staffDashboardVersion) {
                staffDashboardVersion = payload.version;
                // Refresh metrics via AJAX instead of full page reload
                if (typeof window.refreshLivePageData === 'function') {
                    window.refreshLivePageData(payload);
                }
            }
        } catch (error) {
            // Next poll will retry.
        } finally {
            staffDashboardRefreshInFlight = false;
        }
    }

    setInterval(() => checkStaffDashboardUpdates(false), 30000);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) checkStaffDashboardUpdates(true);
    });

    // ── Live real-time clock for the Current Shift card ──────────────────────
    (function dashClock() {
        const el = document.getElementById('dashLiveClock');
        if (!el) return;
        function tick() {
            const now = new Date();
            let h = now.getHours();
            const m = String(now.getMinutes()).padStart(2, '0');
            const s = String(now.getSeconds()).padStart(2, '0');
            const ampm = h >= 12 ? 'PM' : 'AM';
            h = h % 12 || 12;
            el.textContent = `${String(h).padStart(2, '0')}:${m}:${s} ${ampm}`;
        }
        tick();
        setInterval(tick, 1000);
    })();
</script>

<!-- ── Live Sync Engine ─ loaded globally by footer.php ────────────────────── -->
<script>
(function () {
    'use strict';

    // Base URL for live_sync AJAX endpoint on this page (preserves active date filters)
    function getLiveSyncUrl() {
        const url = new URL(window.location.href);
        url.searchParams.set('live_sync', '1');
        url.searchParams.set('_', String(Date.now()));
        return url.toString();
    }

    let _lastVersion = <?= json_encode($dashboard_version ?? '') ?>;
    let _inFlight    = false;
    let _lastMetrics = {};

    // Animate number change on a card
    function flashCard(el) {
        if (!el) return;
        el.style.transition = 'color 0.25s';
        el.style.color = '#002F70';
        setTimeout(() => { el.style.color = ''; }, 600);
    }

    // Update a single metric card value element
    function updateMetricEl(key, newValue) {
        const el = document.querySelector(`[data-live-metric="${key}"]`);
        if (!el) return;
        if (el.textContent.trim() !== String(newValue).trim()) {
            el.textContent = newValue;
            flashCard(el);
        }
    }

    // Called by live_sync.js engine on every successful poll
    window.refreshLivePageData = async function (syncEngineData) {
        if (_inFlight) return;
        _inFlight = true;
        try {
            const ctrl = new AbortController();
            const tid  = setTimeout(() => ctrl.abort(), 6000);
            const res  = await fetch(getLiveSyncUrl(), {
                signal: ctrl.signal,
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Accept': 'application/json' }
            });
            clearTimeout(tid);
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            if (!data.success) throw new Error('API error');

            // Check if data changed (version or metrics differ)
            if (data.version && data.version !== _lastVersion) {
                _lastVersion = data.version;
                // Could trigger a heavier refresh of tables/charts here if desired
            }

            // Update all summary card metric values
            const m = data.metrics || {};
            Object.keys(m).forEach(key => {
                if (_lastMetrics[key] !== m[key]) {
                    updateMetricEl(key, m[key]);
                    _lastMetrics[key] = m[key];
                }
            });

        } catch (e) {
            // Silent - next poll will retry
        } finally {
            _inFlight = false;
        }
    };
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
