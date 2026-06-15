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

// ── POST: Manager actions (Approve, Reject, Adjust) ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';

    $insert_audit = function(int $txn_id, string $action_type, ?string $new_val = null) use ($pdo, $me, $station_id) {
        try {
            $pdo->prepare("INSERT INTO audit_trail (transaction_id, manager_id, action_type, new_value, station_id) VALUES (?, ?, ?, ?, ?)")
                ->execute([$txn_id, $me['id'], $action_type, $new_val, $station_id]);
        } catch (Exception $ae) {}
    };

    // ── Approve Group (same customer + same date) ─────────────────────────────
    if ($post_action === 'approve_group') {
        $group_ids = json_decode($_POST['group_ids'] ?? '[]', true);
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
                        foreach ($itemRows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                            if (($row['item_type'] ?? 'merchandise') !== 'service' && $row['product_id'] && $row['quantity'] > 0) {
                                $pdo->prepare("
                                    UPDATE station_inventory
                                    SET stock_level = GREATEST(stock_level - ?, 0),
                                        last_updated = NOW()
                                    WHERE station_id = ? AND product_id = ?
                                ")->execute([$row['quantity'], $station_id, $row['product_id']]);
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
                    if (pt_has($mt_cols, 'updated_at'))   { $sp[] = "updated_at = NOW()"; }
                    $pdo->prepare("UPDATE merchandise_transactions SET " . implode(', ', $sp) . " WHERE id = ? AND station_id = ?")
                        ->execute(array_merge($sv, [$rid, $station_id]));
                } else {
                    $pdo->prepare("UPDATE job_orders SET validation_status='Approved', status='Pending', validated_by=?, validated_at=NOW() WHERE id=? AND station_id=?")
                        ->execute([$me['id'], $rid, $station_id]);
                }
                $insert_audit($rid, 'Approve', 'Group Approved');
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
                    if (pt_has($mt_cols, 'updated_at')) { $sp[] = "updated_at = NOW()"; }
                    $pdo->prepare("UPDATE merchandise_transactions SET " . implode(', ', $sp) . " WHERE id = ? AND station_id = ?")
                        ->execute(array_merge($sv, [$rid, $station_id]));
                } else {
                    $pdo->prepare("UPDATE job_orders SET validation_status='Rejected', status='Cancelled', validated_by=?, validated_at=NOW() WHERE id=? AND station_id=?")
                        ->execute([$me['id'], $rid, $station_id]);
                }
                $insert_audit($rid, 'Reject', "Group Rejected: {$reason}");
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
                        if (pt_has($mt_cols, 'updated_at'))   { $sp[] = "updated_at=NOW()"; }
                        $pdo->prepare("UPDATE merchandise_transactions SET " . implode(', ', $sp) . " WHERE id=? AND station_id=?")
                            ->execute(array_merge($sv, [$txn_id, $station_id]));
                    } else {
                        $finalTotal = ($new_val !== '' && is_numeric($new_val))
                            ? (float)$new_val
                            : array_sum(array_map(fn($a) => (float)($a['quantity'] ?? 1) * (float)($a['unit_price'] ?? 0), $adjs));
                        $sp = ["validation_status='Adjusted'", "validated_by=?", "validated_at=NOW()"];
                        $sv = [$me['id']];
                        if (pt_has($jo_cols, 'total_cost')) { $sp[] = "total_cost=?"; $sv[] = $finalTotal; }
                        $pdo->prepare("UPDATE job_orders SET " . implode(', ', $sp) . " WHERE id=? AND station_id=?")
                            ->execute(array_merge($sv, [$txn_id, $station_id]));
                    }
                    $insert_audit($txn_id, 'Adjust', "Item-level adjusted: {$reason}");
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
                        if (pt_has($mt_cols, 'updated_at'))   { $sp[] = "updated_at=NOW()"; }
                        if ($new_val !== '' && is_numeric($new_val) && pt_has($mt_cols, 'total_amount')) { $sp[] = "total_amount=?"; $sv[] = (float)$new_val; }
                        $pdo->prepare("UPDATE merchandise_transactions SET " . implode(', ', $sp) . " WHERE id=? AND station_id=?")
                            ->execute(array_merge($sv, [$rid, $station_id]));
                    } else {
                        $sp = ["validation_status='Adjusted'", "validated_by=?", "validated_at=NOW()"];
                        $sv = [$me['id']];
                        if ($new_val !== '' && is_numeric($new_val) && pt_has($jo_cols, 'total_cost')) { $sp[] = "total_cost=?"; $sv[] = (float)$new_val; }
                        $pdo->prepare("UPDATE job_orders SET " . implode(', ', $sp) . " WHERE id=? AND station_id=?")
                            ->execute(array_merge($sv, [$rid, $station_id]));
                    }
                    $insert_audit($rid, 'Adjust', "Price adjusted: {$reason}");
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
$mt_status_col = pt_has($mt_cols, 'validation_status') ? 'mt.validation_status' : "'Pending'";
$mt_date_col   = "CASE WHEN mt.created_at > '2000-01-01' THEN mt.created_at ELSE mt.created_at END";
$mt_paid_col   = pt_has($mt_cols, 'amount_paid') ? 'mt.amount_paid' : 'NULL';

