<?php
/**
 * Official Consolidated Employee Master Report Exporter
 * Petron Station Management System
 */
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../vendor/autoload.php';

require_login();

$me = current_user();
$my_role = role_key($me['role'] ?? 'staff');
$my_station_id = user_station_id();

// Access Control: Only Manager, Admin, and Superadmin can export employee list
if (!in_array($my_role, ['manager', 'admin', 'superadmin'], true)) {
    http_response_code(403);
    exit('Unauthorized access.');
}

$format = strtolower(trim($_GET['format'] ?? 'excel'));
$today = date('Y-m-d');
$now_formatted = date('F j, Y h:i A');

// Fetch Station Name
$station_name = 'Petron Carmen';
if ($my_station_id) {
    $stn_stmt = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
    $stn_stmt->execute([$my_station_id]);
    $stn_row = $stn_stmt->fetch(PDO::FETCH_ASSOC);
    if ($stn_row && !empty($stn_row['name'])) {
        $station_name = $stn_row['name'];
    }
}

// Fetch Employees based on station authorization
$user_cols_sql = "
    u.id,
    u.employee_id,
    u.first_name,
    u.last_name,
    CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS full_name,
    u.username,
    u.role,
    u.email,
    u.phone_number,
    u.status,
    u.created_at,
    u.updated_at,
    s.name AS station_name
";

