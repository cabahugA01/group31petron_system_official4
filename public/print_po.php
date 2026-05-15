<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

$po_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$po_id) {
    die("No PO ID provided");
}

// Fetch PO details with all related info
$stmt = $pdo->prepare("
    SELECT po.*, s.name as supplier_name, s.contact_person, s.phone as supplier_phone, s.email as supplier_email, s.address as supplier_address,
           u.name as created_by_name, u.email as created_by_email,
           approver.name as approved_by_name,
           st.name as station_name, st.location as station_address
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    JOIN users u ON po.created_by = u.id
    JOIN stations st ON po.station_id = st.id
    LEFT JOIN users approver ON po.approved_by = approver.id
    WHERE po.id = ?
");
$stmt->execute([$po_id]);
$po = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$po) {
    die("Purchase Order not found");
}

// Fetch all items
$stmt = $pdo->prepare("SELECT * FROM purchase_order_items WHERE po_id = ?");
$stmt->execute([$po_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$subtotal = 0;
foreach ($items as $item) {
    $subtotal += $item['total_price'];
}
$tax = $subtotal * 0.12; // 12% VAT
$total = $subtotal + $tax;

// Generate QR code URL (using Google Chart API)
$qr_data = urlencode($po['po_number'] . " | " . $po['station_name'] . " | Total: ₱" . number_format($total, 2));
$qr_url = "https://chart.googleapis.com/chart?cht=qr&chs=120x120&chl=$qr_data&choe=UTF-8";

// Status styling
$status_styles = [
    'Draft' => ['bg' => '#e9ecef', 'color' => '#6c757d', 'border' => '#6c757d'],
    'Pending Approval' => ['bg' => '#fff3cd', 'color' => '#856404', 'border' => '#ffc107'],
    'Approved' => ['bg' => '#d1fae5', 'color' => '#065f46', 'border' => '#10b981'],
    'Rejected' => ['bg' => '#f8d7da', 'color' => '#721c24', 'border' => '#dc3545'],
    'Pending' => ['bg' => '#cff4fc', 'color' => '#055160', 'border' => '#0dcaf0'],
    'Confirmed' => ['bg' => '#e0cffc', 'color' => '#4c1d95', 'border' => '#8b5cf6'],
    'Received' => ['bg' => '#d1fae5', 'color' => '#065f46', 'border' => '#10b981'],
    'Cancelled' => ['bg' => '#f8d7da', 'color' => '#721c24', 'border' => '#dc3545']
];

$style = $status_styles[$po['status']] ?? $status_styles['Draft'];
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Purchase Order - <?php echo htmlspecialchars($po['po_number']); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 12px;
            line-height: 1.5;
            color: #333;
            background: white;
            padding: 20px;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
            border: 2px solid #003d7a;
            padding: 30px;
        }
        
        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 3px solid #003d7a;
        }
        
        .company-info h1 {
            color: #003d7a;
            font-size: 24px;
            margin-bottom: 5px;
        }
        
        .company-info p {
            color: #666;
            font-size: 11px;
        }
        
        .po-number-section {
            text-align: right;
        }
        
        .po-label {
            font-size: 11px;
            color: #666;
            text-transform: uppercase;
        }
        
        .po-number {
            font-size: 20px;
            font-weight: bold;
            color: #003d7a;
        }
        
        .qr-code {
            margin-top: 10px;
        }
        
        .qr-code img {
            width: 100px;
            height: 100px;
        }
        
        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 8px 16px;
            background: <?php echo $style['bg']; ?>;
            color: <?php echo $style['color']; ?>;
            border: 2px solid <?php echo $style['border']; ?>;
            border-radius: 4px;
            font-weight: bold;
            font-size: 14px;
            margin-bottom: 20px;
        }
        
        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .info-box {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
        }
        
        .info-box h3 {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 10px;
            border-bottom: 1px solid #dee2e6;
            padding-bottom: 5px;
        }
        
        .info-box p {
            margin-bottom: 5px;
        }
        
        .info-box .label {
            font-weight: bold;
            color: #003d7a;
        }
        
        /* Items Table */
        .items-section {
            margin-bottom: 30px;
        }
        
        .items-section h3 {
            font-size: 14px;
            color: #003d7a;
            margin-bottom: 10px;
            text-transform: uppercase;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        th {
            background: #003d7a;
            color: white;
            padding: 10px;
            text-align: left;
            font-weight: 600;
        }
        
        td {
            padding: 10px;
            border-bottom: 1px solid #dee2e6;
        }
        
        tr:nth-child(even) {
            background: #f8f9fa;
        }
        
        .text-right {
            text-align: right;
        }
        
        /* Totals */
        .totals-section {
            margin-left: auto;
            width: 300px;
            margin-bottom: 30px;
        }
        
        .totals-section table {
            margin-bottom: 0;
        }
        
        .totals-section td {
            border: none;
            padding: 5px 10px;
        }
        
        .totals-section .total-row {
            font-weight: bold;
            font-size: 14px;
            background: #003d7a;
            color: white;
        }
        
        /* Remarks */
        .remarks-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 30px;
        }
        
        .remarks-section h3 {
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
        }
        
        /* Signatures */
        .signatures {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 50px;
            margin-top: 50px;
            page-break-inside: avoid;
        }
        
        .signature-box {
            text-align: center;
        }
        
        .signature-line {
            border-top: 1px solid #333;
            margin-top: 60px;
            padding-top: 10px;
        }
        
        .signature-name {
            font-weight: bold;
            font-size: 13px;
        }
        
        .signature-title {
            font-size: 11px;
            color: #666;
        }
        
        .date-line {
            margin-top: 10px;
            font-size: 11px;
        }
        
        /* Footer */
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #dee2e6;
            text-align: center;
            font-size: 10px;
            color: #666;
        }
        
        /* Print Button */
        .print-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #003d7a;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
        }
        
        .print-btn:hover {
            background: #002a54;
        }
        
        @media print {
            .print-btn {
                display: none;
            }
            
            body {
                padding: 0;
            }
            
            .container {
                border: none;
                padding: 20px;
            }
        }
        
        /* Approval Stamp */
        .approval-stamp {
            position: absolute;
            top: 150px;
            right: 50px;
            transform: rotate(-15deg);
            border: 3px solid #198754;
            color: #198754;
            padding: 10px 20px;
            font-size: 24px;
            font-weight: bold;
            text-transform: uppercase;
            opacity: 0.7;
            display: <?php echo ($po['status'] === 'Approved') ? 'block' : 'none'; ?>;
        }
    </style>
