<?php
// ============================================================
// Manager Inventory Procurement Workflow — manager_stock_request_review.php
// Handles: Pending Requests, Waiting Delivery, Pending Stock-In, Completed
// ====================================================================
$page_id = 'mgr_stock_review';
$page_title = 'Purchase Management';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$station_id = (int)user_station_id();
$role       = role_key($me['role'] ?? '');

// Access control
if (!in_array($role, ['manager', 'superadmin', 'developer'], true)) {
    $_SESSION['error'] = 'Access denied. Manager access required.';
    header('Location: dashboard.php');
    exit;
}

function manager_procurement_prepare_schema(PDO $pdo): void
{
    $statements = [
        "ALTER TABLE stock_requests ADD COLUMN IF NOT EXISTS request_no VARCHAR(50) NULL DEFAULT NULL",
        "ALTER TABLE stock_requests MODIFY COLUMN status VARCHAR(100) DEFAULT 'Pending Manager Review'",
        "ALTER TABLE stock_requests ADD COLUMN IF NOT EXISTS approved_price DECIMAL(10,2) NULL DEFAULT NULL",
        "ALTER TABLE fuel_stock_requests ADD COLUMN IF NOT EXISTS request_no VARCHAR(50) NULL DEFAULT NULL",
        "ALTER TABLE fuel_stock_requests MODIFY COLUMN status VARCHAR(100) DEFAULT 'Pending Manager Review'",
        "ALTER TABLE fuel_stock_requests ADD COLUMN IF NOT EXISTS manager_notes TEXT NULL",
        "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS batch_id VARCHAR(100) NULL DEFAULT NULL",
        "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS admin_finalized TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS admin_finalized_at DATETIME NULL",
        "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS admin_id INT NULL",
        "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS stock_in_done TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS stock_in_at DATETIME NULL",
        "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS stock_in_by INT NULL",
        "ALTER TABLE fuel_purchase_orders ADD COLUMN IF NOT EXISTS batch_id VARCHAR(100) NULL DEFAULT NULL",
        "ALTER TABLE fuel_purchase_orders ADD COLUMN IF NOT EXISTS approved_by INT NULL",
        "ALTER TABLE fuel_purchase_orders ADD COLUMN IF NOT EXISTS approved_at DATETIME NULL",
        "ALTER TABLE fuel_purchase_orders ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL",
        "ALTER TABLE fuel_purchase_orders MODIFY COLUMN status VARCHAR(100) NOT NULL DEFAULT 'Approved PO'",
    ];

    foreach ($statements as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Exception $e) {
            error_log('Manager procurement schema check skipped: ' . $e->getMessage());
        }
    }
}

function manager_petron_supplier_id(PDO $pdo): int
{
    $stmt = $pdo->query("
        SELECT id
        FROM suppliers
        WHERE name LIKE '%Petron%'
        ORDER BY CASE WHEN name = 'Petron Corporation' THEN 0 ELSE 1 END, id ASC
        LIMIT 1
    ");
    $supplier_id = (int)($stmt->fetchColumn() ?: 0);
    if ($supplier_id > 0) {
        return $supplier_id;
    }

    $pdo->exec("
        INSERT INTO suppliers (name, contact_person, phone, email, address)
        VALUES ('Petron Corporation', 'Supply Department', '+63-2-8123-0000', 'supply@petron.ph', 'Petron Plaza, Makati City')
    ");
    return (int)$pdo->lastInsertId();
}

function manager_next_po_number(PDO $pdo): string
{
    $year = date('Y');
    $pattern = 'PO-' . $year . '-%';
    $max_num = 0;
    $queries = [
        "SELECT po_number FROM purchase_orders WHERE po_number LIKE ? ORDER BY po_number DESC LIMIT 1",
        "SELECT batch_id FROM purchase_orders WHERE batch_id LIKE ? ORDER BY batch_id DESC LIMIT 1",
        "SELECT po_number FROM fuel_purchase_orders WHERE po_number LIKE ? ORDER BY po_number DESC LIMIT 1",
        "SELECT batch_id FROM fuel_purchase_orders WHERE batch_id LIKE ? ORDER BY batch_id DESC LIMIT 1",
    ];

    foreach ($queries as $sql) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$pattern]);
            $value = (string)($stmt->fetchColumn() ?: '');
            if (preg_match('/^PO-\d{4}-(\d+)/', $value, $m)) {
                $max_num = max($max_num, (int)$m[1]);
            }
        } catch (Exception $e) {
            error_log('PO number lookup skipped: ' . $e->getMessage());
        }
    }

    return 'PO-' . $year . '-' . str_pad($max_num + 1, 4, '0', STR_PAD_LEFT);
}

