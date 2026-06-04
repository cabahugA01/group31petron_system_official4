<?php
$page_id = 'ato_oversight_dashboard';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/transaction_schema_fix.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();

if (!in_array($role, ['admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: admin_dashboard.php'); exit;
}

// ── POST: Admin transaction actions ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['export'])) {
    $post_action = $_POST['action'] ?? '';

    // Detect available columns dynamically
    $mt_cols_post = [];
    try {
        foreach ($pdo->query("SHOW COLUMNS FROM merchandise_transactions")->fetchAll(PDO::FETCH_ASSOC) as $c)
            $mt_cols_post[strtolower($c['Field'])] = true;
    } catch (Exception $e) {}
    $has_mt = fn($c) => isset($mt_cols_post[strtolower($c)]);

    $jo_cols_post = [];
    try {
        foreach ($pdo->query("SHOW COLUMNS FROM job_orders")->fetchAll(PDO::FETCH_ASSOC) as $c)
            $jo_cols_post[strtolower($c['Field'])] = true;
    } catch (Exception $e) {}
    $has_jo = fn($c) => isset($jo_cols_post[strtolower($c)]);

    // Safe audit trail insert
    $insert_audit = function(int $txn_id, string $action_type, ?string $new_val = null) use ($pdo, $me, $station_id) {
        try {
            $pdo->prepare("INSERT INTO audit_trail (transaction_id, manager_id, action_type, new_value, station_id) VALUES (?, ?, ?, ?, ?)")
                ->execute([$txn_id, $me['id'], $action_type, $new_val, $station_id]);
        } catch (Exception $ae) {}
    };

    // ── Approve Merchandise Transaction ──────────────────────────────────────
    // Admin oversight only acts on records already validated by Manager.
    // Raw 'Pending' staff encodings must go through Manager first.
    if ($post_action === 'approve_transaction') {
        $row_id = (int)($_POST['transaction_id'] ?? 0);
        try {
            // Guard: ensure this record has already passed Manager validation
            $chk = $pdo->prepare("SELECT validation_status FROM merchandise_transactions WHERE id = ? AND station_id = ? LIMIT 1");
            $chk->execute([$row_id, $station_id]);
            $cur_status = strtolower(trim($chk->fetchColumn() ?: ''));
            if (in_array($cur_status, ['pending', ''])) {
                $_SESSION['error'] = 'Transaction #' . $row_id . ' is still pending Manager validation. Admin cannot act on unvalidated staff encodings.';
                header('Location: admin_transactions_oversight.php?' . http_build_query(array_filter(['start'=>$_POST['_start']??'','end'=>$_POST['_end']??'','status'=>$_POST['_status']??'','type'=>$_POST['_type']??'','search'=>$_POST['_search']??''])));
                exit;
            }
            $set_parts = ["validation_status = 'Approved'"];
            $set_vals  = [];
            if ($has_mt('validated_by')) { $set_parts[] = "validated_by = ?"; $set_vals[] = $me['id']; }
            if ($has_mt('validated_at')) { $set_parts[] = "validated_at = NOW()"; }
            if ($has_mt('updated_at'))   { $set_parts[] = "updated_at = NOW()"; }
            $stmt = $pdo->prepare("UPDATE merchandise_transactions SET " . implode(', ', $set_parts) . " WHERE id = ? AND station_id = ?");
            $stmt->execute(array_merge($set_vals, [$row_id, $station_id]));
            if ($stmt->rowCount() > 0) {
                $insert_audit($row_id, 'Approve');
                log_activity($pdo, $me['id'], 'Approve Transaction', "Merchandise transaction #{$row_id} approved by admin {$me['name']}");
                $_SESSION['success'] = 'Transaction approved successfully.';
            } else {
                $_SESSION['error'] = 'Transaction not found or already processed.';
            }
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error approving: ' . $e->getMessage();
        }
        header('Location: admin_transactions_oversight.php?' . http_build_query(array_filter(['start'=>$_POST['_start']??'','end'=>$_POST['_end']??'','status'=>$_POST['_status']??'','type'=>$_POST['_type']??'','search'=>$_POST['_search']??''])));
        exit;
    }

    // ── Reject Merchandise Transaction ───────────────────────────────────────
    if ($post_action === 'reject_transaction') {
        $row_id = (int)($_POST['transaction_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        try {
            $set_parts = ["validation_status = 'Rejected'"];
            $set_vals  = [];
            if ($has_mt('validated_by'))     { $set_parts[] = "validated_by = ?";     $set_vals[] = $me['id']; }
            if ($has_mt('validated_at'))     { $set_parts[] = "validated_at = NOW()"; }
            if ($has_mt('rejection_reason')) { $set_parts[] = "rejection_reason = ?"; $set_vals[] = $reason; }
            elseif ($has_mt('remarks'))      { $set_parts[] = "remarks = ?";          $set_vals[] = 'RETURNED: ' . $reason; }
            if ($has_mt('updated_at'))       { $set_parts[] = "updated_at = NOW()"; }
            $stmt = $pdo->prepare("UPDATE merchandise_transactions SET " . implode(', ', $set_parts) . " WHERE id = ? AND station_id = ?");
            $stmt->execute(array_merge($set_vals, [$row_id, $station_id]));
            if ($stmt->rowCount() > 0) {
                $insert_audit($row_id, 'Return', $reason);
                log_activity($pdo, $me['id'], 'Return Transaction', "Merchandise transaction #{$row_id} returned by admin {$me['name']}. Reason: {$reason}");
                $_SESSION['success'] = 'Transaction returned to staff for correction.';
            } else {
                $_SESSION['error'] = 'Transaction not found or already processed.';
            }
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error returning transaction: ' . $e->getMessage();
        }
        header('Location: admin_transactions_oversight.php?' . http_build_query(array_filter(['start'=>$_POST['_start']??'','end'=>$_POST['_end']??'','status'=>$_POST['_status']??'','type'=>$_POST['_type']??'','search'=>$_POST['_search']??''])));
        exit;
    }

    // ── Adjust Merchandise Transaction ────────────────────────────────────────
    if ($post_action === 'adjust_transaction') {
        $row_id    = (int)($_POST['transaction_id'] ?? 0);
        $new_total = (float)($_POST['adj_total'] ?? 0);
        $adj_note  = trim($_POST['adj_note'] ?? '');
        try {
            $set_parts = ["total_amount = ?", "validation_status = 'Adjusted'"];
            $set_vals  = [$new_total];
            if ($has_mt('validated_by')) { $set_parts[] = "validated_by = ?"; $set_vals[] = $me['id']; }
            if ($has_mt('validated_at')) { $set_parts[] = "validated_at = NOW()"; }
            if ($has_mt('remarks'))      { $set_parts[] = "remarks = ?"; $set_vals[] = 'ADJUSTED: ' . $adj_note; }
            if ($has_mt('updated_at'))   { $set_parts[] = "updated_at = NOW()"; }
            $stmt = $pdo->prepare("UPDATE merchandise_transactions SET " . implode(', ', $set_parts) . " WHERE id = ? AND station_id = ?");
            $stmt->execute(array_merge($set_vals, [$row_id, $station_id]));
            if ($stmt->rowCount() > 0) {
                $insert_audit($row_id, 'Adjust', "New total: ₱{$new_total}. Note: {$adj_note}");
                log_activity($pdo, $me['id'], 'Adjust Transaction', "Merchandise #{$row_id} adjusted to ₱{$new_total} by admin {$me['name']}.");
                $_SESSION['success'] = "Transaction #{$row_id} adjusted successfully.";
            } else {
                $_SESSION['error'] = 'Transaction not found or already processed.';
            }
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error adjusting: ' . $e->getMessage();
        }
        header('Location: admin_transactions_oversight.php?' . http_build_query(array_filter(['start'=>$_POST['_start']??'','end'=>$_POST['_end']??'','status'=>$_POST['_status']??'','type'=>$_POST['_type']??'','search'=>$_POST['_search']??''])));
        exit;
    }

    // ── Approve Job Order ─────────────────────────────────────────────────────
    if ($post_action === 'approve_job_order') {
        $jo_id  = (int)($_POST['jo_id'] ?? 0);
        $jo_src = $_POST['jo_source'] ?? 'job_orders';
        try {
            $pdo->beginTransaction();
            if ($jo_src === 'merchandise_transactions') {
                $pdo->prepare("UPDATE merchandise_transactions SET validation_status='Approved', validated_by=?, validated_at=NOW(), updated_at=NOW() WHERE id=? AND station_id=?")
                    ->execute([$me['id'], $jo_id, $station_id]);
            } else {
                $pdo->prepare("UPDATE job_orders SET validation_status='Approved', status='Pending', validated_by=?, validated_at=NOW() WHERE id=? AND station_id=?")
                    ->execute([$me['id'], $jo_id, $station_id]);
                try { $pdo->prepare("INSERT INTO job_order_audit (job_order_id,action,before_status,after_status,performed_by,performed_at,notes,ip_address,user_agent) VALUES (?,?,?,?,?,NOW(),?,?,?)")
                    ->execute([$jo_id,'APPROVE','Pending Validation','Approved',$me['id'],'Approved by admin',$_SERVER['REMOTE_ADDR']??'',$_SERVER['HTTP_USER_AGENT']??'']); } catch(Exception $ae){}
            }
            $insert_audit($jo_id, 'Approve', "JO Approved by admin.");
            log_activity($pdo, $me['id'], 'JO_APPROVED', "Job Order #{$jo_id} approved by admin {$me['name']}.");
            $pdo->commit();
            $_SESSION['success'] = "Job Order #{$jo_id} approved successfully.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = 'Error approving JO: ' . $e->getMessage();
        }
        header('Location: admin_transactions_oversight.php?' . http_build_query(array_filter(['tab'=>'transactions','start'=>$_POST['_start']??'','end'=>$_POST['_end']??''])));
        exit;
    }

    // ── Reject Job Order ──────────────────────────────────────────────────────
    if ($post_action === 'reject_job_order') {
        $jo_id  = (int)($_POST['jo_id'] ?? 0);
        $jo_src = $_POST['jo_source'] ?? 'job_orders';
        $reason = trim($_POST['reason'] ?? '');
        try {
            $pdo->beginTransaction();
            if ($jo_src === 'merchandise_transactions') {
                $pdo->prepare("UPDATE merchandise_transactions SET validation_status='Rejected', validated_by=?, validated_at=NOW(), updated_at=NOW() WHERE id=? AND station_id=?")
                    ->execute([$me['id'], $jo_id, $station_id]);
            } else {
                $pdo->prepare("UPDATE job_orders SET validation_status='Rejected', status='Cancelled', validated_by=?, validated_at=NOW() WHERE id=? AND station_id=?")
                    ->execute([$me['id'], $jo_id, $station_id]);
                try { $pdo->prepare("INSERT INTO job_order_audit (job_order_id,action,before_status,after_status,performed_by,performed_at,notes,ip_address,user_agent) VALUES (?,?,?,?,?,NOW(),?,?,?)")
                    ->execute([$jo_id,'REJECT','Pending Validation','Rejected',$me['id'],$reason,$_SERVER['REMOTE_ADDR']??'',$_SERVER['HTTP_USER_AGENT']??'']); } catch(Exception $ae){}
            }
            $insert_audit($jo_id, 'Reject', "JO Rejected. Reason: {$reason}");
            log_activity($pdo, $me['id'], 'JO_REJECTED', "Job Order #{$jo_id} rejected by admin {$me['name']}. Reason: {$reason}");
            $pdo->commit();
            $_SESSION['success'] = "Job Order #{$jo_id} rejected.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = 'Error rejecting JO: ' . $e->getMessage();
        }
        header('Location: admin_transactions_oversight.php?' . http_build_query(array_filter(['tab'=>'transactions','start'=>$_POST['_start']??'','end'=>$_POST['_end']??''])));
        exit;
    }

    // ── Fuel transactions removed ─────────────────────────────────────────────
    // Admin Oversight Dashboard shows ONLY Merchandise + Job Orders (NO Fuel).
    // Fuel variance is monitored in separate admin_variance_reports.php page.
}

// ── Dynamic column detection ──────────────────────────────────────────────────
function ato_cols(PDO $pdo, string $table): array {
    try {
        $rows = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $r) $map[strtolower($r['Field'])] = true;
        return $map;
    } catch (Exception $e) { return []; }
}
function ato_has(array $map, string $col): bool { return isset($map[strtolower($col)]); }

