<?php
$page_id = 'pending_transactions';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$station_id = user_station_id();
$role = role_key($me['role'] ?? '');

// ── Module gate ───────────────────────────────────────────────
if (!in_array($role, ['superadmin','developer']) && !is_module_enabled('transactions')) {
    render_module_disabled_page('Transactions');
}

$allowed_roles = [];
try {
    $stmt = $pdo->query("SELECT role_key FROM staff_role_config WHERE can_access_transactions = 1 AND active = 1");
    $allowed_roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch(Exception $e) {
    /* staff_role_config table doesn't exist — use standard roles */
}
if (empty($allowed_roles)) {
    $allowed_roles = ['manager', 'admin', 'superadmin'];
}

if (!in_array($role, $allowed_roles)) {
    $_SESSION['error'] = 'Access denied. Manager access required for Transactions Oversight.';
    header('Location: dashboard.php');
    exit;
}

// ── POST: Approve ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* Dynamically detect which columns exist in merchandise_transactions */
    $mt_cols = [];
    try {
        $col_rows = $pdo->query("SHOW COLUMNS FROM merchandise_transactions")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($col_rows as $cr) $mt_cols[strtolower($cr['Field'])] = true;
    } catch (Exception $e) {}
    $has_mt = fn($c) => isset($mt_cols[strtolower($c)]);

    /* Dynamically detect audit_trail columns */
    $at_cols = [];
    try {
        $at_rows = $pdo->query("SHOW COLUMNS FROM audit_trail")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($at_rows as $ar) $at_cols[strtolower($ar['Field'])] = true;
    } catch (Exception $e) {}
    $has_at = fn($c) => isset($at_cols[strtolower($c)]);

    /* Helper: safe audit trail insert */
    $insert_audit = function(int $txn_id, string $action_type, ?string $new_val = null) use ($pdo, $me, $station_id, $has_at) {
        try {
            $cols = ['action_type'];
            $vals = [$action_type];
            /* Map to whatever column names actually exist */
            $field_mappings = [
                [['transaction_id','txn_id','id'], $txn_id],
                [['manager_id','user_id','actor_id'], $me['id']],
                [['station_id'], $station_id],
                [['new_value','notes','reason'], $new_val],
                [['entity_type'], 'merchandise_transaction'],
            ];
            foreach ($field_mappings as $mapping) {
                $candidates = $mapping[0];
                $value = $mapping[1];
                foreach ($candidates as $col) {
                    if ($has_at($col)) { $cols[] = $col; $vals[] = $value; break; }
                }
            }
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $cl = implode(',', array_map(fn($c) => "`$c`", $cols));
            $pdo->prepare("INSERT INTO audit_trail ($cl) VALUES ($ph)")->execute($vals);
        } catch (Exception $ae) { /* silent — audit must not break main flow */ }
    };

    if ($action === 'approve_transaction') {
        $row_id = (int)($_POST['transaction_id'] ?? 0);
        try {
            /* Build UPDATE dynamically based on existing columns */
            $set_parts = ["validation_status = 'Approved'"];
            $set_vals  = [];
            if ($has_mt('validated_by'))  { $set_parts[] = "validated_by = ?";  $set_vals[] = $me['id']; }
            if ($has_mt('validated_at'))  { $set_parts[] = "validated_at = NOW()"; }
            if ($has_mt('updated_at'))    { $set_parts[] = "updated_at = NOW()"; }

            $stmt = $pdo->prepare("UPDATE merchandise_transactions SET " . implode(', ', $set_parts) . " WHERE id = ? AND station_id = ?");
            $stmt->execute(array_merge($set_vals, [$row_id, $station_id]));

            if ($stmt->rowCount() > 0) {
                $insert_audit($row_id, 'Approve');
                log_activity($pdo, $me['id'], 'Approve Transaction', "Merchandise transaction #{$row_id} approved by {$me['name']}");
                $_SESSION['success'] = 'Transaction approved and verified successfully.';
            } else {
                $_SESSION['error'] = 'Transaction not found or already processed.';
            }
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error approving: ' . $e->getMessage();
        }
        header('Location: transactions.php?' . http_build_query(array_filter(['start'=>$_POST['_start']??'','end'=>$_POST['_end']??'','status'=>$_POST['_status']??''])));
        exit;
    }

    if ($action === 'reject_transaction') {
        $row_id = (int)($_POST['transaction_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        try {
            $set_parts = ["validation_status = 'Rejected'"];
            $set_vals  = [];
            if ($has_mt('validated_by'))    { $set_parts[] = "validated_by = ?";    $set_vals[] = $me['id']; }
            if ($has_mt('validated_at'))    { $set_parts[] = "validated_at = NOW()"; }
            if ($has_mt('rejection_reason')){ $set_parts[] = "rejection_reason = ?"; $set_vals[] = $reason; }
            if ($has_mt('remarks') && !$has_mt('rejection_reason')) {
                /* Fall back to remarks column if rejection_reason doesn't exist */
                $set_parts[] = "remarks = ?"; $set_vals[] = 'RETURNED: ' . $reason;
            }
            if ($has_mt('updated_at'))      { $set_parts[] = "updated_at = NOW()"; }

            $stmt = $pdo->prepare("UPDATE merchandise_transactions SET " . implode(', ', $set_parts) . " WHERE id = ? AND station_id = ?");
            $stmt->execute(array_merge($set_vals, [$row_id, $station_id]));

            if ($stmt->rowCount() > 0) {
                $insert_audit($row_id, 'Return', $reason);
                log_activity($pdo, $me['id'], 'Return Transaction', "Merchandise transaction #{$row_id} returned by {$me['name']}. Reason: {$reason}");
                $_SESSION['success'] = 'Transaction returned to staff for correction.';
            } else {
                $_SESSION['error'] = 'Transaction not found or already processed.';
            }
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error returning transaction: ' . $e->getMessage();
        }
        header('Location: transactions.php?' . http_build_query(array_filter(['start'=>$_POST['_start']??'','end'=>$_POST['_end']??'','status'=>$_POST['_status']??'','type'=>$_POST['_type']??''])));
        exit;
    }

    // ── Adjust Merchandise Transaction ────────────────────────────────────────
    if ($action === 'adjust_transaction') {
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
                log_activity($pdo, $me['id'], 'Adjust Transaction', "Merchandise #{$row_id} adjusted to ₱{$new_total} by {$me['name']}.");
                $_SESSION['success'] = "Transaction #{$row_id} adjusted successfully.";
            } else {
                $_SESSION['error'] = 'Transaction not found or already processed.';
            }
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error adjusting: ' . $e->getMessage();
        }
        header('Location: transactions.php?' . http_build_query(array_filter(['start'=>$_POST['_start']??'','end'=>$_POST['_end']??'','status'=>$_POST['_status']??'','type'=>$_POST['_type']??''])));
        exit;
    }

    // ── Approve Job Order ─────────────────────────────────────────────────────
    if ($action === 'approve_job_order') {
        $jo_id    = (int)($_POST['jo_id'] ?? 0);
        $jo_src   = $_POST['jo_source'] ?? 'job_orders'; // 'job_orders' or 'merchandise_transactions'
        $remarks  = trim($_POST['remarks'] ?? '');
        try {
            $pdo->beginTransaction();
            if ($jo_src === 'merchandise_transactions') {
                // Record came from staff_transactions_hub.php — lives in merchandise_transactions
                $pdo->prepare("UPDATE merchandise_transactions SET validation_status='Approved', validated_by=?, validated_at=NOW(), updated_at=NOW() WHERE id=? AND station_id=?")
                    ->execute([$me['id'], $jo_id, $station_id]);
            } else {
                $pdo->prepare("UPDATE job_orders SET validation_status='Approved', status='Pending', validated_by=?, validated_at=NOW() WHERE id=? AND station_id=?")
                    ->execute([$me['id'], $jo_id, $station_id]);
                try { $pdo->prepare("INSERT INTO job_order_audit (job_order_id,action,before_status,after_status,performed_by,performed_at,notes,ip_address,user_agent) VALUES (?,?,?,?,?,NOW(),?,?,?)")
                    ->execute([$jo_id,'APPROVE','Pending Validation','Approved',$me['id'],$remarks,$_SERVER['REMOTE_ADDR']??'',$_SERVER['HTTP_USER_AGENT']??'']); } catch(Exception $ae){}
            }
            try { $pdo->prepare("INSERT INTO audit_trail (transaction_id,manager_id,action_type,new_value,station_id) VALUES (?,?,'Approve',?,?)")
                ->execute([$jo_id,$me['id'],"JO Approved. {$remarks}",$station_id]); } catch(Exception $ae){}
            log_activity($pdo,$me['id'],'JO_APPROVED',"Job Order #{$jo_id} approved by {$me['name']}.");
            $pdo->commit();
            $_SESSION['success'] = "Job Order #{$jo_id} approved successfully.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = 'Error approving JO: ' . $e->getMessage();
        }
        header('Location: transactions.php?' . http_build_query(array_filter(['start'=>$_POST['_start']??'','end'=>$_POST['_end']??'','status'=>$_POST['_status']??'','type'=>'jo'])));
        exit;
    }

    // ── Reject Job Order ──────────────────────────────────────────────────────
    if ($action === 'reject_job_order') {
        $jo_id   = (int)($_POST['jo_id'] ?? 0);
        $jo_src  = $_POST['jo_source'] ?? 'job_orders';
        $reason  = trim($_POST['reason'] ?? '');
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
            try { $pdo->prepare("INSERT INTO audit_trail (transaction_id,manager_id,action_type,new_value,station_id) VALUES (?,?,'Reject',?,?)")
                ->execute([$jo_id,$me['id'],"JO Rejected. Reason: {$reason}",$station_id]); } catch(Exception $ae){}
            log_activity($pdo,$me['id'],'JO_REJECTED',"Job Order #{$jo_id} rejected by {$me['name']}. Reason: {$reason}");
            $pdo->commit();
            $_SESSION['success'] = "Job Order #{$jo_id} rejected.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = 'Error rejecting JO: ' . $e->getMessage();
        }
        header('Location: transactions.php?' . http_build_query(array_filter(['start'=>$_POST['_start']??'','end'=>$_POST['_end']??'','status'=>$_POST['_status']??'','type'=>'jo'])));
        exit;
    }

    // ── Adjust Job Order ──────────────────────────────────────────────────────
    if ($action === 'adjust_job_order') {
        $jo_id    = (int)($_POST['jo_id'] ?? 0);
        $new_cost = (float)($_POST['adj_cost'] ?? 0);
        $adj_note = trim($_POST['adj_note'] ?? '');
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE job_orders SET total_cost=?, validation_status='Adjusted', validated_by=?, validated_at=NOW() WHERE id=? AND station_id=?")
                ->execute([$new_cost, $me['id'], $jo_id, $station_id]);
            try { $pdo->prepare("INSERT INTO audit_trail (transaction_id,manager_id,action_type,new_value,station_id) VALUES (?,?,'Adjust',?,?)")
                ->execute([$jo_id,$me['id'],"JO Adjusted. New cost: ₱{$new_cost}. {$adj_note}",$station_id]); } catch(Exception $ae){}
            log_activity($pdo,$me['id'],'JO_ADJUSTED',"Job Order #{$jo_id} adjusted to ₱{$new_cost} by {$me['name']}.");
            $pdo->commit();
            $_SESSION['success'] = "Job Order #{$jo_id} adjusted successfully.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = 'Error adjusting JO: ' . $e->getMessage();
        }
        header('Location: transactions.php?' . http_build_query(array_filter(['start'=>$_POST['_start']??'','end'=>$_POST['_end']??'','status'=>$_POST['_status']??'','type'=>'jo'])));
        exit;
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
// Default: last 30 days so existing April data always shows on first load
$start    = $_GET['start']  ?? date('Y-m-d', strtotime('-30 days'));
$end      = $_GET['end']    ?? date('Y-m-d');
$customer = trim($_GET['customer'] ?? '');
$payment  = $_GET['payment'] ?? '';
$status_f = $_GET['status'] ?? '';
$type_f   = $_GET['type']   ?? '';   // '' = all, 'merchandise', 'jo'
$active_tab = $_GET['tab']  ?? 'merch';  // 'merch' | 'jo'

