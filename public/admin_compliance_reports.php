<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../backend/lib.php';
require_login();

/**
 * Admin Compliance / Audit Reports Router
 * Redirects to the Unified Master Admin Reports page under the Audit Reports category
 */
header('Location: admin_reports.php?cat=audit');
exit;
