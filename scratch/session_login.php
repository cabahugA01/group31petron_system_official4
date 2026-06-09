<?php
session_start();
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = 2 LIMIT 1");
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    unset($user['password_hash']);
    $user['user_id'] = $user['id'];
    $_SESSION['user'] = $user;
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['role'] = $user['role'];
    
    // Auto Clock In if not clocked in
    $station_id = $user['station_id'];
    if ($station_id) {
        $check = $pdo->prepare("SELECT id FROM labor_sessions WHERE user_id = ? AND end_time IS NULL");
        $check->execute([$user['id']]);
        if (!$check->fetch()) {
            $pdo->prepare("INSERT INTO labor_sessions (user_id, station_id, start_time, shift_period, shift_name) VALUES (?, ?, NOW(), 'second', 'Second Shift')")->execute([$user['id'], $station_id]);
        }
    }
    
    header("Location: ../public/staff_transactions_hub.php?section=fuel");
    exit;
} else {
    echo "User not found!";
}
