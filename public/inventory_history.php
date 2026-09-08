<?php
// Redirect old inventory history route to admin_inventory_merchandise.php?tab=movement
if (session_status() === PHP_SESSION_NONE) session_start();
require_login();
header("Location: admin_inventory_merchandise.php?tab=movement" . (empty($_SERVER['QUERY_STRING']) ? '' : '&' . $_SERVER['QUERY_STRING']));
exit;
