<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../backend/lib.php';
require_login();

/**
 * Admin Finance Reports Router
 * Redirects to the Unified Master Admin Reports page under the Financial Reports category
 */
header('Location: admin_reports.php?cat=financial');
exit;
