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

// Function to send simulated SMS
if (!function_exists('sendSMS')) {
function sendSMS($to_phone, $message) {
    $log_file = __DIR__ . '/../sms_sent.log';
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] TO: {$to_phone} | MSG: {$message}\n";
    @file_put_contents($log_file, $log_entry, FILE_APPEND);
    error_log("SMS Sent to {$to_phone}: {$message}");
    return true;
}
}
?>
