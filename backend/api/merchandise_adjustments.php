<?php
/**
 * Merchandise Stock Adjustments API (Step 8 & 9)
 * Workflow:
 * 1. Manager creates adjustment -> Status: Pending Admin Approval
 * 2. Admin reviews -> ✅ Approve (updates inventory & logs movement) or ❌ Reject
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

$me         = current_user();
if (!$me) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$role       = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();
$action     = $_GET['action'] ?? ($_POST['action'] ?? '');
$method     = $_SERVER['REQUEST_METHOD'];

// Ensure merchandise_adjustments table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `merchandise_adjustments` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `station_id` INT NOT NULL,
        `product_id` INT NOT NULL,
        `product_name` VARCHAR(255) NOT NULL,
        `sku` VARCHAR(100) NULL,
        `category` VARCHAR(100) NULL,
        `current_stock` INT NOT NULL DEFAULT 0,
        `adjusted_stock` INT NOT NULL DEFAULT 0,
        `quantity_change` INT NOT NULL DEFAULT 0,
        `adjustment_type` VARCHAR(100) NOT NULL DEFAULT 'Physical Count',
        `reason` TEXT NULL,
        `status` ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
        `requested_by` INT NOT NULL,
        `requested_by_name` VARCHAR(255) NULL,
        `requested_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `approved_by` INT NULL,
        `approved_by_name` VARCHAR(255) NULL,
        `approved_at` DATETIME NULL,
        `rejection_reason` TEXT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_station (station_id),
        INDEX idx_product (product_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// Ensure inventory_logs table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `inventory_logs` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `station_id` INT NOT NULL,
        `product_id` INT NOT NULL,
        `product_name` VARCHAR(255) NULL,
        `user_id` INT NULL,
        `performed_by` VARCHAR(255) NULL,
        `action` VARCHAR(100) NOT NULL,
        `quantity_before` INT NOT NULL DEFAULT 0,
        `quantity_after` INT NOT NULL DEFAULT 0,
        `quantity_change` INT NOT NULL DEFAULT 0,
        `reference_type` VARCHAR(100) NULL,
        `reference_id` VARCHAR(100) NULL,
        `notes` TEXT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_station (station_id),
        INDEX idx_product (product_id),
        INDEX idx_action (action)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

try {
    switch ($action) {

        // ── Manager: Request Stock Adjustment ───────────────────────────────
        case 'create':
            if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
                echo json_encode(['success' => false, 'message' => 'Manager access required']); exit;
            }

            $raw  = file_get_contents('php://input');
            $data = json_decode($raw, true) ?: $_POST;

            $product_id      = (int)($data['product_id'] ?? 0);
            $adjusted_stock  = (int)($data['adjusted_stock'] ?? 0);
            $adjustment_type = trim($data['adjustment_type'] ?? 'Physical Count');
            $reason          = trim($data['reason'] ?? '');

            if ($product_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Product ID is required']); exit;
            }

            // Fetch current product stock & details
            $prod_stmt = $pdo->prepare("SELECT id, name AS product_name, sku, category_id, current_stock FROM products WHERE id = ? LIMIT 1");
            $prod_stmt->execute([$product_id]);
            $product = $prod_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                // Fallback check inventory_products
                $prod_stmt = $pdo->prepare("SELECT id, product_name, sku, category, stock AS current_stock FROM inventory_products WHERE id = ? LIMIT 1");
                $prod_stmt->execute([$product_id]);
                $product = $prod_stmt->fetch(PDO::FETCH_ASSOC);
            }

            if (!$product) {
                echo json_encode(['success' => false, 'message' => 'Product not found']); exit;
            }

            $current_stock   = (int)($product['current_stock'] ?? 0);
            $quantity_change = $adjusted_stock - $current_stock;

            $insert_stmt = $pdo->prepare("
                INSERT INTO merchandise_adjustments (
                    station_id, product_id, product_name, sku, category,
                    current_stock, adjusted_stock, quantity_change, adjustment_type,
                    reason, status, requested_by, requested_by_name, requested_at, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?, NOW(), NOW())
            ");

            $insert_stmt->execute([
                $station_id,
                $product_id,
                $product['product_name'] ?? $product['name'],
                $product['sku'] ?? '',
                $product['category'] ?? 'General',
                $current_stock,
                $adjusted_stock,
                $quantity_change,
                $adjustment_type,
                $reason,
                $me['id'],
                $me['name'] ?? $me['username']
            ]);

            $adj_id = $pdo->lastInsertId();

            echo json_encode([
                'success' => true,
                'message' => 'Merchandise stock adjustment submitted successfully. Pending Admin approval.',
                'adjustment_id' => $adj_id
            ]);
            break;

        // ── Admin: Approve Adjustment ────────────────────────────────────────
        case 'approve':
            if (!in_array($role, ['admin', 'superadmin'])) {
                echo json_encode(['success' => false, 'message' => 'Admin access required to approve stock adjustments']); exit;
            }

            $adj_id = (int)($_REQUEST['id'] ?? 0);
            if ($adj_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Adjustment ID required']); exit;
            }

            $adj_stmt = $pdo->prepare("SELECT * FROM merchandise_adjustments WHERE id = ? AND status = 'Pending' LIMIT 1");
            $adj_stmt->execute([$adj_id]);
            $adj = $adj_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$adj) {
                echo json_encode(['success' => false, 'message' => 'Pending adjustment not found']); exit;
            }

            $pdo->beginTransaction();

            // 1. Update product stock
            $product_id = (int)$adj['product_id'];
            $new_stock  = (int)$adj['adjusted_stock'];
            $change     = (int)$adj['quantity_change'];

            $upd1 = $pdo->prepare("UPDATE products SET current_stock = ?, updated_at = NOW() WHERE id = ?");
            $upd1->execute([$new_stock, $product_id]);

            $upd2 = $pdo->prepare("UPDATE inventory_products SET stock = ?, updated_at = NOW() WHERE id = ?");
            $upd2->execute([$new_stock, $product_id]);

            // 2. Mark adjustment as Approved
            $upd_adj = $pdo->prepare("
                UPDATE merchandise_adjustments 
                SET status = 'Approved', approved_by = ?, approved_by_name = ?, approved_at = NOW(), updated_at = NOW() 
                WHERE id = ?
            ");
            $upd_adj->execute([$me['id'], $me['name'] ?? $me['username'], $adj_id]);

            // 3. Record in inventory_logs
            $log_stmt = $pdo->prepare("
                INSERT INTO inventory_logs (
                    station_id, product_id, product_name, user_id, performed_by,
                    action, quantity_before, quantity_after, quantity_change,
                    reference_type, reference_id, notes, created_at
                ) VALUES (?, ?, ?, ?, ?, 'Stock Adjustment', ?, ?, ?, 'merchandise_adjustment', ?, ?, NOW())
            ");
            $log_stmt->execute([
                $adj['station_id'],
                $product_id,
                $adj['product_name'],
                $me['id'],
                $me['name'] ?? 'Admin',
                $adj['current_stock'],
                $new_stock,
                $change,
                $adj_id,
                "Admin Approved Adjustment ({$adj['adjustment_type']}): " . ($adj['reason'] ?: 'System Correction')
            ]);

            $pdo->commit();

            echo json_encode(['success' => true, 'message' => 'Stock adjustment approved and inventory updated successfully.']);
            break;

        // ── Admin: Reject Adjustment ─────────────────────────────────────────
        case 'reject':
            if (!in_array($role, ['admin', 'superadmin'])) {
                echo json_encode(['success' => false, 'message' => 'Admin access required']); exit;
            }

            $adj_id = (int)($_REQUEST['id'] ?? 0);
            $reason = trim($_REQUEST['rejection_reason'] ?? 'Rejected by Admin');

            if ($adj_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Adjustment ID required']); exit;
            }

            $upd_adj = $pdo->prepare("
                UPDATE merchandise_adjustments 
                SET status = 'Rejected', approved_by = ?, approved_by_name = ?, approved_at = NOW(), rejection_reason = ?, updated_at = NOW() 
                WHERE id = ? AND status = 'Pending'
            ");
            $upd_adj->execute([$me['id'], $me['name'] ?? $me['username'], $reason, $adj_id]);

            echo json_encode(['success' => true, 'message' => 'Stock adjustment request rejected.']);
            break;

        // ── List Adjustments ─────────────────────────────────────────────────
        case 'get_adjustments':
            $stmt = $pdo->prepare("SELECT * FROM merchandise_adjustments WHERE station_id = ? ORDER BY id DESC LIMIT 100");
            $stmt->execute([$station_id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $rows]);
            break;

        // ── List Inventory Movement History (Step 9) ──────────────────────────
        case 'get_history':
            $stmt = $pdo->prepare("SELECT * FROM inventory_logs WHERE station_id = ? ORDER BY id DESC LIMIT 200");
            $stmt->execute([$station_id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $rows]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
