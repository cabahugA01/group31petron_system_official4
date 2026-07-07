<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$user = current_user();
$station_id = user_station_id();
$shift = $_GET['shift'] ?? 'AM';
$staff_name = $_GET['staff'] ?? '';
$date = $_GET['date'] ?? date('Y-m-d');
$start_date = $date; // Use single date
$end_date = $date;

// Validate shift
if (!in_array($shift, ['AM', 'PM', 'NIGHT'])) {
    die('<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Invalid shift specified.</p></div>');
}

// Fetch detailed transactions for the specific shift and staff
$stmt = $pdo->prepare("
    SELECT 
        s.id as sale_id,
        s.sale_date,
        s.total as sale_total,
        s.payment_method,
        c.name as customer_name,
        u.name as staff_name,
        pt.name as category,
        p.name as product_name,
        si.quantity,
        si.unit_price,
        si.total_amount,
        CASE 
            WHEN TIME(s.sale_date) BETWEEN '06:00:00' AND '14:00:00' THEN 'AM' 
            ELSE 'PM' 
        END as shift
    FROM sales s
    LEFT JOIN sale_items si ON s.id = si.sale_id
    LEFT JOIN products p ON si.product_id = p.id
    LEFT JOIN product_types pt ON p.type_id = pt.id
    LEFT JOIN customers c ON s.customer_id = c.id
    LEFT JOIN users u ON s.user_id = u.id
    WHERE (s.station_id = ? OR s.station_id IS NULL)
    AND DATE(s.sale_date) BETWEEN ? AND ?
    AND (TIME(s.sale_date) BETWEEN ? AND ?)
    AND u.name = ?
    ORDER BY s.sale_date DESC, s.id DESC
");

$time_range = match($shift) {
    'AM' => ['06:00:00', '14:00:00'],
    'PM' => ['14:00:01', '22:00:00'],
    'NIGHT' => ['22:00:01', '23:59:59'],
    default => ['06:00:00', '14:00:00']
};
$stmt->execute([$station_id, $start_date, $end_date, $time_range[0], $time_range[1], $staff_name]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$fuel_total = 0;
$merch_total = 0;
$service_total = 0;
$grand_total = 0;
$total_transactions = 0;

foreach ($transactions as $transaction) {
    switch ($transaction['category']) {
        case 'fuel':
            $fuel_total += $transaction['total_amount'];
            break;
        case 'merch':
            $merch_total += $transaction['total_amount'];
            break;
        case 'service':
            $service_total += $transaction['total_amount'];
            break;
    }
    $grand_total += $transaction['total_amount'];
    $total_transactions++;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shift Details - <?php echo htmlspecialchars($staff_name); ?> (<?php echo $shift; ?> Shift)</title>
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
            background: rgba(0,0,0,0.5); 
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .modal-container {
            background: white;
            border-radius: 12px;
            width: 100%;
            max-width: 1000px;
            max-height: 90vh;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .modal-header {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: white;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h3 { font-size: 18px; font-weight: 600; }
        .close-btn {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            opacity: 0.8;
        }
        .close-btn:hover { opacity: 1; }
        .modal-body { 
            padding: 24px; 
            overflow-y: auto;
            flex: 1;
        }
        .info-bar {
            background: #f8fafc;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        .info-item {
            text-align: center;
        }
        .info-label {
            font-size: 12px;
            color: #64748b;
            margin-bottom: 4px;
        }
        .info-value {
            font-size: 16px;
            font-weight: 600;
            color: #0f172a;
        }
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .summary-card {
            background: linear-gradient(135deg, #f8fafc, #e2e8f0);
            border-radius: 8px;
            padding: 16px;
            text-align: center;
        }
        .summary-card.fuel { background: linear-gradient(135deg, #dbeafe, #bfdbfe); }
        .summary-card.merch { background: linear-gradient(135deg, #dcfce7, #bbf7d0); }
        .summary-card.service { background: linear-gradient(135deg, #e0e7ff, #c7d2fe); }
        .summary-card.total { background: linear-gradient(135deg, #fef3c7, #fde68a); }
        .summary-card.transactions { background: linear-gradient(135deg, #f3e8ff, #e9d5ff); }
        .summary-label { font-size: 12px; color: #64748b; margin-bottom: 4px; }
        .summary-value { font-size: 20px; font-weight: 700; color: #0f172a; }
        .transactions-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
        }
        .transactions-table th {
            background: #f8fafc;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            padding: 12px 8px;
            border-bottom: 2px solid #e2e8f0;
            text-align: left;
        }
        .transactions-table td {
            padding: 10px 8px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 13px;
        }
        .transactions-table tr:hover td {
            background-color: #f8fafc;
        }
        .badge {
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            color: white;
        }
        .badge-fuel { background: #3b82f6; }
        .badge-merch { background: #10b981; }
        .badge-service { background: #8b5cf6; }
        .badge-shift { background: #f59e0b; color: #333; }
        .empty-state { 
            text-align: center; 
            padding: 40px; 
            color: #64748b; 
        }
        .empty-state i { 
            font-size: 48px; 
            color: #ef4444; 
            margin-bottom: 16px; 
        }
        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #e2e8f0;
            text-align: right;
        }
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            font-size: 14px;
            margin-left: 8px;
        }
        .btn-secondary { background: #f1f5f9; color: #64748b; }
        .btn-secondary:hover { background: #e2e8f0; }
        .btn-primary { background: #2563eb; color: white; }
        .btn-primary:hover { background: #1d4ed8; }
    </style>
</head>
<body>
    <div class="modal-container">
        <div class="modal-header">
            <h3><i class="fas fa-user-clock"></i> Shift Details - <?php echo htmlspecialchars($staff_name); ?> (<?php echo $shift; ?> Shift)</h3>
            <button class="close-btn" onclick="window.close()">&times;</button>
        </div>
        
        <div class="modal-body">
            <div class="info-bar">
                <div class="info-item">
                    <div class="info-label">Staff Name</div>
                    <div class="info-value"><?php echo htmlspecialchars($staff_name); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Shift</div>
                    <div class="info-value">
                        <span class="badge badge-shift"><?php echo $shift; ?></span>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label">Period</div>
                    <div class="info-value"><?php echo date('M d, Y', strtotime($start_date)); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Total Transactions</div>
                    <div class="info-value"><?php echo $total_transactions; ?></div>
                </div>
            </div>
            
            <div class="summary-cards">
                <div class="summary-card fuel">
                    <div class="summary-label">Fuel Sales</div>
                    <div class="summary-value">₱<?php echo number_format($fuel_total, 2); ?></div>
                </div>
                <div class="summary-card merch">
                    <div class="summary-label">Merchandise Sales</div>
                    <div class="summary-value">₱<?php echo number_format($merch_total, 2); ?></div>
                </div>
                <div class="summary-card service">
                    <div class="summary-label">Service Sales</div>
                    <div class="summary-value">₱<?php echo number_format($service_total, 2); ?></div>
                </div>
                <div class="summary-card total">
                    <div class="summary-label">Grand Total</div>
                    <div class="summary-value">₱<?php echo number_format($grand_total, 2); ?></div>
                </div>
            </div>
            
            <?php if (!empty($transactions)): ?>
                <table class="transactions-table">
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Category</th>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Total</th>
                            <th>Customer</th>
                            <th>Payment</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $transaction): ?>
                            <tr>
                                <td><?php echo date('M d, h:i A', strtotime($transaction['sale_date'])); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo strtolower($transaction['category']); ?>">
                                        <?php echo ucfirst($transaction['category']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($transaction['product_name'] ?? '-'); ?></td>
                                <td><?php echo number_format($transaction['quantity'], 2); ?></td>
                                <td>₱<?php echo number_format($transaction['unit_price'], 2); ?></td>
                                <td><strong>₱<?php echo number_format($transaction['total_amount'], 2); ?></strong></td>
                                <td><?php echo htmlspecialchars($transaction['customer_name'] ?? 'Walk-in'); ?></td>
                                <td><?php echo htmlspecialchars($transaction['payment_method'] ?? '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-receipt"></i>
                    <p>No transactions found for this shift and staff member.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="modal-footer">
            <button class="btn btn-primary" onclick="printDetails()">
                <i class="fas fa-print"></i> Print
            </button>
            <button class="btn btn-secondary" onclick="window.close()">Close</button>
        </div>
    </div>

    <script>
        function printDetails() {
            window.print();
        }
    </script>
</body>
</html>
