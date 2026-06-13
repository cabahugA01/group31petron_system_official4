<?php
/**
 * MANAGER SHIFT REPORTS – COMPLETE CONTENTS
 * Matches admin_shift_reports.php professional design with centered header + section tabs
 * Blue theme for Manager role
 */

$is_standalone = !isset($date_start) || !isset($date_end) || !isset($pdo) || !isset($station_id);

if ($is_standalone) {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    require_once __DIR__ . '/../../backend/lib.php';
    require_once __DIR__ . '/../db_connect.php';
    require_login();
    $current_user = current_user();
    $user_role    = role_key($current_user['role'] ?? 'manager');
    $station_id   = user_station_id();
    if (!$station_id && in_array($user_role, ['manager','admin','staff']))
        render_no_station_page('manager_dashboard.php');
    $date_start = $_GET['date_from'] ?? date('Y-m-01');
    $date_end   = $_GET['date_to']   ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_start)) $date_start = date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_end))   $date_end   = date('Y-m-d');
    $page_id = 'manager_reports';
    require_once __DIR__ . '/../../partials/header.php';
}

// Station name
$station_name = '';
try {
    $s = $pdo->prepare("SELECT name FROM stations WHERE id=? LIMIT 1");
    $s->execute([$station_id]);
    $station_name = $s->fetchColumn() ?: '';
} catch (Exception $e) {}

// Active section tab
$section = $_GET['section'] ?? 'fuel_sales';
$valid_sections = ['fuel_sales','merchandise','service_income','payments','job_orders','customers'];
if (!in_array($section, $valid_sections)) $section = 'fuel_sales';

// Active shift filter
$active_shift = (int)($_GET['shift'] ?? 0); // 0 = all, 1 = shift1, 2 = shift2

// Shift definitions — extend Shift 2 to midnight to catch all late transactions
$shifts = [
    1 => ['label'=>'Shift 1 (6AM–2PM)',  'start'=>'06:00:00','end'=>'14:00:00'],
    2 => ['label'=>'Shift 2 (2PM–12AM)', 'start'=>'14:00:00','end'=>'23:59:59'],
];
?>

