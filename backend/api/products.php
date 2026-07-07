<?php
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../products_db.php';

// Initialize database connection
$pdo = getDbConnection();

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$station_id = $_GET['station_id'] ?? 0;
$item_name = $_GET['item_name'] ?? '';

try {
    switch ($action) {
        case 'get_price':
            if (empty($item_name)) {
                echo json_encode(['success' => false, 'error' => 'Item name is required']);
                exit;
            }
            
            $price = resolve_po_unit_price($pdo, (int)$station_id, $item_name);
            echo json_encode(['success' => true, 'price' => $price]);
            break;
            
        case 'list':
            // Get all products for dropdown (fuel types and all merchandise)
            $products = [];
            
            // Get fuel types
            $fuel_types = $pdo->query("SELECT name as product_name, 'Fuel' as category FROM fuel_types ORDER BY name")->fetchAll();
            $products = array_merge($products, $fuel_types);
            
            // Get ALL products from products table
            $all_products = $pdo->query("
                SELECT p.name as product_name, 
                       COALESCE(pc.name, 'General') as category
                FROM products p
                LEFT JOIN product_categories pc ON p.category_id = pc.id
                WHERE p.name IS NOT NULL AND p.name != ''
                ORDER BY p.name
            ")->fetchAll();
            $products = array_merge($products, $all_products);
            
            // Get station-specific products if station_id is provided
            if ($station_id > 0) {
                $station_products = $pdo->prepare("
                    SELECT p.name as product_name, 
                           COALESCE(pc.name, 'Station Items') as category
                    FROM station_inventory si
                    JOIN products p ON si.product_id = p.id
                    LEFT JOIN product_categories pc ON p.category_id = pc.id
                    WHERE si.station_id = ? AND p.name IS NOT NULL AND p.name != ''
                    ORDER BY p.name
                ");
                $station_products->execute([$station_id]);
                $station_products = $station_products->fetchAll();
                $products = array_merge($products, $station_products);
            }
            
            // Remove duplicates by product_name
            $unique_products = [];
            $seen_names = [];
            
            foreach ($products as $item) {
                $name = strtolower(trim($item['product_name']));
                if (!in_array($name, $seen_names)) {
                    $unique_products[] = $item;
                    $seen_names[] = $name;
                }
            }
            
            echo json_encode(['success' => true, 'data' => $unique_products]);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

// Helper function to resolve PO unit price (copied from purchase_order.php)
function resolve_po_unit_price(PDO $pdo, int $station_id, string $item_name): float {
    $name = trim($item_name);
    if ($name === '') return 0.0;

    // First try station inventory
    $stmt = $pdo->prepare("SELECT p.cost, p.price
        FROM station_inventory si
        INNER JOIN products p ON p.id = si.product_id
        WHERE si.station_id = ? AND LOWER(TRIM(p.name)) = LOWER(TRIM(?))
        ORDER BY p.cost DESC
        LIMIT 1");
    $stmt->execute([$station_id, $name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $cost = (float)($row['cost'] ?? 0);
        $price = (float)($row['price'] ?? 0);
        if ($cost > 0) return $cost;
        if ($price > 0) return $price;
    }

    // Then try general products with exact match
    $stmt = $pdo->prepare("SELECT cost, price FROM products WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) ORDER BY cost DESC LIMIT 1");
    $stmt->execute([$name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $cost = (float)($row['cost'] ?? 0);
        $price = (float)($row['price'] ?? 0);
        if ($cost > 0) return $cost;
        if ($price > 0) return $price;
    }

    // Try partial match for fuel types
    $stmt = $pdo->prepare("SELECT name FROM fuel_types WHERE LOWER(name) LIKE LOWER(?) LIMIT 1");
    $stmt->execute(["%$name%"]);
    $fuel_row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($fuel_row) {
        // Look for corresponding product
        $stmt = $pdo->prepare("SELECT cost, price FROM products WHERE LOWER(name) LIKE LOWER(?) ORDER BY cost DESC LIMIT 1");
        $stmt->execute(["%{$fuel_row['name']}%"]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $cost = (float)($row['cost'] ?? 0);
            $price = (float)($row['price'] ?? 0);
            if ($cost > 0) return $cost;
            if ($price > 0) return $price;
        }
    }

    // Try partial match in products
    $stmt = $pdo->prepare("SELECT cost, price FROM products WHERE LOWER(name) LIKE LOWER(?) ORDER BY cost DESC LIMIT 1");
    $stmt->execute(["%$name%"]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $cost = (float)($row['cost'] ?? 0);
        $price = (float)($row['price'] ?? 0);
        if ($cost > 0) return $cost;
        if ($price > 0) return $price;
    }

    return 0.0;
}
?>
