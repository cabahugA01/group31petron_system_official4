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

// ── Generate a per-request API token so AJAX calls don't depend on session cookies ──

// ── Module gate ───────────────────────────────────────────────
if (!in_array($role, ['superadmin','developer']) && !is_module_enabled('transactions')) {
    render_module_disabled_page('Transactions');
}

if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
    header('Location: dashboard.php');
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
    $stmt = $pdo->prepare("
        SELECT ip.id                                          AS product_id,
               ip.product_name,
               COALESCE(NULLIF(TRIM(ip.sku),''), CONCAT('PRD-',ip.id)) AS sku,
               COALESCE(NULLIF(TRIM(ip.category),''),'General')        AS category,
               COALESCE(NULLIF(TRIM(ip.size),''),'')                   AS size,
               ip.unit_price                                            AS unit_price,
               COALESCE(si.stock_level, 0)                             AS stock_level
        FROM inventory_products ip
        LEFT JOIN station_inventory si
               ON si.product_id = ip.id
              AND si.station_id = ?
        WHERE COALESCE(NULLIF(TRIM(ip.category),''),'General') <> 'Fuel'
          AND COALESCE(ip.unit_price, 0) > 0
        ORDER BY ip.category, ip.product_name
    ");
    $stmt->execute([$station_id]);
    $merch_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $merch_products = []; }

// ── Customers for credit transactions ────────────────────────────────────────
$customers = [];
$customer_names = []; // For autocomplete - full names
$customer_first_names = []; // For first name autocomplete
try {
    $stmt = $pdo->prepare("SELECT id, name, contact_number, credit_limit, balance FROM customers WHERE station_id = ? AND status = 'active' ORDER BY name");
    $stmt->execute([$station_id]);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Extract unique names for autocomplete
    foreach ($customers as $c) {
        $full_name = trim($c['name'] ?? '');
        if ($full_name) {
            $customer_names[] = $full_name;
            // Extract first name for filtering
            $parts = explode(' ', $full_name, 2);
            $first_name = $parts[0] ?? '';
            if ($first_name && !in_array($first_name, $customer_first_names)) {
                $customer_first_names[] = $first_name;
            }
        }
    }
} catch (Exception $e) { 
    $customers = []; 
    $customer_names = [];
    $customer_first_names = [];
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

// ── Detect current shift period — use active labor session first (matches dashboard) ──
$merch_shift_key  = '';
$merch_shift_name = '';
try {
    // Priority 1: use the shift from the staff's active clock-in session (same as dashboard)
    $active_sess = $pdo->prepare(
        "SELECT shift_period, shift_name FROM labor_sessions
         WHERE user_id = ? AND end_time IS NULL
         ORDER BY start_time DESC LIMIT 1"
    );
    $active_sess->execute([$me['id']]);
    $active_row = $active_sess->fetch(PDO::FETCH_ASSOC);

    if ($active_row && !empty($active_row['shift_period'])) {
        $merch_shift_key  = $active_row['shift_period'];
        $merch_shift_name = $active_row['shift_name'] ?: '';
    } else {
        // Priority 2: fall back to time-based detection from DB
        $ct = date('H:i:s');
        $sp = $pdo->prepare("SELECT shift_key, shift_name FROM shift_periods WHERE is_active = 1 AND start_time <= ? AND end_time >= ? ORDER BY sort_order ASC LIMIT 1");
        $sp->execute([$ct, $ct]);
        $sf = $sp->fetch(PDO::FETCH_ASSOC);
        if ($sf) {
            $merch_shift_key  = $sf['shift_key'];
            $merch_shift_name = $sf['shift_name'];
        } else {
            // Priority 3: first active shift from DB
            $sp2 = $pdo->query("SELECT shift_key, shift_name FROM shift_periods WHERE is_active = 1 ORDER BY sort_order ASC LIMIT 1");
            $sf2 = $sp2 ? $sp2->fetch(PDO::FETCH_ASSOC) : null;
            if ($sf2) { $merch_shift_key = $sf2['shift_key']; $merch_shift_name = $sf2['shift_name']; }
        }
    }
    // If still empty, last resort: any shift from DB
    if (empty($merch_shift_key)) {
        $sp3 = $pdo->query("SELECT shift_key, shift_name FROM shift_periods ORDER BY sort_order ASC LIMIT 1");
        $sf3 = $sp3 ? $sp3->fetch(PDO::FETCH_ASSOC) : null;
        if ($sf3) { $merch_shift_key = $sf3['shift_key']; $merch_shift_name = $sf3['shift_name']; }
    }
} catch (Exception $e) {}
// Fuel form uses the same shift
$fuel_shift_key  = $merch_shift_key;
$fuel_shift_name = $merch_shift_name;

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
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE staff_id = ? AND status = 'Completed' AND DATE(completed_at) = CURDATE()");
    $stmt->execute([$me['id']]);
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

// ── Merchandise section: Transaction History panel (right side) ───────────────
$mh_recent        = [];
$mh_total         = 0;
$mh_filter_type   = $_GET['mh_type'] ?? 'all';
$mh_filter_start_date = $_GET['mh_start_date'] ?? '';
$mh_filter_end_date   = $_GET['mh_end_date'] ?? '';
$mh_filter_category   = $_GET['mh_category'] ?? '';
$mh_filter_product    = $_GET['mh_product'] ?? '';
$mh_page          = max(1, (int)($_GET['mh_page'] ?? 1));
$mh_per_page      = isset($_GET['mh_per_page']) && in_array((int)$_GET['mh_per_page'], [10,20,30,50]) ? (int)$_GET['mh_per_page'] : 10;
$mh_offset        = ($mh_page - 1) * $mh_per_page;
$mh_available_shifts     = [];
$mh_inv_impact           = [];
$mh_variance_alerts      = [];
$mh_kpi_txn_count        = 0;
$mh_kpi_items_released   = 0;
$mh_kpi_total_encoded    = 0.00;


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
                   mt.customer_name,
                   mt.payment_method,
                   $mh_date_col AS transaction_date,
                   mti.id AS item_id,
                   mti.product_id,
                   mti.product_name,
                   mti.category AS item_category,
                   mti.quantity,
                   mti.unit_price,
                   mti.subtotal AS item_total
            FROM merchandise_transactions mt
            INNER JOIN merchandise_transaction_items mti ON mti.transaction_id = mt.id
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
    $fuel_where  = "WHERE ft.station_id = ? AND ft.staff_id = ?";
    $fuel_params = [$station_id, $me['id']];

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
        // Detect which date column and status column exist in merchandise_transactions
        $mt_cols = [];
        try {
            $col_rows = $pdo->query("SHOW COLUMNS FROM merchandise_transactions")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($col_rows as $cr) { $mt_cols[strtolower($cr['Field'])] = true; }
        } catch (Exception $e) {}
        $mt_date_col   = isset($mt_cols['transaction_date']) ? 'mt.transaction_date' : 'mt.created_at';
        $mt_status_col = isset($mt_cols['validation_status']) ? 'mt.validation_status' : (isset($mt_cols['status']) ? 'mt.status' : "'Pending'");
        $mt_txnid_col  = isset($mt_cols['transaction_id'])   ? 'mt.transaction_id'   : 'mt.id';
        $mt_jo_col     = isset($mt_cols['job_order_service']) ? 'mt.job_order_service' : "NULL";
        $mt_sub_col    = isset($mt_cols['subtotal_amount'])   ? 'mt.subtotal_amount'   : 'NULL';
        $mt_vat_col    = isset($mt_cols['vat_amount'])        ? 'mt.vat_amount'        : 'NULL';

        // Rebuild merch WHERE using correct date column
        $merch_where2  = "WHERE mt.station_id = ? AND mt.staff_id = ?";
        $merch_params2 = [$station_id, $me['id']];
        if ($filter_shift !== '') {
            $merch_where2  .= " AND mt.shift_period = ?";
            $merch_params2[] = $filter_shift;
        }
        if ($filter_date !== '') {
            $merch_where2  .= " AND DATE($mt_date_col) = ?";
            $merch_params2[] = $filter_date;
        }

        // Get total count first (no limit)
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM merchandise_transactions mt $merch_where2");
        $count_stmt->execute($merch_params2);
        $merch_total_count = (int)$count_stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT mt.id,
                   $mt_txnid_col  AS transaction_id,
                   mt.customer_name,
                   mt.total_amount,
                   $mt_sub_col    AS subtotal_amount,
                   $mt_vat_col    AS vat_amount,
                   mt.payment_method,
                   $mt_date_col   AS transaction_date,
                   $mt_status_col AS status,
                   mt.shift_period,
                   mt.shift_name,
                   $mt_jo_col     AS job_order_service
            FROM merchandise_transactions mt
            $merch_where2
            ORDER BY $mt_date_col DESC
            " . ($section === 'merchandise' ? '' : "LIMIT $hist_per_page OFFSET $hist_offset") . "
        ");
        $stmt->execute($merch_params2);
        $recent_merch = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $recent_merch = []; }

    // ── Shift log from labor_sessions REMOVED (staff should not see shift logs) ────
    // Shift management is handled by managers/admins only
    $shift_log = []; // Empty - no shift log for staff
    $available_shifts = []; // Empty - no shift filter needed

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
        'pending payment'     => ['color' => '#ea580c', 'label' => 'Pending Payment'],
        'credit transaction'  => ['color' => '#9333ea', 'label' => 'Credit Transaction'],
        'credit'              => ['color' => '#9333ea', 'label' => 'Credit Transaction'],
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
                            $new_status  = $new_balance <= 0.009 ? 'Paid' : 'Partial Payment';
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
                            $new_status  = $new_balance <= 0.009 ? 'Paid' : 'Partial Payment';
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
                        $allowed = ['In Progress', 'Completed', 'Rejected'];
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
                                    updated_at = NOW()
                                WHERE id = ? AND station_id = ?
                            ")->execute([$cust_name, $veh_plate, $veh_type, $svc_type, $svc_desc, $mech_name, $est_cost, $jo_id, $station_id]);
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
                        $allowed = ['In Progress', 'Completed', 'Rejected'];
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
                                    updated_at = NOW()
                                WHERE id = ? AND station_id = ?
                            ")->execute([$cust_name, $veh_plate, $veh_type, $svc_type, $svc_desc, $mech_name, $est_cost, $jo_id, $station_id]);
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
                       'job_orders' AS _source,
                       $jo_col_due_date    AS due_date,
                       $jo_col_balance_due AS balance_due_col
                FROM job_orders jo
                LEFT JOIN users u  ON u.id = jo.assigned_mechanic_id
                LEFT JOIN users cb ON cb.id = jo.created_by
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
        } catch (Exception $e) { $jo_rows = []; }

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

            $stmt2 = $pdo->prepare("
                SELECT
                    mt.id,
                    mt.customer_name,
                    COALESCE(mt.job_order_service, 'Service') AS service_type,
                    '' AS service_description,
                    COALESCE(mt.workflow_status, mt.validation_status, 'Pending') AS status,
                    COALESCE(mt.validation_status, 'Pending') AS validation_status,
                    mt.total_amount AS estimated_cost,
                    mt.total_amount AS total_cost,
                    COALESCE($mt_col_staff_remarks, mt.remarks, '') AS notes,
                    COALESCE(mt.job_order_vehicle_plate, '') AS vehicle_plate,
                    COALESCE(mt.job_order_vehicle_type, '') AS vehicle_type,
                    mt.created_at,
                    COALESCE(mt.job_order_mechanic_name, '') AS mechanic_name,
                    COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),
                             u.username, CONCAT('User #', mt.staff_id)) AS created_by_name,
                    mt.payment_method,
                    COALESCE(mt.payment_status, 'Pending Payment') AS payment_status,
                    COALESCE(mt.amount_paid, 0)                    AS amount_paid,
                    COALESCE(mt.balance_due, mt.total_amount)      AS balance_due,
                    NULL AS assigned_mechanic_id,
                    NULL AS customer_id,
                    mt.id AS job_order_id,
                    NULL AS job_order_number,
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
                WHERE mt.station_id = ?
                  AND mt.transaction_type IN ('job_order', 'combined')
                ORDER BY mt.created_at DESC
            ");
            $stmt2->execute([$station_id]);
            $mt_rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('staff_transactions_hub MT tracker query error: ' . $e->getMessage());
            $mt_rows = [];
        }

        // Merge and sort by created_at DESC
        $job_orders = array_merge($jo_rows, $mt_rows);
        usort($job_orders, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));

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
        $stmt = $pdo->query("SELECT id, full_name, specialization FROM mechanics WHERE status = 'active' ORDER BY full_name");
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

<style>
/* ── Sub-tab & Icon Button Styles (immune to global button / text overrides) ── */
.txn-subtab-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    border-radius: 4px !important;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s ease-in-out;
}

/* Green theme (Merchandise subtabs & main tabs) */
.txn-subtab-btn.green.active {
    background: #28a745 !important;
    border: 1px solid #28a745 !important;
    color: #ffffff !important;
}
.txn-subtab-btn.green.active i {
    color: #ffffff !important;
}
.txn-subtab-btn.green.inactive {
    background: #ffffff !important;
    border: 1px solid #28a745 !important;
    color: #28a745 !important;
}
.txn-subtab-btn.green.inactive i {
    color: #28a745 !important;
}

/* Blue theme (Fuel & JO subtabs & main tabs) */
.txn-subtab-btn.blue.active {
    background: #002F6C !important;
    border: 1px solid #002F6C !important;
    color: #ffffff !important;
}
.txn-subtab-btn.blue.active i {
    color: #ffffff !important;
}
.txn-subtab-btn.blue.inactive {
    background: #ffffff !important;
    border: 1px solid #002F6C !important;
    color: #002F6C !important;
}
.txn-subtab-btn.blue.inactive i {
    color: #002F6C !important;
}

/* Dark blue theme (Job Order Tracker subtabs) */
.txn-subtab-btn.darkblue.active {
    background: #003d7a !important;
    border: 1px solid #003d7a !important;
    color: #ffffff !important;
}
.txn-subtab-btn.darkblue.active i {
    color: #ffffff !important;
}
.txn-subtab-btn.darkblue.inactive {
    background: #ffffff !important;
    border: 1px solid #003d7a !important;
    color: #003d7a !important;
}
.txn-subtab-btn.darkblue.inactive i {
    color: #003d7a !important;
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
    padding: 20px 20px 120px 20px !important;
}

/* ── Cart wrapper — single full-width column (matches inventory layout) ── */
.cart-wrapper {
    display: flex;
    flex-direction: column;
    gap: 14px;
    width: 100%;
}

/* ── Right panel — sticky so it stays visible while scrolling history ── */
.cart-panel {
    display: flex;
    flex-direction: column;
    width: 100%;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,.07);
    overflow: hidden;
    position: sticky;
    top: 80px;   /* below the fixed top nav */
    max-height: calc(100vh - 100px);
    overflow-y: auto;
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
    padding: 12px 18px 14px;
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
    box-shadow: 0 1px 6px rgba(0,0,0,.05);
    overflow: visible;
    margin-bottom: 16px;
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
    padding: 22px;
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
    padding: 0 16px;
    height: 36px;
    min-width: 140px;
    border-radius: 7px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid transparent;
    transition: all .18s;
    text-decoration: none;
    white-space: nowrap;
    background: white !important;
}

.txn-btn.primary {
    background: white !important;
    color: #003d7a !important;
    border: 1px solid #003d7a !important;
}
.txn-btn.primary:hover {
    background: #003d7a !important;
    color: white !important;
}

.txn-btn.success {
    background: white !important;
    color: #1d6f42 !important;
    border: 1px solid #1d6f42 !important;
}
.txn-btn.success:hover {
    background: #1d6f42 !important;
    color: white !important;
}

.txn-btn.secondary {
    background: white !important;
    color: #6b7280 !important;
    border: 1px solid #6b7280 !important;
}
.txn-btn.secondary:hover {
    background: #6b7280 !important;
    color: white !important;
}

.txn-btn.danger {
    background: white !important;
    color: #dc2626 !important;
    border: 1px solid #dc2626 !important;
}
.txn-btn.danger:hover {
    background: #dc2626 !important;
    color: white !important;
}

.txn-btn:disabled {
    opacity: .5;
    cursor: not-allowed;
}

.txn-btn.full { width: 100%; }

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

