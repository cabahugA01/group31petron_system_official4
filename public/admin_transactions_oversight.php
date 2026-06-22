<?php
$page_id = 'ato_oversight_dashboard';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/transaction_schema_fix.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();
$actor_name = $me['name'] ?? $me['username'] ?? 'Admin';

if (!in_array($role, ['admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: admin_dashboard.php'); exit;
}

// â”€â”€ POST: Admin transaction actions â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !isset($_GET['export'])) {
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
            $at_cols = [];
            try {
                foreach ($pdo->query("SHOW COLUMNS FROM audit_trail")->fetchAll(PDO::FETCH_COLUMN) as $c)
                    $at_cols[$c] = true;
            } catch (Exception $_ce) {}

            if (isset($at_cols['staff_id']) && isset($at_cols['source_table'])) {
                $pdo->prepare("INSERT INTO audit_trail (transaction_id, manager_id, staff_id, action_type, new_value, station_id, source_table) VALUES (?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$txn_id, $me['id'], $me['id'], $action_type, $new_val, $station_id, 'merchandise_transactions']);
            } else {
                $pdo->prepare("INSERT INTO audit_trail (transaction_id, manager_id, action_type, new_value, station_id) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$txn_id, $me['id'], $action_type, $new_val, $station_id]);
            }
        } catch (Exception $ae) {}

        // Also write to audit_logs for Audit Trail report
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'System';
            $detail = "Admin " . htmlspecialchars($me['name'] ?? $me['username'] ?? 'Admin') . " executed '$action_type' on TXN #{$txn_id}." . ($new_val ? " Details: {$new_val}" : "");
            $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, new_values, status, ip_address, user_agent, created_at)
                           VALUES (?, 'TRANSACTION', ?, ?, 'merchandise_transactions', ?, ?, 'SUCCESS', ?, ?, NOW())")
                ->execute([$me['id'], $action_type, $detail, $txn_id, $new_val, $ip, $ua]);
        } catch (Exception $al_err) {}
    };

    // â”€â”€ Approve Merchandise Transaction â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // Admin oversight only acts on records already validated by Manager.
    // Raw 'Pending' staff encodings must go through Manager first.
    if ($post_action === 'approve_transaction') {
        $row_id = (int)($_POST['transaction_id'] ?? 0);
        $notes  = trim($_POST['notes'] ?? '');
        try {
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
            if ($has_mt('manager_notes')) {
                $set_parts[] = "manager_notes = ?";
                $set_vals[]  = $notes !== '' ? "Admin Approved: {$notes}" : "Admin Approved by " . ($me['name'] ?? $me['username']);
            }
            if ($has_mt('updated_at'))   { $set_parts[] = "updated_at = NOW()"; }
            $stmt = $pdo->prepare("UPDATE merchandise_transactions SET " . implode(', ', $set_parts) . " WHERE id = ? AND station_id = ?");
            $stmt->execute(array_merge($set_vals, [$row_id, $station_id]));
            if ($stmt->rowCount() > 0) {
                $insert_audit($row_id, 'Admin_Approve', $notes ?: null);
                log_activity($pdo, $me['id'], 'Approve Transaction', "Merchandise transaction #{$row_id} approved by admin {$actor_name}");
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

    // â”€â”€ Reject Merchandise Transaction â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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
            if ($has_mt('manager_notes'))    { $set_parts[] = "manager_notes = ?";    $set_vals[] = "Admin Rejected: {$reason}"; }
            if ($has_mt('updated_at'))       { $set_parts[] = "updated_at = NOW()"; }
            $stmt = $pdo->prepare("UPDATE merchandise_transactions SET " . implode(', ', $set_parts) . " WHERE id = ? AND station_id = ?");
            $stmt->execute(array_merge($set_vals, [$row_id, $station_id]));
            if ($stmt->rowCount() > 0) {
                $insert_audit($row_id, 'Admin_Return', $reason);
                log_activity($pdo, $me['id'], 'Return Transaction', "Merchandise transaction #{$row_id} returned by admin {$actor_name}. Reason: {$reason}");
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

    // â”€â”€ Adjust Merchandise Transaction â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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
            if ($has_mt('manager_notes')){ $set_parts[] = "manager_notes = ?"; $set_vals[] = "Admin Adjusted: â‚±" . number_format($new_total,2) . ". " . $adj_note; }
            if ($has_mt('updated_at'))   { $set_parts[] = "updated_at = NOW()"; }
            $stmt = $pdo->prepare("UPDATE merchandise_transactions SET " . implode(', ', $set_parts) . " WHERE id = ? AND station_id = ?");
            $stmt->execute(array_merge($set_vals, [$row_id, $station_id]));
            if ($stmt->rowCount() > 0) {
                $insert_audit($row_id, 'Admin_Adjust', "New total: â‚±{$new_total}. Note: {$adj_note}");
                log_activity($pdo, $me['id'], 'Adjust Transaction', "Merchandise #{$row_id} adjusted to â‚±{$new_total} by admin {$actor_name}.");
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

    // â”€â”€ Approve Job Order â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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
            log_activity($pdo, $me['id'], 'JO_APPROVED', "Job Order #{$jo_id} approved by admin {$actor_name}.");
            $pdo->commit();
            $_SESSION['success'] = "Job Order #{$jo_id} approved successfully.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = 'Error approving JO: ' . $e->getMessage();
        }
        header('Location: admin_transactions_oversight.php?' . http_build_query(array_filter(['tab'=>'transactions','start'=>$_POST['_start']??'','end'=>$_POST['_end']??''])));
        exit;
    }

    // â”€â”€ Reject Job Order â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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
            log_activity($pdo, $me['id'], 'JO_REJECTED', "Job Order #{$jo_id} rejected by admin {$actor_name}. Reason: {$reason}");
            $pdo->commit();
            $_SESSION['success'] = "Job Order #{$jo_id} rejected.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = 'Error rejecting JO: ' . $e->getMessage();
        }
        header('Location: admin_transactions_oversight.php?' . http_build_query(array_filter(['tab'=>'transactions','start'=>$_POST['_start']??'','end'=>$_POST['_end']??''])));
        exit;
    }

    // â”€â”€ Fuel transactions removed â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // Admin Oversight Dashboard shows ONLY Merchandise + Job Orders (NO Fuel).
    // Fuel variance is monitored in separate admin_variance_reports.php page.
}

// â”€â”€ Dynamic column detection â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

// â”€â”€ Payment status helper â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

// â”€â”€ Filters â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// Admin Oversight Dashboard: ONLY Merchandise + Job Orders (NO Fuel transactions)
$start      = $_GET['start']  ?? date('Y-m-d', strtotime('-30 days'));
$end        = $_GET['end']    ?? date('Y-m-d');
$search     = trim($_GET['search'] ?? '');
$status_f   = trim($_GET['status'] ?? '');
$type_f     = trim($_GET['type']   ?? ''); // 'merchandise' | 'job_order' | ''

// â”€â”€ Export header (early, before any output) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

// â”€â”€ Fetch unified Merchandise + Job Orders (NO Fuel) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$rows         = [];
$total_amount = 0.0;

// â”€â”€ Merchandise rows â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$mt_status_col   = ato_has($mt_cols, 'validation_status') ? 'mt.validation_status' : "'Pending'";
$mt_staff_col    = ato_has($mt_cols, 'staff_id') ? "COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown')" : "'Unknown'";
$mt_date_col     = ato_has($mt_cols, 'transaction_date')
    ? (ato_has($mt_cols, 'created_at') ? "CASE WHEN mt.transaction_date > '2000-01-01' THEN mt.transaction_date ELSE mt.created_at END" : 'mt.transaction_date')
    : 'mt.created_at';
