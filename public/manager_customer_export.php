<?php
/**
 * Manager Customer Export — PDF / Excel / CSV
 * All queries verified against live DB schema
 */

ob_start();
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../backend/customer_module_helpers.php';
ob_end_clean();

require_login();
$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();

customer_ensure_optional_columns($pdo);

if (!in_array($role, ['manager', 'admin', 'superadmin', 'developer'])) {
    die('Unauthorized');
}
if (!customer_can_view_all_stations($role) && $station_id <= 0) {
    die('Error: You are not assigned to a station.');
}
$format      = strtolower(trim($_GET['format'] ?? 'excel'));
$search      = trim($_GET['search'] ?? '');
$type        = trim($_GET['type'] ?? '');
$status      = trim($_GET['status'] ?? '');
$verification= trim($_GET['verification'] ?? '');
$payment     = trim($_GET['payment'] ?? '');
$dateFrom    = trim($_GET['date_from'] ?? '');
$dateTo      = trim($_GET['date_to'] ?? '');
$profileId   = (int)($_GET['profile_id'] ?? 0);

if ($format === 'pdf' || $profileId > 0) {
    header('Content-Type: text/html; charset=utf-8');
}


// Station name
$station_name = '';
try {
    $sn = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
    $sn->execute([$station_id]);
    $station_name = $sn->fetchColumn() ?: 'Petron Station';
} catch (Exception $e) {}

// ─── Single profile print ──────────────────────────────────────────
if ($profileId > 0) {
    exportProfilePdf($profileId, $station_name);
    exit;
}

// ─── Build customer list query ────────────────────────────────────
$where  = [];
$params = [];
customer_apply_station_scope($where, $params, 'c', $role, $station_id);

$customerIdExpr = customer_id_expr($pdo, 'c');
$displayNameExpr = customer_display_name_expr($pdo, 'c');
$firstNameExpr = customer_first_name_expr($pdo, 'c');
$middleNameExpr = customer_middle_name_expr($pdo, 'c');
$lastNameExpr = customer_last_name_expr($pdo, 'c');
$contactExpr = customer_contact_expr($pdo, 'c');
$typeExpr = customer_type_expr($pdo, 'c');
$statusExpr = customer_status_expr($pdo, 'c');
$registeredExpr = customer_registered_at_expr($pdo, 'c');
$balanceExpr = customer_balance_expr($pdo, 'c');
$creditLimitExpr = customer_credit_limit_expr($pdo, 'c');
$verificationExpr = customer_verification_status_expr($pdo, 'c');

if ($search !== '') {
    $where[] = "($customerIdExpr LIKE ? OR $displayNameExpr LIKE ? OR $contactExpr LIKE ?)";
    $s = "%$search%";
    array_push($params, $s, $s, $s);
}
if ($type !== '' && $type !== 'registered') { $type = ''; }
if ($status !== '')       { $where[] = "$statusExpr = ?";       $params[] = $status; }
if ($verification !== '') { $where[] = "$verificationExpr = ?"; $params[] = $verification; }

$whereClause = $where ? implode(' AND ', $where) : '1=1';

