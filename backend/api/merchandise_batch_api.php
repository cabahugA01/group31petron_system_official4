<?php
/**
 * Merchandise Batch API
 * Handles batch tracking per product (FIFO-based inventory)
 *
 * FLOW:
 *  1. Every approved delivery auto-creates a batch record in merchandise_batches
 *  2. Product stock = SUM of remaining_qty across active batches
 *  3. FIFO: oldest batch consumed first on sale
 *  4. Manager/Admin can view batches per product, add manual batches
 *
 * TABLES:
 *  merchandise_batches  — batch records per product
 *  inventory_products   — product master (stock column kept in sync)
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

// ── Bootstrap merchandise_batches table ───────────────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS merchandise_batches (
            id                INT AUTO_INCREMENT PRIMARY KEY,
            product_id        INT          NOT NULL,
            station_id        INT          NOT NULL,
            batch_number      VARCHAR(50)  NOT NULL,
            delivery_id       INT          DEFAULT NULL COMMENT 'deliveries_oversight.id if from delivery',
            quantity_received INT          NOT NULL DEFAULT 0,
            remaining_qty     INT          NOT NULL DEFAULT 0,
            unit_cost         DECIMAL(12,4) NOT NULL DEFAULT 0,
            supplier          VARCHAR(200) DEFAULT NULL,
            date_received     DATE         NOT NULL,
            encoded_by        INT          DEFAULT NULL,
            validated_by      INT          DEFAULT NULL,
            validated_at      DATETIME     DEFAULT NULL,
            status            ENUM('active','depleted','cancelled') NOT NULL DEFAULT 'active',
            notes             TEXT         DEFAULT NULL,
            created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_product  (product_id),
            INDEX idx_station  (station_id),
            INDEX idx_status   (status),
            INDEX idx_date     (date_received)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    error_log('merchandise_batches bootstrap: ' . $e->getMessage());
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function next_batch_number(PDO $pdo, int $product_id): string {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM merchandise_batches WHERE product_id = ?");
    $stmt->execute([$product_id]);
    $count = (int)$stmt->fetchColumn();
    return 'BATCH-' . str_pad($product_id, 4, '0', STR_PAD_LEFT) . '-' . str_pad($count + 1, 3, '0', STR_PAD_LEFT);
}

function sync_product_stock(PDO $pdo, int $product_id): void {
    // Recalculate stock from active batches
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(remaining_qty), 0)
        FROM merchandise_batches
        WHERE product_id = ? AND status = 'active'
    ");
    $stmt->execute([$product_id]);
    $total = (int)$stmt->fetchColumn();

    $pdo->prepare("UPDATE inventory_products SET stock = ? WHERE id = ?")
        ->execute([$total, $product_id]);
}

// ── Route ─────────────────────────────────────────────────────────────────────
try {
    switch ($action) {

        // ── GET: batches for a specific product ───────────────────────────────
        case 'get_batches':
            $product_id = (int)($_GET['product_id'] ?? 0);
            if (!$product_id) {
                echo json_encode(['success' => false, 'message' => 'product_id required']);
                break;
            }

            // Product info
            $pStmt = $pdo->prepare("
                SELECT id, product_name, category, sku, unit_cost, unit_price, stock
                FROM inventory_products WHERE id = ?
            ");
            $pStmt->execute([$product_id]);
            $product = $pStmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                echo json_encode(['success' => false, 'message' => 'Product not found']);
                break;
            }

            // Batches (FIFO order: oldest first)
            $bStmt = $pdo->prepare("
                SELECT
                    mb.id,
                    mb.batch_number,
                    mb.delivery_id,
                    mb.quantity_received,
                    mb.remaining_qty,
                    mb.unit_cost,
                    mb.supplier,
                    mb.date_received,
                    mb.status,
                    mb.notes,
                    mb.created_at,
                    mb.validated_at,
                    u_enc.name AS encoded_by_name,
                    u_val.name AS validated_by_name,
                    do2.delivery_ref
                FROM merchandise_batches mb
                LEFT JOIN users u_enc ON mb.encoded_by  = u_enc.id
                LEFT JOIN users u_val ON mb.validated_by = u_val.id
                LEFT JOIN deliveries_oversight do2 ON mb.delivery_id = do2.id
                WHERE mb.product_id = ? AND mb.station_id = ?
                ORDER BY mb.date_received ASC, mb.id ASC
            ");
            $bStmt->execute([$product_id, $station_id]);
            $batches = $bStmt->fetchAll(PDO::FETCH_ASSOC);

            // Summary stats
            $total_received  = array_sum(array_column($batches, 'quantity_received'));
            $total_remaining = array_sum(array_column($batches, 'remaining_qty'));
            $active_batches  = count(array_filter($batches, fn($b) => $b['status'] === 'active'));

            echo json_encode([
                'success'         => true,
                'product'         => $product,
                'batches'         => $batches,
                'summary'         => [
                    'total_received'  => $total_received,
                    'total_remaining' => $total_remaining,
                    'active_batches'  => $active_batches,
                    'batch_count'     => count($batches),
                ],
            ]);
            break;

        // ── GET: all products with batch summary ──────────────────────────────
        case 'get_products_with_batches':
            $category_f = trim($_GET['category'] ?? '');
            $search_f   = trim($_GET['search']   ?? '');

            $where  = "WHERE ip.category NOT IN ('Fuel')";
            $params = [];

            if ($category_f !== '') {
                $where   .= " AND ip.category = ?";
                $params[] = $category_f;
            }
            if ($search_f !== '') {
                $where   .= " AND (ip.product_name LIKE ? OR ip.sku LIKE ?)";
                $params[] = '%' . $search_f . '%';
                $params[] = '%' . $search_f . '%';
            }

            $stmt = $pdo->prepare("
                SELECT
                    ip.id,
                    ip.product_name,
                    ip.category,
                    ip.sku,
                    ip.unit_cost,
                    ip.unit_price,
                    ip.stock,
                    COALESCE(batch_agg.active_batches, 0)   AS active_batches,
                    COALESCE(batch_agg.total_batches, 0)    AS total_batches,
                    COALESCE(batch_agg.total_remaining, 0)  AS batch_stock,
                    batch_agg.latest_batch_date,
                    batch_agg.latest_unit_cost
                FROM inventory_products ip
                LEFT JOIN (
                    SELECT
                        product_id,
                        COUNT(*)                                          AS total_batches,
                        SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS active_batches,
                        SUM(CASE WHEN status='active' THEN remaining_qty ELSE 0 END) AS total_remaining,
                        MAX(date_received)                                AS latest_batch_date,
                        (SELECT unit_cost FROM merchandise_batches mb2
                         WHERE mb2.product_id = merchandise_batches.product_id
                           AND mb2.station_id = ?
                         ORDER BY mb2.date_received DESC, mb2.id DESC LIMIT 1) AS latest_unit_cost
                    FROM merchandise_batches
                    WHERE station_id = ?
                    GROUP BY product_id
                ) batch_agg ON batch_agg.product_id = ip.id
                {$where}
                ORDER BY ip.category, ip.product_name
            ");
            $params = array_merge([$station_id, $station_id], $params);
            $stmt->execute($params);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Distinct categories
            $catStmt = $pdo->query("SELECT DISTINCT category FROM inventory_products WHERE category NOT IN ('Fuel') ORDER BY category");
            $categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);

            echo json_encode([
                'success'    => true,
                'products'   => $products,
                'categories' => $categories,
            ]);
            break;

        // ── POST: add manual batch (manager adds stock manually) ──────────────
        case 'add_batch':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];

            $product_id   = (int)($input['product_id']   ?? 0);
            $qty          = (int)($input['quantity']      ?? 0);
            $unit_cost    = (float)($input['unit_cost']   ?? 0);
            $supplier     = trim($input['supplier']       ?? '');
            $date_received = trim($input['date_received'] ?? date('Y-m-d'));
            $notes        = trim($input['notes']          ?? '');

            if (!$product_id) { echo json_encode(['success' => false, 'message' => 'product_id required']); break; }
            if ($qty <= 0)    { echo json_encode(['success' => false, 'message' => 'Quantity must be > 0']); break; }
            if ($unit_cost < 0) { echo json_encode(['success' => false, 'message' => 'Unit cost cannot be negative']); break; }

            // Verify product exists
            $pStmt = $pdo->prepare("SELECT id, product_name FROM inventory_products WHERE id = ?");
            $pStmt->execute([$product_id]);
            $product = $pStmt->fetch(PDO::FETCH_ASSOC);
            if (!$product) { echo json_encode(['success' => false, 'message' => 'Product not found']); break; }

            $pdo->beginTransaction();

            $batch_number = next_batch_number($pdo, $product_id);

            $pdo->prepare("
                INSERT INTO merchandise_batches
                    (product_id, station_id, batch_number, quantity_received, remaining_qty,
                     unit_cost, supplier, date_received, encoded_by, validated_by, validated_at, status, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'active', ?)
            ")->execute([
                $product_id, $station_id, $batch_number, $qty, $qty,
                $unit_cost, $supplier ?: null, $date_received,
                $me['id'], $me['id'],
                $notes ?: null
            ]);

            $batch_id = $pdo->lastInsertId();

            // Sync product stock
            sync_product_stock($pdo, $product_id);

            $pdo->commit();

            log_activity($pdo, $me['id'], 'Batch Added',
                "Manual batch {$batch_number} added for product #{$product_id} ({$product['product_name']}): {$qty} pcs @ ₱{$unit_cost}");

            echo json_encode([
                'success'      => true,
                'message'      => "Batch {$batch_number} added successfully. Stock updated.",
                'batch_id'     => $batch_id,
                'batch_number' => $batch_number,
            ]);
            break;

        // ── POST: create batch from approved delivery (called by delivery approval) ──
        case 'create_from_delivery':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];

            $delivery_id  = (int)($input['delivery_id']  ?? 0);
            $product_id   = (int)($input['product_id']   ?? 0);
            $qty          = (int)($input['quantity']      ?? 0);
            $unit_cost    = (float)($input['unit_cost']   ?? 0);
            $supplier     = trim($input['supplier']       ?? '');
            $date_received = trim($input['date_received'] ?? date('Y-m-d'));

            if (!$product_id || $qty <= 0) {
                echo json_encode(['success' => false, 'message' => 'product_id and quantity required']);
                break;
            }

            $pdo->beginTransaction();

            $batch_number = next_batch_number($pdo, $product_id);

            $pdo->prepare("
                INSERT INTO merchandise_batches
                    (product_id, station_id, batch_number, delivery_id, quantity_received, remaining_qty,
                     unit_cost, supplier, date_received, encoded_by, validated_by, validated_at, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'active')
            ")->execute([
                $product_id, $station_id, $batch_number, $delivery_id ?: null,
                $qty, $qty, $unit_cost, $supplier ?: null, $date_received,
                $me['id'], $me['id']
            ]);

            $batch_id = $pdo->lastInsertId();
            sync_product_stock($pdo, $product_id);

            $pdo->commit();

            echo json_encode([
                'success'      => true,
                'message'      => "Batch {$batch_number} created from delivery.",
                'batch_id'     => $batch_id,
                'batch_number' => $batch_number,
            ]);
            break;

        // ── POST: update batch notes/status ───────────────────────────────────
        case 'update_batch':
            $input      = json_decode(file_get_contents('php://input'), true) ?? [];
            $batch_id   = (int)($input['batch_id'] ?? 0);
            $notes      = trim($input['notes']     ?? '');
            $new_status = trim($input['status']    ?? '');

            if (!$batch_id) { echo json_encode(['success' => false, 'message' => 'batch_id required']); break; }

            $stmt = $pdo->prepare("SELECT * FROM merchandise_batches WHERE id = ? AND station_id = ?");
            $stmt->execute([$batch_id, $station_id]);
            $batch = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$batch) { echo json_encode(['success' => false, 'message' => 'Batch not found']); break; }

            $fields = ['notes = ?', 'updated_at = NOW()'];
            $vals   = [$notes];

            if ($new_status && in_array($new_status, ['active', 'depleted', 'cancelled'])) {
                $fields[] = 'status = ?';
                $vals[]   = $new_status;
            }

            $vals[] = $batch_id;
            $pdo->prepare("UPDATE merchandise_batches SET " . implode(', ', $fields) . " WHERE id = ?")
                ->execute($vals);

            if ($new_status) {
                sync_product_stock($pdo, $batch['product_id']);
            }

            log_activity($pdo, $me['id'], 'Batch Updated',
                "Batch #{$batch_id} ({$batch['batch_number']}) updated" . ($new_status ? " status→{$new_status}" : ''));

            echo json_encode(['success' => true, 'message' => 'Batch updated.']);
            break;

        // ── GET: batch history report per product ─────────────────────────────
        case 'batch_report':
            $product_id = (int)($_GET['product_id'] ?? 0);
            $start      = $_GET['start'] ?? date('Y-m-d', strtotime('-90 days'));
            $end        = $_GET['end']   ?? date('Y-m-d');

            $where  = "WHERE mb.station_id = ? AND mb.date_received BETWEEN ? AND ?";
            $params = [$station_id, $start, $end];

            if ($product_id) {
                $where   .= " AND mb.product_id = ?";
                $params[] = $product_id;
            }

            $stmt = $pdo->prepare("
                SELECT
                    mb.*,
                    ip.product_name,
                    ip.category,
                    ip.sku,
                    u_enc.name AS encoded_by_name,
                    u_val.name AS validated_by_name,
                    do2.delivery_ref
                FROM merchandise_batches mb
                JOIN inventory_products ip ON mb.product_id = ip.id
                LEFT JOIN users u_enc ON mb.encoded_by  = u_enc.id
                LEFT JOIN users u_val ON mb.validated_by = u_val.id
                LEFT JOIN deliveries_oversight do2 ON mb.delivery_id = do2.id
                {$where}
                ORDER BY mb.date_received DESC, mb.id DESC
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'data' => $rows, 'count' => count($rows)]);
            break;

        // ── POST: sync all product stocks from batches (maintenance) ──────────
        case 'sync_all_stocks':
            $stmt = $pdo->query("SELECT DISTINCT product_id FROM merchandise_batches WHERE station_id = {$station_id}");
            $ids  = $stmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $pid) {
                sync_product_stock($pdo, (int)$pid);
            }
            echo json_encode(['success' => true, 'message' => count($ids) . ' products synced.']);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Merchandise Batch API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
