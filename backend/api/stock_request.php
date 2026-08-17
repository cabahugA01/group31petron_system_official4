<?php
/**
 * Stock Request API
 * Handles staff stock requests, manager approval/rejection, and admin oversight.
 * Endpoint: backend/api/stock_request.php
 */
require_once '../lib.php';
require_once '../../public/db_connect.php';

header('Content-Type: application/json');

$me         = current_user();
if (!$me) {
    echo json_encode(['success' => false, 'message' => 'User not authenticated']);
    exit;
}

$role       = role_key($me['role'] ?? 'staff');
$station_id = user_station_id();
$method     = $_SERVER['REQUEST_METHOD'];
$action     = $_GET['action'] ?? '';

function sr_get_safe_user_id(PDO $pdo, array $me, int $station_id): ?int {
    $user_id = (int)($me['id'] ?? 0);
    if ($user_id > 0) {
        try {
            $chk = $pdo->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
            $chk->execute([$user_id]);
            $val = $chk->fetchColumn();
            if ($val !== false && (int)$val > 0) {
                return (int)$val;
            }
        } catch (Exception $e) {}
    }
    try {
        $chk_alt = $pdo->prepare("SELECT id FROM users WHERE station_id = ? ORDER BY id ASC LIMIT 1");
        $chk_alt->execute([$station_id]);
        $val = $chk_alt->fetchColumn();
        if ($val !== false && (int)$val > 0) {
            return (int)$val;
        }
    } catch (Exception $e) {}

    return null;
}
function sr_resolve_merch_product(PDO $pdo, int $item_id): ?array {
    if ($item_id <= 0) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id, product_name, category, COALESCE(unit_price, unit_cost, 0) AS unit_price
            FROM inventory_products
            WHERE id = ? AND LOWER(COALESCE(category, '')) <> 'fuel'
            LIMIT 1
        ");
        $stmt->execute([$item_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    } catch (Throwable $ignored) {}

    try {
        $stmt = $pdo->prepare("
            SELECT p.id,
                   p.name AS product_name,
                   COALESCE(pc.name, 'General') AS category,
                   COALESCE(p.price, p.cost, 0) AS unit_price
            FROM products p
            LEFT JOIN product_categories pc ON pc.id = p.category_id
            WHERE p.id = ?
              AND LOWER(COALESCE(pc.name, '')) NOT IN ('fuel', 'fuel products', 'services')
            LIMIT 1
        ");
        $stmt->execute([$item_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $ignored) {
        return null;
    }
}

function sr_resolve_merch_unit_price(PDO $pdo, int $item_id): float {
    $product = sr_resolve_merch_product($pdo, $item_id);
    return $product ? (float)($product['unit_price'] ?? 0) : 0.0;
}

// ── Route ────────────────────────────────────────────────────────────────────
try {
    switch ($action) {

        // ── Staff: create a new stock request ────────────────────────────────
        case 'create':
            if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
                echo json_encode(['success' => false, 'message' => 'Access denied']); exit;
            }
            if ($method !== 'POST') {
                echo json_encode(['success' => false, 'message' => 'POST required']); exit;
            }
            handle_create($pdo, $me, $role, $station_id);
            break;

        // ── Staff: list own requests ──────────────────────────────────────────
        case 'my_requests':
            if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
                echo json_encode(['success' => false, 'message' => 'Access denied']); exit;
            }
            handle_my_requests($pdo, $me);
            break;

        // ── Manager: list station requests ───────────────────────────────────
        case 'get_requests':
            if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
                echo json_encode(['success' => false, 'message' => 'Access denied']); exit;
            }
            handle_get_requests($pdo, $me, $role, $station_id);
            break;

        // ── Manager: approve (adjust qty) ────────────────────────────────────
        case 'approve':
            if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
                echo json_encode(['success' => false, 'message' => 'Access denied']); exit;
            }
            if ($method !== 'POST') {
                echo json_encode(['success' => false, 'message' => 'POST required']); exit;
            }
            handle_approve($pdo, $me, $role, $station_id);
            break;

        // ── Manager: reject ───────────────────────────────────────────────────
        case 'reject':
            if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
                echo json_encode(['success' => false, 'message' => 'Access denied']); exit;
            }
            if ($method !== 'POST') {
                echo json_encode(['success' => false, 'message' => 'POST required']); exit;
            }
            handle_reject($pdo, $me, $role, $station_id);
            break;

        // ── Admin: audit trail ────────────────────────────────────────────────
        case 'audit_trail':
            if (!in_array($role, ['admin', 'superadmin'])) {
                echo json_encode(['success' => false, 'message' => 'Access denied']); exit;
            }
            handle_audit_trail($pdo, $me, $role, $station_id);
            break;

        // ── Admin: export CSV ─────────────────────────────────────────────────
        case 'export_csv':
            if (!in_array($role, ['admin', 'superadmin'])) {
                echo json_encode(['success' => false, 'message' => 'Access denied']); exit;
            }
            handle_export_csv($pdo, $me, $role, $station_id);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log("Stock Request API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

// ─────────────────────────────────────────────────────────────────────────────
// HANDLERS
// ─────────────────────────────────────────────────────────────────────────────

function get_next_request_no($pdo, $table, $prefix = 'SR') {
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT request_no FROM {$table} WHERE request_no LIKE ? ORDER BY request_no DESC LIMIT 1");
    $stmt->execute(["{$prefix}-{$year}-%"]);
    $last = $stmt->fetchColumn();
    if ($last) {
        $parts = explode('-', $last);
        $seq = (int)end($parts) + 1;
    } else {
        $seq = 1;
    }
    return sprintf("%s-%s-%04d", $prefix, $year, $seq);
}

function handle_create($pdo, $me, $role, $station_id) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON input']); return;
    }

    $remarks = trim($input['remarks'] ?? '');
    $items = [];
    if (isset($input['items']) && is_array($input['items'])) {
        $items = $input['items'];
    } else {
        $items[] = [
            'item_id'            => (int)($input['item_id'] ?? 0),
            'sku'                => trim($input['sku'] ?? ''),
            'item_name'          => trim($input['item_name'] ?? ''),
            'item_category'      => trim($input['item_category'] ?? ''),
            'current_stock'      => (int)($input['current_stock'] ?? 0),
            'requested_quantity' => (int)($input['requested_quantity'] ?? 0),
        ];
    }

    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'No items specified']); return;
    }

    $request_no = get_next_request_no($pdo, 'stock_requests', 'PR');

    $pdo->beginTransaction();
    try {
        $inserted_ids = [];
        $skipped_items = [];

        foreach ($items as $item) {
            $item_id            = (int)($item['item_id'] ?? 0);
            $sku                = trim($item['sku'] ?? '');
            $item_name          = trim($item['item_name'] ?? '');
            $item_category      = trim($item['item_category'] ?? '');
            $current_stock      = (int)($item['current_stock'] ?? 0);
            $requested_quantity = (int)($item['requested_quantity'] ?? 0);

            if (in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
                $requested_quantity = 0;
            }

            if ($item_id <= 0 || empty($item_name)) {
                continue;
            }

            $db_item = sr_resolve_merch_product($pdo, $item_id);
            if (!$db_item) {
                continue;
            }
            if (empty($item_category)) {
                $item_category = $db_item['category'] ?? '';
            }

            // Only store item_id when it references inventory_products.
            // If the product was resolved from the fallback `products` table, using
            // that ID would violate the FK fk_stock_req_item → inventory_products(id).
            $safe_item_id = null;
            try {
                $chk = $pdo->prepare("SELECT id FROM inventory_products WHERE id = ? LIMIT 1");
                $chk->execute([$item_id]);
                if ($chk->fetchColumn() !== false) {
                    $safe_item_id = $item_id;
                }
            } catch (Throwable $ignored) {}

            // Validate staff_id against users table
            $safe_staff_id = null;
            if (!empty($me['id'])) {
                try {
                    $chk_u = $pdo->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
                    $chk_u->execute([(int)$me['id']]);
                    $val_u = $chk_u->fetchColumn();
                    if ($val_u !== false && (int)$val_u > 0) {
                        $safe_staff_id = (int)$val_u;
                    }
                } catch (Exception $e) {}
            }
            if (!$safe_staff_id) {
                try {
                    $chk_alt = $pdo->prepare("SELECT id FROM users WHERE station_id = ? ORDER BY id ASC LIMIT 1");
                    $chk_alt->execute([$station_id]);
                    $val_alt = $chk_alt->fetchColumn();
                    if ($val_alt !== false && (int)$val_alt > 0) {
                        $safe_staff_id = (int)$val_alt;
                    }
                } catch (Exception $e) {}
            }

            // Dup-check: use item_name when item_id is NULL to avoid false negatives
            if ($safe_item_id !== null) {
                $dup = $pdo->prepare("SELECT COUNT(*) FROM stock_requests WHERE staff_id = ? AND item_id = ? AND status IN ('Pending', 'Pending Manager Review')");
                $dup->execute([$safe_staff_id, $safe_item_id]);
            } else {
                $dup = $pdo->prepare("SELECT COUNT(*) FROM stock_requests WHERE staff_id = ? AND item_name = ? AND item_id IS NULL AND status IN ('Pending', 'Pending Manager Review')");
                $dup->execute([$safe_staff_id, $item_name]);
            }
            if ((int)$dup->fetchColumn() > 0) {
                $skipped_items[] = $item_name;
                continue;
            }

            $stmt = $pdo->prepare("
                INSERT INTO stock_requests
                    (request_no, staff_id, station_id, item_id, item_sku, item_name, item_category,
                     current_stock, requested_quantity, remarks, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending Manager Review', NOW())
            ");
            $stmt->execute([
                $request_no, $safe_staff_id, $station_id, $safe_item_id, $sku,
                $item_name, $item_category, $current_stock,
                $requested_quantity, $remarks
            ]);
            $request_id = $pdo->lastInsertId();
            $inserted_ids[] = $request_id;

            $pdo->prepare("
                INSERT INTO stock_request_audit
                    (stock_request_id, action_type, performed_by, performed_by_role,
                     old_status, new_status, notes)
                VALUES (?, 'Created', ?, ?, NULL, 'Pending Manager Review', ?)
            ")->execute([
                $request_id, $safe_staff_id, $role,
                "Staff {$me['name']} requested {$item_name} (SKU: {$sku}) under request no {$request_no} — qty to be set by manager"
            ]);

            if (function_exists('log_activity')) {
                log_activity($pdo, $safe_staff_id, 'Create Stock Request',
                    "Request #{$request_id} | {$item_name} | By: {$me['name']} — qty to be set by manager");
            }

            try {
                $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                $detail = "Stock request created | Request No: {$request_no} | Item: {$item_name} (SKU: {$sku}) | Category: {$item_category} | Current stock: {$current_stock} | Requested qty: {$requested_quantity}" . ($remarks ? " | Remarks: {$remarks}" : '');
                $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at) VALUES (?, 'inventory', 'Create', ?, 'stock_requests', ?, 'Success', ?, ?, NOW())")
                    ->execute([$safe_staff_id, $detail, $request_id, $ip, $ua]);
            } catch (Exception $e) {}
        }

        if (empty($inserted_ids)) {
            $pdo->rollBack();
            $msg = 'No items were requested.';
            if (!empty($skipped_items)) {
                $msg .= ' The following items already have pending requests: ' . implode(', ', $skipped_items);
            }
            echo json_encode(['success' => false, 'message' => $msg]);
            return;
        }

        // ── Notify manager(s) — event-driven, deduplicated ──────
        foreach ($inserted_ids as $rid) {
            notify_manager(
                $pdo, $station_id,
                'info', 'stock_request', 'medium',
                'New Stock Request',
                "Stock request {$request_no} submitted by {$me['name']}. Review required.",
                "stock_req_submitted_{$rid}",
                'manager_inventory_stock_requests.php?id=' . $rid,
                'stock_request', $rid
            );
        }

        $pdo->commit();
        
        $msg = 'Stock request submitted successfully.';
        if (!empty($skipped_items)) {
            $msg .= ' Note: Some items (' . implode(', ', $skipped_items) . ') were skipped because they already have pending requests.';
        }
        
        echo json_encode([
            'success'            => true,
            'message'            => $msg,
            'request_no'         => $request_no,
            'inserted_count'     => count($inserted_ids),
            'status'             => 'Pending Manager Review'
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function handle_my_requests($pdo, $me) {
    // Ensure both tables exist
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS fuel_stock_requests (
                id               INT AUTO_INCREMENT PRIMARY KEY,
                staff_id         INT NOT NULL,
                station_id       INT NOT NULL,
                fuel_type        VARCHAR(100) NOT NULL,
                current_level    DECIMAL(12,2) NOT NULL DEFAULT 0,
                capacity         DECIMAL(12,2) NOT NULL DEFAULT 0,
                stock_status     VARCHAR(30)  NOT NULL DEFAULT 'LOW',
                requested_liters DECIMAL(12,2) NOT NULL,
                remarks          TEXT,
                status           ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
                approved_liters  DECIMAL(12,2) NULL,
                manager_id       INT NULL,
                manager_notes    TEXT NULL,
                processed_at     TIMESTAMP NULL,
                created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Exception $ignored) {}

    $status_filter = $_GET['status']    ?? '';
    $date_from     = $_GET['date_from'] ?? '';
    $date_to       = $_GET['date_to']   ?? '';
    $item_type     = $_GET['item_type'] ?? '';
    $per_page      = max(1, min(100, (int)($_GET['per_page'] ?? 10)));
    $page          = max(1, (int)($_GET['page'] ?? 1));
    $offset        = ($page - 1) * $per_page;

    $where_merch  = ['sr.staff_id = ?'];
    $params_merch = [$me['id']];
    
    $where_fuel  = ['fsr.staff_id = ?'];
    $params_fuel = [$me['id']];

    if ($status_filter) {
        $where_merch[]  = 'sr.status = ?';
        $params_merch[] = $status_filter;
        
        $where_fuel[]  = 'fsr.status = ?';
        $params_fuel[] = $status_filter;
    }
    if ($date_from) {
        $where_merch[]  = 'DATE(sr.created_at) >= ?';
        $params_merch[] = $date_from;
        
        $where_fuel[]  = 'DATE(fsr.created_at) >= ?';
        $params_fuel[] = $date_from;
    }
    if ($date_to) {
        $where_merch[]  = 'DATE(sr.created_at) <= ?';
        $params_merch[] = $date_to;
        
        $where_fuel[]  = 'DATE(fsr.created_at) <= ?';
        $params_fuel[] = $date_to;
    }
    if ($item_type) {
        if (strtolower($item_type) === 'fuel') {
            $where_merch[] = '1 = 0';
        } else {
            $where_merch[] = 'sr.item_category = ?';
            $params_merch[] = $item_type;
            
            $where_fuel[] = '1 = 0';
        }
    }

    $whereSQL_merch = 'WHERE ' . implode(' AND ', $where_merch);
    $whereSQL_fuel  = 'WHERE ' . implode(' AND ', $where_fuel);

    $sql_count = "
        SELECT SUM(cnt) FROM (
            SELECT COUNT(*) AS cnt FROM stock_requests sr $whereSQL_merch
            UNION ALL
            SELECT COUNT(*) AS cnt FROM fuel_stock_requests fsr $whereSQL_fuel
        ) t
    ";

    $params_combined = array_merge($params_merch, $params_fuel);

    $countStmt = $pdo->prepare($sql_count);
    $countStmt->execute($params_combined);
    $total = (int)$countStmt->fetchColumn();

    $sql_data = "
        SELECT 
            sr.id, sr.staff_id, sr.station_id, sr.item_id, sr.item_sku, sr.item_name, sr.item_category,
            sr.current_stock, sr.requested_quantity, sr.approved_quantity, sr.remarks, sr.status,
            sr.manager_id, sr.manager_notes, sr.processed_at, sr.created_at, sr.updated_at,
            m.name AS manager_name
        FROM stock_requests sr
        LEFT JOIN users m ON sr.manager_id = m.id
        $whereSQL_merch
        
        UNION ALL
        
        SELECT 
            fsr.id, fsr.staff_id, fsr.station_id, 0 AS item_id, '—' AS item_sku, fsr.fuel_type AS item_name, 'Fuel' AS item_category,
            fsr.current_level AS current_stock, fsr.requested_liters AS requested_quantity, fsr.approved_liters AS approved_quantity, fsr.remarks, fsr.status,
            fsr.manager_id, fsr.manager_notes, fsr.processed_at, fsr.created_at, fsr.updated_at,
            m.name AS manager_name
        FROM fuel_stock_requests fsr
        LEFT JOIN users m ON fsr.manager_id = m.id
        $whereSQL_fuel
        
        ORDER BY created_at DESC
        LIMIT $per_page OFFSET $offset
    ";

    $stmt = $pdo->prepare($sql_data);
    $stmt->execute($params_combined);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_pages = $total > 0 ? (int)ceil($total / $per_page) : 1;

    echo json_encode([
        'success'     => true,
        'requests'    => $requests,
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $per_page,
        'total_pages' => $total_pages,
    ]);
}

function handle_get_requests($pdo, $me, $role, $station_id) {
    $status_filter = $_GET['status'] ?? '';
    $date_from     = $_GET['date_from'] ?? '';
    $date_to       = $_GET['date_to'] ?? '';

    $where  = [];
    $params = [];

    // Admin sees all stations; manager sees own station
    if (!in_array($role, ['admin', 'superadmin'])) {
        $where[]  = 'sr.station_id = ?';
        $params[] = $station_id;
    }

    if ($status_filter) {
        $where[]  = 'sr.status = ?';
        $params[] = $status_filter;
    }
    if ($date_from) {
        $where[]  = 'DATE(sr.created_at) >= ?';
        $params[] = $date_from;
    }
    if ($date_to) {
        $where[]  = 'DATE(sr.created_at) <= ?';
        $params[] = $date_to;
    }

    $sql = "
        SELECT sr.*,
               COALESCE(sr.purchase_request_id, '') AS purchase_request_id,
               COALESCE(u.name, 'Unknown Staff')  AS staff_name,
               m.name  AS manager_name,
               s.name  AS station_name,
               po.po_number,
               po.status        AS po_status,
               po.admin_finalized,
               po.admin_finalized_at,
               po.delivery_validated,
               po.delivery_validated_at,
               po.delivery_flag,
               po.stock_in_done,
               po.stock_in_at
        FROM stock_requests sr
        LEFT JOIN users u    ON sr.staff_id    = u.id
        LEFT JOIN users m ON sr.manager_id = m.id
        LEFT JOIN stations s ON sr.station_id = s.id
        LEFT JOIN purchase_orders po ON po.request_id = sr.id AND po.type = 'merch'
    ";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY sr.created_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['success' => true, 'requests' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function handle_approve($pdo, $me, $role, $station_id) {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $request_id        = (int)($input['request_id'] ?? 0);
    $approved_quantity = (int)($input['approved_quantity'] ?? 0);
    $manager_notes     = trim($input['manager_notes'] ?? '');

    if ($request_id <= 0 || $approved_quantity <= 0) {
        echo json_encode(['success' => false, 'message' => 'Request ID and approved quantity are required']); return;
    }

    // Fetch request — manager scoped to station
    $scope_sql = in_array($role, ['admin', 'superadmin'])
        ? "SELECT * FROM stock_requests WHERE id = ?"
        : "SELECT * FROM stock_requests WHERE id = ? AND station_id = ?";
    $scope_params = in_array($role, ['admin', 'superadmin'])
        ? [$request_id]
        : [$request_id, $station_id];

    $stmt = $pdo->prepare($scope_sql);
    $stmt->execute($scope_params);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$req) {
        echo json_encode(['success' => false, 'message' => 'Request not found']); return;
    }
    if (!in_array(strtolower($req['status']), ['pending', 'pending manager review'])) {
        echo json_encode(['success' => false, 'message' => 'Request is not pending']); return;
    }

    // Ensure purchase_request_id column exists BEFORE starting the transaction.
    // ALTER TABLE causes an implicit commit in MySQL/MariaDB, which would
    // silently end any open transaction and cause "There is no active transaction"
    // when rollBack() is later called on error.
    try {
        $pdo->exec("ALTER TABLE stock_requests ADD COLUMN IF NOT EXISTS purchase_request_id VARCHAR(50) NULL DEFAULT NULL");
    } catch (Exception $ignored) {}

    $pdo->beginTransaction();
    try {
        // Generate Purchase Request ID: PR-YYYYMMDD-XXXX
        $pr_id = 'PR-' . date('Ymd') . '-' . str_pad($request_id, 4, '0', STR_PAD_LEFT);

        // Update request → Forwarded to Admin
        $pdo->prepare("
            UPDATE stock_requests
            SET status               = 'Forwarded to Admin',
                approved_quantity    = ?,
                manager_id           = ?,
                manager_notes        = ?,
                purchase_request_id  = ?,
                processed_at         = NOW(),
                updated_at           = NOW()
            WHERE id = ?
        ")->execute([$approved_quantity, $me['id'], $manager_notes, $pr_id, $request_id]);

        // Auto-generate PO with status 'Pending Admin Validation'
        $po_number = 'PO-' . date('Ymd') . '-SR' . str_pad($request_id, 4, '0', STR_PAD_LEFT);
        
        // Resolve unit price — use the item from the request if item_id is NULL
        $product_id_for_po = (int)($req['item_id'] ?: 0);
        if ($product_id_for_po > 0) {
            $unit_price = sr_resolve_merch_unit_price($pdo, $product_id_for_po);
        } else {
            // Fallback: product is from `products` table (no FK reference)
            $unit_price = 0;
            try {
                $priceStmt = $pdo->prepare("SELECT COALESCE(price, cost, 0) FROM products WHERE name = ? LIMIT 1");
                $priceStmt->execute([$req['item_name']]);
                $unit_price = (float)($priceStmt->fetchColumn() ?: 0);
            } catch (Throwable $ignored) {}
        }
        
        $total_amount = round($unit_price * $approved_quantity, 2);
        $po_remarks   = "Auto-generated from Stock Request #{$request_id}. Purchase Request: {$pr_id}. Manager: {$me['name']}.";
        if ($manager_notes) $po_remarks .= " Notes: {$manager_notes}";

        // Check if PO already exists for this request
        $existing = $pdo->prepare("SELECT id, po_number FROM purchase_orders WHERE request_id = ?");
        $existing->execute([$request_id]);
        $existing_po = $existing->fetch(PDO::FETCH_ASSOC);
        if (!$existing_po) {
            $pdo->prepare("
                INSERT INTO purchase_orders
                    (request_id, product_name, quantity, unit_price, total_amount,
                     type, po_number, station_id, created_by, status, remarks,
                     created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, 'merch', ?, ?, ?, 'Pending Admin Validation', ?, NOW(), NOW())
            ")->execute([
                $request_id, $req['item_name'], $approved_quantity, $unit_price, $total_amount,
                $po_number, $req['station_id'], $me['id'], $po_remarks
            ]);
            $po_id = $pdo->lastInsertId();

            // Insert into purchase_order_items for data integrity
            $pdo->prepare("
                INSERT INTO purchase_order_items
                    (po_id, product_id, item_name, quantity, quantity_ordered, unit_price, total_price)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $po_id, $product_id_for_po, $req['item_name'], $approved_quantity, $approved_quantity, $unit_price, $total_amount
            ]);
        } else {
            $po_id = $existing_po['id'];
            // Update existing PO
            $pdo->prepare("
                UPDATE purchase_orders
                SET product_name = ?, quantity = ?, unit_price = ?, total_amount = ?,
                    status = 'Pending Admin Validation', remarks = ?, updated_at = NOW()
                WHERE request_id = ?
            ")->execute([$req['item_name'], $approved_quantity, $unit_price, $total_amount, $po_remarks, $request_id]);

            // Sync/update purchase_order_items
            $stmt_item = $pdo->prepare("SELECT id FROM purchase_order_items WHERE po_id = ? AND (product_id = ? OR (product_id IS NULL AND item_name = ?))");
            $stmt_item->execute([$po_id, $product_id_for_po, $req['item_name']]);
            $item_exists_id = $stmt_item->fetchColumn();
            if ($item_exists_id) {
                $pdo->prepare("
                    UPDATE purchase_order_items
                    SET quantity = ?, quantity_ordered = ?, unit_price = ?, total_price = ?
                    WHERE id = ?
                ")->execute([$approved_quantity, $approved_quantity, $unit_price, $total_amount, $item_exists_id]);
            } else {
                $pdo->prepare("
                    INSERT INTO purchase_order_items
                        (po_id, product_id, item_name, quantity, quantity_ordered, unit_price, total_price)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $po_id, $product_id_for_po, $req['item_name'], $approved_quantity, $approved_quantity, $unit_price, $total_amount
                ]);
            }
        }

        // Audit trail
        $audit_note = "Manager approved: qty={$approved_quantity}. Purchase Request ID: {$pr_id}. PO: {$po_number}. Status → Forwarded to Admin. Manager: {$me['name']}.";
        if ($manager_notes) $audit_note .= " Remarks: {$manager_notes}";
        $pdo->prepare("
            INSERT INTO stock_request_audit
                (stock_request_id, action_type, performed_by, performed_by_role,
                 old_status, new_status, notes)
            VALUES (?, 'Forwarded to Admin', ?, ?, 'Pending', 'Forwarded to Admin', ?)
        ")->execute([$request_id, $safe_staff_id, $role, $audit_note]);

        if (function_exists('log_activity')) {
            log_activity($pdo, $me['id'], 'Forward Purchase Request',
                "Request #{$request_id} | {$req['item_name']} | Qty: {$approved_quantity} | PR: {$pr_id} | PO: {$po_number} | By: {$me['name']}");
        }

        $pdo->commit();
        echo json_encode([
            'success'              => true,
            'message'              => "Request approved and forwarded to Admin. Purchase Request ID: {$pr_id}",
            'po_number'            => $po_number,
            'purchase_request_id'  => $pr_id
        ]);

        // ── Audit log ──
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $detail = "Stock request approved | Request #{$request_id} | Item: {$req['item_name']} | Approved qty: {$approved_quantity} | PR: {$pr_id} | PO: {$po_number}" . ($manager_notes ? " | Notes: {$manager_notes}" : '');
            $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at) VALUES (?, 'inventory', 'Approve', ?, 'stock_requests', ?, 'Success', ?, ?, NOW())")
                ->execute([$safe_staff_id, $detail, $request_id, $ip, $ua]);
        } catch (Exception $e) {}

        // ── Notify staff: their request was approved ─────────────────
        notify($pdo, (int)$req['staff_id'], 'staff', 'success', 'stock_request', 'medium',
            'Stock Request Approved',
            "Your stock request {$pr_id} for {$req['item_name']} (Qty: {$approved_quantity}) has been approved." . ($manager_notes ? " Notes: {$manager_notes}" : ''),
            "stock_req_approved_{$request_id}",
            'staff_stock_requests.php?id=' . $request_id,
            'stock_request', $request_id
        );
        // ── Notify admin: PO needs action ────────────────────────────
        notify_admin($pdo, 'info', 'stock_request', 'medium',
            'Purchase Order Requires Action',
            "Stock Request {$pr_id} approved by manager. PO {$po_number} requires Admin review for {$req['item_name']}.",
            "stock_req_po_{$request_id}",
            'admin_approve_stock_requests.php?id=' . $request_id,
            'stock_request', $request_id, (int)$req['station_id']
        );
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function handle_reject($pdo, $me, $role, $station_id) {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $request_id    = (int)($input['request_id'] ?? 0);
    $manager_notes = trim($input['manager_notes'] ?? '');

    if ($request_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Request ID required']); return;
    }
    if (empty($manager_notes)) {
        echo json_encode(['success' => false, 'message' => 'Rejection reason is required']); return;
    }

    $scope_sql = in_array($role, ['admin', 'superadmin'])
        ? "SELECT * FROM stock_requests WHERE id = ?"
        : "SELECT * FROM stock_requests WHERE id = ? AND station_id = ?";
    $scope_params = in_array($role, ['admin', 'superadmin'])
        ? [$request_id]
        : [$request_id, $station_id];

    $stmt = $pdo->prepare($scope_sql);
    $stmt->execute($scope_params);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$req) {
        echo json_encode(['success' => false, 'message' => 'Request not found']); return;
    }
    if (!in_array(strtolower($req['status']), ['pending', 'pending manager review'])) {
        echo json_encode(['success' => false, 'message' => 'Request is not pending']); return;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            UPDATE stock_requests
            SET status       = 'Rejected',
                manager_id   = ?,
                manager_notes = ?,
                processed_at = NOW(),
                updated_at   = NOW()
            WHERE id = ?
        ")->execute([$me['id'], $manager_notes, $request_id]);

        $pdo->prepare("
            INSERT INTO stock_request_audit
                (stock_request_id, action_type, performed_by, performed_by_role,
                 old_status, new_status, notes)
            VALUES (?, 'Rejected', ?, ?, 'Pending', 'Rejected', ?)
        ")->execute([$request_id, $safe_staff_id, $role,
            "Rejected by {$me['name']}. Reason: {$manager_notes}"]);

        if (function_exists('log_activity')) {
            log_activity($pdo, $me['id'], 'Reject Stock Request',
                "Request #{$request_id} | {$req['item_name']} | Reason: {$manager_notes} | By: {$me['name']}");
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Request rejected successfully']);

        // ── Audit log ──
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $detail = "Stock request rejected | Request #{$request_id} | Item: {$req['item_name']} | Reason: {$manager_notes}";
            $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at) VALUES (?, 'inventory', 'Reject', ?, 'stock_requests', ?, 'Success', ?, ?, NOW())")
                ->execute([$safe_staff_id, $detail, $request_id, $ip, $ua]);
        } catch (Exception $e) {}

        // ── Notify staff: their request was rejected ──────────────────
        notify($pdo, (int)$req['staff_id'], 'staff', 'error', 'stock_request', 'medium',
            'Stock Request Rejected',
            "Your stock request #{$request_id} for {$req['item_name']} was rejected." . ($manager_notes ? " Reason: {$manager_notes}" : ''),
            "stock_req_rejected_{$request_id}",
            'staff_stock_requests.php?id=' . $request_id,
            'stock_request', $request_id
        );
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function handle_audit_trail($pdo, $me, $role, $station_id) {
    $date_from = $_GET['date_from'] ?? '';
    $date_to   = $_GET['date_to']   ?? '';
    $status    = $_GET['status']    ?? '';

    $where  = [];
    $params = [];

    if (!in_array($role, ['superadmin'])) {
        $where[]  = 'sr.station_id = ?';
        $params[] = $station_id;
    }
    if ($date_from) { $where[] = 'DATE(sra.created_at) >= ?'; $params[] = $date_from; }
    if ($date_to)   { $where[] = 'DATE(sra.created_at) <= ?'; $params[] = $date_to;   }
    if ($status)    { $where[] = 'sra.new_status = ?';        $params[] = $status;    }

    $sql = "
        SELECT sra.*,
               sr.item_name, sr.item_sku, sr.item_category,
               sr.requested_quantity, sr.approved_quantity,
               sr.remarks AS staff_remarks,
               COALESCE(u.name, 'Unknown User')  AS performed_by_name,
               COALESCE(st.name, 'Unknown Staff') AS staff_name,
               s.name  AS station_name
        FROM stock_request_audit sra
        JOIN stock_requests sr ON sra.stock_request_id = sr.id
        LEFT JOIN users u  ON sra.performed_by = u.id
        LEFT JOIN users st ON sr.staff_id = st.id
        LEFT JOIN stations s ON sr.station_id = s.id
    ";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY sra.created_at DESC LIMIT 500';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['success' => true, 'audit_trail' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function handle_export_csv($pdo, $me, $role, $station_id) {
    // Reuse audit trail data for export
    $date_from = $_GET['date_from'] ?? '';
    $date_to   = $_GET['date_to']   ?? '';
    $status    = $_GET['status']    ?? '';

    $where  = [];
    $params = [];

    if (!in_array($role, ['superadmin'])) {
        $where[]  = 'sr.station_id = ?';
        $params[] = $station_id;
    }
    if ($date_from) { $where[] = 'DATE(sr.created_at) >= ?'; $params[] = $date_from; }
    if ($date_to)   { $where[] = 'DATE(sr.created_at) <= ?'; $params[] = $date_to;   }
    if ($status)    { $where[] = 'sr.status = ?';            $params[] = $status;    }

    $sql = "
        SELECT sr.id, sr.created_at, s.name AS station_name,
               COALESCE(u.name, 'Unknown Staff') AS staff_name, sr.item_name, sr.item_sku, sr.item_category,
               sr.current_stock, sr.requested_quantity, sr.approved_quantity,
               sr.status, m.name AS manager_name, sr.manager_notes, sr.remarks,
               sr.processed_at
        FROM stock_requests sr
        LEFT JOIN users u ON sr.staff_id = u.id
        LEFT JOIN users m ON sr.manager_id = m.id
        LEFT JOIN stations s ON sr.station_id = s.id
    ";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY sr.created_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Output CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="stock_requests_' . date('Ymd_His') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['#', 'Date', 'Station', 'Staff', 'Product', 'SKU', 'Category',
                   'Current Stock', 'Qty Requested', 'Qty Approved', 'Status',
                   'Manager', 'Manager Notes', 'Staff Remarks', 'Processed At']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'],
            $r['created_at'],
            $r['station_name'] ?? '',
            $r['staff_name'],
            $r['item_name'],
            $r['item_sku'],
            $r['item_category'],
            $r['current_stock'],
            $r['requested_quantity'],
            $r['approved_quantity'] ?? '',
            $r['status'],
            $r['manager_name'] ?? '',
            $r['manager_notes'] ?? '',
            $r['remarks'] ?? '',
            $r['processed_at'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}
