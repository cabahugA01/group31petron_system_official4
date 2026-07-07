<?php
// session_start() MUST come before ob_start()
session_start();
ob_start(); // Buffer output to prevent "headers already sent" errors  // Auto Clock Out for staff roles on logout
$staff_roles = ['staff', 'cashier', 'pump_attendant'];
$user_role  = strtolower(trim($_SESSION['role'] ?? $_SESSION['user']['role'] ?? ''));
$user_id  = $_SESSION['user_id'] ?? $_SESSION['user']['id'] ?? null;
$user_name  = $_SESSION['user']['name'] ?? $_SESSION['username'] ?? 'Unknown';  if ($user_id) {  try {  require_once __DIR__ . '/db_connect.php';  // Log logout to audit_logs  $tables = $pdo->query("SHOW TABLES LIKE 'audit_logs'")->fetchAll();  if (!empty($tables)) {  $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at)  VALUES (?, 'user', 'Logout', ?, 'users', ?, 'Success', ?, ?, NOW())")  ->execute([  $user_id,  $user_name . ' logged out',  $user_id,  $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',  $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',  ]);  }  // Auto Clock Out for staff roles  if (in_array($user_role, $staff_roles)) {  $stmt = $pdo->prepare(  "UPDATE labor_sessions  SET end_time = NOW(),  hours_worked = ROUND(TIMESTAMPDIFF(MINUTE, start_time, NOW()) / 60, 2)  WHERE user_id = ? AND end_time IS NULL"  );  $stmt->execute([$user_id]);  if ($stmt->rowCount() > 0) {  $tables2 = $pdo->query("SHOW TABLES LIKE 'activity_logs'")->fetchAll();  if (!empty($tables2)) {  $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'Clock Out', 'Auto clock-out on logout', ?)")  ->execute([$user_id, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);  }  }  }  } catch (Exception $e) { /* Fail silently, do not block logout */ }
}  // Clear all session variables
$_SESSION = [];  // Destroy the session
session_destroy();  // Redirect to login page
header('Location: login.php');
exit;
?>
