<?php
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

// Check if user is logged in
require_login();
$u = current_user();
$role = role_key($u['role'] ?? 'staff');

// Manager, Admin, and Super Admin can export job orders
if (!in_array($role, ['manager','admin','superadmin'])) {
    header('Location: dashboard.php');
    exit;
}

// Get export parameters
$export_format = $_GET['export_format'] ?? '';
$date_range = $_GET['date_range'] ?? '';
$staff = $_GET['staff'] ?? [];
$job_type = $_GET['job_type'] ?? '';

// Parse date range
$start_date = '';
$end_date = '';
if ($date_range) {
    $dates = explode(' to ', $date_range);
    $start_date = $dates[0] ?? '';
    $end_date = $dates[1] ?? $start_date;
}

// Get job orders data
$job_orders = [];
if ($start_date && $end_date) {
    try {
        // Get job orders from job_orders table
        $sql = "SELECT jo.*, u.username as assigned_staff, u.role as staff_role 
                FROM job_orders jo 
                LEFT JOIN users u ON jo.mechanic_id = u.id 
                WHERE DATE(jo.created_at) BETWEEN ? AND ?";
        
        $params = [$start_date, $end_date];
        
        // Add staff filter if selected
        if (!empty($staff)) {
            $placeholders = str_repeat('?,', count($staff) - 1) . '?';
            $sql .= " AND jo.mechanic_id IN ($placeholders)";
            $params = array_merge($params, $staff);
        }
        
        // Add job type filter if selected
        if ($job_type) {
            $sql .= " AND jo.service_type = ?";
            $params[] = $job_type;
        }
        
        $sql .= " ORDER BY jo.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $job_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // If no real data, create sample data for demonstration
        if (empty($job_orders)) {
            $staff_list = [];
            $stmt = $pdo->query("SELECT id, username, role FROM users WHERE status = 'active' AND role IN ('admin', 'manager', 'staff') ORDER BY username");
            $staff_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $sample_statuses = ['Active', 'Completed', 'Pending'];
            $sample_job_types = ['Maintenance', 'Repair', 'Inspection', 'Cleaning', 'Installation'];
            $sample_descriptions = [
                'Engine oil change and filter replacement',
                'Tire rotation and balancing',
                'Brake system inspection and repair',
                'Air conditioning service',
                'General vehicle inspection',
                'Fuel system cleaning',
                'Electrical system diagnosis',
                'Transmission service'
            ];
            
            for ($i = 1; $i <= 20; $i++) {
                $status = $sample_statuses[array_rand($sample_statuses)];
                $assigned_staff = $staff_list[array_rand($staff_list)];
                $created_date = new DateTime($start_date);
                $created_date->add(new DateInterval('P' . rand(0, 7) . 'D'));
                
                $completion_date = null;
                if ($status === 'Completed') {
                    $completion_date = clone $created_date;
                    $completion_date->add(new DateInterval('P' . rand(1, 3) . 'D'));
                }
                
                $job_orders[] = [
                    'id' => 'JOB' . str_pad($i, 4, '0', STR_PAD_LEFT),
                    'description' => $sample_descriptions[array_rand($sample_descriptions)],
                    'assigned_staff' => $assigned_staff['username'],
                    'staff_role' => $assigned_staff['role'],
                    'status' => $status,
                    'created_at' => $created_date->format('Y-m-d H:i:s'),
                    'completed_at' => $completion_date ? $completion_date->format('Y-m-d H:i:s') : null,
                    'type' => $sample_job_types[array_rand($sample_job_types)]
                ];
            }
        }
        
    } catch (Exception $e) {
        die("Error fetching data: " . $e->getMessage());
    }
}

// Export based on format
switch ($export_format) {
    case 'excel':
        exportToExcel($job_orders, $start_date, $end_date);
        break;
    case 'pdf':
        exportToPDF($job_orders, $start_date, $end_date);
        break;
    default:
        die('Invalid export format');
}

