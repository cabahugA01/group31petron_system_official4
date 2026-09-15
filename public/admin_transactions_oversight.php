<?php
// Deprecated legacy file - redirected to active module
if (session_status() === PHP_SESSION_NONE) session_start();
$qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: manager_validated_transactions.php' . $qs);
exit;
