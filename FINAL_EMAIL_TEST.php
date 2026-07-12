<?php
/**
 * FINAL EMAIL DIAGNOSTIC TEST
 * This will tell you EXACTLY why emails are not arriving
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Gmail OTP Delivery Test</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            min-height: 100vh;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #002F6C 0%, #004a9e 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 { font-size: 28px; margin-bottom: 8px; }
        .content { padding: 30px; }
        .test-box {
            background: #f8f9fa;
            border-left: 5px solid #002F6C;
            padding: 20px;
            margin: 20px 0;
            border-radius: 8px;
        }
        .pass { color: #10b981; font-weight: bold; }
        .fail { color: #e30613; font-weight: bold; }
        .warning { color: #f59e0b; font-weight: bold; }
        code {
            background: #1f2937;
            color: #10b981;
            padding: 2px 8px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
        }
        button {
            background: linear-gradient(135deg, #002F6C 0%, #004a9e 100%);
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            width: 100%;
            margin-top: 10px;
        }
        button:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
        input[type="email"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            margin: 10px 0;
        }
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .alert-success {
            background: #d1fae5;
            border: 2px solid #10b981;
            color: #065f46;
        }
        .alert-error {
            background: #fee2e2;
            border: 2px solid #e30613;
            color: #991b1b;
        }
        .alert-warning {
            background: #fef3c7;
            border: 2px solid #f59e0b;
            color: #92400e;
        }
        ul, ol { margin-left: 20px; line-height: 1.8; }
        .big-otp {
            font-size: 32px;
            font-weight: bold;
            font-family: monospace;
            letter-spacing: 8px;
            background: #002F6C;
            color: white;
            padding: 15px;
            text-align: center;
            border-radius: 8px;
            margin: 15px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Gmail OTP Delivery Test</h1>
            <p>Definitive test for yyangcabahug@gmail.com</p>
        </div>
        
        <div class="content">
            <?php
            require_once __DIR__ . '/config/email_config.php';
            
            // Display current config
            echo '<div class="test-box">';
            echo '<h2>Current Email Configuration</h2>';
            echo '<ul>';
            echo '<li><strong>SMTP Host:</strong> <code>' . $email_config['host'] . '</code></li>';
            echo '<li><strong>SMTP Port:</strong> <code>' . $email_config['port'] . '</code></li>';
            echo '<li><strong>From Email:</strong> <code>' . $email_config['from_email'] . '</code></li>';
            echo '<li><strong>From Name:</strong> ' . $email_config['from_name'] . '</li>';
            echo '<li><strong>Encryption:</strong> <code>' . $email_config['encryption'] . '</code></li>';
            echo '<li><strong>App Password:</strong> <code>' . str_repeat('*', strlen($email_config['password_hash'])) . '</code> (' . strlen($email_config['password_hash']) . ' chars)</li>';
            echo '</ul>';
            echo '</div>';
            
            // TEST SENDING
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test'])) {
                $test_email = trim($_POST['test_email']);
                $test_otp = sprintf("%06d", random_int(100000, 999999));
                
                echo '<div class="alert alert-warning">';
                echo '<h3>SENDING EMAIL NOW...</h3>';
                echo '<p><strong>To:</strong> <code>' . htmlspecialchars($test_email) . '</code></p>';
                echo '<p><strong>OTP:</strong> <code>' . $test_otp . '</code></p>';
                echo '<p><strong>Time:</strong> ' . date('Y-m-d H:i:s') . '</p>';
                echo '</div>';
                
                try {
                    // Add detailed SMTP debugging
                    error_log("=== EMAIL TEST START ===");
                    error_log("To: $test_email | OTP: $test_otp");
                    
                    $result = sendPasswordResetOTP($test_email, $test_otp);
                    
                    error_log("Send Result: " . ($result ? 'SUCCESS' : 'FAILED'));
                    error_log("=== EMAIL TEST END ===");
                    
                    if ($result) {
                        echo '<div class="alert alert-success">';
                        echo '<h3>EMAIL SENT SUCCESSFULLY!</h3>';
                        echo '<p><strong>PHPMailer reports:</strong> Email was accepted by Gmail SMTP server</p>';
                        echo '<div class="big-otp">' . $test_otp . '</div>';
                        echo '<h4>Next Steps:</h4>';
                        echo '<ol>';
                        echo '<li><strong>CHECK SPAM/JUNK FOLDER</strong> - Most emails end up here!</li>';
                        echo '<li>Open Gmail and search: <code>from:christianval0813@gmail.com</code></li>';
                        echo '<li>Check <strong>All Mail</strong> folder</li>';
                        echo '<li>Wait 2-3 minutes for delivery</li>';
                        echo '<li>If found in Spam, click "Not Spam" to whitelist sender</li>';
                        echo '</ol>';
                        
                        echo '<div style="background:#fffbeb;padding:15px;border-radius:8px;margin-top:15px;">';
                        echo '<p class="warning"><strong>IMPORTANT:</strong> If email is NOT in inbox OR spam after 5 minutes, then:</p>';
                        echo '<ol>';
                        echo '<li>Gmail App Password is invalid/expired</li>';
                        echo '<li>Gmail is blocking emails from this sender</li>';
                        echo '<li>Need to regenerate App Password: <a href="https://myaccount.google.com/apppasswords" target="_blank">Click Here</a></li>';
                        echo '</ol>';
                        echo '</div>';
                        echo '</div>';
                    } else {
                        echo '<div class="alert alert-error">';
                        echo '<h3>EMAIL SENDING FAILED</h3>';
                        echo '<p>PHPMailer could not send the email. This usually means:</p>';
                        echo '<ol>';
                        echo '<li class="fail">Gmail App Password is WRONG or EXPIRED</li>';
                        echo '<li class="fail">SMTP connection failed</li>';
                        echo '<li class="fail">Gmail account locked or restricted</li>';
                        echo '</ol>';
                        echo '<h4>How to Fix:</h4>';
                        echo '<ol>';
                        echo '<li>Go to: <a href="https://myaccount.google.com/apppasswords" target="_blank">Google App Passwords</a></li>';
                        echo '<li>Sign in with christianval0813@gmail.com</li>';
                        echo '<li>Generate NEW app password for "Mail"</li>';
                        echo '<li>Copy the 16-character password (no spaces)</li>';
                        echo '<li>Edit <code>config/email_config.php</code></li>';
                        echo '<li>Replace old password with new one (line 7)</li>';
                        echo '<li>Save and test again</li>';
                        echo '</ol>';
                        echo '</div>';
                    }
                    
                    // Show detailed log
                    $log_file = __DIR__ . '/email_send.log';
                    if (file_exists($log_file)) {
                        $log_lines = file($log_file);
                        $last_log = end($log_lines);
                        
                        echo '<div class="test-box">';
                        echo '<h3>Email Send Log (Last Entry)</h3>';
                        echo '<pre style="background:#000;color:#0f0;padding:15px;border-radius:5px;overflow-x:auto;font-size:12px;">';
                        echo htmlspecialchars($last_log);
                        echo '</pre>';
                        
                        $log_data = json_decode($last_log, true);
                        if ($log_data && isset($log_data['attempts'])) {
                            foreach ($log_data['attempts'] as $attempt) {
                                $status = $attempt['success'] ? 'SUCCESS' : 'FAILED';
                                $color = $attempt['success'] ? '#10b981' : '#e30613';
                                
                                echo '<div style="background:white;border-left:5px solid ' . $color . ';padding:15px;margin:10px 0;">';
                                echo '<strong>Attempt Details:</strong><br>';
                                echo 'Method: <code>' . $attempt['method'] . '</code><br>';
                                echo 'Transport: <code>' . $attempt['transport'] . '</code><br>';
                                echo 'Result: <span style="color:' . $color . ';font-weight:bold;">' . $status . '</span><br>';
                                if (!empty($attempt['error'])) {
                                    echo 'Error: <span style="color:#e30613;">' . htmlspecialchars($attempt['error']) . '</span><br>';
                                }
                                echo '</div>';
                            }
                        }
                        echo '</div>';
                    }
                    
                } catch (Exception $e) {
                    echo '<div class="alert alert-error">';
                    echo '<h3>EXCEPTION OCCURRED</h3>';
                    echo '<p><code>' . htmlspecialchars($e->getMessage()) . '</code></p>';
                    echo '<p>This is a code error - check your email_config.php file</p>';
                    echo '</div>';
                }
            }
            ?>
            
            <!-- SEND TEST FORM -->
            <div class="test-box">
                <h2>Send Test OTP Email</h2>
                <form method="POST">
                    <label for="test_email"><strong>Email Address:</strong></label>
                    <input type="email" 
                           name="test_email" 
                           id="test_email" 
                           value="yyangcabahug@gmail.com" 
                           required>
                    <button type="submit" name="send_test">
                        SEND TEST OTP NOW
                    </button>
                </form>
            </div>
            
            <!-- TROUBLESHOOTING GUIDE -->
            <div class="test-box">
                <h2>Why Emails Don't Arrive (90% of cases)</h2>
                <h3>1. EMAIL IS IN SPAM FOLDER (MOST COMMON!)</h3>
                <p>Gmail automatically marks unknown senders as spam. Solution:</p>
                <ul>
                    <li>Open Gmail inbox for yyangcabahug@gmail.com</li>
                    <li>Click "Spam" or "Junk" folder</li>
                    <li>Search for emails from: <code>christianval0813@gmail.com</code></li>
                    <li>Click "Not Spam" to whitelist sender</li>
                </ul>
                
                <h3>2. GMAIL APP PASSWORD EXPIRED/INVALID</h3>
                <p>App passwords can expire or be revoked. Solution:</p>
                <ul>
                    <li>Visit: <a href="https://myaccount.google.com/apppasswords" target="_blank">Google App Passwords</a></li>
                    <li>Delete old "Mail" app password</li>
                    <li>Create NEW app password</li>
                    <li>Update <code>config/email_config.php</code> line 7</li>
                </ul>
                
                <h3>3. TOO MANY EMAILS SENT</h3>
                <p>Gmail rate limits senders. Solution:</p>
                <ul>
                    <li>Wait 5-10 minutes between tests</li>
                    <li>Don't send more than 5 emails in 1 minute</li>
                </ul>
            </div>
            
            <div style="text-align:center;padding:20px;">
                <a href="public/forgot_password.php" style="display:inline-block;background:#002F6C;color:white;padding:15px 30px;text-decoration:none;border-radius:8px;font-weight:bold;">
                    Back to Forgot Password
                </a>
            </div>
        </div>
    </div>
</body>
</html>
