<?php
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

// Check if user is logged in
require_login();
$u = current_user();
$role = role_key($u['role'] ?? 'staff');

// Manager, Admin, and Super Admin can export fuel reconciliation
if (!in_array($role, ['manager','admin','superadmin'])) {
    header('Location: dashboard.php');
    exit;
}

// Get export parameters
$export_format = $_GET['export_format'] ?? '';
$date_range = $_GET['date_range'] ?? '';
$stations = $_GET['stations'] ?? [];

// Parse date range
$start_date = '';
$end_date = '';
if ($date_range) {
    $dates = explode(' to ', $date_range);
    $start_date = $dates[0] ?? '';
    $end_date = $dates[1] ?? $start_date;
}

// Get fuel reconciliation data
$reconciliation_data = [];
if ($start_date && $end_date) {
    try {
        // Get fuel deliveries (inflow) data
        $stmt = $pdo->prepare("SELECT station_id, fuel_type, SUM(volume) as total_inflow FROM fuel_deliveries WHERE delivery_date BETWEEN ? AND ? GROUP BY station_id, fuel_type");
        $stmt->execute([$start_date, $end_date]);
        $inflow_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get fuel daily readings (outflow) data
        $stmt = $pdo->prepare("SELECT dr.station_id, fs.fuel_type, SUM(dr.closing_volume - dr.opening_volume) as total_outflow FROM fuel_daily_readings dr JOIN fuel_stations fs ON dr.fuel_station_id = fs.id WHERE dr.reading_date BETWEEN ? AND ? GROUP BY dr.station_id, fs.fuel_type");
        $stmt->execute([$start_date, $end_date]);
        $outflow_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get stations list
        $stations_list = [];
        $stmt = $pdo->query("SELECT id, name FROM stations WHERE status = 'active' ORDER BY name");
        $stations_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Combine and calculate variance
        $fuel_data = [];
        
        // Process inflow data
        foreach ($inflow_data as $inflow) {
            $key = $inflow['station_id'] . '_' . $inflow['fuel_type'];
            $fuel_data[$key] = [
                'station_id' => $inflow['station_id'],
                'fuel_type' => $inflow['fuel_type'],
                'volume_in' => $inflow['total_inflow'],
                'volume_out' => 0,
                'variance' => 0,
                'status' => 'OK'
            ];
        }
        
        // Process outflow data and calculate variance
        foreach ($outflow_data as $outflow) {
            $key = $outflow['station_id'] . '_' . $outflow['fuel_type'];
            
            if (isset($fuel_data[$key])) {
                $fuel_data[$key]['volume_out'] = $outflow['total_outflow'];
                $fuel_data[$key]['variance'] = $fuel_data[$key]['volume_in'] - $fuel_data[$key]['volume_out'];
                
                // Set status based on variance threshold (5% tolerance)
                $variance_percent = abs($fuel_data[$key]['variance']) / max(1, $fuel_data[$key]['volume_in']) * 100;
                $fuel_data[$key]['status'] = $variance_percent > 5 ? 'Variance Alert' : 'OK';
            } else {
                $fuel_data[$key] = [
                    'station_id' => $outflow['station_id'],
                    'fuel_type' => $outflow['fuel_type'],
                    'volume_in' => 0,
                    'volume_out' => $outflow['total_outflow'],
                    'variance' => -$outflow['total_outflow'],
                    'status' => 'Variance Alert'
                ];
            }
        }
        
        // Convert to array and add station names
        foreach ($fuel_data as $data) {
            // Get station name
            $station_name = 'Unknown Station';
            foreach ($stations_list as $station) {
                if ($station['id'] == $data['station_id']) {
                    $station_name = $station['name'];
                    break;
                }
            }
            
            $reconciliation_data[] = [
                'date' => $start_date,
                'station' => $station_name,
                'fuel_type' => $data['fuel_type'],
                'volume_in' => $data['volume_in'],
                'volume_out' => $data['volume_out'],
                'variance' => $data['variance'],
                'status' => $data['status']
            ];
        }
        
    } catch (Exception $e) {
        die("Error fetching data: " . $e->getMessage());
    }
}

// Export based on format
switch ($export_format) {
    case 'excel':
        exportToExcel($reconciliation_data, $start_date, $end_date);
        break;
    case 'pdf':
        exportToPDF($reconciliation_data, $start_date, $end_date);
        break;
    default:
        die('Invalid export format');
}

