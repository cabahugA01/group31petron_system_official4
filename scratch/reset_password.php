<?php
require 'public/db_connect.php';
$password_hash = password_hash('petron123', PASSWORD_DEFAULT);
$stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE username = 'Judy'");
$stmt->execute([$password_hash]);
echo "Password updated successfully for Judy\n";
