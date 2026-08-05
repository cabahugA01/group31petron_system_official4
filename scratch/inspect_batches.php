<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== merchandise_batches.batch_number samples ===\n";
$stmt = $pdo->query("SELECT id, batch_number, product_id, date_received, created_at FROM merchandise_batches ORDER BY id LIMIT 20");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  id={$r['id']} batch_number=[{$r['batch_number']}] product_id={$r['product_id']} date_received={$r['date_received']}\n";
}

echo "\n=== merchandise_stock_in.batch_ref samples ===\n";
$stmt = $pdo->query("SELECT id, batch_ref, po_number, encoded_at FROM merchandise_stock_in ORDER BY id LIMIT 20");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  id={$r['id']} batch_ref=[{$r['batch_ref']}] po_number={$r['po_number']} encoded_at={$r['encoded_at']}\n";
}

echo "\n=== deliveries_oversight.batch_id samples ===\n";
$stmt = $pdo->query("SELECT id, batch_id, delivery_ref, delivery_date FROM deliveries_oversight ORDER BY id LIMIT 10");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  id={$r['id']} batch_id=[{$r['batch_id']}] delivery_ref={$r['delivery_ref']}\n";
}
