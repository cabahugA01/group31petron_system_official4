<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

$station_id = 1; // Test station
echo "Running query for station $station_id...\n";

try {
    $stmt = $pdo->prepare("
        SELECT ip.id,
               ip.product_name AS name,
               ip.category     AS category_name,
               ip.unit_price   AS price,
               ip.unit_cost    AS cost,
               ip.sku,
               COALESCE(si.unit, ip.unit, 'pcs')       AS unit,
               COALESCE(si.status, 'active')          AS status,
               COALESCE(si.stock_level, ip.stock, 0)  AS stock_level,
               COALESCE(si.capacity, ip.max_stock, 100) AS capacity,
               COALESCE(si.reorder_level, ip.min_stock, 10) AS reorder_level,
               COALESCE(si.variance, 0.00)            AS variance,
               COALESCE(si.last_updated, ip.updated_at, ip.created_at) AS last_updated,
               ip.supplier
        FROM inventory_products ip
        LEFT JOIN station_inventory si
               ON si.product_id = ip.id AND si.station_id = ?
        WHERE ip.category NOT IN ('Fuel')
        ORDER BY ip.category, ip.product_name
    ");
    $stmt->execute([$station_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Success! Total items fetched: " . count($results) . "\n";
    if (count($results) > 0) {
        echo "First item:\n";
        print_r($results[0]);
    }
} catch (Exception $e) {
    echo "Query Error: " . $e->getMessage() . "\n";
}

echo "\nRunning categories query...\n";
try {
    $cat_stmt = $pdo->query("SELECT DISTINCT category FROM inventory_products WHERE category NOT IN ('Fuel') AND category IS NOT NULL AND category != '' ORDER BY category");
    $all_categories = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Success! Categories: " . implode(', ', $all_categories) . "\n";
} catch (Exception $e) {
    echo "Query Error: " . $e->getMessage() . "\n";
}

echo "\nRunning history logs query for product ID 1...\n";
try {
    $stmt = $pdo->prepare("
        SELECT il.*, 
               COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.username, 'System') as user_fullname
        FROM inventory_logs il
        LEFT JOIN users u ON il.user_id = u.id
        WHERE il.product_id = ? AND il.station_id = ?
        ORDER BY il.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute([1, $station_id]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Success! Total history logs: " . count($logs) . "\n";
    if (count($logs) > 0) {
        print_r($logs[0]);
    }
} catch (Exception $e) {
    echo "Query Error: " . $e->getMessage() . "\n";
}
