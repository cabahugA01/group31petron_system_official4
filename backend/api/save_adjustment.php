<?php
/**
 * POST /backend/api/save_adjustment.php
 * Manager adjusts an existing merchandise_transaction:
 *   - Updates per-item quantity + unit_price in merchandise_transaction_items
 *   - Recalculates total_amount on merchandise_transactions
 *   - Adjusts station_inventory delta (restores old qty, deducts new qty)
 *   - Sets validation_status = 'Adjusted', records adjustment_reason + manager_remarks
 *   - Writes to audit_trail
 *
 * Expected JSON body:
 * {
 *   "row_id"           : 42,                // merchandise_transactions.id
 *   "payment_method"   : "Cash",
 *   "payment_status"   : "Paid",
 *   "adjustment_reason": "Wrong quantity entered",
 *   "manager_remarks"  : "Corrected from 2 to 1",
 *   "items": [
 *     { "item_id": 7, "quantity": 1, "unit_price": 350.00 },
 *     ...
 *   ]
 * }
 */

header('Content-Type: application/json');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';
require_once __DIR__ . '/../transaction_schema_fix.php';

// Auth
if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit;
}
$me         = current_user();
$station_id = (int) user_station_id();
$role       = role_key($me['role'] ?? '');
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Manager access required']); exit;
}

// Parse input
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data || !isset($data['row_id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']); exit;
}

$row_id            = (int)$data['row_id'];
$payment_method    = trim($data['payment_method']    ?? '');
$payment_status    = trim($data['payment_status']    ?? '');
$adjustment_reason = trim($data['adjustment_reason'] ?? '');
$manager_remarks   = trim($data['manager_remarks']   ?? '');
$items_input       = $data['items'] ?? [];

if (!$adjustment_reason) {
    echo json_encode(['success' => false, 'error' => 'Adjustment reason is required']); exit;
}
if (!$manager_remarks) {
    echo json_encode(['success' => false, 'error' => 'Manager remarks are required']); exit;
}

