<?php
/**
 * DEPLOY FORGOT PASSWORD (NO PHONE) - One-Click Deployment
 * 
 * This will:
 * 1. Backup old forgot_password.php
 * 2. Replace it with the clean version (no phone support)
 * 3. Remove phone_number from database
 */

echo "<h1>Deploy Forgot Password (Email/Username Only)</h1>";
echo "<style>body{font-family:Arial,sans-serif;padding:20px;background:#f5f5f5;} .success{color:#10b981;font-weight:bold;} .error{color:#e30613;font-weight:bold;} .warning{color:#f59e0b;font-weight:bold;} pre{background:#000;color:#0f0;padding:15px;border-radius:5px;overflow-x:auto;}</style>";

$deployed = false;

if (isset($_POST['deploy'])) {
    echo "<h2>Deployment Process</h2>";
    
    try {
        // Step 1: Backup old file
        echo "<p>Step 1: Backing up old forgot_password.php...</p>";
        $old_file = __DIR__ . '/public/forgot_password.php';
        $backup_file = __DIR__ . '/public/forgot_password_backup_' . date('Y-m-d_His') . '.php';
        
        if (file_exists($old_file)) {
            copy($old_file, $backup_file);
            echo "<p class='success'>✅ Backup created: " . basename($backup_file) . "</p>";
        } else {
            echo "<p class='warning'>⚠️ Old file not found, will create new one</p>";
        }
        
        // Step 2: Deploy new forgot_password.php
        echo "<p>Step 2: Deploying new forgot_password.php...</p>";
        $new_file = __DIR__ . '/public/forgot_password_clean.php';
        
        if (file_exists($new_file)) {
            copy($new_file, $old_file);
            echo "<p class='success'>✅ New forgot_password.php deployed!</p>";
        } else {
            throw new Exception("Clean version file not found!");
        }
        
        // Step 2.5: Deploy new verify_otp.php
        echo "<p>Step 2.5: Deploying new verify_otp.php (no SMS)...</p>";
        $old_verify = __DIR__ . '/public/verify_otp.php';
        $backup_verify = __DIR__ . '/public/verify_otp_backup_' . date('Y-m-d_His') . '.php';
        
        if (file_exists($old_verify)) {
            copy($old_verify, $backup_verify);
            echo "<p class='success'>✅ Backup created: " . basename($backup_verify) . "</p>";
        }
        
        $new_verify = __DIR__ . '/public/verify_otp_clean.php';
        if (file_exists($new_verify)) {
            copy($new_verify, $old_verify);
            echo "<p class='success'>✅ New verify_otp.php deployed (EMAIL ONLY)!</p>";
        } else {
            echo "<p class='warning'>⚠️ verify_otp_clean.php not found, skipping</p>";
        }
        
        // Step 3: Remove phone from database
        if (isset($_POST['remove_phone'])) {
            echo "<p>Step 3: Removing phone_number from database...</p>";
            
            require_once __DIR__ . '/public/db_connect.php';
            
            // Check if phone_number exists
            $cols = array_column($pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC), 'Field');
            
            if (in_array('phone_number', $cols)) {
                $pdo->exec("ALTER TABLE users DROP COLUMN phone_number");
                echo "<p class='success'>✅ phone_number column removed from users table</p>";
            } else {
                echo "<p class='warning'>⚠️ phone_number column does not exist</p>";
            }
            
            if (in_array('phone', $cols)) {
                $pdo->exec("ALTER TABLE users DROP COLUMN phone");
                echo "<p class='success'>✅ phone column removed from users table</p>";
            } else {
                echo "<p class='warning'>⚠️ phone column does not exist</p>";
            }
            
            echo "<p class='success'>✅ Database updated successfully!</p>";
        } else {
            echo "<p class='warning'>⚠️ Skipped database phone removal (not checked)</p>";
        }
        
        echo "<hr>";
        echo "<h2 class='success'>✅ DEPLOYMENT COMPLETE!</h2>";
        echo "<p>Your forgot password system now supports EMAIL and USERNAME only.</p>";
        echo "<p><a href='public/forgot_password.php' style='color:#002F6C;font-weight:bold;'>→ Test Forgot Password</a></p>";
        
        $deployed = true;
        
    } catch (Exception $e) {
        echo "<p class='error'>❌ ERROR: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    }
}