$mt_cols = ato_cols($pdo, 'merchandise_transactions');
$jo_cols = ato_cols($pdo, 'job_orders');

// ── Payment status helper ─────────────────────────────────────────────────────
// Derives Paid / Partial / Unpaid from amount_paid vs total_amount columns if available.
// Falls back to payment_method presence as a proxy.
function ato_pay_status(array $row): string {
    $total = (float)($row['amount'] ?? 0);
    $paid  = isset($row['amount_paid']) ? (float)$row['amount_paid'] : null;
    if ($paid === null) {
        // proxy: if payment_method is set and not 'N/A' assume Paid
        $pm = strtolower(trim($row['payment_method'] ?? ''));
        return ($pm !== '' && $pm !== 'n/a') ? 'Paid' : 'Unpaid';
    }
    if ($paid <= 0)            return 'Unpaid';
    if ($paid < $total - 0.01) return 'Partial';
    return 'Paid';
}

// ── Filters ───────────────────────────────────────────────────────────────────
// Admin Oversight Dashboard: ONLY Merchandise + Job Orders (NO Fuel transactions)
$start      = $_GET['start']  ?? date('Y-m-d', strtotime('-30 days'));
$end        = $_GET['end']    ?? date('Y-m-d');
$search     = trim($_GET['search'] ?? '');
$status_f   = trim($_GET['status'] ?? '');
$type_f     = trim($_GET['type']   ?? ''); // 'merchandise' | 'job_order' | ''