/* ── Flash messages ──────────────────────────────────────────── */
.flash {
    padding: 13px 18px;
    border-radius: 9px;
    margin-bottom: 18px;
    font-size: 13px;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 10px;
}
.flash.success { background: #dcfce7; border: 1px solid #86efac; color: #166534; }
.flash.error   { background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; }

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
<div class="flash success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($flash_success) ?></div>
<?php endif; ?>
<?php if ($flash_error): ?>
<div class="flash error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($flash_error) ?></div>
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

        <!-- ── Page Header ───────────────────────────────────────────── -->
        <div class="txn-section-header">
            <div class="txn-section-title">
                <div>
                    <h1>Fuel Transaction</h1>
                    <p style="font-size:14px;color:#666666;margin:3px 0 0;text-transform:uppercase;letter-spacing:0.3px;font-weight:500;">ENCODE DAILY PUMP READINGS AND FUEL TRANSACTIONS FOR MONITORING.</p>
                </div>
            </div>
            <div>
                <button type="button" onclick="window.location.href='staff_dashboard.php'" 
                        class="txn-btn secondary" title="Back to Staff Dashboard">
                    <i class="fas fa-arrow-left"></i> <span>Back</span>
                </button>
            </div>
        </div>

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
        <div style="display:flex;gap:10px;margin-bottom:20px;padding:0 4px;">
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
            
            foreach ($fuel_types as $idx => $ft):
                $ft_name_form = htmlspecialchars($ft['fuel_type']);
                $ft_lower = strtolower(trim($ft['fuel_type']));
                
                // Get tanker configuration for this fuel type
                $config_groups_forms = null;
                foreach ($tanker_config_forms as $key => $groups) {
                    if (str_contains($ft_lower, $key)) {
                        $config_groups_forms = $groups;
                        break;
                    }
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
                <table class="fet" style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="background:#002F70;">
                            <th rowspan="2" style="border:1px solid #001f4d;padding:12px;vertical-align:middle;font-weight:700;font-size:13px;color:#fff;">NAME</th>
                            <th colspan="6" style="border:1px solid #001f4d;padding:8px;text-align:center;font-size:14px;font-weight:700;color:#fff;">METER READING</th>
                            <th rowspan="2" style="border:1px solid #001f4d;padding:12px;vertical-align:middle;font-weight:700;font-size:11px;color:#fff;">NOTES</th>
                        </tr>
                        <tr style="background:#002F70;">
                            <th style="border:1px solid #001f4d;padding:8px;text-align:center;font-size:11px;font-weight:700;color:#fff;">BEGINNING</th>
                            <th style="border:1px solid #001f4d;padding:8px;text-align:center;font-size:11px;font-weight:700;color:#fff;">ENDING</th>
                            <th style="border:1px solid #001f4d;padding:8px;text-align:center;font-size:11px;font-weight:700;color:#fff;">CAL</th>
                            <th style="border:1px solid #001f4d;padding:8px;text-align:center;font-size:11px;font-weight:700;color:#fff;">VOLUME LITERS</th>
                            <th style="border:1px solid #001f4d;padding:8px;text-align:center;font-size:11px;font-weight:700;color:#fff;">PRICE</th>
                            <th style="border:1px solid #001f4d;padding:8px;text-align:center;font-size:11px;font-weight:700;color:#fff;">AMOUNT</th>
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
                    
                    foreach ($fuel_types as $idx => $ft):
                        $ft_lower = strtolower(trim($ft['fuel_type']));
                        $price_per_liter = (float)$ft['price_per_liter'];
                        
                        // Get tanker configuration for this fuel type
                        $config_groups = null;
                        foreach ($tanker_config as $key => $groups) {
                            if (str_contains($ft_lower, $key)) {
                                $config_groups = $groups;
                                break;
                            }
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

                                // ── Continuous Meter Reading Cycle ──────────────────────────────────
                                // Fetch the last present_reading for this specific pump (fuel_type + pump_id)
                                // This becomes the pre-filled Beginning for the current shift.
                                $pump_prev_reading = 0.00;
                                try {
                                    // Bulletproof: match pump_id whether it stores tanker_num OR the resolved fuel_pumps PK
                                    $pump_prev_stmt = $pdo->prepare("
                                        SELECT present_reading FROM fuel_transactions
                                        WHERE station_id = ?
                                          AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                                          AND (
                                            pump_id = ?
                                            OR pump_id = (
                                                SELECT fp.id FROM fuel_pumps fp
                                                JOIN fuel_types ftp ON ftp.id = fp.fuel_type_id
                                                WHERE fp.station_id = ?
                                                  AND fp.pump_number = ?
                                                  AND LOWER(TRIM(ftp.name)) = LOWER(TRIM(?))
                                                LIMIT 1
                                            )
                                          )
                                          AND COALESCE(status, '') != 'Rejected'
                                        ORDER BY transaction_date DESC, id DESC LIMIT 1
                                    ");
                                    $pump_prev_stmt->execute([
                                        $station_id, $ft['fuel_type'], $tanker_num,
                                        $station_id, (string)$tanker_num, $ft['fuel_type']
                                    ]);
                                    $pump_prev_val = $pump_prev_stmt->fetchColumn();
                                    if ($pump_prev_val !== false && $pump_prev_val !== null) {
                                        $pump_prev_reading = (float)$pump_prev_val;
                                    }
                                } catch (Exception $e) { /* fallback to 0 */ }
                    ?>
                    <tr id="fuelRow_<?= $ft_id ?>" style="border-bottom:1px solid #e2e8f0;">
                        <!-- NAME Column (plain text, no icon) -->
                        <td style="border:1px solid #e2e8f0;padding:10px;">
                            <span style="font-weight:700;font-size:12px;color:#1e293b;"><?= $display_name ?></span>
                        </td>

                        <!-- BEGINNING Column — pre-filled from previous shift's Ending reading -->
                        <td style="border:1px solid #e2e8f0;padding:6px;">
                            <input type="text"
                                   form="fuelForm_<?= $ft_id ?>"
                                   name="beginning_reading"
                                   id="beginning_<?= $ft_id ?>"
                                   style="width:110px;padding:8px;font-size:12px;border:1px solid #cbd5e1;border-radius:4px;text-align:right;<?= $pump_prev_reading > 0 ? 'background:#f0f9ff;font-weight:600;' : '' ?>"
                                   value="<?= $pump_prev_reading > 0 ? number_format($pump_prev_reading, 2, '.', ',') : '' ?>"
                                   placeholder="<?= $pump_prev_reading > 0 ? number_format($pump_prev_reading, 2, '.', ',') : '0.00' ?>"
                                   autocomplete="off"
                                   oninput="formatOnInput(this); updateFuelCalc('<?= $ft_id ?>')"
                                   onblur="formatOnBlur(this); updateFuelCalc('<?= $ft_id ?>')">
                        </td>
                        
                        <!-- ENDING Column -->
                        <td style="border:1px solid #e2e8f0;padding:6px;">
                            <input type="text"
                                   form="fuelForm_<?= $ft_id ?>"
                                   name="ending_reading"
                                   id="ending_<?= $ft_id ?>"
                                   style="width:110px;padding:8px;font-size:12px;border:1px solid #cbd5e1;border-radius:4px;text-align:right;font-weight:700;"
                                   placeholder="0.00"
                                   required
                                   autocomplete="off"
                                   oninput="formatOnInput(this); updateFuelCalc('<?= $ft_id ?>')"
                                   onblur="formatOnBlur(this); updateFuelCalc('<?= $ft_id ?>')">
                        </td>
                        
                        <!-- CAL (Calibration) Column -->
                        <td style="border:1px solid #e2e8f0;padding:6px;">
                            <input type="text"
                                   form="fuelForm_<?= $ft_id ?>"
                                   name="calibration"
                                   id="cal_<?= $ft_id ?>"
                                   style="width:80px;padding:8px;font-size:12px;border:1px solid #cbd5e1;border-radius:4px;text-align:right;"
                                   value="0.00"
                                   placeholder="0.00"
                                   autocomplete="off"
                                   oninput="formatOnInput(this); updateFuelCalc('<?= $ft_id ?>')"
                                   onblur="formatOnBlur(this); updateFuelCalc('<?= $ft_id ?>')">
                        </td>
                        
                        <!-- VOLUME LITERS Column (Auto-calculated: Ending - Beginning - CAL) -->
                        <td style="border:1px solid #e2e8f0;padding:6px;">
                            <input type="text"
                                   id="volume_<?= $ft_id ?>"
                                   style="width:90px;padding:8px;font-size:12px;background:#f8fafc;border:1px solid #cbd5e1;border-radius:4px;text-align:right;font-weight:700;color:#334155;"
                                   value="0.00"
                                   readonly>
                            <input type="hidden"
                                   form="fuelForm_<?= $ft_id ?>"
                                   name="volume_liters"
                                   id="volume_value_<?= $ft_id ?>"
                                   value="0.00">
                        </td>
                        
                        <!-- PRICE Column (Fixed — fetched from product config, not editable) -->
                        <td style="border:1px solid #e2e8f0;padding:6px;background:#f8fafc;">
                            <span id="price_display_<?= $ft_id ?>"
                                  style="display:block;width:80px;padding:8px;font-size:13px;font-weight:700;color:#334155;text-align:right;">
                                ₱<?= number_format($price_per_liter, 2) ?>
                            </span>
                            <input type="hidden"
                                   form="fuelForm_<?= $ft_id ?>"
                                   name="price_per_liter"
                                   id="price_<?= $ft_id ?>"
                                   value="<?= number_format($price_per_liter, 2, '.', '') ?>">
                        </td>
                        
                        <!-- AMOUNT Column (Auto-calculated: Volume × Price) -->
                        <td style="border:1px solid #e2e8f0;padding:6px;">
                            <input type="text"
                                   id="amount_<?= $ft_id ?>"
                                   style="width:110px;padding:8px;font-size:12px;background:#f8fafc;border:1px solid #cbd5e1;border-radius:4px;text-align:right;font-weight:800;color:#0f172a;"
                                   value="₱0.00"
                                   readonly>
                            <input type="hidden"
                                   form="fuelForm_<?= $ft_id ?>"
                                   name="total_amount"
                                   id="amount_value_<?= $ft_id ?>"
                                   value="0.00">
                        </td>



                        <!-- NOTES Column -->
                        <td style="border:1px solid #e2e8f0;padding:6px;">
                            <input type="text"
                                   form="fuelForm_<?= $ft_id ?>"
                                   name="notes"
                                   style="width:140px;padding:8px;font-size:11px;border:1px solid #cbd5e1;border-radius:4px;"
                                   placeholder="Remarks…"
                                   maxlength="255"
                                   autocomplete="off">
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

            <!-- Submit/Reset Buttons - Bottom Right -->
            <div style="display:flex;justify-content:flex-end;align-items:center;gap:12px;margin-top:16px;padding:0 8px;">
                <button type="button"
                        onclick="resetAllFuelRows()"
                        class="fet-reset-btn">
                    <i class="fas fa-undo"></i> Reset All
                </button>
                <button type="button"
                        onclick="submitAllFuelRows()"
                        class="fet-submit-btn">
                    <i class="fas fa-paper-plane"></i> Submit All Readings
                </button>
            </div>

        </div><!-- /txn-card encodeCard -->

        <?php endif; ?>

        <!-- ── TODAY'S ENTRIES — Meter Reading History ──────────── -->
        <div class="txn-card" id="todayEntriesCard" style="margin-top:8px; margin-bottom:80px; background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);">
            <div class="txn-card-header" style="background:#fff; border-bottom:1.5px solid #e2e8f0; border-top-left-radius:12px; border-top-right-radius:12px; padding: 16px 20px;">
                <i class="fas fa-history" style="color:var(--petron-blue); font-size:18px;"></i>
                <h3 style="font-size:16px; font-weight:700; color:#0f172a; margin:0;">Meter Reading History</h3>
                <span style="margin-left:auto; font-size:12px; color:#64748b; font-weight:500;">
                    Per-Shift Reporting & Validation History
                </span>
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
                    <!-- Date Filter -->
                    <div style="display:flex; flex-direction:column; gap:6px;">
                        <label for="subtab_date" style="font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:0.5px;">
                            <i class="fas fa-calendar-alt" style="color:var(--petron-blue); margin-right:4px;"></i>Filter by Date
                        </label>
                        <input type="date" id="subtab_date" value="<?= date('Y-m-d') ?>" onchange="loadTodayEntries();"
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

                <!-- Export Buttons -->
                <div style="display:flex; flex-direction:column; gap:6px; align-items:flex-start;">
                    <span style="font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:0.5px;">
                        <i class="fas fa-download" style="color:var(--petron-blue); margin-right:4px;"></i>Export Options
                    </span>
                    <div style="display:inline-flex; align-items:center; gap:8px;">
                        <button onclick="exportTodayReadings('excel');" class="txn-btn success">
                            <i class="fas fa-file-excel"></i> Excel
                        </button>
                        <button onclick="exportTodayReadings('csv');" class="txn-btn primary">
                            <i class="fas fa-file-csv"></i> CSV
                        </button>
                    </div>
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
            let selectionStart = input.selectionStart;
            let oldLength = input.value.length;
            let val = input.value;
            
            // Allow numbers, commas, and a single decimal dot
            let clean = val.replace(/[^\d.]/g, '');
            let parts = clean.split('.');
            if (parts.length > 2) {
                parts = [parts[0], parts.slice(1).join('')];
            }
            
            let integerPart = parts[0];
            if (integerPart) {
                // Remove leading zeroes
                if (integerPart.length > 1 && integerPart.startsWith('0')) {
                    integerPart = integerPart.replace(/^0+/, '');
                    if (integerPart === '') integerPart = '0';
                }
                // Add commas
                integerPart = parseInt(integerPart, 10).toLocaleString('en-US');
            }
            
            let formatted = integerPart;
            if (parts.length > 1) {
                formatted += '.' + parts[1];
            }
            
            input.value = formatted || '';
            
            // Restore selection range/cursor offset
            let newLength = input.value.length;
            let delta = newLength - oldLength;
            input.setSelectionRange(selectionStart + delta, selectionStart + delta);
        }

        function formatOnBlur(input) {
            let val = input.value.replace(/,/g, '');
            let num = parseFloat(val);
            if (!isNaN(num)) {
                input.value = num.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            } else {
                input.value = '';
            }
        }

        // ── Live calculation per row (Beginning → Ending → CAL → Price → Volume → Amount) ──────────────────
        function updateFuelCalc(ftId) {
            const beginningEl = document.getElementById(`beginning_${ftId}`);
            const endingEl    = document.getElementById(`ending_${ftId}`);
            const calEl       = document.getElementById(`cal_${ftId}`);
            const priceEl     = document.getElementById(`price_${ftId}`);
            const volumeEl    = document.getElementById(`volume_${ftId}`);
            const volumeValueEl = document.getElementById(`volume_value_${ftId}`);
            const amountEl    = document.getElementById(`amount_${ftId}`);
            const amountValueEl = document.getElementById(`amount_value_${ftId}`);
            
            if (!beginningEl || !endingEl || !calEl || !priceEl || !volumeEl || !amountEl) return;
            
            const beginning = parseFloat(beginningEl.value.replace(/,/g, '')) || 0;
            const ending = parseFloat(endingEl.value.replace(/,/g, '')) || 0;
            const cal = parseFloat(calEl.value.replace(/,/g, '')) || 0;
            const price = parseFloat(priceEl.value) || 0;
            
            // ── Formula: Volume Liters = (Ending - Beginning) - Calibration ──
            const volume = (ending - beginning) - cal;

            // ── Formula: Amount = Volume Liters × Price Per Liter ──
            const amount = volume > 0 ? volume * price : 0;
            
            // Update displays
            volumeEl.value = volume.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
            if (volumeValueEl) volumeValueEl.value = volume.toFixed(2);
            
            amountEl.value = '₱' + amount.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
            if (amountValueEl) amountValueEl.value = amount.toFixed(2);
        }

        // ── AJAX submit per fuel row (Updated for tanker data) ──────────────────────────────────────────
        async function submitFuelCard(event, ftId) {
            event.preventDefault();

            const form      = document.getElementById('fuelForm_' + ftId);
            const submitBtn = document.getElementById('submitBtn_' + ftId);
            const msgEl     = document.getElementById('cardMsg_'   + ftId);

            if (!form) return false;

            // Build FormData from the form
            const formData = new FormData(form);

            // Validate: ending_reading must be filled (strip commas before parsing)
            const endingRaw = (formData.get('ending_reading') || '').replace(/,/g, '');
            const endingVal = parseFloat(endingRaw);
            if (!endingVal || endingVal <= 0) {
                showRowMsg(msgEl, 'error', 'Please enter the Ending meter reading.');
                return false;
            }
            // Inject stripped value back so API receives a plain number
            formData.set('ending_reading', endingRaw);
            const beginningRaw = (formData.get('beginning_reading') || '').replace(/,/g, '');
            formData.set('beginning_reading', beginningRaw);
            const beginningVal = parseFloat(beginningRaw) || 0;

            if (endingVal <= beginningVal) {
                showRowMsg(msgEl, 'error', 'Invalid Reading: Ending meter reading must be greater than Beginning reading.');
                return false;
            }

            const calibrationRaw = (formData.get('calibration') || '0').replace(/,/g, '');
            formData.set('calibration', calibrationRaw);

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
                    showToast('All meter readings for today\'s shift have been recorded.', 'success');

                    // Clear any inline message
                    if (msgEl) { msgEl.style.display = 'none'; msgEl.innerHTML = ''; }

                    // ── Continuous cycle: carryover submitted Ending → next Beginning ──
                    const endingEl    = document.getElementById('ending_'    + ftId);
                    const beginningEl = document.getElementById('beginning_' + ftId);
                    const calEl       = document.getElementById('cal_'       + ftId);
                    const volumeEl    = document.getElementById('volume_'    + ftId);
                    const volumeValEl = document.getElementById('volume_value_' + ftId);
                    const amountEl    = document.getElementById('amount_'    + ftId);
                    const amountValEl = document.getElementById('amount_value_' + ftId);

                    const submittedEnding = parseFloat((endingEl?.value || '0').replace(/,/g, ''));

                    // Update beginning to the ending we just submitted (with comma formatting)
                    if (beginningEl && submittedEnding > 0) {
                        const fmtEnding = submittedEnding.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
                        beginningEl.value = fmtEnding;
                        beginningEl.placeholder = fmtEnding;
                        beginningEl.style.background = '#f0f9ff';
                        beginningEl.style.fontWeight  = '600';
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
                showRowMsg(msgEl, 'error', 'Request failed: ' + (err.message || 'Unknown error'));
            }

            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit';
            return false;
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

        // ── Reset a single row ────────────────────────────────────────────────
        function resetFuelRow(ftId) {
            const msgEl         = document.getElementById('cardMsg_' + ftId);
            const notesEl       = document.querySelector(`#fuelForm_${ftId} [name="notes"]`);
            const beginningEl   = document.getElementById(`beginning_${ftId}`);
            const endingEl      = document.getElementById(`ending_${ftId}`);
            const calEl         = document.getElementById(`cal_${ftId}`);
            const volumeEl      = document.getElementById(`volume_${ftId}`);
            const volumeValueEl = document.getElementById(`volume_value_${ftId}`);
            const amountEl      = document.getElementById(`amount_${ftId}`);
            const amountValueEl = document.getElementById(`amount_value_${ftId}`);
            
            // Restore beginning to its carryover value (previous shift Ending), not blank
            if (beginningEl) beginningEl.value = beginningEl.placeholder !== '0.00' ? beginningEl.placeholder : '';
            if (endingEl) endingEl.value = '';
            if (calEl) calEl.value = '0.00';
            if (volumeEl) volumeEl.value = '0.00';
            if (volumeValueEl) volumeValueEl.value = '0.00';
            if (amountEl) amountEl.value = '₱0.00';
            if (amountValueEl) amountValueEl.value = '0.00';
            if (notesEl) notesEl.value = '';
            if (msgEl) showRowMsg(msgEl, '', '');
        }


        // ── Reset ALL fuel rows ────────────────────────────────────────────────
        function resetAllFuelRows() {
            if (!confirm('Reset all fuel readings? This will clear all entered data.')) return;
            
            // Find all forms that start with "fuelForm_"
            const allForms = document.querySelectorAll('form[id^="fuelForm_"]');
            allForms.forEach(form => {
                const ftId = form.id.replace('fuelForm_', '');
                resetFuelRow(ftId);
            });

            showToast('All fuel readings have been reset.', 'info');
        }

        // ── Submit ALL fuel rows with data ─────────────────────────────────────
        async function submitAllFuelRows() {
            const allForms = document.querySelectorAll('form[id^="fuelForm_"]');
            const formsToSubmit = [];

            let validationFailed = false;
            // Collect forms that have ending reading (required field)
            allForms.forEach(form => {
                const ftId = form.id.replace('fuelForm_', '');
                const endingEl = document.getElementById(`ending_${ftId}`);
                const endingValue = parseFloat((endingEl?.value || '0').replace(/,/g, ''));
                if (endingValue > 0) {
                    const beginningEl = document.getElementById(`beginning_${ftId}`);
                    const beginningValue = parseFloat((beginningEl?.value || '0').replace(/,/g, ''));
                    if (endingValue <= beginningValue) {
                        showToast(`Invalid Reading for ${ftId.replace(/_/g, ' ').toUpperCase()}: Ending must be greater than Beginning.`, 'error');
                        const msgEl = document.getElementById('cardMsg_' + ftId);
                        if (msgEl) showRowMsg(msgEl, 'error', 'Invalid Reading: Ending must be greater than Beginning.');
                        validationFailed = true;
                    }
                    formsToSubmit.push({ ftId, form });
                }
            });

            if (validationFailed) return;

            if (formsToSubmit.length === 0) {
                showToast('No fuel readings to submit. Please enter at least one ending reading.', 'warning');
                return;
            }

            // Custom confirm instead of browser confirm()
            const confirmed = await showConfirm(`Submit ${formsToSubmit.length} fuel reading(s) for manager validation?`);
            if (!confirmed) return;

            let successCount = 0;
            let errorCount = 0;
            const errors = [];

            // Submit each form
            for (const {ftId, form} of formsToSubmit) {
                try {
                    const formData = new FormData(form);
                    // Strip commas from text inputs before sending to API
                    formData.set('ending_reading',   (formData.get('ending_reading')   || '').replace(/,/g, ''));
                    formData.set('beginning_reading', (formData.get('beginning_reading') || '').replace(/,/g, ''));
                    formData.set('calibration',       (formData.get('calibration')       || '0').replace(/,/g, ''));
                    const response = await fetch('api_fuel_readings.php', {
                        method: 'POST',
                        body: formData
                    });

                    let result;
                    try { result = await response.json(); }
                    catch(e) { result = {success:false, message:'Invalid server response.'}; }

                    if (result.success) {
                        successCount++;
                        // ── Continuous cycle: carryover Ending → Beginning ──
                        const endingEl    = document.getElementById(`ending_${ftId}`);
                        const beginningEl = document.getElementById(`beginning_${ftId}`);
                        const calEl       = document.getElementById(`cal_${ftId}`);
                        const volumeEl    = document.getElementById(`volume_${ftId}`);
                        const volumeValEl = document.getElementById(`volume_value_${ftId}`);
                        const amountEl    = document.getElementById(`amount_${ftId}`);
                        const amountValEl = document.getElementById(`amount_value_${ftId}`);
                        const notesEl     = document.querySelector(`#fuelForm_${ftId} [name="notes"]`);
                        const submittedEnding = parseFloat((endingEl?.value || '0').replace(/,/g, ''));
                        if (beginningEl && submittedEnding > 0) {
                            const fmtEnding = submittedEnding.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
                            beginningEl.value = fmtEnding;
                            beginningEl.placeholder = fmtEnding;
                            beginningEl.style.background = '#f0f9ff';
                            beginningEl.style.fontWeight  = '600';
                        }
                        if (endingEl)    endingEl.value    = '';
                        if (calEl)       calEl.value       = '0.00';
                        if (volumeEl)    volumeEl.value    = '0.00';
                        if (volumeValEl) volumeValEl.value = '0.00';
                        if (amountEl)    amountEl.value    = '₱0.00';
                        if (amountValEl) amountValEl.value = '0.00';
                        if (notesEl)     notesEl.value     = '';
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
            if (errorCount === 0) {
                showToast('✔ All meter readings for today\'s shift have been recorded.', 'success');
                // Redirect to manager validation after short delay
                setTimeout(() => {
                    window.location.href = 'manager_fuel_transaction_validation.php';
                }, 2800);
            } else {
                showToast(
                    `${successCount} reading(s) submitted.` +
                    (errorCount > 0 ? ` ${errorCount} failed — check individual rows.` : ''),
                    errorCount === successCount ? 'error' : 'warning'
                );
            }
        }

        // ── Generic toast helper ────────────────────────────────────────────────
        function showToast(msg, type = 'success') {
            const colors = {
                success: { bg:'#d4edda', color:'#155724', border:'#c3e6cb', icon:'fa-check-circle', iconColor:'#28a745' },
                error:   { bg:'#f8d7da', color:'#721c24', border:'#f5c6cb', icon:'fa-times-circle',  iconColor:'#dc3545' },
                warning: { bg:'#fff3cd', color:'#856404', border:'#ffeeba', icon:'fa-exclamation-triangle', iconColor:'#ffc107' },
                info:    { bg:'#d1ecf1', color:'#0c5460', border:'#bee5eb', icon:'fa-info-circle',   iconColor:'#17a2b8' },
            };
            const c = colors[type] || colors.success;
            const toast = document.createElement('div');
            toast.style.cssText = `position:fixed;top:80px;left:50%;transform:translateX(-50%) scale(0.95);` +
                `background:${c.bg};color:${c.color};border:1.5px solid ${c.border};` +
                `padding:14px 28px;border-radius:12px;font-weight:600;z-index:99999;` +
                `box-shadow:0 6px 24px rgba(0,0,0,.15);transition:opacity .35s ease, transform .25s ease;` +
                `font-size:14px;text-align:center;max-width:480px;line-height:1.5;opacity:0;`;
            toast.innerHTML = `<i class="fas ${c.icon}" style="color:${c.iconColor};margin-right:8px;"></i>${msg}`;
            document.body.appendChild(toast);
            // Animate in
            requestAnimationFrame(() => {
                toast.style.opacity = '1';
                toast.style.transform = 'translateX(-50%) scale(1)';
            });
            setTimeout(() => {
                toast.style.opacity = '0';
                setTimeout(() => toast.remove(), 400);
            }, type === 'error' ? 6000 : 3500);
        }

        // ── Custom confirm modal (replaces browser confirm()) ───────────────────
        function showConfirm(msg) {
            return new Promise(resolve => {
                const overlay = document.createElement('div');
                overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:99998;display:flex;align-items:center;justify-content:center;';
                overlay.innerHTML = `
                    <div style="background:#fff;border-radius:14px;padding:28px 32px;max-width:420px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,.2);text-align:center;">
                        <div style="font-size:22px;color:#002F6C;margin-bottom:12px;"><i class="fas fa-question-circle"></i></div>
                        <p style="font-size:15px;font-weight:600;color:#1e293b;margin:0 0 20px;">${msg}</p>
                        <div style="display:flex;gap:12px;justify-content:center;">
                            <button id="confirmNo"  style="padding:7px 20px;border-radius:4px;border:1px solid #64748b;background:white;color:#64748b;font-weight:600;cursor:pointer;font-size:11px;transition:all .2s;" onmouseover="this.style.background='#64748b';this.style.color='white'" onmouseout="this.style.background='white';this.style.color='#64748b'">Cancel</button>
                            <button id="confirmYes" style="padding:7px 20px;border-radius:4px;border:1px solid #002F6C;background:white;color:#002F6C;font-weight:600;cursor:pointer;font-size:11px;transition:all .2s;" onmouseover="this.style.background='#002F6C';this.style.color='white'" onmouseout="this.style.background='white';this.style.color='#002F6C'">Submit</button>
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
                // Read local sub-tab date and shift filter values
                const dateVal  = document.getElementById('subtab_date')?.value || '<?= date('Y-m-d') ?>';
                const shiftVal = document.getElementById('subtab_shift')?.value || '';

                const params = new URLSearchParams({ 
                    action: 'summary', 
                    date_from: dateVal, 
                    date_to: dateVal, 
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
        
        function renderTodayEntriesTable() {
            const body = document.getElementById('todayEntriesBody');
            const rows = window.todayEntriesData || [];
            
            const totalRows = rows.length;
            const totalPages = Math.max(1, Math.ceil(totalRows / window.todayEntriesPageSize));
            if (window.todayEntriesPage > totalPages) window.todayEntriesPage = totalPages;
            
            const startIdx = (window.todayEntriesPage - 1) * window.todayEntriesPageSize;
            const endIdx = Math.min(startIdx + window.todayEntriesPageSize, totalRows);
            const pageRows = rows.slice(startIdx, endIdx);

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
                return `<span style="background:${c.color}15; color:${c.color}; border:1px solid ${c.color}30; font-weight:700; font-size:11px; padding:4px 8px; border-radius:6px; text-transform:uppercase; letter-spacing:.5px; white-space:nowrap;">${c.label}</span>`;
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

            const TH  = 'padding:8px 10px; font-size:13px; font-weight:700; color:#ffffff; text-transform:uppercase; letter-spacing:.5px; white-space:nowrap;';
            const THR = TH + ' text-align:right;';

            let html = `<div style="overflow-x:auto; border-bottom:1px solid #e2e8f0; background:#ffffff;">
                <table id="todayReadingsTable" style="width:100%; border-collapse:collapse; font-size:13px; text-align:left; table-layout:auto;">
                    <thead>
                        <tr style="background:#002F70; border-bottom:2px solid #001f4d;">
                            <th style="${TH}">Date</th>
                            <th style="${TH}">Shift</th>
                            <th style="${TH}">Pump / Fuel Type</th>
                            <th style="${THR}">Beginning</th>
                            <th style="${THR}">Ending</th>
                            <th style="${THR}">Calibration</th>
                            <th style="${THR}">Volume (L)</th>
                            <th style="${THR}">Price/L</th>
                            <th style="${THR}">Amount</th>
                            <th style="${TH}">Encoded By</th>
                            <th style="${TH}">Status</th>
                            <th style="${TH}">Notes</th>
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
                    notesCellContent = `<div style="line-height:1.2; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${escapeHtml(tooltipText)}"><strong>S:</strong> ${escapeHtml(staffNotes)}<br><strong>M:</strong> ${escapeHtml(mgrNotes)}</div>`;
                } else if (staffNotes) {
                    notesCellContent = `<div style="line-height:1.2; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${escapeHtml(tooltipText)}">${escapeHtml(staffNotes)}</div>`;
                } else if (mgrNotes) {
                    notesCellContent = `<div style="line-height:1.2; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#002F70;" title="${escapeHtml(tooltipText)}"><strong>M:</strong> ${escapeHtml(mgrNotes)}</div>`;
                }

                const dateStr = fmtDate(r.reading_date || r.transaction_date);
                const shiftStr = fmtShift(r.shift_period, r.shift_name);
                const fuelStr = r.fuel_type || '—';
                const staffStr = r.staff_name || '—';

                html += `<tr style="border-bottom:1px solid #f1f5f9; background:#ffffff; transition: background-color 0.15s ease;" onmouseover="this.style.backgroundColor='#f0f5ff';" onmouseout="this.style.backgroundColor='#ffffff';">
                    <td style="padding:10px; color:#1e293b; font-size:13px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; vertical-align:middle;" title="${dateStr}">${dateStr}</td>
                    <td style="padding:10px; color:#334155; font-size:13px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; vertical-align:middle;" title="${shiftStr}">${shiftStr}</td>
                    <td style="padding:10px; font-weight:700; color:#0f172a; font-size:13px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; vertical-align:middle;" title="${fuelStr}">${fuelStr}</td>
                    <td style="padding:10px; text-align:right; font-variant-numeric:tabular-nums; color:#1e293b; font-size:13px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; vertical-align:middle;" title="${fmt(r.beginning)}">${fmt(r.beginning)}</td>
                    <td style="padding:10px; text-align:right; font-variant-numeric:tabular-nums; color:#1e293b; font-weight:600; font-size:13px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; vertical-align:middle;" title="${fmt(r.ending)}">${fmt(r.ending)}</td>
                    <td style="padding:10px; text-align:right; font-variant-numeric:tabular-nums; color:#334155; font-size:13px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; vertical-align:middle;" title="${fmt(r.cal,3)}">${fmt(r.cal,3)}</td>
                    <td style="padding:10px; text-align:right; font-weight:700; font-variant-numeric:tabular-nums; color:#1e293b; font-size:13px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; vertical-align:middle;" title="${fmt(r.volume_liters)} L">${fmt(r.volume_liters)} L</td>
                    <td style="padding:10px; text-align:right; font-variant-numeric:tabular-nums; color:#334155; font-size:13px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; vertical-align:middle;" title="₱${fmt(r.price_per_liter)}">₱${fmt(r.price_per_liter)}</td>
                    <td style="padding:10px; text-align:right; font-weight:800; font-variant-numeric:tabular-nums; color:#0f172a; font-size:13px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; vertical-align:middle;" title="₱${fmt(r.amount)}">₱${fmt(r.amount)}</td>
                    <td style="padding:10px; color:#334155; font-weight:500; font-size:13px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; vertical-align:middle;" title="${staffStr}">${staffStr}</td>
                    <td style="padding:10px; font-size:13px; vertical-align:middle;">${badge(r.status)}</td>
                    <td style="padding:10px; color:#475569; font-size:13px; max-width:160px; overflow:hidden; vertical-align:middle;">${notesCellContent}</td>
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
            
            // Pagination Footer
            html += `
            <div style="display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-top:1px solid #e2e8f0; background:#ffffff; border-radius:0 0 12px 12px; font-size:13px; color:#475569; flex-wrap:wrap; gap:12px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <label style="margin:0; font-weight:500; color:#475569;">Rows per page:</label>
                    <select onchange="window.todayEntriesPageSize=parseInt(this.value); window.todayEntriesPage=1; renderTodayEntriesTable();" style="padding:5px 24px 5px 8px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:13px; background:#fff; color:#1e293b; outline:none; cursor:pointer;">
                        <option value="10" ${window.todayEntriesPageSize === 10 ? 'selected' : ''}>10</option>
                        <option value="20" ${window.todayEntriesPageSize === 20 ? 'selected' : ''}>20</option>
                        <option value="30" ${window.todayEntriesPageSize === 30 ? 'selected' : ''}>30</option>
                        <option value="40" ${window.todayEntriesPageSize === 40 ? 'selected' : ''}>40</option>
                        <option value="50" ${window.todayEntriesPageSize === 50 ? 'selected' : ''}>50</option>
                    </select>
                </div>
                <div style="display:flex; align-items:center; gap:10px;">
                    <button onclick="if(window.todayEntriesPage>1){ window.todayEntriesPage--; renderTodayEntriesTable(); }" 
                            style="width:32px; height:32px; background:#fff; border:1.5px solid #cbd5e1; border-radius:6px; cursor:${window.todayEntriesPage > 1 ? 'pointer' : 'not-allowed'}; color:${window.todayEntriesPage > 1 ? '#475569' : '#cbd5e1'}; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                            onmouseover="if(window.todayEntriesPage>1) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <span style="color:#334155; font-size:13px; font-weight:600; padding:0 4px;">Page ${window.todayEntriesPage} of ${totalPages}</span>
                    <button onclick="if(window.todayEntriesPage<${totalPages}){ window.todayEntriesPage++; renderTodayEntriesTable(); }" 
                            style="width:32px; height:32px; background:#fff; border:1.5px solid #cbd5e1; border-radius:6px; cursor:${window.todayEntriesPage < totalPages ? 'pointer' : 'not-allowed'}; color:${window.todayEntriesPage < totalPages ? '#475569' : '#cbd5e1'}; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                            onmouseover="if(window.todayEntriesPage<${totalPages}) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            </div>`;
            
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
            const dateVal = document.getElementById('subtab_date')?.value || '<?= date('Y-m-d') ?>';
            const shiftVal = document.getElementById('subtab_shift')?.value || 'all_shifts';
            const filename = `meter_readings_history_${dateVal}_${shiftVal}`;
            const title = `Petron Fuel Meter Readings History Report (${dateVal} - Shift: ${shiftVal.toUpperCase()})`;
            
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
                    <p id="txnSectionDesc" style="font-size:14px;color:#666666;margin:3px 0 0;text-transform:uppercase;letter-spacing:0.3px;font-weight:500;"><?= $active_tab === 'tracker' ? 'Monitor service progress and pending balances in real time.' : 'Merchandise sales, job order encoding, and status tracking.' ?></p>
                </div>
            </div>
            <div style="display:flex;gap:8px;align-items:center;">
                <span class="status-badge customer" style="display:none;"></span>
                <button type="button" onclick="window.location.href='staff_transactions_hub.php?section=merchandise&amp;active_tab=merchandise'" 
                        class="txn-btn secondary" title="Back to Merchandise/Service Transaction">
                    <i class="fas fa-arrow-left"></i> <span>Back</span>
                </button>
            </div>
        </div>

        <!-- ── Inner Tabs ─────────────────────────────────────────────── -->
        <div style="display:flex;gap:10px;margin-bottom:24px;flex-wrap:wrap;">
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
                <span style="background:<?= $ia ? '#ffffff' : $tc['color'] ?>;color:<?= $ia ? $tc['color'] : '#fff' ?>;font-size:10px;font-weight:800;
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
        <div class="cart-wrapper" style="display:grid; grid-template-columns:<?= !empty($_GET['mh_open']) ? '1fr' : '1fr 340px' ?>; gap:16px; align-items:start;">

            <!-- Left: Job Order section (top) + Merchandise section (bottom) + Customer/Payment -->
            <div style="min-width:0;overflow:visible;">

                <!-- ══ JOB ORDER SECTION (TOP) ══════════════════════════════ -->
                <div class="txn-card" id="joCard" style="overflow:visible;position:relative;z-index:10;">
                    <div class="txn-card-header" style="background:#fffbeb;">
                        <i class="fas fa-tools" style="color:#b45309;"></i>
                        <h3 style="color:#92400e;">Job Order</h3>
                    </div>
                    <div class="txn-card-body" style="overflow:visible;">

                        <!-- Customer Details — captured here at the Job Order level -->
                        <div style="font-size:11px;font-weight:700;color:#b45309;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">
                            <i class="fas fa-user" style="margin-right:5px;"></i>Customer Details
                        </div>
                        <div class="txn-form-grid" style="margin-bottom:14px;">
                            <div class="txn-field">
                                <label>First Name <span style="color:#dc2626;">*</span></label>
                                <input type="text" 
                                       id="joFirstName" 
                                       class="txn-input"
                                       list="joFirstNameList"
                                       placeholder="Customer first name"
                                       autocomplete="off"
                                       oninput="onCustomerNameInput('jo')">
                                <datalist id="joFirstNameList">
                                    <?php foreach ($customer_first_names as $firstName): ?>
                                        <option value="<?= htmlspecialchars($firstName) ?>">
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                            <div class="txn-field">
                                <label>Last Name</label>
                                <input type="text" 
                                       id="joLastName" 
                                       class="txn-input"
                                       placeholder="Auto-filled from registered customer"
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
                                    <div style="flex:1;position:relative;z-index:100;">
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
                                                    z-index:9999;box-shadow:0 8px 24px rgba(0,0,0,.12);">
                                        </div>
                                    </div>
                                    <button type="button"
                                            onclick="openAddVehicleModal()"
                                            title="Add a new vehicle type"
                                            class="txn-icon-btn blue">
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
                                <input type="text" id="joVehicleBrand" class="txn-input"
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

                        <!-- Service Details -->
                        <div style="font-size:11px;font-weight:700;color:#b45309;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">
                            <i class="fas fa-wrench" style="margin-right:5px;"></i>Service Details
                        </div>

                        <!-- Service Category -->
                        <div class="txn-form-grid" style="margin-bottom:14px;">
                            <div class="txn-field">
                                <label>Service Category</label>
                                <select id="joServiceCategory" class="txn-select" onchange="onServiceCategoryChange()">
                                    <option value="">-- Select Category --</option>
                                    <option value="Oil Change">Oil Change</option>
                                    <option value="Wheel Alignment">Wheel Alignment</option>
                                    <option value="Battery Services">Battery Services</option>
                                    <option value="Brake Services">Brake Services</option>
                                    <option value="Tire Services">Tire Services</option>
                                    <option value="Engine Services">Engine Services</option>
                                    <option value="AC Services">AC Services</option>
                                    <option value="General Inspection">General Inspection</option>
                                    <option value="Others">Others</option>
                                </select>
                            </div>
                        </div>

                        <div class="txn-form-grid" style="margin-bottom:14px;">
                            <div class="txn-field">
                                <label>Service Type <span style="color:#dc2626;">*</span></label>
                                <div style="display:flex;gap:6px;align-items:flex-start;">
                                    <!-- Filterable Service Type Input -->
                                    <div style="flex:1;position:relative;">
                                        <input type="text" 
                                               id="joServiceType" 
                                               class="txn-select" 
                                               placeholder="Type to search service..."
                                               autocomplete="off"
                                               style="width:100%;padding-right:30px;"
                                               oninput="filterServiceTypes()"
                                               onfocus="showServiceDropdown()"
                                               onblur="setTimeout(() => hideServiceDropdown(), 200)">
                                        <input type="hidden" id="joServiceTypeValue" value="">
                                        <i class="fas fa-chevron-down" 
                                           style="position:absolute;right:10px;top:50%;transform:translateY(-50%);
                                                  color:#94a3b8;font-size:12px;pointer-events:none;"></i>
                                        <!-- Dropdown list -->
                                        <div id="joServiceTypeDropdown" 
                                             style="display:none;position:absolute;top:100%;left:0;right:0;
                                                    background:white;border:1px solid #e2e8f0;border-radius:6px;
                                                    box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);
                                                    max-height:240px;overflow-y:auto;z-index:1000;margin-top:2px;">
                                            <div id="joServiceTypeList"></div>
                                        </div>
                                    </div>
                                     <button type="button"
                                             onclick="addServiceFromFormToCart()"
                                             title="Add Job Order Service to Cart"
                                             class="txn-icon-btn blue">
                                         <i class="fas fa-cart-plus"></i>
                                     </button>
                                     <button type="button"
                                             onclick="openAddServiceModal()"
                                             title="Add a new service type"
                                             class="txn-icon-btn blue">
                                         +
                                     </button>













                                </div>
                                <!-- Pricing notes (shown when service type selected) -->
                                <div id="joServicePriceNotes" style="display:none;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;margin-top:8px;font-size:12px;color:#92400e;">
                                    <i class="fas fa-lightbulb" style="margin-right:6px;"></i>
                                    <span id="joServicePriceNotesText"></span>
                                </div>
                            </div>
                            <div class="txn-field">
                                <label>Service Fee</label>
                                <input type="number" id="joServicePrice" class="txn-input auto-pull"
                                       step="0.01" min="0" placeholder="—"
                                       oninput="onJoServicePriceInput()">
                            </div>
                        </div>

                        <!-- Assigned Mechanic + Notes -->
                        <div class="txn-form-grid" style="margin-bottom:6px;">
                            <div class="txn-field">
                                <label>Assigned Mechanic</label>
                                <select id="joMechanic" class="txn-select" onchange="onMechanicChange()">
                                    <option value="">Select mechanic…</option>
                                    <?php foreach ($mechanics as $mech): ?>
                                    <option value="<?= (int)$mech['id'] ?>"
                                            data-name="<?= htmlspecialchars($mech['full_name']) ?>">
                                        <?= htmlspecialchars($mech['full_name']) ?>
                                        <?php if (!empty($mech['specialization'])): ?>
                                        (<?= htmlspecialchars($mech['specialization']) ?>)
                                        <?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                    <?php if (empty($mechanics)): ?>
                                    <option disabled>No mechanics on record</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="txn-field">
                                <label>Notes</label>
                                <input type="text" id="joNotes" class="txn-input"
                                       placeholder="Any additional remarks…"
                                       autocomplete="off">
                            </div>
                        </div>

                        <!-- Add Service to Cart button -->
                        <div style="display:flex;gap:10px;margin-top:14px;justify-content:flex-end;">
                            <button type="button" class="txn-btn success" onclick="addServiceFromFormToCart()" id="joAddServiceBtn">
                                <i class="fas fa-plus"></i> Add Service
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

                    </div>
                </div><!-- /joCard -->

                <!-- ══ MERCHANDISE SECTION (BOTTOM) ════════════════════════ -->
                <div class="txn-card">
                    <div class="txn-card-header">
                        <i class="fas fa-shopping-cart" style="color:#28a745;"></i>
                        <h3>Merchandise</h3>
                    </div>

                    <!-- ── Merchandise sub-tabs ─────────────────────────────── -->
                    <div style="display:flex;gap:10px;padding:0 16px;margin-bottom:12px;margin-top:12px;">
                        <?php $mh_open = isset($_GET['mh_open']) && $_GET['mh_open'] == '1'; ?>
                        <button onclick="switchMerchTab('form')" id="merchTabBtn_form"
                                class="txn-subtab-btn green <?= !$mh_open ? 'active' : 'inactive' ?>">


                            <i class="fas fa-shopping-cart"></i> Merchandise
                        </button>
                        <button onclick="switchMerchTab('history')" id="merchTabBtn_history"
                                class="txn-subtab-btn green <?= $mh_open ? 'active' : 'inactive' ?>">


                            <i class="fas fa-history"></i> Merchandise History
                        </button>
                    </div>

                    <!-- ── Sub-tab: Form ─────────────────────────────────────── -->
                    <div id="merchTab_form">
                    <div class="txn-card-body">

                        <!-- Customer Details — captured here at the Merchandise level -->
                        <div style="font-size:11px;font-weight:700;color:#28a745;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">
                            <i class="fas fa-user" style="margin-right:5px;"></i>Customer Details
                        </div>
                        <div class="txn-form-grid" style="margin-bottom:14px;">
                            <div class="txn-field">
                                <label>First Name</label>
                                <input type="text" 
                                       id="merchFirstName" 
                                       class="txn-input"
                                       list="merchFirstNameList"
                                       placeholder="Walk-in Customer"
                                       autocomplete="off"
                                       oninput="onCustomerNameInput('merch')">
                                <datalist id="merchFirstNameList">
                                    <?php foreach ($customer_first_names as $firstName): ?>
                                        <option value="<?= htmlspecialchars($firstName) ?>">
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                            <div class="txn-field">
                                <label>Last Name</label>
                                <input type="text" 
                                       id="merchLastName" 
                                       class="txn-input"
                                       placeholder="Auto-filled from registered customer"
                                       autocomplete="off">
                            </div>
                        </div>

                        <!-- Contact Number for walk-in merch customers -->
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
                                        <!-- Add Selected to Cart -->
                                        <button type="button" onclick="addProductFromFormToCart()" title="Add selected product to cart" 
                                                class="txn-icon-btn green">
                                            <i class="fas fa-cart-plus"></i>
                                        </button>
                                        <!-- Register brand new product -->
                                        <button type="button" onclick="openAddProductModal()" title="Add new product to database" 
                                                class="txn-icon-btn green">+</button>
                                    </div>
                                    <!-- Dropdown list -->
                                    <div id="productDropdownList"
                                         style="display:none;position:absolute;top:100%;left:0;right:0;
                                                background:#fff;border:1.5px solid #e2e8f0;border-top:none;
                                                border-radius:0 0 8px 8px;box-shadow:0 6px 20px rgba(0,0,0,.1);
                                                z-index:999;max-height:260px;overflow-y:auto;">
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
                                                    color:#94a3b8;text-transform:uppercase;letter-spacing:.6px;
                                                    background:#f8fafc;border-top:1px solid #f1f5f9;">
                                            <?= htmlspecialchars($p['category']) ?>
                                        </div>
                                        <?php $last_cat = $p['category']; endif; ?>
                                        <div class="prod-option"
                                             data-id="<?= (int)$p['product_id'] ?>"
                                             data-name="<?= htmlspecialchars($p['product_name']) ?>"
                                             data-sku="<?= htmlspecialchars($p['sku']) ?>"
                                             data-cat="<?= htmlspecialchars($p['category']) ?>"
                                             data-size="<?= htmlspecialchars($p['size']) ?>"
                                             data-price="<?= (float)$p['unit_price'] ?>"
                                             data-stock="<?= (int)$p['stock_level'] ?>"
                                             data-search="<?= strtolower(htmlspecialchars($p['product_name'].' '.$p['sku'].' '.$p['category'].' '.$p['size'])) ?>"
                                             onclick="selectProduct(this)"
                                             style="padding:8px 14px;cursor:pointer;
                                                    display:flex;align-items:center;
                                                    border-bottom:1px solid #f8fafc;gap:8px;
                                                    justify-content:space-between;
                                                    <?= $out_of_stock ? 'opacity:.6;' : '' ?>">
                                            <!-- Product name + SKU only -->
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
                                        </div>
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
                                            data-stock="<?= (int)$p['stock_level'] ?>">
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
                                <label>Category</label>
                                <input type="text" id="itemCategory" class="txn-input auto-pull" readonly placeholder="—">
                            </div>
                            <div class="txn-field">
                                <label>Unit Price</label>
                                <input type="number" id="itemUnitPrice" class="txn-input auto-pull"
                                       step="0.01" readonly placeholder="—">
                            </div>
                        </div>

                        <div class="txn-form-grid" style="margin-top:14px;">
                            <div class="txn-field">
                                <label>Stock Available</label>
                                <input type="text" id="itemStock" class="txn-input readonly-field" readonly placeholder="—">
                            </div>
                        </div>

                        <div style="display:flex;gap:10px;margin-top:18px;justify-content:flex-end;">
                            <button type="button" class="txn-btn secondary" onclick="resetAll()" title="Reset all fields and clear cart">
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
                                <button type="submit" class="txn-btn primary">
                                    <i class="fas fa-filter"></i> Filter
                                </button>
                                <a href="staff_transactions_hub.php?section=merchandise&mh_open=1" class="txn-btn secondary">
                                    <i class="fas fa-times"></i> Clear
                                </a>
                                <!-- Export buttons -->
                                <div style="margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                    <a href="../backend/export_staff_transactions.php?type=merchandise&format=excel"
                                       title="Export to Excel"
                                       class="txn-btn success">
                                        <i class="fas fa-file-excel"></i> Excel
                                    </a>
                                    <a href="../backend/export_staff_transactions.php?type=merchandise&format=csv"
                                       title="Export to CSV"
                                       class="txn-btn primary">
                                        <i class="fas fa-file-csv"></i> CSV
                                    </a>
                                    <a href="staff_transactions_hub.php?section=merchandise&active_tab=merchandise"
                                       title="Back to Merchandise Form"
                                       class="txn-btn secondary">
                                        <i class="fas fa-arrow-left"></i> Back
                                    </a>
                                </div>
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
                            <div style="width:100%;overflow-x:auto;">
                            <style>
                            #mhHistoryTable th { padding: 8px 10px; }
                            #mhHistoryTable td { padding: 8px 10px; }
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
                                            ₱<?= number_format($mh_kpi_total_encoded, 2) ?>
                                        </div>
                                        <div style="font-size:10px;font-weight:600;color:#64748b;
                                                    text-transform:uppercase;letter-spacing:.5px;margin-top:4px;">
                                            <i class="fas fa-peso-sign" style="color:#003d7a;margin-right:3px;"></i>
                                            Total Encoded
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <table class="txn-table" id="mhHistoryTable" style="width:100%;min-width:1000px;">
                                <colgroup>
                                    <col style="min-width:180px;">
                                    <col style="min-width:180px;">
                                    <col style="min-width:200px;">
                                    <col style="min-width:100px;">
                                    <col style="min-width:120px;">
                                    <col style="min-width:120px;">
                                    <col style="min-width:160px;">
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th style="font-size:12px;text-align:left;padding:10px;">Transaction ID</th>
                                        <th style="font-size:12px;text-align:left;padding:10px;">Customer</th>
                                        <th style="font-size:12px;text-align:left;padding:10px;">Product</th>
                                        <th style="font-size:12px;text-align:center;padding:10px;">Quantity</th>
                                        <th style="font-size:12px;text-align:right;padding:10px;">Unit Price</th>
                                        <th style="font-size:12px;text-align:right;padding:10px;">Total Amount</th>
                                        <th style="font-size:12px;text-align:left;padding:10px;">Date Released</th>
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
                                ?>
                                <tr class="mh-row" style="cursor:pointer;" onclick="viewMerchandiseDetails('<?= addslashes($txn['transaction_id'] ?? '') ?>')" title="Click to view full transaction details">
                                    <td style="font-size:12px;font-weight:700;color:var(--petron-blue);padding:10px;white-space:nowrap;">
                                        <?= htmlspecialchars($txn['transaction_id'] ?? ('#'.$txn['mt_id'])) ?>
                                    </td>
                                    <td style="font-size:12px;padding:10px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                        title="<?= htmlspecialchars($txn['customer_name'] ?? '') ?>">
                                        <?= htmlspecialchars($txn['customer_name'] ?? 'Walk-in Customer') ?>
                                    </td>
                                    <td style="font-size:12px;padding:10px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                        title="<?= htmlspecialchars($txn['product_name'] ?? '') ?>">
                                        <?= htmlspecialchars($txn['product_name'] ?? '—') ?>
                                    </td>
                                    <td style="font-size:12px;text-align:center;font-weight:600;color:#475569;padding:10px;">
                                        <?= $qty_display ?>
                                    </td>
                                    <td style="font-size:12px;text-align:right;font-weight:600;color:#475569;padding:10px;white-space:nowrap;">
                                        ₱<?= number_format($unit_price, 2) ?>
                                    </td>
                                    <td style="font-size:12px;text-align:right;font-weight:700;color:var(--petron-blue);padding:10px;white-space:nowrap;">
                                        ₱<?= number_format($item_total, 2) ?>
                                    </td>
                                    <td style="font-size:11px;color:#64748b;padding:10px;white-space:nowrap;">
                                        <?= $date_released ?>
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
                        var mhOpen = <?= (!empty($_GET['mh_open'])) ? 'true' : 'false' ?>;

                        // ── Merchandise History pagination ────────────────────
                        var mhState = { page: 1, per_page: 10 };

                        function mhRender() {
                            var rows = document.querySelectorAll('#mhTableBody .mh-row');
                            var total = rows.length;
                            var perPage = mhState.per_page;
                            var page = mhState.page;
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

                            // When history is active: expand left column to full width, hide cart panel
                            // When form is active: restore the 2-column grid with cart panel
                            var cartWrapper = document.querySelector('.cart-wrapper');
                            var cartPanel   = document.querySelector('.cart-panel');
                            if (cartWrapper && cartPanel) {
                                if (isHistory) {
                                    cartWrapper.style.gridTemplateColumns = '1fr';
                                    cartPanel.style.display = 'none';
                                } else {
                                    cartWrapper.style.gridTemplateColumns = '1fr 340px';
                                    cartPanel.style.display = 'flex';
                                }
                            }

                            // Tab button styles — use CSS classes to override global button rule
                            formBtn.className = 'txn-subtab-btn green ' + (isHistory ? 'inactive' : 'active');
                            histBtn.className = 'txn-subtab-btn green ' + (isHistory ? 'active' : 'inactive');













                            // Update URL so refresh keeps the tab open
                            if (window.history && window.history.replaceState) {
                                var url = new URL(window.location.href);
                                if (isHistory) {
                                    url.searchParams.set('mh_open', '1');
                                } else {
                                    url.searchParams.delete('mh_open');
                                }
                                window.history.replaceState(null, '', url);
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

            <!-- Payment + Cart panel — full width below the form -->
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
                        <label style="font-size:10px;">Payment Method <span style="color:#dc2626;">*</span></label>
                        <select id="paymentMethod" class="txn-select" style="font-size:12px;padding:7px 10px;" onchange="onPaymentChange()" required>
                            <option value="">Select payment…</option>
                            <option value="Cash">Cash</option>
                            <option value="Card">Card</option>
                            <option value="E-Wallet">E-Wallet</option>
                            <option value="Petron E-Fuel">Petron E-Fuel</option>
                            <option value="Fleet Card">Fleet Card</option>
                            <option value="Credit">Credit</option>
                        </select>
                    </div>

                    <!-- Cash fields -->
                    <div id="cashFields" style="display:none;margin-bottom:4px;">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                            <div class="txn-field">
                                <label style="font-size:10px;">Amount Tendered</label>
                                <input type="number" id="amountTendered" class="txn-input" style="font-size:12px;padding:7px 10px;"
                                       step="0.01" min="0" placeholder="₱0.00"
                                       oninput="computeChange()">
                            </div>
                            <div class="txn-field" id="changeWrap" style="display:none;">
                                <label style="font-size:10px;">Change</label>
                                <input type="number" id="changeAmount" class="txn-input" style="font-size:12px;padding:7px 10px;background:#f0fdf4;" readonly placeholder="—">
                            </div>
                            <div class="txn-field" id="cashBalanceWrap" style="display:none;">
                                <label style="font-size:10px;">Balance Due</label>
                                <input type="number" id="cashBalanceDue" class="txn-input" style="font-size:12px;padding:7px 10px;background:#fff7ed;" readonly placeholder="—">
                            </div>
                        </div>
                    </div>

                    <!-- Credit fields -->
                    <div id="creditFields" style="display:none;margin-bottom:4px;">
                        <div class="txn-field">
                            <label style="font-size:10px;">Credit Account</label>
                            <select id="creditCustomer" class="txn-select" style="font-size:12px;padding:7px 10px;">
                                <option value="">Select account…</option>
                                <?php foreach ($customers as $c): ?>
                                <option value="<?= (int)$c['id'] ?>"
                                        data-limit="<?= (float)$c['credit_limit'] ?>"
                                        data-balance="<?= (float)$c['balance'] ?>">
                                    <?= htmlspecialchars($c['name']) ?>
                                    (Avail: ₱<?= number_format($c['credit_limit'] - $c['balance'], 2) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Card / E-Wallet / E-Fuel fields -->
                    <div id="refFields" style="display:none;margin-bottom:4px;">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                            <div class="txn-field">
                                <label style="font-size:10px;">Amount Paid</label>
                                <input type="number" id="cardAmount" class="txn-input" style="font-size:12px;padding:7px 10px;"
                                       step="0.01" min="0" placeholder="₱0.00"
                                       oninput="checkCardSufficiency()">
                            </div>
                            <div class="txn-field">
                                <label style="font-size:10px;">Reference No.</label>
                                <input type="text" id="refNumber" class="txn-input" style="font-size:12px;padding:7px 10px;" placeholder="Optional">
                            </div>
                        </div>
                        <div class="txn-field" id="cardBalanceWrap" style="display:none;margin-top:6px;">
                            <label style="font-size:10px;">Balance Due</label>
                            <input type="number" id="cardBalanceDue" class="txn-input" style="font-size:12px;padding:7px 10px;background:#fff7ed;" readonly placeholder="—">
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
                        <div style="font-size:14px;font-weight:700;color:#1e293b;">Add New Service Type</div>
                        <div style="font-size:11px;color:#64748b;">Submitted for manager approval</div>
                    </div>
                    <button onclick="closeAddServiceModal()"
                            style="margin-left:auto;background:none;border:none;cursor:pointer;
                                   color:#94a3b8;font-size:20px;line-height:1;padding:0;"
                            title="Close">×</button>
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
                        <option value="Oil Change">Oil Change</option>
                        <option value="Wheel Alignment">Wheel Alignment</option>
                        <option value="Battery Services">Battery Services</option>
                        <option value="Brake Services">Brake Services</option>
                        <option value="Tire Services">Tire Services</option>
                        <option value="Engine Services">Engine Services</option>
                        <option value="AC Services">AC Services</option>
                        <option value="General Inspection">General Inspection</option>
                        <option value="Others" selected>Others</option>
                    </select>
                </div>

                <!-- Service Price -->
                <div style="margin-bottom:18px;">
                    <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:5px;">
                        Price (₱) <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="number" id="newServicePrice" class="txn-input"
                           placeholder="0.00"
                           step="0.01"
                           min="0"
                           style="font-size:13px;"
                           required>
                </div>

                <!-- Info banner -->
                <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;
                            padding:10px 12px;margin-bottom:18px;font-size:11px;color:#92400e;
                            display:flex;align-items:flex-start;gap:8px;">
                    <i class="fas fa-info-circle" style="margin-top:1px;flex-shrink:0;"></i>
                    <span>Your submission will be reviewed by a manager. You can select it immediately after submitting.</span>
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
                        <div style="font-size:14px;font-weight:700;color:#1e293b;">Add New Vehicle Type</div>
                        <div style="font-size:11px;color:#64748b;">Submitted for manager approval</div>
                    </div>
                    <button onclick="closeAddVehicleModal()"
                            style="margin-left:auto;background:none;border:none;cursor:pointer;
                                   color:#94a3b8;font-size:20px;line-height:1;padding:0;"
                            title="Close">×</button>
                </div>

                <!-- Category -->
                <div style="margin-bottom:14px;">
                    <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:5px;">
                        Category <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="text" 
                           id="newVehicleCategory" 
                           class="txn-input" 
                           list="vehicleCategoryList"
                           placeholder="Type or select category..."
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
                        <option value="Other">
                    </datalist>
                </div>

                <!-- Vehicle Name -->
                <div style="margin-bottom:18px;">
                    <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:5px;">
                        Vehicle Name <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="text" id="newVehicleName" class="txn-input"
                           placeholder="e.g. Toyota Innova, Kawasaki Dominar…"
                           maxlength="150"
                           style="font-size:13px;"
                           autocomplete="off">
                    <div style="font-size:10px;color:#94a3b8;margin-top:4px;">
                        Be specific — include brand and model (e.g. "Honda XRM 125")
                    </div>
                </div>

                <!-- Info banner -->
                <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;
                            padding:10px 12px;margin-bottom:18px;font-size:11px;color:#92400e;
                            display:flex;align-items:flex-start;gap:8px;">
                    <i class="fas fa-info-circle" style="margin-top:1px;flex-shrink:0;"></i>
                    <span>Your submission will be reviewed by a manager before it appears in the dropdown. You can still use it immediately after submitting.</span>
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
                        <div style="font-size:14px;font-weight:700;color:#1e293b;">Add New Product</div>
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

                <!-- Unit Price -->
                <div style="margin-bottom:18px;">
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

                <!-- Info banner -->
                <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;
                            padding:10px 12px;margin-bottom:18px;font-size:11px;color:#92400e;
                            display:flex;align-items:flex-start;gap:8px;">
                    <i class="fas fa-info-circle" style="margin-top:1px;flex-shrink:0;"></i>
                    <span>Your submission will be reviewed by a manager before it appears in the product list.</span>
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
        let selectedProduct = null;

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

        function selectProduct(el) {
            selectedProduct = {
                id:    el.dataset.id,
                name:  el.dataset.name,
                sku:   el.dataset.sku,
                cat:   el.dataset.cat,
                size:  el.dataset.size,
                price: parseFloat(el.dataset.price) || 0,
                stock: parseInt(el.dataset.stock) || 0,
            };
            const search = document.getElementById('productSearch');
            if (search) search.value = selectedProduct.name + (selectedProduct.size ? ' · ' + selectedProduct.size : '');
            const cat   = document.getElementById('itemCategory');
            const price = document.getElementById('itemUnitPrice');
            const stock = document.getElementById('itemStock');
            if (cat)   cat.value   = selectedProduct.cat;
            if (price) price.value = selectedProduct.price.toFixed(2);
            if (stock) stock.value = selectedProduct.stock > 0 ? selectedProduct.stock + ' in stock' : 'Out of stock';
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
        async function onMechanicChange() {
            const sel     = document.getElementById('joMechanic');
            const warnBox = document.getElementById('joMechanicBusyWarn');
            const listEl  = document.getElementById('joMechanicBusyList');
            if (!sel || !warnBox || !listEl) return;

            const mechId = sel.value;
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
            if (key === 'oil_change'        || name.includes('oil change'))                               return 'Oil Change';
            if (key === 'wheel_alignment'   || name.includes('wheel alignment'))                          return 'Wheel Alignment';
            if (key === 'battery_replacement'|| name.includes('battery'))                                 return 'Battery Services';
            if (key === 'brake_service'     || name.includes('brake'))                                    return 'Brake Services';
            if (key === 'tire_repair'       || name.includes('tire'))                                     return 'Tire Services';
            if (key === 'engine_repair'     || key === 'transmission'
                || name.includes('engine') || name.includes('transmission'))                              return 'Engine Services';
            if (key === 'air_conditioning'  || name.includes('air conditioning') || name.includes('a/c')) return 'AC Services';
            if (key === 'general_maintenance'||key === 'diagnostic_check'
                || name.includes('maintenance') || name.includes('diagnostic')
                || name.includes('inspection') || name.includes('calibration'))                           return 'General Inspection';
            return 'Others';
        }

        // ── JO Service type change ────────────────────────────────────────────
        // When a service type is selected:
        //   1. Auto-fill the service fee from cached data
        //   2. Show pricing notes
        //   3. Fetch suggested parts from DB and preview them
        //   4. Auto-fill the Category dropdown to match the selected service type
        function onJoServiceTypeChange() {
            const input      = document.getElementById('joServiceType');
            const hidden     = document.getElementById('joServiceTypeValue');
            const notesWrap  = document.getElementById('joServicePriceNotes');
            const notesText  = document.getElementById('joServicePriceNotesText');
            const priceInput = document.getElementById('joServicePrice');
            const categorySelect = document.getElementById('joServiceCategory');
            if (!hidden) return;
            
            // Allow matching typed name if hidden value is empty
            const val = hidden.value || (input ? input.value : '');

            // Auto-fill price from cached service data
            const svc = (window.JO_SERVICE_TYPES || []).find(s => s.name.toLowerCase() === val.toLowerCase());
            if (svc) {
                // Sync values
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
                
                // Auto-fill category dropdown
                if (categorySelect) {
                    const computedCat = getServiceCategory(svc);
                    categorySelect.value = computedCat;
                }

                // Fetch suggested parts for this service
                if (svc.key) fetchServiceParts(svc.key);
            } else {
                if (notesWrap) notesWrap.style.display = 'none';
                if (val && categorySelect) {
                    // Fallback for typed custom names
                    categorySelect.value = getServiceCategory({ name: val });
                }
                clearSuggestedParts();
            }
        }

        // ── Filter service types dropdown ─────────────────────────────────────
        function filterServiceTypes() {
            const input = document.getElementById('joServiceType');
            const list = document.getElementById('joServiceTypeList');
            const dropdown = document.getElementById('joServiceTypeDropdown');
            const selectedCat = (document.getElementById('joServiceCategory')?.value || '').trim().toLowerCase();
            
            if (!input || !list) return;
            
            const filter = input.value.toLowerCase().trim();
            const types = window.JO_SERVICE_TYPES || [];

            // If the user types an exact match, sync hidden value and trigger change
            const hidden = document.getElementById('joServiceTypeValue');
            const exactMatch = types.find(t => t.name.toLowerCase() === filter);
            if (exactMatch) {
                if (hidden && hidden.value !== exactMatch.name) {
                    hidden.value = exactMatch.name;
                    onJoServiceTypeChange();
                }
            } else {
                // If it doesn't match a standard service type but they typed a custom value,
                // trigger change to run fallback category inference
                onJoServiceTypeChange();
            }
            
            // Filter matching types (by text search AND by selected category, if a category is selected)
            const filtered = types.filter(t => {
                const nameMatches = t.name.toLowerCase().includes(filter);
                const tCategory = getServiceCategory(t).toLowerCase();
                const catMatches = !selectedCat || tCategory === selectedCat;
                return nameMatches && catMatches;
            });
            
            // Render dropdown list
            if (filtered.length === 0) {
                list.innerHTML = '<div style="padding:10px;color:#94a3b8;font-size:13px;text-align:center;">No services found</div>';
            } else {
                list.innerHTML = filtered.map(t => {
                    const cat = getServiceCategory(t);
                    return `
                        <div class="service-type-option svc-option" 
                             data-value="${escapeHtml(t.name)}"
                             data-name="${escapeHtml(t.name)}"
                             data-category="${escapeHtml(cat)}"
                             onclick="selectServiceType('${escapeHtml(t.name)}')"
                             style="padding:10px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid #f1f5f9;
                                    transition:background 0.15s;"
                             >
                            ${escapeHtml(t.name)}
                            <span style="float:right;font-size:11px;color:#94a3b8;">${escapeHtml(cat)}</span>
                        </div>
                    `;
                }).join('');
            }
            
            dropdown.style.display = 'block';
        }
        
        // ── Show service dropdown ─────────────────────────────────────────────
        function showServiceDropdown() {
            const dropdown = document.getElementById('joServiceTypeDropdown');
            const input = document.getElementById('joServiceType');
            
            if (!dropdown || !input) return;
            
            // If input is empty, show all options
            if (!input.value.trim()) {
                filterServiceTypes();
            }
            
            dropdown.style.display = 'block';
        }
        
        // ── Hide service dropdown ─────────────────────────────────────────────
        function hideServiceDropdown() {
            const dropdown = document.getElementById('joServiceTypeDropdown');
            if (dropdown) dropdown.style.display = 'none';
        }
        
        // ── Select service type ───────────────────────────────────────────────
        function selectServiceType(serviceName) {
            const input = document.getElementById('joServiceType');
            const hidden = document.getElementById('joServiceTypeValue');
            const dropdown = document.getElementById('joServiceTypeDropdown');
            
            if (input) input.value = serviceName;
            if (hidden) hidden.value = serviceName;
            if (dropdown) dropdown.style.display = 'none';
            
            onJoServiceTypeChange();
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
            const svcType  = (document.getElementById('joServiceTypeValue')?.value || '').trim();
            const svcPrice = parseFloat(document.getElementById('joServicePrice')?.value || 0);

            if (!svcType || svcPrice <= 0) return; // caller already validated

            // ── 1. Add / update service fee line item ─────────────────────────
            const existingSvc = cart.find(i => i.item_type === 'service' && i.product_name === svcType);
            if (existingSvc) {
                existingSvc.unit_price = svcPrice;
            } else {
                cart.push({
                    item_type:    'service',
                    product_name: svcType,
                    category:     'Service Fee',
                    size_variant: '',
                    product_id:   null,
                    quantity:     1,
                    unit_price:   svcPrice,
                });
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
                    `"${svcType}" + ${parts.filter(p => p.product_id && p.in_stock).length} part(s) added to cart.`,
                    'success'
                );
            } else {
                showTxnAlert(`"${svcType}" added to cart (service fee only — no parts mapped).`, 'success');
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
            if (nameEl)  nameEl.value  = '';
            if (priceEl) priceEl.value = '';
            if (catEl)   catEl.value   = 'Others';
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
            const name     = (document.getElementById('newServiceName')?.value     || '').trim();
            const price    = (document.getElementById('newServicePrice')?.value    || '').trim();
            const category = (document.getElementById('newServiceCategory')?.value || 'Others').trim();
            const btn      = document.getElementById('addServiceSubmitBtn');

            setAddServiceError('');
            if (!name)  { setAddServiceError('Please enter the service name.'); return; }
            if (name.length > 100) { setAddServiceError('Name is too long (max 100 characters).'); return; }
            if (!price || isNaN(price) || parseFloat(price) < 0) { setAddServiceError('Please enter a valid positive price.'); return; }

            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting…'; }

            try {
                const res  = await fetch('../backend/api/get_service_types.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ service_name: name, service_price: parseFloat(price), category }),
                });
                const data = await res.json();

                if (data.success) {
                    closeAddServiceModal();
                    await loadServiceTypes(name);
                    showTxnAlert(
                        '"' + name + '" submitted for manager approval and selected. It will appear for all staff once approved.',
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
            // Price manually entered — nothing extra needed
        }

        // ── Customer name autocomplete handler ────────────────────────────────
        // Store customer data for quick lookup
        const customerData = <?= json_encode(array_map(function($name) {
            $parts = explode(' ', trim($name), 2);
            return [
                'full_name' => $name,
                'first_name' => $parts[0] ?? '',
                'last_name' => $parts[1] ?? ''
            ];
        }, $customer_names)) ?>;

        function onCustomerNameInput(prefix) {
            // prefix = 'jo' or 'merch'
            const firstNameEl = document.getElementById(prefix + 'FirstName');
            const lastNameEl = document.getElementById(prefix + 'LastName');
            
            if (!firstNameEl || !lastNameEl) return;
            
            const inputValue = firstNameEl.value.trim();
            
            // Check if the input matches a registered customer's first name
            const matchedCustomer = customerData.find(customer => 
                customer.first_name.toLowerCase() === inputValue.toLowerCase()
            );
            
            // If exact match found on first name, auto-fill last name
            if (matchedCustomer && matchedCustomer.last_name) {
                lastNameEl.value = matchedCustomer.last_name;
            }
        }

        // Also handle when user selects from datalist (change event)
        function setupCustomerAutocomplete() {
            ['jo', 'merch'].forEach(prefix => {
                const firstNameEl = document.getElementById(prefix + 'FirstName');
                const lastNameEl = document.getElementById(prefix + 'LastName');
                
                if (firstNameEl && lastNameEl) {
                    // Handle both input and change events
                    firstNameEl.addEventListener('change', function() {
                        const inputValue = this.value.trim();
                        
                        // Try to find exact match
                        const matchedCustomer = customerData.find(customer => 
                            customer.first_name.toLowerCase() === inputValue.toLowerCase() ||
                            customer.full_name.toLowerCase() === inputValue.toLowerCase()
                        );
                        
                        if (matchedCustomer) {
                            // Auto-fill with split name parts
                            this.value = matchedCustomer.first_name;
                            lastNameEl.value = matchedCustomer.last_name || '';
                        }
                    });
                }
            });
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', setupCustomerAutocomplete);

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
            const nameEl = document.getElementById('newVehicleName');
            const catEl  = document.getElementById('newVehicleCategory');
            if (nameEl) nameEl.value = '';
            if (catEl)  catEl.value  = '';
            setAddVehicleError('');
            const modal = document.getElementById('addVehicleModal');
            if (modal) { modal.style.display = 'flex'; }
            setTimeout(() => nameEl && nameEl.focus(), 80);
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
            const name     = (document.getElementById('newVehicleName')?.value     || '').trim();
            const category = (document.getElementById('newVehicleCategory')?.value || '').trim();
            const btn      = document.getElementById('addVehicleSubmitBtn');

            setAddVehicleError('');
            if (!category) { setAddVehicleError('Please select a category.'); return; }
            if (!name)     { setAddVehicleError('Please enter the vehicle name.'); return; }
            if (name.length > 150) { setAddVehicleError('Name is too long (max 150 characters).'); return; }

            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting\u2026'; }

            try {
                const res  = await fetch('../backend/api/get_vehicle_types.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ vehicle_name: name, category }),
                });
                const data = await res.json();

                if (data.success) {
                    closeAddVehicleModal();
                    await loadVehicleTypes(name);
                    showTxnAlert(
                        '"' + name + '" submitted for manager approval and selected. It will appear for all staff once approved.',
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
            const nameEl = document.getElementById('newProductName');
            const catEl  = document.getElementById('newProductCategory');
            const skuEl  = document.getElementById('newProductSKU');
            const priceEl = document.getElementById('newProductPrice');
            if (nameEl) nameEl.value = '';
            if (catEl)  catEl.value  = '';
            if (skuEl)  skuEl.value  = '';
            if (priceEl) priceEl.value = '';
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
            const price    = parseFloat(document.getElementById('newProductPrice')?.value || 0);
            const btn      = document.getElementById('addProductSubmitBtn');

            setAddProductError('');
            if (!category) { setAddProductError('Please enter or select a category.'); return; }
            if (!name)     { setAddProductError('Please enter the product name.'); return; }
            if (name.length > 150) { setAddProductError('Name is too long (max 150 characters).'); return; }
            if (price <= 0) { setAddProductError('Please enter a valid price greater than zero.'); return; }

            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting\u2026'; }

            try {
                const res  = await fetch('../backend/api/add_product.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ 
                        product_name: name, 
                        category: category,
                        sku: sku || null,
                        unit_price: price
                    }),
                });
                const data = await res.json();

                if (data.success) {
                    closeAddProductModal();
                    showTxnAlert(
                        '"' + name + '" submitted for manager approval. It will appear in the product list once approved.',
                        'success'
                    );
                    // Optionally reload products list here
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

        // ── Add merchandise to cart ───────────────────────────────────────────
        function addToCart() {
            if (!selectedProduct) { showTxnAlert('Please select a product first.', 'warning'); return; }
            const qty = parseInt(document.getElementById('itemQty')?.value || 1);
            if (isNaN(qty) || qty < 1) { showTxnAlert('Quantity must be at least 1.', 'warning'); return; }

            const stock = parseInt(selectedProduct.stock) || 0;
            if (stock <= 0) { showTxnAlert('This product is out of stock.', 'warning'); return; }
            if (qty > stock) {
                showTxnAlert(`Only ${stock} unit(s) available in stock.`, 'warning'); return;
            }

            const pid = String(selectedProduct.id);
            const existing = cart.find(i => i.item_type === 'merchandise' && String(i.product_id) === pid);
            if (existing) {
                const newQty = existing.quantity + qty;
                if (newQty > stock) {
                    showTxnAlert(`Cannot add ${qty} more — only ${stock - existing.quantity} unit(s) left.`, 'warning'); return;
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
                });
            }

            renderCart();
            updateCheckoutBtn();
            resetMerchandiseForm();
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
                    stock_level:  stock
                });
            }

            renderCart();
            updateCheckoutBtn();
            showTxnAlert(`"${name}" added to cart!`, 'success');
        }

        // ── Add currently selected product in merchandise form to cart ──────
        function addProductFromFormToCart() {
            if (!selectedProduct) {
                showTxnAlert('Please select a product from the dropdown first.', 'warning');
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
                await applyJobOrderToCart();
            }

            // Add merchandise product if selected
            if (hasProduct) {
                addToCart();
            }
        }

        // ── Reset merchandise form fields only ───────────────────────────────
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
            // Reset vehicle type and mechanic selects to first option
            const vehicleInput = document.getElementById('joVehicleType');
            if (vehicleInput) vehicleInput.value = ''; // Now an input field
            const mechanicSelect = document.getElementById('joMechanic');
            if (mechanicSelect && mechanicSelect.options) mechanicSelect.selectedIndex = 0;
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
            if (payMethod) { payMethod.selectedIndex = 0; onPaymentChange(); }
            const tendered = document.getElementById('amountTendered');
            if (tendered) tendered.value = '';
            const changeEl = document.getElementById('changeAmount');
            if (changeEl) changeEl.value = '';
        }

        // ── Clear cart ────────────────────────────────────────────────────────
        function clearCart() {
            if (cart.length === 0) return;
            if (!confirm('Clear all items from the cart?')) return;
            cart = [];
            renderCart();
            updateCheckoutBtn();
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
            cart.splice(idx, 1);
            renderCart();
            updateCheckoutBtn();
        }

        function updateTotals(subtotal, vat, grand) {
            const s = document.getElementById('cartSubtotal');
            const v = document.getElementById('cartVat');
            const g = document.getElementById('cartGrandTotal');
            if (s) s.textContent = '₱' + fmtNum(subtotal);
            if (v) v.textContent = '₱' + fmtNum(vat);
            if (g) g.textContent = '₱' + fmtNum(grand);
            computeChange();
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
            const cashFields   = document.getElementById('cashFields');
            const creditFields = document.getElementById('creditFields');
            const refFields    = document.getElementById('refFields');
            if (cashFields)   cashFields.style.display   = method === 'Cash'   ? 'block' : 'none';
            if (creditFields) creditFields.style.display = method === 'Credit' ? 'block' : 'none';
            if (refFields)    refFields.style.display    = ['Card','E-Wallet','E-Fuel Card','Fleet Card'].includes(method) ? 'block' : 'none';
            computeChange();
            checkCardSufficiency();
            updatePaymentStatusBadge();
            updateCheckoutBtn();
        }

        function computeChange() {
            const grand    = getGrandTotal();
            const tendered = parseFloat(document.getElementById('amountTendered')?.value || 0);

            // Change: only show when tendered >= grand (exact / overpayment)
            const changeWrap = document.getElementById('changeWrap');
            const changeEl   = document.getElementById('changeAmount');
            // Balance Due: show when tendered is 0 or partial
            const balWrap = document.getElementById('cashBalanceWrap');
            const balEl   = document.getElementById('cashBalanceDue');

            if (tendered >= grand && grand > 0) {
                const change = tendered - grand;
                if (changeWrap) changeWrap.style.display = 'block';
                if (changeEl)   changeEl.value = change.toFixed(2);
                if (balWrap)    balWrap.style.display  = 'none';
                if (balEl)      balEl.value = '';
            } else {
                if (changeWrap) changeWrap.style.display = 'none';
                if (changeEl)   changeEl.value = '';
                const bal = Math.max(0, grand - tendered);
                if (balWrap) balWrap.style.display = (grand > 0) ? 'block' : 'none';
                if (balEl)   balEl.value = bal > 0 ? bal.toFixed(2) : '';
            }
            updatePaymentStatusBadge();
        }

        function checkCardSufficiency() {
            const grand  = getGrandTotal();
            const amount = parseFloat(document.getElementById('cardAmount')?.value || 0);
            const balWrap = document.getElementById('cardBalanceWrap');
            const balEl   = document.getElementById('cardBalanceDue');
            if (amount >= grand && grand > 0) {
                if (balWrap) balWrap.style.display = 'none';
                if (balEl)   balEl.value = '';
            } else {
                const bal = Math.max(0, grand - amount);
                if (balWrap) balWrap.style.display = (grand > 0) ? 'block' : 'none';
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

            if (method === 'Credit') {
                status    = 'Credit Transaction';
                color     = '#6b21a8'; border = '#d8b4fe'; bg = '#f3e8ff';
                iconClass = 'fas fa-handshake';
                subText   = 'Utang — Manager/Admin tagged. Full amount on credit.';
            } else {
                let amountPaid = 0;
                if (method === 'Cash') {
                    amountPaid = parseFloat(document.getElementById('amountTendered')?.value || 0);
                } else {
                    amountPaid = parseFloat(document.getElementById('cardAmount')?.value || 0);
                }

                if (amountPaid <= 0 || grand === 0) {
                    status    = 'Pending Payment';
                    color     = '#9a3412'; border = '#fed7aa'; bg = '#ffedd5';
                    iconClass = 'fas fa-clock';
                    subText   = grand > 0 ? 'No amount encoded. Balance due: \u20b1' + fmtNum(grand) : 'Enter cart items first.';
                } else if (amountPaid < grand - 0.009) {
                    const bal = grand - amountPaid;
                    status    = 'Partial Payment';
                    color     = '#92400e'; border = '#fde68a'; bg = '#fef9c3';
                    iconClass = 'fas fa-exclamation-circle';
                    subText   = 'Downpayment \u20b1' + fmtNum(amountPaid) + ' \u2014 Balance due: \u20b1' + fmtNum(bal);
                } else {
                    status    = 'Paid';
                    color     = '#166534'; border = '#86efac'; bg = '#dcfce7';
                    iconClass = 'fas fa-check-circle';
                    if (method === 'Cash') {
                        const change = amountPaid - grand;
                        subText = change > 0.009 ? 'Change: \u20b1' + fmtNum(change) : 'Exact amount paid.';
                    } else {
                        subText = 'Full amount received via ' + method + '.';
                    }
                }
            }

            wrap.style.display  = 'flex';
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
            btn.disabled = cart.length === 0 || !method;
        }

        // ── Submit transaction ────────────────────────────────────────────────
        async function submitMerchTxn() {
            if (cart.length === 0) { showTxnAlert('Cart is empty.', 'warning'); return; }

            const method = document.getElementById('paymentMethod')?.value || '';
            if (!method) { showTxnAlert('Please select a payment method.', 'warning'); return; }

            const grand = getGrandTotal();

            // Determine customer name source:
            // Always read from the form where the staff encoded the customer.
            // Job Order section → joFirstName / joLastName
            // Merchandise-only → merchFirstName / merchLastName
            // If both forms have names, Job Order takes priority (it has the required * field).
            const hasService = cart.some(i => i.item_type === 'service');
            let firstName, lastName;
            if (hasService) {
                firstName = (document.getElementById('joFirstName')?.value || '').trim();
                lastName  = (document.getElementById('joLastName')?.value  || '').trim();
                if (!firstName) { showTxnAlert('Please enter the customer\'s first name in the Job Order section.', 'warning'); return; }
            } else {
                firstName = (document.getElementById('merchFirstName')?.value || '').trim();
                lastName  = (document.getElementById('merchLastName')?.value  || '').trim();
                // Merchandise-only: name is optional (defaults to Walk-in Customer)
            }

            // Payment validation — only hard-block Credit without an account
            // Cash/Card/E-Wallet/E-Fuel Card allow partial (downpayment) or zero (pending)
            if (method === 'Credit') {
                const creditSel = document.getElementById('creditCustomer');
                if (!creditSel?.value) { showTxnAlert('Please select a credit account.', 'warning'); return; }
            }

            // Build JO data if service in cart
            let joData = {};
            if (hasService) {
                const mechSel = document.getElementById('joMechanic');
                const mechOpt = mechSel?.options[mechSel.selectedIndex];
                joData = {
                    job_order_service:       (document.getElementById('joServiceTypeValue')?.value || '').trim(),
                    job_order_description:   (document.getElementById('joNotes')?.value || '').trim(),
                    job_order_vehicle_plate: (document.getElementById('joVehiclePlate')?.value || '').trim().toUpperCase(),
                    job_order_vehicle_type:  (document.getElementById('joVehicleType')?.value || '').trim(),
                    job_order_mechanic_id:   mechSel?.value ? parseInt(mechSel.value) : null,
                    job_order_mechanic_name: mechOpt?.dataset?.name || '',
                    job_order_contact:       (document.getElementById('joContactNumber')?.value || '').trim(),
                };
            }

            // Compute amount_paid + derived payment_status for payload
            let amountPaid = 0;
            if (method === 'Cash') {
                amountPaid = parseFloat(document.getElementById('amountTendered')?.value || 0);
            } else if (['Card','E-Wallet','E-Fuel Card','Fleet Card'].includes(method)) {
                amountPaid = parseFloat(document.getElementById('cardAmount')?.value || 0);
            }
            let paymentStatus;
            if (method === 'Credit') {
                paymentStatus = 'Credit Transaction';
            } else if (amountPaid <= 0) {
                paymentStatus = 'Pending Payment';
            } else if (amountPaid < grand - 0.009) {
                paymentStatus = 'Partial Payment';
            } else {
                paymentStatus = 'Paid';
            }
            const balanceDue = (paymentStatus === 'Credit Transaction') ? grand
                             : (paymentStatus === 'Paid' ? 0 : Math.max(0, grand - amountPaid));

            const payload = {
                action:              'create_transaction',
                customer_first_name: firstName || null,
                customer_last_name:  lastName  || null,
                customer_name:       [firstName, lastName].filter(Boolean).join(' ') || 'Walk-in Customer',
                payment_method:      method,
                amount_paid:         amountPaid > 0 ? amountPaid : null,
                amount_tendered:     method === 'Cash' ? (amountPaid > 0 ? amountPaid : null) : null,
                change_amount:       (method === 'Cash' && amountPaid >= grand) ? parseFloat((amountPaid - grand).toFixed(2)) : null,
                balance_due:         balanceDue > 0 ? parseFloat(balanceDue.toFixed(2)) : null,
                payment_status:      paymentStatus,
                card_reference:      document.getElementById('refNumber')?.value || null,
                card_type:           method === 'Card' ? 'Card' : null,
                ewallet_reference:   method === 'E-Wallet' ? (document.getElementById('refNumber')?.value || null) : null,
                ewallet_provider:    method === 'E-Wallet' ? 'E-Wallet' : null,
                efuel_card_number:   method === 'E-Fuel Card' ? (document.getElementById('refNumber')?.value || null) : null,
                credit_customer_id:  method === 'Credit' ? (parseInt(document.getElementById('creditCustomer')?.value) || null) : null,
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
                    // Print receipt using popup-immune iframe
                    printMerchandiseReceipt(data.transaction_id);
                    // Reset everything
                    cart = [];
                    renderCart();
                    updateCheckoutBtn();
                    resetMerchandiseForm();
                    // Reset JO fields
                    ['joFirstName','joLastName','joContactNumber','joVehiclePlate',
                     'joServicePrice','joNotes'].forEach(id => {
                        const el = document.getElementById(id);
                        if (el) el.value = '';
                    });
                    const joSvcInput = document.getElementById('joServiceType');
                    const joSvcHidden = document.getElementById('joServiceTypeValue');
                    if (joSvcInput) joSvcInput.value = '';
                    if (joSvcHidden) joSvcHidden.value = '';
                    const joVehicleSel = document.getElementById('joVehicleType');
                    if (joVehicleSel) joVehicleSel.selectedIndex = 0;
                    const joMech = document.getElementById('joMechanic');
                    if (joMech) joMech.selectedIndex = 0;
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
        function showTxnAlert(msg, type) {
            const colors = {
                success: { bg: '#f0fdf4', border: '#86efac', color: '#166534' },
                warning: { bg: '#fffbeb', border: '#fde68a', color: '#92400e' },
                error:   { bg: '#fee2e2', border: '#fca5a5', color: '#991b1b' },
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
            div.style.cssText = `position:fixed;top:70px;left:50%;transform:translateX(-50%);z-index:9999;
                background:${c.bg};border:1px solid ${c.border};color:${c.color};
                padding:10px 20px;border-radius:8px;font-size:13px;font-weight:600;
                display:flex;align-items:center;gap:8px;box-shadow:0 4px 16px rgba(0,0,0,.12);
                max-width:480px;width:90%;`;
            div.innerHTML = `<i class="fas ${icon}"></i><span>${escHtml(msg)}</span>
                <button onclick="this.parentElement.remove()" style="margin-left:auto;background:none;border:none;cursor:pointer;color:${c.color};font-size:16px;line-height:1;padding:0 0 0 8px;">×</button>`;
            document.body.appendChild(div);
            setTimeout(() => { if (div.parentElement) div.remove(); }, 4000);
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

        <!-- Filter bar — Type, Date Range, Status, Mechanic, Service Type -->
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;margin-bottom:14px;">
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
                        <option value="rejected">Rejected</option>
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
                    <select id="joFilterServiceType" onchange="joApplyFilters()" style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:12px;color:#1e293b;background:#fff;outline:none;max-width:180px;">
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
                        foreach ($jo_service_list as $st): ?>
                        <option value="<?= htmlspecialchars($st) ?>"><?= htmlspecialchars($st) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="button" onclick="joApplyFilters()" class="txn-btn primary">
                    <i class="fas fa-filter"></i> Filter
                </button>
                <button type="button" onclick="joResetFilters()" class="txn-btn secondary">
                    <i class="fas fa-times"></i> Clear
                </button>
                <!-- Export buttons -->
                <div style="margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <a href="../backend/export_staff_transactions.php?type=job_orders&format=excel" class="txn-btn success">
                        <i class="fas fa-file-excel"></i> Excel
                    </a>
                    <a href="../backend/export_staff_transactions.php?type=job_orders&format=csv" class="txn-btn primary">
                        <i class="fas fa-file-csv"></i> CSV
                    </a>
                    <a href="staff_activity_report.php" class="txn-btn secondary" style="border-color:#6366f1;color:#6366f1 !important;">
                        <i class="fas fa-clipboard-list"></i> Activity Log
                    </a>
                </div>
            </div>
        </div>

        <!-- KPI Snapshot Panel — collapsible, today's stats for logged-in staff -->
        <div style="margin-bottom:12px;">
            <button type="button"
                    onclick="document.getElementById('kpiSnapshotPanel').classList.toggle('d-none')"
                    class="txn-btn success">
                <i class="fas fa-chart-bar"></i> My KPI Today
            </button>
        </div>
        <div id="kpiSnapshotPanel" class="d-none"
             style="display:none;margin-bottom:16px;background:#f0f7ff;border:1px solid #bfdbfe;
                    border-radius:8px;padding:14px 18px;">
            <div style="font-size:12px;font-weight:700;color:#1d6f42;margin-bottom:10px;">
                <i class="fas fa-chart-bar"></i> My KPI — <?= date('F j, Y') ?>
            </div>
            <div style="display:flex;gap:16px;flex-wrap:wrap;">
                <div style="flex:1;background:#fff;border-radius:6px;
                            padding:10px 14px;border:1px solid #dbeafe;text-align:center;">
                    <div style="font-size:22px;font-weight:700;color:#002F6C;">
                        <?= (int)$kpi_jo_count ?>
                    </div>
                    <div style="font-size:11px;color:#64748b;margin-top:2px;">Job Orders Encoded Today</div>
                </div>
                <div style="flex:1;background:#fff;border-radius:6px;
                            padding:10px 14px;border:1px solid #dbeafe;text-align:center;">
                    <div style="font-size:22px;font-weight:700;color:#002F6C;">
                        <?= (int)$kpi_merch_released ?>
                    </div>
                    <div style="font-size:11px;color:#64748b;margin-top:2px;">Merchandise Released (pcs)</div>
                </div>
                <div style="flex:1;background:#fff;border-radius:6px;
                            padding:10px 14px;border:1px solid #dbeafe;text-align:center;">
                    <div style="font-size:22px;font-weight:700;color:#002F6C;">
                        ₱<?= number_format($kpi_total_encoded, 2) ?>
                    </div>
                    <div style="font-size:11px;color:#64748b;margin-top:2px;">Total Amount Encoded</div>
                </div>
            </div>
        </div>
        <script>
        // KPI toggle — use inline display since d-none may be overridden by Bootstrap
        (function(){
            var kpiPanel = document.getElementById('kpiSnapshotPanel');
            if (kpiPanel) {
                // Auto-show if there's real data today
                var hasData = (<?= (int)$kpi_jo_count ?> > 0 || <?= (int)$kpi_merch_released ?> > 0 || <?= round($kpi_total_encoded, 2) ?> > 0);
                kpiPanel.style.display = hasData ? 'block' : 'none';
            }
            var kpiBtn = kpiPanel ? kpiPanel.previousElementSibling.querySelector('button') : null;
            if (kpiBtn && kpiPanel) {
                kpiBtn.onclick = function() {
                    kpiPanel.style.display = (kpiPanel.style.display === 'none') ? 'block' : 'none';
                };
            }
        })();
        </script>

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
                <div style="overflow-x:auto;">
                <table class="txn-table" id="joUnifiedTable" style="table-layout:auto;word-wrap:break-word;width:100%;">
                    <colgroup>
                        <col style="width:8%;"><!-- JO Number -->
                        <col style="width:12%;"><!-- Customer -->
                        <col style="width:10%;"><!-- Vehicle -->
                        <col style="width:12%;"><!-- Service Type -->
                        <col style="width:10%;"><!-- Mechanic -->
                        <col style="width:8%;" ><!-- Service Fee -->
                        <col style="width:12%;"><!-- Status -->
                        <col style="width:10%;"><!-- Date Created -->
                        <col style="width:18%;" ><!-- Actions (increased from 6% to 18%) -->
                    </colgroup>
                    <thead>
                        <tr>
                            <th style="font-size:11px;text-align:left;padding:8px 10px;">JO Number</th>
                            <th style="font-size:11px;text-align:left;padding:8px 10px;">Customer</th>
                            <th style="font-size:11px;text-align:left;padding:8px 10px;">Vehicle</th>
                            <th style="font-size:11px;text-align:left;padding:8px 10px;">Service Type</th>
                            <th style="font-size:11px;text-align:left;padding:8px 10px;">Assigned Mechanic</th>
                            <th style="font-size:11px;text-align:right;padding:8px 10px;">Service Fee</th>
                            <th style="font-size:11px;text-align:left;padding:8px 10px;">Status</th>
                            <th style="font-size:11px;text-align:left;padding:8px 10px;">Date Created</th>
                            <th style="font-size:11px;text-align:center;padding:8px 10px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($job_orders as $job):
                        $val_status  = $job['validation_status'] ?? 'Pending Validation';
                        $wf_status   = $job['status'] ?? 'Pending';
                        $pay_status  = $job['payment_status'] ?? 'Pending';
                        $remarks     = $job['rejection_remarks'] ?? $job['notes'] ?? $job['additional_notes'] ?? '';

                        // Determine combined workflow label + badge style (plain text)
                        if (in_array($wf_status, ['Rejected', 'Cancelled']) || $val_status === 'Rejected') {
                            $wf_color='#dc2626'; $wf_label='REJECTED'; $row_filter='rejected';
                        } elseif ($wf_status === 'Completed' && $val_status === 'Pending Validation') {
                            // NEW: Completed pero wala pa approve ni manager
                            $wf_color='#d97706'; $wf_label='COMPLETED / PENDING VALIDATION'; $row_filter='completed';
                        } elseif ($wf_status === 'Completed' || $val_status === 'Completed') {
                            $wf_color='#16a34a'; $wf_label='COMPLETED'; $row_filter='completed';
                        } elseif ($wf_status === 'In Progress' || $val_status === 'In Progress') {
                            $wf_color='#2563eb'; $wf_label='IN PROGRESS'; $row_filter='inprogress';
                        } elseif (in_array($val_status, ['Approved', 'Validated'])) {
                            $wf_color='#16a34a'; $wf_label='APPROVED'; $row_filter='approved';
                        } else {
                            // Catches: 'Pending Validation', 'Pending', '', NULL
                            $wf_color='#d97706'; $wf_label='PENDING VALIDATION'; $row_filter='pending';
                        }

                        // Payment badge and label mapping (plain text style)
                        if ($pay_status === 'Paid') {
                            $pay_color='#16a34a'; $pay_label='PAID';
                        } elseif ($pay_status === 'Partial') {
                            $pay_color='#d97706'; $pay_label='DOWNPAYMENT';
                        } elseif ($pay_status === 'Unpaid') {
                            $pay_color='#dc2626'; $pay_label='UNPAID';
                        } elseif ($pay_status === 'Credit' || $pay_status === 'Receivables/Credit') {
                            $pay_color='#7c3aed'; $pay_label='RECEIVABLES/CREDIT';
                        } else {
                            $pay_color='#ea580c'; $pay_label='PENDING PAYMENT';
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
                        $inv_key      = ($job['_source'] ?? 'job_orders') . ':' . $job['id'];
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
                        $staff_remark   = trim($job['staff_remarks'] ?? $job['notes'] ?? '');
                        $manager_remark = trim($job['manager_notes'] ?? $job['admin_remarks'] ?? '');

                        // ── Shift Indicator ──────────────────────────────────────────────────
                        // Use explicit shift_name/shift_period if available (MT rows),
                        // otherwise derive from created_at time.
                        $shift_name_raw = trim($job['shift_name'] ?? $job['shift_period'] ?? '');
                        if (!empty($shift_name_raw)) {
                            $shift_label    = $shift_name_raw;
                            $shift_key_data = (stripos($shift_name_raw,'2') !== false || stripos($shift_name_raw,'pm') !== false || stripos($shift_name_raw,'night') !== false) ? 'shift2' : 'shift1';
                        } else {
                            // Derive from created_at hour: 06:00-17:59 = Shift 1, else Shift 2
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
                        data-jo-mechanic="<?= htmlspecialchars($job['mechanic_name'] ?? '') ?>"
                        data-jo-service="<?= htmlspecialchars($job['service_type'] ?? '') ?>"
                        data-jo-date="<?= date('Y-m-d', strtotime($job['created_at'])) ?>"
                        style="<?= $wf_status === 'Rejected' ? 'background:#fff8f8;' : ($jo_is_overdue ? 'background:#fff5f5;border-left:3px solid #dc2626;' : '') ?>">

                        <!-- JO Number -->
                        <td style="padding:10px;font-weight:700;color:<?= $wf_status==='Rejected' ? '#dc2626' : 'var(--petron-blue)' ?>;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?= htmlspecialchars($job['job_order_id'] ?? ('#'.$job['id'])) ?>
                        </td>

                        <!-- Customer -->
                        <td style="padding:10px;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                            title="<?= htmlspecialchars($job['customer_name'] ?? '') ?>">
                            <?= htmlspecialchars($job['customer_name'] ?? '—') ?>
                        </td>

                        <!-- Vehicle -->
                        <td style="padding:10px;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?php if (!empty($job['vehicle_plate'])): ?>
                            <strong style="color:#1e293b;"><?= htmlspecialchars($job['vehicle_plate']) ?></strong>
                            <?php if (!empty($job['vehicle_type'])): ?>
                            <span style="color:#94a3b8;"> · <?= htmlspecialchars($job['vehicle_type']) ?></span>
                            <?php endif; ?>
                            <?php else: ?>
                            <span style="color:#cbd5e1;">—</span>
                            <?php endif; ?>
                        </td>

                        <!-- Service Type -->
                        <td style="padding:10px;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                            title="<?= htmlspecialchars($job['service_type'] ?? '') ?>">
                            <?= htmlspecialchars($job['service_type'] ?? '—') ?>
                        </td>

                        <!-- Assigned Mechanic -->
                        <td style="padding:10px;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?php
                            $mech = trim($job['mechanic_name'] ?? '');
                            if ($mech && $mech !== 'Unassigned'): ?>
                            <span style="display:inline-flex;align-items:center;gap:5px;">
                                <i class="fas fa-user-cog" style="color:#64748b;font-size:10px;"></i>
                                <?= htmlspecialchars($mech) ?>
                            </span>
                            <?php else: ?>
                            <span style="color:#cbd5e1;font-style:italic;font-size:11px;">Unassigned</span>
                            <?php endif; ?>
                        </td>

                        <!-- Service Fee -->
                        <td style="padding:10px;font-size:12px;text-align:right;font-weight:700;color:var(--petron-blue);white-space:nowrap;">
                            ₱<?= number_format((float)($job['total_cost'] ?? $job['estimated_cost'] ?? 0), 2) ?>
                        </td>

                        <!-- Status -->
                        <td style="padding:10px;">
                            <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;letter-spacing:.4px;white-space:nowrap;
                                         background:<?= $wf_color ?>18;color:<?= $wf_color ?>;border:1px solid <?= $wf_color ?>40;">
                                <?= $wf_label ?>
                            </span>
                        </td>

                        <!-- Date Created -->
                        <td style="padding:10px;font-size:11px;color:#64748b;white-space:nowrap;">
                            <?= date('M j, Y', strtotime($job['created_at'])) ?><br>
                            <span style="font-size:10px;"><?= date('h:i A', strtotime($job['created_at'])) ?></span>
                        </td>


                        <td style="padding:8px 10px;">
                            <?php
                                $jo_total   = (float)($job['total_cost'] ?? $job['estimated_cost'] ?? 0);
                                $jo_paid    = (float)($job['amount_paid'] ?? 0);
                                $jo_balance = (float)($job['balance_due'] ?? 0);
                                if ($jo_balance <= 0.009 && $jo_total > 0) $jo_balance = max(0, $jo_total - $jo_paid);
                                
                                // Prepare JO data for modals (JSON-encoded)
                                $jo_data = json_encode([
                                    'id' => (int)$job['id'],
                                    'jo_ref' => $job['job_order_id'] ?? ('#'.$job['id']),
                                    'customer' => $job['customer_name'] ?? '—',
                                    'vehicle_plate' => $job['vehicle_plate'] ?? '',
                                    'vehicle_type' => $job['vehicle_type'] ?? '',
                                    'service_type' => $job['service_type'] ?? '',
                                    'service_description' => $job['service_description'] ?? '',
                                    'parts' => $parts_display,
                                    'mechanic' => $job['mechanic_name'] ?? 'Unassigned',
                                    'workflow_status' => $wf_label,
                                    'payment_status' => $pay_label,
                                    'total' => $jo_total,
                                    'paid' => $jo_paid,
                                    'balance' => $jo_balance,
                                    'remarks' => $remarks,
                                    'created_at' => $job['created_at'],
                                    'source' => $job['_source'] ?? 'job_orders'
                                ]);
                            ?>
                            <div style="display:flex;flex-direction:column;gap:4px;">
                                <!-- Row 1: View + Update Status + Adjust -->
                                <div style="display:flex;gap:3px;flex-wrap:wrap;">
                                    <!-- View Button (Always visible) - DARK BLUE -->
                                    <button type="button"
                                            onclick='viewJobOrderDetails(<?= htmlspecialchars($jo_data, ENT_QUOTES) ?>)'
                                            class="txn-btn primary" 
                                            >
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    
                                    <?php if (in_array($val_status, ['Pending Validation', 'Approved']) && !in_array($wf_status, ['In Progress', 'Completed', 'Rejected'])): ?>
                                    <!-- Adjust Button (only before In Progress) - GRAY -->
                                    <button type="button"
                                            onclick='openAdjustJobOrderModal(<?= htmlspecialchars($jo_data, ENT_QUOTES) ?>)'
                                            class="txn-btn secondary" 
                                            >
                                        <i class="fas fa-edit"></i> Adjust
                                    </button>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Row 2: Workflow Actions -->
                                <?php if ($wf_status === 'Rejected'): ?>
                                    <!-- Rejected: Re-encode - GRAY -->
                                    <a href="joborder.php" 
                                       class="txn-btn secondary">
                                        <i class="fas fa-redo"></i> Re-encode
                                    </a>
                                    
                                <?php elseif ($val_status === 'Pending Validation'): ?>
                                    <!-- Pending: Awaiting approval -->
                                    <span style="font-size:12px;color:#94a3b8;font-style:italic;text-align:center;padding:4px 0;">
                                        <i class="fas fa-clock"></i> Awaiting manager approval
                                    </span>
                                    
                                <?php elseif ($wf_status === 'Completed'): ?>
                                    <!-- Completed: Payment or Print Receipt -->
                                    <?php if ($pay_status === 'Paid'): ?>
                                        <!-- Paid = COMPLETE, Print Receipt Only (NO re-issue) - GRAY -->
                                        <button type="button"
                                                onclick="printJobOrderReceipt(<?= (int)$job['id'] ?>,'<?= addslashes($job['job_order_id'] ?? ('#'.$job['id'])) ?>')"
                                                class="txn-btn secondary">
                                            <i class="fas fa-print"></i> Print Receipt
                                        </button>
                                        <span style="font-size:11px;color:#16a34a;font-weight:600;text-align:center;padding:4px 0;display:flex;align-items:center;justify-content:center;gap:4px;">
                                            <i class="fas fa-check-circle"></i> Paid & Complete
                                        </span>
                                    <?php else: ?>
                                        <!-- Pending/Partial = Settle Balance - GREEN -->
                                        <button type="button"
                                                onclick="openPaymentModal(<?= (int)$job['id'] ?>,'<?= addslashes($job['_source'] ?? 'job_orders') ?>',<?= $jo_total ?>,<?= $jo_paid ?>,<?= $jo_balance ?>,'<?= addslashes($job['customer_name'] ?? '') ?>',false,'tracker')"
                                                class="txn-btn success">
                                            <i class="fas fa-money-bill-wave"></i>
                                            <?= $pay_status === 'Partial Payment' ? 'Settle Balance' : 'Mark Paid' ?>
                                        </button>
                                    <?php endif; ?>
                                    
                                <?php else: ?>
                                    <!-- Approved / In Progress: Workflow progression -->
                                    <?php if ($wf_status !== 'In Progress'): ?>
                                    <!-- Start In Progress - DARK BLUE -->
                                    <form method="POST" action="staff_transactions_hub.php?section=merchandise&active_tab=tracker" style="margin:0;">
                                        <input type="hidden" name="jo_action" value="set_in_progress">
                                        <input type="hidden" name="jo_id" value="<?= (int)$job['id'] ?>">
                                        <input type="hidden" name="jo_source" value="<?= htmlspecialchars($job['_source'] ?? 'job_orders') ?>">
                                        <button type="submit" 
                                                class="txn-btn primary">
                                            <i class="fas fa-play"></i> Start In Progress
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    
                                    <!-- IF PAID: Just mark Complete (no payment modal) -->
                                    <?php if ($pay_status === 'Paid'): ?>
                                    <form method="POST" action="staff_transactions_hub.php?section=merchandise&active_tab=tracker" style="margin:0;">
                                        <input type="hidden" name="jo_action" value="set_completed">
                                        <input type="hidden" name="jo_id" value="<?= (int)$job['id'] ?>">
                                        <input type="hidden" name="jo_source" value="<?= htmlspecialchars($job['_source'] ?? 'job_orders') ?>">
                                        <button type="submit" 
                                                class="txn-btn success">
                                            <i class="fas fa-check"></i> Mark Complete
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <!-- ELSE: Complete with payment - GREEN -->
                                    <button type="button"
                                            onclick="openPaymentModal(<?= (int)$job['id'] ?>,'<?= addslashes($job['_source'] ?? 'job_orders') ?>',<?= $jo_total ?>,<?= $jo_paid ?>,<?= $jo_balance ?>,'<?= addslashes($job['customer_name'] ?? '') ?>',true,'tracker')"
                                            class="txn-btn success">
                                        <i class="fas fa-check"></i> Complete & Settle
                                    </button>
                                    <?php endif; ?>
                                    
                                    <?php if (in_array($pay_status, ['Partial Payment','Partial','Unpaid','Pending Payment','Pending']) && $wf_status === 'In Progress'): ?>
                                    <!-- Downpayment option - GREEN -->
                                    <button type="button"
                                            onclick="openPaymentModal(<?= (int)$job['id'] ?>,'<?= addslashes($job['_source'] ?? 'job_orders') ?>',<?= $jo_total ?>,<?= $jo_paid ?>,<?= $jo_balance ?>,'<?= addslashes($job['customer_name'] ?? '') ?>',false,'tracker')"
                                            class="txn-btn success">
                                        <i class="fas fa-coins"></i> Accept Downpayment
                                    </button>
                                    <?php endif; ?>
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

            joState.type        = typeEl    ? typeEl.value    : 'all';
            joState.startDate   = startEl   ? startEl.value   : '';
            joState.endDate     = endEl     ? endEl.value     : '';
            joState.status      = statusEl  ? statusEl.value  : 'all';
            joState.mechanic    = mechEl    ? mechEl.value    : '';
            joState.serviceType = svcEl     ? svcEl.value     : '';
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
            joState.type = 'all'; joState.status = 'all';
            joState.startDate = ''; joState.endDate = '';
            joState.mechanic = ''; joState.serviceType = '';
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

                // Type filter: job_order = native job_orders, combined = merchandise_transactions
                var typeOk = true;
                if (joState.type === 'job_order')  typeOk = (rowSource === 'job_orders');
                if (joState.type === 'combined')    typeOk = (rowSource === 'merchandise_transactions');

                // Date range filter
                var dateOk = true;
                if (joState.startDate && rowDate) dateOk = dateOk && (rowDate >= joState.startDate);
                if (joState.endDate   && rowDate) dateOk = dateOk && (rowDate <= joState.endDate);

                // Mechanic filter (exact match)
                var mechOk = (!joState.mechanic || rowMechanic === joState.mechanic);

                // Service type filter (exact match)
                var svcOk = (!joState.serviceType || rowService === joState.serviceType);

                if (statusOk && typeOk && dateOk && mechOk && svcOk) {
                    visibleRows.push(row);
                } else {
                    row.style.display = 'none';
                }
            });

            // Pagination
            var total      = visibleRows.length;
            var perPage    = joState.per_page;
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
            document.getElementById('pmRefFields').style.display    = ['Card','E-Wallet','E-Fuel Card','Fleet Card'].includes(method) ? 'block' : 'none';
            document.getElementById('pmCreditFields').style.display = method === 'Credit'   ? 'block' : 'none';
            var labels = {'Card':'Card Reference No.','E-Wallet':'E-Wallet Ref No. (GCash/Maya)','E-Fuel Card':'E-Fuel Card ID','Fleet Card':'Fleet Card No.'};
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
            var status  = isPaid ? 'Paid' : 'Partial Payment';
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
             background:rgba(0,0,0,.5);align-items:center;justify-content:center;">
          <div style="background:#fff;border-radius:16px;box-shadow:0 24px 64px rgba(0,0,0,.3);
                      width:100%;max-width:600px;margin:16px;overflow:hidden;animation:pmSlideIn .18s ease;">
            <div style="background:linear-gradient(135deg,#0ea5e9,#0284c7);padding:15px 20px;
                        display:flex;align-items:center;justify-content:space-between;">
              <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:34px;height:34px;background:rgba(255,255,255,.15);border-radius:8px;
                            display:flex;align-items:center;justify-content:center;">
                  <i class="fas fa-clipboard-list" style="color:#fff;font-size:15px;"></i>
                </div>
                <div>
                  <div style="color:#fff;font-weight:700;font-size:14px;">Job Order Details</div>
                  <div id="viewJORef" style="color:#bae6fd;font-size:11px;margin-top:1px;"></div>
                </div>
              </div>
              <button onclick="closeViewJobOrderModal()" style="background:rgba(255,255,255,.15);border:none;color:#fff;
                      width:28px;height:28px;border-radius:6px;font-size:17px;cursor:pointer;
                      display:flex;align-items:center;justify-content:center;"
                      onmouseover="this.style.background='rgba(255,255,255,.28)'"
                      onmouseout="this.style.background='rgba(255,255,255,.15)'">&times;</button>
            </div>
            <div style="padding:20px;max-height:70vh;overflow-y:auto;">
              <div style="display:grid;gap:14px;">
                <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;align-items:start;">
                  <span style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Customer:</span>
                  <span id="viewJOCustomer" style="font-size:13px;color:#1e293b;font-weight:600;">—</span>
                </div>
                <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;align-items:start;">
                  <span style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Vehicle:</span>
                  <span id="viewJOVehicle" style="font-size:13px;color:#475569;">—</span>
                </div>
                <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;align-items:start;">
                  <span style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Service Type:</span>
                  <span id="viewJOService" style="font-size:13px;color:#475569;">—</span>
                </div>
                <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;align-items:start;">
                  <span style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Parts / Items:</span>
                  <span id="viewJOParts" style="font-size:13px;color:#475569;">—</span>
                </div>
                <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;align-items:start;">
                  <span style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Mechanic:</span>
                  <span id="viewJOMechanic" style="font-size:13px;color:#475569;">—</span>
                </div>
                <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;align-items:start;">
                  <span style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Workflow Status:</span>
                  <span id="viewJOWorkflow" style="font-size:11px;font-weight:700;">—</span>
                </div>
                <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;align-items:start;">
                  <span style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Payment Status:</span>
                  <span id="viewJOPayment" style="font-size:11px;font-weight:700;">—</span>
                </div>
                <div style="border-top:1px solid #e2e8f0;padding-top:14px;display:grid;gap:8px;">
                  <div style="display:flex;justify-content:space-between;align-items:center;">
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
                <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;align-items:start;">
                  <span style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Remarks:</span>
                  <span id="viewJORemarks" style="font-size:12px;color:#475569;font-style:italic;">—</span>
                </div>
                <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;align-items:start;">
                  <span style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Created:</span>
                  <span id="viewJOCreated" style="font-size:12px;color:#64748b;">—</span>
                </div>
              </div>
            </div>
            <div style="padding:15px 20px;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;">
              <button onclick="closeViewJobOrderModal()" style="padding:9px 18px;background:#f1f5f9;color:#475569;
                      border:1px solid #e2e8f0;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">
                Close
              </button>
            </div>
          </div>
        </div>

        <!-- View Merchandise Transaction Modal -->
        <div id="viewMerchandiseModal" style="display:none;position:fixed;inset:0;z-index:9999;
             background:rgba(0,0,0,.5);align-items:center;justify-content:center;">
          <div style="background:#fff;border-radius:16px;box-shadow:0 24px 64px rgba(0,0,0,.3);
                      width:100%;max-width:700px;margin:16px;overflow:hidden;animation:pmSlideIn .18s ease;">
            <div style="background:linear-gradient(135deg,#16a34a,#15803d);padding:15px 20px;
                        display:flex;align-items:center;justify-content:space-between;">
              <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:34px;height:34px;background:rgba(255,255,255,.15);border-radius:8px;
                            display:flex;align-items:center;justify-content:center;">
                  <i class="fas fa-shopping-cart" style="color:#fff;font-size:15px;"></i>
                </div>
                <div>
                  <div style="color:#fff;font-weight:700;font-size:14px;">Merchandise Transaction Details</div>
                  <div id="viewMTxnRef" style="color:#bbf7d0;font-size:11px;margin-top:1px;"></div>
                </div>
              </div>
              <button onclick="closeViewMerchandiseModal()" style="background:rgba(255,255,255,.15);border:none;color:#fff;
                      width:28px;height:28px;border-radius:6px;font-size:17px;cursor:pointer;
                      display:flex;align-items:center;justify-content:center;"
                      onmouseover="this.style.background='rgba(255,255,255,.28)'"
                      onmouseout="this.style.background='rgba(255,255,255,.15)'">&times;</button>
            </div>
            <div style="padding:20px;max-height:70vh;overflow-y:auto;">
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
            <div style="padding:15px 20px;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;gap:10px;">
              <button onclick="closeViewMerchandiseModal()" style="padding:9px 18px;background:#f1f5f9;color:#475569;
                      border:1px solid #e2e8f0;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">
                Close
              </button>
            </div>
          </div>
        </div>

        <!-- Update Status Modal -->
        <div id="updateStatusModal" style="display:none;position:fixed;inset:0;z-index:9999;
             background:rgba(0,0,0,.5);align-items:center;justify-content:center;">
          <div style="background:#fff;border-radius:16px;box-shadow:0 24px 64px rgba(0,0,0,.3);
                      width:100%;max-width:480px;margin:16px;overflow:hidden;animation:pmSlideIn .18s ease;">
            <div style="background:linear-gradient(135deg,#003d7a,#0369a1);padding:15px 20px;
                        display:flex;align-items:center;justify-content:space-between;">
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
              <button onclick="closeUpdateStatusModal()" style="background:rgba(255,255,255,.15);border:none;color:#fff;
                      width:28px;height:28px;border-radius:6px;font-size:17px;cursor:pointer;
                      display:flex;align-items:center;justify-content:center;"
                      onmouseover="this.style.background='rgba(255,255,255,.28)'"
                      onmouseout="this.style.background='rgba(255,255,255,.15)'">&times;</button>
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
                    <option value="In Progress">▶ In Progress</option>
                    <option value="Completed">✓✓ Completed</option>
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
             background:rgba(0,0,0,.5);align-items:center;justify-content:center;">
          <div style="background:#fff;border-radius:16px;box-shadow:0 24px 64px rgba(0,0,0,.3);
                      width:100%;max-width:600px;margin:16px;overflow:hidden;animation:pmSlideIn .18s ease;">
            <div style="background:linear-gradient(135deg,#f59e0b,#d97706);padding:15px 20px;
                        display:flex;align-items:center;justify-content:space-between;">
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
              <button onclick="closeAdjustJobOrderModal()" style="background:rgba(255,255,255,.15);border:none;color:#fff;
                      width:28px;height:28px;border-radius:6px;font-size:17px;cursor:pointer;
                      display:flex;align-items:center;justify-content:center;"
                      onmouseover="this.style.background='rgba(255,255,255,.28)'"
                      onmouseout="this.style.background='rgba(255,255,255,.15)'">&times;</button>
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

        <script>
        // ── Job Order Modal Functions ───────────────────────────────────────
        
        // View Job Order Details Modal
        function viewJobOrderDetails(joData) {
            var fmt = function(n){ return '₱' + parseFloat(n).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}); };
            
            document.getElementById('viewJORef').textContent = joData.jo_ref || '—';
            document.getElementById('viewJOCustomer').textContent = joData.customer || '—';
            document.getElementById('viewJOVehicle').textContent = (joData.vehicle_plate || '') + 
                (joData.vehicle_type ? ' (' + joData.vehicle_type + ')' : '') || '—';
            document.getElementById('viewJOService').textContent = joData.service_type || '—';
            document.getElementById('viewJOParts').textContent = joData.parts || '—';
            document.getElementById('viewJOMechanic').textContent = joData.mechanic || 'Unassigned';
            document.getElementById('viewJOWorkflow').textContent = joData.workflow_status || '—';
            document.getElementById('viewJOPayment').textContent = joData.payment_status || '—';
            document.getElementById('viewJOTotal').textContent = fmt(joData.total || 0);
            document.getElementById('viewJOPaid').textContent = fmt(joData.paid || 0);
            document.getElementById('viewJOBalance').textContent = fmt(joData.balance || 0);
            document.getElementById('viewJORemarks').textContent = joData.remarks || 'None';
            document.getElementById('viewJOCreated').textContent = joData.created_at 
                ? new Date(joData.created_at).toLocaleString('en-PH', {dateStyle:'medium', timeStyle:'short'}) 
                : '—';
            
            document.getElementById('viewJobOrderModal').style.display = 'flex';
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
            
            let iframe = document.getElementById('print-receipt-iframe');
            if (!iframe) {
                iframe = document.createElement('iframe');
                iframe.id = 'print-receipt-iframe';
                iframe.style.position = 'fixed';
                iframe.style.right = '0';
                iframe.style.bottom = '0';
                iframe.style.width = '0';
                iframe.style.height = '0';
                iframe.style.border = '0';
                document.body.appendChild(iframe);
            }
            iframe.src = url;
            iframe.onload = function() {
                setTimeout(function() {
                    iframe.contentWindow.focus();
                    iframe.contentWindow.print();
                }, 250);
            };
        }
        
        // Print Merchandise Receipt
        function printMerchandiseReceipt(txnId) {
            var url = 'receipt.php?id=' + encodeURIComponent(txnId) + '&type=merchandise';
            
            let iframe = document.getElementById('print-receipt-iframe');
            if (!iframe) {
                iframe = document.createElement('iframe');
                iframe.id = 'print-receipt-iframe';
                iframe.style.position = 'fixed';
                iframe.style.right = '0';
                iframe.style.bottom = '0';
                iframe.style.width = '0';
                iframe.style.height = '0';
                iframe.style.border = '0';
                document.body.appendChild(iframe);
            }
            iframe.src = url;
            iframe.onload = function() {
                setTimeout(function() {
                    iframe.contentWindow.focus();
                    iframe.contentWindow.print();
                }, 250);
            };
        }
        
        // View Merchandise Transaction Details
        function viewMerchandiseDetails(txnId) {
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
                        document.getElementById('viewMTCustomer').textContent = txn.customer_name || 'Walk-in Customer';
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
        
        // Close modals on outside click
        ['viewJobOrderModal', 'viewMerchandiseModal', 'updateStatusModal', 'adjustJobOrderModal'].forEach(function(modalId) {
            document.getElementById(modalId).addEventListener('click', function(e) {
                if (e.target === this) {
                    this.style.display = 'none';
                }
            });
        });
        </script>

        <script>
        function switchInnerTab(tab) {
            ['merchandise','tracker'].forEach(function(t) {
                var panel = document.getElementById('innerTab_' + t);
                var btn   = document.getElementById('innerTabBtn_' + t);
                if (!panel || !btn) return;
                var themeMap = {merchandise:'green', tracker:'darkblue'};
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



        <?php /* ══════════════════════════════════════════════════════
               SECTION: TRANSACTION HISTORY (formerly Shift History)
        ══════════════════════════════════════════════════════ */ ?>
        <?php elseif ($section === 'history'): ?>

        <div class="txn-section-header">
            <div class="txn-section-title">
                <div>
                    <h1>Transaction History</h1>
                    <p>Your transaction records</p>
                </div>
            </div>
            <div>
                <button type="button" onclick="window.location.href='staff_transactions_hub.php?section=merchandise&amp;active_tab=merchandise'" 
                        class="txn-btn secondary" title="Back to Merchandise/Service Transaction">
                    <i class="fas fa-arrow-left"></i> <span>Back</span>
                </button>
            </div>
        </div>

        <!-- ══ TRANSACTION HISTORY TABLE ══════════════════════════════════ -->
        <?php
        // Merge job orders + merchandise transactions for this staff member's history
        $txn_history = [];

        // Add merchandise transactions
        if (!empty($recent_merch)) {
            foreach ($recent_merch as $mt) {
                $txn_type = 'Merchandise Only';
                $jo_svc = $mt['job_order_service'] ?? '';
                if (!empty($jo_svc) && trim($jo_svc) !== '') {
                    $has_items = !empty($mt['products']) && $mt['products'] !== '—';
                    $txn_type = $has_items ? 'Combined' : 'Job Order Only';
                }
                $txn_history[] = [
                    'id'             => $mt['id'],
                    'transaction_id' => $mt['transaction_id'] ?? ('#MT-'.$mt['id']),
                    'type'           => $txn_type,
                    'customer'       => $mt['customer_name'] ?? 'Walk-in Customer',
                    'amount'         => (float)($mt['total_amount'] ?? 0),
                    'payment_method' => $mt['payment_method'] ?? '—',
                    'date'           => $mt['transaction_date'] ?? '',
                    'status'         => $mt['status'] ?? '—',
                    'source'         => 'merchandise',
                ];
            }
        }

        // Sort by date descending
        usort($txn_history, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });
        ?>

        <div class="txn-card" style="margin-top:20px;">
            <div class="txn-card-header">
                <i class="fas fa-history" style="color:#003d7a;"></i>
                <h3>Transaction History</h3>
            </div>
            <div class="txn-card-body" style="padding:0;">

                <!-- Filter Tabs -->
                <div style="display:flex;gap:0;border-bottom:2px solid #e2e8f0;padding:0 16px;flex-wrap:wrap;">
                    <button onclick="filterTxnHistory('all')" id="thTab_all"
                            style="padding:10px 18px;font-size:12px;font-weight:700;border:none;background:none;
                                   color:#003d7a;border-bottom:2px solid #003d7a;cursor:pointer;margin-bottom:-2px;">
                        All
                    </button>
                    <button onclick="filterTxnHistory('job')" id="thTab_job"
                            style="padding:10px 18px;font-size:12px;font-weight:600;border:none;background:none;
                                   color:#64748b;border-bottom:2px solid transparent;cursor:pointer;margin-bottom:-2px;">
                        Job Order Only
                    </button>
                    <button onclick="filterTxnHistory('merch')" id="thTab_merch"
                            style="padding:10px 18px;font-size:12px;font-weight:600;border:none;background:none;
                                   color:#64748b;border-bottom:2px solid transparent;cursor:pointer;margin-bottom:-2px;">
                        Merchandise Only
                    </button>
                    <button onclick="filterTxnHistory('combined')" id="thTab_combined"
                            style="padding:10px 18px;font-size:12px;font-weight:600;border:none;background:none;
                                   color:#64748b;border-bottom:2px solid transparent;cursor:pointer;margin-bottom:-2px;">
                        Combined
                    </button>
                </div>

                <?php if (empty($txn_history)): ?>
                <div style="text-align:center;padding:48px;color:#94a3b8;">
                    <i class="fas fa-receipt" style="font-size:32px;display:block;margin-bottom:10px;"></i>
                    No transactions found for this period.
                </div>
                <?php else: ?>
                <div style="overflow-x:auto;">
                <table class="txn-table" style="width:100%;table-layout:fixed;word-wrap:break-word;">
                    <thead>
                        <tr>
                            <th style="width:160px;">Transaction ID</th>
                            <th style="width:160px;">Transaction Type</th>
                            <th>Customer</th>
                            <th style="width:120px;">Amount</th>
                            <th style="width:130px;">Payment Method</th>
                            <th style="width:150px;">Date</th>
                        </tr>
                    </thead>
                    <tbody id="thTableBody">
                    <?php foreach ($txn_history as $txn):
                        $type_lower = strtolower($txn['type']);
                        if (strpos($type_lower, 'combined') !== false) {
                            $type_color = '#6f42c1'; $type_bg = '#f3e8ff'; $type_key = 'combined';
                        } elseif (strpos($type_lower, 'job') !== false) {
                            $type_color = '#b45309'; $type_bg = '#fffbeb'; $type_key = 'job';
                        } else {
                            $type_color = '#15803d'; $type_bg = '#f0fdf4'; $type_key = 'merch';
                        }
                        $date_fmt = '—';
                        if (!empty($txn['date'])) {
                            try { $date_fmt = (new DateTime($txn['date']))->format('M j, Y g:i A'); } catch (Exception $e) {}
                        }
                    ?>
                    <tr class="th-row" data-type="<?php echo $type_key; ?>">
                        <td style="font-size:11px;font-weight:700;color:#003d7a;font-family:monospace;">
                            <?php echo htmlspecialchars($txn['transaction_id']); ?>
                        </td>
                        <td>
                            <span style="display:inline-block;padding:3px 8px;border-radius:4px;
                                         font-size:11px;font-weight:700;
                                         background:<?php echo $type_bg; ?>;color:<?php echo $type_color; ?>;">
                                <?php echo htmlspecialchars($txn['type']); ?>
                            </span>
                        </td>
                        <td style="font-weight:600;"><?php echo htmlspecialchars($txn['customer']); ?></td>
                        <td style="font-weight:700;color:#003d7a;">&#8369;<?php echo number_format($txn['amount'], 2); ?></td>
                        <td><?php echo htmlspecialchars($txn['payment_method']); ?></td>
                        <td style="font-size:11px;color:#64748b;"><?php echo $date_fmt; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <!-- Pagination -->
                <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;border-top:1px solid #e2e8f0;">
                    <div style="display:flex;align-items:center;gap:7px;">
                        <label style="font-size:12px;white-space:nowrap;">Rows per page:</label>
                        <select id="thPerPage" onchange="thChangePerPage()" class="pag-select">
                            <option value="10" selected>10</option>
                            <option value="20">20</option>
                            <option value="30">30</option>
                            <option value="50">50</option>
                        </select>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <button id="thPrevBtn" onclick="thGoPage(thState.page-1)" class="pag-btn">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <span id="thPageLabel" style="font-size:13px;color:#495057;white-space:nowrap;">Page 1 of 1</span>
                        <button id="thNextBtn" onclick="thGoPage(thState.page+1)" class="pag-btn">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <script>
        (function(){
            var thState = { page: 1, per_page: 10, filter: 'all' };
            var allTabs = ['all','job','merch','combined'];

            function thRender() {
                var rows = Array.from(document.querySelectorAll('#thTableBody .th-row'));
                // Apply filter
                var filtered = rows.filter(function(r) {
                    if (thState.filter === 'all') return true;
                    return (r.dataset.type || '') === thState.filter;
                });
                // Hide all first
                rows.forEach(function(r) { r.style.display = 'none'; });
                // Paginate filtered
                var perPage = thState.per_page;
                var page    = thState.page;
                var total   = filtered.length;
                var totalPages = Math.max(1, Math.ceil(total / perPage));
                if (page > totalPages) { thState.page = page = totalPages; }
                var start = (page - 1) * perPage;
                var end   = start + perPage;
                filtered.forEach(function(r, i) {
                    r.style.display = (i >= start && i < end) ? '' : 'none';
                });
                var lbl  = document.getElementById('thPageLabel');
                var prev = document.getElementById('thPrevBtn');
                var next = document.getElementById('thNextBtn');
                if (lbl)  lbl.textContent = 'Page ' + page + ' of ' + totalPages;
                if (prev) { prev.disabled = (page <= 1); prev.style.opacity = (page <= 1) ? '0.4' : '1'; }
                if (next) { next.disabled = (page >= totalPages); next.style.opacity = (page >= totalPages) ? '0.4' : '1'; }
            }

            window.thState = thState;
            window.thGoPage = function(p) {
                var rows = Array.from(document.querySelectorAll('#thTableBody .th-row')).filter(function(r){
                    return thState.filter === 'all' || r.dataset.type === thState.filter;
                });
                var totalPages = Math.max(1, Math.ceil(rows.length / thState.per_page));
                if (p < 1 || p > totalPages) return;
                thState.page = p;
                thRender();
            };
            window.thChangePerPage = function() {
                var sel = document.getElementById('thPerPage');
                if (sel) thState.per_page = parseInt(sel.value);
                thState.page = 1;
                thRender();
            };
            window.filterTxnHistory = function(filter) {
                thState.filter = filter;
                thState.page   = 1;
                // Update tab styling
                allTabs.forEach(function(t) {
                    var btn = document.getElementById('thTab_' + t);
                    if (!btn) return;
                    if (t === filter) {
                        btn.style.color       = '#003d7a';
                        btn.style.fontWeight  = '700';
                        btn.style.borderBottom = '2px solid #003d7a';
                    } else {
                        btn.style.color       = '#64748b';
                        btn.style.fontWeight  = '600';
                        btn.style.borderBottom = '2px solid transparent';
                    }
                });
                thRender();
            };
            thRender();
        })();
        </script>


        <?php /* ══════════════════════════════════════════════════════ */ ?>
        <?php elseif ($section === 'fuel_history'): ?>

        <div class="txn-section-header">
            <div class="txn-section-title">
                <div>
                    <h1>Fuel Transaction History</h1>
                    <p>Your fuel transaction records</p>
                </div>
            </div>
            <div>
                <button type="button" onclick="window.location.href='staff_transactions_hub.php?section=fuel'" 
                        class="txn-btn secondary" title="Back to Fuel Transaction">
                    <i class="fas fa-arrow-left"></i> <span>Back</span>
                </button>
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
                <div style="overflow-x:hidden;">
                <table class="txn-table" style="width:100%; table-layout:fixed; word-wrap:break-word;">
                    <thead>
                        <tr>
                            <th>Txn ID</th>
                            <th>Fuel Type</th>
                            <th>Liters</th>
                            <th>Amount</th>
                            <th>Payment</th>
                            <th>Date</th>
                            <th>Status</th>
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
