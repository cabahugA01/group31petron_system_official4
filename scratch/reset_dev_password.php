<?php
require_once __DIR__ . '/../public/db_connect.php';
try {
    $password_hash = password_hash('Developer123!', PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE username = 'developer'");
    $stmt->execute([$password_hash]);
    echo "Developer password reset to 'Developer123!' successfully.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