// ── Export header (early, before any output) ─────────────────────────────────
$export_type = $_GET['export'] ?? '';
$is_export   = in_array($export_type, ['excel', 'csv']);
if ($export_type === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="transactions_oversight_' . date('Y-m-d') . '.xls"');
    header('Pragma: no-cache');
} elseif ($export_type === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="transactions_oversight_' . date('Y-m-d_His') . '.csv"');
    header('Pragma: no-cache');
}

// ── Fetch unified Merchandise + Job Orders (NO Fuel) ──────────────────────────
$rows         = [];
$total_amount = 0.0;

// ── Merchandise rows ──────────────────────────────────────────────────────
$mt_status_col  = ato_has($mt_cols, 'validation_status') ? 'mt.validation_status' : "'Pending'";
$mt_staff_col   = ato_has($mt_cols, 'staff_id')          ? 'u.name'               : "'Unknown'";
$mt_date_col    = "CASE WHEN mt.transaction_date > '2000-01-01' THEN mt.transaction_date ELSE mt.created_at END";
$mt_paid_col    = ato_has($mt_cols, 'amount_paid')        ? 'mt.amount_paid'       : 'NULL';

$mt_where  = "WHERE mt.station_id = ? AND DATE({$mt_date_col}) BETWEEN ? AND ?";
$mt_params = [$station_id, $start, $end];
if ($search !== '') {
    $mt_where .= " AND (mt.customer_name LIKE ? OR mt.transaction_id LIKE ?)";
    $mt_params[] = "%$search%"; $mt_params[] = "%$search%";
}
if ($status_f !== '') {
    $mt_where .= " AND LOWER(TRIM(COALESCE(mt.validation_status,''))) = LOWER(?)";
    $mt_params[] = $status_f;
} else {
    // Admin Oversight: only show APPROVED and COMPLETED transactions validated by Manager
    $mt_where .= " AND LOWER(TRIM(COALESCE(mt.validation_status,''))) IN ('approved', 'completed')";
}

$mt_rows = [];
if ($type_f === '' || $type_f === 'merchandise' || $type_f === 'job_order' || $type_f === 'jo_merchandise') {
    try {
        // Detect if job_order_service column exists for combined detection
        $mt_jo_svc_col = ato_has($mt_cols, 'job_order_service') ? "COALESCE(mt.job_order_service,'')" : "''";
        $mt_jo_veh_col = ato_has($mt_cols, 'job_order_vehicle_plate') ? "COALESCE(mt.job_order_vehicle_plate,'')" : "''";

        $stmt = $pdo->prepare("
            SELECT
                mt.id                                                   AS row_id,
                mt.transaction_id                                       AS txn_id,
                COALESCE(NULLIF(TRIM(mt.customer_name),''),'Walk-in')  AS customer,
                CASE
                    WHEN (
                        TRIM(COALESCE({$mt_jo_svc_col},'')) <> ''
                        OR (SELECT COUNT(*) FROM merchandise_transaction_items i
                            WHERE i.transaction_id = mt.id AND i.item_type = 'service') > 0
                    ) AND (
                        (SELECT COUNT(*) FROM merchandise_transaction_items i2
                         WHERE i2.transaction_id = mt.id AND COALESCE(i2.item_type,'merchandise') = 'merchandise') > 0
                    ) THEN 'JO + Merchandise'
                    WHEN (
                        TRIM(COALESCE({$mt_jo_svc_col},'')) <> ''
                        OR (SELECT COUNT(*) FROM merchandise_transaction_items i3
                            WHERE i3.transaction_id = mt.id AND i3.item_type = 'service') > 0
                    ) THEN 'Job Order'
                    ELSE 'Merchandise'
                END                                                     AS entry_type,
                COALESCE(
                    NULLIF((SELECT GROUP_CONCAT(i.product_name ORDER BY i.id SEPARATOR ', ')
                            FROM merchandise_transaction_items i WHERE i.transaction_id = mt.id),''),
                    NULLIF({$mt_jo_svc_col},''),
                    mt.item_sku, 'N/A'
                )                                                       AS items_service,
                mt.total_amount                                         AS amount,
                {$mt_paid_col}                                          AS amount_paid,
                COALESCE(mt.payment_method,'Cash')                      AS payment_method,
                {$mt_date_col}                                          AS txn_date,
                COALESCE({$mt_status_col},'Pending')                    AS validation_status,
                COALESCE({$mt_staff_col},'Unknown')                     AS staff_name,
                'merchandise_transactions'                              AS _source
            FROM merchandise_transactions mt
            LEFT JOIN users u ON u.id = mt.staff_id
            {$mt_where}
            GROUP BY mt.id
            ORDER BY txn_date DESC
        ");
        $stmt->execute($mt_params);
        $mt_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Filter by specific type if selected
        if ($type_f === 'merchandise') {
            $mt_rows = array_filter($mt_rows, fn($r) => $r['entry_type'] === 'Merchandise');
        } elseif ($type_f === 'job_order') {
            $mt_rows = array_filter($mt_rows, fn($r) => $r['entry_type'] === 'Job Order');
        } elseif ($type_f === 'jo_merchandise') {
            $mt_rows = array_filter($mt_rows, fn($r) => $r['entry_type'] === 'JO + Merchandise');
        }
    } catch (Exception $e) { $mt_rows = []; }
}

// ── Job Order rows ────────────────────────────────────────────────────────
$jo_status_col   = ato_has($jo_cols, 'validation_status') ? 'jo.validation_status' : 'jo.status';
$jo_staff_col    = ato_has($jo_cols, 'created_by')        ? 'COALESCE(jo.created_by, jo.user_id)' : 'jo.user_id';
$jo_mechanic_col = ato_has($jo_cols, 'assigned_mechanic_id') ? 'COALESCE(m.name,\'\')' : "''";
$jo_pay_col      = ato_has($jo_cols, 'payment_method')    ? 'COALESCE(jo.payment_method,\'N/A\')' : "'N/A'";
$jo_cost_col     = ato_has($jo_cols, 'total_cost')        ? 'COALESCE(jo.total_cost,0)' : 'COALESCE(jo.estimated_cost,0)';
$jo_paid_col     = ato_has($jo_cols, 'amount_paid')       ? 'jo.amount_paid' : 'NULL';
$mechanic_join   = ato_has($jo_cols, 'assigned_mechanic_id') ? "LEFT JOIN users m ON m.id = jo.assigned_mechanic_id" : "";

$jo_where  = "WHERE jo.station_id = ? AND DATE(jo.created_at) BETWEEN ? AND ?";
$jo_params = [$station_id, $start, $end];
if ($search !== '') {
    $jo_where .= " AND (jo.customer_name LIKE ? OR jo.service_type LIKE ? OR jo.vehicle_plate LIKE ?)";
    $jo_params[] = "%$search%"; $jo_params[] = "%$search%"; $jo_params[] = "%$search%";
}
if ($status_f !== '') {
    $jo_where .= " AND LOWER(TRIM(COALESCE({$jo_status_col},''))) = LOWER(?)";
    $jo_params[] = $status_f;
} else {
    // Admin Oversight: only show APPROVED and COMPLETED job orders validated by Manager
    $jo_where .= " AND LOWER(TRIM(COALESCE({$jo_status_col},''))) IN ('approved', 'completed')";
}

$jo_rows = [];
if ($type_f === '' || $type_f === 'job_order') {
    try {
        $stmt = $pdo->prepare("
            SELECT
                jo.id                                                        AS row_id,
                CONCAT('JO-', jo.id)                                        AS txn_id,
                COALESCE(NULLIF(TRIM(jo.customer_name),''),'Walk-in')       AS customer,
                'Job Order'                                                  AS entry_type,
                CONCAT(
                    COALESCE(jo.service_type,'Service'),
                    CASE WHEN jo.vehicle_plate IS NOT NULL AND jo.vehicle_plate != ''
                         THEN CONCAT(' | ', jo.vehicle_plate) ELSE '' END,
                    CASE WHEN {$jo_mechanic_col} != ''
                         THEN CONCAT(' | Mech: ', {$jo_mechanic_col}) ELSE '' END
                )                                                            AS items_service,
                {$jo_cost_col}                                               AS amount,
                {$jo_paid_col}                                               AS amount_paid,
                {$jo_pay_col}                                                AS payment_method,
                jo.created_at                                                AS txn_date,
                COALESCE(NULLIF(TRIM({$jo_status_col}),''),'Pending')        AS validation_status,
                COALESCE(u.name,'Unknown')                                   AS staff_name,
                'job_orders'                                                 AS _source
            FROM job_orders jo
            LEFT JOIN users u ON u.id = {$jo_staff_col}
            {$mechanic_join}
            {$jo_where}
            ORDER BY jo.created_at DESC
            LIMIT 500
        ");
        $stmt->execute($jo_params);
        $jo_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $jo_rows = []; }
}

// Merge and sort by date desc
$rows = array_merge($mt_rows, $jo_rows);
usort($rows, fn($a, $b) => strtotime($b['txn_date']) - strtotime($a['txn_date']));

foreach ($rows as $r) $total_amount += (float)($r['amount'] ?? 0);

// ── Status counts ─────────────────────────────────────────────────────────────
$status_counts = [];
$type_counts   = [];
foreach ($rows as $r) {
    $s = strtolower(trim($r['validation_status'] ?? 'pending'));
    $status_counts[$s] = ($status_counts[$s] ?? 0) + 1;
    $t = $r['entry_type'] ?? '';
    $type_counts[$t] = ($type_counts[$t] ?? 0) + 1;
}

// ── Export output ────────────────────────────────────────────────────────────
if ($export_type === 'csv') {
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Transaction ID', 'Customer', 'Type', 'Items/Service', 'Amount', 'Payment Method', 'Payment Status', 'Validation Status', 'Date/Time', 'Staff']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['txn_id'],
            $r['customer'],
            $r['entry_type'],
            $r['items_service'],
            number_format((float)$r['amount'], 2),
            $r['payment_method'],
            ato_pay_status($r),
            $r['validation_status'],
            date('M d, Y H:i', strtotime($r['txn_date'])),
            $r['staff_name'],
        ]);
    }
    fclose($out);
    exit;
}

