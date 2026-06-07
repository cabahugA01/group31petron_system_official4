<?php
/**
 * EMERGENCY FIX - Check and fix token_type column
 */

require_once __DIR__ . '/public/db_connect.php';

echo "<h1>TOKEN TYPE FIX</h1>";
echo "<style>body{font-family:monospace;padding:20px;background:#000;color:#0f0;} .error{color:#f00;} .success{color:#0f0;} .warning{color:#ff0;}</style>";

try {
    // Check the column definition
    echo "<h2>1. Check token_type column definition</h2>";
    $col_info = $pdo->query("SHOW COLUMNS FROM password_reset_tokens WHERE Field = 'token_type'")->fetch(PDO::FETCH_ASSOC);
    echo "<pre>";
    print_r($col_info);
    echo "</pre>";
    
    // Check recent tokens
    echo "<h2>2. Recent tokens in database</h2>";
    $tokens = $pdo->query("SELECT id, user_id, token, token_type, LENGTH(token_type) as type_length, HEX(token_type) as type_hex FROM password_reset_tokens ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    echo "<table border='1' style='border-collapse:collapse;'>";
    echo "<tr><th>ID</th><th>User ID</th><th>Token</th><th>Token Type</th><th>Type Length</th><th>Type HEX</th></tr>";
    foreach ($tokens as $t) {
        echo "<tr>";
        echo "<td>{$t['id']}</td>";
        echo "<td>{$t['user_id']}</td>";
        echo "<td>{$t['token']}</td>";
        echo "<td class='warning'>{$t['token_type']}</td>";
        echo "<td>{$t['type_length']}</td>";
        echo "<td>{$t['type_hex']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Check if token_type is ENUM
    echo "<h2>3. Is token_type an ENUM?</h2>";
    if (strpos($col_info['Type'], 'enum') !== false) {
        echo "<p class='success'>✅ YES - token_type is ENUM</p>";
        echo "<p>Allowed values: {$col_info['Type']}</p>";
        
        // Check if 'login' is in the ENUM values
        if (strpos($col_info['Type'], 'login') !== false) {
            echo "<p class='success'>✅ 'login' is an allowed value</p>";
        } else {
            echo "<p class='error'>❌ 'login' is NOT in the ENUM! This is the problem!</p>";
            echo "<p class='warning'>Need to add 'login' to ENUM values</p>";
            
            // Attempt to fix
            echo "<h3>Attempting to fix ENUM...</h3>";
            try {
                // First, check what values are currently in the ENUM
                preg_match("/^enum\((.*)\)$/", $col_info['Type'], $matches);
                $current_values = $matches[1];
                
                // Add 'login' if not present
                if (strpos($current_values, "'login'") === false) {
                    $new_values = $current_values . ",'login'";
                    $sql = "ALTER TABLE password_reset_tokens MODIFY token_type ENUM($new_values) DEFAULT 'reset'";
                    echo "<p>Executing: <code>$sql</code></p>";
                    $pdo->exec($sql);
                    echo "<p class='success'>✅ ENUM fixed! 'login' has been added.</p>";
                }
            } catch (Exception $e) {
                echo "<p class='error'>Error fixing ENUM: {$e->getMessage()}</p>";
            }
        }
    } else {
        echo "<p class='warning'>⚠️ NO - token_type is VARCHAR</p>";
        echo "<p>Type: {$col_info['Type']}</p>";
    }
    
    // Try inserting a test token
    echo "<h2>4. Test Token Insertion</h2>";
    try {
        $test_user_id = 1;
        $test_token = '999999';
        $test_expires = date('Y-m-d H:i:s', strtotime('+5 minutes'));
        
        $pdo->prepare("DELETE FROM password_reset_tokens WHERE token = ?")->execute([$test_token]);
        $pdo->prepare("INSERT INTO password_reset_tokens (user_id, token, token_type, expires_at, ip_address) VALUES (?, ?, 'login', ?, ?)")
            ->execute([$test_user_id, $test_token, $test_expires, '127.0.0.1']);
        
        echo "<p class='success'>✅ Test insertion successful!</p>";
        
        // Verify
        $verify = $pdo->prepare("SELECT token_type, LENGTH(token_type) as len FROM password_reset_tokens WHERE token = ?")->execute([$test_token]);
        $result = $pdo->query("SELECT token_type, LENGTH(token_type) as len, HEX(token_type) as hex FROM password_reset_tokens WHERE token = '$test_token'")->fetch(PDO::FETCH_ASSOC);
        echo "<p>Inserted token_type: <strong>{$result['token_type']}</strong> (length: {$result['len']}, hex: {$result['hex']})</p>";
        
        if ($result['token_type'] === 'login') {
            echo "<p class='success'>✅ Token type is EXACTLY 'login'</p>";
        } else {
            echo "<p class='error'>❌ Token type is '{$result['token_type']}', not 'login'!</p>";
        }
        
        // Clean up test
        $pdo->prepare("DELETE FROM password_reset_tokens WHERE token = ?")->execute([$test_token]);
        
    } catch (Exception $e) {
        echo "<p class='error'>❌ Test insertion failed: {$e->getMessage()}</p>";
    }
    
    echo "<hr>";
    echo "<h2>5. Recommended Fix</h2>";
    echo "<p>If token_type is ENUM and 'login' is not in the values, run this SQL:</p>";
    echo "<textarea style='width:100%;height:100px;background:#222;color:#0f0;padding:10px;font-family:monospace;'>";
    echo "ALTER TABLE password_reset_tokens \n";
    echo "MODIFY token_type ENUM('reset','login','email_verify','force_change') DEFAULT 'reset';";
    echo "</textarea>";
    
} catch (Exception $e) {
    echo "<p class='error'>ERROR: {$e->getMessage()}</p>";
    echo "<pre>{$e->getTraceAsString()}</pre>";
}

echo "<hr>";
echo "<p><a href='public/login.php' style='color:#0ff;'>← Back to Login</a></p>";
?>
