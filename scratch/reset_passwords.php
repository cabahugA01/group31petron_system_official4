<?php
require_once __DIR__ . '/../public/db_connect.php';
$password_hash = password_hash('password', PASSWORD_DEFAULT);
$stmt = $pdo->prepare('UPDATE users SET password_hash = ?');
$stmt->execute([$password_hash]);
echo "Updated all user passwords to 'password'\n";