if ($export_type === 'excel') {
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="UTF-8">';
    echo '<style>table{border-collapse:collapse}th,td{border:1px solid #ddd;padding:7px}th{background:#002F70;color:#fff;font-weight:700}</style>';
    echo '</head><body>';
    echo '<h2>Transactions Oversight Report</h2>';
    echo '<p>Generated: ' . date('F d, Y h:i A') . ' | Records: ' . count($rows) . '</p>';
    echo '<table><thead><tr>';
    foreach (['Transaction ID','Customer','Type','Items / Service','Amount','Payment Method','Payment Status','Validation Status','Date/Time','Staff'] as $h)
        echo '<th>' . htmlspecialchars($h) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $pay_st = ato_pay_status($r);
        echo '<tr>';
        echo '<td>' . htmlspecialchars($r['txn_id'])            . '</td>';
        echo '<td>' . htmlspecialchars($r['customer'])          . '</td>';
        echo '<td>' . htmlspecialchars($r['entry_type'])        . '</td>';
        echo '<td>' . htmlspecialchars($r['items_service'])     . '</td>';
        echo '<td style="text-align:right">&#8369;' . number_format((float)$r['amount'], 2) . '</td>';
        echo '<td>' . htmlspecialchars($r['payment_method'])    . '</td>';
        echo '<td>' . htmlspecialchars($pay_st)                 . '</td>';
        echo '<td>' . htmlspecialchars($r['validation_status']) . '</td>';
        echo '<td>' . date('M d, Y H:i', strtotime($r['txn_date'])) . '</td>';
        echo '<td>' . htmlspecialchars($r['staff_name'])        . '</td>';
        echo '</tr>';
    }
    echo '<tr style="font-weight:800;background:#f0f7ff">';
    echo '<td colspan="9" style="text-align:right;padding-right:10px">TOTAL AMOUNT</td>';
    echo '<td colspan="1"></td>';
    echo '</tr>';
    // correct total row
    echo '<tr style="font-weight:800;background:#f0f7ff">';
    echo '<td colspan="4" style="text-align:right"><strong>TOTAL</strong></td>';
    echo '<td style="text-align:right"><strong>&#8369;' . number_format($total_amount, 2) . '</strong></td>';
    echo '<td colspan="5">' . count($rows) . ' record(s)</td>';
    echo '</tr>';
    echo '</tbody></table></body></html>';
    exit;
}

