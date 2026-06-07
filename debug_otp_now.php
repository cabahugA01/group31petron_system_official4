<?php
/**
 * EMERGENCY OTP DEBUGGER
 * Check what's in the database RIGHT NOW
 */

require_once __DIR__ . '/public/db_connect.php';

echo "<h1>OTP DEBUG - Current Database State</h1>";
echo "<style>body{font-family:monospace;padding:20px;background:#1a1a1a;color:#0f0;} table{border-collapse:collapse;width:100%;margin:20px 0;} th,td{border:1px solid #0f0;padding:8px;text-align:left;} th{background:#333;} .error{color:#f00;} .success{color:#0f0;} .warning{color:#ff0;}</style>";

try {
    // Check all recent tokens
    echo "<h2>All Recent Password Reset Tokens (Last 10)</h2>";
    $stmt = $pdo->query("
        SELECT 
            prt.id,
            prt.user_id,
            prt.token,
            prt.token_type,
            prt.expires_at,
            prt.is_used,
            prt.created_at,
            CASE 
                WHEN prt.expires_at > NOW() THEN 'VALID'
                ELSE 'EXPIRED'
            END as status,
            u.email,
            u.username,
            u.first_name,
            u.last_name
        FROM password_reset_tokens prt
        LEFT JOIN users u ON prt.user_id = u.user_id OR prt.user_id = u.id
        ORDER BY prt.id DESC
        LIMIT 10
    ");
    
    $tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($tokens)) {
        echo "<p class='warning'>⚠️ NO TOKENS FOUND IN DATABASE!</p>";
    } else {
        echo "<table>";
        echo "<tr>
                <th>ID</th>
                <th>User ID</th>
                <th>User Email</th>
                <th>User Name</th>
                <th>Token (OTP)</th>
                <th>Token Type</th>
                <th>Status</th>
                <th>Is Used</th>
                <th>Expires At</th>
                <th>Created At</th>
              </tr>";
        
        foreach ($tokens as $token) {
            $statusClass = $token['status'] === 'VALID' ? 'success' : 'error';
            $usedClass = $token['is_used'] ? 'error' : 'success';
            
            echo "<tr>";
            echo "<td>{$token['id']}</td>";
            echo "<td>{$token['user_id']}</td>";
            echo "<td>{$token['email']}</td>";
            echo "<td>{$token['first_name']} {$token['last_name']} (@{$token['username']})</td>";
            echo "<td style='font-size:18px;font-weight:bold;'>{$token['token']}</td>";
            echo "<td>{$token['token_type']}</td>";
            echo "<td class='$statusClass'>{$token['status']}</td>";
            echo "<td class='$usedClass'>" . ($token['is_used'] ? 'YES' : 'NO') . "</td>";
            echo "<td>{$token['expires_at']}</td>";
            echo "<td>{$token['created_at']}</td>";
            echo "</tr>";
        }
        
        echo "</table>";
    }
    
    // Check if there's a temp user ID in session
    session_start();
    echo "<h2>Current Session Data</h2>";
    if (isset($_SESSION['temp_2fa_user_id'])) {
        $user_id = $_SESSION['temp_2fa_user_id'];
        echo "<p class='success'>✅ Session User ID: <strong>$user_id</strong></p>";
        
        // Check tokens for this user
        echo "<h3>Tokens for User ID: $user_id</h3>";
        $stmt = $pdo->prepare("
            SELECT * FROM password_reset_tokens 
            WHERE user_id = ? 
            ORDER BY id DESC 
            LIMIT 5
        ");
        $stmt->execute([$user_id]);
        $userTokens = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($userTokens)) {
            echo "<p class='error'>❌ NO TOKENS FOUND FOR THIS USER!</p>";
            echo "<p class='warning'>This is the problem! Login created a session but no token was saved.</p>";
        } else {
            echo "<table>";
            echo "<tr><th>Token (OTP)</th><th>Type</th><th>Expires</th><th>Is Used</th><th>Status</th></tr>";
            foreach ($userTokens as $t) {
                $valid = (strtotime($t['expires_at']) > time() && !$t['is_used']) ? 'VALID' : 'INVALID';
                $class = $valid === 'VALID' ? 'success' : 'error';
                echo "<tr>";
                echo "<td style='font-size:20px;font-weight:bold;'>{$t['token']}</td>";
                echo "<td>{$t['token_type']}</td>";
                echo "<td>{$t['expires_at']}</td>";
                echo "<td>" . ($t['is_used'] ? 'YES' : 'NO') . "</td>";
                echo "<td class='$class'>$valid</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
        
    } else {
        echo "<p class='error'>❌ No temp_2fa_user_id in session!</p>";
        echo "<p>Session data: <pre>" . print_r($_SESSION, true) . "</pre></p>";
    }
    
    // Check users table structure
    echo "<h2>Users Table Structure</h2>";
    $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC);
    echo "<table>";
    echo "<tr><th>Field</th><th>Type</th><th>Key</th></tr>";
    foreach ($cols as $col) {
        echo "<tr>";
        echo "<td>{$col['Field']}</td>";
        echo "<td>{$col['Type']}</td>";
        echo "<td>{$col['Key']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Test query
    echo "<h2>Test Query (What verify_login_otp.php Actually Runs)</h2>";
    echo "<p>Checking if the query used in verify_login_otp.php would work...</p>";
    
    if (isset($_SESSION['temp_2fa_user_id'])) {
        $test_otp = isset($userTokens[0]['token']) ? $userTokens[0]['token'] : '000000';
        echo "<p>Testing with User ID: {$_SESSION['temp_2fa_user_id']}, OTP: $test_otp</p>";
        
        $stmt = $pdo->prepare("
            SELECT prt.token, prt.expires_at, prt.is_used
            FROM password_reset_tokens prt
            WHERE prt.token = ? AND prt.token_type = 'login' AND prt.user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$test_otp, $_SESSION['temp_2fa_user_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            echo "<p class='success'>✅ Query FOUND the token!</p>";
            echo "<pre>" . print_r($result, true) . "</pre>";
        } else {
            echo "<p class='error'>❌ Query FAILED to find token!</p>";
            echo "<p class='warning'>This means the OTP exists but the query can't find it.</p>";
            echo "<p>Possible reasons:</p>";
            echo "<ul>";
            echo "<li>token_type is not 'login' (check table above)</li>";
            echo "<li>user_id doesn't match</li>";
            echo "<li>Token value doesn't match exactly</li>";
            echo "</ul>";
        }
    }
    
} catch (Exception $e) {
    echo "<p class='error'>ERROR: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

echo "<hr>";
echo "<p><a href='public/login.php' style='color:#0ff;'>← Back to Login</a></p>";
echo "<p><a href='javascript:location.reload()' style='color:#0ff;'>🔄 Refresh</a></p>";
?>