if (!$deployed) {
?>

<h2>⚠️ Important Information</h2>

<div style="background:#fff;padding:20px;border-radius:8px;border-left:4px solid #002F6C;margin:20px 0;">
    <h3 style="margin-top:0;">What This Will Do:</h3>
    <ol style="line-height:1.8;">
        <li><strong>Backup</strong> your current <code>forgot_password.php</code></li>
        <li><strong>Replace</strong> it with the clean version (no phone/SMS support)</li>
        <li><strong>Remove</strong> phone_number column from database (optional)</li>
    </ol>
    
    <h3>Changes:</h3>
    <ul style="line-height:1.8;">
        <li>❌ <strong>REMOVED:</strong> Phone number login</li>
        <li>❌ <strong>REMOVED:</strong> SMS OTP support</li>
        <li>✅ <strong>KEPT:</strong> Email OTP</li>
        <li>✅ <strong>KEPT:</strong> Username lookup</li>
    </ul>
    
    <h3>After Deployment:</h3>
    <ul style="line-height:1.8;">
        <li>Users can reset password with: <strong>Email</strong> or <strong>Username</strong></li>
        <li>OTP will be sent via <strong>Email only</strong></li>
        <li>Phone field will be removed from users table</li>
    </ul>
</div>

<form method="POST" style="background:#fff;padding:20px;border-radius:8px;">
    <h3>Deployment Options:</h3>
    
    <label style="display:block;margin:15px 0;cursor:pointer;">
        <input type="checkbox" name="remove_phone" value="1" checked style="margin-right:10px;">
        <strong>Remove phone_number from database</strong>
        <div style="margin-left:25px;font-size:13px;color:#666;margin-top:5px;">
            This will permanently delete the phone_number and phone columns from the users table.
        </div>
    </label>
    
    <hr style="margin:20px 0;">
    
    <button type="submit" name="deploy" value="1" 
            style="background:#002F6C;color:white;border:none;padding:12px 24px;font-size:16px;font-weight:bold;border-radius:8px;cursor:pointer;"
            onclick="return confirm('Are you sure you want to deploy? This will modify your forgot_password.php file and optionally remove phone columns from database.');">
        🚀 Deploy Now
    </button>
    
    <a href="public/forgot_password.php" style="margin-left:15px;color:#002F6C;text-decoration:none;font-weight:bold;">
        Cancel
    </a>
</form>

<hr style="margin:30px 0;">

<h3>Preview: New Forgot Password Features</h3>
<ul style="line-height:1.8;">
    <li>✅ Clean, simple interface</li>
    <li>✅ Email or Username input</li>
    <li>✅ Auto-detect input type</li>
    <li>✅ OTP sent via email</li>
    <li>✅ 5-minute expiration</li>
    <li>✅ Single-use tokens</li>
    <li>✅ Secure and fast</li>
</ul>

<hr style="margin:30px 0;">

<h3>Rollback Instructions</h3>
<p>If you need to rollback, your backup file will be saved with timestamp:</p>
<code style="background:#f0f0f0;padding:5px 10px;border-radius:4px;">
    public/forgot_password_backup_YYYY-MM-DD_HHMMSS.php
</code>
<p>Just rename it back to <code>forgot_password.php</code></p>

<?php
}
?>

<hr style="margin:30px 0;">
<p><a href="public/login.php">← Back to Login</a> | <a href="public/forgot_password_clean.php">Preview Clean Version</a></p>