// ── PDF export ────────────────────────────────────────────────────────────────
if ($export_type === 'pdf') {
    header('Content-Type: text/html; charset=utf-8');
    $logo_url  = '../assets/img/Petron%20Logo.png';
    $generated = date('F d, Y  h:i A');
    $rec_count = count($rows);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
    echo '<title>Transactions Oversight | Petron Station Management</title>';
    echo '<style>';
    echo 'body{font-family:Arial,Helvetica,sans-serif;font-size:14px;margin:0;padding:0;background:#f1f5f9;color:#1e293b;}';
    echo '.action-bar{background:#002F70;padding:12px 24px;display:flex;align-items:center;gap:12px;position:sticky;top:0;z-index:999;}';
    echo '.action-bar h2{color:#fff;font-size:15px;margin:0;flex:1;}';
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
    echo '@media print{.action-bar{display:none!important;}body{background:#fff;}.report{box-shadow:none;border-radius:0;margin:0;max-width:100%;}table{font-size:9px;}th,td{padding:5px 4px;}}';
    echo '</style></head><body>';
    echo '<div class="action-bar">';
    echo '  <h2>&#128438; Transactions Oversight Report</h2>';
    echo '  <a href="javascript:window.print()" class="btn-print">&#128438; Print / Save as PDF</a>';
    echo '  <a href="javascript:void(0)" onclick="window.history.length>1?window.history.back():window.close()" class="btn-back">&#8592; Back</a>';
    echo '</div>';
    echo '<div class="report">';
    echo '<div class="rpt-header">';
    echo '  <img src="' . $logo_url . '" alt="Petron Logo">';
    echo '  <div class="rpt-header-text"><h1>Petron Station Management System</h1><p>Transactions Oversight Report</p></div>';
    echo '  <div class="rpt-header-meta">';
    echo '    <div><strong>Generated:</strong> ' . $generated . '</div>';
    echo '    <div><strong>Total Records:</strong> ' . $rec_count . '</div>';
    echo '  </div>';
    echo '</div>';
    echo '<div class="rpt-body"><table><thead><tr>';
    foreach (['Transaction ID','Customer','Type','Items / Service','Amount','Payment Method','Payment Status','Validation Status','Date/Time','Staff'] as $h)
        echo '<th>' . htmlspecialchars($h) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $pay_st = ato_pay_status($r);
        echo '<tr>';
        echo '<td>' . htmlspecialchars($r['txn_id'])            . '</td>';
        echo '<td>' . htmlspecialchars($r['customer'])          . '</td>';
        echo '<td>' . htmlspecialchars($r['entry_type'])        . '</td>';
        echo '<td>' . htmlspecialchars(mb_substr($r['items_service'], 0, 50)) . '</td>';
        echo '<td class="amount">&#8369;' . number_format((float)$r['amount'], 2) . '</td>';
        echo '<td>' . htmlspecialchars($r['payment_method'])    . '</td>';
        echo '<td>' . htmlspecialchars($pay_st)                 . '</td>';
        echo '<td>' . htmlspecialchars($r['validation_status']) . '</td>';
        echo '<td style="white-space:nowrap">' . date('M d, Y H:i', strtotime($r['txn_date'])) . '</td>';
        echo '<td>' . htmlspecialchars($r['staff_name'])        . '</td>';
        echo '</tr>';
    }
    $col_count = 10;
    echo '<tr class="total-row">';
    echo '<td colspan="' . ($col_count - 1) . '" style="text-align:right;padding-right:14px">TOTAL AMOUNT</td>';
    echo '<td class="amount" style="white-space:nowrap">&#8369;' . number_format($total_amount, 2) . '</td>';
    echo '</tr>';
    echo '</tbody></table></div>';
    echo '<div class="rpt-footer">&#169; ' . date('Y') . ' Petron Station &amp; Service Center Management System. All Rights Reserved.</div>';
    echo '</div></body></html>';
    exit;
}

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1">Oversight Dashboard</h1>
        <div class="sub">System‑wide monitoring of validated transactions and receivables.</div>
    </div>
    <div class="actions" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <!-- Excel -->
        <button type="button"
                onclick="window.location.href='?start=<?php echo urlencode($start); ?>&end=<?php echo urlencode($end); ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_f); ?>&type=<?php echo urlencode($type_f); ?>&export=excel'"
                style="background:#1d6f42;color:#fff;height:36px;padding:8px 14px;border-radius:8px;border:none;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"
                title="Export to Excel">
            <i class="fas fa-file-excel"></i> Excel
        </button>
        <!-- CSV -->
        <button type="button"
                onclick="window.location.href='?start=<?php echo urlencode($start); ?>&end=<?php echo urlencode($end); ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_f); ?>&type=<?php echo urlencode($type_f); ?>&export=csv'"
                style="background:#003d7a;color:#fff;height:36px;padding:8px 14px;border-radius:8px;border:none;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"
                title="Export to CSV">
            <i class="fas fa-file-csv"></i> CSV
        </button>
        <!-- PDF -->
        <button type="button"
                onclick="window.open('?start=<?php echo urlencode($start); ?>&end=<?php echo urlencode($end); ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_f); ?>&type=<?php echo urlencode($type_f); ?>&export=pdf','_blank')"
                style="background:#dc2626;color:#fff;height:36px;padding:8px 14px;border-radius:8px;border:none;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"
                title="Export to PDF">
            <i class="fas fa-file-pdf"></i> PDF
        </button>
        <a href="<?= in_array($role, ['admin', 'superadmin']) ? 'admin_dashboard.php' : 'manager_dashboard.php'; ?>"
           style="background:#6c757d;color:#fff;text-decoration:none;height:36px;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:600;display:inline-flex;align-items:center;gap:6px;">
            <i class="fas fa-arrow-left"></i> Back
        </a>
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

<!-- ── Filter Bar ──────────────────────────────────────────────────────────── -->
<div class="ato-filter-card">
    <form method="get" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
        <div class="ato-flt-grp">
            <label class="ato-lbl"><i class="fas fa-layer-group"></i> Transaction Type</label>
            <select name="type" class="ato-inp ato-select" onchange="this.form.submit()">
                <option value="">All Types</option>
                <option value="merchandise" <?php echo $type_f === 'merchandise' ? 'selected' : ''; ?>>Merchandise</option>
                <option value="job_order"   <?php echo $type_f === 'job_order'   ? 'selected' : ''; ?>>Job Order</option>
                <option value="jo_merchandise" <?php echo $type_f === 'jo_merchandise' ? 'selected' : ''; ?>>JO + Merchandise</option>
            </select>
        </div>
        <div class="ato-flt-grp">
            <label class="ato-lbl"><i class="fas fa-calendar-alt"></i> Date Range</label>
            <div style="display:flex;gap:6px;align-items:center;">
                <input type="date" name="start" value="<?php echo htmlspecialchars($start); ?>" class="ato-inp" max="<?php echo date('Y-m-d'); ?>">
                <span style="color:#999;font-size:12px;">to</span>
                <input type="date" name="end"   value="<?php echo htmlspecialchars($end);   ?>" class="ato-inp" max="<?php echo date('Y-m-d'); ?>">
            </div>
        </div>
        <div class="ato-flt-grp">
            <label class="ato-lbl"><i class="fas fa-search"></i> Search</label>
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                   class="ato-inp" placeholder="Transaction ID, customer..." style="width:200px;">
        </div>
        <div class="ato-flt-grp">
            <label class="ato-lbl"><i class="fas fa-circle-dot"></i> Validation Status</label>
            <select name="status" class="ato-inp ato-select">
                <option value="">All Statuses</option>
                <?php
                // Admin sees only manager-processed records (Approved/Completed)
                $status_opts = ['Approved', 'Completed'];
                foreach ($status_opts as $opt):
                ?>
                <option value="<?php echo strtolower($opt); ?>" <?php echo strtolower($status_f) === strtolower($opt) ? 'selected' : ''; ?>>
                    <?php echo $opt; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ato-flt-grp" style="align-self:flex-end;">
            <button type="submit" class="ato-btn ato-btn-search"><i class="fas fa-search"></i> Search</button>
            <a href="?" class="ato-btn ato-btn-reset"><i class="fas fa-rotate-left"></i> Reset</a>
        </div>
    </form>
