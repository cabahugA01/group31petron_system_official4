<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Debug Whitelist Configuration</h1>";
echo "<style>body{font-family:Arial;padding:20px;background:#f5f5f5;} code{background:#000;color:#0f0;padding:2px 8px;border-radius:4px;} .pass{color:green;font-weight:bold;} .fail{color:red;font-weight:bold;}</style>";

// Test 1: Check if file exists
echo "<h2>Test 1: Check Config File</h2>";
$config_file = __DIR__ . '/config/password_reset_whitelist.php';
if (file_exists($config_file)) {
    echo "<p class='pass'>✓ Config file EXISTS: <code>$config_file</code></p>";
} else {
    echo "<p class='fail'>✗ Config file NOT FOUND: <code>$config_file</code></p>";
    exit;
}

// Test 2: Include the file
echo "<h2>Test 2: Include Config File</h2>";
try {
    require_once $config_file;
    echo "<p class='pass'>✓ Config file LOADED successfully</p>";
} catch (Exception $e) {
    echo "<p class='fail'>✗ Error loading config: " . $e->getMessage() . "</p>";
    exit;
}

// Test 3: Check if function exists
echo "<h2>Test 3: Check Function</h2>";
if (function_exists('isEmailWhitelistedForPasswordReset')) {
    echo "<p class='pass'>✓ Function <code>isEmailWhitelistedForPasswordReset()</code> EXISTS</p>";
} else {
    echo "<p class='fail'>✗ Function <code>isEmailWhitelistedForPasswordReset()</code> NOT FOUND</p>";
    exit;
}

// Test 4: Check whitelist array
echo "<h2>Test 4: Check Whitelist Array</h2>";
$whitelist = getPasswordResetWhitelist();
echo "<p>Whitelist contains <strong>" . count($whitelist) . "</strong> email(s):</p>";
echo "<ul>";
foreach ($whitelist as $email) {
    echo "<li><code>" . htmlspecialchars($email) . "</code></li>";
}
echo "</ul>";

// Test 5: Test the function
echo "<h2>Test 5: Function Test Results</h2>";
echo "<table border='1' cellpadding='10' cellspacing='0' style='background:white;'>";
echo "<tr style='background:#002F6C;color:white;'><th>Test Email</th><th>Function Result</th><th>Status</th></tr>";

$test_cases = [
    'yyangcabahug@gmail.com',
    'YYANGCABAHUG@GMAIL.COM',
    '  yyangcabahug@gmail.com  ',
    'pepito@gmail.com',
    'amiecabahug2020@gmail.com',
    'admin@test.com',
];

foreach ($test_cases as $test_email) {
    $result = isEmailWhitelistedForPasswordReset($test_email);
    $status = $result ? '✓ ALLOWED (will send OTP)' : '✗ BLOCKED (no OTP)';
    $class = $result ? 'pass' : 'fail';
    
    echo "<tr>";
    echo "<td><code>" . htmlspecialchars($test_email) . "</code></td>";
    echo "<td>" . var_export($result, true) . "</td>";
    echo "<td class='$class'>$status</td>";
    echo "</tr>";
}

echo "</table>";

// Test 6: Check from database
echo "<h2>Test 6: Check Database Users</h2>";
try {
    require_once __DIR__ . '/public/db_connect.php';
    
    $stmt = $pdo->query("
        SELECT id, CONCAT(first_name, ' ', last_name) AS name, username, email, role, status
        FROM users
        WHERE LOWER(TRIM(status)) = 'active'
          AND LOWER(TRIM(role)) IN ('staff','manager','admin','developer','superadmin')
        ORDER BY id ASC
    ");
    
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' cellpadding='10' cellspacing='0' style='background:white;width:100%;'>";
    echo "<tr style='background:#002F6C;color:white;'><th>ID</th><th>Name</th><th>Email</th><th>Role</th><th>Can Reset?</th></tr>";
    
    foreach ($users as $user) {
        $email = trim($user['email'] ?? '');
        $can_reset = !empty($email) && isEmailWhitelistedForPasswordReset($email);
        $status = $can_reset ? '✓ YES' : '✗ NO';
        $class = $can_reset ? 'pass' : 'fail';
        
        echo "<tr>";
        echo "<td>" . htmlspecialchars($user['id']) . "</td>";
        echo "<td>" . htmlspecialchars($user['name']) . "</td>";
        echo "<td><code>" . htmlspecialchars($email ?: 'No email') . "</code></td>";
        echo "<td>" . htmlspecialchars(ucfirst($user['role'])) . "</td>";
        echo "<td class='$class'><strong>$status</strong></td>";
        echo "</tr>";
    }
    
    echo "</table>";
    
} catch (Exception $e) {
    echo "<p class='fail'>Database error: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<h2>📝 Summary</h2>";
echo "<p><strong>Expected Behavior:</strong></p>";
echo "<ul>";
echo "<li class='pass'>✓ <code>yyangcabahug@gmail.com</code> should be ALLOWED</li>";
echo "<li class='fail'>✗ All other emails should be BLOCKED</li>";
echo "</ul>";

echo "<hr>";
echo "<p><strong>Next Step:</strong> Try forgot password page with <code>yyangcabahug@gmail.com</code></p>";
echo "<p><a href='public/forgot_password.php' style='display:inline-block;background:#002F6C;color:white;padding:12px 24px;text-decoration:none;border-radius:8px;font-weight:bold;'>→ Test Forgot Password</a></p>";
?>
