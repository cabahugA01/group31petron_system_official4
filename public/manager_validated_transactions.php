<?php
/**
 * MANAGER VALIDATED TRANSACTIONS
 * 
 * Shows official and corrected transactions
 * Manager can view saved transactions and their details
 * Uses NEW tables: merchandise_transactions, job_orders
 * Design: Petron Blue (#002F70)
 */
$page_id = 'validated_transactions_manager';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/transaction_schema_fix.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();

// Only Manager/Admin can access
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Manager role required.';
    header('Location: staff_dashboard.php'); exit;
}

// ── Dynamic column detection ──────────────────────────────────────────────────
function vt_cols(PDO $pdo, string $table): array {
    try {
        $rows = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $r) $map[strtolower($r['Field'])] = true;
        return $map;
    } catch (Exception $e) { return []; }
}
function vt_has(array $map, string $col): bool { return isset($map[strtolower($col)]); }

$mt_cols = vt_cols($pdo, 'merchandise_transactions');
$jo_cols = vt_cols($pdo, 'job_orders');

// ── Payment status helper ─────────────────────────────────────────────────────
function vt_pay_status(array $row): string {
    $total = (float)($row['amount'] ?? 0);
    $paid  = isset($row['amount_paid']) ? (float)$row['amount_paid'] : null;
    if ($paid === null) {
        $pm = strtolower(trim($row['payment_method'] ?? ''));
        return ($pm !== '' && $pm !== 'n/a') ? 'Paid' : 'Unpaid';
    }
    if ($paid <= 0)            return 'Unpaid';
    if ($paid < $total - 0.01) return 'Partial';
    return 'Paid';
}

// ── Filters ───────────────────────────────────────────────────────────────────
$search         = trim($_GET['search']         ?? '');
$date_from      = trim($_GET['date_from']      ?? '');
$date_to        = trim($_GET['date_to']        ?? '');
$type_filter    = trim($_GET['type']           ?? ''); // 'merchandise' | 'job_order' | ''
$payment_method = trim($_GET['payment_method'] ?? ''); // 'Cash' | 'GCash' | etc
$payment_status = trim($_GET['payment_status'] ?? ''); // 'Paid' | 'Unpaid' | 'Partial'
$staff_filter   = trim($_GET['staff']          ?? ''); // staff_id
$shift_filter   = trim($_GET['shift']          ?? ''); // 'Shift 1' | 'Shift 2' | 'Shift 3'

// Fetch official merchandise + job orders.
$rows = [];
$total_amount = 0.0;

// Merchandise official transactions
$mt_status_col = vt_has($mt_cols, 'validation_status') ? 'mt.validation_status' : "'Approved'";
$mt_staff_col  = vt_has($mt_cols, 'staff_id') ? "COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown')" : "'Unknown'";
$mt_date_col   = "CASE WHEN mt.transaction_date > '2000-01-01' THEN mt.transaction_date ELSE mt.created_at END";
$mt_paid_col   = vt_has($mt_cols, 'amount_paid') ? 'mt.amount_paid' : 'NULL';
$mt_vby_col    = vt_has($mt_cols, 'validated_by') ? "COALESCE(NULLIF(CONCAT(v.first_name,' ',v.last_name),' '), v.username, 'N/A')" : "'N/A'";
$mt_shift_col  = vt_has($mt_cols, 'shift') ? 'mt.shift' : "'N/A'";
$mt_staff_id   = vt_has($mt_cols, 'staff_id') ? 'mt.staff_id' : 'NULL';

$mt_where  = "WHERE mt.station_id = ? AND LOWER(TRIM(COALESCE(mt.validation_status,''))) IN ('official','completed','approved','validated','adjusted')";
$mt_params = [$station_id];
if ($search !== '') {
    $mt_where .= " AND (mt.customer_name LIKE ? OR mt.transaction_id LIKE ?)";
    $mt_params[] = "%$search%"; $mt_params[] = "%$search%";
}
if ($date_from !== '') {
    $mt_where .= " AND {$mt_date_col} >= ?";
    $mt_params[] = $date_from;
}
if ($date_to !== '') {
    $mt_where .= " AND {$mt_date_col} <= ?";
    $mt_params[] = $date_to;
}
if ($payment_method !== '') {
    $mt_where .= " AND LOWER(TRIM(COALESCE(mt.payment_method,''))) = LOWER(?)";
    $mt_params[] = $payment_method;
}
if ($staff_filter !== '') {
    $mt_where .= " AND mt.staff_id = ?";
    $mt_params[] = $staff_filter;
}
if (vt_has($mt_cols, 'shift') && $shift_filter !== '') {
    $mt_where .= " AND mt.shift = ?";
    $mt_params[] = $shift_filter;
}

$mt_rows = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            mt.id AS row_id,
            mt.transaction_id AS txn_id,
            COALESCE(NULLIF(TRIM(mt.customer_name),''),'Walk-in') AS customer,
            'Merchandise' AS entry_type,
            COALESCE(mt.item_sku, 'N/A') AS items_service,
            '' AS vehicle_plate,
            mt.total_amount AS amount,
            {$mt_paid_col} AS amount_paid,
            COALESCE(mt.payment_method,'Cash') AS payment_method,
            {$mt_date_col} AS txn_date,
            COALESCE({$mt_status_col},'Approved') AS validation_status,
            COALESCE({$mt_staff_col},'Unknown') AS staff_name,
            {$mt_staff_id} AS staff_id,
            COALESCE({$mt_shift_col},'N/A') AS shift,
            COALESCE({$mt_vby_col},'N/A') AS validated_by,
            'merchandise_transactions' AS _source,
            COALESCE(
                NULLIF(TRIM(COALESCE(mt.manager_notes,'')), ''),
                NULLIF(TRIM(COALESCE(mt.remarks,'')), ''),
                NULLIF(TRIM(COALESCE(mt.adjustment_reason,'')), ''),
                NULLIF(TRIM(COALESCE(mt.rejection_reason,'')), ''),
                ''
            ) AS validation_remarks
        FROM merchandise_transactions mt
        LEFT JOIN users u ON u.id = mt.staff_id
        LEFT JOIN users v ON v.id = mt.validated_by
        {$mt_where}
        ORDER BY txn_date DESC
        LIMIT 500
    ");
    $stmt->execute($mt_params);
    $mt_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $mt_rows = []; }

