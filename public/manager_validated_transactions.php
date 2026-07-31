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
    'â€”'
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
        if ($raw === '' || $raw === 'â€”') return 'â€”';
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
        if (empty($formatted)) return 'â€”';
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
// Apply validation status filter (Completed / Voided / Adjusted)
if ($status_filter !== '') {
    $all_rows = array_filter($all_rows, function($r) use ($status_filter) {
        $vs = strtolower(trim($r['validation_status'] ?? ''));
        if ($status_filter === 'Voided')   return $vs === 'voided';
        if ($status_filter === 'Adjusted') return $vs === 'adjusted';
        return !in_array($vs, ['voided', 'adjusted'], true);
    });
}
$rows = array_values($all_rows);
usort($rows, fn($a, $b) => strtotime($b['txn_date']) - strtotime($a['txn_date']));

// Summary card calculations (all from filtered $rows)
$total_amount = 0.0;
$merch_count  = 0;
$jo_count     = 0;
$paid_count   = 0;
$unpaid_count = 0;
foreach ($rows as $r) {
    $total_amount += (float)($r['amount'] ?? 0);
    if (strtolower($r['entry_type']) === 'merchandise') { $merch_count++; } else { $jo_count++; }
    $ps = vt_pay_status($r);
    if ($ps === 'Paid') { $paid_count++; } else { $unpaid_count++; }
}

