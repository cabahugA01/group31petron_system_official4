<?php
/**
 * Developer Reports API
 * Handles export functionality and audit trail logging
 */

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

// Require login
require_login();

$u = current_user();
$role = $u['role'] ?? 'staff';
$roleKey = function_exists('role_key') ? role_key($role) : strtolower(trim((string)$role));

// Only SuperAdmin and Developer can access
if (!in_array($roleKey, ['superadmin', 'developer'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$action = $_GET['action'] ?? '';

// Handle POST data for logging
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
}

switch ($action) {
    case 'log_access':
        /**
         * Log report access for audit trail
         * POST data: {report_type: string, action: string}
         */
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            exit;
        }

        $report_type = $data['report_type'] ?? '';
        $log_action = $data['action'] ?? 'view';
        
        if (empty($report_type)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Report type is required']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO report_access_audit 
                (user_id, user_name, report_type, action, ip_address, user_agent, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $user_name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
            
            $stmt->execute([
                $u['id'],
                $user_name,
                $report_type,
                $log_action,
                $ip_address,
                $user_agent
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Access logged successfully',
                'log_id' => $pdo->lastInsertId()
            ]);
        } catch (Exception $e) {
            error_log("Error logging report access: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to log access']);
        }
        break;

    case 'export_csv':
        /**
         * Export report data to CSV
         * GET params: report_type, date_from, date_to
         */
        $report_type = $_GET['report_type'] ?? '';
        $date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
        $date_to = $_GET['date_to'] ?? date('Y-m-d');
        
        if (empty($report_type)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Report type is required']);
            exit;
        }

        // Log the export action
        try {
            $stmt = $pdo->prepare("
                INSERT INTO report_access_audit 
                (user_id, user_name, report_type, action, ip_address, created_at)
                VALUES (?, ?, ?, 'export_csv', ?, NOW())
            ");
            $user_name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
            $stmt->execute([
                $u['id'],
                $user_name,
                $report_type,
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);
        } catch (Exception $e) {
            error_log("Error logging CSV export: " . $e->getMessage());
        }

        // Fetch data based on report type
        $data = [];
        $filename = '';
        
        try {
            switch ($report_type) {
                case 'technical':
                    $stmt = $pdo->prepare("
                        SELECT * FROM system_performance_logs
                        WHERE created_at BETWEEN ? AND ?
                        ORDER BY created_at DESC
                    ");
                    $stmt->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
                    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $filename = "technical_report_" . date('Y-m-d') . ".csv";
                    break;

                case 'security':
                    $stmt = $pdo->prepare("
                        SELECT * FROM login_attempts_security
                        WHERE created_at BETWEEN ? AND ?
                        ORDER BY created_at DESC
                    ");
                    $stmt->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
                    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $filename = "security_report_" . date('Y-m-d') . ".csv";
                    break;

                case 'developer_audit':
                    $stmt = $pdo->prepare("
                        SELECT * FROM code_changes_audit
                        WHERE created_at BETWEEN ? AND ?
                        ORDER BY created_at DESC
                    ");
                    $stmt->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
                    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $filename = "developer_audit_" . date('Y-m-d') . ".csv";
                    break;

                case 'audit_trail':
                    $stmt = $pdo->prepare("
                        SELECT * FROM report_access_audit
                        WHERE created_at BETWEEN ? AND ?
                        ORDER BY created_at DESC
                    ");
                    $stmt->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
                    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $filename = "audit_trail_" . date('Y-m-d') . ".csv";
                    break;

                default:
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Invalid report type']);
                    exit;
            }

            if (empty($data)) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'No data available for export']);
                exit;
            }

            // Set headers for CSV download
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            header('Expires: 0');

            // Output CSV
            $output = fopen('php://output', 'w');
            
            // Add BOM for Excel UTF-8 support
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // Write headers
            if (!empty($data)) {
                fputcsv($output, array_keys($data[0]));
                
                // Write data rows
                foreach ($data as $row) {
                    fputcsv($output, $row);
                }
            }
            
            fclose($output);
            exit;

        } catch (Exception $e) {
            error_log("Error exporting CSV: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Export failed: ' . $e->getMessage()]);
        }
        break;

    case 'export_pdf':
        /**
         * Export report data to PDF (placeholder)
         * GET params: report_type, date_from, date_to
         */
        $report_type = $_GET['report_type'] ?? '';
        
        if (empty($report_type)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Report type is required']);
            exit;
        }

        // Log the export action
        try {
            $stmt = $pdo->prepare("
                INSERT INTO report_access_audit 
                (user_id, user_name, report_type, action, ip_address, created_at)
                VALUES (?, ?, ?, 'export_pdf', ?, NOW())
            ");
            $user_name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
            $stmt->execute([
                $u['id'],
                $user_name,
                $report_type,
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);
        } catch (Exception $e) {
            error_log("Error logging PDF export: " . $e->getMessage());
        }

        // PDF generation requires additional library (e.g., TCPDF, FPDF, mPDF)
        // For now, return success with message
        echo json_encode([
            'success' => true,
            'message' => 'PDF export functionality requires PDF library installation',
            'note' => 'Use CSV export or implement PDF library (TCPDF, FPDF, mPDF)'
        ]);
        break;

    case 'get_stats':
        /**
         * Get summary statistics for a report type
         * GET params: report_type, date_from, date_to
         */
        $report_type = $_GET['report_type'] ?? '';
        $date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
        $date_to = $_GET['date_to'] ?? date('Y-m-d');
        
        try {
            $stats = [];
            
            switch ($report_type) {
                case 'technical':
                    $stmt = $pdo->prepare("
                        SELECT 
                            COUNT(*) as total_logs,
                            COUNT(DISTINCT module_name) as unique_modules,
                            AVG(metric_value) as avg_metric
                        FROM system_performance_logs
                        WHERE created_at BETWEEN ? AND ?
                    ");
                    $stmt->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
                    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
                    break;

                case 'security':
                    $stmt = $pdo->prepare("
                        SELECT 
                            COUNT(*) as total_attempts,
                            SUM(CASE WHEN attempt_type = 'success' THEN 1 ELSE 0 END) as successful,
                            SUM(CASE WHEN attempt_type = 'failed' THEN 1 ELSE 0 END) as failed
                        FROM login_attempts_security
                        WHERE created_at BETWEEN ? AND ?
                    ");
                    $stmt->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
                    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
                    break;

                case 'developer_audit':
                    $stmt = $pdo->prepare("
                        SELECT 
                            COUNT(*) as total_commits,
                            SUM(lines_added) as total_additions,
                            SUM(lines_removed) as total_deletions
                        FROM code_changes_audit
                        WHERE created_at BETWEEN ? AND ?
                    ");
                    $stmt->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
                    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
                    break;

                case 'audit_trail':
                    $stmt = $pdo->prepare("
                        SELECT 
                            COUNT(*) as total_logs,
                            COUNT(DISTINCT user_id) as unique_users,
                            COUNT(DISTINCT report_type) as report_types_accessed
                        FROM report_access_audit
                        WHERE created_at BETWEEN ? AND ?
                    ");
                    $stmt->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
                    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
                    break;

                default:
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Invalid report type']);
                    exit;
            }

            echo json_encode([
                'success' => true,
                'stats' => $stats,
                'date_range' => [
                    'from' => $date_from,
                    'to' => $date_to
                ]
            ]);

        } catch (Exception $e) {
            error_log("Error fetching stats: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to fetch statistics']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid action',
            'available_actions' => ['log_access', 'export_csv', 'export_pdf', 'get_stats']
        ]);
        break;
}
?>
