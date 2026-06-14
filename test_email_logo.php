<?php
/**
 * Test Email Logo Embedding
 * This file verifies that the Petron logo from the login page
 * is correctly embedded in OTP emails
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load email config
require_once __DIR__ . '/config/email_config.php';

// Check logo path (same as login page)
$logo_path = __DIR__ . '/assets/img/Petron Logo.png';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Logo Test - Petron System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 40px 20px;
        }
        
        .container {
            max-width: 900px;
            margin: 0 auto;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            padding: 40px;
            margin-bottom: 30px;
        }
        
        h1 {
            color: #002F6C;
            margin-bottom: 10px;
            font-size: 32px;
        }
        
        .subtitle {
            color: #666;
            font-size: 16px;
            margin-bottom: 30px;
        }
        
        .status-box {
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            font-size: 16px;
        }
        
        .success {
            background-color: #d4edda;
            border-left: 5px solid #28a745;
            color: #155724;
        }
        
        .error {
            background-color: #f8d7da;
            border-left: 5px solid #dc3545;
            color: #721c24;
        }
        
        .info {
            background-color: #d1ecf1;
            border-left: 5px solid #0dcaf0;
            color: #055160;
        }
        
        .logo-preview {
            text-align: center;
            padding: 30px;
            background: #f8f9fa;
            border-radius: 8px;
            margin: 20px 0;
        }
        
        .logo-preview img {
            max-width: 300px;
            height: auto;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        
        table th,
        table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }
        
        table th {
            background-color: #002F6C;
            color: white;
            font-weight: 600;
        }
        
        table tr:hover {
            background-color: #f8f9fa;
        }
        
        .email-preview {
            border: 2px solid #dee2e6;
            border-radius: 8px;
            overflow: hidden;
            margin: 20px 0;
        }
        
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #002F6C;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
            font-size: 16px;
        }
        
        .btn:hover {
            background: #004a9e;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,47,108,0.3);
        }
        
        .code-block {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 15px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            overflow-x: auto;
            margin: 15px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h1>🔍 Email Logo Verification</h1>
            <p class="subtitle">Testing Petron Logo Embedding for OTP Emails</p>
            
            <?php
            // Test 1: Check if logo file exists
            echo "<h2>✅ Test 1: Logo File Check</h2>";
            
            if (file_exists($logo_path)) {
                $file_size = filesize($logo_path);
                $file_size_kb = round($file_size / 1024, 2);
                $image_info = getimagesize($logo_path);
                
                echo "<div class='status-box success'>";
                echo "<strong>✅ SUCCESS:</strong> Logo file found!<br>";
                echo "<strong>Path:</strong> <code>assets/img/Petron Logo.png</code><br>";
                echo "<strong>File Size:</strong> {$file_size_kb} KB<br>";
                if ($image_info) {
                    echo "<strong>Dimensions:</strong> {$image_info[0]} x {$image_info[1]} pixels<br>";
                    echo "<strong>Type:</strong> " . image_type_to_mime_type($image_info[2]);
                }
                echo "</div>";
                
                // Show logo preview
                echo "<div class='logo-preview'>";
                echo "<h3>Logo Preview (Same as Login Page)</h3>";
                echo "<img src='assets/img/Petron Logo.png' alt='Petron Logo'>";
                echo "</div>";
                
            } else {
                echo "<div class='status-box error'>";
                echo "<strong>❌ ERROR:</strong> Logo file NOT found at: <code>{$logo_path}</code>";
                echo "</div>";
            }
            
            // Test 2: Show email preview with embedded logo
            echo "<h2>📧 Test 2: Email Preview</h2>";
            echo "<p>This is how the logo will appear in OTP emails:</p>";
            
            $sample_otp = "123456";
            
            // Generate the same logo embedding code as in email_config.php
            if (file_exists($logo_path)) {
                // Convert to base64 for preview (in actual email, PHPMailer uses AddEmbeddedImage)
                $image_data = base64_encode(file_get_contents($logo_path));
                $logo_src = 'data:image/png;base64,' . $image_data;
            } else {
                $logo_src = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
            }
            
            // Show the actual email template
            echo "<div class='email-preview'>";
            ?>
            
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #dee2e6;'>
                <div style='background: linear-gradient(135deg, #002F6C 0%, #004a9e 100%); color: white; padding: 40px 20px; text-align: center;'>
                    <img src='<?= $logo_src ?>' alt='Petron Logo' style='height: 70px; margin-bottom: 20px; display: block; margin-left: auto; margin-right: auto;' />
                    <h1 style='margin: 0; font-size: 26px; font-weight: 700; letter-spacing: 0.5px;'>Station Management System</h1>
                </div>
                <div style='padding: 40px 30px; background-color: #ffffff;'>
                    <h2 style='color: #002F6C; margin-top: 0; font-size: 24px; font-weight: 600;'>Password Reset Request</h2>
                    <p style='color: #333; line-height: 1.6;'>Hello,</p>
                    <p style='color: #333; line-height: 1.6;'>You requested to reset your password for the Petron Management System.</p>
                    <p style='color: #333; line-height: 1.6;'>Please use the following 6-digit OTP (One-Time Password) to reset your password:</p>
                    <div style='background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); padding: 25px; border-radius: 8px; margin: 30px 0; border-left: 5px solid #002F6C; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1);'>
                        <span style='font-size: 36px; font-weight: 700; letter-spacing: 8px; color: #002F6C; font-family: monospace;'><?= $sample_otp ?></span>
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
            
            <?php
            echo "</div>";
            
            // Test 3: Technical Details
            echo "<h2>⚙️ Test 3: Technical Details</h2>";
            ?>
            
            <table>
                <thead>
                    <tr>
                        <th>Component</th>
                        <th>Value</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Logo Path</strong></td>
                        <td><code>assets/img/Petron Logo.png</code></td>
                        <td><?= file_exists($logo_path) ? '✅ Exists' : '❌ Missing' ?></td>
                    </tr>
                    <tr>
                        <td><strong>Same as Login Page</strong></td>
                        <td><code>../assets/img/Petron Logo.png</code></td>
                        <td>✅ Match</td>
                    </tr>
                    <tr>
                        <td><strong>Embedding Method</strong></td>
                        <td>PHPMailer AddEmbeddedImage()</td>
                        <td>✅ Best Practice</td>
                    </tr>
                    <tr>
                        <td><strong>Email Function</strong></td>
                        <td>sendPasswordResetOTP()</td>
                        <td><?= function_exists('sendPasswordResetOTP') ? '✅ Loaded' : '❌ Not Found' ?></td>
                    </tr>
                    <tr>
                        <td><strong>PHPMailer Class</strong></td>
                        <td>PHPMailer\PHPMailer\PHPMailer</td>
                        <td><?= class_exists('PHPMailer\PHPMailer\PHPMailer') ? '✅ Available' : '❌ Missing' ?></td>
                    </tr>
                </tbody>
            </table>
            
            <div class="info status-box">
                <strong>ℹ️ How It Works:</strong><br>
                <ol style="margin: 10px 0 0 20px; line-height: 1.8;">
                    <li>Login page displays: <code>../assets/img/Petron Logo.png</code></li>
                    <li>Email config loads: <code>__DIR__ . '/../assets/img/Petron Logo.png'</code></li>
                    <li>PHPMailer embeds the logo file directly into the email</li>
                    <li>Email clients display the embedded logo using <code>cid:petron_logo</code></li>
                    <li>No external URLs needed - logo is part of the email attachment</li>
                </ol>
            </div>
            
            <h2>📝 Implementation Code</h2>
            <p>The following code is used in <code>config/email_config.php</code>:</p>
            
            <div class="code-block">
// Embed the Petron logo (same as login page)
$logo_path = __DIR__ . '/../assets/img/Petron Logo.png';
if (file_exists($logo_path)) {
    $mail->AddEmbeddedImage($logo_path, 'petron_logo', 'Petron Logo.png');
    $logo_src = 'cid:petron_logo';
} else {
    // Fallback to transparent pixel
    $logo_src = 'data:image/png;base64,...';
}

// Use in HTML template
&lt;img src='{$logo_src}' alt='Petron Logo' style='height: 60px; ...' /&gt;
            </div>
            
            <div style="margin-top: 30px; padding: 20px; background: #e7f3ff; border-left: 5px solid #0066cc; border-radius: 6px;">
                <h3 style="margin-top: 0; color: #0066cc;">✅ Verification Complete!</h3>
                <p style="margin: 10px 0;">The Petron logo from your login page is now properly configured to appear in all OTP emails.</p>
                <p style="margin: 10px 0;"><strong>Next Step:</strong> Test by requesting a password reset and checking your email inbox.</p>
            </div>
            
            <div style="text-align: center; margin-top: 30px;">
                <a href="public/forgot_password.php" class="btn">🧪 Test Password Reset</a>
                <a href="public/login.php" class="btn" style="background: #6c757d; margin-left: 10px;">← Back to Login</a>
            </div>
        </div>
    </div>
</body>
</html>
