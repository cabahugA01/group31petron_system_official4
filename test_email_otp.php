<?php
/**  * Test Email OTP Sending with Detailed Debugging  * Run this script to verify OTP emails are working  */  error_reporting(E_ALL);
ini_set('display_errors', 1);  require_once __DIR__ . '/config/email_config.php';  // Test email address
$test_email = 'cabahug.amiedamas@gmail.com';
$test_otp = '123456';  echo "<h2>Testing OTP Email Sending - Detailed Debug</h2>";
echo "<p>Sending test OTP to: <strong>{$test_email}</strong></p>";
echo "<p>Test OTP Code: <strong>{$test_otp}</strong></p>";
echo "<hr>";  // Check email config
echo "<h3>Email Configuration:</h3>";
echo "<pre>";
echo "Host: " . ($email_config['host'] ?? 'NOT SET') . "\n";
echo "Port: " . ($email_config['port'] ?? 'NOT SET') . "\n";
echo "Username: " . ($email_config['username'] ?? 'NOT SET') . "\n";
echo "From Email: " . ($email_config['from_email'] ?? 'NOT SET') . "\n";
echo "Encryption: " . ($email_config['encryption'] ?? 'NOT SET') . "\n";
echo "</pre>";  // Check if PHPMailer exists
$phpmailer_path = __DIR__ . '/includes/PHPMailer/src/PHPMailer.php';
if (file_exists($phpmailer_path)) {  echo "<p> PHPMailer found at: {$phpmailer_path}</p>";
} else {  die("<p style='color:red;'>ERROR: PHPMailer not found at: {$phpmailer_path}</p>");
}  // Test if function exists
if (!function_exists('sendPasswordResetOTP')) {  die("<p style='color:red;'>ERROR: sendPasswordResetOTP function not found!</p>");
}  echo "<p> sendPasswordResetOTP function found</p>";
echo "<hr>";  // Try to send email with detailed error reporting
echo "<h3>Attempting to send email...</h3>";  try {  $result = sendPasswordResetOTP($test_email, $test_otp);  if ($result) {  echo "<p style='color:green;font-weight:bold;font-size:18px;'>SUCCESS! Email sent successfully.</p>";  echo "<p>Check your inbox (and spam/junk folder) for the OTP email at: <strong>{$test_email}</strong></p>";  } else {  echo "<p style='color:red;font-weight:bold;font-size:18px;'>FAILED! Could not send email.</p>";  echo "<p><strong>Possible issues:</strong></p>";  echo "<ul>";  echo "<li>Gmail App Password might be incorrect</li>";  echo "<li>Check if 2-Step Verification is enabled on Gmail account</li>";  echo "<li>Internet connection issue</li>";  echo "<li>Gmail might be blocking the connection</li>";  echo "</ul>";  }
} catch (Exception $e) {  echo "<p style='color:red;font-weight:bold;'>EXCEPTION: " . htmlspecialchars($e->getMessage()) . "</p>";
}  echo "<hr>";  // Show PHP error log location
echo "<h3>Check Error Logs:</h3>";
echo "<p>PHP Error Log: <code>" . ini_get('error_log') . "</code></p>";
echo "<p>Apache Error Log: <code>C:\\xampp\\apache\\logs\\error.log</code></p>";  echo "<hr>";
echo "<p><a href='public/forgot_password.php'>← Back to Forgot Password</a></p>";
