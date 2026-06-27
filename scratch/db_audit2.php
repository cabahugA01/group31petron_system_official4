<?php
$pdo = new PDO('mysql:host=localhost;dbname=petron_pos_db_secure', 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== Distinct categories in merchandise_transaction_items ===\n";
try {
    $stmt = $pdo->query("SELECT DISTINCT category, COUNT(*) as cnt FROM merchandise_transaction_items GROUP BY category ORDER BY cnt DESC LIMIT 20");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { echo "  [{$r['category']}] - {$r['cnt']}\n"; }
} catch(Exception $e) { echo "ERROR: " . $e->getMessage() . "\n"; }

echo "\n=== Distinct categories in inventory_products ===\n";
try {
    $stmt = $pdo->query("SELECT DISTINCT category, COUNT(*) as cnt FROM inventory_products GROUP BY category ORDER BY cnt DESC LIMIT 20");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { echo "  [{$r['category']}] - {$r['cnt']}\n"; }
} catch(Exception $e) { echo "ERROR: " . $e->getMessage() . "\n"; }

echo "\n=== Sample merchandise_transaction_items with category ===\n";
try {
    $stmt = $pdo->query("SELECT mti.id, mti.category, mti.product_name, mti.subtotal FROM merchandise_transaction_items mti LIMIT 5");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { print_r($r); }
} catch(Exception $e) { echo "ERROR: " . $e->getMessage() . "\n"; }
