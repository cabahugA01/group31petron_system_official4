<?php
// CLI helper to simulate forgot_password flow for a specific email
if (php_sapi_name() !== 'cli') { echo "CLI only\n"; exit(1); }
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../config/email_config.php';

$email = $argv[1] ?? 'yangc.developer@gmail.com';
try {
    $stmt = $pdo->prepare("SELECT id AS user_id, TRIM(email) AS email, role FROM users WHERE LOWER(TRIM(email)) = LOWER(?) LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) { echo "User not found: {$email}\n"; exit(1); }

    $otp_code = sprintf('%06d', random_int(100000, 999999));
    $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id = ?")->execute([$user['user_id']]);
    $pdo->prepare("INSERT INTO password_reset_tokens (user_id, token, token_type, expires_at, ip_address) VALUES (?, ?, 'reset', DATE_ADD(NOW(), INTERVAL 5 MINUTE), ?)")
        ->execute([$user['user_id'], $otp_code, '127.0.0.1']);

    echo "Inserted token for user_id={$user['user_id']} otp={$otp_code}\n";
    $ok = sendPasswordResetOTP($user['email'], $otp_code);
    echo "sendPasswordResetOTP returned: " . ($ok ? 'TRUE' : 'FALSE') . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

?>
