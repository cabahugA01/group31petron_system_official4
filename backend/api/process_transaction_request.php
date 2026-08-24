<?php
/**
 * POST /backend/api/process_transaction_request.php
 * Manager approves or rejects a staff transaction request (Void or Adjustment)
 * - If VOID is approved: Reverts all merchandise/job order items back into station_inventory (+IN) & logs to inventory_logs
 * - If ADJUSTMENT is approved: Re-calculates and applies delta quantity stock deduction/reversal & logs to inventory_logs
 */
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
    exit;
}

$me = current_user();
$role = role_key($me['role'] ?? '');
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Manager access required.']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON input.']);
    exit;
}

$request_id     = (int)($data['request_id'] ?? 0);
$action         = strtolower(trim($data['action'] ?? ''));
$review_remarks = trim($data['review_remarks'] ?? '');

if ($request_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing or invalid request ID.']);
    exit;
}

if (!in_array($action, ['approve', 'reject'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid action. Must be approve or reject.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Check if request exists
    $stmt = $pdo->prepare("SELECT * FROM transaction_requests WHERE id = ? FOR UPDATE");
    $stmt->execute([$request_id]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$req) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Transaction request not found.']);
        exit;
    }

    if ($req['status'] !== 'Pending') {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => "This request is already {$req['status']}."]);
        exit;
    }

    $new_status  = ($action === 'approve') ? 'Approved' : 'Rejected';
    $user_id     = (int)($me['id'] ?? 0);
    $station_id  = (int)($req['station_id'] ?: user_station_id());
    $source      = strtolower(trim($req['record_source'] ?? 'merchandise_transactions'));
    $txn_ref     = trim($req['transaction_id'] ?? '');
    $req_type    = ucfirst(strtolower(trim($req['request_type'] ?? '')));

    // ─────────────────────────────────────────────────────────────────────────
    // IF APPROVED: APPLY INVENTORY REVERSAL / ADJUSTMENT
    // ─────────────────────────────────────────────────────────────────────────
    if ($action === 'approve') {
        if ($req_type === 'Void') {
            // ── VOID MERCHANDISE TRANSACTION ──
            if ($source === 'merchandise_transactions' || strpos($txn_ref, 'MERCH') === 0) {
                // Find merchandise transaction
                $m_stmt = $pdo->prepare("SELECT * FROM merchandise_transactions WHERE (transaction_id = ? OR id = ?) AND station_id = ? LIMIT 1");
                $m_stmt->execute([$txn_ref, $txn_ref, $station_id]);
                $mtxn = $m_stmt->fetch(PDO::FETCH_ASSOC);

                if ($mtxn) {
                    $mtxn_id = (int)$mtxn['id'];
                    $items_stmt = $pdo->prepare("SELECT * FROM merchandise_transaction_items WHERE transaction_id = ?");
                    $items_stmt->execute([$mtxn_id]);
                    $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($items as $item) {
                        $product_id = (int)($item['product_id'] ?? 0);
                        $qty = (float)($item['quantity'] ?? 0);

                        if ($product_id <= 0 && !empty($item['product_name'])) {
                            $pst = $pdo->prepare("SELECT id FROM inventory_products WHERE product_name = ? LIMIT 1");
                            $pst->execute([$item['product_name']]);
                            $product_id = (int)$pst->fetchColumn();
                        }

                        if ($product_id > 0 && $qty > 0 && ($item['item_type'] ?? '') !== 'service') {
                            // 1. Revert stock in station_inventory
                            $st_cur = $pdo->prepare("SELECT stock_level FROM station_inventory WHERE station_id = ? AND product_id = ?");
                            $st_cur->execute([$station_id, $product_id]);
                            $qtyBefore = (float)$st_cur->fetchColumn();
                            $qtyAfter  = $qtyBefore + $qty;

                            $pdo->prepare("
                                UPDATE station_inventory
                                SET stock_level = stock_level + ?, last_updated = NOW()
                                WHERE station_id = ? AND product_id = ?
                            ")->execute([$qty, $station_id, $product_id]);

                            // 2. Log void reversal in inventory_logs
                            try {
                                $pdo->prepare("
                                    INSERT INTO inventory_logs (
                                        station_id, product_id, user_id, action, movement_type,
                                        quantity_before, quantity_after, quantity_change,
                                        reference_type, reference_id, reference_no, notes, created_at
                                    ) VALUES (?, ?, ?, 'void_reversal', 'IN', ?, ?, ?, 'merchandise_transaction', NULL, ?, ?, NOW())
                                ")->execute([
                                    $station_id, $product_id, $user_id,
                                    $qtyBefore, $qtyAfter, $qty,
                                    $mtxn['transaction_id'] ?: $txn_ref,
                                    "Void Approval Reversal: " . ($review_remarks ?: $req['request_reason'])
                                ]);
                            } catch (Exception $e) {}
                        }
                    }

                    // 3. Update merchandise_transactions status to Voided
                    $pdo->prepare("
                        UPDATE merchandise_transactions
                        SET validation_status = 'Voided',
                            workflow_status   = 'Voided',
                            void_reason       = ?,
                            manager_remarks   = ?,
                            inventory_deducted= 0,
                            validated_by      = ?,
                            validated_at      = NOW(),
                            updated_at        = NOW()
                        WHERE id = ?
                    ")->execute([$req['request_reason'], $review_remarks ?: 'Approved by Manager', $user_id, $mtxn_id]);
                }
            }

            // ── VOID JOB ORDER ──
            elseif ($source === 'job_orders' || strpos($txn_ref, 'JO') === 0) {
                $j_stmt = $pdo->prepare("SELECT * FROM job_orders WHERE (job_order_number = ? OR id = ?) AND station_id = ? LIMIT 1");
                $j_stmt->execute([$txn_ref, $txn_ref, $station_id]);
                $jo = $j_stmt->fetch(PDO::FETCH_ASSOC);

                if ($jo) {
                    $jo_id = (int)$jo['id'];
                    // Restore parts if required_parts JSON exists
                    if (!empty($jo['required_parts'])) {
                        $parts = json_decode($jo['required_parts'], true);
                        if (is_array($parts)) {
                            foreach ($parts as $part) {
                                $pname = is_array($part) ? ($part['name'] ?? $part['part_name'] ?? '') : (string)$part;
                                $qty   = is_array($part) ? (float)($part['qty'] ?? $part['quantity'] ?? 1) : 1;
                                if ($pname !== '') {
                                    $pst = $pdo->prepare("SELECT id FROM inventory_products WHERE product_name = ? LIMIT 1");
                                    $pst->execute([$pname]);
                                    $pid = (int)$pst->fetchColumn();
                                    if ($pid > 0 && $qty > 0) {
                                        $pdo->prepare("
                                            UPDATE station_inventory
                                            SET stock_level = stock_level + ?, last_updated = NOW()
                                            WHERE station_id = ? AND product_id = ?
                                        ")->execute([$qty, $station_id, $pid]);
                                    }
                                }
                            }
                        }
                    }

                    // Update job order status to Voided
                    $pdo->prepare("
                        UPDATE job_orders
                        SET status = 'Voided', validation_status = 'Voided',
                            void_reason = ?, manager_remarks = ?,
                            validated_by = ?, validated_at = NOW(), updated_at = NOW()
                        WHERE id = ?
                    ")->execute([$req['request_reason'], $review_remarks ?: 'Approved by Manager', $user_id, $jo_id]);
                }
            }
        }

        elseif ($req_type === 'Adjustment') {
            // ── ADJUSTMENT HANDLING ──
            if ($source === 'merchandise_transactions' || strpos($txn_ref, 'MERCH') === 0) {
                $m_stmt = $pdo->prepare("SELECT * FROM merchandise_transactions WHERE (transaction_id = ? OR id = ?) AND station_id = ? LIMIT 1");
                $m_stmt->execute([$txn_ref, $txn_ref, $station_id]);
                $mtxn = $m_stmt->fetch(PDO::FETCH_ASSOC);

                if ($mtxn) {
                    $mtxn_id = (int)$mtxn['id'];
                    $new_amt = (float)($req['new_amount'] ?: $mtxn['total_amount']);

                    // If quantity adjustment was requested
                    if ($req['correction_field'] === 'quantity' && is_numeric($req['requested_value']) && is_numeric($req['current_value'])) {
                        $old_q = (float)$req['current_value'];
                        $new_q = (float)$req['requested_value'];
                        $delta = $old_q - $new_q; // positive = return to stock (+IN), negative = deduct more (-OUT)

                        // Update first item
                        $first_item = $pdo->prepare("SELECT * FROM merchandise_transaction_items WHERE transaction_id = ? LIMIT 1");
                        $first_item->execute([$mtxn_id]);
                        $f_item = $first_item->fetch(PDO::FETCH_ASSOC);

                        if ($f_item) {
                            $pid = (int)($f_item['product_id'] ?? 0);
                            $pdo->prepare("UPDATE merchandise_transaction_items SET quantity = ?, subtotal = ? WHERE id = ?")
                                ->execute([$new_q, round($new_q * (float)$f_item['unit_price'], 2), $f_item['id']]);

                            if ($pid > 0 && abs($delta) > 0.0001) {
                                $st_cur = $pdo->prepare("SELECT stock_level FROM station_inventory WHERE station_id = ? AND product_id = ?");
                                $st_cur->execute([$station_id, $pid]);
                                $qtyBefore = (float)$st_cur->fetchColumn();
                                $qtyAfter  = $qtyBefore + $delta;

                                $pdo->prepare("
                                    UPDATE station_inventory
                                    SET stock_level = stock_level + ?, last_updated = NOW()
                                    WHERE station_id = ? AND product_id = ?
                                ")->execute([$delta, $station_id, $pid]);

                                try {
                                    $mov_type = $delta >= 0 ? 'IN' : 'OUT';
                                    $pdo->prepare("
                                        INSERT INTO inventory_logs (
                                            station_id, product_id, user_id, action, movement_type,
                                            quantity_before, quantity_after, quantity_change,
                                            reference_type, reference_id, reference_no, notes, created_at
                                        ) VALUES (?, ?, ?, 'adjustment', ?, ?, ?, ?, 'merchandise_transaction', NULL, ?, ?, NOW())
                                    ")->execute([
                                        $station_id, $pid, $user_id,
                                        $mov_type, $qtyBefore, $qtyAfter, $delta,
                                        $mtxn['transaction_id'] ?: $txn_ref,
                                        "Adjustment Approval: " . ($review_remarks ?: $req['request_reason'])
                                    ]);
                                } catch (Exception $e) {}
                            }
                        }
                    }

                    $pdo->prepare("
                        UPDATE merchandise_transactions
                        SET total_amount = ?, validation_status = 'Adjusted',
                            adjustment_reason = ?, manager_remarks = ?,
                            validated_by = ?, validated_at = NOW(), updated_at = NOW()
                        WHERE id = ?
                    ")->execute([$new_amt, $req['request_reason'], $review_remarks ?: 'Adjusted by Manager', $user_id, $mtxn_id]);
                }
            }

            elseif ($source === 'job_orders' || strpos($txn_ref, 'JO') === 0) {
                $j_stmt = $pdo->prepare("SELECT * FROM job_orders WHERE (job_order_number = ? OR id = ?) AND station_id = ? LIMIT 1");
                $j_stmt->execute([$txn_ref, $txn_ref, $station_id]);
                $jo = $j_stmt->fetch(PDO::FETCH_ASSOC);

                if ($jo) {
                    $jo_id = (int)$jo['id'];
                    $new_amt = (float)($req['new_amount'] ?: ($jo['total_cost'] ?: $jo['estimated_cost']));

                    $pdo->prepare("
                        UPDATE job_orders
                        SET total_cost = ?, estimated_cost = ?, validation_status = 'Adjusted',
                            adjustment_reason = ?, manager_remarks = ?,
                            validated_by = ?, validated_at = NOW(), updated_at = NOW()
                        WHERE id = ?
                    ")->execute([$new_amt, $new_amt, $req['request_reason'], $review_remarks ?: 'Adjusted by Manager', $user_id, $jo_id]);
                }
            }
        }
    }

    // Update the request status in transaction_requests
    $upd = $pdo->prepare("
        UPDATE transaction_requests
        SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_remarks = ?
        WHERE id = ?
    ");
    $upd->execute([$new_status, $user_id, $review_remarks, $request_id]);

    // Notify staff
    if (!empty($req['requested_by'])) {
        $msg = "Your {$req['request_type']} request for Transaction #{$req['transaction_id']} was {$new_status} by Manager.";
        if (!empty($review_remarks)) {
            $msg .= " Remarks: {$review_remarks}";
        }
        $ref_t = ($req['request_type'] === 'Void') ? 'void_request' : 'transaction_adjustment';
        $sev   = ($action === 'approve') ? 'medium' : 'high';
        $ntype = ($action === 'approve') ? 'success' : 'error';
        $url   = 'staff_transactions_hub.php?section=merchandise&active_tab=history';

        notify(
            $pdo, (int)$req['requested_by'], 'staff', $ntype, $ref_t, $sev,
            "{$req['request_type']} Request {$new_status}",
            $msg,
            "txn_req_result_{$request_id}",
            $url,
            $ref_t, (int)$request_id
        );
    }

    // Log activity
    log_activity($pdo, $user_id, "Transaction Request {$new_status}", "Req#{$request_id}|{$req['request_type']}|TXN#{$req['transaction_id']}");

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "Request has been successfully {$new_status} and merchandise inventory updated accordingly."
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}