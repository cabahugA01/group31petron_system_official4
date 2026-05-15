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
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                <div style='background-color: #002F6C; color: white; padding: 20px; text-align: center;'>
                    <h1 style='margin: 0; font-size: 24px;'>Petron POS System</h1>
                </div>
                <div style='padding: 30px; background-color: #f8f9fa;'>
                    <h2 style='color: #002F6C; margin-top: 0;'>Password Reset Request</h2>
                    <p>Hello,</p>
                    <p>You requested to reset your password for the Petron Management System.</p>
                    <p>Please use the following 6-digit OTP (One-Time Password) to reset your password:</p>
                    <div style='background-color: white; padding: 20px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #002F6C; text-align: center;'>
                        <span style='font-size: 32px; font-weight: bold; letter-spacing: 5px; color: #002F6C;'>$otp</span>
                    </div>
                    <p>This OTP will expire in 5 minutes.</p>
                    <p>If you didn't request this, please ignore this email.</p>
                </div>
                <div style='background-color: #6c757d; color: white; padding: 20px; text-align: center; font-size: 12px;'>
                    <p style='margin: 0;'>This is an automated message. Please do not reply to this email.</p>
                    <p style='margin: 5px 0 0 0;'>&copy; 2026 Petron Management System. All rights reserved.</p>
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
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                <div style='background-color: #002F6C; color: white; padding: 20px; text-align: center;'>
                    <h1 style='margin: 0; font-size: 24px;'>Petron Station Management System</h1>
                </div>
                <div style='padding: 30px; background-color: #f8f9fa;'>
                    <h2 style='color: #002F6C; margin-top: 0;'>Your Account Has Been Created</h2>
                    <p>Dear <strong>$admin_name</strong>,</p>
                    <p>Your account has been created by the <strong>$creator_label</strong> of <strong>$station_name</strong>.</p>
                    <div style='background-color: white; padding: 20px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #002F6C;'>
                        <p><strong>Station:</strong> $station_name</p>
                        <p><strong>Username (Email):</strong> $username</p>
                        <p><strong>Password:</strong> <code style='background-color: #e9ecef; padding: 2px 4px; border-radius: 3px;'>$password</code></p>
                    </div>
                    <p style='color: #dc3545; font-weight: bold;'>⚠ You will be required to change your password upon first login.</p>
                    <div style='text-align: center; margin: 30px 0;'>
                        <a href='#' style='background-color: #002F6C; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block;'>Log In to System</a>
                    </div>
                </div>
                <div style='background-color: #6c757d; color: white; padding: 20px; text-align: center; font-size: 12px;'>
                    <p style='margin: 0;'>This is an automated message. Please do not reply to this email.</p>
                    <p style='margin: 5px 0 0 0;'>&copy; 2026 Petron Management System. All rights reserved.</p>
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
?>
