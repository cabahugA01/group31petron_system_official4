<?php
/**
 * Manager Merchandise Deliveries API
 * Actions: list, approve, reject, adjust, get_detail, pending_count
 * Roles: manager, admin, superadmin
 *
 * SOURCE TABLE: deliveries_oversight
 *   — Staff encodes deliveries into deliveries_oversight (status: 'Pending Manager Approval')
 *   — Manager reviews, approves, rejects, or adjusts from the same table
 *   — On approve: inventory_products stock is incremented automatically
 *   — On reject:  status set to 'Discrepancy' so staff can resubmit
 */

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

header('Content-Type: application/json');
ob_clean();

$me   = current_user();
$role = role_key($me['role'] ?? '');

if (!$me || !in_array($role, ['manager', 'admin', 'superadmin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Manager access required.']);
    exit;
}

$station_id = user_station_id();
$action     = $_GET['action'] ?? $_POST['action'] ?? '';

// ── Bootstrap deliveries_oversight table ─────────────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS deliveries_oversight (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            delivery_type   ENUM('fuel','merchandise') NOT NULL DEFAULT 'merchandise',
            delivery_ref    VARCHAR(100) NOT NULL DEFAULT '',
            supplier        VARCHAR(200) NOT NULL DEFAULT '',
            product         VARCHAR(200) NOT NULL DEFAULT '',
            quantity        DECIMAL(12,3) NOT NULL DEFAULT 0,
            unit            VARCHAR(30)  NOT NULL DEFAULT 'pcs',
            delivery_date   DATE         NOT NULL,
            dr_number       VARCHAR(100) DEFAULT NULL,
            encoded_by      INT          DEFAULT NULL,
            station_id      INT          NOT NULL,
            status          VARCHAR(60)  NOT NULL DEFAULT 'Pending Manager Approval',
            admin_id        INT          DEFAULT NULL,
            admin_action_at DATETIME     DEFAULT NULL,
            admin_notes     TEXT         DEFAULT NULL,
            remarks         TEXT         DEFAULT NULL,
            created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_station (station_id),
            INDEX idx_status  (status),
            INDEX idx_date    (delivery_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {}

// Add any missing columns from older installs
foreach (['remarks TEXT DEFAULT NULL', 'dr_number VARCHAR(100) DEFAULT NULL'] as $col_def) {
    try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN {$col_def}"); } catch (Exception $e) {}
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function try_log_merch(PDO $pdo, $user_id, $action, $details) {
    try { log_activity($pdo, $user_id, $action, $details, 'deliveries_oversight'); } catch (Exception $e) {}
}

/**
 * Map deliveries_oversight status values to display-friendly labels
 * and the canonical "bucket" used by the manager UI filters.
 */
function map_status_display(string $status): array {
    switch ($status) {
        case 'Pending Manager Approval':
        case 'Pending Manager Confirmation':
            return ['bucket' => 'Pending',  'label' => 'Pending'];
        case 'Confirmed':
            return ['bucket' => 'Approved', 'label' => 'Approved'];
        case 'Discrepancy':
            return ['bucket' => 'Rejected', 'label' => 'Rejected'];
        case 'Closed':
            return ['bucket' => 'Closed',   'label' => 'Closed'];
        default:
            return ['bucket' => $status,    'label' => $status];
    }
}

// ── Route ─────────────────────────────────────────────────────────────────────
try {
    switch ($action) {

        // ── GET: list deliveries ──────────────────────────────────────────────
        case 'list':
            $status_f   = trim($_GET['status']   ?? '');
            $supplier_f = trim($_GET['supplier'] ?? '');
            $start      = $_GET['start'] ?? date('Y-m-d', strtotime('-30 days'));
            $end        = $_GET['end']   ?? date('Y-m-d');

            $where  = "WHERE do2.station_id = ? AND do2.delivery_type = 'merchandise' AND do2.delivery_date BETWEEN ? AND ?";
            $params = [$station_id, $start, $end];

            // Map UI filter bucket → actual DB status values
            if ($status_f !== '') {
                if ($status_f === 'Pending') {
                    $where .= " AND do2.status IN ('Pending Manager Approval','Pending Manager Confirmation')";
                } elseif ($status_f === 'Approved') {
                    $where .= " AND do2.status = 'Confirmed'";
                } elseif ($status_f === 'Rejected') {
                    $where .= " AND do2.status = 'Discrepancy'";
                } elseif ($status_f === 'Closed') {
                    $where .= " AND do2.status = 'Closed'";
                }
            }
            if ($supplier_f !== '') {
                $where   .= " AND do2.supplier LIKE ?";
                $params[] = '%' . $supplier_f . '%';
            }

            $stmt = $pdo->prepare("
                SELECT
                    do2.id,
                    do2.delivery_ref,
                    do2.supplier        AS supplier_name,
                    do2.product         AS product_name,
                    do2.quantity        AS quantity_delivered,
                    do2.unit,
                    do2.delivery_date,
                    do2.dr_number,
                    COALESCE(do2.status, 'Pending Manager Approval') AS status,
                    do2.admin_notes     AS manager_reason,
                    do2.remarks,
                    do2.encoded_by,
                    do2.admin_id        AS manager_id,
                    do2.admin_action_at AS manager_action_at,
                    do2.created_at,
                    u_enc.name          AS encoded_by_name,
                    u_mgr.name          AS manager_name,
                    '' AS category
                FROM deliveries_oversight do2
                LEFT JOIN users u_enc ON do2.encoded_by = u_enc.id
                LEFT JOIN users u_mgr ON do2.admin_id   = u_mgr.id
                {$where}
                ORDER BY
                    FIELD(do2.status,
                        'Discrepancy',
                        'Pending Manager Approval',
                        'Pending Manager Confirmation',
                        'Confirmed',
                        'Closed'
                    ),
                    do2.delivery_date DESC
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Normalise status to UI buckets and count
            $counts = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0, 'Closed' => 0, 'total' => count($rows)];
            foreach ($rows as &$r) {
                $mapped   = map_status_display($r['status']);
                $r['display_status'] = $mapped['label'];   // display label for UI
                $r['status'] = $r['status'];   // keep original status for JavaScript checks
                if (isset($counts[$mapped['bucket']])) $counts[$mapped['bucket']]++;
            }
            unset($r);

            // Debug: Check if we have data
error_log('API Debug: Total rows found: ' . count($rows));
if (count($rows) > 0) {
    error_log('API Debug: First row status: ' . ($rows[0]['status'] ?? 'NULL'));
}

echo json_encode(['success' => true, 'data' => $rows, 'counts' => $counts]);
            break;

        // ── GET: pending badge count ──────────────────────────────────────────
        case 'pending_count':
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM deliveries_oversight
                WHERE station_id = ?
                  AND delivery_type = 'merchandise'
                  AND status IN ('Pending Manager Approval','Pending Manager Confirmation')
            ");
            $stmt->execute([$station_id]);
            echo json_encode(['success' => true, 'count' => (int)$stmt->fetchColumn()]);
            break;

        // ── GET: single delivery detail ───────────────────────────────────────
        case 'get_detail':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'message' => 'ID required']); break; }

            $stmt = $pdo->prepare("
                SELECT
                    do2.*,
                    do2.supplier        AS supplier_name,
                    do2.product         AS product_name,
                    do2.quantity        AS quantity_delivered,
                    do2.admin_notes     AS manager_reason,
                    do2.admin_id        AS manager_id,
                    do2.admin_action_at AS manager_action_at,
                    u_enc.name          AS encoded_by_name,
                    u_mgr.name          AS manager_name,
                    '' AS category
                FROM deliveries_oversight do2
                LEFT JOIN users u_enc ON do2.encoded_by = u_enc.id
                LEFT JOIN users u_mgr ON do2.admin_id   = u_mgr.id
                WHERE do2.id = ? AND do2.station_id = ?
            ");
            $stmt->execute([$id, $station_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) { echo json_encode(['success' => false, 'message' => 'Delivery not found']); break; }

            $mapped = map_status_display($row['status']);
            $row['status'] = $mapped['label'];

            echo json_encode(['success' => true, 'data' => $row]);
            break;

        // ── POST: approve delivery → auto-update inventory ────────────────────
        case 'approve':
            $input  = json_decode(file_get_contents('php://input'), true) ?? [];
            $id     = (int)($input['id'] ?? 0);
            $reason = trim($input['reason'] ?? '');

            if (!$id) { echo json_encode(['success' => false, 'message' => 'ID required']); break; }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT * FROM deliveries_oversight
                WHERE id = ? AND station_id = ? FOR UPDATE
            ");
            $stmt->execute([$id, $station_id]);
            $del = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$del) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Delivery not found']);
                break;
            }
            if (!in_array($del['status'], ['Pending Manager Approval', 'Pending Manager Confirmation'])) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Only Pending deliveries can be approved']);
                break;
            }

            // Update status to Confirmed
            $pdo->prepare("
                UPDATE deliveries_oversight
                SET status = 'Confirmed',
                    admin_id = ?,
                    admin_action_at = NOW(),
                    admin_notes = ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$me['id'], $reason ?: null, $id]);

            // Auto-update inventory stock
            try {
                $upd = $pdo->prepare("
                    UPDATE inventory_products
                    SET stock = stock + ?
                    WHERE product_name = ? AND station_id = ?
                    LIMIT 1
                ");
                $upd->execute([$del['quantity'], $del['product'], $station_id]);

                if ($upd->rowCount() === 0) {
                    // Fallback: try without station_id (global products)
                    $upd2 = $pdo->prepare("
                        UPDATE inventory_products
                        SET stock = stock + ?
                        WHERE product_name = ?
                        LIMIT 1
                    ");
                    $upd2->execute([$del['quantity'], $del['product']]);
                }
            } catch (Exception $e) {
                error_log("Inventory update failed for delivery #{$id}: " . $e->getMessage());
                // Non-fatal — delivery is still approved
            }

            $pdo->commit();

            try_log_merch($pdo, $me['id'], 'Approve Delivery',
                "Approved delivery #{$id} ref:{$del['delivery_ref']} ({$del['product']}, qty:{$del['quantity']}) — inventory updated");

            // ── Audit log ──
            try {
                $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                $detail = "Delivery approved | Ref: {$del['delivery_ref']} | Product: {$del['product']} | Qty: {$del['quantity']} | Supplier: {$del['supplier']}" . ($reason ? " | Notes: {$reason}" : '');
                $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at) VALUES (?, 'transaction', 'Approve', ?, 'deliveries', ?, 'Success', ?, ?, NOW())")
                    ->execute([$me['id'], $detail, $id, $ip, $ua]);
            } catch (Exception $e) {}

            echo json_encode(['success' => true, 'message' => 'Delivery approved and inventory updated.']);
            break;

        // ── POST: reject delivery → return to staff for correction ───────────
        case 'reject':
            $input  = json_decode(file_get_contents('php://input'), true) ?? [];
            $id     = (int)($input['id'] ?? 0);
            $reason = trim($input['reason'] ?? '');

            if (!$id)     { echo json_encode(['success' => false, 'message' => 'ID required']); break; }
            if (!$reason) { echo json_encode(['success' => false, 'message' => 'Rejection reason is required']); break; }

            $stmt = $pdo->prepare("
                SELECT * FROM deliveries_oversight WHERE id = ? AND station_id = ?
            ");
            $stmt->execute([$id, $station_id]);
            $del = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$del) { echo json_encode(['success' => false, 'message' => 'Delivery not found']); break; }
            if (!in_array($del['status'], ['Pending Manager Approval', 'Pending Manager Confirmation'])) {
                echo json_encode(['success' => false, 'message' => 'Only Pending deliveries can be rejected']);
                break;
            }

            // Set to 'Discrepancy' — staff sees this as "Rejected" and can resubmit
            $pdo->prepare("
                UPDATE deliveries_oversight
                SET status = 'Discrepancy',
                    admin_id = ?,
                    admin_action_at = NOW(),
                    admin_notes = ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$me['id'], $reason, $id]);

            try_log_merch($pdo, $me['id'], 'Reject Delivery',
                "Rejected delivery #{$id} ref:{$del['delivery_ref']} ({$del['product']}) — reason: {$reason}");

            // ── Audit log ──
            try {
                $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                $detail = "Delivery rejected | Ref: {$del['delivery_ref']} | Product: {$del['product']} | Qty: {$del['quantity']} | Reason: {$reason}";
                $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at) VALUES (?, 'transaction', 'Reject', ?, 'deliveries', ?, 'Success', ?, ?, NOW())")
                    ->execute([$me['id'], $detail, $id, $ip, $ua]);
            } catch (Exception $e) {}

            echo json_encode(['success' => true, 'message' => 'Delivery rejected. Staff can now resubmit with corrections.']);
            break;

        // ── POST: adjust delivery (minor corrections by manager) ─────────────
        case 'adjust':
            $input    = json_decode(file_get_contents('php://input'), true) ?? [];
            $id       = (int)($input['id'] ?? 0);
            $qty      = (float)($input['quantity_delivered'] ?? 0);
            $supplier = trim($input['supplier_name'] ?? '');
            $remarks  = trim($input['remarks'] ?? '');
            $reason   = trim($input['reason'] ?? '');

            if (!$id)      { echo json_encode(['success' => false, 'message' => 'ID required']); break; }
            if (!$reason)  { echo json_encode(['success' => false, 'message' => 'Adjustment reason is mandatory']); break; }
            if ($qty <= 0) { echo json_encode(['success' => false, 'message' => 'Quantity must be greater than 0']); break; }

            $stmt = $pdo->prepare("SELECT * FROM deliveries_oversight WHERE id = ? AND station_id = ?");
            $stmt->execute([$id, $station_id]);
            $del = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$del) { echo json_encode(['success' => false, 'message' => 'Delivery not found']); break; }

            $fields = ['quantity = ?', 'admin_id = ?', 'admin_action_at = NOW()', 'admin_notes = ?', 'updated_at = NOW()'];
            $vals   = [$qty, $me['id'], $reason];

            if ($supplier !== '') { $fields[] = 'supplier = ?'; $vals[] = $supplier; }
            if ($remarks  !== '') { $fields[] = 'remarks = ?';  $vals[] = $remarks; }

            $vals[] = $id;
            $pdo->prepare("UPDATE deliveries_oversight SET " . implode(', ', $fields) . " WHERE id = ?")
                ->execute($vals);

            try_log_merch($pdo, $me['id'], 'Adjust Delivery',
                "Adjusted delivery #{$id} ref:{$del['delivery_ref']} ({$del['product']}) qty:{$del['quantity']} → {$qty} — reason: {$reason}");

            echo json_encode(['success' => true, 'message' => 'Delivery adjusted successfully.']);
            break;

        // ── GET: export_excel ───────────────────────────────────────
        case 'export_excel':
            $status   = $_GET['status'] ?? '';
            $supplier = $_GET['supplier'] ?? '';
            $start    = $_GET['start'] ?? date('Y-m-d', strtotime('-30 days'));
            $end      = $_GET['end'] ?? date('Y-m-d');

            // Build WHERE clause
            $where = ["d.station_id = ?", "d.delivery_date BETWEEN ? AND ?"];
            $params = [$station_id, $start, $end];
            
            if ($status) {
                $where[] = "d.status = ?";
                $params[] = $status;
            }
            if ($supplier) {
                $where[] = "d.supplier LIKE ?";
                $params[] = "%$supplier%";
            }

            $sql = "
                SELECT 
                    d.delivery_ref,
                    d.supplier,
                    d.product,
                    d.quantity,
                    d.unit,
                    d.delivery_date,
                    d.status,
                    d.remarks,
                    u.username as encoded_by_name,
                    m.username as manager_name,
                    d.admin_action_at,
                    d.admin_notes
                FROM deliveries_oversight d
                LEFT JOIN users u ON d.encoded_by = u.id
                LEFT JOIN users m ON d.admin_id = m.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY d.delivery_date DESC, d.id DESC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Generate CSV
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="merchandise_deliveries_' . date('Y-m-d') . '.csv"');
            
            $output = fopen('php://output', 'w');
            
            // Header
            fputcsv($output, [
                'Delivery ID', 'Supplier', 'Product', 'Quantity', 'Unit', 
                'Delivery Date', 'Status', 'Remarks', 'Encoded By', 
                'Manager', 'Action Date', 'Manager Notes'
            ]);
            
            // Data rows
            foreach ($deliveries as $row) {
                fputcsv($output, [
                    $row['delivery_ref'],
                    $row['supplier'],
                    $row['product'],
                    $row['quantity'],
                    $row['unit'],
                    $row['delivery_date'],
                    $row['status'],
                    $row['remarks'],
                    $row['encoded_by_name'],
                    $row['manager_name'],
                    $row['admin_action_at'],
                    $row['admin_notes']
                ]);
            }
            
            fclose($output);
            exit;

        // ── GET: export_pdf ─────────────────────────────────────────
        case 'export_pdf':
            $status   = $_GET['status'] ?? '';
            $supplier = $_GET['supplier'] ?? '';
            $start    = $_GET['start'] ?? date('Y-m-d', strtotime('-30 days'));
            $end      = $_GET['end'] ?? date('Y-m-d');

            // Build WHERE clause (same as Excel export)
            $where = ["d.station_id = ?", "d.delivery_date BETWEEN ? AND ?"];
            $params = [$station_id, $start, $end];
            
            if ($status) {
                $where[] = "d.status = ?";
                $params[] = $status;
            }
            if ($supplier) {
                $where[] = "d.supplier LIKE ?";
                $params[] = "%$supplier%";
            }

            $sql = "
                SELECT 
                    d.delivery_ref,
                    d.supplier,
                    d.product,
                    d.quantity,
                    d.unit,
                    d.delivery_date,
                    d.status,
                    d.remarks,
                    u.username as encoded_by_name,
                    m.username as manager_name,
                    d.admin_action_at,
                    d.admin_notes
                FROM deliveries_oversight d
                LEFT JOIN users u ON d.encoded_by = u.id
                LEFT JOIN users m ON d.admin_id = m.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY d.delivery_date DESC, d.id DESC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Generate HTML for PDF
            $html = '
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="utf-8">
                <title>Merchandise Deliveries Report</title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 20px; }
                    h1 { color: #002F70; text-align: center; margin-bottom: 20px; }
                    table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
                    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                    th { background-color: #f2f2f2; font-weight: bold; }
                    .status-pending { background-color: #fff3cd; }
                    .status-approved { background-color: #d4edda; }
                    .status-rejected { background-color: #f8d7da; }
                    .footer { margin-top: 30px; text-align: center; color: #666; font-size: 12px; }
                </style>
            </head>
            <body>
                <h1>Merchandise Deliveries Report</h1>
                <p><strong>Station:</strong> #' . $station_id . '</p>
                <p><strong>Date Range:</strong> ' . $start . ' to ' . $end . '</p>
                <p><strong>Generated:</strong> ' . date('Y-m-d H:i:s') . '</p>
                
                <table>
                    <thead>
                        <tr>
                            <th>Delivery ID</th>
                            <th>Supplier</th>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>Unit</th>
                            <th>Delivery Date</th>
                            <th>Status</th>
                            <th>Remarks</th>
                            <th>Encoded By</th>
                            <th>Manager</th>
                            <th>Action Date</th>
                            <th>Manager Notes</th>
                        </tr>
                    </thead>
                    <tbody>';

            foreach ($deliveries as $row) {
                $statusClass = 'status-' . strtolower(str_replace(' ', '-', $row['status']));
                $html .= '
                        <tr>
                            <td>' . htmlspecialchars($row['delivery_ref']) . '</td>
                            <td>' . htmlspecialchars($row['supplier']) . '</td>
                            <td>' . htmlspecialchars($row['product']) . '</td>
                            <td>' . $row['quantity'] . '</td>
                            <td>' . htmlspecialchars($row['unit']) . '</td>
                            <td>' . $row['delivery_date'] . '</td>
                            <td class="' . $statusClass . '">' . htmlspecialchars($row['status']) . '</td>
                            <td>' . htmlspecialchars($row['remarks']) . '</td>
                            <td>' . htmlspecialchars($row['encoded_by_name']) . '</td>
                            <td>' . htmlspecialchars($row['manager_name']) . '</td>
                            <td>' . $row['admin_action_at'] . '</td>
                            <td>' . htmlspecialchars($row['admin_notes']) . '</td>
                        </tr>';
            }

            $html .= '
                    </tbody>
                </table>
                
                <div class="footer">
                    <p>Report generated from Petron Station Management System</p>
                </div>
            </body>
            </html>';

            // Output PDF
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="merchandise_deliveries_' . date('Y-m-d') . '.pdf"');
            
            // Use HTML to PDF conversion (you may need to install a library like DOMPDF)
            echo $html;
            exit;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Manager Merchandise Deliveries API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
