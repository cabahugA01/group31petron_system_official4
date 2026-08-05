<?php
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../public/reports/admin_reports_data.php';

$res = getAdminCustomerDetails($pdo, 40);
print_r($res);
