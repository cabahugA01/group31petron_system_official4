<?php
// Clean version without lib.php dependencies
header('Content-Type: application/json');

try {
    // Database connection - copied from lib.php
    $host = 'localhost';
    $dbname = 'u285762786_petrondbs
';
    $username = 'root';
    $password = '';
    
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // First, add the missing items from the image
    $missing_items = [
        ['name' => 'FITA SLUGS', 'description' => 'Fita slugs snack', 'type_id' => 2, 'cost' => 5.00, 'price' => 7.00],
        ['name' => 'SNACKU BIG', 'description' => 'Big size snacku', 'type_id' => 2, 'cost' => 10.00, 'price' => 15.00],
        ['name' => 'SNACKU SM', 'description' => 'Small size snacku', 'type_id' => 2, 'cost' => 7.00, 'price' => 10.00],
        ['name' => 'CREAM O 360G', 'description' => 'Cream O 360g', 'type_id' => 2, 'cost' => 20.00, 'price' => 30.00],
        ['name' => 'CREAM O 330G', 'description' => 'Cream O 330g', 'type_id' => 2, 'cost' => 18.00, 'price' => 27.00],
        ['name' => 'EGGNOG', 'description' => 'Eggnog drink', 'type_id' => 2, 'cost' => 12.00, 'price' => 18.00],
        ['name' => 'MR.CHIPS BIG', 'description' => 'Big size Mr. Chips', 'type_id' => 2, 'cost' => 25.00, 'price' => 35.00],
        ['name' => 'LM JJAMPONG BIG 70G', 'description' => 'Big LM JJampong 70g', 'type_id' => 2, 'cost' => 22.00, 'price' => 30.00],
        ['name' => 'NISSIN CUPS', 'description' => 'Nissin cups', 'type_id' => 2, 'cost' => 25.00, 'price' => 35.00],
        ['name' => 'PRINGLES', 'description' => 'Pringles chips', 'type_id' => 2, 'cost' => 80.00, 'price' => 110.00],
        ['name' => 'MARTY\'S BIG', 'description' => 'Big size Marty\'s snack', 'type_id' => 2, 'cost' => 15.00, 'price' => 22.00],
        ['name' => 'BRAKE FLUID (900ML)', 'description' => 'Brake fluid 900ml', 'type_id' => 2, 'cost' => 120.00, 'price' => 150.00]
    ];
    
    $added_count = 0;
    foreach ($missing_items as $item) {
        // Force add items from image (delete existing if found, then insert)
        $delete_stmt = $pdo->prepare("DELETE FROM products WHERE LOWER(TRIM(name)) = LOWER(TRIM(?))");
        $delete_stmt->execute([$item['name']]);
        
        // Insert new item
        $insert_stmt = $pdo->prepare("
            INSERT INTO products (name, description, type_id, cost, price, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $insert_stmt->execute([
            $item['name'], 
            $item['description'], 
            $item['type_id'], 
            $item['cost'], 
            $item['price']
        ]);
        $added_count++;
    }
    
    // Update products that have zero prices with default values
    $updates = [];
    
    // Check for products with zero cost and price
    $stmt = $pdo->query("SELECT id, name, cost, price FROM products WHERE cost = 0.00 AND price = 0.00");
    $zero_price_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($zero_price_products)) {
        foreach ($zero_price_products as $product) {
            $default_cost = 50.00;
            $default_price = 75.00;
            
            // Set different defaults based on product type/name
            $name_lower = strtolower($product['name']);
            
            if (strpos($name_lower, 'fita slugs') !== false ||
                strpos($name_lower, 'snacku') !== false ||
                strpos($name_lower, 'cream o') !== false ||
                strpos($name_lower, 'eggnog') !== false ||
                strpos($name_lower, 'mr.chips') !== false ||
                strpos($name_lower, 'jjampong') !== false ||
                strpos($name_lower, 'nissin cups') !== false ||
                strpos($name_lower, 'pringles') !== false ||
                strpos($name_lower, 'marty') !== false) {
                $default_cost = 10.00;
                $default_price = 15.00;
            } elseif (strpos($name_lower, 'filter') !== false) {
                $default_cost = 150.00;
                $default_price = 200.00;
            } elseif (strpos($name_lower, 'oil') !== false || strpos($name_lower, 'oll-') !== false) {
                $default_cost = 200.00;
                $default_price = 250.00;
            } elseif (strpos($name_lower, 'grease') !== false) {
                $default_cost = 300.00;
                $default_price = 400.00;
            } elseif (strpos($name_lower, 'fuel') !== false) {
                $default_cost = 45.00;
                $default_price = 60.00;
            } elseif (strpos($name_lower, 'additive') !== false || strpos($name_lower, 'treatment') !== false) {
                $default_cost = 35.00;
                $default_price = 50.00;
            } elseif (strpos($name_lower, 'coolant') !== false) {
                $default_cost = 120.00;
                $default_price = 150.00;
            }
            
            $update_stmt = $pdo->prepare("UPDATE products SET cost = ?, price = ? WHERE id = ?");
            $update_stmt->execute([$default_cost, $default_price, $product['id']]);
            
            $updates[] = [
                'id' => $product['id'],
                'name' => $product['name'],
                'old_cost' => $product['cost'],
                'old_price' => $product['price'],
                'new_cost' => $default_cost,
                'new_price' => $default_price
            ];
        }
    }
    
    // Get summary of all products with their prices
    $stmt = $pdo->query("
        SELECT id, name, cost, price, 
               CASE 
                   WHEN cost = 0.00 AND price = 0.00 THEN 'No Price'
                   WHEN cost = 0.00 THEN 'No Cost'
                   ELSE 'Has Price'
               END as price_status
        FROM products 
        ORDER BY name
    ");
    $all_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'message' => 'Product prices updated successfully',
        'added_items' => $added_count,
        'updates' => $updates,
        'summary' => [
            'total_products' => count($all_products),
            'updated_count' => count($updates),
            'added_count' => $added_count,
            'products_with_prices' => count(array_filter($all_products, function($p) { return $p['price_status'] === 'Has Price'; })),
            'products_without_prices' => count(array_filter($all_products, function($p) { return $p['price_status'] !== 'Has Price'; }))
        ],
        'all_products' => $all_products
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
