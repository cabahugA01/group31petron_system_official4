<?php
// Redirect old inventory history route to the overhauled admin_inventory_history.php
if (session_status() === PHP_SESSION_NONE) session_start();
header("Location: admin_inventory_history.php" . (empty($_SERVER['QUERY_STRING']) ? '' : '?' . $_SERVER['QUERY_STRING']));
exit;
