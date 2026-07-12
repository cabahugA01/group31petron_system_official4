<?php
// ============================================================
// Manager Inventory Procurement Workflow — manager_stock_request_review.php
// Handles: Pending Requests, Waiting Delivery, Pending Stock-In, Completed
// ============================================================
$page_id = 'mgr_stock_review';
$page_title = 'Purchase Request';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$station_id = (int)user_station_id();
$role       = role_key($me['role'] ?? '');

// Access control
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Manager access required.';
    header('Location: dashboard.php');
    exit;
}

// ── Handle POST Actions ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Generate Merchandise Purchase Request
    if ($action === 'generate_merch_pr') {
        $pr_number = trim($_POST['pr_number'] ?? '');
        $expected_delivery = trim($_POST['expected_delivery'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');
        $quantities = $_POST['quantities'] ?? []; // Array of product_id => qty
        $stock_req_ids = $_POST['stock_req_ids'] ?? []; // Array of product_id => stock_request_id
        $units = $_POST['units'] ?? []; // Array of product_id => unit
        
        if (empty($pr_number)) {
            $pr_number = "PR-" . date('Ymd') . "-" . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        }

        try {
            $pdo->beginTransaction();
            $total_amount = 0;
            $items_to_insert = [];

            foreach ($quantities as $prod_id => $qty) {
                $qty = (int)$qty;
                if ($qty <= 0) continue;

                $p_stmt = $pdo->prepare("SELECT product_name, unit_cost, unit_price FROM inventory_products WHERE id = ?");
                $p_stmt->execute([$prod_id]);
                $prod = $p_stmt->fetch(PDO::FETCH_ASSOC);
                if (!$prod) continue;

                $unit_cost = (float)($prod['unit_cost'] ?: ($prod['unit_price'] * 0.8) ?: 145.00);
                $subtotal = $qty * $unit_cost;
                $total_amount += $subtotal;

                $unit = isset($units[$prod_id]) ? trim($units[$prod_id]) : '';

                $items_to_insert[] = [
                    'product_id' => $prod_id,
                    'product_name' => $prod['product_name'],
                    'quantity' => $qty,
                    'unit_price' => $unit_cost,
                    'total_price' => $subtotal,
                    'unit' => $unit,
                    'stock_req_id' => isset($stock_req_ids[$prod_id]) ? (int)$stock_req_ids[$prod_id] : null
                ];
            }

            if (empty($items_to_insert)) {
                throw new Exception("Please specify quantity for at least one item.");
            }

            $supplier_id = $pdo->query("SELECT id FROM suppliers WHERE name LIKE '%Petron%' OR id = 1 LIMIT 1")->fetchColumn() ?: 1;
            
            $first_req_id = null;
            foreach ($items_to_insert as $item) {
                if ($item['stock_req_id']) {
                    $first_req_id = $item['stock_req_id'];
                    break;
                }
            }

            // Insert into purchase_orders (Status: Approved for staff record delivery)
            $po_stmt = $pdo->prepare("
                INSERT INTO purchase_orders 
                    (po_number, station_id, supplier_id, created_by, status, expected_delivery_date, remarks, type, total_amount, request_id, created_at, updated_at, admin_finalized)
                VALUES (?, ?, ?, ?, 'Approved', ?, ?, 'merch', ?, ?, NOW(), NOW(), 1)
            ");
            $po_stmt->execute([
                $pr_number, $station_id, $supplier_id, $me['id'], $expected_delivery ?: null, $remarks, $total_amount, $first_req_id
            ]);
            $po_id = $pdo->lastInsertId();

            foreach ($items_to_insert as $item) {
                $item_stmt = $pdo->prepare("
                    INSERT INTO purchase_order_items 
                        (po_id, product_id, item_name, quantity, quantity_ordered, unit_price, total_price)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $item_stmt->execute([
                    $po_id, $item['product_id'], $item['product_name'], $item['quantity'], $item['quantity'], $item['unit_price'], $item['total_price']
                ]);

                // Update unit of measure in station_inventory and inventory_products
                if (!empty($item['unit'])) {
                    $upd_unit_stmt = $pdo->prepare("UPDATE station_inventory SET unit = ? WHERE product_id = ? AND station_id = ?");
                    $upd_unit_stmt->execute([$item['unit'], $item['product_id'], $station_id]);

                    $upd_prod_stmt = $pdo->prepare("UPDATE inventory_products SET size = ? WHERE id = ?");
                    $upd_prod_stmt->execute([$item['unit'], $item['product_id']]);
                }

                if ($item['stock_req_id']) {
                    $sr_stmt = $pdo->prepare("
                        UPDATE stock_requests 
                        SET status = 'Approved', approved_quantity = ?, manager_id = ?, manager_notes = ?, processed_at = NOW(), updated_at = NOW()
                        WHERE id = ? AND station_id = ?
                    ");
                    $sr_stmt->execute([
                        $item['quantity'], $me['id'], $remarks, $item['stock_req_id'], $station_id
                    ]);

                    $audit_stmt = $pdo->prepare("
                        INSERT INTO stock_request_audit
                            (stock_request_id, action_type, performed_by, performed_by_role, old_status, new_status, notes)
                        VALUES (?, 'Approved', ?, ?, 'Pending', 'Approved', ?)
                    ");
                    $audit_stmt->execute([
                        $item['stock_req_id'], $me['id'], $role, "Approved by Manager. PR: $pr_number"
                    ]);
                }
            }

            log_activity($pdo, $me['id'], 'Generate Purchase Request', "Generated Merchandise PR: $pr_number");
            $pdo->commit();
            $_SESSION['success'] = "Merchandise Purchase Request <strong>$pr_number</strong> generated successfully.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = "Error: " . $e->getMessage();
        }
        header('Location: manager_stock_request_review.php?tab=pending_requests');
        exit;
    }

    // 2. Generate Fuel Purchase Request
    if ($action === 'generate_fuel_pr') {
        $pr_number       = trim($_POST['pr_number'] ?? '');
        $expected_delivery = trim($_POST['expected_delivery'] ?? '');
        $remarks         = trim($_POST['remarks'] ?? '');
        $fuel_quantities = $_POST['fuel_quantities'] ?? [];  // fuel_type_id => liters
        $fuel_req_ids    = $_POST['fuel_req_ids'] ?? [];     // fuel_type_id => fuel_stock_request_id

        if (empty($pr_number)) {
            $pr_number = 'FPR-' . date('Ymd') . '-' . str_pad(rand(1,9999), 4, '0', STR_PAD_LEFT);
        }
        try {
            $pdo->beginTransaction();
            $supplier_id = (int)($pdo->query("SELECT id FROM suppliers LIMIT 1")->fetchColumn() ?: 1);
            $inserted = 0;
            
            foreach ($fuel_quantities as $fuel_type_id => $liters) {
                $liters = (float)$liters;
                if ($liters <= 0) continue;
                
                $fp = $pdo->prepare("SELECT price_per_liter FROM fuel_inventory WHERE fuel_type_id = ? AND station_id = ? LIMIT 1");
                $fp->execute([$fuel_type_id, $station_id]);
                $price = (float)($fp->fetchColumn() ?: 60.00);
                
                $linked_req_id = isset($fuel_req_ids[$fuel_type_id]) ? (int)$fuel_req_ids[$fuel_type_id] : null;
                $note_text = ($linked_req_id ? "[FSR:{$linked_req_id}] " : '') . $remarks;
                
                $pdo->prepare("
                    INSERT INTO fuel_purchase_orders
                        (po_number, station_id, fuel_type_id, volume, unit_price, total_amount,
                         supplier_id, expected_delivery_date, status, created_by, notes, batch_id, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Approved PO', ?, ?, ?, NOW(), NOW())
                ")->execute([
                    $pr_number, $station_id, $fuel_type_id, $liters, $price, $liters * $price,
                    $supplier_id, $expected_delivery ?: null, $me['id'], $note_text, $pr_number
                ]);

                if ($linked_req_id) {
                    $pdo->prepare("UPDATE fuel_stock_requests SET status='Approved', manager_id=?, processed_at=NOW() WHERE id=? AND station_id=?")
                        ->execute([$me['id'], $linked_req_id, $station_id]);
                }
                $inserted++;
            }
            
            if ($inserted === 0) throw new Exception('Enter liters for at least one fuel type.');
            log_activity($pdo, $me['id'], 'Generate Fuel PR', "Generated Fuel PR: $pr_number");
            $pdo->commit();
            $_SESSION['success'] = "Fuel Purchase Request <strong>$pr_number</strong> generated successfully.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = 'Error: ' . $e->getMessage();
        }
        header('Location: manager_stock_request_review.php?tab=pending_requests');
        exit;
    }

    // 3. Cancel Merchandise Purchase Request
    if ($action === 'cancel_pr') {
        $po_id = (int)($_POST['po_id'] ?? 0);
        if ($po_id > 0) {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id = ? AND station_id = ?");
                $stmt->execute([$po_id, $station_id]);
                $po = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($po && in_array($po['status'], ['Pending', 'Approved', 'Admin Finalized', 'Draft'])) {
                    $pdo->prepare("UPDATE purchase_orders SET status = 'Cancelled', updated_at = NOW() WHERE id = ?")->execute([$po_id]);
                    if ($po['request_id']) {
                        $pdo->prepare("
                            UPDATE stock_requests sr
                            JOIN purchase_order_items poi ON poi.product_id = sr.item_id AND sr.status = 'Approved'
                            SET sr.status = 'Pending', sr.approved_quantity = NULL, sr.processed_at = NULL 
                            WHERE poi.po_id = ? AND sr.station_id = ?
                        ")->execute([$po_id, $station_id]);
                    }
                    log_activity($pdo, $me['id'], 'Cancel Purchase Request', "Cancelled PR: {$po['po_number']}");
                    $pdo->commit();
                    $_SESSION['success'] = "Purchase Request <strong>{$po['po_number']}</strong> cancelled.";
                } else {
                    throw new Exception("Unable to cancel. Purchase Request not found or processed.");
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = "Error: " . $e->getMessage();
            }
        }
        header('Location: manager_stock_request_review.php?tab=waiting_delivery');
        exit;
    }

    // 4. Cancel Fuel Purchase Request
    if ($action === 'cancel_fuel_pr') {
        $batch_id = trim($_POST['batch_id'] ?? '');
        if ($batch_id) {
            try {
                $chk = $pdo->prepare("SELECT status FROM fuel_purchase_orders WHERE batch_id=? AND station_id=? LIMIT 1");
                $chk->execute([$batch_id, $station_id]);
                $fst = $chk->fetchColumn();
                if (in_array($fst, ['Pending', 'Approved PO', 'Approved', 'Draft'])) {
                    $pdo->prepare("UPDATE fuel_purchase_orders SET status='Cancelled' WHERE batch_id=? AND station_id=?")
                        ->execute([$batch_id, $station_id]);
                    $_SESSION['success'] = "Fuel PR <strong>$batch_id</strong> cancelled.";
                } else {
                    throw new Exception('Cannot cancel a request with status: ' . $fst);
                }
            } catch (Exception $e) {
                $_SESSION['error'] = 'Error: ' . $e->getMessage();
            }
        }
        header('Location: manager_stock_request_review.php?tab=waiting_delivery');
        exit;
    }

    // 5. Approve Stock-In Delivery
    // Auto-create fuel_stock_in table if it doesn't exist
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS fuel_stock_in (
            id INT AUTO_INCREMENT PRIMARY KEY,
            delivery_id INT NULL,
            invoice_no VARCHAR(100) NULL,
            station_id INT NOT NULL,
            fuel_type VARCHAR(100) NOT NULL,
            qty_expected DECIMAL(12,2) NOT NULL DEFAULT 0,
            qty_received DECIMAL(12,2) NOT NULL DEFAULT 0,
            qty_variance DECIMAL(12,2) NOT NULL DEFAULT 0,
            condition_flag VARCHAR(50) NOT NULL DEFAULT 'Good',
            remarks TEXT NULL,
            level_before DECIMAL(12,2) NOT NULL DEFAULT 0,
            level_after DECIMAL(12,2) NOT NULL DEFAULT 0,
            encoded_by INT NOT NULL,
            encoded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            batch_ref VARCHAR(100) NULL,
            delivery_ref VARCHAR(100) NULL,
            INDEX idx_station (station_id),
            INDEX idx_encoded_at (encoded_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}

    // 5. Approve Stock-In Delivery
    if ($action === 'approve_stock_in') {
        $delivery_ref = trim($_POST['delivery_ref'] ?? '');
        $condition = trim($_POST['condition'] ?? 'Good');
        $items_data = $_POST['items'] ?? []; // Map: oversight_id => [qty_rec, cost, price]

        if (empty($delivery_ref) || empty($items_data)) {
            $_SESSION['error'] = "Invalid stock-in approval request.";
            header('Location: manager_stock_request_review.php?tab=pending_stock_in');
            exit;
        }

        try {
            $pdo->beginTransaction();

            foreach ($items_data as $do_id => $data) {
                $received_qty = (float)($data['qty_rec'] ?? 0);
                $unit_cost = (float)($data['cost'] ?? 0);
                $selling_price = (float)($data['price'] ?? 0);

                // Fetch delivery oversight row
                $do_stmt = $pdo->prepare("SELECT * FROM deliveries_oversight WHERE id = ? AND station_id = ? AND status = 'Pending Stock-In'");
                $do_stmt->execute([$do_id, $station_id]);
                $del = $do_stmt->fetch(PDO::FETCH_ASSOC);
                if (!$del) {
                    throw new Exception("Delivery item #{$do_id} not found or already processed.");
                }

                $qty_variance = $received_qty - $del['expected_quantity'];

                // Update deliveries_oversight record
                $pdo->prepare("
                    UPDATE deliveries_oversight 
                    SET actual_quantity = ?, 
                        quantity = ?,
                        unit_cost = ?, 
                        unit_price = ?, 
                        status = 'Stock-In Complete',
                        manager_id = ?,
                        manager_action_at = NOW(),
                        manager_notes = ?
                    WHERE id = ?
                ")->execute([
                    $received_qty, 
                    $received_qty, 
                    $unit_cost, 
                    $selling_price, 
                    $me['id'], 
                    "Approved: Condition - " . $condition, 
                    $do_id
                ]);

                if ($del['delivery_type'] === 'merchandise') {
                    // Find product
                    $p_stmt = $pdo->prepare("SELECT * FROM inventory_products WHERE product_name = ? AND category != 'Fuel' LIMIT 1");
                    $p_stmt->execute([$del['product']]);
                    $prod = $p_stmt->fetch(PDO::FETCH_ASSOC) ?: $pdo->query("SELECT * FROM inventory_products WHERE product_name = '{$del['product']}' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                    
                    if ($prod) {
                        $prod_id = $prod['id'];

                        // Check stock levels before
                        $si_stmt = $pdo->prepare("SELECT stock_level FROM station_inventory WHERE product_id = ? AND station_id = ?");
                        $si_stmt->execute([$prod_id, $station_id]);
                        $stock_before = (int)($si_stmt->fetchColumn() ?: 0);
                        $stock_after = $stock_before + $received_qty;

                        // Update station_inventory
                        $check_si = $pdo->prepare("SELECT id FROM station_inventory WHERE product_id = ? AND station_id = ?");
                        $check_si->execute([$prod_id, $station_id]);
                        if ($check_si->fetch()) {
                            $pdo->prepare("
                                UPDATE station_inventory 
                                SET stock_level = stock_level + ?,
                                    cost = ?,
                                    price = ?,
                                    last_updated = NOW()
                                WHERE product_id = ? AND station_id = ?
                            ")->execute([$received_qty, $unit_cost, $selling_price, $prod_id, $station_id]);
                        } else {
                            $pdo->prepare("
                                INSERT INTO station_inventory (station_id, product_id, stock_level, cost, price, unit, status, reorder_level, last_updated)
                                VALUES (?, ?, ?, ?, ?, ?, 'active', 10, NOW())
                            ")->execute([$station_id, $prod_id, $received_qty, $unit_cost, $selling_price, $del['unit'] ?: 'pcs']);
                        }

                        // Update global inventory_products
                        $pdo->prepare("
                            UPDATE inventory_products 
                            SET stock = stock + ?, unit_cost = ?, unit_price = ? 
                            WHERE id = ?
                        ")->execute([$received_qty, $unit_cost, $selling_price, $prod_id]);

                        // Find corresponding PO ID
                        $po_id_val = null;
                        if ($del['source_ref']) {
                            $po_id_val = $pdo->prepare("SELECT id FROM purchase_orders WHERE po_number = ? LIMIT 1");
                            $po_id_val->execute([$del['source_ref']]);
                            $po_id_val = $po_id_val->fetchColumn() ?: null;
                        }

                        // Insert into merchandise_stock_in
                        $pdo->prepare("
                            INSERT INTO merchandise_stock_in 
                                (po_id, po_number, station_id, product_id, product_name, sku, category, qty_ordered, qty_received, qty_variance, unit_cost, total_cost, condition_flag, remarks, stock_before, stock_after, encoded_by, encoded_at, batch_ref)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
                        ")->execute([
                            $po_id_val, $del['source_ref'] ?: $del['delivery_ref'], $station_id, $prod_id, $del['product'],
                            $prod['sku'] ?? '—', $prod['category'] ?? 'General', $del['expected_quantity'], $received_qty,
                            $qty_variance, $unit_cost, $received_qty * $unit_cost, $condition === 'Good' ? 'Good' : 'Damaged',
                            $del['remarks'] ?: 'Manager Approved Stock-In', $stock_before, $stock_after, $me['id'], $del['batch_id'] ?: $del['delivery_ref']
                        ]);

                        // Log Activity / Inventory logs
                        $pdo->prepare("
                            INSERT INTO inventory_logs (station_id, product_id, user_id, action, quantity_before, quantity_after, quantity_change, reference_type, reference_id, notes, created_at)
                            VALUES (?, ?, ?, 'delivery', ?, ?, ?, 'stock_in', ?, ?, NOW())
                        ")->execute([
                            $station_id, $prod_id, $me['id'], $stock_before, $stock_after, $received_qty, $po_id_val,
                            "Stock-In Approved. DR: {$del['dr_number']}, Ref: {$delivery_ref}"
                        ]);

                        // Batches for FIFO (uses actual merchandise_batches schema)
                        try {
                            $pdo->prepare("
                                INSERT INTO merchandise_batches (station_id, product_id, batch_number, quantity_received, remaining_qty, unit_cost, date_received, encoded_by, status, notes, created_at, updated_at)
                                VALUES (?, ?, ?, ?, ?, ?, CURDATE(), ?, 'active', ?, NOW(), NOW())
                            ")->execute([
                                $station_id, $prod_id, $del['batch_id'] ?: $del['delivery_ref'],
                                $received_qty, $received_qty, $unit_cost, $me['id'],
                                'Stock-In Approved. DR: ' . ($del['dr_number'] ?: 'N/A')
                            ]);
                        } catch (Exception $batchEx) { /* batch tracking optional */ }
                    }
                } elseif ($del['delivery_type'] === 'fuel') {
                    // Find fuel details
                    $ft_stmt = $pdo->prepare("SELECT * FROM fuel_types WHERE name = ? LIMIT 1");
                    $ft_stmt->execute([$del['product']]);
                    $fuel_type = $ft_stmt->fetch(PDO::FETCH_ASSOC);

                    if ($fuel_type) {
                        $fuel_type_id = $fuel_type['id'];

                        $fi_stmt = $pdo->prepare("SELECT current_level, reorder_level FROM fuel_inventory WHERE fuel_type_id = ? AND station_id = ?");
                        $fi_stmt->execute([$fuel_type_id, $station_id]);
                        $fuel_inv = $fi_stmt->fetch(PDO::FETCH_ASSOC);
                        $level_before = $fuel_inv ? (float)$fuel_inv['current_level'] : 0.0;
                        $level_after = $level_before + $received_qty;

                        // Update Fuel Inventory
                        if ($fuel_inv) {
                            $pdo->prepare("
                                UPDATE fuel_inventory 
                                SET current_level = current_level + ?,
                                    current_stock = current_stock + ?,
                                    price_per_liter = ?,
                                    last_updated = NOW()
                                WHERE fuel_type_id = ? AND station_id = ?
                            ")->execute([$received_qty, $received_qty, $selling_price, $fuel_type_id, $station_id]);
                        } else {
                            $pdo->prepare("
                                INSERT INTO fuel_inventory (station_id, fuel_type_id, fuel_type, current_level, current_stock, reorder_level, price_per_liter, status, last_updated)
                                VALUES (?, ?, ?, ?, ?, 5000, ?, 'active', NOW())
                            ")->execute([$station_id, $fuel_type_id, $del['product'], $received_qty, $received_qty, $selling_price]);
                        }

                        // Insert into fuel_stock_in (table auto-created above)
                        try {
                            $pdo->prepare("
                                INSERT INTO fuel_stock_in 
                                    (delivery_id, invoice_no, station_id, fuel_type, qty_expected, qty_received, qty_variance, condition_flag, remarks, level_before, level_after, encoded_by, encoded_at, batch_ref, delivery_ref)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)
                            ")->execute([
                                $do_id, $del['dr_number'], $station_id, $del['product'], $del['expected_quantity'],
                                $received_qty, $qty_variance, $condition, $del['remarks'] ?: 'Manager Approved Fuel Stock-In',
                                $level_before, $level_after, $me['id'], $del['batch_id'] ?: $del['delivery_ref'], $del['delivery_ref']
                            ]);
                        } catch (Exception $fsiEx) { /* non-critical */ }

                        // Fuel Adjustment
                        try {
                            $pdo->prepare("
                                INSERT INTO fuel_adjustments (station_id, fuel_type_id, fuel_type, adjustment_type, liters, reason, user_id, adjustment_date, created_at)
                                VALUES (?, ?, ?, 'delivery', ?, ?, ?, CURDATE(), NOW())
                            ")->execute([
                                $station_id, $fuel_type_id, $del['product'], $received_qty, "Stock-In Approved. Ref: $delivery_ref", $me['id']
                            ]);
                        } catch (Exception $adjEx) { /* non-critical */ }
                        // Note: fuel_batches table not available in this schema — skipped
                    }
                }

                // Notifications
                $notify_stmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, type, title, message, event_type, severity, redirect_url, created_at)
                    VALUES (?, 'success', 'Delivery Approved', ?, 'delivery', 'info', ?, NOW())
                ");
                $notify_stmt->execute([$del['encoded_by'], "Your delivery ref '{$del['delivery_ref']}' has been approved and stocked in.", 'staff_record_delivery.php']);
                
                $admins = $pdo->query("SELECT id FROM users WHERE role IN ('admin', 'superadmin')")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($admins as $admin_id) {
                    $notify_stmt->execute([$admin_id, "Manager Approved Stock-In for delivery ref '{$del['delivery_ref']}' at Station #{$station_id}.", 'admin_deliveries_oversight.php']);
                }
            }

            log_activity($pdo, $me['id'], 'Stock-In Complete', "Approved Stock-In for ref: $delivery_ref");
            $pdo->commit();
            $_SESSION['success'] = "Delivery <strong>$delivery_ref</strong> approved and stocked in successfully. Inventory levels updated.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = "Error: " . $e->getMessage();
        }
        header("Location: manager_stock_request_review.php?tab=pending_stock_in");
        exit;
    }

    // 6. Return Stock-In Delivery
    if ($action === 'return_stock_in') {
        $delivery_ref = trim($_POST['delivery_ref'] ?? '');
        $reason = trim($_POST['reason'] ?? '');

        if (empty($delivery_ref)) {
            $_SESSION['error'] = "Invalid delivery reference for return.";
            header("Location: manager_stock_request_review.php?tab=pending_stock_in");
            exit;
        }

        try {
            $pdo->beginTransaction();

            $upd = $pdo->prepare("
                UPDATE deliveries_oversight 
                SET status = 'Pending Verification', 
                    manager_notes = ?,
                    manager_id = ?,
                    manager_action_at = NOW()
                WHERE delivery_ref = ? AND station_id = ? AND status = 'Pending Stock-In'
            ");
            $upd->execute([$reason ?: "Returned by manager.", $me['id'], $delivery_ref, $station_id]);

            // Restore PO statuses back to 'Approved'
            $src_stmt = $pdo->prepare("SELECT DISTINCT source_ref, delivery_type FROM deliveries_oversight WHERE delivery_ref = ? AND station_id = ?");
            $src_stmt->execute([$delivery_ref, $station_id]);
            foreach ($src_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['delivery_type'] === 'merchandise') {
                    $pdo->prepare("UPDATE purchase_orders SET status = 'Approved' WHERE po_number = ? AND station_id = ?")
                        ->execute([$row['source_ref'], $station_id]);
                } else {
                    $pdo->prepare("UPDATE fuel_purchase_orders SET status = 'Approved PO' WHERE po_number = ? AND station_id = ?")
                        ->execute([$row['source_ref'], $station_id]);
                }
            }

            // Notify Staff who encoded
            $enc_stmt = $pdo->prepare("SELECT DISTINCT encoded_by FROM deliveries_oversight WHERE delivery_ref = ? AND station_id = ?");
            $enc_stmt->execute([$delivery_ref, $station_id]);
            $staff_ids = $enc_stmt->fetchAll(PDO::FETCH_COLUMN);

            $notify_stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, type, title, message, event_type, severity, redirect_url, created_at)
                VALUES (?, 'warning', 'Delivery Returned', ?, 'delivery', 'warning', 'staff_record_delivery.php', NOW())
            ");
            foreach ($staff_ids as $staff_id) {
                $notify_stmt->execute([$staff_id, "Delivery ref '{$delivery_ref}' was returned by the manager. Reason: $reason"]);
            }

            log_activity($pdo, $me['id'], 'Stock-In Returned', "Returned delivery ref: $delivery_ref for correction.");
            $pdo->commit();
            $_SESSION['success'] = "Delivery <strong>$delivery_ref</strong> has been returned to staff for correction.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = "Error: " . $e->getMessage();
        }
        header("Location: manager_stock_request_review.php?tab=pending_stock_in");
        exit;
    }
}

// ── Fetch Summary Metrics ───────────────────────────────────────────────────
$cnt_pending_sr_merch = (int)$pdo->query("SELECT COUNT(*) FROM stock_requests WHERE station_id = $station_id AND status = 'Pending' AND LOWER(COALESCE(item_category, '')) != 'fuel'")->fetchColumn();
$cnt_pending_sr_fuel = (int)$pdo->query("SELECT COUNT(*) FROM fuel_stock_requests WHERE station_id = $station_id AND status = 'Pending'")->fetchColumn();
$total_pending_requests = $cnt_pending_sr_merch + $cnt_pending_sr_fuel;

$cnt_waiting_merch = (int)$pdo->query("SELECT COUNT(DISTINCT po_number) FROM purchase_orders WHERE station_id = $station_id AND type = 'merch' AND status IN ('Approved', 'Approved PO', 'Admin Finalized', 'Pending', 'Pending Admin Validation') AND id NOT IN (SELECT DISTINCT po_id FROM merchandise_stock_in WHERE station_id = $station_id)")->fetchColumn();
$cnt_waiting_fuel = (int)$pdo->query("SELECT COUNT(DISTINCT batch_id) FROM fuel_purchase_orders WHERE station_id = $station_id AND status IN ('Approved', 'Approved PO', 'Admin Finalized', 'Pending', 'Pending Admin Validation') AND actual_volume IS NULL")->fetchColumn();
$total_waiting_delivery = $cnt_waiting_merch + $cnt_waiting_fuel;

$total_pending_stock_in = (int)$pdo->query("SELECT COUNT(DISTINCT delivery_ref) FROM deliveries_oversight WHERE station_id = $station_id AND status = 'Pending Stock-In'")->fetchColumn();

$total_completed = (int)$pdo->query("SELECT COUNT(DISTINCT delivery_ref) FROM deliveries_oversight WHERE station_id = $station_id AND status = 'Stock-In Complete'")->fetchColumn();

// Active Tab
$active_tab = $_GET['tab'] ?? 'pending_requests';
if (!in_array($active_tab, ['pending_requests', 'waiting_delivery', 'pending_stock_in', 'completed'])) {
    $active_tab = 'pending_requests';
}

// ── Data Fetching per Tab ────────────────────────────────────────────────────
$pending_requests_list = [];
$waiting_deliveries_list = [];
$pending_stock_ins_list = [];
$completed_stock_ins_list = [];

if ($active_tab === 'pending_requests') {
    // Fetch pending Merchandise requests
    $stmt1 = $pdo->prepare("
        SELECT sr.id, 'Merchandise' AS req_type, sr.item_name AS item_title, sr.requested_quantity AS requested_qty, sr.created_at,
               u.name AS staff_name, COALESCE(si.unit, ip.size, 'pcs') AS unit,
               COALESCE(si.stock_level, ip.stock, 0) AS current_stock,
               COALESCE(si.reorder_level, ip.min_stock, 10) AS reorder_level,
               sr.item_id AS product_id
        FROM stock_requests sr
        LEFT JOIN users u ON sr.staff_id = u.id
        LEFT JOIN inventory_products ip ON sr.item_id = ip.id
        LEFT JOIN station_inventory si ON sr.item_id = si.product_id AND si.station_id = sr.station_id
        WHERE sr.station_id = ? AND sr.status = 'Pending' AND LOWER(COALESCE(sr.item_category, '')) != 'fuel'
    ");
    $stmt1->execute([$station_id]);
    $merch_reqs = $stmt1->fetchAll(PDO::FETCH_ASSOC);

    // Fetch pending Fuel requests
    $stmt2 = $pdo->prepare("
        SELECT fsr.id, 'Fuel' AS req_type, fsr.fuel_type AS item_title, fsr.requested_liters AS requested_qty, fsr.created_at,
               u.name AS staff_name, 'L' AS unit,
               COALESCE(fi.current_level, 0) AS current_stock,
               COALESCE(fi.reorder_level, 5000) AS reorder_level,
               fi.fuel_type_id AS product_id
        FROM fuel_stock_requests fsr
        LEFT JOIN users u ON fsr.staff_id = u.id
        LEFT JOIN fuel_inventory fi ON LOWER(fsr.fuel_type) = LOWER(fi.fuel_type) AND fi.station_id = fsr.station_id
        WHERE fsr.station_id = ? AND fsr.status = 'Pending'
    ");
    $stmt2->execute([$station_id]);
    $fuel_reqs = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    $pending_requests_list = array_merge($merch_reqs, $fuel_reqs);
    usort($pending_requests_list, function($a, $b) {
        return strcmp($b['created_at'], $a['created_at']);
    });

    // Fetch Active Products (for manual addition)
    $all_station_products = [];
    try {
        $products_stmt = $pdo->prepare("
            SELECT ip.*, 
                   COALESCE(si.stock_level, ip.stock_quantity, ip.stock, 0) AS current_stock_actual, 
                   COALESCE(si.reorder_level, ip.min_stock, 10) AS reorder_level,
                   COALESCE(si.unit, ip.size, 'pcs') AS stock_unit
            FROM inventory_products ip
            LEFT JOIN station_inventory si ON ip.id = si.product_id AND si.station_id = ?
            WHERE ip.station_id = ? AND LOWER(ip.category) != 'fuel' AND ip.status = 'active'
            ORDER BY ip.product_name ASC
        ");
        $products_stmt->execute([$station_id, $station_id]);
        $all_station_products = $products_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    // Fetch Fuel inventory list
    $fuel_inventory_list = [];
    try {
        $fi_stmt = $pdo->prepare("SELECT * FROM fuel_inventory WHERE station_id = ? ORDER BY fuel_type");
        $fi_stmt->execute([$station_id]);
        $fuel_inventory_list = $fi_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

} elseif ($active_tab === 'waiting_delivery') {
    // Merchandise POs grouped by po_number and expected_delivery_date
    $stmt1 = $pdo->prepare("
        SELECT po.po_number, po.expected_delivery_date, MIN(po.created_at) AS created_at, po.status,
               u.name AS prepared_by_name,
               GROUP_CONCAT(DISTINCT po.id) AS po_ids,
               GROUP_CONCAT(DISTINCT CONCAT(po.product_name, ' (x', CAST(po.quantity AS SIGNED), ')') SEPARATOR ', ') AS direct_items_list
        FROM purchase_orders po
        LEFT JOIN users u ON po.created_by = u.id
        WHERE po.station_id = ? AND po.type = 'merch' AND po.status IN ('Approved', 'Approved PO', 'Admin Finalized', 'Pending', 'Pending Admin Validation')
          AND po.id NOT IN (SELECT DISTINCT po_id FROM merchandise_stock_in WHERE station_id = ? AND po_id IS NOT NULL)
        GROUP BY po.po_number, po.expected_delivery_date
        ORDER BY created_at DESC
    ");
    $stmt1->execute([$station_id, $station_id]);
    $merch_waiting_raw = $stmt1->fetchAll(PDO::FETCH_ASSOC);

    $merch_waiting = [];
    foreach ($merch_waiting_raw as $po) {
        $po_ids_arr = explode(',', $po['po_ids']);
        $in_clause = implode(',', array_fill(0, count($po_ids_arr), '?'));
        
        // Check purchase_order_items first
        $item_stmt = $pdo->prepare("
            SELECT item_name, quantity FROM purchase_order_items WHERE po_id IN ($in_clause)
        ");
        $item_stmt->execute($po_ids_arr);
        $items = $item_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($items)) {
            $names = [];
            foreach ($items as $it) {
                $names[] = $it['item_name'] . ' (x' . (int)$it['quantity'] . ')';
            }
            $po['items_list'] = implode(', ', $names);
            $po['products'] = $items;
        } else {
            $po['items_list'] = $po['direct_items_list'];
            $direct_stmt = $pdo->prepare("
                SELECT product_name AS item_name, quantity FROM purchase_orders WHERE id IN ($in_clause)
            ");
            $direct_stmt->execute($po_ids_arr);
            $direct_prods = $direct_stmt->fetchAll(PDO::FETCH_ASSOC);
            $prod_list = [];
            foreach ($direct_prods as $dp) {
                $prod_list[] = [
                    'item_name' => $dp['item_name'],
                    'quantity' => (int)$dp['quantity']
                ];
            }
            $po['products'] = $prod_list;
        }
        $po['pr_type'] = 'Merchandise';
        $merch_waiting[] = $po;
    }

    // Fuel POs grouped by po_number and expected_delivery_date
    $stmt2 = $pdo->prepare("
        SELECT fpo.po_number, fpo.expected_delivery_date, MIN(fpo.created_at) AS created_at, fpo.status,
               u.name AS prepared_by_name,
               fpo.batch_id,
               GROUP_CONCAT(DISTINCT fpo.id) AS fpo_ids
        FROM fuel_purchase_orders fpo
        LEFT JOIN users u ON fpo.created_by = u.id
        WHERE fpo.station_id = ? AND fpo.status IN ('Approved', 'Approved PO', 'Admin Finalized', 'Pending', 'Pending Admin Validation') AND fpo.actual_volume IS NULL
        GROUP BY fpo.po_number, fpo.expected_delivery_date
        ORDER BY created_at DESC
    ");
    $stmt2->execute([$station_id]);
    $fuel_waiting_raw = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    $fuel_waiting = [];
    foreach ($fuel_waiting_raw as $po) {
        $fpo_ids_arr = explode(',', $po['fpo_ids']);
        $in_clause = implode(',', array_fill(0, count($fpo_ids_arr), '?'));
        
        $item_stmt = $pdo->prepare("
            SELECT ft.name AS item_name, fpo.volume AS quantity
            FROM fuel_purchase_orders fpo
            LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
            WHERE fpo.id IN ($in_clause)
        ");
        $item_stmt->execute($fpo_ids_arr);
        $items = $item_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $names = [];
        foreach ($items as $it) {
            $names[] = $it['item_name'] . ' (x' . number_format($it['quantity']) . ' L)';
        }
        $po['items_list'] = implode(', ', $names);
        $po['products'] = $items;
        $po['pr_type'] = 'Fuel';
        $fuel_waiting[] = $po;
    }

    $waiting_deliveries_list = array_merge($merch_waiting, $fuel_waiting);
    usort($waiting_deliveries_list, function($a, $b) {
        return strcmp($b['created_at'], $a['created_at']);
    });

} elseif ($active_tab === 'pending_stock_in') {
    // Unique pending deliveries grouped by delivery_ref
    $stmt = $pdo->prepare("
        SELECT 
            do.id,
            do.delivery_ref,
            do.delivery_type,
            do.supplier,
            do.delivery_date,
            do.dr_number,
            do.source_ref,
            MIN(do.created_at) AS date_recorded,
            u.name AS encoded_by_name,
            GROUP_CONCAT(DISTINCT CONCAT(do.product, ' (x', do.quantity, ' ', do.unit, ')') SEPARATOR ', ') AS items_list
        FROM deliveries_oversight do
        LEFT JOIN users u ON do.encoded_by = u.id
        WHERE do.station_id = ? AND do.status = 'Pending Stock-In'
        GROUP BY do.delivery_ref
        ORDER BY date_recorded DESC
    ");
    $stmt->execute([$station_id]);
    $pending_stock_ins_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $merch_pending_stock_in = [];
    $fuel_pending_stock_in = [];
    foreach ($pending_stock_ins_list as $del) {
        if (strtolower($del['delivery_type']) === 'fuel') {
            $fuel_pending_stock_in[] = $del;
        } else {
            $merch_pending_stock_in[] = $del;
        }
    }

    // Fetch individual items detail grouped by delivery_ref for the JS mapper
    $stmt_items = $pdo->prepare("
        SELECT do.*,
               COALESCE(si.stock_level, ip.stock, 0) AS current_stock,
               COALESCE(si.reorder_level, ip.min_stock, 10) AS reorder_level,
               COALESCE(si.unit, ip.size, 'pcs') AS stock_unit,
               COALESCE(si.cost, ip.unit_cost, 0) AS current_cost,
               COALESCE(si.price, ip.unit_price, 0) AS current_price
        FROM deliveries_oversight do
        LEFT JOIN inventory_products ip ON ip.product_name = do.product AND ip.category != 'Fuel'
        LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = do.station_id
        WHERE do.station_id = ? AND do.status = 'Pending Stock-In'
    ");
    $stmt_items->execute([$station_id]);
    $all_pending_items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

    $pending_items_by_ref = [];
    foreach ($all_pending_items as $item) {
        if ($item['delivery_type'] === 'fuel') {
            $f_stmt = $pdo->prepare("
                SELECT fi.fuel_type_id, fi.current_level, fi.reorder_level, fi.price_per_liter,
                       COALESCE((SELECT unit_price FROM fuel_purchase_orders WHERE po_number = ? AND fuel_type_id = fi.fuel_type_id LIMIT 1), 60.00) AS unit_cost
                FROM fuel_inventory fi
                WHERE LOWER(fi.fuel_type) = LOWER(?) AND fi.station_id = ? LIMIT 1
            ");
            $f_stmt->execute([$item['source_ref'], $item['product'], $station_id]);
            $f_info = $f_stmt->fetch(PDO::FETCH_ASSOC);
            $item['current_stock'] = $f_info ? $f_info['current_level'] : 0;
            $item['reorder_level'] = $f_info ? $f_info['reorder_level'] : 5000;
            $item['current_price'] = $f_info ? $f_info['price_per_liter'] : 60.00;
            $item['current_cost']  = $f_info ? $f_info['unit_cost'] : 55.00;
            $item['stock_unit']    = 'L';
        }
        $pending_items_by_ref[$item['delivery_ref']][] = $item;
    }

} elseif ($active_tab === 'completed') {
    // Unique completed stock-ins
    $stmt = $pdo->prepare("
        SELECT 
            do.delivery_ref,
            do.delivery_type,
            do.supplier,
            do.delivery_date,
            do.dr_number,
            do.source_ref,
            do.updated_at AS date_completed,
            u.name AS encoded_by_name,
            GROUP_CONCAT(DISTINCT CONCAT(do.product, ' (x', do.quantity, ' ', do.unit, ')') SEPARATOR ', ') AS items_list,
            SUM(do.actual_quantity * COALESCE(do.unit_cost, 0)) AS total_cost
        FROM deliveries_oversight do
        LEFT JOIN users u ON do.encoded_by = u.id
        WHERE do.station_id = ? AND do.status = 'Stock-In Complete'
        GROUP BY do.delivery_ref
        ORDER BY date_completed DESC
    ");
    $stmt->execute([$station_id]);
    $completed_stock_ins_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $merch_completed_stock_in = [];
    $fuel_completed_stock_in = [];
    foreach ($completed_stock_ins_list as $comp) {
        if (strtolower($comp['delivery_type']) === 'fuel') {
            $fuel_completed_stock_in[] = $comp;
        } else {
            $merch_completed_stock_in[] = $comp;
        }
    }
}

include __DIR__ . '/../partials/header.php';
?>

<style>
/* Override default .main padding to fit screen from left to right */
body .main {
    padding: 0 !important;
}

/* Modern premium styling */
.pr-container {
    padding: 24px 32px 80px 32px;
    font-family: 'Outfit', 'Inter', sans-serif;
    color: #1e293b;
    background: #f8fafc;
    min-height: calc(100vh - 70px);
    box-sizing: border-box;
    overflow-y: auto;
}
.pr-title {
    font-size: 26px;
    font-weight: 800;
    color: #002F6C;
    margin: 0 0 4px 0;
    display: flex;
    align-items: center;
    gap: 12px;
}
.pr-subtitle {
    font-size: 14px;
    color: #64748b;
    margin-bottom: 24px;
}
.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 20px;
    margin-bottom: 28px;
}
.summary-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 22px;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    display: flex;
    align-items: center;
    justify-content: space-between;
    transition: transform 0.15s ease;
}
.summary-card:hover {
    transform: translateY(-2px);
}
.summary-card-label {
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: .7px;
}
.summary-card-value {
    font-size: 32px;
    font-weight: 800;
    color: #002F6C;
    margin-top: 6px;
}
.summary-icon {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
}
.bg-total { background: #eff6ff; color: #1d4ed8; }
.bg-pending { background: #fffbeb; color: #d97706; }
.bg-waiting { background: #faf5ff; color: #9333ea; }
.bg-completed { background: #f0fdf4; color: #16a34a; }

.tab-nav {
    display: flex;
    gap: 8px;
    margin-bottom: 28px;
    border-bottom: 2px solid #e2e8f0;
    padding-bottom: 1px;
}
.tab-btn {
    padding: 12px 24px;
    font-size: 14px;
    font-weight: 700;
    color: #64748b;
    border: none;
    background: none;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.tab-btn:hover {
    color: #002F6C;
}
.tab-btn.active {
    color: #002F6C;
    border-bottom-color: #002F6C;
}

.table-wrap-pr {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    overflow-x: auto;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    margin-bottom: 24px;
}
.table-pr {
    width: 100%;
    border-collapse: collapse;
    font-size: 13.5px;
}
.table-pr th {
    background: #f8fafc;
    color: #475569;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: .5px;
    padding: 16px 20px;
    border-bottom: 1px solid #e2e8f0;
    text-align: left;
}
.table-pr td {
    padding: 16px 20px;
    border-bottom: 1px solid #e2e8f0;
    color: #334155;
    vertical-align: middle;
}
.table-pr tr:hover td {
    background: #f8fafc;
}
.status-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 9999px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .3px;
}
.status-pending { background: #fef3c7; color: #d97706; }
.status-approved { background: #dcfce7; color: #15803d; }
.status-received { background: #dbeafe; color: #1d4ed8; }
.status-cancelled { background: #fee2e2; color: #b91c1c; }

/* Modal Design with Scrollable Body and Sticky Footer */
.modal-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.65);
    display: none;
    align-items: center; /* Centered vertically */
    justify-content: center; /* Centered horizontally */
    z-index: 10005;
    padding: 20px;
    overflow-y: auto !important;
}
.modal-overlay.open {
    display: flex !important;
}
.modal-box {
    background: #fff;
    border-radius: 16px;
    width: 100%;
    max-width: 950px;
    max-height: calc(100vh - 40px); /* Centered and constrained to screen */
    display: flex;
    flex-direction: column;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    animation: modalFadeIn 0.2s ease-out;
    margin: auto; /* Fallback centering */
}
@keyframes modalFadeIn {
    from { opacity: 0; transform: scale(0.96); }
    to { opacity: 1; transform: scale(1); }
}
.modal-header {
    padding: 20px 24px;
    background: #002F6C;
    color: #fff;
    border-radius: 16px 16px 0 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
}
.modal-title {
    font-size: 17px;
    font-weight: 800;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
    color: #ffffff !important; /* Force title text to be white */
}
.modal-close {
    display: none; /* Hide X button as requested */
}
.modal-body {
    padding: 24px;
    overflow-y: auto; /* Scroll body if content overflows */
    flex: 1 1 auto;
}
.modal-footer {
    padding: 20px 24px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    background: #f8fafc;
    border-radius: 0 0 16px 16px;
    flex-shrink: 0;
}

.field-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 20px;
}
.field-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.field-group label {
    font-size: 11px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: .5px;
}
.field-group input, .field-group select, .field-group textarea {
    padding: 10px 14px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 13px;
    outline: none;
    background: #fff;
    color: #1e293b;
    transition: border-color 0.2s;
}
.field-group input:focus, .field-group select:focus, .field-group textarea:focus {
    border-color: #002F6C;
}
.field-group input[readonly] {
    background: #f1f5f9;
    color: #64748b;
    cursor: not-allowed;
}

.table-inner {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    margin-top: 10px;
}
.table-inner th {
    background: #f1f5f9;
    padding: 12px 14px;
    border-bottom: 2px solid #cbd5e1;
    text-align: left;
    color: #475569;
    font-weight: 700;
}
.table-inner td {
    padding: 12px 14px;
    border-bottom: 1px solid #e2e8f0;
}

.btn-pr {
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border: 1px solid transparent;
    transition: all 0.15s;
    text-decoration: none;
}
.btn-primary-pr {
    background: #002F6C;
    color: #fff;
}
.btn-primary-pr:hover {
    background: #001f4d;
}
.btn-outline-pr {
    background: #fff;
    border-color: #cbd5e1;
    color: #475569;
}
.btn-outline-pr:hover {
    background: #f8fafc;
    border-color: #94a3b8;
}
.btn-danger-pr {
    background: #fff;
    border-color: #fca5a5;
    color: #b91c1c;
}
.btn-danger-pr:hover {
    background: #fee2e2;
}

.action-bar {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
}
</style>

<div class="pr-container">

    <!-- Header -->
    <div style="margin-bottom: 28px;">
        <h1 class="pr-title">
            <i class="fas fa-clipboard-list" style="color: #002F6C;"></i> Purchase Requests & Stock-In
        </h1>
        <div class="pr-subtitle">
            Manage your station's procurement pipeline: review stock requests, track waiting deliveries, and stock-in received items.
        </div>
    </div>

    <!-- Alert Notifications -->
    <?php if (!empty($_SESSION['success'])): ?>
        <div style="background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-check-circle" style="font-size: 18px;"></i> <?= $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error'])): ?>
        <div style="background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-exclamation-circle" style="font-size: 18px;"></i> <?= $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <!-- Summary Metrics -->
    <div class="summary-grid">
        <a href="manager_stock_request_review.php?tab=pending_requests" style="text-decoration: none;">
            <div class="summary-card">
                <div>
                    <div class="summary-card-label">Pending Requests</div>
                    <div class="summary-card-value"><?= number_format($total_pending_requests) ?></div>
                </div>
                <div class="summary-icon bg-pending"><i class="fas fa-hourglass-half"></i></div>
            </div>
        </a>
        <a href="manager_stock_request_review.php?tab=waiting_delivery" style="text-decoration: none;">
            <div class="summary-card">
                <div>
                    <div class="summary-card-label">Waiting Delivery</div>
                    <div class="summary-card-value" style="color: #9333ea;"><?= number_format($total_waiting_delivery) ?></div>
                </div>
                <div class="summary-icon bg-waiting"><i class="fas fa-truck"></i></div>
            </div>
        </a>
        <a href="manager_stock_request_review.php?tab=pending_stock_in" style="text-decoration: none;">
            <div class="summary-card">
                <div>
                    <div class="summary-card-label">Pending Stock-In</div>
                    <div class="summary-card-value" style="color: #1d4ed8;"><?= number_format($total_pending_stock_in) ?></div>
                </div>
                <div class="summary-icon bg-total"><i class="fas fa-boxes"></i></div>
            </div>
        </a>
        <a href="manager_stock_request_review.php?tab=completed" style="text-decoration: none;">
            <div class="summary-card">
                <div>
                    <div class="summary-card-label">Completed</div>
                    <div class="summary-card-value" style="color: #16a34a;"><?= number_format($total_completed) ?></div>
                </div>
                <div class="summary-icon bg-completed"><i class="fas fa-check-circle"></i></div>
            </div>
        </a>
    </div>

    <!-- Navigation Tabs -->
    <div class="tab-nav">
        <a href="manager_stock_request_review.php?tab=pending_requests" class="tab-btn <?= $active_tab === 'pending_requests' ? 'active' : '' ?>">
            <i class="fas fa-hourglass-half"></i> Pending Requests
        </a>
        <a href="manager_stock_request_review.php?tab=waiting_delivery" class="tab-btn <?= $active_tab === 'waiting_delivery' ? 'active' : '' ?>">
            <i class="fas fa-truck"></i> Waiting Delivery
        </a>
        <a href="manager_stock_request_review.php?tab=pending_stock_in" class="tab-btn <?= $active_tab === 'pending_stock_in' ? 'active' : '' ?>">
            <i class="fas fa-boxes"></i> Pending Stock-In
        </a>
        <a href="manager_stock_request_review.php?tab=completed" class="tab-btn <?= $active_tab === 'completed' ? 'active' : '' ?>">
            <i class="fas fa-check-circle"></i> Completed
        </a>
    </div>
    <!-- ==================== TAB 1: PENDING REQUESTS ==================== -->
    <?php if ($active_tab === 'pending_requests'): ?>
        <!-- Mini Sub Tabs for Pending Requests -->
        <div class="sub-tab-nav" style="display: flex; gap: 8px; margin-bottom: 20px;">
            <button type="button" class="sub-tab-btn active" id="subtabMerchBtn" onclick="switchPendingSubTab('merch')" style="padding: 10px 20px; font-size: 13px; font-weight: 700; color: #fff; border: none; background: #002F6C; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px; border-radius: 8px;">
                <i class="fas fa-boxes"></i> Merchandise Requests
            </button>
            <button type="button" class="sub-tab-btn" id="subtabFuelBtn" onclick="switchPendingSubTab('fuel')" style="padding: 10px 20px; font-size: 13px; font-weight: 700; color: #64748b; border: 1px solid #cbd5e1; background: #fff; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px; border-radius: 8px;">
                <i class="fas fa-gas-pump"></i> Fuel Requests
            </button>
        </div>

        <!-- Merchandise Section -->
        <div id="pendingMerchSection" class="procurement-section" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <h4 style="margin: 0; color: #002F6C; font-size: 16px; font-weight: 700; display: flex; align-items: center; gap: 8px;"><i class="fas fa-boxes"></i> Merchandise Pending Requests</h4>
                <button type="button" class="btn-pr btn-primary-pr" onclick="loadPendingMerchRequests()" style="padding: 8px 16px; font-size: 13px; display: flex; align-items: center; gap: 6px;"><i class="fas fa-boxes"></i> Generate Merchandise PO</button>
            </div>
            <div class="table-wrap-pr">
                <table class="table-pr">
                    <thead>
                        <tr>
                            <th>Request Ref</th>
                            <th>Product Name</th>
                            <th>Current Stock</th>
                            <th>Reorder Level</th>
                            <th>Requested Qty</th>
                            <th>Requested By</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($merch_reqs)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 36px; color: #64748b;">
                                    <i class="fas fa-inbox" style="font-size: 30px; color: #cbd5e1; display: block; margin-bottom: 8px;"></i>
                                    No pending merchandise stock requests found.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($merch_reqs as $sr): 
                                $ref = 'REQ-' . str_pad($sr['id'], 5, '0', STR_PAD_LEFT);
                            ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($ref) ?></strong></td>
                                    <td><strong><?= htmlspecialchars($sr['item_title']) ?></strong></td>
                                    <td><?= number_format($sr['current_stock']) ?> <?= htmlspecialchars($sr['unit']) ?></td>
                                    <td><?= number_format($sr['reorder_level']) ?> <?= htmlspecialchars($sr['unit']) ?></td>
                                    <td><strong style="color: #002F6C;"><?= number_format($sr['requested_qty']) ?> <?= htmlspecialchars($sr['unit']) ?></strong></td>
                                    <td><?= htmlspecialchars($sr['staff_name'] ?: 'Staff') ?></td>
                                    <td><?= date('M d, Y h:i A', strtotime($sr['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Fuel Section -->
        <div id="pendingFuelSection" class="procurement-section" style="display: none; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <h4 style="margin: 0; color: #0284c7; font-size: 16px; font-weight: 700; display: flex; align-items: center; gap: 8px;"><i class="fas fa-gas-pump"></i> Fuel Pending Requests</h4>
                <button type="button" class="btn-pr btn-primary-pr" onclick="loadPendingFuelRequests()" style="padding: 8px 16px; font-size: 13px; background: #0284c7; display: flex; align-items: center; gap: 6px;"><i class="fas fa-gas-pump"></i> Generate Fuel PO</button>
            </div>
            <div class="table-wrap-pr">
                <table class="table-pr">
                    <thead>
                        <tr>
                            <th>Request Ref</th>
                            <th>Fuel Type</th>
                            <th>Current Liters</th>
                            <th>Reorder Level</th>
                            <th>Requested Liters</th>
                            <th>Requested By</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($fuel_reqs)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 36px; color: #64748b;">
                                    <i class="fas fa-gas-pump" style="font-size: 30px; color: #cbd5e1; display: block; margin-bottom: 8px;"></i>
                                    No pending fuel stock requests found.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($fuel_reqs as $sr): 
                                $ref = 'FSR-' . str_pad($sr['id'], 5, '0', STR_PAD_LEFT);
                            ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($ref) ?></strong></td>
                                    <td><strong><?= htmlspecialchars($sr['item_title']) ?></strong></td>
                                    <td><?= number_format($sr['current_stock']) ?> L</td>
                                    <td><?= number_format($sr['reorder_level']) ?> L</td>
                                    <td><strong style="color: #0284c7;"><?= number_format($sr['requested_qty']) ?> L</strong></td>
                                    <td><?= htmlspecialchars($sr['staff_name'] ?: 'Staff') ?></td>
                                    <td><?= date('M d, Y h:i A', strtotime($sr['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <!-- Merchandise PR Generation Section (Hidden by Default) -->
        <div id="merchPrFormContainer" style="display: none; background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 24px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); margin-bottom: 60px;">
            <h3 style="color: #002F6C; margin-top: 0; margin-bottom: 20px; display: flex; align-items: center; gap: 8px;"><i class="fas fa-boxes"></i> Generate Merchandise Purchase Request</h3>
            <form action="" method="POST">
                <input type="hidden" name="action" value="generate_merch_pr">
                <div class="field-grid">
                    <div class="field-group">
                        <label>PR Number (Auto-Gen)</label>
                        <input type="text" name="pr_number" placeholder="PR-YYYYMMDD-XXXX" readonly value="PR-<?= date('Ymd') ?>-<?= str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT) ?>">
                    </div>
                    <div class="field-group">
                        <label>Expected Delivery Date</label>
                        <input type="date" name="expected_delivery" min="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>

                <table class="table-inner" style="margin-bottom: 20px;">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Current Stock</th>
                            <th>Reorder Level</th>
                            <th>Requested Qty</th>
                            <th style="width: 150px;">Unit of Measure (UOM)</th>
                            <th style="width: 150px;">Quantity to Order</th>
                            <th style="text-align: center; width: 60px;">Remove</th>
                        </tr>
                    </thead>
                    <tbody id="merchPrItemsBody">
                        <!-- Populated by JS -->
                    </tbody>
                </table>

                <div class="field-group" style="margin-bottom: 20px;">
                    <label>Remarks / Notes</label>
                    <textarea name="remarks" rows="2" placeholder="Optional notes for Supplier or Admin..."></textarea>
                </div>

                <div style="text-align: right;">
                    <button type="submit" class="btn-pr btn-primary-pr"><i class="fas fa-file-invoice"></i> Generate Purchase Request</button>
                </div>
            </form>
        </div>

        <!-- Fuel PR Generation Section (Hidden by Default) -->
        <div id="fuelPrFormContainer" style="display: none; background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 24px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); margin-bottom: 60px;">
            <h3 style="color: #002F6C; margin-top: 0; margin-bottom: 20px; display: flex; align-items: center; gap: 8px;"><i class="fas fa-gas-pump"></i> Generate Fuel Purchase Request</h3>
            <form action="" method="POST">
                <input type="hidden" name="action" value="generate_fuel_pr">
                <div class="field-grid">
                    <div class="field-group">
                        <label>PR Number (Auto-Gen)</label>
                        <input type="text" name="pr_number" placeholder="FPR-YYYYMMDD-XXXX" readonly value="FPR-<?= date('Ymd') ?>-<?= str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT) ?>">
                    </div>
                    <div class="field-group">
                        <label>Expected Delivery Date</label>
                        <input type="date" name="expected_delivery" min="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>

                <table class="table-inner" style="margin-bottom: 20px;">
                    <thead>
                        <tr>
                            <th>Fuel Type</th>
                            <th>Current Liters</th>
                            <th>Reorder Level</th>
                            <th>Requested Liters</th>
                            <th style="width: 200px;">Liters to Order</th>
                        </tr>
                    </thead>
                    <tbody id="fuelPrItemsBody">
                        <!-- Populated by JS or pre-rendered list -->
                    </tbody>
                </table>

                <div class="field-group" style="margin-bottom: 20px;">
                    <label>Remarks / Notes</label>
                    <textarea name="remarks" rows="2" placeholder="Optional tanker instructions..."></textarea>
                </div>

                <div style="text-align: right;">
                    <button type="submit" class="btn-pr btn-primary-pr"><i class="fas fa-file-invoice"></i> Generate Purchase Request</button>
                </div>
            </form>
        </div>

    <!-- ==================== TAB 2: WAITING DELIVERY ==================== -->
    <?php elseif ($active_tab === 'waiting_delivery'): ?>
        <!-- Mini Sub Tabs for Waiting Deliveries -->
        <div class="sub-tab-nav" style="display: flex; gap: 8px; margin-bottom: 20px;">
            <button type="button" class="sub-tab-btn active" id="subtabWaitingMerchBtn" onclick="switchWaitingSubTab('merch')" style="padding: 10px 20px; font-size: 13px; font-weight: 700; color: #fff; border: none; background: #002F6C; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px; border-radius: 8px;">
                <i class="fas fa-boxes"></i> Merchandise Deliveries
            </button>
            <button type="button" class="sub-tab-btn" id="subtabWaitingFuelBtn" onclick="switchWaitingSubTab('fuel')" style="padding: 10px 20px; font-size: 13px; font-weight: 700; color: #64748b; border: 1px solid #cbd5e1; background: #fff; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px; border-radius: 8px;">
                <i class="fas fa-gas-pump"></i> Fuel Deliveries
            </button>
        </div>

        <!-- Merchandise Waiting Section -->
        <div id="waitingMerchSection" class="procurement-section" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
            <h4 style="margin: 0 0 16px 0; color: #002F6C; font-size: 16px; font-weight: 700; display: flex; align-items: center; gap: 8px;"><i class="fas fa-boxes"></i> Merchandise Deliveries Awaiting Arrival</h4>
            <div class="table-wrap-pr">
                <table class="table-pr">
                    <thead>
                        <tr>
                            <th>PR/PO No.</th>
                            <th>Items / Product List</th>
                            <th>Request Date</th>
                            <th>Expected Delivery</th>
                            <th>Status</th>
                            <th>Prepared By</th>
                            <th style="text-align: right; width: 120px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($merch_waiting)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 36px; color: #64748b;">
                                    <i class="fas fa-truck-loading" style="font-size: 30px; color: #cbd5e1; display: block; margin-bottom: 8px;"></i>
                                    No merchandise requests waiting for delivery.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($merch_waiting as $po): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($po['po_number']) ?></strong></td>
                                    <td><span style="font-size:12.5px; color:#475569;"><?= htmlspecialchars($po['items_list']) ?></span></td>
                                    <td><?= date('M d, Y', strtotime($po['created_at'])) ?></td>
                                    <td><?= $po['expected_delivery_date'] ? date('M d, Y', strtotime($po['expected_delivery_date'])) : '—' ?></td>
                                    <td><span class="status-badge status-pending">Waiting Delivery</span></td>
                                    <td><?= htmlspecialchars($po['prepared_by_name'] ?: 'Manager') ?></td>
                                    <td style="text-align: right;">
                                        <button type="button" class="btn-pr btn-primary-pr" style="padding: 6px 12px; font-size: 11.5px;" 
                                                onclick="viewPurchaseOrder(this)"
                                                data-po-number="<?= htmlspecialchars($po['po_number']) ?>"
                                                data-type="<?= $po['pr_type'] ?>"
                                                data-date="<?= date('M d, Y', strtotime($po['created_at'])) ?>"
                                                data-expected="<?= $po['expected_delivery_date'] ? date('M d, Y', strtotime($po['expected_delivery_date'])) : '—' ?>"
                                                data-prepared="<?= htmlspecialchars($po['prepared_by_name'] ?: 'Manager') ?>"
                                                data-status="<?= htmlspecialchars($po['status']) ?>"
                                                data-items='<?= json_encode($po['products']) ?>'>
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Fuel Waiting Section -->
        <div id="waitingFuelSection" class="procurement-section" style="display: none; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
            <h4 style="margin: 0 0 16px 0; color: #0284c7; font-size: 16px; font-weight: 700; display: flex; align-items: center; gap: 8px;"><i class="fas fa-gas-pump"></i> Fuel Deliveries Awaiting Arrival</h4>
            <div class="table-wrap-pr">
                <table class="table-pr">
                    <thead>
                        <tr>
                            <th>PR/PO No.</th>
                            <th>Fuel Type & Volume</th>
                            <th>Request Date</th>
                            <th>Expected Delivery</th>
                            <th>Status</th>
                            <th>Prepared By</th>
                            <th style="text-align: right; width: 120px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($fuel_waiting)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 36px; color: #64748b;">
                                    <i class="fas fa-gas-pump" style="font-size: 30px; color: #cbd5e1; display: block; margin-bottom: 8px;"></i>
                                    No fuel requests waiting for delivery.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($fuel_waiting as $po): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($po['po_number']) ?></strong></td>
                                    <td><span style="font-size:12.5px; color:#475569;"><?= htmlspecialchars($po['items_list']) ?></span></td>
                                    <td><?= date('M d, Y', strtotime($po['created_at'])) ?></td>
                                    <td><?= $po['expected_delivery_date'] ? date('M d, Y', strtotime($po['expected_delivery_date'])) : '—' ?></td>
                                    <td><span class="status-badge status-pending">Waiting Delivery</span></td>
                                    <td><?= htmlspecialchars($po['prepared_by_name'] ?: 'Manager') ?></td>
                                    <td style="text-align: right;">
                                        <button type="button" class="btn-pr btn-primary-pr" style="padding: 6px 12px; font-size: 11.5px;" 
                                                onclick="viewPurchaseOrder(this)"
                                                data-po-number="<?= htmlspecialchars($po['po_number']) ?>"
                                                data-type="<?= $po['pr_type'] ?>"
                                                data-date="<?= date('M d, Y', strtotime($po['created_at'])) ?>"
                                                data-expected="<?= $po['expected_delivery_date'] ? date('M d, Y', strtotime($po['expected_delivery_date'])) : '—' ?>"
                                                data-prepared="<?= htmlspecialchars($po['prepared_by_name'] ?: 'Manager') ?>"
                                                data-status="<?= htmlspecialchars($po['status']) ?>"
                                                data-items='<?= json_encode($po['products']) ?>'>
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <!-- ==================== TAB 3: PENDING STOCK-IN ==================== -->
    <?php elseif ($active_tab === 'pending_stock_in'): ?>
        <!-- Mini Sub Tabs for Pending Stock-In -->
        <div class="sub-tab-nav" style="display: flex; gap: 8px; margin-bottom: 20px;">
            <button type="button" class="sub-tab-btn active" id="subtabPendingMerchBtn" onclick="switchPendingStockInSubTab('merch')" style="padding: 10px 20px; font-size: 13px; font-weight: 700; color: #fff !important; background: #002F6C; border: none; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px; border-radius: 8px;">
                <i class="fas fa-boxes"></i> Merchandise Stock-In
            </button>
            <button type="button" class="sub-tab-btn" id="subtabPendingFuelBtn" onclick="switchPendingStockInSubTab('fuel')" style="padding: 10px 20px; font-size: 13px; font-weight: 700; color: #64748b !important; background: #fff; border: 1px solid #cbd5e1; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px; border-radius: 8px;">
                <i class="fas fa-gas-pump"></i> Fuel Stock-In
            </button>
        </div>

        <!-- Merchandise Pending Stock-In Section -->
        <div id="pendingStockInMerchSection" class="procurement-section">
            <div class="table-wrap-pr">
                <table class="table-pr">
                    <thead>
                        <tr>
                            <th>Delivery Ref</th>
                            <th>PR No.</th>
                            <th>DR/Invoice No.</th>
                            <th>Type</th>
                            <th>Supplier</th>
                            <th>Items Received</th>
                            <th>Recorded By</th>
                            <th>Date Recorded</th>
                            <th style="text-align: right; width: 120px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($merch_pending_stock_in)): ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 48px; color: #64748b;">
                                    <i class="fas fa-boxes" style="font-size: 36px; color: #cbd5e1; display: block; margin-bottom: 12px;"></i>
                                    No pending merchandise deliveries awaiting stock-in approval.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($merch_pending_stock_in as $del): ?>
                                <tr>
                                    <td><strong style="color: #002F6C;"><?= htmlspecialchars($del['delivery_ref']) ?></strong></td>
                                    <td><strong><?= htmlspecialchars($del['source_ref'] ?: 'Manual') ?></strong></td>
                                    <td><?= htmlspecialchars($del['dr_number'] ?: '—') ?></td>
                                    <td><span class="status-badge" style="background:#f0fdf4; color:#16a34a;"><?= ucfirst($del['delivery_type']) ?></span></td>
                                    <td><?= htmlspecialchars($del['supplier']) ?></td>
                                    <td style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><span style="font-size: 12.5px;"><?= htmlspecialchars($del['items_list']) ?></span></td>
                                    <td><?= htmlspecialchars($del['encoded_by_name'] ?: 'Staff') ?></td>
                                    <td><?= date('M d, Y h:i A', strtotime($del['date_recorded'])) ?></td>
                                    <td style="text-align: right;">
                                        <button type="button" class="btn-pr btn-primary-pr" style="padding: 6px 12px; font-size: 11.5px;" onclick="openReviewModal('<?= htmlspecialchars($del['delivery_ref']) ?>')"><i class="fas fa-clipboard-check"></i> Review</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Fuel Pending Stock-In Section -->
        <div id="pendingStockInFuelSection" class="procurement-section" style="display: none;">
            <div class="table-wrap-pr">
                <table class="table-pr">
                    <thead>
                        <tr>
                            <th>Delivery Ref</th>
                            <th>PR No.</th>
                            <th>DR/Invoice No.</th>
                            <th>Type</th>
                            <th>Supplier</th>
                            <th>Items Received</th>
                            <th>Recorded By</th>
                            <th>Date Recorded</th>
                            <th style="text-align: right; width: 120px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($fuel_pending_stock_in)): ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 48px; color: #64748b;">
                                    <i class="fas fa-gas-pump" style="font-size: 36px; color: #cbd5e1; display: block; margin-bottom: 12px;"></i>
                                    No pending fuel deliveries awaiting stock-in approval.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($fuel_pending_stock_in as $del): ?>
                                <tr>
                                    <td><strong style="color: #002F6C;"><?= htmlspecialchars($del['delivery_ref']) ?></strong></td>
                                    <td><strong><?= htmlspecialchars($del['source_ref'] ?: 'Manual') ?></strong></td>
                                    <td><?= htmlspecialchars($del['dr_number'] ?: '—') ?></td>
                                    <td><span class="status-badge" style="background:#f0fdf4; color:#16a34a;"><?= ucfirst($del['delivery_type']) ?></span></td>
                                    <td><?= htmlspecialchars($del['supplier']) ?></td>
                                    <td style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><span style="font-size: 12.5px;"><?= htmlspecialchars($del['items_list']) ?></span></td>
                                    <td><?= htmlspecialchars($del['encoded_by_name'] ?: 'Staff') ?></td>
                                    <td><?= date('M d, Y h:i A', strtotime($del['date_recorded'])) ?></td>
                                    <td style="text-align: right;">
                                        <button type="button" class="btn-pr btn-primary-pr" style="padding: 6px 12px; font-size: 11.5px;" onclick="openReviewModal('<?= htmlspecialchars($del['delivery_ref']) ?>')"><i class="fas fa-clipboard-check"></i> Review</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <!-- ==================== TAB 4: COMPLETED ==================== -->
    <?php elseif ($active_tab === 'completed'): ?>
        <!-- Mini Sub Tabs for Completed -->
        <div class="sub-tab-nav" style="display: flex; gap: 8px; margin-bottom: 20px;">
            <button type="button" class="sub-tab-btn active" id="subtabCompletedMerchBtn" onclick="switchCompletedSubTab('merch')" style="padding: 10px 20px; font-size: 13px; font-weight: 700; color: #fff !important; background: #002F6C; border: none; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px; border-radius: 8px;">
                <i class="fas fa-boxes"></i> Merchandise Completed
            </button>
            <button type="button" class="sub-tab-btn" id="subtabCompletedFuelBtn" onclick="switchCompletedSubTab('fuel')" style="padding: 10px 20px; font-size: 13px; font-weight: 700; color: #64748b !important; background: #fff; border: 1px solid #cbd5e1; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px; border-radius: 8px;">
                <i class="fas fa-gas-pump"></i> Fuel Completed
            </button>
        </div>

        <!-- Merchandise Completed Section -->
        <div id="completedMerchSection" class="procurement-section">
            <div class="table-wrap-pr">
                <table class="table-pr">
                    <thead>
                        <tr>
                            <th>Delivery Ref</th>
                            <th>PR No.</th>
                            <th>DR/Invoice No.</th>
                            <th>Type</th>
                            <th>Supplier</th>
                            <th>Items Received</th>
                            <th>Total Cost</th>
                            <th>Date Stocked-In</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($merch_completed_stock_in)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 48px; color: #64748b;">
                                    <i class="fas fa-check-double" style="font-size: 36px; color: #cbd5e1; display: block; margin-bottom: 12px;"></i>
                                    No completed merchandise stock-ins found.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($merch_completed_stock_in as $comp): ?>
                                <tr>
                                    <td><strong style="color: #16a34a;"><?= htmlspecialchars($comp['delivery_ref']) ?></strong></td>
                                    <td><?= htmlspecialchars($comp['source_ref'] ?: 'Manual') ?></td>
                                    <td><?= htmlspecialchars($comp['dr_number'] ?: '—') ?></td>
                                    <td><span class="status-badge" style="background:#f1f5f9; color:#475569;"><?= ucfirst($comp['delivery_type']) ?></span></td>
                                    <td><?= htmlspecialchars($comp['supplier']) ?></td>
                                    <td><span style="font-size: 12.5px;"><?= htmlspecialchars($comp['items_list']) ?></span></td>
                                    <td><strong>₱<?= number_format($comp['total_cost'], 2) ?></strong></td>
                                    <td><?= date('M d, Y h:i A', strtotime($comp['date_completed'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Fuel Completed Section -->
        <div id="completedFuelSection" class="procurement-section" style="display: none;">
            <div class="table-wrap-pr">
                <table class="table-pr">
                    <thead>
                        <tr>
                            <th>Delivery Ref</th>
                            <th>PR No.</th>
                            <th>DR/Invoice No.</th>
                            <th>Type</th>
                            <th>Supplier</th>
                            <th>Items Received</th>
                            <th>Total Cost</th>
                            <th>Date Stocked-In</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($fuel_completed_stock_in)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 48px; color: #64748b;">
                                    <i class="fas fa-check-double" style="font-size: 36px; color: #cbd5e1; display: block; margin-bottom: 12px;"></i>
                                    No completed fuel stock-ins found.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($fuel_completed_stock_in as $comp): ?>
                                <tr>
                                    <td><strong style="color: #16a34a;"><?= htmlspecialchars($comp['delivery_ref']) ?></strong></td>
                                    <td><?= htmlspecialchars($comp['source_ref'] ?: 'Manual') ?></td>
                                    <td><?= htmlspecialchars($comp['dr_number'] ?: '—') ?></td>
                                    <td><span class="status-badge" style="background:#f1f5f9; color:#475569;"><?= ucfirst($comp['delivery_type']) ?></span></td>
                                    <td><?= htmlspecialchars($comp['supplier']) ?></td>
                                    <td><span style="font-size: 12.5px;"><?= htmlspecialchars($comp['items_list']) ?></span></td>
                                    <td><strong>₱<?= number_format($comp['total_cost'], 2) ?></strong></td>
                                    <td><?= date('M d, Y h:i A', strtotime($comp['date_completed'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

</div>

<!-- ════════════════════════════════════════════════════════════
     MODAL: STOCK-IN REVIEW
     ════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="reviewModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-clipboard-check"></i> Stock-In Verification</h3>
        </div>
        <form action="" method="POST" id="reviewForm">
            <input type="hidden" name="action" value="approve_stock_in">
            <input type="hidden" name="delivery_ref" id="revDeliveryRef">
            
            <div class="modal-body">
                <div class="field-grid">
                    <div class="field-group">
                        <label>Batch ID No.</label>
                        <input type="text" id="lblBatchId" readonly style="background: #f1f5f9; font-weight: 600; color: #002F6C;">
                    </div>
                    <div class="field-group">
                        <label>Delivery Reference</label>
                        <input type="text" id="lblDeliveryRef" readonly>
                    </div>
                    <div class="field-group">
                        <label>PR Number / Ref</label>
                        <input type="text" id="lblSourceRef" readonly>
                    </div>
                </div>

                <div class="field-grid">
                    <div class="field-group">
                        <label>DR / Invoice Number</label>
                        <input type="text" id="lblDrNumber" readonly>
                    </div>
                    <div class="field-group">
                        <label>Supplier</label>
                        <input type="text" id="lblSupplier" readonly>
                    </div>
                    <div class="field-group">
                        <label>Delivery Date</label>
                        <input type="text" id="lblDeliveryDate" readonly>
                    </div>
                </div>

                <div class="field-grid">
                    <div class="field-group">
                        <label>Condition</label>
                        <input type="text" name="condition" id="txtCondition" placeholder="e.g. Good, 3 units short, etc." required value="Good">
                    </div>
                </div>

                <div style="margin-top: 20px;">
                    <label style="font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: .5px; display: block; margin-bottom: 8px;">Delivery Items List</label>
                    <table class="table-inner">
                        <thead id="reviewTableHead">
                            <!-- Populated dynamically based on type -->
                        </thead>
                        <tbody id="reviewTableBody">
                            <!-- Populated dynamically -->
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-pr btn-danger-pr" onclick="openReturnModal()"><i class="fas fa-undo"></i> Return for Correction</button>
                <button type="button" class="btn-pr btn-outline-pr" onclick="closeModal('reviewModal')">❌ Cancel</button>
                <button type="submit" class="btn-pr btn-primary-pr">✅ Approve Stock-In</button>
            </div>
        </form>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     MODAL: RETURN FOR CORRECTION REASON
     ════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="returnModal" style="z-index: 10010;">
    <div class="modal-box" style="max-width: 500px;">
        <div class="modal-header" style="background: #b91c1c;">
            <h3 class="modal-title"><i class="fas fa-undo"></i> Return Delivery for Correction</h3>
        </div>
        <form action="" method="POST">
            <input type="hidden" name="action" value="return_stock_in">
            <input type="hidden" name="delivery_ref" id="retDeliveryRef">
            
            <div class="modal-body">
                <p style="font-size: 13.5px; color: #475569; margin-top: 0;">Please specify the reason for returning this delivery record. The staff will be notified to verify and correct it.</p>
                <div class="field-group">
                    <label>Reason / Notes</label>
                    <textarea name="reason" rows="4" required placeholder="Describe discrepancy, e.g., incorrect received volume, incorrect product mapping, unit cost typo..."></textarea>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-pr btn-outline-pr" onclick="closeModal('returnModal')">Cancel</button>
                <button type="submit" class="btn-pr" style="background: #b91c1c; color: #fff;"><i class="fas fa-paper-plane"></i> Send to Staff</button>
            </div>
        </form>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     MODAL: VIEW PURCHASE ORDER DETAILS
     ════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="viewPoModal" style="z-index: 10010;">
    <div class="modal-box" style="max-width: 600px;">
        <div class="modal-header" style="background: #002F6C; color: #fff;">
            <h3 class="modal-title" id="viewPoTitle"><i class="fas fa-file-invoice"></i> Purchase Order Details</h3>
        </div>
        <div class="modal-body" style="padding: 24px;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px; font-size: 13.5px; border-bottom: 1px solid #e2e8f0; padding-bottom: 16px;">
                <div>
                    <p style="margin: 4px 0;"><strong>PO Number:</strong> <span id="viewPoNum" style="font-weight: 700; color: #002F6C;"></span></p>
                    <p style="margin: 4px 0;"><strong>Type:</strong> <span id="viewPoType" style="font-weight: 600;"></span></p>
                    <p style="margin: 4px 0;"><strong>Status:</strong> <span class="status-badge" style="background:#eff6ff; color:#1d4ed8;" id="viewPoStatus"></span></p>
                </div>
                <div>
                    <p style="margin: 4px 0;"><strong>Request Date:</strong> <span id="viewPoDate"></span></p>
                    <p style="margin: 4px 0;"><strong>Expected Delivery:</strong> <span id="viewPoExpected"></span></p>
                    <p style="margin: 4px 0;"><strong>Prepared By:</strong> <span id="viewPoPrepared"></span></p>
                </div>
            </div>
            
            <h4 style="margin: 0 0 12px 0; color: #002F6C; font-size: 14px; font-weight: 700;"><i class="fas fa-list"></i> Order Items</h4>
            <div class="table-wrap-pr" style="margin-bottom: 0; max-height: 300px; overflow-y: auto;">
                <table class="table-pr">
                    <thead>
                        <tr>
                            <th>Item / Product</th>
                            <th style="text-align: right; width: 150px;">Quantity Ordered</th>
                        </tr>
                    </thead>
                    <tbody id="viewPoItemsBody">
                        <!-- Populated dynamically -->
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-footer" style="background: #f8fafc; border-top: 1px solid #e2e8f0; padding: 12px 24px; text-align: right;">
            <button type="button" class="btn-pr btn-outline-pr" onclick="closeModal('viewPoModal')">Close</button>
        </div>
    </div>
</div>

<script>
// JSON payloads injected from backend
var pendingItemsMap = <?= json_encode($pending_items_by_ref ?? []) ?>;
var allProductsList = <?= json_encode($all_station_products ?? []) ?>;
var fuelInventoryList = <?= json_encode($fuel_inventory_list ?? []) ?>;

var pendingMerchRequests = <?= json_encode($merch_reqs ?? []) ?>;
var pendingFuelRequests = <?= json_encode($fuel_reqs ?? []) ?>;

// Switch sub-tab for pending requests
function switchPendingSubTab(type) {
    var merchBtn = document.getElementById('subtabMerchBtn');
    var fuelBtn = document.getElementById('subtabFuelBtn');
    var merchSec = document.getElementById('pendingMerchSection');
    var fuelSec = document.getElementById('pendingFuelSection');
    
    // Hide active PR forms on subtab switch
    document.getElementById('merchPrFormContainer').style.display = 'none';
    document.getElementById('fuelPrFormContainer').style.display = 'none';
    
    if (type === 'merch') {
        merchBtn.classList.add('active');
        merchBtn.style.color = '#fff';
        merchBtn.style.background = '#002F6C';
        merchBtn.style.border = 'none';
        
        fuelBtn.classList.remove('active');
        fuelBtn.style.color = '#64748b';
        fuelBtn.style.background = '#fff';
        fuelBtn.style.border = '1px solid #cbd5e1';
        
        merchSec.style.display = 'block';
        fuelSec.style.display = 'none';
    } else {
        fuelBtn.classList.add('active');
        fuelBtn.style.color = '#fff';
        fuelBtn.style.background = '#002F6C';
        fuelBtn.style.border = 'none';
        
        merchBtn.classList.remove('active');
        merchBtn.style.color = '#64748b';
        merchBtn.style.background = '#fff';
        merchBtn.style.border = '1px solid #cbd5e1';
        
        fuelSec.style.display = 'block';
        merchSec.style.display = 'none';
    }
}

// Switch sub-tab for waiting deliveries
function switchWaitingSubTab(type) {
    var merchBtn = document.getElementById('subtabWaitingMerchBtn');
    var fuelBtn = document.getElementById('subtabWaitingFuelBtn');
    var merchSec = document.getElementById('waitingMerchSection');
    var fuelSec = document.getElementById('waitingFuelSection');
    
    if (type === 'merch') {
        merchBtn.classList.add('active');
        merchBtn.style.color = '#fff';
        merchBtn.style.background = '#002F6C';
        merchBtn.style.border = 'none';
        
        fuelBtn.classList.remove('active');
        fuelBtn.style.color = '#64748b';
        fuelBtn.style.background = '#fff';
        fuelBtn.style.border = '1px solid #cbd5e1';
        
        merchSec.style.display = 'block';
        fuelSec.style.display = 'none';
    } else {
        fuelBtn.classList.add('active');
        fuelBtn.style.color = '#fff';
        fuelBtn.style.background = '#002F6C';
        fuelBtn.style.border = 'none';
        
        merchBtn.classList.remove('active');
        merchBtn.style.color = '#64748b';
        merchBtn.style.background = '#fff';
        merchBtn.style.border = '1px solid #cbd5e1';
        
        fuelSec.style.display = 'block';
        merchSec.style.display = 'none';
    }
}

// Switch sub-tab for pending stock-ins
function switchPendingStockInSubTab(type) {
    var merchBtn = document.getElementById('subtabPendingMerchBtn');
    var fuelBtn = document.getElementById('subtabPendingFuelBtn');
    var merchSec = document.getElementById('pendingStockInMerchSection');
    var fuelSec = document.getElementById('pendingStockInFuelSection');
    
    if (type === 'merch') {
        merchBtn.classList.add('active');
        merchBtn.style.color = '#fff';
        merchBtn.style.background = '#002F6C';
        merchBtn.style.border = 'none';
        
        fuelBtn.classList.remove('active');
        fuelBtn.style.color = '#64748b';
        fuelBtn.style.background = '#fff';
        fuelBtn.style.border = '1px solid #cbd5e1';
        
        merchSec.style.display = 'block';
        fuelSec.style.display = 'none';
    } else {
        fuelBtn.classList.add('active');
        fuelBtn.style.color = '#fff';
        fuelBtn.style.background = '#002F6C';
        fuelBtn.style.border = 'none';
        
        merchBtn.classList.remove('active');
        merchBtn.style.color = '#64748b';
        merchBtn.style.background = '#fff';
        merchBtn.style.border = '1px solid #cbd5e1';
        
        fuelSec.style.display = 'block';
        merchSec.style.display = 'none';
    }
}

// Switch sub-tab for completed stock-ins
function switchCompletedSubTab(type) {
    var merchBtn = document.getElementById('subtabCompletedMerchBtn');
    var fuelBtn = document.getElementById('subtabCompletedFuelBtn');
    var merchSec = document.getElementById('completedMerchSection');
    var fuelSec = document.getElementById('completedFuelSection');
    
    if (type === 'merch') {
        merchBtn.classList.add('active');
        merchBtn.style.color = '#fff';
        merchBtn.style.background = '#002F6C';
        merchBtn.style.border = 'none';
        
        fuelBtn.classList.remove('active');
        fuelBtn.style.color = '#64748b';
        fuelBtn.style.background = '#fff';
        fuelBtn.style.border = '1px solid #cbd5e1';
        
        merchSec.style.display = 'block';
        fuelSec.style.display = 'none';
    } else {
        fuelBtn.classList.add('active');
        fuelBtn.style.color = '#fff';
        fuelBtn.style.background = '#002F6C';
        fuelBtn.style.border = 'none';
        
        merchBtn.classList.remove('active');
        merchBtn.style.color = '#64748b';
        merchBtn.style.background = '#fff';
        merchBtn.style.border = '1px solid #cbd5e1';
        
        fuelSec.style.display = 'block';
        merchSec.style.display = 'none';
    }
}

// Load all pending Merchandise Stock Requests into form
function loadPendingMerchRequests() {
    var tbody = document.getElementById('merchPrItemsBody');
    tbody.innerHTML = '';
    
    if (pendingMerchRequests.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 20px; color: #64748b;">No pending merchandise stock requests found. You can add items manually below.</td></tr>';
    } else {
        pendingMerchRequests.forEach(function(req) {
            var tr = document.createElement('tr');
            tr.id = 'merch_row_' + req.product_id;
            tr.innerHTML = 
                '<td>' +
                    '<strong>' + escHtml(req.item_title) + '</strong>' +
                    '<input type="hidden" name="stock_req_ids[' + req.product_id + ']" value="' + req.id + '">' +
                '</td>' +
                '<td>' + numberFormat(req.current_stock) + '</td>' +
                '<td>' + numberFormat(req.reorder_level) + '</td>' +
                '<td style="color: #64748b; font-size: 12px;">' + numberFormat(req.requested_qty) + ' ' + escHtml(req.unit) + '</td>' +
                '<td>' +
                    '<input type="text" name="units[' + req.product_id + ']" value="' + escHtml(req.unit) + '" placeholder="e.g. bottles" style="width: 100px; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px;" required>' +
                '</td>' +
                '<td>' +
                    '<input type="number" name="quantities[' + req.product_id + ']" min="1" value="" placeholder="Qty to order" style="width: 110px; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px;" required>' +
                '</td>' +
                '<td style="text-align: center;">' +
                    '<button type="button" onclick="removePrRow(' + req.product_id + ', \'merch\')" style="background:none; border:none; color:#b91c1c; cursor:pointer;"><i class="fas fa-trash"></i></button>' +
                '</td>';
            tbody.appendChild(tr);
        });
    }
    
    document.getElementById('merchPrFormContainer').style.display = 'block';
    document.getElementById('fuelPrFormContainer').style.display = 'none';
    document.getElementById('merchPrFormContainer').scrollIntoView({ behavior: 'smooth' });
}

// Load all pending Fuel Stock Requests into form
function loadPendingFuelRequests() {
    var tbody = document.getElementById('fuelPrItemsBody');
    tbody.innerHTML = '';
    
    fuelInventoryList.forEach(function(f) {
        var matchingReq = pendingFuelRequests.find(function(req) {
            return req.product_id == f.fuel_type_id;
        });
        
        var reqLabel = matchingReq ? (numberFormat(matchingReq.requested_qty) + ' L') : '—';
        var reqHiddenInput = matchingReq ? '<input type="hidden" name="fuel_req_ids[' + f.fuel_type_id + ']" value="' + matchingReq.id + '">' : '';
        
        var tr = document.createElement('tr');
        tr.innerHTML = 
            '<td>' +
                '<strong>' + escHtml(f.fuel_type) + '</strong>' +
                reqHiddenInput +
            '</td>' +
            '<td>' + numberFormat(f.current_level) + ' L</td>' +
            '<td>' + numberFormat(f.reorder_level) + ' L</td>' +
            '<td style="color: #64748b; font-size: 12px;">' + reqLabel + '</td>' +
            '<td>' +
                '<input type="number" name="fuel_quantities[' + f.fuel_type_id + ']" min="0" value="" placeholder="Liters to order" style="width: 100%; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px;">' +
            '</td>';
        tbody.appendChild(tr);
    });
    
    document.getElementById('fuelPrFormContainer').style.display = 'block';
    document.getElementById('merchPrFormContainer').style.display = 'none';
    document.getElementById('fuelPrFormContainer').scrollIntoView({ behavior: 'smooth' });
}

function addManualProductRow() {
    var sel = document.getElementById('manualMerchDropdown');
    var prodid = sel.value;
    if (!prodid) return;
    
    var opt = sel.options[sel.selectedIndex];
    var name = opt.getAttribute('data-name');
    var stock = opt.getAttribute('data-stock');
    var reorder = opt.getAttribute('data-reorder');
    var unit = opt.getAttribute('data-unit');

    var tbody = document.getElementById('merchPrItemsBody');
    
    if (document.getElementById('merch_row_' + prodid)) {
        alert('Product already added.');
        return;
    }

    var tr = document.createElement('tr');
    tr.id = 'merch_row_' + prodid;
    tr.innerHTML = 
        '<td><strong>' + escHtml(name) + '</strong></td>' +
        '<td>' + numberFormat(stock) + '</td>' +
        '<td>' + numberFormat(reorder) + '</td>' +
        '<td style="color: #64748b; font-size: 12px;">— (Manual)</td>' +
        '<td>' +
            '<input type="text" name="units[' + prodid + ']" value="' + escHtml(unit) + '" placeholder="e.g. bottles" style="width: 100px; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px;" required>' +
        '</td>' +
        '<td>' +
            '<input type="number" name="quantities[' + prodid + ']" min="1" required placeholder="Qty to Order" style="width: 110px; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px;">' +
        '</td>' +
        '<td style="text-align: center;"><button type="button" onclick="removePrRow(' + prodid + ', \'merch\')" style="background:none; border:none; color:#b91c1c; cursor:pointer;"><i class="fas fa-trash"></i></button></td>';
    tbody.appendChild(tr);
    sel.value = '';
}

function removePrRow(id, type) {
    var row = document.getElementById(type + '_row_' + id);
    if (row) row.remove();
}

// Modal actions
function openModal(id) {
    document.getElementById(id).classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    document.body.style.overflow = '';
}

// Review pending deliveries modal population
function openReviewModal(ref) {
    var items = pendingItemsMap[ref];
    if (!items || items.length === 0) return;

    var first = items[0];
    
    // Generate Batch ID
    var now = new Date();
    var year = now.getFullYear();
    var month = String(now.getMonth() + 1).padStart(2, '0');
    var day = String(now.getDate()).padStart(2, '0');
    var hours = String(now.getHours()).padStart(2, '0');
    var minutes = String(now.getMinutes()).padStart(2, '0');
    var seconds = String(now.getSeconds()).padStart(2, '0');
    var batchId = 'BATCH-' + year + month + day + '-' + hours + minutes + seconds;
    
    document.getElementById('lblBatchId').value = batchId;
    document.getElementById('revDeliveryRef').value = first.delivery_ref;
    document.getElementById('lblDeliveryRef').value = first.delivery_ref;
    document.getElementById('lblSourceRef').value = first.source_ref || 'Manual';
    document.getElementById('lblDrNumber').value = first.dr_number || '—';
    document.getElementById('lblSupplier').value = first.supplier;
    document.getElementById('lblDeliveryDate').value = fmtDate(first.delivery_date, true);
    document.getElementById('txtCondition').value = 'Good';

    var thead = document.getElementById('reviewTableHead');
    var tbody = document.getElementById('reviewTableBody');
    thead.innerHTML = '';
    tbody.innerHTML = '';

    if (first.delivery_type === 'merchandise') {
        thead.innerHTML = 
            '<tr>' +
                '<th>Product</th>' +
                '<th style="text-align: center;">Ordered Qty</th>' +
                '<th style="text-align: center; width: 140px;">Received Qty</th>' +
                '<th>Unit</th>' +
                '<th style="width: 140px;">Unit Cost (₱)</th>' +
                '<th style="width: 140px;">Selling Price (₱)</th>' +
                '<th style="text-align: right;">Total Cost (₱)</th>' +
            '</tr>';

        items.forEach(function(it) {
            var tr = document.createElement('tr');
            tr.innerHTML = 
                '<td><strong>' + escHtml(it.product) + '</strong></td>' +
                '<td style="text-align: center;">' + numberFormat(it.expected_quantity) + '</td>' +
                '<td>' +
                    '<input type="number" step="any" name="items[' + it.id + '][qty_rec]" id="m_received_' + it.id + '" value="' + it.actual_quantity + '" oninput="updateRowTotal(' + it.id + ', \'m\')" style="width: 100%; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; text-align: center;" required min="0">' +
                '</td>' +
                '<td>' + escHtml(it.stock_unit) + '</td>' +
                '<td>' +
                    '<input type="number" step="any" name="items[' + it.id + '][cost]" id="m_cost_' + it.id + '" value="' + it.current_cost + '" oninput="updateRowTotal(' + it.id + ', \'m\')" style="width: 100%; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; text-align: center;" required min="0">' +
                '</td>' +
                '<td>' +
                    '<input type="number" step="any" name="items[' + it.id + '][price]" id="m_price_' + it.id + '" value="' + it.current_price + '" style="width: 100%; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; text-align: center;" required min="0">' +
                '</td>' +
                '<td style="text-align: right; font-weight: 700; color: #002F6C;" id="m_total_' + it.id + '">₱' + numberFormat(it.actual_quantity * it.current_cost) + '</td>';
            tbody.appendChild(tr);
        });
    } else {
        // Fuel
        thead.innerHTML = 
            '<tr>' +
                '<th>Fuel Type</th>' +
                '<th style="text-align: center;">Ordered Liters</th>' +
                '<th style="text-align: center; width: 150px;">Received Liters</th>' +
                '<th style="width: 150px;">Cost / Liter (₱)</th>' +
                '<th style="width: 150px;">Selling Price / Liter (₱)</th>' +
                '<th style="text-align: right;">Total Cost (₱)</th>' +
            '</tr>';

        items.forEach(function(it) {
            var tr = document.createElement('tr');
            tr.innerHTML = 
                '<td><strong>' + escHtml(it.product) + '</strong></td>' +
                '<td style="text-align: center;">' + numberFormat(it.expected_quantity) + ' L</td>' +
                '<td>' +
                    '<input type="number" step="any" name="items[' + it.id + '][qty_rec]" id="f_received_' + it.id + '" value="' + it.actual_quantity + '" oninput="updateRowTotal(' + it.id + ', \'f\')" style="width: 100%; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; text-align: center;" required min="0"> L' +
                '</td>' +
                '<td>' +
                    '<input type="number" step="any" name="items[' + it.id + '][cost]" id="f_cost_' + it.id + '" value="' + it.current_cost + '" oninput="updateRowTotal(' + it.id + ', \'f\')" style="width: 100%; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; text-align: center;" required min="0">' +
                '</td>' +
                '<td>' +
                    '<input type="number" step="any" name="items[' + it.id + '][price]" id="f_price_' + it.id + '" value="' + it.current_price + '" style="width: 100%; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; text-align: center;" required min="0">' +
                '</td>' +
                '<td style="text-align: right; font-weight: 700; color: #002F6C;" id="f_total_' + it.id + '">₱' + numberFormat(it.actual_quantity * it.current_cost) + '</td>';
            tbody.appendChild(tr);
        });
    }

    openModal('reviewModal');
}

function updateRowTotal(id, type) {
    var qtyInput = document.getElementById(type + '_received_' + id);
    var costInput = document.getElementById(type + '_cost_' + id);
    var totalSpan = document.getElementById(type + '_total_' + id);
    if (qtyInput && costInput && totalSpan) {
        var qty = parseFloat(qtyInput.value) || 0;
        var cost = parseFloat(costInput.value) || 0;
        totalSpan.textContent = '₱' + numberFormat(qty * cost);
    }
}

function openReturnModal() {
    var ref = document.getElementById('revDeliveryRef').value;
    document.getElementById('retDeliveryRef').value = ref;
    openModal('returnModal');
}

// Helpers
function escHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function numberFormat(val) {
    return parseFloat(val).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function fmtDate(ds, dateOnly) {
    if (!ds) return '—';
    var d = new Date(ds);
    if (isNaN(d.getTime())) return ds;
    var options = { month: 'short', day: 'numeric', year: 'numeric' };
    if (!dateOnly) {
        options.hour = '2-digit';
        options.minute = '2-digit';
    }
    return d.toLocaleString('en-US', options);
}

function viewPurchaseOrder(btn) {
    var poNumber = btn.getAttribute('data-po-number');
    var type = btn.getAttribute('data-type');
    var date = btn.getAttribute('data-date');
    var expected = btn.getAttribute('data-expected');
    var prepared = btn.getAttribute('data-prepared');
    var status = btn.getAttribute('data-status');
    var items = JSON.parse(btn.getAttribute('data-items') || '[]');

    document.getElementById('viewPoNum').innerText = poNumber;
    document.getElementById('viewPoType').innerText = type;
    document.getElementById('viewPoStatus').innerText = status;
    document.getElementById('viewPoDate').innerText = date;
    document.getElementById('viewPoExpected').innerText = expected;
    document.getElementById('viewPoPrepared').innerText = prepared;

    var tbody = document.getElementById('viewPoItemsBody');
    tbody.innerHTML = '';
    
    if (items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="2" style="text-align: center; padding: 12px; color: #64748b;">No items found.</td></tr>';
    } else {
        items.forEach(function(it) {
            var tr = document.createElement('tr');
            // Format number cleanly without trailing decimals if integer
            var qtyFormatted = parseFloat(it.quantity);
            var qtyStr = (type === 'Fuel') ? qtyFormatted.toLocaleString() + ' L' : qtyFormatted.toLocaleString();
            tr.innerHTML = 
                '<td><strong>' + escHtml(it.item_name) + '</strong></td>' +
                '<td style="text-align: right; font-weight: 600;">' + qtyStr + '</td>';
            tbody.appendChild(tr);
        });
    }

    openModal('viewPoModal');
}

// Initialize sub-tabs on page load
document.addEventListener('DOMContentLoaded', function() {
    // Initialize Pending Stock-In sub-tabs
    if (document.getElementById('subtabPendingMerchBtn')) {
        switchPendingStockInSubTab('merch');
    }
    // Initialize Completed sub-tabs
    if (document.getElementById('subtabCompletedMerchBtn')) {
        switchCompletedSubTab('merch');
    }
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
