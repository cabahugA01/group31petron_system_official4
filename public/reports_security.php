<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../backend/lib.php';
require_login();

header('Location: reports_technical.php?tab=security');
exit;
