<?php
// Email Configuration for Petron System
$email_config = [
    'host' => 'smtp.gmail.com',        // SMTP server
    'port' => 587,                   // SMTP port
    'username' => 'christianval0813@gmail.com', // Your Gmail
    'password' => 'ojgy ravy ufed qgfl',   // App password (not regular password)
    'from_email' => 'christianval0813@gmail.com',
    'from_name' => 'Petron Management System',
    'encryption' => 'tls'
];

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../includes/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../includes/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../includes/PHPMailer/src/SMTP.php';

// Function to send password reset OTP email
function sendPasswordResetOTP($to_email, $otp) {
    global $email_config;
    
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $email_config['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $email_config['username'];
        $mail->Password = $email_config['password'];
        $mail->SMTPSecure = $email_config['encryption'];
        $mail->Port = $email_config['port'];
        
        $mail->setFrom($email_config['from_email'], $email_config['from_name']);
        $mail->addAddress($to_email);
        
        $mail->isHTML(true);
        $mail->Subject = 'Password Reset OTP - Petron Management System';
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #dee2e6;'>
                <div style='background: linear-gradient(135deg, #002F6C 0%, #004a9e 100%); color: white; padding: 30px 20px; text-align: center;'>
                    <img src='https://i.imgur.com/your-petron-logo.png' alt='Petron Logo' style='height: 60px; margin-bottom: 15px;' />
                    <h1 style='margin: 0; font-size: 28px; font-weight: 700;'>Petron POS System</h1>
                    <p style='margin: 5px 0 0 0; font-size: 14px; opacity: 0.9;'>Station Management System</p>
                </div>
                <div style='padding: 40px 30px; background-color: #ffffff;'>
                    <h2 style='color: #002F6C; margin-top: 0; font-size: 24px; font-weight: 600;'>Password Reset Request</h2>
                    <p style='color: #333; line-height: 1.6;'>Hello,</p>
                    <p style='color: #333; line-height: 1.6;'>You requested to reset your password for the Petron Management System.</p>
                    <p style='color: #333; line-height: 1.6;'>Please use the following 6-digit OTP (One-Time Password) to reset your password:</p>
                    <div style='background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); padding: 25px; border-radius: 8px; margin: 30px 0; border-left: 5px solid #002F6C; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1);'>
                        <span style='font-size: 36px; font-weight: 700; letter-spacing: 8px; color: #002F6C; font-family: monospace;'>$otp</span>
                    </div>
                    <div style='background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                        <p style='margin: 0; color: #856404; font-weight: 600;'>⏱ This OTP will expire in 5 minutes.</p>
                    </div>
                    <p style='color: #6c757d; font-size: 14px; line-height: 1.6;'>If you didn't request this, please ignore this email and your password will remain unchanged.</p>
                </div>
                <div style='background-color: #002F6C; color: white; padding: 25px 20px; text-align: center; font-size: 13px;'>
                    <p style='margin: 0 0 8px 0; opacity: 0.9;'>This is an automated message. Please do not reply to this email.</p>
                    <p style='margin: 0; font-weight: 600;'>&copy; 2026 Petron Management System. All rights reserved.</p>
                </div>
            </div>
        ";
        
        return $mail->send();
        
    } catch (Exception $e) {
        error_log("Email sending failed: " . $mail->ErrorInfo);
        return false;
    }
}