// Job Orders official/completed
$jo_status_col = vt_has($jo_cols, 'validation_status') ? 'jo.validation_status' : 'jo.status';
$jo_staff_col  = vt_has($jo_cols, 'created_by') ? 'COALESCE(jo.created_by, jo.user_id)' : 'jo.user_id';
$jo_pay_col    = vt_has($jo_cols, 'payment_method') ? "COALESCE(jo.payment_method,'N/A')" : "'N/A'";
$jo_cost_col   = vt_has($jo_cols, 'total_cost') ? 'COALESCE(jo.total_cost,0)' : 'COALESCE(jo.estimated_cost,0)';
$jo_paid_col   = vt_has($jo_cols, 'amount_paid') ? 'jo.amount_paid' : 'NULL';
$jo_vby_col    = vt_has($jo_cols, 'validated_by') ? "COALESCE(NULLIF(CONCAT(v.first_name,' ',v.last_name),' '), v.username, 'N/A')" : "'N/A'";
$jo_shift_col  = vt_has($jo_cols, 'shift') ? 'jo.shift' : "'N/A'";
$jo_staff_id   = vt_has($jo_cols, 'created_by') ? 'COALESCE(jo.created_by, jo.user_id)' : 'jo.user_id';

$jo_where  = "WHERE jo.station_id = ? AND LOWER(TRIM(COALESCE({$jo_status_col},''))) IN ('official','completed','approved','validated','adjusted')";
$jo_params = [$station_id];
if ($search !== '') {
    $jo_where .= " AND (jo.customer_name LIKE ? OR jo.service_type LIKE ? OR jo.vehicle_plate LIKE ?)";
    $jo_params[] = "%$search%"; $jo_params[] = "%$search%"; $jo_params[] = "%$search%";
}
if ($date_from !== '') {
    $jo_where .= " AND jo.created_at >= ?";
    $jo_params[] = $date_from;
}
if ($date_to !== '') {
    $jo_where .= " AND jo.created_at <= ?";
    $jo_params[] = $date_to;
}
if ($payment_method !== '') {
    $jo_where .= " AND LOWER(TRIM(COALESCE({$jo_pay_col},''))) = LOWER(?)";
    $jo_params[] = $payment_method;
}
if ($staff_filter !== '') {
    $jo_where .= " AND COALESCE(jo.created_by, jo.user_id) = ?";
    $jo_params[] = $staff_filter;
}
if (vt_has($jo_cols, 'shift') && $shift_filter !== '') {
    $jo_where .= " AND jo.shift = ?";
    $jo_params[] = $shift_filter;
}

$jo_rows = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            jo.id AS row_id,
            CONCAT('JO-', jo.id) AS txn_id,
            COALESCE(NULLIF(TRIM(jo.customer_name),''),'Walk-in') AS customer,
            'Job Order' AS entry_type,
            COALESCE(jo.service_type,'Service') AS items_service,
            COALESCE(NULLIF(TRIM(jo.vehicle_plate),''),'N/A') AS vehicle_plate,
            {$jo_cost_col} AS amount,
            {$jo_paid_col} AS amount_paid,
            {$jo_pay_col} AS payment_method,
            jo.created_at AS txn_date,
            COALESCE(NULLIF(TRIM({$jo_status_col}),''),'Approved') AS validation_status,
            COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown') AS staff_name,
            {$jo_staff_id} AS staff_id,
            COALESCE({$jo_shift_col},'N/A') AS shift,
            COALESCE({$jo_vby_col},'N/A') AS validated_by,
            'job_orders' AS _source,
            COALESCE(
                NULLIF(TRIM(COALESCE(jo.admin_remarks,'')), ''),
                NULLIF(TRIM(COALESCE(jo.adjustment_reason,'')), ''),
                NULLIF(TRIM(COALESCE(jo.rejection_reason,'')), ''),
                ''
            ) AS validation_remarks
        FROM job_orders jo
        LEFT JOIN users u ON u.id = COALESCE(jo.created_by, jo.user_id)
        LEFT JOIN users v ON v.id = jo.validated_by
        {$jo_where}
        ORDER BY jo.created_at DESC
        LIMIT 500
    ");
    $stmt->execute($jo_params);
    $jo_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $jo_rows = []; }

// Merge, apply type filter (in-PHP), then sort
$all_rows = array_merge($mt_rows, $jo_rows);
if ($type_filter === 'merchandise') {
    $all_rows = array_filter($all_rows, fn($r) => strtolower($r['entry_type']) === 'merchandise');
} elseif ($type_filter === 'job_order') {
    $all_rows = array_filter($all_rows, fn($r) => strtolower($r['entry_type']) === 'job order');
}
// Apply payment status filter (in-PHP since it's calculated)
if ($payment_status !== '') {
    $all_rows = array_filter($all_rows, function($r) use ($payment_status) {
        $ps = vt_pay_status($r);
        return strtolower($ps) === strtolower($payment_status);
    });
}
$rows = array_values($all_rows);
usort($rows, fn($a, $b) => strtotime($b['txn_date']) - strtotime($a['txn_date']));

// Summary card calculations (all from filtered $rows)
$total_amount = 0.0;
$merch_count  = 0;
$jo_count     = 0;
$paid_count   = 0;
$unpaid_count = 0;
foreach ($rows as $r) {
    $total_amount += (float)($r['amount'] ?? 0);
    if (strtolower($r['entry_type']) === 'merchandise') { $merch_count++; } else { $jo_count++; }
    $ps = vt_pay_status($r);
    if ($ps === 'Paid') { $paid_count++; } else { $unpaid_count++; }
}

// Fetch staff list for Staff Encoder dropdown
$staff_list = [];
try {
    $stmt = $pdo->prepare("SELECT id, COALESCE(NULLIF(CONCAT(first_name,' ',last_name),' '), username) AS name FROM users WHERE station_id = ? AND role != 'admin' ORDER BY name");
    $stmt->execute([$station_id]);
    $staff_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $staff_list = []; }

// ── Server-Side Exports ──────────────────────────────────────────────────────
$export_type = $_GET['export'] ?? '';
if ($export_type === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Validated_Transactions_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Txn ID', 'Customer', 'Type', 'Items / Service', 'Vehicle Plate', 'Amount', 'Payment Method', 'Payment Status', 'Shift', 'Staff', 'Date', 'Validated By', 'Validation Remarks']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['txn_id'],
            $r['customer'],
            $r['entry_type'],
            $r['items_service'] ?? '—',
            $r['vehicle_plate'] ?? '—',
            number_format((float)$r['amount'], 2),
            $r['payment_method'],
            vt_pay_status($r),
            $r['shift'] ?? 'N/A',
            $r['staff_name'],
            date('M d, Y H:i', strtotime($r['txn_date'])),
            $r['validated_by'],
            $r['validation_remarks'] ?? '—'
        ]);
    }
    fclose($out);
    exit;
}

