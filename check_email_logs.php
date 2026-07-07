<?php
/**
 * Check Email Error Logs
 */

echo "<h2>Email Error Log Check</h2>";
echo "<hr>";

// Check Apache error log
$apache_log = 'C:\\xampp\\apache\\logs\\error.log';
if (file_exists($apache_log)) {
    echo "<h3>Apache Error Log (Last 50 lines):</h3>";
    echo "<pre style='background:#f5f5f5;padding:15px;border:1px solid #ccc;max-height:400px;overflow-y:auto;'>";
    $lines = file($apache_log);
    $recent = array_slice($lines, -50);
    
    // Filter for email/SMTP related errors
    $filtered = array_filter($recent, function($line) {
        return stripos($line, 'email') !== false || 
               stripos($line, 'smtp') !== false || 
               stripos($line, 'otp') !== false ||
               stripos($line, 'phpmailer') !== false ||
               stripos($line, 'mail') !== false;
    });
    
    if (empty($filtered)) {
        echo "No email-related errors found in recent logs.\n";
        echo "\nShowing last 10 lines of error log:\n\n";
        echo implode('', array_slice($lines, -10));
    } else {
        echo implode('', $filtered);
    }
    echo "</pre>";
} else {
    echo "<p style='color:red;'>Apache error log not found at: {$apache_log}</p>";
}

echo "<hr>";

// Check PHP error log
$php_log = ini_get('error_log');
if ($php_log && file_exists($php_log)) {
    echo "<h3>PHP Error Log (Last 30 lines):</h3>";
    echo "<p>Log file: <code>{$php_log}</code></p>";
    echo "<pre style='background:#f5f5f5;padding:15px;border:1px solid #ccc;max-height:400px;overflow-y:auto;'>";
    $lines = file($php_log);
    echo implode('', array_slice($lines, -30));
    echo "</pre>";
} else {
    echo "<p>PHP error log: <code>{$php_log}</code> (file not found or not configured)</p>";
}

echo "<hr>";
echo "<h3>Next Steps:</h3>";
echo "<ol>";
echo "<li><a href='test_email_otp.php' target='_blank'>Run Email Test</a> - Test OTP email sending</li>";
echo "<li>Check if Gmail App Password is correct: <code>ojgyravyufedqgfl</code></li>";
echo "<li>Verify 2-Step Verification is enabled on Gmail account</li>";
echo "<li>Generate new App Password at: <a href='https://myaccount.google.com/apppasswords' target='_blank'>Google App Passwords</a></li>";
echo "</ol>";
