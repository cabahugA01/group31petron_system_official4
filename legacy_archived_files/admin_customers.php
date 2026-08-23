<?php
require_once __DIR__ . '/../backend/lib.php';
require_login();

$_SESSION['info'] = 'Customer management is Manager-only. Customer information is available through Admin Reports.';
header('Location: admin_reports.php?section=customers');
exit;
?>