if ($export_type === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="Validated_Transactions_' . date('Y-m-d') . '.xls"');
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="UTF-8">';
    echo '<style>table{border-collapse:collapse}th,td{border:1px solid #ddd;padding:7px}th{background:#002F70;color:#fff;font-weight:700}</style>';
    echo '</head><body>';
    echo '<h2>Validated Transactions Report</h2>';
    echo '<p>Generated: ' . date('F d, Y h:i A') . ' | Records: ' . count($rows) . '</p>';
    echo '<table><thead><tr>';
    foreach (['Txn ID', 'Customer', 'Type', 'Items / Service', 'Vehicle Plate', 'Amount', 'Payment Method', 'Payment Status', 'Shift', 'Staff', 'Date', 'Validated By', 'Validation Remarks'] as $h) {
        echo '<th>' . htmlspecialchars($h) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $pay_st = vt_pay_status($r);
        echo '<tr>';
        echo '<td>' . htmlspecialchars($r['txn_id']) . '</td>';
        echo '<td>' . htmlspecialchars($r['customer']) . '</td>';
        echo '<td>' . htmlspecialchars($r['entry_type']) . '</td>';
        echo '<td>' . htmlspecialchars($r['items_service'] ?? '—') . '</td>';
        echo '<td>' . htmlspecialchars($r['vehicle_plate'] ?? '—') . '</td>';
        echo '<td style="text-align:right">&#8369;' . number_format((float)$r['amount'], 2) . '</td>';
        echo '<td>' . htmlspecialchars($r['payment_method']) . '</td>';
        echo '<td>' . htmlspecialchars($pay_st) . '</td>';
        echo '<td>' . htmlspecialchars($r['shift'] ?? 'N/A') . '</td>';
        echo '<td>' . htmlspecialchars($r['staff_name']) . '</td>';
        echo '<td>' . date('M d, Y H:i', strtotime($r['txn_date'])) . '</td>';
        echo '<td>' . htmlspecialchars($r['validated_by']) . '</td>';
        echo '<td>' . htmlspecialchars($r['validation_remarks'] ?? '—') . '</td>';
        echo '</tr>';
    }
    echo '<tr style="font-weight:800;background:#f0f7ff">';
    echo '<td colspan="5" style="text-align:right"><strong>TOTAL</strong></td>';
    echo '<td style="text-align:right"><strong>&#8369;' . number_format($total_amount, 2) . '</strong></td>';
    echo '<td colspan="7"></td>';
    echo '</tr>';
    echo '</tbody></table></body></html>';
    exit;
}

if ($export_type === 'pdf') {
    header('Content-Type: text/html; charset=utf-8');
    $logo_url  = '../assets/img/Petron%20Logo.png';
    $generated = date('F d, Y  h:i A');
    $rec_count = count($rows);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
    echo '<title>Validated Transactions | Petron Station Management</title>';
    echo '<style>';
    echo 'body{font-family:Arial,Helvetica,sans-serif;font-size:12px;margin:0;padding:0;background:#f1f5f9;color:#1e293b;}';
    echo '.action-bar{background:#002F70;padding:12px 24px;display:flex;align-items:center;text-align:center;justify-content:center;gap:12px;}';
    echo '.action-bar h2{color:#fff;font-size:15px;margin:0;}';
    echo '.btn-print{display:inline-flex;align-items:center;gap:7px;padding:9px 20px;background:#DC0032;color:#fff;border:none;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;}';
    echo '.btn-back{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.35);border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;}';
    echo '.btn-back:hover,.btn-print:hover{opacity:.85;}';
    echo '.report{background:#fff;max-width:1200px;margin:20px auto;border-radius:10px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.12);}';
    echo '.rpt-header{background:linear-gradient(135deg,#002F70 0%,#003d8a 100%);padding:22px 28px;display:flex;align-items:center;gap:18px;}';
    echo '.rpt-header img{height:52px;width:auto;}';
    echo '.rpt-header-text h1{color:#fff;font-size:18px;font-weight:800;margin:0 0 3px;}';
    echo '.rpt-header-text p{color:#93c5fd;font-size:11px;margin:0;}';
    echo '.rpt-header-meta{margin-left:auto;text-align:right;color:#bfdbfe;font-size:11px;line-height:1.7;}';
    echo '.rpt-header-meta strong{color:#fff;}';
    echo '.rpt-body{padding:20px;overflow-x:hidden;}';
    echo 'table{width:100%;border-collapse:collapse;font-size:11px;}';
    echo 'thead tr{background:#002F70;}';
    echo 'th{padding:9px 8px;color:#fff;font-weight:700;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;}';
    echo 'td{padding:8px;border-bottom:1px solid #e2e8f0;}';
    echo 'tr:nth-child(even) td{background:#f8fafc;}';
    echo '.amount{text-align:right;font-weight:700;color:#002F70;}';
    echo '.total-row td{background:#f0f7ff!important;font-weight:800;color:#002F70;border-top:2px solid #002F70;}';
    echo '.rpt-footer{padding:16px 28px;background:#f8fafc;border-top:2px solid #e2e8f0;font-size:10px;color:#64748b;text-align:center;}';
    echo '@media print{.action-bar{display:none!important;}body{background:#fff;}.report{box-shadow:none;border-radius:0;margin:0;max-width:100%;}table{font-size:9.5px;}th,td{padding:5px 4px;}}';
    echo '</style></head><body>';
    echo '<div class="action-bar">';
    echo '  <h2>Validated Transactions Report</h2>';
    echo '  <a href="javascript:window.print()" class="btn-print">Print / Save as PDF</a>';
    echo '  <a href="javascript:void(0)" onclick="window.history.length>1?window.history.back():window.close()" class="btn-back">Back</a>';
    echo '</div>';
    echo '<div class="report">';
    echo '<div class="rpt-header">';
    echo '  <img src="' . $logo_url . '" alt="Petron Logo">';
    echo '  <div class="rpt-header-text"><h1>Petron Station Management System</h1><p>Validated Transactions Report</p></div>';
    echo '  <div class="rpt-header-meta">';
    echo '    <div><strong>Generated:</strong> ' . $generated . '</div>';
    echo '    <div><strong>Total Records:</strong> ' . $rec_count . '</div>';
    echo '  </div>';
    echo '</div>';
    echo '<div class="rpt-body"><table><thead><tr>';
    foreach (['Txn ID', 'Customer', 'Type', 'Items / Service', 'Vehicle Plate', 'Amount', 'Payment Method', 'Payment Status', 'Shift', 'Staff', 'Date', 'Validated By', 'Validation Remarks'] as $h) {
        echo '<th>' . htmlspecialchars($h) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $pay_st = vt_pay_status($r);
        echo '<tr>';
        echo '<td>' . htmlspecialchars($r['txn_id']) . '</td>';
        echo '<td>' . htmlspecialchars($r['customer']) . '</td>';
        echo '<td>' . htmlspecialchars($r['entry_type']) . '</td>';
        echo '<td>' . htmlspecialchars($r['items_service'] ?? '—') . '</td>';
        echo '<td>' . htmlspecialchars($r['vehicle_plate'] ?? '—') . '</td>';
        echo '<td class="amount">&#8369;' . number_format((float)$r['amount'], 2) . '</td>';
        echo '<td>' . htmlspecialchars($r['payment_method']) . '</td>';
        echo '<td>' . htmlspecialchars($pay_st) . '</td>';
        echo '<td>' . htmlspecialchars($r['shift'] ?? 'N/A') . '</td>';
        echo '<td>' . htmlspecialchars($r['staff_name']) . '</td>';
        echo '<td>' . date('M d, Y H:i', strtotime($r['txn_date'])) . '</td>';
        echo '<td>' . htmlspecialchars($r['validated_by']) . '</td>';
        echo '<td>' . htmlspecialchars($r['validation_remarks'] ?? '—') . '</td>';
        echo '</tr>';
    }
    echo '<tr class="total-row">';
    echo '<td colspan="5" style="text-align:right">TOTAL AMOUNT</td>';
    echo '<td class="amount" style="white-space:nowrap">&#8369;' . number_format($total_amount, 2) . '</td>';
    echo '<td colspan="7"></td>';
    echo '</tr>';
    echo '</tbody></table></div>';
    echo '<div class="rpt-footer">&#169; ' . date('Y') . ' Petron Station &amp; Service Center Management System. All Rights Reserved.</div>';
    echo '</div></body></html>';
    exit;
}

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head txn-page-head">
    <div>
        <h1 class="h1"><i class="fas fa-check-double"></i> All Transactions</h1>
        <div class="sub">View and monitor all transactions encoded by staff across operational shifts.</div>
    </div>

    <!-- Export & Back Buttons (Header Right) -->
    <div class="actions txn-head-actions">
        <a href="<?= in_array($role, ['admin', 'superadmin']) ? 'admin_dashboard.php' : 'manager_dashboard.php'; ?>" class="flt-btn flt-btn-reset"><i class="fas fa-arrow-left"></i> Back</a>
        <button type="button" onclick="exportTable('excel')" class="flt-btn flt-btn-excel"><i class="fas fa-file-excel"></i> Excel</button>
        <button type="button" onclick="exportTable('csv')" class="flt-btn flt-btn-search"><i class="fas fa-file-csv"></i> CSV</button>
        <button type="button" onclick="exportTable('pdf')" class="flt-btn flt-btn-pdf"><i class="fas fa-file-pdf"></i> PDF</button>
    </div>
