<?php
/**
 * Admin Dashboard
 *
 * DB-driven station overview for revenue, transactions, users, approvals,
 * inventory, deliveries, audit status, backup status, and quick actions.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$page_id = 'admin_dashboard';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();
$user_id = (int) ($me['id'] ?? ($_SESSION['user_id'] ?? 0));

if (!in_array($role, ['admin', 'superadmin', 'developer'], true)) {
    header('Location: dashboard.php');
    exit;
}

if (!$station_id && $role === 'admin') {
    render_no_station_page('admin_dashboard.php');
}

$date_filter = $_GET['date'] ?? $_POST['date'] ?? date('Y-m-d');
$date_check = DateTimeImmutable::createFromFormat('Y-m-d', (string) $date_filter);
if (!$date_check || $date_check->format('Y-m-d') !== $date_filter) {
    $date_filter = date('Y-m-d');
    $date_check = new DateTimeImmutable($date_filter);
}

function adm_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function adm_money($value): string
{
    return '&#8369;' . number_format((float) $value, 2);
}

function adm_qty($value, int $decimals = 2): string
{
    return number_format((float) $value, $decimals);
}

function adm_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        $cache[$table] = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $cache[$table] = false;
    }

    return $cache[$table];
}

function adm_value(PDO $pdo, string $sql, array $params = [], $default = 0)
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? $default : $value;
    } catch (Throwable $e) {
        error_log('[admin_dashboard] value query failed: ' . $e->getMessage() . ' | ' . $sql);
        return $default;
    }
}

function adm_rows(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[admin_dashboard] rows query failed: ' . $e->getMessage() . ' | ' . $sql);
        return [];
    }
}

function adm_station_clause(int $station_id, string $alias = ''): string
{
    if (!$station_id) {
        return '1=1';
    }

    $prefix = $alias !== '' ? $alias . '.' : '';
    return $prefix . 'station_id = ?';
}

function adm_station_params(int $station_id): array
{
    return $station_id ? [$station_id] : [];
}

function adm_user_name_expr(string $alias = 'u'): string
{
    $p = $alias !== '' ? $alias . '.' : '';
    return "COALESCE(NULLIF(TRIM(CONCAT(COALESCE({$p}first_name, ''), ' ', COALESCE({$p}last_name, ''))), ''), {$p}username, CONCAT('User #', {$p}id))";
}

function adm_badge_class($status): string
{
    $s = strtolower(trim((string) $status));
    if ($s === '' || str_contains($s, 'pending') || str_contains($s, 'await') || str_contains($s, 'warning')) {
        return 'warning';
    }
    if (str_contains($s, 'approved') || str_contains($s, 'active') || str_contains($s, 'completed') || str_contains($s, 'connected') || str_contains($s, 'success') || str_contains($s, 'normal') || str_contains($s, 'ok')) {
        return 'success';
    }
    if (str_contains($s, 'reject') || str_contains($s, 'cancel') || str_contains($s, 'failed') || str_contains($s, 'error') || str_contains($s, 'critical') || str_contains($s, 'inactive') || str_contains($s, 'disabled')) {
        return 'danger';
    }
    if (str_contains($s, 'progress') || str_contains($s, 'review') || str_contains($s, 'ready') || str_contains($s, 'stock')) {
        return 'info';
    }
    if (str_contains($s, 'low') || str_contains($s, 'short') || str_contains($s, 'locked')) {
        return 'warning';
    }
    return 'neutral';
}

function adm_status_label($status, string $fallback = 'Pending'): string
{
    $status = trim((string) $status);
    return $status !== '' ? $status : $fallback;
}

function adm_format_time($value): string
{
    if (!$value) {
        return 'N/A';
    }

    $ts = strtotime((string) $value);
    return $ts ? date('M d, h:i A', $ts) : (string) $value;
}

function adm_bytes($bytes): string
{
    $bytes = max(0, (float) $bytes);
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $index = 0;
    while ($bytes >= 1024 && $index < count($units) - 1) {
        $bytes /= 1024;
        $index++;
    }

    return number_format($bytes, $index === 0 ? 0 : 2) . ' ' . $units[$index];
}

function adm_merch_bucket(string $category, string $product): string
{
    $text = strtolower(trim($category . ' ' . $product));
    if (str_contains($text, 'engine oil') || (str_contains($text, 'engine') && str_contains($text, 'oil'))) {
        return 'Engine Oil';
    }
    if (str_contains($text, 'drink') || str_contains($text, 'beverage') || str_contains($text, 'water') || str_contains($text, 'soda')) {
        return 'Drinks';
    }
    if (str_contains($text, 'snack') || str_contains($text, 'chips') || str_contains($text, 'biscuit') || str_contains($text, 'food')) {
        return 'Snacks';
    }
    if (str_contains($text, 'accessor') || str_contains($text, 'filter') || str_contains($text, 'car care')) {
        return 'Accessories';
    }
    if (str_contains($text, 'lub') || str_contains($text, 'grease') || str_contains($text, 'oil')) {
        return 'Lubricants';
    }

    return 'Accessories';
}

function adm_merge_by_ref(array $rows): array
{
    $seen = [];
    $unique = [];
    foreach ($rows as $row) {
        $key = strtolower(($row['type'] ?? '') . '|' . ($row['ref_no'] ?? ''));
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $unique[] = $row;
    }
    return $unique;
}

function adm_sort_created_desc(array &$rows): void
{
    usort($rows, static function ($a, $b) {
        return strtotime((string) ($b['created_at'] ?? $b['time_sort'] ?? '')) <=> strtotime((string) ($a['created_at'] ?? $a['time_sort'] ?? ''));
    });
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['admin_dashboard_action'] ?? '') === 'run_backup') {
    try {
        require_once __DIR__ . '/../backend/database_management.php';
        $backup_result = DatabaseManagement::createBackup('admin_manual');

        if (empty($backup_result['success'])) {
            throw new RuntimeException($backup_result['error'] ?? 'Backup failed.');
        }

        $backup_file = (string) ($backup_result['filepath'] ?? '');
        $backup_size = ($backup_file !== '' && is_file($backup_file)) ? (int) filesize($backup_file) : 0;
        $backup_name = (string) ($backup_result['filename'] ?? basename($backup_file));

        if (adm_table_exists($pdo, 'system_backups')) {
            $stmt = $pdo->prepare(
                'INSERT INTO system_backups
                 (backup_name, backup_type, file_path, file_size, status, created_by, created_at, completed_at, backup_path)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)'
            );
            $stmt->execute([
                $backup_name,
                'database',
                $backup_file,
                $backup_size,
                'completed',
                $user_id ?: null,
                $backup_file,
            ]);
        }

        $_SESSION['success'] = 'Database backup completed: ' . $backup_name;
    } catch (Throwable $e) {
        if (adm_table_exists($pdo, 'system_backups')) {
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO system_backups
                     (backup_name, backup_type, status, created_by, created_at, completed_at, error_message)
                     VALUES (?, ?, ?, ?, NOW(), NOW(), ?)'
                );
                $stmt->execute([
                    'admin_manual_' . date('Ymd_His'),
                    'database',
                    'failed',
                    $user_id ?: null,
                    $e->getMessage(),
                ]);
            } catch (Throwable $ignored) {
            }
        }

        $_SESSION['error'] = 'Backup failed: ' . $e->getMessage();
    }

    header('Location: admin_dashboard.php?' . http_build_query(['date' => $date_filter]));
    exit;
}

$flash_success = $_SESSION['success'] ?? null;
$flash_error = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

$display_name = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''));
if ($display_name === '') {
    $display_name = $me['full_name'] ?? $me['name'] ?? $me['username'] ?? 'Admin';
}

$station_label = $me['station_name'] ?? '';
if ($station_label === '' && $station_id) {
    $station_label = (string) adm_value($pdo, 'SELECT name FROM stations WHERE id = ?', [$station_id], 'Station #' . $station_id);
}
if ($station_label === '') {
    $station_label = 'All Stations';
}

$station_sql = adm_station_clause($station_id);
$station_params = adm_station_params($station_id);
$station_sql_mt = adm_station_clause($station_id, 'mt');
$station_params_mt = adm_station_params($station_id);

// Summary metrics.
$fuel_count = adm_table_exists($pdo, 'fuel_transactions')
    ? (int) adm_value($pdo, "SELECT COUNT(*) FROM fuel_transactions WHERE {$station_sql} AND DATE(transaction_date) = ?", array_merge($station_params, [$date_filter]))
    : 0;

$merch_count = adm_table_exists($pdo, 'merchandise_transactions')
    ? (int) adm_value($pdo, "SELECT COUNT(*) FROM merchandise_transactions WHERE {$station_sql} AND DATE(COALESCE(transaction_date, created_at)) = ?", array_merge($station_params, [$date_filter]))
    : 0;

$service_count = adm_table_exists($pdo, 'job_orders')
    ? (int) adm_value($pdo, "SELECT COUNT(*) FROM job_orders WHERE {$station_sql} AND DATE(created_at) = ?", array_merge($station_params, [$date_filter]))
    : 0;

$total_transactions = $fuel_count + $merch_count + $service_count;

$fuel_revenue = adm_table_exists($pdo, 'fuel_transactions')
    ? (float) adm_value($pdo, "SELECT COALESCE(SUM(total_amount), 0) FROM fuel_transactions WHERE {$station_sql} AND DATE(transaction_date) = ?", array_merge($station_params, [$date_filter]))
    : 0.0;

$merch_revenue = adm_table_exists($pdo, 'merchandise_transactions')
    ? (float) adm_value($pdo, "SELECT COALESCE(SUM(total_amount), 0) FROM merchandise_transactions WHERE {$station_sql} AND DATE(COALESCE(transaction_date, created_at)) = ?", array_merge($station_params, [$date_filter]))
    : 0.0;

$service_revenue = adm_table_exists($pdo, 'job_orders')
    ? (float) adm_value(
        $pdo,
        "SELECT COALESCE(SUM(COALESCE(total_cost, estimated_cost, COALESCE(actual_labor_cost, 0) + COALESCE(actual_parts_cost, 0), 0)), 0)
         FROM job_orders
         WHERE {$station_sql}
           AND DATE(created_at) = ?
           AND LOWER(COALESCE(status, '')) IN ('completed', 'verified', 'finalized', 'released')",
        array_merge($station_params, [$date_filter])
    )
    : 0.0;

$total_revenue = $fuel_revenue + $merch_revenue + $service_revenue;

$active_admins = adm_table_exists($pdo, 'users')
    ? (int) adm_value($pdo, "SELECT COUNT(*) FROM users WHERE {$station_sql} AND status = 'Active' AND LOWER(role) = 'admin'", $station_params)
    : 0;
$active_managers = adm_table_exists($pdo, 'users')
    ? (int) adm_value($pdo, "SELECT COUNT(*) FROM users WHERE {$station_sql} AND status = 'Active' AND LOWER(role) = 'manager'", $station_params)
    : 0;
$active_staff = adm_table_exists($pdo, 'users')
    ? (int) adm_value($pdo, "SELECT COUNT(*) FROM users WHERE {$station_sql} AND status = 'Active' AND LOWER(role) = 'staff'", $station_params)
    : 0;
$total_active_users = $active_admins + $active_managers + $active_staff;

$pending_user_accounts = adm_table_exists($pdo, 'users')
    ? (int) adm_value($pdo, "SELECT COUNT(*) FROM users WHERE {$station_sql} AND LOWER(COALESCE(status, '')) = 'pending'", $station_params)
    : 0;

$pending_customer_requests = adm_table_exists($pdo, 'customers')
    ? (int) adm_value(
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

$pending_purchase_orders = adm_table_exists($pdo, 'purchase_orders')
    ? (int) adm_value(
        $pdo,
        "SELECT COUNT(*) FROM purchase_orders
         WHERE {$station_sql}
           AND (
                LOWER(COALESCE(status, '')) IN ('pending', 'pending approval', 'pending admin validation')
                OR (admin_finalized = 0 AND LOWER(COALESCE(status, '')) NOT IN ('draft', 'rejected', 'cancelled', 'received'))
           )",
        $station_params
    )
    : 0;

$pending_fuel_purchase_orders = adm_table_exists($pdo, 'fuel_purchase_orders')
    ? (int) adm_value(
        $pdo,
        "SELECT COUNT(*) FROM fuel_purchase_orders
         WHERE {$station_sql}
           AND LOWER(COALESCE(status, '')) IN ('pending', 'pending approval', 'pending admin validation')",
        $station_params
    )
    : 0;

$pending_price_requests = adm_table_exists($pdo, 'pending_price_approvals')
    ? (int) adm_value($pdo, "SELECT COUNT(*) FROM pending_price_approvals WHERE {$station_sql} AND LOWER(status) = 'pending'", $station_params)
    : 0;

$pending_inventory_approvals = $pending_purchase_orders + $pending_fuel_purchase_orders;
$total_pending_approvals = $pending_user_accounts + $pending_customer_requests + $pending_inventory_approvals + $pending_price_requests;

$fuel_total_count = 0;
$fuel_critical_count = 0;
$fuel_low_count = 0;
if (adm_table_exists($pdo, 'fuel_inventory')) {
    $fuel_inv_rows = adm_rows($pdo, "SELECT current_level, current_stock, capacity FROM fuel_inventory WHERE {$station_sql}", $station_params);
    $fuel_total_count = count($fuel_inv_rows);
    foreach ($fuel_inv_rows as $fi_row) {
        $capacity = (float)($fi_row['capacity'] ?? 0);
        $level = min(max(0, (float)($fi_row['current_level'] ?? $fi_row['current_stock'] ?? 0)), $capacity);
        if ($capacity == 14000)    { $critical_lvl = 5000; $low_lvl = 7000; }
        elseif ($capacity == 7000) { $critical_lvl = 1000; $low_lvl = 2000; }
        else                       { $critical_lvl = $capacity * 0.10; $low_lvl = $capacity * 0.20; }
        if ($level <= $critical_lvl) {
            $fuel_critical_count++;
        } elseif ($level <= $low_lvl) {
            $fuel_low_count++;
        }
    }
}
$fuel_normal_count = max(0, $fuel_total_count - $fuel_low_count - $fuel_critical_count);

$merch_total_count = adm_table_exists($pdo, 'station_inventory')
    ? (int) adm_value($pdo, "SELECT COUNT(*) FROM station_inventory WHERE {$station_sql} AND status = 'active'", $station_params)
    : 0;
$merch_critical_count = adm_table_exists($pdo, 'station_inventory')
    ? (int) adm_value($pdo, "SELECT COUNT(*) FROM station_inventory WHERE {$station_sql} AND status = 'active' AND COALESCE(stock_level, 0) <= 0", $station_params)
    : 0;
$merch_low_count = adm_table_exists($pdo, 'station_inventory')
    ? (int) adm_value(
        $pdo,
        "SELECT COUNT(*) FROM station_inventory
         WHERE {$station_sql}
           AND status = 'active'
           AND COALESCE(stock_level, 0) > 0
           AND COALESCE(stock_level, 0) <= COALESCE(reorder_level, 0)",
        $station_params
    )
    : 0;
$merch_normal_count = max(0, $merch_total_count - $merch_low_count - $merch_critical_count);
$total_inventory_alerts = $fuel_low_count + $fuel_critical_count + $merch_low_count + $merch_critical_count;

// Deliveries are merged by visible reference so duplicated oversight/PO rows are not counted twice.
$delivery_rows = [];
if (adm_table_exists($pdo, 'deliveries_oversight')) {
    $delivery_rows = array_merge($delivery_rows, adm_rows(
        $pdo,
        "SELECT id,
                delivery_type AS type,
                COALESCE(NULLIF(delivery_ref, ''), CONCAT('DO-', id)) AS ref_no,
                supplier,
                product,
                quantity,
                unit,
                status,
                delivery_date,
                created_at,
                CASE WHEN delivery_type = 'fuel' THEN 'admin_fuel_deliveries_oversight.php' ELSE 'admin_merchandise_deliveries_oversight.php' END AS action_url,
                'oversight' AS source
         FROM deliveries_oversight
         WHERE {$station_sql}
           AND LOWER(COALESCE(status, '')) IN ('pending manager approval', 'pending validation', 'pending verification', 'pending manager confirmation', 'pending admin oversight', 'discrepancy', 'flagged')
         ORDER BY created_at DESC
         LIMIT 12",
        $station_params
    ));
}
if (adm_table_exists($pdo, 'purchase_orders')) {
    $delivery_rows = array_merge($delivery_rows, adm_rows(
        $pdo,
        "SELECT id,
                COALESCE(NULLIF(type, ''), 'merchandise') AS type,
                po_number AS ref_no,
                COALESCE(product_name, 'Merchandise Products') AS product,
                quantity,
                'pcs' AS unit,
                COALESCE(status, 'Pending') AS status,
                expected_delivery_date AS delivery_date,
                created_at,
                'admin_purchase_orders.php' AS action_url,
                'purchase_order' AS source
         FROM purchase_orders
         WHERE {$station_sql}
           AND admin_finalized = 1
           AND delivery_validated = 0
           AND stock_in_done = 0
           AND LOWER(COALESCE(status, '')) NOT IN ('cancelled', 'rejected')
         ORDER BY created_at DESC
         LIMIT 12",
        $station_params
    ));
}
if (adm_table_exists($pdo, 'fuel_purchase_orders')) {
    $delivery_rows = array_merge($delivery_rows, adm_rows(
        $pdo,
        "SELECT fpo.id,
                'fuel' AS type,
                fpo.po_number AS ref_no,
                COALESCE(ft.name, CONCAT('Fuel Type #', fpo.fuel_type_id)) AS product,
                fpo.volume AS quantity,
                'L' AS unit,
                COALESCE(fpo.status, 'Pending') AS status,
                fpo.expected_delivery_date AS delivery_date,
                fpo.created_at,
                'admin_fuel_deliveries_oversight.php' AS action_url,
                'fuel_purchase_order' AS source
         FROM fuel_purchase_orders fpo
         LEFT JOIN fuel_types ft ON ft.id = fpo.fuel_type_id
         WHERE " . adm_station_clause($station_id, 'fpo') . "
           AND LOWER(COALESCE(fpo.status, '')) IN ('approved', 'approved po', 'admin finalized', 'official', 'confirmed')
           AND fpo.delivery_date IS NULL
         ORDER BY fpo.created_at DESC
         LIMIT 12",
        adm_station_params($station_id)
    ));
}
$delivery_rows = adm_merge_by_ref($delivery_rows);
adm_sort_created_desc($delivery_rows);
$pending_deliveries_count = count($delivery_rows);
$delivery_rows = array_slice($delivery_rows, 0, 8);

// System health and monitoring.
$db_connected = true;
try {
    $pdo->query('SELECT 1');
} catch (Throwable $e) {
    $db_connected = false;
}
$server_running = true;

$latest_backup = adm_table_exists($pdo, 'system_backups')
    ? adm_rows($pdo, 'SELECT * FROM system_backups ORDER BY COALESCE(completed_at, created_at) DESC, id DESC LIMIT 1')
    : [];
$latest_backup = $latest_backup[0] ?? null;
$backup_ok = $latest_backup && strtolower((string) ($latest_backup['status'] ?? '')) === 'completed';
$backup_label = $latest_backup ? adm_status_label($latest_backup['status'], 'Unknown') : 'No Backup';
$system_health_ok = $db_connected && $server_running && $backup_ok;
$system_health_value = $system_health_ok ? 'OK' : ($db_connected && $server_running ? 'Review' : 'Issue');

$audit_today_logs = adm_table_exists($pdo, 'audit_logs')
    ? (int) adm_value($pdo, 'SELECT COUNT(*) FROM audit_logs WHERE DATE(created_at) = ?', [$date_filter])
    : 0;
$audit_errors = adm_table_exists($pdo, 'audit_logs')
    ? (int) adm_value(
        $pdo,
        "SELECT COUNT(*) FROM audit_logs
         WHERE DATE(created_at) = ?
           AND (LOWER(COALESCE(status, '')) IN ('error', 'failed') OR error_message IS NOT NULL)",
        [$date_filter]
    )
    : 0;
$audit_warnings = adm_table_exists($pdo, 'audit_logs')
    ? (int) adm_value(
        $pdo,
        "SELECT COUNT(*) FROM audit_logs
         WHERE DATE(created_at) = ?
           AND (LOWER(COALESCE(status, '')) = 'warning' OR LOWER(COALESCE(log_type, '')) = 'warning')",
        [$date_filter]
    )
    : 0;
$login_successes = adm_table_exists($pdo, 'login_attempts')
    ? (int) adm_value($pdo, "SELECT COUNT(*) FROM login_attempts WHERE DATE(attempt_time) = ? AND LOWER(status) = 'success'", [$date_filter])
    : 0;
$login_failures = adm_table_exists($pdo, 'login_attempts')
    ? (int) adm_value($pdo, "SELECT COUNT(*) FROM login_attempts WHERE DATE(attempt_time) = ? AND LOWER(status) IN ('failed', 'locked', 'blocked')", [$date_filter])
    : 0;

$db_stats = ['storage_bytes' => 0, 'records' => 0];
try {
    $stmt = $pdo->query(
        'SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) AS storage_bytes,
                COALESCE(SUM(TABLE_ROWS), 0) AS records
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()'
    );
    $db_stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: $db_stats;
} catch (Throwable $e) {
}

$next_backup = adm_table_exists($pdo, 'system_settings')
    ? (string) adm_value(
        $pdo,
        "SELECT setting_value FROM system_settings
         WHERE setting_key IN ('next_backup_at', 'backup_schedule', 'database_backup_schedule')
         ORDER BY FIELD(setting_key, 'next_backup_at', 'backup_schedule', 'database_backup_schedule')
         LIMIT 1",
        [],
        ''
    )
    : '';
if ($next_backup === '') {
    $next_backup = 'Manual';
}

// Chart data.
$month_labels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$monthly_revenue = [];
$selected_year = (int) substr($date_filter, 0, 4);
for ($month = 1; $month <= 12; $month++) {
    $start = sprintf('%04d-%02d-01', $selected_year, $month);
    $end = date('Y-m-t', strtotime($start));
    $fuel_month = adm_table_exists($pdo, 'fuel_transactions')
        ? (float) adm_value($pdo, "SELECT COALESCE(SUM(total_amount), 0) FROM fuel_transactions WHERE {$station_sql} AND DATE(transaction_date) BETWEEN ? AND ?", array_merge($station_params, [$start, $end]))
        : 0.0;
    $merch_month = adm_table_exists($pdo, 'merchandise_transactions')
        ? (float) adm_value($pdo, "SELECT COALESCE(SUM(total_amount), 0) FROM merchandise_transactions WHERE {$station_sql} AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ?", array_merge($station_params, [$start, $end]))
        : 0.0;
    $service_month = adm_table_exists($pdo, 'job_orders')
        ? (float) adm_value(
            $pdo,
            "SELECT COALESCE(SUM(COALESCE(total_cost, estimated_cost, COALESCE(actual_labor_cost, 0) + COALESCE(actual_parts_cost, 0), 0)), 0)
             FROM job_orders
             WHERE {$station_sql}
               AND DATE(created_at) BETWEEN ? AND ?
               AND LOWER(COALESCE(status, '')) IN ('completed', 'verified', 'finalized', 'released')",
            array_merge($station_params, [$start, $end])
        )
        : 0.0;
    $monthly_revenue[] = round($fuel_month + $merch_month + $service_month, 2);
}

$week_start = $date_check->modify('monday this week');
$weekly_labels = [];
$weekly_sales = [];
for ($i = 0; $i < 7; $i++) {
    $day = $week_start->modify("+{$i} days");
    $day_value = $day->format('Y-m-d');
    $weekly_labels[] = $day->format('D');
    $fuel_day = adm_table_exists($pdo, 'fuel_transactions')
        ? (float) adm_value($pdo, "SELECT COALESCE(SUM(total_amount), 0) FROM fuel_transactions WHERE {$station_sql} AND DATE(transaction_date) = ?", array_merge($station_params, [$day_value]))
        : 0.0;
    $merch_day = adm_table_exists($pdo, 'merchandise_transactions')
        ? (float) adm_value($pdo, "SELECT COALESCE(SUM(total_amount), 0) FROM merchandise_transactions WHERE {$station_sql} AND DATE(COALESCE(transaction_date, created_at)) = ?", array_merge($station_params, [$day_value]))
        : 0.0;
    $service_day = adm_table_exists($pdo, 'job_orders')
        ? (float) adm_value(
            $pdo,
            "SELECT COALESCE(SUM(COALESCE(total_cost, estimated_cost, COALESCE(actual_labor_cost, 0) + COALESCE(actual_parts_cost, 0), 0)), 0)
             FROM job_orders
             WHERE {$station_sql}
               AND DATE(created_at) = ?
               AND LOWER(COALESCE(status, '')) IN ('completed', 'verified', 'finalized', 'released')",
            array_merge($station_params, [$day_value])
        )
        : 0.0;
    $weekly_sales[] = round($fuel_day + $merch_day + $service_day, 2);
}

$fuel_product_labels = ['Diesel', 'XCS', 'Turbo Diesel', 'XTRA', 'Kerosene'];
$fuel_sales_by_product = [];
$fuel_rules = [
    ["LOWER(fuel_type) LIKE ? AND LOWER(fuel_type) NOT LIKE ?", ['%diesel%', '%turbo%']],
    ["LOWER(fuel_type) LIKE ?", ['%xcs%']],
    ["LOWER(fuel_type) LIKE ? AND LOWER(fuel_type) LIKE ?", ['%turbo%', '%diesel%']],
    ["(LOWER(fuel_type) LIKE ? OR LOWER(fuel_type) LIKE ?)", ['%xtra%', '%unleaded%']],
    ["LOWER(fuel_type) LIKE ?", ['%kerosene%']],
];
foreach ($fuel_rules as [$condition, $condition_params]) {
    $fuel_sales_by_product[] = adm_table_exists($pdo, 'fuel_transactions')
        ? (float) adm_value(
            $pdo,
            "SELECT COALESCE(SUM(liters_sold), 0)
             FROM fuel_transactions
             WHERE {$station_sql}
               AND DATE(transaction_date) = ?
               AND {$condition}",
            array_merge($station_params, [$date_filter], $condition_params)
        )
        : 0.0;
}

$merch_category_labels = ['Lubricants', 'Drinks', 'Snacks', 'Accessories', 'Engine Oil'];
$merch_sales_by_category = array_fill_keys($merch_category_labels, 0.0);
if (adm_table_exists($pdo, 'merchandise_transaction_items') && adm_table_exists($pdo, 'merchandise_transactions')) {
    $category_rows = adm_rows(
        $pdo,
        "SELECT COALESCE(mti.category, '') AS category,
                COALESCE(mti.product_name, '') AS product_name,
                COALESCE(SUM(mti.subtotal), 0) AS total
         FROM merchandise_transaction_items mti
         INNER JOIN merchandise_transactions mt ON mt.id = mti.transaction_id
         WHERE {$station_sql_mt}
           AND DATE(COALESCE(mt.transaction_date, mt.created_at)) = ?
         GROUP BY COALESCE(mti.category, ''), COALESCE(mti.product_name, '')",
        array_merge($station_params_mt, [$date_filter])
    );
    foreach ($category_rows as $row) {
        $bucket = adm_merch_bucket((string) ($row['category'] ?? ''), (string) ($row['product_name'] ?? ''));
        $merch_sales_by_category[$bucket] += (float) ($row['total'] ?? 0);
    }
}

$user_activity_counts = [];
if (adm_table_exists($pdo, 'fuel_transactions')) {
    foreach (adm_rows(
        $pdo,
        "SELECT staff_id AS user_id, COUNT(*) AS tx_count
         FROM fuel_transactions
         WHERE {$station_sql}
           AND DATE(transaction_date) = ?
           AND staff_id IS NOT NULL
         GROUP BY staff_id",
        array_merge($station_params, [$date_filter])
    ) as $row) {
        $id = (int) ($row['user_id'] ?? 0);
        if ($id > 0) {
            $user_activity_counts[$id] = ($user_activity_counts[$id] ?? 0) + (int) ($row['tx_count'] ?? 0);
        }
    }
}
if (adm_table_exists($pdo, 'merchandise_transactions')) {
    foreach (adm_rows(
        $pdo,
        "SELECT staff_id AS user_id, COUNT(*) AS tx_count
         FROM merchandise_transactions
         WHERE {$station_sql}
           AND DATE(COALESCE(transaction_date, created_at)) = ?
           AND staff_id IS NOT NULL
         GROUP BY staff_id",
        array_merge($station_params, [$date_filter])
    ) as $row) {
        $id = (int) ($row['user_id'] ?? 0);
        if ($id > 0) {
            $user_activity_counts[$id] = ($user_activity_counts[$id] ?? 0) + (int) ($row['tx_count'] ?? 0);
        }
    }
}
if (adm_table_exists($pdo, 'job_orders')) {
    foreach (adm_rows(
        $pdo,
        "SELECT COALESCE(NULLIF(created_by, 0), user_id) AS user_id, COUNT(*) AS tx_count
         FROM job_orders
         WHERE {$station_sql}
           AND DATE(created_at) = ?
           AND COALESCE(NULLIF(created_by, 0), user_id) IS NOT NULL
         GROUP BY COALESCE(NULLIF(created_by, 0), user_id)",
        array_merge($station_params, [$date_filter])
    ) as $row) {
        $id = (int) ($row['user_id'] ?? 0);
        if ($id > 0) {
            $user_activity_counts[$id] = ($user_activity_counts[$id] ?? 0) + (int) ($row['tx_count'] ?? 0);
        }
    }
}
arsort($user_activity_counts);
$user_activity_counts = array_slice($user_activity_counts, 0, 6, true);
$user_activity_labels = [];
$user_activity_data = [];
if ($user_activity_counts && adm_table_exists($pdo, 'users')) {
    $ids = array_keys($user_activity_counts);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $name_expr = adm_user_name_expr('u');
    $name_rows = adm_rows($pdo, "SELECT id, {$name_expr} AS display_name FROM users u WHERE id IN ({$placeholders})", $ids);
    $name_map = [];
    foreach ($name_rows as $row) {
        $name_map[(int) $row['id']] = (string) $row['display_name'];
    }
    foreach ($user_activity_counts as $id => $count) {
        $user_activity_labels[] = $name_map[(int) $id] ?? ('User #' . $id);
        $user_activity_data[] = $count;
    }
}

// Management panel data.
$pending_users_data = adm_table_exists($pdo, 'users')
    ? adm_rows(
        $pdo,
        "SELECT id,
                employee_id,
                " . adm_user_name_expr('u') . " AS employee,
                role,
                status
         FROM users u
         WHERE " . adm_station_clause($station_id, 'u') . "
           AND LOWER(COALESCE(status, '')) = 'pending'
         ORDER BY created_at DESC
         LIMIT 8",
        adm_station_params($station_id)
    )
    : [];

$recent_user_activities = adm_table_exists($pdo, 'audit_logs')
    ? adm_rows(
        $pdo,
        "SELECT COALESCE(" . adm_user_name_expr('u') . ", 'System') AS user_name,
                COALESCE(al.entity_type, al.log_type, 'System') AS module,
                COALESCE(al.action_type, 'Activity') AS action,
                al.status,
                al.created_at
         FROM audit_logs al
         LEFT JOIN users u ON u.id = al.user_id
         WHERE (" . adm_station_clause($station_id, 'u') . " OR al.user_id IS NULL)
         ORDER BY al.created_at DESC
         LIMIT 10",
        adm_station_params($station_id)
    )
    : [];

$pending_inventory_adjustments = [];
if (adm_table_exists($pdo, 'purchase_orders')) {
    $pending_inventory_adjustments = array_merge($pending_inventory_adjustments, adm_rows(
        $pdo,
        "SELECT po.id,
                po.po_number AS ref_no,
                COALESCE(po.product_name, 'Merchandise Products') AS product,
                COALESCE(" . adm_user_name_expr('u') . ", 'Manager') AS requested_by,
                po.status,
                po.created_at,
                'Merchandise' AS type,
                'admin_purchase_orders.php' AS action_url
         FROM purchase_orders po
         LEFT JOIN users u ON u.id = po.created_by
         WHERE " . adm_station_clause($station_id, 'po') . "
           AND (
                LOWER(COALESCE(po.status, '')) IN ('pending', 'pending approval', 'pending admin validation')
                OR (po.admin_finalized = 0 AND LOWER(COALESCE(po.status, '')) NOT IN ('draft', 'rejected', 'cancelled', 'received'))
           )
         ORDER BY po.created_at DESC
         LIMIT 8",
        adm_station_params($station_id)
    ));
}
if (adm_table_exists($pdo, 'fuel_purchase_orders')) {
    $pending_inventory_adjustments = array_merge($pending_inventory_adjustments, adm_rows(
        $pdo,
        "SELECT fpo.id,
                fpo.po_number AS ref_no,
                COALESCE(ft.name, CONCAT('Fuel Type #', fpo.fuel_type_id)) AS product,
                COALESCE(" . adm_user_name_expr('u') . ", 'Manager') AS requested_by,
                fpo.status,
                fpo.created_at,
                'Fuel' AS type,
                'admin_purchase_orders.php' AS action_url
         FROM fuel_purchase_orders fpo
         LEFT JOIN fuel_types ft ON ft.id = fpo.fuel_type_id
         LEFT JOIN users u ON u.id = fpo.created_by
         WHERE " . adm_station_clause($station_id, 'fpo') . "
           AND LOWER(COALESCE(fpo.status, '')) IN ('pending', 'pending approval', 'pending admin validation')
         ORDER BY fpo.created_at DESC
         LIMIT 8",
        adm_station_params($station_id)
    ));
}
adm_sort_created_desc($pending_inventory_adjustments);
$pending_inventory_adjustments = array_slice($pending_inventory_adjustments, 0, 8);

$pending_customers = adm_table_exists($pdo, 'customers')
    ? adm_rows(
        $pdo,
        "SELECT c.id,
                c.name AS customer,
                COALESCE(NULLIF(c.contact_number, ''), NULLIF(c.phone, ''), NULLIF(c.email, ''), 'N/A') AS contact,
                COALESCE(" . adm_user_name_expr('u') . ", c.contact_person, 'Customer Form') AS requested_by,
                COALESCE(c.verification_status, c.mgr_status, 'Pending') AS status,
                c.created_at
         FROM customers c
         LEFT JOIN users u ON u.id = c.mgr_reviewed_by
         WHERE " . adm_station_clause($station_id, 'c') . "
           AND LOWER(COALESCE(c.status, 'active')) <> 'inactive'
           AND (
                LOWER(COALESCE(c.verification_status, '')) = 'pending'
                OR LOWER(COALESCE(c.mgr_status, '')) = 'pending'
           )
         ORDER BY c.created_at DESC
         LIMIT 8",
        adm_station_params($station_id)
    )
    : [];

$recent_deliveries = [];
if (adm_table_exists($pdo, 'deliveries_oversight')) {
    $recent_deliveries = array_merge($recent_deliveries, adm_rows(
        $pdo,
        "SELECT id,
                delivery_type AS type,
                COALESCE(NULLIF(delivery_ref, ''), CONCAT('DO-', id)) AS ref_no,
                supplier,
                product,
                status,
                created_at,
                CASE WHEN delivery_type = 'fuel' THEN 'admin_fuel_deliveries_oversight.php' ELSE 'admin_merchandise_deliveries_oversight.php' END AS action_url
         FROM deliveries_oversight
         WHERE {$station_sql}
         ORDER BY created_at DESC
         LIMIT 10",
        $station_params
    ));
}
if (adm_table_exists($pdo, 'purchase_orders')) {
    $recent_deliveries = array_merge($recent_deliveries, adm_rows(
        $pdo,
        "SELECT id,
                COALESCE(NULLIF(type, ''), 'merchandise') AS type,
                po_number AS ref_no,
                'Purchase Order' AS supplier,
                COALESCE(product_name, 'Merchandise Products') AS product,
                status,
                created_at,
                'admin_purchase_orders.php' AS action_url
         FROM purchase_orders
         WHERE {$station_sql}
           AND (delivery_validated = 1 OR stock_in_done = 1 OR LOWER(COALESCE(status, '')) IN ('received', 'confirmed', 'admin finalized', 'approved po'))
         ORDER BY updated_at DESC, created_at DESC
         LIMIT 10",
        $station_params
    ));
}
adm_sort_created_desc($recent_deliveries);
$recent_deliveries = array_slice(adm_merge_by_ref($recent_deliveries), 0, 8);

$recent_transactions = [];
if (adm_table_exists($pdo, 'fuel_transactions')) {
    $recent_transactions = array_merge($recent_transactions, adm_rows(
        $pdo,
        "SELECT 'Fuel' AS type,
                COALESCE(transaction_id, CONCAT('FT-', id)) AS ref_no,
                fuel_type AS details,
                total_amount,
                COALESCE(status, 'Completed') AS status,
                transaction_date AS created_at,
                'admin_all_transactions.php' AS action_url
         FROM fuel_transactions
         WHERE {$station_sql}
         ORDER BY transaction_date DESC, id DESC
         LIMIT 10",
        $station_params
    ));
}
if (adm_table_exists($pdo, 'merchandise_transactions')) {
    $recent_transactions = array_merge($recent_transactions, adm_rows(
        $pdo,
        "SELECT 'Merchandise' AS type,
                COALESCE(transaction_id, CONCAT('MT-', id)) AS ref_no,
                COALESCE(customer_name, 'Merchandise') AS details,
                total_amount,
                COALESCE(validation_status, workflow_status, 'Completed') AS status,
                COALESCE(transaction_date, created_at) AS created_at,
                'admin_all_transactions.php' AS action_url
         FROM merchandise_transactions
         WHERE {$station_sql}
         ORDER BY COALESCE(transaction_date, created_at) DESC, id DESC
         LIMIT 10",
        $station_params
    ));
}
if (adm_table_exists($pdo, 'job_orders')) {
    $recent_transactions = array_merge($recent_transactions, adm_rows(
        $pdo,
        "SELECT 'Service' AS type,
                COALESCE(job_order_number, job_order_id, CONCAT('JO-', id)) AS ref_no,
                COALESCE(customer_name, service_type, 'Service') AS details,
                COALESCE(total_cost, estimated_cost, 0) AS total_amount,
                COALESCE(status, validation_status, 'Pending') AS status,
                created_at,
                'admin_all_transactions.php' AS action_url
         FROM job_orders
         WHERE {$station_sql}
         ORDER BY created_at DESC, id DESC
         LIMIT 10",
        $station_params
    ));
}
adm_sort_created_desc($recent_transactions);
$recent_transactions = array_slice($recent_transactions, 0, 10);

$low_inventory_rows = [];
if (adm_table_exists($pdo, 'fuel_inventory')) {
    $fuel_all = adm_rows($pdo, "SELECT fuel_type, current_level, current_stock, capacity FROM fuel_inventory WHERE {$station_sql}", $station_params);
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
                'type' => 'Fuel',
                'product' => $fi_row['fuel_type'],
                'current_stock' => $level,
                'reorder_level' => $low_lvl,
                'status' => $status,
                'action_url' => 'admin_inventory_fuel.php'
            ];
        }
    }
}
if (adm_table_exists($pdo, 'station_inventory')) {
    $product_name_expr = adm_table_exists($pdo, 'products') ? 'COALESCE(p.name, CONCAT(\'Product #\', si.product_id))' : 'CONCAT(\'Product #\', si.product_id)';
    $product_join = adm_table_exists($pdo, 'products') ? 'LEFT JOIN products p ON p.id = si.product_id' : '';
    $low_inventory_rows = array_merge($low_inventory_rows, adm_rows(
        $pdo,
        "SELECT 'Merchandise' AS type,
                {$product_name_expr} AS product,
                COALESCE(si.stock_level, 0) AS current_stock,
                COALESCE(si.reorder_level, 0) AS reorder_level,
                CASE
                    WHEN COALESCE(si.stock_level, 0) <= 0 THEN 'Critical'
                    WHEN COALESCE(si.stock_level, 0) <= COALESCE(si.reorder_level, 0) THEN 'Low'
                    ELSE 'Normal'
                END AS status,
                'admin_inventory_merchandise.php' AS action_url
         FROM station_inventory si
         {$product_join}
         WHERE " . adm_station_clause($station_id, 'si') . "
           AND si.status = 'active'
           AND COALESCE(si.stock_level, 0) <= COALESCE(si.reorder_level, 0)
         ORDER BY si.stock_level ASC
         LIMIT 8",
        adm_station_params($station_id)
    ));
}
usort($low_inventory_rows, static function ($a, $b) {
    $rank = ['Critical' => 0, 'Low' => 1, 'Normal' => 2];
    return ($rank[$a['status'] ?? 'Normal'] ?? 3) <=> ($rank[$b['status'] ?? 'Normal'] ?? 3);
});
$low_inventory_rows = array_slice($low_inventory_rows, 0, 10);

$quick_actions = [
    [
        'label' => 'User Management',
        'icon' => 'fa-users-cog',
        'href' => 'users.php',
        'badge' => $pending_user_accounts > 0 ? $pending_user_accounts . ' pending' : '',
    ],
    [
        'label' => 'Pricing Management',
        'icon' => 'fa-tags',
        'href' => 'admin_set_prices.php',
        'badge' => $pending_price_requests > 0 ? $pending_price_requests . ' pending' : '',
    ],
    [
        'label' => 'Reports',
        'icon' => 'fa-chart-line',
        'href' => 'admin_reports.php',
        'badge' => '',
    ],
    [
        'label' => 'Audit Logs',
        'icon' => 'fa-clipboard-list',
        'href' => 'admin_audit_trail.php',
        'badge' => $audit_errors > 0 ? $audit_errors . ' errors' : '',
    ],
    [
        'label' => 'System Settings',
        'icon' => 'fa-sliders-h',
        'href' => '#system-monitoring',
        'badge' => 'Status',
    ],
    [
        'label' => 'Inventory',
        'icon' => 'fa-boxes-stacked',
        'href' => 'admin_inventory_merchandise.php',
        'badge' => $total_inventory_alerts > 0 ? $total_inventory_alerts . ' alerts' : '',
    ],
    [
        'label' => 'Transactions',
        'icon' => 'fa-receipt',
        'href' => 'admin_all_transactions.php',
        'badge' => $total_transactions > 0 ? $total_transactions . ' today' : '',
    ],
];

include __DIR__ . '/../partials/header.php';
?>

<style>
    .admin-dashboard {
        --petron-blue: #002f70;
        --petron-navy: #032b55;
        --petron-red: #df1f26;
        --ink: #172033;
        --muted: #64748b;
        --line: #dbe4ef;
        --page: #f5f7fb;
        --card: #ffffff;
        --success: #119b52;
        --warning: #f59e0b;
        --danger: #dc2626;
        --info: #0e7490;
        color: var(--ink);
        padding: 0 0 70px;
        background: var(--page);
        min-height: calc(100vh - 120px);
    }

    .admin-dashboard * {
        box-sizing: border-box;
    }

    .admin-dashboard a {
        text-decoration: none;
    }

    .admin-panel {
        background: var(--card);
        border: 1px solid var(--line);
        border-radius: 8px;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
    }

    .admin-page-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 18px;
        padding: 0 0 18px 0;
        margin-bottom: 22px;
        border-bottom: 2px solid var(--line);
    }

    .admin-kicker {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        color: var(--muted);
        font-size: 14px;
        font-weight: 700;
        margin-top: 8px;
    }

    .admin-page-header h1 {
        margin: 0;
        color: var(--petron-blue);
        font-size: 24px;
        font-weight: 800;
        letter-spacing: 0;
    }

    .admin-subtext {
        margin: 10px 0 0;
        max-width: 860px;
        color: #475569;
        font-size: 14px;
        line-height: 1.55;
    }

    .admin-filter-form {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    .admin-date-input {
        height: 44px;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 0 14px;
        color: #0f172a;
        background: #fff;
        min-width: 170px;
    }

    .admin-btn {
        min-height: 38px;
        border: 0;
        border-radius: 8px;
        padding: 0 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        font-weight: 800;
        font-size: 13px;
        cursor: pointer;
        transition: transform 0.16s ease, box-shadow 0.16s ease, background 0.16s ease;
        white-space: nowrap;
    }

    .admin-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 8px 16px rgba(0, 47, 112, 0.14);
    }

    .admin-btn.primary {
        background: var(--petron-blue);
        color: #fff;
    }

    .admin-btn.success {
        background: var(--success);
        color: #fff;
    }

    .admin-btn.danger {
        background: var(--danger);
        color: #fff;
    }

    .admin-btn.neutral {
        background: #eef4fb;
        color: var(--petron-blue);
    }

    .admin-alert {
        border-radius: 8px;
        padding: 14px 16px;
        margin-bottom: 16px;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .admin-alert.success {
        background: #e9f8ef;
        color: #116b3a;
        border: 1px solid #bde8cb;
    }

    .admin-alert.danger {
        background: #fff1f1;
        color: #991b1b;
        border: 1px solid #fecaca;
    }

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
        background: #ffffff;
        border: 1px solid #dbe3ee;
        border-radius: 8px;
        box-shadow: 0 8px 22px rgba(15, 23, 42, 0.05);
        transition: transform 0.25s ease, box-shadow 0.25s ease;
    }

    .mgr-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.08);
    }

    .mgr-card > div:first-child {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
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
        margin-left: 10px;
    }

    .health-list {
        display: flex;
        flex-direction: column;
        gap: 4px;
        margin-top: 8px;
        color: var(--muted);
        font-size: 12px;
        font-weight: 700;
    }

    .health-list span {
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .admin-dashboard .text-success {
        color: var(--success) !important;
    }

    .admin-dashboard .text-warning {
        color: var(--warning) !important;
    }

    .admin-dashboard .text-danger {
        color: var(--danger) !important;
    }

    .chart-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 18px;
        margin-bottom: 22px;
    }

    .chart-card {
        min-height: 330px;
        padding: 20px;
    }

    .chart-card.wide {
        grid-column: span 2;
    }

    .section-title,
    .panel-title {
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 0;
        color: var(--petron-blue);
        font-size: 20px;
        font-weight: 900;
        letter-spacing: 0;
    }

    .section-title {
        margin: 26px 0 14px;
    }

    .chart-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        border-bottom: 1px solid #edf2f7;
        padding-bottom: 14px;
        margin-bottom: 14px;
    }

    .chart-head h2 {
        font-size: 18px;
    }

    .chart-shell {
        height: 245px;
        position: relative;
    }

    .management-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 18px;
        margin-bottom: 22px;
    }

    .admin-panel {
        min-height: 260px;
        overflow: hidden;
    }

    .admin-panel.full {
        grid-column: 1 / -1;
    }

    .panel-head {
        min-height: 62px;
        padding: 18px 20px;
        border-bottom: 1px solid #edf2f7;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }

    .panel-title {
        font-size: 17px;
    }

    .panel-body {
        padding: 18px 20px 20px;
    }

    .data-table-wrap {
        width: 100%;
        overflow-x: auto;
    }

    .data-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 620px;
    }

    .data-table th {
        color: #475569;
        font-size: 12px;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 0;
        background: #f8fafc;
        border-bottom: 1px solid #e5ecf5;
        padding: 10px;
        text-align: left;
        white-space: nowrap;
    }

    .data-table td {
        padding: 11px 10px;
        border-bottom: 1px solid #edf2f7;
        color: #243044;
        font-size: 13px;
        vertical-align: middle;
    }

    .data-table tr:last-child td {
        border-bottom: 0;
    }

    .table-actions {
        display: flex;
        align-items: center;
        gap: 7px;
        flex-wrap: wrap;
    }

    .tiny-btn {
        min-height: 30px;
        border-radius: 7px;
        padding: 0 10px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        font-size: 12px;
        font-weight: 900;
        color: var(--petron-blue);
        background: transparent;
        border: 1px solid var(--petron-blue);
        text-decoration: none;
        transition: background 0.15s ease, color 0.15s ease;
    }

    .tiny-btn:hover {
        background: var(--petron-blue);
        color: #ffffff;
        text-decoration: none;
    }

    .tiny-btn.success {
        color: #116b3a;
        background: transparent;
        border-color: #116b3a;
    }

    .tiny-btn.success:hover {
        background: #116b3a;
        color: #ffffff;
    }

    .tiny-btn.danger {
        color: #9f1239;
        background: transparent;
        border-color: #9f1239;
    }

    .tiny-btn.danger:hover {
        background: #9f1239;
        color: #ffffff;
    }

    .badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 24px;
        border-radius: 999px;
        padding: 3px 9px;
        font-size: 11px;
        font-weight: 900;
        white-space: nowrap;
    }

    .badge.success {
        background: #dcfce7;
        color: #166534;
    }

    .badge.warning {
        background: #fef3c7;
        color: #92400e;
    }

    .badge.danger {
        background: #fee2e2;
        color: #991b1b;
    }

    .badge.info {
        background: #cffafe;
        color: #155e75;
    }

    .badge.neutral {
        background: #e5e7eb;
        color: #374151;
    }

    .empty-state {
        min-height: 128px;
        display: grid;
        place-items: center;
        text-align: center;
        color: var(--muted);
        font-weight: 700;
        line-height: 1.5;
        background: #f8fafc;
        border: 1px dashed #d8e2ef;
        border-radius: 8px;
        padding: 18px;
    }

    .quick-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 24px;
    }

    .quick-action {
        min-height: 90px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        background: #ffffff;
        color: #334155;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 9px;
        padding: 16px 12px;
        font-weight: 700;
        font-size: 12px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.04);
        transition: all 0.25s ease;
        text-align: center;
        width: 100%;
        cursor: pointer;
        text-decoration: none;
        box-sizing: border-box;
    }

    .quick-action:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        border-color: var(--petron-blue);
        color: var(--petron-blue);
    }

    .quick-action i {
        font-size: 20px;
        color: var(--petron-blue);
        transition: transform 0.25s ease;
        background: transparent;
        width: auto;
        height: auto;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .quick-action:hover i {
        transform: scale(1.15);
    }

    .quick-action span {
        display: block;
        line-height: 1.3;
        font-size: 12px;
        font-weight: 700;
    }

    .quick-action small {
        display: block;
        margin-top: 4px;
        color: #64748b;
        font-size: 10px;
        font-weight: 600;
    }

    .quick-form {
        margin: 0;
    }

    .system-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 18px;
        margin-bottom: 24px;
    }

    .system-stat-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .system-stat {
        border: 1px solid #e5ecf5;
        border-radius: 8px;
        padding: 14px;
        background: #f8fafc;
    }

    .system-stat label {
        display: block;
        color: var(--muted);
        font-size: 12px;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 0;
        margin-bottom: 7px;
    }

    .system-stat strong {
        color: #0f172a;
        font-size: 19px;
        font-weight: 900;
    }

    .mini-list {
        display: grid;
        gap: 10px;
    }

    .mini-list-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        border-bottom: 1px solid #edf2f7;
        padding-bottom: 10px;
        color: #243044;
        font-size: 13px;
    }

    .mini-list-row:last-child {
        border-bottom: 0;
        padding-bottom: 0;
    }

    .mini-list-row span {
        color: var(--muted);
        font-weight: 800;
    }

    @media (max-width: 1300px) {
        .mgr-summary-grid,
        .quick-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .chart-grid,
        .system-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 900px) {
        .admin-dashboard {
            padding: 0;
            padding-bottom: 70px;
        }

        .admin-page-header,
        .management-grid,
        .system-grid {
            grid-template-columns: 1fr;
        }

        .admin-page-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .admin-filter-form {
            width: 100%;
            justify-content: stretch;
        }

        .admin-date-input,
        .admin-filter-form .admin-btn {
            width: 100%;
        }

        .mgr-summary-grid,
        .chart-grid,
        .management-grid,
        .quick-grid {
            grid-template-columns: 1fr;
        }

        .chart-card.wide,
        .admin-panel.full {
            grid-column: auto;
        }
    }
</style>

<div class="admin-dashboard">
    <?php if ($flash_success): ?>
        <div class="admin-alert success"><i class="fas fa-circle-check"></i><?= adm_h($flash_success) ?></div>
    <?php endif; ?>
    <?php if ($flash_error): ?>
        <div class="admin-alert danger"><i class="fas fa-triangle-exclamation"></i><?= adm_h($flash_error) ?></div>
    <?php endif; ?>

    <section class="admin-page-header">
        <div>
            <h1>WELCOME, <?= adm_h(strtoupper($display_name)) ?>!</h1>
            <div class="admin-kicker"><i class="fas fa-user-shield"></i> Admin Dashboard</div>
            <p class="admin-subtext">Monitor overall station performance, users, inventory, financial operations, approvals, and system activities.</p>
        </div>
        <form class="admin-filter-form" method="get" action="admin_dashboard.php">
            <input class="admin-date-input" type="date" name="date" value="<?= adm_h($date_filter) ?>">
            <button class="admin-btn primary" type="submit"><i class="fas fa-filter"></i>Filter</button>
        </form>
    </section>

    <section class="mgr-summary-grid" aria-label="Admin summary cards">
        <div class="mgr-card" data-tone="blue">
            <div>
                <div class="mgr-card-label">Today's Revenue</div>
                <div class="mgr-card-value"><?= adm_money($total_revenue) ?></div>
                <div class="mgr-card-sub">Fuel <?= adm_money($fuel_revenue) ?> · Merchandise <?= adm_money($merch_revenue) ?> · Services <?= adm_money($service_revenue) ?></div>
            </div>
            <div class="mgr-icon" style="background: #eff6ff; color: #002F70;"><i class="fas fa-peso-sign"></i></div>
        </div>

        <div class="mgr-card" data-tone="green">
            <div>
                <div class="mgr-card-label">Today's Transactions</div>
                <div class="mgr-card-value"><?= number_format($total_transactions) ?></div>
                <div class="mgr-card-sub">Fuel <?= number_format($fuel_count) ?> · Merchandise <?= number_format($merch_count) ?> · Services <?= number_format($service_count) ?></div>
            </div>
            <div class="mgr-icon" style="background: #f0fdf4; color: #16a34a;"><i class="fas fa-right-left"></i></div>
        </div>

        <div class="mgr-card" data-tone="cyan">
            <div>
                <div class="mgr-card-label">Active Users</div>
                <div class="mgr-card-value"><?= number_format($total_active_users) ?></div>
                <div class="mgr-card-sub"><?= number_format($active_admins) ?> Admin · <?= number_format($active_managers) ?> Manager · <?= number_format($active_staff) ?> Staff</div>
            </div>
            <div class="mgr-icon" style="background: #ecfeff; color: #0891b2;"><i class="fas fa-users"></i></div>
        </div>

        <div class="mgr-card" data-tone="amber">
            <div>
                <div class="mgr-card-label">Pending Approvals</div>
                <div class="mgr-card-value"><?= number_format($total_pending_approvals) ?></div>
                <div class="mgr-card-sub">Users <?= number_format($pending_user_accounts) ?> · Customers <?= number_format($pending_customer_requests) ?> · Inventory <?= number_format($pending_inventory_approvals) ?> · Pricing <?= number_format($pending_price_requests) ?></div>
            </div>
            <div class="mgr-icon" style="background: #fef9c3; color: #eab308;"><i class="fas fa-clipboard-check"></i></div>
        </div>

        <div class="mgr-card" data-tone="red">
            <div>
                <div class="mgr-card-label">Inventory Alerts</div>
                <div class="mgr-card-value"><?= number_format($total_inventory_alerts) ?></div>
                <div class="mgr-card-sub">Low fuel <?= number_format($fuel_low_count) ?> · Low merchandise <?= number_format($merch_low_count) ?> · Critical <?= number_format($fuel_critical_count + $merch_critical_count) ?></div>
            </div>
            <div class="mgr-icon" style="background: #fef2f2; color: #dc2626;"><i class="fas fa-triangle-exclamation"></i></div>
        </div>

        <div class="mgr-card" data-tone="orange">
            <div>
                <div class="mgr-card-label">Pending Deliveries</div>
                <div class="mgr-card-value"><?= number_format($pending_deliveries_count) ?></div>
                <div class="mgr-card-sub">Awaiting receive, validation, or stock-in action.</div>
            </div>
            <div class="mgr-icon" style="background: #ffedd5; color: #ea580c;"><i class="fas fa-truck"></i></div>
        </div>

        <div class="mgr-card" data-tone="gray">
            <div>
                <div class="mgr-card-label">System Health</div>
                <div class="mgr-card-value"><?= adm_h($system_health_value) ?></div>
                <div class="health-list">
                    <span><i class="fas fa-circle <?= $db_connected ? 'text-success' : 'text-danger' ?>"></i> Database <?= $db_connected ? 'Connected' : 'Issue' ?></span>
                    <span><i class="fas fa-circle <?= $server_running ? 'text-success' : 'text-danger' ?>"></i> Server <?= $server_running ? 'Running' : 'Issue' ?></span>
                    <span><i class="fas fa-circle <?= $backup_ok ? 'text-success' : 'text-warning' ?>"></i> Backup <?= adm_h($backup_label) ?></span>
                </div>
            </div>
            <div class="mgr-icon" style="background: #f1f5f9; color: #64748b;"><i class="fas fa-server"></i></div>
        </div>
    </section>

    <section class="chart-grid" aria-label="Admin charts">
        <article class="admin-panel chart-card">
            <div class="chart-head">
                <h2 class="panel-title"><i class="fas fa-chart-pie"></i>Revenue Breakdown</h2>
            </div>
            <div class="chart-shell"><canvas id="revenueBreakdownChart"></canvas></div>
        </article>

        <article class="admin-panel chart-card wide">
            <div class="chart-head">
                <h2 class="panel-title"><i class="fas fa-chart-line"></i>Monthly Revenue Trend</h2>
                <span class="badge neutral"><?= (int) $selected_year ?></span>
            </div>
            <div class="chart-shell"><canvas id="monthlyRevenueChart"></canvas></div>
        </article>

        <article class="admin-panel chart-card">
            <div class="chart-head">
                <h2 class="panel-title"><i class="fas fa-chart-column"></i>Transactions per Module</h2>
            </div>
            <div class="chart-shell"><canvas id="transactionsModuleChart"></canvas></div>
        </article>

        <article class="admin-panel chart-card">
            <div class="chart-head">
                <h2 class="panel-title"><i class="fas fa-boxes-stacked"></i>Inventory Status</h2>
            </div>
            <div class="chart-shell"><canvas id="inventoryStatusChart"></canvas></div>
        </article>

        <article class="admin-panel chart-card">
            <div class="chart-head">
                <h2 class="panel-title"><i class="fas fa-calendar-week"></i>Weekly Sales Trend</h2>
            </div>
            <div class="chart-shell"><canvas id="weeklySalesChart"></canvas></div>
        </article>

        <article class="admin-panel chart-card">
            <div class="chart-head">
                <h2 class="panel-title"><i class="fas fa-user-check"></i>User Activity</h2>
            </div>
            <div class="chart-shell"><canvas id="userActivityChart"></canvas></div>
        </article>

        <article class="admin-panel chart-card">
            <div class="chart-head">
                <h2 class="panel-title"><i class="fas fa-gas-pump"></i>Fuel Sales by Product</h2>
            </div>
            <div class="chart-shell"><canvas id="fuelSalesChart"></canvas></div>
        </article>

        <article class="admin-panel chart-card">
            <div class="chart-head">
                <h2 class="panel-title"><i class="fas fa-bag-shopping"></i>Merchandise Sales by Category</h2>
            </div>
            <div class="chart-shell"><canvas id="merchandiseCategoryChart"></canvas></div>
        </article>
    </section>

    <h2 class="section-title"><i class="fas fa-list-check"></i>Management Panels</h2>
    <section class="management-grid" aria-label="Admin management panels">
        <article class="admin-panel">
            <div class="panel-head">
                <h3 class="panel-title"><i class="fas fa-user-clock"></i>Pending User Accounts</h3>
                <a class="tiny-btn" href="users.php">Open Users</a>
            </div>
            <div class="panel-body">
                <?php if (!$pending_users_data): ?>
                    <div class="empty-state">No pending user accounts.</div>
                <?php else: ?>
                    <div class="data-table-wrap">
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
                                <?php foreach ($pending_users_data as $row): ?>
                                    <tr>
                                        <td><?= adm_h($row['employee']) ?></td>
                                        <td><?= adm_h(ucfirst((string) $row['role'])) ?></td>
                                        <td><span class="badge <?= adm_badge_class($row['status']) ?>"><?= adm_h($row['status']) ?></span></td>
                                        <td>
                                            <div class="table-actions">
                                                <a class="tiny-btn success" href="users.php">Approve</a>
                                                <a class="tiny-btn danger" href="users.php">Deactivate</a>
                                                <a class="tiny-btn" href="users.php">Reset Password</a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </article>

        <article class="admin-panel">
            <div class="panel-head">
                <h3 class="panel-title"><i class="fas fa-clock-rotate-left"></i>Recent User Activities</h3>
                <a class="tiny-btn" href="admin_audit_trail.php">Audit Logs</a>
            </div>
            <div class="panel-body">
                <?php if (!$recent_user_activities): ?>
                    <div class="empty-state">No recent user activity found.</div>
                <?php else: ?>
                    <div class="data-table-wrap">
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
                                <?php foreach ($recent_user_activities as $row): ?>
                                    <tr>
                                        <td><?= adm_h($row['user_name']) ?></td>
                                        <td><?= adm_h($row['module']) ?></td>
                                        <td><?= adm_h($row['action']) ?></td>
                                        <td><?= adm_h(adm_format_time($row['created_at'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </article>

        <article class="admin-panel">
            <div class="panel-head">
                <h3 class="panel-title"><i class="fas fa-clipboard-list"></i>Pending Inventory Adjustments</h3>
                <a class="tiny-btn" href="admin_purchase_orders.php">Purchase Orders</a>
            </div>
            <div class="panel-body">
                <?php if (!$pending_inventory_adjustments): ?>
                    <div class="empty-state">No pending inventory approvals.</div>
                <?php else: ?>
                    <div class="data-table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Ref No</th>
                                    <th>Product</th>
                                    <th>Requested By</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending_inventory_adjustments as $row): ?>
                                    <tr>
                                        <td><?= adm_h($row['ref_no']) ?></td>
                                        <td><?= adm_h($row['product']) ?></td>
                                        <td><?= adm_h($row['requested_by']) ?></td>
                                        <td><span class="badge <?= adm_badge_class($row['status']) ?>"><?= adm_h(adm_status_label($row['status'])) ?></span></td>
                                        <td>
                                            <div class="table-actions">
                                                <a class="tiny-btn" href="<?= adm_h($row['action_url']) ?>">Review</a>
                                                <a class="tiny-btn success" href="<?= adm_h($row['action_url']) ?>">Approve</a>
                                                <a class="tiny-btn danger" href="<?= adm_h($row['action_url']) ?>">Reject</a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </article>

        <article class="admin-panel">
            <div class="panel-head">
                <h3 class="panel-title"><i class="fas fa-address-card"></i>Pending Customer Registrations</h3>
                <a class="tiny-btn" href="admin_customers.php">Customers</a>
            </div>
            <div class="panel-body">
                <?php if (!$pending_customers): ?>
                    <div class="empty-state">No pending customer registrations.</div>
                <?php else: ?>
                    <div class="data-table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>Contact</th>
                                    <th>Requested By</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending_customers as $row): ?>
                                    <tr>
                                        <td><?= adm_h($row['customer']) ?></td>
                                        <td><?= adm_h($row['contact']) ?></td>
                                        <td><?= adm_h($row['requested_by']) ?></td>
                                        <td>
                                            <div class="table-actions">
                                                <a class="tiny-btn success" href="admin_customers.php">Approve</a>
                                                <a class="tiny-btn danger" href="admin_customers.php">Reject</a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </article>

        <article class="admin-panel">
            <div class="panel-head">
                <h3 class="panel-title"><i class="fas fa-truck-ramp-box"></i>Recent Deliveries</h3>
                <a class="tiny-btn" href="admin_merchandise_deliveries_oversight.php">Delivery Oversight</a>
            </div>
            <div class="panel-body">
                <?php if (!$recent_deliveries): ?>
                    <div class="empty-state">No delivery records yet.</div>
                <?php else: ?>
                    <div class="data-table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Delivery No</th>
                                    <th>Supplier</th>
                                    <th>Product</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_deliveries as $row): ?>
                                    <tr>
                                        <td><?= adm_h($row['ref_no']) ?></td>
                                        <td><?= adm_h($row['supplier'] ?? 'N/A') ?></td>
                                        <td><?= adm_h($row['product'] ?? ucfirst((string) $row['type'])) ?></td>
                                        <td><span class="badge <?= adm_badge_class($row['status']) ?>"><?= adm_h(adm_status_label($row['status'])) ?></span></td>
                                        <td>
                                            <div class="table-actions">
                                                <a class="tiny-btn success" href="<?= adm_h($row['action_url']) ?>">Receive Delivery</a>
                                                <a class="tiny-btn" href="<?= adm_h($row['action_url']) ?>">View</a>
                                                <a class="tiny-btn" href="<?= adm_h($row['action_url']) ?>">Stock-In</a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </article>

        <article class="admin-panel">
            <div class="panel-head">
                <h3 class="panel-title"><i class="fas fa-receipt"></i>Recent Transactions</h3>
                <a class="tiny-btn" href="admin_all_transactions.php">All Transactions</a>
            </div>
            <div class="panel-body">
                <?php if (!$recent_transactions): ?>
                    <div class="empty-state">No recent transactions found.</div>
                <?php else: ?>
                    <div class="data-table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Reference</th>
                                    <th>Details</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_transactions as $row): ?>
                                    <tr>
                                        <td><span class="badge info"><?= adm_h($row['type']) ?></span></td>
                                        <td><a href="<?= adm_h($row['action_url']) ?>"><?= adm_h($row['ref_no']) ?></a></td>
                                        <td><?= adm_h($row['details']) ?></td>
                                        <td><?= adm_money($row['total_amount']) ?></td>
                                        <td><span class="badge <?= adm_badge_class($row['status']) ?>"><?= adm_h(adm_status_label($row['status'], 'Posted')) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </article>

        <article class="admin-panel">
            <div class="panel-head">
                <h3 class="panel-title"><i class="fas fa-box-open"></i>Low Inventory Summary</h3>
                <a class="tiny-btn" href="admin_inventory_merchandise.php">Inventory</a>
            </div>
            <div class="panel-body">
                <?php if (!$low_inventory_rows): ?>
                    <div class="empty-state">Fuel and merchandise inventory are within normal levels.</div>
                <?php else: ?>
                    <div class="data-table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Product</th>
                                    <th>Current Stock</th>
                                    <th>Reorder Level</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($low_inventory_rows as $row): ?>
                                    <tr>
                                        <td><?= adm_h($row['type']) ?></td>
                                        <td><a href="<?= adm_h($row['action_url']) ?>"><?= adm_h($row['product']) ?></a></td>
                                        <td><?= adm_qty($row['current_stock']) ?></td>
                                        <td><?= adm_qty($row['reorder_level']) ?></td>
                                        <td><span class="badge <?= adm_badge_class($row['status']) ?>"><?= adm_h($row['status']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </article>
    </section>

    <h2 id="system-monitoring" class="section-title"><i class="fas fa-shield-halved"></i>System Monitoring</h2>
    <section class="system-grid" aria-label="Admin system monitoring">
        <article class="admin-panel">
            <div class="panel-head">
                <h3 class="panel-title"><i class="fas fa-clipboard-list"></i>Audit Trail Summary</h3>
                <a class="tiny-btn" href="admin_audit_trail.php">View Audit Logs</a>
            </div>
            <div class="panel-body system-stat-grid">
                <div class="system-stat"><label>Today's Logs</label><strong><?= number_format($audit_today_logs) ?></strong></div>
                <div class="system-stat"><label>Errors</label><strong><?= number_format($audit_errors) ?></strong></div>
                <div class="system-stat"><label>Warnings</label><strong><?= number_format($audit_warnings) ?></strong></div>
                <div class="system-stat"><label>Failed Logins</label><strong><?= number_format($login_failures) ?></strong></div>
                <div class="system-stat"><label>Successful Logins</label><strong><?= number_format($login_successes) ?></strong></div>
            </div>
        </article>

        <article class="admin-panel">
            <div class="panel-head">
                <h3 class="panel-title"><i class="fas fa-database"></i>Backup Status</h3>
            </div>
            <div class="panel-body">
                <div class="mini-list">
                    <div class="mini-list-row"><span>Last Backup</span><strong><?= adm_h($latest_backup ? adm_format_time($latest_backup['completed_at'] ?? $latest_backup['created_at']) : 'None') ?></strong></div>
                    <div class="mini-list-row"><span>Status</span><span class="badge <?= adm_badge_class($backup_label) ?>"><?= adm_h($backup_label) ?></span></div>
                    <div class="mini-list-row"><span>Next Scheduled Backup</span><strong><?= adm_h($next_backup) ?></strong></div>
                </div>
                <form class="quick-form" method="post" action="admin_dashboard.php" style="margin-top:16px;">
                    <input type="hidden" name="date" value="<?= adm_h($date_filter) ?>">
                    <input type="hidden" name="admin_dashboard_action" value="run_backup">
                    <button class="admin-btn primary" type="submit"><i class="fas fa-download"></i>Backup Now</button>
                </form>
            </div>
        </article>

        <article class="admin-panel">
            <div class="panel-head">
                <h3 class="panel-title"><i class="fas fa-server"></i>Database Status</h3>
            </div>
            <div class="panel-body">
                <div class="mini-list">
                    <div class="mini-list-row"><span>Connection</span><span class="badge <?= $db_connected ? 'success' : 'danger' ?>"><?= $db_connected ? 'Connected' : 'Disconnected' ?></span></div>
                    <div class="mini-list-row"><span>Storage Used</span><strong><?= adm_h(adm_bytes($db_stats['storage_bytes'] ?? 0)) ?></strong></div>
                    <div class="mini-list-row"><span>Estimated Records</span><strong><?= number_format((int) ($db_stats['records'] ?? 0)) ?></strong></div>
                </div>
            </div>
        </article>
    </section>

    <h2 class="section-title"><i class="fas fa-bolt"></i>Quick Actions</h2>
    <section class="quick-grid" aria-label="Admin quick actions">
        <?php foreach ($quick_actions as $action): ?>
            <a class="quick-action" href="<?= adm_h($action['href']) ?>">
                <i class="fas <?= adm_h($action['icon']) ?>"></i>
                <span>
                    <?= adm_h($action['label']) ?>
                    <?php if ($action['badge'] !== ''): ?>
                        <small><?= adm_h($action['badge']) ?></small>
                    <?php endif; ?>
                </span>
            </a>
        <?php endforeach; ?>
        <form class="quick-form" method="post" action="admin_dashboard.php">
            <input type="hidden" name="date" value="<?= adm_h($date_filter) ?>">
            <input type="hidden" name="admin_dashboard_action" value="run_backup">
            <button class="quick-action" type="submit">
                <i class="fas fa-database"></i>
                <span>
                    Backup Database
                    <small><?= adm_h($backup_label) ?></small>
                </span>
            </button>
        </form>
    </section>
</div>

<script src="<?= adm_h(($app_base_path ?? '') . '/assets/vendor/chart.js/chart.umd.min.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const peso = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
    const number = new Intl.NumberFormat('en-PH');
    const colors = {
        fuel: '#df1f26',
        merch: '#119b52',
        service: '#002f70',
        warning: '#f59e0b',
        danger: '#dc2626',
        info: '#0e7490',
        purple: '#7c3aed',
        grid: '#dbe4ef',
        text: '#475569'
    };

    function safeData(values) {
        return values.map(function (value) {
            const n = Number(value);
            return Number.isFinite(n) ? n : 0;
        });
    }

    function baseOptions(extra) {
        return Object.assign({
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: {
                        usePointStyle: true,
                        boxWidth: 8,
                        color: colors.text,
                        font: { size: 12, weight: '600' }
                    }
                },
                tooltip: {
                    backgroundColor: '#0f172a',
                    padding: 10,
                    titleFont: { weight: '700' },
                    bodyFont: { weight: '600' }
                }
            },
            scales: {
                x: {
                    grid: { color: colors.grid },
                    ticks: { color: colors.text, font: { weight: '600' } }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: colors.grid },
                    ticks: { color: colors.text, font: { weight: '600' } }
                }
            }
        }, extra || {});
    }

    function renderChart(id, config) {
        const canvas = document.getElementById(id);
        if (!canvas || !window.Chart) return;
        new Chart(canvas, config);
    }

    renderChart('revenueBreakdownChart', {
        type: 'doughnut',
        data: {
            labels: ['Fuel Sales', 'Merchandise Sales', 'Service Sales'],
            datasets: [{
                data: safeData([<?= json_encode($fuel_revenue) ?>, <?= json_encode($merch_revenue) ?>, <?= json_encode($service_revenue) ?>]),
                backgroundColor: [colors.fuel, colors.merch, colors.service],
                borderWidth: 0
            }]
        },
        options: baseOptions({
            cutout: '62%',
            scales: {},
            plugins: {
                legend: { position: 'bottom' },
                tooltip: {
                    callbacks: {
                        label: function (ctx) {
                            return ctx.label + ': ' + peso.format(ctx.raw || 0);
                        }
                    }
                }
            }
        })
    });

    renderChart('monthlyRevenueChart', {
        type: 'line',
        data: {
            labels: <?= json_encode($month_labels) ?>,
            datasets: [{
                label: 'Revenue',
                data: safeData(<?= json_encode($monthly_revenue) ?>),
                borderColor: colors.service,
                backgroundColor: 'rgba(0, 47, 112, 0.12)',
                borderWidth: 3,
                pointRadius: 3,
                tension: 0.35,
                fill: true
            }]
        },
        options: baseOptions({
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function (ctx) {
                            return peso.format(ctx.raw || 0);
                        }
                    }
                }
            }
        })
    });

    renderChart('transactionsModuleChart', {
        type: 'bar',
        data: {
            labels: ['Fuel', 'Merchandise', 'Service'],
            datasets: [{
                label: 'Transactions',
                data: safeData([<?= (int) $fuel_count ?>, <?= (int) $merch_count ?>, <?= (int) $service_count ?>]),
                backgroundColor: [colors.fuel, colors.merch, colors.service],
                borderRadius: 5
            }]
        },
        options: baseOptions({
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function (ctx) {
                            return number.format(ctx.raw || 0) + ' transactions';
                        }
                    }
                }
            }
        })
    });

    renderChart('inventoryStatusChart', {
        type: 'bar',
        data: {
            labels: ['Fuel Tanks', 'Merchandise Products'],
            datasets: [
                { label: 'Normal', data: [<?= (int) $fuel_normal_count ?>, <?= (int) $merch_normal_count ?>], backgroundColor: colors.merch, borderRadius: 5 },
                { label: 'Low', data: [<?= (int) $fuel_low_count ?>, <?= (int) $merch_low_count ?>], backgroundColor: colors.warning, borderRadius: 5 },
                { label: 'Critical', data: [<?= (int) $fuel_critical_count ?>, <?= (int) $merch_critical_count ?>], backgroundColor: colors.danger, borderRadius: 5 }
            ]
        },
        options: baseOptions({
            indexAxis: 'y',
            scales: {
                x: { stacked: true, beginAtZero: true, grid: { color: colors.grid }, ticks: { color: colors.text, precision: 0 } },
                y: { stacked: true, grid: { display: false }, ticks: { color: colors.text, font: { weight: '700' } } }
            }
        })
    });

    renderChart('weeklySalesChart', {
        type: 'line',
        data: {
            labels: <?= json_encode($weekly_labels) ?>,
            datasets: [{
                label: 'Revenue',
                data: safeData(<?= json_encode($weekly_sales) ?>),
                borderColor: colors.merch,
                backgroundColor: 'rgba(17, 155, 82, 0.12)',
                borderWidth: 3,
                pointRadius: 3,
                tension: 0.35,
                fill: true
            }]
        },
        options: baseOptions({
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function (ctx) {
                            return peso.format(ctx.raw || 0);
                        }
                    }
                }
            }
        })
    });

    renderChart('userActivityChart', {
        type: 'bar',
        data: {
            labels: <?= json_encode($user_activity_labels) ?>,
            datasets: [{
                label: 'Transactions Processed',
                data: safeData(<?= json_encode($user_activity_data) ?>),
                backgroundColor: colors.purple,
                borderRadius: 5
            }]
        },
        options: baseOptions({
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function (ctx) {
                            return number.format(ctx.raw || 0) + ' processed';
                        }
                    }
                }
            }
        })
    });

    renderChart('fuelSalesChart', {
        type: 'bar',
        data: {
            labels: <?= json_encode($fuel_product_labels) ?>,
            datasets: [{
                label: 'Liters',
                data: safeData(<?= json_encode($fuel_sales_by_product) ?>),
                backgroundColor: [colors.fuel, colors.warning, colors.purple, colors.merch, colors.info],
                borderRadius: 5
            }]
        },
        options: baseOptions({
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function (ctx) {
                            return number.format(ctx.raw || 0) + ' L';
                        }
                    }
                }
            }
        })
    });

    renderChart('merchandiseCategoryChart', {
        type: 'bar',
        data: {
            labels: <?= json_encode($merch_category_labels) ?>,
            datasets: [{
                label: 'Sales',
                data: safeData(<?= json_encode(array_values($merch_sales_by_category)) ?>),
                backgroundColor: [colors.service, colors.info, colors.warning, colors.merch, colors.fuel],
                borderRadius: 5
            }]
        },
        options: baseOptions({
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function (ctx) {
                            return peso.format(ctx.raw || 0);
                        }
                    }
                }
            }
        })
    });
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
