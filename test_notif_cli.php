<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/backend/lib.php';
require_once __DIR__ . '/public/db_connect.php';

try {
    echo "Connecting to DB...\n";
    global $pdo;
    
    // Check if table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'notifications'");
    $exists = $stmt->fetch();
    if ($exists) {
        echo "Notifications table exists!\n";
        
        // Count total and unread
        $total = $pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
        $unread = $pdo->query("SELECT COUNT(*) FROM notifications WHERE status = 'unread'")->fetchColumn();
        echo "Total notifications in DB: $total\n";
        echo "Unread notifications in DB: $unread\n";
        
        // Fetch last 5
        $stmt = $pdo->query("SELECT * FROM notifications ORDER BY created_at DESC LIMIT 5");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "Last 5 notifications:\n";
        print_r($rows);
    } else {
        echo "Notifications table does NOT exist!\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
