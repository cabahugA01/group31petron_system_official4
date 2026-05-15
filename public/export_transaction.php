<?php
// Export single transaction
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$transaction_id = $_GET['id'] ?? 0;
$station_id = user_station_id();

// Get export configuration from database
$export_config = [];
try {
    $stmt = $pdo->query("SELECT * FROM export_config WHERE active = 1 ORDER BY sort_order");
    $export_config = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch(Exception $e) {
    // Fallback to default configuration
    $export_config = [
        'report_title' => 'PETRON STATION TRANSACTION REPORT',
        'currency_symbol' => '₱',
        'date_format' => 'F j, Y H:i:s',
        'decimal_places' => 2,
        'system_name' => 'Petron Station Management System'
    ];
}

$transaction = null;

// Check if it's a fuel transaction (starts with FUELREC_)
if (strpos($transaction_id, 'FUELREC_') === 0) {
    // Get fuel transaction details
    $stmt = $pdo->prepare("SELECT 
        ft.transaction_id,
        ft.created_at,
        ft.status,
        u.name as staff_name,
        'Fuel Transaction' as customer,
        ft.payment_method,
        ft.fuel_type as product_name,
        ft.liters_sold as quantity,
        ft.price_per_liter as unit_price,
        ft.total_amount,
        'Fuel' as category,
        ft.pump_id,
        ft.present_reading,
        ft.previous_reading,
        ft.calibration
    FROM fuel_transactions ft
    LEFT JOIN users u ON ft.staff_id = u.id
    WHERE ft.transaction_id = ? AND ft.station_id = ?");
    
    $stmt->execute([$transaction_id, $station_id]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    // Get regular sales transaction details
    $stmt = $pdo->prepare("SELECT 
        s.id as transaction_id,
        s.created_at,
        s.status,
        u.name as staff_name,
        COALESCE(c.name, s.customer, 'Walk-in') as customer,
        s.payment_method,
        si.name as product_name,
        si.quantity,
        si.unit_price,
        si.total_amount,
        CASE 
            WHEN ip.category = 'Fuel' THEN 'Fuel'
            WHEN ip.category IS NOT NULL THEN ip.category
            WHEN pt.name IS NOT NULL THEN pt.name
            ELSE 'Merchandise'
        END as category,
        si.pump_id,
        si.nozzle_id
    FROM sales s
    JOIN sale_items si ON s.id = si.sale_id
    LEFT JOIN users u ON s.user_id = u.id
    LEFT JOIN customers c ON s.customer_id = c.id
    LEFT JOIN inventory_products ip ON si.name = ip.product_name
    LEFT JOIN products p ON si.product_id = p.id
    LEFT JOIN product_types pt ON p.type_id = pt.id
    WHERE s.id = ? AND s.station_id = ?");

    $stmt->execute([$transaction_id, $station_id]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$transaction) {
    die('Transaction not found');
}

// Set headers for Excel export
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="transaction_' . $transaction_id . '.xls"');
header('Pragma: no-cache');
header('Expires: 0');

// Get configuration values
$report_title = $export_config['report_title'] ?? 'PETRON STATION TRANSACTION REPORT';
$currency_symbol = $export_config['currency_symbol'] ?? '₱';
$decimal_places = $export_config['decimal_places'] ?? 2;
$system_name = $export_config['system_name'] ?? 'Petron Station Management System';

// Create professional HTML table for Excel
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title><?php echo htmlspecialchars($report_title); ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { background: #0066cc; color: white; padding: 10px; text-align: center; font-weight: bold; margin-bottom: 20px; }
        .section-title { background: #f0f0f0; padding: 10px; font-weight: bold; border: 1px solid #ddd; margin-top: 20px; }
        table { border-collapse: collapse; width: 100%; margin: 10px 0; }
        th { background: #4CAF50; color: white; font-weight: bold; padding: 12px 8px; border: 1px solid #ddd; text-align: left; }
        td { padding: 10px 8px; border: 1px solid #ddd; text-align: left; }
        .amount { text-align: right; font-weight: bold; }
        .center { text-align: center; }
        .fuel-info { background: #fff3cd; }
        .audit-info { background: #f8f9fa; }
        .total-row { background: #e8f5e8; font-weight: bold; }
        .header-info { margin-bottom: 15px; }
        .header-info div { margin: 5px 0; }
    </style>
</head>
<body>
    <div class="header"><?php echo htmlspecialchars($report_title); ?></div>
    
    <div class="header-info">
        <div><strong>Transaction ID:</strong> <?php echo htmlspecialchars($transaction['transaction_id']); ?></div>
        <div><strong>Date/Time:</strong> <?php echo date('Y-m-d H:i:s', strtotime($transaction['created_at'])); ?></div>
        <div><strong>Status:</strong> <span style="color: <?php echo $transaction['status'] == 'completed' ? 'green' : 'orange'; ?>;"><?php echo htmlspecialchars(strtoupper($transaction['status'])); ?></span></div>
        <div><strong>Generated:</strong> <?php echo date('F j, Y H:i:s'); ?></div>
    </div>

    <div class="section-title">TRANSACTION DETAILS</div>
    <table>
        <tr>
            <th>Field</th>
            <th>Value</th>
        </tr>
        <tr>
            <td>Staff</td>
            <td><?php echo htmlspecialchars($transaction['staff_name']); ?></td>
        </tr>
        <tr>
            <td>Customer</td>
            <td><?php echo htmlspecialchars($transaction['customer']); ?></td>
        </tr>
        <tr>
            <td>Payment Method</td>
            <td><?php echo htmlspecialchars(ucfirst($transaction['payment_method'])); ?></td>
        </tr>
        <tr>
            <td>Category</td>
            <td><?php echo htmlspecialchars($transaction['category']); ?></td>
        </tr>
        <tr>
            <td>Product</td>
            <td><?php echo htmlspecialchars($transaction['product_name']); ?></td>
        </tr>
        <tr>
            <td>Quantity/Liters</td>
            <td class="amount"><?php echo number_format($transaction['quantity'], $decimal_places); ?></td>
        </tr>
        <tr>
            <td>Unit Price</td>
            <td class="amount"><?php echo $currency_symbol . number_format($transaction['unit_price'], $decimal_places); ?></td>
        </tr>
        <tr class="total-row">
            <td>Total Amount</td>
            <td class="amount"><?php echo $currency_symbol . number_format($transaction['total_amount'], $decimal_places); ?></td>
        </tr>
    </table>

    <?php if ($transaction['category'] === 'Fuel' && isset($transaction['pump_id'])): ?>
    <div class="section-title fuel-info">FUEL TRANSACTION DETAILS</div>
    <table>
        <tr>
            <th>Fuel Field</th>
            <th>Value</th>
        </tr>
        <?php if (isset($transaction['pump_id'])): ?>
        <tr>
            <td>Pump ID</td>
            <td><?php echo htmlspecialchars($transaction['pump_id']); ?></td>
        </tr>
        <?php endif; ?>
        
        <?php if (isset($transaction['present_reading']) && isset($transaction['previous_reading'])): ?>
        <?php 
        $liters_sold = $transaction['present_reading'] - $transaction['previous_reading'] - ($transaction['calibration'] ?? 0);
        ?>
        <tr>
            <td>Present Reading</td>
            <td class="amount"><?php echo number_format($transaction['present_reading'], $decimal_places); ?> liters</td>
        </tr>
        <tr>
            <td>Previous Reading</td>
            <td class="amount"><?php echo number_format($transaction['previous_reading'], $decimal_places); ?> liters</td>
        </tr>
        <tr>
            <td>Calibration</td>
            <td class="amount"><?php echo number_format($transaction['calibration'] ?? 0, $decimal_places); ?> liters</td>
        </tr>
        <tr>
            <td>Liters Sold</td>
            <td class="amount" style="background: #e3f2fd;"><?php echo number_format($liters_sold, $decimal_places); ?> liters</td>
        </tr>
        <?php endif; ?>
    </table>
    <?php endif; ?>

    <div class="section-title audit-info">AUDIT INFORMATION</div>
    <table>
        <tr>
            <th>Audit Field</th>
            <th>Value</th>
        </tr>
        <tr>
            <td>Exported By</td>
            <td><?php echo htmlspecialchars($me['name'] ?? 'System'); ?></td>
        </tr>
        <tr>
            <td>Export Date</td>
            <td><?php echo date('F j, Y H:i:s'); ?></td>
        </tr>
        <tr>
            <td>Export Type</td>
            <td>Excel Format (.xls)</td>
        </tr>
        <tr>
            <td>System</td>
            <td><?php echo htmlspecialchars($system_name); ?></td>
        </tr>
    </table>

</body>
</html>
<?php
?>
