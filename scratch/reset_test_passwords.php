<?php
require_once __DIR__ . '/../public/db_connect.php';

$pw = password_hash('admin123', PASSWORD_DEFAULT);

$users = ['developer', 'Judy', 'Edgar', 'Kathrine'];
foreach ($users as $u) {
    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE username = ?");
    $stmt->execute([$pw, $u]);
    echo "Password for {$u} reset to 'admin123'\n";
}
