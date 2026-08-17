<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../backend/lib.php';
require_login();

/**
 * Merchandise Transactions API — proxy inside /public/ to avoid path issues.
 */
require_once __DIR__ . '/../backend/api/merchandise_transactions.php';