function manager_resolve_fuel_type_id(PDO $pdo, int $station_id, string $fuel_type, int $preferred_id = 0, float $price = 0.0): int
{
    if ($preferred_id > 0) {
        $stmt = $pdo->prepare("SELECT id FROM fuel_types WHERE id = ? LIMIT 1");
        $stmt->execute([$preferred_id]);
        if ($stmt->fetchColumn()) {
            return $preferred_id;
        }
    }

    $stmt = $pdo->prepare("SELECT fuel_type_id FROM fuel_inventory WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?)) LIMIT 1");
    $stmt->execute([$station_id, $fuel_type]);
    $inventory_fuel_id = (int)($stmt->fetchColumn() ?: 0);
    if ($inventory_fuel_id > 0) {
        return $inventory_fuel_id;
    }

    $stmt = $pdo->prepare("SELECT id FROM fuel_types WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
    $stmt->execute([$fuel_type]);
    $fuel_type_id = (int)($stmt->fetchColumn() ?: 0);
    if ($fuel_type_id > 0) {
        return $fuel_type_id;
    }

    $stmt = $pdo->prepare("INSERT INTO fuel_types (name, description, price_per_liter) VALUES (?, 'Petron fuel', ?)");
    $stmt->execute([$fuel_type, $price]);
    return (int)$pdo->lastInsertId();
}

function manager_po_notes(string $pr_number, string $remarks): string
{
    return "Source PR: " . $pr_number . "\n"
        . "Expected Time: 9:00 AM\n"
        . "Receiving Personnel: Any Assigned Staff\n"
        . "Payment Terms: 30 Days\n"
        . "Instructions: Release this purchase order to Petron supplier for delivery.\n"
        . "Remarks: " . ($remarks !== '' ? $remarks : 'None');
}

function manager_existing_user_ids(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
    if (empty($ids)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function manager_notify_users(PDO $pdo, array $user_ids, string $title, string $message, string $redirect_url, string $type = 'info', string $severity = 'medium'): void
{
    try {
        $valid_ids = manager_existing_user_ids($pdo, $user_ids);
        if (empty($valid_ids)) {
            return;
        }

        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, message, event_type, severity, redirect_url, created_at)
            VALUES (?, ?, ?, ?, 'stock_request', ?, ?, NOW())
        ");
        foreach ($valid_ids as $user_id) {
            $stmt->execute([$user_id, $type, $title, $message, $severity, $redirect_url]);
        }
    } catch (Exception $e) {
        error_log('Manager PR notification skipped: ' . $e->getMessage());
    }
}

manager_procurement_prepare_schema($pdo);

//  Handle POST Actions 
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Generate approved Merchandise Purchase Order
    if ($action === 'generate_merch_pr') {
        $pr_number = trim($_POST['pr_number'] ?? '');
        $expected_delivery = trim($_POST['expected_delivery'] ?? '') ?: date('Y-m-d', strtotime('+3 days'));
        $remarks = trim($_POST['remarks'] ?? '');
        $quantities = $_POST['quantities'] ?? [];
        $unit_costs = $_POST['unit_costs'] ?? [];
        $stock_req_ids = $_POST['stock_req_ids'] ?? [];
        $units = $_POST['units'] ?? [];
        
        if (empty($pr_number)) {
            $pr_number = "PR-" . date('Y') . "-" . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        }

        try {
            $pdo->beginTransaction();
            $items_to_insert = [];

            foreach ($quantities as $prod_id => $qty_raw) {
                $qty = (int)$qty_raw;
                if ($qty <= 0) continue;

                $unit_cost = (float)($unit_costs[$prod_id] ?? 0);
                if ($unit_cost <= 0) {
                    throw new Exception("Enter Unit Cost for every product with Qty to Order.");
                }

                $stock_req_id = isset($stock_req_ids[$prod_id]) ? (int)$stock_req_ids[$prod_id] : 0;
                $unit = isset($units[$prod_id]) ? trim($units[$prod_id]) : '';

                $_inv2 = false;
                try { $pdo->query("SELECT 1 FROM inventory_products LIMIT 1"); $_inv2 = true; } catch (Throwable $_e2) {}
                if ($_inv2) {
                    $stmt_req = $pdo->prepare("
                        SELECT sr.*, ip.product_name, ip.sku, ip.category,
                               COALESCE(si.stock_level, ip.stock, sr.current_stock, 0) AS current_stock_actual
                        FROM stock_requests sr
                        LEFT JOIN inventory_products ip ON sr.item_id = ip.id
                        LEFT JOIN station_inventory si ON sr.item_id = si.product_id AND si.station_id = sr.station_id
                        WHERE sr.id = ? AND sr.station_id = ?
                          AND sr.status IN ('Pending', 'Pending Manager Review')
                        LIMIT 1
                    ");
                } else {
                    $stmt_req = $pdo->prepare("
                        SELECT sr.*, NULL AS product_name, NULL AS sku, NULL AS category,
                               COALESCE(si.stock_level, sr.current_stock, 0) AS current_stock_actual
                        FROM stock_requests sr
                        LEFT JOIN station_inventory si ON sr.item_id = si.product_id AND si.station_id = sr.station_id
                        WHERE sr.id = ? AND sr.station_id = ?
                          AND sr.status IN ('Pending', 'Pending Manager Review')
                        LIMIT 1
                    ");
                }
                $stmt_req->execute([$stock_req_id, $station_id]);
                $req = $stmt_req->fetch(PDO::FETCH_ASSOC);
                if (!$req) {
                    throw new Exception("One selected merchandise request is no longer pending.");
                }

                $items_to_insert[] = [
                    'request_id' => (int)$req['id'],
                    'product_id' => !empty($req['item_id']) ? (int)$req['item_id'] : null,
                    'sku' => $req['item_sku'] ?: ($req['sku'] ?? ''),
                    'product_name' => $req['item_name'] ?: ($req['product_name'] ?? 'Merchandise Item'),
                    'category' => $req['item_category'] ?: ($req['category'] ?? 'Merchandise'),
                    'quantity' => $qty,
                    'unit_cost' => $unit_cost,
                    'total' => round($qty * $unit_cost, 2),
                    'unit' => $unit,
                    'staff_id' => (int)$req['staff_id'],
                    'old_status' => $req['status'] ?: 'Pending Manager Review',
                ];
            }

            if (empty($items_to_insert)) {
                throw new Exception("Please enter Qty to Order for at least one product.");
            }

            $supplier_id = manager_petron_supplier_id($pdo);
            $po_number = manager_next_po_number($pdo);
            $po_notes = manager_po_notes($pr_number, $remarks);
            $total_qty = array_sum(array_column($items_to_insert, 'quantity'));
            $grand_total = array_sum(array_column($items_to_insert, 'total'));
            $first_request_id = (int)$items_to_insert[0]['request_id'];

            $stmt_po = $pdo->prepare("
                INSERT INTO purchase_orders (
                    request_id, product_name, quantity, unit_price, total_amount, type, po_number, batch_id,
                    station_id, supplier_id, created_by, status, expected_delivery_date, remarks,
                    admin_finalized, admin_finalized_at, admin_id, approved_by, approved_at, created_at, updated_at
                ) VALUES (
                    ?, 'Merchandise Purchase Order', ?, 0, ?, 'merch', ?, ?,
                    ?, ?, ?, 'Approved', ?, ?,
                    1, NOW(), ?, ?, NOW(), NOW(), NOW()
                )
            ");
            $stmt_po->execute([
                $first_request_id, $total_qty, $grand_total, $po_number, $po_number,
                $station_id, $supplier_id, $me['id'], $expected_delivery, $po_notes,
                $me['id'], $me['id']
            ]);
            $po_id = (int)$pdo->lastInsertId();

            foreach ($items_to_insert as $item) {
                if (!empty($item['unit']) && !empty($item['product_id'])) {
                    $upd_unit_stmt = $pdo->prepare("UPDATE station_inventory SET unit = ? WHERE product_id = ? AND station_id = ?");
                    $upd_unit_stmt->execute([$item['unit'], $item['product_id'], $station_id]);

                    $upd_prod_stmt = $pdo->prepare("UPDATE inventory_products SET size = ? WHERE id = ?");
                    $upd_prod_stmt->execute([$item['unit'], $item['product_id']]);
                }

                $stmt_item = $pdo->prepare("
                    INSERT INTO purchase_order_items
                        (po_id, product_id, item_name, quantity, quantity_ordered, unit_price, total_price)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt_item->execute([
                    $po_id, $item['product_id'], $item['product_name'], $item['quantity'],
                    $item['quantity'], $item['unit_cost'], $item['total']
                ]);

                $sr_stmt = $pdo->prepare("
                    UPDATE stock_requests
                    SET status = 'Purchase Order Generated',
                        approved_quantity = ?,
                        approved_price = ?,
                        manager_id = ?,
                        manager_notes = ?,
                        request_no = ?,
                        processed_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ? AND station_id = ?
                ");
                $sr_stmt->execute([
                    $item['quantity'], $item['unit_cost'], $me['id'], $remarks,
                    $pr_number, $item['request_id'], $station_id
                ]);

                $audit_stmt = $pdo->prepare("
                    INSERT INTO stock_request_audit
                        (stock_request_id, action_type, performed_by, performed_by_role, old_status, new_status, notes)
                    VALUES (?, 'PO Generated', ?, ?, ?, 'Purchase Order Generated', ?)
                ");
                $audit_stmt->execute([
                    $item['request_id'], $me['id'], $role, $item['old_status'],
                    "PO Generated: $po_number from PR $pr_number"
                ]);
            }

            manager_notify_users(
                $pdo,
                array_column($items_to_insert, 'staff_id'),
                'Purchase Order Generated',
                "Purchase Order {$po_number} has been generated for Purchase Request {$pr_number}.",
                'staff_record_delivery.php'
            );

            log_activity($pdo, $me['id'], 'Generate Merchandise Purchase Order', "Generated PO {$po_number} from PR {$pr_number}.");
            $pdo->commit();
            $_SESSION['success'] = "Purchase Order <strong>$po_number</strong> generated and approved.";
            header("Location: print_po_new.php?batch_id=" . urlencode($po_number) . "&type=merch&print=1");
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = "Error: " . $e->getMessage();
            header('Location: manager_stock_request_review.php?tab=pending_requests');
            exit;
        }
    }

    // 2. Generate approved Fuel Purchase Order
    if ($action === 'generate_fuel_pr') {
        $pr_number       = trim($_POST['pr_number'] ?? '');
        $expected_delivery = trim($_POST['expected_delivery'] ?? '') ?: date('Y-m-d', strtotime('+3 days'));
        $remarks         = trim($_POST['remarks'] ?? '');
        $fuel_quantities = $_POST['fuel_quantities'] ?? [];
        $fuel_unit_costs = $_POST['fuel_unit_costs'] ?? [];
        $fuel_req_ids    = $_POST['fuel_req_ids'] ?? [];

        if (empty($pr_number)) {
            $pr_number = 'PR-' . date('Y') . '-' . str_pad(rand(1,9999), 4, '0', STR_PAD_LEFT);
        }

        try {
            $pdo->beginTransaction();
            $items_to_insert = [];
            
            foreach ($fuel_quantities as $fuel_type_id => $liters_raw) {
                $liters = (float)$liters_raw;
                if ($liters <= 0) continue;

                $unit_cost = (float)($fuel_unit_costs[$fuel_type_id] ?? 0);
                if ($unit_cost <= 0) {
                    throw new Exception("Enter Cost per Liter for every fuel type with Liters to Order.");
                }

                $linked_req_id = isset($fuel_req_ids[$fuel_type_id]) ? (int)$fuel_req_ids[$fuel_type_id] : 0;
                $stmt_req = $pdo->prepare("
                    SELECT fsr.*, fi.fuel_type_id, COALESCE(fi.current_level, fsr.current_level, 0) AS current_stock_actual
                    FROM fuel_stock_requests fsr
                    LEFT JOIN fuel_inventory fi ON LOWER(fsr.fuel_type) = LOWER(fi.fuel_type) AND fi.station_id = fsr.station_id
                    WHERE fsr.id = ? AND fsr.station_id = ?
                      AND fsr.status IN ('Pending', 'Pending Manager Review')
                    LIMIT 1
                ");
                $stmt_req->execute([$linked_req_id, $station_id]);
                $req = $stmt_req->fetch(PDO::FETCH_ASSOC);
                if (!$req) {
                    throw new Exception("One selected fuel request is no longer pending.");
                }

                $fuel_name = $req['fuel_type'] ?: 'Fuel';
                $resolved_fuel_type_id = manager_resolve_fuel_type_id($pdo, $station_id, $fuel_name, (int)($req['fuel_type_id'] ?? 0), $unit_cost);

                $items_to_insert[] = [
                    'request_id' => (int)$req['id'],
                    'fuel_type_id' => $resolved_fuel_type_id,
                    'fuel_type' => $fuel_name,
                    'liters' => $liters,
                    'unit_cost' => $unit_cost,
                    'total' => round($liters * $unit_cost, 2),
                    'staff_id' => (int)$req['staff_id'],
                    'old_status' => $req['status'] ?: 'Pending Manager Review',
                ];
            }

            if (empty($items_to_insert)) {
                throw new Exception('Enter Liters to Order for at least one fuel type.');
            }

            $supplier_id = manager_petron_supplier_id($pdo);
            $po_number = manager_next_po_number($pdo);
            $po_notes = manager_po_notes($pr_number, $remarks);
            $line_count = count($items_to_insert);
            $line_index = 1;

            foreach ($items_to_insert as $item) {
                $line_po_number = $line_count > 1
                    ? $po_number . '-' . str_pad($line_index, 2, '0', STR_PAD_LEFT)
                    : $po_number;

                $stmt_ins = $pdo->prepare("
                    INSERT INTO fuel_purchase_orders (
                        po_number, batch_id, station_id, fuel_type_id, volume, unit_price, total_amount,
                        supplier_id, expected_delivery_date, status, created_by, approved_by, approved_at,
                        notes, created_at, updated_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, 'Approved', ?, ?, NOW(),
                        ?, NOW(), NOW()
                    )
                ");
                $stmt_ins->execute([
                    $line_po_number, $po_number, $station_id, $item['fuel_type_id'], $item['liters'],
                    $item['unit_cost'], $item['total'], $supplier_id, $expected_delivery,
                    $me['id'], $me['id'], $po_notes
                ]);

                $pdo->prepare("
                    UPDATE fuel_stock_requests
                    SET status = 'Purchase Order Generated',
                        approved_liters = ?,
                        manager_id = ?,
                        manager_notes = ?,
                        request_no = ?,
                        processed_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ? AND station_id = ?
                ")->execute([
                    $item['liters'], $me['id'], $remarks, $pr_number, $item['request_id'], $station_id
                ]);

                $pdo->prepare("
                    INSERT INTO fuel_stock_request_audit
                        (request_id, action_type, performed_by, performed_by_role, old_status, new_status, notes)
                    VALUES (?, 'PO Generated', ?, ?, ?, 'Purchase Order Generated', ?)
                ")->execute([
                    $item['request_id'], $me['id'], $role, $item['old_status'],
                    "PO Generated: $po_number from PR $pr_number"
                ]);

                $line_index++;
            }

            manager_notify_users(
                $pdo,
                array_column($items_to_insert, 'staff_id'),
                'Purchase Order Generated',
                "Purchase Order {$po_number} has been generated for Fuel Purchase Request {$pr_number}.",
                'staff_record_delivery.php'
            );

            log_activity($pdo, $me['id'], 'Generate Fuel Purchase Order', "Generated fuel PO {$po_number} from PR {$pr_number}.");
            $pdo->commit();
            $_SESSION['success'] = "Fuel Purchase Order <strong>$po_number</strong> generated and approved.";
            header("Location: print_po_new.php?batch_id=" . urlencode($po_number) . "&type=fuel&print=1");
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = 'Error: ' . $e->getMessage();
            header('Location: manager_stock_request_review.php?tab=pending_requests');
            exit;
        }
    }

    // 5. Return PR to Staff
    if ($action === 'return_pr_to_staff') {
        $pr_num   = trim($_POST['pr_number'] ?? '');
        $pr_type  = trim($_POST['pr_type'] ?? 'merch');
        $reason   = trim($_POST['return_reason'] ?? '');
        $req_ids_str = trim($_POST['request_ids'] ?? '');
        
        $req_ids = [];
        if (!empty($req_ids_str)) {
            $req_ids = array_filter(array_map('intval', explode(',', $req_ids_str)));
        }

        if (!empty($req_ids) || $pr_num) {
            try {
                if ($pr_type === 'fuel') {
                    if (!empty($req_ids)) {
                        $in_clause = implode(',', $req_ids);
                        $pdo->prepare("
                            UPDATE fuel_stock_requests SET status='Pending', manager_id=NULL, processed_at=NULL, updated_at=NOW()
                            WHERE id IN ($in_clause) AND station_id=?
                        ")->execute([$station_id]);
                    } else {
                        $pdo->prepare("
                            UPDATE fuel_stock_requests SET status='Pending', manager_id=NULL, processed_at=NULL, updated_at=NOW()
                            WHERE request_no=? AND station_id=?
                        ")->execute([$pr_num, $station_id]);
                    }
                } else {
                    if (!empty($req_ids)) {
                        $in_clause = implode(',', $req_ids);
                        $pdo->prepare("
                            UPDATE stock_requests SET status='Pending', approved_quantity=NULL, manager_id=NULL, processed_at=NULL, updated_at=NOW()
                            WHERE id IN ($in_clause) AND station_id=?
                        ")->execute([$station_id]);
                    } else {
                        $pdo->prepare("
                            UPDATE stock_requests SET status='Pending', approved_quantity=NULL, manager_id=NULL, processed_at=NULL, updated_at=NOW()
                            WHERE request_no=? AND station_id=?
                        ")->execute([$pr_num, $station_id]);
                    }
                }
                
                // Fetch staff ids to notify
                if (!empty($req_ids)) {
                    if ($pr_type === 'fuel') {
                        $staff_ids = $pdo->query("SELECT DISTINCT staff_id FROM fuel_stock_requests WHERE id IN (" . implode(',', $req_ids) . ") AND station_id=$station_id")->fetchAll(PDO::FETCH_COLUMN);
                    } else {
                        $staff_ids = $pdo->query("SELECT DISTINCT staff_id FROM stock_requests WHERE id IN (" . implode(',', $req_ids) . ") AND station_id=$station_id")->fetchAll(PDO::FETCH_COLUMN);
                    }
                } else {
                    if ($pr_type === 'fuel') {
                        $staff_ids_stmt = $pdo->prepare("SELECT DISTINCT staff_id FROM fuel_stock_requests WHERE request_no=? AND station_id=?");
                    } else {
                        $staff_ids_stmt = $pdo->prepare("SELECT DISTINCT staff_id FROM stock_requests WHERE request_no=? AND station_id=?");
                    }
                    $staff_ids_stmt->execute([$pr_num, $station_id]);
                    $staff_ids = $staff_ids_stmt->fetchAll(PDO::FETCH_COLUMN);
                }

                $display_pr_name = $pr_num ?: 'Request Batch';
                manager_notify_users(
                    $pdo,
                    $staff_ids,
                    'PR Returned',
                    "Purchase Request {$display_pr_name} was returned by the manager. Reason: {$reason}.",
                    'staff_stock_requests.php',
                    'warning',
                    'warning'
                );
                log_activity($pdo, $me['id'], 'Return Purchase Request', "Returned PR {$display_pr_name} to staff. Reason: {$reason}");
                $_SESSION['success'] = "Purchase Request <strong>{$display_pr_name}</strong> returned to staff for correction.";
            } catch (Exception $e) {
                $_SESSION['error'] = 'Error: ' . $e->getMessage();
            }
        }
        header('Location: manager_stock_request_review.php?tab=pending_requests');
        exit;
    }
}

//  Summary Card Counts 
$cnt_pending_sr_merch = (int)$pdo->query("SELECT COUNT(*) FROM stock_requests WHERE station_id = $station_id AND status IN ('Pending', 'Pending Manager Review') AND LOWER(COALESCE(item_category, '')) != 'fuel'")->fetchColumn();
$cnt_pending_sr_fuel  = (int)$pdo->query("SELECT COUNT(*) FROM fuel_stock_requests WHERE station_id = $station_id AND status IN ('Pending', 'Pending Manager Review')")->fetchColumn();
$cnt_pending_pr       = $cnt_pending_sr_merch + $cnt_pending_sr_fuel;

$cnt_po_generated_merch = (int)$pdo->query("SELECT COUNT(DISTINCT COALESCE(NULLIF(batch_id, ''), po_number)) FROM purchase_orders WHERE station_id = $station_id AND type = 'merch' AND status IN ('Approved','Approved PO','Admin Finalized')")->fetchColumn();
$cnt_po_generated_fuel  = (int)$pdo->query("SELECT COUNT(DISTINCT COALESCE(NULLIF(batch_id, ''), po_number)) FROM fuel_purchase_orders WHERE station_id = $station_id AND status IN ('Approved','Approved PO','Admin Finalized')")->fetchColumn();
$cnt_po_generated       = $cnt_po_generated_merch + $cnt_po_generated_fuel;

$cnt_pending_delivery = (int)$pdo->query("SELECT COUNT(DISTINCT po_number) FROM purchase_orders WHERE station_id = $station_id AND status IN ('Approved','Approved PO','Admin Finalized') AND id NOT IN (SELECT DISTINCT po_id FROM merchandise_stock_in WHERE station_id = $station_id AND po_id IS NOT NULL)")->fetchColumn()
                      + (int)$pdo->query("SELECT COUNT(DISTINCT batch_id) FROM fuel_purchase_orders WHERE station_id = $station_id AND status IN ('Approved','Approved PO','Admin Finalized') AND actual_volume IS NULL")->fetchColumn();

$cnt_completed = (int)$pdo->query("SELECT COUNT(DISTINCT delivery_ref) FROM deliveries_oversight WHERE station_id = $station_id AND status = 'Stock-In Complete'")->fetchColumn();

//  Always Fetch Pending PRs 
// Merchandise pending requests
$_inv_accessible = false;
try { $pdo->query("SELECT 1 FROM inventory_products LIMIT 1"); $_inv_accessible = true; } catch (Throwable $_e) {}

if ($_inv_accessible) {
    $stmt1 = $pdo->prepare("
        SELECT sr.id, 'Merchandise' AS req_type, sr.item_name AS item_title, sr.requested_quantity AS requested_qty, sr.created_at,
               u.name AS staff_name, sr.staff_id,
               COALESCE(si.unit, ip.size, 'pcs') AS unit,
               COALESCE(si.stock_level, ip.stock, 0) AS current_stock,
               COALESCE(si.reorder_level, ip.min_stock, 10) AS reorder_level,
               sr.item_id AS product_id, sr.request_no, ip.sku AS item_sku,
               COALESCE(sr.approved_price, ip.unit_cost, 0) AS unit_cost,
               sr.status
        FROM stock_requests sr
        LEFT JOIN users u ON sr.staff_id = u.id
        LEFT JOIN inventory_products ip ON sr.item_id = ip.id
        LEFT JOIN station_inventory si ON sr.item_id = si.product_id AND si.station_id = sr.station_id
        WHERE sr.station_id = ? AND sr.status IN ('Pending', 'Pending Manager Review') AND LOWER(COALESCE(sr.item_category, '')) != 'fuel'
        ORDER BY sr.created_at DESC
    ");
} else {
    $stmt1 = $pdo->prepare("
        SELECT sr.id, 'Merchandise' AS req_type, sr.item_name AS item_title, sr.requested_quantity AS requested_qty, sr.created_at,
               u.name AS staff_name, sr.staff_id,
               COALESCE(si.unit, 'pcs') AS unit,
               COALESCE(si.stock_level, 0) AS current_stock,
               COALESCE(si.reorder_level, 10) AS reorder_level,
               sr.item_id AS product_id, sr.request_no, NULL AS item_sku,
               COALESCE(sr.approved_price, 0) AS unit_cost,
               sr.status
        FROM stock_requests sr
        LEFT JOIN users u ON sr.staff_id = u.id
        LEFT JOIN station_inventory si ON sr.item_id = si.product_id AND si.station_id = sr.station_id
        WHERE sr.station_id = ? AND sr.status IN ('Pending', 'Pending Manager Review') AND LOWER(COALESCE(sr.item_category, '')) != 'fuel'
        ORDER BY sr.created_at DESC
    ");
}
$stmt1->execute([$station_id]);
$merch_reqs = $stmt1->fetchAll(PDO::FETCH_ASSOC);

// Fuel pending requests
$stmt2 = $pdo->prepare("
    SELECT fsr.id, 'Fuel' AS req_type, fsr.fuel_type AS item_title, fsr.requested_liters AS requested_qty, fsr.created_at,
           u.name AS staff_name, fsr.staff_id, fsr.remarks AS staff_remarks, 'L' AS unit,
           COALESCE(fi.current_level, 0) AS current_stock,
           COALESCE(fi.reorder_level, 5000) AS reorder_level,
           COALESCE(fi.capacity, 0) AS tank_capacity,
           COALESCE(fi.ugt_no, '') AS ugt_no,
           COALESCE(fi.fuel_type_id, ft.id) AS product_id, fsr.request_no,
           COALESCE(
               (SELECT fp.price_per_liter FROM fuel_pricing fp WHERE fp.fuel_type_id = fi.fuel_type_id AND fp.station_id = fsr.station_id AND fp.is_active = 1 ORDER BY fp.effective_date DESC LIMIT 1),
               ft.price_per_liter,
               0
           ) AS unit_cost,
           fsr.status
    FROM fuel_stock_requests fsr
    LEFT JOIN users u ON fsr.staff_id = u.id
    LEFT JOIN fuel_inventory fi ON LOWER(fsr.fuel_type) = LOWER(fi.fuel_type) AND fi.station_id = fsr.station_id
    LEFT JOIN fuel_types ft ON LOWER(fsr.fuel_type) = LOWER(ft.name)
    WHERE fsr.station_id = ? AND fsr.status IN ('Pending', 'Pending Manager Review')
    ORDER BY fsr.created_at DESC
");
$stmt2->execute([$station_id]);
$fuel_reqs = $stmt2->fetchAll(PDO::FETCH_ASSOC);

// Supporting data for inline forms
$all_station_products = [];
try {
    $ps = $pdo->prepare("SELECT ip.*, COALESCE(si.stock_level, ip.stock, 0) AS current_stock_actual, COALESCE(si.reorder_level, ip.min_stock, 10) AS reorder_level, COALESCE(si.unit, ip.size, 'pcs') AS stock_unit FROM inventory_products ip LEFT JOIN station_inventory si ON ip.id = si.product_id AND si.station_id = ? WHERE ip.station_id = ? AND LOWER(ip.category) != 'fuel' AND ip.status = 'active' ORDER BY ip.product_name ASC");
    $ps->execute([$station_id, $station_id]);
    $all_station_products = $ps->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$fuel_inventory_list = [];
try {
    $fi = $pdo->prepare("SELECT * FROM fuel_inventory WHERE station_id = ? ORDER BY fuel_type");
    $fi->execute([$station_id]);
    $fuel_inventory_list = $fi->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Purchase History Data Fetching ─────────────────────────────────────────────────────────
$purchase_history_list = [];
try {
    // 1. Fetch Merchandise POs
    $stmt_m = $pdo->prepare("
        SELECT 
            po.id,
            po.po_number,
            po.batch_id,
            'merchandise' AS category_type,
            'Merchandise' AS category_label,
            COALESCE(s.name, 'Petron Corporation') AS supplier_name,
            po.created_at AS date_ordered,
            COALESCE(po.stock_in_at, po.updated_at) AS date_received,
            po.total_amount,
            po.status,
            COALESCE(u_req.name, u_req.username, 'Manager') AS requested_by_name,
            COALESCE(u_app.name, u_app.username, 'Admin') AS approved_by_name,
            po.remarks
        FROM purchase_orders po
        LEFT JOIN suppliers s ON po.supplier_id = s.id
        LEFT JOIN users u_req ON po.created_by = u_req.id
        LEFT JOIN users u_app ON po.approved_by = u_app.id
        WHERE po.station_id = ?
        ORDER BY po.created_at DESC
    ");
    $stmt_m->execute([$station_id]);
    $merch_pos = $stmt_m->fetchAll(PDO::FETCH_ASSOC);

    // Group by batch_id so multi-item POs appear as one entry
    $merch_batches = [];
    foreach ($merch_pos as $mpo) {
        $key = !empty($mpo['batch_id']) ? $mpo['batch_id'] : $mpo['po_number'];
        if (!isset($merch_batches[$key])) {
            $merch_batches[$key] = $mpo;
            $merch_batches[$key]['po_number'] = $key;
            $merch_batches[$key]['_po_ids'] = [];
        } else {
            $merch_batches[$key]['total_amount'] += $mpo['total_amount'];
        }
        $merch_batches[$key]['_po_ids'][] = $mpo['id'];
    }

    foreach ($merch_batches as &$mpo) {
        // Fetch ALL items for all PO IDs in this batch
        $po_ids = $mpo['_po_ids'];
        $placeholders = implode(',', array_fill(0, count($po_ids), '?'));
        $stmt_items = $pdo->prepare("
            SELECT
                poi.id,
                poi.po_id,
                COALESCE(ip.sku, 'N/A') AS sku,
                poi.item_name AS product_name,
                poi.quantity,
                COALESCE(ip.size, ip.unit, 'pcs') AS unit,
                poi.unit_price,
                poi.total_price
            FROM purchase_order_items poi
            LEFT JOIN inventory_products ip ON poi.product_id = ip.id
            WHERE poi.po_id IN ($placeholders)
            ORDER BY poi.id ASC
        ");
        $stmt_items->execute($po_ids);
        $raw_items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);
        // Clean garbled product names (??? from charset issues)
        foreach ($raw_items as &$ri) {
            $ri['product_name'] = trim(preg_replace('/\?{2,}/', ' ', $ri['product_name']));
            $ri['product_name'] = preg_replace('/\s+/', ' ', $ri['product_name']);
        }
        unset($ri);
        $mpo['items'] = $raw_items;

        try {
            $stmt_del = $pdo->prepare("
                SELECT dr_number, sales_invoice_no, COALESCE(received_by_name, 'Staff') AS received_by_name, delivery_date
                FROM deliveries_oversight
                WHERE (source_ref = ? OR delivery_ref LIKE ?) AND station_id = ?
                LIMIT 1
            ");
            $stmt_del->execute([$mpo['po_number'], '%' . $mpo['_po_ids'][0], $station_id]);
            $del_info = $stmt_del->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $del_info = null;
        }
        $mpo['dr_number']        = $del_info['dr_number']        ?? 'N/A';
        $mpo['sales_invoice_no'] = $del_info['sales_invoice_no'] ?? 'N/A';
        $mpo['received_by_name'] = $del_info['received_by_name'] ?? ($mpo['requested_by_name'] ?: 'Staff');
        $mpo['delivery_date']    = $del_info['delivery_date']    ?? $mpo['date_received'];

        $purchase_history_list[] = $mpo;
    }
    unset($mpo);

    // 2. Fetch Fuel POs
    $stmt_f = $pdo->prepare("
        SELECT 
            fpo.id,
            fpo.po_number,
            'fuel' AS category_type,
            'Fuel' AS category_label,
            COALESCE(s.name, 'Petron Corporation') AS supplier_name,
            fpo.created_at AS date_ordered,
            COALESCE(fpo.delivery_date, fpo.updated_at) AS date_received,
            fpo.total_amount,
            fpo.status,
            COALESCE(u_req.name, u_req.username, 'Manager') AS requested_by_name,
            COALESCE(u_app.name, u_app.username, 'Admin') AS approved_by_name,
            fpo.notes AS remarks,
            ft.name AS fuel_type,
            fpo.volume AS liters,
            fpo.unit_price AS cost_per_liter
        FROM fuel_purchase_orders fpo
        LEFT JOIN suppliers s ON fpo.supplier_id = s.id
        LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
        LEFT JOIN users u_req ON fpo.created_by = u_req.id
        LEFT JOIN users u_app ON fpo.approved_by = u_app.id
        WHERE fpo.station_id = ?
        ORDER BY fpo.created_at DESC
    ");
    $stmt_f->execute([$station_id]);
    $fuel_pos = $stmt_f->fetchAll(PDO::FETCH_ASSOC);

    foreach ($fuel_pos as &$fpo) {
        $fpo['items'] = [[
            'fuel_type' => $fpo['fuel_type'] ?: 'Fuel',
            'liters' => (float)$fpo['liters'],
            'cost_per_liter' => (float)$fpo['cost_per_liter'],
            'total_price' => (float)$fpo['total_amount']
        ]];

        $stmt_del = $pdo->prepare("
            SELECT dr_number, sales_invoice_no, COALESCE(received_by_name, 'Staff') AS received_by_name, delivery_date
            FROM deliveries_oversight
            WHERE (source_ref = ? OR batch_id = ?) AND station_id = ? AND delivery_type = 'fuel'
            LIMIT 1
        ");
        $stmt_del->execute([$fpo['po_number'], $fpo['po_number'], $station_id]);
        $del_info = $stmt_del->fetch(PDO::FETCH_ASSOC);
        $fpo['dr_number'] = $del_info['dr_number'] ?? 'N/A';
        $fpo['sales_invoice_no'] = $del_info['sales_invoice_no'] ?? 'N/A';
        $fpo['received_by_name'] = $del_info['received_by_name'] ?? ($fpo['requested_by_name'] ?: 'Staff');
        $fpo['delivery_date'] = $del_info['delivery_date'] ?? $fpo['date_received'];

        $purchase_history_list[] = $fpo;
    }
    unset($fpo);

    usort($purchase_history_list, function($a, $b) {
        return strtotime($b['date_ordered']) - strtotime($a['date_ordered']);
    });
} catch (Exception $e) {
    error_log("Error fetching purchase history: " . $e->getMessage());
}

$cnt_hist_total = count($purchase_history_list);
$cnt_hist_fuel = count(array_filter($purchase_history_list, fn($r) => $r['category_type'] === 'fuel'));
$cnt_hist_merch = count(array_filter($purchase_history_list, fn($r) => $r['category_type'] === 'merchandise'));
$cnt_hist_completed = count(array_filter($purchase_history_list, fn($r) => in_array(strtolower($r['status']), ['completed', 'received', 'stock-in complete'])));
$cnt_hist_cancelled = count(array_filter($purchase_history_list, fn($r) => in_array(strtolower($r['status']), ['cancelled', 'rejected', 'withdrawn'])));

include __DIR__ . '/../partials/header.php';
?>

<style>
/* Override default .main padding to fit screen from left to right */
body .main,
.main,
.main-content {
    padding: 0 !important;
}

/* Modern premium styling */
.pr-container {
    padding: 20px 20px 60px 20px;
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
    align-items: flex-end;
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
    align-items: flex-end;
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
    align-items: flex-end;
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
    align-items: flex-end;
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
    text-decoration: none;
}
.table-pr td a,
.table-pr td span,
.pr-inline-panel a,
.pr-inline-panel span {
    text-decoration: none;
    color: inherit;
}
.table-pr tr:hover td {
    background: #f8fafc;
}
.status-badge {
    display: inline-flex;
    align-items: flex-end;
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
    align-items: flex-end; /* Centered vertically */
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
    align-items: flex-end;
    justify-content: space-between;
    flex-shrink: 0;
}
.modal-title {
    font-size: 17px;
    font-weight: 800;
    margin: 0;
    display: flex;
    align-items: flex-end;
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

.btn-pr {
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: flex-end;
    gap: 8px;
    border: 1px solid transparent;
    transition: all 0.15s;
    text-decoration: none;
}
.btn-primary-pr {
    background: #002F6C !important;
    color: #fff !important;
}
.btn-primary-pr:hover {
    background: #001f4d !important;
}
.btn-outline-pr {
    background: #fff !important;
    border-color: #cbd5e1 !important;
    color: #475569 !important;
}
.btn-outline-pr:hover {
    background: #f8fafc !important;
    border-color: #94a3b8 !important;
}
.btn-danger-pr {
    background: #fff !important;
    border-color: #fca5a5 !important;
    color: #b91c1c !important;
}
.btn-danger-pr:hover {
    background: #fee2e2 !important;
}

/* Inline PR Expansion */
.pr-clickable-row td {
    cursor: default;
    user-select: none;
    transition: background 0.15s;
    color: inherit;
    text-decoration: none;
}
.pr-clickable-row td[onclick] {
    cursor: pointer;
}
.pr-clickable-row:hover td {
    background: #f8fafc !important;
}
.pr-clickable-row.expanded td {
    background: #f8fafc;
    border-bottom: none;
}
.pr-expand-icon {
    display: inline-flex;
    align-items: flex-end;
    justify-content: center;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: #002F6C;
    color: #fff;
    font-size: 11px;
    margin-right: 8px;
    transition: transform 0.25s;
    flex-shrink: 0;
}
.pr-clickable-row.expanded .pr-expand-icon {
    transform: rotate(90deg);
    background: #1d4ed8;
}
.pr-inline-detail {
    display: none;
    background: #f8fafc;
}
.pr-inline-detail.open {
    display: table-row;
}
.pr-inline-detail td {
    padding: 0 !important;
}
.pr-inline-panel {
    padding: 24px 28px;
    border-top: 1px solid #e2e8f0;
}
.pr-panel-meta {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 18px;
    padding-bottom: 16px;
    border-bottom: 1px solid #dbeafe;
    font-size: 13.5px;
}
.pr-panel-meta-item strong {
    display: block;
    font-size: 10.5px;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: #64748b;
    font-weight: 700;
    margin-bottom: 2px;
}
.pr-panel-meta-item span {
    color: #002F6C;
    font-weight: 700;
}
.pr-panel-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 18px;
    padding-top: 16px;
    padding-bottom: 8px;
    border-top: 1px solid #dbeafe;
    flex-wrap: wrap;
}
.btn-forward {
    background: #fff !important;
    color: #002F6C !important;
    border: 1.5px solid #002F6C !important;
    padding: 10px 22px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: flex-end;
    gap: 8px;
    transition: all 0.15s;
}
.btn-forward:hover { background: #eff6ff !important; }
.btn-return-req {
    background: #fff !important;
    color: #b91c1c !important;
    border: 1.5px solid #fca5a5 !important;
    padding: 10px 22px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: flex-end;
    gap: 8px;
    transition: background 0.15s;
}
.btn-return-req:hover { background: #fee2e2 !important; }
.btn-cancel-inline {
    background: #fff !important;
    color: #475569 !important;
    border: 1.5px solid #cbd5e1 !important;
    padding: 10px 16px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s;
}
.btn-cancel-inline:hover { background: #e2e8f0 !important; }
</style>

<div class="pr-container">

    <!-- Header -->
    <div style="margin-bottom: 20px;">
        <h1 class="pr-title">
            <i class="fas fa-clipboard-list" style="color: #002F6C;"></i> Purchase Management
        </h1>
    </div>

    <!-- Alert Notifications -->
    <?php if (!empty($_SESSION['success'])): ?>
        <div style="background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; font-weight: 600; display: flex; align-items: flex-end; gap: 10px;">
            <i class="fas fa-check-circle" style="font-size: 18px;"></i> <?= $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error'])): ?>
        <div style="background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; font-weight: 600; display: flex; align-items: flex-end; gap: 10px;">
            <i class="fas fa-exclamation-circle" style="font-size: 18px;"></i> <?= $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>
        <!-- Main Page Tabs -->
    <div class="main-page-tabs" style="display: flex; gap: 10px; margin-bottom: 24px; border-bottom: 2px solid #e2e8f0; padding-bottom: 12px;">
        <button type="button" id="mainTabPrBtn" onclick="switchPendingSubTab('pr')" style="padding: 10px 24px; font-size: 14px; font-weight: 700; color: #ffffff !important; background-color: #002F6C !important; border: 1.5px solid #002F6C !important; cursor: pointer; border-radius: 8px; display: inline-flex; align-items: flex-end; gap: 8px; transition: all 0.2s;">
            <i class="fas fa-clipboard-check"></i> Purchase Request
        </button>
        <button type="button" id="mainTabHistoryBtn" onclick="switchPendingSubTab('history')" style="padding: 10px 24px; font-size: 14px; font-weight: 700; color: #475569 !important; background-color: #f8fafc !important; border: 1.5px solid #cbd5e1 !important; cursor: pointer; border-radius: 8px; display: inline-flex; align-items: flex-end; gap: 8px; transition: all 0.2s;">
            <i class="fas fa-history"></i> Purchase History
        </button>
    </div>
    <!-- PR Summary Cards -->
    <div class="summary-grid" id="prSummaryCardsGrid">
        <a href="manager_stock_request_review.php" class="summary-card" style="text-decoration: none; color: inherit;">
            <div>
                <div class="summary-card-label">Pending Requests</div>
                <div class="summary-card-value"><?= number_format($cnt_pending_pr) ?></div>
            </div>
            <div class="summary-icon bg-pending"><i class="fas fa-hourglass-half"></i></div>
        </a>
        <a href="manager_purchase_orders.php" class="summary-card" style="text-decoration: none; color: inherit;">
            <div>
                <div class="summary-card-label">POs Generated</div>
                <div class="summary-card-value" style="color: #9333ea;"><?= number_format($cnt_po_generated) ?></div>
            </div>
            <div class="summary-icon bg-waiting"><i class="fas fa-file-invoice"></i></div>
        </a>
        <a href="manager_merchandise_deliveries.php" class="summary-card" style="text-decoration: none; color: inherit;">
            <div>
                <div class="summary-card-label">Pending Deliveries</div>
                <div class="summary-card-value" style="color: #1d4ed8;"><?= number_format($cnt_pending_delivery) ?></div>
            </div>
            <div class="summary-icon bg-total"><i class="fas fa-truck"></i></div>
        </a>
        <a href="admin_inventory_history.php" class="summary-card" style="text-decoration: none; color: inherit;">
            <div>
                <div class="summary-card-label">Completed</div>
                <div class="summary-card-value" style="color: #16a34a;"><?= number_format($cnt_completed) ?></div>
            </div>
            <div class="summary-icon bg-completed"><i class="fas fa-check-circle"></i></div>
        </a>
    </div>

    <!-- Purchase History Summary Cards -->
    <div class="summary-grid" id="historySummaryCardsGrid" style="display: none; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">
        <div class="summary-card">
            <div>
                <div class="summary-card-label">Total Purchase Orders</div>
                <div class="summary-card-value"><?= number_format($cnt_hist_total) ?></div>
            </div>
            <div class="summary-icon bg-total"><i class="fas fa-shopping-cart"></i></div>
        </div>
        <div class="summary-card">
            <div>
                <div class="summary-card-label">Fuel Purchases</div>
                <div class="summary-card-value" style="color: #1d4ed8;"><?= number_format($cnt_hist_fuel) ?></div>
            </div>
            <div class="summary-icon" style="background:#eff6ff; color:#1d4ed8;"><i class="fas fa-gas-pump"></i></div>
        </div>
        <div class="summary-card">
            <div>
                <div class="summary-card-label">Merchandise Purchases</div>
                <div class="summary-card-value" style="color: #9333ea;"><?= number_format($cnt_hist_merch) ?></div>
            </div>
            <div class="summary-icon" style="background:#faf5ff; color:#9333ea;"><i class="fas fa-boxes"></i></div>
        </div>
        <div class="summary-card">
            <div>
                <div class="summary-card-label">Completed Purchases</div>
                <div class="summary-card-value" style="color: #16a34a;"><?= number_format($cnt_hist_completed) ?></div>
            </div>
            <div class="summary-icon bg-completed"><i class="fas fa-check-circle"></i></div>
        </div>
        <div class="summary-card">
            <div>
                <div class="summary-card-label">Cancelled Purchases</div>
                <div class="summary-card-value" style="color: #dc2626;"><?= number_format($cnt_hist_cancelled) ?></div>
            </div>
            <div class="summary-icon" style="background:#fef2f2; color:#dc2626;"><i class="fas fa-times-circle"></i></div>
        </div>
    </div>

    <!-- Sub-tabs Navigation -->
    <div id="pendingCategoryNav" class="sub-tab-nav" style="display: flex; gap: 8px; margin-bottom: 20px;">
        <button type="button" id="subtabMerchBtn" onclick="switchPendingSubTab('merch')" style="padding: 9px 20px; font-size: 13px; font-weight: 600; color: #002F6C !important; border: 1.5px solid #002F6C !important; background: #eff6ff !important; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: flex-end; gap: 7px; border-radius: 8px;">
            <i class="fas fa-boxes"></i> Merchandise
        </button>
        <button type="button" id="subtabFuelBtn" onclick="switchPendingSubTab('fuel')" style="padding: 9px 20px; font-size: 13px; font-weight: 600; color: #64748b !important; border: 1.5px solid #e2e8f0 !important; background: #fff !important; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: flex-end; gap: 7px; border-radius: 8px;">
            <i class="fas fa-gas-pump"></i> Fuel
        </button>

    </div>

    <!-- Merchandise Section -->
    <div id="pendingMerchSection" class="procurement-section" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; overflow: hidden; margin-bottom: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.04);">
        <?php
        // Group merchandise requests by request_no (if set) or by staff_id + date (reliable batch grouping)
        $merch_groups = [];
        foreach ($merch_reqs as $r) {
            $date_key = date('Y-m-d', strtotime($r['created_at']));
            // Use request_no if already assigned, otherwise group by staff_id + submission date
            $group_key = !empty($r['request_no'])
                ? $r['request_no']
                : ('BATCH-' . intval($r['staff_id']) . '-' . str_replace('-', '', $date_key));
            
            if (!isset($merch_groups[$group_key])) {
                // Keep real request_no if it exists; temp groups get a display label assigned after loop
                $merch_groups[$group_key] = [
                    'pr_number'  => !empty($r['request_no']) ? $r['request_no'] : null, // assigned below
                    'original_pr'=> $r['request_no'],
                    'staff_name' => $r['staff_name'] ?: 'Staff',
                    'staff_id'   => $r['staff_id'],
                    'created_at' => $r['created_at'],
                    'status'     => $r['status'] ?: 'Pending Manager Review',
                    'items'      => []
                ];
            }
            $merch_groups[$group_key]['items'][] = $r;
        }
        // Assign stable sequential PR display numbers to temp groups (those without a real request_no)
        $temp_counter = 1;
        $stmt_max_pr = $pdo->query("SELECT MAX(CAST(REGEXP_SUBSTR(request_no, '[0-9]+$') AS UNSIGNED)) FROM stock_requests WHERE station_id = $station_id AND request_no IS NOT NULL AND request_no != ''");
        $max_existing = (int)($stmt_max_pr->fetchColumn() ?: 0);
        foreach ($merch_groups as $gk => &$grp) {
            if ($grp['pr_number'] === null) {
                $grp['pr_number'] = 'PR-' . date('Y') . '-' . str_pad($max_existing + $temp_counter, 4, '0', STR_PAD_LEFT);
                $temp_counter++;
            }
        }
        unset($grp);
        ?>

        <?php if (empty($merch_groups)): ?>
            <div style="padding: 48px; text-align: center; color: #64748b;">
                <i class="fas fa-inbox" style="font-size: 36px; color: #cbd5e1; display: block; margin-bottom: 12px;"></i>
                <div style="font-weight: 700; font-size: 15px; color: #475569;">No pending merchandise requests</div>
                <div style="font-size: 13px; margin-top: 4px;">All merchandise stock requests have been processed.</div>
            </div>
        <?php else: ?>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 13.5px;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                        <th style="width: 50px; padding: 11px 14px;"></th>
                        <th style="padding: 11px 14px; text-align: left; font-size: 10.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .5px;">PR Number</th>
                        <th style="padding: 11px 14px; text-align: left; font-size: 10.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .5px;">Requested By</th>
                        <th style="padding: 11px 14px; text-align: left; font-size: 10.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .5px;">Date Requested</th>
                        <th style="padding: 11px 14px; text-align: center; font-size: 10.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .5px;">No. of Products</th>
                        <th style="padding: 11px 14px; text-align: center; font-size: 10.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .5px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($merch_groups as $g_key => $group):
                        $safe_key = 'merch_' . preg_replace('/[^a-zA-Z0-9]/', '_', $g_key);
                        $item_count = count($group['items']);
                        $item_ids = array_column($group['items'], 'id');
                        $item_ids_str = implode(',', $item_ids);
                        
                        // Auto-generate next PR sequence for the generated PO if original was empty
                        $stmt_lastpr = $pdo->query("SELECT request_no FROM stock_requests WHERE station_id = $station_id AND request_no IS NOT NULL AND request_no != '' ORDER BY id DESC LIMIT 1");
                        $last_pr_raw = $stmt_lastpr->fetchColumn();
                        $next_pr_num = 1;
                        if ($last_pr_raw && preg_match('/(\d+)$/', $last_pr_raw, $m)) {
                            $next_pr_num = (int)$m[1] + 1;
                        }
                        $next_pr_to_gen = 'PR-' . date('Y') . '-' . str_pad($next_pr_num, 4, '0', STR_PAD_LEFT);
                        $final_pr_no = !empty($group['original_pr']) ? $group['original_pr'] : $next_pr_to_gen;
                    ?>
                    <tr id="row_<?= $safe_key ?>" class="pr-clickable-row" onclick="toggleInlinePr('<?= $safe_key ?>')" style="cursor: pointer; border-bottom: 1px solid #f1f5f9; transition: background 0.12s;">
                        <td style="padding: 13px 14px; text-align: center;">
                            <span class="pr-expand-icon"><i class="fas fa-chevron-right"></i></span>
                        </td>
                        <td style="padding: 13px 14px; font-weight: 700; color: #002F6C; font-family: monospace; font-size: 14px;">
                            <?= htmlspecialchars($group['pr_number']) ?>
                        </td>
                        <td style="padding: 13px 14px; color: #475569;"><?= htmlspecialchars($group['staff_name']) ?></td>
                        <td style="padding: 13px 14px; color: #64748b; white-space: nowrap;"><?= date('M d, Y', strtotime($group['created_at'])) ?></td>
                        <td style="padding: 13px 14px; text-align: center; font-weight: 700; color: #002F6C;"><?= $item_count ?> Product<?= $item_count !== 1 ? 's' : '' ?></td>
                        <td style="padding: 13px 14px; text-align: center;">
                            <span class="status-badge status-pending"><?= htmlspecialchars(str_ireplace('Manager Review', '', $group['status'])) ?></span>
                        </td>
                    </tr>
                    
                    <tr id="detail_<?= $safe_key ?>" class="pr-inline-detail">
                        <td colspan="6">
                            <div class="pr-inline-panel" style="padding: 24px 28px 32px 28px; background: #f8fafc; border-top: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0;">
                                <div style="margin-bottom: 16px; display: flex; align-items: flex-end; gap: 10px;">
                                    <i class="fas fa-boxes" style="color: #002F6C; font-size: 18px;"></i>
                                    <span style="font-size: 15px; font-weight: 800; color: #002F6C;">Purchase Request Details</span>
                                </div>
                                
                                <div class="pr-panel-meta">
                                    <div class="pr-panel-meta-item">
                                        <strong>PR Number</strong>
                                        <span style="font-family: monospace;"><?= htmlspecialchars($group['pr_number']) ?></span>
                                    </div>
                                    <div class="pr-panel-meta-item">
                                        <strong>Requested By</strong>
                                        <span><?= htmlspecialchars($group['staff_name']) ?></span>
                                    </div>
                                    <div class="pr-panel-meta-item">
                                        <strong>Request Date</strong>
                                        <span style="color: #475569;"><?= date('M d, Y g:i A', strtotime($group['created_at'])) ?></span>
                                    </div>
                                    <div class="pr-panel-meta-item">
                                        <strong>Type</strong>
                                        <span>Merchandise</span>
                                    </div>
                                </div>
                                
                                <form id="form_<?= $safe_key ?>" method="POST" action="" class="merch-inline-form">
                                    <input type="hidden" name="action" value="generate_merch_pr">
                                    <input type="hidden" name="pr_number" value="<?= htmlspecialchars($final_pr_no) ?>">
                                    
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 18px;">
                                        <div style="display: flex; flex-direction: column; gap: 4px;">
                                            <label style="font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase;">Expected Delivery Date <span style="color: #dc2626;">*</span></label>
                                            <input type="date" name="expected_delivery" min="<?= date('Y-m-d') ?>" required style="padding: 9px 12px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 13px; font-family: inherit;">
                                        </div>
                                        <div style="display: flex; flex-direction: column; gap: 4px;">
                                            <label style="font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase;">Remarks / Notes</label>
                                            <input type="text" name="remarks" placeholder="Optional notes for supplier..." style="padding: 9px 12px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 13px; font-family: inherit;">
                                        </div>
                                    </div>
                                    
                                    <div style="overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 10px; margin-bottom: 16px; background: #fff;">
                                        <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                                            <thead>
                                                <tr style="background: #002F6C; border-bottom: 2px solid #001F4D;">
                                                    <th style="padding: 10px 12px; text-align: left; font-size: 10.5px; font-weight: 700; color: #fff; text-transform: uppercase;">Product ID</th>
                                                    <th style="padding: 10px 12px; text-align: left; font-size: 10.5px; font-weight: 700; color: #fff; text-transform: uppercase;">Product Code</th>
                                                    <th style="padding: 10px 12px; text-align: left; font-size: 10.5px; font-weight: 700; color: #fff; text-transform: uppercase;">Product Name</th>
                                                    <th style="padding: 10px 12px; text-align: center; font-size: 10.5px; font-weight: 700; color: #fff; text-transform: uppercase;">UOM</th>
                                                    <th style="padding: 10px 12px; text-align: center; font-size: 10.5px; font-weight: 700; color: #fff; text-transform: uppercase;">Current Stock</th>
                                                    <th style="padding: 10px 12px; text-align: center; font-size: 10.5px; font-weight: 700; color: #fff; text-transform: uppercase;">Reorder Level</th>
                                                    <th style="padding: 10px 12px; text-align: center; font-size: 10.5px; font-weight: 700; color: #fff; text-transform: uppercase; width: 130px;">Qty to Order <span style="color: #ff8a8a;">*</span></th>
                                                    <th style="padding: 10px 12px; text-align: center; font-size: 10.5px; font-weight: 700; color: #fff; text-transform: uppercase; width: 140px;">Unit Cost <span style="color: #ff8a8a;">*</span></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($group['items'] as $item):
                                                    $item_sku = $item['item_sku'] ?: '—';
                                                    $curr_stock = number_format((float)($item['current_stock'] ?? 0), 2);
                                                    $reorder_lvl = number_format((float)($item['reorder_level'] ?? 0), 2);
                                                    $item_unit = $item['unit'] ?: 'pcs';
                                                    $prod_id = $item['product_id'] ?: $item['id'];
                                                    $formatted_prod_id = 'P' . str_pad($prod_id, 4, '0', STR_PAD_LEFT);
                                                    $sr_id = $item['id'];
                                                    $is_below = ((float)($item['current_stock'] ?? 0)) < ((float)($item['reorder_level'] ?? 0));
                                                    $suggested_cost = number_format((float)($item['unit_cost'] ?? 0), 2, '.', '');
                                                ?>
                                                <tr style="border-bottom: 1px solid #eff6ff;">
                                                    <input type="hidden" name="stock_req_ids[<?= $prod_id ?>]" value="<?= $sr_id ?>">
                                                    <input type="hidden" name="units[<?= $prod_id ?>]" value="<?= htmlspecialchars($item_unit) ?>">
                                                    
                                                    <td style="padding: 10px 12px; font-weight: 700; color: #64748b; font-family: monospace; font-size: 12.5px;"><?= htmlspecialchars($formatted_prod_id) ?></td>
                                                    <td style="padding: 10px 12px; font-family: monospace; font-size: 12px; color: #475569;"><?= htmlspecialchars($item_sku) ?></td>
                                                    <td style="padding: 10px 12px; font-weight: 600; color: #1e293b;"><?= htmlspecialchars($item['item_title']) ?></td>
                                                    <td style="padding: 10px 12px; text-align: center; font-weight: 600; color: #475569;"><?= htmlspecialchars($item_unit) ?></td>
                                                    <td style="padding: 10px 12px; text-align: center; font-weight: 600; color: <?= $is_below ? '#dc2626' : '#16a34a' ?>;"><?= $curr_stock ?></td>
                                                    <td style="padding: 10px 12px; text-align: center; color: #dc2626; font-weight: 600;"><?= $reorder_lvl ?></td>
                                                    <td style="padding: 10px 12px; text-align: center;">
                                                        <input type="number" name="quantities[<?= $prod_id ?>]" min="1" step="1" placeholder="0"
                                                            class="merch-qty-input" data-key="<?= $safe_key ?>"
                                                            style="width: 80px; padding: 5px 8px; border: 1.5px solid #93c5fd; border-radius: 6px; text-align: center; font-weight: 700; font-family: inherit;"
                                                            oninput="updateMerchSummary('<?= $safe_key ?>')">
                                                    </td>
                                                    <td style="padding: 10px 12px; text-align: center;">
                                                        <input type="number" name="unit_costs[<?= $prod_id ?>]" min="0.01" step="0.01" value="" placeholder="0.00"
                                                            class="merch-cost-input" data-key="<?= $safe_key ?>"
                                                            style="width: 110px; padding: 5px 8px; border: 1.5px solid #cbd5e1; border-radius: 6px; text-align: right; font-weight: 700; font-family: inherit;"
                                                            oninput="updateMerchSummary('<?= $safe_key ?>')">
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <div style="display: flex; justify-content: flex-end; margin-bottom: 16px;">
                                        <div style="min-width: 280px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; font-size: 13px;">
                                            <div style="display: flex; justify-content: space-between; margin-bottom: 6px;"><span style="color: #64748b; font-weight: 700;">Total Products</span><span id="merch_count_<?= $safe_key ?>" style="font-weight: 800; color: #002F6C;"><?= $item_count ?></span></div>
                                            <div style="display: flex; justify-content: space-between;"><span style="color: #64748b; font-weight: 700;">Grand Total Amount</span><span id="merch_total_<?= $safe_key ?>" style="font-weight: 900; color: #002F6C;">PHP 0.00</span></div>
                                        </div>
                                    </div>
                                    
                                    <div class="pr-panel-actions">
                                        <button type="button" class="btn-return-req" onclick="openReturnPrModal('<?= htmlspecialchars($group['pr_number'], ENT_QUOTES) ?>', 'merch', '<?= $item_ids_str ?>')">
                                            <i class="fas fa-undo"></i> Return Request
                                        </button>
                                        <button type="submit" class="btn-forward">
                                            <i class="fas fa-file-invoice"></i> Generate Purchase Order
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Fuel Section -->
    <div id="pendingFuelSection" class="procurement-section" style="display: none; background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; overflow: hidden; margin-bottom: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.04);">
        <?php
        // Group fuel requests by request_no (if set) or by staff_id + date (reliable batch grouping)
        $fuel_groups = [];
        foreach ($fuel_reqs as $r) {
            $date_key = date('Y-m-d', strtotime($r['created_at']));
            // Use request_no if already assigned, otherwise group by staff_id + submission date
            $group_key = !empty($r['request_no'])
                ? $r['request_no']
                : ('FBATCH-' . intval($r['staff_id']) . '-' . str_replace('-', '', $date_key));
            
            if (!isset($fuel_groups[$group_key])) {
                $fuel_groups[$group_key] = [
                    'pr_number'  => !empty($r['request_no']) ? $r['request_no'] : null, // assigned below
                    'original_pr'=> $r['request_no'],
                    'staff_name' => $r['staff_name'] ?: 'Staff',
                    'staff_id'   => $r['staff_id'],
                    'created_at' => $r['created_at'],
                    'status'     => $r['status'] ?: 'Pending Manager Review',
                    'items'      => []
                ];
            }
            $fuel_groups[$group_key]['items'][] = $r;
        }
        // Assign stable sequential PR display numbers to temp groups
        $ftemp_counter = 1;
        $stmt_max_fpr = $pdo->query("SELECT MAX(CAST(REGEXP_SUBSTR(request_no, '[0-9]+$') AS UNSIGNED)) FROM fuel_stock_requests WHERE station_id = $station_id AND request_no IS NOT NULL AND request_no != ''");
        $max_existing_fpr = (int)($stmt_max_fpr->fetchColumn() ?: 0);
        foreach ($fuel_groups as $fgk => &$fgrp) {
            if ($fgrp['pr_number'] === null) {
                $fgrp['pr_number'] = 'PR-' . date('Y') . '-' . str_pad($max_existing_fpr + $ftemp_counter, 4, '0', STR_PAD_LEFT);
                $ftemp_counter++;
            }
        }
        unset($fgrp);
        ?>

        <?php if (empty($fuel_groups)): ?>
            <div style="padding: 48px; text-align: center; color: #64748b;">
                <i class="fas fa-gas-pump" style="font-size: 36px; color: #cbd5e1; display: block; margin-bottom: 12px;"></i>
                <div style="font-weight: 700; font-size: 15px; color: #475569;">No pending fuel requests</div>
                <div style="font-size: 13px; margin-top: 4px;">All fuel stock requests have been processed.</div>
            </div>
        <?php else: ?>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 13.5px;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                        <th style="width: 50px; padding: 11px 14px;"></th>
                        <th style="padding: 11px 14px; text-align: left; font-size: 10.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .5px;">PR Number</th>
                        <th style="padding: 11px 14px; text-align: left; font-size: 10.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .5px;">Requested By</th>
                        <th style="padding: 11px 14px; text-align: left; font-size: 10.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .5px;">Date Requested</th>
                        <th style="padding: 11px 14px; text-align: center; font-size: 10.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .5px;">No. of Products</th>
                        <th style="padding: 11px 14px; text-align: center; font-size: 10.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .5px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($fuel_groups as $g_key => $group):
                        $safe_key = 'fuel_' . preg_replace('/[^a-zA-Z0-9]/', '_', $g_key);
                        $item_count = count($group['items']);
                        $item_ids = array_column($group['items'], 'id');
                        $item_ids_str = implode(',', $item_ids);
                        
                        // Auto-generate next PR sequence if original was empty
                        $stmt_lastfpr = $pdo->query("SELECT request_no FROM fuel_stock_requests WHERE station_id = $station_id AND request_no IS NOT NULL AND request_no != '' ORDER BY id DESC LIMIT 1");
                        $last_fpr_raw = $stmt_lastfpr->fetchColumn();
                        $next_fpr_num = 1;
                        if ($last_fpr_raw && preg_match('/(\d+)$/', $last_fpr_raw, $m2)) {
                            $next_fpr_num = (int)$m2[1] + 1;
                        }
                        $next_fpr_to_gen = 'PR-' . date('Y') . '-' . str_pad($next_fpr_num, 4, '0', STR_PAD_LEFT);
                        $final_fpr_no = !empty($group['original_pr']) ? $group['original_pr'] : $next_fpr_to_gen;
                    ?>
                    <tr id="row_<?= $safe_key ?>" class="pr-clickable-row" onclick="toggleInlinePr('<?= $safe_key ?>')" style="cursor: pointer; border-bottom: 1px solid #f1f5f9; transition: background 0.12s;">
                        <td style="padding: 13px 14px; text-align: center;">
                            <span class="pr-expand-icon"><i class="fas fa-chevron-right"></i></span>
                        </td>
                        <td style="padding: 13px 14px; font-weight: 700; color: #002F6C; font-family: monospace; font-size: 14px;">
                            <?= htmlspecialchars($group['pr_number']) ?>
                        </td>
                        <td style="padding: 13px 14px; color: #475569;"><?= htmlspecialchars($group['staff_name']) ?></td>
                        <td style="padding: 13px 14px; color: #64748b; white-space: nowrap;"><?= date('M d, Y', strtotime($group['created_at'])) ?></td>
                        <td style="padding: 13px 14px; text-align: center; font-weight: 700; color: #002F6C;"><?= $item_count ?> Fuel Type<?= $item_count !== 1 ? 's' : '' ?></td>
                        <td style="padding: 13px 14px; text-align: center;">
                            <span class="status-badge status-pending" style="background: #f0f9ff; color: #002F6C; border-color: #bae6fd;"><?= htmlspecialchars(str_ireplace('Manager Review', '', $group['status'])) ?></span>
                        </td>
                    </tr>
                    
                    <tr id="detail_<?= $safe_key ?>" class="pr-inline-detail">
                        <td colspan="6">
                            <div class="pr-inline-panel" style="padding: 0; background: #f8fafc; border-top: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0;">

                                <!-- PR Header Bar -->
                                <div style="background: #002F6C; padding: 16px 24px; display: flex; align-items: flex-end; gap: 10px;">
                                    <i class="fas fa-gas-pump" style="color: #fff; font-size: 18px;"></i>
                                    <span style="font-size: 14px; font-weight: 800; color: #fff; letter-spacing: 0.3px;">Purchase Request Details</span>
                                </div>

                                <div style="padding: 20px 24px 28px 24px;">

                                    <!-- Purchase Request Information -->
                                    <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px 20px; margin-bottom: 16px;">
                                        <div style="font-size: 10.5px; font-weight: 800; color: #002F6C; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 12px;"><i class="fas fa-clipboard-list" style="margin-right: 5px;"></i> Purchase Request Information</div>
                                        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px;">
                                            <div>
                                                <div style="font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 3px;">Purchase Request No.</div>
                                                <div style="font-size: 13px; font-weight: 800; color: #002F6C; font-family: monospace;"><?= htmlspecialchars($group['pr_number']) ?></div>
                                            </div>
                                            <div>
                                                <div style="font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 3px;">Requested By</div>
                                                <div style="font-size: 13px; font-weight: 600; color: #1e293b;"><?= htmlspecialchars($group['staff_name']) ?></div>
                                            </div>
                                            <div>
                                                <div style="font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 3px;">Request Date</div>
                                                <div style="font-size: 13px; font-weight: 600; color: #1e293b;"><?= date('M d, Y', strtotime($group['created_at'])) ?></div>
                                            </div>
                                            <div>
                                                <div style="font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 3px;">Request Time</div>
                                                <div style="font-size: 13px; font-weight: 600; color: #1e293b;"><?= date('g:i A', strtotime($group['created_at'])) ?></div>
                                            </div>
                                        </div>
                                        <?php
                                        // Gather remarks from first item that has them
                                        $pr_remarks = '';
                                        foreach ($group['items'] as $ri) {
                                            if (!empty($ri['staff_remarks'])) { $pr_remarks = $ri['staff_remarks']; break; }
                                        }
                                        ?>
                                        <?php if ($pr_remarks): ?>
                                        <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e2e8f0;">
                                            <div style="font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 4px;">Remarks</div>
                                            <div style="font-size: 12.5px; color: #475569; background: #f8fafc; border-left: 3px solid #002F6C; padding: 8px 12px; border-radius: 4px;"><?= htmlspecialchars($pr_remarks) ?></div>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Form -->
                                    <form id="form_<?= $safe_key ?>" method="POST" action="" class="fuel-inline-form">
                                        <input type="hidden" name="action" value="generate_fuel_pr">
                                        <input type="hidden" name="pr_number" value="<?= htmlspecialchars($final_fpr_no) ?>">

                                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                                <label style="font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase;">Expected Delivery Date <span style="color: #dc2626;">*</span></label>
                                                <input type="date" name="expected_delivery" min="<?= date('Y-m-d') ?>" required style="padding: 9px 12px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 13px; font-family: inherit;">
                                            </div>
                                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                                <label style="font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase;">Remarks / Notes</label>
                                                <input type="text" name="remarks" placeholder="Optional notes for supplier..." style="padding: 9px 12px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 13px; font-family: inherit;">
                                            </div>
                                        </div>

                                        <!-- Requested Fuel Table -->
                                        <div style="margin-bottom: 6px; font-size: 10.5px; font-weight: 800; color: #002F6C; text-transform: uppercase; letter-spacing: .5px;"><i class="fas fa-gas-pump" style="margin-right: 5px;"></i> Requested Fuel</div>
                                        <div style="overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 10px; margin-bottom: 16px; background: #fff;">
                                            <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                                                <thead>
                                                    <tr style="background: #002F6C; border-bottom: 2px solid #001F4D;">
                                                        <th style="padding: 10px 12px; text-align: left; font-size: 10.5px; font-weight: 700; color: #fff; text-transform: uppercase;">Fuel ID</th>
                                                        <th style="padding: 10px 12px; text-align: left; font-size: 10.5px; font-weight: 700; color: #fff; text-transform: uppercase;">Fuel Type</th>
                                                        <th style="padding: 10px 12px; text-align: center; font-size: 10.5px; font-weight: 700; color: #fff; text-transform: uppercase;">UGT No.</th>
                                                        <th style="padding: 10px 12px; text-align: center; font-size: 10.5px; font-weight: 700; color: #fff; text-transform: uppercase;">Tank Capacity</th>
                                                        <th style="padding: 10px 12px; text-align: center; font-size: 10.5px; font-weight: 700; color: #fff; text-transform: uppercase;">Current Liters</th>
                                                        <th style="padding: 10px 12px; text-align: center; font-size: 10.5px; font-weight: 700; color: #fff; text-transform: uppercase;">Reorder Level</th>
                                                        <th style="padding: 10px 12px; text-align: center; font-size: 10.5px; font-weight: 700; color: #fff; text-transform: uppercase; width: 140px;">Liters to Order <span style="color: #ff8a8a;">*</span></th>
                                                        <th style="padding: 10px 12px; text-align: center; font-size: 10.5px; font-weight: 700; color: #fff; text-transform: uppercase; width: 140px;">Cost per Liter <span style="color: #ff8a8a;">*</span></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($group['items'] as $fitem):
                                                        $fuel_type_name  = $fitem['item_title'] ?: ($fitem['fuel_type'] ?? '');
                                                        $curr_stock      = number_format((float)($fitem['current_stock'] ?? 0), 2);
                                                        $reorder_lvl     = number_format((float)($fitem['reorder_level'] ?? 0), 2);
                                                        $tank_cap        = number_format((float)($fitem['tank_capacity'] ?? 0), 2);
                                                        $ugt_no          = $fitem['ugt_no'] ?: '—';
                                                        $prod_id         = $fitem['product_id'] ?: $fitem['id'];
                                                        $formatted_fid   = 'F' . str_pad($prod_id, 4, '0', STR_PAD_LEFT);
                                                        $sr_id           = $fitem['id'];
                                                        $is_below        = ((float)($fitem['current_stock'] ?? 0)) < ((float)($fitem['reorder_level'] ?? 0));
                                                        $suggested_cost  = number_format((float)($fitem['unit_cost'] ?? 0), 2, '.', '');
                                                    ?>
                                                    <tr style="border-bottom: 1px solid #f1f5f9;">
                                                        <input type="hidden" name="fuel_req_ids[<?= $prod_id ?>]" value="<?= $sr_id ?>">
                                                        <td style="padding: 11px 12px; font-weight: 700; color: #64748b; font-family: monospace; font-size: 12.5px;"><?= htmlspecialchars($formatted_fid) ?></td>
                                                        <td style="padding: 11px 12px; font-weight: 700; color: #1e293b;"><?= htmlspecialchars($fuel_type_name) ?></td>
                                                        <td style="padding: 11px 12px; text-align: center;">
                                                            <span style="background: #dbeafe; color: #1d4ed8; font-size: 11.5px; font-weight: 700; padding: 3px 10px; border-radius: 20px; font-family: monospace;"><?= htmlspecialchars($ugt_no) ?></span>
                                                        </td>
                                                        <td style="padding: 11px 12px; text-align: center; font-weight: 600; color: #475569;"><?= $tank_cap ?> L</td>
                                                        <td style="padding: 11px 12px; text-align: center; font-weight: 700; color: <?= $is_below ? '#dc2626' : '#16a34a' ?>;">
                                                            <?= $curr_stock ?> L
                                                            <?php if ($is_below): ?>
                                                            <div style="font-size: 10px; color: #dc2626; font-weight: 600;"><i class="fas fa-exclamation-triangle" style="margin-right: 3px;"></i> Below Reorder</div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td style="padding: 11px 12px; text-align: center; color: #dc2626; font-weight: 700;"><?= $reorder_lvl ?> L</td>
                                                        <td style="padding: 11px 12px; text-align: center;">
                                                            <input type="number" name="fuel_quantities[<?= $prod_id ?>]" min="1" step="any" placeholder="0"
                                                                class="fuel-qty-input" data-key="<?= $safe_key ?>"
                                                                style="width: 110px; padding: 6px 8px; border: 1.5px solid #cbd5e1; border-radius: 6px; text-align: center; font-weight: 700; font-family: inherit; font-size: 13px;"
                                                                oninput="updateFuelSummary('<?= $safe_key ?>')"
                                                            >
                                                            <div style="font-size: 9.5px; color: #94a3b8; margin-top: 2px;">Liters (L)</div>
                                                        </td>
                                                        <td style="padding: 11px 12px; text-align: center;">
                                                            <input type="number" name="fuel_unit_costs[<?= $prod_id ?>]" min="0.01" step="0.01" value="" placeholder="0.00"
                                                                class="fuel-cost-input" data-key="<?= $safe_key ?>"
                                                                style="width: 110px; padding: 6px 8px; border: 1.5px solid #cbd5e1; border-radius: 6px; text-align: right; font-weight: 700; font-family: inherit; font-size: 13px;"
                                                                oninput="updateFuelSummary('<?= $safe_key ?>')"
                                                            >
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>

                                        <div style="display: flex; justify-content: flex-end; margin-bottom: 16px;">
                                            <div style="min-width: 300px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; font-size: 13px;">
                                                <div style="display: flex; justify-content: space-between; margin-bottom: 6px;"><span style="color: #64748b; font-weight: 700;">Total Fuel Types</span><span id="fuel_count_<?= $safe_key ?>" style="font-weight: 800; color: #002F6C;"><?= $item_count ?></span></div>
                                                <div style="display: flex; justify-content: space-between;"><span style="color: #64748b; font-weight: 700;">Grand Total Amount</span><span id="fuel_total_<?= $safe_key ?>" style="font-weight: 900; color: #002F6C;">PHP 0.00</span></div>
                                            </div>
                                        </div>

                                        <!-- Actions -->
                                        <div class="pr-panel-actions" style="padding-bottom: 16px; margin-bottom: 8px;">
                                            <button type="button" class="btn-return-req" onclick="openReturnPrModal('<?= htmlspecialchars($group['pr_number'], ENT_QUOTES) ?>', 'fuel', '<?= $item_ids_str ?>')">
                                                <i class="fas fa-undo"></i> Return Request
                                            </button>
                                            <button type="submit" class="btn-forward" style="color: #002F6C !important; border-color: #002F6C !important; padding: 0 22px !important;">
                                                <i class="fas fa-file-invoice"></i> Generate Purchase Order
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    
    <!-- Purchase History Section -->
    <div id="purchaseHistorySection" class="procurement-section" style="display: none; background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 20px; margin-bottom: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.04);">
        <!-- Filter Bar -->
        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 16px; margin-bottom: 20px;">
            <div style="display: flex; flex-wrap: nowrap; gap: 8px; align-items: flex-end; overflow-x: auto;">
                <!-- Search -->
                <div style="flex: 1 1 160px; min-width: 140px;">
                    <label style="display: block; font-size: 10px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 4px; white-space: nowrap;">Search PO / DR / Invoice</label>
                    <input type="text" id="histSearchPo" onkeyup="filterPurchaseHistory()" placeholder="Search PO, DR, invoice..." style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; box-sizing: border-box;">
                </div>

                <!-- Category -->
                <div style="flex: 0 0 130px;">
                    <label style="display: block; font-size: 10px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 4px; white-space: nowrap;">Category</label>
                    <select id="histCategoryFilter" onchange="filterPurchaseHistory()" style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px;">
                        <option value="">All Categories</option>
                        <option value="fuel">Fuel</option>
                        <option value="merchandise">Merchandise</option>
                    </select>
                </div>

                <!-- Supplier -->
                <div style="flex: 0 0 150px;">
                    <label style="display: block; font-size: 10px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 4px; white-space: nowrap;">Supplier</label>
                    <select id="histSupplierFilter" onchange="filterPurchaseHistory()" style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px;">
                        <option value="">All Suppliers</option>
                        <option value="Petron Corporation">Petron Corporation</option>
                    </select>
                </div>

                <!-- From Date -->
                <div style="flex: 0 0 auto;">
                    <label style="display: block; font-size: 10px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 4px; white-space: nowrap;">From Date</label>
                    <input type="date" id="histStartDate" onchange="filterPurchaseHistory()" style="padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; width: 130px;">
                </div>

                <span style="color: #94a3b8; font-weight: 700; padding-bottom: 8px; flex-shrink: 0;">–</span>

                <!-- To Date -->
                <div style="flex: 0 0 auto;">
                    <label style="display: block; font-size: 10px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 4px; white-space: nowrap;">To Date</label>
                    <input type="date" id="histEndDate" onchange="filterPurchaseHistory()" style="padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; width: 130px;">
                </div>

                <!-- Status -->
                <div style="flex: 0 0 140px;">
                    <label style="display: block; font-size: 10px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 4px; white-space: nowrap;">Status</label>
                    <select id="histStatusFilter" onchange="filterPurchaseHistory()" style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px;">
                        <option value="">All Statuses</option>
                        <option value="Completed">Received / Completed</option>
                        <option value="Pending">Approved / Pending Delivery</option>
                        <option value="Cancelled">Cancelled / Rejected</option>
                    </select>
                </div>

                <!-- Buttons — inline with inputs -->
                <div style="display: flex; gap: 6px; flex-shrink: 0; padding-bottom: 1px;">
                    <button type="button" onclick="filterPurchaseHistory()" style="height: 34px; padding: 0 14px; background: #002F6C; color: #fff; border: none; border-radius: 6px; font-weight: 700; font-size: 12px; cursor: pointer; display: flex; align-items: center; gap: 5px; white-space: nowrap;">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <button type="button" onclick="resetHistoryFilter()" style="height: 34px; padding: 0 14px; background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; border-radius: 6px; font-weight: 700; font-size: 12px; cursor: pointer; display: flex; align-items: center; gap: 5px; white-space: nowrap;">
                        <i class="fas fa-undo"></i> Reset
                    </button>
                </div>
            </div>
        </div>

        <!-- Purchase History Table -->

        <div style="overflow-x: auto;">
            <table class="table-pr" id="purchaseHistoryTable">
                <thead>
                    <tr style="background: #002F6C; color: #fff;">
                        <th style="color: #fff;">PO No.</th>
                        <th style="color: #fff;">Category</th>
                        <th style="color: #fff;">Supplier</th>
                        <th style="color: #fff;">Date Ordered</th>
                        <th style="color: #fff;">Date Received</th>
                        <th style="color: #fff;">Total Amount</th>
                        <th style="color: #fff;">Status</th>
                        <th style="color: #fff; text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody id="purchaseHistoryTbody">
                    <?php if (empty($purchase_history_list)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 40px; color: #94a3b8;">No purchase history records found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($purchase_history_list as $ph_item): ?>
                        <tr data-po="<?= htmlspecialchars($ph_item['po_number']) ?>" data-category="<?= $ph_item['category_type'] ?>" data-supplier="<?= htmlspecialchars($ph_item['supplier_name']) ?>" data-status="<?= htmlspecialchars($ph_item['status']) ?>" data-date="<?= date('Y-m-d', strtotime($ph_item['date_ordered'])) ?>">
                            <td style="font-weight: 800; color: #002F6C; font-family: monospace; font-size: 14px;">
                                <?= htmlspecialchars($ph_item['po_number']) ?>
                            </td>
                            <td>
                                <?php if ($ph_item['category_type'] === 'fuel'): ?>
                                    <span style="background:#eff6ff; color:#1d4ed8; font-weight:700; padding:4px 10px; border-radius:12px; font-size:12px;"><i class="fas fa-gas-pump"></i> Fuel</span>
                                <?php else: ?>
                                    <span style="background:#f0fdf4; color:#16a34a; font-weight:700; padding:4px 10px; border-radius:12px; font-size:12px;"><i class="fas fa-box"></i> Merchandise</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight: 600; color: #334155;"><?= htmlspecialchars($ph_item['supplier_name']) ?></td>
                            <td style="color: #64748b; white-space: nowrap;"><?= date('M d, Y', strtotime($ph_item['date_ordered'])) ?></td>
                            <td style="color: #64748b; white-space: nowrap;"><?= !empty($ph_item['date_received']) && $ph_item['date_received'] !== '0000-00-00 00:00:00' ? date('M d, Y', strtotime($ph_item['date_received'])) : '—' ?></td>
                            <td style="font-weight: 800; color: #002F6C;">₱<?= number_format((float)$ph_item['total_amount'], 2) ?></td>
                            <td>
                                <?php
                                $st = strtolower($ph_item['status']);
                                $badge_class = 'status-pending';
                                if (in_array($st, ['completed', 'received', 'stock-in complete'])) $badge_class = 'status-approved';
                                elseif (in_array($st, ['cancelled', 'rejected', 'withdrawn'])) $badge_class = 'status-cancelled';
                                ?>
                                <span class="status-badge <?= $badge_class ?>"><?= htmlspecialchars($ph_item['status']) ?></span>
                            </td>
                            <td style="text-align: center; white-space: nowrap;">
                                <a href="print_po_new.php?po_id=<?= urlencode($ph_item['po_number']) ?>&batch_id=<?= urlencode($ph_item['po_number']) ?>&type=<?= urlencode($ph_item['category_type']) ?>" target="_blank" class="btn-pr btn-outline-pr" title="Print Purchase Order" style="padding:5px 12px; font-size:12px; text-decoration:none;">
                                    <i class="fas fa-print"></i> Print PO
                                </a>
                                <a href="print_supplier_invoice.php?po_id=<?= urlencode($ph_item['po_number']) ?>&batch_id=<?= urlencode($ph_item['po_number']) ?>&type=<?= urlencode($ph_item['category_type']) ?>" target="_blank" class="btn-pr btn-outline-pr" title="Print Invoice" style="padding:5px 12px; font-size:12px; text-decoration:none;">
                                    <i class="fas fa-file-invoice"></i> Invoice
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- View Purchase History Modal -->
    <div id="viewPurchaseHistoryModal" class="modal-overlay">
        <div class="modal-box" style="max-width: 900px;">
            <div class="modal-header" style="background: #002F6C; color: #fff; padding: 18px 24px; display: flex; justify-content: space-between; align-items: flex-end; border-radius: 16px 16px 0 0;">
                <h3 style="margin: 0; font-size: 18px; font-weight: 800; display: flex; align-items: flex-end; gap: 10px;">
                    <i class="fas fa-file-invoice"></i> <span id="modalPoTitle">Purchase History Details</span>
                </h3>
                <button type="button" onclick="closePurchaseHistoryModal()" style="background: none; border: none; color: #fff; font-size: 20px; cursor: pointer;"><i class="fas fa-times"></i></button>
            </div>
            
            <div class="modal-body" style="padding: 24px; overflow-y: auto; max-height: calc(100vh - 180px);">
                <!-- Section 1: Purchase Information -->
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px; margin-bottom: 20px;">
                    <h4 style="margin: 0 0 14px 0; color: #002F6C; font-size: 14px; font-weight: 800; text-transform: uppercase; letter-spacing: .5px; display: flex; align-items: flex-end; gap: 8px;">
                        <i class="fas fa-info-circle"></i> Purchase Information
                    </h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px 20px; font-size: 13px;">
                        <div><strong style="color:#64748b; font-size:11px; text-transform:uppercase; display:block;">Purchase Order No.</strong> <span id="mPoNo" style="font-weight:700; color:#002F6C;">-</span></div>
                        <div><strong style="color:#64748b; font-size:11px; text-transform:uppercase; display:block;">Category</strong> <span id="mCategory" style="font-weight:700;">-</span></div>
                        <div><strong style="color:#64748b; font-size:11px; text-transform:uppercase; display:block;">Supplier</strong> <span id="mSupplier" style="font-weight:700;">-</span></div>
                        <div><strong style="color:#64748b; font-size:11px; text-transform:uppercase; display:block;">Requested By</strong> <span id="mRequestedBy">-</span></div>
                        <div><strong style="color:#64748b; font-size:11px; text-transform:uppercase; display:block;">Approved By</strong> <span id="mApprovedBy">-</span></div>
                        <div><strong style="color:#64748b; font-size:11px; text-transform:uppercase; display:block;">Date Ordered</strong> <span id="mDateOrdered">-</span></div>
                        <div><strong style="color:#64748b; font-size:11px; text-transform:uppercase; display:block;">Date Received</strong> <span id="mDateReceived">-</span></div>
                        <div><strong style="color:#64748b; font-size:11px; text-transform:uppercase; display:block;">Status</strong> <span id="mStatus">-</span></div>
                    </div>
                </div>

                <!-- Section 2: Ordered Items -->
                <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px; margin-bottom: 20px;">
                    <h4 style="margin: 0 0 14px 0; color: #002F6C; font-size: 14px; font-weight: 800; text-transform: uppercase; letter-spacing: .5px; display: flex; align-items: flex-end; gap: 8px;">
                        <i class="fas fa-boxes"></i> Ordered Items
                    </h4>
                    <div id="modalItemsTableContainer" style="overflow-x: auto;">
                        <!-- Dynamically renders Merchandise or Fuel Table -->
                    </div>
                </div>

                <!-- Section 3: Delivery Information -->
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px;">
                    <h4 style="margin: 0 0 14px 0; color: #002F6C; font-size: 14px; font-weight: 800; text-transform: uppercase; letter-spacing: .5px; display: flex; align-items: flex-end; gap: 8px;">
                        <i class="fas fa-truck-loading"></i> Delivery Information
                    </h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px 20px; font-size: 13px;">
                        <div><strong style="color:#64748b; font-size:11px; text-transform:uppercase; display:block;">Delivery Receipt No. (DR)</strong> <span id="mDrNo" style="font-weight:700; color:#002F6C;">-</span></div>
                        <div><strong style="color:#64748b; font-size:11px; text-transform:uppercase; display:block;">Sales Invoice No.</strong> <span id="mInvoiceNo" style="font-weight:700; color:#16a34a;">-</span></div>
                        <div><strong style="color:#64748b; font-size:11px; text-transform:uppercase; display:block;">Received By</strong> <span id="mReceivedBy">-</span></div>
                        <div><strong style="color:#64748b; font-size:11px; text-transform:uppercase; display:block;">Delivery Date</strong> <span id="mDeliveryDate">-</span></div>
                    </div>
                </div>
            </div>

            <div class="modal-footer" style="padding: 16px 24px; background: #f8fafc; border-top: 1px solid #e2e8f0; border-radius: 0 0 16px 16px; display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" id="modalPrintPoBtn" class="btn-pr btn-outline-pr" onclick="printModalPO()">
                    <i class="fas fa-print"></i> Print Purchase Order
                </button>
                <button type="button" id="modalPrintInvoiceBtn" class="btn-pr btn-primary-pr" onclick="printModalInvoice()">
                    <i class="fas fa-file-invoice-dollar"></i> Print Sales Invoice
                </button>
                <button type="button" class="btn-pr btn-outline-pr" onclick="closePurchaseHistoryModal()">
                    Close
                </button>
            </div>
        </div>
    </div>

    
    <!-- Return Request Modal -->
    <div class="modal-overlay" id="returnPrModal" style="z-index: 10030;">
        <div class="modal-box" style="max-width: 480px;">
            <div class="modal-header" style="background: #b91c1c;">
                <h3 class="modal-title"><i class="fas fa-undo"></i> Return Purchase Request</h3>
            </div>
            <form method="POST" action="" id="returnPrForm">
                <input type="hidden" name="action" value="return_pr_to_staff">
                <input type="hidden" name="pr_number" id="returnPrNumber" value="">
                <input type="hidden" name="pr_type" id="returnPrType" value="">
                <input type="hidden" name="request_ids" id="returnRequestIds" value="">
                <div class="modal-body">
                    <p style="font-size:13.5px; color:#475569; margin:0 0 14px 0;">Specify the reason for returning this request to staff for correction.</p>
                    <div class="field-group">
                        <label>Reason / Notes <span style="color:#dc2626;">*</span></label>
                        <textarea name="return_reason" id="returnPrReason" rows="4" required placeholder="e.g. Incorrect quantity, wrong product listed..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-pr btn-outline-pr" onclick="closeModal('returnPrModal')">Cancel</button>
                    <button type="submit" class="btn-pr" style="background:#b91c1c !important; color:#fff !important; border:none !important;"><i class="fas fa-paper-plane"></i> Return to Staff</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
var currentPoItemForModal = null;

function switchPendingSubTab(type) {
    var mainPrBtn   = document.getElementById('mainTabPrBtn');
    var mainHistBtn = document.getElementById('mainTabHistoryBtn');

    var merchBtn = document.getElementById('subtabMerchBtn');
    var fuelBtn  = document.getElementById('subtabFuelBtn');
    var catNav   = document.getElementById('pendingCategoryNav');
    
    var merchSec  = document.getElementById('pendingMerchSection');
    var fuelSec   = document.getElementById('pendingFuelSection');
    var histSec   = document.getElementById('purchaseHistorySection');

    var prCards   = document.getElementById('prSummaryCardsGrid');
    var histCards = document.getElementById('historySummaryCardsGrid');

    if (type === 'history') {
        if (mainHistBtn) {
            mainHistBtn.style.setProperty('color', '#ffffff', 'important');
            mainHistBtn.style.setProperty('background-color', '#002F6C', 'important');
            mainHistBtn.style.setProperty('border', '1.5px solid #002F6C', 'important');
        }
        if (mainPrBtn) {
            mainPrBtn.style.setProperty('color', '#475569', 'important');
            mainPrBtn.style.setProperty('background-color', '#f8fafc', 'important');
            mainPrBtn.style.setProperty('border', '1.5px solid #cbd5e1', 'important');
        }
        if (histSec)   histSec.style.setProperty('display', 'block', 'important');
        if (merchSec)  merchSec.style.setProperty('display', 'none', 'important');
        if (fuelSec)   fuelSec.style.setProperty('display', 'none', 'important');
        if (catNav)    catNav.style.setProperty('display', 'none', 'important');
        if (prCards)   prCards.style.setProperty('display', 'none', 'important');
        if (histCards) histCards.style.setProperty('display', 'none', 'important');
        filterPurchaseHistory();
    } else {
        if (mainPrBtn) {
            mainPrBtn.style.setProperty('color', '#ffffff', 'important');
            mainPrBtn.style.setProperty('background-color', '#002F6C', 'important');
            mainPrBtn.style.setProperty('border', '1.5px solid #002F6C', 'important');
        }
        if (mainHistBtn) {
            mainHistBtn.style.setProperty('color', '#475569', 'important');
            mainHistBtn.style.setProperty('background-color', '#f8fafc', 'important');
            mainHistBtn.style.setProperty('border', '1.5px solid #cbd5e1', 'important');
        }
        if (prCards)  prCards.style.setProperty('display', 'grid', 'important');
        if (histCards) histCards.style.setProperty('display', 'none', 'important');
        if (histSec)  histSec.style.setProperty('display', 'none', 'important');
        if (catNav)   catNav.style.setProperty('display', 'flex', 'important');

        if (type === 'fuel') {
            if (fuelBtn) {
                fuelBtn.style.setProperty('color', '#002F6C', 'important');
                fuelBtn.style.setProperty('background-color', '#eff6ff', 'important');
                fuelBtn.style.setProperty('border', '1.5px solid #002F6C', 'important');
            }
            if (merchBtn) {
                merchBtn.style.setProperty('color', '#64748b', 'important');
                merchBtn.style.setProperty('background-color', '#fff', 'important');
                merchBtn.style.setProperty('border', '1.5px solid #e2e8f0', 'important');
            }
            if (fuelSec)  fuelSec.style.setProperty('display', 'block', 'important');
            if (merchSec) merchSec.style.setProperty('display', 'none', 'important');
        } else {
            // default 'pr' or 'merch'
            if (merchBtn) {
                merchBtn.style.setProperty('color', '#002F6C', 'important');
                merchBtn.style.setProperty('background-color', '#eff6ff', 'important');
                merchBtn.style.setProperty('border', '1.5px solid #002F6C', 'important');
            }
            if (fuelBtn) {
                fuelBtn.style.setProperty('color', '#64748b', 'important');
                fuelBtn.style.setProperty('background-color', '#fff', 'important');
                fuelBtn.style.setProperty('border', '1.5px solid #e2e8f0', 'important');
            }
            if (merchSec) merchSec.style.setProperty('display', 'block', 'important');
            if (fuelSec)  fuelSec.style.setProperty('display', 'none', 'important');
        }
    }

    try {
        var url = new URL(window.location);
        url.searchParams.set('tab', type);
        window.history.replaceState({}, '', url);
        localStorage.setItem('pr_review_active_subtab', type);
    } catch(e) {}
}

document.addEventListener('DOMContentLoaded', function() {
    var urlParams = new URLSearchParams(window.location.search);
    var tabParam = urlParams.get('tab');
    var savedTab = tabParam || localStorage.getItem('pr_review_active_subtab') || 'merch';
    switchPendingSubTab(savedTab);
});

// Inline accordion toggle
var _openPrKey = null;
function toggleInlinePr(key) {
    var detailRow = document.getElementById('detail_' + key);
    var headerRow = document.getElementById('row_' + key);
    if (!detailRow) return;

    if (detailRow.classList.contains('open')) {
        detailRow.classList.remove('open');
        headerRow.classList.remove('expanded');
        _openPrKey = null;
        return;
    }

    if (_openPrKey && _openPrKey !== key) {
        var prev = document.getElementById('detail_' + _openPrKey);
        var prevRow = document.getElementById('row_' + _openPrKey);
        if (prev) prev.classList.remove('open');
        if (prevRow) prevRow.classList.remove('expanded');
    }
    
    detailRow.classList.add('open');
    headerRow.classList.add('expanded');
    _openPrKey = key;
}

// Modal helpers
function openReturnPrModal(prNo, type, reqIds) {
    document.getElementById('returnPrNumber').value = prNo;
    document.getElementById('returnPrType').value   = type;
    document.getElementById('returnRequestIds').value = reqIds || '';
    document.getElementById('returnPrReason').value = '';
    openModal('returnPrModal');
}

function openModal(id) {
    var el = document.getElementById(id);
    if (el) {
        el.classList.add('open');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(id) {
    var el = document.getElementById(id);
    if (el) {
        el.classList.remove('open');
        document.body.style.overflow = '';
    }
}

function formatMoney(value) {
    return 'PHP ' + Number(value || 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function updateMerchSummary(key) {
    var form = document.getElementById('form_' + key);
    if (!form) return;
    var qtyInputs = form.querySelectorAll('.merch-qty-input');
    var totalQty = 0;
    var grandTotal = 0;

    qtyInputs.forEach(function(input) {
        var qty = parseFloat(input.value) || 0;
        var cost = parseFloat(input.dataset.unitCost) || 0;
        var rowTotal = qty * cost;
        totalQty += qty;
        grandTotal += rowTotal;
        var rowTotalEl = document.getElementById('total_' + input.dataset.itemId);
        if (rowTotalEl) {
            rowTotalEl.textContent = formatMoney(rowTotal);
        }
    });

    var countEl = document.getElementById('summary_count_' + key);
    var totalEl = document.getElementById('summary_total_' + key);
    if (countEl) countEl.textContent = totalQty;
    if (totalEl) totalEl.textContent = formatMoney(grandTotal);
}

function updateFuelSummary(key) {
    var form = document.getElementById('form_' + key);
    if (!form) return;
    var litersInput = form.querySelector('.fuel-liters-input');
    var liters = parseFloat(litersInput ? litersInput.value : 0) || 0;
    var cost = parseFloat(litersInput ? litersInput.dataset.costPerLiter : 0) || 0;
    var total = liters * cost;

    var calcEl = document.getElementById('calc_total_' + key);
    var summaryEl = document.getElementById('summary_total_' + key);
    if (calcEl) calcEl.textContent = formatMoney(total);
    if (summaryEl) summaryEl.textContent = formatMoney(total);
}

function filterPurchaseHistory() {
    var search = (document.getElementById('histSearchPo')?.value || '').toLowerCase().trim();
    var cat = (document.getElementById('histCategoryFilter')?.value || '').toLowerCase();
    var supp = (document.getElementById('histSupplierFilter')?.value || '').toLowerCase();
    var start = document.getElementById('histStartDate')?.value || '';
    var end = document.getElementById('histEndDate')?.value || '';
    var status = (document.getElementById('histStatusFilter')?.value || '').toLowerCase();

    var rows = document.querySelectorAll('#purchaseHistoryTbody tr');
    rows.forEach(function(r) {
        if (!r.getAttribute('data-po')) return;
        var po = (r.getAttribute('data-po') || '').toLowerCase();
        var rCat = (r.getAttribute('data-category') || '').toLowerCase();
        var rSupp = (r.getAttribute('data-supplier') || '').toLowerCase();
        var rStatus = (r.getAttribute('data-status') || '').toLowerCase();
        var rDate = r.getAttribute('data-date') || '';
        var text = r.innerText.toLowerCase();

        var show = true;
        if (search && !text.includes(search)) show = false;
        if (cat && rCat !== cat) show = false;
        if (supp && !rSupp.includes(supp)) show = false;
        if (status && !rStatus.includes(status)) show = false;
        if (start && rDate < start) show = false;
        if (end && rDate > end) show = false;

        r.style.display = show ? '' : 'none';
    });
}

function resetHistoryFilter() {
    ['histSearchPo', 'histCategoryFilter', 'histSupplierFilter', 'histStartDate', 'histEndDate', 'histStatusFilter'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.value = '';
    });
    filterPurchaseHistory();
}

function openPurchaseHistoryModal(item) {
    currentPoItemForModal = item;
    
    document.getElementById('modalPoTitle').innerText = 'Purchase History - ' + (item.po_number || 'N/A');
    document.getElementById('mPoNo').innerText = item.po_number || 'N/A';
    document.getElementById('mCategory').innerHTML = item.category_type === 'fuel' ? '<i class="fas fa-gas-pump"></i> Fuel' : '<i class="fas fa-box"></i> Merchandise';
    document.getElementById('mSupplier').innerText = item.supplier_name || 'Petron Corporation';
    document.getElementById('mRequestedBy').innerText = item.requested_by_name || 'Manager';
    document.getElementById('mApprovedBy').innerText = item.approved_by_name || 'Admin';
    document.getElementById('mDateOrdered').innerText = item.date_ordered ? item.date_ordered.substring(0, 10) : '—';
    document.getElementById('mDateReceived').innerText = item.date_received && item.date_received !== '0000-00-00 00:00:00' ? item.date_received.substring(0, 10) : '—';
    document.getElementById('mStatus').innerHTML = '<span class="status-badge status-approved">' + (item.status || 'Completed') + '</span>';

    // Delivery Info
    document.getElementById('mDrNo').innerText = item.dr_number || 'N/A';
    document.getElementById('mInvoiceNo').innerText = item.sales_invoice_no || 'N/A';
    document.getElementById('mReceivedBy').innerText = item.received_by_name || 'Staff';
    document.getElementById('mDeliveryDate').innerText = item.delivery_date || item.date_received || '—';

    // Items table
    var container = document.getElementById('modalItemsTableContainer');
    var html = '';

    if (item.category_type === 'merchandise') {
        html += '<table style="width:100%; border-collapse:collapse; font-size:13px;">';
        html += '<thead><tr style="background:#f8fafc; border-bottom:1.5px solid #e2e8f0; color:#475569; font-size:11px; text-transform:uppercase;">';
        html += '<th style="padding:10px 12px; text-align:left;">SKU</th>';
        html += '<th style="padding:10px 12px; text-align:left;">Product</th>';
        html += '<th style="padding:10px 12px; text-align:center;">Qty</th>';
        html += '<th style="padding:10px 12px; text-align:center;">UOM</th>';
        html += '<th style="padding:10px 12px; text-align:right;">Unit Cost</th>';
        html += '<th style="padding:10px 12px; text-align:right;">Total</th>';
        html += '</tr></thead><tbody>';

        if (item.items && item.items.length > 0) {
            item.items.forEach(function(it) {
                var uCost = parseFloat(it.unit_price || 0);
                var tCost = parseFloat(it.total_price || (uCost * (it.quantity || 1)));
                html += '<tr style="border-bottom:1px solid #f1f5f9;">';
                html += '<td style="padding:10px 12px; font-family:monospace; color:#002F6C; font-weight:700;">' + (it.sku || 'N/A') + '</td>';
                html += '<td style="padding:10px 12px; font-weight:600; color:#334155;">' + (it.product_name || 'Item') + '</td>';
                html += '<td style="padding:10px 12px; text-align:center; font-weight:700;">' + (it.quantity || 1) + '</td>';
                html += '<td style="padding:10px 12px; text-align:center; color:#64748b;">' + (it.unit || 'pcs') + '</td>';
                html += '<td style="padding:10px 12px; text-align:right;">₱' + uCost.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + '</td>';
                html += '<td style="padding:10px 12px; text-align:right; font-weight:700; color:#002F6C;">₱' + tCost.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + '</td>';
                html += '</tr>';
            });
        } else {
            html += '<tr><td colspan="6" style="padding:15px; text-align:center; color:#94a3b8;">No items detailed</td></tr>';
        }
        html += '</tbody></table>';
    } else {
        // Fuel
        html += '<table style="width:100%; border-collapse:collapse; font-size:13px;">';
        html += '<thead><tr style="background:#f8fafc; border-bottom:1.5px solid #e2e8f0; color:#475569; font-size:11px; text-transform:uppercase;">';
        html += '<th style="padding:10px 12px; text-align:left;">Fuel Type</th>';
        html += '<th style="padding:10px 12px; text-align:center;">Liters</th>';
        html += '<th style="padding:10px 12px; text-align:right;">Cost/Liter</th>';
        html += '<th style="padding:10px 12px; text-align:right;">Total</th>';
        html += '</tr></thead><tbody>';

        if (item.items && item.items.length > 0) {
            item.items.forEach(function(it) {
                var cPerL = parseFloat(it.cost_per_liter || 0);
                var ltrs = parseFloat(it.liters || 0);
                var tCost = parseFloat(it.total_price || (cPerL * ltrs));
                html += '<tr style="border-bottom:1px solid #f1f5f9;">';
                html += '<td style="padding:10px 12px; font-weight:700; color:#002F6C;">' + (it.fuel_type || 'Fuel') + '</td>';
                html += '<td style="padding:10px 12px; text-align:center; font-weight:700;">' + ltrs.toLocaleString() + ' L</td>';
                html += '<td style="padding:10px 12px; text-align:right;">₱' + cPerL.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + '</td>';
                html += '<td style="padding:10px 12px; text-align:right; font-weight:700; color:#002F6C;">₱' + tCost.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + '</td>';
                html += '</tr>';
            });
        }
        html += '</tbody></table>';
    }

    container.innerHTML = html;

    openModal('viewPurchaseHistoryModal');
}

function closePurchaseHistoryModal() {
    closeModal('viewPurchaseHistoryModal');
}

function printModalPO() {
    if (!currentPoItemForModal) return;
    window.open('print_po_new.php?po_id=' + encodeURIComponent(currentPoItemForModal.po_number) + '&type=' + encodeURIComponent(currentPoItemForModal.category_type), '_blank');
}

function printModalInvoice() {
    if (!currentPoItemForModal) return;
    window.open('print_supplier_invoice.php?po_id=' + encodeURIComponent(currentPoItemForModal.po_number) + '&type=' + encodeURIComponent(currentPoItemForModal.category_type), '_blank');
}
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>

