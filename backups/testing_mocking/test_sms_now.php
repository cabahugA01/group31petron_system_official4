<?php
/**
 * QUICK SMS TEST
 * Run: http://localhost/group31petron_system_official4/test_sms_now.php
 */

require_once __DIR__ . '/config/email_config.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Quick SMS Test</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 30px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #333; margin-bottom: 20px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-weight: bold; margin-bottom: 5px; color: #555; }
        input[type="text"] { width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 5px; font-size: 15px; }
        button { background: #667eea; color: white; padding: 12px 30px; border: none; border-radius: 5px; font-size: 16px; font-weight: bold; cursor: pointer; }
        button:hover { background: #5568d3; }
        .result { margin-top: 20px; padding: 15px; border-radius: 5px; }
        .success { background: #c6f6d5; border-left: 4px solid #38a169; color: #22543d; }
        .error { background: #fed7d7; border-left: 4px solid #e53e3e; color: #742a2a; }
        .info { background: #bee3f8; border-left: 4px solid #3182ce; color: #2c5282; }
        pre { background: #f7fafc; padding: 10px; border-radius: 5px; overflow-x: auto; }
    </style>
</head>
<body>
    <div class="container">
        <h1>📱 Quick SMS Test</h1>
        
        <?php
        // Show current config
        $config_file = __DIR__ . '/config/sms_config.php';
        require $config_file;
        
        echo "<div class='info'>";
        echo "<strong>Current Configuration:</strong><br>";
        echo "Provider: <strong>" . ($sms_config['provider'] ?? 'NOT SET') . "</strong><br>";
        echo "Enabled: <strong>" . (($sms_config['enabled'] ?? false) ? '✅ YES' : '❌ NO') . "</strong><br>";
        if (($sms_config['provider'] ?? '') === 'textbelt') {
            echo "TextBelt Key: <strong>" . ($sms_config['textbelt_key'] ?? 'NOT SET') . "</strong><br>";
            echo "<br><em>⚠️ TextBelt FREE: 1 SMS per day per phone number</em>";
        }
        echo "</div>";
        ?>
        
        <form method="POST">
            <div class="form-group">
                <label>Phone Number (09XXXXXXXXX):</label>
                <input type="text" name="phone" value="<?php echo htmlspecialchars($_POST['phone'] ?? '09123456789'); ?>" required pattern="[0-9]{11}">
            </div>
            
            <div class="form-group">
                <label>Message:</label>
                <input type="text" name="message" value="<?php echo htmlspecialchars($_POST['message'] ?? 'Your test OTP is 123456. Valid for 5 minutes.'); ?>" required>
            </div>
            
            <button type="submit" name="send">📤 Send SMS Now</button>
        </form>
        
        <?php
        if (isset($_POST['send'])) {
            $phone = trim($_POST['phone'] ?? '');
            $message = trim($_POST['message'] ?? '');
            
            echo "<div class='result info'>";
            echo "<strong>🔄 Sending SMS...</strong><br>";
            echo "To: {$phone}<br>";
            echo "Message: {$message}<br><br>";
            
            // Enable error display
            error_reporting(E_ALL);
            ini_set('display_errors', 1);
            
            // Send SMS
            $start_time = microtime(true);
            $result = sendSMS($phone, $message);
            $end_time = microtime(true);
            $duration = round(($end_time - $start_time) * 1000, 2);
            
            echo "</div>";
            
            if ($result) {
                echo "<div class='result success'>";
                echo "<strong>✅ SMS Sent Successfully!</strong><br><br>";
                echo "Duration: {$duration}ms<br>";
                echo "Provider: " . ($sms_config['provider'] ?? 'unknown') . "<br><br>";
                
                // Check last log entry
                $log_file = __DIR__ . '/sms_sent.log';
                if (file_exists($log_file)) {
                    $log_lines = file($log_file);
                    $last_line = trim(end($log_lines));
                    
                    if (strpos($last_line, 'SIMULATED') !== false) {
                        echo "<div style='background:#fff5f5;padding:10px;border-left:3px solid #e53e3e;margin-top:10px;'>";
                        echo "<strong>⚠️ WARNING: SMS was SIMULATED!</strong><br>";
                        echo "This means the SMS was logged but not actually sent.<br>";
                        echo "Check configuration in <code>config/sms_config.php</code>";
                        echo "</div>";
                    } elseif (strpos($last_line, 'SUCCESS') !== false) {
                        echo "<strong>📱 Real SMS sent to phone!</strong><br>";
                        echo "Check your phone for the message (5-30 seconds).<br>";
                    } elseif (strpos($last_line, 'FAILED') !== false) {
                        echo "<div style='background:#fff5f5;padding:10px;border-left:3px solid #e53e3e;margin-top:10px;'>";
                        echo "<strong>❌ SMS sending failed!</strong><br>";
                        echo "Check error in log below.";
                        echo "</div>";
                    }
                    
                    echo "<br><strong>Last log entry:</strong><br>";
                    echo "<pre>" . htmlspecialchars($last_line) . "</pre>";
                }
                echo "</div>";
            } else {
                echo "<div class='result error'>";
                echo "<strong>❌ SMS Sending Failed</strong><br>";
                echo "Check error logs for details.";
                echo "</div>";
            }
            
            // Show recent log entries
            $log_file = __DIR__ . '/sms_sent.log';
            if (file_exists($log_file)) {
                echo "<div class='result info'>";
                echo "<strong>📝 Recent SMS Log (last 5 entries):</strong>";
                echo "<pre>";
                $log_lines = file($log_file);
                $recent = array_slice($log_lines, -5);
                foreach ($recent as $line) {
                    echo htmlspecialchars($line);
                }
                echo "</pre>";
                echo "</div>";
            }
        }
        ?>
        
        <div style="margin-top: 30px; padding: 15px; background: #f7fafc; border-radius: 5px;">
            <strong>📖 How to Enable Real SMS:</strong>
            <ol style="margin-top: 10px; line-height: 1.8;">
                <li>Current: TextBelt FREE (1 SMS/day per phone)</li>
                <li>For unlimited: Switch to Semaphore or Movider (see SMS_ENABLED_GUIDE.md)</li>
                <li>Test with debug: <a href="debug_sms.php">debug_sms.php</a></li>
            </ol>
        </div>
    </div>
</body>
</html>