$stmt = $pdo->prepare("
    SELECT c.id, c.station_id,
           $customerIdExpr AS customer_id,
           $displayNameExpr AS display_name,
           $firstNameExpr AS first_name,
           $middleNameExpr AS middle_name,
           $lastNameExpr AS last_name,
           $displayNameExpr AS legacy_name,
           $contactExpr AS contact_number,
           $typeExpr AS customer_type,
           $statusExpr AS status,
           $verificationExpr AS verification_status,
           $balanceExpr AS outstanding_balance,
           $creditLimitExpr AS credit_limit,
           " . customer_company_expr($pdo, 'company_name', 'c') . " AS company_name,
           " . customer_company_expr($pdo, 'company_contact_person', 'c') . " AS company_contact_person,
           $registeredExpr AS registered_at
    FROM customers c
    WHERE $whereClause
    ORDER BY $registeredExpr DESC, c.id DESC
");
$stmt->execute($params);
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Apply payment and date filters in PHP
$filteredCustomers = [];
foreach ($customers as $c) {
    $ob = (float)$c['outstanding_balance'];
    $totalSpent = 0;
    $txStation = customer_can_view_all_stations($role) ? (int)($c['station_id'] ?? 0) : $station_id;
    try { $q = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM fuel_transactions WHERE customer_id=? AND station_id=?"); $q->execute([$c['id'], $txStation]); $totalSpent += (float)$q->fetchColumn(); } catch(Exception $e){}
    try { $q = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM merchandise_transactions WHERE customer_id=? AND station_id=?"); $q->execute([$c['id'], $txStation]); $totalSpent += (float)$q->fetchColumn(); } catch(Exception $e){}
    try { $q = $pdo->prepare("SELECT COALESCE(SUM(total_cost),0) FROM job_orders WHERE customer_id=? AND station_id=?"); $q->execute([$c['id'], $txStation]); $totalSpent += (float)$q->fetchColumn(); } catch(Exception $e){}

    if ($ob <= 0)                                          $payStatus = 'paid';
    elseif ($totalSpent > 0 && $ob < $totalSpent)         $payStatus = 'partial';
    else                                                   $payStatus = 'unpaid';

    $c['payment_status'] = $payStatus;
    if ($payment !== '' && $payStatus !== $payment) continue;

    $regDate = date('Y-m-d', strtotime($c['registered_at'] ?? 'now'));
    if ($dateFrom !== '' && $regDate < $dateFrom) continue;
    if ($dateTo   !== '' && $regDate > $dateTo)   continue;

    $filteredCustomers[] = $c;
}

$filename = 'customer_list_' . date('Y-m-d_His');

// ─── CSV ──────────────────────────────────────────────────────────
if ($format === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['Customer ID','Full Name','Registration','Company','Contact No.','Outstanding Balance','Credit Limit','Verification','Payment Status','Status','Registered']);
    foreach ($filteredCustomers as $c) {
        fputcsv($out, [
            $c['customer_id'], $c['display_name'], 'Registered',
            $c['company_name'] ?: 'N/A', $c['contact_number'],
            number_format($c['outstanding_balance'], 2, '.', ''),
            number_format($c['credit_limit'], 2, '.', ''),
            ucfirst($c['verification_status']), ucfirst($c['payment_status']),
            ucfirst($c['status']), date('Y-m-d', strtotime($c['registered_at']))
        ]);
    }
    fclose($out);

// ─── EXCEL ────────────────────────────────────────────────────────
} elseif ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="UTF-8">
    <style>th{background:#002F70;color:#fff;font-weight:bold;}td,th{border:1px solid #ccc;}</style>
    </head><body>';
    echo '<h3>Customer Registry — ' . htmlspecialchars($station_name) . '</h3>';
    echo '<p>Generated: ' . date('M d, Y g:i A') . '</p>';
    echo '<table><thead><tr>
        <th>Customer ID</th><th>Full Name</th><th>Registration</th><th>Company</th><th>Contact No.</th>
        <th>Outstanding Balance</th><th>Credit Limit</th><th>Verification</th><th>Payment Status</th><th>Status</th><th>Registered</th>
    </tr></thead><tbody>';
    foreach ($filteredCustomers as $c) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($c['customer_id']) . '</td>';
        echo '<td>' . htmlspecialchars($c['display_name']) . '</td>';
        echo '<td>Registered</td>';
        echo '<td>' . htmlspecialchars($c['company_name'] ?: 'N/A') . '</td>';
        echo '<td>' . htmlspecialchars($c['contact_number']) . '</td>';
        echo '<td>' . number_format($c['outstanding_balance'], 2, '.', '') . '</td>';
        echo '<td>' . number_format($c['credit_limit'], 2, '.', '') . '</td>';
        echo '<td>' . ucfirst($c['verification_status']) . '</td>';
        echo '<td>' . ucfirst($c['payment_status']) . '</td>';
        echo '<td>' . ucfirst($c['status']) . '</td>';
        echo '<td>' . date('Y-m-d', strtotime($c['registered_at'])) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></body></html>';

// ─── PDF (print-ready HTML) ───────────────────────────────────────
} else {
    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html><head><meta charset="utf-8">
<title>Customer Registry — <?php echo htmlspecialchars($station_name); ?></title>
<style>
body{font-family:Arial,sans-serif;font-size:11px;color:#111;margin:20px;}
.hdr{border-bottom:2px solid #002F70;padding-bottom:8px;margin-bottom:14px;display:flex;justify-content:space-between;}
h1{font-size:16px;color:#002F70;margin:0;} .sub{font-size:10px;color:#555;margin-top:3px;}
table{width:100%;border-collapse:collapse;margin-top:8px;}
th{background:#002F70;color:#fff;padding:6px 8px;font-size:10px;text-align:left;border:1px solid #ddd;}
td{padding:5px 8px;border:1px solid #ddd;font-size:10px;}
tr:nth-child(even) td{background:#f8fafc;}
.no-print{margin-bottom:14px;}
.btn{padding:6px 14px;background:#002F70;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:12px;}
@media print{.no-print{display:none;}}
</style>
</head>
<body onload="window.print()">
<div class="no-print">
    <button onclick="window.print()" class="btn">Print / Save as PDF</button>
    <button onclick="history.back()" class="btn" style="background:#64748b;margin-left:8px;">Back</button>
</div>
<div class="hdr">
    <div>
        <h1>CUSTOMER REGISTRY REPORT</h1>
        <div class="sub">Station: <?php echo htmlspecialchars($station_name); ?> &nbsp;|&nbsp; Generated: <?php echo date('M d, Y g:i A'); ?></div>
    </div>
</div>
<table>
<thead><tr>
    <th>Customer ID</th><th>Name</th><th>Registration</th><th>Company</th><th>Contact</th>
    <th>Outstanding</th><th>Credit Limit</th><th>Verification</th><th>Payment</th><th>Status</th><th>Registered</th>
</tr></thead>
<tbody>
<?php if (empty($filteredCustomers)): ?>
    <tr><td colspan="11" style="text-align:center;color:#94a3b8;">No records found.</td></tr>
<?php else: ?>
    <?php foreach ($filteredCustomers as $c): ?>
    <tr>
        <td><strong><?php echo htmlspecialchars($c['customer_id']); ?></strong></td>
        <td><?php echo htmlspecialchars($c['display_name']); ?></td>
        <td>Registered</td>
        <td><?php echo htmlspecialchars($c['company_name'] ?: '—'); ?></td>
        <td><?php echo htmlspecialchars($c['contact_number']); ?></td>
        <td>&#x20B1;<?php echo number_format($c['outstanding_balance'], 2); ?></td>
        <td>&#x20B1;<?php echo number_format($c['credit_limit'], 2); ?></td>
        <td><?php echo ucfirst($c['verification_status']); ?></td>
        <td><?php echo ucfirst($c['payment_status']); ?></td>
        <td><?php echo ucfirst($c['status']); ?></td>
        <td><?php echo date('M d, Y', strtotime($c['registered_at'])); ?></td>
    </tr>
    <?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</body></html>
<?php }

// Audit
try { write_audit_log($pdo, 'Export', "Exported customer list ($format)", 'customers', 0, 'report'); } catch(Exception $e){}

// ─── Profile PDF helper ───────────────────────────────────────────
function exportProfilePdf($id, $station_name) {
    global $pdo, $station_id, $role;

    $customerIdExpr = customer_id_expr($pdo, 'c');
    $displayNameExpr = customer_display_name_expr($pdo, 'c');
    $contactExpr = customer_contact_expr($pdo, 'c');
    $typeExpr = customer_type_expr($pdo, 'c');
    $statusExpr = customer_status_expr($pdo, 'c');
    $registeredExpr = customer_registered_at_expr($pdo, 'c');
    $balanceExpr = customer_balance_expr($pdo, 'c');
    $creditLimitExpr = customer_credit_limit_expr($pdo, 'c');
    $verificationExpr = customer_verification_status_expr($pdo, 'c');
    $govIdTypeExpr = customer_gov_id_type_expr($pdo, 'c');
    $where = ['c.id = ?'];
    $params = [$id];
    customer_apply_station_scope($where, $params, 'c', $role, $station_id);

    $stmt = $pdo->prepare("
        SELECT c.*,
               $customerIdExpr AS customer_id,
               $displayNameExpr AS display_name,
               $contactExpr AS contact_number,
               $typeExpr AS customer_type,
               $statusExpr AS status,
               $registeredExpr AS registered_at,
               $balanceExpr AS outstanding_balance,
               $creditLimitExpr AS credit_limit,
               $verificationExpr AS verification_status,
               $govIdTypeExpr AS gov_id_type,
               " . customer_company_expr($pdo, 'company_name', 'c') . " AS company_name,
               " . customer_company_expr($pdo, 'company_address', 'c') . " AS company_address,
               " . customer_company_expr($pdo, 'company_contact_person', 'c') . " AS company_contact_person,
               " . customer_company_expr($pdo, 'company_contact_number', 'c') . " AS company_contact_number,
               " . customer_verification_remarks_expr($pdo, 'c') . " AS verification_remarks,
               " . customer_user_name_expr('u') . " AS registered_by_name,
               " . customer_user_name_expr('v') . " AS verified_by_name
        FROM customers c
        LEFT JOIN users u ON c.registered_by = u.id
        LEFT JOIN users v ON c.verified_by   = v.id
        WHERE " . implode(' AND ', $where) . "
    ");
    $stmt->execute($params);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$c) { http_response_code(404); echo 'Customer not found'; return; }

    $fullName = trim(implode(' ', array_filter([$c['first_name'], $c['middle_name'], $c['last_name']])) ?: $c['name']);

    // Fetch transaction totals
    $fuelAmt = $merchAmt = $jobAmt = 0;
    $txStation = customer_can_view_all_stations($role) ? (int)($c['station_id'] ?? 0) : $station_id;
    try { $q = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM fuel_transactions WHERE customer_id=? AND station_id=?"); $q->execute([$id, $txStation]); $fuelAmt = (float)$q->fetchColumn(); } catch(Exception $e){}
    try { $q = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM merchandise_transactions WHERE customer_id=? AND station_id=?"); $q->execute([$id, $txStation]); $merchAmt = (float)$q->fetchColumn(); } catch(Exception $e){}
    try { $q = $pdo->prepare("SELECT COALESCE(SUM(total_cost),0) FROM job_orders WHERE customer_id=? AND station_id=?"); $q->execute([$id, $txStation]); $jobAmt = (float)$q->fetchColumn(); } catch(Exception $e){}
    $totalPurchased = $fuelAmt + $merchAmt + $jobAmt;
    $ob = (float)($c['outstanding_balance'] ?? 0);

    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html><head><meta charset="utf-8">
<title>Customer Profile — <?php echo htmlspecialchars($fullName); ?></title>
<style>
body{font-family:Arial,sans-serif;font-size:11px;color:#111;margin:20px;}
.hdr{background:#002F70;color:#fff;padding:16px 20px;border-radius:6px;margin-bottom:16px;}
.hdr h1{margin:0;font-size:16px;} .hdr .sub{font-size:10px;opacity:.8;margin-top:3px;}
.section{margin-bottom:14px;} h2{font-size:12px;color:#002F70;border-bottom:1px solid #002F70;padding-bottom:4px;margin-bottom:8px;}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
table{width:100%;border-collapse:collapse;}
th{background:#f1f5f9;padding:5px 8px;font-size:10px;text-align:left;border:1px solid #ddd;}
td{padding:5px 8px;border:1px solid #ddd;font-size:10px;}
.footer{margin-top:20px;border-top:1px solid #ddd;padding-top:8px;font-size:9px;color:#666;}
.no-print{margin-bottom:12px;}
.btn{padding:5px 12px;background:#002F70;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:11px;}
@media print{.no-print{display:none;}}
</style>
</head>
<body onload="window.print()">
<div class="no-print">
    <button onclick="window.print()" class="btn">Print Profile</button>
    <button onclick="history.back()" class="btn" style="background:#64748b;margin-left:8px;">Back</button>
</div>
<div class="hdr">
    <h1>CUSTOMER PROFILE — <?php echo htmlspecialchars($fullName); ?></h1>
    <div class="sub"><?php echo htmlspecialchars($c['customer_id']); ?> &nbsp;|&nbsp; <?php echo htmlspecialchars($station_name); ?> &nbsp;|&nbsp; Generated: <?php echo date('M d, Y g:i A'); ?></div>
</div>
<div class="grid">
<div class="section">
<h2>Customer Information</h2>
<table>
<tr><th>Customer ID</th><td><?php echo htmlspecialchars($c['customer_id']); ?></td></tr>
<tr><th>Full Name</th><td><?php echo htmlspecialchars($fullName); ?></td></tr>
<tr><th>Contact Number</th><td><?php echo htmlspecialchars($c['contact_number']); ?></td></tr>
<tr><th>Address</th><td><?php echo htmlspecialchars($c['address'] ?? '—'); ?></td></tr>
<tr><th>Registration</th><td>Registered</td></tr>
<tr><th>Date Registered</th><td><?php echo date('M d, Y', strtotime($c['registered_at'] ?? $c['created_at'])); ?></td></tr>
<tr><th>Status</th><td><?php echo ucfirst($c['status']); ?></td></tr>
</table>
</div>
<div class="section">
<h2>Financial Information</h2>
<table>
<tr><th>Outstanding Balance</th><td><strong>&#x20B1;<?php echo number_format($ob, 2); ?></strong></td></tr>
<tr><th>Credit Limit</th><td>&#x20B1;<?php echo number_format($c['credit_limit'] ?? 0, 2); ?></td></tr>
<tr><th>Total Purchased</th><td>&#x20B1;<?php echo number_format($totalPurchased, 2); ?></td></tr>
<tr><th>Fuel Transactions</th><td>&#x20B1;<?php echo number_format($fuelAmt, 2); ?></td></tr>
<tr><th>Merchandise</th><td>&#x20B1;<?php echo number_format($merchAmt, 2); ?></td></tr>
<tr><th>Job Orders</th><td>&#x20B1;<?php echo number_format($jobAmt, 2); ?></td></tr>
</table>
</div>
</div>
<?php if (false && $c['company_name']): ?>
<div class="section">
<h2>Company Information</h2>
<table>
<tr><th>Company Name</th><td><?php echo htmlspecialchars($c['company_name']); ?></td></tr>
<tr><th>Company Address</th><td><?php echo htmlspecialchars($c['company_address'] ?? '—'); ?></td></tr>
<tr><th>Contact Person</th><td><?php echo htmlspecialchars($c['company_contact_person'] ?? '—'); ?></td></tr>
<tr><th>Company Contact</th><td><?php echo htmlspecialchars($c['company_contact_number'] ?? '—'); ?></td></tr>
</table>
</div>
<?php endif; ?>
<div class="section">
<h2>Verification</h2>
<table>
<tr><th>Gov ID Type</th><td><?php echo htmlspecialchars($c['gov_id_type'] ?? '—'); ?></td></tr>
<tr><th>Verification Status</th><td><?php echo ucfirst($c['verification_status']); ?></td></tr>
<tr><th>Verified By</th><td><?php echo htmlspecialchars(trim($c['verified_by_name']) ?: '—'); ?></td></tr>
<tr><th>Verified At</th><td><?php echo $c['verified_at'] ? date('M d, Y', strtotime($c['verified_at'])) : '—'; ?></td></tr>
<tr><th>Remarks</th><td><?php echo htmlspecialchars($c['verification_remarks'] ?? '—'); ?></td></tr>
</table>
</div>
<div class="footer">
    Printed by: <?php echo htmlspecialchars($GLOBALS['me']['name'] ?? 'System'); ?> &nbsp;|&nbsp;
    Print Date: <?php echo date('M d, Y g:i A'); ?> &nbsp;|&nbsp;
    System Generated — Petron Management System
</div>
</body></html>
<?php
}
?>
