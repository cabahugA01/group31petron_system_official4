<?php
/**
 * EMAIL OTP TEST - COMPREHENSIVE
 * Tests if email OTP is working for forgot password
 * Run: http://localhost/group31petron_system_official4/test_email_otp.php
 */

require_once __DIR__ . '/config/email_config.php';
require_once __DIR__ . '/public/db_connect.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Email OTP Test</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 30px 20px;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 40px;
        }
        h1 {
            color: #2d3748;
            font-size: 28px;
            margin-bottom: 10px;
        }
        .subtitle {
            color: #718096;
            font-size: 14px;
            margin-bottom: 30px;
        }
        .section {
            background: #f7fafc;
            border-left: 4px solid #667eea;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .section h2 {
            color: #2d3748;
            font-size: 18px;
            margin-bottom: 15px;
        }
        .success { border-left-color: #38a169; background: #c6f6d5; color: #22543d; }
        .error { border-left-color: #e53e3e; background: #fed7d7; color: #742a2a; }
        .warning { border-left-color: #ed8936; background: #feebc8; color: #7c2d12; }
        .info { border-left-color: #3182ce; background: #bee3f8; color: #2c5282; }
        .form-group {
            margin-bottom: 20px;
        }
        .form-label {
            display: block;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 8px;
        }
        .form-input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 15px;
        }
        .form-input:focus {
            outline: none;
            border-color: #667eea;
        }
        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn:hover {
            opacity: 0.9;
        }
        .config-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        .config-label {
            font-weight: 600;
            color: #4a5568;
        }
        .config-value {
            color: #2d3748;
            font-family: 'Courier New', monospace;
        }
        pre {
            background: white;
            padding: 15px;
            border-radius: 8px;
            overflow-x: auto;
            font-size: 13px;
            line-height: 1.5;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📧 Email OTP Test</h1>
        <p class="subtitle">Test if email OTP is working for password reset</p>

        <?php
        // Step 1: Check email configuration
        echo '<div class="section info">';
        echo '<h2>🔧 Step 1: Email Configuration</h2>';
        echo '<div class="config-item">';
        echo '<span class="config-label">SMTP Host:</span>';
        echo '<span class="config-value">' . htmlspecialchars($email_config['host']) . '</span>';
        echo '</div>';
        echo '<div class="config-item">';
        echo '<span class="config-label">SMTP Port:</span>';
        echo '<span class="config-value">' . htmlspecialchars($email_config['port']) . '</span>';
        echo '</div>';
        echo '<div class="config-item">';
        echo '<span class="config-label">From Email:</span>';
        echo '<span class="config-value">' . htmlspecialchars($email_config['from_email']) . '</span>';
        echo '</div>';
        echo '<div class="config-item">';
        echo '<span class="config-label">Encryption:</span>';
        echo '<span class="config-value">' . strtoupper(htmlspecialchars($email_config['encryption'])) . '</span>';
        echo '</div>';
        echo '<div class="config-item">';
        echo '<span class="config-label">Username:</span>';
        echo '<span class="config-value">' . htmlspecialchars($email_config['username']) . '</span>';
        echo '</div>';
        echo '<div class="config-item">';
        echo '<span class="config-label">Password:</span>';
        echo '<span class="config-value">' . (empty($email_config['password']) ? '❌ NOT SET' : '✅ SET (hidden)') . '</span>';
        echo '</div>';
        echo '</div>';

        // Step 2: Check PHPMailer
        echo '<div class="section">';
        echo '<h2>📦 Step 2: PHPMailer Check</h2>';
        
        $phpmailer_path = __DIR__ . '/includes/PHPMailer/src/PHPMailer.php';
        if (file_exists($phpmailer_path)) {
            echo '<p>✅ PHPMailer installed: ' . htmlspecialchars($phpmailer_path) . '</p>';
            
            if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
                echo '<p>✅ PHPMailer class loaded successfully</p>';
            } else {
                echo '<p>❌ PHPMailer class not loaded</p>';
            }
        } else {
            echo '<p>❌ PHPMailer not found: ' . htmlspecialchars($phpmailer_path) . '</p>';
        }
        echo '</div>';

        // Step 3: Check sendPasswordResetOTP function
        echo '<div class="section">';
        echo '<h2>🔍 Step 3: Function Check</h2>';
        
        if (function_exists('sendPasswordResetOTP')) {
            echo '<p>✅ sendPasswordResetOTP() function exists</p>';
        } else {
            echo '<p>❌ sendPasswordResetOTP() function NOT found</p>';
        }
        echo '</div>';

        // Step 4: Test email sending
        if (isset($_POST['send_test'])) {
            $test_email = trim($_POST['test_email'] ?? '');
            
            if (empty($test_email)) {
                echo '<div class="section error">';
                echo '<h2>❌ Error</h2>';
                echo '<p>Please enter an email address</p>';
                echo '</div>';
            } elseif (!filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
                echo '<div class="section error">';
                echo '<h2>❌ Error</h2>';
                echo '<p>Invalid email format</p>';
                echo '</div>';
            } else {
                echo '<div class="section info">';
                echo '<h2>📤 Step 4: Sending Test Email</h2>';
                echo '<p><strong>To:</strong> ' . htmlspecialchars($test_email) . '</p>';
                
                // Generate test OTP
                $test_otp = sprintf("%06d", random_int(100000, 999999));
                echo '<p><strong>OTP:</strong> <code style="font-size: 18px; font-weight: bold;">' . $test_otp . '</code></p>';
                
                echo '<p>Sending...</p>';
                echo '</div>';
                
                // Send email
                $result = sendPasswordResetOTP($test_email, $test_otp);
                
                if ($result) {
                    echo '<div class="section success">';
                    echo '<h2>✅ Email Sent Successfully!</h2>';
                    echo '<p><strong>Next steps:</strong></p>';
                    echo '<ol style="margin-left: 20px; line-height: 1.8;">';
                    echo '<li>Check your email: <strong>' . htmlspecialchars($test_email) . '</strong></li>';
                    echo '<li>Look for email from: <strong>' . htmlspecialchars($email_config['from_email']) . '</strong></li>';
                    echo '<li>Subject: <strong>Password Reset OTP - Petron Management System</strong></li>';
                    echo '<li>The OTP code is: <strong>' . $test_otp . '</strong></li>';
                    echo '<li>Email should arrive within 10-30 seconds</li>';
                    echo '</ol>';
                    
                    echo '<p style="margin-top: 20px;"><strong>⚠️ If you don\'t see the email:</strong></p>';
                    echo '<ul style="margin-left: 20px; line-height: 1.8;">';
                    echo '<li>Check your spam/junk folder</li>';
                    echo '<li>Check Gmail\'s "Promotions" or "Updates" tab</li>';
                    echo '<li>Wait 1-2 minutes (sometimes delayed)</li>';
                    echo '<li>Make sure email address is correct</li>';
                    echo '</ul>';
                    echo '</div>';
                } else {
                    echo '<div class="section error">';
                    echo '<h2>❌ Email Sending Failed</h2>';
                    echo '<p><strong>Possible reasons:</strong></p>';
                    echo '<ul style="margin-left: 20px; line-height: 1.8;">';
                    echo '<li>Gmail App Password is incorrect or expired</li>';
                    echo '<li>Gmail account has 2-Step Verification disabled</li>';
                    echo '<li>SMTP server blocked by firewall</li>';
                    echo '<li>Internet connection issue</li>';
                    echo '<li>Gmail daily sending limit reached</li>';
                    echo '</ul>';
                    
                    echo '<p style="margin-top: 15px;"><strong>How to fix:</strong></p>';
                    echo '<ol style="margin-left: 20px; line-height: 1.8;">';
                    echo '<li>Go to: <a href="https://myaccount.google.com/apppasswords" target="_blank">Google App Passwords</a></li>';
                    echo '<li>Generate new App Password for "Mail"</li>';
                    echo '<li>Update password in <code>config/email_config.php</code></li>';
                    echo '<li>Test again</li>';
                    echo '</ol>';
                    echo '</div>';
                }
            }
        }

        // Step 5: Test with real user email
        echo '<div class="section">';
        echo '<h2>👤 Step 5: Test with User Email</h2>';
        
        try {
            $stmt = $pdo->query("SELECT email, username, first_name, last_name FROM users WHERE email IS NOT NULL AND email != '' LIMIT 5");
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($users)) {
                echo '<p><strong>Users with email in database:</strong></p>';
                echo '<table style="width: 100%; border-collapse: collapse; margin-top: 10px;">';
                echo '<thead><tr style="background: #4a5568; color: white;">';
                echo '<th style="padding: 10px; text-align: left;">Username</th>';
                echo '<th style="padding: 10px; text-align: left;">Email</th>';
                echo '<th style="padding: 10px; text-align: left;">Name</th>';
                echo '</tr></thead>';
                echo '<tbody>';
                
                foreach ($users as $user) {
                    echo '<tr style="border-bottom: 1px solid #e2e8f0;">';
                    echo '<td style="padding: 10px;">' . htmlspecialchars($user['username']) . '</td>';
                    echo '<td style="padding: 10px;"><code>' . htmlspecialchars($user['email']) . '</code></td>';
                    echo '<td style="padding: 10px;">' . htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) . '</td>';
                    echo '</tr>';
                }
                
                echo '</tbody></table>';
                
                echo '<p style="margin-top: 15px;"><strong>✅ These users can reset password via email</strong></p>';
            } else {
                echo '<p>❌ No users with email found in database</p>';
                echo '<p>Please add email addresses to user accounts for password reset to work.</p>';
            }
        } catch (Exception $e) {
            echo '<p>Error checking users: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
        
        echo '</div>';

        // Test form
        echo '<div class="section warning">';
        echo '<h2>🧪 Test Email Sending</h2>';
        echo '<p>Enter an email address to test OTP sending:</p>';
        echo '<form method="POST">';
        echo '<div class="form-group">';
        echo '<label class="form-label">Email Address:</label>';
        echo '<input type="email" name="test_email" class="form-input" placeholder="example@gmail.com" required value="' . htmlspecialchars($_POST['test_email'] ?? '') . '">';
        echo '</div>';
        echo '<button type="submit" name="send_test" class="btn">📧 Send Test OTP Email</button>';
        echo '</form>';
        echo '</div>';

        // Instructions
        echo '<div class="section info">';
        echo '<h2>📖 How to Test Forgot Password</h2>';
        echo '<ol style="margin-left: 20px; line-height: 1.8;">';
        echo '<li>Make sure user has valid email in database (check table above)</li>';
        echo '<li>Go to: <a href="public/forgot_password.php" target="_blank">forgot_password.php</a></li>';
        echo '<li>Enter email address</li>';
        echo '<li>Click submit</li>';
        echo '<li>Check email for OTP code</li>';
        echo '<li>Enter OTP on verify page</li>';
        echo '<li>Reset password</li>';
        echo '</ol>';
        echo '</div>';

        // Troubleshooting
        echo '<div class="section warning">';
        echo '<h2>🔧 Troubleshooting</h2>';
        
        echo '<p><strong>If email not working:</strong></p>';
        echo '<ol style="margin-left: 20px; line-height: 1.8;">';
        echo '<li><strong>Check Gmail App Password:</strong>';
        echo '<ul style="margin-left: 20px;">';
        echo '<li>Go to: <a href="https://myaccount.google.com/apppasswords" target="_blank">https://myaccount.google.com/apppasswords</a></li>';
        echo '<li>Enable 2-Step Verification first if not enabled</li>';
        echo '<li>Create new App Password for "Mail"</li>';
        echo '<li>Copy the 16-character password (no spaces)</li>';
        echo '<li>Update in config/email_config.php</li>';
        echo '</ul></li>';
        
        echo '<li><strong>Check Internet Connection:</strong> Make sure server can connect to smtp.gmail.com</li>';
        
        echo '<li><strong>Check Firewall:</strong> Port 587 must be open for SMTP</li>';
        
        echo '<li><strong>Check Email Quota:</strong> Gmail free accounts have daily sending limits</li>';
        
        echo '<li><strong>Test with Different Email:</strong> Try sending to different provider (Yahoo, Outlook)</li>';
        echo '</ol>';
        echo '</div>';
        ?>
    </div>
</body>
</html>
