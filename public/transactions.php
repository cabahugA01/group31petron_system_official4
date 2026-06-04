<?php
$page_id = ($_GET['tab'] ?? 'pending') === 'validated' ? 'mgr_txn_validated' : 'mgr_txn_pending';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/transaction_schema_fix.php';
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
        header('Location: transactions.php?' . http_build_query(array_filter(['start'=>$_POST['_start']??'','end'=>$_POST['_end']??'','status'=>$_POST['_status']??'','tab'=>'validated'])));
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
        header('Location: transactions.php?' . http_build_query(array_filter(['start'=>$_POST['_start']??'','end'=>$_POST['_end']??'','tab'=>'validated'])));
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
        header('Location: transactions.php?' . http_build_query(array_filter(['start'=>$_POST['_start']??'','end'=>$_POST['_end']??'','tab'=>'validated'])));
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
        header('Location: transactions.php?' . http_build_query(array_filter(['start'=>$_POST['_start']??'','end'=>$_POST['_end']??'','tab'=>'validated'])));
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
        header('Location: transactions.php?' . http_build_query(array_filter(['start'=>$_POST['_start']??'','end'=>$_POST['_end']??'','tab'=>'validated'])));
        exit;
    }

    // ── Adjust Job Order ──────────────────────────────────────────────────────
    if ($action === 'adjust_job_order') {
        $jo_id    = (int)($_POST['jo_id'] ?? 0);
        $jo_src   = $_POST['jo_source'] ?? 'job_orders';
        $new_cost = (float)($_POST['adj_cost'] ?? 0);
        $adj_note = trim($_POST['adj_note'] ?? '');
        try {
            $pdo->beginTransaction();
            if ($jo_src === 'merchandise_transactions') {
                // JO that lives in merchandise_transactions (created via staff hub)
                $pdo->prepare("UPDATE merchandise_transactions SET total_amount=?, validation_status='Adjusted', validated_by=?, validated_at=NOW(), updated_at=NOW() WHERE id=? AND station_id=?")
                    ->execute([$new_cost, $me['id'], $jo_id, $station_id]);
            } else {
                $pdo->prepare("UPDATE job_orders SET total_cost=?, validation_status='Adjusted', validated_by=?, validated_at=NOW() WHERE id=? AND station_id=?")
                    ->execute([$new_cost, $me['id'], $jo_id, $station_id]);
            }
            try { $pdo->prepare("INSERT INTO audit_trail (transaction_id,manager_id,action_type,new_value,station_id) VALUES (?,?,'Adjust',?,?)")
                ->execute([$jo_id,$me['id'],"JO Adjusted. New cost: ₱{$new_cost}. {$adj_note}",$station_id]); } catch(Exception $ae){}
            log_activity($pdo,$me['id'],'JO_ADJUSTED',"Job Order #{$jo_id} adjusted to ₱{$new_cost} by {$me['name']}.");
            $pdo->commit();
            $_SESSION['success'] = "Job Order #{$jo_id} adjusted successfully.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = 'Error adjusting JO: ' . $e->getMessage();
        }
        header('Location: transactions.php?' . http_build_query(array_filter(['start'=>$_POST['_start']??'','end'=>$_POST['_end']??'','tab'=>'validated'])));
        exit;
    }

    // ── Mark Job Order Paid ───────────────────────────────────────────────────
    if ($action === 'mark_jo_paid') {
        $jo_id   = (int)($_POST['jo_id'] ?? 0);
        $jo_src  = $_POST['jo_source'] ?? 'job_orders';
        try {
            if ($jo_src === 'merchandise_transactions') {
                $pdo->prepare("UPDATE merchandise_transactions SET payment_status='Paid', updated_at=NOW() WHERE id=? AND station_id=?")
                    ->execute([$jo_id, $station_id]);
            } else {
                $pdo->prepare("UPDATE job_orders SET payment_status='Paid', updated_at=NOW() WHERE id=? AND station_id=?")
                    ->execute([$jo_id, $station_id]);
            }
            log_activity($pdo, $me['id'], 'JO_MARKED_PAID', "Job Order #{$jo_id} marked as Paid by {$me['name']}.");
            $_SESSION['success'] = "Job Order #{$jo_id} marked as Paid.";
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error marking paid: ' . $e->getMessage();
        }
        header('Location: transactions.php?' . http_build_query(array_filter(['start'=>$_POST['_start']??'','end'=>$_POST['_end']??'','tab'=>'validated'])));
        exit;
    }

    // ── Process Payment ───────────────────────────────────────────────────────
    if ($action === 'process_payment') {
        $row_id     = (int)($_POST['pay_id'] ?? 0);
        $pay_src    = $_POST['pay_source'] ?? 'merchandise_transactions';
        $pay_status = trim($_POST['pay_status'] ?? 'Unpaid');
        $amt_paid   = (float)($_POST['amount_paid'] ?? 0);
        $pay_note   = trim($_POST['pay_note'] ?? '');
        $valid_ps   = ['Paid','Unpaid','Pending Payment','Partial','Credit'];
        if (!in_array($pay_status, $valid_ps)) $pay_status = 'Unpaid';
        try {
            $pdo->beginTransaction();
            if ($pay_src === 'job_orders') {
                $is_credit = ($pay_status === 'Credit') ? 1 : 0;
                $pdo->prepare("UPDATE job_orders SET payment_status=?, is_credit=?, amount_paid=?, updated_at=NOW() WHERE id=? AND station_id=?")
                    ->execute([$pay_status, $is_credit, $amt_paid, $row_id, $station_id]);
            } else {
                // Check old payment_status to avoid double inventory deduction
                $old_stmt = $pdo->prepare("SELECT payment_status FROM merchandise_transactions WHERE id=? AND station_id=?");
                $old_stmt->execute([$row_id, $station_id]);
                $old_ps = strtolower($old_stmt->fetchColumn() ?? 'unpaid');
                $pdo->prepare("UPDATE merchandise_transactions SET payment_status=?, amount_tendered=?, updated_at=NOW() WHERE id=? AND station_id=?")
                    ->execute([$pay_status, $amt_paid ?: null, $row_id, $station_id]);
                // Auto-deduct inventory only when newly transitioning to a payment state
                $deduct_statuses = ['paid','partial','credit'];
                if (in_array(strtolower($pay_status), $deduct_statuses) && !in_array($old_ps, $deduct_statuses)) {
                    $items_stmt = $pdo->prepare("SELECT product_id, product_name, quantity FROM merchandise_transaction_items WHERE transaction_id = ? AND item_type = 'merchandise'");
                    $items_stmt->execute([$row_id]);
                    foreach ($items_stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                        if (!empty($item['product_id'])) {
                            $pdo->prepare("UPDATE station_inventory SET stock_level = GREATEST(stock_level - ?, 0), last_updated=NOW() WHERE product_id = ? AND station_id = ?")
                                ->execute([$item['quantity'], $item['product_id'], $station_id]);
                        } else {
                            $pdo->prepare("UPDATE station_inventory SET stock_level = GREATEST(stock_level - ?, 0), last_updated=NOW() WHERE product_name = ? AND station_id = ?")
                                ->execute([$item['quantity'], $item['product_name'], $station_id]);
                        }
                    }
                }
            }
            try {
                $pdo->prepare("INSERT INTO audit_trail (transaction_id,manager_id,action_type,new_value,station_id) VALUES (?,?,'Payment',?,?)")
                    ->execute([$row_id, $me['id'], "Payment set to {$pay_status}." . ($pay_note ? " Note: {$pay_note}" : ''), $station_id]);
            } catch(Exception $ae){}
            log_activity($pdo, $me['id'], 'PAYMENT_SET', "Transaction #{$row_id} payment set to {$pay_status} by {$me['name']}.");
            $pdo->commit();
            $_SESSION['success'] = "Payment status set to <strong>{$pay_status}</strong>.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = 'Error setting payment: ' . $e->getMessage();
        }
        header('Location: transactions.php?' . http_build_query(array_filter(['start'=>$_POST['_start']??'','end'=>$_POST['_end']??'','tab'=>'validated'])));
        exit;
    }

    // ── Mark JO In Progress ───────────────────────────────────────────────────
    if ($action === 'mark_jo_inprogress') {
        $jo_id  = (int)($_POST['jo_id'] ?? 0);
        $jo_src = $_POST['jo_source'] ?? 'job_orders';
        try {
            if ($jo_src === 'merchandise_transactions') {
                $pdo->prepare("UPDATE merchandise_transactions SET workflow_status='In Progress', updated_at=NOW() WHERE id=? AND station_id=?")
                    ->execute([$jo_id, $station_id]);
            } else {
                $pdo->prepare("UPDATE job_orders SET status='In Progress', started_at=NOW(), updated_at=NOW() WHERE id=? AND station_id=?")
                    ->execute([$jo_id, $station_id]);
            }
            log_activity($pdo, $me['id'], 'JO_INPROGRESS', "Job Order #{$jo_id} marked In Progress by {$me['name']}.");
            $_SESSION['success'] = "Job Order #{$jo_id} marked as In Progress.";
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error: ' . $e->getMessage();
        }
        header('Location: transactions.php?' . http_build_query(array_filter(['start'=>$_POST['_start']??'','end'=>$_POST['_end']??'','tab'=>'validated'])));
        exit;
    }

    // ── Mark JO Completed ─────────────────────────────────────────────────────
    if ($action === 'mark_jo_completed') {
        $jo_id  = (int)($_POST['jo_id'] ?? 0);
        $jo_src = $_POST['jo_source'] ?? 'job_orders';
        try {
            if ($jo_src === 'merchandise_transactions') {
                $pdo->prepare("UPDATE merchandise_transactions SET workflow_status='Completed', updated_at=NOW() WHERE id=? AND station_id=?")
                    ->execute([$jo_id, $station_id]);
            } else {
                $pdo->prepare("UPDATE job_orders SET status='Completed', completed_at=NOW(), updated_at=NOW() WHERE id=? AND station_id=?")
                    ->execute([$jo_id, $station_id]);
            }
            log_activity($pdo, $me['id'], 'JO_COMPLETED', "Job Order #{$jo_id} marked Completed by {$me['name']}.");
            $_SESSION['success'] = "Job Order #{$jo_id} marked as Completed.";
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error: ' . $e->getMessage();
        }
        header('Location: transactions.php?' . http_build_query(array_filter(['start'=>$_POST['_start']??'','end'=>$_POST['_end']??'','tab'=>'validated'])));
        exit;
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
// Default: last 30 days so existing April data always shows on first load
$start    = $_GET['start']  ?? date('Y-m-d', strtotime('-90 days'));
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
    if ($s === '' || in_array($s, ['pending','pending validation','pendingvalidation','awaiting'])) return 'pending';
    if (in_array($s, ['verified','validated','approved','complete','completed','in progress']))    return 'verified';
    if (in_array($s, ['rejected','returned','cancelled']))  return 'returned';
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
// Dynamically classify transactions as combined, job_order, or merchandise
$mt_txn_type_expr = "
    CASE 
        WHEN (
            (TRIM(COALESCE(mt.job_order_service, '')) <> '') OR 
            (SELECT COUNT(*) FROM merchandise_transaction_items i WHERE i.transaction_id = mt.id AND i.item_type = 'service') > 0
        ) AND (
            (SELECT COUNT(*) FROM merchandise_transaction_items i WHERE i.transaction_id = mt.id AND COALESCE(i.item_type, 'merchandise') = 'merchandise') > 0
        ) THEN 'combined'
        WHEN (
            (TRIM(COALESCE(mt.job_order_service, '')) <> '') OR 
            (SELECT COUNT(*) FROM merchandise_transaction_items i WHERE i.transaction_id = mt.id AND i.item_type = 'service') > 0
        ) THEN 'job_order'
        ELSE 'merchandise'
    END
";
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
        COALESCE(NULLIF(TRIM(mt.workflow_status),''), 'Pending') AS jo_status,
        COALESCE(NULLIF(TRIM(mt.payment_status),''), 'Unpaid') AS payment_status,
        'merchandise_transactions' AS _source
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
        COALESCE(NULLIF(TRIM(jo.payment_status),''), 'Unpaid') AS payment_status,
        'job_orders' AS _source
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

// ── Merge & sort all ─────────────────────────────────────────────────────────
$all_transactions = array_merge($transactions, $job_orders);
usort($all_transactions, fn($a,$b) => strtotime($b['created_at']) - strtotime($a['created_at']));

if ($type_f !== '') {
    $all_transactions = array_filter($all_transactions, function($t) use ($type_f) {
        $type = $t['txn_type'] ?? '';
        if ($type_f === 'merchandise') return $type === 'merchandise';
        if ($type_f === 'jo')          return $type === 'job_order';
        if ($type_f === 'combined')    return $type === 'combined';
        return true;
    });
    $all_transactions = array_values($all_transactions);
}

// ── Split into Pending vs Validated ──────────────────────────────────────────
// Pending  = needs manager action (pending/pending validation)
// Validated = already processed (approved, adjusted, rejected, completed, in progress)
$pending_transactions   = [];
$validated_transactions = [];
foreach ($all_transactions as $t) {
    $ns = normalise_status($t['status'] ?? '');
    if ($ns === 'pending') {
        $pending_transactions[] = $t;
    } else {
        $validated_transactions[] = $t;
    }
}

// Active tab: default to 'pending', switch to 'validated' when ?tab=validated
$active_tab = ($_GET['tab'] ?? 'pending') === 'validated' ? 'validated' : 'pending';

// The table rendered depends on active tab
$display_transactions = ($active_tab === 'validated') ? $validated_transactions : $pending_transactions;

// ── Summary counts ────────────────────────────────────────────────────────────
$pendingCount   = count($pending_transactions);
$verifiedCount  = 0;
$rejectedCount  = 0;
$adjustedCount  = 0;
$grandTotal     = 0.0;
foreach ($validated_transactions as $t) {
    $ns = normalise_status($t['status'] ?? '');
    if ($ns === 'verified')  $verifiedCount++;
    if ($ns === 'returned')  $rejectedCount++;
    if ($ns === 'adjusted')  $adjustedCount++;
    $grandTotal += (float)($t['total'] ?? 0);
}

// ── Job Order Tracker data (Removed to enforce unified view) ────────────────

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1">
            <?php if ($active_tab === 'validated'): ?>
                <i class="fas fa-check-circle" style="color:#22c55e;"></i> Validated Transactions
            <?php else: ?>
                <i class="fas fa-hourglass-half" style="color:#002F70;"></i> Pending Transactions
            <?php endif; ?>
        </h1>
        <div class="sub">
            <?php if ($active_tab === 'validated'): ?>
                Read-only history — all approved, adjusted &amp; rejected transactions
            <?php else: ?>
                Validation queue — Approve, Reject, or Adjust all Merchandise &amp; Job Order entries
            <?php endif; ?>
        </div>
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
    </div>

    <form method="get" id="filterForm">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
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
                    <option value="jo"          <?php echo ($type_f==='jo')          ? 'selected':''; ?>>🔧 Job Order Only</option>
                    <option value="combined"    <?php echo ($type_f==='combined')    ? 'selected':''; ?>>📦 JO with Merch</option>
                </select>
            </div>

            <!-- Buttons -->
            <div class="flt-group flt-group-btns">
                <label class="flt-lbl">&nbsp;</label>
                <div class="flt-action-row">
                    <button type="submit" class="flt-btn flt-btn-search">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <a href="transactions.php?tab=<?= $active_tab ?>" class="flt-btn flt-btn-reset">
                        <i class="fas fa-rotate-left"></i> Reset
                    </a>
                </div>
            </div>

        </div><!-- /.flt-row -->
    </form>



</div><!-- /.flt-card -->

<!-- Transactions Table -->
<div class="card" style="padding:0;" id="printableTable">
    <!-- Summary bar removed -->
    <div class="po-table-wrap">
        <table class="po-table" id="txnTable" style="table-layout:fixed;font-size:12px;">
            <thead>
                <tr>
                    <th class="col-txnid">Transaction / JO ID</th>
                    <th class="col-type">Type</th>
                    <th class="col-customer">Customer</th>
                    <th class="col-product">Service / Merchandise</th>
                    <th class="col-vehicle">Vehicle</th>
                    <th class="col-staff">Mechanic / Staff</th>
                    <th class="col-subtotal">Subtotal</th>
                    <th class="col-vat">VAT</th>
                    <th class="col-total">Total</th>
                    <th class="col-pay">Payment</th>
                    <th class="col-date">Date/Time</th>
                    <th class="col-status">Validation</th>
                    <th class="col-txnstatus">Txn Status</th>
                    <th class="col-actions sticky-col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($display_transactions as $t): ?>
                <?php
                    $ns         = normalise_status($t['status'] ?? '');
                    $isJO       = ($t['txn_type'] ?? '') === 'job_order';
                    $isCombined = ($t['txn_type'] ?? '') === 'combined';
                    $status     = $t['status'] ?? 'Pending Validation';
                    // Refined status display for JO lifecycle: Pending -> Approved -> In Progress -> Completed
                    $statusClass = 'badge-other';
                    $statusLabel = htmlspecialchars($status);
                    $stLower     = strtolower(trim($status));
                    if (in_array($stLower, ['pending', 'pending validation', 'pendingvalidation'])) {
                        $statusClass = 'badge-pending'; $statusLabel = 'Pending';
                    } elseif (in_array($stLower, ['approved', 'validated', 'verified'])) {
                        $statusClass = 'badge-approved'; $statusLabel = 'Approved';
                    } elseif ($stLower === 'in progress') {
                        $statusClass = 'badge-other'; $statusLabel = 'In Progress';
                    } elseif (in_array($stLower, ['complete', 'completed'])) {
                        $statusClass = 'badge-approved'; $statusLabel = 'Completed';
                    } elseif (in_array($stLower, ['rejected', 'returned', 'cancelled'])) {
                        $statusClass = 'badge-rejected'; $statusLabel = 'Rejected';
                    } elseif ($stLower === 'adjusted') {
                        $statusClass = 'badge-adjusted'; $statusLabel = 'Adjusted';
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
                        'isCombined'    => $isCombined,
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
                <tr class="<?php echo ($isJO || $isCombined) ? 'row-jo' : 'row-merch'; ?>">
                    <td class="col-txnid" title="<?php echo htmlspecialchars($txnDisplay); ?>">
                        <span style="font-weight:600;font-size:11px;">#<?php echo htmlspecialchars($txnShort); ?></span>
                    </td>
                    <td class="col-type" style="text-align:center;">
                        <?php if ($isCombined): ?>
                        <span style="font-size:10px;font-weight:700;color:#6f42c1;">JO+M</span>
                        <?php elseif ($isJO): ?>
                        <span style="font-size:10px;font-weight:700;color:#002F70;">JO</span>
                        <?php else: ?>
                        <span style="font-size:10px;font-weight:700;color:#28a745;">Merch</span>
                        <?php endif; ?>
                    </td>
                    <td class="col-customer">
                        <span style="font-size:12px;font-weight:600;color:#1e293b;"><?php echo htmlspecialchars($t['customer'] ?? 'Walk-in'); ?></span>
                    </td>
                    <td class="col-product" title="<?php echo htmlspecialchars($t['product_name']); ?>">
                        <?php echo htmlspecialchars($t['product_name']); ?>
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
                        <?php
                            $ps_raw = $t['payment_status'] ?? 'Unpaid';
                            $ps     = strtolower(trim($ps_raw));
                            if ($ps === 'paid') {
                                $psc = '#166534'; $pst = '#fff';
                            } elseif (in_array($ps, ['partial payment','partial'])) {
                                $psc = '#495057'; $pst = '#fff';
                            } elseif (in_array($ps, ['pending payment','pending'])) {
                                $psc = '#9a3412'; $pst = '#fff';
                            } elseif (in_array($ps, ['credit transaction','credit'])) {
                                $psc = '#6b21a8'; $pst = '#fff';
                            } else {
                                $psc = '#dc3545'; $pst = '#fff'; // Unpaid
                            }
                        ?>
                        <div><span style="background:<?php echo $psc; ?>;color:<?php echo $pst; ?>;padding:1px 6px;border-radius:6px;font-size:9px;font-weight:700;white-space:nowrap;"><?php echo htmlspecialchars($ps_raw); ?></span></div>
                    </td>
                    <td class="col-date"><?php echo date('M d, H:i', strtotime($t['created_at'])); ?></td>
                    <!-- Validation Status -->
                    <td class="col-status">
                        <span class="status-badge <?php echo $statusClass; ?>">
                            <?php echo $statusLabel; ?>
                        </span>
                    </td>
                    <!-- Transaction Status (workflow: In Progress / Completed) -->
                    <td class="col-txnstatus">
                    <?php
                        $wf = strtolower(trim($t['jo_status'] ?? 'pending'));
                        if (in_array($wf, ['in progress','inprogress'])) {
                            echo '<span style="background:#0e7490;color:#fff;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:700;">&#9654; In Progress</span>';
                        } elseif (in_array($wf, ['completed','complete'])) {
                            echo '<span style="background:#166534;color:#fff;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:700;">&#10003; Completed</span>';
                        } elseif (in_array($wf, ['approved','verified','validated'])) {
                            echo '<span style="background:#1d4ed8;color:#fff;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:700;">&#10003; Approved</span>';
                        } else {
                            echo '<span style="color:#94a3b8;font-size:11px;">—</span>';
                        }
                    ?>
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
                                <input type="hidden" name="jo_source" value="<?php echo htmlspecialchars($t['_source'] ?? 'job_orders'); ?>">
                                <input type="hidden" name="remarks" value="Approved via Pending Transactions">
                                <input type="hidden" name="_start" value="<?php echo htmlspecialchars($start); ?>">
                                <input type="hidden" name="_end" value="<?php echo htmlspecialchars($end); ?>">
                                <input type="hidden" name="_status" value="<?php echo htmlspecialchars($status_f); ?>">
                                <input type="hidden" name="_type" value="<?php echo htmlspecialchars($type_f); ?>">
                                <button type="submit" class="jo-act-btn" style="background:#28a745;"><i class="fas fa-check"></i> Approve</button>
                            </form>
                            <!-- JO: Reject -->
                            <button type="button" class="jo-act-btn" style="background:#dc3545;" onclick="openJORejectModal(<?php echo $rowId; ?>, '<?php echo htmlspecialchars($t['_source'] ?? 'job_orders'); ?>')">
                                <i class="fas fa-times"></i> Reject
                            </button>
                            <!-- JO: Adjust -->
                            <button type="button" class="jo-act-btn" style="background:#002F6C;" onclick="openJOAdjustModal(<?php echo $rowId; ?>, '<?php echo number_format($t['total'],2); ?>', '<?php echo htmlspecialchars($t['_source'] ?? 'job_orders'); ?>')">
                                <i class="fas fa-sliders"></i> Adjust
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
                                <button type="submit" class="jo-act-btn" style="background:#28a745;"><i class="fas fa-check"></i> Approve</button>
                            </form>
                            <!-- Merch: Reject -->
                            <button type="button" class="jo-act-btn" style="background:#dc3545;" onclick="openRejectModal('<?php echo $rowId; ?>','merchandise')">
                                <i class="fas fa-times"></i> Reject
                            </button>
                            <!-- Merch: Adjust -->
                            <button type="button" class="jo-act-btn" style="background:#002F6C;" onclick="openAdjustModal(<?php echo $rowId; ?>, '<?php echo number_format($t['total'],2); ?>')">
                                <i class="fas fa-sliders"></i> Adjust
                            </button>
                            <?php endif; ?>
                            <?php endif; ?>

                            <?php
                            // ── Post-approval buttons ─────────────────────────────────────
                            $cur_pay    = strtolower(trim($t['payment_status'] ?? 'unpaid'));
                            $cur_wf     = strtolower(trim($t['jo_status'] ?? 'pending'));
                            $is_paid_ps = in_array($cur_pay, ['paid','partial','credit']);
                            $src        = $t['_source'] ?? 'merchandise_transactions';
                            ?>

                            <?php if ($ns === 'verified'): ?>

                            <?php if ($isJO || $isCombined): ?>
                            <?php if (in_array($cur_wf, ['pending','','pending validation','reviewed'])): ?>
                            <!-- JO: Start Service -->
                            <form method="POST" style="display:contents;">
                                <input type="hidden" name="action" value="mark_jo_inprogress">
                                <input type="hidden" name="jo_id" value="<?php echo $rowId; ?>">
                                <input type="hidden" name="jo_source" value="<?php echo htmlspecialchars($src); ?>">
                                <input type="hidden" name="_start" value="<?php echo htmlspecialchars($start); ?>">
                                <input type="hidden" name="_end" value="<?php echo htmlspecialchars($end); ?>">
                                <input type="hidden" name="_status" value="<?php echo htmlspecialchars($status_f); ?>">
                                <input type="hidden" name="_type" value="<?php echo htmlspecialchars($type_f); ?>">
                                <button type="submit" class="jo-act-btn" style="background:#17a2b8;" onclick="return confirm('Mark as In Progress?')">
                                    <i class="fas fa-play"></i> Start
                                </button>
                            </form>
                            <?php elseif ($cur_wf === 'in progress'): ?>
                            <!-- JO: Complete Service -->
                            <form method="POST" style="display:contents;">
                                <input type="hidden" name="action" value="mark_jo_completed">
                                <input type="hidden" name="jo_id" value="<?php echo $rowId; ?>">
                                <input type="hidden" name="jo_source" value="<?php echo htmlspecialchars($src); ?>">
                                <input type="hidden" name="_start" value="<?php echo htmlspecialchars($start); ?>">
                                <input type="hidden" name="_end" value="<?php echo htmlspecialchars($end); ?>">
                                <input type="hidden" name="_status" value="<?php echo htmlspecialchars($status_f); ?>">
                                <input type="hidden" name="_type" value="<?php echo htmlspecialchars($type_f); ?>">
                                <button type="submit" class="jo-act-btn" style="background:#28a745;" onclick="return confirm('Mark service as Completed?')">
                                    <i class="fas fa-check-double"></i> Complete
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php endif; ?>

                            <!-- Set Payment -->
                            <?php
                            $pbg = $cur_pay === 'paid' ? '#28a745' : ($cur_pay === 'partial' ? '#e6a817' : ($cur_pay === 'credit' ? '#6f42c1' : '#dc3545'));
                            $pfc = ($cur_pay === 'partial') ? '#212529' : '#fff';
                            ?>
                            <button type="button" class="jo-act-btn" style="background:<?php echo $pbg; ?>;color:<?php echo $pfc; ?>;" title="Set Payment Status"
                                onclick="openPaymentModal(<?php echo $rowId; ?>, '<?php echo htmlspecialchars($src); ?>', '<?php echo htmlspecialchars($t['payment_status'] ?? 'Unpaid'); ?>', <?php echo (float)$t['total']; ?>)">
                                <i class="fas fa-credit-card"></i> <?php echo ($cur_pay === 'paid') ? 'Paid ✓' : 'Set Payment'; ?>
                            </button>

                            <?php endif; // verified ?>

                            <!-- Receipt: only after approval + payment finalised -->
                            <?php if ($ns === 'verified' && $is_paid_ps && !$isJO): ?>
                            <button type="button" class="jo-act-btn" style="background:#6c757d;" title="Print Receipt"
                                onclick="window.open('receipt.php?id=<?php echo $receiptId; ?>&type=merchandise','_blank','width=520,height=800,scrollbars=yes')">
                                <i class="fas fa-receipt"></i> Receipt
                            </button>
                            <?php elseif ($ns === 'verified' && $is_paid_ps && ($isJO || $isCombined)): ?>
                            <button type="button" class="jo-act-btn" style="background:#6c757d;" title="Print Receipt"
                                onclick="window.open('receipt.php?id=<?php echo $receiptId; ?>&type=job_order','_blank','width=520,height=800,scrollbars=yes')">
                                <i class="fas fa-receipt"></i> Receipt
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($all_transactions)): ?>
                <tr>
                    <td colspan="14" style="text-align:center;padding:48px;color:#888;">
                        <i class="fas fa-<?php echo $active_tab==='validated'?'check-circle':'inbox'; ?>" style="font-size:36px;display:block;margin-bottom:12px;opacity:0.3;"></i>
                        <?php if ($active_tab === 'validated'): ?>
                            No validated transactions found for the selected date range.
                        <?php else: ?>
                            No pending transactions — all caught up! <a href="transactions.php?tab=validated" style="color:#22c55e;font-weight:700;">View validated history →</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>



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
                <input type="hidden" id="jo_adj_source" name="jo_source" value="job_orders">
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

<!-- ══ PAYMENT MODAL ══════════════════════════════════════════════════════════ -->
<div id="paymentModal" class="txn-modal" onclick="if(event.target===this)closePaymentModal()">
    <div class="txn-modal-content" style="max-width:480px;">
        <div class="txn-modal-header">
            <h3><i class="fas fa-credit-card"></i> Set Payment Status</h3>
            <button class="txn-close" onclick="closePaymentModal()">&times;</button>
        </div>
        <form method="POST" onsubmit="return validatePaymentModal()">
            <div class="txn-modal-body">
                <input type="hidden" name="action" value="process_payment">
                <input type="hidden" id="pm_pay_id"     name="pay_id">
                <input type="hidden" id="pm_pay_source" name="pay_source">
                <input type="hidden" name="_start" value="<?php echo htmlspecialchars($start); ?>">
                <input type="hidden" name="_end"   value="<?php echo htmlspecialchars($end); ?>">
                <input type="hidden" name="_status" value="<?php echo htmlspecialchars($status_f); ?>">
                <input type="hidden" name="_type"   value="<?php echo htmlspecialchars($type_f); ?>">

                <div id="pm_summary" style="background:#f0f4ff;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:13px;color:#002F6C;font-weight:600;"></div>

                <div class="form-group">
                    <label class="form-label">Payment Status <span style="color:red;">*</span></label>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;" id="pm_status_grid">
                        <?php foreach([
                            ['Paid',           '#28a745','#fff','fas fa-check-circle',    'Full payment received'],
                            ['Partial',        '#e6a817','#212529','fas fa-adjust',       'Downpayment / partial'],
                            ['Credit',         '#6f42c1','#fff','fas fa-handshake',       'Tagged as credit/utang'],
                            ['Pending Payment','#17a2b8','#fff','fas fa-hourglass-half',  'Waiting to settle'],
                            ['Unpaid',         '#dc3545','#fff','fas fa-times-circle',    'Not yet paid'],
                        ] as [$ps_val,$ps_bg,$ps_fg,$ps_ico,$ps_desc]): ?>
                        <label class="pm-status-option" style="cursor:pointer;border:2px solid #e9ecef;border-radius:8px;padding:9px 10px;display:flex;align-items:center;gap:8px;transition:border-color .15s;">
                            <input type="radio" name="pay_status" value="<?php echo $ps_val; ?>" class="pm-radio" style="display:none;">
                            <span style="background:<?php echo $ps_bg; ?>;color:<?php echo $ps_fg; ?>;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="<?php echo $ps_ico; ?>"></i>
                            </span>
                            <span>
                                <div style="font-weight:700;font-size:12px;"><?php echo $ps_val; ?></div>
                                <div style="font-size:10px;color:#888;"><?php echo $ps_desc; ?></div>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group" id="pm_amount_wrap">
                    <label class="form-label">Amount Paid (&#8369;)</label>
                    <input type="number" id="pm_amount" name="amount_paid" class="form-control" step="0.01" min="0" placeholder="0.00">
                    <div style="font-size:11px;color:#888;margin-top:4px;">Required for Partial. Leave 0 for full amount.</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Payment Note <span style="font-weight:400;color:#888;">(optional)</span></label>
                    <textarea id="pm_note" name="pay_note" class="form-control" rows="2" placeholder="e.g. GCash ref #, credit approved by manager…"></textarea>
                </div>
            </div>
            <div class="txn-modal-footer">
                <button type="submit" id="pm_submit_btn" class="btn-receipt-lg"><i class="fas fa-save"></i> Save Payment</button>
                <button type="button" class="btn-secondary" onclick="closePaymentModal()">Cancel</button>
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
    const isCombined    = d.isCombined;
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

    let typeBadge = '';
    if (isCombined) {
        typeBadge = `<span style="font-size:11px;font-weight:700;color:#6f42c1;">JO with Merch</span>`;
    } else if (isJO) {
        typeBadge = `<span style="font-size:11px;font-weight:700;color:#002F70;">Job Order</span>`;
    } else {
        typeBadge = `<span style="font-size:11px;font-weight:700;color:#28a745;">Merchandise</span>`;
    }

    let html = `
    <div class="detail-grid">
        <div class="detail-item"><span class="detail-label">${(isJO || isCombined) ? 'JO ID' : 'Transaction ID'}</span><span class="detail-value" style="font-weight:700;">#${escHtml(txnRef)}</span></div>
        <div class="detail-item"><span class="detail-label">Type</span><span class="detail-value">${typeBadge}</span></div>
        <div class="detail-item"><span class="detail-label">${(isJO || isCombined) ? 'Service' : 'Product / Item'}</span><span class="detail-value">${escHtml(product)}</span></div>
        <div class="detail-item"><span class="detail-label">Customer</span><span class="detail-value">${escHtml(customer)}</span></div>`;

    if (isJO || isCombined) {
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

    if (!isJO || isCombined) {
        html += `<div style="margin-top:14px;" id="vd_items_section">
            <div style="font-size:11px;font-weight:700;color:#0056b3;text-transform:uppercase;letter-spacing:.8px;margin-bottom:8px;">Items Purchased</div>
            <div id="vd_items_loading" style="text-align:center;padding:16px;color:#888;">
                <i class="fas fa-spinner fa-spin"></i> Loading items&hellip;
            </div>
        </div>`;
    }

    document.getElementById('vd_body').innerHTML = html;

    const footer = document.getElementById('vd_footer');
    if (!isJO || isCombined) {
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
function openJOAdjustModal(id, currentCost, source) {
    document.getElementById('jo_adj_id').value     = id;
    document.getElementById('jo_adj_cost').value   = currentCost.replace(/,/g,'');
    document.getElementById('jo_adj_note').value   = '';
    document.getElementById('jo_adj_source').value = source || 'job_orders';
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

// ── Payment Modal ─────────────────────────────────────────────────────────────
function openPaymentModal(id, source, currentStatus, total) {
    document.getElementById('pm_pay_id').value     = id;
    document.getElementById('pm_pay_source').value = source;
    document.getElementById('pm_amount').value     = '';
    document.getElementById('pm_note').value       = '';
    document.getElementById('pm_summary').textContent = `Transaction #${id} — Total: ₱${parseFloat(total||0).toFixed(2)}`;

    // Reset radio styles
    document.querySelectorAll('.pm-status-option').forEach(el => {
        el.style.borderColor = '#e9ecef';
        el.style.background  = '#fff';
    });
    // Pre-select current status
    document.querySelectorAll('.pm-radio').forEach(r => {
        r.checked = (r.value === currentStatus);
        if (r.checked) {
            const lbl = r.closest('.pm-status-option');
            if (lbl) { lbl.style.borderColor = '#002F6C'; lbl.style.background = '#f0f4ff'; }
        }
    });
    // Radio style on click
    document.querySelectorAll('.pm-radio').forEach(r => {
        r.addEventListener('change', function() {
            document.querySelectorAll('.pm-status-option').forEach(el => { el.style.borderColor='#e9ecef'; el.style.background='#fff'; });
            if (this.checked) { const lbl = this.closest('.pm-status-option'); if(lbl){ lbl.style.borderColor='#002F6C'; lbl.style.background='#f0f4ff'; } }
        });
    });
    document.getElementById('paymentModal').style.display = 'flex';
}
function closePaymentModal() { document.getElementById('paymentModal').style.display = 'none'; }
function validatePaymentModal() {
    const selected = document.querySelector('.pm-radio:checked');
    if (!selected) { alert('Please select a payment status.'); return false; }
    if (selected.value === 'Partial') {
        const amt = parseFloat(document.getElementById('pm_amount').value||0);
        if (!amt || amt <= 0) { alert('Please enter the partial amount paid.'); return false; }
    }
    return confirm(`Set payment to "${selected.value}"? This will be logged in the Audit Trail.`);
}
</script>

<style>

/* ── Uniform table design ── */
.po-table-wrap { background:#fff; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,0.07); overflow-x:auto; }
.po-table { width:100%; border-collapse:collapse; font-size:0.78rem; }
.po-table thead th { background:#002F70; color:#fff; padding:10px; text-align:left; font-weight:600; font-size:0.82rem; white-space:nowrap; border-bottom:2px solid #002F70; }
.po-table tbody tr { border-bottom:1px solid #f0f0f0; transition:background 0.15s; }
.po-table tbody tr:hover { background:#f5f8ff; }
.po-table tbody td { padding:9px 10px; vertical-align:middle; color:#333; }
/* Status — plain text, NO background color */
.status-badge { display:inline-block; font-size:0.78rem; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; white-space:nowrap; }
.badge-pending   { color:#002F70; }
.badge-approved, .badge-verified, .badge-validated { color:#28a745; }
.badge-rejected, .badge-returned { color:#dc3545; }
.badge-adjusted  { color:#6c757d; }
.badge-other     { color:#6c757d; }
/* Action buttons — ONLY these have colors */
.btn-action { display:inline-flex; align-items:center; justify-content:center; gap:3px; width:95px; height:24px; border:none; border-radius:4px; cursor:pointer; font-size:0.68rem; font-weight:600; text-decoration:none; transition:opacity 0.15s, transform 0.1s; white-space:nowrap; color:#fff; }
.btn-action:hover { opacity:0.85; transform:scale(1.02); }
.btn-approve { background:#28a745; }
.btn-reject  { background:#dc3545; }
.btn-adjust  { background:#002F70; }
.btn-view    { background:#6c757d; }
/* Actions cell stacked */
.actions-cell { display:flex; flex-direction:column; gap:3px;  }
.actions-cell .btn-action { width:100%; justify-content:center; }

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
.action-col{display:flex;flex-direction:column;gap:4px;}
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
    
}

.flt-group-date {  }
.flt-group-btns {  }

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
.flt-sum-pending  { color: #002F70; font-weight: 600; }
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
           /* ensures scroll kicks in before columns collapse */
    border-collapse: collapse;
    font-size: 12px;
    table-layout: fixed;
}

.txn-table thead th {
    background: #002F70;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .4px;
    padding: 9px 8px;
    border-bottom: 2px solid #001a50;
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
.col-customer { width: 100px; }
.col-product  { width: 120px; }
.col-vehicle  { width: 72px;  }
.col-staff    { width: 80px;  }
.col-subtotal { width: 72px;  text-align: right; }
.col-vat      { width: 60px;  text-align: right; }
.col-total    { width: 76px;  text-align: right; color: #002F6C; }
.col-pay      { width: 72px;  }
.col-date     { width: 76px;  white-space: nowrap; }
.col-status   { width: 72px;  text-align: center; }
.col-txnstatus{ width: 90px;  text-align: center; }
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
    background: #002F70;
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

/* Uniform button colors matching design system */
.ab-view    { background: #6c757d; color: #fff; }   /* gray      — View Details */
.ab-approve { background: #28a745; color: #fff; }   /* green     — Approve      */
.ab-reject  { background: #dc3545; color: #fff; }   /* red       — Reject       */
.ab-receipt { background: #6c757d; color: #fff; }   /* gray      — Receipt      */
.ab-adjust  { background: #002F70; color: #fff; }   /* dark blue — Adjust       */

.col-actions { width: 110px; }

/* Remove row type coloring — clean white rows only */
.row-jo   td { background: transparent; }
.row-merch td { background: transparent; }
.txn-table tbody tr:hover td { background: #f5f8ff !important; }

/* ══ MODALS ════════════════════════════════════════════════════════════════════ */
.txn-modal { display:none; position:fixed; z-index:1050; inset:0; background:rgba(0,0,0,.5); align-items:center; justify-content:center; }
.txn-modal-content { background:#fff; border-radius:12px; width:92%; max-width:640px; box-shadow:0 8px 32px rgba(0,0,0,.18); overflow:hidden; max-height:90vh; display:flex; flex-direction:column; }
.txn-modal-header { display:flex; justify-content:space-between; align-items:center; padding:18px 24px; background:#fff; color:#212529; border-bottom:1px solid #e9ecef; flex-shrink:0; }
.txn-modal-header h3 { margin:0; font-size:1.05rem; font-weight:700; color:#002F70; display:flex; align-items:center; gap:8px; }
.txn-close { background:none; border:none; color:#888; font-size:1.4rem; cursor:pointer; line-height:1; padding:0 4px; }
.txn-close:hover { color:#333; }
.txn-modal-body { padding:20px 24px; overflow-y:auto; flex:1; }
.txn-modal-footer { display:flex; justify-content:flex-end; gap:10px; padding:16px 24px; background:#fff; border-top:1px solid #e9ecef; flex-shrink:0; }

/* ── Detail grid ── */
.detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.detail-item { background:#f8f9fa; padding:11px 13px; border-radius:8px; border:1px solid #e9ecef; }
.detail-label { display:block; font-size:10px; font-weight:700; color:#6c757d; text-transform:uppercase; letter-spacing:.6px; margin-bottom:3px; }
.detail-value { display:block; font-size:13px; color:#212529; }

/* ── Form ── */
.form-group { margin-bottom:14px; }
.form-label { display:block; font-weight:600; color:#333; margin-bottom:6px; font-size:0.88rem; }
.form-control { width:100%; padding:9px 12px; border:1px solid #ced4da; border-radius:6px; font-size:0.9rem; box-sizing:border-box; resize:vertical; }
.form-control:focus { outline:none; border-color:#002F70; box-shadow:0 0 0 3px rgba(0,47,112,.1); }

/* ── Modal footer buttons — match PO design ── */
.btn-danger    { padding:9px 20px; background:#dc3545; color:#fff; border:none; border-radius:6px; font-size:0.9rem; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px; }
.btn-danger:hover { background:#b02a37; }
.btn-secondary { padding:9px 18px; background:#e9ecef; color:#333; border:none; border-radius:6px; font-size:0.9rem; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px; }
.btn-secondary:hover { background:#d3d7db; }
.btn-receipt-lg { padding:9px 18px; background:#6c757d; color:#fff; border:none; border-radius:6px; font-size:0.9rem; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px; }
.btn-receipt-lg:hover { background:#545b62; }
.btn-adjust { padding:9px 20px; background:#002F70; color:#fff; border:none; border-radius:6px; font-size:0.9rem; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px; }
.btn-adjust:hover { background:#001f50; }
.btn-approve-lg { padding:9px 20px; background:#28a745; color:#fff; border:none; border-radius:6px; font-size:0.9rem; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px; }
.btn-approve-lg:hover { background:#1e7e34; }
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>
