<?php
/**
 * DEBUG FORGOT PASSWORD - Check why staff/admin emails not found
 */

require_once __DIR__ . '/public/db_connect.php';

echo "<h1>Forgot Password Debug Tool</h1>";
echo "<style>
body{font-family:Arial,sans-serif;padding:20px;background:#f5f5f5;}
table{border-collapse:collapse;width:100%;margin:20px 0;background:#fff;}
th,td{border:1px solid #ddd;padding:12px;text-align:left;}
th{background:#002F6C;color:white;}
.error{color:#e30613;font-weight:bold;}
.success{color:#10b981;font-weight:bold;}
.warning{color:#f59e0b;font-weight:bold;}
input[type=text]{padding:8px;width:300px;margin:10px 0;}
button{padding:10px 20px;background:#002F6C;color:white;border:none;cursor:pointer;font-size:14px;}
button:hover{background:#004a9e;}
</style>";

// Test email lookup
if (isset($_POST['test_email'])) {
    $test_email = trim($_POST['test_email']);
    
    echo "<h2>Testing Email: " . htmlspecialchars($test_email) . "</h2>";
    
    // Auto-detect columns
    $cols = array_column($pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $uid_col = in_array('user_id', $cols) ? 'user_id' : 'id';
    $phone_col = in_array('phone_number', $cols) ? 'phone_number' : 'phone';
    
    // Check all possible status values
    $all_status = $pdo->query("SELECT DISTINCT status FROM users")->fetchAll(PDO::FETCH_COLUMN);
    echo "<p><strong>All status values in database:</strong> " . implode(', ', array_map('htmlspecialchars', $all_status)) . "</p>";
    
    // Detect which status format is used
    $status_active = in_array('Active', $all_status) ? 'Active' : 'active';
    echo "<p><strong>Detected active status:</strong> <span class='success'>{$status_active}</span></p>";
    
    echo "<h3>Test 1: Exact Match (Case-Sensitive)</h3>";
    $stmt = $pdo->prepare("SELECT `{$uid_col}` AS user_id, username, email, `{$phone_col}` AS phone_number, status, role FROM users WHERE email = ? AND status = ?");
    $stmt->execute([$test_email, $status_active]);
    $result1 = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result1) {
        echo "<p class='success'>✅ FOUND with exact match!</p>";
        echo "<table><tr><th>Field</th><th>Value</th></tr>";
        foreach ($result1 as $k => $v) {
            echo "<tr><td>$k</td><td>" . htmlspecialchars($v) . "</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='error'>❌ NOT FOUND with exact match</p>";
    }
    
    echo "<h3>Test 2: With TRIM (Remove spaces)</h3>";
    $stmt = $pdo->prepare("SELECT `{$uid_col}` AS user_id, username, TRIM(email) AS email, `{$phone_col}` AS phone_number, status, role FROM users WHERE TRIM(email) = ? AND status = ?");
    $stmt->execute([trim($test_email), $status_active]);
    $result2 = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result2) {
        echo "<p class='success'>✅ FOUND with TRIM!</p>";
        echo "<table><tr><th>Field</th><th>Value</th></tr>";
        foreach ($result2 as $k => $v) {
            echo "<tr><td>$k</td><td>" . htmlspecialchars($v) . "</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='error'>❌ NOT FOUND with TRIM</p>";
    }
    
    echo "<h3>Test 3: Case-Insensitive Search (Any Status)</h3>";
    $stmt = $pdo->prepare("SELECT `{$uid_col}` AS user_id, username, email, `{$phone_col}` AS phone_number, status, role, LENGTH(email) as email_length, HEX(email) as email_hex FROM users WHERE LOWER(TRIM(email)) = LOWER(?)");
    $stmt->execute([trim($test_email)]);
    $result3 = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result3) {
        echo "<p class='success'>✅ FOUND with case-insensitive search!</p>";
        echo "<table><tr><th>Field</th><th>Value</th></tr>";
        foreach ($result3 as $k => $v) {
            echo "<tr><td>$k</td><td>" . htmlspecialchars($v) . "</td></tr>";
        }
        echo "</table>";
        
        if ($result3['status'] !== $status_active) {
            echo "<p class='warning'>⚠️ STATUS MISMATCH! User status is '{$result3['status']}' but we're looking for '{$status_active}'</p>";
            echo "<p class='warning'>This is why the user is not found!</p>";
        }
    } else {
        echo "<p class='error'>❌ NOT FOUND even with case-insensitive search</p>";
        echo "<p>This means the email does NOT exist in the database at all.</p>";
    }
    
    echo "<hr>";
}

?>

<h2>Test Email Lookup</h2>
<form method="POST">
    <label><strong>Enter email to test:</strong></label><br>
    <input type="text" name="test_email" placeholder="user@example.com" value="<?php echo htmlspecialchars($_POST['test_email'] ?? ''); ?>" required>
    <br>
    <button type="submit">Test Email</button>
</form>

<hr>

<h2>All Users in Database</h2>
<?php
$cols = array_column($pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC), 'Field');
$uid_col = in_array('user_id', $cols) ? 'user_id' : 'id';
$phone_col = in_array('phone_number', $cols) ? 'phone_number' : 'phone';

$users = $pdo->query("SELECT `{$uid_col}` AS user_id, username, email, role, status FROM users ORDER BY role, user_id")->fetchAll(PDO::FETCH_ASSOC);

echo "<table>";
echo "<tr><th>User ID</th><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th>Test</th></tr>";

foreach ($users as $u) {
    $email_display = htmlspecialchars($u['email']);
    $status_class = (strtolower($u['status']) === 'active') ? 'success' : 'warning';
    
    echo "<tr>";
    echo "<td>{$u['user_id']}</td>";
    echo "<td>{$u['username']}</td>";
    echo "<td>{$email_display}</td>";
    echo "<td>{$u['role']}</td>";
    echo "<td class='$status_class'>{$u['status']}</td>";
    echo "<td><form method='POST' style='margin:0;'><input type='hidden' name='test_email' value='" . htmlspecialchars($u['email']) . "'><button type='submit' style='padding:4px 8px;font-size:12px;'>Test</button></form></td>";
    echo "</tr>";
}

echo "</table>";
?>

<hr>

<h2>Status Value Analysis</h2>
<?php
$status_counts = $pdo->query("SELECT status, COUNT(*) as count FROM users GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);

echo "<table>";
echo "<tr><th>Status Value</th><th>Count</th><th>Case</th></tr>";

foreach ($status_counts as $s) {
    $case = '';
    if ($s['status'] === 'Active') $case = 'Title Case';
    elseif ($s['status'] === 'active') $case = 'Lowercase';
    elseif ($s['status'] === 'ACTIVE') $case = 'Uppercase';
    else $case = 'Mixed/Other';
    
    echo "<tr>";
    echo "<td><strong>{$s['status']}</strong></td>";
    echo "<td>{$s['count']}</td>";
    echo "<td>{$case}</td>";
    echo "</tr>";
}

echo "</table>";

echo "<p><strong>Issue:</strong> If status values are inconsistent (some 'Active', some 'active'), the forgot password query will fail for some users!</p>";
echo "<p><strong>Solution:</strong> Standardize all status values to be consistent.</p>";
?>

<hr>

<h2>Quick Fix</h2>
<p>If you want to standardize all status values to 'Active' (title case), run this:</p>
<form method="POST" onsubmit="return confirm('This will update ALL user status values to title case (Active, Locked, Disabled). Continue?');">
    <input type="hidden" name="fix_status" value="1">
    <button type="submit" style="background:#10b981;">Standardize Status Values</button>
</form>

<?php
if (isset($_POST['fix_status'])) {
    try {
        $pdo->exec("UPDATE users SET status = 'Active' WHERE LOWER(status) = 'active'");
        $pdo->exec("UPDATE users SET status = 'Locked' WHERE LOWER(status) = 'locked'");
        $pdo->exec("UPDATE users SET status = 'Disabled' WHERE LOWER(status) = 'disabled'");
        
        echo "<p class='success'>✅ Status values have been standardized!</p>";
        echo "<p>Please <a href=''>refresh the page</a> to see the changes.</p>";
    } catch (Exception $e) {
        echo "<p class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}
?>

<hr>
<p><a href="public/forgot_password.php">← Back to Forgot Password</a></p>