</div>

<?php if (isset($_SESSION['success'])): ?>
<div style="background:#d4edda;color:#155724;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
</div>
<?php endif; ?>
<?php if (isset($_SESSION['error'])): ?>
<div style="background:#f8d7da;color:#721c24;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
</div>
<?php endif; ?>

<!-- Filter Bar -->
<div class="vt-filter-card">
    <form method="get" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
        <div class="vt-flt-grp">
            <label class="vt-lbl"><i class="fas fa-search"></i> Search</label>
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                   class="vt-inp" placeholder="Transaction ID, customer..." style="width:220px;">
        </div>
        <div class="vt-flt-grp">
            <label class="vt-lbl"><i class="fas fa-tag"></i> Type</label>
            <select name="type" class="vt-inp" style="width:160px;">
                <option value="" <?php echo $type_filter === '' ? 'selected' : ''; ?>>All Types</option>
                <option value="merchandise" <?php echo $type_filter === 'merchandise' ? 'selected' : ''; ?>>Merchandise</option>
                <option value="job_order"   <?php echo $type_filter === 'job_order'   ? 'selected' : ''; ?>>Job Order</option>
            </select>
        </div>
        <div class="vt-flt-grp">
            <label class="vt-lbl"><i class="fas fa-wallet"></i> Payment</label>
            <select name="payment_method" class="vt-inp" style="width:155px;">
                <option value="" <?php echo $payment_method === '' ? 'selected' : ''; ?>>All Methods</option>
                <option value="Cash"          <?php echo $payment_method === 'Cash'          ? 'selected' : ''; ?>>Cash</option>
                <option value="Card"          <?php echo $payment_method === 'Card'          ? 'selected' : ''; ?>>Card</option>
                <option value="E-Wallet"      <?php echo $payment_method === 'E-Wallet'      ? 'selected' : ''; ?>>E-Wallet</option>
                <option value="Petron E-Fuel" <?php echo $payment_method === 'Petron E-Fuel' ? 'selected' : ''; ?>>Petron E-Fuel</option>
                <option value="Fleet Card"    <?php echo $payment_method === 'Fleet Card'    ? 'selected' : ''; ?>>Fleet Card</option>
                <option value="Credit"        <?php echo $payment_method === 'Credit'        ? 'selected' : ''; ?>>Credit</option>
            </select>
        </div>
        <div class="vt-flt-grp">
            <label class="vt-lbl"><i class="fas fa-calendar"></i> From</label>
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="vt-inp">
        </div>
        <div class="vt-flt-grp">
            <label class="vt-lbl"><i class="fas fa-calendar"></i> To</label>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="vt-inp">
        </div>
        <div style="align-self:flex-end;display:flex !important;flex-direction:row !important;gap:8px;">
            <button type="submit" class="flt-btn flt-btn-search"><i class="fas fa-search"></i> Filter</button>
            <a href="?" class="flt-btn flt-btn-reset"><i class="fas fa-rotate-left"></i> Reset</a>
        </div>
    </form>
</div>

