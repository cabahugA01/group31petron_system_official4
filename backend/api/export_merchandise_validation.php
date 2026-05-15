<?php
require_once __DIR__ . '/../../public/db_connect.php';
require_once __DIR__ . '/../../backend/lib.php';

header('Content-Type: application/json');

// Start session for user authentication
session_start();

try {
    // Validate user is logged in and has proper role
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        throw new Exception('User not authenticated');
    }

    $user_id = $_SESSION['user_id'];
    $user_role = $_SESSION['role'];
    
    // Check if user has manager role
    if (!in_array($user_role, ['manager', 'admin', 'superadmin'])) {
        throw new Exception('Unauthorized access');
    }
    
    // Get station ID
    $station_id = user_station_id();
    if (!$station_id) {
        throw new Exception('Station not found');
    }

    $export_type = $_GET['type'] ?? 'pending';
    $format = $_GET['format'] ?? 'csv';
    $date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
    $date_to = $_GET['date_to'] ?? date('Y-m-d');

    switch ($export_type) {
        case 'pending':
            exportPendingTransactions($pdo, $station_id, $format, $date_from, $date_to);
            break;
        case 'validation_log':
            exportValidationLog($pdo, $station_id, $format, $date_from, $date_to);
            break;
        case 'audit_trail':
            exportAuditTrail($pdo, $station_id, $format, $date_from, $date_to);
            break;
        default:
            throw new Exception('Invalid export type');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

function exportPendingTransactions($pdo, $station_id, $format, $date_from, $date_to) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                pmt.transaction_id,
                pmt.customer_name,
                pmt.payment_method,
                pmt.items,
                pmt.total_amount,
                pmt.validation_status,
                pmt.shift_status,
                pmt.created_at,
                u.name as staff_name,
                sh.shift_number,
                pmt.validated_by,
                v_u.name as validated_by_name,
                pmt.validated_at,
                pmt.validation_remarks
            FROM pending_merchandise_transactions pmt
            LEFT JOIN users u ON pmt.staff_id = u.id
            LEFT JOIN users v_u ON pmt.validated_by = v_u.id
            LEFT JOIN shifts sh ON pmt.shift_id = sh.id
            WHERE pmt.station_id = ? 
            AND DATE(pmt.created_at) BETWEEN ? AND ?
            ORDER BY pmt.created_at DESC
        ");
        
        $stmt->execute([$station_id, $date_from, $date_to]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($format === 'csv') {
            exportAsCSV($transactions, 'pending_merchandise_transactions', [
                'Transaction ID',
                'Customer Name',
                'Payment Method',
                'Items Count',
                'Total Amount',
                'Status',
                'Shift Status',
                'Staff Name',
                'Shift Number',
                'Validated By',
                'Validated At',
                'Validation Remarks',
                'Created At'
            ]);
        } elseif ($format === 'json') {
            exportAsJSON($transactions, 'pending_merchandise_transactions');
        } else {
            throw new Exception('Unsupported format');
        }

    } catch (Exception $e) {
        throw new Exception('Error exporting pending transactions: ' . $e->getMessage());
    }
}

function exportValidationLog($pdo, $station_id, $format, $date_from, $date_to) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                val.transaction_id,
                val.action,
                val.manager_name,
                val.staff_name,
                val.original_total,
                val.adjusted_total,
                val.remarks,
                val.created_at,
                val.original_items,
                val.adjusted_items
            FROM validation_actions_log val
            WHERE val.station_id = ? 
            AND DATE(val.created_at) BETWEEN ? AND ?
            ORDER BY val.created_at DESC
        ");
        
        $stmt->execute([$station_id, $date_from, $date_to]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($format === 'csv') {
            exportAsCSV($logs, 'validation_actions_log', [
                'Transaction ID',
                'Action',
                'Manager Name',
                'Staff Name',
                'Original Total',
                'Adjusted Total',
                'Remarks',
                'Created At',
                'Original Items Count',
                'Adjusted Items Count'
            ]);
        } elseif ($format === 'json') {
            exportAsJSON($logs, 'validation_actions_log');
        } else {
            throw new Exception('Unsupported format');
        }

    } catch (Exception $e) {
        throw new Exception('Error exporting validation log: ' . $e->getMessage());
    }
}

