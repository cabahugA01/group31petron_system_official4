<?php
// Email Configuration for Petron System
$email_config = [
    'host' => 'smtp.gmail.com',        // SMTP server
    'port' => 587,                   // SMTP port
    'username' => 'christianval0813@gmail.com', // Your Gmail
    'password_hash' => 'ojgyravyufedqgfl',   // App password (no spaces)
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
    
    // Enable detailed error reporting
    error_log("=== OTP Email Send Attempt ===");
    error_log("To: {$to_email}");
    error_log("OTP: {$otp}");
    
    try {
        $mail = new PHPMailer(true);
        
        // Enable verbose debug output
        $mail->SMTPDebug = 2; // Show detailed debug info
        $mail->Debugoutput = function($str, $level) {
            error_log("PHPMailer DEBUG [{$level}]: {$str}");
        };
        
        $mail->isSMTP();
        $mail->Host          = $email_config['host'];
        $mail->SMTPAuth      = true;
        $mail->Username      = $email_config['username'];
        $mail->Password      = $email_config['password_hash'];
        $mail->SMTPSecure    = $email_config['encryption'];
        $mail->Port          = $email_config['port'];
        $mail->Timeout       = 30;
        $mail->SMTPKeepAlive = false;
        $mail->CharSet       = 'UTF-8';
        
        error_log("SMTP Config - Host: {$mail->Host}, Port: {$mail->Port}, User: {$mail->Username}");
        
        $mail->setFrom($email_config['from_email'], $email_config['from_name']);
        $mail->addAddress($to_email);
        
        // Bypass SSL certificate verification for local environments
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        
        $mail->isHTML(true);
        $mail->Subject = 'Password Reset OTP - Petron Management System';

        // Embed logo via standard AddEmbeddedImage to maximize deliverability
        $logo_path = __DIR__ . '/../assets/img/Petron Logo.png';
        if (file_exists($logo_path)) {
            $mail->AddEmbeddedImage($logo_path, 'petron_logo_otp', 'Petron Logo.png');
            $logo_src = 'cid:petron_logo_otp';
        } else {
            $logo_src = '';
            error_log("Logo not found at: {$logo_path}");
        }
        
        $logo_img = $logo_src
            ? "<img src='{$logo_src}' alt='Petron' style='height:72px;margin-bottom:12px;display:block;margin-left:auto;margin-right:auto;' />"
            : "<div style='font-size:32px;font-weight:900;color:#fff;margin-bottom:8px;'>PETRON</div>";

        $mail->Body = "
            <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;border:1px solid #dee2e6;border-radius:4px;overflow:hidden;'>
                <div style='background:linear-gradient(135deg,#002F6C 0%,#004a9e 100%);color:white;padding:36px 20px 28px;text-align:center;'>
                    {$logo_img}
                    <h1 style='margin:0;font-size:22px;font-weight:700;letter-spacing:0.5px;opacity:0.95;'>Station Management System</h1>
                </div>
                <div style='padding:40px 30px;background-color:#ffffff;'>
                    <h2 style='color:#002F6C;margin-top:0;font-size:22px;font-weight:700;'>Password Reset Request</h2>
                    <p style='color:#333;line-height:1.7;'>Hello,</p>
                    <p style='color:#333;line-height:1.7;'>You requested to reset your password for the <strong>Petron Station Management System</strong>.</p>
                    <p style='color:#333;line-height:1.7;'>Use the following 6-digit OTP to reset your password:</p>
                    <div style='background:linear-gradient(135deg,#f8f9fa 0%,#e9ecef 100%);padding:28px;border-radius:8px;margin:28px 0;border-left:5px solid #002F6C;text-align:center;box-shadow:0 2px 4px rgba(0,0,0,0.08);'>
                        <span style='font-size:40px;font-weight:800;letter-spacing:10px;color:#002F6C;font-family:monospace;'>{$otp}</span>
                    </div>
                    <div style='background-color:#fff3cd;border-left:4px solid #ffc107;padding:14px 16px;border-radius:5px;margin:20px 0;'>
                        <p style='margin:0;color:#856404;font-weight:700;'>&#9200; This OTP will expire in <strong>5 minutes</strong>.</p>
                    </div>
                    <p style='color:#6c757d;font-size:13px;line-height:1.6;'>If you did not request this, please ignore this email. Your password will remain unchanged.</p>
                </div>
                <div style='background-color:#002F6C;color:rgba(255,255,255,0.85);padding:22px 20px;text-align:center;font-size:12px;'>
                    <p style='margin:0 0 6px;'>This is an automated message. Please do not reply.</p>
                    <p style='margin:0;font-weight:700;color:#fff;'>&copy; 2026 Petron Station &amp; Service Center Management System</p>
                </div>
            </div>
        ";

        // Plain text fallback
        $mail->AltBody = "Password Reset OTP - Petron Management System\n\n"
            . "Your OTP code is: {$otp}\n\n"
            . "This OTP will expire in 5 minutes.\n\n"
            . "If you did not request this, please ignore this email.\n\n"
            . "-- Petron Station Management System";
        
        error_log("Attempting to send email...");
        $sent = $mail->send();
        
        if ($sent) {
            error_log("✓ Email sent SUCCESSFULLY to {$to_email}");
        } else {
            error_log("✗ Email send FAILED: " . $mail->ErrorInfo);
        }
        
        return $sent;

    } catch (Exception $e) {
        error_log("✗ OTP email EXCEPTION: " . $e->getMessage());
        if (isset($mail)) {
            error_log("✗ PHPMailer Error: " . $mail->ErrorInfo);
        }
        return false;
    }
}


