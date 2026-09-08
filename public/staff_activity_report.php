<?php
/**
 * MY ACTIVITY REPORT
 * Replaces: staff_activity_report.php
 * Comprehensive activity tracking & audit report.
 * Filters: Business Date (From/To), Shift, Module, Activity Type, Status, Search
 * Table Columns: Date & Time | Module | Activity | Reference No. | Status
 * Export: Print, PDF, Excel, CSV
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? 'staff');
$user_id    = (int)($me['id'] ?? 0);
$station_id = user_station_id();

if (!in_array($role, ['staff','cashier','pump_attendant','manager','admin','superadmin','developer'])) {
    header('Location: dashboard.php'); exit;
}
if (!in_array($role, ['superadmin','developer']) && !is_module_enabled('reports')) {
    render_module_disabled_page('Reports');
}
if (!$station_id) die('Error: You are not assigned to a station.');

// ── Station Info ──────────────────────────────────────────────────────────────
$station_name     = 'Station';
$station_location = '';
try {
    $s = $pdo->prepare("SELECT name, location FROM stations WHERE id=? LIMIT 1");
    $s->execute([$station_id]);
    if ($st = $s->fetch(PDO::FETCH_ASSOC)) {
        $station_name     = $st['name'];
        $station_location = $st['location'] ?? '';
    }
} catch (Exception $e) {}

// ── Filters ───────────────────────────────────────────────────────────────────
$today          = date('Y-m-d');
$seven_days_ago = date('Y-m-d', strtotime('-7 days'));

$date_start     = trim($_GET['date_start'] ?? $seven_days_ago);
$date_end       = trim($_GET['date_end']   ?? $today);
$filter_shift    = trim($_GET['shift']      ?? '');
$filter_module   = trim($_GET['module']     ?? '');
$filter_activity = trim($_GET['activity']   ?? '');
$filter_status   = trim($_GET['status']     ?? '');
$filter_search   = trim($_GET['search']     ?? '');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_start)) $date_start = $seven_days_ago;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_end))   $date_end   = $today;

// ── Helper to check table existence ──────────────────────────────────────────
function check_table_exists($pdo, $table) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        return $stmt && $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

// ── Gather Activity Log Records (Strictly Scoped to Logged-in Staff) ──────────
$raw_activities = [];

// 1. Merchandise Sales & Transactions
if (check_table_exists($pdo, 'merchandise_transactions')) {
    try {
        $sql = "
            SELECT 
                mt.id,
                mt.created_at AS datetime,
                'Sales' AS module,
                CASE 
                    WHEN mt.void_reason IS NOT NULL AND TRIM(mt.void_reason) != '' THEN 'Void Request'
                    WHEN mt.adjustment_reason IS NOT NULL AND TRIM(mt.adjustment_reason) != '' THEN 'Adjustment Request'
                    WHEN LOWER(COALESCE(mt.transaction_type,'')) LIKE '%return%' THEN 'Processed Return'
                    ELSE 'Merchandise Sale'
                END AS activity,
                COALESCE(NULLIF(mt.transaction_id,''), CONCAT('MTX-', mt.id)) AS ref_no,
                CASE 
                    WHEN LOWER(COALESCE(mt.validation_status,'')) IN ('voided','rejected','cancelled','canceled') THEN 'Cancelled'
                    WHEN LOWER(COALESCE(mt.validation_status,'')) IN ('verified','approved','completed','submitted','paid') THEN 'Success'
                    ELSE 'Pending'
                END AS status,
                COALESCE(mt.shift_period, '') AS shift_period,
                CONCAT('Customer: ', COALESCE(mt.customer_name,'Walk-in'), ' | Total: ₱', FORMAT(COALESCE(mt.total_amount,0),2), ' | Payment: ', COALESCE(mt.payment_method,'Cash')) AS details
            FROM merchandise_transactions mt
            WHERE mt.station_id = :sid AND mt.staff_id = :uid AND DATE(mt.created_at) BETWEEN :dstart AND :dend
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['sid' => $station_id, 'uid' => $user_id, 'dstart' => $date_start, 'dend' => $date_end]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $raw_activities = array_merge($raw_activities, $rows);
    } catch (Exception $e) {}
}

// 2. Fuel Transactions & Meter Readings
if (check_table_exists($pdo, 'fuel_transactions')) {
    try {
        $sql = "
            SELECT 
                ft.id,
                COALESCE(ft.transaction_date, ft.created_at) AS datetime,
                'Fuel Management' AS module,
                'Fuel Meter Reading' AS activity,
                COALESCE(NULLIF(ft.transaction_id,''), CONCAT('FTX-', ft.id)) AS ref_no,
                CASE 
                    WHEN LOWER(COALESCE(ft.status,'')) IN ('voided','rejected','cancelled','canceled') THEN 'Cancelled'
                    WHEN LOWER(COALESCE(ft.status,'')) IN ('verified','approved','completed','submitted') THEN 'Success'
                    ELSE 'Pending'
                END AS status,
                COALESCE(ft.shift_period, '') AS shift_period,
                CONCAT('Fuel: ', COALESCE(ft.fuel_type,'Fuel'), ' | Volume: ', FORMAT(COALESCE(ft.liters_sold,0),2), ' L | Amount: ₱', FORMAT(COALESCE(ft.total_amount,0),2)) AS details
            FROM fuel_transactions ft
            WHERE ft.station_id = :sid AND ft.staff_id = :uid AND DATE(COALESCE(ft.transaction_date, ft.created_at)) BETWEEN :dstart AND :dend
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['sid' => $station_id, 'uid' => $user_id, 'dstart' => $date_start, 'dend' => $date_end]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $raw_activities = array_merge($raw_activities, $rows);
    } catch (Exception $e) {}
}

// 3. Job Orders (Created / Updated / Submitted)
if (check_table_exists($pdo, 'job_orders')) {
    try {
        $sql = "
            SELECT 
                jo.id,
                jo.created_at AS datetime,
                'Job Orders' AS module,
                CASE 
                    WHEN jo.updated_at > jo.created_at THEN 'Updated Job Order'
                    ELSE 'Created Job Order'
                END AS activity,
                COALESCE(NULLIF(jo.job_order_id,''), COALESCE(NULLIF(jo.job_order_number,''), CONCAT('JO-', jo.id))) AS ref_no,
                CASE 
                    WHEN LOWER(COALESCE(jo.status,'')) IN ('cancelled','canceled','rejected') THEN 'Cancelled'
                    WHEN LOWER(COALESCE(jo.status,'')) IN ('completed','released','approved','verified') THEN 'Success'
                    ELSE 'Pending'
                END AS status,
                '' AS shift_period,
                CONCAT('Service: ', COALESCE(jo.service_type,'General Service'), ' | Vehicle: ', COALESCE(jo.vehicle_plate,'N/A'), ' | Total: ₱', FORMAT(COALESCE(jo.total_cost, jo.actual_labor_cost + jo.actual_parts_cost, 0),2)) AS details
            FROM job_orders jo
            WHERE jo.station_id = :sid AND (jo.user_id = :uid OR jo.created_by = :uid) AND DATE(jo.created_at) BETWEEN :dstart AND :dend
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['sid' => $station_id, 'uid' => $user_id, 'dstart' => $date_start, 'dend' => $date_end]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $raw_activities = array_merge($raw_activities, $rows);
    } catch (Exception $e) {}
}

// 4. Stock Requests (Submitted by Staff)
if (check_table_exists($pdo, 'stock_requests')) {
    try {
        $sql = "
            SELECT 
                sr.id,
                sr.created_at AS datetime,
                'Inventory' AS module,
                'Stock Request' AS activity,
                COALESCE(NULLIF(sr.request_no,''), CONCAT('SR-', sr.id)) AS ref_no,
                CASE 
                    WHEN LOWER(COALESCE(sr.status,'')) IN ('rejected','cancelled','canceled') THEN 'Cancelled'
                    WHEN LOWER(COALESCE(sr.status,'')) IN ('approved','completed','fulfilled') THEN 'Success'
                    ELSE 'Pending'
                END AS status,
                '' AS shift_period,
                CONCAT('Item: ', COALESCE(sr.item_name,'N/A'), ' (SKU: ', COALESCE(sr.item_sku,'N/A'), ') | Requested Qty: ', COALESCE(sr.requested_quantity,0), IF(sr.remarks IS NOT NULL AND sr.remarks != '', CONCAT(' | Remarks: ', sr.remarks), '')) AS details
            FROM stock_requests sr
            WHERE sr.station_id = :sid AND sr.staff_id = :uid AND DATE(sr.created_at) BETWEEN :dstart AND :dend
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['sid' => $station_id, 'uid' => $user_id, 'dstart' => $date_start, 'dend' => $date_end]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $raw_activities = array_merge($raw_activities, $rows);
    } catch (Exception $e) {}
}

// 5. Master Data Requests (Submitted by Staff)
if (check_table_exists($pdo, 'master_data_requests')) {
    try {
        $sql = "
            SELECT 
                mdr.id,
                mdr.created_at AS datetime,
                'Master Data' AS module,
                'Master Data Request' AS activity,
                COALESCE(NULLIF(mdr.request_no,''), CONCAT('MDR-', mdr.id)) AS ref_no,
                CASE 
                    WHEN LOWER(COALESCE(mdr.status,'')) IN ('rejected','cancelled','canceled') THEN 'Cancelled'
                    WHEN LOWER(COALESCE(mdr.status,'')) IN ('approved','completed') THEN 'Success'
                    ELSE 'Pending'
                END AS status,
                '' AS shift_period,
                CONCAT('Category: ', COALESCE(mdr.category,'General'), ' | Module: ', COALESCE(mdr.source_module,'Staff'), ' | Status: ', COALESCE(mdr.status,'Pending')) AS details
            FROM master_data_requests mdr
            WHERE mdr.station_id = :sid AND mdr.requested_by = :uid AND DATE(mdr.created_at) BETWEEN :dstart AND :dend
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['sid' => $station_id, 'uid' => $user_id, 'dstart' => $date_start, 'dend' => $date_end]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $raw_activities = array_merge($raw_activities, $rows);
    } catch (Exception $e) {}
}

// 6. Transaction Adjustments (Submitted by Staff)
if (check_table_exists($pdo, 'transaction_adjustments')) {
    try {
        $sql = "
            SELECT 
                ta.id,
                COALESCE(ta.adjustment_date, NOW()) AS datetime,
                'Sales' AS module,
                'Adjustment Request' AS activity,
                CONCAT('ADJ-', ta.id) AS ref_no,
                'Pending' AS status,
                '' AS shift_period,
                CONCAT('Txn Ref: ', COALESCE(ta.transaction_id,'N/A'), ' | Diff: ₱', FORMAT(COALESCE(ta.amount_difference,0),2), ' | Reason: ', COALESCE(ta.adjustment_reason,'Adjustment requested')) AS details
            FROM transaction_adjustments ta
            WHERE ta.station_id = :sid AND ta.adjusted_by = :uid AND DATE(COALESCE(ta.adjustment_date, NOW())) BETWEEN :dstart AND :dend
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['sid' => $station_id, 'uid' => $user_id, 'dstart' => $date_start, 'dend' => $date_end]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $raw_activities = array_merge($raw_activities, $rows);
    } catch (Exception $e) {}
}

// 7. Fuel Sales Closing / Shift Turnover Reports
if (check_table_exists($pdo, 'shift_reports')) {
    try {
        $sql = "
            SELECT 
                sr.id,
                sr.created_at AS datetime,
                'Fuel Management' AS module,
                'Fuel Sales Closing' AS activity,
                CONCAT('STR-', sr.id) AS ref_no,
                CASE 
                    WHEN LOWER(COALESCE(sr.status,'')) IN ('rejected','cancelled','canceled') THEN 'Cancelled'
                    WHEN LOWER(COALESCE(sr.status,'')) IN ('finalized','approved','completed') THEN 'Success'
                    ELSE 'Pending'
                END AS status,
                sr.shift AS shift_period,
                CONCAT('Shift: ', sr.shift, ' | Date: ', sr.report_date) AS details
            FROM shift_reports sr
            WHERE sr.station_id = :sid AND (sr.user_id = :uid OR sr.created_by = :uid OR sr.staff_id = :uid) AND DATE(sr.created_at) BETWEEN :dstart AND :dend
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['sid' => $station_id, 'uid' => $user_id, 'dstart' => $date_start, 'dend' => $date_end]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $raw_activities = array_merge($raw_activities, $rows);
    } catch (Exception $e) {}
}

// 8. User Form Drafts (Draft Saved / Updated)
if (check_table_exists($pdo, 'user_form_drafts')) {
    try {
        $sql = "
            SELECT 
                ufd.id,
                ufd.updated_at AS datetime,
                'Drafts' AS module,
                'Draft Saved' AS activity,
                CONCAT('DFT-', ufd.id) AS ref_no,
                'Pending' AS status,
                '' AS shift_period,
                CONCAT('Form Module: ', REPLACE(ufd.module_key,'_',' '), ' | Draft Key: ', ufd.draft_key) AS details
            FROM user_form_drafts ufd
            WHERE ufd.user_id = :uid AND DATE(ufd.updated_at) BETWEEN :dstart AND :dend
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['uid' => $user_id, 'dstart' => $date_start, 'dend' => $date_end]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $raw_activities = array_merge($raw_activities, $rows);
    } catch (Exception $e) {}
}

// 9. Activity Logs (Login, Logout, Clock In, Clock Out, Other Actions)
if (check_table_exists($pdo, 'activity_logs')) {
    try {
        $sql = "
            SELECT 
                act.id,
                act.created_at AS datetime,
                CASE 
                    WHEN LOWER(COALESCE(act.action,'')) LIKE '%login%' OR LOWER(COALESCE(act.action,'')) LIKE '%logout%' OR LOWER(COALESCE(act.action,'')) LIKE '%clock%' THEN 'Auth / Session'
                    WHEN LOWER(COALESCE(act.action,'')) LIKE '%fuel%' THEN 'Fuel Management'
                    WHEN LOWER(COALESCE(act.action,'')) LIKE '%stock%' OR LOWER(COALESCE(act.action,'')) LIKE '%invent%' THEN 'Inventory'
                    WHEN LOWER(COALESCE(act.action,'')) LIKE '%job%' THEN 'Job Orders'
                    WHEN LOWER(COALESCE(act.action,'')) LIKE '%draft%' THEN 'Drafts'
                    ELSE 'Sales'
                END AS module,
                COALESCE(act.action, 'Action') AS activity,
                CONCAT('ACT-', act.id) AS ref_no,
                'Success' AS status,
                '' AS shift_period,
                COALESCE(act.details,'') AS details
            FROM activity_logs act
            WHERE act.user_id = :uid AND DATE(act.created_at) BETWEEN :dstart AND :dend
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['uid' => $user_id, 'dstart' => $date_start, 'dend' => $date_end]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $raw_activities = array_merge($raw_activities, $rows);
    } catch (Exception $e) {}
}

// 10. Audit Logs (Explicit System & Security Audits)
if (check_table_exists($pdo, 'audit_logs')) {
    try {
        $sql = "
            SELECT 
                al.id,
                al.created_at AS datetime,
                CASE 
                    WHEN LOWER(COALESCE(al.action_type,'')) LIKE '%login%' OR LOWER(COALESCE(al.action_type,'')) LIKE '%logout%' THEN 'Auth / Session'
                    WHEN LOWER(COALESCE(al.log_type,'')) LIKE '%fuel%' THEN 'Fuel Management'
                    WHEN LOWER(COALESCE(al.log_type,'')) LIKE '%job%' OR LOWER(COALESCE(al.log_type,'')) LIKE '%service%' THEN 'Job Orders'
                    WHEN LOWER(COALESCE(al.log_type,'')) LIKE '%merch%' OR LOWER(COALESCE(al.log_type,'')) LIKE '%sale%' THEN 'Sales'
                    WHEN LOWER(COALESCE(al.log_type,'')) LIKE '%invent%' OR LOWER(COALESCE(al.log_type,'')) LIKE '%stock%' THEN 'Inventory'
                    WHEN LOWER(COALESCE(al.log_type,'')) LIKE '%cust%' THEN 'Customers'
                    WHEN LOWER(COALESCE(al.log_type,'')) LIKE '%shift%' THEN 'Shift Turnover'
                    WHEN LOWER(COALESCE(al.log_type,'')) LIKE '%draft%' THEN 'Drafts'
                    ELSE 'Sales'
                END AS module,
                COALESCE(NULLIF(al.action_type,''), 'System Action') AS activity,
                CONCAT('LOG-', al.id) AS ref_no,
                CASE 
                    WHEN LOWER(COALESCE(al.status,'')) IN ('failed','error','cancelled','canceled') THEN 'Cancelled'
                    WHEN LOWER(COALESCE(al.status,'')) IN ('success','logged','ok') THEN 'Success'
                    ELSE 'Pending'
                END AS status,
                '' AS shift_period,
                COALESCE(al.action_details,'') AS details
            FROM audit_logs al
            WHERE al.user_id = :uid AND DATE(al.created_at) BETWEEN :dstart AND :dend
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['uid' => $user_id, 'dstart' => $date_start, 'dend' => $date_end]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $raw_activities = array_merge($raw_activities, $rows);
    } catch (Exception $e) {}
}

// ── Filter and Sort Activity Logs ──────────────────────────────────────────────
$filtered_activities = [];

foreach ($raw_activities as $act) {
    // 1. Shift filter
    if ($filter_shift !== '') {
        $sp = strtolower($act['shift_period'] ?? '');
        if (strpos($sp, strtolower($filter_shift)) === false) {
            continue;
        }
    }

    // 2. Module filter
    if ($filter_module !== '' && strtolower($act['module']) !== strtolower($filter_module)) {
        continue;
    }

    // 3. Activity Type filter
    if ($filter_activity !== '' && strtolower($act['activity']) !== strtolower($filter_activity)) {
        continue;
    }

    // 4. Status filter
    if ($filter_status !== '' && strtolower($act['status']) !== strtolower($filter_status)) {
        continue;
    }

    // 5. Search keyword filter
    if ($filter_search !== '') {
        $kw = strtolower($filter_search);
        $searchable = strtolower($act['module'] . ' ' . $act['activity'] . ' ' . $act['ref_no'] . ' ' . $act['status'] . ' ' . ($act['details'] ?? ''));
        if (strpos($searchable, $kw) === false) {
            continue;
        }
    }

    $filtered_activities[] = $act;
}

// Sort by datetime DESC
usort($filtered_activities, function($a, $b) {
    return strtotime($b['datetime']) <=> strtotime($a['datetime']);
});

// Remove duplicate entries (matching module, ref_no, and datetime)
$unique_activities = [];
$seen = [];
foreach ($filtered_activities as $item) {
    $key = $item['module'] . '|' . $item['activity'] . '|' . $item['ref_no'] . '|' . substr($item['datetime'], 0, 16);
    if (!isset($seen[$key])) {
        $seen[$key] = true;
        $unique_activities[] = $item;
    }
}

// ── Export Slugs & Handlers ─────────────────────────────────────────────────────
$export_slug = date('Ymd', strtotime($date_start)) . '_to_' . date('Ymd', strtotime($date_end));

// ── EXCEL EXPORT HANDLER ───────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $filename = "My_Activity_Report_{$export_slug}.xls";
    header('Content-Type: application/vnd.ms-excel');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header('Cache-Control: max-age=0');

    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8" />';
    echo '<style>';
    echo 'table { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; font-size: 11px; }';
    echo 'th, td { border: 1px solid #000000; padding: 6px; text-align: left; }';
    echo 'th { background-color: #002F6C; color: #ffffff; font-weight: bold; text-align: center; }';
    echo '.text-center { text-align: center; }';
    echo '</style></head><body>';

    echo "<h2>MY ACTIVITY REPORT</h2>";
    echo "<p><strong>Station:</strong> " . htmlspecialchars($station_name . ($station_location ? " — {$station_location}" : "")) . "</p>";
    echo "<p><strong>Period:</strong> " . date('F d, Y', strtotime($date_start)) . " – " . date('F d, Y', strtotime($date_end)) . "</p>";
    echo "<br/>";

    echo '<table>';
    echo '<thead><tr>';
    echo '<th>Date & Time</th>';
    echo '<th>Module</th>';
    echo '<th>Activity</th>';
    echo '<th>Reference No.</th>';
    echo '<th>Status</th>';
    echo '</tr></thead><tbody>';

    if (count($unique_activities) > 0) {
        foreach ($unique_activities as $row) {
            echo '<tr>';
            echo '<td>' . date('Y-m-d h:i A', strtotime($row['datetime'])) . '</td>';
            echo '<td>' . htmlspecialchars($row['module']) . '</td>';
            echo '<td>' . htmlspecialchars($row['activity']) . '</td>';
            echo '<td>' . htmlspecialchars($row['ref_no']) . '</td>';
            echo '<td class="text-center">' . htmlspecialchars($row['status']) . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="5" class="text-center">No activity records found for the selected filters.</td></tr>';
    }

    echo '</tbody></table></body></html>';
    exit;
}

// ── CSV EXPORT HANDLER ────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = "My_Activity_Report_{$export_slug}.csv";
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header('Cache-Control: max-age=0');

    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM

    fputcsv($out, ['MY ACTIVITY REPORT']);
    fputcsv($out, [$station_name . ($station_location ? " — {$station_location}" : "")]);
    fputcsv($out, ['Period:', date('F d, Y', strtotime($date_start)) . ' - ' . date('F d, Y', strtotime($date_end))]);
    fputcsv($out, []);

    fputcsv($out, ['Date & Time', 'Module', 'Activity', 'Reference No.', 'Status']);

    foreach ($unique_activities as $row) {
        fputcsv($out, [
            date('Y-m-d h:i A', strtotime($row['datetime'])),
            $row['module'],
            $row['activity'],
            $row['ref_no'],
            $row['status']
        ]);
    }

    fclose($out);
    exit;
}

// ── Page Title ─────────────────────────────────────────────────────────────────
$page_title = 'My Activity Report - ' . $station_name;

require_once __DIR__ . '/../partials/header.php';
require_once __DIR__ . '/../partials/flash_toast.php';
?>

<style>
html, body {
    max-width: 100vw !important;
    overflow-x: hidden !important;
}
.pagination-wrapper, .client-side-pagination, .petron-pagination-bar,
.petron-rows-select-wrap, .rows-per-page { display: none !important; }

/* Main Wrapper - Zero Horizontal Scrolling */
.stock-page {
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
    padding: 16px !important;
    overflow-x: hidden !important;
}

