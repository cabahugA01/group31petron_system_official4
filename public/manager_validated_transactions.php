<?php
/**
 * MANAGER VALIDATED TRANSACTIONS
 * 
 * Shows official and corrected transactions
 * Manager can view saved transactions and their details
 * Uses NEW tables: merchandise_transactions, job_orders
 * Design: Petron Blue (#002F70)
 */
$page_id = 'validated_transactions_manager';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/transaction_schema_fix.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();

// Only Manager/Admin can access
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Manager role required.';
    header('Location: staff_dashboard.php'); exit;
}

// â”€â”€ Dynamic column detection â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function vt_cols(PDO $pdo, string $table): array {
    try {
        $rows = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $r) $map[strtolower($r['Field'])] = true;
        return $map;
    } catch (Exception $e) { return []; }
}
function vt_has(array $map, string $col): bool { return isset($map[strtolower($col)]); }

$mt_cols = vt_cols($pdo, 'merchandise_transactions');
$jo_cols = vt_cols($pdo, 'job_orders');
$mti_cols = vt_cols($pdo, 'merchandise_transaction_items');
$user_cols = vt_cols($pdo, 'users');

// â”€â”€ Payment status helper â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function vt_pay_status(array $row): string {
    $total = (float)($row['amount'] ?? 0);
    $paid  = isset($row['amount_paid']) ? (float)$row['amount_paid'] : null;
    if ($paid === null) {
        $pm = strtolower(trim($row['payment_method'] ?? ''));
        return ($pm !== '' && $pm !== 'n/a') ? 'Paid' : 'Unpaid';
    }
    if ($paid <= 0)            return 'Unpaid';
    if ($paid < $total - 0.01) return 'Partial';
    return 'Paid';
}

function vt_filter_key(string $value): string {
    return strtolower(preg_replace('/[^a-z0-9]+/', '', trim($value)));
}

function vt_payment_condition(string $expr, string $value, array &$params): string {
    $key = vt_filter_key($value);
    if ($key === '') return '';

    $normalized = "LOWER(REPLACE(REPLACE(REPLACE(TRIM(COALESCE({$expr},'')), '-', ''), ' ', ''), '_', ''))";
    $raw = "LOWER(TRIM(COALESCE({$expr},'')))";

    if ($key === 'cash') {
        return "{$normalized} = 'cash'";
    }
    if ($key === 'card') {
        return "({$normalized} IN ('card','creditcard','debitcard') OR {$raw} LIKE '%card%')";
    }
    if ($key === 'ewallet') {
        return "({$normalized} IN ('ewallet','gcash','maya','paymaya','online') OR {$raw} LIKE '%wallet%' OR {$raw} LIKE '%gcash%' OR {$raw} LIKE '%maya%')";
    }
    if ($key === 'petronefuel' || $key === 'efuel') {
        return "({$normalized} LIKE '%efuel%' OR {$raw} LIKE '%e-fuel%' OR {$raw} LIKE '%petron%')";
    }
    if ($key === 'fleetcard') {
        return "({$normalized} LIKE '%fleet%')";
    }
    if ($key === 'credit') {
        return "({$normalized} = 'credit' OR {$raw} LIKE '%credit%')";
    }

    $params[] = $key;
    return "{$normalized} = ?";
}

function vt_shift_keys(string $value): array {
    $key = vt_filter_key($value);
    if (in_array($key, ['1', 'first', 'shift1'], true)) return ['1', 'first', 'shift1'];
    if (in_array($key, ['2', 'second', 'shift2'], true)) return ['2', 'second', 'shift2'];
    if (in_array($key, ['3', 'third', 'shift3'], true)) return ['3', 'third', 'shift3'];
    return $key === '' ? [] : [$key];
}

function vt_shift_condition(string $expr, string $value, array &$params): string {
    $keys = vt_shift_keys($value);
    if (!$keys) return '';
    $normalized = "LOWER(REPLACE(REPLACE(REPLACE(TRIM(COALESCE({$expr},'')), '-', ''), ' ', ''), '_', ''))";
    $params = array_merge($params, $keys);
    return "{$normalized} IN (" . implode(',', array_fill(0, count($keys), '?')) . ")";
}

function vt_shift_display_case(string $expr): string {
    $normalized = "LOWER(REPLACE(REPLACE(REPLACE(TRIM(COALESCE({$expr},'')), '-', ''), ' ', ''), '_', ''))";
    return "CASE
        WHEN {$normalized} IN ('1','first','shift1') THEN 'Shift 1'
        WHEN {$normalized} IN ('2','second','shift2') THEN 'Shift 2'
        WHEN {$normalized} IN ('3','third','shift3') THEN 'Shift 3'
        ELSE COALESCE(NULLIF(TRIM({$expr}),''), 'N/A')
    END";
}

// â”€â”€ Filters â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$search         = trim($_GET['search']         ?? '');
// Default: show last 90 days so historical staff records are always visible
$date_from      = trim($_GET['date_from']      ?? date('Y-m-d', strtotime('-90 days')));
$date_to        = trim($_GET['date_to']        ?? date('Y-m-d'));
$type_filter    = trim($_GET['type']           ?? ''); // 'merchandise' | 'job_order' | ''
$payment_method = trim($_GET['payment_method'] ?? ''); // 'Cash' | 'GCash' | etc
$payment_status = trim($_GET['payment_status'] ?? ''); // 'Paid' | 'Unpaid' | 'Partial'
$staff_filter   = trim($_GET['staff']          ?? ''); // staff_id
$shift_filter   = trim($_GET['shift']          ?? ''); // 'Shift 1' | 'Shift 2' | 'Shift 3'
$status_filter  = trim($_GET['status']         ?? ''); // 'Completed' | 'Voided' | 'Adjusted'

// Fetch official merchandise + job orders.
$rows = [];
$total_amount = 0.0;

// Merchandise official transactions
// IMPORTANT: Show ALL transactions from staff - no validation_status filter
// This ensures all merchandise and job order transactions encoded by staff are visible
$mt_status_col = vt_has($mt_cols, 'validation_status') ? 'mt.validation_status' : "'Approved'";
$mt_staff_col  = vt_has($mt_cols, 'staff_id') ? "CASE WHEN mt.staff_id > 0 THEN COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Staff') ELSE 'Staff (Legacy)' END" : "'Staff'";
// Use created_at as primary date column - it always exists
$mt_date_col   = vt_has($mt_cols, 'transaction_date') ? "COALESCE(NULLIF(mt.transaction_date, '0000-00-00'), mt.created_at)" : 'mt.created_at';
$mt_paid_col   = vt_has($mt_cols, 'amount_paid') ? 'mt.amount_paid' : 'NULL';
$mt_vby_col    = vt_has($mt_cols, 'validated_by') ? "COALESCE(NULLIF(CONCAT(v.first_name,' ',v.last_name),' '), v.username, 'N/A')" : "'N/A'";
$mt_shift_sources = [];
if (vt_has($mt_cols, 'shift_period')) $mt_shift_sources[] = 'mt.shift_period';
if (vt_has($mt_cols, 'shift_name')) $mt_shift_sources[] = 'mt.shift_name';
if (vt_has($user_cols, 'assigned_shift')) $mt_shift_sources[] = 'u.assigned_shift';
if (vt_has($user_cols, 'shift_assignment')) $mt_shift_sources[] = 'u.shift_assignment';
$mt_shift_expr = $mt_shift_sources ? 'COALESCE(' . implode(',', $mt_shift_sources) . ", '')" : "''";
$mt_shift_col  = vt_shift_display_case($mt_shift_expr);
$mt_staff_id   = vt_has($mt_cols, 'staff_id') ? 'mt.staff_id' : 'NULL';
$mt_validated_join = vt_has($mt_cols, 'validated_by') ? 'LEFT JOIN users v ON v.id = mt.validated_by' : '';

$mt_vehicle_expr = "COALESCE(
    NULLIF(TRIM(mt.job_order_vehicle_plate), ''),
    NULLIF(TRIM(jo_mt.vehicle_plate), ''),
    '—'
) AS vehicle_plate";
$mt_jo_service_expr = vt_has($mt_cols, 'job_order_service') ? "COALESCE(NULLIF(TRIM(mt.job_order_service),''),'')" : "''";

// IMPORTANT: Show ALL transactions from staff - no validation_status filter
// This ensures all merchandise and job order transactions encoded by staff are visible
$mt_where  = "WHERE mt.station_id = ?";
$mt_params = [$station_id];
if ($search !== '') {
    $term = "%$search%";
    $mt_search = [];
    foreach (['customer_name', 'transaction_id', 'job_order_id', 'job_order_service', 'job_order_description', 'job_order_vehicle_plate', 'customer_first_name', 'customer_last_name', 'customer_id'] as $col) {
        if (vt_has($mt_cols, $col)) {
            $mt_search[] = "COALESCE(mt.`{$col}`,'') LIKE ?";
            $mt_params[] = $term;
        }
    }
    $item_search = [];
    foreach (['product_name', 'category', 'item_type'] as $col) {
        if (vt_has($mti_cols, $col)) {
            $item_search[] = "COALESCE(mti_s.`{$col}`,'') LIKE ?";
            $mt_params[] = $term;
        }
    }
    if ($item_search) {
        $mt_search[] = "EXISTS (SELECT 1 FROM merchandise_transaction_items mti_s WHERE mti_s.transaction_id = mt.id AND (" . implode(' OR ', $item_search) . "))";
    }
    if ($mt_search) {
        $mt_where .= " AND (" . implode(' OR ', $mt_search) . ")";
    }
}
if ($date_from !== '') {
    $mt_where .= " AND DATE({$mt_date_col}) >= ?";
    $mt_params[] = $date_from;
}
if ($date_to !== '') {
    $mt_where .= " AND DATE({$mt_date_col}) <= ?";
    $mt_params[] = $date_to;
}
if ($payment_method !== '') {
    $payment_sql = vt_payment_condition('mt.payment_method', $payment_method, $mt_params);
    if ($payment_sql !== '') $mt_where .= " AND {$payment_sql}";
}
if ($staff_filter !== '') {
    $mt_where .= " AND mt.staff_id = ?";
    $mt_params[] = $staff_filter;
}
if ($shift_filter !== '') {
    $shift_sql = vt_shift_condition($mt_shift_expr, $shift_filter, $mt_params);
    if ($shift_sql !== '') $mt_where .= " AND {$shift_sql}";
}

$mt_rows = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            mt.id AS row_id,
            mt.transaction_id AS txn_id,
            COALESCE(NULLIF(TRIM(mt.customer_name),''),'Walk-in') AS customer,
            CASE 
                WHEN mt.transaction_type = 'combined' THEN 'Combined'
                WHEN mt.transaction_type = 'job_order' THEN 'Job Order'
                ELSE 'Merchandise'
            END AS entry_type,
            GROUP_CONCAT(CONCAT(mti.product_name, '::', COALESCE(mti.size_variant,''), '::', mti.quantity) ORDER BY mti.id SEPARATOR '||') AS items,
            GROUP_CONCAT(CONCAT(mti.product_name, ' (x', mti.quantity, ')') ORDER BY mti.id SEPARATOR ', ') AS items_service,
            {$mt_vehicle_expr},
            mt.total_amount AS amount,
            {$mt_paid_col} AS amount_paid,
            COALESCE(mt.payment_method,'Cash') AS payment_method,
            {$mt_date_col} AS txn_date,
            COALESCE({$mt_status_col},'Approved') AS validation_status,
            COALESCE(mt.workflow_status, 'Pending') AS workflow_status,
            COALESCE({$mt_staff_col},'Unknown') AS staff_name,
            {$mt_staff_id} AS staff_id,
            COALESCE({$mt_shift_col},'N/A') AS shift,
            COALESCE({$mt_vby_col},'N/A') AS validated_by,
            'merchandise_transactions' AS _source,
            COALESCE(
                NULLIF(TRIM(COALESCE(mt.manager_notes,'')), ''),
                NULLIF(TRIM(COALESCE(mt.remarks,'')), ''),
                NULLIF(TRIM(COALESCE(mt.adjustment_reason,'')), ''),
                NULLIF(TRIM(COALESCE(mt.rejection_reason,'')), ''),
                ''
            ) AS validation_remarks,
            COALESCE(
                NULLIF(TRIM(mt.job_order_service), ''),
                jo_mt.service_type,
                (SELECT GROUP_CONCAT(product_name SEPARATOR ', ') FROM merchandise_transaction_items WHERE transaction_id = mt.id AND (item_type = 'service' OR category LIKE '%Service%') AND category != 'Labor' AND product_name NOT LIKE '%Labor%'),
                ''
            ) AS service_type,
            COALESCE(
                jo_mt.estimated_cost,
                (SELECT NULLIF(SUM(subtotal),0) FROM merchandise_transaction_items WHERE transaction_id = mt.id AND (item_type = 'service' OR category LIKE '%Service%') AND category != 'Labor' AND product_name NOT LIKE '%Labor%'),
                CASE WHEN (mt.job_order_service IS NOT NULL AND TRIM(mt.job_order_service) != '') OR mt.transaction_type IN ('job_order', 'combined') THEN mt.total_amount ELSE 0 END,
                0
            ) AS service_fee,
            COALESCE(
                jo_mt.actual_labor_cost,
                jo_mt.estimated_labor_cost,
                (SELECT COALESCE(SUM(subtotal),0) FROM merchandise_transaction_items WHERE transaction_id = mt.id AND (category = 'Labor' OR product_name LIKE '%Labor%')),
                0
            ) AS labor_fee,
            {$mt_jo_service_expr} AS job_order_service
        FROM merchandise_transactions mt
        LEFT JOIN users u ON u.id = mt.staff_id
        {$mt_validated_join}
        LEFT JOIN job_orders jo_mt ON jo_mt.id = mt.job_order_db_id
        LEFT JOIN merchandise_transaction_items mti ON mti.transaction_id = mt.id AND COALESCE(mti.item_type,'') != 'service' AND COALESCE(mti.category,'') NOT LIKE '%Service%'
        {$mt_where}
        GROUP BY mt.id
        ORDER BY txn_date DESC
        LIMIT 500
    ");
    $stmt->execute($mt_params);
    $mt_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { 
    error_log("ERROR fetching merchandise transactions: " . $e->getMessage());
    $mt_rows = []; 
}

// Job Orders official/completed
// IMPORTANT: Show ALL job orders from staff - no status filter
$jo_status_col = vt_has($jo_cols, 'validation_status') ? 'jo.validation_status' : 'jo.status';
$jo_staff_col  = vt_has($jo_cols, 'created_by') ? 'COALESCE(jo.created_by, jo.user_id)' : 'jo.user_id';
$jo_pay_col    = vt_has($jo_cols, 'payment_method') ? "COALESCE(jo.payment_method,'N/A')" : "'N/A'";
$jo_cost_col   = vt_has($jo_cols, 'total_cost') ? 'COALESCE(jo.total_cost,0)' : 'COALESCE(jo.estimated_cost,0)';
$jo_paid_col   = vt_has($jo_cols, 'amount_paid') ? 'jo.amount_paid' : 'NULL';
$jo_vby_col    = vt_has($jo_cols, 'validated_by') ? "COALESCE(NULLIF(CONCAT(v.first_name,' ',v.last_name),' '), v.username, 'N/A')" : "'N/A'";
$jo_shift_sources = vt_has($jo_cols, 'shift_id') ? ['sh.name'] : [];
if (vt_has($user_cols, 'assigned_shift')) $jo_shift_sources[] = 'u.assigned_shift';
if (vt_has($user_cols, 'shift_assignment')) $jo_shift_sources[] = 'u.shift_assignment';
$jo_shift_expr = $jo_shift_sources ? 'COALESCE(' . implode(',', $jo_shift_sources) . ", '')" : "''";
$jo_shift_col  = vt_shift_display_case($jo_shift_expr);
$jo_staff_id   = vt_has($jo_cols, 'created_by') ? 'COALESCE(jo.created_by, jo.user_id)' : 'jo.user_id';
$jo_shift_join = vt_has($jo_cols, 'shift_id') ? 'LEFT JOIN shifts sh ON sh.id = jo.shift_id' : '';
$jo_validated_join = vt_has($jo_cols, 'validated_by') ? 'LEFT JOIN users v ON v.id = jo.validated_by' : '';

$jo_where  = "WHERE jo.station_id = ?";
$jo_params = [$station_id];
if ($search !== '') {
    $term = "%$search%";
    $jo_search = [];
    foreach (['customer_name', 'service_type', 'service_description', 'vehicle_plate', 'job_order_id', 'job_order_number', 'additional_notes'] as $col) {
        if (vt_has($jo_cols, $col)) {
            $jo_search[] = "COALESCE(jo.`{$col}`,'') LIKE ?";
            $jo_params[] = $term;
        }
    }
    if ($jo_search) {
        $jo_where .= " AND (" . implode(' OR ', $jo_search) . ")";
    }
}
if ($date_from !== '') {
    $jo_where .= " AND DATE(jo.created_at) >= ?";
    $jo_params[] = $date_from;
}
if ($date_to !== '') {
    $jo_where .= " AND DATE(jo.created_at) <= ?";
    $jo_params[] = $date_to;
}
if ($payment_method !== '') {
    $payment_sql = vt_payment_condition($jo_pay_col, $payment_method, $jo_params);
    if ($payment_sql !== '') $jo_where .= " AND {$payment_sql}";
}
if ($staff_filter !== '') {
    $jo_where .= " AND COALESCE(jo.created_by, jo.user_id) = ?";
    $jo_params[] = $staff_filter;
}
if ($shift_filter !== '') {
    $shift_sql = vt_shift_condition($jo_shift_expr, $shift_filter, $jo_params);
    if ($shift_sql !== '') $jo_where .= " AND {$shift_sql}";
}

$jo_rows = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            jo.id AS row_id,
            CONCAT('JO-', jo.id) AS txn_id,
            COALESCE(NULLIF(TRIM(jo.customer_name),''),'Walk-in') AS customer,
            'Job Order' AS entry_type,
            COALESCE(jo.service_type,'') AS items_service,
            COALESCE(jo.service_type,'') AS service_type,
            COALESCE(jo.estimated_cost, 0) AS service_fee,
            COALESCE(jo.actual_labor_cost, jo.estimated_labor_cost, 0) AS labor_fee,
            COALESCE(NULLIF(TRIM(jo.vehicle_plate),''),'N/A') AS vehicle_plate,
            {$jo_cost_col} AS amount,
            {$jo_paid_col} AS amount_paid,
            {$jo_pay_col} AS payment_method,
            jo.created_at AS txn_date,
            COALESCE(NULLIF(TRIM({$jo_status_col}),''),'Approved') AS validation_status,
            COALESCE(jo.status, 'Pending') AS workflow_status,
            COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown') AS staff_name,
            {$jo_staff_id} AS staff_id,
            COALESCE({$jo_shift_col},'N/A') AS shift,
            COALESCE({$jo_vby_col},'N/A') AS validated_by,
            'job_orders' AS _source,
            COALESCE(
                NULLIF(TRIM(COALESCE(jo.admin_remarks,'')), ''),
                NULLIF(TRIM(COALESCE(jo.adjustment_reason,'')), ''),
                NULLIF(TRIM(COALESCE(jo.rejection_reason,'')), ''),
                ''
            ) AS validation_remarks
        FROM job_orders jo
        LEFT JOIN users u ON u.id = COALESCE(jo.created_by, jo.user_id)
        {$jo_validated_join}
        {$jo_shift_join}
        {$jo_where}
        ORDER BY jo.created_at DESC
        LIMIT 500
    ");
    $stmt->execute($jo_params);
    $jo_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { 
    error_log("ERROR fetching job orders: " . $e->getMessage());
    $jo_rows = []; 
}

// Pre-fetch pending transaction requests for dynamic action buttons & status filter
$pending_txn_requests = [];
try {
    $pr_stmt = $pdo->query("SELECT * FROM transaction_requests WHERE status = 'Pending'");
    foreach ($pr_stmt->fetchAll(PDO::FETCH_ASSOC) as $pr_row) {
        $src = $pr_row['record_source'] ?? '';
        $rid = (int)($pr_row['record_id'] ?? 0);
        $tid = trim($pr_row['transaction_id'] ?? '');
        if ($rid) $pending_txn_requests[$src . '_' . $rid] = $pr_row;
        if ($tid) $pending_txn_requests[$src . '_' . $tid] = $pr_row;
    }
} catch (Exception $e) {
    $pending_txn_requests = [];
}

// Pre-fetch items for all merchandise transactions before filtering so we can dynamically classify and filter them accurately
$mgr_items_map = [];
try {
    $mt_ids = array_column($mt_rows, 'row_id');
    if (!empty($mt_ids)) {
        $in_pl = implode(',', array_map('intval', $mt_ids));
        $itm_stmt = $pdo->query("
            SELECT transaction_id, product_name, quantity, unit_price, subtotal,
                   COALESCE(item_type,'merchandise') AS item_type,
                   COALESCE(category,'') AS category,
                   COALESCE(size_variant,'') AS size_variant
            FROM merchandise_transaction_items
            WHERE transaction_id IN ($in_pl)
            ORDER BY transaction_id, id ASC
        ");
        foreach ($itm_stmt->fetchAll(PDO::FETCH_ASSOC) as $itm_row) {
            $mgr_items_map[(int)$itm_row['transaction_id']][] = $itm_row;
        }
    }
} catch (Exception $e) { $mgr_items_map = []; }

// â”€â”€ Items Format Helper (Matching admin_all_transactions) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (!function_exists('format_transaction_items')) {
    function format_transaction_items($raw_items_str, $htmlMode = true) {
        $raw = trim($raw_items_str ?? '');
        if ($raw === '' || $raw === '—') return '—';
        $resolveUnit = function(string $nameLower, float $qty): string {
            if (strpos($nameLower, 'refrigerant') !== false || strpos($nameLower, 'r134a') !== false)
                return $qty > 1 ? 'Cans' : 'Can';
            if (strpos($nameLower, 'oil') !== false || strpos($nameLower, 'coolant') !== false ||
                strpos($nameLower, 'fluid') !== false || strpos($nameLower, 'cleaning') !== false ||
                strpos($nameLower, 'cleaner') !== false || strpos($nameLower, 'lubricant') !== false)
                return $qty > 1 ? 'Bottles' : 'Bottle';
            if (strpos($nameLower, 'liter') !== false || strpos($nameLower, 'litre') !== false)
                return $qty > 1 ? 'Liters' : 'Liter';
            if (strpos($nameLower, 'tire') !== false || strpos($nameLower, 'tyre') !== false)
                return $qty > 1 ? 'pcs' : 'pc';
            return $qty > 1 ? 'pcs' : 'pc';
        };
        $parts = explode('||', $raw);
        $formatted = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') continue;
            $subparts = explode('::', $part);
            if (count($subparts) >= 3) {
                $name    = trim($subparts[0]);
                $variant = trim($subparts[1]);
                $qtyVal  = (float)($subparts[2]);
                $qtyNum  = ($qtyVal == (int)$qtyVal) ? (int)$qtyVal : number_format($qtyVal, 2);
                $unit    = $resolveUnit(strtolower($name), $qtyVal);
                $variantStr = ($variant !== '') ? ' [' . $variant . ']' : '';
                if ($htmlMode) {
                    $formatted[] = '<strong>' . htmlspecialchars($name . $variantStr) . '</strong><br>'
                        . '<span style="color:#64748b;font-size:10px;">Qty: ' . $qtyNum . ' ' . $unit . '</span>';
                } else {
                    $formatted[] = $name . $variantStr . ' x ' . $qtyNum . ' ' . $unit;
                }
            } else {
                if ($htmlMode) {
                    $formatted[] = htmlspecialchars($part);
                } else {
                    $formatted[] = $part;
                }
            }
        }
        if (empty($formatted)) return '—';
        return implode($htmlMode ? '<br><br>' : '; ', $formatted);
    }
}

