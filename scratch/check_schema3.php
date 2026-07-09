<?php
try {
    $pdo = new PDO("mysql:host=localhost;dbname=petron_pos_db_secure;charset=utf8mb4", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "--- inventory_products table columns ---\n";
    $cols = $pdo->query("DESCRIBE inventory_products")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        echo "{$col['Field']} - {$col['Type']} - Null: {$col['Null']} - Key: {$col['Key']}\n";
    }

    echo "--- Sample rows ---\n";
    $rows = $pdo->query("SELECT * FROM inventory_products LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    print_r($rows);

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
