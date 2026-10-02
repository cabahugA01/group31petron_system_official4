<?php
/**
 * Root Login Entry Point
 * Transparently redirects or forwards to public/login.php
 */
$query = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
header('Location: public/login.php' . $query);
exit;