// Function to send admin credentials email
function sendAdminCredentialsEmail($to_email, $admin_name, $station_name, $username, $password, $created_by_role = 'Admin') {
    global $email_config;

    // Normalize creator label for display
    $creator_label = ucfirst(strtolower($created_by_role));
    if (strtolower($created_by_role) === 'superadmin') {
        $creator_label = 'Super Admin';
    }

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $email_config['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $email_config['username'];
        $mail->Password = $email_config['password'];
        $mail->SMTPSecure = $email_config['encryption'];
        $mail->Port = $email_config['port'];

        $mail->setFrom($email_config['from_email'], $email_config['from_name']);
        $mail->addAddress($to_email);

        $mail->isHTML(true);
        $mail->Subject = 'Petron Station Management – Account Credentials';
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 650px; margin: 0 auto; border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;'>
                <!-- Header with Logo -->
                <div style='background: linear-gradient(135deg, #002F6C 0%, #004a9e 100%); color: white; padding: 40px 30px; text-align: center;'>
                    <img src='https://i.imgur.com/your-petron-logo.png' alt='Petron Logo' style='height: 70px; margin-bottom: 20px;' />
                    <h1 style='margin: 0; font-size: 32px; font-weight: 700; text-shadow: 0 2px 4px rgba(0,0,0,0.2);'>Petron Station Management System</h1>
                    <p style='margin: 10px 0 0 0; font-size: 15px; opacity: 0.95;'>Professional Station Operations Platform</p>
                </div>
                
                <!-- Main Content -->
                <div style='padding: 40px 35px; background-color: #ffffff;'>
                    <h2 style='color: #002F6C; margin-top: 0; font-size: 26px; font-weight: 600; border-bottom: 3px solid #002F6C; padding-bottom: 12px;'>
                        🎉 Your Account Has Been Created
                    </h2>
                    
                    <p style='color: #333; line-height: 1.7; font-size: 16px;'>
                        Dear <strong style='color: #002F6C;'>$admin_name</strong>,
                    </p>
                    
                    <p style='color: #333; line-height: 1.7; font-size: 16px;'>
                        Your account has been successfully created by the <strong>$creator_label</strong> of <strong style='color: #002F6C;'>$station_name</strong>.
                    </p>
                    
                    <!-- Credentials Box -->
                    <div style='background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); padding: 30px; border-radius: 10px; margin: 30px 0; border: 2px solid #002F6C; box-shadow: 0 4px 8px rgba(0,47,108,0.1);'>
                        <h3 style='margin: 0 0 20px 0; color: #002F6C; font-size: 20px; font-weight: 700;'>📋 Your Login Credentials</h3>
                        
                        <div style='background-color: white; padding: 15px; border-radius: 6px; margin-bottom: 12px; border-left: 4px solid #28a745;'>
                            <p style='margin: 0; color: #666; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px;'>STATION</p>
                            <p style='margin: 5px 0 0 0; color: #002F6C; font-size: 18px; font-weight: 700;'>$station_name</p>
                        </div>
                        
                        <div style='background-color: white; padding: 15px; border-radius: 6px; margin-bottom: 12px; border-left: 4px solid #007bff;'>
                            <p style='margin: 0; color: #666; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px;'>USERNAME (EMAIL)</p>
                            <p style='margin: 5px 0 0 0; color: #002F6C; font-size: 18px; font-weight: 700; word-break: break-all;'>$username</p>
                        </div>
                        
                        <div style='background-color: white; padding: 15px; border-radius: 6px; border-left: 4px solid #ffc107;'>
                            <p style='margin: 0; color: #666; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px;'>TEMPORARY PASSWORD</p>
                            <p style='margin: 5px 0 0 0; font-family: monospace; font-size: 20px; font-weight: 700; color: #dc3545; letter-spacing: 2px; background-color: #fff3cd; padding: 10px; border-radius: 5px; display: inline-block;'>$password</p>
                        </div>
                    </div>
                    
                    <!-- Security Notice -->
                    <div style='background-color: #fff3cd; border-left: 5px solid #ffc107; padding: 20px; border-radius: 8px; margin: 25px 0;'>
                        <p style='margin: 0; color: #856404; font-weight: 700; font-size: 16px; line-height: 1.6;'>
                            🔐 <strong>IMPORTANT SECURITY NOTICE:</strong>
                        </p>
                        <ul style='margin: 10px 0 0 20px; color: #856404; line-height: 1.7;'>
                            <li>You <strong>MUST change your password</strong> upon first login</li>
                            <li>Never share your credentials with anyone</li>
                            <li>Password must contain: uppercase, lowercase, number, and special character</li>
                        </ul>
                    </div>
                    
                    <!-- Login Button -->
                    <div style='text-align: center; margin: 35px 0;'>
                        <a href='http://localhost/group31petron_system_official4/public/login.php' 
                           style='background: linear-gradient(135deg, #002F6C 0%, #004a9e 100%); 
                                  color: white; 
                                  padding: 16px 40px; 
                                  text-decoration: none; 
                                  border-radius: 8px; 
                                  display: inline-block; 
                                  font-weight: 700; 
                                  font-size: 17px;
                                  box-shadow: 0 4px 8px rgba(0,47,108,0.3);
                                  text-transform: uppercase;
                                  letter-spacing: 1px;'>
                            🚀 Log In to System
                        </a>
                    </div>
                    
                    <p style='color: #6c757d; font-size: 14px; line-height: 1.7; margin-top: 30px; padding-top: 20px; border-top: 1px solid #dee2e6;'>
                        If you didn't expect this email or have any questions, please contact your system administrator immediately.
                    </p>
                </div>
                
                <!-- Footer -->
                <div style='background-color: #002F6C; color: white; padding: 30px 25px; text-align: center;'>
                    <p style='margin: 0 0 10px 0; opacity: 0.9; font-size: 14px;'>
                        This is an automated message from Petron Station Management System
                    </p>
                    <p style='margin: 0 0 10px 0; opacity: 0.9; font-size: 13px;'>
                        Please do not reply to this email
                    </p>
                    <p style='margin: 0; font-weight: 700; font-size: 14px;'>
                        &copy; 2026 Petron Management System. All rights reserved.
                    </p>
                </div>
            </div>
        ";

        return $mail->send();

    } catch (Exception $e) {
        error_log("Credentials email sending failed: " . $mail->ErrorInfo);
        return false;
    }
}