function exportToExcel($data, $start_date, $end_date) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="fuel_reconciliation_report_' . date('Y-m-d') . '.xls"');
    
    echo "Fuel Reconciliation Report\n";
    echo "Date Range: " . $start_date . " to " . $end_date . "\n";
    echo "Generated: " . date('Y-m-d H:i:s') . "\n\n";
    
    echo "Date\tStation\tFuel Type\tVolume In (L)\tVolume Out (L)\tVariance (L)\tStatus\n";
    
    foreach ($data as $row) {
        echo $row['date'] . "\t" . $row['station'] . "\t" . $row['fuel_type'] . "\t" . 
             number_format($row['volume_in'], 2) . "\t" . number_format($row['volume_out'], 2) . "\t" . 
             number_format($row['variance'], 2) . "\t" . $row['status'] . "\n";
    }
    
    // Summary
    $total_inflow = array_sum(array_column($data, 'volume_in'));
    $total_outflow = array_sum(array_column($data, 'volume_out'));
    $total_variance = array_sum(array_column($data, 'variance'));
    
    echo "\nSUMMARY\n";
    echo "Total Volume In: " . number_format($total_inflow, 2) . " L\n";
    echo "Total Volume Out: " . number_format($total_outflow, 2) . " L\n";
    echo "Total Variance: " . number_format($total_variance, 2) . " L\n";
}

function exportToPDF($data, $start_date, $end_date) {
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Fuel Reconciliation Report</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .header { text-align: center; margin-bottom: 30px; }
            .header h1 { color: #333; }
            .info { margin-bottom: 20px; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background-color: #f2f2f2; font-weight: bold; }
            .status-ok { color: #28A745; font-weight: bold; }
            .status-alert { color: #DC3545; font-weight: bold; }
            .summary { margin-top: 30px; padding: 15px; background-color: #f9f9f9; border-radius: 5px; }
            .summary h3 { margin-top: 0; color: #333; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>Fuel Reconciliation Report</h1>
            <div class="info">
                <p><strong>Date Range:</strong> ' . $start_date . ' to ' . $end_date . '</p>
                <p><strong>Generated:</strong> ' . date('Y-m-d H:i:s') . '</p>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Station</th>
                    <th>Fuel Type</th>
                    <th>Volume In (L)</th>
                    <th>Volume Out (L)</th>
                    <th>Variance (L)</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>';
    
    foreach ($data as $row) {
        $statusClass = $row['status'] === 'OK' ? 'status-ok' : 'status-alert';
        $html .= '
                <tr>
                    <td>' . $row['date'] . '</td>
                    <td>' . htmlspecialchars($row['station']) . '</td>
                    <td>' . htmlspecialchars($row['fuel_type']) . '</td>
                    <td>' . number_format($row['volume_in'], 2) . '</td>
                    <td>' . number_format($row['volume_out'], 2) . '</td>
                    <td>' . number_format($row['variance'], 2) . '</td>
                    <td class="' . $statusClass . '">' . htmlspecialchars($row['status']) . '</td>
                </tr>';
    }
    
    $total_inflow = array_sum(array_column($data, 'volume_in'));
    $total_outflow = array_sum(array_column($data, 'volume_out'));
    $total_variance = array_sum(array_column($data, 'variance'));
    
    $html .= '
            </tbody>
        </table>
        
        <div class="summary">
            <h3>Summary</h3>
            <p><strong>Total Volume In:</strong> ' . number_format($total_inflow, 2) . ' L</p>
            <p><strong>Total Volume Out:</strong> ' . number_format($total_outflow, 2) . ' L</p>
            <p><strong>Total Variance:</strong> ' . number_format($total_variance, 2) . ' L</p>
        </div>
    </body>
    </html>';
    
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment;filename="fuel_reconciliation_report_' . date('Y-m-d') . '.pdf"');
    
    if (shell_exec('wkhtmltopdf --version')) {
        $temp_file = tempnam(sys_get_temp_dir(), 'pdf_') . '.html';
        file_put_contents($temp_file, $html);
        
        $pdf_file = tempnam(sys_get_temp_dir(), 'pdf_') . '.pdf';
        shell_exec("wkhtmltopdf $temp_file $pdf_file");
        
        if (file_exists($pdf_file)) {
            readfile($pdf_file);
            unlink($temp_file);
            unlink($pdf_file);
        } else {
            echo $html;
        }
    } else {
        echo $html;
    }
}
?>
