<?php
require_once __DIR__ . '/../backend/lib.php';
require_login();
header('Location: manager_reports.php?cat=financial');
exit;