/* Card Container */
.act-card-container {
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
    background: #ffffff !important;
    border: 1.5px solid #cbd5e1 !important;
    border-radius: 12px !important;
    padding: 20px !important;
    box-shadow: 0 2px 6px rgba(0,0,0,0.04) !important;
    overflow-x: hidden !important;
}

/* Top Controls Bar */
.act-controls-bar {
    background: #ffffff !important;
    border: 1.5px solid #cbd5e1 !important;
    border-radius: 12px !important;
    padding: 14px 18px !important;
    margin-bottom: 16px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 12px !important;
    flex-wrap: wrap !important;
    box-shadow: 0 2px 5px rgba(0,0,0,0.03) !important;
    box-sizing: border-box !important;
    width: 100% !important;
    max-width: 100% !important;
}
.act-filters-group {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    flex-wrap: wrap !important;
    flex: 1 1 auto !important;
}
.act-filter-item {
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
}
.act-filter-label {
    font-weight: 800 !important;
    color: #002F6C !important;
    font-size: 13px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.3px !important;
    white-space: nowrap !important;
}
.act-filter-input, .act-filter-select {
    height: 38px !important;
    padding: 6px 10px !important;
    border: 1.5px solid #cbd5e1 !important;
    border-radius: 7px !important;
    font-size: 13.5px !important;
    font-weight: 600 !important;
    color: #1e293b !important;
    background: #ffffff !important;
    outline: none !important;
    box-sizing: border-box !important;
}
.act-filter-input:focus, .act-filter-select:focus {
    border-color: #002F6C !important;
    box-shadow: 0 0 0 3px rgba(0,47,108,0.12) !important;
}
.act-btn-apply {
    height: 38px !important;
    padding: 0 18px !important;
    background: #002F6C !important;
    color: #ffffff !important;
    font-weight: 800 !important;
    border: none !important;
    border-radius: 7px !important;
    font-size: 13.5px !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    transition: background 0.15s !important;
}
.act-btn-apply:hover {
    background: #001f4d !important;
}

