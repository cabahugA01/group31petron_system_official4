<?php
/**
 * Export Variance Reports (Manager)
 * Supports Excel, CSV, PDF formats
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();

if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    die('Access denied');
}

$format = $_GET['format'] ?? 'pdf'; // Default to PDF for compliance reports

try {
    // Fetch variance data from fuel transactions (last 7 days)
    $query = $pdo->prepare("
        SELECT 
            fuel_type,
            DATE(COALESCE(transaction_date, created_at)) AS transaction_date,
            SUM(present_reading - previous_reading) AS meter_reading,
            SUM(liters_sold) AS pump_liters,
            SUM(ABS((present_reading - previous_reading) - liters_sold)) AS variance,
            COUNT(*) AS transaction_count
        FROM fuel_transactions 
        WHERE station_id = ?
          AND DATE(COALESCE(transaction_date, created_at)) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY fuel_type, DATE(COALESCE(transaction_date, created_at))
        HAVING variance > 0.5
        ORDER BY transaction_date DESC, variance DESC
    ");
    $query->execute([$station_id]);
    $variances = $query->fetchAll(PDO::FETCH_ASSOC);
    
    // Also check variance_reports table if exists
    $inventory_variances = [];
    try {
        $inv_query = $pdo->prepare("
            SELECT 
                product_name,
                expected_quantity,
                actual_quantity,
                variance_quantity,
                variance_value,
                report_type,
                created_at,
                resolved
            FROM variance_reports
            WHERE station_id = ?
              AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            ORDER BY created_at DESC
        ");
        $inv_query->execute([$station_id]);
        $inventory_variances = $inv_query->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Table might not exist
    }

    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="variance_reports_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Fuel Variance Section
        fputcsv($output, ['FUEL VARIANCE REPORT']);
        fputcsv($output, ['Fuel Type', 'Date', 'Meter Reading (L)', 'Pump Liters (L)', 'Variance (L)', 'Transactions']);
        
        foreach ($variances as $row) {
            fputcsv($output, [
                $row['fuel_type'],
                $row['transaction_date'],
                number_format($row['meter_reading'], 2),
                number_format($row['pump_liters'], 2),
                number_format($row['variance'], 2),
                $row['transaction_count']
            ]);
        }
        
        // Inventory Variance Section
        if (!empty($inventory_variances)) {
            fputcsv($output, []);
            fputcsv($output, ['INVENTORY VARIANCE REPORT']);
            fputcsv($output, ['Product', 'Expected', 'Actual', 'Variance Qty', 'Variance Value', 'Type', 'Date', 'Resolved']);
            
            foreach ($inventory_variances as $row) {
                fputcsv($output, [
                    $row['product_name'],
                    $row['expected_quantity'],
                    $row['actual_quantity'],
                    $row['variance_quantity'],
                    number_format($row['variance_value'], 2),
                    $row['report_type'],
                    date('Y-m-d H:i', strtotime($row['created_at'])),
                    $row['resolved'] ? 'Yes' : 'No'
                ]);
            }
        }
        
        fclose($output);
        exit;
    }
    
    if ($format === 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="variance_reports_' . date('Y-m-d') . '.xls"');
        
        echo "<h2>Variance Reports - " . date('Y-m-d') . "</h2>";
        
        // Fuel Variance
        echo "<h3>Fuel Variance (Last 7 Days)</h3>";
        echo "<table border='1'>";
        echo "<tr><th>Fuel Type</th><th>Date</th><th>Meter Reading (L)</th><th>Pump Liters (L)</th><th>Variance (L)</th><th>Transactions</th></tr>";
        
        foreach ($variances as $row) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['fuel_type']) . "</td>";
            echo "<td>" . $row['transaction_date'] . "</td>";
            echo "<td>" . number_format($row['meter_reading'], 2) . "</td>";
            echo "<td>" . number_format($row['pump_liters'], 2) . "</td>";
            echo "<td style='color:red;font-weight:bold;'>" . number_format($row['variance'], 2) . "</td>";
            echo "<td>" . $row['transaction_count'] . "</td>";
            echo "</tr>";
        }
        
        echo "</table><br>";
        
        // Inventory Variance
        if (!empty($inventory_variances)) {
            echo "<h3>Inventory Variance (Last 7 Days)</h3>";
            echo "<table border='1'>";
            echo "<tr><th>Product</th><th>Expected</th><th>Actual</th><th>Variance Qty</th><th>Variance Value</th><th>Type</th><th>Date</th><th>Resolved</th></tr>";
            
            foreach ($inventory_variances as $row) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($row['product_name']) . "</td>";
                echo "<td>" . $row['expected_quantity'] . "</td>";
                echo "<td>" . $row['actual_quantity'] . "</td>";
                echo "<td style='color:red;font-weight:bold;'>" . $row['variance_quantity'] . "</td>";
                echo "<td>₱" . number_format($row['variance_value'], 2) . "</td>";
                echo "<td>" . htmlspecialchars($row['report_type']) . "</td>";
                echo "<td>" . date('Y-m-d H:i', strtotime($row['created_at'])) . "</td>";
                echo "<td>" . ($row['resolved'] ? 'Yes' : 'No') . "</td>";
                echo "</tr>";
            }
            
            echo "</table>";
        }
        
        exit;
    }
    
    if ($format === 'pdf') {
        $html = "<!DOCTYPE html><html><head><title>Variance Compliance Report</title>";
        $html .= "<style>
            body{font-family:Arial,sans-serif;padding:20px;}
            h2{color:#002F70;border-bottom:3px solid #002F70;padding-bottom:10px;}
            h3{color:#444;margin-top:30px;}
            table{width:100%;border-collapse:collapse;margin-top:15px;font-size:11px;}
            th,td{border:1px solid #000;padding:8px;text-align:left;}
            th{background:#002F70;color:#fff;font-weight:bold;}
            .variance{color:red;font-weight:bold;}
            .summary{background:#f0f4ff;padding:15px;margin:20px 0;border-left:4px solid #002F70;}
        </style>";
        $html .= "</head><body>";
        
        // Header
        $html .= "<h2>VARIANCE COMPLIANCE REPORT</h2>";
        $html .= "<p><strong>Report Date:</strong> " . date('F d, Y H:i') . "</p>";
        $html .= "<p><strong>Period:</strong> Last 7 Days</p>";
        $html .= "<p><strong>Generated By:</strong> " . htmlspecialchars($me['full_name'] ?? ($me['name'] ?? 'Manager')) . " (Manager)</p>";
        
        // Summary
        $total_variance_value = 0;
        foreach ($variances as $row) {
            $total_variance_value += $row['variance'] * 50; // Assuming average ₱50/L
        }
        foreach ($inventory_variances as $row) {
            $total_variance_value += $row['variance_value'];
        }
        
        $html .= "<div class='summary'>";
        $html .= "<strong>EXECUTIVE SUMMARY</strong><br>";
        $html .= "Total Fuel Variance Incidents: " . count($variances) . "<br>";
        $html .= "Total Inventory Variance Incidents: " . count($inventory_variances) . "<br>";
        $html .= "Estimated Total Variance Value: PHP " . number_format($total_variance_value, 2);
        $html .= "</div>";
        
        // Fuel Variance
        $html .= "<h3>FUEL VARIANCE DETAILS</h3>";
        if (empty($variances)) {
            $html .= "<p style='color:green;'><strong>✓ No significant fuel variances detected in the past 7 days.</strong></p>";
        } else {
            $html .= "<table>";
            $html .= "<tr><th>Fuel Type</th><th>Date</th><th>Meter Reading (L)</th><th>Pump Liters (L)</th><th>Variance (L)</th><th>Transactions</th></tr>";
            
            foreach ($variances as $row) {
                $html .= "<tr>";
                $html .= "<td>" . htmlspecialchars($row['fuel_type']) . "</td>";
                $html .= "<td>" . $row['transaction_date'] . "</td>";
                $html .= "<td>" . number_format($row['meter_reading'], 2) . "</td>";
                $html .= "<td>" . number_format($row['pump_liters'], 2) . "</td>";
                $html .= "<td class='variance'>" . number_format($row['variance'], 2) . " L</td>";
                $html .= "<td>" . $row['transaction_count'] . "</td>";
                $html .= "</tr>";
            }
            
            $html .= "</table>";
        }
        
        // Inventory Variance
        if (!empty($inventory_variances)) {
            $html .= "<h3>INVENTORY VARIANCE DETAILS</h3>";
            $html .= "<table>";
            $html .= "<tr><th>Product</th><th>Expected</th><th>Actual</th><th>Variance Qty</th><th>Variance Value</th><th>Type</th><th>Date</th><th>Status</th></tr>";
            
            foreach ($inventory_variances as $row) {
                $html .= "<tr>";
                $html .= "<td>" . htmlspecialchars($row['product_name']) . "</td>";
                $html .= "<td>" . $row['expected_quantity'] . "</td>";
                $html .= "<td>" . $row['actual_quantity'] . "</td>";
                $html .= "<td class='variance'>" . $row['variance_quantity'] . "</td>";
                $html .= "<td>PHP " . number_format($row['variance_value'], 2) . "</td>";
                $html .= "<td>" . htmlspecialchars($row['report_type']) . "</td>";
                $html .= "<td>" . date('Y-m-d H:i', strtotime($row['created_at'])) . "</td>";
                $html .= "<td>" . ($row['resolved'] ? '<span style="color:green;">✓ Resolved</span>' : '<span style="color:orange;">⚠ Pending</span>') . "</td>";
                $html .= "</tr>";
            }
            
            $html .= "</table>";
        }
        
        // Footer
        $html .= "<div style='margin-top:40px;padding-top:20px;border-top:1px solid #ccc;font-size:10px;color:#666;'>";
        $html .= "<p><strong>Compliance Note:</strong> This report is generated for internal compliance and variance tracking purposes. ";
        $html .= "All variances should be investigated and documented according to company procedures.</p>";
        $html .= "<p><strong>Manager Signature:</strong> ___________________________ &nbsp;&nbsp;&nbsp; <strong>Date:</strong> " . date('Y-m-d') . "</p>";
        $html .= "</div>";
        
        $html .= "</body></html>";

        require_once __DIR__ . '/../../vendor/autoload.php';
        if (class_exists('\Mpdf\Mpdf')) {
            try {
                $temp_dir = __DIR__ . '/../../scratch';
                if (!is_dir($temp_dir)) @mkdir($temp_dir, 0777, true);
                if (!is_writable($temp_dir)) $temp_dir = sys_get_temp_dir();

                $mpdf = new \Mpdf\Mpdf([
                    'mode' => 'utf-8',
                    'format' => 'A4-P',
                    'margin_left' => 10,
                    'margin_right' => 10,
                    'margin_top' => 10,
                    'margin_bottom' => 10,
                    'tempDir' => $temp_dir,
                    'autoScriptToLang' => true,
                    'autoLangToFont' => true
                ]);
                $mpdf->SetTitle('Variance Compliance Report');
                $mpdf->WriteHTML($html);
                $pdf_filename = 'variance_compliance_report_' . date('Y-m-d') . '.pdf';
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="' . $pdf_filename . '"');
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                $mpdf->Output($pdf_filename, 'D');
                exit;
            } catch (\Throwable $e) {}
        }

        header('Content-Type: text/html; charset=utf-8');
        $print_script = "<script>window.onload = function() { window.focus(); window.print(); };</script>";
        $html = str_replace("</body>", $print_script . "</body>", $html);
        echo $html;
        exit;
    }

} catch (Exception $e) {
    die('Export error: ' . $e->getMessage());
}
