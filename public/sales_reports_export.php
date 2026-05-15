<?php
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

// Access control: Manager/Admin/Super Admin
require_login();
$u = current_user();
$roleKey = function_exists('role_key') ? role_key($u['role'] ?? 'staff') : strtolower(trim((string)($u['role'] ?? 'staff')));

if (!in_array($roleKey, ['manager','admin','superadmin'])) {
    header('Location: dashboard.php');
    exit;
}

// Get export parameters
$export_format = $_GET['export_format'] ?? '';
$date_range = $_GET['date_range'] ?? '';
$branches = $_GET['branches'] ?? [];

// Parse date range
$start_date = '';
$end_date = '';
if ($date_range) {
    $dates = explode(' to ', $date_range);
    $start_date = $dates[0] ?? '';
    $end_date = $dates[1] ?? $start_date;
}

// Get sales data
$sales_data = [];
if ($start_date && $end_date) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM sales WHERE sale_date BETWEEN ? AND ? ORDER BY sale_date DESC");
        $stmt->execute([$start_date, $end_date]);
        $real_sales_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Group by date and calculate daily summaries
        $daily_summary = [];
        foreach ($real_sales_data as $sale) {
            $date = $sale['sale_date'];
            
            if (!isset($daily_summary[$date])) {
                $daily_summary[$date] = [
                    'date' => $date,
                    'sales' => 0,
                    'transactions' => 0,
                    'total' => 0,
                    'branch' => 'Main Branch'
                ];
            }
            
            $daily_summary[$date]['sales'] += $sale['total'];
            $daily_summary[$date]['total'] += $sale['total'];
            $daily_summary[$date]['transactions'] += 1;
        }
        
        $sales_data = array_values($daily_summary);
        
    } catch (Exception $e) {
        die("Error fetching data: " . $e->getMessage());
    }
}

// Export based on format
switch ($export_format) {
    case 'excel':
        exportToExcel($sales_data, $start_date, $end_date);
        break;
    case 'pdf':
        exportToPDF($sales_data, $start_date, $end_date);
        break;
    default:
        die('Invalid export format');
}

function exportToExcel($data, $start_date, $end_date) {
    // Set headers
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="sales_report_' . date('Y-m-d') . '.xls"');
    
    // Create Excel content
    echo "Sales Report\n";
    echo "Date Range: " . $start_date . " to " . $end_date . "\n";
    echo "Generated: " . date('Y-m-d H:i:s') . "\n\n";
    
    // Headers
    echo "Date\tBranch\tSales\tTransactions\tAverage Sale\n";
    
    // Data rows
    foreach ($data as $row) {
        $avg_sale = $row['transactions'] > 0 ? $row['sales'] / $row['transactions'] : 0;
        echo $row['date'] . "\t" . $row['branch'] . "\t" . $row['sales'] . "\t" . $row['transactions'] . "\t" . number_format($avg_sale, 2) . "\n";
    }
    
    // Summary
    $total_sales = array_sum(array_column($data, 'sales'));
    $total_transactions = array_sum(array_column($data, 'transactions'));
    echo "\nSUMMARY\n";
    echo "Total Sales: ₱" . number_format($total_sales, 2) . "\n";
    echo "Total Transactions: " . $total_transactions . "\n";
    echo "Average Sale: ₱" . number_format($total_transactions > 0 ? $total_sales / $total_transactions : 0, 2) . "\n";
}

function exportToPDF($data, $start_date, $end_date) {
    // Create HTML content
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Sales Report</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .header { text-align: center; margin-bottom: 30px; }
            .header h1 { color: #333; }
            .info { margin-bottom: 20px; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background-color: #f2f2f2; font-weight: bold; }
            .summary { margin-top: 30px; padding: 15px; background-color: #f9f9f9; border-radius: 5px; }
            .summary h3 { margin-top: 0; color: #333; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>Sales Report</h1>
            <div class="info">
                <p><strong>Date Range:</strong> ' . $start_date . ' to ' . $end_date . '</p>
                <p><strong>Generated:</strong> ' . date('Y-m-d H:i:s') . '</p>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Branch</th>
                    <th>Sales</th>
                    <th>Transactions</th>
                    <th>Average Sale</th>
                </tr>
            </thead>
            <tbody>';
    
    // Data rows
    foreach ($data as $row) {
        $avg_sale = $row['transactions'] > 0 ? $row['sales'] / $row['transactions'] : 0;
        $html .= '
                <tr>
                    <td>' . $row['date'] . '</td>
                    <td>' . htmlspecialchars($row['branch']) . '</td>
                    <td>₱' . number_format($row['sales'], 2) . '</td>
                    <td>' . $row['transactions'] . '</td>
                    <td>₱' . number_format($avg_sale, 2) . '</td>
                </tr>';
    }
    
    // Summary
    $total_sales = array_sum(array_column($data, 'sales'));
    $total_transactions = array_sum(array_column($data, 'transactions'));
    $html .= '
            </tbody>
        </table>
        
        <div class="summary">
            <h3>Summary</h3>
            <p><strong>Total Sales:</strong> ₱' . number_format($total_sales, 2) . '</p>
            <p><strong>Total Transactions:</strong> ' . $total_transactions . '</p>
            <p><strong>Average Sale:</strong> ₱' . number_format($total_transactions > 0 ? $total_sales / $total_transactions : 0, 2) . '</p>
        </div>
    </body>
    </html>';
    
    // Set headers for PDF
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment;filename="sales_report_' . date('Y-m-d') . '.pdf"');
    
    // Convert HTML to PDF using wkhtmltopdf (if available) or fallback to HTML
    if (shell_exec('wkhtmltopdf --version')) {
        // Use wkhtmltopdf if available
        $temp_file = tempnam(sys_get_temp_dir(), 'pdf_') . '.html';
        file_put_contents($temp_file, $html);
        
        $pdf_file = tempnam(sys_get_temp_dir(), 'pdf_') . '.pdf';
        shell_exec("wkhtmltopdf $temp_file $pdf_file");
        
        if (file_exists($pdf_file)) {
            readfile($pdf_file);
            unlink($temp_file);
            unlink($pdf_file);
        } else {
            // Fallback to HTML if wkhtmltopdf fails
            echo $html;
        }
    } else {
        // Fallback to HTML if wkhtmltopdf not available
        echo $html;
    }
}
?>
