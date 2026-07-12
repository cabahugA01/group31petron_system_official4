<?php
require_once __DIR__ . '/../public/db_connect.php';

try {
    $pdo->beginTransaction();

    // 1. Find all purchase_orders where product_name is null/empty or quantity is null/empty
    $stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE type = 'merch' AND (product_name IS NULL OR product_name = '' OR quantity IS NULL OR quantity = 0)");
    $stmt->execute();
    $pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($pos) . " incomplete merchandise purchase orders.\n";
    
    foreach ($pos as $po) {
        $po_id = $po['id'];
        echo "Processing PO ID: {$po_id}, PO Number: {$po['po_number']}\n";
        
        // Fetch items from purchase_order_items
        $stmt_items = $pdo->prepare("SELECT * FROM purchase_order_items WHERE po_id = ?");
        $stmt_items->execute([$po_id]);
        $items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($items)) {
            // Aggregate info
            $first_item = $items[0];
            $item_count = count($items);
            
            if ($item_count === 1) {
                $product_name = $first_item['item_name'];
                $quantity = $first_item['quantity'];
                $unit_price = $first_item['unit_price'];
                $total_amount = $first_item['total_price'];
            } else {
                $product_name = $first_item['item_name'] . " & " . ($item_count - 1) . " other items";
                $quantity = 0;
                $total_amount = 0;
                foreach ($items as $item) {
                    $quantity += $item['quantity'];
                    $total_amount += $item['total_price'];
                }
                $unit_price = round($total_amount / $quantity, 2);
            }
            
            // Update purchase_orders row
            $stmt_update = $pdo->prepare("
                UPDATE purchase_orders
                SET product_name = ?,
                    quantity = ?,
                    unit_price = ?,
                    total_amount = ?
                WHERE id = ?
            ");
            $stmt_update->execute([$product_name, $quantity, $unit_price, $total_amount, $po_id]);
            echo "-> Updated: product_name='{$product_name}', quantity={$quantity}, total_amount={$total_amount}\n";
        } else {
            echo "-> No items found in purchase_order_items for PO ID: {$po_id}. Checking stock_requests...\n";
            // If request_id is present, get item from stock_requests
            if ($po['request_id']) {
                $stmt_req = $pdo->prepare("SELECT * FROM stock_requests WHERE id = ?");
                $stmt_req->execute([$po['request_id']]);
                $req = $stmt_req->fetch(PDO::FETCH_ASSOC);
                if ($req) {
                    $product_name = $req['item_name'];
                    $quantity = $req['approved_quantity'] ?: $req['requested_quantity'] ?: 1;
                    
                    // Get unit price
                    $stmt_price = $pdo->prepare("SELECT unit_price FROM inventory_products WHERE id = ?");
                    $stmt_price->execute([$req['item_id']]);
                    $unit_price = (float)($stmt_price->fetchColumn() ?: 0);
                    $total_amount = $quantity * $unit_price;
                    
                    $stmt_update = $pdo->prepare("
                        UPDATE purchase_orders
                        SET product_name = ?,
                            quantity = ?,
                            unit_price = ?,
                            total_amount = ?
                        WHERE id = ?
                    ");
                    $stmt_update->execute([$product_name, $quantity, $unit_price, $total_amount, $po_id]);
                    echo "-> Updated from stock_requests: product_name='{$product_name}', quantity={$quantity}, total_amount={$total_amount}\n";
                    
                    // Also create a purchase_order_items record for integrity
                    $pdo->prepare("
                        INSERT INTO purchase_order_items
                            (po_id, product_id, item_name, quantity, quantity_ordered, unit_price, total_price)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ")->execute([
                        $po_id, $req['item_id'], $product_name, $quantity, $quantity, $unit_price, $total_amount
                    ]);
                    echo "-> Inserted item into purchase_order_items.\n";
                } else {
                    echo "-> Stock request #{$po['request_id']} not found.\n";
                }
            } else {
                echo "-> No request_id for PO ID: {$po_id}.\n";
            }
        }
    }
    
    $pdo->commit();
    echo "Cleanup completed successfully.\n";
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Error: " . $e->getMessage() . "\n";
}
