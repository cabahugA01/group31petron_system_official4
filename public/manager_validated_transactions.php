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
$mt_shift_col  = "CASE WHEN LOWER(TRIM(COALESCE(mt.shift_period, mt.shift_name, u.assigned_shift, u.shift_assignment, ''))) IN ('first', 'shift 1', 'shift1') THEN 'Shift 1' WHEN LOWER(TRIM(COALESCE(mt.shift_period, mt.shift_name, u.assigned_shift, u.shift_assignment, ''))) IN ('second', 'shift 2', 'shift2') THEN 'Shift 2' ELSE COALESCE(NULLIF(TRIM(mt.shift_period),''), NULLIF(TRIM(mt.shift_name),''), NULLIF(TRIM(u.assigned_shift),''), NULLIF(TRIM(u.shift_assignment),''), 'N/A') END";
$mt_staff_id   = vt_has($mt_cols, 'staff_id') ? 'mt.staff_id' : 'NULL';

$mt_where  = "WHERE mt.station_id = ?";
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
if ($shift_filter !== '') {
    $mt_where .= " AND (mt.shift_period = ? OR u.assigned_shift = ? OR u.shift_assignment = ?)";
    $mt_params[] = $shift_filter;
    $mt_params[] = $shift_filter;
    $mt_params[] = $shift_filter;
}

$mt_rows = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            mt.id AS row_id,
            mt.transaction_id AS txn_id,
            COALESCE(NULLIF(TRIM(mt.customer_name),''),'Walk-in') AS customer,
            CASE 
                WHEN mt.transaction_type = 'combined' THEN 'Combined'
                WHEN mt.transaction_type = 'job_order' THEN 'Job Order'
                ELSE 'Merchandise'
            END AS entry_type,
            GROUP_CONCAT(CONCAT(mti.product_name, ' (x', mti.quantity, ')') ORDER BY mti.id SEPARATOR ', ') AS items_service,
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
        LEFT JOIN merchandise_transaction_items mti ON mti.transaction_id = mt.id
        {$mt_where}
        GROUP BY mt.id
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
$jo_shift_col  = "CASE WHEN LOWER(TRIM(COALESCE(jo.shift_period, jo.shift_name, u.assigned_shift, u.shift_assignment, ''))) IN ('first', 'shift 1', 'shift1') THEN 'Shift 1' WHEN LOWER(TRIM(COALESCE(jo.shift_period, jo.shift_name, u.assigned_shift, u.shift_assignment, ''))) IN ('second', 'shift 2', 'shift2') THEN 'Shift 2' ELSE COALESCE(NULLIF(TRIM(jo.shift_period),''), NULLIF(TRIM(jo.shift_name),''), NULLIF(TRIM(u.assigned_shift),''), NULLIF(TRIM(u.shift_assignment),''), 'N/A') END";
$jo_staff_id   = vt_has($jo_cols, 'created_by') ? 'COALESCE(jo.created_by, jo.user_id)' : 'jo.user_id';

