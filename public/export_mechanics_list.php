<?php
/**
 * Official Mechanics Management Report Exporter
 * Petron Station Management System
 */
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../vendor/autoload.php';

require_login();

$me         = current_user();
$my_role    = role_key($me['role'] ?? 'staff');
$my_station_id = user_station_id();

// Access Control: Only Manager, Supervisor, Admin, and Superadmin can export mechanics list
if (!in_array($my_role, ['manager', 'supervisor', 'admin', 'superadmin'], true)) {
    http_response_code(403);
    exit('Unauthorized access.');
}

$format = strtolower(trim($_GET['format'] ?? 'excel'));
$today  = date('Y-m-d');
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

// Filter Parameters for Export
$filter_q      = strtolower(trim($_GET['q'] ?? ''));
$filter_status = strtolower(trim($_GET['status'] ?? ''));
$filter_spec   = strtolower(trim($_GET['specialty'] ?? ''));
$filter_shift  = strtolower(trim($_GET['shift'] ?? ''));

// Fetch Mechanics
$and_st = ($my_role !== 'superadmin' && $my_station_id > 0) ? "WHERE m.station_id = ? AND m.archived = 0" : "WHERE m.archived = 0";
$params = ($my_role !== 'superadmin' && $my_station_id > 0) ? [$my_station_id] : [];

