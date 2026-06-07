<?php
/**
 * REAL SMS TEST PAGE
 * Test if SMS sending is working with TextBelt (FREE)
 * Run: http://localhost/group31petron_system_official4/test_sms_real.php
 */

require_once __DIR__ . '/config/email_config.php';
require_once __DIR__ . '/config/sms_config.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMS Test - Real Sending</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
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
            font-size: 32px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .subtitle {
            color: #718096;
            font-size: 14px;
            margin-bottom: 30px;
        }
        .config-box {
            background: #f7fafc;
            border-left: 4px solid #667eea;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 25px;
        }
        .config-box h3 {
            color: #2d3748;
            font-size: 18px;
            margin-bottom: 12px;
        }
        .config-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        .config-item:last-child {
            border-bottom: none;
        }
        .config-label {
            font-weight: 600;
            color: #4a5568;
        }
        .config-value {
            color: #2d3748;
            font-family: 'Courier New', monospace;
        }
        .status-enabled {
            color: #38a169;
            font-weight: bold;
        }
        .status-disabled {
            color: #e53e3e;
            font-weight: bold;
        }
        .form-box {
            background: #ffffff;
            border: 2px solid #e2e8f0;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-label {
            display: block;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 8px;
            font-size: 14px;
        }
        .form-input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 15px;
            font-family: 'Segoe UI', sans-serif;
            transition: border-color 0.2s;
        }
        .form-input:focus {
            outline: none;
            border-color: #667eea;
        }
        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 14px 32px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            width: 100%;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.4);
        }
        .result-box {
            padding: 20px;
            border-radius: 10px;
            margin-top: 20px;
            font-size: 15px;
            line-height: 1.6;
        }
        .result-success {
            background: #c6f6d5;
            border-left: 4px solid #38a169;
            color: #22543d;
        }
        .result-error {
            background: #fed7d7;
            border-left: 4px solid #e53e3e;
            color: #742a2a;
        }
        .result-info {
            background: #bee3f8;
            border-left: 4px solid #3182ce;
            color: #2c5282;
        }
        .info-box {
            background: #fffaf0;
            border-left: 4px solid #ed8936;
            padding: 15px 20px;
            border-radius: 8px;
            margin-top: 20px;
            font-size: 14px;
            line-height: 1.6;
        }
        .info-box strong {
            color: #c05621;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📱 SMS Test - Real Sending</h1>
        <p class="subtitle">Test if your SMS configuration is working</p>

        <div class="config-box">
            <h3>📊 Current SMS Configuration</h3>
            <div class="config-item">
                <span class="config-label">Provider:</span>
                <span class="config-value"><?php echo htmlspecialchars($sms_config['provider'] ?? 'Not set'); ?></span>
            </div>
            <div class="config-item">
                <span class="config-label">Status:</span>
                <span class="<?php echo ($sms_config['enabled'] ?? false) ? 'status-enabled' : 'status-disabled'; ?>">
                    <?php echo ($sms_config['enabled'] ?? false) ? '✅ ENABLED' : '❌ DISABLED'; ?>
                </span>
            </div>
            <?php if (($sms_config['provider'] ?? '') === 'textbelt'): ?>
            <div class="config-item">
                <span class="config-label">TextBelt Key:</span>
                <span class="config-value"><?php echo htmlspecialchars($sms_config['textbelt_key'] ?? 'textbelt'); ?></span>
            </div>
            <?php elseif (($sms_config['provider'] ?? '') === 'semaphore'): ?>
            <div class="config-item">
                <span class="config-label">API Key:</span>
                <span class="config-value"><?php 
                    $key = $sms_config['api_key'] ?? '';
                    echo $key === 'YOUR_SEMAPHORE_API_KEY_HERE' ? 'Not configured' : substr($key, 0, 10) . '...';
                ?></span>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($sms_config['enabled'] ?? false): ?>
            <div class="info-box">
                <?php if (($sms_config['provider'] ?? '') === 'textbelt'): ?>
                    <strong>⚠️ TextBelt Free Limitation:</strong> You can send 1 SMS per day per phone number for free. 
                    For unlimited SMS, get a paid key at <a href="https://textbelt.com" target="_blank">textbelt.com</a>
                <?php elseif (($sms_config['provider'] ?? '') === 'semaphore'): ?>
                    <strong>ℹ️ Semaphore SMS:</strong> Make sure you have credits loaded in your Semaphore account.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="result-error">
                <strong>❌ SMS is DISABLED</strong><br>
                SMS is currently disabled in <code>config/sms_config.php</code>. 
                Set <code>'enabled' => true</code> to activate real SMS sending.
            </div>
        <?php endif; ?>

        <div class="form-box">
            <h3 style="margin-bottom: 20px; color: #2d3748;">🧪 Test SMS Sending</h3>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label class="form-label">Phone Number (Philippine format)</label>
                    <input type="text" name="phone" class="form-input" 
                           placeholder="09123456789" 
                           pattern="[0-9]{11}" 
                           required
                           value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                    <small style="color: #718096; display: block; margin-top: 5px;">
                        Format: 09XXXXXXXXX (11 digits)
                    </small>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Test Message</label>
                    <input type="text" name="message" class="form-input" 
                           placeholder="Hello from Petron SMS System!"
                           value="<?php echo htmlspecialchars($_POST['message'] ?? 'Your test OTP is 123456. Valid for 5 minutes.'); ?>">
                </div>
                
                <button type="submit" name="send_test" class="btn">📤 Send Test SMS</button>
            </form>
        </div>

        <?php
        if (isset($_POST['send_test'])) {
            $phone = trim($_POST['phone'] ?? '');
            $message = trim($_POST['message'] ?? '');
            
            if (empty($phone) || empty($message)) {
                echo '<div class="result-box result-error">';
                echo '<strong>❌ Error:</strong> Phone number and message are required.';
                echo '</div>';
            } elseif (!preg_match('/^[0-9]{10,11}$/', $phone)) {
                echo '<div class="result-box result-error">';
                echo '<strong>❌ Error:</strong> Invalid phone number format. Use 11 digits (e.g., 09123456789).';
                echo '</div>';
            } else {
                echo '<div class="result-box result-info">';
                echo '<strong>📤 Sending SMS...</strong><br>';
                echo 'To: ' . htmlspecialchars($phone) . '<br>';
                echo 'Message: ' . htmlspecialchars($message) . '<br><br>';
                
                // Attempt to send SMS
                $result = sendSMS($phone, $message);
                
                if ($result) {
                    echo '</div>';
                    echo '<div class="result-box result-success">';
                    echo '<strong>✅ SMS Sent Successfully!</strong><br><br>';
                    
                    if (($sms_config['provider'] ?? '') === 'textbelt') {
                        echo '📱 <strong>TextBelt FREE:</strong> SMS should arrive in 5-30 seconds.<br>';
                        echo '⚠️ Remember: Only 1 free SMS per day per phone number.<br><br>';
                    } elseif (($sms_config['provider'] ?? '') === 'semaphore') {
                        echo '📱 <strong>Semaphore:</strong> SMS should arrive in 5-30 seconds.<br><br>';
                    }
                    
                    echo '<strong>Next Steps:</strong><br>';
                    echo '1. Check your phone for the SMS<br>';
                    echo '2. Check <code>sms_sent.log</code> for delivery confirmation<br>';
                    echo '3. If no SMS received, check your provider dashboard<br>';
                } else {
                    echo '</div>';
                    echo '<div class="result-box result-error">';
                    echo '<strong>❌ SMS Sending Failed</strong><br><br>';
                    echo 'Possible reasons:<br>';
                    echo '• API credentials not configured<br>';
                    echo '• No credits remaining (Semaphore)<br>';
                    echo '• Daily quota exceeded (TextBelt free)<br>';
                    echo '• Invalid phone number format<br>';
                    echo '• Network/API error<br><br>';
                    echo 'Check <code>sms_sent.log</code> for error details.';
                }
                echo '</div>';
            }
        }
        ?>

        <div style="margin-top: 30px; padding: 20px; background: #f7fafc; border-radius: 10px;">
            <h3 style="color: #2d3748; margin-bottom: 15px;">📝 How to Check SMS Log</h3>
            <p style="color: #4a5568; line-height: 1.6; margin-bottom: 10px;">
                All SMS attempts (successful or failed) are logged to:
            </p>
            <code style="background: #2d3748; color: #48bb78; padding: 8px 12px; border-radius: 6px; display: block; font-size: 13px;">
                sms_sent.log
            </code>
            <p style="color: #4a5568; line-height: 1.6; margin-top: 15px;">
                <strong>To view the log:</strong><br>
                Open the file in your project root directory, or check the server error log for SMS-related messages.
            </p>
        </div>

        <?php if (!($sms_config['enabled'] ?? false)): ?>
        <div style="margin-top: 20px; padding: 20px; background: #fff5f5; border-left: 4px solid #e53e3e; border-radius: 8px;">
            <h3 style="color: #c53030; margin-bottom: 10px;">🔧 Enable SMS Sending</h3>
            <p style="color: #742a2a; line-height: 1.6;">
                Edit <code>config/sms_config.php</code> and set <code>'enabled' => true</code> to activate real SMS sending.
            </p>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
