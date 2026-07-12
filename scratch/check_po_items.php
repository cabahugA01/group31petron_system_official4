<?php
require_once __DIR__ . '/../public/db_connect.php';

try {
    $po_id = 17;
    $stmt = $pdo->prepare("SELECT * FROM purchase_order_items WHERE po_id = ?");
    $stmt->execute([$po_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "--- purchase_order_items for po_id = $po_id ---\n";
    print_r($items);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