</head>
<body>
    <button class="print-btn" onclick="window.print()">
        <i class="fas fa-print"></i> Print PO / Save as PDF
    </button>
    
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="company-info">
                <h1>Purchase Order</h1>
                <p><?php echo nl2br(htmlspecialchars($po['station_address'] ?? 'Station Address')); ?></p>
            </div>
            <div class="po-number-section">
                <div class="po-label">Purchase Order</div>
                <div class="po-number"><?php echo htmlspecialchars($po['po_number']); ?></div>
                <div class="qr-code">
                    <img src="<?php echo $qr_url; ?>" alt="QR Code">
                    <div style="font-size: 9px; text-align: center; margin-top: 2px;">Scan to verify</div>
                </div>
            </div>
        </div>
        
        <!-- Status Badge -->
        <div style="text-align: center; margin-bottom: 20px;">
            <span class="status-badge">
                <?php echo strtoupper($po['status']); ?>
            </span>
        </div>
        
        <!-- Info Grid -->
        <div class="info-grid">
            <div class="info-box">
                <h3>Supplier Information</h3>
                <p><span class="label">Name:</span> <?php echo htmlspecialchars($po['supplier_name']); ?></p>
                <?php if ($po['contact_person']): ?>
                <p><span class="label">Contact:</span> <?php echo htmlspecialchars($po['contact_person']); ?></p>
                <?php endif; ?>
                <?php if ($po['supplier_phone']): ?>
                <p><span class="label">Phone:</span> <?php echo htmlspecialchars($po['supplier_phone']); ?></p>
                <?php endif; ?>
                <?php if ($po['supplier_email']): ?>
                <p><span class="label">Email:</span> <?php echo htmlspecialchars($po['supplier_email']); ?></p>
                <?php endif; ?>
                <?php if ($po['supplier_address']): ?>
                <p><span class="label">Address:</span> <?php echo nl2br(htmlspecialchars($po['supplier_address'])); ?></p>
                <?php endif; ?>
            </div>
            
            <div class="info-box">
                <h3>Order Information</h3>
                <p><span class="label">Station:</span> <?php echo htmlspecialchars($po['station_name']); ?></p>
                <p><span class="label">Date:</span> <?php echo date('F d, Y', strtotime($po['created_at'])); ?></p>
                <p><span class="label">Requested By:</span> <?php echo htmlspecialchars($po['created_by_name']); ?></p>
                <?php if ($po['expected_delivery_date']): ?>
                <p><span class="label">Expected Delivery:</span> <?php echo date('F d, Y', strtotime($po['expected_delivery_date'])); ?></p>
                <?php endif; ?>
                <?php if ($po['approved_by_name']): ?>
                <p><span class="label">Approved By:</span> <?php echo htmlspecialchars($po['approved_by_name']); ?></p>
                <p><span class="label">Approval Date:</span> <?php echo date('F d, Y g:i A', strtotime($po['approved_at'])); ?></p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Items Table -->
        <div class="items-section">
            <h3>Order Items</h3>
            <table>
                <thead>
                    <tr>
                        <th style="width: 5%;">#</th>
                        <th style="width: 40%;">Description</th>
                        <th style="width: 15%;" class="text-right">Quantity</th>
                        <th style="width: 20%;" class="text-right">Unit Price</th>
                        <th style="width: 20%;" class="text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $index => $item): ?>
                    <tr>
                        <td><?php echo $index + 1; ?></td>
                        <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                        <td class="text-right"><?php echo number_format($item['quantity'], 0); ?></td>
                        <td class="text-right">₱<?php echo number_format($item['unit_price'], 2); ?></td>
                        <td class="text-right">₱<?php echo number_format($item['total_price'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Totals -->
        <div class="totals-section">
            <table>
                <tr>
                    <td>Subtotal:</td>
                    <td class="text-right">₱<?php echo number_format($subtotal, 2); ?></td>
                </tr>
                <tr>
                    <td>VAT (12%):</td>
                    <td class="text-right">₱<?php echo number_format($tax, 2); ?></td>
                </tr>
                <tr class="total-row">
                    <td>TOTAL AMOUNT:</td>
                    <td class="text-right">₱<?php echo number_format($total, 2); ?></td>
                </tr>
            </table>
        </div>
        
        <!-- Remarks -->
        <?php if ($po['remarks']): ?>
        <div class="remarks-section">
            <h3>Remarks / Special Instructions</h3>
            <p><?php echo nl2br(htmlspecialchars($po['remarks'])); ?></p>
        </div>
        <?php endif; ?>
        
        <!-- Signatures -->
        <div class="signatures">
            <div class="signature-box">
                <div class="signature-line">
                    <div class="signature-name"><?php echo htmlspecialchars($po['created_by_name']); ?></div>
                    <div class="signature-title">Prepared By</div>
                    <div class="date-line">Date: _______________</div>
                </div>
            </div>
            
            <div class="signature-box">
                <div class="signature-line">
                    <div class="signature-name"><?php echo $po['approved_by_name'] ? htmlspecialchars($po['approved_by_name']) : '_______________________'; ?></div>
                    <div class="signature-title">Approved By (Station Manager)</div>
                    <div class="date-line">Date: <?php echo $po['approved_at'] ? date('M d, Y', strtotime($po['approved_at'])) : '_______________'; ?></div>
                </div>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="footer">
            <p><strong>This Purchase Order is valid only with authorized signatures and company stamp.</strong></p>
            <p>Questions? Contact: <?php echo htmlspecialchars($po['station_name']); ?> | Generated: <?php echo date('F d, Y g:i A'); ?></p>
            <p style="margin-top: 10px; font-size: 9px;">Document ID: <?php echo $po['po_number']; ?> | Page 1 of 1</p>
        </div>
    </div>
    
    <script>
        // Auto-print if requested
        <?php if (isset($_GET['print']) && $_GET['print'] == '1'): ?>
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        };
        <?php endif; ?>
    </script>
</body>
</html>