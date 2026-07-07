<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

echo "=== MIGRATION START ===\n";

try {
    // Check if column already exists
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'shift_assignment'");
    $exists = $stmt->fetch();
    
    if (!$exists) {
        echo "Column 'shift_assignment' does not exist. Adding it...\n";
        $pdo->exec("ALTER TABLE users ADD COLUMN shift_assignment VARCHAR(50) DEFAULT NULL AFTER assigned_shift");
        echo "Column 'shift_assignment' added successfully.\n";
        
        // Sync values
        $pdo->exec("UPDATE users SET shift_assignment = CAST(assigned_shift AS CHAR) WHERE assigned_shift IS NOT NULL");
        echo "Synced shift_assignment values with assigned_shift.\n";
    } else {
        echo "Column 'shift_assignment' already exists.\n";
    }
} catch (Exception $e) {
    echo "Migration Error: " . $e->getMessage() . "\n";
}

echo "=== MIGRATION END ===\n";