// Helper for service classification
$svc_keywords = ['cleaning','service','repair','check','lube','lubrication','alignment','rotation','flush','replacement','inspection','wash','polish','detailing','tune','oil change','brake','adjust'];
$is_service_item = function(array $i) use ($svc_keywords): bool {
    if (strtolower(trim($i['item_type'] ?? '')) === 'service') return true;
    $nl = strtolower($i['product_name'] ?? '');
    foreach ($svc_keywords as $kw) {
        if (strpos($nl, $kw) !== false) return true;
    }
    return false;
};

// Dynamically resolve entry_type for all rows
$all_rows = array_merge($mt_rows, $jo_rows);
foreach ($all_rows as &$r) {
    $rc_row_items = [];
    if ($r['_source'] === 'merchandise_transactions') {
        $mt_id = (int)$r['row_id'];
        if ($mt_id && !empty($mgr_items_map[$mt_id])) {
            $rc_row_items = $mgr_items_map[$mt_id];
        } elseif (!empty($r['items_service']) && $r['items_service'] !== 'N/A') {
            $parts = explode(',', $r['items_service']);
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p === '') continue;
                $clean_name = trim(preg_replace('/\s*\(x\d+(?:\.\d+)?\)$/i', '', $p));
                $rc_row_items[] = [
                    'item_type'    => 'unknown',
                    'product_name' => $clean_name,
                ];
            }
        }
    } else {
        if (!empty($r['items_service'])) {
            $parts = explode(',', $r['items_service']);
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p === '') continue;
                $clean_name = trim(preg_replace('/\s*\(x\d+(?:\.\d+)?\)$/i', '', $p));
                $rc_row_items[] = [
                    'item_type'    => 'service',
                    'product_name' => $clean_name,
                ];
            }
        }
    }
    
    // Count merch and services
    $has_m = false;
    $has_s = false;
    foreach ($rc_row_items as $itm) {
        if ($is_service_item($itm)) {
            $has_s = true;
        } else {
            $has_m = true;
        }
    }
    
    $has_jo_svc = !empty(trim($r['job_order_service'] ?? ''));
    if (($has_m && $has_s) || ($has_m && $has_jo_svc)) {
        $r['entry_type'] = 'Combined';
    } elseif ($has_s || $has_jo_svc) {
        $r['entry_type'] = 'Job Order';
    } elseif ($has_m) {
        $r['entry_type'] = 'Merchandise';
    } else {
        $db_t = strtolower(trim($r['entry_type'] ?? $r['transaction_type'] ?? ''));
        if ($db_t === 'combined') $r['entry_type'] = 'Combined';
        elseif ($db_t === 'job order' || $db_t === 'job_order') $r['entry_type'] = 'Job Order';
        else $r['entry_type'] = 'Merchandise';
    }
}
unset($r);

// Apply type filter (in-PHP) using dynamic entry_type
if ($type_filter === 'merchandise') {
    $all_rows = array_filter($all_rows, fn($r) => $r['entry_type'] === 'Merchandise');
} elseif ($type_filter === 'job_order') {
    $all_rows = array_filter($all_rows, fn($r) => $r['entry_type'] === 'Job Order');
} elseif ($type_filter === 'combined') {
    $all_rows = array_filter($all_rows, fn($r) => $r['entry_type'] === 'Combined');
}

// Apply payment status filter (in-PHP since it's calculated)
if ($payment_status !== '') {
    $all_rows = array_filter($all_rows, function($r) use ($payment_status) {
        $ps = vt_pay_status($r);
        return strtolower($ps) === strtolower($payment_status);
    });
}


// Apply validation status filter
if ($status_filter !== '') {
    $all_rows = array_filter($all_rows, function($r) use ($status_filter, $pending_txn_requests) {
        $vs = strtolower(trim($r['validation_status'] ?? ''));
        $ws = strtolower(trim($r['workflow_status'] ?? ''));
        $src_key = ($r['_source'] ?? '') . '_' . ($r['row_id'] ?? '');
        $txn_key = ($r['_source'] ?? '') . '_' . ($r['txn_id'] ?? '');
        $pr = $pending_txn_requests[$src_key] ?? ($pending_txn_requests[$txn_key] ?? null);
        $req_type = $pr ? ($pr['request_type'] ?? '') : '';

        if ($status_filter === 'Voided')               return $vs === 'voided';
        if ($status_filter === 'Adjusted')             return $vs === 'adjusted';
        if ($status_filter === 'Adjustment Requested') return $req_type === 'Adjustment';
        if ($status_filter === 'Void Requested')       return $req_type === 'Void';
        if ($status_filter === 'Pending')              return in_array($ws, ['pending', 'awaiting_payment', 'draft']) || in_array($vs, ['pending', 'unvalidated']);
        if ($status_filter === 'In Progress')          return in_array($ws, ['in_progress', 'in progress']);
        if ($status_filter === 'Released')             return $ws === 'released';
        if ($status_filter === 'Completed')            return in_array($ws, ['completed', 'finished']) || (!in_array($vs, ['voided', 'adjusted'], true) && !$req_type && $ws !== 'released' && $ws !== 'in_progress' && $ws !== 'pending');
        // Default fallback
        return !in_array($vs, ['voided', 'adjusted'], true) && !$req_type;
    });
}
$rows = array_values($all_rows);
usort($rows, fn($a, $b) => strtotime($b['txn_date']) - strtotime($a['txn_date']));

// Summary card calculations (all from filtered $rows)
$kpi_total_txns  = count($rows);
$kpi_jo_count    = 0;
$kpi_merch_count = 0;
$kpi_comb_count  = 0;
$kpi_paid_count  = 0;
$kpi_unpaid_count= 0;
$kpi_ar_count    = 0;
$kpi_total_sales = 0.0;

foreach ($rows as $r) {
    $vst = strtolower(trim($r['validation_status'] ?? ''));
    $is_v = ($vst === 'voided');
    
    $entry = strtolower(trim($r['entry_type'] ?? ''));
    if ($entry === 'job order') { $kpi_jo_count++; }
    elseif ($entry === 'merchandise') { $kpi_merch_count++; }
    elseif ($entry === 'combined') { $kpi_comb_count++; }
    
    // Sales & Payment totals: EXCLUDE VOIDED
    if (!$is_v) {
        $kpi_total_sales += (float)($r['amount'] ?? 0);
        $total_amount += (float)($r['amount'] ?? 0); // for export compatibility
        $ps = vt_pay_status($r);
        $pm = strtolower(trim($r['payment_method'] ?? ''));
        if (in_array($pm, ['credit', 'account receivable', 'ar']) || strcasecmp($ps, 'Account Receivable') === 0) {
            $kpi_ar_count++;
        }
        if ($ps === 'Paid') { $kpi_paid_count++; }
        else { $kpi_unpaid_count++; }
    }
}

// Fetch staff list for Staff Encoder dropdown
// ── AJAX JSON POLLING ENDPOINT FOR ALL TRANSACTIONS ──────────────────────
if (isset($_GET['ajax_vt']) && $_GET['ajax_vt'] == '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'kpis' => [
            'total_txns'   => $kpi_total_txns,
            'merch_count'  => $kpi_merch_count,
            'jo_count'     => $kpi_jo_count,
            'comb_count'   => $kpi_comb_count,
            'paid_count'   => $kpi_paid_count,
            'unpaid_count' => $kpi_unpaid_count,
            'ar_count'     => $kpi_ar_count,
            'total_sales'  => '₱' . number_format($kpi_total_sales, 2)
        ],
        'rows_count' => count($rows)
    ]);
    exit;
}

$staff_list = [];
try {
    $stmt = $pdo->prepare("SELECT id, COALESCE(NULLIF(CONCAT(first_name,' ',last_name),' '), username) AS name FROM users WHERE station_id = ? AND role != 'admin' ORDER BY name");
    $stmt->execute([$station_id]);
    $staff_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { // ── AJAX JSON POLLING ENDPOINT FOR ALL TRANSACTIONS ──────────────────────
if (isset($_GET['ajax_vt']) && $_GET['ajax_vt'] == '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'kpis' => [
            'total_txns'   => $kpi_total_txns,
            'merch_count'  => $kpi_merch_count,
            'jo_count'     => $kpi_jo_count,
            'comb_count'   => $kpi_comb_count,
            'paid_count'   => $kpi_paid_count,
            'unpaid_count' => $kpi_unpaid_count,
            'ar_count'     => $kpi_ar_count,
            'total_sales'  => '₱' . number_format($kpi_total_sales, 2)
        ],
        'rows_count' => count($rows)
    ]);
    exit;
}

$staff_list = []; }

// â”€â”€ Server-Side Exports â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$export_type = $_GET['export'] ?? '';
if ($export_type === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Validated_Transactions_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Txn ID', 'Customer', 'Type', 'Items / Service', 'Vehicle Plate', 'Amount', 'Payment Method', 'Payment Status', 'Shift', 'Staff', 'Date', 'Validated By', 'Validation Remarks']);
    foreach ($rows as $r) {
        $items_desc = '';
        if ($r['_source'] === 'merchandise_transactions') {
            $mt_id = (int)$r['row_id'];
            if ($mt_id && !empty($mgr_items_map[$mt_id])) {
                $item_strings = [];
                foreach ($mgr_items_map[$mt_id] as $itm) {
                    $prefix = ($itm['item_type'] === 'service') ? 'ðŸ”§' : 'ðŸ“¦';
                    $qty_str = ($itm['item_type'] !== 'service' && $itm['quantity'] > 0) ? ' (x' . (int)$itm['quantity'] . ')' : '';
                    $item_strings[] = $prefix . ' ' . $itm['product_name'] . $qty_str;
                }
                $items_desc = implode(', ', $item_strings);
            } else {
                $items_desc = $r['items_service'];
            }
        } else {
            $items_desc = 'ðŸ”§ ' . $r['items_service'];
        }

        fputcsv($out, [
            $r['txn_id'],
            $r['customer'],
            $r['entry_type'],
            $items_desc,
            $r['vehicle_plate'] ?? '—',
            number_format((float)$r['amount'], 2),
            $r['payment_method'],
            vt_pay_status($r),
            $r['shift'] ?? 'N/A',
            $r['staff_name'],
            date('M d, Y H:i', strtotime($r['txn_date'])),
            $r['validated_by'],
            $r['validation_remarks'] ?? '—'
        ]);
    }
    fclose($out);
    exit;
}

if ($export_type === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="Validated_Transactions_' . date('Y-m-d') . '.xls"');
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="UTF-8">';
    echo '<style>table{border-collapse:collapse}th,td{border:1px solid #ddd;padding:7px}th{background:#002F70;color:#fff;font-weight:700}</style>';
    echo '</head><body>';
    echo '<h2>Validated Transactions Report</h2>';
    echo '<p>Generated: ' . date('F d, Y h:i A') . ' | Records: ' . count($rows) . '</p>';
    echo '<table><thead><tr>';
    foreach (['Txn ID', 'Customer', 'Type', 'Items / Service', 'Vehicle Plate', 'Amount', 'Payment Method', 'Payment Status', 'Shift', 'Staff', 'Date', 'Validated By', 'Validation Remarks'] as $h) {
        echo '<th>' . htmlspecialchars($h) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $pay_st = vt_pay_status($r);
        $items_desc = '';
        if ($r['_source'] === 'merchandise_transactions') {
            $mt_id = (int)$r['row_id'];
            if ($mt_id && !empty($mgr_items_map[$mt_id])) {
                $item_strings = [];
                foreach ($mgr_items_map[$mt_id] as $itm) {
                    $prefix = ($itm['item_type'] === 'service') ? 'ðŸ”§' : 'ðŸ“¦';
                    $qty_str = ($itm['item_type'] !== 'service' && $itm['quantity'] > 0) ? ' (x' . (int)$itm['quantity'] . ')' : '';
                    $item_strings[] = $prefix . ' ' . $itm['product_name'] . $qty_str;
                }
                $items_desc = implode(', ', $item_strings);
            } else {
                $items_desc = $r['items_service'];
            }
        } else {
            $items_desc = 'ðŸ”§ ' . $r['items_service'];
        }

        echo '<tr>';
        echo '<td>' . htmlspecialchars($r['txn_id']) . '</td>';
        echo '<td>' . htmlspecialchars($r['customer']) . '</td>';
        echo '<td>' . htmlspecialchars($r['entry_type']) . '</td>';
        echo '<td>' . htmlspecialchars($items_desc) . '</td>';
        echo '<td>' . htmlspecialchars($r['vehicle_plate'] ?? '—') . '</td>';
        echo '<td style="text-align:right">&#8369;' . number_format((float)$r['amount'], 2) . '</td>';
        echo '<td>' . htmlspecialchars($r['payment_method']) . '</td>';
        echo '<td>' . htmlspecialchars($pay_st) . '</td>';
        echo '<td>' . htmlspecialchars($r['shift'] ?? 'N/A') . '</td>';
        echo '<td>' . htmlspecialchars($r['staff_name']) . '</td>';
        echo '<td>' . date('M d, Y H:i', strtotime($r['txn_date'])) . '</td>';
        echo '<td>' . htmlspecialchars($r['validated_by']) . '</td>';
        echo '<td>' . htmlspecialchars($r['validation_remarks'] ?? '—') . '</td>';
        echo '</tr>';
    }
    echo '<tr style="font-weight:800;background:#f0f7ff">';
    echo '<td colspan="5" style="text-align:right"><strong>TOTAL</strong></td>';
    echo '<td style="text-align:right"><strong>&#8369;' . number_format($total_amount, 2) . '</strong></td>';
    echo '<td colspan="7"></td>';
    echo '</tr>';
    echo '</tbody></table></body></html>';
    exit;
}

if ($export_type === 'pdf') {
    header('Content-Type: text/html; charset=utf-8');
    $logo_url  = '../assets/img/Petron%20Logo.png';
    $generated = date('F d, Y  h:i A');
    $rec_count = count($rows);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
    echo '<title>Validated Transactions | Petron Station Management</title>';
    echo '<style>';
    echo '@page{size:A4 landscape;margin:0.4in 0.3in;}';
    echo 'body{font-family:Arial,Helvetica,sans-serif;font-size:9px;margin:0;padding:0;background:#fff;color:#1e293b;}';
    echo '.report{background:#fff;max-width:100%;margin:0;border-radius:0;overflow:hidden;}';
    echo '.rpt-header{background:linear-gradient(135deg,#002F70 0%,#003d8a 100%);padding:14px 20px;display:flex;align-items:center;gap:14px;}';
    echo '.rpt-header img{height:38px;width:auto;}';
    echo '.rpt-header-text h1{color:#fff;font-size:15px;font-weight:800;margin:0 0 2px;}';
    echo '.rpt-header-text p{color:#93c5fd;font-size:10px;margin:0;}';
    echo '.rpt-header-meta{margin-left:auto;text-align:right;color:#bfdbfe;font-size:9px;line-height:1.6;}';
    echo '.rpt-header-meta strong{color:#fff;}';
    echo '.rpt-body{padding:12px;overflow-x:auto;}';
    echo 'table{width:100%;border-collapse:collapse;font-size:8px;table-layout:fixed;}';
    echo 'thead tr{background:#002F70;}';
    echo 'th{padding:5px 3px;color:#fff;font-weight:700;text-align:left;font-size:7.5px;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}';
    echo 'td{padding:4px 3px;border-bottom:1px solid #e2e8f0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}';
    echo 'tr:nth-child(even) td{background:#f8fafc;}';
    echo '.amount{text-align:right;font-weight:700;color:#002F70;}';
    echo '.total-row td{background:#f0f7ff!important;font-weight:800;color:#002F70;border-top:2px solid #002F70;}';
    echo '.rpt-footer{padding:10px 20px;background:#f8fafc;border-top:2px solid #e2e8f0;font-size:8px;color:#64748b;text-align:center;}';
    // Column widths
    echo 'th:nth-child(1),td:nth-child(1){width:10%;}'; // Txn ID
    echo 'th:nth-child(2),td:nth-child(2){width:9%;}'; // Customer
    echo 'th:nth-child(3),td:nth-child(3){width:7%;}'; // Type
    echo 'th:nth-child(4),td:nth-child(4){width:15%;}'; // Items/Service
    echo 'th:nth-child(5),td:nth-child(5){width:8%;}'; // Vehicle Plate
    echo 'th:nth-child(6),td:nth-child(6){width:7%;}'; // Amount
    echo 'th:nth-child(7),td:nth-child(7){width:6%;}'; // Payment Method
    echo 'th:nth-child(8),td:nth-child(8){width:6%;}'; // Payment Status
    echo 'th:nth-child(9),td:nth-child(9){width:5%;}'; // Shift
    echo 'th:nth-child(10),td:nth-child(10){width:8%;}'; // Staff
    echo 'th:nth-child(11),td:nth-child(11){width:8%;}'; // Date
    echo 'th:nth-child(12),td:nth-child(12){width:7%;}'; // Validated By
    echo 'th:nth-child(13),td:nth-child(13){width:4%;}'; // Validation Remarks
    // Print styles - remove all URLs and footer
    echo '@media print{';
    echo 'body{background:#fff;margin:0;}';
    echo '.report{margin:0;max-width:100%;}';
    echo '@page{size:A4 landscape;margin:0.3in 0.25in;}';
    echo 'a[href]:after{content:none !important;display:none !important;}'; // Remove URL after links
    echo 'a{text-decoration:none !important;color:inherit !important;}'; // Remove link styling
    echo '.rpt-footer{display:none !important;}'; // Hide footer completely during print
    echo '}';
    echo '</style>';
    echo '<script>';
    echo 'window.onload=function(){window.print();setTimeout(function(){window.close();},100);};';
    echo 'window.onafterprint=function(){window.close();};';
    echo '</script>';
    echo '</head><body>';
    echo '<div class="report">';
    echo '<div class="rpt-header">';
    echo '  <img src="' . $logo_url . '" alt="Petron Logo">';
    echo '  <div class="rpt-header-text"><h1>Petron Station Management System</h1><p>Validated Transactions Report</p></div>';
    echo '  <div class="rpt-header-meta">';
    echo '    <div><strong>Generated:</strong> ' . $generated . '</div>';
    echo '    <div><strong>Total Records:</strong> ' . $rec_count . '</div>';
    echo '  </div>';
    echo '</div>';
    echo '<div class="rpt-body"><table><thead><tr>';
    foreach (['Txn ID', 'Customer', 'Type', 'Items / Service', 'Vehicle Plate', 'Amount', 'Payment Method', 'Payment Status', 'Shift', 'Staff', 'Date', 'Validated By', 'Validation Remarks'] as $h) {
        echo '<th>' . htmlspecialchars($h) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $pay_st = vt_pay_status($r);
        $items_desc = '';
        if ($r['_source'] === 'merchandise_transactions') {
            $mt_id = (int)$r['row_id'];
            if ($mt_id && !empty($mgr_items_map[$mt_id])) {
                $item_strings = [];
                foreach ($mgr_items_map[$mt_id] as $itm) {
                    $prefix = ($itm['item_type'] === 'service') ? 'ðŸ”§' : 'ðŸ“¦';
                    $qty_str = ($itm['item_type'] !== 'service' && $itm['quantity'] > 0) ? ' (x' . (int)$itm['quantity'] . ')' : '';
                    $item_strings[] = $prefix . ' ' . $itm['product_name'] . $qty_str;
                }
                $items_desc = implode(', ', $item_strings);
            } else {
                $items_desc = $r['items_service'];
            }
        } else {
            $items_desc = 'ðŸ”§ ' . $r['items_service'];
        }

        echo '<tr>';
        echo '<td>' . htmlspecialchars($r['txn_id']) . '</td>';
        echo '<td>' . htmlspecialchars($r['customer']) . '</td>';
        echo '<td>' . htmlspecialchars($r['entry_type']) . '</td>';
        echo '<td>' . htmlspecialchars($items_desc) . '</td>';
        echo '<td>' . htmlspecialchars($r['vehicle_plate'] ?? '—') . '</td>';
        echo '<td class="amount">&#8369;' . number_format((float)$r['amount'], 2) . '</td>';
        echo '<td>' . htmlspecialchars($r['payment_method']) . '</td>';
        echo '<td>' . htmlspecialchars($pay_st) . '</td>';
        echo '<td>' . htmlspecialchars($r['shift'] ?? 'N/A') . '</td>';
        echo '<td>' . htmlspecialchars($r['staff_name']) . '</td>';
        echo '<td>' . date('M d, Y H:i', strtotime($r['txn_date'])) . '</td>';
        echo '<td>' . htmlspecialchars($r['validated_by']) . '</td>';
        echo '<td>' . htmlspecialchars($r['validation_remarks'] ?? '—') . '</td>';
        echo '</tr>';
        fputcsv($out, [
            $r['txn_id'],
            $r['customer'],
            $r['entry_type'],
            $items_desc,
            $r['vehicle_plate'] ?? '—',
            number_format((float)$r['amount'], 2),
            $r['payment_method'],
            vt_pay_status($r),
            $r['shift'] ?? 'N/A',
            $r['staff_name'],
            date('M d, Y H:i', strtotime($r['txn_date'])),
            $r['validated_by'],
            $r['validation_remarks'] ?? '—'
        ]);
    }
    fclose($out);
    exit;
}

