<?php
/**
 * Legacy pending-transaction route.
 *
 * Staff transactions are official immediately on save. Managers now use
 * All Transactions to view details, adjust, void, or reprint receipts.
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

$_SESSION['success'] = 'Transactions are official when saved. Use All Transactions to view details, adjust, void, or reprint receipts.';
header('Location: manager_validated_transactions.php');
exit;
