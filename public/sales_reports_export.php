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
    $u = current_user();
    $prepared_by_name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: ($u['username'] ?? 'System User');
    $user_role_label  = function_exists('role_key') ? ucfirst(role_key($u['role'] ?? 'staff')) : 'Staff';

    // Create HTML content
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Sales Report</title>
        <style>
            body { font-family: "Segoe UI", Arial, sans-serif; margin: 20px; color: #1e293b; }
            .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #002F6C; padding-bottom: 10px; }
            .header h1 { font-size: 18px; font-weight: 800; color: #002F6C; margin: 0 0 5px 0; letter-spacing: 0.5px; }
            .info { font-size: 11px; color: #334155; font-weight: 600; margin-top: 5px; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 11px; }
            th, td { border: 1px solid #cbd5e1; padding: 8px 10px; text-align: left; }
            th { background-color: #002F6C; color: #ffffff; font-weight: 700; border-color: #002F6C; }
            .summary { margin-top: 20px; padding: 15px; background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; }
            .summary h3 { margin-top: 0; color: #002F6C; font-size: 14px; }
            .sig-table { width: 100%; margin-top: 30px; page-break-inside: avoid; border: none; border-collapse: collapse; }
            .sig-table td { border: none; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>SALES REPORT</h1>
            <div style="font-size:12px; font-weight:700; color:#1e293b; margin-bottom:4px;">PETRON STATION</div>
            <div class="info">
                <span><strong>Date Range:</strong> ' . htmlspecialchars($start_date) . ' to ' . htmlspecialchars($end_date) . '</span>
                &nbsp;•&nbsp;
                <span><strong>Generated:</strong> ' . date('M j, Y h:i A') . '</span>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Branch</th>
                    <th style="text-align:right">Sales</th>
                    <th style="text-align:center">Transactions</th>
                    <th style="text-align:right">Average Sale</th>
                </tr>
            </thead>
            <tbody>';
    
    // Data rows
    foreach ($data as $row) {
        $avg_sale = $row['transactions'] > 0 ? $row['sales'] / $row['transactions'] : 0;
        $html .= '
                <tr>
                    <td>' . htmlspecialchars($row['date']) . '</td>
                    <td>' . htmlspecialchars($row['branch']) . '</td>
                    <td style="text-align:right">₱' . number_format($row['sales'], 2) . '</td>
                    <td style="text-align:center">' . $row['transactions'] . '</td>
                    <td style="text-align:right">₱' . number_format($avg_sale, 2) . '</td>
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

        <!-- PREPARED BY SIGNATURE -->
        <table class="sig-table">
            <tr>
                <td style="border:none;"></td>
                <td style="border:none; width:220px; text-align:center;">
                    <div style="font-size:10px; font-weight:700; color:#333; margin-bottom:25px;">PREPARED BY:</div>
                    <div style="border-top:1px solid #000; padding-top:4px; font-weight:700; font-size:11px; color:#000;">
                        ' . htmlspecialchars($prepared_by_name) . '
                    </div>
                    <div style="font-size:9.5px; color:#555; margin-top:2px;">' . htmlspecialchars($user_role_label) . '</div>
                </td>
            </tr>
        </table>
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
