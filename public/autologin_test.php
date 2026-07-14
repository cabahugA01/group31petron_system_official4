<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';

$username = $_GET['user'] ?? 'yyang';
$stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
$stmt->execute([$username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['station_id'] = $user['station_id'];
    if ($user['role'] === 'admin') {
        header("Location: admin_purchase_orders.php");
    } else {
        header("Location: staff_dashboard.php");
    }
    exit;
} else {
    echo "User not found";
}
