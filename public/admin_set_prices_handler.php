<?php
// Force browser to always load fresh — prevents stale CSS/JS cache
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

if (!in_array($role, ['admin', 'superadmin'])) {
    echo json_encode(['success' => false, 'message' => 'Admin permission required']);
    exit;
}

$action = $_REQUEST['action'] ?? '';

try {
    switch ($action) {

        // ══════════════════════════════════════════════════════════════════════
        // GET MERCHANDISE DETAILS FOR EDIT MODAL
        // ══════════════════════════════════════════════════════════════════════
        case 'get_merch_details_admin':
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid Product ID']); exit; }

            $item = find_merchandise_pricing_item($pdo, (int)$station_id, $id);
            if (!$item) { echo json_encode(['success' => false, 'message' => 'Product not found']); exit; }

            echo json_encode(['success' => true, 'item' => $item]);
            exit;

        // ══════════════════════════════════════════════════════════════════════
        // EDIT PRODUCT DETAILS (ADMIN) - Price is NOT editable here
        // ══════════════════════════════════════════════════════════════════════
        // ══════════════════════════════════════════════════════════════════════
        // EDIT PRODUCT DETAILS (ADMIN) - Admin can update price directly
        // ══════════════════════════════════════════════════════════════════════
        case 'edit_product_admin':
            $id             = (int)($_POST['id'] ?? 0);
            $product_name   = trim($_POST['product_name'] ?? '');
            $sku            = trim($_POST['sku'] ?? '');
            $category       = trim($_POST['category'] ?? '');
            $brand          = trim($_POST['brand'] ?? '');
            $unit           = trim($_POST['unit'] ?? '');
            $unit_price     = isset($_POST['unit_price']) ? (float)$_POST['unit_price'] : -1;
            $reorder_level  = (int)($_POST['reorder_level'] ?? 24);
            $critical_level = (int)($_POST['critical_level'] ?? 10);
            $prod_status    = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

            if ($id <= 0 || empty($product_name) || empty($category)) {
                echo json_encode(['success' => false, 'message' => 'Product name and category are required']);
                exit;
            }

            $category_id = ensure_product_category_id($pdo, $category);
            $unit_value  = $unit !== '' ? $unit : 'pcs';

            // 1. Update inventory_products
            try {
                if ($unit_price >= 0) {
                    $stmt = $pdo->prepare("
                        UPDATE inventory_products
                        SET product_name=?, sku=?, category=?, brand=?, size=?, unit_price=?,
                            reorder_level=?, critical_level=?, status=?, updated_at=NOW()
                        WHERE id=?
                    ");
                    $stmt->execute([$product_name, $sku, $category, $brand, $unit_value, $unit_price, $reorder_level, $critical_level, $prod_status, $id]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE inventory_products
                        SET product_name=?, sku=?, category=?, brand=?, size=?,
                            reorder_level=?, critical_level=?, status=?, updated_at=NOW()
                        WHERE id=?
                    ");
                    $stmt->execute([$product_name, $sku, $category, $brand, $unit_value, $reorder_level, $critical_level, $prod_status, $id]);
                }
            } catch (Exception $e) {}

            // 2. Update products
            try {
                if ($unit_price >= 0) {
                    if ($category_id > 0) {
                        $stmt = $pdo->prepare("UPDATE products SET name=?, sku=?, category_id=?, brand=?, unit=?, price=?, status=?, updated_at=NOW() WHERE id=?");
                        $stmt->execute([$product_name, $sku, $category_id, $brand, $unit_value, $unit_price, $prod_status, $id]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE products SET name=?, sku=?, brand=?, unit=?, price=?, status=?, updated_at=NOW() WHERE id=?");
                        $stmt->execute([$product_name, $sku, $brand, $unit_value, $unit_price, $prod_status, $id]);
                    }
                } else {
                    if ($category_id > 0) {
                        $stmt = $pdo->prepare("UPDATE products SET name=?, sku=?, category_id=?, brand=?, unit=?, status=?, updated_at=NOW() WHERE id=?");
                        $stmt->execute([$product_name, $sku, $category_id, $brand, $unit_value, $prod_status, $id]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE products SET name=?, sku=?, brand=?, unit=?, status=?, updated_at=NOW() WHERE id=?");
                        $stmt->execute([$product_name, $sku, $brand, $unit_value, $prod_status, $id]);
                    }
                }
            } catch (Exception $e) {}

            // 3. Update station_inventory
            try {
                $stmt = $pdo->prepare("SELECT id FROM station_inventory WHERE station_id=? AND product_id=? LIMIT 1");
                $stmt->execute([$station_id, $id]);
                $si_id = (int)($stmt->fetchColumn() ?: 0);
                if ($si_id > 0) {
                    if ($unit_price >= 0) {
                        $pdo->prepare("UPDATE station_inventory SET unit=?, price=?, reorder_level=?, critical_level=?, status=?, last_updated=NOW() WHERE id=?")
                            ->execute([$unit_value, $unit_price, $reorder_level, $critical_level, $prod_status, $si_id]);
                    } else {
                        $pdo->prepare("UPDATE station_inventory SET unit=?, reorder_level=?, critical_level=?, status=?, last_updated=NOW() WHERE id=?")
                            ->execute([$unit_value, $reorder_level, $critical_level, $prod_status, $si_id]);
                    }
                } else {
                    $pdo->prepare("INSERT INTO station_inventory (station_id, product_id, stock_level, unit, price, reorder_level, critical_level, status, last_updated) VALUES (?, ?, 0, ?, ?, ?, ?, ?, NOW())")
                        ->execute([$station_id, $id, $unit_value, max(0, $unit_price), $reorder_level, $critical_level, $prod_status]);
                }
            } catch (Exception $e) {}

            // If Admin changed price, mark any pending price requests for this product as approved
            if ($unit_price >= 0) {
                $pdo->prepare("UPDATE pending_price_approvals SET status='approved', admin_id=?, reviewed_by=?, reviewed_at=NOW(), updated_at=NOW() WHERE station_id=? AND product_type IN ('merchandise','product','inventory_product') AND product_id=? AND status='pending'")
                    ->execute([$me['id'], $me['id'], $station_id, $id]);
            }

            log_activity($pdo, $me['id'], 'Admin Edit Product', "Admin updated master details for: {$product_name}" . ($unit_price >= 0 ? " (Price: ₱{$unit_price})" : ""));
            echo json_encode(['success' => true, 'message' => 'Product updated successfully!']);
            exit;

        // ══════════════════════════════════════════════════════════════════════
        // ADMIN EDIT FUEL PRODUCT DIRECTLY
        // ══════════════════════════════════════════════════════════════════════
        case 'admin_edit_fuel':
            $id             = (int)($_POST['id'] ?? 0);
            $new_price      = (float)($_POST['price'] ?? 0);
            $capacity       = (float)($_POST['capacity'] ?? 0);
            $critical_level = (float)($_POST['critical_level'] ?? 0);

            if ($id <= 0 || $new_price < 0 || $capacity < 0 || $critical_level < 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT fuel_type, price_per_liter FROM fuel_inventory WHERE id=? AND station_id=? LIMIT 1");
            $stmt->execute([$id, $station_id]);
            $fuel = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$fuel) { echo json_encode(['success' => false, 'message' => 'Fuel product not found']); exit; }

            $old_price = (float)$fuel['price_per_liter'];

            // Update fuel_inventory immediately
            $stmt = $pdo->prepare("UPDATE fuel_inventory SET price_per_liter=?, capacity=?, critical_level=?, updated_by=?, last_updated=NOW() WHERE id=? AND station_id=?");
            $stmt->execute([$new_price, $capacity, $critical_level, $me['id'], $id, $station_id]);

            // Mark any pending price request for this fuel as approved
            $pdo->prepare("UPDATE pending_price_approvals SET status='approved', admin_id=?, reviewed_by=?, reviewed_at=NOW(), updated_at=NOW() WHERE station_id=? AND product_type IN ('fuel','fuel_inventory') AND product_id=? AND status='pending'")
                ->execute([$me['id'], $me['id'], $station_id, $id]);

            if ($old_price != $new_price) {
                try {
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS fuel_price_history (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            fuel_id INT NOT NULL,
                            old_price DECIMAL(10,2) NOT NULL,
                            new_price DECIMAL(10,2) NOT NULL,
                            reason VARCHAR(500),
                            effective_date DATE,
                            updated_by INT,
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                    ");
                    $pdo->prepare("INSERT INTO fuel_price_history (fuel_id, old_price, new_price, reason, effective_date, updated_by, created_at) VALUES (?, ?, ?, 'Direct Admin Edit', CURDATE(), ?, NOW())")
                        ->execute([$id, $old_price, $new_price, $me['id']]);
                } catch (Exception $e) {}
            }

            log_activity($pdo, $me['id'], 'Admin Edit Fuel Product', "Admin updated fuel {$fuel['fuel_type']} price: ₱{$old_price} -> ₱{$new_price}, capacity: {$capacity}L");
            echo json_encode(['success' => true, 'message' => 'Fuel product updated successfully!']);
            exit;

        // ══════════════════════════════════════════════════════════════════════
        // ADMIN EDIT SERVICE TYPE DIRECTLY
        // ══════════════════════════════════════════════════════════════════════
        case 'admin_edit_service':
            $id            = (int)($_POST['id'] ?? 0);
            $service_name  = trim($_POST['service_name'] ?? '');
            $category      = trim($_POST['category'] ?? '');
            $service_key   = trim($_POST['service_key'] ?? '');
            $service_price = (float)($_POST['service_price'] ?? 0);
            $active        = (int)($_POST['active'] ?? 1);

            if ($id <= 0 || empty($service_name) || empty($category) || empty($service_key) || $service_price < 0) {
                echo json_encode(['success' => false, 'message' => 'Service name, category, key and price are required']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT id, service_price FROM job_order_service_types WHERE id=? LIMIT 1");
            $stmt->execute([$id]);
            $svc = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$svc) { echo json_encode(['success' => false, 'message' => 'Service type not found']); exit; }

            $old_price = (float)$svc['service_price'];

            // Update job_order_service_types immediately
            $stmt = $pdo->prepare("UPDATE job_order_service_types SET service_name=?, category=?, service_key=?, service_price=?, active=?, updated_at=NOW() WHERE id=?");
            $stmt->execute([$service_name, $category, $service_key, $service_price, $active, $id]);

            // Mark any pending price request for this service as approved
            $pdo->prepare("UPDATE pending_price_approvals SET status='approved', admin_id=?, reviewed_by=?, reviewed_at=NOW(), updated_at=NOW() WHERE product_type='service_type' AND product_id=? AND status='pending'")
                ->execute([$me['id'], $me['id'], $id]);

            log_activity($pdo, $me['id'], 'Admin Edit Service Type', "Admin updated service {$service_name} price: ₱{$old_price} -> ₱{$service_price}");
            echo json_encode(['success' => true, 'message' => 'Service type updated successfully!']);
            exit;

        // ══════════════════════════════════════════════════════════════════════
        // GET PRICE REQUEST DETAILS (VIEW REQUEST MODAL)
        // ══════════════════════════════════════════════════════════════════════
        case 'get_request_details':
            $approval_id = (int)($_GET['approval_id'] ?? 0);
            if ($approval_id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid Approval ID']); exit; }

            $stmt = $pdo->prepare("
                SELECT ppa.*,
                       COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.name, u.username, 'Manager') AS requested_by_name
                FROM pending_price_approvals ppa
                LEFT JOIN users u ON u.id = ppa.manager_id OR u.id = ppa.requested_by
                WHERE ppa.id = ?
                LIMIT 1
            ");
            $stmt->execute([$approval_id]);
            $req = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$req) { echo json_encode(['success' => false, 'message' => 'Price request not found']); exit; }

            // Get product name if missing
            if (empty($req['product_name'])) {
                $pid = (int)$req['product_id'];
                $item = find_merchandise_pricing_item($pdo, (int)$station_id, $pid);
                $req['product_name'] = $item['product_name'] ?? "Product #$pid";
            }

            echo json_encode(['success' => true, 'request' => $req]);
            exit;

        // ══════════════════════════════════════════════════════════════════════
        // APPROVE PRICE CHANGE REQUEST
        // ══════════════════════════════════════════════════════════════════════
        case 'approve_price_request':
            $approval_id = (int)($_POST['approval_id'] ?? 0);
            if ($approval_id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid Approval ID']); exit; }

            $stmt = $pdo->prepare("SELECT * FROM pending_price_approvals WHERE id = ? AND status = 'pending'");
            $stmt->execute([$approval_id]);
            $pending = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$pending) {
                echo json_encode(['success' => false, 'message' => 'Price request not found or already processed']);
                exit;
            }

            $pid           = (int)($pending['product_id'] ?? 0);
            $new_price_val = (float)($pending['new_price'] ?? $pending['new_value'] ?? 0);
            $old_price_val = (float)($pending['old_price'] ?? $pending['old_value'] ?? 0);
            $new_cost_val  = (float)($pending['new_cost'] ?? 0);
            $target_sid    = (int)($pending['station_id'] ?? $station_id);
            if ($target_sid <= 0) $target_sid = (int)$station_id;

            // 1. Update Default Selling Price in inventory_products
            try {
                $pdo->prepare("UPDATE inventory_products SET unit_cost=?, unit_price=?, updated_at=NOW() WHERE id=?")
                    ->execute([$new_cost_val, $new_price_val, $pid]);
            } catch (Exception $e) {}

            // 2. Update Default Selling Price in products
            try {
                $pdo->prepare("UPDATE products SET cost=?, price=?, updated_at=NOW() WHERE id=?")
                    ->execute([$new_cost_val, $new_price_val, $pid]);
            } catch (Exception $e) {}

            // 3. Update station_inventory
            try {
                $si_stmt = $pdo->prepare("SELECT id FROM station_inventory WHERE station_id=? AND product_id=? LIMIT 1");
                $si_stmt->execute([$target_sid, $pid]);
                $si_id = (int)($si_stmt->fetchColumn() ?: 0);
                if ($si_id > 0) {
                    $pdo->prepare("UPDATE station_inventory SET cost=?, price=?, last_updated=NOW() WHERE id=?")
                        ->execute([$new_cost_val, $new_price_val, $si_id]);
                } else {
                    $pdo->prepare("INSERT INTO station_inventory (station_id, product_id, stock_level, cost, price, status, last_updated) VALUES (?, ?, 0, ?, ?, 'active', NOW())")
                        ->execute([$target_sid, $pid, $new_cost_val, $new_price_val]);
                }
            } catch (Exception $e) {}

            // 4. Update pending_price_approvals
            $pdo->prepare("UPDATE pending_price_approvals SET status='approved', admin_id=?, reviewed_by=?, reviewed_at=NOW(), updated_at=NOW() WHERE id=?")
                ->execute([$me['id'], $me['id'], $approval_id]);

            // 5. Save Price History
            try {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS fuel_price_history (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        fuel_id INT NOT NULL,
                        old_price DECIMAL(10,2) NOT NULL,
                        new_price DECIMAL(10,2) NOT NULL,
                        reason VARCHAR(500),
                        effective_date DATE,
                        updated_by INT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
                $pdo->prepare("
                    INSERT INTO fuel_price_history (fuel_id, old_price, new_price, reason, effective_date, updated_by, created_at)
                    VALUES (?, ?, ?, ?, CURDATE(), ?, NOW())
                ")->execute([$pid, $old_price_val, $new_price_val, "Approved Price Change Request #$approval_id", $me['id']]);
            } catch (Exception $e) {}

            // 6. Save Audit Trail
            log_activity($pdo, $me['id'], 'Approve Price Change', "Admin approved price change for product #{$pid} from ₱{$old_price_val} to ₱{$new_price_val}");

            // 7. Notify Manager
            $manager_user_id = (int)($pending['manager_id'] ?? $pending['requested_by'] ?? 0);
            if ($manager_user_id > 0) {
                $prod_item = find_merchandise_pricing_item($pdo, $target_sid, $pid);
                $pname = $prod_item['product_name'] ?? "Product #$pid";
                try {
                    $pdo->prepare("
                        INSERT INTO notifications (user_id, type, title, message, status, created_at)
                        VALUES (?, 'price_approved', 'Price Change Approved', ?, 'unread', NOW())
                    ")->execute([$manager_user_id, "Your price change request for '{$pname}' (₱{$old_price_val} → ₱{$new_price_val}) has been APPROVED."]);
                } catch (Exception $e) {}
                try {
                    $pdo->prepare("
                        INSERT INTO user_notifications (user_id, type, title, message, is_read, created_at)
                        VALUES (?, 'price_approved', 'Price Change Approved', ?, 0, NOW())
                    ")->execute([$manager_user_id, "Your price change request for '{$pname}' (₱{$old_price_val} → ₱{$new_price_val}) has been APPROVED."]);
                } catch (Exception $e) {}
            }

            echo json_encode(['success' => true, 'message' => 'Price change request approved successfully!']);
            exit;

        // ══════════════════════════════════════════════════════════════════════
        // REJECT PRICE CHANGE REQUEST
        // ══════════════════════════════════════════════════════════════════════
        case 'reject_price_request':
            $approval_id      = (int)($_POST['approval_id'] ?? 0);
            $rejection_reason = trim($_POST['rejection_reason'] ?? $_POST['remarks'] ?? '');

            if ($approval_id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid Approval ID']); exit; }

            $stmt = $pdo->prepare("SELECT * FROM pending_price_approvals WHERE id = ? AND status = 'pending'");
            $stmt->execute([$approval_id]);
            $pending = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$pending) {
                echo json_encode(['success' => false, 'message' => 'Price request not found or already processed']);
                exit;
            }

            // 1. Update status in pending_price_approvals
            $pdo->prepare("
                UPDATE pending_price_approvals
                SET status='rejected', rejection_reason=?, reviewer_notes=?, admin_id=?, reviewed_by=?, reviewed_at=NOW(), updated_at=NOW()
                WHERE id=?
            ")->execute([$rejection_reason, $rejection_reason, $me['id'], $me['id'], $approval_id]);

            // 2. Save Audit Trail
            $pid = (int)($pending['product_id'] ?? 0);
            log_activity($pdo, $me['id'], 'Reject Price Change', "Admin rejected price change request #{$approval_id}. Reason: {$rejection_reason}");

            // 3. Notify Manager
            $manager_user_id = (int)($pending['manager_id'] ?? $pending['requested_by'] ?? 0);
            if ($manager_user_id > 0) {
                $prod_item = find_merchandise_pricing_item($pdo, (int)$station_id, $pid);
                $pname = $prod_item['product_name'] ?? "Product #$pid";
                try {
                    $pdo->prepare("
                        INSERT INTO notifications (user_id, type, title, message, status, created_at)
                        VALUES (?, 'price_rejected', 'Price Change Rejected', ?, 'unread', NOW())
                    ")->execute([$manager_user_id, "Your price change request for '{$pname}' was REJECTED. Reason: {$rejection_reason}"]);
                } catch (Exception $e) {}
                try {
                    $pdo->prepare("
                        INSERT INTO user_notifications (user_id, type, title, message, is_read, created_at)
                        VALUES (?, 'price_rejected', 'Price Change Rejected', ?, 0, NOW())
                    ")->execute([$manager_user_id, "Your price change request for '{$pname}' was REJECTED. Reason: {$rejection_reason}"]);
                } catch (Exception $e) {}
            }

            echo json_encode(['success' => true, 'message' => 'Price change request rejected.']);
            exit;

        // ══════════════════════════════════════════════════════════════════════
        // GET PRICE HISTORY
        // ══════════════════════════════════════════════════════════════════════
        case 'get_price_history':
            $product_id = (int)($_GET['product_id'] ?? 0);
            if ($product_id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid Product ID']); exit; }

            // Fetch from pending_price_approvals
            $history = [];
            try {
                $stmt = $pdo->prepare("
                    SELECT ppa.id, ppa.old_price, ppa.new_price, ppa.created_at AS date_requested, ppa.reviewed_at AS date_approved,
                           ppa.status, ppa.rejection_reason,
                           COALESCE(CONCAT(u1.first_name, ' ', u1.last_name), u1.name, u1.username, 'Manager') AS requested_by,
                           COALESCE(CONCAT(u2.first_name, ' ', u2.last_name), u2.name, u2.username, 'Admin') AS approved_by
                    FROM pending_price_approvals ppa
                    LEFT JOIN users u1 ON u1.id = ppa.manager_id OR u1.id = ppa.requested_by
                    LEFT JOIN users u2 ON u2.id = ppa.admin_id OR u2.id = ppa.reviewed_by
                    WHERE ppa.product_id = ?
                    ORDER BY ppa.created_at DESC
                ");
                $stmt->execute([$product_id]);
                $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}

            // Also fetch from fuel_price_history if present
            try {
                $stmt2 = $pdo->prepare("
                    SELECT fph.id, fph.old_price, fph.new_price, fph.created_at AS date_requested, fph.created_at AS date_approved,
                           'approved' AS status, '' AS rejection_reason,
                           COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.name, u.username, 'Manager') AS requested_by,
                           'Admin' AS approved_by
                    FROM fuel_price_history fph
                    LEFT JOIN users u ON u.id = fph.updated_by
                    WHERE fph.fuel_id = ?
                    ORDER BY fph.created_at DESC
                ");
                $stmt2->execute([$product_id]);
                $history2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                $history = array_merge($history, $history2);
            } catch (Exception $e) {}

            echo json_encode(['success' => true, 'history' => $history]);
            exit;

        // ══════════════════════════════════════════════════════════════════════
        // GET PRODUCT BATCHES (WITH FALLBACK)
        // ══════════════════════════════════════════════════════════════════════
        case 'get_product_batches':
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid Product ID']); exit; }

            // 1. Try merchandise_batches
            $stmt = $pdo->prepare("
                SELECT mb.id,
                       COALESCE(NULLIF(mb.batch_number, ''), CONCAT('BT-', DATE_FORMAT(mb.created_at, '%Y%m%d'), '-', LPAD(mb.id, 4, '0'))) AS batch_number,
                       mb.quantity_received,
                       mb.remaining_qty,
                       mb.unit_cost,
                       mb.selling_price,
                       mb.date_received,
                       mb.status,
                       mb.supplier,
                       mb.notes,
                       COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.name, u.username, 'System') AS encoded_by
                FROM merchandise_batches mb
                LEFT JOIN users u ON u.id = mb.encoded_by
                WHERE mb.product_id = ?
                ORDER BY mb.date_received ASC, mb.id ASC
            ");
            $stmt->execute([$id]);
            $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 2. Fallback to merchandise_stock_in
            if (empty($batches)) {
                $stmt2 = $pdo->prepare("
                    SELECT msi.id,
                           COALESCE(NULLIF(msi.batch_ref, ''), CONCAT('BT-', DATE_FORMAT(msi.encoded_at, '%Y%m%d'), '-', LPAD(msi.id, 4, '0'))) AS batch_number,
                           msi.qty_received AS quantity_received,
                           msi.qty_received AS remaining_qty,
                           msi.unit_cost,
                           msi.selling_price,
                           DATE(msi.encoded_at) AS date_received,
                           'active' AS status,
                           'Petron Corporation' AS supplier,
                           msi.remarks AS notes,
                           COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.name, u.username, 'System') AS encoded_by
                    FROM merchandise_stock_in msi
                    LEFT JOIN users u ON u.id = msi.encoded_by
                    WHERE msi.product_id = ?
                    ORDER BY msi.encoded_at ASC, msi.id ASC
                ");
                $stmt2->execute([$id]);
                $batches = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            }

            echo json_encode(['success' => true, 'batches' => $batches]);
            exit;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            exit;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
