<?php
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

echo "=== user_station_id for each user ===\n";
$users = $pdo->query("SELECT id, username, role, station_id FROM users WHERE status='Active'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($users as $u) {
    // Simulate session
    $_SESSION['user'] = $u;
    $sid = user_station_id();
    echo "  id={$u['id']} username={$u['username']} role={$u['role']} db_station_id={$u['station_id']} => user_station_id()=$sid\n";
}

echo "\n=== role_key for each role ===\n";
$roles = ['admin','superadmin','manager','staff'];
foreach ($roles as $r) {
    echo "  role_key('$r') = " . role_key($r) . "\n";
}