$jo_where  = "WHERE jo.station_id = ?";
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
if ($shift_filter !== '') {
    $jo_where .= " AND (jo.shift_period = ? OR u.assigned_shift = ? OR u.shift_assignment = ?)";
    $jo_params[] = $shift_filter;
    $jo_params[] = $shift_filter;
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

// Pre-fetch items for merchandise_transactions
$mgr_items_map = [];
try {
    $mt_ids = array_column(array_filter($rows, fn($r) => $r['_source'] === 'merchandise_transactions'), 'row_id');
    if (!empty($mt_ids)) {
        $in_pl = implode(',', array_map('intval', $mt_ids));
        $itm_stmt = $pdo->query("
            SELECT transaction_id, product_name, quantity, unit_price, subtotal,
                   COALESCE(item_type,'merchandise') AS item_type,
                   COALESCE(category,'') AS category,
                   COALESCE(size_variant,'') AS size_variant
            FROM merchandise_transaction_items
            WHERE transaction_id IN ($in_pl)
            ORDER BY transaction_id, id ASC
        ");
        foreach ($itm_stmt->fetchAll(PDO::FETCH_ASSOC) as $itm_row) {
            $mgr_items_map[(int)$itm_row['transaction_id']][] = $itm_row;
        }
    }
} catch (Exception $e) { $mgr_items_map = []; }

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
        $items_desc = '';
        if ($r['_source'] === 'merchandise_transactions') {
            $mt_id = (int)$r['row_id'];
            if ($mt_id && !empty($mgr_items_map[$mt_id])) {
                $item_strings = [];
                foreach ($mgr_items_map[$mt_id] as $itm) {
                    $prefix = ($itm['item_type'] === 'service') ? '🔧' : '📦';
                    $qty_str = ($itm['item_type'] !== 'service' && $itm['quantity'] > 0) ? ' (x' . (int)$itm['quantity'] . ')' : '';
                    $item_strings[] = $prefix . ' ' . $itm['product_name'] . $qty_str;
                }
                $items_desc = implode(', ', $item_strings);
            } else {
                $items_desc = $r['items_service'];
            }
        } else {
            $items_desc = '🔧 ' . $r['items_service'];
        }

        fputcsv($out, [
            $r['txn_id'],
            $r['customer'],
            $r['entry_type'],
            $items_desc,
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
        $items_desc = '';
        if ($r['_source'] === 'merchandise_transactions') {
            $mt_id = (int)$r['row_id'];
            if ($mt_id && !empty($mgr_items_map[$mt_id])) {
                $item_strings = [];
                foreach ($mgr_items_map[$mt_id] as $itm) {
                    $prefix = ($itm['item_type'] === 'service') ? '🔧' : '📦';
                    $qty_str = ($itm['item_type'] !== 'service' && $itm['quantity'] > 0) ? ' (x' . (int)$itm['quantity'] . ')' : '';
                    $item_strings[] = $prefix . ' ' . $itm['product_name'] . $qty_str;
                }
                $items_desc = implode(', ', $item_strings);
            } else {
                $items_desc = $r['items_service'];
            }
        } else {
            $items_desc = '🔧 ' . $r['items_service'];
        }

        echo '<tr>';
        echo '<td>' . htmlspecialchars($r['txn_id']) . '</td>';
        echo '<td>' . htmlspecialchars($r['customer']) . '</td>';
        echo '<td>' . htmlspecialchars($r['entry_type']) . '</td>';
        echo '<td>' . htmlspecialchars($items_desc) . '</td>';
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
        $items_desc = '';
        if ($r['_source'] === 'merchandise_transactions') {
            $mt_id = (int)$r['row_id'];
            if ($mt_id && !empty($mgr_items_map[$mt_id])) {
                $item_strings = [];
                foreach ($mgr_items_map[$mt_id] as $itm) {
                    $prefix = ($itm['item_type'] === 'service') ? '🔧' : '📦';
                    $qty_str = ($itm['item_type'] !== 'service' && $itm['quantity'] > 0) ? ' (x' . (int)$itm['quantity'] . ')' : '';
                    $item_strings[] = $prefix . ' ' . $itm['product_name'] . $qty_str;
                }
                $items_desc = implode(', ', $item_strings);
            } else {
                $items_desc = $r['items_service'];
            }
        } else {
            $items_desc = '🔧 ' . $r['items_service'];
        }

        echo '<tr>';
        echo '<td>' . htmlspecialchars($r['txn_id']) . '</td>';
        echo '<td>' . htmlspecialchars($r['customer']) . '</td>';
        echo '<td>' . htmlspecialchars($r['entry_type']) . '</td>';
        echo '<td>' . htmlspecialchars($items_desc) . '</td>';
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
</div>

<?php
// Tab counts
$tab_voided_count = 0; $tab_adj_count = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM voided_transactions WHERE station_id=? AND DATE(void_date) BETWEEN ? AND ?");
    $s->execute([$station_id, date('Y-m-01'), date('Y-m-d')]);
    $tab_voided_count = (int)$s->fetchColumn();
} catch(Exception $e){}
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM transaction_adjustments WHERE station_id=? AND DATE(adjustment_date) BETWEEN ? AND ?");
    $s->execute([$station_id, date('Y-m-01'), date('Y-m-d')]);
    $tab_adj_count = (int)$s->fetchColumn();
} catch(Exception $e){}
?>


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
    <div style="overflow-x:auto;">
    <table class="vt-table" style="table-layout:auto;min-width:100%;">
        <thead>
            <tr>
                <th style="width:10%;">Txn ID</th>
                <th style="width:8%;">Customer</th>
                <th style="width:7%;">Type</th>
                <th style="width:13%;">Items / Service</th>
                <th style="width:8%;">Amount</th>
                <th style="width:8%;">Method</th>
                <th style="width:8%;">Pay Status</th>
                <th style="width:7%;">Shift</th>
                <th style="width:9%;">Date</th>
                <th style="width:8%;">Staff</th>
                <th style="width:14%;text-align:center;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($rows) > 0): ?>
                <?php foreach ($rows as $r): ?>
                <?php 
                    $pay_st = vt_pay_status($r); 
                    
                    // Build items list for this row
                    $rc_row_items = [];
                    if ($r['_source'] === 'merchandise_transactions') {
                        $mt_id = (int)$r['row_id'];
                        if ($mt_id && !empty($mgr_items_map[$mt_id])) {
                            $rc_row_items = $mgr_items_map[$mt_id];
                        } elseif (!empty($r['items_service']) && $r['items_service'] !== 'N/A') {
                            // Legacy fallback
                            $rc_row_items = [[
                                'item_type'    => ($r['entry_type'] === 'Job Order') ? 'service' : 'merchandise',
                                'product_name' => $r['items_service'],
                                'quantity'     => 1,
                                'unit_price'   => $r['amount'] ?? 0,
                                'subtotal'     => $r['amount'] ?? 0,
                                'category'     => '',
                                'size_variant' => '',
                            ]];
                        }
                    } else {
                        // Job order: use items_service
                        if (!empty($r['items_service'])) {
                            $rc_row_items = [[
                                'item_type'    => 'service',
                                'product_name' => $r['items_service'],
                                'quantity'     => 1,
                                'unit_price'   => $r['amount'] ?? 0,
                                'subtotal'     => $r['amount'] ?? 0,
                                'category'     => 'Job Order',
                                'size_variant' => $r['vehicle_plate'] ?? '',
                            ]];
                        }
                    }
                    $expand_id = 'mgre_' . ($r['_source'] === 'job_orders' ? 'jo' : 'mt') . '_' . (int)$r['row_id'];
                    $svc_items   = array_filter($rc_row_items, fn($i) => $i['item_type'] === 'service');
                    $merch_items = array_filter($rc_row_items, fn($i) => $i['item_type'] !== 'service');
                ?>
                <tr class="rc-row-main" onclick="rcToggleExpand('<?= $expand_id ?>')">
                    <td style="font-weight:600;font-size:11px;font-family:monospace;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        <?php echo htmlspecialchars($r['txn_id']); ?>
                    </td>
                    <td style="font-size:10px;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($r['customer']); ?>"><?php echo htmlspecialchars(substr($r['customer'], 0, 12)); ?></td>
                    <td>
                        <?php 
                        $t_label = $r['entry_type']; 
                        $t_class = match(strtolower($t_label)){
                            'combined' => 'vt-badge-combined',
                            'job order' => 'vt-badge-jo',
                            default => 'vt-badge-merch'
                        };
                        ?>
                        <span class="vt-badge <?= $t_class ?>">
                            <?php echo htmlspecialchars($t_label); ?>
                        </span>
                    </td>
                    <td>
                      <?php if (empty($rc_row_items)): ?>
                        <span style="color:#94a3b8;font-size:11px">&mdash;</span>
                      <?php else: ?>
                        <?php foreach ($rc_row_items as $ri): ?>
                          <?php $ri_svc = ($ri['item_type'] === 'service'); ?>
                          <span class="rc-item-chip<?= $ri_svc ? ' svc' : '' ?>">
                            <i class="fas <?= $ri_svc ? 'fa-wrench' : 'fa-box' ?>" style="font-size:9px"></i>
                            <?= htmlspecialchars($ri['product_name']) ?>
                            <?php if (!$ri_svc && (float)$ri['quantity'] > 0): ?>
                              <span class="rc-chip-qty">x<?= (int)$ri['quantity'] ?></span>
                            <?php endif; ?>
                          </span>
                        <?php endforeach; ?>
                        <i class="fas fa-chevron-down" style="font-size:9px;color:#94a3b8;margin-left:4px" id="<?= $expand_id ?>_icon"></i>
                      <?php endif; ?>
                    </td>
                    <td style="font-weight:600;color:#002F70;text-align:right;white-space:nowrap;font-size:10px;">
                        &#8369;<?php echo number_format((float)$r['amount'], 2); ?>
                    </td>
                    <td style="font-size:10px;"><?php echo htmlspecialchars(substr($r['payment_method'], 0, 8)); ?></td>
                    <td>
                        <span class="vt-badge vt-badge-<?php echo strtolower(str_replace(' ', '-', $pay_st)); ?>">
                            <?php echo $pay_st; ?>
                        </span>
                    </td>
                    <td>
                        <?php 
                        // Check validation status for actions
                        $vst = strtolower(trim($r['validation_status'] ?? 'approved'));
                        
                        $s_val = strtolower(trim($r['shift']));
                        $shift_time_label = match($s_val) {
                            'first', 'shift 1' => 'Shift 1',
                            'second', 'shift 2' => 'Shift 2',
                            default => htmlspecialchars($r['shift'] ?: 'N/A')
                        };
                        ?>
                        <span class="vt-badge" style="background:#e0f2fe;color:#0369a1;border-color:#bae6fd;font-size:9px;padding:2px 6px;">
                            <?= $shift_time_label ?>
                        </span>
                    </td>
                    <td style="white-space:nowrap;font-size:10px;color:#64748b;">
                        <?php echo date('M d, H:i', strtotime($r['txn_date'])); ?>
                    </td>
                    <td style="font-size:10px;color:#64748b;"><?php echo htmlspecialchars(substr($r['staff_name'], 0, 12)); ?></td>
                    <td style="text-align:center;padding:6px 4px;" onclick="event.stopPropagation()">
                        <div style="display:flex;flex-direction:column;gap:3px;align-items:stretch;">
                        <button class="vt-btn-action vt-btn-view" onclick="viewValidatedTransaction('<?php echo $r['_source']; ?>', <?php echo $r['row_id']; ?>)" title="View details" style="padding:3px 6px;font-size:9px;width:100%;">
                            <i class="fas fa-eye"></i> View
                        </button>
                        <?php if ($r['_source'] === 'merchandise_transactions' && $vst !== 'voided'): ?>
                        <button class="vt-btn-action" style="color:#d97706;border-color:#d97706;background:white;padding:3px 6px;font-size:9px;width:100%;" onclick="openAdjustModal(<?= $r['row_id'] ?>, '<?= htmlspecialchars(addslashes($r['txn_id'])) ?>', '<?= htmlspecialchars(addslashes($r['customer'])) ?>', '<?= htmlspecialchars(addslashes($r['entry_type'])) ?>', '<?= htmlspecialchars(addslashes($r['txn_date'])) ?>', '<?= htmlspecialchars(addslashes($r['staff_name'])) ?>', '<?= htmlspecialchars(addslashes($r['payment_method'])) ?>', '<?= htmlspecialchars(addslashes($r['payment_status'] ?? 'Paid')) ?>')" title="Adjust">
                            <i class="fas fa-pen"></i> Adjust
                        </button>
                        <button class="vt-btn-action" style="color:#dc2626;border-color:#dc2626;background:white;padding:3px 6px;font-size:9px;width:100%;" onclick="openVoidModal(<?= $r['row_id'] ?>, '<?= htmlspecialchars(addslashes($r['txn_id'])) ?>', '<?= htmlspecialchars(addslashes($r['customer'])) ?>')" title="Void">
                            <i class="fas fa-ban"></i> Void
                        </button>
                        <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php if (!empty($rc_row_items)): ?>
                <tr class="rc-expand-row" id="<?= $expand_id ?>" style="display:none">
                  <td colspan="11" style="background:#f8fafc">
                    <div class="rc-expand-inner">
                      <?php if (!empty($svc_items)): ?>
                      <div style="font-size:11px;font-weight:800;color:#b45309;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px">
                        <i class="fas fa-tools"></i> Services / Job Order
                      </div>
                      <table class="rc-expand-tbl" style="margin-bottom:10px">
                        <thead><tr><th>Service</th><th>Category</th><th>Plate / Note</th><th style="text-align:right">Fee</th></tr></thead>
                        <tbody>
                        <?php foreach ($svc_items as $si): ?>
                        <tr>
                          <td style="font-weight:600"><?= htmlspecialchars($si['product_name']) ?></td>
                          <td style="color:#64748b"><?= htmlspecialchars($si['category'] ?: '&mdash;') ?></td>
                          <td style="color:#64748b;font-size:10px"><?= htmlspecialchars($si['size_variant'] ?: '&mdash;') ?></td>
                          <td style="text-align:right;font-weight:700;color:#002F70">&#8369;<?= number_format((float)$si['subtotal'],2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                      </table>
                      <?php endif; ?>
                      <?php if (!empty($merch_items)): ?>
                      <div style="font-size:11px;font-weight:800;color:#15803d;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px">
                        <i class="fas fa-box"></i> Merchandise Products
                      </div>
                      <table class="rc-expand-tbl">
                        <thead><tr><th>Product</th><th>Size/Variant</th><th style="text-align:center">Qty</th><th style="text-align:right">Unit Price</th><th style="text-align:right">Subtotal</th></tr></thead>
                        <tbody>
                        <?php foreach ($merch_items as $mi): ?>
                        <tr>
                          <td style="font-weight:600"><?= htmlspecialchars($mi['product_name']) ?></td>
                          <td style="color:#64748b;font-size:10px"><?= htmlspecialchars($mi['size_variant'] ?: '&mdash;') ?></td>
                          <td style="text-align:center;font-weight:700"><?= (int)$mi['quantity'] ?></td>
                          <td style="text-align:right;color:#475569">&#8369;<?= number_format((float)$mi['unit_price'],2) ?></td>
                          <td style="text-align:right;font-weight:700;color:#002F70">&#8369;<?= number_format((float)$mi['subtotal'],2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                      </table>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="11" style="text-align:center;padding:60px 20px;color:#94a3b8;">
                        <i class="fas fa-inbox" style="font-size:48px;display:block;margin-bottom:12px;opacity:0.3;"></i>
                        <div style="font-size:16px;font-weight:600;color:#64748b;margin-bottom:4px;">No Transactions Found</div>
                        <div style="font-size:13px;">No transactions found matching your filters.</div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- View Transaction Modal -->
<div class="vt-modal-overlay" id="viewTransactionModal">
    <div class="vt-modal" style="max-width:700px;">
        <div class="vt-modal-header">
            <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:36px;height:36px;background:#eff6ff;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-eye" style="color:#0284c7;font-size:15px;"></i>
                </div>
                <div>
                    <div style="font-size:14px;font-weight:700;color:#1e293b;">Transaction Details</div>
                </div>
            </div>
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

<!-- ═══════════════════════════════════════════════════════════ ADJUST MODAL -->
<div class="vt-modal-overlay" id="adjustModal">
  <div class="vt-modal" style="max-width:720px;">
    <div class="vt-modal-header">
      <div style="display:flex;align-items:center;gap:10px;">
        <div style="width:36px;height:36px;background:#fffbeb;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <i class="fas fa-pen" style="color:#f59e0b;font-size:15px;"></i>
        </div>
        <div>
          <div style="font-size:14px;font-weight:700;color:#1e293b;">Adjust Transaction</div>
        </div>
      </div>
    </div>
    <div class="vt-modal-body" id="adjustModalBody">
      <!-- populated by JS -->
    </div>
    <div class="vt-modal-footer">
      <button type="button" class="vt-btn vt-btn-reset" onclick="closeAdjustModal()">Cancel</button>
      <button type="button" id="saveAdjustBtn" onclick="submitAdjustment()" style="background:#d97706;color:#fff;border:none;height:36px;padding:0 20px;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-save"></i> Save Adjustment</button>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════ VOID MODAL -->
<div class="vt-modal-overlay" id="voidModal">
  <div class="vt-modal" style="max-width:500px;">
    <div class="vt-modal-header">
      <div style="display:flex;align-items:center;gap:10px;">
        <div style="width:36px;height:36px;background:#fef2f2;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <i class="fas fa-ban" style="color:#dc2626;font-size:15px;"></i>
        </div>
        <div>
          <div style="font-size:14px;font-weight:700;color:#1e293b;">Void Transaction</div>
        </div>
      </div>
    </div>
    <div class="vt-modal-body">
      <div id="voidModalInfo" style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#991b1b;"></div>
      <div style="margin-bottom:12px;">
        <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px;">Void Reason <span style="color:#dc2626;">*</span></label>
        <textarea id="voidReason" rows="3" placeholder="Reason for voiding this transaction..." style="width:100%;padding:8px 10px;border:1px solid #fca5a5;border-radius:6px;font-size:13px;resize:vertical;box-sizing:border-box;"></textarea>
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px;">Manager Remarks <span style="color:#dc2626;">*</span></label>
        <textarea id="voidManagerRemarks" rows="2" placeholder="Manager notes..." style="width:100%;padding:8px 10px;border:1px solid #fca5a5;border-radius:6px;font-size:13px;resize:vertical;box-sizing:border-box;"></textarea>
      </div>
      <div style="margin-top:12px;padding:10px;background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;font-size:12px;color:#9a3412;">
        <i class="fas fa-exclamation-triangle"></i> <strong>Warning:</strong> This will permanently void the transaction and restore inventory. This action cannot be undone.
      </div>
    </div>
    <div class="vt-modal-footer">
      <button type="button" class="vt-btn vt-btn-reset" onclick="closeVoidModal()">Cancel</button>
      <button type="button" id="confirmVoidBtn" onclick="submitVoid()" style="background:#dc2626;color:#fff;border:none;height:36px;padding:0 20px;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-ban"></i> Confirm Void</button>
    </div>
  </div>
</div>

<style>
/* == GLOBAL OVERFLOW FIX == */
html, body { 
    overflow-x: hidden !important; 
    max-width: 100vw !important;
    box-sizing: border-box !important;
}
* { 
    box-sizing: border-box !important; 
}

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
.vt-table { width:100%;border-collapse:collapse;font-size:10px;table-layout:fixed; }
.vt-table thead th { 
    background:#002F70;color:#fff;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;padding:7px 6px;border-bottom:2px solid #001a3d;text-align:left;vertical-align:middle;
}
.vt-table tbody td { padding:6px 6px;border-bottom:1px solid #f1f5f9;vertical-align:middle;background:#fff;font-size:10px;word-wrap:break-word;overflow-wrap:break-word;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
.vt-table tbody tr:hover td { background:#eff6ff; }

/* Prevent horizontal scrolling on entire page */
body { overflow-x:hidden !important; max-width:100vw !important; }
.content-wrapper { max-width:100% !important; overflow-x:hidden !important; }
.card { max-width:100% !important; }

/* Make filter responsive */
.vt-filter-card form { max-width:100% !important; overflow-x:hidden !important; }

/* Badges */
.vt-badge { 
    display:inline-block;padding:2px 6px;border-radius:3px;font-size:9px;font-weight:600;white-space:nowrap;background:#f8fafc;color:#64748b;border:1px solid #e2e8f0;
}
.vt-badge-merch { background:#f0fdf4;color:#15803d;border-color:#bbf7d0; }
.vt-badge-jo { background:#fffbeb;color:#b45309;border-color:#fde68a; }
.vt-badge-combined { background:#f5f3ff;color:#6d28d9;border-color:#ddd6fe; }
.vt-badge-paid { background:#f0fdf4;color:#166534;border-color:#bbf7d0; }
.vt-badge-partial { background:#fef3c7;color:#92400e;border-color:#fde047; }
.vt-badge-unpaid { background:#fef2f2;color:#991b1b;border-color:#fecaca; }

/* Item chips & expand rows */
.rc-item-chip{display:inline-flex;align-items:center;gap:3px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:3px;padding:1px 5px;font-size:9px;font-weight:600;color:#374151;margin:1px 2px 1px 0;white-space:nowrap;cursor:pointer}
.rc-item-chip.svc{background:#fffbeb;border-color:#fde68a;color:#92400e}
.rc-item-chip .rc-chip-qty{background:#002F70;color:#fff;border-radius:2px;padding:0 3px;font-size:8px;margin-left:2px}
.rc-expand-row td{background:#f8fafc;padding:0}
.rc-expand-inner{padding:10px 16px;border-top:2px solid #e2e8f0}
.rc-expand-tbl{width:100%;border-collapse:collapse;font-size:11px}
.rc-expand-tbl th{padding:5px 10px;text-align:left;font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid #e2e8f0}
.rc-expand-tbl td{padding:5px 10px;border-bottom:1px solid #f1f5f9}
.rc-expand-tbl tr:last-child td{border-bottom:none}
.rc-row-main{cursor:pointer}
.rc-row-main:hover td{background:#eff6ff !important}

/* Action Buttons */
.vt-btn-action { 
    background: white !important;
    width:100%;
    min-width:60px;
    height:22px;
    border-radius:4px;
    border:1px solid transparent;
    cursor:pointer;
    font-size:9px;
    font-weight:600;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:4px;
    transition:all .15s;
    padding:0 6px;
    white-space:nowrap;
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

/* ─────────────────────────────────────────────── ADJUST MODAL ──────────── */
let _adjRowId = null;
let _adjItems  = []; // fetched items

function openAdjustModal(rowId, txnId, customer, entryType, txnDate, staffName, payMethod, payStat) {
    _adjRowId = rowId;
    _adjItems = [];

    // Show loading state
    document.getElementById('adjustModal').classList.add('active');
    document.getElementById('adjustModalBody').innerHTML =
        '<div style="text-align:center;padding:40px"><i class="fas fa-spinner fa-spin" style="font-size:28px;color:#d97706"></i><div style="margin-top:10px;color:#64748b">Loading items...</div></div>';

    // Fetch items via existing API
    fetch('../backend/api/get_transaction_items.php?id=' + rowId)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                document.getElementById('adjustModalBody').innerHTML =
                    '<div style="color:#dc2626;padding:20px"><i class="fas fa-exclamation-circle"></i> ' + (data.error || 'Failed to load items') + '</div>';
                return;
            }
            _adjItems = data.items || [];
            const fmtDate = txnDate ? new Date(txnDate).toLocaleString('en-PH') : '—';
            let html = `
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px 20px;margin-bottom:16px;padding:14px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;font-size:13px;">
              <div><span style="color:#64748b;font-size:11px;font-weight:700;display:block">Transaction ID</span><strong style="font-family:monospace">${txnId}</strong></div>
              <div><span style="color:#64748b;font-size:11px;font-weight:700;display:block">Customer</span>${customer}</div>
              <div><span style="color:#64748b;font-size:11px;font-weight:700;display:block">Type</span>${entryType}</div>
              <div><span style="color:#64748b;font-size:11px;font-weight:700;display:block">Date</span>${fmtDate}</div>
              <div><span style="color:#64748b;font-size:11px;font-weight:700;display:block">Staff Encoder</span>${staffName}</div>
            </div>
            <div style="margin-bottom:14px;">
              <div style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px">
                <i class="fas fa-edit"></i> Edit Items
              </div>
              <table style="width:100%;border-collapse:collapse;font-size:12px;">
                <thead><tr style="background:#f8fafc;">
                  <th style="padding:8px 10px;text-align:left;border-bottom:2px solid #e2e8f0;color:#64748b;font-size:11px;font-weight:700;text-transform:uppercase">Product / Service</th>
                  <th style="padding:8px 10px;text-align:left;border-bottom:2px solid #e2e8f0;color:#64748b;font-size:11px;font-weight:700;text-transform:uppercase">Type</th>
                  <th style="padding:8px 10px;text-align:center;border-bottom:2px solid #e2e8f0;color:#64748b;font-size:11px;font-weight:700;text-transform:uppercase">Qty</th>
                  <th style="padding:8px 10px;text-align:right;border-bottom:2px solid #e2e8f0;color:#64748b;font-size:11px;font-weight:700;text-transform:uppercase">Unit Price</th>
                  <th style="padding:8px 10px;text-align:right;border-bottom:2px solid #e2e8f0;color:#64748b;font-size:11px;font-weight:700;text-transform:uppercase">Subtotal</th>
                </tr></thead>
                <tbody id="adjItemsBody">`;

            if (_adjItems.length === 0) {
                html += `<tr><td colspan="5" style="padding:20px;text-align:center;color:#94a3b8;">No items found</td></tr>`;
            } else {
                _adjItems.forEach((item, idx) => {
                    const isSvc = item.item_type === 'service';
                    html += `
                    <tr style="border-bottom:1px solid #f1f5f9;">
                      <td style="padding:8px 10px;font-weight:600">${item.product_name}</td>
                      <td style="padding:8px 10px;">
                        <span style="background:${isSvc?'#fffbeb':'#f0fdf4'};color:${isSvc?'#b45309':'#15803d'};padding:2px 8px;border-radius:4px;font-size:10px;font-weight:700">
                          ${isSvc ? '🔧 Service' : '📦 Merchandise'}
                        </span>
                      </td>
                      <td style="padding:8px 10px;text-align:center;">
                        <input type="number" min="0" step="0.01"
                          id="adj_qty_${idx}" value="${parseFloat(item.quantity)}"
                          onchange="recalcAdjRow(${idx})"
                          style="width:70px;padding:4px 6px;border:1px solid #cbd5e1;border-radius:5px;text-align:center;font-size:12px;">
                      </td>
                      <td style="padding:8px 10px;text-align:right;">
                        <input type="number" min="0" step="0.01"
                          id="adj_price_${idx}" value="${parseFloat(item.unit_price)}"
                          onchange="recalcAdjRow(${idx})"
                          style="width:90px;padding:4px 6px;border:1px solid #cbd5e1;border-radius:5px;text-align:right;font-size:12px;">
                      </td>
                      <td style="padding:8px 10px;text-align:right;font-weight:700;color:#002F70" id="adj_sub_${idx}">
                        ₱${parseFloat(item.subtotal).toFixed(2)}
                      </td>
                    </tr>`;
                });
            }
            html += `</tbody>
                <tfoot>
                  <tr style="border-top:2px solid #e2e8f0;background:#f8fafc;">
                    <td colspan="4" style="padding:8px 10px;text-align:right;font-weight:700;font-size:13px;">New Total:</td>
                    <td style="padding:8px 10px;text-align:right;font-weight:800;font-size:14px;color:#002F70" id="adjNewTotal">₱0.00</td>
                  </tr>
                </tfoot>
              </table>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
              <div>
                <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px">Payment Method</label>
                <select id="adjPayMethod" style="width:100%;height:36px;padding:0 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;background:#fff">
                  ${['Cash','GCash','Maya','Bank Transfer','Credit','Card','E-Wallet','Petron E-Fuel','Fleet Card'].map(m =>
                    `<option value="${m}" ${m===payMethod?'selected':''}>${m}</option>`).join('')}
                </select>
              </div>
              <div>
                <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px">Payment Status</label>
                <select id="adjPayStatus" style="width:100%;height:36px;padding:0 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;background:#fff">
                  ${['Paid','Unpaid','Partial Payment','Credit Account'].map(s =>
                    `<option value="${s}" ${s===payStat?'selected':''}>${s}</option>`).join('')}
                </select>
              </div>
            </div>
            <div style="margin-bottom:12px;">
              <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px">Adjustment Reason <span style="color:#dc2626">*</span></label>
              <textarea id="adjReason" rows="2" placeholder="Why is this adjustment being made?" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;resize:vertical;box-sizing:border-box;"></textarea>
            </div>
            <div>
              <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px">Manager Remarks <span style="color:#dc2626">*</span></label>
              <textarea id="adjManagerRemarks" rows="2" placeholder="Manager's notes on this adjustment..." style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;resize:vertical;box-sizing:border-box;"></textarea>
            </div>`;

            document.getElementById('adjustModalBody').innerHTML = html;
            recalcAdjTotal();
        })
        .catch(() => {
            document.getElementById('adjustModalBody').innerHTML =
                '<div style="color:#dc2626;padding:20px"><i class="fas fa-exclamation-circle"></i> Connection error. Please try again.</div>';
        });
}

function recalcAdjRow(idx) {
    const qty   = parseFloat(document.getElementById('adj_qty_'   + idx)?.value || 0);
    const price = parseFloat(document.getElementById('adj_price_' + idx)?.value || 0);
    const sub   = qty * price;
    const subEl = document.getElementById('adj_sub_' + idx);
    if (subEl) subEl.textContent = '₱' + sub.toFixed(2);
    recalcAdjTotal();
}

function recalcAdjTotal() {
    let total = 0;
    (_adjItems || []).forEach((_, idx) => {
        const qty   = parseFloat(document.getElementById('adj_qty_'   + idx)?.value || 0);
        const price = parseFloat(document.getElementById('adj_price_' + idx)?.value || 0);
        total += qty * price;
    });
    const el = document.getElementById('adjNewTotal');
    if (el) el.textContent = '₱' + total.toFixed(2);
}

function closeAdjustModal() {
    document.getElementById('adjustModal').classList.remove('active');
    _adjRowId = null;
    _adjItems = [];
}

function submitAdjustment() {
    const reason  = document.getElementById('adjReason')?.value.trim();
    const remarks = document.getElementById('adjManagerRemarks')?.value.trim();
    if (!reason)  { alert('Please enter the Adjustment Reason.'); return; }
    if (!remarks) { alert('Please enter Manager Remarks.'); return; }

    const btn = document.getElementById('saveAdjustBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

    const itemsPayload = (_adjItems || []).map((item, idx) => ({
        item_id   : item.id,
        quantity  : parseFloat(document.getElementById('adj_qty_'   + idx)?.value || item.quantity),
        unit_price: parseFloat(document.getElementById('adj_price_' + idx)?.value || item.unit_price),
    }));

    fetch('../backend/api/save_adjustment.php', {
        method : 'POST',
        headers: { 'Content-Type': 'application/json' },
        body   : JSON.stringify({
            row_id            : _adjRowId,
            payment_method    : document.getElementById('adjPayMethod')?.value  || '',
            payment_status    : document.getElementById('adjPayStatus')?.value  || '',
            adjustment_reason : reason,
            manager_remarks   : remarks,
            items             : itemsPayload,
        }),
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Adjustment';
        if (data.success) {
            closeAdjustModal();
            showToast('success', '✅ Transaction adjusted successfully. Old total: ₱' + parseFloat(data.old_total||0).toFixed(2) + ' → New total: ₱' + parseFloat(data.new_total||0).toFixed(2));
            setTimeout(() => location.reload(), 2000);
        } else {
            alert('Error: ' + (data.error || 'Adjustment failed. Please try again.'));
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Adjustment';
        alert('Connection error. Please try again.');
    });
}

/* ─────────────────────────────────────────────── VOID MODAL ────────────── */
let _voidRowId = null;

function openVoidModal(rowId, txnId, customer) {
    _voidRowId = rowId;
    document.getElementById('voidReason').value          = '';
    document.getElementById('voidManagerRemarks').value  = '';
    document.getElementById('voidModalInfo').innerHTML   =
        `<i class="fas fa-ban"></i> You are about to void <strong>${txnId}</strong> — Customer: <strong>${customer}</strong>.<br>Inventory will be restored automatically.`;
    document.getElementById('voidModal').classList.add('active');
}

function closeVoidModal() {
    document.getElementById('voidModal').classList.remove('active');
    _voidRowId = null;
}

function submitVoid() {
    const reason  = document.getElementById('voidReason')?.value.trim();
    const remarks = document.getElementById('voidManagerRemarks')?.value.trim();
    if (!reason)  { alert('Please enter the Void Reason.'); return; }
    if (!remarks) { alert('Please enter Manager Remarks.'); return; }

    const btn = document.getElementById('confirmVoidBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

    fetch('../backend/api/void_transaction_manager.php', {
        method : 'POST',
        headers: { 'Content-Type': 'application/json' },
        body   : JSON.stringify({
            row_id          : _voidRowId,
            void_reason     : reason,
            manager_remarks : remarks,
        }),
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-ban"></i> Confirm Void';
        if (data.success) {
            closeVoidModal();
            showToast('success', '✅ ' + (data.message || 'Transaction voided successfully.'));
            setTimeout(() => location.reload(), 2000);
        } else {
            alert('Error: ' + (data.error || 'Void failed. Please try again.'));
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-ban"></i> Confirm Void';
        alert('Connection error. Please try again.');
    });
}

/* ─────────────────────────────────────────────── TOAST ─────────────────── */
function showToast(type, message) {
    let toast = document.getElementById('mgr_toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'mgr_toast';
        toast.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:99999;padding:14px 20px;border-radius:10px;font-size:13px;font-weight:600;box-shadow:0 4px 20px rgba(0,0,0,.2);max-width:420px;transition:opacity .3s;';
        document.body.appendChild(toast);
    }
    toast.style.background = type === 'success' ? '#f0fdf4' : '#fef2f2';
    toast.style.color      = type === 'success' ? '#166534' : '#991b1b';
    toast.style.border     = type === 'success' ? '1px solid #bbf7d0' : '1px solid #fecaca';
    toast.style.opacity    = '1';
    toast.innerHTML        = message;
    clearTimeout(toast._timer);
    toast._timer = setTimeout(() => { toast.style.opacity = '0'; }, 4000);
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
<script src="../assets/vendor/xlsx/xlsx.full.min.js"></script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
<script>
function rcToggleExpand(id) {
  var row  = document.getElementById(id);
  var icon = document.getElementById(id + '_icon');
  if (!row) return;
  var open = row.style.display !== 'none';
  row.style.display = open ? 'none' : '';
  if (icon) icon.style.transform = open ? '' : 'rotate(180deg)';
}
</script>
