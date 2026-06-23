<?php
/**
 * Legacy pending-transaction route.
 *
 * Staff transactions are official immediately on save. Managers now use
 * Transaction Monitoring to Adjust, Void, or Correct transactions with audit.
 */
$page_id = 'manager_transactions';
require_once __DIR__ . '/../backend/lib.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');

if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Manager role required.';
    header('Location: staff_dashboard.php');
    exit;
}

$_SESSION['success'] = 'Transactions are official when saved. Use Adjust, Void, or Correct in Transaction Monitoring.';
header('Location: manager_transaction_monitoring.php');
exit;
