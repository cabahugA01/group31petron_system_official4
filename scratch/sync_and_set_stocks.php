<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

try {
    $station_id = 1253; // Edgar's station ID

    // Fetch active non-fuel products
    $stmt = $pdo->query("SELECT id, product_name, category, sku, unit_price, unit_cost, status, COALESCE(min_stock, 10) as min_stock FROM inventory_products WHERE category != 'Fuel' AND LOWER(COALESCE(status, 'active')) != 'inactive' ORDER BY id ASC");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total = count($products);

    echo "Total active merchandise products: {$total}\n";

    if ($total < 8) {
        echo "Not enough active products to set 5 out of stock and 3 critical.\n";
        exit;
    }

    $pdo->beginTransaction();

    for ($i = 0; $i < $total; $i++) {
        $p = $products[$i];
        
        // Define stock level and reorder level
        if ($i < 5) {
            $stock_val = 0;
            $reorder_val = 10;
        } elseif ($i < 8) {
            $stock_val = 5;
            $reorder_val = 10;
        } else {
            $stock_val = 50;
            $reorder_val = 10;
        }

        // 1. Update inventory_products
        $stmt_ip = $pdo->prepare("UPDATE inventory_products SET stock_quantity = ?, stock = ? WHERE id = ?");
        $stmt_ip->execute([$stock_val, $stock_val, $p['id']]);

        // 2. Insert/Update station_inventory
        $stmt_si_check = $pdo->prepare("SELECT id FROM station_inventory WHERE product_id = ? AND station_id = ?");
        $stmt_si_check->execute([$p['id'], $station_id]);
        $si_exists = $stmt_si_check->fetchColumn();

        if ($si_exists) {
            $stmt_si_up = $pdo->prepare("UPDATE station_inventory SET stock_level = ?, reorder_level = ?, status = 'active', price = ?, cost = ? WHERE product_id = ? AND station_id = ?");
            $stmt_si_up->execute([$stock_val, $reorder_val, $p['unit_price'], $p['unit_cost'], $p['id'], $station_id]);
        } else {
            $stmt_si_ins = $pdo->prepare("INSERT INTO station_inventory (product_id, station_id, stock_level, reorder_level, status, price, cost) VALUES (?, ?, ?, ?, 'active', ?, ?)");
            $stmt_si_ins->execute([$p['id'], $station_id, $stock_val, $reorder_val, $p['unit_price'], $p['unit_cost']]);
        }

        // 3. Insert/Update products (used in dashboard merchandise value/counts)
        $stmt_p_check = $pdo->prepare("SELECT id FROM products WHERE sku = ? AND station_id = ?");
        $stmt_p_check->execute([$p['sku'], $station_id]);
        $p_exists = $stmt_p_check->fetchColumn();

        if ($p_exists) {
            $stmt_p_up = $pdo->prepare("UPDATE products SET current_stock = ?, min_stock_level = ?, price = ?, cost = ?, name = ?, status = 'Normal' WHERE sku = ? AND station_id = ?");
            $stmt_p_up->execute([$stock_val, $reorder_val, $p['unit_price'], $p['unit_cost'], $p['product_name'], $p['sku'], $station_id]);
        } else {
            $stmt_p_ins = $pdo->prepare("INSERT INTO products (sku, name, station_id, current_stock, min_stock_level, price, cost, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Normal')");
            $stmt_p_ins->execute([$p['sku'], $p['product_name'], $station_id, $stock_val, $reorder_val, $p['unit_price'], $p['unit_cost']]);
        }

        echo "Synced product ID {$p['id']} ({$p['product_name']}) | SKU: {$p['sku']} | Stock: {$stock_val} | Reorder: {$reorder_val}\n";
    }

    $pdo->commit();
    echo "\nAll tables successfully synced and stock levels configured!\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Error: " . $e->getMessage() . "\n";
}
