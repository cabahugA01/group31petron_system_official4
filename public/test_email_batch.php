<?php
// CLI-only script: send OTP to registered emails and report results
if (php_sapi_name() !== 'cli') {
    echo "This script is CLI-only.\n";
    exit(1);
}

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../config/email_config.php';

echo "Starting OTP batch test...\n";

try {
    $stmt = $pdo->query("SELECT email FROM users WHERE email IS NOT NULL AND email <> '' LIMIT 30");
    $emails = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    echo "Database error: " . $e->getMessage() . "\n";
    exit(1);
}

if (empty($emails)) {
    echo "No registered emails found.\n";
    exit(0);
}

$logFile = __DIR__ . '/email_test_results.log';
$ts = date('c');
file_put_contents($logFile, "=== OTP Batch Run {$ts} ===\n", FILE_APPEND);

foreach ($emails as $i => $email) {
    $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    echo sprintf("[%02d/%02d] Sending OTP to %s... ", $i+1, count($emails), $email);
    $ok = false;
    try {
        $ok = sendPasswordResetOTP($email, $otp);
    } catch (Exception $e) {
        echo "EXCEPTION\n";
        file_put_contents($logFile, "{$email} | EXCEPTION: " . $e->getMessage() . "\n", FILE_APPEND);
        continue;
    }

    if ($ok) {
        echo "OK\n";
        file_put_contents($logFile, "{$email} | OK\n", FILE_APPEND);
    } else {
        echo "FAILED\n";
        file_put_contents($logFile, "{$email} | FAILED\n", FILE_APPEND);
    }
    // short pause between sends
    usleep(200000);
}

echo "Done. Results appended to: {$logFile}\n";

?>
