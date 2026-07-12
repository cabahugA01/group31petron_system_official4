<?php
require_once __DIR__ . '/../public/db_connect.php';

try {
    // Check all purchase_orders where type = 'merch'
    $stmt = $pdo->query("SELECT id, po_number, product_name, quantity, total_amount, status FROM purchase_orders WHERE type = 'merch'");
    $pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "--- purchase_orders (type = merch) ---\n";
    foreach ($pos as $po) {
        echo "PO ID: {$po['id']}, No: {$po['po_number']}, Name: " . json_encode($po['product_name']) . ", Qty: " . json_encode($po['quantity']) . ", Total: {$po['total_amount']}, Status: {$po['status']}\n";
        // Check items in purchase_order_items
        $istmt = $pdo->prepare("SELECT id, item_name, quantity, unit_price, total_price FROM purchase_order_items WHERE po_id = ?");
        $istmt->execute([$po['id']]);
        $items = $istmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items as $item) {
            echo "   -> Item ID: {$item['id']}, Name: {$item['item_name']}, Qty: {$item['quantity']}, Price: {$item['unit_price']}, Total: {$item['total_price']}\n";
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