</div>





<!-- ── Unified Table ───────────────────────────────────────────────────────── -->
<div class="card" style="padding:0;overflow-x:hidden;">
    <table class="ato-table" style="table-layout:fixed; word-wrap:break-word;width:100%;">
        <thead>
            <tr>
                <th>Transaction ID</th>
                <th>Customer</th>
                <th>Type</th>
                <th>Items / Service</th>
                <th style="text-align:right;">Amount</th>
                <th>Payment Method</th>
                <th>Payment Status</th>
                <th>Validation Status</th>
                <th>Date / Time</th>
                <th>Staff</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($rows) > 0): ?>
                <?php foreach ($rows as $r): ?>
                <?php
                    $vs   = strtolower(trim($r['validation_status'] ?? ''));
                    $pay_st = ato_pay_status($r);
                    $et     = $r['entry_type'] ?? '';
                ?>
                <tr>
                    <td style="font-weight:600;font-size:12px;font-family:monospace;white-space:nowrap;">
                        <?php echo htmlspecialchars($r['txn_id']); ?>
                    </td>
                    <td><?php echo htmlspecialchars($r['customer']); ?></td>
                    <td>
                        <span class="ato-badge ato-badge-type">
                            <?php echo htmlspecialchars($et); ?>
                        </span>
                    </td>
                    <td style="font-size:12px;"
                        title="<?php echo htmlspecialchars($r['items_service']); ?>">
                        <?php echo htmlspecialchars($r['items_service']); ?>
                    </td>
                    <td style="font-weight:600;color:#002F70;text-align:right;white-space:nowrap;">
                        &#8369;<?php echo number_format((float)$r['amount'], 2); ?>
                    </td>
                    <td style="font-size:12px;"><?php echo htmlspecialchars($r['payment_method']); ?></td>
                    <td>
                        <span class="ato-badge ato-badge-<?php echo strtolower(str_replace(' ', '-', $pay_st)); ?>">
                            <?php echo $pay_st; ?>
                        </span>
                    </td>
                    <td>
                        <span class="ato-badge ato-badge-<?php echo $vs; ?>">
                            <?php echo htmlspecialchars(ucfirst($r['validation_status'])); ?>
                        </span>
                    </td>
                    <td style="white-space:nowrap;font-size:12px;color:#64748b;">
                        <?php echo date('M d, Y H:i', strtotime($r['txn_date'])); ?>
                    </td>
                    <td style="font-size:12px;color:#64748b;"><?php echo htmlspecialchars($r['staff_name']); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="10" style="text-align:center;padding:60px 20px;color:#94a3b8;">
                        <i class="fas fa-inbox" style="font-size:48px;display:block;margin-bottom:12px;opacity:0.3;"></i>
                        <div style="font-size:16px;font-weight:600;color:#64748b;margin-bottom:4px;">No Transactions Found</div>
                        <div style="font-size:13px;">Try adjusting your filters or date range.</div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<style>
/* ── Blue Header Table Design ──────────────────────────────────────────────── */
.ato-filter-card { 
    background:#fff;
    border:1px solid #e2e8f0;
    border-radius:12px;
    padding:14px 18px;
    margin-bottom:14px;
    box-shadow:0 1px 4px rgba(0,0,0,.05); 
}
.ato-flt-grp { display:flex;flex-direction:column;gap:4px; }
.ato-lbl { 
    font-size:11px;
    font-weight:700;
    color:#64748b;
    text-transform:uppercase;
    letter-spacing:.4px; 
}
.ato-inp, .ato-select { 
    height:36px;
    padding:0 10px;
    border:1px solid #cbd5e1;
    border-radius:7px;
    font-size:13px;
    color:#1e293b;
    background:#fff;
    outline:none;
    box-sizing:border-box; 
}
.ato-inp:focus, .ato-select:focus { 
    border-color:#002F70;
    box-shadow:0 0 0 3px rgba(0,47,112,.1); 
}
.ato-btn { 
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:0 16px;
    height:36px;
    border:none;
    border-radius:7px;
    font-size:13px;
    font-weight:600;
    cursor:pointer;
    text-decoration:none;
    white-space:nowrap;
    transition:filter .15s; 
}
.ato-btn:hover { filter:brightness(.88); }
.ato-btn-search { background:#002F70;color:#fff; }
.ato-btn-reset  { background:#64748b;color:#fff; }

/* ── Table Styles with Blue Headers ─────────────────────────────────────────── */
.ato-table { 
    width:100%;
    border-collapse:collapse;
    font-size:13px;
}
.ato-table thead th { 
    background:#002F70;
    color:#fff;
    font-size:11px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.4px;
    padding:12px 10px;
    border-bottom:2px solid #001a3d;
    text-align:left;
}
.ato-table tbody td { 
    padding:10px;
    border-bottom:1px solid #f1f5f9;
    vertical-align:middle;
    background:#fff;
}
.ato-table tbody tr:hover td { 
    background:#eff6ff;
}

/* ── Plain Text Badges ──────────────────────────────────────────────────────── */
.ato-badge { 
    display:inline-block;
    padding:3px 10px;
    border-radius:4px;
    font-size:11px;
    font-weight:600;
    white-space:nowrap;
    background:#f8fafc;
    color:#64748b;
    border:1px solid #e2e8f0;
}
.ato-badge-type {
    background:#f1f5f9;
    color:#475569;
    border-color:#cbd5e1;
}
.ato-badge-paid {
    background:#f0fdf4;
    color:#166534;
    border-color:#bbf7d0;
}
.ato-badge-partial {
    background:#fef3c7;
    color:#92400e;
    border-color:#fde047;
}
.ato-badge-unpaid {
    background:#fef2f2;
    color:#991b1b;
    border-color:#fecaca;
}
.ato-badge-approved, .ato-badge-completed {
    background:#f0fdf4;
    color:#166534;
    border-color:#bbf7d0;
}

/* ── Summary Bar ────────────────────────────────────────────────────────────── */
.ato-summary-bar { 
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    align-items:center;
    padding:10px 16px;
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:10px;
    margin-bottom:14px;
    font-size:13px;
    color:#64748b; 
}
.ato-sum-pill { 
    background:#eff6ff;
    color:#1e40af;
    padding:2px 10px;
    border-radius:10px;
    font-size:12px;
    font-weight:600;
}

/* ── Modal Overlay (hidden by default) ─────────────────────────────────────── */
.ato-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.45);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}
.ato-modal-overlay.active {
    display: flex;
}
.ato-modal {
    background: #fff;
    border-radius: 12px;
    padding: 28px 28px 22px;
    width: 100%;
    max-width: 480px;
    box-shadow: 0 8px 40px rgba(0,0,0,.18);
    position: relative;
}
.ato-modal h3 {
    font-size: 16px;
    font-weight: 700;
    color: #1e293b;
    margin: 0 0 18px 0;
    display: flex;
    align-items: center;
}
.ato-modal label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: #475569;
    margin: 0 0 4px 0;
}
.ato-modal input[type=number],
.ato-modal textarea {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    font-size: 13px;
    box-sizing: border-box;
    margin-bottom: 12px;
    resize: vertical;
}
.ato-modal-btns {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    margin-top: 6px;
}
.ato-modal-cancel {
    padding: 8px 16px;
    background: #f1f5f9;
    color: #475569;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
}
.ato-modal-submit {
    padding: 8px 18px;
    color: #fff;
    border: none;
    border-radius: 7px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
}

