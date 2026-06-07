<?php
/**
 * DEPLOY NEW LOGIN SYSTEM
 * This script will backup old login and deploy new one
 * Run: http://localhost/group31petron_system_official4/deploy_new_login.php
 */

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Deploy New Login System</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 30px 20px;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 40px;
        }
        h1 {
            color: #2d3748;
            font-size: 28px;
            margin-bottom: 10px;
        }
        .subtitle {
            color: #718096;
            font-size: 14px;
            margin-bottom: 30px;
        }
        .section {
            background: #f7fafc;
            border-left: 4px solid #667eea;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .section h2 {
            color: #2d3748;
            font-size: 18px;
            margin-bottom: 15px;
        }
        .success { border-left-color: #38a169; background: #c6f6d5; color: #22543d; }
        .error { border-left-color: #e53e3e; background: #fed7d7; color: #742a2a; }
        .warning { border-left-color: #ed8936; background: #feebc8; color: #7c2d12; }
        .info { border-left-color: #3182ce; background: #bee3f8; color: #2c5282; }
        .btn {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: transform 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
        }
        .btn-danger {
            background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%);
        }
        pre {
            background: white;
            padding: 15px;
            border-radius: 8px;
            overflow-x: auto;
            font-size: 13px;
            line-height: 1.6;
            margin: 10px 0;
        }
        .step {
            display: flex;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        .step-number {
            background: #667eea;
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 15px;
            flex-shrink: 0;
        }
        .step-content {
            flex: 1;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 Deploy New Login System</h1>
        <p class="subtitle">This will backup your current login and deploy the new version</p>

        <?php
        $public_dir = __DIR__ . '/public';
        $old_login = $public_dir . '/login.php';
        $new_login = $public_dir . '/login_new.php';
        $backup_login = $public_dir . '/login_old_backup.php';

        // Check files
        $old_exists = file_exists($old_login);
        $new_exists = file_exists($new_login);
        $backup_exists = file_exists($backup_login);

        echo '<div class="section info">';
        echo '<h2>📁 File Status Check</h2>';
        echo '<p>Current login.php: ' . ($old_exists ? '✅ Exists' : '❌ Not found') . '</p>';
        echo '<p>New login_new.php: ' . ($new_exists ? '✅ Exists' : '❌ Not found') . '</p>';
        echo '<p>Backup login_old_backup.php: ' . ($backup_exists ? '⚠️ Already exists' : '✅ Ready to create') . '</p>';
        echo '</div>';

        // Handle deployment
        if (isset($_POST['deploy'])) {
            echo '<div class="section">';
            echo '<h2>🔄 Deployment Process</h2>';
            
            $success = true;
            $messages = [];

            // Step 1: Backup current login
            if ($old_exists && !$backup_exists) {
                if (copy($old_login, $backup_login)) {
                    $messages[] = '✅ Step 1: Backed up current login.php to login_old_backup.php';
                } else {
                    $messages[] = '❌ Step 1: Failed to backup current login.php';
                    $success = false;
                }
            } elseif ($backup_exists) {
                $messages[] = '⚠️ Step 1: Backup already exists, skipping';
            } else {
                $messages[] = '⚠️ Step 1: No current login.php to backup';
            }

            // Step 2: Check if new login exists
            if (!$new_exists) {
                $messages[] = '❌ Step 2: New login_new.php not found!';
                $success = false;
            } else {
                $messages[] = '✅ Step 2: New login_new.php found';
            }

            // Step 3: Replace login.php
            if ($success && $new_exists) {
                // Delete old login
                if ($old_exists) {
                    if (unlink($old_login)) {
                        $messages[] = '✅ Step 3a: Deleted old login.php';
                    } else {
                        $messages[] = '❌ Step 3a: Failed to delete old login.php';
                        $success = false;
                    }
                }

                // Rename new to login.php
                if ($success && rename($new_login, $old_login)) {
                    $messages[] = '✅ Step 3b: Renamed login_new.php to login.php';
                } else {
                    $messages[] = '❌ Step 3b: Failed to rename login_new.php';
                    $success = false;
                }
            }

            // Show results
            foreach ($messages as $msg) {
                echo '<p>' . $msg . '</p>';
            }

            if ($success) {
                echo '</div>';
                echo '<div class="section success">';
                echo '<h2>🎉 Deployment Successful!</h2>';
                echo '<p><strong>New login page is now active!</strong></p>';
                echo '<p>Next steps:</p>';
                echo '<ol style="margin-left: 20px; line-height: 1.8;">';
                echo '<li>Run database update: <a href="database/update_users_final.php" target="_blank">update_users_final.php</a></li>';
                echo '<li>Test new login: <a href="public/login.php" target="_blank">login.php</a></li>';
                echo '<li>If any issues, rollback using backup: login_old_backup.php</li>';
                echo '</ol>';
                echo '</div>';
            } else {
                echo '</div>';
                echo '<div class="section error">';
                echo '<h2>❌ Deployment Failed</h2>';
                echo '<p>Please check file permissions and try again.</p>';
                echo '</div>';
            }
        } elseif (isset($_POST['rollback'])) {
            echo '<div class="section">';
            echo '<h2>🔄 Rollback Process</h2>';
            
            if ($backup_exists) {
                // Delete current login
                if (file_exists($old_login)) {
                    unlink($old_login);
                    echo '<p>✅ Deleted current login.php</p>';
                }
                
                // Restore backup
                if (copy($backup_login, $old_login)) {
                    echo '<p>✅ Restored backup to login.php</p>';
                    unlink($backup_login);
                    echo '<p>✅ Removed backup file</p>';
                    
                    echo '</div>';
                    echo '<div class="section success">';
                    echo '<h2>✅ Rollback Successful!</h2>';
                    echo '<p>Old login page has been restored.</p>';
                    echo '</div>';
                } else {
                    echo '<p>❌ Failed to restore backup</p>';
                    echo '</div>';
                }
            } else {
                echo '<p>❌ No backup file found!</p>';
                echo '</div>';
            }
        } else {
            // Show deployment form
            echo '<div class="section warning">';
            echo '<h2>⚠️ Before You Deploy</h2>';
            echo '<ol style="margin-left: 20px; line-height: 1.8;">';
            echo '<li><strong>Backup database first!</strong> Export petron_pos_db_secure via phpMyAdmin</li>';
            echo '<li>Make sure new login_new.php exists in public folder</li>';
            echo '<li>This will backup current login.php as login_old_backup.php</li>';
            echo '<li>You can rollback if needed using the rollback button</li>';
            echo '</ol>';
            echo '</div>';

            if ($new_exists) {
                echo '<div class="section">';
                echo '<h2>📋 Deployment Steps</h2>';
                
                echo '<div class="step">';
                echo '<div class="step-number">1</div>';
                echo '<div class="step-content">';
                echo '<strong>Backup Current Login</strong><br>';
                echo 'Current login.php will be saved as login_old_backup.php';
                echo '</div></div>';
                
                echo '<div class="step">';
                echo '<div class="step-number">2</div>';
                echo '<div class="step-content">';
                echo '<strong>Deploy New Login</strong><br>';
                echo 'login_new.php will be renamed to login.php';
                echo '</div></div>';
                
                echo '<div class="step">';
                echo '<div class="step-number">3</div>';
                echo '<div class="step-content">';
                echo '<strong>Update Database</strong><br>';
                echo 'You will need to run database/update_users_final.php after deployment';
                echo '</div></div>';
                
                echo '<div style="margin-top: 30px;">';
                echo '<form method="POST" onsubmit="return confirm(\'Are you sure you want to deploy the new login? This will replace the current login.php\');">';
                echo '<button type="submit" name="deploy" class="btn">🚀 Deploy New Login</button>';
                echo '</form>';
                echo '</div>';
                
                if ($backup_exists) {
                    echo '<div style="margin-top: 15px;">';
                    echo '<form method="POST" onsubmit="return confirm(\'Are you sure you want to rollback to the old login?\');">';
                    echo '<button type="submit" name="rollback" class="btn btn-danger">↩️ Rollback to Old Login</button>';
                    echo '</form>';
                    echo '</div>';
                }
                
                echo '</div>';
            } else {
                echo '<div class="section error">';
                echo '<h2>❌ Cannot Deploy</h2>';
                echo '<p><strong>Error:</strong> login_new.php not found in public folder!</p>';
                echo '<p>Make sure the new login file exists before deploying.</p>';
                echo '</div>';
            }
        }
        ?>

        <div class="section" style="margin-top: 30px;">
            <h2>📖 Next Steps After Deployment</h2>
            <ol style="margin-left: 20px; line-height: 1.8;">
                <li><strong>Update Database:</strong> Run <a href="database/update_users_final.php" target="_blank">update_users_final.php</a></li>
                <li><strong>Test Login:</strong> Go to <a href="public/login.php" target="_blank">login.php</a></li>
                <li><strong>Verify Fields:</strong> Check that users table has 11 fields (NO phone)</li>
                <li><strong>Test Credentials:</strong> Station ID + Email/Username + Password + CAPTCHA</li>
            </ol>
        </div>

        <div class="section info">
            <h2>ℹ️ Documentation</h2>
            <p>For complete documentation, see:</p>
            <ul style="margin-left: 20px; line-height: 1.8;">
                <li><a href="NEW_LOGIN_SUMMARY.txt" target="_blank">NEW_LOGIN_SUMMARY.txt</a> - Quick overview</li>
                <li><a href="DEPLOY_NEW_LOGIN.md" target="_blank">DEPLOY_NEW_LOGIN.md</a> - Deployment guide</li>
                <li><a href=".kiro/NEW_LOGIN_IMPLEMENTATION.md" target="_blank">NEW_LOGIN_IMPLEMENTATION.md</a> - Full details</li>
            </ul>
        </div>
    </div>
</body>
</html>