try {
    // ── Ensure needed columns/tables exist ─────────────────────────────────────
    foreach ([
        "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS adjustment_reason TEXT DEFAULT NULL",
        "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS manager_remarks   TEXT DEFAULT NULL",
    ] as $ddl) {
        try { $pdo->exec($ddl); } catch (Exception $e) {}
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS transaction_adjustments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                transaction_id VARCHAR(50) NOT NULL,
                transaction_type ENUM('job_order','merchandise','combined') NOT NULL DEFAULT 'merchandise',
                customer_name VARCHAR(255) DEFAULT NULL,
                original_amount DECIMAL(10,2) NOT NULL,
                updated_amount DECIMAL(10,2) NOT NULL,
                amount_difference DECIMAL(10,2) NOT NULL,
                adjustment_reason VARCHAR(255) NOT NULL,
                manager_remarks TEXT,
                adjusted_by INT NOT NULL,
                adjustment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                station_id INT NOT NULL,
                fields_changed JSON,
                INDEX idx_adj_txn (transaction_id),
                INDEX idx_adj_date (adjustment_date),
                INDEX idx_adj_station (station_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {}

    // ── Load the transaction ──────────────────────────────────────────────────
    $stmt = $pdo->prepare("SELECT * FROM merchandise_transactions WHERE id = ? AND station_id = ? LIMIT 1");
    $stmt->execute([$row_id, $station_id]);
    $txn = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$txn) {
        echo json_encode(['success' => false, 'error' => 'Transaction not found']); exit;
    }

    // Can't adjust a Voided transaction
    $cur_status = strtolower(trim($txn['validation_status'] ?? ''));
    if ($cur_status === 'voided') {
        echo json_encode(['success' => false, 'error' => 'Cannot adjust a voided transaction']); exit;
    }

    $pdo->beginTransaction();

    $old_total = (float)$txn['total_amount'];
    $new_total = 0.0;

    $items_detail = [];
    // ── Process each item ─────────────────────────────────────────────────────
    foreach ($items_input as $item) {
        $item_id    = (int)($item['item_id']    ?? 0);
        $new_qty    = max(0, (float)($item['quantity']   ?? 0));
        $new_price  = max(0, (float)($item['unit_price'] ?? 0));
        $new_sub    = round($new_qty * $new_price, 2);

        if ($item_id <= 0) continue;

        // Fetch existing item
        $si = $pdo->prepare("SELECT * FROM merchandise_transaction_items WHERE id = ? AND transaction_id = ? LIMIT 1");
        $si->execute([$item_id, $row_id]);
        $old_item = $si->fetch(PDO::FETCH_ASSOC);
        if (!$old_item) continue;

        $old_qty    = (float)$old_item['quantity'];
        $old_price  = (float)$old_item['unit_price'];
        $product_id = (int)($old_item['product_id'] ?? 0);

        // Track only if there's a difference in quantity or price
        if (abs($old_qty - $new_qty) > 0.0001 || abs($old_price - $new_price) > 0.0001) {
            $items_detail[] = [
                'product_name' => $old_item['product_name'],
                'item_type'    => $old_item['item_type'] ?? 'merchandise',
                'old_qty'      => $old_qty,
                'new_qty'      => $new_qty,
                'old_price'    => $old_price,
                'new_price'    => $new_price,
            ];
        }

        // Update item row
        $pdo->prepare("UPDATE merchandise_transaction_items SET quantity=?, unit_price=?, subtotal=? WHERE id=?")
            ->execute([$new_qty, $new_price, $new_sub, $item_id]);

        // ── Inventory delta (only for merchandise items) ───────────────────────
        if ($product_id > 0 && $old_item['item_type'] !== 'service') {
            $qty_diff = $old_qty - $new_qty; // positive = restore, negative = deduct more
            if (abs($qty_diff) > 0.0001) {
                $pdo->prepare("
                    UPDATE station_inventory
                    SET stock_level = stock_level + ?
                    WHERE product_id = ? AND station_id = ?
                ")->execute([$qty_diff, $product_id, $station_id]);
            }
        }

        $new_total += $new_sub;
    }

    // Round total
    $new_total = round($new_total, 2);
    $new_subtotal = round($new_total / 1.12, 2);
    $new_vat = round($new_total - $new_subtotal, 2);

    // ── Update transaction header ─────────────────────────────────────────────
    $update_sql = "
        UPDATE merchandise_transactions SET
            total_amount       = ?,
            subtotal_amount    = ?,
            vat_amount         = ?,
            payment_method     = ?,
            payment_status     = ?,
            validation_status  = 'Adjusted',
            adjustment_reason  = ?,
            manager_remarks    = ?,
            validated_by       = ?,
            validated_at       = NOW(),
            updated_at         = NOW()
        WHERE id = ? AND station_id = ?
    ";
    $pdo->prepare($update_sql)->execute([
        $new_total,
        $new_subtotal,
        $new_vat,
        $payment_method ?: $txn['payment_method'],
        $payment_status ?: ($txn['payment_status'] ?? 'Paid'),
        $adjustment_reason,
        $manager_remarks,
        $me['id'],
        $row_id,
        $station_id,
    ]);

    // ── Audit trail ───────────────────────────────────────────────────────────
    $old_snap = json_encode([
        'total_amount'     => $old_total,
        'validation_status'=> $txn['validation_status'] ?? '',
        'payment_method'   => $txn['payment_method']   ?? '',
        'payment_status'   => $txn['payment_status']   ?? '',
    ]);
    $new_snap = json_encode([
        'total_amount'     => $new_total,
        'validation_status'=> 'Adjusted',
        'payment_method'   => $payment_method ?: $txn['payment_method'],
        'payment_status'   => $payment_status,
        'adjustment_reason'=> $adjustment_reason,
        'manager_remarks'  => $manager_remarks,
    ]);

    $pdo->prepare("
        INSERT INTO audit_trail (transaction_id, manager_id, action_type, old_value, new_value, station_id, source_table)
        VALUES (?, ?, 'Adjustment', ?, ?, ?, 'merchandise_transactions')
    ")->execute([
        $txn['transaction_id'] ?? $row_id,
        $me['id'],
        $old_snap,
        $new_snap,
        $station_id,
    ]);

    // ── Log into transaction_adjustments (populates Adjustment History page) ──
    try {
        $pdo->prepare("
            INSERT INTO transaction_adjustments (
                transaction_id, transaction_type, customer_name,
                original_amount, updated_amount, amount_difference,
                adjustment_reason, manager_remarks, adjusted_by,
                adjustment_date, station_id, fields_changed
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)
        ")->execute([
            $txn['transaction_id'] ?? ('TXN-' . $row_id),
            $txn['transaction_type'] ?? 'merchandise',
            $txn['customer_name']   ?? 'Walk-in Customer',
            $old_total,
            $new_total,
            round($new_total - $old_total, 2),
            $adjustment_reason,
            $manager_remarks,
            $me['id'],
            $station_id,
            json_encode([
                'items_updated'   => count($items_input),
                'payment_method'  => $payment_method ?: ($txn['payment_method'] ?? ''),
                'payment_status'  => $payment_status ?: ($txn['payment_status'] ?? ''),
                'old_payment_method' => $txn['payment_method'] ?? '',
                'old_payment_status' => $txn['payment_status'] ?? '',
                'adjusted_items'  => $items_detail
            ]),
        ]);
    } catch (Exception $e) {
        error_log('transaction_adjustments insert warning: ' . $e->getMessage());
    }

    $pdo->commit();

    echo json_encode([
        'success'   => true,
        'message'   => 'Transaction adjusted successfully.',
        'new_total' => $new_total,
        'old_total' => $old_total,
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('save_adjustment error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