/* ── Responsive ─────────────────────────────────────────────────────────────── */
@media (max-width: 768px) {
    .ato-table { font-size:11px; }
    .ato-table thead th, .ato-table tbody td { padding:8px 6px; }
}

/* ── Print Styles ───────────────────────────────────────────────────────────── */
@media print {
    .sidebar,.top-header,.page-head .actions,.ato-filter-card,.ato-tab-bar { display:none !important; }
    .main { margin:0 !important;padding:0 !important; }
    .ato-table { font-size:10px; }
}
</style>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const tables = document.querySelectorAll('table.ato-table');
        tables.forEach(table => {
            const container = table.closest('div');
            if (!container) return;
            if (container.nextElementSibling && container.nextElementSibling.classList.contains('pagination-wrapper')) return;

            const tbody = table.querySelector('tbody');
            if (!tbody) return;
            
            // Filter out the empty placeholder row if it exists
            let rows = Array.from(tbody.querySelectorAll('tr'));
            if (rows.length === 1 && rows[0].querySelector('.fa-inbox')) return;

            let currentPage = 1;
            let rowsPerPage = 10;
            let totalRows = rows.length;
            let totalPages = Math.ceil(totalRows / rowsPerPage);

            const wrapper = document.createElement('div');
            wrapper.className = 'pagination-wrapper client-side-pagination';
            wrapper.style.cssText = 'display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; background: #fff; border: 1px solid #EAEAEA; border-radius: 12px; margin-top: 12px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.02); flex-wrap: wrap; gap: 10px;';
            
            if (!document.getElementById('client-pagination-style')) {
                const style = document.createElement('style');
                style.id = 'client-pagination-style';
                style.innerHTML = `
                    .rows-per-page { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #6b7280; }
                    .rows-per-page select { padding: 6px; border: 1px solid #e5e7eb; border-radius: 4px; outline: none; cursor: pointer; }
                    .page-info { font-size: 13px; color: #6b7280; }
                    .pagination-controls { display: flex; align-items: center; gap: 10px; }
                    .btn-page { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; background: #fff; border: 1px solid #e5e7eb; border-radius: 6px; color: #374151; text-decoration: none; transition: 0.2s; cursor: pointer; }
                    .btn-page:hover:not(.disabled) { background: #f3f4f6; }
                    .btn-page.disabled { opacity: 0.5; cursor: not-allowed; pointer-events: none; }
                    .current-page { font-size: 13px; font-weight: 500; color: #111827; }
                `;
                document.head.appendChild(style);
            }

            function renderTable() {
                tbody.innerHTML = '';
                const start = (currentPage - 1) * rowsPerPage;
                const end = start + rowsPerPage;
                const paginatedRows = rows.slice(start, end);
                
                paginatedRows.forEach(row => tbody.appendChild(row));
                updateControls();
            }

            function updateControls() {
                totalPages = Math.ceil(totalRows / rowsPerPage);
                
                const start = (currentPage - 1) * rowsPerPage + 1;
                const end = Math.min(currentPage * rowsPerPage, totalRows);
                
                wrapper.innerHTML = `
                    <div class="rows-per-page">
                        <label>Rows per page:</label>
                        <select class="rpp-select">
                            <option value="10" ${rowsPerPage === 10 ? 'selected' : ''}>10</option>
                            <option value="25" ${rowsPerPage === 25 ? 'selected' : ''}>25</option>
                            <option value="50" ${rowsPerPage === 50 ? 'selected' : ''}>50</option>
                            <option value="100" ${rowsPerPage === 100 ? 'selected' : ''}>100</option>
                            <option value="${totalRows}" ${rowsPerPage === totalRows ? 'selected' : ''}>All</option>
                        </select>
                    </div>
                    <div class="page-info">
                        Showing ${totalRows === 0 ? 0 : start} to ${end} of ${totalRows} entries
                    </div>
                    <div class="pagination-controls">
                        <button type="button" class="btn-page prev-btn ${currentPage === 1 ? 'disabled' : ''}"><i class="fa-solid fa-chevron-left"></i></button>
                        <span class="current-page">Page ${currentPage} of ${Math.max(1, totalPages)}</span>
                        <button type="button" class="btn-page next-btn ${currentPage === totalPages || totalPages === 0 ? 'disabled' : ''}"><i class="fa-solid fa-chevron-right"></i></button>
                    </div>
                `;

                wrapper.querySelector('.rpp-select').addEventListener('change', function(e) {
                    rowsPerPage = parseInt(e.target.value);
                    currentPage = 1;
                    renderTable();
                });

                wrapper.querySelector('.prev-btn').addEventListener('click', function(e) {
                    e.preventDefault();
                    if (currentPage > 1) {
                        currentPage--;
                        renderTable();
                    }
                });

                wrapper.querySelector('.next-btn').addEventListener('click', function(e) {
                    e.preventDefault();
                    if (currentPage < totalPages) {
                        currentPage++;
                        renderTable();
                    }
                });
            }

            container.parentNode.insertBefore(wrapper, container.nextSibling);
            renderTable();
        });
    });
