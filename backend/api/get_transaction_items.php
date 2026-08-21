<?php
/**
 * GET /backend/api/get_transaction_items.php?id=<merchandise_transaction_id>
 * Returns itemized list for a merchandise transaction (manager view details).
 */
header('Content-Type: application/json');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$me         = current_user();
$station_id = user_station_id();
$role       = role_key($me['role'] ?? '');

if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

$id     = (int)($_GET['id'] ?? 0);
$source = trim($_GET['source'] ?? '');

if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid transaction ID']);
    exit;
}

try {
    $txn = null;
    $items = [];
    $is_jo = ($source === 'job_orders');

    // ── If job_orders or explicitly requested as job_orders ─────────
    if ($is_jo) {
        $stmt = $pdo->prepare("
            SELECT jo.id, jo.job_order_number AS transaction_id, jo.customer_name, jo.payment_method,
                   COALESCE(jo.total_cost, jo.estimated_cost, 0) AS total_amount,
                   jo.amount_paid AS amount_tendered, jo.sukli AS change_amount,
                   jo.validation_status, jo.status, jo.adjustment_reason AS rejection_reason,
                   jo.created_at, jo.validated_at,
                   COALESCE(NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), u.username, 'Staff') AS staff_name,
                   COALESCE(NULLIF(TRIM(CONCAT(vm.first_name, ' ', vm.last_name)), ''), vm.username, 'N/A') AS validated_by_name,
                   jo.service_type, jo.service_description, jo.required_parts, jo.vehicle_plate, jo.vehicle_type
            FROM job_orders jo
            LEFT JOIN users u  ON COALESCE(jo.created_by, jo.user_id) = u.id
            LEFT JOIN users vm ON jo.validated_by = vm.id
            WHERE jo.id = ? AND jo.station_id = ?
            LIMIT 1
        ");
        $stmt->execute([$id, $station_id, $station_id]);
        $txn = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($txn) {
            $total = (float)$txn['total_amount'];
            $items[] = [
                'id'           => 1,
                'product_name' => $txn['service_type'] ?: 'Job Order Service',
                'category'     => 'Service',
                'size_variant' => '',
                'quantity'     => 1,
                'unit_price'   => $total,
                'subtotal'     => $total,
                'item_type'    => 'service',
                'product_id'   => 0
            ];

            if (!empty($txn['required_parts'])) {
                $decoded = json_decode($txn['required_parts'], true);
                if (is_array($decoded)) {
                    foreach ($decoded as $idx => $part) {
                        $pname  = is_array($part) ? ($part['name'] ?? $part['part_name'] ?? 'Part') : (string)$part;
                        $pqty   = is_array($part) ? (float)($part['quantity'] ?? 1) : 1;
                        $pprice = is_array($part) ? (float)($part['price'] ?? $part['unit_price'] ?? 0) : 0;
                        if (empty($pname)) continue;
                        $items[] = [
                            'id'           => $idx + 2,
                            'product_name' => $pname,
                            'category'     => 'Parts',
                            'size_variant' => '',
                            'quantity'     => $pqty,
                            'unit_price'   => $pprice,
                            'subtotal'     => round($pqty * $pprice, 2),
                            'item_type'    => 'merchandise',
                            'product_id'   => 0
                        ];
                    }
                }
            }
        }
    }

    // ── If merchandise_transactions or not found yet ───────────────
    if (!$txn) {
        $stmt = $pdo->prepare("
            SELECT mt.id, mt.transaction_id, mt.customer_name, mt.payment_method,
                   mt.total_amount, mt.amount_tendered, mt.change_amount,
                   mt.validation_status, mt.rejection_reason, mt.created_at,
                   mt.shift_period, mt.shift_name, mt.job_order_service, mt.item_sku,
                   COALESCE(NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), u.username, 'Staff') AS staff_name,
                   COALESCE(NULLIF(TRIM(CONCAT(vm.first_name, ' ', vm.last_name)), ''), vm.username, 'N/A') AS validated_by_name,
                   mt.validated_at
            FROM merchandise_transactions mt
            LEFT JOIN users u  ON mt.staff_id    = u.id
            LEFT JOIN users vm ON mt.validated_by = vm.id
            WHERE mt.id = ? AND mt.station_id = ?
            LIMIT 1
        ");
        $stmt->execute([$id, $station_id, $station_id]);
        $txn = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($txn) {
            // Fetch items
            $stmt2 = $pdo->prepare("
                SELECT mti.id, mti.product_name, mti.category, mti.size_variant,
                       mti.quantity, mti.unit_price, mti.subtotal,
                       COALESCE(mti.item_type,'merchandise') AS item_type,
                       COALESCE(mti.product_id,0) AS product_id
                FROM merchandise_transaction_items mti
                WHERE mti.transaction_id = ?
                ORDER BY mti.id ASC
            ");
            $stmt2->execute([$txn['id']]);
            $items = $stmt2->fetchAll(PDO::FETCH_ASSOC);

            if (empty($items)) {
                $lineName = !empty($txn['job_order_service']) ? $txn['job_order_service'] : (!empty($txn['item_sku']) ? $txn['item_sku'] : 'Item #'.$id);
                $isSvc = !empty($txn['job_order_service']);
                $total = (float)$txn['total_amount'];
                $items[] = [
                    'id'           => 1,
                    'product_name' => $lineName,
                    'category'     => $isSvc ? 'Service' : 'Merchandise',
                    'size_variant' => '',
                    'quantity'     => 1,
                    'unit_price'   => $total,
                    'subtotal'     => $total,
                    'item_type'    => $isSvc ? 'service' : 'merchandise',
                    'product_id'   => 0
                ];
            }
        }
    }

    if (!$txn) {
        echo json_encode(['success' => false, 'error' => 'Transaction not found']);
        exit;
    }

    // Fetch adjustment request details from transaction_requests
    $adjReq = null;
    try {
        $txnRef = $txn['transaction_id'] ?? ('TXN-'.$id);
        $stmtReq = $pdo->prepare("
            SELECT tr.*, 
                   COALESCE(NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), u.username, 'Staff') AS requested_by_name
            FROM transaction_requests tr
            LEFT JOIN users u ON tr.requested_by = u.id
            WHERE (tr.transaction_id = ? OR tr.transaction_id = ?) AND tr.request_type = 'Adjustment'
            ORDER BY tr.id DESC LIMIT 1
        ");
        $stmtReq->execute([(string)$txn['id'], $txnRef]);
        $adjReq = $stmtReq->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {
        $adjReq = null;
    }

    echo json_encode([
        'success'            => true,
        'txn'                => $txn,
        'items'              => $items,
        'adjustment_request' => $adjReq,
    ]);

} catch (Exception $e) {
    error_log('get_transaction_items error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
