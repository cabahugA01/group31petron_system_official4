<?php
/**
 * ═══════════════════════════════════════════════════════════════════════
 * COMPLETE PASSWORD RESET FLOW TESTER & DEBUGGER
 * ═══════════════════════════════════════════════════════════════════════
 * 
 * This tool tests the ENTIRE password reset flow from start to finish:
 * 1. Email OTP Generation & Sending
 * 2. OTP Verification
 * 3. Password Reset
 * 
 * HOW TO USE:
 * 1. Access this file in your browser: http://localhost/group31petron_system_official4/test_complete_password_reset_flow.php
 * 2. Enter a valid email from your users table
 * 3. Click "Send OTP" - you'll see the OTP code on screen
 * 4. Copy the OTP and verify it
 * 5. Check if the flow works correctly
 */

session_start();
ob_start();

require_once __DIR__ . '/public/db_connect.php';
require_once __DIR__ . '/config/email_config.php';

$step = $_GET['step'] ?? 'init';
$email = $_POST['email'] ?? $_GET['email'] ?? '';
$otp = $_POST['otp'] ?? $_GET['otp'] ?? '';
$test_results = [];

// ═══════════════════════════════════════════════════════════════════════
// STEP 1: SEND PASSWORD RESET OTP
// ═══════════════════════════════════════════════════════════════════════
if ($step === 'send_otp' && !empty($email)) {
    $test_results['step'] = 'SEND OTP';
    
    try {
        // Check if user exists
        $stmt = $pdo->prepare("SELECT user_id, email, username, first_name, last_name FROM users WHERE email = ? AND status = 'Active' LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            $test_results['error'] = "❌ User not found with email: {$email}";
            $test_results['suggestion'] = "Make sure the email exists in the users table and status is 'Active'";
        } else {
            $test_results['user_found'] = "✅ User found: {$user['first_name']} {$user['last_name']} (@{$user['username']})";
            
            // Generate OTP
            $otp_code = sprintf("%06d", random_int(100000, 999999));
            $expiry = date('Y-m-d H:i:s', strtotime('+5 minutes'));
            
            // Delete old tokens
            $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id = ? AND token_type = 'reset'")->execute([$user['user_id']]);
            $test_results['old_tokens_deleted'] = "✅ Old tokens cleaned up";
            
            // Insert new token
            $stmt = $pdo->prepare("INSERT INTO password_reset_tokens (user_id, token, token_type, expires_at, ip_address) VALUES (?, ?, 'reset', ?, ?)");
            $stmt->execute([$user['user_id'], $otp_code, $expiry, $_SERVER['REMOTE_ADDR']]);
            $token_id = $pdo->lastInsertId();
            $test_results['token_inserted'] = "✅ Token inserted to database (ID: {$token_id})";
            $test_results['otp_code'] = $otp_code;
            $test_results['expires_at'] = $expiry;
            
            // Verify token was stored correctly
            $verify_stmt = $pdo->prepare("SELECT * FROM password_reset_tokens WHERE id = ? LIMIT 1");
            $verify_stmt->execute([$token_id]);
            $stored_token = $verify_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($stored_token) {
                $test_results['token_verification'] = "✅ Token verified in database:";
                $test_results['stored_data'] = [
                    'ID' => $stored_token['id'],
                    'User ID' => $stored_token['user_id'],
                    'Token (OTP)' => $stored_token['token'],
                    'Token Type' => $stored_token['token_type'],
                    'Expires At' => $stored_token['expires_at'],
                    'Is Used' => $stored_token['is_used'] ? 'Yes' : 'No',
                    'IP Address' => $stored_token['ip_address']
                ];
            } else {
                $test_results['error'] = "❌ Token was inserted but could not be retrieved!";
            }
            
            // Try to send email
            $test_results['email_status'] = "📧 Attempting to send email...";
            if (function_exists('sendPasswordResetOTP')) {
                $email_sent = sendPasswordResetOTP($user['email'], $otp_code);
                if ($email_sent) {
                    $test_results['email_sent'] = "✅ Email sent successfully to {$user['email']}";
                } else {
                    $test_results['email_error'] = "⚠️ Email sending failed (check error logs)";
                }
            } else {
                $test_results['email_error'] = "❌ sendPasswordResetOTP function not found!";
            }
            
            $test_results['next_step'] = "verify_otp";
            $test_results['next_url'] = "?step=verify_otp&email=" . urlencode($email) . "&otp=" . urlencode($otp_code);
        }
    } catch (Exception $e) {
        $test_results['error'] = "❌ Exception: " . $e->getMessage();
        $test_results['trace'] = $e->getTraceAsString();
    }
}

