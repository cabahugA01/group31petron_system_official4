<?php
// ============================================================
// Reports Router - public/reports.php
// Routes to specific report sections based on GET parameter
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'reports';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

// Access control - admin and superadmin only
if (!in_array($role, ['admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Admin access required.';
    header('Location: admin_dashboard.php'); 
    exit;
}

// Get the requested section
$section = $_GET['section'] ?? 'sales';

// Route to appropriate report file
switch ($section) {
    case 'sales':
        header('Location: sales_reports.php');
        break;
    case 'deliveries':
        header('Location: admin_deliveries_oversight.php');
        break;
    case 'staff':
        header('Location: staff_reports.php');
        break;
    case 'inventory':
        header('Location: inventory_reports.php');
        break;
    case 'profit_loss':
        header('Location: profit_loss_reports.php');
        break;
    case 'variance':
        header('Location: variance_reports.php');
        break;
    case 'job_orders':
        header('Location: job_order_reports.php');
        break;
    case 'developer':
        if ($role === 'superadmin') {
            header('Location: developer_reports.php');
        } else {
            $_SESSION['error'] = 'Access denied. Super Admin access required.';
            header('Location: admin_dashboard.php');
        }
        break;
    case 'manager':
        if (in_array($role, ['admin', 'manager', 'superadmin'])) {
            header('Location: manager_reports.php');
        } else {
            $_SESSION['error'] = 'Access denied. Manager access required.';
            header('Location: admin_dashboard.php');
        }
        break;
    default:
        // Default to sales reports
        header('Location: sales_reports.php');
        break;
}
exit;
?>