</script>
<div style="height: 80px;"></div>

<!-- ── Reject Modal ─────────────────────────────────────────────────────────── -->
<div class="ato-modal-overlay" id="atoRejectModal">
    <div class="ato-modal">
        <h3><i class="fas fa-times-circle" style="color:#dc3545;margin-right:8px;"></i>Return Transaction</h3>
        <form method="POST" id="atoRejectForm">
            <input type="hidden" name="action" id="ato_reject_action" value="reject_transaction">
            <input type="hidden" name="transaction_id" id="ato_reject_txn_id" value="">
            <input type="hidden" name="jo_id" id="ato_reject_jo_id" value="">
            <input type="hidden" name="jo_source" id="ato_reject_jo_src" value="job_orders">
            <input type="hidden" name="_start" id="ato_reject_start" value="">
            <input type="hidden" name="_end" id="ato_reject_end" value="">
            <input type="hidden" name="_status" id="ato_reject_status" value="">
            <input type="hidden" name="_search" id="ato_reject_search" value="">
            <label>Reason for returning <span style="color:#dc3545;">*</span></label>
            <textarea name="reason" id="ato_reject_reason" placeholder="Explain why this transaction is being returned…" required></textarea>
            <div class="ato-modal-btns">
                <button type="button" class="ato-modal-cancel" onclick="atoCloseModal('atoRejectModal')">Cancel</button>
                <button type="submit" class="ato-modal-submit" style="background:#dc3545;">Return Transaction</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Adjust Modal ─────────────────────────────────────────────────────────── -->
<div class="ato-modal-overlay" id="atoAdjustModal">
    <div class="ato-modal">
        <h3><i class="fas fa-sliders" style="color:#6f42c1;margin-right:8px;"></i>Adjust Transaction Amount</h3>
        <form method="POST" id="atoAdjustForm">
            <input type="hidden" name="action" value="adjust_transaction">
            <input type="hidden" name="transaction_id" id="ato_adj_txn_id" value="">
            <input type="hidden" name="_start" id="ato_adj_start" value="">
            <input type="hidden" name="_end" id="ato_adj_end" value="">
            <input type="hidden" name="_status" id="ato_adj_status" value="">
            <input type="hidden" name="_search" id="ato_adj_search" value="">
            <label>New Total Amount (₱) <span style="color:#dc3545;">*</span></label>
            <input type="number" name="adj_total" id="ato_adj_total" step="0.01" min="0" required>
            <label>Adjustment Note <span style="color:#dc3545;">*</span></label>
            <textarea name="adj_note" id="ato_adj_note" placeholder="Reason for adjustment…" required></textarea>
            <div class="ato-modal-btns">
                <button type="button" class="ato-modal-cancel" onclick="atoCloseModal('atoAdjustModal')">Cancel</button>
                <button type="submit" class="ato-modal-submit" style="background:#6f42c1;">Save Adjustment</button>
            </div>
        </form>
    </div>
</div>

<script>
function atoOpenRejectModal(type, id, start, end, status, search, joSrc) {
    const modal = document.getElementById('atoRejectModal');
    document.getElementById('ato_reject_reason').value = '';
    document.getElementById('ato_reject_start').value  = start  || '';
    document.getElementById('ato_reject_end').value    = end    || '';
    document.getElementById('ato_reject_status').value = status || '';
    document.getElementById('ato_reject_search').value = search || '';
    // Reset all id fields
    document.getElementById('ato_reject_txn_id').value = '';
    document.getElementById('ato_reject_jo_id').value  = '';
    document.getElementById('ato_reject_jo_id').value   = '';
    if (type === 'merch') {
        document.getElementById('ato_reject_action').value = 'reject_transaction';
        document.getElementById('ato_reject_txn_id').value = id;
    } else if (type === 'jo') {
        document.getElementById('ato_reject_action').value  = 'reject_job_order';
        document.getElementById('ato_reject_jo_id').value   = id;
        document.getElementById('ato_reject_jo_src').value  = joSrc || 'job_orders';
    }
    modal.classList.add('active');
}
function atoOpenAdjustModal(id, amount, start, end, status, search) {
    document.getElementById('ato_adj_txn_id').value = id;
    document.getElementById('ato_adj_total').value  = amount;
    document.getElementById('ato_adj_note').value   = '';
    document.getElementById('ato_adj_start').value  = start  || '';
    document.getElementById('ato_adj_end').value    = end    || '';
    document.getElementById('ato_adj_status').value = status || '';
    document.getElementById('ato_adj_search').value = search || '';
    document.getElementById('atoAdjustModal').classList.add('active');
}
function atoCloseModal(id) {
    document.getElementById(id).classList.remove('active');
}
// Close modal on overlay click
document.querySelectorAll('.ato-modal-overlay').forEach(function(overlay) {
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) overlay.classList.remove('active');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// AUTO-REFRESH: Admin Transactions Oversight (60-second polling for compliance)
// No manual refresh button needed - system automatically reflects manager-validated
// transactions and compliance alerts for admin oversight monitoring.
// ══════════════════════════════════════════════════════════════════════════════
let refreshAdminOversightTimer = null;
let isAdminModalOpen = false;

function autoRefreshAdminOversight() {
    // Skip refresh if admin is reviewing a transaction in modal
    if (isAdminModalOpen) {
        return;
    }
    
    // Silently reload to get fresh oversight data
    const urlParams = new URLSearchParams(window.location.search);
    const currentSearch = urlParams.toString();
    const reloadUrl = currentSearch ? '?' + currentSearch : window.location.pathname;
    
    // Silent reload - preserves all filters and search params
    window.location.replace(reloadUrl + (currentSearch ? '&t=' : '?t=') + Date.now());
}

// Track modal state to pause auto-refresh during admin actions
const originalAtoCloseModal = window.atoCloseModal;
window.atoCloseModal = function(id) {
    originalAtoCloseModal(id);
    isAdminModalOpen = false;
};

const originalAtoOpenRejectModal = window.atoOpenRejectModal;
window.atoOpenRejectModal = function(type, id, start, end, status, search, joSrc) {
    isAdminModalOpen = true;
    return originalAtoOpenRejectModal(type, id, start, end, status, search, joSrc);
};

const originalAtoOpenAdjustModal = window.atoOpenAdjustModal;
window.atoOpenAdjustModal = function(id, amount, start, end, status, search) {
    isAdminModalOpen = true;
    return originalAtoOpenAdjustModal(id, amount, start, end, status, search);
};

// Start auto-refresh timer (60 seconds - appropriate for admin oversight)
window.refreshAdminOversightTimer = setInterval(autoRefreshAdminOversight, 60000);

console.log('✅ Auto-refresh enabled for Admin Transactions Oversight (60s interval)');
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
