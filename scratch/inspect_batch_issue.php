<?php
require_once __DIR__ . '/../public/db_connect.php';

// How many products have no active batch record?
echo "=== Products with no active batch ===\n";
$stmt = $pdo->query("
    SELECT p.id, p.name, p.current_stock, 
           (SELECT COUNT(*) FROM merchandise_batches mb WHERE mb.product_id = p.id AND mb.status = 'active') as batch_count
    FROM products p 
    WHERE p.station_id = 1253 
    HAVING batch_count = 0 
    LIMIT 10
");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  id={$r['id']} name=[{$r['name']}] stock={$r['current_stock']} batches={$r['batch_count']}\n";
}

// Check products table structure
echo "\n=== products table columns ===\n";
$stmt = $pdo->query("DESCRIBE products");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo "  {$c['Field']}  [{$c['Type']}]\n";
}

// Check inventory_products vs products
echo "\n=== Total products in station 1253 ===\n";
echo "  products: " . $pdo->query("SELECT COUNT(*) FROM products WHERE station_id = 1253")->fetchColumn() . "\n";
echo "  merchandise_batches active: " . $pdo->query("SELECT COUNT(DISTINCT product_id) FROM merchandise_batches WHERE status='active'")->fetchColumn() . "\n";
echo "  merchandise_stock_in records: " . $pdo->query("SELECT COUNT(*) FROM merchandise_stock_in WHERE station_id = 1253")->fetchColumn() . "\n";