<!-- Summary Cards -->
<div class="txn-kpi-grid">
    <div class="txn-kpi-card">
        <div class="txn-kpi-lbl"><i class="fas fa-receipt"></i> Total Transactions</div>
        <div class="txn-kpi-val"><?php echo count($rows); ?></div>
    </div>
    <div class="txn-kpi-card blue">
        <div class="txn-kpi-lbl"><i class="fas fa-box"></i> Merchandise</div>
        <div class="txn-kpi-val"><?php echo $merch_count; ?></div>
    </div>
    <div class="txn-kpi-card purple">
        <div class="txn-kpi-lbl"><i class="fas fa-wrench"></i> Job Orders</div>
        <div class="txn-kpi-val"><?php echo $jo_count; ?></div>
    </div>
    <div class="txn-kpi-card green">
        <div class="txn-kpi-lbl"><i class="fas fa-check-circle"></i> Paid</div>
        <div class="txn-kpi-val"><?php echo $paid_count; ?></div>
    </div>
    <div class="txn-kpi-card danger">
        <div class="txn-kpi-lbl"><i class="fas fa-clock"></i> Unpaid / Partial</div>
        <div class="txn-kpi-val"><?php echo $unpaid_count; ?></div>
    </div>
    <div class="txn-kpi-card total-amount-card">
        <div class="txn-kpi-lbl"><i class="fas fa-peso-sign"></i> Total Amount</div>
        <div class="txn-kpi-val">&#8369;<?php echo number_format($total_amount, 2); ?></div>
    </div>
</div>

<!-- Table -->
<div class="card" style="padding:0;overflow:hidden;">
    <table class="vt-table" style="table-layout:auto;width:100%;">
        <colgroup>
            <col style="width:7%;"><!-- Transaction ID -->
            <col style="width:9%;"><!-- Customer -->
            <col style="width:6%;"><!-- Type -->
            <col style="width:12%;"><!-- Items / Service -->
            <col style="width:7%;"><!-- Amount -->
            <col style="width:7%;"><!-- Payment Method -->
            <col style="width:8%;"><!-- Payment Status -->
            <col style="width:9%;"><!-- Date / Time -->
            <col style="width:8%;"><!-- Staff -->
            <col style="width:8%;"><!-- Validated By -->
            <col style="width:12%;"><!-- Validation Remarks -->
            <col style="width:7%;"><!-- Actions -->
        </colgroup>
        <thead>
            <tr>
                <th>Txn ID</th>
                <th>Customer</th>
                <th>Type</th>
                <th>Items / Service</th>
                <th style="text-align:right;">Amount</th>
                <th>Method</th>
                <th>Status</th>
                <th>Date</th>
                <th>Staff</th>
                <th>Validated</th>
                <th>Validation Remarks</th>
                <th style="text-align:center;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($rows) > 0): ?>
                <?php foreach ($rows as $r): ?>
                <?php $pay_st = vt_pay_status($r); ?>
                <tr>
                    <td style="font-weight:600;font-size:13px;font-family:monospace;white-space:nowrap;">
                        <?php echo htmlspecialchars($r['txn_id']); ?>
                    </td>
                    <td style="font-size:13px;" title="<?php echo htmlspecialchars($r['customer']); ?>"><?php echo htmlspecialchars($r['customer']); ?></td>
                    <td>
                        <span class="vt-badge vt-badge-type">
                            <?php echo htmlspecialchars($r['entry_type']); ?>
                        </span>
                    </td>
                    <td style="font-size:13px;"
                        title="<?php echo htmlspecialchars($r['items_service']); ?>">
                        <?php echo htmlspecialchars($r['items_service']); ?>
                    </td>
                    <td style="font-weight:600;color:#002F70;text-align:right;white-space:nowrap;">
                        &#8369;<?php echo number_format((float)$r['amount'], 2); ?>
                    </td>
                    <td style="font-size:13px;"><?php echo htmlspecialchars($r['payment_method']); ?></td>
                    <td>
                        <span class="vt-badge vt-badge-<?php echo strtolower(str_replace(' ', '-', $pay_st)); ?>">
                            <?php echo $pay_st; ?>
                        </span>
                    </td>
                    <td style="white-space:nowrap;font-size:13px;color:#64748b;">
                        <?php echo date('M d, Y H:i', strtotime($r['txn_date'])); ?>
                    </td>
                    <td style="font-size:13px;color:#64748b;"><?php echo htmlspecialchars($r['staff_name']); ?></td>
                    <td style="font-size:13px;color:#64748b;"><?php echo htmlspecialchars($r['validated_by']); ?></td>
                    <?php $val_rem = trim($r['validation_remarks'] ?? ''); ?>
                    <td style="font-size:11px;font-style:italic;color:#64748b;line-height:1.4;" title="<?= htmlspecialchars($val_rem ?: '—') ?>">
                        <?php if ($val_rem !== ''): ?>
                            <span style="display:inline-block;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($val_rem) ?>"><?= htmlspecialchars($val_rem) ?></span>
                        <?php else: ?>
                            <span style="color:#cbd5e1;font-style:normal;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;padding:8px 4px;">
                        <button class="vt-btn-action vt-btn-view" onclick="viewValidatedTransaction('<?php echo $r['_source']; ?>', <?php echo $r['row_id']; ?>)" title="View transaction details" style="padding:6px 10px;font-size:12px;">
                            <i class="fas fa-eye"></i> View
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="12" style="text-align:center;padding:60px 20px;color:#94a3b8;">
                        <i class="fas fa-inbox" style="font-size:48px;display:block;margin-bottom:12px;opacity:0.3;"></i>
                        <div style="font-size:16px;font-weight:600;color:#64748b;margin-bottom:4px;">No Validated Transactions</div>
                        <div style="font-size:13px;">No approved transactions found matching your filters.</div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- View Transaction Modal -->
<div class="vt-modal-overlay" id="viewTransactionModal">
    <div class="vt-modal" style="max-width:700px;">
        <div class="vt-modal-header">
            <h3><i class="fas fa-eye" style="color:#003d82;margin-right:8px;"></i>Transaction Details</h3>
            <button class="vt-modal-close" onclick="closeViewModal()">&times;</button>
        </div>
        <div id="viewTransactionContent" class="vt-modal-body">
            <div style="text-align:center;padding:40px;">
                <i class="fas fa-spinner fa-spin" style="font-size:32px;color:#003d82;"></i>
                <div style="margin-top:12px;color:#64748b;">Loading transaction details...</div>
            </div>
        </div>
        <div class="vt-modal-footer">
            <button type="button" class="vt-btn vt-btn-reset" onclick="closeViewModal()">Close</button>
        </div>
    </div>
</div>