if ($export_type === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="Validated_Transactions_' . date('Y-m-d') . '.xls"');
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="UTF-8">';
    echo '<style>table{border-collapse:collapse}th,td{border:1px solid #ddd;padding:7px}th{background:#002F70;color:#fff;font-weight:700}</style>';
    echo '</head><body>';
    echo '<h2>Validated Transactions Report</h2>';
    echo '<p>Generated: ' . date('F d, Y h:i A') . ' | Records: ' . count($rows) . '</p>';
    echo '<table><thead><tr>';
    foreach (['Txn ID', 'Customer', 'Type', 'Items / Service', 'Vehicle Plate', 'Amount', 'Payment Method', 'Payment Status', 'Shift', 'Staff', 'Date', 'Validated By', 'Validation Remarks'] as $h) {
        echo '<th>' . htmlspecialchars($h) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $pay_st = vt_pay_status($r);
        $items_desc = '';
        if ($r['_source'] === 'merchandise_transactions') {
            $mt_id = (int)$r['row_id'];
            if ($mt_id && !empty($mgr_items_map[$mt_id])) {
                $item_strings = [];
                foreach ($mgr_items_map[$mt_id] as $itm) {
                    $prefix = ($itm['item_type'] === 'service') ? 'ðŸ”§' : 'ðŸ“¦';
                    $qty_str = ($itm['item_type'] !== 'service' && $itm['quantity'] > 0) ? ' (x' . (int)$itm['quantity'] . ')' : '';
                    $item_strings[] = $prefix . ' ' . $itm['product_name'] . $qty_str;
                }
                $items_desc = implode(', ', $item_strings);
            } else {
                $items_desc = $r['items_service'];
            }
        } else {
            $items_desc = 'ðŸ”§ ' . $r['items_service'];
        }

        echo '<tr>';
        echo '<td>' . htmlspecialchars($r['txn_id']) . '</td>';
        echo '<td>' . htmlspecialchars($r['customer']) . '</td>';
        echo '<td>' . htmlspecialchars($r['entry_type']) . '</td>';
        echo '<td>' . htmlspecialchars($items_desc) . '</td>';
        echo '<td>' . htmlspecialchars($r['vehicle_plate'] ?? '—') . '</td>';
        echo '<td style="text-align:right">&#8369;' . number_format((float)$r['amount'], 2) . '</td>';
        echo '<td>' . htmlspecialchars($r['payment_method']) . '</td>';
        echo '<td>' . htmlspecialchars($pay_st) . '</td>';
        echo '<td>' . htmlspecialchars($r['shift'] ?? 'N/A') . '</td>';
        echo '<td>' . htmlspecialchars($r['staff_name']) . '</td>';
        echo '<td>' . date('M d, Y H:i', strtotime($r['txn_date'])) . '</td>';
        echo '<td>' . htmlspecialchars($r['validated_by']) . '</td>';
        echo '<td>' . htmlspecialchars($r['validation_remarks'] ?? '—') . '</td>';
        echo '</tr>';
    }
    echo '<tr style="font-weight:800;background:#f0f7ff">';
    echo '<td colspan="5" style="text-align:right"><strong>TOTAL</strong></td>';
    echo '<td style="text-align:right"><strong>&#8369;' . number_format($total_amount, 2) . '</strong></td>';
    echo '<td colspan="7"></td>';
    echo '</tr>';
    echo '</tbody></table></body></html>';
    exit;
}

if ($export_type === 'pdf') {
    header('Content-Type: text/html; charset=utf-8');
    $logo_url  = '../assets/img/Petron%20Logo.png';
    $generated = date('F d, Y  h:i A');
    $rec_count = count($rows);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
    echo '<title>Validated Transactions | Petron Station Management</title>';
    echo '<style>';
    echo '@page{size:A4 landscape;margin:0.4in 0.3in;}';
    echo 'body{font-family:Arial,Helvetica,sans-serif;font-size:9px;margin:0;padding:0;background:#fff;color:#1e293b;}';
    echo '.report{background:#fff;max-width:100%;margin:0;border-radius:0;overflow:hidden;}';
    echo '.rpt-header{background:linear-gradient(135deg,#002F70 0%,#003d8a 100%);padding:14px 20px;display:flex;align-items:center;gap:14px;}';
    echo '.rpt-header img{height:38px;width:auto;}';
    echo '.rpt-header-text h1{color:#fff;font-size:15px;font-weight:800;margin:0 0 2px;}';
    echo '.rpt-header-text p{color:#93c5fd;font-size:10px;margin:0;}';
    echo '.rpt-header-meta{margin-left:auto;text-align:right;color:#bfdbfe;font-size:9px;line-height:1.6;}';
    echo '.rpt-header-meta strong{color:#fff;}';
    echo '.rpt-body{padding:12px;overflow-x:auto;}';
    echo 'table{width:100%;border-collapse:collapse;font-size:8px;table-layout:fixed;}';
    echo 'thead tr{background:#002F70;}';
    echo 'th{padding:5px 3px;color:#fff;font-weight:700;text-align:left;font-size:7.5px;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}';
    echo 'td{padding:4px 3px;border-bottom:1px solid #e2e8f0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}';
    echo 'tr:nth-child(even) td{background:#f8fafc;}';
    echo '.amount{text-align:right;font-weight:700;color:#002F70;}';
    echo '.total-row td{background:#f0f7ff!important;font-weight:800;color:#002F70;border-top:2px solid #002F70;}';
    echo '.rpt-footer{padding:10px 20px;background:#f8fafc;border-top:2px solid #e2e8f0;font-size:8px;color:#64748b;text-align:center;}';
    // Column widths
    echo 'th:nth-child(1),td:nth-child(1){width:10%;}'; // Txn ID
    echo 'th:nth-child(2),td:nth-child(2){width:9%;}'; // Customer
    echo 'th:nth-child(3),td:nth-child(3){width:7%;}'; // Type
    echo 'th:nth-child(4),td:nth-child(4){width:15%;}'; // Items/Service
    echo 'th:nth-child(5),td:nth-child(5){width:8%;}'; // Vehicle Plate
    echo 'th:nth-child(6),td:nth-child(6){width:7%;}'; // Amount
    echo 'th:nth-child(7),td:nth-child(7){width:6%;}'; // Payment Method
    echo 'th:nth-child(8),td:nth-child(8){width:6%;}'; // Payment Status
    echo 'th:nth-child(9),td:nth-child(9){width:5%;}'; // Shift
    echo 'th:nth-child(10),td:nth-child(10){width:8%;}'; // Staff
    echo 'th:nth-child(11),td:nth-child(11){width:8%;}'; // Date
    echo 'th:nth-child(12),td:nth-child(12){width:7%;}'; // Validated By
    echo 'th:nth-child(13),td:nth-child(13){width:4%;}'; // Validation Remarks
    // Print styles - remove all URLs and footer
    echo '@media print{';
    echo 'body{background:#fff;margin:0;}';
    echo '.report{margin:0;max-width:100%;}';
    echo '@page{size:A4 landscape;margin:0.3in 0.25in;}';
    echo 'a[href]:after{content:none !important;display:none !important;}'; // Remove URL after links
    echo 'a{text-decoration:none !important;color:inherit !important;}'; // Remove link styling
    echo '.rpt-footer{display:none !important;}'; // Hide footer completely during print
    echo '}';
    echo '</style>';
    echo '<script>';
    echo 'window.onload=function(){window.print();setTimeout(function(){window.close();},100);};';
    echo 'window.onafterprint=function(){window.close();};';
    echo '</script>';
    echo '</head><body>';
    echo '<div class="report">';
    echo '<div class="rpt-header">';
    echo '  <img src="' . $logo_url . '" alt="Petron Logo">';
    echo '  <div class="rpt-header-text"><h1>Petron Station Management System</h1><p>Validated Transactions Report</p></div>';
    echo '  <div class="rpt-header-meta">';
    echo '    <div><strong>Generated:</strong> ' . $generated . '</div>';
    echo '    <div><strong>Total Records:</strong> ' . $rec_count . '</div>';
    echo '  </div>';
    echo '</div>';
    echo '<div class="rpt-body"><table><thead><tr>';
    foreach (['Txn ID', 'Customer', 'Type', 'Items / Service', 'Vehicle Plate', 'Amount', 'Payment Method', 'Payment Status', 'Shift', 'Staff', 'Date', 'Validated By', 'Validation Remarks'] as $h) {
        echo '<th>' . htmlspecialchars($h) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $pay_st = vt_pay_status($r);
        $items_desc = '';
        if ($r['_source'] === 'merchandise_transactions') {
            $mt_id = (int)$r['row_id'];
            if ($mt_id && !empty($mgr_items_map[$mt_id])) {
                $item_strings = [];
                foreach ($mgr_items_map[$mt_id] as $itm) {
                    $prefix = ($itm['item_type'] === 'service') ? 'ðŸ”§' : 'ðŸ“¦';
                    $qty_str = ($itm['item_type'] !== 'service' && $itm['quantity'] > 0) ? ' (x' . (int)$itm['quantity'] . ')' : '';
                    $item_strings[] = $prefix . ' ' . $itm['product_name'] . $qty_str;
                }
                $items_desc = implode(', ', $item_strings);
            } else {
                $items_desc = $r['items_service'];
            }
        } else {
            $items_desc = 'ðŸ”§ ' . $r['items_service'];
        }

        echo '<tr>';
        echo '<td>' . htmlspecialchars($r['txn_id']) . '</td>';
        echo '<td>' . htmlspecialchars($r['customer']) . '</td>';
        echo '<td>' . htmlspecialchars($r['entry_type']) . '</td>';
        echo '<td>' . htmlspecialchars($items_desc) . '</td>';
        echo '<td>' . htmlspecialchars($r['vehicle_plate'] ?? '—') . '</td>';
        echo '<td class="amount">&#8369;' . number_format((float)$r['amount'], 2) . '</td>';
        echo '<td>' . htmlspecialchars($r['payment_method']) . '</td>';
        echo '<td>' . htmlspecialchars($pay_st) . '</td>';
        echo '<td>' . htmlspecialchars($r['shift'] ?? 'N/A') . '</td>';
        echo '<td>' . htmlspecialchars($r['staff_name']) . '</td>';
        echo '<td>' . date('M d, Y H:i', strtotime($r['txn_date'])) . '</td>';
        echo '<td>' . htmlspecialchars($r['validated_by']) . '</td>';
        echo '<td>' . htmlspecialchars($r['validation_remarks'] ?? '—') . '</td>';
        echo '</tr>';
    }
    echo '<tr class="total-row">';
    echo '<td colspan="5" style="text-align:right">TOTAL AMOUNT</td>';
    echo '<td class="amount" style="white-space:nowrap">&#8369;' . number_format($total_amount, 2) . '</td>';
    echo '<td colspan="7"></td>';
    echo '</tr>';
    echo '</tbody></table></div>';
    echo '</div></body></html>';
    exit;
}