// Function to generate secure random password
if (!function_exists('generateSecurePassword')) {
function generateSecurePassword($length = 12) {
    // Allowed symbols: _ . - ! @ #
    $upper   = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $lower   = 'abcdefghijklmnopqrstuvwxyz';
    $digits  = '0123456789';
    $symbols = '_.-!@#';
    $all     = $upper . $lower . $digits . $symbols;

    // Guarantee at least one of each required type
    $password  = $upper[random_int(0, strlen($upper) - 1)];
    $password .= $lower[random_int(0, strlen($lower) - 1)];
    $password .= $digits[random_int(0, strlen($digits) - 1)];
    $password .= $symbols[random_int(0, strlen($symbols) - 1)];

    // Fill remaining characters
    for ($i = 4; $i < $length; $i++) {
        $password .= $all[random_int(0, strlen($all) - 1)];
    }

    return str_shuffle($password);
}
}

// Function to send SMS (simulated or real based on config)
if (!function_exists('sendSMS')) {
function sendSMS($to_phone, $message) {
    // IMPORTANT: Use require (not require_once) to always get fresh config
    $sms_config_file = __DIR__ . '/sms_config.php';
    if (file_exists($sms_config_file)) {
        require $sms_config_file; // ← Always reload config for latest settings
        $provider = $sms_config['provider'] ?? 'semaphore';
        $enabled = $sms_config['enabled'] ?? false;
    } else {
        $provider = 'semaphore';
        $enabled = false;
    }
    
    // Check if SMS is configured and enabled
    if ($enabled) {
        // Try to send via configured provider
        if ($provider === 'twilio') {
            // Use Twilio SMS (has FREE trial)
            $account_sid = $sms_config['account_sid'] ?? '';
            $auth_token = $sms_config['auth_token'] ?? '';
            $from_number = $sms_config['from_number'] ?? '';
            
            if (!empty($account_sid) && !empty($auth_token) && !empty($from_number) &&
                $account_sid !== 'YOUR_TWILIO_ACCOUNT_SID_HERE') {
                return sendTwilioSMS($to_phone, $message, $account_sid, $auth_token, $from_number);
            }
        } elseif ($provider === 'semaphore') {
            // Use Semaphore SMS (Philippines)
            $api_key = $sms_config['api_key'] ?? '';
            if (!empty($api_key) && $api_key !== 'YOUR_SEMAPHORE_API_KEY_HERE') {
                return sendSemaphoreSMS($to_phone, $message, $api_key);
            }
        } elseif ($provider === 'movider') {
            // Use Movider SMS (Philippines)
            $api_key = $sms_config['movider_api_key'] ?? '';
            $api_secret = $sms_config['movider_api_secret'] ?? '';
            if (!empty($api_key) && !empty($api_secret) && 
                $api_key !== 'YOUR_MOVIDER_API_KEY_HERE') {
                return sendMoviderSMS($to_phone, $message, $api_key, $api_secret);
            }
        }
    }
    
    // Fallback to simulated SMS (log file)
    $log_file = __DIR__ . '/../sms_sent.log';
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] TO: {$to_phone} | MSG: {$message} (SIMULATED)\n";
    @file_put_contents($log_file, $log_entry, FILE_APPEND);
    error_log("SMS Sent (Simulated) to {$to_phone}: {$message}");
    return true;
}
}

