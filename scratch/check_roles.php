<?php
require_once __DIR__ . '/../public/db_connect.php';
$stmt = $pdo->query("SELECT id, username, role, status FROM users");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "User: " . $r['username'] . " | Role: " . $r['role'] . " | Status: " . $r['status'] . "\n";
}
