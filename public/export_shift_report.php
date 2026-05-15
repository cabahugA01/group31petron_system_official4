<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$station_id = user_station_id();
$role = role_key($me['role'] ?? '');

// Restrict access to managers and admins only
if (!in_array($role, ['manager', 'admin', 'superadmin'], true)) {
    $_SESSION['error'] = 'Access denied. Manager access required.';
    header('Location: transactions.php');
    exit;
}

$shift_id = $_GET['shift_id'] ?? '';

if (empty($shift_id)) {
    $_SESSION['error'] = 'Shift ID is required';
    header('Location: transactions.php');
    exit;
}

try {
    // Get shift details
    $shift_sql = "SELECT 
        ls.id,
        ls.user_id as staff_id,
        u.name as staff_name,
        u.username,
        ls.station_id,
        s.name as station_name,
        ls.start_time,
        ls.end_time,
        ls.status,
        CASE 
            WHEN ls.end_time IS NULL THEN 'Active'
            WHEN ls.end_time IS NOT NULL THEN 'Completed'
            ELSE 'Unknown'
        END as shift_status
    FROM labor_sessions ls
    LEFT JOIN users u ON ls.user_id = u.id
    LEFT JOIN stations s ON ls.station_id = s.id
    WHERE ls.id = ? AND ls.station_id = ?";
    
    $stmt = $pdo->prepare($shift_sql);
    $stmt->execute([$shift_id, $station_id]);
    $shift = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$shift) {
        $_SESSION['error'] = 'Shift not found';
        header('Location: transactions.php');
        exit;
    }
    
    // Calculate duration
    $startTime = new DateTime($shift['start_time']);
    $endTime = $shift['end_time'] ? new DateTime($shift['end_time']) : new DateTime();
    $duration = $startTime->diff($endTime);
    $shift['duration'] = $duration->format('%dh %02dm');
    
    // Get transaction details
    $shiftStart = $shift['start_time'];
    $shiftEnd = $shift['end_time'] ?: date('Y-m-d H:i:s');
    
    $fuel_transactions = [];
    if (manager_shift_table_exists($pdo, 'fuel_transactions')) {
        $sql = "SELECT 
            ft.transaction_id,
            ft.fuel_type,
            ft.present_reading,
            ft.previous_reading,
            ft.calibration,
            ft.liters_sold,
            ft.price_per_liter,
            ft.total_amount,
            ft.payment_method,
            ft.status,
            ft.transaction_date,
            ft.created_at,
            u.name as staff_name
        FROM fuel_transactions ft
        LEFT JOIN users u ON ft.staff_id = u.id
        WHERE ft.staff_id = ? AND ft.station_id = ? 
        AND ft.transaction_date BETWEEN ? AND ?
        ORDER BY ft.transaction_date DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$shift['staff_id'], $station_id, $shiftStart, $shiftEnd]);
        $fuel_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    $merchandise_transactions = [];
    if (manager_shift_table_exists($pdo, 'sales')) {
        $sql = "SELECT 
            s.id as transaction_id,
            s.total,
            s.payment_method,
            s.status,
            s.created_at,
            u.name as staff_name,
            c.name as customer_name,
            GROUP_CONCAT(CONCAT(si.name, ' (', si.quantity, ')') SEPARATOR ', ') as items
        FROM sales s
        LEFT JOIN users u ON s.user_id = u.id
        LEFT JOIN customers c ON s.customer_id = c.id
        LEFT JOIN sale_items si ON s.id = si.sale_id
        WHERE s.user_id = ? AND s.station_id = ? 
        AND s.created_at BETWEEN ? AND ?
        GROUP BY s.id
        ORDER BY s.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$shift['staff_id'], $station_id, $shiftStart, $shiftEnd]);
        $merchandise_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Calculate summary
    $fuel_sales = array_sum(array_column($fuel_transactions, 'total_amount'));
    $fuel_liters = array_sum(array_column($fuel_transactions, 'liters_sold'));
    $merch_sales = array_sum(array_column($merchandise_transactions, 'total'));
    $total_sales = $fuel_sales + $merch_sales;
    $total_transactions = count($fuel_transactions) + count($merchandise_transactions);
    
    // Generate CSV content
    $filename = "shift_report_{$shift_id}_" . date('Y-m-d_H-i-s') . ".csv";
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Header
    fputcsv($output, ['Shift Report - ' . $shift['station_name']]);
    fputcsv($output, ['Generated: ' . date('Y-m-d H:i:s')]);
    fputcsv($output, []);
    
    // Shift Information
    fputcsv($output, ['SHIFT INFORMATION']);
    fputcsv($output, ['Shift ID', '#' . $shift['id']]);
    fputcsv($output, ['Staff Name', $shift['staff_name']]);
    fputcsv($output, ['Station', $shift['station_name']]);
    fputcsv($output, ['Start Time', date('Y-m-d H:i:s', strtotime($shift['start_time']))]);
    fputcsv($output, ['End Time', $shift['end_time'] ? date('Y-m-d H:i:s', strtotime($shift['end_time'])) : 'Active']);
    fputcsv($output, ['Duration', $shift['duration']]);
    fputcsv($output, ['Status', $shift['shift_status']]);
    fputcsv($output, []);
    
    // Summary
    fputcsv($output, ['TRANSACTION SUMMARY']);
    fputcsv($output, ['Fuel Sales', '¥' . number_format($fuel_sales, 2)]);
    fputcsv($output, ['Fuel Liters', number_format($fuel_liters, 2) . ' L']);
    fputcsv($output, ['Merchandise Sales', '¥' . number_format($merch_sales, 2)]);
    fputcsv($output, ['Total Sales', '¥' . number_format($total_sales, 2)]);
    fputcsv($output, ['Total Transactions', $total_transactions]);
    fputcsv($output, []);
    
    // Fuel Transactions
    if (!empty($fuel_transactions)) {
        fputcsv($output, ['FUEL TRANSACTIONS']);
        fputcsv($output, ['Transaction ID', 'Fuel Type', 'Liters Sold', 'Price/Liter', 'Total Amount', 'Payment Method', 'Status', 'Date/Time']);
        
        foreach ($fuel_transactions as $ft) {
            fputcsv($output, [
                '#' . $ft['transaction_id'],
                $ft['fuel_type'],
                number_format($ft['liters_sold'], 2) . ' L',
                '¥' . number_format($ft['price_per_liter'], 2),
                '¥' . number_format($ft['total_amount'], 2),
                $ft['payment_method'] ?: 'N/A',
                $ft['status'],
                date('Y-m-d H:i:s', strtotime($ft['transaction_date']))
            ]);
        }
        fputcsv($output, []);
    }
    
    // Merchandise Transactions
    if (!empty($merchandise_transactions)) {
        fputcsv($output, ['MERCHANDISE TRANSACTIONS']);
        fputcsv($output, ['Transaction ID', 'Customer', 'Items', 'Total Amount', 'Payment Method', 'Status', 'Date/Time']);
        
        foreach ($merchandise_transactions as $mt) {
            fputcsv($output, [
                '#' . $mt['transaction_id'],
                $mt['customer_name'] ?: 'Walk-in',
                $mt['items'] ?: '-',
                '¥' . number_format($mt['total'], 2),
                $mt['payment_method'] ?: '-',
                $mt['status'],
                date('Y-m-d H:i:s', strtotime($mt['created_at']))
            ]);
        }
        fputcsv($output, []);
    }
    
    // Footer
    fputcsv($output, ['Report End']);
    
    fclose($output);
    exit;
    
} catch (Exception $e) {
    $_SESSION['error'] = 'Error generating shift report: ' . $e->getMessage();
    header('Location: transactions.php');
    exit;
}

function manager_shift_table_exists(PDO $pdo, string $tableName): bool {
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$tableName]);
    return (bool)$stmt->fetchColumn();
}
?>
