<?php
/**
 * Force Send Email Test - Direct PHPMailer Test
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/includes/PHPMailer/src/Exception.php';
require_once __DIR__ . '/includes/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/includes/PHPMailer/src/SMTP.php';

$test_email = 'cabahug.amiedamas@gmail.com';
$test_otp = '999888';

echo "<h2>FORCE EMAIL TEST - Direct PHPMailer</h2>";
echo "<p>Attempting to send OTP to: <strong>{$test_email}</strong></p>";
echo "<p>OTP Code: <strong>{$test_otp}</strong></p>";
echo "<hr>";

try {
    $mail = new PHPMailer(true);
    
    // Server settings
    $mail->SMTPDebug = 0; // Disable verbose debug output for cleaner display
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'christianval0813@gmail.com';
    $mail->Password   = 'ojgyravyufedqgfl'; // App Password without spaces
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;
    $mail->Timeout    = 30;
    
    // Bypass SSL verification
    $mail->SMTPOptions = array(
        'ssl' => array(
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        )
    );
    
    // Recipients
    $mail->setFrom('christianval0813@gmail.com', 'Petron Management System');
    $mail->addAddress($test_email);
    
    // Content
    $mail->isHTML(true);
    $mail->Subject = 'Password Reset OTP - Petron System TEST';
    $mail->Body    = "
    <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;border:2px solid #002F6C;border-radius:10px;'>
        <div style='background:#002F6C;color:white;padding:20px;text-align:center;border-radius:8px;'>
            <h1 style='margin:0;'>PETRON SYSTEM</h1>
            <p style='margin:5px 0 0;'>Password Reset OTP</p>
        </div>
        <div style='padding:30px 20px;'>
            <h2 style='color:#002F6C;'>Your OTP Code:</h2>
            <div style='background:#f0f0f0;padding:20px;text-align:center;border-radius:8px;margin:20px 0;'>
                <span style='font-size:36px;font-weight:bold;letter-spacing:8px;color:#002F6C;font-family:monospace;'>{$test_otp}</span>
            </div>
            <p style='color:#666;'>This OTP will expire in 5 minutes.</p>
            <p style='color:#666;font-size:12px;'>If you did not request this, please ignore this email.</p>
        </div>
        <div style='background:#f5f5f5;padding:15px;text-align:center;border-radius:8px;font-size:12px;color:#666;'>
            <p style='margin:0;'>&copy; 2026 Petron Station Management System</p>
        </div>
    </div>
    ";
    $mail->AltBody = "Your OTP code is: {$test_otp}\n\nThis OTP will expire in 5 minutes.";
    
    echo "<p style='color:blue;'>⏳ Sending email...</p>";
    
    $result = $mail->send();
    
    if ($result) {
        echo "<div style='background:#d4edda;border:2px solid #28a745;padding:20px;border-radius:8px;margin:20px 0;'>";
        echo "<h3 style='color:#155724;margin-top:0;'>✅ SUCCESS!</h3>";
        echo "<p style='color:#155724;'><strong>Email sent successfully to {$test_email}</strong></p>";
        echo "<p style='color:#155724;'>Please check your email inbox (and spam/junk folder)</p>";
        echo "<p style='color:#155724;'>OTP Code sent: <strong>{$test_otp}</strong></p>";
        echo "</div>";
    } else {
        echo "<div style='background:#f8d7da;border:2px solid #dc3545;padding:20px;border-radius:8px;margin:20px 0;'>";
        echo "<h3 style='color:#721c24;margin-top:0;'>❌ FAILED</h3>";
        echo "<p style='color:#721c24;'>Could not send email</p>";
        echo "<p style='color:#721c24;'>Error: " . htmlspecialchars($mail->ErrorInfo) . "</p>";
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<div style='background:#f8d7da;border:2px solid #dc3545;padding:20px;border-radius:8px;margin:20px 0;'>";
    echo "<h3 style='color:#721c24;margin-top:0;'>❌ EXCEPTION</h3>";
    echo "<p style='color:#721c24;'><strong>Message:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    if (isset($mail)) {
        echo "<p style='color:#721c24;'><strong>Mailer Error:</strong> " . htmlspecialchars($mail->ErrorInfo) . "</p>";
    }
    echo "</div>";
    
    echo "<h3>Debugging Information:</h3>";
    echo "<ul>";
    echo "<li>PHP OpenSSL: " . (extension_loaded('openssl') ? '✓ Enabled' : '✗ Disabled') . "</li>";
    echo "<li>PHP Version: " . phpversion() . "</li>";
    echo "<li>SMTP Host: smtp.gmail.com:587</li>";
    echo "<li>Username: christianval0813@gmail.com</li>";
    echo "</ul>";
}

echo "<hr>";
echo "<h3>Troubleshooting Steps:</h3>";
echo "<ol>";
echo "<li><strong>Gmail App Password Issue:</strong> The current password might be expired or incorrect</li>";
echo "<li><strong>Generate NEW App Password:</strong>";
echo "<ul>";
echo "<li>Go to: <a href='https://myaccount.google.com/apppasswords' target='_blank'>https://myaccount.google.com/apppasswords</a></li>";
echo "<li>Sign in with: christianval0813@gmail.com</li>";
echo "<li>Make sure 2-Step Verification is enabled</li>";
echo "<li>Click 'Select app' → Choose 'Mail'</li>";
echo "<li>Click 'Select device' → Choose 'Windows Computer'</li>";
echo "<li>Click 'Generate'</li>";
echo "<li>Copy the 16-character code (example: abcd efgh ijkl mnop)</li>";
echo "<li>Remove spaces and update in config/email_config.php</li>";
echo "</ul>";
echo "</li>";
echo "<li><strong>Update the password in:</strong> <code>config/email_config.php</code> line 7</li>";
echo "<li><strong>Test again</strong> by refreshing this page</li>";
echo "</ol>";

echo "<hr>";
echo "<p><a href='public/forgot_password.php'>← Back to Forgot Password</a></p>";
