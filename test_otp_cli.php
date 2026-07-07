<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/email_config.php';

$test_email = 'cabahug.amiedamas@gmail.com';
$test_otp   = '654321';

echo "Testing OTP email to: {$test_email}\n";
echo "OTP Code: {$test_otp}\n\n";

$result = sendPasswordResetOTP($test_email, $test_otp);
echo "\nResult: " . ($result ? 'EMAIL_SUCCESS' : 'EMAIL_FAILED') . "\n";
