<?php
/**
 * Merchandise Stock-In API
 * Called by staff_stock_in.php when staff encodes actual received items.
 * This is the ONLY endpoint that updates inventory stock levels.
 *
 * Actions:
 *   submit_stock_in  — POST: encode received items, update inventory, mark PO done
 *   get_history      — GET:  fetch stock-in history for a station
 */
require_once '../lib.php';
require_once '../../public/db_connect.php';

header('Content-Type: application/json');

$me = current_user();
if (!$me) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$role       = role_key($me['role'] ?? 'staff');
$station_id = (int)user_station_id();
$action     = $_GET['action'] ?? '';
$method     = $_SERVER['REQUEST_METHOD'];

// Only staff, admin, superadmin can encode stock-in
if (!in_array($role, ['staff', 'cashier', 'pump_attendant', 'admin', 'superadmin'])) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// Ensure merchandise_stock_in table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS merchandise_stock_in (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        po_id          INT NULL,
        po_number      VARCHAR(100) NULL,
        station_id     INT NOT NULL,
        product_id     INT NOT NULL,
        product_name   VARCHAR(255) NOT NULL,
        sku            VARCHAR(100) NULL,
        category       VARCHAR(100) NULL,
        qty_ordered    INT NOT NULL DEFAULT 0,
        qty_received   INT NOT NULL DEFAULT 0,
        qty_variance   INT NOT NULL DEFAULT 0,
        unit_cost      DECIMAL(10,2) NOT NULL DEFAULT 0,
        total_cost     DECIMAL(12,2) NOT NULL DEFAULT 0,
        condition_flag ENUM('Good','Damaged','Short','Excess') NOT NULL DEFAULT 'Good',
        remarks        TEXT NULL,
        stock_before   INT NOT NULL DEFAULT 0,
        stock_after    INT NOT NULL DEFAULT 0,
        encoded_by     INT NOT NULL,
        encoded_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        batch_ref      VARCHAR(100) NULL,
        INDEX idx_station    (station_id),
        INDEX idx_encoded_at (encoded_at),
        INDEX idx_po_id      (po_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// Ensure fuel_stock_in table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fuel_stock_in (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        delivery_id    INT NOT NULL,
        invoice_no     VARCHAR(100) NULL,
        station_id     INT NOT NULL,
        fuel_type      VARCHAR(255) NOT NULL,
        qty_expected   DECIMAL(12,2) NOT NULL DEFAULT 0,
        qty_received   DECIMAL(12,2) NOT NULL DEFAULT 0,
        qty_variance   DECIMAL(12,2) NOT NULL DEFAULT 0,
        condition_flag ENUM('Good','Damaged','Short','Excess') NOT NULL DEFAULT 'Good',
        remarks        TEXT NULL,
        level_before   DECIMAL(12,2) NOT NULL DEFAULT 0,
        level_after    DECIMAL(12,2) NOT NULL DEFAULT 0,
        encoded_by     INT NOT NULL,
        encoded_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        batch_ref      VARCHAR(100) NULL,
        INDEX idx_station (station_id),
        INDEX idx_encoded_at (encoded_at),
        INDEX idx_delivery_id (delivery_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// Ensure purchase_orders has required columns
foreach ([
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS admin_finalized TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS stock_in_done   TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS stock_in_at     DATETIME NULL",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS stock_in_by     INT NULL",
] as $sql) { try { $pdo->exec($sql); } catch (Exception $e) {} }

try {
    switch ($action) {

        case 'submit_stock_in':
            if ($method !== 'POST') {
                echo json_encode(['success' => false, 'message' => 'POST required']);
                exit;
            }
            handle_submit_stock_in($pdo, $me, $role, $station_id);
            break;

        case 'submit_fuel_stock_in':
            if ($method !== 'POST') {
                echo json_encode(['success' => false, 'message' => 'POST required']);
                exit;
            }
            handle_submit_fuel_stock_in($pdo, $me, $role, $station_id);
            break;

        case 'get_history':
            handle_get_history($pdo, $station_id);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log("Merchandise Stock-In API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

// ─────────────────────────────────────────────────────────────────────────────
// HANDLERS
// ─────────────────────────────────────────────────────────────────────────────

function handle_submit_stock_in($pdo, $me, $role, $station_id) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
        return;
    }

    $po_id      = (int)($input['po_id']     ?? 0);
    $items      = $input['items']           ?? [];
    $batch_note = trim($input['batch_note'] ?? '');
    $batch_id   = trim($input['batch_id']   ?? '');  // Staff-entered or PO batch_id

    if ($po_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'PO ID is required']);
        return;
    }
    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'No items provided']);
        return;
    }

    // Fetch and validate the PO — must be admin-finalized AND manager-validated
    $stmt = $pdo->prepare("
        SELECT po.*, ip.id AS product_id, ip.sku, ip.category
        FROM purchase_orders po
        LEFT JOIN inventory_products ip
               ON ip.product_name = po.product_name AND ip.category != 'Fuel'
        WHERE po.id = ? AND po.station_id = ?
          AND po.type = 'merch'
          AND po.admin_finalized    = 1
          AND po.delivery_validated = 1
          AND po.stock_in_done      = 0
    ");
    $stmt->execute([$po_id, $station_id]);
    $po = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$po) {
        echo json_encode(['success' => false, 'message' => 'PO not found, already stocked in, not yet finalized by Admin, or not yet validated by Manager']);
        return;
    }

    $product_id = (int)($po['product_id'] ?? 0);
    if ($product_id <= 0) {
        // Try to find product by name
        $ps = $pdo->prepare("SELECT id FROM inventory_products WHERE product_name = ? AND category != 'Fuel' LIMIT 1");
        $ps->execute([$po['product_name']]);
        $product_id = (int)($ps->fetchColumn() ?: 0);
    }

    if ($product_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Product not found in inventory_products. Cannot update stock.']);
        return;
    }

    // Use staff-entered batch_id, fall back to PO batch_id, then auto-generate
    $effective_batch_id = $batch_id ?: ($po['batch_id'] ?? '');
    if (empty($effective_batch_id)) {
        $effective_batch_id = 'B-' . date('Ymd') . '-PO' . str_pad($po_id, 4, '0', STR_PAD_LEFT);
    }

    $batch_ref = 'SI-' . date('Ymd-His') . '-PO' . str_pad($po_id, 4, '0', STR_PAD_LEFT);

    $pdo->beginTransaction();
    try {
        $total_received = 0;
        $si_records     = [];

        foreach ($items as $item) {
            $item_product_id = (int)($item['product_id'] ?? $product_id);
            $qty_received    = (int)($item['qty_received']  ?? 0);
            $qty_ordered     = (int)($item['qty_ordered']   ?? $po['quantity']);
            $unit_cost       = (float)($item['unit_cost']   ?? $po['unit_price'] ?? 0);
            $condition       = $item['condition']           ?? 'Good';
            $remarks         = trim($item['remarks']        ?? '');

            if (!in_array($condition, ['Good', 'Damaged', 'Short', 'Excess'])) {
                $condition = 'Good';
            }

            // Get current stock level
            $stock_before = 0;
            $si_row = $pdo->prepare("SELECT stock_level FROM station_inventory WHERE product_id = ? AND station_id = ?");
            $si_row->execute([$item_product_id, $station_id]);
            $si_data = $si_row->fetch(PDO::FETCH_ASSOC);

            if ($si_data) {
                $stock_before = (int)$si_data['stock_level'];
            } else {
                // Fallback: check inventory_products.stock
                $ip_row = $pdo->prepare("SELECT COALESCE(stock, 0) AS stock FROM inventory_products WHERE id = ?");
                $ip_row->execute([$item_product_id]);
                $stock_before = (int)($ip_row->fetchColumn() ?: 0);
            }

            // Only add to inventory if condition is Good or Excess
            $qty_to_add = 0;
            if (in_array($condition, ['Good', 'Excess'])) {
                $qty_to_add = $qty_received;
            }
            // Short/Damaged: do NOT add to inventory (flag only)

            $stock_after  = $stock_before + $qty_to_add;
            $qty_variance = $qty_received - $qty_ordered;
            $total_cost   = round($unit_cost * $qty_received, 2);

            // Update station_inventory
            if ($qty_to_add > 0) {
                $check = $pdo->prepare("SELECT id FROM station_inventory WHERE product_id = ? AND station_id = ?");
                $check->execute([$item_product_id, $station_id]);
                if ($check->fetch()) {
                    $pdo->prepare("
                        UPDATE station_inventory
                        SET stock_level = stock_level + ?,
                            updated_at  = NOW()
                        WHERE product_id = ? AND station_id = ?
                    ")->execute([$qty_to_add, $item_product_id, $station_id]);
                } else {
                    $pdo->prepare("
                        INSERT INTO station_inventory (product_id, station_id, stock_level, status, created_at, updated_at)
                        VALUES (?, ?, ?, 'active', NOW(), NOW())
                    ")->execute([$item_product_id, $station_id, $qty_to_add]);
                }

                // Also update inventory_products.stock as fallback
                try {
                    $pdo->prepare("UPDATE inventory_products SET stock = stock + ? WHERE id = ?")
                        ->execute([$qty_to_add, $item_product_id]);
                } catch (Exception $e) {}
            }

            // Record stock-in entry
            $pdo->prepare("
                INSERT INTO merchandise_stock_in
                    (po_id, po_number, station_id, product_id, product_name, sku, category,
                     qty_ordered, qty_received, qty_variance, unit_cost, total_cost,
                     condition_flag, remarks, stock_before, stock_after,
                     encoded_by, encoded_at, batch_ref)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
            ")->execute([
                $po_id,
                $po['po_number'] ?? '',
                $station_id,
                $item_product_id,
                $po['product_name'],
                $po['sku'] ?? '',
                $po['category'] ?? '',
                $qty_ordered,
                $qty_received,
                $qty_variance,
                $unit_cost,
                $total_cost,
                $condition,
                $remarks,
                $stock_before,
                $stock_after,
                $me['id'],
                $batch_ref
            ]);

            // ── AUTO-CREATE MERCHANDISE BATCH RECORD (FIFO) ──────────────────
            // Only create a batch for Good/Excess deliveries that increase stock
            if ($qty_to_add > 0) {
                try {
                    // Check if this exact batch_number already exists for this product/station
                    $batchCheck = $pdo->prepare("SELECT id FROM merchandise_batches WHERE product_id = ? AND station_id = ? AND batch_number = ? LIMIT 1");
                    $batchCheck->execute([$item_product_id, $station_id, $effective_batch_id]);
                    if (!$batchCheck->fetch()) {
                        $pdo->prepare("
                            INSERT INTO merchandise_batches
                                (product_id, station_id, batch_number, delivery_id, quantity_received,
                                 remaining_qty, unit_cost, supplier, date_received, encoded_by, status,
                                 notes, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, 'active', ?, NOW(), NOW())
                        ")->execute([
                            $item_product_id,
                            $station_id,
                            $effective_batch_id,
                            $po_id,              // delivery_id = po_id for traceability
                            $qty_received,
                            $qty_to_add,         // remaining = qty added to stock
                            $unit_cost,
                            $po['supplier_name'] ?? '',
                            $me['id'],
                            ($remarks ?: ('Stock-In from PO ' . ($po['po_number'] ?? '')))
                        ]);
                    } else {
                        // Batch already exists — update remaining_qty (cumulative delivery)
                        $pdo->prepare("
                            UPDATE merchandise_batches
                            SET remaining_qty = remaining_qty + ?,
                                quantity_received = quantity_received + ?,
                                updated_at = NOW()
                            WHERE product_id = ? AND station_id = ? AND batch_number = ?
                        ")->execute([$qty_to_add, $qty_received, $item_product_id, $station_id, $effective_batch_id]);
                    }
                } catch (Exception $be) {
                    // Non-fatal: batch table error should not fail the whole stock-in
                    error_log("merchandise_batches insert error: " . $be->getMessage());
                }
            }

            $total_received += $qty_received;
            $si_records[] = [
                'product'      => $po['product_name'],
                'qty_received' => $qty_received,
                'condition'    => $condition,
                'stock_before' => $stock_before,
                'stock_after'  => $stock_after,
                'batch_id'     => $effective_batch_id,
            ];
        }

        // Mark PO as stock-in done
        $pdo->prepare("
            UPDATE purchase_orders
            SET stock_in_done = 1,
                stock_in_at   = NOW(),
                stock_in_by   = ?,
                status        = 'Stock-In Complete',
                updated_at    = NOW()
            WHERE id = ?
        ")->execute([$me['id'], $po_id]);

        // Update stock_requests status to 'Received' if linked
        if (!empty($po['request_id'])) {
            try {
                $pdo->prepare("UPDATE stock_requests SET status = 'Received', updated_at = NOW() WHERE id = ?")
                    ->execute([$po['request_id']]);
            } catch (Exception $e) {}
        }

        // Audit log
        $detail = "Stock-In | PO: {$po['po_number']} | Product: {$po['product_name']} | Received: {$total_received} | Batch: {$effective_batch_id} | Ref: {$batch_ref}";
        if ($batch_note) $detail .= " | Note: {$batch_note}";
        try {
            $pdo->prepare("
                INSERT INTO audit_logs
                    (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at)
                VALUES (?, 'inventory', 'Stock-In', ?, 'purchase_orders', ?, 'Success', ?, ?, NOW())
            ")->execute([
                $me['id'], $detail, $po_id,
                $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
        } catch (Exception $e) {}

        if (function_exists('log_activity')) {
            log_activity($pdo, $me['id'], 'Merchandise Stock-In', $detail);
        }

        $pdo->commit();

        echo json_encode([
            'success'    => true,
            'message'    => "Stock-In complete. Inventory updated for {$po['product_name']}.",
            'batch_ref'  => $batch_ref,
            'batch_id'   => $effective_batch_id,
            'records'    => $si_records,
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function handle_get_history($pdo, $station_id) {
    $date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
    $date_to   = $_GET['date_to']   ?? date('Y-m-d');
    $per_page  = max(1, min(100, (int)($_GET['per_page'] ?? 50)));
    $page      = max(1, (int)($_GET['page'] ?? 1));
    $offset    = ($page - 1) * $per_page;

    $stmt = $pdo->prepare("
        SELECT msi.*, u.name AS encoded_by_name
        FROM merchandise_stock_in msi
        LEFT JOIN users u ON msi.encoded_by = u.id
        WHERE msi.station_id = ?
          AND DATE(msi.encoded_at) BETWEEN ? AND ?
        ORDER BY msi.encoded_at DESC
        LIMIT $per_page OFFSET $offset
    ");
    $stmt->execute([$station_id, $date_from, $date_to]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $cnt = $pdo->prepare("
        SELECT COUNT(*) FROM merchandise_stock_in
        WHERE station_id = ? AND DATE(encoded_at) BETWEEN ? AND ?
    ");
    $cnt->execute([$station_id, $date_from, $date_to]);
    $total = (int)$cnt->fetchColumn();

    echo json_encode([
        'success'     => true,
        'history'     => $rows,
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $per_page,
        'total_pages' => $total > 0 ? (int)ceil($total / $per_page) : 1,
    ]);
}

function handle_submit_fuel_stock_in($pdo, $me, $role, $station_id) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
        return;
    }

    $delivery_id  = (int)($input['delivery_id']  ?? 0);
    $qty_received = (float)($input['qty_received'] ?? 0);
    $condition    = $input['condition']          ?? 'Good';
    $remarks      = trim($input['remarks']       ?? '');

    if ($delivery_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Delivery ID is required']);
        return;
    }
    if ($qty_received < 0) {
        echo json_encode(['success' => false, 'message' => 'Quantity received cannot be negative']);
        return;
    }

    // Fetch the pending fuel delivery
    $stmt = $pdo->prepare("
        SELECT * FROM fuel_deliveries
        WHERE id = ? AND station_id = ? AND status = 'Awaiting Stock-In'
    ");
    $stmt->execute([$delivery_id, $station_id]);
    $del = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$del) {
        echo json_encode(['success' => false, 'message' => 'Pending fuel delivery not found or already stocked in.']);
        return;
    }

    $fuel_type = $del['fuel_type'];
    $qty_expected = (float)$del['delivery_liters'];

    // Resolve fuel_type_id
    $fuel_type_id = null;
    try {
        $ftStmt = $pdo->prepare("SELECT fuel_type_id FROM fuel_inventory WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?)) LIMIT 1");
        $ftStmt->execute([$station_id, $fuel_type]);
        $ftRow = $ftStmt->fetch(PDO::FETCH_ASSOC);
        if ($ftRow) $fuel_type_id = (int)$ftRow['fuel_type_id'];
    } catch (Exception $e) {}

    if (!$fuel_type_id) {
        try {
            $ftStmt2 = $pdo->prepare("SELECT id FROM fuel_types WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
            $ftStmt2->execute([$fuel_type]);
            $fuel_type_id = (int)($ftStmt2->fetchColumn() ?: null);
        } catch (Exception $e) {}
    }

    // Get level before stock-in
    $level_before = 0;
    try {
        $lvlStmt = $pdo->prepare("SELECT COALESCE(current_level, current_stock, 0) FROM fuel_inventory WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?)) LIMIT 1");
        $lvlStmt->execute([$station_id, $fuel_type]);
        $level_before = (float)($lvlStmt->fetchColumn() ?: 0);
    } catch (Exception $e) {}

    // Only add to inventory if condition is Good or Excess
    $qty_to_add = 0;
    if (in_array($condition, ['Good', 'Excess'])) {
        $qty_to_add = $qty_received;
    }
    // Short/Damaged: do NOT add to inventory (flag only)

    $level_after = $level_before + $qty_to_add;
    $qty_variance = $qty_received - $qty_expected;
    $batch_ref = 'FI-SI-' . date('Ymd-His') . '-DEL' . str_pad($delivery_id, 4, '0', STR_PAD_LEFT);

    $pdo->beginTransaction();
    try {
        // Update fuel deliveries status
        $pdo->prepare("
            UPDATE fuel_deliveries
            SET status = 'Stock-In Complete',
                notes = CONCAT(IFNULL(notes,''), ' | Stock-In Completed: ', ?)
            WHERE id = ?
        ")->execute([$remarks ?: 'Completed', $delivery_id]);

        // If PO-based, also update deliveries_oversight status to 'Stock-In Complete'
        if ($del['invoice_no']) {
            try {
                $pdo->prepare("
                    UPDATE deliveries_oversight
                    SET status = 'Stock-In Complete',
                        updated_at = NOW()
                    WHERE station_id = ? AND dr_number = ? AND LOWER(TRIM(product)) = LOWER(TRIM(?))
                ")->execute([$station_id, $del['invoice_no'], $fuel_type]);
            } catch (Exception $e) {}
        }

        // Update fuel_inventory
        if ($qty_to_add > 0) {
            $upd = $pdo->prepare("
                UPDATE fuel_inventory
                SET current_level = COALESCE(current_level, 0) + ?,
                    current_stock = COALESCE(current_stock, 0) + ?,
                    last_updated  = NOW()
                WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
            ");
            $upd->execute([$qty_to_add, $qty_to_add, $station_id, $fuel_type]);

            if ($upd->rowCount() === 0 && $fuel_type_id) {
                $pdo->prepare("
                    INSERT INTO fuel_inventory
                        (station_id, fuel_type_id, fuel_type, current_level, current_stock, last_updated)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ")->execute([$station_id, $fuel_type_id, $fuel_type, $qty_to_add, $qty_to_add]);
            }

            // Insert into fuel_adjustments
            if ($fuel_type_id) {
                try {
                    $audit_reason = "Stock-In | Delivery #{$delivery_id} received. Liters: {$qty_received} ({$condition}). Notes: {$remarks}";
                    $pdo->prepare("
                        INSERT INTO fuel_adjustments
                            (station_id, fuel_type_id, adjustment_type, liters, reason, user_id, adjustment_date)
                        VALUES (?, ?, 'delivery', ?, ?, ?, CURDATE())
                    ")->execute([$station_id, $fuel_type_id, $qty_to_add, $audit_reason, $me['id']]);
                } catch (Exception $ae) {
                    error_log("fuel_adjustments insert failed: " . $ae->getMessage());
                }
            }
        }

        // Insert into fuel_stock_in
        $pdo->prepare("
            INSERT INTO fuel_stock_in
                (delivery_id, invoice_no, station_id, fuel_type, qty_expected, qty_received, qty_variance,
                 condition_flag, remarks, level_before, level_after, encoded_by, encoded_at, batch_ref)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
        ")->execute([
            $delivery_id,
            $del['invoice_no'] ?? '',
            $station_id,
            $fuel_type,
            $qty_expected,
            $qty_received,
            $qty_variance,
            $condition,
            $remarks,
            $level_before,
            $level_after,
            $me['id'],
            $batch_ref
        ]);

        // Audit log
        $detail = "Fuel Stock-In | Delivery #{$delivery_id} | Fuel: {$fuel_type} | Received: {$qty_received} L | Batch: {$batch_ref}";
        if ($remarks) $detail .= " | Note: {$remarks}";
        try {
            $pdo->prepare("
                INSERT INTO audit_logs
                    (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at)
                VALUES (?, 'fuel_management', 'Fuel Stock-In', ?, 'fuel_deliveries', ?, 'Success', ?, ?, NOW())
            ")->execute([
                $me['id'], $detail, $delivery_id,
                $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
        } catch (Exception $e) {}

        if (function_exists('log_activity')) {
            log_activity($pdo, $me['id'], 'Fuel Stock-In', $detail);
        }

        $pdo->commit();

        echo json_encode([
            'success'   => true,
            'message'   => "Stock-In complete. Tank level updated for {$fuel_type}.",
            'batch_ref' => $batch_ref,
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
