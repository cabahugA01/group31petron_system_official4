<?php
/**
 * Backend API for Merchandise Inventory Adjustments
 * Supports creating adjustment requests (Staff) and approving/rejecting (Manager)
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? 'staff');
$station_id = user_station_id();

$action = $_GET['action'] ?? $_POST['action'] ?? 'create';

if ($action === 'create') {
    if (!in_array($role, ['staff', 'cashier', 'pump_attendant', 'manager', 'admin', 'superadmin'])) {
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: $_POST;

    $prod_id   = (int)($data['product_id'] ?? 0);
    $adj_type  = trim($data['adjustment_type'] ?? '');
    $adj_act   = trim($data['adjustment_action'] ?? 'Decrease');
    $qty       = (float)($data['quantity'] ?? 0);
    $reason    = trim($data['reason'] ?? '');
    $remarks   = trim($data['remarks'] ?? '');

    if ($prod_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid product selected.']);
        exit;
    }

    if (empty($adj_type)) {
        echo json_encode(['success' => false, 'message' => 'Please select an adjustment type.']);
        exit;
    }

    if ($qty <= 0) {
        echo json_encode(['success' => false, 'message' => 'Quantity must be greater than zero.']);
        exit;
    }

    // Force action for fixed types
    if (in_array($adj_type, ['Damaged Product', 'Expired Product', 'Missing Item'], true)) {
        $adj_act = 'Decrease';
    } elseif ($adj_type === 'Returned Item') {
        $adj_act = 'Increase';
    }

    try {
        // Fetch current product stock
        $stmt = $pdo->prepare("
            SELECT 
                ip.id,
                ip.product_name,
                COALESCE(ip.category, 'Merchandise') AS category,
                COALESCE(NULLIF(ip.sku, ''), CONCAT('P', LPAD(ip.id, 4, '0'))) AS sku,
                COALESCE(si.stock_level, ip.stock, 0) AS current_stock
            FROM inventory_products ip
            LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
            WHERE ip.id = ?
            
            UNION
            
            SELECT 
                p.id,
                p.name AS product_name,
                COALESCE(pc.name, 'General') AS category,
                COALESCE(NULLIF(p.sku, ''), CONCAT('P', LPAD(p.id, 4, '0'))) AS sku,
                COALESCE(si2.stock_level, p.current_stock, 0) AS current_stock
            FROM products p
            LEFT JOIN product_categories pc ON pc.id = p.category_id
            LEFT JOIN station_inventory si2 ON si2.product_id = p.id AND si2.station_id = ?
            WHERE p.id = ?
            LIMIT 1
        ");
        $stmt->execute([$station_id, $prod_id, $station_id, $prod_id]);
        $prod = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$prod) {
            echo json_encode(['success' => false, 'message' => 'Product not found in database.']);
            exit;
        }

        $current_stock = (float)$prod['current_stock'];

        if ($adj_type === 'Physical Count') {
            $quantity_change = (int)($qty - $current_stock);
            $adj_act = $quantity_change >= 0 ? 'Increase' : 'Decrease';
            $adjusted_stock = max(0, (int)$qty);
        } else {
            $strict_deduction_types = ['Damaged Product', 'Expired Product', 'Missing Item'];
            if ($adj_act === 'Decrease' && in_array($adj_type, $strict_deduction_types, true) && $qty > $current_stock) {
                echo json_encode([
                    'success' => false,
                    'message' => "Validation Error: Deduction quantity ({$qty}) cannot exceed current stock ({$current_stock}) for {$adj_type}."
                ]);
                exit;
            }
            $quantity_change = ($adj_act === 'Decrease') ? -(int)$qty : +(int)$qty;
            $adjusted_stock = max(0, (int)($current_stock + $quantity_change));
        }

        $full_reason = $reason . ($remarks !== '' ? ' — ' . $remarks : '');

        $ins = $pdo->prepare("
            INSERT INTO merchandise_adjustments 
            (station_id, product_id, product_name, sku, category, current_stock, adjusted_stock, quantity_change, adjustment_type, reason, status, requested_by, requested_at, created_at, updated_at)
            VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, NOW(), NOW(), NOW())
        ");
        $ins->execute([
            $station_id,
            $prod_id,
            $prod['product_name'],
            $prod['sku'],
            $prod['category'],
            (int)$current_stock,
            (int)$adjusted_stock,
            (int)$quantity_change,
            $adj_type,
            $full_reason,
            $me['id']
        ]);

        $adj_id = $pdo->lastInsertId();

        // Notify Managers
        try {
            $managers = $pdo->query("SELECT id FROM users WHERE role IN ('manager', 'admin', 'superadmin')")->fetchAll(PDO::FETCH_COLUMN);
            $nStmt = $pdo->prepare("
                INSERT INTO notifications (user_id, type, title, message, event_type, severity, redirect_url, created_at)
                VALUES (?, 'warning', 'Pending Stock Adjustment', ?, 'inventory_adjustment', 'medium', 'manager_inventory_merchandise.php?tab=adjustments', NOW())
            ");
            foreach ($managers as $m_id) {
                $nStmt->execute([
                    $m_id,
                    "New adjustment request for {$prod['product_name']} ({$adj_type}: " . ($quantity_change > 0 ? "+{$qty}" : "-{$qty}") . ") pending approval."
                ]);
            }
        } catch (Exception $e) {}

        log_activity($pdo, $me['id'], 'Stock Adjustment Request', "Submitted adjustment #{$adj_id} for {$prod['product_name']} ({$adj_type}: {$quantity_change})");

        echo json_encode([
            'success' => true,
            'message' => "Adjustment request for '{$prod['product_name']}' submitted successfully! Pending Manager approval.",
            'adjustment_id' => $adj_id
        ]);
        exit;

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
