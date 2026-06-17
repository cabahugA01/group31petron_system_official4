<?php
/**
 * Export Staff Merchandise History
 * Itemized sales export (Excel/CSV) and payment receipts (PDF)
 * Staff Side Only
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();

if (!in_array($role, ['staff', 'cashier', 'pump_attendant', 'manager', 'admin', 'superadmin'])) {
    die('Access denied');
}

$format = $_GET['format'] ?? 'excel';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// Validate format - Excel and CSV only
if (!in_array($format, ['excel', 'csv'])) {
    die('Invalid format');
}

try {
    // Fetch merchandise transactions with itemized details
    // Only standalone merchandise (exclude job-order-linked transactions)
    $query = $pdo->prepare("
        SELECT 
            mt.id,
            CONCAT('MT-', LPAD(mt.id, 5, '0')) AS transaction_ref,
            mt.transaction_id,
            COALESCE(NULLIF(TRIM(mt.customer_name), ''), 'Walk-in') AS customer_name,
            CASE 
                WHEN mt.transaction_date > '2000-01-01' THEN mt.transaction_date 
                ELSE mt.created_at 
            END AS transaction_date,
            mt.total_amount,
            COALESCE(mt.amount_paid, 0) AS amount_paid,
            (mt.total_amount - COALESCE(mt.amount_paid, 0)) AS balance_due,
            mt.payment_method,
            mt.payment_status,
            COALESCE(mt.validation_status, mt.status, 'Pending') AS validation_status,
            mt.shift_name,
            mt.shift_period,
            u.name AS staff_name,
            COALESCE(mt.staff_remarks, mt.remarks, '') AS remarks,
            (SELECT GROUP_CONCAT(
                CONCAT(mti.product_name, ' (Qty: ', mti.quantity, ', ₱', ROUND(mti.unit_price, 2), ', Subtotal: ₱', ROUND(mti.quantity * mti.unit_price, 2), ')')
                SEPARATOR '; '
            )
            FROM merchandise_transaction_items mti
            WHERE mti.transaction_id = mt.id
              AND COALESCE(mti.item_type, 'merchandise') = 'merchandise'
            ) AS items_detail
        FROM merchandise_transactions mt
        LEFT JOIN users u ON mt.staff_id = u.id
        WHERE mt.station_id = ?
          AND mt.staff_id = ?
          AND DATE(CASE WHEN mt.transaction_date > '2000-01-01' THEN mt.transaction_date ELSE mt.created_at END) BETWEEN ? AND ?
          AND COALESCE(mt.transaction_type, 'merchandise') = 'merchandise'
          AND (mt.job_order_service IS NULL OR TRIM(mt.job_order_service) = '')
        ORDER BY transaction_date DESC
        LIMIT 500
    ");
    $query->execute([$station_id, $me['id'], $date_from, $date_to]);
    $transactions = $query->fetchAll(PDO::FETCH_ASSOC);

    if (empty($transactions)) {
        die('No merchandise transaction records found for the selected period');
    }

    // Export based on format
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="merchandise_history_' . date('Y-m-d_His') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Transaction Ref', 'Date/Time', 'Customer', 'Items Detail', 'Total Amount', 'Paid', 'Balance', 'Payment Method', 'Payment Status', 'Status', 'Shift', 'Staff', 'Remarks']);
        
        foreach ($transactions as $row) {
            fputcsv($output, [
                $row['transaction_ref'],
                date('M d, Y H:i', strtotime($row['transaction_date'])),
                $row['customer_name'],
                $row['items_detail'] ?: 'No items',
                number_format((float)$row['total_amount'], 2),
                number_format((float)$row['amount_paid'], 2),
                number_format((float)$row['balance_due'], 2),
                $row['payment_method'],
                $row['payment_status'],
                $row['validation_status'],
                $row['shift_name'] ?: $row['shift_period'],
                $row['staff_name'],
                $row['remarks']
            ]);
        }
        
        fclose($output);
        exit;
        
    } elseif ($format === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="merchandise_history_' . date('Y-m-d_His') . '.xls"');
        
        echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
        echo '<head><meta charset="UTF-8">';
        echo '<style>table{border-collapse:collapse;}th,td{border:1px solid #ddd;padding:8px;}th{background-color:#16a34a;color:white;font-weight:bold;}</style>';
        echo '</head><body>';
        echo '<h2 style="color:#16a34a;">Merchandise Sales History</h2>';
        echo '<p>Staff: ' . htmlspecialchars($me['name']) . ' | Period: ' . date('M d, Y', strtotime($date_from)) . ' to ' . date('M d, Y', strtotime($date_to)) . '</p>';
        echo '<table>';
        echo '<thead><tr>';
        echo '<th>Ref</th><th>Date/Time</th><th>Customer</th><th>Items Detail</th><th>Total</th><th>Paid</th><th>Balance</th><th>Payment Method</th><th>Payment Status</th><th>Status</th><th>Shift</th><th>Remarks</th>';
        echo '</tr></thead><tbody>';
        
        $total_amount = 0;
        $total_paid = 0;
        $total_balance = 0;
        
        foreach ($transactions as $row) {
            $total_amount += (float)$row['total_amount'];
            $total_paid += (float)$row['amount_paid'];
            $total_balance += (float)$row['balance_due'];
            
            echo '<tr>';
            echo '<td>' . htmlspecialchars($row['transaction_ref']) . '</td>';
            echo '<td>' . date('M d, Y H:i', strtotime($row['transaction_date'])) . '</td>';
            echo '<td>' . htmlspecialchars($row['customer_name']) . '</td>';
            echo '<td>' . htmlspecialchars($row['items_detail'] ?: 'No items') . '</td>';
            echo '<td style="text-align:right;">₱' . number_format((float)$row['total_amount'], 2) . '</td>';
            echo '<td style="text-align:right;">₱' . number_format((float)$row['amount_paid'], 2) . '</td>';
            echo '<td style="text-align:right;">₱' . number_format((float)$row['balance_due'], 2) . '</td>';
            echo '<td>' . htmlspecialchars($row['payment_method']) . '</td>';
            echo '<td>' . htmlspecialchars($row['payment_status']) . '</td>';
            echo '<td>' . htmlspecialchars($row['validation_status']) . '</td>';
            echo '<td>' . htmlspecialchars($row['shift_name'] ?: $row['shift_period']) . '</td>';
            echo '<td>' . htmlspecialchars($row['remarks']) . '</td>';
            echo '</tr>';
        }
        
        echo '<tr style="background:#f0fdf4;font-weight:bold;">';
        echo '<td colspan="4" style="text-align:right;">TOTAL:</td>';
        echo '<td style="text-align:right;">₱' . number_format($total_amount, 2) . '</td>';
        echo '<td style="text-align:right;">₱' . number_format($total_paid, 2) . '</td>';
        echo '<td style="text-align:right;">₱' . number_format($total_balance, 2) . '</td>';
        echo '<td colspan="5"></td>';
        echo '</tr>';
        
        echo '</tbody></table></body></html>';
        exit;
    }

} catch (Exception $e) {
    die('Export error: ' . $e->getMessage());
}
