<?php
/**
 * MANAGER PENDING TRANSACTIONS - NEW DESIGN
 * 
 * Shows ONLY transactions with validation_status = 'Pending' or 'Pending Validation'
 * Manager can: Approve, Reject, Adjust
 * Uses NEW tables: merchandise_transactions, job_orders
 * Design: Petron Blue (#002F70)
 */
$page_id = 'pending_transactions_manager';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/transaction_schema_fix.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();

// Only Manager can access
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Manager role required.';
    header('Location: staff_dashboard.php'); exit;
}

// ── Dynamic column detection ──────────────────────────────────────────────────
function pt_cols(PDO $pdo, string $table): array {
    try {
        $rows = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $r) $map[strtolower($r['Field'])] = true;
        return $map;
    } catch (Exception $e) { return []; }
}
function pt_has(array $map, string $col): bool { return isset($map[strtolower($col)]); }

$mt_cols = pt_cols($pdo, 'merchandise_transactions');
$jo_cols = pt_cols($pdo, 'job_orders');

// ── Payment status helper ─────────────────────────────────────────────────────
function pt_pay_status(array $row): string {
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

function pt_shift_condition(string $alias, array $cols, string $date_expr, array $shift): array {
    $parts = [];
    $params = [];

    if (pt_has($cols, 'shift_period') && !empty($shift['shift_key'])) {
        $parts[] = "LOWER(TRIM({$alias}.shift_period)) = LOWER(?)";
        $params[] = $shift['shift_key'];
    }
    if (pt_has($cols, 'shift_name') && !empty($shift['shift_name'])) {
        $parts[] = "LOWER(TRIM({$alias}.shift_name)) = LOWER(?)";
        $params[] = $shift['shift_name'];
    }

    $start = $shift['start_time'] ?? '';
    $end   = $shift['end_time'] ?? '';
    if ($start !== '' && $end !== '') {
        if ($start <= $end) {
            $parts[] = "TIME({$date_expr}) BETWEEN ? AND ?";
            $params[] = $start;
            $params[] = $end;
        } else {
            $parts[] = "(TIME({$date_expr}) >= ? OR TIME({$date_expr}) <= ?)";
            $params[] = $start;
            $params[] = $end;
        }
    }

    if (!$parts) {
        return ['1=0', []];
    }

    return ['(' . implode(' OR ', $parts) . ')', $params];
}

function pt_apply_payment_breakdown(array &$summary, float $amount, float $paid, float $balance, string $method, string $payment_status): void {
    $method_l = strtolower($method);
    $status_l = strtolower($payment_status);
    $is_credit = strpos($method_l, 'credit') !== false || strpos($method_l, 'utang') !== false
              || strpos($status_l, 'credit') !== false || strpos($status_l, 'receivable') !== false;

    if ($is_credit) {
        $summary['utang'] += $balance > 0 ? $balance : $amount;
        return;
    }

    if ($paid >= $amount - 0.01 || $status_l === 'paid') {
        $summary['paid'] += $amount;
        return;
    }

    if ($paid > 0.01) {
        $summary['paid'] += $paid;
        $summary['pending_payment'] += max(0, $balance);
        return;
    }

    $summary['pending_payment'] += $balance > 0 ? $balance : $amount;
}

// ── POST: Manager actions (Approve, Reject, Adjust) ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';

    $insert_audit = function(int $txn_id, string $action_type, ?string $new_val = null, ?string $src = null) use ($pdo, $me, $station_id) {
        $old_val = null;
        try {
            if ($src === 'job_orders') {
                $check = $pdo->prepare("SELECT validation_status, total_cost FROM job_orders WHERE id = ?");
                $check->execute([$txn_id]);
                $r = $check->fetch(PDO::FETCH_ASSOC);
                if ($r) {
                    $old_val = "Status: " . ($r['validation_status'] ?? 'Pending') . " | Cost: ₱" . number_format((float)($r['total_cost'] ?? 0), 2);
                }
            } else {
                $check = $pdo->prepare("SELECT validation_status, total_amount FROM merchandise_transactions WHERE id = ?");
                $check->execute([$txn_id]);
                $r = $check->fetch(PDO::FETCH_ASSOC);
                if ($r) {
                    $old_val = "Status: " . ($r['validation_status'] ?? 'Pending') . " | Cost: ₱" . number_format((float)($r['total_amount'] ?? 0), 2);
                }
            }
        } catch (Exception $ex) {}

        // ── Write to audit_trail (supports optional staff_id + source_table) ─
        try {
            $at_cols = [];
            try {
                foreach ($pdo->query("SHOW COLUMNS FROM audit_trail")->fetchAll(PDO::FETCH_COLUMN) as $c)
                    $at_cols[$c] = true;
            } catch (Exception $_ce) {}

            if (isset($at_cols['staff_id']) && isset($at_cols['source_table'])) {
                $pdo->prepare("INSERT INTO audit_trail (transaction_id, manager_id, staff_id, action_type, old_value, new_value, station_id, source_table) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$txn_id, $me['id'], $me['id'], $action_type, $old_val, $new_val, $station_id, $src ?? 'merchandise_transactions']);
            } else {
                $pdo->prepare("INSERT INTO audit_trail (transaction_id, manager_id, action_type, old_value, new_value, station_id) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$txn_id, $me['id'], $action_type, $old_val, $new_val, $station_id]);
            }
        } catch (Exception $ae) {
            error_log("Failed to insert into audit_trail: " . $ae->getMessage());
        }

        // ── Write to audit_logs (full detail for Audit Trail report) ─────────
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'System';
            $ref = ($src === 'job_orders') ? "JO-{$txn_id}" : "TXN-{$txn_id}";
            $details = "Manager " . htmlspecialchars($me['name'] ?? $me['username'] ?? 'User') . " executed validation action '$action_type' on transaction {$ref}." . ($new_val ? " Details: {$new_val}" : "");
            
            $pdo->prepare("
                INSERT INTO audit_logs (
                    user_id, log_type, action_type, action_details, entity_type, entity_id, 
                    old_values, new_values, ip_address, user_agent, status, created_at
                ) VALUES (?, 'TRANSACTION', ?, ?, ?, ?, ?, ?, ?, ?, 'SUCCESS', NOW())
            ")->execute([
                $me['id'],
                $action_type,
                $details,
                $src ?: 'merchandise_transactions',
                $txn_id,
                $old_val,
                $new_val,
                $ip,
                $ua
            ]);
        } catch (Exception $al_err) {
            error_log("Failed to insert into audit_logs: " . $al_err->getMessage());
        }
    };

    // ── Approve Group (same customer + same date) ─────────────────────────────
    if ($post_action === 'approve_group') {
        $group_ids = json_decode($_POST['group_ids'] ?? '[]', true);
        $notes     = trim($_POST['notes'] ?? '');
        if (!is_array($group_ids) || empty($group_ids)) {
            $_SESSION['error'] = 'No transactions in group.';
            header('Location: pending_transactions.php'); exit;
        }
        try {
            $pdo->beginTransaction();
            $approved = 0;
            foreach ($group_ids as $item) {
                $rid = (int)($item['id'] ?? 0);
                $src = $item['source'] ?? 'merchandise_transactions';
                if ($rid <= 0) continue;
                if ($src === 'merchandise_transactions') {
                    // Fetch transaction details
                    $txStmt = $pdo->prepare("SELECT * FROM merchandise_transactions WHERE id = ? AND station_id = ?");
                    $txStmt->execute([$rid, $station_id]);
                    $transaction = $txStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($transaction) {
                        // Check customer locked/inactive if credit customer
                        if (!empty($transaction['credit_customer_id'])) {
                            $cust_chk = $pdo->prepare("SELECT status FROM customers WHERE id = ?");
                            $cust_chk->execute([$transaction['credit_customer_id']]);
                            $cust_status = $cust_chk->fetchColumn();
                            if ($cust_status === 'locked') {
                                throw new Exception("Approval blocked: Customer account is locked.");
                            }
                            if ($cust_status === 'inactive') {
                                throw new Exception("Approval blocked: Customer account is inactive.");
                            }
                        }

                        // Deduct stock for merchandise items
                        $itemRows = $pdo->prepare("SELECT product_id, quantity, item_type FROM merchandise_transaction_items WHERE transaction_id = ?");
                        $itemRows->execute([$rid]);
                        $pt_did_deduct = false;
                        foreach ($itemRows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                            if (($row['item_type'] ?? 'merchandise') !== 'service' && $row['product_id'] && $row['quantity'] > 0) {
                                $deductSt = $pdo->prepare("
                                    UPDATE station_inventory
                                    SET stock_level = GREATEST(stock_level - ?, 0),
                                        last_updated = NOW()
                                    WHERE station_id = ? AND product_id = ?
                                ");
                                $deductSt->execute([$row['quantity'], $station_id, $row['product_id']]);
                                if ($deductSt->rowCount() > 0) $pt_did_deduct = true;
                            }
                        }
                        // Flag inventory_deducted = 1 so UI shows correct deduction status
                        if ($pt_did_deduct) {
                            try {
                                $pdo->prepare("UPDATE merchandise_transactions SET inventory_deducted = 1 WHERE id = ? AND station_id = ?")
                                    ->execute([$rid, $station_id]);
                            } catch (Exception $_ptide) {
                                error_log("pending_transactions inventory_deducted flag warning: " . $_ptide->getMessage());
                            }
                        }

                        // Update customer balance if credit transaction
                        if (!empty($transaction['credit_customer_id'])) {
                            $pdo->prepare("UPDATE customers SET balance = balance + ? WHERE id = ?")
                                ->execute([$transaction['total_amount'], $transaction['credit_customer_id']]);
                            
                            // Fetch updated balance
                            $bal_stmt = $pdo->prepare("SELECT balance FROM customers WHERE id = ?");
                            $bal_stmt->execute([$transaction['credit_customer_id']]);
                            $new_bal = (float)$bal_stmt->fetchColumn();
                            
                            $cct_stmt = $pdo->prepare("
                                INSERT INTO customer_credit_transactions (
                                    customer_id, transaction_id, transaction_type, amount, 
                                    running_balance, description, station_id, created_by, created_at
                                ) VALUES (?, ?, 'Sale', ?, ?, ?, ?, ?, NOW())
                            ");
                            $cct_stmt->execute([
                                $transaction['credit_customer_id'],
                                $transaction['transaction_id'],
                                $transaction['total_amount'],
                                $new_bal,
                                "Merchandise Sale (Credit) - Ref: " . $transaction['transaction_id'],
                                $station_id,
                                $me['id']
                            ]);
                        }
                    }

                    $sp = ["validation_status='Approved'"];
                    $sv = [];
                    if (pt_has($mt_cols, 'validated_by')) { $sp[] = "validated_by = ?"; $sv[] = $me['id']; }
                    if (pt_has($mt_cols, 'validated_at')) { $sp[] = "validated_at = NOW()"; }
                    if ($notes !== '' && pt_has($mt_cols, 'remarks')) { $sp[] = "remarks = ?"; $sv[] = "APPROVED: {$notes}"; }
                    // Write manager validation note to dedicated column
                    if (pt_has($mt_cols, 'manager_notes')) {
                        $mgr_note = $notes !== '' ? "Approved: {$notes}" : "Approved";
                        $sp[] = "manager_notes = ?"; $sv[] = $mgr_note;
                    }
                    if (pt_has($mt_cols, 'updated_at'))   { $sp[] = "updated_at = NOW()"; }
                    $pdo->prepare("UPDATE merchandise_transactions SET " . implode(', ', $sp) . " WHERE id = ? AND station_id = ?")
                        ->execute(array_merge($sv, [$rid, $station_id]));
                } else {
                    $admin_rem = $notes !== '' ? "APPROVED: {$notes}" : "APPROVED";
                    $pdo->prepare("UPDATE job_orders SET validation_status='Approved', status='Pending', validated_by=?, validated_at=NOW(), admin_remarks=? WHERE id=? AND station_id=?")
                        ->execute([$me['id'], $admin_rem, $rid, $station_id]);
                }
                $insert_audit($rid, 'Approve', 'Group Approved' . ($notes !== '' ? ": {$notes}" : ''), $src);
                $approved++;
            }
            $pdo->commit();
            $_SESSION['success'] = "{$approved} transaction(s) approved successfully.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = 'Error: ' . $e->getMessage();
        }
        header('Location: pending_transactions.php?t=' . time()); exit;
    }

    // ── Reject Group ──────────────────────────────────────────────────────────
    if ($post_action === 'reject_group') {
        $group_ids = json_decode($_POST['group_ids'] ?? '[]', true);
        $reason    = trim($_POST['reason'] ?? '');
        if (!is_array($group_ids) || empty($group_ids)) {
            $_SESSION['error'] = 'No transactions in group.';
            header('Location: pending_transactions.php'); exit;
        }
        try {
            $pdo->beginTransaction();
            $rejected = 0;
            foreach ($group_ids as $item) {
                $rid = (int)($item['id'] ?? 0);
                $src = $item['source'] ?? 'merchandise_transactions';
                if ($rid <= 0) continue;
                if ($src === 'merchandise_transactions') {
                    $sp = ["validation_status='Rejected'"];
                    $sv = [];
                    if (pt_has($mt_cols, 'validated_by')) { $sp[] = "validated_by = ?"; $sv[] = $me['id']; }
                    if (pt_has($mt_cols, 'validated_at')) { $sp[] = "validated_at = NOW()"; }
                    if (pt_has($mt_cols, 'rejection_reason')) { $sp[] = "rejection_reason = ?"; $sv[] = $reason; }
                    elseif (pt_has($mt_cols, 'remarks')) { $sp[] = "remarks = ?"; $sv[] = 'REJECTED: ' . $reason; }
                    // Write manager rejection note to dedicated column
                    if (pt_has($mt_cols, 'manager_notes')) { $sp[] = "manager_notes = ?"; $sv[] = "Rejected: {$reason}"; }
                    if (pt_has($mt_cols, 'updated_at')) { $sp[] = "updated_at = NOW()"; }
                    $pdo->prepare("UPDATE merchandise_transactions SET " . implode(', ', $sp) . " WHERE id = ? AND station_id = ?")
                        ->execute(array_merge($sv, [$rid, $station_id]));
                } else {
                    $pdo->prepare("UPDATE job_orders SET validation_status='Rejected', status='Cancelled', validated_by=?, validated_at=NOW(), rejection_reason=?, admin_remarks=? WHERE id=? AND station_id=?")
                        ->execute([$me['id'], $reason, "REJECTED: {$reason}", $rid, $station_id]);
                }
                $insert_audit($rid, 'Reject', "Group Rejected: {$reason}", $src);
                $rejected++;
            }
            $pdo->commit();
            $_SESSION['success'] = "{$rejected} transaction(s) rejected.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = 'Error: ' . $e->getMessage();
        }
        header('Location: pending_transactions.php?t=' . time()); exit;
    }

    // ── Adjust Group ──────────────────────────────────────────────────────────
    if ($post_action === 'adjust_group') {
        $group_ids        = json_decode($_POST['group_ids'] ?? '[]', true);
        $new_val          = trim($_POST['new_value'] ?? '');
        $reason           = trim($_POST['reason'] ?? '');
        $item_adjustments = json_decode($_POST['item_adjustments'] ?? '[]', true);

        if (!is_array($group_ids) || empty($group_ids)) {
            $_SESSION['error'] = 'No transactions in group.';
            header('Location: pending_transactions.php'); exit;
        }
        try {
            $pdo->beginTransaction();
            $adjusted = 0;

            // ── Apply item-level adjustments ──────────────────────────────────
            if (!empty($item_adjustments) && is_array($item_adjustments)) {
                $byTxn = [];
                foreach ($item_adjustments as $adj) {
                    $tid = (int)($adj['txn_id'] ?? 0);
                    if ($tid <= 0) continue;
                    $byTxn[$tid][] = $adj;
                }
                foreach ($byTxn as $txn_id => $adjs) {
                    $src = $adjs[0]['source'] ?? 'merchandise_transactions';
                    if ($src === 'merchandise_transactions') {
                        $newTotal = 0;
                        foreach ($adjs as $adj) {
                            $item_id  = (int)($adj['item_id'] ?? 0);
                            $qty      = max(0, (float)($adj['quantity']   ?? 0));
                            $uprice   = max(0, (float)($adj['unit_price'] ?? 0));
                            $subtotal = round($qty * $uprice, 2);
                            $newTotal += $subtotal;
                            if ($item_id > 0) {
                                // merchandise_transaction_items has no updated_at column
                                $pdo->prepare("
                                    UPDATE merchandise_transaction_items
                                    SET quantity = ?, unit_price = ?, subtotal = ?
                                    WHERE id = ?
                                ")->execute([$qty, $uprice, $subtotal, $item_id]);
                            }
                        }
                        $finalTotal = ($new_val !== '' && is_numeric($new_val)) ? (float)$new_val : $newTotal;
                        $sp = ["validation_status='Adjusted'", "total_amount=?"];
                        $sv = [$finalTotal];
                        if (pt_has($mt_cols, 'validated_by')) { $sp[] = "validated_by=?"; $sv[] = $me['id']; }
                        if (pt_has($mt_cols, 'validated_at')) { $sp[] = "validated_at=NOW()"; }
                        if (pt_has($mt_cols, 'remarks'))      { $sp[] = "remarks=?"; $sv[] = "ADJUSTED: {$reason}"; }
                        if (pt_has($mt_cols, 'manager_notes')){ $sp[] = "manager_notes=?"; $sv[] = "Adjusted: {$reason}"; }
                        if (pt_has($mt_cols, 'updated_at'))   { $sp[] = "updated_at=NOW()"; }
                        $pdo->prepare("UPDATE merchandise_transactions SET " . implode(', ', $sp) . " WHERE id=? AND station_id=?")
                            ->execute(array_merge($sv, [$txn_id, $station_id]));
                    } else {
                        $finalTotal = ($new_val !== '' && is_numeric($new_val))
                            ? (float)$new_val
                            : array_sum(array_map(fn($a) => (float)($a['quantity'] ?? 1) * (float)($a['unit_price'] ?? 0), $adjs));
                        $sp = ["validation_status='Adjusted'", "validated_by=?", "validated_at=NOW()", "adjustment_reason=?", "admin_remarks=?"];
                        $sv = [$me['id'], $reason, "ADJUSTED: {$reason}"];
                        if (pt_has($jo_cols, 'total_cost')) { $sp[] = "total_cost=?"; $sv[] = $finalTotal; }
                        $pdo->prepare("UPDATE job_orders SET " . implode(', ', $sp) . " WHERE id=? AND station_id=?")
                            ->execute(array_merge($sv, [$txn_id, $station_id]));
                    }
                    $insert_audit($txn_id, 'Adjust', "Item-level adjusted: {$reason}", $src);
                    $adjusted++;
                }
            } else {
                // Fallback: pure total override
                foreach ($group_ids as $item) {
                    $rid = (int)($item['id'] ?? 0);
                    $src = $item['source'] ?? 'merchandise_transactions';
                    if ($rid <= 0) continue;
                    if ($src === 'merchandise_transactions') {
                        $sp = ["validation_status='Adjusted'"];
                        $sv = [];
                        if (pt_has($mt_cols, 'validated_by')) { $sp[] = "validated_by=?"; $sv[] = $me['id']; }
                        if (pt_has($mt_cols, 'validated_at')) { $sp[] = "validated_at=NOW()"; }
                        if (pt_has($mt_cols, 'remarks'))      { $sp[] = "remarks=?"; $sv[] = "ADJUSTED: {$reason}"; }
                        if (pt_has($mt_cols, 'manager_notes')){ $sp[] = "manager_notes=?"; $sv[] = "Adjusted: {$reason}"; }
                        if (pt_has($mt_cols, 'updated_at'))   { $sp[] = "updated_at=NOW()"; }
                        if ($new_val !== '' && is_numeric($new_val) && pt_has($mt_cols, 'total_amount')) { $sp[] = "total_amount=?"; $sv[] = (float)$new_val; }
                        $pdo->prepare("UPDATE merchandise_transactions SET " . implode(', ', $sp) . " WHERE id=? AND station_id=?")
                            ->execute(array_merge($sv, [$rid, $station_id]));
                    } else {
                        $sp = ["validation_status='Adjusted'", "validated_by=?", "validated_at=NOW()", "adjustment_reason=?", "admin_remarks=?"];
                        $sv = [$me['id'], $reason, "ADJUSTED: {$reason}"];
                        if ($new_val !== '' && is_numeric($new_val) && pt_has($jo_cols, 'total_cost')) { $sp[] = "total_cost=?"; $sv[] = (float)$new_val; }
                        $pdo->prepare("UPDATE job_orders SET " . implode(', ', $sp) . " WHERE id=? AND station_id=?")
                            ->execute(array_merge($sv, [$rid, $station_id]));
                    }
                    $insert_audit($rid, 'Adjust', "Price adjusted: {$reason}", $src);
                    $adjusted++;
                }
            }
            $pdo->commit();
            $_SESSION['success'] = "{$adjusted} transaction(s) adjusted successfully.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = 'Error: ' . $e->getMessage();
        }
        header('Location: pending_transactions.php?t=' . time()); exit;
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$search = trim($_GET['search'] ?? '');

// ── Fetch PENDING Merchandise + Job Orders ────────────────────────────────────
$rows = [];
$total_amount = 0.0;

// Merchandise PENDING transactions
$mt_status_col  = pt_has($mt_cols, 'validation_status') ? 'mt.validation_status' : (pt_has($mt_cols, 'status') ? 'mt.status' : "'Pending'");
$mt_date_col    = pt_has($mt_cols, 'transaction_date')  ? 'mt.transaction_date'  : 'mt.created_at';
$mt_paid_col    = pt_has($mt_cols, 'amount_paid')       ? 'mt.amount_paid'       : 'NULL';
$mt_sku_col     = pt_has($mt_cols, 'item_sku')          ? 'mt.item_sku'          : "NULL";
$mt_cust_id_col = pt_has($mt_cols, 'credit_customer_id')
    ? "CASE WHEN mt.credit_customer_id IS NOT NULL AND mt.credit_customer_id > 0 THEN 'Registered' ELSE 'Walk-in' END"
    : "'Walk-in'";
$mt_txntype_col = pt_has($mt_cols, 'transaction_type')  ? 'mt.transaction_type'  : "NULL";

// Use $mt_status_col in WHERE too (not hardcoded column name)
$mt_where  = "WHERE mt.station_id = ? AND LOWER(TRIM(COALESCE({$mt_status_col},''))) = 'pending'";
$mt_params = [$station_id];
if ($search !== '') {
    $mt_where .= " AND (mt.customer_name LIKE ? OR mt.transaction_id LIKE ?)";
    $mt_params[] = "%$search%"; $mt_params[] = "%$search%";
}

$mt_rows = [];
try {
    $mt_jo_svc_col      = pt_has($mt_cols, 'job_order_service')       ? "COALESCE(mt.job_order_service,'')"       : "''";
    $mt_jo_veh_type_col = pt_has($mt_cols, 'job_order_vehicle_type')  ? "COALESCE(mt.job_order_vehicle_type,'')"  : "''";
    $mt_jo_veh_plate_col= pt_has($mt_cols, 'job_order_vehicle_plate') ? "COALESCE(mt.job_order_vehicle_plate,'')" : "''";

    // items_parts fallback: use item_sku only if column exists
    $items_sku_fallback = pt_has($mt_cols, 'item_sku') ? "mt.item_sku" : "NULL";
    $svc_sku_cond       = pt_has($mt_cols, 'item_sku')
        ? "CASE WHEN mt.item_sku IS NOT NULL AND mt.item_sku <> '' THEN '—' ELSE 'N/A' END"
        : "'N/A'";

    $stmt = $pdo->prepare("
        SELECT
            mt.id AS row_id,
            mt.transaction_id AS txn_id,
            COALESCE(NULLIF(TRIM(mt.customer_name),''),'Walk-in') AS customer,
            {$mt_cust_id_col} AS customer_type,
            CASE COALESCE({$mt_txntype_col},'merchandise')
                WHEN 'combined'   THEN 'JO + Merchandise'
                WHEN 'job_order'  THEN 'Job Order'
                ELSE                   'Merchandise'
            END AS entry_type,
            COALESCE(
                NULLIF((SELECT GROUP_CONCAT(
                            CONCAT(i.product_name, ' - ', CAST(i.quantity AS UNSIGNED), IF(CAST(i.quantity AS UNSIGNED)=1,' pc',' pcs'), ' @ ₱', FORMAT(i.unit_price, 2))
                            ORDER BY i.id SEPARATOR ' | ')
                        FROM merchandise_transaction_items i
                        WHERE i.transaction_id = mt.id
                        AND COALESCE(i.item_type, 'merchandise') = 'merchandise'),''),
                {$items_sku_fallback},
                CASE WHEN TRIM(COALESCE({$mt_jo_svc_col},'')) <> '' THEN '—' ELSE 'N/A' END
            ) AS items_parts,
            COALESCE(
                NULLIF((SELECT GROUP_CONCAT(
                            CONCAT(i.product_name, ' - ', CAST(i.quantity AS UNSIGNED), IF(CAST(i.quantity AS UNSIGNED)=1,' pc',' pcs'), ' @ ₱', FORMAT(i.unit_price, 2))
                            ORDER BY i.id SEPARATOR ' | ')
                        FROM merchandise_transaction_items i
                        WHERE i.transaction_id = mt.id
                        AND i.item_type = 'service'),''),
                NULLIF({$mt_jo_svc_col},''),
                {$svc_sku_cond}
            ) AS service_type,
            NULLIF(TRIM(CONCAT(
                COALESCE({$mt_jo_veh_plate_col},''),
                CASE WHEN TRIM(COALESCE({$mt_jo_veh_type_col},'')) <> ''
                     THEN CONCAT(' · ', {$mt_jo_veh_type_col}) ELSE '' END
            )),'') AS vehicle_info,
            COALESCE(mt.total_amount, 0) AS amount,
            COALESCE((SELECT SUM(COALESCE(i.subtotal,0))
                      FROM merchandise_transaction_items i
                      WHERE i.transaction_id = mt.id
                      AND COALESCE(i.item_type,'merchandise') = 'merchandise'), 0) AS merch_subtotal,
            COALESCE((SELECT SUM(COALESCE(i.subtotal,0))
                      FROM merchandise_transaction_items i
                      WHERE i.transaction_id = mt.id
                      AND i.item_type = 'service'), 0) AS service_fee,
            {$mt_paid_col} AS amount_paid,
            COALESCE(mt.payment_method,'Cash') AS payment_method,
            {$mt_date_col} AS txn_date,
            COALESCE({$mt_status_col},'Pending') AS validation_status,
            COALESCE(NULLIF(CONCAT(TRIM(COALESCE(u.first_name,'')), ' ', TRIM(COALESCE(u.last_name,''))), ' '), u.username, 'Unknown') AS staff_name,
            'merchandise_transactions' AS _source,
            COALESCE({$mt_txntype_col},'merchandise') AS txn_type,
            COALESCE(mt.remarks, '') AS encoder_remarks,
            COALESCE(mt.payment_status,'') AS payment_status
        FROM merchandise_transactions mt
        LEFT JOIN users u ON u.id = mt.staff_id
        {$mt_where}
        GROUP BY mt.id
        ORDER BY {$mt_date_col} DESC
        LIMIT 200
    ");
    $stmt->execute($mt_params);
    $mt_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $mt_rows = [];
    error_log('pending_transactions mt_rows error: ' . $e->getMessage());
}

// Job Orders PENDING VALIDATION
$jo_status_col  = pt_has($jo_cols, 'validation_status') ? 'jo.validation_status' : (pt_has($jo_cols, 'status') ? 'jo.status' : "'Pending Validation'");
$jo_pay_col     = pt_has($jo_cols, 'payment_method')    ? "COALESCE(jo.payment_method,'N/A')" : "'N/A'";
$jo_cost_col    = pt_has($jo_cols, 'total_cost')        ? 'COALESCE(jo.total_cost,0)'    : (pt_has($jo_cols, 'estimated_cost') ? 'COALESCE(jo.estimated_cost,0)' : '0');
$jo_paid_col    = pt_has($jo_cols, 'amount_paid')       ? 'jo.amount_paid'               : 'NULL';
$jo_svc_fee_col = pt_has($jo_cols, 'service_fee')       ? 'COALESCE(jo.service_fee,0)'   : $jo_cost_col; // fallback to total_cost if no dedicated column
$jo_cust_id_col = pt_has($jo_cols, 'credit_customer_id')
    ? "CASE WHEN jo.credit_customer_id IS NOT NULL AND jo.credit_customer_id > 0 THEN 'Registered' ELSE 'Walk-in' END"
    : (pt_has($jo_cols, 'customer_id')
        ? "CASE WHEN jo.customer_id IS NOT NULL AND jo.customer_id > 0 THEN 'Registered' ELSE 'Walk-in' END"
        : "'Walk-in'");
$jo_veh_type_col  = pt_has($jo_cols, 'vehicle_type')  ? 'jo.vehicle_type'  : "NULL";
$jo_veh_plate_col = pt_has($jo_cols, 'vehicle_plate') ? 'jo.vehicle_plate' : "NULL";
$jo_created_by_col = pt_has($jo_cols, 'created_by') ? 'jo.created_by' : (pt_has($jo_cols, 'user_id') ? 'jo.user_id' : 'NULL');

$jo_where  = "WHERE jo.station_id = ? AND LOWER(TRIM(COALESCE({$jo_status_col},''))) = 'pending validation'";
$jo_params = [$station_id];
if ($search !== '') {
    $jo_where .= " AND (jo.customer_name LIKE ?";
    $jo_params[] = "%$search%";
    if (pt_has($jo_cols, 'service_type')) { $jo_where .= " OR jo.service_type LIKE ?"; $jo_params[] = "%$search%"; }
    if (pt_has($jo_cols, 'vehicle_plate')) { $jo_where .= " OR jo.vehicle_plate LIKE ?"; $jo_params[] = "%$search%"; }
    $jo_where .= ")";
}

$jo_rows = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            jo.id AS row_id,
            CONCAT('JO-', LPAD(jo.id, 3, '0')) AS txn_id,
            COALESCE(NULLIF(TRIM(jo.customer_name),''),'Walk-in') AS customer,
            {$jo_cust_id_col} AS customer_type,
            'Job Order' AS entry_type,
            '—' AS items_parts,
            COALESCE(NULLIF(TRIM(COALESCE(jo.service_type,'')), ''), 'Service') AS service_type,
            NULLIF(TRIM(CONCAT(
                COALESCE({$jo_veh_plate_col},''),
                CASE WHEN TRIM(COALESCE({$jo_veh_type_col},'')) <> ''
                     THEN CONCAT(' · ', COALESCE({$jo_veh_type_col},'')) ELSE '' END
            )),'') AS vehicle_info,
            {$jo_cost_col} AS amount,
            {$jo_svc_fee_col} AS service_fee,
            0 AS merch_subtotal,
            {$jo_paid_col} AS amount_paid,
            {$jo_pay_col} AS payment_method,
            jo.created_at AS txn_date,
            COALESCE(NULLIF(TRIM(COALESCE({$jo_status_col},'')), ''), 'Pending Validation') AS validation_status,
            COALESCE(NULLIF(CONCAT(TRIM(COALESCE(u.first_name,'')), ' ', TRIM(COALESCE(u.last_name,''))), ' '), u.username, 'Unknown') AS staff_name,
            'job_orders' AS _source,
            'job_order' AS txn_type,
            COALESCE(NULLIF(TRIM(COALESCE(jo.notes,'')), ''), COALESCE(jo.additional_notes, ''), '') AS encoder_remarks,
            COALESCE(jo.payment_status,'') AS payment_status
        FROM job_orders jo
        LEFT JOIN users u ON u.id = {$jo_created_by_col}
        {$jo_where}
        ORDER BY jo.created_at DESC
        LIMIT 200
    ");
    $stmt->execute($jo_params);
    $jo_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $jo_rows = [];
    error_log('pending_transactions jo_rows error: ' . $e->getMessage());
}

// Merge and sort
$rows = array_merge($mt_rows, $jo_rows);
usort($rows, fn($a, $b) => strtotime($b['txn_date']) - strtotime($a['txn_date']));
foreach ($rows as $r) $total_amount += (float)($r['amount'] ?? 0);

// ── Group by customer + date (ONE row per customer per day) ──────────────────
$groups = [];
foreach ($rows as $r) {
    $cust_key = strtolower(trim($r['customer'] ?? 'walk-in'));
    $date_key  = date('Y-m-d', strtotime($r['txn_date']));
    $gkey      = $cust_key . '|' . $date_key;
    if (!isset($groups[$gkey])) {
        $groups[$gkey] = [
            'customer'      => $r['customer'],
            'customer_type' => $r['customer_type'] ?? 'Walk-in',
            'date'          => $date_key,
            'types'         => [],
            'items_parts'   => [],
            'service_types' => [],
            'vehicle_info'   => $r['vehicle_info'] ?? '',
            'total'          => 0.0,
            'service_fee'    => 0.0,
            'merch_subtotal' => 0.0,
            'pay_methods'    => [],
            'staff'          => $r['staff_name'] ?? 'Unknown',
            'ids'            => [],
            'txn_ids'        => [],
            'has_merch_items'=> false,
            'encoder_remarks'=> [],
            'amount_paid'    => 0.0,
            'pay_statuses'   => [],   // collect all payment statuses in group
        ];
    }
    // Keep first non-empty vehicle_info
    if (empty($groups[$gkey]['vehicle_info']) && !empty($r['vehicle_info'])) {
        $groups[$gkey]['vehicle_info'] = $r['vehicle_info'];
    }
    $groups[$gkey]['types'][] = $r['entry_type'];
    if (isset($r['items_parts']) && $r['items_parts'] !== '—' && $r['items_parts'] !== 'N/A') {
        $groups[$gkey]['items_parts'][] = $r['items_parts'];
    }
    if (isset($r['service_type']) && $r['service_type'] !== '—' && $r['service_type'] !== 'N/A') {
        $groups[$gkey]['service_types'][] = $r['service_type'];
    }
    $groups[$gkey]['total']          += (float)($r['amount'] ?? 0);
    $groups[$gkey]['service_fee']    += (float)($r['service_fee'] ?? 0);
    $groups[$gkey]['merch_subtotal'] += (float)($r['merch_subtotal'] ?? 0);
    if ((float)($r['merch_subtotal'] ?? 0) > 0) $groups[$gkey]['has_merch_items'] = true;
    $pay = trim($r['payment_method'] ?? '');
    if ($pay && !in_array($pay, $groups[$gkey]['pay_methods'])) {
        $groups[$gkey]['pay_methods'][] = $pay;
    }
    $groups[$gkey]['txn_ids'][] = $r['txn_id'];
    $groups[$gkey]['ids'][] = ['id' => (int)$r['row_id'], 'source' => $r['_source']];
    $groups[$gkey]['amount_paid'] += (float)($r['amount_paid'] ?? 0);
    // Collect payment status
    $ps = pt_pay_status($r);
    if (!in_array($ps, $groups[$gkey]['pay_statuses'])) {
        $groups[$gkey]['pay_statuses'][] = $ps;
    }
    $rem = trim($r['encoder_remarks'] ?? '');
    if ($rem !== '' && !in_array($rem, $groups[$gkey]['encoder_remarks'])) {
        $groups[$gkey]['encoder_remarks'][] = $rem;
    }
}
$groups = array_values($groups);

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:18px;">
    <div>
        <h1 class="h1" style="margin:0 0 4px 0;">Pending Transactions</h1>
        <div class="sub">Review staff-encoded records awaiting validation.</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <button type="button" onclick="exportPending('excel')" title="Export to Excel" class="txn-btn success">
            <i class="fas fa-file-excel"></i> Excel
        </button>
        <button type="button" onclick="exportPending('csv')" title="Export to CSV" class="txn-btn primary">
            <i class="fas fa-file-csv"></i> CSV
        </button>
        <button type="button" onclick="exportPending('pdf')" title="Export to PDF" class="txn-btn danger">
            <i class="fas fa-file-pdf"></i> PDF
        </button>
        <a href="<?= in_array($role, ['admin', 'superadmin']) ? 'admin_dashboard.php' : 'manager_dashboard.php'; ?>" class="txn-btn secondary">
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
<?php if (isset($_SESSION['info'])): ?>
<div style="background:#d1ecf1;color:#0c5460;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($_SESSION['info']); unset($_SESSION['info']); ?>
</div>
<?php endif; ?>

<!-- Filter Bar -->
<div class="pt-filter-card">
    <form method="get" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
        <div class="pt-flt-grp">
            <label class="pt-lbl"><i class="fas fa-search"></i> Search</label>
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                   class="pt-inp" placeholder="Transaction ID, customer..." style="width:300px;">
        </div>
        <div style="align-self:flex-end;display:flex !important;flex-direction:row !important;gap:8px;">
            <button type="submit" class="pt-btn pt-btn-search"><i class="fas fa-search"></i> Search</button>
            <a href="?" class="pt-btn pt-btn-reset"><i class="fas fa-rotate-left"></i> Reset</a>
        </div>
    </form>
</div>

<!-- Table -->
<div class="pt-table-wrap">
    <table class="pt-table" style="table-layout:fixed;width:100%;">
        <colgroup>
            <col style="width:8%;"><!-- Txn ID(s) -->
            <col style="width:9%;"><!-- Customer + Type -->
            <col style="width:6%;"><!-- Txn Type -->
            <col style="width:6%;"><!-- Vehicle -->
            <col style="width:13%;"><!-- Items / Parts -->
            <col style="width:11%;"><!-- Service Type -->
            <col style="width:9%;"><!-- Amount Breakdown -->
            <col style="width:5%;"><!-- Method -->
            <col style="width:9%;"><!-- Status (val + inv merged) -->
            <col style="width:6%;"><!-- Date -->
            <col style="width:6%;"><!-- Staff -->
            <col style="width:12%;"><!-- Actions -->
        </colgroup>
        <thead>
            <tr>
                <th>Txn ID(s)</th>
                <th>Customer</th>
                <th>Type</th>
                <th>Vehicle</th>
                <th>Items / Parts</th>
                <th>Service</th>
                <th style="text-align:right;">Amount</th>
                <th>Method</th>
                <th>Status</th>
                <th>Date</th>
                <th>Staff</th>
                <th style="text-align:center;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($groups) > 0): ?>
                <?php foreach ($groups as $g): ?>
                <?php
                    $ids_json     = htmlspecialchars(json_encode($g['ids']), ENT_QUOTES, 'UTF-8');
                    $unique_types = array_unique($g['types']);
                    $unique_items_parts = array_unique($g['items_parts']);
                    $unique_services = array_unique($g['service_types']);
                    $pay_str      = implode(' / ', array_unique($g['pay_methods'])) ?: 'Cash';
                    $count        = count($g['ids']);

                    $has_jo    = in_array('Job Order', $g['types']) || in_array('JO + Merchandise', $g['types']);
                    $has_merch = in_array('Merchandise', $g['types']) || in_array('JO + Merchandise', $g['types']);

                    if ($has_jo && $has_merch)   $badge_class = 'pt-badge-type-combined';
                    elseif ($has_jo)              $badge_class = 'pt-badge-type-jo';
                    else                          $badge_class = 'pt-badge-type';

                    $type_label      = implode('+', array_map('trim', $unique_types));
                    $items_display   = !empty($unique_items_parts) ? implode(' | ', $unique_items_parts) : 'N/A';
                    $service_display = !empty($unique_services) ? implode(' | ', $unique_services) : 'N/A';
                    $vehicle_display = !empty($g['vehicle_info']) ? $g['vehicle_info'] : '—';

                    $cust_type = $g['customer_type'] ?? 'Walk-in';
                    $cust_tag_style = $cust_type === 'Registered'
                        ? 'color:#1e40af;font-size:11px;font-weight:600;'
                        : 'color:#94a3b8;font-size:11px;';

                    $svc_fee   = (float)($g['service_fee'] ?? 0);
                    $merch_sub = (float)($g['merch_subtotal'] ?? 0);
                    $total     = (float)($g['total'] ?? 0);

                    // Inventory tag
                    $inv_tag = $has_merch ? '<span style="font-size:11px;color:#d97706;">Inv: Pending</span>' : '';

                    // Notes tooltip
                    $remarks_display = !empty($g['encoder_remarks']) ? implode(' | ', $g['encoder_remarks']) : '';
                ?>
                <tr>
                    <td style="font-weight:600;font-family:monospace;color:#64748b;line-height:1.4;overflow:hidden;text-overflow:ellipsis;">
                        <?php echo htmlspecialchars(implode('<br>', $g['txn_ids'])); ?>
                    </td>
                    <!-- Customer + Customer Type merged -->
                    <td title="<?php echo htmlspecialchars($g['customer']); ?>" style="overflow:hidden;text-overflow:ellipsis;">
                        <div style="font-weight:600;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($g['customer']); ?></div>
                        <div style="<?= $cust_tag_style ?>"><?= htmlspecialchars($cust_type) ?></div>
                    </td>
                    <td>
                        <span class="pt-badge <?= $badge_class ?>" style="font-size:10px;padding:1px 4px;">
                            <?php echo htmlspecialchars($type_label); ?>
                        </span>
                    </td>
                    <td style="color:#475569;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($vehicle_display); ?>">
                        <?php echo htmlspecialchars($vehicle_display); ?>
                    </td>
                    <td style="line-height:1.3;overflow:hidden;" title="<?php echo htmlspecialchars($items_display); ?>">
                        <?php echo htmlspecialchars(mb_strimwidth($items_display, 0, 50, '…')); ?>
                    </td>
                    <td style="line-height:1.3;overflow:hidden;" title="<?php echo htmlspecialchars($service_display); ?>">
                        <?php echo htmlspecialchars(mb_strimwidth($service_display, 0, 40, '…')); ?>
                    </td>
                    <td style="text-align:right;line-height:1.6;">
                        <?php if ($svc_fee > 0): ?>
                        <div style="color:#7c3aed;font-size:10px;">Svc: &#8369;<?= number_format($svc_fee, 2) ?></div>
                        <?php endif; ?>
                        <?php if ($merch_sub > 0): ?>
                        <div style="color:#0284c7;font-size:10px;">Merch: &#8369;<?= number_format($merch_sub, 2) ?></div>
                        <?php endif; ?>
                        <div style="font-weight:700;color:#002F70;white-space:nowrap;">&#8369;<?= number_format($total, 2) ?></div>
                    </td>
                    <td style="overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($pay_str); ?></td>
                    <!-- Val Status + Payment Status + Inv Status -->
                    <td title="<?= htmlspecialchars($remarks_display ?: 'No notes') ?>">
                        <?php
                        // Validation badge — always Pending here
                        ?>
                        <span class="pt-badge" style="font-size:10px;padding:2px 6px;background:#fef3c7;color:#92400e;border-color:#fde047;display:block;margin-bottom:3px;text-align:center;">
                            ⏳ Pending Validation
                        </span>

                        <?php
                        // Payment status badge
                        $g_paid    = (float)($g['amount_paid'] ?? 0);
                        $g_total   = (float)($g['total'] ?? 0);
                        $g_balance = max(0, $g_total - $g_paid);
                        $g_pay_methods = implode('/', array_unique($g['pay_methods'] ?? []));
                        $is_credit = in_array('Credit', $g['pay_methods'] ?? []);

                        if ($is_credit) {
                            $ps_bg='#f3e8ff'; $ps_c='#6b21a8'; $ps_b='#d8b4fe'; $ps_icon='💳'; $ps_label='Account Receivable';
                        } elseif ($g_paid >= $g_total - 0.009 && $g_total > 0) {
                            $ps_bg='#dcfce7'; $ps_c='#166534'; $ps_b='#86efac'; $ps_icon='✓'; $ps_label='Paid';
                        } elseif ($g_paid > 0.009) {
                            $ps_bg='#dbeafe'; $ps_c='#1d4ed8'; $ps_b='#93c5fd'; $ps_icon='↓'; $ps_label='Down Payment';
                        } else {
                            $ps_bg='#fff1f2'; $ps_c='#9f1239'; $ps_b='#fda4af'; $ps_icon='○'; $ps_label='Pending Payment';
                        }
                        ?>
                        <span style="display:block;text-align:center;background:<?= $ps_bg ?>;color:<?= $ps_c ?>;
                                     border:1px solid <?= $ps_b ?>;font-size:10px;font-weight:700;
                                     padding:2px 6px;border-radius:4px;margin-bottom:3px;">
                            <?= $ps_icon ?> <?= $ps_label ?>
                        </span>

                        <?php if ($g_paid > 0.009 && $g_balance > 0.009): ?>
                        <div style="font-size:10px;color:#9a3412;text-align:center;margin-bottom:2px;">
                            Bal: ₱<?= number_format($g_balance, 2) ?>
                        </div>
                        <?php endif; ?>

                        <?= $inv_tag ?>
                        <?php if ($remarks_display): ?>
                        <div style="font-size:10px;color:#7c3aed;margin-top:2px;text-align:center;"><i class="fas fa-comment-alt"></i></div>
                        <?php endif; ?>
                    </td>
                    <td style="color:#64748b;overflow:hidden;text-overflow:ellipsis;">
                        <?php echo date('M d, Y', strtotime($g['date'])); ?>
                    </td>
                    <td style="color:#64748b;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars(mb_strimwidth($g['staff'], 0, 14, '…')); ?></td>
                    <td style="padding:5px 4px;vertical-align:middle;">
                        <div style="display:flex;flex-direction:column;gap:3px;align-items:stretch;width:100%;">
                            <button class="pt-btn-action-full pt-btn-approve" onclick="openApproveModal('<?= $ids_json ?>')" style="width:100%;">
                                <i class="fas fa-check-circle"></i> Approve
                            </button>
                            <button class="pt-btn-action-full pt-btn-reject" onclick="rejectGroup('<?= $ids_json ?>')" style="width:100%;">
                                <i class="fas fa-times-circle"></i> Reject
                            </button>
                            <button class="pt-btn-action-full pt-btn-adjust" onclick="adjustGroup('<?= $ids_json ?>')" style="width:100%;">
                                <i class="fas fa-edit"></i> Adjust
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="12" style="text-align:center;padding:60px 20px;color:#94a3b8;">
                        <i class="fas fa-check-circle" style="font-size:48px;display:block;margin-bottom:12px;opacity:0.3;"></i>
                        <div style="font-size:16px;font-weight:600;color:#64748b;margin-bottom:4px;">No Pending Transactions</div>
                        <div style="font-size:13px;">All transactions have been validated.</div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Pagination Controls -->
<?php if (count($groups) > 0): ?>
<div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;background:#fff;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 12px 12px;flex-wrap:wrap;gap:12px;">
    <div style="display:flex;align-items:center;gap:8px;">
        <label style="font-size:12px;font-weight:600;">Rows per page:</label>
        <select id="rowsPerPage" class="pag-select">
            <option value="10" selected>10</option>
            <option value="20">20</option>
            <option value="30">30</option>
            <option value="40">40</option>
            <option value="50">50</option>
        </select>
    </div>
    <div style="display:flex;align-items:center;gap:10px;">
        <span id="pageInfo" style="font-size:12px;font-weight:600;">Page 1 of 1</span>
        <div style="display:flex;gap:4px;">
            <button id="prevPage" class="pag-btn" disabled>
                <i class="fas fa-chevron-left"></i>
            </button>
            <button id="nextPage" class="pag-btn">
                <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Approve Modal -->
<div class="pt-modal-overlay" id="approveModal">
    <div class="pt-modal">
        <h3><i class="fas fa-check-circle" style="color:#16a34a;margin-right:8px;"></i>Approve Group</h3>
        <form method="POST" id="approveForm">
            <input type="hidden" name="action" value="approve_group">
            <input type="hidden" name="group_ids" id="approve_group_ids" value="">
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px 14px;margin-bottom:14px;font-size:13px;color:#166534;">
                <i class="fas fa-info-circle" style="margin-right:6px;"></i>
                Approving will deduct merchandise stock and update customer balances.
            </div>
            <label>Validation Notes <span style="color:#94a3b8;font-weight:400;">(optional — for audit trail)</span></label>
            <textarea name="notes" id="approve_notes" placeholder="e.g. Verified items match delivery slip, customer confirmed..." style="min-height:70px;"></textarea>
            <div class="pt-modal-btns">
                <button type="button" class="pt-modal-cancel" onclick="closeApproveModal()">Cancel</button>
                <button type="submit" class="pt-modal-submit" style="color:#16a34a;border-color:#16a34a;"
                        onmouseover="this.style.background='#16a34a';this.style.color='#fff'"
                        onmouseout="this.style.background='white';this.style.color='#16a34a'">
                    <i class="fas fa-check-circle"></i> Approve Group
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Modal -->
<div class="pt-modal-overlay" id="rejectModal">
    <div class="pt-modal">
        <h3><i class="fas fa-times-circle" style="color:#dc2626;margin-right:8px;"></i>Reject Group</h3>
        <form method="POST" id="rejectForm">
            <input type="hidden" name="action" value="reject_group">
            <input type="hidden" name="group_ids" id="reject_group_ids" value="">
            <label>Reason for rejection <span style="color:#dc2626;">*</span></label>
            <textarea name="reason" id="reject_reason" placeholder="Explain why this group of transactions is being rejected..." required style="min-height:80px;"></textarea>
            <div class="pt-modal-btns">
                <button type="button" class="pt-modal-cancel" onclick="closeRejectModal()">Cancel</button>
                <button type="submit" class="pt-modal-submit"
                        style="color:#dc2626;border-color:#dc2626;"
                        onmouseover="this.style.background='#dc2626';this.style.color='#fff'"
                        onmouseout="this.style.background='white';this.style.color='#dc2626'">Reject Group</button>
            </div>
        </form>
    </div>
</div>

<!-- Adjust Modal -->
<div class="pt-modal-overlay" id="adjustModal">
    <div class="pt-modal" style="max-width:680px;max-height:90vh;overflow-y:auto;">
        <h3><i class="fas fa-edit" style="color:#f59e0b;margin-right:8px;"></i>Adjust Transaction</h3>
        <form method="POST" id="adjustForm">
            <input type="hidden" name="action" value="adjust_group">
            <input type="hidden" name="group_ids" id="adjust_group_ids" value="">
            <input type="hidden" name="item_adjustments" id="adjust_item_adjustments" value="">

            <!-- Items Section -->
            <div id="adjustItemsSection" style="display:none;margin-bottom:16px;">
                <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;
                            letter-spacing:.5px;margin-bottom:10px;padding-bottom:6px;
                            border-bottom:1px solid #e2e8f0;">
                    <i class="fas fa-boxes" style="margin-right:5px;color:#f59e0b;"></i>
                    Adjust Items / Parts / Service
                </div>
                <div id="adjustItemsList"></div>
            </div>

            <!-- Price Override -->
            <div id="adjustPriceSection" style="margin-bottom:12px;">
                <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;
                            letter-spacing:.5px;margin-bottom:10px;padding-bottom:6px;
                            border-bottom:1px solid #e2e8f0;">
                    <i class="fas fa-tag" style="margin-right:5px;color:#f59e0b;"></i>
                    Override Total Price (optional)
                </div>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <label style="font-size:12px;color:#64748b;">New Total Amount</label>
                    <div style="position:relative;flex:1;">
                        <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);
                                     color:#64748b;font-weight:600;">₱</span>
                        <input type="number" name="new_value" id="adjust_value" step="0.01" min="0"
                               class="pt-modal-input" 
                               placeholder="Auto-computed from items above"
                               style="padding-left:24px;"
                               oninput="this.dataset.manualOverride = this.value.trim() !== '' ? 'true' : 'false'"
                               onchange="this.dataset.manualOverride = this.value.trim() !== '' ? 'true' : 'false'">
                    </div>
                    <button type="button" onclick="clearTotalOverride()"
                            style="font-size:11px;color:#64748b;background:none;border:1px solid #cbd5e1;
                                   border-radius:6px;padding:5px 10px;cursor:pointer;">
                        <i class="fas fa-sync-alt"></i> Reset to Auto
                    </button>
                </div>
                <div style="font-size:10px;color:#94a3b8;margin-top:4px;padding-left:128px;">
                    Leave blank or click "Reset to Auto" to use computed total from items.
                </div>
            </div>

            <!-- Reason -->
            <div style="margin-bottom:12px;">
                <label style="font-size:12px;font-weight:700;color:#374151;display:block;margin-bottom:5px;">
                    Reason for Adjustment <span style="color:#dc2626;">*</span>
                </label>
                <textarea name="reason" id="adjust_reason"
                          placeholder="Explain why this adjustment is needed..."
                          required style="min-height:60px;"
                          class="pt-modal-input"></textarea>
            </div>

            <!-- Computed Total Preview -->
            <div id="adjustTotalPreview"
                 style="display:none;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;
                        padding:10px 14px;margin-bottom:12px;">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <span style="font-size:12px;color:#166534;">
                        <i class="fas fa-calculator" style="margin-right:6px;"></i>
                        Computed Total from Items:
                    </span>
                    <strong id="adjustComputedTotal" style="font-size:18px;color:#002F70;">₱0.00</strong>
                </div>
            </div>

            <div class="pt-modal-btns">
                <button type="button" class="pt-modal-cancel" onclick="closeAdjustModal()">Cancel</button>
                <button type="submit" class="pt-modal-submit"
                        style="color:#d97706;border-color:#d97706;"
                        onmouseover="this.style.background='#d97706';this.style.color='#fff'"
                        onmouseout="this.style.background='white';this.style.color='#d97706'">
                    <i class="fas fa-save"></i> Apply Adjustment
                </button>
            </div>
        </form>
    </div>
</div>

<style>
/* ── Shared export/action buttons (matches staff_transactions_hub) ── */
.txn-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 7px 14px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid transparent;
    transition: all .2s;
    text-decoration: none;
    background: white !important;
}
.txn-btn.success { color: #16a34a !important; border-color: #16a34a !important; }
.txn-btn.success:hover { background: #16a34a !important; color: white !important; }
.txn-btn.primary { color: #00264D !important; border-color: #00264D !important; }
.txn-btn.primary:hover { background: #00264D !important; color: white !important; }
.txn-btn.danger { color: #dc2626 !important; border-color: #dc2626 !important; }
.txn-btn.danger:hover { background: #dc2626 !important; color: white !important; }
.txn-btn.secondary { color: #4b5563 !important; border-color: #6b7280 !important; }
.txn-btn.secondary:hover { background: #6b7280 !important; color: white !important; }

/* ── Pagination buttons ── */
.pag-btn {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 28px !important;
    height: 28px !important;
    background: #ffffff !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 4px !important;
    color: inherit !important;
    font-size: 12px !important;
    cursor: pointer !important;
    transition: all .15s !important;
}
.pag-btn i { color: inherit !important; }
.pag-btn:hover:not(:disabled) { background: #00264D !important; border-color: #00264D !important; color: #ffffff !important; }
.pag-btn:hover:not(:disabled) i { color: #ffffff !important; }
.pag-btn:disabled { opacity: .4 !important; cursor: not-allowed !important; }

/* ── Rows per page select ── */
.pag-select {
    font-size: 12px !important;
    padding: 4px 8px !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 4px !important;
    background: #ffffff !important;
    color: inherit !important;
    outline: none !important;
    cursor: pointer !important;
}

/* Filter Card */
.pt-filter-card { 
    background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px 18px;margin-bottom:14px;box-shadow:0 1px 4px rgba(0,0,0,.05); 
}
.pt-flt-grp { display:flex;flex-direction:column;gap:4px; }
.pt-lbl { font-size:14px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px; }
.pt-inp { 
    height:40px;padding:0 12px;border:1px solid #cbd5e1;border-radius:7px;font-size:14px;color:#1e293b;background:#fff;outline:none;box-sizing:border-box; 
}
.pt-inp:focus { border-color:#002F70;box-shadow:0 0 0 3px rgba(0,47,112,.1); }
.pt-btn { 
    display:inline-flex;align-items:center;gap:6px;padding:0 18px;height:40px;
    border:1px solid transparent;border-radius:7px;font-size:14px;font-weight:600;
    cursor:pointer;text-decoration:none;white-space:nowrap;transition:all .15s;
    background:white !important;
}
.pt-btn-search { color:#002F70 !important; border-color:#002F70 !important; }
.pt-btn-search:hover { background:#002F70 !important; color:#fff !important; }
.pt-btn-reset  { color:#4b5563 !important; border-color:#6b7280 !important; }
.pt-btn-reset:hover { background:#6b7280 !important; color:#fff !important; }

/* Table */
.pt-table-wrap { width:100%; overflow:hidden; max-width:100% !important; border-radius:10px; border:1px solid #e2e8f0; }
.pt-table { width:100%;border-collapse:collapse;font-size:11px;table-layout:fixed;word-wrap:break-word; }
.pt-table thead th { 
    background:#002F70;color:#fff;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.2px;padding:10px 6px;border-bottom:2px solid #001a3d;text-align:left;overflow:hidden;text-overflow:ellipsis;
}
.pt-table tbody td { padding:8px 6px;border-bottom:1px solid #f1f5f9;vertical-align:middle;background:#fff;font-size:11px;overflow:hidden;text-overflow:ellipsis;word-break:break-word;max-width:0; }
.pt-table tbody tr:hover td { background:#eff6ff; }

/* Badges */
.pt-badge { 
    display:inline-block;padding:3px 10px;border-radius:4px;font-size:11px;font-weight:600;white-space:nowrap;background:#f8fafc;color:#64748b;border:1px solid #e2e8f0;
}
.pt-badge-type         { background:#f1f5f9;color:#475569;border-color:#cbd5e1; }
.pt-badge-type-jo      { background:#dbeafe;color:#1e40af;border-color:#93c5fd; }
.pt-badge-type-combined{ background:#ede9fe;color:#5b21b6;border-color:#c4b5fd; }
.pt-badge-paid { background:#f0fdf4;color:#166534;border-color:#bbf7d0; }
.pt-badge-partial { background:#fef3c7;color:#92400e;border-color:#fde047; }
.pt-badge-unpaid { background:#fef2f2;color:#991b1b;border-color:#fecaca; }

/* Action Buttons — unified outline style matching staff Transaction module */
.pt-btn-action-full { 
    background: white !important;
    width:100%;
    min-width:72px;
    height:32px;
    border-radius:5px;
    border:1px solid transparent;
    cursor:pointer;
    font-size:12px;
    font-weight:600;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:4px;
    transition:all .15s;
    padding:0 8px;
    box-sizing:border-box;
    white-space:nowrap;
}
.pt-btn-action-full:hover { 
    transform:none;
    box-shadow:0 4px 12px rgba(0,0,0,.15);
}
.pt-btn-action-full:active {
    transform:translateY(0);
}
.pt-btn-approve { color:#16a34a !important; border-color:#16a34a !important; }
.pt-btn-approve:hover { background:#16a34a !important; color:#fff !important; }
.pt-btn-reject  { color:#dc2626 !important; border-color:#dc2626 !important; }
.pt-btn-reject:hover  { background:#dc2626 !important; color:#fff !important; }
.pt-btn-adjust  { color:#4b5563 !important; border-color:#6b7280 !important; }
.pt-btn-adjust:hover  { background:#6b7280 !important; color:#fff !important; }

/* Modal */
.pt-modal-overlay { display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center; }
.pt-modal-overlay.active { display:flex; }
.pt-modal { background:#fff;border-radius:12px;padding:28px 28px 22px;width:100%;max-width:480px;box-shadow:0 8px 40px rgba(0,0,0,.18); }
.pt-modal h3 { font-size:16px;font-weight:700;color:#1e293b;margin:0 0 18px 0;display:flex;align-items:center; }
.pt-modal label { display:block;font-size:12px;font-weight:600;color:#475569;margin:0 0 4px 0; }
.pt-modal textarea { width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:7px;font-size:13px;box-sizing:border-box;resize:vertical; }
.pt-modal-input { width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:7px;font-size:13px;box-sizing:border-box; }
.pt-modal-input:focus, .pt-modal textarea:focus { border-color:#002F70;outline:none;box-shadow:0 0 0 3px rgba(0,47,112,.1); }
.pt-modal-btns { display:flex;gap:8px;justify-content:flex-end;margin-top:16px; }
.pt-modal-cancel { padding:8px 16px;background:white;color:#475569;border:1px solid #cbd5e1;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;transition:all .15s; }
.pt-modal-cancel:hover { background:#6b7280;color:#fff;border-color:#6b7280; }
.pt-modal-submit { padding:8px 18px;background:white;border:1px solid currentColor;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;transition:all .15s; }
.pt-modal-submit:hover { filter:brightness(.9); color:#fff !important; background:attr(style background) !important; }
</style>

<script>
function openApproveModal(idsJson) {
    document.getElementById('approve_group_ids').value = idsJson;
    document.getElementById('approve_notes').value = '';
    document.getElementById('approveModal').classList.add('active');
}

function closeApproveModal() {
    document.getElementById('approveModal').classList.remove('active');
}

function approveGroup(idsJson) {
    openApproveModal(idsJson);
}

function rejectGroup(idsJson) {
    document.getElementById('reject_group_ids').value = idsJson;
    document.getElementById('reject_reason').value = '';
    document.getElementById('rejectModal').classList.add('active');
}

function closeRejectModal() {
    document.getElementById('rejectModal').classList.remove('active');
}

function adjustGroup(idsJson) {
    const ids = JSON.parse(idsJson);
    document.getElementById('adjust_group_ids').value = idsJson;
    const adjVal = document.getElementById('adjust_value');
    if (adjVal) { adjVal.value = ''; adjVal.dataset.manualOverride = 'false'; }
    document.getElementById('adjust_reason').value = '';
    document.getElementById('adjustItemsList').innerHTML = 
        '<div style="text-align:center;padding:20px;color:#94a3b8;">' +
        '<i class="fas fa-spinner fa-spin"></i> Loading items...</div>';
    document.getElementById('adjustItemsSection').style.display = 'none';
    document.getElementById('adjustTotalPreview').style.display = 'none';
    document.getElementById('adjustModal').classList.add('active');
    isModalOpen = true;

    // Fetch items for all transactions in the group
    const fetchPromises = ids.map(item => {
        // Always fetch from server — handles both merchandise_transactions and job_orders
        return fetch('get_transaction_items.php?id=' + encodeURIComponent(item.id) + '&source=' + encodeURIComponent(item.source), {credentials:'same-origin'})
            .then(r => {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .catch(err => {
                console.error('Failed to fetch items for id=' + item.id, err);
                return { items: [], service_type: '', total_cost: 0, id: item.id, source: item.source };
            });
    });

    Promise.all(fetchPromises).then(results => {
        let allItems = [];
        results.forEach((res, idx) => {
            const sourceId = ids[idx].id;
            const source   = ids[idx].source;

            if (res.error) {
                console.warn('Server error for id=' + sourceId + ':', res.error);
            }

            if (res.items && res.items.length > 0) {
                // Has item breakdown — use it
                res.items.forEach(it => {
                    allItems.push({ ...it, _txn_id: sourceId, _source: source });
                });
            } else if (source === 'job_orders' && (res.service_type || res.total_cost)) {
                // Job order fallback — single service line
                allItems.push({
                    id:           null,
                    product_name: res.service_type || 'Service Fee',
                    item_type:    'service',
                    quantity:     1,
                    unit_price:   parseFloat(res.total_cost) || 0,
                    subtotal:     parseFloat(res.total_cost) || 0,
                    _txn_id:      sourceId,
                    _source:      source,
                    _jo:          true
                });
            } else if (source === 'merchandise_transactions') {
                // Fallback: single line from total_amount
                allItems.push({
                    id:           null,
                    product_name: res.item_label || 'Item',
                    item_type:    'merchandise',
                    quantity:     1,
                    unit_price:   parseFloat(res.total_amount) || 0,
                    subtotal:     parseFloat(res.total_amount) || 0,
                    _txn_id:      sourceId,
                    _source:      source
                });
            }
        });

        if (allItems.length === 0) {
            document.getElementById('adjustItemsList').innerHTML =
                '<div style="font-size:12px;color:#e65c00;padding:12px;background:#fff7ed;border-radius:6px;border:1px solid #fed7aa;">' +
                '<i class="fas fa-exclamation-triangle" style="margin-right:6px;"></i>' +
                'Could not load item breakdown. Use the Total Price override below.</div>';
            document.getElementById('adjustItemsSection').style.display = 'block';
        } else {
            renderAdjustItems(allItems);
            document.getElementById('adjustItemsSection').style.display = 'block';
        }
    }).catch(err => {
        console.error('adjustGroup fetchAll error:', err);
        document.getElementById('adjustItemsList').innerHTML =
            '<div style="font-size:12px;color:#dc2626;padding:12px;">Error loading items. Please try again.</div>';
        document.getElementById('adjustItemsSection').style.display = 'block';
    });
}

function renderAdjustItems(items) {
    const container = document.getElementById('adjustItemsList');
    let html = `
    <div style="display:grid;grid-template-columns:1fr auto auto auto;gap:6px 10px;
                align-items:center;margin-bottom:8px;">
        <div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;padding:0 4px;">Item / Service</div>
        <div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;text-align:center;width:70px;">Qty (pcs)</div>
        <div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;text-align:right;width:100px;">Unit Price (₱)</div>
        <div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;text-align:right;width:90px;">Subtotal</div>
    </div>`;

    items.forEach((it, idx) => {
        const isService = it.item_type === 'service';
        const icon = isService ? 'fa-wrench' : 'fa-box';
        const color = isService ? '#b45309' : '#1d4ed8';
        const qty = parseFloat(it.quantity) || 1;
        const price = parseFloat(it.unit_price) || 0;
        const subtotal = (qty * price).toFixed(2);

        html += `
        <div style="display:grid;grid-template-columns:1fr auto auto auto;gap:6px 10px;
                    align-items:center;padding:8px 6px;background:${idx%2===0?'#f8fafc':'#fff'};
                    border-radius:6px;margin-bottom:4px;">
            <div style="font-size:12px;font-weight:600;color:#1e293b;display:flex;align-items:center;gap:6px;overflow:hidden;">
                <i class="fas ${icon}" style="color:${color};font-size:11px;flex-shrink:0;"></i>
                <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                      title="${escHtml(it.product_name)}">${escHtml(it.product_name)}</span>
            </div>
            <div>
                <input type="number" min="0.5" step="0.5"
                       value="${qty}"
                       data-idx="${idx}"
                       onchange="recalcAdjust()"
                       oninput="recalcAdjust()"
                       class="adj-qty-input"
                       style="width:70px;padding:5px 8px;border:1.5px solid #cbd5e1;border-radius:6px;
                              font-size:12px;text-align:center;box-sizing:border-box;">
            </div>
            <div>
                <input type="number" min="0" step="0.01"
                       value="${price.toFixed(2)}"
                       data-idx="${idx}"
                       onchange="recalcAdjust()"
                       oninput="recalcAdjust()"
                       class="adj-price-input"
                       style="width:100px;padding:5px 8px;border:1.5px solid #cbd5e1;border-radius:6px;
                              font-size:12px;text-align:right;box-sizing:border-box;">
            </div>
            <div class="adj-subtotal" data-idx="${idx}"
                 style="font-size:12px;font-weight:700;color:#002F70;text-align:right;
                        width:90px;white-space:nowrap;">
                ₱${subtotal}
            </div>
        </div>
        <input type="hidden" class="adj-item-id"   data-idx="${idx}" value="${it.id || ''}">
        <input type="hidden" class="adj-item-src"  data-idx="${idx}" value="${it._source}">
        <input type="hidden" class="adj-txn-id"    data-idx="${idx}" value="${it._txn_id}">
        <input type="hidden" class="adj-item-type" data-idx="${idx}" value="${it.item_type || 'merchandise'}">
        <input type="hidden" class="adj-item-name" data-idx="${idx}" value="${escHtml(it.product_name)}">`;
    });

    container.innerHTML = html;
    recalcAdjust();
}

function recalcAdjust() {
    const qtys   = document.querySelectorAll('.adj-qty-input');
    const prices = document.querySelectorAll('.adj-price-input');
    const subs   = document.querySelectorAll('.adj-subtotal');
    let total = 0;

    qtys.forEach((qEl, i) => {
        const q = parseFloat(qEl.value) || 0;
        const p = parseFloat(prices[i]?.value) || 0;
        const sub = q * p;
        total += sub;
        if (subs[i]) subs[i].textContent = '₱' + sub.toFixed(2);
    });

    // Auto-fill the New Total Amount field
    const overrideField = document.getElementById('adjust_value');
    if (overrideField && overrideField.dataset.manualOverride !== 'true') {
        overrideField.value = total.toFixed(2);
    }

    document.getElementById('adjustComputedTotal').textContent = '₱' + total.toFixed(2);
    document.getElementById('adjustTotalPreview').style.display = qtys.length > 0 ? 'block' : 'none';

    // Build item_adjustments JSON
    const items = [];
    qtys.forEach((qEl, i) => {
        items.push({
            item_id:    document.querySelectorAll('.adj-item-id')[i]?.value || '',
            txn_id:     document.querySelectorAll('.adj-txn-id')[i]?.value || '',
            source:     document.querySelectorAll('.adj-item-src')[i]?.value || '',
            item_type:  document.querySelectorAll('.adj-item-type')[i]?.value || 'merchandise',
            name:       document.querySelectorAll('.adj-item-name')[i]?.value || '',
            quantity:   parseFloat(qEl.value) || 0,
            unit_price: parseFloat(document.querySelectorAll('.adj-price-input')[i]?.value) || 0,
        });
    });
    document.getElementById('adjust_item_adjustments').value = JSON.stringify(items);
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function clearTotalOverride() {
    const f = document.getElementById('adjust_value');
    if (f) {
        f.value = '';
        f.dataset.manualOverride = 'false';
        recalcAdjust(); // re-populate with computed
    }
}

function closeAdjustModal() {
    document.getElementById('adjustModal').classList.remove('active');
}

// Close modals on overlay click
document.getElementById('approveModal').addEventListener('click', function(e) {
    if (e.target === this) closeApproveModal();
});
document.getElementById('rejectModal').addEventListener('click', function(e) {
    if (e.target === this) closeRejectModal();
});
document.getElementById('adjustModal').addEventListener('click', function(e) {
    if (e.target === this) closeAdjustModal();
});

// ══════════════════════════════════════════════════════════════════════════════
// AUTO-REFRESH: Pending Transactions (30-second polling for near real-time updates)
// ══════════════════════════════════════════════════════════════════════════════
let refreshPendingTimer = null;
let isModalOpen = false;

function autoRefreshPendingTransactions() {
    if (isModalOpen) return;
    const urlParams = new URLSearchParams(window.location.search);
    const currentSearch = urlParams.toString();
    const reloadUrl = currentSearch ? '?' + currentSearch : window.location.pathname;
    window.location.replace(reloadUrl + (currentSearch ? '&t=' : '?t=') + Date.now());
}

function updateModalState() {
    const rejectModal = document.getElementById('rejectModal');
    const adjustModal = document.getElementById('adjustModal');
    isModalOpen = rejectModal.classList.contains('active') || adjustModal.classList.contains('active')
               || document.getElementById('approveModal').classList.contains('active');
}

const originalCloseRejectModal = window.closeRejectModal;
window.closeRejectModal = function() {
    originalCloseRejectModal();
    updateModalState();
};

const originalCloseAdjustModal = window.closeAdjustModal;
window.closeAdjustModal = function() {
    originalCloseAdjustModal();
    updateModalState();
};

function exportPending(format) {
    const table = document.querySelector('.pt-table');
    if (!table) { alert('No pending transaction data found.'); return; }

    const search = document.querySelector('input[name="search"]')?.value || '';
    const filename = `Pending_Transactions_${search ? 'Search_' + search : 'All'}`;

    // Temporarily show all rows for complete export
    const rows = Array.from(table.querySelectorAll('tbody tr'));
    const originalDisplays = rows.map(r => r.style.display);
    rows.forEach(r => r.style.display = '');

    if (format === 'excel') {
        if (typeof XLSX === 'undefined') {
            alert('Export library not loaded. Please try again.');
            rows.forEach((r, idx) => r.style.display = originalDisplays[idx]);
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
        XLSX.utils.book_append_sheet(wb, ws, 'Pending Transactions');
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
        doc.write(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>Pending Transactions Report</title>
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
            .pt-badge, .badge{border:none;background:none;padding:0;font-weight:normal;}
            tfoot tr{border-top:2px solid #002F70;background:#f2f2f2 !important;}
            tfoot td{padding:6px 5px;font-weight:700;}
        </style></head><body>
            <div class="header-container">
                <img src="${logo_url}" alt="Petron">
                <div class="header-title">
                    <h1>Petron Station Management System</h1>
                    <p>Pending Transactions Report</p>
                </div>
                <div class="meta-info">
                    Search Filter: ${search || 'None'}<br>
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

    // Restore original row displays
    rows.forEach((r, idx) => r.style.display = originalDisplays[idx]);
}

// Start auto-refresh timer (30 seconds)
refreshPendingTimer = setInterval(autoRefreshPendingTransactions, 30000);

// ══════════════════════════════════════════════════════════════════════════════
// PAGINATION FUNCTIONALITY
// ══════════════════════════════════════════════════════════════════════════════
(function() {
    const table = document.querySelector('.pt-table tbody');
    if (!table) return;
    
    const allRows = Array.from(table.querySelectorAll('tr'));
    if (allRows.length === 1 && allRows[0].querySelector('td[colspan]')) return;
    
    let currentPage = 1;
    let rowsPerPage = 10;
    
    const rowsSelect = document.getElementById('rowsPerPage');
    const pageInfo = document.getElementById('pageInfo');
    const prevBtn = document.getElementById('prevPage');
    const nextBtn = document.getElementById('nextPage');
    
    if (!rowsSelect || !pageInfo || !prevBtn || !nextBtn) return;
    
    function updateTable() {
        const totalPages = Math.ceil(allRows.length / rowsPerPage);
        const start = (currentPage - 1) * rowsPerPage;
        const end = start + rowsPerPage;
        
        allRows.forEach(row => row.style.display = 'none');
        allRows.slice(start, end).forEach(row => row.style.display = '');
        
        pageInfo.textContent = `Page ${currentPage} of ${totalPages || 1}`;
        prevBtn.disabled = currentPage === 1;
        nextBtn.disabled = currentPage === totalPages || totalPages === 0;
        
        prevBtn.style.opacity = prevBtn.disabled ? '0.5' : '1';
        prevBtn.style.cursor = prevBtn.disabled ? 'not-allowed' : 'pointer';
        nextBtn.style.opacity = nextBtn.disabled ? '0.5' : '1';
        nextBtn.style.cursor = nextBtn.disabled ? 'not-allowed' : 'pointer';
    }
    
    rowsSelect.addEventListener('change', function() {
        rowsPerPage = parseInt(this.value);
        currentPage = 1;
        updateTable();
    });
    
    prevBtn.addEventListener('click', function() {
        if (currentPage > 1) {
            currentPage--;
            updateTable();
            document.querySelector('.card').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
    
    nextBtn.addEventListener('click', function() {
        const totalPages = Math.ceil(allRows.length / rowsPerPage);
        if (currentPage < totalPages) {
            currentPage++;
            updateTable();
            document.querySelector('.card').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
    
    document.querySelectorAll('.pt-page-btn').forEach(btn => {
        btn.addEventListener('mouseenter', function() {
            if (!this.disabled) {
                this.style.background = '#f1f5f9';
                this.style.borderColor = '#cbd5e1';
            }
        });
        btn.addEventListener('mouseleave', function() {
            this.style.background = '#fff';
            this.style.borderColor = '#e2e8f0';
        });
    });
    
    updateTable();
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