// ═══════════════════════════════════════════════════════════════════════
// STEP 2: VERIFY OTP
// ═══════════════════════════════════════════════════════════════════════
if ($step === 'verify_otp' && !empty($email) && !empty($otp)) {
    $test_results['step'] = 'VERIFY OTP';
    $test_results['input_email'] = $email;
    $test_results['input_otp'] = $otp;
    
    try {
        // Auto-detect column names
        $cols = array_column($pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $uid_col = in_array('user_id', $cols) ? 'user_id' : 'id';
        $status_active = in_array('Active', $pdo->query("SELECT DISTINCT status FROM users LIMIT 10")->fetchAll(PDO::FETCH_COLUMN)) ? 'Active' : 'active';
        
        // Try to find the OTP token (EXACTLY like verify_otp.php does)
        $stmt = $pdo->prepare("
            SELECT prt.user_id, prt.token, prt.expires_at, prt.is_used, prt.used_at,
                   u.username, u.email, u.first_name, u.last_name
            FROM   password_reset_tokens prt
            JOIN   users u ON prt.user_id = u.`{$uid_col}`
            WHERE  prt.token      = ?
              AND  prt.token_type = 'reset'
              AND  u.status       = '{$status_active}'
              AND  u.email        = ?
            LIMIT  1
        ");
        $stmt->execute([$otp, $email]);
        $token_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$token_data) {
            $test_results['error'] = "❌ OTP not found or doesn't match email";
            
            // Debug: Show what's in the database
            $debug_stmt = $pdo->prepare("SELECT * FROM password_reset_tokens WHERE token = ? LIMIT 1");
            $debug_stmt->execute([$otp]);
            $debug_token = $debug_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($debug_token) {
                $test_results['debug_token_exists'] = "⚠️ Token EXISTS in database but didn't match query!";
                $test_results['debug_data'] = $debug_token;
                
                // Check user email
                $user_check = $pdo->prepare("SELECT email, status FROM users WHERE user_id = ? LIMIT 1");
                $user_check->execute([$debug_token['user_id']]);
                $user_data = $user_check->fetch(PDO::FETCH_ASSOC);
                
                if ($user_data) {
                    $test_results['user_email'] = $user_data['email'];
                    $test_results['user_status'] = $user_data['status'];
                    
                    if ($user_data['email'] !== $email) {
                        $test_results['email_mismatch'] = "❌ Email mismatch! Input: {$email}, Database: {$user_data['email']}";
                    }
                    if ($user_data['status'] !== $status_active) {
                        $test_results['status_mismatch'] = "❌ User status is '{$user_data['status']}', expected '{$status_active}'";
                    }
                } else {
                    $test_results['user_not_found'] = "❌ User ID {$debug_token['user_id']} not found!";
                }
            } else {
                $test_results['debug_token_not_found'] = "❌ Token '{$otp}' does not exist in database at all";
                
                // Show all reset tokens
                $all_tokens = $pdo->query("SELECT id, user_id, token, token_type, expires_at, is_used FROM password_reset_tokens WHERE token_type = 'reset' ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
                if ($all_tokens) {
                    $test_results['recent_tokens'] = "Recent 'reset' tokens in database:";
                    $test_results['tokens_list'] = $all_tokens;
                } else {
                    $test_results['no_tokens'] = "❌ No 'reset' tokens found in database!";
                }
            }
        } else {
            $test_results['token_found'] = "✅ OTP found in database";
            $test_results['user_info'] = [
                'Name' => "{$token_data['first_name']} {$token_data['last_name']}",
                'Username' => $token_data['username'],
                'Email' => $token_data['email']
            ];
            
            // Check expiration
            $expires_timestamp = strtotime($token_data['expires_at']);
            $now_timestamp = time();
            $time_left = $expires_timestamp - $now_timestamp;
            
            if ($time_left < 0) {
                $test_results['error'] = "❌ OTP has expired (" . abs($time_left) . " seconds ago)";
                $test_results['expired_at'] = $token_data['expires_at'];
            } elseif ($token_data['is_used'] == 1) {
                $test_results['error'] = "❌ OTP has already been used";
                $test_results['used_at'] = $token_data['used_at'];
            } else {
                $test_results['expiration'] = "✅ OTP is valid (" . floor($time_left / 60) . " minutes left)";
                $test_results['success'] = "✅ OTP VERIFICATION SUCCESSFUL!";
                $test_results['next_step'] = "User can now reset password";
                $test_results['redirect_url'] = "public/forgot_password_reset.php?token=" . urlencode($otp) . "&email=" . urlencode($email);
            }
        }
    } catch (Exception $e) {
        $test_results['error'] = "❌ Exception: " . $e->getMessage();
        $test_results['trace'] = $e->getTraceAsString();
    }
}

// ═══════════════════════════════════════════════════════════════════════
// STEP 3: TEST ACTUAL verify_otp.php PAGE
// ═══════════════════════════════════════════════════════════════════════
if ($step === 'test_real_page' && !empty($email)) {
    $test_results['step'] = 'TEST REAL VERIFY OTP PAGE';
    $test_results['redirect_url'] = "public/verify_otp.php?email=" . urlencode($email);
    $test_results['instruction'] = "Click the link above to test the actual verify_otp.php page";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset Flow Tester - Petron System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 30px 20px;
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
            font-weight: 800;
            margin-bottom: 8px;
        }
        
        .header p {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .content {
            padding: 40px;
        }
        
        .form-group {
            margin-bottom: 24px;
        }
        
        label {
            display: block;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        input[type="email"],
        input[type="text"] {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 16px;
            font-family: inherit;
            transition: border-color 0.2s;
        }
        
        input:focus {
            outline: none;
            border-color: #002F6C;
        }
        
        .btn {
            background: linear-gradient(135deg, #002F6C 0%, #004a9e 100%);
            color: white;
            border: none;
            padding: 14px 28px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,47,108,0.3);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .results {
            background: #f9fafb;
            border-radius: 12px;
            padding: 24px;
            margin-top: 30px;
            border: 2px solid #e5e7eb;
        }
        
        .results h3 {
            color: #1f2937;
            font-size: 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .result-item {
            background: white;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 12px;
            border-left: 4px solid #3b82f6;
        }
        
        .result-item.success {
            border-left-color: #10b981;
            background: #f0fdf4;
        }
        
        .result-item.error {
            border-left-color: #ef4444;
            background: #fef2f2;
        }
        
        .result-item.warning {
            border-left-color: #f59e0b;
            background: #fffbeb;
        }
        
        .result-label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 4px;
            font-size: 13px;
        }
        
        .result-value {
            color: #1f2937;
            font-size: 15px;
            font-family: 'Courier New', monospace;
            background: rgba(0,0,0,0.03);
            padding: 8px;
            border-radius: 4px;
            word-break: break-all;
        }
        
        .otp-display {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            color: white;
            padding: 24px;
            border-radius: 12px;
            text-align: center;
            margin: 20px 0;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }
        
        .otp-code {
            font-size: 48px;
            font-weight: 900;
            letter-spacing: 8px;
            font-family: 'Courier New', monospace;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }
        
        .data-table th,
        .data-table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .data-table th {
            background: #f3f4f6;
            font-weight: 600;
            color: #374151;
            font-size: 13px;
        }
        
        .data-table td {
            font-family: 'Courier New', monospace;
            font-size: 14px;
        }
        
        .action-buttons {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }
        
        .btn-secondary {
            background: #6b7280;
        }
        
        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }
        
        pre {
            background: #1f2937;
            color: #f9fafb;
            padding: 16px;
            border-radius: 8px;
            overflow-x: auto;
            font-size: 13px;
            margin-top: 8px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-flask"></i> Password Reset Flow Tester</h1>
            <p>Complete end-to-end testing tool for email OTP password reset</p>
        </div>
        
        <div class="content">
            <?php if (empty($test_results)): ?>
                <!-- INITIAL FORM -->
                <h2 style="margin-bottom: 20px; color: #1f2937;">Test Password Reset Flow</h2>
                <p style="color: #6b7280; margin-bottom: 30px;">
                    Enter a valid email from your users table to test the complete password reset flow.
                </p>
                
                <form method="POST" action="?step=send_otp">
                    <div class="form-group">
                        <label for="email">
                            <i class="fas fa-envelope"></i> Email Address
                        </label>
                        <input type="email" 
                               name="email" 
                               id="email" 
                               placeholder="user@example.com" 
                               required
                               value="<?php echo htmlspecialchars($email); ?>">
                    </div>
                    
                    <div class="action-buttons">
                        <button type="submit" class="btn">
                            <i class="fas fa-paper-plane"></i>
                            Send Password Reset OTP
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <!-- TEST RESULTS -->
                <div class="results">
                    <h3>
                        <i class="fas fa-clipboard-check"></i>
                        Test Results: <?php echo htmlspecialchars($test_results['step'] ?? 'Test'); ?>
                    </h3>
                    
                    <?php foreach ($test_results as $key => $value): ?>
                        <?php if (in_array($key, ['step', 'next_step', 'next_url'])) continue; ?>
                        
                        <?php
                        $item_class = 'result-item';
                        if (strpos($key, 'error') !== false) {
                            $item_class .= ' error';
                        } elseif (strpos($key, 'success') !== false || strpos($value, '✅') !== false) {
                            $item_class .= ' success';
                        } elseif (strpos($key, 'warning') !== false || strpos($value, '⚠️') !== false) {
                            $item_class .= ' warning';
                        }
                        ?>
                        
                        <div class="<?php echo $item_class; ?>">
                            <div class="result-label"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $key))); ?></div>
                            <?php if (is_array($value)): ?>
                                <table class="data-table">
                                    <?php foreach ($value as $k => $v): ?>
                                        <tr>
                                            <th><?php echo htmlspecialchars($k); ?></th>
                                            <td><?php echo htmlspecialchars(is_array($v) ? json_encode($v) : $v); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                            <?php elseif ($key === 'otp_code'): ?>
                                <div class="otp-display">
                                    <div style="font-size: 16px; margin-bottom: 8px; opacity: 0.9;">Your OTP Code:</div>
                                    <div class="otp-code"><?php echo htmlspecialchars($value); ?></div>
                                    <div style="font-size: 14px; margin-top: 8px; opacity: 0.8;">Use this code to verify</div>
                                </div>
                            <?php elseif ($key === 'trace'): ?>
                                <pre><?php echo htmlspecialchars($value); ?></pre>
                            <?php else: ?>
                                <div class="result-value"><?php echo nl2br(htmlspecialchars($value)); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- NEXT STEP BUTTONS -->
                <?php if (!empty($test_results['next_step'])): ?>
                    <div class="action-buttons">
                        <?php if ($test_results['next_step'] === 'verify_otp'): ?>
                            <a href="<?php echo htmlspecialchars($test_results['next_url']); ?>" class="btn btn-success">
                                <i class="fas fa-check-circle"></i>
                                Test OTP Verification
                            </a>
                        <?php endif; ?>
                        
                        <?php if (!empty($test_results['redirect_url'])): ?>
                            <a href="<?php echo htmlspecialchars($test_results['redirect_url']); ?>" class="btn btn-success" target="_blank">
                                <i class="fas fa-external-link-alt"></i>
                                Go to Password Reset Page
                            </a>
                        <?php endif; ?>
                        
                        <a href="?" class="btn btn-secondary">
                            <i class="fas fa-redo"></i>
                            Start New Test
                        </a>
                    </div>
                <?php else: ?>
                    <div class="action-buttons">
                        <a href="?" class="btn btn-secondary">
                            <i class="fas fa-redo"></i>
                            Start New Test
                        </a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