$mt_where = "WHERE mt.station_id = ? AND LOWER(TRIM(COALESCE(mt.validation_status,''))) = 'pending'";
$mt_params = [$station_id];
if ($search !== '') {
    $mt_where .= " AND (mt.customer_name LIKE ? OR mt.transaction_id LIKE ?)";
    $mt_params[] = "%$search%"; $mt_params[] = "%$search%";
}

$mt_rows = [];
try {
    // Detect if job_order_service column exists
    $mt_jo_svc_col = pt_has($mt_cols, 'job_order_service') ? "COALESCE(mt.job_order_service,'')" : "''";
    $mt_jo_veh_type_col = pt_has($mt_cols, 'job_order_vehicle_type') ? "COALESCE(mt.job_order_vehicle_type,'')" : "''";
    $mt_jo_veh_plate_col = pt_has($mt_cols, 'job_order_vehicle_plate') ? "COALESCE(mt.job_order_vehicle_plate,'')" : "''";

    $stmt = $pdo->prepare("
        SELECT
            mt.id AS row_id,
            mt.transaction_id AS txn_id,
            COALESCE(NULLIF(TRIM(mt.customer_name),''),'Walk-in') AS customer,
            CASE mt.transaction_type
                WHEN 'combined'   THEN 'JO + Merchandise'
                WHEN 'job_order'  THEN 'Job Order'
                ELSE                   'Merchandise'
            END AS entry_type,
            COALESCE(
                NULLIF((SELECT GROUP_CONCAT(
                            CONCAT(i.product_name, ' - ', i.quantity, ' pcs @ ₱', FORMAT(i.unit_price, 2))
                            ORDER BY i.id SEPARATOR ' | ')
                        FROM merchandise_transaction_items i 
                        WHERE i.transaction_id = mt.id 
                        AND COALESCE(i.item_type, 'merchandise') = 'merchandise'),''),
                mt.item_sku, 
                CASE WHEN TRIM(COALESCE({$mt_jo_svc_col},'')) <> '' THEN '—' ELSE 'N/A' END
            ) AS items_parts,
            COALESCE(
                NULLIF((SELECT GROUP_CONCAT(
                            CONCAT(i.product_name, ' - ', i.quantity, ' pcs @ ₱', FORMAT(i.unit_price, 2))
                            ORDER BY i.id SEPARATOR ' | ')
                        FROM merchandise_transaction_items i 
                        WHERE i.transaction_id = mt.id 
                        AND i.item_type = 'service'),''),
                NULLIF({$mt_jo_svc_col},''),
                CASE WHEN mt.item_sku IS NOT NULL AND mt.item_sku <> '' THEN '—' ELSE 'N/A' END
            ) AS service_type,
            NULLIF(TRIM(CONCAT(
                COALESCE({$mt_jo_veh_plate_col},''),
                CASE WHEN TRIM(COALESCE({$mt_jo_veh_type_col},'')) <> ''
                     THEN CONCAT(' · ', {$mt_jo_veh_type_col}) ELSE '' END
            )),'') AS vehicle_info,
            mt.total_amount AS amount,
            {$mt_paid_col} AS amount_paid,
            COALESCE(mt.payment_method,'Cash') AS payment_method,
            {$mt_date_col} AS txn_date,
            COALESCE({$mt_status_col},'Pending') AS validation_status,
            COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown') AS staff_name,
            'merchandise_transactions' AS _source,
            COALESCE(mt.transaction_type,'merchandise') AS txn_type
        FROM merchandise_transactions mt
        LEFT JOIN users u ON u.id = mt.staff_id
        {$mt_where}
        GROUP BY mt.id
        ORDER BY txn_date DESC
        LIMIT 100
    ");
    $stmt->execute($mt_params);
    $mt_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $mt_rows = []; }

// Job Orders PENDING VALIDATION
$jo_status_col = pt_has($jo_cols, 'validation_status') ? 'jo.validation_status' : 'jo.status';
$jo_pay_col    = pt_has($jo_cols, 'payment_method') ? 'COALESCE(jo.payment_method,\'N/A\')' : "'N/A'";
$jo_cost_col   = pt_has($jo_cols, 'total_cost') ? 'COALESCE(jo.total_cost,0)' : 'COALESCE(jo.estimated_cost,0)';
$jo_paid_col   = pt_has($jo_cols, 'amount_paid') ? 'jo.amount_paid' : 'NULL';

$jo_where = "WHERE jo.station_id = ? AND LOWER(TRIM(COALESCE({$jo_status_col},''))) = 'pending validation'";
$jo_params = [$station_id];
if ($search !== '') {
    $jo_where .= " AND (jo.customer_name LIKE ? OR jo.service_type LIKE ? OR jo.vehicle_plate LIKE ?)";
    $jo_params[] = "%$search%"; $jo_params[] = "%$search%"; $jo_params[] = "%$search%";
}

$jo_rows = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            jo.id AS row_id,
            CONCAT('JO-', jo.id) AS txn_id,
            COALESCE(NULLIF(TRIM(jo.customer_name),''),'Walk-in') AS customer,
            'Job Order' AS entry_type,
            '—' AS items_parts,
            CONCAT(COALESCE(jo.service_type,'Service'), 
                   CASE WHEN jo.vehicle_plate IS NOT NULL AND jo.vehicle_plate != '' 
                        THEN CONCAT(' | ', jo.vehicle_plate) ELSE '' END) AS service_type,
            NULLIF(TRIM(CONCAT(
                COALESCE(jo.vehicle_plate,''),
                CASE WHEN jo.vehicle_type IS NOT NULL AND jo.vehicle_type != ''
                     THEN CONCAT(' · ', jo.vehicle_type) ELSE '' END
            )),'') AS vehicle_info,
            {$jo_cost_col} AS amount,
            {$jo_paid_col} AS amount_paid,
            {$jo_pay_col} AS payment_method,
            jo.created_at AS txn_date,
            COALESCE(NULLIF(TRIM({$jo_status_col}),''),'Pending') AS validation_status,
            COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown') AS staff_name,
            'job_orders' AS _source,
            'job_order' AS txn_type
        FROM job_orders jo
        LEFT JOIN users u ON u.id = COALESCE(jo.created_by, jo.created_by)
        {$jo_where}
        ORDER BY jo.created_at DESC
        LIMIT 100
    ");
    $stmt->execute($jo_params);
    $jo_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $jo_rows = []; }

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
            'date'          => $date_key,
            'types'         => [],
            'items_parts'   => [],
            'service_types' => [],
            'vehicle_info'  => $r['vehicle_info'] ?? '',
            'total'         => 0.0,
            'pay_methods'   => [],
            'staff'         => $r['staff_name'] ?? 'Unknown',
            'ids'           => [],
            'txn_ids'       => [],
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
    $groups[$gkey]['total'] += (float)($r['amount'] ?? 0);
    $pay = trim($r['payment_method'] ?? '');
    if ($pay && !in_array($pay, $groups[$gkey]['pay_methods'])) {
        $groups[$gkey]['pay_methods'][] = $pay;
    }
    $groups[$gkey]['txn_ids'][] = $r['txn_id'];
    $groups[$gkey]['ids'][] = ['id' => (int)$r['row_id'], 'source' => $r['_source']];
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
        <button type="button" onclick="exportPending('excel')" title="Export to Excel"
                style="background:white;color:#1d6f42;height:38px;padding:9px 20px;border-radius:8px;border:1px solid #1d6f42;font-size:14px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s;"
                onmouseover="this.style.background='#1d6f42';this.style.color='#fff'"
                onmouseout="this.style.background='white';this.style.color='#1d6f42'">
            <i class="fas fa-file-excel"></i> Excel
        </button>
        <button type="button" onclick="exportPending('csv')" title="Export to CSV"
                style="background:white;color:#003d7a;height:38px;padding:9px 20px;border-radius:8px;border:1px solid #003d7a;font-size:14px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s;"
                onmouseover="this.style.background='#003d7a';this.style.color='#fff'"
                onmouseout="this.style.background='white';this.style.color='#003d7a'">
            <i class="fas fa-file-csv"></i> CSV
        </button>
        <button type="button" onclick="exportPending('pdf')" title="Export to PDF"
                style="background:white;color:#dc2626;height:38px;padding:9px 20px;border-radius:8px;border:1px solid #dc2626;font-size:14px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s;"
                onmouseover="this.style.background='#dc2626';this.style.color='#fff'"
                onmouseout="this.style.background='white';this.style.color='#dc2626'">
            <i class="fas fa-file-pdf"></i> PDF
        </button>
        <a href="<?= in_array($role, ['admin', 'superadmin']) ? 'admin_dashboard.php' : 'manager_dashboard.php'; ?>"
           style="background:white;color:#4b5563;text-decoration:none;height:38px;padding:9px 20px;border-radius:8px;border:1px solid #6b7280;font-size:14px;font-weight:600;display:inline-flex;align-items:center;gap:6px;transition:all .15s;"
           onmouseover="this.style.background='#6b7280';this.style.color='#fff'"
           onmouseout="this.style.background='white';this.style.color='#4b5563'">
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
<div class="card" style="padding:0;overflow-x:auto;">
    <table class="pt-table" style="table-layout:auto;width:100%;">
        <colgroup>
            <col style="width:9%;"><!-- Txn ID(s) -->
            <col style="width:10%;"><!-- Customer -->
            <col style="width:8%;"><!-- Type -->
            <col style="width:10%;"><!-- Vehicle -->
            <col style="width:15%;"><!-- Items / Parts -->
            <col style="width:12%;"><!-- Service Type -->
            <col style="width:8%;"><!-- Amount -->
            <col style="width:8%;"><!-- Method -->
            <col style="width:7%;"><!-- Status -->
            <col style="width:7%;"><!-- Date -->
            <col style="width:7%;"><!-- Staff -->
            <col style="width:9%;"><!-- Actions -->
        </colgroup>
        <thead>
            <tr>
                <th style="font-size:12px;">Txn ID(s)</th>
                <th style="font-size:12px;">Customer</th>
                <th style="font-size:12px;">Type</th>
                <th style="font-size:12px;">Vehicle</th>
                <th style="font-size:12px;">Items / Parts</th>
                <th style="font-size:12px;">Service Type</th>
                <th style="text-align:right;font-size:12px;">Total Amount</th>
                <th style="font-size:12px;">Method</th>
                <th style="font-size:12px;">Status</th>
                <th style="font-size:12px;">Date</th>
                <th style="font-size:12px;">Staff</th>
                <th style="text-align:center;font-size:12px;">Actions</th>
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
                    
                    $has_jo       = in_array('Job Order', $g['types']) || in_array('JO + Merchandise', $g['types']);
                    $has_merch    = in_array('Merchandise', $g['types']) || in_array('JO + Merchandise', $g['types']);
                    
                    if ($has_jo && $has_merch)   $badge_class = 'pt-badge-type-combined';
                    elseif ($has_jo)              $badge_class = 'pt-badge-type-jo';
                    else                          $badge_class = 'pt-badge-type';
                    
                    $type_label   = implode(' + ', $unique_types);
                    $items_display = !empty($unique_items_parts) ? implode(' | ', $unique_items_parts) : 'N/A';
                    $service_display = !empty($unique_services) ? implode(' | ', $unique_services) : 'N/A';
                    $vehicle_display = !empty($g['vehicle_info']) ? $g['vehicle_info'] : '—';
                ?>
                <tr>
                    <td style="font-weight:600;font-size:11px;font-family:monospace;color:#64748b;">
                        <?php echo htmlspecialchars(implode(', ', $g['txn_ids'])); ?>
                    </td>
                    <td style="font-size:12px;font-weight:600;" title="<?php echo htmlspecialchars($g['customer']); ?>">
                        <?php echo htmlspecialchars($g['customer']); ?>
                    </td>
                    <td>
                        <span class="pt-badge <?= $badge_class ?>" style="font-size:10px;">
                            <?php echo htmlspecialchars($type_label); ?>
                        </span>
                    </td>
                    <td style="font-size:11px;line-height:1.4;color:#475569;"
                        title="<?php echo htmlspecialchars($vehicle_display); ?>">
                        <?php echo htmlspecialchars($vehicle_display); ?>
                    </td>
                    <td style="font-size:11px;line-height:1.4;" title="<?php echo htmlspecialchars($items_display); ?>">
                        <?php echo htmlspecialchars($items_display); ?>
                    </td>
                    <td style="font-size:11px;line-height:1.4;" title="<?php echo htmlspecialchars($service_display); ?>">
                        <?php echo htmlspecialchars($service_display); ?>
                    </td>
                    <td style="font-weight:700;color:#002F70;text-align:right;white-space:nowrap;font-size:13px;">
                        &#8369;<?php echo number_format($g['total'], 2); ?>
                    </td>
                    <td style="font-size:12px;"><?php echo htmlspecialchars($pay_str); ?></td>
                    <td>
                        <span class="pt-badge pt-badge-unpaid" style="background:#fef3c7;color:#92400e;border-color:#fde047;font-size:10px;">
                            Pending
                        </span>
                    </td>
                    <td style="white-space:nowrap;font-size:11px;color:#64748b;">
                        <?php echo date('M d, Y', strtotime($g['date'])); ?>
                    </td>
                    <td style="font-size:11px;color:#64748b;"><?php echo htmlspecialchars($g['staff']); ?></td>
                    <td style="text-align:center;padding:8px 6px;">
                        <div style="display:flex;flex-direction:column;gap:4px;align-items:center;">
                            <button class="pt-btn-action-full pt-btn-approve" onclick="approveGroup('<?= $ids_json ?>')" style="font-size:11px;padding:6px 10px;">
                                <i class="fas fa-check-circle"></i> Approve
                            </button>
                            <button class="pt-btn-action-full pt-btn-reject" onclick="rejectGroup('<?= $ids_json ?>')" style="font-size:11px;padding:6px 10px;">
                                <i class="fas fa-times-circle"></i> Reject
                            </button>
                            <button class="pt-btn-action-full pt-btn-adjust" onclick="adjustGroup('<?= $ids_json ?>')" style="font-size:11px;padding:6px 10px;">
                                <i class="fas fa-edit"></i> Adjust
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="10" style="text-align:center;padding:60px 20px;color:#94a3b8;">
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
        <label style="font-size:12px;color:#64748b;font-weight:600;">Rows per page:</label>
        <select id="rowsPerPage" style="padding:6px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;cursor:pointer;">
            <option value="10" selected>10</option>
            <option value="20">20</option>
            <option value="30">30</option>
            <option value="40">40</option>
            <option value="50">50</option>
        </select>
    </div>
    <div style="display:flex;align-items:center;gap:10px;">
        <span id="pageInfo" style="font-size:12px;color:#64748b;font-weight:600;">Page 1 of 1</span>
        <div style="display:flex;gap:4px;">
            <button id="prevPage" class="pt-page-btn" style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:6px;background:#fff;color:#64748b;font-size:12px;cursor:pointer;transition:all .15s;" disabled>
                <i class="fas fa-chevron-left"></i> Prev
            </button>
            <button id="nextPage" class="pt-page-btn" style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:6px;background:#fff;color:#64748b;font-size:12px;cursor:pointer;transition:all .15s;">
                Next <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

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
                    <label style="font-size:12px;color:#64748b;min-width:120px;">New Total Amount</label>
                    <div style="position:relative;flex:1;min-width:180px;">
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
.pt-table { width:100%;border-collapse:collapse;font-size:13px; }
.pt-table thead th { 
    background:#002F70;color:#fff;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:12px 10px;border-bottom:2px solid #001a3d;text-align:left;
}
.pt-table tbody td { padding:10px;border-bottom:1px solid #f1f5f9;vertical-align:middle;background:#fff; }
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
    width:auto;
    min-width:100px;
    height:36px;
    border-radius:8px;
    border:1px solid transparent;
    cursor:pointer;
    font-size:13px;
    font-weight:600;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    transition:all .15s;
    padding:0 12px;
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
function approveGroup(idsJson) {
    if (confirm('Approve all transactions in this group?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'approve_group';
        form.appendChild(actionInput);
        
        const idsInput = document.createElement('input');
        idsInput.type = 'hidden';
        idsInput.name = 'group_ids';
        idsInput.value = idsJson;
        form.appendChild(idsInput);
        
        document.body.appendChild(form);
        form.submit();
    }
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
    isModalOpen = rejectModal.classList.contains('active') || adjustModal.classList.contains('active');
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