$stmt = $pdo->prepare("
    SELECT m.*, s.name AS station_name
    FROM mechanics m
    LEFT JOIN stations s ON m.station_id = s.id
    {$and_st}
    ORDER BY m.status ASC, m.id DESC
");
$stmt->execute($params);
$mechanics = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Apply Filters
$filtered_list = [];
foreach ($mechanics as $m) {
    $mech_id   = strtolower(sprintf("mec-%04d", $m['id']));
    $fname     = strtolower(trim($m['first_name'] ?? ''));
    $lname     = strtolower(trim($m['last_name'] ?? ''));
    $fullname  = strtolower(trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')));
    $contact   = strtolower(trim($m['contact_no'] ?? ''));
    $spec      = strtolower(trim($m['specialization'] ?: 'general mechanic'));
    $status    = strtolower(trim($m['status'] ?? 'active'));
    $shift     = strtolower(trim($m['shift_assignment'] ?? 'all shifts'));

    // Status Filter Check
    if ($filter_status !== '' && $status !== $filter_status) {
        continue;
    }

    // Specialty Filter Check
    if ($filter_spec !== '' && $spec !== $filter_spec) {
        continue;
    }

    // Shift Filter Check
    if ($filter_shift !== '' && $filter_shift !== 'all shifts') {
        if ($shift !== $filter_shift && $shift !== 'all shifts' && $shift !== '') {
            continue;
        }
    }

    // Search Query Check
    if ($filter_q !== '') {
        $match = (strpos($mech_id, $filter_q) !== false) ||
                 (strpos($fname, $filter_q) !== false) ||
                 (strpos($lname, $filter_q) !== false) ||
                 (strpos($fullname, $filter_q) !== false) ||
                 (strpos($contact, $filter_q) !== false) ||
                 (strpos($spec, $filter_q) !== false);
        if (!$match) {
            continue;
        }
    }

    $filtered_list[] = $m;
}
$mechanics = $filtered_list;
$record_count = count($mechanics);

// Audit Log for Export Action
try {
    log_activity(
        $pdo,
        $me['id'],
        'Exported Mechanics Report',
        "Exported $record_count mechanic records (Format: " . strtoupper($format) . ", Station: $station_name)"
    );
} catch (Exception $e) { /* ignore */ }

// Prepare Row Data for Output (Exact 8 Columns requested by User)
$mechanic_rows = [];
foreach ($mechanics as $m) {
    $mech_id     = sprintf("MEC-%04d", $m['id']);
    $first_name  = trim($m['first_name'] ?? '') ?: '—';
    $last_name   = trim($m['last_name'] ?? '') ?: '—';
    $contact_no  = trim($m['contact_no'] ?? '') ?: '—';
    $specialty   = trim($m['specialization'] ?? '') ?: 'General Mechanic';
    $status      = ucfirst(strtolower(trim($m['status'] ?? 'Active')));
    $date_added  = !empty($m['created_at']) ? date('Y-m-d H:i', strtotime($m['created_at'])) : '—';
    $date_updated= !empty($m['updated_at']) ? date('Y-m-d H:i', strtotime($m['updated_at'])) : '—';

    $mechanic_rows[] = [
        'mechanic_id' => $mech_id,
        'first_name'  => $first_name,
        'last_name'   => $last_name,
        'contact_no'  => $contact_no,
        'specialty'   => $specialty,
        'status'      => $status,
        'date_added'  => $date_added,
        'date_updated'=> $date_updated
    ];
}

// ── 1. DIRECT EXCEL (.XLS) HANDLER ──────────────────────────────────────────
if ($format === 'excel') {
    $filename = "Petron_Mechanics_Report_{$today}.xls";
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
    .hdr-title { font-size: 16px; font-weight: bold; color: #00264D; text-align: center; text-transform: uppercase; border: none; }
    .hdr-station { font-size: 12px; font-weight: bold; color: #00264D; text-align: center; border: none; }
    .hdr-date { font-size: 11px; color: #475569; text-align: center; border: none; }
</style>
</head>
<body>
<table>
    <tr><td colspan="8" align="center" class="hdr-title" style="text-align: center; font-size: 16px; font-weight: bold; color: #00264D; border: none; padding: 6px 0;">MECHANICS MANAGEMENT REPORT</td></tr>
    <tr><td colspan="8" align="center" class="hdr-station" style="text-align: center; font-size: 12px; font-weight: bold; color: #00264D; border: none; padding: 3px 0;">' . htmlspecialchars($station_name) . '</td></tr>
    <tr><td colspan="8" align="center" class="hdr-date" style="text-align: center; font-size: 11px; color: #475569; border: none; padding: 3px 0 8px 0;">Date: ' . htmlspecialchars($now_formatted) . '</td></tr>
    <tr><td colspan="8" style="border: none;"></td></tr>
    <tr>
        <th>Mechanic ID</th>
        <th>First Name</th>
        <th>Last Name</th>
        <th>Contact No.</th>
        <th>Specialty</th>
        <th>Status</th>
        <th>Date Added</th>
        <th>Date Updated</th>
    </tr>';

    foreach ($mechanic_rows as $row) {
        echo '<tr>
            <td>' . htmlspecialchars($row['mechanic_id']) . '</td>
            <td>' . htmlspecialchars($row['first_name']) . '</td>
            <td>' . htmlspecialchars($row['last_name']) . '</td>
            <td>' . htmlspecialchars($row['contact_no']) . '</td>
            <td>' . htmlspecialchars($row['specialty']) . '</td>
            <td>' . htmlspecialchars($row['status']) . '</td>
            <td>' . htmlspecialchars($row['date_added']) . '</td>
            <td>' . htmlspecialchars($row['date_updated']) . '</td>
        </tr>';
    }

    echo '</table></body></html>';
    exit;
}

// ── 2. DIRECT CSV HANDLER ──────────────────────────────────────────────────
if ($format === 'csv') {
    $filename = "Petron_Mechanics_Report_{$today}.csv";
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, ['', '', '', 'MECHANICS MANAGEMENT REPORT']);
    fputcsv($output, ['', '', '', $station_name]);
    fputcsv($output, ['', '', '', 'Date: ' . $now_formatted]);
    fputcsv($output, []);
    
    fputcsv($output, [
        'Mechanic ID',
        'First Name',
        'Last Name',
        'Contact No.',
        'Specialty',
        'Status',
        'Date Added',
        'Date Updated'
    ]);
    
    foreach ($mechanic_rows as $row) {
        fputcsv($output, [
            $row['mechanic_id'],
            $row['first_name'],
            $row['last_name'],
            $row['contact_no'],
            $row['specialty'],
            $row['status'],
            $row['date_added'],
            $row['date_updated']
        ]);
    }
    
    fclose($output);
    exit;
}

// ── 3. PDF & PRINT HANDLERS ─────────────────────────────────────────────────
$admin_name = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: $me['username'];
$is_print_mode = ($format === 'print');

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>MECHANICS MANAGEMENT REPORT - Petron Station Management System</title>
<style>
    <?php if ($is_print_mode): ?>
    @page { size: A4 landscape; margin: 6mm; }
    body { font-family: Arial, sans-serif; font-size: 8px; color: #1e293b; margin: 0; padding: 0; background: #525659; display: flex; justify-content: center; }
    .rpt-paper-sheet { background: #ffffff; width: 100%; max-width: 1050px; margin: 25px auto; padding: 35px 40px; box-shadow: 0 4px 20px rgba(0,0,0,0.4); border-radius: 4px; box-sizing: border-box; }
    @media print {
        body { background: #ffffff !important; padding: 0 !important; margin: 0 !important; display: block !important; }
        .rpt-paper-sheet { box-shadow: none !important; padding: 0 !important; width: 100% !important; max-width: none !important; margin: 0 !important; border-radius: 0 !important; }
    }
    <?php else: ?>
    body { font-family: dejavusans, Arial, sans-serif; font-size: 8px; color: #1e293b; margin: 0; padding: 0; }
    .rpt-paper-sheet { width: 100%; }
    <?php endif; ?>
    .hdr-box { text-align: center; margin-bottom: 12px; border-bottom: 2px solid #00264D; padding-bottom: 6px; }
    .hdr-box h2 { font-size: 14px; font-weight: bold; color: #00264D; text-transform: uppercase; margin: 0 0 2px 0; letter-spacing: 0.5px; }
    .hdr-box p { font-size: 9px; color: #475569; margin: 0 0 2px 0; font-weight: bold; }
    
    .data-tbl { width: 100%; border-collapse: collapse; margin-top: 4px; table-layout: fixed; }
    .data-tbl th { background: #00264D; color: #ffffff; padding: 5px 2px; font-size: 7.5pt; text-transform: uppercase; font-weight: bold; text-align: center; border: 1px solid #00264D; word-wrap: break-word; overflow-wrap: break-word; }
    .data-tbl td { padding: 4px 2px; font-size: 7.5pt; border: 1px solid #cbd5e1; vertical-align: middle; word-wrap: break-word; overflow-wrap: break-word; text-align: center; }
    .data-tbl td.left { text-align: left; }
    .data-tbl tr:nth-child(even) td { background: #f8fafc; }
    
    .st-act { color: #15803d; font-weight: bold; }
    .st-inact { color: #b91c1c; font-weight: bold; }
</style>
</head>
<body>

<div class="rpt-paper-sheet">
<div class="hdr-box">
    <h2>MECHANICS MANAGEMENT REPORT</h2>
    <p><?php echo htmlspecialchars($station_name); ?></p>
    <p style="font-weight: normal; color: #64748b; font-size: 8.5px;">Date: <?php echo htmlspecialchars($now_formatted); ?></p>
</div>

<table class="data-tbl">
    <thead>
        <tr>
            <th style="width: 12%;">MECHANIC ID</th>
            <th style="width: 15%;">FIRST NAME</th>
            <th style="width: 15%;">LAST NAME</th>
            <th style="width: 14%;">CONTACT NO.</th>
            <th style="width: 16%;">SPECIALTY</th>
            <th style="width: 8%;">STATUS</th>
            <th style="width: 10%;">DATE ADDED</th>
            <th style="width: 10%;">DATE UPDATED</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($mechanic_rows as $row): 
            $is_act = (strtolower($row['status']) === 'active');
        ?>
        <tr>
            <td><strong><?php echo htmlspecialchars($row['mechanic_id']); ?></strong></td>
            <td class="left"><strong><?php echo htmlspecialchars($row['first_name']); ?></strong></td>
            <td class="left"><strong><?php echo htmlspecialchars($row['last_name']); ?></strong></td>
            <td><?php echo htmlspecialchars($row['contact_no']); ?></td>
            <td><?php echo htmlspecialchars($row['specialty']); ?></td>
            <td><span class="<?php echo $is_act ? 'st-act' : 'st-inact'; ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
            <td><?php echo htmlspecialchars($row['date_added']); ?></td>
            <td><?php echo htmlspecialchars($row['date_updated']); ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($mechanic_rows)): ?>
        <tr>
            <td colspan="8" style="text-align: center; padding: 15px; color: #94a3b8;">No mechanic records found.</td>
        </tr>
        <?php endif; ?>
    </tbody>
</table>

<table style="width: 100%; margin-top: 25px; border-collapse: collapse; page-break-inside: avoid;">
    <tr>
        <td style="width: 60%;"></td>
        <td style="width: 40%; vertical-align: bottom;">
            <div style="font-size: 10px; font-weight: bold; color: #00264D; text-transform: uppercase; text-align: left; margin-bottom: 30px;">PREPARED BY:</div>
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="border-top: 1.5px solid #00264D; padding-top: 4px; text-align: center;">
                        <div style="font-size: 10px; font-weight: bold; color: #1e293b; text-transform: uppercase;"><?php echo htmlspecialchars($admin_name); ?></div>
                        <div style="font-size: 9px; color: #475569; font-weight: bold; margin-top: 1px;"><?php echo htmlspecialchars(ucfirst($my_role)); ?></div>
                        <div style="font-size: 8px; color: #64748b; margin-top: 2px;">Signature over Printed Name</div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</div>

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
    
    $mpdf->SetTitle('Mechanics Management Report - ' . $station_name);
    $mpdf->SetAuthor('Petron Station Management System');
    $mpdf->WriteHTML($html);
    
    $ts_now = date('Y-m-d_His');
    $pdf_filename = "Petron_Mechanics_Report_{$ts_now}.pdf";
    
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $pdf_filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    
    $mpdf->Output($pdf_filename, 'D');
} catch (\Throwable $e) {
    header('Content-Type: text/html; charset=utf-8');
    $print_script = "<script>window.onload = function() { window.focus(); window.print(); };</script>";
    $html = str_replace("</body>", $print_script . "</body>", $html);
    echo $html;
}
exit;