// Fetch staff list for Staff Encoder dropdown
$staff_list = [];
try {
    $stmt = $pdo->prepare("SELECT id, COALESCE(NULLIF(CONCAT(first_name,' ',last_name),' '), username) AS name FROM users WHERE station_id = ? AND role != 'admin' ORDER BY name");
    $stmt->execute([$station_id]);
    $staff_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $staff_list = []; }

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
            $r['vehicle_plate'] ?? 'â€”',
            number_format((float)$r['amount'], 2),
            $r['payment_method'],
            vt_pay_status($r),
            $r['shift'] ?? 'N/A',
            $r['staff_name'],
            date('M d, Y H:i', strtotime($r['txn_date'])),
            $r['validated_by'],
            $r['validation_remarks'] ?? 'â€”'
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
        echo '<td>' . htmlspecialchars($r['vehicle_plate'] ?? 'â€”') . '</td>';
        echo '<td style="text-align:right">&#8369;' . number_format((float)$r['amount'], 2) . '</td>';
        echo '<td>' . htmlspecialchars($r['payment_method']) . '</td>';
        echo '<td>' . htmlspecialchars($pay_st) . '</td>';
        echo '<td>' . htmlspecialchars($r['shift'] ?? 'N/A') . '</td>';
        echo '<td>' . htmlspecialchars($r['staff_name']) . '</td>';
        echo '<td>' . date('M d, Y H:i', strtotime($r['txn_date'])) . '</td>';
        echo '<td>' . htmlspecialchars($r['validated_by']) . '</td>';
        echo '<td>' . htmlspecialchars($r['validation_remarks'] ?? 'â€”') . '</td>';
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
        echo '<td>' . htmlspecialchars($r['vehicle_plate'] ?? 'â€”') . '</td>';
        echo '<td class="amount">&#8369;' . number_format((float)$r['amount'], 2) . '</td>';
        echo '<td>' . htmlspecialchars($r['payment_method']) . '</td>';
        echo '<td>' . htmlspecialchars($pay_st) . '</td>';
        echo '<td>' . htmlspecialchars($r['shift'] ?? 'N/A') . '</td>';
        echo '<td>' . htmlspecialchars($r['staff_name']) . '</td>';
        echo '<td>' . date('M d, Y H:i', strtotime($r['txn_date'])) . '</td>';
        echo '<td>' . htmlspecialchars($r['validated_by']) . '</td>';
        echo '<td>' . htmlspecialchars($r['validation_remarks'] ?? 'â€”') . '</td>';
        echo '</tr>';
        fputcsv($out, [
            $r['txn_id'],
            $r['customer'],
            $r['entry_type'],
            $items_desc,
            $r['vehicle_plate'] ?? 'â€”',
            number_format((float)$r['amount'], 2),
            $r['payment_method'],
            vt_pay_status($r),
            $r['shift'] ?? 'N/A',
            $r['staff_name'],
            date('M d, Y H:i', strtotime($r['txn_date'])),
            $r['validated_by'],
            $r['validation_remarks'] ?? 'â€”'
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
        echo '<td>' . htmlspecialchars($r['vehicle_plate'] ?? 'â€”') . '</td>';
        echo '<td style="text-align:right">&#8369;' . number_format((float)$r['amount'], 2) . '</td>';
        echo '<td>' . htmlspecialchars($r['payment_method']) . '</td>';
        echo '<td>' . htmlspecialchars($pay_st) . '</td>';
        echo '<td>' . htmlspecialchars($r['shift'] ?? 'N/A') . '</td>';
        echo '<td>' . htmlspecialchars($r['staff_name']) . '</td>';
        echo '<td>' . date('M d, Y H:i', strtotime($r['txn_date'])) . '</td>';
        echo '<td>' . htmlspecialchars($r['validated_by']) . '</td>';
        echo '<td>' . htmlspecialchars($r['validation_remarks'] ?? 'â€”') . '</td>';
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
        echo '<td>' . htmlspecialchars($r['vehicle_plate'] ?? 'â€”') . '</td>';
        echo '<td class="amount">&#8369;' . number_format((float)$r['amount'], 2) . '</td>';
        echo '<td>' . htmlspecialchars($r['payment_method']) . '</td>';
        echo '<td>' . htmlspecialchars($pay_st) . '</td>';
        echo '<td>' . htmlspecialchars($r['shift'] ?? 'N/A') . '</td>';
        echo '<td>' . htmlspecialchars($r['staff_name']) . '</td>';
        echo '<td>' . date('M d, Y H:i', strtotime($r['txn_date'])) . '</td>';
        echo '<td>' . htmlspecialchars($r['validated_by']) . '</td>';
        echo '<td>' . htmlspecialchars($r['validation_remarks'] ?? 'â€”') . '</td>';
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
    gap: 3px !important;
    padding: 2px 4px !important;
    font-size: 9.5px !important;
    font-weight: 600 !important;
    border-radius: 4px !important;
    text-decoration: none !important;
    white-space: nowrap !important;
    width: 100% !important;
    box-sizing: border-box !important;
    background: transparent !important;
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
    <form method="get" style="display:flex;flex-direction:column;gap:10px;width:100%;">
        <!-- Row 1: Dropdowns -->
        <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
            <div class="vt-flt-grp">
                <label class="vt-lbl"><i class="fas fa-tag"></i> Type</label>
                <select name="type" class="vt-inp" style="width:180px;">
                    <option value="" <?php echo $type_filter === '' ? 'selected' : ''; ?>>All Types</option>
                    <option value="merchandise" <?php echo $type_filter === 'merchandise' ? 'selected' : ''; ?>>Merchandise</option>
                    <option value="job_order"   <?php echo $type_filter === 'job_order'   ? 'selected' : ''; ?>>Job Order</option>
                    <option value="combined"    <?php echo $type_filter === 'combined'    ? 'selected' : ''; ?>>Job Order + Merchandise</option>
                </select>
            </div>
            <div class="vt-flt-grp">
                <label class="vt-lbl"><i class="fas fa-wallet"></i> Payment</label>
                <select name="payment_method" class="vt-inp" style="width:145px;">
                    <option value="" <?php echo $payment_method === '' ? 'selected' : ''; ?>>All Methods</option>
                    <option value="Cash"          <?php echo $payment_method === 'Cash'          ? 'selected' : ''; ?>>Cash</option>
                    <option value="Card"          <?php echo $payment_method === 'Card'          ? 'selected' : ''; ?>>Card</option>
                    <option value="E-Wallet"      <?php echo $payment_method === 'E-Wallet'      ? 'selected' : ''; ?>>E-Wallet</option>
                    <option value="Petron E-Fuel" <?php echo $payment_method === 'Petron E-Fuel' ? 'selected' : ''; ?>>Petron E-Fuel</option>
                    <option value="Fleet Card"    <?php echo $payment_method === 'Fleet Card'    ? 'selected' : ''; ?>>Fleet Card</option>
                    <option value="Credit"        <?php echo $payment_method === 'Credit'        ? 'selected' : ''; ?>>Credit</option>
                </select>
            </div>
            <div class="vt-flt-grp">
                <label class="vt-lbl"><i class="fas fa-moon"></i> Shift</label>
                <select name="shift" class="vt-inp" style="width:120px;">
                    <option value="" <?php echo $shift_filter === '' ? 'selected' : ''; ?>>All Shifts</option>
                    <option value="Shift 1" <?php echo $shift_filter === 'Shift 1' ? 'selected' : ''; ?>>Shift 1</option>
                    <option value="Shift 2" <?php echo $shift_filter === 'Shift 2' ? 'selected' : ''; ?>>Shift 2</option>
                </select>
            </div>
            <div class="vt-flt-grp">
                <label class="vt-lbl"><i class="fas fa-circle-check"></i> Status</label>
                <select name="status" class="vt-inp" style="width:130px;">
                    <option value="" <?php echo $status_filter === '' ? 'selected' : ''; ?>>All Statuses</option>
                    <option value="Completed" <?php echo $status_filter === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="Voided"    <?php echo $status_filter === 'Voided'    ? 'selected' : ''; ?>>Voided</option>
                    <option value="Adjusted"  <?php echo $status_filter === 'Adjusted'  ? 'selected' : ''; ?>>Adjusted</option>
                </select>
            </div>
        </div>
        <!-- Row 2: Search, Dates, Buttons -->
        <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
            <div class="vt-flt-grp" style="flex:1;min-width:220px;">
                <label class="vt-lbl"><i class="fas fa-search"></i> Search</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                       class="vt-inp" placeholder="Search Transaction ID, OR No., Customer, Plate No." style="width:100%;">
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
<div class="txn-kpi-grid">
    <div class="txn-kpi-card">
        <div class="txn-kpi-lbl"><i class="fas fa-receipt"></i> Total Transactions</div>
        <div class="txn-kpi-val"><?php echo count($rows); ?></div>
    </div>
    <div class="txn-kpi-card blue">
        <div class="txn-kpi-lbl"><i class="fas fa-box"></i> Merchandise</div>
        <div class="txn-kpi-val"><?php echo $merch_count; ?></div>
    </div>
    <div class="txn-kpi-card purple">
        <div class="txn-kpi-lbl"><i class="fas fa-wrench"></i> Job Orders</div>
        <div class="txn-kpi-val"><?php echo $jo_count; ?></div>
    </div>
    <div class="txn-kpi-card green">
        <div class="txn-kpi-lbl"><i class="fas fa-check-circle"></i> Paid</div>
        <div class="txn-kpi-val"><?php echo $paid_count; ?></div>
    </div>
    <div class="txn-kpi-card danger">
        <div class="txn-kpi-lbl"><i class="fas fa-clock"></i> Unpaid / Partial</div>
        <div class="txn-kpi-val"><?php echo $unpaid_count; ?></div>
    </div>
    <div class="txn-kpi-card total-amount-card">
        <div class="txn-kpi-lbl"><i class="fas fa-peso-sign"></i> Total Amount</div>
        <div class="txn-kpi-val">&#8369;<?php echo number_format($total_amount, 2); ?></div>
    </div>
</div>

<!-- Table -->
<div class="card" style="padding:0;width:100%;">
    <div style="width:100%;overflow-x:hidden;">
    <table class="vt-table" style="table-layout:fixed;width:100%;">
        <colgroup>
            <col style="width:6%;"><!-- OR NO. -->
            <col style="width:5.5%;"><!-- TXN ID -->
            <col style="width:6.5%;"><!-- CUSTOMER -->
            <col style="width:10.5%;"><!-- TYPE -->
            <col style="width:9%;"><!-- PRODUCTS -->
            <col style="width:7%;"><!-- SERVICE TYPE -->
            <col style="width:5.5%;"><!-- SVC FEE -->
            <col style="width:5.5%;"><!-- LABOR FEE -->
            <col style="width:5%;"><!-- PLATE NO. -->
            <col style="width:6.5%;"><!-- TOTAL -->
            <col style="width:5%;"><!-- PAYMENT -->
            <col style="width:4%;"><!-- SHIFT -->
            <col style="width:5.5%;"><!-- STAFF -->
            <col style="width:5.5%;"><!-- STATUS -->
            <col style="width:6.5%;"><!-- DATE & TIME -->
            <col style="width:5.5%;"><!-- ACTIONS -->
        </colgroup>
        <thead>
            <tr>
                <th style="white-space:nowrap;font-size:9.5px;">OR NO.</th>
                <th style="white-space:nowrap;font-size:9.5px;">TXN ID</th>
                <th style="white-space:nowrap;font-size:9.5px;">CUSTOMER</th>
                <th style="white-space:nowrap;font-size:9.5px;">TYPE</th>
                <th style="white-space:nowrap;font-size:9.5px;">PRODUCTS</th>
                <th style="white-space:nowrap;font-size:9.5px;">SERVICE TYPE</th>
                <th style="text-align:right;white-space:nowrap;font-size:9.5px;">SVC FEE</th>
                <th style="text-align:right;white-space:nowrap;font-size:9.5px;">LABOR FEE</th>
                <th style="text-align:center;white-space:nowrap;font-size:9.5px;">PLATE NO.</th>
                <th style="text-align:right;white-space:nowrap;font-size:9.5px;">TOTAL</th>
                <th style="white-space:nowrap;font-size:9.5px;">PAYMENT</th>
                <th style="white-space:nowrap;font-size:9.5px;">SHIFT</th>
                <th style="white-space:nowrap;font-size:9.5px;">STAFF</th>
                <th style="text-align:center;white-space:nowrap;font-size:9.5px;">STATUS</th>
                <th style="white-space:nowrap;font-size:9.5px;">DATE & TIME</th>
                <th style="text-align:center;white-space:nowrap;font-size:9.5px;">ACTIONS</th>
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
                    <td style="white-space:nowrap;font-weight:700;font-size:10.5px;color:#0f172a;">
                        <?php echo htmlspecialchars($or_no); ?>
                    </td>
                    <td style="white-space:nowrap;font-size:9.5px;font-family:monospace;color:#64748b;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($r['txn_id']); ?>">
                        <?php echo htmlspecialchars($r['txn_id']); ?>
                    </td>
                    <td style="font-size:10.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#1e293b;" title="<?php echo htmlspecialchars($r['customer']); ?>">
                        <?php echo htmlspecialchars($r['customer']); ?>
                    </td>
                    <td style="white-space:nowrap;">
                        <span class="badge <?= $tBadge ?>"><i class="fas <?= $tIcon ?>"></i> <?= htmlspecialchars($tLabel) ?></span>
                    </td>
                    <!-- Products column -->
                    <td style="font-size:10px;line-height:1.2;vertical-align:middle;word-break:break-word;">
                        <?= format_transaction_items($r['items'] ?? '') ?>
                    </td>
                    <!-- Service Type column -->
                    <td style="font-size:10px;color:#475569;line-height:1.2;vertical-align:middle;word-break:break-word;" title="<?= htmlspecialchars(trim($r['service_type'] ?? $r['job_order_service'] ?? '')) ?>">
                        <?= htmlspecialchars(!empty(trim($r['service_type'] ?? $r['job_order_service'] ?? '')) ? trim($r['service_type'] ?? $r['job_order_service'] ?? '') : 'â€”') ?>
                    </td>
                    <!-- Service Fee column -->
                    <td style="font-size:10.5px;font-weight:700;color:#2563eb;vertical-align:middle;text-align:right;white-space:nowrap;">
                        <?php
                        $s_cost = (float)($r['service_fee'] ?? 0);
                        echo $s_cost > 0 ? 'â‚±' . number_format($s_cost, 2) : '<span style="color:#cbd5e1;font-weight:400;">â€”</span>';
                        ?>
                    </td>
                    <!-- Labor Fee column -->
                    <td style="font-size:10.5px;font-weight:700;color:#16a34a;vertical-align:middle;text-align:right;white-space:nowrap;">
                        <?php
                        $l_cost = (float)($r['labor_fee'] ?? 0);
                        echo $l_cost > 0 ? 'â‚±' . number_format($l_cost, 2) : '<span style="color:#cbd5e1;font-weight:400;">â€”</span>';
                        ?>
                    </td>
                    <!-- Vehicle column -->
                    <td style="font-size:10.5px;text-align:center;white-space:nowrap;color:#475569;">
                      <?php
                        $veh = trim($r['vehicle_plate'] ?? '');
                        if ($veh === '' || $veh === 'â€”' || $veh === 'N/A') {
                            echo '<span style="color:#cbd5e1;">N/A</span>';
                        } else {
                            echo htmlspecialchars($veh);
                        }
                      ?>
                    </td>
                    <!-- Total Amount column -->
                    <td style="font-weight:700;font-size:10.5px;text-align:right;white-space:nowrap;color:#0f172a;">
                        â‚±<?php echo number_format((float)$r['amount'], 2); ?>
                    </td>
                    <!-- Payment Method column -->
                    <td style="font-size:10px;white-space:nowrap;color:#334155;">
                        <div><?php echo htmlspecialchars($r['payment_method']); ?></div>
                        <div style="font-size:9px;font-weight:700;color:<?= $pay_st['color'] ?? '#16a34a' ?>;"><?= htmlspecialchars($pay_st['label'] ?? 'Paid') ?></div>
                    </td>
                    <!-- Shift column -->
                    <td style="font-size:10px;white-space:nowrap;color:#475569;">
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
                    <td style="font-size:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#475569;" title="<?php echo htmlspecialchars($r['staff_name']); ?>"><?php echo htmlspecialchars($r['staff_name']); ?></td>
                    <!-- Status column with dot badge (matching Admin) -->
                    <td style="text-align:center;white-space:nowrap;">
                        <?php
                        $vst = strtolower(trim($r['validation_status'] ?? 'completed'));
                        if ($vst === 'voided') {
                            echo '<span class="badge badge-red" style="white-space:nowrap;"><i class="fas fa-circle" style="font-size:6px;vertical-align:middle;margin-right:2px;"></i>Voided</span>';
                        } elseif ($vst === 'adjusted') {
                            echo '<span class="badge badge-orange" style="white-space:nowrap;"><i class="fas fa-circle" style="font-size:6px;vertical-align:middle;margin-right:2px;"></i>Adjusted</span>';
                        } else {
                            echo '<span class="badge badge-green" style="white-space:nowrap;"><i class="fas fa-circle" style="font-size:6px;vertical-align:middle;margin-right:2px;"></i>Completed</span>';
                        }
                        ?>
                    </td>
                    <!-- Date & Time column -->
                    <td style="white-space:nowrap;line-height:1.2;">
                        <div style="font-size:10px;font-weight:600;color:#334155;white-space:nowrap;"><?php echo date('M d, Y', strtotime($r['txn_date'])); ?></div>
                        <div style="font-size:9.5px;color:#64748b;white-space:nowrap;"><?php echo date('h:i A', strtotime($r['txn_date'])); ?></div>
                    </td>
                    <!-- Actions column -->
                    <td style="text-align:center;padding:4px 2px;vertical-align:middle;white-space:nowrap;">
                        <?php if ($show_actions): ?>
                        <?php
                        $receipt_url = ($r['_source'] === 'job_orders')
                            ? 'receipt.php?id=' . urlencode((string)$r['row_id']) . '&type=job_order'
                            : 'receipt.php?id=' . urlencode((string)$r['txn_id']) . '&type=merchandise';
                        ?>
                        <div style="display:flex;flex-direction:column;gap:2px;align-items:stretch;">
                        <a class="vt-btn-act-sm" href="<?= htmlspecialchars($receipt_url) ?>" target="_blank" rel="noopener" title="Reprint Receipt" style="color:#002F70;border:1px solid #002F70;background:transparent !important;">
                            <i class="fas fa-receipt" style="font-size:8.5px;"></i> Reprint
                        </a>
                        <?php if ($vst !== 'voided'): ?>
                            <?php if ($r['_source'] === 'merchandise_transactions'): ?>
                            <button type="button" class="vt-btn-act-sm" style="color:#16a34a;border:1px solid #16a34a;background:transparent !important;cursor:pointer;" onclick="openAdjustModal(<?= $r['row_id'] ?>, '<?= htmlspecialchars(addslashes($r['txn_id'])) ?>', '<?= htmlspecialchars(addslashes($r['customer'])) ?>', '<?= htmlspecialchars(addslashes($r['entry_type'])) ?>', '<?= htmlspecialchars(addslashes($r['txn_date'])) ?>', '<?= htmlspecialchars(addslashes($r['staff_name'])) ?>', '<?= htmlspecialchars(addslashes($r['payment_method'])) ?>', '<?= htmlspecialchars(addslashes($r['payment_status'] ?? 'Paid')) ?>')" title="Adjust">
                                <i class="fas fa-pen" style="font-size:8.5px;"></i> Adjust
                            </button>
                            <?php endif; ?>

                            <?php 
                            $is_job_order = ($r['entry_type'] === 'Job Order' || $r['entry_type'] === 'Combined' || $r['_source'] === 'job_orders');
                            $wf_status = strtolower(trim($r['workflow_status'] ?? 'pending'));
                            
                            if ($is_job_order): 
                                if ($wf_status === 'pending'): 
                            ?>
                                <button type="button" class="vt-btn-act-sm" style="color:#dc2626;border:1px solid #dc2626;background:transparent !important;cursor:pointer;" onclick="openVoidModal(<?= $r['row_id'] ?>, '<?= htmlspecialchars(addslashes($r['txn_id'])) ?>', '<?= htmlspecialchars(addslashes($r['customer'])) ?>', '<?= htmlspecialchars(addslashes($r['_source'])) ?>')" title="Void">
                                    <i class="fas fa-ban" style="font-size:8.5px;"></i> Void
                                </button>
                                <?php else: ?>
                                <button type="button" class="vt-btn-act-sm" style="color:#94a3b8;border:1px solid #cbd5e1;background:transparent !important;cursor:not-allowed;" disabled title="Cannot void In Progress or Completed Job Orders">
                                    <i class="fas fa-ban" style="font-size:8.5px;"></i> Void
                                </button>
                                <?php endif; ?>
                            <?php else: ?>
                                <button type="button" class="vt-btn-act-sm" style="color:#dc2626;border:1px solid #dc2626;background:transparent !important;cursor:pointer;" onclick="openVoidModal(<?= $r['row_id'] ?>, '<?= htmlspecialchars(addslashes($r['txn_id'])) ?>', '<?= htmlspecialchars(addslashes($r['customer'])) ?>', '<?= htmlspecialchars(addslashes($r['_source'])) ?>')" title="Void">
                                    <i class="fas fa-ban" style="font-size:8.5px;"></i> Void
                                </button>
                            <?php endif; ?>
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
    <div class="vt-modal" style="max-width:700px;">
        <div class="vt-modal-header">
            <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:36px;height:36px;background:#eff6ff;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-eye" style="color:#0284c7;font-size:15px;"></i>
                </div>
                <div>
                    <div style="font-size:14px;font-weight:700;color:#1e293b;">Transaction Details</div>
                </div>
            </div>
        </div>
        <div id="viewTransactionContent" class="vt-modal-body">
            <div style="text-align:center;padding:40px;">
                <i class="fas fa-spinner fa-spin" style="font-size:32px;color:#003d82;"></i>
                <div style="margin-top:12px;color:#64748b;">Loading transaction details...</div>
            </div>
        </div>
        <div class="vt-modal-footer">
            <button type="button" class="vt-btn vt-btn-reset" onclick="closeViewModal()">Close</button>
        </div>
    </div>
</div>

<!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• ADJUST MODAL -->
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
    <div id="voidModalMainContent" style="display:flex; flex-direction:column; max-height:95vh; width:100%; overflow:hidden;">
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
              <div><span style="font-size:9px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;">Transaction ID</span><strong id="voidInfoTxnId" style="font-family:monospace;font-size:11px;color:#0f172a;">-</strong></div>
              <div><span style="font-size:9px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;">Transaction Type</span><span id="voidInfoTxnType" style="font-weight:600;color:#0f172a;">-</span></div>
              <div style="grid-column: span 2;"><span style="font-size:9px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;">Customer Name</span><span id="voidInfoCustomer" style="color:#0f172a;">-</span></div>
              <div><span style="font-size:9px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;">Processed By</span><span id="voidInfoStaff" style="color:#0f172a;">-</span></div>
              <div><span style="font-size:9px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;">Shift</span><span id="voidInfoShift" style="color:#0f172a;">-</span></div>
              <div style="grid-column: span 2;"><span style="font-size:9px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;">Date & Time</span><span id="voidInfoDateTime" style="color:#0f172a;">-</span></div>
              <div><span style="font-size:9px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;">Payment Method</span><span id="voidInfoPayMethod" style="color:#0f172a;">-</span></div>
              <div><span style="font-size:9px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;">Payment Status</span><span id="voidInfoPayStatus" style="color:#0f172a;">-</span></div>
              <div><span style="font-size:9px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;">Transaction Status</span><span id="voidInfoStatus" style="color:#0f172a;">-</span></div>
              <div><span style="font-size:9px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;">Total Amount</span><strong id="voidInfoTotalAmount" style="color:#002F70;font-size:12px;">-</strong></div>
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

        <!-- Inventory and Sales Impact Preview Row -->
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
          <!-- Inventory Impact -->
          <div>
            <div style="font-size:11px;font-weight:800;color:#16a34a;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;">
              <i class="fas fa-cubes"></i> Inventory Impact
            </div>
            <div style="border:1px solid #bbf7d0;background:#f0fdf4;border-radius:8px;padding:12px;min-height:90px;display:flex;flex-direction:column;justify-content:space-between;">
              <div>
                <div style="font-weight:700;color:#15803d;font-size:11px;margin-bottom:4px;">Inventory To Restore:</div>
                <div id="voidInventoryImpactList" style="display:flex;flex-direction:column;gap:3px;color:#166534;font-size:11px;font-weight:600;">
                  <!-- âœ” Product Name (+Qty) -->
                </div>
              </div>
              <div style="font-size:10px;color:#15803d;margin-top:6px;font-style:italic;">
                These quantities will automatically return to inventory after voiding.
              </div>
            </div>
          </div>
          
          <!-- Sales Impact -->
          <div>
            <div style="font-size:11px;font-weight:800;color:#ea580c;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;">
              <i class="fas fa-chart-bar"></i> Sales Impact
            </div>
            <div style="border:1px solid #fed7aa;background:#fff7ed;border-radius:8px;padding:12px;min-height:90px;display:flex;flex-direction:column;justify-content:space-between;">
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div>
                  <span style="display:block;font-size:10px;font-weight:700;color:#c2410c;">Current Sale</span>
                  <strong id="voidSalesCurrent" style="font-size:13px;color:#0f172a;">â‚±0.00</strong>
                </div>
                <div>
                  <span style="display:block;font-size:10px;font-weight:700;color:#c2410c;">After Void</span>
                  <strong id="voidSalesAfter" style="color:#dc2626;font-size:13px;">-â‚±0.00</strong>
                </div>
              </div>
              <div style="font-size:10px;color:#9a3412;margin-top:4px;font-weight:600;display:flex;flex-direction:column;gap:2px;">
                <div>âœ” Sales Report: <span id="voidSalesDiff">-â‚±0.00</span></div>
                <div>âœ” Payment Report Updated</div>
                <div>âœ” Customer Purchase History Updated</div>
              </div>
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
            <div style="font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;margin-bottom:6px;">ðŸ”‘ Manager Authentication (Recommended)</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
              <div>
                <label style="font-size:9px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:3px;">Confirm Manager Password</label>
                <input type="password" id="voidAuthPassword" oninput="validateVoidForm()" placeholder="Password..." style="width:100%;height:28px;padding:0 8px;border:1px solid #cbd5e1;border-radius:6px;font-size:11px;box-sizing:border-box;">
              </div>
              <div>
                <label style="font-size:9px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:3px;">Manager PIN</label>
                <input type="password" id="voidAuthPin" maxlength="6" oninput="validateVoidForm()" placeholder="PIN..." style="width:100%;height:28px;padding:0 8px;border:1px solid #cbd5e1;border-radius:6px;font-size:11px;box-sizing:border-box;">
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
            <span>I verified the inventory restoration.</span>
          </label>
          <label style="display:flex;align-items:flex-start;gap:8px;font-size:11px;color:#334155;cursor:pointer;user-select:none;">
            <input type="checkbox" class="void-checklist" onchange="validateVoidForm()" style="margin-top:2px;">
            <span>I understand sales reports will be recalculated.</span>
          </label>
          <label style="display:flex;align-items:flex-start;gap:8px;font-size:11px;color:#334155;cursor:pointer;user-select:none;">
            <input type="checkbox" class="void-checklist" onchange="validateVoidForm()" style="margin-top:2px;">
            <span>I understand customer history will be updated.</span>
          </label>
          <label style="display:flex;align-items:flex-start;gap:8px;font-size:11px;color:#334155;cursor:pointer;user-select:none;">
            <input type="checkbox" class="void-checklist" onchange="validateVoidForm()" style="margin-top:2px;">
            <span>I understand this action cannot be undone.</span>
          </label>
        </div>


      </div>
      
      <div class="vt-modal-footer" style="padding:12px 20px; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:8px;">
        <button type="button" class="vt-btn vt-btn-reset" onclick="closeVoidModal()">Cancel</button>
        <button type="button" class="vt-btn" onclick="previewVoidImpact()" style="border-color:#ea580c;color:#ea580c;height:36px;padding:0 14px;border-radius:7px;font-size:13px;font-weight:700;"><i class="fas fa-chart-line"></i> Preview Impact</button>
        <button type="button" id="confirmVoidBtnNew" onclick="submitVoidNew()" disabled style="background:#dc2626;color:#fff;border:none;height:36px;padding:0 20px;border-radius:7px;font-size:13px;font-weight:700;cursor:not-allowed;opacity:0.6;display:inline-flex;align-items:center;gap:6px;">
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
          <div>âœ” Inventory Restored Successfully</div>
          <div>âœ” Sales Updated Successfully</div>
          <div>âœ” Audit Log Created</div>
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
    padding-right: 0 !important;
    margin-right: 0 !important;
    overflow-x: hidden !important;
    max-width: 100% !important;
}
.main-content {
    padding-right: 0 !important;
    margin-right: 0 !important;
    overflow-x: hidden !important;
    max-width: 100% !important;
}
.stock-page {
    padding-right: 0 !important;
    padding-left: 0 !important;
    margin-right: 0 !important;
    max-width: 100% !important;
    width: 100% !important;
}

/* == PAGE HEADER - matches SuperAdmin page-head standard == */
.page-head.txn-page-head { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px; margin-top:-12px !important; }
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
    font-size:11px;
    table-layout:auto;
    min-width: 1200px;
}
.vt-table thead th { 
    background:#002F70;color:#fff;font-size:10px;font-weight:700;text-transform:uppercase;
    letter-spacing:.2px;padding:8px 8px;border-bottom:2px solid #001a3d;
    text-align:left;vertical-align:middle;white-space:normal;word-wrap:break-word;
}
.vt-table tbody td { 
    padding:7px 8px;
    border-bottom:1px solid #f1f5f9;
    vertical-align:top;
    background:#fff;
    font-size:11px;
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

/* Table wrapper - allow horizontal scroll ONLY on table */
.card > div { 
    width:100% !important; 
    max-width:100% !important; 
    overflow-x: auto !important;
    overflow-y: visible !important;
}

/* Make filter responsive */
.vt-filter-card form { max-width:100% !important; overflow-x:hidden !important; }

/* Badges */
.vt-badge { 
    display:inline-block;padding:3px 10px;border-radius:3px;font-size:11px;font-weight:600;white-space:nowrap;background:#f8fafc;color:#64748b;border:1px solid #e2e8f0;
}
.vt-badge-merch { background:#f0fdf4;color:#15803d;border-color:#bbf7d0; }
.vt-badge-jo { background:#fffbeb;color:#b45309;border-color:#fde68a; }
.vt-badge-combined { background:#f5f3ff;color:#6d28d9;border-color:#ddd6fe; }
.vt-badge-paid { background:#f0fdf4;color:#166534;border-color:#bbf7d0; }
.vt-badge-partial { background:#fef3c7;color:#92400e;border-color:#fde047; }
.vt-badge-unpaid { background:#fef2f2;color:#991b1b;border-color:#fecaca; }

/* Item chips & expand rows */
.rc-item-chip{display:inline-flex;align-items:center;gap:3px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:3px;padding:2px 6px;font-size:11px;font-weight:600;color:#374151;margin:1px 2px 1px 0;white-space:normal;word-break:break-word;max-width:100%;cursor:pointer}
.rc-item-chip.svc{background:#fffbeb;border-color:#fde68a;color:#92400e}
.rc-item-chip .rc-chip-qty{background:#002F70;color:#fff;border-radius:2px;padding:0 3px;font-size:8px;margin-left:2px}
.rc-expand-row td{background:#f8fafc;padding:0}
.rc-expand-inner{padding:10px 16px;border-top:2px solid #e2e8f0}
.rc-expand-tbl{width:100%;border-collapse:collapse;font-size:11px}
.rc-expand-tbl th{padding:5px 10px;text-align:left;font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid #e2e8f0}
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
.vt-modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:9999; align-items:center; justify-content:center; }
.vt-modal-overlay.active { display:flex; }
.vt-modal { background:#fff; border-radius:12px; width:100%; max-width:700px; box-shadow:0 8px 40px rgba(0,0,0,.2); max-height:95vh; display:flex; flex-direction:column; overflow:hidden; }
.vt-modal-header { display:flex; align-items:center; justify-content:space-between; padding:20px 24px; border-bottom:1px solid #e2e8f0; }
.vt-modal-header h3 { margin:0; font-size:18px; font-weight:700; color:#1e293b; display:flex; align-items:center; }
.vt-modal-close { background:none; border:none; font-size:28px; color:#64748b; cursor:pointer; padding:0; width:32px; height:32px; border-radius:6px; }
.vt-modal-close:hover { background:#f1f5f9; color:#1e293b; }
.vt-modal-body { padding:24px; overflow-y:auto; flex:1; }
.vt-modal-footer { padding:16px 24px; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:8px; }
.vt-detail-grid { display:grid; grid-template-columns:140px 1fr; gap:12px 20px; font-size:14px; }
.vt-detail-label { font-weight:600; color:#64748b; }
.vt-detail-value { color:#1e293b; }
.vt-detail-amount { color:#002F70; font-weight:700; font-size:16px; }
</style>

<script>
function viewValidatedTransaction(source, id) {
    // Open modal
    document.getElementById('viewTransactionModal').classList.add('active');
    document.getElementById('viewTransactionContent').innerHTML = '<div style="text-align:center;padding:40px;"><i class="fas fa-spinner fa-spin" style="font-size:32px;color:#003d82;"></i><div style="margin-top:12px;color:#64748b;">Loading...</div></div>';
    
    // Fetch transaction details
    fetch('../backend/get_transaction_details.php?type=' + source + '&id=' + id)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let html = '<div class="vt-detail-grid">';
                
                if (data.type === 'merchandise') {
                    // Merchandise transaction details
                    html += '<div class="vt-detail-label">Transaction ID:</div><div class="vt-detail-value" style="font-family:monospace;font-weight:600;">' + data.transaction_id + '</div>';
                    html += '<div class="vt-detail-label">Customer:</div><div class="vt-detail-value">' + data.customer_name + '</div>';
                    html += '<div class="vt-detail-label">Item SKU:</div><div class="vt-detail-value">' + data.item_sku + '</div>';
                    html += '<div class="vt-detail-label">Quantity:</div><div class="vt-detail-value">' + data.quantity + '</div>';
                    html += '<div class="vt-detail-label">Unit Price:</div><div class="vt-detail-value">â‚±' + data.unit_price + '</div>';
                    html += '<div class="vt-detail-label">Total Amount:</div><div class="vt-detail-amount">â‚±' + data.total_amount + '</div>';
                    html += '<div class="vt-detail-label">Payment Method:</div><div class="vt-detail-value">' + data.payment_method + '</div>';
                    if (data.amount_tendered !== 'N/A') {
                        html += '<div class="vt-detail-label">Amount Tendered:</div><div class="vt-detail-value">â‚±' + data.amount_tendered + '</div>';
                        html += '<div class="vt-detail-label">Change:</div><div class="vt-detail-value">â‚±' + data.change_amount + '</div>';
                    }
                    html += '<div class="vt-detail-label">Transaction Date:</div><div class="vt-detail-value">' + data.transaction_date + '</div>';
                    html += '<div class="vt-detail-label">Staff:</div><div class="vt-detail-value">' + data.staff_name + '</div>';
                    html += '<div class="vt-detail-label">Status:</div><div class="vt-detail-value"><span style="background:#f0fdf4;color:#166534;padding:4px 12px;border-radius:4px;font-size:12px;font-weight:600;">' + data.validation_status + '</span></div>';
                    html += '<div class="vt-detail-label">Validated By:</div><div class="vt-detail-value">' + data.validated_by + '</div>';
                    html += '<div class="vt-detail-label">Validated At:</div><div class="vt-detail-value">' + data.validated_at + '</div>';
                    if (data.shift !== 'N/A') {
                        html += '<div class="vt-detail-label">Shift:</div><div class="vt-detail-value">' + data.shift + '</div>';
                    }
                    if (data.remarks !== 'N/A') {
                        html += '<div class="vt-detail-label">Remarks:</div><div class="vt-detail-value">' + data.remarks + '</div>';
                    }
                } else if (data.type === 'job_order') {
                    // Job order details
                    html += '<div class="vt-detail-label">Job Order #:</div><div class="vt-detail-value" style="font-family:monospace;font-weight:600;">' + data.transaction_id + '</div>';
                    html += '<div class="vt-detail-label">Customer:</div><div class="vt-detail-value">' + data.customer_name + '</div>';
                    html += '<div class="vt-detail-label">Vehicle Plate:</div><div class="vt-detail-value">' + data.vehicle_plate + '</div>';
                    html += '<div class="vt-detail-label">Vehicle Type:</div><div class="vt-detail-value">' + data.vehicle_type + '</div>';
                    html += '<div class="vt-detail-label">Service Type:</div><div class="vt-detail-value">' + data.service_type + '</div>';
                    html += '<div class="vt-detail-label">Description:</div><div class="vt-detail-value">' + data.service_description + '</div>';
                    html += '<div class="vt-detail-label">Required Parts:</div><div class="vt-detail-value" style="font-size:12px;">' + data.required_parts + '</div>';
                    html += '<div class="vt-detail-label">Mechanic:</div><div class="vt-detail-value">' + data.mechanic_name + '</div>';
                    html += '<div class="vt-detail-label">Estimated Cost:</div><div class="vt-detail-value">â‚±' + data.estimated_cost + '</div>';
                    html += '<div class="vt-detail-label">Total Amount:</div><div class="vt-detail-amount">â‚±' + data.total_amount + '</div>';
                    html += '<div class="vt-detail-label">Amount Paid:</div><div class="vt-detail-value">â‚±' + data.amount_paid + '</div>';
                    html += '<div class="vt-detail-label">Change:</div><div class="vt-detail-value">â‚±' + data.change_amount + '</div>';
                    html += '<div class="vt-detail-label">Payment Method:</div><div class="vt-detail-value">' + data.payment_method + '</div>';
                    html += '<div class="vt-detail-label">Payment Status:</div><div class="vt-detail-value">' + data.payment_status + '</div>';
                    html += '<div class="vt-detail-label">Job Status:</div><div class="vt-detail-value">' + data.job_status + '</div>';
                    html += '<div class="vt-detail-label">Created Date:</div><div class="vt-detail-value">' + data.transaction_date + '</div>';
                    html += '<div class="vt-detail-label">Staff:</div><div class="vt-detail-value">' + data.staff_name + '</div>';
                    html += '<div class="vt-detail-label">Validation Status:</div><div class="vt-detail-value"><span style="background:#f0fdf4;color:#166534;padding:4px 12px;border-radius:4px;font-size:12px;font-weight:600;">' + data.validation_status + '</span></div>';
                    html += '<div class="vt-detail-label">Validated By:</div><div class="vt-detail-value">' + data.validated_by + '</div>';
                    html += '<div class="vt-detail-label">Validated At:</div><div class="vt-detail-value">' + data.validated_at + '</div>';
                    if (data.additional_notes !== 'N/A') {
                        html += '<div class="vt-detail-label">Notes:</div><div class="vt-detail-value">' + data.additional_notes + '</div>';
                    }
                }
                
                html += '</div>';
                document.getElementById('viewTransactionContent').innerHTML = html;
            } else {
                document.getElementById('viewTransactionContent').innerHTML = '<div style="text-align:center;padding:40px;color:#dc2626;"><i class="fas fa-exclamation-circle" style="font-size:32px;display:block;margin-bottom:12px;"></i>' + (data.error || 'Unable to load details') + '</div>';
            }
        })
        .catch(error => {
            console.error('Error loading transaction details:', error);
            document.getElementById('viewTransactionContent').innerHTML = '<div style="text-align:center;padding:40px;color:#f59e0b;"><i class="fas fa-exclamation-triangle" style="font-size:32px;display:block;margin-bottom:12px;"></i>Connection error. Please try again.</div>';
        });
}

function closeViewModal() {
    document.getElementById('viewTransactionModal').classList.remove('active');
}

/* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ ADJUST MODAL â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
let _adjRowId = null;
let _adjItems  = []; // fetched items

function openAdjustModal(rowId, txnId, customer, entryType, txnDate, staffName, payMethod, payStat) {
    _adjRowId = rowId;
    _adjItems = [];

    // Show loading state
    document.getElementById('adjustModal').classList.add('active');
    document.getElementById('adjustModalBody').innerHTML =
        '<div style="text-align:center;padding:40px"><i class="fas fa-spinner fa-spin" style="font-size:28px;color:#d97706"></i><div style="margin-top:10px;color:#64748b">Loading items...</div></div>';

    // Fetch items via existing API
    fetch('../backend/api/get_transaction_items.php?id=' + rowId)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                document.getElementById('adjustModalBody').innerHTML =
                    '<div style="color:#dc2626;padding:20px"><i class="fas fa-exclamation-circle"></i> ' + (data.error || 'Failed to load items') + '</div>';
                return;
            }
            _adjItems = data.items || [];
            const fmtDate = txnDate ? new Date(txnDate).toLocaleString('en-PH') : 'â€”';
            let html = `
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px 20px;margin-bottom:16px;padding:14px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;font-size:13px;">
              <div><span style="color:#64748b;font-size:11px;font-weight:700;display:block">Transaction ID</span><strong style="font-family:monospace">${txnId}</strong></div>
              <div><span style="color:#64748b;font-size:11px;font-weight:700;display:block">Customer</span>${customer}</div>
              <div><span style="color:#64748b;font-size:11px;font-weight:700;display:block">Type</span>${entryType}</div>
              <div><span style="color:#64748b;font-size:11px;font-weight:700;display:block">Date</span>${fmtDate}</div>
              <div><span style="color:#64748b;font-size:11px;font-weight:700;display:block">Staff Encoder</span>${staffName}</div>
            </div>
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
                    html += `
                    <tr style="border-bottom:1px solid #f1f5f9;">
                      <td style="padding:8px 10px;font-weight:600">${item.product_name}</td>
                      <td style="padding:8px 10px;">
                        <span style="background:${isSvc?'#fffbeb':'#f0fdf4'};color:${isSvc?'#b45309':'#15803d'};padding:2px 8px;border-radius:4px;font-size:10px;font-weight:700">
                          ${isSvc ? 'ðŸ”§ Service' : 'ðŸ“¦ Merchandise'}
                        </span>
                      </td>
                      <td style="padding:8px 10px;text-align:center;">
                        <input type="number" min="0" step="0.01"
                          id="adj_qty_${idx}" value="${parseFloat(item.quantity)}"
                          onchange="recalcAdjRow(${idx})"
                          style="width:70px;padding:4px 6px;border:1px solid #cbd5e1;border-radius:5px;text-align:center;font-size:12px;">
                      </td>
                      <td style="padding:8px 10px;text-align:right;">
                        <input type="number" min="0" step="0.01"
                          id="adj_price_${idx}" value="${parseFloat(item.unit_price)}"
                          onchange="recalcAdjRow(${idx})"
                          style="width:90px;padding:4px 6px;border:1px solid #cbd5e1;border-radius:5px;text-align:right;font-size:12px;">
                      </td>
                      <td style="padding:8px 10px;text-align:right;font-weight:700;color:#002F70" id="adj_sub_${idx}">
                        â‚±${parseFloat(item.subtotal).toFixed(2)}
                      </td>
                    </tr>`;
                });
            }
            html += `</tbody>
                <tfoot>
                  <tr style="border-top:2px solid #e2e8f0;background:#f8fafc;">
                    <td colspan="4" style="padding:8px 10px;text-align:right;font-weight:700;font-size:13px;">New Total:</td>
                    <td style="padding:8px 10px;text-align:right;font-weight:800;font-size:14px;color:#002F70" id="adjNewTotal">â‚±0.00</td>
                  </tr>
                </tfoot>
              </table>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
              <div>
                <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px">Payment Method</label>
                <select id="adjPayMethod" style="width:100%;height:36px;padding:0 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;background:#fff">
                  ${['Cash','GCash','Maya','Bank Transfer','Credit','Card','E-Wallet','Petron E-Fuel','Fleet Card'].map(m =>
                    `<option value="${m}" ${m===payMethod?'selected':''}>${m}</option>`).join('')}
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
              <textarea id="adjReason" rows="2" placeholder="Why is this adjustment being made?" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;resize:vertical;box-sizing:border-box;"></textarea>
            </div>
            <div>
              <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px">Manager Remarks <span style="color:#dc2626">*</span></label>
              <textarea id="adjManagerRemarks" rows="2" placeholder="Manager's notes on this adjustment..." style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;resize:vertical;box-sizing:border-box;"></textarea>
            </div>`;

            document.getElementById('adjustModalBody').innerHTML = html;
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
    if (subEl) subEl.textContent = 'â‚±' + sub.toFixed(2);
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
    if (el) el.textContent = 'â‚±' + total.toFixed(2);
}

function closeAdjustModal() {
    document.getElementById('adjustModal').classList.remove('active');
    _adjRowId = null;
    _adjItems = [];
}

function submitAdjustment() {
    const reason  = document.getElementById('adjReason')?.value.trim();
    const remarks = document.getElementById('adjManagerRemarks')?.value.trim();
    if (!reason)  { alert('Please enter the Adjustment Reason.'); return; }
    if (!remarks) { alert('Please enter Manager Remarks.'); return; }

    const btn = document.getElementById('saveAdjustBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

    const itemsPayload = (_adjItems || []).map((item, idx) => ({
        item_id   : item.id,
        quantity  : parseFloat(document.getElementById('adj_qty_'   + idx)?.value || item.quantity),
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
            // Show success banner at top of page
            let banner = document.getElementById('adjSuccessBanner');
            if (!banner) {
                banner = document.createElement('div');
                banner.id = 'adjSuccessBanner';
                banner.style.cssText = 'background:#d4edda;color:#155724;border:1px solid #c3e6cb;border-radius:8px;padding:12px 18px;margin-bottom:14px;display:flex;align-items:center;gap:10px;font-size:13px;font-weight:600;';
                const stockPage = document.querySelector('.stock-page');
                if (stockPage) stockPage.insertBefore(banner, stockPage.firstChild);
                else document.body.insertBefore(banner, document.body.firstChild);
            }
            banner.innerHTML = '<i class="fas fa-check-circle" style="font-size:16px;"></i> Transaction adjusted successfully. Old total: &#8369;' + parseFloat(data.old_total||0).toFixed(2) + ' &rarr; New total: &#8369;' + parseFloat(data.new_total||0).toFixed(2);
            banner.style.display = 'flex';
            setTimeout(() => location.reload(), 2200);
        } else {
            alert('Error: ' + (data.error || 'Adjustment failed. Please try again.'));
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Adjustment';
        if (window.showPetronFlash) {
            window.showPetronFlash('Connection error. Please try again.', 'error');
        } else {
            alert('Connection error. Please try again.');
        }
    });
}

/* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ VOID MODAL â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
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
    document.getElementById('voidAuthPin').value = '';
    
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
    document.getElementById('voidInventoryImpactList').innerHTML = '<div style="color:#64748b;">Loading...</div>';
    document.getElementById('voidSalesCurrent').innerText = 'â‚±0.00';
    document.getElementById('voidSalesAfter').innerText = 'â‚±0.00';
    document.getElementById('voidSalesDiff').innerText = '-â‚±0.00';
    
    document.getElementById('voidModal').classList.add('active');
    
    // Fetch data using Promise.all
    Promise.all([
        fetch('../backend/get_transaction_details.php?type=' + _voidSource + '&id=' + rowId).then(r => r.json()),
        fetch('get_transaction_items.php?id=' + rowId + '&source=' + _voidSource).then(r => r.json())
    ])
    .then(([detailsRes, itemsRes]) => {
        if (!detailsRes.success || !itemsRes.items) {
            alert('Error loading transaction details.');
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
        document.getElementById('voidInfoTotalAmount').innerText = 'â‚±' + grandTotal.toFixed(2);
        
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
                        <td style="padding:4px;border-bottom:1px solid #f1f5f9;text-align:right;">â‚±${parseFloat(item.unit_price).toFixed(2)}</td>
                        <td style="padding:4px;border-bottom:1px solid #f1f5f9;text-align:right;font-weight:700;color:#002F70;">â‚±${parseFloat(item.subtotal).toFixed(2)}</td>
                    </tr>`;
            });
            
            // Subtotal, Discount, VAT, Grand Total
            const subtotal = details.subtotal_amount && details.subtotal_amount !== 'N/A' ? parseFloat(details.subtotal_amount) : grandTotal / 1.12;
            const vat = details.vat_amount && details.vat_amount !== 'N/A' ? parseFloat(details.vat_amount) : grandTotal - subtotal;
            
            itemsHtml += `
                </tbody>
            </table>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:2px;font-size:10px;color:#475569;margin-bottom:8px;padding-right:4px;">
                <div>Subtotal: <strong>â‚±${subtotal.toFixed(2)}</strong></div>
                <div>Discount: <strong>â‚±0.00</strong></div>
                <div>VAT (12%): <strong>â‚±${vat.toFixed(2)}</strong></div>
                <div style="font-size:11px;color:#002F70;margin-top:2px;">Grand Total: <strong>â‚±${grandTotal.toFixed(2)}</strong></div>
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
                        <td style="padding:4px;border-bottom:1px solid #fef3c7;text-align:right;font-weight:700;color:#002F70;">â‚±${parseFloat(item.subtotal).toFixed(2)}</td>
                    </tr>`;
            });
            itemsHtml += `
                </tbody>
            </table>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:2px;font-size:10px;color:#475569;margin-bottom:8px;padding-right:4px;">
                <div style="font-size:11px;color:#002F70;margin-top:2px;">Grand Total: <strong>â‚±${grandTotal.toFixed(2)}</strong></div>
            </div>`;
        }
        
        document.getElementById('voidItemsContainer').innerHTML = itemsHtml || '<div style="color:#94a3b8;">No items in transaction.</div>';
        
        // Render Inventory Restore Impact Preview list
        let invHtml = '';
        if (merchandise.length > 0) {
            merchandise.forEach(item => {
                invHtml += `<div>âœ” ${item.product_name} (+${parseInt(item.quantity)})</div>`;
            });
        } else {
            invHtml = '<div style="color:#94a3b8;">No inventory items to restore.</div>';
        }
        document.getElementById('voidInventoryImpactList').innerHTML = invHtml;
        
        // Sales Impact fields
        document.getElementById('voidSalesCurrent').innerText = 'â‚±' + grandTotal.toFixed(2);
        document.getElementById('voidSalesAfter').innerText = 'â‚±0.00';
        document.getElementById('voidSalesDiff').innerText = '-â‚±' + grandTotal.toFixed(2);
        
        validateVoidForm();
    })
    .catch(err => {
        console.error(err);
        document.getElementById('voidItemsContainer').innerHTML = '<div style="color:#dc2626;">Error loading details.</div>';
        document.getElementById('voidInventoryImpactList').innerHTML = '<div style="color:#dc2626;">Error.</div>';
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

function validateVoidForm() {
    const reasonSelect = document.getElementById('voidReasonSelect').value;
    const reasonOther = document.getElementById('voidReasonOther').value.trim();
    const remarks = document.getElementById('voidManagerRemarksNew').value.trim();
    const password = document.getElementById('voidAuthPassword').value.trim();
    const pin = document.getElementById('voidAuthPin').value.trim();
    
    // Checkboxes
    let checklistChecked = true;
    document.querySelectorAll('.void-checklist').forEach(cb => {
        if (!cb.checked) checklistChecked = false;
    });
    
    let isReasonValid = (reasonSelect !== '');
    if (reasonSelect === 'Other') {
        isReasonValid = (reasonOther !== '');
    }
    
    // Either Password OR PIN must be filled
    const isAuthValid = (password !== '' || pin !== '');
    
    const confirmBtn = document.getElementById('confirmVoidBtnNew');
    if (confirmBtn) {
        if (isReasonValid && remarks !== '' && isAuthValid && checklistChecked) {
            confirmBtn.disabled = false;
            confirmBtn.style.opacity = '1';
            confirmBtn.style.cursor = 'pointer';
        } else {
            confirmBtn.disabled = true;
            confirmBtn.style.opacity = '0.6';
            confirmBtn.style.cursor = 'not-allowed';
        }
    }
}

function previewVoidImpact() {
    const txnId = document.getElementById('voidInfoTxnId').innerText;
    const amt = document.getElementById('voidInfoTotalAmount').innerText;
    
    alert(`Void Impact Preview for ${txnId}:\n\n` +
          `â€¢ Inventory: Stock quantities listed in the preview will be returned to store inventory.\n` +
          `â€¢ Sales: Sales totals will decrease by ${amt}.\n` +
          `â€¢ Reports: Shift summaries, Daily Sales, and Payment reports will exclude this transaction.\n` +
          `â€¢ Customer: Purchase history for this customer will be updated (marked VOIDED).\n` +
          `â€¢ Audit Log: Void event with manager name, reason, remarks, and timestamp will be logged.`);
}

function submitVoidNew() {
    const reasonSelect = document.getElementById('voidReasonSelect').value;
    const reasonOther = document.getElementById('voidReasonOther').value.trim();
    const remarks = document.getElementById('voidManagerRemarksNew').value.trim();
    const password = document.getElementById('voidAuthPassword').value.trim();
    const pin = document.getElementById('voidAuthPin').value.trim();
    
    const finalReason = (reasonSelect === 'Other') ? reasonOther : reasonSelect;
    
    const confirmBtn = document.getElementById('confirmVoidBtnNew');
    confirmBtn.disabled = true;
    confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Voiding...';
    
    fetch('../backend/api/void_transaction_manager.php', {
        method : 'POST',
        headers: { 'Content-Type': 'application/json' },
        body   : JSON.stringify({
            row_id          : _voidRowId,
            source          : _voidSource,
            void_reason     : finalReason,
            manager_remarks : remarks,
            password        : password,
            pin             : pin
        }),
    })
    .then(r => r.json())
    .then(data => {
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = '<i class="fas fa-ban"></i> Confirm Void';
        
        if (data.success) {
            // Populate Success View
            document.getElementById('voidSuccessTxnId').innerText = document.getElementById('voidInfoTxnId').innerText;
            
            // Switch views
            document.getElementById('voidModalMainContent').style.display = 'none';
            document.getElementById('voidModalSuccessContent').style.display = 'flex';
        } else {
            alert('Error: ' + (data.error || 'Failed to void transaction.'));
        }
    })
    .catch(err => {
        console.error(err);
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = '<i class="fas fa-ban"></i> Confirm Void';
        alert('Connection error. Please try again.');
    });
}

function closeVoidModalReload() {
    closeVoidModal();
    location.reload();
}

/* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ TOAST â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
function showToast(type, message) {
    if (window.showPetronFlash) {
        window.showPetronFlash(message, type === 'success' ? 'success' : 'error');
        return;
    }
    let toast = document.getElementById('mgr_toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'mgr_toast';
        toast.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:99999;padding:14px 20px;border-radius:10px;font-size:13px;font-weight:600;box-shadow:0 4px 20px rgba(0,0,0,.2);max-width:420px;transition:opacity .3s;';
        document.body.appendChild(toast);
    }
    toast.style.background = type === 'success' ? '#f0fdf4' : '#fef2f2';
    toast.style.color      = type === 'success' ? '#166534' : '#991b1b';
    toast.style.border     = type === 'success' ? '1px solid #bbf7d0' : '1px solid #fecaca';
    toast.style.opacity    = '1';
    toast.innerHTML        = message;
    clearTimeout(toast._timer);
    toast._timer = setTimeout(() => { toast.style.opacity = '0'; }, 4000);
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
