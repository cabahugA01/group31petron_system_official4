<?php
// Mock session for CLI test
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ACCEPT'] = 'application/json';
session_start();
$_SESSION['user'] = ['id' => 1, 'role' => 'admin', 'username' => 'admin'];

// Mock php://input
$payload = json_encode([
    'filename' => 'Admin_Report_inventory_merch_inventory.pdf',
    'title' => 'MERCHANDISE INVENTORY REPORT',
    'metaLines' => ['Vamenta Blvd., Carmen, Misamis Oriental', 'Date: July 6, 2026 - August 5, 2026'],
    'sections' => [
        [
            'title' => 'MERCHANDISE INVENTORY REPORT',
            'headers' => ['SKU', 'Batch ID', 'Product', 'Category', 'UOM', 'Initial Stock', 'Current Stock', 'Reorder Level', 'Expiration Date', 'Status'],
            'rows' => [
                ['P1012', 'BT-MAIN', '2T AUTOLUBE', 'Oils/Lubes', 'pcs', '50', '66', '24', 'N/A', 'Available']
            ]
        ]
    ]
]);

// Write mock payload to tmp input wrapper
file_put_contents(__DIR__ . '/mock_input.json', $payload);

echo "Payload prepared. Testing PDF generation logic...\n";
