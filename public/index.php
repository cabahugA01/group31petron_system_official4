<?php
session_start();  // FIX: Auto-correct role format if user is already logged in with raw DB values
if (isset($_SESSION['user']) && isset($_SESSION['user']['role'])) {  $r = $_SESSION['user']['role'];  if ($r === 'Super Admin') $_SESSION['user']['role'] = 'superadmin';  if ($r === 'Admin') $_SESSION['user']['role'] = 'admin';  if ($r === 'Staff') $_SESSION['user']['role'] = 'staff';  if ($r === 'operational_staff') $_SESSION['user']['role'] = 'staff';
}  // Check if user is logged in
if (isset($_SESSION['user_id']) && isset($_SESSION['user']) && isset($_SESSION['user']['role'])) {  $role = function_exists('role_key') ? role_key($_SESSION['user']['role'] ?? '') : strtolower(trim($_SESSION['user']['role']));  if ($role === 'superadmin') {  header("Location: super_admin_dashboard.php");  } elseif ($role === 'admin') {  header("Location: admin_dashboard.php");  } elseif ($role === 'manager') {  header("Location: manager_dashboard.php");  } else {  header("Location: staff_dashboard.php");  }  exit;
}  // If not logged in, go to the main login page
header("Location: login.php");
exit;
?>
