<?php
$page_id = 'mgr_inv_merch';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

// Module gate
if (!in_array($role, ['superadmin', 'developer']) && !is_module_enabled('inventory')) {
    render_module_disabled_page('Inventory');
}
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    header('Location: dashboard.php');
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX Endpoints
// ─────────────────────────────────────────────────────────────────────────────
if (isset($_GET['ajax']) && ($_GET['action'] ?? '') === 'get_product_details') {
    $prod_id = (int)($_GET['product_id'] ?? 0);
    header('Content-Type: application/json');
    try {
        // Product Info
        $stmt = $pdo->prepare("
            SELECT
                ip.id,
                ip.product_name                              AS name,
                ip.category                                  AS category_name,
                ip.unit_price                                AS price,
                ip.unit_cost                                 AS cost,
                ip.sku,
                ip.supplier,
                ip.status                                    AS product_status,
                COALESCE(ip.min_stock, 0)                    AS min_stock,
                COALESCE(ip.max_stock, 0)                    AS max_stock,
                COALESCE(si.stock_level, ip.stock, 0)        AS stock_level,
                COALESCE(si.capacity, ip.max_stock, 480)       AS capacity,
                COALESCE(si.reorder_level, ip.min_stock, 24) AS reorder_level,
                COALESCE(si.critical_level, 10)              AS critical_level,
                COALESCE(si.unit, ip.size, 'pcs')            AS unit,
                si.physical_count,
                si.variance,
                si.last_updated
            FROM inventory_products ip
            LEFT JOIN station_inventory si
                   ON si.product_id = ip.id AND si.station_id = ?
            WHERE ip.id = ?
        ");
        $stmt->execute([$station_id, $prod_id]);
        $prod = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$prod) {
            echo json_encode(['success' => false, 'message' => 'Product not found']);
            exit;
        }

        // Apply capacity fallbacks
        $capacity = (float)($prod['capacity'] ?? 0);
        if ($capacity <= 0) {
            $capacity = 480;
        }
        $prod['capacity'] = $capacity;

        // Movement history for this product
        $stmt = $pdo->prepare("
            SELECT il.created_at, il.action AS movement_type, il.quantity_change AS quantity, il.notes, u.name AS user_name
            FROM inventory_logs il
            LEFT JOIN users u ON il.user_id = u.id
            WHERE il.product_id = ? AND il.station_id = ?
            ORDER BY il.created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$prod_id, $station_id]);
        $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Recent deliveries (merchandise_stock_in)
        $stmt = $pdo->prepare("
            SELECT msi.*, u.name AS user_name
            FROM merchandise_stock_in msi
            JOIN users u ON msi.encoded_by = u.id
            WHERE msi.product_id = ? AND msi.station_id = ?
            ORDER BY msi.encoded_at DESC
            LIMIT 50
        ");
        $stmt->execute([$prod_id, $station_id]);
        $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Pending stock requests
        $stmt = $pdo->prepare("
            SELECT sr.*, u.name AS staff_name
            FROM stock_requests sr
            JOIN users u ON sr.staff_id = u.id
            WHERE sr.item_id = ? AND sr.station_id = ? AND sr.status = 'Pending'
            ORDER BY sr.created_at DESC
        ");
        $stmt->execute([$prod_id, $station_id]);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'product' => $prod,
            'movements' => $movements,
            'deliveries' => $deliveries,
            'requests' => $requests
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// POST Actions
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // 1. Validate Product (existing action)
    if ($action === 'validate_product') {
        $id = (int)($_POST['product_id'] ?? 0);
        if ($id) {
            try {
                $stmt = $pdo->prepare("SELECT product_name FROM inventory_products WHERE id=?");
                $stmt->execute([$id]);
                $pname = $stmt->fetchColumn();
                $pdo->prepare("UPDATE inventory_products SET status = 'active' WHERE id=?")->execute([$id]);
                log_activity($pdo, $me['id'], 'Product Validated', "Merchandise product '$pname' (ID:$id) validated by {$me['name']}");
                $_SESSION['success'] = "Product '$pname' validated and is now active.";
            } catch (Exception $e) {
                $_SESSION['error'] = 'Error: ' . $e->getMessage();
            }
        }
        header('Location: manager_inventory_merchandise.php?tab=inventory'); exit;
    }

    // 2. Approve Stock Request (Forward to Admin as PR)
    if ($action === 'approve_request') {
        $req_id = (int)($_POST['request_id'] ?? 0);
        $approved_qty = (int)($_POST['approved_quantity'] ?? 0);
        $notes = trim($_POST['manager_notes'] ?? '');
        
        if ($req_id > 0 && $approved_qty > 0) {
            try {
                $pdo->beginTransaction();
                
                // Fetch request
                $stmt = $pdo->prepare("SELECT * FROM stock_requests WHERE id = ? AND station_id = ? AND status = 'Pending'");
                $stmt->execute([$req_id, $station_id]);
                $req = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$req) throw new Exception("Stock request not found or already processed.");
                
                // Generate sequential PR number
                $stmt_max = $pdo->query("SELECT MAX(CAST(REGEXP_SUBSTR(request_no, '[0-9]+$') AS UNSIGNED)) FROM stock_requests WHERE station_id = $station_id AND request_no IS NOT NULL AND request_no != ''");
                $max_num = (int)($stmt_max->fetchColumn() ?: 0);
                $pr_number = 'PR-' . date('Y') . '-' . str_pad($max_num + 1, 4, '0', STR_PAD_LEFT);
                
                // Update Stock Request
                $pdo->prepare("
                    UPDATE stock_requests 
                    SET status = 'Waiting for Purchase Order', approved_quantity = ?, manager_id = ?, manager_notes = ?, request_no = ?, processed_at = NOW(), updated_at = NOW()
                    WHERE id = ?
                ")->execute([
                    $approved_qty, $me['id'], $notes, $pr_number, $req_id
                ]);
                
                // Audit log & Activity log
                $audit_note = "Forwarded to Admin. PR: $pr_number";
                if ($notes) $audit_note .= " Notes: {$notes}";
                
                $pdo->prepare("
                    INSERT INTO stock_request_audit
                        (stock_request_id, action_type, performed_by, performed_by_role, old_status, new_status, notes)
                    VALUES (?, 'Forwarded to Admin', ?, ?, 'Pending', 'Waiting for Purchase Order', ?)
                ")->execute([$req_id, $me['id'], $role, $audit_note]);
                
                // Send notification to Admin
                $notify_stmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, type, title, message, event_type, severity, redirect_url, created_at)
                    VALUES (?, 'info', 'PR Waiting for PO', ?, 'stock_request', 'high', 'admin_purchase_orders.php?tab=pending', NOW())
                ");
                $admins = $pdo->query("SELECT id FROM users WHERE role IN ('admin', 'superadmin')")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($admins as $admin_id) {
                    $notify_stmt->execute([$admin_id, "Purchase Request {$pr_number} has been approved by Manager and is waiting for PO generation."]);
                }
                
                log_activity($pdo, $me['id'], 'Approve Stock Request', "Request #{$req_id} | {$req['item_name']} | Qty: {$approved_qty} forwarded to Admin under PR {$pr_number}");
                
                $pdo->commit();
                $_SESSION['success'] = "Stock request #{$req_id} approved and forwarded to Admin (PR: {$pr_number}).";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = 'Error: ' . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = 'Invalid quantity or request ID.';
        }
        header('Location: manager_inventory_merchandise.php?tab=requests'); exit;
    }

    // 3. Reject Stock Request
    if ($action === 'reject_request') {
        $req_id = (int)($_POST['request_id'] ?? 0);
        $notes = trim($_POST['manager_notes'] ?? '');
        
        if ($req_id > 0 && !empty($notes)) {
            try {
                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare("SELECT * FROM stock_requests WHERE id = ? AND station_id = ? AND status = 'Pending'");
                $stmt->execute([$req_id, $station_id]);
                $req = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$req) throw new Exception("Stock request not found or already processed.");
                
                $pdo->prepare("
                    UPDATE stock_requests 
                    SET status = 'Rejected', manager_id = ?, manager_notes = ?, processed_at = NOW(), updated_at = NOW()
                    WHERE id = ?
                ")->execute([
                    $me['id'], $notes, $req_id
                ]);
                
                $pdo->prepare("
                    INSERT INTO stock_request_audit
                        (stock_request_id, action_type, performed_by, performed_by_role, old_status, new_status, notes)
                    VALUES (?, 'Rejected', ?, ?, 'Pending', 'Rejected', ?)
                ")->execute([$req_id, $me['id'], $role, "Rejected by {$me['name']}. Reason: {$notes}"]);
                
                log_activity($pdo, $me['id'], 'Reject Stock Request', "Request #{$req_id} | {$req['item_name']} rejected by {$me['name']}");
                
                $pdo->commit();
                $_SESSION['success'] = "Stock request #{$req_id} rejected.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = 'Error: ' . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = 'Rejection reason is required.';
        }
        header('Location: manager_inventory_merchandise.php?tab=requests'); exit;
    }

    // 4. Validate Delivery
    if ($action === 'validate_delivery') {
        $po_id = (int)($_POST['po_id'] ?? 0);
        $actual_qty = (int)($_POST['actual_qty'] ?? 0);
        $flag = $_POST['delivery_flag'] ?? 'OK';
        $notes = trim($_POST['delivery_notes'] ?? '');
        
        if (!in_array($flag, ["OK","Short","Damaged","Excess","Mixed"])) $flag = "OK";
        
        if ($po_id > 0) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id=? AND station_id=? AND admin_finalized=1 AND delivery_validated=0");
                $stmt->execute([$po_id, $station_id]);
                $po = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$po) throw new Exception("PO not found or already validated.");
                
                $pdo->prepare("
                    UPDATE purchase_orders 
                    SET delivery_validated=1, delivery_validated_at=NOW(), delivery_validated_by=?, delivery_flag=?, delivery_notes=?, actual_qty_received=?, updated_at=NOW() 
                    WHERE id=?
                ")->execute([$me['id'], $flag, $notes, $actual_qty, $po_id]);
                
                log_activity($pdo, $me['id'], "Validate Delivery", "PO #{$po['po_number']} | {$po['product_name']} | Flag:{$flag} | Actual:{$actual_qty}");
                
                $_SESSION['success'] = "Delivery for PO #{$po['po_number']} validated. Staff can now encode Stock-In.";
            } catch (Exception $e) {
                $_SESSION['error'] = 'Error: ' . $e->getMessage();
            }
        }
        header('Location: manager_inventory_merchandise.php?tab=deliveries'); exit;
    }

    // 5. Flag Issue
    if ($action === 'flag_delivery_issue') {
        $po_id = (int)($_POST['po_id'] ?? 0);
        $flag = $_POST['delivery_flag'] ?? 'Short';
        $notes = trim($_POST['delivery_notes'] ?? '');
        
        if (empty($notes)) {
            $_SESSION['error'] = "Notes required when flagging.";
            header('Location: manager_inventory_merchandise.php?tab=deliveries'); exit;
        }
        
        if ($po_id > 0) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id=? AND station_id=?");
                $stmt->execute([$po_id, $station_id]);
                $po = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$po) throw new Exception("PO not found.");
                
                $pdo->prepare("UPDATE purchase_orders SET delivery_flag=?, delivery_notes=?, updated_at=NOW() WHERE id=?")->execute([$flag, $notes, $po_id]);
                
                $_SESSION['success'] = "Issue flagged for PO #{$po['po_number']}.";
            } catch (Exception $e) {
                $_SESSION['error'] = 'Error: ' . $e->getMessage();
            }
        }
        header('Location: manager_inventory_merchandise.php?tab=deliveries'); exit;
    }

    // 6. Request Inventory Adjustment
    if ($action === 'request_adjustment') {
        $prod_id = (int)($_POST['product_id'] ?? 0);
        $physical_count = (float)($_POST['physical_count'] ?? 0);
        $notes = trim($_POST['manager_notes'] ?? $_POST['notes'] ?? '');

        if ($prod_id > 0) {
            try {
                $pdo->beginTransaction();

                // Fetch current stock
                $stmt = $pdo->prepare("
                    SELECT COALESCE(si.stock_level, ip.stock, 0) AS stock_level, ip.product_name, si.id AS si_exists
                    FROM inventory_products ip
                    LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
                    WHERE ip.id = ?
                ");
                $stmt->execute([$station_id, $prod_id]);
                $curr = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$curr) throw new Exception("Product not found.");

                $current_stock = (float)$curr['stock_level'];
                $variance = $physical_count - $current_stock;

                // Make sure station_inventory row exists
                if (!$curr['si_exists']) {
                    $pdo->prepare("
                        INSERT INTO station_inventory (product_id, station_id, stock_level, status, last_updated)
                        VALUES (?, ?, ?, 'active', NOW())
                    ")->execute([$prod_id, $station_id, $current_stock]);
                }

                // Update physical_count, variance, stock_level, and last_updated
                $stmt = $pdo->prepare("
                    UPDATE station_inventory
                    SET physical_count = ?,
                        variance = ?,
                        stock_level = ?,
                        last_updated = NOW()
                    WHERE product_id = ? AND station_id = ?
                ");
                $stmt->execute([$physical_count, $variance, $physical_count, $prod_id, $station_id]);

                // Insert into inventory_logs
                $stmt = $pdo->prepare("
                    INSERT INTO inventory_logs (station_id, product_id, action, quantity_change, notes, user_id, created_at)
                    VALUES (?, ?, 'adjustment', ?, ?, ?, NOW())
                ");
                $log_notes = "Physical count adjustment: counted {$physical_count} (was {$current_stock}, variance: " . ($variance > 0 ? '+' : '') . "{$variance}). Notes: {$notes}";
                $stmt->execute([$station_id, $prod_id, $variance, $log_notes, $me['id']]);

                // Also update the fallback stock in inventory_products
                $stmt = $pdo->prepare("UPDATE inventory_products SET stock = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$physical_count, $prod_id]);

                log_activity($pdo, $me['id'], 'Inventory Adjustment', "Product: {$curr['product_name']} | Count: {$physical_count} | Variance: {$variance} | User: {$me['name']}");

                $pdo->commit();
                $_SESSION['success'] = "Inventory adjusted successfully for '{$curr['product_name']}'. Physical count: {$physical_count}, Variance: " . ($variance > 0 ? '+' : '') . "{$variance} logged.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = 'Error: ' . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = 'Invalid product selected.';
        }
        header('Location: manager_inventory_merchandise.php?tab=inventory'); exit;
    }

    // 7. Create Stock Request (replenishment request submitted by manager)
    if ($action === 'create_stock_request') {
        $prod_id = (int)($_POST['product_id'] ?? 0);
        $qty = (int)($_POST['requested_quantity'] ?? 0);
        $remarks = trim($_POST['remarks'] ?? '');
        // Use session-based batch PR number so multiple items submitted in one session share the same PR
        if (empty($_SESSION['current_batch_pr']) || (time() - ($_SESSION['batch_pr_time'] ?? 0)) > 3600) {
            // Generate a new sequential PR number based on the highest existing one
            $stmt_max = $pdo->query("SELECT MAX(CAST(REGEXP_SUBSTR(request_no, '[0-9]+\$') AS UNSIGNED)) FROM stock_requests WHERE station_id = $station_id AND request_no IS NOT NULL AND request_no != ''");
            $max_num = (int)($stmt_max->fetchColumn() ?: 0);
            $_SESSION['current_batch_pr'] = 'PR-' . date('Y') . '-' . str_pad($max_num + 1, 4, '0', STR_PAD_LEFT);
            $_SESSION['batch_pr_time'] = time();
        }
        $batch_pr = $_SESSION['current_batch_pr'];

        if ($prod_id > 0 && $qty > 0) {
            try {
                // Get product information
                $stmt = $pdo->prepare("
                    SELECT ip.product_name, ip.category, ip.sku, COALESCE(si.stock_level, ip.stock, 0) AS stock_level
                    FROM inventory_products ip
                    LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
                    WHERE ip.id = ?
                ");
                $stmt->execute([$station_id, $prod_id]);
                $prod = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$prod) throw new Exception("Product not found.");

                // Insert into stock_requests WITH the shared batch request_no
                $stmt = $pdo->prepare("
                    INSERT INTO stock_requests (
                        request_no, staff_id, station_id, item_id, item_sku, item_name, 
                        item_category, current_stock, requested_quantity, 
                        remarks, status, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW(), NOW())
                ");
                $stmt->execute([
                    $batch_pr,
                    $me['id'],
                    $station_id,
                    $prod_id,
                    $prod['sku'] ?: 'N/A',
                    $prod['product_name'],
                    $prod['category'] ?: 'Uncategorized',
                    (int)$prod['stock_level'],
                    $qty,
                    $remarks
                ]);

                $request_id = $pdo->lastInsertId();
                log_activity($pdo, $me['id'], 'Create Stock Request', "Stock Request #$request_id for {$prod['product_name']} | Qty: $qty | PR: $batch_pr");

                $_SESSION['success'] = "Stock request for '{$prod['product_name']}' added to <strong>{$batch_pr}</strong> (ID: #$request_id).";
            } catch (Exception $e) {
                $_SESSION['error'] = 'Error: ' . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = 'Invalid product or quantity.';
        }
        header('Location: manager_inventory_merchandise.php?tab=alerts'); exit;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Data Fetching
// ─────────────────────────────────────────────────────────────────────────────
$merch_inventory = [];
$msg = '';

// Backfill station_inventory
try {
    $pdo->prepare("
        INSERT INTO station_inventory (product_id, station_id, stock_level, status, last_updated)
        SELECT ip.id, ?, COALESCE(ip.stock, 0), 'active', NOW()
        FROM inventory_products ip
        LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
        WHERE si.id IS NULL
          AND LOWER(COALESCE(ip.category,'')) NOT IN ('fuel')
    ")->execute([$station_id, $station_id]);
} catch (Exception $e) {}

// Main catalog query
try {
    $stmt = $pdo->prepare("
        SELECT
            ip.id,
            ip.product_name                              AS name,
            ip.category                                  AS category_name,
            ip.unit_price                                AS price,
            ip.unit_cost                                 AS cost,
            ip.sku,
            ip.supplier,
            ip.status                                    AS product_status,
            COALESCE(ip.min_stock, 0)                    AS min_stock,
            COALESCE(ip.max_stock, 0)                    AS max_stock,
            COALESCE(si.stock_level, ip.stock, 0)        AS stock_level,
            COALESCE(si.capacity, ip.max_stock, 480)     AS capacity,
            COALESCE(si.reorder_level, ip.min_stock, 24) AS reorder_level,
            COALESCE(si.critical_level, 10)              AS critical_level,
            COALESCE(si.unit, ip.size, 'pcs')            AS unit,
            si.last_updated                              AS last_updated,
            si.physical_count,
            si.variance
        FROM inventory_products ip
        LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
        WHERE LOWER(COALESCE(ip.category,'')) NOT IN ('fuel')
        ORDER BY ip.category, ip.product_name
    ");
    $stmt->execute([$station_id]);
    $merch_inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $msg = 'Error loading merchandise: ' . $e->getMessage();
}

// Last movement per product
$last_movements = [];
try {
    $mvStmt = $pdo->prepare("
        SELECT product_id, qty_received AS qty, 'Delivery' AS mtype, encoded_at AS mdate
        FROM merchandise_stock_in WHERE station_id = ? AND product_id IS NOT NULL
    ");
    $mvStmt->execute([$station_id]);
    foreach ($mvStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pid = (int)$r['product_id'];
        if (!isset($last_movements[$pid]) || $r['mdate'] > $last_movements[$pid]['date'])
            $last_movements[$pid] = ['qty'=>(int)$r['qty'],'type'=>$r['mtype'],'sign'=>'+','date'=>$r['mdate']];
    }
} catch (Exception $e) {}
try {
    $slStmt = $pdo->prepare("
        SELECT ti.product_id, SUM(ti.quantity) AS qty, MAX(t.created_at) AS mdate
        FROM merchandise_transaction_items ti JOIN merchandise_transactions t ON t.id=ti.transaction_id
        WHERE t.station_id=? AND ti.product_id IS NOT NULL GROUP BY ti.product_id
    ");
    $slStmt->execute([$station_id]);
    foreach ($slStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pid = (int)$r['product_id'];
        if (!isset($last_movements[$pid]) || $r['mdate'] > $last_movements[$pid]['date'])
            $last_movements[$pid] = ['qty'=>(int)$r['qty'],'type'=>'Sales','sign'=>'-','date'=>$r['mdate']];
    }
} catch (Exception $e) {}

// Category list for filters
$categories_list = [];
foreach ($merch_inventory as $item) {
    $cat = $item['category_name'] ?? '';
    if ($cat !== '') $categories_list[$cat] = true;
}
ksort($categories_list);

// Build sorted-by-category structure (used by both Inventory Overview and Stock Alerts tabs)
$by_cat = [];
foreach ($merch_inventory as $item) {
    $cat = $item['category_name'] ?? 'Uncategorized';
    $by_cat[$cat][] = $item;
}
$cat_order = ['Oils / Lubes / Grease','Car Accessories','Brake System','Tire','Maintenance','Oil / Fuel Filters','Others (Snacks / Drinks)'];
$sorted = [];
foreach ($cat_order as $k) { if (isset($by_cat[$k])) $sorted[$k] = $by_cat[$k]; }
foreach ($by_cat as $k => $v) { if (!in_array($k, $cat_order)) $sorted[$k] = $v; }

// Summary Stats
$summary_total = count($merch_inventory);
$summary_available = 0;
$summary_low = 0;       // all below reorder (includes critical)
$summary_out = 0;
$summary_variance = 0;
// Granular alert counts for Stock Alerts tab cards
$summary_alert_low      = 0; // Low Stock only (stock > reorder/2 && <= reorder)
$summary_alert_critical = 0; // Critical Stock (stock > 0 && <= reorder/2)
foreach ($merch_inventory as $item) {
    $stock = (float)($item['stock_level'] ?? 0);
    $reorder = (float)($item['reorder_level'] ?? 24);
    $critical = (float)($item['critical_level'] ?? 10);
    $variance = $item['variance'];
    
    if ($variance !== null && (float)$variance != 0) {
        $summary_variance++;
    }
    
    if ($stock <= 0) {
        $summary_out++;
    } elseif ($stock <= $critical) {
        $summary_alert_critical++;
        $summary_low++;
    } elseif ($stock <= $reorder) {
        $summary_alert_low++;
        $summary_low++;
    } else {
        $summary_available++;
    }
}
$stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_requests WHERE station_id = ? AND status = 'Pending'");
$stmt->execute([$station_id]);
$summary_pending_requests = (int)$stmt->fetchColumn();

// Stock requests data
$stock_requests = [];
$summary_req_total = 0;
$summary_req_pending = 0;
$summary_req_approved = 0;
$summary_req_rejected = 0;
$req_categories = [];
$req_staff_users = [];
try {
    $stmt = $pdo->prepare("
        SELECT sr.*, u.name AS staff_name, 
               COALESCE(si.reorder_level, ip.min_stock, 24) AS reorder_level,
               COALESCE(si.critical_level, 10)              AS critical_level,
               COALESCE(si.unit, ip.size, 'pcs') AS unit,
               ip.sku AS prod_sku
        FROM stock_requests sr 
        JOIN users u ON sr.staff_id = u.id 
        LEFT JOIN inventory_products ip ON sr.item_id = ip.id
        LEFT JOIN station_inventory si ON sr.item_id = si.product_id AND si.station_id = sr.station_id
        WHERE sr.station_id = ? 
        ORDER BY CASE sr.status WHEN 'Pending' THEN 1 ELSE 2 END, sr.created_at DESC
    ");
    $stmt->execute([$station_id]);
    $stock_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($stock_requests as $req) {
        $summary_req_total++;
        $status_lc = strtolower($req['status'] ?? 'pending');
        if ($status_lc === 'pending') {
            $summary_req_pending++;
        } elseif ($status_lc === 'approved' || $status_lc === 'validated' || $status_lc === 'waiting for purchase order' || $status_lc === 'purchase order generated') {
            $summary_req_approved++;
        } elseif ($status_lc === 'rejected') {
            $summary_req_rejected++;
        }

        $cat = $req['item_category'] ?? '';
        if ($cat !== '') {
            $req_categories[$cat] = true;
        }

        $staff = $req['staff_name'] ?? '';
        if ($staff !== '') {
            $req_staff_users[$staff] = true;
        }
    }
    ksort($req_categories);
    ksort($req_staff_users);
} catch (Exception $e) {}

// Awaiting Deliveries Verification
$pending_pos = [];
try {
    $stmt = $pdo->prepare("
        SELECT po.*, CONCAT(u_mgr.first_name, ' ', u_mgr.last_name) AS manager_name, CONCAT(u_adm.first_name, ' ', u_adm.last_name) AS admin_name, sr.item_sku, sr.item_category, sr.remarks AS sr_remarks, sr.current_stock 
        FROM purchase_orders po 
        LEFT JOIN users u_mgr ON po.created_by=u_mgr.id 
        LEFT JOIN users u_adm ON po.admin_id=u_adm.id 
        LEFT JOIN stock_requests sr ON po.request_id=sr.id 
        WHERE po.station_id=? AND po.type='merch' AND po.admin_finalized=1 AND po.delivery_validated=0 AND po.stock_in_done=0 
        ORDER BY po.admin_finalized_at ASC
    ");
    $stmt->execute([$station_id]);
    $pending_pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Validated deliveries history
$validated_pos = [];
try {
    $stmt = $pdo->prepare("
        SELECT po.*, CONCAT(u_mgr.first_name, ' ', u_mgr.last_name) AS manager_name, CONCAT(u_adm.first_name, ' ', u_adm.last_name) AS admin_name, CONCAT(u_val.first_name, ' ', u_val.last_name) AS validated_by_name 
        FROM purchase_orders po 
        LEFT JOIN users u_mgr ON po.created_by=u_mgr.id 
        LEFT JOIN users u_adm ON po.admin_id=u_adm.id 
        LEFT JOIN users u_val ON po.delivery_validated_by=u_val.id 
        WHERE po.station_id=? AND po.type='merch' AND po.delivery_validated=1 
        ORDER BY po.delivery_validated_at DESC LIMIT 50
    ");
    $stmt->execute([$station_id]);
    $validated_pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Movement History
$movement_history = [];
$mov_total_count = 0;
$mov_delivery_count = 0;
$mov_sale_count = 0;
$mov_adjustment_count = 0;
$mov_variance_count = 0;

try {
    $stmt = $pdo->prepare("
        SELECT il.id AS log_id, il.created_at, il.action AS movement_type, il.quantity_change AS quantity, 
               il.quantity_before, il.quantity_after, il.reference_type, il.reference_id, il.notes, 
               ip.product_name, ip.sku, COALESCE(si.unit, ip.size, 'pcs') AS unit, u.name AS user_name 
        FROM inventory_logs il 
        JOIN inventory_products ip ON il.product_id = ip.id 
        LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = il.station_id 
        LEFT JOIN users u ON il.user_id = u.id 
        WHERE il.station_id = ? 
        ORDER BY il.created_at DESC LIMIT 500
    ");
    $stmt->execute([$station_id]);
    $movement_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $mov_total_count = count($movement_history);
    foreach ($movement_history as $log) {
        $m_type = strtolower($log['movement_type'] ?? '');
        if ($m_type === 'delivery' || $m_type === 'stock_in' || $m_type === 'stock-in') {
            $mov_delivery_count++;
        } elseif ($m_type === 'sale' || $m_type === 'release') {
            $mov_sale_count++;
        } elseif ($m_type === 'adjustment') {
            $mov_adjustment_count++;
            // If notes contain variance or it's an adjustment, count as variance case
            if (stripos($log['notes'] ?? '', 'variance') !== false || stripos($log['notes'] ?? '', 'physical') !== false) {
                $mov_variance_count++;
            }
        }
    }
} catch (Exception $e) {}

$active_tab = $_GET['tab'] ?? 'inventory';
if (!in_array($active_tab, ['inventory', 'alerts', 'movement', 'requests'])) {
    $active_tab = 'inventory';
}
// URL-driven filter/view params (from sidebar deep-links)
$url_filter = in_array($_GET['filter'] ?? '', ['low','critical']) ? ($_GET['filter']) : '';
$url_view   = ($_GET['view'] ?? '') === 'movement' ? 'movement' : '';

include __DIR__ . '/../partials/header.php';
?>
<style>
/* Header standardization */
body { overflow-x: hidden; }
/* Prevent horizontal page scroll - tables clip to width */
.table-wrap { overflow-x: hidden !important; }
.int-head { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px; padding-top:16px; padding-bottom:16px; border-bottom:2px solid #e9ecef; }
.int-head h1 { font-size:22px !important; font-weight:700 !important; color:#00264D !important; margin:0 !important; text-transform:uppercase !important; display:flex; align-items:center; gap:8px; }
.int-head .sub { font-size:13px; color:#64748b; margin-top:4px; }
.ato-btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; padding:0 16px; border-radius:7px; font-size:13px; font-weight:600; cursor:pointer; border:1px solid transparent; text-decoration:none; transition:all .15s; height:36px; white-space:nowrap; background:white !important; }
.ato-btn-back { color:#4b5563 !important; border-color:#6b7280 !important; }
.ato-btn-back:hover { background:#6b7280 !important; color:#fff !important; }

/* Tabs Layout */
.tab-nav { display:flex; gap:0; border-bottom:2px solid #e2e8f0; margin-bottom:22px; }
.tab-btn { padding:10px 24px; background:none; border:none; border-bottom:3px solid transparent; font-size:13px; font-weight:600; color:#64748b; cursor:pointer; margin-bottom:-2px; transition:all .15s; text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
.tab-btn.active { color:#002F70; border-bottom-color:#002F70; }
.tab-btn:hover { color:#002F70; }

.cat-header td { font-weight:700; background:#e9ecef !important; color:#495057 !important; text-transform:uppercase; font-size:.8em; letter-spacing:.5px; border-bottom:2px solid #dee2e6; padding:8px 12px; text-align:center; }
.merch-row:hover { background:#f8f9fa; }
.inv-filter-bar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:14px; }
.inv-filter-bar select, .inv-filter-bar input[type="text"] { padding:8px 11px; border:1px solid #ced4da; border-radius:5px; font-size:13px; font-family:inherit; color:#1e293b; }
.inv-filter-bar select { min-width:170px; }
.inv-filter-bar input[type="text"] { min-width:210px; }

/* Status Badges */
.inv-stock-badge { display:inline-block; padding:3px 9px; border-radius:4px; font-size:11px; font-weight:600; text-transform:uppercase; }
.pstatus-badge { display:inline-block; padding:3px 9px; border-radius:4px; font-size:11px; font-weight:600; }
.pstatus-active   { background:#d4edda; color:#155724; }
.pstatus-inactive { background:#e9ecef; color:#495057; }
.pstatus-pending  { background:#fff3cd; color:#856404; }

/* Fill bar style */
.fill-bar-wrap{background:#e9ecef;border-radius:3px;height:5px;overflow:hidden;margin-bottom:2px;width:100%;}
.fill-bar-inner{height:100%;border-radius:3px;}

/* Last Movement signs style */
.mv-pos{color:#16a34a;font-weight:700;}
.mv-neg{color:#dc2626;font-weight:700;}
.mv-none{color:#94a3b8;}

.po-table-wrap { width:100%; overflow-x:auto; }
.po-table { width:100%; border-collapse:collapse; font-size:11px; table-layout:auto; }
.po-table thead tr { background:#002F70; }
.po-table thead th { padding:9px 10px; text-align:left; font-size:11px; font-weight:700; color:#fff; text-transform:uppercase; letter-spacing:.4px; border-bottom:2px solid #001a3d; vertical-align:middle; white-space:nowrap; }
.po-table tbody tr { border-bottom:1px solid #f1f5f9; transition:background .1s; }
.po-table tbody tr:hover td { background:#eff6ff; }
.po-table tbody td { padding:9px 10px; color:#334155; vertical-align:middle; white-space:normal; word-break:break-word; background:#fff; font-size:11px; }

/* Modals */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.5); z-index:9999; align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal-box { background:#fff; border-radius:12px; width:90%; max-width:600px; max-height:90vh; overflow-y:auto; box-shadow:0 20px 25px -5px rgba(0,0,0,0.15); padding:28px; }
.modal-box h3 { margin:0 0 16px; font-size:15px; color:#00264D; font-weight:700; text-transform:uppercase; display:flex; align-items:center; gap:8px; border-bottom:1px solid #f1f5f9; padding-bottom:10px; }
.modal-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; padding-bottom:14px; border-bottom:1px solid #e9ecef; }
.modal-title { font-size:1.05rem; font-weight:700; color:#002F70; display:flex; align-items:center; gap:8px; }
.modal-close { background:none; border:none; font-size:1.4rem; cursor:pointer; color:#888; line-height:1; padding:0 4px; }
.modal-close:hover { color:#333; }
.form-group { margin-bottom:14px; }
.form-group label { display:block; font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.4px; margin-bottom:5px; }
.form-group input, .form-group select, .form-group textarea { width:100%; padding:9px 11px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; box-sizing:border-box; outline:none; }
.modal-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:16px; }

/* Custom Outlined Buttons for Petron-clean Look */
.int-btn-outline {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid #002F6C;
    transition: all 0.2s;
    background: white !important;
    color: #002F6C !important;
    height: 30px;
    line-height: 1;
    white-space: nowrap;
    text-decoration: none;
}
.int-btn-outline:hover {
    background: #002F6C !important;
    color: white !important;
}

.int-btn-outline-danger {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid #dc3545;
    transition: all 0.2s;
    background: white !important;
    color: #dc3545 !important;
    height: 30px;
    line-height: 1;
    white-space: nowrap;
    text-decoration: none;
}
.int-btn-outline-danger:hover {
    background: #dc3545 !important;
    color: white !important;
}

.int-btn-outline-success {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid #28a745;
    transition: all 0.2s;
    background: white !important;
    color: #28a745 !important;
    height: 30px;
    line-height: 1;
    white-space: nowrap;
    text-decoration: none;
}
.int-btn-outline-success:hover {
    background: #28a745 !important;
    color: white !important;
}

.status-badge { display:inline-block; padding:3px 8px; border-radius:4px; font-size:10px; font-weight:700; text-transform:uppercase; white-space:nowrap; }
.badge-pending          { background:#fff3cd; color:#856404; border:1px solid #ffeeba; }
.badge-approved         { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
.badge-rejected         { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
.badge-other            { background:#e2e8f0; color:#475569; border:1px solid #cbd5e1; }

.empty-state { text-align:center; padding:40px 20px; color:#94a3b8; }
.empty-state i { font-size:40px; display:block; margin-bottom:12px; opacity:.4; }

/* Hide filtered rows */
.search-hidden { display: none !important; }

.modal-tab-btn {
    border: none;
    background: none !important;
    padding: 10px 16px;
    font-size: 13px;
    font-weight: 600;
    color: #64748b;
    border-bottom: 2px solid transparent;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 6px;
}
.modal-tab-btn:hover {
    color: #002F70;
    background: none !important;
}
.modal-tab-btn.active {
    color: #002F70;
    border-bottom-color: #002F70;
    background: none !important;
}

/* Export Buttons (Filter Button Style) */
.flt-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid transparent;
    transition: all 0.15s;
    height: 34px;
    line-height: 1;
    white-space: nowrap;
    text-decoration: none;
    background: white !important;
}
.flt-btn-search { color: #002F70 !important; border-color: #002F70 !important; }
.flt-btn-search:hover { background: #002F70 !important; color: #fff !important; }
.flt-btn-reset  { color: #6b7280 !important; border-color: #6b7280 !important; }
.flt-btn-reset:hover  { background: #6b7280 !important; color: #fff !important; }
.flt-btn-excel  { color: #1d6f42 !important; border-color: #1d6f42 !important; }
.flt-btn-excel:hover  { background: #1d6f42 !important; color: #fff !important; }
.flt-btn-pdf    { color: #dc2626 !important; border-color: #dc2626 !important; }
.flt-btn-pdf:hover    { background: #dc2626 !important; color: #fff !important; }
.flt-btn-csv    { color: #002F70 !important; border-color: #002F70 !important; }
.flt-btn-csv:hover    { background: #002F70 !important; color: #fff !important; }
</style>

<div class="int-head">
    <div>
        <h1><i class="fas fa-boxes"></i> Merchandise Inventory</h1>
    </div>
</div>

<!-- Success/Error Messages -->
<?php if (!empty($_SESSION['success'])): ?>
<div style="background:#d4edda;color:#155724;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
</div>
<?php endif; ?>
<?php if (!empty($_SESSION['error'])): ?>
<div style="background:#f8d7da;color:#721c24;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
</div>
<?php endif; ?>


<!-- Tabs Navigation -->
<div class="tab-nav">
    <a href="manager_inventory_merchandise.php?tab=inventory" class="tab-btn <?= $active_tab === 'inventory' ? 'active' : '' ?>">
        <i class="fas fa-list"></i> Inventory Overview
    </a>
    <a href="manager_inventory_merchandise.php?tab=alerts" class="tab-btn <?= $active_tab === 'alerts' ? 'active' : '' ?>">
        <i class="fas fa-exclamation-triangle"></i> Stock Alerts
        <?php if (($summary_low + $summary_out) > 0): ?>
            <span style="background:#dc3545;color:#fff;border-radius:10px;padding:1px 8px;font-size:11px;"><?= ($summary_low + $summary_out) ?></span>
        <?php endif; ?>
    </a>
    <a href="manager_inventory_merchandise.php?tab=movement" class="tab-btn <?= $active_tab === 'movement' ? 'active' : '' ?>">
        <i class="fas fa-history"></i> Stock Movement History
    </a>
    <?php if ($active_tab === 'requests'): ?>
    <a href="manager_inventory_merchandise.php?tab=requests" class="tab-btn active">
        <i class="fas fa-paper-plane"></i> Stock Requests Review
        <?php if ($summary_pending_requests > 0): ?>
            <span style="background:#dc3545;color:#fff;border-radius:10px;padding:1px 8px;font-size:11px;"><?= $summary_pending_requests ?></span>
        <?php endif; ?>
    </a>
    <?php endif; ?>
</div>
<!-- TAB CONTENT 1: Inventory Stock Catalog -->
<?php if ($active_tab === 'inventory'): ?>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:24px;">
    <!-- Total Products -->
    <div onclick="filterMgrByCard('')" style="background:#fff;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #e2e8f0;cursor:pointer;" title="Click to show All Products">
        <div>
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">Total Products</div>
            <div style="font-size:24px;font-weight:800;color:#1e293b;margin-top:4px;"><?= number_format($summary_total) ?></div>
        </div>
        <div style="background:#f0f4ff;color:#002F70;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-boxes"></i></div>
    </div>
    <!-- Low Stock -->
    <div onclick="filterMgrByCard('warning')" style="background:#fff;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #fed7aa;cursor:pointer;" title="Click to filter stock alert items">
        <div>
            <div style="font-size:11px;font-weight:700;color:#ea580c;text-transform:uppercase;letter-spacing:.3px;">Low Stock</div>
            <div style="font-size:24px;font-weight:800;color:#ea580c;margin-top:4px;"><?= number_format($summary_alert_low) ?></div>
        </div>
        <div style="background:#fff7ed;color:#ea580c;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-exclamation-triangle"></i></div>
    </div>
    <!-- Critical Stock -->
    <div onclick="filterMgrByCard('warning')" style="background:#fff;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #fecaca;cursor:pointer;" title="Click to filter stock alert items">
        <div>
            <div style="font-size:11px;font-weight:700;color:#dc2626;text-transform:uppercase;letter-spacing:.3px;">Critical Stock</div>
            <div style="font-size:24px;font-weight:800;color:#dc2626;margin-top:4px;"><?= number_format($summary_alert_critical) ?></div>
        </div>
        <div style="background:#fef2f2;color:#dc2626;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-fire"></i></div>
    </div>
    <!-- Out of Stock -->
    <div onclick="filterMgrByCard('warning')" style="background:#fff;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #fecaca;cursor:pointer;" title="Click to filter stock alert items">
        <div>
            <div style="font-size:11px;font-weight:700;color:#991b1b;text-transform:uppercase;letter-spacing:.3px;">Out of Stock</div>
            <div style="font-size:24px;font-weight:800;color:#991b1b;margin-top:4px;"><?= number_format($summary_out) ?></div>
        </div>
        <div style="background:#fef2f2;color:#991b1b;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-times-circle"></i></div>
    </div>
    <!-- Products with Variance (manager-only extra card) -->
    <div onclick="filterMgrByCard('variance detected')" style="background:#fff;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #e2e8f0;cursor:pointer;" title="Click to filter items with Variance">
        <div>
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">With Variance</div>
            <div style="font-size:24px;font-weight:800;color:#1e293b;margin-top:4px;"><?= number_format($summary_variance) ?></div>
        </div>
        <div style="background:#f8fafc;color:#64748b;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-balance-scale"></i></div>
    </div>
</div>

<div style="background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.06);border:1px solid #e9ecef;margin-bottom:20px;">
    <div style="padding:16px 20px;border-bottom:1px solid #e9ecef;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div style="font-size:1rem;font-weight:700;color:#002F70;display:flex;align-items:center;gap:8px;">
            <i class="fas fa-box"></i> Merchandise Stock Catalog
        </div>
        <div class="inv-filter-bar" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <input type="text" id="invSearch" placeholder="Search Product or SKU..." oninput="filterInvTable()" style="padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;width:180px;">
            <select id="invCatFilter" onchange="filterInvTable()" style="padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;">
                <option value="">All Categories</option>
                <?php foreach (array_keys($categories_list) as $cat): ?>
                <option value="<?php echo strtolower(htmlspecialchars($cat)); ?>"><?php echo htmlspecialchars($cat); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" id="invStockFilter" value="">
        </div>
    </div>
    <div class="table-wrap">
        <table class="table" id="mgrMerchTable">
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Product Name</th>
                    <th style="text-align:center;">Category</th>
                    <th>UOM</th>
                    <th style="text-align:center;">Capacity</th>
                    <th>Current Stock / Reorder</th>
                    <th style="text-align:right;">Physical Count</th>
                    <th style="text-align:right;">Variance</th>
                    <th style="text-align:center;">Last Movement</th>
                    <th>Last Updated</th>
                    <th style="text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody id="merchTableBody">
            <?php
            foreach ($sorted as $cat_label => $items):
            ?>
                <tr class="cat-header no-paginate"><td colspan="11"><strong><?php echo htmlspecialchars($cat_label); ?></strong></td></tr>
                <?php foreach ($items as $item):
                    $stock    = (float)($item['stock_level'] ?? 0);
                    $reorder  = (float)($item['reorder_level'] ?? 24);
                    $critical = (float)($item['critical_level'] ?? 10);
                    $capacity = (float)($item['capacity']    ?? 480);
                    $unit     = htmlspecialchars(format_merch_unit($item['unit'] ?? 'pcs'));
                    $variance = $item['variance'];
                    $has_variance = ($variance !== null && (float)$variance != 0);

                    // Dynamic capacity fallbacks
                    if ($capacity <= 0) {
                        $capacity = 480;
                    }

                    $fill_pct = $capacity > 0 ? ($stock / $capacity) * 100 : 0;

                    if ($stock <= 0) {
                        $st = 'OUT OF STOCK'; $sc = '#dc3545'; $si_cls = 'out of stock';
                    } elseif ($stock <= $critical) {
                        $st = 'CRITICAL STOCK'; $sc = '#dc3545'; $si_cls = 'critical';
                    } elseif ($stock <= $reorder) {
                        $st = 'LOW STOCK'; $sc = '#fd7e14'; $si_cls = 'low';
                    } else {
                        $st = 'AVAILABLE'; $sc = '#28a745'; $si_cls = 'available';
                    }

                    // If has variance, show it in status but keep underlying status in data-stock-status
                    $stock_status_class = $si_cls; // Preserve original status for filtering
                    if ($has_variance) {
                        $st = 'VARIANCE DETECTED'; $sc = '#fd7e14'; // Warning color (Orange)
                        $si_cls = 'variance detected';
                    }

                    $timestamp = '—';
                    if (!empty($item['last_updated'])) {
                        try {
                            $timestamp = (new DateTime($item['last_updated']))->format('M d, Y g:i A');
                        } catch (Exception $e) {}
                    }

                    $var_text = '—';
                    $var_style = 'color:#64748b;';
                    if ($variance !== null) {
                        $v_val = (float)$variance;
                        if ($v_val > 0) {
                            $var_text = '+' . number_format($v_val, 0);
                            $var_style = 'color:#28a745;font-weight:700;';
                        } elseif ($v_val < 0) {
                            $var_text = number_format($v_val, 0);
                            $var_style = 'color:#dc3545;font-weight:700;';
                        } else {
                            $var_text = '0';
                            $var_style = 'color:#64748b;font-weight:600;';
                        }
                    }

                    $phys_text = ($item['physical_count'] !== null) ? number_format((float)$item['physical_count'], 0) : '—';

                    $pid = (int)$item['id'];
                    $mv  = $last_movements[$pid] ?? null;
                    $mv_label = $mv ? ($mv['sign'].$mv['qty'].' '.$mv['type']) : '';
                    $mv_class = $mv ? ($mv['sign'] === '+' ? 'mv-pos' : ($mv['sign'] === '-' ? 'mv-neg' : 'mv-none')) : 'mv-none';
                ?>
                <tr class="merch-row"
                    data-name="<?php echo strtolower(htmlspecialchars($item['name'])); ?>"
                    data-sku="<?php echo strtolower(htmlspecialchars($item['sku'] ?? '')); ?>"
                    data-cat="<?php echo strtolower(htmlspecialchars($item['category_name'] ?? '')); ?>"
                    data-has-variance="<?php echo $has_variance ? 'true' : 'false'; ?>"
                    data-inv-status="<?php echo $si_cls; ?>"
                    data-stock-status="<?php echo $stock_status_class; ?>">
                    <td><code style="font-size:11px;font-weight:600;"><?php echo htmlspecialchars($item['sku'] ?? '—'); ?></code></td>
                    <td><strong><?php echo htmlspecialchars($item['name']); ?></strong></td>
                    <td style="text-align:center;"><?php echo htmlspecialchars($item['category_name'] ?? ''); ?></td>
                    <td><?php echo $unit; ?></td>
                    <td style="text-align:center;font-weight:600;color:#334155;"><?php echo number_format($capacity, 0); ?></td>
                    <td>
                        <div class="fill-bar-wrap">
                            <div class="fill-bar-inner" style="width:<?php echo min(100,round($fill_pct)); ?>%;background:<?php echo $sc; ?>;"></div>
                        </div>
                        <span style="font-size:11px;font-weight:600;color:#334155;"><?php echo number_format($stock, 0); ?> <?php echo $unit; ?></span>
                        <span style="font-size:10px;color:#94a3b8;margin-left:4px;">· Reorder: <?php echo number_format($reorder, 0); ?></span>
                    </td>
                    <td style="text-align:right;font-weight:700;color:#0f172a;"><?php echo $phys_text; ?></td>
                    <td style="text-align:right;<?php echo $var_style; ?>"><?php echo $var_text; ?></td>
                    <td style="text-align:center;">
                        <?php if ($mv_label): ?>
                            <span class="<?php echo $mv_class; ?>" style="font-size:11px;"><?php echo htmlspecialchars($mv_label); ?></span>
                        <?php else: ?>
                            <span class="mv-none" style="font-size:11px;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:11px;color:#64748b;"><?php echo $timestamp; ?></td>
                    <td style="text-align:center;">
                        <div style="display:flex; flex-direction:column; gap:4px; align-items:center;">
                            <button class="int-btn-outline" onclick="viewDetails(<?= (int)$item['id'] ?>, 'info')" title="View Details" style="font-size:11px; padding:6px 12px; height:30px; width:100px;">
                                <i class="fas fa-eye"></i> View
                            </button>
                            <?php if ($has_variance): ?>
                            <button class="int-btn-outline" style="border-color:#28a745; color:#28a745; font-size:11px; padding:6px 12px; height:30px; width:100px;" onclick="openAdjustmentModal(<?= (int)$item['id'] ?>, '<?= htmlspecialchars(addslashes($item['name'])) ?>', <?= (float)$stock ?>, '<?= htmlspecialchars(addslashes($unit)) ?>')" title="Adjust Stock">
                                <i class="fas fa-balance-scale"></i> Adjust
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div id="mgrMerchPagination" style="padding:10px 20px;"></div>
</div>
<?php endif; ?>

<!-- TAB CONTENT 2: Stock Alerts -->
<?php if ($active_tab === 'alerts'): ?>

<!-- Stock Alerts Summary Cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:20px;">
    <div style="background:#fff;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);border:1px solid #e2e8f0;">
        <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">Low Stock Items</div>
        <div style="font-size:26px;font-weight:800;color:#002F70;margin-top:4px;"><?= number_format($summary_alert_low) ?></div>
    </div>
    <div style="background:#fff;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);border:1px solid #e2e8f0;">
        <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">Critical Stock Items</div>
        <div style="font-size:26px;font-weight:800;color:#002F70;margin-top:4px;"><?= number_format($summary_alert_critical) ?></div>
    </div>
    <div style="background:#fff;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);border:1px solid #e2e8f0;">
        <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">Out of Stock Items</div>
        <div style="font-size:26px;font-weight:800;color:#002F70;margin-top:4px;"><?= number_format($summary_out) ?></div>
    </div>
    <div style="background:#fff;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);border:1px solid #e2e8f0;">
        <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">Variance Alerts</div>
        <div style="font-size:26px;font-weight:800;color:#002F70;margin-top:4px;"><?= number_format($summary_variance) ?></div>
    </div>
</div>
<!-- Stock Alerts Table Card -->
<div style="background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.06);border:1px solid #e9ecef;margin-bottom:20px;">
    <div style="padding:14px 20px;border-bottom:1px solid #e9ecef;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div style="font-size:1rem;font-weight:700;color:#002F70;display:flex;align-items:center;gap:8px;">
            <i class="fas fa-exclamation-triangle" style="color:#fd7e14;"></i> Stock Alerts
            <?php $total_alerts = $summary_alert_low + $summary_alert_critical + $summary_out + $summary_variance; ?>
            <?php if ($total_alerts > 0): ?>
            <span style="background:#dc3545;color:#fff;border-radius:12px;padding:2px 10px;font-size:12px;font-weight:700;"><?= $total_alerts ?> items</span>
            <?php endif; ?>
        </div>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <input type="text" id="alertSearch" placeholder="Search Product or SKU..." oninput="filterAlertTable()" style="padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;width:190px;">
            <select id="alertCatFilter" onchange="filterAlertTable()" style="padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;">
                <option value="">All Categories</option>
                <?php foreach (array_keys($categories_list) as $cat): ?>
                <option value="<?php echo strtolower(htmlspecialchars($cat)); ?>"><?php echo htmlspecialchars($cat); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="alertTypeFilter" onchange="filterAlertTable()" style="padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;">
                <option value="">All Alert Types</option>
                <option value="low stock">Low Stock</option>
                <option value="critical stock">Critical Stock</option>
                <option value="out of stock">Out of Stock</option>
                <option value="variance detected">Variance Detected</option>
            </select>
        </div>
    </div>
    <div class="table-wrap">
        <table class="table" id="mgrAlertTable">
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Product Name</th>
                    <th style="text-align:center;">Category</th>
                    <th style="text-align:right;">Current Stock</th>
                    <th style="text-align:right;">Reorder Level</th>
                    <th style="text-align:right;">Variance</th>
                    <th style="text-align:center;">Alert Type</th>
                    <th>Recommended Action</th>
                </tr>
            </thead>
            <tbody id="alertTableBody">
            <?php
            $alert_count = 0;
            foreach ($sorted as $cat_label => $items):
                $cat_alerts = [];
                foreach ($items as $item) {
                    $stock   = (float)($item['stock_level'] ?? 0);
                    $reorder = (float)($item['reorder_level'] ?? 24);
                    $critical = (float)($item['critical_level'] ?? 10);
                    $var     = $item['variance'];
                    $has_var = ($var !== null && (float)$var != 0);
                    if ($stock <= $reorder || $has_var) { $cat_alerts[] = $item; }
                }
                if (empty($cat_alerts)) continue;
            ?>
                <tr class="cat-header no-paginate"><td colspan="9"><strong><?php echo htmlspecialchars($cat_label); ?></strong></td></tr>
                <?php foreach ($cat_alerts as $item):
                    $alert_count++;
                    $stock    = (float)($item['stock_level'] ?? 0);
                    $reorder  = (float)($item['reorder_level'] ?? 24);
                    $critical = (float)($item['critical_level'] ?? 10);
                    $unit     = htmlspecialchars($item['unit'] ?? 'pcs');
                    $variance = $item['variance'];
                    $has_variance = ($variance !== null && (float)$variance != 0);
                    if ($has_variance && $stock > $reorder) {
                        $st='Variance Detected'; $sc='#28a745'; $icon='fa-balance-scale';
                        $recommended='Conduct Physical Count';
                        $rec_icon='fa-clipboard-check'; $rec_color='#28a745'; $alert_type_cls='variance detected';
                    } elseif ($stock <= 0) {
                        $st='Out of Stock'; $sc='#343a40'; $icon='fa-times-circle';
                        $recommended='Immediate Restock Required';
                        $rec_icon='fa-exclamation-circle'; $rec_color='#dc3545'; $alert_type_cls='out of stock';
                    } elseif ($stock <= $critical) {
                        $st='Critical Stock'; $sc='#dc3545'; $icon='fa-fire';
                        $recommended='Urgent: Create Stock Request';
                        $rec_icon='fa-bolt'; $rec_color='#dc3545'; $alert_type_cls='critical stock';
                    } else {
                        $st='Low Stock'; $sc='#fd7e14'; $icon='fa-exclamation-triangle';
                        $recommended='Create Stock Request';
                        $rec_icon='fa-file-alt'; $rec_color='#fd7e14'; $alert_type_cls='low stock';
                    }
                    if ($has_variance && $stock <= $reorder) { $recommended .= ' + Physical Count'; }
                    $var_text='&mdash;'; $var_style='color:#64748b;';
                    if ($variance !== null) {
                        $v_val = (float)$variance;
                        if ($v_val > 0)     { $var_text='+'.number_format($v_val,0); $var_style='color:#28a745;font-weight:700;'; }
                        elseif ($v_val < 0) { $var_text=number_format($v_val,0);     $var_style='color:#dc3545;font-weight:700;'; }
                        else                 { $var_text='0';                           $var_style='color:#64748b;font-weight:600;'; }
                    }
                ?>
                <tr class="alert-row"
                    data-name="<?php echo strtolower(htmlspecialchars($item['name'])); ?>"
                    data-sku="<?php echo strtolower(htmlspecialchars($item['sku'] ?? '')); ?>"
                    data-cat="<?php echo strtolower(htmlspecialchars($item['category_name'] ?? '')); ?>"
                    data-alert-type="<?php echo $alert_type_cls; ?>">
                    <td><code style="font-size:11px;font-weight:600;color:#475569;"><?php echo htmlspecialchars($item['sku'] ?? ''); ?></code></td>
                    <td><strong style="color:#0f172a;"><?php echo htmlspecialchars($item['name']); ?></strong></td>
                    <td style="text-align:center;color:#475569;"><?php echo htmlspecialchars($item['category_name'] ?? ''); ?></td>
                    <td style="text-align:right;font-weight:800;font-size:15px;color:<?php echo $sc; ?>;"><?php echo number_format($stock, 0); ?></td>
                    <td style="text-align:right;font-weight:600;color:#475569;"><?php echo number_format($reorder, 0); ?></td>
                    <td style="text-align:right;<?php echo $var_style; ?>"><?php echo $var_text; ?></td>
                    <td style="text-align:center;">
                        <span style="display:inline-flex;align-items:center;gap:5px;background:<?php echo $sc; ?>18;color:<?php echo $sc; ?>;border:1px solid <?php echo $sc; ?>40;padding:4px 10px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase;white-space:nowrap;">
                            <i class="fas <?php echo $icon; ?>"></i> <?php echo $st; ?>
                        </span>
                    </td>
                    <td>
                        <span style="font-size:12px;color:<?php echo $rec_color; ?>;font-weight:600;display:flex;align-items:center;gap:5px;">
                            <i class="fas <?php echo $rec_icon; ?>"></i> <?php echo htmlspecialchars($recommended); ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <?php if ($alert_count === 0): ?>
                <tr><td colspan="8" class="empty-state">
                    <i class="fas fa-check-circle" style="color:#28a745;font-size:32px;display:block;margin-bottom:8px;"></i>
                    No stock alerts. All products are at healthy stock levels!
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div id="mgrAlertPagination" style="padding:10px 20px;"></div>
</div>
<?php endif; ?>

<!-- TAB CONTENT 3: Stock Movement History -->
<?php if ($active_tab === 'movement'): ?>

<!-- Stock Movement History Summary Cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:16px;margin-bottom:20px;">
    <!-- Card 1: Total Movements -->
    <div style="background:#fff;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);border:1px solid #e2e8f0;">
        <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">Total Movements</div>
        <div style="font-size:24px;font-weight:800;color:#002F70;margin-top:4px;"><?php echo number_format($mov_total_count); ?></div>
    </div>
    
    <!-- Card 2: Deliveries -->
    <div style="background:#fff;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);border:1px solid #e2e8f0;">
        <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">Deliveries</div>
        <div style="font-size:24px;font-weight:800;color:#002F70;margin-top:4px;"><?php echo number_format($mov_delivery_count); ?></div>
    </div>

    <!-- Card 3: Releases/Sales -->
    <div style="background:#fff;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);border:1px solid #e2e8f0;">
        <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">Releases / Sales</div>
        <div style="font-size:24px;font-weight:800;color:#002F70;margin-top:4px;"><?php echo number_format($mov_sale_count); ?></div>
    </div>

    <!-- Card 4: Adjustments -->
    <div style="background:#fff;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);border:1px solid #e2e8f0;">
        <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">Adjustments</div>
        <div style="font-size:24px;font-weight:800;color:#002F70;margin-top:4px;"><?php echo number_format($mov_adjustment_count); ?></div>
    </div>

    <!-- Card 5: Variance Cases -->
    <div style="background:#fff;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);border:1px solid #e2e8f0;">
        <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">Variance Cases</div>
        <div style="font-size:24px;font-weight:800;color:#002F70;margin-top:4px;"><?php echo number_format($mov_variance_count); ?></div>
    </div>
</div>

<div style="background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.06);border:1px solid #e9ecef;margin-bottom:20px;">
    <div style="padding:16px 20px;border-bottom:1px solid #e9ecef;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div style="font-size:1rem;font-weight:700;color:#002F70;display:flex;align-items:center;gap:8px;">
            <i class="fas fa-history"></i> Stock Movement History Log
        </div>
        <div class="inv-filter-bar" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:0;">
            <input type="text" id="movSearch" placeholder="Search Product or User..." oninput="filterMovTable()" style="padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;width:180px;">
            <select id="movTypeFilter" onchange="filterMovTable()" style="padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;">
                <option value="">All Movement Types</option>
                <option value="delivery">Delivery</option>
                <option value="sale">Sale</option>
                <option value="adjustment">Adjustment</option>
                <option value="stock_request">Stock Request</option>
                <option value="correction">Correction</option>
            </select>
        </div>
    </div>
    <div class="table-wrap">
        <table class="po-table" id="mgrMovTable">
            <thead>
                <tr>
                    <th>Movement ID</th>
                    <th>Date / Time</th>
                    <th>Product Name</th>
                    <th>Movement Type</th>
                    <th style="text-align:right;">Quantity Change</th>
                    <th style="text-align:right;">Previous Stock</th>
                    <th style="text-align:right;">New Stock</th>
                    <th>Performed By</th>
                    <th>Reference No.</th>
                </tr>
            </thead>
            <tbody id="movTableBody">
            <?php if (empty($movement_history)): ?>
                <tr><td colspan="9" class="empty-state"><i class="fas fa-history"></i>No movement logs recorded yet.</td></tr>
            <?php else: ?>
                <?php foreach ($movement_history as $log): 
                    $qty = (float)$log['quantity'];
                    $qty_color = $qty > 0 ? '#16a34a' : ($qty < 0 ? '#dc2626' : '#64748b');
                    $qty_prefix = $qty > 0 ? '+' : '';
                    
                    // Normalize movement type for filter & class
                    $raw_type = strtolower($log['movement_type'] ?? '');
                    $norm_type = 'correction';
                    $m_label = 'Correction';
                    $badge_style = 'background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;';
                    
                    if (in_array($raw_type, ['delivery', 'stock_in', 'stock-in'])) {
                        $norm_type = 'delivery';
                        $m_label = 'Delivery';
                        $badge_style = 'background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;';
                    } elseif (in_array($raw_type, ['sale', 'release', 'transaction'])) {
                        $norm_type = 'sale';
                        $m_label = 'Sale';
                        $badge_style = 'background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;';
                    } elseif ($raw_type === 'adjustment') {
                        $norm_type = 'adjustment';
                        $m_label = 'Adjustment';
                        $badge_style = 'background:#f3e8ff;color:#5b21b6;border:1px solid #e9d5ff;';
                    } elseif (in_array($raw_type, ['stock_request', 'request'])) {
                        $norm_type = 'stock_request';
                        $m_label = 'Stock Request';
                        $badge_style = 'background:#e0f2fe;color:#075985;border:1px solid #bae6fd;';
                    } elseif ($raw_type === 'correction') {
                        $norm_type = 'correction';
                        $m_label = 'Correction';
                        $badge_style = 'background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;';
                    }
                    
                    // Reference No formatting
                    $ref_type = strtolower($log['reference_type'] ?? '');
                    $ref_id = $log['reference_id'];
                    $ref_no = '—';
                    if ($ref_id) {
                        if ($ref_type === 'po' || $ref_type === 'purchase_order') {
                            $ref_no = 'PO-#' . $ref_id;
                        } elseif ($ref_type === 'sale' || $ref_type === 'transaction') {
                            $ref_no = 'TXN-#' . $ref_id;
                        } elseif ($ref_type === 'adjustment') {
                            $ref_no = 'ADJ-#' . $ref_id;
                        } elseif ($ref_type === 'stock_request') {
                            $ref_no = 'SR-#' . $ref_id;
                        } else {
                            $ref_no = 'REF-#' . $ref_id;
                        }
                    }
                    
                    $mov_id_formatted = sprintf("LOG-%05d", $log['log_id']);
                ?>
                <tr class="mov-row"
                    data-log-id="<?php echo $log['log_id']; ?>"
                    data-mov-id="<?php echo $mov_id_formatted; ?>"
                    data-date="<?php echo date('Y-m-d H:i', strtotime($log['created_at'])); ?>"
                    data-product="<?php echo htmlspecialchars($log['product_name']); ?>"
                    data-sku="<?php echo htmlspecialchars($log['sku'] ?? '—'); ?>"
                    data-type-raw="<?php echo htmlspecialchars($log['movement_type']); ?>"
                    data-type-formatted="<?php echo htmlspecialchars($m_label); ?>"
                    data-qty="<?php echo $qty_prefix . number_format($qty, 0) . ' ' . htmlspecialchars($log['unit'] ?? 'pcs'); ?>"
                    data-qty-val="<?php echo $qty; ?>"
                    data-prev="<?php echo number_format($log['quantity_before'], 0) . ' ' . htmlspecialchars($log['unit'] ?? 'pcs'); ?>"
                    data-new="<?php echo number_format($log['quantity_after'], 0) . ' ' . htmlspecialchars($log['unit'] ?? 'pcs'); ?>"
                    data-user="<?php echo htmlspecialchars($log['user_name'] ?? 'System'); ?>"
                    data-ref="<?php echo htmlspecialchars($ref_no); ?>"
                    data-notes="<?php echo htmlspecialchars($log['notes'] ?? '—'); ?>"
                    data-type="<?php echo $norm_type; ?>">
                    <td><code style="font-size:11px;font-weight:600;"><?php echo $mov_id_formatted; ?></code></td>
                    <td><?php echo date('M d, Y g:i A', strtotime($log['created_at'])); ?></td>
                    <td><strong><?php echo htmlspecialchars($log['product_name']); ?></strong><br><small style="color:#64748b;">SKU: <?php echo htmlspecialchars($log['sku'] ?? '—'); ?></small></td>
                    <td>
                        <span class="inv-stock-badge" style="<?php echo $badge_style; ?>padding:3px 7px;font-size:10px;font-weight:700;border-radius:4px;">
                            <?php echo $m_label; ?>
                        </span>
                    </td>
                    <td style="text-align:right;font-weight:700;color:<?php echo $qty_color; ?>;"><?php echo $qty_prefix . number_format($qty, 0) . ' ' . htmlspecialchars($log['unit'] ?? 'pcs'); ?></td>
                    <td style="text-align:right;font-weight:600;color:#64748b;"><?php echo number_format($log['quantity_before'], 0) . ' ' . htmlspecialchars($log['unit'] ?? 'pcs'); ?></td>
                    <td style="text-align:right;font-weight:700;color:#002F70;"><?php echo number_format($log['quantity_after'], 0) . ' ' . htmlspecialchars($log['unit'] ?? 'pcs'); ?></td>
                    <td><strong><?php echo htmlspecialchars($log['user_name'] ?? 'System'); ?></strong></td>
                    <td><code><?php echo htmlspecialchars($ref_no); ?></code></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div id="mgrMovPagination" style="padding:10px 20px;"></div>
</div>
<?php endif; ?>

<!-- TAB CONTENT 4: Stock Requests Review -->
<?php if ($active_tab === 'requests'): ?>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:24px;">
    <div style="background:#fff;border-left:5px solid #002F6C;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #e2e8f0;border-left-width:5px;">
        <div>
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">Total Requests</div>
            <div style="font-size:24px;font-weight:800;color:#002F6C;margin-top:4px;"><?= number_format($summary_req_total) ?></div>
        </div>
        <div style="background:#e8f4fd;color:#002F6C;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-file-alt"></i></div>
    </div>
    <div style="background:#fff;border-left:5px solid #fd7e14;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #e2e8f0;border-left-width:5px;">
        <div>
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">Pending Requests</div>
            <div style="font-size:24px;font-weight:800;color:#fd7e14;margin-top:4px;"><?= number_format($summary_req_pending) ?></div>
        </div>
        <div style="background:#fff3cd;color:#fd7e14;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-clock"></i></div>
    </div>
    <div style="background:#fff;border-left:5px solid #28a745;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #e2e8f0;border-left-width:5px;">
        <div>
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">Approved Requests</div>
            <div style="font-size:24px;font-weight:800;color:#28a745;margin-top:4px;"><?= number_format($summary_req_approved) ?></div>
        </div>
        <div style="background:#e6f4ea;color:#28a745;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-check-circle"></i></div>
    </div>
    <div style="background:#fff;border-left:5px solid #dc3545;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #e2e8f0;border-left-width:5px;">
        <div>
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">Rejected Requests</div>
            <div style="font-size:24px;font-weight:800;color:#dc3545;margin-top:4px;"><?= number_format($summary_req_rejected) ?></div>
        </div>
        <div style="background:#fce8e6;color:#dc3545;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-times-circle"></i></div>
    </div>
</div>

<div style="background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.06);border:1px solid #e9ecef;margin-bottom:20px;">
    <div style="padding:16px 20px;border-bottom:1px solid #e9ecef;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div style="font-size:1rem;font-weight:700;color:#002F70;display:flex;align-items:center;gap:8px;">
            <i class="fas fa-paper-plane"></i> Stock Requests Review List
        </div>
    </div>
    
    <!-- Stock Request Filters -->
    <div class="inv-filter-bar" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:16px 20px;background:#f8fafc;border-bottom:1px solid #e2e8f0;">
        <div style="display:flex;flex-direction:column;gap:4px;">
            <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin:0;">Search Product</label>
            <input type="text" id="reqSearch" placeholder="Search product name or ID..." oninput="filterRequestsTable()" style="padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;width:180px;">
        </div>
        <div style="display:flex;flex-direction:column;gap:4px;">
            <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin:0;">Category</label>
            <select id="reqCatFilter" onchange="filterRequestsTable()" style="padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;">
                <option value="">All Categories</option>
                <?php foreach (array_keys($req_categories) as $cat): ?>
                <option value="<?php echo strtolower(htmlspecialchars($cat)); ?>"><?php echo htmlspecialchars($cat); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display:flex;flex-direction:column;gap:4px;">
            <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin:0;">Status</label>
            <select id="reqStatusFilter" onchange="filterRequestsTable()" style="padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;">
                <option value="">All Statuses</option>
                <option value="pending">Pending</option>
                <option value="waiting for purchase order">Waiting for PO</option>
                <option value="purchase order generated">PO Generated</option>
                <option value="approved">Approved (Legacy)</option>
                <option value="validated">Validated</option>
                <option value="rejected">Rejected</option>
            </select>
        </div>
        <div style="display:flex;flex-direction:column;gap:4px;">
            <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin:0;">Requested By</label>
            <select id="reqUserFilter" onchange="filterRequestsTable()" style="padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;">
                <option value="">All Staff</option>
                <?php foreach (array_keys($req_staff_users) as $staff): ?>
                <option value="<?php echo strtolower(htmlspecialchars($staff)); ?>"><?php echo htmlspecialchars($staff); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display:flex;flex-direction:column;gap:4px;">
            <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin:0;">Date From</label>
            <input type="date" id="reqDateFrom" onchange="filterRequestsTable()" style="padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;height:35px;box-sizing:border-box;">
        </div>
        <div style="display:flex;flex-direction:column;gap:4px;">
            <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin:0;">Date To</label>
            <input type="date" id="reqDateTo" onchange="filterRequestsTable()" style="padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;height:35px;box-sizing:border-box;">
        </div>
    </div>

    <div class="table-wrap">
        <table class="po-table" id="mgrRequestsTable">
            <thead>
                <tr>
                    <th>Request ID</th>
                    <th>Request Date</th>
                    <th>Product Name</th>
                    <th style="text-align:center;">Category</th>
                    <th style="text-align:right;">Current Stock</th>
                    <th style="text-align:right;">Reorder Level</th>
                    <th style="text-align:right;">Requested Quantity</th>
                    <th>Requested By</th>
                    <th style="text-align:center;">Status</th>
                    <th style="text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($stock_requests)): ?>
                    <tr><td colspan="10" class="empty-state"><i class="fas fa-clipboard"></i>No stock requests found.</td></tr>
                <?php else: ?>
                    <?php foreach ($stock_requests as $req): 
                        $status_lc = strtolower($req['status'] ?? 'pending');
                        $badge_cls = $status_lc === 'pending' ? 'badge-pending' : (($status_lc === 'approved' || $status_lc === 'waiting for purchase order' || $status_lc === 'purchase order generated' || $status_lc === 'validated') ? 'badge-approved' : ($status_lc === 'rejected' ? 'badge-rejected' : 'badge-other'));
                        
                        // Prepare JSON data for modal view
                        $req_json = $req;
                        $req_json['created_at_fmt'] = date('Y-m-d g:i A', strtotime($req['created_at']));
                        $req_json_encoded = htmlspecialchars(json_encode($req_json), ENT_QUOTES, 'UTF-8');
                    ?>
                    <tr class="req-row"
                        data-id="<?= (int)$req['id'] ?>"
                        data-created-at="<?= date('Y-m-d', strtotime($req['created_at'])) ?>"
                        data-staff-name="<?= strtolower(htmlspecialchars($req['staff_name'] ?? '')) ?>"
                        data-item-name="<?= strtolower(htmlspecialchars($req['item_name'] ?? '')) ?>"
                        data-category="<?= strtolower(htmlspecialchars($req['item_category'] ?? '')) ?>"
                        data-status="<?= $status_lc ?>">
                        <td><code style="font-size:11px;font-weight:700;">SR-<?= str_pad($req['id'], 4, '0', STR_PAD_LEFT) ?></code></td>
                        <td><?= date('Y-m-d g:i A', strtotime($req['created_at'])) ?></td>
                        <td><strong><?= htmlspecialchars($req['item_name']) ?></strong></td>
                        <td style="text-align:center;"><?= htmlspecialchars($req['item_category']) ?></td>
                        <?php $req_unit = format_merch_unit($req['unit'] ?? 'pcs'); ?>
                        <td style="text-align:right;font-weight:700;color:#002F70;"><?= number_format($req['current_stock']) ?> <?= htmlspecialchars($req_unit) ?></td>
                        <td style="text-align:right;font-weight:600;color:#475569;"><?= number_format($req['reorder_level']) ?> <?= htmlspecialchars($req_unit) ?></td>
                        <td style="text-align:right;font-weight:700;color:#0f172a;"><?= number_format($req['requested_quantity']) ?> <?= htmlspecialchars($req_unit) ?></td>
                        <td><strong><?= htmlspecialchars($req['staff_name'] ?? 'Staff') ?></strong></td>
                        <td style="text-align:center;"><span class="status-badge <?= $badge_cls ?>"><?= htmlspecialchars($req['status']) ?></span></td>
                        <td style="text-align:center;">
                            <div style="display:flex; flex-direction:column; gap:4px; width:110px; margin:0 auto;">
                                <button class="int-btn-outline" onclick="openReqDetailsModal(<?= $req_json_encoded ?>)" title="View Details" style="font-size:11px; padding:4px 8px; text-align:left; display:block; width:100%;">
                                    <i class="fas fa-eye" style="width:14px;"></i> Details
                                </button>
                                <?php if ($status_lc === 'pending'): ?>
                                    <button class="int-btn-outline" onclick="openApproveRequest(<?= $req['id'] ?>, '<?= htmlspecialchars(addslashes($req['item_name'])) ?>', <?= $req['requested_quantity'] ?>)" style="border-color:#28a745; color:#28a745; font-size:11px; padding:4px 8px; text-align:left; display:block; width:100%;">
                                        <i class="fas fa-check" style="width:14px;"></i> Approve
                                    </button>
                                    <button class="int-btn-outline" onclick="openRejectRequest(<?= $req['id'] ?>, '<?= htmlspecialchars(addslashes($req['item_name'])) ?>')" style="border-color:#dc3545; color:#dc3545; font-size:11px; padding:4px 8px; text-align:left; display:block; width:100%;">
                                        <i class="fas fa-times" style="width:14px;"></i> Reject
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div id="mgrRequestsPagination" style="padding:10px 20px;"></div>
</div>
<?php endif; ?>

<!-- Removed tab contents for deliveries and history -->

<!-- ─────────────────────────────────────────────────────────────────────────────
     Modals
     ───────────────────────────────────────────────────────────────────────────── -->

<!-- STOCK REQUEST DETAILS MODAL -->
<div class="modal-overlay" id="reqDetailsModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:10000; align-items:center; justify-content:center;">
    <div class="modal-box" style="max-width:650px; width:95%; background:#fff; border-radius:8px; overflow:hidden; box-shadow:0 10px 30px rgba(0,0,0,0.3);">
        <div class="modal-head" style="padding:16px 20px; background:#002F70; color:#fff; display:flex; align-items:center; justify-content:space-between;">
            <div class="modal-title" style="font-size:1.1rem; font-weight:700; color:#fff !important;"><i class="fas fa-file-alt"></i> Stock Request Details (<span id="modalReqIdText"></span>)</div>
        </div>
        <div class="modal-body" style="padding:20px; max-height:80vh; overflow-y:auto;">
            <!-- Details Grid -->
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:20px; font-size:13px;">
                <div style="background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0;">
                    <div style="color:#64748b; font-weight:700; font-size:10px; text-transform:uppercase;">Request ID</div>
                    <div id="detReqId" style="font-weight:700; color:#0f172a; margin-top:2px; font-size:14px;"></div>
                </div>
                <div style="background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0;">
                    <div style="color:#64748b; font-weight:700; font-size:10px; text-transform:uppercase;">Request Date</div>
                    <div id="detReqDate" style="font-weight:700; color:#0f172a; margin-top:2px;"></div>
                </div>
                <div style="background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0; grid-column:span 2;">
                    <div style="color:#64748b; font-weight:700; font-size:10px; text-transform:uppercase;">Product Name</div>
                    <div id="detProdName" style="font-weight:700; color:#0f172a; margin-top:2px; font-size:14px;"></div>
                </div>
                <div style="background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0;">
                    <div style="color:#64748b; font-weight:700; font-size:10px; text-transform:uppercase;">SKU</div>
                    <div id="detSKU" style="font-weight:700; color:#0f172a; margin-top:2px;"></div>
                </div>
                <div style="background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0;">
                    <div style="color:#64748b; font-weight:700; font-size:10px; text-transform:uppercase;">Category</div>
                    <div id="detCategory" style="font-weight:700; color:#0f172a; margin-top:2px;"></div>
                </div>
                <div style="background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0;">
                    <div style="color:#64748b; font-weight:700; font-size:10px; text-transform:uppercase;">Current Stock</div>
                    <div id="detCurrentStock" style="font-weight:700; color:#002F70; margin-top:2px; font-size:14px;"></div>
                </div>
                <div style="background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0;">
                    <div style="color:#64748b; font-weight:700; font-size:10px; text-transform:uppercase;">Reorder Level</div>
                    <div id="detReorderLevel" style="font-weight:700; color:#475569; margin-top:2px;"></div>
                </div>
                <div style="background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0;">
                    <div style="color:#64748b; font-weight:700; font-size:10px; text-transform:uppercase;">Requested Quantity</div>
                    <div id="detRequestedQty" style="font-weight:700; color:#0f172a; margin-top:2px; font-size:14px;"></div>
                </div>
                <div style="background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0;">
                    <div style="color:#64748b; font-weight:700; font-size:10px; text-transform:uppercase;">Requested By</div>
                    <div id="detRequestedBy" style="font-weight:700; color:#0f172a; margin-top:2px;"></div>
                </div>
                <div style="background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0; grid-column:span 2;">
                    <div style="color:#64748b; font-weight:700; font-size:10px; text-transform:uppercase;">Reason (Remarks)</div>
                    <div id="detReason" style="color:#334155; margin-top:2px; font-style:italic;"></div>
                </div>
                <div style="background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0;">
                    <div style="color:#64748b; font-weight:700; font-size:10px; text-transform:uppercase;">Status</div>
                    <div id="detStatus" style="margin-top:4px;"></div>
                </div>
                <div style="background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0;">
                    <div style="color:#64748b; font-weight:700; font-size:10px; text-transform:uppercase;">Manager Remarks</div>
                    <div id="detManagerRemarks" style="font-weight:600; color:#0f172a; margin-top:2px;">—</div>
                </div>
            </div>

            <!-- Approve/Reject Section (Shown only if Pending) -->
            <div id="modalActionSection" style="display:none; border-top:1px dashed #cbd5e1; padding-top:16px; margin-top:16px;">
                <h4 style="font-size:12px; font-weight:700; color:#002F70; text-transform:uppercase; margin-bottom:12px;"><i class="fas fa-gavel"></i> Process Request</h4>
                
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                    <!-- Approve form -->
                    <form method="post" action="manager_inventory_merchandise.php" style="background:#f0fdf4; border:1px solid #bbf7d0; padding:12px; border-radius:8px;">
                        <input type="hidden" name="action" value="approve_request">
                        <input type="hidden" name="request_id" id="modalApproveId">
                        <div style="font-weight:700; color:#16a34a; font-size:12px; margin-bottom:8px;"><i class="fas fa-check-circle"></i> Approve Option</div>
                        <div class="form-group" style="margin-bottom:8px;">
                            <label style="font-size:11px; font-weight:600; display:block; margin-bottom:3px; color:#475569;">Approved Quantity</label>
                            <input type="number" name="approved_quantity" id="modalApproveQty" min="1" required style="width:100%; padding:6px 10px; border:1px solid #cbd5e1; border-radius:4px; font-size:13px; color:#0f172a; background:#fff;">
                        </div>
                        <div class="form-group" style="margin-bottom:8px;">
                            <label style="font-size:11px; font-weight:600; display:block; margin-bottom:3px; color:#475569;">Add Remarks</label>
                            <input type="text" name="manager_notes" placeholder="e.g. Approved for next supplier order." style="width:100%; padding:6px 10px; border:1px solid #cbd5e1; border-radius:4px; font-size:12px; color:#0f172a; background:#fff;">
                        </div>
                        <button type="submit" class="txn-btn success sm" style="width:100%; margin-top:4px; background:#28a745 !important; color:#fff !important; border-color:#28a745 !important; padding:6px 12px; border-radius:4px; font-weight:700; cursor:pointer;"><i class="fas fa-check"></i> Approve Request</button>
                    </form>

                    <!-- Reject form -->
                    <form method="post" action="manager_inventory_merchandise.php" style="background:#fef2f2; border:1px solid #fecaca; padding:12px; border-radius:8px;">
                        <input type="hidden" name="action" value="reject_request">
                        <input type="hidden" name="request_id" id="modalRejectId">
                        <div style="font-weight:700; color:#dc2626; font-size:12px; margin-bottom:8px;"><i class="fas fa-times-circle"></i> Reject Option</div>
                        <div class="form-group" style="margin-bottom:8px;">
                            <label style="font-size:11px; font-weight:600; display:block; margin-bottom:3px; color:#475569;">Rejection Reason</label>
                            <select name="rejection_reason_select" onchange="updateRejectionNotes(this)" style="width:100%; padding:6px 10px; border:1px solid #cbd5e1; border-radius:4px; font-size:12px; margin-bottom:4px; color:#0f172a; background:#fff;">
                                <option value="Sufficient stock available">Sufficient stock available</option>
                                <option value="Duplicate request">Duplicate request</option>
                                <option value="Invalid quantity">Invalid quantity</option>
                                <option value="custom">Custom Reason...</option>
                            </select>
                            <input type="text" name="manager_notes" id="modalRejectNotes" value="Sufficient stock available" required style="width:100%; padding:6px 10px; border:1px solid #cbd5e1; border-radius:4px; font-size:12px; color:#0f172a; background:#fff;">
                        </div>
                        <button type="submit" class="txn-btn danger sm" style="width:100%; margin-top:4px; background:#dc3545 !important; color:#fff !important; border-color:#dc3545 !important; padding:6px 12px; border-radius:4px; font-weight:700; cursor:pointer;"><i class="fas fa-times"></i> Reject Request</button>
                    </form>
                </div>
            </div>
            
            <div class="modal-actions" style="margin-top:16px; display:flex; justify-content:flex-end;">
                <button type="button" onclick="closeReqDetailsModal()" class="ato-btn ato-btn-back">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- VIEW DETAILS MODAL -->
<div class="modal-overlay" id="detailsModal">
    <div class="modal-box" style="max-width:850px; width:95%;">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-info-circle"></i> Merchandise Product Details</div>
        </div>
        <div id="detailsContent" style="padding:10px 0;">
            <!-- Loaded via AJAX -->
        </div>
        <div class="modal-footer" id="detailsModalFooter" style="margin-top:16px;border-top:1px solid #e9ecef;padding-top:14px;display:flex;justify-content:flex-end;gap:10px;">
            <button class="ato-btn ato-btn-back" onclick="closeDetailsModal()">Close</button>
        </div>
    </div>
</div>

<!-- REQUEST INVENTORY ADJUSTMENT MODAL -->
<div class="modal-overlay" id="adjustmentModal">
    <div class="modal-box" style="max-width:500px; width:95%;">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-edit" style="color:#28a745;"></i> Request Inventory Adjustment</div>
        </div>
        <form method="post" action="manager_inventory_merchandise.php">
            <input type="hidden" name="action" value="request_adjustment">
            <input type="hidden" name="product_id" id="adjProductId">
            <div style="background:#f8fafc;padding:12px;border-radius:6px;border:1px solid #cbd5e1;margin-bottom:14px;display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div>
                    <div style="font-size:11px;color:#64748b;font-weight:700;">PRODUCT</div>
                    <div id="adjProductName" style="font-size:14px;font-weight:700;color:#002F70;margin-top:2px;">—</div>
                </div>
                <div>
                    <div style="font-size:11px;color:#64748b;font-weight:700;">CURRENT STOCK</div>
                    <div style="font-size:14px;font-weight:700;color:#002F70;margin-top:2px;"><span id="adjCurrentStock">0</span> <span id="adjUnit" style="font-weight:600;font-size:12px;color:#64748b;">pcs</span></div>
                </div>
            </div>
            <div class="form-group">
                <label>Physical Count (Actual Stock) <span style="color:red;">*</span></label>
                <input type="number" name="physical_count" id="adjPhysicalCount" min="0" required oninput="calculateAdjVariance()" style="font-size:16px;font-weight:700;color:#002F70;" placeholder="Enter actual count...">
            </div>
            
            <div id="adjVarianceBox" style="display:none;padding:12px;border-radius:6px;border:1px solid #cbd5e1;margin-bottom:14px;font-size:14px;font-weight:700;text-align:center;">
                Variance: <span id="adjVarianceValue">0</span> <span id="adjVarianceType" style="font-size:12px;font-weight:600;">(No Variance)</span>
            </div>

            <div class="form-group">
                <label>Reason / Manager Notes <span style="color:red;">*</span></label>
                <textarea name="manager_notes" rows="3" required placeholder="Provide reason for variance adjustment..."></textarea>
            </div>
            <div class="modal-actions" style="margin-top:16px;display:flex;justify-content:flex-end;gap:10px;">
                <button type="button" onclick="closeAdjustmentModal()" class="ato-btn ato-btn-back">Cancel</button>
                <button type="submit" class="ato-btn" style="background:#28a745 !important;color:#fff !important;"><i class="fas fa-save"></i> Submit Adjustment</button>
            </div>
        </form>
    </div>
</div>

<!-- APPROVE REQUEST MODAL -->
<div class="modal-overlay" id="approveRequestModal">
    <div class="modal-box" style="max-width:480px;">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-check-circle" style="color:#28a745;"></i> Approve Stock Request</div>
        </div>
        <form method="post" action="manager_inventory_merchandise.php">
            <input type="hidden" name="action" value="approve_request">
            <input type="hidden" name="request_id" id="approveReqId">
            <div style="background:#f8fafc;padding:12px;border-radius:6px;border:1px solid #cbd5e1;margin-bottom:14px;">
                <div style="font-size:11px;color:#64748b;font-weight:700;">PRODUCT</div>
                <div id="approveReqProduct" style="font-size:14px;font-weight:700;color:#002F70;margin-top:2px;">—</div>
            </div>
            <div class="form-group">
                <label>Approved Quantity <span style="color:red;">*</span></label>
                <input type="number" name="approved_quantity" id="approveReqQty" min="1" required style="font-size:16px;font-weight:700;color:#002F70;">
            </div>
            <div class="form-group">
                <label>Add Remarks / Manager Notes</label>
                <textarea name="manager_notes" rows="3" placeholder="Optional notes..."></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" onclick="closeApproveRequest()" class="ato-btn ato-btn-back">Cancel</button>
                <button type="submit" class="ato-btn" style="background:#28a745 !important;color:#fff !important;"><i class="fas fa-check"></i> Confirm Approve</button>
            </div>
        </form>
    </div>
</div>

<!-- REJECT REQUEST MODAL -->
<div class="modal-overlay" id="rejectRequestModal">
    <div class="modal-box" style="max-width:480px;">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-times-circle" style="color:#dc3545;"></i> Reject Stock Request</div>
        </div>
        <form method="post" action="manager_inventory_merchandise.php">
            <input type="hidden" name="action" value="reject_request">
            <input type="hidden" name="request_id" id="rejectReqId">
            <div style="background:#f8fafc;padding:12px;border-radius:6px;border:1px solid #cbd5e1;margin-bottom:14px;">
                <div style="font-size:11px;color:#64748b;font-weight:700;">PRODUCT</div>
                <div id="rejectReqProduct" style="font-size:14px;font-weight:700;color:#002F70;margin-top:2px;">—</div>
            </div>
            <div class="form-group">
                <label>Rejection Reason / Manager Remarks <span style="color:red;">*</span></label>
                <textarea name="manager_notes" rows="4" required placeholder="Describe the reason for rejection..."></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" onclick="closeRejectRequest()" class="ato-btn ato-btn-back">Cancel</button>
                <button type="submit" class="ato-btn" style="background:#dc3545 !important;color:#fff !important;"><i class="fas fa-times"></i> Reject Request</button>
            </div>
        </form>
    </div>
</div>

<!-- VALIDATE DELIVERY MODAL -->
<div class="modal-overlay" id="validateDeliveryModal">
    <div class="modal-box" style="max-width:480px;">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-check-double" style="color:#28a745;"></i> Verify Merchandise Delivery</div>
        </div>
        <form method="post" action="manager_inventory_merchandise.php">
            <input type="hidden" name="action" value="validate_delivery">
            <input type="hidden" name="po_id" id="validatePoId">
            <div style="background:#f8fafc;padding:12px;border-radius:6px;border:1px solid #cbd5e1;margin-bottom:14px;font-size:12px;">
                <strong>PO Number:</strong> <span id="validatePoNum">—</span><br>
                <strong>Product:</strong> <span id="validatePoProduct">—</span>
            </div>
            <div class="form-group">
                <label>Actual Qty Received <span style="color:red;">*</span></label>
                <input type="number" name="actual_qty" id="validateActualQty" min="0" required placeholder="Enter quantity received...">
            </div>
            <div class="form-group">
                <label>Delivery Status Flag <span style="color:red;">*</span></label>
                <select name="delivery_flag">
                    <option value="OK">OK — Matches PO</option>
                    <option value="Short">Short — Less than ordered</option>
                    <option value="Excess">Excess — More than ordered</option>
                    <option value="Damaged">Damaged — Items damaged</option>
                    <option value="Mixed">Mixed — Multiple issues</option>
                </select>
            </div>
            <div class="form-group">
                <label>Remarks / Notes</label>
                <textarea name="delivery_notes" rows="3" placeholder="Optional notes..."></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" onclick="closeValidateDelivery()" class="ato-btn ato-btn-back">Cancel</button>
                <button type="submit" class="ato-btn" style="background:#28a745 !important;color:#fff !important;"><i class="fas fa-check"></i> Verify Delivery</button>
            </div>
        </form>
    </div>
</div>

<!-- FLAG ISSUE MODAL -->
<div class="modal-overlay" id="flagIssueModal">
    <div class="modal-box" style="max-width:480px;">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-exclamation-triangle" style="color:#dc3545;"></i> Flag Delivery Issue</div>
        </div>
        <form method="post" action="manager_inventory_merchandise.php">
            <input type="hidden" name="action" value="flag_delivery_issue">
            <input type="hidden" name="po_id" id="flagPoId">
            <div style="background:#f8fafc;padding:12px;border-radius:6px;border:1px solid #cbd5e1;margin-bottom:14px;font-size:12px;">
                <strong>PO Number:</strong> <span id="flagPoNum">—</span>
            </div>
            <div class="form-group">
                <label>Issue Type <span style="color:red;">*</span></label>
                <select name="delivery_flag">
                    <option value="Short">Short — Less than ordered</option>
                    <option value="Damaged">Damaged — Items damaged</option>
                    <option value="Excess">Excess — More than ordered</option>
                    <option value="Mixed">Mixed — Multiple issues</option>
                </select>
            </div>
            <div class="form-group">
                <label>Issue Details <span style="color:red;">*</span></label>
                <textarea name="delivery_notes" rows="4" required placeholder="Describe the issue in detail..."></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" onclick="closeFlagIssue()" class="ato-btn ato-btn-back">Cancel</button>
                <button type="submit" class="ato-btn" style="background:#dc3545 !important;color:#fff !important;"><i class="fas fa-exclamation-triangle"></i> Flag Issue</button>
            </div>
        </form>
    </div>
<!-- CREATE STOCK REQUEST MODAL -->
<div class="modal-overlay" id="createStockRequestModal">
    <div class="modal-box" style="max-width:500px; width:95%;">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-file-alt" style="color:#002F70;"></i> Create Stock replenishment Request</div>
        </div>
        <form method="post" action="manager_inventory_merchandise.php">
            <input type="hidden" name="action" value="create_stock_request">
            <input type="hidden" name="product_id" id="srProductId">
            <div style="background:#f8fafc;padding:12px;border-radius:6px;border:1px solid #cbd5e1;margin-bottom:14px;display:grid;grid-template-columns:1fr;gap:8px;">
                <div>
                    <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;">Product Name</div>
                    <div id="srProductName" style="font-size:15px;font-weight:700;color:#002F70;margin-top:2px;">—</div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:6px;">
                    <div>
                        <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;">Current Stock</div>
                        <div id="srCurrentStock" style="font-size:14px;font-weight:700;color:#dc3545;">0</div>
                    </div>
                    <div>
                        <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;">Reorder Level</div>
                        <div id="srReorderLevel" style="font-size:14px;font-weight:700;color:#475569;">0</div>
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label>Requested Quantity <span style="color:red;">*</span></label>
                <input type="number" name="requested_quantity" id="srRequestedQty" min="1" required style="font-size:16px;font-weight:700;color:#002F70;" placeholder="Enter qty to request...">
            </div>
            <div class="form-group">
                <label>Remarks / Replenishment Reason</label>
                <textarea name="remarks" rows="3" placeholder="Optional notes (e.g. low stock, supplier order, etc.)..."></textarea>
            </div>
            <div class="modal-actions" style="margin-top:16px;display:flex;justify-content:flex-end;gap:10px;">
                <button type="button" onclick="closeCreateStockRequest()" class="ato-btn ato-btn-back">Cancel</button>
                <button type="submit" class="ato-btn" style="background:#002F70 !important;color:#fff !important;"><i class="fas fa-paper-plane"></i> Submit Request</button>
            </div>
        </form>
    </div>
<!-- VIEW MOVEMENT DETAILS MODAL -->
<div class="modal-overlay" id="viewMovementModal">
    <div class="modal-box" style="max-width:500px; width:95%;">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-history" style="color:#002F70;"></i> Stock Movement Details</div>
        </div>
        <div style="background:#f8fafc;padding:16px;border-radius:8px;border:1px solid #cbd5e1;margin-bottom:16px;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                <div>
                    <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;">Movement ID</div>
                    <div id="vmId" style="font-size:14px;font-weight:700;color:#0f172a;margin-top:2px;">—</div>
                </div>
                <div>
                    <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;">Date / Time</div>
                    <div id="vmDate" style="font-size:14px;font-weight:700;color:#0f172a;margin-top:2px;">—</div>
                </div>
            </div>
            <div style="margin-bottom:12px;">
                <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;">Product Name</div>
                <div id="vmProductName" style="font-size:15px;font-weight:700;color:#002F70;margin-top:2px;">—</div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                <div>
                    <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;">Movement Type</div>
                    <div id="vmType" style="margin-top:2px;">—</div>
                </div>
                <div>
                    <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;">Reference No.</div>
                    <div id="vmRef" style="font-size:14px;font-weight:700;color:#0f172a;margin-top:2px;">—</div>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px;border-top:1px solid #cbd5e1;padding-top:12px;">
                <div>
                    <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;">Previous Stock</div>
                    <div id="vmPrev" style="font-size:14px;font-weight:700;color:#475569;margin-top:2px;">0</div>
                </div>
                <div>
                    <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;">Qty Change</div>
                    <div id="vmChange" style="font-size:14px;font-weight:700;margin-top:2px;">0</div>
                </div>
                <div>
                    <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;">New Stock</div>
                    <div id="vmNew" style="font-size:14px;font-weight:700;color:#002F70;margin-top:2px;">0</div>
                </div>
            </div>
            <div style="border-top:1px solid #cbd5e1;padding-top:12px;margin-bottom:12px;">
                <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;">Performed By</div>
                <div id="vmUser" style="font-size:13px;font-weight:600;color:#0f172a;margin-top:2px;">—</div>
            </div>
            <div style="border-top:1px solid #cbd5e1;padding-top:12px;">
                <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;">Notes / Remarks</div>
                <div id="vmNotes" style="font-size:13px;color:#475569;margin-top:2px;white-space:pre-wrap;">—</div>
            </div>
        </div>
        <div class="modal-actions" style="margin-top:16px;display:flex;justify-content:flex-end;gap:10px;">
            <button type="button" onclick="closeViewMovement()" class="ato-btn ato-btn-back">Close</button>
            <button type="button" onclick="printMovLogFromModal()" class="ato-btn" style="background:#002F70 !important;color:#fff !important;"><i class="fas fa-print"></i> Print Slip</button>
        </div>
    </div>
</div>

<script>
// Escape HTML helper
function esc(str) {
    if (!str) return '';
    return str.toString()
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// Date formatter helper
function fmtDate(dStr) {
    if (!dStr) return '—';
    try {
        var d = new Date(dStr);
        if (isNaN(d.getTime())) return dStr;
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + ' ' +
               d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
    } catch(e) { return dStr; }
}

// ─────────────────────────────────────────────────────────────────────────────
// Modal Actions
// ─────────────────────────────────────────────────────────────────────────────
function openApproveRequest(id, product, qty) {
    document.getElementById('approveReqId').value = id;
    document.getElementById('approveReqProduct').textContent = product;
    document.getElementById('approveReqQty').value = qty;
    document.getElementById('approveRequestModal').classList.add('open');
}
function closeApproveRequest() {
    document.getElementById('approveRequestModal').classList.remove('open');
}

function openRejectRequest(id, product) {
    document.getElementById('rejectReqId').value = id;
    document.getElementById('rejectReqProduct').textContent = product;
    document.getElementById('rejectRequestModal').classList.add('open');
}
function closeRejectRequest() {
    document.getElementById('rejectRequestModal').classList.remove('open');
}

function openValidateDelivery(id, poNum, product, qty) {
    document.getElementById('validatePoId').value = id;
    document.getElementById('validatePoNum').textContent = poNum;
    document.getElementById('validatePoProduct').textContent = product;
    document.getElementById('validateActualQty').value = qty;
    document.getElementById('validateDeliveryModal').classList.add('open');
}
function closeValidateDelivery() {
    document.getElementById('validateDeliveryModal').classList.remove('open');
}

function openFlagIssue(id, poNum) {
    document.getElementById('flagPoId').value = id;
    document.getElementById('flagPoNum').textContent = poNum;
    document.getElementById('flagIssueModal').classList.add('open');
}
function closeFlagIssue() {
    document.getElementById('flagIssueModal').classList.remove('open');
}

// ─────────────────────────────────────────────────────────────────────────────
// View Details Modal (AJAX)
// ─────────────────────────────────────────────────────────────────────────────
function viewDetails(productId, focusTab) {
    focusTab = focusTab || 'info';
    var content = document.getElementById('detailsContent');
    content.innerHTML = '<div style="text-align:center;padding:48px;color:#6c757d;"><i class="fas fa-spinner fa-spin" style="font-size:2rem;margin-bottom:12px;"></i><br>Loading details...</div>';
    document.getElementById('detailsModal').classList.add('open');
    
    fetch('manager_inventory_merchandise.php?ajax=1&action=get_product_details&product_id=' + productId)
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (!res.success) {
            content.innerHTML = '<div class="alert alert-danger" style="margin:20px;">' + esc(res.message) + '</div>';
            return;
        }
        var p = res.product;
        
        // Format last updated timestamp
        var timestamp = '—';
        if (p.last_updated) {
            try {
                timestamp = fmtDate(p.last_updated);
            } catch(e) {}
        }
        
        // Format last movement
        var lastMovText = '—';
        if (res.movements && res.movements.length > 0) {
            var lm = res.movements[0];
            var lmSign = lm.quantity > 0 ? '+' : '';
            lastMovText = lmSign + lm.quantity + ' ' + esc(lm.movement_type.toUpperCase()) + ' (' + fmtDate(lm.created_at) + ')';
        }

        // Format variance & check variance flag
        var variance = p.variance;
        var vText = '—';
        var vColor = '#64748b';
        var hasVarInModal = false;
        if (variance !== null) {
            var vVal = parseFloat(variance);
            if (vVal > 0) {
                vText = '+' + vVal;
                vColor = '#28a745';
                hasVarInModal = true;
            } else if (vVal < 0) {
                vText = vVal;
                vColor = '#dc3545';
                hasVarInModal = true;
            } else {
                vText = '0 (No Variance)';
                vColor = '#64748b';
            }
        }

        var mHtml = '';
        if (res.movements.length === 0) {
            mHtml = '<tr><td colspan="5" style="text-align:center;padding:12px;color:#999;">No movement logs.</td></tr>';
        } else {
            res.movements.forEach(function(m) {
                var qtyPrefix = m.quantity > 0 ? '+' : '';
                mHtml += '<tr>' +
                    '<td>' + fmtDate(m.created_at) + '</td>' +
                    '<td><span style="font-weight:600;">' + esc(m.movement_type.toUpperCase()) + '</span></td>' +
                    '<td style="font-weight:700;color:' + (m.quantity > 0 ? '#28a745' : '#dc3545') + ';">' + qtyPrefix + m.quantity + '</td>' +
                    '<td>' + esc(m.user_name || 'System') + '</td>' +
                    '<td>' + esc(m.notes || '—') + '</td>' +
                    '</tr>';
            });
        }

        var dHtml = '';
        if (res.deliveries.length === 0) {
            dHtml = '<tr><td colspan="6" style="text-align:center;padding:12px;color:#999;">No verified deliveries.</td></tr>';
        } else {
            res.deliveries.forEach(function(d) {
                dHtml += '<tr>' +
                    '<td>' + fmtDate(d.encoded_at) + '</td>' +
                    '<td><code style="font-weight:700;">' + esc(d.po_number || '—') + '</code></td>' +
                    '<td>' + d.qty_ordered + '</td>' +
                    '<td style="font-weight:700;">' + d.qty_received + '</td>' +
                    '<td><span class="status-badge ' + (d.condition_flag === 'Good' ? 'badge-approved' : 'badge-rejected') + '">' + esc(d.condition_flag) + '</span></td>' +
                    '<td>' + esc(d.remarks || '—') + '</td>' +
                    '</tr>';
            });
        }

        content.innerHTML = 
            '<div class="modal-tabs-container" style="display:flex;border-bottom:2px solid #e2e8f0;margin-bottom:20px;gap:8px;">' +
                '<button class="modal-tab-btn active" id="btnModalInfo" onclick="switchModalTab(\'info\')"><i class="fas fa-info-circle"></i> Info</button>' +
                '<button class="modal-tab-btn" id="btnModalMovement" onclick="switchModalTab(\'movement\')"><i class="fas fa-history"></i> Stock Movement</button>' +
                '<button class="modal-tab-btn" id="btnModalDeliveries" onclick="switchModalTab(\'deliveries\')"><i class="fas fa-truck"></i> Delivery History</button>' +
            '</div>' +
            
            '<div id="tabContentInfo" class="modal-tab-content">' +
                '<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">' +
                    '<div style="background:#f8fafc;padding:16px;border-radius:8px;border:1px solid #e2e8f0;">' +
                        '<h4 style="margin:0 0 12px;color:#002F70;text-transform:uppercase;font-size:12px;letter-spacing:.5px;"><i class="fas fa-box"></i> Product Information</h4>' +
                        '<table style="width:100%;font-size:13px;border-collapse:collapse;">' +
                            '<tr><td style="padding:8px 0;color:#64748b;font-weight:600;width:120px;border-bottom:1px solid #f1f5f9;">Product Name:</td><td style="font-weight:700;color:#0f172a;border-bottom:1px solid #f1f5f9;padding:8px 0;">' + esc(p.name) + '</td></tr>' +
                            '<tr><td style="padding:8px 0;color:#64748b;font-weight:600;border-bottom:1px solid #f1f5f9;">SKU:</td><td style="border-bottom:1px solid #f1f5f9;padding:8px 0;"><code>' + esc(p.sku || '—') + '</code></td></tr>' +
                            '<tr><td style="padding:8px 0;color:#64748b;font-weight:600;border-bottom:1px solid #f1f5f9;">Category:</td><td style="border-bottom:1px solid #f1f5f9;padding:8px 0;">' + esc(p.category_name) + '</td></tr>' +
                            '<tr><td style="padding:8px 0;color:#64748b;font-weight:600;border-bottom:1px solid #f1f5f9;">Unit of Measure:</td><td style="border-bottom:1px solid #f1f5f9;padding:8px 0;">' + esc(p.unit) + '</td></tr>' +
                            '<tr><td style="padding:8px 0;color:#64748b;font-weight:600;border-bottom:1px solid #f1f5f9;">Supplier:</td><td style="font-weight:600;color:#475569;border-bottom:1px solid #f1f5f9;padding:8px 0;">' + esc(p.supplier || '—') + '</td></tr>' +
                        '</table>' +
                    '</div>' +
                    '<div style="background:#f8fafc;padding:16px;border-radius:8px;border:1px solid #e2e8f0;">' +
                        '<h4 style="margin:0 0 12px;color:#002F70;text-transform:uppercase;font-size:12px;letter-spacing:.5px;"><i class="fas fa-warehouse"></i> Inventory Information</h4>' +
                        '<table style="width:100%;font-size:13px;border-collapse:collapse;">' +
                            '<tr><td style="padding:8px 0;color:#64748b;font-weight:600;width:120px;border-bottom:1px solid #f1f5f9;">Current Stock:</td><td style="font-weight:700;font-size:14px;color:#002F70;border-bottom:1px solid #f1f5f9;padding:8px 0;">' + p.stock_level + ' ' + esc(p.unit) + '</td></tr>' +
                            '<tr><td style="padding:8px 0;color:#64748b;font-weight:600;border-bottom:1px solid #f1f5f9;">Capacity:</td><td style="font-weight:700;border-bottom:1px solid #f1f5f9;padding:8px 0;">' + p.capacity + ' ' + esc(p.unit) + '</td></tr>' +
                            '<tr><td style="padding:8px 0;color:#64748b;font-weight:600;border-bottom:1px solid #f1f5f9;">Reorder Level:</td><td style="font-weight:700;border-bottom:1px solid #f1f5f9;padding:8px 0;">' + p.reorder_level + ' ' + esc(p.unit) + '</td></tr>' +
                            '<tr><td style="padding:8px 0;color:#64748b;font-weight:600;border-bottom:1px solid #f1f5f9;">Physical Count:</td><td style="font-weight:700;color:#0f172a;border-bottom:1px solid #f1f5f9;padding:8px 0;">' + (p.physical_count !== null ? p.physical_count + ' ' + esc(p.unit) : '—') + '</td></tr>' +
                            '<tr><td style="padding:8px 0;color:#64748b;font-weight:600;border-bottom:1px solid #f1f5f9;">Variance:</td><td style="font-weight:700;color:' + vColor + ';border-bottom:1px solid #f1f5f9;padding:8px 0;">' + vText + '</td></tr>' +
                            '<tr><td style="padding:8px 0;color:#64748b;font-weight:600;border-bottom:1px solid #f1f5f9;">Last Movement:</td><td style="font-size:12px;color:#475569;border-bottom:1px solid #f1f5f9;padding:8px 0;">' + lastMovText + '</td></tr>' +
                            '<tr><td style="padding:8px 0;color:#64748b;font-weight:600;border-bottom:1px solid #f1f5f9;">Last Updated:</td><td style="font-size:12px;color:#475569;border-bottom:1px solid #f1f5f9;padding:8px 0;">' + timestamp + '</td></tr>' +
                        '</table>' +
                    '</div>' +
                '</div>' +
            '</div>' +
            
            '<div id="tabContentMovement" class="modal-tab-content" style="display:none;">' +
                '<h4 style="margin:0 0 12px;color:#002F70;text-transform:uppercase;font-size:12px;letter-spacing:.5px;"><i class="fas fa-history"></i> Stock Movement History</h4>' +
                '<div style="max-height:300px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:6px;">' +
                    '<table class="po-table" style="width:100%;">' +
                        '<thead><tr><th>Date</th><th>Type</th><th>Qty</th><th>Performed By</th><th>Notes</th></tr></thead>' +
                        '<tbody>' + mHtml + '</tbody>' +
                    '</table>' +
                '</div>' +
            '</div>' +
            
            '<div id="tabContentDeliveries" class="modal-tab-content" style="display:none;">' +
                '<h4 style="margin:0 0 12px;color:#002F70;text-transform:uppercase;font-size:12px;letter-spacing:.5px;"><i class="fas fa-truck"></i> Recent Deliveries</h4>' +
                '<div style="max-height:300px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:6px;">' +
                    '<table class="po-table" style="width:100%;">' +
                        '<thead><tr><th>Date</th><th>PO #</th><th>Ordered</th><th>Received</th><th>Condition</th><th>Remarks</th></tr></thead>' +
                        '<tbody>' + dHtml + '</tbody>' +
                    '</table>' +
                '</div>' +
            '</div>';
            
        switchModalTab(focusTab);

        // Update modal footer action button depending on variance
        var footerEl = document.getElementById('detailsModalFooter');
        if (footerEl) {
            var btnHtml = '<button type="button" class="ato-btn ato-btn-back" onclick="closeDetailsModal()">Close</button>';
            var safeName = esc(p.name).replace(/'/g, "\\'");
            var safeUnit = esc(p.unit).replace(/'/g, "\\'");
            if (hasVarInModal) {
                btnHtml += ' <button type="button" class="ato-btn" style="background:#28a745 !important;color:#fff !important;display:inline-flex;align-items:center;gap:6px;" onclick="closeDetailsModal(); openAdjustmentModal(' + p.id + ', \'' + safeName + '\', ' + p.stock_level + ', \'' + safeUnit + '\')"><i class="fas fa-balance-scale"></i> Create Stock Adjustment</button>';
            } else {
                btnHtml += ' <button type="button" disabled title="No variance detected" class="ato-btn" style="background:#cbd5e1 !important;color:#94a3b8 !important;cursor:not-allowed;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-balance-scale"></i> Create Stock Adjustment</button>';
            }
            footerEl.innerHTML = btnHtml;
        }
    })
    .catch(function(e) {
        content.innerHTML = '<div style="color:#dc3545;padding:20px;text-align:center;">Failed to load data. Network issue.</div>';
    });
}
function closeDetailsModal() {
    document.getElementById('detailsModal').classList.remove('open');
}

window.switchModalTab = function(tabName) {
    document.querySelectorAll('.modal-tab-btn').forEach(function(b) {
        b.classList.remove('active');
    });
    document.querySelectorAll('.modal-tab-content').forEach(function(c) {
        c.style.display = 'none';
    });
    
    if (tabName === 'info') {
        var btn = document.getElementById('btnModalInfo');
        if (btn) btn.classList.add('active');
        var cnt = document.getElementById('tabContentInfo');
        if (cnt) cnt.style.display = 'block';
    } else if (tabName === 'movement') {
        var btn = document.getElementById('btnModalMovement');
        if (btn) btn.classList.add('active');
        var cnt = document.getElementById('tabContentMovement');
        if (cnt) cnt.style.display = 'block';
    } else if (tabName === 'deliveries') {
        var btn = document.getElementById('btnModalDeliveries');
        if (btn) btn.classList.add('active');
        var cnt = document.getElementById('tabContentDeliveries');
        if (cnt) cnt.style.display = 'block';
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Request Inventory Adjustment Modal
// ─────────────────────────────────────────────────────────────────────────────
function openAdjustmentModal(id, name, currentStock, unit) {
    document.getElementById('adjProductId').value = id;
    document.getElementById('adjProductName').textContent = name;
    document.getElementById('adjCurrentStock').textContent = currentStock;
    document.getElementById('adjUnit').textContent = unit;
    document.getElementById('adjPhysicalCount').value = '';
    document.getElementById('adjVarianceBox').style.display = 'none';
    document.getElementById('adjustmentModal').classList.add('open');
}

function closeAdjustmentModal() {
    document.getElementById('adjustmentModal').classList.remove('open');
}

function calculateAdjVariance() {
    var current = parseFloat(document.getElementById('adjCurrentStock').textContent) || 0;
    var physical = parseFloat(document.getElementById('adjPhysicalCount').value);
    var box = document.getElementById('adjVarianceBox');
    
    if (isNaN(physical)) {
        box.style.display = 'none';
        return;
    }
    
    var variance = physical - current;
    var valSpan = document.getElementById('adjVarianceValue');
    var typeSpan = document.getElementById('adjVarianceType');
    
    valSpan.textContent = (variance > 0 ? '+' : '') + variance;
    
    if (variance > 0) {
        box.style.background = '#e6f4ea';
        box.style.color = '#137333';
        box.style.borderColor = '#c3e6cb';
        typeSpan.textContent = '(Surplus)';
    } else if (variance < 0) {
        box.style.background = '#fce8e6';
        box.style.color = '#c5221f';
        box.style.borderColor = '#f5c6cb';
        typeSpan.textContent = '(Deficit)';
    } else {
        box.style.background = '#e8f0fe';
        box.style.color = '#1a73e8';
        box.style.borderColor = '#d2e3fc';
        typeSpan.textContent = '(No Variance)';
    }
    
    box.style.display = 'block';
}

// ─────────────────────────────────────────────────────────────────────────────
// Client-side Filters
// ─────────────────────────────────────────────────────────────────────────────
function filterInvTable() {
    var cat = document.getElementById('invCatFilter').value.toLowerCase();
    var srch = document.getElementById('invSearch').value.toLowerCase().trim();
    var stFlt = document.getElementById('invStockFilter').value.toLowerCase().trim();

    console.log('=== FILTER DEBUG ===');
    console.log('Category Filter:', cat || '(all)');
    console.log('Search Filter:', srch || '(none)');
    console.log('Status Filter:', stFlt || '(all)');
    console.log('');

    var visibleCount = 0;
    var statusCounts = {};

    document.querySelectorAll('#merchTableBody .merch-row').forEach(function(r) {
        var rCat = (r.dataset.cat || '').toLowerCase();
        var rName = (r.dataset.name || '').toLowerCase();
        var rSku = (r.dataset.sku || '').toLowerCase();
        var rInv = (r.dataset.invStatus || '').toLowerCase();
        var rStockStatus = (r.dataset.stockStatus || rInv).toLowerCase();

        // Count statuses for debugging
        if (!statusCounts[rInv]) statusCounts[rInv] = 0;
        statusCounts[rInv]++;

        var matchesCat = !cat || rCat === cat;

        // WARNING TIERS: low, critical, out-of-stock are all connected
        var WARNING_STATUSES = ['low', 'critical', 'out of stock', 'out'];
        var isWarning = (WARNING_STATUSES.indexOf(rInv) !== -1 || WARNING_STATUSES.indexOf(rStockStatus) !== -1);

        // Status filter matching
        var matchesStock = false;
        if (!stFlt) {
            matchesStock = true;
        } else if (stFlt === 'warning' || WARNING_STATUSES.indexOf(stFlt) !== -1) {
            // Any warning-tier filter shows ALL warning products
            matchesStock = isWarning;
        } else if (stFlt === 'variance detected') {
            matchesStock = (rInv === 'variance detected');
        } else {
            matchesStock = (rInv === stFlt || rStockStatus === stFlt);
        }

        // Search text matching (status keywords expand to all warnings)
        var matchesSrch = false;
        if (!srch) {
            matchesSrch = true;
        } else if (['low', 'low stock', 'out', 'out of stock', 'critical', 'critical stock', 'warning'].indexOf(srch) !== -1) {
            matchesSrch = isWarning;
        } else if (srch === 'available') {
            matchesSrch = (rInv === 'available' || rStockStatus === 'available');
        } else {
            matchesSrch = rName.includes(srch) || rSku.includes(srch) || rCat.includes(srch);
        }

        var ok = matchesCat && matchesSrch && matchesStock;
        if (ok) {
            r.classList.remove('search-hidden');
            visibleCount++;
        } else {
            r.classList.add('search-hidden');
        }
    });

    console.log('Status Counts:', statusCounts);
    console.log('Visible Rows:', visibleCount);
    console.log('==================');

    // Update category header visibility based on filtered items
    var tbody = document.getElementById('merchTableBody');
    var rows = Array.from(tbody.querySelectorAll('tr'));
    var currentHeader = null;
    var hasVisibleItems = false;
    rows.forEach(function(r) {
        if (r.classList.contains('cat-header')) {
            if (currentHeader) {
                if (hasVisibleItems) {
                    currentHeader.classList.remove('search-hidden');
                    currentHeader.style.display = '';
                } else {
                    currentHeader.classList.add('search-hidden');
                    currentHeader.style.display = 'none';
                }
            }
            currentHeader = r;
            hasVisibleItems = false;
        } else if (r.classList.contains('merch-row')) {
            if (!r.classList.contains('search-hidden')) {
                hasVisibleItems = true;
            }
        }
    });
    if (currentHeader) {
        if (hasVisibleItems) {
            currentHeader.classList.remove('search-hidden');
            currentHeader.style.display = '';
        } else {
            currentHeader.classList.add('search-hidden');
            currentHeader.style.display = 'none';
        }
    }

    if (window.tablePaginationTriggers && window.tablePaginationTriggers['mgrMerchTable']) {
        window.tablePaginationTriggers['mgrMerchTable']();
    }
}

function filterAlertTable() {
    var cat = document.getElementById('alertCatFilter').value.toLowerCase().trim();
    var srch = document.getElementById('alertSearch').value.toLowerCase().trim();
    var type = document.getElementById('alertTypeFilter').value.toLowerCase().trim();
    
    console.log('=== Stock Alerts Filter ===');
    console.log('Category:', cat || '(all)');
    console.log('Search:', srch || '(none)');
    console.log('Alert Type:', type || '(all)');
    
    var visibleCount = 0;
    var typeCount = {};
    
    document.querySelectorAll('#alertTableBody .alert-row').forEach(function(r) {
        var rCat = (r.dataset.cat || '').toLowerCase().trim();
        var rName = (r.dataset.name || '').toLowerCase().trim();
        var rSku = (r.dataset.sku || '').toLowerCase().trim();
        // Use dataset.alertType which corresponds to data-alert-type in HTML
        var rType = (r.dataset.alertType || '').toLowerCase().trim();
        
        // Count alert types for debugging
        if (!typeCount[rType]) {
            typeCount[rType] = 0;
        }
        typeCount[rType]++;
        
        var matchesCat = !cat || rCat === cat;
        var matchesSrch = !srch || rName.includes(srch) || rSku.includes(srch);
        var matchesType = !type || rType === type;
        
        var ok = matchesCat && matchesSrch && matchesType;
        if (ok) {
            r.classList.remove('search-hidden');
            r.style.display = '';
            visibleCount++;
        } else {
            r.classList.add('search-hidden');
            r.style.display = 'none';
        }
    });

    console.log('Visible alerts:', visibleCount);
    console.log('Alert type breakdown:', typeCount);
    console.log('===========================');

    if (window.tablePaginationTriggers && window.tablePaginationTriggers['mgrAlertTable']) {
        window.tablePaginationTriggers['mgrAlertTable']();
    }
}

function openCreateStockRequest(id, name, stock, reorder) {
    document.getElementById('srProductId').value = id;
    document.getElementById('srProductName').textContent = name;
    document.getElementById('srCurrentStock').textContent = stock;
    document.getElementById('srReorderLevel').textContent = reorder;
    document.getElementById('srRequestedQty').value = Math.max(1, reorder - stock);
    document.getElementById('createStockRequestModal').classList.add('open');
}

function closeCreateStockRequest() {
    document.getElementById('createStockRequestModal').classList.remove('open');
}

function printAlertRow(id, name, type, stock, reorder) {
    var printWindow = window.open('', '_blank');
    printWindow.document.write('<html><head><title>Stock Alert Slip</title>');
    printWindow.document.write('<style>body{font-family:Arial,sans-serif;padding:30px;color:#333;} h2{color:#002F70;border-bottom:2px solid #002F70;padding-bottom:8px;margin-bottom:20px;} table{width:100%;border-collapse:collapse;margin-top:20px;} th,td{padding:12px;text-align:left;border-bottom:1px solid #ddd;} th{background:#f8fafc;color:#475569;font-weight:bold;width:150px;} td{color:#0f172a;font-weight:600;} .footer{margin-top:40px;border-top:1px dashed #ccc;padding-top:20px;font-size:12px;text-align:center;color:#64748b;}</style>');
    printWindow.document.write('</head><body>');
    printWindow.document.write('<h2>PETRON Merchandise Stock Alert Slip</h2>');
    printWindow.document.write('<p><strong>Date Generated:</strong> ' + new Date().toLocaleString() + '</p>');
    printWindow.document.write('<table>');
    printWindow.document.write('<tr><th>Product ID</th><td>#' + id + '</td></tr>');
    printWindow.document.write('<tr><th>Product Name</th><td>' + name + '</td></tr>');
    printWindow.document.write('<tr><th>Current Stock</th><td>' + stock + '</td></tr>');
    printWindow.document.write('<tr><th>Reorder Level</th><td>' + reorder + '</td></tr>');
    printWindow.document.write('<tr><th>Alert Type</th><td>' + type + '</td></tr>');
    printWindow.document.write('</table>');
    printWindow.document.write('<div class="footer">Petron Station Management System &copy; ' + new Date().getFullYear() + '</div>');
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.print();
}

// ─────────────────────────────────────────────────────────────────────────────
// Inventory Overview — Print Record
// ─────────────────────────────────────────────────────────────────────────────
function printProductRecord(productId) {
    fetch('manager_inventory_merchandise.php?ajax=1&action=get_product_details&product_id=' + productId)
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (!res.success) { alert('Could not load product data.'); return; }
        var p = res.product;

        var vText = '—', vColor = '#64748b';
        if (p.variance !== null) {
            var vVal = parseFloat(p.variance);
            if (vVal > 0)      { vText = '+' + vVal; vColor = '#28a745'; }
            else if (vVal < 0) { vText = vVal;        vColor = '#dc3545'; }
            else               { vText = '0 (No Variance)'; }
        }

        var stock = parseFloat(p.stock_level) || 0;
        var reorder = parseFloat(p.reorder_level) || 0;

        var ts = p.last_updated ? (function(d) {
            var dt = new Date(d);
            return isNaN(dt) ? d : dt.toLocaleDateString('en-US', {year:'numeric',month:'short',day:'2-digit'}) + ' ' + dt.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'});
        })(p.last_updated) : '—';

        var pw = window.open('', '_blank');
        pw.document.write('<!DOCTYPE html><html><head><title>Product Record — ' + esc(p.name) + '</title>');
        pw.document.write('<style>');
        pw.document.write('body{font-family:Arial,sans-serif;font-size:13px;color:#222;margin:0;padding:24px;}');
        pw.document.write('.header{background:#002F6C;color:#fff;padding:16px 20px;border-radius:6px 6px 0 0;margin-bottom:0;}');
        pw.document.write('.header h2{margin:0;font-size:16px;letter-spacing:.5px;}');
        pw.document.write('.header p{margin:4px 0 0;font-size:11px;opacity:.8;}');
        pw.document.write('.section{border:1px solid #e2e8f0;border-top:none;padding:16px 20px;margin-bottom:12px;}');
        pw.document.write('.section h4{margin:0 0 10px;color:#002F6C;font-size:11px;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid #e2e8f0;padding-bottom:6px;}');
        pw.document.write('table.info{width:100%;border-collapse:collapse;font-size:12px;}');
        pw.document.write('table.info tr td:first-child{color:#64748b;font-weight:600;width:160px;padding:5px 0;}');
        pw.document.write('table.info tr td{padding:5px 0;border-bottom:1px solid #f1f5f9;}');
        pw.document.write('.badge{display:inline-block;padding:3px 10px;border-radius:4px;font-size:11px;font-weight:700;}');
        pw.document.write('.footer{text-align:center;font-size:10px;color:#94a3b8;margin-top:20px;border-top:1px solid #e2e8f0;padding-top:10px;}');
        pw.document.write('@media print{.no-print{display:none;}}');
        pw.document.write('</style></head><body>');
        pw.document.write('<div class="header"><h2><i></i> Product Inventory Record</h2><p>Petron Station Management System &mdash; Printed: ' + new Date().toLocaleString() + '</p></div>');
        pw.document.write('<div class="section"><h4>Product Information</h4>');
        pw.document.write('<table class="info">');
        pw.document.write('<tr><td>Product Name:</td><td><strong>' + esc(p.name) + '</strong></td></tr>');
        pw.document.write('<tr><td>SKU:</td><td><code>' + esc(p.sku || '—') + '</code></td></tr>');
        pw.document.write('<tr><td>Category:</td><td>' + esc(p.category_name || '—') + '</td></tr>');
        pw.document.write('<tr><td>Unit:</td><td>' + esc(p.unit || '—') + '</td></tr>');
        pw.document.write('<tr><td>Supplier:</td><td>' + esc(p.supplier || '—') + '</td></tr>');
        pw.document.write('</table></div>');
        pw.document.write('<div class="section"><h4>Inventory Details</h4>');
        pw.document.write('<table class="info">');
        pw.document.write('<tr><td>Current Stock:</td><td><strong style="font-size:15px;color:#002F6C;">' + p.stock_level + ' ' + esc(p.unit) + '</strong></td></tr>');
        pw.document.write('<tr><td>Reorder Level:</td><td>' + p.reorder_level + ' ' + esc(p.unit) + '</td></tr>');
        pw.document.write('<tr><td>Physical Count:</td><td>' + (p.physical_count !== null ? p.physical_count + ' ' + esc(p.unit) : '—') + '</td></tr>');
        pw.document.write('<tr><td>Variance:</td><td><span style="color:' + vColor + ';font-weight:700;">' + vText + '</span></td></tr>');
        pw.document.write('<tr><td>Last Updated:</td><td>' + ts + '</td></tr>');
        pw.document.write('</table></div>');
        pw.document.write('<div class="footer">Petron Station Management System &copy; ' + new Date().getFullYear() + '</div>');
        pw.document.write('</body></html>');
        pw.document.close();
        pw.print();
    })
    .catch(function() { alert('Network error. Could not load product record.'); });
}

// ─────────────────────────────────────────────────────────────────────────────
// Inventory Overview — Export PDF / Excel
// ─────────────────────────────────────────────────────────────────────────────
function exportInvTablePDF() {
    if (typeof exportTableToPDF === 'function') {
        exportTableToPDF('mgrMerchTable', 'Merchandise Inventory Overview Report');
    } else {
        window.print();
    }
}

function exportInvTableExcel() {
    if (typeof exportTableToExcel === 'function') {
        exportTableToExcel('mgrMerchTable', 'merchandise_inventory_overview.xls');
    } else {
        alert('Excel export not supported on this page.');
    }
}

function exportAlertTablePDF() {
    if (typeof exportTableToPDF === 'function') {
        exportTableToPDF('mgrAlertTable', 'Merchandise Stock Alerts Report');
    } else {
        window.print();
    }
}

function exportAlertTableExcel() {
    if (typeof exportTableToExcel === 'function') {
        exportTableToExcel('mgrAlertTable', 'merchandise_stock_alerts_report.xls');
    } else {
        alert('Excel export not supported on this page');
    }
}

var activeVmLogId = null;
function viewMovLogDetails(btn) {
    var row = btn.closest('tr');
    activeVmLogId = row.dataset.logId;
    document.getElementById('vmId').textContent = row.dataset.movId;
    document.getElementById('vmDate').textContent = row.dataset.date;
    document.getElementById('vmProductName').textContent = row.dataset.product + ' (' + row.dataset.sku + ')';
    
    var typeBadge = document.getElementById('vmType');
    typeBadge.textContent = row.dataset.typeFormatted;
    typeBadge.className = 'inv-stock-badge';
    // Style typeBadge accordingly
    var typeRaw = row.dataset.typeRaw.toLowerCase();
    if (['delivery', 'stock_in', 'stock-in'].indexOf(typeRaw) !== -1) {
        typeBadge.style.cssText = 'background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;padding:3px 7px;font-size:10px;font-weight:700;border-radius:4px;display:inline-block;';
    } else if (['sale', 'release', 'transaction'].indexOf(typeRaw) !== -1) {
        typeBadge.style.cssText = 'background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;padding:3px 7px;font-size:10px;font-weight:700;border-radius:4px;display:inline-block;';
    } else if (typeRaw === 'adjustment') {
        typeBadge.style.cssText = 'background:#f3e8ff;color:#5b21b6;border:1px solid #e9d5ff;padding:3px 7px;font-size:10px;font-weight:700;border-radius:4px;display:inline-block;';
    } else if (['stock_request', 'request'].indexOf(typeRaw) !== -1) {
        typeBadge.style.cssText = 'background:#e0f2fe;color:#075985;border:1px solid #bae6fd;padding:3px 7px;font-size:10px;font-weight:700;border-radius:4px;display:inline-block;';
    } else {
        typeBadge.style.cssText = 'background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;padding:3px 7px;font-size:10px;font-weight:700;border-radius:4px;display:inline-block;';
    }
    
    document.getElementById('vmRef').textContent = row.dataset.ref;
    document.getElementById('vmPrev').textContent = row.dataset.prev;
    
    var changeEl = document.getElementById('vmChange');
    changeEl.textContent = row.dataset.qty;
    var qtyVal = parseFloat(row.dataset.qtyVal) || 0;
    changeEl.style.color = qtyVal > 0 ? '#16a34a' : (qtyVal < 0 ? '#dc2626' : '#64748b');
    
    document.getElementById('vmNew').textContent = row.dataset.new;
    document.getElementById('vmUser').textContent = row.dataset.user;
    document.getElementById('vmNotes').textContent = row.dataset.notes;
    
    document.getElementById('viewMovementModal').classList.add('open');
}

function closeViewMovement() {
    document.getElementById('viewMovementModal').classList.remove('open');
}

function printMovLogFromModal() {
    if (activeVmLogId) {
        var row = document.querySelector('tr[data-log-id="' + activeVmLogId + '"]');
        if (row) {
            printMovLogRecord(row);
        }
    }
}

function printMovLogRecord(el) {
    var row = (el instanceof HTMLElement && el.tagName === 'TR') ? el : el.closest('tr');
    if (!row) return;
    
    var printWindow = window.open('', '_blank');
    printWindow.document.write('<html><head><title>Stock Movement Record</title>');
    printWindow.document.write('<style>body{font-family:Arial,sans-serif;padding:30px;color:#333;} h2{color:#002F70;border-bottom:2px solid #002F70;padding-bottom:8px;margin-bottom:20px;} table{width:100%;border-collapse:collapse;margin-top:20px;} th,td{padding:12px;text-align:left;border-bottom:1px solid #ddd;} th{background:#f8fafc;color:#475569;font-weight:bold;width:180px;} td{color:#0f172a;font-weight:600;} .footer{margin-top:40px;border-top:1px dashed #ccc;padding-top:20px;font-size:12px;text-align:center;color:#64748b;}</style>');
    printWindow.document.write('</head><body>');
    printWindow.document.write('<h2>PETRON Merchandise Stock Movement Slip</h2>');
    printWindow.document.write('<p><strong>Date Generated:</strong> ' + new Date().toLocaleString() + '</p>');
    printWindow.document.write('<table>');
    printWindow.document.write('<tr><th>Movement ID</th><td>' + row.dataset.movId + '</td></tr>');
    printWindow.document.write('<tr><th>Date / Time</th><td>' + row.dataset.date + '</td></tr>');
    printWindow.document.write('<tr><th>Product SKU</th><td>' + row.dataset.sku + '</td></tr>');
    printWindow.document.write('<tr><th>Product Name</th><td>' + row.dataset.product + '</td></tr>');
    printWindow.document.write('<tr><th>Movement Type</th><td>' + row.dataset.typeFormatted + '</td></tr>');
    printWindow.document.write('<tr><th>Reference No.</th><td>' + row.dataset.ref + '</td></tr>');
    printWindow.document.write('<tr><th>Previous Stock</th><td>' + row.dataset.prev + '</td></tr>');
    printWindow.document.write('<tr><th>Quantity Change</th><td>' + row.dataset.qty + '</td></tr>');
    printWindow.document.write('<tr><th>New Stock</th><td>' + row.dataset.new + '</td></tr>');
    printWindow.document.write('<tr><th>Performed By</th><td>' + row.dataset.user + '</td></tr>');
    printWindow.document.write('<tr><th>Notes / Remarks</th><td>' + row.dataset.notes + '</td></tr>');
    printWindow.document.write('</table>');
    printWindow.document.write('<div class="footer">Petron Station Management System &copy; ' + new Date().getFullYear() + '</div>');
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.print();
}

function filterMovTable() {
    var type = document.getElementById('movTypeFilter').value.trim().toLowerCase();
    var srch = document.getElementById('movSearch').value.trim().toLowerCase();
    document.querySelectorAll('#movTableBody .mov-row').forEach(function(r) {
        var rType = (r.dataset.type || '').trim().toLowerCase();
        var rProduct = (r.dataset.product || '').toLowerCase();
        var rSku = (r.dataset.sku || '').toLowerCase();
        var rUser = (r.dataset.user || '').toLowerCase();
        
        var matchesType = (type === '' || rType === type);
        var matchesSrch = (srch === '' || rProduct.includes(srch) || rSku.includes(srch) || rUser.includes(srch));
        
        var ok = matchesType && matchesSrch;
        if (ok) {
            r.classList.remove('search-hidden');
            r.style.display = '';
        } else {
            r.classList.add('search-hidden');
            r.style.display = 'none';
        }
    });

    if (window.tablePaginationTriggers && window.tablePaginationTriggers['mgrMovTable']) {
        window.tablePaginationTriggers['mgrMovTable']();
    }
}

function filterRequestsTable() {
    var srch = document.getElementById('reqSearch').value.toLowerCase();
    var cat = document.getElementById('reqCatFilter').value.toLowerCase();
    var status = document.getElementById('reqStatusFilter').value.toLowerCase();
    var user = document.getElementById('reqUserFilter').value.toLowerCase();
    var dateFrom = document.getElementById('reqDateFrom').value;
    var dateTo = document.getElementById('reqDateTo').value;

    document.querySelectorAll('#mgrRequestsTable tbody .req-row').forEach(function(r) {
        var rId = (r.dataset.id || '').toLowerCase();
        var rItem = (r.dataset.itemName || '').toLowerCase();
        var rCat = (r.dataset.category || '').toLowerCase();
        var rStatus = (r.dataset.status || '').toLowerCase();
        var rUser = (r.dataset.staffName || '').toLowerCase();
        var rDate = r.dataset.createdAt;

        var matchesSrch = !srch || rItem.includes(srch) || rId.includes(srch) || ('sr-' + rId.padStart(4, '0')).includes(srch);
        var matchesCat = !cat || rCat === cat;
        var matchesStatus = false;
        if (!status) {
            matchesStatus = true;
        } else if (status === 'approved') {
            matchesStatus = (rStatus === 'approved' || rStatus === 'waiting for purchase order' || rStatus === 'purchase order generated' || rStatus === 'validated');
        } else {
            matchesStatus = rStatus === status;
        }
        var matchesUser = !user || rUser === user;
        
        var matchesDate = true;
        if (dateFrom && rDate < dateFrom) matchesDate = false;
        if (dateTo && rDate > dateTo) matchesDate = false;

        var ok = matchesSrch && matchesCat && matchesStatus && matchesUser && matchesDate;
        if (ok) {
            r.classList.remove('search-hidden');
        } else {
            r.classList.add('search-hidden');
        }
    });

    if (window.tablePaginationTriggers && window.tablePaginationTriggers['mgrRequestsTable']) {
        window.tablePaginationTriggers['mgrRequestsTable']();
    }
}

function exportMovTablePDF() {
    if (typeof exportTableToPDF === 'function') {
        exportTableToPDF('mgrMovTable', 'Merchandise Stock Movement History');
    } else {
        window.print();
    }
}

function exportMovTableExcel() {
    if (typeof exportTableToExcel === 'function') {
        exportTableToExcel('mgrMovTable', 'merchandise_stock_movement_history.xls');
    } else {
        alert('Excel export not supported on this page');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Standard table pagination setup
    <?php if ($active_tab === 'inventory'): ?>
    setupTablePagination('mgrMerchTable', 'mgrMerchRowsLimit', 'mgrMerchPagination', 50);
    <?php elseif ($active_tab === 'alerts'): ?>
    setupTablePagination('mgrAlertTable', 'mgrMerchRowsLimit', 'mgrAlertPagination', 50);
    <?php elseif ($active_tab === 'movement'): ?>
    setupTablePagination('mgrMovTable', 'mgrMerchRowsLimit', 'mgrMovPagination', 50);
    <?php elseif ($active_tab === 'requests'): ?>
    setupTablePagination('mgrRequestsTable', 'mgrMerchRowsLimit', 'mgrRequestsPagination', 50);
    <?php elseif ($active_tab === 'deliveries'): ?>
    setupTablePagination('mgrDeliveriesTable', 'mgrMerchRowsLimit', 'mgrMerchPagination', 50);
    <?php elseif ($active_tab === 'history'): ?>
    setupTablePagination('mgrHistoryTable', 'mgrMerchRowsLimit', 'mgrMerchPagination', 50);
    <?php endif; ?>

    // ── Auto-apply URL-driven filter (from sidebar deep-links) ──
    <?php if ($url_filter === 'low'): ?>
    // Low Stock Only
    var lowCb = document.getElementById('invLowStockOnly');
    if (lowCb) { lowCb.checked = true; filterInvTable(); }
    <?php elseif ($url_filter === 'critical'): ?>
    // Critical Stock Only
    var critCb = document.getElementById('invCriticalOnly');
    if (critCb) { critCb.checked = true; filterInvTable(); }
    <?php endif; ?>

    // ── Auto-scroll to movement history section if ?view=movement ──
    <?php if ($url_view === 'movement'): ?>
    var mvSect = document.getElementById('movementHistorySection');
    if (mvSect) { setTimeout(function(){ mvSect.scrollIntoView({behavior:'smooth',block:'start'}); }, 300); }
    <?php endif; ?>
});

function filterMgrByCard(val) {
    var select = document.getElementById('invStockFilter');
    if (!select) return;
    if (select.value === val) {
        select.value = '';
    } else {
        select.value = val;
    }
    if (typeof filterInvTable === 'function') {
        filterInvTable();
    } else {
        var event = new Event('change');
        select.dispatchEvent(event);
    }
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
