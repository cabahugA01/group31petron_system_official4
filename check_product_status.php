<?php
try {
    $pdo = new PDO('mysql:host=localhost;dbname=petron_pos_db_secure', 'root', '');
    
    echo "=== CHECKING PRODUCT STATUS VALUES ===\n";
    $stmt = $pdo->query("SELECT DISTINCT status FROM inventory_products");
    echo "Distinct status values in inventory_products:\n";
    while($row = $stmt->fetch()) {
        echo "- '" . ($row['status'] ?? 'NULL') . "'\n";
    }
    
    echo "\n=== CHECKING NON-FUEL PRODUCTS ===\n";
    $stmt = $pdo->query("SELECT COUNT(*) FROM inventory_products WHERE LOWER(COALESCE(category, '')) <> 'fuel'");
    echo "Total non-fuel products: " . $stmt->fetchColumn() . "\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM inventory_products WHERE LOWER(COALESCE(category, '')) <> 'fuel' AND LOWER(COALESCE(status, 'active')) <> 'inactive'");
    echo "Non-fuel products (excluding inactive): " . $stmt->fetchColumn() . "\n";
    
    echo "\n=== SAMPLE NON-FUEL PRODUCTS ===\n";
    $stmt = $pdo->query("SELECT product_name, category, status FROM inventory_products WHERE LOWER(COALESCE(category, '')) <> 'fuel' LIMIT 5");
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "- {$row['product_name']} | Cat: {$row['category']} | Status: " . ($row['status'] ?? 'NULL') . "\n";
    }
    
} catch(Exception $e) {
    echo "Error: " . $e->getMessage();
}
