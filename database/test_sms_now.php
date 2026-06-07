<?php
/**
 * Quick SMS Test Script
 * Tests if SMS sending is properly configured
 */

require_once __DIR__ . '/../config/email_config.php';
require_once __DIR__ . '/../config/sms_config.php';

echo "═══════════════════════════════════════════════════════════\n";
echo "  PETRON SMS TESTING - CURRENT STATUS\n";
echo "═══════════════════════════════════════════════════════════\n\n";

// Check SMS config
if (!isset($sms_config)) {
    echo "❌ ERROR: SMS config not loaded!\n";
    exit(1);
}

echo "📱 SMS Configuration Status:\n";
echo "   Provider: " . ($sms_config['provider'] ?? 'N/A') . "\n";
echo "   API Key: " . (($sms_config['api_key'] ?? '') === 'YOUR_SEMAPHORE_API_KEY_HERE' ? '❌ NOT SET (placeholder)' : '✅ SET') . "\n";
echo "   Enabled: " . ($sms_config['enabled'] ? '✅ TRUE (Real SMS)' : '❌ FALSE (Simulated mode)') . "\n";
echo "   Sender Name: " . ($sms_config['sender_name'] ?? 'N/A') . "\n\n";

// Determine mode
$api_key = $sms_config['api_key'] ?? '';
$enabled = $sms_config['enabled'] ?? false;
$is_real_mode = ($enabled && !empty($api_key) && $api_key !== 'YOUR_SEMAPHORE_API_KEY_HERE');

if ($is_real_mode) {
    echo "🚀 MODE: REAL SMS (Semaphore API)\n";
    echo "   SMS will be sent via Semaphore API\n";
    echo "   Make sure you have credits loaded!\n\n";
} else {
    echo "⚠️  MODE: SIMULATED (Log File)\n";
    echo "   SMS will be written to: sms_sent.log\n";
    echo "   No actual SMS will be sent\n\n";
    
    if (!$enabled) {
        echo "❌ REASON: 'enabled' is set to FALSE in sms_config.php\n";
    }
    if (empty($api_key) || $api_key === 'YOUR_SEMAPHORE_API_KEY_HERE') {
        echo "❌ REASON: API key not configured (still placeholder)\n";
    }
    echo "\n";
}

// Test SMS sending
echo "───────────────────────────────────────────────────────────\n";
echo "  TESTING SMS SEND FUNCTION\n";
echo "───────────────────────────────────────────────────────────\n\n";

// Test phone number (replace with your actual test number)
$test_phone = '09916105744'; // Stafftest's phone from database
$test_otp = sprintf("%06d", random_int(0, 999999));
$test_message = "Your Petron TEST OTP code is {$test_otp}. This is a test message.";

echo "📞 Test Phone: {$test_phone}\n";
echo "🔢 Test OTP: {$test_otp}\n";
echo "💬 Message: {$test_message}\n\n";

echo "Sending...\n";
$result = sendSMS($test_phone, $test_message);

if ($result) {
    echo "✅ SMS SEND: SUCCESS\n\n";
    
    if ($is_real_mode) {
        echo "🎉 Real SMS sent via Semaphore!\n";
        echo "   Check your phone: {$test_phone}\n";
    } else {
        echo "📝 Simulated SMS logged successfully\n";
        $log_file = __DIR__ . '/../sms_sent.log';
        echo "   Check log file: {$log_file}\n";
        
        // Show last line of log
        if (file_exists($log_file)) {
            $log_content = file($log_file);
            $last_line = end($log_content);
            echo "\n   Last log entry:\n   {$last_line}\n";
        }
    }
} else {
    echo "❌ SMS SEND: FAILED\n\n";
    echo "Check error logs for details\n";
}

echo "\n═══════════════════════════════════════════════════════════\n";
echo "  HOW TO ENABLE REAL SMS\n";
echo "═══════════════════════════════════════════════════════════\n\n";

if (!$is_real_mode) {
    echo "1. Go to https://semaphore.co/\n";
    echo "2. Sign up and verify account\n";
    echo "3. Load credits (minimum ₱100)\n";
    echo "4. Get API key from dashboard\n";
    echo "5. Edit: config/sms_config.php\n";
    echo "   - Set 'api_key' to your Semaphore API key\n";
    echo "   - Set 'enabled' to true\n";
    echo "6. Run this test again: php database/test_sms_now.php\n\n";
} else {
    echo "✅ Real SMS is already enabled!\n";
    echo "   You should receive SMS on phone: {$test_phone}\n\n";
}

echo "═══════════════════════════════════════════════════════════\n\n";
?>
