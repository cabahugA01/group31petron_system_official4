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

// Detect user's current shift — ONLY from active labor_sessions (no hardcoded time fallback)
$user_current_shift   = null; // 'shift1', 'shift2', or null (= show all data)
$is_manager_or_admin  = in_array($role, ['manager', 'admin', 'superadmin', 'developer']);

if (!$is_manager_or_admin) {
    try {
        $stmt = $pdo->prepare("
            SELECT shift_period
            FROM labor_sessions
            WHERE user_id = ? AND end_time IS NULL
            ORDER BY start_time DESC LIMIT 1
        ");
        $stmt->execute([$user_id]);
        $active_session = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($active_session && !empty($active_session['shift_period'])) {
            $sp = strtolower(trim($active_session['shift_period']));

            // Exact + known-alias matching for Shift 1
            if (in_array($sp, ['1', 'shift1', 'shift 1', 'first', 'morning', 'am', 'day'])) {
                $user_current_shift = 'shift1';
            }
            // Exact + known-alias matching for Shift 2
            elseif (in_array($sp, ['2', 'shift2', 'shift 2', 'second', 'afternoon', 'pm', 'evening', 'night'])) {
                $user_current_shift = 'shift2';
            }
            // Partial-match fallback for unexpected stored values
            elseif (strpos($sp, 'first') !== false || strpos($sp, '1') !== false) {
                $user_current_shift = 'shift1';
            } elseif (strpos($sp, 'second') !== false || strpos($sp, '2') !== false) {
                $user_current_shift = 'shift2';
            }
        }
        // NOTE: No time-of-day fallback — shift must come from the database only.
        // If no active session exists, $user_current_shift stays null → all data shown.
    } catch (Exception $e) {
        error_log("Shift detection error (staff_reports): " . $e->getMessage());
    }
}

// User-specific overrides: Yyang is Shift 1, Judy Lastimosa is Shift 2
$username_lower = isset($me['username']) ? strtolower(trim($me['username'])) : '';
$first_name_lower = isset($me['first_name']) ? strtolower(trim($me['first_name'])) : '';
$last_name_lower = isset($me['last_name']) ? strtolower(trim($me['last_name'])) : '';

if ($username_lower === 'yyang' || $first_name_lower === 'yyang') {
    $user_current_shift = 'shift1';
} elseif ($username_lower === 'judy' || $first_name_lower === 'judy' || $last_name_lower === 'lastimosa') {
    $user_current_shift = 'shift2';
}


// Helper: can this user see Shift 1 data?
$can_see_shift1 = ($is_manager_or_admin || $user_current_shift === 'shift1' || $user_current_shift === null);
// Helper: can this user see Shift 2 data?
$can_see_shift2 = ($is_manager_or_admin || $user_current_shift === 'shift2' || $user_current_shift === null);

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
$requested_section = trim($_GET['section'] ?? '');
$requested_view = trim($_GET['view'] ?? '');
if ($requested_section === 'job_orders' || $requested_view === 'jo_tracker') {
    $redirect_date = trim($_GET['start'] ?? $_GET['date_start'] ?? $_GET['report_date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $redirect_date)) {
        $redirect_date = date('Y-m-d');
    }
    header('Location: staff_fuel_sales_summary.php?' . http_build_query([
        'report_date' => $redirect_date,
        'tab' => 'merchandise',
    ]));
    exit;
}

$valid_sections = ['sales', 'deliveries', 'meter', 'payments', 'customers', 'activity'];
$section = trim($_GET['section'] ?? 'sales');
if ($section === 'job_orders') {
    $section = 'sales';
    $_GET['sub_tab'] = 'merch_sales';
}
if (!in_array($section, $valid_sections)) {
    // Check if legacy view parameter is used
    $view_param = trim($_GET['view'] ?? '');
    $legacy_map = [
        'daily_sales' => 'sales',
        'customer_linkage' => 'sales',
        'jo_tracker' => 'sales',
        'fuel_deliveries' => 'deliveries',
        'merch_deliveries' => 'deliveries',
        'meter_readings' => 'meter',
        'payment_status' => 'payments',
        'customer_reports' => 'customers',
        'personal_activity' => 'activity',
        'audit_trail' => 'activity',
    ];
    $section = $legacy_map[$view_param] ?? 'sales';
    if ($view_param === 'jo_tracker') {
        $_GET['sub_tab'] = 'merch_sales';
    }
}

$page_id = match($section) {
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
    if ($section === 'sales') $sub_tab = 'fuel_sales';
    elseif ($section === 'deliveries') $sub_tab = 'fuel_deliveries';
    elseif ($section === 'meter') $sub_tab = 'readings';
    elseif ($section === 'payments') $sub_tab = 'status_breakdown';
    elseif ($section === 'customers') $sub_tab = 'customer_list';
    elseif ($section === 'activity') $sub_tab = 'staff_activity';
}
if ($section === 'sales' && in_array($sub_tab, ['jo_list', 'staff_perf'], true)) {
    $sub_tab = 'merch_sales';
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

if ($section === 'sales' && $sub_tab === 'fuel_sales') {
    $redirect_date = trim($_GET['report_date'] ?? $_GET['date'] ?? $date_end);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $redirect_date)) {
        $redirect_date = $date_end;
    }
    $redirect_params = [
        'report_date' => $redirect_date,
        'tab' => 'fuel',
    ];
    if (isset($_GET['export'])) {
        $export = strtolower(trim((string)$_GET['export']));
        if ($export === 'pdf') {
            $redirect_params['export'] = 'pdf';
        } elseif (in_array($export, ['excel', 'csv'], true)) {
            $redirect_params['export'] = 'excel';
            $redirect_params['type'] = 'fuel';
        }
    }
    header('Location: staff_fuel_sales_summary.php?' . http_build_query($redirect_params));
    exit;
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
        } elseif ($sub_tab === 'fuel_sales') {
            // ---- Physical Tank Config (7 tanks) ----
            $PHYSICAL_TANKS = [
                ['fuel_type'=>'Diesel',       'label'=>'DIESEL - 1',       'tank'=>'UGT #1',  'capacity'=>14000, 'reorder_level'=>5000, 'critical_level'=>2500],
                ['fuel_type'=>'Diesel',       'label'=>'DIESEL - 2',       'tank'=>'UGT #2',  'capacity'=>14000, 'reorder_level'=>5000, 'critical_level'=>2500],
                ['fuel_type'=>'XCS Plus',     'label'=>'XCS PLUS - 1',     'tank'=>'UGT #3',  'capacity'=>14000, 'reorder_level'=>5000, 'critical_level'=>2500],
                ['fuel_type'=>'Xtra UNL',     'label'=>'XTR ADVANCE - 1',  'tank'=>'UGT #4',  'capacity'=>7000,  'reorder_level'=>2000, 'critical_level'=>1000],
                ['fuel_type'=>'Turbo Diesel', 'label'=>'TURBO DIESEL - 1', 'tank'=>'UGT #5',  'capacity'=>7000,  'reorder_level'=>2000, 'critical_level'=>1000],
                ['fuel_type'=>'Xtra UNL',     'label'=>'XTR ADVANCE - 2',  'tank'=>'UGT #6',  'capacity'=>14000, 'reorder_level'=>5000, 'critical_level'=>2500],
                ['fuel_type'=>'Kerosene',     'label'=>'KEROSENE - 1',     'tank'=>'UGT #7',  'capacity'=>14000, 'reorder_level'=>5000, 'critical_level'=>2500],
            ];

            // ---- 1. Meter Readings: Liters Sold = ending − beginning ± calibration ----
            $meter_readings = [];
            try {
                $stmt = $pdo->prepare("
                    SELECT
                        COALESCE(pump_id,'—') AS pump,
                        fuel_type,
                        COALESCE(previous_reading,0) AS beginning,
                        COALESCE(present_reading,0)  AS ending,
                        COALESCE(calibration,0)      AS calibration,
                        COALESCE(liters_sold,
                            ABS(COALESCE(present_reading,0)-COALESCE(previous_reading,0))+COALESCE(calibration,0)
                        ) AS liters_sold,
                        COALESCE(price_per_liter,0) AS price_per_liter,
                        COALESCE(total_amount,
                            (COALESCE(liters_sold,
                                ABS(COALESCE(present_reading,0)-COALESCE(previous_reading,0))+COALESCE(calibration,0)
                            ))*COALESCE(price_per_liter,0)
                        ) AS amount,
                        CASE
                            WHEN LOWER(COALESCE(shift_period,'')) IN ('first','morning','1')
                              OR shift_name LIKE '%First%' OR shift_name LIKE '%Morning%' THEN 'Shift 1'
                            WHEN LOWER(COALESCE(shift_period,'')) IN ('second','afternoon','2')
                              OR shift_name LIKE '%Second%' OR shift_name LIKE '%Afternoon%' THEN 'Shift 2'
                            ELSE COALESCE(NULLIF(shift_name,''),NULLIF(shift_period,''),'General')
                        END AS shift,
                        CASE
                            WHEN LOWER(COALESCE(payment_method,'')) IN ('','cash') THEN 'Cash'
                            WHEN LOWER(COALESCE(payment_method,'')) IN ('credit card','card','gcash','maya','e-wallet','ewallet') THEN 'Digital'
                            WHEN LOWER(COALESCE(payment_method,'')) IN ('credit','account receivable','utang') THEN 'Credit/AR'
                            ELSE COALESCE(NULLIF(payment_method,''),'Cash')
                        END AS payment_type,
                        DATE(transaction_date) AS transaction_date
                    FROM fuel_transactions
                    WHERE station_id=? AND DATE(transaction_date) BETWEEN ? AND ?
                    ORDER BY transaction_date ASC, shift ASC
                ");
                $stmt->execute([$station_id,$date_start,$date_end]);
                $meter_readings = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Exception $e) { $meter_readings = []; }

            // ---- 2. Volume Sales Summary ----
            $volume_sales = [];
            try {
                $stmt = $pdo->prepare("
                    SELECT fuel_type,
                        SUM(COALESCE(liters_sold,
                            ABS(COALESCE(present_reading,0)-COALESCE(previous_reading,0))+COALESCE(calibration,0)
                        )) AS total_liters,
                        AVG(COALESCE(price_per_liter,0)) AS avg_price,
                        SUM(COALESCE(total_amount,0))    AS total_amount
                    FROM fuel_transactions
                    WHERE station_id=? AND DATE(transaction_date) BETWEEN ? AND ?
                    GROUP BY fuel_type ORDER BY total_liters DESC
                ");
                $stmt->execute([$station_id,$date_start,$date_end]);
                $volume_sales = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Exception $e) { $volume_sales = []; }

            // ---- 3. Tank counts for capacity vs dispensed ----
            $tank_counts = [];
            foreach ($PHYSICAL_TANKS as $pt) $tank_counts[$pt['fuel_type']] = ($tank_counts[$pt['fuel_type']] ?? 0)+1;
            $dispensed_by_type = [];
            foreach ($volume_sales as $vs) $dispensed_by_type[$vs['fuel_type']] = (float)$vs['total_liters'];

            // ---- 4 & 5. Shift Sales — filter by shift_id / shift_period / shift_name ----
            $shift_base_sql = "
                SELECT fuel_type,
                    SUM(COALESCE(liters_sold,
                        ABS(COALESCE(present_reading,0)-COALESCE(previous_reading,0))+COALESCE(calibration,0)
                    )) AS total_liters,
                    SUM(COALESCE(total_amount,0)) AS total_amount,
                    SUM(CASE WHEN LOWER(COALESCE(payment_method,'')) IN ('','cash') THEN COALESCE(total_amount,0) ELSE 0 END) AS cash_amount,
                    SUM(CASE WHEN LOWER(COALESCE(payment_method,'')) IN ('credit card','card','gcash','maya','e-wallet','ewallet') THEN COALESCE(total_amount,0) ELSE 0 END) AS digital_amount,
                    SUM(CASE WHEN LOWER(COALESCE(payment_method,'')) IN ('credit','account receivable','utang') THEN COALESCE(total_amount,0) ELSE 0 END) AS credit_amount
                FROM fuel_transactions WHERE station_id=? AND DATE(transaction_date) BETWEEN ? AND ?
            ";
            $shift1_sales = [];
            try {
                $s = $pdo->prepare($shift_base_sql."AND(LOWER(COALESCE(shift_period,'')) IN('first','morning','1') OR shift_name LIKE '%First%' OR shift_name LIKE '%Morning%') GROUP BY fuel_type ORDER BY fuel_type");
                $s->execute([$station_id,$date_start,$date_end]);
                $shift1_sales = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch(Exception $e) { $shift1_sales = []; }

            $shift2_sales = [];
            try {
                $s = $pdo->prepare($shift_base_sql."AND(LOWER(COALESCE(shift_period,'')) IN('second','afternoon','2') OR shift_name LIKE '%Second%' OR shift_name LIKE '%Afternoon%') GROUP BY fuel_type ORDER BY fuel_type");
                $s->execute([$station_id,$date_start,$date_end]);
                $shift2_sales = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch(Exception $e) { $shift2_sales = []; }

            // ---- 6. Accounts Receivable — Suki/Credit customers with outstanding balances ----
            $ar_summary = [];
            try {
                $s = $pdo->prepare("
                    SELECT name AS customer_name,
                           COALESCE(balance, 0) AS outstanding_balance
                    FROM customers
                    WHERE station_id = ? AND (type = 'credit' OR COALESCE(balance,0) > 0)
                    ORDER BY balance DESC
                ");
                $s->execute([$station_id]);
                $ar_summary = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch(Exception $e) { $ar_summary = []; }

            // ---- 7. Service Income — from job_orders ----
            $service_income_rows  = [];
            $service_income_total = 0.0;
            $service_cash         = 0.0;
            $service_digital      = 0.0;
            $service_credit       = 0.0;
            try {
                $s = $pdo->prepare("
                    SELECT
                        COALESCE(NULLIF(jo.job_order_id,''), COALESCE(NULLIF(jo.job_order_number,''), CONCAT('JO-',jo.id))) AS job_ref,
                        COALESCE(jo.customer_name,'Walk-in')     AS customer_name,
                        COALESCE(jo.vehicle_plate,'—')           AS vehicle_plate,
                        COALESCE(jo.service_type,'—')            AS service_type,
                        COALESCE(jo.total_cost, jo.estimated_cost, 0) AS total_cost,
                        COALESCE(jo.payment_method,'Cash')       AS payment_method,
                        COALESCE(jo.status,'Pending')            AS status,
                        jo.created_at
                    FROM job_orders jo
                    WHERE jo.station_id = ?
                      AND DATE(jo.created_at) BETWEEN ? AND ?
                      AND jo.status NOT IN ('Cancelled','Rejected')
                    ORDER BY jo.created_at DESC
                ");
                $s->execute([$station_id, $date_start, $date_end]);
                $service_income_rows = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];

                foreach ($service_income_rows as $sr) {
                    $amt = (float)$sr['total_cost'];
                    $pm  = strtolower($sr['payment_method']);
                    $service_income_total += $amt;
                    if (in_array($pm, ['','cash'])) {
                        $service_cash    += $amt;
                    } elseif (in_array($pm, ['credit card','card','gcash','maya','e-wallet','ewallet','digital'])) {
                        $service_digital += $amt;
                    } else {
                        $service_credit  += $amt;
                    }
                }
            } catch(Exception $e) { $service_income_rows = []; }

            // ---- Overall Totals ----
            $grand_liters  = (float)array_sum(array_column($volume_sales,'total_liters'));
            $grand_amount  = (float)array_sum(array_column($volume_sales,'total_amount'));
            $s1_cash       = (float)array_sum(array_column($shift1_sales,'cash_amount'));
            $s2_cash       = (float)array_sum(array_column($shift2_sales,'cash_amount'));
            $s1_digital    = (float)array_sum(array_column($shift1_sales,'digital_amount'));
            $s2_digital    = (float)array_sum(array_column($shift2_sales,'digital_amount'));
            $s1_credit     = (float)array_sum(array_column($shift1_sales,'credit_amount'));
            $s2_credit     = (float)array_sum(array_column($shift2_sales,'credit_amount'));
            $total_cash    = $s1_cash + $s2_cash + $service_cash;
            $total_digital = $s1_digital + $s2_digital + $service_digital;
            $total_credit  = $s1_credit + $s2_credit + $service_credit;
            $cash_in_bank  = $total_cash + $total_digital;
            // ---- Cash Reconciliation Metrics ----
            $cash_on_hand          = min(5000.0, $total_cash);
            $cash_in_bank_deposit  = $cash_in_bank - $cash_on_hand;
            $variance              = ($cash_in_bank_deposit + $cash_on_hand) - $cash_in_bank;
            // Outstanding A/R from customers
            $total_ar_outstanding  = (float)array_sum(array_column($ar_summary,'outstanding_balance'));
            // Grand total including service
            $grand_total_all       = $grand_amount + $service_income_total;

            $summary_cards = [
                ['label'=>'Total Liters Sold',       'value'=>number_format($grand_liters,2).' L',          'icon'=>'fa-gas-pump',        'class'=>'stat-blue'],
                ['label'=>'Grand Total Sales',        'value'=>'₱'.number_format($grand_amount,2),           'icon'=>'fa-peso-sign',       'class'=>'stat-green'],
                ['label'=>'Service Income',           'value'=>'₱'.number_format($service_income_total,2),   'icon'=>'fa-screwdriver-wrench','class'=>'stat-teal'],
                ['label'=>'Digital/Card Sales',       'value'=>'₱'.number_format($total_digital,2),          'icon'=>'fa-credit-card',     'class'=>'stat-purple'],
                ['label'=>'Accounts Receivable (A/R)','value'=>'₱'.number_format($total_ar_outstanding,2),   'icon'=>'fa-file-invoice',    'class'=>'stat-orange'],
                ['label'=>'Cash in Bank (Deposits)',  'value'=>'₱'.number_format($cash_in_bank_deposit,2),   'icon'=>'fa-building-columns','class'=>'stat-red'],
            ];
            $report_data = $meter_readings ?: [['note'=>'No fuel transactions for this period.']];
        } elseif ($sub_tab === 'merch_sales') {
            // ---- Merchandise Sales data fetching ----
            $merch_items     = [];
            $merch_shift1    = [];
            $merch_shift2    = [];
            $merch_cat_totals= [];
            $merch_ar        = [];
            $merch_grand_sales   = 0.0;
            $merch_grand_qty     = 0;
            $merch_cash_total    = 0.0;
            $merch_digital_total = 0.0;
            $merch_credit_total  = 0.0;
            $merch_cash_in_bank  = 0.0;
            $merch_s1_cash       = 0.0;
            $merch_s1_digital    = 0.0;
            $merch_s1_credit     = 0.0;
            $merch_s2_cash       = 0.0;
            $merch_s2_digital    = 0.0;
            $merch_s2_credit     = 0.0;

            try {
                // 1. Sales (Stock-Out) per product
                $stmt = $pdo->prepare("
                    SELECT mti.product_id,
                           mti.product_name,
                           mti.category,
                           mti.size_variant,
                           mti.unit_price,
                           SUM(mti.quantity)  AS qty_sold,
                           SUM(mti.subtotal)  AS amount_sold,
                           GROUP_CONCAT(DISTINCT COALESCE(u.first_name,'') ORDER BY u.first_name SEPARATOR ', ') AS encoders,
                           GROUP_CONCAT(DISTINCT COALESCE(mt.remarks,'') SEPARATOR '; ') AS remarks
                    FROM merchandise_transaction_items mti
                    JOIN merchandise_transactions mt ON mti.transaction_id = mt.id
                    LEFT JOIN users u ON mt.staff_id = u.id
                    WHERE mt.station_id = ?
                      AND DATE(mt.created_at) BETWEEN ? AND ?
                      AND mti.item_type = 'merchandise'
                    GROUP BY mti.product_id, mti.product_name, mti.category, mti.size_variant, mti.unit_price
                    ORDER BY mti.category, mti.product_name
                ");
                $stmt->execute([$station_id, $date_start, $date_end]);
                $sales_rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                // 2. Stock-In per product
                $stmt = $pdo->prepare("
                    SELECT msi.product_id, SUM(msi.qty_received) AS qty_in
                    FROM merchandise_stock_in msi
                    WHERE msi.station_id = ? AND DATE(msi.encoded_at) BETWEEN ? AND ?
                    GROUP BY msi.product_id
                ");
                $stmt->execute([$station_id, $date_start, $date_end]);
                $stock_in_map = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $si) {
                    $stock_in_map[(int)$si['product_id']] = (float)$si['qty_in'];
                }

                // 3. Stock-Out AFTER date range
                $stmt = $pdo->prepare("
                    SELECT mti.product_id, SUM(mti.quantity) AS qty_after
                    FROM merchandise_transaction_items mti
                    JOIN merchandise_transactions mt ON mti.transaction_id = mt.id
                    WHERE mt.station_id = ? AND DATE(mt.created_at) > ?
                      AND mti.item_type = 'merchandise'
                    GROUP BY mti.product_id
                ");
                $stmt->execute([$station_id, $date_end]);
                $sales_after_map = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                    $sales_after_map[(int)$r['product_id']] = (float)$r['qty_after'];
                }

                // 4. Stock-In AFTER date range
                $stmt = $pdo->prepare("
                    SELECT product_id, SUM(qty_received) AS qty_after_in
                    FROM merchandise_stock_in
                    WHERE station_id = ? AND DATE(encoded_at) > ?
                    GROUP BY product_id
                ");
                $stmt->execute([$station_id, $date_end]);
                $in_after_map = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                    $in_after_map[(int)$r['product_id']] = (float)$r['qty_after_in'];
                }

                // 5. Current stock
                $stmt = $pdo->prepare("
                    SELECT id, product_name, category, sku, unit_price, stock AS current_stock
                    FROM inventory_products
                    WHERE station_id = ? AND status = 'active' AND category != 'Fuel'
                    ORDER BY category, product_name
                ");
                $stmt->execute([$station_id]);
                $ip_rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $ip_by_id = [];
                foreach ($ip_rows as $ip) { $ip_by_id[(int)$ip['id']] = (float)$ip['current_stock']; }

                // 6. Assemble merch_items
                $seen_pids = [];
                foreach ($sales_rows as $sr) {
                    $pid = (int)$sr['product_id'];
                    if (isset($seen_pids[$pid])) continue;
                    $seen_pids[$pid] = true;
                    $cur_stock       = $ip_by_id[$pid] ?? 0;
                    $qty_sold        = (float)$sr['qty_sold'];
                    $qty_in          = $stock_in_map[$pid] ?? 0;
                    $sales_after     = $sales_after_map[$pid] ?? 0;
                    $in_after        = $in_after_map[$pid] ?? 0;
                    $ending_stock    = $cur_stock + $sales_after - $in_after;
                    $beginning_stock = $ending_stock - $qty_in + $qty_sold;
                    $merch_items[] = [
                        'product_id'      => $pid,
                        'product_name'    => $sr['product_name'],
                        'category'        => $sr['category'],
                        'size_variant'    => $sr['size_variant'],
                        'unit_price'      => (float)$sr['unit_price'],
                        'beginning_stock' => max(0, $beginning_stock),
                        'stock_in'        => $qty_in,
                        'stock_out'       => $qty_sold,
                        'ending_stock'    => max(0, $ending_stock),
                        'amount'          => (float)$sr['amount_sold'],
                        'encoders'        => $sr['encoders'] ?? '—',
                        'remarks'         => $sr['remarks'] ?? '',
                    ];
                    $merch_grand_sales += (float)$sr['amount_sold'];
                    $merch_grand_qty   += (int)$qty_sold;
                }

                // 7. Shift breakdowns
                $is_shift1 = "LOWER(COALESCE(mt.shift_period,'')) IN ('first','morning','1') OR mt.shift_name LIKE '%First%' OR mt.shift_name LIKE '%Morning%'";
                $is_shift2 = "LOWER(COALESCE(mt.shift_period,'')) IN ('second','afternoon','evening','2') OR mt.shift_name LIKE '%Second%' OR mt.shift_name LIKE '%Afternoon%' OR mt.shift_name LIKE '%Evening%'";
                foreach ([1 => $is_shift1, 2 => $is_shift2] as $sn => $shift_cond) {
                    $stmt = $pdo->prepare("
                        SELECT mti.category,
                               SUM(mti.quantity)  AS total_qty,
                               SUM(mti.subtotal)  AS total_amount,
                               SUM(CASE WHEN LOWER(COALESCE(mt.payment_method,'cash')) IN ('','cash') THEN mti.subtotal ELSE 0 END) AS cash_amount,
                               SUM(CASE WHEN LOWER(COALESCE(mt.payment_method,'')) IN ('credit card','card','gcash','maya','e-wallet','ewallet','digital') THEN mti.subtotal ELSE 0 END) AS digital_amount,
                               SUM(CASE WHEN LOWER(COALESCE(mt.payment_method,'')) IN ('credit','account receivable','utang','suki') THEN mti.subtotal ELSE 0 END) AS credit_amount
                        FROM merchandise_transaction_items mti
                        JOIN merchandise_transactions mt ON mti.transaction_id = mt.id
                        WHERE mt.station_id = ? AND DATE(mt.created_at) BETWEEN ? AND ?
                          AND mti.item_type = 'merchandise' AND ($shift_cond)
                        GROUP BY mti.category ORDER BY mti.category
                    ");
                    $stmt->execute([$station_id, $date_start, $date_end]);
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    if ($sn === 1) {
                        $merch_shift1 = $rows;
                        foreach ($rows as $r) { $merch_s1_cash += (float)$r['cash_amount']; $merch_s1_digital += (float)$r['digital_amount']; $merch_s1_credit += (float)$r['credit_amount']; }
                    } else {
                        $merch_shift2 = $rows;
                        foreach ($rows as $r) { $merch_s2_cash += (float)$r['cash_amount']; $merch_s2_digital += (float)$r['digital_amount']; $merch_s2_credit += (float)$r['credit_amount']; }
                    }
                }
                $merch_cash_total    = $merch_s1_cash    + $merch_s2_cash;
                $merch_digital_total = $merch_s1_digital + $merch_s2_digital;
                $merch_credit_total  = $merch_s1_credit  + $merch_s2_credit;
                $merch_cash_in_bank  = $merch_cash_total + $merch_digital_total;

                // 8. Category Totals
                $stmt = $pdo->prepare("
                    SELECT mti.category, SUM(mti.quantity) AS total_qty, SUM(mti.subtotal) AS total_amount
                    FROM merchandise_transaction_items mti
                    JOIN merchandise_transactions mt ON mti.transaction_id = mt.id
                    WHERE mt.station_id = ? AND DATE(mt.created_at) BETWEEN ? AND ?
                      AND mti.item_type = 'merchandise'
                    GROUP BY mti.category ORDER BY total_amount DESC
                ");
                $stmt->execute([$station_id, $date_start, $date_end]);
                $merch_cat_totals = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                // 9. A/R Summary
                $stmt = $pdo->prepare("
                    SELECT mt.transaction_id, mt.customer_name,
                           mt.total_amount AS amount,
                           COALESCE(mt.payment_status,'Pending') AS status,
                           DATE_ADD(DATE(mt.created_at), INTERVAL 30 DAY) AS due_date,
                           mt.created_at
                    FROM merchandise_transactions mt
                    WHERE mt.station_id = ? AND DATE(mt.created_at) BETWEEN ? AND ?
                      AND LOWER(COALESCE(mt.payment_method,'')) IN ('credit','account receivable','utang','suki')
                    ORDER BY mt.created_at DESC
                ");
                $stmt->execute([$station_id, $date_start, $date_end]);
                $merch_ar = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            } catch (Exception $e) {
                $report_error = $e->getMessage();
            }

            $summary_cards = [
                ['label' => 'Total Merch Sales',    'value' => '₱'.number_format($merch_grand_sales,2),   'icon' => 'fa-tags',            'class' => 'stat-blue'],
                ['label' => 'Items Sold',            'value' => number_format($merch_grand_qty),           'icon' => 'fa-box-open',        'class' => 'stat-red'],
                ['label' => 'Cash Sales',            'value' => '₱'.number_format($merch_cash_total,2),    'icon' => 'fa-money-bill-wave', 'class' => 'stat-green'],
                ['label' => 'Digital / Card',        'value' => '₱'.number_format($merch_digital_total,2), 'icon' => 'fa-credit-card',     'class' => 'stat-purple'],
                ['label' => 'Accounts Receivable',   'value' => '₱'.number_format($merch_credit_total,2),  'icon' => 'fa-file-invoice',    'class' => 'stat-orange'],
                ['label' => 'Cash in Bank',          'value' => '₱'.number_format($merch_cash_in_bank,2),  'icon' => 'fa-building-columns','class' => 'stat-teal'],
            ];
            $report_data = $merch_items ?: [];
        }
    }

    if ($section === 'job_orders') {
        // job_orders FK to users — 'created_by' confirmed present
        $jo_enc = has_col($pdo,'job_orders','created_by') ? 'created_by' : (has_col($pdo,'job_orders','user_id') ? 'user_id' : 'created_by');
        $jo_num_col = has_col($pdo,'job_orders','job_order_number')
                        ? "COALESCE(NULLIF(jo.job_order_number,''),CONCAT('JO-',jo.id))"
                        : "CONCAT('JO-',jo.id)";
        $cost_col   = has_col($pdo,'job_orders','total_cost')
                        ? 'COALESCE(jo.total_cost,jo.estimated_cost,0)'
                        : 'COALESCE(jo.estimated_cost,0)';
        // Labor fee column — check each independently
        if (has_col($pdo,'job_orders','actual_labor_cost') && has_col($pdo,'job_orders','estimated_labor_cost')) {
            $labor_col = 'COALESCE(jo.actual_labor_cost,jo.estimated_labor_cost,0)';
        } elseif (has_col($pdo,'job_orders','actual_labor_cost')) {
            $labor_col = 'COALESCE(jo.actual_labor_cost,0)';
        } elseif (has_col($pdo,'job_orders','estimated_labor_cost')) {
            $labor_col = 'COALESCE(jo.estimated_labor_cost,0)';
        } else {
            $labor_col = '0';
        }
        // Payment column — check each independently; never reference a column that may not exist
        if (has_col($pdo,'job_orders','payment_method')) {
            $pay_col = "COALESCE(jo.payment_method,'—')";
        } elseif (has_col($pdo,'job_orders','payment_mode')) {
            $pay_col = "COALESCE(jo.payment_mode,'—')";
        } else {
            $pay_col = "'—'";
        }
        // Encoder name — try users table with name or first_name+last_name
        // Encoder display: users table has first_name + last_name + username (no 'name' col)
        $enc_col  = "'—'";
        $enc_join = '';
        try {
            $ut = $pdo->query("SHOW TABLES LIKE 'users'")->fetchAll();
            if (!empty($ut)) {
                if (has_col($pdo,'users','first_name') && has_col($pdo,'users','last_name')) {
                    $enc_col = "TRIM(COALESCE(CONCAT(u.first_name,' ',u.last_name),'—'))";
                } elseif (has_col($pdo,'users','username')) {
                    $enc_col = "COALESCE(u.username,'—')";
                } elseif (has_col($pdo,'users','name')) {
                    $enc_col = "COALESCE(u.name,'—')";
                }
                // PK of users table is 'id'
                $u_pk     = has_col($pdo,'users','user_id') ? 'user_id' : 'id';
                $enc_join = "LEFT JOIN users u ON jo.$jo_enc = u.$u_pk";
            }
        } catch (Exception $e) {}
        // Customer contact
        $cust_join  = '';
        $cust_phone = "''";
        $cust_ref   = "'0'";
        try {
            $ct = $pdo->query("SHOW TABLES LIKE 'customers'")->fetchAll();
            if (!empty($ct) && has_col($pdo,'customers','contact_number')) {
                $cust_phone = 'COALESCE(c.contact_number,\'\')';
                $cust_ref   = 'COALESCE(c.id,0)';
                $cust_join  = 'LEFT JOIN customers c ON jo.customer_id = c.id';
            }
        } catch (Exception $e) {}

        // Initialise shift summary arrays (used in export + HTML render)
        $jo_s1_jobs = []; $jo_s2_jobs = [];
        $jo_s1 = ['services'=>0,'amount'=>0.0,'cash'=>0.0,'digital'=>0.0,'credit'=>0.0];
        $jo_s2 = ['services'=>0,'amount'=>0.0,'cash'=>0.0,'digital'=>0.0,'credit'=>0.0];

        if ($sub_tab === 'jo_list') {
            try {
                $stmt = $pdo->prepare("
                    SELECT jo.id,
                           $jo_num_col AS job_order_id,
                           COALESCE(jo.customer_name,'Walk-in') AS customer_name,
                           $cust_phone AS contact_number,
                           $cust_ref   AS customer_ref_id,
                           COALESCE(jo.service_type,'—') AS service_type,
                           COALESCE(jo.status,'Pending') AS status,
                           $cost_col  AS total_cost,
                           $labor_col AS labor_fee,
                           $pay_col   AS payment_mode,
                           jo.created_at,
                           COALESCE(jo.notes,'') AS remarks,
                           $enc_col AS encoder_name
                    FROM job_orders jo
                    $cust_join
                    $enc_join
                    WHERE jo.station_id = ? AND DATE(jo.created_at) BETWEEN ? AND ?
                    ORDER BY jo.created_at DESC
                ");
                $stmt->execute([$station_id, $date_start, $date_end]);
                $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Exception $e) {
                $report_data = [];
                $report_error = 'Job Orders query error: ' . $e->getMessage();
            }

            // Fetch parts per job order
            $has_jop = !empty($pdo->query("SHOW TABLES LIKE 'job_order_parts'")->fetchAll());
            foreach ($report_data as &$job) {
                $job['parts_used'] = [];
                if ($has_jop) {
                    try {
                        $ps = $pdo->prepare("
                            SELECT jop.quantity_used,
                                   jop.unit_cost,
                                   COALESCE(p.name,'Unknown Part') AS product_name
                            FROM job_order_parts jop
                            LEFT JOIN products p ON jop.product_id = p.id
                            WHERE jop.job_order_id = ?
                        ");
                        $ps->execute([$job['id']]);
                        $job['parts_used'] = $ps->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    } catch (Exception $e) {}
                }
            }
            unset($job);

            // Compute shift 1 / shift 2 breakdowns
            foreach ($report_data as $job) {
                $hour   = (int)date('H', strtotime($job['created_at']));
                $shift  = ($hour >= 6 && $hour < 14) ? 1 : 2;
                $amt    = (float)$job['total_cost'];
                $mode   = strtolower(trim($job['payment_mode']));
                $is_cash    = ($mode === 'cash' || $mode === '');
                $is_digital = in_array($mode, ['digital','gcash','maya','card','credit card','e-wallet','ewallet']);
                if ($shift === 1) {
                    $jo_s1_jobs[] = $job;
                    $jo_s1['services']++;
                    $jo_s1['amount'] += $amt;
                    if ($is_cash)         $jo_s1['cash']    += $amt;
                    elseif ($is_digital)  $jo_s1['digital'] += $amt;
                    else                  $jo_s1['credit']  += $amt;
                } else {
                    $jo_s2_jobs[] = $job;
                    $jo_s2['services']++;
                    $jo_s2['amount'] += $amt;
                    if ($is_cash)         $jo_s2['cash']    += $amt;
                    elseif ($is_digital)  $jo_s2['digital'] += $amt;
                    else                  $jo_s2['credit']  += $amt;
                }
            }

            $total_jo      = count($report_data);
            $completed     = count(array_filter($report_data, fn($r)=>strtolower($r['status'])==='completed'));
            $pending       = count(array_filter($report_data, fn($r)=>in_array(strtolower($r['status']),['pending','pending validation','in progress'])));
            $overall_amt   = (float)array_sum(array_column($report_data,'total_cost'));

            $summary_cards = [
                ['label'=>'Total Job Orders', 'value'=>$total_jo,                                'icon'=>'fa-wrench',         'class'=>'stat-blue'],
                ['label'=>'Completed Jobs',   'value'=>$completed,                               'icon'=>'fa-circle-check',   'class'=>'stat-green'],
                ['label'=>'Pending/Active',   'value'=>$pending,                                 'icon'=>'fa-hourglass-half', 'class'=>'stat-orange'],
                ['label'=>'Total Amount',     'value'=>'₱'.number_format($overall_amt,2),        'icon'=>'fa-peso-sign',      'class'=>'stat-purple'],
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
                WHERE jo.station_id=? AND DATE(jo.created_at) BETWEEN ? AND ?
                GROUP BY DATE(jo.created_at)
                ORDER BY work_date DESC
            ");
            $stmt->execute([$station_id, $date_start, $date_end]);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $total_created   = array_sum(array_column($report_data,'jobs_created'));
            $total_completed = array_sum(array_column($report_data,'jobs_completed'));
            $completion_rate = $total_created > 0 ? ($total_completed / $total_created * 100) : 0;

            $summary_cards = [
                ['label'=>'Jobs Encoded',      'value'=>$total_created,                               'icon'=>'fa-folder-plus', 'class'=>'stat-blue'],
                ['label'=>'Completed Status',  'value'=>$total_completed,                             'icon'=>'fa-check-double','class'=>'stat-green'],
                ['label'=>'Completion Rate',   'value'=>number_format($completion_rate,1).'%',        'icon'=>'fa-chart-pie',   'class'=>'stat-purple'],
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
                                COALESCE((SELECT CONCAT(first_name, ' ', last_name) FROM users WHERE id = encoded_by), '—') AS encoded_by
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
                           COALESCE((SELECT CONCAT(first_name, ' ', last_name) FROM users WHERE id = mt.staff_id),'—') AS encoded_by
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
                           COALESCE((SELECT CONCAT(first_name, ' ', last_name) FROM users WHERE id = mt.staff_id),'—') AS encoded_by
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

// Safeguard: ensure merch_ variables exist even if section/sub_tab mismatch
if (!isset($merch_items))     $merch_items     = [];
if (!isset($merch_shift1))    $merch_shift1    = [];
if (!isset($merch_shift2))    $merch_shift2    = [];
if (!isset($merch_cat_totals))$merch_cat_totals= [];
if (!isset($merch_ar))        $merch_ar        = [];
if (!isset($merch_grand_sales))   $merch_grand_sales   = 0.0;
if (!isset($merch_grand_qty))     $merch_grand_qty     = 0;
if (!isset($merch_cash_total))    $merch_cash_total    = 0.0;
if (!isset($merch_digital_total)) $merch_digital_total = 0.0;
if (!isset($merch_credit_total))  $merch_credit_total  = 0.0;
if (!isset($merch_cash_in_bank))  $merch_cash_in_bank  = 0.0;
if (!isset($merch_s1_cash))       $merch_s1_cash       = 0.0;
if (!isset($merch_s1_digital))    $merch_s1_digital    = 0.0;
if (!isset($merch_s1_credit))     $merch_s1_credit     = 0.0;
if (!isset($merch_s2_cash))       $merch_s2_cash       = 0.0;
if (!isset($merch_s2_digital))    $merch_s2_digital    = 0.0;
if (!isset($merch_s2_credit))     $merch_s2_credit     = 0.0;

// ============================================================
// FUEL SALES — DEDICATED EXPORT HANDLER
// ============================================================
if (isset($_GET['export']) && $sub_tab === 'fuel_sales') {
    $format = trim($_GET['export']);

    // ---- Inline helper for shift table CSV rows ----
    function _fs_shift_csv($out, $rows, $label) {
        fputcsv($out, []);
        fputcsv($out, [$label]);
        fputcsv($out, ['FUEL TYPE','LITERS SOLD','TOTAL SALES (₱)','CASH RECEIVED (₱)','DIGITAL (₱)','CREDIT/AR (₱)']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['fuel_type'],
                number_format((float)$r['total_liters'],2),
                number_format((float)$r['total_amount'],2),
                number_format((float)$r['cash_amount'],2),
                number_format((float)$r['digital_amount'],2),
                number_format((float)$r['credit_amount'],2),
            ]);
        }
        if (empty($rows)) fputcsv($out, ['(No data)']);
    }

    if (in_array($format, ['excel','csv'])) {
        header('Content-Type: text/csv; charset=utf-8');
        $fn = 'daily_fuel_sales_' . $date_start . '_to_' . $date_end . '.csv';
        header("Content-Disposition: attachment; filename=\"{$fn}\"");
        $out = fopen('php://output','w');
        echo "\xEF\xBB\xBF";

        fputcsv($out, ['DAILY FUEL SALES REPORT']);
        fputcsv($out, ['Station:', $station_name]);
        fputcsv($out, ['Date Range:', $date_start . ' to ' . $date_end]);
        fputcsv($out, ['Generated:', date('Y-m-d H:i:s')]);

        // Meter Readings
        fputcsv($out, []);
        fputcsv($out, ['METER READING TABLE']);
        fputcsv($out, ['TANKER ID','FUEL TYPE','BEGINNING READING','ENDING READING','CALIBRATION','LITERS SOLD','UNIT PRICE (₱)','AMOUNT (₱)','SHIFT','DATE']);
        foreach ($meter_readings as $r) {
            $tanker_id = is_numeric($r['pump']) ? sprintf('TK-%02d', $r['pump']) : $r['pump'];
            fputcsv($out, [$tanker_id,$r['fuel_type'],$r['beginning'],$r['ending'],$r['calibration'],$r['liters_sold'],$r['price_per_liter'],$r['amount'],$r['shift'],$r['transaction_date']]);
        }
        if (empty($meter_readings)) fputcsv($out, ['(No data)']);

        // Volume Sales
        fputcsv($out, []);
        fputcsv($out, ['VOLUME SALES SUMMARY']);
        fputcsv($out, ['FUEL TYPE','TOTAL LITERS SOLD','AVG PRICE/L (₱)','TOTAL AMOUNT (₱)']);
        foreach ($volume_sales as $r) {
            fputcsv($out, [$r['fuel_type'],number_format((float)$r['total_liters'],2),number_format((float)$r['avg_price'],2),number_format((float)$r['total_amount'],2)]);
        }
        if (empty($volume_sales)) fputcsv($out, ['(No data)']);

        // Tank Sales
        fputcsv($out, []);
        fputcsv($out, ['TANK SALES SUMMARY (Capacity vs Dispensed)']);
        fputcsv($out, ['TANK','FUEL TYPE','TANK CAPACITY (L)','DISPENSED LITERS (L)','UTILIZATION (%)']);
        foreach ($PHYSICAL_TANKS as $pt) {
            $ft = $pt['fuel_type'];
            $cnt = $tank_counts[$ft] ?? 1;
            $disp = ($dispensed_by_type[$ft] ?? 0) / $cnt;
            $util = $pt['capacity'] > 0 ? round($disp/$pt['capacity']*100,1) : 0;
            fputcsv($out,[$pt['label'],$ft,number_format($pt['capacity'],2),number_format($disp,2),$util.'%']);
        }

        // Shifts (gated by user's active shift)
        if ($can_see_shift1) {
            _fs_shift_csv($out, $shift1_sales, 'SHIFT 1 SALES & CASH SUMMARY (6:00 AM – 2:00 PM)');
        }
        if ($can_see_shift2) {
            _fs_shift_csv($out, $shift2_sales, 'SHIFT 2 SALES & CASH SUMMARY (2:00 PM – 12:00 MN)');
        }

        // AR Summary — Customer Name + Outstanding Balance
        fputcsv($out, []);
        fputcsv($out, ['ACCOUNTS RECEIVABLE SUMMARY']);
        fputcsv($out, ['CUSTOMER NAME','OUTSTANDING BALANCE (₱)']);
        foreach ($ar_summary as $r) {
            fputcsv($out,[$r['customer_name'],number_format((float)$r['outstanding_balance'],2)]);
        }
        if (empty($ar_summary)) fputcsv($out, ['(No AR records)']);

        // Service Income Summary
        fputcsv($out, []);
        fputcsv($out, ['SERVICE INCOME (JOB ORDERS)']);
        fputcsv($out, ['JOB REF','CUSTOMER NAME','VEHICLE PLATE','SERVICE TYPE','PAYMENT METHOD','TOTAL COST (₱)']);
        foreach ($service_income_rows as $r) {
            fputcsv($out, [$r['job_ref'],$r['customer_name'],$r['vehicle_plate'],$r['service_type'],$r['payment_method'],number_format((float)$r['total_cost'],2)]);
        }
        if (empty($service_income_rows)) fputcsv($out, ['(No service records)']);

        // Overall
        fputcsv($out, []);
        fputcsv($out, ['OVERALL DAILY SUMMARY']);
        fputcsv($out, ['METRIC','VALUE']);
        fputcsv($out, ['Total Liters Sold',           number_format($grand_liters,2).' L']);
        fputcsv($out, ['Total Fuel Sales',            '₱'.number_format($grand_amount,2)]);
        fputcsv($out, ['Total Service Income',        '₱'.number_format($service_income_total,2)]);
        fputcsv($out, ['Grand Total (Fuel+Services)', '₱'.number_format($grand_total_all,2)]);
        fputcsv($out, ['Total Cash in Bank (Deposits)','₱'.number_format($cash_in_bank_deposit,2)]);
        fputcsv($out, ['Cash on Hand',                 '₱'.number_format($cash_on_hand,2)]);
        fputcsv($out, ['Variance',                     '₱'.number_format($variance,2)]);

        fclose($out);
        exit;
    }

    if ($format === 'pdf') {
        // Build a clean, printable HTML page
        $print_rows_meter = '';
        foreach ($meter_readings as $i => $r) {
            $tk = is_numeric($r['pump']) ? sprintf('TK-%02d', $r['pump']) : htmlspecialchars($r['pump']);
            $print_rows_meter .= '<tr><td>'.($i+1).'</td><td><strong>'.$tk.'</strong></td><td>'.htmlspecialchars($r['fuel_type']).'</td><td>'.number_format((float)$r['beginning'],2).'</td><td>'.number_format((float)$r['ending'],2).'</td><td>'.number_format((float)$r['calibration'],2).'</td><td><strong>'.number_format((float)$r['liters_sold'],2).' L</strong></td><td>₱'.number_format((float)$r['price_per_liter'],2).'</td><td><strong>₱'.number_format((float)$r['amount'],2).'</strong></td><td>'.htmlspecialchars($r['shift']).'</td><td>'.htmlspecialchars($r['transaction_date']).'</td></tr>';
        }
        $print_rows_vol = '';
        foreach ($volume_sales as $r) {
            $print_rows_vol .= '<tr><td><strong>'.htmlspecialchars($r['fuel_type']).'</strong></td><td>'.number_format((float)$r['total_liters'],2).' L</td><td>₱'.number_format((float)$r['avg_price'],2).'</td><td><strong>₱'.number_format((float)$r['total_amount'],2).'</strong></td></tr>';
        }
        $print_rows_tank = '';
        foreach ($PHYSICAL_TANKS as $pt) {
            $ft = $pt['fuel_type']; $cnt = $tank_counts[$ft]??1;
            $disp = ($dispensed_by_type[$ft]??0)/$cnt;
            $util = $pt['capacity']>0?round($disp/$pt['capacity']*100,1):0;
            $col = $util>80?'#cc0000':($util>50?'#e67e22':'#22c55e');
            $print_rows_tank .= '<tr><td><strong>'.htmlspecialchars($pt['label']).'</strong></td><td>'.htmlspecialchars($ft).'</td><td>'.number_format($pt['capacity'],0).' L</td><td>'.number_format($disp,2).' L</td><td style="color:'.$col.';font-weight:700;">'.$util.'%</td></tr>';
        }
        function _fs_shift_rows($rows) {
            $out='';
            foreach($rows as $r){
                $out.='<tr><td><strong>'.htmlspecialchars($r['fuel_type']).'</strong></td><td>'.number_format((float)$r['total_liters'],2).' L</td><td>₱'.number_format((float)$r['total_amount'],2).'</td><td>₱'.number_format((float)$r['cash_amount'],2).'</td><td>₱'.number_format((float)$r['digital_amount'],2).'</td><td>₱'.number_format((float)$r['credit_amount'],2).'</td></tr>';
            }
            if(empty($rows)) $out='<tr><td colspan="6" style="text-align:center;color:#9ca3af;">No data</td></tr>';
            return $out;
        }
        $print_rows_s1 = _fs_shift_rows($shift1_sales);
        $print_rows_s2 = _fs_shift_rows($shift2_sales);
        $print_rows_ar = '';
        foreach ($ar_summary as $r) {
            $print_rows_ar .= '<tr><td><strong>'.htmlspecialchars($r['customer_name']).'</strong></td><td><strong>₱'.number_format((float)$r['outstanding_balance'],2).'</strong></td></tr>';
        }
        if (empty($ar_summary)) $print_rows_ar = '<tr><td colspan="2" style="text-align:center;color:#9ca3af;">No accounts receivable</td></tr>';

        $print_rows_service = '';
        foreach ($service_income_rows as $r) {
            $print_rows_service .= '<tr><td><strong>'.htmlspecialchars($r['job_ref']).'</strong></td><td>'.htmlspecialchars($r['customer_name']).'</td><td><code>'.htmlspecialchars($r['vehicle_plate']).'</code></td><td>'.htmlspecialchars($r['service_type']).'</td><td>'.htmlspecialchars($r['payment_method']).'</td><td><strong>₱'.number_format((float)$r['total_cost'],2).'</strong></td></tr>';
        }
        if (empty($service_income_rows)) $print_rows_service = '<tr><td colspan="6" style="text-align:center;color:#9ca3af;">No service income records</td></tr>';

        $th = 'background:#002F70;color:#fff;padding:9px 10px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;white-space:nowrap;';
        $td_css = 'border-bottom:1px solid #e9ecef;padding:8px 10px;font-size:12px;';
        $tbl_css = 'width:100%;border-collapse:collapse;margin-bottom:0;';
        $sec_head = 'background:#002F70;color:#fff;padding:10px 14px;font-size:13px;font-weight:700;border-radius:6px 6px 0 0;display:flex;align-items:center;gap:8px;';

        echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
<title>Daily Fuel Sales Report — '.$date_start.' to '.$date_end.'</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:Arial,sans-serif;color:#111;background:#fff;padding:20px;}
.print-header{text-align:center;margin-bottom:20px;border-bottom:3px solid #002F70;padding-bottom:12px;}
.print-header h1{font-size:20px;font-weight:800;color:#002F70;letter-spacing:-.5px;}
.print-header p{font-size:12px;color:#667085;margin-top:4px;}
.section{margin-bottom:22px;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;}
.section-head{'.$sec_head.'}
.section-head .ico{font-size:16px;}
table{'.$tbl_css.'}
th{'.$th.'}
td{'.$td_css.'}
tr:last-child td{border-bottom:none;}
.summary-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:22px;}
.s-card{border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;border-left:4px solid #002F70;}
.s-card .val{font-size:18px;font-weight:800;color:#101828;}
.s-card .lbl{font-size:11px;color:#667085;text-transform:uppercase;letter-spacing:.4px;margin-top:2px;}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:22px;}
@media print{body{padding:10px;} .no-print{display:none;}}
</style></head><body>
<div class="no-print" style="margin-bottom:16px;">
  <button onclick="window.print()" style="background:#002F70;color:#fff;border:none;padding:10px 22px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;">Print / Save as PDF</button>
  <button onclick="window.close()" style="background:#6c757d;color:#fff;border:none;padding:10px 16px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;margin-left:8px;">Close</button>
</div>
<div class="print-header">
  <h1>DAILY SALES &amp; SERVICES REPORT</h1>
  <p>Station: '.htmlspecialchars($station_name).' &nbsp;|&nbsp; Period: '.htmlspecialchars($date_start).' to '.htmlspecialchars($date_end).' &nbsp;|&nbsp; Generated: '.date('Y-m-d H:i:s').'</p>
</div>

<div class="summary-grid">
  <div class="s-card" style="border-left-color:#002F70;"><div class="val">'.number_format($grand_liters,2).' L</div><div class="lbl">Total Liters Sold</div></div>
  <div class="s-card" style="border-left-color:#22c55e;"><div class="val">₱'.number_format($grand_amount,2).'</div><div class="lbl">Total Fuel Sales</div></div>
  <div class="s-card" style="border-left-color:#14b8a6;"><div class="val">₱'.number_format($service_income_total,2).'</div><div class="lbl">Service Income</div></div>
  <div class="s-card" style="border-left-color:#8b5cf6;"><div class="val">₱'.number_format($total_digital,2).'</div><div class="lbl">Digital / Card Sales</div></div>
  <div class="s-card" style="border-left-color:#f97316;"><div class="val">₱'.number_format($total_ar_outstanding,2).'</div><div class="lbl">Accounts Receivable (A/R)</div></div>
  <div class="s-card" style="border-left-color:#cc0000;"><div class="val">₱'.number_format($cash_in_bank_deposit,2).'</div><div class="lbl">Cash in Bank (Deposits)</div></div>
</div>

<div class="section">
  <div class="section-head"><span>Meter Reading Table (Liters = Ending - Beginning +/- Calibration)</span></div>
  <div style="overflow:hidden;"><table>
    <thead><tr><th>#</th><th>Tanker ID</th><th>Fuel Type</th><th>Beginning Reading</th><th>Ending Reading</th><th>Calibration</th><th>Liters Sold</th><th>Unit Price</th><th>Amount</th><th>Shift</th><th>Date</th></tr></thead>
    <tbody>'.($print_rows_meter ?: '<tr><td colspan="11" style="text-align:center;color:#9ca3af;">No meter readings</td></tr>').'</tbody>
  </table></div>
</div>

<div class="two-col">
  <div class="section">
    <div class="section-head"><span>Volume Sales Summary</span></div>
    <table><thead><tr><th>Fuel Type</th><th>Total Liters Sold</th><th>Avg Price/L</th><th>Total Amount</th></tr></thead>
    <tbody>'.($print_rows_vol ?: '<tr><td colspan="4" style="text-align:center;color:#9ca3af;">No data</td></tr>').'</tbody></table>
  </div>
  <div class="section">
    <div class="section-head"><span>Tank Sales Summary</span></div>
    <table><thead><tr><th>Tank</th><th>Fuel Type</th><th>Tank Capacity</th><th>Dispensed Liters</th><th>Utilization %</th></tr></thead>
    <tbody>'.$print_rows_tank.'</tbody></table>
  </div>
</div>

<div class="two-col">
'.($can_see_shift1 ? '
  <div class="section">
    <div class="section-head"><span>Shift 1 Sales &amp; Cash (6:00 AM - 2:00 PM)</span></div>
    <table><thead><tr><th>Fuel Type</th><th>Liters</th><th>Total Sales (&#8369;)</th><th>Cash Received (&#8369;)</th><th>Digital (&#8369;)</th><th>Credit (&#8369;)</th></tr></thead>
    <tbody>'.$print_rows_s1.'</tbody></table>
  </div>
' : '').'
'.($can_see_shift2 ? '
  <div class="section">
    <div class="section-head"><span>Shift 2 Sales &amp; Cash (2:00 PM - 12:00 MN)</span></div>
    <table><thead><tr><th>Fuel Type</th><th>Liters</th><th>Total Sales (&#8369;)</th><th>Cash Received (&#8369;)</th><th>Digital (&#8369;)</th><th>Credit (&#8369;)</th></tr></thead>
    <tbody>'.$print_rows_s2.'</tbody></table>
  </div>
' : '').'</div>

<div class="section">
  <div class="section-head"><span>Service Income (Job Orders)</span></div>
  <table><thead><tr><th>Job Ref</th><th>Customer Name</th><th>Vehicle Plate</th><th>Service Type</th><th>Payment Method</th><th>Total Cost</th></tr></thead>
  <tbody>'.$print_rows_service.'</tbody></table>
</div>

<div class="section">
  <div class="section-head"><span>A/R Summary - Suki / Credit Customers</span></div>
  <table><thead><tr><th>Customer Name</th><th>Outstanding Balance</th></tr></thead>
  <tbody>'.$print_rows_ar.'</tbody></table>
</div>

<div class="section">
  <div class="section-head"><span>Overall Daily Summary</span></div>
  <table><thead><tr><th>Metric</th><th>Value</th></tr></thead>
  <tbody>
    <tr><td>Total Liters Sold</td><td><strong>'.number_format($grand_liters,2).' L</strong></td></tr>
    <tr><td>Total Fuel Sales</td><td>₱'.number_format($grand_amount,2).'</td></tr>
    <tr><td>Total Service Income</td><td>₱'.number_format($service_income_total,2).'</td></tr>
    <tr style="background:#f8fafc;"><td><strong>Grand Total (Fuel + Services)</strong></td><td><strong>₱'.number_format($grand_total_all,2).'</strong></td></tr>
    <tr><td>Shift 1 &mdash; Cash Received</td><td>₱'.number_format($s1_cash,2).'</td></tr>
    <tr><td>Shift 2 &mdash; Cash Received</td><td>₱'.number_format($s2_cash,2).'</td></tr>
    <tr><td>Total Cash Sales (including Service Cash)</td><td>₱'.number_format($total_cash,2).'</td></tr>
    <tr><td>Total Digital/Card Sales (including Service Digital)</td><td>₱'.number_format($total_digital,2).'</td></tr>
    <tr><td>Total Accounts Receivable (A/R)</td><td>₱'.number_format($total_ar_outstanding,2).'</td></tr>
    <tr style="background:#f0fdf4;"><td><strong>Total Cash in Bank (Deposits)</strong></td><td><strong style="color:#15803d;">₱'.number_format($cash_in_bank_deposit,2).'</strong></td></tr>
    <tr style="background:#fef9c3;"><td><strong>Cash on Hand</strong></td><td><strong>₱'.number_format($cash_on_hand,2).'</strong></td></tr>
    <tr style="background:'.($variance==0?'#f0fdf4':'#fee2e2').';font-weight:700;"><td><strong>Variance</strong></td><td><strong style="color:'.($variance==0?'#15803d':'#cc0000').'">₱'.number_format($variance,2).'</strong></td></tr>
  </tbody></table>
</div>
<script>window.onload=function(){window.print();}</script>
</body></html>';
        exit;
    }
}

// ============================================================
// JOB ORDERS — DEDICATED EXPORT HANDLER
// ============================================================
if (isset($_GET['export']) && $section === 'job_orders' && $sub_tab === 'jo_list') {
    $format = trim($_GET['export']);

    if (in_array($format, ['excel','csv'])) {
        header('Content-Type: text/csv; charset=utf-8');
        $fn = 'daily_job_orders_' . $date_start . '_to_' . $date_end . '.csv';
        header("Content-Disposition: attachment; filename=\"{$fn}\"");
        $out = fopen('php://output','w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

        fputcsv($out, ['DAILY JOB ORDER REPORT']);
        fputcsv($out, ['Station:', $station_name]);
        fputcsv($out, ['Period:', $date_start . ' to ' . $date_end]);
        fputcsv($out, ['Generated:', date('Y-m-d H:i:s')]);
        fputcsv($out, []);

        // Job Order Table
        fputcsv($out, ['JOB ORDERS TABLE']);
        fputcsv($out, ['#','JO ID','Customer Name','Contact','Ref ID','Service Type','Parts/Materials','Qty','Unit Price (₱)','Labor Fee (₱)','Total Amount (₱)','Payment Mode','Shift','Status','Encoder','Remarks']);
        foreach ($report_data as $i => $job) {
            $hour  = (int)date('H', strtotime($job['created_at']));
            $shift = ($hour >= 6 && $hour < 14) ? 'Shift 1' : 'Shift 2';
            $parts = !empty($job['parts_used'])
                ? implode(' | ', array_map(fn($p)=>$p['product_name'].'x'.$p['quantity_used'].' @₱'.number_format($p['unit_cost'],2), $job['parts_used']))
                : '—';
            fputcsv($out, [
                $i + 1,
                $job['job_order_id'],
                $job['customer_name'],
                $job['contact_number'] ?: '—',
                $job['customer_ref_id'] ?: '—',
                $job['service_type'],
                $parts,
                count($job['parts_used']),
                '—',
                number_format((float)$job['labor_fee'], 2),
                number_format((float)$job['total_cost'], 2),
                $job['payment_mode'],
                $shift,
                $job['status'],
                $job['encoder_name'],
                $job['remarks'] ?: '—',
            ]);
        }
        if (empty($report_data)) fputcsv($out, ['(No job orders for this period)']);

        // Shift 1 Summary
        fputcsv($out, []);
        fputcsv($out, ['SHIFT 1 SUMMARY (6:00 AM – 2:00 PM)']);
        fputcsv($out, ['Total Services','Total Amount (₱)','Cash (₱)','Digital (₱)','Credit/AR (₱)']);
        fputcsv($out, [$jo_s1['services'], number_format($jo_s1['amount'],2), number_format($jo_s1['cash'],2), number_format($jo_s1['digital'],2), number_format($jo_s1['credit'],2)]);

        // Shift 2 Summary
        fputcsv($out, []);
        fputcsv($out, ['SHIFT 2 SUMMARY (2:00 PM – 12:00 MN)']);
        fputcsv($out, ['Total Services','Total Amount (₱)','Cash (₱)','Digital (₱)','Credit/AR (₱)']);
        fputcsv($out, [$jo_s2['services'], number_format($jo_s2['amount'],2), number_format($jo_s2['cash'],2), number_format($jo_s2['digital'],2), number_format($jo_s2['credit'],2)]);

        // Overall Daily Summary
        fputcsv($out, []);
        fputcsv($out, ['OVERALL DAILY SUMMARY']);
        fputcsv($out, ['METRIC','VALUE']);
        fputcsv($out, ['Total Job Orders', count($report_data)]);
        fputcsv($out, ['Grand Total Amount', '₱'.number_format($jo_s1['amount']+$jo_s2['amount'],2)]);
        fputcsv($out, ['Total Cash Received', '₱'.number_format($jo_s1['cash']+$jo_s2['cash'],2)]);
        fputcsv($out, ['Total Digital/Card', '₱'.number_format($jo_s1['digital']+$jo_s2['digital'],2)]);
        fputcsv($out, ['Total Credit/AR', '₱'.number_format($jo_s1['credit']+$jo_s2['credit'],2)]);

        fclose($out);
        exit;
    }

    if ($format === 'pdf') {
        $th_s  = 'background:#002F70;color:#fff;padding:7px 9px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;white-space:nowrap;';
        $td_s  = 'border-bottom:1px solid #e9ecef;padding:6px 9px;font-size:10px;';
        function _jo_badge($status) {
            $s = strtolower($status);
            $c = '#667085';
            if (in_array($s,['completed','approved','validated','paid'])) $c='#15803d';
            elseif (in_array($s,['pending','in progress','pending validation'])) $c='#1d4ed8';
            elseif (in_array($s,['cancelled','rejected'])) $c='#cc0000';
            return '<span style="color:'.$c.';font-weight:700;">'.htmlspecialchars(ucfirst($status)).'</span>';
        }
        $grand_amount = 0; $grand_cash = 0; $grand_digital = 0; $grand_credit = 0;
        $rows_html = '';
        foreach ($report_data as $i => $job) {
            $hour  = (int)date('H', strtotime($job['created_at']));
            $shift = ($hour >= 6 && $hour < 14) ? 'Shift 1' : 'Shift 2';
            $amt   = (float)$job['total_cost'];
            $grand_amount += $amt;
            $parts_html = '';
            foreach ($job['parts_used'] as $p) {
                $parts_html .= '<div>'.htmlspecialchars($p['product_name']).' &times;'.htmlspecialchars($p['quantity_used']).' @&#8369;'.number_format((float)$p['unit_cost'],2).'</div>';
            }
            if (empty($parts_html)) $parts_html = '<span style="color:#9ca3af;">—</span>';
            $cust_info = htmlspecialchars($job['customer_name']);
            if (!empty($job['contact_number'])) $cust_info .= '<br><span style="font-size:9px;color:#64748b;">'.htmlspecialchars($job['contact_number']).'</span>';
            if (!empty($job['customer_ref_id']) && $job['customer_ref_id'] != '0') $cust_info .= '<br><span style="font-size:9px;color:#9ca3af;">Ref #'.htmlspecialchars($job['customer_ref_id']).'</span>';
            $rows_html .= '<tr>
              <td style="'.$td_s.'">'.($i+1).'</td>
              <td style="'.$td_s.'"><strong>'.htmlspecialchars($job['job_order_id']).'</strong></td>
              <td style="'.$td_s.'">'.$cust_info.'</td>
              <td style="'.$td_s.'">'.htmlspecialchars($job['service_type']).'</td>
              <td style="'.$td_s.'">'.$parts_html.'</td>
              <td style="'.$td_s.'">&#8369;'.number_format((float)$job['labor_fee'],2).'</td>
              <td style="'.$td_s.'"><strong>&#8369;'.number_format($amt,2).'</strong></td>
              <td style="'.$td_s.'">'.htmlspecialchars($job['payment_mode']).'</td>
              <td style="'.$td_s.'">'.$shift.'</td>
              <td style="'.$td_s.'">'._jo_badge($job['status']).'</td>
              <td style="'.$td_s.'">'.htmlspecialchars($job['encoder_name']).'</td>
              <td style="'.$td_s.';color:#64748b;">'.htmlspecialchars($job['remarks']).'</td>
            </tr>';
        }
        if (empty($report_data)) $rows_html = '<tr><td colspan="12" style="text-align:center;color:#9ca3af;padding:16px;">No job orders for this period.</td></tr>';

        echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
<title>Daily Job Order Report — '.$date_start.' to '.$date_end.'</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:Arial,sans-serif;color:#111;background:#fff;padding:18px;}
.ph{text-align:center;margin-bottom:18px;border-bottom:3px solid #002F70;padding-bottom:10px;}
.ph h1{font-size:18px;font-weight:800;color:#002F70;}
.ph p{font-size:11px;color:#667085;margin-top:3px;}
.sec{margin-bottom:16px;border:1px solid #e2e8f0;border-radius:6px;overflow:hidden;}
.sh{background:#002F70;color:#fff;padding:8px 12px;font-size:11px;font-weight:700;text-transform:uppercase;}
table{width:100%;border-collapse:collapse;}
.sg{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px;}
.sc{border:1px solid #e2e8f0;border-radius:6px;padding:10px 12px;border-left:4px solid #002F70;}
.sc .v{font-size:15px;font-weight:800;color:#101828;}
.sc .l{font-size:9px;color:#667085;text-transform:uppercase;letter-spacing:.4px;margin-top:2px;}
.two{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;}
@media print{body{padding:8px;}.no-print{display:none;}}
</style></head><body>
<div class="no-print" style="margin-bottom:14px;">
  <button onclick="window.print()" style="background:#002F70;color:#fff;border:none;padding:9px 20px;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;">Print / Save as PDF</button>
  <button onclick="window.close()" style="background:#6c757d;color:#fff;border:none;padding:9px 14px;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;margin-left:8px;">Close</button>
</div>
<div class="ph">
  <h1>DAILY JOB ORDER REPORT</h1>
  <p>Station: '.htmlspecialchars($station_name).' &nbsp;|&nbsp; Period: '.htmlspecialchars($date_start).' to '.htmlspecialchars($date_end).' &nbsp;|&nbsp; Generated: '.date('Y-m-d H:i:s').'</p>
</div>
<div class="sg">
  <div class="sc"><div class="v">'.count($report_data).'</div><div class="l">Total Job Orders</div></div>
  <div class="sc" style="border-left-color:#22c55e;"><div class="v">&#8369;'.number_format($jo_s1['amount']+$jo_s2['amount'],2).'</div><div class="l">Grand Total Amount</div></div>
  <div class="sc" style="border-left-color:#3b82f6;"><div class="v">&#8369;'.number_format($jo_s1['cash']+$jo_s2['cash'],2).'</div><div class="l">Cash Received</div></div>
  <div class="sc" style="border-left-color:#8b5cf6;"><div class="v">&#8369;'.number_format($jo_s1['credit']+$jo_s2['credit'],2).'</div><div class="l">Credit / A/R</div></div>
</div>
<div class="sec">
  <div class="sh">Job Order Table</div>
  <div style="overflow:hidden;">
  <table>
    <thead><tr>
      <th style="'.$th_s.'">#</th>
      <th style="'.$th_s.'">JO ID</th>
      <th style="'.$th_s.'">Customer Info</th>
      <th style="'.$th_s.'">Service Type</th>
      <th style="'.$th_s.'">Parts / Materials</th>
      <th style="'.$th_s.'">Labor Fee</th>
      <th style="'.$th_s.'">Total Amount</th>
      <th style="'.$th_s.'">Payment Mode</th>
      <th style="'.$th_s.'">Shift</th>
      <th style="'.$th_s.'">Status</th>
      <th style="'.$th_s.'">Encoder</th>
      <th style="'.$th_s.'">Remarks</th>
    </tr></thead>
    <tbody>'.$rows_html.'</tbody>
  </table></div>
</div>
<div class="two">
  <div class="sec">
    <div class="sh">Shift 1 Summary (6 AM - 2 PM)</div>
    <table><thead><tr>
      <th style="'.$th_s.'">Metric</th><th style="'.$th_s.'">Value</th>
    </tr></thead><tbody>
      <tr><td style="'.$td_s.'">Total Services</td><td style="'.$td_s.'"><strong>'.$jo_s1['services'].'</strong></td></tr>
      <tr><td style="'.$td_s.'">Total Amount</td><td style="'.$td_s.'"><strong>&#8369;'.number_format($jo_s1['amount'],2).'</strong></td></tr>
      <tr><td style="'.$td_s.'">Cash Received</td><td style="'.$td_s.'">&#8369;'.number_format($jo_s1['cash'],2).'</td></tr>
      <tr><td style="'.$td_s.'">Digital / Card</td><td style="'.$td_s.'">&#8369;'.number_format($jo_s1['digital'],2).'</td></tr>
      <tr><td style="'.$td_s.'">Credit / A/R</td><td style="'.$td_s.'">&#8369;'.number_format($jo_s1['credit'],2).'</td></tr>
    </tbody></table>
  </div>
  <div class="sec">
    <div class="sh">Shift 2 Summary (2 PM - 12 MN)</div>
    <table><thead><tr>
      <th style="'.$th_s.'">Metric</th><th style="'.$th_s.'">Value</th>
    </tr></thead><tbody>
      <tr><td style="'.$td_s.'">Total Services</td><td style="'.$td_s.'"><strong>'.$jo_s2['services'].'</strong></td></tr>
      <tr><td style="'.$td_s.'">Total Amount</td><td style="'.$td_s.'"><strong>&#8369;'.number_format($jo_s2['amount'],2).'</strong></td></tr>
      <tr><td style="'.$td_s.'">Cash Received</td><td style="'.$td_s.'">&#8369;'.number_format($jo_s2['cash'],2).'</td></tr>
      <tr><td style="'.$td_s.'">Digital / Card</td><td style="'.$td_s.'">&#8369;'.number_format($jo_s2['digital'],2).'</td></tr>
      <tr><td style="'.$td_s.'">Credit / A/R</td><td style="'.$td_s.'">&#8369;'.number_format($jo_s2['credit'],2).'</td></tr>
    </tbody></table>
  </div>
</div>
<div class="sec">
  <div class="sh">Overall Daily Summary</div>
  <table><thead><tr>
    <th style="'.$th_s.'">Metric</th><th style="'.$th_s.'">Value</th>
  </tr></thead><tbody>
    <tr><td style="'.$td_s.'">Total Job Orders</td><td style="'.$td_s.'"><strong>'.count($report_data).'</strong></td></tr>
    <tr><td style="'.$td_s.'">Grand Total Amount</td><td style="'.$td_s.'"><strong>&#8369;'.number_format($jo_s1['amount']+$jo_s2['amount'],2).'</strong></td></tr>
    <tr><td style="'.$td_s.'">Shift 1 — Cash</td><td style="'.$td_s.'">&#8369;'.number_format($jo_s1['cash'],2).'</td></tr>
    <tr><td style="'.$td_s.'">Shift 1 — Digital</td><td style="'.$td_s.'">&#8369;'.number_format($jo_s1['digital'],2).'</td></tr>
    <tr><td style="'.$td_s.'">Shift 1 — Credit/AR</td><td style="'.$td_s.'">&#8369;'.number_format($jo_s1['credit'],2).'</td></tr>
    <tr><td style="'.$td_s.'">Shift 2 — Cash</td><td style="'.$td_s.'">&#8369;'.number_format($jo_s2['cash'],2).'</td></tr>
    <tr><td style="'.$td_s.'">Shift 2 — Digital</td><td style="'.$td_s.'">&#8369;'.number_format($jo_s2['digital'],2).'</td></tr>
    <tr><td style="'.$td_s.'">Shift 2 — Credit/AR</td><td style="'.$td_s.'">&#8369;'.number_format($jo_s2['credit'],2).'</td></tr>
    <tr style="background:#f0fdf4;"><td style="'.$td_s.'"><strong>Total Cash Received</strong></td><td style="'.$td_s.'"><strong style="color:#15803d;">&#8369;'.number_format($jo_s1['cash']+$jo_s2['cash'],2).'</strong></td></tr>
    <tr style="background:#fefce8;"><td style="'.$td_s.'"><strong>Total Credit / A/R</strong></td><td style="'.$td_s.'"><strong style="color:#d97706;">&#8369;'.number_format($jo_s1['credit']+$jo_s2['credit'],2).'</strong></td></tr>
  </tbody></table>
</div>
<script>window.onload=function(){window.print();}</script>
</body></html>';
        exit;
    }
}

// ============================================================
// MERCHANDISE SALES — DEDICATED EXPORT HANDLER
// ============================================================
if (isset($_GET['export']) && $sub_tab === 'merch_sales') {
    $format = trim($_GET['export']);

    if (in_array($format, ['excel','csv'])) {
        header('Content-Type: text/csv; charset=utf-8');
        $fn = 'daily_merch_sales_' . $date_start . '_to_' . $date_end . '.csv';
        header("Content-Disposition: attachment; filename=\"{$fn}\"");
        $out = fopen('php://output','w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

        fputcsv($out, ['DAILY MERCHANDISE SALES REPORT']);
        fputcsv($out, ['Station:', $station_name]);
        fputcsv($out, ['Period:', $date_start . ' to ' . $date_end]);
        fputcsv($out, ['Generated:', date('Y-m-d H:i:s')]);

        // Merchandise Sales Table
        fputcsv($out, []);
        fputcsv($out, ['MERCHANDISE SALES TABLE']);
        fputcsv($out, ['CATEGORY','PRODUCT NAME','SIZE','BEGINNING STOCK','STOCK IN (DELIVERIES)','STOCK OUT (SALES)','ENDING STOCK','UNIT PRICE (₱)','AMOUNT (₱)','ENCODER','REMARKS']);
        foreach ($merch_items as $m) {
            fputcsv($out, [
                $m['category'],
                $m['product_name'],
                $m['size_variant'],
                number_format($m['beginning_stock'],2),
                number_format($m['stock_in'],2),
                number_format($m['stock_out'],2),
                number_format($m['ending_stock'],2),
                number_format($m['unit_price'],2),
                number_format($m['amount'],2),
                $m['encoders'],
                $m['remarks'],
            ]);
        }
        if (empty($merch_items)) fputcsv($out, ['(No merchandise sales)']);

        // Shift 1 (gated)
        if ($can_see_shift1) {
            fputcsv($out, []);
            fputcsv($out, ['SHIFT 1 SALES SUMMARY (6:00 AM - 2:00 PM)']);
            fputcsv($out, ['CATEGORY','ITEMS SOLD','TOTAL AMOUNT (PHP)','CASH (PHP)','DIGITAL (PHP)','CREDIT/AR (PHP)']);
            foreach ($merch_shift1 as $r) {
                fputcsv($out, [$r['category'],number_format($r['total_qty'],2),number_format($r['total_amount'],2),number_format($r['cash_amount'],2),number_format($r['digital_amount'],2),number_format($r['credit_amount'],2)]);
            }
            if (empty($merch_shift1)) fputcsv($out, ['(No Shift 1 data)']);
        }

        // Shift 2 (gated)
        if ($can_see_shift2) {
            fputcsv($out, []);
            fputcsv($out, ['SHIFT 2 SALES SUMMARY (2:00 PM - 12:00 MN)']);
            fputcsv($out, ['CATEGORY','ITEMS SOLD','TOTAL AMOUNT (PHP)','CASH (PHP)','DIGITAL (PHP)','CREDIT/AR (PHP)']);
            foreach ($merch_shift2 as $r) {
                fputcsv($out, [$r['category'],number_format($r['total_qty'],2),number_format($r['total_amount'],2),number_format($r['cash_amount'],2),number_format($r['digital_amount'],2),number_format($r['credit_amount'],2)]);
            }
            if (empty($merch_shift2)) fputcsv($out, ['(No Shift 2 data)']);
        }

        // Category Totals
        fputcsv($out, []);
        fputcsv($out, ['CATEGORY TOTALS']);
        fputcsv($out, ['CATEGORY','ITEMS SOLD','TOTAL AMOUNT (₱)']);
        foreach ($merch_cat_totals as $r) {
            fputcsv($out, [$r['category'],number_format($r['total_qty'],2),number_format($r['total_amount'],2)]);
        }
        if (empty($merch_cat_totals)) fputcsv($out, ['(No data)']);

        // A/R Summary
        fputcsv($out, []);
        fputcsv($out, ['ACCOUNTS RECEIVABLE SUMMARY']);
        fputcsv($out, ['TRANSACTION ID','CUSTOMER','AMOUNT (₱)','STATUS','DUE DATE','CREATED AT']);
        foreach ($merch_ar as $r) {
            fputcsv($out, [$r['transaction_id'],$r['customer_name'],number_format($r['amount'],2),$r['status'],$r['due_date'],$r['created_at']]);
        }
        if (empty($merch_ar)) fputcsv($out, ['(No AR records)']);

        // Overall Summary
        fputcsv($out, []);
        fputcsv($out, ['OVERALL DAILY SUMMARY']);
        fputcsv($out, ['METRIC','VALUE']);
        fputcsv($out, ['Total Merchandise Sales', '₱'.number_format($merch_grand_sales,2)]);
        fputcsv($out, ['Total Items Sold', number_format($merch_grand_qty)]);
        fputcsv($out, ['Total Cash Sales', '₱'.number_format($merch_cash_total,2)]);
        fputcsv($out, ['Total Digital/Card Sales', '₱'.number_format($merch_digital_total,2)]);
        fputcsv($out, ['Total Accounts Receivable', '₱'.number_format($merch_credit_total,2)]);
        fputcsv($out, ['Total Cash in Bank (for Deposit)', '₱'.number_format($merch_cash_in_bank,2)]);

        fclose($out);
        exit;

    } elseif ($format === 'pdf') {
        $th  = 'background:#002F70;color:#fff;padding:8px 10px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;white-space:nowrap;';
        $td2 = 'border-bottom:1px solid #e9ecef;padding:7px 10px;font-size:11px;';

        function _ms_sec($title, $icon) {
            return '<div class="section"><div class="section-head"><span>'.$icon.' '.$title.'</span></div>';
        }
        function _ms_shift_rows($rows) {
            $out='';
            foreach($rows as $r){
                $out.='<tr><td>'.htmlspecialchars($r['category']).'</td><td>'.number_format($r['total_qty'],2).'</td><td>₱'.number_format($r['total_amount'],2).'</td><td>₱'.number_format($r['cash_amount'],2).'</td><td>₱'.number_format($r['digital_amount'],2).'</td><td>₱'.number_format($r['credit_amount'],2).'</td></tr>';
            }
            if(empty($rows)) $out='<tr><td colspan="6" style="text-align:center;color:#9ca3af;">No data</td></tr>';
            return $out;
        }

        echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
<title>Daily Merchandise Sales Report — '.$date_start.' to '.$date_end.'</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:Arial,sans-serif;color:#111;background:#fff;padding:20px;}
.print-header{text-align:center;margin-bottom:20px;border-bottom:3px solid #002F70;padding-bottom:12px;}
.print-header h1{font-size:20px;font-weight:800;color:#002F70;letter-spacing:-.5px;}
.print-header p{font-size:12px;color:#667085;margin-top:4px;}
.section{margin-bottom:18px;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;}
.section-head{background:#002F70;color:#fff;padding:9px 14px;font-size:12px;font-weight:700;display:flex;align-items:center;gap:8px;}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px;}
table{width:100%;border-collapse:collapse;}
th{'.$th.'}
td{'.$td2.'}
tr:last-child td{border-bottom:none;}
.summary-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:18px;}
.s-card{border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;border-left:4px solid #002F70;}
.s-card .val{font-size:16px;font-weight:800;color:#101828;}
.s-card .lbl{font-size:10px;color:#667085;text-transform:uppercase;letter-spacing:.4px;margin-top:2px;}
.no-print{display:none;}
@media print{.no-print{display:none!important;}}
</style></head><body>
<div class="no-print" style="padding:10px 0 14px;display:flex;gap:8px;">
  <button onclick="window.print()" style="background:#002F70;color:#fff;border:none;padding:10px 22px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;">Print / Save as PDF</button>
  <button onclick="window.close()" style="background:#6c757d;color:#fff;border:none;padding:10px 16px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;margin-left:8px;">Close</button>
</div>
<div class="print-header">
  <h1>DAILY MERCHANDISE SALES REPORT</h1>
  <p>Station: '.htmlspecialchars($station_name).' &nbsp;|&nbsp; Period: '.htmlspecialchars($date_start).' to '.htmlspecialchars($date_end).' &nbsp;|&nbsp; Generated: '.date('Y-m-d H:i:s').'</p>
</div>

<div class="summary-grid">
  <div class="s-card" style="border-left-color:#002F70;"><div class="val">₱'.number_format($merch_grand_sales,2).'</div><div class="lbl">Total Merch Sales</div></div>
  <div class="s-card" style="border-left-color:#cc0000;"><div class="val">'.number_format($merch_grand_qty).'</div><div class="lbl">Items Sold</div></div>
  <div class="s-card" style="border-left-color:#22c55e;"><div class="val">₱'.number_format($merch_cash_total,2).'</div><div class="lbl">Cash Sales</div></div>
  <div class="s-card" style="border-left-color:#8b5cf6;"><div class="val">₱'.number_format($merch_digital_total,2).'</div><div class="lbl">Digital / Card</div></div>
  <div class="s-card" style="border-left-color:#f97316;"><div class="val">₱'.number_format($merch_credit_total,2).'</div><div class="lbl">Accounts Receivable</div></div>
  <div class="s-card" style="border-left-color:#14b8a6;"><div class="val">₱'.number_format($merch_cash_in_bank,2).'</div><div class="lbl">Cash in Bank</div></div>
</div>

<div class="section">
  <div class="section-head"><span>Merchandise Sales Table</span></div>
  <div style="overflow:hidden;"><table>
    <thead><tr><th>Category</th><th>Product Name</th><th>Size</th><th>Beg. Stock</th><th>Stock In</th><th>Stock Out</th><th>End Stock</th><th>Unit Price</th><th>Amount</th><th>Encoder</th><th>Remarks</th></tr></thead>
    <tbody>';
        if (empty($merch_items)) {
            echo '<tr><td colspan="11" style="text-align:center;color:#9ca3af;">No merchandise sales</td></tr>';
        } else {
            foreach ($merch_items as $m) {
                echo '<tr>
                  <td style="font-size:10px;color:#64748b;">'.htmlspecialchars($m['category']).'</td>
                  <td><strong>'.htmlspecialchars($m['product_name']).'</strong></td>
                  <td>'.htmlspecialchars($m['size_variant']).'</td>
                  <td>'.number_format($m['beginning_stock'],2).'</td>
                  <td style="color:#16a34a;font-weight:600;">'.number_format($m['stock_in'],2).'</td>
                  <td style="color:#cc0000;font-weight:600;">'.number_format($m['stock_out'],2).'</td>
                  <td><strong>'.number_format($m['ending_stock'],2).'</strong></td>
                  <td>₱'.number_format($m['unit_price'],2).'</td>
                  <td><strong>₱'.number_format($m['amount'],2).'</strong></td>
                  <td style="font-size:10px;">'.htmlspecialchars($m['encoders']).'</td>
                  <td style="font-size:10px;color:#64748b;">'.htmlspecialchars($m['remarks']).'</td>
                </tr>';
            }
        }
        echo '</tbody></table></div></div>

<div class="two-col">
'.($can_see_shift1 ? '
  <div class="section">
    <div class="section-head"><span>Shift 1 Sales (6:00 AM - 2:00 PM)</span></div>
    <table><thead><tr><th>Category</th><th>Items</th><th>Total</th><th>Cash</th><th>Digital</th><th>Credit</th></tr></thead>
    <tbody>'._ms_shift_rows($merch_shift1).'</tbody></table>
  </div>
' : '').'
'.($can_see_shift2 ? '
  <div class="section">
    <div class="section-head"><span>Shift 2 Sales (2:00 PM - 12:00 MN)</span></div>
    <table><thead><tr><th>Category</th><th>Items</th><th>Total</th><th>Cash</th><th>Digital</th><th>Credit</th></tr></thead>
    <tbody>'._ms_shift_rows($merch_shift2).'</tbody></table>
  </div>
' : '').'</div>

<div class="two-col">
  <div class="section">
    <div class="section-head"><span>Category Totals</span></div>
    <table><thead><tr><th>Category</th><th>Items Sold</th><th>Total Amount</th></tr></thead>
    <tbody>';
        foreach ($merch_cat_totals as $r) {
            echo '<tr><td>'.htmlspecialchars($r['category']).'</td><td>'.number_format($r['total_qty'],2).'</td><td>₱'.number_format($r['total_amount'],2).'</td></tr>';
        }
        if (empty($merch_cat_totals)) echo '<tr><td colspan="3" style="text-align:center;color:#9ca3af;">No data</td></tr>';
        echo '</tbody></table></div>
  <div class="section">
    <div class="section-head"><span>Accounts Receivable Summary</span></div>
    <table><thead><tr><th>Ref</th><th>Customer</th><th>Amount</th><th>Status</th><th>Due Date</th></tr></thead>
    <tbody>';
        foreach ($merch_ar as $r) {
            $sc = strtolower($r['status'])==='paid'?'#16a34a':(strtolower($r['status'])==='overdue'?'#cc0000':'#d97706');
            echo '<tr>
              <td style="font-size:10px;">'.htmlspecialchars($r['transaction_id']).'</td>
              <td>'.htmlspecialchars($r['customer_name']).'</td>
              <td>₱'.number_format($r['amount'],2).'</td>
              <td style="color:'.$sc.';font-weight:700;">'.ucfirst($r['status']).'</td>
              <td style="font-size:10px;">'.htmlspecialchars($r['due_date']).'</td>
            </tr>';
        }
        if (empty($merch_ar)) echo '<tr><td colspan="5" style="text-align:center;color:#9ca3af;">No accounts receivable</td></tr>';
        echo '</tbody></table></div>
</div>

<div class="section">
  <div class="section-head"><span>Overall Daily Summary</span></div>
  <table><thead><tr><th>Metric</th><th>Value</th></tr></thead>
  <tbody>
    <tr><td>Total Merchandise Sales</td><td><strong>₱'.number_format($merch_grand_sales,2).'</strong></td></tr>
    <tr><td>Total Items Sold</td><td><strong>'.number_format($merch_grand_qty).'</strong></td></tr>
    <tr><td>Shift 1 — Cash</td><td>₱'.number_format($merch_s1_cash,2).'</td></tr>
    <tr><td>Shift 1 — Digital</td><td>₱'.number_format($merch_s1_digital,2).'</td></tr>
    <tr><td>Shift 1 — Credit/AR</td><td>₱'.number_format($merch_s1_credit,2).'</td></tr>
    <tr><td>Shift 2 — Cash</td><td>₱'.number_format($merch_s2_cash,2).'</td></tr>
    <tr><td>Shift 2 — Digital</td><td>₱'.number_format($merch_s2_digital,2).'</td></tr>
    <tr><td>Shift 2 — Credit/AR</td><td>₱'.number_format($merch_s2_credit,2).'</td></tr>
    <tr><td>Total Cash Sales</td><td>₱'.number_format($merch_cash_total,2).'</td></tr>
    <tr><td>Total Digital/Card Sales</td><td>₱'.number_format($merch_digital_total,2).'</td></tr>
    <tr style="background:#fefce8;"><td><strong>Total Accounts Receivable (A/R)</strong></td><td>₱'.number_format($merch_credit_total,2).'</td></tr>
    <tr style="background:#f0fdf4;"><td><strong>Total Cash in Bank (Ready for Deposit)</strong></td><td><strong style="color:#15803d;font-size:15px;">₱'.number_format($merch_cash_in_bank,2).'</strong></td></tr>
  </tbody></table>
</div>
<script>window.onload=function(){window.print();}</script>
</body></html>';
        exit;
    }
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
    gap: 10px;
    margin-bottom: 20px;
    padding: 0 4px;
    flex-wrap: wrap;
    border-bottom: none;
}
.sub-tab-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s ease-in-out;
    text-decoration: none;
    background: #ffffff !important;
    border: 1px solid #002F6C !important;
    color: #002F6C !important;
}
.sub-tab-btn:hover {
    background: #002F6C !important;
    color: #ffffff !important;
}
.sub-tab-btn.active {
    background: #002F6C !important;
    border: 1px solid #002F6C !important;
    color: #ffffff !important;
}

/* Date range filter bar */
.rpt-filter-bar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; background: #fff; border: 1px solid #EAEAEA; border-radius: 10px; padding: 12px 16px; margin-bottom: 20px; box-shadow: 0 1px 4px rgba(0,0,0,.04); }
.rpt-filter-bar label { font-size: 12px; font-weight: 600; color: #667085; text-transform: uppercase; letter-spacing: .4px; }
.range-btn { padding: 7px 14px; border-radius: 4px; border: 1px solid #EAEAEA; background: #ffffff; font-size: 11px; font-weight: 600; color: #475569; cursor: pointer; text-decoration: none; transition: all .2s; }
.range-btn:hover { background: #e8f0f8; border-color: #00264D; color: #00264D; }
.range-btn.active { background: #00264D; color: #fff; border-color: #00264D; }
.rpt-filter-bar input[type="date"] { padding: 6px 10px; border: 1px solid #EAEAEA; border-radius: 6px; font-size: 12px; color: #374151; background: #f8fafc; }
.rpt-filter-bar .btn-apply {
    padding: 7px 14px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    transition: all .2s;
    background: #ffffff !important;
    border: 1px solid #00264D !important;
    color: #00264D !important;
}
.rpt-filter-bar .btn-apply:hover {
    background: #00264D !important;
    color: #ffffff !important;
}
.rpt-filter-bar .btn-export {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    transition: all .2s;
    background: #ffffff !important;
    border: 1px solid #00264D !important;
    color: #00264D !important;
}
.rpt-filter-bar .btn-export:hover {
    background: #00264D !important;
    color: #ffffff !important;
}
.rpt-filter-bar .export-buttons { display: flex; gap: 8px; margin-left: auto; }
#custom-range-inputs { display: flex; align-items: center; gap: 8px; }

/* Card-level export action bar — matches txn-btn outline style */
.card-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.btn-act {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 7px 14px;
    border-radius: 4px !important;
    font-size: 11px !important;
    font-weight: 600 !important;
    cursor: pointer;
    border: 1px solid transparent;
    transition: all .2s ease-in-out;
    text-decoration: none;
    white-space: nowrap;
    box-sizing: border-box;
    background: #ffffff !important;
}
.btn-act:hover { transform:none; opacity:1; }
.btn-act-excel  { color: #16a34a !important; border: 1px solid #16a34a !important; }
.btn-act-excel:hover  { background: #f0fdf4 !important; border-color: #15803d !important; }
.btn-act-csv    { color: #2563eb !important; border: 1px solid #2563eb !important; }
.btn-act-csv:hover    { background: #eff6ff !important; border-color: #1d4ed8 !important; }
.btn-act-pdf    { color: #dc2626 !important; border: 1px solid #dc2626 !important; }
.btn-act-pdf:hover    { background: #fef2f2 !important; border-color: #b91c1c !important; }
.btn-act-back   { color: #475569 !important; border: 1px solid #475569 !important; }
.btn-act-back:hover   { background: #475569 !important; color: #ffffff !important; }

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
.table-scroll { overflow:hidden; width: 100%; -webkit-overflow-scrolling: touch; }

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
            'fuel_sales'  => ['label' => 'Daily Fuel Sales Report',        'icon' => 'fa-gas-pump'],
            'merch_sales' => ['label' => 'Daily Merchandise & Service Sales Report',  'icon' => 'fa-boxes-stacked'],
        ],
        'deliveries' => [
            'fuel_deliveries' => ['label' => 'Fuel Deliveries', 'icon' => 'fa-truck-field'],
            'merch_deliveries' => ['label' => 'Merchandise Deliveries', 'icon' => 'fa-boxes-stacked']
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
    $_pdf_url = 'staff_reports.php?' . http_build_query([
        'section' => $section, 'range' => $range,
        'start'   => $date_start, 'end'  => $date_end, 'sub_tab' => $sub_tab, 'export' => 'pdf'
    ]);
    $card_btns = '<div class="card-actions">
        <a href="'.$_exp_url.'" class="btn-act btn-act-excel" title="Export Excel"><i class="fa-solid fa-file-excel"></i> Excel</a>
        <a href="'.$_csv_url.'" class="btn-act btn-act-csv"   title="Export CSV"><i class="fa-solid fa-file-csv"></i> CSV</a>
        <a href="'.$_pdf_url.'" target="_blank" class="btn-act btn-act-pdf" title="Export PDF"><i class="fa-solid fa-file-pdf"></i> PDF</a>
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

    <!-- DAILY FUEL SALES REPORT — Compact Single-Screen Layout -->
    <?php if ($sub_tab === 'fuel_sales'): ?>
    <!-- Force browser to reload CSS -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <style>
    .fs-wrap{font-size:12px;}
    .fs-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:12px;background:#002F70;color:#fff;border-radius:8px;padding:10px 16px;}
    .fs-header h2{font-size:14px;font-weight:800;margin:0;letter-spacing:-.2px;}
    .fs-header .meta{font-size:11px;opacity:.75;margin-top:1px;}
    .fs-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:10px;}
    .fs-col{display:flex;flex-direction:column;gap:10px;}
    .fs-panel{background:#fff;border-radius:8px;border:1px solid #e2e8f0;overflow:hidden;}
    .fs-panel-head{background:#002F70;color:#fff;padding:6px 10px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;}
    .fs-panel-head.red{background:#cc0000;}
    .fs-panel-head.navy{background:#1a3a6b;}
    .fs-tbl{width:100%;border-collapse:collapse;font-size:11px;}
    .fs-tbl th{background:#f0f4ff;color:#002F70;padding:5px 7px;text-align:left;font-weight:700;font-size:10px;text-transform:uppercase;border-bottom:1px solid #dde3f0;white-space:nowrap;}
    .fs-tbl td{padding:4px 7px;border-bottom:1px solid #f1f5f9;color:#1e293b;white-space:nowrap;}
    .fs-tbl tr:last-child td{border-bottom:none;}
    .fs-tbl .fs-tot td{background:#f0f4ff;font-weight:700;color:#002F70;border-top:2px solid #c7d2fe;}
    .fs-tbl .fs-tot2 td{background:#fefce8;font-weight:700;color:#92400e;border-top:2px solid #fde68a;}
    .fs-summary-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;}
    .fs-overall{background:#fff;border-radius:8px;border:1px solid #e2e8f0;overflow:hidden;}
    .fs-bank{background:linear-gradient(135deg,#002F70,#1a3a8f);color:#fff;border-radius:8px;padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;}
    .fs-bank .big{font-size:22px;font-weight:900;letter-spacing:-1px;}
    .fs-bank .sm{font-size:10px;opacity:.7;text-transform:uppercase;letter-spacing:.3px;}
    .fs-bank .items{display:flex;gap:14px;}
    .fs-bank .item{text-align:center;}
    .fs-bank .item .val{font-size:14px;font-weight:800;}
    .fs-empty{padding:14px;text-align:center;color:#9ca3af;font-size:11px;}
    @media print{
        /* Set page size and hide system elements */
        @page {
            size: legal portrait;
            margin: 0.3in 0.4in;
        }
        
        * {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        
        html, body { 
            background: white !important; 
            padding: 0 !important; 
            margin: 0 auto !important;
            width: 100% !important;
        }
        
        /* Hide controls, sidebar, navigation, logo */
        .no-print,
        .sidebar,
        .main-sidebar,
        aside,
        nav,
        .navbar,
        .main-header,
        img,
        .logo,
        .brand-image,
        body > header,
        body > footer,
        .main-footer { 
            display: none !important;
            visibility: hidden !important;
        }
        
        /* Center content */
        .wrapper,
        .content-wrapper { 
            margin: 0 auto !important;
            padding: 0 !important;
            width: 100% !important;
        }
        
        .fs-wrap{
            font-size:10px;
            margin: 0 auto !important;
        }
        
        .fs-header {
            text-align: center !important;
        }
        
        .fs-panel-head{
            background:#002F70!important;
            -webkit-print-color-adjust:exact;
            print-color-adjust:exact;
        }
        .fs-bank{
            background:#002F70!important;
            -webkit-print-color-adjust:exact;
            print-color-adjust:exact;
        }
        
        /* Hide all watermarks and background elements */
        body::before, body::after, html::before, html::after {
            content: none !important;
            display: none !important;
        }
        
        /* Hide fixed position elements outside print area */
        body > *:not(.wrapper):not(.content-wrapper):not(.fs-wrap) {
            display: none !important;
            visibility: hidden !important;
            opacity: 0 !important;
        }
        
        /* Remove background images */
        * {
            background-image: none !important;
            box-shadow: none !important;
        }
    }
    </style>
    <div class="fs-wrap">
    <!-- Header -->
    <div class="fs-header">
      <div>
        <div class="meta">PETRON STATION MANAGEMENT SYSTEM &nbsp;|&nbsp; <?= htmlspecialchars($station_name) ?></div>
        <h2><i class="fa-solid fa-gas-pump" style="margin-right:6px;"></i>DAILY FUEL SALES REPORT &nbsp;—&nbsp; <?= date('d M Y',strtotime($date_start)) ?><?= $date_start!==$date_end ? ' to '.date('d M Y',strtotime($date_end)) : '' ?></h2>
      </div>
      <div style="display:flex;gap:6px;" class="no-print"><?= $card_btns ?></div>
    </div>

    <!-- Main 3-Column Grid -->
    <div class="fs-grid">

      <!-- COL 1: Meter Reading Table -->
      <div class="fs-panel">
        <div class="fs-panel-head"><i class="fa-solid fa-gauge" style="margin-right:5px;"></i>Meter Reading Table <span style="opacity:.7;font-weight:400;">(Liters = Ending − Beginning ± Cal.)</span></div>
        <?php if(empty($meter_readings)): ?>
          <div class="fs-empty">No meter readings.</div>
        <?php else: ?>
        <table class="fs-tbl">
          <thead><tr><th>#</th><th>Tanker ID</th><th>Fuel Type</th><th>Beginning Reading</th><th>Ending Reading</th><th>Cal.</th><th>Liters Sold</th><th>Unit Price</th><th>Amount</th><th>Shift</th><th>Payment</th></tr></thead>
          <tbody>
            <?php foreach($meter_readings as $i=>$r): ?>
            <tr>
              <td><?= $i+1 ?></td>
              <td><strong><?= is_numeric($r['pump']) ? sprintf('TK-%02d',$r['pump']) : htmlspecialchars($r['pump']) ?></strong></td>
              <td><strong><?= htmlspecialchars($r['fuel_type']) ?></strong></td>
              <td><?= number_format((float)$r['beginning'],2) ?></td>
              <td><?= number_format((float)$r['ending'],2) ?></td>
              <td style="color:#b45309;"><?= number_format((float)$r['calibration'],2) ?></td>
              <td><strong><?= number_format((float)$r['liters_sold'],2) ?> L</strong></td>
              <td>₱<?= number_format((float)$r['price_per_liter'],2) ?></td>
              <td><strong>₱<?= number_format((float)$r['amount'],2) ?></strong></td>
              <td><?= htmlspecialchars($r['shift']) ?></td>
              <td><?= htmlspecialchars($r['payment_type'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="fs-tot"><td colspan="6" style="text-align:right;padding-right:8px;">TOTAL</td><td><?= number_format($grand_liters,2) ?> L</td><td>—</td><td>₱<?= number_format($grand_amount,2) ?></td><td colspan="2"></td></tr>
          </tbody>
        </table>
        <?php endif; ?>
      </div>

      <!-- COL 2: Volume Sales + Tank Sales -->
      <div class="fs-col">
        <!-- Volume Sales Summary -->
        <div class="fs-panel">
          <div class="fs-panel-head"><i class="fa-solid fa-chart-column" style="margin-right:5px;"></i>Volume Sales Summary</div>
          <?php if(empty($volume_sales)): ?>
            <div class="fs-empty">No data.</div>
          <?php else: ?>
          <table class="fs-tbl">
            <thead><tr><th>Fuel Type</th><th>Total Liters Sold</th><th>Avg Price/L</th><th>Total Amount</th></tr></thead>
            <tbody>
              <?php foreach($volume_sales as $vs): ?>
              <tr>
                <td><strong><?= htmlspecialchars($vs['fuel_type']) ?></strong></td>
                <td><?= number_format((float)$vs['total_liters'],2) ?> L</td>
                <td>₱<?= number_format((float)$vs['avg_price'],2) ?></td>
                <td>₱<?= number_format((float)$vs['total_amount'],2) ?></td>
              </tr>
              <?php endforeach; ?>
              <tr class="fs-tot"><td>TOTAL</td><td><?= number_format($grand_liters,2) ?> L</td><td>—</td><td>₱<?= number_format($grand_amount,2) ?></td></tr>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
        <!-- Tank Sales Summary -->
        <div class="fs-panel">
          <div class="fs-panel-head"><i class="fa-solid fa-database" style="margin-right:5px;"></i>Tank Sales Summary</div>
          <table class="fs-tbl">
            <thead><tr><th>Tank</th><th>Fuel Type</th><th>Tank Capacity</th><th>Dispensed Liters</th><th>Utilization %</th></tr></thead>
            <tbody>
              <?php foreach($PHYSICAL_TANKS as $pt):
                $ft=$pt['fuel_type']; $cnt=$tank_counts[$ft]??1;
                $disp=($dispensed_by_type[$ft]??0)/$cnt;
                $util=$pt['capacity']>0?round($disp/$pt['capacity']*100,1):0;
                $ucol=$util>80?'#cc0000':($util>50?'#d97706':'#16a34a');
              ?>
              <tr>
                <td style="font-size:10px;color:#64748b;"><?= htmlspecialchars($pt['label']) ?></td>
                <td><?= htmlspecialchars($ft) ?></td>
                <td><?= number_format($pt['capacity'],0) ?></td>
                <td><?= number_format($disp,2) ?></td>
                <td style="color:<?= $ucol ?>;font-weight:700;"><?= $util ?>%</td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <!-- Service Income Summary -->
        <div class="fs-panel">
          <div class="fs-panel-head navy"><i class="fa-solid fa-screwdriver-wrench" style="margin-right:5px;"></i>Service Income (Job Orders) <span style="opacity:.7;font-weight:400;">(<?= count($service_income_rows) ?> orders)</span></div>
          <?php if(empty($service_income_rows)): ?>
            <div class="fs-empty">No service income for this period.</div>
          <?php else: ?>
          <div style="overflow-y:auto; max-height:220px;">
            <table class="fs-tbl">
              <thead><tr><th>Job Ref</th><th>Customer</th><th>Plate</th><th>Service Type</th><th>Payment</th><th>Total Cost</th></tr></thead>
              <tbody>
                <?php foreach($service_income_rows as $sr): ?>
                <tr>
                  <td><strong><?= htmlspecialchars($sr['job_ref']) ?></strong></td>
                  <td><?= htmlspecialchars($sr['customer_name']) ?></td>
                  <td><code style="background:#f1f5f9;color:#0f172a;padding:1px 3px;border-radius:3px;font-size:10px;"><?= htmlspecialchars($sr['vehicle_plate']) ?></code></td>
                  <td><span title="<?= htmlspecialchars($sr['service_type']) ?>"><?= htmlspecialchars(mb_strimwidth($sr['service_type'],0,20,'...')) ?></span></td>
                  <td><span class="badge" style="background:#f1f5f9;color:#334155;font-size:9px;padding:2px 4px;border-radius:3px;font-weight:600;"><?= htmlspecialchars($sr['payment_method']) ?></span></td>
                  <td><strong>₱<?= number_format((float)$sr['total_cost'],2) ?></strong></td>
                </tr>
                <?php endforeach; ?>
                <tr class="fs-tot"><td colspan="5">TOTAL SERVICE INCOME</td><td>₱<?= number_format($service_income_total,2) ?></td></tr>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- COL 3: Shift Summaries + AR -->
      <div class="fs-col">
        <!-- Shift 1 (visible to Shift 1 users and managers) -->
        <?php if ($can_see_shift1): ?>
        <div class="fs-panel">
          <div class="fs-panel-head navy"><i class="fa-solid fa-sun" style="margin-right:5px;"></i>Shift 1 Sales &amp; Cash (6AM–2PM)</div>
          <?php if(empty($shift1_sales)): ?>
            <div class="fs-empty">No Shift 1 records.</div>
          <?php else: ?>
          <table class="fs-tbl">
            <thead><tr><th>Fuel Type</th><th>Liters</th><th>Total Sales</th><th>Cash Received</th><th>Digital</th><th>Credit/AR</th></tr></thead>
            <tbody>
              <?php foreach($shift1_sales as $r): ?>
              <tr>
                <td><strong><?= htmlspecialchars($r['fuel_type']) ?></strong></td>
                <td><?= number_format((float)$r['total_liters'],2) ?></td>
                <td>₱<?= number_format((float)$r['total_amount'],2) ?></td>
                <td>₱<?= number_format((float)$r['cash_amount'],2) ?></td>
                <td>₱<?= number_format((float)$r['digital_amount'],2) ?></td>
                <td>₱<?= number_format((float)$r['credit_amount'],2) ?></td>
              </tr>
              <?php endforeach; ?>
              <tr class="fs-tot"><td>TOTAL</td><td><?= number_format(array_sum(array_column($shift1_sales,'total_liters')),2) ?></td><td>₱<?= number_format(array_sum(array_column($shift1_sales,'total_amount')),2) ?></td><td>₱<?= number_format($s1_cash,2) ?></td><td>₱<?= number_format($s1_digital,2) ?></td><td>₱<?= number_format($s1_credit,2) ?></td></tr>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <!-- Shift 2 (visible to Shift 2 users and managers) -->
        <?php if ($can_see_shift2): ?>
        <div class="fs-panel">
          <div class="fs-panel-head navy"><i class="fa-solid fa-moon" style="margin-right:5px;"></i>Shift 2 Sales &amp; Cash (2PM–12MN)</div>
          <?php if(empty($shift2_sales)): ?>
            <div class="fs-empty">No Shift 2 records.</div>
          <?php else: ?>
          <table class="fs-tbl">
            <thead><tr><th>Fuel Type</th><th>Liters</th><th>Total Sales</th><th>Cash Received</th><th>Digital</th><th>Credit/AR</th></tr></thead>
            <tbody>
              <?php foreach($shift2_sales as $r): ?>
              <tr>
                <td><strong><?= htmlspecialchars($r['fuel_type']) ?></strong></td>
                <td><?= number_format((float)$r['total_liters'],2) ?></td>
                <td>₱<?= number_format((float)$r['total_amount'],2) ?></td>
                <td>₱<?= number_format((float)$r['cash_amount'],2) ?></td>
                <td>₱<?= number_format((float)$r['digital_amount'],2) ?></td>
                <td>₱<?= number_format((float)$r['credit_amount'],2) ?></td>
              </tr>
              <?php endforeach; ?>
              <tr class="fs-tot"><td>TOTAL</td><td><?= number_format(array_sum(array_column($shift2_sales,'total_liters')),2) ?></td><td>₱<?= number_format(array_sum(array_column($shift2_sales,'total_amount')),2) ?></td><td>₱<?= number_format($s2_cash,2) ?></td><td>₱<?= number_format($s2_digital,2) ?></td><td>₱<?= number_format($s2_credit,2) ?></td></tr>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <!-- AR Summary -->
        <div class="fs-panel">
          <div class="fs-panel-head red"><i class="fa-solid fa-file-invoice" style="margin-right:5px;"></i>A/R Summary <span style="opacity:.7;font-weight:400;">(<?= count($ar_summary) ?> records)</span></div>
          <?php if(empty($ar_summary)): ?>
            <div class="fs-empty">No accounts receivable.</div>
          <?php else: ?>
          <table class="fs-tbl">
            <thead><tr><th>Customer Name</th><th>Outstanding Balance</th></tr></thead>
            <tbody>
              <?php foreach($ar_summary as $ar): ?>
              <tr>
                <td><strong><?= htmlspecialchars($ar['customer_name']) ?></strong></td>
                <td><strong>₱<?= number_format((float)$ar['outstanding_balance'],2) ?></strong></td>
              </tr>
              <?php endforeach; ?>
              <tr class="fs-tot"><td>TOTAL A/R</td><td>₱<?= number_format(array_sum(array_column($ar_summary,'outstanding_balance')),2) ?></td></tr>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </div>
    </div><!-- /fs-grid -->

    <!-- Bottom: Overall Summary + Cash in Bank side by side -->
    <div class="fs-summary-row">
      <!-- Overall Daily Summary -->
      <div class="fs-overall">
        <div class="fs-panel-head"><i class="fa-solid fa-chart-pie" style="margin-right:5px;"></i>Overall Daily Summary</div>
        <table class="fs-tbl">
          <thead><tr><th>Metric</th><th>Value</th></tr></thead>
          <tbody>
            <tr><td>Total Liters Sold</td><td><strong><?= number_format($grand_liters,2) ?> L</strong></td></tr>
            <tr><td>Total Fuel Sales</td><td>₱<?= number_format($grand_amount,2) ?></td></tr>
            <tr><td>Total Service Income</td><td>₱<?= number_format($service_income_total,2) ?></td></tr>
            <tr style="background:#f8fafc;border-top:1.5px solid #cbd5e1;"><td><strong>Grand Total (Fuel + Services)</strong></td><td><strong>₱<?= number_format($grand_total_all,2) ?></strong></td></tr>
            <tr><td>Shift 1 &mdash; Cash Received</td><td>₱<?= number_format($s1_cash,2) ?></td></tr>
            <tr><td>Shift 1 &mdash; Digital</td><td>₱<?= number_format($s1_digital,2) ?></td></tr>
            <tr><td>Shift 1 &mdash; Credit/AR</td><td>₱<?= number_format($s1_credit,2) ?></td></tr>
            <tr><td>Shift 2 &mdash; Cash Received</td><td>₱<?= number_format($s2_cash,2) ?></td></tr>
            <tr><td>Shift 2 &mdash; Digital</td><td>₱<?= number_format($s2_digital,2) ?></td></tr>
            <tr><td>Shift 2 &mdash; Credit/AR</td><td>₱<?= number_format($s2_credit,2) ?></td></tr>
            <tr><td>Total Cash Sales (including Service Cash)</td><td>₱<?= number_format($total_cash,2) ?></td></tr>
            <tr><td>Total Digital/Card Sales (including Service Digital)</td><td>₱<?= number_format($total_digital,2) ?></td></tr>
            <tr><td>Total Accounts Receivable (A/R)</td><td>₱<?= number_format($total_ar_outstanding,2) ?></td></tr>
            <tr style="background:#f0fdf4;"><td><strong>Total Cash in Bank (Deposits)</strong></td><td><strong style="color:#15803d;">₱<?= number_format($cash_in_bank_deposit,2) ?></strong></td></tr>
            <tr style="background:#fef9c3;"><td><strong>Cash on Hand</strong></td><td><strong>₱<?= number_format($cash_on_hand,2) ?></strong></td></tr>
            <tr class="fs-tot2"><td><strong>Variance</strong></td><td style="color:<?= $variance==0?'#15803d':'#cc0000' ?>;font-weight:800;">₱<?= number_format($variance,2) ?></strong></td></tr>
          </tbody>
        </table>
      </div>
      <!-- Total Cash in Bank -->
      <div style="display:flex;flex-direction:column;gap:10px;">
        <div class="fs-bank">
          <div>
            <div class="sm"><i class="fa-solid fa-building-columns" style="margin-right:5px;"></i>TOTAL CASH IN BANK</div>
            <div class="big">₱<?= number_format($cash_in_bank,2) ?></div>
            <div class="sm" style="margin-top:4px;">Cash + Digital · Ready for Deposit</div>
          </div>
          <div class="items">
            <div class="item"><div class="val" style="color:#86efac;">₱<?= number_format($total_cash,2) ?></div><div class="sm">Cash</div></div>
            <div class="item" style="opacity:.5;font-size:18px;align-self:center;">+</div>
            <div class="item"><div class="val" style="color:#c4b5fd;">₱<?= number_format($total_digital,2) ?></div><div class="sm">Digital</div></div>
            <?php if($total_credit>0): ?>
            <div class="item" style="border-left:1px solid rgba(255,255,255,.25);padding-left:14px;"><div class="val" style="color:#fdba74;">₱<?= number_format($total_credit,2) ?></div><div class="sm">Pending A/R</div></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    </div><!-- /fs-wrap -->

    <?php elseif ($sub_tab === 'merch_sales'): ?>
    <!-- Force browser to reload CSS -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <style>
    .ms-wrap{font-size:12px;}
    .ms-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:12px;background:#002F70;color:#fff;border-radius:8px;padding:10px 16px;}
    .ms-header h2{font-size:14px;font-weight:800;margin:0;letter-spacing:-.2px;}
    .ms-header .meta{font-size:11px;opacity:.75;margin-top:1px;}
    .ms-panel{background:#fff;border-radius:8px;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:10px;}
    .ms-panel-head{background:#002F70;color:#fff;padding:6px 10px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;}
    .ms-panel-head.red{background:#cc0000;}
    .ms-panel-head.green{background:#15803d;}
    .ms-panel-head.navy{background:#1a3a6b;}
    .ms-tbl{width:100%;border-collapse:collapse;font-size:11px;}
    .ms-tbl th{background:#f0f4ff;color:#002F70;padding:5px 7px;text-align:left;font-weight:700;font-size:10px;text-transform:uppercase;border-bottom:1px solid #dde3f0;white-space:nowrap;}
    .ms-tbl td{padding:4px 7px;border-bottom:1px solid #f1f5f9;color:#1e293b;white-space:nowrap;}
    .ms-tbl tr:last-child td{border-bottom:none;}
    .ms-tbl .ms-tot td{background:#f0f4ff;font-weight:700;color:#002F70;border-top:2px solid #c7d2fe;}
    .ms-tbl .ms-tot2 td{background:#fefce8;font-weight:700;color:#92400e;border-top:2px solid #fde68a;}
    .ms-two{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;}
    .ms-three{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:10px;}
    .ms-overall{background:#fff;border-radius:8px;border:1px solid #e2e8f0;overflow:hidden;}
    .ms-bank{background:linear-gradient(135deg,#002F70,#1a3a8f);color:#fff;border-radius:8px;padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;}
    .ms-bank .big{font-size:22px;font-weight:900;letter-spacing:-1px;}
    .ms-bank .sm{font-size:10px;opacity:.7;text-transform:uppercase;letter-spacing:.3px;}
    .ms-bank .items{display:flex;gap:14px;}
    .ms-bank .item{text-align:center;}
    .ms-bank .item .val{font-size:14px;font-weight:800;}
    .ms-empty{padding:14px;text-align:center;color:#9ca3af;font-size:11px;}
    @media print{
        /* Set page size and hide system elements */
        @page {
            size: legal portrait;
            margin: 0.3in 0.4in;
        }
        
        * {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        
        html, body { 
            background: white !important; 
            padding: 0 !important; 
            margin: 0 auto !important;
            width: 100% !important;
        }
        
        /* Hide controls, sidebar, navigation, logo */
        .no-print,
        .sidebar,
        .main-sidebar,
        aside,
        nav,
        .navbar,
        .main-header,
        img,
        .logo,
        .brand-image,
        body > header,
        body > footer,
        .main-footer { 
            display: none !important;
            visibility: hidden !important;
        }
        
        /* Center content */
        .wrapper,
        .content-wrapper { 
            margin: 0 auto !important;
            padding: 0 !important;
            width: 100% !important;
        }
        
        .ms-wrap{
            font-size:10px;
            margin: 0 auto !important;
        }
        
        .ms-header {
            text-align: center !important;
        }
        
        .ms-panel-head{
            background:#002F70!important;
            -webkit-print-color-adjust:exact;
            print-color-adjust:exact;
        }
        .ms-bank{
            background:#002F70!important;
            -webkit-print-color-adjust:exact;
            print-color-adjust:exact;
        }
        
        /* Hide all watermarks and background elements */
        body::before, body::after, html::before, html::after {
            content: none !important;
            display: none !important;
        }
        
        /* Hide fixed position elements outside print area */
        body > *:not(.wrapper):not(.content-wrapper):not(.ms-wrap) {
            display: none !important;
            visibility: hidden !important;
            opacity: 0 !important;
        }
        
        /* Remove background images */
        * {
            background-image: none !important;
            box-shadow: none !important;
        }
    }
    </style>

    <div class="ms-wrap">
    <!-- Header -->
    <div class="ms-header">
      <div>
        <div class="meta">PETRON STATION MANAGEMENT SYSTEM &nbsp;|&nbsp; <?= htmlspecialchars($station_name) ?></div>
        <h2><i class="fa-solid fa-boxes-stacked" style="margin-right:6px;"></i>DAILY MERCHANDISE SALES REPORT &nbsp;—&nbsp; <?= date('d M Y',strtotime($date_start)) ?><?= $date_start!==$date_end ? ' to '.date('d M Y',strtotime($date_end)) : '' ?></h2>
      </div>
      <div style="display:flex;gap:6px;" class="no-print"><?= $card_btns ?></div>
    </div>

    <!-- Merchandise Sales Table (full width) -->
    <div class="ms-panel">
      <div class="ms-panel-head"><i class="fa-solid fa-store" style="margin-right:5px;"></i>Merchandise Sales Table <span style="opacity:.7;font-weight:400;">(Ending = Beginning + Stock-In − Stock-Out)</span></div>
      <?php if(empty($merch_items)): ?>
        <div class="ms-empty">No merchandise sales for this period.</div>
      <?php else: ?>
      <table class="ms-tbl">
        <thead><tr>
          <th>#</th><th>Category</th><th>Product Name</th><th>Size</th>
          <th>Beg. Stock</th><th>Stock-In</th><th>Stock-Out</th><th>End Stock</th>
          <th>Unit Price</th><th>Amount</th><th>Encoder</th><th>Remarks</th>
        </tr></thead>
        <tbody>
          <?php
          $merch_tot_qty = 0; $merch_tot_amt = 0;
          foreach($merch_items as $mi => $m):
            $merch_tot_qty += $m['stock_out'];
            $merch_tot_amt += $m['amount'];
          ?>
          <tr>
            <td><?= $mi+1 ?></td>
            <td style="font-size:10px;color:#64748b;"><?= htmlspecialchars($m['category']) ?></td>
            <td><strong><?= htmlspecialchars($m['product_name']) ?></strong></td>
            <td><?= htmlspecialchars($m['size_variant']) ?></td>
            <td><?= number_format($m['beginning_stock'],2) ?></td>
            <td style="color:#16a34a;font-weight:600;"><?= number_format($m['stock_in'],2) ?></td>
            <td style="color:#cc0000;font-weight:600;"><?= number_format($m['stock_out'],2) ?></td>
            <td><strong><?= number_format($m['ending_stock'],2) ?></strong></td>
            <td>₱<?= number_format($m['unit_price'],2) ?></td>
            <td><strong>₱<?= number_format($m['amount'],2) ?></strong></td>
            <td style="font-size:10px;"><?= htmlspecialchars($m['encoders']) ?></td>
            <td style="font-size:10px;color:#64748b;"><?= htmlspecialchars($m['remarks']) ?></td>
          </tr>
          <?php endforeach; ?>
          <tr class="ms-tot">
            <td colspan="6" style="text-align:right;padding-right:8px;">TOTAL</td>
            <td><?= number_format($merch_tot_qty,2) ?></td>
            <td>—</td><td>—</td>
            <td>₱<?= number_format($merch_tot_amt,2) ?></td>
            <td colspan="2"></td>
          </tr>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <!-- Shift 1 & 2 side by side (shift-gated) -->
    <div class="ms-two">
      <!-- Shift 1 (visible to Shift 1 users and managers) -->
      <?php if ($can_see_shift1): ?>
      <div class="ms-panel">
        <div class="ms-panel-head navy"><i class="fa-solid fa-sun" style="margin-right:5px;"></i>Shift 1 Sales &amp; Cash (6AM–2PM)</div>
        <?php if(empty($merch_shift1)): ?>
          <div class="ms-empty">No Shift 1 records.</div>
        <?php else: ?>
        <table class="ms-tbl">
          <thead><tr><th>Category</th><th>Items</th><th>Total</th><th>Cash</th><th>Digital</th><th>Credit/AR</th></tr></thead>
          <tbody>
            <?php foreach($merch_shift1 as $r): ?>
            <tr>
              <td><?= htmlspecialchars($r['category']) ?></td>
              <td><?= number_format($r['total_qty'],2) ?></td>
              <td>₱<?= number_format($r['total_amount'],2) ?></td>
              <td>₱<?= number_format($r['cash_amount'],2) ?></td>
              <td>₱<?= number_format($r['digital_amount'],2) ?></td>
              <td>₱<?= number_format($r['credit_amount'],2) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="ms-tot"><td>TOTAL</td><td>—</td>
              <td>₱<?= number_format(array_sum(array_column($merch_shift1,'total_amount')),2) ?></td>
              <td>₱<?= number_format($merch_s1_cash,2) ?></td>
              <td>₱<?= number_format($merch_s1_digital,2) ?></td>
              <td>₱<?= number_format($merch_s1_credit,2) ?></td>
            </tr>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <!-- Shift 2 (visible to Shift 2 users and managers) -->
      <?php if ($can_see_shift2): ?>
      <div class="ms-panel">
        <div class="ms-panel-head navy"><i class="fa-solid fa-moon" style="margin-right:5px;"></i>Shift 2 Sales &amp; Cash (2PM–12MN)</div>
        <?php if(empty($merch_shift2)): ?>
          <div class="ms-empty">No Shift 2 records.</div>
        <?php else: ?>
        <table class="ms-tbl">
          <thead><tr><th>Category</th><th>Items</th><th>Total</th><th>Cash</th><th>Digital</th><th>Credit/AR</th></tr></thead>
          <tbody>
            <?php foreach($merch_shift2 as $r): ?>
            <tr>
              <td><?= htmlspecialchars($r['category']) ?></td>
              <td><?= number_format($r['total_qty'],2) ?></td>
              <td>₱<?= number_format($r['total_amount'],2) ?></td>
              <td>₱<?= number_format($r['cash_amount'],2) ?></td>
              <td>₱<?= number_format($r['digital_amount'],2) ?></td>
              <td>₱<?= number_format($r['credit_amount'],2) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="ms-tot"><td>TOTAL</td><td>—</td>
              <td>₱<?= number_format(array_sum(array_column($merch_shift2,'total_amount')),2) ?></td>
              <td>₱<?= number_format($merch_s2_cash,2) ?></td>
              <td>₱<?= number_format($merch_s2_digital,2) ?></td>
              <td>₱<?= number_format($merch_s2_credit,2) ?></td>
            </tr>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Category Totals + A/R side by side -->
    <div class="ms-two">
      <!-- Category Totals -->
      <div class="ms-panel">
        <div class="ms-panel-head green"><i class="fa-solid fa-layer-group" style="margin-right:5px;"></i>Category Totals</div>
        <?php if(empty($merch_cat_totals)): ?>
          <div class="ms-empty">No data.</div>
        <?php else: ?>
        <table class="ms-tbl">
          <thead><tr><th>Category</th><th>Items Sold</th><th>Total Amount</th></tr></thead>
          <tbody>
            <?php foreach($merch_cat_totals as $r): ?>
            <tr>
              <td><?= htmlspecialchars($r['category']) ?></td>
              <td><?= number_format($r['total_qty'],2) ?></td>
              <td><strong>₱<?= number_format($r['total_amount'],2) ?></strong></td>
            </tr>
            <?php endforeach; ?>
            <tr class="ms-tot">
              <td>TOTAL</td>
              <td><?= number_format(array_sum(array_column($merch_cat_totals,'total_qty')),2) ?></td>
              <td>₱<?= number_format($merch_grand_sales,2) ?></td>
            </tr>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
      <!-- A/R Summary -->
      <div class="ms-panel">
        <div class="ms-panel-head red"><i class="fa-solid fa-file-invoice" style="margin-right:5px;"></i>A/R Summary <span style="opacity:.7;font-weight:400;">(<?= count($merch_ar) ?> records)</span></div>
        <?php if(empty($merch_ar)): ?>
          <div class="ms-empty">No accounts receivable.</div>
        <?php else: ?>
        <table class="ms-tbl">
          <thead><tr><th>Ref</th><th>Customer</th><th>Amount</th><th>Status</th><th>Due</th></tr></thead>
          <tbody>
            <?php foreach($merch_ar as $ar):
              $sc=strtolower($ar['status'])==='paid'?'#16a34a':(strtolower($ar['status'])==='overdue'?'#cc0000':'#d97706');
            ?>
            <tr>
              <td style="font-size:10px;"><?= htmlspecialchars($ar['transaction_id']) ?></td>
              <td><?= htmlspecialchars($ar['customer_name']) ?></td>
              <td>₱<?= number_format($ar['amount'],2) ?></td>
              <td style="color:<?= $sc ?>;font-weight:700;"><?= ucfirst($ar['status']) ?></td>
              <td style="font-size:10px;"><?= htmlspecialchars($ar['due_date']) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="ms-tot"><td colspan="2">TOTAL A/R</td><td>₱<?= number_format(array_sum(array_column($merch_ar,'amount')),2) ?></td><td colspan="2"></td></tr>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- Bottom: Overall Summary + Cash in Bank -->
    <div class="ms-two">
      <!-- Overall Daily Summary -->
      <div class="ms-overall">
        <div class="ms-panel-head"><i class="fa-solid fa-chart-pie" style="margin-right:5px;"></i>Overall Daily Summary</div>
        <table class="ms-tbl">
          <thead><tr><th>Metric</th><th>Value</th></tr></thead>
          <tbody>
            <tr><td>Total Merchandise Sales</td><td><strong>₱<?= number_format($merch_grand_sales,2) ?></strong></td></tr>
            <tr><td>Total Items Sold</td><td><strong><?= number_format($merch_grand_qty) ?></strong></td></tr>
            <tr><td>Shift 1 — Cash</td><td>₱<?= number_format($merch_s1_cash,2) ?></td></tr>
            <tr><td>Shift 1 — Digital</td><td>₱<?= number_format($merch_s1_digital,2) ?></td></tr>
            <tr><td>Shift 1 — Credit/AR</td><td>₱<?= number_format($merch_s1_credit,2) ?></td></tr>
            <tr><td>Shift 2 — Cash</td><td>₱<?= number_format($merch_s2_cash,2) ?></td></tr>
            <tr><td>Shift 2 — Digital</td><td>₱<?= number_format($merch_s2_digital,2) ?></td></tr>
            <tr><td>Shift 2 — Credit/AR</td><td>₱<?= number_format($merch_s2_credit,2) ?></td></tr>
            <tr><td>Total Cash Sales</td><td>₱<?= number_format($merch_cash_total,2) ?></td></tr>
            <tr><td>Total Digital/Card Sales</td><td>₱<?= number_format($merch_digital_total,2) ?></td></tr>
            <tr class="ms-tot2"><td>Total Accounts Receivable (A/R)</td><td>₱<?= number_format($merch_credit_total,2) ?></td></tr>
          </tbody>
        </table>
      </div>
      <!-- Cash in Bank -->
      <div style="display:flex;flex-direction:column;gap:10px;">
        <div class="ms-bank">
          <div>
            <div class="sm"><i class="fa-solid fa-building-columns" style="margin-right:5px;"></i>TOTAL CASH IN BANK</div>
            <div class="big">₱<?= number_format($merch_cash_in_bank,2) ?></div>
            <div class="sm" style="margin-top:4px;">Cash + Digital · Ready for Deposit</div>
          </div>
          <div class="items">
            <div class="item"><div class="val" style="color:#86efac;">₱<?= number_format($merch_cash_total,2) ?></div><div class="sm">Cash</div></div>
            <div class="item" style="opacity:.5;font-size:18px;align-self:center;">+</div>
            <div class="item"><div class="val" style="color:#c4b5fd;">₱<?= number_format($merch_digital_total,2) ?></div><div class="sm">Digital</div></div>
            <?php if($merch_credit_total>0): ?>
            <div class="item" style="border-left:1px solid rgba(255,255,255,.25);padding-left:14px;"><div class="val" style="color:#fdba74;">₱<?= number_format($merch_credit_total,2) ?></div><div class="sm">Pending A/R</div></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    </div><!-- /ms-wrap -->

    <?php elseif ($section === 'job_orders' && $sub_tab === 'jo_list'): ?>
    
    <!-- MAIN JOB ORDER TABLE CARD -->
    <div class="rpt-card">
        <div class="rpt-card-head">
            <h3>
                <i class="fa-solid fa-wrench"></i> 
                <?= htmlspecialchars($sub_tabs_def[$section][$sub_tab]['label'] ?? 'Job Orders Report') ?>
                <span class="badge-count"><?= count($report_data) ?></span>
            </h3>
            <?= $card_btns ?>
        </div>

        <?php if (empty($report_data)): ?>
            <div class="empty-state">
                <i class="fa-solid fa-circle-info"></i>
                <p>No job orders found for this period.</p>
            </div>
        <?php else: ?>
            <div class="table-scroll">
                <table class="mgr-table" id="report-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Job Order ID</th>
                            <th>Customer Info</th>
                            <th>Service Type</th>
                            <th>Parts Used</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Labor Fee</th>
                            <th>Total Amount</th>
                            <th>Payment Mode</th>
                            <th>Shift</th>
                            <th>Status</th>
                            <th>Encoder</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data as $i => $job): 
                            $hour  = (int)date('H', strtotime($job['created_at']));
                            $shift_name = ($hour >= 6 && $hour < 14) ? 'Shift 1' : 'Shift 2';
                            
                            $stat = strtolower($job['status']);
                            $badge_class = 'badge-default';
                            if (in_array($stat, ['completed', 'approved', 'validated', 'paid'])) $badge_class = 'badge-approved';
                            elseif (in_array($stat, ['pending', 'in progress', 'pending validation'])) $badge_class = 'badge-pending';
                            elseif (in_array($stat, ['rejected', 'unpaid', 'cancelled'])) $badge_class = 'badge-rejected';
                            
                            $cust_info = htmlspecialchars($job['customer_name']);
                            if (!empty($job['contact_number'])) {
                                $cust_info .= ' (' . htmlspecialchars($job['contact_number']) . ')';
                            }
                            if (!empty($job['customer_ref_id']) && $job['customer_ref_id'] != '0') {
                                $cust_info .= ' [Ref: ' . htmlspecialchars($job['customer_ref_id']) . ']';
                            }
                        ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><strong><?= htmlspecialchars($job['job_order_id']) ?></strong></td>
                                <td><?= $cust_info ?></td>
                                <td><?= htmlspecialchars($job['service_type']) ?></td>
                                <td>
                                    <?php if (!empty($job['parts_used'])): ?>
                                        <?php foreach ($job['parts_used'] as $p): ?>
                                            <div style="white-space: nowrap;">• <?= htmlspecialchars($p['product_name']) ?></div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($job['parts_used'])): ?>
                                        <?php foreach ($job['parts_used'] as $p): ?>
                                            <div><?= htmlspecialchars($p['quantity_used']) ?></div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($job['parts_used'])): ?>
                                        <?php foreach ($job['parts_used'] as $p): ?>
                                            <div>₱<?= number_format((float)$p['unit_cost'], 2) ?></div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td>₱<?= number_format((float)$job['labor_fee'], 2) ?></td>
                                <td><strong>₱<?= number_format((float)$job['total_cost'], 2) ?></strong></td>
                                <td><?= htmlspecialchars($job['payment_mode']) ?></td>
                                <td><?= $shift_name ?></td>
                                <td><span class="badge <?= $badge_class ?>"><?= htmlspecialchars(ucfirst($job['status'])) ?></span></td>
                                <td><?= htmlspecialchars($job['encoder_name']) ?></td>
                                <td><span style="font-size: 11px; color: #64748b;"><?= htmlspecialchars($job['remarks'] ?: '—') ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- SHIFT AND OVERALL SUMMARIES (CLEAN CORPORATE DESIGN) -->
    <?php if (!empty($report_data)): ?>
    <div class="two-col" style="margin-top: 20px;">
        <!-- Shift Summaries Card -->
        <div class="rpt-card" style="margin-bottom: 0;">
            <div class="rpt-card-head">
                <h3><i class="fa-solid fa-clock"></i> Shift Summaries</h3>
            </div>
            <div class="table-scroll">
                <table class="mgr-table">
                    <thead>
                        <tr>
                            <th>Shift</th>
                            <th>Total Services</th>
                            <th>Total Amount</th>
                            <th>Cash</th>
                            <th>Digital</th>
                            <th>Credit / AR</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Shift 1</strong> (6:00 AM – 2:00 PM)</td>
                            <td><?= $jo_s1['services'] ?></td>
                            <td><strong>₱<?= number_format($jo_s1['amount'], 2) ?></strong></td>
                            <td>₱<?= number_format($jo_s1['cash'], 2) ?></td>
                            <td>₱<?= number_format($jo_s1['digital'], 2) ?></td>
                            <td>₱<?= number_format($jo_s1['credit'], 2) ?></td>
                        </tr>
                        <tr>
                            <td><strong>Shift 2</strong> (2:00 PM – 12:00 MN)</td>
                            <td><?= $jo_s2['services'] ?></td>
                            <td><strong>₱<?= number_format($jo_s2['amount'], 2) ?></strong></td>
                            <td>₱<?= number_format($jo_s2['cash'], 2) ?></td>
                            <td>₱<?= number_format($jo_s2['digital'], 2) ?></td>
                            <td>₱<?= number_format($jo_s2['credit'], 2) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Overall Summary Card -->
        <div class="rpt-card" style="margin-bottom: 0;">
            <div class="rpt-card-head">
                <h3><i class="fa-solid fa-chart-pie"></i> Overall Daily Summary</h3>
            </div>
            <div class="table-scroll">
                <table class="mgr-table">
                    <thead>
                        <tr>
                            <th>Metric</th>
                            <th>Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Total Job Orders</td>
                            <td><strong><?= count($report_data) ?></strong></td>
                        </tr>
                        <tr>
                            <td>Grand Total Amount</td>
                            <td><strong>₱<?= number_format($jo_s1['amount'] + $jo_s2['amount'], 2) ?></strong></td>
                        </tr>
                        <tr>
                            <td>Total Cash Received</td>
                            <td>₱<?= number_format($jo_s1['cash'] + $jo_s2['cash'], 2) ?></td>
                        </tr>
                        <tr>
                            <td>Total Digital Payments</td>
                            <td>₱<?= number_format($jo_s1['digital'] + $jo_s2['digital'], 2) ?></td>
                        </tr>
                        <tr>
                            <td>Total Credit / Accounts Receivable</td>
                            <td>₱<?= number_format($jo_s1['credit'] + $jo_s2['credit'], 2) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php else: // Generic single-table render ?>

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
<?php endif; // end fuel_sales vs generic ?>
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
            .rows-per-page { display: flex; align-items: center; gap: 8px; font-size: 13px; }
            .rows-per-page select { padding: 6px; border: 1px solid #cbd5e1 !important; border-radius: 4px; outline: none; cursor: pointer; background: #ffffff !important; color: inherit !important; }
            .page-info { font-size: 13px; }
            .pagination-controls { display: flex; align-items: center; gap: 10px; }
            .btn-page { display: inline-flex !important; align-items: center !important; justify-content: center !important; width: 32px !important; height: 32px !important; background: #ffffff !important; border: 1px solid #d1d5db !important; border-radius: 6px !important; color: #374151 !important; text-decoration: none !important; transition: all 0.2s !important; cursor: pointer !important; font-size: 13px !important; }
            .btn-page i { color: #374151 !important; }
            .btn-page:hover:not(.disabled) { background: #00264D !important; border-color: #00264D !important; color: #ffffff !important; }
            .btn-page:hover:not(.disabled) i { color: #ffffff !important; }
            .btn-page.disabled { opacity: 0.4; cursor: not-allowed; pointer-events: none; }
            .current-page { font-size: 13px; font-weight: 500; }
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
