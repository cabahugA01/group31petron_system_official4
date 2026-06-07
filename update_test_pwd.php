<?php
require 'public/db_connect.php';
$hash = password_hash('Password123!', PASSWORD_BCRYPT);
$stmt = $pdo->prepare("UPDATE users SET password = ?, status = 'Active' WHERE username = 'Yang'");
$stmt->execute([$hash]);
echo "Password updated successfully\n";
unlink(__FILE__); // delete self
