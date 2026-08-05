<?php
$_SERVER['REQUEST_METHOD'] = 'POST';
session_start();
$_SESSION['user'] = ['id' => 1, 'role' => 'admin', 'first_name' => 'Admin', 'last_name' => 'User'];

$payload = [
    'filename' => 'Test_Report.pdf',
    'title' => 'MERCHANDISE INVENTORY REPORT',
    'metaLines' => ['Station Address: Vamenta Blvd.', 'Date: July 6, 2026 – August 5, 2026'],
    'sections' => [
        [
            'title' => 'MERCHANDISE INVENTORY REPORT',
            'headers' => ['SKU', 'Product', 'Batch ID', 'Current Stock', 'Status'],
            'rows' => [
                ['P1012', '2T AUTOLUBE', 'BT-001', '66', 'Available'],
                ['P5018', 'ARMOR ALL BIG', 'BT-002', '0', 'Out of Stock']
            ]
        ]
    ]
];

// Capture output of PDF generator logic
require_once __DIR__ . '/../public/report_pdf_download.php';
