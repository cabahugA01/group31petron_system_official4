<?php
try {
    $pdo = new PDO("mysql:host=localhost;dbname=petron_pos_db_secure;charset=utf8mb4", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "--- TABLES ---\n";
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    print_r($tables);

    echo "--- stock_requests table columns ---\n";
    $cols = $pdo->query("DESCRIBE stock_requests")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        echo "{$col['Field']} - {$col['Type']} - Null: {$col['Null']} - Key: {$col['Key']}\n";
    }

    echo "--- purchase_orders table columns ---\n";
    $cols = $pdo->query("DESCRIBE purchase_orders")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        echo "{$col['Field']} - {$col['Type']} - Null: {$col['Null']} - Key: {$col['Key']}\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
