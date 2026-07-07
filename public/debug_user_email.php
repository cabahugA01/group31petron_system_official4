<?php
require_once __DIR__ . '/../public/db_connect.php';

$email = $argv[1] ?? 'yangc.developer@gmail.com';
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(TRIM(email)) = LOWER(?) LIMIT 1");
    $stmt->execute([$email]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        echo "User not found for: {$email}\n";
        exit(0);
    }
    echo "User row:\n";
    foreach ($u as $k => $v) {
        echo "{$k}: " . var_export($v, true) . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

?>
