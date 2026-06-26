<?php
require_once __DIR__ . '/../public/db_connect.php';
$password = 'Manager123!';
$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE username = 'Edgar'");
$stmt->execute([$hash]);
echo "Password hash updated to: $hash\n";
