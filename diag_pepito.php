<?php
require_once __DIR__ . '/public/db_connect.php';
require_once __DIR__ . '/config/email_config.php';

header('Content-Type: text/plain; charset=utf-8');

$target = 'cabahug.amiedamas@gmail.com';

// Check exact match
$stmt = $pdo->prepare("SELECT id, username, email, role, status, LENGTH(email) AS len, HEX(email) AS hex_val FROM users WHERE id = 4");
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);

echo "=== DB Record for user id=4 ===\n";
echo "Username : " . $row['username'] . "\n";
echo "Role     : " . $row['role'] . "\n";
echo "Status   : " . $row['status'] . "\n";
echo "Email    : '" . $row['email'] . "'\n";
echo "Length   : " . $row['len'] . "\n";
echo "Expected : '" . $target . "' (len=" . strlen($target) . ")\n";
echo "Match?   : " . ($row['email'] === $target ? 'YES ✓' : 'NO ✗') . "\n";
echo "Hex      : " . $row['hex_val'] . "\n\n";

// Try finding by email
$stmt2 = $pdo->prepare("SELECT id, username, email, status FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))");
$stmt2->execute([$target]);
$found = $stmt2->fetch(PDO::FETCH_ASSOC);
echo "=== Lookup by email ===\n";
if ($found) {
    echo "Found    : YES ✓ (id={$found['id']}, status={$found['status']})\n";
} else {
    echo "Found    : NO ✗ — email not found by LOWER(TRIM()) match\n";
}

// Try LIKE
$stmt3 = $pdo->prepare("SELECT id, username, email FROM users WHERE email LIKE ?");
$stmt3->execute(['%cabahug.amiedamas%']);
$likes = $stmt3->fetchAll(PDO::FETCH_ASSOC);
echo "\n=== LIKE search '%cabahug.amiedamas%' ===\n";
foreach ($likes as $l) {
    echo "  id={$l['id']} user={$l['username']} email='{$l['email']}'\n";
}

// Try send
echo "\n=== SMTP Test Send ===\n";
$clean = trim(preg_replace('/[\r\n\t]+/', '', $row['email']));
echo "Sending to: '{$clean}'\n";
$otp = rand(100000, 999999);
$result = sendPasswordResetOTP($clean, $otp);
echo "Result: " . ($result ? "SUCCESS ✓" : "FAILED ✗") . "\n";
