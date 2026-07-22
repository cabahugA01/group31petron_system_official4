<?php
require_once __DIR__ . '/../backend/lib.php';
require_login();

$_SESSION['info'] = 'Customer management is handled by the Manager. Use New Transaction to search/select a customer or request a new customer.';
header('Location: staff_transactions_hub.php?section=merchandise');
exit;
?>
