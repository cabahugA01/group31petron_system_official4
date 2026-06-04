<?php
/**
 * Restock Out of Stock Items Script
 * 
 * This script adds stock to out-of-stock merchandise items,
 * leaving only 5 items with zero stock for testing purposes.
 */

require_once __DIR__ . '/public/db_connect.php';

echo "=== Merchandise Restock Script ===\n\n";

try {
    // Get the station_id (assuming station 1, adjust if needed)
    $station_id = 1;
    
    // First, check current out of stock items
    echo "Checking current out-of-stock items...\n\n";
    
    $stmt = $pdo->prepare("
        SELECT ip.id,
               ip.product_name,
               ip.category,
               ip.sku,
               COALESCE(si.stock_level, ip.stock, 0) AS current_stock
        FROM inventory_products ip
        LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
        WHERE ip.category NOT IN ('Fuel')
          AND COALESCE(si.stock_level, ip.stock, 0) = 0
        ORDER BY ip.product_name
    ");
    $stmt->execute([$station_id]);
    $out_of_stock = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $total_oos = count($out_of_stock);
    echo "Found $total_oos out-of-stock items.\n\n";
    
    if ($total_oos <= 5) {
        echo "✅ Already has 5 or fewer out-of-stock items. No action needed.\n";
        echo "\nCurrent out-of-stock items:\n";
        foreach ($out_of_stock as $item) {
            echo "  - " . $item['product_name'] . " (SKU: " . ($item['sku'] ?: 'N/A') . ")\n";
        }
        exit(0);
    }
    
    // Keep first 5 as out of stock, restock the rest
    $to_keep_empty = array_slice($out_of_stock, 0, 5);
    $to_restock = array_slice($out_of_stock, 5);
    
    echo "Plan:\n";
    echo "  - Keep OUT OF STOCK: 5 items\n";
    echo "  - Restock: " . count($to_restock) . " items\n\n";
    
    echo "Items that will remain OUT OF STOCK:\n";
    foreach ($to_keep_empty as $item) {
        echo "  ❌ " . $item['product_name'] . " (SKU: " . ($item['sku'] ?: 'N/A') . ")\n";
    }
    echo "\n";
    
    $pdo->beginTransaction();
    
    $restocked = 0;
    foreach ($to_restock as $item) {
        $product_id = $item['id'];
        $product_name = $item['product_name'];
        $sku = $item['sku'] ?: 'N/A';
        
        // Determine stock level based on category
        $new_stock = 50; // Default
        $category = strtolower($item['category'] ?? '');
        
        if (strpos($category, 'oil') !== false || strpos($category, 'lube') !== false) {
            $new_stock = 100; // Oils/Lubes - higher stock
        } elseif (strpos($category, 'accessories') !== false) {
            $new_stock = 75;  // Accessories - medium-high stock
        } elseif (strpos($category, 'tire') !== false) {
            $new_stock = 20;  // Tires - lower stock (bulky items)
        } elseif (strpos($category, 'brake') !== false) {
            $new_stock = 30;  // Brake parts - medium-low stock
        } elseif (strpos($category, 'filter') !== false) {
            $new_stock = 60;  // Filters - medium-high stock
        } elseif (strpos($category, 'snacks') !== false || strpos($category, 'drinks') !== false) {
            $new_stock = 150; // Snacks/Drinks - high stock (fast-moving)
        }
        
        // Check if station_inventory record exists
        $check = $pdo->prepare("SELECT id FROM station_inventory WHERE product_id = ? AND station_id = ?");
        $check->execute([$product_id, $station_id]);
        $exists = $check->fetch(PDO::FETCH_ASSOC);
        
        if ($exists) {
            // Update existing record
            $update = $pdo->prepare("
                UPDATE station_inventory 
                SET stock_level = ?, last_updated = NOW()
                WHERE product_id = ? AND station_id = ?
            ");
            $update->execute([$new_stock, $product_id, $station_id]);
        } else {
            // Insert new record
            $insert = $pdo->prepare("
                INSERT INTO station_inventory (station_id, product_id, stock_level, reorder_level, last_updated)
                VALUES (?, ?, ?, 10, NOW())
            ");
            $insert->execute([$station_id, $product_id, $new_stock]);
        }
        
        // Also update the inventory_products.stock column for consistency
        $pdo->prepare("UPDATE inventory_products SET stock = ? WHERE id = ?")->execute([$new_stock, $product_id]);
        
        echo "  ✅ Restocked: " . $product_name . " (SKU: $sku) → $new_stock units\n";
        $restocked++;
    }
    
    $pdo->commit();
    
    echo "\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "✅ Restock Complete!\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "  • Restocked: $restocked items\n";
    echo "  • Kept OUT OF STOCK: 5 items\n";
    echo "  • Total processed: " . ($restocked + 5) . " items\n";
    echo "\n";
    
    // Show final summary
    echo "Final inventory status:\n";
    $summary = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN COALESCE(si.stock_level, ip.stock, 0) = 0 THEN 1 ELSE 0 END) as out_of_stock,
            SUM(CASE WHEN COALESCE(si.stock_level, ip.stock, 0) BETWEEN 1 AND si.reorder_level THEN 1 ELSE 0 END) as low_stock,
            SUM(CASE WHEN COALESCE(si.stock_level, ip.stock, 0) > si.reorder_level THEN 1 ELSE 0 END) as in_stock
        FROM inventory_products ip
        LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
        WHERE ip.category NOT IN ('Fuel')
    ");
    $summary->execute([$station_id]);
    $stats = $summary->fetch(PDO::FETCH_ASSOC);
    
    echo "  • OUT OF STOCK: " . ($stats['out_of_stock'] ?? 0) . " items\n";
    echo "  • LOW STOCK: " . ($stats['low_stock'] ?? 0) . " items\n";
    echo "  • IN STOCK: " . ($stats['in_stock'] ?? 0) . " items\n";
    echo "\n";
    echo "🎉 Merchandise inventory updated successfully!\n";
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
