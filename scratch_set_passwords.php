<?php
require_once 'public/db_connect.php';
$new_hash = password_hash('password', PASSWORD_DEFAULT);
$stmt = $pdo->prepare("UPDATE users SET password = ? WHERE username IN ('stafftest@gmail.com', 'manager@gmail.com', 'pepito@gmail.com', 'testsuperadmin@petron.com')");
$stmt->execute([$new_hash]);
echo "Updated " . $stmt->rowCount() . " users with password: 'password'\n";
