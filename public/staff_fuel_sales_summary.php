<?php
/**
 * STAFF FUEL SALES SUMMARY REPORT
 * Complete fetch process with all summaries
 * PDF-optimized printing (no content cutoff)
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? 'staff');
$user_id = (int)($me['id'] ?? 0);
$station_id = user_station_id();

// Access control
if (!in_array($role, ['staff', 'cashier', 'pump_attendant', 'manager', 'admin', 'superadmin', 'developer'])) {
    header('Location: dashboard.php'); exit;
}

// Module gate
if (!in_array($role, ['superadmin', 'developer']) && !is_module_enabled('reports')) {
    render_module_disabled_page('Reports');
}

if (!$station_id) die('Error: You are not assigned to a station.');

// Get Station Info
$station_name = 'Station';
$station_location = '';
try {
    $s = $pdo->prepare("SELECT name, location FROM stations WHERE id=? LIMIT 1");
    $s->execute([$station_id]);
    $st = $s->fetch(PDO::FETCH_ASSOC);
    if ($st) {
        $station_name = $st['name'];
        $station_location = $st['location'] ?? '';
    }
} catch (Exception $e) {}

// Date handling
$today = date('Y-m-d');
// Default to most recent date with fuel data for this station (fallback to today)
$default_date = $today;
try {
    $dr = $pdo->prepare("SELECT DATE(transaction_date) AS d FROM fuel_transactions WHERE station_id=? ORDER BY transaction_date DESC LIMIT 1");
    $dr->execute([$station_id]);
    $dr_row = $dr->fetch(PDO::FETCH_ASSOC);
    if ($dr_row && $dr_row['d']) $default_date = $dr_row['d'];
} catch (Exception $e) {}
$report_date = trim($_GET['report_date'] ?? $default_date);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date)) $report_date = $default_date;
$active_tab = strtolower(trim($_GET['tab'] ?? $_GET['type'] ?? 'fuel'));
if (!in_array($active_tab, ['fuel', 'merchandise'], true)) {
    $active_tab = 'fuel';
}

// Helper: Check table existence
function table_exists($pdo, $table) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function column_exists($pdo, $table, $column) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Normalize various shift_period values to Shift 1 (true) or Shift 2 (false).
 * Handles: 'Shift 1','Shift1','First Shift','1st','Morning','General','Day' → shift1
 *          'Shift 2','Shift2','Second Shift','2nd','Evening','Afternoon','Night' → shift2
 */
function is_shift1(string $shift): bool {
    $s = strtolower(trim($shift));
    $shift1_keywords = ['shift 1','shift1','first','1st','morning','day','general','am'];
    $shift2_keywords = ['shift 2','shift2','second','2nd','evening','afternoon','night','pm'];
    foreach ($shift2_keywords as $kw) {
        if (strpos($s, $kw) !== false) return false;
    }
    foreach ($shift1_keywords as $kw) {
        if (strpos($s, $kw) !== false) return true;
    }
    // Fallback: if it contains digit '2' treat as shift2, else shift1
    return strpos($s, '2') === false;
}

function staff_report_fuel_display_name($fuel_type): string {
    $name = trim((string)$fuel_type);
    $normalized = strtoupper(preg_replace('/\s+/', ' ', $name));
    
    // Remove pump/nozzle numbers pattern like "DIESEL 1 - 1" → "DIESEL"
    // Patterns: "DIESEL 1 - 1", "TURBO DIESEL - 1", "XCS PLUS - 2", "XTRA UNL 1 - 2"
    $name = preg_replace('/\s+\d+\s*-\s*\d+$/', '', $name); // Remove " 1 - 1", " 2 - 3" at end
    $name = preg_replace('/\s*-\s*\d+$/', '', $name); // Remove " - 1", " - 2" at end
    $name = trim($name);
    
    $normalized = strtoupper(preg_replace('/\s+/', ' ', $name));
    if (strpos($normalized, 'TURBO') !== false && strpos($normalized, 'DIESEL') !== false) {
        return 'Turbo Diesel';
    }
    if (strpos($normalized, 'KEROSENE') !== false) {
        return 'Kerosene';
    }
    if (strpos($normalized, 'XCS') !== false) {
        return 'XCS Plus';
    }
    if (strpos($normalized, 'XTRA') !== false && strpos($normalized, 'UNL') !== false) {
        return 'Xtra UNL';
    }
    if (strpos($normalized, 'DIESEL') !== false) {
        return 'Diesel';
    }
    return $name !== '' ? $name : 'Fuel';
}

function staff_report_user_display_sql(PDO $pdo, string $alias): string {
    $parts = [];
    if (column_exists($pdo, 'users', 'first_name') && column_exists($pdo, 'users', 'last_name')) {
        $parts[] = "NULLIF(TRIM(CONCAT(COALESCE($alias.first_name,''),' ',COALESCE($alias.last_name,''))), '')";
    }
    if (column_exists($pdo, 'users', 'name')) {
        $parts[] = "NULLIF($alias.name, '')";
    }
    if (column_exists($pdo, 'users', 'username')) {
        $parts[] = "NULLIF($alias.username, '')";
    }
    $parts[] = "'N/A'";
    return 'COALESCE(' . implode(', ', $parts) . ')';
}

function staff_report_valid_merchandise_where(PDO $pdo, string $alias = 'mt'): string {
    $checks = [];
    if (column_exists($pdo, 'merchandise_transactions', 'validation_status')) {
        $checks[] = "LOWER(COALESCE($alias.validation_status, '')) NOT IN ('voided','rejected','cancelled','canceled')";
    }
    if (column_exists($pdo, 'merchandise_transactions', 'workflow_status')) {
        $checks[] = "LOWER(COALESCE($alias.workflow_status, '')) NOT IN ('voided','rejected','cancelled','canceled')";
    }
    if (column_exists($pdo, 'merchandise_transactions', 'void_reason')) {
        $checks[] = "($alias.void_reason IS NULL OR TRIM($alias.void_reason) = '')";
    }
    return $checks ? implode(' AND ', $checks) : '1=1';
}

function staff_report_line_amount_sql(string $itemAlias = 'mti', string $txAlias = 'mt', string $sumAlias = 'mis'): string {
    return "ROUND(COALESCE($itemAlias.subtotal, COALESCE($itemAlias.quantity, 0) * COALESCE($itemAlias.unit_price, 0), 0) * CASE WHEN COALESCE($sumAlias.item_subtotal, 0) > 0 AND COALESCE($txAlias.total_amount, 0) > 0 THEN $txAlias.total_amount / $sumAlias.item_subtotal ELSE 1 END, 2)";
}

