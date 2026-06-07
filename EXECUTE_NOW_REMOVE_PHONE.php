<?php
/**
 * REMOVE PHONE_NUMBER COLUMN - EXECUTE NOW
 * Direct removal script - no confirmation needed
 */

require_once __DIR__ . '/public/db_connect.php';

echo "<h1>Remove Phone Number Column</h1>";
echo "<style>
body{font-family:Arial,sans-serif;padding:20px;background:#f5f5f5;}
.success{color:#10b981;font-weight:bold;font-size:18px;}
.error{color:#e30613;font-weight:bold;font-size:18px;}
.info{color:#3b82f6;font-weight:bold;}
pre{background:#000;color:#0f0;padding:15px;border-radius:5px;overflow-x:auto;}
table{border-collapse:collapse;width:100%;margin:20px 0;background:#fff;}
th,td{border:1px solid #ddd;padding:10px;text-align:left;}
th{background:#002F6C;color:white;}
</style>";

try {
    echo "<h2>Step 1: Check Current Structure</h2>";
    
    // Get current columns
    $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table><tr><th>Column Name</th><th>Type</th><th>Null</th><th>Key</th></tr>";
    $has_phone_number = false;
    $has_phone = false;
    
    foreach ($cols as $col) {
        echo "<tr>";
        echo "<td><strong>{$col['Field']}</strong></td>";
        echo "<td>{$col['Type']}</td>";
        echo "<td>{$col['Null']}</td>";
        echo "<td>{$col['Key']}</td>";
        echo "</tr>";
        
        if ($col['Field'] === 'phone_number') $has_phone_number = true;
        if ($col['Field'] === 'phone') $has_phone = true;
    }
    echo "</table>";
    
    echo "<p><strong>Total columns:</strong> " . count($cols) . "</p>";
    
    if ($has_phone_number) {
        echo "<p class='error'>❌ phone_number column EXISTS</p>";
    } else {
        echo "<p class='success'>✅ phone_number column does NOT exist</p>";
    }
    
    if ($has_phone) {
        echo "<p class='error'>❌ phone column EXISTS</p>";
    } else {
        echo "<p class='success'>✅ phone column does NOT exist</p>";
    }
    
    // Remove columns if they exist
    echo "<hr>";
    echo "<h2>Step 2: Remove Phone Columns</h2>";
    
    $removed = false;
    
    if ($has_phone_number) {
        echo "<p class='info'>Removing phone_number column...</p>";
        $pdo->exec("ALTER TABLE users DROP COLUMN phone_number");
        echo "<p class='success'>✅ phone_number column REMOVED!</p>";
        $removed = true;
    }
    
    if ($has_phone) {
        echo "<p class='info'>Removing phone column...</p>";
        $pdo->exec("ALTER TABLE users DROP COLUMN phone");
        echo "<p class='success'>✅ phone column REMOVED!</p>";
        $removed = true;
    }
    
    if (!$removed) {
        echo "<p class='success'>✅ No phone columns to remove - already clean!</p>";
    }
    
    // Show final structure
    echo "<hr>";
    echo "<h2>Step 3: Final Structure</h2>";
    
    $final_cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table><tr><th>#</th><th>Column Name</th><th>Type</th></tr>";
    $count = 1;
    foreach ($final_cols as $col) {
        echo "<tr>";
        echo "<td>{$count}</td>";
        echo "<td><strong>{$col['Field']}</strong></td>";
        echo "<td>{$col['Type']}</td>";
        echo "</tr>";
        $count++;
    }
    echo "</table>";
    
    echo "<p class='success'>✅ Total columns now: " . count($final_cols) . "</p>";
    
    // Show sample data
    echo "<hr>";
    echo "<h2>Step 4: Sample Data (First 3 Users)</h2>";
    
    $users = $pdo->query("SELECT * FROM users LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($users)) {
        echo "<table>";
        echo "<tr>";
        foreach (array_keys($users[0]) as $key) {
            echo "<th>{$key}</th>";
        }
        echo "</tr>";
        
        foreach ($users as $user) {
            echo "<tr>";
            foreach ($user as $value) {
                echo "<td>" . htmlspecialchars($value ?? '') . "</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    }
    
    echo "<hr>";
    echo "<h1 class='success'>✅ SUCCESS! Phone columns removed from database!</h1>";
    echo "<p>Users table now has <strong>" . count($final_cols) . " columns</strong> (NO phone_number)</p>";
    
    echo "<hr>";
    echo "<h3>Next Steps:</h3>";
    echo "<ol>";
    echo "<li>✅ Database updated</li>";
    echo "<li>Test forgot password: <a href='public/forgot_password.php' style='color:#002F6C;font-weight:bold;'>forgot_password.php</a></li>";
    echo "<li>Test verify OTP: <a href='public/verify_otp.php' style='color:#002F6C;font-weight:bold;'>verify_otp.php</a></li>";
    echo "</ol>";
    
} catch (Exception $e) {
    echo "<p class='error'>❌ ERROR: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<p><a href=''>🔄 Refresh This Page</a> | <a href='public/login.php'>← Back to Login</a></p>";
?>