// Function to send SMS via Twilio API
if (!function_exists('sendTwilioSMS')) {
function sendTwilioSMS($to_phone, $message, $account_sid, $auth_token, $from_number) {
    try {
        // Twilio SMS API endpoint
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$account_sid}/Messages.json";
        
        // Format phone number for Philippines (+63)
        $formatted_phone = $to_phone;
        if (substr($formatted_phone, 0, 1) === '0') {
            $formatted_phone = '+63' . substr($formatted_phone, 1);
        } elseif (substr($formatted_phone, 0, 2) !== '+63' && substr($formatted_phone, 0, 2) !== '63') {
            $formatted_phone = '+63' . $formatted_phone;
        } elseif (substr($formatted_phone, 0, 2) === '63') {
            $formatted_phone = '+' . $formatted_phone;
        }
        
        // Prepare POST data
        $post_data = [
            'From' => $from_number,
            'To' => $formatted_phone,
            'Body' => $message
        ];
        
        // Initialize cURL with Basic Auth
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, "{$account_sid}:{$auth_token}");
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        // Execute request
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        // Check response
        if ($curl_error) {
            error_log("Twilio SMS cURL Error: " . $curl_error);
            return false;
        }
        
        $result = json_decode($response, true);
        
        if ($http_code === 201 || $http_code === 200) {
            // Success!
            error_log("SMS Sent (Twilio) to {$formatted_phone}: {$message}");
            
            // Log to file for backup
            $log_file = __DIR__ . '/../sms_sent.log';
            $timestamp = date('Y-m-d H:i:s');
            $log_entry = "[{$timestamp}] TO: {$formatted_phone} (SUCCESS via Twilio) | MSG: {$message}\n";
            @file_put_contents($log_file, $log_entry, FILE_APPEND);
            
            return true;
        } else {
            // API returned error
            $error_message = isset($result['message']) ? $result['message'] : 'Unknown error';
            error_log("Twilio SMS Error: HTTP {$http_code} - {$error_message}");
            
            // Log failure
            $log_file = __DIR__ . '/../sms_sent.log';
            $timestamp = date('Y-m-d H:i:s');
            $log_entry = "[{$timestamp}] TO: {$to_phone} (FAILED - Twilio: {$error_message}) | MSG: {$message}\n";
            @file_put_contents($log_file, $log_entry, FILE_APPEND);
            
            return false;
        }
        
    } catch (Exception $e) {
        error_log("Twilio SMS Exception: " . $e->getMessage());
        
        // Log failure
        $log_file = __DIR__ . '/../sms_sent.log';
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[{$timestamp}] TO: {$to_phone} (EXCEPTION: {$e->getMessage()}) | MSG: {$message}\n";
        @file_put_contents($log_file, $log_entry, FILE_APPEND);
        
        return false;
    }
}
}

