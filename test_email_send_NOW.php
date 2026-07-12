<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/email_config.php';

echo "<h1>🧪 Test Email Sending (Direct Test)</h1>";
echo "<style>body{font-family:Arial;padding:20px;background:#f5f5f5;} .pass{color:green;} .fail{color:red;} code{background:#000;color:#0f0;padding:2px 8px;}</style>";

// Check Gmail App Password
echo "<h2>📧 Email Configuration</h2>";
echo "<ul>";
echo "<li>SMTP Host: <code>{$email_config['host']}</code></li>";
echo "<li>SMTP Port: <code>{$email_config['port']}</code></li>";
echo "<li>From Email: <code>{$email_config['username']}</code></li>";
echo "<li>App Password: <code>" . str_repeat('*', strlen($email_config['password_hash'])) . "</code> (length: " . strlen($email_config['password_hash']) . ")</li>";
echo "<li>Encryption: <code>{$email_config['encryption']}</code></li>";
echo "</ul>";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test'])) {
    $test_email = trim($_POST['test_email']);
    $test_otp = sprintf("%06d", random_int(100000, 999999));
    
    echo "<hr>";
    echo "<h2>🚀 Sending Test Email...</h2>";
    echo "<p><strong>To:</strong> <code>$test_email</code></p>";
    echo "<p><strong>OTP:</strong> <code>$test_otp</code></p>";
    
    try {
        $result = sendPasswordResetOTP($test_email, $test_otp);
        
        if ($result) {
            echo "<div style='background:#d1fae5;border:2px solid #10b981;padding:20px;border-radius:8px;margin:20px 0;'>";
            echo "<p class='pass' style='font-size:18px;font-weight:bold;'>✅ EMAIL SENT SUCCESSFULLY!</p>";
            echo "<p><strong>Next Steps:</strong></p>";
            echo "<ol>";
            echo "<li>Check your inbox: <code>$test_email</code></li>";
            echo "<li>Check your <strong>SPAM/JUNK</strong> folder</li>";
            echo "<li>Wait 1-2 minutes for delivery</li>";
            echo "<li>Your OTP code is: <code style='font-size:20px;background:#002F6C;color:white;padding:5px 15px;'>$test_otp</code></li>";
            echo "</ol>";
            echo "</div>";
        } else {
            echo "<div style='background:#fee2e2;border:2px solid #e30613;padding:20px;border-radius:8px;margin:20px 0;'>";
            echo "<p class='fail' style='font-size:18px;font-weight:bold;'>❌ EMAIL SENDING FAILED</p>";
            echo "<p>Check the error log below:</p>";
            echo "</div>";
        }
        
        // Show last email log entry
        $log_file = __DIR__ . '/email_send.log';
        if (file_exists($log_file)) {
            $log_lines = file($log_file);
            $last_log = end($log_lines);
            echo "<h3>📝 Last Email Log Entry:</h3>";
            echo "<pre style='background:#000;color:#0f0;padding:15px;border-radius:5px;overflow-x:auto;'>";
            echo htmlspecialchars($last_log);
            echo "</pre>";
            
            $log_data = json_decode($last_log, true);
            if ($log_data && isset($log_data['attempts'])) {
                echo "<h3>🔍 Detailed Attempt Info:</h3>";
                foreach ($log_data['attempts'] as $idx => $attempt) {
                    $success = $attempt['success'] ? 'YES ✓' : 'NO ✗';
                    $color = $attempt['success'] ? 'green' : 'red';
                    echo "<div style='background:white;padding:10px;margin:10px 0;border-left:5px solid $color;'>";
                    echo "<strong>Attempt #" . ($idx + 1) . ":</strong><br>";
                    echo "Method: {$attempt['method']}<br>";
                    echo "Transport: {$attempt['transport']}<br>";
                    echo "Success: <span style='color:$color;font-weight:bold;'>$success</span><br>";
                    if (!empty($attempt['error'])) {
                        echo "Error: <span style='color:red;'>{$attempt['error']}</span><br>";
                    }
                    if (!empty($attempt['info'])) {
                        echo "Info: {$attempt['info']}<br>";
                    }
                    echo "</div>";
                }
            }
        }
        
    } catch (Exception $e) {
        echo "<div style='background:#fee2e2;border:2px solid #e30613;padding:20px;border-radius:8px;'>";
        echo "<p class='fail' style='font-size:18px;font-weight:bold;'>❌ EXCEPTION OCCURRED</p>";
        echo "<p><code>" . htmlspecialchars($e->getMessage()) . "</code></p>";
        echo "</div>";
    }
}

?>

<hr>
<h2>📨 Send Test Email</h2>
<form method="POST" style="background:white;padding:20px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1);">
    <div style="margin-bottom:15px;">
        <label for="test_email" style="display:block;margin-bottom:8px;font-weight:bold;">Email Address to Test:</label>
        <input type="email" name="test_email" id="test_email" 
               value="yyangcabahug@gmail.com" 
               required 
               style="width:100%;padding:12px;font-size:14px;border:2px solid #ddd;border-radius:5px;">
        <small style="color:#666;">Enter the email where you want to receive the test OTP</small>
    </div>
    <button type="submit" name="send_test" 
            style="background:#002F6C;color:white;padding:12px 30px;border:none;border-radius:5px;font-size:15px;font-weight:bold;cursor:pointer;">
        📧 Send Test Email Now
    </button>
</form>

<hr>
<h2>🔧 Troubleshooting Gmail Delivery</h2>
<div style="background:white;padding:20px;border-radius:8px;">
    <h3>Why emails might not arrive:</h3>
    <ol style="line-height:2;">
        <li><strong>Spam/Junk Folder:</strong> Gmail might be filtering emails as spam. CHECK YOUR SPAM FOLDER!</li>
        <li><strong>App Password Invalid:</strong> Gmail app password might have expired or been revoked.</li>
        <li><strong>Too Many Emails:</strong> Sending too many emails in short time triggers Gmail rate limiting.</li>
        <li><strong>Gmail Blocking:</strong> Gmail might be blocking emails from your server IP.</li>
        <li><strong>Delay:</strong> Sometimes emails take 2-5 minutes to arrive.</li>
    </ol>
    
    <h3>✅ How to Fix:</h3>
    <ol style="line-height:2;">
        <li><strong>Check Spam Folder</strong> - Most likely the emails are there!</li>
        <li><strong>Regenerate Gmail App Password:</strong>
            <ul>
                <li>Go to: <a href="https://myaccount.google.com/apppasswords" target="_blank">https://myaccount.google.com/apppasswords</a></li>
                <li>Delete old app password</li>
                <li>Create new app password for "Mail"</li>
                <li>Copy the 16-character password (no spaces)</li>
                <li>Update <code>config/email_config.php</code> with new password</li>
            </ul>
        </li>
        <li><strong>Wait 5 minutes</strong> between test emails</li>
        <li><strong>Mark emails as "Not Spam"</strong> if they land in spam</li>
    </ol>
</div>

<hr>
<h2>📧 Alternative: Check Gmail Inbox Manually</h2>
<p>Open your Gmail and search for:</p>
<ul>
    <li><code>from:christianval0813@gmail.com</code></li>
    <li><code>subject:Petron System OTP</code></li>
    <li>Check <strong>Spam</strong> folder</li>
    <li>Check <strong>All Mail</strong> folder</li>
</ul>

<hr>
<p><a href="public/forgot_password.php" style="display:inline-block;background:#002F6C;color:white;padding:12px 24px;text-decoration:none;border-radius:8px;font-weight:bold;">← Back to Forgot Password</a></p>
