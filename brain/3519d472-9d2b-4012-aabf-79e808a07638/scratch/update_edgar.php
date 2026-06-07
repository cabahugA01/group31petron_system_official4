<?php
require_once 'c:/xampp/htdocs/group31petron_system_official4/public/db_connect.php';
$hash = password_hash('Password123!', PASSWORD_BCRYPT);
$stmt = $pdo->prepare("UPDATE users SET password_hash = ?, status = 'Active' WHERE username = 'Edgar'");
$stmt->execute([$hash]);
echo "Password for Edgar updated successfully\n";
