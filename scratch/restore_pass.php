<?php
require_once __DIR__ . '/../public/db_connect.php';
$stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE username = 'Edgar'");
$stmt->execute(['$2y$10$BBvzfSYWrEP5aqAxpUyMYe8Ss72YgyLZDGyBVdweq9tBA0qimuXBm']);
echo "Password hash for Edgar restored.\n";