<style>
/* Shift Reports – matches staff_reports.php design with Manager blue theme */
.sr-report-title {
    text-align: center;
    padding: 20px 0 14px;
    border-bottom: 2px solid #e2e8f0;
    margin-bottom: 0;
}
.sr-report-title h2 {
    font-size: 20px;
    font-weight: 800;
    color: #00264D;
    text-transform: uppercase;
    margin: 0 0 4px;
    letter-spacing: 0.5px;
}
.sr-report-title h3 {
    font-size: 16px;
    font-weight: 700;
    color: #00264D;
    text-transform: uppercase;
    margin: 0 0 6px;
    letter-spacing: 0.3px;
}
.sr-report-title p {
    font-size: 12px;
    color: #64748b;
    margin: 2px 0;
}
/* Section Tabs */
.sr-section-tabs {
    display: flex;
    border-bottom: 2px solid #e2e8f0;
    margin-bottom: 0;
    overflow-x: auto;
}
.sr-section-tab {
    padding: 12px 20px;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    color: #64748b;
    background: #f8f9fa;
    border: none;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    white-space: nowrap;
    transition: all 0.2s;
}
.sr-section-tab:hover { background: #fff; color: #00264D; }
.sr-section-tab.active {
    background: #fff;
    color: #00264D;
    border-bottom-color: #002F70;
    font-weight: 800;
}
/* Shift Filter Buttons */
.sr-shift-btns {
    display: flex;
    gap: 8px;
    padding: 14px 0 4px;
    margin-bottom: 16px;
}
.sr-shift-btn {
    padding: 8px 18px;
    font-size: 12px;
    font-weight: 600;
    border: 2px solid #00264D;
    background: white;
    color: #00264D;
    border-radius: 5px;
    cursor: pointer;
    transition: all 0.2s;
}
.sr-shift-btn:hover { background: #f0f4ff; }
.sr-shift-btn.active { background: #00264D; color: white; }
/* Section Content */
.sr-section-panel { display: none; }
.sr-section-panel.active { display: block; }
/* Shift Block */
.sr-shift-block { margin-bottom: 32px; }
.sr-shift-block.hidden { display: none; }
.sr-shift-heading {
    font-size: 13px;
    font-weight: 700;
    color: #00264D;
    text-transform: uppercase;
    padding: 10px 0 6px;
    margin-bottom: 8px;
    border-bottom: 1px solid #e2e8f0;
}
/* Table */
.sr-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}
.sr-table thead tr {
    border-top: 2px solid #00264D;
    border-bottom: 1px solid #e2e8f0;
    background: #002F70;
}
.sr-table thead th {
    padding: 10px 8px;
    text-align: left;
    font-weight: 700;
    color: #ffffff;
    font-size: 11px;
    text-transform: uppercase;
    white-space: nowrap;
    background: #002F70;
}
.sr-table tbody tr { border-bottom: 1px solid #f1f5f9; }
.sr-table tbody tr:hover { background: #f8fafc; }
.sr-table tbody td { padding: 9px 8px; color: #334155; font-size: 12px; }
.sr-table tfoot tr {
    border-top: 2px solid #00264D;
    background: #f0f4ff;
}
.sr-table tfoot td {
    padding: 10px 8px;
    font-weight: 700;
    color: #00264D;
    font-size: 12px;
}
.sr-empty {
    text-align: center;
    padding: 28px;
    color: #94a3b8;
    font-size: 13px;
}
.sr-status {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 700;
    color: white;
}

@media print {
    @page { size: legal portrait; margin: 0.3in 0.4in; }
    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    html, body { background: white !important; padding: 0 !important; margin: 0 !important; }
    .sidebar, aside, nav, .navbar, .header, .main-header, body > header,
    .main-footer, .fixed-footer, .footer-sidebar-area, .main,
    .sr-section-tabs, .rpt-filter-bar, .rpt-export-actions,
    .notification-bell, .theme-toggle-btn, .profile-access,
    .toggle-bar, .brand-mark, img.logo, .no-print {
        display: none !important;
        visibility: hidden !important;
    }
    .main, .content-wrapper, .wrapper {
        margin: 0 !important; padding: 0 !important;
        left: 0 !important; top: 0 !important;
        width: 100% !important; position: static !important;
    }
    .reports-wrapper { box-shadow: none !important; border: none !important; }
    .rpt-content { padding: 0 !important; }
    .sr-shift-block.hidden { display: block !important; }
    .sr-tbl { font-size: 10px !important; page-break-inside: auto !important; }
    .sr-tbl thead th { font-size: 9px !important; padding: 6px 5px !important; }
    .sr-tbl tbody td { font-size: 10px !important; padding: 5px !important; }
    .sr-tbl tfoot td { font-size: 10px !important; padding: 6px 5px !important; }
    .sr-shift-block { page-break-inside: avoid !important; margin-bottom: 16px !important; }
    .sr-section-tabs { display: none !important; }
}
</style>

<!-- Section Tabs -->
<div class="sr-section-tabs">
    <?php
    $tabs = [
        'fuel_sales'      => ['label'=>'Fuel Sales Report',         'ico'=>'fas fa-gas-pump'],
        'merchandise'     => ['label'=>'Merchandise Sales Report',   'ico'=>'fas fa-shopping-cart'],
        'service_income'  => ['label'=>'Service Income Report',      'ico'=>'fas fa-wrench'],
        'payments'        => ['label'=>'Payments Report',            'ico'=>'fas fa-money-bill-wave'],
        'job_orders'      => ['label'=>'Job Orders Report',          'ico'=>'fas fa-clipboard-list'],
        'customers'       => ['label'=>'Customers Report',           'ico'=>'fas fa-users'],
    ];
    foreach ($tabs as $key => $tab): ?>
        <button class="sr-section-tab <?= $section === $key ? 'active' : '' ?>"
                onclick="srSwitchSection('<?= $key ?>')">
            <i class="<?= $tab['ico'] ?>"></i> <?= $tab['label'] ?>
        </button>
    <?php endforeach; ?>
</div>

<?php
function srFetch($pdo, $station_id, $date_start, $date_end, $shift_start_t, $shift_end_t, $section) {
    $rows = [];
    try {
        switch ($section) {

            case 'fuel_sales':
                $is_s1 = ($shift_start_t === '06:00:00');
                if ($is_s1) {
                    $shift_cond = "(LOWER(COALESCE(ft.shift_period,'')) IN ('first','morning','1','shift1','shift 1') OR ft.shift_name LIKE '%First%' OR ft.shift_name LIKE '%Morning%' OR (COALESCE(ft.shift_period,'') = '' AND TIME(ft.transaction_date) >= '06:00:00' AND TIME(ft.transaction_date) < '14:00:00'))";
                } else {
                    $shift_cond = "(LOWER(COALESCE(ft.shift_period,'')) IN ('second','afternoon','evening','2','shift2','shift 2','night','midnight') OR ft.shift_name LIKE '%Second%' OR ft.shift_name LIKE '%Afternoon%' OR ft.shift_name LIKE '%Evening%' OR (COALESCE(ft.shift_period,'') = '' AND (TIME(ft.transaction_date) >= '14:00:00' OR TIME(ft.transaction_date) < '06:00:00')))";
                }

                $q = $pdo->prepare("
                    SELECT
                        COALESCE(ft.pump_id, '—') AS pump_name,
                        COALESCE(ft.fuel_type, '—') AS fuel_type,
                        COALESCE(ft.previous_reading, 0) AS beg_reading,
                        COALESCE(ft.present_reading, 0)  AS end_reading,
                        COALESCE(ft.calibration, 0)      AS calibration,
                        COALESCE(ft.liters_sold,
                            ABS(COALESCE(ft.present_reading,0) - COALESCE(ft.previous_reading,0))
                            + COALESCE(ft.calibration,0)
                        ) AS dispensed_liters,
                        COALESCE(ft.price_per_liter, 0) AS unit_price,
                        COALESCE(ft.total_amount, 0) AS amount,
                        TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS encoder,
                        LOWER(COALESCE(ft.shift_period,'')) AS sp,
                        TIME(ft.transaction_date) AS txn_time
                    FROM fuel_transactions ft
                    LEFT JOIN users u ON ft.staff_id = u.id
                    WHERE ft.station_id = ?
                      AND DATE(ft.transaction_date) BETWEEN ? AND ?
                      AND $shift_cond
                    ORDER BY ft.transaction_date, ft.pump_id
                ");
                $q->execute([$station_id, $date_start, $date_end]);
                $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
                break;

            case 'merchandise':
                $is_s1 = ($shift_start_t === '06:00:00');
                if ($is_s1) {
                    $shift_cond = "(LOWER(COALESCE(mt.shift_period,'')) IN ('first','morning','1','shift1','shift 1') OR mt.shift_name LIKE '%First%' OR mt.shift_name LIKE '%Morning%' OR (COALESCE(mt.shift_period,'') = '' AND TIME(mt.created_at) >= '06:00:00' AND TIME(mt.created_at) < '14:00:00'))";
                } else {
                    $shift_cond = "(LOWER(COALESCE(mt.shift_period,'')) IN ('second','afternoon','evening','2','shift2','shift 2','night','midnight') OR mt.shift_name LIKE '%Second%' OR mt.shift_name LIKE '%Afternoon%' OR mt.shift_name LIKE '%Evening%' OR (COALESCE(mt.shift_period,'') = '' AND (TIME(mt.created_at) >= '14:00:00' OR TIME(mt.created_at) < '06:00:00')))";
                }

                $q = $pdo->prepare("
                    SELECT
                        COALESCE(mti.category, '—') AS category,
                        COALESCE(mti.product_name, '—') AS product_name,
                        COALESCE(si.stock_level, 0) + SUM(mti.quantity) AS beg_stock,
                        0 AS stock_in,
                        SUM(mti.quantity)               AS stock_out,
                        COALESCE(si.stock_level, 0)     AS end_stock,
                        COALESCE(mti.unit_price, 0) AS unit_price,
                        SUM(COALESCE(mti.subtotal, mti.quantity * mti.unit_price, 0)) AS amount,
                        TRIM(GROUP_CONCAT(DISTINCT COALESCE(u.first_name,'') ORDER BY u.first_name SEPARATOR ', ')) AS encoder
                    FROM merchandise_transaction_items mti
                    JOIN merchandise_transactions mt ON mti.transaction_id = mt.id
                    LEFT JOIN users u ON mt.staff_id = u.id
                    LEFT JOIN station_inventory si
                        ON si.product_id = mti.product_id AND si.station_id = mt.station_id
                    WHERE mt.station_id = ?
                      AND DATE(mt.created_at) BETWEEN ? AND ?
                      AND $shift_cond
                      AND mti.item_type = 'merchandise'
                    GROUP BY mti.product_id, mti.category, mti.product_name, mti.unit_price, si.stock_level
                    ORDER BY mti.category, mti.product_name
                ");
                $q->execute([$station_id, $date_start, $date_end]);
                $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];

                // Fallback without item_type filter
                if (empty($rows)) {
                    $q2 = $pdo->prepare("
                        SELECT
                            COALESCE(mti.category, '—') AS category,
                            COALESCE(mti.product_name, '—') AS product_name,
                            COALESCE(si.stock_level, 0) + SUM(mti.quantity) AS beg_stock,
                            0 AS stock_in,
                            SUM(mti.quantity)               AS stock_out,
                            COALESCE(si.stock_level, 0)     AS end_stock,
                            COALESCE(mti.unit_price, 0) AS unit_price,
                            SUM(COALESCE(mti.subtotal, mti.quantity * mti.unit_price, 0)) AS amount,
                            TRIM(GROUP_CONCAT(DISTINCT COALESCE(u.first_name,'') ORDER BY u.first_name SEPARATOR ', ')) AS encoder
                        FROM merchandise_transaction_items mti
                        JOIN merchandise_transactions mt ON mti.transaction_id = mt.id
                        LEFT JOIN users u ON mt.staff_id = u.id
                        LEFT JOIN station_inventory si
                            ON si.product_id = mti.product_id AND si.station_id = mt.station_id
                        WHERE mt.station_id = ?
                          AND DATE(mt.created_at) BETWEEN ? AND ?
                          AND $shift_cond
                        GROUP BY mti.product_id, mti.category, mti.product_name, mti.unit_price, si.stock_level
                        ORDER BY mti.category, mti.product_name
                    ");
                    $q2->execute([$station_id, $date_start, $date_end]);
                    $rows = $q2->fetchAll(PDO::FETCH_ASSOC) ?: [];
                }
                break;

            case 'service_income':
                $is_s1 = ($shift_start_t === '06:00:00');
                if ($is_s1) {
                    $shift_cond = "(TIME(jo.created_at) >= '06:00:00' AND TIME(jo.created_at) < '14:00:00')";
                } else {
                    $shift_cond = "NOT (TIME(jo.created_at) >= '06:00:00' AND TIME(jo.created_at) < '14:00:00')";
                }

                $q = $pdo->prepare("
                    SELECT
                        COALESCE(jo.service_type, 'General Service') AS service_type,
                        COALESCE(jo.actual_labor_cost, jo.estimated_labor_cost, 0) AS labor_fee,
                        COALESCE(jo.actual_parts_cost, jo.estimated_parts_cost, 0) AS parts_used,
                        COALESCE(jo.total_cost, jo.estimated_cost, 0) AS total_amount,
                        TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS encoder
                    FROM job_orders jo
                    LEFT JOIN users u ON jo.created_by = u.id
                    WHERE jo.station_id = ?
                      AND DATE(jo.created_at) BETWEEN ? AND ?
                      AND $shift_cond
                      AND jo.status = 'Completed'
                    ORDER BY jo.service_type
                ");
                $q->execute([$station_id, $date_start, $date_end]);
                $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
                break;

            case 'payments':
                $is_s1 = ($shift_start_t === '06:00:00');
                if ($is_s1) {
                    $shift_cond = "(LOWER(COALESCE(ft.shift_period,'')) IN ('first','morning','1','shift1','shift 1') OR ft.shift_name LIKE '%First%' OR ft.shift_name LIKE '%Morning%' OR (COALESCE(ft.shift_period,'') = '' AND TIME(ft.transaction_date) >= '06:00:00' AND TIME(ft.transaction_date) < '14:00:00'))";
                    $m_shift_cond = "(LOWER(COALESCE(mt.shift_period,'')) IN ('first','morning','1','shift1','shift 1') OR mt.shift_name LIKE '%First%' OR mt.shift_name LIKE '%Morning%' OR (COALESCE(mt.shift_period,'') = '' AND TIME(mt.created_at) >= '06:00:00' AND TIME(mt.created_at) < '14:00:00'))";
                } else {
                    $shift_cond = "(LOWER(COALESCE(ft.shift_period,'')) IN ('second','afternoon','evening','2','shift2','shift 2','night','midnight') OR ft.shift_name LIKE '%Second%' OR ft.shift_name LIKE '%Afternoon%' OR ft.shift_name LIKE '%Evening%' OR (COALESCE(ft.shift_period,'') = '' AND (TIME(ft.transaction_date) >= '14:00:00' OR TIME(ft.transaction_date) < '06:00:00')))";
                    $m_shift_cond = "(LOWER(COALESCE(mt.shift_period,'')) IN ('second','afternoon','evening','2','shift2','shift 2','night','midnight') OR mt.shift_name LIKE '%Second%' OR mt.shift_name LIKE '%Afternoon%' OR mt.shift_name LIKE '%Evening%' OR (COALESCE(mt.shift_period,'') = '' AND (TIME(mt.created_at) >= '14:00:00' OR TIME(mt.created_at) < '06:00:00')))";
                }

                $q = $pdo->prepare("
                    SELECT
                        CASE
                            WHEN LOWER(COALESCE(payment_method,'')) LIKE '%fleet%' THEN 'Fleet'
                            WHEN LOWER(COALESCE(payment_method,'')) LIKE '%fuel card%'
                              OR LOWER(COALESCE(payment_method,'')) LIKE '%efuel%' THEN 'E-Fuel'
                            WHEN LOWER(COALESCE(payment_method,'')) LIKE '%card%' THEN 'Card'
                            WHEN LOWER(COALESCE(payment_method,'')) LIKE '%wallet%'
                              OR LOWER(COALESCE(payment_method,'')) LIKE '%gcash%'
                              OR LOWER(COALESCE(payment_method,'')) LIKE '%maya%' THEN 'E-Wallet'
                            WHEN LOWER(COALESCE(payment_method,'')) LIKE '%cash%'
                              OR COALESCE(payment_method,'') = '' THEN 'Cash'
                            ELSE COALESCE(NULLIF(payment_method,''), 'Cash')
                        END AS mode_of_payment,
                        COUNT(*) AS txn_count,
                        SUM(COALESCE(total_amount, 0)) AS amount
                    FROM fuel_transactions ft
                    WHERE ft.station_id = ?
                      AND DATE(ft.transaction_date) BETWEEN ? AND ?
                      AND $shift_cond
                    GROUP BY mode_of_payment
                    ORDER BY amount DESC
                ");
                $q->execute([$station_id, $date_start, $date_end]);
                $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];

                // Include merchandise payments
                try {
                    $q2 = $pdo->prepare("
                        SELECT
                            CASE
                                WHEN LOWER(COALESCE(payment_method,'')) LIKE '%fleet%' THEN 'Fleet'
                                WHEN LOWER(COALESCE(payment_method,'')) LIKE '%card%' THEN 'Card'
                                WHEN LOWER(COALESCE(payment_method,'')) LIKE '%wallet%'
                                  OR LOWER(COALESCE(payment_method,'')) LIKE '%gcash%'
                                  OR LOWER(COALESCE(payment_method,'')) LIKE '%maya%' THEN 'E-Wallet'
                                ELSE 'Cash'
                            END AS mode_of_payment,
                            COUNT(*) AS txn_count,
                            SUM(COALESCE(total_amount, 0)) AS amount
                        FROM merchandise_transactions mt
                        WHERE mt.station_id = ?
                          AND DATE(mt.created_at) BETWEEN ? AND ?
                          AND $m_shift_cond
                        GROUP BY mode_of_payment
                    ");
                    $q2->execute([$station_id, $date_start, $date_end]);
                    $merch_pay = $q2->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    // Merge
                    $merged = [];
                    foreach (array_merge($rows, $merch_pay) as $r) {
                        $m = $r['mode_of_payment'];
                        if (!isset($merged[$m])) $merged[$m] = ['mode_of_payment'=>$m,'txn_count'=>0,'amount'=>0];
                        $merged[$m]['txn_count'] += $r['txn_count'];
                        $merged[$m]['amount']    += $r['amount'];
                    }
                    usort($merged, fn($a,$b) => $b['amount'] <=> $a['amount']);
                    $rows = array_values($merged);
                } catch (Exception $e2) { }
                break;

            case 'job_orders':
                $is_s1 = ($shift_start_t === '06:00:00');
                if ($is_s1) {
                    $shift_cond = "(TIME(jo.created_at) >= '06:00:00' AND TIME(jo.created_at) < '14:00:00')";
                } else {
                    $shift_cond = "NOT (TIME(jo.created_at) >= '06:00:00' AND TIME(jo.created_at) < '14:00:00')";
                }

                $q = $pdo->prepare("
                    SELECT
                        COALESCE(jo.status, 'Pending') AS status,
                        COALESCE(jo.service_type, '—')  AS service_type,
                        COALESCE(jo.actual_parts_cost, jo.estimated_parts_cost, 0) AS parts_used,
                        COALESCE(jo.actual_labor_cost, jo.estimated_labor_cost, 0) AS labor_fee,
                        COALESCE(jo.total_cost, jo.estimated_cost, 0) AS total_amount,
                        TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS encoder
                    FROM job_orders jo
                    LEFT JOIN users u ON jo.created_by = u.id
                    WHERE jo.station_id = ?
                      AND DATE(jo.created_at) BETWEEN ? AND ?
                      AND $shift_cond
                    ORDER BY FIELD(jo.status,'Pending','In Progress','Completed','Cancelled'), jo.created_at DESC
                ");
                $q->execute([$station_id, $date_start, $date_end]);
                $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
                break;

            case 'customers':
                // Check columns
                try {
                    $ck = $pdo->query("SHOW COLUMNS FROM merchandise_transactions LIKE 'customer_id'");
                    $has_cid = $ck && $ck->rowCount() > 0;
                } catch (Exception $e) { $has_cid = false; }

                try {
                    $lk = $pdo->query("SHOW COLUMNS FROM customers LIKE 'loyalty_points'");
                    $has_lp = $lk && $lk->rowCount() > 0;
                } catch (Exception $e) { $has_lp = false; }

                try {
                    $pk = $pdo->query("SHOW COLUMNS FROM customers LIKE 'points'");
                    $has_points = $pk && $pk->rowCount() > 0;
                } catch (Exception $e) { $has_points = false; }

                if ($has_lp) {
                    $lp_col = 'COALESCE(c.loyalty_points, 0)';
                } elseif ($has_points) {
                    $lp_col = 'COALESCE(c.points, 0)';
                } else {
                    $lp_col = '0';
                }

                $is_s1 = ($shift_start_t === '06:00:00');
                if ($is_s1) {
                    $shift_cond = "(LOWER(COALESCE(mt.shift_period,'')) IN ('first','morning','1','shift1','shift 1') OR mt.shift_name LIKE '%First%' OR mt.shift_name LIKE '%Morning%' OR (COALESCE(mt.shift_period,'') = '' AND TIME(mt.created_at) >= '06:00:00' AND TIME(mt.created_at) < '14:00:00'))";
                } else {
                    $shift_cond = "(LOWER(COALESCE(mt.shift_period,'')) IN ('second','afternoon','evening','2','shift2','shift 2','night','midnight') OR mt.shift_name LIKE '%Second%' OR mt.shift_name LIKE '%Afternoon%' OR mt.shift_name LIKE '%Evening%' OR (COALESCE(mt.shift_period,'') = '' AND (TIME(mt.created_at) >= '14:00:00' OR TIME(mt.created_at) < '06:00:00')))";
                }

                if ($has_cid) {
                    $q = $pdo->prepare("
                        SELECT
                            COALESCE(c.name, '—') AS customer_name,
                            CONCAT('#', c.id) AS customer_ref,
                            COUNT(DISTINCT mt.id) AS txn_count,
                            COALESCE(c.balance, 0) AS balance,
                            $lp_col AS loyalty_points
                        FROM customers c
                        JOIN merchandise_transactions mt
                            ON mt.customer_id = c.id
                           AND mt.station_id = ?
                           AND DATE(mt.created_at) BETWEEN ? AND ?
                           AND $shift_cond
                        WHERE c.station_id = ?
                        GROUP BY c.id, c.name, c.balance
                        ORDER BY txn_count DESC
                    ");
                    $q->execute([$station_id, $date_start, $date_end, $station_id]);
                } else {
                    $q = $pdo->prepare("
                        SELECT
                            COALESCE(c.name, '—') AS customer_name,
                            CONCAT('#', c.id) AS customer_ref,
                            COUNT(DISTINCT mt.id) AS txn_count,
                            COALESCE(c.balance, 0) AS balance,
                            $lp_col AS loyalty_points
                        FROM customers c
                        JOIN merchandise_transactions mt
                            ON mt.customer_name = c.name
                           AND mt.station_id = ?
                           AND DATE(mt.created_at) BETWEEN ? AND ?
                           AND $shift_cond
                        WHERE c.station_id = ?
                        GROUP BY c.id, c.name, c.balance
                        ORDER BY txn_count DESC
                    ");
                    $q->execute([$station_id, $date_start, $date_end, $station_id]);
                }
                $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
                break;
        }
    } catch (Exception $ex) {
        $rows = [];
    }
    return $rows;
}
?>

<!-- Section Panels -->
<?php foreach ($tabs as $sec_key => $tab): ?>
<div id="sr-panel-<?= $sec_key ?>" class="sr-section-panel <?= $section === $sec_key ? 'active' : '' ?>">

    <!-- Centered Report Header (matches staff reports style) -->
    <?php
    $report_titles = [
        'fuel_sales'     => ['title'=>'FUEL SALES REPORT',          'sub'=>'SHIFT SUMMARY'],
        'merchandise'    => ['title'=>'MERCHANDISE SALES REPORT',    'sub'=>'SHIFT SUMMARY'],
        'service_income' => ['title'=>'SERVICE INCOME REPORT',       'sub'=>'SHIFT SUMMARY'],
        'payments'       => ['title'=>'PAYMENTS REPORT',             'sub'=>'SHIFT SUMMARY'],
        'job_orders'     => ['title'=>'JOB ORDERS REPORT',           'sub'=>'SHIFT SUMMARY'],
        'customers'      => ['title'=>'CUSTOMERS REPORT',            'sub'=>'SHIFT SUMMARY'],
    ];
    $rt = $report_titles[$sec_key] ?? ['title'=>strtoupper($tab['label']),'sub'=>'SHIFT SUMMARY'];
    ?>
    <div style="text-align:center;padding:22px 0 14px;border-bottom:2px solid #e2e8f0;margin-bottom:16px;">
        <div style="font-size:20px;font-weight:800;color:#00264D;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">
            <?= $rt['title'] ?>
        </div>
        <div style="font-size:16px;font-weight:700;color:#00264D;text-transform:uppercase;letter-spacing:0.3px;margin-bottom:8px;">
            <?= $rt['sub'] ?>
        </div>
        <div style="font-size:12px;color:#64748b;margin-bottom:2px;">
            <?= htmlspecialchars($station_name) ?>
        </div>
        <div style="font-size:12px;color:#334155;">
            <strong>Date:</strong>
            <?= date('F j, Y', strtotime($date_start)) ?>
            <?= $date_start !== $date_end ? ' – ' . date('F j, Y', strtotime($date_end)) : '' ?>
        </div>
    </div>

    <!-- Section Heading -->
    <div style="padding:14px 0 10px;border-bottom:1px solid #e2e8f0;margin-bottom:18px;">
        <div style="font-size:14px;font-weight:700;color:#00264D;text-transform:uppercase;letter-spacing:0.3px;">
            <i class="<?= $tab['ico'] ?>"></i> <?= $tab['label'] ?>
        </div>
    </div>

    <?php foreach ($shifts as $snum => $sdef):
        $rows = srFetch($pdo, $station_id, $date_start, $date_end, $sdef['start'], $sdef['end'], $sec_key);
    ?>
    <div class="sr-shift-block <?= ($active_shift!==0 && $active_shift!==$snum)?'hidden':'' ?>" data-shift="<?=$snum?>" data-section="<?=$sec_key?>">
        <div class="sr-shift-heading"><?= $sdef['label'] ?></div>

        <?php if ($sec_key === 'fuel_sales'): ?>
        <table class="sr-table">
            <thead><tr>
                <th>Pump / Fuel Type</th><th>Beginning Reading</th><th>Ending Reading</th>
                <th>Calibration</th><th>Dispensed Liters</th><th>Unit Price</th><th>Amount</th><th>Encoder</th>
            </tr></thead>
            <tbody>
            <?php if (empty($rows)): ?><tr><td colspan="8" class="sr-empty">No fuel sales for this shift</td></tr>
            <?php else: $tl=0;$ta=0; foreach($rows as $r): $tl+=$r['dispensed_liters'];$ta+=$r['amount']; ?>
                <tr>
                    <td><?=htmlspecialchars($r['pump_name'])?> / <?=htmlspecialchars($r['fuel_type'])?></td>
                    <td><?=number_format($r['beg_reading'],2)?></td>
                    <td><?=number_format($r['end_reading'],2)?></td>
                    <td><?=number_format($r['calibration'],2)?></td>
                    <td><?=number_format($r['dispensed_liters'],2)?> L</td>
                    <td>₱<?=number_format($r['unit_price'],2)?></td>
                    <td>₱<?=number_format($r['amount'],2)?></td>
                    <td><?=htmlspecialchars($r['encoder'])?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($rows)): ?>
            <tfoot><tr>
                <td colspan="4" style="text-align:right;"><strong>SHIFT TOTALS:</strong></td>
                <td><strong><?=number_format($tl,2)?> L</strong></td>
                <td></td>
                <td><strong>₱<?=number_format($ta,2)?></strong></td>
                <td></td>
            </tr></tfoot>
            <?php endif; ?>
        </table>

        <?php elseif ($sec_key === 'merchandise'): ?>
        <table class="sr-table">
            <thead><tr>
                <th>Category</th><th>Product</th><th>Beg Stock</th><th>Stock In</th>
                <th>Stock Out</th><th>End Stock</th><th>Unit Price</th><th>Amount</th><th>Encoder</th>
            </tr></thead>
            <tbody>
            <?php if (empty($rows)): ?><tr><td colspan="9" class="sr-empty">No merchandise sales for this shift</td></tr>
            <?php else: $ta=0; foreach($rows as $r): $ta+=$r['amount']; ?>
                <tr>
                    <td><?=htmlspecialchars($r['category'])?></td>
                    <td><?=htmlspecialchars($r['product_name'])?></td>
                    <td><?=number_format($r['beg_stock'])?></td>
                    <td><?=number_format($r['stock_in'])?></td>
                    <td><?=number_format($r['stock_out'])?></td>
                    <td><?=number_format($r['end_stock'])?></td>
                    <td>₱<?=number_format($r['unit_price'],2)?></td>
                    <td>₱<?=number_format($r['amount'],2)?></td>
                    <td><?=htmlspecialchars($r['encoder'])?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($rows)): ?>
            <tfoot><tr>
                <td colspan="7" style="text-align:right;"><strong>SHIFT TOTALS:</strong></td>
                <td><strong>₱<?=number_format($ta,2)?></strong></td>
                <td></td>
            </tr></tfoot>
            <?php endif; ?>
        </table>

        <?php elseif ($sec_key === 'service_income'): ?>
        <table class="sr-table">
            <thead><tr>
                <th>Service Type</th><th>Labor Fee</th><th>Parts Used</th><th>Total Amount</th><th>Encoder</th>
            </tr></thead>
            <tbody>
            <?php if (empty($rows)): ?><tr><td colspan="5" class="sr-empty">No service income for this shift</td></tr>
            <?php else: $ta=0; foreach($rows as $r): $ta+=$r['total_amount']; ?>
                <tr>
                    <td><?=htmlspecialchars($r['service_type'])?></td>
                    <td>₱<?=number_format($r['labor_fee'],2)?></td>
                    <td>₱<?=number_format($r['parts_used'],2)?></td>
                    <td>₱<?=number_format($r['total_amount'],2)?></td>
                    <td><?=htmlspecialchars($r['encoder'])?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($rows)): ?>
            <tfoot><tr>
                <td colspan="3" style="text-align:right;"><strong>SHIFT TOTALS:</strong></td>
                <td><strong>₱<?=number_format($ta,2)?></strong></td>
                <td></td>
            </tr></tfoot>
            <?php endif; ?>
        </table>

        <?php elseif ($sec_key === 'payments'): ?>
        <table class="sr-table">
            <thead><tr>
                <th>Mode of Payment</th><th>Transaction Count</th><th>Amount</th>
            </tr></thead>
            <tbody>
            <?php if (empty($rows)): ?><tr><td colspan="3" class="sr-empty">No payment records for this shift</td></tr>
            <?php else: $ta=0; foreach($rows as $r): $ta+=$r['amount']; ?>
                <tr>
                    <td><?=htmlspecialchars($r['mode_of_payment'])?></td>
                    <td><?=number_format($r['txn_count'])?></td>
                    <td>₱<?=number_format($r['amount'],2)?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($rows)): ?>
            <tfoot><tr>
                <td style="text-align:right;"><strong>SHIFT TOTALS:</strong></td>
                <td></td>
                <td><strong>₱<?=number_format($ta,2)?></strong></td>
            </tr></tfoot>
            <?php endif; ?>
        </table>

        <?php elseif ($sec_key === 'job_orders'): ?>
        <table class="sr-table">
            <thead><tr>
                <th>Status</th><th>Service Type</th><th>Parts Used</th><th>Labor Fee</th><th>Total Amount</th><th>Encoder</th>
            </tr></thead>
            <tbody>
            <?php if (empty($rows)): ?><tr><td colspan="6" class="sr-empty">No job orders for this shift</td></tr>
            <?php else: $ta=0; foreach($rows as $r): $ta+=$r['total_amount']; ?>
                <tr>
                    <td><?=htmlspecialchars($r['status'])?></td>
                    <td><?=htmlspecialchars($r['service_type'])?></td>
                    <td>₱<?=number_format($r['parts_used'],2)?></td>
                    <td>₱<?=number_format($r['labor_fee'],2)?></td>
                    <td>₱<?=number_format($r['total_amount'],2)?></td>
                    <td><?=htmlspecialchars($r['encoder'])?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($rows)): ?>
            <tfoot><tr>
                <td colspan="4" style="text-align:right;"><strong>SHIFT TOTALS:</strong></td>
                <td><strong>₱<?=number_format($ta,2)?></strong></td>
                <td></td>
            </tr></tfoot>
            <?php endif; ?>
        </table>

        <?php elseif ($sec_key === 'customers'): ?>
        <table class="sr-table">
            <thead><tr>
                <th>Customer Name</th><th>Customer Ref</th><th>Transaction Count</th><th>Balance</th><th>Loyalty Points</th>
            </tr></thead>
            <tbody>
            <?php if (empty($rows)): ?><tr><td colspan="5" class="sr-empty">No customer transactions for this shift</td></tr>
            <?php else: foreach($rows as $r): ?>
                <tr>
                    <td><?=htmlspecialchars($r['customer_name'])?></td>
                    <td><?=htmlspecialchars($r['customer_ref'])?></td>
                    <td><?=number_format($r['txn_count'])?></td>
                    <td>₱<?=number_format($r['balance'],2)?></td>
                    <td><?=number_format($r['loyalty_points'])?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <?php endif; ?>
    </div>
    <?php endforeach; ?>

</div>
<?php endforeach; ?>

<script>
function srSwitchSection(sectionKey) {
    // Hide all panels
    document.querySelectorAll('.sr-section-panel').forEach(p => p.classList.remove('active'));
    // Show selected panel
    const panel = document.getElementById('sr-panel-' + sectionKey);
    if (panel) panel.classList.add('active');
    
    // Update tab buttons
    document.querySelectorAll('.sr-section-tab').forEach(btn => btn.classList.remove('active'));
    event.target.closest('.sr-section-tab').classList.add('active');
    
    // Update URL without reload
    const url = new URL(window.location);
    url.searchParams.set('section', sectionKey);
    window.history.pushState({}, '', url);
}
</script>

<?php if ($is_standalone) require_once __DIR__ . '/../../partials/footer.php'; ?>
