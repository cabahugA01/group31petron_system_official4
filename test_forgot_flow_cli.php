<?php
/**
 * Test the complete forgot-password OTP flow via CLI.
 * Reads active users from DB and verifies the email each one would receive.
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/public/db_connect.php';
require_once __DIR__ . '/backend/lib.php';
require_once __DIR__ . '/config/email_config.php';

// 1. List all active users with emails
echo "=== Active Users with Email ===\n";
$users = $pdo->query("SELECT id, name, username, email, role, status FROM users WHERE LOWER(TRIM(status)) = 'active' AND email IS NOT NULL AND TRIM(email) != '' ORDER BY role, name")->fetchAll(PDO::FETCH_ASSOC);

if (empty($users)) {  echo "No active users with email found!\n";  exit(1);
}

foreach ($users as $u) {  echo sprintf("  [%d] %-30s %-20s %-15s %s\n", $u['id'], $u['name'], $u['username'], $u['role'], $u['email']);
}

echo "\n=== Testing OTP Send to Each User's Email ===\n";

// Auto-create password_reset_tokens table if missing
$pdo->exec("  CREATE TABLE IF NOT EXISTS `password_reset_tokens` (  `id`  INT(11)  NOT NULL AUTO_INCREMENT,  `user_id`  INT(11)  NOT NULL,  `token`  VARCHAR(10) NOT NULL,  `token_type` VARCHAR(20) NOT NULL DEFAULT 'reset',  `expires_at` DATETIME  NOT NULL,  `used_at`  DATETIME  DEFAULT NULL,  `ip_address` VARCHAR(45) DEFAULT NULL,  `is_used`  TINYINT(1)  NOT NULL DEFAULT 0,  `created_at` TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,  PRIMARY KEY (`id`)  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

foreach ($users as $u) {  $email = trim(preg_replace('/[\r\n]+/', '', $u['email']));  $otp  = sprintf('%06d', random_int(100000, 999999));  echo "\n[{$u['id']}] {$u['name']} ({$u['role']}) → sending OTP {$otp} to: {$email} ... ";  // Store in DB  $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id = ?")->execute([$u['id']]);  $pdo->prepare("INSERT INTO password_reset_tokens (user_id, token, token_type, expires_at, ip_address) VALUES (?, ?, 'reset', DATE_ADD(NOW(), INTERVAL 5 MINUTE), ?)")  ->execute([$u['id'], $otp, '127.0.0.1']);  // Send email  $sent = sendPasswordResetOTP($email, $otp);  echo $sent ? " SENT\n" : " FAILED\n";
}

echo "\n=== Done ===\n";
echo "Check each inbox above. All OTPs stored in password_reset_tokens table.\n";