$do_export = (isset($_GET['export']) && in_array($_GET['export'], ['excel','pdf'])) ? $_GET['export'] : '';

// ── Config lookups ────────────────────────────────────────────────────────────
$transaction_type_names = [];
try {
    $rows = $pdo->query("SELECT type_key, type_name, badge_color FROM transaction_type_config WHERE active = 1 ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) $transaction_type_names[$r['type_key']] = ['name'=>$r['type_name'],'color'=>$r['badge_color']];
} catch(Exception $e) {
    $transaction_type_names = ['fuel'=>['name'=>'Fuel','color'=>'#dc3545'],'merchandise'=>['name'=>'Merchandise','color'=>'#007bff']];
}

// ── Config lookups — DB-driven with safe fallbacks ───────────────────────────
$payment_methods = [];
try {
    $payment_methods = $pdo->query("SELECT method_key, method_name FROM payment_method_config WHERE active = 1 ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}
if (empty($payment_methods)) {
    /* Fetch distinct payment methods actually used in this station's transactions */
    try {
        $pm_rows = $pdo->prepare("SELECT DISTINCT payment_method FROM merchandise_transactions WHERE station_id = ? AND payment_method IS NOT NULL AND payment_method <> '' ORDER BY payment_method");
        $pm_rows->execute([$station_id]);
        foreach ($pm_rows->fetchAll(PDO::FETCH_COLUMN) as $pm) {
            $payment_methods[] = ['method_key' => strtolower($pm), 'method_name' => $pm];
        }
    } catch (Exception $e) {}
}
if (empty($payment_methods)) {
    $payment_methods = [
        ['method_key'=>'cash',     'method_name'=>'Cash'],
        ['method_key'=>'card',     'method_name'=>'Card'],
        ['method_key'=>'credit',   'method_name'=>'Credit'],
        ['method_key'=>'e-wallet', 'method_name'=>'E-Wallet'],
    ];
}

// ── Status normaliser ─────────────────────────────────────────────────────────
function normalise_status(string $raw): string {
    $s = strtolower(trim($raw));
    if ($s === '' || in_array($s, ['pending','pending validation','pendingvalidation'])) return 'pending';
    if (in_array($s, ['verified','validated','approved','complete','completed']))        return 'verified';
    if (in_array($s, ['rejected','returned']))  return 'returned';
    if ($s === 'adjusted') return 'adjusted';
    return 'pending';
}

// ── Dynamic column detection for merchandise_transactions ────────────────────
$mt_available = [];
try {
    foreach ($pdo->query("SHOW COLUMNS FROM merchandise_transactions")->fetchAll(PDO::FETCH_ASSOC) as $c)
        $mt_available[strtolower($c['Field'])] = true;
} catch (Exception $e) {}
$mt_has = fn($c) => isset($mt_available[strtolower($c)]);

// ── Dynamic column detection for job_orders ───────────────────────────────────
$jo_available = [];
try {
    foreach ($pdo->query("SHOW COLUMNS FROM job_orders")->fetchAll(PDO::FETCH_ASSOC) as $c)
        $jo_available[strtolower($c['Field'])] = true;
} catch (Exception $e) {}
$jo_has = fn($c) => isset($jo_available[strtolower($c)]);

// ── Build merchandise SELECT with optional subtotal/vat columns ───────────────
$mt_subtotal_expr = $mt_has('subtotal_amount') ? 'mt.subtotal_amount' : 'mt.total_amount';
$mt_vat_expr      = $mt_has('vat_amount')      ? 'mt.vat_amount'      : '0';

// ── Build merchandise WHERE ───────────────────────────────────────────────────
$merch_date = "CASE WHEN mt.transaction_date > '2000-01-01' THEN DATE(mt.transaction_date) ELSE DATE(mt.created_at) END";

$mw = "WHERE mt.station_id = ? AND ($merch_date) BETWEEN ? AND ?";
$mp = [$station_id, $start, $end];

if ($customer !== '') { $mw .= " AND mt.customer_name LIKE ?"; $mp[] = '%'.$customer.'%'; }
if ($payment  !== '') { $mw .= " AND LOWER(mt.payment_method) = LOWER(?)"; $mp[] = $payment; }
if ($status_f === 'pending') {
    $mw .= " AND LOWER(TRIM(COALESCE(mt.validation_status,''))) IN ('pending','pending validation','pendingvalidation','')";
} elseif ($status_f === 'verified') {
    $mw .= " AND LOWER(TRIM(COALESCE(mt.validation_status,''))) IN ('verified','validated','approved','complete','completed')";
} elseif ($status_f === 'rejected') {
    $mw .= " AND LOWER(TRIM(COALESCE(mt.validation_status,''))) IN ('rejected','returned')";
}

// ── Merchandise query ─────────────────────────────────────────────────────────
// Use actual transaction_type column if it exists, otherwise default to 'merchandise'
$mt_txn_type_expr = $mt_has('transaction_type')
    ? "CASE WHEN mt.transaction_type IN ('job_order','combined') THEN 'job_order' ELSE 'merchandise' END"
    : "'merchandise'";
$mt_vehicle_expr  = $mt_has('job_order_vehicle_plate') ? "COALESCE(mt.job_order_vehicle_plate,'')" : "''";
$mt_mechanic_expr = $mt_has('job_order_mechanic_name') ? "COALESCE(mt.job_order_mechanic_name,'')" : "''";
$mt_jo_service_expr = $mt_has('job_order_service') ? "COALESCE(mt.job_order_service,'')" : "''";

$sql = "
    SELECT
        mt.id AS row_id,
        mt.transaction_id AS txn_ref,
        COALESCE(NULLIF(TRIM(mt.customer_name),''),'Walk-in') AS customer,
        COALESCE(NULLIF(mt.payment_method,''),'Cash') AS payment_method,
        CASE WHEN mt.transaction_date > '2000-01-01' THEN mt.transaction_date ELSE mt.created_at END AS created_at,
        COALESCE(NULLIF(TRIM(mt.validation_status),''),'Pending') AS status,
        COALESCE(u.name,'Unknown') AS staff_name,
        mt.staff_id,
        COALESCE(
            NULLIF((SELECT GROUP_CONCAT(i.product_name ORDER BY i.id SEPARATOR ', ')
                    FROM merchandise_transaction_items i WHERE i.transaction_id = mt.id),''),
            NULLIF($mt_jo_service_expr,''),
            NULLIF(mt.item_sku,''),
            'No items'
        ) AS product_name,
        mt.total_amount AS total,
        COALESCE($mt_subtotal_expr, mt.total_amount) AS subtotal,
        COALESCE($mt_vat_expr, 0) AS vat_amount,
        COALESCE(
            (SELECT SUM(i2.quantity) FROM merchandise_transaction_items i2 WHERE i2.transaction_id = mt.id),
            mt.quantity, 0
        ) AS quantity,
        COALESCE(
            (SELECT i3.unit_price FROM merchandise_transaction_items i3 WHERE i3.transaction_id = mt.id ORDER BY i3.id LIMIT 1),
            mt.unit_price, 0
        ) AS unit_price,
        $mt_vehicle_expr AS vehicle,
        $mt_mechanic_expr AS mechanic,
        $mt_txn_type_expr AS txn_type,
        '' AS jo_status,
        '' AS payment_status
    FROM merchandise_transactions mt
    LEFT JOIN users u ON mt.staff_id = u.id
    $mw
    GROUP BY mt.id
    ORDER BY created_at DESC
";
$params = $mp;

$transactions = [];
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('transactions.php query error: ' . $e->getMessage());
    $_SESSION['error'] = 'Query error: ' . $e->getMessage();
}

// ── Job Orders query (unified flow) ──────────────────────────────────────────
$jo_date = "CASE WHEN jo.created_at > '2000-01-01' THEN DATE(jo.created_at) ELSE CURDATE() END";
$jow = "WHERE jo.station_id = ? AND ($jo_date) BETWEEN ? AND ?
        AND (jo.validation_status IN ('Pending Validation','Pending','') OR jo.validation_status IS NULL
             OR jo.validation_status IN ('Approved','Rejected','Adjusted'))";
$jop = [$station_id, $start, $end];

if ($customer !== '') { $jow .= " AND (jo.customer_name LIKE ? OR jo.vehicle_plate LIKE ?)"; $jop[] = '%'.$customer.'%'; $jop[] = '%'.$customer.'%'; }
if ($status_f === 'pending')  { $jow .= " AND (LOWER(TRIM(COALESCE(jo.validation_status,''))) IN ('pending validation','pending','') OR jo.validation_status IS NULL)"; }
elseif ($status_f === 'verified') { $jow .= " AND LOWER(TRIM(COALESCE(jo.validation_status,''))) IN ('approved','verified')"; }
elseif ($status_f === 'rejected') { $jow .= " AND LOWER(TRIM(COALESCE(jo.validation_status,''))) IN ('rejected','cancelled')"; }

$jo_sql = "
    SELECT
        jo.id AS row_id,
        CONCAT('JO-', jo.id) AS txn_ref,
        COALESCE(NULLIF(TRIM(jo.customer_name),''),'Walk-in') AS customer,
        COALESCE(jo.payment_method,'N/A') AS payment_method,
        jo.created_at,
        COALESCE(NULLIF(TRIM(jo.validation_status),''),'Pending Validation') AS status,
        COALESCE(u.name,'Unknown') AS staff_name,
        COALESCE(jo.user_id, 0) AS staff_id,
        COALESCE(NULLIF(TRIM(jo.service_type),''),'Job Order') AS product_name,
        COALESCE(jo.vehicle_plate,'') AS vehicle,
        COALESCE(jo.mechanic_name,'') AS mechanic,
        COALESCE(NULLIF(jo.total_cost, 0), jo.estimated_cost, 0) AS total,
        COALESCE(NULLIF(jo.total_cost, 0), jo.estimated_cost, 0) AS subtotal,
        0 AS vat_amount,
        0 AS quantity,
        0 AS unit_price,
        'job_order' AS txn_type,
        jo.status AS jo_status,
        COALESCE(NULLIF(TRIM(jo.payment_status),''), 'Unpaid') AS payment_status
    FROM job_orders jo
    LEFT JOIN users u ON u.id = COALESCE(jo.created_by, jo.user_id)
    $jow
    ORDER BY jo.created_at DESC
";

$job_orders = [];
try {
    $jstmt = $pdo->prepare($jo_sql);
    $jstmt->execute($jop);
    $job_orders = $jstmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // job_orders table may not have all columns — silently skip
    error_log('transactions.php JO query: ' . $e->getMessage());
}

// ── Merge based on type filter ────────────────────────────────────────────────
if ($type_f === 'merchandise') {
    $all_transactions = $transactions;
} elseif ($type_f === 'jo') {
    $all_transactions = $job_orders;
} else {
    // Merge and sort by created_at desc
    $all_transactions = array_merge($transactions, $job_orders);
    usort($all_transactions, fn($a,$b) => strtotime($b['created_at']) - strtotime($a['created_at']));
}

// ── Summary counts ────────────────────────────────────────────────────────────
$totalCount = count($all_transactions);
$pendingCount = $verifiedCount = $rejectedCount = 0;
$grandTotal = 0.0;
foreach ($all_transactions as $t) {
    $ns = normalise_status($t['status'] ?? '');
    if ($ns === 'pending')  $pendingCount++;
    if ($ns === 'verified') $verifiedCount++;
    if ($ns === 'rejected') $rejectedCount++;
    $grandTotal += (float)($t['total'] ?? 0);
}

// ── Job Order Tracker data (Tab 2) ────────────────────────────────────────────
$jo_status_filter = trim($_GET['jo_status'] ?? '');
$jo_search_filter = trim($_GET['jo_search'] ?? '');

$jo_stats = ['total'=>0,'pending'=>0,'approved'=>0,'in_progress'=>0,'completed'=>0,'rejected'=>0];
try {
    $r = $pdo->prepare("SELECT COUNT(*) AS total,
        SUM(CASE WHEN status='Pending Validation' OR validation_status='Pending Validation' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status IN ('Approved','Validated') THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN status='In Progress' THEN 1 ELSE 0 END) AS in_progress,
        SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN status IN ('Rejected','Cancelled') THEN 1 ELSE 0 END) AS rejected
        FROM job_orders WHERE station_id=?");
    $r->execute([$station_id]);
    $jo_stats = $r->fetch(PDO::FETCH_ASSOC) ?: $jo_stats;
} catch (Exception $e) {}

$jo_where = ["j.station_id=?"]; $jo_params = [$station_id];
if ($jo_status_filter !== '') {
    $jo_where[] = "(j.status=? OR j.validation_status=?)";
    $jo_params[] = $jo_status_filter; $jo_params[] = $jo_status_filter;
}
if ($jo_search_filter !== '') {
    $jo_where[] = "(COALESCE(c.name,j.customer_name,'') LIKE ? OR j.service_type LIKE ? OR u.name LIKE ? OR j.vehicle_plate LIKE ?)";
    $s = '%'.$jo_search_filter.'%';
    $jo_params[] = $s; $jo_params[] = $s; $jo_params[] = $s; $jo_params[] = $s;
}

$jo_tracker_rows = [];
try {
    $pay_status_col = $jo_has('payment_status')
        ? "COALESCE(NULLIF(TRIM(j.payment_status),''),'Unpaid')"
        : "'Unpaid'";
    $mechanic_col = $jo_has('assigned_mechanic_id')
        ? 'COALESCE(m.name,\'\')'
        : ($jo_has('mechanic_name') ? 'COALESCE(j.mechanic_name,\'\')' : "''");
    $staff_col = $jo_has('created_by')
        ? 'COALESCE(j.created_by, j.user_id)'
        : 'j.user_id';

    // ── Part 1: native job_orders rows ───────────────────────────────────────
    $jo_where_sql = implode(' AND ', $jo_where);
    $part1_sql = "
        SELECT
            j.id,
            j.customer_name,
            j.service_type,
            j.service_description,
            j.status,
            j.validation_status,
            j.estimated_cost,
            j.total_cost,
            j.notes,
            j.vehicle_plate,
            j.created_at,
            COALESCE(c.name, j.customer_name, 'Walk-in') AS cust,
            u.name AS staff_name,
            {$pay_status_col} AS payment_status,
            {$mechanic_col} AS mechanic_name,
            'job_orders' AS _source
        FROM job_orders j
        LEFT JOIN customers c ON c.id = j.customer_id
        LEFT JOIN users u ON u.id = {$staff_col}
        " . ($jo_has('assigned_mechanic_id') ? "LEFT JOIN users m ON m.id = j.assigned_mechanic_id" : "") . "
        WHERE {$jo_where_sql}
    ";

    // ── Part 2: merchandise_transactions with transaction_type job_order/combined ─
    // Only include if the transaction_type column exists
    $part2_sql = '';
    $mt_params2 = [];
    if ($mt_has('transaction_type') && $mt_has('job_order_service')) {
        $mt_mech2 = $mt_has('job_order_mechanic_name') ? "COALESCE(mt2.job_order_mechanic_name,'')" : "''";
        $mt_plate2 = $mt_has('job_order_vehicle_plate') ? "COALESCE(mt2.job_order_vehicle_plate,'')" : "''";
        $mt_vtype2 = $mt_has('job_order_vehicle_type') ? "COALESCE(mt2.job_order_vehicle_type,'')" : "''";

        // Build search/status conditions for merchandise_transactions
        $mt2_where = ["mt2.station_id = ?",
                      "mt2.transaction_type IN ('job_order','combined')"];
        $mt_params2 = [$station_id];

        if ($jo_status_filter !== '') {
            $mt2_where[] = "(mt2.validation_status = ? OR mt2.validation_status = ?)";
            $mt_params2[] = $jo_status_filter; $mt_params2[] = $jo_status_filter;
        }
        if ($jo_search_filter !== '') {
            $mt2_where[] = "(mt2.customer_name LIKE ? OR mt2.job_order_service LIKE ? OR {$mt_plate2} LIKE ?)";
            $s2 = '%'.$jo_search_filter.'%';
            $mt_params2[] = $s2; $mt_params2[] = $s2; $mt_params2[] = $s2;
        }

        $mt2_date_col = $mt_has('transaction_date')
            ? "CASE WHEN mt2.transaction_date > '2000-01-01' THEN mt2.transaction_date ELSE mt2.created_at END"
            : "mt2.created_at";

        $part2_sql = "
        UNION ALL
        SELECT
            mt2.id                                                          AS id,
            COALESCE(NULLIF(TRIM(mt2.customer_name),''),'Walk-in')         AS customer_name,
            COALESCE(mt2.job_order_service,'Service')                       AS service_type,
            ''                                                              AS service_description,
            COALESCE(mt2.validation_status,'Pending Validation')            AS status,
            COALESCE(mt2.validation_status,'Pending Validation')            AS validation_status,
            mt2.total_amount                                                AS estimated_cost,
            mt2.total_amount                                                AS total_cost,
            ''                                                              AS notes,
            {$mt_plate2}                                                    AS vehicle_plate,
            {$mt2_date_col}                                                 AS created_at,
            COALESCE(NULLIF(TRIM(mt2.customer_name),''),'Walk-in')         AS cust,
            u2.name                                                         AS staff_name,
            'Unpaid'                                                        AS payment_status,
            {$mt_mech2}                                                     AS mechanic_name,
            'merchandise_transactions'                                      AS _source
        FROM merchandise_transactions mt2
        LEFT JOIN users u2 ON u2.id = mt2.staff_id
        WHERE " . implode(' AND ', $mt2_where) . "
        ";
    }

    $full_sql = "
        SELECT * FROM (
            {$part1_sql}
            {$part2_sql}
        ) combined_jo
        ORDER BY
            CASE WHEN status = 'Pending Validation'
                   OR validation_status = 'Pending Validation' THEN 0 ELSE 1 END,
            created_at DESC
        LIMIT 200
    ";

    $all_jo_params = array_merge($jo_params, $mt_params2);
    $r = $pdo->prepare($full_sql);
    $r->execute($all_jo_params);
    $jo_tracker_rows = $r->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Surface the error so it's visible during debugging
    $_SESSION['jo_query_error'] = $e->getMessage();
}

// ── Excel Export ──────────────────────────────────────────────────────────────
if ($do_export === 'excel') {
    $filename = 'transactions_' . $start . '_to_' . $end . '.xls';
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8">
    <style>
        body{font-family:Arial,sans-serif;}
        table{border-collapse:collapse;width:100%;}
        th{background:#002F6C;color:#fff;padding:8px;border:1px solid #ccc;font-size:12px;}
        td{padding:7px 8px;border:1px solid #ddd;font-size:11px;}
        .hdr{background:#002F6C;color:#fff;font-size:14px;font-weight:bold;padding:10px;text-align:center;}
        .sub{background:#f0f4ff;font-size:11px;padding:5px 10px;color:#555;}
        .fuel-row td{background:#fff8f0;}
        .merch-row td{background:#f0f8ff;}
        .status-v{color:#155724;font-weight:bold;}
        .status-p{color:#856404;font-weight:bold;}
        .status-r{color:#721c24;font-weight:bold;}
    </style></head><body>';
    echo '<div class="hdr">PETRON STATION MANAGEMENT SYSTEM</div>';
    echo '<div class="sub">Fuel &amp; Merchandise Transactions Report &nbsp;|&nbsp; Period: ' . htmlspecialchars($start) . ' to ' . htmlspecialchars($end) . ' &nbsp;|&nbsp; Generated: ' . date('M j, Y H:i A') . '</div>';
    echo '<br>';
    echo '<table><thead><tr>
        <th>#</th><th>Transaction ID</th><th>Product</th>
        <th>Qty</th><th>Unit Price</th><th>Total</th>
        <th>Payment</th><th>Customer</th><th>Staff</th><th>Date/Time</th><th>Status</th>
    </tr></thead><tbody>';
    $n = 1;
    foreach ($transactions as $t) {
        $ns = normalise_status($t['status'] ?? '');
        if ($ns === 'verified') {
            $statusLabel = 'Verified'; $statusClass = 'status-v';
        } elseif ($ns === 'pending') {
            $statusLabel = 'Pending Validation'; $statusClass = 'status-p';
        } elseif ($ns === 'returned') {
            $statusLabel = 'Returned'; $statusClass = 'status-r';
        } else {
            $statusLabel = ucfirst($t['status']); $statusClass = '';
        }
        echo '<tr>';
        echo '<td>' . $n++ . '</td>';
        echo '<td>' . htmlspecialchars($t['txn_ref'] ?? $t['row_id']) . '</td>';
        echo '<td>' . htmlspecialchars($t['product_name']) . '</td>';
        echo '<td style="text-align:right">' . number_format($t['quantity'], 2) . '</td>';
        echo '<td style="text-align:right">&#8369;' . number_format($t['unit_price'], 2) . '</td>';
        echo '<td style="text-align:right;font-weight:bold">&#8369;' . number_format($t['total'], 2) . '</td>';
        echo '<td>' . htmlspecialchars($t['payment_method'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($t['customer']) . '</td>';
        echo '<td>' . htmlspecialchars($t['staff_name']) . '</td>';
        echo '<td>' . date('M d, Y H:i', strtotime($t['created_at'])) . '</td>';
        echo '<td class="' . $statusClass . '">' . $statusLabel . '</td>';
        echo '</tr>';
    }
    echo '<tr style="background:#e8f0fe;font-weight:bold;">
        <td colspan="5" style="text-align:right">GRAND TOTAL</td>
        <td style="text-align:right">&#8369;' . number_format($grandTotal, 2) . '</td>
        <td colspan="5">' . count($transactions) . ' transaction(s)</td>
    </tr>';
    echo '</tbody></table></body></html>';
    exit;
}

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1">Pending Merchandise/Service Transactions</h1>
        <div class="sub">Validation queue — Approve, Reject, or Adjust all Merchandise &amp; Job Order entries</div>
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

<!-- ══ TAB BAR ══════════════════════════════════════════════════════════════ -->
<div class="txn-tab-bar">
    <a href="?tab=merch&start=<?php echo urlencode($start); ?>&end=<?php echo urlencode($end); ?>"
       class="txn-tab <?php echo $active_tab !== 'jo' ? 'txn-tab-active' : ''; ?>">
        <i class="fas fa-shopping-cart"></i> Pending Merchandise/Service Transactions
        <?php if ($pendingCount > 0): ?>
        <span class="txn-tab-badge"><?php echo $pendingCount; ?></span>
        <?php endif; ?>
    </a>
    <a href="?tab=jo&start=<?php echo urlencode($start); ?>&end=<?php echo urlencode($end); ?>"
       class="txn-tab <?php echo $active_tab === 'jo' ? 'txn-tab-active' : ''; ?>">
        <i class="fas fa-wrench"></i> Job Order Tracker
        <?php if (($jo_stats['pending'] ?? 0) > 0): ?>
        <span class="txn-tab-badge"><?php echo (int)$jo_stats['pending']; ?></span>
        <?php endif; ?>
    </a>
</div>

<?php if ($active_tab !== 'jo'): ?>
<?php
// ── Customer list for auto-suggest ───────────────────────────────────────────
$customer_list = [];
try {
    $cs = $pdo->prepare("
        SELECT DISTINCT TRIM(customer_name) AS cname
        FROM merchandise_transactions
        WHERE station_id = ?
          AND customer_name IS NOT NULL
          AND TRIM(customer_name) != ''
        ORDER BY cname ASC
        LIMIT 200
    ");
    $cs->execute([$station_id]);
    $customer_list = $cs->fetchAll(PDO::FETCH_COLUMN);
} catch(Exception $e) { $customer_list = []; }
?>

<!-- ══ FILTER CARD ══════════════════════════════════════════════════════════ -->
<div class="flt-card">

    <div class="flt-header">
        <span class="flt-title"><i class="fas fa-filter"></i> Filter Transactions</span>
        <div class="flt-export-btns">
            <a href="?<?php echo http_build_query(array_merge($_GET, ['export'=>'excel'])); ?>"
               class="flt-btn flt-btn-excel">
                <i class="fas fa-file-excel"></i> Export Excel
            </a>
        </div>
    </div>

    <form method="get" id="filterForm">
        <!-- Row 1: Date Range + Customer + Payment + Type + Status -->
        <div class="flt-row">

            <!-- Date Range -->
            <div class="flt-group flt-group-date">
                <label class="flt-lbl"><i class="fas fa-calendar-alt"></i> Date Range</label>
                <div class="flt-date-wrap">
                    <input type="date" name="start" id="flt_start"
                           value="<?php echo htmlspecialchars($start); ?>"
                           class="flt-inp" max="<?php echo date('Y-m-d'); ?>">
                    <span class="flt-date-sep">to</span>
                    <input type="date" name="end" id="flt_end"
                           value="<?php echo htmlspecialchars($end); ?>"
                           class="flt-inp" max="<?php echo date('Y-m-d'); ?>">
                </div>
            </div>

            <!-- Customer Name (auto-suggest) -->
            <div class="flt-group">
                <label class="flt-lbl"><i class="fas fa-user"></i> Customer Name</label>
                <div class="flt-autocomplete-wrap">
                    <input type="text" name="customer" id="flt_customer"
                           value="<?php echo htmlspecialchars($customer); ?>"
                           class="flt-inp" placeholder="Search customer…"
                           autocomplete="off"
                           list="customer_datalist">
                    <datalist id="customer_datalist">
                        <?php foreach($customer_list as $cn): ?>
                        <option value="<?php echo htmlspecialchars($cn); ?>">
                        <?php endforeach; ?>
                    </datalist>
                    <?php if($customer !== ''): ?>
                    <button type="button" class="flt-clear-input" onclick="document.getElementById('flt_customer').value='';this.style.display='none';" title="Clear">×</button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Payment Method -->
            <div class="flt-group">
                <label class="flt-lbl"><i class="fas fa-credit-card"></i> Payment Method</label>
                <select name="payment" class="flt-inp flt-select">
                    <option value="">All Methods</option>
                    <?php foreach($payment_methods as $pm): ?>
                    <option value="<?php echo htmlspecialchars($pm['method_key']); ?>"
                        <?php echo ($payment === $pm['method_key']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($pm['method_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Status -->
            <div class="flt-group">
                <label class="flt-lbl"><i class="fas fa-circle-dot"></i> Status</label>
                <select name="status" class="flt-inp flt-select">
                    <option value="">All Statuses</option>
                    <option value="pending"  <?php echo ($status_f==='pending')  ? 'selected':''; ?>>🕐 Pending</option>
                    <option value="verified" <?php echo ($status_f==='verified') ? 'selected':''; ?>>✅ Verified</option>
                    <option value="rejected" <?php echo ($status_f==='rejected') ? 'selected':''; ?>>↩ Returned</option>
                </select>
            </div>

            <!-- Type -->
            <div class="flt-group">
                <label class="flt-lbl"><i class="fas fa-layer-group"></i> Type</label>
                <select name="type" class="flt-inp flt-select">
                    <option value="">All Types</option>
                    <option value="merchandise" <?php echo ($type_f==='merchandise') ? 'selected':''; ?>>🛒 Merchandise</option>
                    <option value="jo"          <?php echo ($type_f==='jo')          ? 'selected':''; ?>>🔧 Job Order</option>
                </select>
            </div>

            <!-- Buttons -->
            <div class="flt-group flt-group-btns">
                <label class="flt-lbl">&nbsp;</label>
                <div class="flt-action-row">
                    <button type="submit" class="flt-btn flt-btn-search">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <a href="transactions.php" class="flt-btn flt-btn-reset">
                        <i class="fas fa-rotate-left"></i> Reset
                    </a>
                </div>
            </div>

        </div><!-- /.flt-row -->
    </form>



</div><!-- /.flt-card -->

<!-- Transactions Table -->
<div class="card" style="padding:0;" id="printableTable">
    <!-- Summary bar -->
    <div style="display:flex;gap:12px;flex-wrap:wrap;padding:14px 18px;border-bottom:1px solid #f0f0f0;background:#fafbfc;">
        <span style="font-size:12px;color:#555;">Total: <strong><?php echo $totalCount; ?></strong></span>
        <span style="font-size:12px;color:#856404;">🕐 Pending: <strong><?php echo $pendingCount; ?></strong></span>
        <span style="font-size:12px;color:#155724;">✅ Approved: <strong><?php echo $verifiedCount; ?></strong></span>
        <span style="font-size:12px;color:#721c24;">↩ Rejected: <strong><?php echo $rejectedCount; ?></strong></span>
        <span style="margin-left:auto;font-size:13px;font-weight:700;color:#002F6C;">Grand Total: &#8369;<?php echo number_format($grandTotal,2); ?></span>
    </div>
    <div class="txn-table-wrap">
        <table class="table txn-table" id="txnTable">
            <thead>
                <tr>
                    <th class="col-txnid">Transaction / JO ID</th>
                    <th class="col-type">Type</th>
                    <th class="col-product">Service / Merchandise</th>
                    <th class="col-vehicle">Vehicle</th>
                    <th class="col-staff">Mechanic / Staff</th>
                    <th class="col-subtotal">Subtotal</th>
                    <th class="col-vat">VAT</th>
                    <th class="col-total">Total</th>
                    <th class="col-pay">Payment</th>
                    <th class="col-date">Date/Time</th>
                    <th class="col-status">Status</th>
                    <th class="col-actions sticky-col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($all_transactions as $t): ?>
                <?php
                    $isJO      = ($t['txn_type'] ?? '') === 'job_order';
                    $status    = $t['status'] ?? 'Pending Validation';
                    $ns        = normalise_status($status);
                    if ($ns === 'verified') {
                        $statusColor = '#28a745'; $statusLabel = 'Approved';
                    } elseif ($ns === 'pending') {
                        $statusColor = '#e6a817'; $statusLabel = 'Pending';
                    } elseif ($ns === 'returned') {
                        $statusColor = '#dc3545'; $statusLabel = 'Rejected';
                    } elseif ($ns === 'adjusted') {
                        $statusColor = '#6f42c1'; $statusLabel = 'Adjusted';
                    } else {
                        $statusColor = '#6c757d'; $statusLabel = htmlspecialchars($status);
                    }

                    $rowId      = (int)$t['row_id'];
                    $txnRef     = $t['txn_ref'] ?? $t['row_id'];
                    $txnDisplay = $txnRef;
                    $txnShort   = strlen((string)$txnDisplay) > 14 ? '…' . substr((string)$txnDisplay, -12) : $txnDisplay;
                    $vehicle    = $t['vehicle'] ?? '';
                    $mechanic   = $t['mechanic'] ?? '';

                    $js = [
                        'rowId'         => $rowId,
                        'txnRef'        => (string)$txnRef,
                        'isJO'          => $isJO,
                        'product'       => $t['product_name'],
                        'vehicle'       => $vehicle,
                        'mechanic'      => $mechanic,
                        'qty'           => number_format((float)$t['quantity'], 2),
                        'unit'          => number_format((float)$t['unit_price'], 2),
                        'subtotal'      => number_format((float)($t['subtotal'] ?? $t['total']), 2),
                        'vat'           => number_format((float)($t['vat_amount'] ?? 0), 2),
                        'total'         => number_format((float)$t['total'], 2),
                        'payment'       => $t['payment_method'] ?? 'N/A',
                        'paymentStatus' => $t['payment_status'] ?? '',
                        'staff'         => $t['staff_name'],
                        'staffId'       => $t['staff_id'] ?? '',
                        'customer'      => $t['customer'],
                        'date'          => date('M d, Y H:i', strtotime($t['created_at'])),
                        'status'        => $ns === 'pending' ? 'Pending Validation' : $statusLabel,
                        'joStatus'      => $t['jo_status'] ?? '',
                    ];
                    $receiptId = urlencode((string)$txnRef);
                ?>
                <tr class="<?php echo $isJO ? 'row-jo' : 'row-merch'; ?>">
                    <td class="col-txnid" title="<?php echo htmlspecialchars($txnDisplay); ?>">
                        <span style="font-weight:600;font-size:11px;">#<?php echo htmlspecialchars($txnShort); ?></span>
                    </td>
                    <td class="col-type" style="text-align:center;">
                        <?php if ($isJO): ?>
                        <span style="background:#fd7e14;color:#fff;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:700;">JO</span>
                        <?php else: ?>
                        <span style="background:#0d6efd;color:#fff;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:700;">Merch</span>
                        <?php endif; ?>
                    </td>
                    <td class="col-product" title="<?php echo htmlspecialchars($t['product_name']); ?>">
                        <?php echo htmlspecialchars($t['product_name']); ?>
                        <?php if ($t['customer'] !== 'Walk-in'): ?>
                        <div style="font-size:10px;color:#888;"><?php echo htmlspecialchars($t['customer']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="col-vehicle" style="font-size:11px;color:#555;">
                        <?php echo $vehicle ? htmlspecialchars($vehicle) : '<span style="color:#ccc;">—</span>'; ?>
                    </td>
                    <td class="col-staff">
                        <?php if ($mechanic): ?>
                        <div style="font-size:11px;font-weight:600;"><?php echo htmlspecialchars($mechanic); ?></div>
                        <div style="font-size:10px;color:#888;"><?php echo htmlspecialchars($t['staff_name']); ?></div>
                        <?php else: ?>
                        <?php echo htmlspecialchars($t['staff_name']); ?>
                        <?php endif; ?>
                    </td>
                    <td class="col-subtotal" style="text-align:right;font-size:12px;">
                        &#8369;<?php echo number_format((float)($t['subtotal'] ?? $t['total']), 2); ?>
                    </td>
                    <td class="col-vat" style="text-align:right;font-size:12px;color:#888;">
                        <?php $vat = (float)($t['vat_amount'] ?? 0); ?>
                        <?php echo $vat > 0 ? '&#8369;' . number_format($vat, 2) : '<span style="color:#ccc;">—</span>'; ?>
                    </td>
                    <td class="col-total"><strong>&#8369;<?php echo number_format($t['total'], 2); ?></strong></td>
                    <td class="col-pay">
                        <?php echo htmlspecialchars($t['payment_method'] ?? ''); ?>
                        <?php if ($isJO && !empty($t['payment_status'])): ?>
                        <?php
                            $ps = strtolower($t['payment_status'] ?? 'unpaid');
                            $psc = $ps === 'paid' ? '#28a745' : ($ps === 'partial' ? '#e6a817' : '#dc3545');
                            $pst = $ps === 'paid' ? '#fff' : ($ps === 'partial' ? '#212529' : '#fff');
                        ?>
                        <div><span style="background:<?php echo $psc; ?>;color:<?php echo $pst; ?>;padding:1px 6px;border-radius:6px;font-size:9px;font-weight:700;"><?php echo ucfirst($t['payment_status']); ?></span></div>
                        <?php endif; ?>
                    </td>
                    <td class="col-date"><?php echo date('M d, H:i', strtotime($t['created_at'])); ?></td>
                    <td class="col-status">
                        <span style="background:<?php echo $statusColor; ?>;color:<?php echo $statusColor==='#e6a817'?'#212529':'#fff'; ?>;font-weight:700;padding:2px 8px;border-radius:10px;font-size:10px;white-space:nowrap;">
                            <?php echo $statusLabel; ?>
                        </span>
                    </td>
                    <td class="col-actions sticky-col">
                        <div class="action-btns">
                            <!-- View Details -->
                            <button class="ab ab-view" title="View Details"
                                onclick="viewDetails(<?php echo htmlspecialchars(json_encode($js), ENT_QUOTES, 'UTF-8'); ?>)">
                                <i class="fas fa-eye"></i><span class="ab-lbl"> View</span>
                            </button>

                            <?php if ($ns === 'pending'): ?>
                            <?php if ($isJO): ?>
                            <!-- JO: Approve -->
                            <form method="POST" style="display:contents;" onsubmit="return confirm('Approve this Job Order?');">
                                <input type="hidden" name="action" value="approve_job_order">
                                <input type="hidden" name="jo_id" value="<?php echo $rowId; ?>">
                                <input type="hidden" name="remarks" value="Approved via Pending Transactions">
                                <input type="hidden" name="_start" value="<?php echo htmlspecialchars($start); ?>">
                                <input type="hidden" name="_end" value="<?php echo htmlspecialchars($end); ?>">
                                <input type="hidden" name="_status" value="<?php echo htmlspecialchars($status_f); ?>">
                                <input type="hidden" name="_type" value="<?php echo htmlspecialchars($type_f); ?>">
                                <button type="submit" class="ab ab-approve"><i class="fas fa-check-circle"></i><span class="ab-lbl"> Approve</span></button>
                            </form>
                            <!-- JO: Reject -->
                            <button class="ab ab-reject" onclick="openJORejectModal(<?php echo $rowId; ?>)">
                                <i class="fas fa-times-circle"></i><span class="ab-lbl"> Reject</span>
                            </button>
                            <!-- JO: Adjust -->
                            <button class="ab ab-adjust" onclick="openJOAdjustModal(<?php echo $rowId; ?>, '<?php echo number_format($t['total'],2); ?>')">
                                <i class="fas fa-sliders"></i><span class="ab-lbl"> Adjust</span>
                            </button>
                            <?php else: ?>
                            <!-- Merch: Approve -->
                            <form method="POST" style="display:contents;" onsubmit="return confirm('Approve and validate this transaction?');">
                                <input type="hidden" name="action" value="approve_transaction">
                                <input type="hidden" name="transaction_id" value="<?php echo $rowId; ?>">
                                <input type="hidden" name="transaction_type" value="merchandise">
                                <input type="hidden" name="_start" value="<?php echo htmlspecialchars($start); ?>">
                                <input type="hidden" name="_end" value="<?php echo htmlspecialchars($end); ?>">
                                <input type="hidden" name="_status" value="<?php echo htmlspecialchars($status_f); ?>">
                                <input type="hidden" name="_type" value="<?php echo htmlspecialchars($type_f); ?>">
                                <button type="submit" class="ab ab-approve"><i class="fas fa-check-circle"></i><span class="ab-lbl"> Approve</span></button>
                            </form>
                            <!-- Merch: Reject -->
                            <button class="ab ab-reject" onclick="openRejectModal('<?php echo $rowId; ?>','merchandise')">
                                <i class="fas fa-undo-alt"></i><span class="ab-lbl"> Reject</span>
                            </button>
                            <!-- Merch: Adjust -->
                            <button class="ab ab-adjust" onclick="openAdjustModal(<?php echo $rowId; ?>, '<?php echo number_format($t['total'],2); ?>')">
                                <i class="fas fa-sliders"></i><span class="ab-lbl"> Adjust</span>
                            </button>
                            <?php endif; ?>
                            <?php endif; ?>

                            <?php if (!$isJO): ?>
                            <!-- Receipt -->
                            <button class="ab ab-receipt" title="Print Receipt"
                                onclick="window.open('receipt.php?id=<?php echo $receiptId; ?>&type=merchandise','_blank','width=520,height=800,scrollbars=yes')">
                                <i class="fas fa-receipt"></i><span class="ab-lbl"> Receipt</span>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($all_transactions)): ?>
                <tr>
                    <td colspan="10" style="text-align:center;padding:48px;color:#888;">
                        <i class="fas fa-inbox" style="font-size:36px;display:block;margin-bottom:12px;opacity:0.3;"></i>
                        No transactions found for the selected filters.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; /* end Tab 1: Pending Merchandise/Service */ ?>

<?php if ($active_tab === 'jo'): ?>
<!-- ══ TAB 2: JOB ORDER TRACKER ═════════════════════════════════════════════ -->

<!-- JO Stats Cards -->
<div class="jo-stat-grid">
    <?php
    $jo_stat_items = [
        ['label'=>'Total',       'val'=>$jo_stats['total'],       'color'=>'#002F6C', 'icon'=>'fa-list'],
        ['label'=>'Pending',     'val'=>$jo_stats['pending'],     'color'=>'#92400e', 'icon'=>'fa-clock', 'filter'=>'Pending Validation'],
        ['label'=>'Approved',    'val'=>$jo_stats['approved'],    'color'=>'#065f46', 'icon'=>'fa-check-circle', 'filter'=>'Approved'],
        ['label'=>'In Progress', 'val'=>$jo_stats['in_progress'], 'color'=>'#1e40af', 'icon'=>'fa-spinner', 'filter'=>'In Progress'],
        ['label'=>'Completed',   'val'=>$jo_stats['completed'],   'color'=>'#14532d', 'icon'=>'fa-flag-checkered', 'filter'=>'Completed'],
        ['label'=>'Rejected',    'val'=>$jo_stats['rejected'],    'color'=>'#991b1b', 'icon'=>'fa-times-circle', 'filter'=>'Rejected'],
    ];
    foreach ($jo_stat_items as $si):
        $href = '?tab=jo&jo_status=' . urlencode($si['filter'] ?? '') . '&start=' . urlencode($start) . '&end=' . urlencode($end);
        $isActive = isset($si['filter']) && $jo_status_filter === $si['filter'];
    ?>
    <a href="<?php echo $href; ?>" class="jo-stat-card <?php echo $isActive ? 'active-filter' : ''; ?>">
        <div class="si" style="color:<?php echo $si['color']; ?>;"><i class="fas <?php echo $si['icon']; ?>"></i></div>
        <div class="sv" style="color:<?php echo $si['color']; ?>;"><?php echo (int)$si['val']; ?></div>
        <div class="sl"><?php echo $si['label']; ?></div>
    </a>
    <?php endforeach; ?>
</div>

<!-- JO Filter + Table -->
<div class="jo-card">
    <form method="GET" action="transactions.php" class="filter-bar" style="margin-bottom:16px;">
        <input type="hidden" name="tab" value="jo">
        <input type="hidden" name="start" value="<?php echo htmlspecialchars($start); ?>">
        <input type="hidden" name="end" value="<?php echo htmlspecialchars($end); ?>">
        <select name="jo_status" style="padding:7px 12px;border:1px solid #ddd;border-radius:8px;font-size:13px;min-width:180px;">
            <option value="">All Statuses</option>
            <?php foreach (['Pending Validation','Approved','Validated','In Progress','Completed','Rejected','Cancelled','Adjusted'] as $opt): ?>
            <option value="<?php echo $opt; ?>" <?php echo $jo_status_filter === $opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="jo_search" value="<?php echo htmlspecialchars($jo_search_filter); ?>"
               placeholder="Search customer, service, vehicle, staff…"
               style="padding:7px 12px;border:1px solid #ddd;border-radius:8px;font-size:13px;width:260px;">
        <button type="submit" style="padding:7px 14px;background:#002F6C;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
            <i class="fas fa-search"></i> Search
        </button>
        <?php if ($jo_status_filter !== '' || $jo_search_filter !== ''): ?>
        <a href="?tab=jo&start=<?php echo urlencode($start); ?>&end=<?php echo urlencode($end); ?>"
           style="padding:7px 14px;background:#f8f9fa;color:#495057;border:1px solid #ddd;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px;">
            <i class="fas fa-times"></i> Clear
        </a>
        <?php endif; ?>
        <span style="font-size:12px;color:#6c757d;margin-left:auto;"><?php echo count($jo_tracker_rows); ?> record(s)</span>
    </form>

    <div style="overflow-x:auto;">
        <table class="jo-table">
            <thead>
                <tr>
                    <th>JO ID</th>
                    <th>Customer</th>
                    <th>Service</th>
                    <th>Vehicle</th>
                    <th>Mechanic</th>
                    <th>Staff</th>
                    <th>Status</th>
                    <th>Payment</th>
                    <th>Cost</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $jo_stMap = [
                'Pending Validation'=>['#FFF3CD','#92400E'],
                'Approved'          =>['#D1FAE5','#065F46'],
                'Validated'         =>['#D1FAE5','#065F46'],
                'In Progress'       =>['#DBEAFE','#1E40AF'],
                'Completed'         =>['#DCFCE7','#14532D'],
                'Rejected'          =>['#FEE2E2','#991B1B'],
                'Cancelled'         =>['#FEE2E2','#991B1B'],
                'Adjusted'          =>['#E0E7FF','#3730A3'],
            ];
            foreach ($jo_tracker_rows as $j):
                $jst       = $j['validation_status'] ?: $j['status'] ?: 'Pending Validation';
                $jsc       = $jo_stMap[$jst] ?? ['#f3f4f6','#374151'];
                $svc       = htmlspecialchars($j['service_type'] ?: $j['service_description'] ?: '—');
                $isPending = in_array($jst, ['Pending Validation','Pending']);
                $canAdjust = !in_array($jst, ['Completed','Cancelled']);
                $cost      = (float)($j['total_cost'] ?: 0) > 0
                           ? (float)$j['total_cost']
                           : (float)($j['estimated_cost'] ?? 0);
            ?>
            <tr>
                <td style="font-weight:700;color:#002F6C;">#<?php echo (int)$j['id']; ?></td>
                <td><?php echo htmlspecialchars($j['cust']); ?></td>
                <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo $svc; ?>"><?php echo $svc; ?></td>
                <td style="font-size:11px;color:#555;"><?php echo htmlspecialchars($j['vehicle_plate'] ?? '—'); ?></td>
                <td style="font-size:11px;color:#555;"><?php echo htmlspecialchars($j['mechanic_name'] ?? '—'); ?></td>
                <td style="color:#6c757d;font-size:11px;"><?php echo htmlspecialchars($j['staff_name'] ?? '—'); ?></td>
                <td><span class="jo-badge" style="background:<?php echo $jsc[0]; ?>;color:<?php echo $jsc[1]; ?>;"><?php echo htmlspecialchars($jst); ?></span></td>
                <td>
                    <?php
                        $jps = strtolower($j['payment_status'] ?? 'unpaid');
                        $jpsc = $jps === 'paid' ? '#28a745' : ($jps === 'partial' ? '#e6a817' : '#dc3545');
                        $jpst = $jps === 'partial' ? '#212529' : '#fff';
                    ?>
                    <span class="jo-badge" style="background:<?php echo $jpsc; ?>;color:<?php echo $jpst; ?>;"><?php echo ucfirst($jps); ?></span>
                </td>
                <td style="font-weight:600;">&#8369;<?php echo number_format($cost, 2); ?></td>
                <td style="color:#6c757d;font-size:11px;white-space:nowrap;"><?php echo $j['created_at'] ? date('M j, Y g:i A', strtotime($j['created_at'])) : '—'; ?></td>
                <td>
                    <div class="action-col">
                        <?php if ($isPending): ?>
                        <form method="POST" action="transactions.php" style="margin:0;">
                            <input type="hidden" name="action" value="approve_job_order">
                            <input type="hidden" name="jo_id" value="<?php echo (int)$j['id']; ?>">
                            <input type="hidden" name="jo_source" value="<?php echo htmlspecialchars($j['_source'] ?? 'job_orders'); ?>">
                            <input type="hidden" name="remarks" value="Approved via Job Order Tracker">
                            <input type="hidden" name="_start" value="<?php echo htmlspecialchars($start); ?>">
                            <input type="hidden" name="_end" value="<?php echo htmlspecialchars($end); ?>">
                            <input type="hidden" name="_type" value="jo">
                            <button type="submit" class="jo-act-btn" style="background:#28a745;"
                                onclick="return confirm('Approve Job Order #<?php echo (int)$j['id']; ?>?')">
                                <i class="fas fa-check"></i> Approve
                            </button>
                        </form>
                        <button type="button" class="jo-act-btn" style="background:#dc3545;"
                            onclick="openJORejectModal(<?php echo (int)$j['id']; ?>, '<?php echo htmlspecialchars($j['_source'] ?? 'job_orders'); ?>')">
                            <i class="fas fa-times"></i> Reject
                        </button>
                        <?php endif; ?>
                        <?php if ($canAdjust): ?>
                        <button type="button" class="jo-act-btn" style="background:#002F6C;"
                            onclick="openJOAdjustModal(<?php echo (int)$j['id']; ?>, '<?php echo number_format($cost, 2); ?>')">
                            <i class="fas fa-sliders"></i> Adjust
                        </button>
                        <?php endif; ?>
                        <?php if (!$isPending && !$canAdjust): ?>
                        <span style="font-size:11px;color:#9ca3af;">—</span>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($jo_tracker_rows)): ?>
            <tr>
                <td colspan="10" style="text-align:center;padding:40px;color:#9ca3af;">
                    <i class="fas fa-inbox" style="font-size:28px;display:block;margin-bottom:10px;opacity:.4;"></i>
                    No job orders found.
                </td>
            </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; /* end Tab 2: Job Order Tracker */ ?>

<div id="viewDetailsModal" class="txn-modal" onclick="if(event.target===this)closeViewModal()">
    <div class="txn-modal-content" style="max-width:700px;">
        <div class="txn-modal-header">
            <h3><i class="fas fa-search"></i> Transaction Details</h3>
            <button class="txn-close" onclick="closeViewModal()">&times;</button>
        </div>
        <div class="txn-modal-body" id="vd_body">
            <!-- Populated by JS -->
            <div style="text-align:center;padding:30px;color:#888;">
                <i class="fas fa-spinner fa-spin" style="font-size:24px;"></i>
                <div style="margin-top:8px;">Loading details&hellip;</div>
            </div>
        </div>
        <div class="txn-modal-footer" id="vd_footer">
            <button class="btn-secondary" onclick="closeViewModal()"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
</div>

<!-- ══ REJECT MODAL (Merchandise) ═══════════════════════════════════════════ -->
<div id="rejectModal" class="txn-modal" onclick="if(event.target===this)closeRejectModal()">
    <div class="txn-modal-content" style="max-width:480px;">
        <div class="txn-modal-header">
            <h3><i class="fas fa-times-circle"></i> Reject Transaction</h3>
            <button class="txn-close" onclick="closeRejectModal()">&times;</button>
        </div>
        <form method="POST" onsubmit="return validateReject()">
            <div class="txn-modal-body">
                <input type="hidden" name="action" value="reject_transaction">
                <input type="hidden" id="reject_txn_id"   name="transaction_id">
                <input type="hidden" id="reject_txn_type" name="transaction_type">
                <input type="hidden" name="_start" value="<?php echo htmlspecialchars($start); ?>">
                <input type="hidden" name="_end" value="<?php echo htmlspecialchars($end); ?>">
                <input type="hidden" name="_status" value="<?php echo htmlspecialchars($status_f); ?>">
                <input type="hidden" name="_type" value="<?php echo htmlspecialchars($type_f); ?>">
                <p style="color:#555;font-size:13px;margin-bottom:14px;">
                    <i class="fas fa-info-circle" style="color:#dc3545;"></i>
                    Status will be set to <strong>Rejected</strong>. Staff will need to correct and resubmit.
                </p>
                <div class="form-group">
                    <label class="form-label">Reason for Rejection <span style="color:red;">*</span></label>
                    <textarea id="reject_reason" name="reason" class="form-control" rows="4"
                        placeholder="e.g. Wrong quantity, incorrect payment method, wrong item…" required></textarea>
                </div>
            </div>
            <div class="txn-modal-footer">
                <button type="submit" class="btn-danger"><i class="fas fa-times-circle"></i> Confirm Reject</button>
                <button type="button" class="btn-secondary" onclick="closeRejectModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ ADJUST MODAL (Merchandise) ══════════════════════════════════════════ -->
<div id="adjustModal" class="txn-modal" onclick="if(event.target===this)closeAdjustModal()">
    <div class="txn-modal-content" style="max-width:480px;">
        <div class="txn-modal-header">
            <h3><i class="fas fa-sliders"></i> Adjust Transaction</h3>
            <button class="txn-close" onclick="closeAdjustModal()">&times;</button>
        </div>
        <form method="POST" onsubmit="return validateAdjust()">
            <div class="txn-modal-body">
                <input type="hidden" name="action" value="adjust_transaction">
                <input type="hidden" id="adj_txn_id" name="transaction_id">
                <input type="hidden" name="_start" value="<?php echo htmlspecialchars($start); ?>">
                <input type="hidden" name="_end" value="<?php echo htmlspecialchars($end); ?>">
                <input type="hidden" name="_status" value="<?php echo htmlspecialchars($status_f); ?>">
                <input type="hidden" name="_type" value="<?php echo htmlspecialchars($type_f); ?>">
                <div class="form-group">
                    <label class="form-label">New Total Amount (₱) <span style="color:red;">*</span></label>
                    <input type="number" id="adj_total" name="adj_total" class="form-control" step="0.01" min="0" required placeholder="0.00">
                </div>
                <div class="form-group">
                    <label class="form-label">Adjustment Note <span style="color:red;">*</span></label>
                    <textarea id="adj_note" name="adj_note" class="form-control" rows="3"
                        placeholder="Reason for adjustment…" required></textarea>
                </div>
            </div>
            <div class="txn-modal-footer">
                <button type="submit" class="btn-adjust"><i class="fas fa-sliders"></i> Save Adjustment</button>
                <button type="button" class="btn-secondary" onclick="closeAdjustModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ JO REJECT MODAL ══════════════════════════════════════════════════════ -->
<div id="joRejectModal" class="txn-modal" onclick="if(event.target===this)closeJORejectModal()">
    <div class="txn-modal-content" style="max-width:480px;">
        <div class="txn-modal-header">
            <h3><i class="fas fa-times-circle"></i> Reject Job Order</h3>
            <button class="txn-close" onclick="closeJORejectModal()">&times;</button>
        </div>
        <form method="POST" onsubmit="return validateJOReject()">
            <div class="txn-modal-body">
                <input type="hidden" name="action" value="reject_job_order">
                <input type="hidden" id="jo_reject_id" name="jo_id">
                <input type="hidden" id="jo_reject_source" name="jo_source" value="job_orders">
                <input type="hidden" name="_start" value="<?php echo htmlspecialchars($start); ?>">
                <input type="hidden" name="_end" value="<?php echo htmlspecialchars($end); ?>">
                <input type="hidden" name="_status" value="<?php echo htmlspecialchars($status_f); ?>">
                <input type="hidden" name="_type" value="<?php echo htmlspecialchars($type_f); ?>">
                <p style="color:#555;font-size:13px;margin-bottom:14px;">
                    <i class="fas fa-info-circle" style="color:#dc3545;"></i>
                    Job Order will be set to <strong>Rejected / Cancelled</strong>.
                </p>
                <div class="form-group">
                    <label class="form-label">Reason for Rejection <span style="color:red;">*</span></label>
                    <textarea id="jo_reject_reason" name="reason" class="form-control" rows="4"
                        placeholder="e.g. Duplicate entry, wrong service, customer cancelled…" required></textarea>
                </div>
            </div>
            <div class="txn-modal-footer">
                <button type="submit" class="btn-danger"><i class="fas fa-times-circle"></i> Confirm Reject</button>
                <button type="button" class="btn-secondary" onclick="closeJORejectModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ JO ADJUST MODAL ══════════════════════════════════════════════════════ -->
<div id="joAdjustModal" class="txn-modal" onclick="if(event.target===this)closeJOAdjustModal()">
    <div class="txn-modal-content" style="max-width:480px;">
        <div class="txn-modal-header">
            <h3><i class="fas fa-sliders"></i> Adjust Job Order</h3>
            <button class="txn-close" onclick="closeJOAdjustModal()">&times;</button>
        </div>
        <form method="POST" onsubmit="return validateJOAdjust()">
            <div class="txn-modal-body">
                <input type="hidden" name="action" value="adjust_job_order">
                <input type="hidden" id="jo_adj_id" name="jo_id">
                <input type="hidden" name="_start" value="<?php echo htmlspecialchars($start); ?>">
                <input type="hidden" name="_end" value="<?php echo htmlspecialchars($end); ?>">
                <input type="hidden" name="_status" value="<?php echo htmlspecialchars($status_f); ?>">
                <input type="hidden" name="_type" value="<?php echo htmlspecialchars($type_f); ?>">
                <div class="form-group">
                    <label class="form-label">New Total Cost (₱) <span style="color:red;">*</span></label>
                    <input type="number" id="jo_adj_cost" name="adj_cost" class="form-control" step="0.01" min="0" required placeholder="0.00">
                </div>
                <div class="form-group">
                    <label class="form-label">Adjustment Note <span style="color:red;">*</span></label>
                    <textarea id="jo_adj_note" name="adj_note" class="form-control" rows="3"
                        placeholder="Reason for adjustment…" required></textarea>
                </div>
            </div>
            <div class="txn-modal-footer">
                <button type="submit" class="btn-adjust"><i class="fas fa-sliders"></i> Save Adjustment</button>
                <button type="button" class="btn-secondary" onclick="closeJOAdjustModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>



<script>
// ── View Details ──────────────────────────────────────────────────────────────
function viewDetails(d) {
    document.getElementById('viewDetailsModal').style.display = 'flex';

    const rowId         = d.rowId;
    const txnRef        = d.txnRef;
    const isJO          = d.isJO;
    const product       = d.product;
    const vehicle       = d.vehicle;
    const mechanic      = d.mechanic;
    const qty           = d.qty;
    const unit          = d.unit;
    const subtotal      = d.subtotal;
    const vat           = d.vat;
    const total         = d.total;
    const payment       = d.payment;
    const paymentStatus = d.paymentStatus;
    const staff         = d.staff;
    const staffId       = d.staffId;
    const customer      = d.customer;
    const date          = d.date;
    const status        = d.status;
    const joStatus      = d.joStatus;

    let statusColor = '#6c757d';
    const sl = status.toLowerCase();
    if (sl.includes('approved') || sl.includes('verified')) statusColor = '#28a745';
    else if (sl.includes('pending')) statusColor = '#e6a817';
    else if (sl.includes('rejected') || sl.includes('returned') || sl.includes('cancelled')) statusColor = '#dc3545';
    else if (sl.includes('adjusted')) statusColor = '#6f42c1';

    const typeBadge = isJO
        ? `<span style="background:#fd7e14;color:#fff;padding:2px 10px;border-radius:10px;font-size:11px;font-weight:700;">Job Order</span>`
        : `<span style="background:#0d6efd;color:#fff;padding:2px 10px;border-radius:10px;font-size:11px;font-weight:700;">Merchandise</span>`;

    let html = `
    <div class="detail-grid">
        <div class="detail-item"><span class="detail-label">${isJO ? 'JO ID' : 'Transaction ID'}</span><span class="detail-value" style="font-weight:700;">#${escHtml(txnRef)}</span></div>
        <div class="detail-item"><span class="detail-label">Type</span><span class="detail-value">${typeBadge}</span></div>
        <div class="detail-item"><span class="detail-label">${isJO ? 'Service' : 'Product / Item'}</span><span class="detail-value">${escHtml(product)}</span></div>
        <div class="detail-item"><span class="detail-label">Customer</span><span class="detail-value">${escHtml(customer)}</span></div>`;

    if (isJO) {
        html += `
        <div class="detail-item"><span class="detail-label">Vehicle</span><span class="detail-value">${escHtml(vehicle) || '—'}</span></div>
        <div class="detail-item"><span class="detail-label">Mechanic</span><span class="detail-value">${escHtml(mechanic) || '—'}</span></div>
        <div class="detail-item"><span class="detail-label">JO Status</span><span class="detail-value">${escHtml(joStatus) || '—'}</span></div>
        <div class="detail-item"><span class="detail-label">Payment Status</span><span class="detail-value">${escHtml(paymentStatus) || 'Unpaid'}</span></div>`;
    } else {
        html += `
        <div class="detail-item"><span class="detail-label">Qty</span><span class="detail-value">${escHtml(qty)}</span></div>
        <div class="detail-item"><span class="detail-label">Unit Price</span><span class="detail-value">&#8369;${escHtml(unit)}</span></div>`;
    }

    html += `
        <div class="detail-item"><span class="detail-label">Staff</span><span class="detail-value">${escHtml(staff)}${staffId ? ' <span style="font-size:10px;color:#888;">(ID: '+escHtml(String(staffId))+')</span>' : ''}</span></div>
        <div class="detail-item"><span class="detail-label">Payment Method</span><span class="detail-value">${escHtml(payment)}</span></div>
        <div class="detail-item"><span class="detail-label">Date / Time</span><span class="detail-value">${escHtml(date)}</span></div>
        <div class="detail-item"><span class="detail-label">Validation Status</span><span class="detail-value">
            <span style="background:${statusColor};color:${statusColor==='#e6a817'?'#212529':'#fff'};padding:2px 10px;border-radius:10px;font-size:11px;font-weight:700;">${escHtml(status)}</span>
        </span></div>
        <div class="detail-item"><span class="detail-label">Subtotal</span><span class="detail-value">&#8369;${escHtml(subtotal)}</span></div>
        <div class="detail-item"><span class="detail-label">VAT</span><span class="detail-value">${parseFloat(vat) > 0 ? '&#8369;'+escHtml(vat) : '—'}</span></div>
        <div class="detail-item" style="grid-column:1/-1;"><span class="detail-label">Total Amount</span><span class="detail-value" style="font-size:20px;font-weight:800;color:#002F6C;">&#8369;${escHtml(total)}</span></div>
    </div>`;

    if (!isJO) {
        html += `<div style="margin-top:14px;" id="vd_items_section">
            <div style="font-size:11px;font-weight:700;color:#0056b3;text-transform:uppercase;letter-spacing:.8px;margin-bottom:8px;">Items Purchased</div>
            <div id="vd_items_loading" style="text-align:center;padding:16px;color:#888;">
                <i class="fas fa-spinner fa-spin"></i> Loading items&hellip;
            </div>
        </div>`;
    }

    document.getElementById('vd_body').innerHTML = html;

    const footer = document.getElementById('vd_footer');
    if (!isJO) {
        footer.innerHTML = `
            <button class="btn-receipt-lg" onclick="window.open('receipt.php?id=${encodeURIComponent(txnRef)}&type=merchandise','_blank','width=520,height=800,scrollbars=yes')">
                <i class="fas fa-receipt"></i> Print Receipt
            </button>
            <button class="btn-secondary" onclick="closeViewModal()"><i class="fas fa-times"></i> Close</button>`;
        fetchMerchandiseItems(rowId);
    } else {
        footer.innerHTML = `<button class="btn-secondary" onclick="closeViewModal()"><i class="fas fa-times"></i> Close</button>`;
    }
}

function fetchMerchandiseItems(rowId) {
    fetch(`../backend/api/get_transaction_items.php?id=${rowId}`)
        .then(r => r.json())
        .then(data => {
            const el = document.getElementById('vd_items_loading');
            if (!el) return;
            if (data.success && data.items && data.items.length > 0) {
                let rows = data.items.map(it => `
                    <tr>
                        <td>${escHtml(it.product_name)}</td>
                        <td style="color:#666;font-size:11px;">${escHtml(it.category||'')}${it.size_variant?' · '+escHtml(it.size_variant):''}</td>
                        <td style="text-align:center;">${parseFloat(it.quantity).toFixed(0)}</td>
                        <td style="text-align:right;">&#8369;${parseFloat(it.unit_price).toFixed(2)}</td>
                        <td style="text-align:right;font-weight:700;">&#8369;${parseFloat(it.subtotal).toFixed(2)}</td>
                    </tr>`).join('');
                el.outerHTML = `
                    <table style="width:100%;border-collapse:collapse;font-size:12px;">
                        <thead>
                            <tr style="background:#f0f4ff;">
                                <th style="padding:7px 8px;text-align:left;border-bottom:2px solid #dee2e6;">Item</th>
                                <th style="padding:7px 8px;text-align:left;border-bottom:2px solid #dee2e6;">Category</th>
                                <th style="padding:7px 8px;text-align:center;border-bottom:2px solid #dee2e6;">Qty</th>
                                <th style="padding:7px 8px;text-align:right;border-bottom:2px solid #dee2e6;">Unit Price</th>
                                <th style="padding:7px 8px;text-align:right;border-bottom:2px solid #dee2e6;">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>${rows}</tbody>
                    </table>`;
            } else {
                el.innerHTML = '<span style="color:#aaa;font-style:italic;">No item details available.</span>';
            }
        })
        .catch(() => {
            const el = document.getElementById('vd_items_loading');
            if (el) el.innerHTML = '<span style="color:#aaa;font-style:italic;">Could not load items.</span>';
        });
}

function closeViewModal() { document.getElementById('viewDetailsModal').style.display = 'none'; }

// ── Merchandise Reject Modal ──────────────────────────────────────────────────
function openRejectModal(id, type) {
    document.getElementById('reject_txn_id').value   = id;
    document.getElementById('reject_txn_type').value = type;
    document.getElementById('reject_reason').value   = '';
    document.getElementById('rejectModal').style.display = 'flex';
    setTimeout(() => document.getElementById('reject_reason').focus(), 120);
}
function closeRejectModal() { document.getElementById('rejectModal').style.display = 'none'; }
function validateReject() {
    if (!document.getElementById('reject_reason').value.trim()) {
        alert('Please enter a reason for rejecting this transaction.');
        return false;
    }
    return confirm('Reject this transaction?');
}

// ── Merchandise Adjust Modal ──────────────────────────────────────────────────
function openAdjustModal(id, currentTotal) {
    document.getElementById('adj_txn_id').value = id;
    document.getElementById('adj_total').value  = currentTotal.replace(/,/g,'');
    document.getElementById('adj_note').value   = '';
    document.getElementById('adjustModal').style.display = 'flex';
    setTimeout(() => document.getElementById('adj_total').focus(), 120);
}
function closeAdjustModal() { document.getElementById('adjustModal').style.display = 'none'; }
function validateAdjust() {
    if (!document.getElementById('adj_note').value.trim()) {
        alert('Please enter an adjustment note.');
        return false;
    }
    return confirm('Save this adjustment? This action will be logged in the Audit Trail.');
}

// ── JO Reject Modal ───────────────────────────────────────────────────────────
function openJORejectModal(id, source) {
    document.getElementById('jo_reject_id').value     = id;
    document.getElementById('jo_reject_source').value = source || 'job_orders';
    document.getElementById('jo_reject_reason').value = '';
    document.getElementById('joRejectModal').style.display = 'flex';
    setTimeout(() => document.getElementById('jo_reject_reason').focus(), 120);
}
function closeJORejectModal() { document.getElementById('joRejectModal').style.display = 'none'; }
function validateJOReject() {
    if (!document.getElementById('jo_reject_reason').value.trim()) {
        alert('Please enter a reason for rejecting this Job Order.');
        return false;
    }
    return confirm('Reject this Job Order?');
}

// ── JO Adjust Modal ───────────────────────────────────────────────────────────
function openJOAdjustModal(id, currentCost) {
    document.getElementById('jo_adj_id').value   = id;
    document.getElementById('jo_adj_cost').value = currentCost.replace(/,/g,'');
    document.getElementById('jo_adj_note').value = '';
    document.getElementById('joAdjustModal').style.display = 'flex';
    setTimeout(() => document.getElementById('jo_adj_cost').focus(), 120);
}
function closeJOAdjustModal() { document.getElementById('joAdjustModal').style.display = 'none'; }
function validateJOAdjust() {
    if (!document.getElementById('jo_adj_note').value.trim()) {
        alert('Please enter an adjustment note.');
        return false;
    }
    return confirm('Save this Job Order adjustment? This action will be logged in the Audit Trail.');
}

// ── Utility ───────────────────────────────────────────────────────────────────
function escHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;');
}
</script>

<style>
/* ══ TAB BAR ═══════════════════════════════════════════════════════════════════ */
.txn-tab-bar {
    display: flex;
    gap: 0;
    border-bottom: 2px solid #dee2e6;
    margin-bottom: 20px;
    flex-wrap: wrap;
}
.txn-tab {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 11px 22px;
    font-size: 13px;
    font-weight: 600;
    color: #6c757d;
    text-decoration: none;
    border-bottom: 3px solid transparent;
    margin-bottom: -2px;
    transition: color .15s, border-color .15s;
    white-space: nowrap;
}
.txn-tab:hover { color: #002F6C; }
.txn-tab-active {
    color: #002F6C;
    border-bottom-color: #002F6C;
    background: #f8fbff;
    border-radius: 6px 6px 0 0;
}
.txn-tab-badge {
    background: #dc3545;
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    padding: 1px 6px;
    border-radius: 10px;
    min-width: 18px;
    text-align: center;
}

/* ══ JO TRACKER STYLES ══════════════════════════════════════════════════════════ */
.jo-stat-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin-bottom:20px}
@media(max-width:1100px){.jo-stat-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:600px){.jo-stat-grid{grid-template-columns:repeat(2,1fr)}}
.jo-stat-card{background:#fff;border-radius:12px;padding:14px 10px;box-shadow:0 1px 6px rgba(0,0,0,.07);border:1px solid #e9ecef;text-align:center;cursor:pointer;transition:transform .15s,box-shadow .15s;text-decoration:none;display:block}
.jo-stat-card:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(0,0,0,.1)}
.jo-stat-card.active-filter{border-color:#002F6C;box-shadow:0 0 0 2px #002F6C}
.jo-stat-card .sv{font-size:26px;font-weight:800;line-height:1.1}
.jo-stat-card .sl{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#6c757d;margin-top:4px}
.jo-stat-card .si{font-size:18px;margin-bottom:6px}
.jo-card{background:#fff;border-radius:12px;padding:18px 20px;box-shadow:0 1px 6px rgba(0,0,0,.07);border:1px solid #e9ecef}
.jo-table{width:100%;border-collapse:collapse;font-size:13px}
.jo-table th{background:#f4f5f7;font-weight:600;color:#444;padding:9px 11px;text-align:left;border-bottom:2px solid #e0e0e0;white-space:nowrap}
.jo-table td{padding:9px 11px;border-bottom:1px solid #f0f0f0;vertical-align:middle}
.jo-table tr:last-child td{border-bottom:none}
.jo-table tr:hover td{background:#fafbfc}
.jo-badge{padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap;display:inline-block}
.jo-act-btn{padding:5px 10px;border-radius:4px;font-size:12px;font-weight:600;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:4px;color:#fff;width:100%;justify-content:center}
.jo-act-btn:hover{opacity:.88}
.action-col{display:flex;flex-direction:column;gap:4px;min-width:90px}
.filter-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap}

/* ══ FILTER CARD ═══════════════════════════════════════════════════════════════ */
.flt-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 1px 6px rgba(0,0,0,.05);
    padding: 16px 20px 14px;
    margin-bottom: 18px;
}

.flt-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 14px;
    flex-wrap: wrap;
    gap: 10px;
}

.flt-title {
    font-size: 13px;
    font-weight: 700;
    color: #002F6C;
    text-transform: uppercase;
    letter-spacing: .5px;
    display: flex;
    align-items: center;
    gap: 7px;
}

.flt-export-btns {
    display: flex;
    gap: 8px;
}

/* ── Filter row ── */
.flt-row {
    display: flex;
    gap: 12px;
    align-items: flex-end;
    flex-wrap: wrap;
}

.flt-group {
    display: flex;
    flex-direction: column;
    gap: 5px;
    min-width: 130px;
}

.flt-group-date { min-width: 240px; }
.flt-group-btns { min-width: 160px; }

.flt-lbl {
    font-size: 11px;
    font-weight: 700;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: .5px;
    white-space: nowrap;
}

.flt-inp {
    height: 36px;
    padding: 0 10px;
    border: 1px solid #ced4da;
    border-radius: 7px;
    font-size: 13px;
    color: #333;
    background: #fff;
    outline: none;
    transition: border-color .15s, box-shadow .15s;
    width: 100%;
    box-sizing: border-box;
}
.flt-inp:focus {
    border-color: #002F6C;
    box-shadow: 0 0 0 3px rgba(0,47,108,.1);
}

.flt-select { cursor: pointer; }

.flt-date-wrap {
    display: flex;
    align-items: center;
    gap: 6px;
}
.flt-date-wrap .flt-inp { width: 140px; }
.flt-date-sep {
    font-size: 12px;
    color: #999;
    flex-shrink: 0;
}

/* Customer autocomplete */
.flt-autocomplete-wrap {
    position: relative;
}
.flt-clear-input {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    font-size: 16px;
    color: #999;
    cursor: pointer;
    line-height: 1;
    padding: 0;
}
.flt-clear-input:hover { color: #dc3545; }

/* ── Buttons ── */
.flt-action-row {
    display: flex;
    gap: 8px;
}

.flt-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 0 16px;
    height: 36px;
    border: none;
    border-radius: 7px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    white-space: nowrap;
    transition: all .2s ease;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    position: relative;
    overflow: hidden;
}

.flt-btn:hover { 
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
}

.flt-btn:active {
    transform: translateY(0);
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
}

.flt-btn-search { background: #002F6C; color: #fff; border-color: #001a4d; }
.flt-btn-reset  { background: #002F6C; color: #fff; border-color: #001a4d; }
.flt-btn-excel  { background: #1d6f42; color: #fff; border-color: #164a32; }
.flt-btn-pdf    { background: #c0392b; color: #fff; border-color: #a02e1f; }

/* ── Summary bar ── */
.flt-summary {
    display: flex;
    gap: 18px;
    flex-wrap: wrap;
    align-items: center;
    margin-top: 14px;
    padding-top: 12px;
    border-top: 1px solid #f0f0f0;
    font-size: 13px;
}

.flt-sum-item     { color: #555; }
.flt-sum-pending  { color: #856404; font-weight: 600; }
.flt-sum-verified { color: #155724; font-weight: 600; }
.flt-sum-returned { color: #721c24; font-weight: 600; }
.flt-sum-total    { margin-left: auto; font-weight: 700; color: #002F6C; font-size: 14px; }

/* ══ TABLE LAYOUT ══════════════════════════════════════════════════════════════ */
.txn-table-wrap {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    position: relative;
}

.txn-table {
    width: 100%;
    min-width: 860px;       /* ensures scroll kicks in before columns collapse */
    border-collapse: collapse;
    font-size: 12px;
    table-layout: fixed;
}

.txn-table thead th {
    background: #f8f9fa;
    color: #495057;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .4px;
    padding: 9px 8px;
    border-bottom: 2px solid #dee2e6;
    white-space: nowrap;
    position: sticky;
    top: 0;
    z-index: 2;
}

.txn-table tbody td {
    padding: 8px 8px;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: middle;
    font-size: 12px;
    color: #333;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.txn-table tbody tr:hover td { background: #f8fbff; }

/* ── Column widths (fixed layout) ── */
.col-txnid    { width: 110px; }
.col-type     { width: 52px;  text-align: center; }
.col-product  { width: 130px; }
.col-vehicle  { width: 72px;  }
.col-staff    { width: 80px;  }
.col-subtotal { width: 72px;  text-align: right; }
.col-vat      { width: 60px;  text-align: right; }
.col-total    { width: 76px;  text-align: right; color: #002F6C; }
.col-pay      { width: 72px;  }
.col-date     { width: 76px;  white-space: nowrap; }
.col-status   { width: 76px;  text-align: center; }
.col-actions  { width: 120px; text-align: center; }

/* ── Sticky Actions column ── */
.sticky-col {
    position: sticky;
    right: 0;
    background: #fff;
    z-index: 1;
    box-shadow: -3px 0 8px rgba(0,0,0,.07);
}
.txn-table thead .sticky-col {
    z-index: 3;
    background: #f8f9fa;
}
.txn-table tbody tr:hover .sticky-col { background: #f8fbff; }

/* ── Action buttons — stacked vertically, matching product_management.php ── */
.action-btns {
    display: flex;
    flex-direction: column;
    gap: 4px;
    align-items: stretch;
}

.ab {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    padding: 5px 8px;
    border: none;
    border-radius: 5px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    line-height: 1;
    width: 100%;
    transition: filter .15s;
}
.ab:hover { filter: brightness(.88); }

/* Exact colors from product_management.php */
.ab-view    { background: #28a745; color: #fff; }   /* green     — View Details */
.ab-approve { background: #002F70; color: #fff; }   /* dark blue — Approve      */
.ab-reject  { background: #E3001F; color: #fff; }   /* petron red — Reject      */
.ab-receipt { background: #6c757d; color: #fff; }   /* grey      — Receipt      */
.ab-adjust  { background: #6f42c1; color: #fff; }   /* purple    — Adjust       */

.col-actions { width: 110px; }

/* Row type coloring */
.row-jo   td { background: #fffbf0; }
.row-merch td { background: #f8fbff; }
.txn-table tbody tr:hover td { background: #f0f4ff !important; }

/* ══ MODALS ════════════════════════════════════════════════════════════════════ */
.txn-modal { display:none; position:fixed; z-index:1050; inset:0; background:rgba(0,0,0,.55); align-items:center; justify-content:center; }
.txn-modal-content { background:#fff; border-radius:12px; width:92%; max-width:640px; box-shadow:0 8px 32px rgba(0,0,0,.22); overflow:hidden; max-height:90vh; display:flex; flex-direction:column; }
.txn-modal-header { display:flex; justify-content:space-between; align-items:center; padding:16px 22px; background:#fff; color:#212529; border-bottom:2px solid #e9ecef; flex-shrink:0; }
.txn-modal-header h3 { margin:0; font-size:16px; color:#002F6C; }
.txn-close { background:none; border:none; color:#6c757d; font-size:26px; cursor:pointer; line-height:1; padding:0; }
.txn-close:hover { color:#212529; }
.txn-modal-body { padding:22px; overflow-y:auto; flex:1; }
.txn-modal-footer { display:flex; justify-content:flex-end; gap:10px; padding:14px 22px; background:#f8f9fa; border-top:1px solid #dee2e6; flex-shrink:0; }

/* ── Detail grid ── */
.detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.detail-item { background:#f8f9fa; padding:11px 13px; border-radius:8px; border:1px solid #e9ecef; }
.detail-label { display:block; font-size:10px; font-weight:700; color:#6c757d; text-transform:uppercase; letter-spacing:.6px; margin-bottom:3px; }
.detail-value { display:block; font-size:13px; color:#212529; }

/* ── Form ── */
.form-group { margin-bottom:14px; }
.form-label { display:block; font-weight:600; color:#495057; margin-bottom:6px; font-size:13px; }
.form-control { width:100%; padding:9px 12px; border:1px solid #ced4da; border-radius:6px; font-size:13px; box-sizing:border-box; resize:vertical; }
.form-control:focus { outline:none; border-color:#002F6C; box-shadow:0 0 0 2px rgba(0,47,108,.18); }

/* ── Modal footer buttons ── */
.btn-danger { padding:9px 20px; background:#dc3545; color:#fff; border:none; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px; }
.btn-danger:hover { background:#c82333; }
.btn-secondary { padding:9px 18px; background:#6c757d; color:#fff; border:none; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px; }
.btn-secondary:hover { background:#545b62; }
.btn-receipt-lg { padding:9px 18px; background:#002F6C; color:#fff; border:none; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px; }
.btn-receipt-lg:hover { background:#003d8a; }
.btn-adjust { padding:9px 20px; background:#6f42c1; color:#fff; border:none; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px; }
.btn-adjust:hover { background:#5a32a3; }
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>