if ($my_role === 'superadmin') {
    $stmt = $pdo->query("
        SELECT {$user_cols_sql}
        FROM users u
        LEFT JOIN stations s ON u.station_id = s.id
        ORDER BY u.created_at ASC
    ");
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("
        SELECT {$user_cols_sql}
        FROM users u
        LEFT JOIN stations s ON u.station_id = s.id
        WHERE u.station_id = ?
        ORDER BY u.role, u.username ASC
    ");
    $stmt->execute([$my_station_id]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Filter Parameters for Export (Matches applied UI filters)
$filter_q      = strtolower(trim($_GET['q'] ?? ''));
$filter_role   = strtolower(trim($_GET['role'] ?? ''));
$filter_status = strtolower(trim($_GET['status'] ?? ''));
$filter_tab    = strtolower(trim($_GET['tab'] ?? 'active'));

$filtered_list = [];
foreach ($employees as $emp) {
    $emp_id    = strtolower(trim($emp['employee_id'] ?: ('EMP-' . str_pad($emp['id'], 4, '0', STR_PAD_LEFT))));
    $full_name = strtolower(trim($emp['full_name'] ?: $emp['username']));
    $username  = strtolower(trim($emp['username'] ?? ''));
    $role_key  = strtolower(trim(role_key($emp['role'] ?? '')));
    $status    = strtolower(trim($emp['status'] ?? 'active'));
    $is_archived = is_user_archived_status($status);

    // Tab Filter Check (Active vs Archived) if status filter not explicitly set
    if ($filter_status === '') {
        if ($filter_tab === 'active' && $is_archived) {
            continue;
        } elseif ($filter_tab === 'archived' && !$is_archived) {
            continue;
        }
    } else {
        // Status Filter Check
        if ($status !== $filter_status) {
            continue;
        }
    }

    // Role Filter Check
    if ($filter_role !== '' && $role_key !== $filter_role) {
        continue;
    }

    // Search Query Check (matches Employee ID, Full Name, or Username)
    if ($filter_q !== '') {
        $match = (strpos($emp_id, $filter_q) !== false) ||
                 (strpos($full_name, $filter_q) !== false) ||
                 (strpos($username, $filter_q) !== false);
        if (!$match) {
            continue;
        }
    }

    $filtered_list[] = $emp;
}
$employees = $filtered_list;

$record_count = count($employees);
$clean_station_name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', str_replace(' ', '_', $station_name));

// Fetch Document Completeness Status map for each user
$docs_map = [];
try {
    $docs_query = $pdo->query("SELECT user_id, doc_type, status, file_name FROM employee_documents");
    while ($d = $docs_query->fetch(PDO::FETCH_ASSOC)) {
        $st = $d['status'];
        if (empty($d['file_name']) && strtolower($st) === 'complete') {
            $st = 'Missing';
        }
        $docs_map[$d['user_id']][$d['doc_type']] = [
            'status' => $st,
            'file_name' => $d['file_name']
        ];
    }
} catch (Exception $e) {}

// Fetch Activity & Last Login Summary map for each user
$activity_map = [];
try {
    // 1. Audit Trail Activity Counts & Last Activity
    $act_query = $pdo->query("
        SELECT user_id, COUNT(*) AS act_count, MAX(created_at) AS last_act
        FROM audit_trail
        GROUP BY user_id
    ");
    while ($a = $act_query->fetch(PDO::FETCH_ASSOC)) {
        $activity_map[$a['user_id']] = [
            'count' => (int)$a['act_count'],
            'last_act' => $a['last_act']
        ];
    }

    // 2. Activity Logs fallback if audit_trail count is 0
    $log_query = $pdo->query("
        SELECT user_id, COUNT(*) AS log_count, MAX(created_at) AS last_log
        FROM activity_logs
        GROUP BY user_id
    ");
    while ($l = $log_query->fetch(PDO::FETCH_ASSOC)) {
        $uid = $l['user_id'];
        if (!isset($activity_map[$uid]) || $activity_map[$uid]['count'] === 0) {
            $activity_map[$uid] = [
                'count' => (int)$l['log_count'],
                'last_act' => $l['last_log']
            ];
        }
    }
} catch (Exception $e) {}

// Log Audit Trail for Export Action
try {
    $audit_format_label = strtoupper($format);
    log_activity(
        $pdo,
        $me['id'],
        'Exported Master Employee Report',
        "Exported $record_count employee records (Format: $audit_format_label, Station: $station_name)"
    );
} catch (Exception $e) { /* ignore */ }

// Helper function for normalized roles
function get_export_role_label($role) {
    $r = strtolower(trim($role));
    if ($r === 'superadmin') return 'Super Admin';
    if ($r === 'admin') return 'Admin/Owner';
    if ($r === 'manager') return 'Manager';
    if ($r === 'staff') return 'Staff';
    return ucfirst($r);
}

// Prepare Consolidated Master Data Rows (All 15 Required Columns)
$master_rows = [];
foreach ($employees as $emp) {
    $uid       = $emp['id'];
    $emp_id    = $emp['employee_id'] ?: ('EMP-' . str_pad($uid, 4, '0', STR_PAD_LEFT));
    $name      = trim($emp['full_name']) ?: $emp['username'];
    $role_name = get_export_role_label($emp['role']);
    $stn_name  = $emp['station_name'] ?: $station_name;
    $status    = ucfirst(strtolower(trim($emp['status'] ?: 'Active')));
    $created   = $emp['created_at'] ? date('Y-m-d', strtotime($emp['created_at'])) : '—';
    $last_login= $emp['updated_at'] ? date('Y-m-d H:i:s', strtotime($emp['updated_at'])) : '—';
    
    // Document statuses (Dynamic database check: missing if no file uploaded)
    $get_doc_status = function($uid, $type) use ($docs_map) {
        if (!isset($docs_map[$uid][$type])) return 'Missing';
        $item = $docs_map[$uid][$type];
        if (empty($item['file_name']) && strtolower($item['status']) === 'complete') return 'Missing';
        return $item['status'] ?: 'Missing';
    };

    $sss        = $get_doc_status($uid, 'SSS');
    $philhealth = $get_doc_status($uid, 'PhilHealth');
    $pagibig    = $get_doc_status($uid, 'Pag-IBIG');
    $tin        = $get_doc_status($uid, 'TIN');
    $valid_id   = $get_doc_status($uid, 'Valid ID');
    
    // Activity summary
    $last_act   = !empty($activity_map[$uid]['last_act']) ? date('Y-m-d H:i:s', strtotime($activity_map[$uid]['last_act'])) : '—';
    $act_count  = isset($activity_map[$uid]['count']) ? (int)$activity_map[$uid]['count'] : 0;
    
    $master_rows[] = [
        'emp_id'       => $emp_id,
        'full_name'    => $name,
        'username'     => '@' . $emp['username'],
        'role'         => $role_name,
        'station'      => $stn_name,
        'status'       => $status,
        'created_at'   => $created,
        'last_login'   => $last_login,
        'sss'          => $sss,
        'philhealth'   => $philhealth,
        'pagibig'      => $pagibig,
        'tin'          => $tin,
        'valid_id'     => $valid_id,
        'last_activity'=> $last_act,
        'act_count'    => $act_count
    ];
}

// ── 1. DIRECT EXCEL (.XLS) HANDLER ──────────────────────────────────────────
if ($format === 'excel') {
    $filename = "Petron_User_Management_Report_{$today}.xls";
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<style>
    body { font-family: Arial, sans-serif; font-size: 11px; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 1px solid #000000; padding: 6px 10px; text-align: left; }
    th { background-color: #00264D; color: #ffffff; font-weight: bold; text-align: center; }
    .hdr { font-size: 14px; font-weight: bold; color: #00264D; }
</style>
</head>
<body>
<table>
    <tr><td colspan="15" class="hdr">PETRON STATION MANAGEMENT SYSTEM</td></tr>
    <tr><td colspan="15" class="hdr">CONSOLIDATED EMPLOYEE MASTER REPORT</td></tr>
    <tr><td colspan="3"><b>Station/Branch:</b></td><td colspan="12">' . htmlspecialchars($station_name) . '</td></tr>
    <tr><td colspan="3"><b>Generated By:</b></td><td colspan="12">' . htmlspecialchars(trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: $me['username']) . '</td></tr>
    <tr><td colspan="3"><b>Generated Date:</b></td><td colspan="12">' . htmlspecialchars($now_formatted) . '</td></tr>
    <tr><td colspan="3"><b>Total Employees:</b></td><td colspan="12">' . $record_count . '</td></tr>
    <tr><td colspan="15"></td></tr>
    <tr>
        <th>Employee ID</th>
        <th>Full Name</th>
        <th>Username</th>
        <th>Role</th>
        <th>Branch/Station</th>
        <th>Account Status</th>
        <th>Date Created</th>
        <th>Last Login</th>
        <th>SSS Status</th>
        <th>PhilHealth Status</th>
        <th>Pag-IBIG Status</th>
        <th>TIN Status</th>
        <th>Valid ID Status</th>
        <th>Last Activity</th>
        <th>Activity Count</th>
    </tr>';

    foreach ($master_rows as $row) {
        echo '<tr>
            <td>' . htmlspecialchars($row['emp_id']) . '</td>
            <td>' . htmlspecialchars($row['full_name']) . '</td>
            <td>' . htmlspecialchars($row['username']) . '</td>
            <td>' . htmlspecialchars($row['role']) . '</td>
            <td>' . htmlspecialchars($row['station']) . '</td>
            <td>' . htmlspecialchars($row['status']) . '</td>
            <td>' . htmlspecialchars($row['created_at']) . '</td>
            <td>' . htmlspecialchars($row['last_login']) . '</td>
            <td>' . htmlspecialchars($row['sss']) . '</td>
            <td>' . htmlspecialchars($row['philhealth']) . '</td>
            <td>' . htmlspecialchars($row['pagibig']) . '</td>
            <td>' . htmlspecialchars($row['tin']) . '</td>
            <td>' . htmlspecialchars($row['valid_id']) . '</td>
            <td>' . htmlspecialchars($row['last_activity']) . '</td>
            <td>' . $row['act_count'] . '</td>
        </tr>';
    }

    echo '</table></body></html>';
    exit;
}

// ── 2. DIRECT CSV HANDLER ──────────────────────────────────────────────────
if ($format === 'csv') {
    $filename = "Petron_User_Management_Report_{$today}.csv";
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, ['PETRON STATION MANAGEMENT SYSTEM']);
    fputcsv($output, ['CONSOLIDATED EMPLOYEE MASTER REPORT']);
    fputcsv($output, ['Station/Branch:', $station_name]);
    fputcsv($output, ['Generated By:', trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: $me['username']]);
    fputcsv($output, ['Generated Date:', $now_formatted]);
    fputcsv($output, ['Total Employees:', $record_count]);
    fputcsv($output, []);
    
    fputcsv($output, [
        'Employee ID',
        'Full Name',
        'Username',
        'Role',
        'Branch/Station',
        'Account Status',
        'Date Created',
        'Last Login',
        'SSS Status',
        'PhilHealth Status',
        'Pag-IBIG Status',
        'TIN Status',
        'Valid ID Status',
        'Last Activity',
        'Activity Count'
    ]);
    
    foreach ($master_rows as $row) {
        fputcsv($output, [
            $row['emp_id'],
            $row['full_name'],
            $row['username'],
            $row['role'],
            $row['station'],
            $row['status'],
            $row['created_at'],
            $row['last_login'],
            $row['sss'],
            $row['philhealth'],
            $row['pagibig'],
            $row['tin'],
            $row['valid_id'],
            $row['last_activity'],
            $row['act_count']
        ]);
    }
    
    fclose($output);
    exit;
}

// ── 2. PDF & PRINT HANDLERS ─────────────────────────────────────────────────
$admin_name = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: $me['username'];
$is_print_mode = ($format === 'print');

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>USER MANAGEMENT REPORT - Petron Station Management System</title>
<style>
    body { font-family: dejavusans, sans-serif; font-size: 9px; color: #1e293b; margin: 0; padding: 0; }
    .hdr-box { text-align: center; margin-bottom: 12px; border-bottom: 2px solid #00264D; padding-bottom: 8px; }
    .hdr-box h2 { font-size: 15px; font-weight: bold; color: #00264D; text-transform: uppercase; margin: 0 0 2px 0; letter-spacing: 0.5px; }
    .hdr-box h4 { font-size: 12px; font-weight: bold; color: #00264D; text-transform: uppercase; margin: 0 0 2px 0; }
    .hdr-box p { font-size: 10px; color: #475569; margin: 0; font-weight: bold; }
    
    .meta-tbl { width: 100%; margin-bottom: 10px; background: #f8fafc; border-collapse: collapse; }
    .meta-tbl td { padding: 4px 6px; font-size: 9.5px; border: 1px solid #cbd5e1; }
    .meta-lbl { font-weight: bold; color: #00264D; background: #f1f5f9; width: 110px; }
    
    .data-tbl { width: 100%; border-collapse: collapse; margin-top: 6px; }
    .data-tbl th { background: #00264D; color: #ffffff; padding: 6px 4px; font-size: 8.5px; text-transform: uppercase; font-weight: bold; text-align: left; border: 1px solid #00264D; white-space: nowrap; }
    .data-tbl td { padding: 5px 4px; font-size: 8.5px; border: 1px solid #cbd5e1; vertical-align: middle; }
    .data-tbl tr:nth-child(even) { background: #f8fafc; }
    
    .st-act { color: #15803d; font-weight: bold; }
    .st-inact { color: #b91c1c; font-weight: bold; }
    .st-ok { color: #16a34a; font-weight: bold; }
    .st-miss { color: #dc2626; font-weight: bold; }
    
    @media print {
        @page { size: A4 landscape; margin: 8mm; }
        body { padding: 0; }
    }
</style>
</head>
<body>

<div class="hdr-box">
    <h2>PETRON STATION MANAGEMENT SYSTEM</h2>
    <h4>USER MANAGEMENT REPORT</h4>
    <p><?php echo htmlspecialchars($station_name); ?></p>
</div>

<table class="meta-tbl">
    <tr>
        <td class="meta-lbl">Station / Branch:</td>
        <td><strong><?php echo htmlspecialchars($station_name); ?></strong></td>
        <td class="meta-lbl">Generated By:</td>
        <td><strong><?php echo htmlspecialchars($admin_name); ?> (<?php echo htmlspecialchars(get_export_role_label($me['role'])); ?>)</strong></td>
    </tr>
    <tr>
        <td class="meta-lbl">Generated Date:</td>
        <td><?php echo htmlspecialchars($now_formatted); ?></td>
        <td class="meta-lbl">Total Employees:</td>
        <td><strong><?php echo $record_count; ?> Employee(s) Listed</strong></td>
    </tr>
</table>

<table class="data-tbl">
    <thead>
        <tr>
            <th>EMP ID</th>
            <th>FULL NAME</th>
            <th>USERNAME</th>
            <th>ROLE</th>
            <th>BRANCH</th>
            <th>STATUS</th>
            <th>CREATED</th>
            <th>LAST LOGIN</th>
            <th>SSS</th>
            <th>PHILHEALTH</th>
            <th>PAG-IBIG</th>
            <th>TIN</th>
            <th>VALID ID</th>
            <th>LAST ACTIVITY</th>
            <th>COUNT</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($master_rows as $row): 
            $is_act = (strtolower($row['status']) === 'active');
        ?>
        <tr>
            <td><strong><?php echo htmlspecialchars($row['emp_id']); ?></strong></td>
            <td><strong><?php echo htmlspecialchars($row['full_name']); ?></strong></td>
            <td style="color: #475569;"><?php echo htmlspecialchars($row['username']); ?></td>
            <td><?php echo htmlspecialchars($row['role']); ?></td>
            <td><?php echo htmlspecialchars($row['station']); ?></td>
            <td><span class="<?php echo $is_act ? 'st-act' : 'st-inact'; ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
            <td><?php echo htmlspecialchars($row['created_at']); ?></td>
            <td><span style="font-size: 8px; color: #475569;"><?php echo htmlspecialchars($row['last_login']); ?></span></td>
            <td><span class="<?php echo strtolower($row['sss']) === 'complete' ? 'st-ok' : 'st-miss'; ?>"><?php echo htmlspecialchars($row['sss']); ?></span></td>
            <td><span class="<?php echo strtolower($row['philhealth']) === 'complete' ? 'st-ok' : 'st-miss'; ?>"><?php echo htmlspecialchars($row['philhealth']); ?></span></td>
            <td><span class="<?php echo strtolower($row['pagibig']) === 'complete' ? 'st-ok' : 'st-miss'; ?>"><?php echo htmlspecialchars($row['pagibig']); ?></span></td>
            <td><span class="<?php echo strtolower($row['tin']) === 'complete' ? 'st-ok' : 'st-miss'; ?>"><?php echo htmlspecialchars($row['tin']); ?></span></td>
            <td><span class="<?php echo strtolower($row['valid_id']) === 'complete' ? 'st-ok' : 'st-miss'; ?>"><?php echo htmlspecialchars($row['valid_id']); ?></span></td>
            <td><span style="font-size: 8px; color: #475569;"><?php echo htmlspecialchars($row['last_activity']); ?></span></td>
            <td style="text-align: center; font-weight: bold; color: #00264D;"><?php echo $row['act_count']; ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($master_rows)): ?>
        <tr>
            <td colspan="15" style="text-align: center; padding: 15px; color: #94a3b8;">No employee records found.</td>
        </tr>
        <?php endif; ?>
    </tbody>
</table>

<table style="width: 100%; margin-top: 25px; border-collapse: collapse; page-break-inside: avoid;">
    <tr>
        <td style="width: 65%;"></td>
        <td style="width: 35%; text-align: center; vertical-align: bottom;">
            <div style="font-size: 10px; font-weight: bold; color: #00264D; text-transform: uppercase; text-align: left; margin-bottom: 30px;">PREPARED BY:</div>
            <div style="border-top: 1.5px solid #00264D; width: 100%; margin-bottom: 3px;"></div>
            <div style="font-size: 10px; font-weight: bold; color: #1e293b; text-transform: uppercase;"><?php echo htmlspecialchars($admin_name); ?></div>
            <div style="font-size: 9px; color: #475569; font-weight: bold; margin-top: 1px;"><?php echo htmlspecialchars(get_export_role_label($me['role'])); ?></div>
            <div style="font-size: 8px; color: #64748b; margin-top: 2px;">Signature over Printed Name</div>
        </td>
    </tr>
</table>

</body>
</html>
<?php
$html = ob_get_clean();

if ($is_print_mode) {
    $print_script = "<script>window.onload = function() { window.focus(); window.print(); }; window.onafterprint = function() { try { window.close(); } catch(e) {} };</script>";
    $html = str_replace("</body>", $print_script . "</body>", $html);
    header("Content-Type: text/html; charset=utf-8");
    echo $html;
    exit;
}

try {
    $temp_dir = __DIR__ . '/../scratch';
    if (!is_dir($temp_dir)) {
        @mkdir($temp_dir, 0777, true);
    }
    if (!is_writable($temp_dir)) {
        $temp_dir = sys_get_temp_dir();
    }

    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4-L',
        'margin_left' => 8,
        'margin_right' => 8,
        'margin_top' => 8,
        'margin_bottom' => 10,
        'tempDir' => $temp_dir,
        'autoScriptToLang' => true,
        'autoLangToFont' => true
    ]);
    
    $mpdf->SetTitle('User Management Report - ' . $station_name);
    $mpdf->SetAuthor('Petron Station Management System');
    $mpdf->WriteHTML($html);
    
    $ts_now = date('Y-m-d_His');
    $pdf_filename = "Petron_User_Management_Report_{$ts_now}.pdf";
    
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $pdf_filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    
    $mpdf->Output($pdf_filename, 'I');
} catch (\Throwable $e) {
    header('Content-Type: text/html; charset=utf-8');
    $print_script = "<script>window.onload = function() { window.focus(); window.print(); };</script>";
    $html = str_replace("</body>", $print_script . "</body>", $html);
    echo $html;
}
exit;