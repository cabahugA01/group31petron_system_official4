<?php
session_start();
$_SESSION['user_id'] = 8; // User ID for Yyang

// Mock GET parameters
$_GET['report_date'] = '2026-07-08';
$_GET['tab'] = 'fuel';

// Capture output
ob_start();
include __DIR__ . '/../public/staff_fuel_sales_summary.php';
$html = ob_get_clean();

// Save captured HTML
file_put_contents(__DIR__ . '/yyang_report_output.html', $html);
echo "HTML Saved to yyang_report_output.html\n";
