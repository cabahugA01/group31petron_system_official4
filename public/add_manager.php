<?php
/**
 * Script to add Manager user to the database
 * Run this file once by visiting: http://localhost/group31petron_system_official4/public/add_manager.php
 */

require_once __DIR__ . '/db_connect.php';

try {
    // Check if manager already exists
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = 'manager'");
    $checkStmt->execute();
    $count = $checkStmt->fetchColumn();
    
    if ($count > 0) {
        echo "Manager user already exists in the database!";
        exit;
    }
    
    // Hash the password
    $password = password_hash('manager123', PASSWORD_DEFAULT);
    
    // Insert manager user
    $stmt = $pdo->prepare("
        INSERT INTO users (username, password, role, hourly_rate, email, name, station_id, status, is_deleted, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $result = $stmt->execute([
        'manager',              // username
        $password,              // password (hashed)
        'manager',              // role
        200.00,                 // hourly_rate
        'manager@petron.com',   // email
        'Station Manager',      // name
        1,                      // station_id (adjust if needed)
        'active',               // status
        0                       // is_deleted
    ]);
    
    if ($result) {
        echo "<h2>✅ Success!</h2>";
        echo "<p>Manager user has been added to the database.</p>";
        echo "<ul>";
        echo "<li><strong>Username:</strong> manager</li>";
        echo "<li><strong>Password:</strong> manager123</li>";
        echo "<li><strong>Role:</strong> manager</li>";
        echo "<li><strong>Email:</strong> manager@petron.com</li>";
        echo "</ul>";
        echo "<p><a href='login.php'>Go to Login</a></p>";
        echo "<hr>";
        echo "<p style='color: red;'><strong>IMPORTANT:</strong> Delete this file (add_manager.php) after running it for security reasons!</p>";
    } else {
        echo "❌ Failed to insert manager user.";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>