// Function to send real SMS via Semaphore API
if (!function_exists('sendSemaphoreSMS')) {
function sendSemaphoreSMS($to_phone, $message, $api_key) {
    try {
        // Semaphore SMS API endpoint
        $url = 'https://api.semaphore.co/api/v4/messages';
        
        // Format phone number for Semaphore (digits-only, no '+' sign)
        $formatted_phone = preg_replace('/\D/', '', $to_phone);
        
        // Load SMS config to get configured sendername
        $sendername = '';
        $sms_config_file = __DIR__ . '/sms_config.php';
        if (file_exists($sms_config_file)) {
            @include $sms_config_file;
            if (isset($sms_config['sender_name'])) {
                $sendername = trim($sms_config['sender_name']);
            }
        }

        // Prepare POST data
        $post_data = [
            'apikey'  => $api_key,
            'number'  => $formatted_phone,
            'message' => $message,
        ];

        if (!empty($sendername) && strtoupper($sendername) !== 'SEMAPHORE') {
            $post_data['sendername'] = $sendername;
        }
        
        // Initialize cURL
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        // Execute request
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        // If request failed and custom sendername was used, fallback and retry without it
        if (!empty($post_data['sendername']) && ($http_code !== 200 || $curl_error || stripos($response, 'sender') !== false)) {
            error_log("Semaphore SMS failed with sendername '{$post_data['sendername']}' (HTTP {$http_code}). Retrying without sendername...");
            unset($post_data['sendername']);
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);
        }
        
        // Check response
        if ($curl_error) {
            error_log("Semaphore SMS cURL Error: " . $curl_error);
            // Fallback to simulated SMS
            $log_file = __DIR__ . '/../sms_sent.log';
            $timestamp = date('Y-m-d H:i:s');
            $log_entry = "[{$timestamp}] TO: {$to_phone} (FAILED - cURL) | MSG: {$message}\n";
            @file_put_contents($log_file, $log_entry, FILE_APPEND);
            return false;
        }
        
        $result = json_decode($response, true);
        
        if ($http_code === 200 && !empty($result)) {
            // Success!
            error_log("SMS Sent (Semaphore) to {$formatted_phone}: {$message}");
            
            // Also log to file for backup
            $log_file = __DIR__ . '/../sms_sent.log';
            $timestamp = date('Y-m-d H:i:s');
            $log_entry = "[{$timestamp}] TO: {$formatted_phone} (SUCCESS via Semaphore) | MSG: {$message}\n";
            @file_put_contents($log_file, $log_entry, FILE_APPEND);
            
            return true;
        } else {
            // API returned error
            $error_message = isset($result[0]['message']) ? $result[0]['message'] : (isset($result['message']) ? $result['message'] : 'Unknown error');
            error_log("Semaphore SMS Error: HTTP {$http_code} - {$error_message}");
            
            // Log failure
            $log_file = __DIR__ . '/../sms_sent.log';
            $timestamp = date('Y-m-d H:i:s');
            $log_entry = "[{$timestamp}] TO: {$to_phone} (FAILED - API: {$error_message}) | MSG: {$message}\n";
            @file_put_contents($log_file, $log_entry, FILE_APPEND);
            
            return false;
        }
        
    } catch (Exception $e) {
        error_log("Semaphore SMS Exception: " . $e->getMessage());
        
        // Log failure
        $log_file = __DIR__ . '/../sms_sent.log';
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[{$timestamp}] TO: {$to_phone} (EXCEPTION: {$e->getMessage()}) | MSG: {$message}\n";
        @file_put_contents($log_file, $log_entry, FILE_APPEND);
        
        return false;
    }
}
}

// Function to send SMS via TextBelt API (FREE - 1 SMS/day per phone, or paid key)
if (!function_exists('sendTextBeltSMS')) {
function sendTextBeltSMS($to_phone, $message, $api_key = 'textbelt') {
    try {
        // TextBelt SMS API endpoint
        $url = 'https://textbelt.com/text';
        
        // Format phone number - TextBelt accepts international format
        $formatted_phone = $to_phone;
        if (substr($formatted_phone, 0, 1) === '0') {
            // Philippine number starting with 0 → +63
            $formatted_phone = '+63' . substr($formatted_phone, 1);
        } elseif (preg_match('/^[0-9]{10,11}$/', $formatted_phone)) {
            // Just digits, assume Philippine
            $formatted_phone = '+63' . ltrim($formatted_phone, '0');
        }
        
        // Prepare POST data
        $post_data = [
            'phone' => $formatted_phone,
            'message' => $message,
            'key' => $api_key  // 'textbelt' for free (1/day), or your paid key
        ];
        
        // Initialize cURL
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        // Execute request
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        // Check response
        if ($curl_error) {
            error_log("TextBelt SMS cURL Error: " . $curl_error);
            
            // Log failure
            $log_file = __DIR__ . '/../sms_sent.log';
            $timestamp = date('Y-m-d H:i:s');
            $log_entry = "[{$timestamp}] TO: {$to_phone} (FAILED - TextBelt cURL error) | MSG: {$message}\n";
            @file_put_contents($log_file, $log_entry, FILE_APPEND);
            
            return false;
        }
        
        $result = json_decode($response, true);
        
        // TextBelt returns: {"success": true, "textId": "...", "quotaRemaining": X}
        if ($http_code === 200 && isset($result['success']) && $result['success'] === true) {
            // Success!
            $quota = isset($result['quotaRemaining']) ? $result['quotaRemaining'] : 'unknown';
            error_log("SMS Sent (TextBelt) to {$formatted_phone} (Quota: {$quota}): {$message}");
            
            // Log to file
            $log_file = __DIR__ . '/../sms_sent.log';
            $timestamp = date('Y-m-d H:i:s');
            $log_entry = "[{$timestamp}] TO: {$formatted_phone} (SUCCESS via TextBelt, Quota: {$quota}) | MSG: {$message}\n";
            @file_put_contents($log_file, $log_entry, FILE_APPEND);
            
            return true;
        } else {
            // API returned error
            $error_message = isset($result['error']) ? $result['error'] : 'Unknown error';
            error_log("TextBelt SMS Error: {$error_message}");
            
            // Log failure
            $log_file = __DIR__ . '/../sms_sent.log';
            $timestamp = date('Y-m-d H:i:s');
            $log_entry = "[{$timestamp}] TO: {$to_phone} (FAILED - TextBelt: {$error_message}) | MSG: {$message}\n";
            @file_put_contents($log_file, $log_entry, FILE_APPEND);
            
            return false;
        }
        
    } catch (Exception $e) {
        error_log("TextBelt SMS Exception: " . $e->getMessage());
        
        // Log failure
        $log_file = __DIR__ . '/../sms_sent.log';
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[{$timestamp}] TO: {$to_phone} (EXCEPTION: {$e->getMessage()}) | MSG: {$message}\n";
        @file_put_contents($log_file, $log_entry, FILE_APPEND);
        
        return false;
    }
}
}

