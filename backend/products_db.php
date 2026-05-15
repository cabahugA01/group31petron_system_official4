<?php
require_once __DIR__ . '/lib.php';

// Database connection
function getDbConnection() {
    $host = 'localhost';
    $username = 'root';
    $password = '';
    $database = 'petron_pos_db_secure';
    
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$database;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch(PDOException $e) {
        return null;
    }
}

// Get products from database
function getProductsFromDB($type = null) {
    $pdo = getDbConnection();
    if (!$pdo) {
        // Fallback to JSON if database fails
        return read_json('products.json', ['fuel'=>[], 'merchandise'=>[], 'services'=>[]]);
    }
    
    $products = ['fuel' => [], 'merchandise' => [], 'services' => []];
    
    // Get fuel products (from fuel_inventory table - separate domain)
     $fuelStmt = $pdo->query("
         SELECT p.id, p.sku, p.name, p.description, p.price,
                COALESCE(fi.stock_level, 0) as level_l,
                COALESCE(fi.capacity, 0) as capacity_l
         FROM products p
         LEFT JOIN fuel_inventory fi ON p.id = fi.product_id
         WHERE p.type_id = 1
         ORDER BY p.name
     ");
     
     while ($fuel = $fuelStmt->fetch(PDO::FETCH_ASSOC)) {
         $products['fuel'][] = [
             'id' => $fuel['sku'],
             'type' => 'fuel',
             'name' => $fuel['name'],
             'variant' => $fuel['sku'],
             'price' => (float)$fuel['price'],
             'capacity_l' => (float)$fuel['capacity_l'],
             'level_l' => (float)$fuel['level_l']
         ];
     }
    
    // Get merchandise products
    $merchStmt = $pdo->query("
        SELECT p.sku, p.name, p.description, p.price, p.cost,
               pc.name as category,
               COALESCE(si.stock_level, 0) as stock
        FROM products p
        LEFT JOIN product_categories pc ON p.category_id = pc.id
        LEFT JOIN station_inventory si ON p.id = si.product_id
        WHERE p.type_id = 2
        ORDER BY pc.name, p.name
    ");
    
    while ($merch = $merchStmt->fetch(PDO::FETCH_ASSOC)) {
        $products['merchandise'][] = [
            'id' => $merch['sku'],
            'type' => 'merch',
            'name' => $merch['name'],
            'category' => strtolower(str_replace(['/', ' ', '&'], '_', $merch['category'])),
            'sku' => $merch['sku'],
            'cost' => (float)$merch['cost'],
            'price' => (float)$merch['price'],
            'stock' => (int)$merch['stock'],
            'desc' => $merch['description']
        ];
    }
    
    // Get service products
    $serviceStmt = $pdo->query("
        SELECT p.sku, p.name, p.description, p.price
        FROM products p
        WHERE p.type_id = 3
        ORDER BY p.name
    ");
    
    while ($service = $serviceStmt->fetch(PDO::FETCH_ASSOC)) {
        $products['services'][] = [
            'id' => $service['sku'],
            'type' => 'service',
            'name' => $service['name'],
            'desc' => $service['description'],
            'price' => (float)$service['price']
        ];
    }
    
    return $products;
}

// Get products
$products = getProductsFromDB();

// Optional query: ?type=fuel|merchandise|services
$type = isset($_GET['type']) ? $_GET['type'] : null;

if($type){
  if(!isset($products[$type])) json_response(['ok'=>false,'error'=>'Invalid type'], 400);
  json_response(['ok'=>true,'data'=>$products[$type]]);
}

json_response(['ok'=>true,'data'=>$products]);
?>