function exportAuditTrail($pdo, $station_id, $format, $date_from, $date_to) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                sal.action,
                sal.details,
                sal.reference_id,
                sal.ip_address,
                sal.user_agent,
                sal.created_at,
                u.name as staff_name
            FROM staff_audit_log sal
            LEFT JOIN users u ON sal.staff_id = u.id
            WHERE sal.station_id = ? 
            AND (sal.action LIKE '%merchandise%' OR sal.action LIKE '%transaction%')
            AND DATE(sal.created_at) BETWEEN ? AND ?
            ORDER BY sal.created_at DESC
        ");
        
        $stmt->execute([$station_id, $date_from, $date_to]);
        $audit_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($format === 'csv') {
            exportAsCSV($audit_logs, 'merchandise_audit_trail', [
                'Action',
                'Details',
                'Reference ID',
                'Staff Name',
                'IP Address',
                'User Agent',
                'Created At'
            ]);
        } elseif ($format === 'json') {
            exportAsJSON($audit_logs, 'merchandise_audit_trail');
        } else {
            throw new Exception('Unsupported format');
        }

    } catch (Exception $e) {
        throw new Exception('Error exporting audit trail: ' . $e->getMessage());
    }
}

function exportAsCSV($data, $filename, $headers) {
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '_' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

    // Open output stream
    $output = fopen('php://output', 'w');

    // Add BOM for UTF-8
    fwrite($output, "\xEF\xBB\xBF");

    // Write headers
    fputcsv($output, $headers);

    // Write data rows
    foreach ($data as $row) {
        $csv_row = [];
        
        if ($filename === 'pending_merchandise_transactions') {
            $csv_row[] = $row['transaction_id'];
            $csv_row[] = $row['customer_name'];
            $csv_row[] = $row['payment_method'];
            $items = json_decode($row['items'], true);
            $csv_row[] = $items ? count($items) : 0;
            $csv_row[] = number_format($row['total_amount'], 2);
            $csv_row[] = $row['validation_status'];
            $csv_row[] = $row['shift_status'];
            $csv_row[] = $row['staff_name'];
            $csv_row[] = $row['shift_number'];
            $csv_row[] = $row['validated_by_name'];
            $csv_row[] = $row['validated_at'];
            $csv_row[] = $row['validation_remarks'];
            $csv_row[] = $row['created_at'];
        } elseif ($filename === 'validation_actions_log') {
            $csv_row[] = $row['transaction_id'];
            $csv_row[] = $row['action'];
            $csv_row[] = $row['manager_name'];
            $csv_row[] = $row['staff_name'];
            $csv_row[] = number_format($row['original_total'], 2);
            $csv_row[] = number_format($row['adjusted_total'], 2);
            $csv_row[] = $row['remarks'];
            $csv_row[] = $row['created_at'];
            $orig_items = json_decode($row['original_items'], true);
            $adj_items = json_decode($row['adjusted_items'], true);
            $csv_row[] = $orig_items ? count($orig_items) : 0;
            $csv_row[] = $adj_items ? count($adj_items) : 0;
        } elseif ($filename === 'merchandise_audit_trail') {
            $csv_row[] = $row['action'];
            $details = json_decode($row['details'], true);
            $csv_row[] = is_string($details) ? $details : json_encode($details);
            $csv_row[] = $row['reference_id'];
            $csv_row[] = $row['staff_name'];
            $csv_row[] = $row['ip_address'];
            $csv_row[] = $row['user_agent'];
            $csv_row[] = $row['created_at'];
        }

        fputcsv($output, $csv_row);
    }

    fclose($output);
    exit;
}

function exportAsJSON($data, $filename) {
    // Set headers for JSON download
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '_' . date('Y-m-d') . '.json"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

    // Process data for JSON export
    $processed_data = [];
    foreach ($data as $row) {
        $processed_row = $row;
        
        // Parse JSON fields for better readability
        if (isset($row['items'])) {
            $processed_row['items'] = json_decode($row['items'], true);
        }
        if (isset($row['original_items'])) {
            $processed_row['original_items'] = json_decode($row['original_items'], true);
        }
        if (isset($row['adjusted_items'])) {
            $processed_row['adjusted_items'] = json_decode($row['adjusted_items'], true);
        }
        if (isset($row['details'])) {
            $details = json_decode($row['details'], true);
            $processed_row['details'] = $details !== null ? $details : $row['details'];
        }
        
        $processed_data[] = $processed_row;
    }

    $export_data = [
        'export_info' => [
            'filename' => $filename,
            'export_date' => date('Y-m-d H:i:s'),
            'total_records' => count($processed_data),
            'station_id' => user_station_id(),
            'exported_by' => $_SESSION['user_id']
        ],
        'data' => $processed_data
    ];

    echo json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}
?>