/* Export Buttons Group */
.rpt-export-group {
    display: flex !important;
    align-items: center !important;
    gap: 7px !important;
    margin-left: auto !important;
    white-space: nowrap !important;
}
.rpt-export-btn {
    padding: 7px 14px !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    border-radius: 5px !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    background: #ffffff !important;
    border: 1px solid #cbd5e1 !important;
    transition: all 0.18s !important;
    text-decoration: none !important;
    box-sizing: border-box !important;
    white-space: nowrap !important;
}
.rpt-btn-print  { color: #475569 !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.rpt-btn-print:hover  { background: #f1f5f9 !important; }
.rpt-btn-pdf   { color: #dc2626 !important; border-color: #dc2626 !important; background: #ffffff !important; }
.rpt-btn-pdf:hover   { background: #fef2f2 !important; }
.rpt-btn-excel { color: #16a34a !important; border-color: #16a34a !important; background: #ffffff !important; }
.rpt-btn-excel:hover { background: #f0fdf4 !important; }
.rpt-btn-csv   { color: #16a34a !important; border-color: #16a34a !important; background: #ffffff !important; }
.rpt-btn-csv:hover   { background: #f0fdf4 !important; }

/* Table Wrapper & Proportional Layout */
.act-table-wrap {
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
    overflow: hidden !important;
    margin-top: 14px !important;
    border-radius: 8px !important;
    border: 1.5px solid #cbd5e1 !important;
}
table.act-table {
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
    table-layout: fixed !important;
    border-collapse: collapse !important;
    margin: 0 !important;
}
table.act-table th,
table.act-table td {
    white-space: normal !important;
    word-break: break-word !important;
    overflow-wrap: break-word !important;
    vertical-align: middle !important;
}
table.act-table th {
    background: #002F6C !important;
    color: #ffffff !important;
    font-size: 15px !important;
    font-weight: 800 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.4px !important;
    padding: 12px 14px !important;
    border-bottom: 2px solid #001f4d !important;
}
table.act-table td {
    padding: 12px 14px !important;
    border-bottom: 1px solid #e2e8f0 !important;
    color: #0f172a !important;
    font-size: 14.5px !important;
    background: #ffffff;
}
table.act-table tr:hover td {
    background: #f8fafc !important;
}
table.act-table tr:last-child td {
    border-bottom: none !important;
}

/* Senior-Friendly Readable Typography */
.act-date-val {
    font-size: 15px !important;
    font-weight: 800 !important;
    color: #0f172a !important;
    line-height: 1.3 !important;
}
.act-time-val {
    font-size: 13.5px !important;
    font-weight: 700 !important;
    color: #475569 !important;
    margin-top: 2px !important;
}
.badge-module {
    display: inline-block !important;
    padding: 5px 11px !important;
    border-radius: 6px !important;
    font-size: 13.5px !important;
    font-weight: 800 !important;
    background: #eff6ff !important;
    color: #002F6C !important;
    border: 1.5px solid #bfdbfe !important;
    letter-spacing: 0.2px !important;
    line-height: 1.25 !important;
}
.act-title {
    font-size: 15.5px !important;
    font-weight: 800 !important;
    color: #001f4d !important;
    line-height: 1.35 !important;
}
.act-details {
    font-size: 13.5px !important;
    font-weight: 600 !important;
    color: #334155 !important;
    line-height: 1.4 !important;
    margin-top: 4px !important;
}
.act-ref-no {
    font-family: 'Consolas', 'Courier New', monospace !important;
    font-size: 15px !important;
    font-weight: 800 !important;
    color: #002F6C !important;
    letter-spacing: 0.3px !important;
}

/* Status Badges */
.badge-status-success {
    display: inline-block !important;
    padding: 5px 14px !important;
    border-radius: 16px !important;
    font-size: 13.5px !important;
    font-weight: 800 !important;
    background: #dcfce7 !important;
    color: #15803d !important;
    border: 1.5px solid #86efac !important;
}
.badge-status-pending {
    display: inline-block !important;
    padding: 5px 14px !important;
    border-radius: 16px !important;
    font-size: 13.5px !important;
    font-weight: 800 !important;
    background: #fef9c3 !important;
    color: #854d0e !important;
    border: 1.5px solid #fde047 !important;
}
.badge-status-cancelled {
    display: inline-block !important;
    padding: 5px 14px !important;
    border-radius: 16px !important;
    font-size: 13.5px !important;
    font-weight: 800 !important;
    background: #fee2e2 !important;
    color: #b91c1c !important;
    border: 1.5px solid #fca5a5 !important;
}

@media print {
    .str-signature-wrap, .sfss-print-only .str-signature-wrap { display: flex !important; justify-content: flex-end !important; page-break-inside: avoid !important; margin-top: 20px !important; padding: 0 !important; }
    .sfss-print-only .section { display: block !important; }
    @page { size: A4 portrait; margin: 10mm 12mm; }
    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; box-shadow: none !important; }
    html, body { margin: 0 !important; padding: 0 !important; background: #fff !important; overflow: visible !important; height: auto !important; font-size: 11px !important; }
    body > *:not(.sfss-print-only) { display: none !important; }
    .stock-page .controls, .act-controls-bar, nav, header, footer, aside, .sidebar, .main-sidebar, .main-header, .navbar, .topbar,
    #toggleScrollBtn, .toggle-scroll-btn, .toast, .toast-container { display: none !important; }
    .sfss-print-only { display: block !important; position: static !important; width: 100% !important; margin: 0 !important; padding: 0 !important; background: #fff !important; font-size: 11px !important; color: #1e293b !important; }
    .sfss-print-only .act-card-container { border: none !important; padding: 0 !important; box-shadow: none !important; }
    .sfss-print-only .act-table-wrap { border: 1px solid #cbd5e1 !important; }
    .sfss-print-only .act-table th { font-size: 11px !important; padding: 7px 10px !important; background: #002F6C !important; color: #fff !important; }
    .sfss-print-only .act-table td { font-size: 11px !important; padding: 6px 10px !important; }
    .sfss-print-only .act-date-val { font-size: 11px !important; }
    .sfss-print-only .act-time-val { font-size: 10px !important; }
    .sfss-print-only .act-title { font-size: 11px !important; }
    .sfss-print-only .act-details { font-size: 10px !important; }
    .sfss-print-only .act-ref-no { font-size: 11px !important; }
    .sfss-print-only .badge-status-success, .sfss-print-only .badge-status-pending, .sfss-print-only .badge-status-cancelled { font-size: 10.5px !important; padding: 3px 8px !important; }
    .sfss-print-only .badge-module { font-size: 10px !important; padding: 2px 6px !important; }
    .sfss-print-only .str-signature-wrap { display: flex !important; justify-content: flex-end !important; page-break-inside: avoid !important; margin-top: 14px !important; padding: 0 !important; border: none !important; background: transparent !important; box-shadow: none !important; }
    .sfss-print-only .str-sig-line { border-top: 1.5px solid #002F6C !important; width: 100% !important; margin-bottom: 4px !important; }
    .sfss-print-only, .sfss-print-only * { min-height: 0 !important; height: auto !important; }
}
</style>

<div class="stock-page">

    <!-- TOP CONTROLS & FILTERS -->
    <div class="act-controls-bar">
        
        <div class="act-filters-group">
            <!-- Business Date Range -->
            <div class="act-filter-item">
                <label class="act-filter-label">From</label>
                <input type="date" id="date_start" value="<?= htmlspecialchars($date_start) ?>" max="<?= $today ?>" class="act-filter-input">
            </div>

            <div class="act-filter-item">
                <label class="act-filter-label">To</label>
                <input type="date" id="date_end" value="<?= htmlspecialchars($date_end) ?>" max="<?= $today ?>" class="act-filter-input">
            </div>

            <!-- Shift -->
            <div class="act-filter-item">
                <label class="act-filter-label">Shift</label>
                <select id="filter_shift" class="act-filter-select">
                    <option value="">All Shifts</option>
                    <option value="first"  <?= strtolower($filter_shift)==='first'  ? 'selected':'' ?>>Shift 1</option>
                    <option value="second" <?= strtolower($filter_shift)==='second' ? 'selected':'' ?>>Shift 2</option>
                    <option value="third"  <?= strtolower($filter_shift)==='third'  ? 'selected':'' ?>>Shift 3</option>
                </select>
            </div>

            <!-- Module -->
            <div class="act-filter-item">
                <label class="act-filter-label">Module</label>
                <select id="filter_module" class="act-filter-select">
                    <option value="">All Modules</option>
                    <option value="Sales"           <?= strtolower($filter_module)==='sales'           ? 'selected':'' ?>>Sales</option>
                    <option value="Fuel Management" <?= strtolower($filter_module)==='fuel management' ? 'selected':'' ?>>Fuel Management</option>
                    <option value="Job Orders"      <?= strtolower($filter_module)==='job orders'      ? 'selected':'' ?>>Job Orders</option>
                    <option value="Inventory"       <?= strtolower($filter_module)==='inventory'       ? 'selected':'' ?>>Inventory</option>
                    <option value="Master Data"     <?= strtolower($filter_module)==='master data'     ? 'selected':'' ?>>Master Data</option>
                    <option value="Auth / Session"  <?= strtolower($filter_module)==='auth / session'  ? 'selected':'' ?>>Auth / Session</option>
                    <option value="Drafts"          <?= strtolower($filter_module)==='drafts'          ? 'selected':'' ?>>Drafts</option>
                    <option value="Customers"       <?= strtolower($filter_module)==='customers'       ? 'selected':'' ?>>Customers</option>
                </select>
            </div>

            <!-- Activity Type -->
            <div class="act-filter-item">
                <label class="act-filter-label">Activity</label>
                <select id="filter_activity" class="act-filter-select">
                    <option value="">All Activities</option>
                    <option value="Login"                          <?= strtolower($filter_activity)==='login'                          ? 'selected':'' ?>>Login</option>
                    <option value="Logout"                         <?= strtolower($filter_activity)==='logout'                         ? 'selected':'' ?>>Logout</option>
                    <option value="Merchandise Sale"               <?= strtolower($filter_activity)==='merchandise sale'               ? 'selected':'' ?>>Merchandise Sale</option>
                    <option value="Created Job Order"              <?= strtolower($filter_activity)==='created job order'              ? 'selected':'' ?>>Created Job Order</option>
                    <option value="Updated Job Order"              <?= strtolower($filter_activity)==='updated job order'              ? 'selected':'' ?>>Updated Job Order</option>
                    <option value="Fuel Meter Reading"             <?= strtolower($filter_activity)==='fuel meter reading'             ? 'selected':'' ?>>Fuel Meter Reading</option>
                    <option value="Fuel Sales Closing"             <?= strtolower($filter_activity)==='fuel sales closing'             ? 'selected':'' ?>>Fuel Sales Closing</option>
                    <option value="Stock Request"                  <?= strtolower($filter_activity)==='stock request'                  ? 'selected':'' ?>>Stock Request</option>
                    <option value="Master Data Request"            <?= strtolower($filter_activity)==='master data request'            ? 'selected':'' ?>>Master Data Request</option>
                    <option value="Void Request"                   <?= strtolower($filter_activity)==='void request'                   ? 'selected':'' ?>>Void Request</option>
                    <option value="Adjustment Request"             <?= strtolower($filter_activity)==='adjustment request'             ? 'selected':'' ?>>Adjustment Request</option>
                    <option value="Draft Saved"                    <?= strtolower($filter_activity)==='draft saved'                    ? 'selected':'' ?>>Draft Saved</option>
                    <option value="Clock In"                       <?= strtolower($filter_activity)==='clock in'                       ? 'selected':'' ?>>Clock In</option>
                    <option value="Clock Out"                      <?= strtolower($filter_activity)==='clock out'                      ? 'selected':'' ?>>Clock Out</option>
                </select>
            </div>

            <!-- Status -->
            <div class="act-filter-item">
                <label class="act-filter-label">Status</label>
                <select id="filter_status" class="act-filter-select">
                    <option value="">All Statuses</option>
                    <option value="Success"   <?= strtolower($filter_status)==='success'   ? 'selected':'' ?>>Success</option>
                    <option value="Pending"   <?= strtolower($filter_status)==='pending'   ? 'selected':'' ?>>Pending</option>
                    <option value="Cancelled" <?= strtolower($filter_status)==='cancelled' ? 'selected':'' ?>>Cancelled</option>
                </select>
            </div>

            <!-- Search -->
            <div class="act-filter-item">
                <input type="text" id="filter_search" value="<?= htmlspecialchars($filter_search) ?>" placeholder="Search..." class="act-filter-input" style="width: 140px;">
            </div>

            <button type="button" onclick="applyFilters()" class="act-btn-apply">
                <i class="fas fa-filter"></i> Apply
            </button>
        </div>

        <!-- EXPORT & PRINT BUTTONS -->
        <div class="rpt-export-group">
            <button type="button" onclick="_actPrint()" class="rpt-export-btn rpt-btn-print" title="Print report">
                <i class="fas fa-print"></i> Print
            </button>
            <button type="button" onclick="exportPDF(this)" class="rpt-export-btn rpt-btn-pdf" title="Export PDF">
                <i class="fas fa-file-pdf"></i> PDF
            </button>
            <a href="?date_start=<?= urlencode($date_start) ?>&date_end=<?= urlencode($date_end) ?>&shift=<?= urlencode($filter_shift) ?>&module=<?= urlencode($filter_module) ?>&activity=<?= urlencode($filter_activity) ?>&status=<?= urlencode($filter_status) ?>&search=<?= urlencode($filter_search) ?>&export=excel" 
               class="rpt-export-btn rpt-btn-excel" title="Export to Excel">
                <i class="fas fa-file-excel"></i> Excel
            </a>
            <button type="button" onclick="actExportCSV()" class="rpt-export-btn rpt-btn-csv" title="Export to CSV">
                <i class="fas fa-file-csv"></i> CSV
            </button>
        </div>
    </div>

    <!-- PRINTABLE REPORT AREA -->
    <div class="print-area" id="actPrintArea">
        <div class="act-card-container">
            
            <!-- HEADER -->
            <div class="header" style="text-align:center; margin-bottom:20px; border-bottom:2.5px solid #002F6C; padding-bottom:14px;">
                <h1 style="font-size:24px; font-weight:900; color:#002F6C; margin:0 0 6px 0; letter-spacing:0.5px; font-family:'Segoe UI', sans-serif;">MY ACTIVITY REPORT</h1>
                <div style="font-size:15px; font-weight:800; color:#1e293b; margin-bottom:5px;">
                    <?= htmlspecialchars($station_name) ?><?= $station_location ? ' — ' . htmlspecialchars($station_location) : '' ?>
                </div>
                <div style="font-size:14px; color:#334155; font-weight:700;">
                    <span><strong>Period:</strong> <?= date('F d, Y', strtotime($date_start)) ?> – <?= date('F d, Y', strtotime($date_end)) ?></span>
                </div>
            </div>

            <!-- ACTIVITY TABLE -->
            <div class="act-table-wrap">
                <table class="act-table report-table no-min-width print-table" id="activityTable">
                    <thead>
                        <tr>
                            <th style="width: 18%;">Date & Time</th>
                            <th style="width: 14%;">Module</th>
                            <th style="width: 38%;">Activity & Details</th>
                            <th style="width: 17%;">Reference No.</th>
                            <th style="width: 13%; text-align: center;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($unique_activities) > 0): ?>
                            <?php foreach ($unique_activities as $row): ?>
                                <?php 
                                    $st_lower = strtolower($row['status']);
                                    $badge_class = 'badge-status-pending';
                                    if ($st_lower === 'success') {
                                        $badge_class = 'badge-status-success';
                                    } elseif ($st_lower === 'cancelled' || $st_lower === 'canceled') {
                                        $badge_class = 'badge-status-cancelled';
                                    }
                                ?>
                                <tr>
                                    <td>
                                        <div class="act-date-val"><?= date('M d, Y', strtotime($row['datetime'])) ?></div>
                                        <div class="act-time-val"><?= date('h:i:s A', strtotime($row['datetime'])) ?></div>
                                    </td>
                                    <td>
                                        <span class="badge-module"><?= htmlspecialchars($row['module']) ?></span>
                                    </td>
                                    <td>
                                        <div class="act-title"><?= htmlspecialchars($row['activity']) ?></div>
                                        <?php if (!empty($row['details'])): ?>
                                            <div class="act-details"><?= htmlspecialchars($row['details']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="act-ref-no"><?= htmlspecialchars($row['ref_no'] ?: '—') ?></span>
                                    </td>
                                    <td style="text-align: center;">
                                        <span class="<?= $badge_class ?>"><?= htmlspecialchars($row['status']) ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: #475569; padding: 32px 16px; font-size: 15px; font-weight: 700; font-style: italic;">
                                    No activity records found matching your selected filters.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- REPORT SIGNATURE: PREPARED BY ONLY (RIGHT-ALIGNED, SINGLE LINE, MATCHES TEXT WIDTH) -->
            <?php 
                $clean_staff_name = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''));
                if (empty($clean_staff_name) || in_array($clean_staff_name, ['—', '-', 'N/A'], true)) {
                    $clean_staff_name = trim($me['name'] ?? $me['username'] ?? 'Staff / Cashier');
                }
            ?>
            <div class="str-signature-wrap" style="display:none; justify-content:flex-end; margin-top:24px; padding:0 4px;">
                <div style="display:inline-flex; flex-direction:column; align-items:center; text-align:center; width:fit-content; max-width:100%;">
                    <div style="font-size:12px; font-weight:800; color:#002F6C; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:28px; align-self:flex-start;">
                        Prepared By:
                    </div>
                    <div class="str-sig-line" style="border-top:2px solid #002F6C; width:100%; margin-bottom:5px;"></div>
                    <div style="font-size:14px; font-weight:800; color:#1e293b; text-transform:uppercase; white-space:nowrap;">
                        <?= htmlspecialchars($clean_staff_name) ?>
                    </div>
                    <div style="font-size:12px; color:#475569; font-weight:700; margin-top:2px; white-space:nowrap;">
                        Signature over Printed Name
                    </div>
                </div>
            </div>

        </div>
    </div>

</div>

<script>
function applyFilters() {
    const ds  = document.getElementById('date_start').value;
    const de  = document.getElementById('date_end').value;
    const sh  = document.getElementById('filter_shift').value;
    const mod = document.getElementById('filter_module').value;
    const act = document.getElementById('filter_activity').value;
    const st  = document.getElementById('filter_status').value;
    const sr  = document.getElementById('filter_search').value;

    if (!ds || !de) {
        alert('Please select both From and To dates.');
        return;
    }
    if (de < ds) {
        alert('To Date cannot be earlier than From Date.');
        return;
    }

    const url = new URL(window.location.href);
    url.searchParams.set('date_start', ds);
    url.searchParams.set('date_end', de);
    if (sh)  url.searchParams.set('shift', sh);     else url.searchParams.delete('shift');
    if (mod) url.searchParams.set('module', mod);   else url.searchParams.delete('module');
    if (act) url.searchParams.set('activity', act); else url.searchParams.delete('activity');
    if (st)  url.searchParams.set('status', st);     else url.searchParams.delete('status');
    if (sr)  url.searchParams.set('search', sr);     else url.searchParams.delete('search');

    window.location.href = url.toString();
}

function actExportCSV() {
    const ds  = document.getElementById('date_start').value;
    const de  = document.getElementById('date_end').value;
    const sh  = document.getElementById('filter_shift').value;
    const mod = document.getElementById('filter_module').value;
    const act = document.getElementById('filter_activity').value;
    const st  = document.getElementById('filter_status').value;
    const sr  = document.getElementById('filter_search').value;

    let url = window.location.pathname + '?export=csv&date_start=' + encodeURIComponent(ds) + '&date_end=' + encodeURIComponent(de);
    if (sh)  url += '&shift='    + encodeURIComponent(sh);
    if (mod) url += '&module='   + encodeURIComponent(mod);
    if (act) url += '&activity=' + encodeURIComponent(act);
    if (st)  url += '&status='   + encodeURIComponent(st);
    if (sr)  url += '&search='   + encodeURIComponent(sr);

    window.location.href = url;
}

function exportPDF(btn) {
    exportPrintableAreaToPDF('#actPrintArea', 'MY ACTIVITY REPORT', 'staff_activity_report', btn);
}

function _actPrint(afterPrint) {
    var old = document.querySelector('.sfss-print-only');
    if (old) old.remove();

    var area = document.getElementById('actPrintArea');
    if (!area) { window.print(); return; }

    var origTitle  = document.title;
    document.title = 'My Activity Report';

    var printDiv           = document.createElement('div');
    printDiv.className     = 'sfss-print-only';
    printDiv.innerHTML     = area.innerHTML;
    printDiv.style.display = 'block';
    document.body.appendChild(printDiv);

    var scrollBtn = document.getElementById('toggleScrollBtn');
    if (scrollBtn) scrollBtn.style.setProperty('display', 'none', 'important');

    setTimeout(function() {
        window.print();
        var cleanup = function() {
            var p = document.querySelector('.sfss-print-only');
            if (p) p.remove();
            document.title = origTitle;
            if (scrollBtn) scrollBtn.style.setProperty('display', 'flex', 'important');
            window.removeEventListener('afterprint', cleanup);
            if (typeof afterPrint === 'function') afterPrint();
        };
        window.addEventListener('afterprint', cleanup);
        setTimeout(cleanup, 30000);
    }, 150);
}
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
