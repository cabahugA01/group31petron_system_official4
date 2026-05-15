<?php
// staff_home.php — redirects directly to staff_dashboard.php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Location: staff_dashboard.php');
exit;
?>