include __DIR__ . '/../partials/header.php';
?>
<style>
.flt-btn-excel { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; } .flt-btn-excel:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
.flt-btn-pdf { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; } .flt-btn-pdf:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
/* â”€â”€ Transaction Type Badges (mirrors admin_all_transactions) â”€â”€ */
.badge{display:inline-block;padding:2px 6px;border-radius:999px;font-size:9.5px;font-weight:700;line-height:1.4;white-space:nowrap;}
.badge-green{background:#dcfce7;color:#166534;border:none;}
.badge-blue{background:#dbeafe;color:#1e40af;border:none;}
.badge-orange{background:#fff7ed;color:#9a3412;border:none;}
.badge-gray{background:#f1f5f9;color:#475569;border:none;}
.badge-red{background:#fee2e2;color:#991b1b;border:none;}
.badge-purple{background:#f3e8ff;color:#6b21a8;border:none;}
.badge i {margin-right:3px;}

/* â”€â”€ Optimized Table Layout (No scrollbar, no text clipping) â”€â”€ */
.vt-table {
    width: 100% !important;
    table-layout: fixed !important;
    border-collapse: collapse !important;
}
.vt-table thead th {
    background: #002F70 !important;
    color: #ffffff !important;
    font-size: 9.5px !important;
    font-weight: 700 !important;
    padding: 8px 3px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.3px !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    border-bottom: 2px solid #001f4d !important;
    vertical-align: middle !important;
}
.vt-table tbody td {
    padding: 6px 3px !important;
    vertical-align: middle !important;
    font-size: 10.5px !important;
    border-bottom: 1px solid #f1f5f9 !important;
}
.vt-table tbody tr:hover td {
    background: #f8fafc !important;
}
.vt-btn-act-sm {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 4px !important;
    padding: 4px 8px !important;
    font-size: 10px !important;
    font-weight: 600 !important;
    border-radius: 5px !important;
    text-decoration: none !important;
    white-space: nowrap !important;
    width: 100% !important;
    box-sizing: border-box !important;
    background: #ffffff !important;
    color: #475569 !important;
    border: 1px solid #cbd5e1 !important;
    transition: all 0.15s ease !important;
}
.vt-btn-act-sm:hover {
    background: #f8fafc !important;
    border-color: #94a3b8 !important;
    color: #1e293b !important;
    text-decoration: none !important;
}
/* Clean design: remove underlines and default link styling across tables, badges, buttons & cards */
a, a:hover, a:focus, a:visited,
.vt-table a, .vt-table button, .vt-table .badge, .vt-table td,
.txn-kpi-card, .txn-kpi-lbl, .txn-kpi-val {
    text-decoration: none !important;
}
.vt-table a:hover, .vt-table button:hover, .vt-table .badge:hover {
    text-decoration: none !important;
}
</style>

<div class="stock-page">
<div class="stock-head">
    <div>
        <h1 class="stock-title"><i class="fas fa-check-double"></i> All Transactions</h1>
    </div>
</div>

<?php
// Tab counts
$tab_voided_count = 0; $tab_adj_count = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM voided_transactions WHERE station_id=? AND DATE(void_date) BETWEEN ? AND ?");
    $s->execute([$station_id, date('Y-m-01'), date('Y-m-d')]);
    $tab_voided_count = (int)$s->fetchColumn();
} catch(Exception $e){}
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM transaction_adjustments WHERE station_id=? AND DATE(adjustment_date) BETWEEN ? AND ?");
    $s->execute([$station_id, date('Y-m-01'), date('Y-m-d')]);
    $tab_adj_count = (int)$s->fetchColumn();
} catch(Exception $e){}
?>


<?php if (isset($_SESSION['success'])): ?>
<div style="background:#d4edda;color:#155724;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
</div>
<?php endif; ?>
<?php if (isset($_SESSION['error'])): ?>
<div style="background:#f8d7da;color:#721c24;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
</div>
<?php endif; ?>

<!-- Filter Bar -->
<div class="vt-filter-card">
    <form method="GET" action="" id="vtFilterForm" style="display:flex;flex-direction:column;gap:10px;width:100%;">
        <!-- Row 1: Dropdown Filters -->
        <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
            <!-- Type Filter -->
            <div class="vt-flt-grp">
                <label class="vt-lbl"><i class="fas fa-layer-group"></i> Type</label>
                <select name="type" class="vt-inp" style="width:170px;">
                    <option value="" <?php echo $type_filter === '' ? 'selected' : ''; ?>>All Types</option>
                    <option value="job_order" <?php echo $type_filter === 'job_order' ? 'selected' : ''; ?>>Job Order</option>
                    <option value="merchandise" <?php echo $type_filter === 'merchandise' ? 'selected' : ''; ?>>Merchandise</option>
                    <option value="combined" <?php echo $type_filter === 'combined' ? 'selected' : ''; ?>>Job Order + Merchandise</option>
                </select>
            </div>
            <!-- Payment Method Filter -->
            <div class="vt-flt-grp">
                <label class="vt-lbl"><i class="fas fa-credit-card"></i> Payment</label>
                <select name="payment_method" class="vt-inp" style="width:190px;">
                    <option value="" <?php echo $payment_method === '' ? 'selected' : ''; ?>>All Methods</option>
                    <option value="Cash" <?php echo $payment_method === 'Cash' ? 'selected' : ''; ?>>Cash</option>
                    <option value="GCash" <?php echo $payment_method === 'GCash' ? 'selected' : ''; ?>>GCash</option>
                    <option value="Maya" <?php echo $payment_method === 'Maya' ? 'selected' : ''; ?>>Maya</option>
                    <option value="Credit Card" <?php echo $payment_method === 'Credit Card' ? 'selected' : ''; ?>>Credit Card</option>
                    <option value="Debit Card" <?php echo $payment_method === 'Debit Card' ? 'selected' : ''; ?>>Debit Card</option>
                    <option value="Fleet Card" <?php echo $payment_method === 'Fleet Card' ? 'selected' : ''; ?>>Petron Fleet Card</option>
                    <option value="Credit" <?php echo $payment_method === 'Credit' ? 'selected' : ''; ?>>Account Receivable / Credit Account</option>
                </select>
            </div>
            <!-- Shift Filter -->
            <div class="vt-flt-grp">
                <label class="vt-lbl"><i class="fas fa-moon"></i> Shift</label>
                <select name="shift" class="vt-inp" style="width:120px;">
                    <option value="" <?php echo $shift_filter === '' ? 'selected' : ''; ?>>All Shifts</option>
                    <option value="Shift 1" <?php echo $shift_filter === 'Shift 1' ? 'selected' : ''; ?>>Shift 1</option>
                    <option value="Shift 2" <?php echo $shift_filter === 'Shift 2' ? 'selected' : ''; ?>>Shift 2</option>
                </select>
            </div>
            <!-- Status Filter -->
            <div class="vt-flt-grp">
                <label class="vt-lbl"><i class="fas fa-circle-check"></i> Status</label>
                <select name="status" class="vt-inp" style="width:165px;">
                    <option value="" <?php echo $status_filter === '' ? 'selected' : ''; ?>>All Statuses</option>
                    <option value="Pending" <?php echo $status_filter === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="In Progress" <?php echo $status_filter === 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                    <option value="Completed" <?php echo $status_filter === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="Released" <?php echo $status_filter === 'Released' ? 'selected' : ''; ?>>Released</option>
                    <option value="Adjustment Requested" <?php echo $status_filter === 'Adjustment Requested' ? 'selected' : ''; ?>>Adjustment Requested</option>
                    <option value="Void Requested" <?php echo $status_filter === 'Void Requested' ? 'selected' : ''; ?>>Void Requested</option>
                    <option value="Adjusted" <?php echo $status_filter === 'Adjusted' ? 'selected' : ''; ?>>Adjusted</option>
                    <option value="Voided" <?php echo $status_filter === 'Voided' ? 'selected' : ''; ?>>Voided</option>
                </select>
            </div>
        </div>
        <!-- Row 2: Search, Dates, Buttons -->
        <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
            <div class="vt-flt-grp" style="flex:1;min-width:220px;">
                <label class="vt-lbl"><i class="fas fa-search"></i> Search</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                       class="vt-inp" placeholder="Search Transaction ID, OR No., Customer, JO No., Plate No." style="width:100%;">
            </div>
            <div class="vt-flt-grp">
                <label class="vt-lbl"><i class="fas fa-calendar"></i> From</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="vt-inp">
            </div>
            <div class="vt-flt-grp">
                <label class="vt-lbl"><i class="fas fa-calendar"></i> To</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="vt-inp">
            </div>
            <div style="display:flex;flex-direction:column;gap:4px;">
                <label class="vt-lbl">&nbsp;</label>
                <div style="display:flex;gap:8px;">
                    <button type="submit" class="flt-btn flt-btn-search"><i class="fas fa-search"></i> Filter</button>
                    <a href="?" class="flt-btn flt-btn-reset"><i class="fas fa-rotate-left"></i> Reset</a>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- Summary Cards -->
<div class="txn-kpi-grid" style="grid-template-columns: repeat(auto-fit, minmax(125px, 1fr)); gap: 10px; margin-bottom: 16px;">
    <div class="txn-kpi-card">
        <div class="txn-kpi-lbl"><i class="fas fa-receipt"></i> Total Txns</div>
        <div class="txn-kpi-val" id="vt_kpi_total_txns"><?php echo $kpi_total_txns; ?></div>
    </div>
    <div class="txn-kpi-card blue">
        <div class="txn-kpi-lbl"><i class="fas fa-shopping-cart"></i> Merchandise</div>
        <div class="txn-kpi-val" id="vt_kpi_merch_count"><?php echo $kpi_merch_count; ?></div>
    </div>
    <div class="txn-kpi-card purple">
        <div class="txn-kpi-lbl"><i class="fas fa-wrench"></i> Job Orders</div>
        <div class="txn-kpi-val" id="vt_kpi_jo_count"><?php echo $kpi_jo_count; ?></div>
    </div>
    <div class="txn-kpi-card teal">
        <div class="txn-kpi-lbl"><i class="fas fa-tools"></i> JO + Merch</div>
        <div class="txn-kpi-val" style="color:#0d9488;" id="vt_kpi_comb_count"><?php echo $kpi_comb_count; ?></div>
    </div>
    <div class="txn-kpi-card green">
        <div class="txn-kpi-lbl"><i class="fas fa-check-circle"></i> Paid</div>
        <div class="txn-kpi-val" id="vt_kpi_paid_count"><?php echo $kpi_paid_count; ?></div>
    </div>
    <div class="txn-kpi-card danger">
        <div class="txn-kpi-lbl"><i class="fas fa-clock"></i> Pending/Partial</div>
        <div class="txn-kpi-val" id="vt_kpi_unpaid_count"><?php echo $kpi_unpaid_count; ?></div>
    </div>
    <div class="txn-kpi-card purple">
        <div class="txn-kpi-lbl"><i class="fas fa-user-clock"></i> Account Rec.</div>
        <div class="txn-kpi-val" style="color:#7c3aed;" id="vt_kpi_ar_count"><?php echo $kpi_ar_count; ?></div>
    </div>
    <div class="txn-kpi-card total-amount-card">
        <div class="txn-kpi-lbl"><i class="fas fa-peso-sign"></i> Total Sales</div>
        <div class="txn-kpi-val" id="vt_kpi_total_sales">&#8369;<?php echo number_format($kpi_total_sales, 2); ?></div>
    </div>
</div>
    </div>
</div>

<!-- Table -->
<div class="card" style="padding:0;width:100%;">
    <div class="vt-table-wrapper">
    <table class="vt-table" style="table-layout:fixed;width:100%;">
        <colgroup>
            <col style="width:7%;"><!-- OR NO. -->
            <col style="width:8%;"><!-- TXN ID -->
            <col style="width:6.5%;"><!-- CUSTOMER -->
            <col style="width:7%;"><!-- TYPE -->
            <col style="width:10%;"><!-- PRODUCTS -->
            <col style="width:7.5%;"><!-- SERVICE TYPE -->
            <col style="width:4%;"><!-- SVC FEE -->
            <col style="width:4%;"><!-- LABOR FEE -->
            <col style="width:4%;"><!-- PLATE NO. -->
            <col style="width:4.5%;"><!-- TOTAL -->
            <col style="width:5%;"><!-- PAYMENT -->
            <col style="width:3.5%;"><!-- SHIFT -->
            <col style="width:5%;"><!-- STAFF -->
            <col style="width:9.5%;"><!-- STATUS -->
            <col style="width:6.5%;"><!-- DATE & TIME -->
            <col style="width:8%;"><!-- ACTIONS -->
        </colgroup>
        <thead>
            <tr>
                <th style="white-space:nowrap;">OR NO.</th>
                <th style="white-space:nowrap;">TXN ID</th>
                <th style="white-space:nowrap;">CUSTOMER</th>
                <th style="white-space:nowrap;">TYPE</th>
                <th style="white-space:nowrap;">PRODUCTS</th>
                <th style="white-space:nowrap;">SERVICE TYPE</th>
                <th style="text-align:right;white-space:nowrap;">SVC FEE</th>
                <th style="text-align:right;white-space:nowrap;">LABOR</th>
                <th style="text-align:center;white-space:nowrap;">PLATE NO.</th>
                <th style="text-align:right;white-space:nowrap;">TOTAL</th>
                <th style="white-space:nowrap;">PAYMENT</th>
                <th style="white-space:nowrap;">SHIFT</th>
                <th style="white-space:nowrap;">STAFF</th>
                <th style="text-align:center;white-space:nowrap;">STATUS</th>
                <th style="white-space:nowrap;">DATE & TIME</th>
                <th style="text-align:center;white-space:nowrap;">ACTIONS</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($rows) > 0): ?>
                <?php 
                // Group tracking for action buttons
                $prev_customer = null;
                $prev_date = null;
                $group_index = 0;
                
                foreach ($rows as $idx => $r): 
                    $pay_st = vt_pay_status($r); 
                    
                    // Check if this is a new group (different customer or different date)
                    $current_customer = $r['customer'];
                    $current_date = date('Y-m-d', strtotime($r['txn_date']));
                    $is_new_group = ($current_customer !== $prev_customer || $current_date !== $prev_date);
                    $show_actions = true; // Every transaction row exposes its own actions
                    
                    if ($is_new_group) {
                        $group_index++;
                        $prev_customer = $current_customer;
                        $prev_date = $current_date;
                    }
                    
                    // Build items list for this row
                    $rc_row_items = [];
                    if ($r['_source'] === 'merchandise_transactions') {
                        $mt_id = (int)$r['row_id'];
                        if ($mt_id && !empty($mgr_items_map[$mt_id])) {
                            $rc_row_items = $mgr_items_map[$mt_id];
                        } elseif (!empty($r['items_service']) && $r['items_service'] !== 'N/A') {
                            $parts = explode(',', $r['items_service']);
                            foreach ($parts as $p) {
                                $p = trim($p);
                                if ($p === '') continue;
                                $qty = 1;
                                if (preg_match('/\(x(\d+(?:\.\d+)?)\)/i', $p, $qmatch)) {
                                    $qty = (float)$qmatch[1];
                                }
                                $clean_name = trim(preg_replace('/\s*\(x\d+(?:\.\d+)?\)$/i', '', $p));
                                $rc_row_items[] = [
                                    'item_type'    => 'unknown',
                                    'product_name' => $clean_name,
                                    'quantity'     => $qty,
                                    'unit_price'   => 0,
                                    'subtotal'     => 0,
                                    'category'     => '',
                                    'size_variant' => '',
                                ];
                            }
                        }
                    } else {
                        // Job order: use items_service
                        if (!empty($r['items_service'])) {
                            $parts = explode(',', $r['items_service']);
                            foreach ($parts as $p) {
                                $p = trim($p);
                                if ($p === '') continue;
                                $qty = 1;
                                if (preg_match('/\(x(\d+(?:\.\d+)?)\)/i', $p, $qmatch)) {
                                    $qty = (float)$qmatch[1];
                                }
                                $clean_name = trim(preg_replace('/\s*\(x\d+(?:\.\d+)?\)$/i', '', $p));
                                $rc_row_items[] = [
                                    'item_type'    => 'service',
                                    'product_name' => $clean_name,
                                    'quantity'     => $qty,
                                    'unit_price'   => 0,
                                    'subtotal'     => 0,
                                    'category'     => 'Job Order',
                                    'size_variant' => $r['vehicle_plate'] ?? '',
                                ];
                            }
                        }
                    }
                    $expand_id = 'mgre_' . ($r['_source'] === 'job_orders' ? 'jo' : 'mt') . '_' . (int)$r['row_id'];
                    // Separate merchandise vs service items using keyword heuristics
                    $svc_keywords = ['cleaning','service','repair','check','lube','lubrication','alignment','rotation','flush','replacement','inspection','wash','polish','detailing','tune','oil change','brake','adjust'];
                    $is_svc_fn = function(array $i) use ($svc_keywords): bool {
                        if (strtolower(trim($i['item_type'] ?? '')) === 'service') return true;
                        $nl = strtolower($i['product_name'] ?? '');
                        foreach ($svc_keywords as $kw) {
                            if (strpos($nl, $kw) !== false) return true;
                        }
                        return false;
                    };
                    $col_svc   = array_values(array_filter($rc_row_items, fn($i) => $is_svc_fn($i)));
                    $col_merch = array_values(array_filter($rc_row_items, fn($i) => !$is_svc_fn($i)));
                    if (empty($col_svc) && !empty(trim($r['job_order_service'] ?? ''))) {
                        $col_svc = [['product_name' => trim($r['job_order_service'])]];
                    }
                    
                    // Smart unit label helper
                    $ri_unit_fn = function(string $name, float $qty): string {
                        $nl = strtolower($name);
                        $pl = $qty > 1;
                        if (strpos($nl,'refrigerant')!==false||strpos($nl,'r134a')!==false) return $pl?'Cans':'Can';
                        if (strpos($nl,'oil')!==false||strpos($nl,'coolant')!==false||strpos($nl,'fluid')!==false||strpos($nl,'lubricant')!==false) return $pl?'Bottles':'Bottle';
                        if (strpos($nl,'liter')!==false||strpos($nl,'litre')!==false) return $pl?'Liters':'Liter';
                        if (strpos($nl,'tire')!==false||strpos($nl,'tyre')!==false) return $pl?'pcs':'pc';
                        return $pl?'pcs':'pc';
                    };
                ?>
                <?php
                    $t = strtolower($r['entry_type'] ?? $r['transaction_type'] ?? '');
                    $has_items   = !empty(trim($r['items'] ?? ''));
                    $has_service = !empty(trim($r['service_type'] ?? $r['job_order_service'] ?? ''));

                    if ($has_items && $has_service) {
                        $tLabel = 'JO + Merchandise'; $tIcon = 'fa-wrench'; $tBadge = 'badge-purple';
                    } elseif ($t === 'combined') {
                        $tLabel = 'JO + Merchandise'; $tIcon = 'fa-wrench'; $tBadge = 'badge-purple';
                    } elseif ($t === 'job_order' || $t === 'job order' || $has_service) {
                        $tLabel = 'Job Order'; $tIcon = 'fa-wrench'; $tBadge = 'badge-orange';
                    } else {
                        $tLabel = 'Merchandise'; $tIcon = 'fa-shopping-cart'; $tBadge = 'badge-blue';
                    }

                    // Generate OR No. from transaction date + numeric DB id
                    $or_year = date('Y', strtotime($r['txn_date']));
                    $or_no   = ($r['_source'] === 'merchandise_transactions')
                        ? 'OR-' . $or_year . '-' . str_pad((int)$r['row_id'], 6, '0', STR_PAD_LEFT)
                        : 'JO-'  . $or_year . '-' . str_pad((int)$r['row_id'], 6, '0', STR_PAD_LEFT);
                ?>
                <tr>
                    <td style="white-space:nowrap;font-weight:700;font-size:12.5px;color:#0f172a;">
                        <?php echo htmlspecialchars($or_no); ?>
                    </td>
                    <td style="white-space:nowrap;font-size:11.5px;font-family:monospace;color:#64748b;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($r['txn_id']); ?>">
                        <?php echo htmlspecialchars($r['txn_id']); ?>
                    </td>
                    <td style="font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#1e293b;" title="<?php echo htmlspecialchars($r['customer']); ?>">
                        <?php echo htmlspecialchars($r['customer']); ?>
                    </td>
                    <td style="white-space:nowrap;">
                        <span class="badge <?php echo $tBadge; ?>"><i class="fas <?php echo $tIcon; ?>"></i> <?php echo htmlspecialchars($tLabel); ?></span>
                    </td>
                    <!-- Products column -->
                    <td style="font-size:12px;line-height:1.3;vertical-align:middle;word-break:break-word;">
                        <?= format_transaction_items($r['items'] ?? '') ?>
                    </td>
                    <!-- Service Type column -->
                    <td style="font-size:12px;color:#475569;line-height:1.3;vertical-align:middle;word-break:break-word;" title="<?php echo htmlspecialchars(trim($r['service_type'] ?? $r['job_order_service'] ?? '')); ?>">
                        <?php echo htmlspecialchars(!empty(trim($r['service_type'] ?? $r['job_order_service'] ?? '')) ? trim($r['service_type'] ?? $r['job_order_service'] ?? '') : '—'); ?>
                    </td>
                    <!-- Service Fee column -->
                    <td style="font-size:12.5px;font-weight:700;color:#2563eb;vertical-align:middle;text-align:right;white-space:nowrap;">
                        <?php
                        $s_cost = (float)($r['service_fee'] ?? 0);
                        echo $s_cost > 0 ? '₱' . number_format($s_cost, 2) : '<span style="color:#cbd5e1;font-weight:400;">—</span>';
                        ?>
                    </td>
                    <!-- Labor Fee column -->
                    <td style="font-size:12.5px;font-weight:700;color:#16a34a;vertical-align:middle;text-align:right;white-space:nowrap;">
                        <?php
                        $l_cost = (float)($r['labor_fee'] ?? 0);
                        echo $l_cost > 0 ? '₱' . number_format($l_cost, 2) : '<span style="color:#cbd5e1;font-weight:400;">—</span>';
                        ?>
                    </td>
                    <!-- Vehicle column -->
                    <td style="font-size:12.5px;text-align:center;white-space:nowrap;color:#475569;">
                      <?php
                        $veh = trim($r['vehicle_plate'] ?? '');
                        if ($veh === '' || $veh === '—' || $veh === 'N/A') {
                            echo '<span style="color:#cbd5e1;">N/A</span>';
                        } else {
                            echo htmlspecialchars($veh);
                        }
                      ?>
                    </td>
                    <!-- Total Amount column -->
                    <td style="font-weight:700;font-size:12.5px;text-align:right;white-space:nowrap;color:#0f172a;">
                        ₱<?php echo number_format((float)$r['amount'], 2); ?>
                    </td>
                    <!-- Payment Method column -->
                    <td style="font-size:12px;white-space:nowrap;color:#334155;">
                        <div><?php echo htmlspecialchars($r['payment_method']); ?></div>
                        <?php
                        $p_st_val = vt_pay_status($r);
                        $p_st_col = match(strtolower($p_st_val)) {
                            'paid' => '#16a34a',
                            'partial' => '#d97706',
                            'account receivable', 'credit', 'ar' => '#7c3aed',
                            default => '#dc2626'
                        };
                        ?>
                        <div style="font-size:11px;font-weight:700;color:<?php echo $p_st_col; ?>;"><?php echo htmlspecialchars($p_st_val); ?></div>
                    </td>
                    <td style="font-size:12px;white-space:nowrap;color:#475569;">
                        <?php 
                        $s_val = strtolower(trim($r['shift'] ?? ''));
                        $shift_time_label = match($s_val) {
                            'first', 'shift 1', '1' => 'Shift 1',
                            'second', 'shift 2', '2' => 'Shift 2',
                            default => htmlspecialchars($r['shift'] ?: 'N/A')
                        };
                        echo $shift_time_label;
                        ?>
                    </td>
                    <!-- Staff Encoder column -->
                    <td style="font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#475569;" title="<?php echo htmlspecialchars($r['staff_name']); ?>"><?php echo htmlspecialchars($r['staff_name']); ?></td>
                    <!-- Status column with badge -->
                    <td style="text-align:center;white-space:nowrap;overflow:hidden;padding:4px 2px;">
                        <?php
                        $src_key = $r['_source'] . '_' . $r['row_id'];
                        $txn_key = $r['_source'] . '_' . $r['txn_id'];
                        $pending_req = $pending_txn_requests[$src_key] ?? ($pending_txn_requests[$txn_key] ?? null);
                        
                        $vst = strtolower(trim($r['validation_status'] ?? 'completed'));
                        $wst = strtolower(trim($r['workflow_status'] ?? ''));
                        
                        $has_adj_req  = ($pending_req && ($pending_req['request_type'] ?? '') === 'Adjustment' && $vst !== 'adjusted' && $vst !== 'voided');
                        $has_void_req = ($pending_req && ($pending_req['request_type'] ?? '') === 'Void' && $vst !== 'voided');
                        
                        if ($vst === 'voided') {
                            echo '<span class="badge badge-red" style="white-space:nowrap;font-size:10.5px;padding:3px 6px;display:inline-block;vertical-align:middle;"><i class="fas fa-ban"></i> Voided</span>';
                        } elseif ($vst === 'adjusted') {
                            echo '<span class="badge badge-purple" style="white-space:nowrap;font-size:10.5px;padding:3px 6px;display:inline-block;vertical-align:middle;"><i class="fas fa-check-circle"></i> Adjusted</span>';
                        } elseif ($has_void_req) {
                            echo '<span class="badge badge-red" style="white-space:nowrap;font-size:10.5px;padding:3px 5px;display:inline-block;max-width:100%;text-overflow:ellipsis;overflow:hidden;vertical-align:middle;" title="Void Requested"><i class="fas fa-clock"></i> Void Requested</span>';
                        } elseif ($has_adj_req) {
                            echo '<span class="badge badge-orange" style="white-space:nowrap;font-size:10.5px;padding:3px 5px;display:inline-block;max-width:100%;text-overflow:ellipsis;overflow:hidden;vertical-align:middle;" title="Adjustment Requested"><i class="fas fa-clock"></i> Adjustment Requested</span>';
                        } elseif ($wst === 'in_progress' || $wst === 'in progress') {
                            echo '<span class="badge badge-blue" style="white-space:nowrap;"><i class="fas fa-spinner"></i> In Progress</span>';
                        } elseif ($wst === 'released') {
                            echo '<span class="badge badge-green" style="white-space:nowrap;"><i class="fas fa-check"></i> Released</span>';
                        } else {
                            echo '<span class="badge badge-green" style="white-space:nowrap;"><i class="fas fa-check-circle"></i> Completed</span>';
                        }
                        ?>
                    </td>
                    <!-- Date & Time column -->
                    <td style="white-space:nowrap;line-height:1.2;">
                        <div style="font-size:10px;font-weight:600;color:#334155;white-space:nowrap;"><?php echo date('M d, Y', strtotime($r['txn_date'])); ?></div>
                        <div style="font-size:9.5px;color:#64748b;white-space:nowrap;"><?php echo date('h:i A', strtotime($r['txn_date'])); ?></div>
                    </td>
                    <!-- Actions column (Manager: View Details always, Adjust/Void ONLY when requested) -->
                    <td style="text-align:center;padding:4px 2px;vertical-align:middle;white-space:nowrap;">
                        <?php if ($show_actions): ?>
                        <div style="display:flex;flex-direction:column;gap:3px;align-items:stretch;">
                            <!-- 1. View Details (Grey Outline Button) -->
                            <button type="button" class="vt-btn-act-sm"
                                    style="color:#475569;border:1px solid #cbd5e1;background:#ffffff !important;cursor:pointer;font-weight:600;padding:4px 8px;border-radius:5px;white-space:nowrap;"
                                    onclick="viewTransactionDetails('<?php echo htmlspecialchars($r['_source']); ?>', <?php echo (int)$r['row_id']; ?>)"
                                    title="View Details">
                                <i class="fas fa-eye" style="font-size:10px;margin-right:3px;"></i> View Details
                            </button>
                            
                            <?php if ($has_adj_req): ?>
                            <!-- 2. Adjust Button (Only when Staff requested Adjustment) -->
                            <button type="button" class="vt-btn-act-sm"
                                    style="color:#b45309;border:1.5px solid #f59e0b;background:#ffffff !important;cursor:pointer;font-weight:700;"
                                    onclick="openReviewRequestModal(<?php echo (int)$pending_req['id']; ?>, 'Adjustment', <?php echo (int)$r['row_id']; ?>, '<?php echo htmlspecialchars(addslashes($r['txn_id'])); ?>', '<?php echo htmlspecialchars(addslashes($r['customer'])); ?>', '<?php echo htmlspecialchars(addslashes($r['entry_type'])); ?>', '<?php echo htmlspecialchars(addslashes($r['txn_date'])); ?>', '<?php echo htmlspecialchars(addslashes($r['staff_name'])); ?>', '<?php echo htmlspecialchars(addslashes($pending_req['request_reason'])); ?>', <?php echo (float)($pending_req['new_amount'] ?? 0); ?>, '<?php echo htmlspecialchars(addslashes($r['_source'])); ?>', '<?php echo htmlspecialchars(addslashes($r['payment_method'])); ?>', '<?php echo htmlspecialchars(addslashes($r['payment_status'] ?? 'Paid')); ?>')"
                                    title="Review & Adjust">
                                <i class="fas fa-sliders-h"></i> Adjust
                            </button>
                            <?php elseif ($has_void_req): ?>
                            <!-- 3. Void Button (Only when Staff requested Void) -->
                            <button type="button" class="vt-btn-act-sm"
                                    style="color:#dc2626;border:1.5px solid #dc2626;background:#ffffff !important;cursor:pointer;font-weight:700;"
                                    onclick="openReviewRequestModal(<?php echo (int)$pending_req['id']; ?>, 'Void', <?php echo (int)$r['row_id']; ?>, '<?php echo htmlspecialchars(addslashes($r['txn_id'])); ?>', '<?php echo htmlspecialchars(addslashes($r['customer'])); ?>', '<?php echo htmlspecialchars(addslashes($r['entry_type'])); ?>', '<?php echo htmlspecialchars(addslashes($r['txn_date'])); ?>', '<?php echo htmlspecialchars(addslashes($r['staff_name'])); ?>', '<?php echo htmlspecialchars(addslashes($pending_req['request_reason'])); ?>', 0, '<?php echo htmlspecialchars(addslashes($r['_source'])); ?>', '<?php echo htmlspecialchars(addslashes($r['payment_method'])); ?>', '<?php echo htmlspecialchars(addslashes($r['payment_status'] ?? 'Paid')); ?>')"
                                    title="Review & Void">
                                <i class="fas fa-ban"></i> Void
                            </button>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="16" style="text-align:center;padding:60px 20px;color:#94a3b8;">
                        <i class="fas fa-inbox" style="font-size:48px;display:block;margin-bottom:12px;opacity:0.3;"></i>
                        <div style="font-size:16px;font-weight:600;color:#64748b;margin-bottom:4px;">No Transactions Found</div>
                        <div style="font-size:13px;">No transactions found matching your filters.</div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- View Transaction Modal -->
<div class="vt-modal-overlay" id="viewTransactionModal">
    <div class="vt-modal" style="max-width:720px;">
        <!-- Professional Dark Blue Header (matches Admin design) -->
        <div class="vt-modal-header" style="background:linear-gradient(135deg,#002F70 0%,#001f4d 100%);border-radius:14px 14px 0 0;padding:16px 24px;border-bottom:none;">
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="width:40px;height:40px;background:rgba(255,255,255,0.12);border-radius:10px;display:flex;align-items:center;justify-content:center;border:1px solid rgba(255,255,255,0.2);flex-shrink:0;">
                    <i class="fas fa-file-invoice-dollar" style="color:#ffffff;font-size:18px;"></i>
                </div>
                <div>
                    <div style="font-size:15px;font-weight:700;color:#ffffff;letter-spacing:0.3px;">Transaction Details</div>
                    <div style="display:flex;align-items:center;gap:8px;margin-top:3px;">
                        <span id="mgr-modal-or-badge" style="background:#2563eb;color:#ffffff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:4px;letter-spacing:0.5px;display:none;"></span>
                        <span id="mgr-modal-txn-id" style="font-size:11px;color:#93c5fd;font-family:monospace;"></span>
                    </div>
                </div>
            </div>
            <button class="vt-modal-close" onclick="closeViewModal()" title="Close" style="color:#ffffff !important;">&times;</button>
        </div>
        <div id="viewTransactionContent" class="vt-modal-body">
            <div style="text-align:center;padding:40px;">
                <i class="fas fa-spinner fa-spin" style="font-size:32px;color:#003d82;"></i>
                <div style="margin-top:12px;color:#64748b;">Loading transaction details...</div>
            </div>
        </div>
        <div class="vt-modal-footer" style="border-top:2px solid #e2e8f0;box-shadow:0 -2px 8px rgba(0,0,0,.05);justify-content:flex-end;">
            <button type="button" class="vt-btn vt-btn-reset" onclick="closeViewModal()"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
</div>

<!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• ADJUST MODAL -->

<!-- ========================================================================= REVIEW REQUEST MODAL -->
<div class="vt-modal-overlay" id="reviewRequestModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,.6);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:16px;">
  <div style="background:#fff;border-radius:14px;max-width:540px;width:100%;box-shadow:0 25px 50px -12px rgba(0,0,0,.25);overflow:hidden;animation:pmSlideIn .18s ease;">
    <div id="reviewReqHeader" style="padding:16px 20px;display:flex;justify-content:space-between;align-items:center;background:#475569;color:#ffffff;">
      <h3 style="margin:0;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;color:#ffffff !important;">
        <i id="reviewReqIcon" class="fas fa-edit" style="color:#ffffff !important;"></i> <span id="reviewReqTitleText" style="color:#ffffff !important;">Review Staff Request</span>
      </h3>
    </div>
    <div style="padding:20px;max-height:75vh;overflow-y:auto;">
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px;margin-bottom:14px;font-size:13px;display:grid;grid-template-columns:1fr 1fr;gap:8px 16px;">
        <div><span style="color:#64748b;font-size:11px;font-weight:700;display:block;">Transaction ID</span><strong id="reviewReqTxnId" style="font-family:monospace;color:#0f172a;">—</strong></div>
        <div><span style="color:#64748b;font-size:11px;font-weight:700;display:block;">Customer</span><span id="reviewReqCustomer" style="color:#1e293b;font-weight:600;">—</span></div>
        <div><span style="color:#64748b;font-size:11px;font-weight:700;display:block;">Transaction Type</span><span id="reviewReqType" style="color:#475569;">—</span></div>
        <div><span style="color:#64748b;font-size:11px;font-weight:700;display:block;">Transaction Date</span><span id="reviewReqDate" style="color:#475569;">—</span></div>
        <div><span style="color:#64748b;font-size:11px;font-weight:700;display:block;">Staff Encoder</span><span id="reviewReqStaff" style="color:#475569;">—</span></div>
      </div>

      <div style="background:#fff3f3;border:1.5px solid #fca5a5;border-radius:8px;padding:14px;margin-bottom:14px;" id="reviewReqReasonBox">
        <div style="font-size:11px;font-weight:800;color:#991b1b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;" id="reviewReqReasonHeader">
          <i class="fas fa-comment-alt"></i> Staff Request Reason:
        </div>
        <div id="reviewReqReasonText" style="font-size:13px;color:#1e293b;font-weight:600;white-space:pre-wrap;">—</div>
        <div id="reviewReqNewAmountRow" style="margin-top:8px;display:none;font-size:12.5px;color:#854d0e;font-weight:700;">
          Proposed New Amount: <span id="reviewReqNewAmountVal">₱0.00</span>
        </div>
      </div>

      <div>
        <label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:6px;">Manager Remarks (Required if rejecting, optional if approving)</label>
        <textarea id="reviewReqManagerRemarks" rows="2" placeholder="Enter remarks or reason for decision..." style="width:100%;padding:9px 12px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:13px;color:#1e293b;background:#fff;outline:none;resize:vertical;box-sizing:border-box;"></textarea>
      </div>
    </div>
    <div style="padding:15px 20px;border-top:1px solid #e2e8f0;display:flex;gap:10px;justify-content:space-between;align-items:center;background:#f8fafc;">
      <button type="button" onclick="closeReviewRequestModal()" class="vt-btn vt-btn-reset" style="padding:8px 16px;border:1px solid #cbd5e1;background:#ffffff;color:#64748b;border-radius:7px;font-weight:600;cursor:pointer;">
        Close
      </button>
      <div style="display:flex;gap:8px;">
        <button type="button" onclick="submitRejectRequest()" style="padding:8px 16px;background:#ffffff !important;color:#dc2626 !important;border:1.5px solid #dc2626 !important;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
          <i class="fas fa-times-circle"></i> Reject Request
        </button>
        <button type="button" id="reviewReqApproveBtn" onclick="submitApproveRequest()" style="padding:8px 18px;background:#16a34a !important;color:#ffffff !important;border:none;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
          <i class="fas fa-check-circle"></i> Approve & Proceed
        </button>
      </div>
    </div>
  </div>
</div>
<div class="vt-modal-overlay" id="adjustModal">
  <div class="vt-modal" style="max-width:720px;">
    <div class="vt-modal-header">
      <div style="display:flex;align-items:center;gap:10px;">
        <div style="width:36px;height:36px;background:#fffbeb;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <i class="fas fa-pen" style="color:#f59e0b;font-size:15px;"></i>
        </div>
        <div>
          <div style="font-size:14px;font-weight:700;color:#1e293b;">Adjust Transaction</div>
        </div>
      </div>
    </div>
    <div class="vt-modal-body" id="adjustModalBody">
      <!-- populated by JS -->
    </div>
    <div class="vt-modal-footer">
      <button type="button" class="vt-btn vt-btn-reset" onclick="closeAdjustModal()">Cancel</button>
      <button type="button" id="saveAdjustBtn" onclick="submitAdjustment()" style="background:#d97706;color:#fff;border:none;height:36px;padding:0 20px;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-save"></i> Save Adjustment</button>
    </div>
  </div>
</div>

<!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• VOID MODAL -->
<!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• VOID MODAL -->
<div class="vt-modal-overlay" id="voidModal">
  <div class="vt-modal" style="max-width:750px; width:95%;">
    <!-- Normal Modal Content -->
    <div id="voidModalMainContent" style="display:flex; flex-direction:column; max-height:calc(100vh - 130px); width:100%; overflow:hidden;">
      <div class="vt-modal-header" style="background:#fff3f3; border-bottom:1px solid #fee2e2;">
        <div style="display:flex;align-items:center;gap:10px;">
          <div>
            <div style="font-size:16px;font-weight:700;color:#991b1b;">VOID TRANSACTION</div>
          </div>
        </div>
      </div>
      
      <div class="vt-modal-body" style="padding:20px; overflow-y:auto; flex:1; min-height:0;">
        
        <!-- Two Column Layout for info -->
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
          
          <!-- Column 1: Read-Only Info -->
          <div>
            <div style="font-size:11px;font-weight:800;color:#475569;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;">
              <i class="fas fa-info-circle"></i> Transaction Information
            </div>
            <div style="display:grid;grid-template-columns:repeat(2, 1fr);gap:8px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;font-size:11px;">
              <div><span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;">Transaction ID</span><strong id="voidInfoTxnId" style="font-family:monospace;font-size:11px;color:#0f172a;">-</strong></div>
              <div><span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;">Transaction Type</span><span id="voidInfoTxnType" style="font-weight:600;color:#0f172a;">-</span></div>
              <div style="grid-column: span 2;"><span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;">Customer Name</span><span id="voidInfoCustomer" style="color:#0f172a;">-</span></div>
              <div><span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;">Processed By</span><span id="voidInfoStaff" style="color:#0f172a;">-</span></div>
              <div><span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;">Shift</span><span id="voidInfoShift" style="color:#0f172a;">-</span></div>
              <div style="grid-column: span 2;"><span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;">Date & Time</span><span id="voidInfoDateTime" style="color:#0f172a;">-</span></div>
              <div><span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;">Payment Method</span><span id="voidInfoPayMethod" style="color:#0f172a;">-</span></div>
              <div><span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;">Payment Status</span><span id="voidInfoPayStatus" style="color:#0f172a;">-</span></div>
              <div><span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;">Transaction Status</span><span id="voidInfoStatus" style="color:#0f172a;">-</span></div>
              <div><span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;">Total Amount</span><strong id="voidInfoTotalAmount" style="color:#002F70;font-size:12px;">-</strong></div>
            </div>
          </div>
          
          <!-- Column 2: Items list -->
          <div>
            <div style="font-size:11px;font-weight:800;color:#475569;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;">
              <i class="fas fa-shopping-cart"></i> Purchased Items / Services
            </div>
            <div id="voidItemsContainer" style="border:1px solid #cbd5e1;border-radius:8px;overflow-y:auto;max-height:165px;background:#fff;padding:8px;font-size:11px;">
              <!-- Dynamic content -->
            </div>
          </div>
          
        </div>

        <!-- Staff Void Request Details Banner (Auto-fetched) -->
        <div id="voidStaffRequestBanner" style="display:none; background:#eff6ff; border:1px solid #bfdbfe; border-left:4px solid #2563eb; border-radius:8px; padding:12px 14px; margin-bottom:16px;">
          <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
            <div style="font-size:11.5px; font-weight:800; color:#1e40af; text-transform:uppercase; display:flex; align-items:center; gap:6px;">
              <i class="fas fa-user-tag"></i> Staff Void Request Details (Auto-Fetched)
            </div>
            <span id="voidStaffReqDate" style="font-size:11px; color:#64748b; font-weight:600;"></span>
          </div>
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; font-size:12px;">
            <div>
              <span style="font-size:10.5px; font-weight:700; color:#64748b; text-transform:uppercase; display:block;">Requested By</span>
              <span id="voidStaffReqName" style="color:#0f172a; font-weight:700;">Staff</span>
            </div>
            <div>
              <span style="font-size:10.5px; font-weight:700; color:#64748b; text-transform:uppercase; display:block;">Staff Reason</span>
              <strong id="voidStaffReasonText" style="color:#dc2626; font-size:12.5px;">-</strong>
            </div>
            <div style="grid-column:span 2;" id="voidStaffRemarksRow">
              <span style="font-size:10.5px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:2px;">Staff Remarks / Justification</span>
              <div id="voidStaffRemarksText" style="color:#1e293b; background:#fff; padding:6px 10px; border-radius:6px; border:1px solid #cbd5e1; font-size:12px; line-height:1.4;">-</div>
            </div>
          </div>
        </div>

        <!-- Void Details & Manager Verification -->
        <div style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:14px; margin-bottom:16px;">
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:12px;">
            <div>
              <label style="font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;display:block;margin-bottom:4px;">Void Reason <span style="color:#dc2626;">*</span></label>
              <select id="voidReasonSelect" onchange="toggleVoidOtherReason()" style="width:100%;height:32px;padding:0 8px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;background:#fff;">
                <option value="" disabled selected>Select Reason...</option>
                <option value="Wrong Product">Wrong Product</option>
                <option value="Wrong Quantity">Wrong Quantity</option>
                <option value="Wrong Customer">Wrong Customer</option>
                <option value="Duplicate Transaction">Duplicate Transaction</option>
                <option value="Customer Cancelled">Customer Cancelled</option>
                <option value="Pricing Error">Pricing Error</option>
                <option value="Staff Encoding Error">Staff Encoding Error</option>
                <option value="System Error">System Error</option>
                <option value="Other">Other</option>
              </select>
            </div>
            <div>
              <label style="font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;display:block;margin-bottom:4px;">Manager Remarks <span style="color:#dc2626;">*</span></label>
              <textarea id="voidManagerRemarksNew" rows="1" placeholder="Manager notes..." oninput="validateVoidForm()" style="width:100%;height:32px;padding:6px 8px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;resize:none;box-sizing:border-box;vertical-align:middle;"></textarea>
            </div>
          </div>
          
          <div id="voidReasonOtherContainer" style="margin-bottom:12px; display:none;">
            <label style="font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;display:block;margin-bottom:4px;">If Other, Specify Reason <span style="color:#dc2626;">*</span></label>
            <input type="text" id="voidReasonOther" oninput="validateVoidForm()" placeholder="Specify void reason..." style="width:100%;height:32px;padding:0 8px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;box-sizing:border-box;">
          </div>
          
          <!-- Manager Auth -->
          <div style="padding:10px; background:#fff; border:1px solid #e2e8f0; border-radius:6px;">
            <div style="font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;margin-bottom:6px;"><i class="fas fa-lock" style="color:#2563eb;margin-right:4px;"></i>Manager Authentication <span style="color:#dc2626;">*</span></div>
            <div>
              <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:3px;">Confirm Manager Password <span style="color:#dc2626;">*</span></label>
              <div style="position:relative;width:100%;">
                <input type="password" id="voidAuthPassword" oninput="validateVoidForm()" placeholder="Enter manager password..." style="width:100%;height:34px;padding:0 36px 0 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;box-sizing:border-box;">
                <i class="fas fa-eye" id="toggleVoidPasswordIcon" onclick="toggleVoidPasswordVisibility()" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);cursor:pointer;color:#64748b;font-size:14px;padding:4px;z-index:2;" title="Show/Hide Password"></i>
              </div>
            </div>
          </div>
        </div>

        <!-- Checklist -->
        <div style="display:grid; grid-template-columns:1fr; gap:6px; margin-bottom:16px; padding:0 4px;">
          <label style="display:flex;align-items:flex-start;gap:8px;font-size:11px;color:#334155;cursor:pointer;user-select:none;">
            <input type="checkbox" class="void-checklist" onchange="validateVoidForm()" style="margin-top:2px;">
            <span>I reviewed this transaction.</span>
          </label>
          <label style="display:flex;align-items:flex-start;gap:8px;font-size:11px;color:#334155;cursor:pointer;user-select:none;">
            <input type="checkbox" class="void-checklist" onchange="validateVoidForm()" style="margin-top:2px;">
            <span>I verified the transaction details for voiding.</span>
          </label>
          <label style="display:flex;align-items:flex-start;gap:8px;font-size:11px;color:#334155;cursor:pointer;user-select:none;">
            <input type="checkbox" class="void-checklist" onchange="validateVoidForm()" style="margin-top:2px;">
            <span>I understand sales reports will be recalculated automatically.</span>
          </label>
          <label style="display:flex;align-items:flex-start;gap:8px;font-size:11px;color:#334155;cursor:pointer;user-select:none;">
            <input type="checkbox" class="void-checklist" onchange="validateVoidForm()" style="margin-top:2px;">
            <span>I understand customer history will be updated automatically.</span>
          </label>
          <label style="display:flex;align-items:flex-start;gap:8px;font-size:11px;color:#334155;cursor:pointer;user-select:none;">
            <input type="checkbox" class="void-checklist" onchange="validateVoidForm()" style="margin-top:2px;">
            <span>I understand this action cannot be undone.</span>
          </label>
        </div>


      </div>
      
      <div class="vt-modal-footer" style="padding:12px 20px; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:8px;">
        <button type="button" class="vt-btn vt-btn-reset" onclick="closeVoidModal()">Cancel</button>
        <button type="button" id="confirmVoidBtnNew" onclick="submitVoidNew()" style="background:#dc2626;color:#fff;border:none;height:38px;padding:0 22px;border-radius:7px;font-size:13.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;box-shadow:0 4px 12px rgba(220,38,38,0.25);">
          <i class="fas fa-ban"></i> Confirm Void
        </button>
      </div>
    </div>

    <!-- Success Modal View (hidden by default) -->
    <div id="voidModalSuccessContent" style="display:none; flex-direction:column; padding:30px; text-align:center; width:100%;">
      <div style="width:64px; height:64px; background:#f0fdf4; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; margin:0 auto 16px;">
        <i class="fas fa-check" style="color:#16a34a; font-size:32px;"></i>
      </div>
      <h3 style="font-size:20px; font-weight:800; color:#15803d; margin:0 0 8px;">Transaction Successfully Voided</h3>
      <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px; display:inline-block; margin:0 auto 20px; text-align:left; font-size:13px; min-width:300px;">
        <div style="margin-bottom:6px;"><span style="color:#64748b; font-weight:600;">Transaction ID:</span> <strong id="voidSuccessTxnId" style="font-family:monospace; margin-left:8px;">-</strong></div>
        <div style="margin-bottom:12px;"><span style="color:#64748b; font-weight:600;">Status:</span> <span style="background:#fef2f2; color:#991b1b; border:1px solid #fecaca; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:700; margin-left:8px;">VOIDED</span></div>
        <div style="display:flex; flex-direction:column; gap:4px; color:#166534; font-weight:600; font-size:12px; margin-top:10px; border-top:1px solid #e2e8f0; padding-top:10px;">
          <div><i class="fas fa-check-circle" style="color:#16a34a;margin-right:4px;"></i>Inventory Restored Successfully</div>
          <div><i class="fas fa-check-circle" style="color:#16a34a;margin-right:4px;"></i>Sales Updated Successfully</div>
          <div><i class="fas fa-check-circle" style="color:#16a34a;margin-right:4px;"></i>Audit Log Created</div>
        </div>
      </div>
      <div>
        <button type="button" class="vt-btn vt-btn-reset" style="background:#16a34a !important; color:#fff !important; border-color:#16a34a !important; height:40px; padding:0 24px;" onclick="closeVoidModalReload()">Done</button>
      </div>
    </div>
  </div>
</div>

<style>
/* == GLOBAL OVERFLOW FIX == */
html, body { 
    overflow-x: hidden !important; 
    max-width: 100vw !important;
    box-sizing: border-box !important;
    margin: 0 !important;
    padding: 0 !important;
}
* { 
    box-sizing: border-box !important; 
}

/* Add padding to prevent right side cut-off */
.content-wrapper {
    overflow-x: hidden !important;
    max-width: 100% !important;
}
.main-content {
    overflow-x: hidden !important;
    max-width: 100% !important;
}
.stock-page {
    margin: 0 !important;
    padding: 0 !important;
    max-width: 100% !important;
    width: 100% !important;
    box-sizing: border-box !important;
}
.stock-head {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    flex-wrap: wrap !important;
    gap: 15px !important;
    margin-top: 0 !important;
    margin-bottom: 25px !important;
}
.stock-title {
    font-size: 24px !important;
    font-weight: 700 !important;
    color: #002f70 !important;
    margin: 0 !important;
    line-height: 1.2 !important;
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif !important;
    letter-spacing: 0.5px !important;
    text-transform: uppercase !important;
}

/* == PAGE HEADER - matches SuperAdmin page-head standard == */
.page-head.txn-page-head { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px; margin-top:16px !important; }
.page-head.txn-page-head h1 { font-size:22px !important; font-weight:700 !important; color:var(--petron-blue,#00264D) !important; margin:0 !important; text-transform:none !important; display:flex; align-items:center; gap:8px; }
.page-head.txn-page-head .sub { font-size:13px; color:#666; margin-top:4px; text-transform:none !important; font-weight:400 !important; }

/* == Shared export/action buttons (flt-btn style) == */
.flt-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 0 16px;
    height: 36px;
    border-radius: 7px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    white-space: nowrap;
    transition: all .15s;
    background: white !important;
    border: 1px solid transparent;
}
.flt-btn-search { color: #00264D !important; border-color: #00264D !important; }
.flt-btn-search:hover { background: #00264D !important; color: #fff !important; }
.flt-btn-reset  { color: #6b7280 !important; border-color: #6b7280 !important; }
.flt-btn-reset:hover  { background: #6b7280 !important; color: #fff !important; }
.flt-btn-excel { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.flt-btn-excel:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
.flt-btn-pdf { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.flt-btn-pdf:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }

/* == Petron Clean KPI Summary Cards == */
.txn-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    margin-bottom: 18px;
}
.txn-kpi-card {
    background: transparent;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px 16px;
    box-shadow: none;
    transition: transform .15s, box-shadow .15s;
}
.txn-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, .08);
}
.txn-kpi-lbl {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: #64748b;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.txn-kpi-val {
    font-size: 24px;
    font-weight: 800;
    color: #002F70;
    line-height: 1.1;
}
.txn-kpi-card.blue .txn-kpi-val { color: #0369a1; }
.txn-kpi-card.purple .txn-kpi-val { color: #7c3aed; }
.txn-kpi-card.green .txn-kpi-val { color: #16a34a; }
.txn-kpi-card.orange .txn-kpi-val { color: #ea580c; }
.txn-kpi-card.danger .txn-kpi-val { color: #dc2626; }

/* Special Gradient Card for Total Amount */
.txn-kpi-card.total-amount-card {
    background: transparent;
    border-left: 1px solid #e2e8f0;
}
.txn-kpi-card.total-amount-card .txn-kpi-lbl {
    color: #64748b;
}
.txn-kpi-card.total-amount-card .txn-kpi-val {
    color: #002F70;
}

/* Filter Card */
.vt-filter-card { 
    background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px 18px;margin-bottom:14px;box-shadow:0 1px 4px rgba(0,0,0,.05);
}
.vt-flt-grp { display:flex;flex-direction:column;gap:4px; }
.vt-lbl { font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px; }
.vt-inp { 
    height:36px;padding:0 12px;border:1px solid #cbd5e1;border-radius:7px;font-size:13px;color:#1e293b;background:#fff;outline:none;box-sizing:border-box; 
}
.vt-inp:focus { border-color:#002F70;box-shadow:0 0 0 3px rgba(0,47,112,.1); }
.vt-btn { 
    display:inline-flex;align-items:center;gap:6px;padding:0 18px;height:36px;
    border:1px solid transparent;border-radius:7px;font-size:13px;font-weight:600;
    cursor:pointer;text-decoration:none;white-space:nowrap;transition:all .15s;
    background:white !important;
}
.vt-btn-search { color:#002F70 !important; border-color:#002F70 !important; }
.vt-btn-search:hover { background:#002F70 !important; color:#fff !important; }
.vt-btn-reset  { color:#4b5563 !important; border-color:#6b7280 !important; }
.vt-btn-reset:hover { background:#6b7280 !important; color:#fff !important; }

.vt-table { 
    width:100%;
    border-collapse:collapse;
    font-size:12.5px;
    table-layout:auto;
    min-width: 1200px;
}
.vt-table thead th { 
    background:#002F70;color:#fff;font-size:12px;font-weight:700;text-transform:uppercase;
    letter-spacing:.2px;padding:8px 8px;border-bottom:2px solid #001a3d;
    text-align:left;vertical-align:middle;white-space:normal;word-wrap:break-word;
}
.vt-table tbody td { 
    padding:9px 8px;
    border-bottom:1px solid #f1f5f9;
    vertical-align:top;
    background:#fff;
    font-size:12.5px;
    white-space:normal;
    word-wrap:break-word;
    overflow:hidden;
    line-height:1.4;
}
.vt-table tbody tr:hover td { background:#eff6ff; }

/* Prevent horizontal scrolling on entire page */
body { overflow-x:hidden !important; max-width:100vw !important; }
.content-wrapper { max-width:100% !important; overflow-x:hidden !important; }
.card { max-width:100% !important; overflow:visible !important; }

/* Table wrapper - allow horizontal scroll ONLY on the table container itself */
.vt-table-wrapper {
    width: 100% !important;
    max-width: 100% !important;
    overflow-x: auto !important;
    overflow-y: visible !important;
    box-sizing: border-box !important;
}

/* Make filter responsive */
.vt-filter-card form { max-width:100% !important; overflow-x:hidden !important; }

/* Badges */
.vt-badge { 
    display:inline-block;padding:4px 10px;border-radius:4px;font-size:12px;font-weight:600;white-space:nowrap;background:#f8fafc;color:#64748b;border:1px solid #e2e8f0;
}
.vt-badge-merch { background:#f0fdf4;color:#15803d;border-color:#bbf7d0; }
.vt-badge-jo { background:#fffbeb;color:#b45309;border-color:#fde68a; }
.vt-badge-combined { background:#f5f3ff;color:#6d28d9;border-color:#ddd6fe; }
.vt-badge-paid { background:#f0fdf4;color:#166534;border-color:#bbf7d0; }
.vt-badge-partial { background:#fef3c7;color:#92400e;border-color:#fde047; }
.vt-badge-unpaid { background:#fef2f2;color:#991b1b;border-color:#fecaca; }

/* Item chips & expand rows */
.rc-item-chip{display:inline-flex;align-items:center;gap:3px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:3px;padding:3px 8px;font-size:12px;font-weight:600;color:#374151;margin:1px 2px 1px 0;white-space:normal;word-break:break-word;max-width:100%;cursor:pointer}
.rc-item-chip.svc{background:#fffbeb;border-color:#fde68a;color:#92400e}
.rc-item-chip .rc-chip-qty{background:#002F70;color:#fff;border-radius:2px;padding:0 3px;font-size:10px;margin-left:2px}
.rc-expand-row td{background:#f8fafc;padding:0}
.rc-expand-inner{padding:10px 16px;border-top:2px solid #e2e8f0}
.rc-expand-tbl{width:100%;border-collapse:collapse;font-size:12.5px}
.rc-expand-tbl th{padding:5px 10px;text-align:left;font-size:11.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid #e2e8f0}
.rc-expand-tbl td{padding:5px 10px;border-bottom:1px solid #f1f5f9}
.rc-expand-tbl tr:last-child td{border-bottom:none}
.rc-row-main{cursor:pointer}
.rc-row-main:hover td{background:#eff6ff !important}

/* Action Buttons - ensure they're always visible */
.vt-btn-action { 
    background: transparent !important;
    width:100%;
    min-width:65px;
    max-width:100%;
    height:22px;
    border-radius:4px;
    border:1px solid transparent;
    cursor:pointer;
    font-size:9px;
    font-weight:600;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:4px;
    transition:all .15s;
    padding:0 6px;
    white-space:nowrap;
    box-sizing:border-box;
text-transform: uppercase !important;
}

/* == PAGE HEADER - matches SuperAdmin page-head standard == */
.page-head.txn-page-head { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px; margin-top:16px !important; }
.page-head.txn-page-head h1 { font-size:22px !important; font-weight:700 !important; color:var(--petron-blue,#00264D) !important; margin:0 !important; text-transform:none !important; display:flex; align-items:center; gap:8px; }
.page-head.txn-page-head .sub { font-size:13px; color:#666; margin-top:4px; text-transform:none !important; font-weight:400 !important; }

/* == Shared export/action buttons (flt-btn style) == */
.flt-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 0 16px;
    height: 36px;
    border-radius: 7px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    white-space: nowrap;
    transition: all .15s;
    background: white !important;
    border: 1px solid transparent;
}
.flt-btn-search { color: #00264D !important; border-color: #00264D !important; }
.flt-btn-search:hover { background: #00264D !important; color: #fff !important; }
.flt-btn-reset  { color: #6b7280 !important; border-color: #6b7280 !important; }
.flt-btn-reset:hover  { background: #6b7280 !important; color: #fff !important; }
.flt-btn-excel { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.flt-btn-excel:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
.flt-btn-pdf { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.flt-btn-pdf:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }

/* == Petron Clean KPI Summary Cards == */
.txn-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    margin-bottom: 18px;
}
.txn-kpi-card {
    background: transparent;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px 16px;
    box-shadow: none;
    transition: transform .15s, box-shadow .15s;
}
.txn-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, .08);
}
.txn-kpi-lbl {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: #64748b;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.txn-kpi-val {
    font-size: 24px;
    font-weight: 800;
    color: #002F70;
    line-height: 1.1;
}
.txn-kpi-card.blue .txn-kpi-val { color: #0369a1; }
.txn-kpi-card.purple .txn-kpi-val { color: #7c3aed; }
.txn-kpi-card.green .txn-kpi-val { color: #16a34a; }
.txn-kpi-card.orange .txn-kpi-val { color: #ea580c; }
.txn-kpi-card.danger .txn-kpi-val { color: #dc2626; }

/* Special Gradient Card for Total Amount */
.txn-kpi-card.total-amount-card {
    background: transparent;
    border-left: 1px solid #e2e8f0;
}
.txn-kpi-card.total-amount-card .txn-kpi-lbl {
    color: #64748b;
}
.txn-kpi-card.total-amount-card .txn-kpi-val {
    color: #002F70;
}

/* Filter Card */
.vt-filter-card { 
    background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px 18px;margin-bottom:14px;box-shadow:0 1px 4px rgba(0,0,0,.05);
}
.vt-flt-grp { display:flex;flex-direction:column;gap:4px; }
.vt-lbl { font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px; }
.vt-inp { 
    height:36px;padding:0 12px;border:1px solid #cbd5e1;border-radius:7px;font-size:13px;color:#1e293b;background:#fff;outline:none;box-sizing:border-box; 
}
.vt-inp:focus { border-color:#002F70;box-shadow:0 0 0 3px rgba(0,47,112,.1); }
.vt-btn { 
    display:inline-flex;align-items:center;gap:6px;padding:0 18px;height:36px;
    border:1px solid transparent;border-radius:7px;font-size:13px;font-weight:600;
    cursor:pointer;text-decoration:none;white-space:nowrap;transition:all .15s;
    background:white !important;
}
.vt-btn-search { color:#002F70 !important; border-color:#002F70 !important; }
.vt-btn-search:hover { background:#002F70 !important; color:#fff !important; }
.vt-btn-reset  { color:#4b5563 !important; border-color:#6b7280 !important; }
.vt-btn-reset:hover { background:#6b7280 !important; color:#fff !important; }

.vt-table { 
    width: 100% !important;
    min-width: 100% !important;
    max-width: 100% !important;
    border-collapse: collapse !important;
    table-layout: fixed !important;
}
.vt-table thead th { 
    background:#002F70 !important;color:#fff !important;font-size:10.5px !important;font-weight:700 !important;text-transform:uppercase !important;
    letter-spacing:.1px !important;padding:8px 3px !important;border-bottom:2px solid #001a3d !important;
    text-align:left;vertical-align:middle;white-space:nowrap !important;
    overflow:hidden; text-overflow:ellipsis;
}
.vt-table tbody td { 
    padding:7px 3px !important;
    border-bottom:1px solid #f1f5f9 !important;
    vertical-align:middle !important;
    background:#fff;
    font-size:11px !important;
    line-height:1.3;
    overflow:hidden;
    text-overflow:ellipsis;
}
.vt-table tbody tr:hover td { background:#eff6ff !important; }

/* Prevent horizontal scrolling on entire page */
body { overflow-x:hidden !important; max-width:100vw !important; }
.content-wrapper { max-width:100% !important; overflow-x:hidden !important; }
.card { max-width:100% !important; overflow:hidden !important; }

/* Table wrapper - no horizontal scroll */
.vt-table-wrapper {
    width: 100% !important;
    max-width: 100% !important;
    overflow-x: hidden !important;
    overflow-y: visible !important;
    box-sizing: border-box !important;
}

/* Make filter responsive */
.vt-filter-card form { max-width:100% !important; overflow-x:hidden !important; }

/* Badges */
.vt-badge { 
    display:inline-block;padding:4px 10px;border-radius:4px;font-size:12px;font-weight:600;white-space:nowrap;background:#f8fafc;color:#64748b;border:1px solid #e2e8f0;
}
.vt-badge-merch { background:#f0fdf4;color:#15803d;border-color:#bbf7d0; }
.vt-badge-jo { background:#fffbeb;color:#b45309;border-color:#fde68a; }
.vt-badge-combined { background:#f5f3ff;color:#6d28d9;border-color:#ddd6fe; }
.vt-badge-paid { background:#f0fdf4;color:#166534;border-color:#bbf7d0; }
.vt-badge-partial { background:#fef3c7;color:#92400e;border-color:#fde047; }
.vt-badge-unpaid { background:#fef2f2;color:#991b1b;border-color:#fecaca; }

/* Item chips & expand rows */
.rc-item-chip{display:inline-flex;align-items:center;gap:3px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:3px;padding:3px 8px;font-size:12px;font-weight:600;color:#374151;margin:1px 2px 1px 0;white-space:normal;word-break:break-word;max-width:100%;cursor:pointer}
.rc-item-chip.svc{background:#fffbeb;border-color:#fde68a;color:#92400e}
.rc-item-chip .rc-chip-qty{background:#002F70;color:#fff;border-radius:2px;padding:0 3px;font-size:10px;margin-left:2px}
.rc-expand-row td{background:#f8fafc;padding:0}
.rc-expand-inner{padding:10px 16px;border-top:2px solid #e2e8f0}
.rc-expand-tbl{width:100%;border-collapse:collapse;font-size:12.5px}
.rc-expand-tbl th{padding:5px 10px;text-align:left;font-size:11.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid #e2e8f0}
.rc-expand-tbl td{padding:5px 10px;border-bottom:1px solid #f1f5f9}
.rc-expand-tbl tr:last-child td{border-bottom:none}
.rc-row-main{cursor:pointer}
.rc-row-main:hover td{background:#eff6ff !important}

/* Action Buttons - ensure they're always visible */
.vt-btn-action { 
    background: transparent !important;
    width:100%;
    min-width:65px;
    max-width:100%;
    height:22px;
    border-radius:4px;
    border:1px solid transparent;
    cursor:pointer;
    font-size:9px;
    font-weight:600;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:4px;
    transition:all .15s;
    padding:0 6px;
    white-space:nowrap;
    box-sizing:border-box;
}
.vt-btn-view   { color:#002F70 !important; border-color:#002F70 !important; }
.vt-btn-view:hover { background:#002F70 !important; color:#fff !important; }

/* View Modal */
.vt-modal-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,.65); backdrop-filter:blur(3px); z-index:999999; align-items:center; justify-content:center; padding:16px; box-sizing:border-box; }
.vt-modal-overlay.active { display:flex; }
.vt-modal { background:#fff; border-radius:12px; width:100%; max-width:760px; box-shadow:0 20px 50px rgba(0,0,0,.3); max-height:min(88vh, 650px) !important; display:flex; flex-direction:column; overflow:hidden; margin:auto; }
.vt-modal-header { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid #e2e8f0; flex-shrink:0; }
.vt-modal-header h3 { margin:0; font-size:18px; font-weight:700; color:#1e293b; display:flex; align-items:center; }
.vt-modal-close { background:none; border:none; font-size:28px; color:#64748b; cursor:pointer; padding:0; width:32px; height:32px; border-radius:6px; }
.vt-modal-close:hover { background:#f1f5f9; color:#1e293b; }
.vt-modal-body { padding:20px; overflow-y:auto; flex:1; min-height:0; }
.vt-modal-footer { padding:14px 20px; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:8px; flex-shrink:0; background:#fff; }
.vt-detail-grid { display:grid; grid-template-columns:150px 1fr; gap:12px 20px; font-size:14.5px; }
.vt-detail-label { font-weight:600; color:#64748b; }
.vt-detail-value { color:#1e293b; }
.vt-detail-amount { color:#002F70; font-weight:700; font-size:16px; }
</style>

<script>
function viewTransactionDetails(source, id) {
    return viewValidatedTransaction(source, id);
}

function viewValidatedTransaction(source, id, orNo, txnIdStr) {
    // Set header badges
    const orBadge = document.getElementById('mgr-modal-or-badge');
    const txnIdEl = document.getElementById('mgr-modal-txn-id');
    if (orNo && orBadge) { orBadge.textContent = orNo; orBadge.style.display = ''; }
    else if (orBadge) { orBadge.style.display = 'none'; }
    if (txnIdEl) txnIdEl.textContent = txnIdStr ? 'ID: ' + txnIdStr : '';

    // Set receipt button link (will be updated after fetch)
    const recBtn = document.getElementById('mgrReceiptPrintBtn');
    if (recBtn) recBtn.href = '#';

    // Open modal with spinner
    document.getElementById('viewTransactionModal').classList.add('active');
    document.getElementById('viewTransactionContent').innerHTML =
        '<div style="text-align:center;padding:40px;"><i class="fas fa-spinner fa-spin" style="font-size:32px;color:#003d82;"></i><div style="margin-top:12px;color:#64748b;">Loading...</div></div>';

    // Fetch transaction details
    fetch('../backend/get_transaction_details.php?type=' + source + '&id=' + id)
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                document.getElementById('viewTransactionContent').innerHTML =
                    '<div style="text-align:center;padding:40px;color:#dc2626;"><i class="fas fa-exclamation-circle" style="font-size:32px;display:block;margin-bottom:12px;"></i>' + (data.error || 'Unable to load details') + '</div>';
                return;
            }

            // Set receipt btn href
            if (recBtn) {
                const rType = data.type === 'job_order' ? 'job_order' : 'merchandise';
                recBtn.href = 'receipt.php?id=' + encodeURIComponent(id) + '&type=' + rType;
            }

            let html = '';

            /* ── STATUS BANNER ── */
            const vs = (data.validation_status || '').toLowerCase();
            let bannerBg='#f0fdf4', bannerClr='#166534', bannerIcon='fa-check-circle', bannerLabel=data.validation_status||'Completed';
            if (vs.includes('void'))   { bannerBg='#fef2f2'; bannerClr='#dc2626'; bannerIcon='fa-ban'; }
            else if (vs.includes('adjust')) { bannerBg='#faf5ff'; bannerClr='#6b21a8'; bannerIcon='fa-edit'; }
            const txnTypeLbl = data.type === 'job_order' ? 'Job Order' : 'Merchandise';
            html += `<div style="background:${bannerBg};border:1px solid ${bannerClr}33;border-radius:8px;padding:10px 14px;margin-bottom:16px;display:flex;align-items:center;gap:10px;">
                <i class="fas ${bannerIcon}" style="color:${bannerClr};font-size:18px;flex-shrink:0;"></i>
                <div>
                    <div style="font-size:13.5px;font-weight:800;color:${bannerClr};text-transform:uppercase;letter-spacing:.4px;">${bannerLabel}</div>
                    <div style="font-size:12.5px;color:#64748b;">${txnTypeLbl} Transaction &bull; ${data.transaction_date||''}</div>
                </div>
            </div>`;

            html += '<div class="vt-detail-grid">';

            if (data.type === 'merchandise') {
                html += `<div class="vt-detail-label">Transaction ID:</div><div class="vt-detail-value" style="font-family:monospace;font-weight:600;">${data.transaction_id}</div>`;
                html += `<div class="vt-detail-label">Customer:</div><div class="vt-detail-value">${data.customer_name}</div>`;
                html += `<div class="vt-detail-label">Item SKU:</div><div class="vt-detail-value" style="font-family:monospace;font-weight:700;color:#002F70;">${data.item_sku}</div>`;
                html += `<div class="vt-detail-label">Total Quantity:</div><div class="vt-detail-value">${data.quantity}</div>`;
                html += `<div class="vt-detail-label">Payment Method:</div><div class="vt-detail-value">${data.payment_method}</div>`;
                if (data.amount_tendered && data.amount_tendered !== 'N/A') {
                    html += `<div class="vt-detail-label">Amount Tendered:</div><div class="vt-detail-value">&#8369;${data.amount_tendered}</div>`;
                    html += `<div class="vt-detail-label">Sukli / Change:</div><div class="vt-detail-value">&#8369;${data.change_amount}</div>`;
                }
                html += `<div class="vt-detail-label">Transaction Date:</div><div class="vt-detail-value">${data.transaction_date}</div>`;
                html += `<div class="vt-detail-label">Staff Encoder:</div><div class="vt-detail-value">${data.staff_name}</div>`;
                if (data.shift && data.shift !== 'N/A') { html += `<div class="vt-detail-label">Shift:</div><div class="vt-detail-value">${data.shift}</div>`; }
                html += `<div class="vt-detail-label">Status:</div><div class="vt-detail-value"><span style="background:${bannerBg};color:${bannerClr};padding:3px 10px;border-radius:4px;font-size:13px;font-weight:700;">${bannerLabel}</span></div>`;
                html += `<div class="vt-detail-label">Validated By:</div><div class="vt-detail-value">${data.validated_by}</div>`;
                html += `<div class="vt-detail-label">Validated At:</div><div class="vt-detail-value">${data.validated_at}</div>`;
                if (data.remarks && data.remarks !== 'N/A') { html += `<div class="vt-detail-label">Remarks:</div><div class="vt-detail-value">${data.remarks}</div>`; }
                html += `<div class="vt-detail-label">Total Amount:</div><div class="vt-detail-amount" style="font-size:18px;font-weight:800;">&#8369;${data.total_amount}</div>`;

            } else if (data.type === 'job_order') {
                html += `<div class="vt-detail-label">Job Order #:</div><div class="vt-detail-value" style="font-family:monospace;font-weight:600;">${data.transaction_id}</div>`;
                html += `<div class="vt-detail-label">Customer:</div><div class="vt-detail-value">${data.customer_name}</div>`;
                html += `<div class="vt-detail-label">Vehicle Plate:</div><div class="vt-detail-value">${data.vehicle_plate}</div>`;
                html += `<div class="vt-detail-label">Vehicle Type:</div><div class="vt-detail-value">${data.vehicle_type}</div>`;
                html += `<div class="vt-detail-label">Service Type:</div><div class="vt-detail-value">${data.service_type}</div>`;
                html += `<div class="vt-detail-label">Description:</div><div class="vt-detail-value">${data.service_description}</div>`;
                html += `<div class="vt-detail-label">Required Parts:</div><div class="vt-detail-value" style="font-size:12px;">${data.required_parts}</div>`;
                html += `<div class="vt-detail-label">Mechanic:</div><div class="vt-detail-value">${data.mechanic_name}</div>`;
                html += `<div class="vt-detail-label">Estimated Cost:</div><div class="vt-detail-value">&#8369;${data.estimated_cost}</div>`;
                html += `<div class="vt-detail-label">Amount Paid:</div><div class="vt-detail-value">&#8369;${data.amount_paid}</div>`;
                html += `<div class="vt-detail-label">Sukli / Change:</div><div class="vt-detail-value">&#8369;${data.change_amount}</div>`;
                html += `<div class="vt-detail-label">Payment Method:</div><div class="vt-detail-value">${data.payment_method}</div>`;
                html += `<div class="vt-detail-label">Payment Status:</div><div class="vt-detail-value">${data.payment_status}</div>`;
                html += `<div class="vt-detail-label">Job Status:</div><div class="vt-detail-value">${data.job_status}</div>`;
                html += `<div class="vt-detail-label">Staff Encoder:</div><div class="vt-detail-value">${data.staff_name}</div>`;
                html += `<div class="vt-detail-label">Created Date:</div><div class="vt-detail-value">${data.transaction_date}</div>`;
                html += `<div class="vt-detail-label">Validated By:</div><div class="vt-detail-value">${data.validated_by}</div>`;
                html += `<div class="vt-detail-label">Validated At:</div><div class="vt-detail-value">${data.validated_at}</div>`;
                if (data.additional_notes && data.additional_notes !== 'N/A') { html += `<div class="vt-detail-label">Notes:</div><div class="vt-detail-value">${data.additional_notes}</div>`; }
                html += `<div class="vt-detail-label">Total Amount:</div><div class="vt-detail-amount" style="font-size:18px;font-weight:800;">&#8369;${data.total_amount}</div>`;
            }

            html += '</div>';

            /* ── ITEMS BREAKDOWN TABLE ── */
            if (data.items_breakdown && data.items_breakdown.length > 0) {
                html += `<div style="margin-top:20px;border-top:1px solid #e2e8f0;padding-top:14px;">
                    <div style="font-size:13px;font-weight:800;color:#002F70;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">
                        <i class="fas fa-boxes" style="margin-right:5px;"></i>Purchased Items Breakdown
                    </div>
                    <table style="width:100%;border-collapse:collapse;font-size:12px;">
                        <thead><tr style="background:#f1f5f9;border-bottom:2px solid #cbd5e1;color:#475569;font-size:12px;text-transform:uppercase;letter-spacing:.4px;">
                            <th style="padding:8px 10px;text-align:left;">SKU</th>
                            <th style="padding:8px 10px;text-align:left;">Product / Item</th>
                            <th style="padding:8px 10px;text-align:center;">Qty</th>
                            <th style="padding:8px 10px;text-align:right;">Unit Price</th>
                            <th style="padding:8px 10px;text-align:right;">Subtotal</th>
                        </tr></thead>
                        <tbody>`;
                data.items_breakdown.forEach((item, idx) => {
                    const bg = idx % 2 === 1 ? '#f8fafc' : '#ffffff';
                    html += `<tr style="background:${bg};border-bottom:1px solid #f1f5f9;">
                        <td style="padding:9px 10px;font-family:monospace;font-weight:700;color:#002F70;font-size:12px;">${item.sku}</td>
                        <td style="padding:9px 10px;font-weight:600;color:#1e293b;">${item.product_name}</td>
                        <td style="padding:9px 10px;text-align:center;color:#475569;font-weight:700;">${item.quantity}</td>
                        <td style="padding:9px 10px;text-align:right;color:#64748b;">&#8369;${item.unit_price}</td>
                        <td style="padding:9px 10px;text-align:right;font-weight:700;color:#002F70;">&#8369;${item.subtotal}</td>
                    </tr>`;
                });
                html += `</tbody></table></div>`;
            }

            document.getElementById('viewTransactionContent').innerHTML = html;
        })
        .catch(error => {
            console.error('Error loading transaction details:', error);
            document.getElementById('viewTransactionContent').innerHTML =
                '<div style="text-align:center;padding:40px;color:#f59e0b;"><i class="fas fa-exclamation-triangle" style="font-size:32px;display:block;margin-bottom:12px;"></i>Connection error. Please try again.</div>';
        });
}
function closeViewModal() {
    document.getElementById('viewTransactionModal').classList.remove('active');
}

let _adjRowId  = null;
let _adjSource = 'merchandise_transactions';
let _adjItems  = []; // fetched items

function openAdjustModal(rowId, txnId, customer, entryType, txnDate, staffName, payMethod, payStat, source) {
    _adjRowId  = rowId;
    _adjSource = source || (entryType && entryType.toLowerCase().includes('job') ? 'job_orders' : 'merchandise_transactions');
    _adjItems  = [];

    // Show loading state
    document.getElementById('adjustModal').classList.add('active');
    document.getElementById('adjustModalBody').innerHTML =
        '<div style="text-align:center;padding:40px"><i class="fas fa-spinner fa-spin" style="font-size:28px;color:#d97706"></i><div style="margin-top:10px;color:#64748b">Loading items...</div></div>';

    // Fetch items via existing API (supports both merchandise and job_orders)
    fetch('../backend/api/get_transaction_items.php?id=' + rowId + '&source=' + encodeURIComponent(_adjSource))
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                document.getElementById('adjustModalBody').innerHTML =
                    '<div style="color:#dc2626;padding:20px"><i class="fas fa-exclamation-circle"></i> ' + (data.error || 'Failed to load items') + '</div>';
                return;
            }
            _adjItems = data.items || [];
            const fmtDate = txnDate ? new Date(txnDate).toLocaleString('en-PH') : '—';
            const adjReq = data.adjustment_request;
            const esc = function(str) { return str ? String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;') : ''; };
            let reqNoticeHtml = '';

            if (adjReq) {
                const reqDate = adjReq.requested_at ? new Date(adjReq.requested_at).toLocaleString('en-PH') : '';
                reqNoticeHtml = `
                <div style="margin-bottom:16px;padding:14px;background:#f0f9ff;border:1.5px solid #93c5fd;border-radius:10px;font-size:12.5px;color:#1e3a8a;">
                  <div style="font-weight:800;font-size:13px;margin-bottom:8px;display:flex;align-items:center;justify-content:space-between;color:#1d4ed8;border-bottom:1px solid #bfdbfe;padding-bottom:6px;">
                    <span><i class="fas fa-edit" style="margin-right:6px;"></i> STAFF ADJUSTMENT REQUEST DETAILS</span>
                    <span style="background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;">Status: ${esc(adjReq.status || 'Pending')}</span>
                  </div>
                  <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 16px;">
                    ${adjReq.requested_by_name ? `<div><span style="color:#64748b;font-size:11px;font-weight:700;display:block">Requested By Staff</span><strong>${esc(adjReq.requested_by_name)}</strong></div>` : ''}
                    ${reqDate ? `<div><span style="color:#64748b;font-size:11px;font-weight:700;display:block">Request Date & Time</span><span>${reqDate}</span></div>` : ''}
                    ${adjReq.correction_field ? `<div><span style="color:#64748b;font-size:11px;font-weight:700;display:block">Field To Correct</span><strong style="color:#002F70">${esc(adjReq.correction_field)}</strong></div>` : ''}
                    ${adjReq.current_value ? `<div><span style="color:#64748b;font-size:11px;font-weight:700;display:block">Current Value</span><span style="color:#ef4444;font-weight:600">${esc(adjReq.current_value)}</span></div>` : ''}
                    ${adjReq.requested_value ? `<div style="grid-column:span 2;"><span style="color:#64748b;font-size:11px;font-weight:700;display:block">Requested Correct Value</span><strong style="color:#16a34a;font-size:13px;background:#dcfce7;padding:2px 8px;border-radius:4px;display:inline-block;margin-top:2px;">${esc(adjReq.requested_value)}</strong></div>` : ''}
                    ${adjReq.request_reason ? `<div style="grid-column:span 2;"><span style="color:#64748b;font-size:11px;font-weight:700;display:block">Staff Reason for Adjustment</span><strong style="color:#1e293b">${esc(adjReq.request_reason)}</strong></div>` : ''}
                    ${adjReq.remarks ? `<div style="grid-column:span 2;"><span style="color:#64748b;font-size:11px;font-weight:700;display:block">Staff Remarks</span><span style="color:#334155">${esc(adjReq.remarks)}</span></div>` : ''}
                  </div>
                </div>`;
            }

            // ── Auto-reflect staff's requested price / quantity ───────────────
            let targetAdjustIdx = -1;
            let targetNewPrice  = null;
            let targetNewQty    = null;

            if (adjReq && adjReq.requested_value) {
                const reqValClean = String(adjReq.requested_value).trim();
                const parsedNum   = parseFloat(reqValClean.replace(/[^0-9.]/g, ''));

                if (!isNaN(parsedNum)) {
                    const fieldLower  = String(adjReq.correction_field || '').toLowerCase();
                    const reasonLower = String(adjReq.request_reason || '').toLowerCase();

                    if (fieldLower.includes('quantity') || fieldLower.includes('qty')) {
                        targetNewQty = parseInt(parsedNum, 10) || 1;
                        targetAdjustIdx = 0;
                    } else if (fieldLower.includes('labor')) {
                        _adjItems.forEach((item, idx) => {
                            const nameLower = (item.product_name || '').toLowerCase();
                            if (nameLower.includes('labor') || nameLower.includes('service')) {
                                targetAdjustIdx = idx;
                            }
                        });
                        if (targetAdjustIdx === -1 && _adjItems.length > 0) {
                            targetAdjustIdx = _adjItems.length - 1;
                        }
                        targetNewPrice = parsedNum;
                    } else if (fieldLower.includes('service')) {
                        _adjItems.forEach((item, idx) => {
                            if (item.item_type === 'service') {
                                targetAdjustIdx = idx;
                            }
                        });
                        if (targetAdjustIdx === -1 && _adjItems.length > 0) targetAdjustIdx = 0;
                        targetNewPrice = parsedNum;
                    } else {
                        let found = false;
                        const curValNum = parseFloat(String(adjReq.current_value || '').replace(/[^0-9.]/g, ''));
                        _adjItems.forEach((item, idx) => {
                            if (!found && !isNaN(curValNum) && (parseFloat(item.unit_price) === curValNum || parseFloat(item.subtotal) === curValNum)) {
                                targetAdjustIdx = idx;
                                targetNewPrice = parsedNum;
                                found = true;
                            }
                        });
                        if (!found && _adjItems.length > 0) {
                            targetAdjustIdx = 0;
                            targetNewPrice = parsedNum;
                        }
                    }
                }
            }

            const initialReason = (adjReq && adjReq.request_reason) ? adjReq.request_reason : 'Price / Quantity Correction';

            const activePayMethod = (function(pm) {
                if (!pm) return 'Cash';
                pm = String(pm).trim();
                if (pm === 'Credit' || pm === 'Credit Account' || pm === 'Account Receivable') return 'Credit Account';
                if (pm === 'Fleet Card' || pm === 'Petron Fleet Card') return 'Petron Fleet Card';
                if (pm === 'Card' || pm === 'Credit Card') return 'Credit Card';
                if (pm === 'Debit Card') return 'Debit Card';
                return pm;
            })(payMethod);

            const validPayMethods = ['Cash', 'Credit Card', 'Debit Card', 'GCash', 'Maya', 'Petron Fleet Card', 'Credit Account'];

            let html = `
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px 20px;margin-bottom:16px;padding:14px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;font-size:13px;">
              <div><span style="color:#64748b;font-size:11px;font-weight:700;display:block">Transaction ID</span><strong style="font-family:monospace">${txnId}</strong></div>
              <div><span style="color:#64748b;font-size:11px;font-weight:700;display:block">Customer</span>${customer}</div>
              <div><span style="color:#64748b;font-size:11px;font-weight:700;display:block">Type</span>${entryType}</div>
              <div><span style="color:#64748b;font-size:11px;font-weight:700;display:block">Date</span>${fmtDate}</div>
              <div><span style="color:#64748b;font-size:11px;font-weight:700;display:block">Staff Encoder</span>${staffName}</div>
            </div>
            ${reqNoticeHtml}
            <div style="margin-bottom:14px;">
              <div style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px">
                <i class="fas fa-edit"></i> Edit Items
              </div>
              <table style="width:100%;border-collapse:collapse;font-size:12px;">
                <thead><tr style="background:#f8fafc;">
                  <th style="padding:8px 10px;text-align:left;border-bottom:2px solid #e2e8f0;color:#64748b;font-size:11px;font-weight:700;text-transform:uppercase">Product / Service</th>
                  <th style="padding:8px 10px;text-align:left;border-bottom:2px solid #e2e8f0;color:#64748b;font-size:11px;font-weight:700;text-transform:uppercase">Type</th>
                  <th style="padding:8px 10px;text-align:center;border-bottom:2px solid #e2e8f0;color:#64748b;font-size:11px;font-weight:700;text-transform:uppercase">Qty</th>
                  <th style="padding:8px 10px;text-align:right;border-bottom:2px solid #e2e8f0;color:#64748b;font-size:11px;font-weight:700;text-transform:uppercase">Unit Price</th>
                  <th style="padding:8px 10px;text-align:right;border-bottom:2px solid #e2e8f0;color:#64748b;font-size:11px;font-weight:700;text-transform:uppercase">Subtotal</th>
                </tr></thead>
                <tbody id="adjItemsBody">`;

            if (_adjItems.length === 0) {
                html += `<tr><td colspan="5" style="padding:20px;text-align:center;color:#94a3b8;">No items found</td></tr>`;
            } else {
                _adjItems.forEach((item, idx) => {
                    const isSvc = item.item_type === 'service';
                    const isLabor = (item.product_name || '').toLowerCase().includes('labor');
                    let itemQty = parseInt(item.quantity) || 1;
                    let itemPrice = parseFloat(item.unit_price) || 0;

                    if (idx === targetAdjustIdx) {
                        if (targetNewQty !== null) itemQty = targetNewQty;
                        if (targetNewPrice !== null) itemPrice = targetNewPrice;
                    }

                    const itemSub = itemQty * itemPrice;

                    html += `
                    <tr style="border-bottom:1px solid #f1f5f9;">
                      <td style="padding:8px 10px;font-weight:600">${item.product_name}</td>
                      <td style="padding:8px 10px;">
                        <span style="background:${isSvc?'#fffbeb':'#f0fdf4'};color:${isSvc?'#b45309':'#15803d'};padding:2px 8px;border-radius:4px;font-size:10px;font-weight:700">
                          ${isSvc ? 'Service' : 'Merchandise'}
                        </span>
                      </td>
                      <td style="padding:8px 10px;text-align:center;">
                        ${isLabor ? `
                          <span style="color:#64748b;font-weight:700;">—</span>
                          <input type="hidden" id="adj_qty_${idx}" value="1">
                        ` : `
                          <input type="number" min="1" step="1"
                            id="adj_qty_${idx}" value="${itemQty}"
                            oninput="recalcAdjRow(${idx})" onchange="recalcAdjRow(${idx})"
                            style="width:70px;padding:4px 6px;border:1px solid #cbd5e1;border-radius:5px;text-align:center;font-size:12px;${idx === targetAdjustIdx && targetNewQty !== null ? 'background:#dcfce7;border-color:#86efac;font-weight:700;' : ''}">
                        `}
                      </td>
                      <td style="padding:8px 10px;text-align:right;">
                        <input type="number" min="0" step="0.01"
                          id="adj_price_${idx}" value="${itemPrice}"
                          onchange="recalcAdjRow(${idx})"
                          style="width:90px;padding:4px 6px;border:1px solid #cbd5e1;border-radius:5px;text-align:right;font-size:12px;${idx === targetAdjustIdx && targetNewPrice !== null ? 'background:#dcfce7;border-color:#86efac;font-weight:700;' : ''}">
                      </td>
                      <td style="padding:8px 10px;text-align:right;font-weight:700;color:#002F70" id="adj_sub_${idx}">
                        ₱${itemSub.toFixed(2)}
                      </td>
                    </tr>`;
                });
            }
            html += `</tbody>
                <tfoot>
                  <tr style="border-top:2px solid #e2e8f0;background:#f8fafc;">
                    <td colspan="4" style="padding:8px 10px;text-align:right;font-weight:700;font-size:13px;">New Total:</td>
                    <td style="padding:8px 10px;text-align:right;font-weight:800;font-size:14px;color:#002F70" id="adjNewTotal">₱0.00</td>
                  </tr>
                </tfoot>
              </table>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
              <div>
                <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px">Payment Method</label>
                <select id="adjPayMethod" style="width:100%;height:36px;padding:0 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;background:#fff">
                  ${validPayMethods.map(m =>
                    `<option value="${m}" ${m===activePayMethod?'selected':''}>${m}</option>`).join('')}
                </select>
              </div>
              <div>
                <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px">Payment Status</label>
                <select id="adjPayStatus" style="width:100%;height:36px;padding:0 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;background:#fff">
                  ${['Paid','Unpaid','Partial Payment','Credit Account'].map(s =>
                    `<option value="${s}" ${s===payStat?'selected':''}>${s}</option>`).join('')}
                </select>
              </div>
            </div>
            <div style="margin-bottom:12px;">
              <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px">Adjustment Reason <span style="color:#dc2626">*</span></label>
              <textarea id="adjReason" rows="2" placeholder="Why is this adjustment being made?" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;resize:vertical;box-sizing:border-box;">${esc(initialReason)}</textarea>
            </div>
            <div>
              <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px">Manager Remarks <span style="color:#dc2626">*</span></label>
              <textarea id="adjManagerRemarks" rows="2" placeholder="Manager's notes on this adjustment..." style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;resize:vertical;box-sizing:border-box;">Adjusted and approved by Manager</textarea>
            </div>`;

            document.getElementById('adjustModalBody').innerHTML = html;

            // Update button to Approve Adjustment when staff requested adjustment
            const saveBtn = document.getElementById('saveAdjustBtn');
            if (saveBtn) {
                if (adjReq) {
                    saveBtn.style.background = '#16a34a';
                    saveBtn.innerHTML = '<i class="fas fa-check-circle"></i> Approve Adjustment';
                } else {
                    saveBtn.style.background = '#d97706';
                    saveBtn.innerHTML = '<i class="fas fa-save"></i> Save Adjustment';
                }
            }

            recalcAdjTotal();
        })
        .catch(() => {
            document.getElementById('adjustModalBody').innerHTML =
                '<div style="color:#dc2626;padding:20px"><i class="fas fa-exclamation-circle"></i> Connection error. Please try again.</div>';
        });
}

function recalcAdjRow(idx) {
    const qty   = parseFloat(document.getElementById('adj_qty_'   + idx)?.value || 0);
    const price = parseFloat(document.getElementById('adj_price_' + idx)?.value || 0);
    const sub   = qty * price;
    const subEl = document.getElementById('adj_sub_' + idx);
    if (subEl) subEl.textContent = '₱' + sub.toFixed(2);
    recalcAdjTotal();
}

function recalcAdjTotal() {
    let total = 0;
    (_adjItems || []).forEach((_, idx) => {
        const qty   = parseFloat(document.getElementById('adj_qty_'   + idx)?.value || 0);
        const price = parseFloat(document.getElementById('adj_price_' + idx)?.value || 0);
        total += qty * price;
    });
    const el = document.getElementById('adjNewTotal');
    if (el) el.textContent = '₱' + total.toFixed(2);
}

function closeAdjustModal() {
    document.getElementById('adjustModal').classList.remove('active');
    _adjRowId = null;
    _adjItems = [];
}

function submitAdjustment() {
    const reason  = document.getElementById('adjReason')?.value.trim() || 'Price / Quantity Correction';
    const remarks = document.getElementById('adjManagerRemarks')?.value.trim() || 'Adjusted and confirmed by Manager';
    if (!reason)  { showToast('Please enter the Adjustment Reason.', 'error'); document.getElementById('adjReason')?.focus(); return; }
    if (!remarks) { showToast('Please enter Manager Remarks.', 'error'); document.getElementById('adjManagerRemarks')?.focus(); return; }

    const btn = document.getElementById('saveAdjustBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

    const itemsPayload = (_adjItems || []).map((item, idx) => ({
        item_id   : item.id,
        quantity  : parseInt(document.getElementById('adj_qty_'   + idx)?.value || item.quantity, 10),
        unit_price: parseFloat(document.getElementById('adj_price_' + idx)?.value || item.unit_price),
    }));

    fetch('../backend/api/save_adjustment.php', {
        method : 'POST',
        headers: { 'Content-Type': 'application/json' },
        body   : JSON.stringify({
            row_id            : _adjRowId,
            payment_method    : document.getElementById('adjPayMethod')?.value  || '',
            payment_status    : document.getElementById('adjPayStatus')?.value  || '',
            adjustment_reason : reason,
            manager_remarks   : remarks,
            items             : itemsPayload,
        }),
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Adjustment';
        if (data.success) {
            closeAdjustModal();
            const oldTot = parseFloat(data.old_total || 0).toFixed(2);
            const newTot = parseFloat(data.new_total || 0).toFixed(2);
            showToast('<i class="fas fa-check"></i> Transaction adjusted successfully! ₱' + oldTot + ' → ₱' + newTot, 'success');
            setTimeout(() => location.reload(), 1400);
        } else {
            showToast('<i class="fas fa-times-circle"></i> ' + (data.error || 'Adjustment failed. Please try again.'), 'error');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Adjustment';
        showToast('<i class="fas fa-times-circle"></i> Connection error. Please try again.', 'error');
    });
}

/* ── REVIEW REQUEST & VOID MODAL HANDLERS ── */
let _currentReviewReqId   = null;
let _currentReviewReqType = null;
let _currentReviewRowId   = null;
let _currentReviewSource  = null;

function openReviewRequestModal(reqId, reqType, rowId, txnIdStr, customer, entryType, txnDate, staffName, reason, newAmount, source, payMethod, payStat) {
    _currentReviewReqId   = reqId;
    _currentReviewReqType = reqType;
    _currentReviewRowId   = rowId;
    _currentReviewSource  = source || 'merchandise_transactions';

    if (reqType === 'Void') {
        openVoidModal(rowId, txnIdStr, customer, source);
        return;
    } else if (reqType === 'Adjustment') {
        openAdjustModal(rowId, txnIdStr, customer, entryType, txnDate, staffName, payMethod, payStat, source);
        return;
    }

    const modal = document.getElementById('reviewRequestModal');
    if (!modal) {
        openVoidModal(rowId, txnIdStr, customer, source);
        return;
    }
    
    document.getElementById('reviewReqTxnId').textContent     = txnIdStr || '—';
    document.getElementById('reviewReqCustomer').textContent  = customer || '—';
    document.getElementById('reviewReqType').textContent      = entryType || '—';
    document.getElementById('reviewReqDate').textContent      = txnDate || '—';
    document.getElementById('reviewReqStaff').textContent     = staffName || '—';
    document.getElementById('reviewReqReasonText').textContent = reason || 'No reason provided.';
    
    const newAmtRow = document.getElementById('reviewReqNewAmountRow');
    if (newAmtRow) {
        if (newAmount && newAmount > 0) {
            document.getElementById('reviewReqNewAmountVal').textContent = '₱' + parseFloat(newAmount).toFixed(2);
            newAmtRow.style.display = 'block';
        } else {
            newAmtRow.style.display = 'none';
        }
    }
    
    modal.classList.add('active');
    modal.style.display = 'flex';
}

function closeReviewRequestModal() {
    const modal = document.getElementById('reviewRequestModal');
    if (modal) {
        modal.classList.remove('active');
        modal.style.display = 'none';
    }
}

/* ─────────────────────────────────────────────── VOID MODAL ────────────── */
let _voidRowId = null;
let _voidSource = null;

function openVoidModal(rowId, txnId, customer, source) {
    _voidRowId = rowId;
    _voidSource = source || 'merchandise_transactions';
    
    // Reset inputs
    document.getElementById('voidReasonSelect').value = '';
    document.getElementById('voidReasonOther').value = '';
    document.getElementById('voidReasonOtherContainer').style.display = 'none';
    document.getElementById('voidManagerRemarksNew').value = '';
    document.getElementById('voidAuthPassword').value = '';
    if (document.getElementById('voidAuthPin')) document.getElementById('voidAuthPin').value = '';
    
    document.querySelectorAll('.void-checklist').forEach(cb => cb.checked = false);
    
    const confirmBtn = document.getElementById('confirmVoidBtnNew');
    if (confirmBtn) {
        confirmBtn.disabled = true;
        confirmBtn.style.opacity = '0.6';
        confirmBtn.style.cursor = 'not-allowed';
    }
    
    // Set view modes
    document.getElementById('voidModalMainContent').style.display = 'flex';
    document.getElementById('voidModalSuccessContent').style.display = 'none';
    
    // Set default textual info
    document.getElementById('voidInfoTxnId').innerText = txnId;
    document.getElementById('voidInfoTxnType').innerText = _voidSource === 'job_orders' ? 'Job Order' : 'Merchandise';
    document.getElementById('voidInfoCustomer').innerText = customer || 'Walk-in';
    document.getElementById('voidInfoStaff').innerText = 'Loading...';
    document.getElementById('voidInfoShift').innerText = 'Loading...';
    document.getElementById('voidInfoDateTime').innerText = 'Loading...';
    document.getElementById('voidInfoPayMethod').innerText = 'Loading...';
    document.getElementById('voidInfoPayStatus').innerText = 'Loading...';
    document.getElementById('voidInfoStatus').innerText = 'Loading...';
    document.getElementById('voidInfoTotalAmount').innerText = 'Loading...';
    
    document.getElementById('voidItemsContainer').innerHTML = '<div style="text-align:center;padding:20px;color:#64748b;"><i class="fas fa-spinner fa-spin"></i> Loading items...</div>';
    const elInv = document.getElementById('voidInventoryImpactList'); if (elInv) elInv.innerHTML = '<div style="color:#64748b;">Loading...</div>';
    const elCur = document.getElementById('voidSalesCurrent'); if (elCur) elCur.innerText = '₱0.00';
    const elAft = document.getElementById('voidSalesAfter'); if (elAft) elAft.innerText = '₱0.00';
    const elDif = document.getElementById('voidSalesDiff'); if (elDif) elDif.innerText = '-₱0.00';
    
    document.getElementById('voidModal').classList.add('active');
    
    // Fetch data using Promise.all
    Promise.all([
        fetch('../backend/get_transaction_details.php?type=' + _voidSource + '&id=' + rowId).then(r => r.json()),
        fetch('get_transaction_items.php?id=' + rowId + '&source=' + _voidSource).then(r => r.json())
    ])
    .then(([detailsRes, itemsRes]) => {
        if (!detailsRes.success || !itemsRes.items) {
            showToast('Error loading transaction details.', 'error');
            return;
        }
        
        const details = detailsRes;
        const items = itemsRes.items || [];
        
        // Populate header fields
        document.getElementById('voidInfoTxnType').innerText = _voidSource === 'job_orders' ? 'Job Order' : (details.type === 'combined' ? 'Combined' : 'Merchandise');
        document.getElementById('voidInfoCustomer').innerText = details.customer_name || 'Walk-in';
        document.getElementById('voidInfoStaff').innerText = details.staff_name || 'Staff';
        document.getElementById('voidInfoShift').innerText = details.shift || 'N/A';
        document.getElementById('voidInfoDateTime').innerText = details.transaction_date || 'N/A';
        document.getElementById('voidInfoPayMethod').innerText = details.payment_method || 'N/A';
        document.getElementById('voidInfoPayStatus').innerText = details.payment_status || details.validation_status || 'N/A';
        document.getElementById('voidInfoStatus').innerText = details.validation_status || details.job_status || 'Completed';
        
        const grandTotal = parseFloat(itemsRes.total_amount || details.total_amount || 0);
        document.getElementById('voidInfoTotalAmount').innerText = '₱' + grandTotal.toFixed(2);
        
        // Render items breakdown inside voidItemsContainer
        let itemsHtml = '';
        const services = items.filter(i => i.item_type === 'service');
        const merchandise = items.filter(i => i.item_type !== 'service');
        
        if (merchandise.length > 0) {
            itemsHtml += `<div style="font-weight:700;color:#15803d;margin-bottom:4px;">Merchandise</div>
            <table style="width:100%;border-collapse:collapse;margin-bottom:8px;">
                <thead>
                    <tr style="background:#f1f5f9;text-align:left;font-size:10px;">
                        <th style="padding:4px;border-bottom:1px solid #cbd5e1;">Product</th>
                        <th style="padding:4px;border-bottom:1px solid #cbd5e1;text-align:center;">Qty</th>
                        <th style="padding:4px;border-bottom:1px solid #cbd5e1;text-align:right;">Unit Price</th>
                        <th style="padding:4px;border-bottom:1px solid #cbd5e1;text-align:right;">Total</th>
                    </tr>
                </thead>
                <tbody>`;
            merchandise.forEach(item => {
                itemsHtml += `
                    <tr>
                        <td style="padding:4px;border-bottom:1px solid #f1f5f9;font-weight:600;">${item.product_name}</td>
                        <td style="padding:4px;border-bottom:1px solid #f1f5f9;text-align:center;">${parseInt(item.quantity)}</td>
                        <td style="padding:4px;border-bottom:1px solid #f1f5f9;text-align:right;">₱${parseFloat(item.unit_price).toFixed(2)}</td>
                        <td style="padding:4px;border-bottom:1px solid #f1f5f9;text-align:right;font-weight:700;color:#002F70;">₱${parseFloat(item.subtotal).toFixed(2)}</td>
                    </tr>`;
            });
            
            // Subtotal, Discount, VAT, Grand Total
            const subtotal = details.subtotal_amount && details.subtotal_amount !== 'N/A' ? parseFloat(details.subtotal_amount) : grandTotal / 1.12;
            const vat = details.vat_amount && details.vat_amount !== 'N/A' ? parseFloat(details.vat_amount) : grandTotal - subtotal;
            
            itemsHtml += `
                </tbody>
            </table>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:2px;font-size:10px;color:#475569;margin-bottom:8px;padding-right:4px;">
                <div>Subtotal: <strong>₱${subtotal.toFixed(2)}</strong></div>
                <div>Discount: <strong>₱0.00</strong></div>
                <div>VAT (12%): <strong>₱${vat.toFixed(2)}</strong></div>
                <div style="font-size:11px;color:#002F70;margin-top:2px;">Grand Total: <strong>₱${grandTotal.toFixed(2)}</strong></div>
            </div>`;
        }
        
        if (services.length > 0) {
            itemsHtml += `<div style="font-weight:700;color:#b45309;margin-top:8px;margin-bottom:4px;">Job Order</div>
            <table style="width:100%;border-collapse:collapse;margin-bottom:8px;">
                <thead>
                    <tr style="background:#fffbeb;text-align:left;font-size:10px;">
                        <th style="padding:4px;border-bottom:1px solid #fde68a;">Service</th>
                        <th style="padding:4px;border-bottom:1px solid #fde68a;text-align:right;">Amount</th>
                    </tr>
                </thead>
                <tbody>`;
            services.forEach(item => {
                itemsHtml += `
                    <tr>
                        <td style="padding:4px;border-bottom:1px solid #fef3c7;font-weight:600;">${item.product_name}</td>
                        <td style="padding:4px;border-bottom:1px solid #fef3c7;text-align:right;font-weight:700;color:#002F70;">₱${parseFloat(item.subtotal).toFixed(2)}</td>
                    </tr>`;
            });
            itemsHtml += `
                </tbody>
            </table>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:2px;font-size:10px;color:#475569;margin-bottom:8px;padding-right:4px;">
                <div style="font-size:11px;color:#002F70;margin-top:2px;">Grand Total: <strong>₱${grandTotal.toFixed(2)}</strong></div>
            </div>`;
        }
        
        document.getElementById('voidItemsContainer').innerHTML = itemsHtml || '<div style="color:#94a3b8;">No items in transaction.</div>';
        
        // Render Inventory Restore Impact Preview list
        let invHtml = '';
        if (merchandise.length > 0) {
            merchandise.forEach(item => {
                invHtml += `<div><i class="fas fa-check" style="color:#16a34a;margin-right:4px;"></i> ${item.product_name} (+${parseInt(item.quantity)})</div>`;
            });
        } else {
            invHtml = '<div style="color:#94a3b8;">No inventory items to restore.</div>';
        }
        if (elInv) elInv.innerHTML = invHtml;
        if (elCur) elCur.innerText = '₱' + grandTotal.toFixed(2);
        // ── Auto-fetch Staff Void Request Reason & Remarks ─────────
        const staffReason = (details.void_reason || details.pending_void_reason || '').trim();
        const staffRemarks = (details.staff_remarks || details.pending_void_remarks || '').trim();
        const staffReqName = details.pending_void_staff_name || details.staff_name || 'Staff';
        const staffReqDate = details.pending_void_date || '';

        const bannerEl = document.getElementById('voidStaffRequestBanner');
        if (staffReason || staffRemarks) {
            if (bannerEl) bannerEl.style.display = 'block';
            const reasonTextEl = document.getElementById('voidStaffReasonText');
            if (reasonTextEl) reasonTextEl.innerText = staffReason || 'Not specified';
            
            const remarksTextEl = document.getElementById('voidStaffRemarksText');
            if (remarksTextEl) remarksTextEl.innerText = staffRemarks || 'None specified';
            
            const reqNameEl = document.getElementById('voidStaffReqName');
            if (reqNameEl) reqNameEl.innerText = staffReqName;
            
            const reqDateEl = document.getElementById('voidStaffReqDate');
            if (reqDateEl) reqDateEl.innerText = staffReqDate;
            
            const remarksRow = document.getElementById('voidStaffRemarksRow');
            if (remarksRow) remarksRow.style.display = staffRemarks ? 'block' : 'none';

            // Auto-select or pre-populate the Void Reason dropdown
            if (staffReason) {
                const reasonSelect = document.getElementById('voidReasonSelect');
                let matched = false;
                if (reasonSelect) {
                    for (let i = 0; i < reasonSelect.options.length; i++) {
                        const optVal = reasonSelect.options[i].value;
                        if (optVal && (optVal.toLowerCase() === staffReason.toLowerCase() || staffReason.toLowerCase().startsWith(optVal.toLowerCase()))) {
                            reasonSelect.selectedIndex = i;
                            matched = true;
                            break;
                        }
                    }
                    if (!matched) {
                        reasonSelect.value = 'Other';
                        const otherContainer = document.getElementById('voidReasonOtherContainer');
                        if (otherContainer) otherContainer.style.display = 'block';
                        const otherInput = document.getElementById('voidReasonOther');
                        if (otherInput) otherInput.value = staffReason;
                    } else {
                        toggleVoidOtherReason();
                    }
                }
            }
        } else {
            if (bannerEl) bannerEl.style.display = 'none';
        }

        validateVoidForm();
    })
    .catch(err => {
        console.error(err);
        document.getElementById('voidItemsContainer').innerHTML = '<div style="color:#dc2626;">Error loading details.</div>';
        if (elInv) elInv.innerHTML = '<div style="color:#dc2626;">Error.</div>';
    });
}

function closeVoidModal() {
    document.getElementById('voidModal').classList.remove('active');
    _voidRowId = null;
    _voidSource = null;
}

function toggleVoidOtherReason() {
    const select = document.getElementById('voidReasonSelect');
    const container = document.getElementById('voidReasonOtherContainer');
    if (select.value === 'Other') {
        container.style.display = 'block';
    } else {
        container.style.display = 'none';
        document.getElementById('voidReasonOther').value = '';
    }
    validateVoidForm();
}

function toggleVoidPasswordVisibility() {
    const pwdInput = document.getElementById('voidAuthPassword');
    const icon = document.getElementById('toggleVoidPasswordIcon');
    if (!pwdInput) return;
    if (pwdInput.type === 'password') {
        pwdInput.type = 'text';
        if (icon) {
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        }
    } else {
        pwdInput.type = 'password';
        if (icon) {
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }
}

function validateVoidForm() {
    const reasonSelect = document.getElementById('voidReasonSelect')?.value || '';
    const reasonOther  = document.getElementById('voidReasonOther')?.value.trim() || '';
    const remarks      = document.getElementById('voidManagerRemarksNew')?.value.trim() || '';
    const password     = document.getElementById('voidAuthPassword')?.value.trim() || '';
    
    let isReasonValid = (reasonSelect !== '');
    if (reasonSelect === 'Other') {
        isReasonValid = (reasonOther !== '');
    }
    
    const isAuthValid = (password !== '');
    const confirmBtn  = document.getElementById('confirmVoidBtnNew');
    
    if (confirmBtn) {
        if (isReasonValid && remarks !== '' && isAuthValid) {
            confirmBtn.disabled = false;
            confirmBtn.style.opacity = '1';
            confirmBtn.style.cursor = 'pointer';
            confirmBtn.style.background = '#dc2626';
        } else {
            confirmBtn.disabled = true;
            confirmBtn.style.opacity = '0.6';
            confirmBtn.style.cursor = 'not-allowed';
            confirmBtn.style.background = '#94a3b8';
        }
    }
}

function previewVoidImpact() {
    const txnId = document.getElementById('voidInfoTxnId').innerText;
    const amt = document.getElementById('voidInfoTotalAmount').innerText;
    
    alert(`Void Impact Preview for ${txnId}:\n\n` +
          `• Inventory: Stock quantities listed in the preview will be returned to store inventory.\n` +
          `• Sales: Sales totals will decrease by ${amt}.\n` +
          `• Reports: Shift summaries, Daily Sales, and Payment reports will exclude this transaction.\n` +
          `• Customer: Purchase history for this customer will be updated (marked VOIDED).\n` +
          `• Audit Log: Void event with manager name, reason, remarks, and timestamp will be logged.`);
}

function submitVoidNew() {
    const reasonSelect = document.getElementById('voidReasonSelect')?.value || '';
    const reasonOther  = document.getElementById('voidReasonOther')?.value.trim() || '';
    const remarks      = document.getElementById('voidManagerRemarksNew')?.value.trim() || '';
    const password     = document.getElementById('voidAuthPassword')?.value.trim() || '';
    
    const finalReason = (reasonSelect === 'Other') ? reasonOther : reasonSelect;
    
    if (!finalReason) {
        showToast('Please select a Void Reason.', 'error');
        document.getElementById('voidReasonSelect')?.focus();
        return;
    }
    if (!remarks) {
        showToast('Please enter Manager Remarks.', 'error');
        document.getElementById('voidManagerRemarksNew')?.focus();
        return;
    }
    if (!password) {
        showToast('Please enter Manager Password to confirm voiding.', 'error');
        document.getElementById('voidAuthPassword')?.focus();
        return;
    }
    
    const confirmBtn = document.getElementById('confirmVoidBtnNew');
    confirmBtn.disabled = true;
    confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing Void...';
    
    fetch('../backend/api/void_transaction_manager.php', {
        method : 'POST',
        headers: { 'Content-Type': 'application/json' },
        body   : JSON.stringify({
            row_id          : _voidRowId,
            source          : _voidSource,
            void_reason     : finalReason,
            manager_remarks : remarks,
            password        : password
        }),
    })
    .then(async res => {
        const rawText = await res.text();
        let data;
        try {
            data = JSON.parse(rawText);
        } catch (err) {
            const match = rawText.match(/\{[\s\S]*\}/);
            if (match) {
                try { data = JSON.parse(match[0]); } catch (e) { throw new Error(rawText.replace(/<[^>]*>/g, '').trim()); }
            } else {
                throw new Error(rawText.replace(/<[^>]*>/g, '').trim());
            }
        }
        return data;
    })
    .then(data => {
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = '<i class="fas fa-ban"></i> Confirm Void';
        
        if (data.success) {
            const txnIdStr = document.getElementById('voidInfoTxnId')?.innerText || _voidRowId;
            closeVoidModal();
            showToast('<i class="fas fa-check"></i> Transaction ' + txnIdStr + ' successfully voided! Inventory restored.', 'success');
            
            setTimeout(() => {
                location.reload();
            }, 1200);
        } else {
            showToast('<i class="fas fa-times-circle"></i> ' + (data.error || 'Failed to void transaction.'), 'error');
        }
    })
    .catch(err => {
        console.error(err);
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = '<i class="fas fa-ban"></i> Confirm Void';
        showToast('<i class="fas fa-times-circle"></i> ' + (err.message || 'Error processing request.'), 'error');
    });
}

function closeVoidModalReload() {
    closeVoidModal();
    location.reload();
}

/* ── TOAST (Right-side Banner) ─────────────────────────────────────────── */
function showToast(a, b) {
    let type = 'success';
    let msg = '';
    if (a === 'success' || a === 'error' || a === 'warning' || a === 'info') {
        type = a;
        msg = b || '';
    } else if (b === 'success' || b === 'error' || b === 'warning' || b === 'info') {
        type = b;
        msg = a || '';
    } else {
        msg = a || b || '';
    }

    if (window.showPetronFlash) {
        window.showPetronFlash(msg, type === 'success' ? 'success' : 'error', 4500);
        return;
    }

    const colors = {
        success: { bg: '#f0fdf4', color: '#166534', border: '#86efac', icon: 'fa-check-circle', iconColor: '#16a34a' },
        error:   { bg: '#fef2f2', color: '#991b1b', border: '#fecaca', icon: 'fa-times-circle',  iconColor: '#dc2626' },
        warning: { bg: '#fffbeb', color: '#92400e', border: '#fde68a', icon: 'fa-exclamation-triangle', iconColor: '#d97706' },
        info:    { bg: '#eff6ff', color: '#1e40af', border: '#bfdbfe', icon: 'fa-info-circle',   iconColor: '#2563eb' },
    };
    const c = colors[type] || colors.success;

    const old = document.getElementById('mgr_right_toast');
    if (old) old.remove();

    const toast = document.createElement('div');
    toast.id = 'mgr_right_toast';
    toast.style.cssText = `position:fixed;top:84px;right:22px;left:auto;z-index:999999;` +
        `background:${c.bg};color:${c.color};border:1.5px solid ${c.border};` +
        `padding:12px 18px;border-radius:10px;font-weight:700;` +
        `box-shadow:0 12px 30px rgba(0,0,0,.15);transition:opacity .35s ease, transform .25s ease;` +
        `font-size:13.5px;display:flex;align-items:center;gap:10px;max-width:440px;width:auto;opacity:0;transform:translateY(-10px);`;
    toast.innerHTML = `<i class="fas ${c.icon}" style="color:${c.iconColor};font-size:16px;flex-shrink:0;"></i><span style="flex:1;">${msg}</span>` +
        `<button onclick="this.parentElement.remove()" style="margin-left:8px;background:none;border:none;cursor:pointer;color:${c.color};font-size:18px;line-height:1;padding:0 2px;opacity:0.8;">×</button>`;
    document.body.appendChild(toast);
    requestAnimationFrame(() => {
        toast.style.opacity = '1';
        toast.style.transform = 'translateY(0)';
    });
    setTimeout(() => {
        if (toast.parentElement) {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(-10px)';
            setTimeout(() => toast.remove(), 350);
        }
    }, 4500);
}

function exportTable(format) {
    const params = new URLSearchParams(window.location.search);
    params.set('export', format);
    window.location.href = '?' + params.toString();
    return;
    const table = document.querySelector('.vt-table');
    if (!table) { alert('No transaction data found.'); return; }

    const dateFrom = document.querySelector('input[name="date_from"]')?.value || '';
    const dateTo   = document.querySelector('input[name="date_to"]')?.value || '';
    const filename = `Validated_Transactions_${dateFrom || 'All'}_to_${dateTo || 'All'}`;

    if (format === 'excel') {
        if (typeof XLSX === 'undefined') {
            alert('Export library not loaded. Please try again.');
            return;
        }
        const aoa = [];
        // Headers
        table.querySelectorAll('thead tr').forEach(tr => {
            const cells = [...tr.querySelectorAll('th')];
            cells.pop(); // Remove "Actions"
            aoa.push(cells.map(th => th.innerText.trim()));
        });
        // Body
        table.querySelectorAll('tbody tr').forEach(tr => {
            const cells = [...tr.querySelectorAll('td')];
            if (cells.length > 1) { // Skip "No records" row if it spans
                cells.pop(); // Remove "Actions"
                aoa.push(cells.map(td => td.innerText.trim()));
            } else {
                aoa.push(cells.map(td => td.innerText.trim()));
            }
        });
        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(aoa);
        if (aoa.length && aoa[0]) {
            ws['!cols'] = aoa[0].map((_, ci) => ({
                wch: Math.min(45, Math.max(10, ...aoa.map(row => String(row[ci] ?? '').length)))
            }));
        }
        XLSX.utils.book_append_sheet(wb, ws, 'Validated Transactions');
        XLSX.writeFile(wb, filename + '.xlsx');
    } else if (format === 'csv') {
        let csv = '';
        // Headers
        table.querySelectorAll('thead tr').forEach(tr => {
            const cells = [...tr.querySelectorAll('th')];
            cells.pop();
            csv += cells.map(th => '"' + th.innerText.trim().replace(/"/g, '""') + '"').join(',') + '\n';
        });
        // Body
        table.querySelectorAll('tbody tr').forEach(tr => {
            const cells = [...tr.querySelectorAll('td')];
            if (cells.length > 1) {
                cells.pop();
                csv += cells.map(td => '"' + td.innerText.trim().replace(/"/g, '""') + '"').join(',') + '\n';
            } else {
                csv += cells.map(td => '"' + td.innerText.trim().replace(/"/g, '""') + '"').join(',') + '\n';
            }
        });
        const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href     = url;
        a.download = filename + '.csv';
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { document.body.removeChild(a); URL.revokeObjectURL(url); }, 200);
    } else if (format === 'pdf') {
        const logo_url  = '../assets/img/Petron%20Logo.png';
        const generated = new Date().toLocaleString();
        
        // Let's clone the table and remove the last column from the print HTML
        const tableClone = table.cloneNode(true);
        tableClone.querySelectorAll('tr').forEach(tr => {
            const lastCell = tr.lastElementChild;
            if (lastCell) lastCell.remove();
        });
        
        let tableHtml = tableClone.outerHTML;
        
        let iframe = document.getElementById('print-iframe');
        if (!iframe) {
            iframe = document.createElement('iframe');
            iframe.id = 'print-iframe';
            iframe.style.position = 'fixed';
            iframe.style.right = '0';
            iframe.style.bottom = '0';
            iframe.style.width = '0';
            iframe.style.height = '0';
            iframe.style.border = '0';
            document.body.appendChild(iframe);
        }

        const doc = iframe.contentDocument || iframe.contentWindow.document;
        doc.open();
        doc.write(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>Validated Transactions Report</title>
        <style>
            @page{size: A4 landscape;margin:.3in .4in;}
            *{-webkit-print-color-adjust:exact !important;print-color-adjust:exact !important;box-sizing:border-box;}
            body{font-family:Arial,sans-serif;font-size:11px;color:#000;background:white;margin:0;padding:20px;}
            .header-container{display:flex;align-items:center;gap:15px;border-bottom:2px solid #002F70;padding-bottom:12px;margin-bottom:15px;}
            .header-container img{height:45px;}
            .header-title h1{font-size:16px;margin:0;color:#002F70;text-transform:uppercase;}
            .header-title p{font-size:10px;margin:3px 0 0;color:#666;}
            .meta-info{margin-left:auto;text-align:right;font-size:10px;color:#444;}
            table{width:100%;border-collapse:collapse;font-size:9.5px;}
            thead tr{background:#f2f2f2 !important;border-top:2px solid #002F70;border-bottom:1px solid #999;}
            thead th{padding:6px 5px;text-align:left;font-weight:700;font-size:9px;text-transform:uppercase;color:#000;}
            tbody tr{border-bottom:1px solid #ddd;}
            tbody td{padding:5px;color:#333;}
            .vt-badge, .badge, .status-badge{border:none;background:none;padding:0;font-weight:normal;}
            tfoot tr{border-top:2px solid #002F70;background:#f2f2f2 !important;}
            tfoot td{padding:6px 5px;font-weight:700;}
        </style></head><body>
            <div class="header-container">
                <img src="${logo_url}" alt="Petron">
                <div class="header-title">
                    <h1>Petron Station Management System</h1>
                    <p>Validated Transactions Report</p>
                </div>
                <div class="meta-info">
                    Date Range: ${dateFrom || 'All'} to ${dateTo || 'All'}<br>
                    Generated: ${generated}
                </div>
            </div>
            ${tableHtml}
        </body></html>`);
        doc.close();

        setTimeout(() => {
            iframe.contentWindow.focus();
            iframe.contentWindow.print();
        }, 250);
    }
}
</script>
<script src="../assets/vendor/xlsx/xlsx.full.min.js"></script>

</div><!-- end stock-page -->

<?php include __DIR__ . '/../partials/footer.php'; ?>
<script>
function rcToggleExpand(id) {
  var row  = document.getElementById(id);
  var icon = document.getElementById(id + '_icon');
  if (!row) return;
  var open = row.style.display !== 'none';
  row.style.display = open ? 'none' : '';
  if (icon) icon.style.transform = open ? '' : 'rotate(180deg)';
}
</script>