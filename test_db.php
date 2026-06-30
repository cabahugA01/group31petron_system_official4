<?php
require_once __DIR__ . '/public/db_connect.php';
try {
    echo "Connecting...\n";
    $s_cols = array_column($pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    echo "Columns: " . implode(', ', $s_cols) . "\n";
    
    $stmt = $pdo->prepare("SELECT * FROM users LIMIT 1");
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "User fetch: ";
    print_r($user);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
