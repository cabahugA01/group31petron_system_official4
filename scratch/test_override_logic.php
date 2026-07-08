<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

function test_user_shift($user_id) {
    global $pdo;
    
    // Fetch user details
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $me = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$me) return "User not found";
    
    $role = role_key($me['role'] ?? 'staff');
    $is_manager_or_admin  = in_array($role, ['manager', 'admin', 'superadmin', 'developer']);
    
    $user_current_shift = null;
    
    if (!$is_manager_or_admin) {
        try {
            $stmt = $pdo->prepare("
                SELECT shift_period
                FROM labor_sessions
                WHERE user_id = ? AND end_time IS NULL
                ORDER BY start_time DESC LIMIT 1
            ");
            $stmt->execute([$user_id]);
            $active_session = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($active_session && !empty($active_session['shift_period'])) {
                $sp = strtolower(trim($active_session['shift_period']));
                if (in_array($sp, ['1', 'shift1', 'shift 1', 'first', 'morning', 'am', 'day'])) {
                    $user_current_shift = 'shift1';
                } elseif (in_array($sp, ['2', 'shift2', 'shift 2', 'second', 'afternoon', 'pm', 'evening', 'night'])) {
                    $user_current_shift = 'shift2';
                }
            }
        } catch (Exception $e) {}
    }
    
    // Applying the override rule
    $username_lower = isset($me['username']) ? strtolower(trim($me['username'])) : '';
    $first_name_lower = isset($me['first_name']) ? strtolower(trim($me['first_name'])) : '';
    $last_name_lower = isset($me['last_name']) ? strtolower(trim($me['last_name'])) : '';

    if ($username_lower === 'yyang' || $first_name_lower === 'yyang') {
        $user_current_shift = 'shift1';
    } elseif ($username_lower === 'judy' || $first_name_lower === 'judy' || $last_name_lower === 'lastimosa') {
        $user_current_shift = 'shift2';
    }
    
    return "User: {$me['username']} ({$me['first_name']} {$me['last_name']}) => Resolved Shift: " . ($user_current_shift ?? 'null (24-Hour)');
}

echo test_user_shift(8) . "\n";
echo test_user_shift(9) . "\n";
