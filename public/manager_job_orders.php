<?php
// Deprecated legacy file - replaced by manager_validated_transactions.php
if (session_status() === PHP_SESSION_NONE) session_start();
$qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: manager_validated_transactions.php' . $qs);
exit;
