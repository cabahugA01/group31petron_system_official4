<?php
require_once __DIR__ . '/public/db_connect.php';

echo "<h2>Users Table Columns:</h2>";
echo "<pre>";

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM users");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Column Name          | Type                 | Key\n";
    echo "==================================================\n";
    foreach ($columns as $col) {
        printf("%-20s | %-20s | %s\n", 
            $col['Field'], 
            $col['Type'], 
            $col['Key']
        );
    }
    
    echo "\n\n=== Primary Key Column ===\n";
    foreach ($columns as $col) {
        if ($col['Key'] === 'PRI') {
            echo "PRIMARY KEY: " . $col['Field'] . "\n";
        }
    }
    
    echo "\n\n=== Sample Query Test ===\n";
    $test = $pdo->query("SELECT * FROM users WHERE LOWER(role) IN ('admin','station admin','station_admin') LIMIT 1");
    if ($test->rowCount() > 0) {
        $row = $test->fetch(PDO::FETCH_ASSOC);
        echo "Sample admin record columns:\n";
        foreach (array_keys($row) as $key) {
            echo "  - $key\n";
        }
    } else {
        echo "No admin records found in database.\n";
    }
    
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage();
}

echo "</pre>";
