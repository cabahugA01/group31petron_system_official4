<?php
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

// Check if user is SuperAdmin or Developer
session_start();
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$u = $_SESSION['user'];
$role = $u['role'] ?? 'staff';
$roleKey = function_exists('role_key') ? role_key($role) : strtolower(trim((string)$role));

if (!in_array($roleKey, ['superadmin', 'developer'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. SuperAdmin/Developer only.']);
    exit;
}

$export_type = $_POST['export_type'] ?? 'audit_trail';
$format = $_POST['export_format'] ?? 'csv';
$date_from = $_POST['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
$date_to = $_POST['date_to'] ?? date('Y-m-d');

// Get filters
$filters = [
    'date_from' => $date_from,
    'date_to' => $date_to,
    'user_filter' => $_POST['user_filter'] ?? '',
    'module_filter' => $_POST['module_filter'] ?? '',
    'severity_filter' => $_POST['severity_filter'] ?? '',
    'status_filter' => $_POST['status_filter'] ?? '',
    'search' => $_POST['search'] ?? ''
];

try {
    // Create export log entry
    $stmt = $pdo->prepare("
        INSERT INTO export_logs 
        (export_type, format, date_from, date_to, filters_applied, status, user_id, ip_address, user_agent) 
        VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?)
    ");
    $stmt->execute([
        $export_type,
        $format,
        $date_from,
        $date_to,
        json_encode($filters),
        $u['id'],
        $_SERVER['REMOTE_ADDR'] ?? '',
        $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
    
    $export_id = $pdo->lastInsertId();
    
    // Get data based on export type
    $data = [];
    $filename = '';
    
    switch ($export_type) {
        case 'audit_trail':
            $data = getAuditTrailData($pdo, $filters);
            $filename = 'audit_trail_' . date('Y-m-d_H-i-s');
            break;
        case 'error_events':
            $data = getErrorTrackingData($pdo, $filters);
            $filename = 'error_events_' . date('Y-m-d_H-i-s');
            break;
        case 'system_alerts':
            $data = getSystemAlertsData($pdo, $filters);
            $filename = 'system_alerts_' . date('Y-m-d_H-i-s');
            break;
        case 'all':
            $data = getAllLogsData($pdo, $filters);
            $filename = 'all_system_logs_' . date('Y-m-d_H-i-s');
            break;
        default:
            throw new Exception('Invalid export type');
    }
    
    // Generate export file
    $file_path = '';
    $file_size = 0;
    
    switch ($format) {
        case 'csv':
            $file_path = generateCSV($data, $filename, $export_type);
            $file_size = filesize($file_path);
            break;
        case 'excel':
            $file_path = generateExcel($data, $filename, $export_type);
            $file_size = filesize($file_path);
            break;
        case 'json':
            $file_path = generateJSON($data, $filename);
            $file_size = filesize($file_path);
            break;
        case 'pdf':
            $file_path = generatePDF($data, $filename, $export_type);
            $file_size = filesize($file_path);
            break;
        default:
            throw new Exception('Invalid export format');
    }
    
    // Update export log
    $stmt = $pdo->prepare("
        UPDATE export_logs 
        SET status = 'completed', file_path = ?, file_size = ?, records_exported = ?, completed_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$file_path, $file_size, count($data), $export_id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Export completed successfully',
        'export_id' => $export_id,
        'file_path' => $file_path,
        'records_exported' => count($data)
    ]);
    
} catch (Exception $e) {
    // Update export log with error
    if (isset($export_id)) {
        $stmt = $pdo->prepare("
            UPDATE export_logs 
            SET status = 'failed', error_message = ?, completed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$e->getMessage(), $export_id]);
    }
    
    echo json_encode([
        'success' => false,
        'message' => 'Export failed: ' . $e->getMessage()
    ]);
}

// Function to get audit trail data
function getAuditTrailData($pdo, $filters) {
    $where = ["1=1"];
    $params = [];
    
    if (!empty($filters['date_from'])) {
        $where[] = "created_at >= ?";
        $params[] = $filters['date_from'] . ' 00:00:00';
    }
    
    if (!empty($filters['date_to'])) {
        $where[] = "created_at <= ?";
        $params[] = $filters['date_to'] . ' 23:59:59';
    }
    
    if (!empty($filters['user_filter'])) {
        $where[] = "u.username = ?";
        $params[] = $filters['user_filter'];
    }
    
    if (!empty($filters['module_filter'])) {
        $where[] = "entity_type = ?";
        $params[] = $filters['module_filter'];
    }
    
    if (!empty($filters['search'])) {
        $where[] = "(action_type LIKE ? OR action_details LIKE ? OR u.username LIKE ?)";
        $params[] = '%' . $filters['search'] . '%';
        $params[] = '%' . $filters['search'] . '%';
        $params[] = '%' . $filters['search'] . '%';
    }
    
    $sql = "SELECT al.*, u.username, u.full_name, s.station_name
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            LEFT JOIN stations s ON al.entity_type = 'station' AND al.entity_id = s.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY al.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to get error tracking data
function getErrorTrackingData($pdo, $filters) {
    $where = ["1=1"];
    $params = [];
    
    if (!empty($filters['date_from'])) {
        $where[] = "Date_Time >= ?";
        $params[] = $filters['date_from'] . ' 00:00:00';
    }
    
    if (!empty($filters['date_to'])) {
        $where[] = "Date_Time <= ?";
        $params[] = $filters['date_to'] . ' 23:59:59';
    }
    
    if (!empty($filters['severity_filter'])) {
        $where[] = "Severity = ?";
        $params[] = $filters['severity_filter'];
    }
    
    if (!empty($filters['status_filter'])) {
        $where[] = "Status = ?";
        $params[] = $filters['status_filter'];
    }
    
    if (!empty($filters['module_filter'])) {
        $where[] = "Module = ?";
        $params[] = $filters['module_filter'];
    }
    
    if (!empty($filters['search'])) {
        $where[] = "(Error_Message LIKE ? OR Error_Type LIKE ? OR Module LIKE ?)";
        $params[] = '%' . $filters['search'] . '%';
        $params[] = '%' . $filters['search'] . '%';
        $params[] = '%' . $filters['search'] . '%';
    }
    
    $sql = "SELECT ee.*, u.username, u.full_name, s.station_name,
                   assigned.username as assigned_to_name,
                   resolver.username as resolved_by_name
            FROM error_events ee
            LEFT JOIN users u ON ee.User_ID = u.id
            LEFT JOIN stations s ON ee.Station_ID = s.id
            LEFT JOIN users assigned ON ee.Assigned_To = assigned.id
            LEFT JOIN users resolver ON ee.Resolved_By = resolver.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY ee.Date_Time DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to get system alerts data
function getSystemAlertsData($pdo, $filters) {
    $where = ["status IN ('active', 'acknowledged', 'resolved')"];
    $params = [];
    
    if (!empty($filters['severity_filter'])) {
        $where[] = "alert_level = ?";
        $params[] = $filters['severity_filter'];
    }
    
    if (!empty($filters['module_filter'])) {
        $where[] = "source = ?";
        $params[] = $filters['module_filter'];
    }
    
    if (!empty($filters['search'])) {
        $where[] = "(title LIKE ? OR message LIKE ? OR alert_type LIKE ?)";
        $params[] = '%' . $filters['search'] . '%';
        $params[] = '%' . $filters['search'] . '%';
        $params[] = '%' . $filters['search'] . '%';
    }
    
    $sql = "SELECT sa.*, 
                   resolver.username as resolved_by_name
            FROM system_alerts sa
            LEFT JOIN users resolver ON sa.resolved_by = resolver.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY sa.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to get all logs data
function getAllLogsData($pdo, $filters) {
    $audit_data = getAuditTrailData($pdo, $filters);
    $error_data = getErrorTrackingData($pdo, $filters);
    $alerts_data = getSystemAlertsData($pdo, $filters);
    
    // Combine and format data
    $all_data = [];
    
    foreach ($audit_data as $row) {
        $all_data[] = array_merge(['log_type' => 'Audit Trail'], $row);
    }
    
    foreach ($error_data as $row) {
        $all_data[] = array_merge(['log_type' => 'Error Event'], $row);
    }
    
    foreach ($alerts_data as $row) {
        $all_data[] = array_merge(['log_type' => 'System Alert'], $row);
    }
    
    return $all_data;
}

// Function to generate CSV
function generateCSV($data, $filename, $export_type) {
    $filepath = __DIR__ . '/../../exports/' . $filename . '.csv';
    
    // Create exports directory if it doesn't exist
    if (!is_dir(__DIR__ . '/../../exports')) {
        mkdir(__DIR__ . '/../../exports', 0777, true);
    }
    
    $file = fopen($filepath, 'w');
    
    // Add BOM for UTF-8
    fwrite($file, "\xEF\xBB\xBF");
    
    // Write headers based on export type
    if ($export_type === 'audit_trail') {
        fputcsv($file, ['Log_ID', 'User', 'Action', 'Module', 'Date_Time', 'Status', 'Severity', 'Details', 'IP_Address', 'Station_Name']);
    } elseif ($export_type === 'error_events') {
        fputcsv($file, ['Error_ID', 'Error_Type', 'Severity', 'Module', 'Date_Time', 'Error_Message', 'Status', 'File_Path', 'Line_Number', 'Assigned_To', 'Station_Name']);
    } elseif ($export_type === 'system_alerts') {
        fputcsv($file, ['Alert_ID', 'Alert_Type', 'Severity', 'Module', 'Date_Time', 'Title', 'Message', 'Status', 'Acknowledged_By', 'Resolved_By']);
    } elseif ($export_type === 'all') {
        fputcsv($file, ['Log_Type', 'ID', 'Type', 'Severity', 'Module', 'Date_Time', 'Description', 'Status', 'User', 'Station']);
    }
    
    // Write data rows
    foreach ($data as $row) {
        $row_data = [];
        
        if ($export_type === 'audit_trail') {
            $row_data = [
                $row['id'] ?? '',
                $row['username'] ?? '',
                $row['action_type'] ?? '',
                $row['entity_type'] ?? '',
                $row['created_at'] ?? '',
                $row['status'] ?? '',
                $row['log_type'] ?? '',
                $row['action_details'] ?? '',
                $row['ip_address'] ?? '',
                $row['station_name'] ?? ''
            ];
        } elseif ($export_type === 'error_events') {
            $row_data = [
                $row['Error_ID'] ?? '',
                $row['Error_Type'] ?? '',
                $row['Severity'] ?? '',
                $row['Module'] ?? '',
                $row['Date_Time'] ?? '',
                $row['Error_Message'] ?? '',
                $row['Status'] ?? '',
                $row['File_Path'] ?? '',
                $row['Line_Number'] ?? '',
                $row['assigned_to_name'] ?? '',
                $row['station_name'] ?? ''
            ];
        } elseif ($export_type === 'system_alerts') {
            $row_data = [
                $row['id'] ?? '',
                $row['alert_type'] ?? '',
                $row['alert_level'] ?? '',
                $row['source'] ?? '',
                $row['created_at'] ?? '',
                $row['title'] ?? '',
                $row['message'] ?? '',
                $row['status'] ?? '',
                $row['resolved_by_name'] ?? ''
            ];
        } elseif ($export_type === 'all') {
            $row_data = [
                $row['log_type'] ?? '',
                $row['id'] ?? '',
                $row['action_type'] ?? $row['Error_Type'] ?? $row['alert_type'] ?? '',
                $row['Severity'] ?? $row['alert_level'] ?? '',
                $row['entity_type'] ?? $row['Module'] ?? $row['source'] ?? '',
                $row['created_at'] ?? $row['Date_Time'] ?? '',
                $row['action_details'] ?? $row['Error_Message'] ?? $row['message'] ?? '',
                $row['status'] ?? '',
                $row['username'] ?? $row['User'] ?? '',
                $row['station_name'] ?? ''
            ];
        }
        
        fputcsv($file, $row_data);
    }
    
    fclose($file);
    return $filepath;
}

// Function to generate Excel (CSV format for simplicity)
function generateExcel($data, $filename, $export_type) {
    return generateCSV($data, $filename . '_excel', $export_type);
}

// Function to generate JSON
function generateJSON($data, $filename) {
    $filepath = __DIR__ . '/../../exports/' . $filename . '.json';
    
    // Create exports directory if it doesn't exist
    if (!is_dir(__DIR__ . '/../../exports')) {
        mkdir(__DIR__ . '/../../exports', 0777, true);
    }
    
    file_put_contents($filepath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return $filepath;
}

// Function to generate PDF (HTML format for simplicity)
function generatePDF($data, $filename, $export_type) {
    $filepath = __DIR__ . '/../../exports/' . $filename . '.html';
    
    // Create exports directory if it doesn't exist
    if (!is_dir(__DIR__ . '/../../exports')) {
        mkdir(__DIR__ . '/../../exports', 0777, true);
    }
    
    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>' . ucfirst($export_type) . ' Export</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .critical { color: #dc3545; }
        .high { color: #fd7e14; }
        .medium { color: #ffc107; }
        .low { color: #28a745; }
        .info { color: #17a2b8; }
    </style>
</head>
<body>
    <h1>' . ucfirst($export_type) . ' Export</h1>
    <p>Generated: ' . date('Y-m-d H:i:s') . '</p>
    <table>';
    
    // Add headers
    if ($export_type === 'audit_trail') {
        $html .= '<tr><th>Log_ID</th><th>User</th><th>Action</th><th>Module</th><th>Date_Time</th><th>Status</th><th>Severity</th><th>Details</th></tr>';
    } elseif ($export_type === 'error_events') {
        $html .= '<tr><th>Error_ID</th><th>Error_Type</th><th>Severity</th><th>Module</th><th>Date_Time</th><th>Error_Message</th><th>Status</th></tr>';
    } elseif ($export_type === 'system_alerts') {
        $html .= '<tr><th>Alert_ID</th><th>Alert_Type</th><th>Severity</th><th>Module</th><th>Date_Time</th><th>Title</th><th>Status</th></tr>';
    }
    
    // Add data rows
    foreach ($data as $row) {
        $html .= '<tr>';
        
        if ($export_type === 'audit_trail') {
            $html .= '<td>' . ($row['Log_ID'] ?? '') . '</td>';
            $html .= '<td>' . ($row['User'] ?? '') . '</td>';
            $html .= '<td>' . ($row['Action'] ?? '') . '</td>';
            $html .= '<td>' . ($row['Module'] ?? '') . '</td>';
            $html .= '<td>' . ($row['Date_Time'] ?? '') . '</td>';
            $html .= '<td>' . ($row['Status'] ?? '') . '</td>';
            $html .= '<td class="' . ($row['Severity'] ?? '') . '">' . ($row['Severity'] ?? '') . '</td>';
            $html .= '<td>' . ($row['Details'] ?? '') . '</td>';
        } elseif ($export_type === 'error_events') {
            $html .= '<td>' . ($row['Error_ID'] ?? '') . '</td>';
            $html .= '<td>' . ($row['Error_Type'] ?? '') . '</td>';
            $html .= '<td class="' . ($row['Severity'] ?? '') . '">' . ($row['Severity'] ?? '') . '</td>';
            $html .= '<td>' . ($row['Module'] ?? '') . '</td>';
            $html .= '<td>' . ($row['Date_Time'] ?? '') . '</td>';
            $html .= '<td>' . ($row['Error_Message'] ?? '') . '</td>';
            $html .= '<td>' . ($row['Status'] ?? '') . '</td>';
        } elseif ($export_type === 'system_alerts') {
            $html .= '<td>' . ($row['Alert_ID'] ?? '') . '</td>';
            $html .= '<td>' . ($row['Alert_Type'] ?? '') . '</td>';
            $html .= '<td class="' . ($row['Severity'] ?? '') . '">' . ($row['Severity'] ?? '') . '</td>';
            $html .= '<td>' . ($row['Module'] ?? '') . '</td>';
            $html .= '<td>' . ($row['Date_Time'] ?? '') . '</td>';
            $html .= '<td>' . ($row['Title'] ?? '') . '</td>';
            $html .= '<td>' . ($row['Status'] ?? '') . '</td>';
        }
        
        $html .= '</tr>';
    }
    
    $html .= '</table></body></html>';
    
    file_put_contents($filepath, $html);
    return $filepath;
}
?>
