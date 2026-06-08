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
// Token = HMAC(user_id|date, sha256(password_hash + app_salt))
// The key is derived from the DB password hash — never exposed to the client.
$_api_token = '';
try {
    $tok_stmt = $pdo->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
    $tok_stmt->execute([$me['id']]);
    $pw_hash    = $tok_stmt->fetchColumn() ?: '';
    $app_salt   = 'petron_fuel_api_2026';
    $token_key  = hash('sha256', $pw_hash . $app_salt);
    $_api_token = hash_hmac('sha256', $me['id'] . '|' . date('Y-m-d'), $token_key);
} catch (Exception $e) { $_api_token = ''; }

$me         = current_user();
$station_id = user_station_id();
$role       = role_key($me['role'] ?? '');

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

// Active sub-section: merchandise | history | fuel | fuel_history
$section = $_GET['section'] ?? 'merchandise';
if (!in_array($section, ['merchandise', 'history', 'fuel', 'fuel_history'])) {
    $section = 'merchandise';
}

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

               -- Price: fuel_pricing (manager-set, active) → fuel_inventory fallback
               COALESCE(
                   (SELECT fp.price_per_liter FROM fuel_pricing fp
                    INNER JOIN fuel_types ftt ON ftt.id = fp.fuel_type_id
                    WHERE fp.station_id = fi.station_id
                      AND LOWER(TRIM(ftt.name)) = LOWER(TRIM(fi.fuel_type))
                      AND fp.is_active = 1
                    ORDER BY fp.effective_date DESC, fp.id DESC LIMIT 1),
                   fi.price_per_liter, 0
               ) AS price_per_liter,

               -- Calibration: fuel_calibration table (technician record, active) → fuel_inventory fallback
               COALESCE(
                   (SELECT fc.calibration_constant FROM fuel_calibration fc
                    WHERE LOWER(TRIM(fc.fuel_type)) = LOWER(TRIM(fi.fuel_type))
                      AND fc.status = 'active'
                    ORDER BY fc.effective_date DESC, fc.id DESC LIMIT 1),
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
try {
    $stmt = $pdo->prepare("SELECT id, name, credit_limit, balance FROM customers WHERE station_id = ? AND status = 'active' ORDER BY name");
    $stmt->execute([$station_id]);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $customers = []; }

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
$mh_filter_shift  = $_GET['mh_shift'] ?? '';
$mh_filter_date   = $_GET['mh_date']  ?? '';
$mh_page          = max(1, (int)($_GET['mh_page'] ?? 1));
$mh_per_page      = isset($_GET['mh_per_page']) && in_array((int)$_GET['mh_per_page'], [10,20,30,50]) ? (int)$_GET['mh_per_page'] : 10;
$mh_offset        = ($mh_page - 1) * $mh_per_page;
$mh_available_shifts = [];

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

        $mh_where  = "WHERE mt.station_id = ? AND mt.staff_id = ?";
        $mh_params = [$station_id, $me['id']];
        if ($mh_filter_shift !== '') { $mh_where .= " AND mt.shift_period = ?"; $mh_params[] = $mh_filter_shift; }
        if ($mh_filter_date  !== '') { $mh_where .= " AND DATE($mh_date_col) = ?"; $mh_params[] = $mh_filter_date; }

        $cnt = $pdo->prepare("SELECT COUNT(*) FROM merchandise_transactions mt $mh_where");
        $cnt->execute($mh_params);
        $mh_total = (int)$cnt->fetchColumn();

        $stmt_mh = $pdo->prepare("
            SELECT mt.id,
                   $mh_txnid_col AS transaction_id,
                   mt.customer_name,
                   mt.total_amount,
                   mt.payment_method,
                   COALESCE(mt.amount_paid, 0)                                    AS amount_paid,
                   COALESCE(mt.balance_due, mt.total_amount)                      AS balance_due,
                   COALESCE(mt.payment_status, 'Pending Payment')                 AS payment_status,
                   $mh_date_col  AS transaction_date,
                   $mh_status_col AS status,
                   mt.shift_name,
                   mt.shift_period
            FROM merchandise_transactions mt
            $mh_where
            ORDER BY $mh_date_col DESC
            LIMIT $mh_per_page OFFSET $mh_offset
        ");
        $stmt_mh->execute($mh_params);
        $mh_recent = $stmt_mh->fetchAll(PDO::FETCH_ASSOC);

        $stmt_sh = $pdo->query("SELECT shift_key, shift_name FROM shift_periods WHERE is_active = 1 ORDER BY sort_order ASC");
        $mh_available_shifts = $stmt_sh ? $stmt_sh->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Exception $e) { 
        $mh_recent = []; 
        $mh_total = 0; 
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

    // ── Shift log from labor_sessions ─────────────────────────────────────
    try {
        $ls_where  = "WHERE ls.user_id = ? AND ls.station_id = ?";
        $ls_params = [$me['id'], $station_id];
        if ($filter_date !== '') {
            $ls_where  .= " AND DATE(ls.start_time) = ?";
            $ls_params[] = $filter_date;
        }
        $stmt = $pdo->prepare("
            SELECT ls.id,
                   ls.start_time,
                   ls.end_time,
                   ls.shift_period,
                   COALESCE(sp.shift_name, ls.shift_period) AS shift_label,
                   TIMESTAMPDIFF(MINUTE, ls.start_time,
                       COALESCE(ls.end_time, NOW())) AS duration_minutes
            FROM labor_sessions ls
            LEFT JOIN shift_periods sp ON sp.shift_key = ls.shift_period
            $ls_where
            ORDER BY ls.start_time DESC
        ");
        $stmt->execute($ls_params);
        $shift_log = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $shift_log = []; }

    // Available shift periods for filter dropdown
    try {
        $stmt = $pdo->query("SELECT shift_key, shift_name FROM shift_periods WHERE is_active = 1 ORDER BY sort_order ASC");
        $available_shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $available_shifts = []; }
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
        'pending validation'  => ['bg' => '#fef9c3', 'color' => '#854d0e', 'border' => '#fde047', 'label' => 'Pending Validation'],
        'pending'             => ['bg' => '#fef9c3', 'color' => '#854d0e', 'border' => '#fde047', 'label' => 'Pending Validation'],
        'verified'            => ['bg' => '#dcfce7', 'color' => '#166534', 'border' => '#86efac', 'label' => 'Verified'],
        'approved'            => ['bg' => '#dcfce7', 'color' => '#166534', 'border' => '#86efac', 'label' => 'Verified'],
        'rejected'            => ['bg' => '#fee2e2', 'color' => '#991b1b', 'border' => '#fca5a5', 'label' => 'Rejected'],
        // Payment statuses
        'paid'                => ['bg' => '#dcfce7', 'color' => '#166534', 'border' => '#86efac', 'label' => 'Paid'],
        'partial payment'     => ['bg' => '#fef9c3', 'color' => '#92400e', 'border' => '#fde68a', 'label' => 'Partial Payment'],
        'pending payment'     => ['bg' => '#ffedd5', 'color' => '#9a3412', 'border' => '#fed7aa', 'label' => 'Pending Payment'],
        'credit transaction'  => ['bg' => '#f3e8ff', 'color' => '#6b21a8', 'border' => '#d8b4fe', 'label' => 'Credit Transaction'],
        'credit'              => ['bg' => '#f3e8ff', 'color' => '#6b21a8', 'border' => '#d8b4fe', 'label' => 'Credit Transaction'],
        'unpaid'              => ['bg' => '#fee2e2', 'color' => '#991b1b', 'border' => '#fca5a5', 'label' => 'Unpaid'],
        // Workflow statuses
        'in progress'         => ['bg' => '#dbeafe', 'color' => '#1e40af', 'border' => '#93c5fd', 'label' => 'In Progress'],
        'completed'           => ['bg' => '#d1fae5', 'color' => '#065f46', 'border' => '#6ee7b7', 'label' => 'Completed'],
    ];
    $key  = strtolower(trim($status));
    $cfg  = $map[$key] ?? ['bg' => '#f1f5f9', 'color' => '#64748b', 'border' => '#e2e8f0', 'label' => htmlspecialchars($status)];
    return sprintf(
        '<span style="background:%s;color:%s;border:1px solid %s;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap;">%s</span>',
        $cfg['bg'], $cfg['color'], $cfg['border'], $cfg['label']
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
        $jo_src      = $_POST['jo_source'] ?? 'job_orders';
        $tracker_tab = $_POST['tracker_tab'] ?? 'pending';
        $redirect_tab = $_POST['redirect_tab'] ?? 'tracker';   // tracker | merchandise (for MH settle)

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
        // Part 1: native job_orders rows
        $jo_rows = [];
        try {
            $stmt = $pdo->prepare("
                SELECT jo.*,
                       COALESCE(u.name, u.username) AS mechanic_name,
                       COALESCE(cb.name, cb.username) AS created_by_name,
                       'job_orders' AS _source
                FROM job_orders jo
                LEFT JOIN users u  ON u.id = jo.assigned_mechanic_id
                LEFT JOIN users cb ON cb.id = jo.created_by
                WHERE jo.station_id = ?
                ORDER BY jo.created_at DESC
            ");
            $stmt->execute([$station_id]);
            $jo_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $jo_rows = []; }

        // Part 2: merchandise_transactions with job_order/combined type
        $mt_rows = [];
        try {
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
                    '' AS notes,
                    COALESCE(mt.job_order_vehicle_plate, '') AS vehicle_plate,
                    COALESCE(mt.job_order_vehicle_type, '') AS vehicle_type,
                    mt.created_at,
                    COALESCE(mt.job_order_mechanic_name, '') AS mechanic_name,
                    u.name AS created_by_name,
                    mt.payment_method,
                    COALESCE(mt.payment_status, 'Pending Payment') AS payment_status,
                    COALESCE(mt.amount_paid, 0)                    AS amount_paid,
                    COALESCE(mt.balance_due, mt.total_amount)      AS balance_due,
                    NULL AS assigned_mechanic_id,
                    NULL AS customer_id,
                    NULL AS job_order_id,
                    NULL AS job_order_number,
                    NULL AS required_parts,
                    NULL AS additional_notes,
                    NULL AS shift_id,
                    mt.updated_at,
                    'merchandise_transactions' AS _source
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
/* ═══════════════════════════════════════════════════════════════
   TRANSACTIONS HUB — Page-level styles
   Uses existing Petron CSS variables from style.css
═══════════════════════════════════════════════════════════════ */

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
    font-size: 20px !important;
    font-weight: 700 !important;
    color: var(--petron-blue) !important;
    margin: 0 !important;
}

.txn-section-title p {
    font-size: 14px;
    color: #666666;
    margin: 3px 0 0;
    text-transform: uppercase;
    font-weight: 500;
    letter-spacing: 0.3px;
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
    overflow: hidden;
    margin-bottom: 16px;
    width: 100%;
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

.txn-card-body { padding: 22px; }

/* ── Form elements ───────────────────────────────────────────── */
.txn-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
    width: 100%;
}

.txn-form-grid.cols-3 { grid-template-columns: 1fr 1fr 1fr; }
.txn-form-grid.cols-1 { grid-template-columns: 1fr; }

.txn-field { display: flex; flex-direction: column; gap: 6px; }

.txn-field label {
    font-size: 12px;
    font-weight: 600;
    color: #475569;
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
    color: #1e293b;
    background: #fff;
    transition: border-color .15s, box-shadow .15s;
    width: 100%;
}

.txn-input:focus, .txn-select:focus {
    outline: none;
    border-color: var(--petron-blue);
    box-shadow: 0 0 0 3px rgba(0,47,108,.1);
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
    gap: 8px;
    padding: 11px 20px;
    border-radius: 9px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    border: none;
    transition: all .18s ease;
    text-decoration: none;
}

.txn-btn.primary {
    background: var(--petron-blue);
    color: #fff;
}
.txn-btn.primary:hover { background: #001f4d; }

.txn-btn.success {
    background: #28a745;
    color: #fff;
}
.txn-btn.success:hover { background: #1e7e34; }

.txn-btn.secondary {
    background: #f1f5f9;
    color: #475569;
    border: 1px solid #e2e8f0;
}
.txn-btn.secondary:hover { background: #e2e8f0; }

.txn-btn.danger {
    background: #fee2e2;
    color: #dc2626;
    border: 1px solid #fca5a5;
}
.txn-btn.danger:hover { background: #fecaca; }

.txn-btn:disabled {
    opacity: .5;
    cursor: not-allowed;
}

.txn-btn.full { width: 100%; }

/* ── Info banner ─────────────────────────────────────────────── */
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
    background: #f8fafc;
    padding: 9px 10px;
    text-align: left;
    font-size: 10px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: .4px;
    border-bottom: 2px solid #e2e8f0;
    white-space: nowrap;
}

.txn-table td {
    padding: 9px 10px;
    border-bottom: 1px solid #f1f5f9;
    color: #334155;
    vertical-align: middle;
    font-size: 12px;
}

.txn-table tr:hover td { background: #f8fafc; }

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
                        style="display:inline-flex;align-items:center;gap:6px;height:36px;padding:8px 14px;background:#6c757d;color:#fff;border-radius:8px;border:none;font-size:13px;font-weight:600;cursor:pointer;" title="Back to Staff Dashboard">
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

        <!-- ══════════════════════════════════════════════════════════════
             FILTER BAR — applies to Today's Entries table below
        ══════════════════════════════════════════════════════════════ -->
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px 18px;margin-bottom:16px;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <form method="GET" action="staff_transactions_hub.php" id="fuelFiltersForm">
                <input type="hidden" name="section" value="fuel">

                <!-- Filter chips row -->
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">

                    <!-- Date From -->
                    <div style="display:flex;flex-direction:column;gap:4px;">
                        <label style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">
                            <i class="fas fa-calendar-day" style="margin-right:3px;"></i>From
                        </label>
                        <input type="date" name="date_from"
                               value="<?= htmlspecialchars($filter_date_from) ?>"
                               style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;color:#1e293b;background:#fff;width:100%;">
                    </div>

                    <!-- Date To -->
                    <div style="display:flex;flex-direction:column;gap:4px;">
                        <label style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">
                            <i class="fas fa-calendar-day" style="margin-right:3px;"></i>To
                        </label>
                        <input type="date" name="date_to"
                               value="<?= htmlspecialchars($filter_date_to) ?>"
                               style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;color:#1e293b;background:#fff;width:100%;">
                    </div>

                    <!-- Fuel Type -->
                    <div style="display:flex;flex-direction:column;gap:4px;">
                        <label style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">
                            <i class="fas fa-gas-pump" style="margin-right:3px;"></i>Fuel Type
                        </label>
                        <select name="fuel_type"
                                style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;color:#1e293b;background:#fff;width:100%;">
                            <option value="">All Types</option>
                            <?php foreach ($fuel_types as $ft_opt): ?>
                            <option value="<?= htmlspecialchars($ft_opt['fuel_type']) ?>"
                                <?= $filter_fuel_type === $ft_opt['fuel_type'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ft_opt['fuel_type']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Staff Name / Cashier -->
                    <div style="display:flex;flex-direction:column;gap:4px;">
                        <label style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">
                            <i class="fas fa-user" style="margin-right:3px;"></i>Staff / Cashier
                        </label>
                        <select name="staff_id"
                                style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;color:#1e293b;background:#fff;width:100%;">
                            <option value="">All Staff</option>
                            <?php foreach ($staff_list as $sl): ?>
                            <option value="<?= (int)$sl['id'] ?>"
                                <?= (string)$filter_staff_id === (string)$sl['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sl['name'] ?: $sl['username']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Status — pulled from DB distinct values -->
                    <div style="display:flex;flex-direction:column;gap:4px;">
                        <label style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">
                            <i class="fas fa-flag" style="margin-right:3px;"></i>Status
                        </label>
                        <select name="status"
                                style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;color:#1e293b;background:#fff;width:100%;">
                            <option value="">All Statuses</option>
                            <?php
                            // Pull distinct statuses from DB
                            $db_statuses = [];
                            try {
                                $ss = $pdo->prepare("SELECT DISTINCT status FROM fuel_transactions WHERE station_id = ? AND status IS NOT NULL AND status != '' ORDER BY status");
                                $ss->execute([$station_id]);
                                $db_statuses = $ss->fetchAll(PDO::FETCH_COLUMN);
                            } catch (Exception $e) {}
                            // Ensure standard statuses always appear even if no data yet
                            $std_statuses = ['Pending Validation','Approved','Verified','Adjusted','Rejected'];
                            $all_statuses = array_unique(array_merge($std_statuses, $db_statuses));
                            foreach ($all_statuses as $st):
                            ?>
                            <option value="<?= htmlspecialchars($st) ?>" <?= $filter_status === $st ? 'selected' : '' ?>>
                                <?= htmlspecialchars($st) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Shift Period — from DB only -->
                    <div style="display:flex;flex-direction:column;gap:4px;">
                        <label style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">
                            <i class="fas fa-clock" style="margin-right:3px;"></i>Shift Period
                        </label>
                        <select name="shift"
                                style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;color:#1e293b;background:#fff;width:100%;">
                            <option value="">All Shifts</option>
                            <?php foreach ($shift_periods as $sp_opt): ?>
                            <option value="<?= htmlspecialchars($sp_opt['shift_key']) ?>"
                                <?= $filter_shift === $sp_opt['shift_key'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sp_opt['shift_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Buttons -->
                    <div style="display:flex;gap:8px;align-items:flex-end;padding-bottom:1px;margin-left:auto;">
                        <button type="submit"
                                style="padding:8px 18px;background:#002f6c;color:#fff;border:none;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;white-space:nowrap;transition:background .15s;"
                                onmouseover="this.style.background='#001f4d'" onmouseout="this.style.background='#002f6c'">
                            <i class="fas fa-search"></i> Apply Filter
                        </button>
                        <a href="staff_transactions_hub.php?section=fuel"
                           style="padding:8px 14px;background:#f1f5f9;color:#475569;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:6px;white-space:nowrap;transition:background .15s;"
                           onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">
                            <i class="fas fa-undo"></i> Reset
                        </a>
                    </div>

                </div><!-- /filter chips row -->

                <!-- Active filter summary -->
                <?php
                $active_filters = [];
                if ($filter_date_from && $filter_date_to) {
                    $active_filters[] = date('M j', strtotime($filter_date_from)) . ' – ' . date('M j, Y', strtotime($filter_date_to));
                }
                if ($filter_fuel_type) $active_filters[] = $filter_fuel_type;
                if ($filter_staff_id) {
                    foreach ($staff_list as $sl) {
                        if ((string)$sl['id'] === (string)$filter_staff_id) {
                            $active_filters[] = $sl['name'] ?: $sl['username'];
                            break;
                        }
                    }
                }
                if ($filter_status) $active_filters[] = $filter_status;
                if ($filter_shift) {
                    foreach ($shift_periods as $sp_opt) {
                        if ($sp_opt['shift_key'] === $filter_shift) {
                            $active_filters[] = $sp_opt['shift_name'];
                            break;
                        }
                    }
                }
                if (!empty($active_filters)):
                ?>
                <div style="margin-top:10px;font-size:11px;color:#64748b;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                    <i class="fas fa-filter" style="color:#94a3b8;"></i>
                    <span>Active:</span>
                    <?php foreach ($active_filters as $af): ?>
                    <span style="background:#eff6ff;color:#1d4ed8;padding:2px 9px;border-radius:20px;font-weight:600;">
                        <?= htmlspecialchars($af) ?>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

            </form>
        </div><!-- /filter bar -->

        <style>
        /* ── Fuel Encoding Table ─────────────────────────────────── */
        .fet-wrap { overflow-x:hidden; }

        .fet {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            
        }

        /* Header */
        .fet thead tr {
            background: #f1f5f9;
        }
        .fet th {
            padding: 11px 14px;
            text-align: left;
            font-size: 11px;
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
            padding: 8px 16px;
            background: #002f6c;
            color: #fff;
            border: none;
            border-radius: 7px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background .15s;
        }
        .fet-submit-btn:hover   { background: #001f4d; }
        .fet-submit-btn:disabled { opacity: .5; cursor: not-allowed; }

        .fet-reset-btn {
            padding: 8px 12px;
            background: #f1f5f9;
            color: #475569;
            border: 1.5px solid #e2e8f0;
            border-radius: 7px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: background .15s;
        }
        .fet-reset-btn:hover { background: #e2e8f0; }

        /* Row message */
        .fet-row-msg {
            font-size: 11px;
            display: none;
            white-space: nowrap;
        }
        </style>

        <!-- ── Fuel Sub-tabs ─────────────────────────────── -->
        <div style="display:flex;gap:0;border-bottom:2px solid #e2e8f0;margin-bottom:20px;padding:0 4px;">
            <button onclick="switchFuelSubTab('encode')" id="fuelSubTabBtn_encode"
                    style="padding:9px 18px;border:none;background:#fff;border-bottom:2px solid #002F6C;
                           margin-bottom:-2px;font-size:13px;font-weight:700;color:#002F6C;
                           cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s;">
                <i class="fas fa-edit"></i> Encode Meter Readings
            </button>
            <button onclick="switchFuelSubTab('readings')" id="fuelSubTabBtn_readings"
                    style="padding:9px 18px;border:none;background:#f8fafc;border-bottom:2px solid transparent;
                           margin-bottom:-2px;font-size:13px;font-weight:500;color:#64748b;
                           cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s;">
                <i class="fas fa-history"></i> Today's Meter Readings
            </button>
        </div>

        <div class="txn-card" style="margin-bottom:20px;" id="encodeCard">
            <div class="txn-card-header" style="background:#f0f4ff;">
                <i class="fas fa-table" style="color:var(--petron-blue);"></i>
                <h3>Encode Meter Readings</h3>
                <span style="margin-left:auto;font-size:11px;color:#64748b;font-weight:500;">
                    <?= date('F j, Y') ?> &nbsp;|&nbsp; <?= htmlspecialchars($fuel_shift_name) ?>
                </span>
            </div>

            <?php /* ── Hidden forms for each fuel row — placed OUTSIDE the table.
                        Inputs inside the table rows use form="fuelForm_..." to associate.
                        Putting <form> inside <td>/<tr> is invalid HTML; browsers eject it
                        from the table, breaking FormData collection. */ ?>
            <?php 
            // Tanker configuration per fuel type - SAME AS TABLE CONFIG
            // ORDER MATTERS: Check longer/more specific names first to avoid partial matches
            $tanker_config_forms = [
                'xcs plus' => [
                    ['name' => 'XCS Plus', 'tankers' => [1, 2, 3, 4], 'price_key' => 'xcs plus']
                ],
                'turbo diesel' => [
                    ['name' => 'Turbo Diesel', 'tankers' => [1, 2], 'price_key' => 'turbo diesel']
                ],
                'xtra advance' => [
                    ['name' => 'XTRA Advance 1', 'tankers' => [1, 2], 'price_key' => 'xtra advance'],
                    ['name' => 'XTRA Advance 2', 'tankers' => [3, 4], 'price_key' => 'xtra advance']
                ],
                'xtra unl' => [
                    ['name' => 'XTRA UNL 1', 'tankers' => [1, 2], 'price_key' => 'xtra unl'],
                    ['name' => 'XTRA UNL 2', 'tankers' => [3, 4], 'price_key' => 'xtra unl']
                ],
                'diesel' => [
                    ['name' => 'Diesel 1', 'tankers' => [1, 2, 3, 4], 'price_key' => 'diesel'],
                    ['name' => 'Diesel 2', 'tankers' => [5, 6], 'price_key' => 'diesel']
                ],
                'xcs' => [
                    ['name' => 'XCS 1', 'tankers' => [1, 2], 'price_key' => 'xcs'],
                    ['name' => 'XCS 2', 'tankers' => [3, 4], 'price_key' => 'xcs']
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

            <div class="fet-wrap" style="overflow-x:auto;">
                <table class="fet" style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="background:#f8fafc;">
                            <th rowspan="2" style="border:1px solid #e2e8f0;padding:12px;vertical-align:middle;min-width:120px;font-weight:700;font-size:13px;">NAME</th>
                            <th colspan="6" style="border:1px solid #e2e8f0;padding:8px;text-align:center;font-size:14px;font-weight:700;color:#002F6C;">METER READING</th>
                            <th rowspan="2" style="border:1px solid #e2e8f0;padding:12px;vertical-align:middle;min-width:100px;font-weight:700;font-size:11px;">TOTAL<br>LITERS</th>
                            <th rowspan="2" style="border:1px solid #e2e8f0;padding:12px;vertical-align:middle;min-width:120px;font-weight:700;font-size:11px;">NOTES</th>
                        </tr>
                        <tr style="background:#f8fafc;">
                            <th style="border:1px solid #e2e8f0;padding:8px;text-align:center;font-size:11px;font-weight:700;color:#64748b;">BEGINNING</th>
                            <th style="border:1px solid #e2e8f0;padding:8px;text-align:center;font-size:11px;font-weight:700;color:#64748b;">ENDING</th>
                            <th style="border:1px solid #e2e8f0;padding:8px;text-align:center;font-size:11px;font-weight:700;color:#64748b;">CAL</th>
                            <th style="border:1px solid #e2e8f0;padding:8px;text-align:center;font-size:11px;font-weight:700;color:#64748b;">VOLUME<br>LITERS</th>
                            <th style="border:1px solid #e2e8f0;padding:8px;text-align:center;font-size:11px;font-weight:700;color:#64748b;">PRICE</th>
                            <th style="border:1px solid #e2e8f0;padding:8px;text-align:center;font-size:11px;font-weight:700;color:#64748b;">AMOUNT</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php 
                    // Tanker configuration per fuel type - THIS CONTROLS THE DISPLAY
                    // ORDER MATTERS: Check longer/more specific names first to avoid partial matches
                    $tanker_config = [
                        'xcs plus' => [
                            ['name' => 'XCS Plus', 'tankers' => [1, 2, 3, 4], 'price_key' => 'xcs plus']
                        ],
                        'turbo diesel' => [
                            ['name' => 'Turbo Diesel', 'tankers' => [1, 2], 'price_key' => 'turbo diesel']
                        ],
                        'xtra advance' => [
                            ['name' => 'XTRA Advance 1', 'tankers' => [1, 2], 'price_key' => 'xtra advance'],
                            ['name' => 'XTRA Advance 2', 'tankers' => [3, 4], 'price_key' => 'xtra advance']
                        ],
                        'xtra unl' => [
                            ['name' => 'XTRA UNL 1', 'tankers' => [1, 2], 'price_key' => 'xtra unl'],
                            ['name' => 'XTRA UNL 2', 'tankers' => [3, 4], 'price_key' => 'xtra unl']
                        ],
                        'diesel' => [
                            ['name' => 'Diesel 1', 'tankers' => [1, 2, 3, 4], 'price_key' => 'diesel'],
                            ['name' => 'Diesel 2', 'tankers' => [5, 6], 'price_key' => 'diesel']
                        ],
                        'xcs' => [
                            ['name' => 'XCS 1', 'tankers' => [1, 2], 'price_key' => 'xcs'],
                            ['name' => 'XCS 2', 'tankers' => [3, 4], 'price_key' => 'xcs']
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
                    ?>
                    <tr id="fuelRow_<?= $ft_id ?>" style="border-bottom:1px solid #e2e8f0;">
                        <!-- NAME Column (with tanker number) -->
                        <td style="border:1px solid #e2e8f0;padding:10px;">
                            <div style="display:flex;align-items:center;gap:8px;">
                                <div style="width:24px;height:24px;border-radius:50%;background:<?= $ft_color ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    <i class="fas <?= $ft_icon ?>" style="color:#fff;font-size:10px;"></i>
                                </div>
                                <div style="font-weight:700;font-size:12px;color:<?= $ft_color ?>;"><?= $display_name ?></div>
                            </div>
                        </td>

                        <!-- BEGINNING Column -->
                        <td style="border:1px solid #e2e8f0;padding:6px;">
                            <input type="number"
                                   form="fuelForm_<?= $ft_id ?>"
                                   name="beginning_reading"
                                   id="beginning_<?= $ft_id ?>"
                                   style="width:110px;padding:8px;font-size:12px;border:1px solid #cbd5e1;border-radius:4px;text-align:right;"
                                   step="0.01" min="0"
                                   placeholder="0.00"
                                   autocomplete="off"
                                   oninput="updateFuelCalc('<?= $ft_id ?>', <?= $price_per_liter ?>)">
                        </td>
                        
                        <!-- ENDING Column -->
                        <td style="border:1px solid #e2e8f0;padding:6px;">
                            <input type="number"
                                   form="fuelForm_<?= $ft_id ?>"
                                   name="ending_reading"
                                   id="ending_<?= $ft_id ?>"
                                   style="width:110px;padding:8px;font-size:12px;border:2px solid <?= $ft_color ?>;border-radius:4px;text-align:right;font-weight:700;"
                                   step="0.01" min="0"
                                   placeholder="0.00"
                                   required
                                   autocomplete="off"
                                   oninput="updateFuelCalc('<?= $ft_id ?>', <?= $price_per_liter ?>)">
                        </td>
                        
                        <!-- CAL (Calibration) Column -->
                        <td style="border:1px solid #e2e8f0;padding:6px;">
                            <input type="number"
                                   form="fuelForm_<?= $ft_id ?>"
                                   name="calibration"
                                   id="cal_<?= $ft_id ?>"
                                   style="width:80px;padding:8px;font-size:12px;border:1px solid #cbd5e1;border-radius:4px;text-align:right;"
                                   step="0.01" min="0"
                                   value="0.00"
                                   placeholder="0.00"
                                   autocomplete="off"
                                   oninput="updateFuelCalc('<?= $ft_id ?>', <?= $price_per_liter ?>)">
                        </td>
                        
                        <!-- VOLUME LITERS Column (Auto-calculated: Ending - Beginning - CAL) -->
                        <td style="border:1px solid #e2e8f0;padding:6px;background:#fef3c7;">
                            <input type="text"
                                   id="volume_<?= $ft_id ?>"
                                   style="width:90px;padding:8px;font-size:12px;background:#fef08a;border:2px solid #fbbf24;border-radius:4px;text-align:right;font-weight:800;color:#92400e;"
                                   value="0.00"
                                   readonly>
                            <input type="hidden"
                                   form="fuelForm_<?= $ft_id ?>"
                                   name="volume_liters"
                                   id="volume_value_<?= $ft_id ?>"
                                   value="0.00">
                        </td>
                        
                        <!-- PRICE Column (Visible, not editable) -->
                        <td style="border:1px solid #e2e8f0;padding:6px;background:#e0f2fe;">
                            <input type="text"
                                   style="width:80px;padding:8px;font-size:12px;background:#dbeafe;border:1px solid #60a5fa;border-radius:4px;text-align:right;font-weight:700;color:#1e40af;"
                                   value="₱<?= number_format($price_per_liter, 2) ?>"
                                   readonly>
                            <input type="hidden"
                                   form="fuelForm_<?= $ft_id ?>"
                                   name="price_per_liter"
                                   value="<?= $price_per_liter ?>">
                        </td>
                        
                        <!-- AMOUNT Column (Auto-calculated: Volume × Price) -->
                        <td style="border:1px solid #e2e8f0;padding:6px;background:#f0f9ff;">
                            <input type="text"
                                   id="amount_<?= $ft_id ?>"
                                   style="width:110px;padding:8px;font-size:12px;background:#e0f2fe;border:2px solid #7dd3fc;border-radius:4px;text-align:right;font-weight:800;color:#0369a1;"
                                   value="₱0.00"
                                   readonly>
                            <input type="hidden"
                                   form="fuelForm_<?= $ft_id ?>"
                                   name="total_amount"
                                   id="amount_value_<?= $ft_id ?>"
                                   value="0.00">
                        </td>

                        <!-- TOTAL LITERS Column (Same as VOLUME for single row) -->
                        <td style="border:1px solid #e2e8f0;padding:6px;background:#dcfce7;">
                            <input type="text"
                                   id="total_<?= $ft_id ?>"
                                   style="width:90px;padding:8px;font-size:12px;background:#bbf7d0;border:2px solid #4ade80;border-radius:4px;text-align:right;font-weight:800;color:#15803d;"
                                   value="0.00 L"
                                   readonly>
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
                        class="fet-reset-btn"
                        style="padding:10px 20px;font-size:13px;font-weight:600;">
                    <i class="fas fa-undo"></i> Reset All
                </button>
                <button type="button"
                        onclick="submitAllFuelRows()"
                        class="fet-submit-btn"
                        style="padding:10px 24px;font-size:13px;font-weight:700;">
                    <i class="fas fa-paper-plane"></i> Submit All Readings
                </button>
            </div>

        </div><!-- /txn-card -->

        <?php endif; ?>

        <!-- ── TODAY'S ENTRIES — Meter Reading Table (Table A) ──────────── -->
        <div class="txn-card" id="todayEntriesCard" style="margin-top:8px; margin-bottom:80px;">
            <div class="txn-card-header" style="background:#fff; border-bottom:1px solid #e2e8f0;">
                <i class="fas fa-tachometer-alt" style="color:var(--petron-blue);"></i>
                <h3>Today's Meter Readings</h3>
                <span style="margin-left:auto;font-size:11px;color:#64748b;font-weight:500;">
                    Your submissions for <?= date('F j, Y') ?> — pending manager validation
                </span>
            </div>
            <div id="todayEntriesBody" style="padding:0;">
                <div style="text-align:center;padding:32px;color:#94a3b8;font-size:13px;">
                    <i class="fas fa-spinner fa-spin" style="font-size:22px;display:block;margin-bottom:8px;"></i>
                    Loading today's entries…
                </div>
            </div>
        </div>

        <script>
        // ── Fuel Sub-tab Switcher ───────────────────────────────────────────────
        function switchFuelSubTab(tab) {
            var isReadings = (tab === 'readings');
            var encodeCard = document.getElementById('encodeCard');
            var todayCard  = document.getElementById('todayEntriesCard');
            var encodeBtn  = document.getElementById('fuelSubTabBtn_encode');
            var readingsBtn = document.getElementById('fuelSubTabBtn_readings');
            if (!encodeCard || !todayCard || !encodeBtn || !readingsBtn) return;

            encodeCard.style.display = isReadings ? 'none' : 'block';
            todayCard.style.display  = isReadings ? 'block' : 'none';

            encodeBtn.style.fontWeight   = isReadings ? '500' : '700';
            encodeBtn.style.color        = isReadings ? '#64748b' : '#002F6C';
            encodeBtn.style.background   = isReadings ? '#f8fafc' : '#fff';
            encodeBtn.style.borderBottom = isReadings ? '2px solid transparent' : '2px solid #002F6C';

            readingsBtn.style.fontWeight   = isReadings ? '700' : '500';
            readingsBtn.style.color        = isReadings ? '#002F6C' : '#64748b';
            readingsBtn.style.background   = isReadings ? '#fff' : '#f8fafc';
            readingsBtn.style.borderBottom = isReadings ? '2px solid #002F6C' : '2px solid transparent';

            if (isReadings) {
                refreshTodayEntries();
            }

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

        // Auto initialize on DOMContentLoaded if fuel_tab parameter or filters are present
        document.addEventListener('DOMContentLoaded', function() {
            var defaultTab = '<?= $fuel_tab_default ?>';
            switchFuelSubTab(defaultTab);
        });

        // ── Fuel Transaction Filters ───────────────────────────────────────────
        function resetFuelFilters() {
            window.location.href = 'staff_transactions_hub.php?section=fuel';
        }

        // ── Live calculation per row (Beginning → Ending → CAL → Volume → Amount) ──────────────────────────
        function updateFuelCalc(ftId, pricePerLiter) {
            const beginningEl = document.getElementById(`beginning_${ftId}`);
            const endingEl    = document.getElementById(`ending_${ftId}`);
            const calEl       = document.getElementById(`cal_${ftId}`);
            const volumeEl    = document.getElementById(`volume_${ftId}`);
            const volumeValueEl = document.getElementById(`volume_value_${ftId}`);
            const amountEl    = document.getElementById(`amount_${ftId}`);
            const amountValueEl = document.getElementById(`amount_value_${ftId}`);
            const totalEl     = document.getElementById(`total_${ftId}`);
            
            if (!beginningEl || !endingEl || !calEl || !volumeEl || !amountEl || !totalEl) return;
            
            const beginning = parseFloat(beginningEl.value) || 0;
            const ending = parseFloat(endingEl.value) || 0;
            const cal = parseFloat(calEl.value) || 0;
            
            // Calculate volume liters: Ending - Beginning - CAL
            let volume = 0;
            if (ending > 0 && ending >= beginning) {
                volume = Math.max(0, ending - beginning - cal);
            }
            
            // Calculate amount: Volume × Price per Liter
            const amount = volume * pricePerLiter;
            
            // Update displays
            volumeEl.value = volume.toFixed(2);
            volumeValueEl.value = volume.toFixed(2);
            
            amountEl.value = '₱' + amount.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
            amountValueEl.value = amount.toFixed(2);
            
            // Total liters = same as volume for single row entry
            totalEl.value = volume.toFixed(2) + ' L';
        }

        // ── AJAX submit per fuel row (Updated for tanker data) ──────────────────────────────────────────
        async function submitFuelCard(event, ftId) {
            event.preventDefault();

            const form      = document.getElementById('fuelForm_' + ftId);
            const submitBtn = document.getElementById('submitBtn_' + ftId);
            const msgEl     = document.getElementById('cardMsg_'   + ftId);

            if (!form) return false;

            // Collect all tanker readings from form inputs
            const formData = new FormData(form);
            let hasPresentReading = false;
            
            // Check if at least one tanker has a present reading
            for (let pair of formData.entries()) {
                if (pair[0].startsWith('tanker_present_') && pair[1] && parseFloat(pair[1]) > 0) {
                    hasPresentReading = true;
                    break;
                }
            }
            
            if (!hasPresentReading) {
                showRowMsg(msgEl, 'error', 'Please enter at least one present reading.');
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
                    const msg = `Meter Reading submitted successfully!`;
                    
                    const toast = document.createElement('div');
                    toast.style.position = 'fixed';
                    toast.style.top = '80px';
                    toast.style.left = '50%';
                    toast.style.transform = 'translateX(-50%)';
                    toast.style.backgroundColor = '#d4edda';
                    toast.style.color = '#155724';
                    toast.style.border = '1px solid #c3e6cb';
                    toast.style.padding = '12px 24px';
                    toast.style.borderRadius = '8px';
                    toast.style.fontWeight = '600';
                    toast.style.zIndex = '9999';
                    toast.style.boxShadow = '0 4px 12px rgba(0,0,0,0.1)';
                    toast.style.transition = 'opacity 0.3s ease';
                    toast.innerHTML = `<i class="fas fa-check-circle" style="color:#28a745; margin-right:8px;"></i> ${msg}`;
                    document.body.appendChild(toast);
                    
                    // Fade out after 3 seconds
                    setTimeout(() => {
                        toast.style.opacity = '0';
                        setTimeout(() => toast.remove(), 300);
                    }, 3000);

                    // Clear any inline message to keep the row clean
                    msgEl.style.display = 'none';
                    msgEl.innerHTML = '';

                    // Clear the present reading
                    const submittedPresent = parseFloat(presentEl.value) || 0;
                    presentEl.value = '';
                    // Update previous reading input so next submit is correct
                    const prevInput = document.getElementById('prev_' + ftId);
                    if (prevInput && submittedPresent > 0) {
                        prevInput.value = submittedPresent;
                    }
                    // Refresh the entries table and switch to it
                    loadTodayEntries();
                    switchFuelSubTab('readings');
                    setTimeout(() => {
                        const todayCard = document.getElementById('todayEntriesCard');
                        if (todayCard) {
                            todayCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
                            todayCard.style.outline = '2px solid #86efac';
                            todayCard.style.borderRadius = '12px';
                            setTimeout(() => { todayCard.style.outline = ''; }, 2000);
                        }
                    }, 600);
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
            const totalEl       = document.getElementById(`total_${ftId}`);
            
            if (beginningEl) beginningEl.value = '';
            if (endingEl) endingEl.value = '';
            if (calEl) calEl.value = '0.00';
            if (volumeEl) volumeEl.value = '0.00';
            if (volumeValueEl) volumeValueEl.value = '0.00';
            if (amountEl) amountEl.value = '₱0.00';
            if (amountValueEl) amountValueEl.value = '0.00';
            if (totalEl) totalEl.value = '0.00 L';
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
            
            alert('All fuel readings have been reset.');
        }

        // ── Submit ALL fuel rows with data ─────────────────────────────────────
        async function submitAllFuelRows() {
            const allForms = document.querySelectorAll('form[id^="fuelForm_"]');
            const formsToSubmit = [];
            
            // Collect forms that have ending reading (required field)
            allForms.forEach(form => {
                const ftId = form.id.replace('fuelForm_', '');
                const endingEl = document.getElementById(`ending_${ftId}`);
                const endingValue = parseFloat(endingEl?.value || 0);
                
                if (endingValue > 0) {
                    formsToSubmit.push({ ftId, form });
                }
            });
            
            if (formsToSubmit.length === 0) {
                alert('No fuel readings to submit. Please enter at least one ending reading.');
                return;
            }
            
            if (!confirm(`Submit ${formsToSubmit.length} fuel reading(s)?`)) return;
            
            let successCount = 0;
            let errorCount = 0;
            const errors = [];
            
            // Submit each form
            for (const {ftId, form} of formsToSubmit) {
                try {
                    const formData = new FormData(form);
                    const response = await fetch('api_fuel_readings.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        successCount++;
                        // Clear the form
                        resetFuelRow(ftId);
                    } else {
                        errorCount++;
                        errors.push(`${ftId}: ${result.message || 'Unknown error'}`);
                    }
                } catch (error) {
                    errorCount++;
                    errors.push(`${ftId}: ${error.message}`);
                }
            }
            
            // Show summary
            let message = `Submitted ${successCount} reading(s) successfully.`;
            if (errorCount > 0) {
                message += `\n${errorCount} reading(s) failed:\n${errors.join('\n')}`;
            }
            alert(message);
            
            // Refresh today's entries if we're on that tab
            if (typeof refreshTodayEntries === 'function') {
                refreshTodayEntries();
            }
        }

        // ── Today's Entries (Table A) — auto-load + refresh after submit ──────
        async function loadTodayEntries() {
            const body = document.getElementById('todayEntriesBody');
            const icon = document.getElementById('refreshIcon');
            if (icon) icon.className = 'fas fa-spinner fa-spin';

            try {
                // Read active filter values from the filter form
                const form      = document.getElementById('fuelFiltersForm');
                const dateFrom  = form?.querySelector('[name="date_from"]')?.value  || '<?= date('Y-m-d') ?>';
                const dateTo    = form?.querySelector('[name="date_to"]')?.value    || '<?= date('Y-m-d') ?>';
                const fuelType  = form?.querySelector('[name="fuel_type"]')?.value  || '';
                const staffId   = form?.querySelector('[name="staff_id"]')?.value   || '';
                const status    = form?.querySelector('[name="status"]')?.value     || '';
                const shift     = form?.querySelector('[name="shift"]')?.value      || '';

                const params = new URLSearchParams({ action: 'summary', date_from: dateFrom, date_to: dateTo, auth_user_id: '<?= (int)$me['id'] ?>' });
                if (fuelType) params.set('fuel_type', fuelType);
                if (staffId)  params.set('staff_id',  staffId);
                if (status)   params.set('status',    status);
                if (shift)    params.set('shift',     shift);

                const url  = `./api_fuel_readings.php?${params.toString()}`;
                const res  = await fetch(url, {credentials:'same-origin'});
                const json = await res.json();

                if (!json.success || !json.meter_readings || json.meter_readings.length === 0) {
                    body.innerHTML = `<div style="text-align:center;padding:32px;color:#94a3b8;font-size:13px;">
                        <i class="fas fa-tachometer-alt" style="font-size:24px;display:block;margin-bottom:8px;"></i>
                        No readings submitted yet today. Encode a reading above to get started.
                    </div>`;
                    if (icon) icon.className = 'fas fa-sync';
                    return;
                }

                window.todayEntriesData = json.meter_readings;
                window.todayEntriesPage = 1;
                renderTodayEntriesTable();
            } catch(e) {
                body.innerHTML = `<div style="text-align:center;padding:24px;color:#ef4444;font-size:13px;">
                    <i class="fas fa-exclamation-circle" style="display:block;margin-bottom:6px;"></i>
                    Could not load entries. Please refresh.
                </div>`;
            }
            if (icon) icon.className = 'fas fa-sync';
        }

        window.todayEntriesPageSize = 10;
        
        function renderTodayEntriesTable() {
            const body = document.getElementById('todayEntriesBody');
            const rows = window.todayEntriesData || [];
            if (rows.length === 0) return;
            
            const totalRows = rows.length;
            const totalPages = Math.max(1, Math.ceil(totalRows / window.todayEntriesPageSize));
            if (window.todayEntriesPage > totalPages) window.todayEntriesPage = totalPages;
            
            const startIdx = (window.todayEntriesPage - 1) * window.todayEntriesPageSize;
            const endIdx = Math.min(startIdx + window.todayEntriesPageSize, totalRows);
            const pageRows = rows.slice(startIdx, endIdx);

            const statusMap = {
                'pending validation': {color:'#b45309',label:'Pending'},
                'pending':            {color:'#b45309',label:'Pending'},
                'approved':           {color:'#15803d',label:'Approved'},
                'verified':           {color:'#15803d',label:'Verified'},
                'adjusted':           {color:'#1d4ed8',label:'Adjusted'},
                'rejected':           {color:'#b91c1c',label:'Rejected'},
            };
            function badge(s) {
                const k = (s||'').toLowerCase().trim();
                const c = statusMap[k] || {color:'#64748b',label:s||'—'};
                return `<span style="color:${c.color};font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;">${c.label}</span>`;
            }
            function fmt(n,d=2){ return Number(n||0).toLocaleString('en-PH',{minimumFractionDigits:d,maximumFractionDigits:d}); }

            let html = `<div style="max-height:450px; overflow-y:auto; overflow-x:hidden; border-bottom:1px solid #e2e8f0;">
                <table style="width:100%;border-collapse:collapse;font-size:13px;table-layout:fixed;">
                    <thead style="position:sticky; top:0; z-index:10; background:#002F70;">
                        <tr>
                            <th style="padding:10px 13px;text-align:left;font-size:11px;font-weight:700;color:#ffffff;text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid #002255;white-space:nowrap;">Fuel Type</th>
                            <th style="padding:10px 13px;text-align:right;font-size:11px;font-weight:700;color:#ffffff;text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid #002255;white-space:nowrap;">Beginning</th>
                            <th style="padding:10px 13px;text-align:right;font-size:11px;font-weight:700;color:#ffffff;text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid #002255;white-space:nowrap;">Ending</th>
                            <th style="padding:10px 13px;text-align:right;font-size:11px;font-weight:700;color:#ffffff;text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid #002255;white-space:nowrap;">Cal</th>
                            <th style="padding:10px 13px;text-align:right;font-size:11px;font-weight:700;color:#ffffff;text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid #002255;white-space:nowrap;">Liters Sold</th>
                            <th style="padding:10px 13px;text-align:right;font-size:11px;font-weight:700;color:#ffffff;text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid #002255;white-space:nowrap;">Price/L</th>
                            <th style="padding:10px 13px;text-align:right;font-size:11px;font-weight:700;color:#ffffff;text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid #002255;white-space:nowrap;">Amount</th>
                            <th style="padding:10px 13px;text-align:left;font-size:11px;font-weight:700;color:#ffffff;text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid #002255;white-space:nowrap;width:20%;">Shift</th>
                            <th style="padding:10px 13px;text-align:left;font-size:11px;font-weight:700;color:#ffffff;text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid #002255;white-space:nowrap;">Status</th>
                        </tr>
                    </thead>
                    <tbody>`;

            pageRows.forEach(r => {
                const shiftLabel = r.shift_name || (r.shift_period ? r.shift_period.replace(/_/g,' ') : '—');
                html += `<tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:10px 13px;font-weight:700;color:#1e293b;">${r.fuel_type||'—'}</td>
                    <td style="padding:10px 13px;text-align:right;font-variant-numeric:tabular-nums;">${fmt(r.beginning)}</td>
                    <td style="padding:10px 13px;text-align:right;font-variant-numeric:tabular-nums;">${fmt(r.ending)}</td>
                    <td style="padding:10px 13px;text-align:right;font-variant-numeric:tabular-nums;">${fmt(r.cal,3)}</td>
                    <td style="padding:10px 13px;text-align:right;font-weight:700;font-variant-numeric:tabular-nums;">${fmt(r.volume_liters)} L</td>
                    <td style="padding:10px 13px;text-align:right;font-variant-numeric:tabular-nums;">₱${fmt(r.price_per_liter)}</td>
                    <td style="padding:10px 13px;text-align:right;font-weight:700;font-variant-numeric:tabular-nums;">₱${fmt(r.amount)}</td>
                    <td style="padding:10px 13px;font-size:11px;color:#64748b;white-space:nowrap;">${shiftLabel}</td>
                    <td style="padding:10px 13px;">${badge(r.status)}</td>
                </tr>`;
            });

            html += `</tbody></table></div>`;
            
            // Pagination Footer
            html += `
            <div style="display:flex; justify-content:space-between; align-items:center; padding:12px 20px; border-top:1px solid #e2e8f0; background:#fff; border-radius:0 0 12px 12px; font-size:13px; color:#475569;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <label style="margin:0; font-weight:400; color:#475569;">Rows per page:</label>
                    <select onchange="window.todayEntriesPageSize=parseInt(this.value); window.todayEntriesPage=1; renderTodayEntriesTable();" style="padding:4px 24px 4px 8px; border:1px solid #cbd5e1; border-radius:4px; font-size:13px; background:#fff; color:#1e293b; outline:none; cursor:pointer;">
                        <option value="10" ${window.todayEntriesPageSize === 10 ? 'selected' : ''}>10</option>
                        <option value="20" ${window.todayEntriesPageSize === 20 ? 'selected' : ''}>20</option>
                        <option value="30" ${window.todayEntriesPageSize === 30 ? 'selected' : ''}>30</option>
                        <option value="40" ${window.todayEntriesPageSize === 40 ? 'selected' : ''}>40</option>
                        <option value="50" ${window.todayEntriesPageSize === 50 ? 'selected' : ''}>50</option>
                    </select>
                </div>
                <div style="display:flex; align-items:center; gap:8px;">
                    <button onclick="if(window.todayEntriesPage>1){ window.todayEntriesPage--; renderTodayEntriesTable(); }" 
                            style="width:28px; height:28px; background:#fff; border:1px solid #cbd5e1; border-radius:4px; cursor:${window.todayEntriesPage > 1 ? 'pointer' : 'not-allowed'}; color:${window.todayEntriesPage > 1 ? '#475569' : '#cbd5e1'}; display:flex; align-items:center; justify-content:center;">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <span style="color:#475569; font-size:13px; padding:0 4px;">Page ${window.todayEntriesPage} of ${totalPages}</span>
                    <button onclick="if(window.todayEntriesPage<${totalPages}){ window.todayEntriesPage++; renderTodayEntriesTable(); }" 
                            style="width:28px; height:28px; background:#fff; border:1px solid #cbd5e1; border-radius:4px; cursor:${window.todayEntriesPage < totalPages ? 'pointer' : 'not-allowed'}; color:${window.todayEntriesPage < totalPages ? '#475569' : '#cbd5e1'}; display:flex; align-items:center; justify-content:center;">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            </div>`;
            
            body.innerHTML = html;
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
                <button type="button" onclick="window.location.href='staff_dashboard.php'" 
                        style="display:inline-flex;align-items:center;gap:6px;height:36px;padding:8px 14px;background:#6c757d;color:#fff;border-radius:8px;border:none;font-size:13px;font-weight:600;cursor:pointer;" title="Back to Staff Dashboard">
                    <i class="fas fa-arrow-left"></i> <span>Back</span>
                </button>
            </div>
        </div>

        <!-- ── Inner Tabs ─────────────────────────────────────────────── -->
        <div style="display:flex;gap:0;margin-bottom:24px;border-bottom:2px solid #e2e8f0;flex-wrap:wrap;">
            <?php
            $inner_tabs = [
                'merchandise'   => ['label'=>'Merchandise/Service Transaction', 'icon'=>'fa-shopping-cart', 'color'=>'#28a745'],
                'tracker'       => ['label'=>'Job Order Tracker',               'icon'=>'fa-tasks',         'color'=>'#003d7a',
                                    'badge'=> $jo_pending_count > 0 ? $jo_pending_count : null],
            ];
            foreach ($inner_tabs as $tk => $tc):
                $ia = ($active_tab === $tk);
            ?>
            <button onclick="switchInnerTab('<?= $tk ?>')"
                    id="innerTabBtn_<?= $tk ?>"
                    style="padding:11px 22px;border:none;background:<?= $ia ? '#fff' : '#f8fafc' ?>;
                           border-bottom:<?= $ia ? '2px solid '.$tc['color'] : '2px solid transparent' ?>;
                           margin-bottom:-2px;font-size:13px;font-weight:<?= $ia ? '700' : '500' ?>;
                           color:<?= $ia ? $tc['color'] : '#64748b' ?>;cursor:pointer;
                           display:inline-flex;align-items:center;gap:7px;transition:all .15s;white-space:nowrap;">
                <i class="fas <?= $tc['icon'] ?>"></i>
                <?= $tc['label'] ?>
                <?php if (!empty($tc['badge'])): ?>
                <span style="background:<?= $tc['color'] ?>;color:#fff;font-size:10px;font-weight:800;
                             padding:1px 7px;border-radius:20px;"><?= $tc['badge'] ?></span>
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
            <div style="min-width:0;overflow:hidden;">

                <!-- ══ JOB ORDER SECTION (TOP) ══════════════════════════════ -->
                <div class="txn-card" id="joCard">
                    <div class="txn-card-header" style="background:#fffbeb;">
                        <i class="fas fa-tools" style="color:#b45309;"></i>
                        <h3 style="color:#92400e;">Job Order</h3>
                    </div>
                    <div class="txn-card-body">

                        <!-- Customer Details — captured here at the Job Order level -->
                        <div style="font-size:11px;font-weight:700;color:#b45309;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">
                            <i class="fas fa-user" style="margin-right:5px;"></i>Customer Details
                        </div>
                        <div class="txn-form-grid" style="margin-bottom:14px;">
                            <div class="txn-field">
                                <label>First Name <span style="color:#dc2626;">*</span></label>
                                <input type="text" id="joFirstName" class="txn-input"
                                       placeholder="Customer first name"
                                       autocomplete="off">
                            </div>
                            <div class="txn-field">
                                <label>Last Name</label>
                                <input type="text" id="joLastName" class="txn-input"
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
                                    <select id="joVehicleType" class="txn-select" style="flex:1;" onchange="onVehicleTypeChange()">
                                        <option value="">— Loading… —</option>
                                    </select>
                                    <button type="button"
                                            onclick="openAddVehicleModal()"
                                            title="Add a new vehicle type"
                                            style="flex-shrink:0;width:34px;height:34px;border:1.5px solid #e2e8f0;
                                                   background:#f8fafc;border-radius:6px;cursor:pointer;
                                                   font-size:18px;font-weight:700;color:#003d7a;
                                                   display:flex;align-items:center;justify-content:center;
                                                   transition:background .15s,border-color .15s;"
                                            onmouseover="this.style.background='#eff6ff';this.style.borderColor='#003d7a';"
                                            onmouseout="this.style.background='#f8fafc';this.style.borderColor='#e2e8f0';">
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

                        <!-- Service Details -->
                        <div style="font-size:11px;font-weight:700;color:#b45309;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">
                            <i class="fas fa-wrench" style="margin-right:5px;"></i>Service Details
                        </div>
                        <div class="txn-form-grid" style="margin-bottom:14px;">
                            <div class="txn-field">
                                <label>Service Type <span style="color:#dc2626;">*</span></label>
                                <div style="display:flex;gap:6px;align-items:flex-start;">
                                    <select id="joServiceType" class="txn-select" style="flex:1;"
                                            onchange="onJoServiceTypeChange()">
                                        <option value="">— Loading… —</option>
                                    </select>
                                    <!-- Add Service to Cart Button -->
                                    <button type="button"
                                            onclick="addServiceFromFormToCart()"
                                            title="Add Job Order Service to Cart"
                                            style="flex-shrink:0;width:34px;height:34px;border:1.5px solid #7dd3fc;
                                                   background:#e0f2fe;border-radius:6px;cursor:pointer;
                                                   font-size:15px;font-weight:700;color:#0369a1;
                                                   display:flex;align-items:center;justify-content:center;
                                                   transition:background .15s,border-color .15s;"
                                            onmouseover="this.style.background='#bae6fd';this.style.borderColor='#0369a1';"
                                            onmouseout="this.style.background='#e0f2fe';this.style.borderColor='#7dd3fc';">
                                        <i class="fas fa-cart-plus"></i>
                                    </button>
                                    <button type="button"
                                            onclick="openAddServiceModal()"
                                            title="Add a new service type"
                                            style="flex-shrink:0;width:34px;height:34px;border:1.5px solid #e2e8f0;
                                                   background:#f8fafc;border-radius:6px;cursor:pointer;
                                                   font-size:18px;font-weight:700;color:#b45309;
                                                   display:flex;align-items:center;justify-content:center;
                                                   transition:background .15s,border-color .15s;"
                                            onmouseover="this.style.background='#fffbeb';this.style.borderColor='#b45309';"
                                            onmouseout="this.style.background='#f8fafc';this.style.borderColor='#e2e8f0';">
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
                    <div style="display:flex;gap:0;border-bottom:2px solid #e2e8f0;padding:0 16px;">
                        <button onclick="switchMerchTab('form')" id="merchTabBtn_form"
                                style="padding:9px 18px;border:none;background:#fff;border-bottom:2px solid #28a745;
                                       margin-bottom:-2px;font-size:12px;font-weight:700;color:#28a745;
                                       cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s;">
                            <i class="fas fa-shopping-cart"></i> Merchandise
                        </button>
                        <button onclick="switchMerchTab('history')" id="merchTabBtn_history"
                                style="padding:9px 18px;border:none;background:#f8fafc;border-bottom:2px solid transparent;
                                       margin-bottom:-2px;font-size:12px;font-weight:500;color:#64748b;
                                       cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s;">
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
                                <input type="text" id="merchFirstName" class="txn-input"
                                       placeholder="Walk-in Customer"
                                       autocomplete="off">
                            </div>
                            <div class="txn-field">
                                <label>Last Name</label>
                                <input type="text" id="merchLastName" class="txn-input"
                                       placeholder=""
                                       autocomplete="off">
                            </div>
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
                                        <button type="button" onclick="addProductFromFormToCart()" title="Add selected product to cart" style="width:36px;height:36px;background:#e0f2fe;border:1px solid #7dd3fc;border-radius:8px;cursor:pointer;font-size:16px;font-weight:700;color:#0369a1;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                            <i class="fas fa-cart-plus"></i>
                                        </button>
                                        <!-- Register brand new product -->
                                        <button type="button" onclick="openAddProductModal()" title="Add new product to database" style="width:36px;height:36px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;cursor:pointer;font-size:16px;font-weight:700;color:#166534;display:flex;align-items:center;justify-content:center;flex-shrink:0;">+</button>
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
                                <i class="fas fa-undo"></i> Reset All
                            </button>
                            <button type="button" class="txn-btn success" onclick="addToCartWithService()">
                                <i class="fas fa-cart-plus"></i> Add to Cart
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
                                    <label style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">Date</label>
                                    <input type="date" name="date" value="<?= htmlspecialchars($filter_date) ?>"
                                           style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:12px;color:#1e293b;background:#fff;">
                                </div>
                                <div style="display:flex;flex-direction:column;gap:4px;">
                                    <label style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">Shift</label>
                                    <select name="shift" style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:12px;color:#1e293b;background:#fff;">
                                        <option value="">All Shifts</option>
                                        <?php foreach ($available_shifts as $sh): ?>
                                        <option value="<?= htmlspecialchars($sh['shift_key']) ?>" <?= $filter_shift === $sh['shift_key'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($sh['shift_name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" class="txn-btn primary" style="padding:7px 14px;font-size:12px;">
                                    <i class="fas fa-filter"></i> Filter
                                </button>
                                <a href="staff_transactions_hub.php?section=merchandise&mh_open=1" class="txn-btn secondary" style="padding:7px 12px;font-size:12px;">
                                    <i class="fas fa-times"></i> Clear
                                </a>
                                <!-- Export buttons -->
                                <div style="margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                    <a href="../backend/export_staff_transactions.php?type=merchandise&format=excel"
                                       title="Export to Excel"
                                       style="background:#1d6f42;color:#fff;text-decoration:none;display:inline-flex;align-items:center;gap:6px;height:36px;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:600;">
                                        <i class="fas fa-file-excel"></i> Excel
                                    </a>
                                    <a href="../backend/export_staff_transactions.php?type=merchandise&format=csv"
                                       title="Export to CSV"
                                       style="background:#003d7a;color:#fff;text-decoration:none;display:inline-flex;align-items:center;gap:6px;height:36px;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:600;">
                                        <i class="fas fa-file-csv"></i> CSV
                                    </a>
                                    <a href="../backend/export_staff_transactions.php?type=merchandise&format=pdf"
                                       target="_blank"
                                       title="Export to PDF"
                                       style="background:#dc2626;color:#fff;text-decoration:none;display:inline-flex;align-items:center;gap:6px;height:36px;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:600;">
                                        <i class="fas fa-file-pdf"></i> PDF
                                    </a>
                                    <a href="staff_transactions_hub.php?section=merchandise&active_tab=merchandise"
                                       title="Back to Merchandise Form"
                                       style="background:#6c7280;color:#fff;text-decoration:none;display:inline-flex;align-items:center;gap:6px;height:36px;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:600;">
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
                            <div style="width:100%;overflow-x:hidden;">
                            <style>
                            #mhHistoryTable th { padding: 8px 6px; }
                            #mhHistoryTable td { padding: 8px 6px; }
                            </style>
                            <table class="txn-table" id="mhHistoryTable" style="width:100%;table-layout:fixed;">
                                <colgroup>
                                    <col style="width:9%;"><!-- Txn ID -->
                                    <col style="width:12%;"><!-- Customer -->
                                    <col style="width:8%;"><!-- Total -->
                                    <col style="width:8%;"><!-- Method -->
                                    <col style="width:9%;"><!-- Balance Due -->
                                    <col style="width:14%;"><!-- Shift -->
                                    <col style="width:13%;"><!-- Date -->
                                    <col style="width:12%;"><!-- Payment Status -->
                                    <col style="width:15%;"><!-- Actions (increased width) -->
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th style="font-size:14px;">Txn ID</th>
                                        <th style="font-size:14px;">Customer</th>
                                        <th style="font-size:14px;">Total</th>
                                        <th style="font-size:14px;">Method</th>
                                        <th style="font-size:14px;">Balance Due</th>
                                        <th style="font-size:14px;">Shift</th>
                                        <th style="font-size:14px;">Date</th>
                                        <th style="font-size:14px;">Payment Status</th>
                                        <th style="font-size:14px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="mhTableBody">
                                <?php foreach ($mh_recent as $txn): ?>
                                <?php
                                    $mh_pay_status = $txn['payment_status'] ?? $txn['status'] ?? 'Pending Payment';
                                    $mh_balance    = (float)($txn['balance_due'] ?? 0);
                                    $mh_paid       = (float)($txn['amount_paid'] ?? 0);
                                    $mh_total      = (float)($txn['total_amount'] ?? 0);
                                    $mh_can_settle = !in_array(strtolower($mh_pay_status), ['paid']) && $mh_balance > 0.009;
                                ?>
                                <tr class="mh-row">
                                    <td style="font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><strong style="color:var(--petron-blue);"><?= htmlspecialchars($txn['transaction_id'] ?? ('#'.$txn['id'])) ?></strong></td>
                                    <td style="font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($txn['customer_name'] ?? '') ?>"><?= htmlspecialchars($txn['customer_name'] ?? '—') ?></td>
                                    <td style="font-size:14px;font-weight:700;color:var(--petron-blue);white-space:nowrap;">₱<?= number_format($mh_total, 2) ?></td>
                                    <td style="font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($txn['payment_method'] ?? '—') ?></td>
                                    <td style="font-size:13px;white-space:nowrap;">
                                        <?= $mh_balance > 0 ? '<span style="color:#9a3412;font-weight:700;">₱' . number_format($mh_balance, 2) . '</span>' : '<span style="color:#166534;">—</span>' ?>
                                    </td>
                                    <td style="font-size:13px;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($txn['shift_name'] ?? $txn['shift_period'] ?? '') ?>"><?= htmlspecialchars($txn['shift_name'] ?? $txn['shift_period'] ?? '—') ?></td>
                                    <td style="font-size:13px;color:#64748b;white-space:nowrap;"><?= date('M j, Y h:i A', strtotime($txn['transaction_date'] ?? 'now')) ?></td>
                                    <td><?= status_badge($mh_pay_status) ?></td>
                                    <td>
                                        <div style="display:flex;flex-direction:column;gap:4px;">
                                            <!-- View Button (Always visible) - DARK BLUE -->
                                            <button type="button"
                                                    onclick="viewMerchandiseDetails('<?= addslashes($txn['transaction_id'] ?? '') ?>')"
                                                    style="padding:6px 12px;font-size:13px;border:none;background:#002F70;color:#fff;border-radius:6px;cursor:pointer;white-space:nowrap;font-weight:600;display:inline-flex;align-items:center;justify-content:center;gap:4px;transition:background 0.2s ease;"
                                                    onmouseover="this.style.background='#001a3d'"
                                                    onmouseout="this.style.background='#002F70'">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                            
                                            <?php if ($mh_can_settle): ?>
                                            <!-- Settle / Paid Button - GREEN -->
                                            <button type="button"
                                                    onclick="openPaymentModal(<?= (int)$txn['id'] ?>,'merchandise_transactions',<?= $mh_total ?>,<?= $mh_paid ?>,<?= $mh_balance ?>,'<?= addslashes($txn['customer_name'] ?? '') ?>',false,'merchandise')"
                                                    style="padding:6px 12px;font-size:13px;border:none;background:#16a34a;color:#fff;border-radius:6px;cursor:pointer;white-space:nowrap;font-weight:600;display:inline-flex;align-items:center;justify-content:center;gap:4px;transition:background 0.2s ease;"
                                                    onmouseover="this.style.background='#15803d'"
                                                    onmouseout="this.style.background='#16a34a'">
                                                <i class="fas fa-coins"></i>
                                                <?= strtolower($mh_pay_status) === 'partial payment' ? 'Settle' : 'Paid' ?>
                                            </button>
                                            <?php elseif (strtolower($mh_pay_status) === 'paid'): ?>
                                            <!-- Print Receipt (When fully paid) - GRAY -->
                                            <button type="button"
                                                    onclick="printMerchandiseReceipt('<?= addslashes($txn['transaction_id'] ?? '') ?>')"
                                                    style="padding:6px 12px;font-size:13px;border:none;background:#6b7280;color:#fff;border-radius:6px;cursor:pointer;white-space:nowrap;font-weight:600;display:inline-flex;align-items:center;justify-content:center;gap:4px;transition:background 0.2s ease;"
                                                    onmouseover="this.style.background='#4b5563'"
                                                    onmouseout="this.style.background='#6b7280'">
                                                <i class="fas fa-print"></i> Print Receipt
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
                                    <label style="font-size:12px;color:#6c757d;white-space:nowrap;">Rows per page:</label>
                                    <select id="mhPerPage" onchange="mhChangePerPage()" style="font-size:12px;padding:5px 8px;border:1px solid #dee2e6;border-radius:5px;color:#495057;">
                                        <option value="10" selected>10</option>
                                        <option value="20">20</option>
                                        <option value="30">30</option>
                                        <option value="40">40</option>
                                        <option value="50">50</option>
                                    </select>
                                </div>
                                <!-- Page indicator + arrows -->
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <button id="mhPrevBtn" onclick="mhGoPage(mhState.page - 1)"
                                        style="padding:5px 12px;border:1px solid #dee2e6;border-radius:5px;background:#fff;cursor:pointer;font-size:13px;color:#495057;">
                                        <i class="fas fa-chevron-left"></i>
                                    </button>
                                    <span id="mhPageLabel" style="font-size:13px;color:#495057;white-space:nowrap;">Page 1 of 1</span>
                                    <button id="mhNextBtn" onclick="mhGoPage(mhState.page + 1)"
                                        style="padding:5px 12px;border:1px solid #dee2e6;border-radius:5px;background:#fff;cursor:pointer;font-size:13px;color:#495057;">
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

                            // Tab button styles
                            formBtn.style.fontWeight   = isHistory ? '500' : '700';
                            formBtn.style.color        = isHistory ? '#64748b' : '#28a745';
                            formBtn.style.background   = isHistory ? '#f8fafc' : '#fff';
                            formBtn.style.borderBottom = isHistory ? '2px solid transparent' : '2px solid #28a745';
                            histBtn.style.fontWeight   = isHistory ? '700' : '500';
                            histBtn.style.color        = isHistory ? '#28a745' : '#64748b';
                            histBtn.style.background   = isHistory ? '#fff' : '#f8fafc';
                            histBtn.style.borderBottom = isHistory ? '2px solid #28a745' : '2px solid transparent';
                            if (isHistory) mhRender();

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
                            <option value="E-Fuel Card">E-Fuel Card</option>
                            <?php if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])): ?>
                            <option value="Credit">Credit (Utang)</option>
                            <?php endif; ?>
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
                    <button type="button" class="txn-btn danger" style="padding:4px 10px;font-size:11px;" onclick="clearCart()">
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

                <!-- Notes / Description -->
                <div style="margin-bottom:18px;">
                    <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:5px;">
                        Pricing Notes <span style="color:#94a3b8;font-weight:400;">(optional)</span>
                    </label>
                    <textarea id="newServiceNotes" rows="2"
                              style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;
                                     padding:9px 12px;font-size:12px;resize:vertical;
                                     box-sizing:border-box;font-family:inherit;"
                              placeholder="e.g. Price varies by vehicle type and parts needed…"></textarea>
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
                            class="txn-btn secondary" style="font-size:12px;padding:8px 16px;">
                        Cancel
                    </button>
                    <button type="button" id="addServiceSubmitBtn"
                            onclick="submitNewServiceType()"
                            class="txn-btn primary" style="font-size:12px;padding:8px 18px;background:#b45309;border-color:#b45309;">
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
                    <select id="newVehicleCategory" class="txn-select" style="font-size:13px;">
                        <option value="">— Select category —</option>
                        <option value="Sedans / Hatchbacks">Sedans / Hatchbacks</option>
                        <option value="SUVs">SUVs</option>
                        <option value="Pickups">Pickups</option>
                        <option value="Vans">Vans</option>
                        <option value="Light Trucks / Utility">Light Trucks / Utility</option>
                        <option value="Motorcycles">Motorcycles</option>
                        <option value="Tricycles / E-bikes">Tricycles / E-bikes</option>
                        <option value="Other">Other</option>
                    </select>
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
                            class="txn-btn secondary" style="font-size:12px;padding:8px 16px;">
                        Cancel
                    </button>
                    <button type="button" id="addVehicleSubmitBtn"
                            onclick="submitNewVehicleType()"
                            class="txn-btn primary" style="font-size:12px;padding:8px 18px;">
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

        // ── JO Service type change ────────────────────────────────────────────
        // When a service type is selected:
        //   1. Auto-fill the service fee from cached data
        //   2. Show pricing notes
        //   3. Fetch suggested parts from DB and preview them
        function onJoServiceTypeChange() {
            const sel        = document.getElementById('joServiceType');
            const notesWrap  = document.getElementById('joServicePriceNotes');
            const notesText  = document.getElementById('joServicePriceNotesText');
            const priceInput = document.getElementById('joServicePrice');
            if (!sel) return;
            const val = sel.value;

            // Auto-fill price from cached service data
            const svc = (window.JO_SERVICE_TYPES || []).find(s => s.name === val);
            if (svc) {
                if (priceInput && !priceInput.value) {
                    priceInput.value = svc.price > 0 ? svc.price.toFixed(2) : '';
                }
                if (notesWrap && notesText && svc.notes) {
                    notesText.textContent = svc.notes;
                    notesWrap.style.display = 'block';
                } else if (notesWrap) {
                    notesWrap.style.display = 'none';
                }
                // Fetch suggested parts for this service
                if (svc.key) fetchServiceParts(svc.key);
            } else {
                if (notesWrap) notesWrap.style.display = 'none';
                clearSuggestedParts();
            }
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
            const svcType  = (document.getElementById('joServiceType')?.value || '').trim();
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
            const sel = document.getElementById('joServiceType');
            if (!sel) return;
            try {
                const res  = await fetch('../backend/api/get_service_types.php', { credentials: 'same-origin' });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Failed to load service types');

                // Cache for price/notes lookup
                window.JO_SERVICE_TYPES = data.types;

                const prev = selectValue !== undefined ? selectValue : sel.value;
                sel.innerHTML = '<option value="">— Select service type —</option>';
                data.types.forEach(t => {
                    const opt = document.createElement('option');
                    opt.value       = t.name;
                    opt.textContent = t.name;
                    if (t.name === prev || t.name === prev + ' (Pending Approval)') opt.selected = true;
                    sel.appendChild(opt);
                });

                // Re-trigger notes display if a value is pre-selected
                if (prev) onJoServiceTypeChange();

            } catch (err) {
                sel.innerHTML = '<option value="">— Could not load service types —</option>';
                console.error('loadServiceTypes error:', err);
            }
        }

        // ── Add Service Type modal ────────────────────────────────────────────
        function openAddServiceModal() {
            const nameEl  = document.getElementById('newServiceName');
            const notesEl = document.getElementById('newServiceNotes');
            if (nameEl)  nameEl.value  = '';
            if (notesEl) notesEl.value = '';
            setAddServiceError('');
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
            const name  = (document.getElementById('newServiceName')?.value  || '').trim();
            const notes = (document.getElementById('newServiceNotes')?.value || '').trim();
            const btn   = document.getElementById('addServiceSubmitBtn');

            setAddServiceError('');
            if (!name) { setAddServiceError('Please enter the service name.'); return; }
            if (name.length > 100) { setAddServiceError('Name is too long (max 100 characters).'); return; }

            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting\u2026'; }

            try {
                const res  = await fetch('../backend/api/get_service_types.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ service_name: name, notes }),
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

        // ── Vehicle type change ───────────────────────────────────────────────
        function onVehicleTypeChange() { /* reserved */ }

        // ── Load vehicle types from database ─────────────────────────────────
        async function loadVehicleTypes(selectValue) {
            const sel = document.getElementById('joVehicleType');
            if (!sel) return;
            try {
                const res  = await fetch('../backend/api/get_vehicle_types.php', { credentials: 'same-origin' });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Failed to load vehicle types');

                const prev = selectValue !== undefined ? selectValue : sel.value;
                sel.innerHTML = '<option value="">— Select vehicle type —</option>';
                Object.entries(data.groups).forEach(([category, vehicles]) => {
                    const grp = document.createElement('optgroup');
                    grp.label = category;
                    vehicles.forEach(v => {
                        const opt = document.createElement('option');
                        opt.value       = v.name;
                        opt.textContent = v.name;
                        if (v.name === prev || v.name === prev + ' (Pending Approval)') opt.selected = true;
                        grp.appendChild(opt);
                    });
                    sel.appendChild(grp);
                });
            } catch (err) {
                sel.innerHTML = '<option value="">— Could not load vehicle types —</option>';
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
            const svcType      = (document.getElementById('joServiceType')?.value || '').trim();
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

        // ── Combined: add service (from Job Order) + merchandise to cart ─────
        // Called by the Merchandise form's "Add to Cart" button.
        // - If a service type is selected in the Job Order form, adds it to cart.
        // - If a product is selected in the Merchandise form, adds it to cart.
        // - At least one of the two must be present.
        async function addToCartWithService() {
            const svcType  = (document.getElementById('joServiceType')?.value || '').trim();
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
            // Reset selects to first option
            ['joVehicleType','joServiceType','joMechanic'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.selectedIndex = 0;
            });
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
            if (refFields)    refFields.style.display    = ['Card','E-Wallet','E-Fuel Card'].includes(method) ? 'block' : 'none';
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
                    job_order_service:       (document.getElementById('joServiceType')?.value || '').trim(),
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
            } else if (['Card','E-Wallet','E-Fuel Card'].includes(method)) {
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
                    // Open receipt in new tab
                    window.open(`receipt.php?id=${encodeURIComponent(data.transaction_id)}&type=merchandise`, '_blank');
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
                    const joSvcSel = document.getElementById('joServiceType');
                    if (joSvcSel) joSvcSel.selectedIndex = 0;
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
        });
        </script>

        </div><!-- /innerTab_merchandise -->

        

        <!-- ══════════════════════════════════════════════════════════
             TAB 3: JOB ORDER TRACKER  (unified single-table design)
        ══════════════════════════════════════════════════════════ -->
        <div id="innerTab_tracker" style="display:<?= $active_tab === 'tracker' ? 'block' : 'none' ?>;">
        <?php
        // Count by status for KPI strip — handles both job_orders and merchandise_transactions rows
        $jo_count_pending    = count(array_filter($job_orders, function($j) {
            $vs = $j['validation_status'] ?? '';
            $st = $j['status'] ?? '';
            return in_array($vs, ['Pending Validation', 'Pending', ''])
                && !in_array($st, ['In Progress', 'Completed', 'Rejected', 'Cancelled', 'Approved']);
        }));
        $jo_count_approved   = count(array_filter($job_orders, function($j) {
            $vs = $j['validation_status'] ?? '';
            $st = $j['status'] ?? '';
            return in_array($vs, ['Approved', 'Validated'])
                && !in_array($st, ['In Progress', 'Completed', 'Rejected', 'Cancelled']);
        }));
        $jo_count_inprogress = count(array_filter($job_orders, fn($j) =>
            ($j['status'] ?? '') === 'In Progress' || ($j['validation_status'] ?? '') === 'In Progress'
        ));
        $jo_count_completed  = count(array_filter($job_orders, fn($j) =>
            ($j['status'] ?? '') === 'Completed' || ($j['validation_status'] ?? '') === 'Completed'
        ));
        $jo_count_rejected   = count(array_filter($job_orders, fn($j) =>
            in_array($j['status'] ?? '', ['Rejected', 'Cancelled'])
            || ($j['validation_status'] ?? '') === 'Rejected'
        ));
        ?>

        <!-- KPI strip (5 status cards) -->
        <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:20px;">
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px 16px;display:flex;align-items:center;gap:10px;">
                <div style="width:36px;height:36px;border-radius:50%;background:#fef9c3;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-clock" style="color:#b45309;font-size:14px;"></i>
                </div>
                <div>
                    <div style="font-size:20px;font-weight:800;color:#b45309;"><?= $jo_count_pending ?></div>
                    <div style="font-size:10px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.4px;">Pending</div>
                </div>
            </div>
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px 16px;display:flex;align-items:center;gap:10px;">
                <div style="width:36px;height:36px;border-radius:50%;background:#d1fae5;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-check" style="color:#065f46;font-size:14px;"></i>
                </div>
                <div>
                    <div style="font-size:20px;font-weight:800;color:#065f46;"><?= $jo_count_approved ?></div>
                    <div style="font-size:10px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.4px;">Approved</div>
                </div>
            </div>
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px 16px;display:flex;align-items:center;gap:10px;">
                <div style="width:36px;height:36px;border-radius:50%;background:#dbeafe;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-play" style="color:#1d4ed8;font-size:14px;"></i>
                </div>
                <div>
                    <div style="font-size:20px;font-weight:800;color:#1d4ed8;"><?= $jo_count_inprogress ?></div>
                    <div style="font-size:10px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.4px;">In Progress</div>
                </div>
            </div>
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px 16px;display:flex;align-items:center;gap:10px;">
                <div style="width:36px;height:36px;border-radius:50%;background:#dcfce7;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-check-double" style="color:#16a34a;font-size:14px;"></i>
                </div>
                <div>
                    <div style="font-size:20px;font-weight:800;color:#16a34a;"><?= $jo_count_completed ?></div>
                    <div style="font-size:10px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.4px;">Completed</div>
                </div>
            </div>
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px 16px;display:flex;align-items:center;gap:10px;">
                <div style="width:36px;height:36px;border-radius:50%;background:#fee2e2;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-times-circle" style="color:#dc2626;font-size:14px;"></i>
                </div>
                <div>
                    <div style="font-size:20px;font-weight:800;color:#dc2626;"><?= $jo_count_rejected ?></div>
                    <div style="font-size:10px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.4px;">Rejected</div>
                </div>
            </div>
        </div>

        <!-- Filter bar -->
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap;">
            <span style="font-size:15px;color:#64748b;font-weight:600;">Filter:</span>
            <button onclick="joFilterTable('all')"    id="joFilter_all"       class="jo-filter-btn jo-filter-active">All</button>
            <button onclick="joFilterTable('pending')"  id="joFilter_pending"   class="jo-filter-btn">Pending</button>
            <button onclick="joFilterTable('approved')" id="joFilter_approved"  class="jo-filter-btn">Approved</button>
            <button onclick="joFilterTable('inprogress')" id="joFilter_inprogress" class="jo-filter-btn">In Progress</button>
            <button onclick="joFilterTable('completed')" id="joFilter_completed" class="jo-filter-btn">Completed</button>
            <button onclick="joFilterTable('rejected')" id="joFilter_rejected"  class="jo-filter-btn">Rejected</button>
            <!-- Export buttons -->
            <div style="margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <a href="../backend/export_staff_transactions.php?type=job_orders&format=excel"
                   title="Export to Excel"
                   style="background:#1d6f42;color:#fff;text-decoration:none;display:inline-flex;align-items:center;gap:6px;height:36px;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:600;">
                    <i class="fas fa-file-excel"></i> Excel
                </a>
                <a href="../backend/export_staff_transactions.php?type=job_orders&format=csv"
                   title="Export to CSV"
                   style="background:#003d7a;color:#fff;text-decoration:none;display:inline-flex;align-items:center;gap:6px;height:36px;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:600;">
                    <i class="fas fa-file-csv"></i> CSV
                </a>
                <a href="../backend/export_staff_transactions.php?type=job_orders&format=pdf"
                   target="_blank"
                   title="Export to PDF"
                   style="background:#dc2626;color:#fff;text-decoration:none;display:inline-flex;align-items:center;gap:6px;height:36px;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:600;">
                    <i class="fas fa-file-pdf"></i> PDF
                </a>
                <a href="staff_transactions_hub.php?section=merchandise&active_tab=tracker"
                   title="Back to Job Order Tracker"
                   style="background:#6c7280;color:#fff;text-decoration:none;display:inline-flex;align-items:center;gap:6px;height:36px;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:600;">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>
        <style>
        .jo-filter-btn {
            padding:7px 16px;border:1px solid #e2e8f0;border-radius:20px;
            background:#f8fafc;color:#64748b;font-size:14px;font-weight:600;
            cursor:pointer;transition:all .15s;
        }
        .jo-filter-btn:hover { background:#e2e8f0; }
        .jo-filter-btn.jo-filter-active {
            background:#003d7a;color:#fff;border-color:#003d7a;
        }
        </style>

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
                <div style="overflow-x:hidden;">
                <table class="txn-table" id="joUnifiedTable" style="table-layout:fixed; word-wrap:break-word;width:100%;">
                    <colgroup>
                        <col style="width:6%;"  ><!-- JO ID -->
                        <col style="width:9%;"  ><!-- Customer -->
                        <col style="width:14%;"><!-- Vehicle / Service -->
                        <col style="width:10%;"><!-- Items / Parts -->
                        <col style="width:9%;" ><!-- Mechanic -->
                        <col style="width:9%;" ><!-- Workflow Status -->
                        <col style="width:10%;"><!-- Payment Status -->
                        <col style="width:8%;" ><!-- Remarks -->
                        <col style="width:9%;" ><!-- Date/Time -->
                        <col style="width:16%;"><!-- Actions -->
                    </colgroup>
                    <thead>
                        <tr>
                            <th style="font-size:11px;">JO ID</th>
                            <th style="font-size:11px;">Customer</th>
                            <th style="font-size:11px;">Vehicle / Service</th>
                            <th style="font-size:11px;">Items / Parts</th>
                            <th style="font-size:11px;">Mechanic</th>
                            <th style="font-size:11px;">Status</th>
                            <th style="font-size:11px;">Payment</th>
                            <th style="font-size:11px;">Remarks</th>
                            <th style="font-size:11px;">Date</th>
                            <th style="font-size:11px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($job_orders as $job):
                        $val_status  = $job['validation_status'] ?? 'Pending Validation';
                        $wf_status   = $job['status'] ?? 'Pending';
                        $pay_status  = $job['payment_status'] ?? 'Pending';
                        $remarks     = $job['rejection_remarks'] ?? $job['notes'] ?? $job['additional_notes'] ?? '';

                        // Determine combined workflow label + badge style
                        if (in_array($wf_status, ['Rejected', 'Cancelled']) || $val_status === 'Rejected') {
                            $wf_bg='#fee2e2'; $wf_color='#991b1b'; $wf_label='REJECTED'; $row_filter='rejected';
                        } elseif ($wf_status === 'Completed' || $val_status === 'Completed') {
                            $wf_bg='#dcfce7'; $wf_color='#166534'; $wf_label='COMPLETED'; $row_filter='completed';
                        } elseif ($wf_status === 'In Progress' || $val_status === 'In Progress') {
                            $wf_bg='#dbeafe'; $wf_color='#1d4ed8'; $wf_label='IN PROGRESS'; $row_filter='inprogress';
                        } elseif (in_array($val_status, ['Approved', 'Validated'])) {
                            $wf_bg='#d1fae5'; $wf_color='#065f46'; $wf_label='APPROVED'; $row_filter='approved';
                        } else {
                            // Catches: 'Pending Validation', 'Pending', '', NULL
                            $wf_bg='#fef9c3'; $wf_color='#854d0e'; $wf_label='PENDING VALIDATION'; $row_filter='pending';
                        }

                        // Payment badge and label mapping based on user request
                        if ($pay_status === 'Paid') {
                            $pay_bg='#dcfce7'; $pay_color='#166534'; $pay_label='PAID';
                        } elseif ($pay_status === 'Partial') {
                            $pay_bg='#fef9c3'; $pay_color='#854d0e'; $pay_label='DOWNPAYMENT';
                        } elseif ($pay_status === 'Unpaid') {
                            $pay_bg='#fee2e2'; $pay_color='#991b1b'; $pay_label='UNPAID';
                        } elseif ($pay_status === 'Credit' || $pay_status === 'Receivables/Credit') {
                            $pay_bg='#e0e7ff'; $pay_color='#3730a3'; $pay_label='RECEIVABLES/CREDIT';
                        } else {
                            $pay_bg='#f1f5f9'; $pay_color='#64748b'; $pay_label='PENDING PAYMENT';
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
                    ?>
                    <tr data-jo-filter="<?= $row_filter ?>" style="<?= $wf_status === 'Rejected' ? 'background:#fff8f8;' : '' ?>">
                        <td>
                            <strong style="color:<?= $wf_status==='Rejected' ? '#dc2626' : 'var(--petron-blue)' ?>;">
                                <?= htmlspecialchars($job['job_order_id'] ?? ('#'.$job['id'])) ?>
                            </strong>
                        </td>
                        <td><?= htmlspecialchars($job['customer_name'] ?? '—') ?></td>
                        <td style="font-size:14px;">
                            <?php if (!empty($job['vehicle_plate'])): ?>
                            <strong><?= htmlspecialchars($job['vehicle_plate']) ?></strong>
                            <?php if (!empty($job['vehicle_type'])): ?>
                            <span style="color:#64748b;"> · <?= htmlspecialchars($job['vehicle_type']) ?></span>
                            <?php endif; ?>
                            <br>
                            <?php endif; ?>
                            <span style="color:#475569;"
                                  title="<?= htmlspecialchars($job['service_type'] ?? '') ?>">
                                <?= htmlspecialchars($job['service_type'] ?? '—') ?>
                            </span>
                        </td>
                        <td style="font-size:13px;color:#475569;">
                            <?= htmlspecialchars($parts_display) ?>
                        </td>
                        <td style="font-size:14px;"><?= htmlspecialchars($job['mechanic_name'] ?? 'Unassigned') ?></td>
                        <td>
                            <span style="background:<?= $wf_bg ?>;color:<?= $wf_color ?>;border:1px solid <?= $wf_bg ?>;
                                         padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700;white-space:nowrap;">
                                <?= $wf_label ?>
                            </span>
                        </td>
                        <td>
                            <span style="background:<?= $pay_bg ?>;color:<?= $pay_color ?>;border:1px solid <?= $pay_bg ?>;
                                         padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700;white-space:nowrap;">
                                <?= $pay_label ?>
                            </span>
                        </td>
                        <td style="font-size:13px;color:#64748b;max-width:150px;">
                            <?= !empty($remarks) ? htmlspecialchars($remarks) : '<span style="color:#cbd5e1;">—</span>' ?>
                        </td>
                        <td style="font-size:13px;color:#64748b;white-space:nowrap;">
                            <?= date('M j, Y', strtotime($job['created_at'])) ?><br>
                            <?= date('h:i A', strtotime($job['created_at'])) ?>
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
                                            class="txn-btn" 
                                            style="padding:6px 12px;font-size:13px;flex:1;background:#002F70;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600;display:inline-flex;align-items:center;justify-content:center;gap:4px;transition:background 0.2s ease;"
                                            onmouseover="this.style.background='#001a3d'"
                                            onmouseout="this.style.background='#002F70'">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    
                                    <?php if (!in_array($wf_status, ['Completed', 'Rejected', 'Cancelled'])): ?>
                                    <!-- Update Status Button - DARK BLUE -->
                                    <button type="button"
                                            onclick="openUpdateStatusModal(<?= (int)$job['id'] ?>,'<?= addslashes($wf_status) ?>','<?= addslashes($job['_source'] ?? 'job_orders') ?>')"
                                            class="txn-btn primary" 
                                            style="padding:6px 12px;font-size:13px;flex:1;background:#002F70;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600;display:inline-flex;align-items:center;justify-content:center;gap:4px;transition:background 0.2s ease;"
                                            onmouseover="this.style.background='#001a3d'"
                                            onmouseout="this.style.background='#002F70'">
                                        <i class="fas fa-sync-alt"></i> Update
                                    </button>
                                    <?php endif; ?>
                                    
                                    <?php if (in_array($val_status, ['Pending Validation', 'Approved']) && !in_array($wf_status, ['In Progress', 'Completed', 'Rejected'])): ?>
                                    <!-- Adjust Button (only before In Progress) - GRAY -->
                                    <button type="button"
                                            onclick='openAdjustJobOrderModal(<?= htmlspecialchars($jo_data, ENT_QUOTES) ?>)'
                                            class="txn-btn" 
                                            style="padding:6px 12px;font-size:13px;flex:1;background:#6b7280;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600;display:inline-flex;align-items:center;justify-content:center;gap:4px;transition:background 0.2s ease;"
                                            onmouseover="this.style.background='#4b5563'"
                                            onmouseout="this.style.background='#6b7280'">
                                        <i class="fas fa-edit"></i> Adjust
                                    </button>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Row 2: Workflow Actions -->
                                <?php if ($wf_status === 'Rejected'): ?>
                                    <!-- Rejected: Re-encode - GRAY -->
                                    <a href="joborder.php" 
                                       class="txn-btn secondary" 
                                       style="padding:7px 14px;font-size:13px;text-align:center;text-decoration:none;background:#6b7280;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600;display:inline-flex;align-items:center;justify-content:center;gap:4px;transition:background 0.2s ease;"
                                       onmouseover="this.style.background='#4b5563'"
                                       onmouseout="this.style.background='#6b7280'">
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
                                        <!-- Print Receipt - GRAY -->
                                        <button type="button"
                                                onclick="printJobOrderReceipt(<?= (int)$job['id'] ?>,'<?= addslashes($job['job_order_id'] ?? ('#'.$job['id'])) ?>')"
                                                class="txn-btn" 
                                                style="padding:7px 14px;font-size:13px;background:#6b7280;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600;display:inline-flex;align-items:center;justify-content:center;gap:4px;transition:background 0.2s ease;"
                                                onmouseover="this.style.background='#4b5563'"
                                                onmouseout="this.style.background='#6b7280'">
                                            <i class="fas fa-print"></i> Print Receipt
                                        </button>
                                    <?php else: ?>
                                        <!-- Mark Paid / Settle Balance - GREEN -->
                                        <button type="button"
                                                onclick="openPaymentModal(<?= (int)$job['id'] ?>,'<?= addslashes($job['_source'] ?? 'job_orders') ?>',<?= $jo_total ?>,<?= $jo_paid ?>,<?= $jo_balance ?>,'<?= addslashes($job['customer_name'] ?? '') ?>',false,'tracker')"
                                                class="txn-btn success" 
                                                style="padding:7px 14px;font-size:13px;background:#16a34a;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600;display:inline-flex;align-items:center;justify-content:center;gap:4px;transition:background 0.2s ease;"
                                                onmouseover="this.style.background='#15803d'"
                                                onmouseout="this.style.background='#16a34a'">
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
                                                class="txn-btn primary" 
                                                style="padding:7px 14px;font-size:13px;width:100%;background:#002F70;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600;display:inline-flex;align-items:center;justify-content:center;gap:4px;transition:background 0.2s ease;"
                                                onmouseover="this.style.background='#001a3d'"
                                                onmouseout="this.style.background='#002F70'">
                                            <i class="fas fa-play"></i> Start In Progress
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    
                                    <!-- Complete with payment - GREEN -->
                                    <button type="button"
                                            onclick="openPaymentModal(<?= (int)$job['id'] ?>,'<?= addslashes($job['_source'] ?? 'job_orders') ?>',<?= $jo_total ?>,<?= $jo_paid ?>,<?= $jo_balance ?>,'<?= addslashes($job['customer_name'] ?? '') ?>',true,'tracker')"
                                            class="txn-btn success" 
                                            style="padding:7px 14px;font-size:13px;width:100%;background:#16a34a;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600;display:inline-flex;align-items:center;justify-content:center;gap:4px;transition:background 0.2s ease;"
                                            onmouseover="this.style.background='#15803d'"
                                            onmouseout="this.style.background='#16a34a'">
                                        <i class="fas fa-check"></i> Complete & Settle
                                    </button>
                                    
                                    <?php if (in_array($pay_status, ['Partial Payment','Partial','Unpaid','Pending Payment','Pending']) && $wf_status === 'In Progress'): ?>
                                    <!-- Downpayment option - GREEN -->
                                    <button type="button"
                                            onclick="openPaymentModal(<?= (int)$job['id'] ?>,'<?= addslashes($job['_source'] ?? 'job_orders') ?>',<?= $jo_total ?>,<?= $jo_paid ?>,<?= $jo_balance ?>,'<?= addslashes($job['customer_name'] ?? '') ?>',false,'tracker')"
                                            class="txn-btn" 
                                            style="padding:7px 14px;font-size:13px;width:100%;background:#16a34a;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600;display:inline-flex;align-items:center;justify-content:center;gap:4px;transition:background 0.2s ease;"
                                            onmouseover="this.style.background='#15803d'"
                                            onmouseout="this.style.background='#16a34a'">
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
                        <label style="margin:0; font-weight:400; color:#475569;">Rows per page:</label>
                        <select id="joPerPage" onchange="joChangePerPage()"
                                style="padding:4px 24px 4px 8px; border:1px solid #cbd5e1; border-radius:4px; font-size:13px; color:#1e293b; background:#fff; outline:none; cursor:pointer;">
                            <option value="10" selected>10</option>
                            <option value="20">20</option>
                            <option value="30">30</option>
                            <option value="40">40</option>
                            <option value="50">50</option>
                        </select>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <button id="joPrevBtn" onclick="joGoPage(joState.page - 1)" style="width:28px; height:28px; background:#fff; border:1px solid #cbd5e1; border-radius:4px; font-size:12px; color:#475569; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all .15s;">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <span id="joPageLabel" style="color:#475569; font-size:13px; padding:0 4px;">Page 1 of 1</span>
                        <button id="joNextBtn" onclick="joGoPage(joState.page + 1)" style="width:28px; height:28px; background:#fff; border:1px solid #cbd5e1; border-radius:4px; font-size:12px; color:#475569; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all .15s;">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <script>
        var joState = {
            filter: 'all',
            page: 1,
            per_page: 10
        };

        function joRenderTable() {
            var rows = document.querySelectorAll('#joUnifiedTable tbody tr');
            var visibleRows = [];
            
            // 1. Apply filter first
            rows.forEach(function(row) {
                var rowFilter = row.getAttribute('data-jo-filter');
                if (joState.filter === 'all' || rowFilter === joState.filter) {
                    visibleRows.push(row);
                } else {
                    row.style.display = 'none';
                }
            });

            // 2. Pagination calculations
            var total = visibleRows.length;
            var perPage = joState.per_page;
            var totalPages = Math.max(1, Math.ceil(total / perPage));
            if (joState.page > totalPages) { joState.page = totalPages; }

            var start = (joState.page - 1) * perPage;
            var end = start + perPage;

            // 3. Display rows within page window
            visibleRows.forEach(function(row, index) {
                if (index >= start && index < end) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });

            // 4. Update UI labels and buttons
            var lbl = document.getElementById('joPageLabel');
            if (lbl) lbl.textContent = 'Page ' + joState.page + ' of ' + totalPages;

            var prev = document.getElementById('joPrevBtn');
            var next = document.getElementById('joNextBtn');
            if (prev) {
                prev.disabled = (joState.page <= 1);
                prev.style.opacity = prev.disabled ? '0.5' : '1';
                prev.style.cursor = prev.disabled ? 'not-allowed' : 'pointer';
            }
            if (next) {
                next.disabled = (joState.page >= totalPages);
                next.style.opacity = next.disabled ? '0.5' : '1';
                next.style.cursor = next.disabled ? 'not-allowed' : 'pointer';
            }
        }

        function joFilterTable(filter) {
            // Update active button
            document.querySelectorAll('.jo-filter-btn').forEach(function(btn) {
                btn.classList.remove('jo-filter-active');
            });
            var activeBtn = document.getElementById('joFilter_' + filter);
            if (activeBtn) activeBtn.classList.add('jo-filter-active');

            joState.filter = filter;
            joState.page = 1; // reset to page 1 on filter
            joRenderTable();
        }

        function joGoPage(p) {
            joState.page = p;
            joRenderTable();
        }

        function joChangePerPage() {
            var sel = document.getElementById('joPerPage');
            if (sel) {
                joState.per_page = parseInt(sel.value, 10);
                joState.page = 1; // reset to page 1
                joRenderTable();
            }
        }

        // Initialize pagination on load
        document.addEventListener('DOMContentLoaded', function() {
            joRenderTable();
        });
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
                  <button type="button" class="pm-method-btn"        data-method="E-Fuel Card" onclick="pmSelectMethod('E-Fuel Card')">
                    <i class="fas fa-gas-pump"        style="display:block;font-size:13px;margin-bottom:2px;color:#dc2626;"></i>E-Fuel
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
            document.getElementById('pmRefFields').style.display    = ['Card','E-Wallet','E-Fuel Card'].includes(method) ? 'block' : 'none';
            document.getElementById('pmCreditFields').style.display = method === 'Credit'   ? 'block' : 'none';
            var labels = {'Card':'Card Reference No.','E-Wallet':'E-Wallet Ref No. (GCash/Maya)','E-Fuel Card':'E-Fuel Card ID'};
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
                    <option value="Approved">✓ Approved</option>
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
                <button type="button" onclick="closeUpdateStatusModal()" style="padding:9px 18px;background:#f1f5f9;color:#475569;
                        border:1px solid #e2e8f0;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">
                  Cancel
                </button>
                <button type="submit" style="padding:9px 18px;background:#003d7a;color:#fff;border:none;
                        border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px;">
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
                <button type="button" onclick="closeAdjustJobOrderModal()" style="padding:9px 18px;background:#f1f5f9;color:#475569;
                        border:1px solid #e2e8f0;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">
                  Cancel
                </button>
                <button type="submit" style="padding:9px 18px;background:#f59e0b;color:#fff;border:none;
                        border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px;">
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
            window.open('receipt.php?id=' + rid + '&type=job_order', '_blank', 'width=520,height=800,scrollbars=yes');
        }
        
        // Print Merchandise Receipt
        function printMerchandiseReceipt(txnId) {
            window.open('receipt.php?id=' + txnId + '&type=merchandise', '_blank', 'width=520,height=800,scrollbars=yes');
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
                                    <td style="padding:8px;text-align:center;color:#475569;">${item.quantity || 0}</td>
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
                var colors = {merchandise:'#28a745', tracker:'#003d7a'};
                var active = (t === tab);
                panel.style.display    = active ? 'block' : 'none';
                btn.style.fontWeight   = active ? '700' : '500';
                btn.style.color        = active ? colors[t] : '#64748b';
                btn.style.background   = active ? '#fff' : '#f8fafc';
                btn.style.borderBottom = active ? '2px solid ' + colors[t] : '2px solid transparent';
            });
            var descElem = document.getElementById('txnSectionDesc');
            if (descElem) {
                descElem.textContent = (tab === 'tracker') ? 'Monitor service progress and pending balances in real time.' : 'Merchandise sales, job order encoding, and status tracking.';
            }
            var url = new URL(window.location.href);
            url.searchParams.set('active_tab', tab);
            history.replaceState(null, '', url.toString());
        }
        </script>

        <?php /* ══════════════════════════════════════════════════════
               SECTION: SHIFT HISTORY
        ══════════════════════════════════════════════════════ */ ?>
        <?php elseif ($section === 'history'): ?>

        <div class="txn-section-header">
            <div class="txn-section-title">
                <div>
                    <h1>Shift History</h1>
                    <p>Your shift log history</p>
                </div>
            </div>
            <div>
                <button type="button" onclick="window.location.href='staff_dashboard.php'" 
                        style="display:inline-flex;align-items:center;gap:6px;height:36px;padding:8px 14px;background:#6c757d;color:#fff;border-radius:8px;border:none;font-size:13px;font-weight:600;cursor:pointer;" title="Back to Staff Dashboard">
                    <i class="fas fa-arrow-left"></i> <span>Back</span>
                </button>
            </div>
        </div>

        <!-- Shift Log -->
        <?php if (!empty($shift_log)): ?>
        <div class="txn-card">
            <div class="txn-card-header">
                <i class="fas fa-clock" style="color:#6f42c1;"></i>
                <h3>Shift Log</h3>
            </div>
            <div class="txn-card-body" style="padding:0;">
                <div style="overflow-x:hidden;">
                <table class="txn-table" style="width:100%; table-layout:fixed; word-wrap:break-word;">
                    <thead>
                        <tr>
                            <th>Shift</th>
                            <th>Clock In</th>
                            <th>Clock Out</th>
                            <th>Duration</th>
                        </tr>
                    </thead>
                    <tbody id="shiftLogBody">
                    <?php foreach ($shift_log as $ls): ?>
                    <tr class="sl-row">
                        <td><?= htmlspecialchars($ls['shift_label'] ?? $ls['shift_period'] ?? '—') ?></td>
                        <td style="font-size:11px;color:#64748b;"><?= date('M j, Y h:i A', strtotime($ls['start_time'])) ?></td>
                        <td style="font-size:11px;color:#64748b;">
                            <?= !empty($ls['end_time']) ? date('M j, Y h:i A', strtotime($ls['end_time'])) : '<span style="color:#f59e0b;font-weight:600;">Active</span>' ?>
                        </td>
                        <td style="font-size:11px;">
                            <?php
                            $mins = (int)($ls['duration_minutes'] ?? 0);
                            echo $mins >= 60 ? floor($mins/60).'h '.($mins%60).'m' : $mins.'m';
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <!-- Rows per page + Pagination controls -->
                <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;border-top:1px solid #e2e8f0;">
                    <div style="display:flex;align-items:center;gap:7px;">
                        <label style="font-size:12px;color:#6c757d;white-space:nowrap;">Rows per page:</label>
                        <select id="slPerPage" onchange="slChangePerPage()" style="font-size:12px;padding:5px 8px;border:1px solid #dee2e6;border-radius:5px;color:#495057;">
                            <option value="10" selected>10</option>
                            <option value="20">20</option>
                            <option value="30">30</option>
                            <option value="40">40</option>
                            <option value="50">50</option>
                        </select>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <button id="slPrevBtn" onclick="slGoPage(slState.page - 1)"
                            style="padding:5px 12px;border:1px solid #dee2e6;border-radius:5px;background:#fff;cursor:pointer;font-size:13px;color:#495057;">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <span id="slPageLabel" style="font-size:13px;color:#495057;white-space:nowrap;">Page 1 of 1</span>
                        <button id="slNextBtn" onclick="slGoPage(slState.page + 1)"
                            style="padding:5px 12px;border:1px solid #dee2e6;border-radius:5px;background:#fff;cursor:pointer;font-size:13px;color:#495057;">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <script>
        (function(){
            var slState = { page: 1, per_page: 10 };
            function slRender() {
                var rows = document.querySelectorAll('#shiftLogBody .sl-row');
                var total = rows.length;
                var perPage = slState.per_page;
                var page = slState.page;
                var totalPages = Math.max(1, Math.ceil(total / perPage));
                if (page > totalPages) { slState.page = page = totalPages; }
                var start = (page - 1) * perPage;
                var end   = start + perPage;
                rows.forEach(function(row, i) {
                    row.style.display = (i >= start && i < end) ? '' : 'none';
                });
                var lbl = document.getElementById('slPageLabel');
                if (lbl) lbl.textContent = 'Page ' + page + ' of ' + totalPages;
                var prev = document.getElementById('slPrevBtn');
                var next = document.getElementById('slNextBtn');
                if (prev) { prev.disabled = (page <= 1); prev.style.opacity = (page <= 1) ? '0.4' : '1'; }
                if (next) { next.disabled = (page >= totalPages); next.style.opacity = (page >= totalPages) ? '0.4' : '1'; }
            }
            window.slState = slState;
            window.slGoPage = function(p) {
                var rows = document.querySelectorAll('#shiftLogBody .sl-row');
                var totalPages = Math.max(1, Math.ceil(rows.length / slState.per_page));
                if (p < 1 || p > totalPages) return;
                slState.page = p;
                slRender();
            };
            window.slChangePerPage = function() {
                var sel = document.getElementById('slPerPage');
                if (sel) slState.per_page = parseInt(sel.value);
                slState.page = 1;
                slRender();
            };
            slRender();
        })();
        </script>
        <?php else: ?>
        <div style="text-align:center;padding:48px;color:#94a3b8;">
            <i class="fas fa-clock" style="font-size:32px;display:block;margin-bottom:10px;"></i>
            No shift log found.
        </div>
        <?php endif; ?>

        <?php /* ══════════════════════════════════════════════════════
               SECTION: FUEL HISTORY
        ══════════════════════════════════════════════════════ */ ?>
        <?php elseif ($section === 'fuel_history'): ?>

        <div class="txn-section-header">
            <div class="txn-section-title">
                <div>
                    <h1>Fuel Transaction History</h1>
                    <p>Your fuel transaction records</p>
                </div>
            </div>
            <div>
                <button type="button" onclick="window.location.href='staff_dashboard.php'" 
                        style="display:inline-flex;align-items:center;gap:6px;height:36px;padding:8px 14px;background:#6c757d;color:#fff;border-radius:8px;border:none;font-size:13px;font-weight:600;cursor:pointer;" title="Back to Staff Dashboard">
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
                        <label style="font-size:12px;color:#6c757d;white-space:nowrap;">Rows per page:</label>
                        <select id="fhPerPage" onchange="fhChangePerPage()" style="font-size:12px;padding:5px 8px;border:1px solid #dee2e6;border-radius:5px;color:#495057;">
                            <option value="10" selected>10</option>
                            <option value="20">20</option>
                            <option value="30">30</option>
                            <option value="40">40</option>
                            <option value="50">50</option>
                        </select>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <button id="fhPrevBtn" onclick="fhGoPage(fhState.page - 1)"
                            style="padding:5px 12px;border:1px solid #dee2e6;border-radius:5px;background:#fff;cursor:pointer;font-size:13px;color:#495057;">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <span id="fhPageLabel" style="font-size:13px;color:#495057;white-space:nowrap;">Page 1 of 1</span>
                        <button id="fhNextBtn" onclick="fhGoPage(fhState.page + 1)"
                            style="padding:5px 12px;border:1px solid #dee2e6;border-radius:5px;background:#fff;cursor:pointer;font-size:13px;color:#495057;">
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
