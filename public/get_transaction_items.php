<?php
/**
 * get_transaction_items.php
 * AJAX endpoint — returns item breakdown for a transaction (JSON).
 * Supports: merchandise_transactions, job_orders
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$id         = (int)($_GET['id']     ?? 0);
$source     = trim($_GET['source']  ?? 'merchandise_transactions');
$station_id = (int) user_station_id();

if ($id <= 0) {
    echo json_encode(['items' => [], 'error' => 'Invalid ID']);
    exit;
}

try {
    // ── JOB ORDERS ────────────────────────────────────────────────────────────
    if ($source === 'job_orders') {
        $stmt = $pdo->prepare("
            SELECT id, service_type, service_description, total_cost, estimated_cost,
                   required_parts, vehicle_plate, vehicle_type
            FROM job_orders
            WHERE id = ? AND station_id = ?
            LIMIT 1
        ");
        $stmt->execute([$id, $station_id]);
        $jo = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$jo) {
            echo json_encode(['items' => [], 'error' => 'Job order not found', 'id' => $id, 'source' => $source]);
            exit;
        }

        $total = (float)($jo['total_cost'] ?? $jo['estimated_cost'] ?? 0);
        $items = [];

        // Service fee line
        $items[] = [
            'id'           => null,
            'product_name' => $jo['service_type'] ?? 'Service Fee',
            'item_type'    => 'service',
            'quantity'     => 1,
            'unit_price'   => $total,
            'subtotal'     => $total,
            'product_id'   => null,
        ];

        // Parts from required_parts (JSON or plain text)
        if (!empty($jo['required_parts'])) {
            $parts_raw = $jo['required_parts'];
            $decoded   = json_decode($parts_raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $part) {
                    $pname = is_array($part) ? ($part['name'] ?? $part['part_name'] ?? 'Part') : (string)$part;
                    $pqty  = is_array($part) ? (float)($part['quantity'] ?? 1) : 1;
                    $pprice = is_array($part) ? (float)($part['price'] ?? $part['unit_price'] ?? 0) : 0;
                    if (empty($pname)) continue;
                    $items[] = [
                        'id'           => null,
                        'product_name' => $pname,
                        'item_type'    => 'merchandise',
                        'quantity'     => $pqty,
                        'unit_price'   => $pprice,
                        'subtotal'     => round($pqty * $pprice, 2),
                        'product_id'   => null,
                    ];
                }
            }
        }

        echo json_encode([
            'items'        => $items,
            'service_type' => $jo['service_type'] ?? 'Service',
            'total_cost'   => $total,
            'id'           => $id,
            'source'       => $source,
        ]);
        exit;
    }

    // ── MERCHANDISE TRANSACTIONS ───────────────────────────────────────────────
    // First try merchandise_transaction_items detail table
    $stmt = $pdo->prepare("
        SELECT
            mti.id,
            COALESCE(NULLIF(TRIM(mti.product_name), ''), 'Item') AS product_name,
            COALESCE(mti.item_type, 'merchandise')               AS item_type,
            mti.quantity,
            mti.unit_price,
            COALESCE(mti.subtotal, mti.quantity * mti.unit_price) AS subtotal,
            mti.product_id
        FROM merchandise_transaction_items mti
        WHERE mti.transaction_id = ?
        ORDER BY
            CASE COALESCE(mti.item_type,'merchandise')
                WHEN 'service'     THEN 0
                WHEN 'merchandise' THEN 1
                ELSE 2
            END ASC,
            mti.id ASC
    ");
    $stmt->execute([$id]);    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fallback: use main transaction row
    if (empty($items)) {
        $mt = $pdo->prepare("
            SELECT item_sku, total_amount, job_order_service,
                   job_order_vehicle_plate, job_order_vehicle_type
            FROM merchandise_transactions
            WHERE id = ? AND station_id = ?
            LIMIT 1
        ");
        $mt->execute([$id, $station_id]);
        $tx = $mt->fetch(PDO::FETCH_ASSOC);

        if ($tx) {
            $total = (float)($tx['total_amount'] ?? 0);
            if (!empty($tx['job_order_service'])) {
                $items[] = [
                    'id'           => null,
                    'product_name' => $tx['job_order_service'],
                    'item_type'    => 'service',
                    'quantity'     => 1,
                    'unit_price'   => $total,
                    'subtotal'     => $total,
                    'product_id'   => null,
                ];
            } elseif (!empty($tx['item_sku'])) {
                $items[] = [
                    'id'           => null,
                    'product_name' => $tx['item_sku'],
                    'item_type'    => 'merchandise',
                    'quantity'     => 1,
                    'unit_price'   => $total,
                    'subtotal'     => $total,
                    'product_id'   => null,
                ];
            } else {
                $items[] = [
                    'id'           => null,
                    'product_name' => 'Item #' . $id,
                    'item_type'    => 'merchandise',
                    'quantity'     => 1,
                    'unit_price'   => $total,
                    'subtotal'     => $total,
                    'product_id'   => null,
                ];
            }

            echo json_encode([
                'items'        => $items,
                'total_amount' => $total,
                'item_label'   => $items[0]['product_name'] ?? 'Item',
                'id'           => $id,
                'source'       => $source,
            ]);
            exit;
        }
    }

    // Compute total from items
    $total_from_items = array_sum(array_column($items, 'subtotal'));

    echo json_encode([
        'items'        => $items,
        'total_amount' => $total_from_items,
        'item_label'   => count($items) . ' item(s)',
        'id'           => $id,
        'source'       => $source,
    ]);

} catch (Exception $e) {
    echo json_encode(['items' => [], 'error' => $e->getMessage(), 'id' => $id, 'source' => $source]);
}
