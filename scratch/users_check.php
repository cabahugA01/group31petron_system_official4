<?php
require_once __DIR__ . '/../public/db_connect.php';

$users = ['developer', 'Edgar', 'pepito', 'yyang', 'judy'];
$hash = password_hash('Password123!', PASSWORD_BCRYPT);

foreach ($users as $username) {
    $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, status = 'Active' WHERE username = ?");
    $stmt->execute([$hash, $username]);
    echo "Updated {$username} to Password123! and status Active. Affected rows: " . $stmt->rowCount() . "\n";
}
