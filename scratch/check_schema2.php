<?php
try {
    $pdo = new PDO("mysql:host=localhost;dbname=petron_pos_db_secure;charset=utf8mb4", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "--- purchase_order_items table columns ---\n";
    $cols = $pdo->query("DESCRIBE purchase_order_items")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        echo "{$col['Field']} - {$col['Type']} - Null: {$col['Null']} - Key: {$col['Key']}\n";
    }

    echo "--- po_items table columns ---\n";
    try {
        $cols = $pdo->query("DESCRIBE po_items")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $col) {
            echo "{$col['Field']} - {$col['Type']} - Null: {$col['Null']} - Key: {$col['Key']}\n";
        }
    } catch (Exception $e) {
        echo "po_items DESCRIBE error: " . $e->getMessage() . "\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
