<?php
require_once __DIR__ . '/db_connect.php';
echo "<pre>";

$pass_hash = password_hash('admin123', PASSWORD_DEFAULT);

// Update developer
$st = $pdo->prepare("UPDATE users SET password_hash = ? WHERE username = ?");
$st->execute([$pass_hash, 'developer']);
echo "Updated developer password to 'admin123'\n";

// Update pepito
$st->execute([$pass_hash, 'pepito']);
echo "Updated pepito password to 'admin123'\n";

// Let's also check if there is an active session in session files or what user is active
echo "</pre>";
