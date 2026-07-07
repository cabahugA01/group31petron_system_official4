<?php
/**
 * admin_po_ajax.php
 * AJAX endpoint for Purchase Order modals:
 *   - get_batch_items: fetch all items in a PO batch (for View PO modal)
 *   - get_pending_items: fetch all pending items for a date (for Finalize modal)
 */
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me   = current_user();
$role = role_key($me['role'] ?? '');

if (!in_array($role, ['admin','superadmin'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

header('Content-Type: application/json');

$action     = $_GET['action']   ?? '';
$po_type    = $_GET['type']     ?? 'merch';
$batch_id   = trim($_GET['batch_id'] ?? '');
$date       = trim($_GET['date']     ?? '');
$status     = trim($_GET['status']   ?? '');
$station_id = (int)user_station_id();

// ── GET BATCH ITEMS (for View PO modal) ──────────────────────────────────────
if ($action === 'get_batch_items') {
    $items = [];

    if ($po_type === 'fuel') {
        if ($batch_id !== '') {
            $stmt = $pdo->prepare("
                SELECT fpo.id, ft.name AS product_name, fpo.volume AS quantity, fpo.unit_price, fpo.total_amount
                FROM fuel_purchase_orders fpo
                LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
                WHERE fpo.batch_id = ?
                ORDER BY fpo.id ASC
            ");
            $stmt->execute([$batch_id]);
        } elseif ($date !== '') {
            $stmt = $pdo->prepare("
                SELECT fpo.id, ft.name AS product_name, fpo.volume AS quantity, fpo.unit_price, fpo.total_amount
                FROM fuel_purchase_orders fpo
                LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
                WHERE fpo.station_id = ? AND DATE(fpo.created_at) = ?
                ORDER BY fpo.id ASC
            ");
            $stmt->execute([$station_id, $date]);
        }
    } else {
        if ($batch_id !== '') {
            $stmt = $pdo->prepare("
                SELECT po.id, po.product_name, po.quantity, po.unit_price, po.total_amount
                FROM purchase_orders po
                WHERE po.batch_id = ?
                ORDER BY po.id ASC
            ");
            $stmt->execute([$batch_id]);
        } elseif ($date !== '') {
            $stmt = $pdo->prepare("
                SELECT po.id, po.product_name, po.quantity, po.unit_price, po.total_amount
                FROM purchase_orders po
                WHERE po.station_id = ? AND DATE(po.created_at) = ? AND po.type = 'merch'
                ORDER BY po.id ASC
            ");
            $stmt->execute([$station_id, $date]);
        }
    }

    if (isset($stmt)) {
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode(['items' => $items]);
    exit;
}

// ── GET PENDING ITEMS (for Finalize modal) ────────────────────────────────────
if ($action === 'get_pending_items') {
    $items = [];

    if ($po_type === 'fuel') {
        $stmt = $pdo->prepare("
            SELECT fpo.id,
                   COALESCE(ft.name,'Fuel') AS product_name,
                   fpo.volume    AS quantity,
                   fpo.unit_price,
                   fpo.total_amount
            FROM fuel_purchase_orders fpo
            LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
            WHERE fpo.station_id = ?
              AND DATE(fpo.created_at) = ?
              AND fpo.status IN ('Pending Admin Validation','Pending')
            ORDER BY fpo.id ASC
        ");
        $stmt->execute([$station_id, $date]);
    } else {
        $stmt = $pdo->prepare("
            SELECT po.id, po.product_name, po.quantity, po.unit_price, po.total_amount
            FROM purchase_orders po
            WHERE po.station_id = ?
              AND DATE(po.created_at) = ?
              AND po.type = 'merch'
              AND po.status = 'Pending Admin Validation'
              AND po.admin_finalized = 0
            ORDER BY po.id ASC
        ");
        $stmt->execute([$station_id, $date]);
    }

    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['items' => $items]);
    exit;
}

// Unknown action
echo json_encode(['error' => 'Unknown action']);
