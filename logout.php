<?php
/**
 * Root Logout Entry Point
 * Transparently redirects or forwards to public/logout.php
 */
$query = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
header('Location: public/logout.php' . $query);
exit;