function staff_report_fetch_merchandise_rows(PDO $pdo, int $station_id, string $report_date): array {
    if (!table_exists($pdo, 'merchandise_transactions')) {
        return [];
    }

    $encoderSql = table_exists($pdo, 'users') ? staff_report_user_display_sql($pdo, 'u') : "'N/A'";
    $validWhere = staff_report_valid_merchandise_where($pdo, 'mt');

    if (table_exists($pdo, 'merchandise_transaction_items')) {
        $lineAmountSql = staff_report_line_amount_sql('mti', 'mt');
        $stmt = $pdo->prepare("
            SELECT
                mt.id,
                COALESCE(NULLIF(mti.category, ''), 'General') AS category,
                COALESCE(NULLIF(mti.product_name, ''), mt.item_sku, 'Item') AS product_name,
                COALESCE(mti.quantity, mt.quantity, 0) AS stock_out,
                COALESCE(mti.unit_price, mt.unit_price, 0) AS unit_price,
                CASE WHEN mti.id IS NOT NULL THEN $lineAmountSql ELSE COALESCE(mt.total_amount, 0) END AS total_amount,
                COALESCE(mt.shift_period, '') AS shift,
                $encoderSql AS encoder,
                COALESCE(mt.payment_method, 'Cash') AS payment_method,
                COALESCE(mt.transaction_date, mt.created_at) AS created_at
            FROM merchandise_transactions mt
            LEFT JOIN (
                SELECT transaction_id,
                       SUM(COALESCE(subtotal, COALESCE(quantity, 0) * COALESCE(unit_price, 0), 0)) AS item_subtotal
                FROM merchandise_transaction_items
                GROUP BY transaction_id
            ) mis ON mis.transaction_id = mt.id
            LEFT JOIN merchandise_transaction_items mti
                   ON mti.transaction_id = mt.id
                  AND LOWER(COALESCE(mti.item_type, 'merchandise')) <> 'service'
            LEFT JOIN users u ON mt.staff_id = u.id
            WHERE mt.station_id = ?
              AND (DATE(mt.transaction_date) = ? OR DATE(mt.created_at) = ?)
              AND $validWhere
              AND (
                    mti.id IS NOT NULL
                    OR LOWER(COALESCE(mt.transaction_type, 'merchandise')) NOT IN ('job_order','combined')
                  )
            ORDER BY category, COALESCE(mt.transaction_date, mt.created_at), mt.id
        ");
        $stmt->execute([$station_id, $report_date, $report_date]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $stmt = $pdo->prepare("
        SELECT
            mt.id,
            'General' AS category,
            COALESCE(NULLIF(mt.item_sku, ''), 'Item') AS product_name,
            COALESCE(mt.quantity, 0) AS stock_out,
            COALESCE(mt.unit_price, 0) AS unit_price,
            COALESCE(mt.total_amount, 0) AS total_amount,
            COALESCE(mt.shift_period, '') AS shift,
            $encoderSql AS encoder,
            COALESCE(mt.payment_method, 'Cash') AS payment_method,
            COALESCE(mt.transaction_date, mt.created_at) AS created_at
        FROM merchandise_transactions mt
        LEFT JOIN users u ON mt.staff_id = u.id
        WHERE mt.station_id = ?
          AND (DATE(mt.transaction_date) = ? OR DATE(mt.created_at) = ?)
          AND $validWhere
          AND LOWER(COALESCE(mt.transaction_type, 'merchandise')) NOT IN ('job_order','combined')
        ORDER BY COALESCE(mt.transaction_date, mt.created_at), mt.id
    ");
    $stmt->execute([$station_id, $report_date, $report_date]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function staff_report_fetch_service_income_rows(PDO $pdo, int $station_id, string $report_date): array {
    $rows = [];
    $nativeJobOrderIds = [];
    $encoderSql = table_exists($pdo, 'users') ? staff_report_user_display_sql($pdo, 'u') : "'N/A'";

    if (table_exists($pdo, 'merchandise_transactions')) {
        $validWhere = staff_report_valid_merchandise_where($pdo, 'mt');
        $jobOrderFilter = "(
            LOWER(COALESCE(mt.transaction_type, '')) IN ('job_order','combined')
            OR NULLIF(TRIM(COALESCE(mt.job_order_service, '')), '') IS NOT NULL
            OR mt.job_order_id IS NOT NULL
            OR mt.job_order_db_id IS NOT NULL
        )";

        if (table_exists($pdo, 'merchandise_transaction_items')) {
            $lineAmountSql = staff_report_line_amount_sql('mti', 'mt');
            $stmt = $pdo->prepare("
                SELECT
                    CONCAT('mt-service-', mt.id, '-', mti.id) AS source_key,
                    mt.id,
                    mt.job_order_db_id AS native_job_order_id,
                    COALESCE(NULLIF(mt.job_order_service, ''), NULLIF(mti.product_name, ''), 'Service') AS service_type,
                    COALESCE(mti.subtotal, COALESCE(mti.quantity, 0) * COALESCE(mti.unit_price, 0), 0) AS labor_fee,
                    (
                        SELECT GROUP_CONCAT(CONCAT(mi.product_name, ' (x', FORMAT(mi.quantity, 0), ')') ORDER BY mi.id SEPARATOR ', ')
                        FROM merchandise_transaction_items mi
                        WHERE mi.transaction_id = mt.id
                          AND LOWER(COALESCE(mi.item_type, 'merchandise')) <> 'service'
                    ) AS parts_used,
                    $lineAmountSql AS total_amount,
                    COALESCE(mt.shift_period, '') AS shift,
                    $encoderSql AS encoder,
                    COALESCE(mt.payment_method, 'Cash') AS payment_method,
                    COALESCE(mt.transaction_date, mt.created_at) AS created_at
                FROM merchandise_transactions mt
                LEFT JOIN (
                    SELECT transaction_id,
                           SUM(COALESCE(subtotal, COALESCE(quantity, 0) * COALESCE(unit_price, 0), 0)) AS item_subtotal
                    FROM merchandise_transaction_items
                    GROUP BY transaction_id
                ) mis ON mis.transaction_id = mt.id
                INNER JOIN merchandise_transaction_items mti
                        ON mti.transaction_id = mt.id
                       AND LOWER(COALESCE(mti.item_type, 'merchandise')) = 'service'
                LEFT JOIN users u ON mt.staff_id = u.id
                WHERE mt.station_id = ?
                  AND (DATE(mt.transaction_date) = ? OR DATE(mt.created_at) = ?)
                  AND $validWhere
                  AND $jobOrderFilter
                ORDER BY COALESCE(mt.transaction_date, mt.created_at), mt.id, mti.id
            ");
            $stmt->execute([$station_id, $report_date, $report_date]);
            $rows = array_merge($rows, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        }

        $fallbackNotExists = table_exists($pdo, 'merchandise_transaction_items')
            ? "AND NOT EXISTS (
                    SELECT 1
                    FROM merchandise_transaction_items mi
                    WHERE mi.transaction_id = mt.id
                      AND LOWER(COALESCE(mi.item_type, 'merchandise')) = 'service'
               )"
            : '';
        $partsSql = table_exists($pdo, 'merchandise_transaction_items')
            ? "(
                    SELECT GROUP_CONCAT(CONCAT(mi.product_name, ' (x', FORMAT(mi.quantity, 0), ')') ORDER BY mi.id SEPARATOR ', ')
                    FROM merchandise_transaction_items mi
                    WHERE mi.transaction_id = mt.id
                      AND LOWER(COALESCE(mi.item_type, 'merchandise')) <> 'service'
               )"
            : "NULL";

        $stmt = $pdo->prepare("
            SELECT
                CONCAT('mt-job-', mt.id) AS source_key,
                mt.id,
                mt.job_order_db_id AS native_job_order_id,
                COALESCE(NULLIF(mt.job_order_service, ''), NULLIF(mt.item_sku, ''), 'Service') AS service_type,
                COALESCE(NULLIF(mt.subtotal_amount, 0), NULLIF(mt.unit_price, 0), mt.total_amount, 0) AS labor_fee,
                $partsSql AS parts_used,
                COALESCE(mt.total_amount, 0) AS total_amount,
                COALESCE(mt.shift_period, '') AS shift,
                $encoderSql AS encoder,
                COALESCE(mt.payment_method, 'Cash') AS payment_method,
                COALESCE(mt.transaction_date, mt.created_at) AS created_at
            FROM merchandise_transactions mt
            LEFT JOIN users u ON mt.staff_id = u.id
            WHERE mt.station_id = ?
              AND (DATE(mt.transaction_date) = ? OR DATE(mt.created_at) = ?)
              AND $validWhere
              AND $jobOrderFilter
              $fallbackNotExists
            ORDER BY COALESCE(mt.transaction_date, mt.created_at), mt.id
        ");
        $stmt->execute([$station_id, $report_date, $report_date]);
        $rows = array_merge($rows, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

        foreach ($rows as $row) {
            $nativeId = (int)($row['native_job_order_id'] ?? 0);
            if ($nativeId > 0) {
                $nativeJobOrderIds[$nativeId] = true;
            }
        }
    }

    if (table_exists($pdo, 'job_orders')) {
        $joEncoderColumn = column_exists($pdo, 'job_orders', 'created_by') ? 'created_by' : (column_exists($pdo, 'job_orders', 'user_id') ? 'user_id' : 'created_by');
        $serviceSql = column_exists($pdo, 'job_orders', 'service_type')
            ? "COALESCE(NULLIF(jo.service_type, ''), NULLIF(jo.service_description, ''), 'Service')"
            : "COALESCE(NULLIF(jo.service_description, ''), 'Service')";
        $laborSql = "COALESCE(NULLIF(jo.actual_labor_cost, 0), NULLIF(jo.estimated_labor_cost, 0), 0)";
        $amountSql = "COALESCE(NULLIF(jo.total_cost, 0), NULLIF(jo.estimated_cost, 0), NULLIF(jo.amount_paid, 0), NULLIF(COALESCE(jo.actual_labor_cost, 0) + COALESCE(jo.actual_parts_cost, 0), 0), NULLIF(COALESCE(jo.estimated_labor_cost, 0) + COALESCE(jo.estimated_parts_cost, 0), 0), 0)";
        $partsSql = table_exists($pdo, 'job_order_parts')
            ? "(
                    SELECT GROUP_CONCAT(CONCAT(COALESCE(ip.product_name, CONCAT('Part #', jop.product_id)), ' (x', jop.quantity_used, ')') ORDER BY jop.id SEPARATOR ', ')
                    FROM job_order_parts jop
                    LEFT JOIN inventory_products ip ON ip.id = jop.product_id
                    WHERE jop.job_order_id = jo.id
               )"
            : "NULL";

        $stmt = $pdo->prepare("
            SELECT
                CONCAT('jo-', jo.id) AS source_key,
                jo.id,
                jo.id AS native_job_order_id,
                $serviceSql AS service_type,
                $laborSql AS labor_fee,
                $partsSql AS parts_used,
                $amountSql AS total_amount,
                CASE WHEN HOUR(jo.created_at) >= 6 AND HOUR(jo.created_at) < 14 THEN 'Shift 1' ELSE 'Shift 2' END AS shift,
                $encoderSql AS encoder,
                COALESCE(jo.payment_method, 'Cash') AS payment_method,
                jo.created_at
            FROM job_orders jo
            LEFT JOIN users u ON jo.$joEncoderColumn = u.id
            WHERE jo.station_id = ?
              AND DATE(jo.created_at) = ?
              AND LOWER(COALESCE(jo.status, '')) NOT IN ('cancelled','canceled','rejected')
              AND LOWER(COALESCE(jo.validation_status, '')) NOT IN ('voided','rejected','cancelled','canceled')
            ORDER BY jo.created_at, jo.id
        ");
        $stmt->execute([$station_id, $report_date]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $nativeId = (int)($row['native_job_order_id'] ?? 0);
            if ($nativeId > 0 && isset($nativeJobOrderIds[$nativeId])) {
                continue;
            }
            $rows[] = $row;
        }
    }

    if (table_exists($pdo, 'service_transactions')) {
        try {
            $stmt = $pdo->prepare("
                SELECT
                    CONCAT('st-', st.id) AS source_key,
                    st.id,
                    NULL AS native_job_order_id,
                    st.service_type,
                    st.labor_fee,
                    st.parts_used,
                    st.total_amount,
                    COALESCE(st.shift, '') AS shift,
                    $encoderSql AS encoder,
                    'Cash' AS payment_method,
                    st.created_at
                FROM service_transactions st
                LEFT JOIN users u ON st.user_id = u.id
                WHERE st.station_id = ? AND DATE(st.created_at) = ?
                ORDER BY st.created_at, st.id
            ");
            $stmt->execute([$station_id, $report_date]);
            $rows = array_merge($rows, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Exception $e) {}
    }

    usort($rows, static function (array $left, array $right): int {
        return strcmp((string)($left['created_at'] ?? ''), (string)($right['created_at'] ?? ''));
    });

    return $rows;
}

function staff_report_payment_bucket(string $payment): string {
    $mode = strtolower(trim($payment));
    if ($mode === '' || in_array($mode, ['cash', 'cash payment'], true)) {
        return 'cash';
    }
    if (in_array($mode, ['card', 'credit card', 'debit card'], true)) {
        return 'card';
    }
    if (in_array($mode, ['gcash', 'maya', 'e-wallet', 'ewallet'], true)) {
        return 'ewallet';
    }
    return 'credit';
}

function staff_report_apply_payment_bucket(array &$summary, float $amount, string $payment): void {
    $summary[staff_report_payment_bucket($payment)] += $amount;
}

function staff_report_empty_ar_shift_summary(): array {
    return ['shift1' => 0, 'shift2' => 0, 'total' => 0];
}

function staff_report_add_ar_shift_amount(array &$summary, array $row): void {
    if (staff_report_payment_bucket((string)($row['payment_method'] ?? '')) !== 'credit') {
        return;
    }
    $amount = (float)($row['total_amount'] ?? $row['amount'] ?? 0);
    if ($amount <= 0) {
        return;
    }

    $shiftLabel = (string)($row['shift'] ?? $row['shift_period'] ?? '');
    if ($shiftLabel === '' && !empty($row['created_at'])) {
        $hour = (int)date('H', strtotime((string)$row['created_at']));
        $shiftLabel = ($hour >= 6 && $hour < 14) ? 'Shift 1' : 'Shift 2';
    }
    if (is_shift1($shiftLabel)) {
        $summary['shift1'] += $amount;
    } else {
        $summary['shift2'] += $amount;
    }
    $summary['total'] = $summary['shift1'] + $summary['shift2'];
}

function staff_report_empty_shift_summary(): array {
    return [
        'fuel_sales' => 0,
        'merchandise_sales' => 0,
        'service_income' => 0,
        'total_sales' => 0,
        'cash' => 0,
        'card' => 0,
        'ewallet' => 0,
        'credit' => 0,
    ];
}

function staff_report_add_shift_sale(array &$shift1_summary, array &$shift2_summary, array $row, string $kind): void {
    $amount = (float)($row['total_amount'] ?? 0);
    if ($amount <= 0) {
        return;
    }

    $shiftLabel = (string)($row['shift'] ?? $row['shift_period'] ?? '');
    if ($shiftLabel === '' && !empty($row['created_at'])) {
        $hour = (int)date('H', strtotime((string)$row['created_at']));
        $shiftLabel = ($hour >= 6 && $hour < 14) ? 'Shift 1' : 'Shift 2';
    }

    $target = is_shift1($shiftLabel) ? $shift1_summary : $shift2_summary;
    if ($kind === 'fuel') {
        $target['fuel_sales'] += $amount;
    } elseif ($kind === 'service') {
        $target['service_income'] += $amount;
    } else {
        $target['merchandise_sales'] += $amount;
    }
    staff_report_apply_payment_bucket($target, $amount, (string)($row['payment_method'] ?? 'Cash'));

    if (is_shift1($shiftLabel)) {
        $shift1_summary = $target;
    } else {
        $shift2_summary = $target;
    }
}

function staff_report_fuel_price_amount(array $reading, array $volume_sales): array {
    $liters = (float)($reading['liters_sold'] ?? 0);
    $price = (float)($reading['unit_price'] ?? $reading['price_per_liter'] ?? 0);
    $amount = (float)($reading['amount'] ?? $reading['total_amount'] ?? 0);

    if ($price > 0 && $amount <= 0) {
        $amount = $liters * $price;
    }
    if ($price <= 0 && $liters > 0 && $amount > 0) {
        $price = $amount / $liters;
    }

    if ($price <= 0 || $amount <= 0) {
        $readingFuel = strtolower(trim((string)($reading['fuel_type'] ?? '')));
        foreach ($volume_sales as $vol) {
            if ($readingFuel !== strtolower(trim((string)($vol['fuel_type'] ?? '')))) {
                continue;
            }
            $fallbackPrice = (float)($vol['avg_price'] ?? 0);
            if ($price <= 0) {
                $price = $fallbackPrice;
            }
            if ($amount <= 0) {
                $amount = $liters * $fallbackPrice;
            }
            break;
        }
    }

    return [$price, $amount];
}

function staff_report_current_fuel_price_map(PDO $pdo, int $station_id): array {
    $prices = [];

    if (table_exists($pdo, 'fuel_inventory') && column_exists($pdo, 'fuel_inventory', 'price_per_liter')) {
        try {
            $stmt = $pdo->prepare("
                SELECT fuel_type, COALESCE(price_per_liter, 0) AS price_per_liter
                FROM fuel_inventory
                WHERE station_id = ?
                ORDER BY id
            ");
            $stmt->execute([$station_id]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $price = (float)($row['price_per_liter'] ?? 0);
                if ($price <= 0) continue;
                $fuel = staff_report_fuel_display_name($row['fuel_type'] ?? '');
                $key = strtolower($fuel);
                if (!isset($prices[$key]) || $prices[$key] <= 0) {
                    $prices[$key] = $price;
                }
            }
        } catch (Exception $e) {}
    }

    if (table_exists($pdo, 'fuel_types') && column_exists($pdo, 'fuel_types', 'price_per_liter')) {
        try {
            $stmt = $pdo->query("
                SELECT name AS fuel_type, COALESCE(price_per_liter, 0) AS price_per_liter
                FROM fuel_types
                ORDER BY id
            ");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $price = (float)($row['price_per_liter'] ?? 0);
                if ($price <= 0) continue;
                $fuel = staff_report_fuel_display_name($row['fuel_type'] ?? '');
                $key = strtolower($fuel);
                if (!isset($prices[$key]) || $prices[$key] <= 0) {
                    $prices[$key] = $price;
                }
            }
        } catch (Exception $e) {}
    }

    return $prices;
}

function staff_report_current_fuel_price(string $fuel_type, array $price_map, float $fallback = 0): float {
    $key = strtolower(staff_report_fuel_display_name($fuel_type));
    $price = (float)($price_map[$key] ?? 0);
    return $price > 0 ? $price : $fallback;
}

function staff_report_build_24h_meter_readings(array $readings, array $price_map): array {
    $groups = [];
    $order = 0;

    foreach ($readings as $reading) {
        $fuel = staff_report_fuel_display_name($reading['fuel_type'] ?? '');
        $pumpName = trim((string)($reading['pump_name'] ?? ''));
        if ($pumpName === '') {
            $pumpName = trim((string)($reading['pump_number'] ?? ''));
        }
        if ($pumpName === '') {
            $pumpName = trim((string)($reading['fuel_type'] ?? $fuel));
        }

        $pumpId = (int)($reading['pump_id'] ?? 0);
        $keyName = strtoupper(preg_replace('/\s+/', ' ', $pumpName));
        $key = $pumpId > 0 ? 'pump:' . $pumpId : 'name:' . $keyName . '|fuel:' . strtolower($fuel);

        if (!isset($groups[$key])) {
            $groups[$key] = [
                'id' => $reading['id'] ?? $order,
                'pump_id' => $pumpId,
                'pump_name' => $pumpName,
                'fuel_type' => $fuel,
                'first_order' => $order,
                'first_beginning' => null,
                'last_ending' => null,
                'shift1_beginning' => null,
                'shift2_ending' => null,
                'calibration' => 0.0,
                'unit_price' => 0.0,
                'status' => $reading['status'] ?? '',
                'encoded_at' => $reading['encoded_at'] ?? $reading['created_at'] ?? '',
            ];
        }

        $beginning = (float)($reading['beginning_reading'] ?? $reading['previous_reading'] ?? 0);
        $ending = (float)($reading['ending_reading'] ?? $reading['present_reading'] ?? 0);
        $calibration = (float)($reading['calibration'] ?? 0);
        $fallbackPrice = (float)($reading['unit_price'] ?? $reading['price_per_liter'] ?? 0);
        $price = staff_report_current_fuel_price($fuel, $price_map, $fallbackPrice);

        if ($groups[$key]['first_beginning'] === null) {
            $groups[$key]['first_beginning'] = $beginning;
        }
        $groups[$key]['last_ending'] = $ending;
        if (is_shift1((string)($reading['shift_period'] ?? $reading['shift'] ?? ''))) {
            if ($groups[$key]['shift1_beginning'] === null) {
                $groups[$key]['shift1_beginning'] = $beginning;
            }
        } else {
            $groups[$key]['shift2_ending'] = $ending;
        }
        $groups[$key]['calibration'] += $calibration;
        if ($price > 0) {
            $groups[$key]['unit_price'] = $price;
        }

        $order++;
    }

    uasort($groups, static fn($a, $b) => ($a['first_order'] ?? 0) <=> ($b['first_order'] ?? 0));

    $combined = [];
    foreach ($groups as $group) {
        $beginning = (float)($group['shift1_beginning'] ?? $group['first_beginning'] ?? 0);
        $ending = (float)($group['shift2_ending'] ?? $group['last_ending'] ?? 0);
        $calibration = (float)$group['calibration'];
        $liters = max($ending - $beginning - $calibration, 0);
        $price = (float)$group['unit_price'];
        $amount = round($liters * $price, 2);

        $combined[] = [
            'id' => $group['id'],
            'pump_id' => $group['pump_id'],
            'pump_name' => $group['pump_name'],
            'fuel_type' => $group['fuel_type'],
            'beginning_reading' => $beginning,
            'ending_reading' => $ending,
            'calibration' => $calibration,
            'liters_sold' => $liters,
            'unit_price' => $price,
            'price_per_liter' => $price,
            'amount' => $amount,
            'total_amount' => $amount,
            'shift_period' => '24-hour',
            'status' => $group['status'],
            'encoded_at' => $group['encoded_at'],
        ];
    }

    return $combined;
}

function staff_report_build_volume_sales_from_meter_rows(array $meter_readings): array {
    $summary = [];

    foreach ($meter_readings as $reading) {
        $fuel = staff_report_fuel_display_name($reading['fuel_type'] ?? '');
        if (!isset($summary[$fuel])) {
            $summary[$fuel] = [
                'fuel_type' => $fuel,
                'total_liters' => 0.0,
                'avg_price' => 0.0,
                'total_amount' => 0.0,
            ];
        }

        $liters = (float)($reading['liters_sold'] ?? 0);
        $amount = (float)($reading['amount'] ?? $reading['total_amount'] ?? 0);
        $summary[$fuel]['total_liters'] += $liters;
        $summary[$fuel]['total_amount'] += $amount;
    }

    foreach ($summary as &$row) {
        $row['avg_price'] = $row['total_liters'] > 0
            ? $row['total_amount'] / $row['total_liters']
            : 0;
    }
    unset($row);

    return array_values($summary);
}

// Check available tables
$has_fuel_readings = table_exists($pdo, 'fuel_readings');
$has_fuel_transactions = table_exists($pdo, 'fuel_transactions');
$has_payments = table_exists($pdo, 'payments');
$has_customers = table_exists($pdo, 'customers');
$has_fuel_types = table_exists($pdo, 'fuel_types');
$has_fuel_pumps = table_exists($pdo, 'fuel_pumps');
$has_merchandise_transactions = table_exists($pdo, 'merchandise_transactions');
$current_fuel_prices = staff_report_current_fuel_price_map($pdo, (int)$station_id);

// Initialize data structures
$meter_readings = [];
$fuel_transactions = [];
$volume_sales = [];
$tank_sales = [];
$ar_shift_summary = staff_report_empty_ar_shift_summary();
$shift1_summary = [
    'fuel_sales' => 0,
    'merchandise_sales' => 0,
    'service_income' => 0,
    'total_sales' => 0,
    'cash' => 0,
    'card' => 0,
    'ewallet' => 0,
    'credit' => 0,
];
$shift2_summary = [
    'fuel_sales' => 0,
    'merchandise_sales' => 0,
    'service_income' => 0,
    'total_sales' => 0,
    'cash' => 0,
    'card' => 0,
    'ewallet' => 0,
    'credit' => 0,
];
$ar_summary = [];
$overall_summary = [
    'total_fuel_sales' => 0,
    'total_merchandise_sales' => 0,
    'total_liters' => 0,
    'total_cash' => 0,
    'total_deposits' => 0,
    'total_ar' => 0,
];

$error_message = null;

// ============================================================
// DATA SOURCE 1: METER READINGS TABLE
// ============================================================
if ($has_fuel_readings) {
    try {
        $sql = "SELECT 
                    fr.id,
                    fr.pump_number,
                    NULL AS pump_id,
                    ";
        
        if ($has_fuel_pumps && $has_fuel_types) {
            $sql .= "COALESCE(NULLIF(fp.pump_number, ''), NULLIF(fr.pump_number, ''), ft.name, fr.fuel_type) AS pump_name, ";
        } elseif ($has_fuel_pumps) {
            $sql .= "COALESCE(NULLIF(fp.pump_number, ''), NULLIF(fr.pump_number, ''), fr.fuel_type) AS pump_name, ";
        } elseif ($has_fuel_types) {
            $sql .= "COALESCE(NULLIF(fr.pump_number, ''), ft.name, fr.fuel_type) AS pump_name, ";
        } else {
            $sql .= "COALESCE(NULLIF(fr.pump_number, ''), fr.fuel_type) AS pump_name, ";
        }
        
        if ($has_fuel_types) {
            $sql .= "COALESCE(ft.name, fr.fuel_type) AS fuel_type, ";
        } else {
            $sql .= "fr.fuel_type, ";
        }
        
        $sql .= "fr.previous_reading AS beginning_reading,
                 fr.present_reading AS ending_reading,
                 fr.difference AS liters_sold,
                 0.00 AS calibration,
                 fr.shift_period,
                 fr.status,
                 fr.encoded_at
            FROM fuel_readings fr ";
        
        if ($has_fuel_pumps) {
            $sql .= "LEFT JOIN fuel_pumps fp ON fr.pump_number = fp.pump_number AND fp.station_id = fr.station_id ";
        }
        
        if ($has_fuel_types) {
            $sql .= "LEFT JOIN fuel_types ft ON fr.fuel_type = ft.id ";
        }
        
        $sql .= "WHERE fr.station_id = ? AND DATE(fr.encoded_at) = ?
                 ORDER BY fr.encoded_at, fr.id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$station_id, $report_date]);
        $meter_readings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $error_message = "Error fetching meter readings: " . $e->getMessage();
    }
}

// If no meter readings exist, use each fuel transaction as one report row.
if (count($meter_readings) == 0 && $has_fuel_transactions) {
    try {
        $sql = "SELECT 
                    ft.id,
                    ft.pump_id,
                    COALESCE(NULLIF(fp.pump_number, ''), ft.fuel_type) AS pump_name,
                    ft.fuel_type,
                    COALESCE(ft.previous_reading, 0) AS beginning_reading,
                    COALESCE(ft.present_reading, 0) AS ending_reading,
                    COALESCE(ft.liters_sold, GREATEST(COALESCE(ft.present_reading, 0) - COALESCE(ft.previous_reading, 0) - COALESCE(ft.calibration, 0), 0)) AS liters_sold,
                    COALESCE(ft.calibration, 0) AS calibration,
                    COALESCE(ft.shift_period, 'Shift 1') AS shift_period,
                    COALESCE(ft.status, 'Completed') AS status,
                    ft.transaction_date AS encoded_at,
                    ft.transaction_id,
                    COALESCE(ft.price_per_liter, 0) AS unit_price,
                    COALESCE(ft.total_amount, 0) AS amount
            FROM fuel_transactions ft
            LEFT JOIN fuel_pumps fp ON fp.id = ft.pump_id AND fp.station_id = ft.station_id
            WHERE ft.station_id = ? AND DATE(ft.transaction_date) = ?
              AND LOWER(COALESCE(ft.status, '')) NOT IN ('voided','rejected','cancelled','canceled')
            ORDER BY ft.transaction_date, ft.id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$station_id, $report_date]);
        $meter_readings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {}
}

foreach ($meter_readings as &$reading) {
    $reading['fuel_type'] = staff_report_fuel_display_name($reading['fuel_type'] ?? '');
}
unset($reading);
$meter_readings = staff_report_build_24h_meter_readings($meter_readings, $current_fuel_prices);

// ============================================================
// DATA SOURCE 2: FUEL TRANSACTIONS TABLE
// ============================================================
if ($has_fuel_transactions) {
    try {
        $sql = "SELECT 
                    ft.id,
                    ft.pump_id,
                    ft.fuel_type,
                    COALESCE(ft.previous_reading, 0) AS previous_reading,
                    COALESCE(ft.present_reading, 0) AS present_reading,
                    COALESCE(ft.calibration, 0) AS calibration,
                    ft.liters_sold,
                    ft.price_per_liter AS unit_price,
                    ft.total_amount,
                    ft.payment_method,
                    ft.shift_period AS shift,
                    ft.transaction_date AS created_at
            FROM fuel_transactions ft
            WHERE ft.station_id = ? AND DATE(ft.transaction_date) = ?
              AND LOWER(COALESCE(ft.status, '')) NOT IN ('voided','rejected','cancelled','canceled')
            ORDER BY ft.transaction_date, ft.id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$station_id, $report_date]);
        $fuel_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($fuel_transactions as &$trans) {
            $trans['fuel_type'] = staff_report_fuel_display_name($trans['fuel_type'] ?? '');
            $liters = (float)($trans['liters_sold'] ?? 0);
            if ($liters <= 0) {
                $liters = max(
                    (float)($trans['present_reading'] ?? 0)
                    - (float)($trans['previous_reading'] ?? 0)
                    - (float)($trans['calibration'] ?? 0),
                    0
                );
            }
            $storedAmount = (float)($trans['total_amount'] ?? 0);
            $fallbackPrice = (float)($trans['unit_price'] ?? 0);
            if ($fallbackPrice <= 0 && $liters > 0 && $storedAmount > 0) {
                $fallbackPrice = $storedAmount / $liters;
            }
            $price = staff_report_current_fuel_price($trans['fuel_type'], $current_fuel_prices, $fallbackPrice);
            $trans['liters_sold'] = $liters;
            $trans['unit_price'] = $price;
            $trans['price_per_liter'] = $price;
            $trans['total_amount'] = round($liters * $price, 2);
        }
        unset($trans);
        foreach ($fuel_transactions as $trans) {
            staff_report_add_ar_shift_amount($ar_shift_summary, $trans);
        }
        
        // Group by fuel type for volume sales summary
        $volume_sales_temp = [];
        foreach ($fuel_transactions as $trans) {
            $fuel = $trans['fuel_type'];
            if (!isset($volume_sales_temp[$fuel])) {
                $volume_sales_temp[$fuel] = [
                    'fuel_type' => $fuel,
                    'total_liters' => 0,
                    'avg_price' => 0,
                    'total_amount' => 0,
                    'count' => 0,
                ];
            }
            $volume_sales_temp[$fuel]['total_liters'] += (float)$trans['liters_sold'];
            $volume_sales_temp[$fuel]['total_amount'] += (float)$trans['total_amount'];
            $volume_sales_temp[$fuel]['count']++;
        }
        
        // Calculate averages
        foreach ($volume_sales_temp as $fuel => $data) {
            $volume_sales_temp[$fuel]['avg_price'] = $data['total_liters'] > 0
                ? $data['total_amount'] / $data['total_liters']
                : 0;
        }
        
        $volume_sales = array_values($volume_sales_temp);
        
    } catch (Exception $e) {
        $error_message = "Error fetching fuel transactions: " . $e->getMessage();
    }
}
$volume_sales = staff_report_build_volume_sales_from_meter_rows($meter_readings);

// ============================================================
// DATA SOURCE 3: PAYMENTS TABLE & SHIFT SUMMARIES
// ============================================================
try {
    // Get fuel sales by shift
    if ($has_fuel_transactions) {
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(shift_period, 'Shift 1') AS shift_period,
                SUM(total_amount) AS total_amount,
                SUM(liters_sold) AS total_liters,
                payment_method,
                COUNT(*) AS transaction_count
            FROM fuel_transactions
            WHERE station_id = ? AND DATE(transaction_date) = ?
            GROUP BY shift_period, payment_method
        ");
        $stmt->execute([$station_id, $report_date]);
        $fuel_by_shift = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($fuel_by_shift as $row) {
            $amount   = (float)$row['total_amount'];
            $payment  = strtolower($row['payment_method'] ?? 'cash');
            $is_s1    = is_shift1($row['shift_period'] ?? '');
            $target   = $is_s1 ? 'shift1' : 'shift2';

            if ($is_s1) {
                $shift1_summary['fuel_sales'] += $amount;
                if (in_array($payment, ['cash','cash payment'])) $shift1_summary['cash'] += $amount;
                elseif (in_array($payment, ['card','credit card','debit card'])) $shift1_summary['card'] += $amount;
                elseif (in_array($payment, ['gcash','maya','e-wallet','ewallet'])) $shift1_summary['ewallet'] += $amount;
                else $shift1_summary['credit'] += $amount;
            } else {
                $shift2_summary['fuel_sales'] += $amount;
                if (in_array($payment, ['cash','cash payment'])) $shift2_summary['cash'] += $amount;
                elseif (in_array($payment, ['card','credit card','debit card'])) $shift2_summary['card'] += $amount;
                elseif (in_array($payment, ['gcash','maya','e-wallet','ewallet'])) $shift2_summary['ewallet'] += $amount;
                else $shift2_summary['credit'] += $amount;
            }
        }
    }
    
    // Get merchandise sales by shift
    if ($has_merchandise_transactions) {
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(shift_period, 'Shift 1') AS shift_period,
                SUM(total_amount) AS total_amount,
                payment_method,
                COUNT(*) AS transaction_count
            FROM merchandise_transactions
            WHERE station_id = ? AND DATE(created_at) = ?
            GROUP BY shift_period, payment_method
        ");
        $stmt->execute([$station_id, $report_date]);
        $merch_by_shift = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($merch_by_shift as $row) {
            $amount  = (float)$row['total_amount'];
            $payment = strtolower($row['payment_method'] ?? 'cash');
            $is_s1   = is_shift1($row['shift_period'] ?? '');

            if ($is_s1) {
                $shift1_summary['merchandise_sales'] += $amount;
                if (in_array($payment, ['cash','cash payment'])) $shift1_summary['cash'] += $amount;
                elseif (in_array($payment, ['card','credit card','debit card'])) $shift1_summary['card'] += $amount;
                elseif (in_array($payment, ['gcash','maya','e-wallet','ewallet'])) $shift1_summary['ewallet'] += $amount;
                else $shift1_summary['credit'] += $amount;
            } else {
                $shift2_summary['merchandise_sales'] += $amount;
                if (in_array($payment, ['cash','cash payment'])) $shift2_summary['cash'] += $amount;
                elseif (in_array($payment, ['card','credit card','debit card'])) $shift2_summary['card'] += $amount;
                elseif (in_array($payment, ['gcash','maya','e-wallet','ewallet'])) $shift2_summary['ewallet'] += $amount;
                else $shift2_summary['credit'] += $amount;
            }
        }
    }
    
    // Calculate totals
    $shift1_summary['total_sales'] = $shift1_summary['fuel_sales'] + $shift1_summary['merchandise_sales'];
    $shift2_summary['total_sales'] = $shift2_summary['fuel_sales'] + $shift2_summary['merchandise_sales'];
    
} catch (Exception $e) {
    $error_message = "Error calculating shift summaries: " . $e->getMessage();
}

// ============================================================
// DATA SOURCE 4: CUSTOMER ACCOUNTS - A/R SUMMARY
// ============================================================
if ($has_customers) {
    try {
        $customerBalanceExpr = column_exists($pdo, 'customers', 'account_balance')
            ? 'c.account_balance'
            : (column_exists($pdo, 'customers', 'current_balance')
                ? 'c.current_balance'
                : (column_exists($pdo, 'customers', 'balance') ? 'c.balance' : '0'));
        $stmt = $pdo->prepare("
            SELECT 
                c.id,
                c.name,
                c.contact_number,
                COALESCE($customerBalanceExpr, 0) AS balance,
                c.credit_limit,
                c.type
            FROM customers c
            WHERE c.station_id = ? 
              AND c.type IN ('credit', 'suki')
              AND COALESCE($customerBalanceExpr, 0) > 0
            ORDER BY balance DESC
        ");
        $stmt->execute([$station_id]);
        $ar_summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $error_message = "Error fetching A/R summary: " . $e->getMessage();
    }
}

if ($ar_shift_summary['total'] <= 0 && table_exists($pdo, 'accounts_receivable')) {
    try {
        $stmt = $pdo->prepare("
            SELECT
                amount,
                'credit' AS payment_method,
                CASE
                    WHEN TIME(created_at) >= '06:00:00' AND TIME(created_at) < '14:00:00' THEN 'Shift 1'
                    ELSE 'Shift 2'
                END AS shift_period,
                created_at
            FROM accounts_receivable
            WHERE station_id = ?
              AND DATE(created_at) = ?
              AND COALESCE(status, 'pending') IN ('pending', 'overdue')
        ");
        $stmt->execute([$station_id, $report_date]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $arRow) {
            staff_report_add_ar_shift_amount($ar_shift_summary, $arRow);
        }
    } catch (Exception $e) {}
}

// ============================================================
// TANK LITERS SUMMARY
// ============================================================
try {
    // Tank liters summary follows the same per-transaction rows as the meter table.
    $tankNo = 1;
    foreach ($meter_readings as $reading) {
        $fuel = staff_report_fuel_display_name($reading['fuel_type'] ?? '');
        $liters = (float)($reading['liters_sold'] ?? 0);
        $tank_sales[] = [
            'tank_name' => trim((string)($reading['pump_name'] ?? '')) !== '' ? $reading['pump_name'] : 'Tank ' . $tankNo,
            'fuel_type' => $fuel,
            'total_dispensed' => $liters,
            'total_liters' => $liters,
            'tank_capacity' => 0,
            'utilization' => 0,
        ];
        $tankNo++;
    }
} catch (Exception $e) {}

// ============================================================
// MERCHANDISE + SERVICE REPORT ROWS
// ============================================================
$merchandise_report_transactions = staff_report_fetch_merchandise_rows($pdo, (int)$station_id, $report_date);
$service_income_transactions = staff_report_fetch_service_income_rows($pdo, (int)$station_id, $report_date);
$total_merch_amount = array_sum(array_map(static fn($row) => (float)($row['total_amount'] ?? 0), $merchandise_report_transactions));
$total_service_amount = array_sum(array_map(static fn($row) => (float)($row['total_amount'] ?? 0), $service_income_transactions));

// Recompute displayed shift totals from the same rows used by the report tables.
$fuel_shift1_summary = staff_report_empty_shift_summary();
$fuel_shift2_summary = staff_report_empty_shift_summary();
$shift1_summary = staff_report_empty_shift_summary();
$shift2_summary = staff_report_empty_shift_summary();
foreach ($fuel_transactions as $row) {
    staff_report_add_shift_sale($fuel_shift1_summary, $fuel_shift2_summary, $row, 'fuel');
}
foreach ($merchandise_report_transactions as $row) {
    staff_report_add_shift_sale($shift1_summary, $shift2_summary, $row, 'merchandise');
}
foreach ($service_income_transactions as $row) {
    staff_report_add_shift_sale($shift1_summary, $shift2_summary, $row, 'service');
}
$fuel_shift1_summary['total_sales'] = $fuel_shift1_summary['fuel_sales'];
$fuel_shift2_summary['total_sales'] = $fuel_shift2_summary['fuel_sales'];
$shift1_summary['total_sales'] = $shift1_summary['fuel_sales'] + $shift1_summary['merchandise_sales'] + $shift1_summary['service_income'];
$shift2_summary['total_sales'] = $shift2_summary['fuel_sales'] + $shift2_summary['merchandise_sales'] + $shift2_summary['service_income'];

// ============================================================
// OVERALL DAILY SUMMARY
// ============================================================
$computed_fuel_total = array_sum(array_column($volume_sales, 'total_amount'));
$overall_summary['total_fuel_sales'] = $computed_fuel_total > 0
    ? $computed_fuel_total
    : ($fuel_shift1_summary['fuel_sales'] + $fuel_shift2_summary['fuel_sales']);
$overall_summary['total_merchandise_sales'] = $shift1_summary['merchandise_sales'] + $shift2_summary['merchandise_sales'];
$overall_summary['total_liters'] = array_sum(array_column($volume_sales, 'total_liters'));
$overall_summary['total_fuel_cash'] = $fuel_shift1_summary['cash'] + $fuel_shift2_summary['cash'];
$overall_summary['total_store_cash'] = $shift1_summary['cash'] + $shift2_summary['cash'];
$overall_summary['total_cash'] = $overall_summary['total_fuel_cash'] + $overall_summary['total_store_cash'];
$overall_summary['total_deposits'] = 0; // Would come from deposits table if available
$overall_summary['total_ar'] = $ar_shift_summary['total'];

// Ensure fuel transactions total matches
if (isset($fuel_transactions) && is_array($fuel_transactions) && count($fuel_transactions) > 0) {
    $direct_fuel_total = array_sum(array_column($fuel_transactions, 'total_amount'));
    // Use the direct total if shift summary is zero but we have transactions
    if ($overall_summary['total_fuel_sales'] == 0 && $direct_fuel_total > 0) {
        $overall_summary['total_fuel_sales'] = $direct_fuel_total;
    }
}

$page_title = "Fuel Sales Summary Report";

// ============================================================
// EXCEL EXPORT HANDLER
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $export_type = $_GET['type'] ?? 'fuel'; // fuel or merchandise
    
    header('Content-Type: application/vnd.ms-excel');
    if ($export_type === 'merchandise') {
        header('Content-Disposition: attachment;filename="Merchandise_Sales_Report_' . $report_date . '.xls"');
    } else {
        header('Content-Disposition: attachment;filename="Fuel_Sales_Report_' . $report_date . '.xls"');
    }
    header('Cache-Control: max-age=0');
    
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head>';
    echo '<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />';
    echo '<!--[if gte mso 9]>';
    echo '<xml>';
    echo '<x:ExcelWorkbook>';
    echo '<x:ExcelWorksheets>';
    echo '<x:ExcelWorksheet>';
    if ($export_type === 'merchandise') {
        echo '<x:Name>Merchandise Sales Report</x:Name>';
    } else {
        echo '<x:Name>Fuel Sales Report</x:Name>';
    }
    echo '<x:WorksheetOptions>';
    echo '<x:Print>';
    echo '<x:ValidPrinterInfo/>';
    echo '</x:Print>';
    echo '</x:WorksheetOptions>';
    echo '</x:ExcelWorksheet>';
    echo '</x:ExcelWorksheets>';
    echo '</x:ExcelWorkbook>';
    echo '</xml>';
    echo '<![endif]-->';
    echo '<style>';
    echo 'table { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; }';
    echo 'th, td { border: 1px solid #000000; padding: 8px; text-align: left; }';
    echo 'th { background-color: #E0E0E0; font-weight: bold; text-align: center; }';
    echo '.text-right { text-align: right; }';
    echo '.text-center { text-align: center; }';
    echo '.font-bold { font-weight: bold; }';
    echo 'h1 { font-size: 18px; font-weight: bold; margin: 10px 0; }';
    echo 'h2 { font-size: 14px; font-weight: bold; margin: 15px 0 10px 0; background-color: #F0F0F0; padding: 5px; border: 1px solid #000; }';
    echo 'h3 { font-size: 12px; font-weight: bold; margin: 10px 0 5px 0; }';
    echo 'p { margin: 5px 0; }';
    echo '.summary-table { background-color: #F9F9F9; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    
    if ($export_type === 'merchandise') {
        // MERCHANDISE & SERVICE SALES REPORT
        echo '<h1>DAILY MERCHANDISE & SERVICE SALES REPORT</h1>';
        echo '<h1 style="font-size: 16px;">24-HOUR SUMMARY</h1>';
        echo '<p>' . htmlspecialchars($station_name) . '</p>';
        echo '<p><strong>Date:</strong> ' . date('F d, Y', strtotime($report_date)) . '</p>';
        echo '<br/>';
        
        $merch_transactions = $merchandise_report_transactions;
        
        // MERCHANDISE SALES TABLE
        echo '<h2>MERCHANDISE SALES TABLE</h2>';
        echo '<table border="1" cellpadding="5" cellspacing="0">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Category</th>';
        echo '<th>Product Name</th>';
        echo '<th>Beginning Stock</th>';
        echo '<th>Stock-In</th>';
        echo '<th>Stock-Out</th>';
        echo '<th>Ending Stock</th>';
        echo '<th>Unit Price</th>';
        echo '<th>Amount</th>';
        echo '<th>Encoder</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        $total_merch_amount = 0;
        if (count($merch_transactions) > 0) {
            foreach ($merch_transactions as $trans) {
                $total_merch_amount += $trans['total_amount'];
                echo '<tr>';
                echo '<td>' . htmlspecialchars($trans['category']) . '</td>';
                echo '<td class="font-bold">' . htmlspecialchars($trans['product_name']) . '</td>';
                echo '<td class="text-right">—</td>';
                echo '<td class="text-right">—</td>';
                echo '<td class="text-right">' . number_format($trans['stock_out'], 2) . '</td>';
                echo '<td class="text-right">—</td>';
                echo '<td class="text-right">₱' . number_format($trans['unit_price'], 2) . '</td>';
                echo '<td class="text-right font-bold">₱' . number_format($trans['total_amount'], 2) . '</td>';
                echo '<td>' . htmlspecialchars($trans['encoder'] ?? 'N/A') . '</td>';
                echo '</tr>';
            }
            echo '<tr class="font-bold">';
            echo '<td colspan="7" class="text-right">TOTAL</td>';
            echo '<td class="text-right">₱' . number_format($total_merch_amount, 2) . '</td>';
            echo '<td></td>';
            echo '</tr>';
        } else {
            echo '<tr><td colspan="9" style="text-align: center; padding: 20px;">No merchandise sales found for this date.</td></tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
        echo '<br/>';
        
        // SERVICE INCOME TABLE
        $service_transactions = $service_income_transactions;
        
        echo '<h2>SERVICE INCOME TABLE</h2>';
        echo '<table border="1" cellpadding="5" cellspacing="0">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Service Type</th>';
        echo '<th>Labor Fee</th>';
        echo '<th>Parts Used</th>';
        echo '<th>Total Service Amount</th>';
        echo '<th>Encoder</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        $total_service_amount = 0;
        if (count($service_transactions) > 0) {
            foreach ($service_transactions as $trans) {
                $total_service_amount += $trans['total_amount'];
                echo '<tr>';
                echo '<td class="font-bold">' . htmlspecialchars($trans['service_type']) . '</td>';
                echo '<td class="text-right">₱' . number_format($trans['labor_fee'], 2) . '</td>';
                echo '<td>' . htmlspecialchars($trans['parts_used'] ?? '—') . '</td>';
                echo '<td class="text-right font-bold">₱' . number_format($trans['total_amount'], 2) . '</td>';
                echo '<td>' . htmlspecialchars($trans['encoder'] ?? 'N/A') . '</td>';
                echo '</tr>';
            }
            echo '<tr class="font-bold">';
            echo '<td colspan="3" class="text-right">TOTAL</td>';
            echo '<td class="text-right">₱' . number_format($total_service_amount, 2) . '</td>';
            echo '<td></td>';
            echo '</tr>';
        } else {
            echo '<tr><td colspan="5" style="text-align: center; padding: 20px;">No service income found for this date.</td></tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
        echo '<br/>';
        
        // SHIFT SUMMARIES
        echo '<h2>SHIFT 1 SALES SUMMARY (6AM-2PM)</h2>';
        echo '<table border="1" cellpadding="5" cellspacing="0" class="summary-table" style="width: 60%;">';
        echo '<tbody>';
        echo '<tr><td class="font-bold">Merchandise Sales:</td><td class="text-right font-bold">₱' . number_format($shift1_summary['merchandise_sales'], 2) . '</td></tr>';
        echo '<tr><td class="font-bold">Service Income:</td><td class="text-right font-bold">₱' . number_format($shift1_summary['service_income'] ?? 0, 2) . '</td></tr>';
        echo '<tr><td colspan="2">&nbsp;</td></tr>';
        echo '<tr><td colspan="2" class="font-bold">Payment Breakdown</td></tr>';
        echo '<tr><td>Cash:</td><td class="text-right">₱' . number_format($shift1_summary['cash'], 2) . '</td></tr>';
        echo '<tr><td>Card:</td><td class="text-right">₱' . number_format($shift1_summary['card'], 2) . '</td></tr>';
        echo '<tr><td>E-Wallet:</td><td class="text-right">₱' . number_format($shift1_summary['ewallet'], 2) . '</td></tr>';
        echo '<tr><td>Credit/Suki:</td><td class="text-right">₱' . number_format($shift1_summary['credit'], 2) . '</td></tr>';
        echo '</tbody>';
        echo '</table>';
        echo '<br/>';
        
        echo '<h2>SHIFT 2 SALES SUMMARY (2PM-12AM)</h2>';
        echo '<table border="1" cellpadding="5" cellspacing="0" class="summary-table" style="width: 60%;">';
        echo '<tbody>';
        echo '<tr><td class="font-bold">Merchandise Sales:</td><td class="text-right font-bold">₱' . number_format($shift2_summary['merchandise_sales'], 2) . '</td></tr>';
        echo '<tr><td class="font-bold">Service Income:</td><td class="text-right font-bold">₱' . number_format($shift2_summary['service_income'] ?? 0, 2) . '</td></tr>';
        echo '<tr><td colspan="2">&nbsp;</td></tr>';
        echo '<tr><td colspan="2" class="font-bold">Payment Breakdown</td></tr>';
        echo '<tr><td>Cash:</td><td class="text-right">₱' . number_format($shift2_summary['cash'], 2) . '</td></tr>';
        echo '<tr><td>Card:</td><td class="text-right">₱' . number_format($shift2_summary['card'], 2) . '</td></tr>';
        echo '<tr><td>E-Wallet:</td><td class="text-right">₱' . number_format($shift2_summary['ewallet'], 2) . '</td></tr>';
        echo '<tr><td>Credit/Suki:</td><td class="text-right">₱' . number_format($shift2_summary['credit'], 2) . '</td></tr>';
        echo '</tbody>';
        echo '</table>';
        echo '<br/>';
        
        // A/R SUMMARY
        if (count($ar_summary) > 0) {
            echo '<h2>A/R SUMMARY (Suki/Credit Customers)</h2>';
            echo '<table border="1" cellpadding="5" cellspacing="0">';
            echo '<thead>';
            echo '<tr>';
            echo '<th>Customer Name</th>';
            echo '<th>Contact Number</th>';
            echo '<th>Type</th>';
            echo '<th>Outstanding Balance</th>';
            echo '<th>Credit Limit</th>';
            echo '<th>Available Credit</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';
            
            $total_ar = 0;
            foreach ($ar_summary as $ar) {
                $total_ar += $ar['balance'];
                $available = $ar['credit_limit'] - $ar['balance'];
                echo '<tr>';
                echo '<td class="font-bold">' . htmlspecialchars($ar['name']) . '</td>';
                echo '<td>' . htmlspecialchars($ar['contact_number'] ?? '-') . '</td>';
                echo '<td>' . strtoupper($ar['type']) . '</td>';
                echo '<td class="text-right font-bold">₱' . number_format($ar['balance'], 2) . '</td>';
                echo '<td class="text-right">₱' . number_format($ar['credit_limit'], 2) . '</td>';
                echo '<td class="text-right">₱' . number_format($available, 2) . '</td>';
                echo '</tr>';
            }
            
            echo '<tr class="font-bold">';
            echo '<td colspan="3" class="text-right">TOTAL ACCOUNTS RECEIVABLE</td>';
            echo '<td class="text-right">₱' . number_format($total_ar, 2) . '</td>';
            echo '<td colspan="2"></td>';
            echo '</tr>';
            echo '</tbody>';
            echo '</table>';
            echo '<br/>';
        }
        
        // OVERALL SUMMARY
        echo '<h2>OVERALL DAILY MERCHANDISE SUMMARY</h2>';
        echo '<table border="1" cellpadding="5" cellspacing="0" class="summary-table" style="width: 60%;">';
        echo '<tbody>';
        echo '<tr><td><strong>Total Merchandise Sales:</strong></td><td class="text-right font-bold">₱' . number_format($overall_summary['total_merchandise_sales'], 2) . '</td></tr>';
        echo '<tr><td><strong>Total Service Income:</strong></td><td class="text-right font-bold">₱' . number_format($total_service_amount, 2) . '</td></tr>';
        echo '<tr><td><strong>Grand Total Sales:</strong></td><td class="text-right font-bold">₱' . number_format($overall_summary['total_merchandise_sales'] + $total_service_amount, 2) . '</td></tr>';
        echo '<tr><td><strong>Total Cash Collection:</strong></td><td class="text-right font-bold">₱' . number_format($overall_summary['total_cash'], 2) . '</td></tr>';
        echo '</tbody>';
        echo '</table>';
        echo '<br/>';
        
        // TOTAL CASH IN BANK
        echo '<h2>TOTAL CASH IN BANK</h2>';
        echo '<table border="1" cellpadding="5" cellspacing="0" class="summary-table" style="width: 60%;">';
        echo '<tbody>';
        echo '<tr><td class="font-bold">Cash on Hand:</td><td class="text-right font-bold">₱' . number_format($overall_summary['total_cash'], 2) . '</td></tr>';
        echo '<tr><td class="font-bold">Deposits Made Today:</td><td class="text-right font-bold">₱' . number_format($overall_summary['total_deposits'], 2) . '</td></tr>';
        echo '<tr><td class="font-bold" style="font-size: 14px;">TOTAL CASH IN BANK:</td><td class="text-right font-bold" style="font-size: 14px;">₱' . number_format($overall_summary['total_cash'] + $overall_summary['total_deposits'], 2) . '</td></tr>';
        echo '</tbody>';
        echo '</table>';
        
    } else {
        // FUEL SALES REPORT (existing code)
        // Header
        echo '<h1>DAILY FUEL SALES REPORT</h1>';
        echo '<h1 style="font-size: 16px;">24-HOUR SUMMARY</h1>';
        echo '<p>' . htmlspecialchars($station_name) . '</p>';
        echo '<p><strong>Date:</strong> ' . date('F d, Y', strtotime($report_date)) . '</p>';
        echo '<br/>';
    
    // METER READINGS TABLE
    echo '<h2>METER READINGS</h2>';
    echo '<table border="1" cellpadding="5" cellspacing="0">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Name</th>';
    echo '<th>Fuel Type</th>';
    echo '<th>Beginning</th>';
    echo '<th>Ending</th>';
    echo '<th>Calibration</th>';
    echo '<th>Volume (Liters)</th>';
    echo '<th>Price</th>';
    echo '<th>Amount</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    if (count($meter_readings) > 0) {
        $total_liters_meter = 0;
        $total_amount_meter = 0;
        foreach ($meter_readings as $reading) {
            $total_liters_meter += $reading['liters_sold'];
            [$price, $amount] = staff_report_fuel_price_amount($reading, $volume_sales);
            $total_amount_meter += $amount;
            
            echo '<tr>';
            echo '<td>' . htmlspecialchars($reading['pump_name'] ?? '-') . '</td>';
            echo '<td>' . htmlspecialchars($reading['fuel_type']) . '</td>';
            echo '<td class="text-right">' . number_format($reading['beginning_reading'], 2) . '</td>';
            echo '<td class="text-right">' . number_format($reading['ending_reading'], 2) . '</td>';
            echo '<td class="text-right">' . number_format($reading['calibration'], 2) . '</td>';
            echo '<td class="text-right font-bold">' . number_format($reading['liters_sold'], 2) . '</td>';
            echo '<td class="text-right">₱' . number_format($price, 2) . '</td>';
            echo '<td class="text-right font-bold">₱' . number_format($amount, 2) . '</td>';
            echo '</tr>';
        }
        echo '<tr class="font-bold">';
        echo '<td colspan="5" class="text-right">TOTAL</td>';
        echo '<td class="text-right">' . number_format($total_liters_meter, 2) . '</td>';
        echo '<td></td>';
        echo '<td class="text-right">₱' . number_format($total_amount_meter, 2) . '</td>';
        echo '</tr>';
    } else {
        echo '<tr><td colspan="8" style="text-align: center; padding: 20px;">No meter readings found for this date.</td></tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    echo '<br/>';
    
    // VOLUME SALES SUMMARY
    echo '<h2>VOLUME SALES SUMMARY</h2>';
    echo '<table border="1" cellpadding="5" cellspacing="0">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Fuel Type</th>';
    echo '<th>Total Liters</th>';
    echo '<th>Avg Price/L</th>';
    echo '<th>Total Amount</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    if (count($volume_sales) > 0) {
        $total_amount_vol = 0;
        foreach ($volume_sales as $vol) {
            $total_amount_vol += $vol['total_amount'];
            echo '<tr>';
            echo '<td class="font-bold">' . htmlspecialchars($vol['fuel_type']) . '</td>';
            echo '<td class="text-right">' . number_format($vol['total_liters'], 2) . ' L</td>';
            echo '<td class="text-right">₱' . number_format($vol['avg_price'], 2) . '</td>';
            echo '<td class="text-right font-bold">₱' . number_format($vol['total_amount'], 2) . '</td>';
            echo '</tr>';
        }
        echo '<tr class="font-bold">';
        echo '<td>TOTAL</td>';
        echo '<td class="text-right">' . number_format(array_sum(array_column($volume_sales, 'total_liters')), 2) . ' L</td>';
        echo '<td class="text-right">-</td>';
        echo '<td class="text-right">₱' . number_format($total_amount_vol, 2) . '</td>';
        echo '</tr>';
    } else {
        echo '<tr><td colspan="4" style="text-align: center; padding: 20px;">No volume sales data available.</td></tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    echo '<br/>';

    // TANK LITERS SUMMARY
    echo '<h2>TANK LITERS SUMMARY</h2>';
    echo '<table border="1" cellpadding="5" cellspacing="0">';
    echo '<thead><tr><th>Tank</th><th>Fuel Type</th><th>Liters</th></tr></thead>';
    echo '<tbody>';
    if (count($tank_sales) > 0) {
        $total_tank_liters = 0;
        foreach ($tank_sales as $tank) {
            $liters = (float)($tank['total_liters'] ?? $tank['total_dispensed'] ?? 0);
            $total_tank_liters += $liters;
            echo '<tr>';
            echo '<td class="font-bold">' . htmlspecialchars($tank['tank_name'] ?? 'Tank') . '</td>';
            echo '<td>' . htmlspecialchars($tank['fuel_type'] ?? '') . '</td>';
            echo '<td class="text-right">' . number_format($liters, 2) . ' L</td>';
            echo '</tr>';
        }
        echo '<tr class="font-bold"><td colspan="2" class="text-right">TOTAL TANK LITERS</td><td class="text-right">' . number_format($total_tank_liters, 2) . ' L</td></tr>';
    } else {
        echo '<tr><td colspan="3" style="text-align: center; padding: 20px;">No tank liters data available.</td></tr>';
    }
    echo '</tbody>';
    echo '</table>';
    echo '<br/>';
    
    // SHIFT SUMMARIES
    echo '<h2>SHIFT 1 FUEL SALES & CASH SUMMARY (6AM-2PM)</h2>';
    echo '<table border="1" cellpadding="5" cellspacing="0" class="summary-table" style="width: 60%;">';
    echo '<tbody>';
    echo '<tr><td class="font-bold">Total Fuel Sales:</td><td class="text-right font-bold">&#8369;' . number_format($fuel_shift1_summary['fuel_sales'], 2) . '</td></tr>';
    echo '<tr><td colspan="2">&nbsp;</td></tr>';
    echo '<tr><td colspan="2" class="font-bold">Payment Breakdown</td></tr>';
    echo '<tr><td>Cash:</td><td class="text-right">&#8369;' . number_format($fuel_shift1_summary['cash'], 2) . '</td></tr>';
    echo '<tr><td>Card:</td><td class="text-right">&#8369;' . number_format($fuel_shift1_summary['card'], 2) . '</td></tr>';
    echo '<tr><td>E-Wallet:</td><td class="text-right">&#8369;' . number_format($fuel_shift1_summary['ewallet'], 2) . '</td></tr>';
    echo '<tr><td>Credit/Suki:</td><td class="text-right">&#8369;' . number_format($fuel_shift1_summary['credit'], 2) . '</td></tr>';
    echo '</tbody>';
    echo '</table>';
    echo '<br/>';
    
    echo '<h2>SHIFT 2 FUEL SALES & CASH SUMMARY (2PM-12AM)</h2>';
    echo '<table border="1" cellpadding="5" cellspacing="0" class="summary-table" style="width: 60%;">';
    echo '<tbody>';
    echo '<tr><td class="font-bold">Total Fuel Sales:</td><td class="text-right font-bold">&#8369;' . number_format($fuel_shift2_summary['fuel_sales'], 2) . '</td></tr>';
    echo '<tr><td colspan="2">&nbsp;</td></tr>';
    echo '<tr><td colspan="2" class="font-bold">Payment Breakdown</td></tr>';
    echo '<tr><td>Cash:</td><td class="text-right">&#8369;' . number_format($fuel_shift2_summary['cash'], 2) . '</td></tr>';
    echo '<tr><td>Card:</td><td class="text-right">&#8369;' . number_format($fuel_shift2_summary['card'], 2) . '</td></tr>';
    echo '<tr><td>E-Wallet:</td><td class="text-right">&#8369;' . number_format($fuel_shift2_summary['ewallet'], 2) . '</td></tr>';
    echo '<tr><td>Credit/Suki:</td><td class="text-right">&#8369;' . number_format($fuel_shift2_summary['credit'], 2) . '</td></tr>';
    echo '</tbody>';
    echo '</table>';
    echo '<br/>';
    
    // A/R SUMMARY
    echo '<h2>A/R SUMMARY (Account Receivable / Utang)</h2>';
    echo '<table border="1" cellpadding="5" cellspacing="0" class="summary-table" style="width: 60%;">';
    echo '<tbody>';
    echo '<tr><td class="font-bold">Shift 1 (6AM-2PM):</td><td class="text-right">&#8369;' . number_format($ar_shift_summary['shift1'], 2) . '</td></tr>';
    echo '<tr><td class="font-bold">Shift 2 (2PM-12AM):</td><td class="text-right">&#8369;' . number_format($ar_shift_summary['shift2'], 2) . '</td></tr>';
    echo '<tr><td class="font-bold">TOTAL A/R:</td><td class="text-right font-bold">&#8369;' . number_format($ar_shift_summary['total'], 2) . '</td></tr>';
    echo '</tbody>';
    echo '</table>';
    echo '<br/>';
    if (count($ar_summary) > 0) {
        echo '<h2>CUSTOMER A/R BALANCES</h2>';
        echo '<table border="1" cellpadding="5" cellspacing="0">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Customer Name</th>';
        echo '<th>Contact Number</th>';
        echo '<th>Type</th>';
        echo '<th>Outstanding Balance</th>';
        echo '<th>Credit Limit</th>';
        echo '<th>Available Credit</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        $total_ar = 0;
        foreach ($ar_summary as $ar) {
            $total_ar += $ar['balance'];
            $available = $ar['credit_limit'] - $ar['balance'];
            echo '<tr>';
            echo '<td class="font-bold">' . htmlspecialchars($ar['name']) . '</td>';
            echo '<td>' . htmlspecialchars($ar['contact_number'] ?? '-') . '</td>';
            echo '<td>' . strtoupper($ar['type']) . '</td>';
            echo '<td class="text-right font-bold">₱' . number_format($ar['balance'], 2) . '</td>';
            echo '<td class="text-right">₱' . number_format($ar['credit_limit'], 2) . '</td>';
            echo '<td class="text-right">₱' . number_format($available, 2) . '</td>';
            echo '</tr>';
        }
        
        echo '<tr class="font-bold">';
        echo '<td colspan="3" class="text-right">TOTAL ACCOUNTS RECEIVABLE</td>';
        echo '<td class="text-right">₱' . number_format($total_ar, 2) . '</td>';
        echo '<td colspan="2"></td>';
        echo '</tr>';
        echo '</tbody>';
        echo '</table>';
        echo '<br/>';
    }
    
    // OVERALL DAILY SUMMARY
    echo '<h2>OVERALL DAILY FUEL SUMMARY</h2>';
    echo '<table border="1" cellpadding="5" cellspacing="0" class="summary-table" style="width: 60%;">';
    echo '<tbody>';
    echo '<tr><td><strong>Total Fuel Sales:</strong></td><td class="text-right font-bold">₱' . number_format($overall_summary['total_fuel_sales'], 2) . '</td></tr>';
    echo '<tr><td><strong>Total Liters Sold:</strong></td><td class="text-right font-bold">' . number_format($overall_summary['total_liters'], 2) . ' L</td></tr>';
    echo '<tr><td><strong>Total Cash Collection:</strong></td><td class="text-right font-bold">₱' . number_format($overall_summary['total_cash'], 2) . '</td></tr>';
    echo '<tr><td><strong>Total Deposits:</strong></td><td class="text-right font-bold">₱' . number_format($overall_summary['total_deposits'], 2) . '</td></tr>';
    echo '</tbody>';
    echo '</table>';
    echo '<br/>';
    
    // TOTAL CASH IN BANK
    echo '<h2>TOTAL CASH IN BANK</h2>';
    echo '<table border="1" cellpadding="5" cellspacing="0" class="summary-table" style="width: 60%;">';
    echo '<tbody>';
    echo '<tr><td class="font-bold">Cash on Hand:</td><td class="text-right font-bold">₱' . number_format($overall_summary['total_cash'], 2) . '</td></tr>';
    echo '<tr><td class="font-bold">Deposits Made Today:</td><td class="text-right font-bold">₱' . number_format($overall_summary['total_deposits'], 2) . '</td></tr>';
    echo '<tr><td class="font-bold" style="font-size: 14px;">TOTAL CASH IN BANK:</td><td class="text-right font-bold" style="font-size: 14px;">₱' . number_format($overall_summary['total_cash'] + $overall_summary['total_deposits'], 2) . '</td></tr>';
    echo '</tbody>';
    echo '</table>';
    
    } // End if merchandise/fuel export type
    
    echo '</body>';
    echo '</html>';
    exit;
}

// ============================================================
// EXPORT HANDLING - FORMATTED LIKE ACTUAL DAILY FUEL REPORT
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Daily Fuel Sales Report - <?= htmlspecialchars($report_date) ?></title>
        <style>
            @page {
                size: A4 portrait;
                margin: 15mm 10mm;
            }
            @media print {
                body { margin: 0; padding: 0; }
                .no-print { display: none !important; }
                table { page-break-inside: avoid; }
                .page-break { page-break-after: always; }
            }
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { 
                font-family: 'Courier New', monospace; 
                font-size: 10pt;
                line-height: 1.2;
            }
            
            /* Header Section */
            .report-header {
                border: 2px solid #000;
                padding: 8px;
                margin-bottom: 10px;
            }
            .report-header table {
                width: 100%;
                border: none;
            }
            .report-header td {
                border: none;
                padding: 2px 5px;
                font-size: 9pt;
            }
            .report-title {
                text-align: center;
                font-size: 14pt;
                font-weight: bold;
                margin-bottom: 8px;
                text-decoration: underline;
            }
            
            /* Main Content Layout */
            .content-wrapper {
                display: grid;
                grid-template-columns: 30% 70%;
                gap: 10px;
            }
            
            /* Left Column - Calibration */
            .left-column {
                border: 1px solid #000;
                padding: 5px;
            }
            .calibration-box {
                border: 1px solid #000;
                padding: 5px;
                margin-bottom: 8px;
            }
            .calibration-box h3 {
                font-size: 9pt;
                text-align: center;
                border-bottom: 1px solid #000;
                padding-bottom: 3px;
                margin-bottom: 5px;
            }
            
            /* Right Column - Main Table */
            .right-column {
                border: 1px solid #000;
                padding: 5px;
            }
            
            /* Meter Reading Table */
            .meter-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 8pt;
                margin-bottom: 8px;
            }
            .meter-table th {
                border: 1px solid #000;
                background: #fff;
                padding: 4px 2px;
                text-align: center;
                font-weight: bold;
            }
            .meter-table td {
                border: 1px solid #000;
                padding: 3px 2px;
                text-align: center;
            }
            .meter-table td.text-right {
                text-align: right;
                padding-right: 5px;
            }
            
            /* Summary Section */
            .summary-section {
                border: 1px solid #000;
                padding: 5px;
                margin-top: 8px;
            }
            .summary-section h3 {
                font-size: 9pt;
                text-align: center;
                border-bottom: 1px solid #000;
                padding-bottom: 3px;
                margin-bottom: 5px;
            }
            .summary-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 8pt;
            }
            .summary-table td {
                border: 1px solid #000;
                padding: 3px 5px;
            }
            .summary-table .label {
                background: #fff;
                font-weight: bold;
                width: 40%;
            }
            .summary-table .value {
                text-align: right;
            }
            
            /* Footer */
            .report-footer {
                margin-top: 10px;
                border-top: 1px solid #000;
                padding-top: 5px;
                font-size: 8pt;
                text-align: center;
            }
            
            .signature-section {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
                margin-top: 20px;
            }
            .signature-box {
                text-align: center;
                padding: 10px;
            }
            .signature-line {
                border-top: 1px solid #000;
                margin: 30px 20px 5px 20px;
            }
        </style>
    </head>
    <body>
        <!-- Report Header -->
        <div class="report-header">
            <div class="report-title">DAILY FUEL SALES REPORT</div>
            <div class="report-title" style="font-size: 11pt; margin-top: 5px;">24-HOUR SUMMARY</div>
            <table>
                <tr>
                    <td style="width: 50%;"><?= htmlspecialchars($station_name) ?></td>
                    <td><strong>Date:</strong> <?= date('F d, Y', strtotime($report_date)) ?></td>
                </tr>
                <tr>
                    <td><strong>Location:</strong> <?= htmlspecialchars($station_location) ?></td>
                    <td><strong>Period:</strong> 24 Hours (00:00 - 23:59)</td>
                </tr>
                <tr>
                    <td colspan="2"><strong>Generated:</strong> <?= date('F d, Y h:i A') ?></td>
                </tr>
            </table>
        </div>
        
        <!-- Main Content -->
        <div class="content-wrapper">
            <!-- Left Column: Calibration & Summary -->
            <div class="left-column">
                <!-- Shift 1 Calibration -->
                <div class="calibration-box">
                    <h3>SHIFT 1 - BEGINNING</h3>
                    <table style="width: 100%; font-size: 8pt;">
                        <tr><td>A/R Report #:</td><td style="text-align: right;">___________</td></tr>
                        <tr><td>Amount:</td><td style="text-align: right;">₱ <?= number_format($shift1_summary['total_sales'], 2) ?></td></tr>
                        <tr><td>Cash Deposit:</td><td style="text-align: right;">₱ <?= number_format($shift1_summary['cash'], 2) ?></td></tr>
                    </table>
                </div>
                
                <div class="calibration-box">
                    <h3>SHIFT 1 - ENDING</h3>
                    <table style="width: 100%; font-size: 8pt;">
                        <tr><td>Cash/Bank:</td><td style="text-align: right;">₱ <?= number_format($shift1_summary['cash'], 2) ?></td></tr>
                        <tr><td>Less Deposit:</td><td style="text-align: right;">₱ 0.00</td></tr>
                        <tr><td style="font-weight: bold;">Cash on Hand:</td><td style="text-align: right; font-weight: bold;">₱ <?= number_format($shift1_summary['cash'], 2) ?></td></tr>
                    </table>
                </div>
                
                <!-- Shift 2 Calibration -->
                <div class="calibration-box">
                    <h3>SHIFT 2 - BEGINNING</h3>
                    <table style="width: 100%; font-size: 8pt;">
                        <tr><td>A/R Report #:</td><td style="text-align: right;">___________</td></tr>
                        <tr><td>Amount:</td><td style="text-align: right;">₱ <?= number_format($shift2_summary['total_sales'], 2) ?></td></tr>
                        <tr><td>Cash Deposit:</td><td style="text-align: right;">₱ <?= number_format($shift2_summary['cash'], 2) ?></td></tr>
                    </table>
                </div>
                
                <div class="calibration-box">
                    <h3>SHIFT 2 - ENDING</h3>
                    <table style="width: 100%; font-size: 8pt;">
                        <tr><td>Cash/Bank:</td><td style="text-align: right;">₱ <?= number_format($shift2_summary['cash'], 2) ?></td></tr>
                        <tr><td>Less Deposit:</td><td style="text-align: right;">₱ 0.00</td></tr>
                        <tr><td style="font-weight: bold;">Cash on Hand:</td><td style="text-align: right; font-weight: bold;">₱ <?= number_format($shift2_summary['cash'], 2) ?></td></tr>
                    </table>
                </div>
                
                <!-- Overall Summary Box -->
                <div class="calibration-box">
                    <h3>OVERALL SUMMARY</h3>
                    <table style="width: 100%; font-size: 8pt;">
                        <tr>
                            <td style="font-weight: bold;">Total Cash in Bank:</td>
                            <td style="text-align: right; font-weight: bold;">₱ <?= number_format($overall_summary['total_cash'], 2) ?></td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <!-- Right Column: Meter Reading Table -->
            <div class="right-column">
                <h3 style="text-align: center; font-size: 10pt; margin-bottom: 8px; text-decoration: underline;">METER READING TABLE</h3>
                
                <table class="meter-table">
                    <thead>
                        <tr>
                            <th>PUMP</th>
                            <th>FUEL<br>TYPE</th>
                            <th>BEGINNING</th>
                            <th>ENDING</th>
                            <th>CAL</th>
                            <th>VOLUME<br>LITERS</th>
                            <th>PRICE</th>
                            <th>AMOUNT</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $grand_total_liters = 0;
                        $grand_total_amount = 0;
                        
                        foreach ($meter_readings as $reading): 
                            [$readingPrice, $readingAmount] = staff_report_fuel_price_amount($reading, $volume_sales);
                            $grand_total_liters += (float)($reading['liters_sold'] ?? 0);
                            $grand_total_amount += $readingAmount;
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($reading['pump_name'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($reading['fuel_type'] ?? '') ?></td>
                            <td class="text-right"><?= number_format((float)($reading['beginning_reading'] ?? 0), 2) ?></td>
                            <td class="text-right"><?= number_format((float)($reading['ending_reading'] ?? 0), 2) ?></td>
                            <td class="text-right"><?= number_format((float)($reading['calibration'] ?? 0), 2) ?></td>
                            <td class="text-right" style="font-weight: bold;"><?= number_format((float)($reading['liters_sold'] ?? 0), 2) ?></td>
                            <td class="text-right">&#8369;<?= number_format($readingPrice, 2) ?></td>
                            <td class="text-right" style="font-weight: bold;">&#8369;<?= number_format($readingAmount, 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <tr style="font-weight: bold;">
                            <td colspan="5" style="text-align: right;">TOTAL</td>
                            <td class="text-right"><?= number_format($grand_total_liters, 2) ?></td>
                            <td></td>
                            <td class="text-right">&#8369;<?= number_format($grand_total_amount, 2) ?></td>
                        </tr>
                    </tbody>
                </table>
                
                <!-- Volume Sales Summary -->
                <div class="summary-section">
                    <h3>VOLUME & AMOUNT SUMMARY</h3>
                    <table class="summary-table">
                        <?php foreach ($volume_sales as $vol): ?>
                        <tr>
                            <td class="label"><?= htmlspecialchars($vol['fuel_type']) ?></td>
                            <td><?= number_format($vol['total_liters'], 2) ?> L</td>
                            <td class="value">₱<?= number_format($vol['total_amount'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr style="font-weight: bold;">
                            <td class="label">TOTAL FUEL SALES</td>
                            <td><?= number_format(array_sum(array_column($volume_sales, 'total_liters')), 2) ?> L</td>
                            <td class="value">₱<?= number_format($overall_summary['total_fuel_sales'], 2) ?></td>
                        </tr>
                        <tr>
                            <td class="label">MERCHANDISE</td>
                            <td>—</td>
                            <td class="value">₱<?= number_format($overall_summary['total_merchandise_sales'], 2) ?></td>
                        </tr>
                        <tr style="font-weight: bold;">
                            <td class="label">TOTAL SALES</td>
                            <td colspan="2" class="value">₱<?= number_format($overall_summary['total_fuel_sales'] + $overall_summary['total_merchandise_sales'], 2) ?></td>
                        </tr>
                    </table>
                </div>

                <!-- Tank Liters Summary -->
                <div class="summary-section">
                    <h3>TANK LITERS SUMMARY</h3>
                    <table class="summary-table">
                        <?php
                        $print_tank_liters = 0;
                        foreach ($tank_sales as $tank):
                            $liters = (float)($tank['total_liters'] ?? $tank['total_dispensed'] ?? 0);
                            $print_tank_liters += $liters;
                        ?>
                        <tr>
                            <td class="label"><?= htmlspecialchars($tank['tank_name'] ?? 'Tank') ?></td>
                            <td><?= htmlspecialchars($tank['fuel_type'] ?? '') ?></td>
                            <td class="value"><?= number_format($liters, 2) ?> L</td>
                        </tr>
                        <?php endforeach; ?>
                        <tr style="font-weight: bold;">
                            <td colspan="2" class="label">TOTAL TANK LITERS</td>
                            <td class="value"><?= number_format($print_tank_liters, 2) ?> L</td>
                        </tr>
                    </table>
                </div>
                
                <!-- Cash Breakdown -->
                <div class="summary-section">
                    <h3>CASH/BANK IN SUMMARY</h3>
                    <table class="summary-table">
                        <tr>
                            <td class="label">TOTAL CASH IN BANK:</td>
                            <td class="value" style="font-weight: bold;">₱<?= number_format($overall_summary['total_cash'], 2) ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- A/R Summary -->
        <div class="summary-section" style="margin-top: 10px;">
            <h3>A/R SUMMARY (ACCOUNT RECEIVABLE / UTANG)</h3>
            <table class="meter-table">
                <tbody>
                    <tr>
                        <td><strong>SHIFT 1</strong></td>
                        <td class="text-right">&#8369;<?= number_format($ar_shift_summary['shift1'], 2) ?></td>
                    </tr>
                    <tr>
                        <td><strong>SHIFT 2</strong></td>
                        <td class="text-right">&#8369;<?= number_format($ar_shift_summary['shift2'], 2) ?></td>
                    </tr>
                    <tr style="font-weight: bold;">
                        <td>TOTAL A/R</td>
                        <td class="text-right">&#8369;<?= number_format($ar_shift_summary['total'], 2) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Customer A/R Balances (if exists) -->
        <?php if (count($ar_summary) > 0): ?>
        <div class="summary-section" style="margin-top: 10px;">
            <h3>CUSTOMER A/R BALANCES</h3>
            <table class="meter-table">
                <thead>
                    <tr>
                        <th>Customer Name</th>
                        <th>Type</th>
                        <th>Balance</th>
                        <th>Credit Limit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $total_ar = 0;
                    foreach ($ar_summary as $ar): 
                        $total_ar += $ar['balance'];
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($ar['name']) ?></td>
                        <td style="text-align: center;"><?= strtoupper($ar['type']) ?></td>
                        <td class="text-right" style="font-weight: bold;">₱<?= number_format($ar['balance'], 2) ?></td>
                        <td class="text-right">₱<?= number_format($ar['credit_limit'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr style="font-weight: bold;">
                        <td colspan="2" style="text-align: right;">TOTAL A/R</td>
                        <td class="text-right">₱<?= number_format($total_ar, 2) ?></td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <!-- Signature Section -->
        <div class="signature-section">
            <div class="signature-box">
                <div class="signature-line"></div>
                <strong>Cashier / Staff</strong><br>
                <small>Printed Name & Signature</small>
            </div>
            <div class="signature-box">
                <div class="signature-line"></div>
                <strong>Manager / Supervisor</strong><br>
                <small>Printed Name & Signature</small>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="report-footer">
            <p><strong>Generated on:</strong> <?= date('F d, Y h:i:s A') ?></p>
            <p>Petron Station Management System © 2026</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$page_title = "Fuel Sales Summary Report";

// Include system header
require_once __DIR__ . '/../partials/header.php';
require_once __DIR__ . '/../partials/flash_toast.php';
?>

<style>
    body {
        margin: 0;
        padding: 0;
        background: white;
    }
    
    .main-content {
        width: 100%;
        max-width: 100%;
        padding: 0;
        margin: 0;
    }
    
    .container {
        max-width: 100%;
        margin: 0;
        padding: 0;
        background: white;
    }
    
    .header {
        background: #fff;
        color: #000;
        padding: 15px 20px;
        text-align: center;
        border-bottom: 2px solid #000;
        margin-bottom: 0;
    }
    
    .header h1 {
        font-size: 22px;
        margin: 0 0 8px 0;
        font-weight: 700;
        color: #000;
    }
    
    .header p {
        font-size: 12px;
        color: #000;
        margin: 3px 0;
    }
    
    .controls {
        padding: 12px 20px;
        background: #fff;
        border-bottom: 1px solid #000;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
    }
    
    .date-controls {
        display: flex;
        gap: 8px;
        align-items: center;
        font-size: 12px;
    }
    
    .date-controls label {
        font-weight: 700;
        color: #000;
    }
    
    .date-controls input[type="date"] {
        padding: 6px 10px;
        border: 1px solid #000;
        font-size: 12px;
    }
    
    .btn {
        padding: 7px 14px;
        border-radius: 4px;
        background: #ffffff;
        border: 1px solid #00264D;
        color: #00264D;
        cursor: pointer;
        font-size: 11px;
        font-weight: 600;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all .2s;
        white-space: nowrap;
    }
    
    .btn:hover {
        background: #00264D;
        color: #ffffff;
    }
    
    .btn-primary {
        border-color: #00264D;
        color: #00264D;
    }
    
    .btn-primary:hover {
        background: #00264D;
        color: #ffffff;
    }
    
    .tab-navigation {
        display: flex;
        gap: 0;
        border-bottom: 2px solid #000;
        background: #fff;
    }
    
    .tab-btn {
        padding: 12px 24px;
        border: 1px solid #000;
        border-bottom: none;
        background: #fff;
        cursor: pointer;
        font-size: 13px;
        font-weight: 600;
        color: #000;
        transition: all 0.3s ease;
    }
    
    .tab-btn:hover {
        background: #f5f5f5;
    }
    
    .tab-btn.active {
        background: #000;
        color: #fff;
        border-bottom: 2px solid #000;
        margin-bottom: -2px;
    }
    
    .tab-content {
        display: none;
    }
    
    .tab-content.active {
        display: block;
    }
    
    .print-area {
        background: #fff;
    }
    
    .content {
        padding: 15px 20px;
    }
    
    .section-title {
        font-size: 16px;
        font-weight: 700;
        margin: 20px 0 10px 0;
        color: #000;
        padding-bottom: 8px;
        border-bottom: 2px solid #000;
        text-transform: uppercase;
    }
    
    .table-container {
        overflow-x: visible;
        margin-bottom: 20px;
        width: 100%;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
        background: white;
        border: 1px solid #000;
        font-size: 11px;
    }
    
    thead {
        background: #fff;
        color: #000;
    }
    
    th {
        padding: 8px 6px;
        text-align: left;
        font-weight: 700;
        font-size: 10px;
        text-transform: uppercase;
        border: 1px solid #000;
    }
    
    td {
        padding: 6px 6px;
        border: 1px solid #000;
        font-size: 11px;
    }
    
    tbody tr {
        background: #fff;
    }
    
    .text-right { text-align: right; }
    .text-center { text-align: center; }
    .font-bold { font-weight: 700; }
    
    .shift-boxes {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
        margin: 20px 0;
    }
    
    .shift-box {
        background: #fff;
        padding: 15px;
        border: 1px solid #000;
    }
    
    .shift-box h3 {
        font-size: 14px;
        color: #000;
        margin: 0 0 10px 0;
        font-weight: 700;
        border-bottom: 1px solid #000;
        padding-bottom: 8px;
        text-transform: uppercase;
    }
    
    .shift-box table {
        font-size: 11px;
    }
    
    .shift-box td {
        padding: 6px 4px;
        border: none;
        border-bottom: 1px solid #ddd;
    }
    
    .summary-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 15px;
        margin: 20px 0;
    }
    
    .summary-card {
        background: #fff;
        padding: 15px;
        border: 1px solid #000;
        text-align: center;
    }
    
    .summary-card .label {
        font-size: 11px;
        color: #000;
        margin-bottom: 8px;
        font-weight: 700;
    }
    
    .summary-card .value {
        font-size: 20px;
        color: #000;
        font-weight: 700;
    }
    
    @media print {
        @page {
            size: legal portrait;
            margin: 0.5in 0.4in;
        }

        * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }

        body * { visibility: hidden !important; }
        .print-area, .print-area * { visibility: visible !important; }
        .print-area {
            position: fixed !important; top: 0 !important; left: 0 !important;
            width: 100% !important; margin: 0 !important; padding: 0 !important;
            background: white !important;
        }
        html, body { margin: 0 !important; padding: 0 !important; background: white !important; overflow: visible !important; }
        .container, .content { margin: 0 !important; padding: 0 !important; }

        /* ── Kill ALL icons ── */
        i, svg, .fas, .far, .fab, .fa, [class*="fa-"] {
            display: none !important;
            width: 0 !important; height: 0 !important;
            font-size: 0 !important; line-height: 0 !important;
            margin: 0 !important; padding: 0 !important;
        }

        .header { text-align: center !important; border-bottom: 2px solid #000 !important; padding: 6px 0 !important; margin: 0 0 8px 0 !important; }
        .header h1 { font-size: 16px !important; font-weight: 700 !important; color: #000 !important; margin: 0 0 3px 0 !important; }
        .header p { font-size: 10px !important; color: #000 !important; margin: 2px 0 !important; }
        .section-title { font-size: 12px !important; font-weight: 700 !important; margin: 8px 0 4px 0 !important; padding-bottom: 3px !important; border-bottom: 2px solid #000 !important; page-break-after: avoid !important; }
        .table-container { overflow: visible !important; width: 100% !important; text-align: center !important; }
        table { width: 95% !important; max-width: 100% !important; border-collapse: collapse !important; font-size: 10px !important; table-layout: auto !important; margin: 0 auto 8px auto !important; }
        thead { display: table-header-group !important; }
        tbody { display: table-row-group !important; }
        tr { page-break-inside: avoid !important; }
        th { font-size: 10px !important; padding: 6px 8px !important; border: 1px solid #000 !important; background: #fff !important; color: #000 !important; font-weight: 700 !important; text-align: center !important; white-space: nowrap !important; }
        td { font-size: 9px !important; padding: 5px 8px !important; border: 1px solid #000 !important; white-space: nowrap !important; vertical-align: top !important; }
        .shift-boxes, .shift-summary { display: grid !important; grid-template-columns: 1fr 1fr !important; gap: 6px !important; margin: 6px 0 !important; page-break-inside: avoid !important; }
        .shift-box { border: 1px solid #000 !important; padding: 5px !important; font-size: 9px !important; }
        .shift-box h3 { font-size: 10px !important; border-bottom: 1px solid #000 !important; padding-bottom: 2px !important; margin: 0 0 4px 0 !important; }
        .shift-box table { width: auto !important; margin: 0 !important; }
        .shift-box td { border: none !important; border-bottom: 1px solid #ddd !important; font-size: 9px !important; }
        .summary-grid { display: grid !important; grid-template-columns: repeat(4, 1fr) !important; gap: 5px !important; margin: 6px 0 !important; page-break-inside: avoid !important; }
        .summary-card { border: 1px solid #000 !important; padding: 5px !important; }
        .summary-card .label { font-size: 7px !important; }
        .summary-card .value { font-size: 10px !important; font-weight: 700 !important; }
        .tab-navigation, .tab-btn, .controls { display: none !important; }
        .tab-content { display: block !important; }
        .tab-pane { display: block !important; }
    }
</style>

<!-- CONTROLS - OUTSIDE PRINTABLE AREA -->
<div class="controls">
    <div class="date-controls">
        <label><strong>Report Date:</strong></label>
        <input type="date" id="report_date" value="<?= htmlspecialchars($report_date) ?>" max="<?= $today ?>">
        <button class="btn btn-primary" onclick="applyFilters()">
            <i class="fa-solid fa-filter"></i> Apply
        </button>
    </div>
    
    <div>
        <a href="?export=excel&type=<?= urlencode($active_tab) ?>&report_date=<?= urlencode($report_date) ?>" class="btn" id="export-excel-btn" style="border-color:#16a34a;color:#16a34a;">
            <i class="fa-solid fa-file-excel"></i> Export Excel
        </a>
        <button class="btn" onclick="window.print()" style="border-color:#475569;color:#475569;">
            <i class="fa-solid fa-print"></i> Print Report
        </button>
    </div>
</div>

<!-- TAB NAVIGATION -->
<div class="tab-navigation">
    <button class="tab-btn <?= $active_tab === 'fuel' ? 'active' : '' ?>" onclick="switchTab('fuel', event)">DAILY FUEL SALES REPORT</button>
    <button class="tab-btn <?= $active_tab === 'merchandise' ? 'active' : '' ?>" onclick="switchTab('merchandise', event)">DAILY MERCHANDISE & SERVICE SALES REPORT</button>
</div>

<!-- PRINTABLE DOCUMENT AREA -->
<div class="print-area">
    <!-- FUEL TAB CONTENT -->
    <div id="fuel-tab" class="tab-content <?= $active_tab === 'fuel' ? 'active' : '' ?>">
        <div class="container">
            <div class="header">
                <h1>DAILY FUEL SALES REPORT</h1>
                <h1 style="font-size: 18px; margin-top: 5px;">24-HOUR SUMMARY</h1>
                <p><?= htmlspecialchars($station_name) ?> <?= $station_location ? '- ' . htmlspecialchars($station_location) : '' ?></p>
                <p><strong>Date:</strong> <?= date('F d, Y', strtotime($report_date)) ?></p>
            </div>
        
        <div class="content">
            <!-- Meter Readings Table -->
            <div class="section-title">
                METER READING TABLE
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Fuel Type</th>
                            <th class="text-right">Beginning</th>
                            <th class="text-right">Ending</th>
                            <th class="text-right">Calibration</th>
                            <th class="text-right">Volume (Liters)</th>
                            <th class="text-right">Price</th>
                            <th class="text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total_liters_meter = 0;
                        $total_amount_meter = 0;
                        if (count($meter_readings) > 0):
                            foreach ($meter_readings as $reading): 
                                $total_liters_meter += $reading['liters_sold'];
                                [$price, $amount] = staff_report_fuel_price_amount($reading, $volume_sales);
                                $total_amount_meter += $amount;
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($reading['pump_name'] ?? '-') ?></strong></td>
                            <td><?= htmlspecialchars($reading['fuel_type']) ?></td>
                            <td class="text-right"><?= number_format($reading['beginning_reading'], 2) ?></td>
                            <td class="text-right"><?= number_format($reading['ending_reading'], 2) ?></td>
                            <td class="text-right"><?= number_format($reading['calibration'], 2) ?></td>
                            <td class="text-right font-bold"><?= number_format($reading['liters_sold'], 2) ?></td>
                            <td class="text-right">₱<?= number_format($price, 2) ?></td>
                            <td class="text-right font-bold">₱<?= number_format($amount, 2) ?></td>
                        </tr>
                        <?php 
                            endforeach;
                        else:
                        ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 40px;">
                                No meter readings found for this date.
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if (count($meter_readings) > 0): ?>
                        <tr style="font-weight: 700;">
                            <td colspan="5" class="text-right">TOTAL</td>
                            <td class="text-right"><?= number_format($total_liters_meter, 2) ?></td>
                            <td></td>
                            <td class="text-right">₱<?= number_format($total_amount_meter, 2) ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Volume Sales Summary -->
            <div class="section-title">
                VOLUME SALES SUMMARY
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Fuel Type</th>
                            <th class="text-right">Total Liters</th>
                            <th class="text-right">Avg Price/L</th>
                            <th class="text-right">Total Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total_amount_vol = 0;
                        if (count($volume_sales) > 0):
                            foreach ($volume_sales as $vol): 
                                $total_amount_vol += $vol['total_amount'];
                        ?>
                        <tr>
                            <td class="font-bold"><?= htmlspecialchars($vol['fuel_type']) ?></td>
                            <td class="text-right"><?= number_format($vol['total_liters'], 2) ?> L</td>
                            <td class="text-right">₱<?= number_format($vol['avg_price'], 2) ?></td>
                            <td class="text-right font-bold">₱<?= number_format($vol['total_amount'], 2) ?></td>
                        </tr>
                        <?php 
                            endforeach;
                        else:
                        ?>
                        <tr>
                            <td colspan="4" style="text-align: center; padding: 40px;">
                                No volume sales data available.
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if (count($volume_sales) > 0): ?>
                        <tr style="font-weight: 700;">
                            <td>TOTAL</td>
                            <td class="text-right"><?= number_format(array_sum(array_column($volume_sales, 'total_liters')), 2) ?> L</td>
                            <td class="text-right">-</td>
                            <td class="text-right">₱<?= number_format($total_amount_vol, 2) ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Tank Liters Summary -->
            <div class="section-title">
                TANK LITERS SUMMARY
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Tank</th>
                            <th>Fuel Type</th>
                            <th class="text-right">Liters</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if (count($tank_sales) > 0):
                            $total_tank_liters = 0;
                            foreach ($tank_sales as $tank): 
                                $total_tank_liters += (float)($tank['total_liters'] ?? $tank['total_dispensed'] ?? 0);
                        ?>
                        <tr>
                            <td class="font-bold"><?= htmlspecialchars($tank['tank_name'] ?? 'Tank') ?></td>
                            <td><?= htmlspecialchars($tank['fuel_type']) ?></td>
                            <td class="text-right"><?= number_format($tank['total_liters'] ?? $tank['total_dispensed'], 2) ?> L</td>
                        </tr>
                        <?php 
                            endforeach;
                        else:
                        ?>
                        <tr>
                            <td colspan="3" style="text-align: center; padding: 40px;">
                                No tank liters data available.
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if (count($tank_sales) > 0): ?>
                        <tr style="font-weight: 700;">
                            <td colspan="2" class="text-right">TOTAL TANK LITERS</td>
                            <td class="text-right"><?= number_format($total_tank_liters, 2) ?> L</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Shift Summaries -->
            <div class="section-title">
                SHIFT FUEL SALES & CASH SUMMARIES
            </div>
            <div class="shift-boxes">
                <!-- Shift 1 -->
                <div class="shift-box">
                    <h3>SHIFT 1 (6AM-2PM)</h3>
                    <table>
                        <tbody>
                            <tr>
                                <td class="font-bold">Total Fuel Sales:</td>
                                <td class="text-right font-bold">&#8369;<?= number_format($fuel_shift1_summary['fuel_sales'], 2) ?></td>
                            </tr>
                            <tr><td colspan="2" style="height: 10px;"></td></tr>
                            <tr>
                                <td colspan="2" class="font-bold">Payment Breakdown</td>
                            </tr>
                            <tr>
                                <td>Cash:</td>
                                <td class="text-right">&#8369;<?= number_format($fuel_shift1_summary['cash'], 2) ?></td>
                            </tr>
                            <tr>
                                <td>Card:</td>
                                <td class="text-right">&#8369;<?= number_format($fuel_shift1_summary['card'], 2) ?></td>
                            </tr>
                            <tr>
                                <td>E-Wallet:</td>
                                <td class="text-right">&#8369;<?= number_format($fuel_shift1_summary['ewallet'], 2) ?></td>
                            </tr>
                            <tr>
                                <td>Credit/Suki:</td>
                                <td class="text-right">&#8369;<?= number_format($fuel_shift1_summary['credit'], 2) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- Shift 2 -->
                <div class="shift-box">
                    <h3>SHIFT 2 (2PM-12AM)</h3>
                    <table>
                        <tbody>
                            <tr>
                                <td class="font-bold">Total Fuel Sales:</td>
                                <td class="text-right font-bold">&#8369;<?= number_format($fuel_shift2_summary['fuel_sales'], 2) ?></td>
                            </tr>
                            <tr><td colspan="2" style="height: 10px;"></td></tr>
                            <tr>
                                <td colspan="2" class="font-bold">Payment Breakdown</td>
                            </tr>
                            <tr>
                                <td>Cash:</td>
                                <td class="text-right">&#8369;<?= number_format($fuel_shift2_summary['cash'], 2) ?></td>
                            </tr>
                            <tr>
                                <td>Card:</td>
                                <td class="text-right">&#8369;<?= number_format($fuel_shift2_summary['card'], 2) ?></td>
                            </tr>
                            <tr>
                                <td>E-Wallet:</td>
                                <td class="text-right">&#8369;<?= number_format($fuel_shift2_summary['ewallet'], 2) ?></td>
                            </tr>
                            <tr>
                                <td>Credit/Suki:</td>
                                <td class="text-right">&#8369;<?= number_format($fuel_shift2_summary['credit'], 2) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- A/R Summary -->
            <div class="section-title">
                A/R SUMMARY (Account Receivable / Utang)
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Shift</th>
                            <th class="text-right">A/R Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="font-bold">Shift 1 (6AM-2PM)</td>
                            <td class="text-right">&#8369;<?= number_format($ar_shift_summary['shift1'], 2) ?></td>
                        </tr>
                        <tr>
                            <td class="font-bold">Shift 2 (2PM-12AM)</td>
                            <td class="text-right">&#8369;<?= number_format($ar_shift_summary['shift2'], 2) ?></td>
                        </tr>
                        <tr style="font-weight: 700;">
                            <td class="text-right">TOTAL A/R</td>
                            <td class="text-right">&#8369;<?= number_format($ar_shift_summary['total'], 2) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Customer A/R Balances -->
            <?php if (count($ar_summary) > 0): ?>
            <div class="section-title">
                CUSTOMER A/R BALANCES
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Customer Name</th>
                            <th>Contact Number</th>
                            <th>Type</th>
                            <th class="text-right">Outstanding Balance</th>
                            <th class="text-right">Credit Limit</th>
                            <th class="text-right">Available Credit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total_ar = 0;
                        foreach ($ar_summary as $ar): 
                            $total_ar += $ar['balance'];
                            $available = $ar['credit_limit'] - $ar['balance'];
                        ?>
                        <tr>
                            <td class="font-bold"><?= htmlspecialchars($ar['name']) ?></td>
                            <td><?= htmlspecialchars($ar['contact_number'] ?? '-') ?></td>
                            <td><?= strtoupper($ar['type']) ?></td>
                            <td class="text-right font-bold">₱<?= number_format($ar['balance'], 2) ?></td>
                            <td class="text-right">₱<?= number_format($ar['credit_limit'], 2) ?></td>
                            <td class="text-right">₱<?= number_format($available, 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr style="font-weight: 700;">
                            <td colspan="3" class="text-right">TOTAL CUSTOMER A/R BALANCE</td>
                            <td class="text-right">₱<?= number_format($total_ar, 2) ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <!-- Overall Daily Summary -->
            <div class="section-title">
                OVERALL DAILY FUEL SUMMARY
            </div>
            <div class="summary-grid">
                <div class="summary-card">
                    <div class="label">Total Fuel Sales</div>
                    <div class="value">₱<?= number_format($overall_summary['total_fuel_sales'], 2) ?></div>
                </div>
                
                <div class="summary-card">
                    <div class="label">Total Liters Sold</div>
                    <div class="value"><?= number_format($overall_summary['total_liters'], 2) ?> L</div>
                </div>
                
                <div class="summary-card">
                    <div class="label">Total Cash Collection</div>
                    <div class="value">₱<?= number_format($overall_summary['total_cash'], 2) ?></div>
                </div>
                
                <div class="summary-card">
                    <div class="label">Total A/R</div>
                    <div class="value">&#8369;<?= number_format($overall_summary['total_ar'], 2) ?></div>
                </div>

                <div class="summary-card">
                    <div class="label">Total Deposits</div>
                    <div class="value">₱<?= number_format($overall_summary['total_deposits'], 2) ?></div>
                </div>
            </div>
            
            <!-- Total Cash in Bank -->
            <div class="section-title">
                TOTAL CASH IN BANK
            </div>
            <div class="table-container">
                <table>
                    <tbody>
                        <tr>
                            <td class="font-bold" style="width: 70%;">Cash on Hand:</td>
                            <td class="text-right font-bold" style="font-size: 18px;">₱<?= number_format($overall_summary['total_cash'], 2) ?></td>
                        </tr>
                        <tr>
                            <td class="font-bold">Deposits Made Today:</td>
                            <td class="text-right font-bold" style="font-size: 18px;">₱<?= number_format($overall_summary['total_deposits'], 2) ?></td>
                        </tr>
                        <tr>
                            <td class="font-bold" style="font-size: 18px;">TOTAL CASH IN BANK:</td>
                            <td class="text-right font-bold" style="font-size: 24px;">₱<?= number_format($overall_summary['total_cash'] + $overall_summary['total_deposits'], 2) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            </div>
        </div>
    </div>
    </div>
    <!-- END FUEL TAB -->
    
    <!-- MERCHANDISE TAB CONTENT -->
    <div id="merchandise-tab" class="tab-content <?= $active_tab === 'merchandise' ? 'active' : '' ?>">
        <div class="container">
            <div class="header">
                <h1>DAILY MERCHANDISE & SERVICE SALES REPORT</h1>
                <h1 style="font-size: 18px; margin-top: 5px;">24-HOUR SUMMARY</h1>
                <p><?= htmlspecialchars($station_name) ?> <?= $station_location ? '- ' . htmlspecialchars($station_location) : '' ?></p>
                <p><strong>Date:</strong> <?= date('F d, Y', strtotime($report_date)) ?></p>
            </div>
            
            <div class="content">
                <!-- Merchandise Sales Table -->
                <div class="section-title">
                    MERCHANDISE SALES TABLE
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Product Name</th>
                                <th class="text-right">Beginning Stock</th>
                                <th class="text-right">Stock-In</th>
                                <th class="text-right">Stock-Out</th>
                                <th class="text-right">Ending Stock</th>
                                <th class="text-right">Unit Price</th>
                                <th class="text-right">Amount</th>
                                <th>Encoder</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $merch_transactions = $merchandise_report_transactions;
                            $total_merch_amount = 0;
                            
                            if (count($merch_transactions) > 0):
                                foreach ($merch_transactions as $trans): 
                                    $total_merch_amount += $trans['total_amount'];
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($trans['category']) ?></td>
                                <td class="font-bold"><?= htmlspecialchars($trans['product_name']) ?></td>
                                <td class="text-right">—</td>
                                <td class="text-right">—</td>
                                <td class="text-right"><?= number_format($trans['stock_out'], 2) ?></td>
                                <td class="text-right">—</td>
                                <td class="text-right">₱<?= number_format($trans['unit_price'], 2) ?></td>
                                <td class="text-right font-bold">₱<?= number_format($trans['total_amount'], 2) ?></td>
                                <td><?= htmlspecialchars($trans['encoder'] ?? 'N/A') ?></td>
                            </tr>
                            <?php 
                                endforeach;
                            else:
                            ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 40px;">
                                    No merchandise sales found for this date.
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php if (count($merch_transactions) > 0): ?>
                            <tr style="font-weight: 700;">
                                <td colspan="7" class="text-right">TOTAL</td>
                                <td class="text-right">₱<?= number_format($total_merch_amount, 2) ?></td>
                                <td></td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Service Income Table -->
                <div class="section-title">
                    SERVICE INCOME TABLE
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Service Type</th>
                                <th class="text-right">Labor Fee</th>
                                <th>Parts Used</th>
                                <th class="text-right">Total Service Amount</th>
                                <th>Encoder</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $service_transactions = $service_income_transactions;
                            $total_service_amount = 0;
                            
                            if (count($service_transactions) > 0):
                                foreach ($service_transactions as $trans): 
                                    $total_service_amount += $trans['total_amount'];
                            ?>
                            <tr>
                                <td class="font-bold"><?= htmlspecialchars($trans['service_type']) ?></td>
                                <td class="text-right">₱<?= number_format($trans['labor_fee'], 2) ?></td>
                                <td><?= htmlspecialchars($trans['parts_used'] ?? '—') ?></td>
                                <td class="text-right font-bold">₱<?= number_format($trans['total_amount'], 2) ?></td>
                                <td><?= htmlspecialchars($trans['encoder'] ?? 'N/A') ?></td>
                            </tr>
                            <?php 
                                endforeach;
                            else:
                            ?>
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 40px;">
                                    No service income found for this date.
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php if (count($service_transactions) > 0): ?>
                            <tr style="font-weight: 700;">
                                <td colspan="3" class="text-right">TOTAL</td>
                                <td class="text-right">₱<?= number_format($total_service_amount, 2) ?></td>
                                <td></td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Shift Summaries -->
                <div class="section-title">
                    SHIFT SALES SUMMARIES
                </div>
                <div class="shift-boxes">
                    <!-- Shift 1 -->
                    <div class="shift-box">
                        <h3>SHIFT 1 (6AM-2PM)</h3>
                        <table>
                            <tbody>
                                <tr>
                                    <td class="font-bold">Merchandise Sales:</td>
                                    <td class="text-right font-bold">₱<?= number_format($shift1_summary['merchandise_sales'], 2) ?></td>
                                </tr>
                                <tr>
                                    <td class="font-bold">Service Income:</td>
                                    <td class="text-right font-bold">₱<?= number_format($shift1_summary['service_income'] ?? 0, 2) ?></td>
                                </tr>
                                <tr><td colspan="2" style="height: 10px;"></td></tr>
                                <tr>
                                    <td colspan="2" class="font-bold">Payment Breakdown</td>
                                </tr>
                                <tr>
                                    <td>Cash:</td>
                                    <td class="text-right">₱<?= number_format($shift1_summary['cash'], 2) ?></td>
                                </tr>
                                <tr>
                                    <td>Card:</td>
                                    <td class="text-right">₱<?= number_format($shift1_summary['card'], 2) ?></td>
                                </tr>
                                <tr>
                                    <td>E-Wallet:</td>
                                    <td class="text-right">₱<?= number_format($shift1_summary['ewallet'], 2) ?></td>
                                </tr>
                                <tr>
                                    <td>Credit/Suki:</td>
                                    <td class="text-right">₱<?= number_format($shift1_summary['credit'], 2) ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Shift 2 -->
                    <div class="shift-box">
                        <h3>SHIFT 2 (2PM-12AM)</h3>
                        <table>
                            <tbody>
                                <tr>
                                    <td class="font-bold">Merchandise Sales:</td>
                                    <td class="text-right font-bold">₱<?= number_format($shift2_summary['merchandise_sales'], 2) ?></td>
                                </tr>
                                <tr>
                                    <td class="font-bold">Service Income:</td>
                                    <td class="text-right font-bold">₱<?= number_format($shift2_summary['service_income'] ?? 0, 2) ?></td>
                                </tr>
                                <tr><td colspan="2" style="height: 10px;"></td></tr>
                                <tr>
                                    <td colspan="2" class="font-bold">Payment Breakdown</td>
                                </tr>
                                <tr>
                                    <td>Cash:</td>
                                    <td class="text-right">₱<?= number_format($shift2_summary['cash'], 2) ?></td>
                                </tr>
                                <tr>
                                    <td>Card:</td>
                                    <td class="text-right">₱<?= number_format($shift2_summary['card'], 2) ?></td>
                                </tr>
                                <tr>
                                    <td>E-Wallet:</td>
                                    <td class="text-right">₱<?= number_format($shift2_summary['ewallet'], 2) ?></td>
                                </tr>
                                <tr>
                                    <td>Credit/Suki:</td>
                                    <td class="text-right">₱<?= number_format($shift2_summary['credit'], 2) ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- A/R Summary -->
                <?php if (count($ar_summary) > 0): ?>
                <div class="section-title">
                    A/R SUMMARY (Suki/Credit Customers)
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Customer Name</th>
                                <th>Contact Number</th>
                                <th>Type</th>
                                <th class="text-right">Outstanding Balance</th>
                                <th class="text-right">Credit Limit</th>
                                <th class="text-right">Available Credit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_ar = 0;
                            foreach ($ar_summary as $ar): 
                                $total_ar += $ar['balance'];
                                $available = $ar['credit_limit'] - $ar['balance'];
                            ?>
                            <tr>
                                <td class="font-bold"><?= htmlspecialchars($ar['name']) ?></td>
                                <td><?= htmlspecialchars($ar['contact_number'] ?? '-') ?></td>
                                <td><?= strtoupper($ar['type']) ?></td>
                                <td class="text-right font-bold">₱<?= number_format($ar['balance'], 2) ?></td>
                                <td class="text-right">₱<?= number_format($ar['credit_limit'], 2) ?></td>
                                <td class="text-right">₱<?= number_format($available, 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr style="font-weight: 700;">
                                <td colspan="3" class="text-right">TOTAL ACCOUNTS RECEIVABLE</td>
                                <td class="text-right">₱<?= number_format($total_ar, 2) ?></td>
                                <td colspan="2"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                
                <!-- Overall Summary -->
                <div class="section-title">
                    OVERALL DAILY MERCHANDISE SUMMARY
                </div>
                <div class="summary-grid">
                    <div class="summary-card">
                        <div class="label">Total Merchandise Sales</div>
                        <div class="value">₱<?= number_format($overall_summary['total_merchandise_sales'], 2) ?></div>
                    </div>
                    
                    <div class="summary-card">
                        <div class="label">Total Service Income</div>
                        <div class="value">₱<?= number_format($total_service_amount, 2) ?></div>
                    </div>
                    
                    <div class="summary-card">
                        <div class="label">Grand Total Sales</div>
                        <div class="value">₱<?= number_format($overall_summary['total_merchandise_sales'] + $total_service_amount, 2) ?></div>
                    </div>
                    
                    <div class="summary-card">
                        <div class="label">Total Cash Collection</div>
                        <div class="value">₱<?= number_format($overall_summary['total_cash'], 2) ?></div>
                    </div>
                </div>
                
                <!-- Total Cash in Bank -->
                <div class="section-title">
                    TOTAL CASH IN BANK
                </div>
                <div class="table-container">
                    <table>
                        <tbody>
                            <tr>
                                <td class="font-bold" style="width: 70%;">Cash on Hand:</td>
                                <td class="text-right font-bold" style="font-size: 18px;">₱<?= number_format($overall_summary['total_cash'], 2) ?></td>
                            </tr>
                            <tr>
                                <td class="font-bold">Deposits Made Today:</td>
                                <td class="text-right font-bold" style="font-size: 18px;">₱<?= number_format($overall_summary['total_deposits'], 2) ?></td>
                            </tr>
                            <tr>
                                <td class="font-bold" style="font-size: 18px;">TOTAL CASH IN BANK:</td>
                                <td class="text-right font-bold" style="font-size: 24px;">₱<?= number_format($overall_summary['total_cash'] + $overall_summary['total_deposits'], 2) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <!-- END MERCHANDISE TAB -->
</div>

<script>
    let currentReportTab = '<?= htmlspecialchars($active_tab, ENT_QUOTES) ?>';

    function switchTab(tabName, evt) {
        currentReportTab = tabName;
        // Hide all tabs
        document.querySelectorAll('.tab-content').forEach(tab => {
            tab.classList.remove('active');
        });
        
        // Remove active from all buttons
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        
        // Show selected tab
        document.getElementById(tabName + '-tab').classList.add('active');
        
        // Highlight selected button
        if (evt && evt.target) {
            evt.target.classList.add('active');
        }
        
        // Update export button
        const exportBtn = document.getElementById('export-excel-btn');
        const reportDate = document.getElementById('report_date').value;
        if (tabName === 'merchandise') {
            exportBtn.href = `?export=excel&type=merchandise&report_date=${reportDate}`;
        } else {
            exportBtn.href = `?export=excel&type=fuel&report_date=${reportDate}`;
        }
    }
    
    function applyFilters() {
        const reportDate = document.getElementById('report_date').value;
        window.location.href = `?report_date=${reportDate}&tab=${currentReportTab}`;
    }
    
    // Allow Enter key to apply filters
    document.getElementById('report_date').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            applyFilters();
        }
    });
</script>

<?php
// Include system footer
require_once __DIR__ . '/../partials/footer.php';
?>