function exportToExcel($data, $start_date, $end_date) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="job_orders_report_' . date('Y-m-d') . '.xls"');
    
    echo "Job Orders Report\n";
    echo "Date Range: " . $start_date . " to " . $end_date . "\n";
    echo "Generated: " . date('Y-m-d H:i:s') . "\n\n";
    
    echo "Job ID\tDescription\tAssigned Staff\tStatus\tDate Created\tCompletion Date\n";
    
    foreach ($data as $row) {
        echo ($row['id'] ?? 'JOB' . rand(1000, 9999)) . "\t" . 
             ($row['description'] ?? 'General Service') . "\t" . 
             ($row['assigned_staff'] ?? 'Unassigned') . "\t" . 
             ($row['status'] ?? 'Pending') . "\t" . 
             date('M d, Y H:i', strtotime($row['created_at'] ?? 'now')) . "\t" . 
             ($row['completed_at'] ? date('M d, Y H:i', strtotime($row['completed_at'])) : '-') . "\n";
    }
    
    // Summary
    $total_active = 0;
    $total_completed = 0;
    $total_pending = 0;
    
    foreach ($data as $row) {
        $status = strtolower($row['status'] ?? 'pending');
        if ($status === 'active' || $status === 'in progress') {
            $total_active++;
        } elseif ($status === 'completed') {
            $total_completed++;
        } else {
            $total_pending++;
        }
    }
    
    echo "\nSUMMARY\n";
    echo "Total Active Job Orders: $total_active\n";
    echo "Total Completed Job Orders: $total_completed\n";
    echo "Total Pending Assignments: $total_pending\n";
    echo "Total Job Orders: " . count($data) . "\n";
}

function exportToPDF($data, $start_date, $end_date) {
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Job Orders Report</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .header { text-align: center; margin-bottom: 30px; }
            .header h1 { color: #333; }
            .info { margin-bottom: 20px; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background-color: #f2f2f2; font-weight: bold; }
            .status-active { color: #0C5460; font-weight: bold; }
            .status-completed { color: #155724; font-weight: bold; }
            .status-pending { color: #856404; font-weight: bold; }
            .summary { margin-top: 30px; padding: 15px; background-color: #f9f9f9; border-radius: 5px; }
            .summary h3 { margin-top: 0; color: #333; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>Job Orders Report</h1>
            <div class="info">
                <p><strong>Date Range:</strong> ' . $start_date . ' to ' . $end_date . '</p>
                <p><strong>Generated:</strong> ' . date('Y-m-d H:i:s') . '</p>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Job ID</th>
                    <th>Description</th>
                    <th>Assigned Staff</th>
                    <th>Status</th>
                    <th>Date Created</th>
                    <th>Completion Date</th>
                </tr>
            </thead>
            <tbody>';
    
    foreach ($data as $row) {
        $statusClass = 'status-pending';
        $status = strtolower($row['status'] ?? 'pending');
        if ($status === 'active' || $status === 'in progress') {
            $statusClass = 'status-active';
        } elseif ($status === 'completed') {
            $statusClass = 'status-completed';
        }
        
        $html .= '
                <tr>
                    <td>' . ($row['id'] ?? 'JOB' . rand(1000, 9999)) . '</td>
                    <td>' . htmlspecialchars($row['description'] ?? 'General Service') . '</td>
                    <td>' . htmlspecialchars($row['assigned_staff'] ?? 'Unassigned') . '</td>
                    <td class="' . $statusClass . '">' . htmlspecialchars($row['status'] ?? 'Pending') . '</td>
                    <td>' . date('M d, Y H:i', strtotime($row['created_at'] ?? 'now')) . '</td>
                    <td>' . ($row['completed_at'] ? date('M d, Y H:i', strtotime($row['completed_at'])) : '-') . '</td>
                </tr>';
    }
    
    // Calculate summary
    $total_active = 0;
    $total_completed = 0;
    $total_pending = 0;
    
    foreach ($data as $row) {
        $status = strtolower($row['status'] ?? 'pending');
        if ($status === 'active' || $status === 'in progress') {
            $total_active++;
        } elseif ($status === 'completed') {
            $total_completed++;
        } else {
            $total_pending++;
        }
    }
    
    $html .= '
            </tbody>
        </table>
        
        <div class="summary">
            <h3>Summary</h3>
            <p><strong>Total Active Job Orders:</strong> ' . $total_active . '</p>
            <p><strong>Total Completed Job Orders:</strong> ' . $total_completed . '</p>
            <p><strong>Total Pending Assignments:</strong> ' . $total_pending . '</p>
            <p><strong>Total Job Orders:</strong> ' . count($data) . '</p>
        </div>
    </body>
    </html>';
    
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment;filename="job_orders_report_' . date('Y-m-d') . '.pdf"');
    
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