// Function to send admin credentials email
function sendAdminCredentialsEmail($to_email, $full_name, $station_name, $username, $password, $created_by_role = 'Admin', $role = 'Staff', $employee_id = '') {
    global $email_config;

    $creator_label = ucfirst(strtolower($created_by_role));
    if (strtolower($created_by_role) === 'superadmin') $creator_label = 'Super Admin';
    $role_display  = ucfirst(strtolower($role));
    $now           = date('F d, Y \a\t h:i A');

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $email_config['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $email_config['username'];
        $mail->Password   = $email_config['password_hash'];
        $mail->SMTPSecure = $email_config['encryption'];
        $mail->Port       = $email_config['port'];

        $mail->setFrom($email_config['from_email'], $email_config['from_name']);
        $mail->addAddress($to_email);

        // Bypass SSL certificate verification for local environments
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );

        $mail->isHTML(true);
        $mail->Subject = 'Petron Station Management System - Your Account Credentials';

        // Embed Petron logo
        $logo_path = __DIR__ . '/../assets/img/Petron Logo.png';
        if (file_exists($logo_path)) {
            $mail->AddEmbeddedImage($logo_path, 'petron_logo_cred', 'Petron Logo.png');
            $logo_src = 'cid:petron_logo_cred';
        } else {
            $logo_src = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
        }

        $emp_id_row = !empty($employee_id) ? "
            <tr>
                <td style='padding:12px 16px;border-bottom:1px solid #f1f5f9;color:#64748b;font-size:13px;font-weight:600;width:30%;min-width:120px;word-break:break-word;'>Employee ID</td>
                <td style='padding:12px 16px;border-bottom:1px solid #f1f5f9;color:#1e293b;font-size:13px;font-family:monospace;font-weight:700;width:70%;word-break:break-word;'>{$employee_id}</td>
            </tr>" : '';

        $mail->Body = "
        <div style='font-family:Inter,Arial,sans-serif;max-width:640px;margin:0 auto;background:#f8fafc;'>

            <!-- HEADER -->
            <div style='background:linear-gradient(135deg,#002F6C 0%,#004a9e 100%);padding:40px 30px;text-align:center;border-radius:12px 12px 0 0;'>
                <img src='{$logo_src}' alt='Petron' style='height:72px;margin-bottom:16px;display:block;margin-left:auto;margin-right:auto;'>
                <h1 style='margin:0;font-size:24px;font-weight:800;color:#ffffff;letter-spacing:0.5px;line-height:1.3;'>Station Management System</h1>
                <p style='margin:8px 0 0;font-size:13px;color:rgba(255,255,255,0.75);'>Account Credentials Notification</p>
            </div>

            <!-- BODY -->
            <div style='background:#ffffff;padding:40px 35px;'>

                <!-- Greeting -->
                <h2 style='color:#002F6C;font-size:22px;font-weight:700;margin:0 0 8px;'>Hello, {$full_name}! 👋</h2>
                <p style='color:#475569;font-size:15px;line-height:1.7;margin:0 0 24px;'>
                    Your account on the <strong style='color:#002F6C;'>Petron Station Management System</strong> has been successfully
                    created by the {$creator_label} for <strong>{$station_name}</strong>.<br>
                    Please find your temporary login credentials below. You can now use them to log in to the system.
                </p>

                <!-- Credentials Card -->
                <div style='background:linear-gradient(135deg,#f0f9ff 0%,#e0f2fe 100%);border:2px solid #0ea5e9;border-radius:12px;padding:28px;margin:0 0 28px;'>
                    <p style='margin:0 0 18px;font-size:14px;font-weight:800;color:#0369a1;text-transform:uppercase;letter-spacing:1px;'>
                        📋 Your Login Credentials
                    </p>
                    <table style='width:100%;border-collapse:collapse;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,0.08);'>
                        <tbody>
                            {$emp_id_row}
                            <tr>
                                <td style='padding:12px 16px;border-bottom:1px solid #f1f5f9;color:#64748b;font-size:13px;font-weight:600;width:30%;min-width:120px;word-break:break-word;'>Full Name</td>
                                <td style='padding:12px 16px;border-bottom:1px solid #f1f5f9;color:#1e293b;font-size:13px;font-weight:700;width:70%;word-break:break-word;'>{$full_name}</td>
                            </tr>
                            <tr>
                                <td style='padding:12px 16px;border-bottom:1px solid #f1f5f9;color:#64748b;font-size:13px;font-weight:600;width:30%;min-width:120px;word-break:break-word;'>Role</td>
                                <td style='padding:12px 16px;border-bottom:1px solid #f1f5f9;width:70%;word-break:break-word;'>
                                    <span style='background:#dbeafe;color:#1d4ed8;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700;'>{$role_display}</span>
                                </td>
                            </tr>
                            <tr>
                                <td style='padding:12px 16px;border-bottom:1px solid #f1f5f9;color:#64748b;font-size:13px;font-weight:600;width:30%;min-width:120px;word-break:break-word;'>Station</td>
                                <td style='padding:12px 16px;border-bottom:1px solid #f1f5f9;color:#1e293b;font-size:13px;font-weight:700;width:70%;word-break:break-word;'>{$station_name}</td>
                            </tr>
                            <tr>
                                <td style='padding:12px 16px;border-bottom:1px solid #f1f5f9;color:#64748b;font-size:13px;font-weight:600;width:30%;min-width:120px;word-break:break-word;'>Username / Email</td>
                                <td style='padding:12px 16px;border-bottom:1px solid #f1f5f9;color:#0369a1;font-size:13px;font-weight:700;width:70%;word-break:break-all;'>{$username}</td>
                            </tr>
                            <tr>
                                <td style='padding:12px 16px;color:#64748b;font-size:13px;font-weight:600;width:30%;min-width:120px;word-break:break-word;'>Temporary Password</td>
                                <td style='padding:12px 16px;width:70%;'>
                                    <span style='font-family:monospace;font-size:16px;font-weight:800;color:#dc2626;background:#fff1f2;padding:6px 12px;border-radius:8px;letter-spacing:1px;border:1.5px dashed #fca5a5;display:inline-block;word-break:break-all;'>{$password}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Warning Notice -->
                <div style='background:#fffbeb;border-left:5px solid #f59e0b;border-radius:8px;padding:18px 20px;margin:0 0 28px;'>
                    <p style='margin:0 0 8px;font-size:14px;font-weight:800;color:#92400e;'>⚠️ IMPORTANT — Security Reminder:</p>
                    <ul style='margin:0;padding-left:20px;color:#78350f;font-size:13px;line-height:1.9;'>
                        <li>You are required to <strong>change your password</strong> upon your first login.</li>
                        <li>Do not share your password with anyone.</li>
                        <li>Your password must contain an uppercase letter, lowercase letter, number, and special character.</li>
                        <li>If you did not request this account, please contact your Administrator immediately.</li>
                    </ul>
                </div>

                <!-- Login Button -->
                <div style='text-align:center;margin:0 0 32px;'>
                    <a href='http://localhost/group31petron_system_official4/public/login.php'
                       style='background:linear-gradient(135deg,#002F6C 0%,#004a9e 100%);color:#ffffff;padding:15px 44px;text-decoration:none;border-radius:10px;display:inline-block;font-weight:800;font-size:16px;letter-spacing:0.5px;box-shadow:0 4px 14px rgba(0,47,108,0.35);'>
                        🚀 Log In to the System
                    </a>
                </div>

                <p style='color:#94a3b8;font-size:12px;text-align:center;margin:0;border-top:1px solid #f1f5f9;padding-top:20px;'>
                    Account created on {$now} &nbsp;|&nbsp; This is an automated message — do not reply.
                </p>
            </div>

            <!-- FOOTER -->
            <div style='background:#002F6C;color:rgba(255,255,255,0.8);padding:24px 30px;text-align:center;border-radius:0 0 12px 12px;font-size:12px;'>
                <p style='margin:0 0 6px;font-weight:700;font-size:13px;color:#ffffff;'>© 2026 Petron Station Management System</p>
                <p style='margin:0;'>All rights reserved. This message was sent automatically — please do not reply.</p>
            </div>
        </div>";

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
