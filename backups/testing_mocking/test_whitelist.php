<?php
/**
 * PASSWORD RESET WHITELIST TESTER
 * 
 * This script tests if the email whitelist is working correctly
 * Run: http://localhost/group31petron_system_official4/backups/testing_mocking/test_whitelist.php
 */

require_once __DIR__ . '/../../config/password_reset_whitelist.php';
require_once __DIR__ . '/../../public/db_connect.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset Whitelist Tester</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 40px 20px;
            min-height: 100vh;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #002F6C 0%, #004a9e 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            font-size: 28px;
            margin-bottom: 8px;
        }
        .header p {
            opacity: 0.9;
            font-size: 14px;
        }
        .content {
            padding: 40px;
        }
        .section {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            border-left: 5px solid #002F6C;
        }
        .section h2 {
            color: #002F6C;
            font-size: 20px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .success { color: #10b981; font-weight: bold; }
        .error { color: #e30613; font-weight: bold; }
        .warning { color: #f59e0b; font-weight: bold; }
        .info { color: #3b82f6; font-weight: bold; }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
            background: white;
            border-radius: 8px;
            overflow: hidden;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        th {
            background: #002F6C;
            color: white;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.5px;
        }
        tr:hover {
            background: #f9fafb;
        }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }
        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }
        .badge-error {
            background: #fee2e2;
            color: #991b1b;
        }
        .test-form {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-top: 16px;
        }
        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #374151;
        }
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
        }
        .form-group input:focus {
            outline: none;
            border-color: #002F6C;
        }
        .btn {
            background: linear-gradient(135deg, #002F6C 0%, #004a9e 100%);
            color: white;
            padding: 12px 28px;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
        }
        code {
            background: #1f2937;
            color: #10b981;
            padding: 2px 8px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
        }
        .footer {
            text-align: center;
            padding: 20px;
            background: #f9fafb;
            color: #6b7280;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔐 Password Reset Whitelist Tester</h1>
            <p>Test if email addresses are allowed to receive password reset OTPs</p>
        </div>
        
        <div class="content">
            
            <!-- Current Whitelist -->
            <div class="section">
                <h2>📋 Current Whitelist</h2>
                <p>These emails are currently allowed to receive password reset OTPs:</p>
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Email Address</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $whitelist = getPasswordResetWhitelist();
                        if (empty($whitelist)) {
                            echo '<tr><td colspan="3" style="text-align:center;color:#9ca3af;">No emails in whitelist</td></tr>';
                        } else {
                            $index = 1;
                            foreach ($whitelist as $email) {
                                echo '<tr>';
                                echo '<td>' . $index++ . '</td>';
                                echo '<td><code>' . htmlspecialchars($email) . '</code></td>';
                                echo '<td><span class="badge badge-success">✓ WHITELISTED</span></td>';
                                echo '</tr>';
                            }
                        }
                        ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Active Users in Database -->
            <div class="section">
                <h2>👥 Active Users in Database</h2>
                <p>Test if these users can request password reset:</p>
                <table>
                    <thead>
                        <tr>
                            <th>User ID</th>
                            <th>Name</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Whitelist Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        try {
                            $stmt = $pdo->query("
                                SELECT id, CONCAT(first_name, ' ', last_name) AS full_name, 
                                       username, email, role, status 
                                FROM users 
                                WHERE LOWER(TRIM(status)) = 'active' 
                                  AND LOWER(TRIM(role)) IN ('staff','manager','admin','developer','superadmin')
                                ORDER BY id ASC
                            ");
                            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            if (empty($users)) {
                                echo '<tr><td colspan="6" style="text-align:center;color:#9ca3af;">No active users found</td></tr>';
                            } else {
                                foreach ($users as $user) {
                                    $email = trim($user['email'] ?? '');
                                    $is_whitelisted = !empty($email) && isEmailWhitelistedForPasswordReset($email);
                                    
                                    echo '<tr>';
                                    echo '<td>' . htmlspecialchars($user['id']) . '</td>';
                                    echo '<td>' . htmlspecialchars($user['full_name']) . '</td>';
                                    echo '<td>' . htmlspecialchars($user['username']) . '</td>';
                                    echo '<td>' . (empty($email) ? '<em style="color:#9ca3af;">No email</em>' : '<code>' . htmlspecialchars($email) . '</code>') . '</td>';
                                    echo '<td>' . htmlspecialchars(ucfirst($user['role'])) . '</td>';
                                    
                                    if (empty($email)) {
                                        echo '<td><span class="badge badge-error">✗ NO EMAIL</span></td>';
                                    } elseif ($is_whitelisted) {
                                        echo '<td><span class="badge badge-success">✓ CAN RESET</span></td>';
                                    } else {
                                        echo '<td><span class="badge badge-error">✗ BLOCKED</span></td>';
                                    }
                                    echo '</tr>';
                                }
                            }
                        } catch (Exception $e) {
                            echo '<tr><td colspan="6" style="text-align:center;color:#e30613;">Error: ' . htmlspecialchars($e->getMessage()) . '</td></tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Test Email -->
            <div class="section">
                <h2>🧪 Test Email Address</h2>
                <p>Enter any email address to check if it's whitelisted:</p>
                
                <?php
                if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_email'])) {
                    $test_email = trim($_POST['test_email']);
                    $is_whitelisted = isEmailWhitelistedForPasswordReset($test_email);
                    
                    echo '<div style="background:white;padding:20px;border-radius:8px;margin-top:16px;border:2px solid ' . ($is_whitelisted ? '#10b981' : '#e30613') . ';">';
                    echo '<p style="margin:0;font-size:16px;">';
                    echo '<strong>Email:</strong> <code>' . htmlspecialchars($test_email) . '</code><br>';
                    echo '<strong>Status:</strong> ';
                    
                    if ($is_whitelisted) {
                        echo '<span class="success">✓ WHITELISTED - Can receive password reset OTP</span>';
                    } else {
                        echo '<span class="error">✗ BLOCKED - Cannot receive password reset OTP</span>';
                    }
                    echo '</p></div>';
                }
                ?>
                
                <form method="POST" class="test-form">
                    <div class="form-group">
                        <label for="test_email">Email Address:</label>
                        <input type="email" name="test_email" id="test_email" 
                               placeholder="example@gmail.com" required 
                               value="<?php echo isset($_POST['test_email']) ? htmlspecialchars($_POST['test_email']) : ''; ?>">
                    </div>
                    <button type="submit" class="btn">🔍 Test Email</button>
                </form>
            </div>
            
            <!-- Instructions -->
            <div class="section">
                <h2>📖 How to Manage Whitelist</h2>
                <p><strong>Configuration File:</strong> <code>config/password_reset_whitelist.php</code></p>
                <p style="margin-top:12px;"><strong>To add an email:</strong></p>
                <ol style="margin-left:20px;line-height:1.8;margin-top:8px;">
                    <li>Open <code>config/password_reset_whitelist.php</code></li>
                    <li>Add email to the <code>$password_reset_whitelist</code> array</li>
                    <li>Save the file</li>
                    <li>Refresh this page to verify</li>
                </ol>
                
                <p style="margin-top:16px;"><strong>To remove an email:</strong></p>
                <ol style="margin-left:20px;line-height:1.8;margin-top:8px;">
                    <li>Open <code>config/password_reset_whitelist.php</code></li>
                    <li>Delete or comment out the email from array</li>
                    <li>Save the file</li>
                </ol>
            </div>
            
            <!-- Test Password Reset Flow -->
            <div class="section">
                <h2>🚀 Test Password Reset Flow</h2>
                <p>Ready to test the actual password reset functionality?</p>
                <div style="margin-top:16px;">
                    <a href="../../public/forgot_password.php" style="display:inline-block;background:linear-gradient(135deg, #002F6C 0%, #004a9e 100%);color:white;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:700;">
                        🔑 Go to Forgot Password Page
                    </a>
                </div>
                <p style="margin-top:12px;font-size:13px;color:#6b7280;">
                    Try entering both whitelisted and non-whitelisted emails to see the difference.
                </p>
            </div>
            
        </div>
        
        <div class="footer">
            Password Reset Whitelist System v1.0 &nbsp;|&nbsp; Last Updated: July 12, 2026
        </div>
    </div>
</body>
</html>
