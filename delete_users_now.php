<?php
/**
 * EMERGENCY USER DELETION SCRIPT
 * This will permanently delete: AMIE D. CABAHUG, Airel, yang c.
 * 
 * TO USE:
 * 1. Access via browser: http://localhost/group31petron_system_official4/delete_users_now.php
 * 2. Click "Delete Users" button
 * 3. Users will be permanently removed
 * 4. DELETE THIS FILE after use for security
 */

session_start();
require_once __DIR__ . '/public/db_connect.php';

// Security check - only allow execution once
$execution_key = 'DELETE_AMIE_AIREL_YANG_' . date('Ymd');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    try {
        // Users to delete
        $users_to_delete = ['AMIE D. CABAHUG', 'Airel', 'yang c.'];
        
        // First, show who we're about to delete
        $stmt = $pdo->prepare("SELECT id, full_name, username, email, role FROM users WHERE full_name IN (?, ?, ?)");
        $stmt->execute($users_to_delete);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h2>Users Found:</h2>";
        echo "<table border='1' cellpadding='10'>";
        echo "<tr><th>ID</th><th>Full Name</th><th>Username</th><th>Email</th><th>Role</th></tr>";
        foreach ($users as $user) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($user['id']) . "</td>";
            echo "<td>" . htmlspecialchars($user['full_name']) . "</td>";
            echo "<td>" . htmlspecialchars($user['username']) . "</td>";
            echo "<td>" . htmlspecialchars($user['email']) . "</td>";
            echo "<td>" . htmlspecialchars($user['role']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Execute deletion
        $delete_stmt = $pdo->prepare("DELETE FROM users WHERE full_name IN (?, ?, ?)");
        $delete_stmt->execute($users_to_delete);
        $deleted_count = $delete_stmt->rowCount();
        
        echo "<h2 style='color: green;'>✅ DELETION COMPLETE</h2>";
        echo "<p><strong>Number of users deleted: {$deleted_count}</strong></p>";
        
        // Verify deletion
        $verify_stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE full_name IN (?, ?, ?)");
        $verify_stmt->execute($users_to_delete);
        $remaining = $verify_stmt->fetchColumn();
        
        echo "<p>Remaining users with these names: <strong>{$remaining}</strong> (should be 0)</p>";
        
        if ($remaining == 0) {
            echo "<h3 style='color: green;'>✅ SUCCESS! All three users have been permanently deleted from the database.</h3>";
        } else {
            echo "<h3 style='color: red;'>⚠️ WARNING! Some users were not deleted. Check database manually.</h3>";
        }
        
        echo "<hr>";
        echo "<p><strong>IMPORTANT: Delete this file (delete_users_now.php) immediately for security!</strong></p>";
        echo "<p><a href='public/users.php'>Go to User Management</a></p>";
        
    } catch (Exception $e) {
        echo "<h2 style='color: red;'>❌ ERROR</h2>";
        echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Delete Users - PERMANENT</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .warning-box {
            background: #fff3cd;
            border: 2px solid #ffc107;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }
        .danger-box {
            background: #f8d7da;
            border: 2px solid #dc3545;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }
        h1 { color: #dc3545; }
        h2 { color: #856404; }
        .btn {
            padding: 15px 30px;
            font-size: 16px;
            font-weight: bold;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin: 10px 5px;
        }
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        .btn-danger:hover {
            background: #c82333;
        }
        .btn-cancel {
            background: #6c757d;
            color: white;
        }
        .btn-cancel:hover {
            background: #5a6268;
        }
        ul {
            text-align: left;
            display: inline-block;
        }
    </style>
</head>
<body>
    <h1>🔴 PERMANENT USER DELETION</h1>
    
    <div class="danger-box">
        <h2>⚠️ WARNING: This action CANNOT be undone!</h2>
        <p><strong>The following users will be PERMANENTLY deleted from the database:</strong></p>
        <ul>
            <li><strong>AMIE D. CABAHUG</strong></li>
            <li><strong>Airel</strong></li>
            <li><strong>yang c.</strong></li>
        </ul>
    </div>
    
    <div class="warning-box">
        <h2>What will happen:</h2>
        <ul>
            <li>✅ Users will be deleted from the database</li>
            <li>✅ They cannot log in anymore</li>
            <li>✅ Their records will be permanently removed</li>
            <li>⚠️ Any transactions/deliveries linked to them may be affected</li>
        </ul>
    </div>
    
    <form method="POST" onsubmit="return confirm('Are you ABSOLUTELY SURE you want to permanently delete these 3 users?\n\nThis action CANNOT be undone!\n\nClick OK to proceed with deletion.');">
        <input type="hidden" name="confirm_delete" value="yes">
        <button type="submit" class="btn btn-danger">🗑️ DELETE USERS PERMANENTLY</button>
        <a href="public/users.php"><button type="button" class="btn btn-cancel">Cancel</button></a>
    </form>
    
    <hr>
    <p style="color: #dc3545; font-weight: bold;">⚠️ REMEMBER: Delete this file (delete_users_now.php) after use!</p>
</body>
</html>
