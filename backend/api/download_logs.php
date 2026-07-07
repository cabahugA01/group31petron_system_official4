<?php
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

// Check if user is authenticated and has proper role
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$u = current_user();
$role = $u['role'] ?? 'staff';
$roleKey = function_exists('role_key') ? role_key($role) : strtolower(trim((string)$role));

if (!in_array($roleKey, ['superadmin', 'admin', 'developer'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$log_type = $_GET['log_type'] ?? 'error';

try {
    // Check if audit_log table exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'audit_log'");
    $stmt->execute();
    $audit_log_exists = $stmt->fetchColumn() > 0;
    
    if (!$audit_log_exists) {
        http_response_code(404);
        echo json_encode(['error' => 'Audit log table not found']);
        exit;
    }
    
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $log_type . '_logs_' . date('Y-m-d_H-i-s') . '.csv"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: 0');
    
    // Open output stream
    $output = fopen('php://output', 'w');
    
    // Add CSV header
    fputcsv($output, [
        'ID',
        'Date/Time',
        'User',
        'Action',
        'Description',
        'Status',
        'IP Address',
        'Station',
        'Details'
    ]);
    
    // Build query based on log type
    $where_clause = "";
    $params = [];
    
    switch ($log_type) {
        case 'error':
            $where_clause = "WHERE (status = 'Failed' OR action LIKE '%Error%' OR action LIKE '%Failed%')";
            break;
        case 'login':
            $where_clause = "WHERE action LIKE '%login%' OR action LIKE '%auth%'";
            break;
        case 'transaction':
            $where_clause = "WHERE action LIKE '%transaction%' OR action LIKE '%sale%'";
            break;
        case 'system':
            $where_clause = "WHERE action LIKE '%system%' OR action LIKE '%backup%' OR action LIKE '%update%'";
            break;
        default:
            // Get all logs
            $where_clause = "";
            break;
    }
    
    // Get logs with user and station information
    $query = "
        SELECT 
            al.id,
            al.created_at,
            COALESCE(u.name, 'System') as user_name,
            al.action,
            al.description,
            al.status,
            al.ip_address,
            COALESCE(s.name, 'N/A') as station_name,
            al.details
        FROM audit_log al
        LEFT JOIN users u ON al.user_id = u.id
        LEFT JOIN stations s ON u.station_id = s.id
        $where_clause
        ORDER BY al.created_at DESC
        LIMIT 10000
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    
    // Output CSV data
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['id'],
            $row['created_at'],
            $row['user_name'],
            $row['action'],
            $row['description'],
            $row['status'],
            $row['ip_address'],
            $row['station_name'],
            $row['details'] ? json_decode($row['details']) : ''
        ]);
    }
    
    // Close output stream
    fclose($output);
    
    // Log the download
    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_log (user_id, action, description, status, ip_address) 
            VALUES (?, ?, ?, 'SUCCESS', ?)
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            'Logs Downloaded',
            "Downloaded {$log_type} logs",
            $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
        ]);
    } catch (Exception $e) {
        error_log("Failed to log download: " . $e->getMessage());
    }
    
} catch (Exception $e) {
    error_log("Download Logs API Error: " . $e->getMessage());
    
    // If headers already sent, output error as JSON
    if (headers_sent()) {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to download logs: ' . $e->getMessage()
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to download logs: ' . $e->getMessage()]);
    }
}
?>