// Function to send SMS via Movider API (Philippines)
if (!function_exists('sendMoviderSMS')) {
function sendMoviderSMS($to_phone, $message, $api_key, $api_secret) {
    try {
        // Movider SMS API endpoint
        $url = 'https://api.movider.co/v1/sms';
        
        // Format phone number (Philippine format: 639XXXXXXXXX)
        $formatted_phone = preg_replace('/\D/', '', $to_phone);
        if (substr($formatted_phone, 0, 1) === '0') {
            $formatted_phone = '63' . substr($formatted_phone, 1);
        } elseif (substr($formatted_phone, 0, 2) !== '63') {
            $formatted_phone = '63' . $formatted_phone;
        }
        
        // Prepare POST data
        $post_data = [
            'api_key' => $api_key,
            'api_secret' => $api_secret,
            'to' => $formatted_phone,
            'text' => $message
        ];
        
        // Initialize cURL
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        // Execute request
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        // Check response
        if ($curl_error) {
            error_log("Movider SMS cURL Error: " . $curl_error);
            
            // Log failure
            $log_file = __DIR__ . '/../sms_sent.log';
            $timestamp = date('Y-m-d H:i:s');
            $log_entry = "[{$timestamp}] TO: {$to_phone} (FAILED - Movider cURL) | MSG: {$message}\n";
            @file_put_contents($log_file, $log_entry, FILE_APPEND);
            
            return false;
        }
        
        $result = json_decode($response, true);
        
        if ($http_code === 200 && isset($result['phone_number_list'])) {
            // Success!
            error_log("SMS Sent (Movider) to {$formatted_phone}: {$message}");
            
            // Log to file
            $log_file = __DIR__ . '/../sms_sent.log';
            $timestamp = date('Y-m-d H:i:s');
            $log_entry = "[{$timestamp}] TO: {$formatted_phone} (SUCCESS via Movider) | MSG: {$message}\n";
            @file_put_contents($log_file, $log_entry, FILE_APPEND);
            
            return true;
        } else {
            // API returned error
            $error_message = isset($result['message']) ? $result['message'] : 'Unknown error';
            error_log("Movider SMS Error: {$error_message}");
            
            // Log failure
            $log_file = __DIR__ . '/../sms_sent.log';
            $timestamp = date('Y-m-d H:i:s');
            $log_entry = "[{$timestamp}] TO: {$to_phone} (FAILED - Movider: {$error_message}) | MSG: {$message}\n";
            @file_put_contents($log_file, $log_entry, FILE_APPEND);
            
            return false;
        }
        
    } catch (Exception $e) {
        error_log("Movider SMS Exception: " . $e->getMessage());
        
        // Log failure
        $log_file = __DIR__ . '/../sms_sent.log';
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[{$timestamp}] TO: {$to_phone} (EXCEPTION: {$e->getMessage()}) | MSG: {$message}\n";
        @file_put_contents($log_file, $log_entry, FILE_APPEND);
        
        return false;
    }
}
}
?>
