<?php
include 'public/db_connect.php';
$new_hash = password_hash('password123', PASSWORD_BCRYPT);
$pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$new_hash, 3]);
$pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$new_hash, 1]);
echo "Passwords updated successfully to: password123\n";
