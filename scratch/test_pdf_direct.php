<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/report_pdf_download.php';

$pdf = new SimpleReportPdf();
$pdf->render('MERCHANDISE INVENTORY REPORT', ['Vamenta Blvd, Carmen', 'Date: July 6, 2026 - August 5, 2026'], [
    [
        'title' => 'MERCHANDISE INVENTORY REPORT',
        'headers' => ['SKU', 'Batch ID', 'Product', 'Category', 'UOM', 'Initial Stock', 'Current Stock', 'Reorder Level', 'Expiration Date', 'Status'],
        'rows' => [
            ['P1012', 'BATCH-MAIN', '2T AUTOLUBE', 'Oils/Lubes/Grease', '60/200ml', '66', '66', '24', 'N/A', 'Available']
        ]
    ]
]);
$out = $pdf->output();
echo "Generated PDF size: " . strlen($out) . " bytes\n";
echo "PDF Magic Header: " . substr($out, 0, 8) . "\n";