<style>
/* == PAGE HEADER - matches SuperAdmin page-head standard == */
.page-head.txn-page-head { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px; margin-top:-12px !important; }
.page-head.txn-page-head h1 { font-size:22px !important; font-weight:700 !important; color:var(--petron-blue,#00264D) !important; margin:0 !important; text-transform:none !important; display:flex; align-items:center; gap:8px; }
.page-head.txn-page-head .sub { font-size:13px; color:#666; margin-top:4px; text-transform:none !important; font-weight:400 !important; }

/* == Shared export/action buttons (flt-btn style) == */
.flt-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 0 16px;
    height: 36px;
    border-radius: 7px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    white-space: nowrap;
    transition: all .15s;
    background: white !important;
    border: 1px solid transparent;
}
.flt-btn-search { color: #00264D !important; border-color: #00264D !important; }
.flt-btn-search:hover { background: #00264D !important; color: #fff !important; }
.flt-btn-reset  { color: #6b7280 !important; border-color: #6b7280 !important; }
.flt-btn-reset:hover  { background: #6b7280 !important; color: #fff !important; }
.flt-btn-excel  { color: #1d6f42 !important; border-color: #1d6f42 !important; }
.flt-btn-excel:hover  { background: #1d6f42 !important; color: #fff !important; }
.flt-btn-pdf    { color: #dc2626 !important; border-color: #dc2626 !important; }
.flt-btn-pdf:hover    { background: #dc2626 !important; color: #fff !important; }

/* == Petron Clean KPI Summary Cards == */
.txn-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    margin-bottom: 18px;
}
.txn-kpi-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px 16px;
    box-shadow: 0 1px 4px rgba(0, 0, 0, .05);
    transition: transform .15s, box-shadow .15s;
}
.txn-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, .08);
}
.txn-kpi-lbl {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: #64748b;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.txn-kpi-val {
    font-size: 24px;
    font-weight: 800;
    color: #002F70;
    line-height: 1.1;
}
.txn-kpi-card.blue { border-left-color: #0369a1; }
.txn-kpi-card.blue .txn-kpi-val { color: #0369a1; }
.txn-kpi-card.purple { border-left-color: #7c3aed; }
.txn-kpi-card.purple .txn-kpi-val { color: #7c3aed; }
.txn-kpi-card.green { border-left-color: #16a34a; }
.txn-kpi-card.green .txn-kpi-val { color: #16a34a; }
.txn-kpi-card.orange { border-left-color: #ea580c; }
.txn-kpi-card.orange .txn-kpi-val { color: #ea580c; }
.txn-kpi-card.danger { border-left-color: #dc2626; }
.txn-kpi-card.danger .txn-kpi-val { color: #dc2626; }

/* Special Gradient Card for Total Amount */
.txn-kpi-card.total-amount-card {
    background: linear-gradient(135deg, #002F70 0%, #003d8a 100%);
    border-left: none;
}
.txn-kpi-card.total-amount-card .txn-kpi-lbl {
    color: #93c5fd;
}
.txn-kpi-card.total-amount-card .txn-kpi-val {
    color: #fff;
}

/* Filter Card */
.vt-filter-card { 
    background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px 18px;margin-bottom:14px;box-shadow:0 1px 4px rgba(0,0,0,.05);
}
.vt-flt-grp { display:flex;flex-direction:column;gap:4px; }
.vt-lbl { font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px; }
.vt-inp { 
    height:36px;padding:0 12px;border:1px solid #cbd5e1;border-radius:7px;font-size:13px;color:#1e293b;background:#fff;outline:none;box-sizing:border-box; 
}
.vt-inp:focus { border-color:#002F70;box-shadow:0 0 0 3px rgba(0,47,112,.1); }
.vt-btn { 
    display:inline-flex;align-items:center;gap:6px;padding:0 18px;height:36px;
    border:1px solid transparent;border-radius:7px;font-size:13px;font-weight:600;
    cursor:pointer;text-decoration:none;white-space:nowrap;transition:all .15s;
    background:white !important;
}
.vt-btn-search { color:#002F70 !important; border-color:#002F70 !important; }
.vt-btn-search:hover { background:#002F70 !important; color:#fff !important; }
.vt-btn-reset  { color:#4b5563 !important; border-color:#6b7280 !important; }
.vt-btn-reset:hover { background:#6b7280 !important; color:#fff !important; }

/* Table - SuperAdmin ato-table standard */
.vt-table { width:100%;border-collapse:collapse;font-size:11px; }
.vt-table thead th { 
    background:#002F70;color:#fff;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:9px 10px;border-bottom:2px solid #001a3d;text-align:left;vertical-align:middle;
}
.vt-table tbody td { padding:9px 10px;border-bottom:1px solid #f1f5f9;vertical-align:middle;background:#fff;font-size:11px; }
.vt-table tbody tr:hover td { background:#eff6ff; }

/* Badges */
.vt-badge { 
    display:inline-block;padding:3px 10px;border-radius:4px;font-size:11px;font-weight:600;white-space:nowrap;background:#f8fafc;color:#64748b;border:1px solid #e2e8f0;
}
.vt-badge-type { background:#f1f5f9;color:#475569;border-color:#cbd5e1; }
.vt-badge-paid { background:#f0fdf4;color:#166534;border-color:#bbf7d0; }
.vt-badge-partial { background:#fef3c7;color:#92400e;border-color:#fde047; }
.vt-badge-unpaid { background:#fef2f2;color:#991b1b;border-color:#fecaca; }

/* Action Buttons */
.vt-btn-action { 
    background: white !important;
    width:auto;
    min-width:80px;
    height:34px;
    border-radius:7px;
    border:1px solid transparent;
    cursor:pointer;
    font-size:11px;
    font-weight:600;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    transition:all .15s;
    padding:0 12px;
}
.vt-btn-view   { color:#002F70 !important; border-color:#002F70 !important; }
.vt-btn-view:hover { background:#002F70 !important; color:#fff !important; }

/* View Modal */
.vt-modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:9999; align-items:center; justify-content:center; }
.vt-modal-overlay.active { display:flex; }
.vt-modal { background:#fff; border-radius:12px; width:100%; max-width:700px; box-shadow:0 8px 40px rgba(0,0,0,.2); max-height:90vh; display:flex; flex-direction:column; }
.vt-modal-header { display:flex; align-items:center; justify-content:space-between; padding:20px 24px; border-bottom:1px solid #e2e8f0; }
.vt-modal-header h3 { margin:0; font-size:18px; font-weight:700; color:#1e293b; display:flex; align-items:center; }
.vt-modal-close { background:none; border:none; font-size:28px; color:#64748b; cursor:pointer; padding:0; width:32px; height:32px; border-radius:6px; }
.vt-modal-close:hover { background:#f1f5f9; color:#1e293b; }
.vt-modal-body { padding:24px; overflow-y:auto; flex:1; }
.vt-modal-footer { padding:16px 24px; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:8px; }
.vt-detail-grid { display:grid; grid-template-columns:140px 1fr; gap:12px 20px; font-size:14px; }
.vt-detail-label { font-weight:600; color:#64748b; }
.vt-detail-value { color:#1e293b; }
.vt-detail-amount { color:#002F70; font-weight:700; font-size:16px; }
</style>

<script>
function viewValidatedTransaction(source, id) {
    // Open modal
    document.getElementById('viewTransactionModal').classList.add('active');
    document.getElementById('viewTransactionContent').innerHTML = '<div style="text-align:center;padding:40px;"><i class="fas fa-spinner fa-spin" style="font-size:32px;color:#003d82;"></i><div style="margin-top:12px;color:#64748b;">Loading...</div></div>';
    
    // Fetch transaction details
    fetch('../backend/get_transaction_details.php?type=' + source + '&id=' + id)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let html = '<div class="vt-detail-grid">';
                
                if (data.type === 'merchandise') {
                    // Merchandise transaction details
                    html += '<div class="vt-detail-label">Transaction ID:</div><div class="vt-detail-value" style="font-family:monospace;font-weight:600;">' + data.transaction_id + '</div>';
                    html += '<div class="vt-detail-label">Customer:</div><div class="vt-detail-value">' + data.customer_name + '</div>';
                    html += '<div class="vt-detail-label">Item SKU:</div><div class="vt-detail-value">' + data.item_sku + '</div>';
                    html += '<div class="vt-detail-label">Quantity:</div><div class="vt-detail-value">' + data.quantity + '</div>';
                    html += '<div class="vt-detail-label">Unit Price:</div><div class="vt-detail-value">₱' + data.unit_price + '</div>';
                    html += '<div class="vt-detail-label">Total Amount:</div><div class="vt-detail-amount">₱' + data.total_amount + '</div>';
                    html += '<div class="vt-detail-label">Payment Method:</div><div class="vt-detail-value">' + data.payment_method + '</div>';
                    if (data.amount_tendered !== 'N/A') {
                        html += '<div class="vt-detail-label">Amount Tendered:</div><div class="vt-detail-value">₱' + data.amount_tendered + '</div>';
                        html += '<div class="vt-detail-label">Change:</div><div class="vt-detail-value">₱' + data.change_amount + '</div>';
                    }
                    html += '<div class="vt-detail-label">Transaction Date:</div><div class="vt-detail-value">' + data.transaction_date + '</div>';
                    html += '<div class="vt-detail-label">Staff:</div><div class="vt-detail-value">' + data.staff_name + '</div>';
                    html += '<div class="vt-detail-label">Status:</div><div class="vt-detail-value"><span style="background:#f0fdf4;color:#166534;padding:4px 12px;border-radius:4px;font-size:12px;font-weight:600;">' + data.validation_status + '</span></div>';
                    html += '<div class="vt-detail-label">Validated By:</div><div class="vt-detail-value">' + data.validated_by + '</div>';
                    html += '<div class="vt-detail-label">Validated At:</div><div class="vt-detail-value">' + data.validated_at + '</div>';
                    if (data.shift !== 'N/A') {
                        html += '<div class="vt-detail-label">Shift:</div><div class="vt-detail-value">' + data.shift + '</div>';
                    }
                    if (data.remarks !== 'N/A') {
                        html += '<div class="vt-detail-label">Remarks:</div><div class="vt-detail-value">' + data.remarks + '</div>';
                    }
                } else if (data.type === 'job_order') {
                    // Job order details
                    html += '<div class="vt-detail-label">Job Order #:</div><div class="vt-detail-value" style="font-family:monospace;font-weight:600;">' + data.transaction_id + '</div>';
                    html += '<div class="vt-detail-label">Customer:</div><div class="vt-detail-value">' + data.customer_name + '</div>';
                    html += '<div class="vt-detail-label">Vehicle Plate:</div><div class="vt-detail-value">' + data.vehicle_plate + '</div>';
                    html += '<div class="vt-detail-label">Vehicle Type:</div><div class="vt-detail-value">' + data.vehicle_type + '</div>';
                    html += '<div class="vt-detail-label">Service Type:</div><div class="vt-detail-value">' + data.service_type + '</div>';
                    html += '<div class="vt-detail-label">Description:</div><div class="vt-detail-value">' + data.service_description + '</div>';
                    html += '<div class="vt-detail-label">Required Parts:</div><div class="vt-detail-value" style="font-size:12px;">' + data.required_parts + '</div>';
                    html += '<div class="vt-detail-label">Mechanic:</div><div class="vt-detail-value">' + data.mechanic_name + '</div>';
                    html += '<div class="vt-detail-label">Estimated Cost:</div><div class="vt-detail-value">₱' + data.estimated_cost + '</div>';
                    html += '<div class="vt-detail-label">Total Amount:</div><div class="vt-detail-amount">₱' + data.total_amount + '</div>';
                    html += '<div class="vt-detail-label">Amount Paid:</div><div class="vt-detail-value">₱' + data.amount_paid + '</div>';
                    html += '<div class="vt-detail-label">Change:</div><div class="vt-detail-value">₱' + data.change_amount + '</div>';
                    html += '<div class="vt-detail-label">Payment Method:</div><div class="vt-detail-value">' + data.payment_method + '</div>';
                    html += '<div class="vt-detail-label">Payment Status:</div><div class="vt-detail-value">' + data.payment_status + '</div>';
                    html += '<div class="vt-detail-label">Job Status:</div><div class="vt-detail-value">' + data.job_status + '</div>';
                    html += '<div class="vt-detail-label">Created Date:</div><div class="vt-detail-value">' + data.transaction_date + '</div>';
                    html += '<div class="vt-detail-label">Staff:</div><div class="vt-detail-value">' + data.staff_name + '</div>';
                    html += '<div class="vt-detail-label">Validation Status:</div><div class="vt-detail-value"><span style="background:#f0fdf4;color:#166534;padding:4px 12px;border-radius:4px;font-size:12px;font-weight:600;">' + data.validation_status + '</span></div>';
                    html += '<div class="vt-detail-label">Validated By:</div><div class="vt-detail-value">' + data.validated_by + '</div>';
                    html += '<div class="vt-detail-label">Validated At:</div><div class="vt-detail-value">' + data.validated_at + '</div>';
                    if (data.additional_notes !== 'N/A') {
                        html += '<div class="vt-detail-label">Notes:</div><div class="vt-detail-value">' + data.additional_notes + '</div>';
                    }
                }
                
                html += '</div>';
                document.getElementById('viewTransactionContent').innerHTML = html;
            } else {
                document.getElementById('viewTransactionContent').innerHTML = '<div style="text-align:center;padding:40px;color:#dc2626;"><i class="fas fa-exclamation-circle" style="font-size:32px;display:block;margin-bottom:12px;"></i>' + (data.error || 'Unable to load details') + '</div>';
            }
        })
        .catch(error => {
            console.error('Error loading transaction details:', error);
            document.getElementById('viewTransactionContent').innerHTML = '<div style="text-align:center;padding:40px;color:#f59e0b;"><i class="fas fa-exclamation-triangle" style="font-size:32px;display:block;margin-bottom:12px;"></i>Connection error. Please try again.</div>';
        });
}

function closeViewModal() {
    document.getElementById('viewTransactionModal').classList.remove('active');
}

function exportTable(format) {
    const params = new URLSearchParams(window.location.search);
    params.set('export', format);
    window.location.href = '?' + params.toString();
    return;
    const table = document.querySelector('.vt-table');
    if (!table) { alert('No transaction data found.'); return; }

    const dateFrom = document.querySelector('input[name="date_from"]')?.value || '';
    const dateTo   = document.querySelector('input[name="date_to"]')?.value || '';
    const filename = `Validated_Transactions_${dateFrom || 'All'}_to_${dateTo || 'All'}`;

    if (format === 'excel') {
        if (typeof XLSX === 'undefined') {
            alert('Export library not loaded. Please try again.');
            return;
        }
        const aoa = [];
        // Headers
        table.querySelectorAll('thead tr').forEach(tr => {
            const cells = [...tr.querySelectorAll('th')];
            cells.pop(); // Remove "Actions"
            aoa.push(cells.map(th => th.innerText.trim()));
        });
        // Body
        table.querySelectorAll('tbody tr').forEach(tr => {
            const cells = [...tr.querySelectorAll('td')];
            if (cells.length > 1) { // Skip "No records" row if it spans
                cells.pop(); // Remove "Actions"
                aoa.push(cells.map(td => td.innerText.trim()));
            } else {
                aoa.push(cells.map(td => td.innerText.trim()));
            }
        });
        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(aoa);
        if (aoa.length && aoa[0]) {
            ws['!cols'] = aoa[0].map((_, ci) => ({
                wch: Math.min(45, Math.max(10, ...aoa.map(row => String(row[ci] ?? '').length)))
            }));
        }
        XLSX.utils.book_append_sheet(wb, ws, 'Validated Transactions');
        XLSX.writeFile(wb, filename + '.xlsx');
    } else if (format === 'csv') {
        let csv = '';
        // Headers
        table.querySelectorAll('thead tr').forEach(tr => {
            const cells = [...tr.querySelectorAll('th')];
            cells.pop();
            csv += cells.map(th => '"' + th.innerText.trim().replace(/"/g, '""') + '"').join(',') + '\n';
        });
        // Body
        table.querySelectorAll('tbody tr').forEach(tr => {
            const cells = [...tr.querySelectorAll('td')];
            if (cells.length > 1) {
                cells.pop();
                csv += cells.map(td => '"' + td.innerText.trim().replace(/"/g, '""') + '"').join(',') + '\n';
            } else {
                csv += cells.map(td => '"' + td.innerText.trim().replace(/"/g, '""') + '"').join(',') + '\n';
            }
        });
        const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href     = url;
        a.download = filename + '.csv';
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { document.body.removeChild(a); URL.revokeObjectURL(url); }, 200);
    } else if (format === 'pdf') {
        const logo_url  = '../assets/img/Petron%20Logo.png';
        const generated = new Date().toLocaleString();
        
        // Let's clone the table and remove the last column from the print HTML
        const tableClone = table.cloneNode(true);
        tableClone.querySelectorAll('tr').forEach(tr => {
            const lastCell = tr.lastElementChild;
            if (lastCell) lastCell.remove();
        });
        
        let tableHtml = tableClone.outerHTML;
        
        let iframe = document.getElementById('print-iframe');
        if (!iframe) {
            iframe = document.createElement('iframe');
            iframe.id = 'print-iframe';
            iframe.style.position = 'fixed';
            iframe.style.right = '0';
            iframe.style.bottom = '0';
            iframe.style.width = '0';
            iframe.style.height = '0';
            iframe.style.border = '0';
            document.body.appendChild(iframe);
        }

        const doc = iframe.contentDocument || iframe.contentWindow.document;
        doc.open();
        doc.write(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>Validated Transactions Report</title>
        <style>
            @page{size:legal landscape;margin:.3in .4in;}
            *{-webkit-print-color-adjust:exact !important;print-color-adjust:exact !important;box-sizing:border-box;}
            body{font-family:Arial,sans-serif;font-size:11px;color:#000;background:white;margin:0;padding:20px;}
            .header-container{display:flex;align-items:center;gap:15px;border-bottom:2px solid #002F70;padding-bottom:12px;margin-bottom:15px;}
            .header-container img{height:45px;}
            .header-title h1{font-size:16px;margin:0;color:#002F70;text-transform:uppercase;}
            .header-title p{font-size:10px;margin:3px 0 0;color:#666;}
            .meta-info{margin-left:auto;text-align:right;font-size:10px;color:#444;}
            table{width:100%;border-collapse:collapse;font-size:9.5px;}
            thead tr{background:#f2f2f2 !important;border-top:2px solid #002F70;border-bottom:1px solid #999;}
            thead th{padding:6px 5px;text-align:left;font-weight:700;font-size:9px;text-transform:uppercase;color:#000;}
            tbody tr{border-bottom:1px solid #ddd;}
            tbody td{padding:5px;color:#333;}
            .vt-badge, .badge, .status-badge{border:none;background:none;padding:0;font-weight:normal;}
            tfoot tr{border-top:2px solid #002F70;background:#f2f2f2 !important;}
            tfoot td{padding:6px 5px;font-weight:700;}
        </style></head><body>
            <div class="header-container">
                <img src="${logo_url}" alt="Petron">
                <div class="header-title">
                    <h1>Petron Station Management System</h1>
                    <p>Validated Transactions Report</p>
                </div>
                <div class="meta-info">
                    Date Range: ${dateFrom || 'All'} to ${dateTo || 'All'}<br>
                    Generated: ${generated}
                </div>
            </div>
            ${tableHtml}
        </body></html>`);
        doc.close();

        setTimeout(() => {
            iframe.contentWindow.focus();
            iframe.contentWindow.print();
        }, 250);
    }
}
</script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