$mt_paid_col     = ato_has($mt_cols, 'amount_paid') ? 'mt.amount_paid' : 'NULL';
$mt_item_sku_col = ato_has($mt_cols, 'item_sku') ? 'mt.item_sku' : 'NULL';

$mt_where  = "WHERE mt.station_id = ? AND DATE({$mt_date_col}) BETWEEN ? AND ?";
$mt_params = [$station_id, $start, $end];
if ($search !== '') {
    $mt_where .= " AND (mt.customer_name LIKE ? OR mt.transaction_id LIKE ?)";
    $mt_params[] = "%$search%"; $mt_params[] = "%$search%";
}
if ($status_f !== '') {
    $mt_where .= " AND LOWER(TRIM(COALESCE({$mt_status_col},''))) = LOWER(?)";
    $mt_params[] = $status_f;
} else {
    // Admin Oversight: show all manager-processed records
    $mt_where .= " AND LOWER(TRIM(COALESCE({$mt_status_col},''))) IN ('approved','completed','adjusted','rejected')";
}

$mt_rows = [];
if ($type_f === '' || $type_f === 'merchandise' || $type_f === 'job_order' || $type_f === 'jo_merchandise') {
    try {
        // Detect if job_order_service column exists for combined detection
        $mt_jo_svc_col = ato_has($mt_cols, 'job_order_service') ? "COALESCE(mt.job_order_service,'')" : "''";
        $mt_jo_veh_col = ato_has($mt_cols, 'job_order_vehicle_plate') ? "COALESCE(mt.job_order_vehicle_plate,'')" : "''";
        $mt_jo_veh_type_col = ato_has($mt_cols, 'job_order_vehicle_type') ? "COALESCE(mt.job_order_vehicle_type,'')" : "''";

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
                    NULLIF((SELECT GROUP_CONCAT(
                                CONCAT(i.product_name, ' - ', i.quantity, ' pcs @ â‚±', FORMAT(i.unit_price, 2))
                                ORDER BY i.id SEPARATOR ' | ')
                            FROM merchandise_transaction_items i 
                            WHERE i.transaction_id = mt.id 
                            AND COALESCE(i.item_type, 'merchandise') = 'merchandise'),''),
                    {$mt_item_sku_col}, 
                    CASE WHEN TRIM(COALESCE({$mt_jo_svc_col},'')) <> '' THEN 'â€”' ELSE 'N/A' END
                )                                                       AS items_parts,
                COALESCE(
                    NULLIF((SELECT GROUP_CONCAT(
                                CONCAT(i.product_name, ' - ', i.quantity, ' pcs @ â‚±', FORMAT(i.unit_price, 2))
                                ORDER BY i.id SEPARATOR ' | ')
                            FROM merchandise_transaction_items i 
                            WHERE i.transaction_id = mt.id 
                            AND i.item_type = 'service'),''),
                    NULLIF({$mt_jo_svc_col},''),
                    CASE WHEN {$mt_item_sku_col} IS NOT NULL AND {$mt_item_sku_col} <> '' THEN 'â€”' ELSE 'N/A' END
                )                                                       AS service_type,
                NULLIF(TRIM(CONCAT(
                    COALESCE({$mt_jo_veh_col},''),
                    CASE WHEN TRIM(COALESCE({$mt_jo_veh_type_col},'')) <> ''
                         THEN CONCAT(' Â· ', {$mt_jo_veh_type_col}) ELSE '' END
                )),'')                                                  AS vehicle_info,
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

// â”€â”€ Job Order rows â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$jo_status_col   = ato_has($jo_cols, 'validation_status') ? 'jo.validation_status' : 'jo.status';
$jo_staff_col    = ato_has($jo_cols, 'created_by')        ? 'COALESCE(jo.created_by, jo.user_id)' : 'jo.user_id';
$jo_mechanic_col = ato_has($jo_cols, 'assigned_mechanic_id') ? "COALESCE(NULLIF(CONCAT(m.first_name,' ',m.last_name),' '), m.username, '')" : "''";
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
    // Admin Oversight: show all manager-processed job orders
    $jo_where .= " AND LOWER(TRIM(COALESCE({$jo_status_col},''))) IN ('approved','completed','adjusted','rejected','in progress')";
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
                'â€”'                                                          AS items_parts,
                CONCAT(
                    COALESCE(jo.service_type,'Service'),
                    CASE WHEN jo.vehicle_plate IS NOT NULL AND jo.vehicle_plate != ''
                         THEN CONCAT(' | ', jo.vehicle_plate) ELSE '' END,
                    CASE WHEN {$jo_mechanic_col} != ''
                         THEN CONCAT(' | Mech: ', {$jo_mechanic_col}) ELSE '' END
                )                                                            AS service_type,
                NULLIF(TRIM(CONCAT(
                    COALESCE(jo.vehicle_plate,''),
                    CASE WHEN jo.vehicle_type IS NOT NULL AND jo.vehicle_type != ''
                         THEN CONCAT(' Â· ', jo.vehicle_type) ELSE '' END
                )),'')                                                       AS vehicle_info,
                {$jo_cost_col}                                               AS amount,
                {$jo_paid_col}                                               AS amount_paid,
                {$jo_pay_col}                                                AS payment_method,
                jo.created_at                                                AS txn_date,
                COALESCE(NULLIF(TRIM({$jo_status_col}),''),'Pending')        AS validation_status,
                COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown') AS staff_name,
                'job_orders'                                                 AS _source
            FROM job_orders jo
            LEFT JOIN users u ON u.id = COALESCE(jo.created_by, jo.user_id)
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

// â”€â”€ Status counts â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$status_counts = [];
$type_counts   = [];
foreach ($rows as $r) {
    $s = strtolower(trim($r['validation_status'] ?? 'pending'));
    $status_counts[$s] = ($status_counts[$s] ?? 0) + 1;
    $t = $r['entry_type'] ?? '';
    $type_counts[$t] = ($type_counts[$t] ?? 0) + 1;
}

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// NEW: Enhancements â€” all DB-driven, zero hardcoded
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•

// â”€â”€ 1. Performance Metrics (KPI panel) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// Total sales, total services, top items, top encoder â€” scoped to station + filter dates
$kpi_total_sales     = 0.0;
$kpi_total_services  = 0;
$kpi_top_items       = [];  // [['name'=>..., 'qty'=>...], ...]
$kpi_top_encoder     = null; // ['name'=>..., 'count'=>...]

try {
    // Total sales (approved/completed merchandise + job orders in date range)
    $kpi_sales_stmt = $pdo->prepare("
        SELECT COALESCE(SUM(mt.total_amount), 0) AS total_sales,
               COUNT(*) AS txn_count
        FROM merchandise_transactions mt
        WHERE mt.station_id = ?
          AND DATE(mt.transaction_date) BETWEEN ? AND ?
          AND LOWER(TRIM(COALESCE(mt.validation_status,''))) IN ('approved','completed','adjusted')
    ");
    $kpi_sales_stmt->execute([$station_id, $start, $end]);
    $kpi_sales_row = $kpi_sales_stmt->fetch(PDO::FETCH_ASSOC);
    $kpi_total_sales = (float)($kpi_sales_row['total_sales'] ?? 0);
} catch (Exception $_kpie) {}

try {
    // Add job order amounts too
    $jo_cost_expr = ato_has($jo_cols,'total_cost') ? 'COALESCE(jo.total_cost,0)' : 'COALESCE(jo.estimated_cost,0)';
    $jo_val_expr  = ato_has($jo_cols,'validation_status') ? 'jo.validation_status' : 'jo.status';
    $kpi_jo_stmt = $pdo->prepare("
        SELECT COALESCE(SUM({$jo_cost_expr}), 0) AS jo_total
        FROM job_orders jo
        WHERE jo.station_id = ?
          AND DATE(jo.created_at) BETWEEN ? AND ?
          AND LOWER(TRIM(COALESCE({$jo_val_expr},''))) IN ('approved','completed')
    ");
    $kpi_jo_stmt->execute([$station_id, $start, $end]);
    $kpi_total_sales += (float)($kpi_jo_stmt->fetchColumn() ?? 0);
} catch (Exception $_kpie2) {}

try {
    // Total services (job orders)
    $jo_val_expr2 = ato_has($jo_cols,'validation_status') ? 'jo.validation_status' : 'jo.status';
    $kpi_svc_stmt = $pdo->prepare("
        SELECT COUNT(*) AS svc_count
        FROM job_orders jo
        WHERE jo.station_id = ?
          AND DATE(jo.created_at) BETWEEN ? AND ?
          AND LOWER(TRIM(COALESCE({$jo_val_expr2},''))) IN ('approved','completed','in progress')
    ");
    $kpi_svc_stmt->execute([$station_id, $start, $end]);
    $kpi_total_services = (int)($kpi_svc_stmt->fetchColumn() ?? 0);
    // Also count job_order type from merchandise_transactions
    $kpi_jo_mt_stmt = $pdo->prepare("
        SELECT COUNT(*) FROM merchandise_transactions
        WHERE station_id = ?
          AND DATE(transaction_date) BETWEEN ? AND ?
          AND transaction_type IN ('job_order','combined')
          AND LOWER(TRIM(COALESCE(validation_status,''))) IN ('approved','completed','adjusted')
    ");
    $kpi_jo_mt_stmt->execute([$station_id, $start, $end]);
    $kpi_total_services += (int)($kpi_jo_mt_stmt->fetchColumn() ?? 0);
} catch (Exception $_kpie3) {}

try {
    // Top 5 items sold in date range
    $kpi_items_stmt = $pdo->prepare("
        SELECT mti.product_name,
               SUM(mti.quantity) AS total_qty
        FROM merchandise_transaction_items mti
        JOIN merchandise_transactions mt ON mt.id = mti.transaction_id
        WHERE mt.station_id = ?
          AND DATE(mt.transaction_date) BETWEEN ? AND ?
          AND COALESCE(mti.item_type,'merchandise') = 'merchandise'
          AND LOWER(TRIM(COALESCE(mt.validation_status,''))) IN ('approved','completed','adjusted')
        GROUP BY mti.product_name
        ORDER BY total_qty DESC
        LIMIT 5
    ");
    $kpi_items_stmt->execute([$station_id, $start, $end]);
    $kpi_top_items = $kpi_items_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $_kpie4) {}

try {
    // Top encoder by transaction count (staff with most approved records)
    $kpi_enc_stmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),
                       u.username, 'Unknown')                            AS staff_name,
               COUNT(*) AS enc_count
        FROM merchandise_transactions mt
        JOIN users u ON u.id = mt.staff_id
        WHERE mt.station_id = ?
          AND DATE(mt.transaction_date) BETWEEN ? AND ?
          AND LOWER(TRIM(COALESCE(mt.validation_status,''))) IN ('approved','completed','adjusted')
        GROUP BY mt.staff_id
        ORDER BY enc_count DESC
        LIMIT 1
    ");
    $kpi_enc_stmt->execute([$station_id, $start, $end]);
    $kpi_top_encoder = $kpi_enc_stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Exception $_kpie5) {}

// â”€â”€ 2. Variance Alerts â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// Scans approved/completed merch transactions in the date range for:
//   A) qty > current stock_level
//   B) sum(item qty Ã— unit_price) != total_amount by > â‚±0.01
$variance_alerts     = [];
$variance_alert_count = 0;
try {
    // Load product stock map for this station
    $va_stock_map = []; // product_id â†’ stock_level
    $va_stock_stmt = $pdo->prepare("
        SELECT product_id, COALESCE(stock_level, 0) AS stock_level
        FROM station_inventory WHERE station_id = ?
    ");
    $va_stock_stmt->execute([$station_id]);
    foreach ($va_stock_stmt->fetchAll(PDO::FETCH_ASSOC) as $_vs)
        $va_stock_map[(int)$_vs['product_id']] = (float)$_vs['stock_level'];

    // Fetch IDs + totals of approved transactions in range
    $va_txn_stmt = $pdo->prepare("
        SELECT mt.id, mt.transaction_id AS txn_ref, mt.total_amount
        FROM merchandise_transactions mt
        WHERE mt.station_id = ?
          AND DATE(CASE WHEN mt.transaction_date > '2000-01-01' THEN mt.transaction_date ELSE mt.created_at END) BETWEEN ? AND ?
          AND LOWER(TRIM(COALESCE(mt.validation_status,''))) IN ('approved','completed','adjusted')
        LIMIT 500
    ");
    $va_txn_stmt->execute([$station_id, $start, $end]);
    $va_txns = $va_txn_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($va_txns)) {
        $va_ids = array_column($va_txns, 'id');
        $va_ph  = implode(',', array_fill(0, count($va_ids), '?'));
        $va_items_stmt = $pdo->prepare("
            SELECT mti.transaction_id, mti.product_id, mti.product_name,
                   mti.quantity, mti.unit_price
            FROM merchandise_transaction_items mti
            WHERE mti.transaction_id IN ($va_ph)
              AND COALESCE(mti.item_type,'merchandise') = 'merchandise'
        ");
        $va_items_stmt->execute($va_ids);
        $va_items_by_txn = [];
        foreach ($va_items_stmt->fetchAll(PDO::FETCH_ASSOC) as $_vi)
            $va_items_by_txn[$_vi['transaction_id']][] = $_vi;

        foreach ($va_txns as $_vt) {
            $items = $va_items_by_txn[$_vt['id']] ?? [];
            $computed_sum = 0;
            foreach ($items as $_vitem) {
                $computed_sum += $_vitem['quantity'] * $_vitem['unit_price'];
                $pid = (int)$_vitem['product_id'];
                if ($pid && isset($va_stock_map[$pid]) && $_vitem['quantity'] > $va_stock_map[$pid]) {
                    $variance_alerts[] = [
                        'txn_ref' => $_vt['txn_ref'],
                        'id'      => $_vt['id'],
                        'type'    => 'qty',
                        'message' => 'Qty mismatch: ' . htmlspecialchars($_vitem['product_name'])
                                   . ' â€” encoded ' . (int)$_vitem['quantity']
                                   . ' pc(s), stock ' . (int)$va_stock_map[$pid],
                    ];
                }
            }
            if (!empty($items) && (float)$_vt['total_amount'] > 0
                && abs($computed_sum - (float)$_vt['total_amount']) > 0.01) {
                $variance_alerts[] = [
                    'txn_ref' => $_vt['txn_ref'],
                    'id'      => $_vt['id'],
                    'type'    => 'amount',
                    'message' => 'Amount mismatch: computed â‚±' . number_format($computed_sum, 2)
                               . ' vs encoded â‚±' . number_format((float)$_vt['total_amount'], 2),
                ];
            }
        }
    }
    $variance_alert_count = count($variance_alerts);
} catch (Exception $_vae) {}

// â”€â”€ 3. Inventory Impact lookup â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// Keyed by "{_source}:{row_id}" â†’ array of per-item deduction info
// Uses merchandise_transaction_items + station_inventory
$inv_impact = [];
try {
    $ii_mt_ids = array_column(
        array_filter($rows, fn($r) => ($r['_source'] ?? '') === 'merchandise_transactions'),
        'row_id'
    );
    if (!empty($ii_mt_ids)) {
        // Check if inventory_deducted column exists
        $mt_has_deducted = ato_has($mt_cols, 'inventory_deducted');
        $mt_deducted_col = $mt_has_deducted ? 'mt.inventory_deducted' : '0';

        $ii_ph = implode(',', array_fill(0, count($ii_mt_ids), '?'));
        $ii_stmt = $pdo->prepare("
            SELECT mti.transaction_id, mti.product_id, mti.product_name,
                   mti.quantity, mti.unit_price,
                   COALESCE(si.stock_level, 0) AS stock_level,
                   mt.validation_status,
                   {$mt_deducted_col} AS inventory_deducted
            FROM merchandise_transaction_items mti
            JOIN merchandise_transactions mt ON mt.id = mti.transaction_id
            LEFT JOIN station_inventory si
                   ON si.product_id = mti.product_id AND si.station_id = ?
            WHERE mti.transaction_id IN ($ii_ph)
              AND COALESCE(mti.item_type,'merchandise') = 'merchandise'
            ORDER BY mti.id
        ");
        $ii_stmt->execute(array_merge([$station_id], $ii_mt_ids));
        foreach ($ii_stmt->fetchAll(PDO::FETCH_ASSOC) as $_ii) {
            $ikey = 'merchandise_transactions:' . $_ii['transaction_id'];
            $is_approved = in_array(strtolower($_ii['validation_status'] ?? ''),
                                    ['approved','completed','adjusted']);
            $ded = (int)$_ii['inventory_deducted'];
            if ($is_approved && $ded)       $ist = 'yes';
            elseif ($is_approved && !$ded)  $ist = 'no';
            elseif (!$_ii['product_id'])    $ist = 'na';
            else                            $ist = 'pending';
            $inv_impact[$ikey][] = [
                'part'   => $_ii['product_name'],
                'qty'    => (int)$_ii['quantity'],
                'stock'  => (float)$_ii['stock_level'],
                'status' => $ist,
            ];
        }
    }
    // For job_orders rows â€” parse required_parts JSON
    foreach ($rows as $_jor) {
        if (($_jor['_source'] ?? '') !== 'job_orders') continue;
        $ikey = 'job_orders:' . $_jor['row_id'];
        $rp   = is_string($_jor['items_parts'] ?? '') ? $_jor['items_parts'] : '';
        // items_parts is text from GROUP_CONCAT, not JSON â€” show N/A
        $inv_impact[$ikey] = []; // job_orders: shown as service-only
    }
} catch (Exception $_iie) {}

// â”€â”€ 4. Receivables Aging â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// Checks balance_due + due_date per row. Overdue = due_date <= today and not paid.
$receivables = []; // row_id â†’ ['balance'=>..., 'due_date'=>..., 'overdue_days'=>..., 'aging_label'=>...]
$today_ts = strtotime(date('Y-m-d'));
try {
    $mt_has_due    = ato_has($mt_cols, 'due_date');
    $mt_has_bal    = ato_has($mt_cols, 'balance_due');
    $mt_has_paid   = ato_has($mt_cols, 'amount_paid');

    if (!empty($ii_mt_ids ?? [])) {
        $rec_ph = implode(',', array_fill(0, count($ii_mt_ids), '?'));
        $due_col = $mt_has_due  ? 'mt.due_date'    : 'NULL';
        $bal_col = $mt_has_bal  ? 'mt.balance_due' : 'NULL';
        $pai_col = $mt_has_paid ? 'mt.amount_paid' : 'NULL';
        $rec_stmt = $pdo->prepare("
            SELECT mt.id, $due_col AS due_date, $bal_col AS balance_due,
                   $pai_col AS amount_paid, mt.total_amount,
                   COALESCE(mt.payment_status,'') AS payment_status
            FROM merchandise_transactions mt
            WHERE mt.id IN ($rec_ph)
        ");
        $rec_stmt->execute($ii_mt_ids);
        foreach ($rec_stmt->fetchAll(PDO::FETCH_ASSOC) as $_rec) {
            $ps = strtolower(trim($_rec['payment_status'] ?? ''));
            if ($ps === 'paid') { $receivables[$_rec['id']] = ['settled' => true]; continue; }

            $bal = (float)($_rec['balance_due'] ?? 0);
            if ($bal <= 0.009) {
                $total = (float)$_rec['total_amount'];
                $paid  = (float)($_rec['amount_paid'] ?? 0);
                $bal   = max(0, $total - $paid);
            }
            $due_date = $_rec['due_date'] ?? null;
            $overdue_days = 0;
            $aging_label  = '';
            if ($due_date && strtotime($due_date) !== false) {
                $diff = (int)floor(($today_ts - strtotime($due_date)) / 86400);
                if ($diff > 0) {
                    $overdue_days = $diff;
                    $aging_label  = $diff . ' day' . ($diff === 1 ? '' : 's') . ' overdue';
                } elseif ($diff === 0) {
                    $aging_label = 'Due today';
                } else {
                    $aging_label = abs($diff) . 'd remaining';
                }
            }
            $receivables[$_rec['id']] = [
                'settled'      => false,
                'balance'      => $bal,
                'due_date'     => $due_date,
                'overdue_days' => $overdue_days,
                'aging_label'  => $aging_label,
            ];
        }
    }
} catch (Exception $_rece) {}

// â”€â”€ 5. Validation Notes (manager remarks) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// Fetches admin_remarks / manager_notes / rejection_reason per row from DB
$validation_notes = []; // "{_source}:{row_id}" â†’ string
try {
    // Merchandise transactions
    if (!empty($ii_mt_ids ?? [])) {
        $vn_has_mgr = ato_has($mt_cols, 'manager_notes');
        $vn_has_adj = ato_has($mt_cols, 'adjustment_reason');
        $vn_has_rej = ato_has($mt_cols, 'rejection_reason');
        $vn_has_rem = ato_has($mt_cols, 'remarks');

        $vn_note_col = $vn_has_mgr ? 'mt.manager_notes'
                     : ($vn_has_rej ? 'mt.rejection_reason'
                     : ($vn_has_adj ? 'mt.adjustment_reason'
                     : ($vn_has_rem ? 'mt.remarks' : 'NULL')));

        $vn_ph = implode(',', array_fill(0, count($ii_mt_ids), '?'));
        $vn_stmt = $pdo->prepare("
            SELECT mt.id, COALESCE($vn_note_col,'') AS note,
                   COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''), u.username,'') AS validated_by_name,
                   mt.validated_at
            FROM merchandise_transactions mt
            LEFT JOIN users u ON u.id = mt.validated_by
            WHERE mt.id IN ($vn_ph)
        ");
        $vn_stmt->execute($ii_mt_ids);
        foreach ($vn_stmt->fetchAll(PDO::FETCH_ASSOC) as $_vn) {
            $note = trim($_vn['note'] ?? '');
            if (empty($note) && !empty($_vn['validated_by_name'])) {
                $note = 'Reviewed by ' . $_vn['validated_by_name'];
                if (!empty($_vn['validated_at']))
                    $note .= ' on ' . date('M d, Y', strtotime($_vn['validated_at']));
            }
            $validation_notes['merchandise_transactions:' . $_vn['id']] = $note;
        }
    }
    // Job orders
    $jo_ids = array_column(
        array_filter($rows, fn($r) => ($r['_source'] ?? '') === 'job_orders'),
        'row_id'
    );
    if (!empty($jo_ids)) {
        $vn_jo_has_adm = ato_has($jo_cols, 'admin_remarks');
        $vn_jo_has_adj = ato_has($jo_cols, 'adjustment_reason');
        $vn_jo_note_col = $vn_jo_has_adm ? 'jo.admin_remarks'
                        : ($vn_jo_has_adj ? 'jo.adjustment_reason' : 'NULL');
        $jo_vn_ph = implode(',', array_fill(0, count($jo_ids), '?'));
        $jo_vn_stmt = $pdo->prepare("
            SELECT jo.id, COALESCE($vn_jo_note_col,'') AS note,
                   COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''), u.username,'') AS validated_by_name,
                   jo.validated_at
            FROM job_orders jo
            LEFT JOIN users u ON u.id = jo.validated_by
            WHERE jo.id IN ($jo_vn_ph)
        ");
        $jo_vn_stmt->execute($jo_ids);
        foreach ($jo_vn_stmt->fetchAll(PDO::FETCH_ASSOC) as $_jvn) {
            $note = trim($_jvn['note'] ?? '');
            if (empty($note) && !empty($_jvn['validated_by_name'])) {
                $note = 'Reviewed by ' . $_jvn['validated_by_name'];
            }
            $validation_notes['job_orders:' . $_jvn['id']] = $note;
        }
    }
} catch (Exception $_vne) {}

// â”€â”€ Export output â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($export_type === 'csv') {
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Transaction ID', 'Customer', 'Type', 'Vehicle', 'Items/Parts', 'Service Type', 'Amount', 'Payment Method', 'Payment Status', 'Validation Status', 'Date/Time', 'Staff']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['txn_id'],
            $r['customer'],
            $r['entry_type'],
            $r['vehicle_info'] ?? 'â€”',
            $r['items_parts'] ?? 'N/A',
            $r['service_type'] ?? 'N/A',
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
    foreach (['Transaction ID','Customer','Type','Vehicle','Items / Parts','Service Type','Amount','Payment Method','Payment Status','Validation Status','Date/Time','Staff'] as $h)
        echo '<th>' . htmlspecialchars($h) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $pay_st = ato_pay_status($r);
        echo '<tr>';
        echo '<td>' . htmlspecialchars($r['txn_id'])            . '</td>';
        echo '<td>' . htmlspecialchars($r['customer'])          . '</td>';
        echo '<td>' . htmlspecialchars($r['entry_type'])        . '</td>';
        echo '<td>' . htmlspecialchars($r['vehicle_info'] ?? 'â€”') . '</td>';
        echo '<td>' . htmlspecialchars($r['items_parts'] ?? 'N/A')     . '</td>';
        echo '<td>' . htmlspecialchars($r['service_type'] ?? 'N/A')    . '</td>';
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
    echo '<td colspan="3"></td>';
    echo '</tr>';
    // correct total row
    echo '<tr style="font-weight:800;background:#f0f7ff">';
    echo '<td colspan="6" style="text-align:right"><strong>TOTAL</strong></td>';
    echo '<td style="text-align:right"><strong>&#8369;' . number_format($total_amount, 2) . '</strong></td>';
    echo '<td colspan="5">' . count($rows) . ' record(s)</td>';
    echo '</tr>';
    echo '</tbody></table></body></html>';
    exit;
}

// â”€â”€ PDF export â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($export_type === 'pdf') {
    header('Content-Type: text/html; charset=utf-8');
    $logo_url  = '../assets/img/Petron%20Logo.png';
    $generated = date('F d, Y  h:i A');
    $rec_count = count($rows);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
    echo '<title>Transactions Oversight | Petron Station Management</title>';
    echo '<style>';
    echo 'body{font-family:Arial,Helvetica,sans-serif;font-size:14px;margin:0;padding:0;background:#f1f5f9;color:#1e293b;}';
    echo '.action-bar{background:#002F70;padding:12px 24px;display:flex;flex-direction:column;align-items:center;text-align:center;position:sticky;top:0;z-index:999;}';
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
    foreach (['Transaction ID','Customer','Type','Vehicle','Items / Parts','Service Type','Amount','Payment Method','Payment Status','Validation Status','Date/Time','Staff'] as $h)
        echo '<th>' . htmlspecialchars($h) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $pay_st = ato_pay_status($r);
        echo '<tr>';
        echo '<td>' . htmlspecialchars($r['txn_id'])            . '</td>';
        echo '<td>' . htmlspecialchars($r['customer'])          . '</td>';
        echo '<td>' . htmlspecialchars($r['entry_type'])        . '</td>';
        echo '<td>' . htmlspecialchars($r['vehicle_info'] ?? 'â€”') . '</td>';
        echo '<td>' . htmlspecialchars(mb_substr($r['items_parts'] ?? 'N/A', 0, 40)) . '</td>';
        echo '<td>' . htmlspecialchars(mb_substr($r['service_type'] ?? 'N/A', 0, 30)) . '</td>';
        echo '<td class="amount">&#8369;' . number_format((float)$r['amount'], 2) . '</td>';
        echo '<td>' . htmlspecialchars($r['payment_method'])    . '</td>';
        echo '<td>' . htmlspecialchars($pay_st)                 . '</td>';
        echo '<td>' . htmlspecialchars($r['validation_status']) . '</td>';
        echo '<td style="white-space:nowrap">' . date('M d, Y H:i', strtotime($r['txn_date'])) . '</td>';
        echo '<td>' . htmlspecialchars($r['staff_name'])        . '</td>';
        echo '</tr>';
    }
    $col_count = 12;
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
        <div class="sub">Systemâ€‘wide monitoring of validated transactions and receivables.</div>
    </div>
    <div class="actions" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <!-- Excel -->
        <button type="button" onclick="atoExport('excel')" class="ato-btn ato-btn-excel">
            <i class="fas fa-file-excel"></i> Excel
        </button>
        <!-- CSV -->
        <button type="button" onclick="atoExport('csv')" class="ato-btn ato-btn-csv">
            <i class="fas fa-file-csv"></i> CSV
        </button>
        <!-- PDF -->
        <button type="button" onclick="atoExport('pdf')" class="ato-btn ato-btn-pdf">
            <i class="fas fa-file-pdf"></i> PDF
        </button>
        <a href="<?= in_array($role, ['admin', 'superadmin']) ? 'admin_dashboard.php' : 'manager_dashboard.php'; ?>" class="ato-btn ato-btn-back">
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

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:14px;">
    <!-- Total Sales Card -->
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;display:flex;align-items:center;gap:14px;">
        <div style="width:44px;height:44px;border-radius:50%;background:#dbeafe;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fas fa-peso-sign" style="color:#1d4ed8;font-size:18px;"></i>
        </div>
        <div style="flex:1;min-width:0;">
            <div style="font-size:20px;font-weight:700;color:#002F70;line-height:1.2;">₱<?= number_format($kpi_total_sales, 2) ?></div>
            <div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.3px;font-weight:600;margin-top:4px;">TOTAL SALES</div>
            <div style="font-size:10px;color:#94a3b8;margin-top:2px;"><?= htmlspecialchars($start) ?> to <?= htmlspecialchars($end) ?></div>
        </div>
    </div>
    
    <!-- Total Services Card -->
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;display:flex;align-items:center;gap:14px;">
        <div style="width:44px;height:44px;border-radius:50%;background:#dcfce7;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fas fa-wrench" style="color:#16a34a;font-size:18px;"></i>
        </div>
        <div style="flex:1;min-width:0;">
            <div style="font-size:20px;font-weight:700;color:#002F70;line-height:1.2;"><?= (int)$kpi_total_services ?></div>
            <div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.3px;font-weight:600;margin-top:4px;">TOTAL SERVICES</div>
            <div style="font-size:10px;color:#94a3b8;margin-top:2px;">Approved / Completed</div>
        </div>
    </div>
    
    <!-- Top Items Sold Card -->
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
            <div style="width:32px;height:32px;border-radius:50%;background:#fef3c7;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="fas fa-star" style="color:#d97706;font-size:14px;"></i>
            </div>
            <div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.3px;font-weight:700;">TOP ITEMS SOLD</div>
        </div>
        <?php if (empty($kpi_top_items)): ?>
            <div style="font-size:12px;color:#94a3b8;text-align:center;padding:8px 0;">No data</div>
        <?php else: foreach ($kpi_top_items as $_idx => $_ti): ?>
            <div style="font-size:11px;display:flex;justify-content:space-between;align-items:center;padding:4px 0;border-bottom:1px solid #f1f5f9;">
                <span style="color:#374151;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;">
                    <?= htmlspecialchars($_ti['product_name']) ?>
                </span>
                <strong style="color:#002F70;margin-left:8px;white-space:nowrap;"><?= (int)$_ti['total_qty'] ?> pc</strong>
            </div>
        <?php endforeach; endif; ?>
    </div>
    
    <!-- Top Encoder Card -->
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;display:flex;align-items:center;gap:14px;">
        <div style="width:44px;height:44px;border-radius:50%;background:#fef3c7;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fas fa-user-check" style="color:#d97706;font-size:18px;"></i>
        </div>
        <div style="flex:1;min-width:0;">
            <div style="font-size:16px;font-weight:700;color:#002F70;line-height:1.2;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= $kpi_top_encoder ? htmlspecialchars($kpi_top_encoder['staff_name']) : '—' ?>
            </div>
            <div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.3px;font-weight:600;margin-top:4px;">TOP ENCODER</div>
            <div style="font-size:10px;color:#94a3b8;margin-top:2px;">
                <?= $kpi_top_encoder ? (int)$kpi_top_encoder['enc_count'] . ' txn(s) validated' : 'No data' ?>
            </div>
        </div>
    </div>
    
    <!-- Variance Alerts Card -->
    <div style="background:<?= $variance_alert_count > 0 ? '#fff3f3' : '#f0fdf4' ?>;
                border:1px solid <?= $variance_alert_count > 0 ? '#fecaca' : '#bbf7d0' ?>;
                border-radius:10px;padding:16px;display:flex;align-items:center;gap:14px;cursor:pointer;"
         onclick="document.getElementById('varianceAlertsPanel').style.display=(document.getElementById('varianceAlertsPanel').style.display==='none'?'block':'none');">
        <div style="width:44px;height:44px;border-radius:50%;background:<?= $variance_alert_count > 0 ? '#fee2e2' : '#dcfce7' ?>;
                    display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fas fa-<?= $variance_alert_count > 0 ? 'exclamation-triangle' : 'check-circle' ?>"
               style="color:<?= $variance_alert_count > 0 ? '#dc2626' : '#16a34a' ?>;font-size:18px;"></i>
        </div>
        <div style="flex:1;min-width:0;">
            <div style="font-size:20px;font-weight:700;color:<?= $variance_alert_count > 0 ? '#dc2626' : '#16a34a' ?>;line-height:1.2;">
                <?= (int)$variance_alert_count ?>
            </div>
            <div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.3px;font-weight:600;margin-top:4px;">VARIANCE ALERTS</div>
            <div style="font-size:10px;color:#94a3b8;margin-top:2px;"><?= $variance_alert_count > 0 ? 'Click to view' : 'All clear' ?></div>
        </div>
    </div>
</div>


<!-- Variance Alerts expandable panel -->
<?php if ($variance_alert_count > 0): ?>
<div id="varianceAlertsPanel" style="display:none;background:#fff3f3;border:1px solid #fecaca;
     border-radius:10px;padding:14px 18px;margin-bottom:14px;">
    <div style="font-weight:700;color:#dc2626;margin-bottom:10px;font-size:13px;">
        <i class="fas fa-exclamation-triangle"></i>
        Variance Alerts â€” <?= (int)$variance_alert_count ?> flag(s) in <?= htmlspecialchars($start) ?> â€“ <?= htmlspecialchars($end) ?>
    </div>
    <div style="display:flex;flex-direction:column;gap:6px;max-height:280px;overflow-y:auto;">
        <?php foreach ($variance_alerts as $_va): ?>
        <div style="background:#fff;border:1px solid #fecaca;border-radius:6px;padding:8px 12px;font-size:12px;">
            <strong style="color:#002F70;"><?= htmlspecialchars($_va['txn_ref']) ?></strong>
            <span style="margin-left:6px;padding:1px 6px;border-radius:3px;font-size:11px;font-weight:600;
                         background:<?= $_va['type']==='qty'?'#fef3c7':'#fee2e2' ?>;
                         color:<?= $_va['type']==='qty'?'#92400e':'#991b1b' ?>;">
                <?= $_va['type']==='qty'?'Qty Mismatch':'Amount Mismatch' ?>
            </span>
            <span style="color:#64748b;margin-left:6px;"><?= htmlspecialchars($_va['message']) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- â”€â”€ Filter Bar â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
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
                $status_opts = ['Approved','Adjusted','Completed','Rejected'];
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





<!-- â”€â”€ Unified Table â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
<div class="card" style="padding:0;">
    <div style="overflow:hidden;border-radius:12px;">
    <table class="ato-table">
        <colgroup>
            <col style="width:7%;">   <!-- Txn ID -->
            <col style="width:8%;">   <!-- Customer -->
            <col style="width:5%;">   <!-- Type -->
            <col style="width:6%;">   <!-- Vehicle -->
            <col style="width:11%;">  <!-- Items / Parts -->
            <col style="width:8%;">   <!-- Service -->
            <col style="width:6%;">   <!-- Amount -->
            <col style="width:5%;">   <!-- Payment -->
            <col style="width:7%;">   <!-- Pay Status + Aging -->
            <col style="width:5%;">   <!-- Validation -->
            <col style="width:8%;">   <!-- Inv. Impact -->
            <col style="width:9%;">   <!-- Validation Notes -->
            <col style="width:7%;">   <!-- Date / Time -->
            <col style="width:8%;">   <!-- Staff -->
        </colgroup>
        <thead>
            <tr>
                <th>Txn ID</th>
                <th>Customer</th>
                <th>Type</th>
                <th>Vehicle</th>
                <th>Items / Parts</th>
                <th>Service</th>
                <th style="text-align:right;">Amount</th>
                <th style="text-align:center;">Payment</th>
                <th style="text-align:center;">Pay Status</th>
                <th style="text-align:center;">Validation</th>
                <th>Inv. Impact</th>
                <th>Validation Notes</th>
                <th>Date / Time</th>
                <th>Staff</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($rows) > 0): ?>
                <?php foreach ($rows as $r):
                    $vs      = strtolower(trim($r['validation_status'] ?? ''));
                    $pay_st  = ato_pay_status($r);
                    $et      = $r['entry_type'] ?? '';
                    $ikey    = ($r['_source'] ?? 'job_orders') . ':' . $r['row_id'];
                    $ii_items = $inv_impact[$ikey] ?? [];

                    // Receivables aging
                    $rec_data    = $receivables[$r['row_id']] ?? null;
                    $aging_label = '';
                    $aging_color = '#64748b';
                    $show_bal    = false;
                    $rec_bal     = 0;
                    if ($rec_data && !($rec_data['settled'] ?? false)) {
                        $rec_bal  = (float)($rec_data['balance'] ?? 0);
                        $show_bal = $rec_bal > 0.009;
                        if (!empty($rec_data['aging_label'])) {
                            $aging_label = $rec_data['aging_label'];
                            $aging_color = $rec_data['overdue_days'] > 0 ? '#dc2626' : '#16a34a';
                        }
                    }

                    // Validation note
                    $val_note = trim($validation_notes[$ikey] ?? '');
                ?>
                <tr>
                    <td style="font-weight:600;font-size:11px;font-family:monospace;overflow:hidden;text-overflow:ellipsis;"
                        title="<?= htmlspecialchars($r['txn_id']) ?>">
                        <?= htmlspecialchars($r['txn_id']) ?>
                    </td>
                    <td style="overflow:hidden;text-overflow:ellipsis;"
                        title="<?= htmlspecialchars($r['customer']) ?>">
                        <?= htmlspecialchars($r['customer']) ?>
                    </td>
                    <td style="text-align:center;">
                        <span class="ato-badge ato-badge-type" style="font-size:10px;padding:1px 4px;">
                            <?= htmlspecialchars($et) ?>
                        </span>
                    </td>
                    <td style="color:#475569;overflow:hidden;text-overflow:ellipsis;"
                        title="<?= htmlspecialchars($r['vehicle_info'] ?? 'â€”') ?>">
                        <?= htmlspecialchars($r['vehicle_info'] ?? 'â€”') ?: 'â€”' ?>
                    </td>
                    <td style="line-height:1.3;overflow:hidden;"
                        title="<?= htmlspecialchars($r['items_parts'] ?? 'N/A') ?>">
                        <?= htmlspecialchars(mb_strimwidth($r['items_parts'] ?? 'N/A', 0, 50, 'â€¦')) ?>
                    </td>
                    <td style="line-height:1.3;overflow:hidden;"
                        title="<?= htmlspecialchars($r['service_type'] ?? 'N/A') ?>">
                        <?= htmlspecialchars(mb_strimwidth($r['service_type'] ?? 'N/A', 0, 40, 'â€¦')) ?>
                    </td>
                    <td style="font-weight:700;color:#002F70;text-align:right;white-space:nowrap;">
                        â‚±<?= number_format((float)$r['amount'], 2) ?>
                    </td>
                    <td style="text-align:center;overflow:hidden;text-overflow:ellipsis;"
                        title="<?= htmlspecialchars($r['payment_method']) ?>">
                        <?= htmlspecialchars(mb_strimwidth($r['payment_method'], 0, 10, 'â€¦')) ?>
                    </td>

                    <!-- Pay Status + Receivables Aging -->
                    <td style="text-align:center;">
                        <span class="ato-badge ato-badge-<?= strtolower(str_replace(' ', '-', $pay_st)) ?>"
                              style="font-size:10px;padding:1px 4px;">
                            <?= htmlspecialchars($pay_st) ?>
                        </span>
                        <?php if ($show_bal): ?>
                        <div style="font-size:10px;color:#9a3412;font-weight:700;margin-top:2px;white-space:nowrap;">
                            â‚±<?= number_format($rec_bal, 2) ?>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($aging_label)): ?>
                        <div style="font-size:10px;color:<?= $aging_color ?>;font-weight:600;margin-top:1px;">
                            <?= $rec_data['overdue_days'] > 0 ? 'âš  ' : '' ?><?= htmlspecialchars($aging_label) ?>
                        </div>
                        <?php endif; ?>
                    </td>

                    <td style="text-align:center;">
                        <span class="ato-badge ato-badge-<?= $vs ?>"
                              style="font-size:10px;padding:1px 4px;">
                            <?= htmlspecialchars(ucfirst($r['validation_status'])) ?>
                        </span>
                    </td>

                    <!-- Inventory Impact -->
                    <td style="line-height:1.6;">
                        <?php if (empty($ii_items) && ($r['_source'] ?? '') === 'job_orders'): ?>
                            <span style="color:#94a3b8;font-size:10px;">Svc only</span>
                        <?php elseif (empty($ii_items)): ?>
                            <span style="color:#cbd5e1;font-size:10px;">â€”</span>
                        <?php else: foreach ($ii_items as $_ii):
                            if ($_ii['status'] === 'yes')    {
                                $ic_bg='#dcfce7'; $ic_c='#166534'; $ic_b='#86efac'; $ic_icon='âœ“'; $il='Deducted';
                            } elseif ($_ii['status'] === 'no') {
                                $ic_bg='#fef9c3'; $ic_c='#92400e'; $ic_b='#fde68a'; $ic_icon='âœ—'; $il='Not Yet';
                            } elseif ($_ii['status'] === 'na') {
                                $ic_bg='#f1f5f9'; $ic_c='#94a3b8'; $ic_b='#e2e8f0'; $ic_icon='â€”'; $il='N/A';
                            } else {
                                $ic_bg='#eff6ff'; $ic_c='#1d4ed8'; $ic_b='#bfdbfe'; $ic_icon='â³'; $il='Pending';
                            }
                        ?>
                        <div style="margin-bottom:3px;"
                             title="<?= htmlspecialchars($_ii['part']) ?> Ã— <?= (int)$_ii['qty'] ?> pc">
                            <div style="font-size:10px;color:#475569;white-space:nowrap;
                                        overflow:hidden;text-overflow:ellipsis;max-width:100px;">
                                <?= htmlspecialchars(mb_strimwidth($_ii['part'],0,12,'â€¦')) ?> Ã—<?= (int)$_ii['qty'] ?>
                            </div>
                            <span style="display:inline-block;background:<?= $ic_bg ?>;color:<?= $ic_c ?>;
                                         border:1px solid <?= $ic_b ?>;font-size:10px;font-weight:700;
                                         padding:1px 5px;border-radius:10px;margin-top:1px;white-space:nowrap;">
                                <?= $ic_icon ?> <?= $il ?>
                            </span>
                        </div>
                        <?php endforeach; endif; ?>
                    </td>

                    <!-- Validation Notes -->
                    <td style="color:#475569;line-height:1.4;overflow:hidden;"
                        title="<?= htmlspecialchars($val_note) ?>">
                        <?php if (!empty($val_note)): ?>
                        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;
                                    padding:3px 6px;font-size:10px;">
                            <span style="font-size:9px;font-weight:700;color:#1d4ed8;
                                         text-transform:uppercase;letter-spacing:.3px;display:block;">
                                âœ… Manager
                            </span>
                            <div style="color:#1e3a5f;margin-top:1px;">
                                <?= htmlspecialchars(mb_strimwidth($val_note, 0, 55, 'â€¦')) ?>
                            </div>
                        </div>
                        <?php else: ?>
                            <span style="color:#cbd5e1;font-size:10px;">â€”</span>
                        <?php endif; ?>
                    </td>

                    <td style="color:#64748b;overflow:hidden;text-overflow:ellipsis;">
                        <?= date('M j, Y', strtotime($r['txn_date'])) ?><br>
                        <span style="font-size:10px;"><?= date('H:i', strtotime($r['txn_date'])) ?></span>
                    </td>
                    <td style="color:#64748b;overflow:hidden;text-overflow:ellipsis;"
                        title="<?= htmlspecialchars($r['staff_name']) ?>">
                        <?= htmlspecialchars(mb_strimwidth($r['staff_name'], 0, 14, 'â€¦')) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="14" style="text-align:center;padding:40px 20px;color:#94a3b8;">
                        <i class="fas fa-inbox" style="font-size:36px;display:block;margin-bottom:10px;opacity:0.3;"></i>
                        <div style="font-size:14px;font-weight:600;color:#64748b;margin-bottom:4px;">No Transactions Found</div>
                        <div style="font-size:12px;">Try adjusting your filters or date range.</div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<style>
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
    border:1px solid transparent;
    border-radius:7px;
    font-size:13px;
    font-weight:600;
    cursor:pointer;
    text-decoration:none;
    white-space:nowrap;
    background:white !important;
    transition:all .15s; 
}
.ato-btn-search { color:#002F70 !important; border-color:#002F70 !important; }
.ato-btn-search:hover { background:#002F70 !important; color:#fff !important; }
.ato-btn-reset  { color:#4b5563 !important; border-color:#6b7280 !important; }
.ato-btn-reset:hover { background:#6b7280 !important; color:#fff !important; }

/* Export button styles - color-coded outline design */
.ato-btn-excel  { color:#1d6f42 !important; border-color:#1d6f42 !important; }
.ato-btn-excel:hover  { background:#1d6f42 !important; color:#fff !important; }
.ato-btn-csv    { color:#003d7a !important; border-color:#003d7a !important; }
.ato-btn-csv:hover    { background:#003d7a !important; color:#fff !important; }
.ato-btn-pdf    { color:#dc2626 !important; border-color:#dc2626 !important; }
.ato-btn-pdf:hover    { background:#dc2626 !important; color:#fff !important; }
.ato-btn-back   { color:#4b5563 !important; border-color:#6b7280 !important; }
.ato-btn-back:hover   { background:#6b7280 !important; color:#fff !important; }

/* â”€â”€ Table Styles with Blue Headers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
.ato-table { 
    width:100%;
    border-collapse:collapse;
    border-spacing:0;
    font-size:11px;
    table-layout:fixed;
    word-wrap:break-word;
    overflow-wrap:break-word;
}
.ato-table thead th { 
    background:#002F70;
    color:#fff;
    font-size:11px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.2px;
    padding:9px 6px;
    border-bottom:2px solid #001a3d;
    text-align:left;
    vertical-align:middle;
    overflow:hidden;
    text-overflow:ellipsis;
}
.ato-table tbody td { 
    padding:7px 6px;
    border-bottom:1px solid #f1f5f9;
    vertical-align:middle;
    background:#fff;
    overflow:hidden;
    text-overflow:ellipsis;
    font-size:11px;
    word-break:break-word;
    overflow-wrap:break-word;
    white-space:normal;
    max-width:0; /* forces text-overflow on fixed-layout table */
}
.ato-table tbody tr:hover td { 
    background:#eff6ff;
}

/* Specific column alignments */
.ato-table th:nth-child(7),
.ato-table td:nth-child(7) { text-align:right; }
.ato-table th:nth-child(8),
.ato-table td:nth-child(8),
.ato-table th:nth-child(9),
.ato-table td:nth-child(9),
.ato-table th:nth-child(10),
.ato-table td:nth-child(10) { text-align:center; }

/* â”€â”€ Plain Text Badges â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
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

/* â”€â”€ Summary Bar â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
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

/* â”€â”€ Modal Overlay (hidden by default) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
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

/* â”€â”€ Responsive â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
@media (max-width: 768px) {
    .ato-table { font-size:11px; }
    .ato-table thead th, .ato-table tbody td { padding:8px 6px; }
}

/* â”€â”€ Print Styles â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
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

<!-- â”€â”€ Reject Modal â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
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
            <textarea name="reason" id="ato_reject_reason" placeholder="Explain why this transaction is being returnedâ€¦" required></textarea>
            <div class="ato-modal-btns">
                <button type="button" class="ato-modal-cancel" onclick="atoCloseModal('atoRejectModal')">Cancel</button>
                <button type="submit" class="ato-modal-submit" style="background:#dc3545;">Return Transaction</button>
            </div>
        </form>
    </div>
</div>

<!-- â”€â”€ Adjust Modal â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
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
            <label>New Total Amount (â‚±) <span style="color:#dc3545;">*</span></label>
            <input type="number" name="adj_total" id="ato_adj_total" step="0.01" min="0" required>
            <label>Adjustment Note <span style="color:#dc3545;">*</span></label>
            <textarea name="adj_note" id="ato_adj_note" placeholder="Reason for adjustmentâ€¦" required></textarea>
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

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// AUTO-REFRESH: Admin Transactions Oversight (60-second polling for compliance)
// No manual refresh button needed - system automatically reflects manager-validated
// transactions and compliance alerts for admin oversight monitoring.
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
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

console.log('âœ… Auto-refresh enabled for Admin Transactions Oversight (60s interval)');

function atoExport(format) {
    const table = document.querySelector('.ato-table');
    if (!table) { alert('No transaction data found.'); return; }

    const dateFrom = document.querySelector('input[name="start"]')?.value || '';
    const dateTo   = document.querySelector('input[name="end"]')?.value || '';
    const filename = `Transactions_Oversight_${dateFrom || 'All'}_to_${dateTo || 'All'}`;

    if (format === 'excel') {
        if (typeof XLSX === 'undefined') {
            alert('Export library not loaded. Please try again.');
            return;
        }
        const aoa = [];
        // Headers
        table.querySelectorAll('thead tr').forEach(tr => {
            aoa.push([...tr.querySelectorAll('th')].map(th => th.innerText.trim()));
        });
        // Body
        table.querySelectorAll('tbody tr').forEach(tr => {
            aoa.push([...tr.querySelectorAll('td')].map(td => td.innerText.trim()));
        });
        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(aoa);
        // Column widths
        if (aoa.length && aoa[0]) {
            ws['!cols'] = aoa[0].map((_, ci) => ({
                wch: Math.min(45, Math.max(10, ...aoa.map(row => String(row[ci] ?? '').length)))
            }));
        }
        XLSX.utils.book_append_sheet(wb, ws, 'Transactions');
        XLSX.writeFile(wb, filename + '.xlsx');
    } else if (format === 'csv') {
        let csv = '';
        // Headers
        table.querySelectorAll('thead tr').forEach(tr => {
            csv += [...tr.querySelectorAll('th')].map(th => '"' + th.innerText.trim().replace(/"/g, '""') + '"').join(',') + '\n';
        });
        // Body
        table.querySelectorAll('tbody tr').forEach(tr => {
            csv += [...tr.querySelectorAll('td')].map(td => '"' + td.innerText.trim().replace(/"/g, '""') + '"').join(',') + '\n';
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
        
        // Construct printable HTML table
        let tableHtml = table.outerHTML;
        
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
        doc.write(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>Transactions Oversight Report</title>
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
            .ato-badge, .badge, .status-badge{border:none;background:none;padding:0;font-weight:normal;}
            tfoot tr{border-top:2px solid #002F70;background:#f2f2f2 !important;}
            tfoot td{padding:6px 5px;font-weight:700;}
        </style></head><body>
            <div class="header-container">
                <img src="${logo_url}" alt="Petron">
                <div class="header-title">
                    <h1>Petron Station Management System</h1>
                    <p>Transactions Oversight Report</p>
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

