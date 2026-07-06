<?php
// ============================================================
// Reports Router - public/reports.php
// Routes to specific report sections based on User Role
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'reports';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();  $me  = current_user();
$role = role_key($me['role'] ?? '');  if (in_array($role, ['admin', 'superadmin'])) {  header('Location: admin_reports.php');
} elseif ($role === 'manager') {  header('Location: manager_reports.php');
} else {  header('Location: staff_reports.php');
}
exit;
?>
