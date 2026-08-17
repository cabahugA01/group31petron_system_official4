<?php
/**
 * manager_shift_transactions.php -> manager_validated_transactions.php
 * Permanent redirect shim to ensure no 404s for any old or existing notification links.
 */
$queryString = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
header('Location: manager_validated_transactions.php' . $queryString, true, 302);
exit;
