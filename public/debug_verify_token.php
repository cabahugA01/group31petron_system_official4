<?php
require_once __DIR__ . '/../public/db_connect.php';

$email = $argv[1] ?? 'yangc.developer@gmail.com';
$otp = $argv[2] ?? null;
if (!$otp) {
    echo "Usage: php debug_verify_token.php email otp\n";
    exit(1);
}

try {
    $uid_col = 'id';
    $sql = "
        SELECT prt.user_id, prt.token, prt.is_used,
               (prt.expires_at > NOW()) AS is_valid_time,
               u.username, TRIM(u.email) AS email
        FROM   password_reset_tokens prt
        JOIN   users u ON prt.user_id = u.`{$uid_col}`
        WHERE  prt.token      = ?
          AND  prt.token_type = 'reset'
          AND  LOWER(TRIM(u.status)) = 'active'
          AND  LOWER(TRIM(u.role)) IN ('staff','manager','admin','developer','superadmin')
          AND  LOWER(TRIM(u.email)) = LOWER(?)
        ORDER BY prt.id DESC
        LIMIT  1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$otp, $email]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($data) {
        echo "Token found:\n";
        print_r($data);
    } else {
        echo "No token found for that email+otp combination.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

?>
