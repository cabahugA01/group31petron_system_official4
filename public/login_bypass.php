<?php
session_start();
require_once __DIR__ . '/db_connect.php';

try {
    // Find Edgar Eslit in database
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = 'Edgar' LIMIT 1");
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        unset($user['password_hash']);
        $_SESSION['user'] = $user;
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['current_shift_key'] = 'first';
        $_SESSION['current_shift_label'] = 'First Shift: 6:00 AM – 2:00 PM';
        
        header("Location: manager_fuel_transaction_validation.php");
        exit;
    } else {
        echo "Edgar not found or inactive";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
