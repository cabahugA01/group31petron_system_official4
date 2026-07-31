<?php
/**
 * STAFF ACTIVITY REPORT
 * Log-style timeline view for audit trail and staff action monitoring
 * Plain black & white design
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
try {
    $s = $pdo->prepare("SELECT name, location FROM stations WHERE id=? LIMIT 1");
    $s->execute([$station_id]);
    $st = $s->fetch(PDO::FETCH_ASSOC);
    if ($st) {
        $station_name = $st['name'];
    }
} catch (Exception $e) {}

// Date handling - default to last 7 days
$today = date('Y-m-d');
$seven_days_ago = date('Y-m-d', strtotime('-7 days'));
$date_start = trim($_GET['date_start'] ?? $seven_days_ago);
$date_end = trim($_GET['date_end'] ?? $today);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_start)) $date_start = $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_end)) $date_end = $today;

// Helper functions
function table_exists($pdo, $table) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

// Check available tables
$has_audit_log = table_exists($pdo, 'audit_logs');
$has_user_sessions = table_exists($pdo, 'user_sessions');

// Initialize data
$activity_logs = [];
$shift1_logins = 0;
$shift1_encodes = 0;
$shift1_edits = 0;
$shift1_exports = 0;
$shift2_logins = 0;
$shift2_encodes = 0;
$shift2_edits = 0;
$shift2_exports = 0;

// ============================================================
// FETCH ACTIVITY LOGS - COMPREHENSIVE FROM ALL SOURCES
// ============================================================
$all_activities = [];
$is_staff_only = in_array($role, ['staff', 'cashier', 'pump_attendant']);

// 1. PRIORITY: Fetch from audit_logs table (main activity tracker)
if ($has_audit_log) {
    try {
        $sql = "
            SELECT 
                al.id,
                al.user_id,
                COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),
                         u.username, CONCAT('User #', al.user_id))  AS username,
                al.action_type                                        AS activity_type,
                al.created_at                                         AS timestamp,
                COALESCE(al.action_details, '')                       AS description,
                COALESCE(al.status,'SUCCESS')                         AS status,
                ''                                                    AS remarks
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE al.user_id = ?
              AND DATE(al.created_at) BETWEEN ? AND ?
            ORDER BY al.created_at DESC
            LIMIT 500
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id, $date_start, $date_end]);
        $audit_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($audit_logs) > 0) {
            $all_activities = array_merge($all_activities, $audit_logs);
        }
    } catch (Exception $e) {
        error_log("Audit logs fetch error: " . $e->getMessage());
    }
}

// 1b. Also from activity_logs (lib.php log_activity() calls â€” staff login, encode, etc.)
try {
    $stmt2 = $pdo->prepare("
        SELECT
            al.id,
            al.user_id,
            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),
                     u.username, CONCAT('User #', al.user_id))  AS username,
            al.action                                             AS activity_type,
            al.created_at                                         AS timestamp,
            COALESCE(al.details,'')                               AS description,
            'LOGGED'                                              AS status,
            ''                                                    AS remarks
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.id
        WHERE al.user_id = ?
          AND DATE(al.created_at) BETWEEN ? AND ?
        ORDER BY al.created_at DESC
        LIMIT 300
    ");
    $stmt2->execute([$user_id, $date_start, $date_end]);
    $al_rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    if (count($al_rows) > 0) {
        $all_activities = array_merge($all_activities, $al_rows);
    }
} catch (Exception $e) {
    error_log("Activity logs fetch error: " . $e->getMessage());
}

// 2. Fetch from user_sessions for login/logout (if not in audit_log)
if ($has_user_sessions) {
    try {
        $login_cond = "WHERE u.station_id = ? AND DATE(us.login_time) BETWEEN ? AND ?";
        $us_params = [$station_id, $date_start, $date_end];
        if ($is_staff_only) {
            $login_cond .= " AND us.user_id = ?";
            $us_params[] = $user_id;
        }

        $logout_cond = "WHERE u.station_id = ? AND us.logout_time IS NOT NULL AND DATE(us.logout_time) BETWEEN ? AND ?";
        $us_params[] = $station_id; $us_params[] = $date_start; $us_params[] = $date_end;
        if ($is_staff_only) {
            $logout_cond .= " AND us.user_id = ?";
            $us_params[] = $user_id;
        }

        $sql = "SELECT 
                    us.id,
                    us.user_id,
                    u.username,
                    'Login' as activity_type,
                    us.login_time as timestamp,
                    CONCAT('Login - Session started') as description,
                    '' as remarks
            FROM user_sessions us
            LEFT JOIN users u ON us.user_id = u.id
            $login_cond
            
            UNION ALL
            
            SELECT 
                    us.id,
                    us.user_id,
                    u.username,
                    'Logout' as activity_type,
                    us.logout_time as timestamp,
                    CONCAT('Logout - Session ended') as description,
                    '' as remarks
            FROM user_sessions us
            LEFT JOIN users u ON us.user_id = u.id
            $logout_cond
            ORDER BY timestamp DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($us_params));
        $login_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $all_activities = array_merge($all_activities, $login_logs);
    } catch (Exception $e) {
        error_log("User sessions fetch error: " . $e->getMessage());
    }
}

// 3. Fuel Transaction Encoding â€” staff's own records only
if (table_exists($pdo, 'fuel_transactions')) {
    try {
        $sql = "
            SELECT 
                ft.id,
                ft.staff_id                                                              AS user_id,
                COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),
                         u.username, CONCAT('User #', ft.staff_id))                     AS username,
                CONCAT('Fuel Reading â€” ', COALESCE(ft.fuel_type,'N/A'))                AS activity_type,
                COALESCE(ft.transaction_date, ft.created_at)                            AS timestamp,
                CONCAT('Fuel: ', COALESCE(ft.fuel_type,'N/A'),
                       ' | Vol: ', FORMAT(COALESCE(ft.liters_sold,0),2), 'L',
                       ' | â‚±', FORMAT(ft.total_amount,2),
                       ' | Status: ', COALESCE(ft.status,'Pending'))                    AS description,
                COALESCE(ft.status,'Pending')                                           AS status,
                COALESCE(ft.notes,'')                                                   AS remarks
            FROM fuel_transactions ft
            LEFT JOIN users u ON u.id = ft.staff_id
            WHERE ft.staff_id = ?
              AND ft.station_id = ?
              AND DATE(COALESCE(ft.transaction_date, ft.created_at)) BETWEEN ? AND ?
            ORDER BY COALESCE(ft.transaction_date, ft.created_at) DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id, $station_id, $date_start, $date_end]);
        $fuel_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $all_activities = array_merge($all_activities, $fuel_logs);
    } catch (Exception $e) {
        error_log("Fuel logs fetch error: " . $e->getMessage());
    }
}

// 4. Merchandise Transaction Encoding â€” staff's own transactions only
if (table_exists($pdo, 'merchandise_transactions')) {
    try {
        $sql = "
            SELECT 
                mt.id,
                mt.staff_id AS user_id,
                COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),
                         u.username, CONCAT('User #', mt.staff_id))  AS username,
                CONCAT('Merchandise â€” ', COALESCE(mt.transaction_type,'merchandise'))  AS activity_type,
                mt.created_at                                                           AS timestamp,
                CONCAT('Txn: ', COALESCE(NULLIF(mt.transaction_id,''), CONCAT('#', mt.id)),
                       ' | Customer: ', COALESCE(NULLIF(mt.customer_name,''),'Walk-in'),
                       ' | Total: â‚±', FORMAT(mt.total_amount,2),
                       ' | Status: ', COALESCE(mt.validation_status,'Pending'))        AS description,
                COALESCE(mt.validation_status,'Pending')                               AS status,
                COALESCE(mt.staff_remarks, mt.remarks, '')                             AS remarks
            FROM merchandise_transactions mt
            LEFT JOIN users u ON mt.staff_id = u.id
            WHERE mt.staff_id = ?
              AND mt.station_id = ?
              AND DATE(mt.created_at) BETWEEN ? AND ?
            ORDER BY mt.created_at DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id, $station_id, $date_start, $date_end]);
        $merch_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $all_activities = array_merge($all_activities, $merch_logs);
    } catch (Exception $e) {
        error_log("Merchandise logs fetch error: " . $e->getMessage());
    }
}

// 5. Job Orders Encoding â€” staff's own records only
if (table_exists($pdo, 'job_orders')) {
    try {
        $sql = "
            SELECT 
                jo.id,
                COALESCE(jo.created_by, jo.user_id)                                     AS user_id,
                COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),
                         u.username, CONCAT('User #', COALESCE(jo.created_by, jo.user_id)))  AS username,
                CONCAT('Job Order â€” ', COALESCE(jo.service_type,'Service'))              AS activity_type,
                jo.created_at                                                             AS timestamp,
                CONCAT('JO: ', COALESCE(jo.job_order_id, COALESCE(jo.job_order_number, CONCAT('JO-', jo.id))),
                       ' | Service: ', COALESCE(jo.service_type,'N/A'),
                       ' | Total: â‚±', FORMAT(COALESCE(jo.total_cost, jo.estimated_cost,0),2),
                       ' | Status: ', COALESCE(jo.validation_status, jo.status,'Pending')) AS description,
                COALESCE(jo.validation_status, jo.status,'Pending')                      AS status,
                COALESCE(jo.notes, jo.additional_notes,'')                               AS remarks
            FROM job_orders jo
            LEFT JOIN users u ON u.id = COALESCE(jo.created_by, jo.user_id)
            WHERE COALESCE(jo.created_by, jo.user_id) = ?
              AND jo.station_id = ?
              AND DATE(jo.created_at) BETWEEN ? AND ?
            ORDER BY jo.created_at DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id, $station_id, $date_start, $date_end]);
        $jo_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $all_activities = array_merge($all_activities, $jo_logs);
    } catch (Exception $e) {
        error_log("Job order logs fetch error: " . $e->getMessage());
    }
}

// 6. Payment Encoding
if (table_exists($pdo, 'payments')) {
    try {
        $sql = "SELECT 
                    p.id,
                    p.user_id,
                    u.username,
                    'Payment Encoding' as activity_type,
                    p.created_at as timestamp,
                    CONCAT('Encoded payment - ', p.transaction_id, ' - ', p.payment_mode, ' - â‚±', FORMAT(p.amount_paid, 2)) as description,
                    p.remarks
            FROM payments p
            LEFT JOIN users u ON p.user_id = u.id ";
            
        $where_clauses = ["p.station_id = ?", "DATE(p.created_at) BETWEEN ? AND ?"];
        $params = [$station_id, $date_start, $date_end];
        if ($is_staff_only) {
            $where_clauses[] = "p.user_id = ?";
            $params[] = $user_id;
        }
        $sql .= " WHERE " . implode(" AND ", $where_clauses);
        $sql .= " ORDER BY p.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $payment_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $all_activities = array_merge($all_activities, $payment_logs);
    } catch (Exception $e) {
        error_log("Payment logs fetch error: " . $e->getMessage());
    }
}

// 7. Audit Log (singular - captures edits, updates, approvals)
if (table_exists($pdo, 'audit_log')) {
    try {
        $sql = "SELECT 
                    al.id,
                    al.user_id,
                    u.username,
                    CONCAT(UPPER(SUBSTRING(al.action, 1, 1)), SUBSTRING(al.action, 2)) as activity_type,
                    al.created_at as timestamp,
                    CONCAT(al.action, ' - ', COALESCE(al.details, '')) as description,
                    '' as remarks
            FROM audit_log al
            LEFT JOIN users u ON al.user_id = u.id ";
            
        $where_clauses = ["al.station_id = ?", "DATE(al.created_at) BETWEEN ? AND ?"];
        $params = [$station_id, $date_start, $date_end];
        if ($is_staff_only) {
            $where_clauses[] = "al.user_id = ?";
            $params[] = $user_id;
        }
        $sql .= " WHERE " . implode(" AND ", $where_clauses);
        $sql .= " ORDER BY al.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $audit_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $all_activities = array_merge($all_activities, $audit_logs);
    } catch (Exception $e) {
        error_log("Audit log singular fetch error: " . $e->getMessage());
    }
}

// Sort all activities by timestamp descending
usort($all_activities, function($a, $b) {
    return strtotime($b['timestamp']) - strtotime($a['timestamp']);
});

// Calculate shift summaries
foreach ($all_activities as $activity) {
    $hour = (int)date('H', strtotime($activity['timestamp']));
    $type = strtolower($activity['activity_type']);
    
    $is_shift1 = ($hour >= 6 && $hour < 14);
    
    if (strpos($type, 'login') !== false) {
        if ($is_shift1) $shift1_logins++; else $shift2_logins++;
    } elseif (strpos($type, 'encoding') !== false || strpos($type, 'encoded') !== false) {
        if ($is_shift1) $shift1_encodes++; else $shift2_encodes++;
    } elseif (strpos($type, 'update') !== false || strpos($type, 'edit') !== false) {
        if ($is_shift1) $shift1_edits++; else $shift2_edits++;
    } elseif (strpos($type, 'export') !== false || strpos($type, 'print') !== false) {
        if ($is_shift1) $shift1_exports++; else $shift2_exports++;
    }
}

// ============================================================
// EXCEL EXPORT HANDLER
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="Activity_Report_' . $date_start . '_to_' . $date_end . '.xls"');
    header('Cache-Control: max-age=0');
    
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8" /></head>';
    echo '<body>';
    echo '<h1>STAFF ACTIVITY REPORT</h1>';
    echo '<p>' . htmlspecialchars($station_name) . '</p>';
    echo '<p><strong>Period:</strong> ' . date('F d, Y', strtotime($date_start)) . ' - ' . date('F d, Y', strtotime($date_end)) . '</p><br/>';
    
    echo '<table border="1" cellpadding="5" cellspacing="0">';
    echo '<thead><tr>';
    echo '<th>#</th><th>Action Type</th><th>Action Details</th><th>Encoder</th><th>Status</th><th>Created At</th><th>Remarks</th>';
    echo '</tr></thead><tbody>';
    
    if (count($all_activities) > 0) {
        $counter = 1;
        foreach ($all_activities as $activity) {
            echo '<tr>';
            echo '<td>' . $counter++ . '</td>';
            echo '<td>' . htmlspecialchars($activity['activity_type']) . '</td>';
            echo '<td>' . htmlspecialchars($activity['description']) . '</td>';
            echo '<td>' . htmlspecialchars($activity['username'] ?? 'System') . '</td>';
            echo '<td>LOGGED</td>';
            echo '<td>' . date('Y-m-d H:i:s', strtotime($activity['timestamp'])) . '</td>';
            echo '<td>' . htmlspecialchars($activity['remarks'] ?? 'â€”') . '</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table>';
    echo '</body></html>';
    exit;
}

// ============================================================
// CSV EXPORT HANDLER
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename="Activity_Report_' . $date_start . '_to_' . $date_end . '.csv"');
    header('Cache-Control: max-age=0');
    
    $output = fopen('php://output', 'w');
    
    // CSV Headers
    fputcsv($output, ['#', 'Action Type', 'Action Details', 'Encoder', 'Status', 'Created At', 'Remarks']);
    
    // CSV Data
    if (count($all_activities) > 0) {
        $counter = 1;
        foreach ($all_activities as $activity) {
            fputcsv($output, [
                $counter++,
                $activity['activity_type'],
                $activity['description'],
                $activity['username'] ?? 'System',
                'LOGGED',
                date('Y-m-d H:i:s', strtotime($activity['timestamp'])),
                $activity['remarks'] ?? 'â€”'
            ]);
        }
    }
    
    fclose($output);
    exit;
}

$page_title = "Staff Activity Report";

// Include system header
require_once __DIR__ . '/../partials/header.php';
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
    
    /* Export Buttons (Filter Button Style) */
    .flt-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 6px 14px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        border: 1px solid transparent;
        transition: all 0.15s;
        height: 34px;
        line-height: 1;
        white-space: nowrap;
        text-decoration: none;
        background: white !important;
    }
    .flt-btn-search { color: #002F70 !important; border-color: #002F70 !important; }
    .flt-btn-search:hover { background: #002F70 !important; color: #fff !important; }
    .flt-btn-reset  { color: #6b7280 !important; border-color: #6b7280 !important; }
    .flt-btn-reset:hover  { background: #6b7280 !important; color: #fff !important; }
    .flt-btn-excel { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
    .flt-btn-excel:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
    .flt-btn-pdf { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
    .flt-btn-pdf:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
    .flt-btn-csv { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
    .flt-btn-csv:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
    
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
    
    /* Table Style - Same as Audit Trail */
    .table-container {
        overflow:hidden;
        margin-bottom: 20px;
        width: 100%;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
        background: white;
        font-size: 11px;
    }
    
    thead { 
        background: #003366;
        color: #fff;
    }
    
    th { 
        padding: 10px 8px; 
        text-align: left; 
        font-weight: 700; 
        font-size: 10px; 
        text-transform: uppercase; 
        border: 1px solid #003366;
        color: #fff;
    }
    
    td { 
        padding: 10px 8px; 
        border: 1px solid #ddd; 
        font-size: 11px; 
        vertical-align: top;
        line-height: 1.5;
    }
    
    tbody tr { 
        background: #fff; 
    }
    
    tbody tr:nth-child(even) {
        background: #f9f9f9;
    }
    
    tbody tr:hover { 
        background: #f0f0f0; 
    }
    
    .text-center { text-align: center; }
    .text-right { text-align: right; }
    
    .shift-summary { 
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
        width: 100%;
    }
    
    .shift-box td { 
        padding: 6px 4px; 
        border: none; 
        border-bottom: 1px solid #ddd; 
    }
    
    .text-right { text-align: right; }
    .font-bold { font-weight: 700; }
    
    @media print {
        @page {
            size: A4 landscape;
            margin: 0.4in 0.4in;
        }

        * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }

        body * { visibility: hidden !important; }
        .print-area, .print-area * { visibility: visible !important; }
        .print-area {
            position: static !important;
            top: auto !important; left: auto !important;
            width: 100% !important; margin: 0 !important; padding: 0 !important;
            background: white !important;
        }
        html, body { margin: 0 !important; padding: 0 !important; background: white !important; overflow: visible !important; }
        .container, .content { margin: 0 !important; padding: 0 !important; }

        /* â”€â”€ Kill ALL icons â”€â”€ */
        i, svg, .fas, .far, .fab, .fa, [class*="fa-"] {
            display: none !important;
            width: 0 !important; height: 0 !important;
            font-size: 0 !important; line-height: 0 !important;
            margin: 0 !important; padding: 0 !important;
        }

        .header { text-align: center !important; border-bottom: 2px solid #000 !important; padding: 6px 0 !important; margin: 0 0 8px 0 !important; }
        .header h1 { font-size: 16px !important; font-weight: 700 !important; color: #000 !important; margin: 0 0 3px 0 !important; text-transform: uppercase !important; }
        .header p { font-size: 10px !important; color: #000 !important; margin: 2px 0 !important; }
        .section-title { font-size: 12px !important; font-weight: 700 !important; margin: 8px 0 4px 0 !important; padding-bottom: 3px !important; border-bottom: 2px solid #000 !important; color: #000 !important; page-break-after: avoid !important; }

        .table-container { overflow: visible !important; margin-bottom: 6px !important; width: 100% !important; text-align: center !important; }

        table { width: 100% !important; max-width: 100% !important; border-collapse: collapse !important; font-size: 9px !important; table-layout: fixed !important; margin: 0 0 8px 0 !important; }
        thead { display: table-header-group !important; }
        tbody { display: table-row-group !important; }
        tr { page-break-inside: auto !important; }
        th { font-size: 9px !important; padding: 5px 6px !important; border: 1px solid #000 !important; background-color: #003366 !important; color: #fff !important; font-weight: 700 !important; text-align: center !important; white-space: normal !important; word-wrap: break-word !important; overflow-wrap: break-word !important; }
        td { font-size: 8.5px !important; padding: 4px 6px !important; border: 1px solid #ccc !important; white-space: normal !important; word-wrap: break-word !important; overflow-wrap: break-word !important; vertical-align: top !important; }

        /* Column widths for the activity log table */
        .print-area table th:nth-child(1),
        .print-area table td:nth-child(1) { width: 4% !important; }
        .print-area table th:nth-child(2),
        .print-area table td:nth-child(2) { width: 14% !important; }
        .print-area table th:nth-child(3),
        .print-area table td:nth-child(3) { width: 42% !important; }
        .print-area table th:nth-child(4),
        .print-area table td:nth-child(4) { width: 13% !important; }
        .print-area table th:nth-child(5),
        .print-area table td:nth-child(5) { width: 10% !important; }
        .print-area table th:nth-child(6),
        .print-area table td:nth-child(6) { width: 17% !important; }
    }
</style>

<div class="stock-page">
<!-- CONTROLS -->
<div class="controls">
    <div class="date-controls">
        <label style="font-weight:700;color:#64748b;font-size:13px;text-transform:uppercase;letter-spacing:.4px;">From</label>
        <input type="date" id="date_start" value="<?= htmlspecialchars($date_start) ?>" max="<?= $today ?>">
        <label style="font-weight:700;color:#64748b;font-size:13px;text-transform:uppercase;letter-spacing:.4px;">To</label>
        <input type="date" id="date_end" value="<?= htmlspecialchars($date_end) ?>" max="<?= $today ?>">
        <button class="btn btn-primary" onclick="applyFilters()">
            <i class="fa-solid fa-filter"></i> Apply
        </button>
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <!-- Excel -->
        <a href="?export=excel&date_start=<?= urlencode($date_start) ?>&date_end=<?= urlencode($date_end) ?>" 
           class="flt-btn flt-btn-excel" title="Export to Excel">
            <i class="fas fa-file-excel"></i> Excel
        </a>
        <!-- CSV -->
        <a href="?export=csv&date_start=<?= urlencode($date_start) ?>&date_end=<?= urlencode($date_end) ?>" 
           class="flt-btn flt-btn-csv" title="Export to CSV">
            <i class="fas fa-file-csv"></i> CSV
        </a>
        <!-- PDF -->
        <button type="button" onclick="exportPrintableAreaToPDF('.print-area', 'Staff Activity Report', 'staff_activity_report_<?= date('Ymd', strtotime($date_start)) ?>_<?= date('Ymd', strtotime($date_end)) ?>', this)" class="flt-btn flt-btn-pdf" title="Export PDF">
            <i class="fas fa-file-pdf"></i> PDF
        </button>
        <!-- Print -->
        <button type="button" onclick="printReportArea()" class="flt-btn flt-btn-print" title="Print report">
            <i class="fas fa-print"></i> Print
        </button>
    </div>
</div>

<!-- PRINTABLE DOCUMENT AREA -->
<div class="print-area">
    <div class="container">
        <div class="header">
            <h1>STAFF ACTIVITY REPORT</h1>
            <p><?= htmlspecialchars($station_name) ?></p>
            <p><strong>Period:</strong> <?= date('F d, Y', strtotime($date_start)) ?> - <?= date('F d, Y', strtotime($date_end)) ?></p>
        </div>
        
        <div class="content">
            <div class="section-title">ACTIVITY LOG</div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 5%;">#</th>
                            <th style="width: 12%;">ACTION TYPE</th>
                            <th style="width: 40%;">ACTION DETAILS</th>
                            <th style="width: 12%;">ENCODER</th>
                            <th style="width: 10%;">STATUS</th>
                            <th style="width: 13%;">CREATED AT</th>
                            <th style="width: 8%;">REMARKS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($all_activities) > 0): 
                            $counter = 1;
                            foreach ($all_activities as $activity): 
                        ?>
                        <tr>
                            <td class="text-center"><?= $counter++ ?></td>
                            <td><strong><?= htmlspecialchars($activity['activity_type']) ?></strong></td>
                            <td><?= htmlspecialchars($activity['description']) ?></td>
                            <td><?= htmlspecialchars($activity['username'] ?? 'System') ?></td>
                            <td class="text-center"><span style="color: #28a745; font-weight: 600;">LOGGED</span></td>
                            <td><?= date('Y-m-d H:i:s', strtotime($activity['timestamp'])) ?></td>
                            <td><?= htmlspecialchars($activity['remarks'] ?? 'â€”') ?></td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 40px;">
                                <p style="margin-bottom: 10px;color:#64748b;">No activity logs found for this period.</p>
                                <p style="font-size: 10px; color: #94a3b8;">
                                    Period: <?= htmlspecialchars($date_start) ?> to <?= htmlspecialchars($date_end) ?>
                                </p>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</div>

<script>
function applyFilters() {
    const dateStart = document.getElementById('date_start').value;
    const dateEnd = document.getElementById('date_end').value;
    window.location.href = `?date_start=${dateStart}&date_end=${dateEnd}`;
}
document.getElementById('date_start').addEventListener('keypress', function(e) { if (e.key === 'Enter') applyFilters(); });
document.getElementById('date_end').addEventListener('keypress', function(e) { if (e.key === 'Enter') applyFilters(); });
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
