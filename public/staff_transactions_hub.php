<?php
/**
 * Staff Transactions Hub
 * Sidebar navigation for Fuel (internal) and Merchandise (customer-facing) transactions.
 */
// Force browser to always load fresh — prevents stale JS/form state
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

$page_id = 'transactions';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/transaction_schema_fix.php';
require_once __DIR__ . '/../backend/customer_module_helpers.php';
require_login();

// Set page_id based on section so sidebar highlights the correct nav item
// (fuel section → highlight Fuel Management, merchandise → highlight Transactions)
$_section_early = $_GET['section'] ?? 'merchandise';
if (in_array($_section_early, ['fuel', 'fuel_history'])) {
    $page_id = 'fuel';
}

$me         = current_user();
$station_id = user_station_id();
$role       = role_key($me['role'] ?? '');

customer_ensure_optional_columns($pdo);
customer_ensure_request_table($pdo);

// ── Generate a per-request API token so AJAX calls don't depend on session cookies ──
if (empty($_SESSION['api_token'])) {
    $_SESSION['api_token'] = bin2hex(random_bytes(32));
}
$_api_token = $_SESSION['api_token'];

// ── Module gate ───────────────────────────────────────────────
if (!in_array($role, ['superadmin','developer']) && !is_module_enabled('transactions')) {
    render_module_disabled_page('Transactions');
}

if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
    if ($role === 'superadmin' || $role === 'developer') {
        header('Location: super_admin_dashboard.php');
    } elseif ($role === 'admin') {
        header('Location: admin_dashboard.php');
    } elseif ($role === 'manager') {
        header('Location: manager_dashboard.php');
    } else {
        header('Location: staff_dashboard.php');
    }
    exit;
}

// ── Schema safety: widen columns that are too narrow (idempotent) ─────────────
foreach ([
    "ALTER TABLE fuel_transactions MODIFY COLUMN `shift_period` VARCHAR(50) NOT NULL DEFAULT 'general'",
    "ALTER TABLE fuel_transactions MODIFY COLUMN `payment_method` VARCHAR(50) NOT NULL DEFAULT 'Internal'",
    "ALTER TABLE fuel_deliveries MODIFY COLUMN `status` VARCHAR(60) NOT NULL DEFAULT 'Pending'",
    "ALTER TABLE fuel_deliveries MODIFY COLUMN `fuel_type` VARCHAR(100) DEFAULT NULL",
] as $_fix) {
    try { $pdo->exec($_fix); } catch (Exception $_e) {}
}
unset($_fix, $_e);

// ── Job Order Tracker enhancements — idempotent column additions ──────────────
// Adds: due_date, balance_due on job_orders; staff_remarks, manager_notes,
//       due_date, inventory_deducted on merchandise_transactions.
// Uses try/catch so existing columns are ignored silently.
foreach ([
    "ALTER TABLE `job_orders` ADD COLUMN `due_date` DATE DEFAULT NULL COMMENT 'Payment due date for receivables'",
    "ALTER TABLE `job_orders` ADD COLUMN `balance_due` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Outstanding balance'",
    "ALTER TABLE `merchandise_transactions` ADD COLUMN `staff_remarks` TEXT DEFAULT NULL COMMENT 'Staff-entered notes (separate from legacy remarks)'",
    "ALTER TABLE `merchandise_transactions` ADD COLUMN `manager_notes` TEXT DEFAULT NULL COMMENT 'Manager validation / approval notes'",
    "ALTER TABLE `merchandise_transactions` ADD COLUMN `due_date` DATE DEFAULT NULL COMMENT 'Payment due date for receivables'",
    "ALTER TABLE `merchandise_transactions` ADD COLUMN `inventory_deducted` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=stock deducted from station_inventory on approval'",
] as $_col_fix) {
    try { $pdo->exec($_col_fix); } catch (Exception $_ce) {
        // Column already exists — ignore Duplicate column error (1060)
    }
}
unset($_col_fix, $_ce);

// Fetch Loyalty Program settings
require_once __DIR__ . '/../backend/loyalty_schema_fix.php';
loyalty_ensure_tables($pdo);

$points_per_amount = 100.00;
$redemption_value  = 1.00;
try {
    $progStmt = $pdo->query("SELECT * FROM loyalty_programs WHERE status = 'active' ORDER BY id ASC LIMIT 1");
    $prog = $progStmt->fetch(PDO::FETCH_ASSOC);
    if ($prog) {
        $points_per_amount = floatval($prog['points_per_amount'] ?? 100.00) ?: 100.00;
        $redemption_value  = floatval($prog['redemption_value'] ?? 1.00) ?: 1.00;
    }
} catch (Exception $e) {}

// Active sub-section: merchandise | history | fuel | fuel_history
$section = $_GET['section'] ?? 'merchandise';
if (!in_array($section, ['merchandise', 'history', 'fuel', 'fuel_history'])) {
    $section = 'merchandise';
}

// Global variance tracking variables
$variance_alerts      = [];
$variance_alert_count = 0;

// ── Fuel types for this station — DB-driven, no hardcoded exclusions ─────────
$fuel_types = [];
try {
    // Pull excluded fuel type names from system_settings (key: excluded_fuel_types, comma-separated)
    $excl_setting = '';
    try {
        $excl_stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'excluded_fuel_types' LIMIT 1");
        $excl_stmt->execute();
        $excl_setting = trim($excl_stmt->fetchColumn() ?: '');
    } catch (Exception $e) {}
    $excluded_fuel_types = array_filter(array_map('trim', explode(',', $excl_setting)));

    $ft_sql = "
        SELECT fi.fuel_type,
               COALESCE(fi.current_level, fi.current_stock, 0) AS current_level,

               -- Price: strictly fuel_inventory.price_per_liter
               COALESCE(fi.price_per_liter, 0) AS price_per_liter,

               -- Calibration: fuel_calibration_records table (technician record, active) -> fuel_inventory fallback
               COALESCE(
                   (SELECT fc.calibration_liters FROM fuel_calibration_records fc
                    WHERE LOWER(TRIM(fc.fuel_type)) = LOWER(TRIM(fi.fuel_type))
                      AND fc.station_id = fi.station_id
                      AND fc.status = 'active'
                    ORDER BY fc.calibration_date DESC, fc.id DESC LIMIT 1),
                   fi.latest_calibration, 0
               ) AS calibration,

               -- Previous reading: last present_reading from ANY status (last ending reading)
               COALESCE(last_tx.present_reading, 0) AS previous_reading

        FROM fuel_inventory fi
        LEFT JOIN (
            SELECT ft2.station_id, ft2.fuel_type, ft2.present_reading
            FROM fuel_transactions ft2
            INNER JOIN (
                SELECT station_id, fuel_type, MAX(id) AS latest_id
                FROM fuel_transactions
                GROUP BY station_id, fuel_type
            ) lx ON lx.station_id = ft2.station_id
               AND LOWER(TRIM(lx.fuel_type)) = LOWER(TRIM(ft2.fuel_type))
               AND lx.latest_id = ft2.id
        ) last_tx ON last_tx.station_id = fi.station_id
                 AND LOWER(TRIM(last_tx.fuel_type)) = LOWER(TRIM(fi.fuel_type))
        WHERE fi.station_id = ?
    ";
    $ft_params = [$station_id];
    if (!empty($excluded_fuel_types)) {
        $placeholders = implode(',', array_fill(0, count($excluded_fuel_types), '?'));
        $ft_sql .= " AND fi.fuel_type NOT IN ($placeholders)";
        $ft_params = array_merge($ft_params, $excluded_fuel_types);
    }
    $ft_sql .= " ORDER BY fi.fuel_type";
    $stmt = $pdo->prepare($ft_sql);
    $stmt->execute($ft_params);
    
    // Fetch all fuel types from database - each one will be expanded by tanker config
    $fuel_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $fuel_types = []; }

// ── Merchandise products for this station ────────────────────────────────────
$merch_products = [];
try {
    // ── Unified merchandise catalog — same source as Pricing/Inventory modules ──
    $stmt = $pdo->prepare("
        SELECT ip.id                                                          AS product_id,
               ip.product_name,
               COALESCE(NULLIF(TRIM(ip.sku),''), CONCAT('P', LPAD(ip.id,4,'0'))) AS sku,
               COALESCE(NULLIF(TRIM(ip.category),''),'General')              AS category,
               COALESCE(NULLIF(TRIM(ip.size),''),'')                         AS size,
               COALESCE(si.price, ip.unit_price, 0)                          AS unit_price,
               COALESCE(si.stock_level, ip.stock_quantity, ip.stock, 0)      AS stock_level,
               COALESCE(NULLIF(TRIM(si.unit),''), NULLIF(TRIM(ip.size),''), 'pcs') AS unit
        FROM inventory_products ip
        LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
        WHERE LOWER(COALESCE(ip.category,'')) NOT IN ('fuel', 'fuel products')
          AND LOWER(COALESCE(ip.status,'active')) NOT IN ('archived','deleted','inactive')

        UNION

        SELECT p.id                                                           AS product_id,
               p.name                                                         AS product_name,
               COALESCE(NULLIF(TRIM(p.sku),''), CONCAT('P', LPAD(p.id,4,'0'))) AS sku,
               COALESCE(pc.name,'General')                                    AS category,
               COALESCE(NULLIF(p.unit,''),'')                                 AS size,
               COALESCE(si2.price, p.price, si2.cost, p.cost, 0)             AS unit_price,
               COALESCE(si2.stock_level, p.current_stock, 0)                 AS stock_level,
               COALESCE(NULLIF(p.unit,''), NULLIF(si2.unit,''), 'pcs')       AS unit
        FROM products p
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        LEFT JOIN station_inventory si2 ON si2.product_id = p.id AND si2.station_id = ?
        WHERE LOWER(COALESCE(pc.name,'')) NOT IN ('fuel','fuel products','services','service')
          AND LOWER(COALESCE(p.status,'active')) NOT IN ('deleted','archived')
          AND p.id NOT IN (SELECT id FROM inventory_products WHERE LOWER(COALESCE(status,'active')) NOT IN ('archived','deleted') AND LOWER(COALESCE(category,'')) NOT IN ('fuel','fuel products'))

        ORDER BY category, product_name
    ");
    $stmt->execute([$station_id, $station_id]);
    $merch_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $merch_products = []; }

// ── Customers for credit transactions & search ────────────────────────────────
$customers = [];
$customer_names = []; // For autocomplete - full names
try {
    $customerNameExpr = customer_display_name_expr($pdo, 'c');
    $customerFirstExpr = customer_first_name_expr($pdo, 'c');
    $customerLastExpr = customer_last_name_expr($pdo, 'c');
    $customerContactExpr = customer_contact_expr($pdo, 'c');
    $customerStatusExpr = customer_status_expr($pdo, 'c');
    $customerTypeExpr = customer_type_expr($pdo, 'c');
    $customerIdExpr = customer_id_expr($pdo, 'c');
    $customerPlateExpr = customer_vehicle_expr($pdo, 'vehicle_plate', 'c');
    $customerMakeExpr = customer_vehicle_expr($pdo, 'vehicle_make', 'c');
    $customerModelExpr = customer_vehicle_expr($pdo, 'vehicle_model', 'c');
    $customerVehicleTypeExpr = customer_vehicle_expr($pdo, 'vehicle_type', 'c');
    $customerCreditLimitExpr = customer_credit_limit_expr($pdo, 'c');
    $customerBalanceExpr = customer_balance_expr($pdo, 'c');
    $customerPointsExpr = customer_expr_col($pdo, 'c', 'points', '0');
    $customerIdNumberExpr = customer_expr_col($pdo, 'c', 'id_number', "''");
    $customerLoyaltyCardExpr = customer_expr_col($pdo, 'c', 'loyalty_card_no', "''");

    // Fetch customers with vehicle information for the search feature
    $stmt = $pdo->prepare("
        SELECT 
            c.id, 
            {$customerNameExpr} AS name,
            {$customerFirstExpr} AS first_name,
            {$customerLastExpr} AS last_name,
            {$customerContactExpr} AS contact_number,
            {$customerCreditLimitExpr} AS credit_limit,
            {$customerBalanceExpr} AS balance,
            {$customerPointsExpr} AS points,
            {$customerIdExpr} AS customer_id,
            {$customerIdNumberExpr} AS id_number,
            {$customerLoyaltyCardExpr} AS loyalty_card_no,
            {$customerVehicleTypeExpr} AS vehicle_type,
            {$customerMakeExpr} AS vehicle_brand,
            {$customerModelExpr} AS vehicle_model,
            {$customerPlateExpr} AS plate_number,
            {$customerTypeExpr} AS customer_type
        FROM customers c
        WHERE c.station_id = ? AND LOWER({$customerStatusExpr}) = 'active'
        ORDER BY {$customerNameExpr}
    ");
    $stmt->execute([$station_id]);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Extract unique names for autocomplete
    foreach ($customers as $c) {
        $full_name = trim($c['name'] ?? '');
        if ($full_name) {
            $customer_names[] = $full_name;
        }
    }
} catch (Exception $e) { 
    $customers = []; 
    $customer_names = [];
}

// ── Current shift ─────────────────────────────────────────────────────────────
$current_shift = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM labor_sessions WHERE user_id = ? AND end_time IS NULL ORDER BY start_time DESC LIMIT 1");
    $stmt->execute([$me['id']]);
    $current_shift = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) { $current_shift = null; }

// ── Data for Fuel Transaction Filters ───────────────────────────────────────
// Get staff names for filter
$staff_list = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, username FROM users WHERE station_id = ? AND role IN ('staff', 'cashier', 'pump_attendant', 'operations_staff') AND status = 'active' ORDER BY name");
    $stmt->execute([$station_id]);
    $staff_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $staff_list = []; }

// Get shift periods for filter
$shift_periods = [];
try {
    $stmt = $pdo->query("SELECT shift_key, shift_name FROM shift_periods WHERE is_active = 1 ORDER BY sort_order ASC");
    $shift_periods = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $shift_periods = []; }

// Get current filter values from GET parameters
$filter_date_from = $_GET['date_from'] ?? date('Y-m-01');
$filter_date_to = $_GET['date_to'] ?? date('Y-m-d');
$filter_fuel_type = $_GET['fuel_type'] ?? '';
$filter_staff_id = $_GET['staff_id'] ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_shift = $_GET['shift'] ?? '';

// ── FETCH VALIDATED BEGINNING READINGS FOR EACH PUMP (shift carry-over) ─────────────
// Rule:
//   Shift 2 (second) → fetch validated Ending from Shift 1 (first)
//   Shift 1 (first)  → fetch validated Ending from Shift 2 (second)
//   Status must be: verified / approved / adjusted / validated
$last_readings_by_pump = [];     // pump_label => validated ending reading value
$pump_missing_prev     = [];     // pump_label => true  (flag: no validated prev found)
try {
    // Determine which shift/day to look back at
    // We detect current shift from session (fuel_shift_key is populated after line 318)
    // Use a two-pass approach: run after shift detection (we'll re-run below after shift is known)
    // Store a deferred closure pattern — actually set a flag, then run after shift variables are set
    $__fetch_prev_readings = function() use ($pdo, $station_id) {
        global $last_readings_by_pump;

        try {
            // Fetch latest present_reading (ending meter) for each pump from COMPLETED/FINALIZED closings only
            $latest_stmt = $pdo->prepare("
                SELECT ft.id, COALESCE(fp.pump_number, ft.fuel_type) AS pump_label, ft.fuel_type, ft.present_reading
                FROM fuel_transactions ft
                LEFT JOIN fuel_pumps fp ON ft.pump_id = fp.id
                WHERE ft.station_id = ?
                  AND LOWER(COALESCE(ft.status, '')) IN ('closing_completed', 'completed', 'reported', 'approved', 'verified', 'adjusted', 'saved')
                ORDER BY ft.transaction_date DESC, ft.id DESC
            ");
            $latest_stmt->execute([$station_id]);
            $latest_rows = $latest_stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($latest_rows as $row) {
                $lbl = strtoupper(trim($row['pump_label'] ?? ''));
                $ft_name = strtoupper(trim($row['fuel_type'] ?? ''));
                if ($lbl !== '' && !isset($last_readings_by_pump[$lbl])) {
                    $last_readings_by_pump[$lbl] = (float)$row['present_reading'];
                }
                if ($ft_name !== '' && !isset($last_readings_by_pump[$ft_name])) {
                    $last_readings_by_pump[$ft_name] = (float)$row['present_reading'];
                }
            }
        } catch (Exception $e) {}
    };
} catch (Exception $e) {}

// ── Detect current shift period — use active labor session first (matches dashboard) ──
$merch_shift_key  = '';
$merch_shift_name = '';
$active_shift = []; // Array to hold full shift details including sort_order
try {
    // Priority 1: use the shift from the staff's active clock-in session (same as dashboard)
    $active_sess = $pdo->prepare(
        "SELECT ls.shift_period, ls.shift_name, sp.sort_order 
         FROM labor_sessions ls
         LEFT JOIN shift_periods sp ON ls.shift_period = sp.shift_key
         WHERE ls.user_id = ? AND ls.end_time IS NULL
         ORDER BY ls.start_time DESC LIMIT 1"
    );
    $active_sess->execute([$me['id']]);
    $active_row = $active_sess->fetch(PDO::FETCH_ASSOC);

    if ($active_row && !empty($active_row['shift_period'])) {
        $merch_shift_key  = $active_row['shift_period'];
        $merch_shift_name = $active_row['shift_name'] ?: '';
        $active_shift = [
            'shift_key' => $active_row['shift_period'],
            'shift_name' => $active_row['shift_name'],
            'shift_order' => (int)($active_row['sort_order'] ?? 1)
        ];
    } else {
        // Priority 2: fall back to time-based detection from DB
        $ct = date('H:i:s');
        $sp = $pdo->prepare("SELECT shift_key, shift_name, sort_order FROM shift_periods WHERE is_active = 1 AND start_time <= ? AND end_time >= ? ORDER BY sort_order ASC LIMIT 1");
        $sp->execute([$ct, $ct]);
        $sf = $sp->fetch(PDO::FETCH_ASSOC);
        if ($sf) {
            $merch_shift_key  = $sf['shift_key'];
            $merch_shift_name = $sf['shift_name'];
            $active_shift = [
                'shift_key' => $sf['shift_key'],
                'shift_name' => $sf['shift_name'],
                'shift_order' => (int)($sf['sort_order'] ?? 1)
            ];
        } else {
            // Priority 3: first active shift from DB
            $sp2 = $pdo->query("SELECT shift_key, shift_name, sort_order FROM shift_periods WHERE is_active = 1 ORDER BY sort_order ASC LIMIT 1");
            $sf2 = $sp2 ? $sp2->fetch(PDO::FETCH_ASSOC) : null;
            if ($sf2) { 
                $merch_shift_key = $sf2['shift_key']; 
                $merch_shift_name = $sf2['shift_name']; 
                $active_shift = [
                    'shift_key' => $sf2['shift_key'],
                    'shift_name' => $sf2['shift_name'],
                    'shift_order' => (int)($sf2['sort_order'] ?? 1)
                ];
            }
        }
    }
    // If still empty, last resort: any shift from DB
    if (empty($merch_shift_key)) {
        $sp3 = $pdo->query("SELECT shift_key, shift_name, sort_order FROM shift_periods ORDER BY sort_order ASC LIMIT 1");
        $sf3 = $sp3 ? $sp3->fetch(PDO::FETCH_ASSOC) : null;
        if ($sf3) { 
            $merch_shift_key = $sf3['shift_key']; 
            $merch_shift_name = $sf3['shift_name']; 
            $active_shift = [
                'shift_key' => $sf3['shift_key'],
                'shift_name' => $sf3['shift_name'],
                'shift_order' => (int)($sf3['sort_order'] ?? 1)
            ];
        }
    }
} catch (Exception $e) {}
// Fuel form uses the same shift
$fuel_shift_key  = $merch_shift_key;
$fuel_shift_name = $merch_shift_name;

// ── NOW run the deferred validated-beginning-reading fetch (needs $fuel_shift_key) ──
if (isset($__fetch_prev_readings) && is_callable($__fetch_prev_readings)) {
    $__fetch_prev_readings();
}

// ── Detect workflow state & pre-fetch saved readings for current date + shift ──────────
$today_date = date('Y-m-d');
$current_shift_status = 'DRAFT';
$today_saved_readings = [];

try {
    $stmt_cstat = $pdo->prepare("
        SELECT status FROM fuel_sales_closing
        WHERE station_id = ? AND report_date = ? AND (shift = ? OR shift_period = ?)
        ORDER BY id DESC LIMIT 1
    ");
    $stmt_cstat->execute([$station_id, $today_date, $fuel_shift_name, $fuel_shift_key]);
    $found_cstat = $stmt_cstat->fetchColumn();
    if ($found_cstat) {
        $current_shift_status = strtoupper(trim($found_cstat));
    }
} catch (Exception $e) {}

if ($current_shift_status === 'DRAFT' || empty($current_shift_status)) {
    try {
        $stmt_txstat = $pdo->prepare("
            SELECT status FROM fuel_transactions
            WHERE station_id = ? AND DATE(transaction_date) = ? AND shift_period = ?
              AND LOWER(COALESCE(status,'')) NOT IN ('rejected','voided','cancelled','canceled')
            ORDER BY id DESC LIMIT 1
        ");
        $stmt_txstat->execute([$station_id, $today_date, $fuel_shift_key]);
        $found_txstat = $stmt_txstat->fetchColumn();
        if ($found_txstat) {
            $current_shift_status = strtoupper(trim($found_txstat));
        }
    } catch (Exception $e) {}
}

try {
    $st_readings = $pdo->prepare("
        SELECT ft.id, COALESCE(NULLIF(fp.pump_number, ''), ft.fuel_type) AS pump_label, ft.fuel_type,
               ft.present_reading, ft.previous_reading, ft.calibration, ft.liters_sold, ft.total_amount, ft.status
        FROM fuel_transactions ft
        LEFT JOIN fuel_pumps fp ON ft.pump_id = fp.id
        WHERE ft.station_id = ?
          AND DATE(ft.transaction_date) = ?
          AND ft.shift_period = ?
          AND LOWER(COALESCE(ft.status,'')) NOT IN ('rejected','voided','cancelled','canceled')
    ");
    $st_readings->execute([$station_id, $today_date, $fuel_shift_key]);
    $saved_rows = $st_readings->fetchAll(PDO::FETCH_ASSOC);
    foreach ($saved_rows as $sr) {
        $lbl_u = strtoupper(trim($sr['pump_label'] ?? ''));
        $ft_u  = strtoupper(trim($sr['fuel_type'] ?? ''));
        if ($lbl_u !== '') $today_saved_readings[$lbl_u] = $sr;
        if ($ft_u !== '')  $today_saved_readings[$ft_u]  = $sr;
    }
} catch (Exception $e) {}

// Check if current shift has submitted readings awaiting closing input
$has_submitted_readings_unclosed = false;
if (!in_array($current_shift_status, ['CLOSING_COMPLETED', 'SAVED', 'REPORTED'])) {
    foreach ($today_saved_readings as $sr) {
        if ((float)($sr['present_reading'] ?? 0) > 0) {
            $has_submitted_readings_unclosed = true;
            break;
        }
    }
}

// ── STAFF DASHBOARD KPI CARDS DATA ────────────────────────────────────────────
$staff_kpi = [
    'orders_today' => 0,
    'merchandise_released' => 0,
    'total_amount' => 0.00,
    'completed_jobs' => 0
];

try {
    $today_start = date('Y-m-d 00:00:00');
    $today_end = date('Y-m-d 23:59:59');
    
    // Orders Today (merchandise transactions)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM merchandise_transactions WHERE staff_id = ? AND transaction_date BETWEEN ? AND ?");
    $stmt->execute([$me['id'], $today_start, $today_end]);
    $staff_kpi['orders_today'] = (int)$stmt->fetchColumn();
    
    // Merchandise Released (total quantity)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM merchandise_transactions WHERE staff_id = ? AND transaction_date BETWEEN ? AND ?");
    $stmt->execute([$me['id'], $today_start, $today_end]);
    $staff_kpi['merchandise_released'] = (int)$stmt->fetchColumn();
    
    // Total Amount Encoded
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM merchandise_transactions WHERE staff_id = ? AND transaction_date BETWEEN ? AND ?");
    $stmt->execute([$me['id'], $today_start, $today_end]);
    $staff_kpi['total_amount'] = (float)$stmt->fetchColumn();
    
    // Completed Job Orders
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE (user_id = ? OR created_by = ?) AND status = 'Completed' AND DATE(completed_at) = CURDATE()");
    $stmt->execute([$me['id'], $me['id']]);
    $staff_kpi['completed_jobs'] = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    error_log("Staff KPI error: " . $e->getMessage());
}

// ── Recent shift transactions (history tab) ───────────────────────────────────
$recent_fuel      = [];
$recent_merch       = [];
$available_shifts   = [];
$shift_log          = [];
$merch_total_count  = 0;
$filter_shift       = $_GET['shift'] ?? '';
$filter_date        = $_GET['date']  ?? '';
$hist_page          = max(1, (int)($_GET['page'] ?? 1));
$hist_per_page      = 50;
$hist_offset        = ($hist_page - 1) * $hist_per_page;
// ── History tab filters ───────────────────────────────────────────────────────
$hist_filter_date_from = $_GET['date_from']  ?? '';
$hist_filter_date_to   = $_GET['date_to']    ?? '';
$hist_filter_type      = $_GET['txn_type']   ?? '';   // job_order|merchandise|combined
$hist_filter_ctype     = $_GET['cust_type']  ?? '';   // registered only (walk-in removed)
$hist_filter_pay       = $_GET['payment']    ?? '';
$hist_filter_pstatus   = $_GET['pstatus']    ?? '';
$hist_filter_vstatus   = $_GET['vstatus']    ?? '';   // Completed|Adjusted|Voided
$hist_filter_shift     = $_GET['shift']      ?? '';
$hist_search           = trim($_GET['hsearch'] ?? '');
// KPI accumulators
$hist_kpi_total = 0; $hist_kpi_jo = 0; $hist_kpi_merch = 0; $hist_kpi_sales = 0.0; $hist_kpi_paid = 0; $hist_kpi_unpaid = 0;

// ── Merchandise section: Transaction History panel (right side) ───────────────
$mh_recent        = [];
$mh_total         = 0;
$mh_filter_type   = $_GET['mh_type'] ?? 'all';
$mh_filter_start_date = $_GET['mh_start_date'] ?? '';
$mh_filter_end_date   = $_GET['mh_end_date'] ?? '';
$mh_filter_category   = $_GET['mh_category'] ?? '';
$mh_filter_product    = $_GET['mh_product'] ?? '';
$mh_filter_status     = $_GET['mh_status'] ?? '';
$mh_page          = max(1, (int)($_GET['mh_page'] ?? 1));
$mh_per_page      = isset($_GET['mh_per_page']) && in_array((int)$_GET['mh_per_page'], [10,20,30,50]) ? (int)$_GET['mh_per_page'] : 10;
$mh_offset        = ($mh_page - 1) * $mh_per_page;
$mh_available_shifts     = [];
$mh_inv_impact           = [];
$mh_variance_alerts      = [];
$mh_kpi_txn_count        = 0;
$mh_kpi_items_released   = 0;
$mh_kpi_total_encoded    = 0.00;

// Pre-fetch pending transaction_requests for Merchandise History rows
        // ── AJAX JSON POLLING ENDPOINT FOR MERCHANDISE HISTORY ─────────────────
        if (isset($_GET['ajax_mh']) && $_GET['ajax_mh'] == '1') {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'kpis' => [
                    'txn_count'     => count($mh_recent),
                    'total_encoded' => '₱' . number_format($mh_kpi_total_encoded, 2)
                ],
                'mh_count' => count($mh_recent)
            ]);
            exit;
        }

        $mh_pending_requests = [];
try {
    $mhpr_stmt = $pdo->prepare(
        "SELECT * FROM transaction_requests WHERE station_id = ? AND record_source = 'merchandise_transactions' AND status = 'Pending'"
    );
    $mhpr_stmt->execute([$station_id]);
    foreach ($mhpr_stmt->fetchAll(PDO::FETCH_ASSOC) as $mhpr_row) {
        // Key by transaction_id (mt.id as string)
        $mh_pending_requests[(string)$mhpr_row['transaction_id']] = $mhpr_row;
    }
} catch (Exception $mhpr_err) {}


// DEBUG

if ($section === 'merchandise') {
    try {
        $mh_cols = [];
        try {
            foreach ($pdo->query("SHOW COLUMNS FROM merchandise_transactions")->fetchAll(PDO::FETCH_ASSOC) as $cr)
                $mh_cols[strtolower($cr['Field'])] = true;
        } catch (Exception $e) {}
        $mh_date_col   = isset($mh_cols['transaction_date']) ? 'mt.transaction_date' : 'mt.created_at';
        $mh_status_col = isset($mh_cols['validation_status']) ? 'mt.validation_status' : (isset($mh_cols['status']) ? 'mt.status' : "'Pending'");
        $mh_txnid_col  = isset($mh_cols['transaction_id'])   ? 'mt.transaction_id'   : 'mt.id';

        // ── Self-healing migration: backfill transaction_type for legacy records ──
        // Fixes legacy rows where transaction_type is NULL but job_order_service is set.
        // This ensures the Merchandise History filter never shows service-linked records.
        if (isset($mh_cols['transaction_type']) && isset($mh_cols['job_order_service'])) {
            try {
                // Fix job_order-only records (no merchandise items alongside the service)
                $pdo->prepare("
                    UPDATE merchandise_transactions mt
                    SET mt.transaction_type = IF(
                        (SELECT COUNT(*) FROM merchandise_transaction_items i
                         WHERE i.transaction_id = mt.id AND COALESCE(i.item_type,'merchandise') = 'merchandise') > 0,
                        'combined', 'job_order'
                    )
                    WHERE mt.station_id = ?
                      AND (mt.transaction_type IS NULL OR mt.transaction_type = '')
                      AND mt.job_order_service IS NOT NULL
                      AND TRIM(mt.job_order_service) <> ''
                ")->execute([$station_id]);
                // Fix remaining NULL rows (pure merchandise, no service)
                $pdo->prepare("
                    UPDATE merchandise_transactions
                    SET transaction_type = 'merchandise'
                    WHERE station_id = ?
                      AND (transaction_type IS NULL OR transaction_type = '')
                ")->execute([$station_id]);
            } catch (Exception $e) {
                error_log('staff_transactions_hub migration warning: ' . $e->getMessage());
            }
        }

        // Fetch categories for filters
        $mh_categories = [];
        try {
            $stmt_cat = $pdo->query("
                SELECT DISTINCT COALESCE(NULLIF(TRIM(category), ''), 'General') AS category 
                FROM inventory_products 
                WHERE category != 'Fuel' AND category IS NOT NULL AND TRIM(category) <> ''
                ORDER BY category
            ");
            if ($stmt_cat) {
                $mh_categories = $stmt_cat->fetchAll(PDO::FETCH_COLUMN);
            }
        } catch (Exception $e) {}

        // Build itemized filters
        $mh_where_clauses = ["mt.station_id = ?"];
        $mh_params = [$station_id];

        // Transaction Type Filter
        if ($mh_filter_type === 'merchandise') {
            $mh_where_clauses[] = "COALESCE(mt.transaction_type, 'merchandise') = 'merchandise'";
        } elseif ($mh_filter_type === 'combined') {
            $mh_where_clauses[] = "COALESCE(mt.transaction_type, 'merchandise') = 'combined'";
        } else {
            // Show both merchandise and combined transactions (excl. pure job_orders in this tab)
            $mh_where_clauses[] = "COALESCE(mt.transaction_type, 'merchandise') IN ('merchandise', 'combined')";
        }

        // Ensure we only show merchandise/product items (not service items)
        $mh_where_clauses[] = "COALESCE(mti.item_type, 'merchandise') = 'merchandise'";

        // Date Range Filters
        if ($mh_filter_start_date !== '') {
            $mh_where_clauses[] = "DATE($mh_date_col) >= ?";
            $mh_params[] = $mh_filter_start_date;
        }
        if ($mh_filter_end_date !== '') {
            $mh_where_clauses[] = "DATE($mh_date_col) <= ?";
            $mh_params[] = $mh_filter_end_date;
        }

        // Category Filter
        if ($mh_filter_category !== '') {
            $mh_where_clauses[] = "COALESCE(NULLIF(TRIM(mti.category), ''), 'General') = ?";
            $mh_params[] = $mh_filter_category;
        }

        // Product Filter
        if ($mh_filter_product !== '') {
            $mh_where_clauses[] = "mti.product_id = ?";
            $mh_params[] = (int)$mh_filter_product;
        }

        // Status Filter
        if ($mh_filter_status !== '') {
            $mh_where_clauses[] = "COALESCE(mt.validation_status, 'Pending') = ?";
            $mh_params[] = $mh_filter_status;
        }

        $mh_where = "WHERE " . implode(" AND ", $mh_where_clauses);

        // Get total count of matching items
        $cnt_stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM merchandise_transactions mt
            INNER JOIN merchandise_transaction_items mti ON mti.transaction_id = mt.id
            $mh_where
        ");
        $cnt_stmt->execute($mh_params);
        $mh_total = (int)$cnt_stmt->fetchColumn();

        // Fetch matching items (no LIMIT here because we do client-side pagination)
        $stmt_mh = $pdo->prepare("
            SELECT mt.id AS mt_id,
                   $mh_txnid_col AS transaction_id,
                   CONCAT('OR-', YEAR($mh_date_col), '-', LPAD(mt.id, 6, '0')) AS or_number,
                   mt.customer_name,
                   mt.payment_method,
                   COALESCE(mt.payment_status, 'Pending') AS payment_status,
                   COALESCE(mt.validation_status, 'Pending') AS validation_status,
                   $mh_date_col AS transaction_date,
                   mti.id AS item_id,
                   mti.product_id,
                   mti.product_name,
                   mti.category AS item_category,
                   mti.quantity,
                   mti.unit_price,
                   mti.subtotal AS item_total,
                   COALESCE(NULLIF(TRIM(si.unit),''), 'pc') AS unit
            FROM merchandise_transactions mt
            INNER JOIN merchandise_transaction_items mti ON mti.transaction_id = mt.id
            LEFT JOIN station_inventory si ON si.product_id = mti.product_id AND si.station_id = mt.station_id
            $mh_where
            ORDER BY $mh_date_col DESC
        ");
        $stmt_mh->execute($mh_params);
        $mh_recent = $stmt_mh->fetchAll(PDO::FETCH_ASSOC);

        // Fetch all unpaid transactions for variance checking
        $mh_variance_alerts = [];
        try {
            $stmt_var = $pdo->prepare("
                SELECT mt.id, $mh_txnid_col AS transaction_id, mt.total_amount, mt.payment_status
                FROM merchandise_transactions mt
                WHERE mt.station_id = ?
                  AND COALESCE(mt.payment_status, 'Pending Payment') != 'Paid'
            ");
            $stmt_var->execute([$station_id]);
            $unpaid_txns = $stmt_var->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($unpaid_txns)) {
                $unpaid_ids = array_column($unpaid_txns, 'id');
                $unpaid_iph = implode(',', array_fill(0, count($unpaid_ids), '?'));
                
                // Fetch product stocks for validation
                $mh_product_stock = [];
                $mh_ps_stmt = $pdo->prepare("
                    SELECT ip.id AS product_id, LOWER(TRIM(ip.product_name)) AS pname,
                           COALESCE(si.stock_level, 0) AS stock_level
                    FROM inventory_products ip
                    LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
                    WHERE ip.category != 'Fuel' OR ip.category IS NULL
                ");
                $mh_ps_stmt->execute([$station_id]);
                foreach ($mh_ps_stmt->fetchAll(PDO::FETCH_ASSOC) as $_mhps) {
                    $mh_product_stock[$_mhps['pname']] = $_mhps;
                }

                // Fetch items for these unpaid transactions
                $stmt_var_items = $pdo->prepare("
                    SELECT transaction_id, product_name, product_id, quantity, unit_price
                    FROM merchandise_transaction_items
                    WHERE transaction_id IN ($unpaid_iph)
                ");
                $stmt_var_items->execute($unpaid_ids);
                $var_items_map = [];
                foreach ($stmt_var_items->fetchAll(PDO::FETCH_ASSOC) as $_mhi) {
                    $var_items_map[$_mhi['transaction_id']][] = $_mhi;
                }
                
                foreach ($unpaid_txns as $_mvt) {
                    $mv_ref = $_mvt['transaction_id'] ?: ('#' . $_mvt['id']);
                    $mh_vitems = $var_items_map[$_mvt['id']] ?? [];
                    
                    // Quantity mismatch check
                    foreach ($mh_vitems as $_mvit) {
                        $pn = strtolower(trim($_mvit['product_name'] ?? ''));
                        $st = isset($mh_product_stock[$pn]) ? (float)$mh_product_stock[$pn]['stock_level'] : null;
                        if ($st !== null && $_mvit['quantity'] > $st) {
                            $mh_variance_alerts[] = [
                                'txn_ref' => $mv_ref,
                                'type'    => 'qty',
                                'message' => 'Quantity mismatch: ' . htmlspecialchars($_mvit['product_name'])
                                           . ' — encoded ' . (int)$_mvit['quantity'] . ' pc(s), stock ' . (int)$st,
                            ];
                        }
                    }
                    
                    // Amount mismatch check
                    if (!empty($mh_vitems)) {
                        $mv_sum   = array_sum(array_map(fn($i) => $i['quantity'] * $i['unit_price'], $mh_vitems));
                        $mv_total = (float)$_mvt['total_amount'];
                        if ($mv_total > 0 && abs($mv_sum - $mv_total) > 0.01) {
                            $mh_variance_alerts[] = [
                                'txn_ref' => $mv_ref,
                                'type'    => 'amount',
                                'message' => 'Amount mismatch: computed ₱' . number_format($mv_sum, 2)
                                           . ' vs encoded ₱' . number_format($mv_total, 2),
                            ];
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log('Variance checks error: ' . $e->getMessage());
        }

        // Merge merch variance alerts into global variance_alerts (for header badge)
        foreach ($mh_variance_alerts as $_mva) {
            $variance_alerts[] = [
                'jo_ref'  => $_mva['txn_ref'],
                'source'  => 'merchandise_transactions',
                'id'      => 0,
                'type'    => $_mva['type'],
                'message' => $_mva['message'],
            ];
        }
        $variance_alert_count = count($variance_alerts);

        // ── Merchandise KPI (today) ───────────────────────────────────────────
        $mh_kpi_txn_count     = 0;
        $mh_kpi_items_released = 0;
        $mh_kpi_total_encoded  = 0.00;
        try {
            $mhkpi = $pdo->prepare("
                SELECT COUNT(DISTINCT mt.id)   AS txn_count,
                       COALESCE(SUM(mti.quantity), 0) AS items_released,
                       COALESCE(SUM(mt.total_amount), 0) AS total_encoded
                FROM merchandise_transactions mt
                LEFT JOIN merchandise_transaction_items mti ON mti.transaction_id = mt.id
                WHERE mt.station_id = ?
                  AND mt.staff_id   = ?
                  AND DATE(mt.created_at) = CURDATE()
                  AND COALESCE(mt.transaction_type,'merchandise') = 'merchandise'
            ");
            $mhkpi->execute([$station_id, $me['id']]);
            $mhkpi_row = $mhkpi->fetch(PDO::FETCH_ASSOC);
            if ($mhkpi_row) {
                $mh_kpi_txn_count      = (int)$mhkpi_row['txn_count'];
                $mh_kpi_items_released = (int)$mhkpi_row['items_released'];
                $mh_kpi_total_encoded  = (float)$mhkpi_row['total_encoded'];
            }
        } catch (Exception $_mhkpie) {}

        $stmt_sh = $pdo->query("SELECT shift_key, shift_name FROM shift_periods WHERE is_active = 1 ORDER BY sort_order ASC");
        $mh_available_shifts = $stmt_sh ? $stmt_sh->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Exception $e) { 
        $mh_recent = []; 
        $mh_total = 0; 
        $mh_inv_impact = [];
        $mh_variance_alerts = [];
        $mh_kpi_txn_count = $mh_kpi_items_released = 0;
        $mh_kpi_total_encoded = 0.00;
        echo '<!-- MERCH ERROR: ' . htmlspecialchars($e->getMessage()) . ' -->';
    }
}

if ($section === 'history' || $section === 'fuel_history') {
    // Build fuel WHERE clause with optional shift/date filters
    if ($station_id > 0) {
        $fuel_where  = "WHERE ft.station_id = ?";
        $fuel_params = [$station_id];
    } else {
        $fuel_where  = "WHERE 1=1";
        $fuel_params = [];
    }

    if ($filter_shift !== '') {
        $fuel_where  .= " AND ft.shift_period = ?";
        $fuel_params[] = $filter_shift;
    }
    if ($filter_date !== '') {
        $fuel_where  .= " AND DATE(ft.transaction_date) = ?";
        $fuel_params[] = $filter_date;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT ft.id, ft.fuel_type, ft.liters_sold, ft.price_per_liter,
                   ROUND(ft.liters_sold * ft.price_per_liter, 2) AS total_amount,
                   ft.transaction_date, ft.status, ft.shift_period
            FROM fuel_transactions ft
            $fuel_where
            ORDER BY ft.transaction_date DESC
        ");
        $stmt->execute($fuel_params);
        $recent_fuel = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $recent_fuel = []; }

    try {
        // Detect columns
        $mt_cols = [];
        try {
            foreach ($pdo->query("SHOW COLUMNS FROM merchandise_transactions")->fetchAll(PDO::FETCH_ASSOC) as $cr)
                $mt_cols[strtolower($cr['Field'])] = true;
        } catch (Exception $e) {}
        $mt_date_col  = isset($mt_cols['transaction_date']) ? 'mt.transaction_date' : 'mt.created_at';
        $mt_txnid_col = isset($mt_cols['transaction_id'])   ? 'mt.transaction_id'   : 'mt.id';
        $mt_pstat_col = isset($mt_cols['payment_status'])   ? 'mt.payment_status'   : "'Pending'";
        $mt_type_col  = isset($mt_cols['transaction_type']) ? 'mt.transaction_type' : "'merchandise'";
        $mt_plate_col = "COALESCE(NULLIF(TRIM(mt.job_order_vehicle_plate),''), '—')";
        $mt_vtype_col = isset($mt_cols['job_order_vehicle_type'])  ? 'mt.job_order_vehicle_type'  : 'NULL';
        $mt_mech_col  = isset($mt_cols['job_order_mechanic_name']) ? 'mt.job_order_mechanic_name' : 'NULL';
        $mt_cont_col  = isset($mt_cols['job_order_contact'])       ? 'mt.job_order_contact'       : 'NULL';
        $mt_cid_col   = isset($mt_cols['credit_customer_id'])      ? 'mt.credit_customer_id'      : 'NULL';
        $mt_sub_col   = isset($mt_cols['subtotal_amount'])         ? 'mt.subtotal_amount'         : 'NULL';
        $mt_vat_col   = isset($mt_cols['vat_amount'])              ? 'mt.vat_amount'              : 'NULL';
        $mt_amtp_col  = isset($mt_cols['amount_paid'])             ? 'mt.amount_paid'             : 'NULL';
        $mt_bal_col   = isset($mt_cols['balance_due'])             ? 'mt.balance_due'             : 'NULL';
        $mt_valstat_col = isset($mt_cols['validation_status'])     ? 'mt.validation_status'       : "'Official'";

        $mt_shift_col   = isset($mt_cols['shift_period']) ? 'mt.shift_period' : (isset($mt_cols['shift_name']) ? 'mt.shift_name' : 'NULL');

        // Build WHERE — fetch transactions for staff station (station_id)
        if ($station_id > 0) {
            $merch_where2  = "WHERE mt.station_id = ?";
            $merch_params2 = [$station_id];
        } else {
            $merch_where2  = "WHERE 1=1";
            $merch_params2 = [];
        }

        if ($hist_filter_date_from !== '') {
            $merch_where2  .= " AND DATE($mt_date_col) >= ?";
            $merch_params2[] = $hist_filter_date_from;
        }
        if ($hist_filter_date_to !== '') {
            $merch_where2  .= " AND DATE($mt_date_col) <= ?";
            $merch_params2[] = $hist_filter_date_to;
        }
        if ($hist_filter_type !== '') {
            $merch_where2  .= " AND COALESCE($mt_type_col,'merchandise') = ?";
            $merch_params2[] = $hist_filter_type;
        }
        if ($mt_cid_col !== 'NULL') {
            // Only show registered customer transactions (walk-in option removed)
            if ($hist_filter_ctype === 'registered') {
                $merch_where2 .= " AND $mt_cid_col IS NOT NULL AND $mt_cid_col > 0";
            }
            elseif ($hist_filter_ctype === 'walkin') {
                $merch_where2 .= " AND ($mt_cid_col IS NULL OR $mt_cid_col = 0)";
            }
        }
        if ($hist_filter_pay !== '') {
            $merch_where2  .= " AND mt.payment_method = ?";
            $merch_params2[] = $hist_filter_pay;
        }
        if ($hist_filter_pstatus !== '') {
            $merch_where2  .= " AND LOWER($mt_pstat_col) = ?";
            $merch_params2[] = strtolower($hist_filter_pstatus);
        }
        if ($hist_filter_vstatus === 'Completed') {
            $merch_where2  .= " AND (COALESCE($mt_valstat_col, '') NOT IN ('Voided', 'Adjusted', 'Cancelled', 'Canceled'))";
        } elseif ($hist_filter_vstatus === 'Adjusted') {
            $merch_where2  .= " AND ($mt_valstat_col = 'Adjusted')";
        } elseif ($hist_filter_vstatus === 'Voided') {
            $merch_where2  .= " AND ($mt_valstat_col IN ('Voided', 'Cancelled', 'Canceled'))";
        }
        if ($hist_filter_shift !== '' && $mt_shift_col !== 'NULL') {
            $merch_where2  .= " AND COALESCE($mt_shift_col, '') = ?";
            $merch_params2[] = $hist_filter_shift;
        }
        if ($hist_search !== '') {
            $slike  = '%' . $hist_search . '%';
            $sllike = '%' . strtolower($hist_search) . '%';
            $merch_where2 .= " AND ($mt_txnid_col LIKE ? OR LOWER(COALESCE(mt.customer_name,'')) LIKE ? OR LOWER(COALESCE($mt_plate_col,'')) LIKE ? OR LOWER(COALESCE(mt.job_order_service,'')) LIKE ?)";
            $merch_params2[] = $slike; $merch_params2[] = $sllike; $merch_params2[] = $sllike; $merch_params2[] = $sllike;
        }

        // KPI aggregation
        $kpi_q = $pdo->prepare("
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN COALESCE($mt_type_col,'merchandise') IN ('job_order','combined') THEN 1 ELSE 0 END) AS jo_cnt,
                   SUM(CASE WHEN COALESCE($mt_type_col,'merchandise') = 'merchandise' THEN 1 ELSE 0 END) AS merch_cnt,
                   COALESCE(SUM(mt.total_amount),0) AS total_sales,
                   SUM(CASE WHEN LOWER($mt_pstat_col) = 'paid' THEN 1 ELSE 0 END) AS paid_cnt,
                   SUM(CASE WHEN LOWER($mt_pstat_col) IN ('pending','partially paid','partial payment','credit','credit account','credit transaction') THEN 1 ELSE 0 END) AS unpaid_cnt
            FROM merchandise_transactions mt $merch_where2
        ");
        $kpi_q->execute($merch_params2);
        $kpi_row = $kpi_q->fetch(PDO::FETCH_ASSOC);
        if ($kpi_row) {
            $hist_kpi_total  = (int)$kpi_row['total'];
            $hist_kpi_jo     = (int)$kpi_row['jo_cnt'];
            $hist_kpi_merch  = (int)$kpi_row['merch_cnt'];
            $hist_kpi_sales  = (float)$kpi_row['total_sales'];
            $hist_kpi_paid   = (int)$kpi_row['paid_cnt'];
            $hist_kpi_unpaid = (int)$kpi_row['unpaid_cnt'];
        }
        $merch_total_count = $hist_kpi_total;

        // Main query
        $stmt = $pdo->prepare("
            SELECT mt.id,
                   $mt_txnid_col  AS transaction_id,
                   mt.customer_name,
                   COALESCE($mt_cid_col, 0) AS credit_customer_id,
                   COALESCE($mt_type_col,'merchandise') AS transaction_type,
                   $mt_plate_col  AS vehicle_plate,
                   $mt_vtype_col  AS vehicle_type,
                   $mt_mech_col   AS mechanic_name,
                   $mt_cont_col   AS contact_number,
                   mt.total_amount,
                   $mt_sub_col    AS subtotal_amount,
                   $mt_vat_col    AS vat_amount,
                   mt.payment_method,
                   $mt_pstat_col  AS payment_status,
                   $mt_amtp_col   AS amount_paid,
                   $mt_bal_col    AS balance_due,
                   COALESCE(NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), u.username, 'Staff') AS encoder_name,
                   $mt_date_col   AS transaction_date,
                   mt.item_sku,
                   mt.quantity,
                   mt.unit_price,
                   $mt_valstat_col AS validation_status,
                   COALESCE(NULLIF(TRIM(mt.job_order_service),''),'') AS job_order_service,
                   COALESCE(
                       jo_mt.estimated_cost,
                       (SELECT NULLIF(SUM(subtotal),0) FROM merchandise_transaction_items WHERE transaction_id = mt.id AND (item_type = 'service' OR category LIKE '%Service%') AND category != 'Labor' AND product_name NOT LIKE '%Labor%'),
                       CASE WHEN (mt.job_order_service IS NOT NULL AND TRIM(mt.job_order_service) != '') OR COALESCE($mt_type_col,'merchandise') IN ('job_order', 'combined') THEN mt.total_amount ELSE 0 END,
                       0
                   ) AS service_fee,
                   COALESCE(
                       jo_mt.actual_labor_cost,
                       jo_mt.estimated_labor_cost,
                       (SELECT COALESCE(SUM(subtotal),0) FROM merchandise_transaction_items WHERE transaction_id = mt.id AND (category = 'Labor' OR product_name LIKE '%Labor%')),
                       0
                   ) AS labor_fee
            FROM merchandise_transactions mt
            LEFT JOIN users u ON u.id = mt.staff_id
            LEFT JOIN job_orders jo_mt ON jo_mt.id = mt.job_order_db_id
            $merch_where2
            ORDER BY $mt_date_col DESC
        ");
        $stmt->execute($merch_params2);
        $recent_merch = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $recent_merch = []; $merch_total_count = 0; }

    $shift_log = []; $available_shifts = [];

    // ── Staff Transaction History Exports ──────────────────────────────────────────
    $export_type = $_GET['export'] ?? '';
    if ($export_type === 'csv' && $section === 'history') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="Staff_Transactions_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Txn ID', 'Customer', 'Type', 'Vehicle Plate', 'Amount', 'Payment Method', 'Payment Status', 'Date', 'Validation Status']);
        foreach ($recent_merch as $r) {
            fputcsv($out, [
                $r['transaction_id'],
                $r['customer_name'] ?: 'No Customer',
                ucwords(str_replace('_', ' ', $r['transaction_type'])),
                $r['vehicle_plate'] ?: '—',
                number_format((float)$r['total_amount'], 2),
                $r['payment_method'],
                $r['payment_status'],
                date('M d, Y H:i', strtotime($r['transaction_date'])),
                $r['validation_status']
            ]);
        }
        fclose($out);
        exit;
    }
    if ($export_type === 'excel' && $section === 'history') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="Staff_Transactions_' . date('Y-m-d') . '.xls"');
        echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="UTF-8">';
        echo '<style>table{border-collapse:collapse}th,td{border:1px solid #ddd;padding:7px}th{background:#002F70;color:#fff;font-weight:700}</style>';
        echo '</head><body>';
        echo '<h2>Staff Transactions Report</h2>';
        echo '<p>Generated: ' . date('F d, Y h:i A') . ' | Records: ' . count($recent_merch) . '</p>';
        echo '<table><thead><tr>';
        foreach (['Txn ID', 'Customer', 'Type', 'Vehicle Plate', 'Amount', 'Payment Method', 'Payment Status', 'Date', 'Validation Status'] as $h) {
            echo '<th>' . htmlspecialchars($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        $total_amount = 0.0;
        foreach ($recent_merch as $r) {
            $total_amount += (float)$r['total_amount'];
            echo '<tr>';
            echo '<td>' . htmlspecialchars($r['transaction_id']) . '</td>';
            echo '<td>' . htmlspecialchars($r['customer_name'] ?: 'No Customer') . '</td>';
            echo '<td>' . htmlspecialchars(ucwords(str_replace('_', ' ', $r['transaction_type']))) . '</td>';
            echo '<td>' . htmlspecialchars($r['vehicle_plate'] ?: '—') . '</td>';
            echo '<td style="text-align:right">&#8369;' . number_format((float)$r['total_amount'], 2) . '</td>';
            echo '<td>' . htmlspecialchars($r['payment_method']) . '</td>';
            echo '<td>' . htmlspecialchars($r['payment_status']) . '</td>';
            echo '<td>' . date('M d, Y H:i', strtotime($r['transaction_date'])) . '</td>';
            echo '<td>' . htmlspecialchars($r['validation_status']) . '</td>';
            echo '</tr>';
        }
        echo '<tr style="font-weight:800;background:#f0f7ff">';
        echo '<td colspan="4" style="text-align:right"><strong>TOTAL</strong></td>';
        echo '<td style="text-align:right"><strong>&#8369;' . number_format($total_amount, 2) . '</strong></td>';
        echo '<td colspan="4"></td>';
        echo '</tr>';
        echo '</tbody></table></body></html>';
        exit;
    }
    if ($export_type === 'pdf' && $section === 'history') {
        header('Content-Type: text/html; charset=utf-8');
        $logo_url = '../assets/img/Petron%20Logo.png';
        $generated = date('F d, Y h:i A');
        $rec_count = count($recent_merch);
        $total_amount = array_sum(array_column($recent_merch, 'total_amount'));
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Staff Transactions Report</title>';
        echo '<style>';
        echo 'body{font-family:Arial,sans-serif;font-size:12px;margin:0;padding:0;background:#f1f5f9;color:#1e293b;}';
        echo '.action-bar{background:#002F70;padding:12px 24px;display:flex;align-items:center;justify-content:center;gap:12px;}';
        echo '.action-bar h2{color:#fff;font-size:15px;margin:0;}';
        echo '.btn-print{padding:9px 20px;background:#DC0032;color:#fff;border:none;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;}';
        echo '.btn-back{padding:9px 18px;background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.35);border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;}';
        echo '.report{background:#fff;max-width:1100px;margin:20px auto;border-radius:10px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.1);}';
        echo '.rpt-header{background:linear-gradient(135deg,#002F70 0%,#003d8a 100%);padding:22px 28px;display:flex;align-items:center;}';
        echo '.rpt-header img{height:45px;margin-right:15px;}';
        echo '.rpt-header-text h1{color:#fff;font-size:18px;margin:0 0 3px;}';
        echo '.rpt-header-text p{color:#93c5fd;font-size:11px;margin:0;}';
        echo '.rpt-header-meta{margin-left:auto;text-align:right;color:#bfdbfe;font-size:11px;}';
        echo '.rpt-body{padding:20px;}';
        echo 'table{width:100%;border-collapse:collapse;font-size:11px;}';
        echo 'th{background:#002F70;color:#fff;padding:9px 8px;font-weight:700;text-align:left;}';
        echo 'td{padding:8px;border-bottom:1px solid #e2e8f0;}';
        echo 'tr:nth-child(even) td{background:#f8fafc;}';
        echo '.amount{text-align:right;font-weight:700;color:#002F70;}';
        echo '.total-row td{background:#f0f7ff!important;font-weight:800;color:#002F70;border-top:2px solid #002F70;}';
        echo '@media print{.action-bar{display:none!important;}body{background:#fff;}.report{box-shadow:none;margin:0;}}';
        echo '</style></head><body>';
        echo '<div class="action-bar">';
        echo '  <h2>Staff Transactions Report</h2>';
        echo '  <button onclick="window.print()" class="btn-print">Print</button>';
        echo '  <button onclick="window.close()" class="btn-back">Close</button>';
        echo '</div>';
        echo '<div class="report">';
        echo '<div class="rpt-header">';
        echo '  <img src="' . $logo_url . '" alt="Petron">';
        echo '  <div class="rpt-header-text"><h1>Petron Station</h1><p>Staff Transaction History Report</p></div>';
        echo '  <div class="rpt-header-meta"><div>Encoder: ' . htmlspecialchars($me['name'] ?? $me['username']) . '</div><div>Generated: ' . $generated . '</div></div>';
        echo '</div>';
        echo '<div class="rpt-body"><table><thead><tr>';
        foreach (['Txn ID', 'Customer', 'Type', 'Vehicle Plate', 'Amount', 'Payment Method', 'Payment Status', 'Date', 'Validation Status'] as $h) {
            echo '<th>' . htmlspecialchars($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($recent_merch as $r) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($r['transaction_id']) . '</td>';
            echo '<td>' . htmlspecialchars($r['customer_name'] ?: 'No Customer') . '</td>';
            echo '<td>' . htmlspecialchars(ucwords(str_replace('_', ' ', $r['transaction_type']))) . '</td>';
            echo '<td>' . htmlspecialchars($r['vehicle_plate'] ?: '—') . '</td>';
            echo '<td class="amount">&#8369;' . number_format((float)$r['total_amount'], 2) . '</td>';
            echo '<td>' . htmlspecialchars($r['payment_method']) . '</td>';
            echo '<td>' . htmlspecialchars($r['payment_status']) . '</td>';
            echo '<td>' . date('M d, Y H:i', strtotime($r['transaction_date'])) . '</td>';
            echo '<td>' . htmlspecialchars($r['validation_status']) . '</td>';
            echo '</tr>';
        }
        echo '<tr class="total-row">';
        echo '<td colspan="4" style="text-align:right">TOTAL AMOUNT</td>';
        echo '<td class="amount">&#8369;' . number_format($total_amount, 2) . '</td>';
        echo '<td colspan="4"></td>';
        echo '</tr>';
        echo '</tbody></table></div></div></body></html>';
        exit;
    }

    if ($export_type === 'csv' && $section === 'fuel_history') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="Staff_Fuel_Transactions_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID', 'Fuel Type', 'Liters Sold', 'Price/Liter', 'Total Amount', 'Date', 'Status', 'Shift']);
        foreach ($recent_fuel as $r) {
            fputcsv($out, [
                $r['id'],
                $r['fuel_type'],
                number_format((float)$r['liters_sold'], 2),
                number_format((float)$r['price_per_liter'], 2),
                number_format((float)$r['total_amount'], 2),
                date('M d, Y H:i', strtotime($r['transaction_date'])),
                $r['status'],
                $r['shift_period']
            ]);
        }
        fclose($out);
        exit;
    }
    if ($export_type === 'excel' && $section === 'fuel_history') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="Staff_Fuel_Transactions_' . date('Y-m-d') . '.xls"');
        echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="UTF-8">';
        echo '<style>table{border-collapse:collapse}th,td{border:1px solid #ddd;padding:7px}th{background:#002F70;color:#fff;font-weight:700}</style>';
        echo '</head><body>';
        echo '<h2>Staff Fuel Transactions Report</h2>';
        echo '<p>Generated: ' . date('F d, Y h:i A') . ' | Records: ' . count($recent_fuel) . '</p>';
        echo '<table><thead><tr>';
        foreach (['ID', 'Fuel Type', 'Liters Sold', 'Price/Liter', 'Total Amount', 'Date', 'Status', 'Shift'] as $h) {
            echo '<th>' . htmlspecialchars($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        $total_liters = 0.0;
        $total_amount = 0.0;
        foreach ($recent_fuel as $r) {
            $total_liters += (float)$r['liters_sold'];
            $total_amount += (float)$r['total_amount'];
            echo '<tr>';
            echo '<td>' . htmlspecialchars($r['id']) . '</td>';
            echo '<td>' . htmlspecialchars($r['fuel_type']) . '</td>';
            echo '<td style="text-align:right">' . number_format((float)$r['liters_sold'], 2) . ' L</td>';
            echo '<td style="text-align:right">&#8369;' . number_format((float)$r['price_per_liter'], 2) . '</td>';
            echo '<td style="text-align:right">&#8369;' . number_format((float)$r['total_amount'], 2) . '</td>';
            echo '<td>' . date('M d, Y H:i', strtotime($r['transaction_date'])) . '</td>';
            echo '<td>' . htmlspecialchars($r['status']) . '</td>';
            echo '<td>' . htmlspecialchars($r['shift_period']) . '</td>';
            echo '</tr>';
        }
        echo '<tr style="font-weight:800;background:#f0f7ff">';
        echo '<td colspan="2" style="text-align:right"><strong>TOTAL</strong></td>';
        echo '<td style="text-align:right"><strong>' . number_format($total_liters, 2) . ' L</strong></td>';
        echo '<td></td>';
        echo '<td style="text-align:right"><strong>&#8369;' . number_format($total_amount, 2) . '</strong></td>';
        echo '<td colspan="3"></td>';
        echo '</tr>';
        echo '</tbody></table></body></html>';
        exit;
    }
    if ($export_type === 'pdf' && $section === 'fuel_history') {
        header('Content-Type: text/html; charset=utf-8');
        $logo_url = '../assets/img/Petron%20Logo.png';
        $generated = date('F d, Y h:i A');
        $rec_count = count($recent_fuel);
        $total_liters = array_sum(array_column($recent_fuel, 'liters_sold'));
        $total_amount = array_sum(array_column($recent_fuel, 'total_amount'));
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Staff Fuel Transactions Report</title>';
        echo '<style>';
        echo 'body{font-family:Arial,sans-serif;font-size:12px;margin:0;padding:0;background:#f1f5f9;color:#1e293b;}';
        echo '.action-bar{background:#002F70;padding:12px 24px;display:flex;align-items:center;justify-content:center;gap:12px;}';
        echo '.action-bar h2{color:#fff;font-size:15px;margin:0;}';
        echo '.btn-print{padding:9px 20px;background:#DC0032;color:#fff;border:none;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;}';
        echo '.btn-back{padding:9px 18px;background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.35);border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;}';
        echo '.report{background:#fff;max-width:1100px;margin:20px auto;border-radius:10px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.1);}';
        echo '.rpt-header{background:linear-gradient(135deg,#002F70 0%,#003d8a 100%);padding:22px 28px;display:flex;align-items:center;}';
        echo '.rpt-header img{height:45px;margin-right:15px;}';
        echo '.rpt-header-text h1{color:#fff;font-size:18px;margin:0 0 3px;}';
        echo '.rpt-header-text p{color:#93c5fd;font-size:11px;margin:0;}';
        echo '.rpt-header-meta{margin-left:auto;text-align:right;color:#bfdbfe;font-size:11px;}';
        echo '.rpt-body{padding:20px;}';
        echo 'table{width:100%;border-collapse:collapse;font-size:11px;}';
        echo 'th{background:#002F70;color:#fff;padding:9px 8px;font-weight:700;text-align:left;}';
        echo 'td{padding:8px;border-bottom:1px solid #e2e8f0;}';
        echo 'tr:nth-child(even) td{background:#f8fafc;}';
        echo '.amount{text-align:right;font-weight:700;color:#002F70;}';
        echo '.total-row td{background:#f0f7ff!important;font-weight:800;color:#002F70;border-top:2px solid #002F70;}';
        echo '@media print{.action-bar{display:none!important;}body{background:#fff;}.report{box-shadow:none;margin:0;}}';
        echo '</style></head><body>';
        echo '<div class="action-bar">';
        echo '  <h2>Staff Fuel Transactions Report</h2>';
        echo '  <button onclick="window.print()" class="btn-print">Print</button>';
        echo '  <button onclick="window.close()" class="btn-back">Close</button>';
        echo '</div>';
        echo '<div class="report">';
        echo '<div class="rpt-header">';
        echo '  <img src="' . $logo_url . '" alt="Petron">';
        echo '  <div class="rpt-header-text"><h1>Petron Station</h1><p>Staff Fuel Transaction History Report</p></div>';
        echo '  <div class="rpt-header-meta"><div>Encoder: ' . htmlspecialchars($me['name'] ?? $me['username']) . '</div><div>Generated: ' . $generated . '</div></div>';
        echo '</div>';
        echo '<div class="rpt-body"><table><thead><tr>';
        foreach (['ID', 'Fuel Type', 'Liters Sold', 'Price/Liter', 'Total Amount', 'Date', 'Status', 'Shift'] as $h) {
            echo '<th>' . htmlspecialchars($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($recent_fuel as $r) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($r['id']) . '</td>';
            echo '<td>' . htmlspecialchars($r['fuel_type']) . '</td>';
            echo '<td style="text-align:right">' . number_format((float)$r['liters_sold'], 2) . ' L</td>';
            echo '<td style="text-align:right">&#8369;' . number_format((float)$r['price_per_liter'], 2) . '</td>';
            echo '<td class="amount">&#8369;' . number_format((float)$r['total_amount'], 2) . '</td>';
            echo '<td>' . date('M d, Y H:i', strtotime($r['transaction_date'])) . '</td>';
            echo '<td>' . htmlspecialchars($r['status']) . '</td>';
            echo '<td>' . htmlspecialchars($r['shift_period']) . '</td>';
            echo '</tr>';
        }
        echo '<tr class="total-row">';
        echo '<td colspan="2" style="text-align:right">TOTALS</td>';
        echo '<td style="text-align:right">' . number_format($total_liters, 2) . ' L</td>';
        echo '<td></td>';
        echo '<td class="amount">&#8369;' . number_format($total_amount, 2) . '</td>';
        echo '<td colspan="3"></td>';
        echo '</tr>';
        echo '</tbody></table></div></div></body></html>';
        exit;
    }

}


// ── Pagination helper ─────────────────────────────────────────────────────────
function hist_page_url(int $page, string $shift, string $date): string {
    $params = ['section' => 'history', 'page' => $page];
    if ($shift !== '') $params['shift'] = $shift;
    if ($date  !== '') $params['date']  = $date;
    return 'staff_transactions_hub.php?' . http_build_query($params);
}

// ── Status badge helper ───────────────────────────────────────
function status_badge(string $status): string {
    $map = [
        // Validation statuses
        'pending validation'  => ['color' => '#d97706', 'label' => 'Pending Validation'],
        'pending'             => ['color' => '#d97706', 'label' => 'Pending Validation'],
        'verified'            => ['color' => '#16a34a', 'label' => 'Verified'],
        'approved'            => ['color' => '#16a34a', 'label' => 'Verified'],
        'rejected'            => ['color' => '#dc2626', 'label' => 'Rejected'],
        // Payment statuses
        'paid'                => ['color' => '#16a34a', 'label' => 'Paid'],
        'partial payment'     => ['color' => '#d97706', 'label' => 'Partial Payment'],
        'partially paid'      => ['color' => '#d97706', 'label' => 'Partially Paid'],
        'pending payment'     => ['color' => '#ea580c', 'label' => 'Pending Payment'],
        'pending'             => ['color' => '#ea580c', 'label' => 'Pending'],
        'credit transaction'  => ['color' => '#9333ea', 'label' => 'Credit Transaction'],
        'credit'              => ['color' => '#9333ea', 'label' => 'Credit Transaction'],
        'credit account'      => ['color' => '#9333ea', 'label' => 'Credit Account'],
        'unpaid'              => ['color' => '#dc2626', 'label' => 'Unpaid'],
        // Workflow statuses
        'in progress'         => ['color' => '#2563eb', 'label' => 'In Progress'],
        'completed'           => ['color' => '#16a34a', 'label' => 'Completed'],
    ];
    $key  = strtolower(trim($status));
    $cfg  = $map[$key] ?? ['color' => '#64748b', 'label' => htmlspecialchars($status)];
    return sprintf(
        '<span style="color:%s;font-size:12px;font-weight:600;white-space:nowrap;">%s</span>',
        $cfg['color'], $cfg['label']
    );
}

// ── Flash messages ────────────────────────────────────────────────────────────
$flash_success = $_SESSION['success'] ?? '';
$flash_error   = $_SESSION['error']   ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// ── Job Order Tracker data (only loaded when that section is active) ──────────
$job_orders        = [];
$jo_pending_count  = 0;
$jo_approved_count = 0;
$jo_rejected_count = 0;
// Enhancement data — safe defaults (populated inside merchandise section block below)
$inv_impact           = [];

$kpi_jo_count         = 0;
$kpi_merch_released   = 0;
$kpi_total_encoded    = 0.00;
if ($section === 'merchandise') {
    // Handle status-update POST from the tracker
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['jo_action'])) {
        // Auto-create audit log table if it doesn't exist yet
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS payment_audit_log (
                id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                record_id       INT UNSIGNED NOT NULL,
                record_source   VARCHAR(60)  NOT NULL DEFAULT 'job_orders',
                staff_id        INT UNSIGNED NOT NULL,
                station_id      INT UNSIGNED NOT NULL,
                amount_paid     DECIMAL(12,2) NOT NULL DEFAULT 0,
                payment_method  VARCHAR(60)  NOT NULL DEFAULT 'Cash',
                balance_due     DECIMAL(12,2) NOT NULL DEFAULT 0,
                payment_status  VARCHAR(60)  NOT NULL DEFAULT 'Pending Payment',
                remarks         TEXT,
                logged_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_record (record_id, record_source),
                INDEX idx_staff  (staff_id),
                INDEX idx_logged (logged_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}

        $jo_action   = $_POST['jo_action'];
        $jo_id       = (int)($_POST['jo_id'] ?? 0);
        // Whitelist jo_source to prevent spoofed table names
        $jo_src_raw  = $_POST['jo_source'] ?? 'job_orders';
        $jo_src      = in_array($jo_src_raw, ['job_orders','merchandise_transactions'])
                        ? $jo_src_raw : 'job_orders';
        $tracker_tab  = $_POST['tracker_tab'] ?? 'pending';
        $redirect_tab = $_POST['redirect_tab'] ?? 'tracker';

        if ($jo_id > 0) {
            try {
                // ── settle_payment: capture amount, compute balance, set status ──────
                if ($jo_action === 'settle_payment') {
                    $amount_now   = round((float)($_POST['settle_amount'] ?? 0), 2);
                    $pay_method   = trim($_POST['settle_method'] ?? 'Cash');
                    $remarks      = trim($_POST['settle_remarks'] ?? '');
                    $mark_complete = !empty($_POST['mark_complete_on_settle']); // also flip to Completed

                    if ($jo_src === 'merchandise_transactions') {
                        // Fetch current totals
                        $row = $pdo->prepare("SELECT total_amount, COALESCE(amount_paid,0) AS amount_paid, COALESCE(balance_due, total_amount) AS balance_due FROM merchandise_transactions WHERE id=? AND station_id=? LIMIT 1");
                        $row->execute([$jo_id, $station_id]);
                        $cur = $row->fetch(PDO::FETCH_ASSOC);
                        if ($cur) {
                            $total       = (float)$cur['total_amount'];
                            $prev_paid   = (float)$cur['amount_paid'];
                            $new_paid    = $prev_paid + $amount_now;
                            $new_balance = max(0, round($total - $new_paid, 2));
                            $new_status  = $new_balance <= 0.009 ? 'Paid' : 'Partially Paid';
                            $sets = "payment_status=?, amount_paid=?, balance_due=?, payment_method=?, updated_at=NOW()";
                            $params = [$new_status, $new_paid, $new_balance, $pay_method];
                            if ($mark_complete) { $sets .= ", workflow_status='Completed'"; }
                            $pdo->prepare("UPDATE merchandise_transactions SET $sets WHERE id=? AND station_id=?")->execute(array_merge($params, [$jo_id, $station_id]));
                            // Audit log
                            try { $pdo->prepare("INSERT INTO payment_audit_log (record_id, record_source, staff_id, station_id, amount_paid, payment_method, balance_due, payment_status, remarks, logged_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())")->execute([$jo_id,'merchandise_transactions',$me['id'],$station_id,$amount_now,$pay_method,$new_balance,$new_status,$remarks]); } catch(Exception $ae){}
                            $_SESSION['success'] = $new_status === 'Paid' ? 'Payment fully settled. Balance: ₱0.00.' : 'Partial payment recorded. Balance due: ₱' . number_format($new_balance, 2) . '.';
                        }
                    } else {
                        // job_orders table
                        $row = $pdo->prepare("SELECT COALESCE(total_cost,estimated_cost,0) AS total_amount, COALESCE(amount_paid,0) AS amount_paid, COALESCE(balance_due, COALESCE(total_cost,estimated_cost,0)) AS balance_due FROM job_orders WHERE id=? AND station_id=? LIMIT 1");
                        $row->execute([$jo_id, $station_id]);
                        $cur = $row->fetch(PDO::FETCH_ASSOC);
                        if ($cur) {
                            $total       = (float)$cur['total_amount'];
                            $prev_paid   = (float)$cur['amount_paid'];
                            $new_paid    = $prev_paid + $amount_now;
                            $new_balance = max(0, round($total - $new_paid, 2));
                            $new_status  = $new_balance <= 0.009 ? 'Paid' : 'Partially Paid';
                            $sets = "payment_status=?, amount_paid=?, balance_due=?, payment_method=?, updated_at=NOW()";
                            $params = [$new_status, $new_paid, $new_balance, $pay_method];
                            if ($mark_complete) { $sets .= ", status='Completed'"; }
                            $pdo->prepare("UPDATE job_orders SET $sets WHERE id=? AND station_id=?")->execute(array_merge($params, [$jo_id, $station_id]));
                            // Audit log
                            try { $pdo->prepare("INSERT INTO payment_audit_log (record_id, record_source, staff_id, station_id, amount_paid, payment_method, balance_due, payment_status, remarks, logged_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())")->execute([$jo_id,'job_orders',$me['id'],$station_id,$amount_now,$pay_method,$new_balance,$new_status,$remarks]); } catch(Exception $ae){}
                            $_SESSION['success'] = $new_status === 'Paid' ? 'Payment fully settled. Balance: ₱0.00.' : 'Partial payment recorded. Balance due: ₱' . number_format($new_balance, 2) . '.';
                        }
                    }
                    $redir_tab = ($redirect_tab === 'merchandise') ? 'merchandise' : 'tracker';
                    $redir_mh  = ($redirect_tab === 'merchandise') ? '&mh_open=1' : '';
                    header("Location: staff_transactions_hub.php?section=merchandise&active_tab={$redir_tab}{$redir_mh}");
                    exit;
                }

                if ($jo_src === 'merchandise_transactions') {
                    // Record lives in merchandise_transactions — use workflow_status column
                    if ($jo_action === 'set_in_progress') {
                        $pdo->prepare("UPDATE merchandise_transactions SET workflow_status='In Progress', updated_at=NOW() WHERE id=? AND station_id=?")->execute([$jo_id, $station_id]);
                        $_SESSION['success'] = 'Job Order marked as In Progress.';
                    } elseif ($jo_action === 'set_completed') {
                        $pdo->prepare("UPDATE merchandise_transactions SET workflow_status='Completed', updated_at=NOW() WHERE id=? AND station_id=?")->execute([$jo_id, $station_id]);
                        $_SESSION['success'] = 'Job Order marked as Completed.';
                    } elseif ($jo_action === 'release_job_order' || $jo_action === 'set_released') {
                        $pdo->prepare("UPDATE merchandise_transactions SET workflow_status='Released', updated_at=NOW() WHERE id=? AND station_id=?")->execute([$jo_id, $station_id]);
                        $_SESSION['success'] = 'Vehicle marked as Released to customer.';
                    } elseif ($jo_action === 'set_paid') {
                        $pdo->prepare("UPDATE merchandise_transactions SET payment_status='Paid', balance_due=0, updated_at=NOW() WHERE id=? AND station_id=?")->execute([$jo_id, $station_id]);
                        $_SESSION['success'] = 'Payment recorded as Paid.';
                    } elseif ($jo_action === 'set_due_date') {
                        // ── Receivables: set/update due date ──────────────────────────────────
                        $raw_due = trim($_POST['due_date'] ?? '');
                        $parsed  = DateTime::createFromFormat('Y-m-d', $raw_due);
                        if ($parsed && $parsed->format('Y-m-d') === $raw_due) {
                            $pdo->prepare("UPDATE merchandise_transactions SET due_date=?, updated_at=NOW() WHERE id=? AND station_id=?")
                                ->execute([$raw_due, $jo_id, $station_id]);
                            $_SESSION['success'] = 'Due date set to ' . $raw_due . '.';
                        } else {
                            $_SESSION['error'] = 'Invalid due date format. Use YYYY-MM-DD.';
                        }
                    } elseif ($jo_action === 'save_staff_remark') {
                        // ── Validation Notes: save staff remark ───────────────────────────────
                        if (in_array($role, ['staff','cashier','pump_attendant'])) {
                            $remark_text = trim($_POST['remark_text'] ?? '');
                            $pdo->prepare("UPDATE merchandise_transactions SET staff_remarks=?, updated_at=NOW() WHERE id=? AND station_id=?")
                                ->execute([$remark_text, $jo_id, $station_id]);
                            $_SESSION['success'] = 'Staff remark saved.';
                        }
                    } elseif ($jo_action === 'update_status') {
                        $allowed = ['Pending', 'Waiting for Parts', 'In Progress', 'Completed', 'Released', 'Rejected'];
                        $new_status = trim($_POST['new_status'] ?? '');
                        $rej_remarks = trim($_POST['rejection_remarks'] ?? '');
                        if (in_array($new_status, $allowed)) {
                            if ($new_status === 'Rejected' && $rej_remarks === '') {
                                $_SESSION['error'] = 'Rejection reason is required.';
                            } else {
                                $sets = "workflow_status=?, updated_at=NOW()";
                                $params = [$new_status];
                                if ($new_status === 'Rejected' && $rej_remarks !== '') {
                                    $sets .= ", staff_remarks=?";
                                    $params[] = 'Rejected: ' . $rej_remarks;
                                }
                                $pdo->prepare("UPDATE merchandise_transactions SET $sets WHERE id=? AND station_id=?")
                                    ->execute(array_merge($params, [$jo_id, $station_id]));
                                $_SESSION['success'] = 'Status updated to ' . htmlspecialchars($new_status) . '.';
                            }
                        } else {
                            $_SESSION['error'] = 'Invalid status selected.';
                        }
                    } elseif ($jo_action === 'adjust_job_order') {
                        $cust_name = trim($_POST['customer_name'] ?? '');
                        $veh_plate = strtoupper(trim($_POST['vehicle_plate'] ?? ''));
                        $veh_type  = trim($_POST['vehicle_type'] ?? '');
                        $svc_type  = trim($_POST['service_type'] ?? '');
                        $svc_desc  = trim($_POST['service_description'] ?? '');
                        $mech_name = trim($_POST['mechanic_name'] ?? '');
                        $est_cost  = round(max(0, (float)($_POST['estimated_cost'] ?? 0)), 2);
                        $est_duration = isset($_POST['estimated_duration']) && $_POST['estimated_duration'] !== '' ? (int)$_POST['estimated_duration'] : null;
                        if ($cust_name !== '' && $svc_type !== '') {
                            $pdo->prepare("
                                UPDATE merchandise_transactions
                                SET customer_name = ?,
                                    job_order_vehicle_plate = ?,
                                    job_order_vehicle_type = ?,
                                    job_order_service = ?,
                                    remarks = ?,
                                    job_order_mechanic_name = ?,
                                    total_amount = ?,
                                    job_order_estimated_duration = ?,
                                    updated_at = NOW()
                                WHERE id = ? AND station_id = ?
                            ")->execute([$cust_name, $veh_plate, $veh_type, $svc_type, $svc_desc, $mech_name, $est_cost, $est_duration, $jo_id, $station_id]);
                            $_SESSION['success'] = 'Job order details adjusted successfully.';
                        } else {
                            $_SESSION['error'] = 'Customer name and service type are required.';
                        }
                    }
                } else {
                    if ($jo_action === 'set_in_progress') {
                        $pdo->prepare("UPDATE job_orders SET status='In Progress', updated_at=NOW() WHERE id=? AND station_id=?")->execute([$jo_id, $station_id]);
                        $_SESSION['success'] = 'Job Order marked as In Progress.';
                    } elseif ($jo_action === 'set_completed') {
                        $pdo->prepare("UPDATE job_orders SET status='Completed', updated_at=NOW() WHERE id=? AND station_id=?")->execute([$jo_id, $station_id]);
                        $_SESSION['success'] = 'Job Order marked as Completed.';
                    } elseif ($jo_action === 'release_job_order' || $jo_action === 'set_released') {
                        $pdo->prepare("UPDATE job_orders SET status='Released', updated_at=NOW() WHERE id=? AND station_id=?")->execute([$jo_id, $station_id]);
                        $_SESSION['success'] = 'Vehicle marked as Released to customer.';
                    } elseif ($jo_action === 'set_paid') {
                        $pdo->prepare("UPDATE job_orders SET payment_status='Paid', balance_due=0, updated_at=NOW() WHERE id=? AND station_id=?")->execute([$jo_id, $station_id]);
                        $_SESSION['success'] = 'Payment recorded as Paid.';
                    } elseif ($jo_action === 'set_due_date') {
                        // ── Receivables: set/update due date ──────────────────────────────────
                        $raw_due = trim($_POST['due_date'] ?? '');
                        $parsed  = DateTime::createFromFormat('Y-m-d', $raw_due);
                        if ($parsed && $parsed->format('Y-m-d') === $raw_due) {
                            $pdo->prepare("UPDATE job_orders SET due_date=?, updated_at=NOW() WHERE id=? AND station_id=?")
                                ->execute([$raw_due, $jo_id, $station_id]);
                            $_SESSION['success'] = 'Due date set to ' . $raw_due . '.';
                        } else {
                            $_SESSION['error'] = 'Invalid due date format. Use YYYY-MM-DD.';
                        }
                    } elseif ($jo_action === 'save_staff_remark') {
                        // ── Validation Notes: save staff remark ───────────────────────────────
                        if (in_array($role, ['staff','cashier','pump_attendant'])) {
                            $remark_text = trim($_POST['remark_text'] ?? '');
                            $pdo->prepare("UPDATE job_orders SET notes=?, updated_at=NOW() WHERE id=? AND station_id=?")
                                ->execute([$remark_text, $jo_id, $station_id]);
                            $_SESSION['success'] = 'Staff remark saved.';
                        }
                    } elseif ($jo_action === 'update_status') {
                        $allowed = ['Pending', 'Waiting for Parts', 'In Progress', 'Completed', 'Released', 'Rejected'];
                        $new_status = trim($_POST['new_status'] ?? '');
                        $rej_remarks = trim($_POST['rejection_remarks'] ?? '');
                        if (in_array($new_status, $allowed)) {
                            if ($new_status === 'Rejected' && $rej_remarks === '') {
                                $_SESSION['error'] = 'Rejection reason is required.';
                            } else {
                                $sets = "status=?, updated_at=NOW()";
                                $params = [$new_status];
                                if ($new_status === 'Rejected' && $rej_remarks !== '') {
                                    $sets .= ", notes=?";
                                    $params[] = 'Rejected: ' . $rej_remarks;
                                }
                                $pdo->prepare("UPDATE job_orders SET $sets WHERE id=? AND station_id=?")
                                    ->execute(array_merge($params, [$jo_id, $station_id]));
                                $_SESSION['success'] = 'Status updated to ' . htmlspecialchars($new_status) . '.';
                            }
                        } else {
                            $_SESSION['error'] = 'Invalid status selected.';
                        }
                    } elseif ($jo_action === 'adjust_job_order') {
                        $cust_name = trim($_POST['customer_name'] ?? '');
                        $veh_plate = strtoupper(trim($_POST['vehicle_plate'] ?? ''));
                        $veh_type  = trim($_POST['vehicle_type'] ?? '');
                        $svc_type  = trim($_POST['service_type'] ?? '');
                        $svc_desc  = trim($_POST['service_description'] ?? '');
                        $mech_name = trim($_POST['mechanic_name'] ?? '');
                        $est_cost  = round(max(0, (float)($_POST['estimated_cost'] ?? 0)), 2);
                        $est_duration = isset($_POST['estimated_duration']) && $_POST['estimated_duration'] !== '' ? (int)$_POST['estimated_duration'] : null;
                        if ($cust_name !== '' && $svc_type !== '') {
                            $pdo->prepare("
                                UPDATE job_orders
                                SET customer_name = ?,
                                    vehicle_plate = ?,
                                    vehicle_type = ?,
                                    service_type = ?,
                                    description = ?,
                                    mechanic_name = ?,
                                    estimated_cost = ?,
                                    estimated_duration = ?,
                                    updated_at = NOW()
                                WHERE id = ? AND station_id = ?
                            ")->execute([$cust_name, $veh_plate, $veh_type, $svc_type, $svc_desc, $mech_name, $est_cost, $est_duration, $jo_id, $station_id]);
                            $_SESSION['success'] = 'Job order details adjusted successfully.';
                        } else {
                            $_SESSION['error'] = 'Customer name and service type are required.';
                        }
                    }
                }
            } catch (Exception $e) {
                $_SESSION['error'] = 'Error updating job order: ' . $e->getMessage();
            }
        }
        header('Location: staff_transactions_hub.php?section=merchandise&active_tab=tracker');
        exit;
    }

    try {
        // Part 1: native job_orders rows — fetch all columns including due_date, balance_due, admin_remarks
        $jo_rows = [];
        try {
            // Check which columns exist in job_orders (graceful for any schema state)
            $jo_cols_check = [];
            try {
                foreach ($pdo->query("SHOW COLUMNS FROM job_orders")->fetchAll(PDO::FETCH_ASSOC) as $_jc)
                    $jo_cols_check[strtolower($_jc['Field'])] = true;
            } catch (Exception $_jce) {}
            $jo_col_due_date    = isset($jo_cols_check['due_date'])    ? 'jo.due_date'    : 'NULL';
            $jo_col_balance_due = isset($jo_cols_check['balance_due']) ? 'jo.balance_due' : '0';

            $stmt = $pdo->prepare("
                SELECT jo.*,
                       COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),
                                u.username, 'Unassigned')                AS mechanic_name,
                       COALESCE(NULLIF(TRIM(CONCAT(COALESCE(cb.first_name,''),' ',COALESCE(cb.last_name,''))),''),
                                cb.username, 'Staff')                    AS created_by_name,
                       COALESCE(jo.engine_number, c_jo.engine_number, '')         AS engine_number,
                       COALESCE(jo.chassis_number, c_jo.chassis_number, '')       AS chassis_number,
                       'job_orders' AS _source,
                       'job_order'  AS transaction_type,
                       $jo_col_due_date    AS due_date,
                       $jo_col_balance_due AS balance_due_col
                FROM job_orders jo
                LEFT JOIN users u  ON u.id = jo.assigned_mechanic_id
                LEFT JOIN users cb ON cb.id = jo.created_by
                LEFT JOIN customers c_jo ON (
                    c_jo.station_id = jo.station_id AND (
                        (jo.customer_id IS NOT NULL AND c_jo.id = jo.customer_id)
                        OR (jo.vehicle_plate != '' AND REPLACE(LOWER(TRIM(c_jo.vehicle_plate)),'‑','-') = REPLACE(LOWER(TRIM(jo.vehicle_plate)),'‑','-'))
                        OR (jo.customer_name != '' AND LOWER(TRIM(c_jo.name)) = LOWER(TRIM(jo.customer_name)))
                        OR (jo.customer_name != '' AND LOWER(TRIM(CONCAT_WS(' ', c_jo.first_name, c_jo.last_name))) = LOWER(TRIM(jo.customer_name)))
                    )
                )
                WHERE jo.station_id = ?
                ORDER BY jo.created_at DESC
            ");
            $stmt->execute([$station_id]);
            $jo_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Normalize: map balance_due_col → balance_due if not already present
            foreach ($jo_rows as &$_jr) {
                if (!isset($_jr['balance_due']) || $_jr['balance_due'] == 0) {
                    $_jr['balance_due'] = $_jr['balance_due_col'] ?? 0;
                }
                $_jr['staff_remarks']      = $_jr['notes'] ?? '';
                $_jr['manager_notes']      = $_jr['admin_remarks'] ?? '';
                $_jr['inventory_deducted'] = 0; // job_orders uses required_parts JSON, handled below
            }
            unset($_jr);
        } catch (Exception $e) {
            error_log('staff_transactions_hub JO query error: ' . $e->getMessage());
            $jo_rows = [];
        }

        // Part 2: merchandise_transactions with job_order/combined type
        // Fetch new columns: staff_remarks, manager_notes, due_date, inventory_deducted
        $mt_rows = [];
        try {
            // Detect which new columns exist (graceful — migration runs above but just in case)
            $mt_tracker_cols = [];
            try {
                foreach ($pdo->query("SHOW COLUMNS FROM merchandise_transactions")->fetchAll(PDO::FETCH_ASSOC) as $_c)
                    $mt_tracker_cols[strtolower($_c['Field'])] = true;
            } catch (Exception $_e) {}
            $mt_col_staff_remarks      = isset($mt_tracker_cols['staff_remarks'])      ? 'mt.staff_remarks'      : 'NULL';
            $mt_col_manager_notes      = isset($mt_tracker_cols['manager_notes'])      ? 'mt.manager_notes'      : 'NULL';
            $mt_col_due_date           = isset($mt_tracker_cols['due_date'])           ? 'mt.due_date'           : 'NULL';
            $mt_col_inventory_deducted = isset($mt_tracker_cols['inventory_deducted']) ? 'mt.inventory_deducted' : '0';
            $mt_col_est_duration       = isset($mt_tracker_cols['job_order_estimated_duration']) ? 'mt.job_order_estimated_duration' : 'NULL';
            $mt_col_jo_veh_brand       = isset($mt_tracker_cols['job_order_vehicle_brand'])  ? 'mt.job_order_vehicle_brand'  : "''";
            $mt_col_jo_veh_model       = isset($mt_tracker_cols['job_order_vehicle_model'])  ? 'mt.job_order_vehicle_model'  : "''";
            $mt_col_jo_year_model      = isset($mt_tracker_cols['job_order_year_model'])      ? 'mt.job_order_year_model'      : "''";
            $mt_col_jo_engine          = isset($mt_tracker_cols['job_order_engine_number'])   ? 'mt.job_order_engine_number'   : "''";
            $mt_col_jo_chassis         = isset($mt_tracker_cols['job_order_chassis_number'])  ? 'mt.job_order_chassis_number'  : "''";

            $stmt2 = $pdo->prepare("
                SELECT
                    mt.id,
                    mt.customer_name,
                    mt.transaction_type,
                    COALESCE($mt_col_est_duration, jo_ref.estimated_duration, 0) AS estimated_duration,
                    COALESCE(
                        NULLIF(TRIM(mt.job_order_service), ''),
                        jo_ref.service_type,
                        (SELECT GROUP_CONCAT(product_name SEPARATOR ', ') FROM merchandise_transaction_items WHERE transaction_id = mt.id AND (item_type = 'service' OR category LIKE '%Service%') AND category != 'Labor' AND product_name NOT LIKE '%Labor%'),
                        'Service'
                    ) AS service_type,
                    COALESCE(jo_ref.service_description, '') AS service_description,
                    COALESCE(mt.workflow_status, mt.validation_status, 'Pending') AS status,
                    COALESCE(mt.validation_status, 'Pending') AS validation_status,
                    COALESCE(
                        (SELECT NULLIF(SUM(subtotal),0) FROM merchandise_transaction_items WHERE transaction_id = mt.id AND (item_type = 'service' OR category LIKE '%Service%') AND category != 'Labor' AND product_name NOT LIKE '%Labor%'),
                        mt.total_amount
                    ) AS service_fee,
                    COALESCE(
                        (SELECT NULLIF(SUM(subtotal),0) FROM merchandise_transaction_items WHERE transaction_id = mt.id AND (item_type = 'service' OR category LIKE '%Service%') AND category != 'Labor' AND product_name NOT LIKE '%Labor%'),
                        mt.total_amount
                    ) AS estimated_cost,
                    (SELECT COALESCE(SUM(subtotal),0) FROM merchandise_transaction_items WHERE transaction_id = mt.id AND (category = 'Labor' OR product_name LIKE '%Labor%')) AS actual_labor_cost,
                    (SELECT COALESCE(SUM(subtotal),0) FROM merchandise_transaction_items WHERE transaction_id = mt.id AND (category = 'Labor' OR product_name LIKE '%Labor%')) AS estimated_labor_cost,
                    mt.total_amount AS total_cost,
                    COALESCE($mt_col_staff_remarks, mt.remarks, '') AS notes,
                    COALESCE(NULLIF(TRIM(mt.job_order_vehicle_plate),''), jo_ref.vehicle_plate, c.vehicle_plate, '') AS vehicle_plate,
                    COALESCE(NULLIF(TRIM(mt.job_order_vehicle_type),''), jo_ref.vehicle_type, c.vehicle_type, '') AS vehicle_type,
                    COALESCE(NULLIF(TRIM(mt.job_order_contact),''), c.contact_number, c.phone, '') AS contact_number,
                    COALESCE(NULLIF(TRIM($mt_col_jo_veh_brand),''), cv.brand, c.vehicle_make, c.vehicle_brand, '') AS vehicle_brand,
                    COALESCE(NULLIF(TRIM($mt_col_jo_veh_model),''), cv.model, c.vehicle_model, '') AS vehicle_model,
                    COALESCE(NULLIF(TRIM($mt_col_jo_year_model),''), cv.year_model, '')              AS year_model,
                    COALESCE(NULLIF(TRIM($mt_col_jo_engine),''), cv.engine_no, jo_ref.engine_number, c.engine_number, '') AS engine_number,
                    COALESCE(NULLIF(TRIM($mt_col_jo_chassis),''), cv.chassis_no, jo_ref.chassis_number, c.chassis_number, '') AS chassis_number,
                    '' AS or_number,
                    mt.created_at,
                    COALESCE(NULLIF(TRIM(mt.job_order_mechanic_name),''), COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u_mech.first_name,''),' ',COALESCE(u_mech.last_name,''))),''), u_mech.username, ''), '') AS mechanic_name,
                    COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),
                             u.username, CONCAT('User #', mt.staff_id)) AS created_by_name,
                    mt.payment_method,
                    COALESCE(mt.payment_status, 'Pending Payment') AS payment_status,
                    COALESCE(mt.amount_paid, 0)                    AS amount_paid,
                    COALESCE(mt.balance_due, mt.total_amount)      AS balance_due,
                    NULL AS assigned_mechanic_id,
                    mt.credit_customer_id AS customer_id,
                    mt.id AS job_order_id,
                    COALESCE(jo_ref.job_order_id, NULL) AS job_order_number,
                    COALESCE(
                        NULLIF(
                            (SELECT GROUP_CONCAT(
                                        CONCAT(i.product_name, ' (', CAST(i.quantity AS UNSIGNED),
                                               IF(CAST(i.quantity AS UNSIGNED)=1,' pc',' pcs'), ')')
                                        ORDER BY i.id SEPARATOR ', ')
                             FROM merchandise_transaction_items i
                             WHERE i.transaction_id = mt.id
                               AND COALESCE(i.item_type, 'merchandise') != 'service'), ''
                        ),
                        NULL
                    ) AS required_parts,
                    NULL AS additional_notes,
                    NULL AS shift_id,
                    mt.updated_at,
                    'merchandise_transactions' AS _source,
                    COALESCE($mt_col_staff_remarks, mt.remarks, '')  AS staff_remarks,
                    COALESCE($mt_col_manager_notes, '')               AS manager_notes,
                    $mt_col_due_date                                  AS due_date,
                    $mt_col_inventory_deducted                        AS inventory_deducted
                FROM merchandise_transactions mt
                LEFT JOIN users u ON u.id = mt.staff_id
                LEFT JOIN job_orders jo_ref ON jo_ref.id = mt.job_order_db_id
                LEFT JOIN customers c ON (
                    c.station_id = mt.station_id AND (
                        (mt.credit_customer_id IS NOT NULL AND c.id = mt.credit_customer_id)
                        OR (mt.customer_id IS NOT NULL AND c.id = mt.customer_id)
                        OR (mt.job_order_vehicle_plate != '' AND REPLACE(LOWER(TRIM(c.vehicle_plate)),'‑','-') = REPLACE(LOWER(TRIM(mt.job_order_vehicle_plate)),'‑','-'))
                        OR (mt.customer_name != '' AND LOWER(TRIM(c.name)) = LOWER(TRIM(mt.customer_name)))
                        OR (mt.customer_name != '' AND LOWER(TRIM(CONCAT_WS(' ', c.first_name, c.last_name))) = LOWER(TRIM(mt.customer_name)))
                    )
                )
                LEFT JOIN customer_vehicles cv ON (
                    (c.id IS NOT NULL AND cv.customer_id = c.id)
                    OR (mt.job_order_vehicle_plate != '' AND REPLACE(LOWER(TRIM(cv.plate_number)),'‑','-') = REPLACE(LOWER(TRIM(mt.job_order_vehicle_plate)),'‑','-'))
                )
                LEFT JOIN users u_mech ON u_mech.id = jo_ref.assigned_mechanic_id
                WHERE mt.station_id = ?
                  AND mt.transaction_type IN ('job_order', 'combined')
                GROUP BY mt.id
                ORDER BY mt.created_at DESC
            ");
            $stmt2->execute([$station_id]);
            $mt_rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('staff_transactions_hub MT tracker query error: ' . $e->getMessage());
            $mt_rows = [];
        }

        // Build a set of native job_orders IDs so we can suppress duplicate mt rows
        $native_jo_ids = array_column($jo_rows, 'id');
        $native_jo_ids = array_flip(array_map('intval', $native_jo_ids));

        // Remove any merchandise_transactions rows that are already represented by a
        // native job_orders row (linked via job_order_db_id).  This prevents double
        // entries in the tracker when a JO was encoded through the POS (which creates
        // both a job_orders record and a merchandise_transactions record).
        $mt_rows_filtered = array_values(array_filter($mt_rows, function($r) use ($native_jo_ids) {
            // Each mt row stores the linked job_orders PK in the 'job_order_db_id' column,
            // which was aliased to job_order_id by the SELECT (or left NULL when absent).
            // We check both field names for safety.
            $linked_id = (int)($r['job_order_db_id'] ?? $r['job_order_number'] ?? 0);
            if ($linked_id > 0 && isset($native_jo_ids[$linked_id])) {
                return false; // suppress — the native jo_row covers this JO
            }
            return true;
        }));

        // Merge and sort by created_at DESC
        $job_orders = array_merge($jo_rows, $mt_rows_filtered);
        usort($job_orders, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));

        // ── Pre-fetch pending transaction_requests for Job Order Tracker ────────
                // ── AJAX JSON POLLING ENDPOINT FOR JOB ORDER TRACKER ────────────────────
        if (isset($_GET['ajax_tracker']) && $_GET['ajax_tracker'] == '1') {
            header('Content-Type: application/json');
            $completed_jo_count = (int)array_reduce($job_orders, fn($c,$j) => $c + (($j['status']??'')==='Completed'?1:0), 0);
            echo json_encode([
                'success' => true,
                'kpis' => [
                    'total_txns'   => (int)($mh_kpi_txn_count + $kpi_jo_count),
                    'total_sales'  => '₱' . number_format($kpi_total_encoded, 2),
                    'completed_jo' => $completed_jo_count,
                    'merch_sold'   => (int)$kpi_merch_released
                ],
                'jo_count' => count($job_orders)
            ]);
            exit;
        }

        $pending_requests = [];
        try {
            $pr_stmt = $pdo->prepare("SELECT * FROM transaction_requests WHERE station_id = ? AND status = 'Pending'");
            $pr_stmt->execute([$station_id]);
            foreach ($pr_stmt->fetchAll(PDO::FETCH_ASSOC) as $pr) {
                $pr_key = ($pr['record_source'] ?? 'job_orders') . ':' . $pr['transaction_id'];
                $pending_requests[$pr_key] = $pr;
            }
        } catch (Exception $pr_err) {}

        // ── Build inventory impact lookup (per JO, per part) ─────────────────────
        // Keyed by "{_source}:{id}" → array of ['part','qty','stock','status']
        $inv_impact = [];
        if (!empty($job_orders)) {
            // Collect all product names used across all JOs (for batch station_inventory lookup)
            $product_name_map = []; // product_name (lower) → ['product_id', 'stock_level']
            try {
                $pi_stmt = $pdo->prepare("
                    SELECT ip.id AS product_id, LOWER(TRIM(ip.product_name)) AS pname,
                           COALESCE(si.stock_level, 0) AS stock_level
                    FROM inventory_products ip
                    LEFT JOIN station_inventory si
                           ON si.product_id = ip.id AND si.station_id = ?
                    WHERE ip.category != 'Fuel' OR ip.category IS NULL
                ");
                $pi_stmt->execute([$station_id]);
                foreach ($pi_stmt->fetchAll(PDO::FETCH_ASSOC) as $_pi) {
                    $product_name_map[$_pi['pname']] = $_pi;
                }
            } catch (Exception $_pie) {}

            // For merchandise_transactions rows: get per-item data from merchandise_transaction_items
            $mt_item_map = []; // transaction_id → [['product_name','product_id','quantity','unit_price']]
            $mt_ids = array_column(
                array_filter($job_orders, fn($j) => ($j['_source'] ?? '') === 'merchandise_transactions'),
                'id'
            );
            if (!empty($mt_ids)) {
                try {
                    $mt_ph = implode(',', array_fill(0, count($mt_ids), '?'));
                    $mti_stmt = $pdo->prepare("
                        SELECT mti.transaction_id, mti.product_name, mti.product_id,
                               mti.quantity, mti.unit_price,
                               COALESCE(mti.item_type, 'merchandise') AS item_type
                        FROM merchandise_transaction_items mti
                        WHERE mti.transaction_id IN ($mt_ph)
                          AND COALESCE(mti.item_type, 'merchandise') != 'service'
                        ORDER BY mti.id
                    ");
                    $mti_stmt->execute($mt_ids);
                    foreach ($mti_stmt->fetchAll(PDO::FETCH_ASSOC) as $_mti) {
                        $mt_item_map[$_mti['transaction_id']][] = $_mti;
                    }
                } catch (Exception $_mtie) {}
            }

            foreach ($job_orders as $_jo) {
                $key        = ($_jo['_source'] ?? 'job_orders') . ':' . $_jo['id'];
                $val_st     = strtolower($_jo['validation_status'] ?? '');
                $is_approved = in_array($val_st, ['approved', 'validated', 'adjusted']);
                $parts_info = [];

                if (($_jo['_source'] ?? '') === 'merchandise_transactions') {
                    // Use merchandise_transaction_items data
                    $items = $mt_item_map[$_jo['id']] ?? [];
                    foreach ($items as $_item) {
                        $pname = strtolower(trim($_item['product_name'] ?? ''));
                        $stock_row = $product_name_map[$pname] ?? null;
                        $stock = $stock_row ? (float)$stock_row['stock_level'] : null;
                        $deducted = (int)($_jo['inventory_deducted'] ?? 0);

                        if ($is_approved && $deducted) {
                            $status = 'yes';
                        } elseif ($is_approved && !$deducted) {
                            $status = 'no';
                        } elseif ($stock === null) {
                            $status = 'na';
                        } else {
                            $status = 'pending';
                        }
                        $parts_info[] = [
                            'part'   => $_item['product_name'],
                            'qty'    => (int)$_item['quantity'],
                            'stock'  => $stock,
                            'unit_price' => (float)$_item['unit_price'],
                            'status' => $status,
                        ];
                    }
                } else {
                    // job_orders — parse required_parts JSON
                    $rp_raw = $_jo['required_parts'] ?? '';
                    $rp_decoded = is_string($rp_raw) ? (json_decode($rp_raw, true) ?? []) : ($rp_raw ?? []);
                    if (is_array($rp_decoded)) {
                        foreach ($rp_decoded as $_rp) {
                            if (!is_array($_rp)) continue;
                            $pname = strtolower(trim($_rp['name'] ?? $_rp['part_name'] ?? ''));
                            $stock_row = $product_name_map[$pname] ?? null;
                            $stock = $stock_row ? (float)$stock_row['stock_level'] : null;
                            // job_orders doesn't have inventory_deducted col — derive from validation
                            $status = $is_approved ? 'yes' : ($stock === null ? 'na' : 'pending');
                            $parts_info[] = [
                                'part'   => $_rp['name'] ?? $_rp['part_name'] ?? '?',
                                'qty'    => (int)($_rp['qty'] ?? $_rp['quantity'] ?? 1),
                                'stock'  => $stock,
                                'unit_price' => (float)($_rp['unit_price'] ?? 0),
                                'status' => $status,
                            ];
                        }
                    }
                }
                $inv_impact[$key] = $parts_info;
            }
        }

        // ── Variance alerts detection ─────────────────────────────────────────────
        // Scans active (non-rejected/completed) job orders for qty or amount mismatches.

        $today_str = date('Y-m-d');
        foreach ($job_orders as $_vjo) {
            $vst = strtolower($_vjo['status'] ?? '');
            if (in_array($vst, ['rejected','cancelled','completed'])) continue;
            $vval = strtolower($_vjo['validation_status'] ?? '');
            if ($vval === 'rejected') continue;

            $vkey = ($_vjo['_source'] ?? 'job_orders') . ':' . $_vjo['id'];
            $vitems = $inv_impact[$vkey] ?? [];
            $jo_ref = $_vjo['job_order_id'] ?? ('#' . $_vjo['id']);

            // A) Quantity variance: encoded qty > current stock level
            foreach ($vitems as $_vit) {
                if ($_vit['stock'] !== null && $_vit['qty'] > $_vit['stock']) {
                    $variance_alerts[] = [
                        'jo_ref'  => $jo_ref,
                        'source'  => $_vjo['_source'] ?? 'job_orders',
                        'id'      => $_vjo['id'],
                        'type'    => 'qty',
                        'message' => 'Quantity mismatch: ' . htmlspecialchars($_vit['part'])
                                   . ' — encoded ' . $_vit['qty'] . ' pc(s), stock ' . (int)$_vit['stock'],
                    ];
                }
            }

            // B) Amount variance: sum(qty × unit_price) vs total_cost
            if (!empty($vitems)) {
                $computed_sum = array_sum(array_map(fn($i) => $i['qty'] * $i['unit_price'], $vitems));
                $total_cost   = (float)($_vjo['total_cost'] ?? $_vjo['estimated_cost'] ?? 0);
                if ($total_cost > 0 && abs($computed_sum - $total_cost) > 0.01) {
                    $variance_alerts[] = [
                        'jo_ref'  => $jo_ref,
                        'source'  => $_vjo['_source'] ?? 'job_orders',
                        'id'      => $_vjo['id'],
                        'type'    => 'amount',
                        'message' => 'Amount mismatch: computed ₱' . number_format($computed_sum, 2)
                                   . ' vs encoded ₱' . number_format($total_cost, 2),
                    ];
                }
            }
        }
        $variance_alert_count = count($variance_alerts);

        // ── Staff KPI Snapshot (today, current station, current user) ─────────────
        $kpi_jo_count       = 0;
        $kpi_merch_released = 0;
        $kpi_total_encoded  = 0.00;
        try {
            // Count JOs encoded today from merchandise_transactions
            $kpi_stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT mt.id)   AS jo_count,
                       COALESCE(SUM(
                           CASE WHEN COALESCE(mti.item_type,'merchandise') != 'service'
                                THEN mti.quantity ELSE 0 END
                       ), 0)                   AS merch_released,
                       COALESCE(SUM(mt.total_amount), 0) AS total_encoded
                FROM merchandise_transactions mt
                LEFT JOIN merchandise_transaction_items mti ON mti.transaction_id = mt.id
                WHERE mt.station_id = ?
                  AND mt.staff_id   = ?
                  AND DATE(mt.created_at) = CURDATE()
                  AND mt.transaction_type IN ('job_order','combined')
            ");
            $kpi_stmt->execute([$station_id, $me['id']]);
            $kpi_row = $kpi_stmt->fetch(PDO::FETCH_ASSOC);
            if ($kpi_row) {
                $kpi_jo_count       += (int)$kpi_row['jo_count'];
                $kpi_merch_released += (int)$kpi_row['merch_released'];
                $kpi_total_encoded  += (float)$kpi_row['total_encoded'];
            }
        } catch (Exception $_kpie) {}
        try {
            // Also count from native job_orders table
            $kpi_jo_stmt = $pdo->prepare("
                SELECT COUNT(*) AS jo_count,
                       COALESCE(SUM(COALESCE(total_cost, estimated_cost, 0)), 0) AS total_encoded
                FROM job_orders
                WHERE station_id  = ?
                  AND created_by  = ?
                  AND DATE(created_at) = CURDATE()
            ");
            $kpi_jo_stmt->execute([$station_id, $me['id']]);
            $kpi_jo_row = $kpi_jo_stmt->fetch(PDO::FETCH_ASSOC);
            if ($kpi_jo_row) {
                $kpi_jo_count      += (int)$kpi_jo_row['jo_count'];
                $kpi_total_encoded += (float)$kpi_jo_row['total_encoded'];
            }
        } catch (Exception $_kpije) {}

    } catch (Exception $e) {
        $job_orders = [];
    }

    $jo_pending_count  = count(array_filter($job_orders, fn($j) =>
        in_array($j['validation_status'] ?? '', ['Pending Validation', 'Pending', ''])
        || ($j['validation_status'] ?? '') === null
    ));
    $jo_approved_count = count(array_filter($job_orders, fn($j) =>
        in_array($j['validation_status'] ?? '', ['Approved', 'Validated'])
    ));
    $jo_rejected_count = count(array_filter($job_orders, fn($j) =>
        in_array($j['status'] ?? '', ['Rejected', 'Cancelled'])
        || ($j['validation_status'] ?? '') === 'Rejected'
    ));
}

// ── Mechanics list (for job order encode form) ────────────────────────────────
$mechanics = [];
if ($section === 'merchandise') {
    try {
        $mech_where = "status = 'active' AND COALESCE(archived, 0) = 0";
        $mech_params = [];
        if ($station_id > 0) {
            $mech_where .= " AND station_id = ?";
            $mech_params[] = $station_id;
        }
        $stmt = $pdo->prepare("SELECT id, full_name, specialization FROM mechanics WHERE {$mech_where} ORDER BY full_name");
        $stmt->execute($mech_params);
        $mechanics = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $mechanics = []; }
}

// ── Service types (for job order encode form) ─────────────────────────────────
$jo_service_types = [];
if ($section === 'merchandise') {
    try {
        $stmt = $pdo->query("SELECT service_key, service_name, service_price, min_price, max_price, price_description, pricing_notes, icon_class, color_class FROM job_order_service_types WHERE active = 1 ORDER BY sort_order, service_name");
        $jo_service_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $jo_service_types = []; }
}

include __DIR__ . '/../partials/header.php';
?>
<script>
// Early global definition of Merchandise Details & Action Request Modals
window.openMerchView = function(btn) {
    if (window.event) {
        if (typeof window.event.stopPropagation === 'function') window.event.stopPropagation();
        if (typeof window.event.preventDefault === 'function') window.event.preventDefault();
    }
    var txnId = '';
    if (typeof btn === 'string') {
        txnId = btn;
    } else if (btn && typeof btn.getAttribute === 'function') {
        txnId = btn.getAttribute('data-txn-id') || btn.getAttribute('data-id') || '';
    }
    if (typeof window.viewMerchandiseDetails === 'function') {
        window.viewMerchandiseDetails(txnId);
    }
    return false;
};

window.openMerchRequest = function(btn, type) {
    if (window.event) {
        if (typeof window.event.stopPropagation === 'function') window.event.stopPropagation();
        if (typeof window.event.preventDefault === 'function') window.event.preventDefault();
    }
    var mtId = 0;
    var customer = '';
    if (btn && typeof btn.getAttribute === 'function') {
        mtId = parseInt(btn.getAttribute('data-mt-id') || btn.getAttribute('data-id') || '0', 10);
        customer = btn.getAttribute('data-customer') || '';
    }
    if (typeof window.openTxnRequestModal === 'function') {
        window.openTxnRequestModal(window.event, mtId, 'merchandise_transactions', type, customer);
    }
    return false;
};

window.openRequestAdjustModal = function(arg1, arg2) {
    var e = null;
    var joData = {};

    if (arg1 && typeof arg1.stopPropagation === 'function') {
        e = arg1;
        if (typeof e.stopPropagation === 'function') e.stopPropagation();
        if (typeof e.preventDefault === 'function') e.preventDefault();
    }

    if (arg2 && typeof arg2.getAttribute === 'function') {
        var btn = arg2;
        joData = {
            id: btn.getAttribute('data-jo-id') || '',
            source: btn.getAttribute('data-jo-source') || 'job_orders',
            jo_ref: btn.getAttribute('data-jo-ref') || '',
            customer: btn.getAttribute('data-jo-customer') || '—',
            workflow_status: btn.getAttribute('data-jo-status') || '—',
            payment_status: btn.getAttribute('data-jo-paystatus') || '—',
            payment_method: btn.getAttribute('data-jo-paymethod') || 'Cash',
            total: btn.getAttribute('data-jo-total') || '0',
            labor_fee: btn.getAttribute('data-jo-labor') || '0',
            paid: btn.getAttribute('data-jo-paid') || '0',
            service_type: btn.getAttribute('data-jo-service') || '',
            vehicle_plate: btn.getAttribute('data-jo-plate') || '',
            vehicle_type: btn.getAttribute('data-jo-vtype') || '',
            mechanic: btn.getAttribute('data-jo-mech') || 'Unassigned'
        };
    } else if (arg1 && typeof arg1.getAttribute === 'function') {
        var btn = arg1;
        joData = {
            id: btn.getAttribute('data-jo-id') || '',
            source: btn.getAttribute('data-jo-source') || 'job_orders',
            jo_ref: btn.getAttribute('data-jo-ref') || '',
            customer: btn.getAttribute('data-jo-customer') || '—',
            workflow_status: btn.getAttribute('data-jo-status') || '—',
            payment_status: btn.getAttribute('data-jo-paystatus') || '—',
            payment_method: btn.getAttribute('data-jo-paymethod') || 'Cash',
            total: btn.getAttribute('data-jo-total') || '0',
            labor_fee: btn.getAttribute('data-jo-labor') || '0',
            paid: btn.getAttribute('data-jo-paid') || '0',
            service_type: btn.getAttribute('data-jo-service') || '',
            vehicle_plate: btn.getAttribute('data-jo-plate') || '',
            vehicle_type: btn.getAttribute('data-jo-vtype') || '',
            mechanic: btn.getAttribute('data-jo-mech') || 'Unassigned'
        };
    } else if (arg1 && typeof arg1 === 'object' && !arg1.stopPropagation) {
        joData = arg1;
    }

    ['viewJobOrderModal', 'viewMerchandiseModal', 'updateStatusModal', 'adjustJobOrderModal', 'txnRequestModal', 'requestVoidModal'].forEach(function(id) {
        var m = document.getElementById(id);
        if (m) m.style.display = 'none';
    });

    var modal = document.getElementById('requestAdjustModal');
    if (!modal) {
        console.error('requestAdjustModal element not found');
        return false;
    }

    window._activeJoDataForReq = joData;
    
    var setVal = function(id, val) { var el = document.getElementById(id); if (el) el.value = val || ''; };
    var setTxt = function(id, val) { var el = document.getElementById(id); if (el) el.textContent = val || '—'; };

    setVal('reqAdjTxnId', joData.id);
    setVal('reqAdjRecordSource', joData.source);
    setTxt('reqAdjJoNo', joData.jo_ref || ('#' + (joData.id || '')));
    setTxt('reqAdjCustomer', joData.customer);
    setTxt('reqAdjStatus', joData.workflow_status);
    setTxt('reqAdjPayStatus', joData.payment_status);
    setTxt('reqAdjPayMethod', joData.payment_method);

    setVal('reqAdjCorrectionField', 'Labor Fee');
    if (typeof window.onReqAdjFieldChange === 'function') {
        window.onReqAdjFieldChange();
    } else {
        setVal('reqAdjCurrentValue', joData.labor_fee ? ('₱' + parseFloat(joData.labor_fee).toFixed(2)) : '₱100.00');
    }
    setVal('reqAdjRequestedValue', '');
    setVal('reqAdjReason', '');
    setVal('reqAdjRemarks', '');

    modal.style.display = 'flex';
    modal.style.zIndex = '9999999';
    return false;
};

window.submitJOAction = function(action, id, source, confirmMsg) {
    if (confirmMsg && !confirm(confirmMsg)) {
        return false;
    }
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = 'staff_transactions_hub.php?section=merchandise&active_tab=tracker';

    var inputAction = document.createElement('input');
    inputAction.type = 'hidden';
    inputAction.name = 'jo_action';
    inputAction.value = action;
    form.appendChild(inputAction);

    var inputId = document.createElement('input');
    inputId.type = 'hidden';
    inputId.name = 'jo_id';
    inputId.value = id;
    form.appendChild(inputId);

    var inputSource = document.createElement('input');
    inputSource.type = 'hidden';
    inputSource.name = 'jo_source';
    inputSource.value = source;
    form.appendChild(inputSource);

    document.body.appendChild(form);
    form.submit();
    return false;
};

window.openRequestVoidModal = function(arg1, arg2) {
    var e = null;
    var joData = {};

    if (arg1 && typeof arg1.stopPropagation === 'function') {
        e = arg1;
        if (typeof e.stopPropagation === 'function') e.stopPropagation();
        if (typeof e.preventDefault === 'function') e.preventDefault();
    }

    if (arg2 && typeof arg2.getAttribute === 'function') {
        var btn = arg2;
        joData = {
            id: btn.getAttribute('data-jo-id') || '',
            source: btn.getAttribute('data-jo-source') || 'job_orders',
            jo_ref: btn.getAttribute('data-jo-ref') || '',
            customer: btn.getAttribute('data-jo-customer') || '—',
            workflow_status: btn.getAttribute('data-jo-status') || '—',
            payment_status: btn.getAttribute('data-jo-paystatus') || '—'
        };
    } else if (arg1 && typeof arg1.getAttribute === 'function') {
        var btn = arg1;
        joData = {
            id: btn.getAttribute('data-jo-id') || '',
            source: btn.getAttribute('data-jo-source') || 'job_orders',
            jo_ref: btn.getAttribute('data-jo-ref') || '',
            customer: btn.getAttribute('data-jo-customer') || '—',
            workflow_status: btn.getAttribute('data-jo-status') || '—',
            payment_status: btn.getAttribute('data-jo-paystatus') || '—'
        };
    } else if (arg1 && typeof arg1 === 'object' && !arg1.stopPropagation) {
        joData = arg1;
    }

    ['viewJobOrderModal', 'viewMerchandiseModal', 'updateStatusModal', 'adjustJobOrderModal', 'txnRequestModal', 'requestAdjustModal'].forEach(function(id) {
        var m = document.getElementById(id);
        if (m) m.style.display = 'none';
    });

    var modal = document.getElementById('requestVoidModal');
    if (!modal) {
        console.error('requestVoidModal element not found');
        return false;
    }

    var setVal = function(id, val) { var el = document.getElementById(id); if (el) el.value = val || ''; };
    var setTxt = function(id, val) { var el = document.getElementById(id); if (el) el.textContent = val || '—'; };

    setVal('reqVoidTxnId', joData.id);
    setVal('reqVoidRecordSource', joData.source);
    setTxt('reqVoidJoNo', joData.jo_ref || ('#' + (joData.id || '')));
    setTxt('reqVoidCustomer', joData.customer);
    setTxt('reqVoidStatus', joData.workflow_status);
    setTxt('reqVoidPayStatus', joData.payment_status);

    setVal('reqVoidReasonSelect', 'Duplicate Transaction');
    setVal('reqVoidRemarks', '');

    modal.style.display = 'flex';
    modal.style.zIndex = '9999999';
    return false;
};
window.viewMerchandiseDetails = function(txnId) {
    if (!txnId) {
        if (typeof showTxnAlert === 'function') showTxnAlert('Invalid transaction ID', 'error');
        else alert('Invalid transaction ID');
        return;
    }
    ['viewJobOrderModal', 'viewMerchandiseModal', 'updateStatusModal', 'adjustJobOrderModal', 'txnRequestModal'].forEach(function(id) {
        var m = document.getElementById(id);
        if (m) m.style.display = 'none';
    });

    var modal = document.getElementById('viewMerchandiseModal');
    if (!modal) {
        console.error('viewMerchandiseModal element not found');
        return;
    }

    var itemsTable = document.getElementById('viewMTItemsBody');
    if (itemsTable) {
        itemsTable.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:16px;color:#64748b;"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>';
    }

    fetch('../backend/get_merchandise_transaction_details.php?id=' + encodeURIComponent(txnId))
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                var txn = data.transaction || {};
                var setTxt = function(id, val) {
                    var el = document.getElementById(id);
                    if (el) el.textContent = val || '�';
                };
                var setHtml = function(id, val) {
                    var el = document.getElementById(id);
                    if (el) el.innerHTML = val || '�';
                };
                setTxt('viewMTxnRef', txn.transaction_id || ('#' + txn.id));
                setTxt('viewMTCustomer', txn.customer_name || 'Walk-in Customer');
                setTxt('viewMTShift', txn.shift_name || txn.shift_period || '�');
                setTxt('viewMTDate', txn.transaction_date || '�');
                setTxt('viewMTPayMethod', txn.payment_method || '�');
                setHtml('viewMTPayStatus', txn.payment_status_badge || '�');
                setHtml('viewMTValStatus', txn.validation_status_badge || '�');
                setTxt('viewMTSubtotal', txn.subtotal_display || '?0.00');
                setTxt('viewMTVAT', txn.vat_display || '?0.00');
                setTxt('viewMTTotal', txn.total_display || '?0.00');
                setTxt('viewMTPaid', txn.paid_display || '?0.00');
                setTxt('viewMTBalance', txn.balance_display || '?0.00');
                setTxt('viewMTRemarks', txn.remarks || '�');
                setTxt('viewMTStaff', txn.staff_name || '�');

                var itemsList = txn.items || data.items || [];
                if (itemsTable) {
                    if (itemsList.length > 0) {
                        itemsTable.innerHTML = itemsList.map(function(item) {
                            var qty = parseInt(item.quantity) || 0;
                            var price = parseFloat(item.unit_price || 0).toFixed(2);
                            var subtotal = parseFloat(item.subtotal || 0).toFixed(2);
                            var name = (item.product_name || 'Item').replace(/</g, '&lt;');
                            var cat = (item.category || '').replace(/</g, '&lt;');
                            var size = (item.size_variant || '').replace(/</g, '&lt;');
                            return '<tr style="border-bottom:1px solid #f1f5f9;">' +
                                '<td style="padding:8px;"><div style="font-weight:600;color:#1e293b;">' + name + '</div>' +
                                (cat ? '<div style="font-size:10px;color:#94a3b8;margin-top:2px;">' + cat + (size ? ' � ' + size : '') + '</div>' : '') + '</td>' +
                                '<td style="padding:8px;text-align:center;color:#475569;">' + qty + ' ' + (qty === 1 ? 'pc' : 'pcs') + '</td>' +
                                '<td style="padding:8px;text-align:right;color:#475569;">?' + price + '</td>' +
                                '<td style="padding:8px;text-align:right;color:#003d7a;font-weight:700;">?' + subtotal + '</td>' +
                                '</tr>';
                        }).join('');
                    } else {
                        itemsTable.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:12px;color:#94a3b8;">No items found</td></tr>';
                    }
                }
                modal.style.display = 'flex';
            } else {
                if (typeof showTxnAlert === 'function') showTxnAlert(data.error || 'Failed to load transaction details', 'error');
                else alert(data.error || 'Failed to load transaction details');
            }
        })
        .catch(function(err) {
            console.error('Error fetching merchandise details:', err);
            if (typeof showTxnAlert === 'function') showTxnAlert('Network error: ' + err.message, 'error');
            else alert('Network error: ' + err.message);
        });
};

window.openTxnRequestModal = function(e, txnId, recordSource, requestType, customerName) {
    if (e) {
        if (typeof e.stopPropagation === 'function') e.stopPropagation();
        if (typeof e.preventDefault === 'function') e.preventDefault();
        if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
    }
    ['viewJobOrderModal', 'viewMerchandiseModal', 'updateStatusModal', 'adjustJobOrderModal'].forEach(function(id) {
        var m = document.getElementById(id);
        if (m) m.style.display = 'none';
    });

    var modal = document.getElementById('txnRequestModal');
    if (!modal) {
        console.error('txnRequestModal element not found');
        return;
    }

    var setVal = function(id, val) {
        var el = document.getElementById(id);
        if (el) el.value = val || '';
    };

    setVal('txnRequestTxnId', txnId);
    setVal('txnRequestRecordSource', recordSource || 'merchandise_transactions');
    setVal('txnRequestType', requestType || 'Void');
    setVal('txnRequestReason', '');
    setVal('txnRequestNewAmount', '');

    var isVoid = (requestType === 'Void');
    var titleText = isVoid ? 'Request Void Transaction' : 'Request Transaction Adjustment';
    var iconClass = isVoid ? 'fas fa-ban' : 'fas fa-sliders-h';

    var elTitleText = document.getElementById('txnRequestTitleText');
    if (elTitleText) elTitleText.textContent = titleText;

    var elIcon = document.getElementById('txnRequestIcon');
    if (elIcon) elIcon.className = iconClass;

    var elNewAmountGroup = document.getElementById('txnRequestNewAmountGroup');
    if (elNewAmountGroup) elNewAmountGroup.style.display = isVoid ? 'none' : 'block';

    var targetInfo = document.getElementById('txnRequestTargetInfo');
    if (targetInfo) {
        var label = (recordSource === 'merchandise_transactions' || recordSource === 'merchandise')
            ? 'Merchandise Txn #' + txnId
            : 'Job Order #' + txnId;
        if (customerName) label += ' (' + customerName + ')';
        targetInfo.textContent = label;
    }

    modal.style.display = 'flex';
};

window.closeViewMerchandiseModal = function() {
    var modal = document.getElementById('viewMerchandiseModal');
    if (modal) modal.style.display = 'none';
};

window.closeTxnRequestModal = function() {
    var modal = document.getElementById('txnRequestModal');
    if (modal) modal.style.display = 'none';
};
</script>

<style>
/* ── Cart Wrapper — Flexbox Row Layout ──────────────────────────────── */
.cart-wrapper {
    display: flex !important;
    margin-bottom: 24px !important;
    padding-bottom: 12px !important;
    flex-direction: row !important;
    gap: 16px !important;
    align-items: flex-start !important;
    width: 100% !important;
    box-sizing: border-box !important;
}

/* When viewing history, hide cart and expand left column */
.cart-wrapper.history-view {
    flex-direction: column !important;
}
.cart-wrapper.history-view .cart-panel {
    display: none !important;
}

/* Collapse to single column on mobile */
@media (max-width: 768px) {
    .cart-wrapper {
        flex-direction: column !important;
    }
}

/* ── Sub-tab Navigation (Horizontal Strip - Exact Match with Reports Design) ── */
.txn-subtab-nav, .rpt-subtab-nav {
    display: flex !important;
    flex-wrap: wrap !important;
    margin-bottom: 22px !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 8px !important;
    overflow: hidden !important;
    border-bottom: 3px solid #002F70 !important;
    background: #ffffff !important;
    width: 100% !important;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05) !important;
}

.txn-subtab-btn, .rpt-subtab-btn {
    flex: 1 !important;
    min-width: 140px !important;
    padding: 12px 18px !important;
    font-size: 11.5px !important;
    font-weight: 700 !important;
    color: #475569 !important;
    background: #ffffff !important;
    border: none !important;
    border-right: 1px solid #cbd5e1 !important;
    text-decoration: none !important;
    transition: all 0.15s ease !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.3px !important;
    text-align: center !important;
    cursor: pointer !important;
    border-radius: 0 !important;
    white-space: nowrap !important;
}

.txn-subtab-btn:last-child, .rpt-subtab-btn:last-child {
    border-right: none !important;
}

.txn-subtab-btn:hover, .rpt-subtab-btn:hover {
    background: #f1f5f9 !important;
    color: #002F70 !important;
    text-decoration: none !important;
}

.txn-subtab-btn.active, .rpt-subtab-btn.active,
.txn-subtab-btn.green.active, .txn-subtab-btn.blue.active, .txn-subtab-btn.darkblue.active {
    background: #002F70 !important;
    color: #ffffff !important;
    font-weight: 800 !important;
}

.txn-subtab-btn.inactive,
.txn-subtab-btn.green.inactive, .txn-subtab-btn.blue.inactive, .txn-subtab-btn.darkblue.inactive {
    background: #ffffff !important;
    color: #475569 !important;
}

.txn-subtab-btn i, .rpt-subtab-btn i {
    font-size: 13px !important;
    color: inherit !important;
}

.subtab-badge-val {
    transition: all 0.15s ease !important;
}
.txn-subtab-btn.active .subtab-badge-val {
    background: #ffffff !important;
    color: #002F70 !important;
}
.txn-subtab-btn.inactive .subtab-badge-val {
    background: #002F70 !important;
    color: #ffffff !important;
}

/* Icon Buttons next to inputs (immune to overrides) */
.txn-icon-btn {
    flex-shrink: 0;
    width: 34px !important;
    height: 34px !important;
    border-radius: 4px !important;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s ease-in-out;
}
.txn-icon-btn i {
    font-size: 14px !important;
}

.txn-icon-btn.green {
    border: 1px solid #28a745 !important;
    background: #ffffff !important;
    color: #28a745 !important;
}
.txn-icon-btn.green i {
    color: #28a745 !important;
}
.txn-icon-btn.green:hover {
    background: #28a745 !important;
    color: #ffffff !important;
}
.txn-icon-btn.green:hover i {
    color: #ffffff !important;
}

.txn-icon-btn.blue {
    border: 1px solid #003d7a !important;
    background: #ffffff !important;
    color: #003d7a !important;
}
.txn-icon-btn.blue i {
    color: #003d7a !important;
}
.txn-icon-btn.blue:hover {
    background: #003d7a !important;
    color: #ffffff !important;
}
.txn-icon-btn.blue:hover i {
    color: #ffffff !important;
}

/* ── Mechanic Typeahead Dropdown Items ─────────────────────────── */
.jo-mechanic-item:hover {
    background: #f0f7ff !important;
    color: #003d7a !important;
}
.jo-mechanic-item:last-child {
    border-bottom: none;
}

/* ═══════════════════════════════════════════════════════════════
   TRANSACTIONS HUB — Page-level styles
   Uses existing Petron CSS variables from style.css
═══════════════════════════════════════════════════════════════ */

/* ── Text Color Fix — Ensure form text is visible ──────────────── */
.txn-field, .txn-field * {
    color: #1e293b;
}

.txn-field label {
    color: #1e293b !important;
}

.txn-input, .txn-select {
    color: #1e293b !important;
}

/* ── Main Content Panel ──────────────────────────────────────── */
.txn-content {
    padding: 0;
    min-width: 0;
    width: 100%;
}

/* Match inventory page — no padding override needed */
main.main {
    padding: 8px 20px 70px 20px !important;
    overflow-x: visible !important;
}

/* ── Cart wrapper — using grid layout defined above to show cart on right ── */

/* ── Right panel — cart fixed on right side ── */
.cart-panel {
    display: flex !important;
    flex-direction: column !important;
    flex: 0 0 340px !important;
    width: 340px !important;
    min-width: 340px !important;
    max-width: 340px !important;
    position: sticky !important;
    top: 16px !important;
    align-self: flex-start !important;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,.07);
    max-height: calc(100vh - 110px) !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
}

/* Customer & Payment — no max-height restriction */
.cart-panel-top {
    flex-shrink: 0;
    padding: 14px 18px 12px;
    border-bottom: 2px solid #f1f5f9;
}

/* Cart header row */
.cart-header {
    flex-shrink: 0;
    padding: 8px 18px;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

/* Cart items */
.cart-body {
    flex: 1 1 0;
    overflow-y: auto;
    padding: 8px 14px;
    min-height: 60px;
    max-height: 320px;
}

/* Totals + button */
.cart-footer {
    flex-shrink: 0;
    padding: 14px 18px 22px !important;
    border-top: 2px solid #e2e8f0;
    background: #fff;
}
.txn-section-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 22px;
    gap: 16px;
    flex-wrap: wrap;
}

.txn-section-title {
    display: flex;
    align-items: center;
    gap: 12px;
}

.txn-section-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}

.txn-section-icon.fuel  { background: rgba(0,47,108,.12); color: var(--petron-blue); }
.txn-section-icon.merch { background: rgba(40,167,69,.12); color: #28a745; }
.txn-section-icon.hist  { background: rgba(111,66,193,.12); color: #6f42c1; }
.txn-section-icon.jo    { background: rgba(180,83,9,.12);   color: #b45309; }

.txn-section-title h1 {
    font-size: 22px !important;
    font-weight: 700 !important;
    color: var(--petron-blue) !important;
    margin: 0 !important;
}

.txn-section-title p {
    font-size: 13px;
    color: #666;
    margin: 4px 0 0;
    text-transform: none;
    font-weight: 400;
    letter-spacing: 0;
}

/* ── Status badge ────────────────────────────────────────────── */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
}
.status-badge.internal { background: #dbeafe; color: #1d4ed8; }
.status-badge.customer { background: #dcfce7; color: #15803d; }

/* ── Cards ───────────────────────────────────────────────────── */
.txn-card {
    background: #fff;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 2px 10px rgba(0,0,0,.06);
    overflow: visible;
    margin-bottom: 16px !important;
    width: 100%;
    position: relative;
    z-index: 1;
}

.txn-card-header {
    padding: 16px 22px;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    gap: 10px;
}

.txn-card-header h3 {
    font-size: 14px !important;
    font-weight: 700 !important;
    color: #1e293b !important;
    margin: 0 !important;
    text-transform: uppercase !important;
    letter-spacing: .5px !important;
}

.txn-card-body { 
    padding: 22px 24px 20px 24px !important;
    position: relative;
    z-index: auto;
}

/* ── Form elements ───────────────────────────────────────────── */
.txn-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
    width: 100%;
    position: relative;
    z-index: auto;
}

.txn-form-grid.cols-3 { grid-template-columns: 1fr 1fr 1fr; }
.txn-form-grid.cols-1 { grid-template-columns: 1fr; }

.txn-field { 
    display: flex; 
    flex-direction: column; 
    gap: 6px;
    position: relative;
    z-index: auto;
}

/* Ensure input fields with datalist work properly */
input[list] {
    position: relative;
    z-index: 10;
}

.txn-field label {
    font-size: 12px;
    font-weight: 600;
    color: #1e293b !important;
    text-transform: uppercase;
    letter-spacing: .4px;
}

.txn-field label .field-note {
    font-weight: 400;
    color: #94a3b8;
    text-transform: none;
    letter-spacing: 0;
    font-size: 11px;
    margin-left: 4px;
}

.txn-input, .txn-select {
    padding: 10px 13px;
    border: 1.5px solid #e2e8f0;
    border-radius: 8px;
    font-size: 14px;
    color: #1e293b !important;
    background: #fff;
    transition: border-color .15s, box-shadow .15s;
    width: 100%;
}

.txn-input::placeholder, .txn-select::placeholder {
    color: #94a3b8 !important;
    opacity: 1;
}

.txn-input:focus, .txn-select:focus {
    outline: none;
    border-color: var(--petron-blue);
    box-shadow: 0 0 0 3px rgba(0,47,108,.1);
}

/* Service Type Dropdown Styles */
#joServiceTypeDropdown {
    scrollbar-width: thin;
    scrollbar-color: #cbd5e1 #f1f5f9;
}

#joServiceTypeDropdown::-webkit-scrollbar {
    width: 6px;
}

#joServiceTypeDropdown::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 3px;
}

#joServiceTypeDropdown::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 3px;
}

#joServiceTypeDropdown::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

.service-type-option {
    cursor: pointer;
    transition: background 0.15s;
}

.service-type-option:hover {
    background: #f8fafc !important;
}

.service-type-option:last-child {
    border-bottom: none !important;
}

.txn-input.auto-pull {
    background: #f0fdf4;
    border-color: #86efac;
    color: #166534;
    font-weight: 600;
}

.txn-input.computed {
    background: #eff6ff;
    border-color: #93c5fd;
    color: #1d4ed8;
    font-weight: 700;
}

.txn-input.readonly-field {
    background: #f8fafc;
    color: #64748b;
    cursor: not-allowed;
}

/* ── Calculation box ─────────────────────────────────────────── */
.calc-box {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 18px 20px;
    margin-top: 4px;
}

.calc-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 7px 0;
    font-size: 14px;
    color: #475569;
    border-bottom: 1px dashed #e2e8f0;
}

.calc-row:last-child { border-bottom: none; }

.calc-row.total {
    font-size: 18px;
    font-weight: 800;
    color: var(--petron-blue);
    border-top: 2px solid #e2e8f0;
    border-bottom: none;
    padding-top: 12px;
    margin-top: 4px;
}

.calc-row .calc-label { font-weight: 500; }
.calc-row .calc-val   { font-weight: 700; }

/* ── Cart total rows ─────────────────────────────────────────── */
.cart-total-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 12px;
    color: #475569;
    padding: 3px 0;
}

.cart-total-row .calc-val { font-weight: 600; }

.cart-total-row.grand {
    font-size: 15px;
    font-weight: 800;
    color: var(--petron-blue);
    border-top: 1.5px solid #e2e8f0;
    padding-top: 8px;
    margin-top: 4px;
}

/* ── Cart item ───────────────────────────────────────────────── */
.cart-empty {
    text-align: center;
    padding: 30px 20px;
    color: #94a3b8;
    font-size: 13px;
}

.cart-empty i { font-size: 28px; display: block; margin-bottom: 8px; }

.cart-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    margin-bottom: 8px;
    transition: box-shadow .15s;
}

.cart-item:hover { box-shadow: 0 2px 8px rgba(0,0,0,.08); }

.cart-item-info { flex: 1; min-width: 0; }

.cart-item-name {
    font-size: 13px;
    font-weight: 600;
    color: #1e293b;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.cart-item-meta { font-size: 11px; color: #64748b; margin-top: 2px; }

.cart-item-subtotal {
    font-size: 13px;
    font-weight: 700;
    color: var(--petron-blue);
    white-space: nowrap;
    margin-right: 8px;
}

.cart-item-remove {
    background: none;
    border: none;
    color: #ef4444;
    cursor: pointer;
    padding: 4px 6px;
    border-radius: 6px;
    font-size: 13px;
    transition: background .15s;
}

.cart-item-remove:hover { background: #fee2e2; }

/* ── Buttons ─────────────────────────────────────────────────── */
.txn-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 7px 14px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid transparent;
    transition: all .2s;
    text-decoration: none;
    white-space: nowrap;
    background: white !important;
}

.txn-btn.primary {
    background: #ffffff !important;
    color: #00264D !important;
    border-color: #00264D !important;
}
.txn-btn.primary:hover {
    background: #f0f7ff !important;
    color: #001A33 !important;
    border-color: #001A33 !important;
}

.txn-btn.success {
    background: #ffffff !important;
    color: #16a34a !important;
    border-color: #16a34a !important;
}
.txn-btn.success:hover {
    background: #f0fdf4 !important;
    color: #15803d !important;
    border-color: #15803d !important;
}

.txn-btn.secondary {
    background: #ffffff !important;
    color: #475569 !important;
    border-color: #475569 !important;
}
.txn-btn.secondary:hover {
    background: #f8fafc !important;
    color: #334155 !important;
    border-color: #334155 !important;
}

.txn-btn.danger {
    background: #ffffff !important;
    color: #dc2626 !important;
    border-color: #dc2626 !important;
}
.txn-btn.danger:hover {

.txn-btn.info {
    background: #ffffff !important;
    color: #0284c7 !important;
    border-color: #0284c7 !important;
}
.txn-btn.info:hover {
    background: #f0f9ff !important;
    color: #0369a1 !important;
    border-color: #0369a1 !important;
}
.txn-btn.warning {
    background: #ffffff !important;
    color: #d97706 !important;
    border-color: #d97706 !important;
}
.txn-btn.warning:hover {
    background: #fffbeb !important;
    color: #b45309 !important;
    border-color: #b45309 !important;
}
    background: #fef2f2 !important;
    color: #b91c1c !important;
    border-color: #b91c1c !important;
}

.txn-btn:disabled {
    opacity: .5;
    cursor: not-allowed;
}

.txn-btn.full { width: 100%; }

/* ── Cart qty +/- buttons: override global button background tint ─── */
.cart-item-row button {
    background: #f8fafc !important;
    color: #374151 !important;
    border-color: #e2e8f0 !important;
}
.cart-item-row button:hover {
    background: #e2e8f0 !important;
    color: #1e293b !important;
}

/* ── Icon Buttons (+ buttons sa dropdowns) ─────────────────────── */
.txn-icon-btn {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 36px !important;
    height: 36px !important;
    padding: 0 !important;
    border-radius: 8px !important;
    font-size: 16px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    transition: all 0.2s !important;
    flex-shrink: 0 !important;
    border: 1.5px solid !important;
    background: white !important;
}

.txn-icon-btn.blue {
    color: #003d7a !important;
    border-color: #003d7a !important;
}

.txn-icon-btn.blue:hover {
    background: #003d7a !important;
    color: white !important;
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,61,122,0.2);
}

.txn-icon-btn.green {
    color: #1d6f42 !important;
    border-color: #1d6f42 !important;
}

.txn-icon-btn.green:hover {
    background: #1d6f42 !important;
    color: white !important;
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(29,111,66,0.2);
}

.txn-icon-btn:active {
    transform: translateY(0);
}

/* ── Pagination buttons ───────────────────────────────────────── */
.pag-btn {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 28px !important;
    height: 28px !important;
    background: #ffffff !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 4px !important;
    color: #475569 !important;
    font-size: 12px !important;
    cursor: pointer !important;
    transition: all .15s !important;
}
.pag-btn i { color: #475569 !important; }
.pag-btn:hover:not(:disabled) { background: #00264D !important; border-color: #00264D !important; color: #ffffff !important; }
.pag-btn:hover:not(:disabled) i { color: #ffffff !important; }
.pag-btn:disabled { opacity: .4 !important; cursor: not-allowed !important; }

/* ── Rows per page select ─────────────────────────────────────── */
.pag-select {
    font-size: 12px !important;
    padding: 4px 8px !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 4px !important;
    background: #ffffff !important;
    color: inherit !important;
    outline: none !important;
    cursor: pointer !important;
}
.txn-info-banner {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 14px 18px;
    border-radius: 10px;
    margin-bottom: 20px;
    font-size: 13px;
}

.txn-info-banner.blue {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    color: #1d4ed8;
}

.txn-info-banner.amber {
    background: #fffbeb;
    border: 1px solid #fde68a;
    color: #92400e;
}

.txn-info-banner i { font-size: 16px; margin-top: 1px; flex-shrink: 0; }

/* ── Status flow ─────────────────────────────────────────────── */
.status-flow {
    display: flex;
    align-items: center;
    gap: 0;
    flex-wrap: wrap;
    margin-bottom: 20px;
}

.status-step {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 14px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
}

.status-step:first-child { border-radius: 8px 0 0 8px; }
.status-step:last-child  { border-radius: 0 8px 8px 0; }

.status-step .step-num {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: #cbd5e1;
    color: #fff;
    font-size: 10px;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
}

.status-step.active { background: #eff6ff; border-color: #93c5fd; color: #1d4ed8; }
.status-step.active .step-num { background: var(--petron-blue); }

.status-arrow {
    font-size: 12px;
    color: #94a3b8;
    padding: 0 2px;
}

/* ── History table ───────────────────────────────────────────── */
.txn-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}

.txn-table th {
    background: #002F70;  /* Blue headers */
    padding: 12px 10px;
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    color: #ffffff;  /* White text */
    text-transform: uppercase;
    letter-spacing: .4px;
    border-bottom: 2px solid #001f4d;
    white-space: nowrap;
}

.txn-table td {
    padding: 10px;
    border-bottom: 1px solid #f1f5f9;
    color: #1e293b;  /* Plain text */
    background: #ffffff;  /* Clean white background */
    vertical-align: middle;
    font-size: 13px;
}

.txn-table tr:hover td { 
    background: #f0f5ff;  /* Light blue hover */
}

.txn-table .empty-row td {
    text-align: center;
    padding: 40px;
    color: #94a3b8;
}

/* ── Flash messages (floating top-right banner) ───────────────── */
.flash {
    position: fixed;
    top: 84px;
    right: 24px;
    z-index: 99999;
    padding: 13px 18px;
    border-radius: 10px;
    font-size: 13.5px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 10px 25px -5px rgba(0,0,0,0.15), 0 8px 10px -6px rgba(0,0,0,0.1);
    max-width: 440px;
    min-width: 280px;
    animation: flashSlideInRight 0.35s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes flashSlideInRight {
    from { opacity: 0; transform: translateX(40px) scale(0.95); }
    to { opacity: 1; transform: translateX(0) scale(1); }
}
.flash.success { background: #dcfce7; border: 1.5px solid #86efac; color: #166534; }
.flash.error   { background: #fee2e2; border: 1.5px solid #fca5a5; color: #991b1b; }

/* ── Responsive ──────────────────────────────────────────────── */
@media (max-width: 1100px) {
    .txn-form-grid { grid-template-columns: 1fr; }
    .txn-form-grid.cols-3 { grid-template-columns: 1fr; }
    .cart-body { min-height: 120px; max-height: 260px; }
}

/* ── Toast notification — override global blue toast ────────── */
#toast, #txnToast {
    bottom: 56px !important;   /* above the 40px fixed footer */
    left: 50% !important;
    right: auto !important;
    transform: translateX(-50%) !important;
    z-index: 10002 !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    border-radius: 10px !important;
    padding: 11px 20px !important;
    box-shadow: 0 4px 20px rgba(0,0,0,.15) !important;
    max-width: 380px !important;
    text-align: center !important;
}

/* ── Product dropdown hover ──────────────────────────────────── */
.prod-option:hover { background: #eff6ff !important; }
.prod-option:active { background: #dbeafe !important; }

/* ── Cart items ──────────────────────────────────────────────── */
.cart-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 7px;
    margin-bottom: 6px;
}
.cart-item:hover { box-shadow: 0 1px 6px rgba(0,0,0,.07); }
.cart-item-info { flex: 1; min-width: 0; }
.cart-item-name { font-size: 12px; font-weight: 600; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cart-item-meta { font-size: 10px; color: #64748b; margin-top: 1px; }
.cart-item-subtotal { font-size: 12px; font-weight: 700; color: var(--petron-blue); white-space: nowrap; }
.cart-item-remove { background: none; border: none; color: #ef4444; cursor: pointer; padding: 3px 5px; border-radius: 5px; font-size: 11px; }
.cart-item-remove:hover { background: #fee2e2; }
.cart-empty { text-align: center; padding: 20px 14px; color: #94a3b8; font-size: 12px; }
.cart-empty i { font-size: 22px; display: block; margin-bottom: 6px; }
</style>

<?php if ($flash_success): ?>
<div class="flash success" id="flashMsgBanner">
    <i class="fas fa-check-circle" style="font-size:16px;flex-shrink:0;"></i>
    <span style="flex:1;"><?= htmlspecialchars($flash_success) ?></span>
</div>
<script>
setTimeout(function() {
    const el = document.getElementById('flashMsgBanner');
    if (el) {
        el.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
        el.style.opacity = '0';
        el.style.transform = 'translateX(40px)';
        setTimeout(() => el.remove(), 400);
    }
}, 3000);
</script>
<?php endif; ?>
<?php if ($flash_error): ?>
<div class="flash error" id="flashErrMsgBanner">
    <i class="fas fa-exclamation-circle" style="font-size:16px;flex-shrink:0;"></i>
    <span style="flex:1;"><?= htmlspecialchars($flash_error) ?></span>
</div>
<script>
setTimeout(function() {
    const el = document.getElementById('flashErrMsgBanner');
    if (el) {
        el.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
        el.style.opacity = '0';
        el.style.transform = 'translateX(40px)';
        setTimeout(() => el.remove(), 400);
    }
}, 3000);
</script>
<?php endif; ?>

<div class="txn-content">

        <?php /* ══════════════════════════════════════════════════════
               SECTION: FUEL TRANSACTION (Internal Only)
        ══════════════════════════════════════════════════════ */ ?>
        <?php if ($section === 'fuel'): ?>
        <?php
        $fuel_tab_default = 'encode';
        if (isset($_GET['date_from']) || isset($_GET['date_to']) || isset($_GET['fuel_type']) || isset($_GET['staff_id']) || isset($_GET['status']) || isset($_GET['shift'])) {
            $fuel_tab_default = 'readings';
        }
        if (isset($_GET['fuel_tab']) && in_array($_GET['fuel_tab'], ['encode', 'readings'])) {
            $fuel_tab_default = $_GET['fuel_tab'];
        }
        ?>

        <script>
        window.formatOnInput = function(input) {
            if (!input) return;
            var raw = input.value;
            if (raw.indexOf('.') === -1 && raw.lastIndexOf(',') !== -1 && (raw.length - raw.lastIndexOf(',')) <= 4) {
                var lastIdx = raw.lastIndexOf(',');
                raw = raw.substring(0, lastIdx) + '.' + raw.substring(lastIdx + 1);
            }
            var val = raw.replace(/[^\d.]/g, '');
            var parts = val.split('.');
            if (parts.length > 2) parts = [parts[0], parts.slice(1).join('')];
            var intPart = parts[0] ? parseInt(parts[0], 10).toLocaleString('en-US') : '';
            input.value = parts.length > 1 ? intPart + '.' + parts[1].substring(0, 3) : intPart;
        };

        window.formatOnBlur = function(input) {
            if (!input) return;
            let val = input.value.replace(/,/g, '').trim();
            if (val.startsWith('.')) {
                if (val.length > 3 && !val.includes('.', 1)) {
                    val = val.substring(1);
                } else {
                    val = '0' + val;
                }
            }
            let num = parseFloat(val);
            if (!isNaN(num)) {
                let dec = 2;
                let parts = val.split('.');
                if (parts.length > 1 && parts[1].length > 2) dec = Math.min(parts[1].length, 3);
                input.value = num.toLocaleString('en-US', {minimumFractionDigits: dec, maximumFractionDigits: 3});
            } else if (input.id && input.id.indexOf('cal_') === 0) {
                input.value = '0.00';
            } else {
                input.value = '';
            }
        };

        window.handleMeterKeydown = function(e, input) {
            var key = e.key;
            if (key !== 'Enter' && key !== 'ArrowDown' && key !== 'ArrowUp') {
                return;
            }

            var currentId = input.id || '';
            var m = currentId.match(/^(beginning|ending|cal)_(.+)$/);
            if (!m) return;

            var inputType = m[1]; // 'beginning', 'ending', or 'cal'
            var ftId = m[2];

            var rows = Array.from(document.querySelectorAll('tr[id^="fuelRow_"]'));
            var currentRow = input.closest('tr');
            var currentRowIdx = rows.indexOf(currentRow);

            var targetInput = null;

            if (key === 'Enter' || key === 'ArrowDown') {
                e.preventDefault();
                if (typeof window.formatOnBlur === 'function') window.formatOnBlur(input);
                if (typeof window.updateFuelCalc === 'function') window.updateFuelCalc(ftId);

                // Move VERTICALLY DOWN in the SAME column
                if (currentRowIdx !== -1) {
                    for (var i = currentRowIdx + 1; i < rows.length; i++) {
                        var nextFtId = (rows[i].id || '').replace('fuelRow_', '');
                        var candidate = document.getElementById(inputType + '_' + nextFtId);
                        if (candidate && !candidate.readOnly && candidate.offsetParent !== null) {
                            targetInput = candidate;
                            break;
                        }
                    }
                }

                // If at the end of the column on Enter, wrap to next column's first editable input
                if (!targetInput && key === 'Enter') {
                    var nextType = (inputType === 'beginning') ? 'ending' : ((inputType === 'ending') ? 'cal' : null);
                    if (nextType) {
                        for (var j = 0; j < rows.length; j++) {
                            var wrapFtId = (rows[j].id || '').replace('fuelRow_', '');
                            var wrapCandidate = document.getElementById(nextType + '_' + wrapFtId);
                            if (wrapCandidate && !wrapCandidate.readOnly && wrapCandidate.offsetParent !== null) {
                                targetInput = wrapCandidate;
                                break;
                            }
                        }
                    }
                }
            } else if (key === 'ArrowUp') {
                e.preventDefault();
                if (typeof window.formatOnBlur === 'function') window.formatOnBlur(input);
                if (typeof window.updateFuelCalc === 'function') window.updateFuelCalc(ftId);

                // Move VERTICALLY UP in the SAME column
                if (currentRowIdx > 0) {
                    for (var k = currentRowIdx - 1; k >= 0; k--) {
                        var prevFtId = (rows[k].id || '').replace('fuelRow_', '');
                        var candidateUp = document.getElementById(inputType + '_' + prevFtId);
                        if (candidateUp && !candidateUp.readOnly && candidateUp.offsetParent !== null) {
                            targetInput = candidateUp;
                            break;
                        }
                    }
                }
            }

            if (targetInput) {
                targetInput.focus();
                if (typeof targetInput.select === 'function') {
                    targetInput.select();
                }
                if (typeof targetInput.scrollIntoView === 'function') {
                    targetInput.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                }
            }
        };

        window.switchFuelSubTab = function(tab) {
            var isReadings = (tab === 'readings');
            var encodeCard  = document.getElementById('encodeCard');
            var todayCard   = document.getElementById('todayEntriesCard');
            var encodeBtn   = document.getElementById('fuelSubTabBtn_encode');
            var readingsBtn = document.getElementById('fuelSubTabBtn_readings');
            if (encodeCard) encodeCard.style.display = isReadings ? 'none' : 'block';
            if (todayCard)  todayCard.style.display  = isReadings ? 'block' : 'none';
            if (encodeBtn)  encodeBtn.className  = 'txn-subtab-btn blue ' + (isReadings ? 'inactive' : 'active');
            if (readingsBtn) readingsBtn.className = 'txn-subtab-btn blue ' + (isReadings ? 'active' : 'inactive');
            if (isReadings && typeof refreshTodayEntries === 'function') refreshTodayEntries();
            if (window.history && window.history.replaceState) {
                var url = new URL(window.location.href);
                if (isReadings) url.searchParams.set('fuel_tab', 'readings');
                else url.searchParams.delete('fuel_tab');
                window.history.replaceState(null, '', url);
            }
        };

        window.updateFuelCalc = window.updateFuelCalc || function(ftId) {};
        </script>

        <!-- ── Page Header ───────────────────────────────────────────── -->
        <div class="txn-section-header">
            <div class="txn-section-title">
                <div>
                    <h1>Meter Readings</h1>
                </div>
            </div>
        </div>

        <?php if (isset($_GET['closing_saved']) && $_GET['closing_saved'] == '1'): ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof showToast === 'function') {
                showToast('Fuel Sales Closing saved successfully!', 'success');
            }
            if (window.history && window.history.replaceState) {
                var cleanUrl = new URL(window.location.href);
                cleanUrl.searchParams.delete('closing_saved');
                window.history.replaceState(null, '', cleanUrl);
            }
        });
        </script>
        <?php endif; ?>

        <?php if (empty($fuel_types)): ?>
        <div class="txn-info-banner amber">
            <i class="fas fa-exclamation-triangle"></i>
            <div>No fuel types are configured for this station. Contact your manager.</div>
        </div>
        <?php else: ?>



        <style>
        /* ── Fuel Encoding Table ─────────────────────────────────── */
        .fet-wrap { overflow-x: hidden; }

        .fet {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            table-layout: fixed;
        }

        /* Header */
        .fet thead tr {
            background: #f1f5f9;
        }
        .fet th {
            padding: 11px 14px;
            text-align: left;
            font-size: 14px;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: .45px;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }
        .fet th.num { text-align: right; }

        /* Body rows */
        .fet tbody tr {
            border-bottom: 1px solid #f1f5f9;
            transition: background .12s;
        }
        .fet tbody tr:last-child { border-bottom: none; }
        .fet tbody tr:hover { background: #f8fafc; }

        .fet td {
            padding: 10px 14px;
            vertical-align: middle;
        }
        .fet td.num { text-align: right; font-variant-numeric: tabular-nums; }

        /* Fuel type identity cell */
        .fet-fuel-cell {
            display: flex;
            align-items: center;
            gap: 10px;
            white-space: nowrap;
        }
        .fet-fuel-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .fet-fuel-name {
            font-weight: 700;
            font-size: 13px;
        }

        /* Auto-pulled read-only cells */
        .fet-auto {
            color: #334155;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
        }
        .fet-auto.dim { color: #94a3b8; font-weight: 400; font-style: italic; }

        /* Input cells */
        .fet-input {
            padding: 8px 10px;
            border: 1.5px solid #e2e8f0;
            border-radius: 7px;
            font-size: 14px;
            font-weight: 700;
            color: #1e293b;
            background: #fff;
            width: 100%;
            
            transition: border-color .15s, box-shadow .15s;
            letter-spacing: .3px;
        }
        .fet-input:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(0,47,108,.1);
        }
        .fet-input.calib {
            
            font-weight: 500;
            color: #475569;
        }
        .fet-input.notes-input {
            
            font-weight: 400;
            font-size: 12px;
        }

        /* Submit button per row */
        .fet-submit-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 7px 14px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid #002f6c;
            background: white !important;
            color: #002f6c !important;
            transition: all .2s;
            text-decoration: none;
            white-space: nowrap;
        }
        .fet-submit-btn:hover   { background: #002f6c !important; color: white !important; }
        .fet-submit-btn:disabled { opacity: .5; cursor: not-allowed; }

        .fet-reset-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 7px 14px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid #64748b;
            background: white !important;
            color: #64748b !important;
            transition: all .2s;
            text-decoration: none;
            white-space: nowrap;
        }
        .fet-reset-btn:hover { background: #64748b !important; color: white !important; }

        /* Row message */
        .fet-row-msg {
            font-size: 11px;
            display: none;
            white-space: nowrap;
        }
        </style>

        <!-- ── Fuel Sub-tabs ─────────────────────────────── -->
        <div class="txn-subtab-nav" style="max-width:560px;">
            <?php $is_enc = ($fuel_tab_default === 'encode'); ?>
            <button onclick="switchFuelSubTab('encode')" id="fuelSubTabBtn_encode"
                    class="txn-subtab-btn blue <?= $is_enc ? 'active' : 'inactive' ?>">
                <i class="fas fa-edit"></i> Encode Meter Readings
            </button>
            <button onclick="switchFuelSubTab('readings')" id="fuelSubTabBtn_readings"
                    class="txn-subtab-btn blue <?= !$is_enc ? 'active' : 'inactive' ?>">
                <i class="fas fa-history"></i> Meter Reading History
            </button>
        </div>

        <div class="txn-card" style="margin-bottom:20px;" id="encodeCard">

            <?php /* ── Hidden forms for each fuel row — placed OUTSIDE the table.
                        Inputs inside the table rows use form="fuelForm_..." to associate.
                        Putting <form> inside <td>/<tr> is invalid HTML; browsers eject it
                        from the table, breaking FormData collection. */ ?>
            <?php 
            // Tanker configuration per fuel type - SAME AS TABLE CONFIG
            // ORDER MATTERS: Check longer/more specific names first to avoid partial matches
            // 5 fuel types, 17 total pumps/tankers
            $tanker_config_forms = [
                'xcs plus' => [
                    ['name' => 'XCS Plus', 'tankers' => [1, 2, 3, 4], 'price_key' => 'xcs plus']
                ],
                'turbo diesel' => [
                    ['name' => 'Turbo Diesel', 'tankers' => [1, 2], 'price_key' => 'turbo diesel']
                ],
                'xtra unl' => [
                    ['name' => 'XTRA UNL 1', 'tankers' => [1, 2], 'price_key' => 'xtra unl'],
                    ['name' => 'XTRA UNL 2', 'tankers' => [3, 4], 'price_key' => 'xtra unl']
                ],
                'diesel' => [
                    ['name' => 'Diesel 1', 'tankers' => [1, 2, 3, 4], 'price_key' => 'diesel'],
                    ['name' => 'Diesel 2', 'tankers' => [5, 6], 'price_key' => 'diesel']
                ],
                'kerosene' => [
                    ['name' => 'Kerosene', 'tankers' => [1], 'price_key' => 'kerosene']
                ]
            ];
            
            $rendered_config_keys_forms = []; // Track already-rendered config keys to avoid duplicates
            foreach ($fuel_types as $idx => $ft):
                $ft_name_form = htmlspecialchars($ft['fuel_type']);
                $ft_lower = strtolower(trim($ft['fuel_type']));
                
                // Get tanker configuration for this fuel type
                $config_groups_forms = null;
                $matched_key_forms = null;
                foreach ($tanker_config_forms as $key => $groups) {
                    if (str_contains($ft_lower, $key)) {
                        $config_groups_forms = $groups;
                        $matched_key_forms = $key;
                        break;
                    }
                }
                
                // Skip if this config key was already rendered (prevents duplicates when
                // fuel_inventory has both generic e.g. "Diesel" AND specific e.g. "Diesel 1","Diesel 2")
                if ($matched_key_forms !== null) {
                    if (in_array($matched_key_forms, $rendered_config_keys_forms)) {
                        continue; // already rendered this group, skip
                    }
                    $rendered_config_keys_forms[] = $matched_key_forms;
                }
                
                // If no config found, create default single tanker
                if (!$config_groups_forms) {
                    $config_groups_forms = [
                        ['name' => $ft['fuel_type'], 'tankers' => [1], 'price_key' => $ft_lower]
                    ];
                }
                
                // Loop through each group and create forms for each tanker
                foreach ($config_groups_forms as $group):
                    $group_name = $group['name'];
                    $tankers = $group['tankers'];
                    
                    foreach ($tankers as $tanker_num):
                        $ft_id = 'fuel_' . preg_replace('/[^a-z0-9]/i', '_', $group_name) . '_' . $idx . '_t' . $tanker_num;
            ?>
            <form id="fuelForm_<?= $ft_id ?>"
                  method="POST"
                  action="api_fuel_readings.php"
                  onsubmit="return submitFuelCard(event, '<?= $ft_id ?>')"
                  style="display:none;">
                <input type="hidden" name="action"           value="encode_reading">
                <input type="hidden" name="api_token"        value="<?= htmlspecialchars($_api_token) ?>">
                <input type="hidden" name="auth_user_id"     value="<?= (int)$me['id'] ?>">
                <input type="hidden" name="shift_id"         value="<?= (int)($current_shift['id'] ?? 0) ?>">
                <input type="hidden" name="staff_id"         value="<?= (int)$me['id'] ?>">
                <input type="hidden" name="station_id"       value="<?= (int)$station_id ?>">
                <input type="hidden" name="fuel_type"        value="<?= $ft_name_form ?>">
                <input type="hidden" name="tanker_number"    value="<?= $tanker_num ?>">
                <input type="hidden" name="pump_label"       value="<?= htmlspecialchars(strtoupper($group_name) . ' - ' . $tanker_num) ?>">
                <input type="hidden" name="shift_period"     value="<?= htmlspecialchars($fuel_shift_key) ?>">
                <input type="hidden" name="shift_name"       value="<?= htmlspecialchars($fuel_shift_name) ?>">
                <input type="hidden" name="reading_date"     value="<?= date('Y-m-d') ?>">
            </form>
            <?php 
                    endforeach; // End tanker loop
                endforeach; // End group loop
            endforeach; // End fuel type loop
            ?>

            <div class="fet-wrap" style="overflow:hidden;">
                <table class="fet" style="width:100%;border-collapse:collapse;table-layout:fixed;">
                    <colgroup>
                        <col style="width:16%;"><!-- NAME -->
                        <col style="width:14%;"><!-- BEGINNING -->
                        <col style="width:14%;"><!-- ENDING -->
                        <col style="width:12%;"><!-- CALIBRATION -->
                        <col style="width:14%;"><!-- PRICE / LITER -->
                        <col style="width:15%;"><!-- NET VOLUME SOLD -->
                        <col style="width:15%;"><!-- TOTAL AMOUNT -->
                    </colgroup>
                    <thead>
                        <tr style="background:#002F70;">
                            <th rowspan="2" style="border:1px solid #001f4d;padding:12px;vertical-align:middle;font-weight:700;font-size:13px;color:#fff;">NAME</th>
                            <th colspan="6" style="border:1px solid #001f4d;padding:8px;text-align:center;font-size:14px;font-weight:700;color:#fff;">METER READING</th>
                        </tr>
                        <tr style="background:#002F70;">
                            <th style="border:1px solid #001f4d;padding:8px;text-align:center;font-size:11px;font-weight:700;color:#fff;" title="Auto-fetched if previous record exists, or manual input for first shift.">BEGINNING<br><span style="font-size:9px;font-weight:400;color:#86efac;">(Auto / Manual)</span></th>
                            <th style="border:1px solid #001f4d;padding:8px;text-align:center;font-size:11px;font-weight:700;color:#fff;">ENDING <span style="color:#f87171;">*</span><br><span style="font-size:9px;font-weight:400;color:#93c5fd;">(Required)</span></th>
                            <th style="border:1px solid #001f4d;padding:8px;text-align:center;font-size:11px;font-weight:700;color:#fff;">CALIBRATION<br><span style="font-size:9px;font-weight:400;color:#93c5fd;">(Default 0.00)</span></th>
                            <th style="border:1px solid #001f4d;padding:8px;text-align:center;font-size:11px;font-weight:700;color:#fff;">PRICE / LITER<br><span style="font-size:9px;font-weight:400;color:#93c5fd;">(Auto)</span></th>
                            <th style="border:1px solid #001f4d;padding:8px;text-align:center;font-size:11px;font-weight:700;color:#fff;">NET VOLUME SOLD<br><span style="font-size:9px;font-weight:400;color:#93c5fd;">(Auto)</span></th>
                            <th style="border:1px solid #001f4d;padding:8px;text-align:center;font-size:11px;font-weight:700;color:#fff;">TOTAL AMOUNT<br><span style="font-size:9px;font-weight:400;color:#93c5fd;">(Auto)</span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php 
                    // Tanker configuration per fuel type - THIS CONTROLS THE DISPLAY
                    // ORDER MATTERS: Check longer/more specific names first to avoid partial matches
                    // 5 fuel types, 17 total pumps/tankers
                    $tanker_config = [
                        'xcs plus' => [
                            ['name' => 'XCS Plus', 'tankers' => [1, 2, 3, 4], 'price_key' => 'xcs plus']
                        ],
                        'turbo diesel' => [
                            ['name' => 'Turbo Diesel', 'tankers' => [1, 2], 'price_key' => 'turbo diesel']
                        ],
                        'xtra unl' => [
                            ['name' => 'XTRA UNL 1', 'tankers' => [1, 2], 'price_key' => 'xtra unl'],
                            ['name' => 'XTRA UNL 2', 'tankers' => [3, 4], 'price_key' => 'xtra unl']
                        ],
                        'diesel' => [
                            ['name' => 'Diesel 1', 'tankers' => [1, 2, 3, 4], 'price_key' => 'diesel'],
                            ['name' => 'Diesel 2', 'tankers' => [5, 6], 'price_key' => 'diesel']
                        ],
                        'kerosene' => [
                            ['name' => 'Kerosene', 'tankers' => [1], 'price_key' => 'kerosene']
                        ]
                    ];
                    
                    $rendered_config_keys_table = []; // Track already-rendered config keys to avoid duplicates
                    foreach ($fuel_types as $idx => $ft):
                        $ft_lower = strtolower(trim($ft['fuel_type']));
                        $price_per_liter = (float)$ft['price_per_liter'];
                        
                        // Get tanker configuration for this fuel type
                        $config_groups = null;
                        $matched_key_table = null;
                        foreach ($tanker_config as $key => $groups) {
                            if (str_contains($ft_lower, $key)) {
                                $config_groups = $groups;
                                $matched_key_table = $key;
                                break;
                            }
                        }
                        
                        // Skip if this config key was already rendered (prevents duplicates from
                        // having both generic e.g. "Diesel" and specific e.g. "Diesel 1", "Diesel 2" in fuel_inventory)
                        if ($matched_key_table !== null) {
                            if (in_array($matched_key_table, $rendered_config_keys_table)) {
                                continue; // already rendered this group, skip
                            }
                            $rendered_config_keys_table[] = $matched_key_table;
                        }
                        
                        // If no config found, create default single tanker
                        if (!$config_groups) {
                            $config_groups = [
                                ['name' => $ft['fuel_type'], 'tankers' => [1], 'price_key' => $ft_lower]
                            ];
                        }
                        
                        // Loop through each group (e.g., Diesel 1 group, Diesel 2 group)
                        foreach ($config_groups as $group):
                            $group_name = $group['name'];
                            $tankers = $group['tankers'];
                            
                            // Color selection
                            $ft_color = '#334155';
                            $ft_icon = 'fa-gas-pump';
                            if (str_contains($ft_lower, 'diesel')) { $ft_color = '#003d7a'; }
                            elseif (str_contains($ft_lower, 'kerosene')) { $ft_color = '#b45309'; $ft_icon = 'fa-fire'; }
                            elseif (str_contains($ft_lower, 'xcs')) { $ft_color = '#0369a1'; }
                            elseif (str_contains($ft_lower, 'xtra')) { $ft_color = '#15803d'; }
                            elseif (str_contains($ft_lower, 'turbo')) { $ft_color = '#7c3aed'; }
                            
                            // Create a row for EACH tanker in this group
                            foreach ($tankers as $tanker_num):
                                $ft_id = 'fuel_' . preg_replace('/[^a-z0-9]/i', '_', $group_name) . '_' . $idx . '_t' . $tanker_num;
                                $display_name = strtoupper($group_name) . ' - ' . $tanker_num;

                                // ── Validated shift carry-over lookup ──
                                $lbl_key   = strtoupper(trim($display_name));
                                $saved_row = $today_saved_readings[$lbl_key] ?? null;

                                if (in_array($current_shift_status, ['CLOSING_COMPLETED', 'SAVED', 'REPORTED'])) {
                                    // Shift closing completed! Use completed present_reading as new beginning reading, reset ending inputs
                                    $pump_prev_reading = ($saved_row && (float)$saved_row['present_reading'] > 0)
                                        ? (float)$saved_row['present_reading']
                                        : (isset($last_readings_by_pump[$lbl_key]) ? (float)$last_readings_by_pump[$lbl_key] : null);
                                    $saved_ending_val = '';
                                    $saved_calib_val  = '0.00';
                                } else {
                                    $pump_prev_reading = ($saved_row && isset($saved_row['previous_reading']) && (float)$saved_row['previous_reading'] > 0)
                                        ? (float)$saved_row['previous_reading']
                                        : (isset($last_readings_by_pump[$lbl_key]) ? (float)$last_readings_by_pump[$lbl_key] : null);
                                    $saved_ending_val = ($saved_row && (float)$saved_row['present_reading'] > 0) ? number_format((float)$saved_row['present_reading'], 2, '.', ',') : '';
                                    $saved_calib_val  = ($saved_row && isset($saved_row['calibration'])) ? number_format((float)$saved_row['calibration'], 2, '.', ',') : '0.00';
                                }
                                $has_prev_reading = ($pump_prev_reading !== null && (float)$pump_prev_reading > 0);
                    ?>
                    <tr id="fuelRow_<?= $ft_id ?>" style="border-bottom:1px solid #e2e8f0;">
                        <!-- NAME Column (plain text, no icon) -->
                        <td style="border:1px solid #e2e8f0;padding:10px;">
                            <span style="font-weight:700;font-size:12px;color:#1e293b;"><?= $display_name ?></span>
                        </td>

                        <!-- BEGINNING Column — Auto-fetched (Read-only) if previous record exists, or Manual Input (Editable) for first shift -->
                        <td style="border:1px solid #e2e8f0;padding:6px;">
                            <?php if ($has_prev_reading): ?>
                                <input type="text"
                                       form="fuelForm_<?= $ft_id ?>"
                                       name="beginning_reading"
                                       id="beginning_<?= $ft_id ?>"
                                       style="width:100%;box-sizing:border-box;padding:8px;font-size:12px;border:1px solid #86efac;border-radius:4px;text-align:right;background:#f0fdf4;font-weight:700;color:#15803d;cursor:not-allowed;"
                                       value="<?= number_format((float)$pump_prev_reading, 2, '.', ',') ?>"
                                       readonly
                                       title="Auto-fetched from previous meter reading (<?= number_format((float)$pump_prev_reading, 2, '.', ',') ?>). Read-only."
                                       data-pump="<?= htmlspecialchars($display_name) ?>">
                            <?php else: ?>
                                <!-- Initial meter reading / first shift — Editable manual input -->
                                <input type="text"
                                       form="fuelForm_<?= $ft_id ?>"
                                       name="beginning_reading"
                                       id="beginning_<?= $ft_id ?>"
                                       style="width:100%;box-sizing:border-box;padding:8px;font-size:12px;border:1px solid #3b82f6;border-radius:4px;text-align:right;background:#ffffff;font-weight:600;color:#1e293b;"
                                       value=""
                                       placeholder="Enter beginning reading..."
                                       autocomplete="off"
                                       oninput="formatOnInput(this); updateFuelCalc('<?= $ft_id ?>')"
                                       onblur="formatOnBlur(this); updateFuelCalc('<?= $ft_id ?>')"
                                       onkeydown="handleMeterKeydown(event, this)"
                                       onfocus="this.select()"
                                       title="First shift / No previous record: Enter beginning meter reading manually."
                                       data-pump="<?= htmlspecialchars($display_name) ?>">
                            <?php endif; ?>
                        </td>

                        <!-- ENDING Column * -->
                        <td style="border:1px solid #e2e8f0;padding:6px;">
                            <input type="text"
                                   form="fuelForm_<?= $ft_id ?>"
                                   name="ending_reading"
                                   id="ending_<?= $ft_id ?>"
                                   style="width:100%;box-sizing:border-box;padding:8px;font-size:12px;border:2px solid #3b82f6;border-radius:4px;text-align:right;font-weight:700;"
                                   value="<?= htmlspecialchars($saved_ending_val) ?>"
                                   placeholder="0.00"
                                   required
                                   autocomplete="off"
                                   oninput="formatOnInput(this); updateFuelCalc('<?= $ft_id ?>')"
                                   onblur="formatOnBlur(this); updateFuelCalc('<?= $ft_id ?>')"
                                   onkeydown="handleMeterKeydown(event, this)"
                                   onfocus="this.select()"
                                   title="Required: Enter the Ending meter reading">
                        </td>

                        <!-- CALIBRATION Column (Default = 0.00) -->
                        <td style="border:1px solid #e2e8f0;padding:6px;">
                            <input type="text"
                                   form="fuelForm_<?= $ft_id ?>"
                                   name="calibration"
                                   id="cal_<?= $ft_id ?>"
                                   style="width:100%;box-sizing:border-box;padding:8px;font-size:12px;border:1px solid #cbd5e1;border-radius:4px;text-align:right;"
                                   value="<?= htmlspecialchars($saved_calib_val) ?>"
                                   placeholder="0.00"
                                   autocomplete="off"
                                   title="Calibration correction (default 0.00). Cannot exceed Gross Volume."
                                   oninput="formatOnInput(this); updateFuelCalc('<?= $ft_id ?>')"
                                   onblur="formatOnBlur(this); updateFuelCalc('<?= $ft_id ?>')"
                                   onkeydown="handleMeterKeydown(event, this)"
                                   onfocus="this.select()"
                                   min="0">
                        </td>

                        <!-- PRICE / LITER Column (Auto — read-only, from fuel_inventory) -->
                        <td style="border:1px solid #e2e8f0;padding:6px;background:#f8fafc;">
                            <span id="price_display_<?= $ft_id ?>"
                                  style="display:block;width:100%;box-sizing:border-box;padding:8px;font-size:13px;font-weight:700;color:#334155;text-align:right;">
                                ₱<?= number_format($price_per_liter, 2) ?>
                            </span>
                            <input type="hidden"
                                   form="fuelForm_<?= $ft_id ?>"
                                   name="price_per_liter"
                                   id="price_<?= $ft_id ?>"
                                   value="<?= number_format($price_per_liter, 2, '.', '') ?>">
                        </td>

                        <!-- NET VOLUME SOLD Column (Auto-calculated: (Ending - Beginning) - Calibration) -->
                        <td style="border:1px solid #e2e8f0;padding:6px;background:#f0fdf4;">
                            <input type="text"
                                   id="volume_<?= $ft_id ?>"
                                   style="width:100%;box-sizing:border-box;padding:8px;font-size:12px;background:transparent;border:1px solid #86efac;border-radius:4px;text-align:right;font-weight:700;color:#15803d;"
                                   value="0.00"
                                   readonly
                                   title="Net Volume Sold = (Ending - Beginning) - Calibration">
                            <input type="hidden"
                                   form="fuelForm_<?= $ft_id ?>"
                                   name="volume_liters"
                                   id="volume_value_<?= $ft_id ?>"
                                   value="0.00">
                        </td>

                        <!-- TOTAL AMOUNT Column (Auto-calculated: Net Volume × Price/Liter) -->
                        <td style="border:1px solid #e2e8f0;padding:6px;background:#eff6ff;">
                            <input type="text"
                                   id="amount_<?= $ft_id ?>"
                                   style="width:100%;box-sizing:border-box;padding:8px;font-size:12px;background:transparent;border:1px solid #93c5fd;border-radius:4px;text-align:right;font-weight:800;color:#1d4ed8;"
                                   value="₱0.00"
                                   readonly
                                   title="Total Amount = Net Volume Sold × Price per Liter">
                            <input type="hidden"
                                   form="fuelForm_<?= $ft_id ?>"
                                   name="total_amount"
                                   id="amount_value_<?= $ft_id ?>"
                                   value="0.00">
                        </td>
                    </tr>
                    <?php 
                        endforeach; // End tanker loop
                        endforeach; // End group loop
                    endforeach; // End fuel type loop
                    ?>
                    </tbody>
                </table>
            </div><!-- /fet-wrap -->

            <?php
            // ── Emit JS array of all ft_ids for page-load init ──
            $all_ft_ids_js = [];
            $rendered_for_js = [];
            foreach ($fuel_types as $idx_js => $ft_js) {
                $ft_lower_js = strtolower(trim($ft_js['fuel_type']));
                $cfg_js = null; $key_js = null;
                foreach ($tanker_config as $k => $g) {
                    if (str_contains($ft_lower_js, $k)) { $cfg_js = $g; $key_js = $k; break; }
                }
                if ($key_js !== null) {
                    if (in_array($key_js, $rendered_for_js)) continue;
                    $rendered_for_js[] = $key_js;
                }
                if (!$cfg_js) $cfg_js = [['name' => $ft_js['fuel_type'], 'tankers' => [1]]];
                foreach ($cfg_js as $grp_js) {
                    foreach ($grp_js['tankers'] as $tn_js) {
                        $all_ft_ids_js[] = 'fuel_' . preg_replace('/[^a-z0-9]/i', '_', $grp_js['name']) . '_' . $idx_js . '_t' . $tn_js;
                    }
                }
            }
            ?>
            <!-- ═══ STANDALONE FUEL CALC SCRIPT — no external dependencies ═══ -->
            <script>
            (function() {
                'use strict';

                /* ── Parse a formatted number string (may have commas) ── */
                function parseNum(str) {
                    return parseFloat((str || '').toString().replace(/,/g, '')) || 0;
                }

                /* ── Format a number with 2 decimal places + thousand commas ── */
                function fmtNum(n) {
                    return n.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
                }

                /* ── Core calculation for one row ── */
                function calcRow(ftId) {
                    var bEl  = document.getElementById('beginning_' + ftId);
                    var eEl  = document.getElementById('ending_'    + ftId);
                    var cEl  = document.getElementById('cal_'       + ftId);
                    var pEl  = document.getElementById('price_'     + ftId);
                    var vEl  = document.getElementById('volume_'    + ftId);
                    var vvEl = document.getElementById('volume_value_' + ftId);
                    var aEl  = document.getElementById('amount_'    + ftId);
                    var avEl = document.getElementById('amount_value_' + ftId);
                    var msgEl = document.getElementById('cardMsg_'  + ftId);

                    if (!bEl || !eEl || !cEl || !pEl || !vEl || !aEl) return;

                    var beginning = parseNum(bEl.value);
                    var ending    = parseNum(eEl.value);
                    var cal       = parseNum(cEl.value);
                    var price     = parseNum(pEl.value);

                    /* ── Validation ── */
                    var errMsg = '';
                    var hasEndingVal = eEl.value.trim() !== '';
                    if (hasEndingVal && ending < beginning) {
                        errMsg = 'Ending Reading cannot be lower than Beginning Reading.';
                        eEl.style.borderColor = '#dc2626';
                    } else {
                        eEl.style.borderColor = '';
                    }
                    if (cal < 0) {
                        errMsg = errMsg || 'Calibration cannot be negative.';
                        cEl.style.borderColor = '#dc2626';
                    } else {
                        cEl.style.borderColor = '';
                    }
                    if (msgEl && errMsg) {
                        msgEl.style.cssText = 'display:block;background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;border-radius:6px;padding:8px 12px;font-size:11px;font-weight:600;margin-top:5px;';
                        msgEl.textContent = errMsg;
                    } else if (msgEl && msgEl.textContent && (msgEl.textContent.indexOf('lower than') !== -1 || msgEl.textContent.indexOf('negative') !== -1)) {
                        msgEl.style.display = 'none';
                        msgEl.textContent = '';
                    }

                    /* ── Formulas ── */
                    /* Net Fuel Dispensed = Ending - Beginning - Calibration */
                    var net    = ending - beginning - cal;
                    var volume = net > 0 ? net : 0;
                    /* Fuel Sales Amount = Net Volume × Price/Liter */
                    var amount = volume * price;

                    /* ── Update display fields ── */
                    vEl.value = fmtNum(volume);
                    if (vvEl) vvEl.value = volume.toFixed(2);
                    aEl.value = '\u20b1' + fmtNum(amount);
                    if (avEl) avEl.value = amount.toFixed(2);
                }

                /* ── Attach listeners to all fuel inputs ── */
                function attachAll() {
                    /* Find all ending_fuel_* and cal_fuel_* inputs */
                    var allEnding = document.querySelectorAll('input[id^="ending_fuel_"]');
                    var allCal    = document.querySelectorAll('input[id^="cal_fuel_"]');
                    var allBeg    = document.querySelectorAll('input[id^="beginning_fuel_"]:not([readonly])');

                    function makeHandler(inp) {
                        return function() {
                            var m = inp.id.match(/^(?:ending|beginning|cal)_(.+)$/);
                            if (m) calcRow(m[1]);
                        };
                    }

                    [].slice.call(allEnding).concat([].slice.call(allCal)).concat([].slice.call(allBeg)).forEach(function(inp) {
                        if (inp._fuelCalcAttached) return;
                        inp._fuelCalcAttached = true;
                        var fn = makeHandler(inp);
                        inp.addEventListener('input',  fn);
                        inp.addEventListener('change', fn);
                        inp.addEventListener('keyup',  fn);
                    });

                    /* Run initial calc for each row (for auto-fetched beginning values) */
                    [].slice.call(allEnding).concat([].slice.call(allBeg)).forEach(function(inp) {
                        if (typeof window.formatOnBlur === 'function' && inp.value && inp.value.trim() !== '') {
                            window.formatOnBlur(inp);
                        }
                        var m = inp.id.match(/^(?:ending|beginning)_(.+)$/);
                        if (m) calcRow(m[1]);
                    });
                }

                /* ── Expose globally so inline oninput handlers can call it ── */
                window.updateFuelCalc = function(ftId) { calcRow(ftId); };

                /* ── Boot ── */
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', attachAll);
                } else {
                    attachAll();
                }
                window.addEventListener('load', attachAll);

            })();
            </script>
            <!-- ═══ end STANDALONE FUEL CALC SCRIPT ═══ -->

            <!-- Submit/Reset/Closing Buttons -->
            <div style="display:flex; justify-content:flex-end; align-items:center; gap:12px; margin-top:20px; margin-bottom:0; padding:10px 0; background:transparent; border:none; box-shadow:none;">
                <button type="button"
                        onclick="resetAllFuelRows()"
                        class="fet-reset-btn">
                    <i class="fas fa-undo"></i> Reset All
                </button>
                <?php if (!empty($has_submitted_readings_unclosed)): ?>
                <a href="staff_fuel_sales_closing.php?date=<?= date('Y-m-d') ?>&shift=<?= urlencode($fuel_shift_name) ?>"
                   style="background:#002F70; color:#ffffff; padding:10px 20px; border:none; border-radius:6px; font-weight:700; font-size:13px; cursor:pointer; display:inline-flex; align-items:center; gap:8px; text-decoration:none; box-shadow:0 2px 6px rgba(0,47,112,0.25); transition:all 0.2s;"
                   onmouseover="this.style.background='#001f4d'"
                   onmouseout="this.style.background='#002F70'"
                   title="Meter readings submitted. Click to continue to Fuel Sales Closing form">
                    <i class="fas fa-calculator"></i> Fuel Sales Closing
                </a>
                <?php endif; ?>
                <button type="button"
                        onclick="submitAllFuelRows()"
                        class="fet-submit-btn">
                    <i class="fas fa-paper-plane"></i> Submit All Readings
                </button>
            </div><!-- /button container -->
            
            <!-- Scroll space for encode card -->
            <div style="height: 40px; clear: both;" aria-hidden="true"></div>
        </div><!-- /txn-card encodeCard -->

        <!-- Global Remarks Modal -->
        <div id="globalRemarksModal" class="modal">
            <div class="modal-card" style="max-width:500px;">
                <div class="modal-head">
                    <div style="display:flex;align-items:center;">
                        <div class="modal-icon"><i class="fas fa-comment-alt"></i></div>
                        <div>
                            <div class="modal-title">Shift Remarks</div>
                            <div class="modal-subtitle">Add notes for this fuel shift (applies to all fuel types)</div>
                        </div>
                    </div>
                    <button class="modal-close" onclick="closeGlobalRemarksModal()" style="background:transparent !important; border:none !important; color:#64748b !important; font-size:22px !important; cursor:pointer !important; padding:4px 8px !important; line-height:1 !important; border-radius:4px !important;" title="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <label>REMARKS / NOTES</label>
                    <textarea id="globalRemarksTextarea" class="input" rows="5" placeholder="Enter any remarks or notes for this shift (e.g., 'No sales due to power outage', 'Low customer traffic', etc.)&#10;&#10;This remark will be saved with all fuel transactions for this shift."></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" onclick="closeGlobalRemarksModal()" style="background:transparent !important; border:1px solid #cbd5e1 !important; color:#334155 !important; font-weight:600 !important; padding:8px 18px !important; border-radius:6px !important; cursor:pointer !important; font-size:14px !important; box-shadow:none !important;">Cancel</button>
                    <button type="button" class="flt-btn flt-btn-solid-primary" onclick="saveGlobalRemarks()"><i class="fas fa-save"></i> Save Remarks</button>
                </div>
            </div>
        </div>

        <?php endif; ?>

        <!-- ── TODAY'S ENTRIES — Meter Reading History ──────────── -->
        <div class="txn-card" id="todayEntriesCard" style="margin-top:0; margin-bottom:40px; background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);">
            <div class="txn-card-header" style="background:#fff; border-bottom:1.5px solid #e2e8f0; border-top-left-radius:12px; border-top-right-radius:12px; padding: 16px 20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                <div style="display:flex; align-items:center; gap:12px;">
                    <i class="fas fa-history" style="color:var(--petron-blue); font-size:18px;"></i>
                    <h3 style="font-size:16px; font-weight:700; color:#0f172a; margin:0;">Meter Reading History</h3>
                </div>
                <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                    <button type="button" onclick="window.location.href='staff_dashboard.php'" 
                            class="txn-btn secondary" title="Back to Staff Dashboard">
                        <i class="fas fa-arrow-left"></i> <span>Back</span>
                    </button>
                </div>
            </div>

            <!-- Summary Cards Row -->
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:16px; padding:20px 20px 0 20px; background:#ffffff;">
                <!-- Card 1: Transactions Encoded -->
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:18px; display:flex; align-items:center; gap:16px; transition: transform 0.2s, box-shadow 0.2s; cursor:default;" 
                     onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(0,0,0,0.05)';" 
                     onmouseout="this.style.transform='none'; this.style.boxShadow='none';">
                    <div style="background:#eff6ff; color:#2563eb; width:48px; height:48px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0;">
                        <i class="fas fa-file-invoice"></i>
                    </div>
                    <div>
                        <div id="summary_encoded_count" style="font-size:26px; font-weight:800; color:#0f172a; line-height:1.2; font-family:var(--font-sans, sans-serif);">0</div>
                        <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.8px; margin-top:2px;">Transactions Encoded</div>
                    </div>
                </div>

                <!-- Card 2: Pending Manager Validation -->
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:18px; display:flex; align-items:center; gap:16px; transition: transform 0.2s, box-shadow 0.2s; cursor:default;"
                     onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(0,0,0,0.05)';" 
                     onmouseout="this.style.transform='none'; this.style.boxShadow='none';">
                    <div style="background:#fffbeb; color:#d97706; width:48px; height:48px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0;">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div>
                        <div id="summary_pending_count" style="font-size:26px; font-weight:800; color:#0f172a; line-height:1.2; font-family:var(--font-sans, sans-serif);">0</div>
                        <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.8px; margin-top:2px;">Pending Validation</div>
                    </div>
                </div>
            </div>

            <!-- Filters & Export Control Bar -->
            <div style="background:#ffffff; padding:20px; display:flex; flex-wrap:wrap; align-items:flex-end; justify-content:space-between; gap:16px; border-bottom:1px solid #f1f5f9;">
                <!-- Local Filter Inputs -->
                <div style="display:flex; flex-wrap:wrap; gap:16px; align-items:center;">
                    <!-- From Date Filter -->
                    <div style="display:flex; flex-direction:column; gap:6px;">
                        <label for="subtab_date_from" style="font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:0.5px;">
                            <i class="fas fa-calendar-alt" style="color:var(--petron-blue); margin-right:4px;"></i>From Date
                        </label>
                        <input type="date" id="subtab_date_from" value="<?= date('Y-m-01') ?>" onchange="loadTodayEntries();"
                               style="padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px; color:#0f172a; outline:none; background:#ffffff; height:38px; box-sizing:border-box; transition:border-color 0.15s ease-in-out;"
                               onfocus="this.style.borderColor='var(--petron-blue)'" onblur="this.style.borderColor='#cbd5e1'">
                    </div>

                    <!-- To Date Filter -->
                    <div style="display:flex; flex-direction:column; gap:6px;">
                        <label for="subtab_date_to" style="font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:0.5px;">
                            <i class="fas fa-calendar-alt" style="color:var(--petron-blue); margin-right:4px;"></i>To Date
                        </label>
                        <input type="date" id="subtab_date_to" value="<?= date('Y-m-d') ?>" onchange="loadTodayEntries();"
                               style="padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px; color:#0f172a; outline:none; background:#ffffff; height:38px; box-sizing:border-box; transition:border-color 0.15s ease-in-out;"
                               onfocus="this.style.borderColor='var(--petron-blue)'" onblur="this.style.borderColor='#cbd5e1'">
                    </div>

                    <!-- Shift Filter -->
                    <div style="display:flex; flex-direction:column; gap:6px;">
                        <label for="subtab_shift" style="font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:0.5px;">
                            <i class="fas fa-clock" style="color:var(--petron-blue); margin-right:4px;"></i>Filter by Shift
                        </label>
                        <select id="subtab_shift" onchange="loadTodayEntries();"
                                style="padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px; color:#0f172a; outline:none; background:#ffffff; height:38px; box-sizing:border-box; cursor:pointer; transition:border-color 0.15s ease-in-out;"
                                onfocus="this.style.borderColor='var(--petron-blue)'" onblur="this.style.borderColor='#cbd5e1'">
                            <option value="">All Shifts</option>
                            <option value="first">First Shift (6 AM–2 PM)</option>
                            <option value="second">Second Shift (2 PM–12 MN)</option>
                        </select>
                    </div>
                </div>

            <!-- Table Body Container -->
            <div id="todayEntriesBody" style="padding:0;">
                <div style="text-align:center; padding:40px; color:#94a3b8; font-size:14px;">
                    <i class="fas fa-spinner fa-spin" style="font-size:24px; display:block; margin-bottom:12px; color:var(--petron-blue);"></i>
                    Loading today's entries…
                </div>
            </div>
        </div><!-- /txn-card todayEntriesCard -->

        <script>
        // ── Fuel Sub-tab Switcher ─────────────────────────────────────────────
        // Shows only the active card; hides the other.
        function switchFuelSubTab(tab) {
            var isReadings = (tab === 'readings');
            var encodeCard  = document.getElementById('encodeCard');
            var todayCard   = document.getElementById('todayEntriesCard');
            var encodeBtn   = document.getElementById('fuelSubTabBtn_encode');
            var readingsBtn = document.getElementById('fuelSubTabBtn_readings');
            if (!encodeBtn || !readingsBtn) return;

            // Show/hide the cards
            if (encodeCard)  encodeCard.style.display  = isReadings ? 'none'  : 'block';
            if (todayCard)   todayCard.style.display   = isReadings ? 'block' : 'none';

            // Update tab button styles
            encodeBtn.className  = 'txn-subtab-btn blue ' + (isReadings ? 'inactive' : 'active');
            readingsBtn.className = 'txn-subtab-btn blue ' + (isReadings ? 'active'   : 'inactive');

            // Load history when switching to readings tab
            if (isReadings) refreshTodayEntries();

            // Persist active tab in URL without reload
            if (window.history && window.history.replaceState) {
                var url = new URL(window.location.href);
                if (isReadings) {
                    url.searchParams.set('fuel_tab', 'readings');
                } else {
                    url.searchParams.delete('fuel_tab');
                }
                window.history.replaceState(null, '', url);
            }
        }
        window.switchFuelSubTab = switchFuelSubTab;

        // Initialize correct tab on page load
        document.addEventListener('DOMContentLoaded', function() {
            var defaultTab = '<?= $fuel_tab_default ?>';
            switchFuelSubTab(defaultTab);
        });

        // ── Fuel Transaction Filters ───────────────────────────────────────────
        function resetFuelFilters() {
            const todayCard = document.getElementById('todayEntriesCard');
            const isReadings = todayCard && todayCard.style.display !== 'none';
            if (isReadings) {
                window.location.href = 'staff_transactions_hub.php?section=fuel&fuel_tab=readings';
            } else {
                window.location.href = 'staff_transactions_hub.php?section=fuel&fuel_tab=encode';
            }
        }

        // Helper functions for dynamic comma-formatting as user types
        function formatOnInput(input) {
            var val = input.value.replace(/[^\d.]/g, '');
            var parts = val.split('.');
            if (parts.length > 2) parts = [parts[0], parts.slice(1).join('')];
            var intPart = parts[0] ? parseInt(parts[0], 10).toLocaleString('en-US') : '';
            input.value = parts.length > 1 ? intPart + '.' + parts[1] : intPart;
        }
        window.formatOnInput = formatOnInput;

        function formatOnBlur(input) {
            if (!input) return;
            let val = input.value.replace(/,/g, '');
            let num = parseFloat(val);
            if (!isNaN(num)) {
                let dec = 2;
                let parts = val.split('.');
                if (parts.length > 1 && parts[1].length > 2) dec = Math.min(parts[1].length, 3);
                input.value = num.toLocaleString('en-US', {minimumFractionDigits: dec, maximumFractionDigits: 3});
            } else if (input.id && input.id.indexOf('cal_') === 0) {
                input.value = '0.00';
            } else {
                input.value = '';
            }
        }
        window.formatOnBlur = formatOnBlur;

        function formatAllFuelInputs() {
            document.querySelectorAll('input[id^="beginning_"], input[id^="ending_"], input[id^="cal_"]').forEach(function(inp) {
                if (inp && inp.value && inp.value.trim() !== '') {
                    formatOnBlur(inp);
                }
            });
        }
        window.formatAllFuelInputs = formatAllFuelInputs;
        document.addEventListener('DOMContentLoaded', formatAllFuelInputs);
        window.addEventListener('load', formatAllFuelInputs);

        // ── AJAX submit per fuel row (Updated for tanker data) ──────────────────────────────────────────
        async function submitFuelCard(event, ftId) {
            event.preventDefault();

            const form      = document.getElementById('fuelForm_' + ftId);
            const submitBtn = document.getElementById('submitBtn_' + ftId);
            const msgEl     = document.getElementById('cardMsg_'   + ftId);

            if (!form) return false;

            // Build FormData from the form first
            const formData = new FormData(form);

            // Add global remarks to this fuel transaction
            const globalRemarks = document.getElementById('globalFuelRemarks').value.trim();
            formData.set('notes', globalRemarks);

            // ── Beginning reading validation for manual input (first shift) ──
            const beginningEl = document.getElementById('beginning_' + ftId);
            let beginningRaw = ((formData.get('beginning_reading') !== null ? formData.get('beginning_reading') : (beginningEl ? beginningEl.value : '')) || '').replace(/,/g, '');

            // If first-shift editable and empty, default to 0 (valid for new pump / first entry)
            if (beginningEl && !beginningEl.readOnly && (beginningRaw === '' || isNaN(parseFloat(beginningRaw)))) {
                beginningRaw = '0';
                formData.set('beginning_reading', '0');
                if (beginningEl) beginningEl.value = '0.00';
            }

            // Validate: ending_reading must be filled (strip commas before parsing)
            const endingRaw = (formData.get('ending_reading') || '').replace(/,/g, '');
            const endingVal = parseFloat(endingRaw);
            if (!endingVal || endingVal <= 0) {
                showRowMsg(msgEl, 'error', 'Please enter the Ending meter reading.');
                return false;
            }
            // Inject stripped values back so API receives plain numbers
            formData.set('ending_reading', endingRaw);
            formData.set('beginning_reading', beginningRaw);
            const beginningVal = parseFloat(beginningRaw) || 0;

            // Validation Rule 1: Ending Reading must not be less than Beginning Reading
            // Note: Ending can equal Beginning (volume = 0), which is valid
            if (endingVal < beginningVal) {
                showRowMsg(msgEl, 'error', 'Ending Reading cannot be lower than Beginning Reading.');
                return false;
            }

            const calibrationRaw = (formData.get('calibration') || '0').replace(/,/g, '');
            formData.set('calibration', calibrationRaw);
            const calibrationVal = parseFloat(calibrationRaw) || 0;

            // Validation Rule 2: Calibration ≥ 0
            if (calibrationVal < 0) {
                showRowMsg(msgEl, 'error', 'Calibration value cannot be negative.');
                return false;
            }

            // Validation Rule 3: Calibration cannot exceed Gross Volume
            const grossVol = endingVal - beginningVal;
            if (calibrationVal > grossVol && grossVol > 0) {
                showRowMsg(msgEl, 'error', `Calibration (${calibrationVal.toFixed(2)}L) cannot be greater than Gross Volume (${grossVol.toFixed(2)}L).`);
                return false;
            }

            // Disable button, show loading
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting…';
            showRowMsg(msgEl, '', '');

            try {
                const targetUrl = window.location.pathname.replace(/[^\\/]+$/, '') + 'api_fuel_readings.php';
                const res  = await fetch(targetUrl, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                });

                const raw = await res.text().catch(() => '');
                let json;
                try {
                    const jsonStart = raw.indexOf('{"success"');
                    const jsonStr   = jsonStart >= 0 ? raw.slice(jsonStart) : raw;
                    json = JSON.parse(jsonStr);
                } catch (parseErr) {
                    const preview = raw.length > 0
                        ? raw.substring(0, 400).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                        : '(empty response)';
                    showRowMsg(msgEl, 'error',
                        'Server error — could not parse response.<br>' +
                        '<code style="font-size:10px;word-break:break-all;display:block;margin-top:4px;background:#fff;padding:4px;border-radius:4px;">' +
                        preview + '</code>'
                    );
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit';
                    return false;
                }

                if (json.success) {
                    // Show success toast via shared helper
                    showToast('Meter readings submitted successfully.', 'success');

                    // Clear any inline message
                    if (msgEl) { msgEl.style.display = 'none'; msgEl.innerHTML = ''; }

                    // ── Continuous cycle: carryover submitted Ending → next Beginning (Auto-fetched & Read-only) ──
                    const endingEl    = document.getElementById('ending_'    + ftId);
                    const beginningEl = document.getElementById('beginning_' + ftId);
                    const calEl       = document.getElementById('cal_'       + ftId);
                    const volumeEl    = document.getElementById('volume_'    + ftId);
                    const volumeValEl = document.getElementById('volume_value_' + ftId);
                    const amountEl    = document.getElementById('amount_'    + ftId);
                    const amountValEl = document.getElementById('amount_value_' + ftId);

                    const submittedEnding = parseFloat((endingEl?.value || '0').replace(/,/g, ''));

                    // After submission, set beginning to submitted ending (auto-fetched & read-only for next shift)
                    if (beginningEl && submittedEnding > 0) {
                        beginningEl.value = submittedEnding.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                        beginningEl.readOnly = true;
                        beginningEl.title = 'Auto-fetched from previous meter reading (' + beginningEl.value + '). Read-only.';
                        delete beginningEl.dataset.missing;
                        beginningEl.style.background = '#f0fdf4';
                        beginningEl.style.fontWeight = '700';
                        beginningEl.style.color      = '#15803d';
                        beginningEl.style.border     = '1px solid #86efac';
                        beginningEl.style.cursor     = 'not-allowed';
                    }

                    // Clear ending and computed fields
                    if (endingEl)    endingEl.value    = '';
                    if (calEl)       calEl.value       = '0.00';
                    if (volumeEl)    volumeEl.value    = '0.00';
                    if (volumeValEl) volumeValEl.value = '0.00';
                    if (amountEl)    amountEl.value    = '₱0.00';
                    if (amountValEl) amountValEl.value = '0.00';

                    // Switch to Meter Reading History tab so the new record is immediately visible
                    if (typeof switchFuelSubTab === 'function') switchFuelSubTab('readings');
                    if (typeof loadTodayEntries  === 'function') loadTodayEntries();
                } else {
                    // Show the actual server error message
                    const errMsg = json.message || 'Submission failed. Please try again.';
                    showRowMsg(msgEl, 'error', errMsg);
                }
            } catch (err) {
                showRowMsg(msgEl, 'error', 'Network error: ' + err.message);
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Save';
                }
            }
        }

        function showRowMsg(el, type, text) {
            if (!el) return;
            if (!text) { el.style.display = 'none'; el.innerHTML = ''; return; }
            const colors = {
                success: { bg: '#dcfce7', color: '#166534', border: '#86efac' },
                error:   { bg: '#fee2e2', color: '#991b1b', border: '#fca5a5' },
            };
            const c = colors[type] || { bg: '#f1f5f9', color: '#475569', border: '#e2e8f0' };
            el.style.cssText = `display:block;background:${c.bg};color:${c.color};border:1px solid ${c.border};
                                border-radius:6px;padding:8px 12px;font-size:11px;font-weight:600;white-space:normal;
                                margin-top:5px;line-height:1.8;`;
            // Convert \n to <br> for multi-line messages
            const safeText = text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
            el.innerHTML = safeText;
        }

        // ── Reset a single row (Clears manual inputs: Ending & Calibration; keeps auto Beginning intact) ──
        function resetFuelRow(ftId) {
            const msgEl         = document.getElementById('cardMsg_' + ftId);
            const beginningEl   = document.getElementById(`beginning_${ftId}`);
            const endingEl      = document.getElementById(`ending_${ftId}`);
            const calEl         = document.getElementById(`cal_${ftId}`);
            const volumeEl      = document.getElementById(`volume_${ftId}`);
            const volumeValueEl = document.getElementById(`volume_value_${ftId}`);
            const amountEl      = document.getElementById(`amount_${ftId}`);
            const amountValueEl = document.getElementById(`amount_value_${ftId}`);
            
            // Do NOT touch beginning reading if it's auto-fetched (read-only)
            if (beginningEl && !beginningEl.readOnly) {
                beginningEl.value = '';
            }
            if (endingEl) endingEl.value = '';
            if (calEl) calEl.value = '0.00';
            if (volumeEl) volumeEl.value = '0.00';
            if (volumeValueEl) volumeValueEl.value = '0.00';
            if (amountEl) amountEl.value = '₱0.00';
            if (amountValueEl) amountValueEl.value = '0.00';
            if (msgEl) showRowMsg(msgEl, '', '');
        }


        // ── Reset ALL fuel rows + clear server-side unclosed draft entries ──────
        async function resetAllFuelRows() {
            if (!confirm('Reset all fuel readings? This will clear all entered data.')) return;
            
            // 1. Clear UI fields
            const allForms = document.querySelectorAll('form[id^="fuelForm_"]');
            allForms.forEach(form => {
                const ftId = form.id.replace('fuelForm_', '');
                resetFuelRow(ftId);
            });
            
            // 2. Clear global remarks
            const remarksInput = document.getElementById('globalFuelRemarks');
            if (remarksInput) remarksInput.value = '';
            const remarksTextarea = document.getElementById('globalRemarksTextarea');
            if (remarksTextarea) remarksTextarea.value = '';
            const remarksBtnLbl = document.getElementById('remarksButtonLabel');
            if (remarksBtnLbl) remarksBtnLbl.textContent = 'Add Remarks';

            // 3. Call API to clear unclosed draft readings from DB for this shift so F5 refresh won't restore them
            try {
                const shiftVal = '<?= htmlspecialchars($fuel_shift_name, ENT_QUOTES) ?>';
                const dateVal  = '<?= date('Y-m-d') ?>';
                const formData = new FormData();
                formData.append('action', 'reset_shift');
                formData.append('shift_period', shiftVal);
                formData.append('reading_date', dateVal);

                await fetch('api_fuel_readings.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                });
            } catch (e) {
                console.error('Reset shift API error:', e);
            }

            if (window.PetronDraft) {
                window.PetronDraft.clear('fuel_meter_readings');
                window.PetronDraft.clear('fuel_meter_readings_fuel');
            }

            showToast('All fuel readings have been reset.', 'info');
        }
        
        // ── Global Remarks Functions ───────────────────────────────────────────
        function openGlobalRemarksModal() {
            const currentRemarks = document.getElementById('globalFuelRemarks').value;
            document.getElementById('globalRemarksTextarea').value = currentRemarks;
            document.getElementById('globalRemarksModal').style.display = 'flex';
            document.getElementById('globalRemarksTextarea').focus();
        }
        
        function closeGlobalRemarksModal() {
            document.getElementById('globalRemarksModal').style.display = 'none';
        }
        
        function saveGlobalRemarks() {
            const remarks = document.getElementById('globalRemarksTextarea').value.trim();
            document.getElementById('globalFuelRemarks').value = remarks;
            
            // Update button label
            const buttonLabel = document.getElementById('remarksButtonLabel');
            if (remarks) {
                buttonLabel.textContent = 'Edit Remarks ✓';
                showToast('Remarks saved! They will be applied to all fuel transactions.', 'success');
            } else {
                buttonLabel.textContent = 'Add Remarks';
            }
            
            closeGlobalRemarksModal();
        }
        
        // Close modal on ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeGlobalRemarksModal();
            }
        });
        
        // Close modal on background click
        document.getElementById('globalRemarksModal')?.addEventListener('click', function(e) {
            if (e.target === this) closeGlobalRemarksModal();
        });

        // ── Submit ALL valid encoded rows ──────────────────────────────────────
        async function submitAllFuelRows() {
            const allForms = document.querySelectorAll('form[id^="fuelForm_"]');
            if (!allForms || allForms.length === 0) {
                showToast('No fuel reading forms found.', 'error');
                return;
            }

            const formsToSubmit = [];
            const skippedForms  = [];

            allForms.forEach(form => {
                const ftId        = form.id.replace('fuelForm_', '');
                const endingEl    = document.getElementById(`ending_${ftId}`);
                const beginningEl = document.getElementById(`beginning_${ftId}`);
                const calEl       = document.getElementById(`cal_${ftId}`);
                const msgEl       = document.getElementById(`cardMsg_${ftId}`);

                const endingRaw    = (endingEl    ? endingEl.value    : '').replace(/,/g, '').trim();
                const beginningRaw = (beginningEl ? beginningEl.value : '').replace(/,/g, '').trim();
                const calRaw       = (calEl       ? calEl.value       : '0.00').replace(/,/g, '').trim();

                const endingVal    = parseFloat(endingRaw)    || 0;
                const beginningVal = parseFloat(beginningRaw) || 0;
                const calVal       = parseFloat(calRaw)       || 0;

                // 1. Skip rows where ending reading is not entered / blank
                if (!endingRaw || endingVal <= 0) {
                    return;
                }

                // 2. If beginning reading is editable (first shift) and empty, default to 0
                //    (valid for new pump installation or first-ever shift entry)
                if (beginningEl && !beginningEl.readOnly && (!beginningRaw || beginningRaw === '')) {
                    beginningEl.value = '0.00';
                    // beginningRaw stays '' but we treat beginningVal as 0 — already handled below
                }

                // 3. Ending must be >= Beginning
                if (endingVal < beginningVal) {
                    skippedForms.push({ ftId, reason: `Ending (${endingVal.toLocaleString()}) < Beginning (${beginningVal.toLocaleString()})` });
                    if (msgEl) showRowMsg(msgEl, 'error', 'Ending Reading cannot be lower than Beginning Reading — skipped.');
                    return;
                }

                // 4. Calibration >= 0
                if (calVal < 0) {
                    skippedForms.push({ ftId, reason: 'Calibration cannot be negative' });
                    if (msgEl) showRowMsg(msgEl, 'error', 'Calibration cannot be negative — skipped.');
                    return;
                }

                formsToSubmit.push({ ftId, form, endingRaw, beginningRaw, calRaw, endingVal });
            });

            if (formsToSubmit.length === 0) {
                if (skippedForms.length > 0) {
                    showToast('Some readings have errors (e.g., Ending < Beginning). Please correct the highlighted rows before submitting.', 'error');
                } else {
                    showToast('Please enter the Ending meter reading for at least one fuel pump before clicking Submit All Readings.', 'info');
                }
                return;
            }

            let confirmMsg = `Submit ${formsToSubmit.length} fuel reading(s) for manager validation?`;
            if (skippedForms.length > 0) {
                confirmMsg += `\n\n⚠️ ${skippedForms.length} row(s) will be SKIPPED due to errors:\n` +
                    skippedForms.map(s => `• ${s.ftId.replace(/_/g,' ').toUpperCase()}: ${s.reason}`).join('\n');
            }

            const confirmed = await showConfirm(confirmMsg);
            if (!confirmed) return;

            let successCount = 0;
            let errorCount   = 0;
            const errors     = [];

            const globalRemarks = document.getElementById('globalFuelRemarks')?.value.trim() || '';

            // Submit each form
            for (const {ftId, form, endingRaw, beginningRaw, calRaw, endingVal} of formsToSubmit) {
                try {
                    const formData = new FormData(form);
                    formData.set('auth_user_id',     '<?= (int)$me['id'] ?>');
                    formData.set('ending_reading',    endingRaw);
                    formData.set('beginning_reading',  beginningRaw);
                    formData.set('calibration',        calRaw);
                    formData.set('notes',              globalRemarks);

                    const targetUrl = window.location.pathname.replace(/[^\\/]+$/, '') + 'api_fuel_readings.php';
                    const response = await fetch(targetUrl, {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    });

                    const raw = await response.text().catch(() => '');
                    let result;
                    try {
                        const jsonStart = raw.indexOf('{"success"');
                        const jsonStr   = jsonStart >= 0 ? raw.slice(jsonStart) : raw;
                        result = JSON.parse(jsonStr);
                    } catch(e) {
                        result = { success: false, message: 'Invalid server response.' };
                    }

                    if (result.success) {
                        successCount++;
                        // ── Continuous cycle: carryover Ending → Beginning (Auto-fetched & Read-only) ──
                        const endingEl    = document.getElementById(`ending_${ftId}`);
                        const beginningEl = document.getElementById(`beginning_${ftId}`);
                        const calEl       = document.getElementById(`cal_${ftId}`);
                        const volumeEl    = document.getElementById(`volume_${ftId}`);
                        const volumeValEl = document.getElementById(`volume_value_${ftId}`);
                        const amountEl    = document.getElementById(`amount_${ftId}`);
                        const amountValEl = document.getElementById(`amount_value_${ftId}`);

                        if (beginningEl && endingVal > 0) {
                            beginningEl.value = endingVal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                            beginningEl.readOnly = true;
                            beginningEl.title = 'Auto-fetched from previous meter reading (' + beginningEl.value + '). Read-only.';
                            delete beginningEl.dataset.missing;
                            beginningEl.style.background = '#f0fdf4';
                            beginningEl.style.fontWeight = '700';
                            beginningEl.style.color      = '#15803d';
                            beginningEl.style.border     = '1px solid #86efac';
                            beginningEl.style.cursor     = 'not-allowed';
                        }
                        if (endingEl)    endingEl.value    = '';
                        if (calEl)       calEl.value       = '0.00';
                        if (volumeEl)    volumeEl.value    = '0.00';
                        if (volumeValEl) volumeValEl.value = '0.00';
                        if (amountEl)    amountEl.value    = '₱0.00';
                        if (amountValEl) amountValEl.value = '0.00';
                    } else {
                        errorCount++;
                        errors.push(`${ftId}: ${result.message || 'Unknown error'}`);
                    }
                } catch (error) {
                    errorCount++;
                    errors.push(`${ftId}: ${error.message}`);
                }
            }

            // Switch to history tab and refresh
            if (typeof switchFuelSubTab === 'function') switchFuelSubTab('readings');
            if (typeof loadTodayEntries === 'function') loadTodayEntries();

            // Show summary toast
            if (errorCount === 0 || successCount > 0) {
                const remarksInput = document.getElementById('globalFuelRemarks');
                if (remarksInput) remarksInput.value = '';
                const remarksTextarea = document.getElementById('globalRemarksTextarea');
                if (remarksTextarea) remarksTextarea.value = '';
                const remarksBtnLbl = document.getElementById('remarksButtonLabel');
                if (remarksBtnLbl) remarksBtnLbl.textContent = 'Add Remarks';

                if (window.PetronDraft) {
                    window.PetronDraft.clear('fuel_meter_readings');
                    window.PetronDraft.clear('fuel_meter_readings_fuel');
                }

                showToast('Meter readings submitted! Redirecting to Fuel Sales Closing...', 'success');
                setTimeout(() => {
                    const todayStr = new Date().toISOString().split('T')[0];
                    const shiftStr = '<?= htmlspecialchars($fuel_shift_name, ENT_QUOTES) ?>';
                    window.location.href = 'staff_fuel_sales_closing.php?date=' + encodeURIComponent(todayStr) + '&shift=' + encodeURIComponent(shiftStr) + '&from_readings=1';
                }, 1500);
            } else {
                const firstErr = errors[0] ? errors[0].replace(/^[^:]+:\s*/, '') : 'Submission failed.';
                showToast(firstErr, 'error');
            }
        }

        // ── Generic toast helper ────────────────────────────────────────────────
        function showToast(msg, type = 'success') {
            if (window.showPetronFlash) {
                window.showPetronFlash(msg, type);
                return;
            }
            const colors = {
                success: { bg:'#f0fdf4', color:'#166534', border:'#86efac', icon:'fa-check-circle', iconColor:'#16a34a' },
                error:   { bg:'#fef2f2', color:'#991b1b', border:'#fecaca', icon:'fa-times-circle',  iconColor:'#dc2626' },
                warning: { bg:'#fffbeb', color:'#92400e', border:'#fde68a', icon:'fa-exclamation-triangle', iconColor:'#d97706' },
                info:    { bg:'#eff6ff', color:'#1e40af', border:'#bfdbfe', icon:'fa-info-circle',   iconColor:'#2563eb' },
            };
            const c = colors[type] || colors.success;
            const old = document.getElementById('petronToastRightBanner');
            if (old) old.remove();

            const toast = document.createElement('div');
            toast.id = 'petronToastRightBanner';
            toast.style.cssText = `position:fixed;top:84px;right:22px;left:auto;z-index:999999;` +
                `background:${c.bg};color:${c.color};border:1.5px solid ${c.border};` +
                `padding:12px 18px;border-radius:10px;font-weight:700;` +
                `box-shadow:0 12px 30px rgba(0,0,0,.15);transition:opacity .35s ease, transform .25s ease;` +
                `font-size:13.5px;display:flex;align-items:center;gap:10px;max-width:440px;width:auto;opacity:0;transform:translateY(-10px);`;
            toast.innerHTML = `<i class="fas ${c.icon}" style="color:${c.iconColor};font-size:16px;flex-shrink:0;"></i><span style="flex:1;">${msg}</span>`;
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
            }, 3000);
        }

        // ── Custom confirm modal (replaces browser confirm()) ───────────────────
        function showConfirm(msg) {
            return new Promise(resolve => {
                const overlay = document.createElement('div');
                overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99998;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(2px);';
                overlay.innerHTML = `
                    <div style="background:#ffffff;border-radius:14px;padding:28px 32px;max-width:420px;width:90%;box-shadow:0 12px 36px rgba(0,0,0,.25);text-align:center;">
                        <div style="font-size:28px;color:#002F6C;margin-bottom:14px;"><i class="fas fa-question-circle"></i></div>
                        <p style="font-size:15px;font-weight:600;color:#1e293b;margin:0 0 24px;line-height:1.5;">${msg.replace(/\n/g, '<br>')}</p>
                        <div style="display:flex;gap:14px;justify-content:center;">
                            <button id="confirmNo" type="button" style="padding:10px 24px !important;border-radius:6px !important;border:1px solid #cbd5e1 !important;background-color:#e2e8f0 !important;color:#334155 !important;font-weight:700 !important;cursor:pointer !important;font-size:13px !important;transition:all .2s;" onmouseover="this.style.setProperty('background-color','#cbd5e1','important');this.style.setProperty('color','#0f172a','important');" onmouseout="this.style.setProperty('background-color','#e2e8f0','important');this.style.setProperty('color','#334155','important');">Cancel</button>
                            <button id="confirmYes" type="button" style="padding:10px 24px !important;border-radius:6px !important;border:1px solid #002F6C !important;background-color:#002F6C !important;color:#ffffff !important;font-weight:700 !important;cursor:pointer !important;font-size:13px !important;transition:all .2s;" onmouseover="this.style.setProperty('background-color','#001f4d','important');this.style.setProperty('color','#ffffff','important');" onmouseout="this.style.setProperty('background-color','#002F6C','important');this.style.setProperty('color','#ffffff','important');">Submit</button>
                        </div>
                    </div>`;
                document.body.appendChild(overlay);
                overlay.querySelector('#confirmYes').onclick = () => { overlay.remove(); resolve(true); };
                overlay.querySelector('#confirmNo').onclick  = () => { overlay.remove(); resolve(false); };
            });
        }

        // ── Today's Entries (Table A) — auto-load + refresh after submit ──────
        async function loadTodayEntries() {
            const body = document.getElementById('todayEntriesBody');
            const icon = document.getElementById('refreshIcon');
            if (icon) icon.className = 'fas fa-spinner fa-spin';

            try {
                // Read local sub-tab date range and shift filter values
                const dateFromVal = document.getElementById('subtab_date_from')?.value || '<?= date('Y-m-01') ?>';
                const dateToVal   = document.getElementById('subtab_date_to')?.value || '<?= date('Y-m-d') ?>';
                const shiftVal    = document.getElementById('subtab_shift')?.value || '';

                const params = new URLSearchParams({ 
                    action: 'summary', 
                    date_from: dateFromVal, 
                    date_to: dateToVal, 
                    auth_user_id: '<?= (int)$me['id'] ?>' 
                });
                if (shiftVal) params.set('shift', shiftVal);

                const url  = `./api_fuel_readings.php?${params.toString()}`;
                const res  = await fetch(url, {credentials:'same-origin'});
                const json = await res.json();

                const readings = json.meter_readings || [];

                // ── Update Summary Cards Counts ──
                const totalCount = readings.length;
                const pendingCount = readings.filter(r => {
                    const s = (r.status || '').toLowerCase().trim();
                    return s === 'pending' || s === 'pending validation';
                }).length;

                const elEncoded = document.getElementById('summary_encoded_count');
                const elPending = document.getElementById('summary_pending_count');
                if (elEncoded) elEncoded.textContent = totalCount;
                if (elPending) elPending.textContent = pendingCount;

                if (!json.success) {
                    body.innerHTML = `<div style="text-align:center;padding:40px;color:#ef4444;font-size:14px;background:#ffffff;">
                        <i class="fas fa-exclamation-circle" style="font-size:28px;display:block;margin-bottom:10px;color:#f87171;"></i>
                        Failed to load history entries.
                    </div>`;
                    if (icon) icon.className = 'fas fa-sync';
                    return;
                }

                window.todayEntriesData = readings;
                window.todayEntriesPage = 1;
                renderTodayEntriesTable();
            } catch(e) {
                body.innerHTML = `<div style="text-align:center;padding:30px;color:#ef4444;font-size:13px;background:#ffffff;">
                    <i class="fas fa-exclamation-circle" style="display:block;margin-bottom:6px;font-size:20px;"></i>
                    Could not load entries. Please check your connection or refresh the page.
                </div>`;
            }
            if (icon) icon.className = 'fas fa-sync';
        }

                window.todayEntriesPageSize = 10;

        // ── 10-SECOND REAL-TIME AUTO REFRESH FOR METER READING HISTORY ──────────
        async function autoRefreshMeterReadingHistory() {
            // Only refresh if Meter Reading History card is visible
            const todayCard = document.getElementById('todayEntriesCard');
            if (!todayCard || todayCard.style.display === 'none') return;

            // Do not refresh if user has any modal open
            const modals = ['fuelDetailsModal', 'readingEditModal', 'readingVoidModal', 'readingAdjustModal', 'requestVoidModal', 'requestAdjustModal'];
            for (let mId of modals) {
                const m = document.getElementById(mId);
                if (m && (m.style.display === 'flex' || m.style.display === 'block')) return;
            }

            try {
                if (typeof loadTodayEntries === 'function') {
                    await loadTodayEntries();
                }
            } catch (e) {
                console.warn('Meter Reading History refresh notice:', e);
            }
        }

        // Run auto-refresh every 10 seconds
        setInterval(autoRefreshMeterReadingHistory, 10000);
        
        function renderTodayEntriesTable() {
            const body = document.getElementById('todayEntriesBody');
            const rows = window.todayEntriesData || [];
            
            const totalRows = rows.length;
            const totalPages = Math.max(1, Math.ceil(totalRows / window.todayEntriesPageSize));
            if (window.todayEntriesPage > totalPages) window.todayEntriesPage = totalPages;
            
            const startIdx = (window.todayEntriesPage - 1) * window.todayEntriesPageSize;
            const endIdx = Math.min(startIdx + window.todayEntriesPageSize, totalRows);
            const pageRows = rows.slice(startIdx, endIdx);

            // Assign sequential labels using GROUP-level counter
            function _getFuelGroup(ft) {
                const f = (ft || '').toUpperCase().trim();
                if (f.includes('TURBO') && f.includes('DIESEL')) return 'TURBO DIESEL';
                if (f.includes('DIESEL')) return 'DIESEL';
                if (f.includes('KEROSENE')) return 'KEROSENE';
                if (f.includes('XCS') && f.includes('PLUS'))  return 'XCS PLUS';
                if (f.includes('XTRA') && f.includes('UNL'))  return 'XTRA UNL';
                return f;
            }
            function getFormattedFuelName(fuelType, seqNumber) {
                const f = (fuelType || '').toUpperCase().trim();
                if (f.includes('TURBO') && f.includes('DIESEL')) {
                    return `TURBO DIESEL - ${seqNumber}`;
                }
                if (f.includes('DIESEL')) {
                    if (seqNumber <= 4) {
                        return `DIESEL 1 - ${seqNumber}`;
                    } else {
                        return `DIESEL 2 - ${seqNumber}`;
                    }
                }
                if (f.includes('KEROSENE')) {
                    return `KEROSENE - ${seqNumber}`;
                }
                if (f.includes('XCS') && f.includes('PLUS')) {
                    return `XCS PLUS - ${seqNumber}`;
                }
                if (f.includes('XTRA') && f.includes('UNL')) {
                    if (seqNumber <= 2) {
                        return `XTRA UNL 1 - ${seqNumber}`;
                    } else {
                        return `XTRA UNL 2 - ${seqNumber}`;
                    }
                }
                return `${f} - ${seqNumber}`;
            }
            const _grpCounters = {};
            rows.forEach(r => {
                const grp   = _getFuelGroup(r.fuel_type);
                if (!_grpCounters[grp]) _grpCounters[grp] = 0;
                _grpCounters[grp]++;
                r._seq_label = getFormattedFuelName(r.fuel_type, _grpCounters[grp]);
            });

            const statusMap = {
                'pending validation': {color:'#d97706',label:'Pending Validation'},
                'pending':            {color:'#d97706',label:'Pending Validation'},
                'approved':           {color:'#16a34a',label:'Verified'},
                'verified':           {color:'#16a34a',label:'Verified'},
                'validated':          {color:'#16a34a',label:'Verified'},
                'adjusted':           {color:'#2563eb',label:'Adjusted'},
                'rejected':           {color:'#dc2626',label:'Rejected'},
            };
            function badge(s) {
                const k = (s||'').toLowerCase().trim();
                const c = statusMap[k] || {color:'#64748b',label:s||'—'};
                return `<span style="background:${c.color}15; color:${c.color}; border:1px solid ${c.color}30; font-weight:700; font-size:10px; padding:2px 6px; border-radius:4px; text-transform:uppercase; letter-spacing:.3px; white-space:nowrap; display:inline-block; max-width:100%; overflow:hidden; text-overflow:ellipsis; vertical-align:middle;" title="${c.label}">${c.label}</span>`;
            }
            function fmt(n,d=2){ return Number(n||0).toLocaleString('en-PH',{minimumFractionDigits:d,maximumFractionDigits:d}); }

            function fmtTime(dtStr) {
                if (!dtStr) return '—';
                try {
                    const parts = dtStr.trim().split(' ');
                    if (parts.length < 2) return dtStr;
                    const timeParts = parts[1].split(':');
                    if (timeParts.length < 2) return parts[1];
                    let hour = parseInt(timeParts[0], 10);
                    const minute = timeParts[1];
                    const ampm = hour >= 12 ? 'PM' : 'AM';
                    hour = hour % 12;
                    hour = hour ? hour : 12;
                    return `${hour}:${minute} ${ampm}`;
                } catch(e) {
                    return dtStr;
                }
            }

            function fmtDate(dtStr) {
                if (!dtStr) return '—';
                try {
                    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                    const parts = dtStr.split('-');
                    if (parts.length >= 3) {
                        return `${months[parseInt(parts[1],10)-1]} ${parseInt(parts[2],10)}, ${parts[0]}`;
                    }
                    return dtStr;
                } catch(e) { return dtStr; }
            }
            function fmtShift(shiftPeriod, shiftName) {
                if (shiftName) return shiftName;
                if (!shiftPeriod) return '—';
                const sl = shiftPeriod.toLowerCase();
                if (sl.includes('first') || sl === 'shift_1' || sl === '1') return 'First Shift';
                if (sl.includes('second') || sl === 'shift_2' || sl === '2') return 'Second Shift';
                return shiftPeriod;
            }

            const TH  = 'padding:6px 4px; font-size:11px; font-weight:700; color:#ffffff; text-transform:uppercase; letter-spacing:.3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;';
            const THR = TH + ' text-align:right;';

            let html = `<div style="overflow-x:hidden; border-bottom:1px solid #e2e8f0; background:#ffffff;">
                <table id="todayReadingsTable" style="width:100%; border-collapse:collapse; font-size:11px; text-align:left; table-layout:fixed;">
                    <colgroup>
                        <col style="width: 7%;">  <!-- Date -->
                        <col style="width: 8%;">  <!-- Shift -->
                        <col style="width: 14%;"> <!-- Name -->
                        <col style="width: 7%;">  <!-- Beginning -->
                        <col style="width: 7%;">  <!-- Ending -->
                        <col style="width: 7%;">  <!-- Calibration -->
                        <col style="width: 8%;">  <!-- Volume (L) -->
                        <col style="width: 7%;">  <!-- Price/L -->
                        <col style="width: 9%;">  <!-- Amount -->
                        <col style="width: 9%;">  <!-- Encoded By -->
                        <col style="width: 10%;"> <!-- Status -->
                        <col style="width: 7%;">  <!-- Notes -->
                    </colgroup>
                    <thead>
                        <tr style="background:#002F70; border-bottom:2px solid #001f4d;">
                            <th style="${TH}" title="Date">Date</th>
                            <th style="${TH}" title="Shift">Shift</th>
                            <th style="${TH}" title="Name">Name</th>
                            <th style="${THR}" title="Beginning">Beginning</th>
                            <th style="${THR}" title="Ending">Ending</th>
                            <th style="${THR}" title="Calibration">Calibration</th>
                            <th style="${THR}" title="Volume (L)">Volume (L)</th>
                            <th style="${THR}" title="Price/L">Price/L</th>
                            <th style="${THR}" title="Amount">Amount</th>
                            <th style="${TH}" title="Encoded By">Encoded By</th>
                            <th style="${TH}" title="Status">Status</th>
                            <th style="${TH}" title="Notes">Notes</th>
                        </tr>
                    </thead>
                    <tbody>`;

            const escapeHtml = (str) => String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');

            pageRows.forEach(r => {
                let staffNotes = (r.notes || '').trim();
                let mgrNotes = (r.reject_reason || '').trim();
                let tooltipText = '';
                if (staffNotes) tooltipText += 'Staff: ' + staffNotes;
                if (mgrNotes) tooltipText += (tooltipText ? '\n' : '') + 'Manager: ' + mgrNotes;

                let notesCellContent = '—';
                if (staffNotes && mgrNotes) {
                    notesCellContent = `<div style="line-height:1.2; width:100%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${escapeHtml(tooltipText)}"><strong>S:</strong> ${escapeHtml(staffNotes)}<br><strong>M:</strong> ${escapeHtml(mgrNotes)}</div>`;
                } else if (staffNotes) {
                    notesCellContent = `<div style="line-height:1.2; width:100%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${escapeHtml(tooltipText)}">${escapeHtml(staffNotes)}</div>`;
                } else if (mgrNotes) {
                    notesCellContent = `<div style="line-height:1.2; width:100%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#002F70;" title="${escapeHtml(tooltipText)}"><strong>M:</strong> ${escapeHtml(mgrNotes)}</div>`;
                }

                const dateStr = fmtDate(r.reading_date || r.transaction_date);
                const shiftStr = fmtShift(r.shift_period, r.shift_name);
                const fuelStr = r._seq_label || (r.fuel_type || '—').toUpperCase();
                const staffStr = r.staff_name || '—';

                html += `<tr style="border-bottom:1px solid #f1f5f9; background:#ffffff; transition: background-color 0.15s ease;" onmouseover="this.style.backgroundColor='#f0f5ff';" onmouseout="this.style.backgroundColor='#ffffff';">
                    <td style="padding:6px 4px; color:#1e293b; font-size:11px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; vertical-align:middle;" title="${dateStr}">${dateStr}</td>
                    <td style="padding:6px 4px; color:#334155; font-size:11px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; vertical-align:middle;" title="${shiftStr}">${shiftStr}</td>
                    <td style="padding:6px 4px; font-weight:700; color:#0f172a; font-size:11px; white-space:normal; word-break:break-word; vertical-align:middle;" title="${fuelStr}">${fuelStr}</td>
                    <td style="padding:6px 4px; text-align:right; font-variant-numeric:tabular-nums; color:#1e293b; font-size:11px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; vertical-align:middle;" title="${fmt(r.beginning)}">${fmt(r.beginning)}</td>
                    <td style="padding:6px 4px; text-align:right; font-variant-numeric:tabular-nums; color:#1e293b; font-weight:600; font-size:11px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; vertical-align:middle;" title="${fmt(r.ending)}">${fmt(r.ending)}</td>
                    <td style="padding:6px 4px; text-align:right; font-variant-numeric:tabular-nums; color:#334155; font-size:11px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; vertical-align:middle;" title="${fmt(r.cal,3)}">${fmt(r.cal,3)}</td>
                    <td style="padding:6px 4px; text-align:right; font-weight:700; font-variant-numeric:tabular-nums; color:#1e293b; font-size:11px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; vertical-align:middle;" title="${fmt(r.volume_liters)} L">${fmt(r.volume_liters)} L</td>
                    <td style="padding:6px 4px; text-align:right; font-variant-numeric:tabular-nums; color:#334155; font-size:11px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; vertical-align:middle;" title="₱${fmt(r.price_per_liter)}">₱${fmt(r.price_per_liter)}</td>
                    <td style="padding:6px 4px; text-align:right; font-weight:800; font-variant-numeric:tabular-nums; color:#0f172a; font-size:11px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; vertical-align:middle;" title="₱${fmt(r.amount)}">₱${fmt(r.amount)}</td>
                    <td style="padding:6px 4px; color:#334155; font-weight:500; font-size:11px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; vertical-align:middle;" title="${staffStr}">${staffStr}</td>
                    <td style="padding:6px 4px; font-size:11px; vertical-align:middle;">${badge(r.status)}</td>
                    <td style="padding:6px 4px; color:#475569; font-size:11px; overflow:hidden; vertical-align:middle;">${notesCellContent}</td>
                </tr>`;
            });

            if (pageRows.length === 0) {
                html += `<tr>
                    <td colspan="12" style="padding:40px; text-align:center; color:#94a3b8; font-size:14px; background:#ffffff;">
                        <i class="fas fa-history" style="font-size:24px; display:block; margin-bottom:8px; color:#cbd5e1;"></i>
                        No readings submitted for the selected filter criteria.
                    </td>
                </tr>`;
            }

            html += `</tbody></table></div>`;
            
            // Pagination Footer (Only if Total Records > 10)
            if (totalRows > 10) {
                var showingStart = (window.todayEntriesPage - 1) * window.todayEntriesPageSize + 1;
                var showingEnd = Math.min(window.todayEntriesPage * window.todayEntriesPageSize, totalRows);
                
                html += `
                <div style="display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-top:1px solid #e2e8f0; background:#ffffff; border-radius:0 0 12px 12px; font-size:13px; color:#475569; flex-wrap:wrap; gap:12px;">
                    <div style="display:flex; align-items:center;">
                        <span style="font-size:13px; color:#64748b; font-weight:600;">Showing ${showingStart}–${showingEnd} of ${totalRows} entries</span>
                    </div>
                    <div style="display:flex; align-items:center; gap:16px;">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <label style="margin:0; font-weight:600; color:#64748b; font-size:13px;">Rows per page:</label>
                            <select onchange="window.todayEntriesPageSize=parseInt(this.value); window.todayEntriesPage=1; renderTodayEntriesTable();" style="padding:4px 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; background:transparent !important; color:#334155; outline:none; cursor:pointer;">
                                <option value="10" ${window.todayEntriesPageSize === 10 ? 'selected' : ''}>10</option>
                                <option value="20" ${window.todayEntriesPageSize === 20 ? 'selected' : ''}>20</option>
                                <option value="50" ${window.todayEntriesPageSize === 50 ? 'selected' : ''}>50</option>
                                <option value="100" ${window.todayEntriesPageSize === 100 ? 'selected' : ''}>100</option>
                            </select>
                        </div>
                        <div style="display:flex; align-items:center; gap:6px;">
                            <button onclick="if(window.todayEntriesPage>1){ window.todayEntriesPage--; renderTodayEntriesTable(); }" 
                                    style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:${window.todayEntriesPage > 1 ? 'pointer' : 'not-allowed'}; color:${window.todayEntriesPage > 1 ? '#475569' : '#cbd5e1'}; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                                    onmouseover="if(window.todayEntriesPage>1) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                            <span style="color:#334155; font-size:13px; font-weight:600; padding:0 4px;">Page ${window.todayEntriesPage} of ${totalPages}</span>
                            <button onclick="if(window.todayEntriesPage<${totalPages}){ window.todayEntriesPage++; renderTodayEntriesTable(); }" 
                                    style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:${window.todayEntriesPage < totalPages ? 'pointer' : 'not-allowed'}; color:${window.todayEntriesPage < totalPages ? '#475569' : '#cbd5e1'}; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                                    onmouseover="if(window.todayEntriesPage<${totalPages}) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        </div>
                    </div>
                </div>`;
            }

            
            body.innerHTML = html;

        }

        // ── Export Sub-Tab Readings ──
        function exportTodayReadings(format) {
            const tableId = 'todayReadingsTable';
            const tableEl = document.getElementById(tableId);
            if (!tableEl) {
                alert('No data available to export.');
                return;
            }
            const dateFromVal = document.getElementById('subtab_date_from')?.value || '<?= date('Y-m-01') ?>';
            const dateToVal   = document.getElementById('subtab_date_to')?.value || '<?= date('Y-m-d') ?>';
            const shiftVal    = document.getElementById('subtab_shift')?.value || 'all_shifts';
            const filename    = `meter_readings_history_${dateFromVal}_to_${dateToVal}_${shiftVal}`;
            const title       = `Petron Fuel Meter Readings History Report (${dateFromVal} to ${dateToVal} - Shift: ${shiftVal.toUpperCase()})`;
            
            if (format === 'excel') {
                if (typeof exportTableToExcel === 'function') {
                    exportTableToExcel(tableId, filename + '.xls');
                } else {
                    alert('Excel export function is not loaded.');
                }
            } else if (format === 'csv') {
                if (typeof exportTableToCSV === 'function') {
                    exportTableToCSV(tableId, filename + '.csv');
                } else {
                    alert('CSV export function is not loaded.');
                }
            } else if (format === 'pdf') {
                if (typeof exportTableToPDF === 'function') {
                    exportTableToPDF(tableId, title);
                } else {
                    alert('PDF export function is not loaded.');
                }
            }
        }

        function refreshTodayEntries() { loadTodayEntries(); }

        // Load on page open
        document.addEventListener('DOMContentLoaded', loadTodayEntries);
        </script>



        <?php /* ══════════════════════════════════════════════════════
               SECTION: MERCHANDISE TRANSACTION (Customer-facing)
        ══════════════════════════════════════════════════════ */ ?>
        <?php elseif ($section === 'merchandise'): ?>
        <script>
        /* ── Auto-clear stale POS / Job-Order draft data on section load ────────
           When the staff lands on the Merchandise section (fresh page load), any
           leftover draft from a previously submitted or abandoned transaction is
           cleared from both localStorage AND the server drafts API.
           This runs synchronously before DOMContentLoaded so the draft engine
           never restores stale data into the empty form.                       */
        (function () {
            var userId = (window.pageData && window.pageData.userId) ? window.pageData.userId : 0;
            var basePath = (window.pageData && window.pageData.appBasePath)
                ? window.pageData.appBasePath.replace(/\/$/, '')
                : (window.location.pathname.includes('/public/') ? window.location.pathname.split('/public/')[0] : '');
            var DRAFTS_API = basePath + '/backend/api/drafts_api.php';

            // All module keys the draft engine can create for this page/section
            var draftKeys = [
                'pos_merchandise_joborder',
                'pos_merchandise_joborder_merchandise',
                'pos_merchandise_joborder_tracker',
                'job_order',
                'merchandise_transaction',
                'form_staff_transactions_hub_merchandise_merchandiseForm',
                'form_staff_transactions_hub_merchandise_jobOrderForm'
            ];

            draftKeys.forEach(function (key) {
                // 1. Clear from localStorage immediately
                try { localStorage.removeItem('petron_draft_' + userId + '_' + key); } catch (e) {}

                // 2. Clear from server asynchronously (fire-and-forget)
                try {
                    fetch(DRAFTS_API + '?action=clear', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ module: key }),
                        credentials: 'same-origin'
                    }).catch(function () {});
                } catch (e) {}
            });
        })();
        </script>
        <?php
        // Active inner tab: merchandise | tracker
        $active_tab  = $_GET['active_tab'] ?? 'merchandise';
        if (!in_array($active_tab, ['merchandise','tracker'])) $active_tab = 'merchandise';
        $tracker_tab = $_GET['tracker_tab'] ?? 'pending';
        if (!in_array($tracker_tab, ['pending','approved','rejected'])) $tracker_tab = 'pending';

        $jo_pending  = array_values(array_filter($job_orders, fn($j) => ($j['validation_status'] ?? '') === 'Pending Validation'));
        $jo_approved = array_values(array_filter($job_orders, fn($j) => ($j['validation_status'] ?? '') === 'Approved'));
        $jo_rejected = array_values(array_filter($job_orders, fn($j) => ($j['status'] ?? '') === 'Rejected'));
        ?>

        <!-- ── Page Header ───────────────────────────────────────────── -->
        <div class="txn-section-header">
            <div class="txn-section-title">
                <div>
                    <h1>Transactions</h1>
                </div>
            </div>
            <div style="display:flex;gap:8px;align-items:center;">
                <span class="status-badge customer" style="display:none;"></span>
                <button type="button" id="headerBackButton" onclick="goBackFromTracker()" 
                        style="display: <?= $active_tab === 'tracker' ? 'inline-flex' : 'none' ?>;"
                        class="txn-btn secondary" title="Back to Merchandise/Service Transaction">
                    <i class="fas fa-arrow-left"></i> <span>Back</span>
                </button>
            </div>
        </div>

        <!-- ── Inner Tabs ─────────────────────────────────────────────── -->
        <div class="txn-subtab-nav">
            <?php
            // Variance alert badge: show warning count if any
            $tracker_badge_val  = $jo_pending_count > 0 ? $jo_pending_count : null;
            $tracker_badge_warn = $variance_alert_count > 0 ? $variance_alert_count : null;
            $inner_tabs = [
                'merchandise'   => ['label'=>'Merchandise/Service Transaction', 'icon'=>'fa-shopping-cart', 'color'=>'#28a745'],
                'tracker'       => ['label'=>'Job Order Tracker',               'icon'=>'fa-tasks',         'color'=>'#003d7a',
                                    'badge'=> $tracker_badge_val, 'badge_warn' => $tracker_badge_warn],
            ];
            foreach ($inner_tabs as $tk => $tc):
                $ia = ($active_tab === $tk);
            ?>
            <button onclick="switchInnerTab('<?= $tk ?>')"
                    id="innerTabBtn_<?= $tk ?>"
                    class="txn-subtab-btn <?= $tc['color'] === '#28a745' ? 'green' : 'darkblue' ?> <?= $ia ? 'active' : 'inactive' ?>"
                    style="white-space:nowrap;">
                <i class="fas <?= $tc['icon'] ?>"></i>
                <?= $tc['label'] ?>
                <?php if (!empty($tc['badge'])): ?>
                <span class="subtab-badge-val" style="background:<?= $ia ? '#ffffff' : '#002F70' ?>;color:<?= $ia ? '#002F70' : '#ffffff' ?>;font-size:10.5px;font-weight:800;
                             padding:1px 7px;border-radius:20px;"><?= $tc['badge'] ?> Pending</span>
                <?php endif; ?>
                <?php if (!empty($tc['badge_warn'])): ?>
                <span style="background:#dc2626;color:#fff;font-size:10px;font-weight:800;
                             padding:1px 7px;border-radius:20px;" title="Variance alerts detected">
                    ⚠ <?= $tc['badge_warn'] ?>
                </span>
                <?php endif; ?>
            </button>
            <?php endforeach; ?>

        </div>


        <!-- ══════════════════════════════════════════════════════════
             TAB 1: MERCHANDISE/SERVICE TRANSACTION
        ══════════════════════════════════════════════════════════ -->
        <div id="innerTab_merchandise" style="display:<?= $active_tab === 'merchandise' ? 'block' : 'none' ?>;">

        <!-- Cart layout -->
        <div class="cart-wrapper <?= !empty($_GET['mh_open']) ? 'history-view' : '' ?>">

            <!-- Left: Job Order section (top) + Merchandise section (bottom) + Customer/Payment -->
            <div style="flex:1; min-width:0; overflow:visible;">

                <!-- ══ JOB ORDER SECTION (TOP) ══════════════════════════════ -->
                <div class="txn-card" id="joCard" style="overflow:visible;position:relative;z-index:10;">
                    <div class="txn-card-header" style="background:#fffbeb;">
                        <i class="fas fa-tools" style="color:#b45309;"></i>
                        <h3 style="color:#92400e;">Job Order</h3>
                    </div>
                    <div class="txn-card-body" style="overflow:visible;">

                        <!-- Customer Details — New Radio Button Selection -->
                        <div style="font-size:11px;font-weight:700;color:#b45309;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;">
                            <i class="fas fa-user" style="margin-right:5px;"></i>Customer Details
                        </div>
                        
                        <!-- Customer Type Selection - REMOVED: Now only Registered Customers -->
                        <input type="hidden" id="joCustomerModeType" value="walkin">

                        <!-- Customer Input Fields -->
                        <div id="joCustomerLockedBanner" style="display:none;background:#ecfdf5;border:1.5px solid #6ee7b7;border-radius:8px;padding:8px 14px;margin-bottom:10px;font-size:12px;color:#065f46;align-items:center;justify-content:space-between;">
                            <div style="display:flex;align-items:center;gap:8px;">
                                <i class="fas fa-lock" style="color:#059669;"></i>
                                <span>Customer info locked.</span>
                            </div>
                            <button type="button" onclick="clearSelectedCustomerFull('jo')" title="Unlock / Clear Customer" style="background:transparent !important;border:none !important;color:#dc2626 !important;cursor:pointer;font-size:16px;padding:2px 6px;line-height:1;display:inline-flex;align-items:center;justify-content:center;">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div class="txn-form-grid" style="margin-bottom:14px;">
                            <div class="txn-field" style="position:relative;">
                                <label>First Name <span style="color:#dc2626;">*</span></label>
                                <input type="text" 
                                       id="joFirstName" 
                                       class="txn-input"
                                       placeholder="Type customer name or search..."
                                       autocomplete="off"
                                       oninput="unlockCustomerIfNeeded('jo'); searchCustomerByName('jo')"
                                       onfocus="searchCustomerByName('jo')">
                                <!-- First Name Dropdown -->
                                <div id="joFirstNameResults" 
                                     style="display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;
                                            background:#fff;border:1px solid #cbd5e1;border-radius:8px;
                                            max-height:280px;overflow-y:auto;z-index:100;
                                            box-shadow:0 8px 24px rgba(0,0,0,.12);">
                                </div>
                            </div>
                            <div class="txn-field">
                                <label>Last Name</label>
                                <input type="text" 
                                       id="joLastName" 
                                       class="txn-input"
                                       placeholder="Customer last name"
                                       autocomplete="off">
                            </div>
                        </div>
                        <div class="txn-form-grid" style="margin-bottom:14px;">
                            <div class="txn-field">
                                <label>Contact Number</label>
                                <input type="text" id="joContactNumber" class="txn-input"
                                       placeholder="e.g. 09XX-XXX-XXXX"
                                       autocomplete="off">
                            </div>
                        </div>

                        <!-- Vehicle Details -->
                        <div style="font-size:11px;font-weight:700;color:#b45309;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">
                            <i class="fas fa-car" style="margin-right:5px;"></i>Vehicle Details
                        </div>
                        <div class="txn-form-grid" style="margin-bottom:14px;">
                            <div class="txn-field">
                                <label>Vehicle Type</label>
                                <div style="display:flex;gap:6px;align-items:flex-start;">
                                    <div style="flex:1;position:relative;z-index:50;">
                                        <input type="text" 
                                               id="joVehicleType" 
                                               class="txn-input" 
                                               style="flex:1;width:100%;" 
                                               placeholder="Type or select vehicle type..."
                                               autocomplete="off"
                                               oninput="filterVehicleDropdown(this.value)"
                                               onfocus="showVehicleDropdown()"
                                               onblur="setTimeout(hideVehicleDropdown,200)"
                                               onchange="onVehicleTypeChange()">
                                        <div id="vehicleTypeDropdown"
                                             style="display:none;position:absolute;top:100%;left:0;right:0;
                                                    background:#fff;border:1.5px solid #cbd5e1;border-top:none;
                                                    border-radius:0 0 8px 8px;max-height:220px;overflow-y:auto;
                                                    z-index:999;box-shadow:0 8px 24px rgba(0,0,0,.12);">
                                        </div>
                                    </div>
                                    <button type="button"
                                            onclick="openAddVehicleModal()"
                                            title="Request new vehicle"
                                            class="txn-icon-btn blue"
                                            style="position:relative;z-index:10;width:38px;height:38px;display:flex;align-items:center;justify-content:center;font-weight:bold;font-size:16px;">
                                        +
                                    </button>
                                </div>
                            </div>
                            <div class="txn-field">
                                <label>Plate Number <span style="color:#dc2626;">*</span></label>
                                <input type="text" id="joVehiclePlate" class="txn-input"
                                       placeholder="e.g. ABC 1234"
                                       style="text-transform:uppercase;"
                                       autocomplete="off">
                            </div>
                        </div>
                        
                        <!-- Vehicle Brand + Model -->
                        <div class="txn-form-grid" style="margin-bottom:14px;">
                            <div class="txn-field">
                                <label>Vehicle Brand</label>
                                <input type="text" id="joVehicleBrand" class="txn-input auto-pull"
                                       placeholder="e.g. Toyota, Honda..."
                                       autocomplete="off">
                            </div>
                            <div class="txn-field">
                                <label>Vehicle Model</label>
                                <input type="text" id="joVehicleModel" class="txn-input"
                                       placeholder="e.g. Vios, City, Civic..."
                                       autocomplete="off">
                            </div>
                        </div>
                        <!-- Year Model + Odometer -->
                        <div class="txn-form-grid" style="margin-bottom:14px;">
                            <div class="txn-field">
                                <label>Year Model <span style="color:#94a3b8;font-weight:400;">(optional)</span></label>
                                <input type="number" id="joYearModel" class="txn-input"
                                       placeholder="e.g. 2020" min="1900" max="2099"
                                       autocomplete="off">
                            </div>
                            <div class="txn-field">
                                <label>Odometer Reading</label>
                                <input type="text" id="joOdometer" class="txn-input"
                                       placeholder="e.g. 35,000 km"
                                       autocomplete="off"
                                       oninput="formatOdometerInput(this)">
                            </div>
                        </div>

                        <!-- Engine Number + Chassis Number (VIN) [Vehicle Identification & Security] -->
                        <div class="txn-form-grid" style="margin-bottom:14px;">

                            <div class="txn-field">
                                <label>Engine Number <span style="color:#dc2626;">*</span></label>
                                <input type="text" id="joEngineNumber" class="txn-input"
                                       placeholder="e.g. 1NZ-FE-1234567"
                                       style="text-transform:uppercase;"
                                       autocomplete="off"
                                       oninput="formatEngineNumberInput(this)">
                            </div>
                            <div class="txn-field">
                                <label>Chassis Number (VIN) <span style="color:#dc2626;">*</span></label>
                                <input type="text" id="joChassisNumber" class="txn-input"
                                       placeholder="e.g. MHFXE1234567890"
                                       style="text-transform:uppercase;"
                                       autocomplete="off"
                                       oninput="checkVehicleSecurityWarning()">
                            </div>
                        </div>

                        <!-- Real-time Vehicle Security Warning Alert -->
                        <div id="joVehicleSecurityWarningBox" style="display:none; background:#fffbe6; border:1px solid #ffe58f; border-radius:6px; padding:10px 14px; margin-bottom:14px; color:#d46b08; font-size:12px; font-weight:600; line-height:1.5;">
                            <i class="fas fa-shield-alt" style="margin-right:6px; color:#fa8c16; font-size:14px;"></i>
                            <span id="joVehicleSecurityWarningText"></span>
                        </div>

                        <div style="font-size:11px;font-weight:700;color:#b45309;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;margin-top:6px;padding-top:12px;border-top:1px solid #fde68a;">
                            <i class="fas fa-file-alt" style="margin-right:5px;"></i>Job Order Information
                        </div>
                        <div class="txn-form-grid" style="margin-bottom:14px;">
                            <div class="txn-field">
                                <label>JO Number</label>
                                <input type="text" id="joNumber" class="txn-input"
                                       placeholder="Auto-Generated" readonly
                                       style="background:#f8fafc;color:#64748b;font-style:italic;cursor:not-allowed;">
                            </div>
                            <div class="txn-field">
                                <label>JO Date</label>
                                <input type="date" id="joDate" class="txn-input"
                                       value="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>
                        <div class="txn-form-grid" style="margin-bottom:14px;">
                            <div class="txn-field">
                                <label>Priority</label>
                                <div style="display:flex;gap:20px;padding:10px 12px;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:8px;align-items:center;">
                                    <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-weight:600;font-size:13px;">
                                        <input type="radio" name="joPriority" id="joPriorityNormal" value="Normal" checked
                                               style="accent-color:#002F70;width:15px;height:15px;">
                                        <span style="color:#334155;">Normal</span>
                                    </label>
                                    <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-weight:600;font-size:13px;">
                                        <input type="radio" name="joPriority" id="joPriorityUrgent" value="Urgent"
                                               style="accent-color:#dc2626;width:15px;height:15px;">
                                        <span style="color:#dc2626;">Urgent</span>
                                    </label>
                                </div>
                            </div>
                            <div class="txn-field">
                                <label>Expected Release Date</label>
                                <input type="date" id="joExpectedRelease" class="txn-input">
                            </div>
                        </div>

                        <!-- ── STEP 5: VEHICLE INSPECTION ─────────────────────── -->
                        <div style="font-size:11px;font-weight:700;color:#b45309;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;margin-top:6px;padding-top:12px;border-top:1px solid #fde68a;">
                            <i class="fas fa-clipboard-check" style="margin-right:5px;"></i>Vehicle Inspection
                        </div>
                        <div style="background:#fffbeb;border:1.5px solid #fde68a;border-radius:10px;padding:14px 16px;margin-bottom:14px;">
                            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px 16px;margin-bottom:12px;">
                                <?php
                                try {
                                    $db_insp_stmt = $pdo->query("SELECT item_name FROM vehicle_inspection_items WHERE is_active=1 ORDER BY id ASC");
                                    $inspection_items = $db_insp_stmt->fetchAll(PDO::FETCH_COLUMN);
                                } catch (Exception $e) {
                                    $inspection_items = [];
                                }
                                if (empty($inspection_items)) {
                                    $inspection_items = ['Engine','Battery','Tires','Brakes','Lights','Cooling System','Suspension','Transmission Fluid','Air Filter','Wipers & Washers','Belts & Hoses','Steering System','Exhaust System','Others'];
                                }
                                foreach ($inspection_items as $insp_item):
                                    $insp_id = 'joInspect_' . str_replace([' ', '&', '/'], '_', $insp_item);
                                ?>
                                <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-size:12.5px;font-weight:500;color:#334155;padding:6px 8px;border-radius:6px;background:#fff;border:1px solid #e2e8f0;transition:background .15s;"
                                       onmouseover="this.style.background='#fef9c3'" onmouseout="this.style.background='#fff'">
                                    <input type="checkbox" id="<?= $insp_id ?>" name="jo_inspection[]" value="<?= htmlspecialchars($insp_item) ?>"
                                           style="accent-color:#b45309;width:15px;height:15px;flex-shrink:0;">
                                    <?= htmlspecialchars($insp_item) ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                            <div>
                                <label style="font-size:11px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:5px;">Remarks</label>
                                <textarea id="joInspectionRemarks" class="txn-input" rows="2"
                                          placeholder="Inspection remarks or findings..."
                                          style="resize:vertical;min-height:56px;font-size:13px;"></textarea>
                            </div>
                        </div>

                        <!-- ── STEP 6: CUSTOMER COMPLAINT ─────────────────────── -->
                        <div style="font-size:11px;font-weight:700;color:#b45309;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;padding-top:12px;border-top:1px solid #fde68a;">
                            <i class="fas fa-comment-dots" style="margin-right:5px;"></i>Customer Complaint
                        </div>
                        <div style="margin-bottom:14px;">
                            <textarea id="joCustomerComplaint" class="txn-input" rows="3"
                                      placeholder="Describe the customer's complaint or concern..."
                                      style="resize:vertical;min-height:72px;font-size:13px;"></textarea>
                        </div>

                        <!-- ── STEP 7: INITIAL ASSESSMENT ─────────────────── -->
                        <div style="font-size:11px;font-weight:700;color:#b45309;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;padding-top:12px;border-top:1px solid #fde68a;">
                            <i class="fas fa-stethoscope" style="margin-right:5px;"></i>Initial Assessment
                            <span style="font-size:10px;font-weight:400;color:#92400e;text-transform:none;letter-spacing:0;margin-left:8px;">(Manager may update later)</span>
                        </div>
                        <div style="margin-bottom:14px;">
                            <textarea id="joRepairRecommendation" class="txn-input" rows="3"
                                      placeholder="Staff initial assessment or findings..."
                                      style="resize:vertical;min-height:72px;font-size:13px;"></textarea>
                        </div>

                        <!-- Service Details -->
                        <div style="font-size:11px;font-weight:700;color:#b45309;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;padding-top:12px;border-top:1px solid #fde68a;">
                            <i class="fas fa-wrench" style="margin-right:5px;"></i>Service Details
                        </div>

                        <!-- Service Type with auto-info panel -->
                        <div style="margin-bottom:14px;">
                            <label style="font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:6px;">Service Type <span style="color:#dc2626;">*</span></label>
                            <div style="display:flex;gap:6px;align-items:flex-start;">
                                <div style="flex:1;position:relative;">
                                    <input type="text" 
                                           id="joServiceType" 
                                           class="txn-select" 
                                           placeholder="Type to search service..."
                                           autocomplete="off"
                                           style="width:100%;padding-right:30px;"
                                           oninput="filterServiceTypes()"
                                           onfocus="showServiceDropdown()">
                                    <input type="hidden" id="joServiceTypeValue" value="">
                                    <i class="fas fa-chevron-down" 
                                       style="position:absolute;right:10px;top:50%;transform:translateY(-50%);
                                              color:#94a3b8;font-size:12px;pointer-events:none;"></i>
                                    <div id="joServiceTypeDropdown" 
                                         onmousedown="event.preventDefault()"
                                         style="display:none;position:absolute;top:100%;left:0;right:0;
                                                background:white;border:1px solid #e2e8f0;border-radius:6px;
                                                box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);
                                                max-height:300px;overflow-y:auto;z-index:1000;margin-top:2px;">
                                        <div id="joServiceTypeList"></div>
                                    </div>
                                </div>
                                <button type="button"
                                        onclick="openAddServiceModal()"
                                        title="Request new service type"
                                        class="txn-btn primary"
                                        style="padding:6px 12px;height:38px;align-self:stretch;display:flex;align-items:center;justify-content:center;font-weight:bold;font-size:16px;">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>

                            <!-- Auto-display service info (shown when service type selected) -->
                            <div id="joServiceAutoInfo" style="display:none;margin-top:10px;background:#f0f7ff;border:1.5px solid #bfdbfe;border-radius:10px;padding:10px 16px;">
                                <div>
                                    <div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;">Category</div>
                                    <div id="joServiceAutoCategory" style="font-size:13px;font-weight:600;color:#1e40af;">—</div>
                                </div>
                            </div>
                        </div>

                        <!-- Service Price + Labor — separate editable fields -->
                        <div class="txn-form-grid" style="margin-bottom:14px;">
                            <div class="txn-field">
                                <label>Service Price <span style="color:#dc2626;">*</span>
                                    <span style="font-size:10px;font-weight:400;color:#64748b;">(Auto-filled, editable)</span>
                                </label>
                                <div style="position:relative;">
                                    <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);font-weight:700;color:#374151;font-size:14px;">₱</span>
                                    <input type="number" id="joServicePrice" class="txn-input"
                                           step="0.01" min="0" placeholder="0.00"
                                           style="padding-left:26px;font-weight:700;"
                                           oninput="onJoServicePriceInput()">
                                </div>
                            </div>
                            <div class="txn-field">
                                <label>Labor Charge
                                    <span style="font-size:10px;font-weight:400;color:#64748b;">(Optional, separate)</span>
                                </label>
                                <div style="position:relative;">
                                    <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);font-weight:700;color:#374151;font-size:14px;">₱</span>
                                    <input type="text" id="joLaborCharge" class="txn-input"
                                           inputmode="decimal" placeholder="0.00"
                                           style="padding-left:26px;font-weight:700;"
                                           oninput="formatPesoInput(this); onJoLaborChargeInput()">
                                </div>
                            </div>
                        </div>

                        <!-- Hidden price notes + hidden category -->
                        <select id="joServiceCategory" style="display:none;"></select>
                        <div id="joServicePriceNotes" style="display:none;"><span id="joServicePriceNotesText"></span></div>

                        <!-- Assigned Mechanic + Notes -->
                        <div class="txn-form-grid cols-3" style="margin-bottom:6px;">
                            <div class="txn-field">
                                <label>Assigned Mechanic</label>
                                <div style="position:relative;">
                                    <input type="text"
                                           id="joMechanic"
                                           class="txn-input"
                                           placeholder="Type to search mechanic…"
                                           autocomplete="off"
                                           oninput="filterMechanicDropdown(this.value)"
                                           onfocus="showMechanicDropdown()"
                                           onblur="setTimeout(hideMechanicDropdown, 200)"
                                           style="padding-right:30px;">
                                    <input type="hidden" id="joMechanicId"   value="">
                                    <input type="hidden" id="joMechanicName" value="">
                                    <i class="fas fa-chevron-down"
                                       style="position:absolute;right:10px;top:50%;transform:translateY(-50%);
                                              color:#94a3b8;font-size:12px;pointer-events:none;"></i>
                                    <div id="joMechanicDropdown"
                                         style="display:none;position:absolute;top:100%;left:0;right:0;
                                                background:#fff;border:1.5px solid #cbd5e1;border-top:none;
                                                border-radius:0 0 8px 8px;max-height:220px;overflow-y:auto;
                                                z-index:999;box-shadow:0 8px 24px rgba(0,0,0,.12);">
                                        <?php foreach ($mechanics as $mech): ?>
                                        <div class="jo-mechanic-item"
                                             data-id="<?= (int)$mech['id'] ?>"
                                             data-name="<?= htmlspecialchars($mech['full_name']) ?>"
                                             data-spec="<?= htmlspecialchars($mech['specialization'] ?? '') ?>"
                                             onclick="selectMechanic(this)"
                                             style="padding:9px 14px;cursor:pointer;font-size:13px;
                                                    color:#1e293b;border-bottom:1px solid #f1f5f9;
                                                    transition:background .12s;">
                                            <?= htmlspecialchars($mech['full_name']) ?>
                                            <?php if (!empty($mech['specialization'])): ?>
                                            <span style="color:#64748b;font-size:11px;"> — <?= htmlspecialchars($mech['specialization']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php endforeach; ?>
                                        <?php if (empty($mechanics)): ?>
                                        <div style="padding:10px 14px;color:#94a3b8;font-size:12px;">No mechanics on record</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="txn-field">
                                <label>Estimated Duration (mins)</label>
                                <input type="number" id="joEstimatedDuration" class="txn-input"
                                       placeholder="e.g. 60" min="1" step="1"
                                       autocomplete="off">
                            </div>
                            <div class="txn-field">
                                <label>Notes</label>
                                <input type="text" id="joNotes" class="txn-input"
                                       placeholder="Any additional remarks…"
                                       autocomplete="off">
                            </div>
                        </div>




                        <!-- Bottom Action Buttons -->
                        <div style="display:flex;gap:10px;margin-top:16px;justify-content:flex-end;flex-wrap:wrap;">
                            <button type="button" class="txn-btn secondary" onclick="resetJobOrderForm()" title="Reset all job order fields">
                                <i class="fas fa-undo"></i> Reset
                            </button>
                            <button type="button" class="txn-btn success" onclick="submitJobOrder()" id="joSubmitBtn">
                                <i class="fas fa-paper-plane"></i> Submit Job Order
                            </button>
                        </div>

                        <!-- Mechanic busy warning banner -->
                        <div id="joMechanicBusyWarn" style="display:none;margin-bottom:14px;
                             background:#fffbeb;border:1.5px solid #f59e0b;border-radius:8px;
                             padding:10px 14px;font-size:12px;color:#92400e;">
                            <div style="display:flex;align-items:flex-start;gap:8px;">
                                <i class="fas fa-exclamation-triangle" style="color:#f59e0b;margin-top:1px;flex-shrink:0;"></i>
                                <div>
                                    <strong>Mechanic may be busy</strong> — this mechanic has ongoing job order(s):
                                    <div id="joMechanicBusyList" style="margin-top:6px;display:flex;flex-direction:column;gap:4px;"></div>
                                    <div style="margin-top:6px;font-size:11px;color:#b45309;">
                                        You can still proceed. Make sure to note this in the <em>Notes</em> field if needed.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Suggested Parts Preview (auto-populated when service type selected) -->
                        <div id="joSuggestedParts" style="display:none;margin-bottom:14px;">
                            <div style="font-size:11px;font-weight:700;color:#b45309;text-transform:uppercase;
                                        letter-spacing:.5px;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                                <i class="fas fa-boxes"></i> Suggested Parts
                                <span style="font-size:10px;font-weight:400;color:#64748b;text-transform:none;letter-spacing:0;">
                                    — will be added to cart automatically
                                </span>
                            </div>
                            <div id="joSuggestedPartsList"
                                 style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;
                                        padding:10px 12px;font-size:12px;max-height:180px;overflow-y:auto;">
                            </div>
                        </div>

                        <!-- Parts loading indicator -->
                        <span id="joPartsLoadingIndicator"
                              style="display:none;font-size:11px;color:#64748b;align-items:center;gap:5px;">
                            <i class="fas fa-spinner fa-spin" style="color:#b45309;"></i> Fetching parts…
                        </span>

                    </div><!-- /txn-card-body -->
                </div><!-- /joCard -->


                <!-- ══ MERCHANDISE SECTION (BOTTOM) ════════════════════════ -->
                <div class="txn-card">
                    <div class="txn-card-header">
                        <i class="fas fa-shopping-cart" style="color:#28a745;"></i>
                        <h3>Merchandise</h3>
                    </div>

                    <!-- ── Merchandise sub-tabs ─────────────────────────────── -->
                    <div style="display:flex;gap:10px;padding:0 16px;margin-bottom:12px;margin-top:12px;align-items:center;flex-wrap:wrap;width:100%;">
                        <?php $mh_open = isset($_GET['mh_open']) && $_GET['mh_open'] == '1'; ?>
                        <div class="txn-subtab-nav" style="margin-bottom:0 !important; max-width:520px;">
                            <button onclick="switchMerchTab('form')" id="merchTabBtn_form"
                                    class="txn-subtab-btn green <?= !$mh_open ? 'active' : 'inactive' ?>">
                                <i class="fas fa-shopping-cart"></i> Merchandise
                            </button>
                            <button onclick="switchMerchTab('history')" id="merchTabBtn_history"
                                    class="txn-subtab-btn green <?= $mh_open ? 'active' : 'inactive' ?>">
                                <i class="fas fa-history"></i> Merchandise History
                            </button>
                        </div>
                        <div id="merchHistoryHeaderButtons" style="display: <?= $mh_open ? 'flex' : 'none' ?>; gap:8px; align-items:center; margin-left:auto;">
                            <a href="staff_transactions_hub.php?section=merchandise&active_tab=merchandise"
                               title="Back to Merchandise Form"
                               class="txn-btn secondary"
                               style="font-size:12px;padding:7px 14px;">
                                <i class="fas fa-arrow-left"></i> Back
                            </a>
                        </div>
                    </div>

                    <!-- ── Sub-tab: Form ─────────────────────────────────────── -->
                    <div id="merchTab_form">
                    <div class="txn-card-body">

                        <!-- Customer Details — New Radio Button Selection -->
                        <div style="font-size:11px;font-weight:700;color:#28a745;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;">
                            <i class="fas fa-user" style="margin-right:5px;"></i>Customer Details
                        </div>
                        
                        <!-- Customer Type Selection - REMOVED: Now only Registered Customers -->
                        <input type="hidden" id="merchCustomerModeType" value="walkin">

                        <!-- Customer Input Fields (Merchandise) -->
                        <div id="merchCustomerLockedBanner" style="display:none;background:#ecfdf5;border:1.5px solid #6ee7b7;border-radius:8px;padding:8px 14px;margin-bottom:10px;font-size:12px;color:#065f46;align-items:center;justify-content:space-between;">
                            <div style="display:flex;align-items:center;gap:8px;">
                                <i class="fas fa-lock" style="color:#059669;"></i>
                                <span>Customer info locked.</span>
                            </div>
                            <button type="button" onclick="clearSelectedCustomerFull('merch')" title="Unlock / Clear Customer" style="background:transparent !important;border:none !important;color:#dc2626 !important;cursor:pointer;font-size:16px;padding:2px 6px;line-height:1;display:inline-flex;align-items:center;justify-content:center;">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div class="txn-form-grid" style="margin-bottom:14px;">
                            <div class="txn-field" style="position:relative;">
                                <label>First Name <span style="color:#dc2626;">*</span></label>
                                <input type="text"
                                       id="merchFirstName"
                                       class="txn-input"
                                       placeholder="Type customer name or search..."
                                       autocomplete="off"
                                       oninput="unlockCustomerIfNeeded('merch'); searchCustomerByName('merch')"
                                       onfocus="searchCustomerByName('merch')">
                                <!-- First Name Dropdown -->
                                <div id="merchFirstNameResults" 
                                     style="display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;
                                            background:#fff;border:1px solid #cbd5e1;border-radius:8px;
                                            max-height:280px;overflow-y:auto;z-index:100;
                                            box-shadow:0 8px 24px rgba(0,0,0,.12);">
                                </div>
                            </div>
                            <div class="txn-field">
                                <label>Last Name</label>
                                <input type="text"
                                       id="merchLastName"
                                       class="txn-input"
                                       placeholder="Customer last name"
                                       autocomplete="off">
                            </div>
                        </div>
                        <div class="txn-form-grid" style="margin-bottom:14px;">
                            <div class="txn-field">
                                <label>Contact Number</label>
                                <input type="text" id="merchContactNumber" class="txn-input"
                                       placeholder="e.g. 09XX-XXX-XXXX"
                                       autocomplete="off">
                            </div>
                        </div>

                        <!-- Merchandise Section label -->
                        <div style="font-size:11px;font-weight:700;color:#28a745;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">
                            <i class="fas fa-box" style="margin-right:5px;"></i>Merchandise Section
                        </div>

                        <div class="txn-form-grid">
                            <!-- Custom searchable dropdown -->
                            <div class="txn-field">
                                <label>Product</label>
                                <div id="productDropdownWrap" style="position:relative;">
                                    <div style="display:flex;gap:6px;align-items:center;">
                                        <div style="position:relative;flex:1;">
                                            <input type="text" id="productSearch" class="txn-input"
                                                   placeholder="Search by name, SKU, or category…"
                                                   oninput="filterProductDropdown()"
                                                   onfocus="openProductDropdown()"
                                                   autocomplete="off"
                                                   style="padding-right:34px;">
                                            <span id="productDropdownArrow"
                                                  onclick="toggleProductDropdown()"
                                                  style="position:absolute;right:10px;top:50%;transform:translateY(-50%);
                                                         cursor:pointer;color:#64748b;font-size:12px;user-select:none;">
                                                <i class="fas fa-chevron-down" id="productArrowIcon"></i>
                                            </span>
                                        </div>
                                        <!-- Request New Product plus button -->
                                        <button type="button"
                                                onclick="openAddProductModal()"
                                                title="Request new product"
                                                class="txn-btn primary"
                                                style="padding:6px 12px;height:38px;align-self:stretch;display:flex;align-items:center;justify-content:center;font-weight:bold;font-size:16px;">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </div>
                                    <!-- Dropdown list -->
                                    <div id="productDropdownList"
                                         onmousedown="event.preventDefault()"
                                         style="display:none;position:absolute;top:100%;left:0;right:0;
                                                background:#fff;border:1.5px solid #e2e8f0;border-top:none;
                                                border-radius:0 0 8px 8px;box-shadow:0 6px 20px rgba(0,0,0,.1);
                                                z-index:999;max-height:300px;overflow-y:auto;">
                                        <?php if (empty($merch_products)): ?>
                                        <div style="padding:14px;text-align:center;color:#94a3b8;font-size:13px;">
                                            No products found for this station.
                                        </div>
                                        <?php else: ?>
                                        <?php
                                        $last_cat = '';
                                        foreach ($merch_products as $p):
                                            $out_of_stock = (int)$p['stock_level'] <= 0;
                                        ?>
                                        <?php if ($p['category'] !== $last_cat): ?>
                                        <div class="prod-group-header"
                                             data-group="<?= htmlspecialchars($p['category']) ?>"
                                             style="padding:5px 12px 3px;font-size:10px;font-weight:700;
                                                    color:#64748b;text-transform:uppercase;letter-spacing:.6px;
                                                    background:#f8fafc;border-top:1px solid #f1f5f9;">
                                            <?= htmlspecialchars($p['category']) ?>
                                        </div>
                                        <?php $last_cat = $p['category']; endif; ?>
                                        <label class="prod-option"
                                             data-id="<?= (int)$p['product_id'] ?>"
                                             data-name="<?= htmlspecialchars($p['product_name']) ?>"
                                             data-sku="<?= htmlspecialchars($p['sku']) ?>"
                                             data-cat="<?= htmlspecialchars($p['category']) ?>"
                                             data-size="<?= htmlspecialchars($p['size']) ?>"
                                             data-price="<?= (float)$p['unit_price'] ?>"
                                             data-stock="<?= (int)$p['stock_level'] ?>"
                                             data-unit="<?= htmlspecialchars($p['unit'] ?? 'pc') ?>"
                                             data-search="<?= strtolower(htmlspecialchars($p['product_name'].' '.$p['sku'].' '.$p['category'].' '.$p['size'])) ?>"
                                             style="padding:8px 14px;cursor:pointer;
                                                    display:flex;align-items:center;
                                                    border-bottom:1px solid #f8fafc;gap:10px;
                                                    justify-content:space-between;
                                                    transition:background .15s;
                                                    <?= $out_of_stock ? 'opacity:.55;' : '' ?>"
                                             onmouseover="this.style.background='#f0f7ff'" onmouseout="this.style.background=''">
                                            <input type="checkbox"
                                                   class="merch-prod-checkbox"
                                                   data-id="<?= (int)$p['product_id'] ?>"
                                                   data-name="<?= htmlspecialchars($p['product_name']) ?>"
                                                   data-sku="<?= htmlspecialchars($p['sku']) ?>"
                                                   data-cat="<?= htmlspecialchars($p['category']) ?>"
                                                   data-size="<?= htmlspecialchars($p['size']) ?>"
                                                   data-price="<?= (float)$p['unit_price'] ?>"
                                                   data-stock="<?= (int)$p['stock_level'] ?>"
                                                   data-unit="<?= htmlspecialchars($p['unit'] ?? 'pc') ?>"
                                                   <?= $out_of_stock ? 'disabled' : '' ?>
                                                   onchange="onProductCheckboxChange(this)"
                                                   style="width:16px;height:16px;accent-color:#002F70;cursor:pointer;flex-shrink:0;">
                                            <!-- Product name + SKU -->
                                            <span style="min-width:0;flex:1;">
                                                <span style="font-size:13px;font-weight:600;color:<?= $out_of_stock ? '#94a3b8' : '#1e293b' ?>;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                                    <?= htmlspecialchars($p['product_name']) ?>
                                                    <?php if ($p['size']): ?>
                                                    <span style="font-weight:400;color:#64748b;"> · <?= htmlspecialchars($p['size']) ?></span>
                                                    <?php endif; ?>
                                                </span>
                                                <span style="font-size:10px;color:#94a3b8;display:block;margin-top:1px;">
                                                    SKU: <?= htmlspecialchars($p['sku']) ?>
                                                    <?php if ($out_of_stock): ?>
                                                    &nbsp;<span style="color:#ef4444;font-weight:700;">● Out of Stock</span>
                                                    <?php endif; ?>
                                                </span>
                                            </span>
                                        </label>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <!-- Hidden select for data storage / addToCart compatibility -->
                                <select id="productSelect" style="display:none;">
                                    <option value=""></option>
                                    <?php foreach ($merch_products as $p): ?>
                                    <option value="<?= (int)$p['product_id'] ?>"
                                            data-name="<?= htmlspecialchars($p['product_name']) ?>"
                                            data-sku="<?= htmlspecialchars($p['sku']) ?>"
                                            data-cat="<?= htmlspecialchars($p['category']) ?>"
                                            data-size="<?= htmlspecialchars($p['size']) ?>"
                                            data-price="<?= (float)$p['unit_price'] ?>"
                                            data-stock="<?= (int)$p['stock_level'] ?>"
                                            data-unit="<?= htmlspecialchars($p['unit'] ?? 'pc') ?>">
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Quantity -->
                            <div class="txn-field">
                                <label>Quantity</label>
                                <input type="number" id="itemQty" class="txn-input"
                                       min="1" value="1" placeholder="1">
                            </div>
                        </div>

                        <div class="txn-form-grid" style="margin-top:14px;">
                            <div class="txn-field">
                                <label>SKU / Product Code <span style="font-size:10px;color:#64748b;font-weight:400;">(Auto)</span></label>
                                <input type="text" id="itemSku" class="txn-input auto-pull" readonly placeholder="—" style="background:#f8fafc;font-weight:600;color:#002F70;">
                            </div>
                            <div class="txn-field">
                                <label>Category</label>
                                <input type="text" id="itemCategory" class="txn-input auto-pull" readonly placeholder="—">
                            </div>
                        </div>

                        <div class="txn-form-grid" style="margin-top:14px;">
                            <div class="txn-field">
                                <label>Unit Price</label>
                                <input type="number" id="itemUnitPrice" class="txn-input auto-pull"
                                       step="0.01" readonly placeholder="—">
                            </div>
                            <div class="txn-field">
                                <label>Stock Available</label>
                                <input type="text" id="itemStock" class="txn-input readonly-field" readonly placeholder="—" style="font-weight:600;">
                            </div>
                        </div>

                        <div style="display:flex;gap:10px;margin-top:18px;justify-content:flex-end;">
                            <button type="button" class="txn-btn secondary" onclick="fullResetMerchandiseForm()" title="Reset merchandise fields">
                                <i class="fas fa-undo"></i> Reset Form
                            </button>
                            <button type="button" class="txn-btn success" onclick="addProductFromFormToCart()" id="addItemBtn">
                                <i class="fas fa-plus"></i> Add Item
                            </button>
                        </div>

                    </div><!-- /txn-card-body -->
                    </div><!-- /merchTab_form -->

                    <!-- ── Sub-tab: History ──────────────────────────────────── -->
                    <div id="merchTab_history" style="display:none; padding-bottom: 80px; min-width:0; overflow:hidden;">
                        <!-- Filter bar -->
                        <div style="padding:14px 16px;border-bottom:1px solid #e2e8f0;">
                            <form method="GET" action="staff_transactions_hub.php" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                                <input type="hidden" name="section" value="merchandise">
                                <input type="hidden" name="mh_open" value="1">
                                <div style="display:flex;flex-direction:column;gap:4px;">
                                    <label style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">Type</label>
                                    <select name="mh_type" style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:12px;color:#1e293b;background:#fff;outline:none;">
                                        <option value="all" <?= $mh_filter_type === 'all' ? 'selected' : '' ?>>All Transactions</option>
                                        <option value="merchandise" <?= $mh_filter_type === 'merchandise' ? 'selected' : '' ?>>Merchandise Only</option>
                                        <option value="combined" <?= $mh_filter_type === 'combined' ? 'selected' : '' ?>>Combined Transaction</option>
                                    </select>
                                </div>
                                <div style="display:flex;flex-direction:column;gap:4px;">
                                    <label style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">Start Date</label>
                                    <input type="date" name="mh_start_date" value="<?= htmlspecialchars($mh_filter_start_date) ?>"
                                           style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:12px;color:#1e293b;background:#fff;outline:none;">
                                </div>
                                <div style="display:flex;flex-direction:column;gap:4px;">
                                    <label style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">End Date</label>
                                    <input type="date" name="mh_end_date" value="<?= htmlspecialchars($mh_filter_end_date) ?>"
                                           style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:12px;color:#1e293b;background:#fff;outline:none;">
                                </div>
                                <div style="display:flex;flex-direction:column;gap:4px;">
                                    <label style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">Category</label>
                                    <select name="mh_category" style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:12px;color:#1e293b;background:#fff;outline:none;">
                                        <option value="">All Categories</option>
                                        <?php foreach ($mh_categories as $cat): ?>
                                        <option value="<?= htmlspecialchars($cat) ?>" <?= $mh_filter_category === $cat ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cat) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div style="display:flex;flex-direction:column;gap:4px;">
                                    <label style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">Product</label>
                                    <select name="mh_product" style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:12px;color:#1e293b;background:#fff;outline:none;max-width:180px;">
                                        <option value="">All Products</option>
                                        <?php foreach ($merch_products as $prod): ?>
                                        <option value="<?= (int)$prod['product_id'] ?>" <?= $mh_filter_product == $prod['product_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($prod['product_name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div style="display:flex;flex-direction:column;gap:4px;">
                                    <label style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">Status</label>
                                    <select name="mh_status" style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:12px;color:#1e293b;background:#fff;outline:none;">
                                        <option value="">All Statuses</option>
                                        <option value="Completed" <?= ($_GET['mh_status'] ?? '') === 'Completed' ? 'selected' : '' ?>>Completed</option>
                                        <option value="Pending" <?= ($_GET['mh_status'] ?? '') === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                        <option value="Voided" <?= ($_GET['mh_status'] ?? '') === 'Voided' ? 'selected' : '' ?>>Voided</option>
                                    </select>
                                </div>
                                <button type="submit" class="txn-btn primary">
                                    <i class="fas fa-filter"></i> Filter
                                </button>
                                <a href="staff_transactions_hub.php?section=merchandise&mh_open=1" class="txn-btn secondary">
                                    <i class="fas fa-times"></i> Clear
                                </a>
                                <!-- Export buttons managed in header -->
                            </form>
                        </div>
                        <!-- Table -->
                        <div style="padding:0;">
                            <?php if (empty($mh_recent)): ?>
                            <div style="text-align:center;padding:36px;color:#94a3b8;">
                                <i class="fas fa-receipt" style="font-size:26px;display:block;margin-bottom:8px;"></i>
                                No merchandise transactions found.
                            </div>
                            <?php else: ?>
                            <div style="width:100%;overflow-x:hidden !important;">
                            <style>
                            #mhHistoryTable th { padding: 8px 10px; }
                            #mhHistoryTable td { padding: 8px 10px; }
                            #mhHistoryTable { table-layout: fixed !important; width: 100% !important; }
                            #mhHistoryTable td { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
                            </style>

                            <!-- ── Merchandise KPI Snapshot ─────────────────── -->
                            <div style="margin:12px 16px 4px;display:flex;align-items:center;gap:8px;">
                                <button type="button"
                                        id="mhKpiToggleBtn"
                                        onclick="var p=document.getElementById('mhKpiPanel');
                                                 var isOpen=p.style.display!=='none';
                                                 p.style.display=isOpen?'none':'block';"
                                        class="txn-btn success">
                                    <i class="fas fa-chart-bar"></i> My KPI Today
                                    <?php if ($mh_kpi_txn_count > 0): ?>
                                    <span style="background:#dcfce7;padding:1px 7px;border-radius:10px;
                                                 font-size:10px;font-weight:800;color:#1d6f42;"><?= (int)$mh_kpi_txn_count ?> txn<?= $mh_kpi_txn_count > 1 ? 's' : '' ?></span>
                                    <?php endif; ?>
                                </button>
                                <?php if (!empty($mh_variance_alerts)): ?>
                                <span style="display:inline-flex;align-items:center;gap:5px;background:#fee2e2;
                                             color:#dc2626;border:1px solid #fca5a5;border-radius:4px;
                                             padding:5px 10px;font-size:11px;font-weight:700;">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <?= count($mh_variance_alerts) ?> Variance Alert<?= count($mh_variance_alerts)>1?'s':'' ?>
                                </span>
                                <?php endif; ?>
                            </div>

                            <!-- KPI Panel -->
                            <div id="mhKpiPanel"
                                 style="display:<?= $mh_kpi_txn_count > 0 ? 'block' : 'none' ?>;
                                        margin:8px 16px 0;background:linear-gradient(135deg,#f0f7ff,#eff6ff);
                                        border:1px solid #bfdbfe;border-radius:10px;padding:14px 16px;">
                                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                                    <div style="font-size:12px;font-weight:700;color:#003d7a;">
                                        <i class="fas fa-chart-bar" style="margin-right:5px;"></i>
                                        Merchandise KPI — Today
                                    </div>
                                </div>
                                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
                                    <div style="background:#fff;border-radius:8px;padding:12px;
                                                border:1px solid #dbeafe;text-align:center;
                                                box-shadow:0 1px 4px rgba(0,47,110,.06);">
                                        <div style="font-size:26px;font-weight:800;color:#002F6C;line-height:1.1;">
                                            <?= (int)$mh_kpi_txn_count ?>
                                        </div>
                                        <div style="font-size:10px;font-weight:600;color:#64748b;
                                                    text-transform:uppercase;letter-spacing:.5px;margin-top:4px;">
                                            <i class="fas fa-receipt" style="color:#003d7a;margin-right:3px;"></i>
                                            Transactions Today
                                        </div>
                                    </div>
                                    <div style="background:#fff;border-radius:8px;padding:12px;
                                                border:1px solid #dbeafe;text-align:center;
                                                box-shadow:0 1px 4px rgba(0,47,110,.06);">
                                        <div style="font-size:26px;font-weight:800;color:#002F6C;line-height:1.1;">
                                            <?= (int)$mh_kpi_items_released ?>
                                        </div>
                                        <div style="font-size:10px;font-weight:600;color:#64748b;
                                                    text-transform:uppercase;letter-spacing:.5px;margin-top:4px;">
                                            <i class="fas fa-boxes" style="color:#003d7a;margin-right:3px;"></i>
                                            Items Released
                                        </div>
                                    </div>
                                    <div style="background:#fff;border-radius:8px;padding:12px;
                                                border:1px solid #dbeafe;text-align:center;
                                                box-shadow:0 1px 4px rgba(0,47,110,.06);">
                                        <div style="font-size:18px;font-weight:800;color:#002F6C;line-height:1.2;">
                                            <span id="mh_kpi_total_encoded">₱<?= number_format($mh_kpi_total_encoded, 2) ?></span>
                                        </div>
                                        <div style="font-size:10px;font-weight:600;color:#64748b;
                                                    text-transform:uppercase;letter-spacing:.5px;margin-top:4px;">
                                            <i class="fas fa-peso-sign" style="color:#003d7a;margin-right:3px;"></i>
                                            Total Encoded
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <table class="txn-table" id="mhHistoryTable" style="width:100%; table-layout:fixed;">
                                <colgroup>
                                    <col style="width:7%;"><!-- OR No. -->
                                    <col style="width:9%;"><!-- Transaction ID -->
                                    <col style="width:9%;"><!-- Customer -->
                                    <col style="width:10%;"><!-- Product -->
                                    <col style="width:3%;"><!-- Qty -->
                                    <col style="width:3%;"><!-- UOM -->
                                    <col style="width:7%;"><!-- Unit Price -->
                                    <col style="width:7%;"><!-- Total -->
                                    <col style="width:8%;"><!-- Pay Status -->
                                    <col style="width:8%;"><!-- Pay Method -->
                                    <col style="width:9%;"><!-- Date Released -->
                                    <col style="width:19%;"><!-- Actions -->
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th style="font-size:11px;text-align:left;padding:9px 8px;">OR No.</th>
                                        <th style="font-size:11px;text-align:left;padding:9px 8px;">Txn ID</th>
                                        <th style="font-size:11px;text-align:left;padding:9px 8px;">Customer</th>
                                        <th style="font-size:11px;text-align:left;padding:9px 8px;">Product</th>
                                        <th style="font-size:11px;text-align:center;padding:9px 4px;">Qty</th>
                                        <th style="font-size:11px;text-align:center;padding:9px 4px;">UOM</th>
                                        <th style="font-size:11px;text-align:right;padding:9px 8px;">Unit Price</th>
                                        <th style="font-size:11px;text-align:right;padding:9px 8px;">Total</th>
                                        <th style="font-size:11px;text-align:left;padding:9px 8px;">Pay Status</th>
                                        <th style="font-size:11px;text-align:left;padding:9px 8px;">Pay Method</th>
                                        <th style="font-size:11px;text-align:left;padding:9px 8px;">Date Released</th>
                                        <th style="font-size:11px;text-align:center;padding:9px 8px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="mhTableBody">
                                <?php foreach ($mh_recent as $txn):
                                     $qty = (float)$txn['quantity'];
                                     $qty_display = ($qty == (int)$qty) ? (int)$qty : $qty;
                                     $unit_price = (float)$txn['unit_price'];
                                     $item_total = (float)$txn['item_total'];
                                     $date_released = '—';
                                     if (!empty($txn['transaction_date'])) {
                                         try {
                                             $date_released = (new DateTime($txn['transaction_date']))->format('M j, Y g:i A');
                                         } catch (Exception $e) {}
                                     }
                                     // Get unit label directly from inventory (as-is, no normalization)
                                     $unit_label = $txn['unit'] ?? 'pc';

                                     // ── Determine action state ─────────────────────────────────────────
                                     $mh_val_status  = strtolower(trim($txn['validation_status'] ?? ''));
                                     $mh_pay_status  = strtolower(trim($txn['payment_status']   ?? ''));
                                     $mh_mt_id_str   = (string)$txn['mt_id'];

                                     // Check if there is a pending request for this transaction
                                     $mh_pending_req  = $mh_pending_requests[$mh_mt_id_str] ?? null;
                                     $mh_pending_type = $mh_pending_req ? $mh_pending_req['request_type'] : null;

                                     // Status flags
                                     $mh_is_voided   = in_array($mh_val_status, ['voided','cancelled','canceled']);
                                     $mh_is_adjusted = ($mh_val_status === 'adjusted');
                                     $mh_adj_req     = ($mh_pending_type === 'Adjustment');
                                     $mh_void_req    = ($mh_pending_type === 'Void');
                                 ?>
                                 <tr class="mh-row">
                                    <td style="font-size:11px;font-weight:700;color:#475569;padding:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:0;">
                                        <?= htmlspecialchars($txn['or_number'] ?? '—') ?>
                                    </td>
                                    <td style="font-size:11px;color:var(--petron-blue);font-family:monospace;padding:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:0;"
                                        title="<?= htmlspecialchars($txn['transaction_id'] ?? '') ?>">
                                        <?= htmlspecialchars($txn['transaction_id'] ?? ('#'.$txn['mt_id'])) ?>
                                    </td>
                                    <td style="font-size:12px;padding:10px;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                        title="<?= htmlspecialchars($txn['customer_name'] ?? '') ?>">
                                        <?= htmlspecialchars($txn['customer_name'] ?? 'No Customer') ?>
                                    </td>
                                    <td style="font-size:12px;padding:10px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                        title="<?= htmlspecialchars($txn['product_name'] ?? '') ?>">
                                        <?= htmlspecialchars($txn['product_name'] ?? '—') ?>
                                    </td>
                                    <td style="font-size:12px;text-align:center;font-weight:600;color:#475569;padding:10px;">
                                        <?= $qty_display ?>
                                    </td>
                                    <td style="font-size:12px;text-align:center;color:#64748b;padding:10px;">
                                        <?= htmlspecialchars($unit_label) ?>
                                    </td>
                                    <td style="font-size:12px;text-align:right;font-weight:600;color:#475569;padding:10px;white-space:nowrap;">
                                        ₱<?= number_format($unit_price, 2) ?>
                                    </td>
                                    <td style="font-size:12px;text-align:right;font-weight:700;color:var(--petron-blue);padding:10px;white-space:nowrap;">
                                        ₱<?= number_format($item_total, 2) ?>
                                    </td>
                                    <td style="font-size:11px;padding:8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:0;">
                                        <?php
                                            $mh_pstat_raw = $txn['payment_status'] ?? 'Pending';
                                            $mh_pstat_lc  = strtolower(trim($mh_pstat_raw));
                                            $mh_pstat_bg  = '#f1f5f9'; $mh_pstat_col = '#475569';
                                            if ($mh_pstat_lc === 'paid')                    { $mh_pstat_bg = '#dcfce7'; $mh_pstat_col = '#166534'; }
                                            elseif ($mh_pstat_lc === 'partial')             { $mh_pstat_bg = '#fef9c3'; $mh_pstat_col = '#854d0e'; }
                                            elseif (in_array($mh_pstat_lc, ['account receivable','credit','ar'])) { $mh_pstat_bg = '#ede9fe'; $mh_pstat_col = '#5b21b6'; }
                                            elseif (in_array($mh_pstat_lc, ['unpaid','pending','pending payment','unpaid'])) { $mh_pstat_bg = '#fee2e2'; $mh_pstat_col = '#b91c1c'; }
                                        ?>
                                        <span style="display:inline-block;padding:2px 6px;border-radius:4px;background:<?= $mh_pstat_bg ?>;color:<?= $mh_pstat_col ?>;font-size:10px;font-weight:700;white-space:nowrap;">
                                            <?= htmlspecialchars($mh_pstat_raw) ?>
                                        </span>
                                    </td>
                                    <td style="font-size:11px;color:#475569;padding:8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:0;" title="<?= htmlspecialchars($txn['payment_method'] ?? '') ?>">
                                        <?= htmlspecialchars($txn['payment_method'] ?? '—') ?>
                                    </td>
                                    <td style="font-size:11px;color:#64748b;padding:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                        <?= $date_released ?>
                                    </td>
                                     <td style="padding:6px;text-align:left;">
                                         <div style="display:flex;flex-direction:column;gap:3px;align-items:stretch;">

                                             <?php if ($mh_is_voided): ?>
                                             <!-- VOIDED Status: View only + Badge -->
                                             <button type="button" onclick="event.stopPropagation(); viewMerchandiseDetails('<?= addslashes($txn['mt_id']) ?>')"
                                                class="txn-btn secondary" style="width:100%;padding:4px 6px;font-size:10.5px;box-sizing:border-box;text-align:center;justify-content:center;">
                                                 <i class="fas fa-eye"></i> View
                                             </button>
                                             <span style="font-size:10.5px;color:#991b1b;font-weight:700;text-align:center;padding:2px 0;">
                                                 <i class="fas fa-ban"></i> Voided
                                             </span>

                                             <?php elseif ($mh_is_adjusted): ?>
                                             <!-- ADJUSTED Status: View, Reprint + Badge -->
                                             <button type="button" onclick="event.stopPropagation(); viewMerchandiseDetails('<?= addslashes($txn['mt_id']) ?>')"
                                                class="txn-btn secondary" style="width:100%;padding:4px 6px;font-size:10.5px;box-sizing:border-box;text-align:center;justify-content:center;">
                                                 <i class="fas fa-eye"></i> View
                                             </button>
                                             <a href="receipt.php?id=<?= urlencode($txn['transaction_id'] ?? '') ?>&type=merchandise" target="_blank" onclick="event.stopPropagation();"
                                                class="txn-btn secondary" style="width:100%;padding:4px 6px;font-size:10.5px;box-sizing:border-box;text-align:center;justify-content:center;text-decoration:none;">
                                                 <i class="fas fa-receipt"></i> Reprint
                                             </a>
                                             <span style="font-size:10.5px;color:#4338ca;font-weight:700;text-align:center;padding:2px 0;">
                                                 <i class="fas fa-check-circle"></i> Adjusted
                                             </span>

                                             <?php elseif ($mh_adj_req): ?>
                                             <!-- ADJUSTMENT REQUESTED Status: View, Reprint + Badge -->
                                             <button type="button" onclick="event.stopPropagation(); viewMerchandiseDetails('<?= addslashes($txn['mt_id']) ?>')"
                                                class="txn-btn secondary" style="width:100%;padding:4px 6px;font-size:10.5px;box-sizing:border-box;text-align:center;justify-content:center;">
                                                 <i class="fas fa-eye"></i> View
                                             </button>
                                             <a href="receipt.php?id=<?= urlencode($txn['transaction_id'] ?? '') ?>&type=merchandise" target="_blank" onclick="event.stopPropagation();"
                                                class="txn-btn secondary" style="width:100%;padding:4px 6px;font-size:10.5px;box-sizing:border-box;text-align:center;justify-content:center;text-decoration:none;">
                                                 <i class="fas fa-receipt"></i> Reprint
                                             </a>
                                             <span style="font-size:10.5px;color:#d97706;font-weight:700;text-align:center;padding:2px 0;" title="Pending manager review">
                                                 <i class="fas fa-clock"></i> Adjustment Requested
                                             </span>

                                             <?php elseif ($mh_void_req): ?>
                                             <!-- VOID REQUESTED Status: View, Reprint + Badge -->
                                             <button type="button" onclick="event.stopPropagation(); viewMerchandiseDetails('<?= addslashes($txn['mt_id']) ?>')"
                                                class="txn-btn secondary" style="width:100%;padding:4px 6px;font-size:10.5px;box-sizing:border-box;text-align:center;justify-content:center;">
                                                 <i class="fas fa-eye"></i> View
                                             </button>
                                             <a href="receipt.php?id=<?= urlencode($txn['transaction_id'] ?? '') ?>&type=merchandise" target="_blank" onclick="event.stopPropagation();"
                                                class="txn-btn secondary" style="width:100%;padding:4px 6px;font-size:10.5px;box-sizing:border-box;text-align:center;justify-content:center;text-decoration:none;">
                                                 <i class="fas fa-receipt"></i> Reprint
                                             </a>
                                             <span style="font-size:10.5px;color:#dc2626;font-weight:700;text-align:center;padding:2px 0;" title="Pending manager review">
                                                 <i class="fas fa-clock"></i> Void Requested
                                             </span>

                                             <?php else: ?>
                                             <!-- NORMAL / CORRECT: View, Reprint, Request Adjust, Request Void -->
                                             <button type="button" onclick="event.stopPropagation(); viewMerchandiseDetails('<?= addslashes($txn['mt_id']) ?>')"
                                                class="txn-btn secondary" style="width:100%;padding:4px 6px;font-size:10.5px;box-sizing:border-box;text-align:center;justify-content:center;">
                                                 <i class="fas fa-eye"></i> View
                                             </button>
                                             <a href="receipt.php?id=<?= urlencode($txn['transaction_id'] ?? '') ?>&type=merchandise" target="_blank" onclick="event.stopPropagation();"
                                                class="txn-btn secondary" style="width:100%;padding:4px 6px;font-size:10.5px;box-sizing:border-box;text-align:center;justify-content:center;text-decoration:none;">
                                                 <i class="fas fa-receipt"></i> Reprint
                                             </a>
                                             <button type="button" class="txn-btn secondary"
                                                onclick="event.stopPropagation(); openTxnRequestModal(event, '<?= addslashes($txn['mt_id']) ?>', 'merchandise_transactions', 'Adjustment', '<?= addslashes($txn['customer_name'] ?? '') ?>')"
                                                title="Request adjustment - wrong item/qty/price/customer details"
                                                style="width:100%;padding:4px 6px;font-size:10px;box-sizing:border-box;text-align:center;justify-content:center;cursor:pointer;">
                                                 <i class="fas fa-sliders-h"></i> Request Adjust
                                             </button>
                                             <button type="button" class="txn-btn danger"
                                                onclick="event.stopPropagation(); openTxnRequestModal(event, '<?= addslashes($txn['mt_id']) ?>', 'merchandise_transactions', 'Void', '<?= addslashes($txn['customer_name'] ?? '') ?>')"
                                                title="Request void - duplicate, cancelled or wrong transaction"
                                                style="width:100%;padding:4px 6px;font-size:10px;box-sizing:border-box;text-align:center;justify-content:center;cursor:pointer;">
                                                 <i class="fas fa-ban"></i> Request Void
                                             </button>
                                             <?php endif; ?>

                                         </div>
                                     </td>
                                 </tr>
                                 <?php endforeach; ?>
                                </tbody>
                            </table>
                            </div>
                            <!-- Rows per page + Pagination controls -->
                            <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;border-top:1px solid #e2e8f0;">
                                <!-- Rows per page -->
                                <div style="display:flex;align-items:center;gap:7px;">
                                    <label style="font-size:12px;white-space:nowrap;">Rows per page:</label>
                                    <select id="mhPerPage" onchange="mhChangePerPage()" class="pag-select">
                                        <option value="10" selected>10</option>
                                        <option value="20">20</option>
                                        <option value="30">30</option>
                                        <option value="40">40</option>
                                        <option value="50">50</option>
                                    </select>
                                </div>
                                <!-- Page indicator + arrows -->
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <button id="mhPrevBtn" onclick="mhGoPage(mhState.page - 1)" class="pag-btn">
                                        <i class="fas fa-chevron-left"></i>
                                    </button>
                                    <span id="mhPageLabel" style="font-size:13px;color:#495057;white-space:nowrap;">Page 1 of 1</span>
                                    <button id="mhNextBtn" onclick="mhGoPage(mhState.page + 1)" class="pag-btn">
                                        <i class="fas fa-chevron-right"></i>
                                    </button>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div><!-- /merchTab_history -->

                    <script>
                    (function(){
                        // Global capturing listener for Merchandise History action buttons

                        var mhOpen = <?= (!empty($_GET['mh_open'])) ? 'true' : 'false' ?>;

                        // ── Merchandise History pagination ────────────────────
                        var mhState = { page: 1, per_page: 10 };

                        function mhRender() {
                            var rows = document.querySelectorAll('#mhTableBody .mh-row');
                            var total = rows.length;
                            var perPage = mhState.per_page;
                            var page = mhState.page;

                            var foot = document.getElementById('mhPerPage') ? document.getElementById('mhPerPage').closest('div[style*="display:flex"]') : null;
                            if (foot) {
                                foot.style.display = total <= 10 ? 'none' : 'flex';
                            }
                            if (total <= 10) {
                                rows.forEach(function(row) { row.style.display = ''; });
                                return;
                            }

                            var totalPages = Math.max(1, Math.ceil(total / perPage));
                            if (page > totalPages) { mhState.page = page = totalPages; }

                            var start = (page - 1) * perPage;
                            var end   = start + perPage;
                            rows.forEach(function(row, i) {
                                row.style.display = (i >= start && i < end) ? '' : 'none';
                            });

                            var lbl = document.getElementById('mhPageLabel');
                            if (lbl) lbl.textContent = 'Page ' + page + ' of ' + totalPages;

                            var prev = document.getElementById('mhPrevBtn');
                            var next = document.getElementById('mhNextBtn');
                            if (prev) prev.disabled = (page <= 1);
                            if (next) next.disabled = (page >= totalPages);
                            if (prev) prev.style.opacity = (page <= 1) ? '0.4' : '1';
                            if (next) next.style.opacity = (page >= totalPages) ? '0.4' : '1';
                        }

                        window.mhState = mhState;
                        window.mhGoPage = function(p) {
                            var rows = document.querySelectorAll('#mhTableBody .mh-row');
                            var totalPages = Math.max(1, Math.ceil(rows.length / mhState.per_page));
                            if (p < 1 || p > totalPages) return;
                            mhState.page = p;
                            mhRender();
                        };
                        window.mhChangePerPage = function() {
                            var sel = document.getElementById('mhPerPage');
                            if (sel) mhState.per_page = parseInt(sel.value);
                            mhState.page = 1;
                            mhRender();
                        };

                        // ── Tab switcher ──────────────────────────────────────
                        function switchMerchTab(tab) {
                            var isHistory = (tab === 'history');
                            var formPanel = document.getElementById('merchTab_form');
                            var histPanel = document.getElementById('merchTab_history');
                            var formBtn   = document.getElementById('merchTabBtn_form');
                            var histBtn   = document.getElementById('merchTabBtn_history');
                            if (!formPanel || !histPanel) return;

                            // Show/hide sub-tab panels
                            formPanel.style.display = isHistory ? 'none' : 'block';
                            histPanel.style.display = isHistory ? 'block' : 'none';

                            // When history is active: hide cart panel and use single column
                            // When form is active: show cart panel with 2-column grid
                            var cartWrapper = document.querySelector('.cart-wrapper');
                            var cartPanel   = document.querySelector('.cart-panel');
                            
                            if (cartWrapper && cartPanel) {
                                if (isHistory) {
                                    // History mode: single column, hide cart
                                    cartWrapper.classList.add('history-view');
                                    cartPanel.style.display = 'none';
                                } else {
                                    // Form mode: two column grid, show cart
                                    cartWrapper.classList.remove('history-view');
                                    cartPanel.style.display = 'block';
                                }
                            }

                            // Tab button styles — use CSS classes to override global button rule
                            formBtn.className = 'txn-subtab-btn green ' + (isHistory ? 'inactive' : 'active');
                            histBtn.className = 'txn-subtab-btn green ' + (isHistory ? 'active' : 'inactive');

                            var headerBtns = document.getElementById('merchHistoryHeaderButtons');
                            if (headerBtns) {
                                headerBtns.style.display = isHistory ? 'flex' : 'none';
                            }











                            // Update URL so refresh keeps the tab open
                            if (window.history && window.history.replaceState) {
                                var url = new URL(window.location.href);
                                if (isHistory) {
                                    url.searchParams.set('mh_open', '1');
                                } else {
                                    url.searchParams.delete('mh_open');
                                }
                                window.history.replaceState(null, '', url.toString());
                            }
                        }
                        window.switchMerchTab = switchMerchTab;

                        // Init
                        mhRender();
                        if (mhOpen) switchMerchTab('history');
                    })();
                    </script>

                </div><!-- /merchandise card -->

            </div><!-- /left column -->

            <!-- Payment + Cart panel — right side -->
            <div class="cart-panel" <?= !empty($_GET['mh_open']) ? 'style="display:none;"' : '' ?>>

                <!-- ── Single-column inner layout: Payment top, Cart bottom ── -->
                <div style="display:flex; flex-direction:column; gap:0; min-height:320px;">

                <!-- ── Customer & Payment (top) ─── -->
                <div class="cart-panel-top" style="border-bottom:2px solid #f1f5f9;">
                    <!-- Customer & Payment header -->
                    <div style="font-size:11px;font-weight:700;color:#1e293b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;display:flex;align-items:center;gap:6px;">
                        <i class="fas fa-credit-card" style="color:var(--petron-blue);"></i>Payment
                    </div>

                    <!-- Shift info -->
                    <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:6px 10px;margin-bottom:10px;font-size:10px;color:#166534;display:flex;align-items:center;gap:6px;">
                        <i class="fas fa-clock"></i>
                        <span><strong><?= htmlspecialchars($merch_shift_name) ?></strong> · <?= htmlspecialchars($me['name'] ?? $me['username']) ?></span>
                    </div>

                    <!-- Payment Method -->
                    <div class="txn-field" style="margin-bottom:8px;">
                        <label style="font-size:10px;font-weight:600;color:#475569;">Payment Method <span style="color:#dc2626;">*</span></label>
                        <select id="paymentMethod" class="txn-select" style="font-size:12px;padding:7px 10px;" onchange="onPaymentChange()" required>
                            <option value="">-- Select Payment Method --</option>
                            <option value="Cash">Cash</option>
                            <option value="Credit Card">Credit Card</option>
                            <option value="Debit Card">Debit Card</option>
                            <option value="GCash">GCash</option>
                            <option value="Maya">Maya</option>
                            <option value="Petron Fleet Card">Petron Fleet Card</option>
                            <option value="Credit Account">Credit Account</option>
                        </select>
                    </div>

                    <!-- Cash fields -->
                    <div id="cashFields" style="display:none;margin-bottom:8px;">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                            <div class="txn-field">
                                <label style="font-size:10px;font-weight:600;color:#475569;">Amount Tendered</label>
                                <input type="number" id="amountTendered" class="txn-input" style="font-size:12px;padding:7px 10px;"
                                       step="0.01" min="0" placeholder="₱0.00"
                                       oninput="computeChange()">
                            </div>
                            <div class="txn-field" id="changeWrap" style="display:none;">
                                <label style="font-size:10px;font-weight:600;color:#475569;">Change</label>
                                <input type="number" id="changeAmount" class="txn-input" style="font-size:12px;padding:7px 10px;background:#f0fdf4;" readonly placeholder="—">
                            </div>
                            <div class="txn-field" id="cashBalanceWrap" style="display:none;">
                                <label style="font-size:10px;font-weight:600;color:#475569;">Balance Due</label>
                                <input type="number" id="cashBalanceDue" class="txn-input" style="font-size:12px;padding:7px 10px;background:#fff7ed;" readonly placeholder="—">
                            </div>
                        </div>
                    </div>

                    <!-- Credit Card fields -->
                    <div id="creditCardFields" style="display:none;margin-bottom:8px;">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
                            <div class="txn-field">
                                <label style="font-size:10px;font-weight:600;color:#475569;">Amount Paid</label>
                                <input type="number" id="ccAmount" class="txn-input" style="font-size:12px;padding:7px 10px;"
                                       step="0.01" min="0" placeholder="₱0.00"
                                       oninput="onPaymentAmountInput('ccAmount')">
                            </div>
                            <div class="txn-field">
                                <label style="font-size:10px;font-weight:600;color:#475569;">Card Type</label>
                                <select id="ccType" class="txn-select" style="font-size:12px;padding:7px 10px;">
                                    <option value="Visa">Visa</option>
                                    <option value="Mastercard">Mastercard</option>
                                    <option value="AMEX">American Express</option>
                                    <option value="JCB">JCB</option>
                                    <option value="UnionPay">UnionPay</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                            <div class="txn-field">
                                <label style="font-size:10px;font-weight:600;color:#475569;">Last 4 Digits (Opt)</label>
                                <input type="text" id="ccLastFour" class="txn-input" style="font-size:12px;padding:7px 10px;" maxlength="4" placeholder="e.g. 1234">
                            </div>
                            <div class="txn-field">
                                <label style="font-size:10px;font-weight:600;color:#475569;">Reference No.</label>
                                <input type="text" id="ccRefNumber" class="txn-input" style="font-size:12px;padding:7px 10px;" placeholder="Ref #">
                            </div>
                        </div>
                    </div>

                    <!-- Debit Card fields -->
                    <div id="debitCardFields" style="display:none;margin-bottom:8px;">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
                            <div class="txn-field">
                                <label style="font-size:10px;font-weight:600;color:#475569;">Amount Paid</label>
                                <input type="number" id="dcAmount" class="txn-input" style="font-size:12px;padding:7px 10px;"
                                       step="0.01" min="0" placeholder="₱0.00"
                                       oninput="onPaymentAmountInput('dcAmount')">
                            </div>
                            <div class="txn-field">
                                <label style="font-size:10px;font-weight:600;color:#475569;">Card Type</label>
                                <select id="dcType" class="txn-select" style="font-size:12px;padding:7px 10px;">
                                    <option value="Visa">Visa</option>
                                    <option value="Mastercard">Mastercard</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>
                        <div class="txn-field">
                            <label style="font-size:10px;font-weight:600;color:#475569;">Reference No.</label>
                            <input type="text" id="dcRefNumber" class="txn-input" style="font-size:12px;padding:7px 10px;" placeholder="Ref #">
                        </div>
                    </div>

                    <!-- E-Wallet fields -->
                    <div id="ewalletFields" style="display:none;margin-bottom:8px;">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
                            <div class="txn-field">
                                <label style="font-size:10px;font-weight:600;color:#475569;">Amount Paid</label>
                                <input type="number" id="ewAmount" class="txn-input" style="font-size:12px;padding:7px 10px;"
                                       step="0.01" min="0" placeholder="₱0.00"
                                       oninput="onPaymentAmountInput('ewAmount')">
                            </div>
                            <div class="txn-field">
                                <label style="font-size:10px;font-weight:600;color:#475569;">Provider</label>
                                <select id="ewProvider" class="txn-select" style="font-size:12px;padding:7px 10px;">
                                    <option value="GCash">GCash</option>
                                    <option value="Maya">Maya</option>
                                    <option value="GrabPay">GrabPay</option>
                                    <option value="ShopeePay">ShopeePay</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>
                        <div class="txn-field">
                            <label style="font-size:10px;font-weight:600;color:#475569;">Reference No.</label>
                            <input type="text" id="ewRefNumber" class="txn-input" style="font-size:12px;padding:7px 10px;" placeholder="Ref #">
                        </div>
                    </div>

                    <!-- Petron Fleet Card fields -->
                    <div id="fleetCardFields" style="display:none;margin-bottom:8px;">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
                            <div class="txn-field">
                                <label style="font-size:10px;font-weight:600;color:#475569;">Amount Paid</label>
                                <input type="number" id="fcAmount" class="txn-input" style="font-size:12px;padding:7px 10px;"
                                       step="0.01" min="0" placeholder="₱0.00"
                                       oninput="onPaymentAmountInput('fcAmount')">
                            </div>
                            <div class="txn-field">
                                <label style="font-size:10px;font-weight:600;color:#475569;">Fleet Card Number</label>
                                <input type="text" id="fcNumber" class="txn-input" style="font-size:12px;padding:7px 10px;" placeholder="Card #">
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                            <div class="txn-field">
                                <label style="font-size:10px;font-weight:600;color:#475569;">Company Name</label>
                                <input type="text" id="fcCompanyName" class="txn-input" style="font-size:12px;padding:7px 10px;" placeholder="Company Name">
                            </div>
                            <div class="txn-field">
                                <label style="font-size:10px;font-weight:600;color:#475569;">Auth No. (Opt)</label>
                                <input type="text" id="fcAuthNumber" class="txn-input" style="font-size:12px;padding:7px 10px;" placeholder="Auth #">
                            </div>
                        </div>
                    </div>

                    <!-- Petron E-Fuel Card fields -->
                    <div id="efuelCardFields" style="display:none;margin-bottom:8px;">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
                            <div class="txn-field">
                                <label style="font-size:10px;font-weight:600;color:#475569;">Amount Paid</label>
                                <input type="number" id="efAmount" class="txn-input" style="font-size:12px;padding:7px 10px;"
                                       step="0.01" min="0" placeholder="₱0.00"
                                       oninput="onPaymentAmountInput('efAmount')">
                            </div>
                            <div class="txn-field">
                                <label style="font-size:10px;font-weight:600;color:#475569;">E-Fuel Card Number</label>
                                <input type="text" id="efCardNumber" class="txn-input" style="font-size:12px;padding:7px 10px;" placeholder="Card #">
                            </div>
                        </div>
                        <div class="txn-field">
                            <label style="font-size:10px;font-weight:600;color:#475569;">Reference No.</label>
                            <input type="text" id="efRefNumber" class="txn-input" style="font-size:12px;padding:7px 10px;" placeholder="Ref #">
                        </div>
                    </div>

                    <!-- Credit Account fields -->
                    <div id="creditAccountFields" style="display:none;margin-bottom:8px;">
                        <div class="txn-field" style="margin-bottom:8px;">
                            <label style="font-size:10px;font-weight:600;color:#475569;">Credit Account <span style="color:#dc2626;">*</span></label>
                            <select id="creditCustomer" class="txn-select" style="font-size:12px;padding:7px 10px;" onchange="onCreditCustomerChange()">
                                <option value="">Select account…</option>
                                <?php foreach ($customers as $c): ?>
                                <option value="<?= (int)$c['id'] ?>"
                                        data-name="<?= htmlspecialchars($c['name']) ?>"
                                        data-limit="<?= (float)$c['credit_limit'] ?>"
                                        data-balance="<?= (float)$c['balance'] ?>">
                                    <?= htmlspecialchars($c['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
                            <div class="txn-field">
                                <label style="font-size:10px;font-weight:600;color:#475569;">Company Name</label>
                                <input type="text" id="creditCompanyName" class="txn-input" style="font-size:12px;padding:7px 10px;" placeholder="Company Name">
                            </div>
                            <div class="txn-field">
                                <label style="font-size:10px;font-weight:600;color:#475569;">Account Number</label>
                                <input type="text" id="creditAccountNumber" class="txn-input" style="font-size:12px;padding:7px 10px;" placeholder="Account #">
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                            <div class="txn-field">
                                <label style="font-size:10px;font-weight:600;color:#475569;">PO Number (Opt)</label>
                                <input type="text" id="creditPoNumber" class="txn-input" style="font-size:12px;padding:7px 10px;" placeholder="PO #">
                            </div>
                            <div class="txn-field">
                                <label style="font-size:10px;font-weight:600;color:#475569;">Due Date</label>
                                <input type="date" id="creditDueDate" class="txn-input" style="font-size:12px;padding:7px 10px;">
                            </div>
                        </div>
                    </div>

                    <!-- Shared Balance Due display for non-cash/non-credit-account payments -->
                    <div id="generalBalanceWrap" style="display:none;margin-top:6px;margin-bottom:4px;">
                        <div class="txn-field">
                            <label style="font-size:10px;font-weight:600;color:#475569;">Balance Due</label>
                            <input type="number" id="generalBalanceDue" class="txn-input" style="font-size:12px;padding:7px 10px;background:#fff7ed;" readonly placeholder="—">
                        </div>
                    </div>

                    <!-- ── Live Payment Status Badge ── -->
                    <div id="payStatusBadgeWrap" style="display:none;margin-top:10px;padding:8px 12px;border-radius:8px;border:1.5px solid #e2e8f0;background:#f8fafc;align-items:center;gap:8px;">
                        <i id="payStatusIcon" class="fas fa-circle" style="font-size:10px;flex-shrink:0;"></i>
                        <div>
                            <div id="payStatusLabel" style="font-size:12px;font-weight:700;"></div>
                            <div id="payStatusSub" style="font-size:10px;margin-top:1px;color:#64748b;"></div>
                        </div>
                    </div>

                    <!-- Loyalty Program Section -->
                    <div class="txn-field" style="margin-top:10px; margin-bottom:8px; border-top:1.5px solid #e2e8f0; padding-top:10px;">
                        <label style="font-size:10px;font-weight:700;color:#002F70;text-transform:uppercase;letter-spacing:0.5px;">Loyalty Program</label>
                        <select id="loyaltyProgram" class="txn-select" style="font-size:12px;padding:7px 10px;margin-top:4px;" onchange="onLoyaltyChange()">
                            <option value="No Loyalty">No Loyalty</option>
                            <option value="Petron Rewards Card">Petron Rewards Card</option>
                        </select>
                    </div>

                    <!-- Loyalty Fields (hidden by default, shown when Petron Rewards Card is selected) -->
                    <div id="loyaltyFields" style="display:none;margin-bottom:8px;">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
                            <div class="txn-field">
                                <label style="font-size:10px;font-weight:600;color:#475569;">Loyalty Card No.</label>
                                <input type="text" id="loyaltyCardNo" class="txn-input" style="font-size:12px;padding:7px 10px;background:#f8fafc;font-weight:600;color:#1e293b;" readonly placeholder="Card #">
                            </div>
                            <div class="txn-field">
                                <label style="font-size:10px;font-weight:600;color:#475569;">Current Points Balance</label>
                                <input type="number" id="loyaltyPointsBalance" class="txn-input" style="font-size:12px;padding:7px 10px;background:#f8fafc;font-weight:700;color:#002F70;" readonly value="0">
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
                            <div class="txn-field">
                                <label style="font-size:10px;font-weight:600;color:#475569;">Points Earned</label>
                                <input type="number" id="loyaltyPointsEarned" class="txn-input" style="font-size:12px;padding:7px 10px;background:#f0fdf4;font-weight:700;color:#16a34a;" readonly value="0">
                            </div>
                            <div class="txn-field">
                                <label style="font-size:10px;font-weight:600;color:#475569;">Redeem Points (Optional)</label>
                                <input type="number" id="loyaltyPointsRedeemed" class="txn-input" style="font-size:12px;padding:7px 10px;" min="0" value="0" oninput="calcLoyaltyPoints()">
                            </div>
                        </div>
                        <div class="txn-field">
                            <label style="font-size:10px;font-weight:600;color:#475569;">Points After Transaction</label>
                            <input type="number" id="loyaltyPointsAfter" class="txn-input" style="font-size:12px;padding:7px 10px;background:#eff6ff;font-weight:700;color:#1d4ed8;" readonly value="0">
                            <div id="loyaltyErrorMsg" style="font-size:10.5px;color:#dc2626;font-weight:600;margin-top:2px;display:none;"></div>
                        </div>
                    </div>

                </div><!-- /cart-panel-top -->

                <!-- ── Right column: Cart header + items + footer ── -->
                <div style="display:flex;flex-direction:column;min-height:320px;">

                <!-- ── Cart header ────────────────────────────────── -->
                <div class="cart-header">
                    <span style="font-size:12px;font-weight:700;color:#1e293b;display:flex;align-items:center;gap:6px;">
                        <i class="fas fa-shopping-basket" style="color:#28a745;"></i>Cart
                    </span>
                    <button type="button" class="txn-btn danger" onclick="clearCart()">
                        <i class="fas fa-trash"></i> Clear
                    </button>
                </div>

                <!-- ── Cart items (scrollable) ────────────────────── -->
                <div class="cart-body" id="cartBody">
                    <div class="cart-empty" id="cartEmpty">
                        <i class="fas fa-shopping-cart"></i>
                        Cart is empty.<br>Add service or items from the left.
                    </div>
                </div>

                <!-- ── Totals + Checkout (pinned bottom) ─────────── -->
                <div class="cart-footer">
                    <div class="cart-total-row">
                        <span>Subtotal</span>
                        <span class="calc-val" id="cartSubtotal">₱0.00</span>
                    </div>
                    <div class="cart-total-row">
                        <span>VAT (12%)</span>
                        <span class="calc-val" id="cartVat">₱0.00</span>
                    </div>
                    <div class="cart-total-row grand">
                        <span>Grand Total</span>
                        <span id="cartGrandTotal">₱0.00</span>
                    </div>
                    <button type="button" class="txn-btn primary full" onclick="submitMerchTxn()" id="checkoutBtn" disabled>
                        <i class="fas fa-receipt"></i> Process & Print Receipt
                    </button>
                    <p style="font-size:10px;color:#94a3b8;text-align:center;margin:5px 0 0;">
                        A Petron itemized receipt will be generated.
                    </p>
                </div><!-- /cart-footer -->

                </div><!-- /cart right column -->
                </div><!-- /cart inner two-col grid -->

            </div><!-- /cart-panel -->

        </div><!-- /cart-wrapper -->



        <!-- ══ ADD SERVICE TYPE MODAL ═══════════════════════════════════════ -->
        <div id="addServiceModal"
             style="display:none;position:fixed;inset:0;z-index:10000;
                    background:rgba(0,0,0,.45);align-items:center;justify-content:center;">
            <div style="background:#fff;border-radius:12px;padding:28px 28px 24px;
                        width:100%;max-width:440px;box-shadow:0 20px 60px rgba(0,0,0,.25);
                        position:relative;margin:16px;">
                <!-- Header -->
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:20px;">
                    <div style="width:36px;height:36px;background:#fffbeb;border-radius:8px;
                                display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-wrench" style="color:#b45309;font-size:15px;"></i>
                    </div>
                    <div>
                        <div style="font-size:14px;font-weight:700;color:#1e293b;">Request New Service Type</div>
                        <div style="font-size:11px;color:#64748b;">Submitted for manager approval</div>
                    </div>
                </div>

                <!-- Service Name -->
                <div style="margin-bottom:14px;">
                    <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:5px;">
                        Service Name <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="text" id="newServiceName" class="txn-input"
                           placeholder="e.g. Clutch Replacement, Radiator Flush…"
                           maxlength="100"
                           style="font-size:13px;"
                           autocomplete="off">
                    <div style="font-size:10px;color:#94a3b8;margin-top:4px;">
                        Be specific — use the standard service name (e.g. "Timing Belt Replacement")
                    </div>
                </div>

                <!-- Service Category -->
                <div style="margin-bottom:14px;">
                    <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:5px;">
                        Category <span style="color:#dc2626;">*</span>
                    </label>
                    <select id="newServiceCategory" class="txn-select" style="font-size:13px;width:100%;">
                        <option value="Lubrication">Lubrication</option>
                        <option value="PMS">PMS</option>
                        <option value="Engine">Engine</option>
                        <option value="Fuel System">Fuel System</option>
                        <option value="Cooling System">Cooling System</option>
                        <option value="Transmission">Transmission</option>
                        <option value="Brake">Brake</option>
                        <option value="Suspension">Suspension</option>
                        <option value="Steering">Steering</option>
                        <option value="Tire Services">Tire Services</option>
                        <option value="Battery Services">Battery Services</option>
                        <option value="Electrical">Electrical</option>
                        <option value="Air Conditioning">Air Conditioning</option>
                        <option value="Diagnostics">Diagnostics</option>
                        <option value="Inspection">Inspection</option>
                        <option value="Others" selected>Others</option>
                    </select>
                </div>

                <!-- Service Price -->
                <div style="margin-bottom:14px;">
                    <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:5px;">
                        Default Service Fee (₱) <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="number" id="newServicePrice" class="txn-input"
                           placeholder="0.00"
                           step="0.01"
                           min="0"
                           style="font-size:13px;"
                           required>
                </div>

                <!-- Estimated Duration -->
                <div style="margin-bottom:14px;">
                    <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:5px;">
                        Estimated Duration
                    </label>
                    <input type="text" id="newServiceDuration" class="txn-input"
                           placeholder="e.g., 30 minutes, 1-2 hours..."
                           maxlength="50"
                           style="font-size:13px;"
                           autocomplete="off">
                </div>

                <!-- Description -->
                <div style="margin-bottom:14px;">
                    <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:5px;">
                        Description
                    </label>
                    <textarea id="newServiceDescription" class="txn-input"
                              placeholder="Additional details about this service (optional)..."
                              rows="2"
                              maxlength="255"
                              style="font-size:13px;resize:vertical;"
                              autocomplete="off"></textarea>
                </div>

                <!-- Reason for Request -->
                <div style="margin-bottom:18px;">
                    <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:5px;">
                        Reason for Request <span style="color:#dc2626;">*</span>
                    </label>
                    <textarea id="newServiceReason" class="txn-input"
                              placeholder="Why do you need this service added? (e.g., 'Customer requested this service')"
                              rows="2"
                              maxlength="500"
                              style="font-size:13px;resize:vertical;"
                              autocomplete="off"></textarea>
                </div>

                <!-- Error -->
                <div id="addServiceError"
                     style="display:none;background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;
                            padding:9px 12px;margin-bottom:14px;font-size:12px;color:#991b1b;
                            align-items:center;gap:7px;">
                    <i class="fas fa-exclamation-circle"></i>
                    <span id="addServiceErrorText"></span>
                </div>

                <!-- Buttons -->
                <div style="display:flex;gap:10px;justify-content:flex-end;">
                    <button type="button" onclick="closeAddServiceModal()"
                            class="txn-btn secondary">
                        Cancel
                    </button>
                    <button type="button" id="addServiceSubmitBtn"
                            onclick="submitNewServiceType()"
                            class="txn-btn primary">
                        <i class="fas fa-paper-plane"></i> Submit for Approval
                    </button>
                </div>
            </div>
        </div>

        <!-- ══ ADD VEHICLE TYPE MODAL ══════════════════════════════════════ -->
        <div id="addVehicleModal"
             style="display:none;position:fixed;inset:0;z-index:10000;
                    background:rgba(0,0,0,.45);align-items:center;justify-content:center;">
            <div style="background:#fff;border-radius:12px;padding:28px 28px 24px;
                        width:100%;max-width:420px;box-shadow:0 20px 60px rgba(0,0,0,.25);
                        position:relative;margin:16px;">
                <!-- Header -->
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:20px;">
                    <div style="width:36px;height:36px;background:#eff6ff;border-radius:8px;
                                display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-car" style="color:#003d7a;font-size:15px;"></i>
                    </div>
                    <div>
                        <div style="font-size:14px;font-weight:700;color:#1e293b;">Request New Vehicle</div>
                        <div style="font-size:11px;color:#64748b;">Submitted for manager approval</div>
                    </div>
                    <button onclick="closeAddVehicleModal()"
                            style="margin-left:auto;background:none;border:none;cursor:pointer;
                                   color:#94a3b8;font-size:20px;line-height:1;padding:0;"
                            title="Close">×</button>
                </div>

                <!-- Brand -->
                <div style="margin-bottom:14px;">
                    <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:5px;">
                        Vehicle Brand <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="text" 
                           id="newVehicleBrand" 
                           class="txn-input" 
                           placeholder="e.g. Toyota, Honda, Mitsubishi..."
                           style="font-size:13px;"
                           autocomplete="off">
                </div>

                <!-- Model -->
                <div style="margin-bottom:14px;">
                    <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:5px;">
                        Vehicle Model <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="text" 
                           id="newVehicleModel" 
                           class="txn-input" 
                           placeholder="e.g. Vios, Civic, Montero..."
                           style="font-size:13px;"
                           autocomplete="off">
                </div>

                <!-- Vehicle Type -->
                <div style="margin-bottom:14px;">
                    <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:5px;">
                        Vehicle Type <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="text" 
                           id="newVehicleType" 
                           class="txn-input" 
                           list="vehicleCategoryList"
                           placeholder="Type or select vehicle type..."
                           style="font-size:13px;"
                           autocomplete="off">
                    <datalist id="vehicleCategoryList">
                        <option value="Sedans / Hatchbacks">
                        <option value="SUVs">
                        <option value="Pickups">
                        <option value="Vans">
                        <option value="Light Trucks / Utility">
                        <option value="Motorcycles">
                        <option value="Tricycles / E-bikes">
                        <option value="Others">
                    </datalist>
                </div>

                <!-- Fuel Type -->
                <div style="margin-bottom:14px;">
                    <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:5px;">
                        Fuel Type <span style="color:#dc2626;">*</span>
                    </label>
                    <select id="newVehicleFuelType" class="txn-select" style="font-size:13px;width:100%;">
                        <option value="Gasoline" selected>Gasoline</option>
                        <option value="Diesel">Diesel</option>
                        <option value="LPG">LPG</option>
                        <option value="Hybrid">Hybrid</option>
                        <option value="Electric">Electric</option>
                    </select>
                </div>

                <!-- Remarks -->
                <div style="margin-bottom:18px;">
                    <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:5px;">
                        Remarks / Reason for Request
                    </label>
                    <textarea id="newVehicleRemarks" class="txn-input"
                              placeholder="Why do you need this vehicle type added? (e.g., 'Customer owns this model')"
                              rows="2"
                              maxlength="500"
                              style="font-size:13px;resize:vertical;"
                              autocomplete="off"></textarea>
                </div>

                <!-- Error message -->
                <div id="addVehicleError"
                     style="display:none;background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;
                            padding:9px 12px;margin-bottom:14px;font-size:12px;color:#991b1b;
                            display:none;align-items:center;gap:7px;">
                    <i class="fas fa-exclamation-circle"></i>
                    <span id="addVehicleErrorText"></span>
                </div>

                <!-- Buttons -->
                <div style="display:flex;gap:10px;justify-content:flex-end;">
                    <button type="button" onclick="closeAddVehicleModal()"
                            class="txn-btn secondary">
                        Cancel
                    </button>
                    <button type="button" id="addVehicleSubmitBtn"
                            onclick="submitNewVehicleType()"
                            class="txn-btn primary">
                        <i class="fas fa-paper-plane"></i> Submit for Approval
                    </button>
                </div>
            </div>
        </div>

        <!-- ══ ADD PRODUCT MODAL ════════════════════════════════════════════ -->
        <div id="addProductModal"
             style="display:none;position:fixed;inset:0;z-index:10000;
                    background:rgba(0,0,0,.45);align-items:center;justify-content:center;">
            <div style="background:#fff;border-radius:16px;padding:26px;
                        max-width:460px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);">
                
                <!-- Header -->
                <div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:20px;">
                    <div style="flex-shrink:0;width:42px;height:42px;border-radius:10px;
                                background:linear-gradient(135deg,#f0fdf4,#dcfce7);
                                display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-box-open" style="font-size:18px;color:#166534;"></i>
                    </div>
                    <div>
                        <div style="font-size:14px;font-weight:700;color:#1e293b;">Request New Product</div>
                        <div style="font-size:11px;color:#64748b;">Submitted for manager approval</div>
                    </div>
                    <button type="button" onclick="closeAddProductModal()"
                            style="margin-left:auto;background:none;border:none;font-size:20px;
                                   color:#94a3b8;cursor:pointer;padding:0;line-height:1;">
                        ×
                    </button>
                </div>

                <!-- Category -->
                <div style="margin-bottom:14px;">
                    <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:5px;">
                        Category <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="text" 
                           id="newProductCategory" 
                           class="txn-input" 
                           list="productCategoryList"
                           placeholder="Type or select category..."
                           style="font-size:13px;"
                           autocomplete="off">
                    <datalist id="productCategoryList">
                        <option value="Beverages">
                        <option value="Snacks">
                        <option value="Food Items">
                        <option value="Automotive Supplies">
                        <option value="Lubricants">
                        <option value="Accessories">
                        <option value="Tobacco Products">
                        <option value="Personal Care">
                        <option value="Other">
                    </datalist>
                </div>

                <!-- Product Name -->
                <div style="margin-bottom:14px;">
                    <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:5px;">
                        Product Name <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="text" id="newProductName" class="txn-input"
                           placeholder="e.g. Coca-Cola 500ml, Marlboro Red…"
                           maxlength="150"
                           style="font-size:13px;"
                           autocomplete="off">
                    <div style="font-size:10px;color:#94a3b8;margin-top:4px;">
                        Be specific — include brand and size if applicable
                    </div>
                </div>

                <!-- SKU (optional) -->
                <div style="margin-bottom:14px;">
                    <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:5px;">
                        SKU / Product Code (optional)
                    </label>
                    <input type="text" id="newProductSKU" class="txn-input"
                           placeholder="e.g. COKE-500ML"
                           maxlength="50"
                           style="font-size:13px;text-transform:uppercase;"
                           autocomplete="off">
                </div>

                <!-- Unit -->
                <div style="margin-bottom:14px;">
                    <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:5px;">
                        Unit <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="text" id="newProductUnit" class="txn-input"
                           list="productUnitList"
                           placeholder="e.g. pcs, bottle, pack, box..."
                           maxlength="30"
                           style="font-size:13px;"
                           autocomplete="off">
                    <datalist id="productUnitList">
                        <option value="pcs">
                        <option value="bottle">
                        <option value="pack">
                        <option value="box">
                        <option value="can">
                        <option value="bag">
                        <option value="liter">
                        <option value="kg">
                        <option value="pair">
                        <option value="set">
                    </datalist>
                </div>

                <!-- Unit Price -->
                <div style="margin-bottom:14px;">
                    <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:5px;">
                        Unit Price (₱) <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="number" id="newProductPrice" class="txn-input"
                           placeholder="0.00"
                           min="0"
                           step="0.01"
                           style="font-size:13px;"
                           autocomplete="off">
                </div>

                <!-- Reason for Request -->
                <div style="margin-bottom:18px;">
                    <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:5px;">
                        Reason for Request <span style="color:#dc2626;">*</span>
                    </label>
                    <textarea id="newProductReason" class="txn-input"
                              placeholder="Why do you need this product added? (e.g., 'Customer is looking for this item')"
                              rows="2"
                              maxlength="500"
                              style="font-size:13px;resize:vertical;"
                              autocomplete="off"></textarea>
                </div>

                <!-- Error message -->
                <div id="addProductError"
                     style="display:none;background:#fee2e2;border:1px solid #fca5a5;
                            border-radius:8px;padding:10px 12px;margin-bottom:14px;
                            font-size:12px;color:#991b1b;display:flex;align-items:flex-start;gap:8px;">
                    <i class="fas fa-exclamation-circle"></i>
                    <span id="addProductErrorText"></span>
                </div>

                <!-- Buttons -->
                <div style="display:flex;gap:10px;justify-content:flex-end;">
                    <button type="button" onclick="closeAddProductModal()"
                            class="txn-btn secondary">
                        Cancel
                    </button>
                    <button type="button" id="addProductSubmitBtn"
                            onclick="submitNewProduct()"
                            class="txn-btn primary">
                        <i class="fas fa-paper-plane"></i> Submit for Approval
                    </button>
                </div>
            </div>
        </div>

        <!-- ══ MERCHANDISE TRANSACTION JAVASCRIPT ══════════════════════════ -->
        <script>
        // ── State ────────────────────────────────────────────────────────────
        let cart = [];
        window.cart = cart;
        window.getPetronCart = function() { return cart; };
        window.setPetronCart = function(c) {
            if (Array.isArray(c)) {
                cart = c;
                window.cart = c;
                if (typeof renderCart === 'function') renderCart();
                if (typeof updateCheckoutBtn === 'function') updateCheckoutBtn();
                if (typeof onPaymentChange === 'function') onPaymentChange();
            }
        };
        let selectedProduct = null;
        const pointsPerAmount = <?= (float)$points_per_amount ?>;
        const redemptionValue  = <?= (float)$redemption_value ?>;
        const selectedCustomerIds = { jo: null, merch: null };

        function onLoyaltyChange() {
            const program = document.getElementById('loyaltyProgram')?.value || 'No Loyalty';
            const fieldsWrap = document.getElementById('loyaltyFields');
            const cardNoInput = document.getElementById('loyaltyCardNo');
            const balanceInput = document.getElementById('loyaltyPointsBalance');

            if (fieldsWrap) {
                if (program !== 'No Loyalty') {
                    fieldsWrap.style.display = 'block';
                    updateLoyaltyPointsEarned(getGrandTotal());
                } else {
                    fieldsWrap.style.display = 'none';
                    if (cardNoInput) cardNoInput.value = '';
                    if (balanceInput) balanceInput.value = '0';
                    const pointsEarned = document.getElementById('loyaltyPointsEarned');
                    const pointsRedeemed = document.getElementById('loyaltyPointsRedeemed');
                    const pointsAfter = document.getElementById('loyaltyPointsAfter');
                    if (pointsEarned) pointsEarned.value = '0';
                    if (pointsRedeemed) pointsRedeemed.value = '0';
                    if (pointsAfter) pointsAfter.value = '0';
                    calcLoyaltyPoints();
                }
            }
        }

        function updateLoyaltyPointsEarned(grand) {
            const program = document.getElementById('loyaltyProgram')?.value || 'No Loyalty';
            const pointsEarnedInput = document.getElementById('loyaltyPointsEarned');
            if (program !== 'No Loyalty' && pointsEarnedInput) {
                // Points Earned = Math.floor(eligibleTotal / pointsPerAmount)
                const pts = Math.floor((grand || 0) / pointsPerAmount);
                pointsEarnedInput.value = Math.max(0, pts);
            } else if (pointsEarnedInput) {
                pointsEarnedInput.value = 0;
            }
            calcLoyaltyPoints();
        }

        function calcLoyaltyPoints() {
            const program = document.getElementById('loyaltyProgram')?.value || 'No Loyalty';
            const balanceInput = document.getElementById('loyaltyPointsBalance');
            const earnedInput = document.getElementById('loyaltyPointsEarned');
            const redeemedInput = document.getElementById('loyaltyPointsRedeemed');
            const afterInput = document.getElementById('loyaltyPointsAfter');
            const errorEl = document.getElementById('loyaltyErrorMsg');

            if (program === 'No Loyalty') {
                if (afterInput) afterInput.value = 0;
                if (errorEl) errorEl.style.display = 'none';
                return;
            }

            const currentBalance = parseInt(balanceInput?.value || '0', 10) || 0;
            const pointsEarned = parseInt(earnedInput?.value || '0', 10) || 0;
            let pointsRedeemed = parseInt(redeemedInput?.value || '0', 10) || 0;
            if (pointsRedeemed < 0) {
                pointsRedeemed = 0;
                if (redeemedInput) redeemedInput.value = 0;
            }

            let isValid = true;
            if (pointsRedeemed > currentBalance) {
                isValid = false;
                if (errorEl) {
                    errorEl.textContent = `Insufficient points. Available points: ${currentBalance}.`;
                    errorEl.style.display = 'block';
                }
            } else {
                if (errorEl) errorEl.style.display = 'none';
            }

            // Disable / Enable checkout / submit button
            const submitBtn = document.querySelector('.cart-pay-btn') || document.getElementById('submitTxnBtn');
            if (submitBtn) {
                if (!isValid) {
                    submitBtn.disabled = true;
                    submitBtn.style.opacity = '0.5';
                    submitBtn.style.cursor = 'not-allowed';
                } else {
                    submitBtn.disabled = false;
                    submitBtn.style.opacity = '1';
                    submitBtn.style.cursor = 'pointer';
                }
            }

            // Formula: New Points Balance = Previous Balance + Points Earned - Points Redeemed
            const pointsAfter = currentBalance + pointsEarned - pointsRedeemed;
            if (afterInput) afterInput.value = Math.max(0, pointsAfter);
        }

        document.addEventListener('DOMContentLoaded', () => {
            document.getElementById('loyaltyCardNo')?.addEventListener('input', function() {
                const cardNo = this.value.trim().toLowerCase();
                const balanceInput = document.getElementById('loyaltyPointsBalance');
                if (!balanceInput) return;
                if (!cardNo) {
                    balanceInput.value = '0';
                    return;
                }
                const found = customerData.find(c => 
                    (c.customer_id && c.customer_id.toLowerCase() === cardNo) || 
                    (c.id_number && c.id_number.toLowerCase() === cardNo)
                );
                if (found) {
                    balanceInput.value = found.points || 0;
                } else {
                    balanceInput.value = '0';
                }
            });
        });

        // ── Product dropdown ──────────────────────────────────────────────────
        function openProductDropdown() {
            const list = document.getElementById('productDropdownList');
            if (list) list.style.display = 'block';
        }

        function closeProductDropdown() {
            const list = document.getElementById('productDropdownList');
            if (list) list.style.display = 'none';
        }

        function toggleProductDropdown() {
            const list = document.getElementById('productDropdownList');
            if (!list) return;
            list.style.display = list.style.display === 'none' ? 'block' : 'none';
        }

        function filterProductDropdown() {
            const q = (document.getElementById('productSearch')?.value || '').toLowerCase();
            const list = document.getElementById('productDropdownList');
            if (!list) return;
            list.style.display = 'block';
            list.querySelectorAll('.prod-option').forEach(opt => {
                const search = (opt.dataset.search || '').toLowerCase();
                opt.style.display = (!q || search.includes(q)) ? '' : 'none';
            });
            list.querySelectorAll('.prod-group-header').forEach(hdr => {
                const group = hdr.dataset.group || '';
                const hasVisible = [...list.querySelectorAll(`.prod-option[data-cat="${group}"]`)]
                    .some(o => o.style.display !== 'none');
                hdr.style.display = hasVisible ? '' : 'none';
            });
        }

        // ── Called when a product checkbox is toggled — auto-adds/removes cart item ──
        function onProductCheckboxChange(cb) {
            const pid   = String(cb.dataset.id);
            const name  = cb.dataset.name;
            const price = parseFloat(cb.dataset.price) || 0;
            const stock = parseInt(cb.dataset.stock) || 0;
            const unit  = cb.dataset.unit || 'Piece (pc)';
            const cat   = cb.dataset.cat || '';
            const size  = cb.dataset.size || '';

            if (cb.checked) {
                // Guard stock
                if (stock <= 0) {
                    showTxnAlert('This product is out of stock.', 'warning');
                    cb.checked = false;
                    return;
                }
                const existing = cart.find(i => i.item_type === 'merchandise' && String(i.product_id) === pid);
                if (!existing) {
                    cart.push({
                        item_type:    'merchandise',
                        product_id:   pid,
                        product_name: name,
                        category:     cat,
                        size_variant: size,
                        quantity:     1,
                        unit_price:   price,
                        unit:         unit,
                    });
                }
            } else {
                // Remove from cart
                cart = cart.filter(i => !(i.item_type === 'merchandise' && String(i.product_id) === pid));
            }

            // Also fill in the form fields for reference
            selectedProduct = cb.checked ? {
                id: pid, name, sku: cb.dataset.sku, cat, size, price, stock, unit
            } : null;
            if (cb.checked) {
                const search = document.getElementById('productSearch');
                if (search) search.value = name + (size ? ' · ' + size : '');
                const skuEl   = document.getElementById('itemSku');
                const catEl   = document.getElementById('itemCategory');
                const priceEl = document.getElementById('itemUnitPrice');
                const stockEl = document.getElementById('itemStock');
                if (skuEl)   skuEl.value   = cb.dataset.sku || '—';
                if (catEl)   catEl.value   = cat || '—';
                if (priceEl) priceEl.value = price.toFixed(2);
                if (stockEl) {
                    stockEl.value = stock > 0 ? stock + ' available' : 'Out of stock';
                    stockEl.style.color = stock > 0 ? '#065f46' : '#dc2626';
                }
            }

            renderCart();
            updateCheckoutBtn();
        }

        // ── Sync checkbox states to match cart (called after cart changes) ───
        function syncProductCheckboxes() {
            document.querySelectorAll('.merch-prod-checkbox').forEach(cb => {
                const pid = String(cb.dataset.id);
                const inCart = cart.some(i => i.item_type === 'merchandise' && String(i.product_id) === pid);
                cb.checked = inCart;
            });
        }

        function selectProduct(el) {
            // When clicking the label area (not the checkbox itself), toggle the checkbox
            const cb = el.querySelector ? el.querySelector('.merch-prod-checkbox') : null;
            if (cb && !cb.disabled) {
                cb.checked = !cb.checked;
                onProductCheckboxChange(cb);
                return;
            }
            // Fallback: old behavior for direct element calls
            selectedProduct = {
                id:    el.dataset.id,
                name:  el.dataset.name,
                sku:   el.dataset.sku,
                cat:   el.dataset.cat,
                size:  el.dataset.size,
                price: parseFloat(el.dataset.price) || 0,
                stock: parseInt(el.dataset.stock) || 0,
                unit:  el.dataset.unit || 'Piece (pc)',
            };
            const search = document.getElementById('productSearch');
            if (search) search.value = selectedProduct.name + (selectedProduct.size ? ' · ' + selectedProduct.size : '');
            const sku   = document.getElementById('itemSku');
            const cat   = document.getElementById('itemCategory');
            const price = document.getElementById('itemUnitPrice');
            const stock = document.getElementById('itemStock');
            if (sku)   sku.value   = selectedProduct.sku || '—';
            if (cat)   cat.value   = selectedProduct.cat || '—';
            if (price) price.value = selectedProduct.price.toFixed(2);
            let rawUnit = (selectedProduct.unit || 'pc').toLowerCase();
            let uomLabel = 'pcs';
            if (rawUnit.includes('bottle')) uomLabel = 'Bottles';
            else if (rawUnit.includes('liter') || rawUnit.includes('litre')) uomLabel = 'Liters';
            else if (rawUnit.includes('can')) uomLabel = 'Cans';
            else if (rawUnit.includes('box')) uomLabel = 'Boxes';
            else if (rawUnit.includes('set')) uomLabel = 'Sets';
            else if (rawUnit.includes('pack')) uomLabel = 'Packs';
            else if (rawUnit.includes('pair')) uomLabel = 'Pairs';
            else if (rawUnit.includes('pc') || rawUnit.includes('piece')) uomLabel = 'pcs';
            else uomLabel = selectedProduct.unit || 'pcs';
            if (stock) {
                if (selectedProduct.stock > 0) {
                    stock.value = selectedProduct.stock + ' ' + uomLabel + ' available';
                    stock.style.color = '#065f46';
                } else {
                    stock.value = 'Out of stock (0 ' + uomLabel + ')';
                    stock.style.color = '#dc2626';
                }
            }
            closeProductDropdown();
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            const wrap = document.getElementById('productDropdownWrap');
            if (wrap && !wrap.contains(e.target)) closeProductDropdown();
        });

        // ── Mechanic busy-status check ────────────────────────────────────────
        // Fires when the Assigned Mechanic dropdown changes.
        // Calls the API and shows a warning banner if the mechanic has ongoing jobs.
        // ── Mechanic Typeahead Dropdown ──────────────────────────────────────
        function filterMechanicDropdown(query) {
            const dd    = document.getElementById('joMechanicDropdown');
            const items = dd ? dd.querySelectorAll('.jo-mechanic-item') : [];
            const q     = query.toLowerCase().trim();
            let anyVisible = false;
            items.forEach(item => {
                const name = (item.dataset.name || '').toLowerCase();
                const spec = (item.dataset.spec || '').toLowerCase();
                const show = !q || name.includes(q) || spec.includes(q);
                item.style.display = show ? '' : 'none';
                if (show) anyVisible = true;
            });
            if (dd) dd.style.display = 'block';
            // Clear hidden fields if text was manually changed (no selection yet)
            const hiddenId = document.getElementById('joMechanicId');
            if (hiddenId && hiddenId.value) {
                // User is typing again — deselect previous choice
                hiddenId.value = '';
                document.getElementById('joMechanicName').value = '';
            }
        }

        function showMechanicDropdown() {
            const dd    = document.getElementById('joMechanicDropdown');
            const items = dd ? dd.querySelectorAll('.jo-mechanic-item') : [];
            items.forEach(i => i.style.display = '');   // show all
            if (dd) dd.style.display = 'block';
        }

        function hideMechanicDropdown() {
            const dd = document.getElementById('joMechanicDropdown');
            if (dd) dd.style.display = 'none';
        }

        function selectMechanic(el) {
            const id   = el.dataset.id   || '';
            const name = el.dataset.name || '';
            document.getElementById('joMechanic').value     = name;
            document.getElementById('joMechanicId').value   = id;
            document.getElementById('joMechanicName').value = name;
            hideMechanicDropdown();
            // Trigger busy-check
            onMechanicChange();
        }

        // ── Mechanic Busy-Check ──────────────────────────────────────────────
        async function onMechanicChange() {
            const warnBox = document.getElementById('joMechanicBusyWarn');
            const listEl  = document.getElementById('joMechanicBusyList');
            if (!warnBox || !listEl) return;

            // Now reads from the hidden ID field (joMechanic is a text input)
            const mechId = (document.getElementById('joMechanicId')?.value || '').trim();
            if (!mechId) {
                warnBox.style.display = 'none';
                listEl.innerHTML = '';
                return;
            }

            try {
                const res  = await fetch(`../backend/api/get_mechanic_status.php?mechanic_id=${encodeURIComponent(mechId)}`, {
                    credentials: 'same-origin',
                });
                const data = await res.json();

                if (data.busy && data.jobs && data.jobs.length > 0) {
                    const statusColors = {
                        'In Progress':        '#1e40af',
                        'Pending':            '#92400e',
                        'Pending Validation': '#92400e',
                        'Approved':           '#065f46',
                        'Validated':          '#065f46',
                    };
                    listEl.innerHTML = data.jobs.map(j => {
                        const color  = statusColors[j.status] || '#475569';
                        const plate  = j.vehicle_plate ? ` — <strong>${j.vehicle_plate}</strong>` : '';
                        const svc    = j.service_label  ? ` (${j.service_label})`                 : '';
                        const ref    = j.jo_ref         ? `<span style="font-weight:700;">${j.jo_ref}</span>` : '';
                        return `<div style="display:flex;align-items:center;gap:6px;padding:4px 8px;
                                            background:#fff;border:1px solid #fde68a;border-radius:6px;font-size:11px;">
                                    <span style="width:8px;height:8px;border-radius:50%;background:${color};flex-shrink:0;"></span>
                                    ${ref}${svc}${plate}
                                    <span style="margin-left:auto;color:${color};font-weight:700;">${j.status}</span>
                                </div>`;
                    }).join('');
                    warnBox.style.display = 'block';
                } else {
                    warnBox.style.display = 'none';
                    listEl.innerHTML = '';
                }
            } catch (err) {
                // Silently ignore network errors — don't block the workflow
                warnBox.style.display = 'none';
            }
        }

        // Helper: get category for a service type object.
        // Prefers the DB-sourced `category` field (now always present in API response).
        // Falls back to name/key inference for typed custom values not yet in DB.
        function getServiceCategory(svc) {
            if (!svc) return 'Others';
            // Primary: use DB-stored category if present
            if (svc.category) return svc.category;
            // Fallback inference for un-saved / custom names
            const key  = svc.key  || '';
            const name = (svc.name || '').toLowerCase();
            if (name.includes('oil') || name.includes('lube') || name.includes('grease') || name.includes('flush'))  return 'Lubrication';
            if (name.includes('pms') || name.includes('preventive') || name.includes('km'))                            return 'PMS';
            if (name.includes('spark') || name.includes('glow') || name.includes('throttle') || name.includes('carbon') || name.includes('timing belt') || name.includes('serpentine') || name.includes('valve cover') || name.includes('compression') || name.includes('intake') || name.includes('injector clean')) return 'Engine';
            if (name.includes('fuel pump') || name.includes('fuel tank') || name.includes('fuel line') || name.includes('fuel inject') || name.includes('diesel injector') || name.includes('fuel pressure') || name.includes('fuel rail')) return 'Fuel System';
            if (name.includes('radiator') || name.includes('coolant') || name.includes('thermostat') || name.includes('water pump') || name.includes('cooling'))                                                                              return 'Cooling System';
            if (name.includes('atf') || name.includes('cvt') || name.includes('transmission') || name.includes('clutch') || name.includes('differential') || name.includes('transfer case') || name.includes('gear oil'))                    return 'Transmission';
            if (name.includes('brake'))                                                                                 return 'Brake';
            if (name.includes('shock') || name.includes('coil spring') || name.includes('ball joint') || name.includes('stabilizer') || name.includes('control arm') || name.includes('rack end') || name.includes('tie rod'))             return 'Suspension';
            if (name.includes('steering') || name.includes('wheel bearing'))                                           return 'Steering';
            if (name.includes('tire') || name.includes('wheel align') || name.includes('wheel balanc') || name.includes('vulcan') || name.includes('mounting'))                                                                              return 'Tire Services';
            if (name.includes('battery') || name.includes('alternator') || name.includes('starter motor'))            return 'Battery Services';
            if (name.includes('headlight') || name.includes('taillight') || name.includes('bulb') || name.includes('fuse') || name.includes('wiring'))                                                                                       return 'Electrical';
            if (name.includes('aircon') || name.includes('air conditioning') || name.includes('a/c') || name.includes('refrigerant')) return 'Air Conditioning';
            if (name.includes('ecu') || name.includes('obd') || name.includes('diagnostic') || name.includes('scan')) return 'Diagnostics';
            if (name.includes('inspection') || name.includes('safety check') || name.includes('lto'))                 return 'Inspection';
            return 'Others';
        }

        // ── JO Service type change ────────────────────────────────────────────
        // When a service type is selected:
        //   1. Auto-fill the service fee from cached data
        //   2. Show pricing notes
        //   3. Fetch suggested parts from DB and preview them
        // Helper: sync category dropdown selection case-insensitively
        function syncServiceCategory(cat) {
            const categorySelect = document.getElementById('joServiceCategory');
            if (!categorySelect || !cat) return;
            const target = String(cat).toLowerCase().trim();
            let found = false;
            for (let i = 0; i < categorySelect.options.length; i++) {
                const optVal = categorySelect.options[i].value.toLowerCase().trim();
                const optTxt = categorySelect.options[i].text.toLowerCase().trim();
                if (optVal === target || optTxt === target) {
                    categorySelect.selectedIndex = i;
                    found = true;
                    break;
                }
            }
            if (!found) {
                for (let i = 0; i < categorySelect.options.length; i++) {
                    if (categorySelect.options[i].value === 'Others') {
                        categorySelect.selectedIndex = i;
                        break;
                    }
                }
            }
        }

        // ── JO Service type change ────────────────────────────────────────────
        function onJoServiceTypeChange() {
            const input      = document.getElementById('joServiceType');
            const hidden     = document.getElementById('joServiceTypeValue');
            const notesWrap  = document.getElementById('joServicePriceNotes');
            const notesText  = document.getElementById('joServicePriceNotesText');
            const priceInput = document.getElementById('joServicePrice');
            const autoInfo   = document.getElementById('joServiceAutoInfo');
            const autoCategory = document.getElementById('joServiceAutoCategory');
            const autoPrice    = document.getElementById('joServiceAutoPrice');
            const autoDuration = document.getElementById('joServiceAutoDuration');
            if (!hidden) return;
            
            const val = hidden.value || (input ? input.value : '');

            const svc = (window.JO_SERVICE_TYPES || []).find(s => s.name.toLowerCase() === val.toLowerCase());
            if (svc) {
                if (hidden && hidden.value !== svc.name) hidden.value = svc.name;
                if (input && input.value !== svc.name) input.value = svc.name;
                
                if (priceInput) {
                    priceInput.value = svc.price > 0 ? svc.price.toFixed(2) : '';
                }
                if (notesWrap && notesText && svc.notes) {
                    notesText.textContent = svc.notes;
                    notesWrap.style.display = 'block';
                } else if (notesWrap) {
                    notesWrap.style.display = 'none';
                }
                
                // Auto-fetch category
                const computedCat = getServiceCategory(svc);
                syncServiceCategory(computedCat);

                // Populate the visible auto-info panel (Category only)
                if (autoInfo) {
                    if (autoCategory) autoCategory.textContent = computedCat || '—';
                    autoInfo.style.display = 'block';
                }

                // Pre-fill the visible Service Price editable field
                if (priceInput) {
                    priceInput.value = svc.price > 0 ? svc.price.toFixed(2) : '';
                }

                if (svc.key) fetchServiceParts(svc.key);
            } else {
                if (notesWrap) notesWrap.style.display = 'none';
                if (autoInfo) autoInfo.style.display = 'none';
                if (val) {
                    const computedCat = getServiceCategory({ name: val });
                    syncServiceCategory(computedCat);
                }
                clearSuggestedParts();
            }
        }

        // ── Filter service types dropdown ─────────────────────────────────────
        // ── Filter service types dropdown ─────────────────────────────────────
        function getSelectedServiceNames() {
            return cart
                .filter(i => i.item_type === 'service' && i.category !== 'Labor' && i.product_name !== 'Labor Charge')
                .map(i => i.product_name);
        }

        function syncServiceCheckboxes() {
            const selectedNames = getSelectedServiceNames();
            document.querySelectorAll('.jo-svc-checkbox').forEach(cb => {
                cb.checked = selectedNames.includes(cb.value);
            });
        }

        function onServiceCheckboxChange(cb) {
            const name = cb.value;
            const isChecked = cb.checked;
            const types = window.JO_SERVICE_TYPES || [];
            const svc = types.find(s => s.name === name);
            const price = (svc && parseFloat(svc.price) > 0) ? parseFloat(svc.price) : (parseFloat(cb.dataset.price) || 0);

            if (isChecked) {
                const existing = cart.find(i => i.item_type === 'service' && i.product_name === name);
                if (!existing) {
                    cart.push({
                        item_type:    'service',
                        product_name: name,
                        category:     svc ? getServiceCategory(svc) : (cb.dataset.category || 'Service Fee'),
                        size_variant: '',
                        product_id:   null,
                        quantity:     1,
                        unit_price:   price,
                    });
                }
            } else {
                cart = cart.filter(i => !(i.item_type === 'service' && i.product_name === name));
            }

            updateServiceSelectionState();
        }

        function updateServiceSelectionState() {
            const selectedNames = getSelectedServiceNames();
            const hidden = document.getElementById('joServiceTypeValue');
            if (hidden) hidden.value = selectedNames.join(', ');

            // Update tags display if present
            const tagsDiv = document.getElementById('joSelectedServicesTags');
            if (tagsDiv) {
                if (selectedNames.length > 0) {
                    tagsDiv.style.display = 'flex';
                    tagsDiv.innerHTML = selectedNames.map(name => {
                        return `<span style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:20px;padding:3px 10px;font-size:11px;color:#1e40af;font-weight:600;display:inline-flex;align-items:center;gap:5px;">
                                    ${escapeHtml(name)}
                                    <span onclick="removeServiceByName('${escapeHtml(name.replace(/'/g,"\\'"))}')" style="cursor:pointer;color:#64748b;font-size:13px;line-height:1;">&times;</span>
                                </span>`;
                    }).join('');
                } else {
                    tagsDiv.style.display = 'none';
                    tagsDiv.innerHTML = '';
                }
            }

            const types = window.JO_SERVICE_TYPES || [];
            let totalPrice = 0;

            selectedNames.forEach(name => {
                const item = cart.find(i => i.item_type === 'service' && i.product_name === name);
                if (item) totalPrice += (item.unit_price * item.quantity);

                const svc = types.find(s => s.name === name);
                if (svc && svc.key && selectedNames.length === 1) {
                    fetchServiceParts(svc.key);
                }
            });

            const priceInput = document.getElementById('joServicePrice');
            if (priceInput) priceInput.value = totalPrice > 0 ? totalPrice.toFixed(2) : '';

            // Sync category
            if (selectedNames.length > 0) {
                const firstSvc = types.find(s => s.name === selectedNames[0]);
                if (firstSvc) {
                    const cat = getServiceCategory(firstSvc);
                    syncServiceCategory(cat);
                    const autoCategory = document.getElementById('joServiceAutoCategory');
                    if (autoCategory) autoCategory.textContent = cat;
                }
            } else {
                syncServiceCategory('');
            }

            syncServiceCheckboxes();
            renderCart();
            updateCheckoutBtn();
        }

        function removeServiceByName(name) {
            cart = cart.filter(i => !(i.item_type === 'service' && i.product_name === name));
            updateServiceSelectionState();
        }

        function filterServiceTypes() {
            const input = document.getElementById('joServiceType');
            const list = document.getElementById('joServiceTypeList');
            const dropdown = document.getElementById('joServiceTypeDropdown');
            
            if (!input || !list) return;
            
            const filter = input.value.toLowerCase().trim();
            const types = window.JO_SERVICE_TYPES || [];
            const filtered = filter ? types.filter(t => t.name.toLowerCase().includes(filter)) : types;
            
            if (filtered.length === 0) {
                list.innerHTML = '<div style="padding:10px;color:#94a3b8;font-size:13px;text-align:center;">No services found</div>';
            } else {
                const selectedNames = getSelectedServiceNames();
                const groups = {};
                filtered.forEach(t => {
                    const cat = getServiceCategory(t) || 'Others';
                    if (!groups[cat]) groups[cat] = [];
                    groups[cat].push(t);
                });

                let html = '';
                Object.keys(groups).sort().forEach(cat => {
                    html += `<div style="padding:6px 12px 3px;font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;background:#f8fafc;border-bottom:1px solid #f1f5f9;">${escapeHtml(cat)}</div>`;
                    groups[cat].forEach(t => {
                        const isChecked = selectedNames.includes(t.name) ? 'checked' : '';
                        html += `
                            <label style="display:flex;align-items:center;gap:10px;padding:8px 12px;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:13px;color:#1e293b;transition:background 0.15s;"
                                   onmouseover="this.style.background='#f0f7ff'" onmouseout="this.style.background=''">
                                <input type="checkbox"
                                       class="jo-svc-checkbox"
                                       value="${escapeHtml(t.name)}"
                                       data-price="${parseFloat(t.price)||0}"
                                       data-category="${escapeHtml(cat)}"
                                       ${isChecked}
                                       onchange="onServiceCheckboxChange(this)"
                                       style="width:16px;height:16px;accent-color:#002F70;cursor:pointer;flex-shrink:0;">
                                <span style="flex:1;">${escapeHtml(t.name)}</span>
                            </label>`;
                    });
                });
                list.innerHTML = html;
            }
            
            if (dropdown) dropdown.style.display = 'block';
        }
        
        // ── Show service dropdown ─────────────────────────────────────────────
        function showServiceDropdown() {
            const dropdown = document.getElementById('joServiceTypeDropdown');
            const input = document.getElementById('joServiceType');
            if (!dropdown || !input) return;
            filterServiceTypes();
            dropdown.style.display = 'block';
        }
        
        // ── Hide service dropdown ─────────────────────────────────────────────
        function hideServiceDropdown() {
            const dropdown = document.getElementById('joServiceTypeDropdown');
            if (dropdown) dropdown.style.display = 'none';
        }

        // Close service dropdown when clicking outside the input or panel
        document.addEventListener('click', function(e) {
            const input    = document.getElementById('joServiceType');
            const dropdown = document.getElementById('joServiceTypeDropdown');
            if (input && dropdown &&
                !input.contains(e.target) &&
                !dropdown.contains(e.target)) {
                hideServiceDropdown();
            }
        });
        
        // ── Select service type ───────────────────────────────────────────────
        function selectServiceType(serviceName) {
            const cb = document.querySelector(`.jo-svc-checkbox[value="${CSS.escape(serviceName)}"]`);
            if (cb) {
                cb.checked = !cb.checked;
                onServiceCheckboxChange(cb);
            } else {
                const selectedNames = getSelectedServiceNames();
                if (selectedNames.includes(serviceName)) {
                    cart = cart.filter(i => !(i.item_type === 'service' && i.product_name === serviceName));
                } else {
                    const types = window.JO_SERVICE_TYPES || [];
                    const svc = types.find(s => s.name === serviceName);
                    const price = svc && parseFloat(svc.price) > 0 ? parseFloat(svc.price) : 0;
                    cart.push({
                        item_type:    'service',
                        product_name: serviceName,
                        category:     svc ? getServiceCategory(svc) : 'Service Fee',
                        size_variant: '',
                        product_id:   null,
                        quantity:     1,
                        unit_price:   price,
                    });
                }
                updateServiceSelectionState();
            }
        }
        
        // ── HTML escape helper ────────────────────────────────────────────────
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // ── Fetch suggested parts from DB ─────────────────────────────────────
        async function fetchServiceParts(serviceKey) {
            const partsWrap = document.getElementById('joSuggestedParts');
            const partsList = document.getElementById('joSuggestedPartsList');
            const indicator = document.getElementById('joPartsLoadingIndicator');

            if (!partsWrap || !partsList) return;

            // Show loading
            if (indicator) indicator.style.display = 'flex';
            partsWrap.style.display = 'none';

            try {
                const res  = await fetch(`../backend/api/get_service_parts.php?service_key=${encodeURIComponent(serviceKey)}`, {
                    credentials: 'same-origin'
                });
                const data = await res.json();

                if (indicator) indicator.style.display = 'none';

                if (!data.success || !data.parts || data.parts.length === 0) {
                    partsWrap.style.display = 'none';
                    // Cache empty parts list
                    window._joSuggestedParts = [];
                    return;
                }

                // Cache parts for use in applyJobOrderToCart
                window._joSuggestedParts = data.parts;

                // Render preview
                partsList.innerHTML = data.parts.map(p => {
                    const stockBadge = p.in_stock
                        ? `<span style="background:#f0fdf4;color:#166534;border:1px solid #86efac;padding:1px 6px;border-radius:10px;font-size:10px;font-weight:600;">In Stock (${p.stock_level})</span>`
                        : `<span style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;padding:1px 6px;border-radius:10px;font-size:10px;font-weight:600;">Out of Stock</span>`;
                    const reqBadge = p.is_required
                        ? `<span style="background:#fffbeb;color:#92400e;border:1px solid #fde68a;padding:1px 6px;border-radius:10px;font-size:10px;font-weight:600;">Required</span>`
                        : '';
                    const price = p.unit_price > 0 ? ` · ₱${fmtNum(p.unit_price)}` : '';
                    return `<div style="display:flex;align-items:center;gap:6px;padding:5px 0;border-bottom:1px solid #fde68a;flex-wrap:wrap;">
                        <i class="fas fa-box" style="color:#b45309;font-size:10px;flex-shrink:0;"></i>
                        <span style="font-weight:600;color:#1e293b;">${escHtml(p.product_name)}</span>
                        <span style="color:#64748b;">×${p.default_quantity}${price}</span>
                        ${reqBadge}
                        ${stockBadge}
                    </div>`;
                }).join('');

                partsWrap.style.display = 'block';

            } catch (err) {
                if (indicator) indicator.style.display = 'none';
                partsWrap.style.display = 'none';
                window._joSuggestedParts = [];
                console.warn('fetchServiceParts error:', err);
            }
        }

        function clearSuggestedParts() {
            window._joSuggestedParts = [];
            const partsWrap = document.getElementById('joSuggestedParts');
            if (partsWrap) partsWrap.style.display = 'none';
        }

        // ── Apply Job Order to Cart (service fee + auto-parts) ────────────────
        // This is the single entry point for adding a JO service to the cart.
        // It adds:
        //   1. The service fee as a 'service' line item
        //   2. All suggested parts (from DB) as 'merchandise' line items
        //      — skips parts that are out of stock (warns user)
        //      — skips parts already in cart (merges quantity)
        async function applyJobOrderToCart() {
            const svcType   = (document.getElementById('joServiceTypeValue')?.value || '').trim();
            const laborCharge = parseFloat((document.getElementById('joLaborCharge')?.value || '0').replace(/[^0-9.]/g, '')) || 0;

            if (!svcType) return; // caller already validated

            const selectedNames = svcType.split(', ').map(s => s.trim()).filter(Boolean);
            const types = window.JO_SERVICE_TYPES || [];

            // ── 1. Ensure each selected service is in cart individually ──────────
            selectedNames.forEach(name => {
                const svc = types.find(s => s.name === name);
                const price = svc && parseFloat(svc.price) > 0 ? parseFloat(svc.price) : 0;
                const existing = cart.find(i => i.item_type === 'service' && i.product_name === name);
                if (existing) {
                    if (existing.unit_price <= 0 && price > 0) existing.unit_price = price;
                } else {
                    cart.push({
                        item_type:    'service',
                        product_name: name,
                        category:     svc ? getServiceCategory(svc) : 'Service Fee',
                        size_variant: '',
                        product_id:   null,
                        quantity:     1,
                        unit_price:   price,
                    });
                }
            });

            // ── 1b. Add labor charge as separate line item (if > 0) ───────────
            if (laborCharge > 0) {
                const laborLabel = 'Labor Charge';
                const existingLabor = cart.find(i => i.item_type === 'service' && (i.product_name === laborLabel || i.category === 'Labor'));
                if (existingLabor) {
                    existingLabor.product_name = laborLabel;
                    existingLabor.unit_price = laborCharge;
                } else {
                    cart.push({
                        item_type:    'service',
                        product_name: laborLabel,
                        category:     'Labor',
                        size_variant: '',
                        product_id:   null,
                        quantity:     1,
                        unit_price:   laborCharge,
                    });
                }
            } else {
                cart = cart.filter(i => i.category !== 'Labor' && i.product_name !== 'Labor Charge');
            }

            // ── 2. Add suggested parts as merchandise items ───────────────────
            const parts = window._joSuggestedParts || [];
            const skipped = [];

            for (const part of parts) {
                if (!part.product_id) {
                    // Part not found in inventory — skip silently
                    continue;
                }
                if (!part.in_stock) {
                    skipped.push(part.product_name);
                    continue;
                }

                const qty = part.default_quantity || 1;
                const existing = cart.find(i =>
                    i.item_type === 'merchandise' && i.product_id == part.product_id
                );

                if (existing) {
                    // Merge — respect stock ceiling
                    const newQty = existing.quantity + qty;
                    existing.quantity = Math.min(newQty, part.stock_level);
                } else {
                    cart.push({
                        item_type:    'merchandise',
                        product_id:   part.product_id,
                        product_name: part.product_name,
                        category:     part.category,
                        size_variant: part.size || '',
                        quantity:     Math.min(qty, part.stock_level),
                        unit_price:   part.unit_price,
                    });
                }
            }

            renderCart();
            updateCheckoutBtn();

            // ── 3. Feedback ───────────────────────────────────────────────────
            if (skipped.length > 0) {
                showTxnAlert(
                    `Service added. ${skipped.length} part(s) skipped (out of stock): ${skipped.join(', ')}`,
                    'warning'
                );
            } else if (parts.length > 0) {
                showTxnAlert(
                    `"${selectedNames.join(', ')}" + ${parts.filter(p => p.product_id && p.in_stock).length} part(s) added to cart.`,
                    'success'
                );
            } else {
                showTxnAlert(`"${selectedNames.join(', ')}" added to cart.`, 'success');
            }
        }

        // ── Load service types from database ──────────────────────────────────
        async function loadServiceTypes(selectValue) {
            const input = document.getElementById('joServiceType');
            const hidden = document.getElementById('joServiceTypeValue');
            if (!input || !hidden) return;
            try {
                const res  = await fetch('../backend/api/get_service_types.php', { credentials: 'same-origin' });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Failed to load service types');

                // Cache for price/notes lookup and filtering
                window.JO_SERVICE_TYPES = data.types;

                // Set placeholder
                input.placeholder = 'Type to search service...';
                
                // If there's a pre-selected value, set it
                if (selectValue !== undefined) {
                    input.value = selectValue;
                    hidden.value = selectValue;
                    onJoServiceTypeChange();
                }

            } catch (err) {
                input.placeholder = '— Could not load service types —';
                console.error('loadServiceTypes error:', err);
            }
        }

        // ── Add Service Type modal ────────────────────────────────────────────
        function openAddServiceModal() {
            const nameEl  = document.getElementById('newServiceName');
            const priceEl = document.getElementById('newServicePrice');
            const catEl   = document.getElementById('newServiceCategory');
            const durationEl = document.getElementById('newServiceDuration');
            const descEl  = document.getElementById('newServiceDescription');
            const reasonEl = document.getElementById('newServiceReason');
            
            if (nameEl)  nameEl.value  = '';
            if (priceEl) priceEl.value = '';
            if (catEl)   catEl.value   = 'Others';
            if (durationEl) durationEl.value = '';
            if (descEl)  descEl.value  = '';
            if (reasonEl) reasonEl.value = '';
            setAddServiceError('');
            
            // Auto-detect category as they type in the modal
            if (nameEl && !nameEl.dataset.catListenerAdded) {
                nameEl.dataset.catListenerAdded = 'true';
                nameEl.addEventListener('input', function() {
                    if (catEl) {
                        catEl.value = getServiceCategory({ name: this.value });
                    }
                });
            }

            const modal = document.getElementById('addServiceModal');
            if (modal) modal.style.display = 'flex';
            setTimeout(() => nameEl && nameEl.focus(), 80);
        }

        function closeAddServiceModal() {
            const modal = document.getElementById('addServiceModal');
            if (modal) modal.style.display = 'none';
        }

        function setAddServiceError(msg) {
            const box  = document.getElementById('addServiceError');
            const text = document.getElementById('addServiceErrorText');
            if (!box || !text) return;
            text.textContent = msg;
            box.style.display = msg ? 'flex' : 'none';
        }

        async function submitNewServiceType() {
            const name     = (document.getElementById('newServiceName')?.value        || '').trim();
            const price    = (document.getElementById('newServicePrice')?.value       || '').trim();
            const category = (document.getElementById('newServiceCategory')?.value    || 'Others').trim();
            const duration = (document.getElementById('newServiceDuration')?.value    || '').trim();
            const description = (document.getElementById('newServiceDescription')?.value || '').trim();
            const reason   = (document.getElementById('newServiceReason')?.value      || '').trim();
            const btn      = document.getElementById('addServiceSubmitBtn');

            setAddServiceError('');
            if (!name)  { setAddServiceError('Please enter the service name.'); return; }
            if (name.length > 100) { setAddServiceError('Name is too long (max 100 characters).'); return; }
            if (!price || isNaN(price) || parseFloat(price) < 0) { setAddServiceError('Please enter a valid positive price.'); return; }
            if (!reason) { setAddServiceError('Please explain why you need this service added.'); return; }
            if (reason.length < 10) { setAddServiceError('Please provide a more detailed reason (minimum 10 characters).'); return; }

            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting…'; }

            try {
                const res  = await fetch('../backend/api/submit_master_data_request.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        request_type: 'service_type',
                        request_data: {
                            service_name: name,
                            service_category: category,
                            default_price: parseFloat(price),
                            estimated_duration: duration,
                            description: description
                        },
                        reason: reason
                    })
                });
                const data = await res.json();

                if (data.success) {
                    closeAddServiceModal();
                    // Set the value in the inputs for current use
                    const serviceInput = document.getElementById('joServiceType');
                    const serviceHidden = document.getElementById('joServiceTypeValue');
                    const servicePriceInput = document.getElementById('joServicePrice');
                    if (serviceInput) serviceInput.value = name;
                    if (serviceHidden) serviceHidden.value = name;
                    if (servicePriceInput) servicePriceInput.value = price;
                    
                    showTxnAlert(
                        'Request submitted successfully! Request ID: #' + data.request_id + '. Status: Pending Manager Approval. You can use "' + name + '" now.',
                        'success'
                    );
                } else {
                    setAddServiceError(data.error || 'Submission failed.');
                }
            } catch (err) {
                setAddServiceError('Network error: ' + err.message);
            } finally {
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit for Approval'; }
            }
        }
        

        // Close service modal on backdrop click
        document.addEventListener('click', function(e) {
            const modal = document.getElementById('addServiceModal');
            if (modal && e.target === modal) closeAddServiceModal();
        });

        function onJoServicePriceInput() {
            // Price manually entered — live update cart if already added
            const svcType  = (document.getElementById('joServiceTypeValue')?.value || '').trim();
            const svcPrice = parseFloat(document.getElementById('joServicePrice')?.value || 0);
            if (svcType) {
                const existingSvc = cart.find(i => i.item_type === 'service' && i.product_name === svcType);
                if (existingSvc) {
                    existingSvc.unit_price = Math.max(0, svcPrice);
                    renderCart();
                }
            }
        }

        function onJoLaborChargeInput() {
            const laborCharge = parseFloat((document.getElementById('joLaborCharge')?.value || '0').replace(/[^0-9.]/g, '')) || 0;
            const laborLabel = 'Labor Charge';
            const existingLabor = cart.find(i => i.item_type === 'service' && (i.product_name === laborLabel || i.category === 'Labor'));
            if (laborCharge > 0) {
                if (existingLabor) {
                    existingLabor.unit_price = laborCharge;
                } else {
                    const hasServiceInCart = cart.some(i => i.item_type === 'service');
                    if (hasServiceInCart) {
                        cart.push({
                            item_type:    'service',
                            product_name: laborLabel,
                            category:     'Labor',
                            size_variant: '',
                            product_id:   null,
                            quantity:     1,
                            unit_price:   laborCharge,
                        });
                    }
                }
            } else if (existingLabor) {
                cart = cart.filter(i => i.category !== 'Labor' && i.product_name !== laborLabel);
            }
            renderCart();
        }

        // ── Customer data for search and autocomplete ─────────────────────────────
        // Store complete customer data including vehicle info
        const customerData = <?= json_encode(array_map(function($customer) {
            return [
                'id' => $customer['id'],
                'full_name' => trim($customer['name'] ?? ''),
                'first_name' => trim($customer['first_name'] ?? ''),
                'last_name' => trim($customer['last_name'] ?? ''),
                'contact_number' => $customer['contact_number'] ?? '',
                'vehicle_type' => $customer['vehicle_type'] ?? '',
                'vehicle_brand' => $customer['vehicle_brand'] ?? '',
                'vehicle_model' => $customer['vehicle_model'] ?? '',
                'plate_number' => $customer['plate_number'] ?? '',
                'points' => (int)($customer['points'] ?? 0),
                'customer_id' => $customer['customer_id'] ?? '',
                'id_number' => $customer['id_number'] ?? '',
                'customer_type' => $customer['customer_type'] ?? 'walk-in'
            ];
        }, $customers)) ?>;

        console.log('Customer data loaded:', customerData.length, 'customers');
        if (customerData.length > 0) {
            console.log('Sample customer:', customerData[0]);
        }

        let customerRequestPrefix = 'merch';

        function ensureCustomerRequestModal() {
            if (document.getElementById('customerRequestModal')) return;

            const modalHtml = `
                <div id="customerRequestModal" style="display:none;position:fixed;inset:0;z-index:10000;background:rgba(15,23,42,.48);align-items:center;justify-content:center;padding:18px;">
                    <div style="background:#fff;border-radius:14px;max-width:720px;width:100%;box-shadow:0 18px 50px rgba(15,23,42,.24);overflow:hidden;">
                        <div style="display:flex;align-items:flex-start;gap:12px;padding:18px 22px;border-bottom:1px solid #e2e8f0;">
                            <div style="width:42px;height:42px;border-radius:10px;background:#eff6ff;display:flex;align-items:center;justify-content:center;color:#003b7a;">
                                <i class="fas fa-user-plus"></i>
                            </div>
                            <div style="flex:1;">
                                <div style="font-size:16px;font-weight:800;color:#00264D;">Request New Customer</div>
                                <div style="font-size:12px;color:#64748b;">Submitted to the Manager for approval.</div>
                            </div>
                            <button type="button" onclick="closeCustomerRequestModal()" style="border:none;background:transparent;color:#64748b;font-size:22px;line-height:1;cursor:pointer;">&times;</button>
                        </div>
                        <div style="padding:20px 22px;max-height:70vh;overflow:auto;">
                            <div class="txn-form-grid" style="margin-bottom:14px;">
                                <div class="txn-field">
                                    <label>First Name <span style="color:#dc2626;">*</span></label>
                                    <input type="text" id="requestCustomerFirstName" class="txn-input" autocomplete="off">
                                </div>
                                <div class="txn-field">
                                    <label>Middle Name</label>
                                    <input type="text" id="requestCustomerMiddleName" class="txn-input" autocomplete="off">
                                </div>
                                <div class="txn-field">
                                    <label>Last Name <span style="color:#dc2626;">*</span></label>
                                    <input type="text" id="requestCustomerLastName" class="txn-input" autocomplete="off">
                                </div>
                            </div>
                            <div class="txn-form-grid" style="margin-bottom:14px;">
                                <div class="txn-field">
                                    <label>Contact Number <span style="color:#dc2626;">*</span></label>
                                    <input type="text" id="requestCustomerContact" class="txn-input" autocomplete="off">
                                </div>
                                <div class="txn-field">
                                    <label>Customer Type</label>
                                    <select id="requestCustomerType" class="txn-select">
                                        <option value="walk-in">Walk-in</option>
                                        <option value="regular">Regular</option>
                                        <option value="credit">Credit</option>
                                    </select>
                                </div>
                            </div>
                            <div class="txn-field" style="margin-bottom:14px;">
                                <label>Address</label>
                                <input type="text" id="requestCustomerAddress" class="txn-input" autocomplete="off">
                            </div>
                            <div style="font-size:11px;font-weight:800;color:#00264D;text-transform:uppercase;margin:14px 0 10px;">Vehicle Information</div>
                            <div class="txn-form-grid" style="margin-bottom:14px;">
                                <div class="txn-field">
                                    <label>Plate Number</label>
                                    <input type="text" id="requestCustomerPlate" class="txn-input" autocomplete="off">
                                </div>
                                <div class="txn-field">
                                    <label>Vehicle Make</label>
                                    <input type="text" id="requestCustomerMake" class="txn-input" autocomplete="off">
                                </div>
                                <div class="txn-field">
                                    <label>Vehicle Model</label>
                                    <input type="text" id="requestCustomerModel" class="txn-input" autocomplete="off">
                                </div>
                                <div class="txn-field">
                                    <label>Vehicle Type</label>
                                    <input type="text" id="requestCustomerVehicleType" class="txn-input" autocomplete="off">
                                </div>
                            </div>
                            <div class="txn-field" style="margin-bottom:14px;">
                                <label>Reason / Notes</label>
                                <textarea id="requestCustomerReason" class="txn-input" rows="3" placeholder="Why does this customer need to be added?" style="resize:vertical;"></textarea>
                            </div>
                            <div id="customerRequestError" style="display:none;background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;color:#991b1b;font-size:12px;padding:10px 12px;margin-bottom:14px;"></div>
                            <div style="display:flex;gap:10px;justify-content:flex-end;">
                                <button type="button" onclick="closeCustomerRequestModal()" class="txn-btn secondary">Cancel</button>
                                <button type="button" id="customerRequestSubmitBtn" onclick="submitCustomerRequest()" class="txn-btn primary">
                                    <i class="fas fa-paper-plane"></i> Submit Request
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            document.getElementById('customerRequestModal')?.addEventListener('click', function(e) {
                if (e.target === this) closeCustomerRequestModal();
            });
        }

        function setCustomerRequestError(message) {
            const box = document.getElementById('customerRequestError');
            if (!box) return;
            box.textContent = message || '';
            box.style.display = message ? 'block' : 'none';
        }

        function openCustomerRequestModal(prefix) {
            ensureCustomerRequestModal();
            customerRequestPrefix = prefix === 'jo' ? 'jo' : 'merch';
            setCustomerRequestError('');

            const get = id => (document.getElementById(id)?.value || '').trim();
            document.getElementById('requestCustomerFirstName').value = get(customerRequestPrefix + 'FirstName');
            document.getElementById('requestCustomerMiddleName').value = '';
            document.getElementById('requestCustomerLastName').value = get(customerRequestPrefix + 'LastName');
            document.getElementById('requestCustomerContact').value = get(customerRequestPrefix + 'ContactNumber');
            document.getElementById('requestCustomerType').value = 'walk-in';
            document.getElementById('requestCustomerAddress').value = '';
            document.getElementById('requestCustomerPlate').value = get(customerRequestPrefix + 'VehiclePlate');
            document.getElementById('requestCustomerMake').value = get(customerRequestPrefix + 'VehicleBrand');
            document.getElementById('requestCustomerModel').value = get(customerRequestPrefix + 'VehicleModel');
            document.getElementById('requestCustomerVehicleType').value = get(customerRequestPrefix + 'VehicleType');
            document.getElementById('requestCustomerReason').value = 'Needed for transaction lookup.';

            document.getElementById(customerRequestPrefix + 'CustomerResults')?.style.setProperty('display', 'none');
            document.getElementById(customerRequestPrefix + 'FirstNameResults')?.style.setProperty('display', 'none');
            document.getElementById('customerRequestModal').style.display = 'flex';
            document.getElementById('requestCustomerFirstName')?.focus();
        }

        function closeCustomerRequestModal() {
            const modal = document.getElementById('customerRequestModal');
            if (modal) modal.style.display = 'none';
        }

        async function submitCustomerRequest() {
            const firstName = (document.getElementById('requestCustomerFirstName')?.value || '').trim();
            const lastName = (document.getElementById('requestCustomerLastName')?.value || '').trim();
            const contact = (document.getElementById('requestCustomerContact')?.value || '').trim();

            if (!firstName || !lastName || !contact) {
                setCustomerRequestError('First name, last name, and contact number are required.');
                return;
            }

            const form = new FormData();
            form.append('action', 'request_new_customer');
            form.append('first_name', firstName);
            form.append('middle_name', (document.getElementById('requestCustomerMiddleName')?.value || '').trim());
            form.append('last_name', lastName);
            form.append('contact_number', contact);
            form.append('address', (document.getElementById('requestCustomerAddress')?.value || '').trim());
            form.append('customer_type', document.getElementById('requestCustomerType')?.value || 'walk-in');
            form.append('vehicle_plate', (document.getElementById('requestCustomerPlate')?.value || '').trim());
            form.append('vehicle_make', (document.getElementById('requestCustomerMake')?.value || '').trim());
            form.append('vehicle_model', (document.getElementById('requestCustomerModel')?.value || '').trim());
            form.append('vehicle_type', (document.getElementById('requestCustomerVehicleType')?.value || '').trim());
            form.append('request_reason', (document.getElementById('requestCustomerReason')?.value || '').trim());

            const btn = document.getElementById('customerRequestSubmitBtn');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
            }

            try {
                const res = await fetch('staff_customer_operations.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: form
                });
                const data = await res.json();
                if (data.success) {
                    closeCustomerRequestModal();
                    showTxnAlert(data.message || 'Customer request submitted to the Manager.', 'success');
                } else {
                    setCustomerRequestError(data.error || 'Unable to submit customer request.');
                }
            } catch (err) {
                setCustomerRequestError('Network error: ' + err.message);
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
                }
            }
        }

        // ═══════════════════════════════════════════════════════════════════════
        // Customer Type Toggle & Search Functions
        // ═══════════════════════════════════════════════════════════════════════
        
                function setCustomerMode(prefix, mode) {
            const hiddenEl = document.getElementById(prefix + 'CustomerModeType');
            if (hiddenEl) hiddenEl.value = mode;

            const fnInput = document.getElementById(prefix + 'FirstName');
            const lnInput = document.getElementById(prefix + 'LastName');

            if (mode === 'walkin') {
                if (fnInput && (!fnInput.value || fnInput.value === 'Walk-In')) {
                    fnInput.value = 'Walk-In';
                }
                if (lnInput && (!lnInput.value || lnInput.value === 'Customer')) {
                    lnInput.value = 'Customer';
                }
                clearSelectedCustomer(prefix);
            } else if (mode === 'registered') {
                if (fnInput && fnInput.value === 'Walk-In') fnInput.value = '';
                if (lnInput && lnInput.value === 'Customer') lnInput.value = '';
                if (fnInput) fnInput.focus();
            }
        }

        function toggleCustomerType(prefix) {
            if (Object.prototype.hasOwnProperty.call(selectedCustomerIds, prefix)) {
                selectedCustomerIds[prefix] = null;
            }

            // UPDATED: First Name is typeable for search, other fields auto-fill from selection
            const searchSection = document.getElementById(prefix + 'SearchCustomerSection');
            const firstNameInput = document.getElementById(prefix + 'FirstName');
            const lastNameInput = document.getElementById(prefix + 'LastName');
            const contactInput = document.getElementById(prefix + 'ContactNumber');
            const vehicleType = document.getElementById(prefix + 'VehicleType');
            const vehicleBrand = document.getElementById(prefix + 'VehicleBrand');
            const vehicleModel = document.getElementById(prefix + 'VehicleModel');
            const vehiclePlate = document.getElementById(prefix + 'VehiclePlate');
            
            // Hide search section if it still exists (we removed it)
            if (searchSection) searchSection.style.display = 'none';
            
            // First Name is editable for search/filter
            if (firstNameInput) {
                firstNameInput.value = '';
                firstNameInput.readOnly = false;  // ✅ Can type freely
                firstNameInput.style.background = '#fff';  // White background
            }
            
            // All fields are editable - staff can type manually or auto-fill from customer search
            if (lastNameInput) {
                lastNameInput.value = '';
                lastNameInput.readOnly = false;
                lastNameInput.style.background = '#fff';
            }
            if (contactInput) {
                contactInput.value = '';
                contactInput.readOnly = false;
                contactInput.style.background = '#fff';
            }
            if (vehicleType) {
                vehicleType.value = '';
                vehicleType.readOnly = false;
                vehicleType.style.background = '#fff';
            }
            if (vehicleBrand) {
                vehicleBrand.value = '';
                vehicleBrand.readOnly = false;
                vehicleBrand.style.background = '#fff';
            }
            if (vehicleModel) {
                vehicleModel.value = '';
                vehicleModel.readOnly = false;
                vehicleModel.style.background = '#fff';
            }
            if (vehiclePlate) {
                vehiclePlate.value = '';
                vehiclePlate.readOnly = false;
                vehiclePlate.style.background = '#fff';
            }
        }

        function clearSelectedCustomer(prefix) {
            if (Object.prototype.hasOwnProperty.call(selectedCustomerIds, prefix)) {
                selectedCustomerIds[prefix] = null;
            }
            // Unlock customer fields for prefix (do NOT clear values — user may be typing)
            const fields = [prefix + 'FirstName', prefix + 'LastName', prefix + 'ContactNumber'];
            fields.forEach(id => {
                const el = document.getElementById(id);
                if (el) {
                    el.removeAttribute('readonly');
                    el.style.background = '';
                    el.style.cursor = '';
                    el.style.color = '';
                }
            });
            const banner = document.getElementById(prefix + 'CustomerLockedBanner');
            if (banner) banner.style.display = 'none';
        }

        // Full reset — clears all fields (used by reset button only)
        function clearSelectedCustomerFull(prefix) {
            clearSelectedCustomer(prefix);
            const fn = document.getElementById(prefix + 'FirstName');
            if (fn) fn.value = '';
            const ln = document.getElementById(prefix + 'LastName');
            if (ln) ln.value = '';
            const cn = document.getElementById(prefix + 'ContactNumber');
            if (cn) cn.value = '';
        }

        // Called on oninput \u2014 only unlocks the field if it was locked (readonly), never clears values
        function unlockCustomerIfNeeded(prefix) {
            const fn = document.getElementById(prefix + 'FirstName');
            if (fn && fn.hasAttribute('readonly')) {
                // User is editing a locked field \u2014 clear the selected customer and unlock
                clearSelectedCustomer(prefix);
            }
            // else: field is already unlocked (fresh entry), just let typing happen normally
        }

        function searchCustomer(prefix) {
            const searchInput = document.getElementById(prefix + 'SearchCustomer');
            const resultsDiv = document.getElementById(prefix + 'CustomerResults');
            
            if (!searchInput || !resultsDiv) return;
            
            const query = searchInput.value.trim().toLowerCase();
            
            if (query.length < 2) {
                resultsDiv.style.display = 'none';
                return;
            }
            
            // Filter customers by name, contact, or plate
            const filtered = customerData.filter(c => {
                const fullName = (c.first_name + ' ' + c.last_name).toLowerCase();
                const contact = (c.contact_number || '').toLowerCase();
                const plate = (c.plate_number || '').toLowerCase();
                
                return fullName.includes(query) || 
                       contact.includes(query) || 
                       plate.includes(query);
            });
            
            if (filtered.length === 0) {
                resultsDiv.innerHTML = `
                    <div style="padding:14px 16px;text-align:center;color:#64748b;font-size:13px;">No customers found</div>
                    <div style="padding:0 16px 14px;text-align:center;">
                        <button type="button" onclick="openCustomerRequestModal('${prefix}')"
                                style="border:1px solid #003b7a;background:#003b7a;color:#fff;border-radius:8px;
                                       padding:8px 12px;font-size:12px;font-weight:700;cursor:pointer;">
                            <i class="fas fa-user-plus"></i> Request New Customer
                        </button>
                    </div>
                `;
                resultsDiv.style.display = 'block';
                return;
            }
            
            // Build results HTML
            let html = '';
            filtered.forEach(customer => {
                const displayName = (customer.first_name + ' ' + customer.last_name).trim();
                const displayContact = customer.contact_number || 'No contact';
                const displayVehicle = [customer.vehicle_brand, customer.vehicle_model].filter(Boolean).join(' ') || 'No vehicle';
                const displayPlate = customer.plate_number || 'No plate';
                
                html += `
                    <div onclick="selectCustomer('${prefix}', ${customer.id})" 
                         style="padding:12px 16px;cursor:pointer;border-bottom:1px solid #f1f5f9;transition:background .15s;"
                         onmouseover="this.style.background='#f8fbff'"
                         onmouseout="this.style.background='#fff'">
                        <div style="font-weight:600;font-size:13px;color:#1e293b;margin-bottom:4px;">
                            ${escapeHtml(displayName)}
                        </div>
                        <div style="font-size:12px;color:#64748b;display:flex;gap:12px;flex-wrap:wrap;">
                            <span><i class="fas fa-phone" style="width:14px;"></i> ${escapeHtml(displayContact)}</span>
                            <span><i class="fas fa-car" style="width:14px;"></i> ${escapeHtml(displayVehicle)}</span>
                            <span><i class="fas fa-id-card" style="width:14px;"></i> ${escapeHtml(displayPlate)}</span>
                        </div>
                    </div>
                `;
            });
            
            resultsDiv.innerHTML = html;
            resultsDiv.style.display = 'block';
        }

        // ── Clear Job Order services, inspection, complaint, remarks, cart & payment ─────
        function clearJobOrderDetailsOnly() {
            // Clear Vehicle Inspection checkboxes & remarks
            document.querySelectorAll('input[name="jo_inspection[]"]').forEach(cb => cb.checked = false);
            const inspRemarks = document.getElementById('joInspectionRemarks');
            if (inspRemarks) inspRemarks.value = '';

            // Clear Complaint & Initial Assessment / Repair Recommendation
            const joComplaint = document.getElementById('joCustomerComplaint');
            if (joComplaint) joComplaint.value = '';
            const joRec = document.getElementById('joRepairRecommendation');
            if (joRec) joRec.value = '';

            // Reset Priority to Normal & Clear Expected Release Date
            const priNormal = document.getElementById('joPriorityNormal');
            if (priNormal) priNormal.checked = true;
            const joExpectedRelease = document.getElementById('joExpectedRelease');
            if (joExpectedRelease) joExpectedRelease.value = '';

            // Clear Service Details
            const joServiceCategory = document.getElementById('joServiceCategory');
            const joServiceType = document.getElementById('joServiceType');
            const joServiceTypeValue = document.getElementById('joServiceTypeValue');
            const joServicePrice = document.getElementById('joServicePrice');
            const joLaborCharge = document.getElementById('joLaborCharge');
            const joNotes = document.getElementById('joNotes');
            const joEstimatedDuration = document.getElementById('joEstimatedDuration');
            if (joServiceCategory) joServiceCategory.selectedIndex = 0;
            if (joServiceType) joServiceType.value = '';
            if (joServiceTypeValue) joServiceTypeValue.value = '';
            if (joServicePrice) joServicePrice.value = '';
            if (joLaborCharge) joLaborCharge.value = '';
            if (joNotes) joNotes.value = '';
            if (joEstimatedDuration) joEstimatedDuration.value = '';

            // Reset mechanic
            const joMechanic = document.getElementById('joMechanic');
            if (joMechanic) joMechanic.value = '';
            const joMechanicId = document.getElementById('joMechanicId');
            if (joMechanicId) joMechanicId.value = '';
            const joMechanicName = document.getElementById('joMechanicName');
            if (joMechanicName) joMechanicName.value = '';
            if (typeof hideMechanicDropdown === 'function') hideMechanicDropdown();

            // Clear Cart & Payment details
            if (window.cart) window.cart = [];
            if (typeof cart !== 'undefined') cart = [];
            if (typeof updateCartDisplay === 'function') updateCartDisplay();
            if (typeof renderCart === 'function') renderCart();

            const pmSel = document.getElementById('paymentMethod');
            if (pmSel) pmSel.selectedIndex = 0;
            if (typeof onPaymentChange === 'function') onPaymentChange();
            ['amountTendered', 'changeAmount', 'cashBalanceDue', 'ccAmount', 'ccLastFour', 'ccRefNumber', 'dcAmount', 'dcRefNumber', 'ewAmount', 'ewRefNumber', 'fcAmount', 'fcNumber', 'fcCompanyName', 'fcAuthNumber'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.value = '';
            });

            // Hide previews & warnings
            const notesWrap = document.getElementById('joServicePriceNotes');
            const partsWrap = document.getElementById('joSuggestedParts');
            if (notesWrap) notesWrap.style.display = 'none';
            if (partsWrap) partsWrap.style.display = 'none';
            const mechanicBusyWarn = document.getElementById('joMechanicBusyWarn');
            if (mechanicBusyWarn) mechanicBusyWarn.style.display = 'none';

            // Clear stored drafts for job order
            try {
                sessionStorage.removeItem('jo_draft');
                localStorage.removeItem('jo_draft');
                if (window.PetronDraft) {
                    PetronDraft.clear('jo');
                    PetronDraft.clear('job_orders');
                }
            } catch (e) {}
        }

        function selectCustomer(prefix, customerId) {
            const customer = customerData.find(c => c.id == customerId);
            if (!customer) return;
            selectedCustomerIds[prefix] = parseInt(customer.id, 10) || null;
            
            // Clear any previously filled service types, inspection items, complaints, remarks, cart, and payment details
            if (prefix === 'jo') {
                clearJobOrderDetailsOnly();
            }

            // Fill in all customer fields
            const firstNameInput = document.getElementById(prefix + 'FirstName');
            const lastNameInput = document.getElementById(prefix + 'LastName');
            const contactInput = document.getElementById(prefix + 'ContactNumber');
            const vehicleType = document.getElementById(prefix + 'VehicleType');
            const vehicleBrand = document.getElementById(prefix + 'VehicleBrand');
            const vehicleModel = document.getElementById(prefix + 'VehicleModel');
            const vehiclePlate = document.getElementById(prefix + 'VehiclePlate');
            
            if (firstNameInput) firstNameInput.value = customer.first_name || '';
            if (lastNameInput) lastNameInput.value = customer.last_name || '';
            if (contactInput) contactInput.value = customer.contact_number || '';
            if (vehicleType) vehicleType.value = customer.vehicle_type || '';
            if (vehicleBrand) vehicleBrand.value = customer.vehicle_brand || '';
            if (vehicleModel) vehicleModel.value = customer.vehicle_model || '';
            if (vehiclePlate) vehiclePlate.value = customer.plate_number || '';
            
            // Auto-switch loyalty dropdown based on Customer Master Data
            const loyaltyDropdown = document.getElementById('loyaltyProgram');
            const loyaltyCardNoInput = document.getElementById('loyaltyCardNo');
            const loyaltyPointsBalanceEl = document.getElementById('loyaltyPointsBalance');
            const hasLoyaltyCard = customer.loyalty_card_no && customer.loyalty_card_no.trim() !== '';

            if (hasLoyaltyCard) {
                // Customer has a card — switch to Petron Rewards Card, show fields, fill values
                if (loyaltyDropdown) loyaltyDropdown.value = 'Petron Rewards Card';
                if (loyaltyCardNoInput) loyaltyCardNoInput.value = customer.loyalty_card_no;
                if (loyaltyPointsBalanceEl) loyaltyPointsBalanceEl.value = customer.points || 0;
                onLoyaltyChange(); // shows loyaltyFields and recalculates points
            } else {
                // No card — keep No Loyalty, hide fields
                if (loyaltyDropdown) loyaltyDropdown.value = 'No Loyalty';
                if (loyaltyCardNoInput) loyaltyCardNoInput.value = '';
                if (loyaltyPointsBalanceEl) loyaltyPointsBalanceEl.value = 0;
                onLoyaltyChange(); // hides loyaltyFields
            }

            // Hide search results and clear search input
            const resultsDiv = document.getElementById(prefix + 'CustomerResults');
            const searchInput = document.getElementById(prefix + 'SearchCustomer');
            if (resultsDiv) resultsDiv.style.display = 'none';
            if (searchInput) searchInput.value = (customer.first_name + ' ' + customer.last_name).trim();
        }

        function showCustomerResults(prefix) {
            const searchInput = document.getElementById(prefix + 'SearchCustomer');
            if (searchInput && searchInput.value.trim().length >= 2) {
                searchCustomer(prefix);
            }
        }

        // ── NEW: Search customer by typing in First Name field ──────────────────
        function searchCustomerByName(prefix) {
            const firstNameInput = document.getElementById(prefix + 'FirstName');
            const resultsDiv = document.getElementById(prefix + 'FirstNameResults');
            
            if (!firstNameInput || !resultsDiv) return;
            
            const query = firstNameInput.value.trim().toLowerCase();
            
            console.log('Searching customers with query:', query);
            
            // Show dropdown even with 1 character
            if (query.length < 1) {
                resultsDiv.style.display = 'none';
                return;
            }
            
            // Filter customers by first name or last name
            const filtered = customerData.filter(c => {
                const firstName = (c.first_name || '').toLowerCase();
                const lastName = (c.last_name || '').toLowerCase();
                const fullName = (firstName + ' ' + lastName).trim();
                const contact = (c.contact_number || '').toLowerCase();
                const plate = (c.plate_number || '').toLowerCase();
                
                return firstName.includes(query) || 
                       lastName.includes(query) ||
                       fullName.includes(query) ||
                       contact.includes(query) ||
                       plate.includes(query);
            });
            
            console.log('Found', filtered.length, 'matching customers');
            
            if (filtered.length === 0) {
                resultsDiv.innerHTML = `
                    <div style="padding:14px 16px;text-align:center;color:#64748b;font-size:13px;">No customers found</div>
                    <div style="padding:0 16px 14px;text-align:center;">
                        <button type="button" onclick="openCustomerRequestModal('${prefix}')"
                                style="border:1px solid #003b7a;background:#003b7a;color:#fff;border-radius:8px;
                                       padding:8px 12px;font-size:12px;font-weight:700;cursor:pointer;">
                            <i class="fas fa-user-plus"></i> Request New Customer
                        </button>
                    </div>
                `;
                resultsDiv.style.display = 'block';
                return;
            }
            
            // Build results HTML
            let html = '';
            filtered.forEach(customer => {
                const displayName = (customer.first_name + ' ' + customer.last_name).trim();
                const displayContact = customer.contact_number || 'No contact';
                const displayVehicle = [customer.vehicle_brand, customer.vehicle_model].filter(Boolean).join(' ') || 'No vehicle';
                const displayPlate = customer.plate_number || 'No plate';
                
                html += `
                    <div onclick="selectCustomerFromName('${prefix}', ${customer.id})" 
                         style="padding:12px 16px;cursor:pointer;border-bottom:1px solid #f1f5f9;transition:background .15s;"
                         onmouseover="this.style.background='#f8fbff'"
                         onmouseout="this.style.background='#fff'">
                        <div style="font-weight:600;font-size:13px;color:#1e293b;margin-bottom:4px;">
                            ${escapeHtml(displayName)}
                        </div>
                        <div style="font-size:12px;color:#64748b;display:flex;gap:12px;flex-wrap:wrap;">
                            <span><i class="fas fa-phone" style="width:14px;"></i> ${escapeHtml(displayContact)}</span>
                            <span><i class="fas fa-car" style="width:14px;"></i> ${escapeHtml(displayVehicle)}</span>
                            <span><i class="fas fa-id-card" style="width:14px;"></i> ${escapeHtml(displayPlate)}</span>
                        </div>
                    </div>
                `;
            });
            
            resultsDiv.innerHTML = html;
            resultsDiv.style.display = 'block';
        }

        // ── Select customer from First Name dropdown ────────────────────────────
        function selectCustomerFromName(prefix, customerId) {
            const customer = customerData.find(c => c.id == customerId);
            if (!customer) return;
            selectedCustomerIds[prefix] = parseInt(customer.id, 10) || null;
            
            console.log('Selected customer from name field:', customer);
            
            // Clear any previously filled service types, inspection items, complaints, remarks, cart, and payment details
            if (prefix === 'jo') {
                clearJobOrderDetailsOnly();
            }

            // Fill in all customer fields
            const firstNameInput = document.getElementById(prefix + 'FirstName');
            const lastNameInput = document.getElementById(prefix + 'LastName');
            const contactInput = document.getElementById(prefix + 'ContactNumber');
            const vehicleType = document.getElementById(prefix + 'VehicleType');
            const vehicleBrand = document.getElementById(prefix + 'VehicleBrand');
            const vehicleModel = document.getElementById(prefix + 'VehicleModel');
            const vehiclePlate = document.getElementById(prefix + 'VehiclePlate');
            
            if (firstNameInput) firstNameInput.value = customer.first_name || '';
            if (lastNameInput) lastNameInput.value = customer.last_name || '';
            if (contactInput) contactInput.value = customer.contact_number || '';
            if (vehicleType) vehicleType.value = customer.vehicle_type || '';
            if (vehicleBrand) vehicleBrand.value = customer.vehicle_brand || '';
            if (vehicleModel) vehicleModel.value = customer.vehicle_model || '';
            if (vehiclePlate) vehiclePlate.value = customer.plate_number || '';

            // Lock customer fields (jo and merch prefixes)
            const lockFields = [firstNameInput, lastNameInput, contactInput];
            lockFields.forEach(el => {
                if (!el) return;
                el.setAttribute('readonly', true);
                el.style.background = '#f0fdf4';
                el.style.cursor = 'not-allowed';
                el.style.color = '#15803d';
            });
            const banner = document.getElementById(prefix + 'CustomerLockedBanner');
            if (banner) banner.style.display = 'flex';
            
            // Automatically fill loyalty fields if customer matches
            const loyaltyCardNoInput = document.getElementById('loyaltyCardNo');
            if (loyaltyCardNoInput) {
                const cardVal = customer.customer_id || customer.id_number || '';
                loyaltyCardNoInput.value = cardVal;
                loyaltyCardNoInput.dispatchEvent(new Event('input'));
            }

            // Hide the first name dropdown
            const resultsDiv = document.getElementById(prefix + 'FirstNameResults');
            if (resultsDiv) resultsDiv.style.display = 'none';
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Close customer results when clicking outside
        document.addEventListener('click', function(e) {
            // Close first name dropdown
            if (!e.target.closest('#joFirstName') && !e.target.closest('#joFirstNameResults')) {
                const resultsDiv = document.getElementById('joFirstNameResults');
                if (resultsDiv) resultsDiv.style.display = 'none';
            }
            if (!e.target.closest('#merchFirstName') && !e.target.closest('#merchFirstNameResults')) {
                const resultsDiv = document.getElementById('merchFirstNameResults');
                if (resultsDiv) resultsDiv.style.display = 'none';
            }
        });

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Set initial state for No Customer
            toggleCustomerType('jo');
            toggleCustomerType('merch'); // Initialize merchandise section too
        });

        // ── Vehicle type change ───────────────────────────────────────────────
        function onVehicleTypeChange() { /* reserved */ }

        // ── Vehicle Type Custom Dropdown ──────────────────────────────────────
        let _vehicleTypesAll = []; // flat list: [{name, category}]

        function showVehicleDropdown() {
            filterVehicleDropdown(document.getElementById('joVehicleType')?.value || '');
            document.getElementById('vehicleTypeDropdown').style.display = 'block';
        }
        function hideVehicleDropdown() {
            document.getElementById('vehicleTypeDropdown').style.display = 'none';
        }
        function filterVehicleDropdown(query) {
            const dd  = document.getElementById('vehicleTypeDropdown');
            if (!dd) return;
            const q   = query.trim().toLowerCase();
            const matches = q === ''
                ? _vehicleTypesAll
                : _vehicleTypesAll.filter(v =>
                    v.name.toLowerCase().includes(q) ||
                    v.category.toLowerCase().includes(q));

            if (matches.length === 0) {
                dd.innerHTML = '<div style="padding:10px 14px;font-size:12px;color:#94a3b8;">No matches found</div>';
                dd.style.display = 'block';
                return;
            }

            // Group by category
            const grouped = {};
            matches.forEach(v => {
                if (!grouped[v.category]) grouped[v.category] = [];
                grouped[v.category].push(v.name);
            });

            let html = '';
            Object.entries(grouped).forEach(([cat, names]) => {
                html += `<div style="padding:6px 12px 2px;font-size:10px;font-weight:700;
                                     color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;
                                     background:#f8fafc;border-bottom:1px solid #f1f5f9;">${cat}</div>`;
                names.forEach(name => {
                    html += `<div onclick="selectVehicleType('${name.replace(/'/g,"&#39;")}')"
                                  style="padding:9px 16px;font-size:13px;cursor:pointer;color:#1e293b;
                                         border-bottom:1px solid #f8fafc;transition:background .15s;"
                                  onmouseover="this.style.background='#eff6ff'"
                                  onmouseout="this.style.background=''">${name}</div>`;
                });
            });
            dd.innerHTML = html;
            dd.style.display = 'block';
        }
        function selectVehicleType(name) {
            const inp = document.getElementById('joVehicleType');
            if (inp) inp.value = name;
            
            // Auto-fill Vehicle Brand from the vehicle name
            const brandInput = document.getElementById('joVehicleBrand');
            if (brandInput && name) {
                // Extract brand from vehicle name (first word is usually the brand)
                const brand = name.split(' ')[0];
                brandInput.value = brand;
            }
            
            hideVehicleDropdown();
            onVehicleTypeChange();
        }

        // ── Load vehicle types from database ─────────────────────────────────
        async function loadVehicleTypes(selectValue) {
            const input = document.getElementById('joVehicleType');
            if (!input) return;
            try {
                const res  = await fetch('../backend/api/get_vehicle_types.php', { credentials: 'same-origin' });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Failed to load vehicle types');

                const prev = selectValue !== undefined ? selectValue : input.value;
                _vehicleTypesAll = [];

                // Build flat list from grouped data for custom dropdown
                Object.entries(data.groups).forEach(([category, vehicles]) => {
                    vehicles.forEach(v => {
                        _vehicleTypesAll.push({ name: v.name, category });
                    });
                });

                // Restore previous value if any
                if (prev) input.value = prev;
            } catch (err) {
                _vehicleTypesAll = [];
                console.error('loadVehicleTypes error:', err);
            }
        }

        // ── Add Vehicle Type modal ────────────────────────────────────────────
        function openAddVehicleModal() {
            const brandEl  = document.getElementById('newVehicleBrand');
            const modelEl  = document.getElementById('newVehicleModel');
            const typeEl   = document.getElementById('newVehicleType');
            const fuelEl   = document.getElementById('newVehicleFuelType');
            const remarksEl = document.getElementById('newVehicleRemarks');
            
            if (brandEl) brandEl.value = '';
            if (modelEl) modelEl.value = '';
            if (typeEl)  typeEl.value  = '';
            if (fuelEl)  fuelEl.value  = 'Gasoline';
            if (remarksEl) remarksEl.value = '';
            
            setAddVehicleError('');
            const modal = document.getElementById('addVehicleModal');
            if (modal) { modal.style.display = 'flex'; }
            setTimeout(() => brandEl && brandEl.focus(), 80);
        }

        function closeAddVehicleModal() {
            const modal = document.getElementById('addVehicleModal');
            if (modal) modal.style.display = 'none';
        }

        function setAddVehicleError(msg) {
            const box  = document.getElementById('addVehicleError');
            const text = document.getElementById('addVehicleErrorText');
            if (!box || !text) return;
            text.textContent = msg;
            box.style.display = msg ? 'flex' : 'none';
        }

        async function submitNewVehicleType() {
            const brand   = (document.getElementById('newVehicleBrand')?.value   || '').trim();
            const model   = (document.getElementById('newVehicleModel')?.value   || '').trim();
            const type    = (document.getElementById('newVehicleType')?.value    || '').trim();
            const fuel    = (document.getElementById('newVehicleFuelType')?.value    || '').trim();
            const remarks = (document.getElementById('newVehicleRemarks')?.value || '').trim();
            const btn     = document.getElementById('addVehicleSubmitBtn');

            setAddVehicleError('');
            if (!brand)   { setAddVehicleError('Please enter the vehicle brand.'); return; }
            if (!model)   { setAddVehicleError('Please enter the vehicle model.'); return; }
            if (!type)    { setAddVehicleError('Please select or enter a vehicle type.'); return; }
            if (!fuel)    { setAddVehicleError('Please select a fuel type.'); return; }
            if (!remarks) { setAddVehicleError('Please enter remarks/reason for request.'); return; }
            if (remarks.length < 5) { setAddVehicleError('Please provide a reason with at least 5 characters.'); return; }

            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting…'; }

            try {
                const res  = await fetch('../backend/api/submit_master_data_request.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        request_type: 'vehicle_type',
                        request_data: {
                            vehicle_brand: brand,
                            vehicle_model: model,
                            vehicle_type: type,
                            fuel_type: fuel,
                            remarks: remarks
                        },
                        reason: remarks
                    })
                });
                const data = await res.json();

                if (data.success) {
                    closeAddVehicleModal();
                    // Set the value in the input for current use
                    const vehicleInput = document.getElementById('joVehicleType');
                    const displayName = brand + ' ' + model;
                    if (vehicleInput) vehicleInput.value = displayName;
                    
                    showTxnAlert(
                        'Request submitted successfully! Request ID: #' + data.request_id + '. Status: Pending Manager Approval. You can use "' + displayName + '" now.',
                        'success'
                    );
                } else {
                    setAddVehicleError(data.error || 'Submission failed.');
                }
            } catch (err) {
                setAddVehicleError('Network error: ' + err.message);
            } finally {
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit for Approval'; }
            }
        }

        // Close modal on backdrop click
        document.addEventListener('click', function(e) {
            const modal = document.getElementById('addVehicleModal');
            if (modal && e.target === modal) closeAddVehicleModal();
        });

        // ── Add Product modal ────────────────────────────────────────────────
        function openAddProductModal() {
            const nameEl   = document.getElementById('newProductName');
            const catEl    = document.getElementById('newProductCategory');
            const skuEl    = document.getElementById('newProductSKU');
            const unitEl   = document.getElementById('newProductUnit');
            const priceEl  = document.getElementById('newProductPrice');
            const reasonEl = document.getElementById('newProductReason');
            if (nameEl)   nameEl.value   = '';
            if (catEl)    catEl.value    = '';
            if (skuEl)    skuEl.value    = '';
            if (unitEl)   unitEl.value   = '';
            if (priceEl)  priceEl.value  = '';
            if (reasonEl) reasonEl.value = '';
            setAddProductError('');
            const modal = document.getElementById('addProductModal');
            if (modal) { modal.style.display = 'flex'; }
            setTimeout(() => catEl && catEl.focus(), 80);
        }

        function closeAddProductModal() {
            const modal = document.getElementById('addProductModal');
            if (modal) modal.style.display = 'none';
        }

        function setAddProductError(msg) {
            const box  = document.getElementById('addProductError');
            const text = document.getElementById('addProductErrorText');
            if (!box || !text) return;
            text.textContent = msg;
            box.style.display = msg ? 'flex' : 'none';
        }

        async function submitNewProduct() {
            const name     = (document.getElementById('newProductName')?.value     || '').trim();
            const category = (document.getElementById('newProductCategory')?.value || '').trim();
            const sku      = (document.getElementById('newProductSKU')?.value      || '').trim().toUpperCase();
            const unit     = (document.getElementById('newProductUnit')?.value     || '').trim();
            const price    = parseFloat(document.getElementById('newProductPrice')?.value || 0);
            const reason   = (document.getElementById('newProductReason')?.value   || '').trim();
            const btn      = document.getElementById('addProductSubmitBtn');

            setAddProductError('');
            if (!category) { setAddProductError('Please enter or select a category.'); return; }
            if (!name)     { setAddProductError('Please enter the product name.'); return; }
            if (name.length > 150) { setAddProductError('Name is too long (max 150 characters).'); return; }
            if (!unit)     { setAddProductError('Please enter the unit (e.g. pcs, bottle, pack).'); return; }
            if (price <= 0) { setAddProductError('Please enter a valid price greater than zero.'); return; }
            if (!reason)   { setAddProductError('Please explain why you need this product added.'); return; }
            if (reason.length < 10) { setAddProductError('Please provide a more detailed reason (minimum 10 characters).'); return; }

            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting…'; }

            try {
                const res  = await fetch('../backend/api/submit_master_data_request.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        request_type: 'product',
                        request_data: {
                            product_name: name,
                            category: category,
                            sku: sku || null,
                            unit: unit,
                            unit_price: price,
                            reason: reason
                        }
                    })
                });
                const data = await res.json();

                if (data.success) {
                    closeAddProductModal();
                    showTxnAlert(
                        'Request submitted! Request ID: #' + data.request_id + '. Status: Pending Manager Approval.',
                        'success'
                    );
                } else {
                    setAddProductError(data.error || 'Submission failed.');
                }
            } catch (err) {
                setAddProductError('Network error: ' + err.message);
            } finally {
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit for Approval'; }
            }
        }

        // Close product modal on backdrop click
        document.addEventListener('click', function(e) {
            const modal = document.getElementById('addProductModal');
            if (modal && e.target === modal) closeAddProductModal();
        });

        // ── Try to recover selectedProduct from what's shown in the search box ──
        function tryResolveSelectedProduct() {
            if (selectedProduct) return true; // already set
            const searchVal = (document.getElementById('productSearch')?.value || '').trim();
            if (!searchVal) return false;
            const list = document.getElementById('productDropdownList');
            if (!list) return false;
            // Find the first visible prod-option whose name matches what's shown
            const opts = list.querySelectorAll('.prod-option');
            for (const opt of opts) {
                const label = opt.dataset.name + (opt.dataset.size ? ' · ' + opt.dataset.size : '');
                if (label.toLowerCase() === searchVal.toLowerCase() || opt.dataset.name.toLowerCase() === searchVal.toLowerCase()) {
                    selectProduct(opt);
                    return true;
                }
            }
            return false;
        }

        // ── Add merchandise to cart ───────────────────────────────────────────
        function addToCart() {
            tryResolveSelectedProduct();
            if (!selectedProduct) { showTxnAlert('Please select a product from the dropdown first.', 'warning'); return; }
            const qty = parseInt(document.getElementById('itemQty')?.value || 1);
            if (isNaN(qty) || qty < 1) { showTxnAlert('Quantity must be at least 1.', 'warning'); return; }

            const stock = parseInt(selectedProduct.stock) || 0;
            let rawUnit = (selectedProduct.unit || 'pc').toLowerCase();
            let uomLabel = 'pcs';
            if (rawUnit.includes('bottle')) uomLabel = 'Bottles';
            else if (rawUnit.includes('liter') || rawUnit.includes('litre')) uomLabel = 'Liters';
            else if (rawUnit.includes('can')) uomLabel = 'Cans';
            else if (rawUnit.includes('box')) uomLabel = 'Boxes';
            else if (rawUnit.includes('set')) uomLabel = 'Sets';
            else if (rawUnit.includes('pack')) uomLabel = 'Packs';
            else if (rawUnit.includes('pair')) uomLabel = 'Pairs';
            else if (rawUnit.includes('pc') || rawUnit.includes('piece')) uomLabel = 'pcs';
            else uomLabel = selectedProduct.unit || 'pcs';

            if (stock <= 0) { 
                showTxnAlert(`❌ Insufficient stock.\nAvailable: 0 ${uomLabel}`, 'warning'); 
                return; 
            }
            if (qty > stock) {
                showTxnAlert(`❌ Insufficient stock.\nAvailable: ${stock} ${uomLabel}`, 'warning'); 
                return;
            }

            const pid = String(selectedProduct.id);
            const existing = cart.find(i => i.item_type === 'merchandise' && String(i.product_id) === pid);
            if (existing) {
                const newQty = existing.quantity + qty;
                if (newQty > stock) {
                    showTxnAlert(`❌ Insufficient stock.\nAvailable: ${stock} ${uomLabel} (${existing.quantity} already in cart)`, 'warning'); 
                    return;
                }
                existing.quantity = newQty;
            } else {
                cart.push({
                    item_type:    'merchandise',
                    product_id:   selectedProduct.id,
                    product_name: selectedProduct.name,
                    category:     selectedProduct.cat,
                    size_variant: selectedProduct.size,
                    quantity:     qty,
                    unit_price:   selectedProduct.price,
                    unit:         selectedProduct.unit || 'Piece (pc)',
                });
            }

            renderCart();
            updateCheckoutBtn();
            resetMerchandiseForm();
            showTxnAlert(`✔ Item successfully added to cart.`, 'success');
        }

        // ── Quick Add selected product directly from dropdown list option ───
        function quickAddProductToCart(productId) {
            const opt = document.querySelector(`#productSelect option[value="${productId}"]`);
            if (!opt) return;

            const name = opt.getAttribute('data-name');
            const sku = opt.getAttribute('data-sku');
            const cat = opt.getAttribute('data-cat');
            const size = opt.getAttribute('data-size');
            const price = parseFloat(opt.getAttribute('data-price') || 0);
            const stock = parseInt(opt.getAttribute('data-stock') || 0);
            const unit = opt.getAttribute('data-unit') || 'Piece (pc)';

            if (stock <= 0) {
                showTxnAlert('This product is out of stock.', 'warning');
                return;
            }

            const pid = String(productId);
            const existing = cart.find(i => i.item_type === 'merchandise' && String(i.product_id) === pid);
            if (existing) {
                const newQty = existing.quantity + 1;
                if (newQty > stock) {
                    showTxnAlert(`Cannot add more — only ${stock} unit(s) available in stock.`, 'warning');
                    return;
                }
                existing.quantity = newQty;
            } else {
                cart.push({
                    item_type:    'merchandise',
                    product_id:   productId,
                    product_name: name,
                    category:     cat,
                    size_variant: size || '',
                    quantity:     1,
                    unit_price:   price,
                    unit:         unit,
                    stock_level:  stock
                });
            }

            renderCart();
            updateCheckoutBtn();
            showTxnAlert(`"${name}" added to cart!`, 'success');
        }

        // ── Add currently selected product in merchandise form to cart ──────
        function addProductFromFormToCart() {
            tryResolveSelectedProduct();
            if (!selectedProduct) {
                showTxnAlert('Please select a product from the dropdown list first.', 'warning');
                document.getElementById('productSearch')?.focus();
                openProductDropdown();
                return;
            }
            addToCart();
        }

        // ── Add currently configured Job Order service to cart ───────────────
        async function addServiceFromFormToCart() {
            const svcType      = (document.getElementById('joServiceTypeValue')?.value || '').trim();
            const svcPrice     = parseFloat(document.getElementById('joServicePrice')?.value || 0);
            const vehiclePlate = (document.getElementById('joVehiclePlate')?.value || '').trim();
            const vehicleType  = (document.getElementById('joVehicleType')?.value || '').trim();
            const mechanicId   = (document.getElementById('joMechanicId')?.value || '').trim();

            if (!svcType) {
                showTxnAlert('Please select a service type first.', 'warning');
                return;
            }
            if (svcPrice <= 0 || isNaN(svcPrice)) {
                showTxnAlert('Please enter a service fee greater than ₱0.', 'warning');
                return;
            }
            if (!vehiclePlate) {
                showTxnAlert('Please enter the vehicle plate number first.', 'warning');
                const plateInput = document.getElementById('joVehiclePlate');
                if (plateInput) plateInput.focus();
                return;
            }
            if (!mechanicId) {
                showTxnAlert('Please select a valid assigned mechanic from the dropdown list.', 'warning');
                const mechInput = document.getElementById('joMechanic');
                if (mechInput) mechInput.focus();
                return;
            }

            // Run standard Job Order cart addition logic (includes auto-adding mapped parts)
            await applyJobOrderToCart();
        }

        // ── Service Category filter — narrows service type list to selected category ──
        function onServiceCategoryChange() {
            filterServiceTypes();
            const dropdown = document.getElementById('joServiceTypeDropdown');
            if (dropdown) dropdown.style.display = 'block';
        }

        // ── Combined: add service (from Job Order) + merchandise to cart ─────
        // Called by the Merchandise form's "Add to Cart" button.
        // - If a service type is selected in the Job Order form, adds it to cart.
        // - If a product is selected in the Merchandise form, adds it to cart.
        // - At least one of the two must be present.
        async function addToCartWithService() {
            const svcType  = (document.getElementById('joServiceTypeValue')?.value || '').trim();
            const svcPrice = parseFloat(document.getElementById('joServicePrice')?.value || 0);
            const hasProduct = !!selectedProduct;

            // Nothing selected at all
            if (!svcType && !hasProduct) {
                showTxnAlert('Please select a product or fill in a service type first.', 'warning');
                return;
            }

            // If service type is set, validate fee before doing anything
            if (svcType && svcPrice <= 0) {
                showTxnAlert('Please enter a service fee greater than ₱0.', 'warning');
                return;
            }

            // Add service to cart (includes auto-fetched parts)
            if (svcType) {
                const mechanicId = (document.getElementById('joMechanicId')?.value || '').trim();
                if (!mechanicId) {
                    showTxnAlert('Please select a valid assigned mechanic from the dropdown list.', 'warning');
                    const mechInput = document.getElementById('joMechanic');
                    if (mechInput) mechInput.focus();
                    return;
                }
                await applyJobOrderToCart();
            }

            // Add merchandise product if selected
            if (hasProduct) {
                addToCart();
            }
        }

        // ── Reset merchandise form fields only (product section — keeps customer info) ──
        function resetMerchandiseForm() {
            selectedProduct = null;
            const search = document.getElementById('productSearch');
            const qty    = document.getElementById('itemQty');
            const cat    = document.getElementById('itemCategory');
            const price  = document.getElementById('itemUnitPrice');
            const stock  = document.getElementById('itemStock');
            if (search) search.value = '';
            if (qty)    qty.value    = 1;
            if (cat)    cat.value    = '';
            if (price)  price.value  = '';
            if (stock)  stock.value  = '';
            closeProductDropdown();
            // NOTE: Customer fields are intentionally NOT cleared here
            // so they persist across multiple cart additions.
        }

        // ── Full reset: product fields + customer fields (called by Reset Form button) ──
        function fullResetMerchandiseForm() {
            resetMerchandiseForm(); // clears product fields
            clearSelectedCustomerFull('merch');
            // Hide customer results dropdown if open
            const firstNameResults = document.getElementById('merchFirstNameResults');
            if (firstNameResults) firstNameResults.style.display = 'none';
            // Reset Loyalty
            const loyaltyProgram = document.getElementById('loyaltyProgram');
            if (loyaltyProgram) {
                loyaltyProgram.value = 'No Loyalty';
                onLoyaltyChange();
            }
        }

        // ── Reset Job Order form fields only ─────────────────────────────────
        function resetJobOrderForm() {
            selectedCustomerIds.jo = null;

            // Clear customer details
            ['joFirstName','joLastName','joContactNumber'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.value = '';
            });
            const fnResults = document.getElementById('joFirstNameResults');
            if (fnResults) fnResults.style.display = 'none';

            // Clear vehicle details
            ['joVehicleType','joVehicleBrand','joVehicleModel','joVehiclePlate','joYearModel','joOdometer','joEngineNumber','joChassisNumber'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.value = '';
            });

            // Clear JO Information
            const joDate = document.getElementById('joDate');
            if (joDate) joDate.value = new Date().toISOString().split('T')[0];
            const joExpectedRelease = document.getElementById('joExpectedRelease');
            if (joExpectedRelease) joExpectedRelease.value = '';
            // Reset priority to Normal
            const priNormal = document.getElementById('joPriorityNormal');
            if (priNormal) priNormal.checked = true;

            // Clear Vehicle Inspection
            document.querySelectorAll('input[name="jo_inspection[]"]').forEach(cb => cb.checked = false);
            const inspRemarks = document.getElementById('joInspectionRemarks');
            if (inspRemarks) inspRemarks.value = '';

            // Clear Complaint & Recommendation
            const joComplaint = document.getElementById('joCustomerComplaint');
            if (joComplaint) joComplaint.value = '';
            const joRec = document.getElementById('joRepairRecommendation');
            if (joRec) joRec.value = '';

            // Clear service details
            const joServiceCategory = document.getElementById('joServiceCategory');
            const joServiceType = document.getElementById('joServiceType');
            const joServiceTypeValue = document.getElementById('joServiceTypeValue');
            const joServicePrice = document.getElementById('joServicePrice');
            const joNotes = document.getElementById('joNotes');
            const joEstimatedDuration = document.getElementById('joEstimatedDuration');
            if (joServiceCategory) joServiceCategory.selectedIndex = 0;
            if (joServiceType) joServiceType.value = '';
            if (joServiceTypeValue) joServiceTypeValue.value = '';
            if (joServicePrice) joServicePrice.value = '';
            const joLaborCharge = document.getElementById('joLaborCharge');
            if (joLaborCharge) joLaborCharge.value = '';
            if (joNotes) joNotes.value = '';
            if (joEstimatedDuration) joEstimatedDuration.value = '';

            // Reset mechanic
            const joMechanic = document.getElementById('joMechanic');
            if (joMechanic) joMechanic.value = '';
            const joMechanicId = document.getElementById('joMechanicId');
            if (joMechanicId) joMechanicId.value = '';
            const joMechanicName = document.getElementById('joMechanicName');
            if (joMechanicName) joMechanicName.value = '';
            hideMechanicDropdown();

            // Clear payment and loyalty fields
            const pmSel = document.getElementById('paymentMethod');
            if (pmSel) pmSel.selectedIndex = 0;
            if (typeof onPaymentChange === 'function') onPaymentChange();
            ['amountTendered', 'changeAmount', 'cashBalanceDue', 'ccAmount', 'ccLastFour', 'ccRefNumber', 'dcAmount', 'dcRefNumber', 'ewAmount', 'ewRefNumber', 'fcAmount', 'fcNumber', 'fcCompanyName', 'fcAuthNumber'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.value = '';
            });
            const loyaltyProgram = document.getElementById('loyaltyProgram');
            if (loyaltyProgram) {
                loyaltyProgram.value = 'No Loyalty';
                if (typeof onLoyaltyChange === 'function') onLoyaltyChange();
            }
            ['loyaltyCardNo', 'loyaltyPointsBalance', 'loyaltyPointsEarned', 'loyaltyPointsAfter'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.value = (id === 'loyaltyCardNo') ? '' : '0';
            });

            // Hide previews/warnings
            const notesWrap = document.getElementById('joServicePriceNotes');
            const partsWrap = document.getElementById('joSuggestedParts');
            if (notesWrap) notesWrap.style.display = 'none';
            if (partsWrap) partsWrap.style.display = 'none';
            const mechanicBusyWarn = document.getElementById('joMechanicBusyWarn');
            if (mechanicBusyWarn) mechanicBusyWarn.style.display = 'none';

            window._joSuggestedParts = [];
            hideServiceDropdown();
            hideVehicleDropdown();
            const customerResults = document.getElementById('joCustomerResults');
            if (customerResults) customerResults.style.display = 'none';

            // Reset parts & merchandise list
            joResetParts();
            const merchList = document.getElementById('joMerchandiseList');
            if (merchList) merchList.innerHTML = '';
            const merchHidden = document.getElementById('joMerchandiseData');
            if (merchHidden) merchHidden.value = '[]';
        }

        // ── Save Job Order as Draft ──────────────────────────────────────────
        function saveJobOrderDraft() {
            const firstName = document.getElementById('joFirstName')?.value?.trim();
            const plate     = document.getElementById('joVehiclePlate')?.value?.trim();
            if (!firstName) {
                showTxnAlert('Please enter the customer\'s first name before saving draft.', 'warning');
                return;
            }
            // Store draft in sessionStorage for persistence
            const draftData = collectJobOrderData();
            draftData.status = 'Draft';
            try {
                sessionStorage.setItem('jo_draft', JSON.stringify(draftData));
                showTxnAlert('Job Order draft saved! You can continue editing before submitting.', 'success');
            } catch (e) {
                showTxnAlert('Could not save draft: ' + e.message, 'warning');
            }
        }

        // ── Submit Job Order ─────────────────────────────────────────────────
        async function submitJobOrder() {
            let firstName = (document.getElementById('joFirstName')?.value || '').trim();
            let plate     = (document.getElementById('joVehiclePlate')?.value || '').trim();
            let engineNo  = (document.getElementById('joEngineNumber')?.value || '').trim();
            let chassisNo = (document.getElementById('joChassisNumber')?.value || '').trim();

            // 100% Flexible Walk-In Defaults: Never block staff if walk-in fields are left blank
            if (!firstName) {
                firstName = 'Walk-In';
                const fnEl = document.getElementById('joFirstName');
                if (fnEl) fnEl.value = 'Walk-In';
            }
            if (!plate) {
                plate = 'N/A';
                const plEl = document.getElementById('joVehiclePlate');
                if (plEl) plEl.value = 'N/A';
            }
            if (!engineNo) {
                engineNo = 'N/A';
                const engEl = document.getElementById('joEngineNumber');
                if (engEl) engEl.value = 'N/A';
            }
            if (!chassisNo) {
                chassisNo = 'N/A';
                const chEl = document.getElementById('joChassisNumber');
                if (chEl) chEl.value = 'N/A';
            }
            const serviceType = (document.getElementById('joServiceTypeValue')?.value || '').trim();
            if (!serviceType) {
                showTxnAlert('Please select a service type before submitting the Job Order.', 'warning');
                return;
            }

            // ── Check payment method before anything else ─────────────────────
            const method = (document.getElementById('paymentMethod')?.value || '').trim();
            if (!method) {
                showTxnAlert('Please select a Payment Method before submitting the Job Order.', 'warning');
                // Scroll payment method into view
                const pmEl = document.getElementById('paymentMethod');
                if (pmEl) pmEl.focus();
                return;
            }

            // ── Ensure service is in cart ─────────────────────────────────────
            const alreadyInCart = cart.some(i => i.item_type === 'service');
            if (!alreadyInCart) {
                await addServiceFromFormToCart();
            }

            // ── Directly finalize & submit (no separate checkout click needed) ─
            await submitMerchTxn();
        }

        var _secCheckDebounce = null;
        function checkVehicleSecurityWarning() {
            clearTimeout(_secCheckDebounce);
            _secCheckDebounce = setTimeout(function() {
                const plate   = (document.getElementById('joVehiclePlate')?.value || '').trim();
                const engine  = (document.getElementById('joEngineNumber')?.value || '').trim();
                const chassis = (document.getElementById('joChassisNumber')?.value || '').trim();
                const warnBox = document.getElementById('joVehicleSecurityWarningBox');
                const warnTxt = document.getElementById('joVehicleSecurityWarningText');

                if (!engine && !chassis) {
                    if (warnBox) warnBox.style.display = 'none';
                    return;
                }

                fetch(`../backend/api/check_vehicle_security.php?plate_number=${encodeURIComponent(plate)}&engine_number=${encodeURIComponent(engine)}&chassis_number=${encodeURIComponent(chassis)}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.warnings && data.warnings.length > 0) {
                        if (warnTxt) warnTxt.innerHTML = data.warnings.join('<br>');
                        if (warnBox) warnBox.style.display = 'block';
                    } else {
                        if (warnBox) warnBox.style.display = 'none';
                    }
                })
                .catch(e => {});
            }, 300);
        }

        // ── Collect Job Order form data as object ────────────────────────────
        function collectJobOrderData() {
            const inspected = [];
            document.querySelectorAll('input[name="jo_inspection[]"]:checked').forEach(cb => inspected.push(cb.value));
            let merchData = [];
            try {
                merchData = JSON.parse(document.getElementById('joMerchandiseData')?.value || '[]');
            } catch (e) { merchData = []; }
            
            return {
                customer_first_name:   document.getElementById('joFirstName')?.value?.trim() || '',
                customer_last_name:    document.getElementById('joLastName')?.value?.trim() || '',
                contact_number:        document.getElementById('joContactNumber')?.value?.trim() || '',
                vehicle_type:          document.getElementById('joVehicleType')?.value?.trim() || '',
                plate_number:          document.getElementById('joVehiclePlate')?.value?.trim() || '',
                engine_number:         document.getElementById('joEngineNumber')?.value?.trim() || '',
                chassis_number:        document.getElementById('joChassisNumber')?.value?.trim() || '',
                vehicle_brand:         document.getElementById('joVehicleBrand')?.value?.trim() || '',
                vehicle_model:         document.getElementById('joVehicleModel')?.value?.trim() || '',
                year_model:            document.getElementById('joYearModel')?.value || '',
                odometer:              document.getElementById('joOdometer')?.value?.trim() || '',

                jo_date:               document.getElementById('joDate')?.value || '',
                priority:              document.querySelector('input[name="joPriority"]:checked')?.value || 'Normal',
                expected_release:      document.getElementById('joExpectedRelease')?.value || '',
                inspection_items:      inspected,
                inspection_remarks:    document.getElementById('joInspectionRemarks')?.value?.trim() || '',
                customer_complaint:    document.getElementById('joCustomerComplaint')?.value?.trim() || '',
                repair_recommendation: document.getElementById('joRepairRecommendation')?.value?.trim() || '',
                service_category:      document.getElementById('joServiceCategory')?.value || '',
                service_type:          document.getElementById('joServiceType')?.value?.trim() || '',
                service_fee:           document.getElementById('joServicePrice')?.value || '',
                mechanic_id:           document.getElementById('joMechanicId')?.value || '',
                mechanic_name:         document.getElementById('joMechanicName')?.value || '',
                estimated_duration:    document.getElementById('joEstimatedDuration')?.value || '',
                notes:                 document.getElementById('joNotes')?.value?.trim() || '',
                parts:                 window._joPartsList || [],
                merchandise_used:      merchData,
                status:                'Pending',
            };
        }

        // ── Merchandise Used (Optional) in Job Order Form ───────────────────
        window.merchandiseProducts = <?php echo json_encode($merch_products ?? []); ?>;

        function addMerchandiseRow(productName = '', qty = 1, price = 0) {
            const list = document.getElementById('joMerchandiseList');
            if (!list) return;

            const rowId = 'merchRow_' + Date.now() + '_' + Math.random().toString(36).substr(2, 4);
            const row = document.createElement('div');
            row.id = rowId;
            row.className = 'jo-merch-row';
            row.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:8px;background:#f8fafc;padding:8px;border-radius:8px;border:1px solid #e2e8f0;';

            let optionsHtml = '<option value="">-- Select Product --</option>';
            (window.merchandiseProducts || []).forEach(p => {
                const pName = p.product_name || p.name || '';
                const pPrice = parseFloat(p.unit_price || p.price || 0);
                const selected = (pName === productName) ? 'selected' : '';
                optionsHtml += `<option value="${escapeHtml(pName)}" data-price="${pPrice}" ${selected}>${escapeHtml(pName)} (${pPrice > 0 ? '₱'+pPrice.toFixed(2) : 'No price'})</option>`;
            });

            row.innerHTML = `
                <div style="flex:2;">
                    <select class="txn-select merch-prod-select" onchange="onMerchandiseRowChange('${rowId}')" style="width:100%;font-size:12px;padding:6px 8px;">
                        ${optionsHtml}
                    </select>
                </div>
                <div style="flex:1;max-width:80px;">
                    <input type="number" class="txn-input merch-prod-qty" min="1" value="${qty}" oninput="onMerchandiseRowChange('${rowId}')" placeholder="Qty" style="font-size:12px;padding:6px 8px;text-align:center;">
                </div>
                <div style="flex:1;max-width:100px;">
                    <input type="number" step="0.01" min="0" class="txn-input merch-prod-price" value="${price > 0 ? price : ''}" oninput="onMerchandiseRowChange('${rowId}')" placeholder="Price" style="font-size:12px;padding:6px 8px;text-align:right;">
                </div>
                <button type="button" onclick="removeMerchandiseRow('${rowId}')" style="background:#fee2e2;color:#dc2626;border:none;border-radius:6px;width:30px;height:30px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;" title="Remove product">
                    <i class="fas fa-trash-alt" style="font-size:12px;"></i>
                </button>
            `;

            list.appendChild(row);
            updateMerchandiseData();
        }

        function onMerchandiseRowChange(rowId) {
            const row = document.getElementById(rowId);
            if (!row) return;
            const select = row.querySelector('.merch-prod-select');
            const priceInput = row.querySelector('.merch-prod-price');
            if (select && priceInput && select.selectedIndex > 0) {
                const opt = select.options[select.selectedIndex];
                const defaultPrice = parseFloat(opt.getAttribute('data-price') || 0);
                if (!priceInput.value && defaultPrice > 0) {
                    priceInput.value = defaultPrice.toFixed(2);
                }
            }
            updateMerchandiseData();
        }

        function removeMerchandiseRow(rowId) {
            const row = document.getElementById(rowId);
            if (row) {
                row.remove();
                updateMerchandiseData();
            }
        }

        function updateMerchandiseData() {
            const list = document.getElementById('joMerchandiseList');
            const hidden = document.getElementById('joMerchandiseData');
            if (!list || !hidden) return;

            const items = [];
            list.querySelectorAll('.jo-merch-row').forEach(row => {
                const select = row.querySelector('.merch-prod-select');
                const qtyInput = row.querySelector('.merch-prod-qty');
                const priceInput = row.querySelector('.merch-prod-price');

                const productName = select ? select.value.trim() : '';
                const qty = qtyInput ? (parseInt(qtyInput.value, 10) || 1) : 1;
                const price = priceInput ? (parseFloat(priceInput.value) || 0) : 0;

                if (productName) {
                    items.push({ product_name: productName, qty: qty, price: price });
                }
            });

            hidden.value = JSON.stringify(items);
        }

        // ── Step 8: Parts / Merchandise Used ────────────────────────────────
        window._joPartsList = [];  // [{id, name, qty, price}]

        function joPartSearchInput(q) {
            const dropdown = document.getElementById('joPartDropdown');
            if (!dropdown) return;
            q = (q || '').toLowerCase().trim();
            const products = window.allMerchData || [];
            const filtered = q.length === 0
                ? products.slice(0, 40)
                : products.filter(p =>
                    (p.name || '').toLowerCase().includes(q) ||
                    (p.sku  || '').toLowerCase().includes(q) ||
                    (p.category || '').toLowerCase().includes(q)
                  ).slice(0, 40);

            if (filtered.length === 0) {
                dropdown.innerHTML = '<div style="padding:10px 14px;color:#94a3b8;font-size:12px;">No products found.</div>';
            } else {
                dropdown.innerHTML = filtered.map(p => {
                    const stock = Number(p.stock || 0);
                    const price = parseFloat(p.price || p.unit_price || 0);
                    return `<div class="jo-part-item"
                        data-id="${p.id}" data-name="${(p.name||'').replace(/"/g,'&quot;')}"
                        data-price="${price}" data-stock="${stock}"
                        onclick="joSelectPart(this)"
                        style="padding:9px 14px;cursor:pointer;border-bottom:1px solid #f1f5f9;
                               display:flex;justify-content:space-between;align-items:center;
                               font-size:13px;color:#1e293b;transition:background .12s;"
                        onmouseover="this.style.background='#fffbeb'"
                        onmouseout="this.style.background=''"
                    >
                        <div>
                            <div style="font-weight:600;">${escHtml(p.name)}</div>
                            <div style="font-size:10px;color:#94a3b8;">${escHtml(p.sku||'')} · ${escHtml(p.category||'')} · Stock: ${stock} ${escHtml(p.unit||'pc')}</div>
                        </div>
                        <div style="font-weight:700;color:#b45309;font-size:13px;">₱${price.toFixed(2)}</div>
                    </div>`;
                }).join('');
            }
            dropdown.style.display = 'block';
            // Close on outside click
            document.addEventListener('click', joClosePartDropdown);
        }

        function joClosePartDropdown(e) {
            const dd = document.getElementById('joPartDropdown');
            const inp = document.getElementById('joPartSearch');
            if (dd && inp && !dd.contains(e.target) && e.target !== inp) {
                dd.style.display = 'none';
                document.removeEventListener('click', joClosePartDropdown);
            }
        }

        function escHtml(s) {
            return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        function joSelectPart(el) {
            document.getElementById('joPartId').value    = el.dataset.id;
            document.getElementById('joPartName').value  = el.dataset.name;
            document.getElementById('joPartSearch').value = el.dataset.name;
            document.getElementById('joPartPrice').value  = parseFloat(el.dataset.price || 0).toFixed(2);
            document.getElementById('joPartQty').value    = 1;
            const dd = document.getElementById('joPartDropdown');
            if (dd) dd.style.display = 'none';
        }

        function joAddPart() {
            const id    = document.getElementById('joPartId')?.value;
            const name  = document.getElementById('joPartName')?.value?.trim()
                       || document.getElementById('joPartSearch')?.value?.trim();
            const qty   = parseInt(document.getElementById('joPartQty')?.value || 1);
            const price = parseFloat(document.getElementById('joPartPrice')?.value || 0);

            if (!name) {
                showTxnAlert('Please search and select a product first.', 'warning');
                return;
            }
            if (qty < 1) {
                showTxnAlert('Quantity must be at least 1.', 'warning');
                return;
            }

            // Check if already in list — add qty instead
            const existing = window._joPartsList.find(p => p.id == id && id);
            if (existing) {
                existing.qty += qty;
            } else {
                window._joPartsList.push({ id: id || '', name, qty, price });
            }

            // Clear inputs
            document.getElementById('joPartSearch').value = '';
            document.getElementById('joPartId').value     = '';
            document.getElementById('joPartName').value   = '';
            document.getElementById('joPartPrice').value  = '';
            document.getElementById('joPartQty').value    = 1;

            joUpdatePartsTable();
        }

        function joRemovePart(idx) {
            window._joPartsList.splice(idx, 1);
            joUpdatePartsTable();
        }

        function joUpdatePartsTable() {
            const tbody   = document.getElementById('joPartsTableBody');
            const wrap    = document.getElementById('joPartsTableWrap');
            const empty   = document.getElementById('joPartsEmptyNote');
            const total   = document.getElementById('joPartsTotalCell');
            const parts   = window._joPartsList;

            if (!parts.length) {
                if (wrap)  wrap.style.display  = 'none';
                if (empty) empty.style.display = 'block';
                if (total) total.textContent   = '₱0.00';
                return;
            }
            if (wrap)  wrap.style.display  = 'block';
            if (empty) empty.style.display = 'none';

            let grandTotal = 0;
            tbody.innerHTML = parts.map((p, i) => {
                const lineTotal = p.qty * p.price;
                grandTotal += lineTotal;
                return `<tr style="border-bottom:1px solid #fde68a;background:${i%2===0?'#fff':'#fffbeb'};">
                    <td style="padding:7px 10px;font-weight:600;color:#1e293b;">${escHtml(p.name)}</td>
                    <td style="padding:7px 10px;text-align:center;font-weight:700;color:#002F70;">${p.qty}</td>
                    <td style="padding:7px 10px;text-align:right;color:#64748b;">₱${parseFloat(p.price).toFixed(2)}</td>
                    <td style="padding:7px 10px;text-align:right;font-weight:700;color:#b45309;">₱${lineTotal.toFixed(2)}</td>
                    <td style="padding:7px 10px;text-align:center;">
                        <button type="button" onclick="joRemovePart(${i})"
                                style="background:none;border:none;color:#dc2626;cursor:pointer;font-size:14px;padding:2px 5px;"
                                title="Remove">
                            <i class="fas fa-times"></i>
                        </button>
                    </td>
                </tr>`;
            }).join('');
            if (total) total.textContent = '₱' + grandTotal.toFixed(2);
        }

        function joResetParts() {
            window._joPartsList = [];
            joUpdatePartsTable();
            const ps = document.getElementById('joPartSearch');
            if (ps) ps.value = '';
            const pid = document.getElementById('joPartId');
            if (pid) pid.value = '';
            const pn = document.getElementById('joPartName');
            if (pn) pn.value = '';
            const pp = document.getElementById('joPartPrice');
            if (pp) pp.value = '';
            const pq = document.getElementById('joPartQty');
            if (pq) pq.value = 1;
        }

        // ── Clear all payment inputs ─────────────────────────────────────────
        function clearPaymentInputs() {
            // Cash
            const amountTendered = document.getElementById('amountTendered');
            if (amountTendered) amountTendered.value = '';
            const changeAmount = document.getElementById('changeAmount');
            if (changeAmount) changeAmount.value = '';
            const cashBalanceDue = document.getElementById('cashBalanceDue');
            if (cashBalanceDue) cashBalanceDue.value = '';

            // Credit Card
            const ccAmount = document.getElementById('ccAmount');
            if (ccAmount) ccAmount.value = '';
            const ccLastFour = document.getElementById('ccLastFour');
            if (ccLastFour) ccLastFour.value = '';
            const ccRefNumber = document.getElementById('ccRefNumber');
            if (ccRefNumber) ccRefNumber.value = '';

            // Debit Card
            const dcAmount = document.getElementById('dcAmount');
            if (dcAmount) dcAmount.value = '';
            const dcRefNumber = document.getElementById('dcRefNumber');
            if (dcRefNumber) dcRefNumber.value = '';

            // E-Wallet
            const ewAmount = document.getElementById('ewAmount');
            if (ewAmount) ewAmount.value = '';
            const ewRefNumber = document.getElementById('ewRefNumber');
            if (ewRefNumber) ewRefNumber.value = '';

            // Fleet Card
            const fcAmount = document.getElementById('fcAmount');
            if (fcAmount) fcAmount.value = '';
            const fcNumber = document.getElementById('fcNumber');
            if (fcNumber) fcNumber.value = '';
            const fcCompanyName = document.getElementById('fcCompanyName');
            if (fcCompanyName) fcCompanyName.value = '';
            const fcAuthNumber = document.getElementById('fcAuthNumber');
            if (fcAuthNumber) fcAuthNumber.value = '';

            // E-Fuel Card
            const efAmount = document.getElementById('efAmount');
            if (efAmount) efAmount.value = '';
            const efCardNumber = document.getElementById('efCardNumber');
            if (efCardNumber) efCardNumber.value = '';
            const efRefNumber = document.getElementById('efRefNumber');
            if (efRefNumber) efRefNumber.value = '';

            // Credit Account
            const creditCustomer = document.getElementById('creditCustomer');
            if (creditCustomer) creditCustomer.selectedIndex = 0;
            const creditCompanyName = document.getElementById('creditCompanyName');
            if (creditCompanyName) creditCompanyName.value = '';
            const creditAccountNumber = document.getElementById('creditAccountNumber');
            if (creditAccountNumber) creditAccountNumber.value = '';
            const creditPoNumber = document.getElementById('creditPoNumber');
            if (creditPoNumber) creditPoNumber.value = '';
            const creditDueDate = document.getElementById('creditDueDate');
            if (creditDueDate) creditDueDate.value = '';

            const generalBalanceDue = document.getElementById('generalBalanceDue');
            if (generalBalanceDue) generalBalanceDue.value = '';
        }

        // ── Reset ALL — clears Job Order + Merchandise forms + cart ──────────
        function resetAll() {
            if (!confirm('Reset all fields and clear the cart?')) return;

            // ── Job Order fields ──────────────────────────────────────────────
            const joFields = ['joFirstName','joLastName','joContactNumber',
                              'joVehiclePlate','joServicePrice','joNotes'];
            joFields.forEach(id => {
                const el = document.getElementById(id);
                if (el) el.value = '';
            });
            // Reset service type input and hidden value
            const joServiceInput = document.getElementById('joServiceType');
            const joServiceHidden = document.getElementById('joServiceTypeValue');
            if (joServiceInput) joServiceInput.value = '';
            if (joServiceHidden) joServiceHidden.value = '';
            // Reset vehicle type and mechanic typeahead
            const vehicleInput = document.getElementById('joVehicleType');
            if (vehicleInput) vehicleInput.value = '';
            const mechInput = document.getElementById('joMechanic');
            if (mechInput) mechInput.value = '';
            const mechIdHid = document.getElementById('joMechanicId');
            if (mechIdHid) mechIdHid.value = '';
            const mechNameHid = document.getElementById('joMechanicName');
            if (mechNameHid) mechNameHid.value = '';
            hideMechanicDropdown();
            // Hide pricing notes and suggested parts
            const notesWrap = document.getElementById('joServicePriceNotes');
            const partsWrap = document.getElementById('joSuggestedParts');
            if (notesWrap) notesWrap.style.display = 'none';
            if (partsWrap) partsWrap.style.display = 'none';
            window._joSuggestedParts = [];

            // ── Merchandise fields ────────────────────────────────────────────
            resetMerchandiseForm();
            const mFields = ['merchFirstName','merchLastName'];
            mFields.forEach(id => {
                const el = document.getElementById(id);
                if (el) el.value = '';
            });

            // ── Cart ──────────────────────────────────────────────────────────
            cart = [];
            renderCart();
            updateCheckoutBtn();

            // ── Payment panel ─────────────────────────────────────────────────
            const payMethod = document.getElementById('paymentMethod');
            if (payMethod) payMethod.selectedIndex = 0;
            clearPaymentInputs();
            onPaymentChange();
        }

        // ── Clear cart ────────────────────────────────────────────────────────
        function clearCart() {
            if (cart.length === 0) return;
            if (!confirm('Clear all items from the cart?')) return;
            cart = [];
            if (typeof updateServiceSelectionState === 'function') updateServiceSelectionState();
            renderCart();
            updateCheckoutBtn();
            syncProductCheckboxes();
        }

        // ── Render cart ───────────────────────────────────────────────────────
        function renderCart() {
            const body  = document.getElementById('cartBody');
            const empty = document.getElementById('cartEmpty');
            if (!body) return;

            if (cart.length === 0) {
                body.innerHTML = `<div class="cart-empty" id="cartEmpty">
                    <i class="fas fa-shopping-cart"></i>
                    Cart is empty.<br>Add service or items from the left.
                </div>`;
                updateTotals(0, 0, 0);
                return;
            }

            body.innerHTML = cart.map((item, idx) => {
                const subtotal = item.quantity * item.unit_price;
                const icon = item.item_type === 'service' ? 'fa-wrench' : 'fa-box';
                const color = item.item_type === 'service' ? '#b45309' : '#28a745';
                return `<div class="cart-item-row" style="display:flex;align-items:flex-start;gap:8px;padding:8px 10px;border-bottom:1px solid #f1f5f9;">
                    <i class="fas ${icon}" style="color:${color};margin-top:3px;font-size:11px;flex-shrink:0;"></i>
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:12px;font-weight:600;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escHtml(item.product_name)}</div>
                        <div style="font-size:10px;color:#64748b;">₱${fmtNum(item.unit_price)} × ${item.quantity}</div>
                    </div>
                    <div style="display:flex;align-items:center;gap:4px;flex-shrink:0;">
                        <button type="button" onclick="cartQty(${idx},-1)" style="width:22px;height:22px;border:1px solid #e2e8f0;background:#f8fafc;border-radius:4px;cursor:pointer;font-size:13px;line-height:1;padding:0;">−</button>
                        <span style="font-size:12px;font-weight:700;text-align:center;">${item.quantity}</span>
                        <button type="button" onclick="cartQty(${idx},+1)" style="width:22px;height:22px;border:1px solid #e2e8f0;background:#f8fafc;border-radius:4px;cursor:pointer;font-size:13px;line-height:1;padding:0;">+</button>
                    </div>
                    <div style="font-size:12px;font-weight:700;color:var(--petron-blue);text-align:right;flex-shrink:0;">₱${fmtNum(subtotal)}</div>
                    <button type="button" onclick="cartRemove(${idx})" style="background:none;border:none;color:#ef4444;cursor:pointer;font-size:13px;padding:0 2px;flex-shrink:0;" title="Remove">×</button>
                </div>`;
            }).join('');

            const subtotal = cart.reduce((s, i) => s + i.quantity * i.unit_price, 0);
            const vat      = subtotal * 0.12;
            const grand    = subtotal + vat;
            updateTotals(subtotal, vat, grand);
            // Keep product checkboxes in sync with cart
            if (typeof syncProductCheckboxes === 'function') syncProductCheckboxes();
            if (typeof syncServiceCheckboxes === 'function') syncServiceCheckboxes();

            window.cart = cart;
            if (window.PetronDraft) window.PetronDraft.flushAll();
        }

        function cartQty(idx, delta) {
            const item = cart[idx];
            if (!item) return;
            const newQty = item.quantity + delta;
            if (newQty < 1) { cartRemove(idx); return; }
            if (item.item_type === 'merchandise') {
                // Look up stock from the hidden select; service items have no stock limit
                const opt = document.querySelector(`#productSelect option[value="${item.product_id}"]`);
                const stock = opt ? parseInt(opt.dataset.stock || 0) : 9999;
                if (stock > 0 && newQty > stock) {
                    showTxnAlert(`Only ${stock} unit(s) available in stock.`, 'warning'); return;
                }
            }
            item.quantity = newQty;
            renderCart();
            updateCheckoutBtn();
        }

        function cartRemove(idx) {
            const item = cart[idx];
            cart.splice(idx, 1);
            if (item && item.item_type === 'service' && item.category !== 'Labor' && item.product_name !== 'Labor Charge') {
                if (typeof updateServiceSelectionState === 'function') updateServiceSelectionState();
            } else {
                renderCart();
                updateCheckoutBtn();
                syncProductCheckboxes();
                if (typeof syncServiceCheckboxes === 'function') syncServiceCheckboxes();
            }
        }

        function updateTotals(subtotal, vat, grand) {
            const s = document.getElementById('cartSubtotal');
            const v = document.getElementById('cartVat');
            const g = document.getElementById('cartGrandTotal');
            if (s) s.textContent = '₱' + fmtNum(subtotal);
            if (v) v.textContent = '₱' + fmtNum(vat);
            if (g) g.textContent = '₱' + fmtNum(grand);
            computeChange();
            updateLoyaltyPointsEarned(grand);
        }

        function fmtNum(n) {
            return Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function escHtml(s) {
            return String(s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        }

        // ── Payment panel ─────────────────────────────────────────────────────
        function onPaymentChange() {
            const method = document.getElementById('paymentMethod')?.value || '';
            const isEwallet = (method === 'GCash' || method === 'Maya');

            const containers = {
                'cashFields':          method === 'Cash',
                'creditCardFields':    method === 'Credit Card',
                'debitCardFields':     method === 'Debit Card',
                'ewalletFields':       isEwallet,
                'fleetCardFields':     method === 'Petron Fleet Card',
                'efuelCardFields':     false, // removed from dropdown
                'creditAccountFields': method === 'Credit Account'
            };
            for (const [id, show] of Object.entries(containers)) {
                const el = document.getElementById(id);
                if (el) el.style.display = show ? 'block' : 'none';
            }

            // Auto-set provider for GCash / Maya
            if (isEwallet) {
                const prov = document.getElementById('ewProvider');
                if (prov) prov.value = method; // 'GCash' or 'Maya'
            }

            // Show balance due bar for card / ewallet / fleet
            const generalBalanceWrap = document.getElementById('generalBalanceWrap');
            const needsBalance = ['Credit Card','Debit Card','GCash','Maya','Petron Fleet Card'].includes(method);
            if (generalBalanceWrap) generalBalanceWrap.style.display = needsBalance ? 'block' : 'none';

            // Pre-fill amount paid with grand total
            const grand = getGrandTotal();
            const prefillMap = {
                'Credit Card': 'ccAmount', 'Debit Card': 'dcAmount',
                'GCash': 'ewAmount', 'Maya': 'ewAmount', 'Petron Fleet Card': 'fcAmount'
            };
            const fillId = prefillMap[method];
            if (fillId) {
                const inp = document.getElementById(fillId);
                if (inp && (!inp.value || parseFloat(inp.value) === 0)) inp.value = grand > 0 ? grand.toFixed(2) : '';
            }

            computeChange();
            onPaymentAmountInput();
            updatePaymentStatusBadge();
            updateCheckoutBtn();
        }

        function onCreditCustomerChange() {
            const select = document.getElementById('creditCustomer');
            const compInput = document.getElementById('creditCompanyName');
            const accInput  = document.getElementById('creditAccountNumber');
            if (select && select.value) {
                const opt  = select.options[select.selectedIndex];
                const name = opt.getAttribute('data-name') || '';
                if (compInput) compInput.value = name;
                if (accInput && !accInput.value) accInput.value = 'ACC-' + String(select.value).padStart(5, '0');
            } else {
                if (compInput) compInput.value = '';
                if (accInput)  accInput.value  = '';
            }
            updatePaymentStatusBadge();
            updateCheckoutBtn();
        }

        function _getAmountPaid(method) {
            const idMap = {
                'Cash': 'amountTendered', 'Credit Card': 'ccAmount', 'Debit Card': 'dcAmount',
                'GCash': 'ewAmount', 'Maya': 'ewAmount', 'Petron Fleet Card': 'fcAmount'
            };
            const id = idMap[method];
            return id ? parseFloat(document.getElementById(id)?.value || 0) : 0;
        }

        function onPaymentAmountInput() {
            const method = document.getElementById('paymentMethod')?.value || '';
            if (method === 'Cash' || method === 'Credit Account' || !method) return;
            const amount = _getAmountPaid(method);
            const grand  = getGrandTotal();
            const balEl  = document.getElementById('generalBalanceDue');
            if (balEl) {
                const bal = Math.max(0, grand - amount);
                balEl.value = (grand > 0 && bal > 0) ? bal.toFixed(2) : '';
            }
            updatePaymentStatusBadge();
        }

        function computeChange() {
            const grand    = getGrandTotal();
            const tendered = parseFloat(document.getElementById('amountTendered')?.value || 0);
            const changeWrap = document.getElementById('changeWrap');
            const changeEl   = document.getElementById('changeAmount');
            const balWrap    = document.getElementById('cashBalanceWrap');
            const balEl      = document.getElementById('cashBalanceDue');
            if (tendered >= grand && grand > 0) {
                if (changeWrap) changeWrap.style.display = 'block';
                if (changeEl)   changeEl.value = (tendered - grand).toFixed(2);
                if (balWrap)    balWrap.style.display = 'none';
                if (balEl)      balEl.value = '';
            } else {
                if (changeWrap) changeWrap.style.display = 'none';
                if (changeEl)   changeEl.value = '';
                const bal = Math.max(0, grand - tendered);
                if (balWrap) balWrap.style.display = grand > 0 ? 'block' : 'none';
                if (balEl)   balEl.value = bal > 0 ? bal.toFixed(2) : '';
            }
            updatePaymentStatusBadge();
        }

        // ── Live payment status badge ─────────────────────────────────────────
        function updatePaymentStatusBadge() {
            const method = document.getElementById('paymentMethod')?.value || '';
            const wrap   = document.getElementById('payStatusBadgeWrap');
            const icon   = document.getElementById('payStatusIcon');
            const lbl    = document.getElementById('payStatusLabel');
            const sub    = document.getElementById('payStatusSub');
            if (!wrap || !method) { if (wrap) wrap.style.display = 'none'; return; }

            const grand = getGrandTotal();
            let status, color, border, bg, iconClass, subText = '';

            if (method === 'Credit Account') {
                status = 'Pending'; color = '#6b21a8'; border = '#d8b4fe'; bg = '#f3e8ff';
                iconClass = 'fas fa-handshake';
                const sel = document.getElementById('creditCustomer');
                const acct = (sel && sel.selectedIndex > 0)
                    ? (sel.options[sel.selectedIndex].getAttribute('data-name') || 'Selected Account')
                    : 'Credit Account';
                subText = 'Charged to ' + acct + '. Full amount on credit.';
            } else {
                const amountPaid = _getAmountPaid(method);
                if (amountPaid <= 0 || grand === 0) {
                    status = 'Pending'; color = '#9a3412'; border = '#fed7aa'; bg = '#ffedd5';
                    iconClass = 'fas fa-clock';
                    subText = grand > 0 ? 'No amount encoded. Balance due: \u20b1' + fmtNum(grand) : 'Add items first.';
                } else if (amountPaid < grand - 0.009) {
                    status = 'Partially Paid'; color = '#92400e'; border = '#fde68a'; bg = '#fef9c3';
                    iconClass = 'fas fa-exclamation-circle';
                    subText = 'Paid \u20b1' + fmtNum(amountPaid) + ' \u2014 Balance: \u20b1' + fmtNum(grand - amountPaid);
                } else {
                    status = 'Paid'; color = '#166534'; border = '#86efac'; bg = '#dcfce7';
                    iconClass = 'fas fa-check-circle';
                    subText = method === 'Cash'
                        ? (amountPaid - grand > 0.009 ? 'Change: \u20b1' + fmtNum(amountPaid - grand) : 'Exact amount paid.')
                        : 'Full amount received via ' + method + '.';
                }
            }

            wrap.style.display = 'flex';
            wrap.style.background = bg;
            wrap.style.borderColor = border;
            if (icon) { icon.className = iconClass; icon.style.color = color; }
            if (lbl)  { lbl.textContent = status; lbl.style.color = color; }
            if (sub)  { sub.textContent = subText; }
        }

        function getGrandTotal() {
            const subtotal = cart.reduce((s, i) => s + i.quantity * i.unit_price, 0);
            return subtotal * 1.12;
        }

        function updateCheckoutBtn() {
            const btn    = document.getElementById('checkoutBtn');
            const method = document.getElementById('paymentMethod')?.value || '';
            if (!btn) return;
            let disabled = cart.length === 0 || !method;
            if (method === 'Credit Account') {
                if (!document.getElementById('creditCustomer')?.value) disabled = true;
            }
            btn.disabled = disabled;
        }
        // ── Submit transaction ────────────────────────────────────────────────
        async function submitMerchTxn() {
            if (cart.length === 0) { showTxnAlert('Cart is empty.', 'warning'); return; }

            const method = document.getElementById('paymentMethod')?.value || '';
            if (!method) { showTxnAlert('Please select a payment method.', 'warning'); return; }

            const grand = getGrandTotal();

            // ── Customer name ─────────────────────────────────────────────────
            const hasService = cart.some(i => i.item_type === 'service');
            const activeCustomerPrefix = hasService ? 'jo' : 'merch';
            let firstName, lastName;
            if (hasService) {
                firstName = (document.getElementById('joFirstName')?.value || '').trim();
                lastName  = (document.getElementById('joLastName')?.value  || '').trim();
                if (!firstName) { showTxnAlert('Please enter the customer\'s first name in the Job Order section.', 'warning'); return; }
            } else {
                firstName = (document.getElementById('merchFirstName')?.value || '').trim();
                lastName  = (document.getElementById('merchLastName')?.value  || '').trim();
                if (!firstName) { showTxnAlert('Please enter the customer\'s first name.', 'warning'); return; }
            }
            let selectedCustomerId = selectedCustomerIds[activeCustomerPrefix] || null;
            const mode = document.getElementById(activeCustomerPrefix + 'CustomerModeType')?.value || 'walkin';

            // 100% Flexible: Allow Walk-in transactions without requiring registered customer selection!
            if (!firstName) firstName = 'Walk-In';
            if (!lastName)  lastName  = 'Customer';

            // ── Payment validation ────────────────────────────────────────────
            if (method === 'Credit Account') {
                if (!document.getElementById('creditCustomer')?.value) {
                    showTxnAlert('Please select a credit account.', 'warning'); return;
                }
            }

            // ── Loyalty validation & fields ──────────────────────────────────
            const loyaltyProgram = document.getElementById('loyaltyProgram')?.value || 'No Loyalty';
            const loyaltyCardNo = (document.getElementById('loyaltyCardNo')?.value || '').trim();
            const loyaltyPointsBalance = parseInt(document.getElementById('loyaltyPointsBalance')?.value || 0) || 0;
            const loyaltyPointsEarned = parseInt(document.getElementById('loyaltyPointsEarned')?.value || 0) || 0;
            const loyaltyPointsRedeemed = parseInt(document.getElementById('loyaltyPointsRedeemed')?.value || 0) || 0;
            const hasLoyaltyCard = loyaltyProgram !== 'No Loyalty' && loyaltyCardNo !== '';

            if (loyaltyProgram !== 'No Loyalty' && loyaltyPointsRedeemed > loyaltyPointsBalance) {
                showTxnAlert(`Cannot redeem more points than current balance (${loyaltyPointsBalance} pts).`, 'warning');
                document.getElementById('loyaltyPointsRedeemed')?.focus();
                return;
            }

            // ── JO data ───────────────────────────────────────────────────────
            let joData = {};
            if (hasService) {
                const mechanicId = (document.getElementById('joMechanicId')?.value || '').trim();
                if (!mechanicId) {
                    showTxnAlert('Please select a valid assigned mechanic from the dropdown list.', 'warning');
                    const mechInput = document.getElementById('joMechanic');
                    if (mechInput) mechInput.focus();
                    return;
                }
                joData = {
                    job_order_service:            (document.getElementById('joServiceTypeValue')?.value || '').trim(),
                    job_order_description:        (document.getElementById('joNotes')?.value || '').trim(),
                    job_order_vehicle_plate:      (document.getElementById('joVehiclePlate')?.value || '').trim().toUpperCase(),
                    job_order_vehicle_type:       (document.getElementById('joVehicleType')?.value || '').trim(),
                    job_order_vehicle_brand:      (document.getElementById('joVehicleBrand')?.value || '').trim(),
                    job_order_vehicle_model:      (document.getElementById('joVehicleModel')?.value || '').trim(),
                    job_order_year_model:         (document.getElementById('joYearModel')?.value || '').trim(),
                    job_order_engine_number:      (document.getElementById('joEngineNumber')?.value || '').trim(),
                    job_order_chassis_number:     (document.getElementById('joChassisNumber')?.value || '').trim(),
                    job_order_mechanic_id:        parseInt(mechanicId) || null,
                    job_order_mechanic_name:      (document.getElementById('joMechanicName')?.value || '').trim(),
                    job_order_contact:            (document.getElementById('joContactNumber')?.value || '').trim(),
                    job_order_estimated_duration: parseInt(document.getElementById('joEstimatedDuration')?.value || 0) || null,
                };
            }

            // ── Amount paid & payment status ──────────────────────────────────
            const amountPaid = _getAmountPaid(method);
            let paymentStatus;
            if (method === 'Credit Account') {
                paymentStatus = 'Pending';
            } else if (amountPaid <= 0) {
                paymentStatus = 'Pending';
            } else if (amountPaid < grand - 0.009) {
                paymentStatus = 'Partially Paid';
            } else {
                paymentStatus = 'Paid';
            }
            const balanceDue = method === 'Credit Account' ? grand
                : (paymentStatus === 'Paid' ? 0 : Math.max(0, grand - amountPaid));

            // ── Build payload ─────────────────────────────────────────────────
            const isCard    = method === 'Credit Card' || method === 'Debit Card';
            const isEwallet = method === 'GCash' || method === 'Maya';
            const isFleet   = method === 'Petron Fleet Card';
            const isCredit  = method === 'Credit Account';

            const payload = {
                action:              'create_transaction',
                customer_id:         selectedCustomerId,
                customer_first_name: firstName || null,
                customer_last_name:  lastName  || null,
                customer_name:       [firstName, lastName].filter(Boolean).join(' ') || 'No Customer',
                payment_method:      method,
                amount_paid:         amountPaid > 0 ? amountPaid : null,
                amount_tendered:     method === 'Cash' ? (amountPaid > 0 ? amountPaid : null) : null,
                change_amount:       method === 'Cash' && amountPaid >= grand ? parseFloat((amountPaid - grand).toFixed(2)) : null,
                balance_due:         balanceDue > 0 ? parseFloat(balanceDue.toFixed(2)) : null,
                payment_status:      paymentStatus,

                // Card fields
                card_type:           isCard ? (document.getElementById(method === 'Credit Card' ? 'ccType' : 'dcType')?.value || null) : null,
                card_last_four:      method === 'Credit Card' ? (document.getElementById('ccLastFour')?.value || null) : null,
                card_reference:      method === 'Credit Card' ? (document.getElementById('ccRefNumber')?.value || null)
                                   : method === 'Debit Card'  ? (document.getElementById('dcRefNumber')?.value || null) : null,

                // E-Wallet fields (GCash / Maya)
                ewallet_provider:    isEwallet ? method : null,
                ewallet_reference:   isEwallet ? (document.getElementById('ewRefNumber')?.value || null) : null,

                // Fleet Card fields
                fleet_card_number:   isFleet ? (document.getElementById('fcNumber')?.value || null) : null,
                fleet_company_name:  isFleet ? (document.getElementById('fcCompanyName')?.value || null) : null,
                fleet_auth_number:   isFleet ? (document.getElementById('fcAuthNumber')?.value || null) : null,

                // Credit Account fields
                credit_customer_id:    isCredit ? (parseInt(document.getElementById('creditCustomer')?.value) || null) : null,
                credit_company_name:   isCredit ? (document.getElementById('creditCompanyName')?.value || null) : null,
                credit_account_number: isCredit ? (document.getElementById('creditAccountNumber')?.value || null) : null,
                credit_po_number:      isCredit ? (document.getElementById('creditPoNumber')?.value || null) : null,
                credit_due_date:       isCredit ? (document.getElementById('creditDueDate')?.value || null) : null,
                
                // Loyalty fields
                loyalty_type:            loyaltyProgram !== 'No Loyalty' ? loyaltyProgram : null,
                loyalty_card_no:         hasLoyaltyCard ? loyaltyCardNo : null,
                loyalty_points_earned:   hasLoyaltyCard ? loyaltyPointsEarned : null,
                loyalty_points_redeemed: hasLoyaltyCard ? loyaltyPointsRedeemed : null,

                items: cart.map(i => ({
                    item_type:    i.item_type,
                    product_id:   i.product_id,
                    product_name: i.product_name,
                    category:     i.category,
                    size_variant: i.size_variant,
                    quantity:     i.quantity,
                    unit_price:   i.unit_price,
                })),
                ...joData,
            };

            const btn = document.getElementById('checkoutBtn');
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing…'; }

            try {
                const res  = await fetch('../backend/api/merchandise_transactions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify(payload),
                });
                const data = await res.json();

                if (data.success) {
                    // If job order (has service), show banner with print link + redirect to tracker
                    if (hasService) {
                        const txnId = data.transaction_id;
                        // Show success banner with a separate print link — no auto-print
                        const old = document.getElementById('txnAlertBanner');
                        if (old) old.remove();
                        const div = document.createElement('div');
                        div.id = 'txnAlertBanner';
                        div.style.cssText = `position:fixed;top:84px;right:22px;left:auto;z-index:999999;
                            background:#f0fdf4;border:1.5px solid #86efac;color:#166534;
                            padding:12px 18px;border-radius:10px;font-size:13.5px;font-weight:700;
                            display:flex;align-items:center;gap:10px;box-shadow:0 12px 30px rgba(0,0,0,.15);
                            max-width:480px;width:auto;`;
                        div.innerHTML = `<i class="fas fa-check-circle" style="font-size:16px;flex-shrink:0;"></i>
                            <span style="flex:1;">Job Order successfully submitted!
                            <a href="javascript:void(0)" onclick="printMerchandiseReceipt(${txnId})"
                               style="color:#15803d;text-decoration:underline;font-weight:800;margin-left:6px;">
                               <i class="fas fa-print" style="margin-right:3px;"></i>Print Receipt
                            </a></span>`;
                        document.body.appendChild(div);
                        setTimeout(function() { if (div.parentElement) div.remove(); }, 5000);
                        // Redirect to tracker
                        setTimeout(function() {
                            window.location.href = 'staff_transactions_hub.php?section=merchandise&active_tab=tracker';
                        }, 1800);
                        return;
                    }

                    // Reset everything (merchandise-only)
                    cart = [];
                    renderCart();
                    updateCheckoutBtn();
                    resetMerchandiseForm();
                    selectedCustomerIds.jo = null;
                    selectedCustomerIds.merch = null;
                    // Reset JO fields
                    ['joFirstName','joLastName','joContactNumber','joVehiclePlate',
                     'joServicePrice','joNotes','joEstimatedDuration'].forEach(id => {
                        const el = document.getElementById(id);
                        if (el) el.value = '';
                    });
                    const joSvcInput = document.getElementById('joServiceType');
                    const joSvcHidden = document.getElementById('joServiceTypeValue');
                    if (joSvcInput) joSvcInput.value = '';
                    if (joSvcHidden) joSvcHidden.value = '';
                    const joVehicleSel = document.getElementById('joVehicleType');
                    if (joVehicleSel) joVehicleSel.value = '';
                    // Reset mechanic typeahead
                    const joMech = document.getElementById('joMechanic');
                    if (joMech) joMech.value = '';
                    const joMechId = document.getElementById('joMechanicId');
                    if (joMechId) joMechId.value = '';
                    const joMechNm = document.getElementById('joMechanicName');
                    if (joMechNm) joMechNm.value = '';
                    hideMechanicDropdown();
                    const notesWrap = document.getElementById('joServicePriceNotes');
                    if (notesWrap) notesWrap.style.display = 'none';
                    // Hide mechanic busy warning on reset
                    const mechBusyWarn = document.getElementById('joMechanicBusyWarn');
                    if (mechBusyWarn) mechBusyWarn.style.display = 'none';
                    clearSuggestedParts();
                    // Reset merch customer fields
                    ['merchFirstName','merchLastName'].forEach(id => {
                        const el = document.getElementById(id);
                        if (el) el.value = '';
                    });
                    // Reset payment
                    const pmSel = document.getElementById('paymentMethod');
                    if (pmSel) pmSel.selectedIndex = 0;
                    onPaymentChange();
                    // Auto-print receipt for merchandise-only transactions
                    printMerchandiseReceipt(data.transaction_id);
                    showTxnAlert('Transaction submitted successfully! Receipt opened in a new tab.', 'success');
                } else {
                    showTxnAlert('Error: ' + (data.error || 'Transaction failed.'), 'error');
                }
            } catch (err) {
                showTxnAlert('Network error: ' + err.message, 'error');
            } finally {
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-receipt"></i> Process & Print Receipt'; }
                updateCheckoutBtn();
            }
        }

        // ── Alert helper ──────────────────────────────────────────────────────
        // ── Alert helper (Right-side Floating Banner) ──────────────────────────
        function showTxnAlert(msg, type) {
            const colors = {
                success: { bg: '#f0fdf4', border: '#86efac', color: '#166534' },
                warning: { bg: '#fffbeb', border: '#fde68a', color: '#92400e' },
                error:   { bg: '#fef2f2', border: '#fecaca', color: '#991b1b' },
                info:    { bg: '#eff6ff', border: '#bfdbfe', color: '#1e40af' },
            };
            const c = colors[type] || colors.info;
            const icons = { success: 'fa-check-circle', warning: 'fa-exclamation-triangle', error: 'fa-times-circle', info: 'fa-info-circle' };
            const icon = icons[type] || icons.info;

            // Remove existing alert
            const old = document.getElementById('txnAlertBanner');
            if (old) old.remove();

            const div = document.createElement('div');
            div.id = 'txnAlertBanner';
            div.style.cssText = `position:fixed;top:84px;right:22px;left:auto;transform:none;z-index:999999;
                background:${c.bg};border:1.5px solid ${c.border};color:${c.color};
                padding:12px 18px;border-radius:10px;font-size:13.5px;font-weight:700;
                display:flex;align-items:center;gap:10px;box-shadow:0 12px 30px rgba(0,0,0,.15);
                max-width:440px;width:auto;`;
            div.innerHTML = `<i class="fas ${icon}" style="font-size:16px;flex-shrink:0;"></i><span style="flex:1;">${escHtml(msg)}</span>`;
            document.body.appendChild(div);
            setTimeout(() => { if (div.parentElement) div.remove(); }, 3000);
        }

        // ── Init ──────────────────────────────────────────────────────────────
        document.addEventListener('DOMContentLoaded', function() {
            loadServiceTypes();
            loadVehicleTypes();
            renderCart();
            updateCheckoutBtn();
            onPaymentChange();

            // Close vehicle dropdown when clicking outside
            document.addEventListener('click', function(e) {
                const input = document.getElementById('joVehicleType');
                const dd    = document.getElementById('vehicleTypeDropdown');
                if (dd && input && !input.contains(e.target) && !dd.contains(e.target)) {
                    dd.style.display = 'none';
                }
            });
        });
        </script>

        </div><!-- /innerTab_merchandise -->

        

        <!-- ══════════════════════════════════════════════════════════
             TAB 3: JOB ORDER TRACKER  (unified single-table design)
        ══════════════════════════════════════════════════════════ -->
        <div id="innerTab_tracker" style="display:<?= $active_tab === 'tracker' ? 'block' : 'none' ?>;">
        <?php
        // Staff view: simple tracker without manager-level KPIs
        ?>

        <!-- Filter bar -->
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;margin-bottom:14px;">
            <!-- Row 1: Search -->
            <div style="margin-bottom:10px;">
                <input type="text" id="joSearchInput" oninput="joApplyFilters()"
                       style="width:100%;padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:12px;color:#1e293b;background:#fff;outline:none;box-sizing:border-box;"
                       placeholder="Search Transaction ID, Customer, JO No., Plate No.">
            </div>
            <!-- Row 2: Dropdowns + buttons -->
            <div style="display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;">

                <!-- Type -->
                <div style="display:flex;flex-direction:column;gap:4px;">
                    <label style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">Type</label>
                    <select id="joFilterType" onchange="joApplyFilters()" style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:12px;color:#1e293b;background:#fff;outline:none;">
                        <option value="all">All Transactions</option>
                        <option value="job_order">Job Order Only</option>
                        <option value="combined">Combined Transaction</option>
                    </select>
                </div>

                <!-- Start Date -->
                <div style="display:flex;flex-direction:column;gap:4px;">
                    <label style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">Start Date</label>
                    <input type="date" id="joFilterStartDate" onchange="joApplyFilters()" style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:12px;color:#1e293b;background:#fff;outline:none;">
                </div>

                <!-- End Date -->
                <div style="display:flex;flex-direction:column;gap:4px;">
                    <label style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">End Date</label>
                    <input type="date" id="joFilterEndDate" onchange="joApplyFilters()" style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:12px;color:#1e293b;background:#fff;outline:none;">
                </div>

                <!-- Status -->
                <div style="display:flex;flex-direction:column;gap:4px;">
                    <label style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">Status</label>
                    <select id="joFilterStatus" onchange="joApplyFilters()" style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:12px;color:#1e293b;background:#fff;outline:none;">
                        <option value="all">All Statuses</option>
                        <option value="pending">Pending Validation</option>
                        <option value="inprogress">In Progress</option>
                        <option value="completed">Completed</option>
                        <option value="released">Released</option>
                    </select>
                </div>

                <!-- Assigned Mechanic -->
                <div style="display:flex;flex-direction:column;gap:4px;">
                    <label style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">Assigned Mechanic</label>
                    <select id="joFilterMechanic" onchange="joApplyFilters()" style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:12px;color:#1e293b;background:#fff;outline:none;max-width:180px;">
                        <option value="">All Mechanics</option>
                        <?php
                        // Collect unique mechanics from loaded job orders
                        $jo_mechanics_list = [];
                        foreach ($job_orders as $_jom) {
                            $mn = trim($_jom['mechanic_name'] ?? '');
                            if ($mn && $mn !== 'Unassigned' && !in_array($mn, $jo_mechanics_list)) {
                                $jo_mechanics_list[] = $mn;
                            }
                        }
                        sort($jo_mechanics_list);
                        foreach ($jo_mechanics_list as $mn): ?>
                        <option value="<?= htmlspecialchars($mn) ?>"><?= htmlspecialchars($mn) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Service Type -->
                <div style="display:flex;flex-direction:column;gap:4px;">
                    <label style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">Service Type</label>
                    <select id="joFilterServiceType" onchange="joApplyFilters()" style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:12px;color:#1e293b;background:#fff;outline:none;max-width:180px;text-overflow:ellipsis;overflow:hidden;white-space:nowrap;">
                        <option value="">All Service Types</option>
                        <?php
                        $jo_service_list = [];
                        foreach ($job_orders as $_jos) {
                            $st = trim($_jos['service_type'] ?? '');
                            if ($st && !in_array($st, $jo_service_list)) {
                                $jo_service_list[] = $st;
                            }
                        }
                        sort($jo_service_list);
                        foreach ($jo_service_list as $st): 
                            $disp_st = (mb_strlen($st) > 38) ? (mb_substr($st, 0, 36) . '...') : $st;
                        ?>
                        <option value="<?= htmlspecialchars($st) ?>" title="<?= htmlspecialchars($st) ?>"><?= htmlspecialchars($disp_st) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="button" onclick="joApplyFilters()" class="txn-btn primary">
                    <i class="fas fa-filter"></i> Filter
                </button>
                <button type="button" onclick="joResetFilters()" class="txn-btn secondary">
                    <i class="fas fa-times"></i> Clear
                </button>

            </div>
        </div>

        <!-- KPI Cards — always visible -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-bottom:16px;">
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px 16px;text-align:center;">
                <div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Today's Transactions</div>
                <div style="font-size:24px;font-weight:800;color:#002F70;" id="jo_kpi_total_txns"><?= (int)($mh_kpi_txn_count + $kpi_jo_count) ?></div>
            </div>
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px 16px;text-align:center;">
                <div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Today's Sales</div>
                <div style="font-size:20px;font-weight:800;color:#002F70;" id="jo_kpi_total_sales">₱<?= number_format($kpi_total_encoded, 2) ?></div>
            </div>
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px 16px;text-align:center;">
                <div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Completed JO</div>
                <div style="font-size:24px;font-weight:800;color:#16a34a;" id="jo_kpi_completed_jo"><?= (int)array_reduce($job_orders, fn($c,$j) => $c + (($j['status']??'')==='Completed'?1:0), 0) ?></div>
            </div>
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px 16px;text-align:center;">
                <div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Merchandise Sold</div>
                <div style="font-size:24px;font-weight:800;color:#002F70;" id="jo_kpi_merch_sold"><?= (int)$kpi_merch_released ?></div>
            </div>
        </div>

        <!-- Unified Job Order Table -->
        <div class="txn-card" style="margin-bottom: 80px;">
            <div class="txn-card-header" style="background:#f0f7ff;">
                <i class="fas fa-clipboard-list" style="color:#003d7a;"></i>
                <h3 style="color:#003d7a;">All Job Orders</h3>
            </div>
            <div class="txn-card-body" style="padding:0;">

                
                <?php if (empty($job_orders)): ?>
                <div style="text-align:center;padding:40px;color:#94a3b8;">
                    <i class="fas fa-clipboard" style="font-size:28px;display:block;margin-bottom:8px;"></i>
                    No job orders found.
                </div>
                <?php else: ?>
                <div style="width:100%;overflow-x:hidden !important;padding-bottom:12px;">
                <table class="txn-table" id="joUnifiedTable" style="width:100% !important;table-layout:fixed !important;border-collapse:collapse;">
                    <colgroup>
                        <col style="width:4.5%;"><!-- JO # -->
                        <col style="width:6.5%;"><!-- OR No. -->
                        <col style="width:8.5%;"><!-- Customer -->
                        <col style="width:5.5%;"><!-- Plate No. -->
                        <col style="width:5%;"><!-- Vehicle -->
                        <col style="width:7%;"><!-- Service Type -->
                        <col style="width:7.5%;"><!-- Assigned Mechanic -->
                        <col style="width:5%;"><!-- Service Fee -->
                        <col style="width:5%;"><!-- Labor Fee -->
                        <col style="width:8.5%;"><!-- JO Status -->
                        <col style="width:5.5%;"><!-- Payment Status -->
                        <col style="width:5.5%;"><!-- Payment Method -->
                        <col style="width:6.5%;"><!-- Est. Completion -->
                        <col style="width:6%;"><!-- Date Created -->
                        <col style="width:13.5%;"><!-- Actions -->
                    </colgroup>
                    <thead style="background:linear-gradient(135deg,#002F70 0%,#003d8f 100%);">
                        <tr>
                            <th style="font-size:11px;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:.3px;padding:9px 5px;text-align:left;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;" title="Job Order Number">JO #</th>
                            <th style="font-size:11px;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:.3px;padding:9px 5px;text-align:left;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;" title="Official Receipt Number">OR No.</th>
                            <th style="font-size:11px;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:.3px;padding:9px 5px;text-align:left;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;" title="Customer Name">Customer</th>
                            <th style="font-size:11px;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:.3px;padding:9px 5px;text-align:left;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;" title="Plate Number">Plate No.</th>
                            <th style="font-size:11px;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:.3px;padding:9px 5px;text-align:left;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;" title="Vehicle Type">Vehicle</th>
                            <th style="font-size:11px;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:.3px;padding:9px 5px;text-align:left;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;" title="Service Type">Svc Type</th>
                            <th style="font-size:11px;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:.3px;padding:9px 5px;text-align:left;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;" title="Assigned Mechanic">Mechanic</th>
                            <th style="font-size:11px;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:.3px;padding:9px 5px;text-align:right;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;" title="Service Fee">Svc Fee</th>
                            <th style="font-size:11px;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:.3px;padding:9px 5px;text-align:right;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;" title="Labor Fee">Labor</th>
                            <th style="font-size:11px;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:.3px;padding:9px 5px;text-align:center;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;" title="JO Status">JO Status</th>
                            <th style="font-size:11px;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:.3px;padding:9px 4px;text-align:center;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;" title="Payment Status">Pay Status</th>
                            <th style="font-size:11px;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:.3px;padding:9px 4px;text-align:center;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;" title="Payment Method">Pay Method</th>
                            <th style="font-size:11px;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:.3px;padding:9px 5px;text-align:left;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;" title="Estimated Completion">Est. Done</th>
                            <th style="font-size:11px;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:.3px;padding:9px 5px;text-align:left;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;" title="Date Created">Created</th>
                            <th style="font-size:11px;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:.3px;padding:9px 5px;text-align:center;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;" title="Actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($job_orders as $job):
                        $val_status  = $job['validation_status'] ?? 'Pending Validation';
                        $wf_status   = $job['status'] ?? 'Pending';
                        $pay_status  = $job['payment_status'] ?? 'Pending';
                        $remarks     = $job['rejection_remarks'] ?? $job['notes'] ?? $job['additional_notes'] ?? '';

                        $inv_key     = ($job['_source'] ?? 'job_orders') . ':' . $job['id'];
                        $pending_req = $pending_requests[$inv_key] ?? null;

                        // Determine combined workflow label + badge style
                        if ($pending_req) {
                            if (($pending_req['request_type'] ?? '') === 'Adjustment') {
                                $wf_color='#d97706'; $wf_bg='#fef3c7'; $wf_label='Adjustment Requested'; $row_filter='adj_requested';
                            } elseif (($pending_req['request_type'] ?? '') === 'Void') {
                                $wf_color='#dc2626'; $wf_bg='#fee2e2'; $wf_label='Void Requested'; $row_filter='void_requested';
                            }
                        } elseif (in_array(strtolower($val_status), ['adjusted']) || in_array(strtolower($wf_status), ['adjusted'])) {
                            $wf_color='#4338ca'; $wf_bg='#e0e7ff'; $wf_label='Adjusted'; $row_filter='adjusted';
                        } elseif (in_array(strtolower($val_status), ['voided']) || in_array(strtolower($wf_status), ['voided']) || strtolower($wf_status) === 'voided') {
                            $wf_color='#991b1b'; $wf_bg='#fee2e2'; $wf_label='Voided'; $row_filter='voided';
                        } elseif ($wf_status === 'Released') {
                            $wf_color='#475569'; $wf_bg='#f1f5f9'; $wf_label='Released'; $row_filter='released';
                        } elseif ($wf_status === 'Completed' || $val_status === 'Completed') {
                            $wf_color='#16a34a'; $wf_bg='#dcfce7'; $wf_label='Completed'; $row_filter='completed';
                        } elseif ($wf_status === 'In Progress' || $val_status === 'In Progress') {
                            $wf_color='#8b5cf6'; $wf_bg='#ede9fe'; $wf_label='In Progress'; $row_filter='inprogress';
                        } elseif ($wf_status === 'Waiting for Parts' || $wf_status === 'Waiting For Parts') {
                            $wf_color='#2563eb'; $wf_bg='#dbeafe'; $wf_label='Waiting for Parts'; $row_filter='waiting_for_parts';
                        } elseif (in_array($wf_status, ['Rejected', 'Cancelled']) || $val_status === 'Rejected') {
                            $wf_color='#dc2626'; $wf_bg='#fee2e2'; $wf_label='Cancelled'; $row_filter='rejected';
                        } else {
                            $wf_color='#d97706'; $wf_bg='#fef3c7'; $wf_label='Pending'; $row_filter='pending';
                        }

                        // Payment badge — supports both new and legacy status values
                        if ($pay_status === 'Paid') {
                            $pay_color='#16a34a'; $pay_label='PAID';
                        } elseif ($pay_status === 'Partially Paid' || $pay_status === 'Partial Payment' || $pay_status === 'Partial') {
                            $pay_color='#d97706'; $pay_label='PARTIALLY PAID';
                        } elseif ($pay_status === 'Pending' || $pay_status === 'Pending Payment' || $pay_status === 'Unpaid') {
                            $pay_color='#ea580c'; $pay_label='PENDING';
                        } elseif ($pay_status === 'Credit Account' || $pay_status === 'Credit Transaction' || $pay_status === 'Credit' || $pay_status === 'Receivables/Credit' || $pay_status === 'A/R') {
                            $pay_color='#7c3aed'; $pay_label='A/R';
                        } else {
                            $pay_color='#ea580c'; $pay_label='PENDING';
                        }

                        // Parts/items
                        $parts_raw = $job['required_parts'] ?? '';
                        $parts_display = '—';
                        if (!empty($parts_raw)) {
                            $decoded = json_decode($parts_raw, true);
                            if (is_array($decoded)) {
                                $parts_display = implode(', ', array_map(fn($p) => is_array($p) ? ($p['name'] ?? $p['part_name'] ?? '') : $p, $decoded));
                            } else {
                                $parts_display = $parts_raw;
                            }
                            if (strlen($parts_display) > 60) $parts_display = substr($parts_display, 0, 57) . '…';
                        }

                        // ── Inventory Impact for this row ─────────────────────────────────────
                        $inv_items    = $inv_impact[$inv_key] ?? [];

                        // ── Receivables: due date + overdue ──────────────────────────────────
                        $jo_due_date  = $job['due_date'] ?? null;
                        $jo_is_overdue = false;
                        if ($jo_due_date && !in_array($pay_status, ['Paid','Completed'])) {
                            $jo_is_overdue = (strtotime(date('Y-m-d')) >= strtotime($jo_due_date));
                        }
                        $jo_balance_display = (float)($job['balance_due'] ?? 0);
                        $jo_total_for_bal   = (float)($job['total_cost'] ?? $job['estimated_cost'] ?? 0);
                        $jo_paid_for_bal    = (float)($job['amount_paid'] ?? 0);
                        if ($jo_balance_display <= 0.009 && $jo_total_for_bal > 0)
                            $jo_balance_display = max(0, $jo_total_for_bal - $jo_paid_for_bal);

                        // ── Separated remarks ────────────────────────────────────────────────
                        $staff_remark   = trim($job['staff_remarks'] ?? $job['notes'] ?? $job['job_order_description'] ?? '');
                        $manager_remark = trim($job['manager_notes'] ?? $job['admin_remarks'] ?? '');
                        $remarks        = !empty($staff_remark) ? $staff_remark : (!empty($manager_remark) ? $manager_remark : '');

                        // ── Shift Indicator ──────────────────────────────────────────────────
                        $shift_name_raw = trim($job['shift_name'] ?? $job['shift_period'] ?? '');
                        if (!empty($shift_name_raw)) {
                            $shift_label    = $shift_name_raw;
                            $shift_key_data = (stripos($shift_name_raw,'2') !== false || stripos($shift_name_raw,'pm') !== false || stripos($shift_name_raw,'night') !== false) ? 'shift2' : 'shift1';
                        } else {
                            $created_hour = (int)date('H', strtotime($job['created_at']));
                            if ($created_hour >= 6 && $created_hour < 18) {
                                $shift_label = 'Shift 1'; $shift_key_data = 'shift1';
                            } else {
                                $shift_label = 'Shift 2'; $shift_key_data = 'shift2';
                            }
                        }
                        $shift_icon  = ($shift_key_data === 'shift1') ? '🌤' : '🌙';
                        $shift_color = ($shift_key_data === 'shift1') ? '#d97706' : '#4f46e5';
                        $shift_bg    = ($shift_key_data === 'shift1') ? '#fef9c3' : '#ede9fe';
                    ?>
                    <tr
                        data-jo-filter="<?= $row_filter ?>"
                        data-jo-shift="<?= $shift_key_data ?>"
                        data-jo-source="<?= htmlspecialchars($job['_source'] ?? 'job_orders') ?>"
                        data-jo-type="<?= htmlspecialchars($job['transaction_type'] ?? 'job_order') ?>"
                        data-jo-mechanic="<?= htmlspecialchars($job['mechanic_name'] ?? '') ?>"
                        data-jo-service="<?= htmlspecialchars($job['service_type'] ?? '') ?>"
                        data-jo-date="<?= date('Y-m-d', strtotime($job['created_at'])) ?>"
                        data-jo-search="<?= htmlspecialchars(strtolower(implode(' ', [
                            $job['job_order_id'] ?? ('#'.$job['id']),
                            $job['customer_name'] ?? '',
                            $job['vehicle_plate'] ?? '',
                            $job['service_type'] ?? '',
                        ]))) ?>"
                        style="<?= $wf_status === 'Rejected' ? 'background:#fff8f8;' : ($jo_is_overdue ? 'background:#fff5f5;border-left:3px solid #dc2626;' : '') ?>">

                        <!-- JO Number -->
                        <td style="padding:8px 5px;font-weight:700;color:<?= $wf_status==='Rejected' ? '#dc2626' : '#002F70' ?>;font-size:12px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;vertical-align:middle;">
                            <?= htmlspecialchars($job['job_order_id'] ?? ('#'.$job['id'])) ?>
                        </td>

                        <!-- OR No. -->
                        <td style="padding:8px 5px;font-size:12px;color:#475569;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;vertical-align:middle;">
                            <?php
                            $jo_or = '';
                            if (!empty($job['created_at'])) {
                                $jo_or = 'OR-' . date('Y', strtotime($job['created_at'])) . '-' . str_pad($job['id'], 6, '0', STR_PAD_LEFT);
                            }
                            echo $jo_or ? htmlspecialchars($jo_or) : '<span style="color:#cbd5e1;">—</span>';
                            ?>
                        </td>

                        <!-- Customer -->
                        <td style="padding:8px 5px;font-size:12px;color:#1e293b;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;vertical-align:middle;"
                            title="<?= htmlspecialchars($job['customer_name'] ?? '') ?>">
                            <?= htmlspecialchars($job['customer_name'] ?? '—') ?>
                        </td>

                        <!-- Plate No. -->
                        <td style="padding:8px 5px;font-size:12px;font-weight:700;color:#1e293b;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;vertical-align:middle;">
                            <?= !empty($job['vehicle_plate']) ? htmlspecialchars($job['vehicle_plate']) : '<span style="color:#cbd5e1;">—</span>' ?>
                        </td>

                        <!-- Vehicle Type -->
                        <td style="padding:8px 5px;font-size:12px;color:#475569;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;vertical-align:middle;">
                            <?= !empty($job['vehicle_type']) ? htmlspecialchars($job['vehicle_type']) : '<span style="color:#cbd5e1;">—</span>' ?>
                        </td>

                        <!-- Service Type -->
                        <td style="padding:8px 5px;font-size:12px;color:#0369a1;font-weight:600;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;vertical-align:middle;"
                            title="<?= htmlspecialchars($job['service_type'] ?? '—') ?>">
                            <?= htmlspecialchars($job['service_type'] ?? '—') ?>
                        </td>

                        <!-- Assigned Mechanic -->
                        <td style="padding:8px 5px;font-size:12px;color:#475569;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;vertical-align:middle;">
                            <?php
                            $mech = trim($job['mechanic_name'] ?? '');
                            if ($mech && $mech !== 'Unassigned'): ?>
                            <span style="display:inline-flex;align-items:center;gap:3px;">
                                <i class="fas fa-user-cog" style="color:#94a3b8;font-size:10px;flex-shrink:0;"></i>
                                <?= htmlspecialchars($mech) ?>
                            </span>
                            <?php else: ?>
                            <span style="color:#cbd5e1;font-style:italic;font-size:11px;">Unassigned</span>
                            <?php endif; ?>
                        </td>

                        <!-- Service Fee -->
                        <td style="padding:8px 5px;font-size:12px;text-align:right;font-weight:700;color:#002F70;white-space:nowrap;vertical-align:middle;">
                            ₱<?= number_format((float)($job['service_fee'] ?? $job['estimated_cost'] ?? $job['total_cost'] ?? 0), 2) ?>
                        </td>

                        <!-- Labor Fee -->
                        <td style="padding:8px 5px;font-size:12px;text-align:right;font-weight:700;color:#16a34a;white-space:nowrap;vertical-align:middle;">
                            <?php
                            $labor_val = (float)($job['actual_labor_cost'] ?? $job['estimated_labor_cost'] ?? 0);
                            echo $labor_val > 0 ? '₱' . number_format($labor_val, 2) : '<span style="color:#cbd5e1;">—</span>';
                            ?>
                        </td>

                        <!-- JO Status -->
                        <td style="padding:8px 4px;vertical-align:middle;overflow:hidden;text-align:center;">
                            <span style="display:inline-flex;align-items:center;justify-content:center;padding:2px 6px;border-radius:10px;font-size:10px;font-weight:700;white-space:nowrap;max-width:100%;box-sizing:border-box;text-overflow:ellipsis;overflow:hidden;
                                         background:<?= $wf_bg ?>;color:<?= $wf_color ?>;border:1px solid <?= $wf_color ?>40;"
                                  title="<?= htmlspecialchars($wf_label) ?>">
                                <?= htmlspecialchars($wf_label) ?>
                            </span>
                        </td>

                        <!-- Payment Status -->
                        <td style="padding:8px 4px;vertical-align:middle;overflow:hidden;text-align:center;">
                            <span style="display:inline-flex;align-items:center;justify-content:center;padding:2px 6px;border-radius:10px;font-size:10px;font-weight:700;white-space:nowrap;max-width:100%;box-sizing:border-box;text-overflow:ellipsis;overflow:hidden;
                                         background:<?= $pay_color ?>18;color:<?= $pay_color ?>;border:1px solid <?= $pay_color ?>40;"
                                  title="<?= htmlspecialchars($pay_label) ?>">
                                <?= htmlspecialchars($pay_label) ?>
                            </span>
                        </td>

                        <!-- Payment Method -->
                        <td style="padding:8px 4px;vertical-align:middle;text-align:center;overflow:hidden;">
                            <?php
                            $pay_method = trim($job['payment_method'] ?? 'Cash');
                            if (empty($pay_method)) $pay_method = 'Cash';
                            $pm_bg = '#f1f5f9'; $pm_fg = '#334155';
                            if (stripos($pay_method, 'Cash') !== false) { $pm_bg = '#e0f2fe'; $pm_fg = '#0369a1'; }
                            elseif (stripos($pay_method, 'Card') !== false) { $pm_bg = '#e0e7ff'; $pm_fg = '#3730a3'; }
                            elseif (stripos($pay_method, 'GCash') !== false || stripos($pay_method, 'Maya') !== false) { $pm_bg = '#dcfce7'; $pm_fg = '#15803d'; }
                            elseif (stripos($pay_method, 'Fleet') !== false || stripos($pay_method, 'Petron') !== false) { $pm_bg = '#fef3c7'; $pm_fg = '#b45309'; }
                            elseif (stripos($pay_method, 'Credit') !== false || stripos($pay_method, 'A/R') !== false) { $pm_bg = '#f3e8ff'; $pm_fg = '#6b21a8'; }
                            ?>
                            <span style="display:inline-flex;align-items:center;justify-content:center;padding:2px 6px;border-radius:10px;font-size:10px;font-weight:700;white-space:nowrap;max-width:100%;box-sizing:border-box;text-overflow:ellipsis;overflow:hidden;background:<?= $pm_bg ?>;color:<?= $pm_fg ?>;border:1px solid <?= $pm_fg ?>30;"
                                  title="<?= htmlspecialchars($pay_method) ?>">
                                <?= htmlspecialchars($pay_method) ?>
                            </span>
                        </td>

                        <!-- Est. Completion -->
                        <td style="padding:8px 5px;font-size:12px;color:#475569;overflow:hidden;vertical-align:middle;">
                            <?php
                            $est_comp = $job['due_date'] ?? null;
                            $created_time = !empty($job['created_at']) ? strtotime($job['created_at']) : time();
                            $est_disp = '';
                            $est_sub = '';

                            if ($est_comp && $est_comp !== '0000-00-00' && $est_comp !== '0000-00-00 00:00:00') {
                                $ts = strtotime($est_comp);
                                if ($ts && $ts > 0) {
                                    $est_disp = date('M j, Y', $ts);
                                    $est_sub = date('h:i A', $ts);
                                }
                            }

                            if (empty($est_disp)) {
                                $duration_mins = (int)($job['estimated_duration'] ?? 0);
                                if ($duration_mins <= 0) {
                                    $svc_name = strtolower($job['service_type'] ?? '');
                                    if (str_contains($svc_name, 'oil') || str_contains($svc_name, 'additive')) {
                                        $duration_mins = 45;
                                    } elseif (str_contains($svc_name, 'atf') || str_contains($svc_name, 'transmission')) {
                                        $duration_mins = 60;
                                    } elseif (str_contains($svc_name, 'brake')) {
                                        $duration_mins = 90;
                                    } elseif (str_contains($svc_name, 'wash')) {
                                        $duration_mins = 45;
                                    } else {
                                        $duration_mins = 60;
                                    }
                                }
                                $completion_ts = $created_time + ($duration_mins * 60);
                                $est_disp = date('M j, Y', $completion_ts);
                                $est_sub = date('h:i A', $completion_ts) . ' (' . $duration_mins . 'm)';
                            }

                            $comp_ts = strtotime($est_disp);
                            $today_ts = strtotime(date('Y-m-d'));
                            $color = ($comp_ts < $today_ts && !in_array($wf_status ?? '', ['Completed'])) ? '#dc2626' : '#475569';
                            echo '<span style="color:'.$color.';font-weight:600;font-size:12px;">'.htmlspecialchars($est_disp).'</span>';
                            if ($est_sub) {
                                echo '<br><span style="font-size:11px;color:#0284c7;">'.htmlspecialchars($est_sub).'</span>';
                            }
                            ?>
                        </td>

                        <!-- Date Created -->
                        <td style="padding:8px 5px;font-size:12px;color:#475569;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;vertical-align:middle;">
                            <?= date('M j, Y', strtotime($job['created_at'])) ?><br>
                            <span style="font-size:11px;color:#94a3b8;"><?= date('h:i A', strtotime($job['created_at'])) ?></span>
                        </td>

                        <!-- Actions Column -->
                        <td style="padding:6px 3px;text-align:center;">
                            <?php
                                $jo_total   = (float)($job['total_cost'] ?? $job['estimated_cost'] ?? 0);
                                $jo_paid    = (float)($job['amount_paid'] ?? 0);
                                $jo_balance = (float)($job['balance_due'] ?? 0);
                                if ($jo_balance <= 0.009 && $jo_total > 0) $jo_balance = max(0, $jo_total - $jo_paid);
                                $labor_fee_val = (float)($job['actual_labor_cost'] ?? $job['estimated_labor_cost'] ?? 0);
                                
                                // Prepare JO data for modals (JSON-encoded)
                                $jo_or_number = '';
                                if (!empty($job['or_number'])) {
                                    $jo_or_number = $job['or_number'];
                                } elseif (!empty($job['created_at'])) {
                                    $jo_or_number = 'OR-' . date('Y', strtotime($job['created_at'])) . '-' . str_pad($job['id'], 6, '0', STR_PAD_LEFT);
                                }
                                $jo_data = json_encode([
                                    'id' => (int)$job['id'],
                                    'jo_ref' => $job['job_order_id'] ?? ('#'.$job['id']),
                                    'or_number' => $jo_or_number,
                                    'customer' => $job['customer_name'] ?? '',
                                    'contact_number' => $job['contact_number'] ?? $job['customer_contact'] ?? '',
                                    'vehicle_plate' => $job['vehicle_plate'] ?? '',
                                    'vehicle_type' => $job['vehicle_type'] ?? '',
                                    'vehicle_brand' => $job['vehicle_brand'] ?? $job['brand'] ?? '',
                                    'vehicle_model' => $job['vehicle_model'] ?? $job['model'] ?? '',
                                    'year_model' => $job['year_model'] ?? $job['vehicle_year'] ?? '',
                                    'engine_number' => $job['engine_number'] ?? '',
                                    'chassis_number' => $job['chassis_number'] ?? $job['vin'] ?? '',
                                    'odometer' => $job['odometer'] ?? '',
                                    'service_type' => $job['service_type'] ?? '',
                                    'service_description' => $job['service_description'] ?? $job['additional_notes'] ?? '',
                                    'parts' => $parts_display,
                                    'mechanic' => $job['mechanic_name'] ?? 'Unassigned',
                                    'workflow_status' => $wf_label,
                                    'payment_status' => $pay_label,
                                    'payment_method' => $pay_method,
                                    'total' => $jo_total,
                                    'labor_fee' => $labor_fee_val,
                                    'paid' => $jo_paid,
                                    'balance' => $jo_balance,
                                    'remarks' => !empty($remarks) ? $remarks : ($job['service_description'] ?? $job['additional_notes'] ?? ''),
                                    'staff_remarks' => $job['staff_remarks'] ?? $job['notes'] ?? $job['job_order_description'] ?? '',
                                    'customer_complaint' => $job['customer_complaint'] ?? '',
                                    'repair_recommendation' => $job['repair_recommendation'] ?? '',
                                    'created_at' => $job['created_at'],
                                    'source' => $job['_source'] ?? 'job_orders',
                                    'estimated_duration' => (int)(($job['estimated_duration'] ?? 0) > 0 ? $job['estimated_duration'] : ($duration_mins ?? 0))
                                ]);
                            ?>
                            <div style="display:flex;flex-direction:column;gap:3px;width:100%;">
                                <!-- Always show View button -->
                                <button type="button"
                                        onclick='viewJobOrderDetails(<?= htmlspecialchars($jo_data, ENT_QUOTES) ?>)'
                                        class="txn-btn secondary" 
                                        style="width:100%;padding:4px 6px;font-size:10.5px;box-sizing:border-box;text-align:center;justify-content:center;">
                                    <i class="fas fa-eye"></i> View
                                </button>

                                <?php if ($wf_label === 'Voided'): ?>
                                    <!-- VOIDED Status: View only + Voided Badge -->
                                    <span style="font-size:10.5px;color:#991b1b;font-weight:700;text-align:center;padding:2px 0;">
                                        <i class="fas fa-ban"></i> Voided
                                    </span>

                                <?php elseif ($wf_label === 'Void Requested' || ($pending_req && ($pending_req['request_type'] ?? '') === 'Void')): ?>
                                    <!-- VOID REQUESTED Status: View, Reprint + Badge -->
                                    <button type="button"
                                            onclick="printJobOrderReceipt(<?= (int)$job['id'] ?>,'<?= addslashes($job['job_order_id'] ?? ('#'.$job['id'])) ?>')"
                                            class="txn-btn secondary" style="width:100%;padding:4px 6px;font-size:10.5px;box-sizing:border-box;text-align:center;justify-content:center;">
                                        <i class="fas fa-print"></i> Reprint
                                    </button>
                                    <span style="font-size:10.5px;color:#dc2626;font-weight:700;text-align:center;padding:2px 0;">
                                        <i class="fas fa-clock"></i> Void Requested
                                    </span>

                                <?php elseif ($wf_label === 'Adjustment Requested' || ($pending_req && ($pending_req['request_type'] ?? '') === 'Adjustment')): ?>
                                    <!-- ADJUSTMENT REQUESTED Status: View, Reprint + Badge -->
                                    <button type="button"
                                            onclick="printJobOrderReceipt(<?= (int)$job['id'] ?>,'<?= addslashes($job['job_order_id'] ?? ('#'.$job['id'])) ?>')"
                                            class="txn-btn secondary" style="width:100%;padding:4px 6px;font-size:10.5px;box-sizing:border-box;text-align:center;justify-content:center;">
                                        <i class="fas fa-print"></i> Reprint
                                    </button>
                                    <span style="font-size:10.5px;color:#d97706;font-weight:700;text-align:center;padding:2px 0;">
                                        <i class="fas fa-clock"></i> Adjustment Requested
                                    </span>

                                <?php elseif ($wf_label === 'Adjusted'): ?>
                                    <!-- ADJUSTED Status: View, Reprint + Badge -->
                                    <button type="button"
                                            onclick="printJobOrderReceipt(<?= (int)$job['id'] ?>,'<?= addslashes($job['job_order_id'] ?? ('#'.$job['id'])) ?>')"
                                            class="txn-btn secondary" style="width:100%;padding:4px 6px;font-size:10.5px;box-sizing:border-box;text-align:center;justify-content:center;">
                                        <i class="fas fa-print"></i> Reprint
                                    </button>
                                    <span style="font-size:10.5px;color:#4338ca;font-weight:700;text-align:center;padding:2px 0;">
                                        <i class="fas fa-check-circle"></i> Adjusted
                                    </span>
                                    <?php if (in_array($wf_status, ['Completed','Released'])): ?>
                                        <button type="button"
                                                data-jo-id="<?= (int)$job['id'] ?>"
                                                data-jo-source="<?= htmlspecialchars($job['_source'] ?? 'job_orders') ?>"
                                                data-jo-ref="<?= htmlspecialchars($job['job_order_id'] ?? ('#'.$job['id'])) ?>"
                                                data-jo-customer="<?= htmlspecialchars($job['customer_name'] ?? '—') ?>"
                                                data-jo-status="<?= htmlspecialchars($wf_label) ?>"
                                                data-jo-paystatus="<?= htmlspecialchars($pay_label) ?>"
                                                data-jo-paymethod="<?= htmlspecialchars($pay_method) ?>"
                                                data-jo-total="<?= (float)$jo_total ?>"
                                                data-jo-labor="<?= (float)$labor_fee_val ?>"
                                                data-jo-paid="<?= (float)$jo_paid ?>"
                                                data-jo-service="<?= htmlspecialchars($job['service_type'] ?? '') ?>"
                                                data-jo-plate="<?= htmlspecialchars($job['vehicle_plate'] ?? '') ?>"
                                                data-jo-vtype="<?= htmlspecialchars($job['vehicle_type'] ?? '') ?>"
                                                data-jo-mech="<?= htmlspecialchars($job['mechanic_name'] ?? 'Unassigned') ?>"
                                                onclick="return openRequestAdjustModal(event, this);"
                                                class="txn-btn secondary" style="width:100%;padding:4px 6px;font-size:10px;box-sizing:border-box;text-align:center;justify-content:center;cursor:pointer;">
                                            <i class="fas fa-sliders-h"></i> Request Adjust
                                        </button>
                                        <button type="button"
                                                data-jo-id="<?= (int)$job['id'] ?>"
                                                data-jo-source="<?= htmlspecialchars($job['_source'] ?? 'job_orders') ?>"
                                                data-jo-ref="<?= htmlspecialchars($job['job_order_id'] ?? ('#'.$job['id'])) ?>"
                                                data-jo-customer="<?= htmlspecialchars($job['customer_name'] ?? '—') ?>"
                                                data-jo-status="<?= htmlspecialchars($wf_label) ?>"
                                                data-jo-paystatus="<?= htmlspecialchars($pay_label) ?>"
                                                onclick="return openRequestVoidModal(event, this);"
                                                class="txn-btn danger" style="width:100%;padding:4px 6px;font-size:10px;box-sizing:border-box;text-align:center;justify-content:center;background:#dc2626;color:#fff;border:none;cursor:pointer;">
                                            <i class="fas fa-ban"></i> Request Void
                                        </button>
                                    <?php endif; ?>

                                <?php elseif ($wf_status === 'Released'): ?>
                                    <!-- RELEASED Status: View, Reprint (NO Request Adjust, NO Request Void) -->
                                    <button type="button"
                                            onclick="printJobOrderReceipt(<?= (int)$job['id'] ?>,'<?= addslashes($job['job_order_id'] ?? ('#'.$job['id'])) ?>')"
                                            class="txn-btn secondary" style="width:100%;padding:4px 6px;font-size:10.5px;box-sizing:border-box;text-align:center;justify-content:center;">
                                        <i class="fas fa-print"></i> Reprint
                                    </button>

                                <?php elseif ($wf_status === 'Completed'): ?>
                                    <!-- COMPLETED Status: View, Reprint, Mark Paid/Settle, Release Vehicle, Request Adjust, Request Void -->
                                    <button type="button"
                                            onclick="printJobOrderReceipt(<?= (int)$job['id'] ?>,'<?= addslashes($job['job_order_id'] ?? ('#'.$job['id'])) ?>')"
                                            class="txn-btn secondary" style="width:100%;padding:4px 6px;font-size:10.5px;box-sizing:border-box;text-align:center;justify-content:center;">
                                        <i class="fas fa-print"></i> Reprint
                                    </button>

                                    <?php if ($pay_status !== 'Paid'): ?>
                                        <button type="button"
                                                onclick="openPaymentModal(<?= (int)$job['id'] ?>,'<?= addslashes($job['_source'] ?? 'job_orders') ?>',<?= $jo_total ?>,<?= $jo_paid ?>,<?= $jo_balance ?>,'<?= addslashes($job['customer_name'] ?? '') ?>',false,'tracker')"
                                                class="txn-btn success" style="width:100%;padding:4px 6px;font-size:10.5px;box-sizing:border-box;text-align:center;justify-content:center;">
                                            <i class="fas fa-money-bill-wave"></i>
                                            <?= in_array($pay_status, ['Partially Paid','Partial Payment','Partial']) ? 'Settle Balance' : 'Mark Paid' ?>
                                        </button>
                                    <?php endif; ?>

                                    <!-- RELEASE VEHICLE BUTTON -->
                                    <form method="POST" action="staff_transactions_hub.php?section=merchandise&active_tab=tracker" style="margin:0;width:100%;" onsubmit="return confirm('Are you sure you want to mark this Job Order as Released to customer?');">
                                        <input type="hidden" name="jo_action" value="release_job_order">
                                        <input type="hidden" name="jo_id" value="<?= (int)$job['id'] ?>">
                                        <input type="hidden" name="jo_source" value="<?= htmlspecialchars($job['_source'] ?? 'job_orders') ?>">
                                        <button type="submit" 
                                                class="txn-btn primary" style="width:100%;padding:4px 6px;font-size:10.5px;box-sizing:border-box;text-align:center;justify-content:center;background:#002F70;color:#fff;border:none;">
                                            <i class="fas fa-car-side"></i> Release Vehicle
                                        </button>
                                    </form>

                                    <button type="button"
                                            data-jo-id="<?= (int)$job['id'] ?>"
                                            data-jo-source="<?= htmlspecialchars($job['_source'] ?? 'job_orders') ?>"
                                            data-jo-ref="<?= htmlspecialchars($job['job_order_id'] ?? ('#'.$job['id'])) ?>"
                                            data-jo-customer="<?= htmlspecialchars($job['customer_name'] ?? '—') ?>"
                                            data-jo-status="<?= htmlspecialchars($wf_label) ?>"
                                            data-jo-paystatus="<?= htmlspecialchars($pay_label) ?>"
                                            data-jo-paymethod="<?= htmlspecialchars($pay_method) ?>"
                                            data-jo-total="<?= (float)$jo_total ?>"
                                            data-jo-labor="<?= (float)$labor_fee_val ?>"
                                            data-jo-paid="<?= (float)$jo_paid ?>"
                                            data-jo-service="<?= htmlspecialchars($job['service_type'] ?? '') ?>"
                                            data-jo-plate="<?= htmlspecialchars($job['vehicle_plate'] ?? '') ?>"
                                            data-jo-vtype="<?= htmlspecialchars($job['vehicle_type'] ?? '') ?>"
                                            data-jo-mech="<?= htmlspecialchars($job['mechanic_name'] ?? 'Unassigned') ?>"
                                            onclick="return openRequestAdjustModal(event, this);"
                                            class="txn-btn secondary" style="width:100%;padding:4px 6px;font-size:10px;box-sizing:border-box;text-align:center;justify-content:center;cursor:pointer;">
                                        <i class="fas fa-sliders-h"></i> Request Adjust
                                    </button>
                                    <button type="button"
                                            data-jo-id="<?= (int)$job['id'] ?>"
                                            data-jo-source="<?= htmlspecialchars($job['_source'] ?? 'job_orders') ?>"
                                            data-jo-ref="<?= htmlspecialchars($job['job_order_id'] ?? ('#'.$job['id'])) ?>"
                                            data-jo-customer="<?= htmlspecialchars($job['customer_name'] ?? '—') ?>"
                                            data-jo-status="<?= htmlspecialchars($wf_label) ?>"
                                            data-jo-paystatus="<?= htmlspecialchars($pay_label) ?>"
                                            onclick="return openRequestVoidModal(event, this);"
                                            class="txn-btn danger" style="width:100%;padding:4px 6px;font-size:10px;box-sizing:border-box;text-align:center;justify-content:center;background:#dc2626;color:#fff;border:none;cursor:pointer;">
                                        <i class="fas fa-ban"></i> Request Void
                                    </button>

                                <?php elseif ($wf_status === 'In Progress'): ?>
                                    <!-- IN PROGRESS Status: View, Complete, Request Adjust, Request Void -->
                                    <?php if ($pay_status === 'Paid'): ?>
                                        <form method="POST" action="staff_transactions_hub.php?section=merchandise&active_tab=tracker" style="margin:0;width:100%;">
                                            <input type="hidden" name="jo_action" value="set_completed">
                                            <input type="hidden" name="jo_id" value="<?= (int)$job['id'] ?>">
                                            <input type="hidden" name="jo_source" value="<?= htmlspecialchars($job['_source'] ?? 'job_orders') ?>">
                                            <button type="submit" 
                                                    class="txn-btn success" style="width:100%;padding:4px 6px;font-size:10.5px;box-sizing:border-box;text-align:center;justify-content:center;">
                                                <i class="fas fa-check"></i> Mark Complete
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <button type="button"
                                                onclick="openPaymentModal(<?= (int)$job['id'] ?>,'<?= addslashes($job['_source'] ?? 'job_orders') ?>',<?= $jo_total ?>,<?= $jo_paid ?>,<?= $jo_balance ?>,'<?= addslashes($job['customer_name'] ?? '') ?>',true,'tracker')"
                                                class="txn-btn success" style="width:100%;padding:4px 6px;font-size:10.5px;box-sizing:border-box;text-align:center;justify-content:center;">
                                            <i class="fas fa-check"></i> Complete & Settle
                                        </button>
                                    <?php endif; ?>

                                    <button type="button"
                                            data-jo-id="<?= (int)$job['id'] ?>"
                                            data-jo-source="<?= htmlspecialchars($job['_source'] ?? 'job_orders') ?>"
                                            data-jo-ref="<?= htmlspecialchars($job['job_order_id'] ?? ('#'.$job['id'])) ?>"
                                            data-jo-customer="<?= htmlspecialchars($job['customer_name'] ?? '—') ?>"
                                            data-jo-status="<?= htmlspecialchars($wf_label) ?>"
                                            data-jo-paystatus="<?= htmlspecialchars($pay_label) ?>"
                                            data-jo-paymethod="<?= htmlspecialchars($pay_method) ?>"
                                            data-jo-total="<?= (float)$jo_total ?>"
                                            data-jo-labor="<?= (float)$labor_fee_val ?>"
                                            data-jo-paid="<?= (float)$jo_paid ?>"
                                            data-jo-service="<?= htmlspecialchars($job['service_type'] ?? '') ?>"
                                            data-jo-plate="<?= htmlspecialchars($job['vehicle_plate'] ?? '') ?>"
                                            data-jo-vtype="<?= htmlspecialchars($job['vehicle_type'] ?? '') ?>"
                                            data-jo-mech="<?= htmlspecialchars($job['mechanic_name'] ?? 'Unassigned') ?>"
                                            onclick="return openRequestAdjustModal(event, this);"
                                            class="txn-btn secondary" style="width:100%;padding:4px 6px;font-size:10px;box-sizing:border-box;text-align:center;justify-content:center;cursor:pointer;">
                                        <i class="fas fa-sliders-h"></i> Request Adjust
                                    </button>
                                    <button type="button"
                                            data-jo-id="<?= (int)$job['id'] ?>"
                                            data-jo-source="<?= htmlspecialchars($job['_source'] ?? 'job_orders') ?>"
                                            data-jo-ref="<?= htmlspecialchars($job['job_order_id'] ?? ('#'.$job['id'])) ?>"
                                            data-jo-customer="<?= htmlspecialchars($job['customer_name'] ?? '—') ?>"
                                            data-jo-status="<?= htmlspecialchars($wf_label) ?>"
                                            data-jo-paystatus="<?= htmlspecialchars($pay_label) ?>"
                                            onclick="return openRequestVoidModal(event, this);"
                                            class="txn-btn danger" style="width:100%;padding:4px 6px;font-size:10px;box-sizing:border-box;text-align:center;justify-content:center;background:#dc2626;color:#fff;border:none;cursor:pointer;">
                                        <i class="fas fa-ban"></i> Request Void
                                    </button>

                                <?php else: ?>
                                    <!-- Pending / Approved / Other initial states -->
                                    <?php if ($wf_status !== 'In Progress' && $val_status !== 'Pending Validation'): ?>
                                        <form method="POST" action="staff_transactions_hub.php?section=merchandise&active_tab=tracker" style="margin:0;width:100%;">
                                            <input type="hidden" name="jo_action" value="set_in_progress">
                                            <input type="hidden" name="jo_id" value="<?= (int)$job['id'] ?>">
                                            <input type="hidden" name="jo_source" value="<?= htmlspecialchars($job['_source'] ?? 'job_orders') ?>">
                                            <button type="submit" 
                                                    class="txn-btn primary" style="width:100%;padding:4px 6px;font-size:10.5px;box-sizing:border-box;text-align:center;justify-content:center;">
                                                <i class="fas fa-play"></i> Start In Progress
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <button type="button"
                                            data-jo-id="<?= (int)$job['id'] ?>"
                                            data-jo-source="<?= htmlspecialchars($job['_source'] ?? 'job_orders') ?>"
                                            data-jo-ref="<?= htmlspecialchars($job['job_order_id'] ?? ('#'.$job['id'])) ?>"
                                            data-jo-customer="<?= htmlspecialchars($job['customer_name'] ?? '—') ?>"
                                            data-jo-status="<?= htmlspecialchars($wf_label) ?>"
                                            data-jo-paystatus="<?= htmlspecialchars($pay_label) ?>"
                                            data-jo-paymethod="<?= htmlspecialchars($pay_method) ?>"
                                            data-jo-total="<?= (float)$jo_total ?>"
                                            data-jo-labor="<?= (float)$labor_fee_val ?>"
                                            data-jo-paid="<?= (float)$jo_paid ?>"
                                            data-jo-service="<?= htmlspecialchars($job['service_type'] ?? '') ?>"
                                            data-jo-plate="<?= htmlspecialchars($job['vehicle_plate'] ?? '') ?>"
                                            data-jo-vtype="<?= htmlspecialchars($job['vehicle_type'] ?? '') ?>"
                                            data-jo-mech="<?= htmlspecialchars($job['mechanic_name'] ?? 'Unassigned') ?>"
                                            onclick="return openRequestAdjustModal(event, this);"
                                            class="txn-btn secondary" style="width:100%;padding:4px 6px;font-size:10px;box-sizing:border-box;text-align:center;justify-content:center;cursor:pointer;">
                                        <i class="fas fa-sliders-h"></i> Request Adjust
                                    </button>
                                    <button type="button"
                                            data-jo-id="<?= (int)$job['id'] ?>"
                                            data-jo-source="<?= htmlspecialchars($job['_source'] ?? 'job_orders') ?>"
                                            data-jo-ref="<?= htmlspecialchars($job['job_order_id'] ?? ('#'.$job['id'])) ?>"
                                            data-jo-customer="<?= htmlspecialchars($job['customer_name'] ?? '—') ?>"
                                            data-jo-status="<?= htmlspecialchars($wf_label) ?>"
                                            data-jo-paystatus="<?= htmlspecialchars($pay_label) ?>"
                                            onclick="return openRequestVoidModal(event, this);"
                                            class="txn-btn danger" style="width:100%;padding:4px 6px;font-size:10px;box-sizing:border-box;text-align:center;justify-content:center;background:#dc2626;color:#fff;border:none;cursor:pointer;">
                                        <i class="fas fa-ban"></i> Request Void
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <!-- Pagination Footer -->
                <div style="display:flex; justify-content:space-between; align-items:center; padding:12px 20px; border-top:1px solid #e2e8f0; background:#fff; font-size:13px; color:#475569; border-radius:0 0 12px 12px;">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <label style="margin:0; font-weight:400;">Rows per page:</label>
                        <select id="joPerPage" onchange="joChangePerPage()" class="pag-select">
                            <option value="10" selected>10</option>
                            <option value="20">20</option>
                            <option value="30">30</option>
                            <option value="40">40</option>
                            <option value="50">50</option>
                        </select>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <button id="joPrevBtn" onclick="joGoPage(joState.page - 1)" class="pag-btn">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <span id="joPageLabel" style="color:#475569; font-size:13px; padding:0 4px;">Page 1 of 1</span>
                        <button id="joNextBtn" onclick="joGoPage(joState.page + 1)" class="pag-btn">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <script>
        var joState = {
            status:      'all',
            type:        'all',
            startDate:   '',
            endDate:     '',
            mechanic:    '',
            serviceType: '',
            search:      '',
            page:    1,
            per_page: 10
        };

        function joApplyFilters() {
            var typeEl    = document.getElementById('joFilterType');
            var startEl   = document.getElementById('joFilterStartDate');
            var endEl     = document.getElementById('joFilterEndDate');
            var statusEl  = document.getElementById('joFilterStatus');
            var mechEl    = document.getElementById('joFilterMechanic');
            var svcEl     = document.getElementById('joFilterServiceType');
            var searchEl  = document.getElementById('joSearchInput');

            joState.type        = typeEl    ? typeEl.value    : 'all';
            joState.startDate   = startEl   ? startEl.value   : '';
            joState.endDate     = endEl     ? endEl.value     : '';
            joState.status      = statusEl  ? statusEl.value  : 'all';
            joState.mechanic    = mechEl    ? mechEl.value    : '';
            joState.serviceType = svcEl     ? svcEl.value     : '';
            joState.search      = searchEl  ? searchEl.value.trim().toLowerCase() : '';
            joState.page = 1;
            joRenderTable();
        }

        function joResetFilters() {
            var ids = ['joFilterType','joFilterStatus','joFilterMechanic','joFilterServiceType'];
            ids.forEach(function(id) {
                var el = document.getElementById(id);
                if (el) el.value = el.options[0].value;
            });
            ['joFilterStartDate','joFilterEndDate'].forEach(function(id) {
                var el = document.getElementById(id);
                if (el) el.value = '';
            });
            var searchEl = document.getElementById('joSearchInput');
            if (searchEl) searchEl.value = '';
            joState.type = 'all'; joState.status = 'all';
            joState.startDate = ''; joState.endDate = '';
            joState.mechanic = ''; joState.serviceType = '';
            joState.search = '';
            joState.page = 1;
            joRenderTable();
        }

        function joRenderTable() {
            var rows = document.querySelectorAll('#joUnifiedTable tbody tr');
            var visibleRows = [];

            rows.forEach(function(row) {
                var rowFilter   = row.getAttribute('data-jo-filter')   || '';
                var rowSource   = row.getAttribute('data-jo-source')   || '';
                var rowMechanic = row.getAttribute('data-jo-mechanic') || '';
                var rowService  = row.getAttribute('data-jo-service')  || '';
                var rowDate     = row.getAttribute('data-jo-date')     || '';

                // Status filter
                var statusOk = (joState.status === 'all' || rowFilter === joState.status);

                // Type filter: job_order = job_order type, combined = combined type
                var rowType = row.getAttribute('data-jo-type') || 'job_order';
                var typeOk = (joState.type === 'all' || rowType === joState.type);

                // Date range filter
                var dateOk = true;
                if (joState.startDate && rowDate) dateOk = dateOk && (rowDate >= joState.startDate);
                if (joState.endDate   && rowDate) dateOk = dateOk && (rowDate <= joState.endDate);

                // Mechanic filter (exact match)
                var mechOk = (!joState.mechanic || rowMechanic === joState.mechanic);

                // Service type filter (exact match)
                var svcOk = (!joState.serviceType || rowService === joState.serviceType);

                // Search filter
                var searchOk = true;
                if (joState.search) {
                    var rowSearchData = row.getAttribute('data-jo-search') || '';
                    searchOk = rowSearchData.indexOf(joState.search) !== -1;
                }

                if (statusOk && typeOk && dateOk && mechOk && svcOk && searchOk) {
                    visibleRows.push(row);
                } else {
                    row.style.display = 'none';
                }
            });

            // Pagination
            var total      = visibleRows.length;
            var perPage    = joState.per_page;

            var foot = document.getElementById('joPerPage') ? document.getElementById('joPerPage').closest('div[style*="display:flex"]') : null;
            if (foot) {
                foot.style.display = total <= 10 ? 'none' : 'flex';
            }
            if (total <= 10) {
                visibleRows.forEach(function(row) { row.style.display = ''; });
                return;
            }

            var totalPages = Math.max(1, Math.ceil(total / perPage));
            if (joState.page > totalPages) { joState.page = totalPages; }


            var start = (joState.page - 1) * perPage;
            var end   = start + perPage;

            visibleRows.forEach(function(row, index) {
                row.style.display = (index >= start && index < end) ? '' : 'none';
            });

            var lbl = document.getElementById('joPageLabel');
            if (lbl) lbl.textContent = 'Page ' + joState.page + ' of ' + totalPages;

            var prev = document.getElementById('joPrevBtn');
            var next = document.getElementById('joNextBtn');
            if (prev) { prev.disabled = (joState.page <= 1); prev.style.opacity = prev.disabled ? '0.5' : '1'; }
            if (next) { next.disabled = (joState.page >= totalPages); next.style.opacity = next.disabled ? '0.5' : '1'; }
        }

        function joGoPage(p) { joState.page = p; joRenderTable(); }

        function joChangePerPage() {
            var sel = document.getElementById('joPerPage');
            if (sel) { joState.per_page = parseInt(sel.value, 10); joState.page = 1; joRenderTable(); }
        }

                document.addEventListener('DOMContentLoaded', function() { joRenderTable(); });

        // ── 10-SECOND REAL-TIME AUTO REFRESH FOR JOB ORDER TRACKER ───────────
        async function autoRefreshJobOrderTracker() {
            // Do not refresh if user has any modal open
            const modals = ['paymentSettleModal', 'viewJobOrderModal', 'updateStatusModal', 'adjustJobOrderModal', 'txnRequestModal', 'requestAdjustModal', 'requestVoidModal'];
            for (let mId of modals) {
                const m = document.getElementById(mId);
                if (m && (m.style.display === 'flex' || m.style.display === 'block')) return;
            }

            try {
                const resp = await fetch('staff_transactions_hub.php?section=merchandise&active_tab=tracker&ajax_tracker=1');
                if (!resp.ok) return;
                const data = await resp.json();

                if (data.kpis) {
                    if (document.getElementById('jo_kpi_total_txns')) document.getElementById('jo_kpi_total_txns').textContent = data.kpis.total_txns;
                    if (document.getElementById('jo_kpi_total_sales')) document.getElementById('jo_kpi_total_sales').textContent = data.kpis.total_sales;
                    if (document.getElementById('jo_kpi_completed_jo')) document.getElementById('jo_kpi_completed_jo').textContent = data.kpis.completed_jo;
                    if (document.getElementById('jo_kpi_merch_sold')) document.getElementById('jo_kpi_merch_sold').textContent = data.kpis.merch_sold;
                }

                if (typeof data.jo_count !== 'undefined') {
                    const rows = document.querySelectorAll('#joUnifiedTable tbody tr.jo-data-row');
                    const noRecRow = document.getElementById('joNoRecordsRow');
                    const currentCount = rows.length;
                    if (data.jo_count !== currentCount) {
                        window.location.reload();
                    }
                }
            } catch (e) {
                console.warn('Job Order Tracker refresh notice:', e);
            }
        }

        // Run auto-refresh every 10 seconds
        setInterval(autoRefreshJobOrderTracker, 10000);
        </script>

        </div><!-- /innerTab_tracker -->

        <!-- ══════════════════════════════════════════════════════════
             PAYMENT SETTLEMENT MODAL  (v2 — clean per-method fields)
        ══════════════════════════════════════════════════════════ -->
        <style>
        @keyframes pmSlideIn{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:translateY(0)}}
        .pm-method-btn{flex:1;padding:8px 4px;border:1.5px solid #e2e8f0;border-radius:8px;
            background:#f8fafc;font-size:11px;font-weight:600;color:#64748b;cursor:pointer;text-align:center;
            transition:all .15s;line-height:1.4;}
        .pm-method-btn.active{border-color:#003d7a;background:#eff6ff;color:#003d7a;}
        .pm-method-btn:hover:not(.active){border-color:#94a3b8;background:#f1f5f9;}
        .pm-label{font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:4px;
            text-transform:uppercase;letter-spacing:.3px;}
        .pm-input{width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;
            color:#1e293b;background:#fff;outline:none;box-sizing:border-box;transition:border-color .15s;}
        .pm-input:focus{border-color:#003d7a;}
        .pm-row{margin-bottom:13px;}
        </style>
        <div id="paymentSettleModal" style="display:none;position:fixed;inset:0;z-index:9999;
             background:rgba(0,0,0,.5);align-items:center;justify-content:center;">
          <div style="background:#fff;border-radius:16px;box-shadow:0 24px 64px rgba(0,0,0,.3);
                      width:100%;max-width:450px;margin:16px;overflow:hidden;animation:pmSlideIn .18s ease;">

            <!-- Header -->
            <div style="background:linear-gradient(135deg,#003d7a,#0369a1);padding:15px 20px;
                        display:flex;align-items:center;justify-content:space-between;">
              <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:34px;height:34px;background:rgba(255,255,255,.15);border-radius:8px;
                            display:flex;align-items:center;justify-content:center;">
                  <i id="pmHeaderIcon" class="fas fa-receipt" style="color:#fff;font-size:15px;"></i>
                </div>
                <div>
                  <div id="pmModalTitle" style="color:#fff;font-weight:700;font-size:14px;">Payment Settlement</div>
                  <div id="pmModalCustomer" style="color:#93c5fd;font-size:11px;margin-top:1px;"></div>
                </div>
              </div>
              <button onclick="closePaymentModal()" style="background:rgba(255,255,255,.15);border:none;color:#fff;
                      width:28px;height:28px;border-radius:6px;font-size:17px;cursor:pointer;
                      display:flex;align-items:center;justify-content:center;"
                      onmouseover="this.style.background='rgba(255,255,255,.28)'"
                      onmouseout="this.style.background='rgba(255,255,255,.15)'">&times;</button>
            </div>

            <!-- Balance Strip -->
            <div style="background:#f0f7ff;border-bottom:1px solid #dbeafe;padding:11px 20px;
                        display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;text-align:center;">
              <div>
                <div style="font-size:9px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px;">Grand Total</div>
                <div style="font-size:15px;font-weight:800;color:#003d7a;">₱<span id="pmTotal">0.00</span></div>
              </div>
              <div>
                <div style="font-size:9px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px;">Already Paid</div>
                <div style="font-size:15px;font-weight:800;color:#166534;">₱<span id="pmAlreadyPaid">0.00</span></div>
              </div>
              <div id="pmBalanceCell" style="border-radius:8px;padding:3px 6px;">
                <div id="pmBalanceLbl" style="font-size:9px;text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px;">Balance Due</div>
                <div style="font-size:15px;font-weight:800;" id="pmBalanceDueWrap">₱<span id="pmBalanceDue">0.00</span></div>
              </div>
            </div>

            <!-- Complete notice -->
            <div id="pmCompleteNotice" style="display:none;background:#f0fdf4;border-bottom:1px solid #bbf7d0;
                 padding:8px 20px;font-size:12px;color:#166534;align-items:center;gap:7px;">
              <i class="fas fa-check-circle"></i>
              <span>This will also mark the Job Order as <strong>Completed</strong>.</span>
            </div>

            <!-- Form -->
            <form id="paymentSettleForm" method="POST"
                  action="staff_transactions_hub.php?section=merchandise"
                  style="padding:16px 20px 20px;">
              <input type="hidden" name="jo_action"               value="settle_payment">
              <input type="hidden" name="jo_id"                   id="pmJoId"         value="">
              <input type="hidden" name="jo_source"               id="pmJoSource"     value="">
              <input type="hidden" name="redirect_tab"            id="pmRedirectTab"  value="tracker">
              <input type="hidden" name="mark_complete_on_settle" id="pmMarkComplete" value="">
              <input type="hidden" name="settle_method"           id="pmMethodHidden" value="Cash">

              <!-- Amount -->
              <div class="pm-row">
                <label class="pm-label">Amount to Pay Now <span style="color:#dc2626;">*</span></label>
                <div style="display:flex;gap:6px;">
                  <div id="pmAmountWrap" style="display:flex;align-items:center;border:1.5px solid #d1d5db;
                       border-radius:8px;overflow:hidden;flex:1;transition:border-color .15s;">
                    <span style="background:#f3f4f6;padding:9px 11px;font-weight:700;color:#374151;
                                 border-right:1px solid #d1d5db;font-size:13px;">₱</span>
                    <input type="number" id="pmAmountInput" name="settle_amount"
                           step="0.01" min="0.01" placeholder="0.00" oninput="pmRecalc()"
                           onfocus="document.getElementById('pmAmountWrap').style.borderColor='#003d7a'"
                           onblur="document.getElementById('pmAmountWrap').style.borderColor='#d1d5db'"
                           style="flex:1;border:none;padding:9px 10px;font-size:16px;font-weight:700;
                                  color:#003d7a;outline:none;background:#fff;min-width:0;">
                  </div>
                  <button type="button" onclick="pmSetFull()"
                          style="padding:0 13px;background:#003d7a;color:#fff;border:none;border-radius:8px;
                                 font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap;flex-shrink:0;line-height:1.3;">
                    Full<br>Amount
                  </button>
                </div>
              </div>

              <!-- Payment Method buttons -->
              <div class="pm-row">
                <label class="pm-label">Payment Method</label>
                <div style="display:flex;gap:5px;flex-wrap:wrap;">
                  <button type="button" class="pm-method-btn active" data-method="Cash"        onclick="pmSelectMethod('Cash')">
                    <i class="fas fa-money-bill-wave" style="display:block;font-size:13px;margin-bottom:2px;color:#16a34a;"></i>Cash
                  </button>
                  <button type="button" class="pm-method-btn"        data-method="Card"        onclick="pmSelectMethod('Card')">
                    <i class="fas fa-credit-card"     style="display:block;font-size:13px;margin-bottom:2px;color:#003d7a;"></i>Card
                  </button>
                  <button type="button" class="pm-method-btn"        data-method="E-Wallet"    onclick="pmSelectMethod('E-Wallet')">
                    <i class="fas fa-mobile-alt"      style="display:block;font-size:13px;margin-bottom:2px;color:#7c3aed;"></i>E-Wallet
                  </button>
                  <button type="button" class="pm-method-btn"        data-method="Petron E-Fuel" onclick="pmSelectMethod('Petron E-Fuel')">
                    <i class="fas fa-gas-pump"        style="display:block;font-size:13px;margin-bottom:2px;color:#dc2626;"></i>E-Fuel
                  </button>
                  <button type="button" class="pm-method-btn"        data-method="Fleet Card"  onclick="pmSelectMethod('Fleet Card')">
                    <i class="fas fa-truck"           style="display:block;font-size:13px;margin-bottom:2px;color:#0284c7;"></i>Fleet
                  </button>
                  <button type="button" class="pm-method-btn"        data-method="Credit"      onclick="pmSelectMethod('Credit')">
                    <i class="fas fa-file-invoice-dollar" style="display:block;font-size:13px;margin-bottom:2px;color:#92400e;"></i>Credit
                  </button>
                </div>
              </div>

              <!-- Cash: tendered + change -->
              <div id="pmCashFields" class="pm-row">
                <label class="pm-label">Amount Tendered <span style="font-weight:400;text-transform:none;letter-spacing:0;color:#94a3b8;">(optional)</span></label>
                <div style="display:flex;align-items:center;border:1.5px solid #d1d5db;border-radius:8px;overflow:hidden;max-width:200px;">
                  <span style="background:#f3f4f6;padding:9px 11px;font-weight:700;color:#374151;border-right:1px solid #d1d5db;font-size:13px;">₱</span>
                  <input type="number" id="pmTendered" step="0.01" min="0" placeholder="0.00"
                         oninput="pmCalcChange()"
                         style="flex:1;border:none;padding:9px 10px;font-size:14px;color:#1e293b;outline:none;background:#fff;">
                </div>
                <div id="pmChangeRow" style="display:none;margin-top:7px;padding:8px 12px;
                     background:#dcfce7;border:1px solid #86efac;border-radius:7px;
                     font-size:13px;color:#166534;font-weight:700;display:none;">
                  <i class="fas fa-coins" style="margin-right:6px;"></i>Change (Sukli): ₱<span id="pmChangeAmt">0.00</span>
                </div>
              </div>

              <!-- Card / E-Wallet / E-Fuel: reference -->
              <div id="pmRefFields" style="display:none;" class="pm-row">
                <label class="pm-label" id="pmRefLabel">Reference Number</label>
                <input type="text" id="pmRefInput" name="settle_reference" class="pm-input"
                       placeholder="e.g. ref #12345…">
              </div>

              <!-- Credit: note -->
              <div id="pmCreditFields" style="display:none;" class="pm-row">
                <div style="padding:10px 13px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;
                            font-size:12px;color:#92400e;">
                  <i class="fas fa-exclamation-triangle" style="margin-right:6px;"></i>
                  <strong>Credit (Utang)</strong> — balance will remain as receivable.
                </div>
              </div>

              <!-- Remarks -->
              <div class="pm-row">
                <label class="pm-label">Remarks <span style="font-weight:400;text-transform:none;letter-spacing:0;color:#94a3b8;">(optional)</span></label>
                <input type="text" id="pmRemarks" name="settle_remarks" class="pm-input"
                       placeholder="e.g. Final payment, GCash ref #12345…">
              </div>

              <!-- Live preview -->
              <div id="pmPreview" style="display:none;background:#f8fafc;border:1px solid #e2e8f0;
                   border-radius:8px;padding:10px 14px;margin-bottom:14px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">
                  <span style="font-size:11px;color:#64748b;">New Balance Due</span>
                  <strong id="pmPreviewBalance" style="font-size:14px;color:#9a3412;">—</strong>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;">
                  <span style="font-size:11px;color:#64748b;">Payment Status</span>
                  <span id="pmPreviewStatusBadge" style="font-size:11px;font-weight:700;padding:3px 10px;
                        border-radius:20px;background:#fef9c3;color:#92400e;border:1px solid #fde68a;">—</span>
                </div>
              </div>

              <!-- Buttons -->
              <div style="display:flex;gap:8px;">
                <button type="submit" id="pmSubmitBtn"
                        style="flex:1;padding:11px;background:#003d7a;color:#fff;border:none;border-radius:8px;
                               font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;
                               justify-content:center;gap:7px;">
                  <i class="fas fa-check-circle"></i><span id="pmSubmitLabel">Confirm Payment</span>
                </button>
                <button type="button" onclick="closePaymentModal()"
                        style="padding:11px 16px;background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;
                               border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">
                  Cancel
                </button>
              </div>
            </form>
          </div>
        </div>

        <script>
        // ── Payment Settlement Modal JS ───────────────────────────────────────
        var _pmBalance = 0;
        var _pmMethod  = 'Cash';

        function openPaymentModal(joId, joSource, total, alreadyPaid, balanceDue, customerName, markComplete, redirectTab) {
            _pmBalance = parseFloat(balanceDue) || 0;
            var fmt = function(n){ return parseFloat(n).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}); };

            document.getElementById('pmJoId').value         = joId;
            document.getElementById('pmJoSource').value     = joSource;
            document.getElementById('pmRedirectTab').value  = redirectTab || 'tracker';
            document.getElementById('pmMarkComplete').value = markComplete ? '1' : '';
            document.getElementById('pmTotal').textContent       = fmt(total);
            document.getElementById('pmAlreadyPaid').textContent = fmt(alreadyPaid);
            document.getElementById('pmBalanceDue').textContent  = fmt(balanceDue);

            // Colour balance cell
            var cell = document.getElementById('pmBalanceCell');
            var lbl  = document.getElementById('pmBalanceLbl');
            var val  = document.getElementById('pmBalanceDueWrap');
            if (_pmBalance > 0.009) {
                cell.style.background = '#fff3cd'; lbl.style.color = '#92400e'; val.style.color = '#9a3412';
            } else {
                cell.style.background = '#dcfce7'; lbl.style.color = '#166534'; val.style.color = '#166534';
            }

            // Auto-fill amount = balance due; tendered = balance due (staff just edits if different)
            document.getElementById('pmAmountInput').value = _pmBalance > 0.009 ? _pmBalance.toFixed(2) : '';
            document.getElementById('pmTendered').value    = _pmBalance > 0.009 ? _pmBalance.toFixed(2) : '';
            document.getElementById('pmRefInput').value    = '';
            document.getElementById('pmRemarks').value     = '';
            document.getElementById('pmChangeRow').style.display = 'none';
            document.getElementById('pmPreview').style.display   = 'none';

            // Complete notice
            var notice = document.getElementById('pmCompleteNotice');
            notice.style.display = markComplete ? 'flex' : 'none';

            // Title / icon / submit label
            var title = markComplete ? 'Complete & Settle Payment'
                      : (_pmBalance > 0.009 ? 'Settle Balance' : 'Record Payment');
            document.getElementById('pmModalTitle').textContent    = title;
            document.getElementById('pmModalCustomer').textContent = customerName ? 'Customer: ' + customerName : '';
            document.getElementById('pmHeaderIcon').className      = markComplete ? 'fas fa-check-circle' : 'fas fa-receipt';
            document.getElementById('pmSubmitLabel').textContent   = markComplete ? 'Complete & Confirm' : 'Confirm Payment';

            pmSelectMethod('Cash');
            document.getElementById('paymentSettleModal').style.display = 'flex';
            // Focus tendered for Cash (staff just types what customer hands over)
            setTimeout(function(){
                var focus = _pmMethod === 'Cash'
                    ? document.getElementById('pmTendered')
                    : document.getElementById('pmAmountInput');
                if (focus) focus.select();
            }, 80);
        }

        function closePaymentModal() {
            document.getElementById('paymentSettleModal').style.display = 'none';
        }

        function pmSelectMethod(method) {
            _pmMethod = method;
            document.getElementById('pmMethodHidden').value = method;
            document.querySelectorAll('.pm-method-btn').forEach(function(b){
                b.classList.toggle('active', b.dataset.method === method);
            });
            document.getElementById('pmCashFields').style.display   = method === 'Cash'     ? 'block' : 'none';
            document.getElementById('pmRefFields').style.display    = ['Card','E-Wallet','E-Fuel Card','Petron E-Fuel','Fleet Card'].includes(method) ? 'block' : 'none';
            document.getElementById('pmCreditFields').style.display = method === 'Credit'   ? 'block' : 'none';
            var labels = {
                'Card': 'Card Reference No.',
                'E-Wallet': 'E-Wallet Ref No. (GCash/Maya)',
                'E-Fuel Card': 'E-Fuel Card ID',
                'Petron E-Fuel': 'E-Fuel Card ID',
                'Fleet Card': 'Fleet Card No.'
            };
            if (labels[method]) document.getElementById('pmRefLabel').textContent = labels[method];
            pmRecalc();
        }

        function pmSetFull() {
            document.getElementById('pmAmountInput').value = _pmBalance.toFixed(2);
            if (_pmMethod === 'Cash') {
                document.getElementById('pmTendered').value = _pmBalance.toFixed(2);
                pmCalcChange();
            }
            pmRecalc();
        }

        function pmCalcChange() {
            var tendered = parseFloat(document.getElementById('pmTendered').value) || 0;
            var amount   = parseFloat(document.getElementById('pmAmountInput').value) || _pmBalance;
            var change   = tendered - amount;
            var row      = document.getElementById('pmChangeRow');
            if (tendered > 0 && change >= 0) {
                document.getElementById('pmChangeAmt').textContent =
                    change.toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
                row.style.display = 'block';
            } else {
                row.style.display = 'none';
            }
        }

        function pmRecalc() {
            var amount  = parseFloat(document.getElementById('pmAmountInput').value) || 0;
            var preview = document.getElementById('pmPreview');
            if (amount <= 0) { preview.style.display = 'none'; return; }
            var newBal  = Math.max(0, _pmBalance - amount);
            var isPaid  = newBal <= 0.009;
            var status  = isPaid ? 'Paid' : 'Partially Paid';
            var fmt = function(n){ return '₱'+parseFloat(n).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}); };
            document.getElementById('pmPreviewBalance').textContent = fmt(newBal);
            document.getElementById('pmPreviewBalance').style.color = isPaid ? '#166534' : '#9a3412';
            var badge = document.getElementById('pmPreviewStatusBadge');
            badge.textContent      = status;
            badge.style.background = isPaid ? '#dcfce7' : '#fef9c3';
            badge.style.color      = isPaid ? '#166534' : '#92400e';
            badge.style.border     = isPaid ? '1px solid #86efac' : '1px solid #fde68a';
            preview.style.display  = 'block';
            if (_pmMethod === 'Cash') pmCalcChange();
        }

        document.getElementById('paymentSettleModal').addEventListener('click', function(e){
            if (e.target === this) closePaymentModal();
        });

        document.getElementById('paymentSettleForm').addEventListener('submit', function(e){
            var amount = parseFloat(document.getElementById('pmAmountInput').value) || 0;
            if (amount <= 0) {
                e.preventDefault();
                var wrap = document.getElementById('pmAmountWrap');
                wrap.style.borderColor = '#dc2626';
                document.getElementById('pmAmountInput').focus();
                setTimeout(function(){ wrap.style.borderColor = '#d1d5db'; }, 2000);
            }
        });
        </script>

        <!-- ══════════════════════════════════════════════════════════
             JOB ORDER DETAIL MODALS (View / Update Status / Adjust)
        ══════════════════════════════════════════════════════════ -->
        
        <!-- View Job Order Details Modal -->
        <div id="viewJobOrderModal" style="display:none;position:fixed;inset:0;z-index:9999;
             background:rgba(0,0,0,.5);align-items:center;justify-content:center;padding:75px 16px 50px 16px;box-sizing:border-box;overflow-y:auto;">
          <div style="background:#fff;border-radius:16px;box-shadow:0 24px 64px rgba(0,0,0,.3);
                      width:100%;max-width:600px;margin:auto;max-height:calc(100vh - 140px);display:flex;flex-direction:column;overflow:hidden;animation:pmSlideIn .18s ease;">
            <div style="background:#fff;border-bottom:1px solid #e2e8f0;padding:18px 24px;
                        display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
              <div style="display:flex;align-items:center;gap:12px;">
                <div style="width:36px;height:36px;background:#f0f7ff;border-radius:10px;
                            display:flex;align-items:center;justify-content:center;">
                  <i class="fas fa-clipboard-list" style="color:#002F70;font-size:16px;"></i>
                </div>
                <div>
                  <div style="color:#002F70;font-weight:700;font-size:15px;">Job Order Details</div>
                  <div id="viewJORef" style="color:#64748b;font-size:12px;margin-top:2px;"></div>
                </div>
              </div>
            </div>
            <div style="padding:20px 24px 24px 24px;flex:1;overflow-y:auto;">
              <!-- Transaction Information -->
              <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:#002F70;background:#f0f7ff;padding:8px 14px;margin:8px -24px 16px;border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;">
                <i class="fas fa-info-circle" style="margin-right:6px;"></i>Transaction Information
              </div>
              <div style="display:grid;gap:10px;">
                <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;">
                  <span style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">JO Number:</span>
                  <span id="viewJORef2" style="font-size:13px;color:#002F70;font-weight:700;">—</span>
                </div>
                <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;">
                  <span style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">OR Number:</span>
                  <span id="viewJOOrNo" style="font-size:12px;color:#475569;font-family:monospace;">—</span>
                </div>
                <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;">
                  <span style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Customer:</span>
                  <span id="viewJOCustomer" style="font-size:13px;color:#1e293b;font-weight:600;">—</span>
                </div>
                <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;">
                  <span style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Contact:</span>
                  <span id="viewJOContact" style="font-size:13px;color:#475569;">—</span>
                </div>
                <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;">
                  <span style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Vehicle:</span>
                  <span id="viewJOVehicle" style="font-size:13px;color:#475569;">—</span>
                </div>
                <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;">
                  <span style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Brand / Model:</span>
                  <span id="viewJOBrandModel" style="font-size:13px;color:#475569;font-weight:600;">—</span>
                </div>
                <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;">
                  <span style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Year Model:</span>
                  <span id="viewJOYearModel" style="font-size:13px;color:#475569;">—</span>
                </div>
                <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;">
                  <span style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Engine No.:</span>
                  <span id="viewJOEngineNo" style="font-size:13px;color:#475569;font-family:monospace;">—</span>
                </div>
                <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;">
                  <span style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Chassis No.:</span>
                  <span id="viewJOChassisNo" style="font-size:13px;color:#475569;font-family:monospace;">—</span>
                </div>
                <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;">
                  <span style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Mechanic:</span>
                  <span id="viewJOMechanic" style="font-size:13px;color:#475569;">—</span>
                </div>
                <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;">
                  <span style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Date Created:</span>
                  <span id="viewJOCreated" style="font-size:12px;color:#64748b;">—</span>
                </div>
              </div>
              <!-- Services -->
              <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:#002F70;background:#f0f7ff;padding:8px 14px;margin:20px -24px 16px;border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;">
                <i class="fas fa-wrench" style="margin-right:6px;"></i>Services
              </div>
              <div style="display:grid;gap:10px;">
                <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;">
                  <span style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Service Type:</span>
                  <span id="viewJOService" style="font-size:13px;color:#475569;">—</span>
                </div>
                <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;">
                  <span style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Est. Duration:</span>
                  <span id="viewJODuration" style="font-size:13px;color:#475569;">—</span>
                </div>
              </div>
              <!-- Merchandise -->
              <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:#002F70;background:#f0f7ff;padding:8px 14px;margin:20px -24px 16px;border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;">
                <i class="fas fa-box" style="margin-right:6px;"></i>Merchandise
              </div>
              <div style="display:grid;gap:10px;">
                <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;">
                  <span style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Parts / Items:</span>
                  <span id="viewJOParts" style="font-size:13px;color:#475569;">—</span>
                </div>
              </div>
              <!-- Remarks -->
              <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:#002F70;background:#f0f7ff;padding:8px 14px;margin:20px -24px 16px;border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;">
                <i class="fas fa-comment-alt" style="margin-right:6px;"></i>Remarks
              </div>
              <div style="display:grid;gap:10px;">
                <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;">
                  <span style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Notes:</span>
                  <span id="viewJORemarks" style="font-size:13px;color:#475569;word-break:break-word;">—</span>
                </div>
              </div>
              <!-- Payment -->
              <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:#002F70;background:#f0f7ff;padding:8px 14px;margin:20px -24px 16px;border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;">
                <i class="fas fa-credit-card" style="margin-right:6px;"></i>Payment
              </div>
              <div style="display:grid;gap:10px;">
                <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;">
                  <span style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Status:</span>
                  <span id="viewJOWorkflow" style="font-size:11px;font-weight:700;">—</span>
                </div>
                <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;">
                  <span style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Payment Status:</span>
                  <span id="viewJOPayment" style="font-size:11px;font-weight:700;">—</span>
                </div>
                <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;">
                  <span style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Payment Method:</span>
                  <span id="viewJOPayMethod" style="font-size:12px;color:#475569;font-weight:600;">—</span>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;padding-top:4px;">
                  <span style="font-size:12px;color:#64748b;font-weight:600;">Total Cost:</span>
                  <span id="viewJOTotal" style="font-size:16px;font-weight:800;color:#003d7a;">₱0.00</span>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;">
                  <span style="font-size:12px;color:#64748b;font-weight:600;">Amount Paid:</span>
                  <span id="viewJOPaid" style="font-size:14px;font-weight:700;color:#166534;">₱0.00</span>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;">
                  <span style="font-size:12px;color:#64748b;font-weight:600;">Balance Due:</span>
                  <span id="viewJOBalance" style="font-size:14px;font-weight:700;color:#dc2626;">₱0.00</span>
                </div>
              </div>
            </div>
            <div style="padding:16px 24px;border-top:1px solid #e2e8f0;background:#f8fafc;display:flex;justify-content:flex-end;align-items:center;gap:12px;flex-shrink:0;">
              <button onclick="closeViewJobOrderModal()" style="padding:0 24px !important;height:40px !important;background:#003d7a !important;color:#ffffff !important;
                      border:none !important;border-radius:8px !important;font-size:14px !important;font-weight:800 !important;cursor:pointer !important;
                      display:inline-flex !important;align-items:center !important;justify-content:center !important;transition:all 0.15s !important;min-width:110px !important;box-shadow:0 2px 6px rgba(0,61,122,.3) !important;"
                      onmouseover="this.style.background='#002855'"
                      onmouseout="this.style.background='#003d7a'">
                <i class="fas fa-times" style="margin-right:6px;color:#ffffff !important;"></i> Close
              </button>
            </div>
          </div>
        </div>
        <!-- View Merchandise Transaction Modal -->
        <div id="viewMerchandiseModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;z-index:999999;
             background:rgba(15,23,42,0.65);backdrop-filter:blur(3px);align-items:center;justify-content:center;padding:75px 16px 50px 16px;box-sizing:border-box;overflow-y:auto;">
          <div style="background:#fff;border-radius:16px;box-shadow:0 24px 64px rgba(0,0,0,.3);
                      width:100%;max-width:700px;margin:auto;max-height:calc(100vh - 140px);display:flex;flex-direction:column;overflow:hidden;animation:pmSlideIn .18s ease;">
            <div style="background:linear-gradient(135deg,#16a34a,#15803d);padding:22px 24px;
                        display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
              <div style="display:flex;align-items:center;gap:12px;">
                <div style="width:38px;height:38px;background:rgba(255,255,255,.18);border-radius:10px;
                            display:flex;align-items:center;justify-content:center;">
                  <i class="fas fa-shopping-cart" style="color:#fff;font-size:16px;"></i>
                </div>
                <div>
                  <div style="color:#fff;font-weight:700;font-size:14px;">Merchandise Transaction Details</div>
                  <div id="viewMTxnRef" style="color:#bbf7d0;font-size:11px;margin-top:1px;"></div>
                </div>
              </div>
              
            </div>
            <div style="padding:24px 24px 28px 24px;max-height:calc(100vh - 340px);overflow-y:auto;">
              <div style="display:grid;gap:14px;">
                <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;align-items:start;">
                  <span style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Customer:</span>
                  <span id="viewMTCustomer" style="font-size:13px;color:#1e293b;font-weight:600;">—</span>
                </div>
                <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;align-items:start;">
                  <span style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Shift:</span>
                  <span id="viewMTShift" style="font-size:13px;color:#475569;">—</span>
                </div>
                <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;align-items:start;">
                  <span style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Transaction Date:</span>
                  <span id="viewMTDate" style="font-size:13px;color:#475569;">—</span>
                </div>
                
                <!-- Items Table -->
                <div style="border-top:1px solid #e2e8f0;padding-top:14px;">
                  <div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">Items Purchased:</div>
                  <div id="viewMTItems" style="max-height:200px;overflow-y:auto;">
                    <table style="width:100%;font-size:12px;border-collapse:collapse;">
                      <thead style="background:#f8fafc;position:sticky;top:0;">
                        <tr>
                          <th style="text-align:left;padding:6px 8px;border-bottom:2px solid #e2e8f0;color:#64748b;font-weight:600;">Item</th>
                          <th style="text-align:center;padding:6px 8px;border-bottom:2px solid #e2e8f0;color:#64748b;font-weight:600;width:60px;">Qty</th>
                          <th style="text-align:right;padding:6px 8px;border-bottom:2px solid #e2e8f0;color:#64748b;font-weight:600;width:90px;">Unit Price</th>
                          <th style="text-align:right;padding:6px 8px;border-bottom:2px solid #e2e8f0;color:#64748b;font-weight:600;width:100px;">Subtotal</th>
                        </tr>
                      </thead>
                      <tbody id="viewMTItemsBody">
                        <tr><td colspan="4" style="text-align:center;padding:12px;color:#94a3b8;">Loading items...</td></tr>
                      </tbody>
                    </table>
                  </div>
                </div>
                
                <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;align-items:start;">
                  <span style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Payment Method:</span>
                  <span id="viewMTPayMethod" style="font-size:13px;color:#475569;">—</span>
                </div>
                <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;align-items:start;">
                  <span style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Payment Status:</span>
                  <span id="viewMTPayStatus" style="font-size:11px;font-weight:700;">—</span>
                </div>
                <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;align-items:start;">
                  <span style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Validation Status:</span>
                  <span id="viewMTValStatus" style="font-size:11px;font-weight:700;">—</span>
                </div>
                
                <div style="border-top:1px solid #e2e8f0;padding-top:14px;display:grid;gap:8px;">
                  <div style="display:flex;justify-content:space-between;align-items:center;">
                    <span style="font-size:12px;color:#64748b;font-weight:600;">Subtotal:</span>
                    <span id="viewMTSubtotal" style="font-size:14px;font-weight:700;color:#475569;">₱0.00</span>
                  </div>
                  <div style="display:flex;justify-content:space-between;align-items:center;">
                    <span style="font-size:12px;color:#64748b;font-weight:600;">VAT (12%):</span>
                    <span id="viewMTVAT" style="font-size:14px;font-weight:700;color:#475569;">₱0.00</span>
                  </div>
                  <div style="display:flex;justify-content:space-between;align-items:center;padding-top:8px;border-top:2px solid #e2e8f0;">
                    <span style="font-size:13px;color:#1e293b;font-weight:700;">Total Amount:</span>
                    <span id="viewMTTotal" style="font-size:18px;font-weight:800;color:#003d7a;">₱0.00</span>
                  </div>
                  <div style="display:flex;justify-content:space-between;align-items:center;">
                    <span style="font-size:12px;color:#64748b;font-weight:600;">Amount Paid:</span>
                    <span id="viewMTPaid" style="font-size:14px;font-weight:700;color:#166534;">₱0.00</span>
                  </div>
                  <div style="display:flex;justify-content:space-between;align-items:center;">
                    <span style="font-size:12px;color:#64748b;font-weight:600;">Balance Due:</span>
                    <span id="viewMTBalance" style="font-size:14px;font-weight:700;color:#dc2626;">₱0.00</span>
                  </div>
                </div>
                
                <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;align-items:start;">
                  <span style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Remarks:</span>
                  <span id="viewMTRemarks" style="font-size:12px;color:#475569;font-style:italic;">—</span>
                </div>
                <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;align-items:start;">
                  <span style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Staff:</span>
                  <span id="viewMTStaff" style="font-size:12px;color:#64748b;">—</span>
                </div>
              </div>
            </div>
            <div style="padding:16px 24px 20px 24px;border-top:1px solid #e2e8f0;background:#f8fafc;display:flex;justify-content:flex-end;gap:10px;">
              <button onclick="closeViewMerchandiseModal()" style="padding:0 22px !important;height:38px !important;background:#ffffff !important;color:#475569 !important;border:1.5px solid #cbd5e1 !important;border-radius:8px !important;font-size:13px !important;font-weight:700 !important;cursor:pointer !important;display:inline-flex !important;align-items:center !important;justify-content:center !important;min-width:100px !important;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='#ffffff'">
                Close
              </button>
            </div>
          </div>
        </div>

        <!-- Update Status Modal -->
        <div id="updateStatusModal" style="display:none;position:fixed;inset:0;z-index:9999;
             background:rgba(0,0,0,.5);align-items:center;justify-content:center;padding:75px 16px 50px 16px;box-sizing:border-box;overflow-y:auto;">
          <div style="background:#fff;border-radius:16px;box-shadow:0 24px 64px rgba(0,0,0,.3);
                      width:100%;max-width:480px;margin:auto;max-height:calc(100vh - 140px);display:flex;flex-direction:column;overflow:hidden;animation:pmSlideIn .18s ease;">
            <div style="background:linear-gradient(135deg,#003d7a,#0369a1);padding:15px 20px;
                        display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
              <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:34px;height:34px;background:rgba(255,255,255,.15);border-radius:8px;
                            display:flex;align-items:center;justify-content:center;">
                  <i class="fas fa-sync-alt" style="color:#fff;font-size:15px;"></i>
                </div>
                <div>
                  <div style="color:#fff;font-weight:700;font-size:14px;">Update Workflow Status</div>
                  <div id="updateStatusJORef" style="color:#93c5fd;font-size:11px;margin-top:1px;"></div>
                </div>
              </div>
            </div>
            <form id="updateStatusForm" method="POST" action="staff_transactions_hub.php?section=merchandise&active_tab=tracker">
              <div style="padding:20px;">
                <input type="hidden" name="jo_action" value="update_status">
                <input type="hidden" name="jo_id" id="updateStatusJOId" value="">
                <input type="hidden" name="jo_source" id="updateStatusJOSource" value="">
                
                <div style="margin-bottom:14px;">
                  <label style="font-size:11px;color:#374151;font-weight:700;text-transform:uppercase;letter-spacing:.3px;display:block;margin-bottom:6px;">
                    Current Status: <span id="updateStatusCurrent" style="color:#003d7a;">—</span>
                  </label>
                </div>
                
                <div style="margin-bottom:14px;">
                  <label style="font-size:11px;color:#374151;font-weight:700;text-transform:uppercase;letter-spacing:.3px;display:block;margin-bottom:6px;">
                    New Status <span style="color:#dc2626;">*</span>
                  </label>
                  <select name="new_status" id="updateStatusSelect" required
                          style="width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;
                                 color:#1e293b;background:#fff;outline:none;cursor:pointer;">
                    <option value="">Select new status...</option>
                    <option value="Pending">⏳ Pending</option>
                    <option value="Waiting for Parts">📦 Waiting for Parts</option>
                    <option value="In Progress">▶ In Progress</option>
                    <option value="Completed">✓ Completed</option>
                    <option value="Released">🚗 Released</option>
                    <option value="Rejected">✗ Rejected</option>
                  </select>
                </div>
                
                <div id="updateStatusRemarksDiv" style="display:none;margin-bottom:14px;">
                  <label style="font-size:11px;color:#374151;font-weight:700;text-transform:uppercase;letter-spacing:.3px;display:block;margin-bottom:6px;">
                    Reason for Rejection <span style="color:#dc2626;">*</span>
                  </label>
                  <textarea name="rejection_remarks" id="updateStatusRemarks" rows="3"
                            style="width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;
                                   color:#1e293b;background:#fff;outline:none;resize:vertical;font-family:inherit;"
                            placeholder="Explain why this job order is being rejected..."></textarea>
                </div>
              </div>
              <div style="padding:15px 20px;border-top:1px solid #e2e8f0;display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" onclick="closeUpdateStatusModal()" class="txn-btn secondary">
                  Cancel
                </button>
                <button type="submit" class="txn-btn primary">
                  <i class="fas fa-check"></i> Update Status
                </button>
              </div>
            </form>
          </div>
        </div>

        <!-- Adjust Job Order Modal -->
        <div id="adjustJobOrderModal" style="display:none;position:fixed;inset:0;z-index:9999;
             background:rgba(0,0,0,.5);align-items:center;justify-content:center;padding:75px 16px 50px 16px;box-sizing:border-box;overflow-y:auto;">
          <div style="background:#fff;border-radius:16px;box-shadow:0 24px 64px rgba(0,0,0,.3);
                      width:100%;max-width:600px;margin:auto;max-height:calc(100vh - 140px);display:flex;flex-direction:column;overflow:hidden;animation:pmSlideIn .18s ease;">
            <div style="background:linear-gradient(135deg,#f59e0b,#d97706);padding:15px 20px;
                        display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
              <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:34px;height:34px;background:rgba(255,255,255,.15);border-radius:8px;
                            display:flex;align-items:center;justify-content:center;">
                  <i class="fas fa-edit" style="color:#fff;font-size:15px;"></i>
                </div>
                <div>
                  <div style="color:#fff;font-weight:700;font-size:14px;">Adjust Job Order</div>
                  <div id="adjustJORef" style="color:#fef3c7;font-size:11px;margin-top:1px;"></div>
                </div>
              </div>
            </div>
            <form id="adjustJobOrderForm" method="POST" action="staff_transactions_hub.php?section=merchandise&active_tab=tracker">
              <div style="padding:20px;max-height:65vh;overflow-y:auto;">
                <input type="hidden" name="jo_action" value="adjust_job_order">
                <input type="hidden" name="jo_id" id="adjustJOId" value="">
                <input type="hidden" name="jo_source" id="adjustJOSource" value="">
                
                <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 12px;margin-bottom:16px;
                            font-size:12px;color:#92400e;">
                  <i class="fas fa-info-circle"></i> You can adjust job order details before the service begins (In Progress).
                </div>
                
                <div style="display:grid;gap:12px;">
                  <div>
                    <label style="font-size:11px;color:#374151;font-weight:700;display:block;margin-bottom:4px;">Customer Name</label>
                    <input type="text" name="customer_name" id="adjustJOCustomer" required
                           style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;
                                  color:#1e293b;background:#fff;outline:none;box-sizing:border-box;">
                  </div>
                  <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <div>
                      <label style="font-size:11px;color:#374151;font-weight:700;display:block;margin-bottom:4px;">Vehicle Plate</label>
                      <input type="text" name="vehicle_plate" id="adjustJOPlate"
                             style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;
                                    color:#1e293b;background:#fff;outline:none;box-sizing:border-box;">
                    </div>
                    <div>
                      <label style="font-size:11px;color:#374151;font-weight:700;display:block;margin-bottom:4px;">Vehicle Type</label>
                      <input type="text" name="vehicle_type" id="adjustJOType"
                             style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;
                                    color:#1e293b;background:#fff;outline:none;box-sizing:border-box;">
                    </div>
                  </div>
                  <div>
                    <label style="font-size:11px;color:#374151;font-weight:700;display:block;margin-bottom:4px;">Service Type</label>
                    <input type="text" name="service_type" id="adjustJOService" required
                           style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;
                                  color:#1e293b;background:#fff;outline:none;box-sizing:border-box;">
                  </div>
                  <div>
                    <label style="font-size:11px;color:#374151;font-weight:700;display:block;margin-bottom:4px;">Service Description</label>
                    <textarea name="service_description" id="adjustJODescription" rows="2"
                              style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;
                                     color:#1e293b;background:#fff;outline:none;resize:vertical;font-family:inherit;box-sizing:border-box;"></textarea>
                  </div>
                  <div>
                    <label style="font-size:11px;color:#374151;font-weight:700;display:block;margin-bottom:4px;">Mechanic</label>
                    <input type="text" name="mechanic_name" id="adjustJOMechanic"
                           style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;
                                  color:#1e293b;background:#fff;outline:none;box-sizing:border-box;">
                  </div>
                  <div>
                    <label style="font-size:11px;color:#374151;font-weight:700;display:block;margin-bottom:4px;">Estimated Cost (₱)</label>
                    <input type="number" name="estimated_cost" id="adjustJOCost" step="0.01" min="0"
                           style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;
                                  color:#1e293b;background:#fff;outline:none;box-sizing:border-box;">
                  </div>
                  <div>
                    <label style="font-size:11px;color:#374151;font-weight:700;display:block;margin-bottom:4px;">Estimated Duration (mins)</label>
                    <input type="number" name="estimated_duration" id="adjustJODuration" min="1" step="1"
                           style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;
                                  color:#1e293b;background:#fff;outline:none;box-sizing:border-box;">
                  </div>
                </div>
              </div>
              <div style="padding:15px 20px;border-top:1px solid #e2e8f0;display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" onclick="closeAdjustJobOrderModal()" class="txn-btn secondary">
                  Cancel
                </button>
                <button type="submit" class="txn-btn primary">
                  <i class="fas fa-save"></i> Save Changes
                </button>
              </div>
             </form>
          </div>
        </div>

        <!-- Request Adjustment Modal -->
        <div id="requestAdjustModal" style="display:none;position:fixed;inset:0;z-index:999999;background:rgba(15,23,42,.6);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:16px;box-sizing:border-box;">
          <div style="background:#fff;border-radius:14px;max-width:500px;width:100%;box-shadow:0 25px 50px -12px rgba(0,0,0,.25);margin:auto;max-height:min(88vh, 640px);display:flex;flex-direction:column;overflow:hidden;">
             <div style="background:#002F70;color:#ffffff;padding:14px 20px;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;">
               <h3 style="margin:0;font-size:16px;font-weight:700;display:flex;align-items:center;gap:8px;color:#ffffff !important;">
                  <i class="fas fa-sliders-h" style="color:#ffffff !important;"></i> <span>REQUEST ADJUSTMENT</span>
               </h3>
               <button type="button" onclick="closeRequestAdjustModal()" style="background:transparent;border:none;color:#ffffff;font-size:22px;cursor:pointer;line-height:1;padding:0 4px;" title="Close">&times;</button>
             </div>
             <form id="requestAdjustForm" onsubmit="submitRequestAdjust(event)" style="display:flex;flex-direction:column;flex:1;min-height:0;margin:0;overflow:hidden;">
               <input type="hidden" id="reqAdjTxnId" name="transaction_id">
               <input type="hidden" id="reqAdjRecordSource" name="record_source">
               <input type="hidden" id="reqAdjType" name="request_type" value="Adjustment">
               
               <div style="padding:16px 20px;flex:1;overflow-y:auto;min-height:0;">
                 <!-- Transaction Brief Details Box -->
                 <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;margin-bottom:16px;font-size:12.5px;color:#334155;display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                   <div><span style="color:#64748b;font-size:11px;">JO No.:</span> <strong id="reqAdjJoNo" style="color:#002F70;display:block;">—</strong></div>
                   <div><span style="color:#64748b;font-size:11px;">Customer:</span> <strong id="reqAdjCustomer" style="color:#0f172a;display:block;">—</strong></div>
                   <div><span style="color:#64748b;font-size:11px;">Current Status:</span> <strong id="reqAdjStatus" style="color:#0f172a;display:block;">—</strong></div>
                   <div><span style="color:#64748b;font-size:11px;">Payment Status:</span> <strong id="reqAdjPayStatus" style="color:#0f172a;display:block;">—</strong></div>
                   <div style="grid-column:span 2;"><span style="color:#64748b;font-size:11px;">Payment Method:</span> <strong id="reqAdjPayMethod" style="color:#0f172a;display:block;">—</strong></div>
                 </div>

                 <!-- What needs to be corrected? -->
                 <div style="margin-bottom:14px;">
                   <label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:6px;">What needs to be corrected? <span style="color:#ef4444;">*</span></label>
                   <select id="reqAdjCorrectionField" name="correction_field" required onchange="onReqAdjFieldChange()" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;color:#1e293b;background:#fff;outline:none;box-sizing:border-box;">
                     <option value="Labor Fee">Labor Fee</option>
                     <option value="Service Fee">Service Fee</option>
                     <option value="Service Type">Service Type</option>
                     <option value="Merchandise / Item">Merchandise / Item</option>
                     <option value="Quantity">Quantity</option>
                     <option value="Customer Information">Customer Information</option>
                     <option value="Plate Number / Vehicle Information">Plate Number / Vehicle Information</option>
                     <option value="Mechanic Assignment">Mechanic Assignment</option>
                     <option value="Payment Method">Payment Method</option>
                     <option value="Payment Amount / Down Payment">Payment Amount / Down Payment</option>
                     <option value="Other Encoding Error">Other Encoding Error</option>
                   </select>
                 </div>

                 <!-- Current Value -->
                 <div style="margin-bottom:14px;">
                   <label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:6px;">Current Value</label>
                   <input type="text" id="reqAdjCurrentValue" name="current_value" readonly style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;color:#64748b;background:#f8fafc;outline:none;box-sizing:border-box;">
                 </div>

                 <!-- Requested Value -->
                 <div style="margin-bottom:14px;">
                   <label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:6px;">Requested Value <span style="color:#ef4444;">*</span></label>
                   <input type="text" id="reqAdjRequestedValue" name="requested_value" required placeholder="Enter correct value (e.g. ₱150.00)" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;color:#1e293b;background:#fff;outline:none;box-sizing:border-box;">
                 </div>

                 <!-- Reason -->
                 <div style="margin-bottom:14px;">
                   <label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:6px;">Reason <span style="color:#ef4444;">*</span></label>
                   <input type="text" id="reqAdjReason" name="request_reason" required placeholder="e.g. Incorrect labor fee" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;color:#1e293b;background:#fff;outline:none;box-sizing:border-box;">
                 </div>

                 <!-- Remarks -->
                 <div style="margin-bottom:10px;">
                   <label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:6px;">Remarks</label>
                   <textarea id="reqAdjRemarks" name="remarks" rows="2" placeholder="Additional notes for manager review..." style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;color:#1e293b;background:#fff;outline:none;resize:vertical;box-sizing:border-box;"></textarea>
                 </div>
               </div>

               <div style="padding:12px 20px;border-top:1px solid #e2e8f0;display:flex;gap:10px;justify-content:flex-end;background:#f8fafc;flex-shrink:0;">
                 <button type="button" onclick="closeRequestAdjustModal()" class="txn-btn secondary">Cancel</button>
                 <button type="submit" id="reqAdjSubmitBtn" class="txn-btn primary" style="background:#002F70;color:#fff;border:none;">
                   <i class="fas fa-paper-plane"></i> Submit Adjustment Request
                 </button>
               </div>
             </form>
          </div>
        </div>

        <!-- Request Void Modal -->
        <div id="requestVoidModal" style="display:none;position:fixed;inset:0;z-index:999999;background:rgba(15,23,42,.6);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:16px;box-sizing:border-box;">
          <div style="background:#fff;border-radius:14px;max-width:480px;width:100%;box-shadow:0 25px 50px -12px rgba(0,0,0,.25);margin:auto;max-height:min(88vh, 580px);display:flex;flex-direction:column;overflow:hidden;">
             <div style="background:#dc2626;color:#ffffff;padding:14px 20px;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;">
               <h3 style="margin:0;font-size:16px;font-weight:700;display:flex;align-items:center;gap:8px;color:#ffffff !important;">
                  <i class="fas fa-ban" style="color:#ffffff !important;"></i> <span>REQUEST VOID</span>
               </h3>
               <button type="button" onclick="closeRequestVoidModal()" style="background:transparent;border:none;color:#ffffff;font-size:22px;cursor:pointer;line-height:1;padding:0 4px;" title="Close">&times;</button>
             </div>
             <form id="requestVoidForm" onsubmit="submitRequestVoid(event)" style="display:flex;flex-direction:column;flex:1;min-height:0;margin:0;overflow:hidden;">
               <input type="hidden" id="reqVoidTxnId" name="transaction_id">
               <input type="hidden" id="reqVoidRecordSource" name="record_source">
               <input type="hidden" id="reqVoidType" name="request_type" value="Void">
               
               <div style="padding:16px 20px;flex:1;overflow-y:auto;min-height:0;">
                 <!-- Transaction Brief Details Box -->
                 <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px 14px;margin-bottom:16px;font-size:12.5px;color:#991b1b;display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                   <div><span style="color:#7f1d1d;font-size:11px;">JO No.:</span> <strong id="reqVoidJoNo" style="color:#991b1b;display:block;">—</strong></div>
                   <div><span style="color:#7f1d1d;font-size:11px;">Customer:</span> <strong id="reqVoidCustomer" style="color:#0f172a;display:block;">—</strong></div>
                   <div><span style="color:#7f1d1d;font-size:11px;">JO Status:</span> <strong id="reqVoidStatus" style="color:#0f172a;display:block;">—</strong></div>
                   <div><span style="color:#7f1d1d;font-size:11px;">Payment Status:</span> <strong id="reqVoidPayStatus" style="color:#0f172a;display:block;">—</strong></div>
                 </div>

                 <!-- Void Reason -->
                 <div style="margin-bottom:14px;">
                   <label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:6px;">Void Reason <span style="color:#ef4444;">*</span></label>
                   <select id="reqVoidReasonSelect" name="request_reason" required style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;color:#1e293b;background:#fff;outline:none;box-sizing:border-box;">
                     <option value="Duplicate Transaction">Duplicate Transaction</option>
                     <option value="Wrong Customer">Wrong Customer</option>
                     <option value="Wrong Vehicle">Wrong Vehicle</option>
                     <option value="Wrong Transaction Encoded">Wrong Transaction Encoded</option>
                     <option value="Duplicate Service">Duplicate Service</option>
                     <option value="Transaction Created by Mistake">Transaction Created by Mistake</option>
                     <option value="Customer Cancelled Entire Service">Customer Cancelled Entire Service</option>
                     <option value="Incorrect JO Cannot Be Adjusted">Incorrect JO Cannot Be Adjusted</option>
                     <option value="Other">Other</option>
                   </select>
                 </div>

                 <!-- Remarks -->
                 <div style="margin-bottom:10px;">
                   <label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:6px;">Remarks <span style="color:#ef4444;">*</span></label>
                   <textarea id="reqVoidRemarks" name="remarks" required rows="3" placeholder="Provide details (e.g. Duplicate of JO-0004)..." style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;color:#1e293b;background:#fff;outline:none;resize:vertical;box-sizing:border-box;"></textarea>
                 </div>
               </div>

               <div style="padding:12px 20px;border-top:1px solid #e2e8f0;display:flex;gap:10px;justify-content:flex-end;background:#f8fafc;flex-shrink:0;">
                 <button type="button" onclick="closeRequestVoidModal()" class="txn-btn secondary">Cancel</button>
                 <button type="submit" id="reqVoidSubmitBtn" class="txn-btn primary" style="background:#dc2626;color:#fff;border:none;">
                   <i class="fas fa-paper-plane"></i> Submit Void Request
                 </button>
               </div>
             </form>
          </div>
        </div>

        <!-- Request Void / Request Adjustment Modal (Generic Fallback) -->
        <div id="txnRequestModal" style="display:none;position:fixed;inset:0;z-index:999999;background:rgba(15,23,42,.6);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:16px;box-sizing:border-box;">
          <div style="background:#fff;border-radius:14px;max-width:480px;width:100%;box-shadow:0 25px 50px -12px rgba(0,0,0,.25);margin:auto;max-height:min(88vh, 580px);display:flex;flex-direction:column;overflow:hidden;">
             <div id="txnRequestHeader" style="background:#002F70;color:#ffffff;padding:14px 20px;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;">
               <h3 id="txnRequestTitle" style="margin:0;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;color:#ffffff !important;">
                  <i id="txnRequestIcon" class="fas fa-paper-plane" style="color:#ffffff !important;"></i> <span id="txnRequestTitleText" style="color:#ffffff !important;">Request Transaction Action</span>
               </h3>
               <button type="button" onclick="closeTxnRequestModal()" style="background:transparent;border:none;color:#ffffff;font-size:22px;cursor:pointer;line-height:1;padding:0 4px;" title="Close">&times;</button>
             </div>
             <form id="txnRequestForm" onsubmit="submitTxnRequest(event)" style="display:flex;flex-direction:column;flex:1;min-height:0;margin:0;overflow:hidden;">
               <input type="hidden" id="txnRequestTxnId" name="transaction_id">
               <input type="hidden" id="txnRequestRecordSource" name="record_source">
               <input type="hidden" id="txnRequestType" name="request_type">
               <div style="padding:16px 20px;flex:1;overflow-y:auto;min-height:0;">
                 <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;margin-bottom:16px;font-size:13px;color:#334155;">
                   <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Target Transaction</div>
                   <div style="font-weight:700;color:#0f172a;" id="txnRequestTargetInfo">Transaction #—</div>
                 </div>

                <div id="txnRequestNewAmountGroup" style="display:none;margin-bottom:16px;">
                  <label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:6px;">Proposed New Amount (Optional)</label>
                  <input type="number" id="txnRequestNewAmount" name="new_amount" step="0.01" min="0" placeholder="0.00" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;color:#1e293b;background:#fff;outline:none;box-sizing:border-box;">
                </div>

                <div style="margin-bottom:10px;">
                  <label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:6px;">Reason for Request <span style="color:#ef4444;">*</span></label>
                  <textarea id="txnRequestReason" name="request_reason" required rows="3" placeholder="Explain clearly why this void or adjustment is needed for the manager to approve..." style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;color:#1e293b;background:#fff;outline:none;resize:vertical;box-sizing:border-box;"></textarea>
                </div>
              </div>
              <div style="padding:12px 20px;border-top:1px solid #e2e8f0;display:flex;gap:8px;justify-content:flex-end;background:#f8fafc;flex-shrink:0;">
                <button type="button" onclick="closeTxnRequestModal()" class="txn-btn secondary">Cancel</button>
                <button type="submit" id="txnRequestSubmitBtn" class="txn-btn primary">
                  <i class="fas fa-paper-plane"></i> Submit Request
                </button>
              </div>
            </form>
          </div>
        </div>

        <script>
        // ── Job Order Modal Functions ───────────────────────────────────────
        
        // View Job Order Details Modal
        // Store current JO ref for print
        var _currentViewJORef = '';
        var _currentViewJOSource = '';

        function viewJobOrderDetails(joData) {
            ['viewMerchandiseModal', 'updateStatusModal', 'adjustJobOrderModal', 'txnRequestModal', 'requestAdjustModal', 'requestVoidModal'].forEach(function(id) {
                var m = document.getElementById(id);
                if (m) m.style.display = 'none';
            });
            var fmt = function(n){ return '₱' + parseFloat(n || 0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}); };
            var val = function(v, fallback) { return (v && v !== '' && v !== '0' && v !== 0) ? v : (fallback || '—'); };
            var setText = function(id, text) {
                var el = document.getElementById(id);
                if (el) el.textContent = text;
            };

            _currentViewJORef    = joData.jo_ref || '';
            _currentViewJOSource = joData.source || 'job_orders';

            setText('viewJORef', val(joData.jo_ref));
            setText('viewJORef2', val(joData.jo_ref));
            setText('viewJOTxnId', val(joData.jo_ref));
            setText('viewJOCustomer', val(joData.customer));

            // Build vehicle display: plate + type + brand/model/year
            var vParts = [];
            if (joData.vehicle_plate) vParts.push(joData.vehicle_plate);
            if (joData.vehicle_type) vParts.push('(' + joData.vehicle_type + ')');
            setText('viewJOVehicle', vParts.length ? vParts.join(' ') : '—');

            var brandModelParts = [];
            var vb = val(joData.vehicle_brand, '');
            var vm = val(joData.vehicle_model, '');
            if (vb && vb !== '—' && vb !== '0') brandModelParts.push(vb);
            if (vm && vm !== '—' && vm !== '0') brandModelParts.push(vm);
            setText('viewJOBrandModel', brandModelParts.length ? brandModelParts.join(' ') : '—');
            setText('viewJOVehicleBrand', vb);
            setText('viewJOVehicleModel', vm);

            setText('viewJOYearModel', val(joData.year_model));
            setText('viewJOEngineNo', val(joData.engine_number));
            setText('viewJOChassisNo', val(joData.chassis_number));
            setText('viewJOContact', val(joData.contact_number));
            setText('viewJOOrNo', val(joData.or_number));

            setText('viewJOService', val(joData.service_type));
            setText('viewJOParts', val(joData.parts));
            setText('viewJOMechanic', val(joData.mechanic, 'Unassigned'));
            setText('viewJODuration', joData.estimated_duration ? joData.estimated_duration + ' mins' : '—');
            setText('viewJOWorkflow', val(joData.workflow_status));
            setText('viewJOPayment', val(joData.payment_status));
            setText('viewJOPayMethod', val(joData.payment_method, 'Cash'));

            setText('viewJOTotal', fmt(joData.total || 0));
            setText('viewJOPaid', fmt(joData.paid || 0));
            setText('viewJOBalance', fmt(joData.balance || 0));
            setText('viewJOCreated', joData.created_at
                ? new Date(joData.created_at).toLocaleString('en-PH', {dateStyle:'medium', timeStyle:'short'})
                : '—');

            setText('viewJORemarks', val(joData.remarks || joData.staff_remarks || joData.service_description));

            var modal = document.getElementById('viewJobOrderModal');
            if (modal) modal.style.display = 'flex';
        }

        function printJobOrderReceiptFromModal() {
            if (_currentViewJORef) {
                printJobOrderReceipt(0, _currentViewJORef);
            }
        }
        
        function closeViewJobOrderModal() {
            document.getElementById('viewJobOrderModal').style.display = 'none';
        }
        
        // Update Status Modal
        function openUpdateStatusModal(joId, currentStatus, source) {
            document.getElementById('updateStatusJOId').value = joId;
            document.getElementById('updateStatusJOSource').value = source;
            document.getElementById('updateStatusJORef').textContent = 'JO #' + joId;
            document.getElementById('updateStatusCurrent').textContent = currentStatus || 'Pending';
            
            var select = document.getElementById('updateStatusSelect');
            select.value = '';
            
            // Show/hide rejection remarks based on selection
            select.onchange = function() {
                var remarksDiv = document.getElementById('updateStatusRemarksDiv');
                var remarksField = document.getElementById('updateStatusRemarks');
                if (this.value === 'Rejected') {
                    remarksDiv.style.display = 'block';
                    remarksField.required = true;
                } else {
                    remarksDiv.style.display = 'none';
                    remarksField.required = false;
                }
            };
            
            document.getElementById('updateStatusModal').style.display = 'flex';
        }
        
        function closeUpdateStatusModal() {
            document.getElementById('updateStatusModal').style.display = 'none';
        }
        
        // Adjust Job Order Modal
        function openAdjustJobOrderModal(joData) {
            document.getElementById('adjustJOId').value = joData.id || '';
            document.getElementById('adjustJOSource').value = joData.source || 'job_orders';
            document.getElementById('adjustJORef').textContent = joData.jo_ref || '';
            document.getElementById('adjustJOCustomer').value = joData.customer || '';
            document.getElementById('adjustJOPlate').value = joData.vehicle_plate || '';
            document.getElementById('adjustJOType').value = joData.vehicle_type || '';
            document.getElementById('adjustJOService').value = joData.service_type || '';
            document.getElementById('adjustJODescription').value = joData.service_description || '';
            document.getElementById('adjustJOMechanic').value = joData.mechanic || '';
            document.getElementById('adjustJOCost').value = joData.total || '';
            document.getElementById('adjustJODuration').value = joData.estimated_duration || '';
            
            document.getElementById('adjustJobOrderModal').style.display = 'flex';
        }
        
        function closeAdjustJobOrderModal() {
            document.getElementById('adjustJobOrderModal').style.display = 'none';
        }
        
        // Print Job Order Receipt
        function printJobOrderReceipt(joId, joRef) {
            // joRef may be '#688' (fragment) if job_order_id is null in DB.
            // Browsers strip '#...' from URLs, so use numeric joId in that case.
            var rid = (joRef && joRef.charAt(0) !== '#') ? encodeURIComponent(joRef) : joId;
            var url = 'receipt.php?id=' + rid + '&type=job_order';
            window.open(url, '_blank');
        }
        
        // Print Merchandise Receipt
        function printMerchandiseReceipt(txnId) {
            var url = 'receipt.php?id=' + encodeURIComponent(txnId) + '&type=merchandise';
            window.open(url, '_blank');
        }
        
        // View Merchandise Transaction Details
        function viewMerchandiseDetails(txnId) {
            ['viewJobOrderModal', 'viewMerchandiseModal', 'updateStatusModal', 'adjustJobOrderModal', 'txnRequestModal'].forEach(id => {
                const m = document.getElementById(id);
                if (m) m.style.display = 'none';
            });
            if (!txnId) {
                showTxnAlert('Invalid transaction ID', 'error');
                return;
            }
            
            // Fetch transaction details via AJAX
            fetch('../backend/get_merchandise_transaction_details.php?id=' + encodeURIComponent(txnId))
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const txn = data.transaction;
                        
                        // Populate modal fields
                        document.getElementById('viewMTxnRef').textContent = txn.transaction_id || '—';
                        document.getElementById('viewMTCustomer').textContent = txn.customer_name || 'No Customer';
                        document.getElementById('viewMTShift').textContent = (txn.shift_name || txn.shift_period) || '—';
                        document.getElementById('viewMTDate').textContent = txn.transaction_date || '—';
                        document.getElementById('viewMTPayMethod').textContent = txn.payment_method || '—';
                        document.getElementById('viewMTPayStatus').innerHTML = txn.payment_status_badge || '—';
                        document.getElementById('viewMTValStatus').innerHTML = txn.validation_status_badge || '—';
                        document.getElementById('viewMTSubtotal').textContent = txn.subtotal_display || '₱0.00';
                        document.getElementById('viewMTVAT').textContent = txn.vat_display || '₱0.00';
                        document.getElementById('viewMTTotal').textContent = txn.total_display || '₱0.00';
                        document.getElementById('viewMTPaid').textContent = txn.paid_display || '₱0.00';
                        document.getElementById('viewMTBalance').textContent = txn.balance_display || '₱0.00';
                        document.getElementById('viewMTRemarks').textContent = txn.remarks || '—';
                        document.getElementById('viewMTStaff').textContent = txn.staff_name || '—';
                        
                        // Populate items table
                        const itemsBody = document.getElementById('viewMTItemsBody');
                        if (txn.items && txn.items.length > 0) {
                            itemsBody.innerHTML = txn.items.map(item => `
                                <tr style="border-bottom:1px solid #f1f5f9;">
                                    <td style="padding:8px;">
                                        <div style="font-weight:600;color:#1e293b;">${item.product_name || '—'}</div>
                                        ${item.category ? `<div style="font-size:10px;color:#94a3b8;margin-top:2px;">${item.category}${item.size_variant ? ' • ' + item.size_variant : ''}</div>` : ''}
                                    </td>
                                    <td style="padding:8px;text-align:center;color:#475569;">${parseInt(item.quantity) || 0} ${parseInt(item.quantity) === 1 ? 'pc' : 'pcs'}</td>
                                    <td style="padding:8px;text-align:right;color:#475569;">₱${parseFloat(item.unit_price || 0).toFixed(2)}</td>
                                    <td style="padding:8px;text-align:right;color:#003d7a;font-weight:700;">₱${parseFloat(item.subtotal || 0).toFixed(2)}</td>
                                </tr>
                            `).join('');
                        } else {
                            itemsBody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:12px;color:#94a3b8;">No items found</td></tr>';
                        }
                        
                        // Show modal
                        document.getElementById('viewMerchandiseModal').style.display = 'flex';
                    } else {
                        showTxnAlert(data.error || 'Failed to load transaction details', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error fetching merchandise details:', error);
                    showTxnAlert('Network error: ' + error.message, 'error');
                });
        }
        
        function closeViewMerchandiseModal() {
            document.getElementById('viewMerchandiseModal').style.display = 'none';
        }
        
        // Request Void / Request Adjustment Modal Functions
        function openTxnRequestModal(e, txnId, recordSource, requestType, customerName) {
            if (e) {
                if (typeof e.stopPropagation === 'function') e.stopPropagation();
                if (typeof e.preventDefault === 'function') e.preventDefault();
            }
            ['viewJobOrderModal', 'viewMerchandiseModal', 'updateStatusModal', 'adjustJobOrderModal'].forEach(function(id) {
                var m = document.getElementById(id);
                if (m) m.style.display = 'none';
            });

            var modal = document.getElementById('txnRequestModal');
            if (!modal) {
                console.error('txnRequestModal element not found');
                return;
            }

            var elTxnId = document.getElementById('txnRequestTxnId');
            if (elTxnId) elTxnId.value = txnId || '';

            var elSource = document.getElementById('txnRequestRecordSource');
            if (elSource) elSource.value = recordSource || 'job_orders';

            var elType = document.getElementById('txnRequestType');
            if (elType) elType.value = requestType || 'Void';

            var elReason = document.getElementById('txnRequestReason');
            if (elReason) elReason.value = '';

            var elAmount = document.getElementById('txnRequestNewAmount');
            if (elAmount) elAmount.value = '';

            var titleText = (requestType === 'Void') ? 'Request Void Transaction' : 'Request Transaction Adjustment';
            var iconClass = (requestType === 'Void') ? 'fas fa-ban' : 'fas fa-sliders-h';
            var headerBg  = (requestType === 'Void') ? '#dc2626' : '#475569';

            var elTitleText = document.getElementById('txnRequestTitleText');
            if (elTitleText) elTitleText.textContent = titleText;

            var elIcon = document.getElementById('txnRequestIcon');
            if (elIcon) elIcon.className = iconClass;

            var elHeader = document.getElementById('txnRequestHeader');
            if (elHeader) {
                elHeader.style.background = headerBg;
                elHeader.style.color = '#ffffff';
            }

            var elTitle = document.getElementById('txnRequestTitle');
            if (elTitle) elTitle.style.color = '#ffffff';

            var elInfo = document.getElementById('txnRequestTargetInfo');
            if (elInfo) {
                elInfo.textContent = (recordSource === 'job_orders' ? 'Job Order #' : 'Transaction #') + txnId + (customerName ? ' (' + customerName + ')' : '');
            }

            var amountGroup = document.getElementById('txnRequestNewAmountGroup');
            if (amountGroup) {
                amountGroup.style.display = (requestType === 'Adjustment') ? 'block' : 'none';
            }

            modal.style.display = 'flex';
            modal.style.zIndex = '9999999';
        }

        function closeTxnRequestModal() {
            document.getElementById('txnRequestModal').style.display = 'none';
        }

        function submitTxnRequest(e) {
            e.preventDefault();
            var btn = document.getElementById('txnRequestSubmitBtn');
            var origText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';

            var payload = {
                transaction_id: parseInt(document.getElementById('txnRequestTxnId').value),
                record_source: document.getElementById('txnRequestRecordSource').value,
                request_type: document.getElementById('txnRequestType').value,
                request_reason: document.getElementById('txnRequestReason').value.trim(),
                new_amount: document.getElementById('txnRequestNewAmount').value
            };

            fetch('../backend/api/request_transaction_action.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = origText;
                if (data.success) {
                    closeTxnRequestModal();
                    showTxnAlert('✔ ' + (data.message || 'Request submitted successfully! Status: Pending Manager Review.'), 'success');
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    showTxnAlert('❌ ' + (data.error || 'Failed to submit request'), 'error');
                }
            })
            .catch(err => {
                btn.disabled = false;
                btn.innerHTML = origText;
                showTxnAlert('❌ Network error: ' + err.message, 'error');
            });
        }
        
        // Dedicated Job Order Request Adjust & Request Void JS Functions
        window._activeJoDataForReq = window._activeJoDataForReq || null;

        window.closeRequestAdjustModal = function() {
            document.getElementById('requestAdjustModal').style.display = 'none';
        };

        window.onReqAdjFieldChange = function() {
            if (!_activeJoDataForReq) return;
            var field = document.getElementById('reqAdjCorrectionField').value;
            var curVal = '—';
            if (field === 'Labor Fee') {
                curVal = _activeJoDataForReq.labor_fee ? ('₱' + parseFloat(_activeJoDataForReq.labor_fee).toFixed(2)) : '₱100.00';
            } else if (field === 'Service Fee') {
                curVal = _activeJoDataForReq.total ? ('₱' + parseFloat(_activeJoDataForReq.total).toFixed(2)) : '₱0.00';
            } else if (field === 'Service Type') {
                curVal = _activeJoDataForReq.service_type || '—';
            } else if (field === 'Customer Information') {
                curVal = _activeJoDataForReq.customer || '—';
            } else if (field === 'Plate Number / Vehicle Information') {
                curVal = (_activeJoDataForReq.vehicle_plate || '') + ' ' + (_activeJoDataForReq.vehicle_type || '');
            } else if (field === 'Mechanic Assignment') {
                curVal = _activeJoDataForReq.mechanic || 'Unassigned';
            } else if (field === 'Payment Method') {
                curVal = _activeJoDataForReq.payment_method || 'Cash';
            } else if (field === 'Payment Amount / Down Payment') {
                curVal = '₱' + parseFloat(_activeJoDataForReq.paid || 0).toFixed(2);
            } else {
                curVal = '₱' + parseFloat(_activeJoDataForReq.total || 0).toFixed(2);
            }
            document.getElementById('reqAdjCurrentValue').value = curVal;
        };

        window.submitRequestAdjust = function(e) {
            e.preventDefault();
            var btn = document.getElementById('reqAdjSubmitBtn');
            var origText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';

            var payload = {
                transaction_id: parseInt(document.getElementById('reqAdjTxnId').value),
                record_source: document.getElementById('reqAdjRecordSource').value,
                request_type: 'Adjustment',
                correction_field: document.getElementById('reqAdjCorrectionField').value,
                current_value: document.getElementById('reqAdjCurrentValue').value,
                requested_value: document.getElementById('reqAdjRequestedValue').value.trim(),
                request_reason: document.getElementById('reqAdjReason').value.trim(),
                remarks: document.getElementById('reqAdjRemarks').value.trim()
            };

            fetch('../backend/api/request_transaction_action.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = origText;
                if (data.success) {
                    closeRequestAdjustModal();
                    showTxnAlert('✔ ' + (data.message || 'Adjustment request submitted successfully! Status: ADJUSTMENT REQUESTED.'), 'success');
                    setTimeout(function() { location.reload(); }, 1200);
                } else {
                    showTxnAlert('❌ ' + (data.error || 'Failed to submit adjustment request'), 'error');
                }
            })
            .catch(err => {
                btn.disabled = false;
                btn.innerHTML = origText;
                showTxnAlert('❌ Network error: ' + err.message, 'error');
            });
        };

        window.closeRequestVoidModal = function() {
            document.getElementById('requestVoidModal').style.display = 'none';
        };

        window.submitRequestVoid = function(e) {
            e.preventDefault();
            var btn = document.getElementById('reqVoidSubmitBtn');
            var origText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';

            var payload = {
                transaction_id: parseInt(document.getElementById('reqVoidTxnId').value),
                record_source: document.getElementById('reqVoidRecordSource').value,
                request_type: 'Void',
                request_reason: document.getElementById('reqVoidReasonSelect').value,
                remarks: document.getElementById('reqVoidRemarks').value.trim()
            };

            fetch('../backend/api/request_transaction_action.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = origText;
                if (data.success) {
                    closeRequestVoidModal();
                    showTxnAlert('✔ ' + (data.message || 'Void request submitted successfully! Status: VOID REQUESTED.'), 'success');
                    setTimeout(function() { location.reload(); }, 1200);
                } else {
                    showTxnAlert('❌ ' + (data.error || 'Failed to submit void request'), 'error');
                }
            })
            .catch(err => {
                btn.disabled = false;
                btn.innerHTML = origText;
                showTxnAlert('❌ Network error: ' + err.message, 'error');
            });
        };
        
        // Close modals on outside click
        ['viewJobOrderModal', 'viewMerchandiseModal', 'updateStatusModal', 'adjustJobOrderModal', 'txnRequestModal', 'requestAdjustModal', 'requestVoidModal'].forEach(function(modalId) {
            var el = document.getElementById(modalId);
            if (el) {
                el.addEventListener('click', function(e) {
                    if (e.target === this) {
                        this.style.display = 'none';
                    }
                });
            }
        });
        </script>

        <script>
        function switchInnerTab(tab) {
            ['merchandise','tracker'].forEach(function(t) {
                var panel = document.getElementById('innerTab_' + t);
                var btn   = document.getElementById('innerTabBtn_' + t);
                if (!panel || !btn) return;
                var themeMap = {merchandise:'green', tracker:'darkblue'};
                var active = (t === tab);
                panel.style.display = active ? 'block' : 'none';
                btn.className = 'txn-subtab-btn ' + themeMap[t] + ' ' + (active ? 'active' : 'inactive');
                btn.style.whiteSpace = 'nowrap';
            });
            var descElem = document.getElementById('txnSectionDesc');
            if (descElem) {
                descElem.textContent = (tab === 'tracker') ? 'Monitor service progress and pending balances in real time.' : 'Merchandise sales, job order encoding, and status tracking.';
            }
            
            // Toggle header back button and tracker export buttons dynamically
            var backBtn = document.getElementById('headerBackButton');
            if (backBtn) backBtn.style.display = (tab === 'tracker') ? 'inline-flex' : 'none';
            
            var trackerExports = document.getElementById('trackerExportButtons');
            if (trackerExports) trackerExports.style.display = (tab === 'tracker') ? 'flex' : 'none';

            var url = new URL(window.location.href);
            url.searchParams.set('active_tab', tab);
            history.replaceState(null, '', url.toString());
        }

        function goBackFromTracker() {
            // Check if user came from dashboard (referrer contains staff_dashboard.php)
            var referrer = document.referrer || '';
            var fromDashboard = referrer.indexOf('staff_dashboard.php') !== -1;
            
            // Check URL parameters - if came from encode_jo, go back to merchandise tab
            var urlParams = new URLSearchParams(window.location.search);
            var hasEncodeJo = window.location.href.indexOf('encode_jo') !== -1;
            
            if (fromDashboard && !hasEncodeJo) {
                // User came from dashboard → go back to dashboard
                window.location.href = 'staff_dashboard.php';
            } else {
                // User came from within the page (merchandise tab) → switch to merchandise tab
                switchInnerTab('merchandise');
            }
        }




        </script>



        <?php /* SECTION: TRANSACTION HISTORY */ ?>
        <?php elseif ($section === 'history'): ?>
        <?php require __DIR__ . '/staff_txn_history.php'; ?>

        <?php /* ══════════════════════════════════════════════════════ */ ?>
        <?php /* ══════════════════════════════════════════════════════ */ ?>
        <?php elseif ($section === 'fuel_history'): ?>

        <div class="txn-section-header">
            <div class="txn-section-title">
                <div>
                    <h1>Fuel Transaction History</h1>
                </div>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;">
                <button type="button" onclick="window.location.href='staff_transactions_hub.php?section=fuel'" 
                        class="txn-btn secondary" title="Back to Fuel Transaction">
                    <i class="fas fa-arrow-left"></i> <span>Back</span>
                </button>
                <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;">
                    <button type="button" onclick="printReportArea()" class="txn-btn primary" style="text-decoration:none;display:inline-flex;align-items:center;gap:5px;"><i class="fas fa-print"></i> Print</button>
                </div>
            </div>
        </div>

        <div class="txn-card">
            <div class="txn-card-header">
                <i class="fas fa-gas-pump" style="color:var(--petron-blue);"></i>
                <h3>Fuel Transactions</h3>
            </div>
            <div class="txn-card-body" style="padding:0;">
                <?php if (empty($recent_fuel)): ?>
                <div style="text-align:center;padding:40px;color:#94a3b8;">
                    <i class="fas fa-gas-pump" style="font-size:28px;display:block;margin-bottom:8px;"></i>
                    No fuel transactions found.
                </div>
                <?php else: ?>
                <div style="width:100%;">
                <table class="txn-table" id="fuelHistoryTable" style="width:100%;table-layout:fixed;word-wrap:break-word;">
                    <thead>
                        <tr>
                            <th style="width:22%">Txn ID</th>
                            <th style="width:12%">Fuel Type</th>
                            <th style="width:10%">Liters</th>
                            <th style="width:12%">Amount</th>
                            <th style="width:12%">Payment</th>
                            <th style="width:18%">Date</th>
                            <th style="width:14%">Status</th>
                        </tr>
                    </thead>
                    <tbody id="fhTableBody">
                    <?php foreach ($recent_fuel as $ft): ?>
                    <tr class="fh-row">
                        <td><strong style="color:var(--petron-blue);"><?= htmlspecialchars($ft['transaction_id'] ?? ('#'.$ft['id'])) ?></strong></td>
                        <td><?= htmlspecialchars($ft['fuel_type'] ?? '—') ?></td>
                        <td><?= number_format((float)($ft['liters'] ?? $ft['liters_sold'] ?? 0), 2) ?> L</td>
                        <td style="font-weight:700;color:var(--petron-blue);">₱<?= number_format((float)($ft['total_amount'] ?? $ft['amount'] ?? 0), 2) ?></td>
                        <td style="font-size:11px;"><?= htmlspecialchars($ft['payment_method'] ?? '—') ?></td>
                        <td style="font-size:11px;color:#64748b;white-space:nowrap;"><?= date('M j, Y h:i A', strtotime($ft['transaction_date'] ?? $ft['created_at'] ?? 'now')) ?></td>
                        <td><?= status_badge($ft['status'] ?? 'Pending') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <!-- Rows per page + Pagination controls -->
                <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;border-top:1px solid #e2e8f0;">
                    <div style="display:flex;align-items:center;gap:7px;">
                        <label style="font-size:12px;white-space:nowrap;">Rows per page:</label>
                        <select id="fhPerPage" onchange="fhChangePerPage()" class="pag-select">
                            <option value="10" selected>10</option>
                            <option value="20">20</option>
                            <option value="30">30</option>
                            <option value="40">40</option>
                            <option value="50">50</option>
                        </select>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <button id="fhPrevBtn" onclick="fhGoPage(fhState.page - 1)" class="pag-btn">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <span id="fhPageLabel" style="font-size:13px;color:#495057;white-space:nowrap;">Page 1 of 1</span>
                        <button id="fhNextBtn" onclick="fhGoPage(fhState.page + 1)" class="pag-btn">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <script>
        (function(){
            var fhState = { page: 1, per_page: 10 };
            function fhRender() {
                var rows = document.querySelectorAll('#fhTableBody .fh-row');
                var total = rows.length;
                var perPage = fhState.per_page;
                var page = fhState.page;

                var foot = document.getElementById('fhPerPage') ? document.getElementById('fhPerPage').closest('div[style*="display:flex"]') : null;
                if (foot) {
                    foot.style.display = total <= 10 ? 'none' : 'flex';
                }
                if (total <= 10) {
                    rows.forEach(function(row) { row.style.display = ''; });
                    return;
                }

                var totalPages = Math.max(1, Math.ceil(total / perPage));

                if (page > totalPages) { fhState.page = page = totalPages; }
                var start = (page - 1) * perPage;
                var end   = start + perPage;
                rows.forEach(function(row, i) {
                    row.style.display = (i >= start && i < end) ? '' : 'none';
                });
                var lbl = document.getElementById('fhPageLabel');
                if (lbl) lbl.textContent = 'Page ' + page + ' of ' + totalPages;
                var prev = document.getElementById('fhPrevBtn');
                var next = document.getElementById('fhNextBtn');
                if (prev) { prev.disabled = (page <= 1); prev.style.opacity = (page <= 1) ? '0.4' : '1'; }
                if (next) { next.disabled = (page >= totalPages); next.style.opacity = (page >= totalPages) ? '0.4' : '1'; }
            }
            window.fhState = fhState;
            window.fhGoPage = function(p) {
                var rows = document.querySelectorAll('#fhTableBody .fh-row');
                var totalPages = Math.max(1, Math.ceil(rows.length / fhState.per_page));
                if (p < 1 || p > totalPages) return;
                fhState.page = p;
                fhRender();
            };
            window.fhChangePerPage = function() {
                var sel = document.getElementById('fhPerPage');
                if (sel) fhState.per_page = parseInt(sel.value);
                fhState.page = 1;
                fhRender();
            };
            fhRender();
        })();
        </script>

        <?php endif; /* end section switch */ ?>

</div><!-- /txn-content -->

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
