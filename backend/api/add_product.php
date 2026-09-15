<?php
// backend/api/add_product.php
// Handles on-the-fly product creation by staff members

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Auth check
if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$me = current_user();
$station_id = user_station_id();
$role = role_key($me['role'] ?? '');

// Both staff and managers/admins can add products on the fly
if (!in_array($role, ['staff', 'cashier', 'pump_attendant', 'manager', 'admin', 'superadmin'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $rawBody = file_get_contents('php://input');
    $data = json_decode($rawBody, true);

    if (!$data) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON data']);
        exit;
    }

    $product_name = trim($data['product_name'] ?? '');
    $sku = trim($data['sku'] ?? '');
    $category = trim($data['category'] ?? 'General');
    $size = trim($data['size'] ?? ''); // Unit/size
    $unit_price = floatval($data['unit_price'] ?? 0);
    $unit_cost = floatval($data['unit_cost'] ?? ($unit_price * 0.7)); // default unit cost is 70% of price if not given

    if (empty($product_name)) {
        http_response_code(400);
        echo json_encode(['error' => 'Product Name is required']);
        exit;
    }

    // Auto-generate SKU if empty
    if (empty($sku)) {
        $sku = 'SKU-' . strtoupper(substr(md5($product_name . time()), 0, 8));
    }

    // Check if product already exists with same name/SKU within this station
    $checkStmt = $pdo->prepare("SELECT id FROM inventory_products WHERE station_id = ? AND (product_name = ? OR (sku = ? AND sku != '')) LIMIT 1");
    $checkStmt->execute([$station_id, $product_name, $sku]);
    if ($checkStmt->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'A product with this name or SKU already exists.']);
        exit;
    }

    $status = 'Active';
    if (in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
        $status = 'pending';
    }

    $pdo->beginTransaction();

    // Insert into inventory_products with status, stock, and station_id
    $stmt = $pdo->prepare("
        INSERT INTO inventory_products (product_name, sku, category, size, unit_cost, unit_price, stock_quantity, stock, status, station_id, created_at, updated_at) 
        VALUES (?, ?, ?, ?, ?, ?, 0, 0, ?, ?, NOW(), NOW())
    ");
    $stmt->execute([$product_name, $sku, $category, $size, $unit_cost, $unit_price, $status, $station_id]);
    $product_id = $pdo->lastInsertId();

    // Insert zero-stock entry into station_inventory with full price and cost
    $siStmt = $pdo->prepare("
        INSERT INTO station_inventory (station_id, product_id, stock_level, unit, cost, price, reorder_level, critical_level, status, last_updated) 
        VALUES (?, ?, 0, ?, ?, ?, 24, 10, 'active', NOW())
    ");
    $siStmt->execute([$station_id, $product_id, $size ?: 'pcs', $unit_cost, $unit_price]);

    // Also sync products table
    try {
        $cat_id = function_exists('ensure_product_category_id') ? ensure_product_category_id($pdo, $category) : null;
        $pdo->prepare("
            INSERT INTO products 
            (id, sku, name, description, category_id, unit, cost, price, created_at, updated_at, min_stock_level, max_stock_level, station_id, current_stock, capacity, status)
            VALUES (?, ?, ?, '', ?, ?, ?, ?, NOW(), NOW(), 24, 100, ?, 0, 480, 'active')
        ")->execute([$product_id, $sku, $product_name, $cat_id, $size ?: 'pcs', $unit_cost, $unit_price, $station_id]);
    } catch (Exception $e) {}

    $pdo->commit();

    // Audit logging
    try {
        if (function_exists('log_activity')) {
            log_activity($pdo, $me['id'], 'Product Created On-The-Fly', "Product: $product_name (SKU: $sku), Price: ₱" . number_format($unit_price, 2));
        }
    } catch (Exception $e) {}

    echo json_encode([
        'success' => true,
        'product' => [
            'product_id' => $product_id,
            'product_name' => $product_name,
            'sku' => $sku,
            'category' => $category,
            'size' => $size,
            'unit_price' => $unit_price,
            'stock_level' => 0
        ],
        'message' => 'Product created successfully!'
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
}
