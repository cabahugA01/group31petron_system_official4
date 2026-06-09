<?php
/**
 * DEBUG SMS Configuration
 * Run: http://localhost/group31petron_system_official4/debug_sms.php
 */

header('Content-Type: text/html; charset=utf-8');

echo "<!DOCTYPE html><html><head><title>SMS Debug</title>";
echo "<style>body{font-family:monospace;padding:20px;background:#f0f0f0;}";
echo ".box{background:white;padding:20px;margin:10px 0;border-radius:8px;border-left:4px solid #667eea;}";
echo ".success{border-left-color:#38a169;background:#c6f6d5;}";
echo ".error{border-left-color:#e53e3e;background:#fed7d7;}";
echo "h2{margin-top:0;}</style></head><body>";

echo "<h1>🔍 SMS Configuration Debug</h1>";

// Check if config file exists
echo "<div class='box'>";
echo "<h2>Step 1: Check Config File</h2>";
$config_file = __DIR__ . '/config/sms_config.php';
if (file_exists($config_file)) {
    echo "✅ Config file exists: <code>{$config_file}</code><br>";
    echo "File size: " . filesize($config_file) . " bytes<br>";
    echo "Last modified: " . date('Y-m-d H:i:s', filemtime($config_file));
} else {
    echo "❌ Config file NOT FOUND!";
}
echo "</div>";

// Load config
echo "<div class='box'>";
echo "<h2>Step 2: Load Configuration</h2>";
require_once $config_file;
echo "<pre>";
echo "Provider: " . ($sms_config['provider'] ?? 'NOT SET') . "\n";
echo "Enabled: " . (($sms_config['enabled'] ?? false) ? 'TRUE ✅' : 'FALSE ❌') . "\n";
echo "TextBelt Key: " . ($sms_config['textbelt_key'] ?? 'NOT SET') . "\n";
echo "\nFull config:\n";
print_r($sms_config);
echo "</pre>";
echo "</div>";

// Check if sendSMS function exists
echo "<div class='box'>";
echo "<h2>Step 3: Check SMS Functions</h2>";
require_once __DIR__ . '/config/email_config.php';
if (function_exists('sendSMS')) {
    echo "✅ sendSMS() function exists<br>";
} else {
    echo "❌ sendSMS() function NOT FOUND<br>";
}
if (function_exists('sendTextBeltSMS')) {
    echo "✅ sendTextBeltSMS() function exists<br>";
} else {
    echo "❌ sendTextBeltSMS() function NOT FOUND<br>";
}
if (function_exists('sendSemaphoreSMS')) {
    echo "✅ sendSemaphoreSMS() function exists<br>";
} else {
    echo "❌ sendSemaphoreSMS() function NOT FOUND<br>";
}
echo "</div>";

// Test SMS sending logic (without actually sending)
echo "<div class='box'>";
echo "<h2>Step 4: Test SMS Logic</h2>";
$test_phone = '09123456789';
$test_message = 'Test message';

echo "Testing with:<br>";
echo "Phone: {$test_phone}<br>";
echo "Message: {$test_message}<br><br>";

echo "Logic flow:<br>";
echo "1. Load config... ";
$sms_config_file = __DIR__ . '/config/sms_config.php';
if (file_exists($sms_config_file)) {
    require $sms_config_file;
    echo "✅ Loaded<br>";
} else {
    echo "❌ Failed<br>";
}

echo "2. Check provider... ";
$provider = $sms_config['provider'] ?? 'NOT SET';
echo "<strong>{$provider}</strong><br>";

echo "3. Check enabled... ";
$enabled = $sms_config['enabled'] ?? false;
echo "<strong>" . ($enabled ? 'TRUE ✅' : 'FALSE ❌') . "</strong><br>";

echo "4. Determine action... ";
if ($enabled) {
    if ($provider === 'textbelt') {
        echo "<strong style='color:green;'>WILL CALL sendTextBeltSMS()</strong> ✅<br>";
    } elseif ($provider === 'semaphore') {
        $api_key = $sms_config['api_key'] ?? '';
        if ($api_key !== 'YOUR_SEMAPHORE_API_KEY_HERE' && !empty($api_key)) {
            echo "<strong style='color:green;'>WILL CALL sendSemaphoreSMS()</strong> ✅<br>";
        } else {
            echo "<strong style='color:orange;'>FALLBACK to SIMULATED (no API key)</strong> ⚠️<br>";
        }
    } else {
        echo "<strong style='color:orange;'>FALLBACK to SIMULATED (unknown provider)</strong> ⚠️<br>";
    }
} else {
    echo "<strong style='color:red;'>FALLBACK to SIMULATED (disabled)</strong> ❌<br>";
}
echo "</div>";

// Actual send test
echo "<div class='box'>";
echo "<h2>Step 5: Actual SMS Send Test</h2>";
echo "<form method='POST'>";
echo "Phone: <input type='text' name='phone' value='09123456789' style='padding:8px;margin:10px 0;'><br>";
echo "Message: <input type='text' name='message' value='Test SMS' style='padding:8px;margin:10px 0;width:300px;'><br>";
echo "<button type='submit' name='test' style='padding:10px 20px;background:#667eea;color:white;border:none;border-radius:5px;cursor:pointer;'>Send Test SMS</button>";
echo "</form>";

if (isset($_POST['test'])) {
    $phone = $_POST['phone'] ?? '';
    $message = $_POST['message'] ?? '';
    
    echo "<div style='margin-top:20px;padding:15px;background:#fff5f5;border-left:4px solid #e53e3e;'>";
    echo "<strong>Sending SMS...</strong><br><br>";
    
    // Enable error reporting
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    
    $result = sendSMS($phone, $message);
    
    if ($result) {
        echo "<div style='color:green;font-weight:bold;'>✅ sendSMS() returned TRUE</div>";
    } else {
        echo "<div style='color:red;font-weight:bold;'>❌ sendSMS() returned FALSE</div>";
    }
    
    echo "<br><strong>Check sms_sent.log for details</strong>";
    echo "</div>";
    
    // Show last log entry
    $log_file = __DIR__ . '/sms_sent.log';
    if (file_exists($log_file)) {
        $log_lines = file($log_file);
        $last_line = end($log_lines);
        echo "<div style='margin-top:10px;padding:15px;background:#f7fafc;'>";
        echo "<strong>Last log entry:</strong><br>";
        echo "<code>{$last_line}</code>";
        echo "</div>";
    }
}
echo "</div>";

// PHP Info
echo "<div class='box'>";
echo "<h2>Step 6: PHP Environment</h2>";
echo "PHP Version: " . phpversion() . "<br>";
echo "cURL Enabled: " . (function_exists('curl_version') ? '✅ YES' : '❌ NO') . "<br>";
if (function_exists('curl_version')) {
    $curl_version = curl_version();
    echo "cURL Version: " . $curl_version['version'] . "<br>";
    echo "SSL Version: " . $curl_version['ssl_version'] . "<br>";
}
echo "</div>";

echo "</body></html>";
?>
