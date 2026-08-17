<?php
require_once __DIR__ . '/../public/db_connect.php';

// 1. Truncate notifications completely
$pdo->exec("TRUNCATE TABLE notifications");
echo "[1] Notifications table truncated.\n";

// 2. Fetch all superadmin users
$stmt = $pdo->query("SELECT id, username, role FROM users WHERE LOWER(role) IN ('superadmin','developer')");
$sa_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "[2] Superadmin users: " . json_encode($sa_users) . "\n";

// 3. Simulate calling superadmin generator for each superadmin user
require_once __DIR__ . '/../backend/lib.php';

foreach ($sa_users as $su) {
    $_SESSION['user'] = $su;
    $sa_ids = [(int)$su['id']];
    
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND status = 'unread'");
    $cnt->execute([$su['id']]);
    $u_cnt = (int)$cnt->fetchColumn();
    
    echo "User: " . $su['username'] . " (ID: " . $su['id'] . ") -> Notifications unread: " . $u_cnt . "\n";
}
