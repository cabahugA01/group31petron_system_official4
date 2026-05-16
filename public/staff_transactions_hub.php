<?php
/**
 * Staff Transactions Hub
 * Sidebar navigation for Fuel (internal) and Merchandise (customer-facing) transactions.
 */
$page_id = 'transactions';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

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

// Active sub-section: fuel | merchandise | history | fuel_history  (default: fuel)
$section = $_GET['section'] ?? 'fuel';
if (!in_array($section, ['fuel', 'merchandise', 'history', 'fuel_history'])) {
    $section = 'fuel';
}

// ── Fuel types for this station (exclude discontinued types) ─────────────────
$fuel_types = [];
$excluded_fuel_types = ['Petron Blaze 100', 'Petron E10'];
try {
    $placeholders = implode(',', array_fill(0, count($excluded_fuel_types), '?'));
    $stmt = $pdo->prepare("
        SELECT fi.fuel_type,
               COALESCE(fi.current_level, fi.current_stock, 0) AS current_level,
               COALESCE(fi.price_per_liter, 0)         AS price_per_liter,
               COALESCE(fi.latest_calibration, 0)      AS calibration,
               COALESCE(last_tx.present_reading, 0)    AS previous_reading
        FROM fuel_inventory fi
        LEFT JOIN (
            SELECT ft.station_id, ft.fuel_type, ft.present_reading
            FROM fuel_transactions ft
            INNER JOIN (
                SELECT station_id, fuel_type, MAX(transaction_date) AS latest
                FROM fuel_transactions
                WHERE LOWER(status) IN ('approved','verified')
                GROUP BY station_id, fuel_type
            ) lx ON lx.station_id = ft.station_id
               AND LOWER(TRIM(lx.fuel_type)) = LOWER(TRIM(ft.fuel_type))
               AND lx.latest = ft.transaction_date
        ) last_tx ON last_tx.station_id = fi.station_id
                 AND LOWER(TRIM(last_tx.fuel_type)) = LOWER(TRIM(fi.fuel_type))
        WHERE fi.station_id = ?
          AND fi.fuel_type NOT IN ($placeholders)
        ORDER BY fi.fuel_type
    ");
    $stmt->execute(array_merge([$station_id], $excluded_fuel_types));
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
    $stmt = $pdo->prepare("SELECT id, name, username FROM users WHERE station_id = ? AND role IN ('staff', 'cashier', 'pump_attendant') AND account_status = 'active' ORDER BY name");
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
$merch_shift_key  = 'first';
$merch_shift_name = 'First Shift: 6:00 AM – 2:00 PM';
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
        $merch_shift_name = $active_row['shift_name'] ?: $merch_shift_name;
    } else {
        // Priority 2: fall back to time-based detection
        $ct = date('H:i:s');
        $sp = $pdo->prepare("SELECT shift_key, shift_name FROM shift_periods WHERE is_active = 1 AND start_time <= ? AND end_time >= ? ORDER BY sort_order ASC LIMIT 1");
        $sp->execute([$ct, $ct]);
        $sf = $sp->fetch(PDO::FETCH_ASSOC);
        if ($sf) {
            $merch_shift_key  = $sf['shift_key'];
            $merch_shift_name = $sf['shift_name'];
        } else {
            $sp2 = $pdo->query("SELECT shift_key, shift_name FROM shift_periods WHERE is_active = 1 ORDER BY sort_order DESC LIMIT 1");
            $sf2 = $sp2 ? $sp2->fetch(PDO::FETCH_ASSOC) : null;
            if ($sf2) { $merch_shift_key = $sf2['shift_key']; $merch_shift_name = $sf2['shift_name']; }
        }
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
$mh_per_page      = in_array((int)($_GET['mh_per_page'] ?? 10), [10,20,30,50]) ? (int)$_GET['mh_per_page'] : 10;
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
    } catch (Exception $e) { $mh_recent = []; $mh_total = 0; }
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
            LIMIT $hist_per_page OFFSET $hist_offset
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
        'pending validation' => ['bg' => '#fef9c3', 'color' => '#854d0e', 'border' => '#fde047', 'label' => 'Pending Validation'],
        'pending'            => ['bg' => '#fef9c3', 'color' => '#854d0e', 'border' => '#fde047', 'label' => 'Pending Validation'],
        'verified'           => ['bg' => '#dcfce7', 'color' => '#166534', 'border' => '#86efac', 'label' => 'Verified'],
        'approved'           => ['bg' => '#dcfce7', 'color' => '#166534', 'border' => '#86efac', 'label' => 'Verified'],
        'rejected'           => ['bg' => '#fee2e2', 'color' => '#991b1b', 'border' => '#fca5a5', 'label' => 'Rejected'],
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
        $jo_action  = $_POST['jo_action'];
        $jo_id      = (int)($_POST['jo_id'] ?? 0);
        $jo_src     = $_POST['jo_source'] ?? 'job_orders';
        $tracker_tab = $_POST['tracker_tab'] ?? 'pending';

        if ($jo_id > 0) {
            try {
                if ($jo_src === 'merchandise_transactions') {
                    // Record lives in merchandise_transactions — use workflow_status column
                    if ($jo_action === 'set_in_progress') {
                        $pdo->prepare("UPDATE merchandise_transactions SET workflow_status='In Progress', updated_at=NOW() WHERE id=? AND station_id=?")->execute([$jo_id, $station_id]);
                        $_SESSION['success'] = 'Job Order marked as In Progress.';
                    } elseif ($jo_action === 'set_completed') {
                        $pdo->prepare("UPDATE merchandise_transactions SET workflow_status='Completed', updated_at=NOW() WHERE id=? AND station_id=?")->execute([$jo_id, $station_id]);
                        $_SESSION['success'] = 'Job Order marked as Completed.';
                    } elseif ($jo_action === 'set_paid') {
                        $pdo->prepare("UPDATE merchandise_transactions SET payment_status='Paid', updated_at=NOW() WHERE id=? AND station_id=?")->execute([$jo_id, $station_id]);
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
                        $pdo->prepare("UPDATE job_orders SET payment_status='Paid', updated_at=NOW() WHERE id=? AND station_id=?")->execute([$jo_id, $station_id]);
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
                    COALESCE(mt.payment_status, 'Unpaid') AS payment_status,
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
    padding: 20px 20px 60px 20px !important;
}

/* ── Cart wrapper — single full-width column (matches inventory layout) ── */
.cart-wrapper {
    display: flex;
    flex-direction: column;
    gap: 14px;
    width: 100%;
}

/* ── Right panel — now a normal full-width card, not sticky ── */
.cart-panel {
    display: flex;
    flex-direction: column;
    width: 100%;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,.07);
    overflow: hidden;
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
    font-size: 12px;
    color: #64748b;
    margin: 3px 0 0;
    text-transform: none !important;
    font-weight: 400 !important;
    letter-spacing: 0 !important;
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

        <!-- ── Page Header ───────────────────────────────────────────── -->
        <div class="txn-section-header">
            <div class="txn-section-title">
                <div class="txn-section-icon fuel"><i class="fas fa-gas-pump"></i></div>
                <div>
                    <h1>Fuel Transaction</h1>
                </div>
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
                    <div style="display:flex;flex-direction:column;gap:4px;min-width:140px;">
                        <label style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">
                            <i class="fas fa-calendar-day" style="margin-right:3px;"></i>From
                        </label>
                        <input type="date" name="date_from"
                               value="<?= htmlspecialchars($filter_date_from) ?>"
                               style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;color:#1e293b;background:#fff;width:100%;">
                    </div>

                    <!-- Date To -->
                    <div style="display:flex;flex-direction:column;gap:4px;min-width:140px;">
                        <label style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">
                            <i class="fas fa-calendar-day" style="margin-right:3px;"></i>To
                        </label>
                        <input type="date" name="date_to"
                               value="<?= htmlspecialchars($filter_date_to) ?>"
                               style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;color:#1e293b;background:#fff;width:100%;">
                    </div>

                    <!-- Fuel Type -->
                    <div style="display:flex;flex-direction:column;gap:4px;min-width:150px;">
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
                    <div style="display:flex;flex-direction:column;gap:4px;min-width:160px;">
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

                    <!-- Status — actual DB values -->
                    <div style="display:flex;flex-direction:column;gap:4px;min-width:150px;">
                        <label style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">
                            <i class="fas fa-flag" style="margin-right:3px;"></i>Status
                        </label>
                        <select name="status"
                                style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;color:#1e293b;background:#fff;width:100%;">
                            <option value="">All Statuses</option>
                            <option value="Pending Validation" <?= $filter_status === 'Pending Validation' ? 'selected' : '' ?>>Pending Validation</option>
                            <option value="Approved"           <?= $filter_status === 'Approved'           ? 'selected' : '' ?>>Approved</option>
                            <option value="Verified"           <?= $filter_status === 'Verified'           ? 'selected' : '' ?>>Verified</option>
                            <option value="Adjusted"           <?= $filter_status === 'Adjusted'           ? 'selected' : '' ?>>Adjusted</option>
                            <option value="Rejected"           <?= $filter_status === 'Rejected'           ? 'selected' : '' ?>>Rejected</option>
                        </select>
                    </div>

                    <!-- Shift Period — from DB -->
                    <div style="display:flex;flex-direction:column;gap:4px;min-width:200px;">
                        <label style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">
                            <i class="fas fa-clock" style="margin-right:3px;"></i>Shift Period
                        </label>
                        <select name="shift"
                                style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;color:#1e293b;background:#fff;width:100%;">
                            <option value="">All Shifts</option>
                            <?php
                            $shift_opts = !empty($shift_periods) ? $shift_periods : [
                                ['shift_key' => 'first',  'shift_name' => 'First Shift: 6:00 AM – 2:00 PM'],
                                ['shift_key' => 'second', 'shift_name' => 'Second Shift: 2:00 PM – 12:00 Midnight'],
                            ];
                            foreach ($shift_opts as $sp_opt):
                            ?>
                            <option value="<?= htmlspecialchars($sp_opt['shift_key']) ?>"
                                <?= $filter_shift === $sp_opt['shift_key'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sp_opt['shift_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Buttons -->
                    <div style="display:flex;gap:8px;align-items:flex-end;padding-bottom:1px;">
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
                    foreach ($shift_opts as $sp_opt) {
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
        .fet-wrap { overflow-x: auto; }

        .fet {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            min-width: 780px;
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
            min-width: 120px;
            transition: border-color .15s, box-shadow .15s;
            letter-spacing: .3px;
        }
        .fet-input:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(0,47,108,.1);
        }
        .fet-input.calib {
            min-width: 90px;
            font-weight: 500;
            color: #475569;
        }
        .fet-input.notes-input {
            min-width: 140px;
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

        <div class="txn-card" style="margin-bottom:20px;">
            <div class="txn-card-header" style="background:#f0f4ff;">
                <i class="fas fa-table" style="color:var(--petron-blue);"></i>
                <h3>Encode Meter Readings</h3>
                <span style="margin-left:auto;font-size:11px;color:#64748b;font-weight:500;">
                    <?= date('F j, Y') ?> &nbsp;|&nbsp; <?= htmlspecialchars($fuel_shift_name) ?>
                </span>
            </div>

            <div class="fet-wrap">
                <table class="fet">
                    <thead>
                        <tr>
                            <th>Fuel Type</th>
                            <th class="num">Prev. Reading</th>
                            <th style="min-width:150px;">Present Reading <span style="color:#dc2626;font-weight:800;">*</span></th>
                            <th style="min-width:110px;">Calibration</th>
                            <th style="min-width:160px;">Notes</th>
                            <th style="min-width:160px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($fuel_types as $idx => $ft):
                        $ft_id   = 'fuel_' . preg_replace('/[^a-z0-9]/i', '_', $ft['fuel_type']) . '_' . $idx;
                        $ft_name = htmlspecialchars($ft['fuel_type']);
                        $ft_lower = strtolower($ft['fuel_type']);

                        // Brand-accurate colors
                        if      (str_contains($ft_lower, 'diesel'))   { $ft_color = '#003d7a'; $ft_icon = 'fa-gas-pump';  }
                        elseif  (str_contains($ft_lower, 'kerosene')) { $ft_color = '#b45309'; $ft_icon = 'fa-fire';      }
                        elseif  (str_contains($ft_lower, 'xcs'))      { $ft_color = '#0369a1'; $ft_icon = 'fa-gas-pump';  }
                        elseif  (str_contains($ft_lower, 'xtra'))     { $ft_color = '#15803d'; $ft_icon = 'fa-gas-pump';  }
                        elseif  (str_contains($ft_lower, 'blaze'))    { $ft_color = '#b91c1c'; $ft_icon = 'fa-fire-alt';  }
                        elseif  (str_contains($ft_lower, 'e10'))      { $ft_color = '#065f46'; $ft_icon = 'fa-leaf';      }
                        else                                           { $ft_color = '#334155'; $ft_icon = 'fa-gas-pump';  }

                        $prev_reading = (float)$ft['previous_reading'];
                    ?>
                    <tr id="fuelRow_<?= $ft_id ?>">
                        <td>
                            <!-- Hidden form fields for this row -->
                            <form id="fuelForm_<?= $ft_id ?>"
                                  method="POST"
                                  action="api_fuel_readings.php"
                                  onsubmit="return submitFuelCard(event, '<?= $ft_id ?>')">
                                <input type="hidden" name="action"           value="encode_reading">
                                <input type="hidden" name="shift_id"         value="<?= (int)($current_shift['id'] ?? 0) ?>">
                                <input type="hidden" name="staff_id"         value="<?= (int)$me['id'] ?>">
                                <input type="hidden" name="station_id"       value="<?= (int)$station_id ?>">
                                <input type="hidden" name="fuel_type"        value="<?= $ft_name ?>">
                                <input type="hidden" name="previous_reading" value="<?= $prev_reading ?>">
                                <input type="hidden" name="price_per_liter"  value="<?= (float)$ft['price_per_liter'] ?>">
                                <input type="hidden" name="shift_period"     value="<?= htmlspecialchars($fuel_shift_key) ?>">
                                <input type="hidden" name="shift_name"       value="<?= htmlspecialchars($fuel_shift_name) ?>">
                                <input type="hidden" name="reading_date"     value="<?= date('Y-m-d') ?>">
                            </form>

                            <!-- Fuel type identity -->
                            <div class="fet-fuel-cell">
                                <div style="width:32px;height:32px;border-radius:50%;background:<?= $ft_color ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    <i class="fas <?= $ft_icon ?>" style="color:#fff;font-size:13px;"></i>
                                </div>
                                <div class="fet-fuel-name" style="color:<?= $ft_color ?>;"><?= $ft_name ?></div>
                            </div>
                        </td>

                        <!-- Previous Reading — auto-pulled, read-only -->
                        <td class="num">
                            <span class="fet-auto <?= $prev_reading > 0 ? '' : 'dim' ?>">
                                <?= $prev_reading > 0 ? number_format($prev_reading, 2) : '—' ?>
                            </span>
                        </td>

                        <!-- Present Reading — staff encodes -->
                        <td>
                            <input type="number"
                                   form="fuelForm_<?= $ft_id ?>"
                                   name="present_reading"
                                   id="present_<?= $ft_id ?>"
                                   class="fet-input"
                                   step="0.01" min="0"
                                   placeholder="Enter reading"
                                   required
                                   autocomplete="off"
                                   style="border-color:<?= $ft_color ?>;">
                        </td>

                        <!-- Calibration — optional adjustment -->
                        <td>
                            <input type="number"
                                   form="fuelForm_<?= $ft_id ?>"
                                   name="calibration"
                                   id="calib_<?= $ft_id ?>"
                                   class="fet-input calib"
                                   step="0.001" min="0" value="0"
                                   placeholder="0.000"
                                   autocomplete="off">
                            <?php if ((float)$ft['calibration'] > 0): ?>
                            <div style="font-size:10px;color:#94a3b8;margin-top:3px;font-style:italic;">
                                ref: <?= number_format((float)$ft['calibration'], 3) ?>
                            </div>
                            <?php endif; ?>
                        </td>

                        <!-- Notes — optional -->
                        <td>
                            <input type="text"
                                   form="fuelForm_<?= $ft_id ?>"
                                   name="notes"
                                   class="fet-input notes-input"
                                   placeholder="Remarks…"
                                   maxlength="255"
                                   autocomplete="off">
                        </td>

                        <!-- Actions -->
                        <td>
                            <div style="display:flex;gap:6px;align-items:center;flex-wrap:nowrap;">
                                <button type="submit"
                                        form="fuelForm_<?= $ft_id ?>"
                                        class="fet-submit-btn"
                                        id="submitBtn_<?= $ft_id ?>">
                                    <i class="fas fa-paper-plane"></i> Submit
                                </button>
                                <button type="button"
                                        class="fet-reset-btn"
                                        onclick="resetCard('<?= $ft_id ?>')"
                                        title="Reset this row">
                                    <i class="fas fa-undo"></i>
                                </button>
                            </div>
                            <div id="cardMsg_<?= $ft_id ?>" class="fet-row-msg" style="margin-top:5px;"></div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div><!-- /fet-wrap -->
        </div><!-- /txn-card -->

        <?php endif; ?>

        <!-- ── TODAY'S ENTRIES — Meter Reading Table (Table A) ──────────── -->
        <div class="txn-card" id="todayEntriesCard" style="margin-top:8px;">
            <div class="txn-card-header" style="background:#f0f4ff;">
                <i class="fas fa-tachometer-alt" style="color:var(--petron-blue);"></i>
                <h3>Today's Meter Readings</h3>
                <span style="margin-left:auto;font-size:11px;color:#64748b;font-weight:500;">
                    Your submissions for <?= date('F j, Y') ?> — pending manager validation
                </span>
                <button type="button" onclick="refreshTodayEntries()"
                        style="margin-left:12px;background:none;border:1px solid #e2e8f0;border-radius:6px;padding:4px 10px;font-size:11px;color:#64748b;cursor:pointer;display:flex;align-items:center;gap:4px;">
                    <i class="fas fa-sync" id="refreshIcon"></i> Refresh
                </button>
            </div>
            <div id="todayEntriesBody" style="padding:0;">
                <div style="text-align:center;padding:32px;color:#94a3b8;font-size:13px;">
                    <i class="fas fa-spinner fa-spin" style="font-size:22px;display:block;margin-bottom:8px;"></i>
                    Loading today's entries…
                </div>
            </div>
        </div>

        <script>
        // ── Fuel Transaction Filters ───────────────────────────────────────────
        function resetFuelFilters() {
            window.location.href = 'staff_transactions_hub.php?section=fuel';
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

                const params = new URLSearchParams({ action: 'summary', date_from: dateFrom, date_to: dateTo });
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

                const rows = json.meter_readings;
                const statusMap = {
                    'pending validation': {bg:'#fef9c3',color:'#854d0e',label:'Pending'},
                    'pending':            {bg:'#fef9c3',color:'#854d0e',label:'Pending'},
                    'approved':           {bg:'#dcfce7',color:'#166534',label:'Approved'},
                    'verified':           {bg:'#dcfce7',color:'#166534',label:'Verified'},
                    'adjusted':           {bg:'#dbeafe',color:'#1d4ed8',label:'Adjusted'},
                    'rejected':           {bg:'#fee2e2',color:'#991b1b',label:'Rejected'},
                };
                function badge(s) {
                    const k = (s||'').toLowerCase().trim();
                    const c = statusMap[k] || {bg:'#f1f5f9',color:'#64748b',label:s||'—'};
                    return `<span style="background:${c.bg};color:${c.color};padding:2px 9px;border-radius:20px;font-size:10px;font-weight:700;white-space:nowrap;">${c.label}</span>`;
                }
                function fmt(n,d=2){ return Number(n||0).toLocaleString('en-PH',{minimumFractionDigits:d,maximumFractionDigits:d}); }

                let html = `<div style="overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;font-size:13px;">
                        <thead>
                            <tr style="background:#f8fafc;">
                                <th style="padding:10px 13px;text-align:left;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;border-bottom:2px solid #e2e8f0;white-space:nowrap;">Fuel Type</th>
                                <th style="padding:10px 13px;text-align:right;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;border-bottom:2px solid #e2e8f0;white-space:nowrap;">Beginning</th>
                                <th style="padding:10px 13px;text-align:right;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;border-bottom:2px solid #e2e8f0;white-space:nowrap;">Ending</th>
                                <th style="padding:10px 13px;text-align:right;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;border-bottom:2px solid #e2e8f0;white-space:nowrap;">Cal</th>
                                <th style="padding:10px 13px;text-align:right;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;border-bottom:2px solid #e2e8f0;white-space:nowrap;">Liters Sold</th>
                                <th style="padding:10px 13px;text-align:right;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;border-bottom:2px solid #e2e8f0;white-space:nowrap;">Price/L</th>
                                <th style="padding:10px 13px;text-align:right;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;border-bottom:2px solid #e2e8f0;white-space:nowrap;">Amount</th>
                                <th style="padding:10px 13px;text-align:left;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;border-bottom:2px solid #e2e8f0;white-space:nowrap;">Shift</th>
                                <th style="padding:10px 13px;text-align:left;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;border-bottom:2px solid #e2e8f0;white-space:nowrap;">Status</th>
                            </tr>
                        </thead>
                        <tbody>`;

                rows.forEach(r => {
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
                body.innerHTML = html;

            } catch(e) {
                body.innerHTML = `<div style="text-align:center;padding:24px;color:#ef4444;font-size:13px;">
                    <i class="fas fa-exclamation-circle" style="display:block;margin-bottom:6px;"></i>
                    Could not load entries. Please refresh.
                </div>`;
            }
            if (icon) icon.className = 'fas fa-sync';
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
        // Active inner tab: merchandise | encode_jo | tracker
        $active_tab  = $_GET['active_tab'] ?? 'merchandise';
        if (!in_array($active_tab, ['merchandise','encode_jo','tracker'])) $active_tab = 'merchandise';
        $tracker_tab = $_GET['tracker_tab'] ?? 'pending';
        if (!in_array($tracker_tab, ['pending','approved','rejected'])) $tracker_tab = 'pending';

        $jo_pending  = array_values(array_filter($job_orders, fn($j) => ($j['validation_status'] ?? '') === 'Pending Validation'));
        $jo_approved = array_values(array_filter($job_orders, fn($j) => ($j['validation_status'] ?? '') === 'Approved'));
        $jo_rejected = array_values(array_filter($job_orders, fn($j) => ($j['status'] ?? '') === 'Rejected'));
        ?>

        <!-- ── Page Header ───────────────────────────────────────────── -->
        <div class="txn-section-header">
            <div class="txn-section-title">
                <div class="txn-section-icon merch"><i class="fas fa-shopping-cart"></i></div>
                <div>
                    <h1>Transactions</h1>
                    <p style="font-size:12px;color:#64748b;margin:3px 0 0;">Merchandise sales, job order encoding, and status tracking.</p>
                </div>
            </div>
            <span class="status-badge customer" style="display:none;"></span>
        </div>

        <!-- ── Inner Tabs ─────────────────────────────────────────────── -->
        <div style="display:flex;gap:0;margin-bottom:24px;border-bottom:2px solid #e2e8f0;flex-wrap:wrap;">
            <?php
            $inner_tabs = [
                'merchandise' => ['label'=>'Merchandise/Service Transaction', 'icon'=>'fa-shopping-cart', 'color'=>'#28a745'],
                'tracker'     => ['label'=>'Job Order Tracker',       'icon'=>'fa-tasks',         'color'=>'#003d7a',
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

        <?php if (!empty($flash_success)): ?>
        <div class="flash success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($flash_success) ?></div>
        <?php endif; ?>
        <?php if (!empty($flash_error)): ?>
        <div class="flash error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($flash_error) ?></div>
        <?php endif; ?>

        <!-- ══════════════════════════════════════════════════════════
             TAB 1: MERCHANDISE/SERVICE TRANSACTION
        ══════════════════════════════════════════════════════════ -->
        <div id="innerTab_merchandise" style="display:<?= $active_tab === 'merchandise' ? 'block' : 'none' ?>;">

        <!-- Cart layout -->
        <div class="cart-wrapper">

            <!-- Left: Job Order section (top) + Merchandise section (bottom) + Customer/Payment -->
            <div>

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
                        <div class="txn-form-grid" style="margin-bottom:14px;">
                            <div class="txn-field">
                                <label>Assigned Mechanic</label>
                                <select id="joMechanic" class="txn-select">
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
                                    <div style="position:relative;">
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
                                                    display:flex;align-items:flex-start;
                                                    border-bottom:1px solid #f8fafc;gap:8px;
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

                    </div>
                </div><!-- /merchandise card -->

            </div><!-- /left column -->

            <!-- Payment + Cart panel — full width below the form -->
            <div class="cart-panel">

                <!-- ── Two-column inner layout: Payment left, Cart right ── -->
                <div style="display:grid;grid-template-columns:340px 1fr;gap:0;min-height:320px;">

                <!-- ── Customer & Payment (left column) ─── -->
                <div class="cart-panel-top" style="border-right:2px solid #f1f5f9;border-bottom:none;">
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
                            <option value="Credit">Credit (Utang)</option>
                        </select>
                    </div>

                    <!-- Cash fields -->
                    <div id="cashFields" style="display:none;margin-bottom:4px;">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                            <div class="txn-field">
                                <label style="font-size:10px;">Amount Tendered</label>
                                <input type="number" id="amountTendered" class="txn-input" style="font-size:12px;padding:7px 10px;"
                                       step="0.01" min="0" placeholder="Cash received"
                                       oninput="computeChange()">
                            </div>
                            <div class="txn-field">
                                <label style="font-size:10px;">Change</label>
                                <input type="number" id="changeAmount" class="txn-input computed" style="font-size:12px;padding:7px 10px;" readonly placeholder="—">
                            </div>
                        </div>
                        <div id="cashInsufficientNote" style="display:none;margin-top:6px;padding:6px 10px;background:#fee2e2;border:1px solid #fca5a5;border-radius:6px;color:#991b1b;font-size:11px;font-weight:600;display:flex;align-items:center;gap:5px;">
                            <i class="fas fa-exclamation-triangle" style="flex-shrink:0;"></i>
                            <span>Insufficient cash amount.</span>
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
                                <label style="font-size:10px;">Amount Paid <span style="color:#dc2626;">*</span></label>
                                <input type="number" id="cardAmount" class="txn-input" style="font-size:12px;padding:7px 10px;"
                                       step="0.01" min="0" placeholder="Amount paid"
                                       oninput="checkCardSufficiency()">
                            </div>
                            <div class="txn-field">
                                <label style="font-size:10px;">Reference No.</label>
                                <input type="text" id="refNumber" class="txn-input" style="font-size:12px;padding:7px 10px;" placeholder="Optional">
                            </div>
                        </div>
                        <div id="cardInsufficientNote" style="display:none;margin-top:6px;padding:6px 10px;background:#fee2e2;border:1px solid #fca5a5;border-radius:6px;color:#991b1b;font-size:11px;font-weight:600;display:flex;align-items:center;gap:5px;">
                            <i class="fas fa-exclamation-triangle" style="flex-shrink:0;"></i>
                            <span>Insufficient payment amount.</span>
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

        <!-- ══ TRANSACTION HISTORY PANEL ══ -->
        <div style="margin-top:16px;">
            <!-- Transaction History — full width -->
            <div class="txn-card" style="min-width:0;width:100%;">
                <div class="txn-card-header" style="background:#f5f3ff;">
                    <i class="fas fa-history" style="color:#6f42c1;"></i>
                    <h3 style="color:#6f42c1;">Transaction History</h3>
                    <span style="margin-left:auto;font-size:11px;color:#64748b;"><?= $mh_total ?> record(s)</span>
                </div>
                <!-- Filter bar -->
                <div style="padding:10px 14px;border-bottom:1px solid #f1f5f9;">
                    <form method="GET" action="staff_transactions_hub.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
                        <input type="hidden" name="section"    value="merchandise">
                        <input type="hidden" name="active_tab" value="merchandise">
                        <input type="hidden" name="mh_page"    value="1">
                        <div style="display:flex;flex-direction:column;gap:3px;flex:1;min-width:100px;">
                            <label style="font-size:9px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">Date</label>
                            <input type="date" name="mh_date" value="<?= htmlspecialchars($mh_filter_date) ?>"
                                   style="padding:5px 8px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:12px;color:#1e293b;background:#fff;width:100%;">
                        </div>
                        <div style="display:flex;flex-direction:column;gap:3px;flex:1;min-width:110px;">
                            <label style="font-size:9px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">Shift</label>
                            <select name="mh_shift" style="padding:5px 8px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:12px;color:#1e293b;background:#fff;width:100%;">
                                <option value="">All Shifts</option>
                                <?php foreach ($mh_available_shifts as $sh): ?>
                                <option value="<?= htmlspecialchars($sh['shift_key']) ?>" <?= $mh_filter_shift === $sh['shift_key'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($sh['shift_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="display:flex;gap:5px;align-items:flex-end;padding-bottom:1px;">
                            <button type="submit" style="padding:5px 12px;background:#6f42c1;color:#fff;border:none;border-radius:6px;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap;">
                                <i class="fas fa-filter"></i> Filter
                            </button>
                            <a href="staff_transactions_hub.php?section=merchandise&active_tab=merchandise"
                               style="padding:5px 10px;background:#f1f5f9;color:#475569;border:1.5px solid #e2e8f0;border-radius:6px;font-size:11px;font-weight:700;text-decoration:none;white-space:nowrap;">
                                <i class="fas fa-times"></i> Clear
                            </a>
                        </div>
                    </form>
                </div>
                <!-- Table -->
                <div style="padding:0;">
                    <?php if (empty($mh_recent)): ?>
                    <div style="text-align:center;padding:30px;color:#94a3b8;font-size:12px;">
                        <i class="fas fa-receipt" style="font-size:22px;display:block;margin-bottom:6px;"></i>
                        No transactions found.
                    </div>
                    <?php else: ?>
                    <div style="overflow-x:auto;">
                    <table class="txn-table" style="min-width:340px;font-size:11px;">
                        <thead>
                            <tr>
                                <th style="font-size:9px;">Txn ID</th>
                                <th style="font-size:9px;">Customer</th>
                                <th style="font-size:9px;">Amount</th>
                                <th style="font-size:9px;">Payment</th>
                                <th style="font-size:9px;">Shift</th>
                                <th style="font-size:9px;">Date</th>
                                <th style="font-size:9px;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($mh_recent as $mht): ?>
                        <tr>
                            <td style="font-size:10px;"><strong style="color:var(--petron-blue);"><?= htmlspecialchars($mht['transaction_id'] ?? ('#'.$mht['id'])) ?></strong></td>
                            <td style="font-size:10px;max-width:80px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($mht['customer_name'] ?? '—') ?></td>
                            <td style="font-size:10px;font-weight:700;color:var(--petron-blue);white-space:nowrap;">₱<?= number_format((float)($mht['total_amount'] ?? 0), 2) ?></td>
                            <td style="font-size:10px;"><?= htmlspecialchars($mht['payment_method'] ?? '—') ?></td>
                            <td style="font-size:10px;color:#64748b;max-width:80px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($mht['shift_name'] ?? $mht['shift_period'] ?? '—') ?></td>
                            <td style="font-size:10px;color:#64748b;white-space:nowrap;"><?= date('M j, g:i A', strtotime($mht['transaction_date'] ?? 'now')) ?></td>
                            <td><?= status_badge($mht['status'] ?? 'Pending') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <!-- Pagination footer -->
                    <?php $mh_total_pages = max(1, (int)ceil($mh_total / $mh_per_page)); ?>
                    <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;border-top:1px solid #f1f5f9;background:#fafbfc;">
                        <span style="font-size:11px;color:#64748b;">Rows per page:
                            <form method="GET" action="staff_transactions_hub.php" style="display:inline;">
                                <input type="hidden" name="section"    value="merchandise">
                                <input type="hidden" name="active_tab" value="merchandise">
                                <input type="hidden" name="mh_page"    value="1">
                                <input type="hidden" name="mh_date"    value="<?= htmlspecialchars($mh_filter_date) ?>">
                                <input type="hidden" name="mh_shift"   value="<?= htmlspecialchars($mh_filter_shift) ?>">
                                <select name="mh_per_page" onchange="this.form.submit()"
                                        style="padding:2px 4px;border:1px solid #e2e8f0;border-radius:4px;font-size:11px;color:#1e293b;background:#fff;cursor:pointer;">
                                    <?php foreach ([10,20,30,50] as $rpp): ?>
                                    <option value="<?= $rpp ?>" <?= $mh_per_page === $rpp ? 'selected' : '' ?>><?= $rpp ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </span>
                        <div style="display:flex;align-items:center;gap:10px;font-size:11px;color:#64748b;">
                            <?php
                            $mh_base = 'staff_transactions_hub.php?section=merchandise&active_tab=merchandise&mh_per_page='.$mh_per_page
                                .($mh_filter_date  ? '&mh_date='.urlencode($mh_filter_date)   : '')
                                .($mh_filter_shift ? '&mh_shift='.urlencode($mh_filter_shift) : '');
                            ?>
                            <?php if ($mh_page > 1): ?>
                            <a href="<?= $mh_base ?>&mh_page=<?= $mh_page - 1 ?>" style="color:#475569;text-decoration:none;">
                                <i class="fas fa-chevron-left" style="font-size:10px;"></i>
                            </a>
                            <?php else: ?>
                            <span style="color:#cbd5e1;"><i class="fas fa-chevron-left" style="font-size:10px;"></i></span>
                            <?php endif; ?>
                            <span>Page <strong><?= $mh_page ?></strong> of <strong><?= $mh_total_pages ?></strong></span>
                            <?php if ($mh_page < $mh_total_pages): ?>
                            <a href="<?= $mh_base ?>&mh_page=<?= $mh_page + 1 ?>" style="color:#475569;text-decoration:none;">
                                <i class="fas fa-chevron-right" style="font-size:10px;"></i>
                            </a>
                            <?php else: ?>
                            <span style="color:#cbd5e1;"><i class="fas fa-chevron-right" style="font-size:10px;"></i></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div><!-- /transaction history side panel -->

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
                    if (t.name === prev) opt.selected = true;
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
                        if (v.name === prev) opt.selected = true;
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
                        <span style="font-size:12px;font-weight:700;min-width:20px;text-align:center;">${item.quantity}</span>
                        <button type="button" onclick="cartQty(${idx},+1)" style="width:22px;height:22px;border:1px solid #e2e8f0;background:#f8fafc;border-radius:4px;cursor:pointer;font-size:13px;line-height:1;padding:0;">+</button>
                    </div>
                    <div style="font-size:12px;font-weight:700;color:var(--petron-blue);min-width:60px;text-align:right;flex-shrink:0;">₱${fmtNum(subtotal)}</div>
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
            updateCheckoutBtn();
        }

        function computeChange() {
            const grand    = getGrandTotal();
            const tendered = parseFloat(document.getElementById('amountTendered')?.value || 0);
            const change   = Math.max(0, tendered - grand);
            const changeEl = document.getElementById('changeAmount');
            if (changeEl) changeEl.value = change > 0 ? change.toFixed(2) : '';
            const note = document.getElementById('cashInsufficientNote');
            if (note) note.style.display = (tendered > 0 && tendered < grand) ? 'flex' : 'none';
        }

        function checkCardSufficiency() {
            const grand  = getGrandTotal();
            const amount = parseFloat(document.getElementById('cardAmount')?.value || 0);
            const note   = document.getElementById('cardInsufficientNote');
            if (note) note.style.display = (amount > 0 && amount < grand) ? 'flex' : 'none';
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

            // Payment validation
            if (method === 'Cash') {
                const tendered = parseFloat(document.getElementById('amountTendered')?.value || 0);
                if (tendered < grand) { showTxnAlert('Cash amount is insufficient.', 'warning'); return; }
            }
            if (['Card','E-Wallet','E-Fuel Card'].includes(method)) {
                const paid = parseFloat(document.getElementById('cardAmount')?.value || 0);
                if (paid < grand) { showTxnAlert('Payment amount is insufficient.', 'warning'); return; }
            }
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

            const payload = {
                action:              'create_transaction',
                customer_first_name: firstName || null,
                customer_last_name:  lastName  || null,
                customer_name:       [firstName, lastName].filter(Boolean).join(' ') || 'Walk-in Customer',
                payment_method:      method,
                amount_tendered:     parseFloat(document.getElementById('amountTendered')?.value || 0) || null,
                change_amount:       parseFloat(document.getElementById('changeAmount')?.value || 0) || null,
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
             TAB 2: ENCODE JOB ORDER
        ══════════════════════════════════════════════════════════ -->
        <div id="innerTab_encode_jo" style="display:<?= $active_tab === 'encode_jo' ? 'block' : 'none' ?>;">
        <div class="txn-card">
            <div class="txn-card-header" style="background:#fffbeb;">
                <i class="fas fa-tools" style="color:#b45309;"></i>
                <h3 style="color:#92400e;">Encode Job Order</h3>
                <span style="margin-left:auto;font-size:11px;color:#92400e;font-weight:500;">
                    Job Order ID auto-generated &nbsp;|&nbsp; Staff: <?= htmlspecialchars($me['name'] ?? $me['username']) ?>
                </span>
            </div>
            <div class="txn-card-body">


        <div class="job-card">
            <?php 
            $is_editing = isset($_SESSION['edit_job_order']);
            $edit_job = $_SESSION['edit_job_order'] ?? null;
            ?>
            <h2 style="margin-bottom: 30px; color: #003d7a;">
                <i class="fas fa-<?php echo $is_editing ? 'edit' : 'wrench'; ?>"></i> 
                <?php echo $is_editing ? 'Edit Job Order' : 'Encode Job Order'; ?>
                <?php if ($is_editing): ?>
                    <span style="color: #dc3545; font-size: 14px; margin-left: 10px;">
                        (Job Order #<?php echo $edit_job['id']; ?>)
                    </span>
                <?php endif; ?>
            </h2>
            
            <?php if ($is_editing): ?>
                <div style="background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px; padding: 15px; margin-bottom: 20px;">
                    <h5 style="color: #856404; margin: 0 0 10px 0;">
                        <i class="fas fa-exclamation-triangle"></i> Editing Rejected Job Order
                    </h5>
                    <p style="margin: 0; color: #856404; font-size: 14px;">
                        Please correct the errors that caused this job order to be rejected. 
                        After saving, the job order will return to Pending Validation for manager review.
                    </p>
                </div>
                
                <script>
                // Load existing job order data when editing
                document.addEventListener('DOMContentLoaded', function() {
                    <?php if ($is_editing && !empty($edit_job)): ?>
                        // Load existing parts data
                        const existingParts = <?php echo json_encode($edit_job['required_parts'] ?? []); ?>;
                        const existingManualParts = <?php echo json_encode($edit_job['manual_parts'] ?? []); ?>;
                        
                        console.log('Loading existing parts for editing:', existingParts, existingManualParts);
                        
                        // Load existing required parts
                        if (existingParts && existingParts.length > 0) {
                            setTimeout(() => {
                                loadExistingParts(existingParts);
                            }, 500);
                        }
                        
                        // Load existing manual parts
                        if (existingManualParts && existingManualParts.length > 0) {
                            setTimeout(() => {
                                loadExistingManualParts(existingManualParts);
                            }, 600);
                        }
                    <?php endif; ?>
                });
                
                function loadExistingParts(parts) {
                    const container = document.getElementById('required-parts-container');
                    if (!container) return;
                    
                    // Clear existing content
                    container.innerHTML = '';
                    
                    parts.forEach(part => {
                        const partDiv = document.createElement('div');
                        partDiv.style.cssText = 'margin: 5px 0; display: flex; align-items: center;';
                        partDiv.innerHTML = `
                            <input type="checkbox" name="required_parts[]" value="${part.part_name}" 
                                   data-part-id="${part.part_id}" data-category="${part.category}" 
                                   data-unit-cost="${part.unit_cost}" checked onchange="updatePartsSummary()">
                            <label style="margin-left: 8px; flex: 1;">
                                ${part.part_name} (${part.category}) - ₱${parseFloat(part.unit_cost).toFixed(2)}
                            </label>
                        `;
                        container.appendChild(partDiv);
                    });
                    
                    updatePartsSummary();
                }
                
                function loadExistingManualParts(manualParts) {
                    const container = document.getElementById('manual-parts-list');
                    if (!container) return;
                    
                    // Clear existing content
                    container.innerHTML = '';
                    
                    manualParts.forEach((part, index) => {
                        addManualPartRowWithData(part);
                    });
                    
                    updatePartsSummary();
                }
                
                function addManualPartRowWithData(part) {
                    const container = document.getElementById('manual-parts-list');
                    const rowDiv = document.createElement('div');
                    rowDiv.style.cssText = 'margin: 5px 0; display: flex; align-items: center; gap: 10px;';
                    rowDiv.innerHTML = `
                        <input type="text" name="manual_part_names[]" placeholder="Part name" 
                               value="${part.part_name || ''}" style="flex: 1;" onchange="updatePartsSummary()">
                        <input type="number" name="manual_part_quantities[]" placeholder="Qty" 
                               value="${part.quantity || 1}" min="1" style="width: 80px;" onchange="updatePartsSummary()">
                        <input type="number" name="manual_part_costs[]" placeholder="Cost" 
                               value="${part.unit_cost || 0}" min="0" step="0.01" style="width: 100px;" onchange="updatePartsSummary()">
                        <button type="button" onclick="this.parentElement.remove(); updatePartsSummary();" 
                                class="btn btn-sm btn-danger" style="padding: 2px 8px;">×</button>
                    `;
                    container.appendChild(rowDiv);
                }
                </script>
            <?php endif; ?>
            
            <form method="post" action="joborder.php" onsubmit="injectPartsServiceData()">
                <input type="hidden" name="action" value="<?php echo $is_editing ? 'update_job_order' : 'create_job_order'; ?>">
                <?php if ($is_editing): ?>
                    <input type="hidden" name="job_order_id" value="<?php echo $edit_job['id']; ?>">
                <?php endif; ?>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Customer Name</label>
                        <input type="text" name="customer_name" class="form-input" 
                               placeholder="Walk-in customer name" required
                               value="<?php echo htmlspecialchars($edit_job['customer_name'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Credit Customer (Optional)</label>
                        <select name="credit_customer_id" class="form-select" id="credit_customer_select" onchange="handleCreditCustomerChange()">
                            <option value="">Select credit customer (optional)</option>
                            <?php foreach ($customers as $customer): ?>
                                <option value="<?php echo $customer['id']; ?>" 
                                        data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                        data-credit-limit="<?php echo $customer['credit_limit']; ?>"
                                        data-balance="<?php echo $customer['balance']; ?>"
                                        data-reference-number="<?php echo htmlspecialchars($customer['reference_number'] ?? ''); ?>"
                                        data-receivable-id="<?php echo $customer['receivable_id'] ?? ''; ?>"
                                        <?php echo ($edit_job['credit_customer_id'] ?? '') == $customer['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($customer['id'] . ' - ' . $customer['name']); ?>
                                    (Limit: <?php echo number_format($customer['credit_limit'], 2); ?>, Balance: <?php echo number_format($customer['balance'], 2); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="receivable_id" id="receivable_id" value="">
                        <input type="hidden" name="is_credit_transaction" id="is_credit_transaction" value="false">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Service Type</label>
                        <div id="service-types-container" style="border: 1px solid #ddd; padding: 15px; border-radius: 6px 6px 0 0; background: #f8f9fa; max-height: 300px; overflow-y: auto;">
                            <?php
                            // Load service types with pricing information
                            try {
                                // Use simple query that definitely works
                                $service_types_with_parts = [];
                                
                                // First check if table exists
                                $table_check = $pdo->query("SHOW TABLES LIKE 'job_order_service_types'");
                                if ($table_check->rowCount() > 0) {
                                    // Check if service_price column exists
                                    $column_check = $pdo->query("SHOW COLUMNS FROM job_order_service_types LIKE 'service_price'");
                                    if ($column_check->rowCount() > 0) {
                                        // Use the working query
                                        $stmt = $pdo->query("SELECT service_key, service_name, base_rate_per_hour, service_price, min_price, max_price, price_description, pricing_notes, icon_class, color_class, 0 as parts_count FROM job_order_service_types WHERE active = TRUE ORDER BY sort_order, service_name");
                                        $service_types_with_parts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                    } else {
                                        // Fallback: Add service_price column if missing
                                        $pdo->exec("ALTER TABLE job_order_service_types ADD COLUMN service_price DECIMAL(10,2) NOT NULL DEFAULT 400.00");
                                        $pdo->exec("ALTER TABLE job_order_service_types ADD COLUMN min_price DECIMAL(10,2) NOT NULL DEFAULT 0.00");
                                        $pdo->exec("ALTER TABLE job_order_service_types ADD COLUMN max_price DECIMAL(10,2) NOT NULL DEFAULT 0.00");
                                        $pdo->exec("ALTER TABLE job_order_service_types ADD COLUMN price_description VARCHAR(255) NULL");
                                        $pdo->exec("ALTER TABLE job_order_service_types ADD COLUMN pricing_notes TEXT NULL");
                                        
                                        // Try query again
                                        $stmt = $pdo->query("SELECT service_key, service_name, base_rate_per_hour, service_price, min_price, max_price, price_description, pricing_notes, icon_class, color_class, 0 as parts_count FROM job_order_service_types WHERE active = TRUE ORDER BY sort_order, service_name");
                                        $service_types_with_parts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                    }
                                } else {
                                    // Create table if it doesn't exist
                                    $pdo->exec("CREATE TABLE IF NOT EXISTS job_order_service_types (
                                        id INT AUTO_INCREMENT PRIMARY KEY,
                                        service_key VARCHAR(50) UNIQUE NOT NULL,
                                        service_name VARCHAR(100) NOT NULL,
                                        base_rate_per_hour DECIMAL(10,2) DEFAULT 0.00,
                                        service_price DECIMAL(10,2) NOT NULL DEFAULT 400.00,
                                        min_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                                        max_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                                        price_description VARCHAR(255) NULL,
                                        pricing_notes TEXT NULL,
                                        icon_class VARCHAR(50) NULL,
                                        color_class VARCHAR(20) NULL,
                                        allows_custom_input TINYINT(1) DEFAULT 0,
                                        active TINYINT(1) DEFAULT 1,
                                        sort_order INT DEFAULT 0,
                                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                                    )");
                                    
                                    // Insert default data
                                    $default_services = [
                                        ['oil_change', 'Oil Change', 3540.00, 1700.00, 5380.00, '₱1,700 to ₱5,380 (depends on oil type and filter)', 'Consider: Oil type (mineral vs synthetic), filter quality, engine size, oil capacity', 'fas fa-oil-can', 'text-success', 1],
                                        ['tire_repair', 'Tire Repair', 500.00, 300.00, 700.00, '₱300 to ₱700 per tire (depends on puncture size)', 'Consider: Puncture size/location, tire condition, patch vs plug vs replacement', 'fas fa-circle-dot', 'text-warning', 2],
                                        ['calibration', 'Calibration', 3400.00, 800.00, 6000.00, '₱800 to ₱6,000+ (depends on equipment type)', 'Consider: Equipment type, number of pumps, calibration complexity', 'fas fa-tachometer-alt', 'text-info', 3],
                                    ];
                                    
                                    $stmt = $pdo->prepare("INSERT INTO job_order_service_types (service_key, service_name, service_price, min_price, max_price, price_description, pricing_notes, icon_class, color_class, allows_custom_input, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                    foreach ($default_services as $service) {
                                        $stmt->execute($service);
                                    }
                                    
                                    // Get the data
                                    $stmt = $pdo->query("SELECT service_key, service_name, base_rate_per_hour, service_price, min_price, max_price, price_description, pricing_notes, icon_class, color_class, 0 as parts_count FROM job_order_service_types WHERE active = TRUE ORDER BY sort_order, service_name");
                                    $service_types_with_parts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                }
                                
                                // Get selected service types for editing
                                $selected_services = [];
                                if ($is_editing && !empty($edit_job['service_type'])) {
                                    $selected_services = array_map('trim', explode(',', $edit_job['service_type']));
                                }
                                
                                foreach ($service_types_with_parts as $service):
                                    $is_selected = in_array($service['service_name'], $selected_services);
                                ?>
                                    <div style="margin: 8px 0; padding: 12px; background: white; border-radius: 4px; border: 1px solid #e9ecef;">
                                        <div style="display: flex; align-items: center; margin-bottom: 12px; padding: 8px; border-radius: 6px; background: #f8f9fa; border: 1px solid #e9ecef; transition: all 0.2s ease;" 
                                             onmouseover="this.style.background='#e9ecef'; this.style.borderColor='#dee2e6';"
                                             onmouseout="this.style.background='#f8f9fa'; this.style.borderColor='#e9ecef';">
                                            <input type="checkbox" 
                                                   name="service_types[]" 
                                                   value="<?php echo htmlspecialchars($service['service_name']); ?>"
                                                   id="service_<?php echo htmlspecialchars($service['service_key']); ?>"
                                                   data-service-key="<?php echo htmlspecialchars($service['service_key']); ?>"
                                                   data-min-price="<?php echo htmlspecialchars($service['min_price']); ?>"
                                                   data-max-price="<?php echo htmlspecialchars($service['max_price']); ?>"
                                                   data-default-price="<?php echo htmlspecialchars($service['service_price']); ?>"
                                                   data-parts-count="<?php echo $service['parts_count']; ?>"
                                                   onchange="toggleServicePrice('<?php echo htmlspecialchars($service['service_key']); ?>')"
                                                   <?php echo $is_selected ? 'checked' : ''; ?>
                                                   style="margin-right: 12px; width: 18px; height: 18px; cursor: pointer;">
                                            <label for="service_<?php echo htmlspecialchars($service['service_key']); ?>" style="flex: 1; margin: 0; cursor: pointer; display: flex; align-items: center; font-size: 0.95em;">
                                                <?php if (!empty($service['icon_class'])): ?>
                                                    <i class="<?php echo htmlspecialchars($service['icon_class']); ?>" style="margin-right: 8px; color: <?php echo htmlspecialchars($service['color_class'] ?? '#003d7a'); ?>; font-size: 1.1em;"></i>
                                                <?php endif; ?>
                                                <span style="font-weight: 600; color: #212529;"><?php echo htmlspecialchars($service['service_name']); ?></span>
                                                <span style="margin-left: 8px; font-size: 0.8em; color: #6c757d; font-weight: normal;">
                                                    (₱<?php echo number_format($service['min_price'], 0); ?> - ₱<?php echo number_format($service['max_price'], 0); ?>)
                                                </span>
                                            </label>
                                        </div>
                                        
                                        <!-- Price Range Display and Edit -->
                                        <div id="price_container_<?php echo htmlspecialchars($service['service_key']); ?>" style="margin-left: 30px; display: <?php echo $is_selected ? 'block' : 'none'; ?>;">
                                            <div style="background: #f8f9fa; padding: 8px; border-radius: 5px; border-left: 4px solid #007bff; margin-bottom: 8px;">
                                                <div style="font-size: 0.9em; color: #495057; margin-bottom: 4px; font-weight: 600;">
                                                    <i class="fas fa-tag" style="color: #007bff; margin-right: 5px;"></i>
                                                    Price Range:
                                                </div>
                                                <div style="font-size: 1.1em; font-weight: bold; color: #212529; margin-bottom: 4px;">
                                                    ₱<?php echo number_format($service['min_price'], 2); ?> - ₱<?php echo number_format($service['max_price'], 2); ?>
                                                </div>
                                                <?php if (!empty($service['price_description'])): ?>
                                                    <div style="font-size: 0.8em; color: #6c757d; font-style: italic;">
                                                        <?php echo htmlspecialchars($service['price_description']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 5px;">
                                                <label style="font-size: 0.9em; margin: 0; font-weight: 500; color: #495057;">
                                                    <i class="fas fa-edit" style="color: #28a745; margin-right: 3px;"></i>
                                                    Your Price:
                                                </label>
                                                <div style="display: flex; align-items: center; border: 1px solid #ced4da; border-radius: 4px; overflow: hidden;">
                                                    <span style="background: #e9ecef; padding: 6px 8px; font-size: 0.9em; color: #495057; font-weight: 500; border-right: 1px solid #ced4da;">₱</span>
                                                    <input type="number" 
                                                           name="service_price_<?php echo htmlspecialchars($service['service_key']); ?>"
                                                           id="price_<?php echo htmlspecialchars($service['service_key']); ?>"
                                                           min="<?php echo htmlspecialchars($service['min_price']); ?>"
                                                           max="<?php echo htmlspecialchars($service['max_price']); ?>"
                                                           step="0.01"
                                                           value="<?php echo htmlspecialchars($service['service_price']); ?>"
                                                           onchange="validateServicePrice('<?php echo htmlspecialchars($service['service_key']); ?>')"
                                                           onkeyup="updatePaymentSummary()"
                                                           style="width: 130px; padding: 6px 8px; border: none; font-size: 0.95em; font-weight: 500;"
                                                           placeholder="0.00">
                                                </div>
                                                <span id="price_error_<?php echo htmlspecialchars($service['service_key']); ?>" style="font-size: 0.8em; color: #dc3545; display: none; margin-left: 5px;"></span>
                                            </div>
                                            <?php if (!empty($service['pricing_notes'])): ?>
                                                <div style="background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px; padding: 6px 8px; margin-top: 5px;">
                                                    <div style="font-size: 0.8em; color: #856404; font-weight: 500; margin-bottom: 2px;">
                                                        <i class="fas fa-lightbulb" style="color: #ffc107; margin-right: 3px;"></i>
                                                        Considerations:
                                                    </div>
                                                    <div style="font-size: 0.75em; color: #856404; line-height: 1.3;">
                                                        <?php echo htmlspecialchars($service['pricing_notes']); ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>


                                        </div>
                                    </div>
                                <?php endforeach;
                            } catch (Exception $e) {
                                echo '<div style="color: red; padding: 10px;">Error loading service types: ' . htmlspecialchars($e->getMessage()) . '</div>';
                            }
                            ?>
                        </div>
                        
                        <div class="section-action-bar">
                            <button type="button" onclick="selectAllServiceTypes()" class="action-btn action-btn--primary">
                                <i class="fas fa-check-square"></i> Select All
                            </button>
                            <button type="button" onclick="clearServiceTypes()" class="action-btn action-btn--outline-danger">
                                <i class="fas fa-times"></i> Clear
                            </button>
                        </div>

                        <!-- Hidden input for backward compatibility -->
                        <input type="hidden" name="service_type" id="service_type_combined" value="">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Assigned Mechanic</label>
                        <select name="assigned_mechanic_id" class="form-select" required id="mechanic_select" onchange="checkMechanicStatus()">
                            <option value="">Select mechanic</option>
                            <?php foreach ($mechanics as $mechanic): ?>
                                <option value="<?php echo $mechanic['id']; ?>" 
                                        <?php echo ($edit_job['assigned_mechanic_id'] ?? '') == $mechanic['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($mechanic['full_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="staff_override" id="staff_override" value="false">
                        <input type="hidden" name="override_reason" id="override_reason" value="">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Vehicle Plate Number</label>
                        <input type="text" name="vehicle_plate" class="form-input" 
                               placeholder="Enter plate number" required
                               value="<?php echo htmlspecialchars($edit_job['vehicle_plate'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Vehicle Type</label>
                        <input type="text" name="vehicle_type" class="form-input" 
                               placeholder="Enter vehicle description (e.g., Toyota Hilux Pickup, Honda Click 125)" required
                               value="<?php echo htmlspecialchars($edit_job['vehicle_type'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <div id="auto-populate-indicator" style="margin-top: 5px; font-size: 12px; color: #28a745; display: block;">
                            <i class="fas fa-info-circle"></i> <span id="auto-populate-text">Select service types above to auto-populate parts</span>
                        </div>
                        <div id="required-parts-container">
                    <style>
                        .part-item {
                            margin: 6px 0;
                            padding: 9px 14px;
                            background: #fff;
                            border: 1px solid #e2e8f0;
                            border-radius: 6px;
                            display: flex;
                            align-items: center;
                            transition: border-color 0.15s, box-shadow 0.15s;
                        }
                        .part-item:hover {
                            border-color: #003d7a;
                            box-shadow: 0 1px 4px rgba(0,61,122,0.10);
                        }
                        .part-item input[type="checkbox"] {
                            accent-color: #003d7a;
                            width: 16px;
                            height: 16px;
                            cursor: pointer;
                            flex-shrink: 0;
                        }
                        .part-item label {
                            cursor: pointer;
                            flex: 1;
                            margin: 0 0 0 10px;
                            font-size: 13px;
                            font-weight: 500;
                            color: #212529;
                            display: flex;
                            align-items: center;
                            flex-wrap: wrap;
                            gap: 4px;
                        }
                        .part-item .part-controls {
                            display: flex;
                            align-items: center;
                            gap: 6px;
                            flex-shrink: 0;
                        }
                        .part-item .part-controls input[type="number"] {
                            width: 58px;
                            padding: 5px 6px;
                            border: 1px solid #ced4da;
                            border-radius: 4px;
                            font-size: 13px;
                            text-align: center;
                        }
                        .part-item .part-controls input[type="text"] {
                            width: 130px;
                            padding: 5px 8px;
                            border: 1px solid #ced4da;
                            border-radius: 4px;
                            font-size: 12px;
                        }
                        .part-item .part-controls .btn-remove {
                            background: none;
                            color: #adb5bd;
                            border: 1px solid #dee2e6;
                            border-radius: 4px;
                            padding: 4px 8px;
                            cursor: pointer;
                            font-size: 12px;
                            transition: color 0.15s, border-color 0.15s;
                        }
                        .part-item .part-controls .btn-remove:hover {
                            color: #dc3545;
                            border-color: #dc3545;
                        }
                        .service-parts {
                            display: none;
                        }
                        .service-parts.active {
                            display: block !important;
                        }
                        .service-parts-header {
                            display: flex;
                            align-items: center;
                            padding: 7px 12px;
                            margin: 10px 0 4px 0;
                            background: #eef2fb;
                            border-left: 4px solid #003d7a;
                            border-radius: 4px;
                            font-weight: 600;
                            font-size: 12px;
                            color: #003d7a;
                            letter-spacing: 0.3px;
                            text-transform: uppercase;
                        }
                        .service-parts-header i {
                            margin-right: 7px;
                            font-size: 13px;
                        }
                        #required-parts-container {
                            border: 1px solid #dee2e6;
                            border-radius: 6px 6px 0 0;
                            padding: 10px 12px;
                            max-height: 420px;
                            overflow-y: auto;
                            background: #f8f9fa;
                        }
                        #required-parts-container::-webkit-scrollbar { width: 5px; }
                        #required-parts-container::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 3px; }
                        #required-parts-container::-webkit-scrollbar-thumb { background: #c1c9d6; border-radius: 3px; }

                        /* ── Shared action bar (bottom of both sections) ── */
                        .section-action-bar {
                            display: flex;
                            justify-content: flex-end;
                            align-items: center;
                            gap: 8px;
                            padding: 7px 10px;
                            background: #f1f3f5;
                            border: 1px solid #dee2e6;
                            border-top: none;
                            border-radius: 0 0 6px 6px;
                        }
                        .action-btn {
                            display: inline-flex;
                            align-items: center;
                            gap: 5px;
                            padding: 5px 13px;
                            font-size: 12px;
                            font-weight: 600;
                            border-radius: 4px;
                            cursor: pointer;
                            transition: opacity .15s, background .15s;
                            white-space: nowrap;
                        }
                        .action-btn:hover { opacity: .85; }
                        .action-btn--primary {
                            background: #003d7a;
                            color: #fff;
                            border: none;
                        }
                        .action-btn--outline-danger {
                            background: #fff;
                            color: #dc3545;
                            border: 1.5px solid #dc3545;
                        }
                    </style>
                    
                    <!-- Placeholder Message -->
                    <div id="parts-placeholder" style="padding:20px;text-align:center;color:#666;">
                        <i class="fas fa-tools" style="font-size: 24px; margin-bottom: 10px;"></i>
                        <p>Parts will appear here when you select service types above</p>
                    </div>

                    <!-- Oil Change Parts -->
                    <div id="oil-change-parts" class="service-parts" style="display: none;">
                        <div class="service-parts-header"><i class="fas fa-oil-can"></i> Oil Change — Required Parts</div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Engine Oil HD30" id="oil_part_1" onchange="updatePartsSummary()">
                            <label for="oil_part_1">
                                Engine Oil HD30
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱114.40</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Engine Oil HD40" id="oil_part_2" onchange="updatePartsSummary()">
                            <label for="oil_part_2">
                                Engine Oil HD40
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱120.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Engine Oil Ultron Touring" id="oil_part_3" onchange="updatePartsSummary()">
                            <label for="oil_part_3">
                                Engine Oil Ultron Touring
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱185.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Engine Oil Blaze Racing" id="oil_part_4" onchange="updatePartsSummary()">
                            <label for="oil_part_4">
                                Engine Oil Blaze Racing
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱210.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Engine Oil MO30/MO40" id="oil_part_5" onchange="updatePartsSummary()">
                            <label for="oil_part_5">
                                Engine Oil MO30/MO40
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱130.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Oil Filter Nomis" id="oil_part_6" onchange="updatePartsSummary()">
                            <label for="oil_part_6">
                                Oil Filter Nomis
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱180.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Oil Filter VIC" id="oil_part_7" onchange="updatePartsSummary()">
                            <label for="oil_part_7">
                                Oil Filter VIC
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱200.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Oil Filter Sakura" id="oil_part_8" onchange="updatePartsSummary()">
                            <label for="oil_part_8">
                                Oil Filter Sakura
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱195.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Oil Filter C-series" id="oil_part_9" onchange="updatePartsSummary()">
                            <label for="oil_part_9">
                                Oil Filter C-series
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱220.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Gasket Maker" id="oil_part_10" onchange="updatePartsSummary()">
                            <label for="oil_part_10">
                                Gasket Maker
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱95.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Tire Repair Parts -->
                    <div id="tire-repair-parts" class="service-parts" style="display: none;">
                        <div class="service-parts-header"><i class="fas fa-circle-notch"></i> Tire Repair — Required Parts</div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Tire Valve Rubber" id="tire_part_1" onchange="updatePartsSummary()">
                            <label for="tire_part_1">
                                Tire Valve Rubber
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱45.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Tire Valve Steel" id="tire_part_2" onchange="updatePartsSummary()">
                            <label for="tire_part_2">
                                Tire Valve Steel
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱60.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="MP1 Patch (Med)" id="tire_part_3" onchange="updatePartsSummary()">
                            <label for="tire_part_3">
                                MP1 Patch (Med)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱35.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="MP2 Patch (Large)" id="tire_part_4" onchange="updatePartsSummary()">
                            <label for="tire_part_4">
                                MP2 Patch (Large)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱50.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="CT20 Radial Patch" id="tire_part_5" onchange="updatePartsSummary()">
                            <label for="tire_part_5">
                                CT20 Radial Patch
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱75.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Valkarn Cement" id="tire_part_6" onchange="updatePartsSummary()">
                            <label for="tire_part_6">
                                Valkarn Cement
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱90.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Calibration Parts -->
                    <div id="calibration-parts" class="service-parts" style="display: none;">
                        <div class="service-parts-header"><i class="fas fa-tachometer-alt"></i> Calibration — Required Parts</div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Hydrotur (oil/lube)" id="cal_part_1" onchange="updatePartsSummary()">
                            <label for="cal_part_1">
                                Hydrotur (oil/lube)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱120.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="MP Grease (sealant)" id="cal_part_2" onchange="updatePartsSummary()">
                            <label for="cal_part_2">
                                MP Grease (sealant)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱89.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Standard Gauge (from accessories)" id="cal_part_3" onchange="updatePartsSummary()">
                            <label for="cal_part_3">
                                Standard Gauge (from accessories)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱350.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- General Maintenance Parts -->
                    <div id="general-maintenance-parts" class="service-parts" style="display: none;">
                        <div class="service-parts-header"><i class="fas fa-wrench"></i> General Maintenance — Required Parts</div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="MP Grease" id="gm_part_1" onchange="updatePartsSummary()">
                            <label for="gm_part_1">
                                MP Grease
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱89.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="WD-40" id="gm_part_2" onchange="updatePartsSummary()">
                            <label for="gm_part_2">
                                WD-40
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 can</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱150.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Petromate Oil" id="gm_part_3" onchange="updatePartsSummary()">
                            <label for="gm_part_3">
                                Petromate Oil
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 can</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱135.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Armor All (Small)" id="gm_part_4" onchange="updatePartsSummary()">
                            <label for="gm_part_4">
                                Armor All (Small)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">Small</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱180.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Armor All (Big)" id="gm_part_5" onchange="updatePartsSummary()">
                            <label for="gm_part_5">
                                Armor All (Big)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">Big</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱320.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="VS1 Protector (Small)" id="gm_part_6" onchange="updatePartsSummary()">
                            <label for="gm_part_6">
                                VS1 Protector (Small)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">Small</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱160.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="VS1 Protector (Big)" id="gm_part_7" onchange="updatePartsSummary()">
                            <label for="gm_part_7">
                                VS1 Protector (Big)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">Big</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱300.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Chamois/Kanebo" id="gm_part_8" onchange="updatePartsSummary()">
                            <label for="gm_part_8">
                                Chamois/Kanebo
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱95.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Engine Repair Parts -->
                    <div id="engine-repair-parts" class="service-parts" style="display: none;">
                        <div class="service-parts-header"><i class="fas fa-cogs"></i> Engine Repair — Required Parts</div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Engine Oil HD30" id="eng_part_1" onchange="updatePartsSummary()">
                            <label for="eng_part_1">
                                Engine Oil HD30
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱114.40</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Engine Oil HD40" id="eng_part_2" onchange="updatePartsSummary()">
                            <label for="eng_part_2">
                                Engine Oil HD40
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱120.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Engine Oil Ultron" id="eng_part_3" onchange="updatePartsSummary()">
                            <label for="eng_part_3">
                                Engine Oil Ultron
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱185.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Engine Oil Blaze Racing" id="eng_part_4" onchange="updatePartsSummary()">
                            <label for="eng_part_4">
                                Engine Oil Blaze Racing
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱210.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Engine Oil Trekker" id="eng_part_5" onchange="updatePartsSummary()">
                            <label for="eng_part_5">
                                Engine Oil Trekker
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱195.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Oil Filter Nomis" id="eng_part_6" onchange="updatePartsSummary()">
                            <label for="eng_part_6">
                                Oil Filter Nomis
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱180.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Oil Filter VIC" id="eng_part_7" onchange="updatePartsSummary()">
                            <label for="eng_part_7">
                                Oil Filter VIC
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱200.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Oil Filter Sakura" id="eng_part_8" onchange="updatePartsSummary()">
                            <label for="eng_part_8">
                                Oil Filter Sakura
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱195.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Oil Filter C-series" id="eng_part_9" onchange="updatePartsSummary()">
                            <label for="eng_part_9">
                                Oil Filter C-series
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱220.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Coolant Regular" id="eng_part_10" onchange="updatePartsSummary()">
                            <label for="eng_part_10">
                                Coolant Regular
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱110.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Coolant Green" id="eng_part_11" onchange="updatePartsSummary()">
                            <label for="eng_part_11">
                                Coolant Green
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱115.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Coolant Pink" id="eng_part_12" onchange="updatePartsSummary()">
                            <label for="eng_part_12">
                                Coolant Pink
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱120.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Gasket Maker" id="eng_part_13" onchange="updatePartsSummary()">
                            <label for="eng_part_13">
                                Gasket Maker
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱95.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Brake Service Parts -->
                    <div id="brake-service-parts" class="service-parts" style="display: none;">
                        <div class="service-parts-header"><i class="fas fa-compact-disc"></i> Brake Service — Required Parts</div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Brake Fluid 900ml" id="brake_part_1" onchange="updatePartsSummary()">
                            <label for="brake_part_1">
                                Brake Fluid 900ml
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">900ml</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱160.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Brake Fluid Med" id="brake_part_2" onchange="updatePartsSummary()">
                            <label for="brake_part_2">
                                Brake Fluid Med
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">500ml</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱120.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Brake Fluid Small" id="brake_part_3" onchange="updatePartsSummary()">
                            <label for="brake_part_3">
                                Brake Fluid Small
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">250ml</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱95.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Brake Cleaner Hardex" id="brake_part_4" onchange="updatePartsSummary()">
                            <label for="brake_part_4">
                                Brake Cleaner Hardex
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 can</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱150.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Electrical Service Parts -->
                    <div id="electrical-parts" class="service-parts" style="display: none;">
                        <div class="service-parts-header"><i class="fas fa-bolt"></i> Electrical Service — Required Parts</div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="WD-40" id="elec_part_1" onchange="updatePartsSummary()">
                            <label for="elec_part_1">
                                WD-40
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 can</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱150.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Petromate Oil" id="elec_part_2" onchange="updatePartsSummary()">
                            <label for="elec_part_2">
                                Petromate Oil
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 can</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱135.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="MP Grease (for terminals)" id="elec_part_3" onchange="updatePartsSummary()">
                            <label for="elec_part_3">
                                MP Grease (for terminals)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱89.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Air Conditioning Parts -->
                    <div id="air-conditioning-parts" class="service-parts" style="display: none;">
                        <div class="service-parts-header"><i class="fas fa-snowflake"></i> Air Conditioning — Required Parts</div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Coolant Green" id="ac_part_1" onchange="updatePartsSummary()">
                            <label for="ac_part_1">
                                Coolant Green
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱115.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Coolant Pink" id="ac_part_2" onchange="updatePartsSummary()">
                            <label for="ac_part_2">
                                Coolant Pink
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱120.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="AC Filter (Oil/Fuel Filter variants)" id="ac_part_3" onchange="updatePartsSummary()">
                            <label for="ac_part_3">
                                AC Filter (Oil/Fuel Filter variants)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱250.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="O-rings (from accessories)" id="ac_part_4" onchange="updatePartsSummary()">
                            <label for="ac_part_4">
                                O-rings (from accessories)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 set</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱45.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Transmission Service Parts -->
                    <div id="transmission-service-parts" class="service-parts" style="display: none;">
                        <div class="service-parts-header"><i class="fas fa-cog"></i> Transmission Service — Required Parts</div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="ATF Premium" id="trans_part_1" onchange="updatePartsSummary()">
                            <label for="trans_part_1">
                                ATF Premium
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱185.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="ATF HTF" id="trans_part_2" onchange="updatePartsSummary()">
                            <label for="trans_part_2">
                                ATF HTF
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱195.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Transmission Filter (Fuel/Oil Filter variants)" id="trans_part_3" onchange="updatePartsSummary()">
                            <label for="trans_part_3">
                                Transmission Filter (Fuel/Oil Filter variants)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱240.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Gasket Maker" id="trans_part_4" onchange="updatePartsSummary()">
                            <label for="trans_part_4">
                                Gasket Maker
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱95.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Suspension Repair Parts -->
                    <div id="suspension-repair-parts" class="service-parts" style="display: none;">
                        <div class="service-parts-header"><i class="fas fa-car-crash"></i> Suspension Repair — Required Parts</div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="MP Grease (for bushings/ball joints)" id="sus_part_1" onchange="updatePartsSummary()">
                            <label for="sus_part_1">
                                MP Grease (for bushings/ball joints)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱89.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Shock Absorber (if stocked)" id="sus_part_2" onchange="updatePartsSummary()">
                            <label for="sus_part_2">
                                Shock Absorber (if stocked)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱1850.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Wheel Alignment Parts -->
                    <div id="wheel-alignment-parts" class="service-parts" style="display: none;">
                        <div class="service-parts-header"><i class="fas fa-dot-circle"></i> Wheel Alignment — Required Parts</div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Tire Valve Rubber" id="wheel_part_1" onchange="updatePartsSummary()">
                            <label for="wheel_part_1">
                                Tire Valve Rubber
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱45.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Tire Valve Steel" id="wheel_part_2" onchange="updatePartsSummary()">
                            <label for="wheel_part_2">
                                Tire Valve Steel
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱60.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Alignment Bolts/Wheel Weights (from accessories)" id="wheel_part_3" onchange="updatePartsSummary()">
                            <label for="wheel_part_3">
                                Alignment Bolts/Wheel Weights (from accessories)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 set</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱150.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Battery Replacement Parts -->
                    <div id="battery-replacement-parts" class="service-parts" style="display: none;">
                        <div class="service-parts-header"><i class="fas fa-car-battery"></i> Battery Replacement — Required Parts</div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Car Battery (if stocked)" id="bat_part_1" onchange="updatePartsSummary()">
                            <label for="bat_part_1">
                                Car Battery (if stocked)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱3820.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="MP Grease (small packs for terminals)" id="bat_part_2" onchange="updatePartsSummary()">
                            <label for="bat_part_2">
                                MP Grease (small packs for terminals)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱89.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Diagnostic Check Parts -->
                    <div id="diagnostic-check-parts" class="service-parts" style="display: none;">
                        <div class="service-parts-header"><i class="fas fa-stethoscope"></i> Diagnostic Check — Required Parts</div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="OBD Scanner (tool)" id="diag_part_1" onchange="updatePartsSummary()">
                            <label for="diag_part_1">
                                OBD Scanner (tool)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱2500.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Diagnostic Printout Paper (office supply)" id="diag_part_2" onchange="updatePartsSummary()">
                            <label for="diag_part_2">
                                Diagnostic Printout Paper (office supply)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 set</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱25.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Detailing / Cleaning Parts -->
                    <div id="detailing-cleaning-parts" class="service-parts" style="display: none;">
                        <div class="service-parts-header"><i class="fas fa-spray-can"></i> Detailing / Cleaning — Required Parts</div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Clean N Shine Shampoo" id="detail_part_1" onchange="updatePartsSummary()">
                            <label for="detail_part_1">
                                Clean N Shine Shampoo
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 bottle</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱180.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Armor All (Small)" id="detail_part_2" onchange="updatePartsSummary()">
                            <label for="detail_part_2">
                                Armor All (Small)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">Small</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱180.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Armor All (Big)" id="detail_part_3" onchange="updatePartsSummary()">
                            <label for="detail_part_3">
                                Armor All (Big)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">Big</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱320.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Tire Black (Small)" id="detail_part_4" onchange="updatePartsSummary()">
                            <label for="detail_part_4">
                                Tire Black (Small)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">Small</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱150.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Tire Black (Big)" id="detail_part_5" onchange="updatePartsSummary()">
                            <label for="detail_part_5">
                                Tire Black (Big)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">Big</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱280.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Chamois/Kanebo" id="detail_part_6" onchange="updatePartsSummary()">
                            <label for="detail_part_6">
                                Chamois/Kanebo
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱95.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Air Freshener Neo Shaldan" id="detail_part_7" onchange="updatePartsSummary()">
                            <label for="detail_part_7">
                                Air Freshener Neo Shaldan
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱120.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Air Freshener California Scents" id="detail_part_8" onchange="updatePartsSummary()">
                            <label for="detail_part_8">
                                Air Freshener California Scents
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱150.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Air Freshener Little Trees" id="detail_part_9" onchange="updatePartsSummary()">
                            <label for="detail_part_9">
                                Air Freshener Little Trees
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱95.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Air Freshener Glade Spray" id="detail_part_10" onchange="updatePartsSummary()">
                            <label for="detail_part_10">
                                Air Freshener Glade Spray
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 can</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱110.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                        </div>

                        <!-- ── Required Parts action bar (bottom-right, matches Service Type) ── -->
                        <div id="required-parts-buttons" class="section-action-bar">
                            <button type="button" onclick="selectAllRequiredParts()" class="action-btn action-btn--primary">
                                <i class="fas fa-check-square"></i> Select All
                            </button>
                            <button type="button" onclick="clearRequiredParts()" class="action-btn action-btn--outline-danger">
                                <i class="fas fa-times"></i> Clear All
                            </button>
                        </div>

                        <!-- Parts Summary -->
                        <div id="parts-summary" style="margin-top:8px; padding:8px 12px; background:#f0f4ff; border:1px solid #c8d8f8; border-radius:5px; display:flex; align-items:center; gap:18px; flex-wrap:wrap;">
                            <span style="font-size:13px; color:#495057;">
                                <i class="fas fa-list-check" style="color:#003d7a; margin-right:4px;"></i>
                                <strong>Selected Parts:</strong> <span id="selected-parts-count" style="color:#003d7a; font-weight:700;">0</span>
                            </span>
                            <span style="color:#dee2e6;">|</span>
                            <span style="font-size:13px; color:#495057;">
                                <i class="fas fa-box" style="color:#6c757d; margin-right:4px;"></i>
                                <strong>Merchandise:</strong> <span id="merchandise-count" style="color:#6c757d; font-weight:700;">0</span>
                            </span>
                            <span style="color:#dee2e6;">|</span>
                            <span style="font-size:13px; color:#495057;">
                                <i class="fas fa-pencil" style="color:#ffc107; margin-right:4px;"></i>
                                <strong>Manual:</strong> <span id="manual-count" style="color:#856404; font-weight:700;">0</span>
                            </span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Estimated Duration (minutes) <small class="text-muted">- For planning purposes only (pricing is per service)</small></label>
                        <input type="number" name="estimated_duration" class="form-input" 
                               value="<?php echo htmlspecialchars($edit_job['estimated_duration'] ?? '60'); ?>" min="15" max="480" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Service Description</label>
                    <textarea name="service_description" class="form-textarea" 
                              placeholder="Describe the service needed..." required><?php echo htmlspecialchars($edit_job['service_description'] ?? ''); ?></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Payment Method</label>
                    <select name="payment_method" class="form-select" id="payment_method" onchange="handlePaymentMethodChange()" required>
                        <option value="">Select payment method</option>
                        <?php if (!empty($payment_methods)): ?>
                            <?php foreach ($payment_methods as $method): ?>
                                <option value="<?php echo htmlspecialchars($method['method_name']); ?>"
                                        <?php echo ($edit_job['payment_method'] ?? '') == $method['method_name'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($method['method_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="Cash"        <?php echo ($edit_job['payment_method'] ?? '') == 'Cash'        ? 'selected' : ''; ?>>Cash</option>
                            <option value="Card"        <?php echo ($edit_job['payment_method'] ?? '') == 'Card'        ? 'selected' : ''; ?>>Card (Debit/Credit)</option>
                            <option value="E-Wallet"    <?php echo ($edit_job['payment_method'] ?? '') == 'E-Wallet'    ? 'selected' : ''; ?>>E-Wallet</option>
                            <option value="E-Fuel Card" <?php echo ($edit_job['payment_method'] ?? '') == 'E-Fuel Card' ? 'selected' : ''; ?>>E-Fuel Card</option>
                            <option value="Credit"      <?php echo ($edit_job['payment_method'] ?? '') == 'Credit'      ? 'selected' : ''; ?>>Credit (Utang)</option>
                        <?php endif; ?>
                    </select>
                </div>

                <!-- ══ PAYMENT PROCESSING PANEL ══════════════════════════════════════ -->
                <div id="payment_fields" style="display:none; margin-top:4px;">

                    <!-- Cost Breakdown -->
                    <div style="background:#f0f4ff; border:1px solid #c8d8f8; border-radius:7px; padding:14px 16px; margin-bottom:14px;">
                        <div style="font-size:13px; font-weight:700; color:#003d7a; margin-bottom:10px;">
                            <i class="fas fa-calculator"></i> Cost Breakdown
                        </div>
                        <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px;">
                            <div style="background:#fff; border-radius:5px; padding:10px; border:1px solid #dce8ff; text-align:center;">
                                <div style="font-size:10px; color:#6c757d; text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px;">Labor (Service Fee)</div>
                                <div style="font-size:16px; font-weight:800; color:#003d7a;">₱<span id="labor_cost_display">0.00</span></div>
                            </div>
                            <div style="background:#fff; border-radius:5px; padding:10px; border:1px solid #dce8ff; text-align:center;">
                                <div style="font-size:10px; color:#6c757d; text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px;">Parts (Merchandise)</div>
                                <div style="font-size:16px; font-weight:800; color:#495057;">₱<span id="parts_cost_display">0.00</span></div>
                            </div>
                            <div style="background:#003d7a; border-radius:5px; padding:10px; text-align:center;">
                                <div style="font-size:10px; color:#a8c4e8; text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px;">Grand Total</div>
                                <div style="font-size:16px; font-weight:800; color:#fff;">₱<span id="total_amount_display">0.00</span></div>
                            </div>
                        </div>
                        <!-- Hidden inputs submitted with form -->
                        <input type="hidden" name="labor_cost"  id="labor_cost_input"  value="0">
                        <input type="hidden" name="parts_cost"  id="parts_cost_input"  value="0">
                        <input type="hidden" name="total_amount" id="total_amount_input" value="0">
                    </div>

                    <!-- ── CASH ─────────────────────────────────────────────────────── -->
                    <div id="pm_cash" style="display:none;">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-money-bill-wave" style="color:#28a745;margin-right:5px;"></i>
                                Amount Tendered <span style="color:#dc3545;">*</span>
                            </label>
                            <div style="display:flex;align-items:center;border:1.5px solid #ced4da;border-radius:5px;overflow:hidden;max-width:220px;">
                                <span style="background:#e9ecef;padding:9px 12px;font-weight:700;color:#495057;border-right:1px solid #ced4da;">₱</span>
                                <input type="number" name="amount_paid" id="amount_paid"
                                       class="form-input" style="border:none;margin:0;border-radius:0;"
                                       step="0.01" min="0" placeholder="0.00"
                                       oninput="recalcPayment()">
                            </div>
                        </div>
                        <div id="sukli_group" style="display:none; margin-bottom:12px;">
                            <label class="form-label">Change (Sukli)</label>
                            <div style="padding:10px 14px; background:#d4edda; border:1.5px solid #c3e6cb; border-radius:5px; display:flex; align-items:center; gap:8px; max-width:220px;">
                                <i class="fas fa-coins" style="color:#155724;"></i>
                                <strong style="color:#155724; font-size:16px;">₱<span id="sukli_display">0.00</span></strong>
                            </div>
                            <input type="hidden" name="sukli" id="sukli_input" value="0">
                        </div>
                    </div>

                    <!-- ── CARD ─────────────────────────────────────────────────────── -->
                    <div id="pm_card" style="display:none;">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-credit-card" style="color:#003d7a;margin-right:5px;"></i>
                                Card Reference No. <span style="font-size:11px;color:#6c757d;">(optional)</span>
                            </label>
                            <input type="text" name="card_ref" id="card_ref"
                                   class="form-input" style="max-width:280px;"
                                   placeholder="e.g. 1234-5678-XXXX">
                            <small style="color:#6c757d;">Swipe / insert card on POS terminal. Exact service cost will be charged.</small>
                        </div>
                    </div>

                    <!-- ── E-WALLET ─────────────────────────────────────────────────── -->
                    <div id="pm_ewallet" style="display:none;">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-mobile-alt" style="color:#17a2b8;margin-right:5px;"></i>
                                E-Wallet Reference No. <span style="color:#dc3545;">*</span>
                            </label>
                            <input type="text" name="ewallet_ref" id="ewallet_ref"
                                   class="form-input" style="max-width:280px;"
                                   placeholder="e.g. GCash / Maya ref no.">
                            <small style="color:#6c757d;">Scan QR or input reference number after transfer confirmation.</small>
                        </div>
                    </div>

                    <!-- ── E-FUEL CARD ──────────────────────────────────────────────── -->
                    <div id="pm_efuel" style="display:none;">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-gas-pump" style="color:#dc3545;margin-right:5px;"></i>
                                E-Fuel Card ID <span style="color:#dc3545;">*</span>
                            </label>
                            <input type="text" name="efuel_card_id" id="efuel_card_id"
                                   class="form-input" style="max-width:280px;"
                                   placeholder="Enter card ID / number">
                            <small style="color:#6c757d;">Stored value will be deducted automatically.</small>
                        </div>
                    </div>

                    <!-- ── CREDIT ───────────────────────────────────────────────────── -->
                    <div id="pm_credit" style="display:none;">
                        <div style="background:#fff8e1; border:1.5px solid #ffc107; border-radius:6px; padding:12px 14px; margin-bottom:10px;">
                            <div style="font-size:12px; font-weight:700; color:#856404; margin-bottom:6px;">
                                <i class="fas fa-exclamation-triangle"></i> Credit Transaction
                            </div>
                            <div style="font-size:12px; color:#856404;">
                                Transaction will be saved as <strong>Pending Payment</strong> and auto-linked to the Receivables module.
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-user" style="color:#856404;margin-right:5px;"></i>
                                Credit Customer Name <span style="color:#dc3545;">*</span>
                            </label>
                            <input type="text" name="credit_customer_name" id="credit_customer_name"
                                   class="form-input" style="max-width:280px;"
                                   placeholder="Customer name for credit account">
                        </div>
                    </div>

                    <!-- ── Payment Status Badge ─────────────────────────────────────── -->
                    <div style="margin-top:8px;">
                        <label class="form-label">Payment Status</label>
                        <div id="payment_status_display"
                             style="display:inline-block; padding:7px 18px; border-radius:20px; font-weight:700; font-size:13px; background:#fff3cd; color:#856404; border:1.5px solid #ffc107;">
                            Pending
                        </div>
                    </div>

                </div><!-- /#payment_fields -->

                <div class="form-group">
                    <label class="form-label">Additional Notes</label>
                    <textarea name="additional_notes" class="form-textarea" 
                              placeholder="Special instructions or notes..."></textarea>
                </div>

                <div style="display: flex; gap: 15px; margin-top: 30px;">
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-save"></i> Create Job Order
                    </button>
                    <button type="reset" class="btn-secondary" onclick="resetForm()">
                        <i class="fas fa-redo"></i> Reset
                    </button>
                </div>
            </form>
        </div>

            </div>
        </div>
        </div><!-- /innerTab_encode_jo -->

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
            <span style="font-size:12px;color:#64748b;font-weight:600;">Filter:</span>
            <button onclick="joFilterTable('all')"    id="joFilter_all"       class="jo-filter-btn jo-filter-active">All</button>
            <button onclick="joFilterTable('pending')"  id="joFilter_pending"   class="jo-filter-btn">Pending</button>
            <button onclick="joFilterTable('approved')" id="joFilter_approved"  class="jo-filter-btn">Approved</button>
            <button onclick="joFilterTable('inprogress')" id="joFilter_inprogress" class="jo-filter-btn">In Progress</button>
            <button onclick="joFilterTable('completed')" id="joFilter_completed" class="jo-filter-btn">Completed</button>
            <button onclick="joFilterTable('rejected')" id="joFilter_rejected"  class="jo-filter-btn">Rejected</button>
        </div>
        <style>
        .jo-filter-btn {
            padding:5px 14px;border:1px solid #e2e8f0;border-radius:20px;
            background:#f8fafc;color:#64748b;font-size:11px;font-weight:600;
            cursor:pointer;transition:all .15s;
        }
        .jo-filter-btn:hover { background:#e2e8f0; }
        .jo-filter-btn.jo-filter-active {
            background:#003d7a;color:#fff;border-color:#003d7a;
        }
        </style>

        <!-- Unified Job Order Table -->
        <div class="txn-card">
            <div class="txn-card-header" style="background:#f0f7ff;">
                <i class="fas fa-clipboard-list" style="color:#003d7a;"></i>
                <h3 style="color:#003d7a;">All Job Orders</h3>
                <span style="margin-left:auto;font-size:11px;color:#64748b;">Status-based workflow — all orders in one view</span>
            </div>
            <div class="txn-card-body" style="padding:0;">
                <?php if (empty($job_orders)): ?>
                <div style="text-align:center;padding:40px;color:#94a3b8;">
                    <i class="fas fa-clipboard" style="font-size:28px;display:block;margin-bottom:8px;"></i>
                    No job orders found.
                </div>
                <?php else: ?>
                <div style="overflow-x:auto;">
                <table class="txn-table" id="joUnifiedTable" style="min-width:1000px;">
                    <thead>
                        <tr>
                            <th>JO ID</th>
                            <th>Customer</th>
                            <th>Vehicle / Service</th>
                            <th>Items / Parts</th>
                            <th>Mechanic</th>
                            <th>Workflow Status</th>
                            <th>Payment Status</th>
                            <th>Remarks</th>
                            <th>Date/Time</th>
                            <th>Actions</th>
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

                        // Payment badge
                        if ($pay_status === 'Paid') {
                            $pay_bg='#dcfce7'; $pay_color='#166534'; $pay_label='PAID';
                        } elseif ($pay_status === 'Partial') {
                            $pay_bg='#fef9c3'; $pay_color='#854d0e'; $pay_label='PARTIAL';
                        } elseif ($pay_status === 'Unpaid') {
                            $pay_bg='#fee2e2'; $pay_color='#991b1b'; $pay_label='UNPAID';
                        } else {
                            $pay_bg='#f1f5f9'; $pay_color='#64748b'; $pay_label='PENDING';
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
                        <td style="font-size:12px;">
                            <?php if (!empty($job['vehicle_plate'])): ?>
                            <strong><?= htmlspecialchars($job['vehicle_plate']) ?></strong>
                            <?php if (!empty($job['vehicle_type'])): ?>
                            <span style="color:#64748b;"> · <?= htmlspecialchars($job['vehicle_type']) ?></span>
                            <?php endif; ?>
                            <br>
                            <?php endif; ?>
                            <span style="color:#475569;max-width:140px;display:inline-block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                                  title="<?= htmlspecialchars($job['service_type'] ?? '') ?>">
                                <?= htmlspecialchars($job['service_type'] ?? '—') ?>
                            </span>
                        </td>
                        <td style="font-size:11px;color:#475569;max-width:140px;">
                            <?= htmlspecialchars($parts_display) ?>
                        </td>
                        <td style="font-size:12px;"><?= htmlspecialchars($job['mechanic_name'] ?? 'Unassigned') ?></td>
                        <td>
                            <span style="background:<?= $wf_bg ?>;color:<?= $wf_color ?>;border:1px solid <?= $wf_bg ?>;
                                         padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;white-space:nowrap;">
                                <?= $wf_label ?>
                            </span>
                        </td>
                        <td>
                            <span style="background:<?= $pay_bg ?>;color:<?= $pay_color ?>;border:1px solid <?= $pay_bg ?>;
                                         padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;white-space:nowrap;">
                                <?= $pay_label ?>
                            </span>
                        </td>
                        <td style="font-size:11px;color:#64748b;max-width:150px;">
                            <?= !empty($remarks) ? htmlspecialchars($remarks) : '<span style="color:#cbd5e1;">—</span>' ?>
                        </td>
                        <td style="font-size:11px;color:#64748b;white-space:nowrap;">
                            <?= date('M j, Y', strtotime($job['created_at'])) ?><br>
                            <?= date('h:i A', strtotime($job['created_at'])) ?>
                        </td>
                        <td>
                            <?php if ($wf_status === 'Rejected'): ?>
                                <!-- Rejected: re-encode -->
                                <a href="joborder.php" class="txn-btn secondary" style="padding:5px 11px;font-size:11px;white-space:nowrap;">
                                    <i class="fas fa-redo"></i> Re-encode
                                </a>
                            <?php elseif ($val_status === 'Pending Validation'): ?>
                                <!-- Pending: view only -->
                                <span style="font-size:11px;color:#94a3b8;font-style:italic;">Awaiting approval</span>
                            <?php elseif ($wf_status === 'Completed'): ?>
                                <!-- Completed: show payment update if not yet paid -->
                                <?php if ($pay_status !== 'Paid'): ?>
                                <form method="POST" action="staff_transactions_hub.php?section=merchandise" style="margin:0;">
                                    <input type="hidden" name="jo_action" value="set_paid">
                                    <input type="hidden" name="jo_id" value="<?= (int)$job['id'] ?>">
                                    <input type="hidden" name="jo_source" value="<?= htmlspecialchars($job['_source'] ?? 'job_orders') ?>">
                                    <button type="submit" class="txn-btn success" style="padding:5px 11px;font-size:11px;white-space:nowrap;">
                                        <i class="fas fa-money-bill-wave"></i> Mark Paid
                                    </button>
                                </form>
                                <?php else: ?>
                                <span style="font-size:11px;color:#16a34a;font-weight:700;"><i class="fas fa-check-circle"></i> Paid</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <!-- Approved / In Progress: workflow actions -->
                                <div style="display:flex;flex-direction:column;gap:4px;min-width:110px;">
                                    <?php if ($wf_status !== 'In Progress'): ?>
                                    <form method="POST" action="staff_transactions_hub.php?section=merchandise" style="margin:0;">
                                        <input type="hidden" name="jo_action" value="set_in_progress">
                                        <input type="hidden" name="jo_id" value="<?= (int)$job['id'] ?>">
                                        <input type="hidden" name="jo_source" value="<?= htmlspecialchars($job['_source'] ?? 'job_orders') ?>">
                                        <button type="submit" class="txn-btn primary" style="padding:5px 11px;font-size:11px;width:100%;white-space:nowrap;">
                                            <i class="fas fa-play"></i> In Progress
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <form method="POST" action="staff_transactions_hub.php?section=merchandise" style="margin:0;">
                                        <input type="hidden" name="jo_action" value="set_completed">
                                        <input type="hidden" name="jo_id" value="<?= (int)$job['id'] ?>">
                                        <input type="hidden" name="jo_source" value="<?= htmlspecialchars($job['_source'] ?? 'job_orders') ?>">
                                        <button type="submit" class="txn-btn success" style="padding:5px 11px;font-size:11px;width:100%;white-space:nowrap;">
                                            <i class="fas fa-check"></i> Complete
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <script>
        function joFilterTable(filter) {
            // Update active button
            document.querySelectorAll('.jo-filter-btn').forEach(function(btn) {
                btn.classList.remove('jo-filter-active');
            });
            var activeBtn = document.getElementById('joFilter_' + filter);
            if (activeBtn) activeBtn.classList.add('jo-filter-active');

            // Show/hide rows
            document.querySelectorAll('#joUnifiedTable tbody tr').forEach(function(row) {
                var rowFilter = row.getAttribute('data-jo-filter');
                row.style.display = (filter === 'all' || rowFilter === filter) ? '' : 'none';
            });
        }
        </script>

        </div><!-- /innerTab_tracker -->

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
                <div class="txn-section-icon hist"><i class="fas fa-history"></i></div>
                <div>
                    <h1>Shift History</h1>
                    <p>Your merchandise transaction history by shift</p>
                </div>
            </div>
            <a href="staff_transactions_hub.php?section=merchandise" class="txn-btn secondary" style="font-size:12px;padding:8px 14px;">
                <i class="fas fa-arrow-left"></i> Back to Transactions
            </a>
        </div>

        <!-- Filter bar -->
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px 18px;margin-bottom:16px;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <form method="GET" action="staff_transactions_hub.php">
                <input type="hidden" name="section" value="history">
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                    <div style="display:flex;flex-direction:column;gap:4px;min-width:140px;">
                        <label style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">Date</label>
                        <input type="date" name="date" value="<?= htmlspecialchars($filter_date) ?>"
                               style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;color:#1e293b;background:#fff;">
                    </div>
                    <div style="display:flex;flex-direction:column;gap:4px;min-width:150px;">
                        <label style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">Shift</label>
                        <select name="shift" style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;color:#1e293b;background:#fff;">
                            <option value="">All Shifts</option>
                            <?php foreach ($available_shifts as $sh): ?>
                            <option value="<?= htmlspecialchars($sh['shift_key']) ?>" <?= $filter_shift === $sh['shift_key'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sh['shift_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="txn-btn primary" style="padding:8px 16px;font-size:12px;">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <a href="staff_transactions_hub.php?section=history" class="txn-btn secondary" style="padding:8px 14px;font-size:12px;">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </div>
            </form>
        </div>

        <!-- Merchandise Transactions -->
        <div class="txn-card">
            <div class="txn-card-header">
                <i class="fas fa-shopping-cart" style="color:#28a745;"></i>
                <h3>Merchandise Transactions</h3>
                <span style="margin-left:auto;font-size:11px;color:#64748b;"><?= $merch_total_count ?> total record(s)</span>
            </div>
            <div class="txn-card-body" style="padding:0;">
                <?php if (empty($recent_merch)): ?>
                <div style="text-align:center;padding:40px;color:#94a3b8;">
                    <i class="fas fa-receipt" style="font-size:28px;display:block;margin-bottom:8px;"></i>
                    No merchandise transactions found.
                </div>
                <?php else: ?>
                <div style="overflow-x:auto;">
                <table class="txn-table" style="min-width:700px;">
                    <thead>
                        <tr>
                            <th>Txn ID</th>
                            <th>Customer</th>
                            <th>Amount</th>
                            <th>Payment</th>
                            <th>Shift</th>
                            <th>Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recent_merch as $txn): ?>
                    <tr>
                        <td><strong style="color:var(--petron-blue);"><?= htmlspecialchars($txn['transaction_id'] ?? ('#'.$txn['id'])) ?></strong></td>
                        <td><?= htmlspecialchars($txn['customer_name'] ?? '—') ?></td>
                        <td style="font-weight:700;color:var(--petron-blue);">₱<?= number_format((float)($txn['total_amount'] ?? 0), 2) ?></td>
                        <td style="font-size:11px;"><?= htmlspecialchars($txn['payment_method'] ?? '—') ?></td>
                        <td style="font-size:11px;color:#64748b;"><?= htmlspecialchars($txn['shift_name'] ?? $txn['shift_period'] ?? '—') ?></td>
                        <td style="font-size:11px;color:#64748b;white-space:nowrap;"><?= date('M j, Y h:i A', strtotime($txn['transaction_date'] ?? 'now')) ?></td>
                        <td><?= status_badge($txn['status'] ?? 'Pending') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <!-- Pagination -->
                <?php if ($merch_total_count > $hist_per_page): ?>
                <?php $total_pages = (int)ceil($merch_total_count / $hist_per_page); ?>
                <div style="display:flex;justify-content:center;gap:6px;padding:14px;">
                    <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                    <a href="<?= hist_page_url($p, $filter_shift, $filter_date) ?>"
                       style="padding:5px 11px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;
                              background:<?= $p === $hist_page ? 'var(--petron-blue)' : '#f1f5f9' ?>;
                              color:<?= $p === $hist_page ? '#fff' : '#475569' ?>;
                              border:1px solid <?= $p === $hist_page ? 'var(--petron-blue)' : '#e2e8f0' ?>;">
                        <?= $p ?>
                    </a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>
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
                <div style="overflow-x:auto;">
                <table class="txn-table" style="min-width:500px;">
                    <thead>
                        <tr>
                            <th>Shift</th>
                            <th>Clock In</th>
                            <th>Clock Out</th>
                            <th>Duration</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($shift_log as $ls): ?>
                    <tr>
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
            </div>
        </div>
        <?php endif; ?>

        <?php /* ══════════════════════════════════════════════════════
               SECTION: FUEL HISTORY
        ══════════════════════════════════════════════════════ */ ?>
        <?php elseif ($section === 'fuel_history'): ?>

        <div class="txn-section-header">
            <div class="txn-section-title">
                <div class="txn-section-icon fuel"><i class="fas fa-gas-pump"></i></div>
                <div>
                    <h1>Fuel Transaction History</h1>
                    <p>Your fuel transaction records</p>
                </div>
            </div>
            <a href="staff_transactions_hub.php?section=fuel" class="txn-btn secondary" style="font-size:12px;padding:8px 14px;">
                <i class="fas fa-arrow-left"></i> Back to Fuel
            </a>
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
                <div style="overflow-x:auto;">
                <table class="txn-table" style="min-width:700px;">
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
                    <tbody>
                    <?php foreach ($recent_fuel as $ft): ?>
                    <tr>
                        <td><strong style="color:var(--petron-blue);"><?= htmlspecialchars($ft['transaction_id'] ?? ('#'.$ft['id'])) ?></strong></td>
                        <td><?= htmlspecialchars($ft['fuel_type'] ?? '—') ?></td>
                        <td><?= number_format((float)($ft['liters'] ?? 0), 2) ?> L</td>
                        <td style="font-weight:700;color:var(--petron-blue);">₱<?= number_format((float)($ft['total_amount'] ?? $ft['amount'] ?? 0), 2) ?></td>
                        <td style="font-size:11px;"><?= htmlspecialchars($ft['payment_method'] ?? '—') ?></td>
                        <td style="font-size:11px;color:#64748b;white-space:nowrap;"><?= date('M j, Y h:i A', strtotime($ft['transaction_date'] ?? $ft['created_at'] ?? 'now')) ?></td>
                        <td><?= status_badge($ft['status'] ?? 'Pending') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php endif; /* end section switch */ ?>

</div><!-- /txn-content -->

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
