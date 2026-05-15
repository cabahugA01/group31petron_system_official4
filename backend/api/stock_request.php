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

function handle_create($pdo, $me, $role, $station_id) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON input']); return;
    }

    $item_id            = (int)($input['item_id'] ?? 0);
    $sku                = trim($input['sku'] ?? '');
    $item_name          = trim($input['item_name'] ?? '');
    $item_category      = trim($input['item_category'] ?? '');
    $current_stock      = (int)($input['current_stock'] ?? 0);
    $requested_quantity = (int)($input['requested_quantity'] ?? 0);
    $remarks            = trim($input['remarks'] ?? '');

    if ($item_id <= 0 || empty($item_name)) {
        echo json_encode(['success' => false, 'message' => 'Item is required']); return;
    }

    // Verify item exists
    $stmt = $pdo->prepare("SELECT id, product_name, category FROM inventory_products WHERE id = ? AND category != 'Fuel'");
    $stmt->execute([$item_id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        echo json_encode(['success' => false, 'message' => 'Item not found in inventory']); return;
    }

    // Check for duplicate pending request
    $dup = $pdo->prepare("SELECT COUNT(*) FROM stock_requests WHERE staff_id = ? AND item_id = ? AND status = 'Pending'");
    $dup->execute([$me['id'], $item_id]);
    if ((int)$dup->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'You already have a pending request for this item']); return;
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO stock_requests
                (staff_id, station_id, item_id, item_sku, item_name, item_category,
                 current_stock, requested_quantity, remarks, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())
        ");
        $stmt->execute([
            $me['id'], $station_id, $item_id, $sku,
            $item_name, $item_category, $current_stock,
            $requested_quantity, $remarks
        ]);
        $request_id = $pdo->lastInsertId();

        // Audit trail
        $pdo->prepare("
            INSERT INTO stock_request_audit
                (stock_request_id, action_type, performed_by, performed_by_role,
                 old_status, new_status, notes)
            VALUES (?, 'Created', ?, ?, NULL, 'Pending', ?)
        ")->execute([
            $request_id, $me['id'], $role,
            "Staff {$me['name']} requested {$item_name} (SKU: {$sku}) — qty to be set by manager"
        ]);

        // Activity log
        if (function_exists('log_activity')) {
            log_activity($pdo, $me['id'], 'Create Stock Request',
                "Request #{$request_id} | {$item_name} | By: {$me['name']} — qty to be set by manager");
        }

        $pdo->commit();
        echo json_encode([
            'success'            => true,
            'message'            => 'Stock request submitted successfully',
            'request_id'         => $request_id,
            'item_name'          => $item_name,
            'requested_quantity' => $requested_quantity,
            'status'             => 'Pending'
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function handle_my_requests($pdo, $me) {
    $stmt = $pdo->prepare("
        SELECT sr.*, m.name AS manager_name
        FROM stock_requests sr
        LEFT JOIN users m ON sr.manager_id = m.id
        WHERE sr.staff_id = ?
        ORDER BY sr.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$me['id']]);
    echo json_encode(['success' => true, 'requests' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
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
               u.name  AS staff_name,
               m.name  AS manager_name,
               s.name  AS station_name
        FROM stock_requests sr
        JOIN users u    ON sr.staff_id    = u.id
        LEFT JOIN users m ON sr.manager_id = m.id
        LEFT JOIN stations s ON sr.station_id = s.id
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
    if (strtolower($req['status']) !== 'pending') {
        echo json_encode(['success' => false, 'message' => 'Request is not pending']); return;
    }

    $pdo->beginTransaction();
    try {
        // Generate Purchase Request ID: PR-YYYYMMDD-XXXX
        $pr_id = 'PR-' . date('Ymd') . '-' . str_pad($request_id, 4, '0', STR_PAD_LEFT);

        // Ensure purchase_request_id column exists (add if missing)
        try {
            $pdo->exec("ALTER TABLE stock_requests ADD COLUMN IF NOT EXISTS purchase_request_id VARCHAR(50) NULL DEFAULT NULL");
        } catch (Exception $ignored) {}

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
        $po_number    = 'PO-' . date('Ymd') . '-SR' . str_pad($request_id, 4, '0', STR_PAD_LEFT);
        $ip_stmt      = $pdo->prepare("SELECT unit_price FROM inventory_products WHERE id = ?");
        $ip_stmt->execute([$req['item_id']]);
        $unit_price   = (float)($ip_stmt->fetchColumn() ?: 0);
        $total_amount = round($unit_price * $approved_quantity, 2);
        $po_remarks   = "Auto-generated from Stock Request #{$request_id}. Purchase Request: {$pr_id}. Manager: {$me['name']}.";
        if ($manager_notes) $po_remarks .= " Notes: {$manager_notes}";

        // Check if PO already exists for this request
        $existing = $pdo->prepare("SELECT id FROM purchase_orders WHERE request_id = ?");
        $existing->execute([$request_id]);
        if (!$existing->fetch()) {
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
        } else {
            // Update existing PO
            $pdo->prepare("
                UPDATE purchase_orders
                SET quantity = ?, unit_price = ?, total_amount = ?,
                    status = 'Pending Admin Validation', remarks = ?, updated_at = NOW()
                WHERE request_id = ?
            ")->execute([$approved_quantity, $unit_price, $total_amount, $po_remarks, $request_id]);
        }

        // Audit trail
        $audit_note = "Manager approved: qty={$approved_quantity}. Purchase Request ID: {$pr_id}. PO: {$po_number}. Status → Forwarded to Admin. Manager: {$me['name']}.";
        if ($manager_notes) $audit_note .= " Remarks: {$manager_notes}";
        $pdo->prepare("
            INSERT INTO stock_request_audit
                (stock_request_id, action_type, performed_by, performed_by_role,
                 old_status, new_status, notes)
            VALUES (?, 'Forwarded to Admin', ?, ?, 'Pending', 'Forwarded to Admin', ?)
        ")->execute([$request_id, $me['id'], $role, $audit_note]);

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
    } catch (Exception $e) {
        $pdo->rollBack();
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
    if (strtolower($req['status']) !== 'pending') {
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
        ")->execute([$request_id, $me['id'], $role,
            "Rejected by {$me['name']}. Reason: {$manager_notes}"]);

        if (function_exists('log_activity')) {
            log_activity($pdo, $me['id'], 'Reject Stock Request',
                "Request #{$request_id} | {$req['item_name']} | Reason: {$manager_notes} | By: {$me['name']}");
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Request rejected successfully']);
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
               u.name  AS performed_by_name,
               st.name AS staff_name,
               s.name  AS station_name
        FROM stock_request_audit sra
        JOIN stock_requests sr ON sra.stock_request_id = sr.id
        JOIN users u  ON sra.performed_by = u.id
        JOIN users st ON sr.staff_id = st.id
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
               u.name AS staff_name, sr.item_name, sr.item_sku, sr.item_category,
               sr.current_stock, sr.requested_quantity, sr.approved_quantity,
               sr.status, m.name AS manager_name, sr.manager_notes, sr.remarks,
               sr.processed_at
        FROM stock_requests sr
        JOIN users u ON sr.staff_id = u.id
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
