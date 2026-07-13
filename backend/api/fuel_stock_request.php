<?php
/**
 * Fuel Stock Request API
 * Handles staff fuel stock requests, manager approval/rejection, and audit trail.
 * Endpoint: backend/api/fuel_stock_request.php
 */
require_once '../lib.php';
require_once '../../public/db_connect.php';

header('Content-Type: application/json');

$me = current_user();
if (!$me) {
    echo json_encode(['success' => false, 'message' => 'User not authenticated']);
    exit;
}

$role       = role_key($me['role'] ?? 'staff');
$station_id = user_station_id();
$method     = $_SERVER['REQUEST_METHOD'];
$action     = $_GET['action'] ?? '';

// Ensure table exists on first use
ensure_fuel_stock_requests_table($pdo);

try {
    switch ($action) {

        // ── Staff: get low/critical/out-of-stock fuel types ──────────────────
        case 'get_low_stock':
            if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
                echo json_encode(['success' => false, 'message' => 'Access denied']); exit;
            }
            handle_get_low_stock($pdo, $station_id);
            break;

        // ── Staff: submit a fuel stock request ───────────────────────────────
        case 'create':
            if (!in_array($role, ['staff', 'cashier', 'pump_attendant', 'manager', 'admin', 'superadmin'])) {
                echo json_encode(['success' => false, 'message' => 'Access denied']); exit;
            }
            if ($method !== 'POST') {
                echo json_encode(['success' => false, 'message' => 'POST required']); exit;
            }
            handle_create($pdo, $me, $role, $station_id);
            break;

        // ── Staff: list own fuel requests ─────────────────────────────────────
        case 'my_requests':
            if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
                echo json_encode(['success' => false, 'message' => 'Access denied']); exit;
            }
            handle_my_requests($pdo, $me, $station_id);
            break;

        // ── Manager: list station fuel requests ───────────────────────────────
        case 'get_requests':
            if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
                echo json_encode(['success' => false, 'message' => 'Access denied']); exit;
            }
            handle_get_requests($pdo, $me, $role, $station_id);
            break;

        // ── Manager: approve ──────────────────────────────────────────────────
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

        // ── Manager/Admin: audit trail ────────────────────────────────────────
        case 'audit_trail':
            if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
                echo json_encode(['success' => false, 'message' => 'Access denied']); exit;
            }
            handle_audit_trail($pdo, $me, $role, $station_id);
            break;

        // ── Manager/Admin: export CSV ─────────────────────────────────────────
        case 'export_csv':
            if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
                echo json_encode(['success' => false, 'message' => 'Access denied']); exit;
            }
            handle_export_csv($pdo, $me, $role, $station_id);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log("Fuel Stock Request API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

// ─────────────────────────────────────────────────────────────────────────────
// TABLE BOOTSTRAP
// ─────────────────────────────────────────────────────────────────────────────

function ensure_fuel_stock_requests_table($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS fuel_stock_requests (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            request_no       VARCHAR(50) NULL DEFAULT NULL,
            staff_id         INT NOT NULL,
            station_id       INT NOT NULL,
            fuel_type        VARCHAR(100) NOT NULL,
            current_level    DECIMAL(12,2) NOT NULL DEFAULT 0,
            capacity         DECIMAL(12,2) NOT NULL DEFAULT 0,
            stock_status     VARCHAR(30)  NOT NULL DEFAULT 'LOW',
            requested_liters DECIMAL(12,2) NOT NULL,
            remarks          TEXT,
            status           VARCHAR(50) DEFAULT 'Pending Manager Review',
            approved_liters  DECIMAL(12,2) NULL,
            manager_id       INT NULL,
            manager_notes    TEXT NULL,
            processed_at     TIMESTAMP NULL,
            created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_staff_id   (staff_id),
            INDEX idx_station_id (station_id),
            INDEX idx_manager_id (manager_id),
            INDEX idx_status     (status),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS fuel_stock_request_audit (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            request_id       INT NOT NULL,
            action_type      VARCHAR(50) NOT NULL,
            performed_by     INT NOT NULL,
            performed_by_role VARCHAR(50) NOT NULL,
            old_status       VARCHAR(30) NULL,
            new_status       VARCHAR(30) NULL,
            notes            TEXT,
            created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_request_id (request_id),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

// ─────────────────────────────────────────────────────────────────────────────
// HANDLERS
// ─────────────────────────────────────────────────────────────────────────────

function handle_get_low_stock($pdo, $station_id) {
    $stmt = $pdo->prepare("
        SELECT
            ip.product_name                                          AS fuel_type,
            COALESCE(fi.current_level, fi.current_stock, ip.stock, 0) AS current_level,
            COALESCE(fi.capacity, 20000.00)                          AS capacity
        FROM inventory_products ip
        LEFT JOIN fuel_inventory fi
               ON ip.product_name = fi.fuel_type AND fi.station_id = ?
        WHERE ip.category = 'Fuel'
        ORDER BY ip.product_name
    ");
    $stmt->execute([$station_id]);
    $fuels = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach ($fuels as $f) {
        $level = (float)$f['current_level'];
        $cap   = (float)($f['capacity'] ?? 0);
        $pct   = $cap > 0 ? ($level / $cap) * 100 : 0;

        if ($cap == 14000)    { $crit = 5000; $low = 7000; }
        elseif ($cap == 7000) { $crit = 1000; $low = 2000; }
        else                  { $crit = $cap * 0.10; $low = $cap * 0.20; }

        if ($level <= 0)         { $status = 'OUT OF STOCK'; }
        elseif ($level <= $crit) { $status = 'CRITICAL'; }
        elseif ($level <= $low)  { $status = 'LOW'; }
        else                     { $status = 'AVAILABLE'; }

        $result[] = [
            'fuel_type'     => $f['fuel_type'],
            'current_level' => $level,
            'capacity'      => $cap,
            'fill_pct'      => round($pct, 1),
            'status'        => $status,
            'is_low'        => in_array($status, ['OUT OF STOCK', 'CRITICAL', 'LOW']),
        ];
    }

    echo json_encode(['success' => true, 'fuels' => $result]);
}

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
            'fuel_type'        => trim($input['fuel_type']        ?? ''),
            'current_level'    => (float)($input['current_level']    ?? 0),
            'capacity'         => (float)($input['capacity']         ?? 0),
            'stock_status'     => trim($input['stock_status']     ?? 'LOW'),
            'requested_liters' => (float)($input['requested_liters'] ?? 0),
        ];
    }

    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'No items specified']); return;
    }

    $request_no = get_next_request_no($pdo, 'fuel_stock_requests', 'PR');

    $pdo->beginTransaction();
    try {
        $inserted_ids = [];
        $skipped_items = [];

        foreach ($items as $item) {
            $fuel_type        = trim($item['fuel_type']        ?? '');
            $current_level    = (float)($item['current_level']    ?? 0);
            $capacity         = (float)($item['capacity']         ?? 0);
            $stock_status     = trim($item['stock_status']     ?? 'LOW');
            $requested_liters = (float)($item['requested_liters'] ?? 0);

            if (in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
                $requested_liters = 0.0;
            }

            if (empty($fuel_type)) {
                continue;
            }

            $dup = $pdo->prepare("
                SELECT COUNT(*) FROM fuel_stock_requests
                WHERE staff_id = ? AND station_id = ? AND fuel_type = ? AND status IN ('Pending', 'Pending Manager Review')
            ");
            $dup->execute([$me['id'], $station_id, $fuel_type]);
            if ((int)$dup->fetchColumn() > 0) {
                $skipped_items[] = $fuel_type;
                continue;
            }

            $stmt = $pdo->prepare("
                INSERT INTO fuel_stock_requests
                    (request_no, staff_id, station_id, fuel_type, current_level, capacity,
                     stock_status, requested_liters, remarks, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending Manager Review', NOW())
            ");
            $stmt->execute([
                $request_no, $me['id'], $station_id, $fuel_type,
                $current_level, $capacity, $stock_status,
                $requested_liters, $remarks
            ]);
            $request_id = $pdo->lastInsertId();
            $inserted_ids[] = $request_id;

            $pdo->prepare("
                INSERT INTO fuel_stock_request_audit
                    (request_id, action_type, performed_by, performed_by_role,
                     old_status, new_status, notes)
                VALUES (?, 'Created', ?, ?, NULL, 'Pending Manager Review', ?)
            ")->execute([
                $request_id, $me['id'], $role,
                "Staff {$me['name']} requested {$fuel_type} (Status: {$stock_status}) under request no {$request_no} — qty to be set by manager"
            ]);

            if (function_exists('log_activity')) {
                log_activity($pdo, $me['id'], 'Create Fuel Stock Request',
                    "Request #{$request_id} | {$fuel_type} | By: {$me['name']} — qty to be set by manager");
            }
        }

        if (empty($inserted_ids)) {
            $pdo->rollBack();
            $msg = 'No fuel types were requested.';
            if (!empty($skipped_items)) {
                $msg .= ' The following fuel types already have pending requests: ' . implode(', ', $skipped_items);
            }
            echo json_encode(['success' => false, 'message' => $msg]);
            return;
        }

        $managers = [];
        try {
            $m_stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'manager' AND station_id = ? AND status = 'Active'");
            $m_stmt->execute([$station_id]);
            $managers = $m_stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {}

        $notif_stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, message, event_type, severity, redirect_url, created_at)
            VALUES (?, 'info', 'New Bulk Fuel Stock Request', ?, 'stock_request', 'medium', 'manager_stock_request_review.php?tab=pending_requests', NOW())
        ");
        foreach ($managers as $mgr_id) {
            $notif_stmt->execute([$mgr_id, "New fuel stock request {$request_no} submitted by {$me['name']}. Manager review required."]);
        }

        $pdo->commit();
        
        $msg = 'Fuel stock request submitted successfully.';
        if (!empty($skipped_items)) {
            $msg .= ' Note: Some fuel types (' . implode(', ', $skipped_items) . ') were skipped because they already have pending requests.';
        }

        echo json_encode([
            'success'          => true,
            'message'          => $msg,
            'request_no'       => $request_no,
            'inserted_count'   => count($inserted_ids),
            'status'           => 'Pending Manager Review'
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function handle_my_requests($pdo, $me, $station_id) {
    $stmt = $pdo->prepare("
        SELECT fsr.*, u.name AS staff_name, m.name AS manager_name
        FROM fuel_stock_requests fsr
        JOIN users u ON fsr.staff_id = u.id
        LEFT JOIN users m ON fsr.manager_id = m.id
        WHERE fsr.staff_id = ? AND fsr.station_id = ?
        ORDER BY fsr.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$me['id'], $station_id]);
    echo json_encode(['success' => true, 'requests' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function handle_get_requests($pdo, $me, $role, $station_id) {
    $status_filter = $_GET['status']    ?? '';
    $date_from     = $_GET['date_from'] ?? '';
    $date_to       = $_GET['date_to']   ?? '';

    $where  = [];
    $params = [];

    if (!in_array($role, ['admin', 'superadmin'])) {
        $where[]  = 'fsr.station_id = ?';
        $params[] = $station_id;
    }
    if ($status_filter) { $where[] = 'fsr.status = ?';                $params[] = $status_filter; }
    if ($date_from)     { $where[] = 'DATE(fsr.created_at) >= ?';     $params[] = $date_from; }
    if ($date_to)       { $where[] = 'DATE(fsr.created_at) <= ?';     $params[] = $date_to; }

    $sql = "
        SELECT fsr.*,
               u.name AS staff_name,
               m.name AS manager_name,
               s.name AS station_name
        FROM fuel_stock_requests fsr
        JOIN users u ON fsr.staff_id = u.id
        LEFT JOIN users m ON fsr.manager_id = m.id
        LEFT JOIN stations s ON fsr.station_id = s.id
    ";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY fsr.created_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['success' => true, 'requests' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function handle_approve($pdo, $me, $role, $station_id) {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $request_id      = (int)($input['request_id']      ?? 0);
    $approved_liters = (float)($input['approved_liters'] ?? 0);
    $manager_notes   = trim($input['manager_notes']   ?? '');

    if ($request_id <= 0 || $approved_liters <= 0) {
        echo json_encode(['success' => false, 'message' => 'Request ID and approved liters are required']); return;
    }

    $scope_sql    = in_array($role, ['admin', 'superadmin'])
        ? "SELECT * FROM fuel_stock_requests WHERE id = ?"
        : "SELECT * FROM fuel_stock_requests WHERE id = ? AND station_id = ?";
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
            UPDATE fuel_stock_requests
            SET status          = 'Approved',
                approved_liters = ?,
                manager_id      = ?,
                manager_notes   = ?,
                processed_at    = NOW(),
                updated_at      = NOW()
            WHERE id = ?
        ")->execute([$approved_liters, $me['id'], $manager_notes, $request_id]);

        // Audit trail
        $audit_note = "Approved: {$req['requested_liters']} L → {$approved_liters} L of {$req['fuel_type']}. Manager: {$me['name']}.";
        if ($manager_notes) $audit_note .= " Notes: {$manager_notes}";

        $pdo->prepare("
            INSERT INTO fuel_stock_request_audit
                (request_id, action_type, performed_by, performed_by_role,
                 old_status, new_status, notes)
            VALUES (?, 'Approved', ?, ?, 'Pending', 'Approved', ?)
        ")->execute([$request_id, $me['id'], $role, $audit_note]);

        // Log to main audit_trail table for manager_audit_trail.php visibility
        try {
            $pdo->prepare("
                INSERT INTO audit_trail
                    (transaction_id, manager_id, station_id, action_type, new_value, notes, created_at)
                VALUES (?, ?, ?, 'Approve Fuel Request', ?, ?, NOW())
            ")->execute([
                'FSR-' . $request_id,
                $me['id'],
                $req['station_id'],
                "Approved {$approved_liters} L of {$req['fuel_type']}",
                $audit_note
            ]);
        } catch (Exception $ignored) {}

        if (function_exists('log_activity')) {
            log_activity($pdo, $me['id'], 'Approve Fuel Stock Request',
                "Request #{$request_id} | {$req['fuel_type']} | {$approved_liters} L | By: {$me['name']}");
        }

        $pdo->commit();
        echo json_encode([
            'success'        => true,
            'message'        => "Fuel request approved. {$approved_liters} L of {$req['fuel_type']} confirmed.",
            'approved_liters'=> $approved_liters
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function handle_reject($pdo, $me, $role, $station_id) {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $request_id    = (int)($input['request_id']    ?? 0);
    $manager_notes = trim($input['manager_notes'] ?? '');

    if ($request_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Request ID required']); return;
    }
    if (empty($manager_notes)) {
        echo json_encode(['success' => false, 'message' => 'Rejection reason is required']); return;
    }

    $scope_sql    = in_array($role, ['admin', 'superadmin'])
        ? "SELECT * FROM fuel_stock_requests WHERE id = ?"
        : "SELECT * FROM fuel_stock_requests WHERE id = ? AND station_id = ?";
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
            UPDATE fuel_stock_requests
            SET status       = 'Rejected',
                manager_id   = ?,
                manager_notes = ?,
                processed_at = NOW(),
                updated_at   = NOW()
            WHERE id = ?
        ")->execute([$me['id'], $manager_notes, $request_id]);

        $audit_note = "Rejected by {$me['name']}. Reason: {$manager_notes}";
        $pdo->prepare("
            INSERT INTO fuel_stock_request_audit
                (request_id, action_type, performed_by, performed_by_role,
                 old_status, new_status, notes)
            VALUES (?, 'Rejected', ?, ?, 'Pending', 'Rejected', ?)
        ")->execute([$request_id, $me['id'], $role, $audit_note]);

        // Log to main audit_trail table
        try {
            $pdo->prepare("
                INSERT INTO audit_trail
                    (transaction_id, manager_id, station_id, action_type, new_value, notes, created_at)
                VALUES (?, ?, ?, 'Reject Fuel Request', ?, ?, NOW())
            ")->execute([
                'FSR-' . $request_id,
                $me['id'],
                $req['station_id'],
                "Rejected {$req['fuel_type']} request ({$req['requested_liters']} L)",
                $audit_note
            ]);
        } catch (Exception $ignored) {}

        if (function_exists('log_activity')) {
            log_activity($pdo, $me['id'], 'Reject Fuel Stock Request',
                "Request #{$request_id} | {$req['fuel_type']} | Reason: {$manager_notes} | By: {$me['name']}");
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Fuel request rejected successfully']);
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
        $where[]  = 'fsr.station_id = ?';
        $params[] = $station_id;
    }
    if ($date_from) { $where[] = 'DATE(fsra.created_at) >= ?'; $params[] = $date_from; }
    if ($date_to)   { $where[] = 'DATE(fsra.created_at) <= ?'; $params[] = $date_to;   }
    if ($status)    { $where[] = 'fsra.new_status = ?';        $params[] = $status;    }

    $sql = "
        SELECT fsra.*,
               fsr.fuel_type, fsr.requested_liters, fsr.approved_liters,
               fsr.stock_status, fsr.remarks AS staff_remarks,
               u.name  AS performed_by_name,
               st.name AS staff_name,
               s.name  AS station_name
        FROM fuel_stock_request_audit fsra
        JOIN fuel_stock_requests fsr ON fsra.request_id = fsr.id
        JOIN users u  ON fsra.performed_by = u.id
        JOIN users st ON fsr.staff_id = st.id
        LEFT JOIN stations s ON fsr.station_id = s.id
    ";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY fsra.created_at DESC LIMIT 500';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['success' => true, 'audit_trail' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function handle_export_csv($pdo, $me, $role, $station_id) {
    $date_from = $_GET['date_from'] ?? '';
    $date_to   = $_GET['date_to']   ?? '';
    $status    = $_GET['status']    ?? '';

    $where  = [];
    $params = [];

    if (!in_array($role, ['superadmin'])) {
        $where[]  = 'fsr.station_id = ?';
        $params[] = $station_id;
    }
    if ($date_from) { $where[] = 'DATE(fsr.created_at) >= ?'; $params[] = $date_from; }
    if ($date_to)   { $where[] = 'DATE(fsr.created_at) <= ?'; $params[] = $date_to;   }
    if ($status)    { $where[] = 'fsr.status = ?';            $params[] = $status;    }

    $sql = "
        SELECT fsr.id, fsr.created_at, s.name AS station_name,
               u.name AS staff_name, fsr.fuel_type, fsr.stock_status,
               fsr.current_level, fsr.capacity, fsr.requested_liters,
               fsr.approved_liters, fsr.status,
               m.name AS manager_name, fsr.manager_notes, fsr.remarks,
               fsr.processed_at
        FROM fuel_stock_requests fsr
        JOIN users u ON fsr.staff_id = u.id
        LEFT JOIN users m ON fsr.manager_id = m.id
        LEFT JOIN stations s ON fsr.station_id = s.id
    ";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY fsr.created_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="fuel_stock_requests_' . date('Ymd_His') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        '#', 'Date Submitted', 'Station', 'Staff Name', 'Fuel Type', 'Stock Status',
        'Current Level (L)', 'Capacity (L)', 'Qty Requested (L)', 'Qty Approved (L)',
        'Status', 'Manager', 'Manager Notes', 'Staff Remarks', 'Processed At'
    ]);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'],
            $r['created_at'],
            $r['station_name'] ?? '',
            $r['staff_name'],
            $r['fuel_type'],
            $r['stock_status'],
            $r['current_level'],
            $r['capacity'],
            $r['requested_liters'],
            $r['approved_liters'] ?? '',
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
